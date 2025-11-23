<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$mysqli = new mysqli('localhost', 'root', '', 'user_management');

if ($mysqli->connect_errno) {
    die("Failed to connect to MySQL: " . $mysqli->connect_error);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $activity_type = $mysqli->real_escape_string($_POST['activity_type']);
    $property_no = $mysqli->real_escape_string($_POST['property_no']);
    $status = $mysqli->real_escape_string($_POST['status']); // NEW FIELD
    $date_time = date('Y-m-d H:i:s');

    // Insert into your activity log table
    $query = "INSERT INTO activity_log (activity_type, property_no, status, date_time)
              VALUES ('$activity_type', '$property_no', '$status', '$date_time')";

    if ($mysqli->query($query)) {
        $_SESSION['success'] = "Activity Log added successfully!";
    } else {
        $_SESSION['error'] = "Error: " . $mysqli->error;
    }

    // Redirect to your target page
    header("Location: http://localhost/PEPO/admin_dashboard.php?view=activities");
    exit;
}
?>
