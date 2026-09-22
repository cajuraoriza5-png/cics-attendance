# Complete Training Guide - LBPH + Fisherfaces Hybrid System

## Overview

This guide covers the complete training process for your hybrid face recognition system using LBPH and Fisherfaces algorithms for thesis evaluation.

## System Architecture

```
┌─────────────────┐     HTTP     ┌──────────────────┐
│  PHP Service    │◄────────────►│  Python Service  │
│  (InfinityFree) │              │  (Render)        │
└─────────────────┘              └──────────────────┘
         │                                 │
         └────────────┬────────────────────┘
                      │
              ┌───────▼────────┐
              │  PostgreSQL   │
              │  (Render)      │
              └────────────────┘
```

## Prerequisites

### 1. Database Setup
- Students must be enrolled in the `users` table
- Each student must have a valid `id` (primary key)

### 2. Face Images
- Face images must be stored in `faces/` directory
- Naming format: `{user_id}_{index}.jpg`
  - Example: `2_0.jpg`, `2_1.jpg`, `2_2.jpg` (for user ID 2)
  - Example: `5_0.jpg`, `5_1.jpg` (for user ID 5)
- Minimum 2 different students required for Fisherfaces
- Recommended 10-20 images per student

### 3. Python Dependencies
```bash
pip install numpy>=1.24,<2.0
pip install opencv-contrib-python-headless
pip install setuptools<81
pip install Pillow
pip install flask
pip install flask-cors
pip install gunicorn
```

## Step-by-Step Training Process

### Step 1: Enroll Students

1. Go to your Admin Dashboard
2. Click "Add Student"
3. Fill in student details (first name, last name, student_id)
4. Save the student
5. Note the `id` assigned to the student

### Step 2: Capture Face Images

1. Go to "Face Enrollment" page
2. Select the student from the dropdown
3. Allow camera access
4. Click "Capture Face" multiple times (10-20 times recommended)
5. Each capture saves an image as `{user_id}_{index}.jpg`
6. Verify images are saved in `faces/` directory

### Step 3: Verify Face Images

**Check physical files:**
```bash
cd c:\Users\840G3\OneDrive\Desktop\cics-attendance
dir faces\*.jpg
```

**Check database records:**
```sql
SELECT 
    COUNT(*) AS total_records,
    COUNT(DISTINCT face_image) AS unique_filenames,
    COUNT(DISTINCT user_id) AS unique_students
FROM face_data;
```

Expected result:
- `total_records` should equal `unique_filenames`
- `unique_students` should be ≥ 2 (for Fisherfaces)

### Step 4: Train Models (Local Development)

**Option A: Via Admin Dashboard**
1. Go to Admin Dashboard
2. Click "Re-train Models"
3. Wait for training to complete
4. Check training result

**Option B: Via Command Line**
```bash
cd c:\Users\840G3\OneDrive\Desktop\cics-attendance
python train_all_models.py
```

**Option C: Via Python Server**
```bash
# Start face server
python face_server.py

# In another terminal, trigger training
curl -X POST http://127.0.0.1:5001/train
```

### Step 5: Verify Training Results

**Check training status:**
```bash
type faces\.train_status.json
```

Expected output:
```json
{
  "state": "done",
  "message": "LBPH and Fisherfaces training completed.",
  "progress": 100,
  "result": {
    "lbph": {
      "ok": true,
      "samples": 184,
      "students": 7,
      "error": null
    },
    "fisherfaces": {
      "ok": true,
      "samples": 184,
      "students": 7,
      "error": null
    }
  }
}
```

**Check model files:**
```bash
dir trainer.yml fisherfaces.yml
```

### Step 6: Test Recognition

**Option A: Via Scanner**
1. Go to "Start Face Attendance"
2. Allow camera access
3. Position face in camera
4. Check recognition result

**Option B: Via API**
```bash
# Test with a face image
curl -X POST http://127.0.0.1:5001/recognize \
  -H "Content-Type: application/json" \
  -d "{\"image\": \"$(base64 -w 0 faces/2_0.jpg)\"}"
```

Expected response:
```json
{
  "faces_count": 1,
  "bbox": {"x": 100, "y": 50, "w": 150, "h": 150},
  "lbph": {
    "id": 2,
    "confidence": 85.5,
    "distance": 22.5,
    "matched": true,
    "algorithm": "lbph",
    "name": "Student Name",
    "student_id": "2023-0001"
  },
  "fisherfaces": {
    "id": 2,
    "confidence": 92.3,
    "distance": 450.2,
    "matched": true,
    "algorithm": "fisherfaces",
    "name": "Student Name",
    "student_id": "2023-0001"
  },
  "hybrid": {
    "id": 2,
    "confidence": 99.9,
    "distance": 450.2,
    "matched": true,
    "algorithm": "hybrid",
    "lbph_confidence": 85.5,
    "fisherfaces_confidence": 92.3,
    "boosted": true,
    "name": "Student Name",
    "student_id": "2023-0001"
  }
}
```

## Step 7: Deploy to Render

### 7.1 Push to GitHub
```bash
git add .
git commit -m "Update face recognition models"
git push origin main
```

### 7.2 Sync Face Images to Render

Your PHP system automatically syncs face images to Render when you click "Re-train Models".

### 7.3 Train on Render

