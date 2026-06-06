<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/includes/session_helper.php';
start_user_session();

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

try {
    $requestedLocation = $_GET['location'] ?? $_SESSION['user_location'] ?? null;
    $userRole = $_SESSION['role'] ?? 'user';

    $sql = "SELECT * FROM activities WHERE 1=1";
    $params = [];
    $types = "";

    // If a location is found, filter by it
    if ($requestedLocation && in_array($requestedLocation, ['Mamburao', 'Sablayan', 'San Jose', 'Lubang'])) {
        $sql .= " AND location = ?";
        $params[] = $requestedLocation;
        $types .= "s";
    } 
    // If NO location is found:
    // - If Admin/Superadmin/pgdh: Show ALL (do nothing, let query select all)
    // - If Standard User: Force empty result (Security)
    else if (!in_array($userRole, ['admin', 'superadmin', 'pgdh_pacco', 'pgdh_gso'])) {
        echo json_encode([]);
        $mysqli->close();
        exit();
    }

    $sql .= " ORDER BY activity_date ASC, activity_time ASC";
    $stmt = $mysqli->prepare($sql);
    
    if (!$stmt) {
        throw new Exception("Database prepare failed");
    }

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $events = [];
    while ($row = $result->fetch_assoc()) {
        $type = strtolower($row['activity_type']);
        if (strpos($type, 'inspection') !== false) {
            $borderColor = '#8b5cf6';
        } elseif (strpos($type, 'repair') !== false || strpos($type, 'maintenance') !== false) {
            $borderColor = '#f97316';
        } else {
            $borderColor = '#10b981';
        }
        
        $events[] = [
            'id' => $row['id'],
            'title' => $row['activity_type'],
            'start' => $row['activity_date'] . 'T' . $row['activity_time'],
            'backgroundColor' => '#ffffff',
            'borderColor' => $borderColor,
            'textColor' => '#1f2937',
            'extendedProps' => [
                'activity_type' => $row['activity_type'],
                'property_no' => $row['property_no'] ?? '',
                'location' => $row['location'],
                'activity_date' => $row['activity_date'],
                'activity_time' => $row['activity_time'],
                'remarks' => $row['remarks'] ?? '',
                'status' => $row['status'] ?? '',
                'dotColor' => $borderColor
            ]
        ];
    }

    echo json_encode($events);

    $stmt->close();
    $mysqli->close();
} catch (Exception $e) {
    error_log("[" . date('Y-m-d H:i:s') . "] Error in fetch_activities.php: " . $e->getMessage());
    echo json_encode([]);
    $mysqli->close();
    exit();
}
?>