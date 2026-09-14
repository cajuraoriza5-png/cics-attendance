# Deployment Guide

## ⚠️ Important: Hybrid Algorithm Support

Your system uses a **hybrid algorithm** combining:
- **LBPH** (OpenCV) - Primary, fast, lightweight
- **dlib/face_recognition** - Helper, validates and boosts LBPH accuracy

This hybrid approach is **already implemented** in `face_server.py` and works perfectly together. The challenge is deployment platform compatibility.

## Recommended Platform: Railway.app

**Use Railway.app** instead of Render because:
- ✅ Can compile dlib/face_recognition (better build environment)
- ✅ Supports both PHP and Python services
- ✅ Built-in PostgreSQL database
- ✅ Volume support for persistent face data storage
- ✅ Free tier available

**See `RAILWAY_DEPLOYMENT.md` for complete Railway deployment instructions.**

## Alternative: Render (Not Recommended for Hybrid)

Render's free tier **cannot compile dlib**. If you must use Render:
- Use Render paid tier (better build environment)
- Pre-compile dlib locally and upload as wheel
- Accept LBPH-only mode (loses hybrid benefits)

## Architecture (Both Platforms)

```
┌─────────────────┐     HTTP     ┌──────────────────┐
│  PHP Service    │◄────────────►│  Python Service  │
│  (Main App)    │              │  (Face Server)   │
│  Port 10000    │              │  Port 5001       │
└─────────────────┘              └──────────────────┘
         │                                 │
         └────────────┬────────────────────┘
                      │
              ┌───────▼────────┐
              │  PostgreSQL   │
              │  (Database)   │
              └────────────────┘
```

## File Storage Requirements

Both platforms need persistent storage for:
- `faces/` directory (enrolled face images)
- `trainer.yml` (LBPH trained model)
- `face_encodings.pkl` (dlib face encodings)

**Railway**: Use Volumes feature
**Render**: Use Render Disk (paid) or cloud storage

## Deployment Steps

### 1. Prepare Repository
```bash
# Copy environment template
cp .env.example .env

# Edit .env with your local values for testing
# On Render, these will be set via environment variables
```

### 2. Push to GitHub
```bash
git add .
git commit -m "Add Render deployment configuration"
git push origin main
```

### 3. Deploy on Render
1. Go to [render.com](https://render.com)
2. Click "New +" → "Blueprint"
3. Connect your GitHub repository
4. Render will read `render.yaml` and create services automatically

### 4. Set Environment Variables
In Render dashboard, set these for the PHP service:
- `DATABASE_URL` (auto-set from database)
- `PYTHON_SERVICE_URL`: Your Python service URL (e.g., `https://cics-attendance-python.onrender.com`)
- `APP_ENV`: `production`

### 5. Migrate Database
Since you're moving from MySQL to PostgreSQL:
1. Export your MySQL database
2. Convert to PostgreSQL format
3. Import to Render PostgreSQL
4. Update any MySQL-specific queries to PostgreSQL syntax

### 6. Handle Face Data
For the `faces/` directory and model files:
- **Option A**: Use Render Disk (paid)
  - Add disk to Python service
  - Mount to `/opt/render/project/faces`
  
- **Option B**: Use cloud storage
  - Upload face images to S3/Cloudinary
  - Modify Python to load from URLs
  - Store model in database or object storage

## Testing After Deployment

1. Check PHP service: `https://cics-attendance-php.onrender.com`
2. Check Python service: `https://cics-attendance-python.onrender.com/status`
3. Test face recognition endpoint
4. Verify database connectivity

## Alternative: Railway.app
Railway supports both PHP and Python better than Render:
- Better build environment for Python packages
- Easier to run multiple services
- Built-in PostgreSQL
- Consider migrating to Railway if Render doesn't work well
