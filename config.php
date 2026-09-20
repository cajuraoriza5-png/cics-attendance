<?php
// CICS Attendance System Configuration

return [

    // ============================================================
    // DATABASE
    // ============================================================
    'database' => [

        'host' =>
            getenv('DATABASE_HOST')
            ?: 'sql107.infinityfree.com',

        'name' =>
            getenv('DATABASE_NAME')
            ?: 'if0_42609958_attendance',

        'user' =>
            getenv('DATABASE_USER')
            ?: 'if0_42609958',

        'password' =>
            getenv('DATABASE_PASSWORD')
            ?: '',

        'port' =>
            getenv('DATABASE_PORT')
            ?: '3306',
    ],


    // ============================================================
    // PYTHON FACE RECOGNITION SERVICE
    // ============================================================
    'python_service' => [

        /*
         * IMPORTANT:
         * The Python server is running on Render.
         *
         * DO NOT use:
         * http://127.0.0.1:5001
         *
         * because 127.0.0.1 on InfinityFree refers to
         * the InfinityFree server itself.
         */
        'url' =>
            getenv('PYTHON_SERVICE_URL')
            ?: 'https://cics-attendance.onrender.com',
    ],


    // ============================================================
    // APPLICATION
    // ============================================================
    'app' => [

        'env' =>
            getenv('APP_ENV')
            ?: 'production',
    ],

];

?>