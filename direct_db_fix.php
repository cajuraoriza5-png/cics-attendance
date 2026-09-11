<?php
/**
 * Direct Database Fix - No phpMyAdmin needed
 */

echo "<h2>🔧 Direct Database Column Fix</h2>";

// Try different MySQL connection methods
$connections = [
    ['host' => 'localhost', 'user' => 'root', 'pass' => '', 'db' => 'attendance'],
    ['host' => '127.0.0.1', 'user' => 'root', 'pass' => '', 'db' => 'attendance'],
    ['host' => 'localhost', 'user' => 'root', 'pass' => 'root', 'db' => 'attendance'],
    ['host' => 'localhost', 'user' => 'root', 'pass' => 'password', 'db' => 'attendance']
];

$conn = null;
foreach($connections as $config) {
    try {
        $conn = new mysqli($config['host'], $config['user'], $config['pass'], $config['db']);
        if($conn->connect_error) {
            echo "<p style='color: #74c0fc;'>Trying {$config['host']} with user {$config['user']}...</p>";
            continue;
        } else {
            echo "<p style='color: #51cf66;'>✅ Connected successfully to {$config['host']} as {$config['user']}</p>";
            break;
        }
    } catch(Exception $e) {
        echo "<p style='color: #74c0fc;'>Failed connection attempt...</p>";
        continue;
    }
}

if(!$conn || $conn->connect_error) {
    die("<p style='color: #ff6b6b;'>❌ Could not connect to MySQL. Please start MySQL service in XAMPP.</p>");
}

// Direct column addition
$sql_commands = [
    "ALTER TABLE events ADD COLUMN IF NOT EXISTS event_banner VARCHAR(255) AFTER event_description",
    "ALTER TABLE events ADD COLUMN IF NOT EXISTS event_type ENUM('Regular', 'Special', 'Exam', 'Meeting', 'Activity') DEFAULT 'Regular' AFTER event_banner",
    "ALTER TABLE events ADD COLUMN IF NOT EXISTS start_date DATE AFTER event_type",
    "ALTER TABLE events ADD COLUMN IF NOT EXISTS end_date DATE AFTER start_date",
    "ALTER TABLE events ADD COLUMN IF NOT EXISTS qr_code VARCHAR(255) AFTER end_date",
    "ALTER TABLE events ADD COLUMN IF NOT EXISTS mobile_scan_link VARCHAR(255) AFTER qr_code",
    "ALTER TABLE events ADD COLUMN IF NOT EXISTS event_history TEXT AFTER mobile_scan_link",
    "UPDATE events SET start_date = event_date, end_date = event_date WHERE start_date IS NULL OR end_date IS NULL"
];

echo "<h3>🗄️ Adding Columns</h3>";

foreach($sql_commands as $sql) {
    echo "<p style='color: #74c0fc;'>Executing: " . htmlspecialchars(substr($sql, 0, 50)) . "...</p>";
    
    if($conn->query($sql)) {
        echo "<p style='color: #51cf66;'>✅ Success!</p>";
    } else {
        echo "<p style='color: #74c0fc;'>ℹ️ " . $conn->error . "</p>";
    }
}

// Create directory
echo "<h3>📁 Creating Event Banners Directory</h3>";
if(!is_dir('event_banners')) {
    mkdir('event_banners', 0755, true);
    echo "<p style='color: #51cf66;'>✅ Event banners directory created</p>";
} else {
    echo "<p style='color: #74c0fc;'>✅ Event banners directory exists</p>";
}

// Verify
echo "<h3>🔍 Verification</h3>";
$result = $conn->query("DESCRIBE events");
$columns = [];
while($row = $result->fetch_assoc()) {
    $columns[] = $row['Field'];
}

$required_columns = ['event_banner', 'event_type', 'start_date', 'end_date', 'qr_code', 'mobile_scan_link', 'event_history'];
$all_exist = true;

foreach($required_columns as $col) {
    if(in_array($col, $columns)) {
        echo "<p style='color: #51cf66;'>✅ $col exists</p>";
    } else {
        echo "<p style='color: #ff6b6b;'>❌ $col missing</p>";
        $all_exist = false;
    }
}

if($all_exist) {
    echo "<div style='background: rgba(40,167,69,0.1); border: 2px solid rgba(40,167,69,0.3); padding: 20px; border-radius: 15px; margin: 20px 0;'>";
    echo "<h3 style='color: #28a745;'>🎉 Database Fixed Successfully!</h3>";
    echo "<p style='color: white;'>All enhanced event system columns are now available.</p>";
    echo "</div>";
    
    echo "<div style='margin-top: 30px; text-align: center;'>";
    echo "<a href='test_enhanced_events.php' style='background: linear-gradient(45deg, #28a745, #20c997); color: white; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; display: inline-block;'>🧪 Test System</a>";
    echo "<a href='create_event.php' style='background: linear-gradient(45deg, #FFD700, #FFA500); color: black; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; margin-left: 10px; display: inline-block;'>📅 Create Event</a>";
    echo "</div>";
}

$conn->close();
?>
