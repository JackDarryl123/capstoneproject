<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/session_helper.php';
start_user_session();

if (session_status() === PHP_SESSION_NONE) session_start();

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', '0');

// [FIXED] For AJAX requests, return error message instead of redirecting
if (empty($_SESSION['user_id'])) {
    error_log("[" . date('Y-m-d H:i:s') . "] Session expired in load_content.php");
    echo '<div class="alert alert-danger">Session expired. Please refresh the page.</div>';
    exit();
}

// Check if user is staff
$userId = $_SESSION['user_id'];
$roleStmt = $mysqli->prepare("SELECT role FROM users WHERE id = ?");
$roleStmt->bind_param("i", $userId);
$roleStmt->execute();
$userData = $roleStmt->get_result()->fetch_assoc();
$roleStmt->close();

if (!$userData || $userData['role'] !== 'staff') {
    error_log("[" . date('Y-m-d H:i:s') . "] Access denied for user ID: " . ($userId ?? 'unknown'));
    echo '<div class="alert alert-danger">Access denied. Staff only.</div>';
    exit();
}

// Allowed views
$allowedViews = ['dashboard', 'equipment', 'scan', 'inventory', 'activities', 'report'];
$view = $_GET['view'] ?? 'dashboard';
if (!in_array($view, $allowedViews, true)) {
    $view = '404';
}

// Base directory for staff view files
$staffDir = __DIR__; // C:\xampp\htdocs\PEPO\staff

// Build the view file path
$viewFile = $staffDir . "/side_$view.php";

// Check if file exists
if (!file_exists($viewFile)) {
    // Try parent directory for some views (like equipment)
    $parentDir = dirname($staffDir);
    $viewFile = $parentDir . "/side_$view.php";
    
    // For scan view, check if it's in users directory
    if ($view === 'scan' && !file_exists($viewFile)) {
        $viewFile = $parentDir . "/users/side_scan.php";
    }
}

