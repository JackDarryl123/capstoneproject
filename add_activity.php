<?php
session_start(); // Optional if you want to use session messages

$mysqli = new mysqli('localhost', 'root', '', 'user_management');
if ($mysqli->connect_errno) {
    header("Location: admin_dashboard.php?view=activities&error=" . urlencode("Database connection failed"));
    exit;
}

// Validate POST inputs
$required_fields = ['activity_type', 'property_no', 'location', 'activity_date', 'activity_time', 'remarks'];
foreach ($required_fields as $field) {
    if (empty($_POST[$field])) {
        header("Location: admin_dashboard.php?view=activities&error=" . urlencode("Missing field: $field"));
        exit;
    }
}

// Sanitize inputs
$activity_type = trim($_POST['activity_type']);
$property_no   = trim($_POST['property_no']);
$location      = trim($_POST['location']);
$activity_date = trim($_POST['activity_date']);
$activity_time = trim($_POST['activity_time']);
$remarks       = trim($_POST['remarks']);

// Insert into database
$stmt = $mysqli->prepare("INSERT INTO activities (activity_type, property_no, location, activity_date, activity_time, remarks) VALUES (?, ?, ?, ?, ?, ?)");
if (!$stmt) {
    header("Location: admin_dashboard.php?view=activities&error=" . urlencode("Prepare failed: " . $mysqli->error));
    exit;
}

$stmt->bind_param("ssssss", $activity_type, $property_no, $location, $activity_date, $activity_time, $remarks);

if ($stmt->execute()) {
    header("Location: admin_dashboard.php?view=activities&success=" . urlencode("Activity added successfully"));
    exit;
} else {
    header("Location: admin_dashboard.php?view=activities&error=" . urlencode("Execution failed: " . $stmt->error));
    exit;
}

$stmt->close();
$mysqli->close();
?>
