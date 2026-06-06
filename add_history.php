<?php
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pre_repair_no   = $_POST['pre_repair_no'] ?? '';
    $recommendation  = $_POST['recommendation'] ?? '';
    $date            = $_POST['date'] ?? '';
    $status          = $_POST['status'] ?? '';

    if ($pre_repair_no && $recommendation && $date && $status) {
        // Copy document info into maintenance
        $sql = "INSERT INTO maintenance (document_id, equipment, property_no, pre_repair_no, recommendation, date, status)
                SELECT d.id, c.category_name, d.property_no, d.pre_repair_no, ?, ?, ?
                FROM documents d
                JOIN equipment_category c ON d.category_id = c.id
                WHERE d.pre_repair_no = ?
                LIMIT 1";

        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("ssss", $recommendation, $date, $status, $pre_repair_no);

        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $_SESSION['success'] = "History added successfully!";
        } else {
            $_SESSION['error'] = "No matching document found for Pre-Repair No: " . htmlspecialchars($pre_repair_no);
        }
    } else {
        $_SESSION['error'] = "All fields are required.";
    }
}

header("Location: side_maintenance.php");
exit();
