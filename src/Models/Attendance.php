<?php

namespace App\Models;

class Attendance
{
    public static function findById(int $id): ?array
    {
        return db()->queryOne("SELECT * FROM attendance WHERE id = ?", [$id]);
    }

    public static function findByCallout(int $calloutId): array
    {
        return db()->query(
            "SELECT a.*, m.display_name as member_name, m.rank as member_rank,
                    t.name as truck_name, t.is_station, p.name as position_name, p.allow_multiple
             FROM attendance a
             JOIN members m ON a.member_id = m.id
             LEFT JOIN trucks t ON a.truck_id = t.id
             LEFT JOIN positions p ON a.position_id = p.id
             WHERE a.callout_id = ? AND (a.status = 'I' OR a.status IS NULL)
             ORDER BY t.sort_order, p.sort_order, m.display_name",
            [$calloutId]
        );
    }

    public static function findByCalloutWithStatus(int $calloutId): array
    {
        return db()->query(
            "SELECT a.*, m.display_name as member_name, m.rank as member_rank,
                    t.name as truck_name, t.is_station, p.name as position_name, p.allow_multiple
             FROM attendance a
             JOIN members m ON a.member_id = m.id
             LEFT JOIN trucks t ON a.truck_id = t.id
             LEFT JOIN positions p ON a.position_id = p.id
             WHERE a.callout_id = ?
             ORDER BY a.status, t.sort_order, p.sort_order, m.display_name",
            [$calloutId]
        );
    }

    public static function findLeaveByCallout(int $calloutId): array
    {
        return db()->query(
            "SELECT a.*, m.display_name as member_name, m.rank as member_rank
             FROM attendance a
             JOIN members m ON a.member_id = m.id
             WHERE a.callout_id = ? AND a.status = 'L'
             ORDER BY m.display_name",
            [$calloutId]
        );
    }

    public static function findAbsentByCallout(int $calloutId): array
    {
        return db()->query(
            "SELECT a.*, m.display_name as member_name, m.rank as member_rank
             FROM attendance a
             JOIN members m ON a.member_id = m.id
             WHERE a.callout_id = ? AND a.status = 'A'
             ORDER BY m.display_name",
            [$calloutId]
        );
    }

    public static function findByCalloutGrouped(int $calloutId): array
    {
        $attendance = self::findByCallout($calloutId);
        $grouped = [];

        foreach ($attendance as $record) {
            $truckId = $record['truck_id'];
            if (!isset($grouped[$truckId])) {
                $grouped[$truckId] = [
                    'truck_id' => $truckId,
                    'truck_name' => $record['truck_name'],
                    'is_station' => $record['is_station'],
                    'positions' => [],
                ];
            }

            $positionId = $record['position_id'];
            if (!isset($grouped[$truckId]['positions'][$positionId])) {
                $grouped[$truckId]['positions'][$positionId] = [
                    'position_id' => $positionId,
                    'position_name' => $record['position_name'],
                    'allow_multiple' => $record['allow_multiple'],
                    'members' => [],
                ];
            }

            $grouped[$truckId]['positions'][$positionId]['members'][] = [
                'id' => $record['id'],
                'member_id' => $record['member_id'],
                'member_name' => $record['member_name'],
                'member_rank' => $record['member_rank'],
            ];
        }

        return array_values($grouped);
    }

    public static function create(array $data): int
    {
        // Remove existing attendance for this member in this callout
        db()->delete('attendance', 'callout_id = ? AND member_id = ?', [$data['callout_id'], $data['member_id']]);

        // Set default status if not provided
        if (!isset($data['status'])) {
            $data['status'] = 'I';
        }
        if (!isset($data['source'])) {
            $data['source'] = 'manual';
        }

        return db()->insert('attendance', $data);
    }

    /**
     * Create attendance with status (for Leave/Absent without truck/position)
     */
    public static function createWithStatus(array $data): int
    {
        // Remove existing attendance for this member in this callout
        db()->delete('attendance', 'callout_id = ? AND member_id = ?', [$data['callout_id'], $data['member_id']]);

        // For Leave/Absent status, truck_id and position_id are optional
        $insertData = [
            'callout_id' => $data['callout_id'],
            'member_id' => $data['member_id'],
            'status' => $data['status'] ?? 'I',
            'source' => $data['source'] ?? 'manual',
            'notes' => $data['notes'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        // Only include truck/position if provided
        if (!empty($data['truck_id'])) {
            $insertData['truck_id'] = $data['truck_id'];
        }
        if (!empty($data['position_id'])) {
            $insertData['position_id'] = $data['position_id'];
        }

        return db()->insert('attendance', $insertData);
    }

    public static function delete(int $id): int
    {
        return db()->delete('attendance', 'id = ?', [$id]);
    }

    public static function deleteByMember(int $calloutId, int $memberId): int
    {
        return db()->delete('attendance', 'callout_id = ? AND member_id = ?', [$calloutId, $memberId]);
    }

    public static function deleteByCallout(int $calloutId): int
    {
        return db()->delete('attendance', 'callout_id = ?', [$calloutId]);
    }

    public static function getAssignedMemberIds(int $calloutId): array
    {
        $results = db()->query("SELECT member_id FROM attendance WHERE callout_id = ?", [$calloutId]);
        return array_column($results, 'member_id');
    }

    public static function getAvailableMembers(int $calloutId, int $brigadeId, ?string $memberOrder = null): array
    {
        $assignedIds = self::getAssignedMemberIds($calloutId);

        // Get brigade order setting if not provided
        if ($memberOrder === null) {
            $brigade = Brigade::findById($brigadeId);
            $memberOrder = Brigade::getMemberOrder($brigade);
        }

        if (empty($assignedIds)) {
            return Member::findByBrigadeOrdered($brigadeId, $memberOrder);
        }

        $placeholders = implode(',', array_fill(0, count($assignedIds), '?'));
        $members = db()->query(
            "SELECT * FROM members WHERE brigade_id = ? AND is_active = 1 AND id NOT IN ({$placeholders})",
            array_merge([$brigadeId], $assignedIds)
        );

        return Member::sortMembers($members, $memberOrder);
    }
}
