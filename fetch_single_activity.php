<?php
// fetch_single_activity.php
require_once __DIR__ . '/config.php';

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

// Get activity ID
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    echo json_encode(['success' => false, 'error' => 'Invalid activity ID']);
    exit();
}

// Get user location from session
$userLocation = $_SESSION['user_location'] ?? null;

// Query - if user location is set, filter by it for security
if ($userLocation) {
    $stmt = $mysqli->prepare('SELECT id, activity_type, property_no, location, activity_date, activity_time, remarks, user_name, created_at FROM activities WHERE id = ? AND location = ? LIMIT 1');
    $stmt->bind_param('is', $id, $userLocation);
} else {
    // Fallback if location not set (shouldn't happen in normal use)
    $stmt = $mysqli->prepare('SELECT id, activity_type, property_no, location, activity_date, activity_time, remarks, user_name, created_at FROM activities WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
}

$stmt->execute();
$res = $stmt->get_result();
$data = $res->fetch_assoc();

if ($data) {
    echo json_encode($data);
} else {
    echo json_encode(['success' => false, 'error' => 'Activity not found or access denied']);
}

$stmt->close();
$mysqli->close();
?>