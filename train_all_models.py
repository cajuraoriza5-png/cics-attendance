"""
train_all_models.py
===============================================================================
PURPOSE : Train both LBPH and Fisherfaces models from enrolled face images.
          Called by face_server.py when training is triggered via /train endpoint.

ALGORITHMS:
  - LBPH (Local Binary Patterns Histograms) - Fast, lightweight
  - Fisherfaces (Linear Discriminant Analysis) - Class separation

OUTPUT FILES:
  - trainer.yml - LBPH trained model
  - fisherfaces.yml - Fisherfaces trained model
===============================================================================
"""

import os
import sys
import json
import cv2
import numpy as np

PROJECT = os.path.dirname(os.path.abspath(__file__))

FACES_DIR = os.path.join(PROJECT, "faces")
TRAINER = os.path.join(PROJECT, "trainer.yml")
FISHERFACES = os.path.join(PROJECT, "fisherfaces.yml")
STATUS_F = os.path.join(FACES_DIR, ".train_status.json")


# =============================================================================
# UPDATE TRAINING STATUS
# =============================================================================

def update_status(state, message, progress=0, result=None):
    """Update training status file."""

    status = {
        "state": state,
        "message": message,
        "progress": progress,
        "timestamp": str(np.datetime64("now"))
    }

    if result is not None:
        status["result"] = result

    try:
        os.makedirs(FACES_DIR, exist_ok=True)

        with open(STATUS_F, "w", encoding="utf-8") as f:
            json.dump(status, f, indent=2)

    except Exception as e:
        print(
            f"[train] Failed to update status: {e}",
            flush=True
        )


# =============================================================================
# LOAD TRAINING DATA
# =============================================================================

def load_training_data():
    """Load enrolled face images and labels from faces/ directory."""

    print(
        "[train] Loading training data...",
        flush=True
    )

    update_status(
        "loading",
        "Loading face images...",
        10
    )

    faces = []
    labels = []

    # Check faces directory
    if not os.path.exists(FACES_DIR):

        print(
            "[train] ERROR: faces/ directory not found!",
            flush=True
        )

        return None, None

    # -------------------------------------------------------------------------
    # Read all JPG files
    # -------------------------------------------------------------------------

    filenames = sorted(os.listdir(FACES_DIR))

    for filename in filenames:

        # Only process JPG files
        if not filename.lower().endswith(".jpg"):
            continue

        # ---------------------------------------------------------------------
        # Extract student/user ID
        #
        # Examples:
        # 2_0.jpg  -> 2
        # 2_1.jpg  -> 2
        # 2_2.jpg  -> 2
        # 5_0.jpg  -> 5
        # ---------------------------------------------------------------------

        try:

            user_id = int(
                filename.split("_")[0]
            )

        except (ValueError, IndexError):

            print(
                f"[train] Skipping invalid filename: {filename}",
                flush=True
            )

            continue

        # ---------------------------------------------------------------------
        # Build image path
        # ---------------------------------------------------------------------

        filepath = os.path.join(
            FACES_DIR,
            filename
        )

        # ---------------------------------------------------------------------
        # Load image as grayscale
        # ---------------------------------------------------------------------

        img = cv2.imread(
            filepath,
            cv2.IMREAD_GRAYSCALE
        )

        if img is None:

            print(
                f"[train] Failed to load: {filename}",
                flush=True
            )

            continue

        # ---------------------------------------------------------------------
        # Resize image
        # ---------------------------------------------------------------------

        try:

            img = cv2.resize(
                img,
                (100, 100)
            )

        except Exception as e:

            print(
                f"[train] Failed to resize {filename}: {e}",
                flush=True
            )

            continue

        # ---------------------------------------------------------------------
        # Add image and label
        # ---------------------------------------------------------------------

        faces.append(img)
        labels.append(user_id)

    # -------------------------------------------------------------------------
    # Check if any images were loaded
    # -------------------------------------------------------------------------

    if len(faces) == 0:

        print(
            "[train] ERROR: No face images found!",
            flush=True
        )

        return None, None

    print(
        f"[train] Loaded {len(faces)} face images "
        f"from {len(set(labels))} students",
        flush=True
    )

    return (
        np.array(faces),
        np.array(labels, dtype=np.int32)
    )


# =============================================================================
# TRAIN LBPH
# =============================================================================

def train_lbph(faces, labels):
    """Train the LBPH face recognition model."""

    print(
        "[train] Training LBPH model...",
        flush=True
    )

    update_status(
        "training_lbph",
        "Training LBPH model...",
        40
    )

    # Check OpenCV contrib
    if not hasattr(cv2, "face"):

        print(
            "[train] ERROR: cv2.face is not available.",
            flush=True
        )

        print(
            "[train] Install opencv-contrib-python.",
            flush=True
        )

        return False

    if not hasattr(
        cv2.face,
        "LBPHFaceRecognizer_create"
    ):

        print(
            "[train] ERROR: LBPHFaceRecognizer_create is not available.",
            flush=True
        )

        return False

    try:

        recognizer = cv2.face.LBPHFaceRecognizer_create(
            radius=1,
            neighbors=8,
            grid_x=8,
            grid_y=8
        )

        recognizer.train(
            faces,
            labels
        )

        recognizer.save(
            TRAINER
        )

        print(
            f"[train] LBPH model saved to: {TRAINER}",
            flush=True
        )

        return True

    except Exception as e:

        print(
            f"[train] LBPH training failed: {e}",
            flush=True
        )

        return False


