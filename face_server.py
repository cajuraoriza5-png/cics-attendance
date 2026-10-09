"""CICS attendance API: YOLOv8n student classifier + ArcFace + Hybrid.
Compatibility endpoints: /status, /recognize, /detect, /sync_faces, /train, /train/status, /reload.
"""
import os, re, json, time, base64, shutil, threading, subprocess, sys, traceback
from pathlib import Path
from collections import Counter
import cv2, numpy as np
from flask import Flask, request, jsonify
from flask_cors import CORS

ROOT=Path(__file__).resolve().parent; FACES=ROOT/'faces'; INCOMING=ROOT/'faces_incoming'; MODELS=ROOT/'models'
YOLO_WEIGHTS=MODELS/'yolov8n_student_cls'/'weights'/'best.pt'; ARC_DB=ROOT/'arcface_embeddings.npz'; STATUS_F=FACES/'.train_status.json'
for p in (FACES,INCOMING): p.mkdir(parents=True,exist_ok=True)
app=Flask(__name__); CORS(app)
ARCFACE_THRESHOLD=float(os.getenv('ARCFACE_THRESHOLD','0.55'))
YOLO_THRESHOLD=float(os.getenv('YOLO_THRESHOLD','0.65'))
HYBRID_THRESHOLD=float(os.getenv('HYBRID_THRESHOLD','80'))
MIN_SAMPLES=int(os.getenv('MIN_SAMPLES_PER_STUDENT','3')); MIN_BYTES=int(os.getenv('MIN_FILE_BYTES','1024'))
lock=threading.RLock(); cascade_lock=threading.RLock(); train_thread=None
cascade=None; yolo_model=None; arc_model=None; arc_embeddings=None; arc_labels=None
ready={'yolo':False,'arcface':False,'hybrid':False,'lbph':False,'loading':True}

def _sync_legacy_status():
    # Legacy PHP scanner expects models.lbph; this aliases it to YOLO during migration.
    ready['lbph'] = ready['yolo']


def _load_cascade():
    global cascade
    with cascade_lock:
        c=cv2.CascadeClassifier(cv2.data.haarcascades+'haarcascade_frontalface_default.xml')
        cascade=None if c.empty() else c
        return cascade is not None

def _detect(frame):
    if frame is None or frame.size==0:return []
    gray=cv2.equalizeHist(cv2.cvtColor(frame,cv2.COLOR_BGR2GRAY))
    with cascade_lock:
        global cascade
        try:
            if cascade is None or cascade.empty(): _load_cascade()
            if cascade is None:return []
            return cascade.detectMultiScale(gray,scaleFactor=1.1,minNeighbors=4,minSize=(30,30))
        except cv2.error:
            _load_cascade()
            try:return cascade.detectMultiScale(gray,scaleFactor=1.1,minNeighbors=4,minSize=(30,30)) if cascade is not None else []
            except Exception:return []

def _largest(dets): return max(dets,key=lambda b:int(b[2])*int(b[3])) if len(dets) else None

def _crop(frame,box,pad=.12):
    x,y,w,h=map(int,box); px=int(w*pad); py=int(h*pad)
    x1=max(0,x-px);y1=max(0,y-py);x2=min(frame.shape[1],x+w+px);y2=min(frame.shape[0],y+h+py)
    return frame[y1:y2,x1:x2]

def _parse_sid(name):
    m=re.match(r'^(\d+)(?:[_-].*)?\.(jpg|jpeg|png)$',name,re.I);return int(m.group(1)) if m else None

def _face_files():
    items=[]
    for p in FACES.iterdir():
        if not p.is_file() or p.suffix.lower() not in ('.jpg','.jpeg','.png'):continue
        sid=_parse_sid(p.name)
        try:
            if sid and p.stat().st_size>=MIN_BYTES and cv2.imread(str(p)) is not None:items.append((p,sid))
        except OSError:pass
    return items

def _load_arc_db():
    global arc_embeddings,arc_labels
    if not ARC_DB.exists():
        with lock:arc_embeddings=None;arc_labels=None;ready['arcface']=False
        return False
    try:
        with np.load(ARC_DB,allow_pickle=False) as d:
            e=np.asarray(d['embeddings'],np.float32); l=np.asarray(d['labels'],np.int32)
        if len(e)==0 or len(e)!=len(l):raise ValueError('Invalid ArcFace embedding database')
        with lock:arc_embeddings=e;arc_labels=l;ready['arcface']=True
        return True
    except Exception as e:
        print('[face_server] ArcFace DB error:',e,flush=True);return False

