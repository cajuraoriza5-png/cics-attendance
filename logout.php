<?php
session_start();

/* Unset specific session variables */
unset($_SESSION['student_id']);
unset($_SESSION['admin_id']);

/* Destroy session */
session_destroy();

/* Redirect safely */
header("Location: index.php");
exit;
?>