FROM python:3.11-slim

WORKDIR /app

# System dependencies required by OpenCV/InsightFace/ONNX Runtime
RUN apt-get update && apt-get install -y \
    build-essential \
    cmake \
    g++ \
    libglib2.0-0 \
    libgl1 \
    libgomp1 \
    libopenblas-dev \
    && rm -rf /var/lib/apt/lists/*

# Keep memory and CPU usage predictable on small Render instances.
ENV OMP_NUM_THREADS=1
ENV OPENBLAS_NUM_THREADS=1
ENV MKL_NUM_THREADS=1
ENV NUMEXPR_NUM_THREADS=1
ENV ORT_INTRA_OP_NUM_THREADS=1
ENV ORT_INTER_OP_NUM_THREADS=1
ENV MPLCONFIGDIR=/tmp/matplotlib

COPY requirements.txt .

RUN pip install --no-cache-dir --upgrade pip
RUN pip install --no-cache-dir -r requirements.txt

COPY face_server.py .
COPY train_all_models.py .

RUN mkdir -p faces faces_incoming /tmp/matplotlib

EXPOSE 5001

CMD ["gunicorn", "face_server:app", "--bind", "0.0.0.0:5001", "--workers", "1", "--timeout", "300"]
