<?php

namespace App\Controllers;

use App\Models\Brigade;
use App\Models\Callout;
use App\Models\Attendance;
use App\Models\Member;
use App\Models\Truck;
use App\Middleware\PinAuth;
use App\Services\EmailService;

class AttendanceController
{
    public function index(string $slug): void
    {
        $brigade = PinAuth::requireAuth($slug);

        echo view('attendance/entry', [
            'brigade' => $brigade,
            'slug' => $slug,
        ]);
    }

    public function getActive(string $slug): void
    {
        $brigade = PinAuth::requireAuth($slug);
        $memberOrder = Brigade::getMemberOrder($brigade);

        $callout = Callout::findActive($brigade['id']);

        if ($callout) {
            $callout['attendance'] = Attendance::findByCalloutGrouped($callout['id']);
            $callout['available_members'] = Attendance::getAvailableMembers($callout['id'], $brigade['id'], $memberOrder);
        }

        json_response([
            'callout' => $callout,
            'trucks' => Truck::findByBrigadeWithPositions($brigade['id']),
            'members' => Member::findByBrigadeOrdered($brigade['id'], $memberOrder),
        ]);
    }

    public function createCallout(string $slug): void
    {
        $brigade = PinAuth::requireAuth($slug);

        $data = json_decode(file_get_contents('php://input'), true);
        $icadNumber = trim($data['icad_number'] ?? '');

        if (empty($icadNumber)) {
            json_response(['error' => 'ICAD number is required'], 400);
            return;
        }

        // Check for existing active callout
        $existing = Callout::findActive($brigade['id']);
        if ($existing) {
            json_response(['error' => 'An active callout already exists'], 400);
            return;
        }

        $calloutId = Callout::create([
            'brigade_id' => $brigade['id'],
            'icad_number' => $icadNumber,
            'status' => 'active',
        ]);

        audit_log($brigade['id'], $calloutId, 'callout_created', ['icad_number' => $icadNumber]);

        $memberOrder = Brigade::getMemberOrder($brigade);
        $callout = Callout::findById($calloutId);
        $callout['attendance'] = [];
        $callout['available_members'] = Member::findByBrigadeOrdered($brigade['id'], $memberOrder);

        json_response(['callout' => $callout]);
    }

    public function updateCallout(string $slug, string $calloutId): void
    {
        $brigade = PinAuth::requireAuth($slug);

        $callout = Callout::findById((int)$calloutId);

        if (!$callout || $callout['brigade_id'] !== $brigade['id']) {
            json_response(['error' => 'Callout not found'], 404);
            return;
        }

        if ($callout['status'] !== 'active') {
            json_response(['error' => 'Cannot modify a submitted callout'], 400);
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);

        if (isset($data['icad_number'])) {
            Callout::update((int)$calloutId, ['icad_number' => trim($data['icad_number'])]);
            audit_log($brigade['id'], (int)$calloutId, 'callout_updated', ['icad_number' => $data['icad_number']]);
        }

        json_response(['success' => true]);
    }

    public function submitCallout(string $slug, string $calloutId): void
    {
        $brigade = PinAuth::requireAuth($slug);

        $callout = Callout::findById((int)$calloutId);

        if (!$callout || $callout['brigade_id'] !== $brigade['id']) {
            json_response(['error' => 'Callout not found'], 404);
            return;
        }

        if ($callout['status'] !== 'active') {
            json_response(['error' => 'Callout already submitted'], 400);
            return;
        }

        $submittedBy = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        Callout::submit((int)$calloutId, $submittedBy);

        audit_log($brigade['id'], (int)$calloutId, 'callout_submitted', []);

        // Reload callout to get updated submitted_at timestamp
        $callout = Callout::findById((int)$calloutId);

        // Send email (wrapped in try-catch to prevent breaking the response)
        try {
            $emailService = new EmailService();
            $emailService->sendAttendanceEmail($brigade, $callout);
        } catch (\Exception $e) {
            // Log error but don't fail the submission
            error_log('Email send failed: ' . $e->getMessage());
        }

        json_response(['success' => true]);
    }

    public function getMembers(string $slug): void
    {
        $brigade = PinAuth::requireAuth($slug);
        json_response(['members' => Member::findByBrigade($brigade['id'])]);
    }

    public function getTrucks(string $slug): void
    {
        $brigade = PinAuth::requireAuth($slug);
        json_response(['trucks' => Truck::findByBrigadeWithPositions($brigade['id'])]);
    }

