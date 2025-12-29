<?php

namespace App\Services;

use PDO;
use PDOException;

class Database
{
    private PDO $pdo;

    public function __construct(string $path)
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->pdo = new PDO('sqlite:' . $path);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->pdo->exec('PRAGMA foreign_keys = ON');
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    public function close(): void
    {
        unset($this->pdo);
    }

    public function query(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function queryOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function insert(string $table, array $data): int
    {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
        $this->execute($sql, array_values($data));

        return (int) $this->pdo->lastInsertId();
    }

    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $set = implode(', ', array_map(fn($col) => "{$col} = ?", array_keys($data)));
        $sql = "UPDATE {$table} SET {$set} WHERE {$where}";

        return $this->execute($sql, array_merge(array_values($data), $whereParams));
    }

    public function delete(string $table, string $where, array $params = []): int
    {
        $sql = "DELETE FROM {$table} WHERE {$where}";
        return $this->execute($sql, $params);
    }

    public function migrate(): void
    {
        // Add member_order column to brigades if not exists
        $columns = $this->query("PRAGMA table_info(brigades)");
        $columnNames = array_column($columns, 'name');

        if (!in_array('member_order', $columnNames)) {
            $this->pdo->exec("ALTER TABLE brigades ADD COLUMN member_order TEXT DEFAULT 'rank_name'");
        }

        // Migrate members table to new structure
        $columns = $this->query("PRAGMA table_info(members)");
        $columnNames = array_column($columns, 'name');

        // Add join_date column if not exists
        if (!in_array('join_date', $columnNames)) {
            $this->pdo->exec("ALTER TABLE members ADD COLUMN join_date DATE");
        }

        // Add new member columns if not exists
        if (!in_array('display_name', $columnNames)) {
            $this->pdo->exec("ALTER TABLE members ADD COLUMN display_name TEXT DEFAULT ''");
            $this->pdo->exec("ALTER TABLE members ADD COLUMN first_name TEXT DEFAULT ''");
            $this->pdo->exec("ALTER TABLE members ADD COLUMN last_name TEXT DEFAULT ''");
            $this->pdo->exec("ALTER TABLE members ADD COLUMN email TEXT DEFAULT ''");

            // Migrate existing data: use "rank name" as display_name, split name into first/last
            $members = $this->query("SELECT id, name, rank FROM members");
            foreach ($members as $member) {
                $name = $member['name'];
                $rank = $member['rank'];

                // Create display_name as "rank name"
                $displayName = trim($rank . ' ' . $name);

                // Split name into first and last (assume first space separates them)
                $nameParts = explode(' ', $name, 2);
                $firstName = $nameParts[0] ?? '';
                $lastName = $nameParts[1] ?? '';

                $this->execute(
                    "UPDATE members SET display_name = ?, first_name = ?, last_name = ? WHERE id = ?",
                    [$displayName, $firstName, $lastName, $member['id']]
                );
            }
        }

        // Add region column to brigades if not exists
        $brigadeColumns = $this->query("PRAGMA table_info(brigades)");
        $brigadeColumnNames = array_column($brigadeColumns, 'name');

        if (!in_array('region', $brigadeColumnNames)) {
            $this->pdo->exec("ALTER TABLE brigades ADD COLUMN region INTEGER DEFAULT 1");
        }

        // Add FENZ incident columns to callouts if not exists
        $calloutColumns = $this->query("PRAGMA table_info(callouts)");
        $calloutColumnNames = array_column($calloutColumns, 'name');

        if (!in_array('location', $calloutColumnNames)) {
            $this->pdo->exec("ALTER TABLE callouts ADD COLUMN location TEXT");
        }
        if (!in_array('duration', $calloutColumnNames)) {
            $this->pdo->exec("ALTER TABLE callouts ADD COLUMN duration TEXT");
        }
        if (!in_array('call_type', $calloutColumnNames)) {
            $this->pdo->exec("ALTER TABLE callouts ADD COLUMN call_type TEXT");
        }
        if (!in_array('fenz_fetched_at', $calloutColumnNames)) {
            $this->pdo->exec("ALTER TABLE callouts ADD COLUMN fenz_fetched_at DATETIME");
        }

        // Add require_submitter_name column to brigades if not exists (default: on)
        if (!in_array('require_submitter_name', $brigadeColumnNames)) {
            $this->pdo->exec("ALTER TABLE brigades ADD COLUMN require_submitter_name INTEGER DEFAULT 1");
        }
    }

