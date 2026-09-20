<?php
/**
 * train_status.php
 *
 * Gets the live training status from the Render face recognition server.
 * The admin dashboard polls this file every 2 seconds.
 */

header('Content-Type: application/json');


// ============================================================
// LOAD CONFIG
// ============================================================

$config = require __DIR__ . '/config.php';

if (
    !isset($config['python_service']) ||
    !isset($config['python_service']['url'])
) {
    echo json_encode([
        'state' => 'error',
        'message' => 'Python service URL is not configured.'
    ]);
    exit;
}

$FACE_SERVER = rtrim(
    $config['python_service']['url'],
    '/'
);


// ============================================================
// CLEAR STATUS
// ============================================================

if (isset($_GET['clear'])) {

    echo json_encode([
        'cleared' => true,
        'state' => 'idle'
    ]);

    exit;
}


// ============================================================
// GET RENDER TRAINING STATUS
// ============================================================

$ch = curl_init(
    $FACE_SERVER . '/train/status'
);

curl_setopt_array($ch, [

    CURLOPT_RETURNTRANSFER => true,

    CURLOPT_CONNECTTIMEOUT => 10,

    CURLOPT_TIMEOUT => 15,

    CURLOPT_FOLLOWLOCATION => true,

    CURLOPT_HTTPHEADER => [
        'Accept: application/json'
    ]

]);

$response = curl_exec($ch);

$curlError = curl_error($ch);

$httpCode = curl_getinfo(
    $ch,
    CURLINFO_HTTP_CODE
);

curl_close($ch);


// ============================================================
// CONNECTION ERROR
// ============================================================

if ($response === false) {

    echo json_encode([
        'state' => 'error',
        'message' => 'Cannot connect to Render training server.',
        'error' => $curlError
    ]);

    exit;
}


// ============================================================
// HTTP ERROR
// ============================================================

if ($httpCode < 200 || $httpCode >= 300) {

    echo json_encode([
        'state' => 'error',
        'message' => 'Render training server returned HTTP ' . $httpCode
    ]);

    exit;
}


// ============================================================
// DECODE RESPONSE
// ============================================================

$data = json_decode(
    $response,
    true
);


if (!is_array($data)) {

    echo json_encode([
        'state' => 'error',
        'message' => 'Invalid response from Render training server.'
    ]);

    exit;
}


// ============================================================
// RETURN RENDER STATUS DIRECTLY
// ============================================================

echo json_encode($data);

?>