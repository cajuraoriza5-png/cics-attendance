"""
train_all_models.py
===============================================================================
PURPOSE : Train both LBPH and Fisherfaces models from enrolled face images.
          Called by face_server.py when training is triggered via /train endpoint.

ALGORITHMS:
  - LBPH (Local Binary Patterns Histograms) - Fast, lightweight
  - Fisherfaces (Linear Discriminant Analysis) - Better class separation

OUTPUT FILES:
  - trainer.yml - LBPH trained model
  - fisherfaces.yml - Fisherfaces trained model
===============================================================================
"""
import os, sys, json, cv2, numpy as np
from pathlib import Path

PROJECT = os.path.dirname(os.path.abspath(__file__))
FACES_DIR = os.path.join(PROJECT, 'faces')
TRAINER = os.path.join(PROJECT, 'trainer.yml')
FISHERFACES = os.path.join(PROJECT, 'fisherfaces.yml')
STATUS_F = os.path.join(FACES_DIR, '.train_status.json')

def update_status(state, message, progress=0):
    """Update training status file."""
    status = {
        'state': state,
        'message': message,
        'progress': progress,
        'timestamp': str(np.datetime64('now'))
    }
    try:
        with open(STATUS_F, 'w') as f:
            json.dump(status, f, indent=2)
    except Exception as e:
        print(f'[train] Failed to update status: {e}')

def load_training_data():
    """Load face images and labels from faces/ directory."""
    print('[train] Loading training data...', flush=True)
    update_status('loading', 'Loading face images...', 10)
    
    faces = []
    labels = []
    
    if not os.path.exists(FACES_DIR):
        print('[train] ERROR: faces/ directory not found!', flush=True)
        return None, None
    
    # Load all .jpg files from faces/ directory
    for filename in os.listdir(FACES_DIR):
        if not filename.endswith('.jpg'):
            continue
        
        # Extract user ID from filename (e.g., "19.jpg" -> ID 19)
        try:
            user_id = int(filename.replace('.jpg', ''))
        except ValueError:
            print(f'[train] Skipping invalid filename: {filename}', flush=True)
            continue
        
        filepath = os.path.join(FACES_DIR, filename)
        img = cv2.imread(filepath, cv2.IMREAD_GRAYSCALE)
        
        if img is None:
            print(f'[train] Failed to load: {filename}', flush=True)
            continue
        
        # Resize to standard size
        img = cv2.resize(img, (100, 100))
        
        faces.append(img)
        labels.append(user_id)
    
    if len(faces) == 0:
        print('[train] ERROR: No face images found!', flush=True)
        return None, None
    
    print(f'[train] Loaded {len(faces)} face images from {len(set(labels))} students', flush=True)
    return np.array(faces), np.array(labels)

def train_lbph(faces, labels):
    """Train LBPH model."""
    print('[train] Training LBPH model...', flush=True)
    update_status('training_lbph', 'Training LBPH model...', 40)
    
    if not (hasattr(cv2, 'face') and hasattr(cv2.face, 'LBPHFaceRecognizer_create')):
        print('[train] ERROR: opencv-contrib not installed!', flush=True)
        return False
    
    try:
        recognizer = cv2.face.LBPHFaceRecognizer_create(
            radius=1,
            neighbors=8,
            grid_x=8,
            grid_y=8
        )
        recognizer.train(faces, labels)
        recognizer.save(TRAINER)
        print(f'[train] LBPH model saved to {TRAINER}', flush=True)
        return True
    except Exception as e:
        print(f'[train] LBPH training failed: {e}', flush=True)
        return False

def train_fisherfaces(faces, labels):
    """Train Fisherfaces model."""
    print('[train] Training Fisherfaces model...', flush=True)
    update_status('training_fisherfaces', 'Training Fisherfaces model...', 70)
    
    if not (hasattr(cv2, 'face') and hasattr(cv2.face, 'FisherFaceRecognizer_create')):
        print('[train] ERROR: opencv-contrib not installed!', flush=True)
        return False
    
    try:
        recognizer = cv2.face.FisherFaceRecognizer_create()
        recognizer.train(faces, labels)
        recognizer.save(FISHERFACES)
        print(f'[train] Fisherfaces model saved to {FISHERFACES}', flush=True)
        return True
    except Exception as e:
        print(f'[train] Fisherfaces training failed: {e}', flush=True)
        return False

def main():
    """Main training function."""
    print('[train] ===== Starting Training =====', flush=True)
    update_status('starting', 'Initializing training...', 0)
    
    # Load data
    faces, labels = load_training_data()
    if faces is None or labels is None:
        update_status('error', 'No training data found', 0)
        return
    
    # Train LBPH
    lbph_success = train_lbph(faces, labels)
    
    # Train Fisherfaces
    fisher_success = train_fisherfaces(faces, labels)
    
    # Final status
    if lbph_success and fisher_success:
        update_status('completed', 'Training completed successfully!', 100)
        print('[train] ===== Training Completed Successfully =====', flush=True)
    elif lbph_success:
        update_status('partial', 'LBPH trained, Fisherfaces failed', 100)
        print('[train] ===== Partial Success (LBPH only) =====', flush=True)
    else:
        update_status('error', 'Training failed', 0)
        print('[train] ===== Training Failed =====', flush=True)

if __name__ == '__main__':
    main()
