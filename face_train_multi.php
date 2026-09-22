<?php
/**
 * face_train_multi.php
 *
 * ONLINE FACE TRAINING FLOW
 *
 * InfinityFree
 *      |
 *      | 1. Read enrolled JPG faces
 *      v
 * Render /sync_faces
 *      |
 *      | 2. Store current face dataset on Render
 *      v
 * Render /train
 *      |
 *      | 3. Train
 *      v
 * LBPH + Fisherfaces
 *
 * IMPORTANT:
 * InfinityFree cannot run Python.
 */

header('Content-Type: application/json');

@set_time_limit(0);
@ini_set('memory_limit', '128M');
@ignore_user_abort(true);


// ============================================================
// CONFIGURATION
// ============================================================

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

$FACE_SERVER = rtrim(
    $config['python_service']['url'],
    '/'
);

$facesDir = __DIR__ . '/faces';


// ============================================================
// HELPER: GET REQUEST
// ============================================================

function render_get($url, $timeout = 30)
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET => true,
        CURLOPT_CONNECTTIMEOUT => 15,
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

    $code = curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );

    curl_close($ch);

    return [
        'ok' => (
            $response !== false &&
            $code >= 200 &&
            $code < 300
        ),

        'code' => $code,

        'error' => $error,

        'raw' => $response,

        'data' => (
            $response !== false
                ? json_decode($response, true)
                : null
        )
    ];
}


// ============================================================
// HELPER: POST JSON
// ============================================================

function render_post_json($url, $payload = [], $timeout = 30)
{
    $ch = curl_init($url);

    $jsonPayload = json_encode($payload);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,

        CURLOPT_POST => true,

        CURLOPT_POSTFIELDS => $jsonPayload,

        CURLOPT_CONNECTTIMEOUT => 15,

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

    $code = curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );

    curl_close($ch);

    return [
        'ok' => (
            $response !== false &&
            $code >= 200 &&
            $code < 300
        ),

        'code' => $code,

        'error' => $error,

        'raw' => $response,

        'data' => (
            $response !== false
                ? json_decode($response, true)
                : null
        )
    ];
}


// ============================================================
// HELPER: CHECK RENDER
// ============================================================

function check_render($server)
{
    /*
     * Render free services may sleep.
     * Give Render several attempts to wake up.
     */

    $lastResult = null;

    for ($i = 1; $i <= 6; $i++) {

        $result = render_get(
            $server . '/status',
            30
        );

        $lastResult = $result;

        if ($result['ok']) {

            return [
                'ready' => true,
                'result' => $result
            ];
        }

        sleep(5);
    }

    return [
        'ready' => false,
        'result' => $lastResult
    ];
}


// ============================================================
// STEP 1: CHECK RENDER
// ============================================================

$renderCheck = check_render(
    $FACE_SERVER
);

if (!$renderCheck['ready']) {

    $r = $renderCheck['result'];

    echo json_encode([
        'success' => false,

        'error' =>
            'InfinityFree could not connect to the Render face server.',

        'server' => $FACE_SERVER,

        'http_code' =>
            $r['code'] ?? 0,

        'curl_error' =>
            $r['error'] ?? '',

        'response' =>
            $r['raw'] ?? ''
    ]);

    exit;
}


// ============================================================
// STEP 2: CHECK LOCAL FACES DIRECTORY
// ============================================================

if (!is_dir($facesDir)) {

    echo json_encode([
        'success' => false,

        'error' =>
            'The faces directory does not exist.'
    ]);

    exit;
}


// ============================================================
// STEP 3: FIND ALL JPG FACE IMAGES
// ============================================================

$faceFiles = [];

$files = scandir($facesDir);

foreach ($files as $file) {

    if (
        $file === '.' ||
        $file === '..'
    ) {
        continue;
    }

    $fullPath =
        $facesDir .
        DIRECTORY_SEPARATOR .
        $file;

    if (!is_file($fullPath)) {
        continue;
    }

    $extension =
        strtolower(
            pathinfo(
                $file,
                PATHINFO_EXTENSION
            )
        );

    if ($extension !== 'jpg') {
        continue;
    }

    $faceFiles[] = $fullPath;
}

sort(
    $faceFiles,
    SORT_NATURAL
);

$totalFiles = count(
    $faceFiles
);


// ============================================================
// NO FACES
// ============================================================

if ($totalFiles === 0) {

    echo json_encode([
        'success' => false,

        'error' =>
            'No JPG face images were found in the faces directory.'
    ]);

    exit;
}


// ============================================================
// STEP 4: SYNCHRONIZE FACES TO RENDER
// ============================================================

