<?php
header('Content-Type: text/plain; charset=utf-8');

echo "=== FACE SERVER DIAGNOSTICS ===\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n";
echo "================================\n\n";

// 1. Check Python
echo "1. PYTHON CHECK\n";
echo "----------------\n";
$python = 'py -3';
$output = shell_exec("$python --version 2>&1");
echo "Python: $output\n\n";

// 2. Check OpenCV
echo "2. OPENCV CHECK\n";
echo "---------------\n";
$output = shell_exec("$python -c \"import cv2; print('Version:', cv2.__version__)\" 2>&1");
echo "$output\n";

$output = shell_exec("$python -c \"import cv2; print('Has face:', hasattr(cv2, 'face')); print('Has LBPH:', hasattr(cv2.face, 'LBPHFaceRecognizer_create') if hasattr(cv2, 'face') else False)\" 2>&1");
echo "$output\n\n";

// 3. Check trainer.yml
echo "3. TRAINER.YML CHECK\n";
echo "--------------------\n";
$trainer = 'c:\xampp\htdocs\cics_attendance\trainer.yml';
if (file_exists($trainer)) {
    $size = filesize($trainer);
    echo "Exists: YES\n";
    echo "Size: $size bytes (" . round($size/1024/1024, 2) . " MB)\n";
} else {
    echo "Exists: NO\n";
}
echo "\n";

// 4. Check face_encodings.pkl
echo "4. FACE_ENCODINGS.PKL CHECK\n";
echo "--------------------------\n";
$encodings = 'c:\xampp\htdocs\cics_attendance\face_encodings.pkl';
if (file_exists($encodings)) {
    $size = filesize($encodings);
    echo "Exists: YES\n";
    echo "Size: $size bytes (" . round($size/1024, 2) . " KB)\n";
} else {
    echo "Exists: NO\n";
}
echo "\n";

// 5. Check if server is running
echo "5. SERVER STATUS\n";
echo "---------------\n";
$check = @fsockopen('127.0.0.1', 5001, $errno, $errstr, 2);
if ($check) {
    fclose($check);
    echo "Port 5001: RUNNING\n";

    // Try to get status
    $config = require __DIR__ . '/config.php';
    $status = @file_get_contents($config['python_service']['url'] . '/status');
    if ($status) {
        echo "Status API: OK\n";
        echo "Response: $status\n";
    } else {
        echo "Status API: FAILED\n";
    }
} else {
    echo "Port 5001: NOT RUNNING\n";
    echo "Error: $errstr ($errno)\n";
}
echo "\n";

// 6. Check server log
echo "6. SERVER LOG\n";
echo "------------\n";
$log = 'c:\xampp\htdocs\cics_attendance\faces\.server_log.txt';
if (file_exists($log)) {
    $size = filesize($log);
    echo "Log exists: YES\n";
    echo "Log size: $size bytes\n";
    echo "\nLast 100 lines:\n";
    echo "----------------\n";
    $lines = file($log);
    $last_lines = array_slice($lines, -100);
    echo implode('', $last_lines);
} else {
    echo "Log exists: NO\n";
    echo "Log path: $log\n";
}
echo "\n";

// 7. Test Haar Cascade
echo "7. HAAR CASCADE TEST\n";
echo "-------------------\n";
$output = shell_exec("$python -c \"import cv2; cascade = cv2.CascadeClassifier(cv2.data.haarcascades + 'haarcascade_frontalface_default.xml'); print('Cascade empty:', cascade.empty())\" 2>&1");
echo "$output\n\n";

// 8. Test LBPH loading
echo "8. LBPH MODEL LOAD TEST\n";
echo "----------------------\n";
$test_cmd = "$python -c \"import cv2, os; trainer = 'c:\\\\xampp\\\\htdocs\\\\cics_attendance\\\\trainer.yml'; print('Trainer exists:', os.path.exists(trainer)); rec = cv2.face.LBPHFaceRecognizer_create(); rec.read(trainer); print('LBPH loaded: OK')\" 2>&1";
$output = shell_exec($test_cmd);
echo "$output\n\n";

echo "================================\n";
echo "=== DIAGNOSTICS COMPLETE ===\n";
?>