def _load_yolo():
    global yolo_model
    if not YOLO_WEIGHTS.exists():
        with lock:yolo_model=None;ready['yolo']=False;_sync_legacy_status()
        return False
    from ultralytics import YOLO
    model=YOLO(str(YOLO_WEIGHTS),task='classify')
    names=model.names
    with lock:yolo_model=model;ready['yolo']=True;_sync_legacy_status()
    print(f'[face_server] YOLO classifier loaded with {len(names)} classes',flush=True)
    return True

def _get_arc_model():
    global arc_model
    with lock:
        if arc_model is not None:return arc_model
    from insightface import model_zoo
    path=Path.home()/'.insightface/models/buffalo_s/w600k_mbf.onnx'
    if not path.exists():
        # Reuse the training script's downloader to ensure the same model source.
        import urllib.request,zipfile
        path.parent.mkdir(parents=True,exist_ok=True); zpath=path.parents[1]/'buffalo_s.zip'
        if not zpath.exists():
            with urllib.request.urlopen('https://github.com/deepinsight/insightface/releases/download/v0.7/buffalo_s.zip',timeout=90) as r,open(zpath,'wb') as f:shutil.copyfileobj(r,f)
        with zipfile.ZipFile(zpath) as z:
            member=next((n for n in z.namelist() if n.replace('\\','/').endswith('w600k_mbf.onnx')),None)
            if not member:raise RuntimeError('ArcFace model not found in buffalo_s.zip')
            with z.open(member) as src,open(path,'wb') as dst:shutil.copyfileobj(src,dst)
    m=model_zoo.get_model(str(path),providers=['CPUExecutionProvider']);m.prepare(ctx_id=-1)
    with lock:arc_model=m
    return m

def _align(face):
    h,w=face.shape[:2]
    src=np.array([[.32*w,.38*h],[.68*w,.38*h],[.50*w,.56*h],[.38*w,.72*h],[.62*w,.72*h]],np.float32)
    dst=np.array([[38.2946,51.6963],[73.5318,51.5014],[56.0252,71.7366],[41.5493,92.3655],[70.7299,92.2041]],np.float32)
    M,_=cv2.estimateAffinePartial2D(src,dst,method=cv2.LMEDS)
    return cv2.warpAffine(face,M,(112,112),borderMode=cv2.BORDER_REPLICATE) if M is not None else cv2.resize(face,(112,112))

def _arc_scores(face):
    with lock:e=arc_embeddings;l=arc_labels
    if e is None or l is None:return {'id':-1,'confidence':0.0,'similarity':0.0,'matched':False,'algorithm':'arcface','error':'ArcFace embedding database is not trained.'},{}
    try:
        feat=np.asarray(_get_arc_model().get_feat([_align(face)])[0],np.float32).reshape(-1); norm=np.linalg.norm(feat)
        if norm<=1e-8:raise ValueError('Zero ArcFace embedding')
        feat=feat/norm
        sims=e@feat/(np.maximum(np.linalg.norm(e,axis=1),1e-8))
        per={}
        for sid in np.unique(l):
            ss=np.sort(sims[l==sid])[::-1][:5];per[int(sid)]=float(np.mean(ss))
        best=max(per,key=per.get);sim=per[best]
        return {'id':int(best),'confidence':round(max(0,min(100,(sim+1)*50)),1),'similarity':round(sim,5),'matched':bool(sim>=ARCFACE_THRESHOLD),'algorithm':'arcface','score_note':'ArcFace cosine similarity display score; not a calibrated probability.'},per
    except Exception as exc:
        traceback.print_exc();return {'id':-1,'confidence':0.0,'similarity':0.0,'matched':False,'algorithm':'arcface','error':str(exc)},{}

def _yolo_scores(face):
    with lock:m=yolo_model
    if m is None:return {'id':-1,'confidence':0.0,'matched':False,'algorithm':'yolo','error':'YOLO student classifier is not loaded.'},{}
    try:
        result=m.predict(source=cv2.resize(face,(112,112)),imgsz=int(os.getenv('YOLO_RUNTIME_SIZE','160')),verbose=False,device='cpu')[0]
        probs=result.probs.data.detach().cpu().numpy().astype(float)
        names=m.names
        score_map={}
        for idx,p in enumerate(probs):
            raw=names.get(idx,str(idx)) if isinstance(names,dict) else names[idx]
            try:sid=int(str(raw))
            except ValueError:continue
            score_map[sid]=float(p)
        if not score_map:raise RuntimeError('YOLO class names are not numeric database user IDs')
        sid=max(score_map,key=score_map.get);conf=score_map[sid]*100
        return {'id':sid,'confidence':round(conf,1),'class_probability':round(score_map[sid],5),'matched':bool(score_map[sid]>=YOLO_THRESHOLD),'algorithm':'yolo','score_note':'YOLO classification probability; not a calibrated identity probability.'},score_map
    except Exception as exc:
        traceback.print_exc();return {'id':-1,'confidence':0.0,'matched':False,'algorithm':'yolo','error':str(exc)},{}

