<?php
require_once __DIR__ . '/config.php';
require_once 'includes/session_helper.php';
require_once 'includes/mail_config.php';

$message = '';
$messageType = '';
$success = false;

$requestId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($requestId <= 0) {
    $message = 'Invalid request ID.';
    $messageType = 'danger';
}
    
    // Get request details
    $stmt = $mysqli->prepare("
        SELECT id, pre_repair_no, property_no, requested_by, status 
        FROM supply_requests 
        WHERE id = ?
    ");
    $stmt->bind_param("i", $requestId);
    $stmt->execute();
    $result = $stmt->get_result();
    $request = $result->fetch_assoc();
    $stmt->close();
    
if (!$request) {
        $message = 'Request not found.';
        $messageType = 'danger';
    } elseif ($request['status'] === 'received') {
        $message = 'This request has already been confirmed as received.';
        $messageType = 'info';
        $success = true;
    } elseif ($request['status'] !== 'complied') {
        $message = 'This request cannot be confirmed as received. It must be in "Complied" status first.';
        $messageType = 'warning';
    } else {
        // Update status to received
        $updateStmt = $mysqli->prepare("UPDATE supply_requests SET status = 'received', received_at = NOW() WHERE id = ?");
        $updateStmt->bind_param("i", $requestId);
        
        if ($updateStmt->execute()) {
            $message = 'Thank you! You have confirmed receipt of your requested items. The supply request is now complete.';
            $messageType = 'success';
            $success = true;
        } else {
            $message = 'Failed to update request status. Please try again.';
            $messageType = 'danger';
        }
        $updateStmt->close();
    }
    
    $mysqli->close();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirm Received - PEPO</title>
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
                <?php if ($success): ?>
                    <div class="text-center">
                        <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                            <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                        </div>
                        <h2 class="text-2xl font-bold text-pepo-dark mb-2">Confirmed!</h2>
                    </div>
                <?php else: ?>
                    <div class="text-center">
                        <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
                            <svg class="w-8 h-8 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </div>
                        <h2 class="text-2xl font-bold text-pepo-dark mb-2">Unable to Confirm</h2>
                    </div>
                <?php endif; ?>
                
                <?php if ($message): ?>
                    <div class="mb-6 p-4 rounded-lg text-center <?= $messageType === 'success' ? 'bg-green-100 text-green-700' : ($messageType === 'warning' ? 'bg-yellow-100 text-yellow-700' : 'bg-red-100 text-red-700'); ?>">
                        <?= htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($request) && $request): ?>
                    <div class="bg-gray-50 rounded-lg p-4 mb-6">
                        <h3 class="font-semibold text-gray-700 mb-2">Request Details</h3>
                        <p class="text-sm"><strong>Request ID:</strong> #<?= $request['id']; ?></p>
                        <p class="text-sm"><strong>Pre-Repair No:</strong> <?= htmlspecialchars($request['pre_repair_no'] ?? 'N/A'); ?></p>
                        <p class="text-sm"><strong>Property No:</strong> <?= htmlspecialchars($request['property_no'] ?? 'N/A'); ?></p>
                        <p class="text-sm"><strong>Requested By:</strong> <?= htmlspecialchars($request['requested_by']); ?></p>
                    </div>
                <?php endif; ?>
                
                <div class="text-center">
                    <a href="index.php" class="inline-block bg-green-600 hover:bg-green-700 text-white font-bold py-3 px-6 rounded-lg transition duration-200">
                        Go to Login
                    </a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
