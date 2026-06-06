<?php
// api/mark_read.php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/session_helper.php';

$userId = $_SESSION['user_id'];

// 1. Get User Location first
$userQuery = $mysqli->prepare("SELECT location FROM users WHERE id = ?");
$userQuery->bind_param("i", $userId);
$userQuery->execute();
$locationResult = $userQuery->get_result();
$userLocation = $locationResult->fetch_assoc()['location'] ?? '';

// 2. Update documents matching that location
if ($userLocation) {
    $stmt = $mysqli->prepare("
        UPDATE documents 
        SET is_read = 1 
        WHERE status IN ('Pending', 'pending_supply') 
        AND is_read = 0 
        AND location = ?
    ");
    
    $stmt->bind_param("s", $userLocation);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Notifications cleared']);
    } else {
        echo json_encode(['success' => false, 'message' => $stmt->error]);
    }
    $stmt->close();
} else {
    echo json_encode(['success' => false, 'message' => 'Location not found']);
}

$mysqli->close();
?>