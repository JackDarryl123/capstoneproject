<?php
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/config.php';
require_once 'includes/session_helper.php';
start_user_session();

// 🔹 Helper Function: Log User Actions
function logAction($username, $action, $result)
{
    global $mysqli;
    $stmt = $mysqli->prepare("INSERT INTO logs (username, action_type, result) VALUES (?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("sss", $username, $action, $result);
        $stmt->execute();
        $stmt->close();
    }
}

// ==========================================
// 🔹 REGISTRATION PROCESS
// ==========================================
if (isset($_POST['register'])) {
    error_log("POST DATA: " . print_r($_POST, true));
    $username = htmlspecialchars(trim($_POST['username']));
    $email = filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'];

    // Mapping: Form field 'designation' -> DB column 'location'
    $location = $_POST['designation'] ?? null;
    $requested_role = $_POST['role'] ?? 'user';
    
        // Validate role - only allow specific roles
        $allowedRoles = ['user', 'staff', 'supply', 'admin', 'pgdh_gso'];
        error_log("Attempting registration with role: '" . $requested_role . "'");
        error_log("Allowed roles: " . implode(", ", $allowedRoles));
        

    // SECURITY UPDATE: Changed from strict 8 chars to MINIMUM 8 chars
    if (strlen($password) < 8) {
        $_SESSION['message'] = "Password must be at least 8 characters long.";
        $_SESSION['msg_type'] = "danger";
        header("Location: index.php?register=error"); // Keep register form open
        exit();
    }

    // Check if email exists in 'users' table
    $checkEmail = $mysqli->prepare("SELECT id FROM users WHERE email = ?");
    $checkEmail->bind_param("s", $email);
    $checkEmail->execute();
    if ($checkEmail->get_result()->num_rows > 0) {
        $_SESSION['message'] = "Email already exists! Please sign in.";
        $_SESSION['msg_type'] = "warning";
        header("Location: index.php"); // Redirect to login
        exit();
    }
    $checkEmail->close();

    // Check if email exists in 'signup_requests' table
    $checkSignup = $mysqli->prepare("SELECT id FROM signup_requests WHERE email = ? AND status = 'pending'");
    $checkSignup->bind_param("s", $email);
    $checkSignup->execute();
    if ($checkSignup->get_result()->num_rows > 0) {
        $_SESSION['message'] = "You already have a pending application. Please wait for admin approval.";
        $_SESSION['msg_type'] = "warning";
        header("Location: index.php");
        exit();
    }
    $checkSignup->close();

    // Hash Password
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    // Insert into signup_requests
    $stmt = $mysqli->prepare("INSERT INTO signup_requests (username, email, password, designation, role, requested_role) VALUES (?, ?, ?, ?, ?, ?)");

    if (!$stmt) {
        $_SESSION['message'] = "System Error: " . $mysqli->error;
        $_SESSION['msg_type'] = "danger";
        header("Location: index.php?register=error");
        exit();
    }

    // Binding: role is used twice (once for 'role' col, once for 'requested_role' col)
    $stmt->bind_param("ssssss", $username, $email, $hashedPassword, $location, $requested_role, $requested_role);

    if ($stmt->execute()) {
        $_SESSION['message'] = "Registration successful! Your account is under review.";
        $_SESSION['msg_type'] = "success";
        header("Location: index.php"); // Switch to login view for success message
    } else {
        $_SESSION['message'] = "Registration failed: " . $stmt->error;
        $_SESSION['msg_type'] = "danger";
        header("Location: index.php?register=error");
    }
    $stmt->close();
    exit();
}

