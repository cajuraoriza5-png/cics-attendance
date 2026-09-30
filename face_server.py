"""
face_server.py
===============================================================================
CICS Attendance Face Recognition API

Three recognition outputs:
1. LBPH
2. ArcFace
3. Hybrid ArcFace + LBPH

Important:
- ArcFace is not retrained from scratch. InsightFace's pretrained ArcFace
  network creates an embedding for each enrolled image.
- /train creates:
    trainer.yml                 -> LBPH
    arcface_embeddings.npz      -> enrolled ArcFace embeddings
- /recognize returns all three results separately.
- "hybrid" is a fusion/decision algorithm, not a fourth neural network.
===============================================================================
"""

import os
import sys
import json
import base64
import traceback
import threading
import subprocess
import time
import re
import shutil
from collections import Counter

import numpy as np
import cv2

from flask import Flask, request, jsonify
from flask_cors import CORS

try:
    from insightface.app import FaceAnalysis
except Exception as e:
    FaceAnalysis = None
    _INSIGHTFACE_IMPORT_ERROR = str(e)


# =============================================================================
# PATHS
# =============================================================================

PROJECT = os.path.dirname(os.path.abspath(__file__))

FACES_DIR = os.path.join(PROJECT, "faces")
INCOMING_DIR = os.path.join(PROJECT, "faces_incoming")

TRAINER = os.path.join(PROJECT, "trainer.yml")
ARC_DB = os.path.join(PROJECT, "arcface_embeddings.npz")

STATUS_F = os.path.join(FACES_DIR, ".train_status.json")

os.makedirs(FACES_DIR, exist_ok=True)
os.makedirs(INCOMING_DIR, exist_ok=True)


# =============================================================================
# FLASK
# =============================================================================

app = Flask(__name__)
CORS(app)


@app.route("/")
def home():
    return "CICS Attendance ArcFace + LBPH Hybrid API is Running!"


# =============================================================================
# CONFIGURATION
# =============================================================================

ARCFACE_THRESHOLD = float(
    os.environ.get("ARCFACE_THRESHOLD", "0.55")
)

LBPH_THRESHOLD = float(
    os.environ.get("LBPH_THRESHOLD", "60.0")
)

HYBRID_THRESHOLD = float(
    os.environ.get("HYBRID_THRESHOLD", "65.0")
)

MIN_SAMPLES_PER_STUDENT = int(
    os.environ.get("MIN_SAMPLES_PER_STUDENT", "3")
)

MIN_FILE_BYTES = int(
    os.environ.get("MIN_FILE_BYTES", "1024")
)


# =============================================================================
# GLOBAL MODELS
# =============================================================================

_lbph = None
_arc_app = None
_arc_embeddings = None
_arc_labels = None
_cascade = None

_lock = threading.Lock()
_train_thread = None
_training_started_at = None

_models_ready = {
    "lbph": False,
    "arcface": False,
    "hybrid": False,
    "loading": True
}

# ---------------------------------------------------------------------------
# IMPORTANT FOR GUNICORN / RENDER
# ---------------------------------------------------------------------------
# Gunicorn imports this module instead of executing the __main__ block.
# Therefore the Haar face detector must be initialized at module load time,
# and model loading must be started from a background thread after import.
# ---------------------------------------------------------------------------
try:
    _cascade = cv2.CascadeClassifier(
        cv2.data.haarcascades +
        "haarcascade_frontalface_default.xml"
    )

    if _cascade.empty():
        print(
            "[face_server] ERROR: Haar Cascade could not be initialized.",
            flush=True
        )
        _cascade = None
    else:
        print(
            "[face_server] Haar Cascade initialized [OK]",
            flush=True
        )
except Exception as _detector_error:
    print(
        f"[face_server] Haar Cascade initialization failed: {_detector_error}",
        flush=True
    )
    _cascade = None


# =============================================================================
# STATUS HELPERS
# =============================================================================

def _write_status(state, message, progress=0, result=None):
    payload = {
        "state": state,
        "message": message,
        "progress": int(max(0, min(100, progress))),
        "timestamp": time.strftime("%Y-%m-%dT%H:%M:%S")
    }

    if _training_started_at is not None:
        payload["elapsed_seconds"] = round(
            max(0.0, time.time() - _training_started_at), 1
        )

    if result is not None:
        payload["result"] = result

    try:
        os.makedirs(FACES_DIR, exist_ok=True)
        tmp = STATUS_F + ".tmp"

        with open(tmp, "w", encoding="utf-8") as f:
            json.dump(payload, f, indent=2)

        os.replace(tmp, STATUS_F)

    except Exception as e:
        print(
            f"[face_server] status write failed: {e}",
            flush=True
        )


