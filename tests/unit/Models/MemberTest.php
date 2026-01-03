<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use PHPUnit\Framework\TestCase;

/**
 * Member Model Unit Tests
 */
class MemberTest extends TestCase
{
    private ?\PDO $db = null;
    private int $brigadeId;

    protected function setUp(): void
    {
        $this->db = new \PDO('sqlite::memory:');
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // Create tables
        $this->db->exec("
            CREATE TABLE brigades (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                slug TEXT UNIQUE NOT NULL,
                pin_hash TEXT NOT NULL,
                admin_username TEXT NOT NULL,
                admin_password_hash TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $this->db->exec("
            CREATE TABLE members (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                brigade_id INTEGER NOT NULL,
                display_name TEXT NOT NULL,
                rank TEXT,
                first_name TEXT,
                last_name TEXT,
                email TEXT,
                join_date DATE,
                is_active INTEGER DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (brigade_id) REFERENCES brigades(id)
            )
        ");

        // Create test brigade
        $this->db->exec("
            INSERT INTO brigades (name, slug, pin_hash, admin_username, admin_password_hash)
            VALUES ('Test Brigade', 'test', 'hash', 'admin', 'hash')
        ");
        $this->brigadeId = (int)$this->db->lastInsertId();
    }

    protected function tearDown(): void
    {
        $this->db = null;
    }

    public function testCanCreateMember(): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO members (brigade_id, display_name, rank)
            VALUES (?, ?, ?)
        ");
        $result = $stmt->execute([$this->brigadeId, 'John Smith', 'FF']);

        $this->assertTrue($result);
        $this->assertNotEmpty($this->db->lastInsertId());
    }

    public function testMemberRequiresBrigadeId(): void
    {
        $this->expectException(\PDOException::class);

        $stmt = $this->db->prepare("
            INSERT INTO members (display_name, rank)
            VALUES (?, ?)
        ");
        $stmt->execute(['Invalid Member', 'FF']);
    }

    public function testMemberRequiresDisplayName(): void
    {
        $this->expectException(\PDOException::class);

        $stmt = $this->db->prepare("
            INSERT INTO members (brigade_id, rank)
            VALUES (?, ?)
        ");
        $stmt->execute([$this->brigadeId, 'FF']);
    }

    public function testIsActiveDefaultsToTrue(): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO members (brigade_id, display_name, rank)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$this->brigadeId, 'Active Test', 'FF']);
        $id = $this->db->lastInsertId();

        $stmt = $this->db->prepare("SELECT is_active FROM members WHERE id = ?");
        $stmt->execute([$id]);
        $member = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertEquals(1, $member['is_active']);
    }

    public function testCanDeactivateMember(): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO members (brigade_id, display_name, rank)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$this->brigadeId, 'Deactivate Test', 'FF']);
        $id = $this->db->lastInsertId();

        // Deactivate
        $stmt = $this->db->prepare("UPDATE members SET is_active = 0 WHERE id = ?");
        $stmt->execute([$id]);

        // Verify
        $stmt = $this->db->prepare("SELECT is_active FROM members WHERE id = ?");
        $stmt->execute([$id]);
        $member = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertEquals(0, $member['is_active']);
    }

    public function testCanFindMembersByBrigade(): void
    {
        // Create multiple members
        $stmt = $this->db->prepare("
            INSERT INTO members (brigade_id, display_name, rank)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$this->brigadeId, 'Member 1', 'FF']);
        $stmt->execute([$this->brigadeId, 'Member 2', 'QFF']);
        $stmt->execute([$this->brigadeId, 'Member 3', 'SFF']);

        $stmt = $this->db->prepare("SELECT * FROM members WHERE brigade_id = ?");
        $stmt->execute([$this->brigadeId]);
        $members = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertCount(3, $members);
    }

    public function testCanFindOnlyActiveMembers(): void
    {
        // Create members
        $stmt = $this->db->prepare("
            INSERT INTO members (brigade_id, display_name, rank, is_active)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$this->brigadeId, 'Active Member', 'FF', 1]);
        $stmt->execute([$this->brigadeId, 'Inactive Member', 'FF', 0]);

        $stmt = $this->db->prepare("SELECT * FROM members WHERE brigade_id = ? AND is_active = 1");
        $stmt->execute([$this->brigadeId]);
        $members = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertCount(1, $members);
        $this->assertEquals('Active Member', $members[0]['display_name']);
    }

    public function testCanUpdateMember(): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO members (brigade_id, display_name, rank)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$this->brigadeId, 'Original Name', 'FF']);
        $id = $this->db->lastInsertId();

        // Update
        $stmt = $this->db->prepare("UPDATE members SET display_name = ?, rank = ? WHERE id = ?");
        $stmt->execute(['Updated Name', 'SFF', $id]);

        // Verify
        $stmt = $this->db->prepare("SELECT * FROM members WHERE id = ?");
        $stmt->execute([$id]);
        $member = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertEquals('Updated Name', $member['display_name']);
        $this->assertEquals('SFF', $member['rank']);
    }

    public function testCanStoreOptionalFields(): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO members (brigade_id, display_name, rank, first_name, last_name, email, join_date)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $this->brigadeId,
            'John Smith',
            'FF',
            'John',
            'Smith',
            'john@example.com',
            '2023-01-15',
        ]);
        $id = $this->db->lastInsertId();

        $stmt = $this->db->prepare("SELECT * FROM members WHERE id = ?");
        $stmt->execute([$id]);
        $member = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertEquals('John', $member['first_name']);
        $this->assertEquals('Smith', $member['last_name']);
        $this->assertEquals('john@example.com', $member['email']);
        $this->assertEquals('2023-01-15', $member['join_date']);
    }

    public function testMembersAreScopedToBrigade(): void
    {
        // Create second brigade
        $this->db->exec("
            INSERT INTO brigades (name, slug, pin_hash, admin_username, admin_password_hash)
            VALUES ('Second Brigade', 'second', 'hash', 'admin2', 'hash')
        ");
        $secondBrigadeId = (int)$this->db->lastInsertId();

        // Create members in different brigades
        $stmt = $this->db->prepare("
            INSERT INTO members (brigade_id, display_name, rank)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$this->brigadeId, 'Brigade 1 Member', 'FF']);
        $stmt->execute([$secondBrigadeId, 'Brigade 2 Member', 'FF']);

        // Verify scoping
        $stmt = $this->db->prepare("SELECT * FROM members WHERE brigade_id = ?");
        $stmt->execute([$this->brigadeId]);
        $brigade1Members = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $stmt->execute([$secondBrigadeId]);
        $brigade2Members = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertCount(1, $brigade1Members);
        $this->assertCount(1, $brigade2Members);
        $this->assertEquals('Brigade 1 Member', $brigade1Members[0]['display_name']);
        $this->assertEquals('Brigade 2 Member', $brigade2Members[0]['display_name']);
    }
}