    public function initializeSchema(): void
    {
        $schema = <<<SQL
        CREATE TABLE IF NOT EXISTS brigades (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            slug TEXT UNIQUE NOT NULL,
            pin_hash TEXT NOT NULL,
            admin_username TEXT NOT NULL,
            admin_password_hash TEXT NOT NULL,
            email_recipients TEXT DEFAULT '[]',
            include_non_attendees INTEGER DEFAULT 0,
            member_order TEXT DEFAULT 'rank_name',
            region INTEGER DEFAULT 1,
            require_submitter_name INTEGER DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS trucks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            brigade_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            is_station INTEGER DEFAULT 0,
            sort_order INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (brigade_id) REFERENCES brigades(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS positions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            truck_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            allow_multiple INTEGER DEFAULT 0,
            sort_order INTEGER DEFAULT 0,
            FOREIGN KEY (truck_id) REFERENCES trucks(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS members (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            brigade_id INTEGER NOT NULL,
            display_name TEXT NOT NULL,
            rank TEXT DEFAULT '',
            first_name TEXT DEFAULT '',
            last_name TEXT DEFAULT '',
            email TEXT DEFAULT '',
            join_date DATE,
            is_active INTEGER DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (brigade_id) REFERENCES brigades(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS callouts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            brigade_id INTEGER NOT NULL,
            icad_number TEXT NOT NULL,
            status TEXT DEFAULT 'active',
            location TEXT,
            duration TEXT,
            call_type TEXT,
            fenz_fetched_at DATETIME,
            submitted_at DATETIME,
            submitted_by TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (brigade_id) REFERENCES brigades(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS attendance (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            callout_id INTEGER NOT NULL,
            member_id INTEGER NOT NULL,
            truck_id INTEGER NOT NULL,
            position_id INTEGER NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (callout_id) REFERENCES callouts(id) ON DELETE CASCADE,
            FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
            FOREIGN KEY (truck_id) REFERENCES trucks(id) ON DELETE CASCADE,
            FOREIGN KEY (position_id) REFERENCES positions(id) ON DELETE CASCADE,
            UNIQUE(callout_id, member_id)
        );

        CREATE TABLE IF NOT EXISTS audit_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            brigade_id INTEGER NOT NULL,
            callout_id INTEGER,
            action TEXT NOT NULL,
            details TEXT DEFAULT '{}',
            ip_address TEXT,
            user_agent TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (brigade_id) REFERENCES brigades(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS rate_limits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            identifier TEXT NOT NULL,
            attempts INTEGER DEFAULT 1,
            first_attempt DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(identifier)
        );

        CREATE TABLE IF NOT EXISTS sse_notifications (
            callout_id INTEGER PRIMARY KEY,
            timestamp INTEGER NOT NULL,
            FOREIGN KEY (callout_id) REFERENCES callouts(id) ON DELETE CASCADE
        );

        CREATE INDEX IF NOT EXISTS idx_trucks_brigade ON trucks(brigade_id);
        CREATE INDEX IF NOT EXISTS idx_positions_truck ON positions(truck_id);
        CREATE INDEX IF NOT EXISTS idx_members_brigade ON members(brigade_id);
        CREATE INDEX IF NOT EXISTS idx_members_active ON members(brigade_id, is_active);
        CREATE INDEX IF NOT EXISTS idx_callouts_brigade ON callouts(brigade_id);
        CREATE INDEX IF NOT EXISTS idx_callouts_status ON callouts(brigade_id, status);
        CREATE INDEX IF NOT EXISTS idx_callouts_icad ON callouts(brigade_id, icad_number);
        CREATE INDEX IF NOT EXISTS idx_attendance_callout ON attendance(callout_id);
        CREATE INDEX IF NOT EXISTS idx_attendance_member ON attendance(member_id);
        CREATE INDEX IF NOT EXISTS idx_audit_brigade ON audit_log(brigade_id);
        CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_log(created_at);
        CREATE INDEX IF NOT EXISTS idx_rate_limits_identifier ON rate_limits(identifier);
        SQL;

        $this->pdo->exec($schema);
    }

    public function createDemoBrigade(): void
    {
        // Check if demo brigade exists
        $existing = $this->queryOne("SELECT id FROM brigades WHERE slug = ?", ['demo-brigade']);
        if ($existing) {
            return;
        }

        // Create demo brigade with PIN: 1234, admin: admin/admin123
        $brigadeId = $this->insert('brigades', [
            'name' => 'Demo Fire Brigade',
            'slug' => 'demo-brigade',
            'pin_hash' => password_hash('1234', PASSWORD_DEFAULT),
            'admin_username' => 'admin',
            'admin_password_hash' => password_hash('admin123', PASSWORD_DEFAULT),
            'email_recipients' => json_encode(['admin@example.com']),
            'include_non_attendees' => 0,
        ]);

        // Create trucks
        $pump1Id = $this->insert('trucks', [
            'brigade_id' => $brigadeId,
            'name' => 'Pump 1',
            'is_station' => 0,
            'sort_order' => 1,
        ]);

        $tankerId = $this->insert('trucks', [
            'brigade_id' => $brigadeId,
            'name' => 'Tanker',
            'is_station' => 0,
            'sort_order' => 2,
        ]);

        $stationId = $this->insert('trucks', [
            'brigade_id' => $brigadeId,
            'name' => 'Station',
            'is_station' => 1,
            'sort_order' => 99,
        ]);

        // Create positions for Pump 1 (full crew)
        $positions = ['OIC', 'DR', '1', '2', '3', '4'];
        foreach ($positions as $i => $pos) {
            $this->insert('positions', [
                'truck_id' => $pump1Id,
                'name' => $pos,
                'allow_multiple' => 0,
                'sort_order' => $i + 1,
            ]);
        }

        // Create positions for Tanker (light crew)
        $positions = ['OIC', 'DR'];
        foreach ($positions as $i => $pos) {
            $this->insert('positions', [
                'truck_id' => $tankerId,
                'name' => $pos,
                'allow_multiple' => 0,
                'sort_order' => $i + 1,
            ]);
        }

        // Create standby position for Station
        $this->insert('positions', [
            'truck_id' => $stationId,
            'name' => 'Standby',
            'allow_multiple' => 1,
            'sort_order' => 1,
        ]);

        // Create demo members
        $members = [
            ['display_name' => 'SO John Smith', 'rank' => 'SO', 'first_name' => 'John', 'last_name' => 'Smith', 'email' => 'john.smith@example.com'],
            ['display_name' => 'SFF Sarah Jones', 'rank' => 'SFF', 'first_name' => 'Sarah', 'last_name' => 'Jones', 'email' => 'sarah.jones@example.com'],
            ['display_name' => 'QFF Mike Brown', 'rank' => 'QFF', 'first_name' => 'Mike', 'last_name' => 'Brown', 'email' => 'mike.brown@example.com'],
            ['display_name' => 'QFF Emma Wilson', 'rank' => 'QFF', 'first_name' => 'Emma', 'last_name' => 'Wilson', 'email' => 'emma.wilson@example.com'],
            ['display_name' => 'FF David Clark', 'rank' => 'FF', 'first_name' => 'David', 'last_name' => 'Clark', 'email' => 'david.clark@example.com'],
            ['display_name' => 'FF Lisa Taylor', 'rank' => 'FF', 'first_name' => 'Lisa', 'last_name' => 'Taylor', 'email' => 'lisa.taylor@example.com'],
            ['display_name' => 'SFF James Anderson', 'rank' => 'SFF', 'first_name' => 'James', 'last_name' => 'Anderson', 'email' => 'james.anderson@example.com'],
            ['display_name' => 'QFF Rachel White', 'rank' => 'QFF', 'first_name' => 'Rachel', 'last_name' => 'White', 'email' => 'rachel.white@example.com'],
            ['display_name' => 'FF Tom Harris', 'rank' => 'FF', 'first_name' => 'Tom', 'last_name' => 'Harris', 'email' => 'tom.harris@example.com'],
            ['display_name' => 'RCFF Amy Martin', 'rank' => 'RCFF', 'first_name' => 'Amy', 'last_name' => 'Martin', 'email' => 'amy.martin@example.com'],
        ];

        foreach ($members as $member) {
            $this->insert('members', [
                'brigade_id' => $brigadeId,
                'display_name' => $member['display_name'],
                'rank' => $member['rank'],
                'first_name' => $member['first_name'],
                'last_name' => $member['last_name'],
                'email' => $member['email'],
                'is_active' => 1,
            ]);
        }
    }
}
