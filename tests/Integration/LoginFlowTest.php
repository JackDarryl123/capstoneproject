<?php
declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use mysqli;

class LoginFlowTest extends TestCase
{
    private ?mysqli $mysqli = null;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        $_POST = [];
        $_COOKIE = [];

        $host = getenv('DB_HOST') ?: 'localhost';
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS');
        $pass = $pass === false ? '' : $pass;
        $db = getenv('DB_NAME') ?: 'user_management';

        $this->mysqli = new mysqli($host, $user, $pass, $db);

        if ($this->mysqli->connect_error) {
            $this->markTestSkipped('Database not available: ' . $this->mysqli->connect_error);
        }
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST = [];
        $_COOKIE = [];

        if ($this->mysqli && !$this->mysqli->connect_error) {
            $this->mysqli->query("DELETE FROM users WHERE email LIKE '%test%'");
            $this->mysqli->close();
        }

        parent::tearDown();
    }

    public function testDatabaseConnection(): void
    {
        $this->assertNotNull($this->mysqli);
        $this->assertTrue($this->mysqli->ping());
    }

    public function testDatabaseSelectUserManagement(): void
    {
        $result = $this->mysqli->query("SELECT DATABASE() as db_name");
        $row = $result->fetch_assoc();

        $this->assertEquals('user_management', $row['db_name']);
    }

    public function testUsersTableExists(): void
    {
        $result = $this->mysqli->query("SHOW TABLES LIKE 'users'");
        $this->assertEquals(1, $result->num_rows);
    }

    public function testCanInsertAndDeleteTestUser(): void
    {
        $testEmail = 'test_' . time() . '@pepo.test';
        $password = password_hash('testpass123', PASSWORD_DEFAULT);
        $username = 'TestUser';

        $stmt = $this->mysqli->prepare(
            "INSERT INTO users (username, email, password, role, status) VALUES (?, ?, ?, 'user', 'active')"
        );
        $stmt->bind_param('sss', $username, $testEmail, $password);
        $stmt->execute();
        $insertId = $stmt->insert_id;
        $stmt->close();

        $this->assertGreaterThan(0, $insertId);

        $result = $this->mysqli->query("SELECT * FROM users WHERE id = $insertId");
        $user = $result->fetch_assoc();

        $this->assertEquals($testEmail, $user['email']);
        $this->assertEquals('TestUser', $user['username']);

        $this->mysqli->query("DELETE FROM users WHERE id = $insertId");

        $result = $this->mysqli->query("SELECT * FROM users WHERE id = $insertId");
        $this->assertEquals(0, $result->num_rows);
    }

    public function testPasswordVerification(): void
    {
        $plainPassword = 'admin123';
        $hashedPassword = password_hash($plainPassword, PASSWORD_DEFAULT);

        $this->assertTrue(password_verify($plainPassword, $hashedPassword));
        $this->assertFalse(password_verify('wrongpassword', $hashedPassword));
    }

    public function testCanFetchExistingUsers(): void
    {
        $result = $this->mysqli->query("SELECT id, username, email, role, status FROM users LIMIT 10");

        $this->assertInstanceOf(\mysqli_result::class, $result);

        $users = [];
        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }

        $this->assertIsArray($users);
    }

    public function testPreparedStatementPreventsSqlInjection(): void
    {
        $maliciousEmail = "admin' OR '1'='1";

        $stmt = $this->mysqli->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->bind_param('s', $maliciousEmail);
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();

        $this->assertEquals(0, $result->num_rows);
    }
}
