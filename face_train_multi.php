<?php
/**
 * face_train_multi.php
 *
 * FIXED ONLINE TRAINING FLOW
 *
 * InfinityFree PHP does NOT upload every face image in one long request.
 * The browser calls this file repeatedly in small batches.
 *
 * Each request:
 *   1. Reads only one batch of local JPG files.
 *   2. Sends that batch to Render /sync_faces in ONE HTTP request.
 *   3. Returns immediately with the next offset.
 *
 * The final batch starts Render /train. Render performs the actual
 * LBPH + Fisherfaces training in its background thread.
 *
 * This prevents the old problem where InfinityFree PHP stayed alive while
 * making hundreds of individual cURL requests and was eventually killed.
 */

header('Content-Type: application/json; charset=utf-8');

@set_time_limit(0);
@ini_set('memory_limit', '128M');
@ignore_user_abort(true);

$config = require __DIR__ . '/config.php';

if (!isset($config['python_service']['url'])) {
    echo json_encode([
        'success' => false,
        'error' => 'Python service URL is not configured.'
    ]);
    exit;
}

$FACE_SERVER = rtrim($config['python_service']['url'], '/');
$facesDir = __DIR__ . '/faces';

/*
 * These limits keep each InfinityFree request reasonably small.
 * 100 files is the maximum count, but the byte limit normally stops
 * the batch earlier. This is deliberately below InfinityFree's
 * documented 30 MB maximum POST size seen on current free hosting.
 */
$MAX_BATCH_FILES = 100;
$MAX_BATCH_BYTES = 12 * 1024 * 1024; // 12 MB

function render_get_json($url, $timeout = 20)
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json'
        ],
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'ok' => ($response !== false && $code >= 200 && $code < 300),
        'code' => $code,
        'error' => $error,
        'raw' => $response,
        'data' => ($response !== false ? json_decode($response, true) : null)
    ];
}

function render_post_json($url, $payload = [], $timeout = 30)
{
    $ch = curl_init($url);
    $jsonPayload = json_encode($payload);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $jsonPayload,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json'
        ],
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'ok' => ($response !== false && $code >= 200 && $code < 300),
        'code' => $code,
        'error' => $error,
        'raw' => $response,
        'data' => ($response !== false ? json_decode($response, true) : null)
    ];
}

function fail_json($message, $extra = [])
{
    echo json_encode(array_merge([
        'success' => false,
        'error' => $message
    ], $extra));
    exit;
}

/* -------------------------------------------------------------
 * Only the new batch API is allowed.
 * ------------------------------------------------------------- */
$batchMode = isset($_GET['batch']) && (string)$_GET['batch'] === '1';

if (!$batchMode) {
    fail_json(
        'The old long-running training request is disabled. ' .
        'Use the Re-train Models button so the dataset is synchronized in batches.'
    );
}

$offset = isset($_GET['offset']) ? max(0, intval($_GET['offset'])) : 0;
$requestedLimit = isset($_GET['limit']) ? intval($_GET['limit']) : $MAX_BATCH_FILES;
$requestedLimit = max(1, min($MAX_BATCH_FILES, $requestedLimit));
$replaceDataset = isset($_GET['replace']) && in_array(
    strtolower((string)$_GET['replace']),
    ['1', 'true', 'yes'],
    true
);

/* -------------------------------------------------------------
 * Check local dataset.
 * ------------------------------------------------------------- */
if (!is_dir($facesDir)) {
    fail_json('The faces directory does not exist.');
}

$faceFiles = [];
$files = scandir($facesDir);

if ($files === false) {
    fail_json('Unable to read the faces directory.');
}

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
    fail_json('No JPG face images were found in the faces directory.');
}

if ($offset >= $totalFiles) {
    fail_json('The requested batch offset is outside the face dataset.', [
        'total_files' => $totalFiles,
        'offset' => $offset
    ]);
}

/* -------------------------------------------------------------
 * Check Render only when needed. The request itself can also wake
 * the Render service, so do not waste six long health-check attempts.
 * ------------------------------------------------------------- */
$serverCheck = render_get_json($FACE_SERVER . '/status', 20);

