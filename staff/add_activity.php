<?php
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/../includes/session_helper.php';
start_user_session();

// Database connection
require_once __DIR__ . '/../db_connect.php';

if ( $mysqli->connect_errno ) {
    header( 'Location: ./staff_dashboard.php?view=activities&error=' . urlencode( 'Database connection failed' ) );
    exit;
}

// Validate POST inputs
$required_fields = [ 'activity_type', 'property_no', 'location', 'activity_date', 'activity_time', 'remarks' ];
foreach ( $required_fields as $field ) {
    if ( empty( $_POST[ $field ] ) ) {
        header( 'Location: ./staff_dashboard.php?view=activities&error=' . urlencode( "Missing field: $field" ) );
        exit;
    }
}

// Sanitize inputs
$activity_type = trim( $_POST[ 'activity_type' ] );
$property_no   = trim( $_POST[ 'property_no' ] );
$location      = trim( $_POST[ 'location' ] );
$activity_date = trim( $_POST[ 'activity_date' ] );
$activity_time = trim( $_POST[ 'activity_time' ] );
$remarks       = trim( $_POST[ 'remarks' ] );

// Insert into database
$stmt = $mysqli->prepare( 'INSERT INTO activities (activity_type, property_no, location, activity_date, activity_time, remarks) VALUES (?, ?, ?, ?, ?, ?)' );
if ( !$stmt ) {
    header( 'Location: ./staff_dashboard.php?view=activities&error=' . urlencode( 'Prepare failed: ' . $mysqli->error ) );
    exit;
}

$stmt->bind_param( 'ssssss', $activity_type, $property_no, $location, $activity_date, $activity_time, $remarks );

if ( $stmt->execute() ) {
    header( 'Location: ./staff_dashboard.php?view=activities&success=' . urlencode( 'Activity added successfully' ) );
    exit;
} else {
    header( 'Location: staff_dashboard.php?view=activities&error=' . urlencode( 'Execution failed: ' . $stmt->error ) );
    exit;
}

$stmt->close();
$mysqli->close();
?>