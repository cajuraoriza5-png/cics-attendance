<?php
header('Content-Type: text/plain');

echo "=== Face Server Diagnostics ===\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

// Check Python installation
$python = 'py -3';
echo "Checking Python...\n";
$output = shell_exec("$python --version 2>&1");
echo "Python version: $output\n\n";

// Check OpenCV
echo "Checking OpenCV...\n";
$output = shell_exec("$python -c \"import cv2; print('OpenCV:', cv2.__version__)\" 2>&1");
echo "$output\n";

// Check opencv-contrib
echo "Checking opencv-contrib...\n";
$output = shell_exec("$python -c \"import cv2; print('Has face:', hasattr(cv2, 'face')); print('Has LBPH:', hasattr(cv2.face, 'LBPHFaceRecognizer_create') if hasattr(cv2, 'face') else False)\" 2>&1");
echo "$output\n";

// Check trainer.yml
echo "Checking trainer.yml...\n";
$trainer = 'c:\xampp\htdocs\cics_attendance\trainer.yml';
if (file_exists($trainer)) {
    $size = filesize($trainer);
    echo "Exists: Yes\n";
    echo "Size: $size bytes (" . round($size/1024/1024, 2) . " MB)\n";
} else {
    echo "Exists: No\n";
}
echo "\n";

// Check if server is running
echo "Checking if server is running on port 5001...\n";
$check = @fsockopen('127.0.0.1', 5001, $errno, $errstr, 1);
if ($check) {
    fclose($check);
    echo "Server: RUNNING\n";
} else {
    echo "Server: NOT RUNNING\n";
}
echo "\n";

// Check server log
echo "Server log (last 50 lines):\n";
$log = 'c:\xampp\htdocs\cics_attendance\faces\.server_log.txt';
if (file_exists($log)) {
    $lines = file($log);
    $last_lines = array_slice($lines, -50);
    echo implode('', $last_lines);
} else {
    echo "Log file does not exist\n";
}

echo "\n=== Diagnostics Complete ===\n";
?>
