<?php
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $activity_type = $mysqli->real_escape_string($_POST['activity_type']);
    $property_no = $mysqli->real_escape_string($_POST['property_no']);
    $status = $mysqli->real_escape_string($_POST['status']);
    $date_time = date('Y-m-d H:i:s');
    
    // Get location from POST data (from the hidden input in the form)
    $location = $mysqli->real_escape_string($_POST['location'] ?? '');
    
    // If location is empty, try to get it from session
    if (empty($location) && isset($_SESSION['user_location'])) {
        $location = $mysqli->real_escape_string($_SESSION['user_location']);
    }
    
    // Default location if still empty
    if (empty($location)) {
        $location = 'mamburao'; // Default fallback
    }

    // Insert into your activity log table WITH LOCATION
    $query = "INSERT INTO activity_log (activity_type, property_no, location, status, date_time)
              VALUES ('$activity_type', '$property_no', '$location', '$status', '$date_time')";

    if ($mysqli->query($query)) {
        $_SESSION['success'] = "Activity Log added successfully!";
    } else {
        $_SESSION['error'] = "Error: " . $mysqli->error;
    }

    // Redirect to the admin dashboard with the activities view
    $redirect = 'admin_dashboard.php?view=activities';  // Updated to your desired URL
    header('Location: ' . $redirect);
    exit();  // Always exit after a redirect to prevent further execution
}

$mysqli->close();
?>