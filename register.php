<?php
session_start();
include("db.php");

$error = "";

if($_SERVER["REQUEST_METHOD"] == "POST"){

$username   = trim($_POST['username']);
$password   = $_POST['password'];
$confirm    = $_POST['confirm_password'];

$first_name  = trim($_POST['first_name']);
$middle_name = trim($_POST['middle_name'] ?? '');
$last_name   = trim($_POST['last_name']);
$student_id = trim($_POST['student_id']);
$age         = intval($_POST['age']);
$gender      = $_POST['gender'];
$email       = trim($_POST['email']);
$phone       = trim($_POST['phone']);

$course      = $_POST['course'];
$year_level  = $_POST['year_level'];
$section     = trim($_POST['section']);
$school_year = $_POST['school_year'];
$semester    = $_POST['semester'];

// Validation
if($password != $confirm){
$error = "Passwords do not match.";
}
elseif(strlen($username) < 3){
$error = "Username must be at least 3 characters long.";
}
elseif(strlen($password) < 6){
$error = "Password must be at least 6 characters long.";
}
elseif(!filter_var($email, FILTER_VALIDATE_EMAIL)){
$error = "Invalid email address.";
}
elseif(!preg_match("/^63[0-9]{10}$/",$phone)){
$error = "Phone number must be exactly 10 digits (e.g., 9123456789).";
}
elseif($age < 16 || $age > 60){
$error = "Age must be between 16 and 60.";
}
elseif(!preg_match("/^\d{4}-\d{4}[A-Z]$/",$student_id)){
$error = "Invalid Student ID format. Example: 2023-0970E";
}
else{

$check = $conn->prepare("SELECT id FROM users WHERE username=? OR student_id=? OR email=?");
$check->bind_param("sss",$username,$student_id,$email);
$check->execute();
$res = $check->get_result();

if($res->num_rows > 0){
$error = "Username, Student ID, or Email already exists.";
}else{

$hash = password_hash($password,PASSWORD_DEFAULT);

$is_officer = 0;

$stmt = $conn->prepare("
INSERT INTO users(
username,password,student_id,
first_name,last_name,middle_name,age,gender,email,phone,
course,year_level,section,
is_officer,school_year,current_semester
)
VALUES(
?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?
)");

if(!$stmt){
die("SQL ERROR: " . $conn->error);
}

$type_string = "ssssssisssssssis";
echo "Type string length: " . strlen($type_string) . "<br>";
echo "Variables: username, hash, student_id, first_name, last_name, middle_name, age, gender, email, phone, course, year_level, section, is_officer, school_year, semester<br>";

$stmt->bind_param(
$type_string,
$username,$hash,$student_id,
$first_name,$last_name,$middle_name,$age,$gender,$email,$phone,
$course,$year_level,$section,
$is_officer,$school_year,$semester
);

if($stmt->execute()){

$uid = $conn->insert_id;

echo "<script>
alert('Registration Successful! Please enroll your face to complete the process.');
window.location='face_enroll.php?uid=$uid';
</script>";
exit;

}else{
$error = "Registration failed. Please try again.";
}

}
}
}
?>

<!DOCTYPE html>
<html>
<head>
<title>Student Registration</title>

<style>

*{
margin:0;
padding:0;
box-sizing:border-box;
font-family:'Segoe UI',Arial,sans-serif;
}

body{
background:
linear-gradient(rgba(0,0,0,.92),rgba(0,0,0,.92)),
url('images/bg.jpg');
background-size:cover;
background-position:center;
background-attachment:fixed;
min-height:100vh;
padding:12px;
color:#fff;
}

/* Custom scrollbar */
::-webkit-scrollbar{
width:10px;
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

.content{
padding:12px 18px;
}

.error{
background:#481717;
border:1px solid #a83b3b;
color:#ffb1b1;
padding:12px 15px;
border-radius:10px;
margin-bottom:18px;
font-size:14px;
}

.section{
margin-bottom:12px;
}

.section-title{
font-size:18px;
font-weight:700;
color:#FFD700;
margin-bottom:8px;
padding-bottom:4px;
border-bottom:1px solid rgba(255,215,0,.18);
}

.grid{
display:grid;
grid-template-columns:repeat(2,1fr);
gap:8px;
align-items:start;
}

input,
select{
width:100%;
height:42px;
padding:0 12px;
border-radius:8px;
border:1px solid rgba(255,215,0,.22);
background:#1a1a1a;
color:white;
font-size:13px;
outline:none;
transition:.25s;
}

select option{
background:#1a1a1a;
color:white;
}

input:focus,
select:focus{
border-color:#FFD700;
box-shadow:0 0 12px rgba(255,215,0,.25);
}

.phone-box{
display:flex;
height:42px;
}

.prefix{
display:flex;
align-items:center;
justify-content:center;
padding:0 12px;
background:#262626;
border:1px solid rgba(255,215,0,.22);
border-right:none;
border-radius:8px 0 0 8px;
color:#FFD700;
font-weight:700;
font-size:13px;
}

.phone-box input{
height:42px;
border-radius:0 8px 8px 0;
}

.password-box{
position:relative;
}

.password-box input{
padding-right:40px;
height:42px;
}

.password-box span{
position:absolute;
right:15px;
top:50%;
transform:translateY(-50%);
cursor:pointer;
font-size:18px;
display:flex;
align-items:center;
justify-content:center;
}


.form-hint{
margin-top:6px;
font-size:12px;
color:#bdbdbd;
}

.form-hint .hint-example{
color:#FFD700;
font-weight:700;
}

input.input-error{
border-color:#dc3545 !important;
box-shadow:0 0 10px rgba(220,53,69,.25);
}

.actions{
display:grid;
grid-template-columns:1fr 1fr;
gap:10px;
margin-top:12px;
}

.btn{
height:44px;
border:none;
border-radius:22px;
font-size:14px;
font-weight:700;
cursor:pointer;
text-decoration:none;
display:flex;
align-items:center;
justify-content:center;
transition:.25s;
}

.primary{
background:#FFD700;
color:#000;
}

.primary:hover{
transform:translateY(-2px);
box-shadow:0 8px 20px rgba(255,215,0,.25);
}

.secondary{
background:#2a2a2a;
color:#fff;
border:1px solid rgba(255,215,0,.22);
}

.secondary:hover{
background:#343434;
}

@media(max-width:1200px){

.grid{
grid-template-columns:1fr;
}

}

@media(max-width:768px){

body{
padding:8px;
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

.section-title{
font-size:16px;
margin-bottom:6px;
padding-bottom:3px;
}

.grid{
grid-template-columns:1fr;
gap:10px;
}

input,
select{
height:40px;
font-size:13px;
}

.phone-box{
height:40px;
}

.phone-box input{
height:40px;
}

.password-box input{
height:40px;
padding-right:38px;
}

.actions{
grid-template-columns:1fr;
gap:8px;
margin-top:10px;
}

.btn{
height:42px;
font-size:13px;
}

}

</style>

<script>
function togglePass(id){
let x = document.getElementById(id);
x.type = x.type === "password" ? "text" : "password";
}

function enforcePhoneDigits(input){
// Strip any non-digit characters
input.value = input.value.replace(/\D/g,'');
// Limit to 10 digits
if(input.value.length > 10) input.value = input.value.slice(0,10);
}

function preparePhone(){
let digits = document.getElementById('phone_digits').value;
document.getElementById('phone').value = '63' + digits;
return true;
}

// Auto-format Student ID as YYYY-NNNNL
function formatStudentId(input){
let raw = input.value.toUpperCase().replace(/[^0-9A-Z\-]/g,'');

// Extract digit and letter parts
let numPart = '';
let letterPart = '';
let i = 0;

// Collect up to 8 digits (strip the dash)
let digitsOnly = raw.replace(/[^0-9]/g,'');
let lettersOnly = raw.replace(/[^A-Z]/g,'');

let year = digitsOnly.slice(0,4);
let seq  = digitsOnly.slice(4,8);
letterPart = lettersOnly.slice(0,1);

let result = year;
if(seq.length > 0) result += '-' + seq;
else if(year.length === 4 && (raw.endsWith('-') || raw.length > 4)) result += '-';
if(seq.length === 4 && letterPart) result += letterPart;

input.value = result;

// Live hint feedback
let hint = document.getElementById('student_id_hint');
if(!hint) return;
let valid = /^\d{4}-\d{4}[A-Z]$/.test(input.value);
if(input.value.length === 0){
  hint.style.color = 'rgba(255,255,255,0.55)';
  hint.querySelector('.hint-label').textContent = 'Format:';
  input.classList.remove('input-error');
} else if(valid){
  hint.style.color = 'rgba(81,207,102,0.9)';
  hint.querySelector('.hint-label').textContent = '\u2714 Valid format';
  input.classList.remove('input-error');
} else {
  hint.style.color = 'rgba(255,107,107,0.9)';
  hint.querySelector('.hint-label').textContent = '\u2716 Format:';
}
}

function calculateAge(){
let b = document.getElementById("birthdate").value;
if(!b) return;

let today = new Date();
let birth = new Date(b);

let age = today.getFullYear() - birth.getFullYear();
let m = today.getMonth() - birth.getMonth();

if(m < 0 || (m === 0 && today.getDate() < birth.getDate())){
age--;
}

document.getElementById("age").value = age;
}

function loadRegion(){
fetch("get_region.php")
.then(res => res.text())
.then(data => {
document.getElementById("region").innerHTML = data;
});
}

function loadProvince(){
let region = document.getElementById("region").value;

fetch("get_province.php?region="+region)
.then(res => res.text())
.then(data => {
document.getElementById("province").innerHTML = data;
});
}

function loadMunicipality(){
let province = document.getElementById("province").value;

fetch("get_municipality.php?province="+province)
.then(res => res.text())
.then(data => {
document.getElementById("municipality").innerHTML = data;
});
}

function loadBarangay(){
let municipality = document.getElementById("municipality").value;

fetch("get_barangay.php?municipality="+municipality)
.then(res => res.text())
.then(data => {
document.getElementById("barangay").innerHTML = data;
});
}

function setZip(){
let municipality = document.getElementById("municipality").value;

fetch("get_zipcode.php?municipality="+municipality)
.then(res => res.text())
.then(data => {
document.getElementById("zip_code").value = data;
});
}

window.onload = loadRegion;
</script>

</head>
<body>

<div class="wrapper">

<div class="topbar">
<h1>🎓 Student Registration</h1>
<p>Face-In, Penalty-Out | College of Information and Computing Studies</p>
</div>

<div class="content">

<?php if($error!=""){ ?>
<div class="error"><?php echo $error; ?></div>
<?php } ?>

<form method="POST" onsubmit="return preparePhone()">

<div class="section">
<div class="section-title">Personal Information</div>

<div class="grid">

<input type="text" name="username" placeholder="Username" value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" required>

<input type="text" name="first_name" placeholder="First Name" value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>" required>

<input type="text" name="middle_name" placeholder="Middle Name (Optional)" value="<?php echo htmlspecialchars($_POST['middle_name'] ?? ''); ?>">

<input type="text" name="last_name" placeholder="Last Name" value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>" required>

<div>
 <input type="text" name="student_id" id="student_id_input"
  placeholder="e.g., 2023-0970E"
  maxlength="10"
  oninput="formatStudentId(this)"
  value="<?php echo htmlspecialchars($_POST['student_id'] ?? ''); ?>"
  class="<?php echo (strpos($error,'Student ID') !== false) ? 'input-error' : ''; ?>"
  required>
 <div class="form-hint" id="student_id_hint">
  <span class="hint-label">Format:</span>
  <span class="hint-example">YYYY-NNNNL</span>
  <span style="color:rgba(255,255,255,0.45);font-size:12px;">&nbsp;(e.g., 2023-0970E)</span>
 </div>
</div>

<input type="number" name="age" placeholder="Age" min="16" max="60" value="<?php echo htmlspecialchars($_POST['age'] ?? ''); ?>" required>

<select name="gender" required>
<option value="">Select Gender</option>
<option value="Male" <?php echo (($_POST['gender'] ?? '')==='Male'?'selected':''); ?>>Male</option>
<option value="Female" <?php echo (($_POST['gender'] ?? '')==='Female'?'selected':''); ?>>Female</option>
</select>

<input type="email" name="email" placeholder="Email Address" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>

<div class="phone-box">
    <span class="prefix">+63</span>
    <input type="tel" id="phone_digits"
           placeholder="9XXXXXXXXX"
           maxlength="10"
           pattern="[0-9]{10}"
           inputmode="numeric"
           oninput="enforcePhoneDigits(this)"
           value="<?php echo htmlspecialchars(substr($_POST['phone'] ?? '', 2)); ?>"
           required>
    <!-- Hidden field carries the full number to PHP -->
    <input type="hidden" id="phone" name="phone" value="">
</div>

<div class="password-box">
<input type="password" name="password" id="pass1" placeholder="Password" required>
<span onclick="togglePass('pass1')">👁</span>
</div>

<div class="password-box">
<input type="password" name="confirm_password" id="pass2" placeholder="Confirm Password" required>
<span onclick="togglePass('pass2')">👁</span>
</div>

</div>
</div>

<div class="section">
<div class="section-title">Academic Information</div>

<div class="grid">

<select name="course" required>
<option value="">Select Course</option>
<option value="BSCS" <?php echo (($_POST['course'] ?? '')==='BSCS'?'selected':''); ?>>Bachelor of Science in Computer Science</option>
<option value="BSIT" <?php echo (($_POST['course'] ?? '')==='BSIT'?'selected':''); ?>>Bachelor of Science in Information Technology</option>
<option value="BLIS" <?php echo (($_POST['course'] ?? '')==='BLIS'?'selected':''); ?>>Bachelor of Library and Information Science</option>
</select>

<select name="year_level" required>
<option value="">Select Year Level</option>
<option value="1st Year" <?php echo (($_POST['year_level'] ?? '')==='1st Year'?'selected':''); ?>>1st Year</option>
<option value="2nd Year" <?php echo (($_POST['year_level'] ?? '')==='2nd Year'?'selected':''); ?>>2nd Year</option>
<option value="3rd Year" <?php echo (($_POST['year_level'] ?? '')==='3rd Year'?'selected':''); ?>>3rd Year</option>
<option value="4th Year" <?php echo (($_POST['year_level'] ?? '')==='4th Year'?'selected':''); ?>>4th Year</option>
</select>

<input type="text" name="section" placeholder="Section (e.g., A, B, C)" value="<?php echo htmlspecialchars($_POST['section'] ?? ''); ?>" required>

<select name="school_year" required>
<option value="">Select School Year</option>
<?php
$currentYear = date('Y');
for($i = 0; $i < 5; $i++){
    $sy = ($currentYear + $i) . '-' . ($currentYear + $i + 1);
    $selected = (($_POST['school_year'] ?? '') === $sy) ? 'selected' : '';
    echo "<option value=\"$sy\" $selected>$sy</option>";
}
?>
</select>

<select name="semester" required>
<option value="">Select Semester</option>
<option value="1st Semester" <?php echo (($_POST['semester'] ?? '')==='1st Semester'?'selected':''); ?>>1st Semester</option>
<option value="2nd Semester" <?php echo (($_POST['semester'] ?? '')==='2nd Semester'?'selected':''); ?>>2nd Semester</option>
<option value="Summer" <?php echo (($_POST['semester'] ?? '')==='Summer'?'selected':''); ?>>Summer</option>
</select>

</div>
</div>

<div class="actions">
<button type="submit" class="btn primary"> Register Account</button>
<a href="student_login.php" class="btn secondary">⬅ Back to Login</a>
</div>

</form>

</div>
</div>

</body>
</html>
