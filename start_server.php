<?php
/*
 * start_server.php
 * -----------------------------------------------------------------------
 * PURPOSE : Starts the face_server.py if it's not already running.
 *           Called from admin dashboard or can be run directly.
 * -----------------------------------------------------------------------
 */

header('Content-Type: application/json');

// Check if face_server is already running on port 5001
$checkPort = @fsockopen('127.0.0.1', 5001, $errno, $errstr, 1);
if($checkPort){
    fclose($checkPort);
    echo json_encode(['success' => true, 'message' => 'Face server is already running on port 5001']);
    exit;
}

// Start the face server using Windows start command (detached process)
$pythonPath = 'py -3';
$scriptPath = 'c:\xampp\htdocs\cics_attendance\face_server.py';
$logPath = 'c:\xampp\htdocs\cics_attendance\faces\.server_log.txt';

// Use start /B to run in background without opening new window
$command = 'start /B "" ' . $pythonPath . ' "' . $scriptPath . '" >> "' . $logPath . '" 2>&1';

// Execute using pclose(popen()) for background execution
$handle = popen($command, 'r');
pclose($handle);

// Wait a moment for server to start
sleep(3);

// Check if it started successfully
$checkPort = @fsockopen('127.0.0.1', 5001, $errno, $errstr, 1);
if($checkPort){
    fclose($checkPort);
    echo json_encode(['success' => true, 'message' => 'Face server started successfully']);
} else {
    // Try alternative method using WScript to run completely detached
    $vbsPath = 'c:\xampp\htdocs\cics_attendance\start_server_hidden.vbs';
    $vbsContent = 'Set WshShell = CreateObject("WScript.Shell")' . "\n";
    $vbsContent .= 'WshShell.Run "' . $pythonPath . ' ' . $scriptPath . '", 0, False';
    file_put_contents($vbsPath, $vbsContent);
    
    $command = 'wscript.exe "' . $vbsPath . '"';
    exec($command);
    
    sleep(3);
    
    $checkPort = @fsockopen('127.0.0.1', 5001, $errno, $errstr, 1);
    if($checkPort){
        fclose($checkPort);
        echo json_encode(['success' => true, 'message' => 'Face server started successfully (via VBS)']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to start face server. Check log file: ' . $logPath]);
    }
}
?>
