import zipfile
import urllib.request
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

    temp = ARC_DB + ".tmp"
    np.savez_compressed(
        temp,
        embeddings=embedding_array,
        labels=label_array
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

        update_status(
            "running",
            "LBPH completed. Loading ArcFace...",
            35,
            {
                "lbph": lbph_result
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

        update_status(
            "done",
            (
                "LBPH, ArcFace and Hybrid "
                "training completed."
            ),
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
