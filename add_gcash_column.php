<?php
$conn = new mysqli('localhost', 'root', '', 'attendance');
if($conn->connect_error) {
    die('Connection failed: ' . $conn->connect_error);
}

// Add gcash_number column to admin table
$sql = "ALTER TABLE admin ADD COLUMN gcash_number VARCHAR(15) DEFAULT NULL AFTER full_name";

if($conn->query($sql)) {
    echo "Successfully added gcash_number column to admin table.<br>";
} else {
    echo "Error adding gcash_number column: " . $conn->error . "<br>";
}

// Update the default admin with a sample GCash number
$sql = "UPDATE admin SET gcash_number = '09123456789' WHERE username = 'admin'";
if($conn->query($sql)) {
    echo "Updated admin account with sample GCash number: 09123456789<br>";
} else {
    echo "Error updating admin GCash number: " . $conn->error . "<br>";
}

$conn->close();
?>
