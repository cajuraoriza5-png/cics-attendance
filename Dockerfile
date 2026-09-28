FROM python:3.11-slim

WORKDIR /app

# System dependencies
RUN apt-get update && apt-get install -y \
    build-essential \
    cmake \
    g++ \
    libglib2.0-0 \
    libgl1 \
    libgomp1 \
    libopenblas-dev \
    && rm -rf /var/lib/apt/lists/*

# Copy requirements
COPY requirements.txt .

# Upgrade pip
RUN pip install --no-cache-dir --upgrade pip

# Install controlled versions first
RUN pip install --no-cache-dir \
    numpy==1.26.4 \
    onnx==1.23.0 \
    onnxruntime==1.30.0 \
    opencv-contrib-python-headless==4.10.0.84

# Install InsightFace without allowing it to replace our versions
RUN pip install --no-cache-dir \
    --no-deps \
    insightface==0.7.3

# Install remaining InsightFace dependencies
RUN pip install --no-cache-dir \
    flask \
    flask-cors \
    tqdm \
    requests \
    scipy \
    scikit-learn \
    scikit-image \
    easydict \
    cython \
    albumentations \
    prettytable \
    Pillow \
    gunicorn

# Prevent matplotlib from rebuilding its cache in an awkward location
ENV MPLCONFIGDIR=/tmp/matplotlib

# Application
COPY face_server.py .
COPY train_all_models.py .

# Directories
RUN mkdir -p faces faces_incoming /tmp/matplotlib

# Render port
EXPOSE 10000

CMD ["sh", "-c", "gunicorn face_server:app --bind 0.0.0.0:${PORT:-10000} --workers 1 --timeout 300 --access-logfile - --error-logfile -"]
