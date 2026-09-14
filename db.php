<?php
$config = require __DIR__ . '/config.php';

$db = $config['database'];

// Support both MySQL (InfinityFree) and PostgreSQL (Render)
if (isset($_ENV['DATABASE_URL'])) {
    // PostgreSQL connection for Render
    $dbUrl = parse_url(getenv('DATABASE_URL'));
    $conn = new pg_connect(
        "host=" . $dbUrl['host'] .
        " port=" . ($dbUrl['port'] ?? 5432) .
        " dbname=" . ltrim($dbUrl['path'], '/') .
        " user=" . $dbUrl['user'] .
        " password=" . $dbUrl['pass']
    );
    if (!$conn) {
        die("PostgreSQL Connection failed");
    }
} else {
    // MySQL connection for local/InfinityFree
    $conn = new mysqli(
        $db['host'],
        $db['user'],
        $db['password'],
        $db['name'],
        $db['port']
    );

    if ($conn->connect_error) {
        die("MySQL Connection failed: " . $conn->connect_error);
    }
    $conn->set_charset("utf8");
}
?>