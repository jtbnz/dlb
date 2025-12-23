<?php

namespace App\Models;

class Brigade
{
    public static function findBySlug(string $slug): ?array
    {
        return db()->queryOne("SELECT * FROM brigades WHERE slug = ?", [$slug]);
    }

    public static function findById(int $id): ?array
    {
        return db()->queryOne("SELECT * FROM brigades WHERE id = ?", [$id]);
    }

    public static function all(): array
    {
        return db()->query("SELECT * FROM brigades ORDER BY name");
    }

    public static function create(array $data): int
    {
        return db()->insert('brigades', $data);
    }

    public static function update(int $id, array $data): int
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        return db()->update('brigades', $data, 'id = ?', [$id]);
    }

    public static function verifyPin(array $brigade, string $pin): bool
    {
        return password_verify($pin, $brigade['pin_hash']);
    }

    public static function verifyAdminPassword(array $brigade, string $password): bool
    {
        return password_verify($password, $brigade['admin_password_hash']);
    }

    public static function getEmailRecipients(array $brigade): array
    {
        return json_decode($brigade['email_recipients'], true) ?? [];
    }

    public static function getMemberOrder(array $brigade): string
    {
        return $brigade['member_order'] ?? 'rank_name';
    }
}
