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


def train_arcface(files, model):
    print("[train] Generating ArcFace embeddings in batches...", flush=True)

    embeddings = []
    labels = []
    failed = []
    total = len(files)
    batch_size = int(os.environ.get("ARCFACE_BATCH_SIZE", "8"))

    cascade = cv2.CascadeClassifier(
        cv2.data.haarcascades + "haarcascade_frontalface_default.xml"
    )

    batch_faces = []
    batch_labels = []
    batch_names = []

    def flush_batch():
        if not batch_faces:
            return

        features = model.get_feat(batch_faces)

        for emb, label, name in zip(features, batch_labels, batch_names):
            norm = normalize_embedding(emb)
            if norm is None:
                failed.append(name)
                continue
            embeddings.append(norm)
            labels.append(label)
            embedding_files.append(name)

        batch_faces.clear()
        batch_labels.clear()
        batch_names.clear()

    for index, (path, student_id, filename) in enumerate(files, start=1):
        try:
            image = cv2.imread(path, cv2.IMREAD_COLOR)
            if image is None:
                failed.append(filename)
                continue

            gray = cv2.equalizeHist(
                cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
            )

            boxes = cascade.detectMultiScale(
                gray, 1.10, 4, minSize=(40,40)
            )

            if len(boxes) == 0:
                boxes = cascade.detectMultiScale(
                    gray, 1.05, 3, minSize=(30,30)
                )

            if len(boxes) == 0:
                failed.append(filename)
                continue

            x, y, w, h = max(boxes, key=lambda r: r[2] * r[3])

            px, py = int(w*0.12), int(h*0.12)
            x1, y1 = max(0,x-px), max(0,y-py)
            x2, y2 = min(image.shape[1],x+w+px), min(image.shape[0],y+h+py)

            aligned = align_for_arcface(image[y1:y2, x1:x2])
            if aligned is None:
                failed.append(filename)
                continue

            batch_faces.append(aligned)
            batch_labels.append(student_id)
            batch_names.append(filename)

            if len(batch_faces) >= batch_size:
                flush_batch()

            if index % batch_size == 0 or index == total:
                progress = 40 + int((index / max(1,total)) * 45)
                update_status(
                    "running",
                    f"ArcFace embeddings {index}/{total}...",
                    progress
                )

        except Exception as e:
            print(f"[train] ArcFace failed for {filename}: {e}", flush=True)
            failed.append(filename)

    flush_batch()

    if not embeddings:
        raise RuntimeError("ArcFace could not generate any embeddings.")

    embedding_array = np.asarray(embeddings, dtype=np.float32)
    label_array = np.asarray(labels, dtype=np.int32)
    file_array = np.asarray(embedding_files, dtype="U512")

    temp = ARC_DB + ".tmp"
    np.savez_compressed(
        temp,
        embeddings=embedding_array,
        labels=label_array,
        filenames=file_array
    )

    actual_temp = temp if os.path.exists(temp) else temp + ".npz"
    os.replace(actual_temp, ARC_DB)

    gc.collect()

    print(f"[train] ArcFace DB saved: {ARC_DB}", flush=True)

    return {
        "ok": True,
        "samples": int(len(embedding_array)),
        "students": int(len(np.unique(label_array))),
        "failed_count": len(failed),
        "failed_images": failed[:50],
        "batch_size": batch_size
    }



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