// ==========================================
// 🔹 LOGIN PROCESS
// ==========================================
if (isset($_POST['login'])) {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    // Fetch user data
    $stmt = $mysqli->prepare("SELECT id, username, password, role, location, status, failed_attempts, lockout_time, is_admin FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    // 1. User Not Found -> Check Pending Requests
    if (!$user) {
        $checkSignup = $mysqli->prepare("SELECT id, status FROM signup_requests WHERE email = ? AND status = 'pending'");
        $checkSignup->bind_param("s", $email);
        $checkSignup->execute();

        if ($checkSignup->get_result()->num_rows > 0) {
            $_SESSION['message'] = "Your application is currently pending admin review.";
            $_SESSION['msg_type'] = "warning";
            logAction($email, 'login', 'pending_approval');
        } else {
            $_SESSION['message'] = "Incorrect email or password.";
            $_SESSION['msg_type'] = "danger";
            logAction($email, 'login', 'failed_not_found');
        }
        $checkSignup->close();
        header("Location: index.php");
        exit();
    }

    $username = $user['username'];

    // 2. Check Lockout Time
    if ($user['lockout_time']) {
        $lockoutTimestamp = strtotime($user['lockout_time']);
        $currentTime = time();

        if ($lockoutTimestamp > $currentTime) {
            // Still locked - Show remaining time
            $remaining = ceil(($lockoutTimestamp - $currentTime) / 60);
            $_SESSION['message'] = "Account locked. Please try again in $remaining minutes.";
            $_SESSION['msg_type'] = "danger";
            logAction($username, 'login', 'blocked_lockout');
            header("Location: index.php");
            exit();
        } else {
            // Lockout expired - Reset attempts so they can try again
            $resetStmt = $mysqli->prepare("UPDATE users SET failed_attempts = 0, lockout_time = NULL WHERE id = ?");
            $resetStmt->bind_param("i", $user['id']);
            $resetStmt->execute();
            $resetStmt->close();

            // Update local variable for the password check below
            $user['failed_attempts'] = 0;
        }
    }

    // 3. Check Inactive Status
    if ($user['status'] !== 'active') {
        $_SESSION['message'] = "Your account has been deactivated. Contact the administrator.";
        $_SESSION['msg_type'] = "danger";
        logAction($username, 'login', 'inactive_attempt');
        header("Location: index.php");
        exit();
    }

    // 4. Verify Password
    if (!password_verify($password, $user['password'])) {
        $userId = $user['id'];
        $failedAttempts = $user['failed_attempts'] + 1;

        if ($failedAttempts >= 5) {
            // Lock account for 10 minutes
            $lockoutTime = date("Y-m-d H:i:s", time() + 600);
            $stmt = $mysqli->prepare("UPDATE users SET failed_attempts = ?, lockout_time = ? WHERE id = ?");
            $stmt->bind_param("isi", $failedAttempts, $lockoutTime, $userId);
            $_SESSION['message'] = "Too many failed attempts. Account locked for 10 minutes.";
            logAction($username, 'login', 'account_locked');
        } else {
            // Increment failed attempts
            $stmt = $mysqli->prepare("UPDATE users SET failed_attempts = ? WHERE id = ?");
            $stmt->bind_param("ii", $failedAttempts, $userId);
            $_SESSION['message'] = "Incorrect email or password.";
            logAction($username, 'login', 'failed_password');
        }
        $stmt->execute();
        $stmt->close();

        $_SESSION['msg_type'] = "danger";
        header("Location: index.php");
        exit();
    }

    // 5. Login Successful
    // Reset failed attempts
    $stmt = $mysqli->prepare("UPDATE users SET failed_attempts = 0, lockout_time = NULL WHERE id = ?");
    $stmt->bind_param("i", $user['id']);
    $stmt->execute();
    $stmt->close();

    session_regenerate_id(true);

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['location'] = $user['location'];
    $_SESSION['is_admin'] = $user['is_admin'];

    error_log("Login success for user: " . $user['username'] . " with role: " . $user['role']);

    logAction($user['username'], 'login', 'success');

    // Role-based Redirect
    switch ($user['role']) {
        case 'pgdh_pacco':
            error_log("Redirecting to PACCO dashboard");
            header("Location: PACCO/admin_dashboard.php");
            break;
        case 'pgdh_gso':
            error_log("Redirecting to GSO dashboard");
            header("Location: GSO/admin_dashboard.php");
            break;
        case 'admin':
            error_log("Redirecting to Admin dashboard");
            header("Location: admin_dashboard.php");
            break;
        case 'staff':
            error_log("Redirecting to Staff dashboard");
            header("Location: staff/staff_dashboard.php");
            break;
        case 'supply':
            error_log("Redirecting to Supply dashboard");
            header("Location: supply/supply_dashboard.php");
            break;
        default:
            error_log("Redirecting to User dashboard (Default)");
            header("Location: users/user_dashboard.php");
            break;
    }
    exit();
}

// ==========================================
// 🔹 ADMIN: HANDLE SIGNUP REQUESTS
// ==========================================
if (isset($_POST['signup_request_id']) && isset($_POST['action'])) {
    if (($_SESSION['role'] ?? '') !== 'admin') {
        die('Unauthorized action.');
    }

    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf'] ?? '')) {
        die('CSRF validation failed.');
    }

    $signup_request_id = $_POST['signup_request_id'];
    $action = $_POST['action'];
    $admin_id = $_SESSION['user_id'];

    if ($action === 'approve') {
        $stmt = $mysqli->prepare("SELECT * FROM signup_requests WHERE id = ?");
        $stmt->bind_param("i", $signup_request_id);
        $stmt->execute();
        $req = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($req) {
            // Insert into users (using 'designation' from request as 'location' in users)
            $stmt = $mysqli->prepare("INSERT INTO users (username, email, password, location, role, status) VALUES (?, ?, ?, ?, ?, 'active')");
            $stmt->bind_param("sssss", $req['username'], $req['email'], $req['password'], $req['designation'], $req['requested_role']);
            $stmt->execute();
            $stmt->close();

            // Mark request as approved
            $stmt = $mysqli->prepare("UPDATE signup_requests SET status = 'approved', processed_at = NOW(), processed_by = ? WHERE id = ?");
            $stmt->bind_param("ii", $admin_id, $signup_request_id);
            $stmt->execute();
            $stmt->close();
        }
    } elseif ($action === 'decline') {
        $stmt = $mysqli->prepare("UPDATE signup_requests SET status = 'declined', processed_at = NOW(), processed_by = ? WHERE id = ?");
        $stmt->bind_param("ii", $admin_id, $signup_request_id);
        $stmt->execute();
        $stmt->close();
    }

    header("Location: admin_dashboard.php?view=user");
    exit();
}

