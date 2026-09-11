<?php
$uid = $_GET['uid'];
$output = shell_exec("python enroll.py $uid");
echo $output;
?>