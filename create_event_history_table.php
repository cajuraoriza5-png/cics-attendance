<?php
/**
 * Create Event History Tracking Table
 * Tracks all changes made to events
 */

$conn = new mysqli("localhost","root","","attendance");

if($conn->connect_error){
    die("Connection failed: " . $conn->connect_error);
}

echo "<h2>📜 Creating Event History Tracking System</h2>";

// Create event_history_log table
$sql = "CREATE TABLE IF NOT EXISTS event_history_log (
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

if($conn->query($sql)) {
    echo "<p style='color: #51cf66;'>✅ Event history log table created successfully!</p>";
} else {
    echo "<p style='color: #ff6b6b;'>❌ Error creating table: " . $conn->error . "</p>";
}

echo "<div style='margin-top: 30px; text-align: center;'>";
echo "<a href='edit_event.php' style='background: linear-gradient(45deg, #FFD700, #FFA500); color: black; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; display: inline-block;'>✏️ Edit Event</a>";
echo "<a href='manage_events.php' style='background: linear-gradient(45deg, #007bff, #0056b3); color: white; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; margin-left: 10px; display: inline-block;'>📅 Manage Events</a>";
echo "</div>";

$conn->close();
?>