// Check if file exists and include it
if (file_exists($viewFile)) {
    try {
        // Dashboard requires special queries - ONLY run if view is dashboard
        if ($view === 'dashboard') {
            // Vehicle counts
            $vehicle_counts = ['Mamburao' => 0, 'San Jose' => 0, 'Sablayan' => 0, 'Lubang'   => 0];
            
            $stmt = $mysqli->prepare("SELECT location, COUNT(*) AS total FROM equipment WHERE location IN ('Mamburao','San Jose','Sablayan','Lubang') GROUP BY location");
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $vehicle_counts[$row['location']] = (int)$row['total'];
            }
            $stmt->close();
            
            // Document counts
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
            $percent = fn($val) => $totalDocs > 0 ? round(($val / $totalDocs) * 100) : 0;
            
            $pendingPct = $percent($pending);
            $ongoingPct = $percent($ongoing);
            $donePct    = $percent($done);
            
            // Pre Repair Inspection Data
            $locations = [];
            $resLoc = $mysqli->query("SELECT DISTINCT location FROM equipment ORDER BY location");
            while ($r = $resLoc->fetch_assoc()) { $locations[] = $r['location']; }
            
            $statuses = ['Under repair', 'Operational', 'Unserviceable'];
            $repairData = [];
            foreach ($statuses as $status) {
                $repairData[$status] = [];
                foreach ($locations as $loc) {
                    $stmt = $mysqli->prepare("SELECT COUNT(*) as cnt FROM equipment WHERE location=? AND status=?");
                    $stmt->bind_param("ss", $loc, $status);
                    $stmt->execute();
                    $repairData[$status][] = (int)$stmt->get_result()->fetch_assoc()['cnt'];
                }
            }
            
            // Summary Status Report
            $stats_query = "SELECT ec.category_name as equipment_name, COUNT(*) as units, SUM(CASE WHEN e.status = 'Operational' THEN 1 ELSE 0 END) as operational, SUM(CASE WHEN e.status = 'Under repair' THEN 1 ELSE 0 END) as under_repair, SUM(CASE WHEN e.status = 'Unserviceable' THEN 1 ELSE 0 END) as unserviceable FROM equipment e LEFT JOIN equipment_category ec ON e.category_id = ec.id GROUP BY ec.id, ec.category_name ORDER BY ec.category_name";
            $stats_result = $mysqli->query($stats_query);
            
            $loc_stats_query = "SELECT e.location as location_name, COUNT(*) as units, SUM(CASE WHEN e.status = 'Operational' THEN 1 ELSE 0 END) as operational, SUM(CASE WHEN e.status = 'Under repair' THEN 1 ELSE 0 END) as under_repair, SUM(CASE WHEN e.status = 'Unserviceable' THEN 1 ELSE 0 END) as unserviceable FROM equipment e GROUP BY e.location ORDER BY e.location";
            $loc_stats_result = $mysqli->query($loc_stats_query);

            // Get user location for inventory queries
            $userLocStmt = $mysqli->prepare("SELECT location FROM users WHERE id = ?");
            $userLocStmt->bind_param("i", $userId);
            $userLocStmt->execute();
            $userLocResult = $userLocStmt->get_result()->fetch_assoc();
            $userLocation = $userLocResult['location'] ?? 'Mamburao';
            $userLocStmt->close();

            // Inventory Stats
            $invStatsQuery = $mysqli->prepare('
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN (log_stats LIKE "BORROWED%" OR log_stats = "ISSUED") THEN 1 ELSE 0 END) as borrowed,
                    SUM(CASE WHEN log_stats = "AVAILABLE" OR log_stats IS NULL OR log_stats = "" THEN 1 ELSE 0 END) as available,
                    SUM(CASE WHEN status = "LOW" THEN 1 ELSE 0 END) as low_stock,
                    SUM(CASE WHEN status = "WORN_OUT" THEN 1 ELSE 0 END) as worn_out,
                    SUM(CASE WHEN status = "OUT_OF_STOCK" THEN 1 ELSE 0 END) as out_of_stock
                FROM inventory 
                WHERE allocation = ?
            ');
            $invStatsQuery->bind_param('s', $userLocation);
            $invStatsQuery->execute();
            $invStatsResult = $invStatsQuery->get_result()->fetch_assoc();
            $invStats = [
                'total' => $invStatsResult['total'] ?? 0,
                'borrowed' => $invStatsResult['borrowed'] ?? 0,
                'available' => $invStatsResult['available'] ?? 0,
                'low_stock' => $invStatsResult['low_stock'] ?? 0,
                'worn_out' => $invStatsResult['worn_out'] ?? 0,
                'out_of_stock' => $invStatsResult['out_of_stock'] ?? 0
            ];
            $invStatsQuery->close();

            // Inventory Categories
            $invCategoryQuery = $mysqli->prepare('
                SELECT category, COUNT(*) as count,
                SUM(CASE WHEN (log_stats LIKE "BORROWED%" OR log_stats = "ISSUED") THEN 1 ELSE 0 END) as borrowed
                FROM inventory 
                WHERE allocation = ? AND category IS NOT NULL AND category != ""
                GROUP BY category
                ORDER BY count DESC
            ');
            $invCategoryQuery->bind_param('s', $userLocation);
            $invCategoryQuery->execute();
            $invCategoryResult = $invCategoryQuery->get_result();
            $invCategories = [];
            while ($row = $invCategoryResult->fetch_assoc()) {
                $invCategories[] = $row;
            }
            $invCategoryQuery->close();

            // Inventory Activity (last 7 days)
            $activityQuery = $mysqli->prepare('
                SELECT action_type, COUNT(*) as count, DATE(date_time) as activity_date
                FROM inventory_activity_log 
                WHERE location = ? AND date_time >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                GROUP BY DATE(date_time), action_type
                ORDER BY activity_date ASC
            ');
            $activityQuery->bind_param('s', $userLocation);
            $activityQuery->execute();
            $activityResult = $activityQuery->get_result();
            $activityData = ['dates' => [], 'issued' => [], 'returned' => [], 'added' => []];
            $tempActivity = [];
            while ($row = $activityResult->fetch_assoc()) {
                $date = $row['activity_date'];
                if (!in_array($date, $tempActivity)) {
                    $tempActivity[] = $date;
                }
                if (!isset($activityData['dates']) || !in_array($date, $activityData['dates'])) {
                    $activityData['dates'][] = $date;
                }
                $action = strtoupper($row['action_type']);
                if ($action === 'ISSUED') {
                    $activityData['issued'][$date] = $row['count'];
                } elseif ($action === 'RETURNED') {
                    $activityData['returned'][$date] = $row['count'];
                } elseif ($action === 'ADDED') {
                    $activityData['added'][$date] = $row['count'];
                }
            }
            $activityQuery->close();

            $allDates = [];
            for ($i = 6; $i >= 0; $i--) {
                $allDates[] = date('Y-m-d', strtotime("-{$i} days"));
            }
            $activityData['dates'] = $allDates;
            foreach ($allDates as $date) {
                if (!isset($activityData['issued'][$date])) $activityData['issued'][$date] = 0;
                if (!isset($activityData['returned'][$date])) $activityData['returned'][$date] = 0;
                if (!isset($activityData['added'][$date])) $activityData['added'][$date] = 0;
            }
            $activityData['issued'] = array_values($activityData['issued']);
            $activityData['returned'] = array_values($activityData['returned']);
            $activityData['added'] = array_values($activityData['added']);

            // Recent Inventory Logs
            $recentLogsQuery = $mysqli->prepare('
                SELECT id, action_type, item_name, performed_by, date_time 
                FROM inventory_activity_log 
                WHERE location = ?
                ORDER BY date_time DESC
                LIMIT 5
            ');
            $recentLogsQuery->bind_param('s', $userLocation);
            $recentLogsQuery->execute();
            $recentLogsResult = $recentLogsQuery->get_result();
            $recentInventoryLogs = [];
            while ($row = $recentLogsResult->fetch_assoc()) {
                $recentInventoryLogs[] = $row;
            }
            $recentLogsQuery->close();
        }
        
        // Include the requested view file
        include $viewFile;
    } catch (Exception $e) {
        error_log("[" . date('Y-m-d H:i:s') . "] Load Content Error: " . $e->getMessage());
        echo '<div class="alert alert-danger">An error occurred while loading content. Please try again.</div>';
    }
} else {
    echo '<h2 class="fw-bold mb-4">Page Not Found</h2>';
    echo '<p>The view you requested does not exist.</p>';
    echo '<p>Looking for: ' . htmlspecialchars($view) . '</p>';
    echo '<p>Tried to find: ' . htmlspecialchars($viewFile) . '</p>';
}