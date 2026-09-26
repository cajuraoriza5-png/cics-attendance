<?php

header('Content-Type: text/plain');

include(__DIR__ . "/db.php");

if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error);
    die("Database connection error");
}

/*
============================================================
VALIDATE REQUEST
============================================================
*/

if (!isset($_POST['uid']) || !isset($_FILES['image'])) {
    error_log("Missing uid or image");
    die("Invalid request");
}

$uid = intval($_POST['uid']);
$index = intval($_POST['index'] ?? 0);

if ($uid <= 0) {
    error_log("Invalid user ID: " . $uid);
    die("Invalid user ID");
}

/*
============================================================
VALIDATE UPLOAD
============================================================
*/

if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {

    error_log(
        "Upload error: " .
        $_FILES['image']['error']
    );

    die("File upload failed");
}

$tmpFile = $_FILES['image']['tmp_name'];

if (!is_uploaded_file($tmpFile)) {
    error_log("Not a valid uploaded file.");
    die("Invalid uploaded file");
}

/*
============================================================
CHECK FILE SIZE
============================================================
*/

$fileSize = filesize($tmpFile);

error_log(
    "Face upload: UID={$uid}, INDEX={$index}, SIZE={$fileSize}"
);

/*
 * Reject tiny/corrupted files.
 */
if ($fileSize < 5000) {

    error_log(
        "REJECTED SMALL IMAGE: UID={$uid}, INDEX={$index}, SIZE={$fileSize}"
    );

    die("Face image is too small or corrupted");
}

/*
============================================================
CHECK ACTUAL IMAGE
============================================================
*/

$imageInfo = @getimagesize($tmpFile);

if ($imageInfo === false) {

    error_log(
        "Invalid image: UID={$uid}, INDEX={$index}"
    );

    die("Invalid image file");
}

if (($imageInfo['mime'] ?? '') !== 'image/jpeg') {

    error_log(
        "Wrong MIME type: " .
        ($imageInfo['mime'] ?? 'unknown')
    );

    die("Image must be JPEG");
}

/*
============================================================
CORRECT FACES DIRECTORY
============================================================

__DIR__ points to:

C:\Users\840G3\OneDrive\Desktop\cics-attendance

Therefore this becomes:

C:\Users\840G3\OneDrive\Desktop\cics-attendance\faces
*/

$facesDir = __DIR__ . DIRECTORY_SEPARATOR . "faces";

/*
============================================================
CREATE FACES DIRECTORY IF NECESSARY
============================================================
*/

if (!is_dir($facesDir)) {

    if (!mkdir($facesDir, 0755, true)) {

        error_log(
            "Could not create faces directory: " .
            $facesDir
        );

        die("Could not create faces directory");
    }
}

/*
============================================================
MAKE SURE FACES IS NOT INSIDE ANOTHER FACES FOLDER
============================================================
*/

error_log(
    "Saving face to: " . $facesDir
);

/*
============================================================
FILENAME
============================================================
*/

$filename = $uid . "_" . $index . ".jpg";

$destination = $facesDir .
    DIRECTORY_SEPARATOR .
    $filename;

/*
============================================================
SAVE IMAGE
============================================================
*/

if (!move_uploaded_file($tmpFile, $destination)) {

    error_log(
        "Failed to save face: " .
        $destination
    );

    die("Failed to save face image");
}

/*
============================================================
VERIFY SAVED IMAGE
============================================================
*/

if (!file_exists($destination)) {

    error_log(
        "Saved file does not exist: " .
        $destination
    );

    die("Face image was not saved");
}

$savedSize = filesize($destination);

if ($savedSize < 5000) {

    error_log(
        "Saved image is too small: " .
        $destination .
        " (" .
        $savedSize .
        " bytes)"
    );

    @unlink($destination);

    die("Saved face image is corrupted");
}

$verifyImage = @getimagesize($destination);

if ($verifyImage === false) {

    error_log(
        "Saved file is not a valid image: " .
        $destination
    );

    @unlink($destination);

    die("Saved face image is invalid");
}

/*
============================================================
DATABASE
============================================================
*/

$stmt = $conn->prepare(
    "INSERT INTO face_data
    (student_id, face_image, created_at)
    VALUES (?, ?, NOW())"
);

if ($stmt === false) {

    error_log(
        "Database prepare failed: " .
        $conn->error
    );

    @unlink($destination);

    die("Database error");
}

$stmt->bind_param(
    "is",
    $uid,
    $filename
);

if (!$stmt->execute()) {

    error_log(
        "Database insert failed: " .
        $stmt->error
    );

    @unlink($destination);

    $stmt->close();

    die("Database error");
}

$stmt->close();
$conn->close();

/*
============================================================
SUCCESS
============================================================
*/

echo "success";

?>