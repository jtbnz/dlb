<?php

namespace App\Models;

class Callout
{
    public static function findById(int $id): ?array
    {
        return db()->queryOne("SELECT * FROM callouts WHERE id = ?", [$id]);
    }

    public static function findActive(int $brigadeId): ?array
    {
        return db()->queryOne(
            "SELECT * FROM callouts WHERE brigade_id = ? AND status = 'active' ORDER BY created_at DESC LIMIT 1",
            [$brigadeId]
        );
    }

    public static function findAllActive(int $brigadeId, bool $includeHidden = false): array
    {
        if ($includeHidden) {
            return db()->query(
                "SELECT * FROM callouts WHERE brigade_id = ? AND status = 'active' ORDER BY created_at ASC",
                [$brigadeId]
            );
        }

        return db()->query(
            "SELECT * FROM callouts WHERE brigade_id = ? AND status = 'active' AND (visible = 1 OR visible IS NULL) ORDER BY created_at ASC",
            [$brigadeId]
        );
    }

    public static function findAllActiveVisible(int $brigadeId): array
    {
        return self::findAllActive($brigadeId, false);
    }

    public static function findByIcadNumber(int $brigadeId, string $icadNumber): ?array
    {
        return db()->queryOne(
            "SELECT * FROM callouts WHERE brigade_id = ? AND icad_number = ? ORDER BY created_at DESC LIMIT 1",
            [$brigadeId, $icadNumber]
        );
    }

    public static function findLastSubmitted(int $brigadeId): ?array
    {
        return db()->queryOne(
            "SELECT * FROM callouts WHERE brigade_id = ? AND status = 'submitted' ORDER BY submitted_at DESC LIMIT 1",
            [$brigadeId]
        );
    }

    public static function findLastSubmittedMuster(int $brigadeId): ?array
    {
        return db()->queryOne(
            "SELECT * FROM callouts WHERE brigade_id = ? AND status = 'submitted' AND (LOWER(icad_number) LIKE 'muster%') ORDER BY submitted_at DESC LIMIT 1",
            [$brigadeId]
        );
    }

    public static function findByBrigade(int $brigadeId, int $limit = 50, int $offset = 0): array
    {
        return db()->query(
            "SELECT * FROM callouts WHERE brigade_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?",
            [$brigadeId, $limit, $offset]
        );
    }

    public static function search(int $brigadeId, array $filters = []): array
    {
        $sql = "SELECT c.*, (SELECT COUNT(*) FROM attendance a WHERE a.callout_id = c.id) as attendance_count
                FROM callouts c WHERE c.brigade_id = ?";
        $params = [$brigadeId];

        if (!empty($filters['icad'])) {
            $sql .= " AND c.icad_number LIKE ?";
            $params[] = '%' . $filters['icad'] . '%';
        }

        if (!empty($filters['status'])) {
            $sql .= " AND c.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['from_date'])) {
            $sql .= " AND c.created_at >= ?";
            $params[] = $filters['from_date'];
        }

        if (!empty($filters['to_date'])) {
            $sql .= " AND c.created_at <= ?";
            $params[] = $filters['to_date'] . ' 23:59:59';
        }

        if (!empty($filters['sms_status'])) {
            if ($filters['sms_status'] === 'uploaded') {
                $sql .= " AND c.sms_uploaded = 1";
            } elseif ($filters['sms_status'] === 'not_uploaded') {
                $sql .= " AND (c.sms_uploaded = 0 OR c.sms_uploaded IS NULL)";
            }
        }

        $sql .= " ORDER BY c.created_at DESC";

        // Add pagination support
        if (!empty($filters['limit'])) {
            $sql .= " LIMIT ?";
            $params[] = (int)$filters['limit'];
        }

        if (!empty($filters['offset'])) {
            $sql .= " OFFSET ?";
            $params[] = (int)$filters['offset'];
        }

        return db()->query($sql, $params);
    }

    public static function create(array $data): int
    {
        return db()->insert('callouts', $data);
    }

