<?php
require_once __DIR__ . '/config.php';

$status_info = null;
$error = null;
$email_to_check = null;

// ---------------------------------------------------------
// 1. DETERMINE WHICH EMAIL TO CHECK
// ---------------------------------------------------------

// A. If user just submitted the form on this page
if (isset($_POST['check_status']) && !empty($_POST['email'])) {
    $email_to_check = filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL);
    $_SESSION['checked_email'] = $email_to_check; // Remember this email
}

// 0. HANDLE RESET BUTTON (Add this new part)
if (isset($_POST['clear_check'])) {
    unset($_SESSION['email']);         // Clear signup email
    unset($_SESSION['checked_email']); // Clear manual check email
    $status_info = null;               // Reset status to show form
}
// B. If user is coming from Signup (Session)
elseif (isset($_SESSION['email']) && !empty($_SESSION['email'])) {
    $email_to_check = filter_var(trim($_SESSION['email']), FILTER_SANITIZE_EMAIL);
}
// C. If user previously checked an email in this session
elseif (isset($_SESSION['checked_email']) && !empty($_SESSION['checked_email'])) {
    $email_to_check = $_SESSION['checked_email'];
}
// D. If passed via URL
elseif (isset($_GET['email']) && !empty($_GET['email'])) {
    $email_to_check = filter_var(trim($_GET['email']), FILTER_SANITIZE_EMAIL);
}

// ---------------------------------------------------------
// 2. EXECUTE LOOKUP (If we have an email)
// ---------------------------------------------------------

