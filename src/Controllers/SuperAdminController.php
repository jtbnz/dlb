<?php

namespace App\Controllers;

use App\Models\Brigade;
use App\Middleware\SuperAdminAuth;
use App\Services\FenzFetcher;

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
            // Regenerate session ID to prevent session fixation
            session_regenerate_id(true);
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

        // Generate welcome email template
        $appUrl = config('app.url', 'https://example.com');
        $welcomeEmail = $this->generateWelcomeEmail($name, $slug, $adminUsername, $adminPassword, $pin, $appUrl);

        json_response([
            'success' => true,
            'brigade' => [
                'id' => $brigadeId,
                'name' => $name,
                'slug' => $slug,
            ],
            'welcome_email' => $welcomeEmail,
        ]);
    }

    /**
     * Generate a welcome email template for a new brigade admin
     */
    private function generateWelcomeEmail(string $name, string $slug, string $adminUsername, string $adminPassword, string $pin, string $appUrl): array
    {
        $attendanceUrl = rtrim($appUrl, '/') . '/' . $slug;
        $adminUrl = rtrim($appUrl, '/') . '/' . $slug . '/admin';

        $subject = "Welcome to Brigade Attendance - {$name}";

        $body = <<<EMAIL
Kia ora,

Your brigade has been set up on the Digital Logbook (DLB) Brigade Attendance system. Below are your login details and instructions to get started.

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
BRIGADE DETAILS
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

Brigade Name: {$name}

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
ADMIN ACCESS (for brigade management)
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

URL: {$adminUrl}
Username: {$adminUsername}
Password: {$adminPassword}

Use this to:
- Add and manage brigade members
- Configure trucks and positions
- View and export callout history
- Generate API tokens for integrations
- Update settings

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
ATTENDANCE ENTRY (for all members)
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

URL: {$attendanceUrl}
PIN: {$pin}

Share this URL and PIN with your brigade members.
They can use this on any device to record attendance at callouts and musters.

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
GETTING STARTED
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

1. Log in to Admin using the credentials above
2. Add your brigade members (Members > Add Member or Import CSV)
3. Configure your trucks and positions (Trucks > Add Truck)
4. Share the Attendance URL and PIN with your members
5. Optionally set up a QR code (Settings > QR Code)

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
SECURITY RECOMMENDATIONS
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

- Change your admin password after first login
- Keep the attendance PIN simple for easy member access
- Only share admin credentials with authorised personnel

If you have any questions, please contact your system administrator.

Nga mihi,
Brigade Attendance System
EMAIL;

        return [
            'subject' => $subject,
            'body' => $body,
            'admin_url' => $adminUrl,
            'attendance_url' => $attendanceUrl,
        ];
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

    public function fenzStatus(): void
    {
        SuperAdminAuth::requireAuth();

        echo view('superadmin/fenz-status', []);
    }

    public function apiFenzStatus(): void
    {
        SuperAdminAuth::requireAuth();

        $logs = FenzFetcher::getRecentLogs(200);
        $fetchStatus = FenzFetcher::getFetchStatus();

        // Get brigades with pending FENZ fetches
        $pendingBrigades = db()->query(
            "SELECT DISTINCT b.id, b.name, b.region, COUNT(c.id) as pending_count
             FROM brigades b
             INNER JOIN callouts c ON c.brigade_id = b.id
             WHERE c.fenz_fetched_at IS NULL
               AND c.status = 'submitted'
               AND c.created_at >= datetime('now', '-7 days')
               AND c.icad_number LIKE 'F%'
             GROUP BY b.id"
        );

        json_response([
            'logs' => $logs,
            'fetch_status' => $fetchStatus,
            'pending_brigades' => $pendingBrigades,
            'current_nz_time' => (new \DateTime('now', new \DateTimeZone('Pacific/Auckland')))->format('Y-m-d H:i:s T'),
            'current_nz_day' => FenzFetcher::getNzDayName(),
        ]);
    }

    public function apiFenzTrigger(): void
    {
        SuperAdminAuth::requireAuth();

        $data = json_decode(file_get_contents('php://input'), true);
        $brigadeId = (int)($data['brigade_id'] ?? 0);

        if ($brigadeId === 0) {
            // Trigger for all brigades
            $results = FenzFetcher::updateAllBrigades();
            json_response(['success' => true, 'results' => $results]);
        } else {
            // Trigger for specific brigade
            $brigade = Brigade::findById($brigadeId);
            if (!$brigade) {
                json_response(['error' => 'Brigade not found'], 404);
                return;
            }

            // Clear rate limit for this brigade to force fetch
            $lockFile = __DIR__ . '/../../data/fenz_cache/brigade_' . $brigadeId . '.lock';
            if (file_exists($lockFile)) {
                unlink($lockFile);
            }

            $region = $brigade['region'] ?? 1;
            $updated = FenzFetcher::updateIfNeeded($brigadeId, $region);

            json_response([
                'success' => true,
                'brigade_id' => $brigadeId,
                'updated' => $updated,
            ]);
        }
    }
}
