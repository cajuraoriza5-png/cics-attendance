<?php
date_default_timezone_set('Asia/Manila');
session_start();
if(!isset($_SESSION['student_id'])){
    header("Location: student_login.php");
    exit;
}

$config = require __DIR__ . '/config.php';
require __DIR__ . '/db.php';

$student_id = $_SESSION['student_id'];
$event_id = 1; // You can make this dynamic later
$current_time = date("H:i:s");
$current_date = date("Y-m-d");

/* TIME SETTINGS */
$morning_in_start  = "07:00:00"; $morning_in_end  = "07:30:00";
$morning_out_start = "11:30:00"; $morning_out_end = "12:00:00";
$afternoon_in_start  = "13:00:00"; $afternoon_in_end  = "13:30:00";
$afternoon_out_start = "16:30:00"; $afternoon_out_end = "17:00:00";

/* CHECK IF RECORD EXISTS TODAY */
$check = $conn->prepare("SELECT * FROM attendance WHERE student_id=? AND date=?");
$check->bind_param("is", $student_id, $current_date);
$check->execute();
$result = $check->get_result();

if($result->num_rows > 0){
    $attendance = $result->fetch_assoc();
}else{
    $attendance = null;
}

/* LOGIC */
$message = "";

if(isset($_POST['log_attendance'])){

    if(!$attendance){
        $insert = $conn->prepare("
            INSERT INTO attendance
            (student_id,event_id,date)
            VALUES (?,?,?)
        ");
        $insert->bind_param("iis",$student_id,$event_id,$current_date);
        $insert->execute();
    }

    $existing = $conn->query("SELECT * FROM attendance WHERE student_id='{$student_id}' AND date='{$current_date}'")->fetch_assoc();
    $old_penalty = floatval($existing['penalty'] ?? 0);
    $session = null; $direction = null;

    // Determine window
    if($current_time >= $morning_in_start && $current_time <= $morning_in_end){
        $session='morning'; $direction='in';
    } elseif($current_time >= $morning_out_start && $current_time <= $morning_out_end){
        $session='morning'; $direction='out';
    } elseif($current_time >= $afternoon_in_start && $current_time <= $afternoon_in_end){
        $session='afternoon'; $direction='in';
    } elseif($current_time >= $afternoon_out_start && $current_time <= $afternoon_out_end){
        $session='afternoon'; $direction='out';
    }

    if(!$session){
        $message = "Not within attendance time window.";
    } else {
        $inCol  = $session . '_in';
        $outCol = $session . '_out';
        $statusCol = $session . '_status';
        $session_penalty = 0.0;

        if($direction === 'in'){
            $loginEnd = ($session=='morning') ? $morning_in_end : $afternoon_in_end;
            if($current_time > $loginEnd){
                $status = 'Late';
                $session_penalty = 25.00;
            } else {
                $status = 'Present';
            }
            $total_penalty = $old_penalty + $session_penalty;
            $u = $conn->prepare("UPDATE attendance SET {$inCol}=?, {$statusCol}=?, penalty=? WHERE student_id=? AND date=?");
            $u->bind_param("ssdis", $current_time, $status, $total_penalty, $student_id, $current_date);
            $u->execute();
            $u->close();
            $message = ucfirst($session) . " In Recorded ($status)";
        } else {
            $u = $conn->prepare("UPDATE attendance SET {$outCol}=? WHERE student_id=? AND date=?");
            $u->bind_param("sis", $current_time, $student_id, $current_date);
            $u->execute();
            $u->close();
            $message = ucfirst($session) . " Out Recorded";
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
<title>Attendance</title>
<style>
body{
    margin:0;
    font-family:Arial;
    background:#f4f6f9;
}
.navbar{
    background:#0b3c70;
    color:white;
    padding:15px 30px;
    display:flex;
    justify-content:space-between;
    align-items:center;
}
.container{
    padding:40px;
}
.card{
    background:white;
    padding:30px;
    border-radius:12px;
    box-shadow:0 4px 12px rgba(0,0,0,0.1);
    max-width:400px;
    margin:auto;
    text-align:center;
}
button{
    background:#ffd400;
    border:none;
    padding:10px 20px;
    border-radius:8px;
    font-weight:bold;
    cursor:pointer;
}
.message{
    margin-top:20px;
    font-weight:bold;
    color:#0b3c70;
}
</style>
</head>
<body>

<div class="navbar">
    <h3>Attendance Logging</h3>
    <a href="student_dashboard.php" style="color:white;text-decoration:none;">Back</a>
</div>

<div class="container">
    <div class="card">
        <h3>Log Your Attendance</h3>
        <p>Current Time: <?php echo date("h:i A"); ?></p>

        <form method="POST">
            <button type="submit" name="log_attendance">
                Scan / Log Attendance
            </button>
        </form>

        <?php if($message!=""){ ?>
            <div class="message">
                <?php echo $message; ?>
            </div>
        <?php } ?>
    </div>
</div>

</body>
</html>