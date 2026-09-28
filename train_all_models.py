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

# Keep CPU/RAM usage predictable on Render Free (512 MB instances).
os.environ.setdefault("OMP_NUM_THREADS", "1")
os.environ.setdefault("OPENBLAS_NUM_THREADS", "1")
os.environ.setdefault("MKL_NUM_THREADS", "1")
os.environ.setdefault("NUMEXPR_NUM_THREADS", "1")
os.environ.setdefault("ORT_INTRA_OP_NUM_THREADS", "1")
os.environ.setdefault("ORT_INTER_OP_NUM_THREADS", "1")
os.environ.setdefault("MPLCONFIGDIR", "/tmp/matplotlib")
os.makedirs(os.environ["MPLCONFIGDIR"], exist_ok=True)

import json
import time
import traceback
import re
import gc
from collections import Counter

import cv2
import numpy as np

try:
    from insightface.app import FaceAnalysis
except Exception as e:
    FaceAnalysis = None
    INSIGHTFACE_IMPORT_ERROR = str(e)


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

def get_arcface():
    if FaceAnalysis is None:
        raise RuntimeError(
            "InsightFace is unavailable: "
            + globals().get(
                "INSIGHTFACE_IMPORT_ERROR",
                "unknown import error"
            )
        )

    print(
        "[train] Loading InsightFace buffalo_s...",
        flush=True
    )

    # buffalo_sc is the lightweight InsightFace pack. It contains the
    # SCRFD-500MF detector + ArcFace MobileFaceNet recognizer and omits
    # the extra landmark/age models. This is much more appropriate for
    # Render Free's 512-MB memory limit.
    model = FaceAnalysis(
        name="buffalo_sc",
        allowed_modules=["detection", "recognition"],
        providers=["CPUExecutionProvider"]
    )

    model.prepare(
        ctx_id=-1,
        det_size=(320, 320)
    )

    print(
        "[train] InsightFace loaded [OK]",
        flush=True
    )

    return model


def normalize_embedding(embedding):
    embedding = np.asarray(
        embedding,
        dtype=np.float32
    )

    if (
        embedding.ndim != 1
        or embedding.size == 0
    ):
        return None

    norm = np.linalg.norm(
        embedding
    )

    if norm <= 1e-8:
        return None

    return (
        embedding / norm
    ).astype(
        np.float32
    )


def train_arcface(
    files,
    model
):
    print(
        "[train] Generating ArcFace embeddings...",
        flush=True
    )

    embeddings = []
    labels = []
    failed = []

    total = len(files)

    for index, (
        path,
        student_id,
        filename
    ) in enumerate(
        files,
        start=1
    ):
        try:
            image = cv2.imread(
                path,
                cv2.IMREAD_COLOR
            )

            if image is None:
                failed.append(
                    filename
                )
                continue

            detected_faces = (
                model.get(image)
            )

            if not detected_faces:
                failed.append(
                    filename
                )
                continue

            # Use the strongest detected face.
            face = max(
                detected_faces,
                key=lambda item: float(
                    getattr(
                        item,
                        "det_score",
                        0.0
                    ) or 0.0
                )
            )

            embedding = (
                normalize_embedding(
                    face.embedding
                )
            )

            if embedding is None:
                failed.append(
                    filename
                )
                continue

            embeddings.append(
                embedding
            )

            labels.append(
                student_id
            )

            progress = (
                40
                + int(
                    (index / max(1, total))
                    * 45
                )
            )

            update_status(
                "running",
                (
                    "Generating ArcFace embeddings "
                    f"{index}/{total}..."
                ),
                progress
            )

        except Exception as e:
            print(
                f"[train] ArcFace failed for "
                f"{filename}: {e}",
                flush=True
            )

            failed.append(
                filename
            )

    if not embeddings:
        raise RuntimeError(
            "ArcFace could not generate any embeddings. "
            "Check that the enrolled images contain detectable faces."
        )

    embedding_array = np.asarray(
        embeddings,
        dtype=np.float32
    )

    label_array = np.asarray(
        labels,
        dtype=np.int32
    )

    # Save atomically.
    temp = ARC_DB + ".tmp"

    np.savez_compressed(
        temp,
        embeddings=embedding_array,
        labels=label_array
    )

    # np.savez may add .npz.
    actual_temp = (
        temp
        if os.path.exists(temp)
        else temp + ".npz"
    )

    os.replace(
        actual_temp,
        ARC_DB
    )

    print(
        f"[train] ArcFace DB saved: {ARC_DB}",
        flush=True
    )

    return {
        "ok": True,
        "samples": int(
            len(embedding_array)
        ),
        "students": int(
            len(
                np.unique(
                    label_array
                )
            )
        ),
        "failed_count": len(
            failed
        ),
        "failed_images": failed[:50]
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

        # Release the heavy ArcFace runtime before finalization.
        del arc_model
        gc.collect()

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