if (!$serverCheck['ok']) {
    fail_json('Cannot connect to the Render face recognition server.', [
        'http_code' => $serverCheck['code'],
        'curl_error' => $serverCheck['error'],
        'response' => $serverCheck['raw']
    ]);
}

/* -------------------------------------------------------------
 * Build a batch by both file count and total byte size.
 * ------------------------------------------------------------- */
$batch = [];
$batchBytes = 0;

for ($i = $offset; $i < $totalFiles && count($batch) < $requestedLimit; $i++) {
    $filePath = $faceFiles[$i];
    $size = @filesize($filePath);

    if ($size === false) {
        fail_json('Unable to read file size.', [
            'file' => basename($filePath)
        ]);
    }

    /* If one file is unusually large, still allow it as a one-file batch. */
    if (!empty($batch) && ($batchBytes + $size) > $MAX_BATCH_BYTES) {
        break;
    }

    $batch[] = $filePath;
    $batchBytes += $size;
}

if (empty($batch)) {
    fail_json('Could not build a valid synchronization batch.');
}

/* -------------------------------------------------------------
 * Send all files in this batch in ONE multipart request.
 *
 * Indexed field names are intentional: files[0], files[1], ... .
 * The Render server accepts these indexed multipart keys.
 * ------------------------------------------------------------- */
$postFields = [];

foreach ($batch as $index => $filePath) {
    $filename = basename($filePath);
    $mimeType = 'image/jpeg';

    if (function_exists('mime_content_type')) {
        $detected = @mime_content_type($filePath);
        if ($detected) {
            $mimeType = $detected;
        }
    }

    $postFields['files[' . $index . ']'] = curl_file_create(
        $filePath,
        $mimeType,
        $filename
    );
}

/* Replace the Render dataset only on the first batch. */
if ($replaceDataset && $offset === 0) {
    $postFields['replace'] = '1';
}

$ch = curl_init($FACE_SERVER . '/sync_faces');

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $postFields,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_TIMEOUT => 50,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTPHEADER => [
        'Accept: application/json'
    ],
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4
]);

$response = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$json = ($response !== false) ? json_decode($response, true) : null;

if (
    $response === false ||
    $httpCode < 200 ||
    $httpCode >= 300 ||
    !is_array($json) ||
    empty($json['success'])
) {
    fail_json('Render rejected the face-image batch.', [
        'offset' => $offset,
        'batch_files' => count($batch),
        'batch_bytes' => $batchBytes,
        'http_code' => $httpCode,
        'curl_error' => $curlError,
        'render_response' => $response,
        'first_file' => basename($batch[0])
    ]);
}

$batchSynced = intval($json['saved'] ?? count($batch));
$nextOffset = $offset + count($batch);
$done = ($nextOffset >= $totalFiles);

/* -------------------------------------------------------------
 * Final batch: start Render training and return immediately.
 * ------------------------------------------------------------- */
$trainStarted = false;
$trainResponse = null;

if ($done) {
    $trainResult = render_post_json(
        $FACE_SERVER . '/train',
        [],
        30
    );

    if (!$trainResult['ok']) {
        fail_json('All face images were synchronized, but Render could not start training.', [
            'total_files' => $totalFiles,
            'synced_total' => $nextOffset,
            'http_code' => $trainResult['code'],
            'curl_error' => $trainResult['error'],
            'render_response' => $trainResult['raw']
        ]);
    }

    $trainData = $trainResult['data'];

    if (is_array($trainData) && isset($trainData['success']) && !$trainData['success']) {
        fail_json('Render refused to start training.', [
            'total_files' => $totalFiles,
            'synced_total' => $nextOffset,
            'render_response' => $trainData
        ]);
    }

    $trainStarted = true;
    $trainResponse = $trainData;
}

echo json_encode([
    'success' => true,
    'batch_mode' => true,
    'offset' => $offset,
    'next_offset' => $nextOffset,
    'batch_files' => count($batch),
    'batch_synced' => $batchSynced,
    'batch_bytes' => $batchBytes,
    'total_files' => $totalFiles,
    'synced_total' => $nextOffset,
    'done' => $done,
    'train_started' => $trainStarted,
    'train_response' => $trainResponse
]);
exit;
?>
