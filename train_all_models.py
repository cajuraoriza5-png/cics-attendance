"""Train YOLOv8n student classifier and prepare ArcFace embeddings.
Input filenames: faces/<database_user_id>_<capture>.jpg (or <id>.jpg).
Outputs: models/yolov8n_student_cls/weights/best.pt and arcface_embeddings.npz.
Run on Render through POST /train, or locally with python train_all_models.py.
"""
import os, re, json, time, shutil, random, gc
from pathlib import Path
from collections import defaultdict
import cv2
import numpy as np

ROOT = Path(__file__).resolve().parent
FACES = ROOT / 'faces'
MODELS = ROOT / 'models'
DATASET = ROOT / 'yolo_student_dataset'
ARC_DB = ROOT / 'arcface_embeddings.npz'
YOLO_PROJECT = MODELS / 'yolov8n_student_cls'
STATUS = FACES / '.train_status.json'
MIN_BYTES = int(os.getenv('MIN_FILE_BYTES', '1024'))
MIN_SAMPLES = int(os.getenv('MIN_SAMPLES_PER_STUDENT', '3'))
EPOCHS = int(os.getenv('YOLO_EPOCHS', '20'))
IMG_SIZE = int(os.getenv('YOLO_IMG_SIZE', '160'))
SEED = int(os.getenv('YOLO_SEED', '42'))


def status(state, message, progress, result=None):
    FACES.mkdir(parents=True, exist_ok=True)
    payload={'state':state,'message':message,'progress':int(progress),'timestamp':time.strftime('%Y-%m-%dT%H:%M:%S')}
    if result is not None: payload['result']=result
    tmp=STATUS.with_suffix('.tmp')
    tmp.write_text(json.dumps(payload, indent=2), encoding='utf-8')
    tmp.replace(STATUS)


def parse_sid(name):
    m=re.match(r'^(\d+)(?:[_-].*)?\.(jpg|jpeg|png)$', name, re.I)
    return int(m.group(1)) if m else None


def largest_face_crop(image, cascade):
    if image is None: return None
    h,w=image.shape[:2]
    gray=cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
    faces=()
    if cascade is not None and not cascade.empty():
        try: faces=cascade.detectMultiScale(gray, scaleFactor=1.1, minNeighbors=4, minSize=(28,28))
        except cv2.error: faces=()
    if len(faces):
        x,y,fw,fh=max(faces,key=lambda b:int(b[2])*int(b[3]))
        px,py=int(fw*.12),int(fh*.12)
        x1=max(0,x-px); y1=max(0,y-py); x2=min(w,x+fw+px); y2=min(h,y+fh+py)
        crop=image[y1:y2,x1:x2]
    else:
        # Registered captures are expected to contain a face. Center-square fallback
        # preserves tightly cropped registration photos when Haar misses a face.
        side=min(h,w); x1=(w-side)//2; y1=(h-side)//2
        crop=image[y1:y1+side,x1:x1+side]
    if crop is None or crop.size==0: return None
    return cv2.resize(crop,(112,112),interpolation=cv2.INTER_AREA)


def get_face_files():
    groups=defaultdict(list)
    for p in sorted(FACES.iterdir() if FACES.exists() else []):
        if not p.is_file() or p.suffix.lower() not in ('.jpg','.jpeg','.png'): continue
        try:
            if p.stat().st_size < MIN_BYTES: continue
        except OSError: continue
        sid=parse_sid(p.name)
        if sid is not None and cv2.imread(str(p)) is not None: groups[sid].append(p)
    return {sid:ps for sid,ps in groups.items() if len(ps)>=MIN_SAMPLES}, {sid:len(ps) for sid,ps in groups.items() if len(ps)<MIN_SAMPLES}


def prepare_yolo_dataset(groups):
    if DATASET.exists(): shutil.rmtree(DATASET)
    rng=random.Random(SEED)
    cascade=cv2.CascadeClassifier(cv2.data.haarcascades+'haarcascade_frontalface_default.xml')
    class_names=[str(sid) for sid in sorted(groups)]
    # Ultralytics classification format: train/<class>, val/<class>.
    counts={}
    for sid,paths in sorted(groups.items()):
        paths=list(paths); rng.shuffle(paths)
        val_n=max(1, round(len(paths)*0.2)) if len(paths)>1 else 0
        val_paths=paths[:val_n]; train_paths=paths[val_n:] or paths
        counts[str(sid)]={'train':0,'val':0}
        for split,items in [('train',train_paths),('val',val_paths)]:
            dest=DATASET/split/str(sid); dest.mkdir(parents=True,exist_ok=True)
            for i,p in enumerate(items):
                img=cv2.imread(str(p))
                crop=largest_face_crop(img,cascade)
                if crop is None: continue
                out=dest/f'{p.stem}_{i}.jpg'
                if cv2.imwrite(str(out),crop): counts[str(sid)][split]+=1
    # Remove classes with no usable train images, fail if class has no train sample.
    empty=[sid for sid,c in counts.items() if c['train']==0]
    if empty: raise RuntimeError('No usable training crops for student IDs: '+', '.join(empty))
    return class_names,counts


