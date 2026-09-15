<?php
date_default_timezone_set('Asia/Manila');
session_start();

if(!isset($_SESSION['admin_id'])){
    header("Location: admin_login.php");
    exit;
}

$conn = new mysqli("localhost","root","","attendance");

if($conn->connect_error){
    die("Connection failed: " . $conn->connect_error);
}

if(file_exists("auto_absent.php")){
    include("auto_absent.php");
}

$adminName = $_SESSION['admin_name'] ?? 'Admin';
$role = $_SESSION['role'] ?? 'admin';

/* CURRENT EVENT — same row the scan page reads */

$event = $conn->query("
SELECT *
FROM events
WHERE event_date = CURDATE()
ORDER BY id DESC
LIMIT 1
");

$eventData = $event->fetch_assoc();

$event_id = $eventData['id'] ?? 0;
$event_name = $eventData['event_name'] ?? 'No Event Scheduled';

/* COUNTS */

$totalStudents = $conn->query("
SELECT COUNT(*) total
FROM users
WHERE role='student'
")->fetch_assoc()['total'] ?? 0;

$registeredStudents = $conn->query("
SELECT COUNT(*) total
FROM users
WHERE role='student'
AND face_registered=1
")->fetch_assoc()['total'] ?? 0;

$pendingStudents = $totalStudents - $registeredStudents;

/* ATTENDANCE */

$totalPresent = 0;
$totalLate = 0;
$totalAbsent = 0;

if($event_id){

$totalPresent = $conn->query("
SELECT COUNT(*) total
FROM attendance
WHERE event_id='$event_id'
AND (morning_status='Present' OR afternoon_status='Present')
")->fetch_assoc()['total'] ?? 0;

$totalLate = $conn->query("
SELECT COUNT(*) total
FROM attendance
WHERE event_id='$event_id'
AND (morning_status='Late' OR afternoon_status='Late')
AND morning_status!='Present' AND afternoon_status!='Present'
")->fetch_assoc()['total'] ?? 0;

$totalAbsent = $conn->query("
SELECT COUNT(*) total
FROM attendance
WHERE event_id='$event_id'
AND (morning_status='Absent' OR morning_status IS NULL)
AND (afternoon_status='Absent' OR afternoon_status IS NULL)
")->fetch_assoc()['total'] ?? 0;

}

/* PAYMENTS STATS */
$pendingPaymentsCount = $conn->query("
SELECT COUNT(*) total FROM payments WHERE status='Pending'
")->fetch_assoc()['total'] ?? 0;

$outstandingTotal = $conn->query("
SELECT COALESCE(SUM(
    CASE
        WHEN (a.morning_status='Absent' OR a.afternoon_status='Absent') AND (a.penalty IS NULL OR a.penalty=0) THEN e.absent_penalty
        WHEN (a.morning_status='Late' OR a.afternoon_status='Late')    AND (a.penalty IS NULL OR a.penalty=0) THEN e.late_penalty
        ELSE a.penalty
    END
),0) - COALESCE((SELECT SUM(amount) FROM payments WHERE status='Confirmed'),0) as total
FROM attendance a
JOIN events e ON a.event_id = e.id
JOIN users u ON a.student_id = u.id WHERE u.role='student'
")->fetch_assoc()['total'] ?? 0;

/* RECENT EVENTS */

$recentEvents = $conn->query("
SELECT *
FROM events
ORDER BY created_at DESC
LIMIT 10
");

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport"
content="width=device-width, initial-scale=1.0">

<title>Admin Dashboard</title>

<style>

*{
margin:0;
padding:0;
box-sizing:border-box;
font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;
}

body{
background:linear-gradient(
135deg,
rgba(0,0,0,0.95),
rgba(0,0,0,0.85)
);
min-height:100vh;
color:white;
overflow-x:hidden;
}

/* SIDEBAR */

.sidebar{
width:280px;
height:100vh;
position:fixed;
left:0;
top:0;
padding:25px;
overflow:auto;
background:rgba(0,0,0,0.9);
backdrop-filter:blur(20px);
border-right:2px solid rgba(255,215,0,0.3);
box-shadow:10px 0 30px rgba(0,0,0,0.5);
z-index:1000;
}

.logo-box{
background:linear-gradient(
135deg,
rgba(255,215,0,0.2),
rgba(255,165,0,0.1)
);
border:2px solid rgba(255,215,0,0.3);
padding:20px;
border-radius:20px;
text-align:center;
margin-bottom:25px;
}

.logo-box h2{
font-size:24px;
font-weight:900;
background:linear-gradient(
45deg,
#FFD700,
#FFA500
);
-webkit-background-clip:text;
-webkit-text-fill-color:transparent;
}

.logo-box p{
font-size:14px;
color:rgba(255,255,255,0.8);
margin-top:8px;
}

.admin-box{
background:rgba(255,215,0,0.1);
border:1px solid rgba(255,215,0,0.3);
padding:18px;
border-radius:18px;
text-align:center;
margin-bottom:25px;
}

.admin-box h4{
font-size:20px;
margin-bottom:8px;
color:#FFD700;
font-weight:800;
}

.badge{
display:inline-block;
padding:8px 16px;
border-radius:25px;
background:linear-gradient(
45deg,
#FFD700,
#FFA500
);
color:#000;
font-size:12px;
font-weight:800;
}

.sidebar a{
display:block;
padding:16px 20px;
margin-bottom:12px;
border-radius:15px;
text-decoration:none;
font-weight:700;
color:rgba(255,255,255,0.8);
background:rgba(255,255,255,0.05);
transition:0.3s;
}

.sidebar a:hover,
.sidebar a.active{
background:rgba(255,215,0,0.2);
color:#FFD700;
}

/* MAIN */

.main{
margin-left:280px;
padding:30px;
}

/* TOPBAR */

.topbar{
background:rgba(0,0,0,0.8);
backdrop-filter:blur(20px);
border:2px solid rgba(255,215,0,0.3);
border-radius:25px;
padding:30px;
margin-bottom:30px;
box-shadow:0 15px 35px rgba(0,0,0,0.5);
}

.topbar h1{
font-size:36px;
font-weight:900;
background:linear-gradient(
45deg,
#FFD700,
#FFA500
);
-webkit-background-clip:text;
-webkit-text-fill-color:transparent;
margin-bottom:10px;
}

.topbar p{
font-size:16px;
color:rgba(255,255,255,0.8);
}

.top-actions{
display:flex;
gap:15px;
flex-wrap:wrap;
align-items:center;
margin-top:15px;
}

.status{
padding:12px 20px;
background:rgba(255,193,7,0.2);
border:1px solid rgba(255,215,0,0.4);
border-radius:20px;
font-weight:700;
color:#FFD700;
}

.face-btn{
display:inline-flex;
align-items:center;
justify-content:center;
padding:12px 22px;
border-radius:20px;
background:linear-gradient(
45deg,
#FFD700,
#FFA500
);
color:black;
font-weight:800;
text-decoration:none;
transition:0.3s;
box-shadow:0 5px 15px rgba(255,215,0,0.3);
}

.face-btn:hover{
transform:translateY(-2px);
}

/* ANALYTICS */

.analytics-grid{
display:grid;
grid-template-columns:
repeat(auto-fit,minmax(250px,1fr));
gap:25px;
margin-bottom:35px;
}

.analytics-card{
background:rgba(0,0,0,0.8);
backdrop-filter:blur(20px);
border:2px solid rgba(255,215,0,0.3);
border-radius:25px;
padding:30px;
box-shadow:0 15px 35px rgba(0,0,0,0.5);
}

.analytics-card h3{
font-size:16px;
color:rgba(255,255,255,0.8);
margin-bottom:15px;
font-weight:700;
}

.analytics-value{
font-size:42px;
font-weight:900;
background:linear-gradient(
45deg,
#FFD700,
#FFA500
);
-webkit-background-clip:text;
-webkit-text-fill-color:transparent;
}

/* CONTENT */

.content-grid{
display:grid;
grid-template-columns:1fr;
gap:30px;
}

.panel{
background:rgba(0,0,0,0.8);
backdrop-filter:blur(20px);
border:2px solid rgba(255,215,0,0.3);
border-radius:25px;
padding:30px;
box-shadow:0 15px 35px rgba(0,0,0,0.5);
}

.panel h2{
font-size:24px;
margin-bottom:25px;
background:linear-gradient(
45deg,
#FFD700,
#FFA500
);
-webkit-background-clip:text;
-webkit-text-fill-color:transparent;
font-weight:800;
}

/* TABLE */

.table-scroll{
overflow-x:auto;
}

.data-table{
width:100%;
border-collapse:collapse;
min-width:700px;
}

.data-table th{
background:rgba(255,215,0,0.1);
border:1px solid rgba(255,215,0,0.3);
padding:15px;
text-align:left;
font-weight:700;
color:#FFD700;
}

.data-table td{
border:1px solid rgba(255,215,0,0.1);
padding:15px;
color:rgba(255,255,255,0.8);
}

.data-table tr:hover{
background:rgba(255,215,0,0.05);
}

/* TRAINING PANEL */

.train-panel{
background:rgba(0,0,0,0.85);
backdrop-filter:blur(20px);
border:2px solid rgba(255,215,0,0.35);
border-radius:22px;
padding:24px 28px;
margin-bottom:28px;
}

.train-panel.hidden{ display:none; }

.train-header{
display:flex;
align-items:center;
justify-content:space-between;
flex-wrap:wrap;
gap:12px;
margin-bottom:16px;
}

.train-title{
font-size:18px;
font-weight:800;
background:linear-gradient(45deg,#FFD700,#FFA500);
-webkit-background-clip:text;
-webkit-text-fill-color:transparent;
}

.train-msg{
font-size:14px;
color:rgba(255,255,255,0.75);
margin-bottom:14px;
min-height:20px;
}

.train-bar-wrap{
background:rgba(255,255,255,0.08);
border-radius:12px;
height:22px;
position:relative;
overflow:hidden;
margin-bottom:18px;
}

.train-bar-fill{
height:100%;
border-radius:12px;
background:linear-gradient(90deg,#FFD700,#FFA500);
transition:width 0.5s ease;
width:0%;
}

.train-bar-fill.done{ background:linear-gradient(90deg,#28a745,#20c997); }
.train-bar-fill.error{ background:linear-gradient(90deg,#dc3545,#e91e63); }

.train-bar-pct{
position:absolute;
top:50%;
left:50%;
transform:translate(-50%,-50%);
font-size:12px;
font-weight:800;
color:#000;
}

.train-models{
display:grid;
grid-template-columns:repeat(3,1fr);
gap:12px;
margin-bottom:16px;
}

.tm-card{
background:rgba(255,255,255,0.05);
border:1px solid rgba(255,255,255,0.1);
border-radius:14px;
padding:12px;
text-align:center;
transition:all 0.3s;
}

.tm-card.active{
border-color:#FFD700;
background:rgba(255,215,0,0.1);
animation:tmPulse 1s infinite alternate;
}

.tm-card.ok{ border-color:#28a745; background:rgba(40,167,69,0.12); }
.tm-card.fail{ border-color:#dc3545; background:rgba(220,53,69,0.1); }
.tm-card.skip{ border-color:#6c757d; background:rgba(108,117,125,0.1); }

@keyframes tmPulse{ from{opacity:1;} to{opacity:0.65;} }

.tm-icon{ font-size:24px; margin-bottom:4px; }
.tm-name{ font-size:12px; font-weight:700; color:rgba(255,255,255,0.85); margin-bottom:4px; }
.tm-status{ font-size:11px; color:rgba(255,255,255,0.55); }

.train-actions{
display:flex;
gap:12px;
flex-wrap:wrap;
align-items:center;
}

.btn-retrain{
padding:10px 22px;
border:none;
border-radius:14px;
background:linear-gradient(45deg,#FFD700,#FFA500);
color:#000;
font-weight:800;
font-size:14px;
cursor:pointer;
transition:0.3s;
}

.btn-retrain:hover{ transform:translateY(-2px); }
.btn-retrain:disabled{ background:rgba(255,255,255,0.15); color:rgba(255,255,255,0.4); cursor:not-allowed; transform:none; }

.btn-dismiss{
padding:10px 18px;
border:1px solid rgba(255,255,255,0.2);
border-radius:14px;
background:rgba(255,255,255,0.07);
color:rgba(255,255,255,0.7);
font-size:14px;
cursor:pointer;
transition:0.3s;
}

.btn-dismiss:hover{ background:rgba(255,255,255,0.12); }

.train-elapsed{
font-size:12px;
color:rgba(255,255,255,0.4);
}

/* MOBILE */

.menu-toggle{
display:none;
position:fixed;
top:20px;
left:20px;
z-index:2001;
background:linear-gradient(
45deg,
#FFD700,
#FFA500
);
border:none;
border-radius:50%;
width:50px;
height:50px;
cursor:pointer;
color:#000;
font-size:20px;
font-weight:bold;
}

.overlay{
display:none;
position:fixed;
inset:0;
background:rgba(0,0,0,0.6);
z-index:1999;
}

.overlay.active{
display:block;
}

@media(max-width:768px){

.menu-toggle{
display:block;
}

.sidebar{
left:-280px;
transition:0.3s;
}

.sidebar.active{
left:0;
}

.main{
margin-left:0;
padding:20px;
}

.topbar{
padding:20px;
}

.topbar h1{
font-size:26px;
padding-left:60px;
}

.top-actions{
flex-direction:column;
align-items:stretch;
gap:10px;
}

.status{
text-align:center;
}

.face-btn{
width:100%;
justify-content:center;
}

.analytics-grid{
grid-template-columns:1fr 1fr;
gap:12px;
}

.analytics-value{
font-size:28px;
}

.analytics-card{
padding:20px;
}

.analytics-card h3{
font-size:13px;
}

.train-models{
grid-template-columns:repeat(2,1fr);
}

.train-actions{
flex-direction:column;
align-items:stretch;
}

.btn-retrain,.btn-dismiss{
width:100%;
text-align:center;
}

.panel{
padding:22px;
}

}

@media(max-width:480px){

.analytics-grid{
grid-template-columns:1fr 1fr;
}

.main{
padding:12px;
}

.panel{
padding:16px;
}

.topbar{
padding:16px;
}

.topbar h1{
font-size:22px;
}

.train-models{
grid-template-columns:1fr;
}

}

</style>

<script>

function toggleSidebar(){

document.querySelector('.sidebar')
.classList.toggle('active');

document.querySelector('.overlay')
.classList.toggle('active');

}

</script>

</head>

<body>

<button class="menu-toggle"
onclick="toggleSidebar()">

☰

</button>

<div class="overlay"
onclick="toggleSidebar()"></div>

<!-- SIDEBAR -->

<div class="sidebar">

<div class="logo-box">

<h2>CICS ADMIN</h2>

<img src="assets/images/cics_logo.png" alt="CICS Logo" style="width:120px;height:120px;margin:10px auto;display:block;border-radius:10px;">

<p>Face Attendance and Penalty Monitoring System</p>

</div>

<a href="admin_dashboard.php"
class="active">

🏠 Dashboard

</a>

<a href="student_list.php">

🎓 Students &amp; Reports

</a>

<a href="create_event_new.php">

📅 Create Event

</a>

<a href="event_history.php">

🕘 Event History

</a>

<a href="payments.php">

💳 Payments

</a>

<a href="#" onclick="startTraining(); return false;">

🔁 Re-train Models

</a>

<a href="logout.php">

🚪 Logout

</a>

</div>

<!-- MAIN -->

<div class="main">

<!-- TOPBAR -->

<div class="topbar">

<h1>

Welcome back,
<?php echo $adminName; ?> 👋

</h1>

<p>

<?php echo date("F d, Y"); ?>

</p>

<div class="top-actions">

<div class="status">

📌 Current Event:
<?php echo $event_name; ?>

</div>

<div style="display:flex;gap:10px;">
    <a href="face_recognition_scan.php"
    class="face-btn">

    📷 Start Face Attendance

    </a>
</div>

</div>

</div>

<!-- RE-TRAIN BUTTON (always visible) -->
<div class="train-actions" style="margin-bottom:12px">
    <button class="btn-retrain" id="btnRetrain" onclick="startTraining()">🔁 Re-train Models</button>
</div>

<!-- TRAINING PROGRESS PANEL -->
<div class="train-panel hidden" id="trainPanel">
    <div class="train-header">
        <span class="train-title" id="trainTitle">🔄 Model Training</span>
        <span class="train-elapsed" id="trainElapsed"></span>
    </div>
    <div class="train-msg" id="trainMsg">Initializing…</div>
    <div class="train-bar-wrap">
        <div class="train-bar-fill" id="trainBarFill"></div>
        <div class="train-bar-pct"  id="trainBarPct">0%</div>
    </div>
    <div class="train-models">
        <div class="tm-card" id="tm-lbph">
            <div class="tm-icon">🔷</div>
            <div class="tm-name">LBPH</div>
            <div class="tm-status" id="tms-lbph">Waiting…</div>
        </div>
        <div class="tm-card" id="tm-cnn">
            <div class="tm-icon">🧠</div>
            <div class="tm-name">Dlib CNN</div>
            <div class="tm-status" id="tms-cnn">Waiting…</div>
        </div>
    </div>
    <div class="train-actions">
        <button class="btn-dismiss" id="btnDismiss" onclick="dismissTraining()">✕ Dismiss</button>
    </div>
</div>

<!-- ANALYTICS -->

<div class="analytics-grid">

<div class="analytics-card">

<h3>👨‍🎓 Total Students</h3>

<div class="analytics-value">

<?php echo $totalStudents; ?>

</div>

</div>

<div class="analytics-card">

<h3>📸 Face Registered</h3>

<div class="analytics-value">

<?php echo $registeredStudents; ?>

</div>

</div>

<div class="analytics-card">

<h3>⏳ Pending Registration</h3>

<div class="analytics-value">

<?php echo $pendingStudents; ?>

</div>

</div>

<div class="analytics-card">

<h3>✅ Present Today</h3>

<div class="analytics-value">

<?php echo $totalPresent; ?>

</div>

</div>

<div class="analytics-card">

<h3>⏰ Late Today</h3>

<div class="analytics-value">

<?php echo $totalLate; ?>

</div>

</div>

<div class="analytics-card">

<h3>❌ Absent Today</h3>

<div class="analytics-value">

<?php echo $totalAbsent; ?>

</div>

</div>

<div class="analytics-card" onclick="window.location='payments.php'" style="cursor:pointer;">

<h3>⏳ Pending Payments</h3>

<div class="analytics-value" style="color:<?php echo $pendingPaymentsCount>0?'#ffa500':'#51cf66'; ?>">

<?php echo $pendingPaymentsCount; ?>

</div>

</div>

<div class="analytics-card" onclick="window.location='payments.php'" style="cursor:pointer;">

<h3>💰 Outstanding Fines</h3>

<div class="analytics-value" style="color:<?php echo $outstandingTotal>0?'#ff6b6b':'#51cf66'; ?>">

₱<?php echo number_format($outstandingTotal,0); ?>

</div>

</div>

</div>

<!-- RECENT EVENTS -->

<div class="content-grid">

<div class="panel">

<h2>

📅 Recent Events

</h2>

<div class="table-scroll">

<table class="data-table">

<thead>

<tr>

<th>Event</th>
<th>Type</th>
<th>Venue</th>
<th>Date</th>

</tr>

</thead>

<tbody>

<?php while($row =
$recentEvents->fetch_assoc()){ ?>

<tr>

<td>

<?php
echo htmlspecialchars(
$row['event_name']
);
?>

</td>

<td>

<?php
echo htmlspecialchars(
$row['event_type']
);
?>

</td>

<td>

<?php
echo htmlspecialchars(
$row['venue']
);
?>

</td>

<td>

<?php
echo date(
'M d, Y',
strtotime($row['event_date'])
);
?>

</td>

</tr>

<?php } ?>

</tbody>

</table>

</div>

</div>

</div>

</div>

<script>
// ── Training Progress Widget ───────────────────────────────────────────────
const trainPanel   = document.getElementById('trainPanel');
const trainTitle   = document.getElementById('trainTitle');
const trainMsg     = document.getElementById('trainMsg');
const trainBarFill = document.getElementById('trainBarFill');
const trainBarPct  = document.getElementById('trainBarPct');
const trainElapsed = document.getElementById('trainElapsed');
const btnRetrain   = document.getElementById('btnRetrain');
const btnDismiss   = document.getElementById('btnDismiss');

let _pollTimer = null;
let _lastState = 'idle';

function setModelCard(id, cls, msg){
    const card = document.getElementById('tm-' + id);
    if(!card) return;
    card.className = 'tm-card ' + cls;
    document.getElementById('tms-' + id).textContent = msg;
}

function applyStatus(d){
    if(!d || d.state === 'idle'){ return; }

    trainPanel.classList.remove('hidden');
    trainMsg.textContent = d.message || '';

    const pct = parseInt(d.progress || 0);
    trainBarFill.style.width = pct + '%';
    trainBarPct.textContent  = pct + '%';

    if(d.elapsed) trainElapsed.textContent = '⏱ ' + d.elapsed + 's';

    trainBarFill.className = 'train-bar-fill';
    if(d.state === 'done')  trainBarFill.classList.add('done');
    if(d.state === 'error') trainBarFill.classList.add('error');

    const step = d.step || '';

    // LBPH card
    if(step === 'lbph' && d.state === 'running'){
        setModelCard('lbph', 'active', 'Training…');
    } else if(d.result?.lbph) {
        const l = d.result.lbph;
        if(l.ok) setModelCard('lbph', 'ok',   '✅ ' + l.samples + ' samples / ' + l.students + ' students');
        else     setModelCard('lbph', 'fail',  '❌ ' + (l.error||'failed').substring(0,40));
    }

    // Dlib CNN card
    if(step === 'fr_helper' && d.state === 'running'){
        setModelCard('cnn', 'active', 'Generating encodings…');
    } else if(d.result?.fr_helper) {
        const f = d.result.fr_helper;
        if(f.ok) setModelCard('cnn', 'ok',   '✅ ' + f.encodings + ' encodings');
        else     setModelCard('cnn', 'fail',  '❌ ' + (f.error||'failed').substring(0,40));
    }


    // Title
    if(d.state === 'running') trainTitle.textContent = '🔄 Training in Progress…';
    else if(d.state === 'done')  trainTitle.textContent = '✅ Training Complete';
    else if(d.state === 'error') trainTitle.textContent = '❌ Training Failed';

    // Buttons
    const finished = (d.state === 'done' || d.state === 'error');
    btnRetrain.disabled = (d.state === 'running');
    btnDismiss.classList.toggle('hidden', !finished);

    _lastState = d.state;

    // Stop polling when done/error
    if(finished && _pollTimer){
        clearInterval(_pollTimer);
        _pollTimer = null;
    }
}

function pollStatus(){
    fetch('train_status.php')
        .then(r => r.json())
        .then(d => applyStatus(d))
        .catch(() => {});
}

function startPolling(){
    if(_pollTimer) clearInterval(_pollTimer);
    pollStatus(); // immediate first check
    _pollTimer = setInterval(pollStatus, 2000);
}

async function startTraining(){
    btnRetrain.disabled = true;
    trainPanel.classList.remove('hidden');
    trainTitle.textContent = '🔄 Starting training…';
    trainMsg.textContent   = 'Connecting to face server…';
    trainBarFill.style.width = '2%';
    trainBarFill.className = 'train-bar-fill';
    setModelCard('lbph', '', 'Waiting…');
    setModelCard('cnn', '', 'Waiting…');
    btnDismiss.classList.add('hidden');

    try {
        await fetch('face_train_multi.php?async=1');
    } catch(e) { /* server will handle */ }

    startPolling();
}

function dismissTraining(){
    fetch('train_status.php?clear=1').catch(()=>{});
    trainPanel.classList.add('hidden');
    btnRetrain.disabled = false;
    _lastState = 'idle';
}

// On page load: check if training is already running
pollStatus();
// If it was running, keep polling
setTimeout(() => {
    if(_lastState === 'running' || _lastState === 'starting'){
        startPolling();
    }
}, 500);

// Toggle Sidebar for Mobile
function toggleSidebar(){
    document.querySelector('.sidebar').classList.toggle('collapsed');
}
</script>

</body>
</html>