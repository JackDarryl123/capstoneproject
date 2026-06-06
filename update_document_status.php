<?php
session_start();

header('Content-Type: application/json');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/mail_helper.php';

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = intval($_POST['id'] ?? 0);
    
    if ($id == 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid document ID']);
        exit;
    }

    // Add to Maintenance
    if (isset($_POST['add_to_maintenance'])) {
        $date_done = date('Y-m-d');
        $stmt = $mysqli->prepare("UPDATE documents SET status = 'Done', date_done = ? WHERE id = ?");
        $stmt->bind_param("si", $date_done, $id);
        
        if ($stmt->execute()) {
            // Send email notification to user
            sendRepairCompleteEmail($id, $mysqli);
            echo json_encode(['success' => true, 'message' => 'Document added to maintenance']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed: ' . $stmt->error]);
        }
        $stmt->close();
    }
    // Archive document
    elseif (isset($_POST['archive'])) {
        $stmt = $mysqli->prepare("UPDATE documents SET status = 'Archived' WHERE id = ?");
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Document archived']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed: ' . $stmt->error]);
        }
        $stmt->close();
    }
    // Approve document
    elseif (isset($_POST['approve'])) {
        $date_approved = date('Y-m-d');
        $stmt = $mysqli->prepare("UPDATE documents SET status = 'Approved', date_approved = ? WHERE id = ?");
        $stmt->bind_param("si", $date_approved, $id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Document approved']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed: ' . $stmt->error]);
        }
        $stmt->close();
    }
    // Update document fields
    elseif (isset($_POST['update_document'])) {
        $pre_repair_no = $_POST['pre_repair_no'] ?? '';
        $inspector_name = $_POST['inspector_name'] ?? '';
        $inspector_position = $_POST['inspector_position'] ?? '';
        $defect = $_POST['defect'] ?? '';
        $findings = $_POST['findings'] ?? '';
        $recommendation = $_POST['recommendation'] ?? '';
        $officer_name = $_POST['officer_name'] ?? '';
        $inspected_by = $_POST['inspected_by'] ?? '';
        $approved_by_pepo = $_POST['approved_by_pepo'] ?? '';
        $witnessed_by = $_POST['witnessed_by'] ?? '';
        $approved_by_gso = $_POST['approved_by_gso'] ?? '';
        $carrying_amount = $_POST['carrying_amount'] ?? '0.00';

        // Handle File Upload during Update
        $attached_file_path = $_POST['existing_attached_file_path'] ?? '';
        if (isset($_FILES['attached_file_path']) && $_FILES['attached_file_path']['error'] == 0) {
            $upload_dir = 'uploads/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            $file_name = time() . '_' . basename($_FILES['attached_file_path']['name']);
            $target_path = $upload_dir . $file_name;
            if (move_uploaded_file($_FILES['attached_file_path']['tmp_name'], $target_path)) {
                $attached_file_path = $target_path;
            }
        }

        // Collect Materials and Quantities (1 to 10)
        $materials = [];
        for ($i = 1; $i <= 10; $i++) {
            $materials["material_$i"] = $_POST["material_$i"] ?? '';
            $materials["quantity_$i"] = $_POST["quantity_$i"] ?? '';
        }

        $sql = "UPDATE documents SET 
            pre_repair_no = ?, inspector_name = ?, inspector_position = ?, 
            defect = ?, findings = ?, recommendation = ?, officer_name = ?,
            inspected_by = ?, approved_by_pepo = ?, witnessed_by = ?, 
            approved_by_gso = ?, carrying_amount = ?, attached_file_path = ?";
        
        // Append materials to SQL
        for ($i = 1; $i <= 10; $i++) {
            $sql .= ", material_$i = ?, quantity_$i = ?";
        }
        
        $sql .= " WHERE id = ?";
        
        $stmt = $mysqli->prepare($sql);
        
        // Prepare parameters for bind_param
        $params = [
            $pre_repair_no, $inspector_name, $inspector_position,
            $defect, $findings, $recommendation, $officer_name,
            $inspected_by, $approved_by_pepo, $witnessed_by,
            $approved_by_gso, $carrying_amount, $attached_file_path
        ];

        // Add materials and quantities to parameters
        for ($i = 1; $i <= 10; $i++) {
            $params[] = $materials["material_$i"];
            $params[] = $materials["quantity_$i"];
        }

        $params[] = $id;

        $types = str_repeat('s', count($params) - 1) . 'i';
        $stmt->bind_param($types, ...$params);

        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Document updated successfully', 'attached_file_path' => $attached_file_path]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed: ' . $stmt->error]);
        }
        $stmt->close();
    }
    else {
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
}
// Handle GET requests (backward compatibility)
else {
    $id = intval($_GET['id'] ?? 0);
    $action = $_GET['action'] ?? '';

    if ($id == 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid document ID']);
        exit;
    }

    if ($action === 'maintenance') {
        $date_done = date('Y-m-d');
        $stmt = $mysqli->prepare("UPDATE documents SET status = 'Done', date_done = ? WHERE id = ?");
        $stmt->bind_param("si", $date_done, $id);
        
        if ($stmt->execute()) {
            // Send email notification to user
            sendRepairCompleteEmail($id, $mysqli);
            echo json_encode(['success' => true, 'message' => 'Document added to maintenance']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed: ' . $stmt->error]);
        }
        $stmt->close();
    } elseif ($action === 'archive') {
        $stmt = $mysqli->prepare("UPDATE documents SET status = 'Archived' WHERE id = ?");
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Document archived']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed: ' . $stmt->error]);
        }
        $stmt->close();
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
}

$mysqli->close();
?>
