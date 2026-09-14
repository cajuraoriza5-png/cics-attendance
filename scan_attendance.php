<?php
date_default_timezone_set('Asia/Manila');
header('Content-Type: application/json');

$config = require __DIR__ . '/config.php';
require __DIR__ . '/db.php';

// ── Validate input ────────────────────────────────────────────────────────
$student_id = intval($_POST['student_id'] ?? 0);
if(!$student_id){
    echo json_encode(['success'=>false,'message'=>'Invalid student ID']); exit;
}

// ── Verify student ────────────────────────────────────────────────────────
$st = $conn->prepare("SELECT id,first_name,last_name FROM users WHERE id=? AND role='student' AND face_registered=1");
$st->bind_param("i",$student_id); $st->execute();
$str = $st->get_result();
if($str->num_rows == 0){
    echo json_encode(['success'=>false,'message'=>'Student not found or face not registered']); exit;
}
$student      = $str->fetch_assoc();
$student_name = $student['first_name'].' '.$student['last_name'];
$st->close();

// ── Get today's event ────────────────────────────────────────────────────
$ev = $conn->prepare("SELECT * FROM events WHERE event_date=CURDATE() ORDER BY id DESC LIMIT 1");
$ev->execute();
$evr = $ev->get_result();
if($evr->num_rows == 0){
    echo json_encode(['success'=>false,'message'=>'No event scheduled for today']); exit;
}
$eventData = $evr->fetch_assoc();
$event_id  = $eventData['id'];
$event_type = $eventData['event_type'] ?? 'Whole Day';
$ev->close();

// ── Current time ──────────────────────────────────────────────────────────
$now = date("H:i:s");
$nowTs = strtotime($now);

// Helper: check if time is within a window
function inWindow($t, $start, $end){
    if(!$start || !$end) return false;
    $ts = strtotime($t); $s = strtotime($start); $e = strtotime($end);
    return $ts >= $s && $ts <= $e;
}

// Helper: add minutes to a HH:MM:SS string
function extendTime($timeStr, $minutes){
    if(!$timeStr) return $timeStr;
    return date('H:i:s', strtotime($timeStr) + ($minutes * 60));
}

// Determine which session and direction (login/logout)
$session = null; // 'morning' or 'afternoon'
$direction = null; // 'in' or 'out'

// Each window is extended by 15 minutes (grace period)
// Login grace = Late; Logout grace = still records normally
if(inWindow($now, $eventData['morning_login_start'],    extendTime($eventData['morning_login_end'],    15))){
    $session = 'morning';   $direction = 'in';
} elseif(inWindow($now, $eventData['morning_logout_start'],  extendTime($eventData['morning_logout_end'],  15))){
    $session = 'morning';   $direction = 'out';
} elseif(inWindow($now, $eventData['afternoon_login_start'], extendTime($eventData['afternoon_login_end'], 15))){
    $session = 'afternoon'; $direction = 'in';
} elseif(inWindow($now, $eventData['afternoon_logout_start'],extendTime($eventData['afternoon_logout_end'],15))){
    $session = 'afternoon'; $direction = 'out';
}

// If outside all windows, block
if(!$session){
    echo json_encode(['success'=>false,'message'=>'Not within any active attendance window']); exit;
}

// For Morning Only, skip afternoon; for Afternoon Only, skip morning
if($event_type === 'Morning Only' && $session === 'afternoon'){
    echo json_encode(['success'=>false,'message'=>'Afternoon scanning disabled for Morning Only events']); exit;
}
if($event_type === 'Afternoon Only' && $session === 'morning'){
    echo json_encode(['success'=>false,'message'=>'Morning scanning disabled for Afternoon Only events']); exit;
}

// ── Check existing record ─────────────────────────────────────────────────
$ca = $conn->prepare("SELECT * FROM attendance WHERE student_id=? AND event_id=? AND date=CURDATE() LIMIT 1");
$ca->bind_param("ii",$student_id,$event_id); $ca->execute();
$car = $ca->get_result();
$existing = $car->num_rows > 0 ? $car->fetch_assoc() : null;
$ca->close();

// ── Determine which column to update ──────────────────────────────────────
$inCol  = $session . '_in';   // e.g. morning_in or afternoon_in
$outCol = $session . '_out';  // e.g. morning_out or afternoon_out
$statusCol = $session . '_status'; // e.g. morning_status or afternoon_status

