<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/session_helper.php';
start_user_session();

header('Content-Type: application/json');


// 2. INITIALIZE RESPONSE
$response = ['success' => false, 'message' => 'An unexpected error occurred.'];

// 3. AUTHENTICATION CHECK
if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Session expired. Please login again.']);
    exit();
}

// 5. USER CONTEXT & LOCATION RECOVERY
$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? 'Unknown';
$user_location = $_SESSION['location'] ?? null;

// If location or username is missing from session, try to fetch it from DB
if (!$user_location || $username === 'Unknown') {
    if ($stmt = $mysqli->prepare("SELECT location, username FROM users WHERE id = ?")) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $user_location = $row['location'];
            $username = $row['username'];
            $_SESSION['location'] = $user_location;
            $_SESSION['username'] = $username;
        }
        $stmt->close();
    }
}

// 6. PROCESS FORM SUBMISSION
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Validate Required Fields
    $required_fields = ['activity_type', 'property_no', 'status'];
    $missing_fields = [];

    foreach ($required_fields as $field) {
        if (empty($_POST[$field])) {
            $missing_fields[] = $field;
        }
    }

    if (!empty($missing_fields)) {
        $response['message'] = "Missing required fields: " . implode(', ', $missing_fields);
    } else {
        // Prepare Data
        $activity_type = $_POST['activity_type'];
        $property_no = $_POST['property_no'];
        $status = $_POST['status'];
        $details = $_POST['details'] ?? ''; // Capture remarks
        $date_time = date('Y-m-d H:i:s');

        // Use posted location or fallback to user location
        $posted_location = !empty($_POST['location']) ? $_POST['location'] : $user_location;

        // Security: Ensure user is not posting to a different location (Allowing matching based on display location too)
        // This is a bit flexible to handle the 'maintenance_mamburao' vs 'mamburao' issue if any
        
        // Simple check for now
        $allowed = ($posted_location === $user_location || strpos($user_location, $posted_location) !== false || strpos($posted_location, $user_location) !== false);

        if (!$allowed && $_SESSION['role'] !== 'admin') {
            $response['message'] = "Permission denied: You cannot add logs for this location.";
        } else {
            // Insert into Database
            $query = "INSERT INTO activity_log (activity_type, property_no, status, performed_by, date_time, location, remarks) 
          VALUES (?, ?, ?, ?, ?, ?, ?)";

            if ($stmt = $mysqli->prepare($query)) {
                $stmt->bind_param("sssssss", $activity_type, $property_no, $status, $username, $date_time, $posted_location, $details);

                if ($stmt->execute()) {
                    $response['success'] = true;
                    $response['message'] = 'Activity Log added successfully!';
                } else {
                    $response['message'] = 'Database Error: ' . $stmt->error;
                }
                $stmt->close();
            } else {
                $response['message'] = 'Query Preparation Failed: ' . $mysqli->error;
            }
        }
    }
} else {
    $response['message'] = 'Invalid Request Method (POST required)';
}

// 7. FINAL OUTPUT
$mysqli->close();
echo json_encode($response);
exit();
?>