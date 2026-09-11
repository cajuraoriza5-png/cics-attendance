<?php
header('Content-Type: text/plain; charset=utf-8');

echo "=== Checking Face Server Algorithm Support ===\n\n";

// Test the recognize endpoint with a dummy request
echo "Testing /recognize endpoint structure...\n";

$check = @fsockopen('127.0.0.1', 5001, $errno, $errstr, 2);
if (!$check) {
    echo "ERROR: Server not running on port 5001\n";
    exit;
}
fclose($check);

// Get a test image from faces folder
$faces = glob('c:\xampp\htdocs\cics_attendance\faces\*.jpg');
if (empty($faces)) {
    echo "No face images found for testing\n";
    exit;
}

$testImage = $faces[0];
$imageData = base64_encode(file_get_contents($testImage));

// Send test request
$ch = curl_init('http://127.0.0.1:5001/recognize');
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['image' => $imageData]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
curl_close($ch);

echo "Response from server:\n";
echo $response . "\n\n";

$data = json_decode($response, true);
if ($data) {
    echo "=== Response Structure Analysis ===\n";
    if (isset($data['lbph'])) {
        echo "lbph field exists: YES\n";
        $lbph = $data['lbph'];
        echo "  - id: " . ($lbph['id'] ?? 'N/A') . "\n";
        echo "  - confidence: " . ($lbph['confidence'] ?? 'N/A') . "\n";
        echo "  - algorithm: " . ($lbph['algorithm'] ?? 'NOT SET - server needs restart') . "\n";
        echo "  - lbph_confidence: " . ($lbph['lbph_confidence'] ?? 'NOT SET') . "\n";
        echo "  - cnn_confidence: " . ($lbph['cnn_confidence'] ?? 'NOT SET') . "\n";
    } else {
        echo "lbph field: NOT FOUND\n";
    }
}

echo "\n=== Complete ===\n";
?>
