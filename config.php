<?php
$host = getenv('DB_HOST') ?: 'localhost';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS');
$pass = $pass === false ? '' : $pass;
$db = getenv('DB_NAME') ?: 'user_management';

$mysqli = new mysqli($host, $user, $pass, $db);

if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error);
}

// ✅ Ensure missing inventory columns exist
// $conn->query("ALTER TABLE inventory ADD COLUMN IF NOT EXISTS log_stats VARCHAR(50) DEFAULT 'IN STOCK'");
// $conn->query("ALTER TABLE inventory ADD COLUMN IF NOT EXISTS borrowed_date DATETIME NULL");
// $conn->query("ALTER TABLE inventory ADD COLUMN IF NOT EXISTS returned_date DATETIME NULL");
?>
