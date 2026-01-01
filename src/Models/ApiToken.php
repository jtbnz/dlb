<?php

namespace App\Models;

class ApiToken
{
    /**
     * Available permissions for API tokens
     */
    public const PERMISSIONS = [
        'musters:create' => 'Create musters',
        'musters:read' => 'Read musters',
        'musters:update' => 'Update musters',
        'attendance:create' => 'Create/update attendance',
        'attendance:read' => 'Read attendance',
        'members:read' => 'Read members',
        'members:create' => 'Create members',
    ];

    /**
     * Find token by ID
     */
    public static function findById(int $id): ?array
    {
        return db()->queryOne("SELECT * FROM api_tokens WHERE id = ?", [$id]);
    }

    /**
     * Find all tokens for a brigade
     */
    public static function findByBrigade(int $brigadeId): array
    {
        return db()->query(
            "SELECT id, brigade_id, name, permissions, last_used_at, expires_at, created_at
             FROM api_tokens WHERE brigade_id = ? ORDER BY created_at DESC",
            [$brigadeId]
        );
    }

    /**
     * Verify a token and return the token record with brigade data if valid
     */
    public static function verify(string $token): ?array
    {
        // Token format: dlb_{slug}_{random}
        if (!preg_match('/^dlb_([a-z0-9-]+)_([a-f0-9]+)$/i', $token, $matches)) {
            return null;
        }

        $slug = $matches[1];

        // Find brigade by slug
        $brigade = Brigade::findBySlug($slug);
        if (!$brigade) {
            return null;
        }

        // Get all tokens for this brigade
        $tokens = db()->query(
            "SELECT * FROM api_tokens WHERE brigade_id = ?",
            [$brigade['id']]
        );

        // Check each token hash
        foreach ($tokens as $tokenRecord) {
            if (password_verify($token, $tokenRecord['token_hash'])) {
                // Check expiration
                if ($tokenRecord['expires_at'] && strtotime($tokenRecord['expires_at']) < time()) {
                    return null;
                }

                // Update last used
                db()->update('api_tokens', [
                    'last_used_at' => date('Y-m-d H:i:s'),
                ], 'id = ?', [$tokenRecord['id']]);

                $tokenRecord['brigade'] = $brigade;
                $tokenRecord['permissions_array'] = json_decode($tokenRecord['permissions'], true) ?? [];

                return $tokenRecord;
            }
        }

        return null;
    }

    /**
     * Check if token has a specific permission
     */
    public static function hasPermission(array $token, string $permission): bool
    {
        $permissions = $token['permissions_array'] ?? json_decode($token['permissions'], true) ?? [];
        return in_array($permission, $permissions);
    }

    /**
     * Generate a new API token
     * Returns the plain token (display once to user) and creates the database record
     */
    public static function generate(int $brigadeId, string $name, array $permissions, ?string $expiresAt = null): array
    {
        $brigade = Brigade::findById($brigadeId);
        if (!$brigade) {
            throw new \InvalidArgumentException('Brigade not found');
        }

        // Generate secure random token
        $randomPart = bin2hex(random_bytes(32));
        $plainToken = sprintf('dlb_%s_%s', $brigade['slug'], $randomPart);

        // Hash the token for storage
        $tokenHash = password_hash($plainToken, PASSWORD_DEFAULT);

        // Validate permissions
        $validPermissions = array_intersect($permissions, array_keys(self::PERMISSIONS));

        $id = db()->insert('api_tokens', [
            'brigade_id' => $brigadeId,
            'token_hash' => $tokenHash,
            'name' => $name,
            'permissions' => json_encode(array_values($validPermissions)),
            'expires_at' => $expiresAt,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return [
            'id' => $id,
            'token' => $plainToken,
            'name' => $name,
            'permissions' => $validPermissions,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Update token permissions
     */
    public static function update(int $id, array $data): int
    {
        $updates = [];

        if (isset($data['name'])) {
            $updates['name'] = $data['name'];
        }

        if (isset($data['permissions'])) {
            $validPermissions = array_intersect($data['permissions'], array_keys(self::PERMISSIONS));
            $updates['permissions'] = json_encode(array_values($validPermissions));
        }

        if (isset($data['expires_at'])) {
            $updates['expires_at'] = $data['expires_at'];
        }

        if (empty($updates)) {
            return 0;
        }

        return db()->update('api_tokens', $updates, 'id = ?', [$id]);
    }

    /**
     * Revoke (delete) a token
     */
    public static function revoke(int $id): int
    {
        // Delete rate limits first
        db()->delete('api_rate_limits', 'token_id = ?', [$id]);
        return db()->delete('api_tokens', 'id = ?', [$id]);
    }

    /**
     * Check rate limit for a token
     * Returns true if within limits, false if rate limited
     */
    public static function checkRateLimit(int $tokenId): bool
    {
        $now = date('Y-m-d H:i:s');
        $minuteAgo = date('Y-m-d H:i:s', strtotime('-1 minute'));
        $hourAgo = date('Y-m-d H:i:s', strtotime('-1 hour'));

        $limits = db()->queryOne(
            "SELECT * FROM api_rate_limits WHERE token_id = ?",
            [$tokenId]
        );

        if (!$limits) {
            // Create new rate limit record
            db()->insert('api_rate_limits', [
                'token_id' => $tokenId,
                'minute_count' => 1,
                'minute_reset' => date('Y-m-d H:i:s', strtotime('+1 minute')),
                'hour_count' => 1,
                'hour_reset' => date('Y-m-d H:i:s', strtotime('+1 hour')),
            ]);
            return true;
        }

        // Check and reset minute counter
        if ($limits['minute_reset'] && strtotime($limits['minute_reset']) < time()) {
            $limits['minute_count'] = 0;
            $limits['minute_reset'] = date('Y-m-d H:i:s', strtotime('+1 minute'));
        }

        // Check and reset hour counter
        if ($limits['hour_reset'] && strtotime($limits['hour_reset']) < time()) {
            $limits['hour_count'] = 0;
            $limits['hour_reset'] = date('Y-m-d H:i:s', strtotime('+1 hour'));
        }

        // Check limits (100/minute, 1000/hour)
        if ($limits['minute_count'] >= 100 || $limits['hour_count'] >= 1000) {
            return false;
        }

        // Increment counters
        db()->update('api_rate_limits', [
            'minute_count' => $limits['minute_count'] + 1,
            'minute_reset' => $limits['minute_reset'],
            'hour_count' => $limits['hour_count'] + 1,
            'hour_reset' => $limits['hour_reset'],
        ], 'token_id = ?', [$tokenId]);

        return true;
    }

    /**
     * Get rate limit info for response headers
     */
    public static function getRateLimitInfo(int $tokenId): array
    {
        $limits = db()->queryOne(
            "SELECT * FROM api_rate_limits WHERE token_id = ?",
            [$tokenId]
        );

        if (!$limits) {
            return [
                'limit' => 100,
                'remaining' => 100,
                'reset' => time() + 60,
            ];
        }

        $remaining = max(0, 100 - ($limits['minute_count'] ?? 0));
        $reset = $limits['minute_reset'] ? strtotime($limits['minute_reset']) : time() + 60;

        return [
            'limit' => 100,
            'remaining' => $remaining,
            'reset' => $reset,
        ];
    }
}
