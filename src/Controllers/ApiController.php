<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Brigade;
use App\Models\Callout;
use App\Models\Attendance;
use App\Models\Member;
use App\Models\Truck;
use App\Middleware\ApiAuth;

/**
 * API v1 Controller
 * Handles token-authenticated API endpoints for external integrations
 */
class ApiController
{
    /**
     * Create a new muster
     * POST /{slug}/api/v1/musters
     */
    public function createMuster(string $slug): void
    {
        $token = ApiAuth::requireAuth($slug, 'musters:create');
        $brigade = $token['brigade'];

        $data = $this->getJsonInput();

        // Validate required fields
        if (empty($data['call_date'])) {
            $this->errorResponse('VALIDATION_ERROR', 'call_date is required', 400);
        }

        // Validate and generate ICAD number
        $icadNumber = $data['icad_number'] ?? 'muster';
        $isMuster = strtolower($icadNumber) === 'muster';
        $startsWithF = strtoupper(substr($icadNumber, 0, 1)) === 'F';

        if (!$isMuster && !$startsWithF) {
            $this->errorResponse('VALIDATION_ERROR', 'ICAD number must start with F or be "muster"', 400);
        }

        if ($isMuster) {
            $icadNumber = 'muster_' . str_replace('-', '', $data['call_date']);
        }

        // Check for duplicate ICAD number
        $existing = Callout::findByIcadNumber($brigade['id'], $icadNumber);
        if ($existing) {
            $this->errorResponse('VALIDATION_ERROR', 'A muster with this ICAD number already exists', 400);
        }

        $calloutData = [
            'brigade_id' => $brigade['id'],
            'icad_number' => $icadNumber,
            'status' => 'active',
            'visible' => isset($data['visible']) ? ($data['visible'] ? 1 : 0) : 1,
            'call_date' => $data['call_date'],
            'call_time' => $data['call_time'] ?? null,
            'location' => $data['location'] ?? null,
            'call_type' => $data['call_type'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        $id = Callout::create($calloutData);

        audit_log($brigade['id'], $id, 'api_muster_created', [
            'token_id' => $token['id'],
            'token_name' => $token['name'],
            'icad_number' => $icadNumber,
        ]);

        $this->jsonResponse([
            'success' => true,
            'muster' => [
                'id' => $id,
                'icad_number' => $icadNumber,
                'status' => 'active',
                'visible' => (bool)$calloutData['visible'],
                'created_at' => date('c'),
            ],
        ], 201);
    }

    /**
     * List musters
     * GET /{slug}/api/v1/musters
     */
    public function listMusters(string $slug): void
    {
        $token = ApiAuth::requireAuth($slug, 'musters:read');
        $brigade = $token['brigade'];

        $filters = [
            'status' => $_GET['status'] ?? '',
            'from_date' => $_GET['from'] ?? '',
            'to_date' => $_GET['to'] ?? '',
        ];

        // Use existing search method but include hidden musters for API
        $musters = Callout::search($brigade['id'], $filters);

        $result = [];
        foreach ($musters as $muster) {
            $attendanceCount = count(Attendance::getAssignedMemberIds($muster['id']));

            $result[] = [
                'id' => (int)$muster['id'],
                'icad_number' => $muster['icad_number'],
                'call_date' => $muster['call_date'] ?? substr($muster['created_at'], 0, 10),
                'call_time' => $muster['call_time'] ?? null,
                'location' => $muster['location'],
                'call_type' => $muster['call_type'],
                'status' => $muster['status'],
                'visible' => (bool)($muster['visible'] ?? 1),
                'attendance_count' => $attendanceCount,
                'created_at' => date('c', strtotime($muster['created_at'])),
                'submitted_at' => $muster['submitted_at'] ? date('c', strtotime($muster['submitted_at'])) : null,
            ];
        }

        $this->jsonResponse([
            'success' => true,
            'musters' => $result,
        ]);
    }

    /**
     * Update muster visibility
     * PUT /{slug}/api/v1/musters/{id}/visibility
     */
    public function updateVisibility(string $slug, string $id): void
    {
        $token = ApiAuth::requireAuth($slug, 'musters:update');
        $brigade = $token['brigade'];

        $callout = Callout::findById((int)$id);
        if (!$callout || $callout['brigade_id'] !== $brigade['id']) {
            $this->errorResponse('NOT_FOUND', 'Muster not found', 404);
        }

        $data = $this->getJsonInput();

        if (!isset($data['visible'])) {
            $this->errorResponse('VALIDATION_ERROR', 'visible field is required', 400);
        }

        Callout::update((int)$id, [
            'visible' => $data['visible'] ? 1 : 0,
        ]);

        audit_log($brigade['id'], (int)$id, 'api_visibility_updated', [
            'token_id' => $token['id'],
            'visible' => $data['visible'],
        ]);

        $this->jsonResponse([
            'success' => true,
            'muster' => [
                'id' => (int)$id,
                'visible' => (bool)$data['visible'],
                'updated_at' => date('c'),
            ],
        ]);
    }

    /**
     * Set member attendance status for a muster
     * POST /{slug}/api/v1/musters/{id}/attendance
     */
    public function setAttendance(string $slug, string $musterId): void
    {
        $token = ApiAuth::requireAuth($slug, 'attendance:create');
        $brigade = $token['brigade'];

        $callout = Callout::findById((int)$musterId);
        if (!$callout || $callout['brigade_id'] !== $brigade['id']) {
            $this->errorResponse('NOT_FOUND', 'Muster not found', 404);
        }

        if ($callout['status'] === 'submitted') {
            $this->errorResponse('MUSTER_SUBMITTED', 'Cannot modify attendance for a submitted muster', 409);
        }

        $data = $this->getJsonInput();

        if (empty($data['member_id'])) {
            $this->errorResponse('VALIDATION_ERROR', 'member_id is required', 400);
        }

        if (empty($data['status']) || !in_array($data['status'], ['I', 'L', 'A'])) {
            $this->errorResponse('VALIDATION_ERROR', 'status must be I (In Attendance), L (Leave), or A (Absent)', 400);
        }

        $member = Member::findById((int)$data['member_id']);
        if (!$member || $member['brigade_id'] !== $brigade['id']) {
            $this->errorResponse('VALIDATION_ERROR', 'Invalid member_id', 400);
        }

        // For Leave/Absent, we don't need truck/position
        // For In Attendance via API, we also allow no truck/position (will need manual assignment later)
        $attendanceData = [
            'callout_id' => (int)$musterId,
            'member_id' => (int)$data['member_id'],
            'status' => $data['status'],
            'source' => 'api',
            'notes' => $data['notes'] ?? null,
        ];

        // If truck/position provided (for I status), validate and include them
        if ($data['status'] === 'I' && !empty($data['truck_id']) && !empty($data['position_id'])) {
            $truck = Truck::findById((int)$data['truck_id']);
            if (!$truck || $truck['brigade_id'] !== $brigade['id']) {
                $this->errorResponse('VALIDATION_ERROR', 'Invalid truck_id', 400);
            }
            $attendanceData['truck_id'] = (int)$data['truck_id'];
            $attendanceData['position_id'] = (int)$data['position_id'];
        }

        // Delete existing attendance for this member in this callout
        Attendance::deleteByMember((int)$musterId, (int)$data['member_id']);

        // Create new attendance record
        $attendanceId = Attendance::createWithStatus($attendanceData);

        audit_log($brigade['id'], (int)$musterId, 'api_attendance_set', [
            'token_id' => $token['id'],
            'member_id' => $data['member_id'],
            'status' => $data['status'],
        ]);

        $this->jsonResponse([
            'success' => true,
            'attendance' => [
                'id' => $attendanceId,
                'member_id' => (int)$data['member_id'],
                'member_name' => $member['display_name'],
                'status' => $data['status'],
                'notes' => $data['notes'] ?? null,
                'created_at' => date('c'),
            ],
        ], 201);
    }

    /**
     * Bulk set attendance for a muster
     * POST /{slug}/api/v1/musters/{id}/attendance/bulk
     */
    public function bulkSetAttendance(string $slug, string $musterId): void
    {
        $token = ApiAuth::requireAuth($slug, 'attendance:create');
        $brigade = $token['brigade'];

        $callout = Callout::findById((int)$musterId);
        if (!$callout || $callout['brigade_id'] !== $brigade['id']) {
            $this->errorResponse('NOT_FOUND', 'Muster not found', 404);
        }

        if ($callout['status'] === 'submitted') {
            $this->errorResponse('MUSTER_SUBMITTED', 'Cannot modify attendance for a submitted muster', 409);
        }

        $data = $this->getJsonInput();

        if (empty($data['attendance']) || !is_array($data['attendance'])) {
            $this->errorResponse('VALIDATION_ERROR', 'attendance array is required', 400);
        }

        $results = [];
        $created = 0;
        $failed = 0;

        foreach ($data['attendance'] as $item) {
            if (empty($item['member_id']) || empty($item['status'])) {
                $results[] = [
                    'member_id' => $item['member_id'] ?? null,
                    'status' => 'error',
                    'error' => 'member_id and status are required',
                ];
                $failed++;
                continue;
            }

            if (!in_array($item['status'], ['I', 'L', 'A'])) {
                $results[] = [
                    'member_id' => $item['member_id'],
                    'status' => 'error',
                    'error' => 'Invalid status value',
                ];
                $failed++;
                continue;
            }

            $member = Member::findById((int)$item['member_id']);
            if (!$member || $member['brigade_id'] !== $brigade['id']) {
                $results[] = [
                    'member_id' => $item['member_id'],
                    'status' => 'error',
                    'error' => 'Invalid member_id',
                ];
                $failed++;
                continue;
            }

            try {
                // Delete existing and create new
                Attendance::deleteByMember((int)$musterId, (int)$item['member_id']);

                Attendance::createWithStatus([
                    'callout_id' => (int)$musterId,
                    'member_id' => (int)$item['member_id'],
                    'status' => $item['status'],
                    'source' => 'api',
                    'notes' => $item['notes'] ?? null,
                ]);

                $results[] = [
                    'member_id' => (int)$item['member_id'],
                    'status' => 'success',
                ];
                $created++;
            } catch (\Exception $e) {
                $results[] = [
                    'member_id' => $item['member_id'],
                    'status' => 'error',
                    'error' => 'Failed to create attendance record',
                ];
                $failed++;
            }
        }

        audit_log($brigade['id'], (int)$musterId, 'api_bulk_attendance_set', [
            'token_id' => $token['id'],
            'created' => $created,
            'failed' => $failed,
        ]);

        $this->jsonResponse([
            'success' => true,
            'created' => $created,
            'failed' => $failed,
            'results' => $results,
        ]);
    }

    /**
     * Get muster attendance
     * GET /{slug}/api/v1/musters/{id}/attendance
     */
    public function getAttendance(string $slug, string $musterId): void
    {
        $token = ApiAuth::requireAuth($slug, 'attendance:read');
        $brigade = $token['brigade'];

        $callout = Callout::findById((int)$musterId);
        if (!$callout || $callout['brigade_id'] !== $brigade['id']) {
            $this->errorResponse('NOT_FOUND', 'Muster not found', 404);
        }

        $attendance = Attendance::findByCalloutWithStatus((int)$musterId);

        // Count by status
        $totalMembers = count(Member::findByBrigade($brigade['id'], true));
        $inAttendance = 0;
        $onLeave = 0;
        $absent = 0;

        $attendanceResult = [];
        foreach ($attendance as $record) {
            $status = $record['status'] ?? 'I';

            if ($status === 'I') {
                $inAttendance++;
            } elseif ($status === 'L') {
                $onLeave++;
            } elseif ($status === 'A') {
                $absent++;
            }

            $item = [
                'member_id' => (int)$record['member_id'],
                'member_name' => $record['member_name'],
                'status' => $status,
            ];

            if ($status === 'I' && !empty($record['truck_name'])) {
                $item['truck'] = $record['truck_name'];
                $item['position'] = $record['position_name'];
            }

            if (!empty($record['notes'])) {
                $item['notes'] = $record['notes'];
            }

            $attendanceResult[] = $item;
        }

        $this->jsonResponse([
            'success' => true,
            'muster' => [
                'id' => (int)$callout['id'],
                'icad_number' => $callout['icad_number'],
                'call_date' => $callout['call_date'] ?? substr($callout['created_at'], 0, 10),
                'status' => $callout['status'],
            ],
            'attendance' => $attendanceResult,
            'summary' => [
                'total_members' => $totalMembers,
                'in_attendance' => $inAttendance,
                'on_leave' => $onLeave,
                'absent' => $absent,
            ],
        ]);
    }

    /**
     * List members
     * GET /{slug}/api/v1/members
     */
    public function listMembers(string $slug): void
    {
        $token = ApiAuth::requireAuth($slug, 'members:read');
        $brigade = $token['brigade'];

        $activeOnly = ($_GET['active'] ?? '1') !== '0';
        $members = Member::findByBrigade($brigade['id'], $activeOnly);

        $result = [];
        foreach ($members as $member) {
            $result[] = [
                'id' => (int)$member['id'],
                'name' => $member['display_name'],
                'rank' => $member['rank'],
                'is_active' => (bool)$member['is_active'],
                'created_at' => date('c', strtotime($member['created_at'])),
            ];
        }

        $this->jsonResponse([
            'success' => true,
            'members' => $result,
        ]);
    }

    /**
     * Create a member
     * POST /{slug}/api/v1/members
     */
    public function createMember(string $slug): void
    {
        $token = ApiAuth::requireAuth($slug, 'members:create');
        $brigade = $token['brigade'];

        $data = $this->getJsonInput();

        if (empty($data['name'])) {
            $this->errorResponse('VALIDATION_ERROR', 'name is required', 400);
        }

        $memberData = [
            'brigade_id' => $brigade['id'],
            'display_name' => trim($data['name']),
            'rank' => trim($data['rank'] ?? ''),
            'first_name' => trim($data['first_name'] ?? ''),
            'last_name' => trim($data['last_name'] ?? ''),
            'email' => trim($data['email'] ?? ''),
            'is_active' => isset($data['is_active']) ? ($data['is_active'] ? 1 : 0) : 1,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        $id = Member::create($memberData);

        audit_log($brigade['id'], null, 'api_member_created', [
            'token_id' => $token['id'],
            'member_id' => $id,
            'name' => $data['name'],
        ]);

        $this->jsonResponse([
            'success' => true,
            'member' => [
                'id' => $id,
                'name' => $memberData['display_name'],
                'rank' => $memberData['rank'],
                'is_active' => (bool)$memberData['is_active'],
                'created_at' => date('c'),
            ],
        ], 201);
    }

    /**
     * Get JSON input from request body
     */
    private function getJsonInput(): array
    {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->errorResponse('VALIDATION_ERROR', 'Invalid JSON in request body', 400);
        }

        return $data ?? [];
    }

    /**
     * Send JSON response
     */
    private function jsonResponse(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    /**
     * Send error response
     */
    private function errorResponse(string $code, string $message, int $status): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ]);
        exit;
    }
}