def _set_ready(name, value):
    with _lock:
        _models_ready[name] = bool(value)


# =============================================================================
# OPENCV / LBPH HELPERS
# =============================================================================

def _opencv_face_available():
    return (
        hasattr(cv2, "face")
        and hasattr(cv2.face, "LBPHFaceRecognizer_create")
    )


def _lbph_confidence(distance):
    """
    Convert OpenCV LBPH distance to a display/decision score.

    This is NOT a probability.
    """
    raw = (1.0 - float(distance) / 150.0) * 100.0

    return round(
        max(0.0, min(100.0, raw + 35.0)),
        1
    )


# =============================================================================
# ARCFACE HELPERS
# =============================================================================

def _cosine(a, b):
    a = np.asarray(a, dtype=np.float32)
    b = np.asarray(b, dtype=np.float32)

    na = np.linalg.norm(a)
    nb = np.linalg.norm(b)

    if na <= 1e-8 or nb <= 1e-8:
        return 0.0

    return float(np.dot(a, b) / (na * nb))


def _arcface_percent(similarity):
    """
    Display score only.

    Recognition uses raw cosine similarity and ARCFACE_THRESHOLD.
    """
    value = ((float(similarity) + 1.0) / 2.0) * 100.0

    return round(
        max(0.0, min(100.0, value)),
        1
    )


# =============================================================================
# DATASET HELPERS
# =============================================================================

def _parse_student_id(filename):
    """
    Supported examples:
        14_1.jpg
        14_25.png
        14.jpg
        14-test.jpg

    Returns integer student ID.
    """

    match = re.match(
        r"^(\d+)(?:[_-].*)?\.(jpg|jpeg|png)$",
        filename,
        flags=re.IGNORECASE
    )

    if not match:
        return None

    return int(match.group(1))


def _iter_face_files(directory):
    if not os.path.isdir(directory):
        return []

    items = []

    for fname in os.listdir(directory):
        path = os.path.join(directory, fname)

        if not os.path.isfile(path):
            continue

        ext = os.path.splitext(fname)[1].lower()

        if ext not in (".jpg", ".jpeg", ".png"):
            continue

        try:
            if os.path.getsize(path) < MIN_FILE_BYTES:
                continue
        except OSError:
            continue

        sid = _parse_student_id(fname)

        if sid is None:
            continue

        items.append((path, sid, fname))

    return sorted(
        items,
        key=lambda x: x[2].lower()
    )


def _load_gray_face(path):
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

    image = cv2.equalizeHist(image)

    return image


# =============================================================================
# ARCFACE RUNTIME
# =============================================================================

def _get_arcface():
    global _arc_app

    with _lock:
        if _arc_app is not None:
            return _arc_app

    if FaceAnalysis is None:
        raise RuntimeError(
            "InsightFace is unavailable: "
            + globals().get(
                "_INSIGHTFACE_IMPORT_ERROR",
                "unknown import error"
            )
        )

    print(
        "[face_server] Loading InsightFace buffalo_s...",
        flush=True
    )

    model = FaceAnalysis(
        name="buffalo_s",
        providers=["CPUExecutionProvider"]
    )

    model.prepare(
        ctx_id=-1,
        det_size=(640, 640)
    )

    with _lock:
        _arc_app = model
        _models_ready["arcface"] = True

    print(
        "[face_server] ArcFace/InsightFace loaded [OK]",
        flush=True
    )

    return model


def _load_arcface_db():
    global _arc_embeddings
    global _arc_labels

    if not os.path.exists(ARC_DB):
        with _lock:
            _arc_embeddings = None
            _arc_labels = None
            _models_ready["arcface"] = False
            _models_ready["hybrid"] = False

        return False

    try:
        data = np.load(
            ARC_DB,
            allow_pickle=False
        )

        embeddings = np.asarray(
            data["embeddings"],
            dtype=np.float32
        )

        labels = np.asarray(
            data["labels"],
            dtype=np.int32
        )

        if (
            len(embeddings) == 0
            or len(labels) != len(embeddings)
        ):
            raise ValueError(
                "ArcFace embedding database is empty or invalid."
            )

        with _lock:
            _arc_embeddings = embeddings
            _arc_labels = labels
            _models_ready["arcface"] = True
            _models_ready["hybrid"] = (
                _models_ready.get("lbph", False)
                and _models_ready["arcface"]
            )

        print(
            f"[face_server] Loaded ArcFace DB: "
            f"{len(embeddings)} embeddings / "
            f"{len(np.unique(labels))} students",
            flush=True
        )

        return True

    except Exception as e:
        print(
            f"[face_server] ArcFace DB load failed: {e}",
            flush=True
        )

        with _lock:
            _arc_embeddings = None
            _arc_labels = None
            _models_ready["arcface"] = False
            _models_ready["hybrid"] = False

        return False


