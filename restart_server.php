<?php
header('Content-Type: application/json');

// Kill existing Python face_server process
echo "Killing existing face server process...\n";

// Find and kill Python processes using face_server.py
$output = shell_exec('taskkill /F /FI "WINDOWTITLE eq face_server*" 2>&1');
echo "Taskkill output: $output\n";

// Also try to kill by port
$output = shell_exec('for /f "tokens=5" %a in (\'netstat -aon ^| findstr :5001\') do taskkill /F /PID %a 2>&1');
echo "Port kill output: $output\n";

// Wait a moment
sleep(2);

// Clear the log file
$log = 'c:\xampp\htdocs\cics_attendance\faces\.server_log.txt';
if (file_exists($log)) {
    file_put_contents($log, '');
    echo "Log file cleared\n";
}

// Start the server
echo "Starting face server...\n";
$python = 'py -3';
$script = 'c:\xampp\htdocs\cics_attendance\face_server.py';
$logPath = 'c:\xampp\htdocs\cics_attendance\faces\.server_log.txt';

// Use start /B to run in background
$command = 'start /B "" ' . $python . ' "' . $script . '" >> "' . $logPath . '" 2>&1';
$handle = popen($command, 'r');
pclose($handle);

echo "Server restart command executed\n";

// Wait for server to start
sleep(3);

// Check if it started
$check = @fsockopen('127.0.0.1', 5001, $errno, $errstr, 2);
if ($check) {
    fclose($check);
    echo json_encode(['success' => true, 'message' => 'Face server restarted successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Server may not have started. Check log file.']);
}
?>
