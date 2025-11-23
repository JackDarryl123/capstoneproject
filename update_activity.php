<?php
// update_activity.php
header('Content-Type: application/json');

$mysqli = new mysqli('localhost', 'root', '', 'user_management');
if ($mysqli->connect_errno) {
    echo json_encode(['success' => false, 'message' => 'DB connection failed']);
    exit;
}

$id = $_POST['id'] ?? null;
$activity_type = $_POST['activity_type'] ?? '';
$property_no = $_POST['property_no'] ?? '';
$location = $_POST['location'] ?? '';
$activity_date = $_POST['activity_date'] ?? '';
$activity_time = $_POST['activity_time'] ?? '';
$remarks = $_POST['remarks'] ?? '';

if (empty($id)) {
    echo json_encode(['success' => false, 'message' => 'Missing activity id']);
    exit;
}

$stmt = $mysqli->prepare("UPDATE activities SET activity_type = ?, property_no = ?, location = ?, activity_date = ?, activity_time = ?, remarks = ? WHERE id = ?");
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $mysqli->error]);
    exit;
}

$stmt->bind_param('ssssssi', $activity_type, $property_no, $location, $activity_date, $activity_time, $remarks, $id);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Activity updated']);
} else {
    echo json_encode(['success' => false, 'message' => 'Execute failed: ' . $stmt->error]);
}

$stmt->close();
$mysqli->close();
?>