// ==========================================
// 🔹 ADMIN: USER MANAGEMENT (Role/Status/Loc)
// ==========================================
if (isset($_POST['user_id']) && (isset($_POST['update_role']) || isset($_POST['update_status']) || isset($_POST['update_location']))) {
    if (($_SESSION['role'] ?? '') !== 'admin') {
        die('Unauthorized action.');
    }

    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf'] ?? '')) {
        die('CSRF validation failed.');
    }

    // Additional check: Only is_admin = 1 can change roles
    if (isset($_POST['update_role'])) {
        $checkAdminStmt = $mysqli->prepare("SELECT is_admin FROM users WHERE id = ?");
        $checkAdminStmt->bind_param("i", $_SESSION['user_id']);
        $checkAdminStmt->execute();
        $adminResult = $checkAdminStmt->get_result();
        $currentUser = $adminResult->fetch_assoc();
        $checkAdminStmt->close();

        if (($currentUser['is_admin'] ?? 0) != 1) {
            die('Only Provincial Admin can change user roles.');
        }
    }

    $userId = intval($_POST['user_id']);

    if (isset($_POST['update_role'])) {
        $stmt = $mysqli->prepare("UPDATE users SET role = ? WHERE id = ?");
        $stmt->bind_param("si", $_POST['update_role'], $userId);
    } elseif (isset($_POST['update_status'])) {
        $stmt = $mysqli->prepare("UPDATE users SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $_POST['update_status'], $userId);
    } elseif (isset($_POST['update_location'])) {
        $stmt = $mysqli->prepare("UPDATE users SET location = ? WHERE id = ?");
        $stmt->bind_param("si", $_POST['update_location'], $userId);
    }

    if (isset($stmt)) {
        $stmt->execute();
        $stmt->close();
        
        // Get username for logging
        $usernameStmt = $mysqli->prepare("SELECT username FROM users WHERE id = ?");
        $usernameStmt->bind_param("i", $userId);
        $usernameStmt->execute();
        $userResult = $usernameStmt->get_result();
        $targetUser = $userResult->fetch_assoc();
        $usernameStmt->close();
        
        $targetUsername = $targetUser['username'] ?? 'unknown';
        
        // Log the action
        if (isset($_POST['update_role'])) {
            logAction($_SESSION['username'], "Changed role of $targetUsername to " . $_POST['update_role'], "Success");
        } elseif (isset($_POST['update_status'])) {
            logAction($_SESSION['username'], "Changed status of $targetUsername to " . $_POST['update_status'], "Success");
        } elseif (isset($_POST['update_location'])) {
            logAction($_SESSION['username'], "Changed location of $targetUsername to " . $_POST['update_location'], "Success");
        }
    }

    header("Location: admin_dashboard.php?view=user");
    exit();
};


