<?php
/**
 * Check and Manage Existing User Data
 */

$conn = new mysqli("localhost","root","","attendance");

if($conn->connect_error){
    die("Connection failed: " . $conn->connect_error);
}

echo "<h2>🔍 Check Existing User Data</h2>";

// Show all existing users
echo "<h3>📋 Current Users in Database:</h3>";
$users = $conn->query("SELECT id, username, student_id, email, first_name, last_name, role FROM users ORDER BY id");

if($users->num_rows > 0) {
    echo "<div style='background: rgba(0,0,0,0.8); padding: 20px; border-radius: 15px; color: white; margin-bottom: 20px;'>";
    echo "<table style='width: 100%; border-collapse: collapse;'>";
    echo "<tr style='background: rgba(255,215,0,0.2);'>";
    echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>ID</th>";
    echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>Username</th>";
    echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>Student ID</th>";
    echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>Email</th>";
    echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>Name</th>";
    echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>Role</th>";
    echo "<th style='padding: 10px; border: 1px solid rgba(255,215,0,0.3);'>Action</th>";
    echo "</tr>";
    
    while($row = $users->fetch_assoc()) {
        echo "<tr>";
        echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>" . $row['id'] . "</td>";
        echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>" . $row['username'] . "</td>";
        echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>" . $row['student_id'] . "</td>";
        echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>" . $row['email'] . "</td>";
        echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>" . $row['first_name'] . ' ' . $row['last_name'] . "</td>";
        echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>" . $row['role'] . "</td>";
        echo "<td style='padding: 10px; border: 1px solid rgba(255,215,0,0.1);'>";
        if($row['role'] == 'student') {
            echo "<a href='delete_user.php?id=" . $row['id'] . "' style='color: #dc3545; text-decoration: none;'>🗑️ Delete</a>";
        } else {
            echo "Admin";
        }
        echo "</td>";
        echo "</tr>";
    }
    echo "</table>";
    echo "</div>";
} else {
    echo "<p>✅ No users found in database</p>";
}

// Check for potential duplicates
echo "<h3>🔍 Check for Potential Duplicates:</h3>";

// Check duplicate usernames
$duplicate_usernames = $conn->query("SELECT username, COUNT(*) as count FROM users GROUP BY username HAVING COUNT(*) > 1");
if($duplicate_usernames->num_rows > 0) {
    echo "<p style='color: #ff6b6b;'>⚠️ Found duplicate usernames!</p>";
    while($row = $duplicate_usernames->fetch_assoc()) {
        echo "<p>Username '{$row['username']}' appears {$row['count']} times</p>";
    }
}

// Check duplicate student IDs
$duplicate_student_ids = $conn->query("SELECT student_id, COUNT(*) as count FROM users GROUP BY student_id HAVING COUNT(*) > 1");
if($duplicate_student_ids->num_rows > 0) {
    echo "<p style='color: #ff6b6b;'>⚠️ Found duplicate student IDs!</p>";
    while($row = $duplicate_student_ids->fetch_assoc()) {
        echo "<p>Student ID '{$row['student_id']}' appears {$row['count']} times</p>";
    }
}

// Check duplicate emails
$duplicate_emails = $conn->query("SELECT email, COUNT(*) as count FROM users GROUP BY email HAVING COUNT(*) > 1");
if($duplicate_emails->num_rows > 0) {
    echo "<p style='color: #ff6b6b;'>⚠️ Found duplicate emails!</p>";
    while($row = $duplicate_emails->fetch_assoc()) {
        echo "<p>Email '{$row['email']}' appears {$row['count']} times</p>";
    }
}

if($duplicate_usernames->num_rows == 0 && $duplicate_student_ids->num_rows == 0 && $duplicate_emails->num_rows == 0) {
    echo "<p style='color: #51cf66;'>✅ No duplicates found</p>";
}

// Action buttons
echo "<div style='margin-top: 30px;'>";
echo "<h3>🛠️ Available Actions:</h3>";
echo "<a href='REGISTER.PHP' style='background: linear-gradient(45deg, #28a745, #20c997); color: white; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; margin-right: 10px; display: inline-block;'>📝 Register New Student</a>";
echo "<a href='clear_students.php' style='background: linear-gradient(45deg, #dc3545, #c82333); color: white; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; margin-right: 10px; display: inline-block;'>🗑️ Clear All Students</a>";
echo "<a href='admin_dashboard.php' style='background: linear-gradient(45deg, #FFD700, #FFA500); color: black; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; display: inline-block;'>📊 Admin Dashboard</a>";
echo "</div>";

echo "<div style='background: rgba(255,193,7,0.1); border: 2px solid rgba(255,215,0,0.3); padding: 20px; border-radius: 15px; margin-top: 20px;'>";
echo "<h3 style='color: #FFD700;'>💡 Troubleshooting Tips:</h3>";
echo "<ul style='color: white;'>";
echo "<li>If you're getting 'Username, Student ID, or Email already exists' error, check the list above for existing users</li>";
echo "<li>Use the 'Delete' link to remove specific student accounts</li>";
echo "<li>Use 'Clear All Students' to remove all student accounts (keeps admin accounts)</li>";
echo "<li>Make sure to use unique values for username, student ID, and email when registering</li>";
echo "</ul>";
echo "</div>";

$conn->close();
?>
