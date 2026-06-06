<?php
// complete_document.php - Handle complete action via AJAX
header('Content-Type: application/json');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/session_helper.php';
require_once __DIR__ . '/includes/mail_helper.php';
start_user_session();

$id = intval($_GET['id'] ?? 0);

if ($id === 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid document ID']);
    exit;
}

$date_completed = date('Y-m-d');

// Update document status to Complete
$stmt = $mysqli->prepare("UPDATE documents SET status = 'Complete', date_completed = ? WHERE id = ?");
$stmt->bind_param("si", $date_completed, $id);

if ($stmt->execute()) {
    // Also update equipment status if property_no exists
    $docStmt = $mysqli->prepare("SELECT property_no FROM documents WHERE id = ?");
    $docStmt->bind_param("i", $id);
    $docStmt->execute();
    $docResult = $docStmt->get_result();
    $doc = $docResult->fetch_assoc();
    $docStmt->close();
    
    if ($doc && !empty($doc['property_no'])) {
        $equipStmt = $mysqli->prepare("UPDATE equipment SET status = 'Operational', last_repair_date = ? WHERE property_no = ?");
        $equipStmt->bind_param("ss", $date_completed, $doc['property_no']);
        $equipStmt->execute();
        $equipStmt->close();
    }
    
    // Send email notification to user
    sendRepairCompleteEmail($id, $mysqli);
    
    echo json_encode(['success' => true, 'message' => 'Document marked as Complete']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to update document']);
}

$stmt->close();
$mysqli->close();
