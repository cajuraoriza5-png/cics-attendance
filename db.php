<?php

$config_file = __DIR__ . "/db_config.php";

if (file_exists($config_file)) {
    $config = require $config_file;

    $conn = new mysqli(
        $config['host'],
        $config['username'],
        $config['password'],
        $config['database'],
        $config['port']
    );
} else {
    $conn = new mysqli("localhost", "root", "", "attendance");
}

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>