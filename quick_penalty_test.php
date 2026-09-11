<?php
/**
 * Quick Penalty Test - Check Current Status
 */

$conn = new mysqli("localhost","root","","attendance");

if($conn->connect_error){
    die("Connection failed: " . $conn->connect_error);
}

echo "<h2>🔍 Quick Penalty Status Check</h2>";

// Check if students have attendance records with penalties
echo "<h3>📊 Attendance Records with Penalties</h3>";
$attendance_check = $conn->query("
    SELECT COUNT(*) as total, 
           SUM(CASE WHEN penalty > 0 THEN 1 ELSE 0 END) as with_penalties,
           SUM(penalty) as total_penalties
    FROM attendance
")->fetch_assoc();

echo "<p>Total Attendance Records: " . $attendance_check['total'] . "</p>";
echo "<p>Records with Penalties: " . $attendance_check['with_penalties'] . "</p>";
echo "<p>Total Penalties: ₱" . number_format($attendance_check['total_penalties'], 2) . "</p>";

// Check student total_penalty field
echo "<h3>👥 Student Total Penalty Field</h3>";
$student_check = $conn->query("
    SELECT COUNT(*) as total_students,
           SUM(CASE WHEN total_penalty > 0 THEN 1 ELSE 0 END) as with_penalties,
           SUM(total_penalty) as total_penalties
    FROM users WHERE role = 'student'
")->fetch_assoc();

echo "<p>Total Students: " . $student_check['total_students'] . "</p>";
echo "<p>Students with Penalties: " . $student_check['with_penalties'] . "</p>";
echo "<p>Total Student Penalties: ₱" . number_format($student_check['total_penalties'], 2) . "</p>";

// Compare the two
echo "<h3>🔄 Comparison</h3>";
if($attendance_check['total_penalties'] != $student_check['total_penalties']) {
    echo "<p style='color: #ff6b6b;'>❌ PENALTY MISMATCH DETECTED!</p>";
    echo "<p>Attendance penalties: ₱" . number_format($attendance_check['total_penalties'], 2) . "</p>";
    echo "<p>Student penalties: ₱" . number_format($student_check['total_penalties'], 2) . "</p>";
    echo "<p>Difference: ₱" . number_format(abs($attendance_check['total_penalties'] - $student_check['total_penalties']), 2) . "</p>";
    
    echo "<div style='background: rgba(255,193,7,0.1); border: 2px solid rgba(255,215,0,0.3); padding: 20px; border-radius: 15px; margin: 20px 0;'>";
    echo "<h3 style='color: #FFD700;'>🔧 To Fix This Issue:</h3>";
    echo "<ol style='color: white;'>";
    echo "<li>Run: <a href='fix_penalty_discrepancy.php' style='color: #FFD700;'>fix_penalty_discrepancy.php</a></li>";
    echo "<li>This will recalculate all penalties and synchronize the data</li>";
    echo "<li>After running the fix, check your student dashboard and student list</li>";
    echo "</ol>";
    echo "</div>";
} else {
    echo "<p style='color: #51cf66;'>✅ Penalties are synchronized correctly!</p>";
}

// Show sample students with their penalties
echo "<h3>📋 Sample Student Penalties</h3>";
$sample_students = $conn->query("
    SELECT u.student_id, u.first_name, u.last_name, u.total_penalty,
           COALESCE(SUM(a.penalty), 0) as calculated_penalty
    FROM users u
    LEFT JOIN attendance a ON u.id = a.student_id
    WHERE u.role = 'student'
    GROUP BY u.id, u.student_id, u.first_name, u.last_name, u.total_penalty
    LIMIT 5
");

echo "<div style='background: rgba(0,0,0,0.8); padding: 20px; border-radius: 15px; color: white;'>";
echo "<table style='width: 100%; border-collapse: collapse;'>";
echo "<tr style='background: rgba(255,215,0,0.2);'>";
echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>Student</th>";
echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>User Penalty</th>";
echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>Calculated Penalty</th>";
echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>Status</th>";
echo "</tr>";

while($student = $sample_students->fetch_assoc()){
    $status = ($student['total_penalty'] == $student['calculated_penalty']) ? '✅ Match' : '❌ Mismatch';
    $status_color = ($student['total_penalty'] == $student['calculated_penalty']) ? '#51cf66' : '#ff6b6b';
    
    echo "<tr>";
    echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>" . htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) . "</td>";
    echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>₱" . number_format($student['total_penalty'], 2) . "</td>";
    echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>₱" . number_format($student['calculated_penalty'], 2) . "</td>";
    echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1;'><span style='color: $status_color;'>$status</span></td>";
    echo "</tr>";
}

echo "</table>";
echo "</div>";

echo "<div style='margin-top: 30px; text-align: center;'>";
echo "<a href='fix_penalty_discrepancy.php' style='background: linear-gradient(45deg, #FFD700, #FFA500); color: black; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; display: inline-block;'>🔧 Fix Penalty Discrepancy</a>";
echo "</div>";

$conn->close();
?>
