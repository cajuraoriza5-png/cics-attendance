<?php
// Configuration file - load from environment variables
return [
    'database' => [
        'host' => getenv('DATABASE_HOST') ?: 'localhost',
        'name' => getenv('DATABASE_NAME') ?: 'attendance',
        'user' => getenv('DATABASE_USER') ?: 'root',
        'password' => getenv('DATABASE_PASSWORD') ?: '',
        'port' => getenv('DATABASE_PORT') ?: '3306',
    ],
    'python_service' => [
        'url' => getenv('PYTHON_SERVICE_URL') ?: 'http://127.0.0.1:5001',
    ],
    'app' => [
        'env' => getenv('APP_ENV') ?: 'development',
    ]
];
