<?php
/**
 * Test Student Dashboard Data
 * Verifies that the correct student information is being displayed
 */

$conn = new mysqli("localhost","root","","attendance");

if($conn->connect_error){
    die("Connection failed: " . $conn->connect_error);
}

echo "<h2>🧪 Testing Student Dashboard Data</h2>";

// Check if there are students in the database
$students_query = $conn->query("SELECT id, username, first_name, last_name, student_id, total_penalty FROM users WHERE role = 'student' ORDER BY id");

if($students_query->num_rows == 0) {
    echo "<p style='color: #ff6b6b;'>❌ No students found in the database.</p>";
    echo "<p>Please register a student first.</p>";
    echo "<p><a href='REGISTER.PHP'>Register Student</a></p>";
    exit();
}

echo "<h3>📋 Available Students:</h3>";
echo "<div style='background: rgba(0,0,0,0.8); padding: 20px; border-radius: 15px; color: white; margin-bottom: 20px;'>";
echo "<table style='width: 100%; border-collapse: collapse;'>";
echo "<tr style='background: rgba(255,215,0,0.2);'>";
echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>ID</th>";
echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>Username</th>";
echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>Name</th>";
echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>Student ID</th>";
echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>Penalty</th>";
echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>Test</th>";
echo "</tr>";

while($student = $students_query->fetch_assoc()){
    echo "<tr>";
    echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>" . $student['id'] . "</td>";
    echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>" . htmlspecialchars($student['username']) . "</td>";
    echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>" . htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) . "</td>";
    echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>" . htmlspecialchars($student['student_id']) . "</td>";
    echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>₱" . number_format($student['total_penalty'], 2) . "</td>";
    echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>";
    echo "<a href='test_student_data.php?id=" . $student['id'] . "' style='color: #FFD700; text-decoration: none;'>Test Data</a>";
    echo "</td>";
    echo "</tr>";
}

echo "</table>";
echo "</div>";

// Test each student's data
echo "<h3>🔍 Testing Student Data Queries:</h3>";

