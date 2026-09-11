<?php
/**
 * Add Venue Field and Update Event Types
 */

$conn = new mysqli("localhost","root","","attendance");

if($conn->connect_error){
    die("Connection failed: " . $conn->connect_error);
}

echo "<h2>🏢 Adding Venue Field and Updating Event Types</h2>";

// Step 1: Add venue field
echo "<h3>📍 Adding Venue Field</h3>";
$check_venue = $conn->query("SHOW COLUMNS FROM events LIKE 'venue'");

if($check_venue->num_rows == 0) {
    $result = $conn->query("ALTER TABLE events ADD COLUMN venue VARCHAR(255) AFTER event_description");
    if($result) {
        echo "<p style='color: #51cf66;'>✅ Venue field added successfully!</p>";
    } else {
        echo "<p style='color: #ff6b6b;'>❌ Error adding venue field: " . $conn->error . "</p>";
    }
} else {
    echo "<p style='color: #74c0fc;'>✅ Venue field already exists</p>";
}

// Step 2: Update event_type ENUM to include new options
echo "<h3>🏷️ Updating Event Types</h3>";
$check_event_type = $conn->query("SHOW COLUMNS FROM events LIKE 'event_type'");
$event_type_info = $check_event_type->fetch_assoc();

if($event_type_info) {
    $current_type = $event_type_info['Type'];
    echo "<p>Current event_type: " . htmlspecialchars($current_type) . "</p>";
    
    // Check if new types already exist
    if(strpos($current_type, 'Morning Only') === false || strpos($current_type, 'Afternoon Only') === false) {
        echo "<p>Adding new event types...</p>";
        
        // Modify the ENUM to include new options
        $result = $conn->query("ALTER TABLE events MODIFY COLUMN event_type ENUM('Regular', 'Special', 'Exam', 'Meeting', 'Activity', 'Morning Only', 'Afternoon Only') DEFAULT 'Regular'");
        
        if($result) {
            echo "<p style='color: #51cf66;'>✅ Event types updated successfully!</p>";
        } else {
            echo "<p style='color: #ff6b6b;'>❌ Error updating event types: " . $conn->error . "</p>";
        }
    } else {
        echo "<p style='color: #74c0fc;'>✅ New event types already exist</p>";
    }
} else {
    echo "<p style='color: #ff6b6b;'>❌ event_type column not found</p>";
}

// Step 3: Show updated table structure
echo "<h3>📋 Updated Events Table Structure</h3>";
$result = $conn->query("DESCRIBE events");

echo "<div style='background: rgba(0,0,0,0.8); padding: 20px; border-radius: 15px; color: white;'>";
echo "<table style='width: 100%; border-collapse: collapse;'>";
echo "<tr style='background: rgba(255,215,0,0.2);'>";
echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>Field</th>";
echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>Type</th>";
echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>Default</th>";
echo "</tr>";

while($row = $result->fetch_assoc()){
    $is_new_field = in_array($row['Field'], ['venue', 'event_type']);
    $row_style = $is_new_field ? 'style="background: rgba(255,215,0,0.1);"' : '';
    
    echo "<tr $row_style>";
    echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>" . $row['Field'] . "</td>";
    echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>" . $row['Type'] . "</td>";
    echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>" . $row['Default'] . "</td>";
    echo "</tr>";
}

echo "</table>";
echo "</div>";

// Step 4: Show available event types
echo "<h3>🏷️ Available Event Types</h3>";
echo "<div style='background: rgba(0,0,0,0.8); padding: 20px; border-radius: 15px; color: white;'>";
echo "<div style='display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;'>";

$event_types = ['Regular', 'Special', 'Exam', 'Meeting', 'Activity', 'Morning Only', 'Afternoon Only'];
foreach($event_types as $type) {
    echo "<div style='background: rgba(255,215,0,0.1); padding: 15px; border-radius: 10px; text-align: center; border: 1px solid rgba(255,215,0,0.3);'>";
    echo "<strong style='color: #FFD700;'>" . htmlspecialchars($type) . "</strong>";
    echo "<p style='font-size: 12px; color: rgba(255,255,255,0.7); margin-top: 5px;'>";
    
    switch($type) {
        case 'Regular':
            echo "Full day event";
            break;
        case 'Special':
            echo "Special occasion";
            break;
        case 'Exam':
            echo "Examination period";
            break;
        case 'Meeting':
            echo "Meeting/gathering";
            break;
        case 'Activity':
            echo "School activity";
            break;
        case 'Morning Only':
            echo "Morning session only";
            break;
        case 'Afternoon Only':
            echo "Afternoon session only";
            break;
    }
    echo "</p>";
    echo "</div>";
}

echo "</div>";
echo "</div>";

echo "<div style='margin-top: 30px; text-align: center;'>";
echo "<a href='create_event.php' style='background: linear-gradient(45deg, #FFD700, #FFA500); color: black; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; display: inline-block;'>📅 Create Event with Venue</a>";
echo "<a href='test_enhanced_events.php' style='background: linear-gradient(45deg, #28a745, #20c997); color: white; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; margin-left: 10px; display: inline-block;'>🧪 Test System</a>";
echo "</div>";

echo "<div style='background: rgba(255,193,7,0.1); border: 2px solid rgba(255,215,0,0.3); padding: 20px; border-radius: 15px; margin-top: 20px;'>";
echo "<h3 style='color: #FFD700;'>🏢 New Features Added:</h3>";
echo "<ul style='color: white;'>";
echo "<li>✅ Venue field for event location</li>";
echo "<li>✅ Morning Only event type (morning session only)</li>";
echo "<li>✅ Afternoon Only event type (afternoon session only)</li>";
echo "<li>✅ Enhanced event categorization</li>";
echo "</ul>";
echo "</div>";

$conn->close();
?>