// Block duplicate: same direction already recorded
if($direction === 'in' && $existing && $existing[$inCol] !== null){
    echo json_encode([
        'success'=>false,
        'message'=>'Already logged ' . $session . ' in',
        'student_name'=>$student_name,
        'scan_time'=>$existing[$inCol],
        'status'=>$existing[$statusCol]
    ]); exit;
}
if($direction === 'out' && $existing && $existing[$outCol] !== null){
    echo json_encode([
        'success'=>false,
        'message'=>'Already logged ' . $session . ' out',
        'student_name'=>$student_name,
        'scan_time'=>$existing[$outCol],
        'status'=>$existing[$statusCol]
    ]); exit;
}

// ── Determine status & per-session penalty ────────────────────────────────
$session_penalty = 0.0;
if($direction === 'in'){
    // Use morning/afternoon_late_time if set; otherwise login_end is the cutoff
    // (scans within [login_start..login_end] = Present, [login_end..login_end+15] = Late)
    $lateTime = !empty($eventData[$session . '_late_time'])
                    ? $eventData[$session . '_late_time']
                    : $eventData[$session . '_login_end'];
    if($lateTime && $nowTs > strtotime($lateTime)){
        $status = 'Late';
        $session_penalty = floatval($eventData['late_penalty'] ?? 0);
    } else {
        $status = 'Present';
    }
} else {
    // Logout
    $hadLogin = ($existing && $existing[$inCol] !== null);
    if(!$hadLogin){
        // No prior login + scanning out = Late (they were present but missed login scan)
        $status = 'Late';
        $session_penalty = floatval($eventData['late_penalty'] ?? 0);
    } else {
        // Has prior login = keep whatever status was set at login (Present or Late)
        $status = $existing[$statusCol] ?? 'Present';
    }
}

// ── Total penalty = existing + any new late penalty ───────────────────────
$old_penalty = floatval($existing['penalty'] ?? 0);
$total_penalty = $old_penalty + $session_penalty;

// ── Build & execute SQL ───────────────────────────────────────────────────
$ok = false;

if($existing){
    $col = ($direction === 'in') ? $inCol : $outCol;
    if($direction === 'out' && $existing[$inCol] !== null){
        // Has prior login: only record the out-time, do NOT overwrite status
        $u = $conn->prepare("UPDATE attendance SET {$col}=? WHERE student_id=? AND event_id=? AND date=CURDATE()");
        $u->bind_param("sii", $now, $student_id, $event_id);
    } else {
        $u = $conn->prepare("UPDATE attendance SET {$col}=?, {$statusCol}=?, penalty=? WHERE student_id=? AND event_id=? AND date=CURDATE()");
        $u->bind_param("ssdii", $now, $status, $total_penalty, $student_id, $event_id);
    }
    $ok = $u->execute();
    $u->close();
} else {
    $i = $conn->prepare("INSERT INTO attendance(student_id,event_id,date,{$inCol},{$outCol},{$statusCol},penalty) VALUES(?,?,CURDATE(),?,?,?,?)");
    $n = ($direction === 'in') ? $now : null;
    $o = ($direction === 'out') ? $now : null;
    $i->bind_param("iisssd", $student_id, $event_id, $n, $o, $status, $total_penalty);
    $ok = $i->execute();
    $i->close();
}

// ── Sync users.total_penalty ──────────────────────────────────────────────
// Only add the NEW session penalty; absent penalties stay (from auto_absent)
if($ok){
    if($session_penalty != 0){
        $adj = $conn->prepare("UPDATE users SET total_penalty=GREATEST(0.00, total_penalty + ?) WHERE id=?");
        $adj->bind_param("di",$session_penalty,$student_id);
        $adj->execute();
        $adj->close();
    }
    echo json_encode([
        'success'=>true,
        'message'=>"Attendance recorded: $session " . ucfirst($direction) . " — $status",
        'student_name'=>$student_name,
        'status'=>$status,
        'scan_time'=>$now,
        'penalty'=>$total_penalty,
        'session'=>$session,
        'direction'=>$direction
    ]);
} else {
    echo json_encode(['success'=>false,'message'=>'Failed to save attendance']);
}

$conn->close();
?>