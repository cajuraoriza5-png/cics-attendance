
<?php
/**
 * Update Events Table Structure
 * Adds support for start/end dates, banner photos, event types, and QR codes
 */

$conn = new mysqli("localhost","root","","attendance");

if($conn->connect_error){
    die("Connection failed: " . $conn->connect_error);
}

echo "<h2>🔧 Updating Events Table Structure</h2>";

// Create event_banners directory
echo "<h3>📁 Creating Event Banners Directory</h3>";
if(!is_dir('event_banners')) {
    mkdir('event_banners', 0755, true);
    echo "<p style='color: #51cf66;'>✅ Event banners directory created</p>";
} else {
    echo "<p style='color: #74c0fc;'>✅ Event banners directory already exists</p>";
}

// Add new columns to events table
echo "<h3>🗄️ Adding New Columns to Events Table</h3>";

$new_columns = [
    'event_banner' => "VARCHAR(255) AFTER event_description",
    'event_type' => "ENUM('Regular', 'Special', 'Exam', 'Meeting', 'Activity') DEFAULT 'Regular' AFTER event_banner",
    'start_date' => "DATE AFTER event_type",
    'end_date' => "DATE AFTER start_date",
    'qr_code' => "VARCHAR(255) AFTER end_date",
    'mobile_scan_link' => "VARCHAR(255) AFTER qr_code",
    'event_history' => "TEXT AFTER mobile_scan_link"
];

foreach($new_columns as $column_name => $column_definition) {
    $check_column = $conn->query("SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'attendance' AND TABLE_NAME = 'events' AND COLUMN_NAME = '$column_name'");
    
    if($check_column->num_rows == 0) {
        echo "<p>Adding '$column_name' column...</p>";
        $result = $conn->query("ALTER TABLE events ADD COLUMN $column_name $column_definition");
        if($result) {
            echo "<p style='color: #51cf66;'>✅ $column_name column added successfully!</p>";
        } else {
            echo "<p style='color: #ff6b6b;'>❌ Error adding $column_name column: " . $conn->error . "</p>";
        }
    } else {
        echo "<p style='color: #74c0fc;'>✅ $column_name column already exists</p>";
    }
}

// Show updated table structure
echo "<h3>📋 Updated Events Table Structure:</h3>";
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
    $highlight = in_array($row['Field'], ['event_banner', 'event_type', 'start_date', 'end_date', 'qr_code', 'mobile_scan_link', 'event_history']) ? 'style="background: rgba(255,215,0,0.1);"' : '';
    echo "<tr $highlight>";
    echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>" . $row['Field'] . "</td>";
    echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>" . $row['Type'] . "</td>";
    echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>" . $row['Null'] . "</td>";
    echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>" . $row['Default'] . "</td>";
    echo "</tr>";
}

echo "</table>";
echo "</div>";

// Update existing events to use start_date = event_date and end_date = event_date
echo "<h3>🔄 Updating Existing Events</h3>";
$update_existing = $conn->query("
    UPDATE events 
    SET start_date = event_date, 
        end_date = event_date 
    WHERE start_date IS NULL OR end_date IS NULL
");

if($update_existing) {
    $affected_rows = $conn->affected_rows;
    echo "<p style='color: #51cf66;'>✅ Updated $affected_rows existing events with start/end dates</p>";
} else {
    echo "<p style='color: #ff6b6b;'>❌ Error updating existing events: " . $conn->error . "</p>";
}

// Create event history log table for tracking changes
echo "<h3>📜 Creating Event History Log Table</h3>";
$history_sql = "CREATE TABLE IF NOT EXISTS event_history_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    admin_id INT NOT NULL,
    action_type VARCHAR(50) NOT NULL,
    old_values TEXT,
    new_values TEXT,
    change_description TEXT,
    change_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_event_id (event_id),
    INDEX idx_admin_id (admin_id),
    INDEX idx_change_time (change_time)
)";

if($conn->query($history_sql)) {
    echo "<p style='color: #51cf66;'>✅ Event history log table created successfully!</p>";
} else {
    echo "<p style='color: #ff6b6b;'>❌ Error creating history table: " . $conn->error . "</p>";
}

echo "<div style='margin-top: 30px; text-align: center;'>";
echo "<h3>🚀 Next Steps:</h3>";
echo "<a href='create_event_new.php' style='background: linear-gradient(45deg, #FFD700, #FFA500); color: black; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; margin-right: 10px; display: inline-block;'>📅 Create Event</a>";
echo "<a href='manage_events.php' style='background: linear-gradient(45deg, #007bff, #0056b3); color: white; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; margin-right: 10px; display: inline-block;'>📅 Manage Events</a>";
echo "<a href='admin_dashboard.php' style='background: linear-gradient(45deg, #28a745, #20c997); color: white; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; display: inline-block;'>👨‍💼 Admin Dashboard</a>";
echo "</div>";

echo "<div style='background: rgba(255,193,7,0.1); border: 2px solid rgba(255,215,0,0.3); padding: 20px; border-radius: 15px; margin-top: 20px;'>";
echo "<h3 style='color: #FFD700;'>🔧 New Event Features Added:</h3>";
echo "<ul style='color: white;'>";
echo "<li>✅ Event Banner Photo Upload</li>";
echo "<li>✅ Event Type (Regular, Special, Exam, Meeting, Activity)</li>";
echo "<li>✅ Start Date and End Date (instead of number of days)</li>";
echo "<li>✅ QR Code Generation for Mobile Scanning</li>";
echo "<li>✅ Mobile Scan Link for Officers</li>";
echo "<li>✅ Event History Tracking</li>";
echo "<li>✅ Banner Display in Student Dashboard</li>";
echo "</ul>";
echo "</div>";

$conn->close();
?>
