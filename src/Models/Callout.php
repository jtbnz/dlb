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

    public static function findByBrigade(int $brigadeId, int $limit = 50, int $offset = 0): array
    {
        return db()->query(
            "SELECT * FROM callouts WHERE brigade_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?",
            [$brigadeId, $limit, $offset]
        );
    }

    public static function search(int $brigadeId, array $filters = []): array
    {
        $sql = "SELECT * FROM callouts WHERE brigade_id = ?";
        $params = [$brigadeId];

        if (!empty($filters['icad'])) {
            $sql .= " AND icad_number LIKE ?";
            $params[] = '%' . $filters['icad'] . '%';
        }

        if (!empty($filters['status'])) {
            $sql .= " AND status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['from_date'])) {
            $sql .= " AND created_at >= ?";
            $params[] = $filters['from_date'];
        }

        if (!empty($filters['to_date'])) {
            $sql .= " AND created_at <= ?";
            $params[] = $filters['to_date'] . ' 23:59:59';
        }

        $sql .= " ORDER BY created_at DESC";

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

    public static function countForYear(int $brigadeId, int $year = null): int
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
}
