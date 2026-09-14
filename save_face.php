<?php
header('Content-Type: text/plain');

$config = require __DIR__ . '/config.php';
require __DIR__ . '/db.php';

// Validate input
if(!isset($_POST['uid']) || !isset($_FILES['image'])){
    error_log("Missing required parameters");
    die("Invalid request");
}

$uid = intval($_POST['uid']);
$index = intval($_POST['index'] ?? 0);

if($uid <= 0){
    error_log("Invalid user ID: $uid");
    die("Invalid user ID");
}

// Validate file upload
if($_FILES['image']['error'] !== UPLOAD_ERR_OK){
    error_log("File upload error: " . $_FILES['image']['error']);
    die("File upload failed");
}

// Check file type
$imageInfo = getimagesize($_FILES['image']['tmp_name']);
if($imageInfo === false || $imageInfo['mime'] !== 'image/jpeg'){
    error_log("Invalid image type");
    die("Invalid image format");
}

// Create faces directory if it doesn't exist
$folder = "faces/";
if(!is_dir($folder)){
    if(!mkdir($folder, 0755, true)){
        error_log("Failed to create faces directory");
        die("Directory creation failed");
    }
}

// Generate filename with user ID and index for training compatibility
$filename = $uid . "_" . $index . ".jpg";
$fullpath = $folder . $filename;

// Move uploaded file
if(move_uploaded_file($_FILES['image']['tmp_name'], $fullpath)){
    
    // Insert into face_data table
    $stmt = $conn->prepare("INSERT INTO face_data(student_id, face_image, created_at) VALUES (?, ?, NOW())");
    if($stmt === false){
        error_log("Prepare failed: " . $conn->error);
        die("Database error");
    }
    
    $stmt->bind_param("is", $uid, $filename);
    
    if($stmt->execute()){
        echo "success";
    }else{
        error_log("Database insert failed: " . $stmt->error);
        // Remove uploaded file if database insert failed
        unlink($fullpath);
        die("Database error");
    }
    
    $stmt->close();
    
}else{
    error_log("Failed to move uploaded file to: $fullpath");
    die("File save failed");
}

$conn->close();
?>