<?php
// One-time migration: adds morning_late_time and afternoon_late_time to events table
$conn = new mysqli("localhost","root","","attendance");
if($conn->connect_error){ die("DB error: ".$conn->connect_error); }

$queries = [
    "ALTER TABLE events ADD COLUMN IF NOT EXISTS morning_late_time   TIME DEFAULT NULL AFTER morning_login_end",
    "ALTER TABLE events ADD COLUMN IF NOT EXISTS afternoon_late_time  TIME DEFAULT NULL AFTER afternoon_login_end",
];

foreach($queries as $q){
    if($conn->query($q)){
        echo "<p style='color:green'>✅ $q</p>";
    } else {
        echo "<p style='color:orange'>⚠️ $q — ".$conn->error."</p>";
    }
}

echo "<br><strong>Done. <a href='admin_dashboard.php'>Go to dashboard</a></strong>";
$conn->close();
?>
