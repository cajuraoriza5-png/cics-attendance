import zipfile
import urllib.request
import shutil
import gc
"""
train_all_models.py
===============================================================================
CICS Attendance - Model Training

Trains/prepares THREE recognition algorithms:

1. LBPH
   - OpenCV LBPHFaceRecognizer
   - Saved as trainer.yml

2. ArcFace
   - Uses InsightFace's pretrained buffalo_s model.
   - It is NOT retrained from scratch.
   - Each enrolled face is converted to an ArcFace embedding.
   - Embeddings + student IDs are saved to arcface_embeddings.npz.

3. Hybrid ArcFace + LBPH
   - No separate neural-network file is required.
   - The hybrid decision is performed by face_server.py by combining the
     ArcFace and LBPH results.

Input:
    faces/<student_id>_<capture_number>.jpg
    faces/<student_id>_<capture_number>.jpeg
    faces/<student_id>_<capture_number>.png

Output:
    trainer.yml
    arcface_embeddings.npz
===============================================================================
"""

import os
import json
import time
import traceback
import re
import hashlib
from collections import Counter

import cv2
import numpy as np

INSIGHTFACE_IMPORT_ERROR = ""


# =============================================================================
# PATHS
# =============================================================================

PROJECT = os.path.dirname(
    os.path.abspath(__file__)
)

FACES_DIR = os.path.join(
    PROJECT,
    "faces"
)

TRAINER = os.path.join(
    PROJECT,
    "trainer.yml"
)

ARC_DB = os.path.join(
    PROJECT,
    "arcface_embeddings.npz"
)

STATUS_F = os.path.join(
    FACES_DIR,
    ".train_status.json"
)


# =============================================================================
# CONFIG
# =============================================================================

MIN_SAMPLES_PER_STUDENT = int(
    os.environ.get(
        "MIN_SAMPLES_PER_STUDENT",
        "3"
    )
)

MIN_FILE_BYTES = int(
    os.environ.get(
        "MIN_FILE_BYTES",
        "1024"
    )
)


# =============================================================================
# STATUS
# =============================================================================

def update_status(
    state,
    message,
    progress=0,
    result=None
):
    payload = {
        "state": state,
        "message": message,
        "progress": int(
            max(
                0,
                min(
                    100,
                    progress
                )
            )
        ),
        "timestamp": time.strftime(
            "%Y-%m-%dT%H:%M:%S"
        )
    }

    if result is not None:
        payload["result"] = result

    try:
        os.makedirs(
            FACES_DIR,
            exist_ok=True
        )

        tmp = STATUS_F + ".tmp"

        with open(
            tmp,
            "w",
            encoding="utf-8"
        ) as f:
            json.dump(
                payload,
                f,
                indent=2
            )

        os.replace(
            tmp,
            STATUS_F
        )

    except Exception as e:
        print(
            f"[train] Status write failed: {e}",
            flush=True
        )


# =============================================================================
# DATASET
# =============================================================================

def parse_student_id(filename):
    match = re.match(
        r"^(\d+)(?:[_-].*)?\.(jpg|jpeg|png)$",
        filename,
        flags=re.IGNORECASE
    )

    if not match:
        return None

    return int(
        match.group(1)
    )


def iter_face_files():
    if not os.path.isdir(
        FACES_DIR
    ):
        return []

    files = []

    for filename in os.listdir(
        FACES_DIR
    ):
        path = os.path.join(
            FACES_DIR,
            filename
        )

        if not os.path.isfile(path):
            continue

        ext = os.path.splitext(
            filename
        )[1].lower()

        if ext not in (
            ".jpg",
            ".jpeg",
            ".png"
        ):
            continue

        try:
            if os.path.getsize(
                path
            ) < MIN_FILE_BYTES:
                continue
        except OSError:
            continue

        student_id = parse_student_id(
            filename
        )

        if student_id is None:
            continue

        files.append(
            (
                path,
                student_id,
                filename
            )
        )

    return sorted(
        files,
        key=lambda x: x[2].lower()
    )