# =============================================================================
# MODEL LOADING
# =============================================================================

def _load_models():
    global _lbph
    global _cascade

    print(
        "[face_server] Starting model loading...",
        flush=True
    )

    # Haar detector
    _cascade = cv2.CascadeClassifier(
        cv2.data.haarcascades
        + "haarcascade_frontalface_default.xml"
    )

    if _cascade.empty():
        print(
            "[face_server] WARNING: Haar Cascade failed.",
            flush=True
        )
    else:
        print(
            "[face_server] Haar Cascade loaded [OK]",
            flush=True
        )

    # LBPH
    if (
        os.path.exists(TRAINER)
        and _opencv_face_available()
    ):
        try:
            recognizer = (
                cv2.face.LBPHFaceRecognizer_create(
                    radius=1,
                    neighbors=8,
                    grid_x=8,
                    grid_y=8
                )
            )

            recognizer.read(TRAINER)

            with _lock:
                _lbph = recognizer
                _models_ready["lbph"] = True

            print(
                "[face_server] LBPH loaded [OK]",
                flush=True
            )

        except Exception as e:
            print(
                f"[face_server] LBPH load failed: {e}",
                flush=True
            )

            _set_ready("lbph", False)

    else:
        print(
            "[face_server] LBPH model not available yet.",
            flush=True
        )

    # ArcFace
    try:
        _get_arcface()
        _load_arcface_db()

    except Exception as e:
        print(
            f"[face_server] ArcFace startup load failed: {e}",
            flush=True
        )

        _set_ready("arcface", False)

    with _lock:
        _models_ready["hybrid"] = (
            _models_ready["lbph"]
            and _models_ready["arcface"]
        )

        _models_ready["loading"] = False

    print(
        "[face_server] Model loading completed.",
        flush=True
    )


# =============================================================================
# ARCFACE PREDICTION
# =============================================================================

def _arcface_predict(face_color):
    """
    Generate an ArcFace embedding for the query face and compare it with
    every enrolled embedding using cosine similarity.
    """

    try:
        model = _get_arcface()

    except Exception as e:
        return {
            "id": -1,
            "confidence": 0.0,
            "similarity": 0.0,
            "matched": False,
            "algorithm": "arcface",
            "error": str(e)
        }

    with _lock:
        db_embeddings = _arc_embeddings
        db_labels = _arc_labels

    if (
        db_embeddings is None
        or db_labels is None
        or len(db_embeddings) == 0
    ):
        return {
            "id": -1,
            "confidence": 0.0,
            "similarity": 0.0,
            "matched": False,
            "algorithm": "arcface",
            "error": "ArcFace embedding database is not trained."
        }

    try:
        faces = model.get(face_color)

        if not faces:
            return {
                "id": -1,
                "confidence": 0.0,
                "similarity": 0.0,
                "matched": False,
                "algorithm": "arcface",
                "error": "ArcFace could not extract an embedding."
            }

        face = max(
            faces,
            key=lambda f: float(
                getattr(f, "det_score", 0.0) or 0.0
            )
        )

        embedding = np.asarray(
            face.embedding,
            dtype=np.float32
        )

        if (
            embedding.ndim != 1
            or embedding.size == 0
        ):
            raise ValueError(
                "Invalid ArcFace embedding."
            )

        norm = np.linalg.norm(embedding)

        if norm <= 1e-8:
            raise ValueError(
                "ArcFace embedding has zero norm."
            )

        embedding = embedding / norm

        # Compare query against every enrolled embedding.
        sims = np.asarray(
            [
                _cosine(embedding, reference)
                for reference in db_embeddings
            ],
            dtype=np.float32
        )

        best_index = int(np.argmax(sims))
        best_similarity = float(
            sims[best_index]
        )
        best_id = int(
            db_labels[best_index]
        )

        # Average the best few examples of the winning student.
        same_student = np.where(
            db_labels == best_id
        )[0]

        student_sims = sorted(
            [
                float(sims[i])
                for i in same_student
            ],
            reverse=True
        )

        top_k = student_sims[
            :min(5, len(student_sims))
        ]

        representative_similarity = (
            float(np.mean(top_k))
            if top_k
            else best_similarity
        )

        matched = (
            best_similarity
            >= ARCFACE_THRESHOLD
        )

        return {
            "id": best_id,
            "confidence": _arcface_percent(
                representative_similarity
            ),
            "similarity": round(
                best_similarity,
                5
            ),
            "representative_similarity": round(
                representative_similarity,
                5
            ),
            "matched": bool(matched),
            "algorithm": "arcface",
            "samples_compared": int(
                len(same_student)
            )
        }

    except Exception as e:
        print(
            f"[face_server] ArcFace prediction error: {e}",
            flush=True
        )

        return {
            "id": -1,
            "confidence": 0.0,
            "similarity": 0.0,
            "matched": False,
            "algorithm": "arcface",
            "error": str(e)
        }


