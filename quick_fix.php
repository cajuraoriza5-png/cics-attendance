<?php
// Quick fix for gender column issue
$conn = new mysqli("localhost","root","","attendance");

if($conn->connect_error){
    die("Connection failed: " . $conn->connect_error);
}

// Add gender column if it doesn't exist
$check = $conn->query("SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'attendance' AND TABLE_NAME = 'users' AND COLUMN_NAME = 'gender'");

if($check->num_rows == 0) {
    $conn->query("ALTER TABLE users ADD COLUMN gender VARCHAR(10) AFTER age");
    echo "Gender column added successfully!";
} else {
    echo "Gender column already exists!";
}

// Test the table structure
$result = $conn->query("DESCRIBE users");
echo "<br><br>Table Structure:<br>";
while($row = $result->fetch_assoc()) {
    echo $row['Field'] . " - " . $row['Type'] . "<br>";
}

$conn->close();

// Redirect to registration
echo "<br><br><a href='REGISTER.PHP'>Go to Registration</a>";
?>
