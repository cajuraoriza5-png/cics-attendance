# Railway.app Deployment Guide (Recommended for Hybrid Algorithm)

## Why Railway.app?

Railway.app is **recommended** for your hybrid dlib + LBPH system because:
- ✅ Better build environment for Python packages (can compile dlib)
- ✅ Supports both PHP and Python services via Docker
- ✅ Built-in PostgreSQL database
- ✅ Volume support for persistent face data storage
- ✅ Free tier available (with limitations)

## Architecture

```
┌─────────────────┐     HTTP     ┌──────────────────┐
│  PHP Service    │◄────────────►│  Python Service  │
│  (Main App)    │              │  (Face Server)   │
│  Port 8080     │              │  Port 5001       │
└─────────────────┘              └──────────────────┘
         │                                 │
         └────────────┬────────────────────┘
                      │
              ┌───────▼────────┐
              │  PostgreSQL   │
              │  (Database)   │
              └────────────────┘
```

## Deployment Steps

### 1. Create Railway Account
- Go to [railway.app](https://railway.app)
- Sign up with GitHub
- Verify email

### 2. Create New Project
1. Click "New Project"
2. Click "Deploy from GitHub repo"
3. Select your repository
4. Click "Deploy"

### 3. Add Services Manually

Railway will auto-detect but we'll configure manually for better control:

#### Add PostgreSQL Database
1. In your project, click "New Service"
2. Select "Database" → "PostgreSQL"
3. Click "Add PostgreSQL"
4. Railway will create and set `DATABASE_URL` automatically

#### Add PHP Service
1. Click "New Service" → "Dockerfile"
2. Select root directory: `/`
3. Select Dockerfile: `Dockerfile.php`
4. Service name: `cics-attendance-php`
5. Click "Add Service"
6. Go to service settings → "Variables"
7. Add environment variables:
   - `DATABASE_URL`: `${{Postgres.DATABASE_URL}}` (Railway auto-resolves)
   - `PYTHON_SERVICE_URL`: `${{cics-attendance-python.RAILWAY_PUBLIC_DOMAIN}}`
   - `APP_ENV`: `production`

#### Add Python Service
1. Click "New Service" → "Dockerfile"
2. Select root directory: `/`
3. Select Dockerfile: `Dockerfile.python`
4. Service name: `cics-attendance-python`
5. Click "Add Service"
6. Go to service settings → "Variables"
7. Add environment variables:
   - `PORT`: `5001`

### 4. Add Persistent Volume for Face Data

1. Go to Python service (`cics-attendance-python`)
2. Click "Settings" → "Volumes"
3. Click "New Volume"
4. Configure:
   - **Volume Name**: `faces-data`
   - **Mount Path**: `/app/faces`
5. Click "Create Volume"
6. Restart the Python service

### 5. Set Service Dependencies

1. Go to PHP service settings
2. Click "Dependencies"
3. Add dependency: `cics-attendance-python`
4. This ensures PHP service starts after Python service

### 6. Migrate Database

Since you're moving from MySQL to PostgreSQL:

```bash
# Export from MySQL
mysqldump -u username -p database_name > backup.sql

# Convert to PostgreSQL (use pgloader or online converter)
# Or manually adjust SQL syntax

# Import to Railway PostgreSQL
psql $DATABASE_URL < backup.sql
```

### 7. Test Deployment

1. Check PHP service: Click on PHP service → "View Domain"
2. Check Python service: Click on Python service → "View Domain" + `/status`
3. Verify both algorithms are loaded:
   ```json
   {
     "ok": true,
     "models": {
       "lbph": true,
       "fr_helper": true
     }
   }
   ```

## Environment Variables Reference

### PHP Service Variables
| Variable | Value | Description |
|----------|-------|-------------|
| `DATABASE_URL` | `${{Postgres.DATABASE_URL}}` | PostgreSQL connection string (auto-resolved) |
| `PYTHON_SERVICE_URL` | `${{cics-attendance-python.RAILWAY_PUBLIC_DOMAIN}}` | Python service URL (auto-resolved) |
| `APP_ENV` | `production` | Application environment |

### Python Service Variables
| Variable | Value | Description |
|----------|-------|-------------|
| `PORT` | `5001` | Port for Flask server |

## Troubleshooting

### dlib Build Fails
If dlib fails to compile during Python service build:
1. This is normal for first build - Railway will retry
2. If persistent, check build logs for specific errors
3. Ensure Dockerfile.python has all required system dependencies

### Face Data Not Persisting
1. Verify volume is mounted at `/app/faces`
2. Check volume exists in Python service settings
3. Restart Python service after adding volume

### PHP Can't Connect to Python
1. Check `PYTHON_SERVICE_URL` environment variable
2. Verify Python service is running (check `/status` endpoint)
3. Ensure both services are in same Railway project

### Database Connection Issues
1. Verify `DATABASE_URL` is auto-resolved from PostgreSQL service
2. Check PostgreSQL service is running
3. Test connection via Railway's built-in database viewer

## File Structure After Deployment

```
/app/
├── Dockerfile.php          # PHP service Dockerfile
├── Dockerfile.python       # Python service Dockerfile
├── config.php              # Configuration file
├── *.php                   # All PHP files
├── face_server.py          # Python face server
├── requirements.txt        # Python dependencies
├── faces/                  # Persistent volume mount
│   ├── .gitkeep
│   └── (enrolled faces)
└── event_banners/          # Event banner images
```

## Cost Estimate (Free Tier)

Railway free tier includes:
- $5 credit/month (renews monthly)
- PostgreSQL: ~$5/month (may exceed free tier)
- PHP Service: ~$5/month (may exceed free tier)
- Python Service: ~$5/month (may exceed free tier)
- Volume: $0.25/GB/month

**Total**: May exceed free tier. Consider:
- Using Railway for development only
- Deploying to a VPS for production (cheaper)
- Optimizing services to reduce resource usage
