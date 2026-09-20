"""
face_server.py
===============================================================================
CICS Attendance Face Recognition API

Runs on Render and provides:

GET  /                  Health check
GET  /status            Server/model status

POST /detect            Detect face using Haar Cascade
POST /recognize         Detect + recognize face
POST /sync_faces        Receive enrolled face images from PHP
POST /train             Train LBPH + Fisherfaces
GET  /train/status      Training status
GET  /reload            Reload trained models

Architecture:

InfinityFree PHP
        |
        | upload enrolled face images
        v
Render Flask API
        |
        +--> faces/
        |
        +--> LBPH
        |
        +--> Fisherfaces
        |
        v
Recognition
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

import numpy as np
import cv2

from flask import Flask, request, jsonify
from flask_cors import CORS


# =============================================================================
# PATHS
# =============================================================================

PROJECT = os.path.dirname(os.path.abspath(__file__))

FACES_DIR = os.path.join(PROJECT, "faces")
TRAINER = os.path.join(PROJECT, "trainer.yml")
FISHERFACES = os.path.join(PROJECT, "fisherfaces.yml")
STATUS_F = os.path.join(FACES_DIR, ".train_status.json")


os.makedirs(FACES_DIR, exist_ok=True)


# =============================================================================
# FLASK
# =============================================================================

app = Flask(__name__)
CORS(app)


@app.route("/")
def home():
    return "CICS Attendance Face Recognition API is Running!"


# =============================================================================
# GLOBAL MODELS
# =============================================================================

_lbph = None
_fisherfaces = None
_cascade = None

_lock = threading.Lock()

_models_ready = {
    "lbph": False,
    "fisherfaces": False,
    "loading": True
}

_train_thread = None


# =============================================================================
# OPENCV CHECK
# =============================================================================

def _opencv_face_available():

    return (
        hasattr(cv2, "face")
        and hasattr(
            cv2.face,
            "LBPHFaceRecognizer_create"
        )
    )


# =============================================================================
# MODEL LOADING
# =============================================================================

def _load_models():

    global _lbph
    global _fisherfaces
    global _cascade
    global _models_ready

    print(
        "[face_server] Starting model loading...",
        flush=True
    )

    # -------------------------------------------------------------------------
    # Haar Cascade
    # -------------------------------------------------------------------------

    print(
        "[face_server] Loading Haar Cascade...",
        flush=True
    )

    _cascade = cv2.CascadeClassifier(
        cv2.data.haarcascades
        + "haarcascade_frontalface_default.xml"
    )

    if _cascade.empty():

        print(
            "[face_server] WARNING: Haar Cascade failed!",
            flush=True
        )

    else:

        print(
            "[face_server] Haar Cascade loaded [OK]",
            flush=True
        )

    # -------------------------------------------------------------------------
    # OpenCV Contrib
    # -------------------------------------------------------------------------

    if not _opencv_face_available():

        print(
            "[face_server] ERROR: OpenCV contrib face module unavailable.",
            flush=True
        )

    else:

        print(
            "[face_server] OpenCV face module available [OK]",
            flush=True
        )

    # -------------------------------------------------------------------------
    # LBPH
    # -------------------------------------------------------------------------

    if (
        os.path.exists(TRAINER)
        and _opencv_face_available()
    ):

        try:

            print(
                "[face_server] Loading LBPH model...",
                flush=True
            )

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

    else:

        print(
            "[face_server] No LBPH model available yet.",
            flush=True
        )

    # -------------------------------------------------------------------------
    # Fisherfaces
    # -------------------------------------------------------------------------

    if (
        os.path.exists(FISHERFACES)
        and hasattr(cv2, "face")
        and hasattr(
            cv2.face,
            "FisherFaceRecognizer_create"
        )
    ):

        try:

            print(
                "[face_server] Loading Fisherfaces model...",
                flush=True
            )

            recognizer = (
                cv2.face.FisherFaceRecognizer_create()
            )

            recognizer.read(FISHERFACES)

            with _lock:
                _fisherfaces = recognizer

            _models_ready["fisherfaces"] = True

            print(
                "[face_server] Fisherfaces loaded [OK]",
                flush=True
            )

        except Exception as e:

            print(
                f"[face_server] Fisherfaces load failed: {e}",
                flush=True
            )

    else:

        print(
            "[face_server] No Fisherfaces model available yet.",
            flush=True
        )

    with _lock:
        _models_ready["loading"] = False

    print(
        "[face_server] Model loading completed.",
        flush=True
    )


# =============================================================================
# CONFIDENCE
# =============================================================================

def _lbph_confidence(distance):

    raw = (
        1.0
        - float(distance) / 150.0
    ) * 100.0

    return round(
        max(
            0.0,
            min(
                100.0,
                raw + 35.0
            )
        ),
        1
    )


def _fisherfaces_confidence(distance):

    raw = (
        1.0
        - float(distance) / 2000.0
    ) * 100.0

    return round(
        max(
            0.0,
            min(
                100.0,
                raw + 30.0
            )
        ),
        1
    )


# =============================================================================
# FACE PREDICTION
# =============================================================================

def _combined_predict(face_roi):

    global _lbph
    global _fisherfaces

    lbph_result = None
    fisher_result = None

    # -------------------------------------------------------------------------
    # Lazy load LBPH
    # -------------------------------------------------------------------------

    with _lock:
        lbph_model = _lbph

    if (
        lbph_model is None
        and os.path.exists(TRAINER)
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
                lbph_model = recognizer

            _models_ready["lbph"] = True

            print(
                "[face_server] LBPH lazy-loaded [OK]",
                flush=True
            )

        except Exception as e:

            print(
                f"[face_server] LBPH lazy-load failed: {e}",
                flush=True
            )

    # -------------------------------------------------------------------------
    # Lazy load Fisherfaces
    # -------------------------------------------------------------------------

    with _lock:
        fisher_model = _fisherfaces

    if (
        fisher_model is None
        and os.path.exists(FISHERFACES)
        and hasattr(cv2, "face")
        and hasattr(
            cv2.face,
            "FisherFaceRecognizer_create"
        )
    ):

        try:

            recognizer = (
                cv2.face.FisherFaceRecognizer_create()
            )

            recognizer.read(FISHERFACES)

            with _lock:
                _fisherfaces = recognizer
                fisher_model = recognizer

            _models_ready["fisherfaces"] = True

            print(
                "[face_server] Fisherfaces lazy-loaded [OK]",
                flush=True
            )

        except Exception as e:

            print(
                f"[face_server] Fisherfaces lazy-load failed: {e}",
                flush=True
            )

    # -------------------------------------------------------------------------
    # LBPH prediction
    # -------------------------------------------------------------------------

    if lbph_model is not None:

        try:

            student_id, distance = (
                lbph_model.predict(face_roi)
            )

            confidence = _lbph_confidence(
                distance
            )

            lbph_result = {
                "id": int(student_id),
                "confidence": confidence,
                "distance": float(distance),
                "matched": bool(
                    confidence >= 75.0
                )
            }

        except Exception as e:

            print(
                f"[face_server] LBPH prediction error: {e}",
                flush=True
            )

    # -------------------------------------------------------------------------
    # Fisherfaces prediction
    # -------------------------------------------------------------------------

    if fisher_model is not None:

        try:

            student_id, distance = (
                fisher_model.predict(face_roi)
            )

            confidence = _fisherfaces_confidence(
                distance
            )

            fisher_result = {
                "id": int(student_id),
                "confidence": confidence,
                "distance": float(distance),
                "matched": bool(
                    confidence >= 75.0
                )
            }

        except Exception as e:

            print(
                f"[face_server] Fisherfaces prediction error: {e}",
                flush=True
            )

    # -------------------------------------------------------------------------
    # Combine
    # -------------------------------------------------------------------------

    if (
        fisher_result is not None
        and fisher_result["matched"]
    ):

        result = fisher_result.copy()

        result["algorithm"] = "fisherfaces"

        result["lbph_confidence"] = (
            lbph_result["confidence"]
            if lbph_result
            else 0
        )

        result["fisherfaces_confidence"] = (
            fisher_result["confidence"]
        )

        if (
            lbph_result
            and lbph_result["matched"]
            and lbph_result["id"]
            == fisher_result["id"]
        ):

            result["confidence"] = min(
                99.9,
                fisher_result["confidence"] + 10.0
            )

            result["boosted"] = True

        else:

            result["boosted"] = False

        return result

    # -------------------------------------------------------------------------

    if lbph_result is not None:

        result = lbph_result.copy()

        result["algorithm"] = "lbph"

        result["lbph_confidence"] = (
            lbph_result["confidence"]
        )

        result["fisherfaces_confidence"] = (
            fisher_result["confidence"]
            if fisher_result
            else 0
        )

        if (
            fisher_result
            and fisher_result["matched"]
            and lbph_result["id"]
            != fisher_result["id"]
        ):

            result["confidence"] = max(
                0.0,
                result["confidence"] - 20.0
            )

            result["matched"] = (
                result["confidence"] >= 50.0
            )

            result["disagreement"] = True

        else:

            result["disagreement"] = False

        result["boosted"] = False

        return result

    # -------------------------------------------------------------------------

    return {
        "id": -1,
        "confidence": 0.0,
        "matched": False,
        "distance": 999,
        "algorithm": "none",
        "lbph_confidence": 0,
        "fisherfaces_confidence": 0
    }


# =============================================================================
# STATUS
# =============================================================================

@app.route("/status")
def status():

    return jsonify({
        "ok": True,
        "models": _models_ready,
        "loading": _models_ready.get(
            "loading",
            False
        )
    })


# =============================================================================
# FACE DETECTION
# =============================================================================

@app.route(
    "/detect",
    methods=["POST"]
)
def detect():

    try:

        data = request.get_json(
            force=True
        )

        image_base64 = data.get(
            "image",
            ""
        )

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

    gray = cv2.cvtColor(
        frame,
        cv2.COLOR_BGR2GRAY
    )

    gray = cv2.equalizeHist(
        gray
    )

    detections = (
        _cascade.detectMultiScale(
            gray,
            1.1,
            5,
            minSize=(40, 40)
        )
    )

    if len(detections) == 0:

        detections = (
            _cascade.detectMultiScale(
                gray,
                1.05,
                3,
                minSize=(30, 30)
            )
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

@app.route(
    "/recognize",
    methods=["POST"]
)
def recognize():

    result = {
        "faces_count": 0,
        "bbox": None,
        "lbph": {
            "error": "not run"
        }
    }

    try:

        data = request.get_json(
            force=True
        )

        image_base64 = data.get(
            "image",
            ""
        )

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
        })

    if frame is None:

        return jsonify({
            "error": "Empty frame"
        })

    if _cascade is None:

        return jsonify({
            "error": "Face detector is not ready"
        })

    # -------------------------------------------------------------------------
    # Detect face
    # -------------------------------------------------------------------------

    gray = cv2.cvtColor(
        frame,
        cv2.COLOR_BGR2GRAY
    )

    gray_eq = cv2.equalizeHist(
        gray
    )

    detections = (
        _cascade.detectMultiScale(
            gray_eq,
            1.1,
            5,
            minSize=(40, 40)
        )
    )

    if len(detections) == 0:

        detections = (
            _cascade.detectMultiScale(
                gray_eq,
                1.05,
                3,
                minSize=(30, 30)
            )
        )

    result["faces_count"] = int(
        len(detections)
    )

    if len(detections) == 0:

        result["lbph"] = {
            "id": -1,
            "confidence": 0.0,
            "matched": False,
            "message": "No face detected"
        }

        return jsonify(result)

    # -------------------------------------------------------------------------
    # Largest face
    # -------------------------------------------------------------------------

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

    face_roi = gray_eq[
        y:y + h,
        x:x + w
    ]

    face_roi = cv2.resize(
        face_roi,
        (100, 100)
    )

    # -------------------------------------------------------------------------
    # Recognition
    # -------------------------------------------------------------------------

    prediction = _combined_predict(
        face_roi
    )

    result["lbph"] = prediction

    return jsonify(result)


# =============================================================================
# SYNC FACE IMAGES FROM INFINITYFREE
# =============================================================================

@app.route(
    "/sync_faces",
    methods=["POST"]
)
def sync_faces():

    try:

        uploaded_files = request.files.getlist(
            "files"
        )

        if not uploaded_files:

            uploaded_files = request.files.getlist(
                "files[]"
            )

        if not uploaded_files:

            return jsonify({
                "success": False,
                "error": "No face images received"
            }), 400

        saved = []
        skipped = []

        for uploaded in uploaded_files:

            filename = uploaded.filename

            if not filename:

                continue

            filename = os.path.basename(
                filename
            )

            if not filename.lower().endswith(
                ".jpg"
            ):

                skipped.append(
                    filename
                )

                continue

            # Prevent unsafe filenames
            if "/" in filename or "\\" in filename:

                skipped.append(
                    filename
                )

                continue

            destination = os.path.join(
                FACES_DIR,
                filename
            )

            uploaded.save(
                destination
            )

            saved.append(
                filename
            )

        print(
            f"[face_server] Synced {len(saved)} face images.",
            flush=True
        )

        return jsonify({
            "success": True,
            "saved": len(saved),
            "skipped": len(skipped),
            "files": saved
        })

    except Exception as e:

        print(
            f"[face_server] Face sync error: {e}",
            flush=True
        )

        traceback.print_exc()

        return jsonify({
            "success": False,
            "error": str(e)
        }), 500


# =============================================================================
# TRAINING
# =============================================================================

def _write_training_status(
    state,
    message,
    progress=0,
    result=None
):

    os.makedirs(
        FACES_DIR,
        exist_ok=True
    )

    status = {
        "state": state,
        "message": message,
        "progress": progress,
        "timestamp": str(
            np.datetime64("now")
        )
    }

    if result is not None:

        status["result"] = result

    try:

        with open(
            STATUS_F,
            "w",
            encoding="utf-8"
        ) as f:

            json.dump(
                status,
                f,
                indent=2
            )

    except Exception as e:

        print(
            f"[face_server] Status write failed: {e}",
            flush=True
        )


def _do_train():

    global _lbph
    global _fisherfaces
    global _models_ready

    try:

        print(
            "[face_server] =================================",
            flush=True
        )

        print(
            "[face_server] Starting model training...",
            flush=True
        )

        print(
            "[face_server] =================================",
            flush=True
        )

        _write_training_status(
            "running",
            "Starting training...",
            5
        )

        # ---------------------------------------------------------------------
        # Count training images
        # ---------------------------------------------------------------------

        image_files = [
            f
            for f in os.listdir(FACES_DIR)
            if f.lower().endswith(".jpg")
        ]

        if not image_files:

            _write_training_status(
                "error",
                "No face images found on Render.",
                0
            )

            print(
                "[face_server] ERROR: No face images found.",
                flush=True
            )

            return

        print(
            f"[face_server] Found {len(image_files)} face images.",
            flush=True
        )

        # ---------------------------------------------------------------------
        # Run train_all_models.py
        # ---------------------------------------------------------------------

        train_script = os.path.join(
            PROJECT,
            "train_all_models.py"
        )

        process = subprocess.run(
            [
                sys.executable,
                train_script
            ],
            cwd=PROJECT,
            env=os.environ.copy(),
            capture_output=True,
            text=True
        )

        print(
            process.stdout,
            flush=True
        )

        if process.stderr:

            print(
                process.stderr,
                flush=True
            )

        # ---------------------------------------------------------------------
        # Check generated models
        # ---------------------------------------------------------------------

        lbph_ok = (
            os.path.exists(TRAINER)
        )

        fisher_ok = (
            os.path.exists(FISHERFACES)
        )

        # ---------------------------------------------------------------------
        # Reload LBPH
        # ---------------------------------------------------------------------

        if lbph_ok:

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

                print(
                    "[face_server] LBPH reloaded [OK]",
                    flush=True
                )

            except Exception as e:

                lbph_ok = False

                print(
                    f"[face_server] LBPH reload failed: {e}",
                    flush=True
                )

        # ---------------------------------------------------------------------
        # Reload Fisherfaces
        # ---------------------------------------------------------------------

        if fisher_ok:

            try:

                recognizer = (
                    cv2.face.FisherFaceRecognizer_create()
                )

                recognizer.read(
                    FISHERFACES
                )

                with _lock:
                    _fisherfaces = recognizer

                _models_ready["fisherfaces"] = True

                print(
                    "[face_server] Fisherfaces reloaded [OK]",
                    flush=True
                )

            except Exception as e:

                fisher_ok = False

                print(
                    f"[face_server] Fisherfaces reload failed: {e}",
                    flush=True
                )

        # ---------------------------------------------------------------------
        # Result
        # ---------------------------------------------------------------------

        result = {
            "lbph": {
                "ok": lbph_ok,
                "samples": len(image_files),
                "students": len(
                    set(
                        f.split("_")[0]
                        for f in image_files
                    )
                ) if image_files else 0
            },
            "fisherfaces": {
                "ok": fisher_ok,
                "samples": len(image_files),
                "students": len(
                    set(
                        f.split("_")[0]
                        for f in image_files
                    )
                ) if image_files else 0
            }
        }

        # ---------------------------------------------------------------------
        # Final status
        # ---------------------------------------------------------------------

        if lbph_ok and fisher_ok:

            _write_training_status(
                "done",
                "LBPH and Fisherfaces training completed.",
                100,
                result
            )

            print(
                "[face_server] TRAINING COMPLETED [OK]",
                flush=True
            )

        elif lbph_ok:

            _write_training_status(
                "done",
                "LBPH trained. Fisherfaces unavailable.",
                100,
                result
            )

            print(
                "[face_server] LBPH completed.",
                flush=True
            )

        else:

            _write_training_status(
                "error",
                "Training failed.",
                0,
                result
            )

            print(
                "[face_server] TRAINING FAILED.",
                flush=True
            )

    except Exception as e:

        print(
            f"[face_server] Training error: {e}",
            flush=True
        )

        traceback.print_exc()

        _write_training_status(
            "error",
            str(e),
            0
        )


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
        })

    _train_thread = threading.Thread(
        target=_do_train,
        daemon=True
    )

    _train_thread.start()

    return jsonify({
        "success": True,
        "message": "Training started"
    })


# =============================================================================
# TRAINING STATUS
# =============================================================================

@app.route("/train/status")
def train_status():

    try:

        if not os.path.exists(
            STATUS_F
        ):

            return jsonify({
                "state": "unknown",
                "message": "No training has been started."
            })

        with open(
            STATUS_F,
            "r",
            encoding="utf-8"
        ) as f:

            status = json.load(f)

        return jsonify(
            status
        )

    except Exception as e:

        return jsonify({
            "state": "error",
            "message": str(e)
        })


# =============================================================================
# RELOAD MODELS
# =============================================================================

@app.route(
    "/reload",
    methods=["GET", "POST"]
)
def reload_models():

    global _lbph
    global _fisherfaces

    loaded = {}

    # -------------------------------------------------------------------------
    # LBPH
    # -------------------------------------------------------------------------

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
        loaded["lbph_error"] = (
            "trainer.yml missing"
        )

    # -------------------------------------------------------------------------
    # Fisherfaces
    # -------------------------------------------------------------------------

    if (
        os.path.exists(FISHERFACES)
        and hasattr(cv2, "face")
        and hasattr(
            cv2.face,
            "FisherFaceRecognizer_create"
        )
    ):

        try:

            recognizer = (
                cv2.face.FisherFaceRecognizer_create()
            )

            recognizer.read(
                FISHERFACES
            )

            with _lock:
                _fisherfaces = recognizer

            _models_ready["fisherfaces"] = True

            loaded["fisherfaces"] = True

        except Exception as e:

            loaded["fisherfaces"] = False
            loaded["fisherfaces_error"] = str(e)

    else:

        loaded["fisherfaces"] = False
        loaded["fisherfaces_error"] = (
            "fisherfaces.yml missing"
        )

    return jsonify({
        "ok": True,
        "loaded": loaded,
        "models": _models_ready
    })


# =============================================================================
# START SERVER
# =============================================================================

if __name__ == "__main__":

    print(
        "[face_server] Starting CICS Face Recognition API...",
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