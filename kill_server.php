<?php
header('Content-Type: application/json');

// Kill Python processes using face_server.py
echo "Attempting to kill face server...\n";

// Method 1: Kill by process name
$output = shell_exec('taskkill /F /IM python.exe 2>&1');
echo "Kill python.exe output: $output\n";

// Method 2: Kill by port
$output = shell_exec('for /f "tokens=5" %a in (\'netstat -aon ^| findstr :5001\') do taskkill /F /PID %a 2>&1');
echo "Kill by port output: $output\n";

sleep(2);

// Clear log
$log = 'c:\xampp\htdocs\cics_attendance\faces\.server_log.txt';
if (file_exists($log)) {
    file_put_contents($log, '');
    echo "Log cleared\n";
}

echo json_encode(['success' => true, 'message' => 'Kill commands executed']);
?>