def _hybrid(yolo,arc,y_scores,a_scores):
    ids=set(y_scores)|set(a_scores)
    if not ids:return {'id':-1,'confidence':0.0,'matched':False,'algorithm':'hybrid','reason':'No candidate identity available'}
    # Map ArcFace cosine similarity to a bounded display scale using the configured
    # decision threshold as the midpoint; retain raw scores in the response.
    def arc_norm(s):return max(0.0,min(1.0,(float(s)-ARCFACE_THRESHOLD+0.25)/0.50))
    fused={sid:0.5*float(y_scores.get(sid,0.0))+0.5*arc_norm(a_scores.get(sid,-1.0)) for sid in ids}
    sid=max(fused,key=fused.get);yp=float(y_scores.get(sid,0.0));asim=float(a_scores.get(sid,-1.0));score=100*fused[sid]
    accepted=bool(sid>0 and yp>=YOLO_THRESHOLD and asim>=ARCFACE_THRESHOLD and score>=HYBRID_THRESHOLD)
    return {'id':int(sid),'comparison_id':int(sid),'confidence':round(score,1),'matched':accepted,'algorithm':'hybrid','reason':'Same-candidate YOLO + ArcFace score fusion','yolo_probability':round(yp,5),'yolo_confidence':round(yp*100,1),'arcface_similarity':round(asim,5),'arcface_confidence':round(max(0,min(100,(asim+1)*50)),1),'fusion_method':'50% YOLO class probability + 50% threshold-scaled ArcFace similarity','score_note':'Fusion score is a comparison score, not a calibrated probability.'}

def _models_load():
    _load_cascade()
    try:_load_yolo()
    except Exception as e:print('[face_server] YOLO load failed:',e,flush=True)
    try:_load_arc_db()
    except Exception as e:print('[face_server] ArcFace DB load failed:',e,flush=True)
    with lock:ready['hybrid']=bool(ready['yolo'] and ready['arcface']);_sync_legacy_status();_sync_legacy_status();ready['loading']=False

def _decode_image():
    data=request.get_json(force=True,silent=True) or {}; raw=data.get('image','')
    if not raw:raise ValueError('No image data received.')
    if ',' in raw and raw.strip().lower().startswith('data:image'):raw=raw.split(',',1)[1]
    b=base64.b64decode(raw,validate=True);frame=cv2.imdecode(np.frombuffer(b,np.uint8),cv2.IMREAD_COLOR)
    if frame is None:raise ValueError('Empty or invalid image frame')
    return frame

@app.get('/')
def home():return 'CICS YOLOv8n + ArcFace Hybrid API is Running!'
@app.get('/status')
def status():
    with lock:_sync_legacy_status();models=dict(ready)
    return jsonify({'ok':True,'hybrid_fusion_version':'YOLOV8N_ARCFACE_HYBRID_V1','models':models,'algorithms':['YOLOv8n Student Classification','ArcFace','Hybrid YOLOv8n + ArcFace'],'thresholds':{'yolo_class_probability':YOLO_THRESHOLD,'arcface_similarity':ARCFACE_THRESHOLD,'hybrid_confidence':HYBRID_THRESHOLD}})
@app.post('/detect')
def detect():
    try:frame=_decode_image()
    except Exception as e:return jsonify({'bbox':None,'faces_count':0,'error':str(e)}),400
    dets=_detect(frame);box=_largest(dets)
    if box is None:return jsonify({'bbox':None,'faces_count':0})
    x,y,w,h=map(int,box);return jsonify({'bbox':{'x':x,'y':y,'w':w,'h':h},'faces_count':len(dets)})
@app.post('/recognize')
def recognize():
    try:frame=_decode_image()
    except Exception as e:return jsonify({'error':f'Input error: {e}'}),400
    dets=_detect(frame);box=_largest(dets)
    if box is None:
        no={'id':-1,'confidence':0.0,'matched':False,'error':'No face detected'}
        return jsonify({'faces_count':0,'bbox':None,'yolo':dict(no,algorithm='yolo'),'arcface':dict(no,algorithm='arcface'),'hybrid':dict(no,algorithm='hybrid',reason='No face detected'),'lbph':dict(no,algorithm='lbph',reason='Deprecated alias; use yolo')})
    x,y,w,h=map(int,box);face=_crop(frame,box)
    if face is None or face.size==0:return jsonify({'error':'Could not crop detected face'}),422
    yolo,ys=_yolo_scores(face);arc,ascores=_arc_scores(face);hybrid=_hybrid(yolo,arc,ys,ascores)
    # Preserve old response key for older PHP/JS versions during rollout.
    return jsonify({'faces_count':len(dets),'bbox':{'x':x,'y':y,'w':w,'h':h},'yolo':yolo,'arcface':arc,'hybrid':hybrid,'lbph':yolo,'final_algorithm':'hybrid','final_id':hybrid.get('id',-1)})
