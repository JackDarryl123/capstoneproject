
<?php
$host = "localhost";
$user = "root"; // Default MySQL user
$pass = ""; // Default MySQL password (empty in XAMPP)
$db = "user_management"; // Your database name

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
