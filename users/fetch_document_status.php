<?php
// FILE: users/fetch_document_status.php
error_reporting(0);
ini_set('display_errors', 0);
header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';

$location = $_GET['location'] ?? '';

// Helper function to count docs by status & location
function getCount($mysqli, $status, $location) {
    $sql = "SELECT COUNT(*) as c FROM documents WHERE status = ?";
    if (!empty($location)) {
        $sql .= " AND location = ?";
    }
    
    $stmt = $mysqli->prepare($sql);
    if (!empty($location)) {
        $stmt->bind_param("ss", $status, $location);
    } else {
        $stmt->bind_param("s", $status);
    }
    
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    return (int) ($result['c'] ?? 0);
}

// Fetch counts for each status
$data = [
    'Pending' => getCount($mysqli, 'PENDING', $location),
    'Approved' => getCount($mysqli, 'APPROVED', $location),
    'Done' => getCount($mysqli, 'DONE', $location),
    'Complete' => getCount($mysqli, 'COMPLETE', $location)
];

echo json_encode($data);
?>