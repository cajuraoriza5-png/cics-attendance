<?php
session_start();
$conn = new mysqli("localhost","root","","attendance");

if($conn->connect_error){
    die("Connection failed: " . $conn->connect_error);
}

/* FILTERS */
$eventFilter  = $_GET['event_id'] ?? '';
$courseFilter = $_GET['course'] ?? '';
$search       = $_GET['search'] ?? '';

$query = "
SELECT a.*, u.first_name, u.last_name, u.course,
       e.event_name, e.event_date, e.event_type,
       e.late_penalty, e.absent_penalty
FROM attendance a
JOIN users u ON a.student_id = u.id
JOIN events e ON a.event_id = e.id
WHERE 1
";

if($eventFilter != ''){
$query .= " AND a.event_id='$eventFilter'";
}

if($courseFilter != ''){
$query .= " AND u.course='$courseFilter'";
}

if($search != ''){
$query .= " AND (u.first_name LIKE '%$search%' OR u.last_name LIKE '%$search%')";
}

$query .= " ORDER BY e.event_date DESC";

$reports = $conn->query($query);

// Handle cash payment acceptance
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'accept_cash'){
    $cash_sid = intval($_POST['cash_student_id']);
    $cash_amount = floatval($_POST['cash_amount']);
    $cash_date = $_POST['cash_date'];
    $admin_id = $_SESSION['admin_id'];
    
    if($cash_amount > 0){
        $stmt = $conn->prepare("INSERT INTO payments (student_id, amount, payment_method, payment_date, status, admin_notes, confirmed_by, confirmed_at) VALUES (?, ?, 'Cash', ?, 'Confirmed', 'Cash payment received at counter', ?, NOW())");
        $stmt->bind_param("idsi", $cash_sid, $cash_amount, $cash_date, $admin_id);
        $stmt->execute();
    }
}

/* SUMMARY */
$totalPresent = 0;
$totalLate    = 0;
$totalAbsent  = 0;

