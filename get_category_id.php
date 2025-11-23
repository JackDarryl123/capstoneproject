<?php
// get_category_id.php
if (!isset($_GET['property_no'])) {
    echo '';
    exit;
}

$mysqli = new mysqli('localhost', 'root', '', 'user_management');
if ($mysqli->connect_errno) {
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
