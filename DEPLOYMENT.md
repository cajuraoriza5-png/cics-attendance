# Deployment Guide

## Current Setup

- **PHP**: InfinityFree (hosting)
- **Python**: Local development (planned for Render)
- **Database**: MySQL (InfinityFree)

## Deployment to Render

### 1. Deploy Python Service to Render

1. Create account at [render.com](https://render.com)
2. Click "New +" → "Web Service"
3. Connect your GitHub repository
4. Configure:
   - Runtime: Python 3
   - Build Command: `pip install -r requirements.txt`
   - Start Command: `gunicorn face_server:app --bind 0.0.0.0:$PORT`
5. Deploy

### 2. Update PHP Files

Change all `http://127.0.0.1:5001` references to your Render Python service URL:
- `face_recognize_api.php`
- `face_train_multi.php`
- `face_recognition_scan.php`
- `check_server.php`
- `check_algorithm.php`
- `face_enroll.php`

### 3. Database Migration (Optional)

If moving from InfinityFree MySQL to Render PostgreSQL:
1. Export MySQL database
2. Convert to PostgreSQL
3. Import to Render
4. Update connection strings

### 4. File Storage

For persistent face data on Render:
- Use Render Disk (paid feature)
- Or use cloud storage (S3, Cloudinary)

## Notes

- Render free tier has build limitations
- dlib may not compile on Render free tier
- Consider using Railway.app if dlib compilation fails
