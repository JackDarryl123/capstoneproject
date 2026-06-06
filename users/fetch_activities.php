<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/../includes/session_helper.php';
start_user_session();

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

try {
    // Get Location Filter
    $locationFilter = $_GET['location'] ?? '';

    // Build Query
    $sql = "SELECT id, activity_type, location, activity_date, activity_time 
            FROM activities 
            WHERE 1=1";

    if (!empty($locationFilter)) {
        $sql .= " AND location = ?";
    }

    $stmt = $mysqli->prepare($sql);
    if (!$stmt) throw new Exception("Database prepare failed");

    if (!empty($locationFilter)) {
        $stmt->bind_param("s", $locationFilter);
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

        $startDateTime = $row['activity_date'] . 'T' . $row['activity_time'];

        $events[] = [
            'id' => $row['id'],
            'title' => $row['activity_type'] . ' - ' . $row['location'],
            'start' => $startDateTime,
            'backgroundColor' => '#ffffff',
            'borderColor' => $borderColor,
            'textColor' => '#1f2937',
            'extendedProps' => [
                'time_formatted' => date('h:i A', strtotime($row['activity_time'])),
                'dotColor' => $borderColor
            ]
        ];
    }

    echo json_encode($events);

    $stmt->close();
    $mysqli->close();
} catch (Exception $e) {
    error_log("[" . date('Y-m-d H:i:s') . "] Error in users/fetch_activities.php: " . $e->getMessage());
    echo json_encode([]);
    $mysqli->close();
    exit();
}
?>