FROM python:3.11-slim

WORKDIR /app

# System dependencies required by InsightFace/OpenCV
RUN apt-get update && apt-get install -y \
    build-essential \
    cmake \
    g++ \
    libglib2.0-0 \
    libgl1 \
    libgomp1 \
    libopenblas-dev \
    && rm -rf /var/lib/apt/lists/*

# Matplotlib configuration
ENV MPLBACKEND=Agg
ENV MPLCONFIGDIR=/app/.matplotlib

# Create Matplotlib cache directory
RUN mkdir -p /app/.matplotlib

# Copy requirements
COPY requirements.txt .

# Upgrade pip
RUN pip install --no-cache-dir --upgrade pip

# Install Python dependencies
RUN pip install --no-cache-dir -r requirements.txt

# Build Matplotlib font cache during Docker build
RUN python -c "import matplotlib; import matplotlib.font_manager"

# Copy application
COPY face_server.py .
COPY train_all_models.py .

# Create faces directory
RUN mkdir -p faces

EXPOSE 5001

CMD ["gunicorn", "face_server:app", "--bind", "0.0.0.0:5001", "--workers", "1", "--timeout", "300"]
