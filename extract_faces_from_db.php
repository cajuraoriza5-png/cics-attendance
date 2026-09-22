<?php
/**
 * extract_faces_from_db.php
 * 
 * Extracts face images from the face_data table in the database
 * and saves them to the faces/ directory as JPG files.
 * 
 * Usage: php extract_faces_from_db.php
 */

date_default_timezone_set('Asia/Manila');
include("db.php");

echo "=== Extracting Face Images from Database ===\n\n";

// Check if face_data table exists
$checkTable = $conn->query("SHOW TABLES LIKE 'face_data'");
if ($checkTable->num_rows == 0) {
    echo "ERROR: face_data table does not exist in database.\n";
    echo "Please ensure face enrollment is working first.\n";
    exit(1);
}

echo "✓ face_data table found\n\n";

// Get all face data records
$result = $conn->query("SELECT id, user_id, face_image FROM face_data ORDER BY user_id, id");

if (!$result) {
    echo "ERROR: Failed to query face_data table: " . $conn->error . "\n";
    exit(1);
}

$totalRecords = $result->num_rows;
echo "Found $totalRecords face records in database\n\n";

if ($totalRecords == 0) {
    echo "No face images found in database. Please enroll students first.\n";
    exit(1);
}

// Create faces directory if it doesn't exist
$facesDir = __DIR__ . '/faces';
if (!is_dir($facesDir)) {
    mkdir($facesDir, 0777, true);
    echo "✓ Created faces/ directory\n\n";
}

// Track user IDs to create sequential numbering
$userImageCount = [];
$extracted = 0;
$errors = 0;

while ($row = $result->fetch_assoc()) {
    $userId = $row['user_id'];
    $faceImageData = $row['face_image'];
    
    // Initialize counter for this user if not exists
    if (!isset($userImageCount[$userId])) {
        $userImageCount[$userId] = 0;
    }
    
    // Generate filename: {user_id}_{index}.jpg
    $filename = $userId . '_' . $userImageCount[$userId] . '.jpg';
    $filepath = $facesDir . '/' . $filename;
    
    // Decode base64 image data
    // Handle both data URL format and raw base64
    if (preg_match('/^data:image\/(\w+);base64,(.+)$/', $faceImageData, $matches)) {
        $imageData = base64_decode($matches[2]);
    } else {
        $imageData = base64_decode($faceImageData);
    }
    
    if ($imageData === false) {
        echo "✗ Failed to decode image for user ID $userId (record ID: {$row['id']})\n";
        $errors++;
        continue;
    }
    
    // Save to file
    if (file_put_contents($filepath, $imageData) === false) {
        echo "✗ Failed to save $filename\n";
        $errors++;
        continue;
    }
    
    $extracted++;
    $userImageCount[$userId]++;
    echo "✓ Saved: $filename\n";
}

echo "\n=== Extraction Complete ===\n";
echo "Total records: $totalRecords\n";
echo "Extracted: $extracted\n";
echo "Errors: $errors\n\n";

// Show summary by user
echo "=== Images per Student ===\n";
foreach ($userImageCount as $userId => $count) {
    echo "User ID $userId: $count images\n";
}

echo "\n✓ Face images extracted to faces/ directory\n";
echo "Next step: Go to Admin Dashboard and click 'Re-train Models' to sync to Render and train.\n";
?>
