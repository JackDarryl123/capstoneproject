<?php
$mysqli = new mysqli('localhost', 'root', '', 'user_management');

if ($mysqli->connect_error) {
    die("Database connection failed: " . $mysqli->connect_error);
}
?>
