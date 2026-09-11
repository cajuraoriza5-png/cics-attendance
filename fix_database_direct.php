<?php
/**
 * Fix Database - Direct Column Addition
 */

$conn = new mysqli("localhost","root","","attendance");

if($conn->connect_error){
    die("Connection failed: " . $conn->connect_error);
}

echo "<h2>🔧 Fixing Database Columns</h2>";

// Direct SQL statements to add columns
$alter_statements = [
    "ALTER TABLE events ADD COLUMN event_banner VARCHAR(255) AFTER event_description",
    "ALTER TABLE events ADD COLUMN event_type ENUM('Regular', 'Special', 'Exam', 'Meeting', 'Activity') DEFAULT 'Regular' AFTER event_banner",
    "ALTER TABLE events ADD COLUMN start_date DATE AFTER event_type",
    "ALTER TABLE events ADD COLUMN end_date DATE AFTER start_date",
    "ALTER TABLE events ADD COLUMN qr_code VARCHAR(255) AFTER end_date",
    "ALTER TABLE events ADD COLUMN mobile_scan_link VARCHAR(255) AFTER qr_code",
    "ALTER TABLE events ADD COLUMN event_history TEXT AFTER mobile_scan_link"
];

foreach($alter_statements as $sql) {
    echo "<p>Executing: " . htmlspecialchars($sql) . "</p>";
    
    try {
        $result = $conn->query($sql);
        if($result) {
            echo "<p style='color: #51cf66;'>✅ Success!</p>";
        } else {
            echo "<p style='color: #74c0fc;'>ℹ️ Column may already exist or error: " . $conn->error . "</p>";
        }
    } catch(Exception $e) {
        echo "<p style='color: #74c0fc;'>ℹ️ Exception: " . $e->getMessage() . "</p>";
    }
}

// Create event_banners directory
echo "<h3>📁 Creating Event Banners Directory</h3>";
if(!is_dir('event_banners')) {
    mkdir('event_banners', 0755, true);
    echo "<p style='color: #51cf66;'>✅ Event banners directory created</p>";
} else {
    echo "<p style='color: #74c0fc;'>✅ Event banners directory already exists</p>";
}

// Verify columns exist
echo "<h3>🔍 Verifying Columns</h3>";
$check_columns = ['event_banner', 'event_type', 'start_date', 'end_date', 'qr_code', 'mobile_scan_link', 'event_history'];

foreach($check_columns as $column) {
    $result = $conn->query("SHOW COLUMNS FROM events LIKE '$column'");
    if($result->num_rows > 0) {
        echo "<p style='color: #51cf66;'>✅ $column exists</p>";
    } else {
        echo "<p style='color: #ff6b6b;'>❌ $column missing</p>";
    }
}

// Show table structure
echo "<h3>📋 Current Events Table Structure</h3>";
$result = $conn->query("DESCRIBE events");

echo "<div style='background: rgba(0,0,0,0.8); padding: 20px; border-radius: 15px; color: white;'>";
echo "<table style='width: 100%; border-collapse: collapse;'>";
echo "<tr style='background: rgba(255,215,0,0.2);'>";
echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>Field</th>";
echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>Type</th>";
echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>Null</th>";
echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>Default</th>";
echo "</tr>";

while($row = $result->fetch_assoc()){
    echo "<tr>";
    echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>" . $row['Field'] . "</td>";
    echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>" . $row['Type'] . "</td>";
    echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>" . $row['Null'] . "</td>";
    echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>" . $row['Default'] . "</td>";
    echo "</tr>";
}

echo "</table>";
echo "</div>";

echo "<div style='margin-top: 30px;'>";
echo "<a href='test_enhanced_events.php' style='background: linear-gradient(45deg, #28a745, #20c997); color: white; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; display: inline-block;'>🧪 Test System</a>";
echo "<a href='create_event.php' style='background: linear-gradient(45deg, #FFD700, #FFA500); color: black; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; margin-left: 10px; display: inline-block;'>📅 Create Event</a>";
echo "</div>";

$conn->close();
?>
