<?php

namespace App\Middleware;

class SuperAdminAuth
{
    public static function check(): bool
    {
        $sessionKey = 'super_admin_auth';

        if (!isset($_SESSION[$sessionKey]) || $_SESSION[$sessionKey] !== true) {
            return false;
        }

        // Check session timeout
        $timeoutKey = 'super_admin_timeout';
        $timeout = config('session.timeout', 1800);

        if (isset($_SESSION[$timeoutKey]) && (time() - $_SESSION[$timeoutKey]) > $timeout) {
            self::clearAuth();
            return false;
        }

        // Refresh timeout
        $_SESSION[$timeoutKey] = time();

        return true;
    }

    public static function requireAuth(): void
    {
        if (!self::check()) {
            if (self::isApiRequest()) {
                json_response(['error' => 'Unauthorised'], 401);
            }
            redirect('admin');
        }
    }

    public static function verifyCredentials(string $username, string $password): bool
    {
        $configUsername = config('super_admin.username');
        $configPassword = config('super_admin.password');

        if (!$configUsername || !$configPassword) {
            return false;
        }

        return $username === $configUsername && $password === $configPassword;
    }

    public static function setAuthenticated(): void
    {
        $_SESSION['super_admin_auth'] = true;
        $_SESSION['super_admin_timeout'] = time();
    }

    public static function clearAuth(): void
    {
        unset($_SESSION['super_admin_auth']);
        unset($_SESSION['super_admin_timeout']);
    }

    private static function isApiRequest(): bool
    {
        return strpos($_SERVER['REQUEST_URI'], '/api/') !== false ||
               (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
    }
}