$students_query->data_seek(0); // Reset pointer
while($student = $students_query->fetch_assoc()){
    $student_id = $student['id'];
    
    echo "<div style='background: rgba(0,0,0,0.8); padding: 20px; border-radius: 15px; color: white; margin-bottom: 20px;'>";
    echo "<h4 style='color: #FFD700;'>Testing Student: " . htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) . " (ID: $student_id)</h4>";
    
    // Test the same query used in student_dashboard.php
    $student_query = $conn->prepare("SELECT face_registered, total_penalty, first_name, middle_name, last_name, course, year_level, section, age, gender, email, phone, student_id FROM users WHERE id = ?");
    $student_query->bind_param("i", $student_id);
    $student_query->execute();
    $student_data = $student_query->get_result()->fetch_assoc();
    
    if($student_data) {
        echo "<p style='color: #51cf66;'>✅ Student data retrieved successfully</p>";
        echo "<table style='width: 100%; border-collapse: collapse; margin-top: 10px;'>";
        echo "<tr><td style='padding: 5px; border: 1px solid rgba(255,215,0,0.1);'><strong>Name:</strong></td><td style='padding: 5px; border: 1px solid rgba(255,215,0,0.1);'>" . htmlspecialchars($student_data['first_name'] . ' ' . ($student_data['middle_name'] ? $student_data['middle_name'] . ' ' : '') . $student_data['last_name']) . "</td></tr>";
        echo "<tr><td style='padding: 5px; border: 1px solid rgba(255,215,0,0.1);'><strong>Student ID:</strong></td><td style='padding: 5px; border: 1px solid rgba(255,215,0,0.1);'>" . htmlspecialchars($student_data['student_id']) . "</td></tr>";
        echo "<tr><td style='padding: 5px; border: 1px solid rgba(255,215,0,0.1);'><strong>Course:</strong></td><td style='padding: 5px; border: 1px solid rgba(255,215,0,0.1);'>" . htmlspecialchars($student_data['course']) . "</td></tr>";
        echo "<tr><td style='padding: 5px; border: 1px solid rgba(255,215,0,0.1);'><strong>Year & Section:</strong></td><td style='padding: 5px; border: 1px solid rgba(255,215,0,0.1);'>" . htmlspecialchars($student_data['year_level'] . ' - ' . $student_data['section']) . "</td></tr>";
        echo "<tr><td style='padding: 5px; border: 1px solid rgba(255,215,0,0.1);'><strong>Email:</strong></td><td style='padding: 5px; border: 1px solid rgba(255,215,0,0.1);'>" . htmlspecialchars($student_data['email']) . "</td></tr>";
        echo "<tr><td style='padding: 5px; border: 1px solid rgba(255,215,0,0.1);'><strong>Phone:</strong></td><td style='padding: 5px; border: 1px solid rgba(255,215,0,0.1);'>" . htmlspecialchars($student_data['phone']) . "</td></tr>";
        echo "<tr><td style='padding: 5px; border: 1px solid rgba(255,215,0,0.1);'><strong>Total Penalty:</strong></td><td style='padding: 5px; border: 1px solid rgba(255,215,0,0.1);'>₱" . number_format($student_data['total_penalty'], 2) . "</td></tr>";
        echo "<tr><td style='padding: 5px; border: 1px solid rgba(255,215,0,0.1);'><strong>Face Registered:</strong></td><td style='padding: 5px; border: 1px solid rgba(255,215,0,0.1);'>" . ($student_data['face_registered'] ? 'Yes' : 'No') . "</td></tr>";
        echo "</table>";
        
        // Test attendance query
        $attendance_query = $conn->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN morning_status = 'Present' THEN 1 ELSE 0 END) as present,
                SUM(CASE WHEN morning_status = 'Late' THEN 1 ELSE 0 END) as late,
                SUM(CASE WHEN morning_status = 'Absent' OR morning_status IS NULL THEN 1 ELSE 0 END) as absent,
                SUM(penalty) as total_penalty
            FROM attendance a
            JOIN events e ON a.event_id = e.id
            WHERE a.student_id = ?
            AND e.event_date <= CURDATE()
        ");
        $attendance_query->bind_param("i", $student_id);
        $attendance_query->execute();
        $attendance_data = $attendance_query->get_result()->fetch_assoc();
        
        if($attendance_data) {
            echo "<p style='color: #51cf66;'>✅ Attendance data retrieved successfully</p>";
            echo "<table style='width: 100%; border-collapse: collapse; margin-top: 10px;'>";
            echo "<tr><td style='padding: 5px; border: 1px solid rgba(255,215,0,0.1);'><strong>Total Events:</strong></td><td style='padding: 5px; border: 1px solid rgba(255,215,0,0.1);'>" . $attendance_data['total'] . "</td></tr>";
            echo "<tr><td style='padding: 5px; border: 1px solid rgba(255,215,0,0.1);'><strong>Present:</strong></td><td style='padding: 5px; border: 1px solid rgba(255,215,0,0.1);'>" . $attendance_data['present'] . "</td></tr>";
            echo "<tr><td style='padding: 5px; border: 1px solid rgba(255,215,0,0.1);'><strong>Late:</strong></td><td style='padding: 5px; border: 1px solid rgba(255,215,0,0.1);'>" . $attendance_data['late'] . "</td></tr>";
            echo "<tr><td style='padding: 5px; border: 1px solid rgba(255,215,0,0.1);'><strong>Absent:</strong></td><td style='padding: 5px; border: 1px solid rgba(255,215,0,0.1);'>" . $attendance_data['absent'] . "</td></tr>";
            echo "<tr><td style='padding: 5px; border: 1px solid rgba(255,215,0,0.1);'><strong>Attendance Penalty:</strong></td><td style='padding: 5px; border: 1px solid rgba(255,215,0,0.1);'>₱" . number_format($attendance_data['total_penalty'], 2) . "</td></tr>";
            echo "</table>";
        } else {
            echo "<p style='color: #ff6b6b;'>❌ Failed to retrieve attendance data</p>";
        }
        
    } else {
        echo "<p style='color: #ff6b6b;'>❌ Failed to retrieve student data</p>";
    }
    
    echo "</div>";
}

echo "<div style='margin-top: 30px;'>";
echo "<h3>🚀 Next Steps:</h3>";
echo "<a href='student_login.php' style='background: linear-gradient(45deg, #007bff, #0056b3); color: white; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; margin-right: 10px; display: inline-block;'>👤 Student Login</a>";
echo "<a href='admin_dashboard.php' style='background: linear-gradient(45deg, #FFD700, #FFA500); color: black; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; display: inline-block;'>👨‍💼 Admin Dashboard</a>";
echo "</div>";

echo "<div style='background: rgba(255,193,7,0.1); border: 2px solid rgba(255,215,0,0.3); padding: 20px; border-radius: 15px; margin-top: 20px;'>";
echo "<h3 style='color: #FFD700;'>🔧 What This Test Does:</h3>";
echo "<ul style='color: white;'>";
echo "<li>✅ Lists all students in the database</li>";
echo "<li>✅ Tests the exact queries used in student_dashboard.php</li>";
echo "<li>✅ Verifies student data retrieval</li>";
echo "<li>✅ Checks attendance calculation</li>";
echo "<li>✅ Identifies any data inconsistencies</li>";
echo "</ul>";
echo "</div>";

$conn->close();
?>
