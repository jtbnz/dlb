<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Models\ApiToken;
use App\Models\Brigade;

class ApiAuth
{
    /**
     * Verify API token and check permission
     * Returns token data with brigade if valid, null otherwise
     */
    public static function verify(string $requiredPermission): ?array
    {
        $token = self::extractToken();

        if (!$token) {
            return null;
        }

        $tokenData = ApiToken::verify($token);

        if (!$tokenData) {
            return null;
        }

        // Check rate limit
        if (!ApiToken::checkRateLimit($tokenData['id'])) {
            self::sendRateLimitResponse($tokenData['id']);
            return null;
        }

        // Check permission
        if (!ApiToken::hasPermission($tokenData, $requiredPermission)) {
            return null;
        }

        return $tokenData;
    }

    /**
     * Require API authentication - returns token data or sends error response
     */
    public static function requireAuth(string $slug, string $requiredPermission): array
    {
        $token = self::extractToken();

        if (!$token) {
            self::sendErrorResponse('INVALID_TOKEN', 'Missing or malformed Authorization header', 401);
        }

        $tokenData = ApiToken::verify($token);

        if (!$tokenData) {
            self::sendErrorResponse('INVALID_TOKEN', 'The provided API token is invalid or expired', 401);
        }

        // Verify the token belongs to the correct brigade (from URL slug)
        $brigade = Brigade::findBySlug($slug);
        if (!$brigade || $tokenData['brigade_id'] !== $brigade['id']) {
            self::sendErrorResponse('INVALID_TOKEN', 'Token does not match the requested brigade', 401);
        }

        // Check rate limit
        if (!ApiToken::checkRateLimit($tokenData['id'])) {
            self::sendRateLimitResponse($tokenData['id']);
        }

        // Check permission
        if (!ApiToken::hasPermission($tokenData, $requiredPermission)) {
            self::sendErrorResponse(
                'PERMISSION_DENIED',
                "Token lacks required permission: {$requiredPermission}",
                403
            );
        }

        // Add rate limit headers
        self::addRateLimitHeaders($tokenData['id']);

        // Log API access
        audit_log(
            $tokenData['brigade_id'],
            null,
            'api_access',
            [
                'token_id' => $tokenData['id'],
                'token_name' => $tokenData['name'],
                'permission' => $requiredPermission,
                'endpoint' => $_SERVER['REQUEST_URI'] ?? '',
                'method' => $_SERVER['REQUEST_METHOD'] ?? '',
            ]
        );

        return $tokenData;
    }

    /**
     * Extract bearer token from Authorization header
     */
    private static function extractToken(): ?string
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        // Try Apache workaround
        if (empty($authHeader) && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }

        // Try getallheaders
        if (empty($authHeader) && function_exists('getallheaders')) {
            $headers = getallheaders();
            $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }

        if (empty($authHeader)) {
            return null;
        }

        if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return null;
        }

        return trim($matches[1]);
    }

    /**
     * Send JSON error response and exit
     */
    private static function sendErrorResponse(string $code, string $message, int $httpStatus): void
    {
        http_response_code($httpStatus);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ]);
        exit;
    }

    /**
     * Send rate limit exceeded response
     */
    private static function sendRateLimitResponse(int $tokenId): void
    {
        $rateLimitInfo = ApiToken::getRateLimitInfo($tokenId);

        http_response_code(429);
        header('Content-Type: application/json');
        header('Retry-After: ' . max(1, $rateLimitInfo['reset'] - time()));
        header('X-RateLimit-Limit: ' . $rateLimitInfo['limit']);
        header('X-RateLimit-Remaining: 0');
        header('X-RateLimit-Reset: ' . $rateLimitInfo['reset']);

        echo json_encode([
            'success' => false,
            'error' => [
                'code' => 'RATE_LIMITED',
                'message' => 'Too many requests. Please retry after ' . ($rateLimitInfo['reset'] - time()) . ' seconds.',
            ],
        ]);
        exit;
    }

    /**
     * Add rate limit headers to response
     */
    private static function addRateLimitHeaders(int $tokenId): void
    {
        $rateLimitInfo = ApiToken::getRateLimitInfo($tokenId);

        header('X-RateLimit-Limit: ' . $rateLimitInfo['limit']);
        header('X-RateLimit-Remaining: ' . $rateLimitInfo['remaining']);
        header('X-RateLimit-Reset: ' . $rateLimitInfo['reset']);
    }
}
