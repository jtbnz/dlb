<?php

namespace App\Controllers;

use App\Models\Brigade;
use App\Middleware\PinAuth;

class AuthController
{
    public function showPin(string $slug): void
    {
        $brigade = Brigade::findBySlug($slug);

        if (!$brigade) {
            http_response_code(404);
            echo view('layouts/error', ['code' => 404, 'message' => 'Brigade not found']);
            return;
        }

        // Already authenticated? Redirect to attendance
        if (PinAuth::check($slug)) {
            redirect("{$slug}/attendance");
        }

        echo view('attendance/pin', [
            'brigade' => $brigade,
            'slug' => $slug,
            'error' => $_SESSION['pin_error'] ?? null,
        ]);

        unset($_SESSION['pin_error']);
    }

    public function verifyPin(string $slug): void
    {
        $brigade = Brigade::findBySlug($slug);

        if (!$brigade) {
            json_response(['error' => 'Brigade not found'], 404);
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $pin = $data['pin'] ?? '';

        // Rate limiting
        if ($this->isRateLimited($slug)) {
            json_response(['error' => 'Too many attempts. Please try again later.'], 429);
            return;
        }

        if (Brigade::verifyPin($brigade, $pin)) {
            $this->clearRateLimit($slug);
            // Regenerate session ID to prevent session fixation
            session_regenerate_id(true);
            PinAuth::setAuthenticated($slug);
            audit_log($brigade['id'], null, 'pin_login', ['success' => true]);
            json_response(['success' => true, 'redirect' => base_path() . "/{$slug}/attendance"]);
        } else {
            $this->incrementRateLimit($slug);
            audit_log($brigade['id'], null, 'pin_login', ['success' => false]);
            json_response(['error' => 'Invalid PIN'], 401);
        }
    }

    private function isRateLimited(string $slug): bool
    {
        $identifier = 'pin_' . $slug . '_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $window = config('security.rate_limit_window', 900);
        $maxAttempts = config('security.rate_limit_attempts', 5);

        $record = db()->queryOne(
            "SELECT * FROM rate_limits WHERE identifier = ?",
            [$identifier]
        );

        if (!$record) {
            return false;
        }

        $firstAttempt = strtotime($record['first_attempt']);

        if (time() - $firstAttempt > $window) {
            db()->delete('rate_limits', 'identifier = ?', [$identifier]);
            return false;
        }

        return $record['attempts'] >= $maxAttempts;
    }

    private function incrementRateLimit(string $slug): void
    {
        $identifier = 'pin_' . $slug . '_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

        $record = db()->queryOne(
            "SELECT * FROM rate_limits WHERE identifier = ?",
            [$identifier]
        );

        if ($record) {
            db()->execute(
                "UPDATE rate_limits SET attempts = attempts + 1 WHERE identifier = ?",
                [$identifier]
            );
        } else {
            db()->insert('rate_limits', [
                'identifier' => $identifier,
                'attempts' => 1,
            ]);
        }
    }

    private function clearRateLimit(string $slug): void
    {
        $identifier = 'pin_' . $slug . '_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        db()->delete('rate_limits', 'identifier = ?', [$identifier]);
    }
}
