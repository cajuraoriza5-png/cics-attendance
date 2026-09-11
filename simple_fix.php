<?php
$conn = new mysqli("localhost", "root", "", "attendance");

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

echo "Simple fix for graduation issue...\n\n";

// Show current state
$result = $conn->query("SELECT COUNT(*) as count FROM users WHERE year_level = 'Graduated'");
$row = $result->fetch_assoc();
echo "Currently graduated students: " . $row['count'] . "\n";

// Revert ALL graduated students back to 4th year
$fix = $conn->query("UPDATE users SET year_level = '4th Year', role = 'student' WHERE year_level = 'Graduated'");

if ($fix) {
    echo "✅ Reverted " . $conn->affected_rows . " students from Graduated to 4th Year\n";
} else {
    echo "❌ Error: " . $conn->error . "\n";
}

// Show updated state
$result = $conn->query("SELECT COUNT(*) as count FROM users WHERE year_level = 'Graduated'");
$row = $result->fetch_assoc();
echo "Currently graduated students after fix: " . $row['count'] . "\n";

$conn->close();
echo "\nDone. All graduated students are now 4th Year.\n";
?>
