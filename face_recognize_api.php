<?php
header('Content-Type: application/json');
@set_time_limit(30);
@ini_set('memory_limit','128M');

$config = require __DIR__ . '/config.php';
define('FACE_SERVER', $config['python_service']['url']);

// ── Input validation ─────────────────────────────────────────────────────
$imageData = $_POST['image'] ?? '';
if (!$imageData) {
    echo json_encode(['error' => 'No image data received']);
    exit;
}

// Strip data-URL prefix if present
$imageData = preg_replace('/^data:image\/\w+;base64,/', '', $imageData);

// ── Proxy to Flask server ─────────────────────────────────────────────────
$ch = curl_init(FACE_SERVER . '/recognize');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode(['image' => $imageData]),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT        => 20,
]);
$output = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$result = json_decode($output, true);

if (!$result || $httpCode !== 200) {
    if (!$output) {
        echo json_encode(['error' => 'Face server not running. Run: py -3 face_server.py']);
    } else {
        echo json_encode(['error' => 'Face server error (HTTP ' . $httpCode . ')']);
    }
    exit;
}

goto enrich;

// ── Direct recognition fallback (calls face_recognize_api.py) ────────────
function recognize_direct($imgData) {
    $script = __DIR__ . '/face_recognize_api.py';
    $input  = json_encode(['image' => $imgData]);
    $cmd    = 'py -3 "' . $script . '"';

    $desc = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
    $proc = proc_open($cmd, $desc, $pipes, __DIR__);
    if (!is_resource($proc)) return null;

    fwrite($pipes[0], $input);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    $parsed = json_decode($out, true);
    return $parsed ?: null;
}

enrich:

// ── Enrich with student names from DB ───────────────────────────────────
$conn = new mysqli('localhost', 'root', '', 'attendance');
if (!$conn->connect_error) {

    if (isset($result['lbph']['id']) && $result['lbph']['id'] > 0) {
        $id   = intval($result['lbph']['id']);
        $stmt = $conn->prepare(
            "SELECT first_name, last_name, student_id FROM users WHERE id = ? LIMIT 1"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $result['lbph']['name']       = $row['first_name'] . ' ' . $row['last_name'];
            $result['lbph']['student_id'] = $row['student_id'] ?? '';
        } else {
            $result['lbph']['name'] = 'Unknown';
        }
    } elseif (isset($result['lbph']['id'])) {
        $result['lbph']['name'] = 'Unknown';
    }

    $conn->close();
}

echo json_encode($result);
?>
