<?php
/**
 * Update Payment System Database Structure
 * Adds receipt upload, admin confirmation, and penalty tracking
 */

$conn = new mysqli("localhost","root","","attendance");

if($conn->connect_error){
    die("Connection failed: " . $conn->connect_error);
}

echo "<h2>🔧 Updating Payment System Database</h2>";

// Drop and recreate payments table with enhanced structure
echo "<h3>🗄️ Recreating Payments Table</h3>";
$conn->query("DROP TABLE IF EXISTS payments");

$payments_sql = "
CREATE TABLE payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_method ENUM('GCash', 'Cash') NOT NULL,
    gcash_reference VARCHAR(50),
    receipt_image VARCHAR(255),
    payment_date DATE NOT NULL,
    status ENUM('Pending', 'Confirmed', 'Rejected') DEFAULT 'Pending',
    admin_notes TEXT,
    confirmed_by INT,
    confirmed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (confirmed_by) REFERENCES users(id) ON DELETE SET NULL
)";

if($conn->query($payments_sql)) {
    echo "<p style='color: #51cf66;'>✅ Enhanced payments table created</p>";
} else {
    echo "<p style='color: #ff6b6b;'>❌ Error creating payments table: " . $conn->error . "</p>";
}

// Create receipts directory
echo "<h3>📁 Creating Receipts Directory</h3>";
if(!is_dir('receipts')) {
    mkdir('receipts', 0755, true);
    echo "<p style='color: #51cf66;'>✅ Receipts directory created</p>";
} else {
    echo "<p style='color: #74c0fc;'>✅ Receipts directory already exists</p>";
}

// Update attendance table to ensure penalty calculation
echo "<h3>📊 Updating Attendance Penalty Calculation</h3>";

// First, let's check if penalty column exists
$check_penalty = $conn->query("SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'attendance' AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'penalty'");
if($check_penalty->num_rows == 0) {
    $conn->query("ALTER TABLE attendance ADD COLUMN penalty DECIMAL(10,2) DEFAULT 0.00");
    echo "<p style='color: #51cf66;'>✅ Penalty column added to attendance table</p>";
} else {
    echo "<p style='color: #74c0fc;'>✅ Penalty column already exists</p>";
}

// Update existing attendance records to calculate penalties for absences
echo "<h3>💰 Calculating Penalties for Existing Records</h3>";
$update_penalties = "
    UPDATE attendance 
    SET penalty = CASE 
        WHEN morning_status = 'Absent' THEN 50.00
        WHEN morning_status = 'Late' THEN 25.00
        ELSE 0.00
    END
    WHERE penalty = 0.00
";

if($conn->query($update_penalties)) {
    $affected_rows = $conn->affected_rows;
    echo "<p style='color: #51cf66;'>✅ Penalties calculated for $affected_rows attendance records</p>";
} else {
    echo "<p style='color: #ff6b6b;'>❌ Error calculating penalties: " . $conn->error . "</p>";
}

// Update users table total_penalty based on attendance
echo "<h3>🔄 Updating Total Penalties</h3>";
$update_total_penalties = "
    UPDATE users u 
    SET total_penalty = (
        SELECT COALESCE(SUM(penalty), 0.00) 
        FROM attendance a 
        WHERE a.student_id = u.id
    )
";

if($conn->query($update_total_penalties)) {
    echo "<p style='color: #51cf66;'>✅ Total penalties updated for all users</p>";
} else {
    echo "<p style='color: #ff6b6b;'>❌ Error updating total penalties: " . $conn->error . "</p>";
}

// Show updated table structures
echo "<h3>📋 Updated Database Structure</h3>";

// Payments table structure
echo "<h4>💳 Payments Table:</h4>";
$result = $conn->query("DESCRIBE payments");
echo "<div style='background: rgba(0,0,0,0.8); padding: 15px; border-radius: 10px; color: white; margin-bottom: 20px;'>";
echo "<table style='width: 100%; border-collapse: collapse;'>";
echo "<tr style='background: rgba(255,215,0,0.2);'>";
echo "<th style='padding: 8px; border: 1px solid rgba(255,215,0,0.3);'>Field</th>";
echo "<th style='padding: 8px; border: 1px solid rgba(255,215,0,0.3);'>Type</th>";
echo "<th style='padding: 8px; border: 1px solid rgba(255,215,0,0.3);'>Null</th>";
echo "<th style='padding: 8px; border: 1px solid rgba(255,215,0,0.3);'>Default</th>";
echo "</tr>";

