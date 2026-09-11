<?php
/**
 * Direct SQL Column Addition
 */

$conn = new mysqli("localhost","root","","attendance");

if($conn->connect_error){
    die("Connection failed: " . $conn->connect_error);
}

echo "<h2>🔧 Adding Missing Columns Directly</h2>";

// Check and add each column individually
$columns_to_add = [
    [
        'name' => 'event_banner',
        'sql' => "ALTER TABLE events ADD COLUMN event_banner VARCHAR(255) AFTER event_description"
    ],
    [
        'name' => 'event_type', 
        'sql' => "ALTER TABLE events ADD COLUMN event_type ENUM('Regular', 'Special', 'Exam', 'Meeting', 'Activity') DEFAULT 'Regular' AFTER event_banner"
    ],
    [
        'name' => 'start_date',
        'sql' => "ALTER TABLE events ADD COLUMN start_date DATE AFTER event_type"
    ],
    [
        'name' => 'end_date',
        'sql' => "ALTER TABLE events ADD COLUMN end_date DATE AFTER start_date"
    ],
    [
        'name' => 'qr_code',
        'sql' => "ALTER TABLE events ADD COLUMN qr_code VARCHAR(255) AFTER end_date"
    ],
    [
        'name' => 'mobile_scan_link',
        'sql' => "ALTER TABLE events ADD COLUMN mobile_scan_link VARCHAR(255) AFTER qr_code"
    ],
    [
        'name' => 'event_history',
        'sql' => "ALTER TABLE events ADD COLUMN event_history TEXT AFTER mobile_scan_link"
    ]
];

foreach($columns_to_add as $column) {
    echo "<h3>Adding {$column['name']}...</h3>";
    
    // Check if column exists
    $check = $conn->query("SHOW COLUMNS FROM events LIKE '{$column['name']}'");
    
    if($check->num_rows == 0) {
        // Column doesn't exist, add it
        echo "<p>Column not found, adding...</p>";
        
        if($conn->query($column['sql'])) {
            echo "<p style='color: #51cf66;'>✅ {$column['name']} added successfully!</p>";
        } else {
            echo "<p style='color: #ff6b6b;'>❌ Error adding {$column['name']}: " . $conn->error . "</p>";
        }
    } else {
        echo "<p style='color: #74c0fc;'>✅ {$column['name']} already exists</p>";
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

// Show final table structure
echo "<h3>📋 Final Table Structure</h3>";
$result = $conn->query("DESCRIBE events");

echo "<div style='background: rgba(0,0,0,0.8); padding: 20px; border-radius: 15px; color: white;'>";
echo "<table style='width: 100%; border-collapse: collapse;'>";
echo "<tr style='background: rgba(255,215,0,0.2);'>";
echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>Field</th>";
echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>Type</th>";
echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>Default</th>";
echo "</tr>";

while($row = $result->fetch_assoc()){
    $is_new_column = in_array($row['Field'], ['event_banner', 'event_type', 'start_date', 'end_date', 'qr_code', 'mobile_scan_link', 'event_history']);
    $row_style = $is_new_column ? 'style="background: rgba(255,215,0,0.1);"' : '';
    
    echo "<tr $row_style>";
    echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>" . $row['Field'] . "</td>";
    echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>" . $row['Type'] . "</td>";
    echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>" . $row['Default'] . "</td>";
    echo "</tr>";
}

echo "</table>";
echo "</div>";

echo "<div style='margin-top: 30px; text-align: center;'>";
echo "<a href='test_enhanced_events.php' style='background: linear-gradient(45deg, #28a745, #20c997); color: white; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; display: inline-block;'>🧪 Test Enhanced Events</a>";
echo "<a href='create_event.php' style='background: linear-gradient(45deg, #FFD700, #FFA500); color: black; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; margin-left: 10px; display: inline-block;'>📅 Create Event</a>";
echo "</div>";

$conn->close();
?>