$synced = 0;

$failed = 0;

$failedFiles = [];


// ------------------------------------------------------------
// IMPORTANT
// ------------------------------------------------------------
// The first successful upload uses:
//
//     replace=1
//
// This tells Render to delete its old face dataset first.
//
// After the first successful upload:
//
//     replace=0
//
// Therefore Render will contain only the current
// InfinityFree face dataset.
// ------------------------------------------------------------

$replaceDataset = true;


// ------------------------------------------------------------
// Upload one image per request
// ------------------------------------------------------------
// This avoids problems with PHP/cURL multipart handling
// when sending many files using the same "files[]" field.
// ------------------------------------------------------------

foreach ($faceFiles as $filePath) {

    $filename =
        basename($filePath);

    $mimeType = 'image/jpeg';

    if (
        function_exists(
            'mime_content_type'
        )
    ) {

        $detected =
            @mime_content_type(
                $filePath
            );

        if ($detected) {
            $mimeType = $detected;
        }
    }


    // --------------------------------------------------------
    // Create CURL file
    // --------------------------------------------------------

    $curlFile =
        curl_file_create(
            $filePath,
            $mimeType,
            $filename
        );


    // --------------------------------------------------------
    // Upload fields
    // --------------------------------------------------------

    $uploadFields = [
        'files[]' => $curlFile
    ];


    // --------------------------------------------------------
    // FIRST SUCCESSFUL UPLOAD ONLY
    // --------------------------------------------------------

    if ($replaceDataset) {

        $uploadFields['replace'] = '1';
    }


    // --------------------------------------------------------
    // Send to Render
    // --------------------------------------------------------

    $ch = curl_init(
        $FACE_SERVER .
        '/sync_faces'
    );

    curl_setopt_array($ch, [

        CURLOPT_RETURNTRANSFER => true,

        CURLOPT_POST => true,

        CURLOPT_POSTFIELDS =>
            $uploadFields,

        CURLOPT_CONNECTTIMEOUT =>
            15,

        CURLOPT_TIMEOUT =>
            45,

        CURLOPT_FOLLOWLOCATION =>
            true,

        CURLOPT_HTTPHEADER => [
            'Accept: application/json'
        ],

        CURLOPT_HTTP_VERSION =>
            CURL_HTTP_VERSION_1_1,

        CURLOPT_IPRESOLVE =>
            CURL_IPRESOLVE_V4
    ]);


    $response =
        curl_exec($ch);

    $curlError =
        curl_error($ch);

    $httpCode =
        curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );

    curl_close($ch);


    // --------------------------------------------------------
    // Decode response
    // --------------------------------------------------------

    $json = null;

    if ($response !== false) {

        $json =
            json_decode(
                $response,
                true
            );
    }


    // --------------------------------------------------------
    // SUCCESS
    // --------------------------------------------------------

    if (
        $response !== false &&
        $httpCode >= 200 &&
        $httpCode < 300 &&
        is_array($json) &&
        !empty($json['success'])
    ) {

        $synced++;


        // ----------------------------------------------------
        // VERY IMPORTANT:
        //
        // Only the first successful upload replaces
        // the old Render dataset.
        // ----------------------------------------------------

        $replaceDataset = false;

    } else {

        $failed++;

        $failedFiles[] = [

            'file' =>
                $filename,

            'http_code' =>
                $httpCode,

            'error' =>
                $curlError ?:
                (
                    is_array($json) &&
                    isset($json['error'])
                        ? $json['error']
                        : 'Unknown upload error'
                )
        ];
    }


    // --------------------------------------------------------
    // Small delay to avoid hammering Render
    // --------------------------------------------------------

    usleep(100000);
}


// ============================================================
// STEP 5: CHECK SYNCHRONIZATION
// ============================================================

if ($failed > 0) {

    echo json_encode([

        'success' => false,

        'error' =>
            'Some face images could not be synchronized to Render.',

        'total_files' =>
            $totalFiles,

        'synced' =>
            $synced,

        'failed' =>
            $failed,

        'failed_files' =>
            $failedFiles
    ]);

    exit;
}


// ============================================================
// STEP 6: START RENDER TRAINING
// ============================================================
//
// /train is POST.
//
// Do NOT use GET here.
//

$trainResult =
    render_post_json(
        $FACE_SERVER . '/train',
        [],
        30
    );


