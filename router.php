<?php
/**
 * PHP Development Server Router for Subdirectory Deployment
 *
 * This router simulates the subdirectory deployment (e.g., /dlb/)
 * that is commonly used in production.
 *
 * Usage: php -S localhost:8080 router.php
 */

$uri = $_SERVER['REQUEST_URI'];
$basePath = '/dlb';

// Handle requests to the base path
if (strpos($uri, $basePath) === 0) {
    // Strip the base path for processing
    $path = substr($uri, strlen($basePath)) ?: '/';

    // Check if it's a static file
    $staticPath = __DIR__ . '/public' . parse_url($path, PHP_URL_PATH);
    if (is_file($staticPath)) {
        // Serve static file directly
        return false;
    }

    // Update REQUEST_URI to include base path for the application
    $_SERVER['REQUEST_URI'] = $uri;

    // Include the main index.php
    require __DIR__ . '/public/index.php';
    return true;
}

// Redirect root to base path
if ($uri === '/' || $uri === '') {
    header('Location: ' . $basePath);
    exit;
}

// 404 for other paths
http_response_code(404);
echo 'Not Found';
return true;
