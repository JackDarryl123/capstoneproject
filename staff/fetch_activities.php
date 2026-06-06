<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/../includes/session_helper.php';
start_user_session();

require_once __DIR__ . '/../db_connect.php';

header('Content-Type: application/json');

try {
    // Get location filter from query parameter or session
    $location = $_GET['location'] ?? ($_SESSION['user_location'] ?? null);

    // Build query with location filter
    if ($location) {
        $stmt = $mysqli->prepare("SELECT * FROM activities WHERE location = ? ORDER BY activity_date ASC, activity_time ASC");
        if (!$stmt) throw new Exception("Database prepare failed");
        $stmt->bind_param("s", $location);
        $stmt->execute();
        $result = $stmt->get_result();  
    } else {
        // No location filter, get all activities
        $result = $mysqli->query("SELECT * FROM activities ORDER BY activity_date ASC, activity_time ASC");
        if (!$result) throw new Exception("Database query failed");
    }

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
            'title' => $row['activity_type'] . (isset($row['property_no']) ? ' - ' . $row['property_no'] : ''),
            'start' => $row['activity_date'] . 'T' . $row['activity_time'],
            'backgroundColor' => '#ffffff',
            'borderColor' => $borderColor,
            'textColor' => '#1f2937',
            'extendedProps' => [
                'activity_type' => $row['activity_type'],
                'property_no' => $row['property_no'],
                'location' => $row['location'],
                'activity_date' => $row['activity_date'],
                'activity_time' => $row['activity_time'],
                'remarks' => $row['remarks'],
                'dotColor' => $borderColor
            ]
        ];
    }

    echo json_encode($events);

    if (isset($stmt)) {
        $stmt->close();
    }
    $mysqli->close();
} catch (Exception $e) {
    error_log("[" . date('Y-m-d H:i:s') . "] Error in staff/fetch_activities.php: " . $e->getMessage());
    echo json_encode([]);
    $mysqli->close();
    exit();
}
?>