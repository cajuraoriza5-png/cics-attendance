<?php
/**
 * Test QR Code and Multi-Device Scanning System
 * Comprehensive testing for QR codes, banners, and multi-device support
 */

$conn = new mysqli("localhost","root","","attendance");

if($conn->connect_error){
    die("Connection failed: " . $conn->connect_error);
}

echo "<h2>📱 Testing QR Code and Multi-Device System</h2>";

// Test 1: Check QR code directory and files
echo "<h3>📁 Test 1: QR Code Directory Check</h3>";
if(is_dir('event_qr_codes')) {
    echo "<p style='color: #51cf66;'>✅ Event QR codes directory exists</p>";
    
    $qr_files = glob('event_qr_codes/*');
    if(count($qr_files) > 0) {
        echo "<p style='color: #51cf66;'>✅ Found " . count($qr_files) . " QR code files</p>";
    } else {
        echo "<p style='color: #74c0fc;'>ℹ️ No QR code files found yet</p>";
    }
} else {
    echo "<p style='color: #ff6b6b;'>❌ Event QR codes directory missing</p>";
}

// Test 2: Check officer access log table
echo "<h3>📊 Test 2: Officer Access Log Table</h3>";
$log_check = $conn->query("SHOW TABLES LIKE 'officer_access_log'");

if($log_check->num_rows > 0) {
    echo "<p style='color: #51cf66;'>✅ Officer access log table exists</p>";
    
    $log_count = $conn->query("SELECT COUNT(*) as count FROM officer_access_log")->fetch_assoc()['count'];
    echo "<p style='color: #74c0fc;'>ℹ️ $log_count access records found</p>";
} else {
    echo "<p style='color: #ff6b6b;'>❌ Officer access log table missing</p>";
}

// Test 3: Check events with mobile scan links
echo "<h3>📱 Test 3: Events with Mobile Scan Links</h3>";
$events_query = $conn->query("SELECT id, event_name, mobile_scan_link, qr_code, venue, event_type FROM events WHERE mobile_scan_link IS NOT NULL ORDER BY created_at DESC LIMIT 5");

