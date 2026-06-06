<?php
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if property_no is provided
if (!isset($_POST['property_no']) || empty(trim($_POST['property_no']))) {
    die(json_encode(['success' => false, 'message' => 'Property number is required']));
}

$property_no = trim($_POST['property_no']);

// Lookup equipment by property_no
$query = "
    SELECT e.*, c.category_name 
    FROM equipment e
    JOIN equipment_category c ON e.category_id = c.id
    WHERE e.property_no = ?
";
$stmt = $mysqli->prepare($query);
$stmt->bind_param("s", $property_no);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    // Try case-insensitive search
    $query = "
        SELECT e.*, c.category_name 
        FROM equipment e
        JOIN equipment_category c ON e.category_id = c.id
        WHERE UPPER(e.property_no) = UPPER(?)
    ";
    $stmt = $mysqli->prepare($query);
    $stmt->bind_param("s", $property_no);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        die(json_encode([
            'success' => false, 
            'message' => "Equipment with property number '{$property_no}' not found."
        ]));
    }
}

$equipment = $result->fetch_assoc();
$stmt->close();
$mysqli->close();

// Return success with equipment data
echo json_encode([
    'success' => true,
    'message' => 'Equipment found successfully',
    'equipment' => [
        'id' => $equipment['id'],
        'property_no' => $equipment['property_no'],
        'category_name' => $equipment['category_name'],
        'description' => $equipment['description'],
        'location' => $equipment['location'],
        'status' => $equipment['status']
    ]
]);
?>