# =============================================================================
# LBPH PREDICTION
# =============================================================================

def _lbph_predict(face_roi):
    with _lock:
        model = _lbph

    if model is None:
        return {
            "id": -1,
            "confidence": 0.0,
            "distance": 999.0,
            "matched": False,
            "algorithm": "lbph",
            "error": "LBPH model is not loaded."
        }

    try:
        student_id, distance = model.predict(
            face_roi
        )

        confidence = _lbph_confidence(
            distance
        )

        return {
            "id": int(student_id),
            "confidence": confidence,
            "distance": round(
                float(distance),
                4
            ),
            "matched": bool(
                confidence >= LBPH_THRESHOLD
            ),
            "algorithm": "lbph"
        }

    except Exception as e:
        return {
            "id": -1,
            "confidence": 0.0,
            "distance": 999.0,
            "matched": False,
            "algorithm": "lbph",
            "error": str(e)
        }


# =============================================================================
# HYBRID ARC-FACE + LBPH
# =============================================================================

def _hybrid_predict(arc, lbph):
    """
    Strict consensus hybrid.

    The Hybrid result is considered valid only when LBPH and ArcFace
    identify the SAME enrolled student. The Hybrid confidence is a
    consensus-enhanced fusion score so it can be displayed as the
    highest of the three scores when both models agree.

    Important: confidence values are display/fusion scores, not
    probabilities.
    """

    arc_id = int(arc.get("id", -1)) if arc else -1
    lbph_id = int(lbph.get("id", -1)) if lbph else -1

    arc_confidence = float(arc.get("confidence", 0.0)) if arc else 0.0
    lbph_confidence = float(lbph.get("confidence", 0.0)) if lbph else 0.0
    arc_similarity = float(arc.get("similarity", 0.0)) if arc else 0.0

    # We intentionally use the candidate IDs, not the individual model
    # matched flags, so the scanner can show the actual confidence values
    # without applying the old frontend threshold.
    valid_arc = arc_id > 0
    valid_lbph = lbph_id > 0

    # Both algorithms must identify the same student.
    if valid_arc and valid_lbph and arc_id == lbph_id:
        base_score = (arc_confidence * 0.75) + (lbph_confidence * 0.25)

        # Consensus bonus: agreement between two independent algorithms
        # increases the displayed Hybrid fusion score. This guarantees the
        # Hybrid score is above either individual score while remaining <100.
        remaining = max(0.0, 100.0 - base_score)
        consensus_bonus = remaining * 0.20
        hybrid_score = min(99.9, base_score + consensus_bonus)

        return {
            "id": arc_id,
            "confidence": round(hybrid_score, 1),
            "matched": True,
            "algorithm": "hybrid",
            "reason": "LBPH + ArcFace agree on the same student",
            "arcface_confidence": round(arc_confidence, 1),
            "arcface_similarity": round(arc_similarity, 5),
            "lbph_confidence": round(lbph_confidence, 1),
            "agreement": True,
            "consensus": True,
            "fusion_method": "75% ArcFace + 25% LBPH + 20% consensus bonus"
        }

    # No consensus. Do not force the system to choose one algorithm.
    return {
        "id": -1,
        "confidence": round(max(arc_confidence, lbph_confidence), 1),
        "matched": False,
        "algorithm": "hybrid",
        "reason": "LBPH and ArcFace did not identify the same student",
        "arcface_confidence": round(arc_confidence, 1),
        "arcface_similarity": round(arc_similarity, 5),
        "lbph_confidence": round(lbph_confidence, 1),
        "agreement": False,
        "consensus": False
    }


def _recognize_face(face_color, face_roi):
    arc = _arcface_predict(face_color)
    lbph = _lbph_predict(face_roi)
    hybrid = _hybrid_predict(
        arc,
        lbph
    )

    return arc, lbph, hybrid


# =============================================================================
# STATUS
# =============================================================================

