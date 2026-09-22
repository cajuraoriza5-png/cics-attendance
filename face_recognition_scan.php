<?php
/*
 * face_recognition_scan.php
 * -----------------------------------------------------------------------
 * PURPOSE : Main face recognition attendance scanner page.
 *           Accessible by: admin OR officer (student with is_officer = 1).
 *           Unauthorized users are redirected to ADMIN_LOGIN.PHP.
 *
 * HOW IT WORKS:
 *   1. PHP renders the scanner UI (camera + stats + event info).
 *   2. JavaScript captures webcam frames and sends them as Base64 to
 *      face_server.py (Flask API on port 5001) via fetch().
 *   3. Flask runs LBPH recognition and returns the matched student ID.
 *   4. JS posts the result to scan_attendance.php to record attendance.
 *
 * AJAX ENDPOINTS (called by JavaScript on the same page):
 *   ?scanned_ids  - Returns array of already-scanned student IDs (prevents double scan)
 *   ?stats_only   - Returns live attendance counts (present/late/absent)
 *
 * KEY SESSION VARIABLES:
 *   $_SESSION['admin_id']   - Set when admin is logged in
 *   $_SESSION['student_id'] - Set when student/officer is logged in
 * -----------------------------------------------------------------------
 */
date_default_timezone_set('Asia/Manila');
session_start();
include("db.php");

if($conn->connect_error){
    die("Connection failed: " . $conn->connect_error);
}
// Migration: ensure is_officer column exists before querying it
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS is_officer TINYINT(1) NOT NULL DEFAULT 0");

// --- Access Control: allow admin OR officer student only ---
$isAdmin   = isset($_SESSION['admin_id']);
$isOfficer = false;
if(!$isAdmin && isset($_SESSION['student_id'])){
    $oc = $conn->query("SELECT is_officer FROM users WHERE id=".intval($_SESSION['student_id'])." LIMIT 1")->fetch_assoc();
    $isOfficer = ($oc && !empty($oc['is_officer']));
}
if(!$isAdmin && !$isOfficer){ header("Location: ADMIN_LOGIN.PHP"); exit; }

$event = $conn->query("SELECT * FROM events WHERE event_date = CURDATE() ORDER BY id DESC LIMIT 1")->fetch_assoc();
$event_name = $event['event_name'] ?? 'No Event Today';
$event_id   = $event['id'] ?? 0;

// ── Scanned-IDs AJAX endpoint (window-aware: only block the current direction) ──
if(isset($_GET['scanned_ids'])){
    header('Content-Type: application/json');
    $ids = [];
    if($event_id){
        $win = $_GET['window'] ?? 'any';
        if($win === 'morning_login')        $cond = 'morning_in  IS NOT NULL';
        elseif($win === 'morning_logout')   $cond = 'morning_out IS NOT NULL';
        elseif($win === 'afternoon_login')  $cond = 'afternoon_in  IS NOT NULL';
        elseif($win === 'afternoon_logout') $cond = 'afternoon_out IS NOT NULL';
        else $cond = '(morning_in IS NOT NULL OR afternoon_in IS NOT NULL)';
        $r = $conn->query("SELECT student_id FROM attendance WHERE event_id='$event_id' AND date=CURDATE() AND ($cond)");
        while($r && ($row = $r->fetch_assoc())){ $ids[] = (int)$row['student_id']; }
    }
    echo json_encode($ids);
    exit;
}

// ── Server-status proxy (relays Flask /status to JS without CORS) ──────────
if(isset($_GET['server_status'])){
    header('Content-Type: application/json');
    $config = require __DIR__ . '/config.php';
    $ch = curl_init($config['python_service']['url'] . '/status');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>3,CURLOPT_CONNECTTIMEOUT=>2]);
    $r    = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if($code===200 && $r) echo $r;
    else echo json_encode(['ok'=>false,'loading'=>true,'offline'=>true,
                           'models'=>['lbph'=>false,'fr_helper'=>false]]);
    exit;
}

