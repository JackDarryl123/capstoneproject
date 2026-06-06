<?php
require_once 'includes/session_helper.php';
start_user_session();

$mysqli = new mysqli( 'localhost', 'root', '', 'user_management' );
if ( $mysqli->connect_errno ) {
    $response = ['success' => false, 'message' => 'Database connection failed'];
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode($response);
        exit();
    }
    die( 'Database connection failed: ' . $mysqli->connect_error );
}

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// Check if form is submitted
if ( $_SERVER[ 'REQUEST_METHOD' ] === 'POST' ) {
    // Get form data
    $document_id = $_POST[ 'document_id' ] ?? 0;
    $pre_repair_no = $_POST[ 'pre_repair_no' ] ?? '';
    $supply_location = $_POST[ 'supply_location' ] ?? '';
    $remarks = $_POST[ 'remarks' ] ?? '';

    // Get current user info from session
    $requested_by = $_SESSION[ 'username' ] ?? 'Admin';
    $admin_location = $_SESSION[ 'location' ] ?? 'mamburao';
    // Default if not set

    // ✅ CHECK FOR DUPLICATE REQUEST
    if ( !empty( $pre_repair_no ) ) {
        $check_stmt = $mysqli->prepare( "SELECT id FROM supply_requests WHERE pre_repair_no = ?" );
        $check_stmt->bind_param( 's', $pre_repair_no );
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ( $check_result->num_rows > 0 ) {
            $message = "A supply request has already been sent for Pre-Repair No: $pre_repair_no";
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $message]);
                exit();
            }
            $_SESSION[ 'error' ] = $message;
            header( 'Location: side_maintenance.php' );
            exit();
        }
        $check_stmt->close();
    }

    // Validate required fields
    if ( empty( $document_id ) || empty( $supply_location ) ) {
        $message = 'Please select a supply location';
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $message]);
            exit();
        }
        $_SESSION[ 'error' ] = $message;
        header( 'Location: view_document.php?id=' . $document_id );
        exit();
    }

    // Fetch document details for the request
    $stmt = $mysqli->prepare( "
        SELECT d.*, e.description 
        FROM documents d 
        LEFT JOIN equipment e ON d.property_no = e.property_no 
        WHERE d.id = ?
    " );
    $stmt->bind_param( 'i', $document_id );
    $stmt->execute();
    $result = $stmt->get_result();
    $document = $result->fetch_assoc();
    $stmt->close();

    if (!$document) {
        $message = 'Document not found';
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $message]);
            exit();
        }
        $_SESSION[ 'error' ] = $message;
        header( 'Location: view_document.php?id=' . $document_id );
        exit();
    }

    // Insert into supply_requests table
    $stmt = $mysqli->prepare( "
        INSERT INTO supply_requests (
            document_id, pre_repair_no, property_no, description, 
            supply_location, requested_by, admin_location, remarks, 
            status, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
    " );

    $stmt->bind_param(
        'isssssss',
        $document_id,
        $pre_repair_no,
        $document[ 'property_no' ],
        $document[ 'description' ],
        $supply_location,
        $requested_by,
        $admin_location,
        $remarks
    );

    if ( $stmt->execute() ) {
        $message = 'Request sent successfully to Supply Department (' . ucfirst( str_replace( '_', ' ', $supply_location ) ) . ')';
        
        // Optional: Send notification to supply users in the selected location
        sendSupplyNotification( $mysqli, $supply_location, $document_id, $requested_by );

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => $message]);
            exit();
        }
        
        $_SESSION[ 'success' ] = $message;

    } else {
        $message = 'Failed to send request: ' . $mysqli->error;
        
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $message]);
            exit();
        }
        
        $_SESSION[ 'error' ] = $message;
    }

    $stmt->close();

    // Redirect back to side_maintenance.php
    header( 'Location: side_maintenance.php' );
    exit();
} else {
    header( 'Location: admin_dashboard.php' );
    exit();
}

/**
* Send notification to supply department users in the specified location
*/

function sendSupplyNotification( $mysqli, $location, $document_id, $requester ) {
    // Find all users with role 'supply' in the selected location
    $stmt = $mysqli->prepare( "
        SELECT id, username, email 
        FROM users 
        WHERE role = 'supply' AND location = ?
    " );
    $stmt->bind_param( 's', $location );
    $stmt->execute();
    $result = $stmt->get_result();

    // You can implement email or in-app notifications here
    // For now, we'll just create notification records in a notifications table
    
    // Create notifications table if it doesn't exist
    $mysqli->query( "
        CREATE TABLE IF NOT EXISTS notifications (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            message TEXT NOT NULL,
            type VARCHAR(50) DEFAULT 'supply_request',
            link VARCHAR(255),
            is_read BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    " );

    $message = "New supply request from $requester for document #$document_id";
    $link = 'view_supply_request.php?id=' . $document_id;

    while ( $user = $result->fetch_assoc() ) {
        $notif_stmt = $mysqli->prepare( "
            INSERT INTO notifications (user_id, message, type, link) 
            VALUES (?, ?, 'supply_request', ?)
        " );
        $notif_stmt->bind_param( 'iss', $user[ 'id' ], $message, $link );
        $notif_stmt->execute();
        $notif_stmt->close();
    }

    $stmt->close();
}
?>