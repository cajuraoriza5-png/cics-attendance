<?php
$facesDir = __DIR__ . '/faces';
if (!is_dir($facesDir)) {
    echo "faces directory does not exist\n";
    exit;
}

$files = glob($facesDir . '/*.jpg');
echo "JPG files in faces/: " . count($files) . "\n";

if (count($files) > 0) {
    echo "\nFirst 10 files:\n";
    foreach (array_slice($files, 0, 10) as $f) {
        echo basename($f) . "\n";
    }
    
    // Count by user ID
    $userCounts = [];
    foreach ($files as $f) {
        if (preg_match('/(\d+)_(\d+)\.jpg/', basename($f), $matches)) {
            $userId = $matches[1];
            if (!isset($userCounts[$userId])) {
                $userCounts[$userId] = 0;
            }
            $userCounts[$userId]++;
        }
    }
    
    echo "\nImages per user:\n";
    foreach ($userCounts as $userId => $count) {
        echo "User ID $userId: $count images\n";
    }
} else {
    echo "No face images found. Students need to enroll their faces.\n";
}
?>
