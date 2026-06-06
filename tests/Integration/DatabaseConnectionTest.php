<?php
declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use mysqli;

class DatabaseConnectionTest extends TestCase
{
    private ?mysqli $mysqli = null;

    protected function setUp(): void
    {
        parent::setUp();

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
        if ($this->mysqli && !$this->mysqli->connect_error) {
            $this->mysqli->close();
        }

        parent::tearDown();
    }

    public function testMysqliConnectionEstablishes(): void
    {
        $this->assertNotNull($this->mysqli);
        $this->assertNull($this->mysqli->connect_error);
    }

    public function testPingMethodReturnsTrue(): void
    {
        $this->assertTrue($this->mysqli->ping());
    }

    public function testGetHostInfoReturnsConfiguredHost(): void
    {
        $host = getenv('DB_HOST') ?: 'localhost';
        $hostInfo = $this->mysqli->host_info;
        $this->assertStringContainsString($host, $hostInfo);
    }

    public function testGetServerInfo(): void
    {
        $serverInfo = $this->mysqli->server_info;
        $this->assertNotEmpty($serverInfo);
        $this->assertIsString($serverInfo);
    }

    public function testGetCharacterSet(): void
    {
        $charset = $this->mysqli->character_set_name();
        $this->assertNotEmpty($charset);
    }

    public function testCanQueryDatabase(): void
    {
        $result = $this->mysqli->query("SELECT 1 as test");
        $this->assertInstanceOf(\mysqli_result::class, $result);

        $row = $result->fetch_assoc();
        $this->assertEquals(1, $row['test']);

        $result->free();
    }

    public function testCanGetLastInsertId(): void
    {
        $testEmail = 'test_insert_' . time() . '@pepo.test';
        $password = password_hash('test123', PASSWORD_DEFAULT);
        $username = 'test_insert';

        $stmt = $this->mysqli->prepare(
            "INSERT INTO users (username, email, password, role, status) VALUES (?, ?, ?, 'user', 'active')"
        );
        $stmt->bind_param('sss', $username, $testEmail, $password);
        $stmt->execute();
        $insertId = $stmt->insert_id;
        $stmt->close();

        $this->assertGreaterThan(0, $insertId);

        $this->mysqli->query("DELETE FROM users WHERE id = $insertId");
    }

    public function testAffectedRowsAfterInsert(): void
    {
        $testEmail = 'test_affect_' . time() . '@pepo.test';
        $password = password_hash('test123', PASSWORD_DEFAULT);
        $username = 'test_affect';

        $stmt = $this->mysqli->prepare(
            "INSERT INTO users (username, email, password, role, status) VALUES (?, ?, ?, 'user', 'active')"
        );
        $stmt->bind_param('sss', $username, $testEmail, $password);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        $this->assertEquals(1, $affected);

        $this->mysqli->query("DELETE FROM users WHERE email = '$testEmail'");
    }

    public function testTransactionRollback(): void
    {
        $this->mysqli->autocommit(false);

        $testEmail = 'test_tx_' . time() . '@pepo.test';
        $password = password_hash('test123', PASSWORD_DEFAULT);

        $this->mysqli->query("INSERT INTO users (username, email, password, role, status) VALUES ('test', '$testEmail', '$password', 'user', 'active')");
        $this->mysqli->rollback();

        $result = $this->mysqli->query("SELECT * FROM users WHERE email = '$testEmail'");
        $this->assertEquals(0, $result->num_rows);

        $this->mysqli->autocommit(true);
    }

    public function testMultipleStatementsInTransaction(): void
    {
        $this->mysqli->autocommit(false);

        $testEmail1 = 'test_tx1_' . time() . '@pepo.test';
        $testEmail2 = 'test_tx2_' . time() . '@pepo.test';
        $password = password_hash('test123', PASSWORD_DEFAULT);

        $this->mysqli->query("INSERT INTO users (username, email, password, role, status) VALUES ('test1', '$testEmail1', '$password', 'user', 'active')");
        $this->mysqli->query("INSERT INTO users (username, email, password, role, status) VALUES ('test2', '$testEmail2', '$password', 'user', 'active')");
        $this->mysqli->commit();

        $result = $this->mysqli->query("SELECT * FROM users WHERE email IN ('$testEmail1', '$testEmail2')");
        $this->assertEquals(2, $result->num_rows);

        $this->mysqli->query("DELETE FROM users WHERE email IN ('$testEmail1', '$testEmail2')");
        $this->mysqli->autocommit(true);
    }
}
