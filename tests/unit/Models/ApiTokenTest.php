<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use PHPUnit\Framework\TestCase;

/**
 * API Token Model Unit Tests
 */
class ApiTokenTest extends TestCase
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
            CREATE TABLE api_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                brigade_id INTEGER NOT NULL,
                token_hash TEXT NOT NULL,
                name TEXT NOT NULL,
                permissions TEXT NOT NULL DEFAULT '[]',
                last_used_at DATETIME,
                expires_at DATETIME,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (brigade_id) REFERENCES brigades(id)
            )
        ");

        $this->db->exec("
            CREATE TABLE api_rate_limits (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                token_id INTEGER NOT NULL,
                minute_count INTEGER DEFAULT 0,
                minute_reset DATETIME,
                hour_count INTEGER DEFAULT 0,
                hour_reset DATETIME,
                FOREIGN KEY (token_id) REFERENCES api_tokens(id)
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

    public function testCanCreateApiToken(): void
    {
        $tokenHash = password_hash('test_token_123', PASSWORD_DEFAULT);
        $permissions = json_encode(['musters:read', 'musters:create']);

        $stmt = $this->db->prepare("
            INSERT INTO api_tokens (brigade_id, token_hash, name, permissions)
            VALUES (?, ?, ?, ?)
        ");
        $result = $stmt->execute([$this->brigadeId, $tokenHash, 'Test Token', $permissions]);

        $this->assertTrue($result);
        $this->assertNotEmpty($this->db->lastInsertId());
    }

    public function testTokenHashIsStoredNotPlaintext(): void
    {
        $plainToken = 'dlb_test_' . bin2hex(random_bytes(16));
        $tokenHash = password_hash($plainToken, PASSWORD_DEFAULT);

        $stmt = $this->db->prepare("
            INSERT INTO api_tokens (brigade_id, token_hash, name, permissions)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$this->brigadeId, $tokenHash, 'Hash Test', '[]']);
        $id = $this->db->lastInsertId();

        $stmt = $this->db->prepare("SELECT token_hash FROM api_tokens WHERE id = ?");
        $stmt->execute([$id]);
        $token = $stmt->fetch(\PDO::FETCH_ASSOC);

        // Verify hash is stored, not plain token
        $this->assertNotEquals($plainToken, $token['token_hash']);
        $this->assertTrue(password_verify($plainToken, $token['token_hash']));
    }

    public function testCanStorePermissionsAsJson(): void
    {
        $permissions = ['musters:create', 'musters:read', 'attendance:create'];
        $permissionsJson = json_encode($permissions);

        $stmt = $this->db->prepare("
            INSERT INTO api_tokens (brigade_id, token_hash, name, permissions)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$this->brigadeId, 'hash', 'Permission Test', $permissionsJson]);
        $id = $this->db->lastInsertId();

        $stmt = $this->db->prepare("SELECT permissions FROM api_tokens WHERE id = ?");
        $stmt->execute([$id]);
        $token = $stmt->fetch(\PDO::FETCH_ASSOC);

        $storedPermissions = json_decode($token['permissions'], true);
        $this->assertEquals($permissions, $storedPermissions);
    }

    public function testCanSetExpirationDate(): void
    {
        $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));

        $stmt = $this->db->prepare("
            INSERT INTO api_tokens (brigade_id, token_hash, name, permissions, expires_at)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$this->brigadeId, 'hash', 'Expiry Test', '[]', $expiresAt]);
        $id = $this->db->lastInsertId();

        $stmt = $this->db->prepare("SELECT expires_at FROM api_tokens WHERE id = ?");
        $stmt->execute([$id]);
        $token = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertEquals($expiresAt, $token['expires_at']);
    }

    public function testCanUpdateLastUsedAt(): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO api_tokens (brigade_id, token_hash, name, permissions)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$this->brigadeId, 'hash', 'Usage Test', '[]']);
        $id = $this->db->lastInsertId();

        // Update last_used_at
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare("UPDATE api_tokens SET last_used_at = ? WHERE id = ?");
        $stmt->execute([$now, $id]);

        $stmt = $this->db->prepare("SELECT last_used_at FROM api_tokens WHERE id = ?");
        $stmt->execute([$id]);
        $token = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertEquals($now, $token['last_used_at']);
    }

    public function testCanDeleteToken(): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO api_tokens (brigade_id, token_hash, name, permissions)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$this->brigadeId, 'hash', 'Delete Test', '[]']);
        $id = $this->db->lastInsertId();

        // Delete
        $stmt = $this->db->prepare("DELETE FROM api_tokens WHERE id = ?");
        $stmt->execute([$id]);

        // Verify
        $stmt = $this->db->prepare("SELECT * FROM api_tokens WHERE id = ?");
        $stmt->execute([$id]);
        $token = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertFalse($token);
    }

    public function testTokensScopedToBrigade(): void
    {
        // Create second brigade
        $this->db->exec("
            INSERT INTO brigades (name, slug, pin_hash, admin_username, admin_password_hash)
            VALUES ('Second Brigade', 'second', 'hash', 'admin2', 'hash')
        ");
        $secondBrigadeId = (int)$this->db->lastInsertId();

        // Create tokens in different brigades
        $stmt = $this->db->prepare("
            INSERT INTO api_tokens (brigade_id, token_hash, name, permissions)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$this->brigadeId, 'hash1', 'Token 1', '[]']);
        $stmt->execute([$secondBrigadeId, 'hash2', 'Token 2', '[]']);

        // Verify scoping
        $stmt = $this->db->prepare("SELECT * FROM api_tokens WHERE brigade_id = ?");
        $stmt->execute([$this->brigadeId]);
        $brigade1Tokens = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $stmt->execute([$secondBrigadeId]);
        $brigade2Tokens = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertCount(1, $brigade1Tokens);
        $this->assertCount(1, $brigade2Tokens);
    }

    public function testCanTrackRateLimits(): void
    {
        // Create token
        $stmt = $this->db->prepare("
            INSERT INTO api_tokens (brigade_id, token_hash, name, permissions)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$this->brigadeId, 'hash', 'Rate Limit Test', '[]']);
        $tokenId = $this->db->lastInsertId();

        // Create rate limit record
        $minuteReset = date('Y-m-d H:i:s', strtotime('+1 minute'));
        $hourReset = date('Y-m-d H:i:s', strtotime('+1 hour'));

        $stmt = $this->db->prepare("
            INSERT INTO api_rate_limits (token_id, minute_count, minute_reset, hour_count, hour_reset)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$tokenId, 10, $minuteReset, 100, $hourReset]);

        // Verify
        $stmt = $this->db->prepare("SELECT * FROM api_rate_limits WHERE token_id = ?");
        $stmt->execute([$tokenId]);
        $rateLimit = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertEquals(10, $rateLimit['minute_count']);
        $this->assertEquals(100, $rateLimit['hour_count']);
    }

    public function testCanFindNonExpiredTokens(): void
    {
        $futureDate = date('Y-m-d H:i:s', strtotime('+30 days'));
        $pastDate = date('Y-m-d H:i:s', strtotime('-1 day'));

        $stmt = $this->db->prepare("
            INSERT INTO api_tokens (brigade_id, token_hash, name, permissions, expires_at)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$this->brigadeId, 'hash1', 'Valid Token', '[]', $futureDate]);
        $stmt->execute([$this->brigadeId, 'hash2', 'Expired Token', '[]', $pastDate]);
        $stmt->execute([$this->brigadeId, 'hash3', 'No Expiry Token', '[]', null]);

        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare("
            SELECT * FROM api_tokens
            WHERE brigade_id = ? AND (expires_at IS NULL OR expires_at > ?)
        ");
        $stmt->execute([$this->brigadeId, $now]);
        $validTokens = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertCount(2, $validTokens);
    }
}
