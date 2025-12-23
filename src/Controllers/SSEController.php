<?php

namespace App\Controllers;

use App\Models\Callout;
use App\Models\Attendance;
use App\Middleware\PinAuth;

class SSEController
{
    public function stream(string $slug, string $calloutId): void
    {
        $brigade = PinAuth::requireAuth($slug);

        $callout = Callout::findById((int)$calloutId);
        if (!$callout || $callout['brigade_id'] !== $brigade['id']) {
            http_response_code(404);
            return;
        }

        // SSE headers
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no'); // Disable nginx buffering

        // Disable output buffering
        if (ob_get_level()) {
            ob_end_clean();
        }

        $sseFile = __DIR__ . '/../../data/sse_' . $calloutId . '.json';
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

            // Check for updates
            if (file_exists($sseFile)) {
                $data = json_decode(file_get_contents($sseFile), true);
                if ($data && $data['timestamp'] > $lastTimestamp) {
                    $lastTimestamp = $data['timestamp'];

                    // Send updated attendance data
                    $attendance = Attendance::findByCalloutGrouped((int)$calloutId);
                    $availableMembers = Attendance::getAvailableMembers((int)$calloutId, $brigade['id']);

                    echo "event: update\n";
                    echo "data: " . json_encode([
                        'attendance' => $attendance,
                        'available_members' => $availableMembers,
                        'timestamp' => $lastTimestamp,
                    ]) . "\n\n";
                    flush();
                }
            }

            // Check if callout was submitted
            $currentCallout = Callout::findById((int)$calloutId);
            if ($currentCallout && $currentCallout['status'] !== 'active') {
                echo "event: submitted\n";
                echo "data: " . json_encode(['status' => $currentCallout['status']]) . "\n\n";
                flush();
                // Don't reconnect after submission - just end the stream
                return;
            }

            // Send keepalive
            echo ": keepalive\n\n";
            flush();

            sleep(1);
        }

        // Tell client to reconnect (only for timeout, not submission)
        echo "event: reconnect\n";
        echo "data: {}\n\n";
        flush();
    }
}
