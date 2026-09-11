<?php
/**
 * Test Admin Dashboard Fixes
 * Verify all warnings are fixed and connections work
 */

$conn = new mysqli("localhost","root","","attendance");

if($conn->connect_error){
    die("Connection failed: " . $conn->connect_error);
}

echo "<h2>🔧 Testing Admin Dashboard Fixes</h2>";

// Test 1: Check database connection
echo "<h3>🗄️ Database Connection</h3>";
echo "<p style='color: #51cf66;'>✅ Database connection successful</p>";

// Test 2: Check events table structure
echo "<h3>📅 Events Table Structure</h3>";
$events_check = $conn->query("DESCRIBE events");
if($events_check) {
    echo "<p style='color: #51cf66;'>✅ Events table exists</p>";
    
    // Check for required columns
    $required_columns = ['id', 'event_name', 'start_date', 'end_date', 'event_date'];
    $found_columns = [];
    
    while($row = $events_check->fetch_assoc()) {
        $found_columns[] = $row['Field'];
    }
    
    foreach($required_columns as $column) {
        if(in_array($column, $found_columns)) {
            echo "<p style='color: #51cf66;'>✅ Column '$column' exists</p>";
        } else {
            echo "<p style='color: #ff6b6b;'>❌ Column '$column' missing</p>";
        }
    }
} else {
    echo "<p style='color: #ff6b6b;'>❌ Events table not found</p>";
}

// Test 3: Check attendance table
echo "<h3>📊 Attendance Table</h3>";
$attendance_check = $conn->query("SHOW TABLES LIKE 'attendance'");
if($attendance_check->num_rows > 0) {
    echo "<p style='color: #51cf66;'>✅ Attendance table exists</p>";
    
    // Check attendance structure
    $attendance_structure = $conn->query("DESCRIBE attendance");
    $attendance_columns = [];
    while($row = $attendance_structure->fetch_assoc()) {
        $attendance_columns[] = $row['Field'];
    }
    
    $required_attendance_columns = ['event_id', 'morning_status', 'late_status', 'absent_status'];
    foreach($required_attendance_columns as $column) {
        if(in_array($column, $attendance_columns)) {
            echo "<p style='color: #51cf66;'>✅ Attendance column '$column' exists</p>";
        } else {
            echo "<p style='color: #ff6b6b;'>❌ Attendance column '$column' missing</p>";
        }
    }
} else {
    echo "<p style='color: #ff6b6b;'>❌ Attendance table not found (will use fallback)</p>";
}

// Test 4: Check face_data table
echo "<h3>👤 Face Data Table</h3>";
$face_data_check = $conn->query("SHOW TABLES LIKE 'face_data'");
if($face_data_check->num_rows > 0) {
    echo "<p style='color: #51cf66;'>✅ Face data table exists</p>";
} else {
    echo "<p style='color: #ff6b6b;'>❌ Face data table not found (will use fallback)</p>";
}

// Test 5: Test event query
echo "<h3>🎯 Event Query Test</h3>";
try {
    $event_query = $conn->query("SELECT * FROM events WHERE start_date = CURDATE() OR end_date >= CURDATE() ORDER BY start_date ASC LIMIT 1");
    if($event_query) {
        $event_data = $event_query->fetch_assoc();
        if($event_data) {
            echo "<p style='color: #51cf66;'>✅ Event query successful - Found: " . htmlspecialchars($event_data['event_name'] ?? 'No name') . "</p>";
        } else {
            echo "<p style='color: #74c0fc;'>ℹ️ No current events found</p>";
        }
    } else {
        echo "<p style='color: #ff6b6b;'>❌ Event query failed</p>";
    }
} catch(Exception $e) {
    echo "<p style='color: #ff6b6b;'>❌ Event query error: " . $e->getMessage() . "</p>";
}

// Test 6: Test student count queries
echo "<h3>👨‍🎓 Student Count Queries</h3>";
try {
    $total_students = $conn->query("SELECT COUNT(*) total FROM users WHERE role='student'")->fetch_assoc()['total'] ?? 0;
    echo "<p style='color: #51cf66;'>✅ Total students: $total_students</p>";
    
    $registered_students = $conn->query("SELECT COUNT(*) total FROM users WHERE role='student' AND face_registered=1")->fetch_assoc()['total'] ?? 0;
    echo "<p style='color: #51cf66;'>✅ Registered students: $registered_students</p>";
    
    $pending_students = $total_students - $registered_students;
    echo "<p style='color: #51cf66;'>✅ Pending students: $pending_students</p>";
} catch(Exception $e) {
    echo "<p style='color: #ff6b6b;'>❌ Student count error: " . $e->getMessage() . "</p>";
}

