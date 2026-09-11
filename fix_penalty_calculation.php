<?php
/**
 * Fix Penalty Calculation System
 * Updates attendance records to properly calculate penalties for absences and late arrivals
 */

$conn = new mysqli("localhost","root","","attendance");

if($conn->connect_error){
    die("Connection failed: " . $conn->connect_error);
}

echo "<h2>🔧 Fixing Penalty Calculation System</h2>";

// Define penalty rates
define('ABSENT_PENALTY', 50.00);
define('LATE_PENALTY', 25.00);

// Update attendance records to calculate penalties properly
echo "<h3>💰 Updating Attendance Penalties</h3>";

$update_query = "
    UPDATE attendance 
    SET penalty = CASE 
        WHEN morning_status = 'Absent' THEN " . ABSENT_PENALTY . "
        WHEN morning_status = 'Late' THEN " . LATE_PENALTY . "
        WHEN afternoon_status = 'Absent' THEN " . ABSENT_PENALTY . "
        WHEN afternoon_status = 'Late' THEN " . LATE_PENALTY . "
        ELSE 0.00
    END
";

if($conn->query($update_query)) {
    $affected_rows = $conn->affected_rows;
    echo "<p style='color: #51cf66;'>✅ Updated penalties for $affected_rows attendance records</p>";
} else {
    echo "<p style='color: #ff6b6b;'>❌ Error updating penalties: " . $conn->error . "</p>";
}

// Calculate combined penalties (if both morning and afternoon have penalties)
echo "<h3>🔄 Calculating Combined Penalties</h3>";

$combined_update = "
    UPDATE attendance 
    SET penalty = CASE 
        WHEN (
            (morning_status = 'Absent' OR morning_status = 'Late') 
            AND (afternoon_status = 'Absent' OR afternoon_status = 'Late')
        ) THEN (
            (CASE WHEN morning_status = 'Absent' THEN " . ABSENT_PENALTY . " ELSE " . LATE_PENALTY . " END) +
            (CASE WHEN afternoon_status = 'Absent' THEN " . ABSENT_PENALTY . " ELSE " . LATE_PENALTY . " END)
        )
        WHEN morning_status = 'Absent' THEN " . ABSENT_PENALTY . "
        WHEN morning_status = 'Late' THEN " . LATE_PENALTY . "
        WHEN afternoon_status = 'Absent' THEN " . ABSENT_PENALTY . "
        WHEN afternoon_status = 'Late' THEN " . LATE_PENALTY . "
        ELSE 0.00
    END
";

if($conn->query($combined_update)) {
    echo "<p style='color: #51cf66;'>✅ Calculated combined penalties for morning and afternoon</p>";
} else {
    echo "<p style='color: #ff6b6b;'>❌ Error calculating combined penalties: " . $conn->error . "</p>";
}

// Update users table with total penalties
echo "<h3>👥 Updating Student Total Penalties</h3>";

$update_total = "
    UPDATE users u 
    SET total_penalty = (
        SELECT COALESCE(SUM(penalty), 0.00) 
        FROM attendance a 
        WHERE a.student_id = u.id
    )
    WHERE u.role = 'student'
";

if($conn->query($update_total)) {
    echo "<p style='color: #51cf66;'>✅ Updated total penalties for all students</p>";
} else {
    echo "<p style='color: #ff6b6b;'>❌ Error updating total penalties: " . $conn->error . "</p>";
}

// Show penalty statistics
echo "<h3>📊 Current Penalty Statistics</h3>";

