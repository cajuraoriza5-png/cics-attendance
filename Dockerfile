FROM python:3.11-slim

WORKDIR /app

# Keep native dependencies small enough for a low-memory Render service.
RUN apt-get update && apt-get install -y --no-install-recommends \
    build-essential \
    cmake \
    g++ \
    libglib2.0-0 \
    libgl1 \
    libgomp1 \
    libopenblas-dev \
    && rm -rf /var/lib/apt/lists/*

COPY requirements.txt .

RUN pip install --no-cache-dir --upgrade pip \
    && pip install --no-cache-dir -r requirements.txt

# InsightFace may pull a normal OpenCV wheel as a dependency. Reinstall the
# contrib headless wheel last so cv2.face/LBPH is definitely available.
RUN pip uninstall -y opencv-python opencv-python-headless opencv-contrib-python opencv-contrib-python-headless || true \
    && pip install --no-cache-dir opencv-contrib-python-headless==4.10.0.84

COPY face_server.py .
COPY train_all_models.py .

RUN mkdir -p faces faces_incoming /tmp/matplotlib

EXPOSE 5001

# One worker only. face_server.py lazy-loads ArcFace so the worker does not
# load InsightFace until recognition actually needs it.
CMD ["gunicorn", "face_server:app", "--bind", "0.0.0.0:5001", "--workers", "1", "--timeout", "300", "--preload"]
