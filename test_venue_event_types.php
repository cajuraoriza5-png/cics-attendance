<?php
/**
 * Test Venue and New Event Types
 * Verifies venue field and Morning Only/Afternoon Only event types
 */

$conn = new mysqli("localhost","root","","attendance");

if($conn->connect_error){
    die("Connection failed: " . $conn->connect_error);
}

echo "<h2>🏢 Testing Venue and New Event Types</h2>";

// Test 1: Check venue field
echo "<h3>📍 Test 1: Venue Field Check</h3>";
$check_venue = $conn->query("SHOW COLUMNS FROM events LIKE 'venue'");

if($check_venue->num_rows > 0) {
    echo "<p style='color: #51cf66;'>✅ Venue field exists</p>";
    $venue_info = $check_venue->fetch_assoc();
    echo "<p>Type: " . htmlspecialchars($venue_info['Type']) . "</p>";
} else {
    echo "<p style='color: #ff6b6b;'>❌ Venue field missing</p>";
}

// Test 2: Check updated event types
echo "<h3>🏷️ Test 2: Event Types Check</h3>";
$check_event_type = $conn->query("SHOW COLUMNS FROM events LIKE 'event_type'");
$event_type_info = $check_event_type->fetch_assoc();

if($event_type_info) {
    $current_type = $event_type_info['Type'];
    echo "<p>Current event_type: " . htmlspecialchars($current_type) . "</p>";
    
    if(strpos($current_type, 'Morning Only') !== false && strpos($current_type, 'Afternoon Only') !== false) {
        echo "<p style='color: #51cf66;'>✅ New event types (Morning Only, Afternoon Only) added successfully!</p>";
    } else {
        echo "<p style='color: #ff6b6b;'>❌ New event types missing</p>";
    }
} else {
    echo "<p style='color: #ff6b6b;'>❌ event_type column not found</p>";
}

// Test 3: Show available event types
echo "<h3>📋 Available Event Types</h3>";
$event_types = ['Regular', 'Special', 'Exam', 'Meeting', 'Activity', 'Morning Only', 'Afternoon Only'];

echo "<div style='background: rgba(0,0,0,0.8); padding: 20px; border-radius: 15px; color: white;'>";
echo "<div style='display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;'>";

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

// Test 4: Check existing events with venue
echo "<h3>📊 Test 4: Existing Events with Venue</h3>";
$events_query = $conn->query("SELECT event_name, event_type, venue, start_date, end_date FROM events ORDER BY created_at DESC LIMIT 5");

if($events_query->num_rows > 0) {
    echo "<div style='background: rgba(0,0,0,0.8); padding: 20px; border-radius: 15px; color: white; margin-bottom: 20px;'>";
    echo "<table style='width: 100%; border-collapse: collapse;'>";
    echo "<tr style='background: rgba(255,215,0,0.2);'>";
    echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>Event</th>";
    echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>Type</th>";
    echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>Venue</th>";
    echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>Date Range</th>";
    echo "</tr>";
    
    while($event = $events_query->fetch_assoc()){
        echo "<tr>";
        echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>" . htmlspecialchars($event['event_name']) . "</td>";
        echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>" . htmlspecialchars($event['event_type'] ?? 'N/A') . "</td>";
        echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>" . ($event['venue'] ? htmlspecialchars($event['venue']) : '<span style="color: #74c0fc;">Not set</span>') . "</td>";
        echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>";
        if($event['start_date'] && $event['end_date']) {
            $start = new DateTime($event['start_date']);
            $end = new DateTime($event['end_date']);
            echo $start->format('M d') . ' - ' . $end->format('M d, Y');
        } else {
            echo 'N/A';
        }
        echo "</td>";
        echo "</tr>";
    }
    echo "</table>";
    echo "</div>";
} else {
    echo "<p style='color: #74c0fc;'>ℹ️ No events found in database</p>";
}

