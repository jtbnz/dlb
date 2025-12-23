<?php

namespace App\Middleware;

use App\Models\Brigade;

class PinAuth
{
    public static function check(string $slug): ?array
    {
        $brigade = Brigade::findBySlug($slug);

        if (!$brigade) {
            return null;
        }

        $sessionKey = "pin_auth_{$slug}";

        if (!isset($_SESSION[$sessionKey]) || $_SESSION[$sessionKey] !== true) {
            return null;
        }

        return $brigade;
    }

    public static function requireAuth(string $slug): array
    {
        $brigade = self::check($slug);

        if (!$brigade) {
            if (self::isApiRequest()) {
                json_response(['error' => 'Unauthorized'], 401);
            }
            redirect("{$slug}");
        }

        return $brigade;
    }

    public static function setAuthenticated(string $slug): void
    {
        $_SESSION["pin_auth_{$slug}"] = true;
    }

    public static function clearAuth(string $slug): void
    {
        unset($_SESSION["pin_auth_{$slug}"]);
    }

    private static function isApiRequest(): bool
    {
        return strpos($_SERVER['REQUEST_URI'], '/api/') !== false ||
               (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
    }
}
