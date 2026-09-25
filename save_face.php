<?php
header('Content-Type: text/plain');

require_once __DIR__ . '/db.php';

if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error);
    die("Database connection error");
}

// --------------------------------------------------
// Validate input
// --------------------------------------------------

if (!isset($_POST['uid']) || !isset($_FILES['image'])) {
    error_log("Missing required parameters");
    die("Invalid request");
}

$uid = intval($_POST['uid']);
$index = intval($_POST['index'] ?? 0);

if ($uid <= 0) {
    error_log("Invalid user ID: " . $uid);
    die("Invalid user ID");
}

// --------------------------------------------------
// Validate uploaded file
// --------------------------------------------------

if (!isset($_FILES['image']['error']) ||
    $_FILES['image']['error'] !== UPLOAD_ERR_OK) {

    $errorCode = $_FILES['image']['error'] ?? -1;

    error_log("File upload error: " . $errorCode);
    die("File upload failed");
}

$tmpFile = $_FILES['image']['tmp_name'];

if (!is_uploaded_file($tmpFile)) {
    error_log("Uploaded file validation failed: " . $tmpFile);
    die("Invalid uploaded file");
}

// --------------------------------------------------
// Check actual image
// --------------------------------------------------

$imageInfo = @getimagesize($tmpFile);

if ($imageInfo === false) {
    error_log("getimagesize() failed. File may be corrupted.");
    die("Invalid image");
}

if ($imageInfo['mime'] !== 'image/jpeg') {
    error_log("Invalid MIME type: " . $imageInfo['mime']);
    die("Image must be JPEG");
}

// --------------------------------------------------
// Check image dimensions
// --------------------------------------------------

$width  = intval($imageInfo[0]);
$height = intval($imageInfo[1]);

if ($width <= 0 || $height <= 0) {
    error_log("Invalid dimensions: {$width}x{$height}");
    die("Invalid image dimensions");
}

// --------------------------------------------------
// Check uploaded file size
// --------------------------------------------------

$fileSize = filesize($tmpFile);

if ($fileSize === false || $fileSize < 10000) {
    error_log("Image too small: " . $fileSize . " bytes");
    die("Image file is too small");
}

// --------------------------------------------------
// Use ABSOLUTE project path
// --------------------------------------------------

$folder = __DIR__ . DIRECTORY_SEPARATOR . 'faces';

if (!is_dir($folder)) {
    if (!mkdir($folder, 0755, true)) {
        error_log("Failed to create faces directory: " . $folder);
        die("Directory creation failed");
    }
}

// --------------------------------------------------
// Generate filename
// --------------------------------------------------

$filename = $uid . "_" . $index . ".jpg";

$fullpath = $folder . DIRECTORY_SEPARATOR . $filename;

// --------------------------------------------------
// Move uploaded image
// --------------------------------------------------

if (!move_uploaded_file($tmpFile, $fullpath)) {

    error_log(
        "Failed to move uploaded file. " .
        "Source: " . $tmpFile .
        " Destination: " . $fullpath
    );

    die("File save failed");
}

// --------------------------------------------------
// Verify saved image AFTER moving
// --------------------------------------------------

$savedSize = filesize($fullpath);
$savedInfo = @getimagesize($fullpath);

if ($savedInfo === false) {

    error_log("Saved image cannot be read: " . $fullpath);

    @unlink($fullpath);

    die("Saved image is corrupted");
}

if ($savedInfo['mime'] !== 'image/jpeg') {

    error_log("Saved image is not JPEG");

    @unlink($fullpath);

    die("Saved image format invalid");
}

// Require reasonable file size
if ($savedSize < 10000) {

    error_log(
        "Saved image is suspiciously small: " .
        $savedSize . " bytes"
    );

    @unlink($fullpath);

    die("Saved image is too small");
}

// --------------------------------------------------
// Save database record
// --------------------------------------------------

$stmt = $conn->prepare(
    "INSERT INTO face_data
     (student_id, face_image, created_at)
     VALUES (?, ?, NOW())"
);

if ($stmt === false) {

    error_log("Prepare failed: " . $conn->error);

    @unlink($fullpath);

    die("Database error");
}

$stmt->bind_param("is", $uid, $filename);

if (!$stmt->execute()) {

    error_log(
        "Database insert failed: " .
        $stmt->error
    );

    @unlink($fullpath);

    $stmt->close();
    $conn->close();

    die("Database error");
}

$stmt->close();
$conn->close();

// --------------------------------------------------
// Success
// --------------------------------------------------

echo "success";
?>