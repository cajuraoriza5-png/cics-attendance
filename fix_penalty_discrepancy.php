<?php
/**
 * Fix Penalty Calculation Discrepancy
 * Ensures penalties are calculated correctly across all pages
 */

$conn = new mysqli("localhost","root","","attendance");

if($conn->connect_error){
    die("Connection failed: " . $conn->connect_error);
}

echo "<h2>🔧 Fixing Penalty Calculation Discrepancy</h2>";

// Step 1: Check current penalty calculation status
echo "<h3>📊 Current Penalty Status</h3>";

$check_query = $conn->query("
    SELECT 
        u.id,
        u.student_id,
        u.first_name,
        u.last_name,
        u.total_penalty as user_penalty,
        COALESCE(SUM(a.penalty), 0) as attendance_penalty
    FROM users u
    LEFT JOIN attendance a ON u.id = a.student_id
    WHERE u.role = 'student'
    GROUP BY u.id, u.student_id, u.first_name, u.last_name, u.total_penalty
    HAVING user_penalty != attendance_penalty OR user_penalty = 0
    ORDER BY u.last_name, u.first_name
");

if($check_query->num_rows > 0) {
    echo "<p style='color: #ff6b6b;'>⚠️ Found " . $check_query->num_rows . " students with penalty discrepancies</p>";
    
    echo "<div style='background: rgba(0,0,0,0.8); padding: 20px; border-radius: 15px; color: white; margin-bottom: 20px;'>";
    echo "<table style='width: 100%; border-collapse: collapse;'>";
    echo "<tr style='background: rgba(255,215,0,0.2);'>";
    echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>Student</th>";
    echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>User Penalty</th>";
    echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>Attendance Penalty</th>";
    echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>Status</th>";
    echo "</tr>";
    
    while($row = $check_query->fetch_assoc()){
        echo "<tr>";
        echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>" . htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) . "</td>";
        echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>₱" . number_format($row['user_penalty'], 2) . "</td>";
        echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>₱" . number_format($row['attendance_penalty'], 2) . "</td>";
        echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>";
        if($row['user_penalty'] != $row['attendance_penalty']) {
            echo "<span style='color: #ff6b6b;'>Mismatch</span>";
        } else {
            echo "<span style='color: #51cf66;'>Match</span>";
        }
        echo "</td>";
        echo "</tr>";
    }
    echo "</table>";
    echo "</div>";
} else {
    echo "<p style='color: #51cf66;'>✅ All student penalties are correctly calculated</p>";
}

// Step 2: Recalculate attendance penalties
echo "<h3>💰 Recalculating Attendance Penalties</h3>";

$absent_penalty = 50.00;
$late_penalty = 25.00;

$update_attendance = $conn->query("
    UPDATE attendance 
    SET penalty = CASE 
        WHEN morning_status = 'Absent' THEN $absent_penalty
        WHEN morning_status = 'Late' THEN $late_penalty
        WHEN afternoon_status = 'Absent' THEN $absent_penalty
        WHEN afternoon_status = 'Late' THEN $late_penalty
        ELSE 0.00
    END
");

if($update_attendance) {
    $affected_rows = $conn->affected_rows;
    echo "<p style='color: #51cf66;'>✅ Updated penalties for $affected_rows attendance records</p>";
} else {
    echo "<p style='color: #ff6b6b;'>❌ Error updating attendance penalties: " . $conn->error . "</p>";
}

// Step 3: Calculate combined penalties for both morning and afternoon
echo "<h3>🔄 Calculating Combined Penalties</h3>";

$combined_update = $conn->query("
    UPDATE attendance 
    SET penalty = CASE 
        WHEN (
            (morning_status = 'Absent' OR morning_status = 'Late') 
            AND (afternoon_status = 'Absent' OR afternoon_status = 'Late')
        ) THEN (
            (CASE WHEN morning_status = 'Absent' THEN $absent_penalty ELSE $late_penalty END) +
            (CASE WHEN afternoon_status = 'Absent' THEN $absent_penalty ELSE $late_penalty END)
        )
        WHEN morning_status = 'Absent' THEN $absent_penalty
        WHEN morning_status = 'Late' THEN $late_penalty
        WHEN afternoon_status = 'Absent' THEN $absent_penalty
        WHEN afternoon_status = 'Late' THEN $late_penalty
        ELSE 0.00
    END
");

if($combined_update) {
    echo "<p style='color: #51cf66;'>✅ Calculated combined penalties for morning and afternoon</p>";
} else {
    echo "<p style='color: #ff6b6b;'>❌ Error calculating combined penalties: " . $conn->error . "</p>";
}

// Step 4: Update all student total penalties
echo "<h3>👥 Updating Student Total Penalties</h3>";

$update_totals = $conn->query("
    UPDATE users u 
    SET total_penalty = (
        SELECT COALESCE(SUM(penalty), 0.00) 
        FROM attendance a 
        WHERE a.student_id = u.id
    )
    WHERE u.role = 'student'
");

if($update_totals) {
    $updated_students = $conn->affected_rows;
    echo "<p style='color: #51cf66;'>✅ Updated total penalties for $updated_students students</p>";
} else {
    echo "<p style='color: #ff6b6b;'>❌ Error updating total penalties: " . $conn->error . "</p>";
}

// Step 5: Verify the fix
echo "<h3>✅ Verifying Penalty Fix</h3>";

$verify_query = $conn->query("
    SELECT 
        u.id,
        u.student_id,
        u.first_name,
        u.last_name,
        u.total_penalty as user_penalty,
        COALESCE(SUM(a.penalty), 0) as attendance_penalty
    FROM users u
    LEFT JOIN attendance a ON u.id = a.student_id
    WHERE u.role = 'student'
    GROUP BY u.id, u.student_id, u.first_name, u.last_name, u.total_penalty
    ORDER BY u.last_name, u.first_name
");

$mismatch_count = 0;
$total_students = 0;

echo "<div style='background: rgba(0,0,0,0.8); padding: 20px; border-radius: 15px; color: white; margin-bottom: 20px;'>";
echo "<table style='width: 100%; border-collapse: collapse;'>";
echo "<tr style='background: rgba(255,215,0,0.2);'>";
echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>Student ID</th>";
echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>Name</th>";
echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>User Penalty</th>";
echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>Attendance Penalty</th>";
echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>Status</th>";
echo "</tr>";

while($row = $verify_query->fetch_assoc()){
    $total_students++;
    $status = ($row['user_penalty'] == $row['attendance_penalty']) ? 'Match' : 'Mismatch';
    $status_color = ($row['user_penalty'] == $row['attendance_penalty']) ? '#51cf66' : '#ff6b6b';
    
    if($row['user_penalty'] != $row['attendance_penalty']) {
        $mismatch_count++;
    }
    
    echo "<tr>";
    echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>" . htmlspecialchars($row['student_id']) . "</td>";
    echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>" . htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) . "</td>";
    echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>₱" . number_format($row['user_penalty'], 2) . "</td>";
    echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>₱" . number_format($row['attendance_penalty'], 2) . "</td>";
    echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1;'><span style='color: $status_color;'>$status</span></td>";
    echo "</tr>";
}

echo "</table>";
echo "</div>";

// Step 6: Create automatic penalty calculation trigger
echo "<h3>🔧 Creating Automatic Penalty Calculation Trigger</h3>";

$trigger_sql = "
CREATE TRIGGER IF NOT EXISTS update_student_penalty_after_attendance
AFTER INSERT ON attendance
FOR EACH ROW
BEGIN
    UPDATE users SET 
        total_penalty = (
            SELECT COALESCE(SUM(penalty), 0.00) 
            FROM attendance 
            WHERE student_id = NEW.student_id
        )
    WHERE id = NEW.student_id;
END
";

if($conn->query($trigger_sql)) {
    echo "<p style='color: #51cf66;'>✅ Automatic penalty calculation trigger created</p>";
} else {
    echo "<p style='color: #ff6b6b;'>❌ Error creating trigger: " . $conn->error . "</p>";
}

// Step 7: Create update trigger for attendance modifications
$update_trigger_sql = "
CREATE TRIGGER IF NOT EXISTS update_student_penalty_after_attendance_update
AFTER UPDATE ON attendance
FOR EACH ROW
BEGIN
    UPDATE users SET 
        total_penalty = (
            SELECT COALESCE(SUM(penalty), 0.00) 
            FROM attendance 
            WHERE student_id = NEW.student_id
        )
    WHERE id = NEW.student_id;
END
";

if($conn->query($update_trigger_sql)) {
    echo "<p style='color: #51cf66;'>✅ Automatic penalty update trigger created</p>";
} else {
    echo "<p style='color: #ff6b6b;'>❌ Error creating update trigger: " . $conn->error . "</p>";
}

// Final results
echo "<h3>📋 Final Results</h3>";
echo "<div style='background: rgba(0,0,0,0.8); padding: 20px; border-radius: 15px; color: white; margin-bottom: 20px;'>";
echo "<p>📊 Total Students Checked: $total_students</p>";
echo "<p>✅ Students with Correct Penalties: " . ($total_students - $mismatch_count) . "</p>";
echo "<p>⚠️ Students with Mismatched Penalties: $mismatch_count</p>";
echo "</div>";

if($mismatch_count == 0) {
    echo "<div style='background: rgba(40,167,69,0.1); border: 2px solid rgba(40,167,69,0.3); padding: 20px; border-radius: 15px; margin: 20px 0;'>";
    echo "<h3 style='color: #28a745;'>🎉 Penalty Calculation Fixed Successfully!</h3>";
    echo "<p style='color: white;'>All student penalties are now correctly calculated and synchronized across all pages.</p>";
    echo "</div>";
} else {
    echo "<div style='background: rgba(220,53,69,0.1); border: 2px solid rgba(220,53,69,0.3); padding: 20px; border-radius: 15px; margin: 20px 0;'>";
    echo "<h3 style='color: #dc3545;'>⚠️ Some Penalties Still Need Attention</h3>";
    echo "<p style='color: white;'>$mismatch_count students still have penalty discrepancies. Please review the table above.</p>";
    echo "</div>";
}

echo "<div style='margin-top: 30px;'>";
echo "<h3>🚀 Next Steps:</h3>";
echo "<a href='student_dashboard.php' style='background: linear-gradient(45deg, #007bff, #0056b3); color: white; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; margin-right: 10px; display: inline-block;'>👤 Student Dashboard</a>";
echo "<a href='student_list.php' style='background: linear-gradient(45deg, #28a745, #20c997); color: white; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; margin-right: 10px; display: inline-block;'>📋 Student List</a>";
echo "<a href='admin_dashboard.php' style='background: linear-gradient(45deg, #FFD700, #FFA500); color: black; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; display: inline-block;'>👨‍💼 Admin Dashboard</a>";
echo "</div>";

echo "<div style='background: rgba(255,193,7,0.1); border: 2px solid rgba(255,215,0,0.3); padding: 20px; border-radius: 15px; margin-top: 20px;'>";
echo "<h3 style='color: #FFD700;'>🔧 What This Fix Does:</h3>";
echo "<ul style='color: white;'>";
echo "<li>✅ Recalculates all attendance penalties properly</li>";
echo "<li>✅ Updates student total_penalty field to match attendance penalties</li>";
echo "<li>✅ Creates automatic triggers for future penalty calculations</li>";
echo "<li>✅ Ensures consistency across student dashboard and student list</li>";
echo "<li>✅ Fixes penalty calculation for both morning and afternoon sessions</li>";
echo "</ul>";
echo "</div>";

$conn->close();
?>
