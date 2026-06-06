<?php
// Include the same session helper
require_once 'includes/session_helper.php';
require_once __DIR__ . '/config.php';
start_user_session();

// Set JSON header
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false, 
        'message' => 'Unauthorized access. Please login.',
        'session_debug' => [
            'user_id' => $_SESSION['user_id'] ?? 'not set',
            'user_role' => $_SESSION['user_role'] ?? 'not set',
            'session_id' => session_id()
        ]
    ]);
    exit();
}

// Get user role from session
$user_role = $_SESSION['role'] ?? ''; // Note: In process.php you set $_SESSION['role'], not $_SESSION['user_role']
$user_id = $_SESSION['user_id'] ?? 0;
$user_location = $_SESSION['location'] ?? null;

// Check if user has permission (admin or staff)
if (!in_array($user_role, ['admin', 'staff'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have permission to update appointment status.']);
    exit();
}

// Check if request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit();
}

// Get and validate input
$appointment_id = $_POST['appointment_id'] ?? null;
$new_status = $_POST['new_status'] ?? null;

if (!$appointment_id || !$new_status) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters.']);
    exit();
}

// Validate status
$allowed_statuses = ['approved', 'rejected'];
if (!in_array($new_status, $allowed_statuses)) {
    echo json_encode(['success' => false, 'message' => 'Invalid status value.']);
    exit();
}

if ($mysqli->connect_errno) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
    exit();
}

// Check if appointment exists and get current status
$stmt = $mysqli->prepare("SELECT * FROM appointment_requests WHERE id = ?");
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Database query failed.']);
    exit();
}

$stmt->bind_param("i", $appointment_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $stmt->close();
    $mysqli->close();
    echo json_encode(['success' => false, 'message' => 'Appointment not found.']);
    exit();
}

$appointment = $result->fetch_assoc();
$stmt->close();

// Check if user has permission to update this appointment
// Staff can only update appointments from their location
if ($user_role === 'staff' && $user_location) {
    if ($appointment['location'] !== $user_location) {
        $mysqli->close();
        echo json_encode(['success' => false, 'message' => 'You do not have permission to update appointments from this location.']);
        exit();
    }
}

// Check if appointment is pending (can only update pending appointments)
if ($appointment['status'] !== 'pending') {
    $mysqli->close();
    echo json_encode(['success' => false, 'message' => 'Only pending appointments can be updated.']);
    exit();
}

// Update appointment status
$update_stmt = $mysqli->prepare("UPDATE appointment_requests SET status = ?, updated_at = NOW() WHERE id = ?");
if (!$update_stmt) {
    $mysqli->close();
    echo json_encode(['success' => false, 'message' => 'Database update failed.']);
    exit();
}

$update_stmt->bind_param("si", $new_status, $appointment_id);

if ($update_stmt->execute()) {
    // Log the activity
    $activity_type = $new_status === 'approved' ? 'Appointment Approved' : 'Appointment Rejected';
    $log_stmt = $mysqli->prepare("INSERT INTO activity_log (user_id, activity_type, property_no, status, date_time, location) 
                                  SELECT ?, ?, property_no, ?, NOW(), location 
                                  FROM appointment_requests WHERE id = ?");
    if ($log_stmt) {
        $log_stmt->bind_param("issi", $user_id, $activity_type, $new_status, $appointment_id);
        $log_stmt->execute();
        $log_stmt->close();
    }
    
    echo json_encode(['success' => true, 'message' => 'Appointment status updated successfully.']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to update appointment status: ' . $mysqli->error]);
}

$update_stmt->close();
$mysqli->close();
?>
