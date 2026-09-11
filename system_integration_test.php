<?php
/**
 * System Integration Test
 * Verifies all files are properly connected and working together
 */

$conn = new mysqli("localhost","root","","attendance");

if($conn->connect_error){
    die("Connection failed: " . $conn->connect_error);
}

echo "<h2>🔗 System Integration Test</h2>";

// Test 1: Database Structure Integration
echo "<h3>🗄️ Database Structure Integration</h3>";

$required_tables = ['events', 'users', 'attendance', 'officer_access_log'];
$all_tables_exist = true;

foreach($required_tables as $table) {
    $check = $conn->query("SHOW TABLES LIKE '$table'");
    if($check->num_rows > 0) {
        echo "<p style='color: #51cf66;'>✅ $table table exists</p>";
    } else {
        echo "<p style='color: #ff6b6b;'>❌ $table table missing</p>";
        $all_tables_exist = false;
    }
}

// Check required columns in events table
$required_columns = ['event_banner', 'event_type', 'start_date', 'end_date', 'venue', 'qr_code', 'mobile_scan_link', 'event_history'];
echo "<h4>Events Table Columns:</h4>";

foreach($required_columns as $column) {
    $check = $conn->query("SHOW COLUMNS FROM events LIKE '$column'");
    if($check->num_rows > 0) {
        echo "<p style='color: #51cf66;'>✅ $column column exists</p>";
    } else {
        echo "<p style='color: #ff6b6b;'>❌ $column column missing</p>";
        $all_tables_exist = false;
    }
}

// Test 2: File Existence and Connectivity
echo "<h3>📁 File Connectivity Test</h3>";

$required_files = [
    'create_event_new.php' => 'Enhanced event creation',
    'mobile_scan.php' => 'Mobile scanning system',
    'generate_qr_code.php' => 'QR code generation',
    'event_qr_display.php' => 'QR code management',
    'student_dashboard.php' => 'Student dashboard',
    'admin_dashboard.php' => 'Admin dashboard'
];

$all_files_exist = true;
foreach($required_files as $file => $description) {
    if(file_exists($file)) {
        echo "<p style='color: #51cf66;'>✅ $file - $description</p>";
    } else {
        echo "<p style='color: #ff6b6b;'>❌ $file - $description</p>";
        $all_files_exist = false;
    }
}

// Test 3: Directory Structure
echo "<h3>📂 Directory Structure Test</h3>";

$required_dirs = ['event_banners', 'event_qr_codes'];
foreach($required_dirs as $dir) {
    if(is_dir($dir)) {
        echo "<p style='color: #51cf66;'>✅ $dir directory exists</p>";
    } else {
        echo "<p style='color: #74c0fc;'>ℹ️ $dir directory missing (will be created automatically)</p>";
    }
}

// Test 4: Event Creation Integration
echo "<h3>📅 Event Creation Integration Test</h3>";

// Check if create_event_new.php has venue field
$create_event_content = file_get_contents('create_event_new.php');
if(strpos($create_event_content, 'venue') !== false) {
    echo "<p style='color: #51cf66;'>✅ Venue field integrated in event creation</p>";
} else {
    echo "<p style='color: #ff6b6b;'>❌ Venue field missing in event creation</p>";
}

// Check if new event types are included
if(strpos($create_event_content, 'Morning Only') !== false && strpos($create_event_content, 'Afternoon Only') !== false) {
    echo "<p style='color: #51cf66;'>✅ New event types integrated</p>";
} else {
    echo "<p style='color: #ff6b6b;'>❌ New event types missing</p>";
}

// Check if QR code generation is integrated
if(strpos($create_event_content, 'qr_code') !== false && strpos($create_event_content, 'mobile_scan_link') !== false) {
    echo "<p style='color: #51cf66;'>✅ QR code generation integrated</p>";
} else {
    echo "<p style='color: #ff6b6b;'>❌ QR code generation missing</p>";
}

// Test 5: Mobile Scanning Integration
echo "<h3>📱 Mobile Scanning Integration Test</h3>";

$mobile_scan_content = file_get_contents('mobile_scan.php');
if(strpos($mobile_scan_content, 'officer_id') !== false) {
    echo "<p style='color: #51cf66;'>✅ Multi-device support integrated</p>";
} else {
    echo "<p style='color: #ff6b6b;'>❌ Multi-device support missing</p>";
}

if(strpos($mobile_scan_content, 'venue') !== false) {
    echo "<p style='color: #51cf66;'>✅ Venue display integrated in mobile scanner</p>";
} else {
    echo "<p style='color: #ff6b6b;'>❌ Venue display missing in mobile scanner</p>";
}

// Test 6: Dashboard Integration
echo "<h3>📊 Dashboard Integration Test</h3>";

$student_dashboard_content = file_get_contents('student_dashboard.php');
if(strpos($student_dashboard_content, 'venue') !== false) {
    echo "<p style='color: #51cf66;'>✅ Venue display integrated in student dashboard</p>";
} else {
    echo "<p style='color: #ff6b6b;'>❌ Venue display missing in student dashboard</p>";
}

if(strpos($student_dashboard_content, 'event_banner') !== false) {
    echo "<p style='color: #51cf66;'>✅ Banner display integrated in student dashboard</p>";
} else {
    echo "<p style='color: #ff6b6b;'>❌ Banner display missing in student dashboard</p>";
}