if (
    !$trainResult['ok']
) {

    echo json_encode([

        'success' => false,

        'error' =>
            'Face images were synchronized, but Render could not start training.',

        'total_files' =>
            $totalFiles,

        'synced' =>
            $synced,

        'failed' =>
            $failed,

        'http_code' =>
            $trainResult['code'] ?? 0,

        'curl_error' =>
            $trainResult['error'] ?? '',

        'render_response' =>
            $trainResult['raw'] ?? ''
    ]);

    exit;
}


// ============================================================
// STEP 7: ASYNC MODE
// ============================================================
//
// Admin dashboard uses:
//
// face_train_multi.php?async=1
//
// After Render accepts the training request,
// return immediately.
//

if (
    isset($_GET['async'])
) {

    echo json_encode([

        'success' => true,

        'mode' => 'async',

        'message' =>
            'Face images synchronized and Render training started.',

        'total_files' =>
            $totalFiles,

        'synced' =>
            $synced,

        'failed' =>
            $failed
    ]);

    exit;
}


// ============================================================
// STEP 8: SYNCHRONOUS MODE
// ============================================================
//
// Used when this file is called without ?async=1.
//
// Wait for Render training to finish.
//
// Maximum:
// 180 checks x 2 seconds
// = approximately 6 minutes
//

$trainingResult = null;

for (
    $i = 0;
    $i < 180;
    $i++
) {

    sleep(2);


    $statusResult =
        render_get(
            $FACE_SERVER .
            '/train/status',
            20
        );


    if (
        !$statusResult['ok'] ||
        !is_array(
            $statusResult['data']
        )
    ) {
        continue;
    }


    $status =
        $statusResult['data'];

    $state =
        $status['state'] ??
        'unknown';


    if (
        $state === 'done' ||
        $state === 'error'
    ) {

        $trainingResult =
            $status;

        break;
    }
}


// ============================================================
// TRAINING TIMEOUT
// ============================================================

if (
    $trainingResult === null
) {

    echo json_encode([

        'success' => false,

        'error' =>
            'Training timed out while waiting for Render.',

        'total_files' =>
            $totalFiles,

        'synced' =>
            $synced
    ]);

    exit;
}


// ============================================================
// TRAINING ERROR
// ============================================================

if (
    ($trainingResult['state'] ?? '') ===
    'error'
) {

    echo json_encode([

        'success' => false,

        'error' =>
            $trainingResult['message'] ??
            'Render training failed.',

        'total_files' =>
            $totalFiles,

        'synced' =>
            $synced,

        'training' =>
            $trainingResult
    ]);

    exit;
}


// ============================================================
// GET TRAINING RESULTS
// ============================================================

$result =
    $trainingResult['result'] ??
    [];


// ============================================================
// LBPH
// ============================================================

$lbph =
    $result['lbph'] ??
    [

        'ok' => false,

        'samples' => 0,

        'students' => 0,

        'error' =>
            'No LBPH result returned.'
    ];


// ============================================================
// FISHERFACES
// ============================================================

$fisherfaces =
    $result['fisherfaces'] ??
    [

        'ok' => false,

        'samples' => 0,

        'students' => 0,

        'error' =>
            'No Fisherfaces result returned.'
    ];


// ============================================================
// LABELS
// ============================================================

if (
    !empty($lbph['ok'])
) {

    $lbphLabel =
        'Trained on ' .
        ($lbph['samples'] ?? 0) .
        ' samples / ' .
        ($lbph['students'] ?? 0) .
        ' students';

} else {

    $lbphLabel =
        'Error: ' .
        ($lbph['error'] ?? 'unknown');
}


if (
    !empty($fisherfaces['ok'])
) {

    $fisherLabel =
        'Trained on ' .
        ($fisherfaces['samples'] ?? 0) .
        ' samples / ' .
        ($fisherfaces['students'] ?? 0) .
        ' students';

} else {

    $fisherLabel =
        'Error: ' .
        ($fisherfaces['error'] ?? 'unknown');
}


// ============================================================
// FINAL RESPONSE
// ============================================================

echo json_encode([

    'success' => true,

    'mode' => 'completed',

    'message' =>
        'Face images synchronized and models successfully retrained.',

    'sync' => [

        'total_files' =>
            $totalFiles,

        'synced' =>
            $synced,

        'failed' =>
            $failed
    ],

    'lbph' =>
        $lbph,

    'fisherfaces' =>
        $fisherfaces,


    /*
     * Compatibility with older dashboard code.
     *
     * Older code may still refer to "fr_helper".
     * We map it to Fisherfaces.
     */

    'fr_helper' =>
        $fisherfaces,


    'labels' => [

        'lbph' =>
            $lbphLabel,

        'fisherfaces' =>
            $fisherLabel,

        'fr_helper' =>
            $fisherLabel
    ]

]);

?>