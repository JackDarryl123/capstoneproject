<?php
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (isset($_POST['add_document'])) {
    $fields = [
        'pre_repair_no',
        'property_no',
        'category_id',
        'inspector_name',
        'inspector_position',
        'defect',
        'findings',
        'admin_note',
        'recommendation',
        'inspected_by',
        'approved_by_pepo',
        'witnessed_by',
        'approved_by_gso'
    ];

    // Add materials & quantities
    for ($i = 1; $i <= 10; $i++) {
        $fields[] = "material_$i";
        $fields[] = "quantity_$i";
    }

    // Handle File Upload
    $attached_file_path = '';
    if (isset($_FILES['attached_file']) && $_FILES['attached_file']['error'] == 0) {
        $upload_dir = 'uploads/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        $file_name = time() . '_' . basename($_FILES['attached_file']['name']);
        $target_path = $upload_dir . $file_name;
        if (move_uploaded_file($_FILES['attached_file']['tmp_name'], $target_path)) {
            $attached_file_path = $target_path;
        }
    }

    $fields[] = 'attached_file_path';

    $values = [];
    $placeholders = [];
    foreach ($fields as $f) {
        if ($f === 'attached_file_path') {
            $values[] = $attached_file_path;
        } else {
            $values[] = $_POST[$f] ?? '';
        }
        $placeholders[] = '?';
    }

    $types = str_repeat('s', count($fields));
    $stmt = $mysqli->prepare("INSERT INTO documents (" . implode(',', $fields) . ") VALUES (" . implode(',', $placeholders) . ")");
    $stmt->bind_param($types, ...$values);

    if ($stmt->execute()) {
        $redirect = 'admin_dashboard.php?view=documents';
        // Handle different dashboard locations if needed
        if (strpos($_SERVER['HTTP_REFERER'], 'GSO') !== false) {
            $redirect = 'admin_dashboard.php?view=documents';
        }
        header("Location: " . $redirect);
        exit();
    } else {
        echo "<div class='alert alert-danger'>Failed to add document: " . $stmt->error . "</div>";
    }
    $stmt->close();
}
