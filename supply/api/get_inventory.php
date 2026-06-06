<?php
// supply/api/get_inventory.php - Fetch fresh inventory data
header('Content-Type: application/json');

require_once __DIR__ . '/../../includes/session_helper.php';
start_user_session();

// Security check - only allow supply role
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'supply') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../db_connect.php';

$user_location = 'Mamburao';
$user_id = $_SESSION['user_id'];

// Get user location
$user_query = $mysqli->prepare("SELECT location, username FROM users WHERE id = ?");
$user_query->bind_param("i", $user_id);
$user_query->execute();
$user_result = $user_query->get_result();
if ($user_result && $user_result->num_rows > 0) {
    $user_data = $user_result->fetch_assoc();
    $user_location = $user_data['location'] ?? 'Mamburao';
}
$user_query->close();

// Fetch items - NO CACHE, fresh from database
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 50;
$offset = ($page - 1) * $limit;

$inventoryData = [];

// Fetch items with pagination
$stmt = $mysqli->prepare("SELECT id, category, item, model_no, allocation, status, log_stats, date_added, borrowed_date, returned_date FROM inventory WHERE allocation = ? ORDER BY id DESC LIMIT ? OFFSET ?");
$stmt->bind_param("sii", $user_location, $limit, $offset);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $inventoryData['items'][] = $row;
}
$stmt->close();

// Get total count
$count_stmt = $mysqli->prepare("SELECT COUNT(*) as total FROM inventory WHERE allocation = ?");
$count_stmt->bind_param("s", $user_location);
$count_stmt->execute();
$inventoryData['total'] = $count_stmt->get_result()->fetch_assoc()['total'] ?? 0;
$count_stmt->close();

// Get stats
$stats_stmt = $mysqli->prepare("
    SELECT 
        (SELECT COUNT(*) FROM inventory WHERE allocation = ? AND log_stats LIKE 'BORROWED%') as borrowed,
        (SELECT COUNT(*) FROM inventory WHERE allocation = ? AND (log_stats = 'AVAILABLE' OR log_stats IS NULL OR log_stats = '')) as available
");
$stats_stmt->bind_param("ss", $user_location, $user_location);
$stats_stmt->execute();
$stats_result = $stats_stmt->get_result()->fetch_assoc();
$inventoryData['stats'] = [
    'borrowed' => $stats_result['borrowed'] ?? 0,
    'available' => $stats_result['available'] ?? 0
];
$stats_stmt->close();

echo json_encode([
    'success' => true,
    'data' => $inventoryData,
    'location' => $user_location
]);
