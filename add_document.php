<?php
if (session_status() === PHP_SESSION_NONE) session_start();

$mysqli = new mysqli('localhost', 'root', '', 'user_management');
if ($mysqli->connect_errno) die("Connection failed: " . $mysqli->connect_error);

if (isset($_POST['add_document'])) {
    $fields = [
        'pre_repair_no', 'property_no', 'category_id', 'inspector_name', 'inspector_position',
        'defect', 'findings', 'recommendation', 'inspected_by', 'approved_by_pepo', 'witnessed_by', 'approved_by_gso'
    ];

    // Add materials & quantities
    for ($i = 1; $i <= 10; $i++) {
        $fields[] = "material_$i";
        $fields[] = "quantity_$i";
    }

    $values = [];
    $placeholders = [];
    $types = str_repeat('s', count($fields));
    foreach ($fields as $f) {
        $values[] = $_POST[$f] ?? '';
        $placeholders[] = '?';
    }

    $stmt = $mysqli->prepare("INSERT INTO documents (" . implode(',', $fields) . ") VALUES (" . implode(',', $placeholders) . ")");
    $stmt->bind_param($types, ...$values);

    if ($stmt->execute()) {
        header("Location: admin_dashboard.php?view=documents");
        exit();
    } else {
        echo "<div class='alert alert-danger'>Failed to add document.</div>";
    }
    $stmt->close();
}
?>