    public function addAttendance(string $slug): void
    {
        $brigade = PinAuth::requireAuth($slug);
        $memberOrder = Brigade::getMemberOrder($brigade);

        $data = json_decode(file_get_contents('php://input'), true);

        $calloutId = (int)($data['callout_id'] ?? 0);
        $memberId = (int)($data['member_id'] ?? 0);
        $truckId = (int)($data['truck_id'] ?? 0);
        $positionId = (int)($data['position_id'] ?? 0);

        // Validate callout
        $callout = Callout::findById($calloutId);
        if (!$callout || $callout['brigade_id'] !== $brigade['id']) {
            json_response(['error' => 'Invalid callout'], 400);
            return;
        }

        if ($callout['status'] !== 'active') {
            json_response(['error' => 'Cannot modify a submitted callout'], 400);
            return;
        }

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

        $position = \App\Models\Position::findById($positionId);
        if (!$position || $position['truck_id'] !== $truckId) {
            json_response(['error' => 'Invalid position'], 400);
            return;
        }

        // Check if position allows multiple (for standby)
        if (!$position['allow_multiple']) {
            // Check if position is already taken by ANOTHER member
            $existing = db()->queryOne(
                "SELECT id, member_id FROM attendance WHERE callout_id = ? AND position_id = ?",
                [$calloutId, $positionId]
            );
            if ($existing && $existing['member_id'] !== $memberId) {
                // Position taken by someone else - return current state for UI refresh
                json_response([
                    'error' => 'Position already assigned to another member',
                    'attendance' => Attendance::findByCalloutGrouped($calloutId),
                    'available_members' => Attendance::getAvailableMembers($calloutId, $brigade['id'], $memberOrder),
                ], 409);
                return;
            }
        }

        try {
            $attendanceId = Attendance::create([
                'callout_id' => $calloutId,
                'member_id' => $memberId,
                'truck_id' => $truckId,
                'position_id' => $positionId,
            ]);

            audit_log($brigade['id'], $calloutId, 'attendance_added', [
                'member_id' => $memberId,
                'member_name' => $member['name'],
                'truck_id' => $truckId,
                'position_id' => $positionId,
            ]);

            // Notify SSE clients
            $this->notifySSE($calloutId);

            json_response([
                'success' => true,
                'attendance_id' => $attendanceId,
                'attendance' => Attendance::findByCalloutGrouped($calloutId),
                'available_members' => Attendance::getAvailableMembers($calloutId, $brigade['id'], $memberOrder),
            ]);
        } catch (\Exception $e) {
            // Handle database constraint errors gracefully
            json_response([
                'error' => 'Failed to save. Please try again.',
                'attendance' => Attendance::findByCalloutGrouped($calloutId),
                'available_members' => Attendance::getAvailableMembers($calloutId, $brigade['id'], $memberOrder),
            ], 409);
        }
    }

    public function removeAttendance(string $slug, string $attendanceId): void
    {
        $brigade = PinAuth::requireAuth($slug);
        $memberOrder = Brigade::getMemberOrder($brigade);

        $attendance = Attendance::findById((int)$attendanceId);
        if (!$attendance) {
            json_response(['error' => 'Attendance not found'], 404);
            return;
        }

        $callout = Callout::findById($attendance['callout_id']);
        if (!$callout || $callout['brigade_id'] !== $brigade['id']) {
            json_response(['error' => 'Invalid callout'], 400);
            return;
        }

        if ($callout['status'] !== 'active') {
            json_response(['error' => 'Cannot modify a submitted callout'], 400);
            return;
        }

        $member = Member::findById($attendance['member_id']);

        Attendance::delete((int)$attendanceId);

        audit_log($brigade['id'], $callout['id'], 'attendance_removed', [
            'member_id' => $attendance['member_id'],
            'member_name' => $member ? $member['name'] : 'Unknown',
        ]);

        // Notify SSE clients
        $this->notifySSE($callout['id']);

        json_response([
            'success' => true,
            'attendance' => Attendance::findByCalloutGrouped($callout['id']),
            'available_members' => Attendance::getAvailableMembers($callout['id'], $brigade['id'], $memberOrder),
        ]);
    }

    private function notifySSE(int $calloutId): void
    {
        // Write to a file that SSE clients poll
        $sseFile = __DIR__ . '/../../data/sse_' . $calloutId . '.json';
        file_put_contents($sseFile, json_encode([
            'timestamp' => microtime(true),
            'callout_id' => $calloutId,
        ]));
    }
}
