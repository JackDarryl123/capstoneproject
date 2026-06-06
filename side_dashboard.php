<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/config.php';

// Redirect if not logged in or not admin
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    error_log("[" . date('Y-m-d H:i:s') . "] Unauthorized access attempt to side_dashboard.php");
    header('Location: index.php?login');
    exit();
}

// Include profile_modal.php after $mysqli is defined
include_once 'profile_modal.php';

// Simple caching function
function getCachedData($key, $callback, $ttl = 300)
{
    $cacheDir = __DIR__ . '/cache/';
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }
    $cacheFile = $cacheDir . md5($key) . '.cache';

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
        return unserialize(file_get_contents($cacheFile));
    }

    $data = $callback();
    file_put_contents($cacheFile, serialize($data));
    return $data;
}

// Get user data
$userId = $_SESSION['user_id'];
$userQuery = $mysqli->prepare('
    SELECT username, email, status, role, signature, is_admin, location
    FROM users WHERE id = ?
');
$userQuery->bind_param('i', $userId);
$userQuery->execute();
$userResult = $userQuery->get_result();
$currentUser = $userResult->fetch_assoc();
$userQuery->close();

// Get user's location
$userLocation = $currentUser['location'] ?? 'Mamburao';
$isSuperAdmin = isset($currentUser['is_admin']) && $currentUser['is_admin'] == 1;




// Fetch Equipment Statistics based on User Location
$equipStatsQuery = $mysqli->prepare('
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = "Operational" THEN 1 ELSE 0 END) as operational,
        SUM(CASE WHEN status = "Under repair" THEN 1 ELSE 0 END) as repair,
        SUM(CASE WHEN status = "Unserviceable" THEN 1 ELSE 0 END) as unserviceable
    FROM equipment 
    WHERE location = ?
');
$equipStatsQuery->bind_param('s', $userLocation);
$equipStatsQuery->execute();
$equipStatsResult = $equipStatsQuery->get_result();
$equipStats = $equipStatsResult->fetch_assoc();
$equipStatsQuery->close();

// Set default values if null (in case of no records)
$totalEquip = $equipStats['total'] ?? 0;
$operationalCount = $equipStats['operational'] ?? 0;
$repairCount = $equipStats['repair'] ?? 0;
$unserviceableCount = $equipStats['unserviceable'] ?? 0;



// Fetch Equipment Distribution by Location
$locDistQuery = $mysqli->prepare('
    SELECT 
        location,
        COUNT(*) as total,
        SUM(CASE WHEN status = "Operational" THEN 1 ELSE 0 END) as operational,
        SUM(CASE WHEN status = "Under repair" THEN 1 ELSE 0 END) as repair,
        SUM(CASE WHEN status = "Unserviceable" THEN 1 ELSE 0 END) as unserviceable
    FROM equipment 
    GROUP BY location
    ORDER BY total DESC
');
$locDistQuery->execute();
$locDistResult = $locDistQuery->get_result();

$locationStats = [];
$totalFleet = 0;
$maxTotal = 0;

while ($row = $locDistResult->fetch_assoc()) {
    $locationStats[$row['location']] = $row;
    $totalFleet += $row['total'];

    // Find the highest equipment count for the bar chart width
    if ($row['total'] > $maxTotal) {
        $maxTotal = $row['total'];
    }
}
$locDistQuery->close();

// Define fixed locations to ensure all appear even if empty
$allLocations = ['Mamburao', 'Sablayan', 'San Jose', 'Lubang'];





// --- CALENDAR DATA ---

// Fetch Activities for Calendar & List
// CORRECTION: Removed 'status' from the SELECT list because it doesn't exist in the table
$actQuery = $mysqli->prepare('
    SELECT id, activity_type, activity_date, activity_time, property_no, remarks
    FROM activities 
    WHERE location = ? 
    ORDER BY activity_date ASC, activity_time ASC
');

// Check if prepare failed (Good practice for debugging)
if ($actQuery === false) {
    die('Prepare failed: ' . $mysqli->error);
}

$actQuery->bind_param('s', $userLocation);
$actQuery->execute();
$actResult = $actQuery->get_result();

$calendarEvents = [];
$upcomingEvents = [];
$today = date('Y-m-d');
$nextWeek = date('Y-m-d', strtotime('+7 days'));

// ... (Your existing query setup remains the same) ...

while ($row = $actResult->fetch_assoc()) {
    // 1. Prepare data for the Interactive Calendar (JSON)
    $startDateTime = $row['activity_date'] . 'T' . $row['activity_time'];
    $type = $row['activity_type'];

    // Default: Appointment (Blue)
    $color = '#3b82f6';       // Blue-500
    $textColor = '#eff6ff';   // Blue-50
    $icon = 'fa-handshake';   // Handshake icon
    $borderColor = '#2563eb'; // Blue-600

    // Inspection (Green)
    if (stripos($type, 'Inspection') !== false) {
        $color = '#10b981';       // Emerald-500
        $textColor = '#ecfdf5';   // Emerald-50
        $icon = 'fa-clipboard-check';
        $borderColor = '#059669'; // Emerald-600
    }
    // Maintenance/Repair (Amber)
    elseif (stripos($type, 'Maintenance') !== false || stripos($type, 'Repair') !== false) {
        $color = '#f59e0b';       // Amber-500
        $textColor = '#fffbeb';   // Amber-50
        $icon = 'fa-tools';
        $borderColor = '#d97706'; // Amber-600
    }

    $calendarEvents[] = [
        'id' => $row['id'],
        'title' => $type, // simplified title
        'start' => $startDateTime,
        'backgroundColor' => $color,
        'borderColor' => $borderColor,
        'textColor' => $textColor,
        'extendedProps' => [
            'property_no' => $row['property_no'],
            'remarks' => $row['remarks'],
            'icon' => $icon,
            'type' => $type,
            'time' => date('h:i A', strtotime($row['activity_time']))
        ]
    ];

    // 2. Prepare data for the "Upcoming Schedules" list
    if ($row['activity_date'] >= $today && $row['activity_date'] <= $nextWeek) {
        // Add the calculated styles to the row data for the list view
        $row['ui_color'] = $color; // Hex color
        $row['ui_icon'] = $icon;

        // Add Tailwind classes based on type for the Sidebar List
        if (stripos($type, 'Inspection') !== false) {
            $row['tw_bg'] = 'bg-emerald-50';
            $row['tw_text'] = 'text-emerald-700';
            $row['tw_border'] = 'border-emerald-100 group-hover:border-emerald-200';
        } elseif (stripos($type, 'Maintenance') !== false || stripos($type, 'Repair') !== false) {
            $row['tw_bg'] = 'bg-amber-50';
            $row['tw_text'] = 'text-amber-700';
            $row['tw_border'] = 'border-amber-100 group-hover:border-amber-200';
        } else {
            $row['tw_bg'] = 'bg-blue-50';
            $row['tw_text'] = 'text-blue-700';
            $row['tw_border'] = 'border-blue-100 group-hover:border-blue-200';
        }

        $upcomingEvents[] = $row;
    }
}
$actQuery->close();
// ... (json_encode remains the same) ...



// Convert to JSON for JavaScript
$calendarEventsJSON = json_encode($calendarEvents);


// Fetch Equipment Stats Grouped by Category (For Pre-Repair Tab)
$categoryStatsQuery = $mysqli->prepare('
    SELECT 
        ec.category_name as equipment_name,
        COUNT(e.id) as units,
        SUM(CASE WHEN e.status = "Operational" THEN 1 ELSE 0 END) as operational,
        SUM(CASE WHEN e.status = "Under repair" THEN 1 ELSE 0 END) as under_repair,
        SUM(CASE WHEN e.status = "Unserviceable" THEN 1 ELSE 0 END) as unserviceable
    FROM equipment e
    JOIN equipment_category ec ON e.category_id = ec.id
    WHERE e.location = ?
    GROUP BY ec.category_name
    ORDER BY units DESC
');
$categoryStatsQuery->bind_param('s', $userLocation);
$categoryStatsQuery->execute();
$stats_result = $categoryStatsQuery->get_result(); // We will use this in the HTML loop
// Note: We do not close the query here if we want to loop through it later, 
// or we fetch all into an array now. Let's fetch to array to be safe.
$categoryStats = [];
while ($row = $stats_result->fetch_assoc()) {
    $categoryStats[] = $row;
}
$categoryStatsQuery->close();

// Fetch Equipment Stats Grouped by Location (For Summary Tab)
$locationSummaryQuery = $mysqli->prepare('
    SELECT 
        location as location_name,
        COUNT(*) as units,
        SUM(CASE WHEN status = "Operational" THEN 1 ELSE 0 END) as operational,
        SUM(CASE WHEN status = "Under repair" THEN 1 ELSE 0 END) as under_repair,
        SUM(CASE WHEN status = "Unserviceable" THEN 1 ELSE 0 END) as unserviceable
    FROM equipment
    GROUP BY location
    ORDER BY units DESC
');
$locationSummaryQuery->execute();
$loc_stats_result = $locationSummaryQuery->get_result();
$locationStatsArray = [];
while ($row = $loc_stats_result->fetch_assoc()) {
    $locationStatsArray[] = $row;
}
$locationSummaryQuery->close();

// Fetch Repair Statistics by Location (for the new Pre-Repair Chart)
$repairChartQuery = $mysqli->prepare('
    SELECT 
        location,
        SUM(CASE WHEN status = "Under repair" THEN 1 ELSE 0 END) as repair,
        SUM(CASE WHEN status = "Operational" THEN 1 ELSE 0 END) as operational,
        SUM(CASE WHEN status = "Unserviceable" THEN 1 ELSE 0 END) as unserviceable
    FROM equipment 
    GROUP BY location
');
$repairChartQuery->execute();
$repairChartResult = $repairChartQuery->get_result();

$repairChartData = [
    'locations' => [],
    'repair' => [],
    'operational' => [],
    'unserviceable' => []
];

$tempStats = [];
while ($row = $repairChartResult->fetch_assoc()) {
    $tempStats[$row['location']] = $row;
}
$repairChartQuery->close();

$repairChartData = [
    'locations' => [],
    'repair' => [],
    'operational' => [],
    'unserviceable' => []
];

foreach ($allLocations as $loc) {
    $data = $tempStats[$loc] ?? ['repair' => 0, 'operational' => 0, 'unserviceable' => 0];
    $repairChartData['locations'][] = $loc;
    $repairChartData['repair'][] = (int) ($data['repair'] ?? 0);
    $repairChartData['operational'][] = (int) ($data['operational'] ?? 0);
    $repairChartData['unserviceable'][] = (int) ($data['unserviceable'] ?? 0);
}


// --- END OF ADDED BLOCK ---



// Get document counts for the bar chart - OPTIMIZED VERSION
$docCountQuery = $mysqli->prepare('
    SELECT 
        status,
        COUNT(*) as count
    FROM documents 
    WHERE location = ?
    AND status IN ("Pending", "Approved", "Done", "Complete")
    GROUP BY status
');
$docCountQuery->bind_param('s', $userLocation);
$docCountQuery->execute();
$docCountResult = $docCountQuery->get_result();

$documentStats = [
    'Pending' => 0,
    'Approved' => 0,
    'Done' => 0,
    'Complete' => 0
];

$totalDocs = 0;
while ($row = $docCountResult->fetch_assoc()) {
    $documentStats[$row['status']] = (int) $row['count'];
    $totalDocs += $row['count'];
}
$docCountQuery->close();

// Store user location in session
$_SESSION['user_location'] = $userLocation;

// =======================
// INVENTORY ANALYTICS
// =======================

// Get inventory stats by location
$invStatsQuery = $mysqli->prepare('
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN (log_stats LIKE "BORROWED%" OR log_stats = "ISSUED") THEN 1 ELSE 0 END) as borrowed,
        SUM(CASE WHEN log_stats = "AVAILABLE" OR log_stats IS NULL OR log_stats = "" THEN 1 ELSE 0 END) as available,
        SUM(CASE WHEN status = "LOW" THEN 1 ELSE 0 END) as low_stock,
        SUM(CASE WHEN status = "WORN_OUT" OR status = "WORN OUT" THEN 1 ELSE 0 END) as worn_out,
        SUM(CASE WHEN status = "OUT_OF_STOCK" OR status = "OUT OF STOCK" THEN 1 ELSE 0 END) as out_of_stock
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

// Get inventory by category
$invCategoryQuery = $mysqli->prepare('
    SELECT 
        category,
        COUNT(*) as count,
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

// Get inventory activity (last 7 days)
$activityQuery = $mysqli->prepare('
    SELECT 
        action_type,
        COUNT(*) as count,
        DATE(date_time) as activity_date
    FROM inventory_activity_log 
    WHERE location = ? AND date_time >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    GROUP BY DATE(date_time), action_type
    ORDER BY activity_date ASC
');
$activityQuery->bind_param('s', $userLocation);
$activityQuery->execute();
$activityResult = $activityQuery->get_result();
$activityData = [
    'dates' => [],
    'issued' => [],
    'returned' => [],
    'added' => []
];
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

// Fill missing dates with 0
$allDates = [];
for ($i = 6; $i >= 0; $i--) {
    $allDates[] = date('Y-m-d', strtotime("-{$i} days"));
}
$activityData['dates'] = $allDates;
foreach ($allDates as $date) {
    if (!isset($activityData['issued'][$date]))
        $activityData['issued'][$date] = 0;
    if (!isset($activityData['returned'][$date]))
        $activityData['returned'][$date] = 0;
    if (!isset($activityData['added'][$date]))
        $activityData['added'][$date] = 0;
}
$activityData['issued'] = array_values($activityData['issued']);
$activityData['returned'] = array_values($activityData['returned']);
$activityData['added'] = array_values($activityData['added']);

// Get recent activity logs
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

// Fetch dashboard data only if needed
$view = $_GET['view'] ?? 'dashboard';
if ($view === 'dashboard') {
    // ... (rest of your dashboard data fetching remains the same)
}

// Store session messages
$sessionMessage = '';
$sessionMsgType = '';
if (isset($_SESSION['message'])) {
    $sessionMessage = $_SESSION['message'];
    $sessionMsgType = $_SESSION['msg_type'] ?? 'info';
    unset($_SESSION['message'], $_SESSION['msg_type']);
}



?>

<!-- Add preconnect for CDN resources -->
<link rel="preconnect" href="https://cdn.jsdelivr.net">
<link rel="preconnect" href="https://cdnjs.cloudflare.com">

<!-- Inline critical CSS -->
<style>
    /* :root {
        --sidebar-width: 260px;
        --header-height: 70px;
    } 

    body {
        background-color: #f3f4f6;
        font-family: 'Inter', sans-serif;
    } */

    /* Add this right at the top of your style block */
    :root {
        --bs-zindex: 1160;
        --bs-backdrop-zindex: 1150;
    }

    .modal-backdrop {
        z-index: var(--bs-backdrop-zindex) !important;
    }

    /* Chart Styles */
    .chart-container {
        position: relative;
        height: 300px;
        width: 100%;
    }

    .chart-loading {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(255, 255, 255, 0.9);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 10;
    }

    .chart-type-buttons {
        display: flex;
        gap: 8px;
        margin-bottom: 16px;
    }

    .chart-btn {
        padding: 8px 16px;
        border: 1px solid #ddd;
        background: white;
        border-radius: 6px;
        cursor: pointer;
        font-size: 14px;
        transition: all 0.2s;
    }

    .chart-btn.active {
        background: #10b981;
        color: white;
        border-color: #10b981;
    }

    .chart-btn:hover:not(.active) {
        background: #f9fafb;
    }

    /* Status colors */
    .status-pending {
        color: #f59e0b;
    }

    .status-approved {
        color: #10b981;
    }

    .status-done {
        color: #3b82f6;
    }

    .status-complete {
        color: #8b5cf6;
    }



    /* FullCalendar Modern Theme Styles */
    .fc-toolbar-title {
        font-size: 1.1rem !important;
        font-weight: 800;
        color: #111827;
        letter-spacing: -0.025em;
        text-transform: uppercase;
    }

    .fc-button-primary {
        background-color: #ffffff !important;
        border: 1px solid #e5e7eb !important;
        color: #374151 !important;
        font-weight: 600 !important;
        text-transform: capitalize !important;
        padding: 0.5rem 1rem !important;
        border-radius: 10px !important;
        box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05) !important;
        transition: all 0.2s ease !important;
    }

    .fc-button-primary:hover {
        background-color: #f9fafb !important;
        border-color: #d1d5db !important;
        color: #111827 !important;
        transform: translateY(-1px);
    }

    .fc-button-active {
        background-color: #111827 !important;
        border-color: #111827 !important;
        color: #ffffff !important;
    }

    .fc-daygrid-day-number {
        padding: 8px !important;
        color: #6b7280;
        font-weight: 600;
        font-size: 0.85rem;
        text-decoration: none !important;
        transition: all 0.2s;
    }

    .fc-day-today .fc-daygrid-day-number {
        background-color: #10b981 !important;
        color: white !important;
        border-radius: 8px;
        width: 28px;
        height: 28px;
        display: flex;
        justify-content: center;
        align-items: center;
        margin: 4px;
    }

    .fc-col-header-cell-cushion {
        color: #9ca3af;
        font-weight: 700;
        text-transform: uppercase;
        font-size: 0.7rem;
        letter-spacing: 0.1em;
        text-decoration: none !important;
    }

    .fc-event {
        background-color: transparent !important;
        border: none !important;
        margin: 1px 2px !important;
        padding: 1px 4px !important;
        transition: all 0.2s ease;
        cursor: pointer;
        overflow: hidden !important;
    }

    .fc-event:hover {
        background-color: rgba(0, 0, 0, 0.03) !important;
        border-radius: 4px;
    }

    .fc-event-title {
        color: #111827 !important;
        font-weight: 600 !important;
        font-size: 0.7rem !important;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .fc-daygrid-event-harness {
        margin-bottom: 1px !important;
    }

    .fc-daygrid-day-frame {
        min-height: 100px !important;
    }

    .fc-daygrid-more-link {
        color: #10b981 !important;
        font-weight: 700 !important;
        font-size: 0.7rem !important;
        text-decoration: none !important;
        padding-left: 8px !important;
    }

    /* Glassmorphism Popover */
    .fc-popover {
        border-radius: 16px !important;
        border: 1px solid rgba(229, 231, 235, 0.5) !important;
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04) !important;
        background: rgba(255, 255, 255, 0.95) !important;
        backdrop-filter: blur(8px);
        z-index: 1500 !important;
        overflow: hidden;
    }

    .fc-popover-header {
        background: #f9fafb !important;
        padding: 12px !important;
        font-weight: 700 !important;
    }

    .custom-scrollbar::-webkit-scrollbar {
        width: 4px;
    }

    .custom-scrollbar::-webkit-scrollbar-track {
        background: #f1f1f1;
    }

    .custom-scrollbar::-webkit-scrollbar-thumb {
        background: #d1d5db;
        border-radius: 4px;
    }

    /* Chart Animations */
    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(20px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .chart-loaded {
        animation: fadeInUp 0.5s ease-out;
    }

    .chart-container {
        transition: all 0.3s ease;
    }

    .chart-container:hover {
        box-shadow: 0 10px 40px -10px rgba(0, 0, 0, 0.15);
    }

    /* Interactive stat cards */
    .stat-card-interactive {
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .stat-card-interactive:hover {
        transform: translateY(-4px) scale(1.02);
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
    }

    /* Total card pulse animation */
    @keyframes pulse-glow {

        0%,
        100% {
            box-shadow: 0 0 20px rgba(16, 185, 129, 0.3);
        }

        50% {
            box-shadow: 0 0 40px rgba(16, 185, 129, 0.6);
        }
    }

    .total-card-glow {
        animation: pulse-glow 3s ease-in-out infinite;
    }

    /* Inventory Section Styles */
    .inv-stat-card {
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .inv-stat-card:hover {
        transform: translateY(-4px) scale(1.02);
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
    }

    .inv-card-gradient {
        background: linear-gradient(135deg, rgba(255, 255, 255, 1) 0%, rgba(249, 250, 251, 1) 100%);
    }
</style>

<!-- ✅ View Activity Modal (View-Only Version) -->
<div class="modal fade" id="viewActivityModalSide" tabindex="-1" aria-labelledby="viewActivityLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-2xl rounded-3xl overflow-hidden">
            <!-- Modal Header -->
            <div class="relative p-6 bg-white border-b border-gray-100 flex justify-between items-center">
                <div class="flex items-center gap-4">
                    <div
                        class="h-12 w-12 rounded-2xl bg-emerald-50 flex items-center justify-center text-emerald-600 shadow-sm">
                        <i class="fas fa-calendar-check text-xl"></i>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <h5 class="text-xl font-extrabold text-gray-900 tracking-tight" id="viewActivityLabel">
                                Schedule Details</h5>
                            <span id="modal_id_badge"
                                class="text-[9px] bg-emerald-100 text-emerald-700 px-1.5 py-0.5 rounded-md font-bold border border-emerald-200">#ID</span>
                        </div>
                        <div class="flex items-center gap-2 mt-0.5">
                            <p class="text-xs text-gray-500 font-medium uppercase tracking-widest">Activity Information
                            </p>
                            <span class="h-1 w-1 rounded-full bg-gray-300"></span>
                            <span id="modal_property_tag"
                                class="text-[10px] bg-black text-white px-2 py-0.5 rounded-md font-bold tracking-wider uppercase">Loading...</span>
                        </div>
                    </div>
                </div>
                <button type="button"
                    class="h-10 w-10 flex items-center justify-center rounded-full hover:bg-gray-100 transition-colors text-gray-400 hover:text-gray-600"
                    data-bs-dismiss="modal" aria-label="Close">
                    <i class="fas fa-times text-lg"></i>
                </button>
            </div>

            <div class="modal-body p-8 space-y-6 bg-gray-50/30">
                <!-- Activity Type -->
                <div class="group">
                    <label class="block text-xs font-bold text-gray-400 uppercase tracking-widest mb-2 px-1">Activity
                        Classification</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-gray-400">
                            <i class="fas fa-tag"></i>
                        </div>
                        <input type="text" id="activity_type_side"
                            class="form-control pl-11 h-12 bg-white border-transparent rounded-2xl font-semibold text-gray-800 shadow-sm cursor-default"
                            readonly>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Property No -->
                    <div class="group">
                        <label
                            class="block text-xs font-bold text-gray-400 uppercase tracking-widest mb-2 px-1">Property
                            No.</label>
                        <div class="relative">
                            <div
                                class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-gray-400">
                                <i class="fas fa-barcode"></i>
                            </div>
                            <input type="text" id="property_no_display_side"
                                class="form-control pl-11 h-12 bg-white border-transparent rounded-2xl font-semibold text-gray-800 shadow-sm cursor-default"
                                readonly>
                        </div>
                    </div>

                    <!-- Location -->
                    <div class="group">
                        <label
                            class="block text-xs font-bold text-gray-400 uppercase tracking-widest mb-2 px-1">Location</label>
                        <div class="relative">
                            <div
                                class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-gray-400">
                                <i class="fas fa-map-marker-alt"></i>
                            </div>
                            <input type="text" id="location_display_side"
                                class="form-control pl-11 h-12 bg-white border-transparent rounded-2xl font-semibold text-gray-800 shadow-sm cursor-default"
                                readonly>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Date -->
                    <div class="group">
                        <label
                            class="block text-xs font-bold text-gray-400 uppercase tracking-widest mb-2 px-1">Execution
                            Date</label>
                        <div class="relative">
                            <div
                                class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-gray-400">
                                <i class="fas fa-calendar-day"></i>
                            </div>
                            <input type="text" id="activity_date_side"
                                class="form-control pl-11 h-12 bg-white border-transparent rounded-2xl font-semibold text-gray-800 shadow-sm cursor-default"
                                readonly>
                        </div>
                    </div>

                    <!-- Time -->
                    <div class="group">
                        <label class="block text-xs font-bold text-gray-400 uppercase tracking-widest mb-2 px-1">Time
                            Window</label>
                        <div class="relative">
                            <div
                                class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-gray-400">
                                <i class="fas fa-clock"></i>
                            </div>
                            <input type="text" id="activity_time_side"
                                class="form-control pl-11 h-12 bg-white border-transparent rounded-2xl font-semibold text-gray-800 shadow-sm cursor-default"
                                readonly>
                        </div>
                    </div>
                </div>

                <!-- Remarks Area -->
                <div class="group">
                    <label class="block text-xs font-bold text-gray-400 uppercase tracking-widest mb-2 px-1">Additional
                        Remarks</label>
                    <div class="relative">
                        <div class="absolute top-4 left-4 pointer-events-none text-gray-400">
                            <i class="fas fa-comment-alt"></i>
                        </div>
                        <textarea id="activity_remarks_side"
                            class="form-control pl-11 pt-3 min-h-[100px] bg-white border-transparent rounded-3xl font-medium text-gray-700 shadow-sm resize-none cursor-default"
                            readonly placeholder="No additional notes provided..."></textarea>
                    </div>
                </div>

                <!-- Audit Information -->
                <div class="pt-4 border-t border-gray-100 grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="flex items-center gap-2 text-gray-400">
                        <i class="fas fa-user-circle text-xs"></i>
                        <span class="text-[10px] font-bold uppercase tracking-tighter">Created By:</span>
                        <span id="modal_created_by" class="text-[10px] font-bold text-gray-600">Admin</span>
                    </div>
                    <div class="flex items-center gap-2 text-gray-400 md:justify-end">
                        <i class="fas fa-clock text-xs"></i>
                        <span class="text-[10px] font-bold uppercase tracking-tighter">Registered On:</span>
                        <span id="modal_registered_on" class="text-[10px] font-bold text-gray-600">00/00/0000</span>
                    </div>
                </div>
            </div>

            <div class="modal-footer p-6 bg-white border-t border-gray-100">
                <button type="button"
                    class="w-full h-12 bg-gray-100 text-gray-600 rounded-2xl hover:bg-gray-200 font-bold transition-all"
                    data-bs-dismiss="modal">Close Details</button>
            </div>
        </div>
    </div>
</div>

<!-- Load Font Awesome -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<!-- Tailwind CSS -->
<script src="https://cdn.tailwindcss.com"></script>

<?php if ($view === 'dashboard'): ?>
    <!-- Dashboard Header -->


    <!-- Stats Summary -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">

        <!-- Total Equipment (Blue Theme) -->
        <div
            class="relative overflow-hidden bg-gradient-to-br from-blue-700 to-blue-800 rounded-2xl shadow-lg p-6 group hover:shadow-blue-300 transition-all duration-300 border border-blue-600/50">
            <div
                class="absolute -right-4 -top-4 w-24 h-24 bg-white/10 rounded-full blur-2xl group-hover:scale-150 transition-transform">
            </div>
            <div class="flex items-center justify-between mb-4 relative z-10">
                <div class="p-3 rounded-xl bg-white/20 backdrop-blur-md text-white border border-white/30 shadow-inner">
                    <i class="fas fa-cubes text-xl"></i>
                </div>
                <span
                    class="text-[10px] font-black px-2.5 py-1 rounded-lg bg-white/20 text-white uppercase tracking-widest border border-white/20 shadow-sm">
                    Fleet Size
                </span>
            </div>
            <h3 class="text-5xl font-black text-white mb-1 relative z-10 tracking-tight drop-shadow-md">
                <?php echo $totalEquip; ?>
            </h3>
            <p class="text-white text-xs font-black uppercase tracking-[0.2em] mb-1.5 relative z-10 opacity-90">Total
                Equipment</p>
            <div class="flex items-center gap-2 relative z-10">
                <div class="h-1 w-12 bg-white/30 rounded-full overflow-hidden">
                    <div class="h-full bg-white w-full"></div>
                </div>
                <p class="text-white text-[15px] font-black uppercase tracking-tighter">
                    <i class="fas fa-map-marker-alt mr-1 opacity-70"></i>
                    <?php echo htmlspecialchars($userLocation); ?>
                </p>
            </div>
        </div>

        <!-- Operational (Green Theme) -->
        <div
            class="relative overflow-hidden bg-gradient-to-br from-emerald-600 to-emerald-700 rounded-2xl shadow-lg p-6 group hover:shadow-emerald-300 transition-all duration-300 border border-emerald-500/50">
            <div
                class="absolute -right-4 -top-4 w-24 h-24 bg-white/10 rounded-full blur-2xl group-hover:scale-150 transition-transform">
            </div>
            <div class="flex items-center justify-between mb-4 relative z-10">
                <div class="p-3 rounded-xl bg-white/20 backdrop-blur-md text-white border border-white/30 shadow-inner">
                    <i class="fas fa-power-off text-xl"></i>
                </div>
                <div
                    class="flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-white/20 text-white border border-white/20 shadow-sm">
                    <span class="text-xs font-black stat-percent"
                        data-target="<?php echo $totalEquip > 0 ? round(($operationalCount / $totalEquip) * 100) : 0; ?>">0%</span>
                </div>
            </div>
            <h3 class="text-5xl font-black text-white mb-1 relative z-10 tracking-tight drop-shadow-md">
                <?php echo $operationalCount; ?>
            </h3>
            <p class="text-white text-xs font-black uppercase tracking-[0.2em] mb-1.5 relative z-10 opacity-90">Operational
            </p>
            <div class="flex items-center gap-2 relative z-10">
                <div class="h-1 w-12 bg-white/30 rounded-full overflow-hidden">
                    <div class="h-full bg-white stat-progress-bar" style="width: 0%"
                        data-target="<?php echo $totalEquip > 0 ? ($operationalCount / $totalEquip) * 100 : 0; ?>"></div>
                </div>
                <p class="text-white text-[15px] font-black uppercase tracking-tighter flex items-center">
                    <i class="fas fa-check-circle mr-1 text-emerald-200"></i>Active & Ready
                </p>
            </div>
        </div>

        <!-- Under Repair (Amber Theme) -->
        <div
            class="relative overflow-hidden bg-gradient-to-br from-amber-500 to-amber-600 rounded-2xl shadow-lg p-6 group hover:shadow-amber-300 transition-all duration-300 border border-amber-400/50">
            <div
                class="absolute -right-4 -top-4 w-24 h-24 bg-white/10 rounded-full blur-2xl group-hover:scale-150 transition-transform">
            </div>
            <div class="flex items-center justify-between mb-4 relative z-10">
                <div class="p-3 rounded-xl bg-white/20 backdrop-blur-md text-white border border-white/30 shadow-inner">
                    <i class="fas fa-tools text-xl"></i>
                </div>
                <div
                    class="flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-white/20 text-white border border-white/20 shadow-sm">
                    <span class="text-xs font-black stat-percent"
                        data-target="<?php echo $totalEquip > 0 ? round(($repairCount / $totalEquip) * 100) : 0; ?>">0%</span>
                </div>
            </div>
            <h3 class="text-5xl font-black text-white mb-1 relative z-10 tracking-tight drop-shadow-md">
                <?php echo $repairCount; ?>
            </h3>
            <p class="text-white text-xs font-black uppercase tracking-[0.2em] mb-1.5 relative z-10 opacity-90">Under Repair
            </p>
            <div class="flex items-center gap-2 relative z-10">
                <div class="h-1 w-12 bg-white/30 rounded-full overflow-hidden">
                    <div class="h-full bg-white stat-progress-bar" style="width: 0%"
                        data-target="<?php echo $totalEquip > 0 ? ($repairCount / $totalEquip) * 100 : 0; ?>"></div>
                </div>
                <p class="text-white text-[15px] font-black uppercase tracking-tighter flex items-center">
                    <i class="fas fa-wrench mr-1 text-amber-100 animate-pulse"></i>Under Maintenance/Repair
                </p>
            </div>
        </div>

        <!-- Unserviceable (Red Theme) -->
        <div
            class="relative overflow-hidden bg-gradient-to-br from-rose-600 to-rose-700 rounded-2xl shadow-lg p-6 group hover:shadow-rose-300 transition-all duration-300 border border-rose-500/50">
            <div
                class="absolute -right-4 -top-4 w-24 h-24 bg-white/10 rounded-full blur-2xl group-hover:scale-150 transition-transform">
            </div>
            <div class="flex items-center justify-between mb-4 relative z-10">
                <div class="p-3 rounded-xl bg-white/20 backdrop-blur-md text-white border border-white/30 shadow-inner">
                    <i class="fas fa-ban text-xl"></i>
                </div>
                <div
                    class="flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-white/20 text-white border border-white/20 shadow-sm">
                    <span class="text-xs font-black stat-percent"
                        data-target="<?php echo $totalEquip > 0 ? round(($unserviceableCount / $totalEquip) * 100) : 0; ?>">0%</span>
                </div>
            </div>
            <h3 class="text-5xl font-black text-white mb-1 relative z-10 tracking-tight drop-shadow-md">
                <?php echo $unserviceableCount; ?>
            </h3>
            <p class="text-white text-xs font-black uppercase tracking-[0.2em] mb-1.5 relative z-10 opacity-90">
                Unserviceable</p>
            <div class="flex items-center gap-2 relative z-10">
                <div class="h-1 w-12 bg-white/30 rounded-full overflow-hidden">
                    <div class="h-full bg-white stat-progress-bar" style="width: 0%"
                        data-target="<?php echo $totalEquip > 0 ? ($unserviceableCount / $totalEquip) * 100 : 0; ?>">
                    </div>
                </div>
                <p class="text-white text-[15px] font-black uppercase tracking-tighter flex items-center">
                    <i class="fas fa-exclamation-triangle mr-1 text-rose-200"></i>Needs Attention
                </p>
            </div>
        </div>
    </div>
    </div>
    </div>

    <!-- Row 1: Analytics Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
        <!-- Pre-Repair Inspection Chart (Now Much Wider) -->
        <div
            class="lg:col-span-2 bg-white rounded-xl shadow-sm border border-gray-100 p-6 flex flex-col hover:shadow-md transition-shadow">
            <div class="flex justify-between items-start mb-6">
                <div>
                    <h3 class="text-lg font-bold text-gray-900">Equipment status overview</h3>
                    <p class="text-[11px] text-gray-400 font-medium uppercase tracking-wider mt-1">Flow trends across all
                        locations</p>
                </div>
                <?php
                $globalRepairTotal = array_sum($repairChartData['repair']);
                ?>
                <div class="flex flex-col items-end">
                    <span
                        class="text-xs font-bold text-amber-600 bg-amber-50 px-3 py-1.5 rounded-xl border border-amber-100 shadow-sm">
                        <i class="fas fa-tools mr-1.5"></i>
                        <?php echo $globalRepairTotal; ?> Units in Repair
                    </span>
                </div>
            </div>
            <div class="flex-grow relative h-[320px]">
                <canvas id="repairChart"></canvas>
            </div>
            <div class="mt-6 pt-4 border-t border-gray-50 flex justify-center gap-8">
                <div class="flex items-center gap-2">
                    <span class="w-3 h-3 rounded-full bg-emerald-500 shadow-sm shadow-emerald-200"></span>
                    <span class="text-[10px] font-bold text-gray-500 uppercase tracking-widest">Operational</span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="w-3 h-3 rounded-full bg-amber-500 shadow-sm shadow-amber-200"></span>
                    <span class="text-[10px] font-bold text-gray-500 uppercase tracking-widest">Repair</span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="w-3 h-3 rounded-full bg-rose-500 shadow-sm shadow-rose-200"></span>
                    <span class="text-[10px] font-bold text-gray-500 uppercase tracking-widest">Unserviceable</span>
                </div>
            </div>
        </div>

        <!-- Equipment Distribution by Location (Kept in the 1/3 column) -->
        <div class="bg-white rounded-xl shadow p-6 border border-gray-100 h-full flex flex-col">
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h1 class="text-lg font-bold text-gray-900">Equipment Distribution</h1>
                    <p class="text-xs text-gray-500 mt-1">Volume by location & status</p>
                </div>
                <div class="flex flex-col items-end">
                    <span class="text-2xl font-bold text-gray-800">
                        <?php echo $totalFleet; ?>
                    </span>
                    <span class="text-xs text-gray-500 uppercase font-semibold">Total Units</span>
                </div>
            </div>

            <div class="space-y-6">
                <?php foreach ($allLocations as $loc):
                    // Get data
                    $data = $locationStats[$loc] ?? ['total' => 0, 'operational' => 0, 'repair' => 0, 'unserviceable' => 0];
                    $total = $data['total'];

                    // 1. Calculate Width relative to the Largest Location (Max 100%)
                    $widthRelativeToMax = $maxTotal > 0 ? ($total / $maxTotal) * 100 : 0;

                    // 2. Global share percentage
                    $globalShare = $totalFleet > 0 ? round(($total / $totalFleet) * 100) : 0;
                    ?>
                    <div class="group cursor-default">
                        <div class="flex justify-between items-end mb-2">
                            <div class="flex items-center gap-2">
                                <span class="font-bold text-gray-700 group-hover:text-emerald-700 transition-colors">
                                    <?php echo $loc; ?>

                                </span>
                                <span
                                    class="text-xs px-2 py-0.5 rounded-md bg-emerald-50 text-emerald-600 font-bold border border-emerald-100 equip-share-percent"
                                    data-target="<?php echo $globalShare; ?>">0% Share</span>
                            </div>
                            <div class="text-sm font-extrabold text-gray-900">
                                <?php echo $total; ?> <span
                                    class="text-gray-400 text-[10px] font-bold uppercase tracking-tighter ml-0.5">Units</span>
                            </div>
                        </div>

                        <div class="w-full bg-gray-100/80 rounded-full h-3.5 p-0.5 relative overflow-hidden">
                            <!-- Unified Professional Green Bar -->
                            <div class="h-full rounded-full bg-gradient-to-r from-emerald-500 to-emerald-600 shadow-sm transition-all duration-1000 ease-out relative group/bar equip-progress-bar"
                                style="width: 0%" data-target="<?php echo max($widthRelativeToMax, 2); ?>">

                                <!-- Hover Glow Effect -->
                                <div
                                    class="absolute inset-0 bg-white/20 opacity-0 group-hover/bar:opacity-100 transition-opacity">
                                </div>

                                <!-- Detailed Tooltip -->
                                <div class="absolute bottom-full left-1/2 -translate-x-1/2 mb-3 hidden group-hover:block z-30">
                                    <div
                                        class="bg-gray-900 text-white text-[11px] rounded-xl py-2.5 px-4 whitespace-nowrap shadow-2xl border border-gray-800 backdrop-blur-sm">
                                        <div class="flex items-center gap-2 mb-1.5 border-b border-gray-700 pb-1.5">
                                            <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                                            <span class="font-bold uppercase tracking-wider">
                                                <?php echo $loc; ?> Status
                                            </span>
                                        </div>
                                        <div class="space-y-1 font-medium">
                                            <div class="flex justify-between gap-4">
                                                <span class="text-gray-400">Operational:</span>
                                                <span class="text-emerald-400 font-bold">
                                                    <?php echo $data['operational']; ?>
                                                </span>
                                            </div>
                                            <div class="flex justify-between gap-4">
                                                <span class="text-gray-400">Under Repair:</span>
                                                <span class="text-amber-400 font-bold">
                                                    <?php echo $data['repair']; ?>
                                                </span>
                                            </div>
                                            <div class="flex justify-between gap-4">
                                                <span class="text-gray-400">Unserviceable:</span>
                                                <span class="text-red-400 font-bold">
                                                    <?php echo $data['unserviceable']; ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div
                                            class="absolute top-full left-1/2 -translate-x-1/2 border-8 border-transparent border-t-gray-900">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php
            // Calculate totals dynamically from the chart data
            $sumOperational = array_sum(array_column($locationStats, 'operational'));
            $sumRepair = array_sum(array_column($locationStats, 'repair'));
            $sumUnserviceable = array_sum(array_column($locationStats, 'unserviceable'));
            ?>

            <div class="mt-8 pt-6 border-t border-gray-100 grid grid-cols-3 gap-4">

                <div
                    class="flex flex-col items-center justify-center p-4 rounded-xl bg-green-50/50 border border-green-100 transition-transform hover:-translate-y-1">
                    <div class="w-10 h-10 rounded-full bg-green-100 flex items-center justify-center mb-2 text-green-600">
                        <i class="fas fa-power-off text-lg"></i>
                    </div>
                    <span class="text-2xl font-bold text-gray-800">
                        <?php echo $sumOperational; ?>
                    </span>
                    <span class="text-xs font-medium text-green-600 uppercase tracking-wide mt-1">Operational</span>
                </div>

                <div
                    class="flex flex-col items-center justify-center p-4 rounded-xl bg-amber-50/50 border border-amber-100 transition-transform hover:-translate-y-1">
                    <div class="w-10 h-10 rounded-full bg-amber-100 flex items-center justify-center mb-2 text-amber-600">
                        <i class="fas fa-tools text-lg"></i>
                    </div>
                    <span class="text-2xl font-bold text-gray-800">
                        <?php echo $sumRepair; ?>
                    </span>
                    <span class="text-xs font-medium text-amber-600 uppercase tracking-wide mt-1">Repair</span>
                </div>

                <div
                    class="flex flex-col items-center justify-center p-4 rounded-xl bg-red-50/50 border border-red-100 transition-transform hover:-translate-y-1">
                    <div class="w-10 h-10 rounded-full bg-red-100 flex items-center justify-center mb-2 text-red-600">
                        <i class="fas fa-ban text-lg"></i>
                    </div>
                    <span class="text-2xl font-bold text-gray-800">
                        <?php echo $sumUnserviceable; ?>
                    </span>
                    <span class="text-xs font-medium text-red-600 uppercase tracking-wide mt-1">Unserviceable</span>
                </div>

            </div>

        </div>
    </div>

    <!-- Inventory Analytics Section -->
    <div class="mb-8">
        <div class="flex items-center justify-between mb-6">
            <div class="flex items-center gap-3">
                <div class="p-3 rounded-xl bg-gradient-to-br from-blue-600 to-blue-700 shadow-lg">
                    <i class="fas fa-boxes-stacked text-white text-xl"></i>
                </div>
                <div>
                    <h3 class="text-xl font-bold text-gray-900">Inventory Analytics</h3>
                    <p class="text-sm text-gray-500">
                        <?php echo htmlspecialchars($userLocation); ?>
                    </p>
                </div>
            </div>
            <div class="text-right">
                <p class="text-xs text-gray-400 uppercase tracking-wide">Last updated</p>
                <p class="text-sm font-semibold text-gray-600">
                    <?php echo date('M d, Y h:i A'); ?>
                </p>
            </div>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-8">
            <?php
            $totalItems = $invStats['total'] ?: 1;
            $stats = [
                ['Total Items', $invStats['total'], 'blue', 'fa-boxes', '100%'],
                ['Available', $invStats['available'], 'emerald', 'fa-check-circle', round(($invStats['available'] / $totalItems) * 100) . '%'],
                ['Borrowed', $invStats['borrowed'], 'orange', 'fa-arrow-up', round(($invStats['borrowed'] / $totalItems) * 100) . '%'],
                ['Low Stock', $invStats['low_stock'], 'amber', 'fa-exclamation-triangle', round(($invStats['low_stock'] / $totalItems) * 100) . '%'],
                ['Worn Out', $invStats['worn_out'], 'rose', 'fa-trash-alt', round(($invStats['worn_out'] / $totalItems) * 100) . '%']
            ];
            foreach ($stats as $stat):
                $isZero = $stat[1] == 0;
                ?>
                <div
                    class="inv-stat-card group relative bg-white rounded-2xl shadow-md hover:shadow-xl transition-all duration-300 border border-gray-100 overflow-hidden <?= $isZero ? 'opacity-60' : '' ?>">
                    <div
                        class="absolute top-0 right-0 w-24 h-24 bg-gradient-to-br from-<?= $stat[2] ?>-100 to-transparent rounded-bl-full -mr-4 -mt-4 opacity-50">
                    </div>
                    <div class="p-5">
                        <div class="flex items-center justify-between mb-3">
                            <div
                                class="p-2.5 rounded-xl bg-<?= $stat[2] ?>-100 text-<?= $stat[2] ?>-600 group-hover:scale-110 transition-transform duration-300">
                                <i class="fas <?= $stat[3] ?> text-lg"></i>
                            </div>
                            <span
                                class="text-xs font-semibold text-<?= $stat[2] ?>-600 bg-<?= $stat[2] ?>-50 px-2 py-1 rounded-full">
                                <?= $stat[4] ?>
                            </span>
                        </div>
                        <h4 class="text-3xl font-black text-gray-900 mb-1">
                            <?= $stat[1] ?>
                        </h4>
                        <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">
                            <?= $stat[0] ?>
                        </p>
                    </div>
                    <div class="h-1 bg-gradient-to-r from-<?= $stat[2] ?>-400 to-<?= $stat[2] ?>-600"></div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
            <div
                class="bg-white rounded-2xl shadow-lg border border-gray-100 p-6 hover:shadow-xl transition-shadow duration-300">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-2">
                        <div class="p-2 rounded-lg bg-purple-100">
                            <i class="fas fa-chart-pie text-purple-600"></i>
                        </div>
                        <h3 class="text-lg font-bold text-gray-900">Inventory Status</h3>
                    </div>
                    <span class="text-xs font-medium text-gray-400 bg-gray-50 px-3 py-1 rounded-full">Distribution</span>
                </div>
                <div class="h-[320px] flex items-center justify-center">
                    <canvas id="inventoryStatusChart"></canvas>
                </div>
            </div>

            <div
                class="lg:col-span-2 bg-white rounded-2xl shadow-lg border border-gray-100 p-6 hover:shadow-xl transition-shadow duration-300">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-2">
                        <div class="p-2 rounded-lg bg-indigo-100">
                            <i class="fas fa-chart-line text-indigo-600"></i>
                        </div>
                        <div>
                            <h3 class="text-lg font-bold text-gray-900">Activity Trend</h3>
                            <p class="text-xs text-gray-400">Last 7 days inventory movements</p>
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <span class="flex items-center gap-1 text-xs text-gray-500"><span
                                class="w-2 h-2 rounded-full bg-orange-500"></span>Borrowed</span>
                        <span class="flex items-center gap-1 text-xs text-gray-500"><span
                                class="w-2 h-2 rounded-full bg-emerald-500"></span>Returned</span>
                        <span class="flex items-center gap-1 text-xs text-gray-500"><span
                                class="w-2 h-2 rounded-full bg-blue-500"></span>Added</span>
                    </div>
                </div>
                <div class="h-[280px]">
                    <canvas id="inventoryTrendChart"></canvas>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div
                class="bg-white rounded-2xl shadow-lg border border-gray-100 p-6 hover:shadow-xl transition-shadow duration-300">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-2">
                        <div class="p-2 rounded-lg bg-cyan-100">
                            <i class="fas fa-history text-cyan-600"></i>
                        </div>
                        <h3 class="text-lg font-bold text-gray-900">Recent Activity</h3>
                    </div>
                    <span class="text-xs font-medium text-gray-400 bg-gray-50 px-3 py-1 rounded-full">Last 5</span>
                </div>
                <div class="space-y-3 max-h-[280px] overflow-y-auto custom-scrollbar pr-2">
                    <?php if (!empty($recentInventoryLogs)): ?>
                        <?php foreach ($recentInventoryLogs as $log): ?>
                            <?php
                            $actionIcon = match ($log['action_type']) {
                                'ADDED' => 'fa-plus',
                                'UPDATED' => 'fa-edit',
                                'ISSUED' => 'fa-arrow-up',
                                'RETURNED' => 'fa-arrow-down',
                                default => 'fa-circle'
                            };
                            $actionColor = match ($log['action_type']) {
                                'ADDED' => 'bg-blue-100 text-blue-600 border-blue-200',
                                'UPDATED' => 'bg-purple-100 text-purple-600 border-purple-200',
                                'ISSUED' => 'bg-orange-100 text-orange-600 border-orange-200',
                                'RETURNED' => 'bg-emerald-100 text-emerald-600 border-emerald-200',
                                default => 'bg-gray-100 text-gray-600 border-gray-200'
                            };
                            ?>
                            <div
                                class="flex items-center gap-3 p-3 rounded-xl bg-gray-50 hover:bg-gray-100 transition-colors duration-200 border border-gray-100">
                                <div class="p-2.5 rounded-lg <?php echo $actionColor; ?> border">
                                    <i class="fas <?php echo $actionIcon; ?>"></i>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-semibold text-gray-900 truncate">
                                        <?php echo htmlspecialchars($log['item_name']); ?>
                                    </p>
                                    <p class="text-xs text-gray-500">
                                        <span
                                            class="font-medium text-<?php echo strtolower($log['action_type']) === 'added' ? 'blue' : (strtolower($log['action_type']) === 'issued' ? 'orange' : (strtolower($log['action_type']) === 'returned' ? 'emerald' : 'gray')); ?>-600">
                                            <?php echo htmlspecialchars($log['action_type']); ?>
                                        </span>
                                        by
                                        <?php echo htmlspecialchars($log['performed_by']); ?>
                                    </p>
                                </div>
                                <span class="text-xs text-gray-400 whitespace-nowrap">
                                    <?php echo date('h:i A', strtotime($log['date_time'])); ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center py-10 text-gray-400">
                            <div class="w-16 h-16 mx-auto mb-3 rounded-full bg-gray-100 flex items-center justify-center">
                                <i class="fas fa-inbox text-2xl"></i>
                            </div>
                            <p class="text-sm font-medium">No recent activity</p>
                            <p class="text-xs text-gray-400 mt-1">Activity will appear here</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div
                class="bg-white rounded-2xl shadow-lg border border-gray-100 p-6 hover:shadow-xl transition-shadow duration-300">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-2">
                        <div class="p-2 rounded-lg bg-pink-100">
                            <i class="fas fa-tags text-pink-600"></i>
                        </div>
                        <h3 class="text-lg font-bold text-gray-900">Category Breakdown</h3>
                    </div>
                    <span class="text-xs font-medium text-gray-400 bg-gray-50 px-3 py-1 rounded-full">
                        <?php echo count($invCategories); ?> Categories
                    </span>
                </div>
                <div class="space-y-4">
                    <?php if (!empty($invCategories)): ?>
                        <?php foreach ($invCategories as $index => $cat): ?>
                            <?php
                            $percentage = $invStats['total'] > 0 ? round(($cat['count'] / $invStats['total']) * 100) : 0;
                            $colors = ['from-blue-500 to-blue-600', 'from-emerald-500 to-emerald-600', 'from-amber-500 to-amber-600', 'from-rose-500 to-rose-600', 'from-purple-500 to-purple-600', 'from-cyan-500 to-cyan-600', 'from-indigo-500 to-indigo-600'];
                            $colorIndex = $index % count($colors);
                            ?>
                            <div class="group">
                                <div class="flex justify-between items-center mb-2">
                                    <span class="text-sm font-semibold text-gray-700 flex items-center gap-2">
                                        <span
                                            class="w-2 h-2 rounded-full bg-<?php echo explode(' ', $colors[$colorIndex])[1]; ?>"></span>
                                        <?php echo htmlspecialchars($cat['category']); ?>
                                    </span>
                                    <div class="text-right">
                                        <span class="text-sm font-bold text-gray-900">
                                            <?php echo $cat['count']; ?>
                                        </span>
                                        <span class="text-xs text-gray-400 ml-1">(
                                            <?php echo $percentage; ?>%)
                                        </span>
                                    </div>
                                </div>
                                <div class="h-2.5 bg-gray-100 rounded-full overflow-hidden">
                                    <div class="h-full bg-gradient-to-r <?php echo $colors[$colorIndex]; ?> rounded-full transition-all duration-500 group-hover:shadow-lg"
                                        style="width: <?php echo $percentage; ?>%"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center py-8 text-gray-400">
                            <i class="fas fa-tags text-3xl mb-2"></i>
                            <p class="text-sm">No categories found</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Row 2: Document Status Overview (Full Width) -->
    <div class="grid grid-cols-1 mb-8">
        <div
            class="bg-white rounded-2xl shadow-sm border border-gray-100 p-8 flex flex-col hover:shadow-lg transition-all duration-300">
            <div
                class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6 mb-10 border-b border-gray-50 pb-6">
                <div class="flex items-center gap-5">
                    <div class="p-4 bg-emerald-50 rounded-2xl text-emerald-600 shadow-sm">
                        <i class="fas fa-file-invoice-dollar text-2xl"></i>
                    </div>
                    <div class="flex flex-col md:flex-row md:items-center gap-3 md:gap-6">
                        <h3 class="text-4 font-black text-gray-900 tracking-tight">Repair and Maintenance Status Overview</h3>

                        <!-- Toggle Buttons moved beside title -->
                        <div
                            class="flex bg-gray-100 p-1 rounded-xl border border-gray-200 shadow-inner scale-90 md:scale-100 origin-left">
                            <button id="barChartBtn"
                                class="px-5 py-2 text-[11px] font-black uppercase tracking-wider rounded-lg transition-all duration-300 active bg-white text-emerald-700 shadow-sm ring-1 ring-gray-200 hover:scale-105 hover:shadow-md"
                                onclick="showBarChart()">
                                <i class="fas fa-chart-bar mr-1.5"></i>Bar
                            </button>
                            <button id="pieChartBtn"
                                class="px-5 py-2 text-[11px] font-black uppercase tracking-wider rounded-lg transition-all duration-300 text-gray-500 hover:text-emerald-600 hover:bg-white/50 hover:scale-105"
                                onclick="showPieChart()">
                                <i class="fas fa-chart-pie mr-1.5"></i>Pie
                            </button>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-3 bg-emerald-50/50 px-4 py-2 rounded-2xl border border-emerald-100/50">
                    <span class="flex h-2.5 w-2.5 rounded-full bg-emerald-500 animate-pulse"></span>
                    <p class="text-[11px] text-gray-600 font-black uppercase tracking-widest">
                        Location: <span class="text-emerald-700">
                            <?php echo htmlspecialchars($userLocation); ?>
                        </span>
                    </p>
                </div>
            </div>

            <div class="flex flex-col gap-8">
                <!-- 4 Wide Status Cards on Top -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-5">
                    <?php
                    $statItems = [
                        ['Pending', $documentStats['Pending'], 'amber', 'fa-clock'],
                        ['Approved', $documentStats['Approved'], 'sky', 'fa-check-double'],
                        ['Done', $documentStats['Done'], 'emerald', 'fa-circle-check'],
                        ['Complete', $documentStats['Complete'], 'slate', 'fa-box-archive']
                    ];
                    foreach ($statItems as $item):
                        $color = $item[2];
                        ?>
                        <div
                            class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100 group hover:border-<?php echo $color; ?>-400 hover:shadow-md transition-all duration-300 cursor-default relative overflow-hidden stat-card-interactive">
                            <div class="flex justify-between items-start mb-4 relative z-10">
                                <div>
                                    <span class="text-[10px] text-gray-400 font-black uppercase tracking-widest mb-1 block">
                                        <?php echo $item[0]; ?>
                                    </span>
                                    <div
                                        class="text-3xl font-black text-gray-900 tracking-tight group-hover:text-<?php echo $color; ?>-600 transition-colors">
                                        <?php echo $item[1]; ?>
                                    </div>
                                </div>
                                <div
                                    class="p-3 rounded-xl bg-<?php echo $color; ?>-50 text-<?php echo $color; ?>-600 group-hover:bg-<?php echo $color; ?>-500 group-hover:text-white transition-all duration-300 shadow-sm">
                                    <i class="fas <?php echo $item[3]; ?> text-sm"></i>
                                </div>
                            </div>

                            <?php if ($totalDocs > 0):
                                $pct = ($item[1] / $totalDocs) * 100;
                                ?>
                                <div class="space-y-2 relative z-10">
                                    <div class="flex justify-between items-center">
                                        <span class="text-xs font-bold text-gray-500 uppercase tracking-wide">Total</span>
                                        <span class="text-sm font-black text-<?php echo $color; ?>-600 percent-text"
                                            data-target="<?php echo round($pct); ?>">0%</span>
                                    </div>
                                    <div class="h-3 w-full bg-gray-100 rounded-full overflow-hidden border border-gray-200">
                                        <div class="h-full bg-<?php echo $color; ?>-500 rounded-full progress-bar" style="width: 0%"
                                            data-target="<?php echo $pct; ?>"></div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Subtle background decoration -->
                            <div
                                class="absolute -right-4 -bottom-4 w-16 h-16 bg-<?php echo $color; ?>-50/30 rounded-full blur-xl group-hover:scale-150 transition-transform duration-700">
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Expanded Chart Area below the cards -->
                <div class="flex flex-col lg:flex-row gap-8 items-center">
                    <div
                        class="w-full lg:w-[85%] h-[500px] relative group bg-gray-50/50 rounded-3xl p-8 border border-gray-100 hover:shadow-lg transition-shadow duration-300">
                        <canvas id="documentChart"></canvas>
                        <?php if ($totalDocs == 0): ?>
                            <div
                                class="absolute inset-0 flex items-center justify-center bg-white/80 backdrop-blur-sm rounded-3xl">
                                <div class="text-center p-10">
                                    <div
                                        class="w-20 h-20 bg-gray-50 rounded-full flex items-center justify-center mx-auto mb-4 border-2 border-dashed border-gray-200">
                                        <i class="fas fa-file-circle-exclamation text-3xl text-gray-300"></i>
                                    </div>
                                    <p class="text-lg font-black text-gray-400 uppercase tracking-tighter">No active records
                                        found</p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Total Volume Card (Sized to match chart) -->
                    <div class="w-full lg:w-[15%] flex flex-col gap-4">
                        <div
                            class="bg-gradient-to-br from-emerald-600 to-emerald-800 h-[500px] rounded-3xl flex flex-col justify-center items-center text-center shadow-xl relative overflow-hidden group px-8 border border-emerald-500/30 total-card-glow">
                            <div
                                class="absolute inset-0 bg-gradient-to-br from-white/20 to-transparent opacity-0 group-hover:opacity-100 transition-opacity">
                            </div>

                            <!-- Decorative Ring -->
                            <div
                                class="absolute w-64 h-64 border-4 border-white/10 rounded-full -top-20 -right-20 group-hover:scale-110 transition-transform duration-700">
                            </div>

                            <div class="relative z-10">
                                <div
                                    class="h-20 w-20 rounded-3xl bg-white/20 backdrop-blur-md flex items-center justify-center text-white mx-auto mb-8 group-hover:rotate-12 transition-transform duration-500 border border-white/20 shadow-2xl">
                                    <i class="fas fa-layer-group text-3xl"></i>
                                </div>
                                <div class="text-white text-7xl font-black tracking-tighter mb-3 drop-shadow-2xl">
                                    <?php echo $totalDocs; ?>
                                </div>
                                <div class="text-white/80 text-xs font-black uppercase tracking-[0.3em] mb-1">Total
                                    Documents</div>
                                <div
                                    class="text-white/60 text-[9px] font-bold uppercase tracking-widest italic mt-4 border-t border-white/10 pt-4">
                                    Live System Feed</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Calendar Section -->
    <div class="bg-white rounded-xl shadow p-6 border border-gray-100">
        <div class="flex flex-col md:flex-row gap-6">

            <div class="flex-grow">
                <div class="mb-4 flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-bold text-gray-900">Schedule Calendar</h3>
                        <p class="text-xs text-gray-500">Activities in
                            <?php echo htmlspecialchars($userLocation); ?>
                        </p>
                    </div>
                    <div class="hidden md:flex gap-4 text-xs font-medium text-gray-600">
                        <div class="flex items-center">
                            <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 mr-2"></span>Inspection
                        </div>
                        <div class="flex items-center">
                            <span class="w-2.5 h-2.5 rounded-full bg-amber-500 mr-2"></span>Repair
                        </div>
                        <div class="flex items-center">
                            <span class="w-2.5 h-2.5 rounded-full bg-blue-500 mr-2"></span>Appointment
                        </div>
                    </div>
                </div>

                <div id="adminCalendar" class="min-h-[400px] text-sm font-medium text-gray-600"></div>
            </div>

            <div class="w-full md:w-80 border-l border-gray-100 md:pl-6 pt-6 md:pt-0">
                <div class="flex justify-between items-center mb-4">
                    <h4 class="font-bold text-gray-800">Next 7 Days</h4>
                    <span class="text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded-full font-semibold">
                        <?php echo count($upcomingEvents); ?> Upcoming
                    </span>
                </div>

                <div class="space-y-3 max-h-[600px] overflow-y-auto pr-2 custom-scrollbar">
                    <?php if (empty($upcomingEvents)): ?>
                        <div class="text-center py-8 bg-gray-50 rounded-lg border border-dashed border-gray-200">
                            <i class="fas fa-calendar-check text-gray-300 text-3xl mb-2"></i>
                            <p class="text-sm text-gray-500">No upcoming events</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($upcomingEvents as $event):
                            $dateObj = new DateTime($event['activity_date']);
                            $isToday = $event['activity_date'] === $today;
                            ?>
                            <div
                                class="group flex gap-3 p-3 rounded-lg border border-gray-100 hover:border-green-200 hover:bg-green-50/30 transition-all duration-200">
                                <div class="flex-shrink-0 w-12 text-center">
                                    <div class="text-xs font-bold text-gray-400 uppercase tracking-wider">
                                        <?php echo $dateObj->format('M'); ?>
                                    </div>
                                    <div class="text-xl font-bold <?php echo $isToday ? 'text-green-600' : 'text-gray-700'; ?>">
                                        <?php echo $dateObj->format('d'); ?>
                                    </div>
                                </div>

                                <div class="flex-grow">
                                    <h5 class="text-sm font-bold text-gray-800 group-hover:text-green-700 transition-colors">
                                        <?php echo htmlspecialchars($event['activity_type']); ?>
                                    </h5>
                                    <div class="flex items-center text-xs text-gray-500 mt-1">
                                        <i class="far fa-clock mr-1.5 text-gray-400"></i>
                                        <?php echo date('h:i A', strtotime($event['activity_time'])); ?>
                                    </div>
                                    <div class="text-xs text-gray-400 mt-1 truncate max-w-[150px]"
                                        title="<?php echo htmlspecialchars($event['property_no']); ?>">
                                        #
                                        <?php echo htmlspecialchars($event['property_no']); ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <br>



    <!-- Equipment Status Report -->
    <div class="bg-white rounded-xl shadow-soft border border-gray-100 p-6 mb-8">
        <!-- Header -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-6">
            <div>
                <h2 class="text-xl font-bold text-gray-900">Equipment Status Report</h2>
                <p class="text-gray-600 text-sm mt-1">Comprehensive overview of all equipment status</p>
            </div>

            <!-- Tabs -->
            <div class="flex space-x-2 bg-gray-100 p-1 rounded-lg">
                <button id="preRepairBtn" class="px-4 py-2 text-sm font-medium rounded-md transition-all duration-200 ease-in-out transform hover:scale-105
                            text-black-700 hover:bg-green" onclick="showTab('preRepair')">
                    Pre-Repair Inspection
                </button>
                <button id="summaryBtn" class="px-4 py-2 text-sm font-medium rounded-md transition-all duration-200 ease-in-out transform hover:scale-105
                           text-gray-700 hover:text-black-900 hover:bg-green" onclick="showTab('summary')">
                    Summary by Location
                </button>
            </div>
        </div>


        <!-- Tab Content -->
        <div id="preRepairTab" class="report-tab">
            <div class="overflow-x-auto rounded-lg border border-gray-200">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th scope="col"
                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">#
                            </th>
                            <th scope="col"
                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Equipment</th>
                            <th scope="col"
                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Units
                            </th>
                            <th scope="col"
                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Operational</th>
                            <th scope="col"
                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Under
                                Repair</th>
                            <th scope="col"
                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Unserviceable</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (!empty($categoryStats)):
                            $row_number = 1;
                            foreach ($categoryStats as $row):
                                $units = $row['units'];
                                // Avoid division by zero
                                $opPct = $units > 0 ? round(($row['operational'] / $units) * 100) : 0;
                                $repPct = $units > 0 ? round(($row['under_repair'] / $units) * 100) : 0;
                                $unsPct = $units > 0 ? round(($row['unserviceable'] / $units) * 100) : 0;
                                ?>
                                <tr class="hover:bg-blue-50/50 transition-colors duration-200 group">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-500">
                                        <?php echo $row_number++; ?>
                                    </td>

                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div
                                                class="flex-shrink-0 h-8 w-8 rounded bg-blue-100 text-blue-600 flex items-center justify-center mr-3 font-bold text-xs">
                                                <?php echo strtoupper(substr($row['equipment_name'], 0, 2)); ?>
                                            </div>
                                            <div
                                                class="text-sm font-bold text-gray-900 group-hover:text-blue-600 transition-colors">
                                                <?php echo htmlspecialchars($row['equipment_name']); ?>
                                            </div>
                                        </div>
                                    </td>

                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span
                                            class="inline-flex items-center justify-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                            <?php echo $units; ?> Units
                                        </span>
                                    </td>

                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="w-full max-w-[140px]">
                                            <div class="flex justify-between text-xs mb-1">
                                                <span class="font-semibold text-green-700">
                                                    <?php echo $row['operational']; ?>
                                                </span>
                                                <span class="text-gray-400 equip-status-percent"
                                                    data-target="<?php echo $opPct; ?>">
                                                    0%
                                                </span>
                                            </div>
                                            <div class="w-full bg-gray-100 rounded-full h-1.5 overflow-hidden">
                                                <div class="bg-green-500 h-1.5 rounded-full equip-status-bar" style="width: 0%"
                                                    data-target="<?php echo $opPct; ?>">
                                                </div>
                                            </div>
                                        </div>
                                    </td>

                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="w-full max-w-[140px]">
                                            <div class="flex justify-between text-xs mb-1">
                                                <span class="font-semibold text-amber-700">
                                                    <?php echo $row['under_repair']; ?>
                                                </span>
                                                <span class="text-gray-400 equip-status-percent"
                                                    data-target="<?php echo $repPct; ?>">
                                                    0%
                                                </span>
                                            </div>
                                            <div class="w-full bg-gray-100 rounded-full h-1.5 overflow-hidden">
                                                <div class="bg-amber-500 h-1.5 rounded-full equip-status-bar" style="width: 0%"
                                                    data-target="<?php echo $repPct; ?>">
                                                </div>
                                            </div>
                                        </div>
                                    </td>

                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="w-full max-w-[140px]">
                                            <div class="flex justify-between text-xs mb-1">
                                                <span class="font-semibold text-red-700">
                                                    <?php echo $row['unserviceable']; ?>
                                                </span>
                                                <span class="text-gray-400 equip-status-percent"
                                                    data-target="<?php echo $unsPct; ?>">
                                                    0%
                                                </span>
                                            </div>
                                            <div class="w-full bg-gray-100 rounded-full h-1.5 overflow-hidden">
                                                <div class="bg-red-500 h-1.5 rounded-full equip-status-bar" style="width: 0%"
                                                    data-target="<?php echo $unsPct; ?>">
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="px-6 py-10 text-center text-gray-500">
                                    <div class="flex flex-col items-center justify-center">
                                        <i class="fas fa-folder-open text-4xl mb-3 text-gray-300"></i>
                                        <p>No equipment categories found for this location.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div id="summaryTab" class="report-tab hidden">
            <div class="overflow-x-auto rounded-lg border border-gray-200">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th scope="col"
                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Location</th>
                            <th scope="col"
                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total
                                Units</th>
                            <th scope="col"
                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Operational</th>
                            <th scope="col"
                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Under
                                Repair</th>
                            <th scope="col"
                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Unserviceable</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (!empty($locationStatsArray)):
                            foreach ($locationStatsArray as $row):
                                $units = $row['units'];
                                $opPct = $units > 0 ? round(($row['operational'] / $units) * 100) : 0;
                                $repPct = $units > 0 ? round(($row['under_repair'] / $units) * 100) : 0;
                                $unsPct = $units > 0 ? round(($row['unserviceable'] / $units) * 100) : 0;

                                // Highlight current user location row
                                $isCurrentLoc = ($row['location_name'] === $userLocation);
                                $rowClass = $isCurrentLoc ? 'bg-blue-50 border-l-4 border-blue-500' : 'hover:bg-gray-50 border-l-4 border-transparent';
                                ?>
                                <tr class="<?php echo $rowClass; ?> transition-all duration-200">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div
                                                class="flex-shrink-0 h-8 w-8 rounded-full <?php echo $isCurrentLoc ? 'bg-blue-100 text-blue-600' : 'bg-gray-100 text-gray-500'; ?> flex items-center justify-center mr-3">
                                                <i class="fas fa-map-marker-alt text-sm"></i>
                                            </div>
                                            <div>
                                                <div class="text-sm font-bold text-gray-900">
                                                    <?php echo htmlspecialchars($row['location_name']); ?>
                                                </div>
                                                <?php if ($isCurrentLoc): ?>
                                                    <span class="text-[10px] text-blue-600 font-medium">Your Location</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>

                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-bold text-gray-900">
                                            <?php echo $units; ?>
                                        </div>
                                    </td>

                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center gap-2">
                                            <div class="flex flex-col w-24">
                                                <div class="h-1.5 w-full bg-green-100 rounded-full overflow-hidden">
                                                    <div class="h-full bg-green-500 equip-status-bar" style="width: 0%"
                                                        data-target="<?php echo $opPct; ?>"></div>
                                                </div>
                                            </div>
                                            <div class="text-xs">
                                                <span class="font-bold text-green-700">
                                                    <?php echo $row['operational']; ?>
                                                </span>
                                                <span class="text-gray-400 mx-1">/</span>
                                                <span class="text-gray-500 equip-status-percent"
                                                    data-target="<?php echo $opPct; ?>">
                                                    0%
                                                </span>
                                            </div>
                                        </div>
                                    </td>

                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center gap-2">
                                            <div class="flex flex-col w-24">
                                                <div class="h-1.5 w-full bg-amber-100 rounded-full overflow-hidden">
                                                    <div class="h-full bg-amber-500 equip-status-bar" style="width: 0%"
                                                        data-target="<?php echo $repPct; ?>"></div>
                                                </div>
                                            </div>
                                            <div class="text-xs">
                                                <span class="font-bold text-amber-700">
                                                    <?php echo $row['under_repair']; ?>
                                                </span>
                                                <span class="text-gray-400 mx-1">/</span>
                                                <span class="text-gray-500 equip-status-percent"
                                                    data-target="<?php echo $repPct; ?>">
                                                    0%
                                                </span>
                                            </div>
                                        </div>
                                    </td>

                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center gap-2">
                                            <div class="flex flex-col w-24">
                                                <div class="h-1.5 w-full bg-red-100 rounded-full overflow-hidden">
                                                    <div class="h-full bg-red-500 equip-status-bar" style="width: 0%"
                                                        data-target="<?php echo $unsPct; ?>"></div>
                                                </div>
                                            </div>
                                            <div class="text-xs">
                                                <span class="font-bold text-red-700">
                                                    <?php echo $row['unserviceable']; ?>
                                                </span>
                                                <span class="text-gray-400 mx-1">/</span>
                                                <span class="text-gray-500 equip-status-percent"
                                                    data-target="<?php echo $unsPct; ?>">
                                                    0%
                                                </span>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="text-center py-6 text-gray-500">No location data available.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- View Full Report Button -->
        <div class="mt-6 pt-6 border-t border-gray-200 text-center">
            <a href="?view=report"
                class="inline-flex items-center px-5 py-2.5 bg-green-600 text-white font-medium rounded-lg hover:bg-blue-700 
                  transition-all duration-200 transform hover:scale-105 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                <i class="fas fa-chart-bar mr-2"></i>
                View Full Report
            </a>
        </div>
    </div>


    <!-- Rest of your dashboard content remains the same -->
    <?php
        // Keep your existing Activity Log, Calendar, and Equipment Status Report sections
        // They remain unchanged
    ?>
<?php endif; ?>

<!-- Load Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js'></script>

<!-- Progress Bar Animation -->
<script>
    document.addEventListener('DOMContentLoaded', function () {
        // Animate function for progress bars and percentages
        function animateElements(selectorPairs) {
            selectorPairs.forEach((pair, groupIndex) => {
                const bars = document.querySelectorAll(pair.bar);
                const percents = document.querySelectorAll(pair.percent);

                bars.forEach((bar, index) => {
                    const targetWidth = parseFloat(bar.dataset.target);
                    const percentText = percents[index];
                    if (!percentText) return;
                    const targetPercent = parseInt(percentText.dataset.target);
                    const suffixText = pair.suffix || '%';

                    setTimeout(() => {
                        // Animate bar
                        let startTime = null;
                        const duration = 1500;

                        function animateBar(timestamp) {
                            if (!startTime) startTime = timestamp;
                            const elapsed = timestamp - startTime;
                            const progress = Math.min(elapsed / duration, 1);
                            const easeOut = 1 - Math.pow(1 - progress, 4);
                            bar.style.width = (easeOut * targetWidth) + '%';
                            if (progress < 1) requestAnimationFrame(animateBar);
                        }

                        // Animate percent
                        let percentStartTime = null;

                        function animatePercent(timestamp) {
                            if (!percentStartTime) percentStartTime = timestamp;
                            const elapsed = timestamp - percentStartTime;
                            const progress = Math.min(elapsed / duration, 1);
                            const easeOut = 1 - Math.pow(1 - progress, 4);
                            percentText.textContent = Math.round(easeOut * targetPercent) + suffixText;
                            if (progress < 1) requestAnimationFrame(animatePercent);
                        }

                        requestAnimationFrame(animateBar);
                        requestAnimationFrame(animatePercent);
                    }, (groupIndex * 200) + (index * 150));
                });
            });
        }

        // Animate all progress elements
        animateElements([
            { bar: '.progress-bar', percent: '.percent-text', suffix: '%' },
            { bar: '.equip-progress-bar', percent: '.equip-share-percent', suffix: '% Share' },
            { bar: '.stat-progress-bar', percent: '.stat-percent', suffix: '%' },
            { bar: '.equip-status-bar', percent: '.equip-status-percent', suffix: '%' }
        ]);
    });
</script>

<!-- Document Chart JavaScript -->
<script>
    document.addEventListener('DOMContentLoaded', function () {
        // Prepare data from PHP
        const documentData = {
            labels: ['Pending', 'Approved', 'Done', 'Complete'],
            values: [
                <?php echo $documentStats['Pending']; ?>,
                <?php echo $documentStats['Approved']; ?>,
                <?php echo $documentStats['Done']; ?>,
                <?php echo $documentStats['Complete']; ?>
            ],
        colors: [
            'rgba(245, 158, 11, 0.9)',   // Pending
            'rgba(14, 165, 233, 0.9)',   // Approved
            'rgba(34, 197, 94, 0.9)',    // Done
            'rgba(71, 85, 105, 0.9)'     // Complete
        ],
            borderColors: [
                'rgb(245, 158, 11)',
                'rgb(14, 165, 233)',
                'rgb(34, 197, 94)',
                'rgb(71, 85, 105)'
            ]
    };

    // Custom Plugin for Data Labels (Percentages)
    const percentagePlugin = {
        id: 'percentageLabels',
        afterDraw: (chart) => {
            const { ctx, data, chartArea: { top, bottom, left, right, width, height } } = chart;
            const total = <?php echo $totalDocs; ?>;
            if (total === 0) return;

            ctx.save();
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.font = 'bold 11px "Inter", sans-serif';

            chart.data.datasets.forEach((dataset, i) => {
                chart.getDatasetMeta(i).data.forEach((datapoint, index) => {
                    const value = data.datasets[i].data[index];
                    const percentage = ((value / total) * 100).toFixed(1) + '%';

                    // Only show if value > 0
                    if (value === 0) return;

                    if (chart.config.type === 'doughnut') {
                        // Doughnut placement
                        const { x, y } = datapoint.tooltipPosition();
                        ctx.fillStyle = 'white';
                        // Add small shadow for readability
                        ctx.shadowBlur = 4;
                        ctx.shadowColor = 'rgba(0,0,0,0.5)';
                        ctx.fillText(percentage, x, y);
                    } else {
                        // Bar placement
                        const { x, y } = datapoint.tooltipPosition();
                        ctx.fillStyle = '#111827';
                        ctx.fillText(percentage, x, y - 10);
                    }
                });
            });
            ctx.restore();
        }
    };

    let currentChart = null;
    let chartContainer = document.getElementById('documentChart');

    // Animation function for smooth transitions
    function animateChartTransition(callback) {
        chartContainer.style.opacity = '0';
        chartContainer.style.transform = 'scale(0.95)';
        chartContainer.style.transition = 'all 0.3s ease-in-out';

        setTimeout(() => {
            callback();
            chartContainer.style.opacity = '1';
            chartContainer.style.transform = 'scale(1)';
        }, 300);
    }

    // Initialize with bar chart as default
    if (<?php echo $totalDocs; ?> > 0) {
        showBarChart();
    }

    function showBarChart() {
        const barBtn = document.getElementById('barChartBtn');
        const pieBtn = document.getElementById('pieChartBtn');
        barBtn.className = "px-5 py-2 text-[11px] font-black uppercase tracking-wider rounded-lg transition-all duration-300 active bg-white text-emerald-700 shadow-sm ring-1 ring-gray-200 hover:scale-105 hover:shadow-md";
        pieBtn.className = "px-5 py-2 text-[11px] font-black uppercase tracking-wider rounded-lg transition-all duration-300 text-gray-500 hover:text-emerald-600 hover:bg-white/50 hover:scale-105";

        animateChartTransition(() => {
            if (currentChart) currentChart.destroy();
            const ctx = document.getElementById('documentChart').getContext('2d');

            currentChart = new Chart(ctx, {
                type: 'bar',
                plugins: [percentagePlugin],
                data: {
                    labels: documentData.labels,
                    datasets: [{
                        data: documentData.values,
                        backgroundColor: documentData.colors,
                        borderColor: documentData.borderColors,
                        borderWidth: 3,
                        borderRadius: 16,
                        barThickness: 120,
                        hoverBackgroundColor: documentData.colors.map(c => c.replace('0.9', '1.0')),
                        hoverBorderWidth: 4,
                        hoverBorderColor: documentData.borderColors
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    layout: { padding: { top: 40, right: 30, bottom: 20 } },
                    animation: {
                        duration: 1000,
                        easing: 'easeOutQuart',
                        onComplete: function () {
                            document.getElementById('documentChart').classList.add('chart-loaded');
                        }
                    },
                    onHover: (event, chartElement) => {
                        event.native.target.style.cursor = chartElement[0] ? 'pointer' : 'default';
                    },
                    onClick: (event, elements) => {
                        if (elements.length > 0) {
                            const index = elements[0].index;
                            const label = documentData.labels[index];
                            const value = documentData.values[index];
                            const colors = ['Pending', 'Approved', 'Done', 'Complete'];
                            alert(`${label}: ${value} document(s)\nClick to view details in document management.`);
                        }
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            enabled: true,
                            backgroundColor: 'rgba(17, 24, 39, 0.95)',
                            padding: 12,
                            cornerRadius: 8,
                            titleFont: { size: 13, weight: 'bold' },
                            bodyFont: { size: 12 },
                            displayColors: true,
                            callbacks: {
                                label: function (context) {
                                    const total = <?php echo $totalDocs; ?>;
                                    const percentage = ((context.raw / total) * 100).toFixed(1);
                                    return ` ${context.raw} documents (${percentage}%)`;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: { borderDash: [5, 5], color: '#f3f4f6' },
                            ticks: { font: { weight: 'bold', size: 12 } }
                        },
                        x: {
                            grid: { display: false },
                            ticks: { font: { weight: 'black', size: 14 }, color: '#111827' }
                        }
                    }
                }
            });
        });
    }

    function showPieChart() {
        const barBtn = document.getElementById('barChartBtn');
        const pieBtn = document.getElementById('pieChartBtn');
        pieBtn.className = "px-5 py-2 text-[11px] font-black uppercase tracking-wider rounded-lg transition-all duration-300 active bg-white text-emerald-700 shadow-sm ring-1 ring-gray-200 hover:scale-105 hover:shadow-md";
        barBtn.className = "px-5 py-2 text-[11px] font-black uppercase tracking-wider rounded-lg transition-all duration-300 text-gray-500 hover:text-emerald-600 hover:bg-white/50 hover:scale-105";

        animateChartTransition(() => {
            if (currentChart) currentChart.destroy();
            const ctx = document.getElementById('documentChart').getContext('2d');

            currentChart = new Chart(ctx, {
                type: 'doughnut',
                plugins: [percentagePlugin],
                data: {
                    labels: documentData.labels,
                    datasets: [{
                        data: documentData.values,
                        backgroundColor: documentData.colors,
                        borderColor: 'white',
                        borderWidth: 5,
                        hoverOffset: 50,
                        hoverBackgroundColor: documentData.colors.map(c => c.replace('0.9', '1.0'))
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '55%',
                    animation: {
                        animateRotate: true,
                        animateScale: true,
                        duration: 1000,
                        easing: 'easeOutQuart'
                    },
                    onHover: (event, chartElement) => {
                        event.native.target.style.cursor = chartElement[0] ? 'pointer' : 'default';
                    },
                    onClick: (event, elements) => {
                        if (elements.length > 0) {
                            const index = elements[0].index;
                            const label = documentData.labels[index];
                            const value = documentData.values[index];
                            alert(`${label}: ${value} document(s)\nClick to view details in document management.`);
                        }
                    },
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: {
                                padding: 30,
                                usePointStyle: true,
                                font: { size: 14, weight: 'black' },
                                color: '#111827',
                                generateLabels: function (chart) {
                                    const data = chart.data;
                                    return data.labels.map((label, i) => ({
                                        text: `${label}: ${data.datasets[0].data[i]}`,
                                        fillStyle: data.datasets[0].backgroundColor[i],
                                        hidden: false,
                                        index: i
                                    }));
                                }
                            }
                        },
                        tooltip: {
                            enabled: true,
                            backgroundColor: 'rgba(17, 24, 39, 0.95)',
                            padding: 12,
                            cornerRadius: 8,
                            titleFont: { size: 13, weight: 'bold' },
                            bodyFont: { size: 12 },
                            displayColors: true,
                            callbacks: {
                                label: function (context) {
                                    const total = <?php echo $totalDocs; ?>;
                                    const percentage = ((context.raw / total) * 100).toFixed(1);
                                    return ` ${context.raw} documents (${percentage}%)`;
                                }
                            }
                        }
                    }
                }
            });
        });
    }

    window.showBarChart = showBarChart;
    window.showPieChart = showPieChart;

    // Initialize Pre-Repair Area Chart (Modern Stacked Area)
    const repairCtx = document.getElementById('repairChart');
    if (repairCtx) {
        const ctx = repairCtx.getContext('2d');

        // Create Gradients
        const gradOp = ctx.createLinearGradient(0, 0, 0, 400);
        gradOp.addColorStop(0, 'rgba(16, 185, 129, 0.4)');
        gradOp.addColorStop(1, 'rgba(16, 185, 129, 0.0)');

        const gradRep = ctx.createLinearGradient(0, 0, 0, 400);
        gradRep.addColorStop(0, 'rgba(245, 158, 11, 0.4)');
        gradRep.addColorStop(1, 'rgba(245, 158, 11, 0.0)');

        const gradUns = ctx.createLinearGradient(0, 0, 0, 400);
        gradUns.addColorStop(0, 'rgba(244, 63, 94, 0.4)');
        gradUns.addColorStop(1, 'rgba(244, 63, 94, 0.0)');

        new Chart(repairCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($repairChartData['locations']); ?>,
                    datasets: [
                        {
                            label: 'Operational',
                            data: <?php echo json_encode($repairChartData['operational']); ?>,
                                borderColor: '#10b981',
                                    backgroundColor: gradOp,
                                        fill: true,
                                            tension: 0.4,
                                                borderWidth: 3,
                                                    pointRadius: 4,
                                                        pointBackgroundColor: '#fff',
                                                            pointBorderColor: '#10b981',
                                                                pointBorderWidth: 2
    },
    {
        label: 'Under Repair',
            data: <?php echo json_encode($repairChartData['repair']); ?>,
                borderColor: '#f59e0b',
                    backgroundColor: gradRep,
                        fill: true,
                            tension: 0.4,
                                borderWidth: 3,
                                    pointRadius: 4,
                                        pointBackgroundColor: '#fff',
                                            pointBorderColor: '#f59e0b',
                                                pointBorderWidth: 2
    },
    {
        label: 'Unserviceable',
            data: <?php echo json_encode($repairChartData['unserviceable']); ?>,
                borderColor: '#f43f5e',
                    backgroundColor: gradUns,
                        fill: true,
                            tension: 0.4,
                                borderWidth: 3,
                                    pointRadius: 4,
                                        pointBackgroundColor: '#fff',
                                            pointBorderColor: '#f43f5e',
                                                pointBorderWidth: 2
    }
                    ]
                },
    options: {
        responsive: true,
            maintainAspectRatio: false,
                interaction: {
            mode: 'index',
                intersect: false,
                    },
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: '#111827',
                    padding: 12,
                        cornerRadius: 12,
                            titleFont: { size: 13, weight: 'bold' },
                bodyFont: { size: 12 },
                usePointStyle: true,
                    callbacks: {
                    footer: (tooltipItems) => {
                        let sum = 0;
                        tooltipItems.forEach(function (tooltipItem) {
                            sum += tooltipItem.parsed.y;
                        });
                        return 'Total Fleet: ' + sum;
                    }
                }
            }
        },
        scales: {
            y: {
                stacked: false,
                    beginAtZero: true,
                        grid: { borderDash: [5, 5], color: '#f3f4f6' },
                ticks: {
                    font: { weight: 'bold', size: 11 },
                    precision: 0
                }
            },
            x: {
                grid: { display: false },
                ticks: { font: { weight: 'bold', size: 11 } }
            }
        }
    }
            });
        }
    });



    // calendar
    document.addEventListener('DOMContentLoaded', function () {
        var calendarEl = document.getElementById('adminCalendar');
        var calendarEvents = <?php echo $calendarEventsJSON; ?>;

        var calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'dayGridMonth',
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,listWeek'
            },
            height: 'auto',
            dayMaxEvents: 3,
            moreLinkClick: 'popover',
            events: calendarEvents,
            editable: false,
            selectable: true,
            eventClick: function (info) {
                showActivityDetailsSide(info.event.id);
            },
            eventDidMount: function (info) {
                var el = info.el;
                var title = info.event.title || '';
                var propNo = info.event.extendedProps.property_no || '';
                var viewType = info.view.type;
                var timeText = info.timeText || ''; // Capture FullCalendar's formatted time

                // Force transparent background for non-badge look
                el.style.backgroundColor = 'transparent';
                el.style.border = 'none';
                el.style.boxShadow = 'none';

                // Remove default FullCalendar dots to prevent duplication
                el.querySelectorAll('.fc-daygrid-event-dot').forEach(dot => dot.remove());

                // Determine color based on activity type
                let dotColor = '#9ca3af'; // Default Gray
                if (title.includes('Inspection')) dotColor = '#10b981'; // Emerald
                else if (title.includes('Repair') || title.includes('Maintenance')) dotColor = '#f59e0b'; // Amber
                else if (title.includes('Appointment')) dotColor = '#3b82f6'; // Blue

                // Clean Title for display - only show Prop No if it actually exists
                let displayTitle = title;
                if ((viewType === 'listWeek' || el.closest('.fc-popover')) && propNo && propNo.trim() !== '') {
                    displayTitle = `${title} <span style="opacity: 0.6; font-size: 0.85em; font-weight: 500;">[#${propNo}]</span>`;
                }

                // Inject a clean, unified structure into the main event container
                // This replaces everything inside to ensure NO duplication
                const mainEl = el.querySelector('.fc-event-main') || el;
                mainEl.innerHTML = `
                    <div style="display: flex; align-items: center; gap: 6px; width: 100%; min-width: 0; padding: 2px 0;">
                        <span style="height: 7px; width: 7px; background-color: ${dotColor}; border-radius: 50%; flex-shrink: 0; display: inline-block; box-shadow: 0 0 3px ${dotColor}88;"></span>
                        ${timeText ? `<span style="font-size: 0.7rem; font-weight: 700; color: #6b7280; flex-shrink: 0; white-space: nowrap;">${timeText}</span>` : ''}
                        <span style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; flex-grow: 1; font-size: 0.75rem; font-weight: 600; color: #111827;">${displayTitle}</span>
                    </div>
                `;
            }
        });

        calendar.render();
    });

    // Function to show activity details in modal
    function showActivityDetailsSide(activityId) {
        // Close popovers
        document.querySelectorAll('.fc-popover').forEach(p => p.remove());

        // We use the same fetch endpoint as side_activities.php
        fetch('fetch_single_activity.php?id=' + activityId)
            .then(r => r.json())
            .then(data => {
                if (!data.id) return;

                // Populate modal
                document.getElementById('modal_id_badge').textContent = '#' + data.id;
                document.getElementById('modal_property_tag').textContent = data.property_no || 'N/A';
                document.getElementById('activity_type_side').value = data.activity_type || '';
                document.getElementById('property_no_display_side').value = data.property_no || '';
                document.getElementById('location_display_side').value = data.location || '';
                document.getElementById('activity_date_side').value = data.activity_date || '';
                document.getElementById('activity_time_side').value = data.activity_time || '';
                document.getElementById('activity_remarks_side').value = data.remarks || '';
                document.getElementById('modal_created_by').textContent = data.user_name || 'System';

                if (data.created_at) {
                    const date = new Date(data.created_at);
                    document.getElementById('modal_registered_on').textContent = date.toLocaleDateString('en-GB') + ' ' + date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                }

                var modal = new bootstrap.Modal(document.getElementById('viewActivityModalSide'));
                modal.show();
            })
            .catch(err => console.error('Error loading activity:', err));
    }



    function showTab(tabName) {
        // Hide all tabs
        document.querySelectorAll('.report-tab').forEach(el => el.classList.add('hidden'));

        // Reset buttons
        const btn1 = document.getElementById('preRepairBtn');
        const btn2 = document.getElementById('summaryBtn');

        // Default Styling
        const inactiveClass = "text-gray-700 hover:text-gray-900 hover:bg-white bg-transparent";
        const activeClass = "bg-white text-blue-700 shadow-sm ring-1 ring-gray-200";

        btn1.className = `px-4 py-2 text-sm font-medium rounded-md transition-all duration-200 ${inactiveClass}`;
        btn2.className = `px-4 py-2 text-sm font-medium rounded-md transition-all duration-200 ${inactiveClass}`;

        // Show selected tab and activate button
        if (tabName === 'preRepair') {
            document.getElementById('preRepairTab').classList.remove('hidden');
            btn1.className = `px-4 py-2 text-sm font-medium rounded-md transition-all duration-200 ${activeClass}`;
        } else {
            document.getElementById('summaryTab').classList.remove('hidden');
            btn2.className = `px-4 py-2 text-sm font-medium rounded-md transition-all duration-200 ${activeClass}`;
        }
    }

    // =======================
    // INVENTORY CHARTS
    // =======================
    const invStatusCtx = document.getElementById('inventoryStatusChart');
    const invTrendCtx = document.getElementById('inventoryTrendChart');

    // Status Distribution Chart
    if (invStatusCtx) {
        const statusLabels = ['Good', 'Low Stock', 'Worn Out', 'Out of Stock'];
        const statusData = [
            <?php echo $invStats['available']; ?>,
            <?php echo $invStats['low_stock']; ?>,
            <?php echo $invStats['worn_out']; ?>,
            <?php echo $invStats['out_of_stock']; ?>
        ];
        const statusColors = ['#10b981', '#f59e0b', '#ef4444', '#6b7280'];

        const total = statusData.reduce((a, b) => a + b, 0);
        const percentages = statusData.map(val => total > 0 ? ((val / total) * 100).toFixed(1) + '%' : '0%');

        new Chart(invStatusCtx, {
            type: 'doughnut',
            data: {
                labels: statusLabels,
                datasets: [{
                    data: statusData,
                    backgroundColor: statusColors,
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right',
                        labels: {
                            boxWidth: 12,
                            padding: 20,
                            font: { size: 12 },
                            usePointStyle: true,
                            pointStyle: 'circle'
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const value = context.raw;
                                const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                return `${context.label}: ${value} (${percentage}%)`;
                            }
                        }
                    }
                },
                cutout: '55%',
                layout: { padding: 20 }
            },
            plugins: [{
                id: 'centerText',
                beforeDraw: function (chart) {
                    if (total === 0) return;

                    const ctx = chart.ctx;
                    const centerX = (chart.chartArea.left + chart.chartArea.right) / 2;
                    const centerY = (chart.chartArea.top + chart.chartArea.bottom) / 2;

                    ctx.save();
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'middle';

                    ctx.font = 'bold 32px sans-serif';
                    ctx.fillStyle = '#1f2937';
                    ctx.fillText(total, centerX, centerY - 10);

                    ctx.font = '12px sans-serif';
                    ctx.fillStyle = '#6b7280';
                    ctx.fillText('Total Items', centerX, centerY + 15);

                    ctx.restore();
                }
            }, {
                id: 'percentageLabels',
                afterDraw: function (chart) {
                    const ctx = chart.ctx;
                    const meta = chart.getDatasetMeta(0);

                    if (total === 0) return;

                    meta.data.forEach(function (element, index) {
                        if (statusData[index] === 0) return;

                        const percentage = percentages[index];
                        const center = element.getCenterPoint();

                        ctx.save();
                        ctx.font = 'bold 11px sans-serif';
                        ctx.fillStyle = '#fff';
                        ctx.textAlign = 'center';
                        ctx.textBaseline = 'middle';

                        const value = statusData[index];
                        const slicePercentage = (value / total) * 100;

                        if (slicePercentage >= 5) {
                            ctx.fillText(percentage, center.x, center.y);
                        }

                        ctx.restore();
                    });
                }
            }]
        });
    }

    // Activity Trend Chart
    if (invTrendCtx) {
        const trendDates = <?php echo json_encode(array_map(function ($d) {
            return date('M d', strtotime($d));
        }, $activityData['dates'])); ?>;
        const issuedData = <?php echo json_encode(array_values($activityData['issued'])); ?>;
        const returnedData = <?php echo json_encode(array_values($activityData['returned'])); ?>;
        const addedData = <?php echo json_encode(array_values($activityData['added'])); ?>;

        new Chart(invTrendCtx, {
            type: 'line',
            data: {
                labels: trendDates,
                datasets: [
                    {
                        label: 'Borrowed',
                        data: issuedData,
                        borderColor: '#f97316',
                        backgroundColor: 'rgba(249, 115, 22, 0.1)',
                        fill: true,
                        tension: 0.4
                    },
                    {
                        label: 'Returned',
                        data: returnedData,
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        fill: true,
                        tension: 0.4
                    },
                    {
                        label: 'Added',
                        data: addedData,
                        borderColor: '#3b82f6',
                        backgroundColor: 'rgba(59, 130, 246, 0.1)',
                        fill: true,
                        tension: 0.4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: { boxWidth: 12, padding: 15, font: { size: 11 } }
                    }
                },
                scales: {
                    y: { beginAtZero: true, ticks: { stepSize: 1 } }
                }
            }
        });
    }

</script>

<!-- Rest of your existing JavaScript remains the same -->