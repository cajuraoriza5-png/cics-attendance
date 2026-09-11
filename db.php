<?php
$conn = new mysqli("sql107.infinityfree.com", "if0_42609958", "Arriannah100924", "if0_42609958_attendance", 3306);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8");
?>