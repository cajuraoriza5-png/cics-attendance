<?php
/**
 * Create Officer Access Log Table
 * Tracks multiple officers scanning events
 */

$conn = new mysqli("localhost","root","","attendance");

if($conn->connect_error){
    die("Connection failed: " . $conn->connect_error);
}

echo "<h2>📱 Creating Officer Access Log Table</h2>";

// Create officer_access_log table
$sql = "CREATE TABLE IF NOT EXISTS officer_access_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    officer_id VARCHAR(255) NOT NULL,
    access_time DATETIME NOT NULL,
    device_info TEXT,
    scans_count INT DEFAULT 1,
    last_scan_time DATETIME,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    UNIQUE KEY unique_officer_event (event_id, officer_id),
    INDEX idx_event_id (event_id),
    INDEX idx_officer_id (officer_id),
    INDEX idx_access_time (access_time)
)";

if($conn->query($sql)) {
    echo "<p style='color: #51cf66;'>✅ Officer access log table created successfully!</p>";
} else {
    echo "<p style='color: #ff6b6b;'>❌ Error creating table: " . $conn->error . "</p>";
}

// Create event_qr_codes directory
if(!is_dir('event_qr_codes')) {
    mkdir('event_qr_codes', 0755, true);
    echo "<p style='color: #51cf66;'>✅ Event QR codes directory created</p>";
} else {
    echo "<p style='color: #74c0fc;'>✅ Event QR codes directory already exists</p>";
}

echo "<div style='margin-top: 30px; text-align: center;'>";
echo "<a href='mobile_scan.php' style='background: linear-gradient(45deg, #28a745, #20c997); color: white; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; display: inline-block;'>📱 Test Mobile Scanner</a>";
echo "<a href='create_event_new.php' style='background: linear-gradient(45deg, #FFD700, #FFA500); color: black; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; margin-left: 10px; display: inline-block;'>📅 Create Event</a>";
echo "</div>";

$conn->close();
?>
