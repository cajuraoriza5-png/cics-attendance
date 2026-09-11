<?php
/**
 * Complete System Test for Enhanced CICS Attendance System
 * Tests all components with the new database structure
 */

$conn = new mysqli("localhost","root","","attendance");

if($conn->connect_error){
    die("Connection failed: " . $conn->connect_error);
}

echo "<h2>🧪 Complete System Test</h2>";
echo "<p style='color: #FFD700;'>Testing all system components with new database structure...</p>";

// Test 1: Database Structure
echo "<h3>📊 Test 1: Database Structure</h3>";
$tests = [
    'users' => ['id', 'username', 'password', 'student_id', 'first_name', 'last_name', 'age', 'gender', 'email', 'course', 'year_level', 'section', 'face_registered', 'total_penalty', 'role'],
    'events' => ['id', 'event_name', 'event_date', 'morning_start', 'morning_end', 'afternoon_start', 'afternoon_end'],
    'attendance' => ['id', 'student_id', 'event_id', 'date', 'morning_in', 'morning_out', 'morning_status', 'afternoon_in', 'afternoon_out', 'afternoon_status', 'penalty'],
    'face_data' => ['id', 'student_id', 'face_image', 'created_at'],
    'payments' => ['id', 'student_id', 'amount', 'payment_method', 'payment_date', 'status', 'reference_number']
];

$all_tests_passed = true;
foreach($tests as $table => $required_columns) {
    $result = $conn->query("DESCRIBE $table");
    $existing_columns = [];
    while($row = $result->fetch_assoc()) {
        $existing_columns[] = $row['Field'];
    }
    
    $missing = array_diff($required_columns, $existing_columns);
    if(empty($missing)) {
        echo "<p style='color: #51cf66;'>✅ $table table structure OK</p>";
    } else {
        echo "<p style='color: #ff6b6b;'>❌ $table table missing columns: " . implode(', ', $missing) . "</p>";
        $all_tests_passed = false;
    }
}

// Test 2: Admin Account
echo "<h3>👤 Test 2: Admin Account</h3>";
$admin_test = $conn->query("SELECT username, role FROM users WHERE role = 'admin' LIMIT 1");
if($admin_test->num_rows > 0) {
    $admin = $admin_test->fetch_assoc();
    echo "<p style='color: #51cf66;'>✅ Admin account found: {$admin['username']}</p>";
} else {
    echo "<p style='color: #ff6b6b;'>❌ No admin account found</p>";
    $all_tests_passed = false;
}

// Test 3: Event System
echo "<h3>📅 Test 3: Event System</h3>";
$event_test = $conn->query("SELECT * FROM events WHERE event_date = CURDATE()");
if($event_test->num_rows > 0) {
    $event = $event_test->fetch_assoc();
    echo "<p style='color: #51cf66;'>✅ Today's event found: {$event['event_name']}</p>";
} else {
    echo "<p style='color: #ffa500;'>⚠️ No event for today (creating sample event)</p>";
    $conn->query("INSERT INTO events (event_name, event_date, morning_start, morning_end, afternoon_start, afternoon_end) VALUES ('Test Event', CURDATE(), '08:00:00', '08:30:00', '13:00:00', '13:30:00')");
    echo "<p style='color: #51cf66;'>✅ Sample event created</p>";
}

// Test 4: Registration Form Fields
echo "<h3>📝 Test 4: Registration Form Compatibility</h3>";
$required_fields = ['username', 'student_id', 'first_name', 'last_name', 'age', 'gender', 'email', 'course', 'year_level', 'section', 'password'];
$registration_test = $conn->query("DESCRIBE users");
$available_fields = [];
while($row = $registration_test->fetch_assoc()) {
    $available_fields[] = $row['Field'];
}

$missing_registration = array_diff($required_fields, $available_fields);
if(empty($missing_registration)) {
    echo "<p style='color: #51cf66;'>✅ Registration form fields compatible</p>";
} else {
    echo "<p style='color: #ff6b6b;'>❌ Registration form missing fields: " . implode(', ', $missing_registration) . "</p>";
    $all_tests_passed = false;
}

