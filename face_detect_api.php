<?php
/**
 * face_detect_api.php
 * Lightweight face-detection-only endpoint (no recognition).
 * Called by face_enroll.php every ~500ms to draw the bounding box.
 * Much faster than face_recognize_api.php because it skips DeepFace / LBPH.
 */
header('Content-Type: application/json');

$imageData = $_POST['image'] ?? '';
if (!$imageData) {
    echo json_encode(['error' => 'No image data']);
    exit;
}

// Strip data-URL prefix if present
$imageData = preg_replace('/^data:image\/\w+;base64,/', '', $imageData);

$scriptPath = __DIR__ . '/face_detect_only.py';
$input      = json_encode(['image' => $imageData]);

$descriptorSpec = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

$process = proc_open(
    'py -3 "' . $scriptPath . '"',
    $descriptorSpec,
    $pipes,
    __DIR__
);

if (!is_resource($process)) {
    echo json_encode(['error' => 'Python process failed to start']);
    exit;
}

fwrite($pipes[0], $input);
fclose($pipes[0]);

$output = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
proc_close($process);

$result = json_decode($output, true);

if (!$result) {
    echo json_encode([
        'error'  => 'Python returned invalid JSON',
        'raw'    => substr($output, 0, 300),
        'stderr' => substr($stderr, 0, 300)
    ]);
    exit;
}

echo json_encode($result);
?>