def build_training_dataset():
    files = iter_face_files()

    if not files:
        raise RuntimeError(
            "No valid face images found in faces/."
        )

    counts = Counter(
        student_id
        for _, student_id, _ in files
    )

    usable_ids = {
        student_id
        for student_id, count in counts.items()
        if count >= MIN_SAMPLES_PER_STUDENT
    }

    dropped = {
        str(student_id): count
        for student_id, count in counts.items()
        if student_id not in usable_ids
    }

    files = [
        item
        for item in files
        if item[1] in usable_ids
    ]

    if not usable_ids:
        raise RuntimeError(
            "No student has enough face images. "
            f"Minimum is {MIN_SAMPLES_PER_STUDENT}."
        )

    return files, dropped


# =============================================================================
# LBPH
# =============================================================================

def load_gray_face(path):
    image = cv2.imread(
        path,
        cv2.IMREAD_GRAYSCALE
    )

    if image is None:
        return None

    image = cv2.resize(
        image,
        (100, 100),
        interpolation=cv2.INTER_AREA
    )

    image = cv2.equalizeHist(
        image
    )

    return image


def train_lbph(files):
    print(
        "[train] Training LBPH...",
        flush=True
    )

    if not (
        hasattr(cv2, "face")
        and hasattr(
            cv2.face,
            "LBPHFaceRecognizer_create"
        )
    ):
        raise RuntimeError(
            "opencv-contrib-python is required for LBPH."
        )

    faces = []
    labels = []
    embedding_files = []
    failed = []

    for path, student_id, filename in files:
        image = load_gray_face(
            path
        )

        if image is None:
            failed.append(
                filename
            )
            continue

        faces.append(
            image
        )

        labels.append(
            student_id
        )

    if not faces:
        raise RuntimeError(
            "No readable images were available for LBPH."
        )

    recognizer = (
        cv2.face.LBPHFaceRecognizer_create(
            radius=1,
            neighbors=8,
            grid_x=8,
            grid_y=8
        )
    )

    recognizer.train(
        faces,
        np.asarray(
            labels,
            dtype=np.int32
        )
    )

    temp = TRAINER + ".tmp"

    recognizer.write(
        temp
    )

    os.replace(
        temp,
        TRAINER
    )

    print(
        f"[train] LBPH saved: {TRAINER}",
        flush=True
    )

    return {
        "ok": True,
        "samples": len(faces),
        "students": len(
            set(labels)
        ),
        "failed_count": len(
            failed
        )
    }


# =============================================================================
# ARCFACE
# =============================================================================


def find_arcface_model():
    home = os.path.expanduser("~")
    candidates = [
        os.environ.get("ARCFACE_MODEL_PATH", "").strip(),
        os.path.join(home, ".insightface", "models", "buffalo_s", "w600k_mbf.onnx"),
        os.path.join(PROJECT, "models", "w600k_mbf.onnx"),
    ]

    for path in candidates:
        if path and os.path.isfile(path) and os.path.getsize(path) > 1024:
            return path

    for zip_path in [
        os.path.join(home, ".insightface", "models", "buffalo_s.zip"),
        os.path.join(PROJECT, "models", "buffalo_s.zip")
    ]:
        if not os.path.isfile(zip_path):
            continue

        target_dir = os.path.join(os.path.dirname(zip_path), "buffalo_s")
        target = os.path.join(target_dir, "w600k_mbf.onnx")
        try:
            os.makedirs(target_dir, exist_ok=True)
            with zipfile.ZipFile(zip_path, "r") as zf:
                member = next(
                    (n for n in zf.namelist()
                     if n.replace("\\", "/").endswith("w600k_mbf.onnx")),
                    None
                )
                if member:
                    with zf.open(member) as src, open(target, "wb") as dst:
                        shutil.copyfileobj(src, dst)
                    if os.path.isfile(target) and os.path.getsize(target) > 1024:
                        return target
        except Exception as e:
            print(f"[train] Model extraction failed: {e}", flush=True)

    return None


def download_arcface_model():
    home = os.path.expanduser("~")
    root = os.path.join(home, ".insightface", "models")
    os.makedirs(root, exist_ok=True)

    zip_path = os.path.join(root, "buffalo_s.zip")
    target_dir = os.path.join(root, "buffalo_s")
    target = os.path.join(target_dir, "w600k_mbf.onnx")

    if os.path.isfile(target) and os.path.getsize(target) > 1024:
        return target

    url = "https://github.com/deepinsight/insightface/releases/download/v0.7/buffalo_s.zip"
    tmp = zip_path + ".part"

    print("[train] Downloading ArcFace model pack...", flush=True)

    with urllib.request.urlopen(url, timeout=60) as response, open(tmp, "wb") as dst:
        while True:
            chunk = response.read(1024 * 1024)
            if not chunk:
                break
            dst.write(chunk)

    os.replace(tmp, zip_path)
    os.makedirs(target_dir, exist_ok=True)

    with zipfile.ZipFile(zip_path, "r") as zf:
        member = next(
            (n for n in zf.namelist()
             if n.replace("\\", "/").endswith("w600k_mbf.onnx")),
            None
        )
        if not member:
            raise RuntimeError("w600k_mbf.onnx not found.")

        with zf.open(member) as src, open(target, "wb") as dst:
            shutil.copyfileobj(src, dst)

    return target


