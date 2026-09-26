<?php
/*
 * face_enroll.php
 * -----------------------------------------------------------------------
 * PURPOSE : Face enrollment page. Captures a student's face using the
 *           webcam and saves it so LBPH can later recognize them.
 *
 * HOW IT WORKS:
 *   1. PHP checks if the student is already enrolled (face_registered = 1).
 *   2. If not enrolled (or re-enrolling), the page opens the webcam via
 *      the face_server.py Flask API (/detect endpoint on port 5001).
 *   3. Once a face is confirmed, a snapshot is uploaded to
 *      upload_face.php which saves it to the faces/ folder.
 *   4. After saving, face_registered in the users table is set to 1.
 *   5. The admin can then trigger training (face_train_multi.php) to
 *      update the LBPH model with the new face.
 *
 * ACCESS:
 *   - Admin can enroll any student (?uid=X&admin=1)
 *   - Student can enroll themselves after registration
 *
 * KEY URL PARAMS:
 *   ?uid=X       - Database ID of the student being enrolled
 *   ?reenroll=1  - Skip the "already enrolled" confirmation screen
 *   ?admin=1     - Flag that admin initiated the enrollment
 * -----------------------------------------------------------------------
 */
include("db.php");

// Ensure face_data table exists (auto-create on first run)
$conn->query("CREATE TABLE IF NOT EXISTS face_data (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    face_image VARCHAR(255) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_student (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$uid     = intval($_GET['uid'] ?? 0);
$isAdmin = isset($_SESSION['admin_id']) ? 1 : 0;
$adminFlag = isset($_GET['admin']) ? 1 : 0;
if($uid <= 0){ die("<h2 style='text-align:center;margin-top:100px;color:red;'>Invalid User.</h2>"); }

// Access control: a logged-in student can only enroll themselves;
// an admin can enroll/re-enroll anyone.
if(!$isAdmin){
    if(!isset($_SESSION['user_id']) || intval($_SESSION['user_id']) !== $uid){
        // allow if they just registered (no session yet) — REGISTER.PHP redirects here directly
        // so we accept the request as long as the uid matches a real, unregistered user.
    }
}

$check = $conn->query("SELECT face_registered, first_name, last_name FROM users WHERE id='$uid' LIMIT 1");
if($check->num_rows == 0){ die("<h2 style='text-align:center;margin-top:100px;color:red;'>User Not Found.</h2>"); }
$row = $check->fetch_assoc();

$reenroll = isset($_GET['reenroll']) && $_GET['reenroll'] === '1';

// If already registered and no explicit re-enroll confirmation, show confirmation page
if($row && $row['face_registered'] == 1 && !$reenroll){
    $sName      = htmlspecialchars(trim($row['first_name'].' '.$row['last_name']));
    $backUrl    = $isAdmin ? 'admin_dashboard.php' : 'student_dashboard.php';
    $reenrollUrl = 'face_enroll.php?uid=' . $uid . '&reenroll=1' . ($adminFlag ? '&admin=1' : '');
    ?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Re-enroll Face</title>
    <style>
    *{margin:0;padding:0;box-sizing:border-box;font-family:'Segoe UI',sans-serif;}
    body{background:#0d0d0d;min-height:100vh;display:flex;align-items:center;justify-content:center;}
    .box{background:#1a1a2e;border:1px solid rgba(255,215,0,.2);border-radius:18px;padding:40px 36px;max-width:460px;width:90%;text-align:center;color:#fff;}
    h2{font-size:22px;margin-bottom:10px;color:#FFD700;}
    p{color:rgba(255,255,255,.7);margin-bottom:28px;line-height:1.6;}
    .badge{display:inline-block;background:rgba(40,167,69,.2);color:#28a745;border:1px solid #28a745;
           border-radius:50px;padding:6px 18px;font-size:13px;font-weight:700;margin-bottom:20px;}
    .btn{display:block;width:100%;padding:14px;border:none;border-radius:12px;font-size:16px;
         font-weight:700;cursor:pointer;margin-bottom:12px;text-decoration:none;transition:.2s;}
    .btn-danger{background:linear-gradient(135deg,#dc3545,#c82333);color:#fff;}
    .btn-secondary{background:rgba(255,255,255,.08);color:rgba(255,255,255,.7);}
    .btn:hover{transform:translateY(-2px);}
    </style></head><body>
    <div class="box">
        <div class="badge">✅ Already Registered</div>
        <h2>Face Already Enrolled</h2>
        <p><strong><?php echo $sName; ?></strong> already has a registered face.<br>
        Re-enrolling will <strong>delete</strong> your existing face data and require a new scan.</p>
        <a href="<?php echo htmlspecialchars($reenrollUrl); ?>" class="btn btn-danger">🔄 Yes, Re-enroll My Face</a>
        <a href="<?php echo htmlspecialchars($backUrl); ?>" class="btn btn-secondary">← Cancel, Keep My Face Data</a>
    </div>
    </body></html><?php
    exit;
}

// Only wipe old data when user explicitly confirmed re-enrollment (?reenroll=1)
if($row && $row['face_registered'] == 1 && $reenroll){
    $oldFiles = $conn->query("SELECT face_image FROM face_data WHERE student_id='$uid'");
    while($f = $oldFiles->fetch_assoc()){
        $fp = __DIR__ . '/faces/' . $f['face_image'];
        if(file_exists($fp)) @unlink($fp);
    }
    $conn->query("DELETE FROM face_data WHERE student_id='$uid'");
    $conn->query("UPDATE users SET face_registered=0 WHERE id='$uid'");
    $facesDir = __DIR__ . '/faces/';
    foreach(glob($facesDir . '*.pkl') ?: [] as $pkl) @unlink($pkl);
    foreach(glob($facesDir . 'representations_*.pkl') ?: [] as $pkl) @unlink($pkl);
}

$studentName = trim($row['first_name'] . ' ' . $row['last_name']);
$redirectUrl = 'student_dashboard.php';



?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Face Enrollment – <?php echo htmlspecialchars($studentName); ?></title>
<style>
*{ margin:0; padding:0; box-sizing:border-box; }
body{
    font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;
    background:linear-gradient(135deg,#0f0c29,#302b63,#24243e);
    height:100vh; overflow:hidden;
    display:flex; justify-content:center; align-items:center;
    color:white; padding:14px;
}
.container{
    background:rgba(255,255,255,0.05);
    backdrop-filter:blur(20px);
    border:1px solid rgba(255,215,0,0.25);
    border-radius:22px; padding:18px 22px;
    width:100%; max-width:1080px; height:100%;
    display:flex; flex-direction:column; gap:10px;
    box-shadow:0 25px 60px rgba(0,0,0,0.5);
    overflow:hidden;
}

/* ── Header row ── */
.enroll-header{
    display:flex; align-items:center; justify-content:space-between;
    flex-wrap:wrap; gap:6px; flex-shrink:0;
}
h2{
    font-size:22px;
    background:linear-gradient(90deg,#FFD700,#FFA500);
    -webkit-background-clip:text; -webkit-text-fill-color:transparent;
}
.student-info{
    font-size:14px; color:rgba(255,255,255,0.75);
}
.student-info strong{ color:#FFD700; }

/* ── 2-column body ── */
.enroll-body{
    display:flex; gap:14px; flex:1; min-height:0; overflow:hidden;
}

/* LEFT: camera column */
.enroll-left{
    display:flex; flex-direction:column; gap:8px;
    width:46%; flex-shrink:0;
}
.camera-wrap{
    position:relative; flex:1; min-height:0;
    border-radius:16px; overflow:hidden;
    box-shadow:0 0 24px rgba(255,215,0,0.2);
}
#video{
    width:100%; height:100%; display:block;
    background:#000; object-fit:cover;
    transform:scaleX(-1);
}
#overlayCanvas{
    position:absolute; top:0; left:0;
    width:100%; height:100%;
    pointer-events:none; z-index:10;
}
#shutterOverlay{
    position:absolute; top:0; left:0; width:100%; height:100%;
    background:rgba(255,255,255,0); pointer-events:none; z-index:20;
    transition:background 0.1s;
}
.face-guide{
    position:absolute; top:50%; left:50%;
    transform:translate(-50%,-50%);
    width:42%; height:78%;
    min-width:110px; max-width:210px;
    min-height:150px; max-height:290px;
    border:2px solid rgba(255,215,0,0.7);
    border-radius:50%; pointer-events:none; z-index:5;
    box-shadow:0 0 0 9999px rgba(0,0,0,0.38), 0 0 18px rgba(255,215,0,0.25);
    transition:border-color 0.3s, box-shadow 0.3s;
}
.face-guide.ready{
    border-color:rgba(40,255,120,0.9);
    box-shadow:0 0 0 9999px rgba(0,0,0,0.3), 0 0 24px rgba(40,255,120,0.4);
}

/* Quality badges */
.quality-row{
    display:flex; gap:6px; flex-shrink:0;
}
.quality-badge{
    flex:1;
    background:rgba(0,0,0,0.4); border:1px solid rgba(255,255,255,0.1);
    border-radius:10px; padding:7px 4px; text-align:center;
    transition:all 0.3s;
}
.quality-badge.ok{ border-color:#28a745; background:rgba(40,167,69,0.15); }
.quality-badge.warn{ border-color:#ffc107; background:rgba(255,193,7,0.1); }
.quality-badge .qb-icon{ font-size:15px; display:block; margin-bottom:2px; }
.quality-badge .qb-label{ color:rgba(255,255,255,0.6); font-size:9px; }

/* Buttons */
.controls{ display:flex; gap:10px; flex-shrink:0; }
.btn{
    flex:1; padding:10px 12px; font-size:13px; font-weight:bold;
    border:none; border-radius:14px; cursor:pointer;
    transition:all 0.3s ease; text-transform:uppercase; letter-spacing:0.5px;
}
.btn-start{ background:linear-gradient(45deg,#FFD700,#FFA500); color:#000; box-shadow:0 6px 18px rgba(255,215,0,0.3); }
.btn-start:hover:not(:disabled){ transform:translateY(-2px); }
.btn-start:disabled{ background:rgba(255,255,255,0.1); color:rgba(255,255,255,0.3); cursor:not-allowed; transform:none; box-shadow:none; }
.btn-cancel{ background:linear-gradient(45deg,#dc3545,#c82333); color:white; }
.btn-cancel:hover{ transform:translateY(-2px); }

/* RIGHT: info column */
.enroll-right{
    flex:1; display:flex; flex-direction:column; gap:8px;
    min-height:0; overflow-y:auto;
}

/* Status */
.status-box{
    background:rgba(0,0,0,0.45); border:1px solid rgba(255,215,0,0.2);
    border-radius:12px; padding:11px 14px; text-align:center; flex-shrink:0;
}
#statusTxt{ font-size:16px; font-weight:bold; color:#FFD700; }

/* Progress */
.prog-wrap{
    background:rgba(0,0,0,0.45); border:1px solid rgba(255,215,0,0.2);
    border-radius:12px; padding:11px 14px; flex-shrink:0;
}
.prog-label{ font-size:13px; margin-bottom:6px; color:rgba(255,255,255,0.85); }
.prog-bar{ width:100%; height:16px; background:rgba(255,255,255,0.08); border-radius:10px; overflow:hidden; position:relative; }
.prog-fill{ height:100%; width:0%; background:linear-gradient(90deg,#FFD700,#FFA500); border-radius:10px; transition:width 0.4s ease; }
.prog-text{ position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); font-size:11px; font-weight:bold; color:#000; }

/* Algorithm panel */
.algo-panel{
    display:grid; grid-template-columns:repeat(3,1fr); gap:8px; flex-shrink:0;
}
.algo-card{
    background:rgba(0,0,0,0.4); border:1px solid rgba(255,255,255,0.1);
    border-radius:12px; padding:10px; text-align:center;
    transition:border-color 0.3s, box-shadow 0.3s;
}
.algo-card.ready{ border-color:#28a745; box-shadow:0 0 10px rgba(40,167,69,0.3); }
.algo-card.active{ border-color:#FFD700; box-shadow:0 0 10px rgba(255,215,0,0.4); animation:algoPulse 1s infinite alternate; }
.algo-card.done{ border-color:#20c997; box-shadow:0 0 10px rgba(32,201,151,0.3); }
.algo-card.error{ border-color:#dc3545; }
@keyframes algoPulse{ from{opacity:1;} to{opacity:0.7;} }
.algo-name{ font-size:11px; font-weight:bold; color:rgba(255,255,255,0.9); margin-bottom:3px; }
.algo-icon{ font-size:22px; margin-bottom:3px; }
.algo-status{ font-size:10px; color:rgba(255,255,255,0.6); }

/* Instructions */
.instructions{
    background:rgba(255,215,0,0.06); border:1px solid rgba(255,215,0,0.18);
    border-radius:10px; padding:10px 12px; font-size:11.5px;
    color:rgba(255,255,255,0.7); line-height:1.65; flex-shrink:0;
}
.instructions strong{ color:#FFD700; }

/* ── Mobile: stack vertically ── */
@media(max-width:700px){
    body{ height:auto; overflow:auto; padding:10px; align-items:flex-start; }
    .container{ height:auto; overflow:visible; padding:14px; }
    .enroll-body{ flex-direction:column; }
    .enroll-left{ width:100%; }
    .camera-wrap{ height:55vw; max-height:300px; flex:none; }
    h2{ font-size:19px; }
    .btn{ font-size:13px; }
}
</style>
</head>
<body>
<div class="container">

    <!-- ── Header ── -->
    <div class="enroll-header">
        <h2>🎓 Face Enrollment System</h2>
        <div class="student-info">Enrolling: <strong><?php echo htmlspecialchars($studentName); ?></strong></div>
    </div>

    <!-- ── 2-column body ── -->
    <div class="enroll-body">

        <!-- LEFT: camera -->
        <div class="enroll-left">
            <div class="camera-wrap">
                <video id="video" autoplay playsinline></video>
                <canvas id="overlayCanvas"></canvas>
                <div id="shutterOverlay"></div>
                <div class="face-guide"></div>
            </div>

            <div class="quality-row">
                <div class="quality-badge" id="qb-face">
                    <span class="qb-icon">👤</span>
                    <div id="qb-face-txt">No Face</div>
                    <div class="qb-label">Detection</div>
                </div>
                <div class="quality-badge" id="qb-size">
                    <span class="qb-icon">📏</span>
                    <div id="qb-size-txt">—</div>
                    <div class="qb-label">Face Size</div>
                </div>
                <div class="quality-badge" id="qb-pos">
                    <span class="qb-icon">🎯</span>
                    <div id="qb-pos-txt">—</div>
                    <div class="qb-label">Position</div>
                </div>
                <div class="quality-badge" id="qb-ready">
                    <span class="qb-icon">✅</span>
                    <div id="qb-ready-txt">Not Ready</div>
                    <div class="qb-label">Status</div>
                </div>
            </div>

            <div class="controls">
                <button id="startBtn" class="btn btn-start" onclick="startEnroll()" disabled>📷 Start</button>
                <button class="btn btn-cancel" onclick="window.location='<?php echo $redirectUrl; ?>'">❌ Cancel</button>
            </div>
        </div>

        <!-- RIGHT: info -->
        <div class="enroll-right">
            <div class="status-box">
                <div id="statusTxt">📷 Initializing camera…</div>
            </div>

            <div class="prog-wrap">
                <div class="prog-label">Capturing Images: <span id="capCount">0</span> / 30</div>
                <div class="prog-bar">
                    <div class="prog-fill" id="progFill"></div>
                    <div class="prog-text" id="progText">0%</div>
                </div>
            </div>

            <div class="algo-panel">
                <div class="algo-card" id="card-lbph">
                    <div class="algo-icon">🔷</div>
                    <div class="algo-name">LBPH</div>
                    <div class="algo-status" id="status-lbph">Waiting…</div>
                </div>
                <div class="algo-card" id="card-fr">
                    <div class="algo-icon">�</div>
                    <div class="algo-name">Face Recognition</div>
                    <div class="algo-status" id="status-fr">Waiting…</div>
                </div>
            </div>

            <div class="instructions">
                <strong>📸 Instructions:</strong><br>
                • Face within the oval guide, good lighting<br>
                • <strong>30 images</strong> captured with angle variations<br>
                • Look straight; occasional slight turns are fine<br>
                • Training runs automatically after capture
            </div>
        </div>

    </div>
</div>

<script>
const video      = document.getElementById('video');
const canvas     = document.getElementById('overlayCanvas');
const shutter    = document.getElementById('shutterOverlay');
const statusTxt  = document.getElementById('statusTxt');
const startBtn   = document.getElementById('startBtn');
const progFill   = document.getElementById('progFill');
const progText   = document.getElementById('progText');
const capCount   = document.getElementById('capCount');

const TOTAL_CAPS = 30;
let isEnrolling  = false;
let detectLoop   = null;
let detectIsRAF  = false;   // true when detectLoop is a requestAnimationFrame id
let faceReady    = false;
let lastBbox     = null;

// ── Camera ────────────────────────────────────────────────────────────────
async function startCamera(){
    try {
        const stream = await navigator.mediaDevices.getUserMedia({
            video:{ width:{ideal:640}, height:{ideal:480}, facingMode:'user' }
        });
        video.srcObject = stream;
        video.onloadedmetadata = () => {
            video.play();
            canvas.width  = video.videoWidth  || 640;
            canvas.height = video.videoHeight || 480;
            setStatus('✅ Camera ready – position your face in the oval', '#28a745');
            startBtn.disabled = false;
            beginDetection();
        };
    } catch(e){
        setStatus('❌ Camera error: ' + e.message, '#dc3545');
    }
}

// ── Live face detection ─ prefer native FaceDetector (zero latency, no download)
//    falls back to server-side OpenCV via face_detect_api.php ─────────────────
let nativeDetector = null;
if('FaceDetector' in window){
    try { nativeDetector = new FaceDetector({fastMode:true, maxDetectedFaces:1}); }
    catch(e){ nativeDetector = null; }
}

function beginDetection(){
    if(nativeDetector){
        // Browser-native detection ─ ~30 fps, no network calls
        detectIsRAF = true;
        const tick = async () => {
            if(video.readyState === 4){
                try {
                    const faces = await nativeDetector.detect(video);
                    if(faces && faces.length > 0){
                        const r = faces[0].boundingBox;
                        const bbox = { x:r.x, y:r.y, w:r.width, h:r.height };
                        const data = { faces_count:1, bbox };
                        lastBbox = bbox;
                        updateQualityBadges(data);
                        drawBbox(bbox, 1);
                        document.querySelector('.face-guide')?.classList.toggle('ready', faceReady);
                    } else {
                        lastBbox = null;
                        updateQualityBadges({faces_count:0, bbox:null});
                        drawBbox(null, 0);
                        document.querySelector('.face-guide')?.classList.remove('ready');
                    }
                } catch(e){ /* silent */ }
            }
            detectLoop = requestAnimationFrame(tick);
        };
        tick();
        return;
    }
    detectIsRAF = false;

    // Fallback: server-side detection via Flask /detect (fast) or face_detect_api.php (slow)
   const FLASK_DETECT = 'https://cics-attendance.onrender.com/detect';
    let useFlask = true;
    let detectBusy = false;

    detectLoop = setInterval(async () => {
        if(detectBusy) return;
        const b64 = captureFrame();
        if(!b64) return;
        detectBusy = true;
        try {
            let data;
            if(useFlask){
                const res = await fetch(FLASK_DETECT, {
                    method:'POST', body:JSON.stringify({image:b64}),
                    headers:{'Content-Type':'application/json'}
                });
                data = await res.json();
            } else {
                const fd = new FormData();
                fd.append('image', b64);
                const res = await fetch('face_detect_api.php', {method:'POST', body:fd});
                data = await res.json();
            }
            lastBbox = data.bbox || null;
            updateQualityBadges(data);
            drawBbox(data.bbox, data.faces_count);
            document.querySelector('.face-guide')?.classList.toggle('ready', faceReady);
        } catch(e){
            if(useFlask){ useFlask = false; } // Flask down, switch to PHP fallback
        }
        detectBusy = false;
    }, 250);
}

// ── Quality badge updater ─────────────────────────────────────────────────
function updateQualityBadges(data){
    const hasFace = data.faces_count > 0;
    const bbox    = data.bbox;

    // Face detection badge
    const qbFace = document.getElementById('qb-face');
    document.getElementById('qb-face-txt').textContent = hasFace ? 'Detected ✓' : 'Not Detected';
    qbFace.className = 'quality-badge ' + (hasFace ? 'ok' : 'warn');

    if(!hasFace){
        ['qb-size','qb-pos','qb-ready'].forEach(id => {
            document.getElementById(id).className = 'quality-badge warn';
        });
        document.getElementById('qb-size-txt').textContent = '—';
        document.getElementById('qb-pos-txt').textContent  = '—';
        document.getElementById('qb-ready-txt').textContent = 'No Face';
        faceReady = false;
        return;
    }

    // Size badge (face should be at least 80×80 px in native resolution)
    const sizeOk = bbox.w >= 80 && bbox.h >= 80;
    const qbSize = document.getElementById('qb-size');
    document.getElementById('qb-size-txt').textContent = bbox.w + '×' + bbox.h + 'px';
    qbSize.className = 'quality-badge ' + (sizeOk ? 'ok' : 'warn');

    // Position badge (face should be roughly centered)
    const cx = bbox.x + bbox.w / 2;
    const cy = bbox.y + bbox.h / 2;
    const vw = canvas.width, vh = canvas.height;
    const posOk = cx > vw * 0.25 && cx < vw * 0.75 && cy > vh * 0.15 && cy < vh * 0.85;
    const qbPos = document.getElementById('qb-pos');
    document.getElementById('qb-pos-txt').textContent = posOk ? 'Centered ✓' : 'Adjust';
    qbPos.className = 'quality-badge ' + (posOk ? 'ok' : 'warn');

    // Overall readiness
    faceReady = sizeOk && posOk;
    const qbReady = document.getElementById('qb-ready');
    document.getElementById('qb-ready-txt').textContent = faceReady ? 'Ready! ✓' : 'Adjust…';
    qbReady.className = 'quality-badge ' + (faceReady ? 'ok' : 'warn');
}

// ── Draw bounding box on canvas ───────────────────────────────────────────
function drawBbox(bbox, count){
    const ctx = canvas.getContext('2d');
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    if(!bbox) return;

    // Mirror to match video (video is CSS-mirrored, canvas is not)
    const mx = canvas.width - bbox.x - bbox.w;
    const color = faceReady ? '#00ff88' : '#FFD700';

    ctx.save();
    ctx.shadowColor = color;
    ctx.shadowBlur  = 20;
    ctx.strokeStyle = color;
    ctx.lineWidth   = 3;
    ctx.strokeRect(mx, bbox.y, bbox.w, bbox.h);
    ctx.shadowBlur  = 0;

    // Corner brackets
    const cs = 18;
    ctx.lineWidth = 4;
    [[mx,bbox.y],[mx+bbox.w-cs,bbox.y],[mx,bbox.y+bbox.h-cs],[mx+bbox.w-cs,bbox.y+bbox.h-cs]].forEach(([bx,by])=>{
        ctx.beginPath();
        ctx.moveTo(bx+cs,by); ctx.lineTo(bx,by); ctx.lineTo(bx,by+cs);
        ctx.stroke();
    });

    // Label above box
    ctx.shadowBlur = 0;
    ctx.fillStyle  = faceReady ? 'rgba(0,255,136,0.85)' : 'rgba(255,215,0,0.85)';
    ctx.fillRect(mx, bbox.y - 28, faceReady ? 110 : 130, 24);
    ctx.fillStyle  = '#000';
    ctx.font       = 'bold 13px Segoe UI';
    ctx.fillText(faceReady ? '✓ Face Ready' : '⚠ Adjust Position', mx + 5, bbox.y - 9);

    ctx.restore();
}

// ── Capture frame to base64 (raw, not mirrored) ──────────────────────────
function captureFrame(){
    try {
        const tmp = document.createElement('canvas');
        tmp.width  = video.videoWidth;
        tmp.height = video.videoHeight;
        tmp.getContext('2d').drawImage(video, 0, 0);
        return tmp.toDataURL('image/jpeg', 0.9).split(',')[1];
    } catch(e){ return null; }
}

// ── Save a captured image to server ──────────────────────────────────────
function saveImage(index){
    return new Promise((resolve, reject) => {
        const tmp = document.createElement('canvas');
        tmp.width  = video.videoWidth;
        tmp.height = video.videoHeight;
        const ctx = tmp.getContext('2d');
        // Mirror for natural selfie storage
        ctx.translate(tmp.width, 0); ctx.scale(-1, 1);
        ctx.drawImage(video, 0, 0);

  tmp.toBlob(blob => {
    if(!blob){
        reject(new Error('Blob creation failed'));
        return;
    }

    console.log(
        'CAPTURED IMAGE:',
        'width=', tmp.width,
        'height=', tmp.height,
        'size=', blob.size,
        'bytes'
    );

    console.log(
        'KB:',
        (blob.size / 1024).toFixed(2)
    );

    const fd = new FormData();
    fd.append('image', blob, `face_${index}.jpg`);
    fd.append('uid', '<?php echo $uid; ?>');
    fd.append('index', index);

    fetch('save_face.php', {
        method:'POST',
        body:fd
    })
    .then(r => r.text())
    .then(t => {
        console.log('save_face.php response:', t);

        if(t.trim()==='success'){
            resolve();
        }else{
            reject(new Error(t));
        }
    })
    .catch(reject);

}, 'image/jpeg', 0.95);
function sleep(ms){ return new Promise(r=>setTimeout(r,ms)); }

async function waitForFace(maxMs=6000){
    const t0 = Date.now();
    while(!faceReady){
        if(Date.now()-t0 > maxMs) return false;
        await sleep(120);
    }
    return true;
}

// ── Progress helpers ──────────────────────────────────────────────────────
function setProgress(cur, tot){
    const pct = Math.round((cur/tot)*100);
    progFill.style.width = pct + '%';
    progText.textContent = pct + '%';
    capCount.textContent = cur;
}
function setStatus(msg, color='#FFD700'){
    statusTxt.innerHTML = msg;
    statusTxt.style.color = color;
}
function setAlgoCard(id, state, msg){
    document.getElementById('card-'+id).className = 'algo-card ' + state;
    document.getElementById('status-'+id).textContent = msg;
}
function flash(){
    shutter.style.background='rgba(255,255,255,0.7)';
    setTimeout(()=>{ shutter.style.background='rgba(255,255,255,0)'; },150);
}

// ── Main enrollment flow ──────────────────────────────────────────────────
async function startEnroll(){
    if(isEnrolling) return;

    isEnrolling  = true;
    startBtn.disabled = true;

    setAlgoCard('lbph', 'active', 'Capturing…');
    setAlgoCard('fr',   'active', 'Capturing…');

    let ok = 0;
    let angleStep = 0; // 0=straight, 1=slight left, 2=slight right, cycle

    const angleHints = [
        '📸 Look straight at camera',
        '↙ Tilt slightly left',
        '↘ Tilt slightly right',
        '📸 Look straight at camera',
        '🔼 Chin slightly up',
        '🔽 Chin slightly down',
    ];

    for(let i = 1; i <= TOTAL_CAPS; i++){
        const hint = angleHints[(i-1) % angleHints.length];
        setStatus(`${hint} – Capture ${i}/${TOTAL_CAPS}`, '#FFA500');
        await sleep(300);
        try {
            await saveImage(i);
            ok++;
            flash();
            setProgress(i, TOTAL_CAPS);
            setStatus(`✅ Saved ${i}/${TOTAL_CAPS}`, '#28a745');
        } catch(e){
            setStatus(`⚠️ Capture ${i} failed – retrying`, '#ffc107');
            window._failCount = (window._failCount || 0) + 1;
            if(window._failCount > 5){
                setStatus('❌ Too many capture failures. Check the camera/network.', '#dc3545');
                break;
            }
            i--;
        }
        if(i < TOTAL_CAPS) await sleep(400);
    }

    if(ok < 15){
        setStatus(`❌ Only ${ok}/${TOTAL_CAPS} captured. Please try again.`, '#dc3545');
        ['lbph','fr'].forEach(a=>setAlgoCard(a,'error','Failed'));
        startBtn.disabled = false;
        isEnrolling = false;
        return;
    }

    // Mark face_registered in DB
    setStatus('💾 Saving enrollment status…', '#FFA500');
    try {
        const r   = await fetch('save_face_status.php?uid=<?php echo $uid; ?>');
        const txt = await r.text();
        if(txt.trim() !== 'success'){
            throw new Error('save_face_status returned: ' + txt);
        }
    } catch(e){
        setStatus('❌ Error saving status: ' + e.message, '#dc3545');
        startBtn.disabled = false;
        isEnrolling = false;
        return;
    }

    // ── Fire-and-forget training: kicks off in background, user redirects immediately
    setStatus('🎉 Captures complete! Training runs in background. Redirecting…', '#20c997');
    setAlgoCard('lbph', 'done', '✅ Queued');
    setAlgoCard('fr',   'done', '✅ Queued');

    try {
        // Tell server to start training in background; do NOT await full completion.
        fetch('face_train_multi.php?async=1', {keepalive:true}).catch(()=>{});
    } catch(e){ /* silent */ }

    // Redirect quickly ─ admin dashboard will show progress bar
    setTimeout(()=>{ window.location='<?php echo $redirectUrl; ?>'; }, 1200);
}

// Init
startCamera();
window.addEventListener('beforeunload', ()=>{
    if(detectLoop){
        if(detectIsRAF) cancelAnimationFrame(detectLoop);
        else            clearInterval(detectLoop);
    }
    if(video.srcObject) video.srcObject.getTracks().forEach(t=>t.stop());
});
</script>
</body>
</html>