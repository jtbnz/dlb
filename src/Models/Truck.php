<?php

namespace App\Models;

use App\Services\Cache;

class Truck
{
    public static function findById(int $id): ?array
    {
        return db()->queryOne("SELECT * FROM trucks WHERE id = ?", [$id]);
    }

    public static function findByBrigade(int $brigadeId): array
    {
        $cacheKey = "trucks_brigade_{$brigadeId}";
        
        return Cache::remember($cacheKey, function() use ($brigadeId) {
            $trucks = db()->query(
                "SELECT * FROM trucks WHERE brigade_id = ? ORDER BY sort_order, id",
                [$brigadeId]
            );

            // Ensure is_station is properly cast to integer for JSON
            foreach ($trucks as &$truck) {
                $truck['is_station'] = (int)($truck['is_station'] ?? 0);
            }

            return $trucks;
        });
    }

    public static function findByBrigadeWithPositions(int $brigadeId): array
    {
        $cacheKey = "trucks_with_positions_brigade_{$brigadeId}";
        
        return Cache::remember($cacheKey, function() use ($brigadeId) {
            $trucks = self::findByBrigade($brigadeId);

            foreach ($trucks as &$truck) {
                $truck['positions'] = Position::findByTruck($truck['id']);
            }

            return $trucks;
        });
    }

    public static function create(array $data): int
    {
        $result = db()->insert('trucks', $data);
        // Invalidate cache for this brigade
        if (isset($data['brigade_id'])) {
            Cache::forget("trucks_brigade_{$data['brigade_id']}");
            Cache::forget("trucks_with_positions_brigade_{$data['brigade_id']}");
        }
        return $result;
    }

    public static function update(int $id, array $data): int
    {
        $truck = self::findById($id);
        $result = db()->update('trucks', $data, 'id = ?', [$id]);
        // Invalidate cache for this brigade
        if ($truck) {
            Cache::forget("trucks_brigade_{$truck['brigade_id']}");
            Cache::forget("trucks_with_positions_brigade_{$truck['brigade_id']}");
        }
        return $result;
    }

    public static function delete(int $id): int
    {
        $truck = self::findById($id);
        $result = db()->delete('trucks', 'id = ?', [$id]);
        // Invalidate cache for this brigade
        if ($truck) {
            Cache::forget("trucks_brigade_{$truck['brigade_id']}");
            Cache::forget("trucks_with_positions_brigade_{$truck['brigade_id']}");
        }
        return $result;
    }

    public static function reorder(int $brigadeId, array $order): void
    {
        foreach ($order as $index => $truckId) {
            db()->update('trucks', ['sort_order' => $index + 1], 'id = ? AND brigade_id = ?', [$truckId, $brigadeId]);
        }
    }
}
