<?php
/**
 * Add Middle Name and Phone Number Fields to Users Table
 */

$conn = new mysqli("localhost","root","","attendance");

if($conn->connect_error){
    die("Connection failed: " . $conn->connect_error);
}

echo "<h2>🔧 Adding New Fields to Users Table</h2>";

// Check if middle_name column exists
$check_middle_name = $conn->query("SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'attendance' AND TABLE_NAME = 'users' AND COLUMN_NAME = 'middle_name'");
if($check_middle_name->num_rows == 0) {
    echo "<p>Adding 'middle_name' column...</p>";
    $result = $conn->query("ALTER TABLE users ADD COLUMN middle_name VARCHAR(50) AFTER last_name");
    if($result) {
        echo "<p>✅ Middle name column added successfully!</p>";
    } else {
        echo "<p>❌ Error adding middle name column: " . $conn->error . "</p>";
    }
} else {
    echo "<p>✅ Middle name column already exists</p>";
}

// Check if phone column exists
$check_phone = $conn->query("SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'attendance' AND TABLE_NAME = 'users' AND COLUMN_NAME = 'phone'");
if($check_phone->num_rows == 0) {
    echo "<p>Adding 'phone' column...</p>";
    $result = $conn->query("ALTER TABLE users ADD COLUMN phone VARCHAR(15) AFTER email");
    if($result) {
        echo "<p>✅ Phone column added successfully!</p>";
    } else {
        echo "<p>❌ Error adding phone column: " . $conn->error . "</p>";
    }
} else {
    echo "<p>✅ Phone column already exists</p>";
}

// Show current table structure
echo "<h3>📋 Updated Users Table Structure:</h3>";
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

echo "<p><a href='REGISTER.PHP'>Go to Registration Form</a></p>";

$conn->close();
?>