$temp = $conn->query($query);
while($r = $temp->fetch_assoc()){

$ms = $r['morning_status'] ?? '';
$as = $r['afternoon_status'] ?? '';

if($ms == 'Present' || $as == 'Present'){
    $status = 'Present';
    $totalPresent++;
}
elseif($ms == 'Late' || $as == 'Late'){
    $status = 'Late';
    $totalLate++;
}
else{
    $status = 'Absent';
    $totalAbsent++;
}

$r['status'] = $status;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Attendance Reports</title>

<script src="https://cdn.jsdelivr.net/npm/xlsx/dist/xlsx.full.min.js"></script>

<style>
*{
margin:0;
padding:0;
box-sizing:border-box;
font-family:'Segoe UI',sans-serif;
}

body{
background:#f5f7fb;
padding:25px;
}

.container{
max-width:1500px;
margin:auto;
}

/* HEADER */
.header{
background:linear-gradient(145deg,#ffffff,#fff8d6);
padding:25px;
border-radius:22px;
box-shadow:0 10px 25px rgba(0,0,0,.05);
margin-bottom:22px;
}

.header h1{
font-size:34px;
color:#111;
margin-bottom:8px;
}

.header p{
color:#666;
}

/* FILTER BOX */
.card{
background:#fff;
padding:22px;
border-radius:18px;
box-shadow:0 10px 25px rgba(0,0,0,.05);
margin-bottom:22px;
}

form{
display:grid;
grid-template-columns:repeat(auto-fit,minmax(200px,1fr));
gap:12px;
margin-bottom:15px;
}

select,input{
padding:12px;
border-radius:12px;
border:1px solid #ddd;
font-size:15px;
outline:none;
}

select:focus,input:focus{
border-color:#FFD700;
}

.btn-group{
display:flex;
gap:10px;
flex-wrap:wrap;
}

button{
padding:12px 18px;
border:none;
border-radius:12px;
font-weight:800;
cursor:pointer;
transition:.25s;
}

.gold{
background:linear-gradient(135deg,#FFD700,#FFC107);
color:#111;
}

.dark{
background:#111;
color:#fff;
}

button:hover{
transform:translateY(-2px);
}

/* STATS */
.stats{
display:grid;
grid-template-columns:repeat(auto-fit,minmax(220px,1fr));
gap:16px;
margin-top:15px;
}

.stat{
padding:22px;
border-radius:18px;
color:#fff;
text-align:center;
font-weight:800;
box-shadow:0 10px 20px rgba(0,0,0,.06);
}

.stat h2{
font-size:38px;
margin-top:8px;
}

.present{
background:#28a745;
}

.late{
background:#ff9800;
}

.absent{
background:#dc3545;
}

/* TABLE */
.table-wrap{
overflow:auto;
border-radius:16px;
}

table{
width:100%;
border-collapse:collapse;
min-width:900px;
}

th{
background:#FFD700;
padding:14px;
color:#111;
text-align:center;
font-size:15px;
}

td{
padding:14px;
text-align:center;
border-bottom:1px solid #eee;
font-size:14px;
}

tr:hover{
background:#fffbea;
}

.badge{
padding:6px 10px;
border-radius:50px;
font-size:12px;
font-weight:800;
display:inline-block;
}

.present-b{
background:#d4edda;
color:#155724;
}

.late-b{
background:#fff3cd;
color:#856404;
}

.absent-b{
background:#f8d7da;
color:#721c24;
}

.money{
font-weight:900;
color:#dc2626;
}

.empty{
padding:40px;
text-align:center;
font-size:18px;
color:#888;
font-weight:700;
}

/* MOBILE */
@media(max-width:768px){

body{
padding:15px;
}

.header h1{
font-size:28px;
}

}
</style>
</head>
<body>

<div class="container">

<!-- HEADER -->
<div class="header">
<h1>📊 Attendance Reports</h1>
<p>Monitor attendance, penalties, and export reports.</p>
</div>

<!-- FILTER -->
<div class="card">

<form method="GET">

<select name="event_id">
<option value="">All Events</option>
<?php
$events = $conn->query("SELECT id,event_name,event_date FROM events ORDER BY event_date DESC");
while($e = $events->fetch_assoc()){ ?>
<option value="<?= $e['id'] ?>" <?= $eventFilter==$e['id']?"selected":"" ?>>
<?= $e['event_name']." (".$e['event_date'].")" ?>
</option>
<?php } ?>
</select>

<select name="course">
<option value="">All Courses</option>
<option value="BSCS" <?= $courseFilter=="BSCS"?"selected":"" ?>>BSCS</option>
<option value="BSIT" <?= $courseFilter=="BSIT"?"selected":"" ?>>BSIT</option>
<option value="BLIS" <?= $courseFilter=="BLIS"?"selected":"" ?>>BLIS</option>
</select>

<input type="text" name="search" placeholder="Search student..." value="<?= $search ?>">

<div class="btn-group">
<button type="submit" class="gold">🔍 Filter</button>
<button type="button" onclick="exportExcel()" class="gold">📥 Export Excel</button>
<button type="button" onclick="window.print()" class="dark">🖨 Print</button>
</div>

</form>

<!-- STATS -->
<div class="stats">

<div class="stat present">
Present
<h2><?= $totalPresent ?></h2>
</div>

<div class="stat late">
Late
<h2><?= $totalLate ?></h2>
</div>

<div class="stat absent">
Absent
<h2><?= $totalAbsent ?></h2>
</div>

</div>

</div>

<!-- TABLE -->
<div class="card">

<div class="table-wrap">

<?php if($reports->num_rows == 0){ ?>

<div class="empty">📭 No records found.</div>

<?php } else { ?>

<table id="reportTable">

<tr>
<th>Event</th>
<th>Date</th>
<th>Student Name</th>
<th>Course</th>
<th>Status</th>
<th>Penalty</th>
<th>Action</th>
</tr>

<?php while($row = $reports->fetch_assoc()){

$ms = $row['morning_status'] ?? '';
$as = $row['afternoon_status'] ?? '';

if($ms == 'Present' || $as == 'Present'){
    $status = 'Present';
}
elseif($ms == 'Late' || $as == 'Late'){
    $status = 'Late';
}
else{
    $status = 'Absent';
}

// Compute penalty: Whole Day = single unified penalty; Morning/Afternoon Only = single session
$late_p   = floatval($row['late_penalty']   ?? 0);
$absent_p = floatval($row['absent_penalty'] ?? 0);
$et = $row['event_type'] ?? '';
$rms = $row['morning_status']   ?? '';
$ras = $row['afternoon_status'] ?? '';
if($et === 'Morning Only'){
    $penalty = ($rms==='Late') ? $late_p : (($rms==='Absent') ? $absent_p : 0);
} elseif($et === 'Afternoon Only'){
    $penalty = ($ras==='Late') ? $late_p : (($ras==='Absent') ? $absent_p : 0);
} else {
    if($rms==='Present' || $ras==='Present')     $penalty = 0;
    elseif($rms==='Late'   || $ras==='Late')     $penalty = $late_p;
    elseif($rms==='Absent' || $ras==='Absent')   $penalty = $absent_p;
    else                                          $penalty = 0;
}

$class = "present-b";
if($status=="Late") $class="late-b";
if($status=="Absent") $class="absent-b";
?>

<tr>
<td><?= $row['event_name'] ?></td>
<td><?= $row['event_date'] ?></td>
<td><?= $row['first_name']." ".$row['last_name'] ?></td>
<td><?= $row['course'] ?></td>
<td><span class="badge <?= $class ?>"><?= $status ?></span></td>
<td class="money">₱<?= number_format($penalty,2) ?></td>
<td>
<?php if($penalty > 0): ?>
<button class="gold" style="padding:8px 12px;font-size:12px;" onclick="acceptCashPayment(<?= $row['student_id'] ?>, <?= $penalty ?>, '<?= $row['first_name']." ".$row['last_name'] ?>')">💵 Pay Cash</button>
<?php else: ?>
<span style="color:#888;font-size:12px;">-</span>
<?php endif; ?>
</td>
</tr>

<?php } ?>

</table>

<?php } ?>

</div>
</div>

</div>

<!-- Cash Payment Modal -->
<div id="cashModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.8);z-index:1000;">
<div style="background:#fff;padding:30px;border-radius:18px;max-width:400px;margin:10% auto;position:relative;">
<span onclick="closeCashModal()" style="position:absolute;right:20px;top:20px;font-size:28px;cursor:pointer;font-weight:bold;color:#666;">&times;</span>
<h3 style="margin-bottom:20px;color:#111;">💵 Accept Cash Payment</h3>
<form method="POST">
<input type="hidden" name="action" value="accept_cash">
<input type="hidden" name="cash_student_id" id="cash_student_id">

<div style="margin-bottom:15px;">
<label style="display:block;margin-bottom:5px;font-weight:600;color:#333;">Student</label>
<div id="cash_student_name" style="color:#111;font-weight:bold;"></div>
</div>

<div style="margin-bottom:15px;">
<label for="cash_amount" style="display:block;margin-bottom:5px;font-weight:600;color:#333;">Amount (₱)</label>
<input type="number" name="cash_amount" id="cash_amount" step="0.01" min="0.01" required style="width:100%;padding:12px;border-radius:12px;border:1px solid #ddd;font-size:15px;outline:none;">
</div>

<div style="margin-bottom:15px;">
<label for="cash_date" style="display:block;margin-bottom:5px;font-weight:600;color:#333;">Payment Date</label>
<input type="date" name="cash_date" id="cash_date" value="<?= date('Y-m-d') ?>" required style="width:100%;padding:12px;border-radius:12px;border:1px solid #ddd;font-size:15px;outline:none;">
</div>

<div style="text-align:center;margin-top:20px;">
<button type="submit" class="gold">Accept Payment</button>
<button type="button" onclick="closeCashModal()" class="dark">Cancel</button>
</div>
</form>
</div>
</div>

<script>
function acceptCashPayment(studentId, outstandingAmount, studentName){
document.getElementById('cash_student_id').value = studentId;
document.getElementById('cash_student_name').textContent = studentName;
document.getElementById('cash_amount').value = outstandingAmount;
document.getElementById('cashModal').style.display = 'block';
}

function closeCashModal(){
document.getElementById('cashModal').style.display = 'none';
document.getElementById('cash_amount').value = '';
}

// Close modal when clicking outside
window.onclick = function(event){
const cashModal = document.getElementById('cashModal');
if(event.target == cashModal){
closeCashModal();
}
}

function exportExcel(){
var table = document.getElementById("reportTable");
var wb = XLSX.utils.table_to_book(table,{sheet:"Attendance Report"});
XLSX.writeFile(wb,"attendance_report.xlsx");
}
</script>

</body>
</html>