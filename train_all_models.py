import zipfile
import urllib.request
import shutil
import gc
"""
train_all_models.py
===============================================================================

VERSION: TRUE_80_20_HOLDOUT_RENDER_512MB

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
    embedding_files = []
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


def evaluate_validation(files, arc_model=None):
    """
    REAL 80/20 HOLD-OUT VALIDATION.

    IMPORTANT:
    - The production models may be trained on all images later.
    - For this evaluation, each student's images are split deterministically
      into 80% reference/training images and 20% unseen test images.
    - LBPH is trained ONLY on the 80% reference images.
    - ArcFace test embeddings are compared ONLY against 80% reference
      embeddings. Test embeddings are never used as references.
    - Hybrid is evaluated on the same unseen 20% images using the temporary
      80%-only LBPH model plus ArcFace.
    - The pretrained ArcFace network itself is not "fit" on these images;
      it only converts images into embeddings.
    """
    try:
        train_files, val_files = _validation_split(files, 0.20)
    except Exception as e:
        return {
            "available": False,
            "reason": f"Could not create 80/20 split: {e}"
        }

    if not train_files or not val_files:
        return {
            "available": False,
            "reason": "80/20 split did not produce both training and validation sets."
        }

    # ------------------------------------------------------------------
    # 1. Train a TEMPORARY LBPH model using ONLY the 80% training files.
    # ------------------------------------------------------------------
    try:
        temp_lbph = cv2.face.LBPHFaceRecognizer_create(
            radius=1,
            neighbors=8,
            grid_x=8,
            grid_y=8
        )

        lb_faces = []
        lb_labels = []

        for path, student_id, _ in train_files:
            gray = load_gray_face(path)
            if gray is None:
                continue
            lb_faces.append(gray)
            lb_labels.append(int(student_id))

        if not lb_faces:
            return {
                "available": False,
                "reason": "No readable images in the 80% LBPH training split."
            }

        temp_lbph.train(
            lb_faces,
            np.asarray(lb_labels, dtype=np.int32)
        )

        del lb_faces
        del lb_labels
        gc.collect()

    except Exception as e:
        return {
            "available": False,
            "reason": f"Temporary 80% LBPH training failed: {e}"
        }

    # ------------------------------------------------------------------
    # 2. Load the ArcFace embeddings produced during the single ArcFace
    #    inference pass. Separate them by filename into 80% references and
    #    20% unseen test embeddings.
    # ------------------------------------------------------------------
    try:
        db = np.load(ARC_DB, allow_pickle=False)

        all_embeddings = np.asarray(
            db["embeddings"],
            dtype=np.float32
        )

        all_labels = np.asarray(
            db["labels"],
            dtype=np.int32
        )

        if "filenames" not in db.files:
            return {
                "available": False,
                "reason": "ArcFace DB has no filenames; cannot guarantee hold-out separation."
            }

        all_filenames = np.asarray(
            db["filenames"],
            dtype=str
        )

    except Exception as e:
        return {
            "available": False,
            "reason": f"Could not load ArcFace DB: {e}"
        }

    # Normalize defensively.
    norms = np.linalg.norm(
        all_embeddings,
        axis=1,
        keepdims=True
    )

    all_embeddings = (
        all_embeddings /
        np.maximum(norms, 1e-8)
    )

    train_names = {
        item[2]
        for item in train_files
    }

    val_names = {
        item[2]
        for item in val_files
    }

    train_mask = np.array(
        [name in train_names for name in all_filenames],
        dtype=bool
    )

    val_mask = np.array(
        [name in val_names for name in all_filenames],
        dtype=bool
    )

    # Only successfully embedded files can be evaluated by ArcFace.
    ref_embeddings = all_embeddings[train_mask]
    ref_labels = all_labels[train_mask]
    ref_names = all_filenames[train_mask]

    test_embeddings = all_embeddings[val_mask]
    test_labels = all_labels[val_mask]
    test_names = all_filenames[val_mask]

    if len(ref_embeddings) == 0:
        return {
            "available": False,
            "reason": "No 80% training/reference ArcFace embeddings were available."
        }

    if len(test_embeddings) == 0:
        return {
            "available": False,
            "reason": "No 20% validation ArcFace embeddings were available."
        }

    # ------------------------------------------------------------------
    # 3. True ArcFace hold-out accuracy:
    #    each unseen test embedding is compared ONLY to 80% references.
    # ------------------------------------------------------------------
    arc_correct = 0
    arc_total = 0

    # Keep a lookup so the hybrid loop can find the ArcFace result by filename.
    arc_predictions = {}

    for emb, true_id, filename in zip(
        test_embeddings,
        test_labels,
        test_names
    ):
        # Cosine similarity because embeddings are normalized.
        similarities = np.dot(
            ref_embeddings,
            emb
        )

        best_index = int(
            np.argmax(similarities)
        )

        best_similarity = float(
            similarities[best_index]
        )

        predicted_id = int(
            ref_labels[best_index]
        )

        arc_confidence = _arcface_display_score(
            best_similarity
        )

        # Same raw ArcFace threshold used by the server by default.
        arc_matched = bool(
            best_similarity >= 0.55
        )

        arc_correct += int(
            arc_matched and predicted_id == int(true_id)
        )

        arc_total += 1

        arc_predictions[str(filename)] = {
            "id": predicted_id,
            "true_id": int(true_id),
            "similarity": best_similarity,
            "confidence": arc_confidence,
            "matched": arc_matched
        }

    # ------------------------------------------------------------------
    # 4. True LBPH hold-out accuracy using ONLY the temporary 80%-trained
    #    recognizer.
    # ------------------------------------------------------------------
    lbph_correct = 0
    lbph_total = 0
    lbph_predictions = {}

    val_lookup = {
        item[2]: item
        for item in val_files
    }

    for filename in sorted(val_lookup):
        path, true_id, _ = val_lookup[filename]

        try:
            gray = load_gray_face(path)
            if gray is None:
                continue

            predicted_id, distance = temp_lbph.predict(
                gray
            )

            lbph_confidence = _lbph_score(
                distance
            )

            # Current attendance threshold shown by your server is 80%.
            lbph_matched = bool(
                lbph_confidence >= 80.0
            )

            lbph_correct += int(
                lbph_matched and int(predicted_id) == int(true_id)
            )

            lbph_total += 1

            lbph_predictions[filename] = {
                "id": int(predicted_id),
                "true_id": int(true_id),
                "confidence": float(lbph_confidence),
                "distance": float(distance),
                "matched": lbph_matched
            }

        except Exception:
            continue

    # ------------------------------------------------------------------
    # 5. True Hybrid hold-out accuracy.
    #
    # Match the deployed Hybrid concept:
    #   - ArcFace is primary.
    #   - LBPH is secondary.
    #   - If both agree and both pass, fuse 75% ArcFace + 25% LBPH.
    #   - If they disagree, require a stronger ArcFace signal and use the
    #     same 85/15 confidence fusion.
    #   - Hybrid acceptance threshold = 80%.
    # ------------------------------------------------------------------
    hybrid_correct = 0
    hybrid_total = 0
    hybrid_accepted = 0
    hybrid_agreement = 0

    for filename, arc in arc_predictions.items():
        lbph = lbph_predictions.get(filename)

        if lbph is None:
            continue

        arc_id = int(arc["id"])
        lbph_id = int(lbph["id"])

        arc_similarity = float(
            arc["similarity"]
        )
        arc_confidence = float(
            arc["confidence"]
        )
        lbph_confidence = float(
            lbph["confidence"]
        )

        arc_pass = bool(
            arc["matched"] and arc_id > 0
        )

        lbph_pass = bool(
            lbph["matched"] and lbph_id > 0
        )

        true_id = int(
            arc["true_id"]
        )

        if not arc_pass:
            hybrid_id = -1
            hybrid_score = arc_confidence
            hybrid_matched = False

        elif lbph_pass and lbph_id == arc_id:
            hybrid_id = arc_id

            hybrid_score = (
                arc_confidence * 0.75
                + lbph_confidence * 0.25
            )

            hybrid_matched = bool(
                hybrid_score >= 80.0
            )

            hybrid_agreement += 1

        else:
            # Same concept as the deployed server:
            # disagreement requires a stronger ArcFace similarity.
            strong_arc = bool(
                arc_similarity >= 0.63
            )

            hybrid_id = arc_id

            hybrid_score = (
                arc_confidence * 0.85
                + lbph_confidence * 0.15
            )

            hybrid_matched = bool(
                strong_arc
                and hybrid_score >= 80.0
            )

        hybrid_total += 1

        if hybrid_matched:
            hybrid_accepted += 1

        hybrid_correct += int(
            hybrid_matched
            and hybrid_id == true_id
        )

    # ------------------------------------------------------------------
    # 6. Report the REAL numbers.
    # ------------------------------------------------------------------
    result = {
        "available": True,
        "validation_type": "proper_80_20_holdout",
        "split_ratio": "80/20",
        "total_images": len(files),
        "training_images": len(train_files),
        "validation_images": len(val_files),

        "lbph_validation_images": lbph_total,
        "lbph_correct": lbph_correct,
        "lbph_incorrect": max(0, lbph_total - lbph_correct),
        "lbph_accuracy": round(
            lbph_correct / max(1, lbph_total) * 100.0,
            1
        ),

        "arcface_validation_images": arc_total,
        "arcface_correct": arc_correct,
        "arcface_incorrect": max(0, arc_total - arc_correct),
        "arcface_accuracy": round(
            arc_correct / max(1, arc_total) * 100.0,
            1
        ),

        "hybrid_validation_images": hybrid_total,
        "hybrid_correct": hybrid_correct,
        "hybrid_incorrect": max(0, hybrid_total - hybrid_correct),
        "hybrid_accuracy": round(
            hybrid_correct / max(1, hybrid_total) * 100.0,
            1
        ),

        "hybrid_acceptance_rate": round(
            hybrid_accepted / max(1, hybrid_total) * 100.0,
            1
        ),

        "hybrid_agreement_rate": round(
            hybrid_agreement / max(1, hybrid_total) * 100.0,
            1
        ),

        "arcface_reference_images": len(ref_embeddings),
        "arcface_test_embeddings": len(test_embeddings),
        "method_note": (
            "Validation images were excluded from LBPH training and "
            "from the ArcFace reference database."
        )
    }

    # Explicit cleanup for Render's 512 MB limit.
    del all_embeddings
    del all_labels
    del all_filenames
    del ref_embeddings
    del ref_labels
    del test_embeddings
    del test_labels
    del temp_lbph
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

        lbph_validation = None
        try:
            _, val_files_preview = _validation_split(files)
            if val_files_preview:
                # A temporary ArcFace model is not needed yet; calculate LBPH
                # validation directly with a temporary held-out model.
                train_val_files, val_only = _validation_split(files)
                vf, vl = [], []
                for vpath, vsid, _ in train_val_files:
                    img = load_gray_face(vpath)
                    if img is not None:
                        vf.append(img)
                        vl.append(vsid)
                temp_lb = None
                if vf and len(set(vl)) >= 2:
                    temp_lb = cv2.face.LBPHFaceRecognizer_create(
                        radius=1, neighbors=8, grid_x=8, grid_y=8
                    )
                    temp_lb.train(vf, np.asarray(vl, dtype=np.int32))

                correct = total = 0
                for vpath, vsid, _ in val_only:
                    img = load_gray_face(vpath)
                    if img is None or temp_lb is None:
                        continue
                    pred, _ = temp_lb.predict(img)
                    total += 1
                    correct += int(int(pred) == int(vsid))

                if total:
                    lbph_validation = {
                        "accuracy": round(correct / total * 100.0, 1),
                        "validation_images": total
                    }
                    lbph_result["validation_accuracy"] = lbph_validation["accuracy"]
                    update_status(
                        "running",
                        f"LBPH validation accuracy: {lbph_validation['accuracy']:.1f}%",
                        35,
                        {
                            "lbph": lbph_result,
                            "accuracy": {"lbph": lbph_validation}
                        }
                    )
        except Exception as validation_error:
            print(
                f"[train] LBPH validation warning: {validation_error}",
                flush=True
            )

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
            "ArcFace embeddings completed. Running PROPER 80/20 HOLD-OUT validation...",
            88,
            {
                "lbph": lbph_result,
                "arcface": arc_result
            }
        )

        try:
            validation = evaluate_validation(files, None)
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
            final_message = "Training completed. Proper 80/20 hold-out validation was unavailable."

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
