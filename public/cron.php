<?php
/**
 * FENZ Data Fetcher Cron Endpoint
 *
 * This script can be called by a server cron job to update callouts with FENZ incident data.
 * It bypasses rate limiting since it's meant to run on a schedule.
 *
 * Example cron entry (run every hour):
 * 0 * * * * curl -s http://your-domain.com/cron.php?token=YOUR_SECRET_TOKEN
 *
 * Set the CRON_TOKEN environment variable or define it in config.php
 */

require_once __DIR__ . '/../src/bootstrap.php';

use App\Services\FenzFetcher;

// Simple token-based authentication
$expectedToken = getenv('CRON_TOKEN') ?: 'change-this-token-in-production';
$providedToken = $_GET['token'] ?? '';

// Allow CLI execution without token
$isCli = php_sapi_name() === 'cli';

if (!$isCli && $providedToken !== $expectedToken) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid token']);
    exit;
}

// Set JSON content type for web requests
if (!$isCli) {
    header('Content-Type: application/json');
}

try {
    $results = FenzFetcher::updateAllBrigades();

    $totalUpdated = array_sum(array_column($results, 'updated'));

    $response = [
        'success' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'total_updated' => $totalUpdated,
        'brigades' => $results,
    ];

    if ($isCli) {
        echo "FENZ Data Fetch Complete\n";
        echo "Timestamp: " . $response['timestamp'] . "\n";
        echo "Total Updated: " . $totalUpdated . "\n";
        foreach ($results as $brigade) {
            echo "  - {$brigade['brigade_name']}: {$brigade['updated']} callouts updated\n";
        }
    } else {
        echo json_encode($response, JSON_PRETTY_PRINT);
    }
} catch (Exception $e) {
    $error = [
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s'),
    ];

    if ($isCli) {
        echo "Error: " . $e->getMessage() . "\n";
        exit(1);
    } else {
        http_response_code(500);
        echo json_encode($error);
    }
}
