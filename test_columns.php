<?php
$conn = new mysqli("localhost", "root", "", "attendance");

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

echo "Testing database columns...\n";

// Check if columns exist
$result = $conn->query("SHOW COLUMNS FROM users LIKE 'school_year'");
echo "school_year column: " . ($result->num_rows > 0 ? "EXISTS" : "NOT FOUND") . "\n";

$result = $conn->query("SHOW COLUMNS FROM users LIKE 'current_semester'");
echo "current_semester column: " . ($result->num_rows > 0 ? "EXISTS" : "NOT FOUND") . "\n";

// Try to add columns if they don't exist
if (!$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS school_year VARCHAR(20) DEFAULT '2024-2025'")) {
    echo "Error adding school_year: " . $conn->error . "\n";
} else {
    echo "school_year column ready\n";
}

if (!$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS current_semester ENUM('1st Semester', '2nd Semester', 'Summer') DEFAULT '1st Semester'")) {
    echo "Error adding current_semester: " . $conn->error . "\n";
} else {
    echo "current_semester column ready\n";
}

// Verify again
$result = $conn->query("SHOW COLUMNS FROM users LIKE 'school_year'");
echo "After add - school_year: " . ($result->num_rows > 0 ? "EXISTS" : "NOT FOUND") . "\n";

$result = $conn->query("SHOW COLUMNS FROM users LIKE 'current_semester'");
echo "After add - current_semester: " . ($result->num_rows > 0 ? "EXISTS" : "NOT FOUND") . "\n";

$conn->close();
echo "Test complete.\n";
?>
