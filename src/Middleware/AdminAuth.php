<?php

namespace App\Middleware;

use App\Models\Brigade;

class AdminAuth
{
    public static function check(string $slug): ?array
    {
        $brigade = Brigade::findBySlug($slug);

        if (!$brigade) {
            return null;
        }

        $sessionKey = "admin_auth_{$slug}";

        if (!isset($_SESSION[$sessionKey]) || $_SESSION[$sessionKey] !== true) {
            return null;
        }

        // Check session timeout
        $timeoutKey = "admin_timeout_{$slug}";
        $timeout = config('session.timeout', 1800);

        if (isset($_SESSION[$timeoutKey]) && (time() - $_SESSION[$timeoutKey]) > $timeout) {
            self::clearAuth($slug);
            return null;
        }

        // Refresh timeout
        $_SESSION[$timeoutKey] = time();

        return $brigade;
    }

    public static function requireAuth(string $slug): array
    {
        $brigade = self::check($slug);

        if (!$brigade) {
            if (self::isApiRequest()) {
                json_response(['error' => 'Unauthorized'], 401);
            }
            redirect("{$slug}/admin");
        }

        return $brigade;
    }

    public static function setAuthenticated(string $slug): void
    {
        $_SESSION["admin_auth_{$slug}"] = true;
        $_SESSION["admin_timeout_{$slug}"] = time();
    }

    public static function clearAuth(string $slug): void
    {
        unset($_SESSION["admin_auth_{$slug}"]);
        unset($_SESSION["admin_timeout_{$slug}"]);
    }

    private static function isApiRequest(): bool
    {
        return strpos($_SERVER['REQUEST_URI'], '/api/') !== false ||
               (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
    }
}