    public static function update(int $id, array $data): int
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        return db()->update('callouts', $data, 'id = ?', [$id]);
    }

    public static function submit(int $id, string $submittedBy): int
    {
        return self::update($id, [
            'status' => 'submitted',
            'submitted_at' => date('Y-m-d H:i:s'),
            'submitted_by' => $submittedBy,
        ]);
    }

    public static function lock(int $id): int
    {
        return self::update($id, ['status' => 'locked']);
    }

    public static function unlock(int $id): int
    {
        return self::update($id, ['status' => 'active']);
    }

    public static function delete(int $id): int
    {
        return db()->delete('callouts', 'id = ?', [$id]);
    }

    public static function countForYear(int $brigadeId, ?int $year = null): int
    {
        $year = $year ?? (int)date('Y');
        $startOfYear = "{$year}-01-01 00:00:00";
        $endOfYear = "{$year}-12-31 23:59:59";

        $result = db()->queryOne(
            "SELECT COUNT(*) as count FROM callouts
             WHERE brigade_id = ? AND status = 'submitted' AND created_at >= ? AND created_at <= ?",
            [$brigadeId, $startOfYear, $endOfYear]
        );

        return (int)($result['count'] ?? 0);
    }

    public static function getWithAttendance(int $id): ?array
    {
        $callout = self::findById($id);
        if (!$callout) {
            return null;
        }

        $callout['attendance'] = Attendance::findByCallout($id);
        return $callout;
    }

    public static function findRecentByBrigade(int $brigadeId, int $days = 30): array
    {
        return db()->query(
            "SELECT * FROM callouts
             WHERE brigade_id = ?
               AND status = 'submitted'
               AND created_at >= datetime('now', '-' || ? || ' days')
             ORDER BY created_at DESC",
            [$brigadeId, $days]
        );
    }

    public static function findUnfetchedByBrigade(int $brigadeId): array
    {
        return db()->query(
            "SELECT * FROM callouts
             WHERE brigade_id = ?
               AND status = 'submitted'
               AND fenz_fetched_at IS NULL
               AND created_at >= datetime('now', '-7 days')
               AND icad_number LIKE 'F%'
             ORDER BY created_at DESC",
            [$brigadeId]
        );
    }

    public static function updateFenzData(int $id, ?string $location, ?string $duration, ?string $callType): int
    {
        return self::update($id, [
            'location' => $location,
            'duration' => $duration,
            'call_type' => $callType,
            'fenz_fetched_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function updateSmsStatus(int $id, bool $uploaded, string $updatedBy): int
    {
        return self::update($id, [
            'sms_uploaded' => $uploaded ? 1 : 0,
            'sms_uploaded_at' => date('Y-m-d H:i:s'),
            'sms_uploaded_by' => $updatedBy,
        ]);
    }

    /**
     * Get callouts with full attendance data for logbook view
     * Excludes musters (only includes callouts with ICAD starting with 'F')
     */
    public static function getLogbookData(int $brigadeId, string $fromDate, string $toDate): array
    {
        // Get submitted callouts in date range, ordered by date
        // Only include actual callouts (ICAD starting with F), exclude musters
        $callouts = db()->query(
            "SELECT * FROM callouts
             WHERE brigade_id = ?
               AND status = 'submitted'
               AND DATE(created_at) >= ?
               AND DATE(created_at) <= ?
               AND icad_number LIKE 'F%'
             ORDER BY created_at ASC",
            [$brigadeId, $fromDate, $toDate]
        );

        // Enrich each callout with attendance data grouped by truck
        foreach ($callouts as &$callout) {
            // Get attendance with member and position details, excluding standby positions
            $attendance = db()->query(
                "SELECT a.*,
                        m.name as member_name,
                        m.rank as member_rank,
                        t.name as truck_name,
                        t.is_station,
                        p.name as position_name,
                        p.sort_order as position_order,
                        p.allow_multiple
                 FROM attendance a
                 JOIN members m ON a.member_id = m.id
                 JOIN trucks t ON a.truck_id = t.id
                 JOIN positions p ON a.position_id = p.id
                 WHERE a.callout_id = ?
                   AND p.allow_multiple = 0
                 ORDER BY t.sort_order, p.sort_order",
                [$callout['id']]
            );

            // Group attendance by truck
            $trucks = [];
            foreach ($attendance as $a) {
                $truckId = $a['truck_id'];
                if (!isset($trucks[$truckId])) {
                    $trucks[$truckId] = [
                        'id' => $truckId,
                        'name' => $a['truck_name'],
                        'is_station' => $a['is_station'],
                        'personnel' => [],
                    ];
                }

                // Format member name as "RANK SURNAME INITIAL"
                $nameParts = explode(' ', $a['member_name']);
                $surname = array_pop($nameParts);
                $initial = !empty($nameParts) ? strtoupper(substr($nameParts[0], 0, 1)) : '';
                $formattedName = strtoupper($a['member_rank']) . ' ' . strtoupper($surname) . ' ' . $initial;

                // Map position to display role (OIC, DR, 1, 2, 3, 4)
                $role = self::mapPositionToRole($a['position_name'], $a['position_order']);

                $trucks[$truckId]['personnel'][] = [
                    'role' => $role,
                    'name' => trim($formattedName),
                    'position_order' => $a['position_order'],
                ];
            }

            // Sort personnel within each truck by position order
            foreach ($trucks as &$truck) {
                usort($truck['personnel'], function($a, $b) {
                    return $a['position_order'] - $b['position_order'];
                });
            }

            $callout['trucks'] = array_values($trucks);
        }

        return $callouts;
    }

    /**
     * Map position name/order to display role
     */
    private static function mapPositionToRole(string $positionName, int $order): string
    {
        $nameLower = strtolower($positionName);

        if (strpos($nameLower, 'oic') !== false || strpos($nameLower, 'officer') !== false) {
            return 'OIC';
        }
        if (strpos($nameLower, 'driver') !== false || strpos($nameLower, 'dr') !== false) {
            return 'DR';
        }

        // For numbered positions, use the sort order
        if ($order <= 2) {
            return $order == 1 ? 'OIC' : 'DR';
        }

        return (string)($order - 2);
    }
}
