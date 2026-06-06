<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/session_helper.php';
start_user_session();

echo "<h1>GSO Session Debug</h1>";
echo "<pre>";
echo "Session ID: " . session_id() . "
";
echo "Session Data: " . print_r($_SESSION, true) . "
";
echo "Role: " . ($_SESSION['role'] ?? 'NOT SET') . "
";
echo "User ID: " . ($_SESSION['user_id'] ?? 'NOT SET') . "
";
echo "</pre>";

if ($mysqli->connect_error) {
    echo "DB Connection Failed: " . $mysqli->connect_error;
} else {
    echo "DB Connection Success
";
    $res = $mysqli->query("SELECT role FROM users WHERE id = " . ($_SESSION['user_id'] ?? 0));
    if ($res) {
        $user = $res->fetch_assoc();
        echo "DB Role for current user: " . ($user['role'] ?? 'N/A') . "
";
    }
}
?>
<br><a href="../index.php">Go to Login</a> | <a href="admin_dashboard.php">Go to GSO Dashboard</a>
