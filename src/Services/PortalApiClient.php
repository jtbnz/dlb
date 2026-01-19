<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Portal API Client
 *
 * Handles communication with the Puke Portal API for leave data synchronization.
 */
class PortalApiClient
{
    private string $apiUrl;
    private string $apiToken;
    private int $timeout;
    private int $cacheTtl;
    private static array $cache = [];

    public function __construct()
    {
        $config = config('portal');
        $this->apiUrl = rtrim($config['api_url'] ?? '', '/');
        $this->apiToken = $config['api_token'] ?? '';
        $this->timeout = $config['timeout'] ?? 10;
        $this->cacheTtl = $config['cache_ttl'] ?? 300;
    }

    /**
     * Check if Portal integration is enabled and configured
     */
    public static function isEnabled(): bool
    {
        $config = config('portal');
        return ($config['enabled'] ?? false)
            && !empty($config['api_url'])
            && !empty($config['api_token']);
    }

    /**
     * Get approved leave for a specific date
     *
     * @param string $date Date in Y-m-d format
     * @return array Array of leave records with member info
     */
    public function getLeaveForDate(string $date): array
    {
        $cacheKey = "leave_{$date}";

        // Check cache first
        if (isset(self::$cache[$cacheKey])) {
            $cached = self::$cache[$cacheKey];
            if (time() - $cached['time'] < $this->cacheTtl) {
                return $cached['data'];
            }
        }

        try {
            $response = $this->request('GET', '/api/leave', [
                'date' => $date,
                'status' => 'approved'
            ]);

            $data = $response['leave'] ?? [];

            // Cache the result
            self::$cache[$cacheKey] = [
                'time' => time(),
                'data' => $data
            ];

            return $data;
        } catch (Exception $e) {
            // Log error but don't fail - return cached data if available or empty array
            error_log("Portal API error: " . $e->getMessage());

            if (isset(self::$cache[$cacheKey])) {
                return self::$cache[$cacheKey]['data'];
            }

            return [];
        }
    }

    /**
     * Get leave for a date range
     *
     * @param string $fromDate Start date in Y-m-d format
     * @param string $toDate End date in Y-m-d format
     * @return array Array of leave records
     */
    public function getLeaveForDateRange(string $fromDate, string $toDate): array
    {
        $cacheKey = "leave_{$fromDate}_{$toDate}";

        // Check cache first
        if (isset(self::$cache[$cacheKey])) {
            $cached = self::$cache[$cacheKey];
            if (time() - $cached['time'] < $this->cacheTtl) {
                return $cached['data'];
            }
        }

        try {
            $response = $this->request('GET', '/api/leave', [
                'from' => $fromDate,
                'to' => $toDate,
                'status' => 'approved'
            ]);

            $data = $response['leave'] ?? [];

            // Cache the result
            self::$cache[$cacheKey] = [
                'time' => time(),
                'data' => $data
            ];

            return $data;
        } catch (Exception $e) {
            error_log("Portal API error: " . $e->getMessage());

            if (isset(self::$cache[$cacheKey])) {
                return self::$cache[$cacheKey]['data'];
            }

            return [];
        }
    }

    /**
     * Create a leave request in Portal
     *
     * @param int $memberId Member ID (Portal member ID)
     * @param string $date Leave date in Y-m-d format
     * @param string $reason Reason for leave
     * @return array|null Created leave record or null on failure
     */
    public function createLeave(int $memberId, string $date, string $reason = ''): ?array
    {
        try {
            $response = $this->request('POST', '/api/leave', [
                'member_id' => $memberId,
                'date' => $date,
                'reason' => $reason,
                'source' => 'dlb'  // Mark as coming from DLB
            ]);

            // Clear cache for this date
            $cacheKey = "leave_{$date}";
            unset(self::$cache[$cacheKey]);

            return $response['leave'] ?? null;
        } catch (Exception $e) {
            error_log("Portal API error creating leave: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Make an HTTP request to the Portal API
     *
     * @param string $method HTTP method (GET, POST, etc.)
     * @param string $endpoint API endpoint (e.g., /api/leave)
     * @param array $data Request data (query params for GET, body for POST)
     * @return array Response data
     * @throws Exception on request failure
     */
    private function request(string $method, string $endpoint, array $data = []): array
    {
        $url = $this->apiUrl . $endpoint;

        $ch = curl_init();

        $headers = [
            'Authorization: Bearer ' . $this->apiToken,
            'Content-Type: application/json',
            'Accept: application/json'
        ];

        if ($method === 'GET' && !empty($data)) {
            $url .= '?' . http_build_query($data);
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
        ]);

        if ($method === 'POST' && !empty($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception("cURL error: {$error}");
        }

        if ($httpCode >= 400) {
            throw new Exception("Portal API returned HTTP {$httpCode}");
        }

        $decoded = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON response from Portal API");
        }

        if (isset($decoded['success']) && $decoded['success'] === false) {
            $errorMsg = $decoded['error']['message'] ?? 'Unknown error';
            throw new Exception("Portal API error: {$errorMsg}");
        }

        return $decoded;
    }

    /**
     * Clear the leave cache
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /**
     * Get cache status for debugging
     */
    public static function getCacheStatus(): array
    {
        $status = [];
        foreach (self::$cache as $key => $value) {
            $status[$key] = [
                'age' => time() - $value['time'],
                'count' => count($value['data'])
            ];
        }
        return $status;
    }
}
