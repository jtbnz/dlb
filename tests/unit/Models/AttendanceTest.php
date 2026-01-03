<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use PHPUnit\Framework\TestCase;

/**
 * Attendance Model Unit Tests
 */
class AttendanceTest extends TestCase
{
    private ?\PDO $db = null;
    private int $brigadeId;
    private int $memberId;
    private int $calloutId;
    private int $truckId;
    private int $positionId;

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
                is_active INTEGER DEFAULT 1,
                FOREIGN KEY (brigade_id) REFERENCES brigades(id)
            )
        ");

        $this->db->exec("
            CREATE TABLE callouts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                brigade_id INTEGER NOT NULL,
                icad_number TEXT NOT NULL,
                status TEXT DEFAULT 'active',
                FOREIGN KEY (brigade_id) REFERENCES brigades(id)
            )
        ");

        $this->db->exec("
            CREATE TABLE trucks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                brigade_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                is_station INTEGER DEFAULT 0,
                sort_order INTEGER DEFAULT 0,
                FOREIGN KEY (brigade_id) REFERENCES brigades(id)
            )
        ");

        $this->db->exec("
            CREATE TABLE positions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                truck_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                allow_multiple INTEGER DEFAULT 0,
                sort_order INTEGER DEFAULT 0,
                FOREIGN KEY (truck_id) REFERENCES trucks(id)
            )
        ");

        $this->db->exec("
            CREATE TABLE attendance (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                callout_id INTEGER NOT NULL,
                member_id INTEGER NOT NULL,
                truck_id INTEGER,
                position_id INTEGER,
                status TEXT DEFAULT 'I',
                source TEXT DEFAULT 'manual',
                notes TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (callout_id) REFERENCES callouts(id),
                FOREIGN KEY (member_id) REFERENCES members(id),
                FOREIGN KEY (truck_id) REFERENCES trucks(id),
                FOREIGN KEY (position_id) REFERENCES positions(id),
                UNIQUE(callout_id, member_id)
            )
        ");

        // Create test data
        $this->db->exec("
            INSERT INTO brigades (name, slug, pin_hash, admin_username, admin_password_hash)
            VALUES ('Test Brigade', 'test', 'hash', 'admin', 'hash')
        ");
        $this->brigadeId = (int)$this->db->lastInsertId();

        $this->db->exec("
            INSERT INTO members (brigade_id, display_name, rank)
            VALUES ({$this->brigadeId}, 'Test Member', 'FF')
        ");
        $this->memberId = (int)$this->db->lastInsertId();

        $this->db->exec("
            INSERT INTO callouts (brigade_id, icad_number)
            VALUES ({$this->brigadeId}, 'F1234567')
        ");
        $this->calloutId = (int)$this->db->lastInsertId();

        $this->db->exec("
            INSERT INTO trucks (brigade_id, name)
            VALUES ({$this->brigadeId}, 'Pump 1')
        ");
        $this->truckId = (int)$this->db->lastInsertId();

        $this->db->exec("
            INSERT INTO positions (truck_id, name)
            VALUES ({$this->truckId}, 'OIC')
        ");
        $this->positionId = (int)$this->db->lastInsertId();
    }

    protected function tearDown(): void
    {
        $this->db = null;
    }

    public function testCanCreateAttendance(): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO attendance (callout_id, member_id, truck_id, position_id)
            VALUES (?, ?, ?, ?)
        ");
        $result = $stmt->execute([$this->calloutId, $this->memberId, $this->truckId, $this->positionId]);

        $this->assertTrue($result);
        $this->assertNotEmpty($this->db->lastInsertId());
    }

    public function testStatusDefaultsToInAttendance(): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO attendance (callout_id, member_id, truck_id, position_id)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$this->calloutId, $this->memberId, $this->truckId, $this->positionId]);
        $id = $this->db->lastInsertId();

        $stmt = $this->db->prepare("SELECT status FROM attendance WHERE id = ?");
        $stmt->execute([$id]);
        $attendance = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertEquals('I', $attendance['status']);
    }

    public function testSourceDefaultsToManual(): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO attendance (callout_id, member_id, truck_id, position_id)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$this->calloutId, $this->memberId, $this->truckId, $this->positionId]);
        $id = $this->db->lastInsertId();

        $stmt = $this->db->prepare("SELECT source FROM attendance WHERE id = ?");
        $stmt->execute([$id]);
        $attendance = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertEquals('manual', $attendance['source']);
    }

    public function testCanSetLeaveStatus(): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO attendance (callout_id, member_id, status, notes)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$this->calloutId, $this->memberId, 'L', 'On leave']);
        $id = $this->db->lastInsertId();

        $stmt = $this->db->prepare("SELECT status, notes FROM attendance WHERE id = ?");
        $stmt->execute([$id]);
        $attendance = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertEquals('L', $attendance['status']);
        $this->assertEquals('On leave', $attendance['notes']);
    }

    public function testCanSetApiSource(): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO attendance (callout_id, member_id, status, source, notes)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$this->calloutId, $this->memberId, 'L', 'api', 'Set via API']);
        $id = $this->db->lastInsertId();

        $stmt = $this->db->prepare("SELECT source FROM attendance WHERE id = ?");
        $stmt->execute([$id]);
        $attendance = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertEquals('api', $attendance['source']);
    }

    public function testUniqueConstraintOnCalloutMember(): void
    {
        $this->expectException(\PDOException::class);

        $stmt = $this->db->prepare("
            INSERT INTO attendance (callout_id, member_id, truck_id, position_id)
            VALUES (?, ?, ?, ?)
        ");

        // First insert
        $stmt->execute([$this->calloutId, $this->memberId, $this->truckId, $this->positionId]);

        // Duplicate should fail
        $stmt->execute([$this->calloutId, $this->memberId, $this->truckId, $this->positionId]);
    }

    public function testCanUpdateAttendance(): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO attendance (callout_id, member_id, truck_id, position_id)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$this->calloutId, $this->memberId, $this->truckId, $this->positionId]);
        $id = $this->db->lastInsertId();

        // Create second position
        $this->db->exec("INSERT INTO positions (truck_id, name) VALUES ({$this->truckId}, 'Driver')");
        $newPositionId = (int)$this->db->lastInsertId();

        // Update position
        $stmt = $this->db->prepare("UPDATE attendance SET position_id = ? WHERE id = ?");
        $stmt->execute([$newPositionId, $id]);

        // Verify
        $stmt = $this->db->prepare("SELECT position_id FROM attendance WHERE id = ?");
        $stmt->execute([$id]);
        $attendance = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertEquals($newPositionId, $attendance['position_id']);
    }

    public function testCanDeleteAttendance(): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO attendance (callout_id, member_id, truck_id, position_id)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$this->calloutId, $this->memberId, $this->truckId, $this->positionId]);
        $id = $this->db->lastInsertId();

        // Delete
        $stmt = $this->db->prepare("DELETE FROM attendance WHERE id = ?");
        $stmt->execute([$id]);

        // Verify
        $stmt = $this->db->prepare("SELECT * FROM attendance WHERE id = ?");
        $stmt->execute([$id]);
        $attendance = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertFalse($attendance);
    }

    public function testCanFindAttendanceByCallout(): void
    {
        // Create multiple members
        $this->db->exec("INSERT INTO members (brigade_id, display_name, rank) VALUES ({$this->brigadeId}, 'Member 2', 'QFF')");
        $member2Id = (int)$this->db->lastInsertId();
        $this->db->exec("INSERT INTO members (brigade_id, display_name, rank) VALUES ({$this->brigadeId}, 'Member 3', 'SFF')");
        $member3Id = (int)$this->db->lastInsertId();

        // Create positions
        $this->db->exec("INSERT INTO positions (truck_id, name) VALUES ({$this->truckId}, 'Driver')");
        $position2Id = (int)$this->db->lastInsertId();
        $this->db->exec("INSERT INTO positions (truck_id, name) VALUES ({$this->truckId}, '1')");
        $position3Id = (int)$this->db->lastInsertId();

        // Add attendance
        $stmt = $this->db->prepare("
            INSERT INTO attendance (callout_id, member_id, truck_id, position_id)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$this->calloutId, $this->memberId, $this->truckId, $this->positionId]);
        $stmt->execute([$this->calloutId, $member2Id, $this->truckId, $position2Id]);
        $stmt->execute([$this->calloutId, $member3Id, $this->truckId, $position3Id]);

        // Find attendance
        $stmt = $this->db->prepare("SELECT * FROM attendance WHERE callout_id = ?");
        $stmt->execute([$this->calloutId]);
        $attendance = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertCount(3, $attendance);
    }

    public function testAttendanceWithoutTruckPositionForLeave(): void
    {
        // Leave status can be set without truck/position
        $stmt = $this->db->prepare("
            INSERT INTO attendance (callout_id, member_id, status, notes)
            VALUES (?, ?, ?, ?)
        ");
        $result = $stmt->execute([$this->calloutId, $this->memberId, 'L', 'On leave - no truck']);

        $this->assertTrue($result);

        $stmt = $this->db->prepare("SELECT * FROM attendance WHERE callout_id = ? AND member_id = ?");
        $stmt->execute([$this->calloutId, $this->memberId]);
        $attendance = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertEquals('L', $attendance['status']);
        $this->assertNull($attendance['truck_id']);
        $this->assertNull($attendance['position_id']);
    }
}
