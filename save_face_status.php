<?php
header('Content-Type: text/plain');

$conn = new mysqli("localhost","root","","attendance");

if($conn->connect_error){
    error_log("Database connection failed: " . $conn->connect_error);
    die("Database connection error");
}

// Validate input
if(!isset($_GET['uid'])){
    error_log("Missing user ID");
    die("Invalid request");
}

$uid = intval($_GET['uid']);

if($uid <= 0){
    error_log("Invalid user ID: $uid");
    die("Invalid user ID");
}

// Check if user has face images before marking as registered
$check = $conn->prepare("SELECT COUNT(*) as count FROM face_data WHERE student_id = ?");
if($check === false){
    error_log("Prepare failed: " . $conn->error);
    die("Database error");
}

$check->bind_param("i", $uid);
$check->execute();
$result = $check->get_result();
$row = $result->fetch_assoc();

if($row['count'] < 3){ // Require at least 3 face images
    error_log("Insufficient face images for user $uid: " . $row['count']);
    die("Insufficient face images");
}

// Update user as face registered
$stmt = $conn->prepare("UPDATE users SET face_registered = 1 WHERE id = ?");
if($stmt === false){
    error_log("Prepare failed: " . $conn->error);
    die("Database error");
}

$stmt->bind_param("i", $uid);

if($stmt->execute()){
    if($stmt->affected_rows > 0){
        echo "success";
    }else{
        error_log("User $uid not found or already registered");
        echo "success"; // Still return success if already registered
    }
}else{
    error_log("Update failed: " . $stmt->error);
    die("Database error");
}

$stmt->close();
$check->close();
$conn->close();
?>