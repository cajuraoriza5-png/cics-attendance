<?php
/**
 * extract_faces_web.php
 * 
 * Web-based face image extraction from database to faces/ directory
 * Access via browser: extract_faces_web.php
 */

date_default_timezone_set('Asia/Manila');
include("db.php");

header('Content-Type: text/html; charset=utf-8');

echo "<!DOCTYPE html><html><head><title>Extract Face Images</title>";
echo "<style>body{font-family:Arial,sans-serif;padding:20px;background:#f5f5f5;}";
echo ".container{max-width:800px;margin:0 auto;background:white;padding:20px;border-radius:8px;}";
echo ".success{color:green;}.error{color:red;}.info{color:blue;}";
echo "pre{background:#f0f0f0;padding:10px;border-radius:4px;overflow:auto;}";
echo "button{padding:10px 20px;background:#007bff;color:white;border:none;border-radius:4px;cursor:pointer;}";
echo "button:hover{background:#0056b3;}</style></head><body>";
echo "<div class='container'><h1>📷 Extract Face Images from Database</h1>";

if (isset($_POST['extract'])) {
    echo "<h2>Extraction Progress</h2><pre>";
    
    // Check if face_data table exists
    $checkTable = $conn->query("SHOW TABLES LIKE 'face_data'");
    if ($checkTable->num_rows == 0) {
        echo "<span class='error'>ERROR: face_data table does not exist in database.</span>\n";
        echo "Please ensure face enrollment is working first.\n";
        echo "</pre></div></body></html>";
        exit;
    }
    
    echo "<span class='success'>✓ face_data table found</span>\n\n";
    
    // Get all face data records
    $result = $conn->query("SELECT id, user_id, face_image FROM face_data ORDER BY user_id, id");
    
    if (!$result) {
        echo "<span class='error'>ERROR: Failed to query face_data table: " . $conn->error . "</span>\n";
        echo "</pre></div></body></html>";
        exit;
    }
    
    $totalRecords = $result->num_rows;
    echo "<span class='info'>Found $totalRecords face records in database</span>\n\n";
    
    if ($totalRecords == 0) {
        echo "<span class='error'>No face images found in database. Please enroll students first.</span>\n";
        echo "</pre></div></body></html>";
        exit;
    }
    
    // Create faces directory if it doesn't exist
    $facesDir = __DIR__ . '/faces';
    if (!is_dir($facesDir)) {
        mkdir($facesDir, 0777, true);
        echo "<span class='success'>✓ Created faces/ directory</span>\n\n";
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
            echo "<span class='error'>✗ Failed to decode image for user ID $userId (record ID: {$row['id']})</span>\n";
            $errors++;
            continue;
        }
        
        // Save to file
        if (file_put_contents($filepath, $imageData) === false) {
            echo "<span class='error'>✗ Failed to save $filename</span>\n";
            $errors++;
            continue;
        }
        
        $extracted++;
        $userImageCount[$userId]++;
        echo "<span class='success'>✓ Saved: $filename</span>\n";
    }
    
    echo "\n<span class='info'>=== Extraction Complete ===</span>\n";
    echo "Total records: $totalRecords\n";
    echo "Extracted: $extracted\n";
    echo "Errors: $errors\n\n";
    
    // Show summary by user
    echo "<span class='info'>=== Images per Student ===</span>\n";
    foreach ($userImageCount as $userId => $count) {
        echo "User ID $userId: $count images\n";
    }
    
    echo "\n<span class='success'>✓ Face images extracted to faces/ directory</span>\n";
    echo "<span class='info'>Next step: Go to Admin Dashboard and click 'Re-train Models' to sync to Render and train.</span>\n";
    echo "</pre>";
    echo "<p><a href='admin_dashboard.php' style='color:#007bff;text-decoration:none;'>← Back to Admin Dashboard</a></p>";
} else {
    echo "<p>This tool extracts face images from the database and saves them to the faces/ directory.</p>";
    echo "<p><strong>Why do this?</strong> The face recognition system needs face images in the faces/ directory to train models.</p>";
    echo "<form method='post'>";
    echo "<button type='submit' name='extract'>🚀 Start Extraction</button>";
    echo "</form>";
    echo "<p><a href='admin_dashboard.php' style='color:#007bff;text-decoration:none;'>← Cancel</a></p>";
}

echo "</div></body></html>";
?>
