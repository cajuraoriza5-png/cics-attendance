#!/usr/bin/env python3
"""
CICS Attendance standalone trainer
Algorithms: LBPH, ArcFace embeddings, Hybrid ArcFace + LBPH.

This script is compatible with the Render face_server.py. The running Flask
server performs the same operations through POST /train; this file is useful
for local/manual training and for keeping the repository self-contained.
"""
import os, json, time, re, traceback
from collections import Counter
import cv2
import numpy as np

try:
    from insightface.app import FaceAnalysis
except Exception as exc:
    FaceAnalysis = None
    INSIGHTFACE_ERROR = str(exc)

PROJECT = os.path.dirname(os.path.abspath(__file__))
FACES_DIR = os.path.join(PROJECT, "faces")
TRAINER = os.path.join(PROJECT, "trainer.yml")
ARC_DB = os.path.join(PROJECT, "arcface_embeddings.npz")
STATUS_F = os.path.join(FACES_DIR, ".train_status.json")
ARCFACE_THRESHOLD = float(os.environ.get("ARCFACE_THRESHOLD", "0.55"))
MIN_SAMPLES_PER_STUDENT = int(os.environ.get("MIN_SAMPLES_PER_STUDENT", "5"))
MIN_FILE_BYTES = int(os.environ.get("MIN_FILE_BYTES", "1024"))

def status(state, message, progress, result=None):
    payload = {"state": state, "message": message, "progress": int(progress), "timestamp": time.strftime("%Y-%m-%dT%H:%M:%S")}
    if result is not None: payload["result"] = result
    os.makedirs(FACES_DIR, exist_ok=True)
    tmp = STATUS_F + ".tmp"
    with open(tmp, "w", encoding="utf-8") as f: json.dump(payload, f, indent=2)
    os.replace(tmp, STATUS_F)
    print(f"[train] {progress}% - {message}", flush=True)

def student_id(filename):
    m = re.match(r"^(\d+)(?:[_-].*)?\.(jpg|jpeg|png)$", filename, re.I)
    return int(m.group(1)) if m else None

def files():
    out=[]
    if not os.path.isdir(FACES_DIR): return out
    for name in sorted(os.listdir(FACES_DIR)):
        path=os.path.join(FACES_DIR,name)
        if not os.path.isfile(path) or os.path.getsize(path)<MIN_FILE_BYTES: continue
        sid=student_id(name)
        if sid is not None and os.path.splitext(name)[1].lower() in (".jpg",".jpeg",".png"):
            out.append((path,sid,name))
    return out

def train_lbph(items):
    if not hasattr(cv2,"face") or not hasattr(cv2.face,"LBPHFaceRecognizer_create"):
        raise RuntimeError("opencv-contrib-python is required for LBPH")
    images=[]; labels=[]
    for path,sid,_ in items:
        img=cv2.imread(path,cv2.IMREAD_GRAYSCALE)
        if img is None: continue
        img=cv2.resize(img,(100,100),interpolation=cv2.INTER_AREA)
        img=cv2.equalizeHist(img)
        images.append(img); labels.append(sid)
    if not images: raise RuntimeError("No readable face images for LBPH")
    rec=cv2.face.LBPHFaceRecognizer_create(radius=1,neighbors=8,grid_x=8,grid_y=8)
    rec.train(images,np.asarray(labels,dtype=np.int32))
    tmp=TRAINER+".tmp"; rec.write(tmp); os.replace(tmp,TRAINER)
    return {"ok":True,"samples":len(images),"students":len(set(labels))}

def make_arcface():
    if FaceAnalysis is None:
        raise RuntimeError("InsightFace unavailable: "+globals().get("INSIGHTFACE_ERROR","unknown"))
    model=FaceAnalysis(name="buffalo_s",providers=["CPUExecutionProvider"])
    model.prepare(ctx_id=-1,det_size=(640,640))
    return model

def train_arcface(items, model):
    embeddings=[]; labels=[]; failed=[]
    for i,(path,sid,name) in enumerate(items,1):
        try:
            img=cv2.imread(path,cv2.IMREAD_COLOR)
            faces=model.get(img) if img is not None else []
            if not faces: failed.append(name); continue
            face=max(faces,key=lambda f: float(getattr(f,"det_score",0) or 0))
            emb=np.asarray(face.embedding,dtype=np.float32)
            norm=np.linalg.norm(emb)
            if emb.ndim!=1 or emb.size==0 or norm<=1e-8: failed.append(name); continue
            embeddings.append(emb/norm); labels.append(sid)
            status("running",f"Generating ArcFace embeddings {i}/{len(items)}...",35+int(i/max(1,len(items))*45))
        except Exception:
            failed.append(name)
    if not embeddings: raise RuntimeError("ArcFace could not generate any embeddings")
    emb=np.asarray(embeddings,dtype=np.float32); lab=np.asarray(labels,dtype=np.int32)
    tmp=ARC_DB+".tmp.npz"; np.savez_compressed(tmp,embeddings=emb,labels=lab); os.replace(tmp,ARC_DB)
    return {"ok":True,"samples":len(emb),"students":len(set(labels)),"failed_count":len(failed),"failed_images":failed[:50]}

def main():
    try:
        status("starting","Initializing LBPH + ArcFace + Hybrid training...",0)
        all_items=files()
        if not all_items: raise RuntimeError("No valid face images found in faces/")
        counts=Counter(sid for _,sid,_ in all_items)
        usable={sid for sid,n in counts.items() if n>=MIN_SAMPLES_PER_STUDENT}
        items=[x for x in all_items if x[1] in usable]
        dropped={str(sid):n for sid,n in counts.items() if sid not in usable}
        if not usable: raise RuntimeError("No student has enough face images for training")
        status("running",f"Dataset ready: {len(items)} images / {len(usable)} students.",15,{"students":len(usable),"samples":len(items),"samples_per_student":dict(counts),"dropped_students":dropped})
        status("running","Training LBPH...",20)
        lbph=train_lbph(items)
        status("running","LBPH completed. Loading ArcFace...",35,{"lbph":lbph})
        model=make_arcface()
        arc=train_arcface(items,model)
        status("running","Building Hybrid ArcFace + LBPH...",90,{"lbph":lbph,"arcface":arc})
        result={"lbph":lbph,"arcface":arc,"hybrid":{"ok":True,"students":len(usable),"method":"ArcFace primary + LBPH verification"},"samples":len(items),"students":len(usable),"dropped_students":dropped}
        status("done","LBPH + ArcFace + Hybrid training completed.",100,result)
        print("[train] TRAINING COMPLETED SUCCESSFULLY",flush=True)
    except Exception as exc:
        traceback.print_exc()
        status("error",f"Training failed: {exc}",0,{"error":str(exc)})
        raise

if __name__=="__main__": main()
