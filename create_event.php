<?php
session_start();
$conn = new mysqli("localhost","root","","attendance");

if($conn->connect_error){
    die("Connection failed: ".$conn->connect_error);
}

/* SAVE EVENT */
if(isset($_POST['add_event'])){

    $course = isset($_POST['course']) ? implode(",",$_POST['course']) : "ALL";
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $event_type = $_POST['event_type'];
    $venue = $_POST['venue'];
    if($venue === 'Other'){
        $venue = $_POST['other_venue'] ?? '';
    }
    $event_history = $_POST['event_history'] ?? '';
    
    // Handle banner upload
    $event_banner = null;
    if(isset($_FILES['event_banner']) && $_FILES['event_banner']['error'] == UPLOAD_ERR_OK){
        $allowed_types = ['image/jpeg', 'image/png', 'image/jpg'];
        $file_info = getimagesize($_FILES['event_banner']['tmp_name']);
        
        if($file_info !== false && in_array($file_info['mime'], $allowed_types)){
            if($_FILES['event_banner']['size'] <= 5 * 1024 * 1024){ // 5MB limit
                if(!is_dir('event_banners')){
                    mkdir('event_banners', 0755, true);
                }
                
                $filename = 'banner_' . time() . '.jpg';
                $filepath = 'event_banners/' . $filename;
                
                if(move_uploaded_file($_FILES['event_banner']['tmp_name'], $filepath)){
                    $event_banner = $filename;
                }
            }
        }
    }
    
    // Generate QR code and mobile scan link
    $qr_code = 'qr_' . time() . '.png';
    $mobile_scan_link = 'mobile_scan.php?event_id=' . uniqid();
    
    // Calculate date range
    $start = new DateTime($start_date);
    $end = new DateTime($end_date);
    $interval = new DateInterval('P1D');
    $date_range = new DatePeriod($start, $interval, $end->modify('+1 day'));
    
    foreach($date_range as $date){
        $event_date = $date->format('Y-m-d');
        
        $stmt = $conn->prepare("
            INSERT INTO events(
                event_name, event_description, event_date, course,
                event_type, venue, start_date, end_date, event_banner,
                qr_code, mobile_scan_link, event_history,
                late_penalty, absent_penalty,
                morning_login_start, morning_login_end,
                morning_logout_start, morning_logout_end,
                afternoon_login_start, afternoon_login_end,
                afternoon_logout_start, afternoon_logout_end,
                created_at
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
        ");
        
        // Store time values in variables to avoid reference error
        $morning_login_start = '07:00:00';
        $morning_login_end = '07:30:00';
        $morning_logout_start = '11:30:00';
        $morning_logout_end = '12:00:00';
        $afternoon_login_start = '13:00:00';
        $afternoon_login_end = '13:30:00';
        $afternoon_logout_start = '16:30:00';
        $afternoon_logout_end = '17:00:00';
        
        $stmt->bind_param(
            "sssssssssssiiisssssss",
            $_POST['event_name'],
            $_POST['event_description'],
            $event_date,
            $course,
            $event_type,
            $venue,
            $start_date,
            $end_date,
            $event_banner,
            $qr_code,
            $mobile_scan_link,
            $event_history,
            $_POST['late_penalty'],
            $_POST['absent_penalty'],
            $morning_login_start,
            $morning_login_end,
            $morning_logout_start,
            $morning_logout_end,
            $afternoon_login_start,
            $afternoon_login_end,
            $afternoon_logout_start,
            $afternoon_logout_end
        );
        
        $stmt->execute();
    }
    
    header("Location:create_event.php?success=1");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Create Event</title>

<style>
*{
margin:0;
padding:0;
box-sizing:border-box;
font-family:'Segoe UI',sans-serif;
}

body{
background:#f4f6fb;
padding:18px;
color:#111;
}

.container{
max-width:1180px;
margin:auto;
}

/* HEADER */
.top{
background:linear-gradient(135deg,#FFD700,#FFC107,#ffb300);
padding:16px 20px;
border-radius:18px;
display:flex;
align-items:center;
gap:14px;
box-shadow:0 10px 25px rgba(0,0,0,.06);
margin-bottom:16px;
}

.logo{
width:60px;
height:60px;
background:#fff;
border-radius:50%;
padding:6px;
object-fit:contain;
}

.top h1{
font-size:30px;
font-weight:900;
line-height:1;
}

.top p{
font-size:14px;
font-weight:700;
margin-top:4px;
}

/* CARD */
.card{
background:#fff;
padding:20px;
border-radius:18px;
box-shadow:0 10px 24px rgba(0,0,0,.05);
margin-bottom:16px;
}

.card h2{
font-size:28px;
font-weight:900;
margin-bottom:14px;
}

/* GRID */
.grid{
display:grid;
grid-template-columns:repeat(3,1fr);
gap:12px;
}

.two{
grid-column:span 2;
}

.full{
grid-column:1/-1;
}

/* LABEL */
label{
display:block;
font-size:14px;
font-weight:900;
margin-bottom:6px;
color:#222;
}

/* INPUT */
input,select,textarea{
width:100%;
padding:11px 12px;
border:1px solid #ddd;
border-radius:10px;
font-size:15px;
outline:none;
background:#fff;
transition:.25s;
}

input:focus,
select:focus,
textarea:focus{
border-color:#FFD700;
box-shadow:0 0 0 3px rgba(255,215,0,.12);
}

textarea{
height:78px;
resize:none;
}

/* COURSES */
.courses{
display:grid;
grid-template-columns:repeat(4,1fr);
gap:10px;
}

.course{
background:#fff8d6;
padding:10px 12px;
border-radius:12px;
display:flex;
justify-content:space-between;
align-items:center;
font-size:14px;
font-weight:900;
}

/* TITLES */
.section-title{
font-size:22px;
font-weight:900;
margin:16px 0 10px;
}

/* TIME GRID */
.time-grid{
display:grid;
grid-template-columns:repeat(4,1fr);
gap:10px;
}

.time-box label{
font-size:13px;
}

/* BUTTONS */
.btn-row{
display:grid;
grid-template-columns:1fr 1fr;
gap:12px;
margin-top:16px;
}

.btn{
padding:13px;
border:none;
border-radius:12px;
font-size:16px;
font-weight:900;
cursor:pointer;
transition:.25s;
}

.btn:hover{
transform:translateY(-2px);
}

.dark{
background:#111;
color:#fff;
}

.gold{
background:linear-gradient(135deg,#FFD700,#FFC107);
color:#111;
}

/* SUCCESS */
.success{
background:#d4edda;
color:#155724;
padding:12px;
border-radius:12px;
font-size:15px;
font-weight:800;
margin-bottom:14px;
}

/* TABLE */
.table-wrap{
overflow:auto;
border-radius:12px;
border:1px solid #eee;
}

table{
width:100%;
border-collapse:collapse;
min-width:850px;
}

th,td{
padding:12px;
font-size:14px;
text-align:center;
}

th{
background:#FFD700;
}

td{
border-bottom:1px solid #eee;
}

tr:hover{
background:#fffbea;
}

/* MOBILE */
@media(max-width:950px){

.grid{
grid-template-columns:1fr 1fr;
}

.courses{
grid-template-columns:1fr 1fr;
}

.time-grid{
grid-template-columns:1fr 1fr;
}

}

@media(max-width:650px){

.grid,
.courses,
.time-grid,
.btn-row{
grid-template-columns:1fr;
}

.two,.full{
grid-column:auto;
}

.top{
flex-direction:column;
text-align:center;
}

}
</style>
</head>
<body>

<div class="container">

<!-- HEADER -->
<div class="top">
<img src="assets/images/cics_logo.png" class="logo">
<div>
<h1>CICS EVENT MANAGER</h1>
<p>Face-In, Penalty-Out Attendance System</p>
</div>
</div>

<!-- FORM -->
<div class="card">

<h2>📅 Create Event</h2>

<?php if(isset($_GET['success'])){ ?>
<div class="success">✅ Event Created Successfully!</div>
<?php } ?>

<form method="POST">

<div class="grid">

<div class="two">
<label>Event Name</label>
<input type="text" name="event_name" required>
</div>

<div>
<label>Date</label>
<input type="date" name="event_date" required>
</div>

<div class="full">
<label>Description</label>
<textarea name="event_description"></textarea>
</div>

<div>
<label>Number of Days</label>
<input type="number" name="days" min="1" value="1" required>
</div>

<div>
<label>Event Type</label>
<select name="event_type">
<option value="full">Full Day</option>
<option value="morning">Morning Only</option>
<option value="afternoon">Afternoon Only</option>
</select>
</div>

<div>
<label>Late Penalty</label>
<input type="number" name="late_penalty" value="0">
</div>

<div>
<label>Absent Penalty</label>
<input type="number" name="absent_penalty" value="0">
</div>

<div>
<label>Venue</label>
<select name="venue" id="venue_select" onchange="toggleOtherVenue()" required>
<option value="">-- Select Venue --</option>
<option value="CICS Building">CICS Building</option>
<option value="Nisu main gymnasium">Nisu main gymnasium</option>
<option value="Other">Other (specify below)</option>
</select>
</div>

<div id="other_venue_div" style="display:none;">
<label>Other Venue</label>
<input type="text" name="other_venue" id="other_venue" placeholder="Enter venue name">
</div>

<div class="full">
<label>Courses</label>

<div class="courses">
<label class="course">ALL <input type="checkbox" name="course[]" value="ALL"></label>
<label class="course">BSCS <input type="checkbox" name="course[]" value="BSCS"></label>
<label class="course">BSIT <input type="checkbox" name="course[]" value="BSIT"></label>
<label class="course">BLIS <input type="checkbox" name="course[]" value="BLIS"></label>
</div>

</div>

</div>

<!-- MORNING -->
<div class="section-title"> Morning Schedule</div>

<div class="time-grid">
<div class="time-box">
<label>Login Start</label>
<input type="time" name="morning_login_start">
</div>

<div class="time-box">
<label>Login End</label>
<input type="time" name="morning_login_end">
</div>

<div class="time-box">
<label>Logout Start</label>
<input type="time" name="morning_logout_start">
</div>

<div class="time-box">
<label>Logout End</label>
<input type="time" name="morning_logout_end">
</div>
</div>

<!-- AFTERNOON -->
<div class="section-title"> Afternoon Schedule</div>

<div class="time-grid">
<div class="time-box">
<label>Login Start</label>
<input type="time" name="afternoon_login_start">
</div>

<div class="time-box">
<label>Login End</label>
<input type="time" name="afternoon_login_end">
</div>

<div class="time-box">
<label>Logout Start</label>
<input type="time" name="afternoon_logout_start">
</div>

<div class="time-box">
<label>Logout End</label>
<input type="time" name="afternoon_logout_end">
</div>
</div>

<div class="btn-row">
<button type="button" class="btn dark">👁 Preview</button>
<button type="submit" name="add_event" class="btn gold">Create Event</button>
</div>

</form>

<script>
function toggleOtherVenue(){
    const select = document.getElementById('venue_select');
    const otherDiv = document.getElementById('other_venue_div');
    const otherInput = document.getElementById('other_venue');
    
    if(select.value === 'Other'){
        otherDiv.style.display = 'block';
        otherInput.required = true;
    } else {
        otherDiv.style.display = 'none';
        otherInput.required = false;
        otherInput.value = '';
    }
}
</script>

</div>

<!-- TABLE -->
<div class="card">

<h2>📅 Event Calendar</h2>

<div class="table-wrap">

<table>
<tr>
<th>Date</th>
<th>Event Name</th>
<th>Course</th>
<th>Late</th>
<th>Absent</th>
</tr>

<?php
$list = $conn->query("SELECT * FROM events ORDER BY event_date DESC");

while($row = $list->fetch_assoc()){
?>

<tr>
<td><?= $row['event_date']; ?></td>
<td><?= $row['event_name']; ?></td>
<td><?= $row['course']; ?></td>
<td>₱<?= number_format($row['late_penalty'],2); ?></td>
<td>₱<?= number_format($row['absent_penalty'],2); ?></td>
</tr>

<?php } ?>

</table>

</div>

</div>

</div>

</body>
</html>