1. Go to Admin Dashboard
2. Click "Re-train Models"
3. The system will:
   - Clear old Render face dataset
   - Upload current face images
   - Train LBPH model
   - Train Fisherfaces model
4. Wait for completion

### 7.4 Verify Render Training

Check Render training status:
```
https://cics-attendance.onrender.com/train/status
```

Expected output:
```json
{
  "state": "done",
  "message": "LBPH and Fisherfaces training completed.",
  "progress": 100,
  "result": {
    "lbph": {
      "ok": true,
      "samples": 184,
      "students": 7
    },
    "fisherfaces": {
      "ok": true,
      "samples": 184,
      "students": 7
    }
  }
}
```

### 7.5 Verify Render Models

Check Render model status:
```
https://cics-attendance.onrender.com/status
```

Expected output:
```json
{
  "ok": true,
  "models": {
    "lbph": true,
    "fisherfaces": true,
    "loading": false
  }
}
```

## Troubleshooting

### Issue: "No face detected"

**Possible causes:**
1. Haar Cascade not loaded
2. Poor lighting
3. Face too far/close to camera
4. Face not centered

**Solutions:**
1. Check face server logs: `faces\.server_log.txt`
2. Improve lighting
3. Move closer/further from camera
4. Center face in frame

### Issue: "LBPH not available"

**Possible causes:**
1. `trainer.yml` not found
2. OpenCV contrib not installed
3. Training not completed

**Solutions:**
1. Run training: `python train_all_models.py`
2. Install opencv-contrib: `pip install opencv-contrib-python-headless`
3. Check `trainer.yml` exists

### Issue: "Fisherfaces not available"

**Possible causes:**
1. `fisherfaces.yml` not found
2. Less than 2 students enrolled
3. Training not completed

**Solutions:**
1. Run training: `python train_all_models.py`
2. Enroll at least 2 different students
3. Check `fisherfaces.yml` exists

### Issue: Only LBPH showing

**Possible causes:**
1. Old code running
2. Fisherfaces model not loaded
3. PHP file not updated

**Solutions:**
1. Restart face server
2. Clear browser cache
3. Verify `face_server.py` has updated code
4. Push latest code to Render

### Issue: Training fails

**Possible causes:**
1. No face images in `faces/` directory
2. Invalid image filenames
3. Corrupted image files

**Solutions:**
1. Check `faces/` directory has JPG files
2. Verify filenames match `{user_id}_{index}.jpg` format
3. Remove corrupted images
4. Re-enroll students

## Thesis Evaluation

### Algorithm Comparison Table

For your thesis, you should collect the following metrics:

| Algorithm | Accuracy | Precision | Recall | F1-score | Avg. Response Time |
|-----------|----------|-----------|--------|----------|-------------------|
| LBPH      |          |           |        |          |                   |
| Fisherfaces |        |           |        |          |                   |
| Hybrid    |          |           |        |          |                   |

### How to Collect Metrics

1. **Create a test dataset** (separate from training)
2. **Test each algorithm individually** using the recognition API
3. **Record results** for each test image
4. **Calculate metrics**:
   - Accuracy = (Correct predictions) / (Total predictions)
   - Precision = TP / (TP + FP)
   - Recall = TP / (TP + FN)
   - F1-score = 2 × (Precision × Recall) / (Precision + Recall)

### Hybrid Fusion Rules

Your hybrid system uses these rules:

1. **Both agree** (same student): Boost confidence +10%
2. **Fisherfaces matches only**: Use Fisherfaces result
3. **LBPH matches only**: Use LBPH result
4. **Neither matches**: No match

## Files Checklist

Ensure these files are present and correct:

### Python Files
- ✅ `face_server.py` - Flask face recognition server
- ✅ `train_all_models.py` - Training script
- ✅ `requirements.txt` - Python dependencies

### PHP Files
- ✅ `config.php` - Configuration (uses Render URL)
- ✅ `face_recognize_api.php` - Recognition API proxy
- ✅ `face_train_multi.php` - Training trigger
- ✅ `face_recognition_scan.php` - Scanner interface
- ✅ `face_enroll.php` - Enrollment interface
- ✅ `db.php` - Database connection

### Model Files (after training)
- ✅ `trainer.yml` - LBPH trained model
- ✅ `fisherfaces.yml` - Fisherfaces trained model

### Data Files
- ✅ `faces/` directory - Face images
- ✅ `faces/.train_status.json` - Training status

## Quick Reference

### Start Local Face Server
```bash
cd c:\Users\840G3\OneDrive\Desktop\cics-attendance
python face_server.py
```

### Train Models Locally
```bash
cd c:\Users\840G3\OneDrive\Desktop\cics-attendance
python train_all_models.py
```

### Check Training Status
```bash
type faces\.train_status.json
```

### Check Server Status
```bash
curl http://127.0.0.1:5001/status
```

### Test Recognition
```bash
curl -X POST http://127.0.0.1:5001/recognize \
  -H "Content-Type: application/json" \
  -d "{\"image\": \"$(base64 -w 0 faces/2_0.jpg)\"}"
```

## Support

If you encounter issues:

1. Check `faces/.server_log.txt` for server errors
2. Check browser console for JavaScript errors
3. Verify all files are present
4. Ensure Render deployment is successful
5. Check this guide for troubleshooting steps
