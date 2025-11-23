<?php
header('Content-Type: application/json');
$mysqli = new mysqli('localhost', 'root', '', 'user_management');
if ($mysqli->connect_errno) {
    echo json_encode(['success' => false]);
    exit;
}
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    echo json_encode(['success' => false]);
    exit;
}
$stmt = $mysqli->prepare("SELECT id, activity_type, property_no, location, activity_date, activity_time, remarks FROM activities WHERE id = ? LIMIT 1");
if (!$stmt) {
    echo json_encode(['success' => false]);
    exit;
}
$stmt->bind_param('i', $id);
$stmt->execute();
$res = $stmt->get_result();
$data = $res->fetch_assoc();
if ($data) {
    echo json_encode($data);
} else {
    echo json_encode(['success' => false]);
}
$stmt->close();
$mysqli->close();
?>
