<?php
$conn = new mysqli("localhost","root","","attendance");

if(!isset($_GET['event_id'])){
    die("Event not found.");
}

$event_id = $_GET['event_id'];

/* ================= GET EVENT DETAILS ================= */

$event = $conn->query("
SELECT * FROM events 
WHERE id='$event_id'
")->fetch_assoc();

/* ================= GET STUDENT ATTENDANCE ================= */

$result = $conn->query("
SELECT 
u.student_id,
u.first_name,
u.last_name,
a.morning_status,
a.afternoon_status
FROM users u
LEFT JOIN attendance a 
ON u.student_id = a.student_id 
AND a.event_id = '$event_id'
WHERE u.role='student'
ORDER BY u.last_name ASC
");

/* ================= COUNTERS ================= */

$totalPresent = 0;
$totalLate = 0;
$totalAbsent = 0;

?>

<!DOCTYPE html>
<html>
<head>

<title>Event Attendance Report</title>

<style>

body{
font-family:Arial;
background:#f4f6f9;
padding:30px;
}

.card{
background:white;
padding:20px;
border-radius:10px;
box-shadow:0 3px 8px rgba(0,0,0,0.1);
margin-bottom:20px;
}

table{
width:100%;
border-collapse:collapse;
}

th,td{
padding:10px;
border-bottom:1px solid #ddd;
text-align:center;
}

th{
background:#0b3c70;
color:white;
}

.penalty{
color:red;
font-weight:bold;
}

.summary{
display:flex;
gap:20px;
margin-bottom:20px;
}

.box{
background:#0b3c70;
color:white;
padding:15px;
border-radius:8px;
flex:1;
text-align:center;
}

</style>

</head>

<body>

<div class="card">

<h2>Event Attendance Report</h2>

<strong>Event:</strong> <?php echo $event['event_name']; ?><br>
<strong>Date:</strong> <?php echo $event['event_date']; ?><br>

<strong>Late Penalty:</strong> ₱<?php echo $event['late_penalty']; ?><br>
<strong>Absent Penalty:</strong> ₱<?php echo $event['absent_penalty']; ?>

</div>

<?php

$rows = [];

while($row = $result->fetch_assoc()){

$ms = $row['morning_status'] ?? null;
$as = $row['afternoon_status'] ?? null;

/* ================= UNIFIED STATUS ================= */

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

/* ================= PENALTY (Whole Day = single; Morning/Afternoon Only = single session) ================= */
$late_p   = floatval($event['late_penalty']   ?? 0);
$absent_p = floatval($event['absent_penalty'] ?? 0);
$et = $event['event_type'] ?? '';
if($et === 'Morning Only'){
    $penalty = ($ms==='Late') ? $late_p : (($ms==='Absent') ? $absent_p : 0);
} elseif($et === 'Afternoon Only'){
    $penalty = ($as==='Late') ? $late_p : (($as==='Absent') ? $absent_p : 0);
} else {
    if($ms==='Present' || $as==='Present')   $penalty = 0;
    elseif($ms==='Late'   || $as==='Late')   $penalty = $late_p;
    elseif($ms==='Absent' || $as==='Absent') $penalty = $absent_p;
    else                                      $penalty = 0;
}

$row['status'] = $status;
$row['penalty'] = $penalty;

$rows[] = $row;

}

?>

<div class="summary">

<div class="box">
Total Present
<h2><?php echo $totalPresent; ?></h2>
</div>

<div class="box">
Total Late
<h2><?php echo $totalLate; ?></h2>
</div>

<div class="box">
Total Absent
<h2><?php echo $totalAbsent; ?></h2>
</div>

</div>

<div class="card">

<table>

<tr>
<th>Student ID</th>
<th>Student Name</th>
<th>Status</th>
<th>Penalty</th>
</tr>

<?php foreach($rows as $r): ?>

<tr>

<td><?php echo $r['student_id']; ?></td>

<td>
<?php echo $r['last_name']." ".$r['first_name']; ?>
</td>

<td><?php echo $r['status']; ?></td>

<td class="penalty">

<?php
if($r['penalty'] > 0){
echo "₱".$r['penalty'];
}else{
echo "-";
}
?>

</td>

</tr>

<?php endforeach; ?>

</table>

</div>

</body>
</html>