def evaluate_validation(files, validation_ratio=0.20):
    """
    Proper 80/20 hold-out validation for LBPH, ArcFace and Hybrid.

    - A deterministic 80/20 split is created separately for every student.
    - LBPH is trained temporarily using ONLY the 80% training images.
    - ArcFace is a pretrained/fixed model, so its saved embeddings are used,
      but validation images are compared ONLY against embeddings belonging to
      the 80% training split. Validation identities are never used as
      reference embeddings.
    - Hybrid combines the hold-out ArcFace and LBPH predictions.

    The production models are still trained on ALL images after validation.
    """
    try:
        train_files, val_files = _validation_split(
            files,
            validation_ratio=validation_ratio
        )

        if not train_files or not val_files:
            return {
                "available": False,
                "reason": "Could not create an 80/20 hold-out split."
            }

        train_names = {str(item[2]) for item in train_files}
        val_names = {str(item[2]) for item in val_files}

        # ------------------------------------------------------------------
        # LBPH: train a temporary model using ONLY the 80% training split.
        # ------------------------------------------------------------------
        train_faces = []
        train_labels = []

        for path, student_id, _ in train_files:
            image = load_gray_face(path)
            if image is None:
                continue
            train_faces.append(image)
            train_labels.append(int(student_id))

        if not train_faces or len(set(train_labels)) < 2:
            return {
                "available": False,
                "reason": "Not enough valid training images for hold-out LBPH."
            }

        lb_model = cv2.face.LBPHFaceRecognizer_create(
            radius=1,
            neighbors=8,
            grid_x=8,
            grid_y=8
        )
        lb_model.train(
            train_faces,
            np.asarray(train_labels, dtype=np.int32)
        )

        # ------------------------------------------------------------------
        # ArcFace: use the saved production embeddings, but only training
        # embeddings are allowed to be reference examples.
        # ------------------------------------------------------------------
        db = np.load(ARC_DB, allow_pickle=False)
        arc_embeddings = np.asarray(
            db["embeddings"], dtype=np.float32
        )
        arc_labels = np.asarray(
            db["labels"], dtype=np.int32
        )
        if "filenames" not in db.files:
            db.close()
            return {
                "available": False,
                "reason": "ArcFace embedding database has no filenames."
            }
        db_filenames = np.asarray(
            db["filenames"], dtype=str
        )
        db.close()

        norms = np.linalg.norm(
            arc_embeddings,
            axis=1,
            keepdims=True
        )
        arc_embeddings = arc_embeddings / np.maximum(
            norms,
            1e-8
        )

        train_indices = [
            i for i, name in enumerate(db_filenames.tolist())
            if str(name) in train_names
        ]

        val_indices = {
            str(name): i
            for i, name in enumerate(db_filenames.tolist())
            if str(name) in val_names
        }

        if not train_indices:
            return {
                "available": False,
                "reason": "No ArcFace training embeddings matched the 80% split."
            }

        ref_embeddings = arc_embeddings[train_indices]
        ref_labels = arc_labels[train_indices]

        # ------------------------------------------------------------------
        # Test every held-out image.
        # ------------------------------------------------------------------
        arc_correct = 0
        arc_total = 0
        lb_correct = 0
        lb_total = 0
        hybrid_correct = 0
        hybrid_total = 0
        hybrid_accepted = 0
        hybrid_accepted_correct = 0
        consensus = 0
        skipped = 0

        for path, true_id, filename in val_files:
            true_id = int(true_id)

            try:
                # ArcFace hold-out prediction.
                val_idx = val_indices.get(str(filename))
                if val_idx is None:
                    skipped += 1
                    continue

                val_embedding = arc_embeddings[val_idx]
                sims = np.dot(ref_embeddings, val_embedding)
                best = int(np.argmax(sims))
                arc_id = int(ref_labels[best])
                best_similarity = float(sims[best])
                arc_conf = _arcface_display_score(best_similarity)

                # ArcFace's recognition threshold is kept at the current
                # production default (0.55 similarity).
                arc_pass = best_similarity >= float(
                    os.environ.get("ARCFACE_THRESHOLD", "0.55")
                )
                arc_prediction = arc_id if arc_pass else -1

                arc_total += 1
                arc_correct += int(arc_prediction == true_id)

                # LBPH hold-out prediction.
                gray = load_gray_face(path)
                if gray is None:
                    skipped += 1
                    continue

                lb_id_raw, distance = lb_model.predict(gray)
                lb_conf = _lbph_score(distance)
                lb_pass = lb_conf >= float(
                    os.environ.get("LBPH_THRESHOLD", "60.0")
                )
                lb_prediction = int(lb_id_raw) if lb_pass else -1

                lb_total += 1
                lb_correct += int(lb_prediction == true_id)

                # Hybrid decision mirrors the current ArcFace-primary +
                # LBPH-secondary logic, with the requested 80% acceptance
                # threshold used for the final hybrid result.
                if arc_prediction <= 0:
                    hybrid_id = -1
                    hybrid_score = arc_conf
                    hybrid_valid = False
                elif lb_prediction > 0 and lb_prediction == arc_prediction:
                    hybrid_id = arc_prediction
                    consensus += 1
                    hybrid_score = (
                        arc_conf * 0.75
                        + lb_conf * 0.25
                    )
                    hybrid_valid = hybrid_score >= 80.0
                else:
                    hybrid_id = arc_prediction
                    hybrid_score = (
                        arc_conf * 0.85
                        + lb_conf * 0.15
                    )
                    strong_arc = best_similarity >= max(
                        float(os.environ.get("ARCFACE_THRESHOLD", "0.55")) + 0.08,
                        0.63
                    )
                    hybrid_valid = bool(
                        strong_arc
                        and hybrid_score >= 80.0
                    )

                hybrid_total += 1
                final_hybrid_id = hybrid_id if hybrid_valid else -1
                hybrid_correct += int(final_hybrid_id == true_id)

                if hybrid_valid:
                    hybrid_accepted += 1
                    hybrid_accepted_correct += int(
                        hybrid_id == true_id
                    )

            except Exception as e:
                skipped += 1
                print(
                    f"[train] Hold-out validation skipped {filename}: {e}",
                    flush=True
                )

        if not arc_total or not lb_total or not hybrid_total:
            return {
                "available": False,
                "reason": "No valid hold-out predictions were produced."
            }

        total_files = len(files)
        training_images = len(train_files)
        validation_images = len(val_files)

        result = {
            "available": True,
            "validation_type": "proper_80_20_holdout",
            "split_ratio": "80/20",
            "training_images": training_images,
            "validation_images": validation_images,
            "total_images": total_files,
            "students": len(set(item[1] for item in files)),
            "lbph_validation_images": lb_total,
            "arcface_validation_images": arc_total,
            "hybrid_validation_images": hybrid_total,
            "skipped_validation_images": skipped,
            "lbph_accuracy": round(
                lb_correct / max(1, lb_total) * 100.0,
                1
            ),
            "arcface_accuracy": round(
                arc_correct / max(1, arc_total) * 100.0,
                1
            ),
            "hybrid_accuracy": round(
                hybrid_correct / max(1, hybrid_total) * 100.0,
                1
            ),
            "hybrid_acceptance_rate": round(
                hybrid_accepted / max(1, hybrid_total) * 100.0,
                1
            ),
            "hybrid_accepted_correct_rate": round(
                hybrid_accepted_correct / max(1, hybrid_accepted) * 100.0,
                1
            ) if hybrid_accepted else 0.0,
            "hybrid_consensus_rate": round(
                consensus / max(1, hybrid_total) * 100.0,
                1
            ),
            "hybrid_threshold": 80.0,
            "note": (
                "Accuracy is calculated only on unseen 20% hold-out images. "
                "Production models are trained on the full dataset afterward."
            )
        }

        return result

    except Exception as e:
        traceback.print_exc()
        return {
            "available": False,
            "reason": str(e)
        }


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
    print(
        "================================================",
        flush=True
    )

    print(
        "[train] CICS 3-Algorithm Face Training",
        flush=True
    )

    print(
        "Algorithms: LBPH + ArcFace + Hybrid",
        flush=True
    )

    print(
        "================================================",
        flush=True
    )

    update_status(
        "starting",
        "Initializing LBPH + ArcFace training...",
        0
    )

    try:
        files, dropped = (
            build_training_dataset()
        )

        counts = Counter(
            student_id
            for _, student_id, _ in files
        )

        print(
            f"[train] Valid images: {len(files)}",
            flush=True
        )

        print(
            f"[train] Students: {len(counts)}",
            flush=True
        )

        print(
            f"[train] Samples per student: "
            f"{dict(sorted(counts.items()))}",
            flush=True
        )

        update_status(
            "running",
            (
                f"Dataset ready: {len(files)} "
                f"images / {len(counts)} students."
            ),
            10,
            {
                "samples": len(files),
                "students": len(counts),
                "samples_per_student": dict(
                    sorted(counts.items())
                ),
                "dropped_students": dropped
            }
        )

        # ---------------------------------------------------------
        # LBPH
        # ---------------------------------------------------------

        update_status(
            "running",
            "Training LBPH...",
            20
        )

        lbph_result = train_lbph(
            files
        )

        # Explicitly mark LBPH as finished before any ArcFace work begins.
        update_status(
            "running",
            "LBPH training completed. Model saved. Measuring LBPH validation accuracy...",
            33,
            {"lbph": lbph_result}
        )

        # Hold-out validation is reported separately from training accuracy.
        # This gives a more meaningful estimate of recognition performance.
        update_status(
            "running",
            "LBPH completed. Preparing validation accuracy...",
            34,
            {
                "lbph": lbph_result
            }
        )

        # The final accuracy report is calculated after ArcFace embeddings
        # are ready, using a proper deterministic 80/20 hold-out split.

        update_status(
            "running",
            "LBPH completed successfully. Loading ArcFace...",
            36,
            {
                "lbph": lbph_result,
                "accuracy": {"lbph": lbph_validation} if lbph_validation else {}
            }
        )

        # ---------------------------------------------------------
        # ArcFace
        # ---------------------------------------------------------

        arc_model = get_arcface()

        arc_result = train_arcface(
            files,
            arc_model
        )

        # Free the ArcFace ONNX model before validation. Render has only 512 MB
        # and the training process must not keep a second large model alive.
        try:
            del arc_model
        except Exception:
            pass
        gc.collect()

        # Validate using the embeddings already produced above. This avoids
        # running ArcFace inference a second time and is much faster/lighter.
        update_status(
            "running",
            "ArcFace embeddings completed. Running proper 80/20 hold-out validation...",
            88,
            {
                "lbph": lbph_result,
                "arcface": arc_result
            }
        )

        try:
            validation = evaluate_validation(files, validation_ratio=0.20)
        except Exception as validation_error:
            print(
                f"[train] Full validation warning: {validation_error}",
                flush=True
            )
            validation = {
                "available": False,
                "reason": str(validation_error)
            }

        if validation.get("available"):
            update_status(
                "running",
                (
                    f"Validation accuracy — "
                    f"LBPH: {validation['lbph_accuracy']:.1f}% | "
                    f"ArcFace: {validation['arcface_accuracy']:.1f}% | "
                    f"Hybrid: {validation['hybrid_accuracy']:.1f}%"
                ),
                91,
                {
                    "lbph": lbph_result,
                    "arcface": arc_result,
                    "accuracy": validation
                }
            )
        else:
            update_status(
                "running",
                "Validation accuracy unavailable: " + validation.get("reason", "unknown reason"),
                91,
                {
                    "lbph": lbph_result,
                    "arcface": arc_result,
                    "accuracy": validation
                }
            )

        # ---------------------------------------------------------
        # Hybrid
        # ---------------------------------------------------------

        update_status(
            "running",
            "Preparing Hybrid ArcFace + LBPH...",
            92,
            {
                "lbph": lbph_result,
                "arcface": arc_result
            }
        )

        hybrid_result = {
            "ok": (
                os.path.exists(
                    TRAINER
                )
                and os.path.exists(
                    ARC_DB
                )
            ),
            "type": "decision_fusion",
            "description": (
                "ArcFace primary identity + "
                "LBPH secondary verification"
            )
        }

        result = {
            "lbph": lbph_result,
            "arcface": arc_result,
            "hybrid": hybrid_result,
            "accuracy": validation,
            "samples": len(files),
            "students": len(counts),
            "samples_per_student": dict(
                sorted(counts.items())
            ),
            "dropped_students": dropped
        }

        if not hybrid_result["ok"]:
            raise RuntimeError(
                "One or more required model files "
                "were not created."
            )

        if validation.get("available"):
            final_message = (
                "Training completed. "
                f"Validation accuracy — "
                f"LBPH {validation['lbph_accuracy']:.1f}% | "
                f"ArcFace {validation['arcface_accuracy']:.1f}% | "
                f"Hybrid {validation['hybrid_accuracy']:.1f}% at 80% threshold."
            )
        else:
            final_message = "Training completed. Validation accuracy was unavailable."

        update_status(
            "done",
            final_message,
            100,
            result
        )

        print(
            "================================================",
            flush=True
        )

        print(
            "[train] TRAINING COMPLETED SUCCESSFULLY",
            flush=True
        )

        print(
            f"[train] LBPH: {lbph_result}",
            flush=True
        )

        print(
            f"[train] ArcFace: {arc_result}",
            flush=True
        )

        print(
            f"[train] Hybrid: {hybrid_result}",
            flush=True
        )

        print(
            "================================================",
            flush=True
        )

        return 0

    except Exception as e:
        traceback.print_exc()

        update_status(
            "error",
            f"Training failed: {e}",
            0,
            {
                "error": str(e)
            }
        )

        print(
            f"[train] TRAINING FAILED: {e}",
            flush=True
        )

        return 1


if __name__ == "__main__":
    raise SystemExit(
        main()
    )