def get_arcface():
    from insightface import model_zoo

    path = find_arcface_model()
    if path is None:
        path = download_arcface_model()

    print(f"[train] Loading lightweight ArcFace: {path}", flush=True)

    model = model_zoo.get_model(
        path,
        providers=["CPUExecutionProvider"]
    )
    model.prepare(ctx_id=-1)

    print("[train] Lightweight ArcFace loaded [OK]", flush=True)
    return model


def normalize_embedding(embedding):
    embedding = np.asarray(embedding, dtype=np.float32)

    if embedding.ndim != 1 or embedding.size == 0:
        return None

    norm = np.linalg.norm(embedding)
    if norm <= 1e-8:
        return None

    return (embedding / norm).astype(np.float32)


def align_for_arcface(face):
    h, w = face.shape[:2]
    if w < 20 or h < 20:
        return None

    src = np.array([
        [0.32*w, 0.38*h],
        [0.68*w, 0.38*h],
        [0.50*w, 0.56*h],
        [0.38*w, 0.72*h],
        [0.62*w, 0.72*h],
    ], dtype=np.float32)

    dst = np.array([
        [38.2946, 51.6963],
        [73.5318, 51.5014],
        [56.0252, 71.7366],
        [41.5493, 92.3655],
        [70.7299, 92.2041],
    ], dtype=np.float32)

    M, _ = cv2.estimateAffinePartial2D(src, dst, method=cv2.LMEDS)

    if M is None:
        return cv2.resize(face, (112,112), interpolation=cv2.INTER_AREA)

    return cv2.warpAffine(
        face, M, (112,112), borderMode=cv2.BORDER_REPLICATE
    )


def file_signature(path):
    try:
        st=os.stat(path); return hashlib.sha1(f'{st.st_size}:{st.st_mtime_ns}'.encode()).hexdigest()
    except OSError: return None

def load_arcface_cache():
    if not os.path.isfile(ARC_DB): return {}, {}, {}
    try:
        db=np.load(ARC_DB,allow_pickle=False); e=np.asarray(db['embeddings'],dtype=np.float32); l=np.asarray(db['labels'],dtype=np.int32); n=np.asarray(db['filenames'],dtype=str); sg=np.asarray(db['signatures'],dtype=str) if 'signatures' in db.files else np.asarray([],dtype=str); db.close()
        return {str(x):e[i] for i,x in enumerate(n)}, {str(x):int(l[i]) for i,x in enumerate(n)}, ({str(x):str(sg[i]) for i,x in enumerate(n)} if len(sg)==len(n) else {})
    except Exception as e: print(f'[train] Cache ignored: {e}',flush=True); return {},{},{}

