<?php
/**
 * Complete Database Setup for Enhanced CICS Attendance System
 * Creates fresh database structure with all required tables and columns
 */

$conn = new mysqli("localhost","root","");

if($conn->connect_error){
    die("Connection failed: " . $conn->connect_error);
}

echo "<h2>🗄️ Setting Up New Database Structure</h2>";
echo "<p style='color: #FFD700;'>Creating fresh database structure for enhanced attendance system...</p>";

// Drop existing database and recreate
echo "<h3>🔄 Resetting Database</h3>";
$conn->query("DROP DATABASE IF EXISTS attendance");
$conn->query("CREATE DATABASE attendance");
$conn->select_db("attendance");
echo "<p>✅ Database recreated successfully</p>";

// Create users table with all required fields
echo "<h3>👥 Creating Users Table</h3>";
$users_sql = "
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    student_id VARCHAR(20) UNIQUE NOT NULL,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    age INT NOT NULL,
    gender VARCHAR(10) DEFAULT 'Other',
    email VARCHAR(100) UNIQUE NOT NULL,
    course VARCHAR(50) NOT NULL,
    year_level VARCHAR(20) NOT NULL,
    section VARCHAR(20) NOT NULL,
    face_registered TINYINT(1) DEFAULT 0,
    total_penalty DECIMAL(10,2) DEFAULT 0.00,
    role ENUM('admin', 'student') DEFAULT 'student',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";

if($conn->query($users_sql)) {
    echo "<p>✅ Users table created successfully</p>";
} else {
    echo "<p>❌ Error creating users table: " . $conn->error . "</p>";
}

// Create events table
echo "<h3>📅 Creating Events Table</h3>";
$events_sql = "
CREATE TABLE events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_name VARCHAR(100) NOT NULL,
    event_date DATE NOT NULL,
    morning_start TIME DEFAULT '08:00:00',
    morning_end TIME DEFAULT '08:30:00',
    afternoon_start TIME DEFAULT '13:00:00',
    afternoon_end TIME DEFAULT '13:30:00',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_event_date (event_date)
)";

if($conn->query($events_sql)) {
    echo "<p>✅ Events table created successfully</p>";
} else {
    echo "<p>❌ Error creating events table: " . $conn->error . "</p>";
}

// Create attendance table
echo "<h3>📊 Creating Attendance Table</h3>";
$attendance_sql = "
CREATE TABLE attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    event_id INT NOT NULL,
    date DATE NOT NULL,
    morning_in TIME,
    morning_out TIME,
    morning_status ENUM('Present', 'Late', 'Absent') DEFAULT 'Absent',
    afternoon_in TIME,
    afternoon_out TIME,
    afternoon_status ENUM('Present', 'Late', 'Absent') DEFAULT 'Absent',
    penalty DECIMAL(10,2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    UNIQUE KEY unique_attendance (student_id, event_id, date)
)";

if($conn->query($attendance_sql)) {
    echo "<p>✅ Attendance table created successfully</p>";
} else {
    echo "<p>❌ Error creating attendance table: " . $conn->error . "</p>";
}

// Create face_data table
echo "<h3>📸 Creating Face Data Table</h3>";
$face_data_sql = "
CREATE TABLE face_data (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    face_image VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
)";

if($conn->query($face_data_sql)) {
    echo "<p>✅ Face data table created successfully</p>";
} else {
    echo "<p>❌ Error creating face data table: " . $conn->error . "</p>";
}

// Create payments table (optional)
echo "<h3>💰 Creating Payments Table</h3>";
$payments_sql = "
CREATE TABLE payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_method ENUM('GCash', 'Cash', 'Bank Transfer') NOT NULL,
    payment_date DATE NOT NULL,
    status ENUM('Pending', 'Completed', 'Failed') DEFAULT 'Pending',
    reference_number VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
)";

if($conn->query($payments_sql)) {
    echo "<p>✅ Payments table created successfully</p>";
} else {
    echo "<p>❌ Error creating payments table: " . $conn->error . "</p>";
}

// Create default admin account
echo "<h3>👤 Creating Default Admin Account</h3>";
$admin_password = password_hash("admin123", PASSWORD_DEFAULT);
$insert_admin = "
INSERT INTO users (username, password, first_name, last_name, age, gender, email, course, year_level, section, role) 
VALUES ('admin', '$admin_password', 'System', 'Administrator', 30, 'Other', 'admin@cics.edu', 'N/A', 'N/A', 'N/A', 'admin')
";

if($conn->query($insert_admin)) {
    echo "<p>✅ Default admin account created (username: admin, password: admin123)</p>";
} else {
    echo "<p>❌ Error creating admin account: " . $conn->error . "</p>";
}