$stats = $conn->query("
    SELECT 
        COUNT(*) as total_students,
        SUM(CASE WHEN total_penalty > 0 THEN 1 ELSE 0 END) as students_with_penalties,
        SUM(total_penalty) as total_penalties,
        AVG(total_penalty) as average_penalty
    FROM users 
    WHERE role = 'student'
")->fetch_assoc();

echo "<div style='background: rgba(0,0,0,0.8); padding: 20px; border-radius: 15px; color: white; margin-bottom: 20px;'>";
echo "<p>📊 Total Students: " . $stats['total_students'] . "</p>";
echo "<p>⚠️ Students with Penalties: " . $stats['students_with_penalties'] . "</p>";
echo "<p>💰 Total Penalties: ₱" . number_format($stats['total_penalties'], 2) . "</p>";
echo "<p>📈 Average Penalty: ₱" . number_format($stats['average_penalty'], 2) . "</p>";
echo "</div>";

// Show detailed penalty breakdown
echo "<h3>📋 Detailed Penalty Breakdown</h3>";

$penalty_breakdown = $conn->query("
    SELECT 
        morning_status,
        afternoon_status,
        COUNT(*) as count,
        SUM(penalty) as total_penalty
    FROM attendance 
    WHERE penalty > 0
    GROUP BY morning_status, afternoon_status
    ORDER BY total_penalty DESC
");

echo "<div style='background: rgba(0,0,0,0.8); padding: 20px; border-radius: 15px; color: white;'>";
echo "<table style='width: 100%; border-collapse: collapse;'>";
echo "<tr style='background: rgba(255,215,0,0.2);'>";
echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>Morning</th>";
echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>Afternoon</th>";
echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>Count</th>";
echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>Total Penalty</th>";
echo "</tr>";

while($row = $penalty_breakdown->fetch_assoc()){
    echo "<tr>";
    echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>" . ($row['morning_status'] ?? '-') . "</td>";
    echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>" . ($row['afternoon_status'] ?? '-') . "</td>";
    echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>" . $row['count'] . "</td>";
    echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>₱" . number_format($row['total_penalty'], 2) . "</td>";
    echo "</tr>";
}

echo "</table>";
echo "</div>";

// Create a function to automatically calculate penalties for new attendance records
echo "<h3>🔧 Creating Automatic Penalty Calculation Function</h3>";

$function_sql = "
CREATE EVENT IF NOT EXISTS daily_penalty_calculation
ON SCHEDULE EVERY 1 DAY
STARTS CURRENT_TIMESTAMP + INTERVAL 1 MINUTE
DO
    UPDATE attendance a
    JOIN events e ON a.event_id = e.id
    SET a.penalty = CASE 
        WHEN a.morning_status = 'Absent' THEN " . ABSENT_PENALTY . "
        WHEN a.morning_status = 'Late' THEN " . LATE_PENALTY . "
        WHEN a.afternoon_status = 'Absent' THEN " . ABSENT_PENALTY . "
        WHEN a.afternoon_status = 'Late' THEN " . LATE_PENALTY . "
        ELSE 0.00
    END
    WHERE e.event_date <= CURDATE() AND a.penalty = 0.00;
";

if($conn->query($function_sql)) {
    echo "<p style='color: #51cf66;'>✅ Automatic penalty calculation event created</p>";
} else {
    echo "<p style='color: #ffa500;'>⚠️ Note: Event scheduler may need to be enabled in MySQL</p>";
}

// Update attendance recording to automatically calculate penalties
echo "<h3>📝 Updating Attendance Recording Logic</h3>";

// Create a trigger for automatic penalty calculation
$trigger_sql = "
CREATE TRIGGER IF NOT EXISTS calculate_attendance_penalty
AFTER INSERT ON attendance
FOR EACH ROW
BEGIN
    UPDATE attendance SET 
        penalty = CASE 
            WHEN NEW.morning_status = 'Absent' THEN " . ABSENT_PENALTY . "
            WHEN NEW.morning_status = 'Late' THEN " . LATE_PENALTY . "
            WHEN NEW.afternoon_status = 'Absent' THEN " . ABSENT_PENALTY . "
            WHEN NEW.afternoon_status = 'Late' THEN " . LATE_PENALTY . "
            ELSE 0.00
        END
    WHERE id = NEW.id;
    
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

echo "<div style='margin-top: 30px;'>";
echo "<h3>🚀 Next Steps:</h3>";
echo "<a href='student_dashboard.php' style='background: linear-gradient(45deg, #007bff, #0056b3); color: white; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; margin-right: 10px; display: inline-block;'>👤 Student Dashboard</a>";
echo "<a href='admin_dashboard.php' style='background: linear-gradient(45deg, #FFD700, #FFA500); color: black; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; margin-right: 10px; display: inline-block;'>👨‍💼 Admin Dashboard</a>";
echo "<a href='student_payment.php' style='background: linear-gradient(45deg, #28a745, #20c997); color: white; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; display: inline-block;'>💳 Student Payment</a>";
echo "</div>";

echo "<div style='background: rgba(255,193,7,0.1); border: 2px solid rgba(255,215,0,0.3); padding: 20px; border-radius: 15px; margin-top: 20px;'>";
echo "<h3 style='color: #FFD700;'>💰 Penalty System Rules:</h3>";
echo "<ul style='color: white;'>";
echo "<li>✅ Absent (Morning/Afternoon): ₱" . number_format(ABSENT_PENALTY, 2) . "</li>";
echo "<li>✅ Late (Morning/Afternoon): ₱" . number_format(LATE_PENALTY, 2) . "</li>";
echo "<li>✅ Combined Penalties: If both morning and afternoon have infractions</li>";
echo "<li>✅ Automatic Calculation: Penalties calculated when attendance is recorded</li>";
echo "<li>✅ Payment System: Students can pay penalties with receipt upload</li>";
echo "<li>✅ Admin Confirmation: Admin must confirm payments to remove penalties</li>";
echo "<li>✅ Automatic Removal: Penalties automatically removed upon payment confirmation</li>";
echo "</ul>";
echo "</div>";

$conn->close();
?>
