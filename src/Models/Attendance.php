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
            "SELECT a.*, m.name as member_name, m.rank as member_rank,
                    t.name as truck_name, t.is_station, p.name as position_name, p.allow_multiple
             FROM attendance a
             JOIN members m ON a.member_id = m.id
             JOIN trucks t ON a.truck_id = t.id
             JOIN positions p ON a.position_id = p.id
             WHERE a.callout_id = ?
             ORDER BY t.sort_order, p.sort_order, m.name",
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

        return db()->insert('attendance', $data);
    }

    public static function delete(int $id): int
    {
        return db()->delete('attendance', 'id = ?', [$id]);
    }

    public static function deleteByMember(int $calloutId, int $memberId): int
    {
        return db()->delete('attendance', 'callout_id = ? AND member_id = ?', [$calloutId, $memberId]);
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