if ($email_to_check) {
    // Check USERS table first
    $stmt = $mysqli->prepare("SELECT id, username, email, role, status FROM users WHERE email = ?");
    $stmt->bind_param("s", $email_to_check);
    $stmt->execute();
    $userResult = $stmt->get_result();
    $user = $userResult->fetch_assoc();

    if ($user) {
        $status_info = $user;
        $status_info['requested_role'] = $user['role']; // Standardize key
        $status_info['source'] = 'users';
    } else {
        // If not found, check signup_requests
        $stmt = $mysqli->prepare("SELECT username, email, requested_role, status, requested_at, processed_at FROM signup_requests WHERE email = ?");
        $stmt->bind_param("s", $email_to_check);
        $stmt->execute();
        $signupResult = $stmt->get_result();
        $signup = $signupResult->fetch_assoc();

        if ($signup) {
            $status_info = $signup;
            $status_info['source'] = 'signup_requests';
        } else {
            $error = "No account found for: " . htmlspecialchars($email_to_check);
            // If checking failed, clear the session so the form reappears next time
            unset($_SESSION['checked_email']);
        }
    }
}
$mysqli->close();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Check Signup Status</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .container-wrapper {
            width: 100%;
            max-width: 500px;
            padding: 20px;
        }

        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
        }

        .status-badge {
            padding: 10px 20px;
            border-radius: 50px;
            font-weight: 600;
            display: inline-block;
        }

        /* Status Colors */
        .status-pending {
            background-color: #fff3cd;
            color: #856404;
        }

        .status-approved,
        .status-active {
            background-color: #d4edda;
            color: #155724;
        }

        .status-declined,
        .status-deactivated {
            background-color: #f8d7da;
            color: #721c24;
        }

        .info-section {
            background-color: #e3f2fd;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 10px;
        }

        .info-label {
            font-weight: 600;
            color: #667eea;
            margin-bottom: 5px;
        }

        .btn-check {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            color: white;
            font-weight: 600;
        }

        .btn-check:hover {
            background: linear-gradient(135deg, #764ba2 0%, #667eea 100%);
            color: white;
        }

        .back-link {
            text-align: center;
            margin-top: 20px;
        }

        .back-link a {
            color: white;
            text-decoration: none;
        }

        .back-link a:hover {
            text-decoration: underline;
        }

        /* Find this block and change '.btn-check' to '.btn-submit-custom' */
        .btn-submit-custom {
            /* <--- RENAMED */
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            color: white;
            font-weight: 600;
        }

        /* Also update the hover state */
        .btn-submit-custom:hover {
            /* <--- RENAMED */
            background: linear-gradient(135deg, #764ba2 0%, #667eea 100%);
            color: white;
        }
    </style>
</head>

<body>
    <div class="container-wrapper">
        <div class="card">
            <div class="card-header text-center p-4"
                style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 15px 15px 0 0;">
                <h3 class="m-0">📋 Check Your Signup Status</h3>
                <p class="small mb-0 mt-2 opacity-75">Enter your email to check your application status</p>
            </div>

            <div class="card-body p-4">

                <?php if ($status_info): ?>
                    <div class="text-center">
                        <h5 class="text-info mb-3">Your Signup Information</h5>

                        <div class="info-section">
                            <div class="row text-center">
                                <div class="col-12 mb-3">
                                    <span class="info-label d-block">Username:</span>
                                    <?= htmlspecialchars($status_info['username']); ?>
                                </div>
                                <div class="col-12 mb-3">
                                    <span class="info-label d-block">Email:</span>
                                    <?= htmlspecialchars($status_info['email']); ?>
                                </div>
                                <div class="col-12 mb-3">
                                    <span class="info-label d-block">Requested Role:</span>
                                    <?php
                                    if ($status_info['requested_role'] === 'user') {
                                        echo 'Property Custodian';
                                    } else {
                                        echo ucfirst($status_info['requested_role']);
                                    }
                                    ?>
                                </div>
                                <div class="col-12">
                                    <span class="info-label d-block">Applied On:</span>
                                    <?= isset($status_info['requested_at']) ? date('M d, Y h:i A', strtotime($status_info['requested_at'])) : 'N/A'; ?>
                                </div>
                            </div>
                        </div>

                        <div class="mt-4">
                            <div class="info-label mb-2">Application Status:</div>
                            <?php
                            // Define variables with defaults
                            $sClass = 'status-pending';
                            $sText = '⏳ PENDING - Awaiting Admin Review';
                            $sNote = 'Your account is pending approval. You will be notified once reviewed.';

                            // Get actual status from database
                            $status = $status_info['status'] ?? '';

                            switch ($status) {
                                case 'approved':
                                    $sClass = 'status-approved';
                                    $sText = '✅ APPROVED - You can now login!';
                                    $sNote = 'Your account has been approved. Please log in to activate it.';
                                    break;
                                case 'active':
                                    $sClass = 'status-active';
                                    $sText = '✅ ACTIVE - Account Enabled';
                                    $sNote = 'Your account is fully active. Enjoy our services!';
                                    break;
                                case 'declined':
                                    $sClass = 'status-declined';
                                    $sText = '❌ DECLINED';
                                    $sNote = 'Unfortunately your application was declined. Contact support for more information.';
                                    break;
                                case 'deactivated':
                                    $sClass = 'status-deactivated';
                                    $sText = '🔒 DEACTIVATED';
                                    $sNote = 'This account has been deactivated. To reactivate, please contact support.';
                                    break;
                                // 'pending' is already the default
                            }
                            ?>

                            <!-- Single status badge -->
                            <div class="status-badge <?= $sClass ?>"><?= $sText ?></div>

                            <?php if (!empty($sNote)): ?>
                                <p class="status-note mt-2 small"><?= htmlspecialchars($sNote) ?></p>
                            <?php endif; ?>
                        </div>


                        <?php if ($status_info['status'] === 'pending'): ?>
                            <div class="alert alert-info mt-3 small">
                                Your application is under review. Please check back later.
                            </div>
                        <?php endif; ?>

                        <form method="POST" class="mt-4">
                            <!-- <button type="submit" name="clear_check" class="btn btn-outline-secondary btn-sm">
                                Check Another Email
                            </button> -->
                        </form>
                    </div>

                <?php else: ?>

                    <?php if ($error): ?>
                        <div class="alert alert-danger text-center"><?= $error ?></div>
                    <?php endif; ?>

                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label fw-bold">📧 Enter Your Email:</label>
                            <input type="email" name="email" class="form-control form-control-lg"
                                placeholder="your@email.com" required>
                        </div>

                        <button type="submit" name="check_status"
                            class="btn btn-primary btn-submit-custom w-100 btn-lg mt-3">
                            Check Status
                        </button>
                    </form>
                <?php endif; ?>

            </div>
        </div>

        <div class="back-link">
            <a href="index.php">← Back to Login</a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>