def train_arcface(files, model):
    cached, cached_labels, cached_sigs=load_arcface_cache(); embeddings=[]; labels=[]; names=[]; sigs=[]; failed=[]; reused=0; generated=0; total=len(files); batch_size=int(os.environ.get('ARCFACE_BATCH_SIZE','16'))
    cascade=cv2.CascadeClassifier(cv2.data.haarcascades+'haarcascade_frontalface_default.xml'); bf=[]; bl=[]; bn=[]; bs=[]
    def flush():
        nonlocal generated
        if not bf:return
        features=model.get_feat(bf)
        for emb,label,name,sig in zip(features,bl,bn,bs):
            norm=normalize_embedding(emb)
            if norm is None: failed.append(name); continue
            embeddings.append(norm); labels.append(label); names.append(name); sigs.append(sig); generated+=1
        bf.clear();bl.clear();bn.clear();bs.clear()
    for i,(path,sid,filename) in enumerate(files,1):
        sig=file_signature(path)
        if sig and filename in cached and cached_labels.get(filename)==sid and cached_sigs.get(filename)==sig:
            embeddings.append(cached[filename]); labels.append(sid); names.append(filename); sigs.append(sig); reused+=1
        else:
            try:
                image=cv2.imread(path,cv2.IMREAD_COLOR)
                if image is None: failed.append(filename); continue
                gray=cv2.equalizeHist(cv2.cvtColor(image,cv2.COLOR_BGR2GRAY)); boxes=cascade.detectMultiScale(gray,1.10,4,minSize=(40,40))
                if len(boxes)==0: boxes=cascade.detectMultiScale(gray,1.05,3,minSize=(30,30))
                if len(boxes)==0: failed.append(filename); continue
                x,y,w,h=max(boxes,key=lambda r:r[2]*r[3]); px,py=int(w*.12),int(h*.12); x1,y1=max(0,x-px),max(0,y-py); x2,y2=min(image.shape[1],x+w+px),min(image.shape[0],y+h+py); aligned=align_for_arcface(image[y1:y2,x1:x2])
                if aligned is None: failed.append(filename); continue
                bf.append(aligned);bl.append(sid);bn.append(filename);bs.append(sig)
                if len(bf)>=batch_size: flush()
            except Exception as e: print(f'[train] ArcFace failed for {filename}: {e}',flush=True); failed.append(filename)
        if i%batch_size==0 or i==total: update_status('running',f'ArcFace {i}/{total} ({reused} reused, {generated} generated)',40+int(i/max(1,total)*45))
    flush()
    if not embeddings: raise RuntimeError('ArcFace could not generate any embeddings.')
    temp=ARC_DB+'.tmp'; np.savez_compressed(temp,embeddings=np.asarray(embeddings,dtype=np.float32),labels=np.asarray(labels,dtype=np.int32),filenames=np.asarray(names,dtype='U512'),signatures=np.asarray(sigs,dtype='U128')); actual=temp if os.path.exists(temp) else temp+'.npz'; os.replace(actual,ARC_DB); gc.collect()
    return {'ok':True,'samples':len(embeddings),'students':len(np.unique(labels)),'failed_count':len(failed),'failed_images':failed[:50],'batch_size':batch_size,'reused':reused,'generated':generated}


# =============================================================================
# VALIDATION / ACCURACY
# =============================================================================

def _validation_split(files, validation_ratio=0.20):
    """
    Create a deterministic per-student validation split.

    This is NOT training accuracy. Validation images are excluded from the
    temporary validation models/references so the reported accuracy is a
    better estimate of recognition performance on unseen enrolled images.
    """
    grouped = {}
    for item in files:
        grouped.setdefault(item[1], []).append(item)

    train_files = []
    val_files = []

    for student_id, items in sorted(grouped.items()):
        items = sorted(items, key=lambda x: x[2].lower())

        if len(items) < 2:
            train_files.extend(items)
            continue

        val_count = max(1, int(round(len(items) * validation_ratio)))
        if len(items) - val_count < 1:
            val_count = len(items) - 1

        # Deterministic ordering avoids changing the metric on every retrain.
        ranked = sorted(
            items,
            key=lambda x: hashlib.sha256(x[2].encode("utf-8")).hexdigest()
        )
        val_set = {x[2] for x in ranked[:val_count]}

        for item in items:
            if item[2] in val_set:
                val_files.append(item)
            else:
                train_files.append(item)

    return train_files, val_files


def _prepare_arcface_embedding(path, cascade):
    image = cv2.imread(path, cv2.IMREAD_COLOR)
    if image is None:
        return None

    gray = cv2.equalizeHist(
        cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
    )

    boxes = cascade.detectMultiScale(
        gray, 1.10, 4, minSize=(40, 40)
    )

    if len(boxes) == 0:
        boxes = cascade.detectMultiScale(
            gray, 1.05, 3, minSize=(30, 30)
        )

    if len(boxes) == 0:
        return None

    x, y, w, h = max(boxes, key=lambda r: r[2] * r[3])

    px, py = int(w * 0.12), int(h * 0.12)
    x1, y1 = max(0, x - px), max(0, y - py)
    x2, y2 = min(image.shape[1], x + w + px), min(image.shape[0], y + h + py)

    aligned = align_for_arcface(image[y1:y2, x1:x2])
    return aligned


def _lbph_score(distance):
    raw = (1.0 - float(distance) / 150.0) * 100.0
    return max(0.0, min(100.0, raw + 35.0))


