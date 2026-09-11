<?php
$conn = new mysqli('localhost', 'root', '', 'attendance');
if($conn->connect_error) {
    die('Connection failed: ' . $conn->connect_error);
}

$result = $conn->query('DESCRIBE events');
if(!$result) {
    die('Query failed: ' . $conn->error);
}

echo "<h3>Events Table Structure:</h3><table border='1'><tr><th>#</th><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
$count = 0;
$columns = [];
while($row = $result->fetch_assoc()) {
    $count++;
    $columns[] = $row['Field'];
    echo "<tr><td>$count</td><td>{$row['Field']}</td><td>{$row['Type']}</td><td>{$row['Null']}</td><td>{$row['Key']}</td><td>{$row['Default']}</td></tr>";
}
echo "</table>";
echo "<p>Total columns: $count</p>";

echo "<h3>INSERT Statement Columns:</h3>";
$insert_cols = "event_name,event_description,venue,event_banner,event_type,start_date,end_date,event_date,course,qr_code,mobile_scan_link,event_history,late_penalty,absent_penalty,morning_login_start,morning_login_end,morning_late_time,morning_logout_start,morning_logout_end,afternoon_login_start,afternoon_login_end,afternoon_late_time,afternoon_logout_start,afternoon_logout_end,morning_start,morning_end,afternoon_start,afternoon_end";
$insert_array = explode(",", $insert_cols);
echo "<p>" . count($insert_array) . " columns:</p>";
echo "<ul>";
foreach($insert_array as $col) {
    echo "<li>$col</li>";
}
echo "</ul>";

echo "<h3>VALUES Clause:</h3>";
$values_clause = "?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?";
$placeholder_count = substr_count($values_clause, "?");
echo "<p>$placeholder_count placeholders (?)</p>";
echo "<p>$values_clause</p>";

echo "<p><strong>Match: " . (count($insert_array) == $placeholder_count ? "YES" : "NO") . "</strong></p>";

$conn->close();
?>
