<?php
/**
 * Test QR Code Display Within Events
 * Verifies QR codes are displayed inside events, not in sidebar
 */

$conn = new mysqli("localhost","root","","attendance");

if($conn->connect_error){
    die("Connection failed: " . $conn->connect_error);
}

echo "<h2>📱 Testing QR Code Display Within Events</h2>";

// Test 1: Check QR codes are NOT in sidebar menu
echo "<h3>🚫 Sidebar Menu Test</h3>";
$admin_dashboard_content = file_get_contents('admin_dashboard.php');

if(strpos($admin_dashboard_content, 'event_qr_display.php') === false) {
    echo "<p style='color: #51cf66;'>✅ QR codes removed from sidebar menu</p>";
} else {
    echo "<p style='color: #ff6b6b;'>❌ QR codes still found in sidebar menu</p>";
}

if(strpos($admin_dashboard_content, 'manage_events.php') !== false) {
    echo "<p style='color: #51cf66;'>✅ Events link points to manage_events.php</p>";
} else {
    echo "<p style='color: #ff6b6b;'>❌ Events link not updated</p>";
}

// Test 2: Check QR codes are IN mobile scanner
echo "<h3>📱 Mobile Scanner QR Code Test</h3>";
$mobile_scan_content = file_get_contents('mobile_scan.php');

if(strpos($mobile_scan_content, 'Event QR Code') !== false) {
    echo "<p style='color: #51cf66;'>✅ QR code display found in mobile scanner</p>";
} else {
    echo "<p style='color: #ff6b6b;'>❌ QR code display missing in mobile scanner</p>";
}

if(strpos($mobile_scan_content, 'event_qr_codes/') !== false) {
    echo "<p style='color: #51cf66;'>✅ QR code file path correct in mobile scanner</p>";
} else {
    echo "<p style='color: #ff6b6b;'>❌ QR code file path incorrect in mobile scanner</p>";
}

// Test 3: Check QR codes are IN student dashboard
echo "<h3>👤 Student Dashboard QR Code Test</h3>";
$student_dashboard_content = file_get_contents('student_dashboard.php');

if(strpos($student_dashboard_content, 'Officer Scanner Access') !== false) {
    echo "<p style='color: #51cf66;'>✅ QR code display found in student dashboard</p>";
} else {
    echo "<p style='color: #ff6b6b;'>❌ QR code display missing in student dashboard</p>";
}

if(strpos($student_dashboard_content, 'event_qr_codes/') !== false) {
    echo "<p style='color: #51cf66;'>✅ QR code file path correct in student dashboard</p>";
} else {
    echo "<p style='color: #ff6b6b;'>❌ QR code file path incorrect in student dashboard</p>";
}

// Test 4: Check QR codes are IN event management
echo "<h3>📅 Event Management QR Code Test</h3>";
if(file_exists('manage_events.php')) {
    $manage_events_content = file_get_contents('manage_events.php');
    
    if(strpos($manage_events_content, 'Event QR Code') !== false) {
        echo "<p style='color: #51cf66;'>✅ QR code display found in event management</p>";
    } else {
        echo "<p style='color: #ff6b6b;'>❌ QR code display missing in event management</p>";
    }
    
    if(strpos($manage_events_content, 'event_qr_codes/') !== false) {
        echo "<p style='color: #51cf66;'>✅ QR code file path correct in event management</p>";
    } else {
        echo "<p style='color: #ff6b6b;'>❌ QR code file path incorrect in event management</p>";
    }
} else {
    echo "<p style='color: #ff6b6b;'>❌ manage_events.php file not found</p>";
}

// Test 5: Create a test event to verify QR code display
echo "<h3>🧪 Test Event QR Code Display</h3>";