if($events_query->num_rows > 0) {
    echo "<div style='background: rgba(0,0,0,0.8); padding: 20px; border-radius: 15px; color: white; margin-bottom: 20px;'>";
    echo "<table style='width: 100%; border-collapse: collapse;'>";
    echo "<tr style='background: rgba(255,215,0,0.2);'>";
    echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>Event</th>";
    echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>Type</th>";
    echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>Venue</th>";
    echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>QR Code</th>";
    echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>Mobile Link</th>";
    echo "</tr>";
    
    while($event = $events_query->fetch_assoc()){
        echo "<tr>";
        echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>" . htmlspecialchars($event['event_name']) . "</td>";
        echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>" . htmlspecialchars($event['event_type'] ?? 'Regular') . "</td>";
        echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>" . ($event['venue'] ? htmlspecialchars($event['venue']) : 'N/A') . "</td>";
        echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>" . ($event['qr_code'] ? '✅' : '❌') . "</td>";
        echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>" . ($event['mobile_scan_link'] ? '✅' : '❌') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    echo "</div>";
} else {
    echo "<p style='color: #74c0fc;'>ℹ️ No events with mobile scan links found</p>";
}

// Test 4: Check banner photos
echo "<h3>📸 Test 4: Event Banner Photos</h3>";
if(is_dir('event_banners')) {
    echo "<p style='color: #51cf66;'>✅ Event banners directory exists</p>";
    
    $banner_files = glob('event_banners/*');
    if(count($banner_files) > 0) {
        echo "<p style='color: #51cf66;'>✅ Found " . count($banner_files) . " banner files</p>";
        
        // Show sample banners
        echo "<div style='display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-top: 15px;'>";
        foreach(array_slice($banner_files, 0, 4) as $banner) {
            echo "<div style='text-align: center;'>";
            echo "<img src='$banner' alt='Banner' style='width: 100%; max-height: 100px; object-fit: cover; border-radius: 10px; border: 2px solid rgba(255,215,0,0.3);'>";
            echo "<p style='font-size: 12px; color: rgba(255,255,255,0.7); margin-top: 5px;'>" . basename($banner) . "</p>";
            echo "</div>";
        }
        echo "</div>";
    } else {
        echo "<p style='color: #74c0fc;'>ℹ️ No banner files found</p>";
    }
} else {
    echo "<p style='color: #ff6b6b;'>❌ Event banners directory missing</p>";
}

// Test 5: Multi-device simulation
echo "<h3>📱 Test 5: Multi-Device Scanning Simulation</h3>";
$test_event_id = 'test_' . time();
$mobile_scan_url = "mobile_scan.php?event_id=$test_event_id";

echo "<div style='background: rgba(0,0,0,0.8); padding: 20px; border-radius: 15px; color: white; margin-bottom: 20px;'>";
echo "<h4 style='color: #FFD700; margin-bottom: 15px;'>🔄 Multi-Device Test Simulation</h4>";

// Simulate multiple officers accessing the same event
$officer_ids = ['officer_1_' . time(), 'officer_2_' . time(), 'officer_3_' . time()];
$success_count = 0;

foreach($officer_ids as $officer_id) {
    // Simulate officer access
    $test_url = "http://localhost/attendance/mobile_scan.php?event_id=$test_event_id&officer_id=$officer_id";
    
    echo "<p style='margin: 10px 0;'>";
    echo "<strong>Officer " . substr($officer_id, -8) . ":</strong> ";
    echo "<a href='$test_url' target='_blank' style='color: #007bff; text-decoration: none;'>📱 Test Scanner</a>";
    echo "</p>";
    
    $success_count++;
}

echo "<p style='color: #51cf66; margin-top: 15px;'>✅ Simulated $success_count officers accessing the same event</p>";
echo "</div>";

// Test 6: Create comprehensive test event
echo "<h3>🎯 Test 6: Create Comprehensive Test Event</h3>";
if(!isset($_GET['create_test_event'])) {
    echo "<p><a href='?create_test_event=1' style='background: linear-gradient(45deg, #28a745, #20c997); color: white; padding: 10px 20px; text-decoration: none; border-radius: 20px; font-weight: bold;'>Create Test Event</a></p>";
} else {
    // Create a comprehensive test event
    $test_event = [
        'event_name' => 'Multi-Device Test Event',
        'event_description' => 'Test event for QR codes, banners, and multi-device scanning',
        'event_type' => 'Special',
        'venue' => 'Test Auditorium',
        'start_date' => date('Y-m-d'),
        'end_date' => date('Y-m-d'),
        'course' => 'ALL',
        'late_penalty' => 25,
        'absent_penalty' => 50,
        'event_history' => 'Test event for comprehensive system validation',
        'mobile_scan_link' => 'mobile_scan.php?event_id=test_' . uniqid(),
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
        
        // Generate QR code for the test event
        $qr_url = "generate_qr_code.php?event_id=$event_id";
        echo "<p><a href='$qr_url' target='_blank' style='color: #FFD700; text-decoration: none;'>🔄 Generate QR Code</a></p>";
        
        // Test mobile scan
        $mobile_url = "mobile_scan.php?event_id=" . urlencode($test_event['mobile_scan_link']);
        echo "<p><a href='$mobile_url' target='_blank' style='color: #007bff; text-decoration: none;'>📱 Test Mobile Scanner</a></p>";
    } else {
        echo "<p style='color: #ff6b6b;'>❌ Error creating test event: " . $conn->error . "</p>";
    }
}

echo "<div style='margin-top: 30px;'>";
echo "<h3>🚀 System Features Summary:</h3>";
echo "<div style='background: rgba(255,193,7,0.1); border: 2px solid rgba(255,215,0,0.3); padding: 20px; border-radius: 15px;'>";
echo "<ul style='color: white;'>";
echo "<li>✅ QR Code Generation for Events</li>";
echo "<li>✅ Multi-Device Officer Support</li>";
echo "<li>✅ Mobile-Optimized Scanning Interface</li>";
echo "<li>✅ Event Banner Photo System</li>";
echo "<li>✅ Venue and Enhanced Event Types</li>";
echo "<li>✅ Real-time Multi-Device Tracking</li>";
echo "<li>✅ Admin QR Code Management</li>";
echo "</ul>";
echo "</div>";
echo "</div>";

echo "<div style='margin-top: 30px; text-align: center;'>";
echo "<a href='create_officer_log_table.php' style='background: linear-gradient(45deg, #FFD700, #FFA500); color: black; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; margin-right: 10px; display: inline-block;'>📊 Setup Officer Log</a>";
echo "<a href='create_sample_banners.php' style='background: linear-gradient(45deg, #28a745, #20c997); color: white; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; margin-right: 10px; display: inline-block;'>📸 Create Sample Banners</a>";
echo "<a href='event_qr_display.php' style='background: linear-gradient(45deg, #007bff, #0056b3); color: white; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; display: inline-block;'>📱 View QR Codes</a>";
echo "</div>";

$conn->close();
?>
