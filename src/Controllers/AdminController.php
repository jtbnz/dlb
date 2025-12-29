<?php

namespace App\Controllers;

use App\Models\Brigade;
use App\Models\Member;
use App\Models\Truck;
use App\Models\Position;
use App\Models\Callout;
use App\Models\Attendance;
use App\Middleware\AdminAuth;

class AdminController
{
    public function showLogin(string $slug): void
    {
        $brigade = Brigade::findBySlug($slug);

        if (!$brigade) {
            http_response_code(404);
            echo view('layouts/error', ['code' => 404, 'message' => 'Brigade not found']);
            return;
        }

        if (AdminAuth::check($slug)) {
            redirect("{$slug}/admin/dashboard");
        }

        echo view('admin/login', ['brigade' => $brigade, 'slug' => $slug]);
    }

    public function login(string $slug): void
    {
        $brigade = Brigade::findBySlug($slug);

        if (!$brigade) {
            json_response(['error' => 'Brigade not found'], 404);
            return;
        }

        // Rate limiting check
        if ($this->isRateLimited('admin_' . $slug)) {
            json_response(['error' => 'Too many failed attempts. Please try again in 15 minutes.'], 429);
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $username = $data['username'] ?? '';
        $password = $data['password'] ?? '';

        if ($username === $brigade['admin_username'] && Brigade::verifyAdminPassword($brigade, $password)) {
            $this->clearRateLimit('admin_' . $slug);
            // Regenerate session ID to prevent session fixation
            session_regenerate_id(true);
            AdminAuth::setAuthenticated($slug);
            audit_log($brigade['id'], null, 'admin_login', ['username' => $username]);
            json_response(['success' => true, 'redirect' => base_path() . "/{$slug}/admin/dashboard"]);
        } else {
            $this->incrementRateLimit('admin_' . $slug);
            audit_log($brigade['id'], null, 'admin_login_failed', ['username' => $username]);
            json_response(['error' => 'Invalid credentials'], 401);
        }
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

    public function logout(string $slug): void
    {
        $brigade = Brigade::findBySlug($slug);
        if ($brigade) {
            audit_log($brigade['id'], null, 'admin_logout', []);
        }
        AdminAuth::clearAuth($slug);
        redirect("{$slug}/admin");
    }

    public function dashboard(string $slug): void
    {
        $brigade = AdminAuth::requireAuth($slug);

        $activeCallouts = db()->query(
            "SELECT COUNT(*) as count FROM callouts WHERE brigade_id = ? AND status = 'active'",
            [$brigade['id']]
        )[0]['count'];

        $recentCallouts = Callout::findByBrigade($brigade['id'], 5);

        echo view('admin/dashboard', [
            'brigade' => $brigade,
            'slug' => $slug,
            'activeCallouts' => $activeCallouts,
            'recentCallouts' => $recentCallouts,
        ]);
    }

    // Members
    public function members(string $slug): void
    {
        $brigade = AdminAuth::requireAuth($slug);
        echo view('admin/members', ['brigade' => $brigade, 'slug' => $slug]);
    }

    public function apiGetMembers(string $slug): void
    {
        $brigade = AdminAuth::requireAuth($slug);
        json_response(['members' => Member::findByBrigade($brigade['id'], false)]);
    }

    public function apiCreateMember(string $slug): void
    {
        $brigade = AdminAuth::requireAuth($slug);
        $data = json_decode(file_get_contents('php://input'), true);

        $memberData = [
            'brigade_id' => $brigade['id'],
            'display_name' => trim($data['display_name'] ?? ''),
            'rank' => trim($data['rank'] ?? ''),
            'first_name' => trim($data['first_name'] ?? ''),
            'last_name' => trim($data['last_name'] ?? ''),
            'email' => trim($data['email'] ?? ''),
            'is_active' => 1,
        ];

        if (!empty($data['join_date'])) {
            $memberData['join_date'] = $data['join_date'];
        }

        $id = Member::create($memberData);

        audit_log($brigade['id'], null, 'member_created', ['member_id' => $id, 'display_name' => $data['display_name']]);
        json_response(['success' => true, 'id' => $id]);
    }

    public function apiUpdateMember(string $slug, string $memberId): void
    {
        $brigade = AdminAuth::requireAuth($slug);
        $data = json_decode(file_get_contents('php://input'), true);

        $member = Member::findById((int)$memberId);
        if (!$member || $member['brigade_id'] !== $brigade['id']) {
            json_response(['error' => 'Member not found'], 404);
            return;
        }

        $updateData = [
            'display_name' => trim($data['display_name'] ?? $member['display_name']),
            'rank' => trim($data['rank'] ?? $member['rank']),
            'first_name' => trim($data['first_name'] ?? $member['first_name']),
            'last_name' => trim($data['last_name'] ?? $member['last_name']),
            'email' => trim($data['email'] ?? $member['email']),
            'is_active' => $data['is_active'] ?? $member['is_active'],
        ];

        if (array_key_exists('join_date', $data)) {
            $updateData['join_date'] = $data['join_date'] ?: null;
        }

        Member::update((int)$memberId, $updateData);

        audit_log($brigade['id'], null, 'member_updated', ['member_id' => $memberId]);
        json_response(['success' => true]);
    }

    public function apiDeleteMember(string $slug, string $memberId): void
    {
        $brigade = AdminAuth::requireAuth($slug);

        $member = Member::findById((int)$memberId);
        if (!$member || $member['brigade_id'] !== $brigade['id']) {
            json_response(['error' => 'Member not found'], 404);
            return;
        }

        Member::deactivate((int)$memberId);
        audit_log($brigade['id'], null, 'member_deactivated', ['member_id' => $memberId, 'display_name' => $member['display_name']]);
        json_response(['success' => true]);
    }

    public function apiImportMembers(string $slug): void
    {
        $brigade = AdminAuth::requireAuth($slug);
        $data = json_decode(file_get_contents('php://input'), true);

        $csv = $data['csv'] ?? '';
        $updateExisting = $data['update_existing'] ?? false;

        $result = Member::importCsv($brigade['id'], $csv, $updateExisting);
        audit_log($brigade['id'], null, 'members_imported', $result);
        json_response($result);
    }

    // Trucks
    public function trucks(string $slug): void
    {
        $brigade = AdminAuth::requireAuth($slug);
        echo view('admin/trucks', ['brigade' => $brigade, 'slug' => $slug]);
    }

    public function apiGetTrucks(string $slug): void
    {
        $brigade = AdminAuth::requireAuth($slug);
        json_response(['trucks' => Truck::findByBrigadeWithPositions($brigade['id'])]);
    }

    public function apiCreateTruck(string $slug): void
    {
        $brigade = AdminAuth::requireAuth($slug);
        $data = json_decode(file_get_contents('php://input'), true);

        $maxOrder = db()->queryOne(
            "SELECT MAX(sort_order) as max FROM trucks WHERE brigade_id = ?",
            [$brigade['id']]
        )['max'] ?? 0;

        $id = Truck::create([
            'brigade_id' => $brigade['id'],
            'name' => trim($data['name'] ?? ''),
            'is_station' => $data['is_station'] ?? 0,
            'sort_order' => $maxOrder + 1,
        ]);

        // Create positions from template
        $template = $data['template'] ?? 'full';
        if ($data['is_station'] ?? false) {
            $template = 'station';
        }
        Position::createFromTemplate($id, $template);

        audit_log($brigade['id'], null, 'truck_created', ['truck_id' => $id, 'name' => $data['name']]);
        json_response(['success' => true, 'id' => $id]);
    }

    public function apiUpdateTruck(string $slug, string $truckId): void
    {
        $brigade = AdminAuth::requireAuth($slug);
        $data = json_decode(file_get_contents('php://input'), true);

        $truck = Truck::findById((int)$truckId);
        if (!$truck || $truck['brigade_id'] !== $brigade['id']) {
            json_response(['error' => 'Truck not found'], 404);
            return;
        }

        Truck::update((int)$truckId, [
            'name' => trim($data['name'] ?? $truck['name']),
        ]);

        audit_log($brigade['id'], null, 'truck_updated', ['truck_id' => $truckId]);
        json_response(['success' => true]);
    }

    public function apiDeleteTruck(string $slug, string $truckId): void
    {
        $brigade = AdminAuth::requireAuth($slug);

        $truck = Truck::findById((int)$truckId);
        if (!$truck || $truck['brigade_id'] !== $brigade['id']) {
            json_response(['error' => 'Truck not found'], 404);
            return;
        }

        Truck::delete((int)$truckId);
        audit_log($brigade['id'], null, 'truck_deleted', ['truck_id' => $truckId, 'name' => $truck['name']]);
        json_response(['success' => true]);
    }

    public function apiReorderTrucks(string $slug): void
    {
        $brigade = AdminAuth::requireAuth($slug);
        $data = json_decode(file_get_contents('php://input'), true);

        Truck::reorder($brigade['id'], $data['order'] ?? []);
        json_response(['success' => true]);
    }

    public function apiCreatePosition(string $slug, string $truckId): void
    {
        $brigade = AdminAuth::requireAuth($slug);
        $data = json_decode(file_get_contents('php://input'), true);

        $truck = Truck::findById((int)$truckId);
        if (!$truck || $truck['brigade_id'] !== $brigade['id']) {
            json_response(['error' => 'Truck not found'], 404);
            return;
        }

        $maxOrder = db()->queryOne(
            "SELECT MAX(sort_order) as max FROM positions WHERE truck_id = ?",
            [(int)$truckId]
        )['max'] ?? 0;

        $id = Position::create([
            'truck_id' => (int)$truckId,
            'name' => trim($data['name'] ?? ''),
            'allow_multiple' => $data['allow_multiple'] ?? 0,
            'sort_order' => $maxOrder + 1,
        ]);

        json_response(['success' => true, 'id' => $id]);
    }

    public function apiUpdatePosition(string $slug, string $positionId): void
    {
        $brigade = AdminAuth::requireAuth($slug);
        $data = json_decode(file_get_contents('php://input'), true);

        $position = Position::findById((int)$positionId);
        if (!$position) {
            json_response(['error' => 'Position not found'], 404);
            return;
        }

        $truck = Truck::findById($position['truck_id']);
        if (!$truck || $truck['brigade_id'] !== $brigade['id']) {
            json_response(['error' => 'Position not found'], 404);
            return;
        }

        Position::update((int)$positionId, [
            'name' => trim($data['name'] ?? $position['name']),
            'allow_multiple' => $data['allow_multiple'] ?? $position['allow_multiple'],
        ]);

        json_response(['success' => true]);
    }

    public function apiDeletePosition(string $slug, string $positionId): void
    {
        $brigade = AdminAuth::requireAuth($slug);

        $position = Position::findById((int)$positionId);
        if (!$position) {
            json_response(['error' => 'Position not found'], 404);
            return;
        }

        $truck = Truck::findById($position['truck_id']);
        if (!$truck || $truck['brigade_id'] !== $brigade['id']) {
            json_response(['error' => 'Position not found'], 404);
            return;
        }

        Position::delete((int)$positionId);
        json_response(['success' => true]);
    }

    // Callouts
    public function callouts(string $slug): void
    {
        $brigade = AdminAuth::requireAuth($slug);
        echo view('admin/callouts', ['brigade' => $brigade, 'slug' => $slug]);
    }

    public function apiGetCallouts(string $slug): void
    {
        $brigade = AdminAuth::requireAuth($slug);

        $filters = [
            'icad' => $_GET['icad'] ?? '',
            'status' => $_GET['status'] ?? '',
            'from_date' => $_GET['from_date'] ?? '',
            'to_date' => $_GET['to_date'] ?? '',
        ];

        json_response(['callouts' => Callout::search($brigade['id'], $filters)]);
    }

    public function apiGetCallout(string $slug, string $calloutId): void
    {
        $brigade = AdminAuth::requireAuth($slug);

        $callout = Callout::getWithAttendance((int)$calloutId);
        if (!$callout || $callout['brigade_id'] !== $brigade['id']) {
            json_response(['error' => 'Callout not found'], 404);
            return;
        }

        $callout['attendance_grouped'] = Attendance::findByCalloutGrouped((int)$calloutId);

        // Include trucks and members for editing
        $memberOrder = Brigade::getMemberOrder($brigade);
        json_response([
            'callout' => $callout,
            'trucks' => Truck::findByBrigadeWithPositions($brigade['id']),
            'members' => Member::findByBrigadeOrdered($brigade['id'], $memberOrder),
        ]);
    }

    public function apiUpdateCallout(string $slug, string $calloutId): void
    {
        $brigade = AdminAuth::requireAuth($slug);

        $callout = Callout::findById((int)$calloutId);
        if (!$callout || $callout['brigade_id'] !== $brigade['id']) {
            json_response(['error' => 'Callout not found'], 404);
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);
        $updates = [];
        $auditData = [];

        // Allow updating ICAD number
        if (isset($data['icad_number'])) {
            $updates['icad_number'] = trim($data['icad_number']);
            $auditData['icad_number'] = $updates['icad_number'];
        }

        // Allow updating FENZ data fields
        if (isset($data['location'])) {
            $updates['location'] = trim($data['location']) ?: null;
            $auditData['location'] = $updates['location'];
        }

        if (isset($data['duration'])) {
            $updates['duration'] = trim($data['duration']) ?: null;
            $auditData['duration'] = $updates['duration'];
        }

        if (isset($data['call_type'])) {
            $updates['call_type'] = trim($data['call_type']) ?: null;
            $auditData['call_type'] = $updates['call_type'];
        }

        if (!empty($updates)) {
            // Mark as manually fetched if FENZ data was updated
            if (isset($data['location']) || isset($data['duration']) || isset($data['call_type'])) {
                $updates['fenz_fetched_at'] = date('Y-m-d H:i:s');
            }

            Callout::update((int)$calloutId, $updates);
            audit_log($brigade['id'], (int)$calloutId, 'callout_updated', $auditData);
        }

        json_response(['success' => true]);
    }

    public function apiUnlockCallout(string $slug, string $calloutId): void
    {
        $brigade = AdminAuth::requireAuth($slug);

        $callout = Callout::findById((int)$calloutId);
        if (!$callout || $callout['brigade_id'] !== $brigade['id']) {
            json_response(['error' => 'Callout not found'], 404);
            return;
        }

        Callout::unlock((int)$calloutId);
        audit_log($brigade['id'], (int)$calloutId, 'callout_unlocked', []);
        json_response(['success' => true]);
    }

    public function apiDeleteCallout(string $slug, string $calloutId): void
    {
        $brigade = AdminAuth::requireAuth($slug);

        $callout = Callout::findById((int)$calloutId);
        if (!$callout || $callout['brigade_id'] !== $brigade['id']) {
            json_response(['error' => 'Callout not found'], 404);
            return;
        }

        // Delete all attendance records first
        Attendance::deleteByCallout((int)$calloutId);

        // Delete the callout
        Callout::delete((int)$calloutId);

        audit_log($brigade['id'], (int)$calloutId, 'callout_deleted', ['icad_number' => $callout['icad_number']]);
        json_response(['success' => true]);
    }

    public function apiAddCalloutAttendance(string $slug, string $calloutId): void
    {
        $brigade = AdminAuth::requireAuth($slug);

        $callout = Callout::findById((int)$calloutId);
        if (!$callout || $callout['brigade_id'] !== $brigade['id']) {
            json_response(['error' => 'Callout not found'], 404);
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);
        $memberId = (int)($data['member_id'] ?? 0);
        $truckId = (int)($data['truck_id'] ?? 0);
        $positionId = (int)($data['position_id'] ?? 0);

        // Validate member
        $member = Member::findById($memberId);
        if (!$member || $member['brigade_id'] !== $brigade['id']) {
            json_response(['error' => 'Invalid member'], 400);
            return;
        }

        // Validate truck and position
        $truck = Truck::findById($truckId);
        if (!$truck || $truck['brigade_id'] !== $brigade['id']) {
            json_response(['error' => 'Invalid truck'], 400);
            return;
        }

        $position = Position::findById($positionId);
        if (!$position || $position['truck_id'] !== $truckId) {
            json_response(['error' => 'Invalid position'], 400);
            return;
        }

        // Check if position allows multiple (for standby)
        if (!$position['allow_multiple']) {
            $existing = db()->queryOne(
                "SELECT id FROM attendance WHERE callout_id = ? AND position_id = ?",
                [(int)$calloutId, $positionId]
            );
            if ($existing) {
                json_response(['error' => 'Position already filled'], 400);
                return;
            }
        }

        // Check if member is already assigned
        $existing = db()->queryOne(
            "SELECT id FROM attendance WHERE callout_id = ? AND member_id = ?",
            [(int)$calloutId, $memberId]
        );
        if ($existing) {
            json_response(['error' => 'Member already assigned to this callout'], 400);
            return;
        }

        try {
            Attendance::create([
                'callout_id' => (int)$calloutId,
                'member_id' => $memberId,
                'truck_id' => $truckId,
                'position_id' => $positionId,
            ]);

            audit_log($brigade['id'], (int)$calloutId, 'admin_attendance_added', [
                'member_id' => $memberId,
                'member_name' => $member['name'],
                'truck_id' => $truckId,
                'position_id' => $positionId,
            ]);

            json_response([
                'success' => true,
                'attendance_grouped' => Attendance::findByCalloutGrouped((int)$calloutId),
            ]);
        } catch (\Exception $e) {
            json_response(['error' => 'Failed to add attendance'], 500);
        }
    }

    public function apiRemoveCalloutAttendance(string $slug, string $calloutId, string $attendanceId): void
    {
        $brigade = AdminAuth::requireAuth($slug);

        $callout = Callout::findById((int)$calloutId);
        if (!$callout || $callout['brigade_id'] !== $brigade['id']) {
            json_response(['error' => 'Callout not found'], 404);
            return;
        }

        $attendance = Attendance::findById((int)$attendanceId);
        if (!$attendance || $attendance['callout_id'] !== (int)$calloutId) {
            json_response(['error' => 'Attendance record not found'], 404);
            return;
        }

        $member = Member::findById($attendance['member_id']);

        Attendance::delete((int)$attendanceId);

        audit_log($brigade['id'], (int)$calloutId, 'admin_attendance_removed', [
            'member_id' => $attendance['member_id'],
            'member_name' => $member ? $member['name'] : 'Unknown',
        ]);

        json_response([
            'success' => true,
            'attendance_grouped' => Attendance::findByCalloutGrouped((int)$calloutId),
        ]);
    }

    public function apiMoveCalloutAttendance(string $slug, string $calloutId, string $attendanceId): void
    {
        $brigade = AdminAuth::requireAuth($slug);

        $callout = Callout::findById((int)$calloutId);
        if (!$callout || $callout['brigade_id'] !== $brigade['id']) {
            json_response(['error' => 'Callout not found'], 404);
            return;
        }

        $attendance = Attendance::findById((int)$attendanceId);
        if (!$attendance || $attendance['callout_id'] !== (int)$calloutId) {
            json_response(['error' => 'Attendance record not found'], 404);
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);
        $truckId = (int)($data['truck_id'] ?? 0);
        $positionId = (int)($data['position_id'] ?? 0);

        // Validate truck and position
        $truck = Truck::findById($truckId);
        if (!$truck || $truck['brigade_id'] !== $brigade['id']) {
            json_response(['error' => 'Invalid truck'], 400);
            return;
        }

        $position = Position::findById($positionId);
        if (!$position || $position['truck_id'] !== $truckId) {
            json_response(['error' => 'Invalid position'], 400);
            return;
        }

        // Check if position allows multiple (for standby)
        if (!$position['allow_multiple']) {
            $existing = db()->queryOne(
                "SELECT id FROM attendance WHERE callout_id = ? AND position_id = ? AND id != ?",
                [(int)$calloutId, $positionId, (int)$attendanceId]
            );
            if ($existing) {
                json_response(['error' => 'Position already filled'], 400);
                return;
            }
        }

        $member = Member::findById($attendance['member_id']);

        // Update the attendance record
        db()->update('attendance', [
            'truck_id' => $truckId,
            'position_id' => $positionId,
        ], 'id = ?', [(int)$attendanceId]);

        audit_log($brigade['id'], (int)$calloutId, 'admin_attendance_moved', [
            'member_id' => $attendance['member_id'],
            'member_name' => $member ? $member['name'] : 'Unknown',
            'new_truck_id' => $truckId,
            'new_position_id' => $positionId,
        ]);

        json_response([
            'success' => true,
            'attendance_grouped' => Attendance::findByCalloutGrouped((int)$calloutId),
        ]);
    }

    public function apiExportCallouts(string $slug): void
    {
        $brigade = AdminAuth::requireAuth($slug);

        $format = $_GET['format'] ?? 'csv';
        $filters = [
            'icad' => $_GET['icad'] ?? '',
            'status' => $_GET['status'] ?? '',
            'from_date' => $_GET['from_date'] ?? '',
            'to_date' => $_GET['to_date'] ?? '',
        ];

        $callouts = Callout::search($brigade['id'], $filters);

        // Get attendance for each callout
        foreach ($callouts as &$callout) {
            $callout['attendance'] = Attendance::findByCalloutGrouped((int)$callout['id']);
        }

        if ($format === 'csv') {
            $this->exportCsv($brigade, $callouts);
        } else {
            $this->exportPdf($brigade, $callouts);
        }
    }

    private function exportCsv(array $brigade, array $callouts): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="callouts_' . $brigade['slug'] . '_' . date('Y-m-d') . '.csv"');

        $output = fopen('php://output', 'w');

        // Header row
        fputcsv($output, ['ICAD', 'Status', 'Created', 'Submitted', 'Submitted By', 'Members']);

        foreach ($callouts as $callout) {
            $members = [];
            if (!empty($callout['attendance'])) {
                foreach ($callout['attendance'] as $truck) {
                    foreach ($truck['positions'] as $pos) {
                        foreach ($pos['members'] as $m) {
                            $members[] = $m['member_name'] . ' (' . $truck['truck_name'] . '/' . $pos['position_name'] . ')';
                        }
                    }
                }
            }

            fputcsv($output, [
                $callout['icad_number'],
                $callout['status'],
                $callout['created_at'],
                $callout['submitted_at'] ?? '',
                $callout['submitted_by'] ?? '',
                implode('; ', $members),
            ]);
        }

        fclose($output);
        exit;
    }

    private function exportPdf(array $brigade, array $callouts): void
    {
        // Generate HTML-based PDF (simple approach without external libraries)
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: attachment; filename="callouts_' . $brigade['slug'] . '_' . date('Y-m-d') . '.html"');

        echo '<!DOCTYPE html><html><head><meta charset="UTF-8">';
        echo '<title>Callouts Export - ' . htmlspecialchars($brigade['name']) . '</title>';
        echo '<style>
            body { font-family: Arial, sans-serif; font-size: 12px; margin: 20px; }
            h1 { font-size: 18px; margin-bottom: 20px; }
            .callout { border: 1px solid #ccc; margin-bottom: 20px; padding: 15px; page-break-inside: avoid; }
            .callout h2 { font-size: 14px; margin: 0 0 10px 0; }
            .meta { color: #666; margin-bottom: 10px; }
            .truck { margin: 10px 0; }
            .truck h3 { font-size: 12px; margin: 5px 0; background: #f0f0f0; padding: 5px; }
            .position { margin-left: 20px; }
            @media print { .callout { page-break-inside: avoid; } }
        </style></head><body>';

        echo '<h1>Callouts Export - ' . htmlspecialchars($brigade['name']) . '</h1>';
        echo '<p>Generated: ' . date('Y-m-d H:i:s') . '</p>';

        foreach ($callouts as $callout) {
            echo '<div class="callout">';
            echo '<h2>ICAD: ' . htmlspecialchars($callout['icad_number']) . '</h2>';
            echo '<div class="meta">';
            echo 'Status: ' . htmlspecialchars($callout['status']) . ' | ';
            echo 'Created: ' . htmlspecialchars($callout['created_at']);
            if ($callout['submitted_at']) {
                echo ' | Submitted: ' . htmlspecialchars($callout['submitted_at']);
                echo ' by ' . htmlspecialchars($callout['submitted_by'] ?? 'Unknown');
            }
            echo '</div>';

            if (!empty($callout['attendance'])) {
                foreach ($callout['attendance'] as $truck) {
                    echo '<div class="truck"><h3>' . htmlspecialchars($truck['truck_name']) . '</h3>';
                    foreach ($truck['positions'] as $pos) {
                        foreach ($pos['members'] as $m) {
                            echo '<div class="position">' . htmlspecialchars($pos['position_name']) . ': ';
                            echo htmlspecialchars($m['member_name']) . ' (' . htmlspecialchars($m['member_rank']) . ')</div>';
                        }
                    }
                    echo '</div>';
                }
            } else {
                echo '<p>No attendance recorded.</p>';
            }

            echo '</div>';
        }

        echo '</body></html>';
        exit;
    }

    // Settings
    public function settings(string $slug): void
    {
        $brigade = AdminAuth::requireAuth($slug);
        echo view('admin/settings', ['brigade' => $brigade, 'slug' => $slug]);
    }

    public function apiGetSettings(string $slug): void
    {
        $brigade = AdminAuth::requireAuth($slug);
        json_response([
            'name' => $brigade['name'],
            'email_recipients' => json_decode($brigade['email_recipients'], true) ?? [],
            'include_non_attendees' => (bool)$brigade['include_non_attendees'],
            'member_order' => $brigade['member_order'] ?? 'rank_name',
            'region' => $brigade['region'] ?? 1,
            'require_submitter_name' => (bool)($brigade['require_submitter_name'] ?? 1),
        ]);
    }

    public function apiUpdateSettings(string $slug): void
    {
        $brigade = AdminAuth::requireAuth($slug);
        $data = json_decode(file_get_contents('php://input'), true);

        $updates = [];

        if (isset($data['name'])) {
            $updates['name'] = trim($data['name']);
        }

        if (isset($data['email_recipients'])) {
            $updates['email_recipients'] = json_encode($data['email_recipients']);
        }

        if (isset($data['include_non_attendees'])) {
            $updates['include_non_attendees'] = $data['include_non_attendees'] ? 1 : 0;
        }

        if (isset($data['member_order']) && in_array($data['member_order'], ['rank_name', 'rank_joindate', 'alphabetical'])) {
            $updates['member_order'] = $data['member_order'];
        }

        if (isset($data['region'])) {
            $region = (int)$data['region'];
            if ($region >= 1 && $region <= 99) {
                $updates['region'] = $region;
            }
        }

        if (isset($data['require_submitter_name'])) {
            $updates['require_submitter_name'] = $data['require_submitter_name'] ? 1 : 0;
        }

        if (!empty($updates)) {
            Brigade::update($brigade['id'], $updates);
            audit_log($brigade['id'], null, 'settings_updated', array_keys($updates));
        }

        json_response(['success' => true]);
    }

    public function apiUpdatePin(string $slug): void
    {
        $brigade = AdminAuth::requireAuth($slug);
        $data = json_decode(file_get_contents('php://input'), true);

        $newPin = $data['pin'] ?? '';
        if (strlen($newPin) < 4 || strlen($newPin) > 6 || !ctype_digit($newPin)) {
            json_response(['error' => 'PIN must be 4-6 digits'], 400);
            return;
        }

        Brigade::update($brigade['id'], [
            'pin_hash' => password_hash($newPin, PASSWORD_DEFAULT),
        ]);

        audit_log($brigade['id'], null, 'pin_changed', []);
        json_response(['success' => true]);
    }

    public function apiUpdatePassword(string $slug): void
    {
        $brigade = AdminAuth::requireAuth($slug);
        $data = json_decode(file_get_contents('php://input'), true);

        $currentPassword = $data['current_password'] ?? '';
        $newPassword = $data['new_password'] ?? '';

        if (!Brigade::verifyAdminPassword($brigade, $currentPassword)) {
            json_response(['error' => 'Current password is incorrect'], 400);
            return;
        }

        if (strlen($newPassword) < 8) {
            json_response(['error' => 'Password must be at least 8 characters'], 400);
            return;
        }

        Brigade::update($brigade['id'], [
            'admin_password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
        ]);

        audit_log($brigade['id'], null, 'password_changed', []);
        json_response(['success' => true]);
    }

    public function apiGetQRCode(string $slug): void
    {
        $brigade = AdminAuth::requireAuth($slug);

        $url = config('app.url') . '/' . $slug;

        // Generate QR code using QR Server API (free, no API key needed)
        $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($url);

        json_response([
            'url' => $url,
            'qr_image' => $qrUrl,
        ]);
    }

    public function apiBackup(string $slug): void
    {
        $brigade = AdminAuth::requireAuth($slug);

        $dbPath = config('database.path');

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="backup_' . $slug . '_' . date('Y-m-d_His') . '.sqlite"');
        header('Content-Length: ' . filesize($dbPath));

        readfile($dbPath);
        exit;
    }

    public function apiRestore(string $slug): void
    {
        $brigade = AdminAuth::requireAuth($slug);

        if (!isset($_FILES['backup']) || $_FILES['backup']['error'] !== UPLOAD_ERR_OK) {
            json_response(['error' => 'No file uploaded or upload error'], 400);
            return;
        }

        $uploadedFile = $_FILES['backup']['tmp_name'];
        $dbPath = config('database.path');
        $backupDir = dirname($dbPath);

        // Validate it's a valid SQLite database
        try {
            $testDb = new \SQLite3($uploadedFile, SQLITE3_OPEN_READONLY);
            $result = $testDb->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='brigades'");
            $testDb->close();

            if (!$result) {
                json_response(['error' => 'Invalid database file - missing required tables'], 400);
                return;
            }
        } catch (\Exception $e) {
            json_response(['error' => 'Invalid SQLite database file'], 400);
            return;
        }

        // Create automatic backup before restore
        $autoBackupPath = $backupDir . '/pre_restore_backup_' . date('Y-m-d_His') . '.sqlite';
        if (!copy($dbPath, $autoBackupPath)) {
            json_response(['error' => 'Failed to create automatic backup'], 500);
            return;
        }

        // Close existing database connection
        db()->close();

        // Replace database with uploaded file
        if (!copy($uploadedFile, $dbPath)) {
            // Restore from auto backup if copy fails
            copy($autoBackupPath, $dbPath);
            json_response(['error' => 'Failed to restore database'], 500);
            return;
        }

        audit_log($brigade['id'], null, 'database_restored', ['auto_backup' => $autoBackupPath]);

        json_response([
            'success' => true,
            'message' => 'Database restored successfully. Auto backup saved as: ' . basename($autoBackupPath)
        ]);
    }

    public function apiDownloadQRCode(string $slug): void
    {
        $brigade = AdminAuth::requireAuth($slug);

        $url = config('app.url') . '/' . $slug;

        // Generate QR code using QR Server API
        $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=400x400&format=png&data=' . urlencode($url);

        // Fetch the image
        $imageData = @file_get_contents($qrUrl);

        if ($imageData === false) {
            json_response(['error' => 'Failed to generate QR code'], 500);
            return;
        }

        header('Content-Type: image/png');
        header('Content-Disposition: attachment; filename="qrcode_' . $slug . '.png"');
        header('Content-Length: ' . strlen($imageData));

        echo $imageData;
        exit;
    }

    // Audit
    public function audit(string $slug): void
    {
        $brigade = AdminAuth::requireAuth($slug);
        echo view('admin/audit', ['brigade' => $brigade, 'slug' => $slug]);
    }

    public function apiGetAudit(string $slug): void
    {
        $brigade = AdminAuth::requireAuth($slug);

        $limit = (int)($_GET['limit'] ?? 100);
        $offset = (int)($_GET['offset'] ?? 0);

        $logs = db()->query(
            "SELECT * FROM audit_log WHERE brigade_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?",
            [$brigade['id'], $limit, $offset]
        );

        json_response(['logs' => $logs]);
    }
}
