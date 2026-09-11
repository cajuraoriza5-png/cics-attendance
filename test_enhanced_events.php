<?php
/**
 * Test Enhanced Event System
 * Verifies all new features: banners, start/end dates, event types, mobile scanning
 */

$conn = new mysqli("localhost","root","","attendance");

if($conn->connect_error){
    die("Connection failed: " . $conn->connect_error);
}

echo "<h2>🧪 Testing Enhanced Event System</h2>";

// Test 1: Check if new columns exist
echo "<h3>📊 Test 1: Database Structure Check</h3>";

$new_columns = ['event_banner', 'event_type', 'start_date', 'end_date', 'qr_code', 'mobile_scan_link', 'event_history'];
$all_columns_exist = true;

foreach($new_columns as $column) {
    $check_column = $conn->query("SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'attendance' AND TABLE_NAME = 'events' AND COLUMN_NAME = '$column'");
    
    if($check_column->num_rows > 0) {
        echo "<p style='color: #51cf66;'>✅ $column column exists</p>";
    } else {
        echo "<p style='color: #ff6b6b;'>❌ $column column missing</p>";
        $all_columns_exist = false;
    }
}

if($all_columns_exist) {
    echo "<p style='color: #51cf66;'>✅ All new event columns are present</p>";
} else {
    echo "<p style='color: #ff6b6b;'>❌ Some columns are missing. Run update_events_table.php</p>";
}

// Test 2: Check event_banners directory
echo "<h3>📁 Test 2: Event Banners Directory</h3>";
if(is_dir('event_banners')) {
    echo "<p style='color: #51cf66;'>✅ Event banners directory exists</p>";
    
    $banner_files = glob('event_banners/*');
    if(count($banner_files) > 0) {
        echo "<p style='color: #51cf66;'>✅ Found " . count($banner_files) . " banner files</p>";
    } else {
        echo "<p style='color: #74c0fc;'>ℹ️ No banner files found yet</p>";
    }
} else {
    echo "<p style='color: #ff6b6b;'>❌ Event banners directory missing</p>";
}

// Test 3: Check existing events with new fields
echo "<h3>📋 Test 3: Existing Events with New Fields</h3>";
$events_query = $conn->query("SELECT * FROM events ORDER BY created_at DESC LIMIT 5");

