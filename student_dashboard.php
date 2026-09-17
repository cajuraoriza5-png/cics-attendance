<?php
date_default_timezone_set('Asia/Manila');
session_start();
if(!isset($_SESSION['student_id'])){
    header("Location: student_login.php");
    exit;
}

include("db.php");


$student_id = $_SESSION['student_id'];

// Get GCash number from admin
$admin = $conn->query("SELECT gcash_number FROM admin LIMIT 1")->fetch_assoc();
$gcash_number = $admin['gcash_number'] ?? '';

$student_query = $conn->prepare("
SELECT 
face_registered,
total_penalty,
first_name,
middle_name,
last_name,
course,
year_level,
section,
age,
gender,
email,
phone,
student_id,
is_officer
FROM users
WHERE id = ?
");

$student_query->bind_param("i",$student_id);
$student_query->execute();
$student = $student_query->get_result()->fetch_assoc();

if(!$student){
die("Student not found.");
}

/* FACE IMAGE */
$face_image = null;

if($student['face_registered'] == 1){

$face_query = $conn->prepare("
SELECT face_image 
FROM face_data 
WHERE student_id=? 
ORDER BY created_at DESC 
LIMIT 1
");

$face_query->bind_param("i",$student_id);
$face_query->execute();

$face_result = $face_query->get_result();

if($face_result->num_rows > 0){
$row = $face_result->fetch_assoc();
$face_image = $row['face_image'];
}
}

/* ATTENDANCE SUMMARY */

$attendance_query = $conn->prepare("
SELECT 
COUNT(*) total,
SUM(CASE WHEN morning_status='Present' OR afternoon_status='Present' THEN 1 ELSE 0 END) as present,
SUM(CASE
    WHEN (morning_status != 'Present' OR morning_status IS NULL)
     AND (afternoon_status != 'Present' OR afternoon_status IS NULL)
     AND (morning_status = 'Late' OR afternoon_status = 'Late')
    THEN 1 ELSE 0
END) as late_count,
SUM(CASE
    WHEN (morning_status IS NULL OR morning_status = 'Absent')
     AND (afternoon_status IS NULL OR afternoon_status = 'Absent')
    THEN 1 ELSE 0
END) as absent
FROM attendance
WHERE student_id=?
");

$attendance_query->bind_param("i",$student_id);
$attendance_query->execute();

$attendance = $attendance_query->get_result()->fetch_assoc();

/* TODAY EVENT */

$event_query = $conn->query("
SELECT * FROM events
WHERE event_date = CURDATE()
ORDER BY id DESC
LIMIT 1
");

$today_event = $event_query->fetch_assoc();

/* TODAY'S ATTENDANCE STATUS FOR THIS STUDENT */
$today_attendance = null;
if($today_event){
    $taq = $conn->prepare("
        SELECT morning_status, morning_in, morning_out,
               afternoon_status, afternoon_in, afternoon_out, penalty
        FROM attendance
        WHERE student_id=? AND event_id=? AND date=CURDATE()
        LIMIT 1
    ");
    $taq->bind_param("ii",$student_id,$today_event['id']);
    $taq->execute();
    $today_attendance = $taq->get_result()->fetch_assoc();
    $taq->close();
}
?>

<!DOCTYPE html>
<html>
<head>

<title>Student Dashboard</title>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

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

.student-box{
background:rgba(255,215,0,0.1);
border:1px solid rgba(255,215,0,0.3);
padding:18px;
border-radius:18px;
text-align:center;
margin-bottom:25px;
}

.student-box h4{
font-size:18px;
margin-bottom:8px;
color:#FFD700;
font-weight:800;
}

.student-box p{
font-size:13px;
color:rgba(255,255,255,0.7);
margin-top:4px;
}

.student-details{
margin-top:16px;
display:flex;
flex-direction:column;
gap:8px;
}

.student-detail-item{
display:flex;
justify-content:space-between;
align-items:center;
padding:8px 12px;
background:rgba(255,255,255,0.05);
border-radius:10px;
border:1px solid rgba(255,255,255,0.08);
}

.detail-label{
font-size:11px;
color:rgba(255,255,255,0.5);
text-transform:uppercase;
letter-spacing:0.5px;
}

.detail-value{
font-size:12px;
font-weight:700;
color:rgba(255,255,255,0.9);
text-align:right;
}

.profile-avatar{
width:80px;
height:80px;
border-radius:50%;
margin:0 auto 12px;
background:rgba(0,0,0,0.4);
border:3px solid #FFD700;
display:flex;
align-items:center;
justify-content:center;
font-size:36px;
overflow:hidden;
box-shadow:0 0 0 4px rgba(255,215,0,0.15);
}

.profile-avatar img{
width:100%;
height:100%;
object-fit:cover;
}

/* PROFILE SECTION */
.profile-content{
display:flex;
flex-direction:column;
align-items:center;
gap:20px;
}

.profile-avatar-large{
width:120px;
height:120px;
border-radius:50%;
margin:0 auto;
background:rgba(0,0,0,0.4);
border:4px solid #FFD700;
display:flex;
align-items:center;
justify-content:center;
font-size:48px;
overflow:hidden;
box-shadow:0 0 0 6px rgba(255,215,0,0.15);
}

.profile-avatar-large img{
width:100%;
height:100%;
object-fit:cover;
}

.profile-details{
width:100%;
display:flex;
flex-direction:column;
gap:12px;
}

.profile-item{
display:flex;
justify-content:space-between;
align-items:center;
padding:12px 16px;
background:rgba(255,255,255,0.05);
border-radius:12px;
border:1px solid rgba(255,255,255,0.1);
}

.profile-label{
font-size:13px;
color:rgba(255,255,255,0.6);
font-weight:600;
}

.profile-value{
font-size:14px;
font-weight:700;
color:rgba(255,255,255,0.9);
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

/* OFFICER BANNER */
.officer-card{
background:linear-gradient(135deg,rgba(255,215,0,0.14),rgba(255,140,0,0.08));
border:2px solid rgba(255,215,0,0.5);border-radius:22px;padding:22px 24px;
display:flex;align-items:center;justify-content:space-between;gap:16px;
box-shadow:0 0 30px rgba(255,215,0,0.12);flex-wrap:wrap;
margin-bottom:30px;
}
.officer-card-icon{font-size:42px;flex-shrink:0;}
.officer-card-text{flex:1;min-width:0;}
.officer-card-text h3{font-size:17px;font-weight:900;background:linear-gradient(90deg,#FFD700,#FFA500);-webkit-background-clip:text;-webkit-text-fill-color:transparent;margin-bottom:4px;}
.officer-card-text p{font-size:13px;color:rgba(255,255,255,0.6);}
.btn-scan-officer{
display:inline-flex;align-items:center;gap:8px;padding:14px 28px;
border-radius:50px;background:linear-gradient(45deg,#FFD700,#FFA500);
color:#000;font-weight:900;font-size:14px;text-decoration:none;
transition:0.25s;box-shadow:0 6px 20px rgba(255,215,0,0.35);white-space:nowrap;
flex-shrink:0;
}
.btn-scan-officer:hover{transform:translateY(-2px);box-shadow:0 10px 28px rgba(255,215,0,0.5);}

/* TABLE */

.table-scroll{
overflow-x:auto;
}

.data-table{
width:100%;
border-collapse:collapse;
min-width:700px;
}

.data-table th,
.data-table td{
padding:16px;
text-align:left;
border-bottom:1px solid rgba(255,255,255,0.1);
}

.data-table th{
color:#FFD700;
font-weight:800;
text-transform:uppercase;
font-size:13px;
letter-spacing:1px;
}

.data-table tr:hover{
background:rgba(255,215,0,0.05);
}

/* BUTTON */

.btn{
padding:12px 24px;
border:none;
border-radius:12px;
font-weight:800;
cursor:pointer;
transition:0.3s;
font-size:16px;
}

.btn-primary{
background:linear-gradient(
45deg,
#FFD700,
#FFA500
);
color:black;
}

.btn-secondary{
background:rgba(255,255,255,0.1);
border:1px solid rgba(255,215,0,0.3);
color:white;
}

.btn:hover{
transform:translateY(-2px);
}

/* STAT TILES */
.stat-tiles{display:grid;grid-template-columns:1fr 1fr;gap:15px;}
.stat-tile{border-radius:16px;padding:20px;text-align:center;background:rgba(0,0,0,0.5);border:1px solid rgba(255,255,255,0.1);}
.stat-tile .t-num{font-size:36px;font-weight:900;line-height:1;}
.stat-tile .t-lbl{font-size:13px;text-transform:uppercase;letter-spacing:0.6px;margin-top:8px;opacity:0.8;}
.tile-present{background:linear-gradient(135deg,rgba(81,207,102,0.18),rgba(55,178,77,0.08));border:1px solid rgba(81,207,102,0.3);}
.tile-present .t-num{color:#51cf66;}
.tile-late{background:linear-gradient(135deg,rgba(255,165,0,0.18),rgba(230,130,0,0.08));border:1px solid rgba(255,165,0,0.3);}
.tile-late .t-num{color:#ffa500;}
.tile-absent{background:linear-gradient(135deg,rgba(255,107,107,0.18),rgba(220,53,69,0.08));border:1px solid rgba(255,107,107,0.3);}
.tile-absent .t-num{color:#ff6b6b;}
.tile-total{background:linear-gradient(135deg,rgba(116,192,252,0.18),rgba(74,144,226,0.08));border:1px solid rgba(116,192,252,0.3);}
.tile-total .t-num{color:#74c0fc;}

/* PENALTY DISPLAY */
.penalty-big{text-align:center;padding:20px 0;}
.penalty-amount{font-size:48px;font-weight:900;}
.penalty-amount.clean{color:#51cf66;}
.penalty-amount.owed{color:#ff6b6b;}
.penalty-sub{font-size:14px;color:rgba(255,255,255,0.5);margin-top:8px;}
.pay-status-badge{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:20px;font-size:13px;font-weight:700;margin-top:12px;}
.badge-pending{background:rgba(255,165,0,0.15);color:#ffa500;border:1px solid rgba(255,165,0,0.3);}

/* ITEM ROWS */
.item{display:flex;justify-content:space-between;align-items:flex-start;padding:12px 0;gap:10px;border-bottom:1px solid rgba(255,255,255,0.08);}
.item:last-child{border-bottom:none;}
.item-label{font-size:14px;color:rgba(255,255,255,0.6);}
.item-value{font-size:14px;font-weight:700;text-align:right;}
.green{color:#51cf66;} .red{color:#ff6b6b;} .orange{color:#ffa500;} .blue{color:#74c0fc;}

/* SESSION STATUS */
.session-row{display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid rgba(255,255,255,0.08);}
.session-row:last-child{border-bottom:none;}
.session-label{font-size:14px;color:rgba(255,255,255,0.6);min-width:80px;}
.session-val{font-size:14px;font-weight:700;}
.session-time{font-size:12px;color:rgba(255,255,255,0.5);margin-left:auto;}

/* EVENT BANNER */
.event-banner{width:100%;height:180px;object-fit:cover;border-radius:16px;margin-bottom:16px;border:1px solid rgba(255,215,0,0.2);}

/* NO EVENT */
.no-event{text-align:center;padding:30px 0;color:rgba(255,255,255,0.4);font-size:15px;}

/* FOOTER */
.footer{padding:20px;text-align:center;font-size:13px;color:rgba(255,255,255,0.4);letter-spacing:0.5px;}

/* MODAL */
.modal{
display:none;
position:fixed;
top:0;
left:0;
width:100%;
height:100%;
background:rgba(0,0,0,0.8);
backdrop-filter:blur(10px);
z-index:2000;
align-items:center;
justify-content:center;
}

.modal.active{
display:flex;
}

.modal-content{
background:rgba(0,0,0,0.9);
border:2px solid rgba(255,215,0,0.3);
border-radius:25px;
padding:40px;
max-width:500px;
width:90%;
max-height:90vh;
overflow-y:auto;
box-shadow:0 20px 60px rgba(0,0,0,0.8);
}

.modal-header{
display:flex;
justify-content:space-between;
align-items:center;
margin-bottom:25px;
padding-bottom:15px;
border-bottom:1px solid rgba(255,255,255,0.1);
}

.modal-header h2{
font-size:24px;
font-weight:800;
background:linear-gradient(45deg,#FFD700,#FFA500);
-webkit-background-clip:text;
-webkit-text-fill-color:transparent;
}

.modal-close{
background:rgba(255,215,0,0.2);
border:1px solid rgba(255,215,0,0.4);
color:#FFD700;
width:40px;
height:40px;
border-radius:50%;
display:flex;
align-items:center;
justify-content:center;
cursor:pointer;
font-size:20px;
transition:0.3s;
}

.modal-close:hover{
background:rgba(255,215,0,0.4);
transform:rotate(90deg);
}

.modal-profile-img{
width:120px;
height:120px;
border-radius:50%;
margin:0 auto 20px;
border:4px solid #FFD700;
object-fit:cover;
display:flex;
align-items:center;
justify-content:center;
background:rgba(0,0,0,0.4);
font-size:48px;
overflow:hidden;
}

.modal-profile-img img{
width:100%;
height:100%;
object-fit:cover;
}

.modal-info-row{
display:flex;
justify-content:space-between;
align-items:center;
padding:12px 16px;
background:rgba(255,255,255,0.05);
border-radius:12px;
margin-bottom:10px;
border:1px solid rgba(255,255,255,0.08);
}

.modal-info-label{
font-size:13px;
color:rgba(255,255,255,0.6);
font-weight:600;
}

.modal-info-value{
font-size:14px;
font-weight:700;
color:rgba(255,255,255,0.9);
text-align:right;
}

/* MOBILE */
@media(max-width:1024px){
.sidebar{
width:100%;
height:auto;
position:relative;
transform:none;
padding:20px;
border-right:none;
border-bottom:2px solid rgba(255,215,0,0.3);
margin-bottom:20px;
}
.main{
margin-left:0;
padding:20px;
}
.mobile-toggle{
display:none;
}
.topbar{
padding:20px;
margin-bottom:20px;
}
.topbar h1{
font-size:24px;
}
.analytics-grid{
grid-template-columns:1fr;
gap:20px;
}
.content-grid{
grid-template-columns:1fr;
gap:20px;
}
.modal-content{
padding:25px;
width:95%;
}
}

@media(min-width:1025px){
.mobile-toggle{
display:none;
}
}

@media(max-width:768px){
.sidebar{
padding:15px;
}
.topbar h1{font-size:22px;}
.topbar p{font-size:14px;}
.topbar{padding:15px;}
.analytics-grid{grid-template-columns:1fr;}
.stat-tiles{grid-template-columns:1fr 1fr;}
.penalty-amount{font-size:38px;}
.officer-card{flex-direction:column;gap:12px;}
.btn-scan-officer{width:100%;justify-content:center;}
.student-box h4{font-size:16px;}
.student-box p{font-size:12px;}
.modal-header h2{font-size:20px;}
.modal-info-row{padding:10px 12px;}
.modal-info-label{font-size:12px;}
.modal-info-value{font-size:13px;}
.main{padding:15px;}
}

@media(max-width:480px){
.sidebar{
padding:12px;
}
.topbar h1{font-size:18px;}
.topbar{padding:12px;}
.main{padding:12px;}
.stat-tile .t-num{font-size:28px;}
.penalty-amount{font-size:32px;}
.modal-profile-img{width:100px;height:100px;}
.modal-content{padding:20px;}
.logo-box h2{font-size:20px;}
.student-box h4{font-size:14px;}
.profile-avatar{width:60px;height:60px;font-size:28px;}
}

</style>

</head>

<body>

<div class="mobile-toggle" onclick="toggleSidebar()">☰</div>

<!-- SIDEBAR -->

<div class="sidebar">

<div class="logo-box">

<h2>CICS STUDENT</h2>

<p>Face Attendance System</p>

</div>

<div class="student-box" onclick="openProfileModal()" style="cursor:pointer;">

<div class="profile-avatar">
    <?php if($face_image): ?>
      <img src="faces/<?php echo htmlspecialchars($face_image); ?>">
    <?php else: ?>👤<?php endif; ?>
  </div>

<h4><?php echo htmlspecialchars($student['first_name'].' '.$student['last_name']); ?></h4>

<p><?php echo htmlspecialchars($student['student_id']); ?></p>
<p style="font-size:11px;color:rgba(255,255,255,0.5);margin-top:4px;">Click to view profile</p>

</div>

<a href="student_dashboard.php" class="active">

🏠 Dashboard

</a>

<a href="face_enroll.php?uid=<?php echo $student_id; ?>">

📷 Update Face

</a>

<a href="student_payment.php">

💳 Payments

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
<?php echo htmlspecialchars($_SESSION['student_name']); ?> 👋

</h1>

<p>

<?php echo date("F d, Y"); ?>

</p>

<div class="top-actions">

<div class="status">

📌 Course: <?php echo htmlspecialchars($student['course']); ?>

</div>

</div>

</div>

<?php if(!empty($student['is_officer'])): ?>
<div class="officer-card">
  <div class="officer-card-icon">🔑</div>
  <div class="officer-card-text">
    <h3>Officer Scanner Access</h3>
    <p>You are designated as an attendance officer. Open the scanner to record student attendance.</p>
  </div>
  <a href="face_recognition_scan.php" class="btn-scan-officer">📷 Open Scanner</a>
</div>
<?php endif; ?>

<div class="analytics-grid">

<!-- ATTENDANCE -->
<div class="analytics-card">
<h3>📊 Attendance Summary</h3>
<div class="stat-tiles">
  <div class="stat-tile tile-present">
    <div class="t-num"><?= intval($attendance['present'] ?? 0) ?></div>
    <div class="t-lbl">Present</div>
  </div>
  <div class="stat-tile tile-absent">
    <div class="t-num"><?= intval($attendance['absent'] ?? 0) ?></div>
    <div class="t-lbl">Absent</div>
  </div>
  <div class="stat-tile tile-late">
    <div class="t-num"><?= intval($attendance['late_count'] ?? 0) ?></div>
    <div class="t-lbl">Late</div>
  </div>
  <div class="stat-tile tile-total">
    <div class="t-num"><?= intval($attendance['total'] ?? 0) ?></div>
    <div class="t-lbl">Events</div>
  </div>
</div>
</div>

<!-- PENALTY -->
<div class="analytics-card">
<h3>💰 Penalty Status</h3>
<?php
// Compute accrued penalty: Whole Day = single penalty per event; Morning/Afternoon Only = single session
$accruedQ = $conn->query("
    SELECT COALESCE(SUM(
        CASE
            WHEN e.event_type = 'Morning Only' THEN
                CASE WHEN a.morning_status   = 'Late'   THEN e.late_penalty
                     WHEN a.morning_status   = 'Absent' THEN e.absent_penalty
                     ELSE 0 END
            WHEN e.event_type = 'Afternoon Only' THEN
                CASE WHEN a.afternoon_status = 'Late'   THEN e.late_penalty
                     WHEN a.afternoon_status = 'Absent' THEN e.absent_penalty
                     ELSE 0 END
            ELSE
                CASE
                    WHEN a.morning_status = 'Present' OR a.afternoon_status = 'Present' THEN 0
                    WHEN a.morning_status = 'Late'    OR a.afternoon_status = 'Late'    THEN e.late_penalty
                    WHEN a.morning_status = 'Absent'  OR a.afternoon_status = 'Absent'  THEN e.absent_penalty
                    ELSE 0
                END
        END
    ), 0) as p
    FROM attendance a
    JOIN events e ON a.event_id = e.id
    WHERE a.student_id='$student_id'
")->fetch_assoc();
$paidQ    = $conn->query("SELECT COALESCE(SUM(amount),0) as p FROM payments WHERE student_id='$student_id' AND status='Confirmed'")->fetch_assoc();
$accrued  = floatval($accruedQ['p'] ?? 0);
$paid     = floatval($paidQ['p'] ?? 0);
$outstandingPenalty = max(0.0, $accrued - $paid);

$pendingPayment = $conn->query("SELECT id FROM payments WHERE student_id='$student_id' AND status='Pending' LIMIT 1")->num_rows > 0;
?>
<div class="penalty-big">
  <div class="penalty-amount <?= $outstandingPenalty > 0 ? 'owed' : 'clean' ?>">
    ₱<?= number_format($outstandingPenalty, 2) ?>
  </div>
  <div class="penalty-sub">Accrued ₱<?= number_format($accrued,2) ?> &nbsp;·&nbsp; Paid ₱<?= number_format($paid,2) ?></div>
  <?php if($pendingPayment): ?>
  <span class="pay-status-badge badge-pending">⏳ Pending Review</span>
  <?php endif; ?>
</div>
<?php if($outstandingPenalty > 0 && !$pendingPayment): ?>
<button class="btn btn-primary" style="margin-top:4px;" onclick="window.location='student_payment.php'">💳 Pay Penalty</button>
<?php endif; ?>
<button class="btn btn-secondary" style="margin-top:8px;" onclick="window.location='student_payment.php'">📄 Payment History</button>

<?php if($gcash_number): ?>
<div style="margin-top:15px; padding:12px; background:rgba(255,215,0,0.1); border-radius:12px; border:1px solid rgba(255,215,0,0.3); text-align:center;">
  <div style="font-size:12px; color:rgba(255,215,0,0.9); margin-bottom:5px;">GCash Number for Payment</div>
  <div style="font-size:18px; font-weight:bold; color:white; letter-spacing:1px;">
    <?php echo htmlspecialchars($gcash_number); ?>
  </div>
</div>
<?php endif; ?>
</div>

<!-- CONTENT GRID -->

<div class="content-grid">

<!-- TODAY'S EVENT -->
<div class="panel">
<h2>📅 Today's Event</h2>

<?php if($today_event){ ?>

<?php if(!empty($today_event['event_banner'])){ ?>

<img
src="event_banners/<?php echo htmlspecialchars($today_event['event_banner']); ?>"
class="event-banner">

<?php } ?>

<div class="item">
<div class="item-label">Event</div>
<div class="item-value">
<?php echo htmlspecialchars($today_event['event_name']); ?>
</div>
</div>

<div class="item">
<div class="item-label">Type</div>
<div class="item-value blue">
<?php echo htmlspecialchars($today_event['event_type']); ?>
</div>
</div>

<div class="item">
<div class="item-label">Venue</div>
<div class="item-value">
<?php echo htmlspecialchars($today_event['venue']); ?>
</div>
</div>

<?php
// Helper to render a session status badge
function renderSessionStatus($label, $status, $inTime, $outTime){
    $color = 'blue';
    if($status === 'Present') $color = 'green';
    elseif($status === 'Late') $color = 'orange';
    elseif($status === 'Absent') $color = 'red';
    $html = '<div style="margin-bottom:6px;"><strong style="font-size:13px;color:rgba(255,255,255,0.7);">' . htmlspecialchars($label) . '</strong> ';
    if($status === 'Present'){
        $html .= '<span style="color:#51cf66;font-weight:700;">✅ Present</span>';
        if($inTime) $html .= ' <small style="font-size:12px;opacity:.75;">In ' . date('g:i A',strtotime($inTime)) . '</small>';
        if($outTime) $html .= ' <small style="font-size:12px;opacity:.75;">Out ' . date('g:i A',strtotime($outTime)) . '</small>';
    } elseif($status === 'Late'){
        $html .= '<span style="color:#ffa500;font-weight:700;">⚠️ Late</span>';
        if($inTime) $html .= ' <small style="font-size:12px;opacity:.75;">In ' . date('g:i A',strtotime($inTime)) . '</small>';
        if($outTime) $html .= ' <small style="font-size:12px;opacity:.75;">Out ' . date('g:i A',strtotime($outTime)) . '</small>';
    } elseif($status === 'Absent'){
        $html .= '<span style="color:#ff6b6b;font-weight:700;">❌ Absent</span>';
    } else {
        $html .= '<span style="color:rgba(255,255,255,0.5);">🕐 Not yet scanned</span>';
    }
    $html .= '</div>';
    return $html;
}

$eventType = $today_event['event_type'] ?? 'Whole Day';
$ms = $today_attendance['morning_status'] ?? null;
$min = $today_attendance['morning_in'] ?? null;
$mout = $today_attendance['morning_out'] ?? null;
$as = $today_attendance['afternoon_status'] ?? null;
$ain = $today_attendance['afternoon_in'] ?? null;
$aout = $today_attendance['afternoon_out'] ?? null;
// Compute today's penalty: Whole Day = single unified penalty; Morning/Afternoon Only = single session
$tpen = 0;
if($today_event && $today_attendance){
    $tlp = floatval($today_event['late_penalty']   ?? 0);
    $tap = floatval($today_event['absent_penalty'] ?? 0);
    $tet = $today_event['event_type'] ?? '';
    $tms = $today_attendance['morning_status']   ?? '';
    $tas = $today_attendance['afternoon_status'] ?? '';
    if($tet === 'Morning Only'){
        if($tms === 'Late')   $tpen = $tlp;
        elseif($tms === 'Absent') $tpen = $tap;
    } elseif($tet === 'Afternoon Only'){
        if($tas === 'Late')   $tpen = $tlp;
        elseif($tas === 'Absent') $tpen = $tap;
    } else {
        if($tms === 'Present' || $tas === 'Present')       $tpen = 0;
        elseif($tms === 'Late'   || $tas === 'Late')       $tpen = $tlp;
        elseif($tms === 'Absent' || $tas === 'Absent')     $tpen = $tap;
    }
}
?>
<div class="item">
<div class="item-label">Today's Status</div>
<div class="item-value" style="text-align:left;">
<?php if($eventType === 'Morning Only'): ?>
    <?php echo renderSessionStatus('Morning', $ms, $min, $mout); ?>
<?php elseif($eventType === 'Afternoon Only'): ?>
    <?php echo renderSessionStatus('Afternoon', $as, $ain, $aout); ?>
<?php else: /* Whole Day or default */ ?>
    <?php echo renderSessionStatus('Morning', $ms, $min, $mout); ?>
    <?php echo renderSessionStatus('Afternoon', $as, $ain, $aout); ?>
<?php endif; ?>
</div>
</div>

<?php if($tpen > 0): ?>
<div class="item">
<div class="item-label">Today's Penalty</div>
<div class="item-value red">₱<?php echo number_format($tpen,2); ?></div>
</div>
<?php endif; ?>

<?php } else { ?>
<div class="no-event">📭 No event scheduled for today</div>
<?php } ?>
</div>

<!-- EVENTS WITH PENALTIES -->
<div class="panel">
<h2>⚠️ Events with Penalties</h2>
<?php
$penaltyEvents = $conn->query("
    SELECT e.event_name, e.event_date, e.event_type, e.late_penalty, e.absent_penalty,
           a.morning_status, a.afternoon_status,
           CASE
               WHEN e.event_type = 'Morning Only' THEN
                   CASE WHEN a.morning_status='Late' THEN e.late_penalty
                        WHEN a.morning_status='Absent' THEN e.absent_penalty
                        ELSE 0 END
               WHEN e.event_type = 'Afternoon Only' THEN
                   CASE WHEN a.afternoon_status='Late' THEN e.late_penalty
                        WHEN a.afternoon_status='Absent' THEN e.absent_penalty
                        ELSE 0 END
               ELSE
                   CASE
                       WHEN a.morning_status='Present' OR a.afternoon_status='Present' THEN 0
                       WHEN a.morning_status='Late' OR a.afternoon_status='Late' THEN e.late_penalty
                       WHEN a.morning_status='Absent' OR a.afternoon_status='Absent' THEN e.absent_penalty
                       ELSE 0 END
           END as penalty_amount
    FROM attendance a
    JOIN events e ON a.event_id = e.id
    WHERE a.student_id='$student_id'
    AND (
        (e.event_type = 'Morning Only' AND a.morning_status IN ('Late', 'Absent'))
        OR (e.event_type = 'Afternoon Only' AND a.afternoon_status IN ('Late', 'Absent'))
        OR (e.event_type NOT IN ('Morning Only', 'Afternoon Only') AND (a.morning_status IN ('Late', 'Absent') OR a.afternoon_status IN ('Late', 'Absent')))
    )
    ORDER BY e.event_date DESC
    LIMIT 5
");

if($penaltyEvents->num_rows > 0):
?>
<?php while($pe = $penaltyEvents->fetch_assoc()): ?>
<div class="item">
  <div class="item-label"><?= htmlspecialchars($pe['event_name']) ?></div>
  <div class="item-value">
    <div style="font-size:12px;color:rgba(255,255,255,0.5);"><?= date('M d, Y', strtotime($pe['event_date'])) ?></div>
    <div style="color:#ff6b6b;font-weight:700;">₱<?= number_format($pe['penalty_amount'], 2) ?></div>
  </div>
</div>
<?php endwhile; ?>
<?php else: ?>
<div style="text-align:center;padding:16px 0;color:rgba(255,255,255,0.4);font-size:13px;">✅ No penalties</div>
<?php endif; ?>
</div>

</div><!-- end content-grid -->

</div><!-- end main -->

<script>
// Toggle Sidebar for Mobile
function toggleSidebar(){
    document.querySelector('.sidebar').classList.toggle('collapsed');
}

// Profile Modal
function openProfileModal(){
    document.getElementById('profileModal').classList.add('active');
}

function closeProfileModal(){
    document.getElementById('profileModal').classList.remove('active');
}

// Close modal when clicking outside
document.getElementById('profileModal').addEventListener('click', function(e){
    if(e.target === this){
        closeProfileModal();
    }
});
</script>

<!-- PROFILE MODAL -->
<div class="modal" id="profileModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>👤 Student Profile</h2>
            <div class="modal-close" onclick="closeProfileModal()">✕</div>
        </div>
        <div class="modal-profile-img">
            <?php if($face_image): ?>
              <img src="faces/<?php echo htmlspecialchars($face_image); ?>">
            <?php else: ?>👤<?php endif; ?>
        </div>
        <div class="modal-info-row">
            <div class="modal-info-label">Full Name</div>
            <div class="modal-info-value"><?php echo htmlspecialchars($student['first_name'].' '.($student['middle_name']?$student['middle_name'].' ':'').$student['last_name']); ?></div>
        </div>
        <div class="modal-info-row">
            <div class="modal-info-label">Student ID</div>
            <div class="modal-info-value"><?php echo htmlspecialchars($student['student_id']); ?></div>
        </div>
        <div class="modal-info-row">
            <div class="modal-info-label">Course</div>
            <div class="modal-info-value"><?php echo htmlspecialchars($student['course']); ?></div>
        </div>
        <div class="modal-info-row">
            <div class="modal-info-label">Year & Section</div>
            <div class="modal-info-value"><?php echo htmlspecialchars($student['year_level'].' - '.$student['section']); ?></div>
        </div>
        <div class="modal-info-row">
            <div class="modal-info-label">Email</div>
            <div class="modal-info-value"><?php echo htmlspecialchars($student['email']); ?></div>
        </div>
        <div class="modal-info-row">
            <div class="modal-info-label">Phone</div>
            <div class="modal-info-value"><?php echo htmlspecialchars($student['phone']); ?></div>
        </div>
        <div class="modal-info-row">
            <div class="modal-info-label">Age</div>
            <div class="modal-info-value"><?php echo htmlspecialchars($student['age']); ?></div>
        </div>
        <div class="modal-info-row">
            <div class="modal-info-label">Gender</div>
            <div class="modal-info-value"><?php echo htmlspecialchars($student['gender']); ?></div>
        </div>
        <div class="modal-info-row">
            <div class="modal-info-label">Face Registration</div>
            <div class="modal-info-value <?= $student['face_registered']?'green':'orange' ?>">
                <?php echo $student['face_registered']?'✓ Registered':'⚠ Pending' ?>
            </div>
        </div>
        <?php if(!$student['face_registered']): ?>
        <button class="btn btn-primary" style="margin-top:15px;width:100%;" onclick="window.location='face_enroll.php?uid=<?= $student_id ?>';">📷 Register Face</button>
        <?php endif; ?>
    </div>
</div>

<div class="footer">
CICS Face Attendance and Penalty Monitoring System © 2026
</div>

</body>
</html>