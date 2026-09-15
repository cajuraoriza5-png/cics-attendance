# Replit Deployment Guide - Free Hosting

## Why Replit?

- ✅ **Completely free** - no subscription or payment required
- ✅ Can compile dlib (supports your hybrid algorithm)
- ✅ Supports both PHP and Python
- ✅ Built-in PostgreSQL database
- ✅ Easy to use, no complex setup

## Limitations (Free Tier)

- ❌ Repls sleep after 15-30 minutes of inactivity
- ❌ No custom domain on free tier
- ❌ Limited resources (CPU/RAM)
- ❌ Not suitable for production deployment

**Best for:** Development, testing, demo, proof-of-concept

## Deployment Steps

### 1. Create Replit Account

1. Go to [replit.com](https://replit.com)
2. Click "Sign up"
3. Sign up with GitHub, Google, or email
4. Verify your email

### 2. Create PHP Repl

1. Click "+ Create Repl"
2. Search for "PHP"
3. Select "PHP" template
4. Name it: `cics-attendance`
5. Click "Create Repl"

### 3. Upload Your Files

**Option A: Drag and Drop**
1. Open your local folder: `c:\Users\840G3\OneDrive\Desktop\cics-attendance`
2. Select all files (Ctrl+A)
3. Drag them into the Replit file explorer

**Option B: Upload via Replit**
1. In Replit file explorer, click "Upload File"
2. Upload all files from your local folder
3. Create folders as needed (faces/, event_banners/, etc.)

### 4. Configure Environment Variables

1. In Replit, click the "Secrets" (key icon) in the left sidebar
2. Add these environment variables:

```
DATABASE_HOST=localhost
DATABASE_NAME=replitdb
DATABASE_USER=replit
DATABASE_PASSWORD=
DATABASE_PORT=5432
PYTHON_SERVICE_URL=http://127.0.0.1:5001
APP_ENV=development
```

### 5. Set Up PostgreSQL Database

Replit has built-in PostgreSQL:

1. In Replit, click "Database" icon in left sidebar
2. Click "Create Database"
3. Select "PostgreSQL"
4. Replit will create and connect the database automatically
5. Update your `DATABASE_URL` in Secrets if needed

### 6. Install Python Dependencies

1. Open the Replit Shell (bottom panel)
2. Run these commands:

```bash
pip install numpy>=1.24,<2.0
pip install opencv-contrib-python-headless
pip install setuptools<81
pip install dlib
pip install face_recognition
pip install face_recognition_models
pip install Pillow
pip install flask
pip install flask-cors
pip install gunicorn
```

**Note:** dlib compilation may take 5-10 minutes. Be patient.

### 7. Start Python Face Server

1. In Replit Shell, run:

```bash
python face_server.py
```

2. The server will start on port 5001
3. Keep this shell running (don't close it)

### 8. Start PHP Web Server

1. Open a new Shell tab in Replit (click "+" next to Shell)
2. Run:

```bash
php -S 0.0.0.0:8080
```

3. The PHP server will start on port 8080
4. Replit will show a "Webview" button - click it to open your app

### 9. Test Your System

1. Open the Webview (your PHP app should load)
2. Try logging in as admin
3. Test face enrollment
4. Test face recognition
5. Verify both LBPH and dlib are working

## Architecture on Replit

```
┌─────────────────┐
│  Replit Repl    │
│                 │
│  PHP Server    │ ◄── Port 8080
│  (Web App)     │
│                 │
│  Python Server │ ◄── Port 5001
│  (Face API)    │
│                 │
│  PostgreSQL    │ ◄── Built-in DB
└─────────────────┘
```

## Important Notes

### Keeping Servers Running

Replit will sleep after inactivity. To keep it awake:

1. **For development**: Just interact with the Repl regularly
2. **For demo**: Use Replit's "Always On" feature (requires paid plan)
3. **Alternative**: Use a keep-alive script (not recommended for long-term)

### File Persistence

- All files in your Repl are saved automatically
- Database data persists
- Face images in `faces/` directory persist

### Face Data Storage

- Your `faces/` directory will work as-is
- `trainer.yml` and `face_encodings.pkl` will be saved
- No special configuration needed

## Troubleshooting

### dlib Compilation Fails

If dlib fails to compile:

1. Make sure you have enough time (5-10 minutes)
2. Check shell for error messages
3. Try installing system dependencies first:
   ```bash
   sudo apt-get update
   sudo apt-get install -y cmake g++ libopenblas-dev libx11-dev
   ```
4. Then retry: `pip install dlib`

### PHP Server Not Starting

1. Check if port 8080 is already in use
2. Try a different port: `php -S 0.0.0.0:3000`
3. Update your browser URL accordingly

### Python Server Not Starting

1. Check if port 5001 is already in use
2. Check for import errors in shell
3. Verify all dependencies are installed

### Database Connection Issues

1. Verify PostgreSQL is created in Replit
2. Check Secrets are set correctly
3. Test connection in Shell:
   ```bash
   psql $DATABASE_URL
   ```

## Next Steps After Replit

Once your system works on Replit:

1. **For production**: Consider a VPS (DigitalOcean $4-6/month)
2. **For demo**: Replit is perfect
3. **For development**: Keep using Replit

## Cost Comparison

| Platform | Cost | dlib Support | Production Ready |
|----------|------|--------------|------------------|
| Replit | Free | ✅ Yes | ❌ No (sleeps) |
| Railway | $15+/month | ✅ Yes | ✅ Yes |
| DigitalOcean VPS | $4-6/month | ✅ Yes | ✅ Yes |
| Render | Free tier | ❌ No | ❌ No (no dlib) |

## Summary

**Replit is perfect for:**
- Testing your hybrid dlib + LBPH algorithm
- Development and debugging
- Demoing your system
- Proof of concept

**Not suitable for:**
- Production deployment (sleeps, no custom domain)
- High-traffic applications

**Your system will work perfectly on Replit** for development and testing purposes.