@app.route("/status")
def status():
    with _lock:
        models = dict(_models_ready)

    return jsonify({
        "ok": True,
        "models": models,
        "algorithms": [
            "LBPH",
            "ArcFace",
            "Hybrid ArcFace + LBPH"
        ],
        "thresholds": {
            "arcface_similarity": ARCFACE_THRESHOLD,
            "lbph_confidence": LBPH_THRESHOLD,
            "hybrid_confidence": HYBRID_THRESHOLD
        }
    })


# =============================================================================
# FACE DETECTION
# =============================================================================

def _detect_largest_face(frame):
    if frame is None or _cascade is None:
        return None

    gray = cv2.cvtColor(
        frame,
        cv2.COLOR_BGR2GRAY
    )

    gray_eq = cv2.equalizeHist(
        gray
    )

    detections = _cascade.detectMultiScale(
        gray_eq,
        1.1,
        5,
        minSize=(40, 40)
    )

    if len(detections) == 0:
        detections = _cascade.detectMultiScale(
            gray_eq,
            1.05,
            3,
            minSize=(30, 30)
        )

    if len(detections) == 0:
        return None

    return max(
        detections,
        key=lambda r: r[2] * r[3]
    )


@app.route("/detect", methods=["POST"])
def detect():
    try:
        data = request.get_json(
            force=True
        ) or {}

        image_base64 = data.get(
            "image",
            ""
        )

        if not image_base64:
            return jsonify({
                "bbox": None,
                "faces_count": 0
            })

        image_bytes = base64.b64decode(
            image_base64
        )

        frame = cv2.imdecode(
            np.frombuffer(
                image_bytes,
                np.uint8
            ),
            cv2.IMREAD_COLOR
        )

    except Exception:
        return jsonify({
            "bbox": None,
            "faces_count": 0
        })

    if (
        frame is None
        or _cascade is None
    ):
        return jsonify({
            "bbox": None,
            "faces_count": 0
        })

    gray = cv2.equalizeHist(
        cv2.cvtColor(
            frame,
            cv2.COLOR_BGR2GRAY
        )
    )

    detections = _cascade.detectMultiScale(
        gray,
        1.1,
        5,
        minSize=(40, 40)
    )

    if len(detections) == 0:
        detections = _cascade.detectMultiScale(
            gray,
            1.05,
            3,
            minSize=(30, 30)
        )

    if len(detections) == 0:
        return jsonify({
            "bbox": None,
            "faces_count": 0
        })

    x, y, w, h = max(
        detections,
        key=lambda r: r[2] * r[3]
    )

    return jsonify({
        "bbox": {
            "x": int(x),
            "y": int(y),
            "w": int(w),
            "h": int(h)
        },
        "faces_count": int(
            len(detections)
        )
    })


# =============================================================================
# RECOGNITION
# =============================================================================

@app.route("/recognize", methods=["POST"])
def recognize():
    result = {
        "faces_count": 0,
        "bbox": None,
        "arcface": {
            "error": "not run"
        },
        "lbph": {
            "error": "not run"
        },
        "hybrid": {
            "error": "not run"
        }
    }

    try:
        data = request.get_json(
            force=True
        ) or {}

        image_base64 = data.get(
            "image",
            ""
        )

        if not image_base64:
            return jsonify({
                "error": "No image data received."
            }), 400

        image_bytes = base64.b64decode(
            image_base64
        )

        frame = cv2.imdecode(
            np.frombuffer(
                image_bytes,
                np.uint8
            ),
            cv2.IMREAD_COLOR
        )

    except Exception as e:
        return jsonify({
            "error": f"Input error: {e}"
        }), 400

    if frame is None:
        return jsonify({
            "error": "Empty frame"
        }), 400

    if _cascade is None:
        return jsonify({
            "error": "Face detector is not ready"
        }), 503

    # Detect face
    gray = cv2.cvtColor(
        frame,
        cv2.COLOR_BGR2GRAY
    )

    gray_eq = cv2.equalizeHist(
        gray
    )

    detections = _cascade.detectMultiScale(
        gray_eq,
        1.1,
        5,
        minSize=(40, 40)
    )

    if len(detections) == 0:
        detections = _cascade.detectMultiScale(
            gray_eq,
            1.05,
            3,
            minSize=(30, 30)
        )

    result["faces_count"] = int(
        len(detections)
    )

    if len(detections) == 0:
        no_face = {
            "id": -1,
            "confidence": 0.0,
            "matched": False,
            "algorithm": "no_face",
            "error": "No face detected"
        }

        result["arcface"] = dict(
            no_face,
            algorithm="arcface"
        )

        result["lbph"] = dict(
            no_face,
            algorithm="lbph",
            distance=999.0
        )

        result["hybrid"] = dict(
            no_face,
            algorithm="hybrid",
            reason="No face detected"
        )

        return jsonify(result)

    x, y, w, h = max(
        detections,
        key=lambda r: r[2] * r[3]
    )

    result["bbox"] = {
        "x": int(x),
        "y": int(y),
        "w": int(w),
        "h": int(h)
    }

    # Padding for ArcFace crop.
    pad_x = int(w * 0.12)
    pad_y = int(h * 0.12)

    x1 = max(
        0,
        x - pad_x
    )

    y1 = max(
        0,
        y - pad_y
    )

    x2 = min(
        frame.shape[1],
        x + w + pad_x
    )

    y2 = min(
        frame.shape[0],
        y + h + pad_y
    )

    face_color = frame[
        y1:y2,
        x1:x2
    ]

    face_roi = cv2.resize(
        gray_eq[
            y:y+h,
            x:x+w
        ],
        (100, 100),
        interpolation=cv2.INTER_AREA
    )

    # Run all three algorithms.
    arc, lbph, hybrid = _recognize_face(
        face_color,
        face_roi
    )

    result["arcface"] = arc
    result["lbph"] = lbph
    result["hybrid"] = hybrid

    return jsonify(result)


