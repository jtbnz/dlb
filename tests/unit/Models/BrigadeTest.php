<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use PHPUnit\Framework\TestCase;
use App\Models\Brigade;

/**
 * Brigade Model Unit Tests
 */
class BrigadeTest extends TestCase
{
    private ?\PDO $db = null;

    protected function setUp(): void
    {
        // Create in-memory database
        $this->db = new \PDO('sqlite::memory:');
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // Create brigades table
        $this->db->exec("
            CREATE TABLE brigades (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                slug TEXT UNIQUE NOT NULL,
                pin_hash TEXT NOT NULL,
                admin_username TEXT NOT NULL,
                admin_password_hash TEXT NOT NULL,
                email_recipients TEXT DEFAULT '[]',
                include_non_attendees INTEGER DEFAULT 0,
                require_submitter_name INTEGER DEFAULT 0,
                member_order TEXT DEFAULT 'rank_name',
                region INTEGER,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }

    protected function tearDown(): void
    {
        $this->db = null;
    }

    public function testCanCreateBrigade(): void
    {
        $brigadeData = [
            'name' => 'Test Brigade',
            'slug' => 'test-brigade',
            'pin_hash' => password_hash('1234', PASSWORD_DEFAULT),
            'admin_username' => 'admin',
            'admin_password_hash' => password_hash('password123', PASSWORD_DEFAULT),
        ];

        $stmt = $this->db->prepare("
            INSERT INTO brigades (name, slug, pin_hash, admin_username, admin_password_hash)
            VALUES (:name, :slug, :pin_hash, :admin_username, :admin_password_hash)
        ");

        $result = $stmt->execute($brigadeData);
        $this->assertTrue($result);

        $id = $this->db->lastInsertId();
        $this->assertNotEmpty($id);
    }

    public function testSlugMustBeUnique(): void
    {
        $this->expectException(\PDOException::class);

        $brigadeData = [
            'name' => 'Test Brigade 1',
            'slug' => 'duplicate-slug',
            'pin_hash' => 'hash1',
            'admin_username' => 'admin1',
            'admin_password_hash' => 'hash1',
        ];

        $stmt = $this->db->prepare("
            INSERT INTO brigades (name, slug, pin_hash, admin_username, admin_password_hash)
            VALUES (:name, :slug, :pin_hash, :admin_username, :admin_password_hash)
        ");
        $stmt->execute($brigadeData);

        // Try to insert duplicate
        $brigadeData['name'] = 'Test Brigade 2';
        $brigadeData['admin_username'] = 'admin2';
        $stmt->execute($brigadeData);
    }

    public function testCanFindBrigadeBySlug(): void
    {
        $slug = 'find-test-' . time();

        $stmt = $this->db->prepare("
            INSERT INTO brigades (name, slug, pin_hash, admin_username, admin_password_hash)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute(['Find Test Brigade', $slug, 'hash', 'admin', 'hash']);

        $stmt = $this->db->prepare("SELECT * FROM brigades WHERE slug = ?");
        $stmt->execute([$slug]);
        $brigade = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotFalse($brigade);
        $this->assertEquals($slug, $brigade['slug']);
        $this->assertEquals('Find Test Brigade', $brigade['name']);
    }

    public function testCanUpdateBrigade(): void
    {
        // Insert brigade
        $stmt = $this->db->prepare("
            INSERT INTO brigades (name, slug, pin_hash, admin_username, admin_password_hash)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute(['Original Name', 'update-test', 'hash', 'admin', 'hash']);
        $id = $this->db->lastInsertId();

        // Update
        $stmt = $this->db->prepare("UPDATE brigades SET name = ? WHERE id = ?");
        $stmt->execute(['Updated Name', $id]);

        // Verify
        $stmt = $this->db->prepare("SELECT name FROM brigades WHERE id = ?");
        $stmt->execute([$id]);
        $brigade = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertEquals('Updated Name', $brigade['name']);
    }

    public function testEmailRecipientsDefaultsToEmptyArray(): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO brigades (name, slug, pin_hash, admin_username, admin_password_hash)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute(['Email Test', 'email-test', 'hash', 'admin', 'hash']);
        $id = $this->db->lastInsertId();

        $stmt = $this->db->prepare("SELECT email_recipients FROM brigades WHERE id = ?");
        $stmt->execute([$id]);
        $brigade = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertEquals('[]', $brigade['email_recipients']);
    }

    public function testCanStoreEmailRecipientsAsJson(): void
    {
        $emails = ['test1@example.com', 'test2@example.com'];
        $emailsJson = json_encode($emails);

        $stmt = $this->db->prepare("
            INSERT INTO brigades (name, slug, pin_hash, admin_username, admin_password_hash, email_recipients)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute(['Email Test', 'email-json-test', 'hash', 'admin', 'hash', $emailsJson]);
        $id = $this->db->lastInsertId();

        $stmt = $this->db->prepare("SELECT email_recipients FROM brigades WHERE id = ?");
        $stmt->execute([$id]);
        $brigade = $stmt->fetch(\PDO::FETCH_ASSOC);

        $storedEmails = json_decode($brigade['email_recipients'], true);
        $this->assertEquals($emails, $storedEmails);
    }

    public function testMemberOrderDefaultsToRankName(): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO brigades (name, slug, pin_hash, admin_username, admin_password_hash)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute(['Order Test', 'order-test', 'hash', 'admin', 'hash']);
        $id = $this->db->lastInsertId();

        $stmt = $this->db->prepare("SELECT member_order FROM brigades WHERE id = ?");
        $stmt->execute([$id]);
        $brigade = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertEquals('rank_name', $brigade['member_order']);
    }

    public function testCanDeleteBrigade(): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO brigades (name, slug, pin_hash, admin_username, admin_password_hash)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute(['Delete Test', 'delete-test', 'hash', 'admin', 'hash']);
        $id = $this->db->lastInsertId();

        // Delete
        $stmt = $this->db->prepare("DELETE FROM brigades WHERE id = ?");
        $stmt->execute([$id]);

        // Verify
        $stmt = $this->db->prepare("SELECT * FROM brigades WHERE id = ?");
        $stmt->execute([$id]);
        $brigade = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertFalse($brigade);
    }
}
