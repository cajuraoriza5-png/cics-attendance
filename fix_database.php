<?php
/**
 * Fix Database Schema
 * Checks and fixes student roles and year levels
 */

$conn = new mysqli("localhost","root","","attendance");

if($conn->connect_error){
    die("Connection failed: " . $conn->connect_error);
}

echo "<h2>🔧 Fixing Database</h2>";

// Check student count by role
echo "<h3>Current User Distribution:</h3>";
$result = $conn->query("SELECT role, COUNT(*) as count FROM users GROUP BY role");
echo "<table border='1' style='border-collapse: collapse; margin: 20px 0; background: white; color: black;'>";
echo "<tr><th>Role</th><th>Count</th></tr>";
while($row = $result->fetch_assoc()){
    echo "<tr><td>" . ($row['role'] == '' ? '(blank)' : $row['role']) . "</td><td>" . $row['count'] . "</td></tr>";
}
echo "</table>";

// Show all users with details
echo "<h3>All Users:</h3>";
$result = $conn->query("SELECT id, student_id, first_name, last_name, role, year_level FROM users ORDER BY id LIMIT 10");
echo "<table border='1' style='border-collapse: collapse; margin: 20px 0; background: white; color: black;'>";
echo "<tr><th>ID</th><th>Student ID</th><th>Name</th><th>Role</th><th>Year Level</th></tr>";
while($row = $result->fetch_assoc()){
    echo "<tr>";
    echo "<td>" . $row['id'] . "</td>";
    echo "<td>" . $row['student_id'] . "</td>";
    echo "<td>" . $row['first_name'] . " " . $row['last_name'] . "</td>";
    echo "<td>" . ($row['role'] == '' ? '(blank)' : $row['role']) . "</td>";
    echo "<td>" . $row['year_level'] . "</td>";
    echo "</tr>";
}
echo "</table>";

// Check year level distribution
echo "<h3>Year Level Distribution:</h3>";
$result = $conn->query("SELECT year_level, COUNT(*) as count FROM users WHERE role='student' GROUP BY year_level");
echo "<table border='1' style='border-collapse: collapse; margin: 20px 0; background: white; color: black;'>";
echo "<tr><th>Year Level</th><th>Count</th></tr>";
while($row = $result->fetch_assoc()){
    echo "<tr><td>" . $row['year_level'] . "</td><td>" . $row['count'] . "</td></tr>";
}
echo "</table>";

// Fix: Change alumni back to student if they were incorrectly graduated
echo "<h3>Fixing Issues:</h3>";
$fix = $conn->query("UPDATE users SET role='student' WHERE role='alumni' AND year_level='Graduated'");
if($fix){
    echo "<p>✅ Changed " . $conn->affected_rows . " alumni back to student role</p>";
}

// Fix: Set blank roles to student
$fix = $conn->query("UPDATE users SET role='student' WHERE (role IS NULL OR role='')");
if($fix){
    echo "<p>✅ Set " . $conn->affected_rows . " users with blank role to student</p>";
}

// Fix: Revert Graduated students to 4th Year (they were incorrectly graduated)
$fix = $conn->query("UPDATE users SET year_level='4th Year' WHERE year_level='Graduated' AND role='student'");
if($fix){
    echo "<p>✅ Reverted " . $conn->affected_rows . " students from Graduated to 4th Year</p>";
}

// Fix: Set blank year levels to 1st Year
$fix = $conn->query("UPDATE users SET year_level='1st Year' WHERE (year_level IS NULL OR year_level='') AND role='student'");
if($fix){
    echo "<p>✅ Set " . $conn->affected_rows . " students with blank year level to 1st Year</p>";
}

echo "<p><a href='student_list.php'>Go to Student List</a></p>";

$conn->close();
?>
