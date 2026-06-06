<?php
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/session_helper.php';
start_user_session();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$location = $_POST['location'] ?? '';
$propertyNo = $_POST['property_no'] ?? '';

if (empty($location)) {
    echo json_encode(['success' => false, 'message' => 'Please select a Location first!']);
    exit();
}

if (empty($propertyNo)) {
    echo json_encode(['success' => false, 'message' => 'Please select a Property Number first!']);
    exit();
}

$year = date('Y');

$stmt = $mysqli->prepare("SELECT designation FROM equipment WHERE property_no = ?");
$stmt->bind_param('s', $propertyNo);
$stmt->execute();
$result = $stmt->get_result();
$equipment = $result->fetch_assoc();
$stmt->close();

if (!$equipment || empty($equipment['designation'])) {
    echo json_encode(['success' => false, 'message' => 'Property Number not found in system!']);
    $mysqli->close();
    exit();
}

$designation = $equipment['designation'];

if (stripos($designation, 'Provincial Equipment Pool Office') !== false) {
    $designationCode = 'PEPO';
} else {
    $designationCode = strtoupper(preg_replace('/[^A-Z]/', '', $designation));
    if (empty($designationCode)) {
        $designationCode = 'PEPO';
    }
}

$countStmt = $mysqli->prepare("
    SELECT COUNT(*) as cnt 
    FROM documents 
    WHERE pre_repair_no LIKE ?
");
$likePattern = $year . '-' . $designationCode . '%';
$countStmt->bind_param('s', $likePattern);
$countStmt->execute();
$countResult = $countStmt->get_result()->fetch_assoc();
$count = ($countResult['cnt'] ?? 0) + 1;
$countStmt->close();

$mysqli->close();

$preno = $year . '-' . $designationCode . '-' . str_pad($count, 3, '0', STR_PAD_LEFT);

echo json_encode([
    'success' => true,
    'pre_repair_no' => $preno,
    'designation' => $designation,
    'location' => $location,
    'count' => $count
]);
exit();
