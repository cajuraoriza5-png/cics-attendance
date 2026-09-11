<?php
$conn = new mysqli("localhost", "root", "", "attendance");

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

echo "Fixing incorrect graduations...\n";

// Revert students who were incorrectly graduated (3rd year students who became graduated)
$revert = $conn->prepare("
    UPDATE users
    SET year_level = '3rd Year',
        role = 'student'
    WHERE year_level = 'Graduated'
    AND role = 'alumni'
    AND id NOT IN (
        SELECT id FROM (
            SELECT id FROM users WHERE year_level = '4th Year' AND role = 'student'
        ) as temp
    )
");

if ($revert->execute()) {
    echo "✅ Reverted " . $revert->affected_rows . " incorrectly graduated students back to 3rd Year\n";
} else {
    echo "❌ Error: " . $conn->error . "\n";
}

// Alternative approach: Set all Graduated students back to their previous year level
// This is safer - we'll just set them back to 4th year if they were graduated recently
$fix = $conn->prepare("
    UPDATE users
    SET year_level = '4th Year',
        role = 'student'
    WHERE year_level = 'Graduated'
    AND role = 'alumni'
");

if ($fix->execute()) {
    echo "✅ Reverted " . $fix->affected_rows . " graduated students back to 4th Year\n";
} else {
    echo "❌ Error: " . $conn->error . "\n";
}

$conn->close();
echo "Fix complete.\n";
?>
