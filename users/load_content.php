<?php
// load_content.php
ob_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/session_helper.php';
start_user_session();

// Redirect checks
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'user') {
    die("Unauthorized");
}

$userId = $_SESSION['user_id'];

// Fetch user details for context
$userStmt = $mysqli->prepare("SELECT username, email, status, role FROM users WHERE id = ?");
$userStmt->bind_param("i", $userId);
$userStmt->execute();
$currentUser = $userStmt->get_result()->fetch_assoc();
$userStmt->close();

// Determine which view to load
$allowedViews = ['dashboard', 'scan', 'request', 'appointment'];
$view = $_GET['view'] ?? 'dashboard';
if (!in_array($view, $allowedViews, true)) {
    $view = 'dashboard';
}

// Load the appropriate content
switch ($view) {
    case 'dashboard':
        // Fetch dashboard data
        $vehicle_counts = ['Mamburao' => 0, 'San Jose' => 0, 'Sablayan' => 0, 'Lubang' => 0];
        $pendingPct = 0; $ongoingPct = 0; $donePct = 0;
        $locations = [];
        $repairData = ['Under repair' => [], 'Operational' => [], 'Unserviceable' => []];

        // Vehicle Count
        $stmt = $mysqli->prepare("SELECT location, COUNT(*) AS total FROM equipment WHERE location IN ('Mamburao','San Jose','Sablayan','Lubang') GROUP BY location");
        if ($stmt) {
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $vehicle_counts[$row['location']] = (int)$row['total'];
            }
            $stmt->close();
        }

        // Document Counts
        function getDocCount($mysqli, $status) {
            $stmt = $mysqli->prepare("SELECT COUNT(*) as c FROM documents WHERE status=?");
            $stmt->bind_param("s", $status);
            $stmt->execute();
            $res = $stmt->get_result()->fetch_assoc();
            return (int)($res['c'] ?? 0);
        }

        $pending = getDocCount($mysqli, 'PENDING');
        $ongoing = getDocCount($mysqli, 'APPROVED');
        $done    = getDocCount($mysqli, 'DONE');
        $totalDocs = $pending + $ongoing + $done;
        
        if ($totalDocs > 0) {
            $pendingPct = round(($pending / $totalDocs) * 100);
            $ongoingPct = round(($ongoing / $totalDocs) * 100);
            $donePct    = round(($done / $totalDocs) * 100);
        }

        // Repair Inspection Data
        $stmt = $mysqli->prepare("SELECT DISTINCT location FROM equipment ORDER BY location");
        if ($stmt) {
            $stmt->execute();
            $resLoc = $stmt->get_result();
            while ($r = $resLoc->fetch_assoc()) {
                $locations[] = $r['location'];
            }
            $stmt->close();
        }

        foreach (['Under repair', 'Operational', 'Unserviceable'] as $status) {
            foreach ($locations as $loc) {
                $stmt = $mysqli->prepare("SELECT COUNT(*) as cnt FROM equipment WHERE location=? AND status=?");
                $stmt->bind_param("ss", $loc, $status);
                $stmt->execute();
                $repairData[$status][] = (int)$stmt->get_result()->fetch_assoc()['cnt'];
                $stmt->close();
            }
        }

        // Include dashboard content
        include 'side_dashboard.php';
        
        // Output script to initialize charts with real data
        echo '<script>';
        echo 'if (typeof initializeDocumentChart === "function") { initializeDocumentChart([' . $pendingPct . ', ' . $ongoingPct . ', ' . $donePct . ']); }';
        echo 'if (typeof initializeRepairChart === "function") { initializeRepairChart(' . json_encode([
            'locations' => $locations,
            'underRepair' => $repairData['Under repair'] ?? [],
            'operational' => $repairData['Operational'] ?? [],
            'unserviceable' => $repairData['Unserviceable'] ?? []
        ]) . '); }';
        echo '</script>';
        break;
        
    case 'scan':
        include 'side_scan.php';
        break;
        
    case 'request':
        include 'side_request.php';
        break;
        
    case 'appointment':
        include 'appointment.php';
        break;
}

$mysqli->close();
ob_end_flush();