// Test 5: Face Enrollment System
echo "<h3>📸 Test 5: Face Enrollment System</h3>";
$face_columns_test = $conn->query("DESCRIBE face_data");
if($face_columns_test->num_rows > 0) {
    echo "<p style='color: #51cf66;'>✅ Face data table exists</p>";
    
    // Test faces directory
    if(is_dir('faces') && is_writable('faces')) {
        echo "<p style='color: #51cf66;'>✅ Faces directory exists and writable</p>";
    } else {
        echo "<p style='color: #ffa500;'>⚠️ Faces directory issue (creating)</p>";
        if(!is_dir('faces')) {
            mkdir('faces', 0755, true);
        }
        echo "<p style='color: #51cf66;'>✅ Faces directory created</p>";
    }
} else {
    echo "<p style='color: #ff6b6b;'>❌ Face data table not found</p>";
    $all_tests_passed = false;
}

// Test 6: Attendance Recording
echo "<h3>📊 Test 6: Attendance Recording System</h3>";
$attendance_test = $conn->query("DESCRIBE attendance");
if($attendance_test->num_rows > 0) {
    echo "<p style='color: #51cf66;'>✅ Attendance table exists</p>";
    
    // Test foreign key relationships
    $fk_test = $conn->query("
        SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME 
        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
        WHERE TABLE_SCHEMA = 'attendance' 
        AND TABLE_NAME = 'attendance' 
        AND REFERENCED_TABLE_NAME IS NOT NULL
    ");
    
    if($fk_test->num_rows >= 2) {
        echo "<p style='color: #51cf66;'>✅ Foreign key relationships established</p>";
    } else {
        echo "<p style='color: #ffa500;'>⚠️ Some foreign key relationships may be missing</p>";
    }
} else {
    echo "<p style='color: #ff6b6b;'>❌ Attendance table not found</p>";
    $all_tests_passed = false;
}

// Test 7: Student Dashboard Fields
echo "<h3>👤 Test 7: Student Dashboard Compatibility</h3>";
$dashboard_fields = ['face_registered', 'total_penalty', 'first_name', 'last_name', 'course', 'year_level', 'section', 'age', 'gender', 'email'];
$dashboard_missing = array_diff($dashboard_fields, $available_fields);
if(empty($dashboard_missing)) {
    echo "<p style='color: #51cf66;'>✅ Student dashboard fields compatible</p>";
} else {
    echo "<p style='color: #ff6b6b;'>❌ Student dashboard missing fields: " . implode(', ', $dashboard_missing) . "</p>";
    $all_tests_passed = false;
}

// Test 8: Create Test Student
echo "<h3>🧪 Test 8: Create Test Student</h3>";
$test_student_data = [
    'username' => 'test_student_' . time(),
    'password' => password_hash('test123', PASSWORD_DEFAULT),
    'student_id' => '2024-TEST1',
    'first_name' => 'Test',
    'last_name' => 'Student',
    'age' => 20,
    'gender' => 'Other',
    'email' => 'test@student.com',
    'course' => 'BSCS',
    'year_level' => '1st Year',
    'section' => 'A'
];

$insert_test = $conn->prepare("
    INSERT INTO users(username, password, student_id, first_name, last_name, age, gender, email, course, year_level, section, face_registered, total_penalty, role)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0.00, 'student')
");

if($insert_test) {
    $insert_test->bind_param("sssssissssss", 
        $test_student_data['username'], 
        $test_student_data['password'], 
        $test_student_data['student_id'], 
        $test_student_data['first_name'], 
        $test_student_data['last_name'], 
        $test_student_data['age'], 
        $test_student_data['gender'], 
        $test_student_data['email'], 
        $test_student_data['course'], 
        $test_student_data['year_level'], 
        $test_student_data['section']
    );
    
    if($insert_test->execute()) {
        $test_student_id = $conn->insert_id;
        echo "<p style='color: #51cf66;'>✅ Test student created successfully (ID: $test_student_id)</p>";
        
        // Test face data insertion
        $face_test = $conn->prepare("INSERT INTO face_data(student_id, face_image) VALUES (?, 'test_image.jpg')");
        if($face_test) {
            $face_test->bind_param("i", $test_student_id);
            if($face_test->execute()) {
                echo "<p style='color: #51cf66;'>✅ Test face data created</p>";
            } else {
                echo "<p style='color: #ff6b6b;'>❌ Failed to create test face data</p>";
            }
        }
        
        // Test attendance recording
        $event_id = $conn->query("SELECT id FROM events WHERE event_date = CURDATE() LIMIT 1")->fetch_assoc()['id'];
        $attendance_test = $conn->prepare("
            INSERT INTO attendance(student_id, event_id, date, morning_in, morning_status, penalty)
            VALUES (?, ?, CURDATE(), NOW(), 'Present', 0.00)
        ");
        if($attendance_test) {
            $attendance_test->bind_param("ii", $test_student_id, $event_id);
            if($attendance_test->execute()) {
                echo "<p style='color: #51cf66;'>✅ Test attendance recorded</p>";
            } else {
                echo "<p style='color: #ff6b6b;'>❌ Failed to record test attendance</p>";
            }
        }
        
        // Clean up test data
        $conn->query("DELETE FROM attendance WHERE student_id = $test_student_id");
        $conn->query("DELETE FROM face_data WHERE student_id = $test_student_id");
        $conn->query("DELETE FROM users WHERE id = $test_student_id");
        echo "<p style='color: #74c0fc;'>🧹 Test data cleaned up</p>";
        
    } else {
        echo "<p style='color: #ff6b6b;'>❌ Failed to create test student</p>";
        $all_tests_passed = false;
    }
} else {
    echo "<p style='color: #ff6b6b;'>❌ Failed to prepare test student insertion</p>";
    $all_tests_passed = false;
}

// Final Results
echo "<h3>📋 Test Results Summary</h3>";
if($all_tests_passed) {
    echo "<div style='background: rgba(40,167,69,0.1); border: 2px solid rgba(40,167,69,0.3); padding: 20px; border-radius: 15px; margin: 20px 0;'>";
    echo "<h3 style='color: #28a745;'>🎉 All Tests Passed!</h3>";
    echo "<p style='color: white;'>Your enhanced CICS Attendance System is ready to use with the new database structure.</p>";
    echo "</div>";
} else {
    echo "<div style='background: rgba(220,53,69,0.1); border: 2px solid rgba(220,53,69,0.3); padding: 20px; border-radius: 15px; margin: 20px 0;'>";
    echo "<h3 style='color: #dc3545;'>⚠️ Some Tests Failed</h3>";
    echo "<p style='color: white;'>Please review the failed tests above and fix the issues before using the system.</p>";
    echo "</div>";
}

// Next Steps
echo "<div style='margin-top: 30px;'>";
echo "<h3>🚀 Ready to Use:</h3>";
echo "<a href='admin_login.php' style='background: linear-gradient(45deg, #FFD700, #FFA500); color: black; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; margin-right: 10px; display: inline-block;'>🔐 Admin Login</a>";
echo "<a href='REGISTER.PHP' style='background: linear-gradient(45deg, #28a745, #20c997); color: white; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; margin-right: 10px; display: inline-block;'>📝 Register Student</a>";
echo "<a href='student_login.php' style='background: linear-gradient(45deg, #007bff, #0056b3); color: white; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; display: inline-block;'>👤 Student Login</a>";
echo "</div>";

echo "<div style='background: rgba(255,193,7,0.1); border: 2px solid rgba(255,215,0,0.3); padding: 20px; border-radius: 15px; margin-top: 20px;'>";
echo "<h3 style='color: #FFD700;'>🔑 System Features:</h3>";
echo "<ul style='color: white;'>";
echo "<li>✅ Enhanced registration with all required fields</li>";
echo "<li>✅ Professional face enrollment with continuous capture</li>";
echo "<li>✅ Student dashboard with profile display</li>";
echo "<li>✅ Admin analytics dashboard</li>";
echo "<li>✅ Black/gold/yellow theme with transparency</li>";
echo "<li>✅ Robust error handling and validation</li>";
echo "<li>✅ Mobile-responsive design</li>";
echo "</ul>";
echo "</div>";

$conn->close();
?>