def build_arcface_db(groups):
    from insightface import model_zoo
    import urllib.request, zipfile
    home=Path.home(); model_path=home/'.insightface/models/buffalo_s/w600k_mbf.onnx'
    if not model_path.exists():
        model_path.parent.mkdir(parents=True,exist_ok=True)
        zip_path=model_path.parents[1]/'buffalo_s.zip'
        url='https://github.com/deepinsight/insightface/releases/download/v0.7/buffalo_s.zip'
        if not zip_path.exists():
            with urllib.request.urlopen(url,timeout=90) as r, open(zip_path,'wb') as f: shutil.copyfileobj(r,f)
        with zipfile.ZipFile(zip_path) as z:
            member=next((n for n in z.namelist() if n.replace('\\','/').endswith('w600k_mbf.onnx')),None)
            if not member: raise RuntimeError('w600k_mbf.onnx missing from buffalo_s.zip')
            with z.open(member) as src, open(model_path,'wb') as dst: shutil.copyfileobj(src,dst)
    model=model_zoo.get_model(str(model_path),providers=['CPUExecutionProvider']); model.prepare(ctx_id=-1)
    cascade=cv2.CascadeClassifier(cv2.data.haarcascades+'haarcascade_frontalface_default.xml')
    embeddings=[]; labels=[]; files=[]
    for sid,paths in sorted(groups.items()):
        for p in paths:
            image=cv2.imread(str(p)); crop=largest_face_crop(image,cascade)
            if crop is None: continue
            # Same deterministic alignment used by face_server.
            h,w=crop.shape[:2]
            src=np.array([[.32*w,.38*h],[.68*w,.38*h],[.50*w,.56*h],[.38*w,.72*h],[.62*w,.72*h]],np.float32)
            dst=np.array([[38.2946,51.6963],[73.5318,51.5014],[56.0252,71.7366],[41.5493,92.3655],[70.7299,92.2041]],np.float32)
            M,_=cv2.estimateAffinePartial2D(src,dst,method=cv2.LMEDS)
            aligned=cv2.warpAffine(crop,M,(112,112),borderMode=cv2.BORDER_REPLICATE) if M is not None else crop
            feat=np.asarray(model.get_feat([aligned])[0],np.float32).reshape(-1)
            norm=np.linalg.norm(feat)
            if norm>1e-8:
                embeddings.append(feat/norm); labels.append(sid); files.append(p.name)
    if not embeddings: raise RuntimeError('ArcFace generated no usable embeddings')
    np.savez_compressed(ARC_DB, embeddings=np.asarray(embeddings,np.float32), labels=np.asarray(labels,np.int32), filenames=np.asarray(files,dtype='U256'))
    del model; gc.collect()
    return len(embeddings)


def main():
    status('running','Reading enrolled face images...',5)
    groups,dropped=get_face_files()
    if len(groups)<2: raise RuntimeError('YOLO classification requires at least 2 student classes with at least %d valid images each.'%MIN_SAMPLES)
    samples=sum(map(len,groups.values()))
    status('running',f'Preparing crops for {len(groups)} students ({samples} images)...',12)
    classes,counts=prepare_yolo_dataset(groups)
    status('running','Training YOLOv8n student identity classifier...',20,{'students':len(groups),'samples':samples,'class_counts':counts})
    from ultralytics import YOLO
    # YOLOv8n classification weights; training can download this pretrained checkpoint.
    model=YOLO(os.getenv('YOLO_CLASSIFY_WEIGHTS','yolov8n-cls.pt'))
    model.train(data=str(DATASET), epochs=EPOCHS, imgsz=IMG_SIZE, batch=int(os.getenv('YOLO_BATCH','8')), device='cpu', workers=0, project=str(MODELS), name='yolov8n_student_cls', exist_ok=True, patience=max(5,EPOCHS//3), seed=SEED, verbose=True, plots=False)
    best=YOLO_PROJECT/'weights'/'best.pt'
    last=YOLO_PROJECT/'weights'/'last.pt'
    if not best.exists() and last.exists(): shutil.copy2(last,best)
    if not best.exists(): raise RuntimeError('YOLO training did not produce models/yolov8n_student_cls/weights/best.pt')
    del model; gc.collect()
    status('running','Generating ArcFace embeddings...',75)
    arc_samples=build_arcface_db(groups)
    result={'yolo':{'ok':True,'weights':str(best.relative_to(ROOT)),'classes':len(groups),'samples':samples,'epochs':EPOCHS,'imgsz':IMG_SIZE,'class_counts':counts},'arcface':{'ok':True,'embeddings':arc_samples},'hybrid':{'ok':True,'description':'YOLO student-class probability + ArcFace candidate similarity'},'dropped_students':{str(k):v for k,v in dropped.items()}}
    status('done','YOLOv8n, ArcFace, and Hybrid training completed.',100,result)
    print(json.dumps(result,indent=2))

if __name__=='__main__':
    try: main()
    except Exception as e:
        status('error','Training failed: '+str(e),0,{'error':str(e)})
        raise