while($row = $result->fetch_assoc()){
    echo "<tr>";
    echo "<td style='padding: 8px; border: 1px solid rgba(255,215,0,0.1);'>" . $row['Field'] . "</td>";
    echo "<td style='padding: 8px; border: 1px solid rgba(255,215,0,0.1);'>" . $row['Type'] . "</td>";
    echo "<td style='padding: 8px; border: 1px solid rgba(255,215,0,0.1);'>" . $row['Null'] . "</td>";
    echo "<td style='padding: 8px; border: 1px solid rgba(255,215,0,0.1);'>" . $row['Default'] . "</td>";
    echo "</tr>";
}
echo "</table>";
echo "</div>";

// Attendance table structure
echo "<h4>📊 Attendance Table:</h4>";
$result = $conn->query("DESCRIBE attendance");
echo "<div style='background: rgba(0,0,0,0.8); padding: 15px; border-radius: 10px; color: white;'>";
echo "<table style='width: 100%; border-collapse: collapse;'>";
echo "<tr style='background: rgba(255,215,0,0.2);'>";
echo "<th style='padding: 8px; border: 1px solid rgba(255,215,0,0.3);'>Field</th>";
echo "<th style='padding: 8px; border: 1px solid rgba(255,215,0,0.3);'>Type</th>";
echo "<th style='padding: 8px; border: 1px solid rgba(255,215,0,0.3);'>Null</th>";
echo "<th style='padding: 8px; border: 1px solid rgba(255,215,0,0.3);'>Default</th>";
echo "</tr>";

while($row = $result->fetch_assoc()){
    $highlight = ($row['Field'] == 'penalty') ? 'style="background: rgba(255,215,0,0.1);"' : '';
    echo "<tr $highlight>";
    echo "<td style='padding: 8px; border: 1px solid rgba(255,215,0,0.1);'>" . $row['Field'] . "</td>";
    echo "<td style='padding: 8px; border: 1px solid rgba(255,215,0,0.1);'>" . $row['Type'] . "</td>";
    echo "<td style='padding: 8px; border: 1px solid rgba(255,215,0,0.1);'>" . $row['Null'] . "</td>";
    echo "<td style='padding: 8px; border: 1px solid rgba(255,215,0,0.1);'>" . $row['Default'] . "</td>";
    echo "</tr>";
}
echo "</table>";
echo "</div>";

// Show current penalty statistics
echo "<h3>💰 Current Penalty Statistics</h3>";
$penalty_stats = $conn->query("
    SELECT 
        COUNT(*) as total_students,
        SUM(CASE WHEN total_penalty > 0 THEN 1 ELSE 0 END) as students_with_penalties,
        SUM(total_penalty) as total_penalties
    FROM users WHERE role = 'student'
")->fetch_assoc();

echo "<div style='background: rgba(0,0,0,0.8); padding: 15px; border-radius: 10px; color: white;'>";
echo "<p>📊 Total Students: " . $penalty_stats['total_students'] . "</p>";
echo "<p>⚠️ Students with Penalties: " . $penalty_stats['students_with_penalties'] . "</p>";
echo "<p>💰 Total Penalties: ₱" . number_format($penalty_stats['total_penalties'], 2) . "</p>";
echo "</div>";

echo "<div style='margin-top: 30px;'>";
echo "<h3>🚀 Next Steps:</h3>";
echo "<a href='student_payment.php' style='background: linear-gradient(45deg, #28a745, #20c997); color: white; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; margin-right: 10px; display: inline-block;'>💳 Student Payment Upload</a>";
echo "<a href='admin_payments.php' style='background: linear-gradient(45deg, #FFD700, #FFA500); color: black; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; margin-right: 10px; display: inline-block;'>👨‍💼 Admin Payment Confirmation</a>";
echo "<a href='student_dashboard.php' style='background: linear-gradient(45deg, #007bff, #0056b3); color: white; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; display: inline-block;'>👤 Student Dashboard</a>";
echo "</div>";

echo "<div style='background: rgba(255,193,7,0.1); border: 2px solid rgba(255,215,0,0.3); padding: 20px; border-radius: 15px; margin-top: 20px;'>";
echo "<h3 style='color: #FFD700;'>🔧 Payment System Features:</h3>";
echo "<ul style='color: white;'>";
echo "<li>✅ Receipt upload functionality</li>";
echo "<li>✅ GCash reference number tracking</li>";
echo "<li>✅ Admin confirmation system</li>";
echo "<li>✅ Automatic penalty removal</li>";
echo "<li>✅ Payment history tracking</li>";
echo "<li>✅ Proper penalty calculation for absences</li>";
echo "<li>✅ Student-specific payment access</li>";
echo "</ul>";
echo "</div>";

$conn->close();
?>
