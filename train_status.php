<?php
/**
 * train_status.php
 * Returns the current training status (written by train_all_models.py).
 * Polled by the admin dashboard to show live progress.
 *
 *   GET ?            -> {state, step, progress, message, elapsed, ...}
 *   GET ?clear=1     -> deletes the status file (used after admin acks "done")
 */
header('Content-Type: application/json');

$statusFile = __DIR__ . '/faces/.train_status.json';

if (isset($_GET['clear'])) {
    if (file_exists($statusFile)) @unlink($statusFile);
    echo json_encode(['cleared' => true]);
    exit;
}

if (!file_exists($statusFile)) {
    echo json_encode(['state' => 'idle']);
    exit;
}

$raw    = @file_get_contents($statusFile);
$parsed = $raw ? json_decode($raw, true) : null;

if (!$parsed) {
    echo json_encode(['state' => 'idle']);
    exit;
}

// Detect a stale "running" status (process crashed): nothing written for 5 min
if (($parsed['state'] ?? '') === 'running') {
    $mtime = @filemtime($statusFile);
    if ($mtime && (time() - $mtime) > 900) {
        $parsed['state']   = 'error';
        $parsed['message'] = 'Training appears stalled (no update in 15 min). Check faces/.train_log.txt';
    }
}

echo json_encode($parsed);
?>
