<?php
/**
 * face_train_multi.php
 * Delegates training to the persistent face_server.py Flask backend.
 * Returns a per-model JSON status so the UI can show what trained.
 */
header('Content-Type: application/json');

@set_time_limit(0);
@ini_set('memory_limit', '128M');
@ignore_user_abort(true);

define('FACE_SERVER', 'http://127.0.0.1:5001');

$scriptDir  = __DIR__;
$statusFile = $scriptDir . '/faces/.train_status.json';
$logFile    = $scriptDir . '/faces/.server_log.txt';

// ── Check / auto-start the face server ─────────────────────────────────────────────
function face_server_running_train() {
    $ch = curl_init(FACE_SERVER . '/status');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 2,
        CURLOPT_CONNECTTIMEOUT => 2,
    ]);
    curl_exec($ch);
    $ok = curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
    curl_close($ch);
    return $ok;
}

if (!face_server_running_train()) {
    $script = $scriptDir . '/face_server.py';
    if (stripos(PHP_OS, 'WIN') === 0) {
        $bat = tempnam(sys_get_temp_dir(), 'fsrv_') . '.bat';
        file_put_contents($bat,
            '@echo off' . "\r\n" .
            'py -3 "' . $script . '" > "' . $logFile . '" 2>&1' . "\r\n"
        );
        pclose(popen('start /B "" "' . $bat . '"', 'r'));
    } else {
        exec('nohup py -3 ' . escapeshellarg($script) .
             ' > ' . escapeshellarg($logFile) . ' 2>&1 &');
    }
    $ready = false;
    for ($i = 0; $i < 20; $i++) {
        sleep(1);
        if (face_server_running_train()) { $ready = true; break; }
    }
    if (!$ready) {
        // Fallback: run train_all_models.py directly (no server needed)
        run_training_direct($scriptDir, $statusFile);
        exit;
    }
}

// ── Direct training fallback (runs train_all_models.py without Flask) ──────────
function run_training_direct($scriptDir, $statusFile) {
    $trainScript = $scriptDir . '/train_all_models.py';
    $trainLog    = $scriptDir . '/faces/.train_log.txt';

    @file_put_contents($statusFile, json_encode([
        'state' => 'running', 'step' => 'init',
        'progress' => 2, 'message' => 'Training directly (server unavailable)…',
        'updated' => date('c'),
    ]));

    if (stripos(PHP_OS, 'WIN') === 0) {
        $bat = tempnam(sys_get_temp_dir(), 'trn_') . '.bat';
        file_put_contents($bat,
            '@echo off' . "\r\n" .
            'py -3 "' . $trainScript . '" > "' . $trainLog . '" 2>&1' . "\r\n"
        );
        pclose(popen('start /B "" "' . $bat . '"', 'r'));
    } else {
        exec('nohup py -3 ' . escapeshellarg($trainScript) .
             ' > ' . escapeshellarg($trainLog) . ' 2>&1 &');
    }

    echo json_encode(['success' => true, 'mode' => 'direct',
        'message' => 'Training started directly (server was unavailable)']);
}

// Initialize status file so the dashboard sees "starting" immediately
@file_put_contents($statusFile, json_encode([
    'state' => 'starting', 'step' => 'init',
    'progress' => 0, 'message' => 'Queued for training…', 'started' => date('c'),
]));

// ── Async mode: fire-and-forget POST to /train, return immediately ────────────
if (isset($_GET['async'])) {
    $ch = curl_init(FACE_SERVER . '/train');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => '{}',
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 5,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // If server call failed, use direct fallback
    if ($code !== 200) {
        run_training_direct($scriptDir, $statusFile);
        exit;
    }

    echo json_encode(['success' => true, 'mode' => 'async',
        'message' => 'Training started in background']);
    exit;
}

// ── Synchronous mode: POST /train then poll /train/status until done ─────────
$ch = curl_init(FACE_SERVER . '/train');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => '{}',
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT        => 5,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// If server /train call failed, fallback to direct
if ($code !== 200) {
    run_training_direct($scriptDir, $statusFile);
    exit;
}

// Poll every 2 s until state becomes 'done' or 'error' (max 5 min)
$parsed = null;
for ($i = 0; $i < 150; $i++) {
    sleep(2);
    $ch2 = curl_init(FACE_SERVER . '/train/status');
    curl_setopt_array($ch2, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3]);
    $body = curl_exec($ch2);
    curl_close($ch2);
    $s = json_decode($body, true);
    if ($s && in_array($s['state'] ?? '', ['done', 'error'])) {
        // Reshape into the format the UI expects
        $r = $s['result'] ?? [];
        $parsed = [
            'success'   => ($s['state'] === 'done'),
            'lbph'      => $r['lbph']      ?? ['ok' => false, 'error' => 'no data'],
            'fr_helper' => $r['fr_helper'] ?? ['ok' => false, 'error' => 'no data'],
        ];
        break;
    }
}

if (!$parsed) {
    echo json_encode(['success' => false, 'error' => 'Training timed out or returned no result']);
    exit;
}

// Helper text labels for the UI
$parsed['labels'] = [
    'lbph'      => $parsed['lbph']['ok']
        ? ('Trained on ' . ($parsed['lbph']['samples'] ?? 0) . ' samples / '
           . ($parsed['lbph']['students'] ?? 0) . ' students')
        : 'Error: ' . ($parsed['lbph']['error'] ?? 'unknown'),
    'fr_helper' => $parsed['fr_helper']['ok']
        ? ('Built ' . ($parsed['fr_helper']['encodings'] ?? 0) . ' encodings')
        : 'Skipped: ' . ($parsed['fr_helper']['error'] ?? 'unavailable'),
];

echo json_encode($parsed);
?>
