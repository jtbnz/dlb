<?php

declare(strict_types=1);

/**
 * PHPUnit Bootstrap File
 *
 * Sets up the testing environment for unit tests
 */

// Define test mode
define('TESTING', true);

// Set up autoloading
spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/../../src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// Load helpers
require_once __DIR__ . '/../../src/helpers.php';

// Set up test configuration
$testConfig = [
    'app' => [
        'name' => 'Test Brigade Attendance',
        'url' => 'http://localhost:8080/dlb',
        'base_path' => '/dlb',
        'debug' => true,
    ],
    'database' => [
        'path' => ':memory:', // Use in-memory SQLite for tests
    ],
    'session' => [
        'timeout' => 1800,
        'pin_timeout' => 86400,
    ],
    'security' => [
        'rate_limit_attempts' => 5,
        'rate_limit_window' => 900,
    ],
    'super_admin' => [
        'username' => 'testadmin',
        'password' => 'testpassword',
    ],
    'email' => [
        'driver' => 'mail',
        'from_address' => 'test@example.com',
        'from_name' => 'Test',
    ],
];

// Store config for tests
$GLOBALS['test_config'] = $testConfig;

// Mock the config function if needed
if (!function_exists('test_config')) {
    function test_config(string $key, $default = null) {
        global $test_config;
        $keys = explode('.', $key);
        $value = $test_config;

        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }
}
