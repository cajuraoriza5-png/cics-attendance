<?php
/**
 * Add Gender Column Structure to Users Table
 */

$conn = new mysqli("localhost","root","","attendance");

if($conn->connect_error){
    die("Connection failed: " . $conn->connect_error);
}

echo "<h2>🔧 Adding Gender Column Structure</h2>";

// Check if gender column exists
$check_column = $conn->query("SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'attendance' AND TABLE_NAME = 'users' AND COLUMN_NAME = 'gender'");

if($check_column->num_rows == 0) {
    // Add gender column with proper structure
    $sql = "ALTER TABLE users ADD COLUMN gender VARCHAR(10) AFTER age";
    
    if($conn->query($sql)) {
        echo "<p>✅ Gender column added successfully!</p>";
        
        // Set default values for existing records
        $conn->query("UPDATE users SET gender = 'Other' WHERE gender IS NULL");
        echo "<p>✅ Default values set for existing records</p>";
        
    } else {
        echo "<p>❌ Error adding gender column: " . $conn->error . "</p>";
    }
} else {
    echo "<p>✅ Gender column already exists</p>";
}

// Show current table structure
echo "<h3>📋 Current Users Table Structure:</h3>";
$result = $conn->query("DESCRIBE users");

echo "<div style='background: rgba(0,0,0,0.8); padding: 20px; border-radius: 15px; color: white;'>";
echo "<table style='width: 100%; border-collapse: collapse;'>";
echo "<tr style='background: rgba(255,215,0,0.2);'>";
echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>Field</th>";
echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>Type</th>";
echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>Null</th>";
echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>Key</th>";
echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>Default</th>";
echo "</tr>";

while($row = $result->fetch_assoc()){
    echo "<tr>";
    echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>" . $row['Field'] . "</td>";
    echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>" . $row['Type'] . "</td>";
    echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>" . $row['Null'] . "</td>";
    echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>" . $row['Key'] . "</td>";
    echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>" . $row['Default'] . "</td>";
    echo "</tr>";
}

echo "</table>";
echo "</div>";

// Test a query to make sure everything works
echo "<h3>🧪 Testing Gender Column</h3>";
$test_query = $conn->query("SELECT username, gender FROM users LIMIT 5");
if($test_query) {
    echo "<p>✅ Gender column is working correctly</p>";
    echo "<div style='background: rgba(0,0,0,0.8); padding: 15px; border-radius: 10px; color: white; margin-top: 10px;'>";
    echo "<table style='width: 100%;'>";
    echo "<tr><th style='padding: 8px;'>Username</th><th style='padding: 8px;'>Gender</th></tr>";
    while($row = $test_query->fetch_assoc()) {
        echo "<tr>";
        echo "<td style='padding: 8px; border: 1px solid rgba(255,215,0,0.1);'>" . $row['username'] . "</td>";
        echo "<td style='padding: 8px; border: 1px solid rgba(255,215,0,0.1);'>" . ($row['gender'] ?? 'Not set') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    echo "</div>";
} else {
    echo "<p>❌ Error testing gender column: " . $conn->error . "</p>";
}

echo "<div style='margin-top: 20px;'>";
echo "<a href='REGISTER.PHP' style='background: linear-gradient(45deg, #28a745, #20c997); color: white; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; margin-right: 10px;'>📝 Test Registration Form</a>";
echo "<a href='student_dashboard.php' style='background: linear-gradient(45deg, #FFD700, #FFA500); color: black; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold;'>👤 Student Dashboard</a>";
echo "</div>";

$conn->close();
?>
