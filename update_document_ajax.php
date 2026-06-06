<?php
session_start();

header('Content-Type: application/json');

require_once __DIR__ . '/config.php';

$id = intval($_POST['document_id'] ?? 0);

if ($id == 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid document ID']);
    exit;
}

// Fields that can be updated
$editableFields = [
    'pre_repair_no',
    'carrying_amount',
    'defect',
    'findings',
    'recommendation'
];

// Add material and quantity fields
for ($i = 1; $i <= 10; $i++) {
    $editableFields[] = "material_$i";
    $editableFields[] = "quantity_$i";
}

$updateFields = [];
$params = [];
$types = '';

foreach ($editableFields as $field) {
    if (isset($_POST[$field])) {
        $updateFields[] = "$field = ?";
        $params[] = $_POST[$field];
        $types .= 's';
    }
}

if (empty($updateFields)) {
    echo json_encode(['success' => false, 'message' => 'No fields to update']);
    exit;
}

$params[] = $id;
$types .= 'i';

$sql = "UPDATE documents SET " . implode(', ', $updateFields) . " WHERE id = ?";
$stmt = $mysqli->prepare($sql);

if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $mysqli->error]);
    exit;
}

$stmt->bind_param($types, ...$params);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Document updated successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Update failed: ' . $stmt->error]);
}

$stmt->close();
$mysqli->close();
