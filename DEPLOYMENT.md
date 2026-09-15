# Render Deployment Guide - LBPH + Fisherfaces

## Architecture

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

## Hybrid Algorithm

- **Primary**: LBPH (Local Binary Patterns Histograms) - Fast, lightweight
- **Helper**: Fisherfaces (Linear Discriminant Analysis) - Better class separation
- **Combined**: Both algorithms validate each other for improved accuracy

## Deployment Steps

### 1. Push to GitHub

```bash
git add .
git commit -m "Add Render deployment for LBPH+Fisherfaces"
git push origin main
```

### 2. Deploy to Render

1. Go to [render.com](https://render.com)
2. Click "New +" → "Blueprint"
3. Connect your GitHub repository
4. Render will read `render.yaml` and create services automatically

### 3. Services Created

Render will create:
- **cics-attendance-php**: PHP web service
- **cics-attendance-python**: Python face recognition service
- **cics-attendance-db**: PostgreSQL database

### 4. Update Python Service URL

After deployment, Render will assign a URL to your Python service (e.g., `https://cics-attendance-python.onrender.com`).

Update the `PYTHON_SERVICE_URL` in the PHP service environment variables to match the actual Python service URL.

### 5. Database Migration

If migrating from MySQL (InfinityFree) to PostgreSQL (Render):

1. Export your MySQL database from InfinityFree
2. Convert MySQL to PostgreSQL format
3. Import to Render PostgreSQL
4. Update any MySQL-specific queries to PostgreSQL syntax

### 6. File Storage

For persistent face data (`faces/` directory, `trainer.yml`, `fisherfaces.yml`):

**Option A: Render Disk (Paid)**
- Add disk to Python service
- Mount to `/opt/render/project/faces`

**Option B: Cloud Storage (Recommended for Free Tier)**
- Upload face images to S3 or Cloudinary
- Modify Python to load from URLs
- Store model files in database or object storage

**Option C: Re-train on Deploy**
- Face data can be re-enrolled after deployment
- Students re-enroll their faces on the live system

### 7. Test Deployment

1. Check PHP service: `https://cics-attendance-php.onrender.com`
2. Check Python service: `https://cics-attendance-python.onrender.com/status`
3. Expected status response:
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
4. Test face recognition endpoint
5. Verify database connectivity

## Environment Variables

Render automatically sets these from `render.yaml`:

**PHP Service:**
- `DATABASE_URL`: Auto-set from database
- `PYTHON_SERVICE_URL`: Python service URL
- `APP_ENV`: production

**Python Service:**
- `PYTHON_VERSION`: 3.11.0
- `PORT`: 5001

## Notes

- ✅ LBPH + Fisherfaces works on Render free tier (no dlib needed)
- ✅ Always-on deployment (no sleeping)
- ✅ Custom domain available on free tier
- ⚠️ Free tier has limited disk space (consider cloud storage for face data)
- ⚠️ Build time may be longer for OpenCV compilation

## Troubleshooting

**Build fails on OpenCV:**
- Ensure `opencv-contrib-python-headless` is in requirements.txt
- Render may take 5-10 minutes to compile OpenCV

**Python service not responding:**
- Check Render logs for errors
- Verify gunicorn is starting correctly
- Check port configuration

**Database connection issues:**
- Verify DATABASE_URL is set correctly
- Check PostgreSQL is running
- Test connection in Render dashboard