# =============================================================================
# TRAIN FISHERFACES
# =============================================================================

def train_fisherfaces(faces, labels):
    """Train the Fisherfaces face recognition model."""

    print(
        "[train] Training Fisherfaces model...",
        flush=True
    )

    update_status(
        "training_fisherfaces",
        "Training Fisherfaces model...",
        70
    )

    # Check OpenCV contrib
    if not hasattr(cv2, "face"):

        print(
            "[train] ERROR: cv2.face is not available.",
            flush=True
        )

        return False

    if not hasattr(
        cv2.face,
        "FisherFaceRecognizer_create"
    ):

        print(
            "[train] ERROR: FisherFaceRecognizer_create is not available.",
            flush=True
        )

        return False

    try:

        # Fisherfaces requires multiple classes.
        unique_labels = np.unique(labels)

        if len(unique_labels) < 2:

            print(
                "[train] Fisherfaces requires at least "
                "2 different students.",
                flush=True
            )

            return False

        recognizer = cv2.face.FisherFaceRecognizer_create()

        recognizer.train(
            faces,
            labels
        )

        recognizer.save(
            FISHERFACES
        )

        print(
            f"[train] Fisherfaces model saved to: {FISHERFACES}",
            flush=True
        )

        return True

    except Exception as e:

        print(
            f"[train] Fisherfaces training failed: {e}",
            flush=True
        )

        return False


# =============================================================================
# MAIN TRAINING FUNCTION
# =============================================================================

def main():

    print(
        "[train] =======================================",
        flush=True
    )

    print(
        "[train] Starting face model training",
        flush=True
    )

    print(
        "[train] =======================================",
        flush=True
    )

    update_status(
        "starting",
        "Initializing training...",
        0
    )

    # -------------------------------------------------------------------------
    # Load images
    # -------------------------------------------------------------------------

    faces, labels = load_training_data()

    if faces is None or labels is None:

        update_status(
            "error",
            "No training data found.",
            0
        )

        print(
            "[train] Training stopped: no training data.",
            flush=True
        )

        return

    # -------------------------------------------------------------------------
    # Display information
    # -------------------------------------------------------------------------

    print(
        f"[train] Total images: {len(faces)}",
        flush=True
    )

    print(
        f"[train] Total students: {len(np.unique(labels))}",
        flush=True
    )

    print(
        f"[train] Student IDs: {sorted(np.unique(labels).tolist())}",
        flush=True
    )

    # -------------------------------------------------------------------------
    # Train LBPH
    # -------------------------------------------------------------------------

    lbph_success = train_lbph(
        faces,
        labels
    )

    # -------------------------------------------------------------------------
    # Train Fisherfaces
    # -------------------------------------------------------------------------

    fisher_success = train_fisherfaces(
        faces,
        labels
    )

    # -------------------------------------------------------------------------
    # Final status
    # -------------------------------------------------------------------------

    result = {
        "lbph": {
            "ok": lbph_success,
            "samples": len(faces),
            "students": len(np.unique(labels)),
            "error": None if lbph_success else "LBPH training failed"
        },
        "fisherfaces": {
            "ok": fisher_success,
            "samples": len(faces),
            "students": len(np.unique(labels)),
            "error": None if fisher_success else "Fisherfaces training failed"
        }
    }

    if lbph_success and fisher_success:

        update_status(
            "done",
            "LBPH and Fisherfaces training completed.",
            100,
            result
        )

        print(
            "[train] =======================================",
            flush=True
        )

        print(
            "[train] TRAINING COMPLETED SUCCESSFULLY",
            flush=True
        )

        print(
            f"[train] LBPH: {len(faces)} samples / {len(np.unique(labels))} students",
            flush=True
        )

        print(
            f"[train] Fisherfaces: {len(faces)} samples / {len(np.unique(labels))} students",
            flush=True
        )

        print(
            "[train] =======================================",
            flush=True
        )

    elif lbph_success:

        update_status(
            "done",
            "LBPH trained, Fisherfaces failed.",
            100,
            result
        )

        print(
            "[train] LBPH trained successfully.",
            flush=True
        )

        print(
            "[train] Fisherfaces training failed.",
            flush=True
        )

    elif fisher_success:

        update_status(
            "done",
            "Fisherfaces trained, LBPH failed.",
            100,
            result
        )

        print(
            "[train] Fisherfaces trained successfully.",
            flush=True
        )

        print(
            "[train] LBPH training failed.",
            flush=True
        )

    else:

        update_status(
            "error",
            "Both LBPH and Fisherfaces training failed.",
            0,
            result
        )

        print(
            "[train] =======================================",
            flush=True
        )

        print(
            "[train] TRAINING FAILED",
            flush=True
        )

        print(
            "[train] =======================================",
            flush=True
        )


# =============================================================================
# RUN
# =============================================================================

if __name__ == "__main__":
    main()
