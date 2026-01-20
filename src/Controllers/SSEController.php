<?php

namespace App\Controllers;

use App\Models\Brigade;
use App\Models\Callout;
use App\Models\Attendance;
use App\Middleware\PinAuth;

class SSEController
{
    public function stream(string $slug, string $calloutId): void
    {
        $brigade = PinAuth::requireAuth($slug);
        $memberOrder = Brigade::getMemberOrder($brigade);

        // Validate callout ID is numeric to prevent directory traversal
        if (!is_numeric($calloutId) || (int)$calloutId <= 0) {
            http_response_code(400);
            return;
        }

        $callout = Callout::findById((int)$calloutId);
        if (!$callout || $callout['brigade_id'] !== $brigade['id']) {
            http_response_code(404);
            return;
        }

        // Release session lock to allow other requests to proceed
        // This is critical - PHP sessions block concurrent requests
        session_write_close();

        // SSE headers
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no'); // Disable nginx buffering

        // Disable output buffering
        if (ob_get_level()) {
            ob_end_clean();
        }

        $lastTimestamp = 0;

        // Send initial connection event
        echo "event: connected\n";
        echo "data: " . json_encode(['callout_id' => (int)$calloutId]) . "\n\n";
        flush();

        $maxRuntime = 30; // seconds
        $startTime = time();

        while (time() - $startTime < $maxRuntime) {
            if (connection_aborted()) {
                break;
            }

            // Check for updates in database (more reliable than file I/O)
            $notification = db()->queryOne(
                "SELECT timestamp FROM sse_notifications WHERE callout_id = ?",
                [(int)$calloutId]
            );

            if ($notification && $notification['timestamp'] > $lastTimestamp) {
                $lastTimestamp = $notification['timestamp'];

                // Send updated attendance data
                $attendance = Attendance::findByCalloutGrouped((int)$calloutId);
                $availableMembers = Attendance::getAvailableMembers((int)$calloutId, $brigade['id'], $memberOrder);

                echo "event: update\n";
                echo "data: " . json_encode([
                    'attendance' => $attendance,
                    'available_members' => $availableMembers,
                    'timestamp' => $lastTimestamp,
                ]) . "\n\n";
                flush();
            }

            // Check if callout was submitted - use a simple status query to avoid overhead
            $currentStatus = db()->queryOne(
                "SELECT status FROM callouts WHERE id = ?",
                [(int)$calloutId]
            );
            if ($currentStatus && $currentStatus['status'] !== 'active') {
                echo "event: submitted\n";
                echo "data: " . json_encode(['status' => $currentStatus['status']]) . "\n\n";
                flush();
                // Don't reconnect after submission - just end the stream
                return;
            }

            // Send keepalive
            echo ": keepalive\n\n";
            flush();

            // Optimize polling: sleep 2 seconds instead of 1 to reduce server load by ~50%
            sleep(2);
        }

        // Tell client to reconnect (only for timeout, not submission)
        echo "event: reconnect\n";
        echo "data: {}\n\n";
        flush();
    }
}
