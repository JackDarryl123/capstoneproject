<?php
// fetch_notifications.php

// 1. Disable HTML error reporting (Keep JSON clean)
error_reporting(E_ALL);
ini_set('display_errors', 0);

// 2. Buffer output to catch any unwanted text/whitespace
ob_start();

header('Content-Type: application/json');

try {
    // 3. Include config and session helper
    require_once __DIR__ . '/config.php';
    
    $sessionHelperPath = __DIR__ . '/includes/session_helper.php';
    if (!file_exists($sessionHelperPath)) {
        throw new Exception('Session helper file not found.');
    }
    require_once $sessionHelperPath;

    // 4. CRITICAL FIX: Call your custom function to start the session
    if (function_exists('start_user_session')) {
        start_user_session();
    } else {
        if (session_status() === PHP_SESSION_NONE) session_start();
    }

    // 5. Validate Login
    if (empty($_SESSION['user_id'])) {
        throw new Exception('User not logged in.');
    }

    $userId = $_SESSION['user_id'];

    // 7. Get User Location
    $userLocation = '';
    
    $colCheck = $mysqli->query("SHOW COLUMNS FROM users LIKE 'location'");
    
    if ($colCheck && $colCheck->num_rows > 0) {
        $userStmt = $mysqli->prepare("SELECT location FROM users WHERE id = ?");
        $userStmt->bind_param("i", $userId);
        $userStmt->execute();
        $userRes = $userStmt->get_result();
        
        if ($row = $userRes->fetch_assoc()) {
            $userLocation = $row['location'];
        }
        $userStmt->close();
    }

    $notifications = [];
    $totalCount = 0;

    // Part A: Get document notifications
    $docSql = "SELECT id, officer_name, pre_repair_no as ref_no, date_requested, status, equipment, 'document' as source
                FROM documents 
                WHERE status IN ('Pending', 'pending_supply') 
                AND is_read = 0";
    
    if (!empty($userLocation)) {
        $docSql .= " AND location = ?";
    }
    
    $docStmt = $mysqli->prepare($docSql);
    if (!empty($userLocation)) {
        $docStmt->bind_param("s", $userLocation);
    }
    $docStmt->execute();
    $docResult = $docStmt->get_result();
    
    while ($row = $docResult->fetch_assoc()) {
        $notifications[] = $row;
    }
    $docStmt->close();

    // Part B: Get supply request notifications
    $supplySql = "SELECT id, requested_by as officer_name, pre_repair_no as ref_no, 
                  COALESCE(approved_at, created_at) as date_requested, 
                  status, property_no as equipment, 'supply' as source
                  FROM supply_requests 
                  WHERE status IN ('approved', 'complied')";
    
    if (!empty($userLocation)) {
        $supplySql .= " AND supply_location = ?";
    }
    
    $supplyStmt = $mysqli->prepare($supplySql);
    if (!empty($userLocation)) {
        $supplyStmt->bind_param("s", $userLocation);
    }
    $supplyStmt->execute();
    $supplyResult = $supplyStmt->get_result();
    
    while ($row = $supplyResult->fetch_assoc()) {
        $notifications[] = $row;
    }
    $supplyStmt->close();

    // Sort by date
    usort($notifications, function($a, $b) {
        return strtotime($b['date_requested']) - strtotime($a['date_requested']);
    });
    
    // Limit to 20
    $notifications = array_slice($notifications, 0, 20);
    $totalCount = count($notifications);

    // 10. Output JSON Response
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