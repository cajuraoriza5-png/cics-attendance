<?php
/**
 * Database Reset Script
 * Clears all data from the CICS Attendance System while preserving table structure
 */

$conn = new mysqli("localhost","root","","attendance");

if($conn->connect_error){
    die("Connection failed: " . $conn->connect_error);
}

echo "<h2>🔄 Database Reset - CICS Attendance System</h2>";
echo "<p style='color: red; font-weight: bold;'>⚠️ WARNING: This will delete ALL data in the system!</p>";

// Check if confirmation was provided
if(!isset($_GET['confirm']) || $_GET['confirm'] !== 'RESET_ALL_DATA'){
    echo "<div style='background: rgba(220,53,69,0.1); border: 2px solid rgba(220,53,69,0.3); padding: 20px; border-radius: 15px; margin: 20px 0;'>";
    echo "<h3 style='color: #dc3545;'>⚠️ Confirm Database Reset</h3>";
    echo "<p>This action will permanently delete:</p>";
    echo "<ul style='color: white;'>";
    echo "<li>👥 All user accounts (students and admins)</li>";
    echo "<li>📸 All face registration data</li>";
    echo "<li>📊 All attendance records</li>";
    echo "<li>📅 All event records</li>";
    echo "<li>💰 All payment records</li>";
    echo "</ul>";
    echo "<p><strong>This action cannot be undone!</strong></p>";
    echo "<a href='reset_database.php?confirm=RESET_ALL_DATA' style='background: linear-gradient(45deg, #dc3545, #c82333); color: white; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold;'>⚠️ Yes, Reset Everything</a>";
    echo " <a href='admin_login.php' style='background: rgba(255,255,255,0.1); color: white; padding: 15px 30px; text-decoration: none; border-radius: 25px; margin-left: 10px;'>❌ Cancel</a>";
    echo "</div>";
    exit;
}

echo "<h3>🗑️ Clearing Database Tables...</h3>";

// Clear attendance records first (due to foreign key constraints)
echo "<p>Clearing attendance records...</p>";
$conn->query("DELETE FROM attendance");
echo "<p>✅ Attendance records cleared</p>";

// Clear face data
echo "<p>Clearing face registration data...</p>";
$conn->query("DELETE FROM face_data");
echo "<p>✅ Face registration data cleared</p>";

// Clear events
echo "<p>Clearing event records...</p>";
$conn->query("DELETE FROM events");
echo "<p>✅ Event records cleared</p>";

// Clear users (except keep one admin account for login)
echo "<p>Clearing user accounts...</p>";
$conn->query("DELETE FROM users WHERE role = 'student'");
echo "<p>✅ Student accounts cleared</p>";

// Clear any admin accounts except one
$conn->query("DELETE FROM users WHERE role = 'admin' AND id > 1");
echo "<p>✅ Extra admin accounts cleared</p>";

// Clear payments if table exists
$check_payments = $conn->query("SHOW TABLES LIKE 'payments'");
if($check_payments->num_rows > 0){
    echo "<p>Clearing payment records...</p>";
    $conn->query("DELETE FROM payments");
    echo "<p>✅ Payment records cleared</p>";
}

// Reset auto-increment values
echo "<p>Resetting auto-increment values...</p>";
$conn->query("ALTER TABLE attendance AUTO_INCREMENT = 1");
$conn->query("ALTER TABLE face_data AUTO_INCREMENT = 1");
$conn->query("ALTER TABLE events AUTO_INCREMENT = 1");
$conn->query("ALTER TABLE users AUTO_INCREMENT = 1");
if($check_payments->num_rows > 0){
    $conn->query("ALTER TABLE payments AUTO_INCREMENT = 1");
}
echo "<p>✅ Auto-increment values reset</p>";

// Create default admin account if it doesn't exist
$check_admin = $conn->query("SELECT id FROM users WHERE username = 'admin'");
if($check_admin->num_rows == 0){
    echo "<p>Creating default admin account...</p>";
    $admin_password = password_hash("admin123", PASSWORD_DEFAULT);
    $conn->query("INSERT INTO users (username, password, first_name, last_name, role) VALUES ('admin', '$admin_password', 'System', 'Administrator', 'admin')");
    echo "<p>✅ Default admin account created (username: admin, password: admin123)</p>";
} else {
    echo "<p>✅ Admin account already exists</p>";
}

// Create sample event for testing
echo "<p>Creating sample event for testing...</p>";
$conn->query("INSERT INTO events (event_name, event_date, morning_start, morning_end, afternoon_start, afternoon_end) VALUES ('Sample Event', CURDATE(), '08:00:00', '08:30:00', '13:00:00', '13:30:00')");
echo "<p>✅ Sample event created for today</p>";

// Show current database status
echo "<h3>📊 Database Status After Reset</h3>";

$tables = ['users', 'events', 'attendance', 'face_data'];
foreach($tables as $table){
    $count = $conn->query("SELECT COUNT(*) as count FROM $table")->fetch_assoc()['count'];
    echo "<p>📋 $table: $count records</p>";
}

// Clean up face images directory
echo "<h3>🗂️ Cleaning Face Images Directory...</h3>";
$faces_dir = 'faces';
if(is_dir($faces_dir)){
    $files = glob($faces_dir . '/*');
    foreach($files as $file){
        if(is_file($file)){
            unlink($file);
        }
    }
    echo "<p>✅ Face images directory cleared</p>";
} else {
    echo "<p>ℹ️ Face images directory not found</p>";
}

echo "<div style='background: rgba(40,167,69,0.1); border: 2px solid rgba(40,167,69,0.3); padding: 20px; border-radius: 15px; margin: 20px 0;'>";
echo "<h3 style='color: #28a745;'>✅ Database Reset Complete!</h3>";
echo "<p>The system has been successfully reset to a clean state.</p>";
echo "<p><strong>Next Steps:</strong></p>";
echo "<ul style='color: white;'>";
echo "<li>🔐 Login with admin account: username: <strong>admin</strong>, password: <strong>admin123</strong></li>";
echo "<li>👤 Register new student accounts</li>";
echo "<li>📸 Set up face registration for students</li>";
echo "<li>📅 Create events for attendance tracking</li>";
echo "</ul>";
echo "<p><a href='admin_login.php' style='background: linear-gradient(45deg, #28a745, #20c997); color: white; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold;'>🚀 Go to Admin Login</a></p>";
echo "</div>";

$conn->close();
?>
