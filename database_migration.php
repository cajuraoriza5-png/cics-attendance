<?php
/**
 * Database Migration Script
 * Adds missing columns to the users table for the enhanced registration form
 */

$conn = new mysqli("localhost","root","","attendance");

if($conn->connect_error){
    die("Connection failed: " . $conn->connect_error);
}

echo "<h2>Database Migration</h2>";

// Check if gender column exists
$check_gender = $conn->query("SHOW COLUMNS FROM users LIKE 'gender'");
if($check_gender->num_rows == 0){
    echo "<p>Adding 'gender' column...</p>";
    $conn->query("ALTER TABLE users ADD COLUMN gender VARCHAR(10) AFTER age");
    echo "<p>✅ Gender column added successfully</p>";
} else {
    echo "<p>✅ Gender column already exists</p>";
}

// Check if face_image column exists in face_data table
$check_face_image = $conn->query("SHOW COLUMNS FROM face_data LIKE 'face_image'");
if($check_face_image->num_rows == 0){
    echo "<p>Adding 'face_image' column to face_data table...</p>";
    $conn->query("ALTER TABLE face_data ADD COLUMN face_image VARCHAR(255) AFTER student_id");
    echo "<p>✅ Face image column added successfully</p>";
} else {
    echo "<p>✅ Face image column already exists</p>";
}

// Update existing face_data records to use student_id_1.jpg format
echo "<p>Updating face_data records...</p>";
$update_result = $conn->query("UPDATE face_data SET face_image = CONCAT(student_id, '_', id, '.jpg') WHERE face_image IS NULL OR face_image = ''");
if($update_result){
    echo "<p>✅ Face data records updated</p>";
}

// Show current database structure
echo "<h3>Current Users Table Structure:</h3>";
$result = $conn->query("DESCRIBE users");
echo "<table border='1' style='border-collapse: collapse; margin: 20px 0;'>";
echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th></tr>";
while($row = $result->fetch_assoc()){
    echo "<tr>";
    echo "<td>" . $row['Field'] . "</td>";
    echo "<td>" . $row['Type'] . "</td>";
    echo "<td>" . $row['Null'] . "</td>";
    echo "<td>" . $row['Key'] . "</td>";
    echo "</tr>";
}
echo "</table>";

echo "<h3>Current Face Data Table Structure:</h3>";
$result = $conn->query("DESCRIBE face_data");
echo "<table border='1' style='border-collapse: collapse; margin: 20px 0;'>";
echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th></tr>";
while($row = $result->fetch_assoc()){
    echo "<tr>";
    echo "<td>" . $row['Field'] . "</td>";
    echo "<td>" . $row['Type'] . "</td>";
    echo "<td>" . $row['Null'] . "</td>";
    echo "<td>" . $row['Key'] . "</td>";
    echo "</tr>";
}
echo "</table>";

echo "<h3>Migration Complete!</h3>";
echo "<p><a href='REGISTER.PHP'>Go to Registration Form</a></p>";

$conn->close();
?>