// ── Stats-only AJAX endpoint (must be before any HTML output) ──────────────
if(isset($_GET['stats_only'])){
    header('Content-Type: application/json');
    if($event_id){
        $s = $conn->query("
            SELECT COUNT(*) as total,
            SUM(CASE WHEN morning_status='Present' OR afternoon_status='Present' THEN 1 ELSE 0 END) as present,
            SUM(CASE WHEN (morning_status='Late' OR afternoon_status='Late') AND morning_status!='Present' AND afternoon_status!='Present' THEN 1 ELSE 0 END) as late,
            SUM(CASE WHEN (morning_status='Absent' OR morning_status IS NULL) AND (afternoon_status='Absent' OR afternoon_status IS NULL) THEN 1 ELSE 0 END) as absent
            FROM attendance WHERE event_id='$event_id' AND date=CURDATE()
        ")->fetch_assoc();
        echo json_encode($s);
    } else {
        echo json_encode(['total'=>0,'present'=>0,'late'=>0,'absent'=>0]);
    }
    exit;
}

$stats = ['total'=>0,'present'=>0,'late'=>0,'absent'=>0];
if($event_id){
    $stats = $conn->query("
        SELECT COUNT(*) as total,
        SUM(CASE WHEN morning_status='Present' OR afternoon_status='Present' THEN 1 ELSE 0 END) as present,
        SUM(CASE WHEN (morning_status='Late' OR afternoon_status='Late') AND morning_status!='Present' AND afternoon_status!='Present' THEN 1 ELSE 0 END) as late,
        SUM(CASE WHEN (morning_status='Absent' OR morning_status IS NULL) AND (afternoon_status='Absent' OR afternoon_status IS NULL) THEN 1 ELSE 0 END) as absent
        FROM attendance WHERE event_id='$event_id' AND date=CURDATE()
    ")->fetch_assoc();
}

// Timing data for JS
$timingJson = json_encode([
    'event_type'             => $event['event_type']              ?? '',
    'morning_login_start'    => $event['morning_login_start']    ?? null,
    'morning_login_end'      => $event['morning_login_end']      ?? null,
    'morning_late_time'      => $event['morning_late_time']      ?? null,
    'morning_logout_start'   => $event['morning_logout_start']   ?? null,
    'morning_logout_end'     => $event['morning_logout_end']     ?? null,
    'afternoon_login_start'  => $event['afternoon_login_start']  ?? null,
    'afternoon_login_end'    => $event['afternoon_login_end']    ?? null,
    'afternoon_late_time'    => $event['afternoon_late_time']    ?? null,
    'afternoon_logout_start' => $event['afternoon_logout_start'] ?? null,
    'afternoon_logout_end'   => $event['afternoon_logout_end']   ?? null,
]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Face Recognition Attendance</title>
<style>
*{margin:0;padding:0;box-sizing:border-box;font-family:'Segoe UI',sans-serif;}
html,body{
    height:100%;overflow:hidden;
    background:linear-gradient(135deg,#0f0c29,#302b63,#24243e);color:white;
}

/* ── HEADER ─────────────────────────────────────────────────────────── */
.header{
    height:58px;background:rgba(0,0,0,0.55);backdrop-filter:blur(12px);
    border-bottom:1px solid rgba(255,215,0,0.2);
    display:flex;align-items:center;justify-content:space-between;
    padding:0 22px;gap:10px;flex-shrink:0;
}
.header h1{font-size:20px;background:linear-gradient(90deg,#FFD700,#FFA500);-webkit-background-clip:text;-webkit-text-fill-color:transparent;white-space:nowrap;}
.event-badge{background:rgba(255,215,0,0.12);border:1px solid rgba(255,215,0,0.25);border-radius:18px;padding:5px 14px;font-size:13px;color:#FFD700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:340px;}
.back-btn{background:rgba(255,255,255,0.1);border:1px solid rgba(255,255,255,0.2);color:white;padding:7px 16px;border-radius:18px;font-size:13px;text-decoration:none;white-space:nowrap;}
.back-btn:hover{background:rgba(255,215,0,0.15);}

/* ── MAIN LAYOUT ─────────────────────────────────────────────────────── */
.main{
    display:flex;gap:14px;padding:14px;
    height:calc(100vh - 94px);
    overflow:hidden;
}

/* ── LEFT — camera ──────────────────────────────────────────────────── */
.cam-section{
    flex:1;min-width:0;
    display:flex;flex-direction:column;
    gap:10px;
    overflow:hidden;
}
.cam-card{
    flex:1;min-height:0;
    background:rgba(255,255,255,0.06);backdrop-filter:blur(10px);
    border:1px solid rgba(255,215,0,0.2);border-radius:18px;
    padding:14px;
    display:flex;flex-direction:column;
    overflow:hidden;
}
.cam-card h2{font-size:15px;color:#FFD700;margin-bottom:10px;text-align:center;flex-shrink:0;}
.video-wrap{
    flex:1;min-height:0;
    position:relative;border-radius:12px;overflow:hidden;background:#000;
}
#video{width:100%;height:100%;display:block;object-fit:cover;transform:scaleX(-1);}
#bboxCanvas{position:absolute;top:0;left:0;width:100%;height:100%;pointer-events:none;}
.scan-overlay{
    position:absolute;bottom:8px;left:8px;right:8px;
    background:rgba(0,0,0,0.72);border-radius:8px;padding:8px 12px;
    text-align:center;font-size:13px;font-weight:bold;color:#FFD700;
}

/* ── CONTROLS + RESULT (below camera, compact) ──────────────────────── */
.bottom-bar{
    flex-shrink:0;
    background:rgba(255,255,255,0.04);border:1px solid rgba(255,215,0,0.15);
    border-radius:14px;padding:10px 14px;
    display:flex;align-items:center;gap:12px;flex-wrap:wrap;
}
.controls{display:flex;gap:10px;flex-shrink:0;}
.btn{padding:9px 22px;font-size:14px;font-weight:bold;border:none;border-radius:12px;cursor:pointer;transition:all .25s;}
.btn-start{background:linear-gradient(45deg,#28a745,#20c997);color:white;}
.btn-start:hover{transform:translateY(-2px);box-shadow:0 6px 16px rgba(40,167,69,0.4);}
.btn-stop{background:linear-gradient(45deg,#dc3545,#e91e63);color:white;}
.btn-stop:hover{transform:translateY(-2px);}
.btn:disabled{background:#333;cursor:not-allowed;transform:none;color:#777;}
.result-inline{flex:1;min-width:0;}
.result-name{font-size:17px;font-weight:bold;color:#FFD700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.result-status{font-size:12px;color:rgba(255,255,255,0.6);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.result-inline.success .result-name{color:#51cf66;}
.result-inline.fail    .result-name{color:#ff6b6b;}

/* ── RIGHT PANEL ─────────────────────────────────────────────────────── */
.right-section{
    width:300px;flex-shrink:0;
    display:flex;flex-direction:column;
    gap:12px;overflow:hidden;
}
.panel-card{
    background:rgba(255,255,255,0.06);backdrop-filter:blur(10px);
    border:1px solid rgba(255,215,0,0.2);border-radius:18px;padding:16px;
    flex-shrink:0;
}
.panel-card h2{font-size:14px;color:#FFD700;margin-bottom:12px;}

/* Stats */
.stats-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;}
.stat{border-radius:10px;padding:10px;text-align:center;}
.stat h4{font-size:10px;opacity:.8;margin-bottom:3px;text-transform:uppercase;letter-spacing:.5px;}
.stat .val{font-size:26px;font-weight:bold;}
.s-total  {background:linear-gradient(135deg,#667eea,#764ba2);}
.s-present{background:linear-gradient(135deg,#28a745,#20c997);}
.s-late   {background:linear-gradient(135deg,#ffc107,#ff9800);color:#000;}
.s-absent {background:linear-gradient(135deg,#dc3545,#e91e63);}

/* Scans history — fills remaining height and scrolls */
.scans-panel{
    flex:1;min-height:0;
    background:rgba(255,255,255,0.06);backdrop-filter:blur(10px);
    border:1px solid rgba(255,215,0,0.2);border-radius:18px;padding:16px;
    display:flex;flex-direction:column;
    overflow:hidden;
}
.scans-panel h2{font-size:14px;color:#FFD700;margin-bottom:10px;flex-shrink:0;}
.scans-list{
    flex:1;overflow-y:auto;overflow-x:hidden;
    scrollbar-width:thin;scrollbar-color:rgba(255,215,0,.3) transparent;
}
.scans-list::-webkit-scrollbar{width:4px;}
.scans-list::-webkit-scrollbar-track{background:transparent;}
.scans-list::-webkit-scrollbar-thumb{background:rgba(255,215,0,.3);border-radius:4px;}
.scan-item{
    background:rgba(0,0,0,0.35);border-left:4px solid #28a745;
    border-radius:8px;padding:9px 11px;margin-bottom:7px;
    display:flex;flex-direction:column;gap:2px;
}
.scan-item.late {border-left-color:#ffc107;}
.scan-item.error{border-left-color:#dc3545;}
.scan-row{display:flex;justify-content:space-between;align-items:center;}
.scan-name{font-weight:800;font-size:14px;color:white;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.scan-time{font-size:11px;color:rgba(255,255,255,0.5);white-space:nowrap;margin-left:8px;}
.scan-conf{font-size:11px;color:rgba(255,215,0,0.65);margin-top:1px;}
.badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:bold;}
.badge-present{background:#28a745;color:white;}
.badge-late   {background:#ffc107;color:#000;}
.badge-error  {background:#dc3545;color:white;}
.no-scans{text-align:center;color:rgba(255,255,255,0.35);padding:24px 0;font-size:13px;}

/* ── MOBILE ──────────────────────────────────────────────────────────── */
@media(max-width:900px){
    html,body{overflow:auto;height:auto;}
    .main{flex-direction:column;height:auto;overflow:visible;padding:12px;gap:12px;}
    .right-section{width:100%;}
    .scans-panel{min-height:260px;max-height:320px;}
    .cam-section{min-height:300px;}
    .cam-card{min-height:300px;}
    .header h1{font-size:16px;}
    .event-badge{max-width:180px;font-size:12px;}
}
</style>
</head>
<body>
<div class="header">
    <h1>📷 Face Attendance</h1>
    <div class="event-badge">📅 <?php echo htmlspecialchars($event_name); ?> &nbsp;|&nbsp; <?php echo date('F d, Y'); ?></div>
    <?php if($isAdmin): ?>
    <a href="admin_dashboard.php" class="back-btn">← Dashboard</a>
    <?php else: ?>
    <a href="student_dashboard.php" class="back-btn">← My Dashboard</a>
    <?php endif; ?>
</div>

<?php
$scanEventType  = $event['event_type'] ?? '';
$showMorning    = ($scanEventType !== 'Afternoon Only');
$showAfternoon  = ($scanEventType !== 'Morning Only');
?>
<!-- Timing strip -->
<div id="timingStrip" style="background:rgba(0,0,0,0.45);border-bottom:1px solid rgba(255,215,0,0.15);padding:7px 22px;display:flex;align-items:center;gap:18px;flex-wrap:wrap;font-size:13px;">
    <span style="color:rgba(255,255,255,0.55);">Windows:</span>
    <?php if($event && $showMorning && $event['morning_login_start']): ?>
    <?php $mLateAt = !empty($event['morning_late_time']) ? $event['morning_late_time'] : $event['morning_login_end']; ?>
    <span style="color:#74c0fc;">&#128197; Login&nbsp;<strong><?php echo date('g:i A',strtotime($event['morning_login_start'])); ?> &ndash; <?php echo date('g:i A',strtotime($event['morning_login_end']) + 900); ?></strong>&nbsp;<span style="color:#ffc107;font-size:11px;">(Late after <?php echo date('g:i A',strtotime($mLateAt)); ?>)</span></span>
    <?php endif; ?>
    <?php if($event && $showMorning && $event['morning_logout_start']): ?>
    <span style="color:#a9e34b;">&#128197; Logout&nbsp;<strong><?php echo date('g:i A',strtotime($event['morning_logout_start'])); ?> &ndash; <?php echo date('g:i A',strtotime($event['morning_logout_end']) + 900); ?></strong></span>
    <?php endif; ?>
    <?php if($event && $showAfternoon && $event['afternoon_login_start']): ?>
    <?php $aLateAt = !empty($event['afternoon_late_time']) ? $event['afternoon_late_time'] : $event['afternoon_login_end']; ?>
    <span style="color:#74c0fc;">&#9728; Login&nbsp;<strong><?php echo date('g:i A',strtotime($event['afternoon_login_start'])); ?> &ndash; <?php echo date('g:i A',strtotime($event['afternoon_login_end']) + 900); ?></strong>&nbsp;<span style="color:#ffc107;font-size:11px;">(Late after <?php echo date('g:i A',strtotime($aLateAt)); ?>)</span></span>
    <?php endif; ?>
    <?php if($event && $showAfternoon && $event['afternoon_logout_start']): ?>
    <span style="color:#a9e34b;">&#9728; Logout&nbsp;<strong><?php echo date('g:i A',strtotime($event['afternoon_logout_start'])); ?> &ndash; <?php echo date('g:i A',strtotime($event['afternoon_logout_end']) + 900); ?></strong></span>
    <?php endif; ?>
    <span id="windowStatus" style="margin-left:auto;font-weight:700;padding:3px 12px;border-radius:12px;background:rgba(255,255,255,0.1);">Checking…</span>
</div>

<div class="main">
<!-- ── Camera Section ── -->
<div class="cam-section">
    <div class="cam-card">
        <h2>📹 Live Camera Feed</h2>
        <div class="video-wrap">
            <video id="video" autoplay playsinline></video>
            <canvas id="bboxCanvas"></canvas>
            <div class="scan-overlay" id="scanOverlay">Click "Start Scanning" to begin</div>
        </div>
    </div>
    <div class="bottom-bar">
        <div class="controls">
            <button id="startBtn" class="btn btn-start">▶ Start</button>
            <button id="stopBtn"  class="btn btn-stop" disabled>⏹ Stop</button>
        </div>
        <div class="result-inline" id="resultBox">
            <div class="result-name" id="resultName">Awaiting scan…</div>
            <div class="result-status" id="resultStatus"></div>
        </div>
    </div>
</div>

<!-- ── Right Panel ── -->
<div class="right-section">
    <div class="panel-card">
        <h2>📊 Today's Statistics</h2>
        <div class="stats-grid">
            <div class="stat s-total">
                <h4>Total</h4>
                <div class="val" id="statTotal"><?php echo $stats['total']; ?></div>
            </div>
            <div class="stat s-present">
                <h4>Present</h4>
                <div class="val" id="statPresent"><?php echo $stats['present']; ?></div>
            </div>
            <div class="stat s-late">
                <h4>Late</h4>
                <div class="val" id="statLate"><?php echo $stats['late']; ?></div>
            </div>
            <div class="stat s-absent">
                <h4>Absent</h4>
                <div class="val" id="statAbsent"><?php echo $stats['absent']; ?></div>
            </div>
        </div>
    </div>
    <div class="scans-panel">
        <h2>🕐 Recent Scans</h2>
        <div class="scans-list" id="scansList">
            <div class="no-scans">No scans yet</div>
        </div>
    </div>
</div>
</div>

<script>
const video      = document.getElementById('video');
const bboxCanvas = document.getElementById('bboxCanvas');
const scanOverlay= document.getElementById('scanOverlay');
const startBtn   = document.getElementById('startBtn');
const stopBtn    = document.getElementById('stopBtn');
const scansList  = document.getElementById('scansList');
const resultBox  = document.getElementById('resultBox');
const resultName = document.getElementById('resultName');
const resultStatus=document.getElementById('resultStatus');

// ── Timing data (moved here so it's available before loadAlreadyScanned) ──
const _timing = <?php echo $timingJson; ?>;

// ── Server readiness polling ──────────────────────────────────────────────
let _serverReady   = false;
let _serverPollTmr = null;
let _autoScan      = true; // auto-start scanning when both camera + server ready

function beginScanning(){
    if(isScanning || !_autoScan) return;
    if(!stream || !ctx){
        setTimeout(beginScanning, 500); // camera not ready yet — retry
        return;
    }

    // No event today = Recognition Only mode.
    // With an event, the normal attendance time-window rules apply.
    const recognitionOnly = <?php echo $event_id ? 'false' : 'true'; ?>;

    if(!recognitionOnly && !isAnyWindowOpen()){
        scanOverlay.textContent = '✅ Camera ready – click "Start Scanning"';
        return;
    }

    isScanning = true;
    startBtn.disabled = true;
    stopBtn.disabled  = false;
    scanOverlay.textContent = recognitionOnly
        ? '🔍 Recognition Only – Scanning…'
        : '🔍 Scanning…';
    startFaceDetectionLoop();
    scanTimer = setInterval(doScan, 1500);
    doScan();
}

async function pollServerReady(){
    let d;
    try {
        const r = await fetch('face_recognition_scan.php?server_status=1');
        d = await r.json();
    } catch(e){
        scanOverlay.textContent = '⚙️ Step 1/4: Starting face server…';
        // Auto-start the server
        try {
            await fetch('start_server.php');
        } catch(startErr) {
            // Silent fail, will retry on next poll
        }
        return;
    }

    if(d.offline){
        scanOverlay.textContent = '⚙️ Step 1/4: Starting face server…';
        // Auto-start the server
        try {
            await fetch('start_server.php');
        } catch(startErr) {
            // Silent fail, will retry on next poll
        }
        return;
    }

    const lbph   = d.models?.lbph      === true;
    const fr     = d.models?.fr_helper === true;
    const loading= d.models?.loading    !== false;

    if(loading && !lbph){
        scanOverlay.textContent = '⚙️ Step 2/4: Loading Haar Cascade & LBPH model…';
    } else if(loading && lbph && !fr){
        scanOverlay.textContent = '⚙️ Step 4/4: Loading face_recognition helper (optional)…';
    } else if(!loading && lbph){
        // ✅ LBPH ready - allow scanning (face_recognition is optional)
        clearInterval(_serverPollTmr);
        _serverPollTmr = null;
        _serverReady   = true;
        startBtn.disabled = false;
        await startCamera();
        beginScanning(); // auto-start if camera already loaded
        return;
    } else if(!loading && !lbph){
        // Server loaded but LBPH failed - show error
        clearInterval(_serverPollTmr);
        _serverPollTmr = null;
        scanOverlay.textContent = '⚠️ LBPH model failed to load. Please train models first.';
        return;
    }
}

function startServerPolling(){
    if(_serverPollTmr) return;
    pollServerReady();
    _serverPollTmr = setInterval(pollServerReady, 2000);
}

let stream       = null;
let isScanning   = false;
let scanTimer    = null;
const cooldowns  = {};                    // studentId -> timestamp of last scan
const COOLDOWN_MS         = 30000;        // 30 s within-session re-scan window
const ALREADY_SCANNED_MS  = 1000*60*60*8; // 8 h — effectively "already done today"
let ctx          = null;

// ── Determine which attendance window is currently open (includes +15 min grace) ──
function getCurrentWindow(){
    function inW(startHMS, endHMS){
        if(!startHMS || !endHMS) return false;
        const now = new Date();
        const s = fmtT(startHMS);
        const e = fmtTPlus(endHMS, 15); // +15 min grace
        return s && e && now >= s && now <= e;
    }
    if(inW(_timing.morning_login_start,    _timing.morning_login_end))    return 'morning_login';
    if(inW(_timing.morning_logout_start,   _timing.morning_logout_end))   return 'morning_logout';
    if(inW(_timing.afternoon_login_start,  _timing.afternoon_login_end))  return 'afternoon_login';
    if(inW(_timing.afternoon_logout_start, _timing.afternoon_logout_end)) return 'afternoon_logout';
    return 'any';
}

// ── Pre-load already-scanned IDs for the CURRENT window only ─────────────
//    Resets persisted marks each refresh so a new event or new window
//    never keeps old blocks alive (fixes new-event & logout-after-login bugs)
async function loadAlreadyScanned(){
    try {
        const win = getCurrentWindow();
        const r   = await fetch('face_recognition_scan.php?scanned_ids=1&window=' + win);
        const ids = await r.json();
        if(Array.isArray(ids)){
            // Clear ALL old persisted marks before rebuilding from DB
            for(const k of Object.keys(cooldowns)){
                if(String(k).endsWith('_persisted')) delete cooldowns[k];
            }
            const stamp = Date.now() - 1000;
            ids.forEach(id => { cooldowns[id] = stamp; cooldowns[id+'_persisted'] = true; });
        }
    } catch(e){ /* silent */ }
}
loadAlreadyScanned();
setInterval(loadAlreadyScanned, 10000); // refresh every 10 s

// ── Camera ─────────────────────────────────────────────────────────────────────
async function startCamera(){
    if(stream) return; // already running — do not restart
    // Try front-facing (user) camera first; fall back to any available camera
    const constraints = [
        { video: { width:{ideal:640}, height:{ideal:480}, facingMode:'user' } },
        { video: { width:{ideal:640}, height:{ideal:480} } },
        { video: true }
    ];
    for(const c of constraints){
        try {
            stream = await navigator.mediaDevices.getUserMedia(c);
            break;
        } catch(e){
            if(c === constraints[constraints.length - 1]){
                scanOverlay.textContent = '❌ Camera error: ' + e.message;
                return;
            }
        }
    }
    video.srcObject = stream;
    video.onloadedmetadata = () => {
        video.play();
        bboxCanvas.width  = video.videoWidth  || 640;
        bboxCanvas.height = video.videoHeight || 480;
        ctx = bboxCanvas.getContext('2d');
        scanOverlay.textContent = '✅ Camera ready – click “Start Scanning”';
    };
}

// ── Capture frame ─────────────────────────────────────────────────────────
function captureFrame(){
    const tmp = document.createElement('canvas');
    tmp.width  = video.videoWidth;
    tmp.height = video.videoHeight;
    tmp.getContext('2d').drawImage(video, 0, 0);
    return tmp.toDataURL('image/jpeg', 0.85).split(',')[1];
}

// ── Draw bounding box ─────────────────────────────────────────────────────
function drawBbox(bbox, matched){
    if(!ctx) return;
    ctx.clearRect(0, 0, bboxCanvas.width, bboxCanvas.height);
    if(!bbox) return;

    const color = matched ? '#00ff88' : '#FFD700';
    const mx    = bboxCanvas.width - bbox.x - bbox.w;  // mirror

    ctx.save();
    ctx.shadowColor = color; ctx.shadowBlur = 20;
    ctx.strokeStyle = color; ctx.lineWidth = 3;
    ctx.strokeRect(mx, bbox.y, bbox.w, bbox.h);
    ctx.shadowBlur = 0;

    // Corner marks
    const cs = 16; ctx.lineWidth = 4;
    [[mx,bbox.y],[mx+bbox.w-cs,bbox.y],[mx,bbox.y+bbox.h-cs],[mx+bbox.w-cs,bbox.y+bbox.h-cs]]
    .forEach(([bx,by])=>{
        ctx.beginPath();
        ctx.moveTo(bx+cs,by); ctx.lineTo(bx,by); ctx.lineTo(bx,by+cs);
        ctx.stroke();
    });
    ctx.restore();
}

// ── Face pre-detection pipeline (avoids API calls when no face in frame) ──
let _nativeFD = null;
let _faceVisible = !('FaceDetector' in window); // true=always call API if no native FD

if('FaceDetector' in window){
    try { _nativeFD = new FaceDetector({fastMode:true, maxDetectedFaces:1}); } catch(e){}
}

function startFaceDetectionLoop(){
    if(!_nativeFD){ _faceVisible = true; return; }
    const tick = async () => {
        if(video.readyState === 4){
            try {
                const faces = await _nativeFD.detect(video);
                _faceVisible = !!(faces && faces.length > 0);
                if(!_faceVisible && isScanning){
                    scanOverlay.textContent = '👁️ No face – look at camera';
                    ctx && ctx.clearRect(0,0,bboxCanvas.width,bboxCanvas.height);
                }
            } catch(e){ _faceVisible = true; }
        }
        requestAnimationFrame(tick);
    };
    tick();
}

// ── Consensus & attendance ────────────────────────────────────────────────
async function recordAttendance(studentId){
    try {
        const fd = new FormData();
        fd.append('student_id', studentId);
        const res  = await fetch('scan_attendance.php', {method:'POST', body:fd});
        return await res.json();
    } catch(e){
        return {success:false, message:'Network error'};
    }
}

function addScanItem(name, status, algoSummary, time){
    const no = document.querySelector('.no-scans');
    if(no) no.remove();

    const div = document.createElement('div');
    div.className = 'scan-item' + (status==='Late' ? ' late' : status==='Error' ? ' error' : '');
    const badge = status==='Present' ? 'badge-present' : status==='Late' ? 'badge-late' : 'badge-error';
    div.innerHTML = `
        <div class="scan-row">
            <div class="scan-name">${name}</div>
            <div class="scan-time">${time}</div>
        </div>
        <div class="scan-row" style="margin-top:4px;">
            <div class="scan-conf">${algoSummary}</div>
            <span class="badge ${badge}">${status}</span>
        </div>`;
    scansList.insertBefore(div, scansList.firstChild);
    while(scansList.children.length > 30) scansList.removeChild(scansList.lastChild);
}

function refreshStats(){
    fetch('face_recognition_scan.php?stats_only=1')
        .then(r=>r.json())
        .then(d=>{
            if(!d) return;
            document.getElementById('statTotal').textContent   = d.total   ?? '–';
            document.getElementById('statPresent').textContent = d.present ?? '–';
            document.getElementById('statLate').textContent    = d.late    ?? '–';
            document.getElementById('statAbsent').textContent  = d.absent  ?? '–';
        }).catch(()=>{});
}

// ── Main scan tick ────────────────────────────────────────────────────────
let scanInFlight = false;
async function doScan(){
    if(!isScanning || !stream) return;
    if(scanInFlight) return;
    // Pipeline: skip expensive API call if native FD sees no face
    if(!_faceVisible) return;
    scanInFlight = true;

    const b64 = captureFrame();
    const fd  = new FormData();
    fd.append('image', b64);

    let data;
    try {
        const res = await fetch('face_recognize_api.php', {method:'POST', body:fd});
        data = await res.json();
    } catch(e){
        scanOverlay.textContent = '⚠️ API error';
        scanInFlight = false;
        return;
    } finally {
        // scanInFlight released after full processing below
    }

    if(data.error && !data.bbox){
        scanOverlay.textContent = '⚠️ ' + data.error;
        ctx && ctx.clearRect(0,0,bboxCanvas.width,bboxCanvas.height);
        scanInFlight = false;
        return;
    }

    // Draw bbox
    drawBbox(data.bbox, !!(data.lbph?.matched));

    if(data.faces_count === 0){
        scanOverlay.textContent = '👁️ No face detected – please look at camera';
        resetCards();
        scanInFlight = false;
        return;
    }

    // ── Recognition: single LBPH result (face_recognition boosts internally) ───
    let consensusId = null, consensusName = null;

    const lbph = data.lbph;
    if(lbph && !lbph.error && lbph.matched && lbph.id > 0 && lbph.confidence >= 65){
        consensusId   = lbph.id;
        consensusName = lbph.name || ('ID:' + lbph.id);
    }

    if(!consensusId){
        const c = lbph?.confidence;
        
        // Build algorithm summary even for low confidence
        let algoInfo = '';
        const algo = lbph?.algorithm || 'lbph';
        const lbphConf = lbph?.lbph_confidence || 0;
        const cnnConf = lbph?.cnn_confidence || 0;
        
        if (algo === 'dlib_cnn') {
            algoInfo = `CNN: ${cnnConf.toFixed(1)}%`;
            if (lbphConf > 0) algoInfo += ` | LBPH: ${lbphConf.toFixed(1)}%`;
        } else {
            algoInfo = `LBPH: ${lbphConf.toFixed(1)}%`;
            if (cnnConf > 0) algoInfo += ` | CNN: ${cnnConf.toFixed(1)}%`;
        }
        
        scanOverlay.textContent = `❓ Face detected – ${algoInfo} (need ≥65%)`;
        resultBox.className='result-inline fail';
        resultName.textContent = 'Not recognized';
        resultStatus.textContent = (c != null && !lbph?.error)
            ? `${algoInfo} – need ≥65% for match`
            : (lbph?.error || 'No match found');
        scanInFlight = false;
        return;
    }

    // Cooldown / duplicate check ─ enforces no-duplicate rule in live scan
    const now = Date.now();
    if(cooldowns[consensusId+'_persisted']){
        // Already recorded today (loaded from DB) — hard block
        scanOverlay.textContent = `✅ ${consensusName} – already recorded today`;
        resultBox.className='result-inline';
        resultName.textContent = consensusName;
        resultStatus.textContent = 'Already scanned today – no duplicate allowed';
        scanInFlight = false;
        return;
    }
    if(cooldowns[consensusId] && now - cooldowns[consensusId] < COOLDOWN_MS){
        const remaining = Math.ceil((COOLDOWN_MS - (now - cooldowns[consensusId]))/1000);
        scanOverlay.textContent = `⏳ ${consensusName} – wait ${remaining}s`;
        resultBox.className='result-inline';
        resultName.textContent = consensusName;
        resultStatus.textContent = `Already scanned – cooldown ${remaining}s`;
        scanInFlight = false;
        return;
    }

    // Record attendance
    scanOverlay.textContent = `✅ Recognized: ${consensusName} – recording…`;
    const att = await recordAttendance(consensusId);

    const conf = (lbph && !lbph.error) ? Math.round(lbph.confidence || 0) : 0;
    const quality = conf >= 90 ? 'Excellent' : conf >= 75 ? 'Good' : conf >= 60 ? 'Fair' : 'Low';
    
    // Build algorithm summary showing hybrid system details
    let algoSummary = '';
    const algo = lbph?.algorithm || 'lbph';
    const lbphConf = lbph?.lbph_confidence || 0;
    const cnnConf = lbph?.cnn_confidence || 0;
    
    if (algo === 'dlib_cnn') {
        // dlib CNN was used (more accurate)
        algoSummary = `CNN: ${cnnConf.toFixed(1)}%`;
        if (lbph?.boosted) {
            algoSummary += ` (Boosted by LBPH: ${lbphConf.toFixed(1)}%)`;
        } else if (lbphConf > 0) {
            algoSummary += ` | LBPH: ${lbphConf.toFixed(1)}%`;
        }
    } else if (algo === 'lbph') {
        // LBPH was used
        algoSummary = `LBPH: ${lbphConf.toFixed(1)}% (${quality})`;
        if (cnnConf > 0) {
            algoSummary += ` | CNN: ${cnnConf.toFixed(1)}%`;
            if (lbph?.disagreement) {
                algoSummary += ' [Disagreement]';
            }
        }
    } else {
        algoSummary = `Confidence: ${conf}% (${quality})`;
    }

    if(att.success){
        cooldowns[consensusId] = now;
        cooldowns[consensusId+'_persisted'] = true;
        const status = att.status || 'Present';
        scanOverlay.textContent = `🎉 ${att.student_name} – ${status}`;
        resultBox.className='result-inline success';
        resultName.textContent = att.student_name;
        resultStatus.textContent = `${status} · ${new Date().toLocaleTimeString()} · ${algoSummary}`;
        addScanItem(att.student_name, status, algoSummary, new Date().toLocaleTimeString());
        refreshStats();
    } else {
        scanOverlay.textContent = `⚠️ ${att.message}`;
        resultBox.className='result-inline fail';
        resultName.textContent = consensusName;
        resultStatus.textContent = att.message;
        if(att.message?.includes('already scanned')){
            cooldowns[consensusId] = now;
            cooldowns[consensusId+'_persisted'] = true;
        }
    }
    scanInFlight = false;
}

// ── Toast notification ────────────────────────────────────────────────────
let _toastTimer = null;
function showToast(msg, ms=5000){
    let t = document.getElementById('scanToast');
    if(!t){
        t = document.createElement('div');
        t.id = 'scanToast';
        t.style.cssText = [
            'position:fixed','bottom:80px','left:50%',
            'transform:translateX(-50%) translateY(20px)',
            'background:rgba(20,20,20,0.95)','color:#FFD700',
            'padding:12px 22px','border-radius:12px','font-size:13px',
            'font-weight:600','z-index:9999','pointer-events:none',
            'border:1px solid rgba(255,215,0,0.35)',
            'box-shadow:0 4px 20px rgba(0,0,0,0.5)',
            'transition:opacity .3s,transform .3s',
            'opacity:0','max-width:90vw','text-align:center'
        ].join(';');
        document.body.appendChild(t);
    }
    t.textContent = msg;
    requestAnimationFrame(()=>{
        t.style.opacity='1'; t.style.transform='translateX(-50%) translateY(0)';
    });
    clearTimeout(_toastTimer);
    _toastTimer = setTimeout(()=>{
        t.style.opacity='0'; t.style.transform='translateX(-50%) translateY(20px)';
    }, ms);
}

function resetCards(){
    resultBox.className='result-inline';
    resultName.textContent='Awaiting scan…';
    resultStatus.textContent='';
}

// ── Controls ──────────────────────────────────────────────────────────────
startBtn.addEventListener('click', ()=>{
    if(!stream) return;

    // No event today = Recognition Only mode.
    // Allow face recognition to run, but attendance recording remains disabled.
    const recognitionOnly = <?php echo $event_id ? 'false' : 'true'; ?>;

    if(!recognitionOnly && !isAnyWindowOpen()){
        const info = getNextWindowInfo();
        scanOverlay.textContent = '🚫 Not time yet';
        resultBox.className = 'result-inline fail';
        resultName.textContent = 'Scanning blocked';
        resultStatus.textContent = info;
        showToast('⏳ ' + info, 4000);
        return;
    }

    _autoScan = true;
    beginScanning();
});

stopBtn.addEventListener('click', ()=>{
    isScanning = false;
    _autoScan  = false; // manual stop — don't auto-restart
    clearInterval(scanTimer);
    scanTimer = null;
    startBtn.disabled = false;
    stopBtn.disabled  = true;
    scanOverlay.textContent = 'Scanning stopped – click Start to resume';
    ctx && ctx.clearRect(0,0,bboxCanvas.width,bboxCanvas.height);
    _faceVisible = false;
    resetCards();
});

startCamera();
startServerPolling(); // poll until server ready → auto-starts scanning

// ── Login window guard ───────────────────────────────────────────────────

// Robust time parser: handles HH:MM, HH:MM:SS — returns today's Date at that hour/minute
function fmtT(hms){
    if(!hms || typeof hms !== 'string') return null;
    const parts = hms.split(':');
    if(parts.length < 2) return null;
    const h = parseInt(parts[0], 10);
    const m = parseInt(parts[1], 10);
    if(isNaN(h) || isNaN(m)) return null;
    const d = new Date();
    d.setHours(h, m, 0, 0);
    return d;
}

// Shift a parsed time by addMins minutes (used for 15-min grace extension)
function fmtTPlus(hms, addMins){
    const t = fmtT(hms);
    if(!t) return null;
    return new Date(t.getTime() + addMins * 60000);
}

// Build window list respecting event_type
// Any type that is NOT Morning Only / Afternoon Only is treated as Whole Day
function buildWindows(typeMap){
    const et = _timing.event_type || '';
    const showMorning   = (et !== 'Afternoon Only');
    const showAfternoon = (et !== 'Morning Only');
    const windows = [];
    if(showMorning){
        if(typeMap.morning_login)  windows.push({label:'Morning Login',  s:fmtT(_timing.morning_login_start),  e:fmtTPlus(_timing.morning_login_end,  15), type:'login',  lateAfter:fmtT(_timing.morning_late_time || _timing.morning_login_end)});
        if(typeMap.morning_logout) windows.push({label:'Morning Logout', s:fmtT(_timing.morning_logout_start), e:fmtTPlus(_timing.morning_logout_end, 15), type:'logout'});
    }
    if(showAfternoon){
        if(typeMap.afternoon_login)  windows.push({label:'Afternoon Login',  s:fmtT(_timing.afternoon_login_start),  e:fmtTPlus(_timing.afternoon_login_end,  15), type:'login',  lateAfter:fmtT(_timing.afternoon_late_time || _timing.afternoon_login_end)});
        if(typeMap.afternoon_logout) windows.push({label:'Afternoon Logout', s:fmtT(_timing.afternoon_logout_start), e:fmtTPlus(_timing.afternoon_logout_end, 15), type:'logout'});
    }
    return windows.filter(w=>w.s && w.e);
}

function getAllWindows(){
    return buildWindows({morning_login:true,morning_logout:true,afternoon_login:true,afternoon_logout:true});
}

function nowClean(){
    const d = new Date();
    d.setMilliseconds(0);
    return d;
}

function isAnyWindowOpen(){
    const now = nowClean();
    return getAllWindows().some(w => now >= w.s && now <= w.e);
}

function getNextWindowInfo(){
    const now = nowClean();
    const allWindows = buildWindows({morning_login:true,morning_logout:true,afternoon_login:true,afternoon_logout:true});

    const active = allWindows.find(w => now >= w.s && now <= w.e);
    if(active) return active.label + ' is open now';

    const upcoming = allWindows.filter(w=> now < w.s).sort((a,b)=>a.s-b.s)[0];
    if(upcoming){
        const diff = Math.round((upcoming.s - now)/60000);
        return 'Next: ' + upcoming.label + ' in ' + diff + 'm';
    }
    return 'All windows have ended for today';
}

function checkWindow(){
    const now = nowClean();
    const ws  = document.getElementById('windowStatus');
    if(!ws) return;

    const allWindows = buildWindows({morning_login:true,morning_logout:true,afternoon_login:true,afternoon_logout:true});

    const active = allWindows.find(w => now >= w.s && now <= w.e);

    if(active){
        const inLateZone = active.type === 'login' && active.lateAfter && now > active.lateAfter;
        ws.textContent = inLateZone
            ? '� ' + active.label + ' – Late Zone (+15m)'
            : '�🟢 ' + active.label + ' Open';
        ws.style.background = inLateZone ? 'rgba(255,193,7,0.25)' : (active.type==='login' ? 'rgba(40,167,69,0.35)' : 'rgba(100,180,60,0.35)');
        ws.style.color = inLateZone ? '#ffc107' : '#a9e34b';
    } else {
        const upcoming = allWindows.filter(w=> now < w.s).sort((a,b)=>a.s-b.s)[0];
        if(upcoming){
            const diff = Math.round((upcoming.s - now)/60000);
            ws.textContent = '🟡 Next: ' + upcoming.label + ' in ' + diff + 'm';
            ws.style.background = 'rgba(255,165,0,0.2)';
            ws.style.color = '#ffa500';
        } else {
            ws.textContent = '🔴 All windows closed';
            ws.style.background = 'rgba(220,53,69,0.2)';
            ws.style.color = '#ff6b6b';
        }
    }

    // Disable Start button visually only when an event exists but its
    // attendance window is closed. With no event, Recognition Only mode
    // must remain available.
    const recognitionOnly = <?php echo $event_id ? 'false' : 'true'; ?>;
    if(!recognitionOnly && !isAnyWindowOpen() && !isScanning){
        startBtn.style.opacity = '0.4';
        startBtn.style.cursor = 'not-allowed';
        startBtn.title = '⏳ ' + getNextWindowInfo();
    } else {
        startBtn.style.opacity = '1';
        startBtn.style.cursor = 'pointer';
        startBtn.title = recognitionOnly ? 'Recognition Only – no event today' : '';
    }
}
checkWindow();
setInterval(checkWindow, 10000);

window.addEventListener('beforeunload', ()=>{
    clearInterval(scanTimer);
    if(stream) stream.getTracks().forEach(t=>t.stop());
});
</script>
</body>
</html>
