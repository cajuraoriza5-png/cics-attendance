<?php
session_start();

if(!isset($_SESSION['student_id'])){
    header("Location: student_login.php");
    exit;
}

$student_id = $_SESSION['student_id'];

/* RUN PYTHON */
$output = shell_exec("python recognize.py");

/* GET RESULT (USER ID FROM PYTHON) */
$recognized_id = trim($output);

$conn = new mysqli("localhost","root","","attendance");

/* CHECK MATCH */
if($recognized_id == $student_id){

    /* INSERT ATTENDANCE */
    $status = "Present";

    $stmt = $conn->prepare("
        INSERT INTO attendance (student_id, status, date)
        VALUES (?, ?, CURDATE())
    ");
    $stmt->bind_param("is", $student_id, $status);
    $stmt->execute();

    echo "<script>alert('✅ Attendance Recorded'); window.location='student_dashboard.php';</script>";

}else{
    echo "<script>alert('❌ Face not recognized or mismatch'); window.location='student_dashboard.php';</script>";
}
?>