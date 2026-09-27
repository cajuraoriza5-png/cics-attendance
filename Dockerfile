FROM python:3.11-slim

WORKDIR /app

# System libraries required by OpenCV and InsightFace
RUN apt-get update && apt-get install -y \
    build-essential \
    cmake \
    g++ \
    libglib2.0-0 \
    libgl1 \
    libgomp1 \
    && rm -rf /var/lib/apt/lists/*

# Upgrade pip
RUN pip install --no-cache-dir --upgrade pip

# Install binary packages first
RUN pip install --no-cache-dir \
    numpy==1.26.4 \
    onnx==1.23.0 \
    onnxruntime==1.30.0 \
    opencv-contrib-python-headless==4.10.0.84

# Install dependencies required by InsightFace
RUN pip install --no-cache-dir \
    tqdm \
    requests \
    scipy \
    scikit-learn \
    scikit-image \
    easydict \
    cython \
    albumentations \
    prettytable \
    matplotlib \
    Pillow

# Install InsightFace separately
RUN pip install --no-cache-dir \
    --no-deps \
    insightface==0.7.3

# Install Flask and Gunicorn
RUN pip install --no-cache-dir \
    Flask \
    flask-cors \
    gunicorn

# Copy application
COPY face_server.py .
COPY train_all_models.py .

# Create faces directory
RUN mkdir -p faces

EXPOSE 5001

CMD ["gunicorn", "face_server:app", "--bind", "0.0.0.0:5001", "--workers", "1", "--timeout", "120"]
