<?php

return [
    'app' => [
        'name' => 'Brigade Attendance',
        'url' => 'http://localhost:8080',
        'debug' => true,
    ],
    'database' => [
        'path' => __DIR__ . '/../data/database.sqlite',
    ],
    'session' => [
        'timeout' => 1800,  // 30 minutes for admin
        'pin_timeout' => 86400,  // 24 hours for PIN session
    ],
    'security' => [
        'rate_limit_attempts' => 5,
        'rate_limit_window' => 900,  // 15 minutes
    ],
    'email' => [
        'driver' => 'mail',  // smtp, sendmail, mail
        'host' => 'smtp.example.com',
        'port' => 587,
        'username' => '',
        'password' => '',
        'encryption' => 'tls',
        'from_address' => 'attendance@example.com',
        'from_name' => 'Brigade Attendance',
    ],
];
