FROM python:3.11-slim

WORKDIR /app

# System libraries required by OpenCV / InsightFace
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

# Install NumPy first
RUN pip install --no-cache-dir \
    numpy==1.26.4

# Install OpenCV contrib FIRST
RUN pip install --no-cache-dir \
    opencv-contrib-python-headless==4.10.0.84

# Install ONNX packages
RUN pip install --no-cache-dir \
    onnx==1.23.0 \
    onnxruntime==1.30.0

# Install InsightFace without allowing it to replace our packages
RUN pip install --no-cache-dir \
    --no-deps \
    insightface==0.7.3

# Install remaining packages
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
    matplotlib \
    Pillow \
    gunicorn

# IMPORTANT:
# Reinstall OpenCV contrib at the very end.
# This guarantees cv2.face / LBPH is available.
RUN pip install --no-cache-dir \
    --force-reinstall \
    --no-deps \
    opencv-contrib-python-headless==4.10.0.84

# Verify OpenCV has the face module
COPY face_server.py .
COPY train_all_models.py .

# Create faces directory
RUN mkdir -p faces

EXPOSE 5001

CMD ["gunicorn", "face_server:app", "--bind", "0.0.0.0:5001", "--workers", "1", "--timeout", "300"]
