<?php
/**
 * face_train_multi.php
 *
 * Correct online training flow:
 *
 * InfinityFree
 *     ↓
 * Read faces/*.jpg
 *     ↓
 * Upload each face image to Render /sync_faces
 *     ↓
 * Render stores the images
 *     ↓
 * Render /train
 *     ↓
 * LBPH + Fisherfaces
 *
 * IMPORTANT:
 * InfinityFree cannot run Python directly.
 */

header('Content-Type: application/json');

@set_time_limit(0);
@ini_set('memory_limit', '128M');
@ignore_user_abort(true);

$config = require __DIR__ . '/config.php';

if (
    !isset($config['python_service']) ||
    !isset($config['python_service']['url'])
) {
    echo json_encode([
        'success' => false,
        'error' => 'Python service URL is not configured.'
    ]);
    exit;
}

$FACE_SERVER = rtrim($config['python_service']['url'], '/');

$scriptDir = __DIR__;
$facesDir  = $scriptDir . '/faces';


// ============================================================
// HELPER: GET JSON FROM RENDER
// ============================================================

function render_get_json($url, $timeout = 15)
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json'
        ]
    ]);

    $response = curl_exec($ch);

    $error = curl_error($ch);
    $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($response === false) {
        return [
            'ok' => false,
            'code' => 0,
            'error' => $error
        ];
    }

    $json = json_decode($response, true);

    return [
        'ok' => ($code >= 200 && $code < 300),
        'code' => $code,
        'data' => $json,
        'raw' => $response
    ];
}


// ============================================================
// HELPER: CHECK RENDER SERVER
// ============================================================

function render_server_ready($FACE_SERVER)
{
    $result = render_get_json(
        $FACE_SERVER . '/status',
        15
    );

    return $result['ok'];
}


// ============================================================
// STEP 1: WAIT FOR RENDER
// ============================================================

$serverReady = false;

for ($attempt = 1; $attempt <= 3; $attempt++) {

    if (render_server_ready($FACE_SERVER)) {
        $serverReady = true;
        break;
    }

    // Render free service may be sleeping.
    // Give it time to wake up.
    sleep(5);
}

if (!$serverReady) {

    echo json_encode([
        'success' => false,
        'error' => 'Cannot connect to Render face recognition server.',
        'server' => $FACE_SERVER
    ]);

    exit;
}


// ============================================================
// STEP 2: FIND ALL ENROLLED FACE IMAGES
// ============================================================

if (!is_dir($facesDir)) {

    echo json_encode([
        'success' => false,
        'error' => 'The local faces directory does not exist.'
    ]);

    exit;
}

$faceFiles = [];

$files = scandir($facesDir);

foreach ($files as $file) {

    if ($file === '.' || $file === '..') {
        continue;
    }

    $fullPath = $facesDir . DIRECTORY_SEPARATOR . $file;

    if (!is_file($fullPath)) {
        continue;
    }

    if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'jpg') {
        continue;
    }

    $faceFiles[] = $fullPath;
}

sort($faceFiles, SORT_NATURAL);


$totalFiles = count($faceFiles);

if ($totalFiles === 0) {

    echo json_encode([
        'success' => false,
        'error' => 'No JPG face images were found in the faces directory.'
    ]);

    exit;
}


// ============================================================
// STEP 3: SYNC FACE IMAGES TO RENDER
// ============================================================
//
// The current Render /sync_faces endpoint accepts:
//
// files
// files[]
//
// We send one image per request using "files[]".
// This avoids the PHP multipart duplicate-key problem.
//

$synced = 0;
$failed = 0;
$failedFiles = [];

foreach ($faceFiles as $index => $filePath) {

    $filename = basename($filePath);

    $mimeType = 'image/jpeg';

    if (function_exists('mime_content_type')) {

        $detectedMime = @mime_content_type($filePath);

        if ($detectedMime) {
            $mimeType = $detectedMime;
        }
    }


    $curlFile = curl_file_create(
        $filePath,
        $mimeType,
        $filename
    );


    $postFields = [
        'files[]' => $curlFile
    ];


    $ch = curl_init(
        $FACE_SERVER . '/sync_faces'
    );

    curl_setopt_array($ch, [

        CURLOPT_RETURNTRANSFER => true,

        CURLOPT_POST => true,

        CURLOPT_POSTFIELDS => $postFields,

        CURLOPT_CONNECTTIMEOUT => 15,

        CURLOPT_TIMEOUT => 30,

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


    $json = null;

    if ($response !== false) {
        $json = json_decode($response, true);
    }


    if (
        $response !== false &&
        $httpCode >= 200 &&
        $httpCode < 300 &&
        is_array($json) &&
        !empty($json['success'])
    ) {

        $synced++;

    } else {

        $failed++;

        $failedFiles[] = [
            'file' => $filename,
            'http_code' => $httpCode,
            'error' => $curlError ?: (
                is_array($json) && isset($json['error'])
                    ? $json['error']
                    : 'Unknown synchronization error'
            )
        ];
    }


    /*
     * Small delay so Render is not flooded with requests.
     */
    usleep(50000);
}


// ============================================================
// STEP 4: MAKE SURE EVERYTHING SYNCHRONIZED
// ============================================================

if ($failed > 0) {

    echo json_encode([
        'success' => false,
        'error' => 'Some face images could not be synchronized to Render.',
        'total_files' => $totalFiles,
        'synced' => $synced,
        'failed' => $failed,
        'failed_files' => $failedFiles
    ]);

    exit;
}


// ============================================================
// STEP 5: START TRAINING ON RENDER
// ============================================================

$ch = curl_init($FACE_SERVER . '/train');

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => '{}',
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Accept: application/json'
    ]
]);

