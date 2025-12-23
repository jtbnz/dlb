<?php

namespace App\Models;

class Position
{
    public static function findById(int $id): ?array
    {
        return db()->queryOne("SELECT * FROM positions WHERE id = ?", [$id]);
    }

    public static function findByTruck(int $truckId): array
    {
        return db()->query(
            "SELECT * FROM positions WHERE truck_id = ? ORDER BY sort_order, id",
            [$truckId]
        );
    }

    public static function create(array $data): int
    {
        return db()->insert('positions', $data);
    }

    public static function update(int $id, array $data): int
    {
        return db()->update('positions', $data, 'id = ?', [$id]);
    }

    public static function delete(int $id): int
    {
        return db()->delete('positions', 'id = ?', [$id]);
    }

    public static function createFromTemplate(int $truckId, string $template): void
    {
        $templates = [
            'light' => ['OIC', 'DR'],
            'medium' => ['OIC', 'DR', '1', '2'],
            'full' => ['OIC', 'DR', '1', '2', '3', '4'],
            'station' => ['Standby'],
        ];

        $positions = $templates[$template] ?? $templates['full'];
        $isStation = $template === 'station';

        foreach ($positions as $index => $name) {
            self::create([
                'truck_id' => $truckId,
                'name' => $name,
                'allow_multiple' => $isStation ? 1 : 0,
                'sort_order' => $index + 1,
            ]);
        }
    }
}