// Test 5: Create sample events with venue and new types
echo "<h3>🎯 Test 5: Create Sample Events</h3>";
if(!isset($_GET['create_samples'])) {
    echo "<p><a href='?create_samples=1' style='background: linear-gradient(45deg, #28a745, #20c997); color: white; padding: 10px 20px; text-decoration: none; border-radius: 20px; font-weight: bold;'>Create Sample Events</a></p>";
} else {
    // Sample events with venue and new types
    $sample_events = [
        [
            'event_name' => 'Morning Assembly',
            'event_description' => 'Regular morning assembly for all students',
            'event_type' => 'Morning Only',
            'venue' => 'School Auditorium',
            'start_date' => date('Y-m-d'),
            'end_date' => date('Y-m-d'),
            'course' => 'ALL',
            'late_penalty' => 25,
            'absent_penalty' => 50,
            'event_history' => 'Sample morning-only event with venue',
            'mobile_scan_link' => 'mobile_scan.php?event_id=' . uniqid(),
            'qr_code' => 'qr_morning_' . time() . '.png'
        ],
        [
            'event_name' => 'Afternoon Workshop',
            'event_description' => 'Technical workshop for CS and IT students',
            'event_type' => 'Afternoon Only',
            'venue' => 'Computer Lab 201',
            'start_date' => date('Y-m-d', strtotime('+1 day')),
            'end_date' => date('Y-m-d', strtotime('+1 day')),
            'course' => 'BSCS,BSIT',
            'late_penalty' => 25,
            'absent_penalty' => 50,
            'event_history' => 'Sample afternoon-only event with venue',
            'mobile_scan_link' => 'mobile_scan.php?event_id=' . uniqid(),
            'qr_code' => 'qr_afternoon_' . time() . '.png'
        ],
        [
            'event_name' => 'Special Guest Lecture',
            'event_description' => 'Guest lecture on emerging technologies',
            'event_type' => 'Special',
            'venue' => 'Main Conference Hall',
            'start_date' => date('Y-m-d', strtotime('+2 days')),
            'end_date' => date('Y-m-d', strtotime('+2 days')),
            'course' => 'ALL',
            'late_penalty' => 25,
            'absent_penalty' => 50,
            'event_history' => 'Sample special event with venue',
            'mobile_scan_link' => 'mobile_scan.php?event_id=' . uniqid(),
            'qr_code' => 'qr_special_' . time() . '.png'
        ]
    ];
    
    $created_count = 0;
    foreach($sample_events as $event) {
        $stmt = $conn->prepare("
            INSERT INTO events(
                event_name, event_description, event_date, course,
                event_type, venue, start_date, end_date, event_history,
                qr_code, mobile_scan_link,
                late_penalty, absent_penalty,
                morning_login_start, morning_login_end,
                morning_logout_start, morning_logout_end,
                afternoon_login_start, afternoon_login_end,
                afternoon_logout_start, afternoon_logout_end,
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        // Store time values in variables
        $morning_login_start = '07:00:00';
        $morning_login_end = '07:30:00';
        $morning_logout_start = '11:30:00';
        $morning_logout_end = '12:00:00';
        $afternoon_login_start = '13:00:00';
        $afternoon_login_end = '13:30:00';
        $afternoon_logout_start = '16:30:00';
        $afternoon_logout_end = '17:00:00';
        
        $stmt->bind_param(
            "sssssssssssiiisssssss",
            $event['event_name'],
            $event['event_description'],
            $event['start_date'],
            $event['course'],
            $event['event_type'],
            $event['venue'],
            $event['start_date'],
            $event['end_date'],
            $event['event_history'],
            $event['qr_code'],
            $event['mobile_scan_link'],
            $event['late_penalty'],
            $event['absent_penalty'],
            $morning_login_start,
            $morning_login_end,
            $morning_logout_start,
            $morning_logout_end,
            $afternoon_login_start,
            $afternoon_login_end,
            $afternoon_logout_start,
            $afternoon_logout_end
        );
        
        if($stmt->execute()) {
            $created_count++;
        }
    }
    
    if($created_count > 0) {
        echo "<p style='color: #51cf66;'>✅ Created $created_count sample events with venues and new types!</p>";
    } else {
        echo "<p style='color: #ff6b6b;'>❌ Error creating sample events</p>";
    }
}

echo "<div style='margin-top: 30px;'>";
echo "<h3>🚀 Next Steps:</h3>";
echo "<a href='add_venue_field.php' style='background: linear-gradient(45deg, #FFD700, #FFA500); color: black; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; margin-right: 10px; display: inline-block;'>🏢 Add Venue Field</a>";
echo "<a href='create_event.php' style='background: linear-gradient(45deg, #28a745, #20c997); color: white; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; margin-right: 10px; display: inline-block;'>📅 Create Event</a>";
echo "<a href='student_dashboard.php' style='background: linear-gradient(45deg, #007bff, #0056b3); color: white; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; display: inline-block;'>👤 Student Dashboard</a>";
echo "</div>";

echo "<div style='background: rgba(255,193,7,0.1); border: 2px solid rgba(255,215,0,0.3); padding: 20px; border-radius: 15px; margin-top: 20px;'>";
echo "<h3 style='color: #FFD700;'>🏢 New Features Added:</h3>";
echo "<ul style='color: white;'>";
echo "<li>✅ Venue field for event location (Room 201, Auditorium, Gym, etc.)</li>";
echo "<li>✅ Morning Only event type (hides afternoon time fields)</li>";
echo "<li>✅ Afternoon Only event type (hides morning time fields)</li>";
echo "<li>✅ Enhanced event categorization</li>";
echo "<li>✅ Venue display in student dashboard</li>";
echo "<li>✅ Venue display in mobile scanning</li>";
echo "<li>✅ Smart form field toggling based on event type</li>";
echo "</ul>";
echo "</div>";

$conn->close();
?>
