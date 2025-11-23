<?php  
require_once 'includes/session_helper.php'; // use our helper for role-based sessions
$mysqli = new mysqli('localhost', 'root', '', 'user_management');

// Log user actions
function logAction($username, $action, $result) {
    global $mysqli;
    $stmt = $mysqli->prepare("INSERT INTO logs (username, action_type, result) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $username, $action, $result);
    $stmt->execute();
}

// Check DB connection
if ($mysqli->connect_error) {
    die("Database connection failed: " . $mysqli->connect_error);
}

// 🔹 Registration Process
if (isset($_POST['register'])) {
    $username = htmlspecialchars(trim($_POST['username']));
    $email = filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'];
    $role = $_POST['role'] ?? 'user';
    $status = 'active'; // Default status

    if (strlen($password) !== 8) {
        $_SESSION['message'] = "Password must be exactly 8 characters long.";
        $_SESSION['msg_type'] = "danger";
        header("Location: index.php");
        exit();
    }

    $checkEmail = $mysqli->prepare("SELECT id FROM users WHERE email = ?");
    $checkEmail->bind_param("s", $email);
    $checkEmail->execute();
    $result = $checkEmail->get_result();

    if ($result->num_rows > 0) {
        $_SESSION['message'] = "Email already exists! Try logging in.";
        $_SESSION['msg_type'] = "warning";
        header("Location: index.php");
        exit();
    }

    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $mysqli->prepare("INSERT INTO users (username, email, password, role, status) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("sssss", $username, $email, $hashedPassword, $role, $status);

    if ($stmt->execute()) {
        $_SESSION['message'] = "Registration successful! Please log in.";
        $_SESSION['msg_type'] = "success";
        header("Location: index.php?login");
        exit();
    } else {
        $_SESSION['message'] = "Error during registration. Try again.";
        $_SESSION['msg_type'] = "danger";
        header("Location: index.php");
        exit();
    }
}

// 🔹 Login Process
if (isset($_POST['login'])) {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    $stmt = $mysqli->prepare("SELECT id, username, password, role, status, failed_attempts, lockout_time FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    if (!$user) {
        logAction($email, 'login', 'failed');
        $_SESSION['message'] = "No account found with this email.";
        $_SESSION['msg_type'] = "danger";
        header("Location: index.php?login");
        exit();
    }

    $username = $user['username'];

    // 🔒 Inactive users
    if ($user['status'] !== 'active') {
        $_SESSION['message'] = "Your account is inactive. Please contact the administrator.";
        $_SESSION['msg_type'] = "danger";
        logAction($username, 'login', 'inactive');
        header("Location: index.php?login");
        exit();
    }

    // 🔒 Locked out
    if ($user['lockout_time'] && strtotime($user['lockout_time']) > time()) {
        $_SESSION['message'] = "Account locked. Try again later.";
        $_SESSION['msg_type'] = "danger";
        logAction($username, 'login', 'locked');
        header("Location: index.php?login");
        exit();
    }

    // 🔑 Password check
    if (!password_verify($password, $user['password'])) {
        $userId = $user['id'];
        $user['failed_attempts'] += 1;

        if ($user['failed_attempts'] >= 5) {
            $lockoutTime = date("Y-m-d H:i:s", time() + 600);
            $stmt = $mysqli->prepare("UPDATE users SET failed_attempts = 5, lockout_time = ? WHERE id = ?");
            $stmt->bind_param("si", $lockoutTime, $userId);
            $stmt->execute();
            $_SESSION['message'] = "Too many failed attempts. Account locked for 10 minutes.";
            $_SESSION['msg_type'] = "danger";
            logAction($username, 'login', 'locked');
        } else {
            $stmt = $mysqli->prepare("UPDATE users SET failed_attempts = ? WHERE id = ?");
            $stmt->bind_param("ii", $user['failed_attempts'], $userId);
            $stmt->execute();
            $_SESSION['message'] = "Incorrect password.";
            $_SESSION['msg_type'] = "danger";
            logAction($username, 'login', 'failed');
        }

        header("Location: index.php?login");
        exit();
    }

    // 🔓 Successful login
    $stmt = $mysqli->prepare("UPDATE users SET failed_attempts = 0, lockout_time = NULL WHERE id = ?");
    $stmt->bind_param("i", $user['id']);
    $stmt->execute();

    // ✅ Start role-specific session
    start_user_session($user['role']);

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['LAST_ACTIVITY'] = time();

    // ✅ Start one session (already started in session_helper.php)
// $_SESSION['user_id']   = $user['id'];
// $_SESSION['username']  = $user['username'];
// $_SESSION['role']      = $user['role'];
// $_SESSION['LAST_ACTIVITY'] = time();


    logAction($user['username'], 'login', 'success');

    // Redirect by role
    switch ($user['role']) {
        case 'admin':
            header("Location: admin_dashboard.php");
            break;
        case 'staff':
            header("Location: staff_dashboard.php");
            break;
        case 'supply':
            header("Location: supply/supply_dashboard.php");
            break;
        default:
            header("Location: users/user_dashboard.php");
            break;
    }
    exit();
}

// (role/status/user update handlers remain unchanged here...)

$mysqli->close();
