<?php
header('Content-Type: text/plain');

echo "=== Testing Python Execution ===\n\n";

// Test basic Python
echo "Test 1: Basic Python version\n";
$output = shell_exec('py -3 --version 2>&1');
echo "Output: $output\n\n";

// Test simple script
echo "Test 2: Simple print script\n";
$output = shell_exec('py -3 -c "print(\'Hello from Python\')" 2>&1');
echo "Output: $output\n\n";

// Test OpenCV import
echo "Test 3: OpenCV import\n";
$output = shell_exec('py -3 -c "import cv2; print(cv2.__version__)" 2>&1');
echo "Output: $output\n\n";

echo "=== Complete ===\n";
?>
