<?php
date_default_timezone_set('Asia/Manila');

include("db.php");

if($conn->connect_error){
    die("Connection failed: " . $conn->connect_error);
}

$event = $conn->query("SELECT * FROM events WHERE event_date = CURDATE() ORDER BY id DESC LIMIT 1")->fetch_assoc();
if(!$event) return;

$event_id   = $event['id'];
$event_type = $event['event_type'] ?? '';
$absentPenalty = floatval($event['absent_penalty']);
$latePenalty   = floatval($event['late_penalty']);
$now = date("H:i:s");

function extendTime($timeStr, $minutes){
    if(!$timeStr) return $timeStr;
    return date('H:i:s', strtotime($timeStr) + ($minutes * 60));
}

function markSessionAbsent($conn, $event_id, $session, $absentPenalty) {
    $statusCol = $session . '_status';
    $inCol     = $session . '_in';

    $students = $conn->query("SELECT id FROM users WHERE role='student'");
    if(!$students) return;

    while($s = $students->fetch_assoc()){
        $sid = $s['id'];

        $existing = $conn->query("
            SELECT id, {$statusCol}, penalty
            FROM attendance
            WHERE student_id='{$sid}' AND event_id='{$event_id}' AND date=CURDATE()
            LIMIT 1
        ")->fetch_assoc();

        if($existing){
            // Already has a real status for this session (Present/Late) — skip
            if($existing[$statusCol] !== NULL && $existing[$statusCol] !== 'Absent') continue;
            // Already marked Absent for this session — don't double-penalize
            if($existing[$statusCol] === 'Absent') continue;

            // Record exists but this session is still NULL — mark Absent
            $newPenalty = floatval($existing['penalty'] ?? 0) + $absentPenalty;
            $conn->query("
                UPDATE attendance
                SET {$statusCol}='Absent', penalty={$newPenalty}
                WHERE id={$existing['id']}
            ");
            $conn->query("
                UPDATE users
                SET total_penalty = total_penalty + {$absentPenalty}
                WHERE id='{$sid}'
            ");
        } else {
            // No record at all — insert with this session as Absent
            $conn->query("
                INSERT INTO attendance (student_id, event_id, date, {$statusCol}, penalty)
                VALUES ('{$sid}', '{$event_id}', CURDATE(), 'Absent', '{$absentPenalty}')
            ");
            $conn->query("
                UPDATE users
                SET total_penalty = total_penalty + {$absentPenalty}
                WHERE id='{$sid}'
            ");
        }
    }
}

// ── After logout window closes: logged-in-but-no-logout = Absent ────────────
function finalizeAbsent($conn, $event_id, $session, $latePenalty, $absentPenalty) {
    $statusCol = $session . '_status';
    $inCol     = $session . '_in';
    $outCol    = $session . '_out';

    $students = $conn->query("SELECT id FROM users WHERE role='student'");
    if(!$students) return;

    while($s = $students->fetch_assoc()){
        $sid = $s['id'];
        $row = $conn->query("
            SELECT id, {$inCol}, {$outCol}, {$statusCol}, penalty
            FROM attendance
            WHERE student_id='{$sid}' AND event_id='{$event_id}' AND date=CURDATE()
            LIMIT 1
        ")->fetch_assoc();

        if(!$row) continue;                       // No record at all
        if(!$row[$inCol]) continue;               // No login (handled by markSessionAbsent or logout scan)
        if($row[$outCol]) continue;               // Has logout = session complete, keep status
        if($row[$statusCol] === 'Absent') continue; // Already absent

        // Logged in but never logged out = Absent
        // Adjust penalty: remove any late penalty already charged, add absent penalty
        $prevPenalty = ($row[$statusCol] === 'Late') ? $latePenalty : 0.0;
        $newPenalty  = floatval($row['penalty'] ?? 0) - $prevPenalty + $absentPenalty;

        $conn->query("
            UPDATE attendance
            SET {$statusCol}='Absent', penalty={$newPenalty}
            WHERE id={$row['id']}
        ");
        $conn->query("
            UPDATE users
            SET total_penalty = GREATEST(0, total_penalty - {$prevPenalty} + {$absentPenalty})
            WHERE id='{$sid}'
        ");
    }
}

// Morning session: mark absent only after login window + 15-min grace closes
$showMorning = ($event_type !== 'Afternoon Only');
if($showMorning && $event['morning_login_end'] && $now > extendTime($event['morning_login_end'], 15)) {
    markSessionAbsent($conn, $event_id, 'morning', $absentPenalty);
}

// Afternoon session: mark absent only after login window + 15-min grace closes
$showAfternoon = ($event_type !== 'Morning Only');
if($showAfternoon && $event['afternoon_login_end'] && $now > extendTime($event['afternoon_login_end'], 15)) {
    markSessionAbsent($conn, $event_id, 'afternoon', $absentPenalty);
}

// After logout windows close: finalize "logged in but no logout" = Absent
if($showMorning && $event['morning_logout_end'] && $now > extendTime($event['morning_logout_end'], 15)) {
    finalizeAbsent($conn, $event_id, 'morning', $latePenalty, $absentPenalty);
}
if($showAfternoon && $event['afternoon_logout_end'] && $now > extendTime($event['afternoon_logout_end'], 15)) {
    finalizeAbsent($conn, $event_id, 'afternoon', $latePenalty, $absentPenalty);
}
?>