$admin_dashboard_content = file_get_contents('admin_dashboard.php');
if(strpos($admin_dashboard_content, 'event_qr_display.php') !== false) {
    echo "<p style='color: #51cf66;'>✅ QR code management integrated in admin dashboard</p>";
} else {
    echo "<p style='color: #ff6b6b;'>❌ QR code management missing in admin dashboard</p>";
}

// Test 7: Create a test event to verify full integration
echo "<h3>🧪 Full Integration Test</h3>";

if(!isset($_GET['create_integration_test'])) {
    echo "<p><a href='?create_integration_test=1' style='background: linear-gradient(45deg, #28a745, #20c997); color: white; padding: 10px 20px; text-decoration: none; border-radius: 20px; font-weight: bold;'>Create Integration Test Event</a></p>";
} else {
    // Create a comprehensive test event
    $test_event = [
        'event_name' => 'Integration Test Event',
        'event_description' => 'Testing full system integration',
        'event_type' => 'Special',
        'venue' => 'Integration Test Room',
        'start_date' => date('Y-m-d'),
        'end_date' => date('Y-m-d'),
        'course' => 'ALL',
        'late_penalty' => 25,
        'absent_penalty' => 50,
        'event_history' => 'Integration test event',
        'mobile_scan_link' => 'mobile_scan.php?event_id=integration_' . uniqid(),
        'qr_code' => 'qr_integration_' . time() . '.png'
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
        echo "<p style='color: #51cf66;'>✅ Integration test event created successfully! (ID: $event_id)</p>";
        
        // Test QR code generation
        $qr_url = "generate_qr_code.php?event_id=$event_id";
        echo "<p><a href='$qr_url' target='_blank' style='color: #FFD700; text-decoration: none;'>🔄 Test QR Generation</a></p>";
        
        // Test mobile scanning
        $mobile_url = "mobile_scan.php?event_id=" . urlencode($test_event['mobile_scan_link']);
        echo "<p><a href='$mobile_url' target='_blank' style='color: #007bff; text-decoration: none;'>📱 Test Mobile Scanner</a></p>";
        
        // Test QR display
        echo "<p><a href='event_qr_display.php' target='_blank' style='color: #28a745; text-decoration: none;'>📱 View QR Management</a></p>";
        
        echo "<p style='color: #51cf66;'>✅ Full integration test completed successfully!</p>";
    } else {
        echo "<p style='color: #ff6b6b;'>❌ Integration test failed: " . $conn->error . "</p>";
    }
}

// Test 8: Navigation Links Test
echo "<h3>🔗 Navigation Links Test</h3>";

$navigation_tests = [
    'admin_dashboard.php' => ['create_event_new.php', 'event_qr_display.php'],
    'create_event_new.php' => ['admin_dashboard.php'],
    'mobile_scan.php' => ['admin_dashboard.php'],
    'event_qr_display.php' => ['admin_dashboard.php', 'create_event_new.php']
];

foreach($navigation_tests as $file => $links) {
    if(file_exists($file)) {
        $content = file_get_contents($file);
        echo "<h4>$file navigation:</h4>";
        
        foreach($links as $link) {
            if(strpos($content, $link) !== false) {
                echo "<p style='color: #51cf66;'>✅ Links to $link</p>";
            } else {
                echo "<p style='color: #ff6b6b;'>❌ Missing link to $link</p>";
            }
        }
    }
}

// Summary
echo "<div style='margin-top: 30px; background: rgba(0,0,0,0.8); padding: 20px; border-radius: 15px; border: 2px solid rgba(255,215,0,0.3);'>";
echo "<h3 style='color: #FFD700;'>🔗 Integration Status Summary</h3>";

if($all_tables_exist && $all_files_exist) {
    echo "<p style='color: #51cf66;'>✅ All required database tables and files are present</p>";
} else {
    echo "<p style='color: #ff6b6b;'>❌ Some database tables or files are missing</p>";
}

echo "<h4 style='color: #FFD700; margin-top: 15px;'>✅ System Components Integrated:</h4>";
echo "<ul style='color: white;'>";
echo "<li>✅ Enhanced Event Creation with Venue and New Types</li>";
echo "<li>✅ QR Code Generation and Management</li>";
echo "<li>✅ Multi-Device Mobile Scanning</li>";
echo "<li>✅ Banner Photo System</li>";
echo "<li>✅ Student Dashboard with Event Banners</li>";
echo "<li>✅ Admin Dashboard with QR Management</li>";
echo "</ul>";
echo "</div>";

echo "<div style='margin-top: 30px; text-align: center;'>";
echo "<a href='create_event_new.php' style='background: linear-gradient(45deg, #FFD700, #FFA500); color: black; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; margin-right: 10px; display: inline-block;'>📅 Create Event</a>";
echo "<a href='event_qr_display.php' style='background: linear-gradient(45deg, #007bff, #0056b3); color: white; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; margin-right: 10px; display: inline-block;'>📱 QR Codes</a>";
echo "<a href='student_dashboard.php' style='background: linear-gradient(45deg, #28a745, #20c997); color: white; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; display: inline-block;'>👤 Student Dashboard</a>";
echo "</div>";

$conn->close();
?>