$response = curl_exec($ch);

$curlError = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

curl_close($ch);

$trainData = $response !== false
    ? json_decode($response, true)
    : null;

$trainResult = [
    'ok' => (
        $response !== false &&
        $httpCode >= 200 &&
        $httpCode < 300
    ),
    'code' => $httpCode,
    'data' => $trainData,
    'raw' => $response,
    'error' => $curlError
];


if (!$serverReady) {

    $debug = render_get_json(
        $FACE_SERVER . '/status',
        30
    );

    echo json_encode([
        'success' => false,
        'error' => 'Cannot connect to Render face recognition server.',
        'server' => $FACE_SERVER,
        'http_code' => $debug['code'] ?? 0,
        'curl_error' => $debug['error'] ?? '',
        'response' => $debug['raw'] ?? ''
    ]);

    exit;
}

// ============================================================
// ASYNC MODE
// ============================================================
//
// Used by face_enroll.php:
//
// face_train_multi.php?async=1
//
// We synchronize first, then start Render training.
//

if (isset($_GET['async'])) {

    echo json_encode([
        'success' => true,
        'mode' => 'async',
        'message' => 'Face images synchronized and training started.',
        'total_files' => $totalFiles,
        'synced' => $synced
    ]);

    exit;
}


// ============================================================
// STEP 6: WAIT FOR TRAINING TO FINISH
// ============================================================

$trainingResult = null;

for ($i = 0; $i < 180; $i++) {

    sleep(2);


    $statusResult = render_get_json(
        $FACE_SERVER . '/train/status',
        15
    );


    if (
        !$statusResult['ok'] ||
        !is_array($statusResult['data'])
    ) {
        continue;
    }


    $status = $statusResult['data'];

    $state = $status['state'] ?? 'unknown';


    if ($state === 'done' || $state === 'error') {

        $trainingResult = $status;

        break;
    }
}


// ============================================================
// STEP 7: TRAINING TIMEOUT
// ============================================================

if ($trainingResult === null) {

    echo json_encode([
        'success' => false,
        'error' => 'Training timed out while waiting for Render.',
        'total_files' => $totalFiles,
        'synced' => $synced
    ]);

    exit;
}


// ============================================================
// STEP 8: PROCESS TRAINING RESULT
// ============================================================

if (($trainingResult['state'] ?? '') === 'error') {

    echo json_encode([
        'success' => false,
        'error' => $trainingResult['message'] ?? 'Training failed.',
        'total_files' => $totalFiles,
        'synced' => $synced,
        'training' => $trainingResult
    ]);

    exit;
}


$result = $trainingResult['result'] ?? [];


// ============================================================
// LBPH RESULT
// ============================================================

$lbph = $result['lbph'] ?? [
    'ok' => false,
    'samples' => 0,
    'students' => 0,
    'error' => 'No LBPH result returned.'
];


// ============================================================
// FISHERFACES RESULT
// ============================================================

$fisherfaces = $result['fisherfaces'] ?? [
    'ok' => false,
    'samples' => 0,
    'students' => 0,
    'error' => 'No Fisherfaces result returned.'
];


// ============================================================
// UI LABELS
// ============================================================

$lbphLabel = $lbph['ok']

    ? (
        'Trained on ' .
        ($lbph['samples'] ?? 0) .
        ' samples / ' .
        ($lbph['students'] ?? 0) .
        ' students'
    )

    : (
        'Error: ' .
        ($lbph['error'] ?? 'unknown')
    );


$fisherLabel = $fisherfaces['ok']

    ? (
        'Trained on ' .
        ($fisherfaces['samples'] ?? 0) .
        ' samples / ' .
        ($fisherfaces['students'] ?? 0) .
        ' students'
    )

    : (
        'Error: ' .
        ($fisherfaces['error'] ?? 'unknown')
    );


// ============================================================
// FINAL RESPONSE
// ============================================================

echo json_encode([

    'success' => true,

    'mode' => 'completed',

    'message' =>
        'Face images synchronized and models successfully retrained.',

    'sync' => [

        'total_files' => $totalFiles,

        'synced' => $synced,

        'failed' => $failed
    ],

    'lbph' => $lbph,

    'fisherfaces' => $fisherfaces,

    /*
     * Keep these names too so older dashboard JavaScript
     * does not immediately break.
     */
    'fr_helper' => $fisherfaces,

    'labels' => [

        'lbph' => $lbphLabel,

        'fisherfaces' => $fisherLabel,

        'fr_helper' => $fisherLabel
    ]

]);

?>