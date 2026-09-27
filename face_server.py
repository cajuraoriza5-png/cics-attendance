"""
CICS Attendance Face Recognition API
LBPH + ArcFace + Hybrid

Endpoints:
GET  /
GET  /status
POST /detect
POST /sync_faces
POST /train
GET  /train/status
GET  /reload
POST /recognize

Dataset:
faces/<student_id>_<capture_number>.jpg|jpeg|png

ArcFace:
Uses InsightFace FaceAnalysis with the buffalo_s recognition model.
The pretrained model is NOT retrained. During /train, embeddings are
generated from the enrolled images and stored in arcface_embeddings.npz.

LBPH:
Still trained locally from the same enrolled images.

Hybrid:
ArcFace is the primary identity signal. LBPH is a second verification
signal. Scores are kept separate and a calibrated hybrid decision is made.
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

PROJECT = os.path.dirname(os.path.abspath(__file__))

FACES_DIR = os.path.join(PROJECT, "faces")
INCOMING_DIR = os.path.join(PROJECT, "faces_incoming")
TRAINER = os.path.join(PROJECT, "trainer.yml")
ARC_DB = os.path.join(PROJECT, "arcface_embeddings.npz")
STATUS_F = os.path.join(FACES_DIR, ".train_status.json")

os.makedirs(FACES_DIR, exist_ok=True)
os.makedirs(INCOMING_DIR, exist_ok=True)

app = Flask(__name__)
CORS(app)

# ---------------------------------------------------------------------------
# Runtime configuration
# ---------------------------------------------------------------------------

# ArcFace cosine similarity:
# 0.50 = permissive, 0.60 = moderate, 0.65 = stricter.
# We start at 0.55 and expose the raw similarity so it can be calibrated.
ARCFACE_THRESHOLD = float(os.environ.get("ARCFACE_THRESHOLD", "0.55"))

# LBPH confidence is converted only for display/verification.
LBPH_THRESHOLD = float(os.environ.get("LBPH_THRESHOLD", "60.0"))

# Hybrid minimum. The final hybrid score is 0..100.
HYBRID_THRESHOLD = float(os.environ.get("HYBRID_THRESHOLD", "65.0"))

# Minimum images per student for a usable identity.
MIN_SAMPLES_PER_STUDENT = int(os.environ.get("MIN_SAMPLES_PER_STUDENT", "5"))

# Ignore tiny files, which are often placeholder files.
MIN_FILE_BYTES = int(os.environ.get("MIN_FILE_BYTES", "1024"))

_lbph = None
_arc_app = None
_cascade = None
_arc_embeddings = None
_arc_labels = None

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
# Helpers
# ---------------------------------------------------------------------------

def _write_status(state, message, progress, result=None):
    payload = {
        "state": state,
        "message": message,
        "progress": int(max(0, min(100, progress))),
        "timestamp": time.strftime("%Y-%m-%dT%H:%M:%S"),
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
            json.dump(payload, f)
        os.replace(tmp, STATUS_F)
    except Exception as e:
        print(f"[face_server] status write failed: {e}", flush=True)


def _set_ready(name, value):
    with _lock:
        _models_ready[name] = bool(value)


def _update_hybrid_ready():
    """Hybrid is ready when both independent recognizers are ready."""
    with _lock:
        _models_ready["hybrid"] = bool(
            _models_ready.get("lbph", False)
            and _models_ready.get("arcface", False)
        )


def _opencv_face_available():
    return (
        hasattr(cv2, "face")
        and hasattr(cv2.face, "LBPHFaceRecognizer_create")
    )


def _lbph_confidence(distance):
    # This is a display/verification mapping, not a probability.
    raw = (1.0 - float(distance) / 150.0) * 100.0
    return round(max(0.0, min(100.0, raw + 35.0)), 1)


def _cosine(a, b):
    a = np.asarray(a, dtype=np.float32)
    b = np.asarray(b, dtype=np.float32)

    na = np.linalg.norm(a)
    nb = np.linalg.norm(b)

    if na <= 1e-8 or nb <= 1e-8:
        return 0.0

    return float(np.dot(a, b) / (na * nb))


def _arcface_percent(similarity):
    # Map cosine similarity [-1,1] into a display score [0,100].
    # Recognition still uses the raw threshold separately.
    value = (float(similarity) + 1.0) / 2.0 * 100.0
    return round(max(0.0, min(100.0, value)), 1)


def _parse_student_id(filename):
    """
    Accept:
      14_1.jpg
      14_25.png
      14.jpg

    Return:
      14
    """
    m = re.match(r"^(\d+)(?:[_-].*)?\.(jpg|jpeg|png)$",
                 filename, flags=re.IGNORECASE)
    if not m:
        return None
    return int(m.group(1))


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

        if os.path.getsize(path) < MIN_FILE_BYTES:
            continue

        sid = _parse_student_id(fname)
        if sid is None:
            continue

        items.append((path, sid, fname))

    return sorted(items, key=lambda x: x[2].lower())


def _load_gray_face(path):
    img = cv2.imread(path, cv2.IMREAD_GRAYSCALE)
    if img is None:
        return None

    img = cv2.resize(img, (100, 100), interpolation=cv2.INTER_AREA)
    img = cv2.equalizeHist(img)
    return img


def _get_arcface():
    global _arc_app

    with _lock:
        if _arc_app is not None:
            return _arc_app

    if FaceAnalysis is None:
        raise RuntimeError(
            "InsightFace is unavailable: " +
            globals().get("_INSIGHTFACE_IMPORT_ERROR", "unknown import error")
        )

    print("[face_server] Loading ArcFace/InsightFace buffalo_s...", flush=True)

    # buffalo_s is selected to keep the Render deployment smaller.
    app_model = FaceAnalysis(
        name="buffalo_s",
        providers=["CPUExecutionProvider"]
    )

    # Render CPU service. 640x640 gives better detection than tiny input.
    app_model.prepare(ctx_id=-1, det_size=(640, 640))

    with _lock:
        _arc_app = app_model
        _models_ready["arcface"] = True

    print("[face_server] ArcFace/InsightFace loaded [OK]", flush=True)
    return app_model


def _load_arcface_db():
    global _arc_embeddings, _arc_labels

    if not os.path.exists(ARC_DB):
        with _lock:
            _arc_embeddings = None
            _arc_labels = None
            _models_ready["arcface"] = False
        return False

    try:
        data = np.load(ARC_DB, allow_pickle=False)
        embeddings = np.asarray(data["embeddings"], dtype=np.float32)
        labels = np.asarray(data["labels"], dtype=np.int32)

        if len(embeddings) == 0 or len(labels) != len(embeddings):
            raise ValueError("ArcFace embedding database is empty or invalid.")

        with _lock:
            _arc_embeddings = embeddings
            _arc_labels = labels
            _models_ready["arcface"] = True

        print(
            f"[face_server] Loaded ArcFace DB: "
            f"{len(embeddings)} embeddings / {len(np.unique(labels))} students",
            flush=True
        )
        return True

    except Exception as e:
        print(f"[face_server] ArcFace DB load failed: {e}", flush=True)
        with _lock:
            _arc_embeddings = None
            _arc_labels = None
            _models_ready["arcface"] = False
        return False


def _load_models():
    global _lbph, _cascade

    print("[face_server] Starting ArcFace + LBPH model loading...", flush=True)

    # Haar is retained for the lightweight API bounding box.
    _cascade = cv2.CascadeClassifier(
        cv2.data.haarcascades + "haarcascade_frontalface_default.xml"
    )

    if _cascade.empty():
        print("[face_server] WARNING: Haar Cascade failed.", flush=True)
    else:
        print("[face_server] Haar Cascade loaded [OK]", flush=True)

    # LBPH
    if os.path.exists(TRAINER) and _opencv_face_available():
        try:
            rec = cv2.face.LBPHFaceRecognizer_create(
                radius=1, neighbors=8, grid_x=8, grid_y=8
            )
            rec.read(TRAINER)

            with _lock:
                _lbph = rec
                _models_ready["lbph"] = True

            print("[face_server] LBPH loaded [OK]", flush=True)
        except Exception as e:
            print(f"[face_server] LBPH load failed: {e}", flush=True)
            _set_ready("lbph", False)
    else:
        print("[face_server] LBPH model not available yet.", flush=True)
        _set_ready("lbph", False)

    # ArcFace database + runtime
    try:
        _get_arcface()
        _load_arcface_db()
    except Exception as e:
        print(f"[face_server] ArcFace startup load failed: {e}", flush=True)
        _set_ready("arcface", False)

    with _lock:
        _models_ready["loading"] = False
    _update_hybrid_ready()

    print("[face_server] Model loading completed.", flush=True)


def _arcface_predict(face_color):
    """
    Run InsightFace on the color face crop and compare the embedding with
    all enrolled embeddings. Identity is chosen by maximum cosine similarity.
    """
    try:
        app_model = _get_arcface()
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

    if db_embeddings is None or db_labels is None or len(db_embeddings) == 0:
        return {
            "id": -1,
            "confidence": 0.0,
            "similarity": 0.0,
            "matched": False,
            "algorithm": "arcface",
            "error": "ArcFace embedding database is not trained."
        }

    try:
        # InsightFace expects BGR images.
        faces = app_model.get(face_color)

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

        embedding = np.asarray(face.embedding, dtype=np.float32)
        if embedding.ndim != 1 or embedding.size == 0:
            raise ValueError("Invalid ArcFace embedding.")

        embedding /= max(np.linalg.norm(embedding), 1e-8)

        # Compare against every enrolled embedding.
        sims = np.asarray(
            [_cosine(embedding, ref) for ref in db_embeddings],
            dtype=np.float32
        )

        best_index = int(np.argmax(sims))
        best_similarity = float(sims[best_index])
        best_id = int(db_labels[best_index])

        # Aggregate the best few embeddings for the winning student.
        # This reduces dependence on one enrollment frame.
        same_student = np.where(db_labels == best_id)[0]
        student_sims = sorted(
            [float(sims[i]) for i in same_student],
            reverse=True
        )

        top_k = student_sims[:min(5, len(student_sims))]
        representative_similarity = (
            float(np.mean(top_k)) if top_k else best_similarity
        )

        # Use the best similarity for identity, but report the representative
        # score as the confidence-like value.
        matched = best_similarity >= ARCFACE_THRESHOLD

        return {
            "id": best_id if matched else best_id,
            "confidence": _arcface_percent(representative_similarity),
            "similarity": round(best_similarity, 5),
            "representative_similarity": round(
                representative_similarity, 5
            ),
            "matched": bool(matched),
            "algorithm": "arcface",
            "samples_compared": int(len(same_student))
        }

    except Exception as e:
        print(f"[face_server] ArcFace prediction error: {e}", flush=True)
        return {
            "id": -1,
            "confidence": 0.0,
            "similarity": 0.0,
            "matched": False,
            "algorithm": "arcface",
            "error": str(e)
        }


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
        sid, distance = model.predict(face_roi)
        confidence = _lbph_confidence(distance)

        return {
            "id": int(sid),
            "confidence": confidence,
            "distance": round(float(distance), 4),
            "matched": bool(confidence >= LBPH_THRESHOLD),
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


def _hybrid_predict(arc, lbph):
    """
    Hybrid decision.

    ArcFace is primary. LBPH is a second signal.

    Cases:
      1. ArcFace + LBPH agree -> strong hybrid score.
      2. ArcFace passes, LBPH does not -> still allow if ArcFace is strong,
         but lower the hybrid score.
      3. LBPH passes but ArcFace fails -> reject by default.
      4. They disagree -> reject.
    """

    arc_id = int(arc.get("id", -1)) if arc else -1
    lbph_id = int(lbph.get("id", -1)) if lbph else -1

    arc_sim = float(arc.get("similarity", 0.0)) if arc else 0.0
    arc_conf = float(arc.get("confidence", 0.0)) if arc else 0.0
    lbph_conf = float(lbph.get("confidence", 0.0)) if lbph else 0.0

    arc_pass = bool(arc and arc.get("matched") and arc_id > 0)
    lbph_pass = bool(lbph and lbph.get("matched") and lbph_id > 0)

    if not arc_pass:
        return {
            "id": arc_id if arc_id > 0 else lbph_id,
            "confidence": round(max(0.0, min(100.0, arc_conf)), 1),
            "matched": False,
            "algorithm": "hybrid",
            "reason": "ArcFace threshold not reached",
            "arcface_confidence": arc_conf,
            "arcface_similarity": arc_sim,
            "lbph_confidence": lbph_conf,
            "agreement": False
        }

    # ArcFace passes. If LBPH identifies the same person, reward agreement.
    if lbph_pass and lbph_id == arc_id:
        hybrid_score = (arc_conf * 0.75) + (lbph_conf * 0.25)

        return {
            "id": arc_id,
            "confidence": round(hybrid_score, 1),
            "matched": bool(hybrid_score >= HYBRID_THRESHOLD),
            "algorithm": "hybrid",
            "reason": "ArcFace + LBPH agree",
            "arcface_confidence": arc_conf,
            "arcface_similarity": arc_sim,
            "lbph_confidence": lbph_conf,
            "agreement": True
        }

    # ArcFace is strong enough but LBPH did not pass.
    # Keep it as a valid ArcFace-led result only when similarity is comfortably
    # above threshold. The gap avoids treating marginal ArcFace matches as
    # attendance.
    strong_arc = arc_sim >= max(
        ARCFACE_THRESHOLD + 0.08,
        0.63
    )

    hybrid_score = (arc_conf * 0.85) + (lbph_conf * 0.15)

    return {
        "id": arc_id,
        "confidence": round(hybrid_score, 1),
        "matched": bool(strong_arc and hybrid_score >= HYBRID_THRESHOLD),
        "algorithm": "hybrid",
        "reason": (
            "ArcFace accepted; LBPH did not verify"
            if not lbph_pass
            else "ArcFace/LBPH disagreement"
        ),
        "arcface_confidence": arc_conf,
        "arcface_similarity": arc_sim,
        "lbph_confidence": lbph_conf,
        "agreement": bool(lbph_pass and lbph_id == arc_id)
    }


def _recognize_face(face_color, face_roi):
    arc = _arcface_predict(face_color)
    lbph = _lbph_predict(face_roi)
    hybrid = _hybrid_predict(arc, lbph)

    return arc, lbph, hybrid


# ---------------------------------------------------------------------------
# Routes
# ---------------------------------------------------------------------------

@app.route("/")
def home():
    return "CICS Attendance ArcFace + LBPH API is Running!"


@app.route("/status")
def status():
    with _lock:
        models = dict(_models_ready)

    return jsonify({
        "ok": True,
        "models": models,
        "thresholds": {
            "arcface_similarity": ARCFACE_THRESHOLD,
            "lbph_confidence": LBPH_THRESHOLD,
            "hybrid_confidence": HYBRID_THRESHOLD
        }
    })


@app.route("/detect", methods=["POST"])
def detect():
    try:
        data = request.get_json(force=True) or {}
        image_base64 = data.get("image", "")

        image_bytes = base64.b64decode(image_base64)
        frame = cv2.imdecode(
            np.frombuffer(image_bytes, np.uint8),
            cv2.IMREAD_COLOR
        )
    except Exception:
        return jsonify({"bbox": None, "faces_count": 0})

    if frame is None or _cascade is None:
        return jsonify({"bbox": None, "faces_count": 0})

    gray = cv2.equalizeHist(
        cv2.cvtColor(frame, cv2.COLOR_BGR2GRAY)
    )

    detections = _cascade.detectMultiScale(
        gray, 1.1, 5, minSize=(40, 40)
    )

    if len(detections) == 0:
        detections = _cascade.detectMultiScale(
            gray, 1.05, 3, minSize=(30, 30)
        )

    if len(detections) == 0:
        return jsonify({"bbox": None, "faces_count": 0})

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
        "faces_count": int(len(detections))
    })


@app.route("/recognize", methods=["POST"])
def recognize():
    result = {
        "faces_count": 0,
        "bbox": None,
        "arcface": {"error": "not run"},
        "lbph": {"error": "not run"},
        "hybrid": {"error": "not run"}
    }

    try:
        data = request.get_json(force=True) or {}
        image_base64 = data.get("image", "")

        if not image_base64:
            return jsonify({"error": "No image data received."}), 400

        image_bytes = base64.b64decode(image_base64)
        frame = cv2.imdecode(
            np.frombuffer(image_bytes, np.uint8),
            cv2.IMREAD_COLOR
        )
    except Exception as e:
        return jsonify({"error": f"Input error: {e}"}), 400

    if frame is None:
        return jsonify({"error": "Empty frame"}), 400

    if _cascade is None:
        return jsonify({"error": "Face detector is not ready"}), 503

    gray = cv2.cvtColor(frame, cv2.COLOR_BGR2GRAY)
    gray_eq = cv2.equalizeHist(gray)

    detections = _cascade.detectMultiScale(
        gray_eq, 1.1, 5, minSize=(40, 40)
    )

    if len(detections) == 0:
        detections = _cascade.detectMultiScale(
            gray_eq, 1.05, 3, minSize=(30, 30)
        )

    result["faces_count"] = int(len(detections))

    if len(detections) == 0:
        result["arcface"] = {
            "id": -1,
            "confidence": 0.0,
            "similarity": 0.0,
            "matched": False,
            "algorithm": "arcface",
            "error": "No face detected"
        }
        result["lbph"] = {
            "id": -1,
            "confidence": 0.0,
            "distance": 999.0,
            "matched": False,
            "algorithm": "lbph",
            "error": "No face detected"
        }
        result["hybrid"] = {
            "id": -1,
            "confidence": 0.0,
            "matched": False,
            "algorithm": "hybrid",
            "reason": "No face detected"
        }
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

    # Slight padding improves ArcFace when Haar box is tight.
    pad_x = int(w * 0.12)
    pad_y = int(h * 0.12)

    x1 = max(0, x - pad_x)
    y1 = max(0, y - pad_y)
    x2 = min(frame.shape[1], x + w + pad_x)
    y2 = min(frame.shape[0], y + h + pad_y)

    face_color = frame[y1:y2, x1:x2]
    face_roi = cv2.resize(
        gray_eq[y:y+h, x:x+w],
        (100, 100),
        interpolation=cv2.INTER_AREA
    )

    arc, lbph, hybrid = _recognize_face(
        face_color,
        face_roi
    )

    result["arcface"] = arc
    result["lbph"] = lbph
    result["hybrid"] = hybrid

    return jsonify(result)


# ---------------------------------------------------------------------------
# Dataset synchronization
# ---------------------------------------------------------------------------

@app.route("/sync_faces", methods=["POST"])
def sync_faces():
    """
    Receives multipart:
      files[0], files[1], ...
      replace=1 on the first batch.

    Files are staged into faces_incoming when replace=1. The existing live
    dataset is not destroyed until /train is called after all batches arrive.
    """

    try:
        replace = str(
            request.form.get("replace", "0")
        ).lower() in ("1", "true", "yes")

        incoming = INCOMING_DIR if replace else FACES_DIR

        if replace:
            # Clear staging only, not the currently active dataset.
            for old in os.listdir(INCOMING_DIR):
                path = os.path.join(INCOMING_DIR, old)
                try:
                    if os.path.isfile(path) or os.path.islink(path):
                        os.remove(path)
                    elif os.path.isdir(path):
                        shutil.rmtree(path)
                except Exception:
                    pass

        uploaded = request.files.getlist("files")
        if not uploaded:
            uploaded = request.files.getlist("files[]")

        if not uploaded:
            return jsonify({
                "success": False,
                "error": "No face images received"
            }), 400

        saved = []
        skipped = []

        for f in uploaded:
            filename = os.path.basename(f.filename or "")

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

            # Save then validate.
            destination = os.path.join(incoming, filename)
            f.save(destination)

            if not os.path.exists(destination):
                skipped.append({
                    "file": filename,
                    "reason": "save failed"
                })
                continue

            if os.path.getsize(destination) < MIN_FILE_BYTES:
                os.remove(destination)
                skipped.append({
                    "file": filename,
                    "reason": "file too small"
                })
                continue

            test = cv2.imread(destination)
            if test is None:
                os.remove(destination)
                skipped.append({
                    "file": filename,
                    "reason": "unreadable image"
                })
                continue

            saved.append(filename)

        return jsonify({
            "success": True,
            "saved": len(saved),
            "skipped": len(skipped),
            "replaced_dataset": replace,
            "files": saved,
            "skipped_files": skipped
        })

    except Exception as e:
        traceback.print_exc()
        return jsonify({
            "success": False,
            "error": str(e)
        }), 500


# ---------------------------------------------------------------------------
# Training
# ---------------------------------------------------------------------------

def _build_training_dataset():
    files = _iter_face_files(FACES_DIR)

    if not files:
        raise RuntimeError(
            "No valid face images found in faces/."
        )

    groups = Counter(sid for _, sid, _ in files)

    # Drop students with too few images.
    usable_ids = {
        sid for sid, count in groups.items()
        if count >= MIN_SAMPLES_PER_STUDENT
    }

    dropped = {
        str(sid): count
        for sid, count in groups.items()
        if sid not in usable_ids
    }

    files = [
        item for item in files
        if item[1] in usable_ids
    ]

    if len(usable_ids) < 1:
        raise RuntimeError(
            "No student has enough valid face images for training."
        )

    return files, dropped


def _train_lbph(files):
    if not _opencv_face_available():
        raise RuntimeError(
            "opencv-contrib-python is required for LBPH."
        )

    faces = []
    labels = []

    for path, sid, fname in files:
        gray = _load_gray_face(path)

        if gray is None:
            continue

        faces.append(gray)
        labels.append(sid)

    if not faces:
        raise RuntimeError("No readable grayscale faces for LBPH.")

    recognizer = cv2.face.LBPHFaceRecognizer_create(
        radius=1,
        neighbors=8,
        grid_x=8,
        grid_y=8
    )

    recognizer.train(
        faces,
        np.asarray(labels, dtype=np.int32)
    )

    temp = TRAINER + ".tmp"
    recognizer.write(temp)
    os.replace(temp, TRAINER)

    return {
        "ok": True,
        "samples": len(faces),
        "students": len(set(labels))
    }


def _train_arcface(files):
    global _arc_embeddings, _arc_labels

    app_model = _get_arcface()

    embeddings = []
    labels = []
    failed = []

    total = len(files)

    for index, (path, sid, fname) in enumerate(files, start=1):
        try:
            image = cv2.imread(path, cv2.IMREAD_COLOR)

            if image is None:
                failed.append(fname)
                continue

            faces = app_model.get(image)

            if not faces:
                failed.append(fname)
                continue

            face = max(
                faces,
                key=lambda f: float(
                    getattr(f, "det_score", 0.0) or 0.0
                )
            )

            emb = np.asarray(
                face.embedding,
                dtype=np.float32
            )

            if emb.ndim != 1 or emb.size == 0:
                failed.append(fname)
                continue

            norm = np.linalg.norm(emb)
            if norm <= 1e-8:
                failed.append(fname)
                continue

            emb = emb / norm

            embeddings.append(emb)
            labels.append(sid)

            # Training progress: ArcFace embedding generation is the expensive
            # phase, so report it continuously.
            progress = 35 + int(
                (index / max(1, total)) * 45
            )
            _write_status(
                "running",
                f"Generating ArcFace embeddings {index}/{total}...",
                progress
            )

        except Exception as e:
            print(
                f"[face_server] ArcFace enrollment failed "
                f"{fname}: {e}",
                flush=True
            )
            failed.append(fname)

    if not embeddings:
        raise RuntimeError(
            "ArcFace could not generate any embeddings. "
            "Check that enrolled images contain detectable faces."
        )

    embedding_array = np.asarray(
        embeddings,
        dtype=np.float32
    )
    label_array = np.asarray(
        labels,
        dtype=np.int32
    )

    temp = ARC_DB + ".tmp.npz"

    np.savez_compressed(
        temp,
        embeddings=embedding_array,
        labels=label_array
    )

    # np.savez adds .npz if the filename does not end in .npz.
    actual_temp = temp if os.path.exists(temp) else temp + ".npz"

    os.replace(
        actual_temp,
        ARC_DB
    )

    with _lock:
        _arc_embeddings = embedding_array
        _arc_labels = label_array
        _models_ready["arcface"] = True

    return {
        "ok": True,
        "samples": int(len(embedding_array)),
        "students": int(len(np.unique(label_array))),
        "failed_images": failed[:50],
        "failed_count": len(failed)
    }


def _swap_incoming_dataset():
    if not os.path.isdir(INCOMING_DIR):
        return False

    incoming_files = _iter_face_files(INCOMING_DIR)
    if not incoming_files:
        return False

    # Keep status file and any non-image operational files in faces/.
    for fname in os.listdir(FACES_DIR):
        path = os.path.join(FACES_DIR, fname)
        if os.path.isfile(path):
            ext = os.path.splitext(fname)[1].lower()
            if ext in (".jpg", ".jpeg", ".png"):
                try:
                    os.remove(path)
                except Exception:
                    pass

    for path, _, fname in incoming_files:
        destination = os.path.join(FACES_DIR, fname)
        os.replace(path, destination)

    return True


def _do_train():
    global _lbph, _arc_app, _arc_embeddings, _arc_labels
    global _training_started_at

    _training_started_at = time.time()

    try:
        _write_status(
            "running",
            "Preparing face dataset...",
            5
        )

        # If the retraining flow uploaded a replacement dataset, activate it
        # only now, after all batches have been received.
        _swap_incoming_dataset()

        files, dropped = _build_training_dataset()

        counts = Counter(sid for _, sid, _ in files)

        print(
            f"[face_server] Training {len(files)} images "
            f"from {len(counts)} students.",
            flush=True
        )
        print(
            f"[face_server] Samples per student: {dict(sorted(counts.items()))}",
            flush=True
        )

        _write_status(
            "running",
            f"Dataset ready: {len(files)} images / "
            f"{len(counts)} students.",
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

        # Invalidate old in-memory models before replacing them.
        with _lock:
            _lbph = None
            _arc_embeddings = None
            _arc_labels = None
            _models_ready["lbph"] = False
            _models_ready["arcface"] = False
            _models_ready["hybrid"] = False

        # LBPH
        _write_status(
            "running",
            "Training LBPH...",
            20
        )

        lbph_result = _train_lbph(files)

        _write_status(
            "running",
            "LBPH completed. Preparing ArcFace...",
            35,
            {"lbph": lbph_result}
        )

        # ArcFace embeddings
        arc_result = _train_arcface(files)

        _write_status(
            "running",
            "Reloading trained models...",
            90,
            {
                "lbph": lbph_result,
                "arcface": arc_result
            }
        )

        # Reload LBPH from disk.
        rec = cv2.face.LBPHFaceRecognizer_create(
            radius=1,
            neighbors=8,
            grid_x=8,
            grid_y=8
        )
        rec.read(TRAINER)

        with _lock:
            _lbph = rec
            _models_ready["lbph"] = True

        _load_arcface_db()
        _update_hybrid_ready()

        result = {
            "lbph": lbph_result,
            "arcface": arc_result,
            "students": len(counts),
            "samples": len(files),
            "samples_per_student": dict(
                sorted(counts.items())
            ),
            "dropped_students": dropped
        }

        _write_status(
            "done",
            "LBPH and ArcFace training completed.",
            100,
            result
        )

        print(
            "[face_server] LBPH + ArcFace training completed [OK]",
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
            _models_ready["hybrid"] = bool(
                _models_ready["lbph"] and _models_ready["arcface"]
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


@app.route("/train", methods=["POST"])
def train():
    global _train_thread

    if _train_thread and _train_thread.is_alive():
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
        "message": "ArcFace + LBPH training started"
    })


@app.route("/train/status")
def train_status():
    try:
        with open(STATUS_F, "r", encoding="utf-8") as f:
            return jsonify(json.load(f))
    except Exception:
        return jsonify({
            "state": "unknown",
            "message": "Training has not started yet.",
            "progress": 0
        })


@app.route("/reload", methods=["GET", "POST"])
def reload_models():
    global _lbph

    loaded = {}

    # LBPH
    if (
        os.path.exists(TRAINER)
        and _opencv_face_available()
    ):
        try:
            rec = cv2.face.LBPHFaceRecognizer_create(
                radius=1,
                neighbors=8,
                grid_x=8,
                grid_y=8
            )
            rec.read(TRAINER)

            with _lock:
                _lbph = rec
                _models_ready["lbph"] = True

            loaded["lbph"] = True

        except Exception as e:
            loaded["lbph"] = False
            loaded["lbph_error"] = str(e)
    else:
        loaded["lbph"] = False
        loaded["lbph_error"] = "trainer.yml missing"

    # ArcFace database
    try:
        _get_arcface()
        loaded["arcface"] = _load_arcface_db()
    except Exception as e:
        loaded["arcface"] = False
        loaded["arcface_error"] = str(e)

    _update_hybrid_ready()

    return jsonify({
        "ok": True,
        "loaded": loaded,
        "models": _models_ready
    })


if __name__ == "__main__":
    print(
        "[face_server] Starting CICS ArcFace + LBPH API...",
        flush=True
    )

    thread = threading.Thread(
        target=_load_models,
        daemon=True
    )
    thread.start()

    port = int(
        os.environ.get("PORT", 5001)
    )

    app.run(
        host="0.0.0.0",
        port=port,
        threaded=True
    )