if($events_query->num_rows > 0) {
    echo "<div style='background: rgba(0,0,0,0.8); padding: 20px; border-radius: 15px; color: white; margin-bottom: 20px;'>";
    echo "<table style='width: 100%; border-collapse: collapse;'>";
    echo "<tr style='background: rgba(255,215,0,0.2);'>";
    echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>Event</th>";
    echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>Type</th>";
    echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>Start Date</th>";
    echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>End Date</th>";
    echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>Banner</th>";
    echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>Mobile Link</th>";
    echo "</tr>";
    
    while($event = $events_query->fetch_assoc()){
        echo "<tr>";
        echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>" . htmlspecialchars($event['event_name']) . "</td>";
        echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>" . htmlspecialchars($event['event_type'] ?? 'N/A') . "</td>";
        echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>" . ($event['start_date'] ? date('M d, Y', strtotime($event['start_date'])) : 'N/A') . "</td>";
        echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>" . ($event['end_date'] ? date('M d, Y', strtotime($event['end_date'])) : 'N/A') . "</td>";
        echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>" . ($event['event_banner'] ? '✅' : '❌') . "</td>";
        echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>" . ($event['mobile_scan_link'] ? '✅' : '❌') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    echo "</div>";
} else {
    echo "<p style='color: #74c0fc;'>ℹ️ No events found in database</p>";
}

// Test 4: Check mobile scan functionality
echo "<h3>📱 Test 4: Mobile Scan Links</h3>";
$mobile_links_query = $conn->query("SELECT event_name, mobile_scan_link FROM events WHERE mobile_scan_link IS NOT NULL");

if($mobile_links_query->num_rows > 0) {
    echo "<p style='color: #51cf66;'>✅ Found " . $mobile_links_query->num_rows . " events with mobile scan links</p>";
    
    echo "<div style='background: rgba(0,0,0,0.8); padding: 20px; border-radius: 15px; color: white; margin-bottom: 20px;'>";
    while($event = $mobile_links_query->fetch_assoc()){
        echo "<p>📱 <strong>" . htmlspecialchars($event['event_name']) . "</strong>: ";
        echo "<a href='" . htmlspecialchars($event['mobile_scan_link']) . "' style='color: #FFD700; text-decoration: none;' target='_blank'>";
        echo "Open Mobile Scanner</a></p>";
    }
    echo "</div>";
} else {
    echo "<p style='color: #74c0fc;'>ℹ️ No mobile scan links found (create new events to generate them)</p>";
}

// Test 5: Check today's event for student dashboard
echo "<h3>📅 Test 5: Today's Event for Student Dashboard</h3>";
$today_event_query = $conn->prepare("SELECT * FROM events WHERE event_date = CURDATE() ORDER BY event_type DESC, event_name ASC LIMIT 1");
$today_event_query->execute();
$today_event_result = $today_event_query->get_result();
$today_event = $today_event_result->fetch_assoc();

if($today_event) {
    echo "<p style='color: #51cf66;'>✅ Today's event found: " . htmlspecialchars($today_event['event_name']) . "</p>";
    
    echo "<div style='background: rgba(0,0,0,0.8); padding: 20px; border-radius: 15px; color: white; margin-bottom: 20px;'>";
    echo "<h4 style='color: #FFD700;'>Event Details:</h4>";
    echo "<p><strong>Name:</strong> " . htmlspecialchars($today_event['event_name']) . "</p>";
    echo "<p><strong>Type:</strong> " . htmlspecialchars($today_event['event_type'] ?? 'N/A') . "</p>";
    echo "<p><strong>Date:</strong> " . date('M d, Y', strtotime($today_event['event_date'])) . "</p>";
    echo "<p><strong>Description:</strong> " . htmlspecialchars(substr($today_event['event_description'] ?? 'No description', 0, 100)) . "...</p>";
    
    if($today_event['event_banner']) {
        echo "<p><strong>Banner:</strong> ✅ Available</p>";
        echo "<img src='event_banners/" . htmlspecialchars($today_event['event_banner']) . "' style='max-width: 200px; border-radius: 10px; margin-top: 10px;'>";
    } else {
        echo "<p><strong>Banner:</strong> ❌ Not uploaded</p>";
    }
    
    echo "</div>";
} else {
    echo "<p style='color: #74c0fc;'>ℹ️ No events scheduled for today</p>";
}

// Test 6: File upload capabilities
echo "<h3>📤 Test 6: File Upload Configuration</h3>";
$max_upload = ini_get('upload_max_filesize');
$max_post = ini_get('post_max_size');
$memory_limit = ini_get('memory_limit');

echo "<div style='background: rgba(0,0,0,0.8); padding: 20px; border-radius: 15px; color: white; margin-bottom: 20px;'>";
echo "<p><strong>Upload Max Filesize:</strong> $max_upload</p>";
echo "<p><strong>Post Max Size:</strong> $max_post</p>";
echo "<p><strong>Memory Limit:</strong> $memory_limit</p>";
echo "<p><strong>Recommended:</strong> upload_max_filesize >= 5M for banner uploads</p>";
echo "</div>";

// Test 7: Create sample event for testing
echo "<h3>🎯 Test 7: Create Sample Event</h3>";
if(!isset($_GET['create_sample'])) {
    echo "<p><a href='?create_sample=1' style='background: linear-gradient(45deg, #28a745, #20c997); color: white; padding: 10px 20px; text-decoration: none; border-radius: 20px; font-weight: bold;'>Create Sample Event</a></p>";
} else {
    // Create a sample event with all new features
    $sample_event = [
        'event_name' => 'Sample Enhanced Event',
        'event_description' => 'This is a sample event to test all enhanced features including banners, start/end dates, event types, and mobile scanning.',
        'event_type' => 'Special',
        'start_date' => date('Y-m-d'),
        'end_date' => date('Y-m-d', strtotime('+1 day')),
        'course' => 'ALL',
        'late_penalty' => 25,
        'absent_penalty' => 50,
        'event_history' => 'Sample event created for testing enhanced features.',
        'mobile_scan_link' => 'mobile_scan.php?event_id=' . uniqid(),
        'qr_code' => 'qr_' . time() . '.png'
    ];
    
    $stmt = $conn->prepare("
        INSERT INTO events(
            event_name, event_description, event_date, course,
            event_type, start_date, end_date, event_history,
            qr_code, mobile_scan_link,
            late_penalty, absent_penalty,
            morning_login_start, morning_login_end,
            morning_logout_start, morning_logout_end,
            afternoon_login_start, afternoon_login_end,
            afternoon_logout_start, afternoon_logout_end,
            created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    
    // Store time values in variables to avoid reference error
    $morning_login_start = '07:00:00';
    $morning_login_end = '07:30:00';
    $morning_logout_start = '11:30:00';
    $morning_logout_end = '12:00:00';
    $afternoon_login_start = '13:00:00';
    $afternoon_login_end = '13:30:00';
    $afternoon_logout_start = '16:30:00';
    $afternoon_logout_end = '17:00:00';
    
    $stmt->bind_param(
        "sssssssssiiisssssss",
        $sample_event['event_name'],
        $sample_event['event_description'],
        $sample_event['start_date'],
        $sample_event['course'],
        $sample_event['event_type'],
        $sample_event['start_date'],
        $sample_event['end_date'],
        $sample_event['event_history'],
        $sample_event['qr_code'],
        $sample_event['mobile_scan_link'],
        $sample_event['late_penalty'],
        $sample_event['absent_penalty'],
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
        echo "<p style='color: #51cf66;'>✅ Sample event created successfully!</p>";
        echo "<p><a href='create_event.php' style='color: #FFD700; text-decoration: none;'>Create more events</a></p>";
    } else {
        echo "<p style='color: #ff6b6b;'>❌ Error creating sample event: " . $conn->error . "</p>";
    }
}

echo "<div style='margin-top: 30px;'>";
echo "<h3>🚀 Next Steps:</h3>";
echo "<a href='update_events_table.php' style='background: linear-gradient(45deg, #FFD700, #FFA500); color: black; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; margin-right: 10px; display: inline-block;'>🔧 Update Database</a>";
echo "<a href='create_event.php' style='background: linear-gradient(45deg, #28a745, #20c997); color: white; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; margin-right: 10px; display: inline-block;'>📅 Create Event</a>";
echo "<a href='student_dashboard.php' style='background: linear-gradient(45deg, #007bff, #0056b3); color: white; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; display: inline-block;'>👤 Student Dashboard</a>";
echo "</div>";

echo "<div style='background: rgba(255,193,7,0.1); border: 2px solid rgba(255,215,0,0.3); padding: 20px; border-radius: 15px; margin-top: 20px;'>";
echo "<h3 style='color: #FFD700;'>🔧 Enhanced Event System Features:</h3>";
echo "<ul style='color: white;'>";
echo "<li>✅ Start Date and End Date (instead of number of days)</li>";
echo "<li>✅ Event Banner Photo Upload (JPG, PNG - Max 5MB)</li>";
echo "<li>✅ Event Type Selection (Regular, Special, Exam, Meeting, Activity)</li>";
echo "<li>✅ Event History/Notes Tracking</li>";
echo "<li>✅ QR Code Generation for Mobile Scanning</li>";
echo "<li>✅ Mobile Scan Links for Officers</li>";
echo "<li>✅ Enhanced Student Dashboard with Event Banners</li>";
echo "<li>✅ Mobile Attendance Scanning System</li>";
echo "</ul>";
echo "</div>";

$conn->close();
?>
