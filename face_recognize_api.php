<?php

header('Content-Type: application/json');

@set_time_limit(30);
@ini_set('memory_limit', '128M');

// ============================================================
// CICS Attendance - Face Recognition API Proxy
// InfinityFree PHP -> Render Python Face Server
// ============================================================

$config = require __DIR__ . '/config.php';

$faceServer = rtrim($config['python_service']['url'] ?? '', '/');

if ($faceServer === '') {
    echo json_encode([
        'error' => 'Python face server URL is not configured.'
    ]);
    exit;
}

// ------------------------------------------------------------
// Input validation
// ------------------------------------------------------------

$imageData = $_POST['image'] ?? '';

if (!$imageData) {
    echo json_encode([
        'error' => 'No image data received.'
    ]);
    exit;
}

// Remove data URL prefix if the browser sends one.
// Example: data:image/jpeg;base64,AAAA...
$imageData = preg_replace(
    '/^data:image\/[\w.+-]+;base64,/i',
    '',
    $imageData
);

if (!$imageData) {
    echo json_encode([
        'error' => 'Image data is empty after processing.'
    ]);
    exit;
}

// ------------------------------------------------------------
// Send image to Render Python face server
// ------------------------------------------------------------

$endpoint = $faceServer . '/recognize';

$payload = json_encode([
    'image' => $imageData
]);

if ($payload === false) {
    echo json_encode([
        'error' => 'Failed to prepare image request.'
    ]);
    exit;
}

$ch = curl_init($endpoint);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Accept: application/json'
    ],
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);

$output = curl_exec($ch);

$curlErrorNo = curl_errno($ch);
$curlError = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

curl_close($ch);

// ------------------------------------------------------------
// Handle connection errors
// ------------------------------------------------------------

if ($output === false || $curlErrorNo !== 0) {
    echo json_encode([
        'error' => 'Cannot connect to the Render face server.',
        'details' => $curlError ?: 'Unknown cURL error',
        'endpoint' => $endpoint
    ]);
    exit;
}

// ------------------------------------------------------------
// Handle HTTP errors
// ------------------------------------------------------------

if ($httpCode !== 200) {
    $serverMessage = trim($output);

    // Do not expose an unnecessarily large server response.
    if (strlen($serverMessage) > 500) {
        $serverMessage = substr($serverMessage, 0, 500);
    }

    echo json_encode([
        'error' => 'Face server returned HTTP ' . $httpCode . '.',
        'details' => $serverMessage
    ]);
    exit;
}

// ------------------------------------------------------------
// Decode Render response
// ------------------------------------------------------------

$result = json_decode($output, true);

if (!is_array($result)) {
    echo json_encode([
        'error' => 'Invalid JSON response from the Render face server.',
        'details' => substr($output, 0, 500)
    ]);
    exit;
}

// ------------------------------------------------------------
// Enrich recognized LBPH result with student information
// ------------------------------------------------------------
// IMPORTANT:
// Use db.php instead of localhost/root/attendance.
// InfinityFree cannot use the local XAMPP database settings.
// ------------------------------------------------------------

require __DIR__ . '/db.php';

if (isset($conn) && !$conn->connect_error) {

    if (
        isset($result['lbph']['id']) &&
        is_numeric($result['lbph']['id']) &&
        intval($result['lbph']['id']) > 0
    ) {

        $id = intval($result['lbph']['id']);

        $stmt = $conn->prepare(
            "SELECT first_name, last_name, student_id
             FROM users
             WHERE id = ?
             LIMIT 1"
        );

        if ($stmt) {

            $stmt->bind_param('i', $id);
            $stmt->execute();

            $queryResult = $stmt->get_result();
            $row = $queryResult ? $queryResult->fetch_assoc() : null;

            $stmt->close();

            if ($row) {
                $result['lbph']['name'] =
                    trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));

                $result['lbph']['student_id'] =
                    $row['student_id'] ?? '';
            } else {
                $result['lbph']['name'] = 'Unknown';
                $result['lbph']['student_id'] = '';
            }

        } else {
            $result['lbph']['name'] = 'Unknown';
            $result['lbph']['student_id'] = '';
        }

    } elseif (isset($result['lbph'])) {

        $result['lbph']['name'] = 'Unknown';
        $result['lbph']['student_id'] = '';
    }

    $conn->close();
}

// ------------------------------------------------------------
// Return final result to browser
// ------------------------------------------------------------

echo json_encode($result);

?>
