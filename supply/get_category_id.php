<?php
// get_category_id.php
require_once __DIR__ . '/../config.php';

if (!isset($_GET['property_no'])) {
    echo '';
    exit;
}

$property_no = $mysqli->real_escape_string($_GET['property_no']);
$result = $mysqli->query("SELECT category_id FROM equipment WHERE property_no = '$property_no' LIMIT 1");
if ($row = $result->fetch_assoc()) {
    echo $row['category_id'];
} else {
    echo '';
}
?>