// ==========================================
// 🔹 PROFILE UPDATE (STANDARD REDIRECT METHOD)
// ==========================================
if (isset($_POST['update'])) {

    // Check Session
    if (!isset($_SESSION['user_id'])) {
        header("Location: index.php");
        exit();
    }

    $userId = $_SESSION['user_id'];
    $username = htmlspecialchars(trim($_POST['username']));
    // This catches the redirect URL from the form
    $redirect = $_POST['redirect_to'] ?? 'admin_dashboard.php';
    $error = null;

    // --- Password Logic ---
    if (!empty($_POST['password'])) {
        $password = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];
        $current_password = $_POST['current_password'] ?? '';

        $stmt = $mysqli->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $stmt->bind_result($currentHash);
        $stmt->fetch();
        $stmt->close();

        if (!password_verify($current_password, $currentHash)) {
            $error = "Incorrect current password.";
        } elseif (strlen($password) < 8) {
            $error = "New password must be at least 8 characters.";
        } elseif ($password !== $confirm_password) {
            $error = "New passwords do not match.";
        } else {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $mysqli->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->bind_param("si", $hashedPassword, $userId);
            $stmt->execute();
            $stmt->close();
        }
    }

    // Stop if password error
    if ($error) {
        $_SESSION['message'] = $error;
        $_SESSION['msg_type'] = "danger";
        header("Location: " . $redirect . "&modal=profile"); // &modal=profile keeps modal open on reload
        exit();
    }

    // --- Signature Upload Logic ---
    if (isset($_FILES['signature']) && $_FILES['signature']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = 'uploads/signatures/';
        if (!is_dir($uploadDir))
            mkdir($uploadDir, 0777, true);

        $fileExt = strtolower(pathinfo($_FILES['signature']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png'];

        if (in_array($fileExt, $allowed)) {
            $fileName = 'user_sig_' . $userId . '_' . time() . '.' . $fileExt;
            if (move_uploaded_file($_FILES['signature']['tmp_name'], $uploadDir . $fileName)) {
                $filePath = $uploadDir . $fileName;
                $stmt = $mysqli->prepare("UPDATE users SET signature = ? WHERE id = ?");
                $stmt->bind_param("si", $filePath, $userId);
                $stmt->execute();
                $stmt->close();
            }
        }
    }

    // --- Update Username & Success ---
    $stmt = $mysqli->prepare("UPDATE users SET username = ? WHERE id = ?");
    $stmt->bind_param("si", $username, $userId);

    if ($stmt->execute()) {
        $_SESSION['username'] = $username;
        $_SESSION['message'] = "Profile updated successfully!";
        $_SESSION['msg_type'] = "success";
    } else {
        $_SESSION['message'] = "Database error: " . $mysqli->error;
        $_SESSION['msg_type'] = "danger";
    }
    $stmt->close();

    // Redirect back to dashboard
    header("Location: " . $redirect);
    exit();
}