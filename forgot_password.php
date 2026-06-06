<?php
require_once __DIR__ . '/config.php';
require_once 'includes/session_helper.php';
require_once 'includes/mail_config.php';
require_once __DIR__ . '/vendor/autoload.php';

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL);
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Please enter a valid email address.';
        $messageType = 'danger';
    }
        
        $stmt = $mysqli->prepare("SELECT id, username FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($user = $result->fetch_assoc()) {
            $token = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', time() + (RESET_TOKEN_EXPIRY * 60));
            
            $insertStmt = $mysqli->prepare("INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (?, ?, ?)");
            $insertStmt->bind_param("iss", $user['id'], $token, $expiresAt);
            
            if ($insertStmt->execute()) {
                require_once 'includes/mail_helper.php';
                if (sendPasswordResetEmail($email, $token)) {
                    $message = 'Password reset link has been sent to your email. Please check your inbox (and spam folder).';
                    $messageType = 'success';
                } else {
                    $message = 'Failed to send email. Please try again later or contact administrator.';
                    $messageType = 'danger';
                }
            } else {
                $message = 'Failed to generate reset token. Please try again.';
                $messageType = 'danger';
            }
            $insertStmt->close();
        } else {
            $message = 'No account found with that email address.';
            $messageType = 'danger';
        }
        
        $stmt->close();
        $mysqli->close();
    }

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - PEPO</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Poppins', 'sans-serif'],
                    },
                    colors: {
                        pepo: {
                            dark: '#1e1e2d',
                            green: '#0ac347',
                            light: '#f3f6f9',
                            muted: '#a1a5b7',
                        }
                    }
                }
            }
        }
    </script>
    <style>
        body {
            background: linear-gradient(-45deg, #103a13, #064e3b, #129112, #000000);
            background-size: 500% 500%;
            animation: gradientBG 20s ease infinite;
            min-height: 100vh;
        }
        @keyframes gradientBG {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
    </style>
</head>
<body class="flex items-center justify-center min-h-screen">
    <div class="w-full max-w-md mx-4">
        <div class="bg-white rounded-2xl shadow-2xl overflow-hidden">
            <div class="bg-gradient-to-r from-green-700 to-green-500 p-8 text-center">
                <h1 class="text-2xl font-bold text-white">PEPO</h1>
                <p class="text-green-100 text-sm">Equipment Pool Operations</p>
            </div>
            
            <div class="p-8">
                <h2 class="text-2xl font-bold text-pepo-dark mb-2 text-center">Forgot Password?</h2>
                <p class="text-gray-500 text-sm mb-6 text-center">Enter your email address and we'll send you a link to reset your password.</p>
                
                <?php if ($message): ?>
                    <div class="mb-4 p-4 rounded-lg <?= $messageType === 'success' ? 'bg-green-100 text-green-700 border border-green-200' : 'bg-red-100 text-red-700 border border-red-200'; ?>">
                        <?= htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="forgot_password.php">
                    <div class="mb-6">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Email Address</label>
                        <input type="email" name="email" required
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500 outline-none transition"
                            placeholder="Enter your registered email">
                    </div>
                    
                    <button type="submit"
                        class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-3 rounded-lg transition duration-200">
                        Send Reset Link
                    </button>
                </form>
                
                <div class="mt-6 text-center">
                    <a href="index.php" class="text-sm text-green-600 hover:underline">
                        ← Back to Login
                    </a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