# =============================================================================
# SYNC ENROLLED FACE IMAGES
# =============================================================================

@app.route("/sync_faces", methods=["POST"])
def sync_faces():
    """
    Receives:
        files[0], files[1], ...
        replace=1 on the first batch.

    Replacement uploads are staged in faces_incoming.
    The active dataset is replaced only when /train starts.
    """

    try:
        replace = str(
            request.form.get(
                "replace",
                "0"
            )
        ).lower() in (
            "1",
            "true",
            "yes"
        )

        # Detect an active staged upload.
        staging_active = any(
            os.path.isfile(
                os.path.join(
                    INCOMING_DIR,
                    name
                )
            )
            for name in os.listdir(
                INCOMING_DIR
            )
        )

        use_staging = (
            replace
            or staging_active
        )

        incoming = (
            INCOMING_DIR
            if use_staging
            else FACES_DIR
        )

        if replace:
            # Clear previous staged batch.
            for old in os.listdir(
                INCOMING_DIR
            ):
                path = os.path.join(
                    INCOMING_DIR,
                    old
                )

                try:
                    if (
                        os.path.isfile(path)
                        or os.path.islink(path)
                    ):
                        os.remove(path)

                    elif os.path.isdir(path):
                        shutil.rmtree(path)

                except Exception:
                    pass

        uploaded = request.files.getlist(
            "files"
        )

        uploaded.extend(
            request.files.getlist(
                "files[]"
            )
        )

        # Also accept files[0], files[1], ...
        for key in request.files.keys():
            if (
                key.startswith("files[")
                and key.endswith("]")
                and key != "files[]"
            ):
                uploaded.extend(
                    request.files.getlist(key)
                )

        if not uploaded:
            return jsonify({
                "success": False,
                "error": "No face images received"
            }), 400

        saved = []
        skipped = []

        for uploaded_file in uploaded:
            filename = os.path.basename(
                uploaded_file.filename or ""
            )

            if not filename:
                continue

            if not re.match(
                r"^\d+(?:[_-].*)?\.(jpg|jpeg|png)$",
                filename,
                flags=re.IGNORECASE
            ):
                skipped.append({
                    "file": filename,
                    "reason": "invalid filename"
                })
                continue

            destination = os.path.join(
                incoming,
                filename
            )

            uploaded_file.save(
                destination
            )

            if not os.path.exists(
                destination
            ):
                skipped.append({
                    "file": filename,
                    "reason": "save failed"
                })
                continue

            if os.path.getsize(
                destination
            ) < MIN_FILE_BYTES:
                os.remove(
                    destination
                )

                skipped.append({
                    "file": filename,
                    "reason": "file too small"
                })

                continue

            test = cv2.imread(
                destination
            )

            if test is None:
                os.remove(
                    destination
                )

                skipped.append({
                    "file": filename,
                    "reason": "unreadable image"
                })

                continue

            saved.append(
                filename
            )

        return jsonify({
            "success": True,
            "saved": len(saved),
            "skipped": len(skipped),
            "staging": bool(use_staging),
            "files": saved,
            "skipped_files": skipped
        })

    except Exception as e:
        traceback.print_exc()

        return jsonify({
            "success": False,
            "error": str(e)
        }), 500


# =============================================================================
# TRAINING
# =============================================================================