// Create sample event for testing
echo "<h3>📅 Creating Sample Event</h3>";
$sample_event = "
INSERT INTO events (event_name, event_date, morning_start, morning_end, afternoon_start, afternoon_end) 
VALUES ('Sample Event', CURDATE(), '08:00:00', '08:30:00', '13:00:00', '13:30:00')
";

if($conn->query($sample_event)) {
    echo "<p>✅ Sample event created for today</p>";
} else {
    echo "<p>❌ Error creating sample event: " . $conn->error . "</p>";
}

// Show final database structure
echo "<h3>📋 Final Database Structure</h3>";
$tables = ['users', 'events', 'attendance', 'face_data', 'payments'];

foreach($tables as $table) {
    echo "<div style='background: rgba(0,0,0,0.8); padding: 20px; border-radius: 15px; margin-bottom: 20px;'>";
    echo "<h4 style='color: #FFD700; margin-bottom: 15px;'>📋 Table: $table</h4>";
    
    $result = $conn->query("DESCRIBE $table");
    echo "<table style='width: 100%; border-collapse: collapse; color: white;'>";
    echo "<tr style='background: rgba(255,215,0,0.2);'>";
    echo "<th style='padding: 8px; border: 1px solid rgba(255,215,0,0.3);'>Field</th>";
    echo "<th style='padding: 8px; border: 1px solid rgba(255,215,0,0.3);'>Type</th>";
    echo "<th style='padding: 8px; border: 1px solid rgba(255,215,0,0.3);'>Null</th>";
    echo "<th style='padding: 8px; border: 1px solid rgba(255,215,0,0.3);'>Key</th>";
    echo "<th style='padding: 8px; border: 1px solid rgba(255,215,0,0.3);'>Default</th>";
    echo "</tr>";
    
    while($row = $result->fetch_assoc()){
        echo "<tr>";
        echo "<td style='padding: 8px; border: 1px solid rgba(255,215,0,0.1);'>" . $row['Field'] . "</td>";
        echo "<td style='padding: 8px; border: 1px solid rgba(255,215,0,0.1);'>" . $row['Type'] . "</td>";
        echo "<td style='padding: 8px; border: 1px solid rgba(255,215,0,0.1);'>" . $row['Null'] . "</td>";
        echo "<td style='padding: 8px; border: 1px solid rgba(255,215,0,0.1);'>" . $row['Key'] . "</td>";
        echo "<td style='padding: 8px; border: 1px solid rgba(255,215,0,0.1);'>" . $row['Default'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    echo "</div>";
}

// Create faces directory
echo "<h3>📁 Creating Directories</h3>";
if(!is_dir('faces')) {
    mkdir('faces', 0755, true);
    echo "<p>✅ Faces directory created</p>";
} else {
    echo "<p>✅ Faces directory already exists</p>";
}

// Success message
echo "<div style='background: rgba(40,167,69,0.1); border: 2px solid rgba(40,167,69,0.3); padding: 20px; border-radius: 15px; margin: 20px 0;'>";
echo "<h3 style='color: #28a745;'>🎉 Database Setup Complete!</h3>";
echo "<p><strong>What's been created:</strong></p>";
echo "<ul style='color: white;'>";
echo "<li>✅ Fresh database with optimized structure</li>";
echo "<li>✅ Users table with all required fields (username, student_id, gender, etc.)</li>";
echo "<li>✅ Events table for attendance scheduling</li>";
echo "<li>✅ Attendance table with proper relationships</li>";
echo "<li>✅ Face data table for biometric data</li>";
echo "<li>✅ Payments table for penalty management</li>";
echo "<li>✅ Default admin account (admin/admin123)</li>";
echo "<li>✅ Sample event for testing</li>";
echo "</ul>";
echo "</div>";

// Next steps
echo "<div style='margin-top: 30px;'>";
echo "<h3>🚀 Next Steps:</h3>";
echo "<a href='admin_login.php' style='background: linear-gradient(45deg, #FFD700, #FFA500); color: black; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; margin-right: 10px; display: inline-block;'>🔐 Admin Login</a>";
echo "<a href='REGISTER.PHP' style='background: linear-gradient(45deg, #28a745, #20c997); color: white; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; margin-right: 10px; display: inline-block;'>📝 Register Student</a>";
echo "<a href='student_login.php' style='background: linear-gradient(45deg, #007bff, #0056b3); color: white; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; display: inline-block;'>👤 Student Login</a>";
echo "</div>";

echo "<div style='background: rgba(255,193,7,0.1); border: 2px solid rgba(255,215,0,0.3); padding: 20px; border-radius: 15px; margin-top: 20px;'>";
echo "<h3 style='color: #FFD700;'>🔑 Login Credentials:</h3>";
echo "<p style='color: white;'><strong>Admin:</strong> username: <code>admin</code>, password: <code>admin123</code></p>";
echo "<p style='color: white;'><strong>Students:</strong> Register new accounts using the registration form</p>";
echo "</div>";

$conn->close();
?>
