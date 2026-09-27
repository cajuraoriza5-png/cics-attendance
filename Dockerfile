FROM python:3.11-slim

WORKDIR /app

# System libraries required by OpenCV
RUN apt-get update && apt-get install -y \
    libglib2.0-0 \
    libgl1 \
    libgomp1 \
    && rm -rf /var/lib/apt/lists/*

# Copy requirements
COPY requirements.txt .

# Upgrade pip
RUN pip install --no-cache-dir --upgrade pip

# Install Python packages using prebuilt wheels
RUN pip install --no-cache-dir --only-binary=:all: -r requirements.txt

# Copy application
COPY face_server.py .
COPY train_all_models.py .

# Create faces directory
RUN mkdir -p faces

EXPOSE 5001

CMD ["gunicorn", "face_server:app", "--bind", "0.0.0.0:5001", "--workers", "1", "--timeout", "120"]