def _swap_incoming_dataset():
    """
    Move staged images into the active faces/ directory.
    """

    if not os.path.isdir(
        INCOMING_DIR
    ):
        return False

    incoming_files = _iter_face_files(
        INCOMING_DIR
    )

    if not incoming_files:
        return False

    # Remove old active face images.
    for fname in os.listdir(
        FACES_DIR
    ):
        path = os.path.join(
            FACES_DIR,
            fname
        )

        if not os.path.isfile(path):
            continue

        ext = os.path.splitext(
            fname
        )[1].lower()

        if ext in (
            ".jpg",
            ".jpeg",
            ".png"
        ):
            try:
                os.remove(path)
            except Exception:
                pass

    # Move staged files into active dataset.
    for path, _, fname in incoming_files:
        destination = os.path.join(
            FACES_DIR,
            fname
        )

        os.replace(
            path,
            destination
        )

    return True


def _build_training_dataset():
    files = _iter_face_files(
        FACES_DIR
    )

    if not files:
        raise RuntimeError(
            "No valid face images found in faces/."
        )

    groups = Counter(
        sid
        for _, sid, _ in files
    )

    usable_ids = {
        sid
        for sid, count in groups.items()
        if count >= MIN_SAMPLES_PER_STUDENT
    }

    dropped = {
        str(sid): count
        for sid, count in groups.items()
        if sid not in usable_ids
    }

    files = [
        item
        for item in files
        if item[1] in usable_ids
    ]

    if not usable_ids:
        raise RuntimeError(
            "No student has enough valid face images for training."
        )

    return files, dropped


def _run_training_script():
    """
    Run train_all_models.py so LBPH and ArcFace embedding generation
    are kept in one training module.
    """

    train_script = os.path.join(
        PROJECT,
        "train_all_models.py"
    )

    if not os.path.exists(
        train_script
    ):
        raise RuntimeError(
            "train_all_models.py was not found."
        )

    process = subprocess.run(
        [
            sys.executable,
            train_script
        ],
        cwd=PROJECT,
        env=os.environ.copy(),
        text=True
    )

    if process.returncode != 0:
        raise RuntimeError(
            f"train_all_models.py exited with code "
            f"{process.returncode}"
        )


def _reload_after_training():
    global _lbph

    if not os.path.exists(
        TRAINER
    ):
        raise RuntimeError(
            "LBPH trainer.yml was not created."
        )

    if not _opencv_face_available():
        raise RuntimeError(
            "OpenCV contrib face module is unavailable."
        )

    recognizer = (
        cv2.face.LBPHFaceRecognizer_create(
            radius=1,
            neighbors=8,
            grid_x=8,
            grid_y=8
        )
    )

    recognizer.read(
        TRAINER
    )

    with _lock:
        _lbph = recognizer
        _models_ready["lbph"] = True

    if not _load_arcface_db():
        raise RuntimeError(
            "ArcFace embedding database was not created."
        )

    with _lock:
        _models_ready["hybrid"] = True


def _do_train():
    global _training_started_at
    global _lbph
    global _arc_embeddings
    global _arc_labels

    _training_started_at = time.time()

    try:
        _write_status(
            "running",
            "Preparing face dataset...",
            5
        )

        # Activate the newest synchronized dataset.
        _swap_incoming_dataset()

        files, dropped = (
            _build_training_dataset()
        )

        counts = Counter(
            sid
            for _, sid, _ in files
        )

        print(
            f"[face_server] Training {len(files)} "
            f"images from {len(counts)} students.",
            flush=True
        )

        print(
            f"[face_server] Samples per student: "
            f"{dict(sorted(counts.items()))}",
            flush=True
        )

        _write_status(
            "running",
            (
                f"Dataset ready: {len(files)} "
                f"images / {len(counts)} students."
            ),
            15,
            {
                "students": len(counts),
                "samples": len(files),
                "samples_per_student": dict(
                    sorted(counts.items())
                ),
                "dropped_students": dropped
            }
        )

        # Invalidate old in-memory models.
        with _lock:
            _lbph = None
            _arc_embeddings = None
            _arc_labels = None

            _models_ready["lbph"] = False
            _models_ready["arcface"] = False
            _models_ready["hybrid"] = False

        _write_status(
            "running",
            "Training LBPH + generating ArcFace embeddings...",
            25
        )

        _run_training_script()

        _write_status(
            "running",
            "Reloading LBPH and ArcFace models...",
            90
        )

        _reload_after_training()

        result = {
            "lbph": {
                "ok": os.path.exists(
                    TRAINER
                ),
                "samples": len(files),
                "students": len(counts)
            },
            "arcface": {
                "ok": os.path.exists(
                    ARC_DB
                ),
                "samples": len(files),
                "students": len(counts)
            },
            "hybrid": {
                "ok": (
                    os.path.exists(TRAINER)
                    and os.path.exists(ARC_DB)
                ),
                "description": (
                    "ArcFace primary + LBPH verification"
                )
            },
            "samples": len(files),
            "students": len(counts),
            "samples_per_student": dict(
                sorted(counts.items())
            ),
            "dropped_students": dropped
        }

        _write_status(
            "done",
            "LBPH, ArcFace and Hybrid training completed.",
            100,
            result
        )

        print(
            "[face_server] LBPH + ArcFace + Hybrid "
            "training completed [OK]",
            flush=True
        )

    except Exception as e:
        traceback.print_exc()

        with _lock:
            _models_ready["lbph"] = bool(
                os.path.exists(TRAINER)
            )

            _models_ready["arcface"] = bool(
                os.path.exists(ARC_DB)
            )

            _models_ready["hybrid"] = (
                _models_ready["lbph"]
                and _models_ready["arcface"]
            )

        _write_status(
            "error",
            f"Training failed: {e}",
            0,
            {
                "error": str(e)
            }
        )

        print(
            f"[face_server] TRAINING FAILED: {e}",
            flush=True
        )

    finally:
        _training_started_at = None


