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

        // Get ALL active callouts (supports multiple simultaneous callouts)
        $callouts = Callout::findAllActive($brigade['id']);

        // Enrich each callout with attendance data
        foreach ($callouts as &$callout) {
            $callout['attendance'] = Attendance::findByCalloutGrouped($callout['id']);
            $callout['leave_members'] = Attendance::findLeaveByCallout($callout['id']);
            $callout['absent_members'] = Attendance::findAbsentByCallout($callout['id']);
            $callout['available_members'] = Attendance::getAvailableMembers($callout['id'], $brigade['id'], $memberOrder);
        }

        $lastCallout = Callout::findLastSubmitted($brigade['id']);

        json_response([
            'callouts' => $callouts,  // Array of all active callouts
            'trucks' => Truck::findByBrigadeWithPositions($brigade['id']),
            'members' => Member::findByBrigadeOrdered($brigade['id'], $memberOrder),
            'callouts_this_year' => Callout::countForYear($brigade['id']),
            'last_callout' => $lastCallout ? [
                'icad_number' => $lastCallout['icad_number'],
                'created_at' => $lastCallout['created_at'],
            ] : null,
            'require_submitter_name' => (bool)($brigade['require_submitter_name'] ?? 1),
        ]);
    }

    public function createCallout(string $slug): void
    {
        $brigade = PinAuth::requireAuth($slug);
        $memberOrder = Brigade::getMemberOrder($brigade);

        $data = json_decode(file_get_contents('php://input'), true);
        $icadNumber = trim($data['icad_number'] ?? '');
        $location = trim($data['location'] ?? '');
        $callType = trim($data['call_type'] ?? '');
        $callDateTime = trim($data['call_datetime'] ?? '');

        if (empty($icadNumber)) {
            json_response(['error' => 'ICAD number is required'], 400);
            return;
        }

        // Validate ICAD format: must start with F (case-insensitive) or be "muster"
        $isMuster = strtolower($icadNumber) === 'muster';
        $startsWithF = strtoupper(substr($icadNumber, 0, 1)) === 'F';
        if (!$isMuster && !$startsWithF) {
            json_response(['error' => 'ICAD number must start with F or be "muster"'], 400);
            return;
        }

        // Append date to muster to make each unique (e.g., "Muster-2024-12-24")
        if ($isMuster) {
            $icadNumber = 'Muster-' . date('Y-m-d');
        }

        // Multiple simultaneous callouts are now allowed
        // Check if this ICAD number has been used before
        $existingCallout = Callout::findByIcadNumber($brigade['id'], $icadNumber);
        if ($existingCallout) {
            if ($existingCallout['status'] === 'active') {
                // Resume existing active callout
                $existingCallout['attendance'] = Attendance::findByCalloutGrouped($existingCallout['id']);
                $existingCallout['available_members'] = Attendance::getAvailableMembers($existingCallout['id'], $brigade['id'], $memberOrder);
                json_response(['callout' => $existingCallout, 'resumed' => true]);
                return;
            } else {
                // Already submitted - let client know
                $submittedAt = $existingCallout['submitted_at'] ?? $existingCallout['created_at'];
                json_response([
                    'already_submitted' => true,
                    'submitted_at' => $submittedAt,
                    'icad_number' => $icadNumber,
                ]);
                return;
            }
        }

        // Build callout data
        $calloutData = [
            'brigade_id' => $brigade['id'],
            'icad_number' => $icadNumber,
            'status' => 'active',
        ];

        // Add optional fields if provided
        if (!empty($location)) {
            $calloutData['location'] = $location;
        }
        if (!empty($callType)) {
            $calloutData['call_type'] = $callType;
        }
        if (!empty($callDateTime)) {
            // Convert datetime-local format (YYYY-MM-DDTHH:MM) to database format
            $calloutData['created_at'] = str_replace('T', ' ', $callDateTime) . ':00';
        }

        $calloutId = Callout::create($calloutData);

        audit_log($brigade['id'], $calloutId, 'callout_created', [
            'icad_number' => $icadNumber,
            'location' => $location,
            'call_type' => $callType,
        ]);

        // For musters, auto-populate all active members to Station/Standby
        if ($isMuster) {
            $this->autoPopulateMusterAttendance($brigade['id'], $calloutId);
        }

        $callout = Callout::findById($calloutId);
        $callout['attendance'] = Attendance::findByCalloutGrouped($calloutId);
        $callout['leave_members'] = Attendance::findLeaveByCallout($calloutId);
        $callout['absent_members'] = Attendance::findAbsentByCallout($calloutId);
        $callout['available_members'] = Attendance::getAvailableMembers($calloutId, $brigade['id'], $memberOrder);

        json_response(['callout' => $callout]);
    }

    /**
     * Auto-populate all active members to Station/Standby for a muster
     */
    private function autoPopulateMusterAttendance(int $brigadeId, int $calloutId): void
    {
        // Find the Station truck (is_station = 1)
        $trucks = Truck::findByBrigadeWithPositions($brigadeId);
        $stationTruck = null;
        $standbyPosition = null;

        foreach ($trucks as $truck) {
            if ($truck['is_station'] == 1) {
                $stationTruck = $truck;
                // Find the standby position (allow_multiple = 1)
                foreach ($truck['positions'] as $position) {
                    if ($position['allow_multiple'] == 1) {
                        $standbyPosition = $position;
                        break;
                    }
                }
                break;
            }
        }

        if (!$stationTruck || !$standbyPosition) {
            // No station truck configured, skip auto-population
            return;
        }

        // Get all active members
        $members = Member::findByBrigade($brigadeId, true);

        // Add each member to the standby position
        foreach ($members as $member) {
            try {
                Attendance::create([
                    'callout_id' => $calloutId,
                    'member_id' => $member['id'],
                    'truck_id' => $stationTruck['id'],
                    'position_id' => $standbyPosition['id'],
                    'status' => 'I',
                    'source' => 'auto',
                ]);
            } catch (\Exception $e) {
                // Skip if there's a conflict
            }
        }
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

        // Get submitted_by from request body if provided
        $data = json_decode(file_get_contents('php://input'), true);
        $submittedBy = !empty($data['submitted_by']) ? trim($data['submitted_by']) : ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
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

    public function getLastCallAttendance(string $slug): void
    {
        $brigade = PinAuth::requireAuth($slug);

        // Get the last submitted callout for this brigade
        $lastCallout = Callout::findLastSubmitted($brigade['id']);

        if (!$lastCallout) {
            json_response(['attendance' => [], 'message' => 'No previous callout found']);
            return;
        }

        $attendance = Attendance::findByCallout($lastCallout['id']);

        json_response([
            'callout' => [
                'id' => $lastCallout['id'],
                'icad_number' => $lastCallout['icad_number'],
                'submitted_at' => $lastCallout['submitted_at'],
            ],
            'attendance' => $attendance,
        ]);
    }

    public function copyLastCall(string $slug, string $calloutId): void
    {
        $brigade = PinAuth::requireAuth($slug);
        $memberOrder = Brigade::getMemberOrder($brigade);

        $callout = Callout::findById((int)$calloutId);
        if (!$callout || $callout['brigade_id'] !== $brigade['id']) {
            json_response(['error' => 'Invalid callout'], 400);
            return;
        }

        if ($callout['status'] !== 'active') {
            json_response(['error' => 'Cannot modify a submitted callout'], 400);
            return;
        }

        // Get the last submitted muster (not callout)
        $lastCallout = Callout::findLastSubmittedMuster($brigade['id']);
        if (!$lastCallout) {
            json_response(['error' => 'No previous muster to copy from'], 400);
            return;
        }

        // Get attendance from last callout
        $lastAttendance = Attendance::findByCallout($lastCallout['id']);

        // Copy each attendance record to the new callout
        $copied = 0;
        foreach ($lastAttendance as $record) {
            // Check if member is still active
            $member = Member::findById($record['member_id']);
            if (!$member || !$member['is_active']) {
                continue;
            }

            // Check if truck and position still exist
            $truck = Truck::findById($record['truck_id']);
            $position = \App\Models\Position::findById($record['position_id']);
            if (!$truck || !$position) {
                continue;
            }

            try {
                Attendance::create([
                    'callout_id' => (int)$calloutId,
                    'member_id' => $record['member_id'],
                    'truck_id' => $record['truck_id'],
                    'position_id' => $record['position_id'],
                ]);
                $copied++;
            } catch (\Exception $e) {
                // Skip if there's a conflict
            }
        }

        audit_log($brigade['id'], (int)$calloutId, 'attendance_copied', [
            'from_callout' => $lastCallout['id'],
            'from_icad' => $lastCallout['icad_number'],
            'records_copied' => $copied,
        ]);

        // Notify SSE clients
        $this->notifySSE((int)$calloutId);

        json_response([
            'success' => true,
            'copied' => $copied,
            'from_icad' => $lastCallout['icad_number'],
            'attendance' => Attendance::findByCalloutGrouped((int)$calloutId),
            'available_members' => Attendance::getAvailableMembers((int)$calloutId, $brigade['id'], $memberOrder),
        ]);
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

    public function history(string $slug): void
    {
        $brigade = PinAuth::requireAuth($slug);

        echo view('attendance/history', [
            'brigade' => $brigade,
            'slug' => $slug,
        ]);
    }

    public function apiGetHistory(string $slug): void
    {
        $brigade = PinAuth::requireAuth($slug);

        // Get recent callouts (last 30 days)
        $callouts = Callout::findRecentByBrigade($brigade['id'], 30);

        // Enrich with attendance counts per truck
        foreach ($callouts as &$callout) {
            $attendance = Attendance::findByCallout($callout['id']);
            $callout['crew_count'] = count($attendance);

            // Group by truck for per-truck counts
            $truckCounts = [];
            foreach ($attendance as $a) {
                $truckName = $a['truck_name'] ?? 'Unknown';
                if (!isset($truckCounts[$truckName])) {
                    $truckCounts[$truckName] = 0;
                }
                $truckCounts[$truckName]++;
            }
            $callout['truck_crews'] = $truckCounts;
        }

        json_response([
            'callouts' => $callouts,
        ]);
    }

    public function apiGetHistoryDetail(string $slug, string $calloutId): void
    {
        $brigade = PinAuth::requireAuth($slug);

        $callout = Callout::findById((int)$calloutId);

        if (!$callout || $callout['brigade_id'] !== $brigade['id']) {
            json_response(['error' => 'Callout not found'], 404);
            return;
        }

        // Get grouped attendance
        $callout['attendance_grouped'] = Attendance::findByCalloutGrouped((int)$calloutId);

        json_response([
            'callout' => $callout,
        ]);
    }
}
