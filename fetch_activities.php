<?php
header('Content-Type: application/json');

$mysqli = new mysqli('localhost', 'root', '', 'user_management');
if ($mysqli->connect_errno) {
    echo json_encode([]);
    exit;
}

// Fetch activities
$query = "SELECT * FROM activities ORDER BY activity_date ASC";
$result = $mysqli->query($query);

$events = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        // Decide event color based on activity type
        switch ($row['activity_type']) {
            case 'Inspection':
                $color = '#198754'; // green
                break;
            case 'Maintenance/Repair':
                $color = '#ffc107'; // yellow
                break;
            case 'Appointment':
                $color = '#0d6efd'; // blue
                break;
            default:
                $color = '#6c757d'; // gray
        }

        $events[] = [
            'id' => $row['id'],
            'title' => $row['activity_type'] . ' (' . $row['property_no'] . ')', // show type + property
            'start' => $row['activity_date'] . 'T' . $row['activity_time'],
            'color' => $color,
            'extendedProps' => [
                'property_no' => $row['property_no'],
                'location' => $row['location'],
                'time' => $row['activity_time'],
                'remarks' => $row['remarks'],
            ],
        ];
    }
}

echo json_encode($events);
$mysqli->close(); 
