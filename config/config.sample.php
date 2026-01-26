<?php

/**
 * Configuration Sample File
 *
 * Copy this file to config.php and update the values for your environment.
 * The config.php file is ignored by git to protect your settings.
 *
 * On your server:
 *   cp config.sample.php config.php
 *   # Then edit config.php with your production values
 */

return [
    'app' => [
        'name' => 'Brigade Attendance',
        'url' => 'https://example.com/dlb',     // Your full URL
        'base_path' => '/dlb',                   // Subdirectory path (no trailing slash), or '' for root
        'debug' => false,                        // Set to false in production
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
    'super_admin' => [
        'username' => 'superadmin',           // Change this!
        'password' => 'changeme123',          // Change this immediately!
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
    'webhooks' => [
        'portal' => [
            'enabled' => false,
            'url' => 'https://example.com/pp/api/webhook/attendance',
            'secret' => '', // Shared secret - must match Portal's dlb.webhook_secret
            'timeout' => 10, // seconds
        ],
    ],
];
