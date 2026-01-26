<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Webhook Service
 *
 * Handles pushing data to external systems (Portal) when
 * callouts or attendance records are created/updated.
 */
class WebhookService
{
    private PDO $db;
    private array $config;

    public function __construct(PDO $db, array $config)
    {
        $this->db = $db;
        $this->config = $config;
    }

    /**
     * Check if Portal webhook is enabled
     */
    public function isPortalWebhookEnabled(): bool
    {
        $portalConfig = $this->config['webhooks']['portal'] ?? [];
        return !empty($portalConfig['enabled'])
            && !empty($portalConfig['url'])
            && !empty($portalConfig['secret']);
    }

    /**
     * Send callout data to Portal when callout is created or updated
     *
     * @param int $calloutId The callout ID
     * @param string $event Event type: 'callout.created', 'callout.updated', 'attendance.saved'
     * @return array Result with success status and details
     */
    public function pushCalloutToPortal(int $calloutId, string $event = 'callout.updated'): array
    {
        if (!$this->isPortalWebhookEnabled()) {
            return ['success' => false, 'error' => 'Portal webhook not enabled'];
        }

        $portalConfig = $this->config['webhooks']['portal'];

        // Get callout data with attendance
        $calloutData = $this->getCalloutWithAttendance($calloutId);
        if (!$calloutData) {
            return ['success' => false, 'error' => 'Callout not found'];
        }

        // Prepare payload
        $payload = [
            'event' => $event,
            'callout' => [
                'id' => $calloutData['id'],
                'icad_number' => $calloutData['icad_number'],
                'call_type' => $calloutData['call_type'],
                'call_date' => $calloutData['call_date'],
                'visible' => (bool)$calloutData['visible'],
                'status' => $calloutData['status'],
            ],
            'attendance' => $calloutData['attendance'] ?? [],
        ];

        // Send webhook
        return $this->sendWebhook(
            $portalConfig['url'],
            $portalConfig['secret'],
            $payload,
            (int)($portalConfig['timeout'] ?? 10)
        );
    }

    /**
     * Get callout with all attendance records
     */
    private function getCalloutWithAttendance(int $calloutId): ?array
    {
        // Get callout
        $stmt = $this->db->prepare("
            SELECT id, brigade_id, icad_number, call_type, DATE(created_at) as call_date, visible, status
            FROM callouts
            WHERE id = ?
        ");
        $stmt->execute([$calloutId]);
        $callout = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$callout) {
            return null;
        }

        // Get attendance
        $stmt = $this->db->prepare("
            SELECT
                a.member_id,
                a.status,
                p.name as position,
                t.name as truck
            FROM attendance a
            LEFT JOIN positions p ON a.position_id = p.id
            LEFT JOIN trucks t ON a.truck_id = t.id
            WHERE a.callout_id = ?
        ");
        $stmt->execute([$calloutId]);
        $callout['attendance'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $callout;
    }

    /**
     * Send HTTP webhook request
     */
    private function sendWebhook(string $url, string $secret, array $payload, int $timeout): array
    {
        $jsonPayload = json_encode($payload);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $secret,
                'X-Webhook-Source: dlb',
                'X-Webhook-Event: ' . ($payload['event'] ?? 'unknown'),
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->logWebhookError($url, $payload['event'] ?? '', $error);
            return ['success' => false, 'error' => $error];
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $this->logWebhookError($url, $payload['event'] ?? '', "HTTP {$httpCode}: {$response}");
            return ['success' => false, 'error' => "HTTP {$httpCode}", 'response' => $response];
        }

        $this->logWebhookSuccess($url, $payload['event'] ?? '', $payload['callout']['id'] ?? 0);

        return [
            'success' => true,
            'http_code' => $httpCode,
            'response' => json_decode($response, true) ?? $response,
        ];
    }

    /**
     * Log successful webhook (to error_log for debugging)
     */
    private function logWebhookSuccess(string $url, string $event, int $calloutId): void
    {
        error_log("Webhook sent to {$url}: event={$event}, callout_id={$calloutId}");
    }

    /**
     * Log webhook error
     */
    private function logWebhookError(string $url, string $event, string $error): void
    {
        error_log("Webhook error to {$url}: event={$event}, error={$error}");
    }
}
