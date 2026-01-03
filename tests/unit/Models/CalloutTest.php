<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use PHPUnit\Framework\TestCase;

/**
 * Callout Model Unit Tests
 */
class CalloutTest extends TestCase
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
            CREATE TABLE callouts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                brigade_id INTEGER NOT NULL,
                icad_number TEXT NOT NULL,
                status TEXT DEFAULT 'active',
                visible INTEGER DEFAULT 1,
                location TEXT,
                duration TEXT,
                call_type TEXT,
                call_date DATE,
                call_time TIME,
                fenz_fetched_at DATETIME,
                submitted_at DATETIME,
                submitted_by TEXT,
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

    public function testCanCreateCallout(): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO callouts (brigade_id, icad_number)
            VALUES (?, ?)
        ");
        $result = $stmt->execute([$this->brigadeId, 'F1234567']);

        $this->assertTrue($result);
        $this->assertNotEmpty($this->db->lastInsertId());
    }

    public function testStatusDefaultsToActive(): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO callouts (brigade_id, icad_number)
            VALUES (?, ?)
        ");
        $stmt->execute([$this->brigadeId, 'F1234567']);
        $id = $this->db->lastInsertId();

        $stmt = $this->db->prepare("SELECT status FROM callouts WHERE id = ?");
        $stmt->execute([$id]);
        $callout = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertEquals('active', $callout['status']);
    }

    public function testVisibleDefaultsToTrue(): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO callouts (brigade_id, icad_number)
            VALUES (?, ?)
        ");
        $stmt->execute([$this->brigadeId, 'F1234567']);
        $id = $this->db->lastInsertId();

        $stmt = $this->db->prepare("SELECT visible FROM callouts WHERE id = ?");
        $stmt->execute([$id]);
        $callout = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertEquals(1, $callout['visible']);
    }

    public function testCanCreateHiddenCallout(): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO callouts (brigade_id, icad_number, visible)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$this->brigadeId, 'muster_20250120', 0]);
        $id = $this->db->lastInsertId();

        $stmt = $this->db->prepare("SELECT visible FROM callouts WHERE id = ?");
        $stmt->execute([$id]);
        $callout = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertEquals(0, $callout['visible']);
    }

    public function testCanUpdateCalloutStatus(): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO callouts (brigade_id, icad_number)
            VALUES (?, ?)
        ");
        $stmt->execute([$this->brigadeId, 'F1234567']);
        $id = $this->db->lastInsertId();

        // Submit
        $stmt = $this->db->prepare("
            UPDATE callouts SET status = ?, submitted_at = ?, submitted_by = ?
            WHERE id = ?
        ");
        $stmt->execute(['submitted', date('Y-m-d H:i:s'), 'Test User', $id]);

        $stmt = $this->db->prepare("SELECT status, submitted_by FROM callouts WHERE id = ?");
        $stmt->execute([$id]);
        $callout = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertEquals('submitted', $callout['status']);
        $this->assertEquals('Test User', $callout['submitted_by']);
    }

    public function testCanStoreCalloutDetails(): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO callouts (brigade_id, icad_number, location, call_type, call_date, call_time)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $this->brigadeId,
            'F1234567',
            '123 Test Street',
            'Structure Fire',
            '2025-01-15',
            '14:30',
        ]);
        $id = $this->db->lastInsertId();

        $stmt = $this->db->prepare("SELECT * FROM callouts WHERE id = ?");
        $stmt->execute([$id]);
        $callout = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertEquals('123 Test Street', $callout['location']);
        $this->assertEquals('Structure Fire', $callout['call_type']);
        $this->assertEquals('2025-01-15', $callout['call_date']);
        $this->assertEquals('14:30', $callout['call_time']);
    }

    public function testCanFindActiveCallouts(): void
    {
        // Create multiple callouts with different statuses
        $stmt = $this->db->prepare("
            INSERT INTO callouts (brigade_id, icad_number, status)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$this->brigadeId, 'F1111111', 'active']);
        $stmt->execute([$this->brigadeId, 'F2222222', 'submitted']);
        $stmt->execute([$this->brigadeId, 'F3333333', 'active']);

        $stmt = $this->db->prepare("
            SELECT * FROM callouts WHERE brigade_id = ? AND status = 'active'
        ");
        $stmt->execute([$this->brigadeId]);
        $callouts = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertCount(2, $callouts);
    }

    public function testCanFindVisibleCallouts(): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO callouts (brigade_id, icad_number, visible)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$this->brigadeId, 'F1111111', 1]);
        $stmt->execute([$this->brigadeId, 'muster_hidden', 0]);
        $stmt->execute([$this->brigadeId, 'F3333333', 1]);

        $stmt = $this->db->prepare("
            SELECT * FROM callouts WHERE brigade_id = ? AND visible = 1
        ");
        $stmt->execute([$this->brigadeId]);
        $callouts = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertCount(2, $callouts);
    }

    public function testCalloutsAreScopedToBrigade(): void
    {
        // Create second brigade
        $this->db->exec("
            INSERT INTO brigades (name, slug, pin_hash, admin_username, admin_password_hash)
            VALUES ('Second Brigade', 'second', 'hash', 'admin2', 'hash')
        ");
        $secondBrigadeId = (int)$this->db->lastInsertId();

        // Create callouts in different brigades
        $stmt = $this->db->prepare("
            INSERT INTO callouts (brigade_id, icad_number)
            VALUES (?, ?)
        ");
        $stmt->execute([$this->brigadeId, 'F1111111']);
        $stmt->execute([$secondBrigadeId, 'F2222222']);

        // Verify scoping
        $stmt = $this->db->prepare("SELECT * FROM callouts WHERE brigade_id = ?");
        $stmt->execute([$this->brigadeId]);
        $brigade1Callouts = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $stmt->execute([$secondBrigadeId]);
        $brigade2Callouts = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertCount(1, $brigade1Callouts);
        $this->assertCount(1, $brigade2Callouts);
    }

    public function testCanDeleteCallout(): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO callouts (brigade_id, icad_number)
            VALUES (?, ?)
        ");
        $stmt->execute([$this->brigadeId, 'F1234567']);
        $id = $this->db->lastInsertId();

        // Delete
        $stmt = $this->db->prepare("DELETE FROM callouts WHERE id = ?");
        $stmt->execute([$id]);

        // Verify
        $stmt = $this->db->prepare("SELECT * FROM callouts WHERE id = ?");
        $stmt->execute([$id]);
        $callout = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertFalse($callout);
    }
}