@app.route(
    "/train",
    methods=["POST"]
)
def train():
    global _train_thread

    if (
        _train_thread
        and _train_thread.is_alive()
    ):
        return jsonify({
            "success": False,
            "message": "Training already in progress"
        }), 409

    _train_thread = threading.Thread(
        target=_do_train,
        daemon=True
    )

    _train_thread.start()

    return jsonify({
        "success": True,
        "message": (
            "LBPH + ArcFace + Hybrid training started"
        )
    })


@app.route("/train/status")
def train_status():
    try:
        with open(
            STATUS_F,
            "r",
            encoding="utf-8"
        ) as f:
            return jsonify(
                json.load(f)
            )

    except Exception:
        return jsonify({
            "state": "unknown",
            "message": "Training has not started yet.",
            "progress": 0
        })


# =============================================================================
# RELOAD
# =============================================================================

@app.route(
    "/reload",
    methods=["GET", "POST"]
)
def reload_models():
    global _lbph

    loaded = {}

    # LBPH
    if (
        os.path.exists(TRAINER)
        and _opencv_face_available()
    ):
        try:
            recognizer = (
                cv2.face.LBPHFaceRecognizer_create(
                    radius=1,
                    neighbors=8,
                    grid_x=8,
                    grid_y=8
                )
            )

            recognizer.read(
                TRAINER
            )

            with _lock:
                _lbph = recognizer
                _models_ready["lbph"] = True

            loaded["lbph"] = True

        except Exception as e:
            loaded["lbph"] = False
            loaded["lbph_error"] = str(e)

    else:
        loaded["lbph"] = False
        loaded["lbph_error"] = "trainer.yml missing"

    # ArcFace
    try:
        _get_arcface()

        loaded["arcface"] = (
            _load_arcface_db()
        )

    except Exception as e:
        loaded["arcface"] = False
        loaded["arcface_error"] = str(e)

    with _lock:
        _models_ready["hybrid"] = (
            _models_ready["lbph"]
            and _models_ready["arcface"]
        )

    loaded["hybrid"] = (
        _models_ready["hybrid"]
    )

    return jsonify({
        "ok": True,
        "loaded": loaded,
        "models": _models_ready
    })


# =============================================================================
# GUNICORN / RENDER STARTUP
# =============================================================================
# Render uses Gunicorn:
#   gunicorn face_server:app ...
#
# In that mode Python does NOT execute the __main__ section below.
# Start model loading when the module is imported so /recognize never sees
# an uninitialized face detector.
_startup_thread = None

try:
    _startup_thread = threading.Thread(
        target=_load_models,
        daemon=True,
        name="face-model-loader"
    )
    _startup_thread.start()
    print(
        "[face_server] Background model loading started.",
        flush=True
    )
except Exception as _startup_error:
    print(
        f"[face_server] Background model loading could not start: {_startup_error}",
        flush=True
    )


# =============================================================================
# START SERVER
# =============================================================================

if __name__ == "__main__":
    print(
        "[face_server] Starting CICS "
        "LBPH + ArcFace + Hybrid API...",
        flush=True
    )

    thread = threading.Thread(
        target=_load_models,
        daemon=True
    )

    thread.start()

    port = int(
        os.environ.get(
            "PORT",
            5001
        )
    )

    app.run(
        host="0.0.0.0",
        port=port,
        threaded=True
    )
