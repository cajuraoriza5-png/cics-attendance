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
import re

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

# Training timing/progress state
_training_started_at = None
_training_lock = threading.Lock()


def _training_elapsed():
    """Return elapsed training time in seconds for the current training run."""
    with _training_lock:
        started = _training_started_at

    if started is None:
        return 0.0

    return round(
        max(0.0, time.time() - started),
        1
    )


def _write_training_progress(
    message,
    progress,
    result=None
):
    """Write a running training status update."""
    _write_training_status(
        "running",
        message,
        progress,
        result
    )


def _progress_heartbeat(stop_event):
    """
    Keep the UI progress moving while train_all_models.py is running.

    The percentage here is workflow progress, not an exact internal
    percentage from OpenCV. It will never reach 100% until the actual
    training subprocess finishes successfully.
    """

    progress = 20

    while not stop_event.wait(2.0):

        elapsed = _training_elapsed()

        if progress < 90:

            if elapsed < 20:
                step = 1

            elif elapsed < 60:
                step = 2

            else:
                step = 1

            progress = min(
                90,
                progress + step
            )

        _write_training_progress(
            f"Training models... {progress}% "
            f"(elapsed {elapsed:.1f}s)",
            progress
        )


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

        # If replace=1 is supplied, remove the existing Render face dataset
        # before saving the newly synchronized images. This prevents old/deleted
        # student face images from remaining on Render.
        replace_dataset = (
            str(request.form.get("replace", "0")).lower()
            in ("1", "true", "yes")
        )

        removed_old = 0
        removed_models = []

        if replace_dataset:
            # A replacement is a COMPLETE dataset reset.
            # Remove all enrolled face images and all models trained
            # from the previous dataset so old students cannot remain
            # recognizable after a new dataset is synchronized.
            for old_file in os.listdir(FACES_DIR):
                if not old_file.lower().endswith(".jpg"):
                    continue

                old_path = os.path.join(FACES_DIR, old_file)

                if os.path.isfile(old_path):
                    try:
                        os.remove(old_path)
                        removed_old += 1
                    except Exception as e:
                        print(
                            f"[face_server] Could not remove old face "
                            f"{old_file}: {e}",
                            flush=True
                        )

            # Remove old trained models. They must not survive a complete
            # face-dataset replacement.
            for model_path in (TRAINER, FISHERFACES):
                if os.path.isfile(model_path):
                    try:
                        os.remove(model_path)
                        removed_models.append(os.path.basename(model_path))
                    except Exception as e:
                        print(
                            f"[face_server] Could not remove old model "
                            f"{model_path}: {e}",
                            flush=True
                        )

            # Clear in-memory models too, otherwise the running Flask process
            # could continue using the old models until the service restarts.
            with _lock:
                _lbph = None
                _fisherfaces = None
                _models_ready["lbph"] = False
                _models_ready["fisherfaces"] = False

            print(
                f"[face_server] COMPLETE DATASET REPLACEMENT: "
                f"removed {removed_old} old JPG files and "
                f"{len(removed_models)} old model files.",
                flush=True
            )

        # Accept the normal Flask keys plus indexed multipart keys such as
        # files[0], files[1], ... . PHP/cURL uses the indexed form when
        # sending several CURLFile objects in one request.
        uploaded_files = []

        uploaded_files.extend(request.files.getlist("files"))
        uploaded_files.extend(request.files.getlist("files[]"))

        for key in request.files.keys():
            if key.startswith("files[") and key.endswith("]") and key not in ("files[]",):
                uploaded_files.extend(request.files.getlist(key))

        if not uploaded_files:

            if replace_dataset:
                return jsonify({
                    "success": True,
                    "message": "Render face dataset and old trained models were cleared.",
                    "saved": 0,
                    "skipped": 0,
                    "removed_old": removed_old,
                    "removed_models": removed_models,
                    "replaced_dataset": True,
                    "cleanup_only": True,
                    "files": []
                })

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
            "removed_old": removed_old,
            "removed_models": removed_models,
            "replaced_dataset": replace_dataset,
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
        "progress": int(progress),
        "elapsed_seconds": _training_elapsed(),
        "timestamp": str(
            np.datetime64("now")
        )
    }

    with _training_lock:

        if _training_started_at is not None:

            status["started_at_epoch"] = (
                _training_started_at
            )

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
    global _training_started_at

    heartbeat_stop = threading.Event()
    heartbeat_thread = None

    try:

        with _training_lock:
            _training_started_at = time.time()

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

        _write_training_progress(
            "Checking synchronized face images...",
            10
        )

        image_files = [
            f
            for f in os.listdir(FACES_DIR)
            if re.match(
                r"^\d+_\d+\.jpg$",
                f,
                re.IGNORECASE
            )
        ]

        image_files.sort(
            key=lambda name: [
                int(part) if part.isdigit() else part.lower()
                for part in re.split(
                    r"(\d+)",
                    name
                )
            ]
        )

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

        student_count = len({
            f.split("_")[0]
            for f in image_files
        })

        print(
            f"[face_server] Found {len(image_files)} face images "
            f"for {student_count} students.",
            flush=True
        )

        _write_training_progress(
            f"Found {len(image_files)} face images / "
            f"{student_count} students.",
            15
        )

        # ---------------------------------------------------------------------
        # Run train_all_models.py
        # ---------------------------------------------------------------------

        train_script = os.path.join(
            PROJECT,
            "train_all_models.py"
        )

        if not os.path.exists(train_script):

            raise FileNotFoundError(
                f"Training script not found: {train_script}"
            )

        _write_training_progress(
            "Starting LBPH and Fisherfaces training...",
            20
        )

        # The actual training script can take several minutes.
        # This heartbeat updates the status file every two seconds so the
        # dashboard has changing progress and elapsed time while training.
        heartbeat_thread = threading.Thread(
            target=_progress_heartbeat,
            args=(heartbeat_stop,),
            daemon=True
        )

        heartbeat_thread.start()

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

        heartbeat_stop.set()

        if heartbeat_thread is not None:

            heartbeat_thread.join(
                timeout=2
            )

            heartbeat_thread = None

        if process.stdout:

            print(
                process.stdout,
                flush=True
            )

        if process.stderr:

            print(
                process.stderr,
                flush=True
            )

        if process.returncode != 0:

            error_text = (
                process.stderr.strip()
                if (
                    process.stderr
                    and process.stderr.strip()
                )
                else (
                    process.stdout.strip()
                    if (
                        process.stdout
                        and process.stdout.strip()
                    )
                    else (
                        "Training script exited with "
                        f"code {process.returncode}."
                    )
                )
            )

            _write_training_status(
                "error",
                error_text,
                0
            )

            print(
                f"[face_server] TRAINING SCRIPT FAILED: "
                f"{error_text}",
                flush=True
            )

            return

        # ---------------------------------------------------------------------
        # Check generated models
        # ---------------------------------------------------------------------

        _write_training_progress(
            "Training finished. Checking generated models...",
            92
        )

        lbph_ok = os.path.exists(
            TRAINER
        )

        fisher_ok = os.path.exists(
            FISHERFACES
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

        _write_training_progress(
            "LBPH model checked. Checking Fisherfaces...",
            95
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
                "students": student_count
            },

            "fisherfaces": {
                "ok": fisher_ok,
                "samples": len(image_files),
                "students": student_count
            }
        }

        # ---------------------------------------------------------------------
        # Final status
        # ---------------------------------------------------------------------

        elapsed = _training_elapsed()

        if lbph_ok and fisher_ok:

            _write_training_status(
                "done",
                "LBPH and Fisherfaces training completed.",
                100,
                result
            )

            print(
                f"[face_server] TRAINING COMPLETED [OK] "
                f"in {elapsed:.1f}s",
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
                f"[face_server] LBPH completed "
                f"in {elapsed:.1f}s.",
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

    finally:

        heartbeat_stop.set()

        if heartbeat_thread is not None:

            heartbeat_thread.join(
                timeout=2
            )

        # Do not clear the timer until after the final status has been
        # written. This lets /train/status return the final elapsed time.
        #
        # The next training request will create a new timer.
        with _training_lock:

            _training_started_at = None


@app.route(
    "/train",
    methods=["POST"]
)
def train():

    global _train_thread
    global _training_started_at

    if (
        _train_thread
        and _train_thread.is_alive()
    ):

        return jsonify({
            "success": False,
            "message": "Training already in progress",
            "state": "running",
            "elapsed_seconds": _training_elapsed()
        }), 409

    with _training_lock:

        _training_started_at = time.time()

    _write_training_status(
        "running",
        "Training request accepted. Starting worker...",
        1
    )

    _train_thread = threading.Thread(
        target=_do_train,
        daemon=True
    )

    _train_thread.start()

    return jsonify({
        "success": True,
        "message": "Training started",
        "state": "running",
        "progress": 1,
        "elapsed_seconds": _training_elapsed()
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
                "message": "No training has been started.",
                "progress": 0,
                "elapsed_seconds": 0.0
            })

        with open(
            STATUS_F,
            "r",
            encoding="utf-8"
        ) as f:

            status = json.load(
                f
            )

        # During active training, calculate elapsed time from the live
        # in-memory timer instead of relying only on the last JSON write.
        if status.get("state") == "running":

            status["elapsed_seconds"] = (
                _training_elapsed()
            )

        return jsonify(
            status
        )

    except Exception as e:

        return jsonify({
            "state": "error",
            "message": str(e),
            "progress": 0,
            "elapsed_seconds": 0.0
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
