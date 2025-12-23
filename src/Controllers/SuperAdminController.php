<?php

namespace App\Controllers;

use App\Models\Brigade;
use App\Middleware\SuperAdminAuth;

class SuperAdminController
{
    public function showLogin(): void
    {
        if (SuperAdminAuth::check()) {
            redirect('admin/dashboard');
        }

        echo view('superadmin/login', []);
    }

    public function login(): void
    {
        // Rate limiting check
        if ($this->isRateLimited('super_admin')) {
            json_response(['error' => 'Too many failed attempts. Please try again in 15 minutes.'], 429);
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $username = $data['username'] ?? '';
        $password = $data['password'] ?? '';

        if (SuperAdminAuth::verifyCredentials($username, $password)) {
            $this->clearRateLimit('super_admin');
            SuperAdminAuth::setAuthenticated();
            json_response(['success' => true, 'redirect' => base_path() . '/admin/dashboard']);
        } else {
            $this->incrementRateLimit('super_admin');
            json_response(['error' => 'Invalid credentials'], 401);
        }
    }

    public function logout(): void
    {
        SuperAdminAuth::clearAuth();
        redirect('admin');
    }

    public function dashboard(): void
    {
        SuperAdminAuth::requireAuth();

        $brigades = Brigade::all();

        echo view('superadmin/dashboard', [
            'brigades' => $brigades,
        ]);
    }

    public function apiGetBrigades(): void
    {
        SuperAdminAuth::requireAuth();
        json_response(['brigades' => Brigade::all()]);
    }

    public function apiCreateBrigade(): void
    {
        SuperAdminAuth::requireAuth();

        $data = json_decode(file_get_contents('php://input'), true);

        $name = trim($data['name'] ?? '');
        $slug = trim($data['slug'] ?? '');
        $adminUsername = trim($data['admin_username'] ?? 'admin');
        $adminPassword = $data['admin_password'] ?? '';
        $pin = $data['pin'] ?? '1234';

        if (empty($name)) {
            json_response(['error' => 'Brigade name is required'], 400);
            return;
        }

        // Generate slug if not provided
        if (empty($slug)) {
            $slug = $this->generateSlug($name);
        } else {
            $slug = $this->sanitizeSlug($slug);
        }

        // Check if slug already exists
        if (Brigade::findBySlug($slug)) {
            json_response(['error' => 'A brigade with this slug already exists'], 400);
            return;
        }

        // Validate PIN
        if (strlen($pin) < 4 || strlen($pin) > 6 || !ctype_digit($pin)) {
            json_response(['error' => 'PIN must be 4-6 digits'], 400);
            return;
        }

        // Validate password
        if (strlen($adminPassword) < 8) {
            json_response(['error' => 'Admin password must be at least 8 characters'], 400);
            return;
        }

        $brigadeId = Brigade::create([
            'name' => $name,
            'slug' => $slug,
            'pin_hash' => password_hash($pin, PASSWORD_DEFAULT),
            'admin_username' => $adminUsername,
            'admin_password_hash' => password_hash($adminPassword, PASSWORD_DEFAULT),
            'email_recipients' => json_encode([]),
            'include_non_attendees' => 0,
            'member_order' => 'rank_name',
        ]);

        json_response([
            'success' => true,
            'brigade' => [
                'id' => $brigadeId,
                'name' => $name,
                'slug' => $slug,
            ],
        ]);
    }

    public function apiUpdateBrigade(string $brigadeId): void
    {
        SuperAdminAuth::requireAuth();

        $brigade = Brigade::findById((int)$brigadeId);
        if (!$brigade) {
            json_response(['error' => 'Brigade not found'], 404);
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);
        $updates = [];

        if (isset($data['name'])) {
            $updates['name'] = trim($data['name']);
        }

        if (isset($data['slug'])) {
            $newSlug = $this->sanitizeSlug($data['slug']);
            if ($newSlug !== $brigade['slug']) {
                // Check if new slug already exists
                $existing = Brigade::findBySlug($newSlug);
                if ($existing && $existing['id'] !== $brigade['id']) {
                    json_response(['error' => 'A brigade with this slug already exists'], 400);
                    return;
                }
                $updates['slug'] = $newSlug;
            }
        }

        if (isset($data['admin_username'])) {
            $updates['admin_username'] = trim($data['admin_username']);
        }

        if (!empty($data['admin_password'])) {
            if (strlen($data['admin_password']) < 8) {
                json_response(['error' => 'Admin password must be at least 8 characters'], 400);
                return;
            }
            $updates['admin_password_hash'] = password_hash($data['admin_password'], PASSWORD_DEFAULT);
        }

        if (!empty($data['pin'])) {
            if (strlen($data['pin']) < 4 || strlen($data['pin']) > 6 || !ctype_digit($data['pin'])) {
                json_response(['error' => 'PIN must be 4-6 digits'], 400);
                return;
            }
            $updates['pin_hash'] = password_hash($data['pin'], PASSWORD_DEFAULT);
        }

        if (!empty($updates)) {
            Brigade::update((int)$brigadeId, $updates);
        }

        json_response(['success' => true]);
    }

    public function apiDeleteBrigade(string $brigadeId): void
    {
        SuperAdminAuth::requireAuth();

        $brigade = Brigade::findById((int)$brigadeId);
        if (!$brigade) {
            json_response(['error' => 'Brigade not found'], 404);
            return;
        }

        // Delete all related data
        db()->delete('attendance', 'callout_id IN (SELECT id FROM callouts WHERE brigade_id = ?)', [(int)$brigadeId]);
        db()->delete('callouts', 'brigade_id = ?', [(int)$brigadeId]);
        db()->delete('positions', 'truck_id IN (SELECT id FROM trucks WHERE brigade_id = ?)', [(int)$brigadeId]);
        db()->delete('trucks', 'brigade_id = ?', [(int)$brigadeId]);
        db()->delete('members', 'brigade_id = ?', [(int)$brigadeId]);
        db()->delete('audit_log', 'brigade_id = ?', [(int)$brigadeId]);
        db()->delete('brigades', 'id = ?', [(int)$brigadeId]);

        json_response(['success' => true]);
    }

    private function generateSlug(string $name): string
    {
        $slug = strtolower($name);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');

        // Ensure uniqueness
        $baseSlug = $slug;
        $counter = 1;
        while (Brigade::findBySlug($slug)) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    private function sanitizeSlug(string $slug): string
    {
        $slug = strtolower($slug);
        $slug = preg_replace('/[^a-z0-9-]+/', '-', $slug);
        return trim($slug, '-');
    }

    private function isRateLimited(string $prefix): bool
    {
        $identifier = $prefix . '_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
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

    private function incrementRateLimit(string $prefix): void
    {
        $identifier = $prefix . '_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

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

    private function clearRateLimit(string $prefix): void
    {
        $identifier = $prefix . '_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        db()->delete('rate_limits', 'identifier = ?', [$identifier]);
    }
}
