<?php
// One-time fix: populate missing attendance.penalty values from events table
// Run once by visiting: http://localhost/cics_attendance/fix_old_penalties.php
date_default_timezone_set('Asia/Manila');
$conn = new mysqli("localhost","root","","attendance");
if($conn->connect_error) die("Connection failed");

// Fix Absent records with missing penalty
$absentFixed = $conn->query("
    UPDATE attendance a
    JOIN events e ON a.event_id = e.id
    SET a.penalty = e.absent_penalty
    WHERE a.morning_status = 'Absent'
      AND (a.penalty IS NULL OR a.penalty = 0)
      AND e.absent_penalty > 0
");
$absentRows = $conn->affected_rows;

// Fix Late records with missing penalty
$lateFixed = $conn->query("
    UPDATE attendance a
    JOIN events e ON a.event_id = e.id
    SET a.penalty = e.late_penalty
    WHERE a.morning_status = 'Late'
      AND (a.penalty IS NULL OR a.penalty = 0)
      AND e.late_penalty > 0
");
$lateRows = $conn->affected_rows;

echo "<pre>\n";
echo "✅ Historical penalty fix complete!\n\n";
echo "Absent records fixed: $absentRows\n";
echo "Late records fixed:   $lateRows\n\n";
echo "You can delete this file now.\n";
echo "</pre>";
$conn->close();
?>