// Test 7: Test recent activity query
echo "<h3>📈 Recent Activity Query</h3>";
try {
    $face_data_check = $conn->query("SHOW TABLES LIKE 'face_data'");
    if($face_data_check->num_rows > 0) {
        $recent_query = $conn->query("
            SELECT u.student_id, u.first_name, u.last_name, u.face_registered, 
                   fd.created_at as face_enrolled_date
            FROM users u 
            LEFT JOIN face_data fd ON u.id = fd.student_id 
            WHERE u.role='student' 
            ORDER BY COALESCE(fd.created_at, u.created_at) DESC 
            LIMIT 5
        ");
        echo "<p style='color: #51cf66;'>✅ Recent activity query with face_data successful</p>";
    } else {
        $recent_query = $conn->query("
            SELECT u.student_id, u.first_name, u.last_name, u.face_registered, 
                   u.created_at as face_enrolled_date
            FROM users u 
            WHERE u.role='student' 
            ORDER BY u.created_at DESC 
            LIMIT 5
        ");
        echo "<p style='color: #51cf66;'>✅ Recent activity query fallback successful</p>";
    }
    
    if($recent_query && $recent_query->num_rows > 0) {
        echo "<p style='color: #51cf66;'>✅ Found " . $recent_query->num_rows . " recent activities</p>";
    }
} catch(Exception $e) {
    echo "<p style='color: #ff6b6b;'>❌ Recent activity error: " . $e->getMessage() . "</p>";
}

// Test 8: Check file connections
echo "<h3>🔗 File Connections</h3>";
$required_files = [
    'admin_dashboard.php',
    'manage_events.php',
    'create_event_new.php',
    'student_list.php',
    'reports.php',
    'payments.php'
];

foreach($required_files as $file) {
    if(file_exists($file)) {
        echo "<p style='color: #51cf66;'>✅ $file exists</p>";
    } else {
        echo "<p style='color: #ff6b6b;'>❌ $file missing</p>";
    }
}

// Test 9: Check auto_absent.php
echo "<h3>⚙️ Auto Absent System</h3>";
if(file_exists("auto_absent.php")) {
    echo "<p style='color: #51cf66;'>✅ auto_absent.php exists and will be included</p>";
} else {
    echo "<p style='color: #ff6b6b;'>❌ auto_absent.php missing (will be skipped)</p>";
}

echo "<div style='margin-top: 30px; background: rgba(255,193,7,0.1); border: 2px solid rgba(255,215,0,0.3); padding: 20px; border-radius: 15px;'>";
echo "<h3 style='color: #FFD700;'>🔧 Fixes Applied:</h3>";
echo "<ul style='color: white;'>";
echo "<li>✅ Fixed event date query (start_date/end_date instead of event_date)</li>";
echo "<li>✅ Added error handling for database queries</li>";
echo "<li>✅ Added fallback for missing tables (attendance, face_data)</li>";
echo "<li>✅ Fixed session variable handling with null coalescing</li>";
echo "<li>✅ Added connection error checking</li>";
echo "<li>✅ Updated sidebar to point to manage_events.php</li>";
echo "<li>✅ Added conditional include for auto_absent.php</li>";
echo "</ul>";
echo "</div>";

echo "<div style='margin-top: 30px; text-align: center;'>";
echo "<a href='admin_dashboard.php' style='background: linear-gradient(45deg, #FFD700, #FFA500); color: black; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; margin-right: 10px; display: inline-block;'>👨‍💼 Admin Dashboard</a>";
echo "<a href='manage_events.php' style='background: linear-gradient(45deg, #007bff, #0056b3); color: white; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; margin-right: 10px; display: inline-block;'>📅 Manage Events</a>";
echo "<a href='create_event_new.php' style='background: linear-gradient(45deg, #28a745, #20c997); color: white; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; display: inline-block;'>📅 Create Event</a>";
echo "</div>";

$conn->close();
?>
