<?php

include("db.php");

header("Content-Type: text/plain; charset=utf-8");

if ($conn->connect_error) {
    die("Database connection failed.\n");
}

$facesDir = __DIR__ . "/faces/";

if (!is_dir($facesDir)) {
    die("Faces directory not found.\n");
}

/*
 * Get every face filename that is actually registered
 * in the database.
 */
$validFaces = [];

$result = $conn->query("
    SELECT face_image
    FROM face_data
");

if (!$result) {
    die("Failed to read face_data: " . $conn->error . "\n");
}

while ($row = $result->fetch_assoc()) {
    $filename = basename($row['face_image']);

    if ($filename !== '') {
        $validFaces[$filename] = true;
    }
}

$result->free();

/*
 * Scan the physical faces directory.
 */
$files = scandir($facesDir);

$checked = 0;
$kept = 0;
$deleted = 0;
$errors = 0;

echo "=== FACE CLEANUP STARTED ===\n\n";

foreach ($files as $file) {

    if ($file === '.' || $file === '..') {
        continue;
    }

    $path = $facesDir . $file;

    if (!is_file($path)) {
        continue;
    }

    /*
     * Only process JPG face images.
     */
    if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'jpg') {
        continue;
    }

    $checked++;

    /*
     * Keep files that exist in face_data.
     */
    if (isset($validFaces[$file])) {
        $kept++;
        continue;
    }

    /*
     * Delete only orphan JPG files.
     */
    if (unlink($path)) {
        $deleted++;
        echo "DELETED: $file\n";
    } else {
        $errors++;
        echo "ERROR: Could not delete $file\n";
    }
}

echo "\n=== CLEANUP COMPLETE ===\n";
echo "Physical JPG files checked: $checked\n";
echo "Valid database files kept: $kept\n";
echo "Orphan files deleted: $deleted\n";
echo "Deletion errors: $errors\n";

$conn->close();

?>