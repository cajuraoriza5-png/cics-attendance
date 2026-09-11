<?php
/**
 * Clear All Student Accounts
 */

$conn = new mysqli("localhost","root","","attendance");

if($conn->connect_error){
    die("Connection failed: " . $conn->connect_error);
}

echo "<h2>🗑️ Clear All Student Accounts</h2>";
echo "<p style='color: #ff6b6b; font-weight: bold;'>⚠️ WARNING: This will delete ALL student accounts and their data!</p>";

// Check if confirmation was provided
if(!isset($_GET['confirm']) || $_GET['confirm'] !== 'CLEAR_STUDENTS') {
    echo "<div style='background: rgba(220,53,69,0.1); border: 2px solid rgba(220,53,69,0.3); padding: 20px; border-radius: 15px; margin: 20px 0;'>";
    echo "<h3 style='color: #dc3545;'>⚠️ Confirm Clear All Students</h3>";
    echo "<p>This action will permanently delete:</p>";
    echo "<ul style='color: white;'>";
    echo "<li>👥 All student accounts</li>";
    echo "<li>📸 All face registration data for students</li>";
    echo "<li>📊 All attendance records for students</li>";
    echo "</ul>";
    echo "<p><strong>Admin accounts will be preserved!</strong></p>";
    echo "<p><strong>This action cannot be undone!</strong></p>";
    echo "<a href='clear_students.php?confirm=CLEAR_STUDENTS' style='background: linear-gradient(45deg, #dc3545, #c82333); color: white; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold;'>⚠️ Yes, Clear All Students</a>";
    echo " <a href='check_existing_data.php' style='background: rgba(255,255,255,0.1); color: white; padding: 15px 30px; text-decoration: none; border-radius: 25px; margin-left: 10px;'>❌ Cancel</a>";
    echo "</div>";
    exit;
}

// Get count before deletion
$student_count = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'student'")->fetch_assoc()['count'];
$face_data_count = $conn->query("SELECT COUNT(*) as count FROM face_data")->fetch_assoc()['count'];
$attendance_count = $conn->query("SELECT COUNT(*) as count FROM attendance")->fetch_assoc()['count'];

echo "<h3>🗑️ Clearing Student Data...</h3>";

// Delete attendance records for students
echo "<p>Clearing attendance records...</p>";
$conn->query("DELETE a FROM attendance a JOIN users u ON a.student_id = u.id WHERE u.role = 'student'");
echo "<p>✅ Student attendance records cleared</p>";

// Delete face data for students
echo "<p>Clearing face registration data...</p>";
$conn->query("DELETE fd FROM face_data fd JOIN users u ON fd.student_id = u.id WHERE u.role = 'student'");
echo "<p>✅ Student face registration data cleared</p>";

// Delete student accounts
echo "<p>Clearing student accounts...</p>";
$conn->query("DELETE FROM users WHERE role = 'student'");
echo "<p>✅ Student accounts cleared</p>";

// Show summary
echo "<div style='background: rgba(40,167,69,0.1); border: 2px solid rgba(40,167,69,0.3); padding: 20px; border-radius: 15px; margin: 20px 0;'>";
echo "<h3 style='color: #28a745;'>✅ Student Data Cleared Successfully!</h3>";
echo "<p><strong>Summary of deleted items:</strong></p>";
echo "<ul style='color: white;'>";
echo "<li>👥 Student accounts deleted: $student_count</li>";
echo "<li>📸 Face registration records deleted: $face_data_count</li>";
echo "<li>📊 Attendance records deleted: $attendance_count</li>";
echo "</ul>";
echo "<p>Admin accounts have been preserved.</p>";
echo "</div>";

// Show remaining users
echo "<h3>📋 Remaining Users:</h3>";
$remaining_users = $conn->query("SELECT username, role FROM users ORDER BY id");
if($remaining_users->num_rows > 0) {
    echo "<div style='background: rgba(0,0,0,0.8); padding: 20px; border-radius: 15px; color: white;'>";
    echo "<table style='width: 100%; border-collapse: collapse;'>";
    echo "<tr style='background: rgba(255,215,0,0.2);'>";
    echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>Username</th>";
    echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>Role</th>";
    echo "</tr>";
    while($row = $remaining_users->fetch_assoc()) {
        echo "<tr>";
        echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>" . $row['username'] . "</td>";
        echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>" . $row['role'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    echo "</div>";
} else {
    echo "<p>No users remaining in system.</p>";
}

echo "<div style='margin-top: 30px;'>";
echo "<a href='REGISTER.PHP' style='background: linear-gradient(45deg, #28a745, #20c997); color: white; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; margin-right: 10px; display: inline-block;'>📝 Register New Students</a>";
echo "<a href='admin_login.php' style='background: linear-gradient(45deg, #FFD700, #FFA500); color: black; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; display: inline-block;'>🔐 Admin Login</a>";
echo "</div>";

$conn->close();
?>