def _arcface_display_score(similarity):
    value = ((float(similarity) + 1.0) / 2.0) * 100.0
    return max(0.0, min(100.0, value))


def evaluate_validation(files, arc_model=None):
    """
    FAST validation for the 512 MB Render instance.

    IMPORTANT:
    - Does NOT run ArcFace inference again.
    - Uses the embeddings that were already generated during training.
    - Uses a small deterministic sample per student for LBPH/Hybrid checks.
    - This is a quick model-consistency validation, not a full hold-out test.

    Keeping validation lightweight prevents a second large ArcFace workload
    immediately after the embedding database has been created.
    """
    try:
        db = np.load(ARC_DB, allow_pickle=False)
        arc_embeddings = np.asarray(db["embeddings"], dtype=np.float32)
        arc_labels = np.asarray(db["labels"], dtype=np.int32)
        db_filenames = np.asarray(
            db["filenames"], dtype=str
        ) if "filenames" in db.files else None
    except Exception as e:
        return {"available": False, "reason": f"Could not load ArcFace DB: {e}"}

    if len(arc_embeddings) < 2:
        return {"available": False, "reason": "Not enough ArcFace embeddings."}

    # Normalize defensively.
    norms = np.linalg.norm(arc_embeddings, axis=1, keepdims=True)
    arc_embeddings = arc_embeddings / np.maximum(norms, 1e-8)

    # Leave-one-out ArcFace nearest-neighbor accuracy.
    arc_correct = 0
    arc_total = 0
    consensus = 0
    hybrid_correct = 0
    hybrid_total = 0

    # A tiny deterministic LBPH sample keeps validation fast on Render.
    max_per_student = int(os.environ.get("FAST_VALIDATION_PER_STUDENT", "3"))
    selected = []
    grouped = {}
    for item in files:
        grouped.setdefault(item[1], []).append(item)
    for sid in sorted(grouped):
        items = sorted(grouped[sid], key=lambda x: x[2].lower())
        ranked = sorted(
            items,
            key=lambda x: hashlib.sha256(x[2].encode("utf-8")).hexdigest()
        )
        selected.extend(ranked[:max_per_student])

    lb_model = None
    try:
        if os.path.exists(TRAINER):
            lb_model = cv2.face.LBPHFaceRecognizer_create(
                radius=1, neighbors=8, grid_x=8, grid_y=8
            )
            lb_model.read(TRAINER)
    except Exception as e:
        print(f"[train] Fast validation LBPH load warning: {e}", flush=True)
        lb_model = None

    # Map stored embedding filenames to indices for Hybrid checks.
    filename_to_indices = {}
    if db_filenames is not None:
        for i, name in enumerate(db_filenames.tolist()):
            filename_to_indices.setdefault(str(name), []).append(i)

    # ArcFace quick accuracy: each stored embedding predicts against all other
    # stored embeddings. This requires no ArcFace neural-network inference.
    for i in range(len(arc_embeddings)):
        sims = np.dot(arc_embeddings, arc_embeddings[i])
        sims[i] = -2.0
        best = int(np.argmax(sims))
        predicted = int(arc_labels[best])
        true_id = int(arc_labels[i])
        arc_total += 1
        arc_correct += int(predicted == true_id)

    # Hybrid quick sample. ArcFace identity comes from the saved embedding;
    # LBPH identity comes from the actual image.
    for path, true_id, filename in selected:
        try:
            indices = filename_to_indices.get(filename, [])
            if not indices:
                continue

            # Use the first matching embedding for this image.
            i = int(indices[0])
            sims = np.dot(arc_embeddings, arc_embeddings[i])
            sims[i] = -2.0
            best = int(np.argmax(sims))
            arc_id = int(arc_labels[best])

            lb_id = -1
            if lb_model is not None:
                gray = load_gray_face(path)
                if gray is not None:
                    pred, dist = lb_model.predict(gray)
                    lb_id = int(pred)
                    lb_conf = _lbph_score(dist)
                else:
                    lb_conf = 0.0
            else:
                lb_conf = 0.0

            # ArcFace quick display score from top similarity.
            arc_conf = _arcface_display_score(float(sims[best]))

            if arc_id == lb_id and arc_id > 0:
                hybrid_id = arc_id
                consensus += 1
                hybrid_conf = min(99.9, max(arc_conf, lb_conf) +
                                  (100.0 - max(arc_conf, lb_conf)) * 0.20)
            elif arc_id > 0:
                hybrid_id = arc_id
                hybrid_conf = arc_conf * 0.90
            elif lb_id > 0:
                hybrid_id = lb_id
                hybrid_conf = lb_conf * 0.90
            else:
                hybrid_id = -1
                hybrid_conf = 0.0

            hybrid_total += 1
            hybrid_correct += int(hybrid_id == int(true_id))
        except Exception as e:
            print(f"[train] Fast validation skipped {filename}: {e}", flush=True)

    result = {
        "available": True,
        "validation_type": "quick_consistency_validation",
        "arcface_validation_images": arc_total,
        "hybrid_validation_images": hybrid_total,
        "lbph_hybrid_sample_per_student": max_per_student,
        "students": len(grouped),
        "arcface_accuracy": round(arc_correct / max(1, arc_total) * 100.0, 1),
        "hybrid_accuracy": round(hybrid_correct / max(1, hybrid_total) * 100.0, 1),
        "hybrid_consensus_rate": round(consensus / max(1, hybrid_total) * 100.0, 1),
    }

    # LBPH-only accuracy is measured from the same small sample.
    lb_correct = 0
    lb_total = 0
    if lb_model is not None:
        for path, true_id, _ in selected:
            try:
                gray = load_gray_face(path)
                if gray is None:
                    continue
                pred, _ = lb_model.predict(gray)
                lb_total += 1
                lb_correct += int(int(pred) == int(true_id))
            except Exception:
                continue

    result["lbph_validation_images"] = lb_total
    result["lbph_accuracy"] = round(lb_correct / max(1, lb_total) * 100.0, 1)
    result["hybrid_acceptance_rate"] = 100.0
    result["hybrid_accepted_correct_rate"] = result["hybrid_accuracy"]

    del arc_embeddings
    del arc_labels
    if db_filenames is not None:
        del db_filenames
    gc.collect()

    return result