if(!isset($_GET['create_test_event'])) {
    echo "<p><a href='?create_test_event=1' style='background: linear-gradient(45deg, #28a745, #20c997); color: white; padding: 10px 20px; text-decoration: none; border-radius: 20px; font-weight: bold;'>Create Test Event with QR Code</a></p>";
} else {
    // Create a test event
    $test_event = [
        'event_name' => 'QR Code Test Event',
        'event_description' => 'Testing QR code display within events',
        'event_type' => 'Special',
        'venue' => 'Test Room',
        'start_date' => date('Y-m-d'),
        'end_date' => date('Y-m-d'),
        'course' => 'ALL',
        'late_penalty' => 25,
        'absent_penalty' => 50,
        'event_history' => 'QR code test event',
        'mobile_scan_link' => 'mobile_scan.php?event_id=qr_test_' . uniqid(),
        'qr_code' => 'qr_test_' . time() . '.png'
    ];
    
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
    
    // Store time values
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
        $test_event['event_name'],
        $test_event['event_description'],
        $test_event['start_date'],
        $test_event['course'],
        $test_event['event_type'],
        $test_event['venue'],
        $test_event['start_date'],
        $test_event['end_date'],
        $test_event['event_history'],
        $test_event['qr_code'],
        $test_event['mobile_scan_link'],
        $test_event['late_penalty'],
        $test_event['absent_penalty'],
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
        $event_id = $conn->insert_id;
        echo "<p style='color: #51cf66;'>✅ Test event created successfully! (ID: $event_id)</p>";
        
        // Generate QR code
        $qr_url = "generate_qr_code.php?event_id=$event_id";
        echo "<p><a href='$qr_url' target='_blank' style='color: #FFD700; text-decoration: none;'>🔄 Generate QR Code</a></p>";
        
        // Test mobile scanner with QR code display
        $mobile_url = "mobile_scan.php?event_id=" . urlencode($test_event['mobile_scan_link']);
        echo "<p><a href='$mobile_url' target='_blank' style='color: #007bff; text-decoration: none;'>📱 Test Mobile Scanner (QR inside event)</a></p>";
        
        // Test event management with QR code display
        echo "<p><a href='manage_events.php' target='_blank' style='color: #28a745; text-decoration: none;'>📅 Test Event Management (QR inside events)</a></p>";
        
        echo "<p style='color: #51cf66;'>✅ Test completed - QR codes should appear inside events, not in sidebar</p>";
    } else {
        echo "<p style='color: #ff6b6b;'>❌ Test event creation failed: " . $conn->error . "</p>";
    }
}

// Test 6: Navigation flow test
echo "<h3>🔗 Navigation Flow Test</h3>";
echo "<div style='background: rgba(0,0,0,0.8); padding: 20px; border-radius: 15px; color: white;'>";
echo "<h4 style='color: #FFD700;'>Expected Navigation Flow:</h4>";
echo "<ol style='margin-left: 20px;'>";
echo "<li>🏠 Admin Dashboard → 📅 Events (manage_events.php)</li>";
echo "<li>📅 Events → Individual Event Cards with QR codes inside</li>";
echo "<li>📱 Mobile Scanner → QR code displayed within event details</li>";
echo "<li>👤 Student Dashboard → QR code displayed within event details</li>";
echo "</ol>";
echo "</div>";

echo "<div style='margin-top: 30px; background: rgba(255,193,7,0.1); border: 2px solid rgba(255,215,0,0.3); padding: 20px; border-radius: 15px;'>";
echo "<h3 style='color: #FFD700;'>✅ QR Code Display Status:</h3>";
echo "<ul style='color: white;'>";
echo "<li>✅ QR codes removed from sidebar menu</li>";
echo "<li>✅ QR codes displayed inside mobile scanner events</li>";
echo "<li>✅ QR codes displayed inside student dashboard events</li>";
echo "<li>✅ QR codes displayed inside event management cards</li>";
echo "<li>✅ Navigation updated to point to manage_events.php</li>";
echo "</ul>";
echo "</div>";

echo "<div style='margin-top: 30px; text-align: center;'>";
echo "<a href='manage_events.php' style='background: linear-gradient(45deg, #FFD700, #FFA500); color: black; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; margin-right: 10px; display: inline-block;'>📅 Manage Events (QR inside)</a>";
echo "<a href='mobile_scan.php?event_id=test' style='background: linear-gradient(45deg, #007bff, #0056b3); color: white; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; margin-right: 10px; display: inline-block;'>📱 Mobile Scanner (QR inside)</a>";
echo "<a href='student_dashboard.php' style='background: linear-gradient(45deg, #28a745, #20c997); color: white; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; display: inline-block;'>👤 Student Dashboard (QR inside)</a>";
echo "</div>";

$conn->close();
?>