@app.post('/sync_faces')
def sync_faces():
    try:
        replace=str(request.form.get('replace','0')).lower() in ('1','true','yes')
        staged=any(p.is_file() for p in INCOMING.iterdir()); use_staging=replace or staged; target=INCOMING if use_staging else FACES
        if replace:
            for p in INCOMING.iterdir():shutil.rmtree(p) if p.is_dir() else p.unlink(missing_ok=True)
        uploads=list(request.files.getlist('files'))+list(request.files.getlist('files[]'))
        for k in request.files.keys():
            if k.startswith('files[') and k.endswith(']') and k!='files[]':uploads.extend(request.files.getlist(k))
        if not uploads:return jsonify({'success':False,'error':'No face images received'}),400
        saved=[];skipped=[]
        for f in uploads:
            name=os.path.basename(f.filename or '')
            if not name or _parse_sid(name) is None:skipped.append({'file':name,'reason':'invalid filename'});continue
            dest=target/name;f.save(str(dest))
            if dest.stat().st_size<MIN_BYTES or cv2.imread(str(dest)) is None:dest.unlink(missing_ok=True);skipped.append({'file':name,'reason':'invalid or small image'});continue
            saved.append(name)
        return jsonify({'success':True,'saved':len(saved),'skipped':len(skipped),'staging':use_staging,'files':saved,'skipped_files':skipped})
    except Exception as e:traceback.print_exc();return jsonify({'success':False,'error':str(e)}),500

def _train_worker():
    try:
        STATUS_F.write_text(json.dumps({'state':'running','message':'Training YOLOv8n + ArcFace...','progress':5}),encoding='utf-8')
        staged=[p for p in INCOMING.iterdir() if p.is_file() and _parse_sid(p.name) is not None]
        if staged:
            for p in FACES.iterdir():
                if p.is_file() and p.suffix.lower() in ('.jpg','.jpeg','.png'):p.unlink(missing_ok=True)
            for p in staged:shutil.move(str(p),str(FACES/p.name))
        script=ROOT/'train_all_models.py'
        proc=subprocess.run([sys.executable,str(script)],cwd=str(ROOT),env=os.environ.copy(),text=True)
        if proc.returncode:raise RuntimeError('train_all_models.py exited with code '+str(proc.returncode))
        _load_yolo();_load_arc_db()
        with lock:ready['hybrid']=bool(ready['yolo'] and ready['arcface']);_sync_legacy_status()
        if not ready['hybrid']:raise RuntimeError('One or more trained models could not be loaded')
    except Exception as e:
        traceback.print_exc();STATUS_F.write_text(json.dumps({'state':'error','message':'Training failed: '+str(e),'progress':0,'result':{'error':str(e)}}),encoding='utf-8')
    else:STATUS_F.write_text(json.dumps({'state':'done','message':'YOLOv8n, ArcFace and Hybrid training completed.','progress':100,'result':{'yolo':True,'arcface':True,'hybrid':True}}),encoding='utf-8')
@app.post('/train')
def train():
    global train_thread
    if train_thread and train_thread.is_alive():return jsonify({'success':False,'message':'Training already in progress'}),409
    train_thread=threading.Thread(target=_train_worker,daemon=True);train_thread.start()
    return jsonify({'success':True,'message':'YOLOv8n + ArcFace + Hybrid training started'})
@app.get('/train/status')
def train_status():
    try:return jsonify(json.loads(STATUS_F.read_text(encoding='utf-8')))
    except Exception:return jsonify({'state':'unknown','message':'Training has not started yet.','progress':0})
@app.route('/reload',methods=['GET','POST'])
def reload_models():
    errors={}
    try:y=_load_yolo()
    except Exception as e:y=False;errors['yolo_error']=str(e)
    try:a=_load_arc_db()
    except Exception as e:a=False;errors['arcface_error']=str(e)
    with lock:ready['hybrid']=bool(y and a);_sync_legacy_status()
    return jsonify({'ok':True,'loaded':{'yolo':y,'arcface':a,'hybrid':ready['hybrid']},'models':ready,'errors':errors})

# Load light models in background so Gunicorn becomes reachable promptly.
threading.Thread(target=_models_load,daemon=True,name='model-loader').start()
if __name__=='__main__':app.run(host='0.0.0.0',port=int(os.getenv('PORT','5001')),threaded=True)