def _arcface_align_from_validation_crop(face_color):
    """
    Same alignment used by training, kept as a separate helper so validation
    uses exactly the same ArcFace preprocessing.
    """
    return align_for_arcface(face_color)


# =============================================================================
# MAIN
# =============================================================================

def main():
    update_status('starting','Initializing FAST LBPH + ArcFace training...',0)
    try:
        files,dropped=build_training_dataset(); counts=Counter(x[1] for x in files); update_status('running',f'Dataset ready: {len(files)} images / {len(counts)} students.',5)
        update_status('running','Training LBPH...',10); lbph_result=train_lbph(files); update_status('running','LBPH completed. No second validation training.',35,{'lbph':lbph_result})
        _,_,cache_sigs=load_arcface_cache(); all_cached=bool(cache_sigs) and all(file_signature(path)==cache_sigs.get(name) for path,_,name in files)
        if all_cached:
            db=np.load(ARC_DB,allow_pickle=False); arc_result={'ok':True,'samples':int(len(db['embeddings'])),'students':int(len(np.unique(db['labels']))),'reused':int(len(db['embeddings'])),'generated':0,'cache_only':True}; db.close(); update_status('running','ArcFace cache current. Skipping embedding generation.',88,{'lbph':lbph_result,'arcface':arc_result})
        else:
            update_status('running','Loading ArcFace for new/changed images...',38,{'lbph':lbph_result}); arc_model=get_arcface(); arc_result=train_arcface(files,arc_model); del arc_model; gc.collect(); update_status('running',f'ArcFace ready: {arc_result["generated"]} generated / {arc_result["reused"]} reused.',88,{'lbph':lbph_result,'arcface':arc_result})
        hybrid={'ok':os.path.exists(TRAINER) and os.path.exists(ARC_DB),'type':'decision_fusion','threshold':80.0}; result={'lbph':lbph_result,'arcface':arc_result,'hybrid':hybrid,'accuracy':{'validation_skipped':True,'reason':'Fast training mode'},'samples':len(files),'students':len(counts),'dropped_students':dropped}
        if not hybrid['ok']: raise RuntimeError('Required model files were not created.')
        update_status('done','FAST training completed. LBPH + ArcFace + Hybrid ready at 80%.',100,result); return 0
    except Exception as e:
        traceback.print_exc(); update_status('error',f'Training failed: {e}',0,{'error':str(e)}); return 1

if __name__ == "__main__":
    raise SystemExit(
        main()
    )
