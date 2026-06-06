<?php
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/session_helper.php';
start_user_session();

header('Content-Type: application/json');

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'user') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$userId = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? '';

// If username is not in session, fetch it from database
if (empty($username)) {
    $userStmt = $mysqli->prepare("SELECT username FROM users WHERE id = ?");
    $userStmt->bind_param("i", $userId);
    $userStmt->execute();
    $userRes = $userStmt->get_result()->fetch_assoc();
    $username = $userRes['username'] ?? '';
    $userStmt->close();
    
    // Cache it in session for future use
    $_SESSION['username'] = $username;
}

// Create table for read notifications if not exists
$createTable = $mysqli->query("
    CREATE TABLE IF NOT EXISTS user_notifications_read (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        document_id INT NOT NULL,
        read_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_read (user_id, document_id),
        INDEX idx_user_id (user_id)
    )
");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'mark_read') {
        $documentId = (int)($_POST['document_id'] ?? 0);
        
        if ($documentId > 0) {
            $stmt = $mysqli->prepare("
                INSERT IGNORE INTO user_notifications_read (user_id, document_id) VALUES (?, ?)
            ");
            $stmt->bind_param('ii', $userId, $documentId);
            $stmt->execute();
            $stmt->close();
            
            echo json_encode(['success' => true, 'message' => 'Marked as read']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid document ID']);
        }
        $mysqli->close();
        exit();
    }
    
    if ($action === 'mark_all_read') {
        // Mark all user's documents as read
        $stmt = $mysqli->prepare("
            SELECT id FROM documents 
            WHERE user_id = ?
            AND status IN ('Approved', 'APPROVED', 'Archived', 'Complete', 'Done')
        ");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $insertStmt = $mysqli->prepare("
            INSERT IGNORE INTO user_notifications_read (user_id, document_id) VALUES (?, ?)
        ");
        
        while ($row = $result->fetch_assoc()) {
            $insertStmt->bind_param('ii', $userId, $row['id']);
            $insertStmt->execute();
        }
        
        $insertStmt->close();
        $stmt->close();
        
        echo json_encode(['success' => true, 'message' => 'All marked as read']);
        $mysqli->close();
        exit();
    }
    
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    $mysqli->close();
    exit();
}

$cleanUsername = trim($username);

// Get notifications by user_id (the actual requester)
$query = "
    SELECT d.id, d.pre_repair_no, d.property_no, d.status, d.date_requested, d.date_completed, d.location
    FROM documents d
    WHERE d.user_id = ?
    AND d.status IN ('Approved', 'APPROVED', 'Archived', 'Complete', 'Done')
    AND d.id NOT IN (
        SELECT document_id FROM user_notifications_read WHERE user_id = ?
    )
    ORDER BY d.date_requested DESC
    LIMIT 20
";

$stmt = $mysqli->prepare($query);
$stmt->bind_param('ii', $userId, $userId);
$stmt->execute();
$result = $stmt->get_result();

$notifications = [];
while ($row = $result->fetch_assoc()) {
    $notifications[] = $row;
}
$stmt->close();
$mysqli->close();

echo json_encode([
    'success' => true,
    'notifications' => $notifications,
    'count' => count($notifications)
]);
exit();
