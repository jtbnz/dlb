<?php

namespace App\Models;

class Truck
{
    public static function findById(int $id): ?array
    {
        return db()->queryOne("SELECT * FROM trucks WHERE id = ?", [$id]);
    }

    public static function findByBrigade(int $brigadeId): array
    {
        return db()->query(
            "SELECT * FROM trucks WHERE brigade_id = ? ORDER BY sort_order, id",
            [$brigadeId]
        );
    }

    public static function findByBrigadeWithPositions(int $brigadeId): array
    {
        $trucks = self::findByBrigade($brigadeId);

        foreach ($trucks as &$truck) {
            $truck['positions'] = Position::findByTruck($truck['id']);
        }

        return $trucks;
    }

    public static function create(array $data): int
    {
        return db()->insert('trucks', $data);
    }

    public static function update(int $id, array $data): int
    {
        return db()->update('trucks', $data, 'id = ?', [$id]);
    }

    public static function delete(int $id): int
    {
        return db()->delete('trucks', 'id = ?', [$id]);
    }

    public static function reorder(int $brigadeId, array $order): void
    {
        foreach ($order as $index => $truckId) {
            db()->update('trucks', ['sort_order' => $index + 1], 'id = ? AND brigade_id = ?', [$truckId, $brigadeId]);
        }
    }
}
