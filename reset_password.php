<?php
require_once __DIR__ . '/config.php';
require_once 'includes/session_helper.php';
require_once 'includes/mail_config.php';
require_once 'includes/mail_helper.php';

$message = '';
$messageType = '';
$tokenValid = false;
$userId = null;

$token = $_GET['token'] ?? '';

if (empty($token)) {
    $message = 'Invalid reset token. Please request a new password reset.';
    $messageType = 'danger';
}
    
    $stmt = $mysqli->prepare("
        SELECT prt.id, prt.user_id, prt.expires_at, u.username, u.email 
        FROM password_reset_tokens prt
        JOIN users u ON prt.user_id = u.id
        WHERE prt.token = ? AND prt.expires_at > NOW()
    ");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($tokenData = $result->fetch_assoc()) {
        $tokenValid = true;
        $userId = $tokenData['user_id'];
        $username = $tokenData['username'];
        $userEmail = $tokenData['email'];
    } else {
        $message = 'Invalid or expired reset token. Please request a new password reset.';
        $messageType = 'danger';
    }
    
    $stmt->close();
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenValid) {
        $newPassword = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        if (strlen($newPassword) < 8) {
            $message = 'Password must be at least 8 characters.';
            $messageType = 'danger';
        } elseif ($newPassword !== $confirmPassword) {
            $message = 'Passwords do not match.';
            $messageType = 'danger';
        } else {
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            
            $updateStmt = $mysqli->prepare("UPDATE users SET password = ? WHERE id = ?");
            $updateStmt->bind_param("si", $hashedPassword, $userId);
            
            if ($updateStmt->execute()) {
                $deleteStmt = $mysqli->prepare("DELETE FROM password_reset_tokens WHERE user_id = ?");
                $deleteStmt->bind_param("i", $userId);
                $deleteStmt->execute();
                $deleteStmt->close();
                
                sendPasswordChangeNotification($userEmail, $username);
                
                $message = 'Password has been reset successfully! You can now login with your new password.';
                $messageType = 'success';
                $tokenValid = false;
            } else {
                $message = 'Failed to update password. Please try again.';
                $messageType = 'danger';
            }
            $updateStmt->close();
        }
    }
    
    $mysqli->close();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - PEPO</title>
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
                <h2 class="text-2xl font-bold text-pepo-dark mb-2 text-center">Reset Password</h2>
                
                <?php if ($message): ?>
                    <div class="mb-4 p-4 rounded-lg <?= $messageType === 'success' ? 'bg-green-100 text-green-700 border border-green-200' : 'bg-red-100 text-red-700 border border-red-200'; ?>">
                        <?= htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($messageType === 'success'): ?>
                    <div class="text-center">
                        <a href="index.php"
                            class="inline-block bg-green-600 hover:bg-green-700 text-white font-bold py-3 px-6 rounded-lg transition duration-200">
                            Go to Login
                        </a>
                    </div>
                <?php elseif ($tokenValid): ?>
                    <form method="POST" action="reset_password.php?token=<?= urlencode($token); ?>">
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">New Password</label>
                            <input type="password" name="password" required minlength="8"
                                class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500 outline-none transition"
                                placeholder="Enter new password (min 8 characters)">
                        </div>
                        
                        <div class="mb-6">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Confirm New Password</label>
                            <input type="password" name="confirm_password" required minlength="8"
                                class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500 outline-none transition"
                                placeholder="Confirm new password">
                        </div>
                        
                        <button type="submit"
                            class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-3 rounded-lg transition duration-200">
                            Reset Password
                        </button>
                    </form>
                <?php endif; ?>
                
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
