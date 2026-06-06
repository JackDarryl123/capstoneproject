<?php
// fetch_supply_notifications.php - For Supply Dashboard

error_reporting(0);
ini_set('display_errors', 0);

ob_start();

header('Content-Type: application/json');

try {
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/includes/session_helper.php';
    
    if (function_exists('start_user_session')) {
        start_user_session();
    } else {
        if (session_status() === PHP_SESSION_NONE) session_start();
    }

    if (empty($_SESSION['user_id'])) {
        throw new Exception('User not logged in.');
    }

    $userId = $_SESSION['user_id'];
    $userRole = $_SESSION['role'] ?? '';

    // Handle Mark as Read action
    if (isset($_POST['action']) && $_POST['action'] === 'mark_as_read') {
        $userLocation = '';
        $userStmt = $mysqli->prepare("SELECT location FROM users WHERE id = ?");
        $userStmt->bind_param("i", $userId);
        $userStmt->execute();
        $userRes = $userStmt->get_result();
        
        if ($row = $userRes->fetch_assoc()) {
            $userLocation = $row['location'];
        }
        $userStmt->close();

        if (!empty($userLocation)) {
            // Archive all pending and received notifications for this location
            $updateStmt = $mysqli->prepare("UPDATE supply_requests SET status = 'archived' WHERE status IN ('pending', 'received') AND LOWER(supply_location) = LOWER(?)");
            $updateStmt->bind_param("s", $userLocation);
            $updateStmt->execute();
            $updateStmt->close();
        }

        ob_end_clean();
        echo json_encode(['success' => true, 'message' => 'Notifications marked as read']);
        exit;
    }

    // Get User Location for Supply
    $userLocation = '';
    $userStmt = $mysqli->prepare("SELECT location FROM users WHERE id = ?");
    $userStmt->bind_param("i", $userId);
    $userStmt->execute();
    $userRes = $userStmt->get_result();
    
    if ($row = $userRes->fetch_assoc()) {
        $userLocation = $row['location'];
    }
    $userStmt->close();

    // Fetch Pending and Received Supply Requests for this supply location
    $sql = "SELECT id, pre_repair_no, property_no, requested_by, supply_location, status, created_at 
            FROM supply_requests 
            WHERE status IN ('pending', 'received')
            AND LOWER(supply_location) = LOWER(?)
            ORDER BY 
                CASE status 
                    WHEN 'pending' THEN 1 
                    WHEN 'received' THEN 2 
                END, created_at DESC";

    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("s", $userLocation);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $notifications = [];
    while ($row = $result->fetch_assoc()) {
        $notifications[] = $row;
    }
    $stmt->close();

    // Get Total Count (Pending + Received)
    $countSql = "SELECT COUNT(*) as total 
                 FROM supply_requests 
                 WHERE status IN ('pending', 'received')
                 AND LOWER(supply_location) = LOWER(?)";
                  
    $countStmt = $mysqli->prepare($countSql);
    $countStmt->bind_param("s", $userLocation);
    $countStmt->execute();
    $countData = $countStmt->get_result()->fetch_assoc();
    $totalCount = $countData['total'];
    $countStmt->close();

    ob_end_clean();
    echo json_encode([
        'success' => true, 
        'notifications' => $notifications, 
        'count' => $totalCount
    ]);

} catch (Exception $e) {
    ob_end_clean();
    echo json_encode([
        'success' => false, 
        'message' => $e->getMessage()
    ]);
}

if (isset($mysqli)) {
    $mysqli->close();
}
?>
