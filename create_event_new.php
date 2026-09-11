<?php
date_default_timezone_set('Asia/Manila');
session_start();

$conn = new mysqli("localhost","root","","attendance");

if($conn->connect_error){
die("Connection failed: ".$conn->connect_error);
}

/* CREATE EVENT */
if(isset($_POST['add_event'])){

$course = isset($_POST['course'])
? implode(",",$_POST['course'])
: "ALL";

$event_name = trim($_POST['event_name']);
$event_description = trim($_POST['event_description'] ?? '');
$event_type = $_POST['event_type'];
$venue = trim($_POST['venue']);
if($venue === 'Other'){
    $venue = trim($_POST['other_venue'] ?? '');
}

$start_date = $_POST['start_date'];
$end_date = $_POST['end_date'];

$late_penalty = $_POST['late_penalty'];
$absent_penalty = $_POST['absent_penalty'];

/* TIMES */
$morning_login_start = $_POST['morning_login_start'] ?? '';
$morning_login_end = $_POST['morning_login_end'] ?? '';
$morning_logout_start = $_POST['morning_logout_start'] ?? '';
$morning_logout_end = $_POST['morning_logout_end'] ?? '';

$afternoon_login_start = $_POST['afternoon_login_start'] ?? '';
$afternoon_login_end = $_POST['afternoon_login_end'] ?? '';
$afternoon_logout_start = $_POST['afternoon_logout_start'] ?? '';
$afternoon_logout_end = $_POST['afternoon_logout_end'] ?? '';

$morning_start = $_POST['morning_start'] ?? '08:00:00';
$morning_end = $_POST['morning_end'] ?? '08:30:00';
$afternoon_start = $_POST['afternoon_start'] ?? '13:00:00';
$afternoon_end = $_POST['afternoon_end'] ?? '13:30:00';

$morning_late_time = $_POST['morning_late_time'] ?? NULL;
$afternoon_late_time = $_POST['afternoon_late_time'] ?? NULL;

/* EVENT TYPE LOGIC */

if($event_type == "Morning Only"){

$afternoon_login_start = NULL;
$afternoon_login_end = NULL;
$afternoon_logout_start = NULL;
$afternoon_logout_end = NULL;

}

if($event_type == "Afternoon Only"){

$morning_login_start = NULL;
$morning_login_end = NULL;
$morning_logout_start = NULL;
$morning_logout_end = NULL;

}

/* BANNER */

$event_banner = "";

if(isset($_FILES['event_banner']) &&
$_FILES['event_banner']['error'] == 0){

// Validate file type
$allowedTypes = ['png', 'jpg', 'jpeg'];
$ext = strtolower(pathinfo($_FILES['event_banner']['name'], PATHINFO_EXTENSION));

if(!in_array($ext, $allowedTypes)){
die("Invalid file type. Only PNG and JPG files are allowed.");
}

// Validate file size (5MB max)
$maxSize = 5 * 1024 * 1024; // 5MB in bytes
if($_FILES['event_banner']['size'] > $maxSize){
die("File size exceeds 5MB limit. Please select a smaller file.");
}

if(!is_dir("event_banners")){
mkdir("event_banners");
}

$event_banner = "banner_" . time() . "." . $ext;

move_uploaded_file(
$_FILES['event_banner']['tmp_name'],
"event_banners/".$event_banner
);

}

/* DATE RANGE */

$start = new DateTime($start_date);
$end = new DateTime($end_date);

$interval = new DateInterval("P1D");

$daterange = new DatePeriod(
$start,
$interval,
$end->modify('+1 day')
);

/* INSERT */

foreach($daterange as $date){

$event_date = $date->format("Y-m-d");

$qr_code = "qr_" . time() . ".png";

$mobile_scan_link =
"mobile_scan.php?event=" . uniqid();

$event_history = ''; // Empty for new events

$stmt = $conn->prepare("
INSERT INTO events(
event_name,
event_description,
venue,
event_banner,
event_type,
start_date,
end_date,
event_date,
course,
qr_code,
mobile_scan_link,
event_history,
late_penalty,
absent_penalty,
morning_login_start,
morning_login_end,
morning_late_time,
morning_logout_start,
morning_logout_end,
afternoon_login_start,
afternoon_login_end,
afternoon_late_time,
afternoon_logout_start,
afternoon_logout_end,
morning_start,
morning_end,
afternoon_start,
afternoon_end
)
VALUES(
?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?
)");

if(!$stmt){
die("SQL ERROR (prepare failed): ".$conn->error);
}

$stmt->bind_param(
"sssssssssddsssssssssssssssss",
$event_name,
$event_description,
$venue,
$event_banner,
$event_type,
$start_date,
$end_date,
$event_date,
$course,
$qr_code,
$mobile_scan_link,
$event_history,
$late_penalty,
$absent_penalty,
$morning_login_start,
$morning_login_end,
$morning_late_time,
$morning_logout_start,
$morning_logout_end,
$afternoon_login_start,
$afternoon_login_end,
$afternoon_late_time,
$afternoon_logout_start,
$afternoon_logout_end,
$morning_start,
$morning_end,
$afternoon_start,
$afternoon_end
);

if(!$stmt->execute()){
die("SQL ERROR on execute: ".$stmt->error);
}

}

header("Location:create_event_new.php?success=1");
exit();

}
?>

<!DOCTYPE html>
<html>

<head>

<title>Create Event</title>

<meta charset="UTF-8">
<meta name="viewport"
content="width=device-width, initial-scale=1.0">

<style>
/* Cache-busting: v2 */

*{
margin:0;
padding:0;
box-sizing:border-box;
font-family:'Segoe UI';
font-size:16px;
font-weight:600;
}

::-webkit-scrollbar{
width:8px;
}
::-webkit-scrollbar-track{
background:#1a1a1a;
}
::-webkit-scrollbar-thumb{
background:#FFD700;
border-radius:5px;
}
::-webkit-scrollbar-thumb:hover{
background:#FFC107;
}

body{
background:
linear-gradient(
135deg,
rgba(0,0,0,.95),
rgba(0,0,0,.85)
);

min-height:100vh;
padding:20px;
color:white;
}

/* CONTAINER */

.container{
max-width:1500px;
margin:auto;
}

/* WRAPPER */

.wrapper{
width:96%;
max-width:1500px;
margin:auto;
background:rgba(10,10,10,.95);
border:1px solid rgba(255,215,0,.25);
border-radius:24px;
overflow:hidden;
box-shadow:
0 0 30px rgba(255,215,0,.08),
0 10px 40px rgba(0,0,0,.6);
}

/* TOPBAR */

.topbar{
padding:16px 20px;
background:
linear-gradient(
90deg,
rgba(255,215,0,.08),
rgba(255,215,0,.02)
);
border-bottom:1px solid rgba(255,215,0,.18);
}

.topbar h1{
font-size:28px;
font-weight:800;
color:#FFD700;
margin-bottom:2px;
}

.topbar p{
font-size:13px;
color:#d9d9d9;
}

/* CONTENT */

.content{
padding:12px 18px;
}

/* CARD */

.card{
background:rgba(255,255,255,.05);
border:1px solid rgba(255,215,0,.2);
padding:24px;
border-radius:20px;
backdrop-filter:blur(20px);
}

/* TITLE */

.title{
font-size:26px;
font-weight:900;
margin-bottom:20px;
color:#FFD700;
}

/* GRID */

.form-row{
display:grid;
grid-template-columns:repeat(2,1fr);
gap:8px;
margin-bottom:12px;
align-items:start;
}

/* INPUTS */

.form-group{
display:flex;
flex-direction:column;
}

.form-group label{
margin-bottom:6px;
font-weight:700;
color:#FFD700;
font-size:16px;
}

.form-group input,
.form-group textarea,
.form-group select{
width:100%;
height:42px;
padding:0 12px;
border-radius:8px;
border:1px solid rgba(255,215,0,.22);
background:#1a1a1a;
color:white;
font-size:16px;
outline:none;
transition:.25s;
}

.form-group input:focus,
.form-group textarea:focus,
.form-group select:focus{
border-color:rgba(255,215,0,.5);
box-shadow:0 0 10px rgba(255,215,0,.1);
}

.form-group select option{
background:#1a1a1a;
color:white;
}

.form-group textarea{
resize:none;
height:100px;
padding:12px;
}

input[type="file"]{
height:42px;
padding:8px 12px;
background:#1a1a1a;
border:1px solid rgba(255,215,0,.22);
border-radius:8px;
color:white;
}

/* CHECKBOX */

.checkbox-group{
display:flex;
gap:16px;
flex-wrap:wrap;
margin-top:6px;
}

.checkbox-item{
display:flex;
align-items:center;
gap:8px;
cursor:pointer;
user-select:none;
}

.checkbox-item input[type="checkbox"]{
width:16px;
height:16px;
accent-color:#FFD700;
cursor:pointer;
margin:0;
flex-shrink:0;
}

.checkbox-item span{
color:white;
font-weight:600;
font-size:16px;
cursor:pointer;
margin:0;
line-height:16px;
}

/* RADIO */
.radio-group{
display:flex;
gap:14px;
flex-wrap:wrap;
margin-top:6px;
}

.radio-item{
display:flex;
align-items:center;
gap:8px;
cursor:pointer;
}

.radio-item input[type="radio"]{
width:18px;
height:18px;
accent-color:#FFD700;
cursor:pointer;
}

.radio-item label{
color:white;
font-weight:600;
font-size:16px;
cursor:pointer;
}

/* BUTTONS */

.btn-group{
display:flex;
gap:12px;
margin-top:20px;
flex-wrap:wrap;
}

.btn{
padding:12px 24px;
border:none;
border-radius:12px;
font-weight:800;
cursor:pointer;
transition:.3s;
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
background:rgba(255,255,255,.08);
color:white;
text-decoration:none;
display:flex;
align-items:center;
justify-content:center;
}

.btn:hover{
transform:translateY(-2px);
}

/* SUCCESS */

.success{
background:rgba(40,167,69,.2);
padding:15px;
border-radius:15px;
margin-bottom:20px;
color:#51cf66;
font-weight:700;
text-align:center;
}

/* MOBILE */

@media(max-width:1200px){

.form-row{
grid-template-columns:1fr;
}

}

@media(max-width:768px){

body{
padding:12px;
}

.wrapper{
width:100%;
border-radius:16px;
}

.topbar{
padding:12px 16px;
}

.topbar h1{
font-size:22px;
}

.topbar p{
font-size:12px;
}

.content{
padding:10px 14px;
}

.card{
padding:16px;
border-radius:16px;
}

.title{
font-size:22px;
margin-bottom:16px;
}

.form-row{
gap:12px;
margin-bottom:12px;
}

.form-group label{
font-size:12px;
margin-bottom:5px;
}

.form-group input,
.form-group textarea,
.form-group select{
padding:10px;
font-size:13px;
border-radius:10px;
}

.form-group textarea{
height:80px;
}

.checkbox-group,
.radio-group{
gap:10px;
margin-top:5px;
}

.btn-group{
flex-direction:column;
gap:10px;
margin-top:16px;
}

.btn{
width:100%;
padding:12px 20px;
font-size:13px;
}

.success{
padding:12px;
font-size:13px;
margin-bottom:16px;
}

}

</style>

</head>

<body>

<div class="container">

<?php if(isset($_GET['success'])){ ?>

<div class="success">

✅ Event Created Successfully

</div>

<?php } ?>

<div class="wrapper">

<div class="topbar">
<h1>📅 Create Event</h1>
<p>Create new attendance events for students</p>
</div>

<div class="content">

<div class="card">

<div class="title">

Event Details

</div>

<form
method="POST"
enctype="multipart/form-data">

<!-- EVENT NAME -->

<div class="form-row">

<div class="form-group">
<label>Event Name</label>

<input
type="text"
name="event_name"
required>

</div>

<div class="form-group">
<label>Event Description</label>

<textarea
name="event_description"
placeholder="Enter event description"></textarea>

</div>

</div>

<!-- VENUE -->

<div class="form-row">

<div class="form-group">
<label>Venue</label>

<select
name="venue"
id="venue_select"
onchange="toggleOtherVenue()"
required>
<option value="">-- Select Venue --</option>
<option value="CICS Building">CICS Building</option>
<option value="Nisu main gymnasium">Nisu main gymnasium</option>
<option value="Other">Other (specify below)</option>
</select>
</div>

<div class="form-group" id="other_venue_div" style="display:none;">
<label>Other Venue</label>
<input
type="text"
name="other_venue"
id="other_venue"
placeholder="Enter venue name">
</div>

</div>

<!-- TYPE -->

<div class="form-row">

<div class="form-group">

<label>Event Type</label>

<select name="event_type" id="event_type_select" onchange="toggleTimeFields()">
<option value="Whole Day" selected>Whole Day</option>
<option value="Morning Only">Morning Only</option>
<option value="Afternoon Only">Afternoon Only</option>
</select>

</div>

<div class="form-group">

<label>Event Banner</label>

<input
type="file"
name="event_banner"
accept="image/png,image/jpeg,image/jpg"
onchange="validateFileSize(this, 5)">

<small style="color:rgba(255,255,255,0.5);font-size:12px;margin-top:5px;display:block;">Jpe, jpeg, PNG, JPG (Max 5MB)</small>

</div>

</div>

<!-- DATES -->

<div class="form-row">

<div class="form-group">

<label>Start Date</label>

<input
type="date"
name="start_date"
required>

</div>

<div class="form-group">

<label>End Date</label>

<input
type="date"
name="end_date"
required>

</div>

</div>

<!-- COURSES -->

<div class="form-group">

<label>Target Courses</label>

<div class="checkbox-group">

<label class="checkbox-item">
<input type="checkbox"
name="course[]"
value="BSCS">
<span>BSCS</span>
</label>

<label class="checkbox-item">
<input type="checkbox"
name="course[]"
value="BSIT">
<span>BSIT</span>
</label>

<label class="checkbox-item">
<input type="checkbox"
name="course[]"
value="BLIS">
<span>BLIS</span>
</label>

<label class="checkbox-item">
<input type="checkbox"
name="course[]"
value="ALL">
<span>ALL</span>
</label>

</div>

</div>

<!-- PENALTY -->

<div class="form-row">

<div class="form-group">

<label>Late Penalty</label>

<input
type="number"
step="0.01"
name="late_penalty"
value="25">

</div>

<div class="form-group">

<label>Absent Penalty</label>

<input
type="number"
step="0.01"
name="absent_penalty"
value="50">

</div>

</div>

<!-- MORNING -->

<div id="morning_section">

<div class="form-row">

<div class="form-group">

<label>Morning Login Start</label>

<input
type="time"
name="morning_login_start"
id="morning_login_start"
value="07:00">

</div>

<div class="form-group">

<label>Morning Login End</label>

<input
type="time"
name="morning_login_end"
id="morning_login_end"
value="07:30">

</div>

</div>

<div class="form-row">

<div class="form-group">

<label>Morning Logout Start</label>

<input
type="time"
name="morning_logout_start"
id="morning_logout_start"
value="11:30">

</div>

<div class="form-group">

<label>Morning Logout End</label>

<input
type="time"
name="morning_logout_end"
id="morning_logout_end"
value="12:00">

</div>

</div>

</div>

<!-- AFTERNOON -->

<div id="afternoon_section">

<div class="form-row">

<div class="form-group">

<label>Afternoon Login Start</label>

<input
type="time"
name="afternoon_login_start"
id="afternoon_login_start"
value="13:00">

</div>

<div class="form-group">

<label>Afternoon Login End</label>

<input
type="time"
name="afternoon_login_end"
id="afternoon_login_end"
value="13:30">

</div>

</div>

<div class="form-row">

<div class="form-group">

<label>Afternoon Logout Start</label>

<input
type="time"
name="afternoon_logout_start"
id="afternoon_logout_start"
value="16:30">

</div>

<div class="form-group">

<label>Afternoon Logout End</label>

<input
type="time"
name="afternoon_logout_end"
id="afternoon_logout_end"
value="17:00">

</div>

</div>

</div>

<!-- BUTTONS -->

<div class="btn-group">

<button
type="submit"
name="add_event"
class="btn btn-primary">

Create Event

</button>

<a
href="admin_dashboard.php"
class="btn btn-secondary">

Cancel

</a>

</div>

</form>

</div>

</div>

</div>

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

function toggleTimeFields(){

const select = document.getElementById('event_type_select');
const eventType = select ? select.value : 'Whole Day';

const morningSection =
document.getElementById(
"morning_section"
);

const afternoonSection =
document.getElementById(
"afternoon_section"
);

/* WHOLE DAY */

morningSection.style.display =
"block";

afternoonSection.style.display =
"block";

/* MORNING ONLY */

if(eventType === "Morning Only"){

morningSection.style.display =
"block";

afternoonSection.style.display =
"none";

}

/* AFTERNOON ONLY */

else if(
eventType === "Afternoon Only"
){

morningSection.style.display =
"none";

afternoonSection.style.display =
"block";

}

}

function validateFileSize(input, maxSizeMB){
    const maxSize = maxSizeMB * 1024 * 1024; // Convert MB to bytes
    const file = input.files[0];

    if(file){
        if(file.size > maxSize){
            alert('File size exceeds ' + maxSizeMB + 'MB limit. Please select a smaller file.');
            input.value = ''; // Clear the file input
            return false;
        }
    }
    return true;
}

</script>

</body>
</html>