<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../config.php';

// Start session safely if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// -----------------------------------------------------------
// 1. UPDATE: ROLE CHECK CHANGED TO 'staff'
// -----------------------------------------------------------
// We allow 'staff' here. If you want admins to view this too, use: in_array($_SESSION['role'], ['admin', 'staff'])
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'staff') {
    // Return a clean error message for the AJAX loader
    error_log("[" . date('Y-m-d H:i:s') . "] Unauthorized access attempt to staff side_dashboard.php");
    exit('<div class="p-4 text-red-600 bg-red-50 rounded-lg">Unauthorized access. Please log in with valid credentials.</div>');
}

// -----------------------------------------------------------
// 2. UPDATE: PATH CORRECTION (Go up one level)
// -----------------------------------------------------------
// Profile modal included after $currentUser is defined below

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

// Include profile modal after $currentUser is defined
if (file_exists('../profile_modal.php')) {
    include_once '../profile_modal.php';
}

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
$actQuery = $mysqli->prepare('
    SELECT id, activity_type, activity_date, activity_time, property_no, remarks
    FROM activities 
    WHERE location = ? 
    ORDER BY activity_date ASC, activity_time ASC
');

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
$stats_result = $categoryStatsQuery->get_result();
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

// Get document counts for the bar chart
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

?>

<style>
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

    /* Tailwind-ish customization for FullCalendar */
    .fc-toolbar-title {
        font-size: 1.25rem !important;
        font-weight: 700;
        color: #1f2937;
    }

    .fc-button-primary {
        background-color: #10b981 !important;
        border-color: #10b981 !important;
    }

    .fc-button-primary:hover {
        background-color: #059669 !important;
        border-color: #059669 !important;
    }

    .fc-daygrid-day-number {
        color: #4b5563;
        font-weight: 500;
        text-decoration: none !important;
    }

    .fc-col-header-cell-cushion {
        color: #6b7280;
        font-weight: 600;
        text-transform: uppercase;
        font-size: 0.75rem;
    }

    .fc-event {
        border: none;
        box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
    }
</style>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
    <!-- Equipment Status Chart - Staff View (User's Location Only) -->
    <div
        class="lg:col-span-2 bg-white rounded-2xl shadow-lg border border-slate-100 p-6 hover:shadow-xl transition-shadow duration-300">
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-6">
            <div class="flex items-center gap-3">
                <div class="p-3 rounded-xl bg-gradient-to-br from-violet-500 to-violet-600 shadow-lg">
                    <i class="fas fa-chart-line text-white text-xl"></i>
                </div>
                <div>
                    <h3 class="text-lg font-bold text-slate-800">Equipment Status Overview</h3>
                    <p class="text-sm text-slate-500">Equipment status for <span
                            class="font-semibold text-violet-700"><?php echo htmlspecialchars($userLocation); ?></span>
                    </p>
                </div>
            </div>
        </div>

        <div class="chart-container h-[300px]">
            <canvas id="equipmentStatusChartBar"></canvas>
        </div>

        <div class="mt-4 pt-4 border-t border-slate-100 flex justify-center gap-6">
            <div class="flex items-center gap-2">
                <span class="w-3 h-3 rounded-full bg-emerald-500"></span>
                <span class="text-xs font-semibold text-slate-600">Operational</span>
            </div>
            <div class="flex items-center gap-2">
                <span class="w-3 h-3 rounded-full bg-amber-500"></span>
                <span class="text-xs font-semibold text-slate-600">Under Repair</span>
            </div>
            <div class="flex items-center gap-2">
                <span class="w-3 h-3 rounded-full bg-red-500"></span>
                <span class="text-xs font-semibold text-slate-600">Unserviceable</span>
            </div>
        </div>
    </div>

    <div
        class="bg-white rounded-2xl shadow-lg border border-slate-100 p-6 hover:shadow-xl transition-shadow duration-300">
        <div class="flex items-center justify-between mb-6">
            <div class="flex items-center gap-3">
                <div class="p-3 rounded-xl bg-gradient-to-br from-teal-500 to-teal-600 shadow-lg">
                    <i class="fas fa-chart-pie text-white text-lg"></i>
                </div>
                <div>
                    <h3 class="text-lg font-bold text-slate-800">Equipment Distribution</h3>
                    <p class="text-xs text-slate-500">Volume by location</p>
                </div>
            </div>
            <div class="text-right">
                <span class="text-3xl font-black text-slate-800"><?php echo $totalFleet; ?></span>
                <p class="text-xs text-slate-400 font-semibold uppercase">Total Units</p>
            </div>
        </div>

        <div class="space-y-5">
            <?php foreach ($allLocations as $loc):
                $data = $locationStats[$loc] ?? ['total' => 0, 'operational' => 0, 'repair' => 0, 'unserviceable' => 0];
                $total = $data['total'];
                $widthRelativeToMax = $maxTotal > 0 ? ($total / $maxTotal) * 100 : 0;
                $opPct = $total > 0 ? ($data['operational'] / $total) * 100 : 0;
                $repPct = $total > 0 ? ($data['repair'] / $total) * 100 : 0;
                $unsPct = $total > 0 ? ($data['unserviceable'] / $total) * 100 : 0;
                $globalShare = $totalFleet > 0 ? round(($total / $totalFleet) * 100) : 0;
                $isActive = ($loc === $userLocation);
                ?>
                <div class="group <?= $isActive ? 'bg-violet-50 -mx-3 px-3 rounded-xl' : '' ?>">
                    <div class="flex justify-between items-end mb-2">
                        <div class="flex items-center gap-2">
                            <span class="font-bold text-slate-700"><?php echo $loc; ?></span>
                            <?php if ($isActive): ?>
                                <span
                                    class="text-[10px] px-2 py-0.5 rounded-full bg-violet-200 text-violet-700 font-semibold">Your
                                    Location</span>
                            <?php else: ?>
                                <span
                                    class="text-[10px] px-1.5 py-0.5 rounded bg-slate-100 text-slate-500 font-medium"><?php echo $globalShare; ?>%</span>
                            <?php endif; ?>
                        </div>
                        <div class="text-sm font-bold text-slate-800"><?php echo $total; ?> <span
                                class="text-slate-400 text-xs font-normal">units</span></div>
                    </div>
                    <div class="w-full bg-slate-100 rounded-full h-4 p-0.5 relative">
                        <div class="h-full rounded-full bg-gradient-to-r from-emerald-500 to-green-500 transition-all duration-1000 ease-out"
                            style="width: <?php echo max($widthRelativeToMax, 2); ?>%"></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Legend -->
        <div class="flex justify-center gap-4 mt-6 pt-4 border-t border-slate-100">
            <span class="flex items-center gap-1.5 text-xs text-slate-500"><span
                    class="w-2.5 h-2.5 rounded-full bg-green-500"></span>Total Equipment</span>
        </div>
    </div>
</div>

<!-- Inventory Stats Cards -->
<div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-8">
    <?php
    $totalItems = $invStats['total'] ?: 1;
    $invStatsList = [
        ['Total Items', $invStats['total'], 'blue', 'fa-boxes', '100%'],
        ['Available', $invStats['available'], 'emerald', 'fa-check-circle', round(($invStats['available'] / $totalItems) * 100) . '%'],
        ['Borrowed', $invStats['borrowed'], 'orange', 'fa-arrow-up', round(($invStats['borrowed'] / $totalItems) * 100) . '%'],
        ['Low Stock', $invStats['low_stock'], 'amber', 'fa-exclamation-triangle', round(($invStats['low_stock'] / $totalItems) * 100) . '%'],
        ['Worn Out', $invStats['worn_out'], 'rose', 'fa-trash-alt', round(($invStats['worn_out'] / $totalItems) * 100) . '%']
    ];
    foreach ($invStatsList as $index => $stat):
        $isZero = $stat[1] == 0;
        $mobileWider = ($stat[0] === 'Worn Out') ? 'col-span-2 md:col-span-1' : '';
        ?>
        <div
            class="inv-stat-card group relative bg-white rounded-2xl shadow-md hover:shadow-xl transition-all duration-300 border border-slate-100 overflow-hidden <?= $isZero ? 'opacity-60' : '' ?> <?= $mobileWider ?>">
            <div
                class="absolute top-0 right-0 w-20 h-20 bg-gradient-to-br from-<?= $stat[2] ?>-100 to-transparent rounded-bl-full opacity-50">
            </div>
            <div class="p-4">
                <div class="flex items-center justify-between mb-2">
                    <div
                        class="p-2 rounded-lg bg-<?= $stat[2] ?>-100 text-<?= $stat[2] ?>-600 group-hover:scale-110 transition-transform duration-300">
                        <i class="fas <?= $stat[3] ?> text-lg"></i>
                    </div>
                    <span
                        class="text-xs font-semibold text-<?= $stat[2] ?>-600 bg-<?= $stat[2] ?>-50 px-2 py-1 rounded-full"><?= $stat[4] ?></span>
                </div>
                <h4 class="text-2xl font-black text-slate-800 mb-1"><?= $stat[1] ?></h4>
                <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider"><?= $stat[0] ?></p>
            </div>
            <div class="h-1 bg-gradient-to-r from-<?= $stat[2] ?>-400 to-<?= $stat[2] ?>-600"></div>
        </div>
    <?php endforeach; ?>
</div>

<!-- Inventory Analytics Charts -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
    <div
        class="bg-white rounded-2xl shadow-lg border border-slate-100 p-6 hover:shadow-xl transition-shadow duration-300">
        <div class="flex items-center justify-between mb-4">
            <div class="flex items-center gap-2">
                <div class="p-2 rounded-lg bg-purple-100">
                    <i class="fas fa-chart-pie text-purple-600"></i>
                </div>
                <h3 class="text-lg font-bold text-slate-800">Inventory Status</h3>
            </div>
            <span class="text-xs font-medium text-slate-400 bg-slate-50 px-3 py-1 rounded-full">Distribution</span>
        </div>
        <div class="h-[280px] flex items-center justify-center">
            <canvas id="inventoryStatusChart"></canvas>
        </div>
    </div>

    <div
        class="lg:col-span-2 bg-white rounded-2xl shadow-lg border border-slate-100 p-6 hover:shadow-xl transition-shadow duration-300">
        <div class="flex items-center justify-between mb-4">
            <div class="flex items-center gap-2">
                <div class="p-2 rounded-lg bg-indigo-100">
                    <i class="fas fa-chart-line text-indigo-600"></i>
                </div>
                <div>
                    <h3 class="text-lg font-bold text-slate-800">Activity Trend</h3>
                    <p class="text-xs text-slate-400">Last 7 days inventory movements</p>
                </div>
            </div>
            <div class="flex gap-2">
                <span class="flex items-center gap-1 text-xs text-slate-500"><span
                        class="w-2 h-2 rounded-full bg-orange-500"></span>Borrowed</span>
                <span class="flex items-center gap-1 text-xs text-slate-500"><span
                        class="w-2 h-2 rounded-full bg-emerald-500"></span>Returned</span>
                <span class="flex items-center gap-1 text-xs text-slate-500"><span
                        class="w-2 h-2 rounded-full bg-blue-500"></span>Added</span>
            </div>
        </div>
        <div class="h-[250px]">
            <canvas id="inventoryTrendChart"></canvas>
        </div>
    </div>
</div>

<!-- Inventory Recent Activity & Categories -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
    <div
        class="bg-white rounded-2xl shadow-lg border border-slate-100 p-6 hover:shadow-xl transition-shadow duration-300">
        <div class="flex items-center justify-between mb-4">
            <div class="flex items-center gap-2">
                <div class="p-2 rounded-lg bg-cyan-100">
                    <i class="fas fa-history text-cyan-600"></i>
                </div>
                <h3 class="text-lg font-bold text-slate-800">Recent Activity</h3>
            </div>
            <span class="text-xs font-medium text-slate-400 bg-slate-50 px-3 py-1 rounded-full">Last 5</span>
        </div>
        <div class="space-y-3 max-h-[280px] overflow-y-auto pr-2 custom-scrollbar">
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
                        default => 'bg-slate-100 text-slate-600 border-slate-200'
                    };
                    ?>
                    <div
                        class="flex items-center gap-3 p-3 rounded-xl bg-slate-50 hover:bg-slate-100 transition-colors duration-200 border border-slate-100">
                        <div class="p-2 rounded-lg <?php echo $actionColor; ?> border">
                            <i class="fas <?php echo $actionIcon; ?>"></i>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-semibold text-slate-800 truncate">
                                <?php echo htmlspecialchars($log['item_name']); ?>
                            </p>
                            <p class="text-xs text-slate-500">
                                <span
                                    class="font-medium <?php echo strtolower($log['action_type']) === 'added' ? 'text-blue-600' : (strtolower($log['action_type']) === 'issued' ? 'text-orange-600' : (strtolower($log['action_type']) === 'returned' ? 'text-emerald-600' : 'text-slate-600')); ?>"><?php echo htmlspecialchars($log['action_type']); ?></span>
                                by <?php echo htmlspecialchars($log['performed_by']); ?>
                            </p>
                        </div>
                        <span
                            class="text-xs text-slate-400 whitespace-nowrap"><?php echo date('h:i A', strtotime($log['date_time'])); ?></span>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="text-center py-10 text-slate-400">
                    <div class="w-14 h-14 mx-auto mb-3 rounded-full bg-slate-100 flex items-center justify-center">
                        <i class="fas fa-inbox text-2xl"></i>
                    </div>
                    <p class="text-sm font-medium">No recent activity</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div
        class="bg-white rounded-2xl shadow-lg border border-slate-100 p-6 hover:shadow-xl transition-shadow duration-300">
        <div class="flex items-center justify-between mb-4">
            <div class="flex items-center gap-2">
                <div class="p-2 rounded-lg bg-pink-100">
                    <i class="fas fa-tags text-pink-600"></i>
                </div>
                <h3 class="text-lg font-bold text-slate-800">Category Breakdown</h3>
            </div>
            <span
                class="text-xs font-medium text-slate-400 bg-slate-50 px-3 py-1 rounded-full"><?php echo count($invCategories); ?>
                Categories</span>
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
                            <span class="text-sm font-semibold text-slate-700 flex items-center gap-2">
                                <span
                                    class="w-2 h-2 rounded-full bg-<?php echo explode(' ', $colors[$colorIndex])[1]; ?>"></span>
                                <?php echo htmlspecialchars($cat['category']); ?>
                            </span>
                            <div class="text-right">
                                <span class="text-sm font-bold text-slate-800"><?php echo $cat['count']; ?></span>
                                <span class="text-xs text-slate-400 ml-1">(<?php echo $percentage; ?>%)</span>
                            </div>
                        </div>
                        <div class="h-2.5 bg-slate-100 rounded-full overflow-hidden">
                            <div class="h-full bg-gradient-to-r <?php echo $colors[$colorIndex]; ?> rounded-full transition-all duration-500 group-hover:shadow-lg"
                                style="width: <?php echo $percentage; ?>%"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="text-center py-8 text-slate-400">
                    <i class="fas fa-tags text-3xl mb-2"></i>
                    <p class="text-sm">No categories found</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div
    class="bg-white rounded-2xl shadow-lg border border-slate-100 p-6 mb-8 hover:shadow-xl transition-shadow duration-300">
    <div class="flex flex-col md:flex-row gap-6">
        <div class="flex-grow">
            <div class="flex items-center justify-between mb-5">
                <div class="flex items-center gap-3">
                    <div class="p-3 rounded-xl bg-gradient-to-br from-cyan-500 to-cyan-600 shadow-lg">
                        <i class="fas fa-calendar-alt text-white text-lg"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-bold text-slate-800">Schedule Calendar</h3>
                        <p class="text-xs text-slate-500">Activities in <?php echo htmlspecialchars($userLocation); ?>
                        </p>
                    </div>
                </div>
                <div class="hidden md:flex gap-3 text-xs font-medium text-slate-600">
                    <div class="flex items-center"><span
                            class="w-2.5 h-2.5 rounded-full bg-emerald-500 mr-1.5"></span>Inspection</div>
                    <div class="flex items-center"><span
                            class="w-2.5 h-2.5 rounded-full bg-amber-500 mr-1.5"></span>Repair</div>
                    <div class="flex items-center"><span
                            class="w-2.5 h-2.5 rounded-full bg-blue-500 mr-1.5"></span>Appointment</div>
                </div>
            </div>
            <div id="adminCalendar" class="min-h-[400px] text-sm font-medium text-slate-600"></div>
        </div>

        <div class="w-full md:w-80 border-l border-slate-100 md:pl-6 pt-6 md:pt-0">
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center gap-2">
                    <div class="p-2 rounded-lg bg-violet-100">
                        <i class="fas fa-clock text-violet-600 text-sm"></i>
                    </div>
                    <h4 class="font-bold text-slate-800">Next 7 Days</h4>
                </div>
                <span
                    class="text-xs font-semibold bg-violet-100 text-violet-700 px-2 py-1 rounded-full"><?php echo count($upcomingEvents); ?>
                    Upcoming</span>
            </div>
            <div class="space-y-3 max-h-[600px] overflow-y-auto pr-2 custom-scrollbar">
                <?php if (empty($upcomingEvents)): ?>
                    <div class="text-center py-10 bg-slate-50 rounded-xl border-2 border-dashed border-slate-200">
                        <div class="w-14 h-14 mx-auto mb-3 rounded-full bg-slate-100 flex items-center justify-center">
                            <i class="fas fa-calendar-check text-2xl text-slate-300"></i>
                        </div>
                        <p class="text-sm text-slate-500 font-medium">No upcoming events</p>
                        <p class="text-xs text-slate-400 mt-1">Schedule activities to see them here</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($upcomingEvents as $event):
                        $dateObj = new DateTime($event['activity_date']);
                        $isToday = $event['activity_date'] === $today;
                        ?>
                        <div
                            class="group flex gap-3 p-4 rounded-xl border border-slate-100 hover:border-violet-200 hover:bg-violet-50/30 transition-all duration-200">
                            <div class="flex-shrink-0 w-12 text-center">
                                <div class="text-xs font-bold text-slate-400 uppercase tracking-wider">
                                    <?php echo $dateObj->format('M'); ?></div>
                                <div class="text-2xl font-black <?php echo $isToday ? 'text-violet-600' : 'text-slate-700'; ?>">
                                    <?php echo $dateObj->format('d'); ?></div>
                                <?php if ($isToday): ?>
                                    <span
                                        class="text-[9px] font-bold text-violet-600 bg-violet-100 px-1.5 py-0.5 rounded-full">Today</span>
                                <?php endif; ?>
                            </div>
                            <div class="flex-grow">
                                <h5 class="text-sm font-bold text-slate-800 group-hover:text-violet-700 transition-colors">
                                    <?php echo htmlspecialchars($event['activity_type']); ?></h5>
                                <div class="flex items-center text-xs text-slate-500 mt-1.5">
                                    <i class="far fa-clock mr-1.5 text-slate-400"></i>
                                    <?php echo date('h:i A', strtotime($event['activity_time'])); ?>
                                </div>
                                <div class="text-xs text-slate-400 mt-1 truncate max-w-[160px]"
                                    title="<?php echo htmlspecialchars($event['property_no']); ?>">
                                    <i class="fas fa-tag mr-1"></i>#<?php echo htmlspecialchars($event['property_no']); ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div
    class="bg-white rounded-2xl shadow-lg border border-slate-100 p-6 mb-8 hover:shadow-xl transition-shadow duration-300">
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-6">
        <div class="flex items-center gap-3">
            <div class="p-3 rounded-xl bg-gradient-to-br from-indigo-500 to-indigo-600 shadow-lg">
                <i class="fas fa-tools text-white text-lg"></i>
            </div>
            <div>
                <h2 class="text-xl font-bold text-slate-800">Equipment Status Report</h2>
                <p class="text-sm text-slate-500">Comprehensive overview of all equipment status</p>
            </div>
        </div>
        <div class="flex bg-slate-100 p-1.5 rounded-xl">
            <button id="preRepairBtn"
                class="px-4 py-2 text-sm font-semibold rounded-lg bg-white text-indigo-700 shadow-sm transition-all duration-200 hover:scale-105"
                onclick="showTab('preRepair')">
                <i class="fas fa-list mr-1"></i> Pre-Repair
            </button>
            <button id="summaryBtn"
                class="px-4 py-2 text-sm font-semibold rounded-lg text-slate-500 hover:text-slate-700 transition-all duration-200"
                onclick="showTab('summary')">
                <i class="fas fa-map-marker-alt mr-1"></i> Summary
            </button>
        </div>
    </div>

    <div id="preRepairTab" class="report-tab">
        <div class="overflow-x-auto rounded-lg border border-gray-200">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">#
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Equipment</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Units
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Operational</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Under
                            Repair</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Unserviceable</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (!empty($categoryStats)):
                        $row_number = 1;
                        foreach ($categoryStats as $row):
                            $units = $row['units'];
                            $opPct = $units > 0 ? round(($row['operational'] / $units) * 100) : 0;
                            $repPct = $units > 0 ? round(($row['under_repair'] / $units) * 100) : 0;
                            $unsPct = $units > 0 ? round(($row['unserviceable'] / $units) * 100) : 0;
                            ?>
                            <tr class="hover:bg-blue-50/50 transition-colors duration-200">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-500">
                                    <?php echo $row_number++; ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900">
                                    <?php echo htmlspecialchars($row['equipment_name']); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900"><?php echo $units; ?>
                                    Units</td>
                                <td class="px-6 py-4 whitespace-nowrap text-xs">
                                    <span class="font-semibold text-green-700"><?php echo $row['operational']; ?></span> <span
                                        class="text-gray-400"><?php echo $opPct; ?>%</span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-xs">
                                    <span class="font-semibold text-amber-700"><?php echo $row['under_repair']; ?></span> <span
                                        class="text-gray-400"><?php echo $repPct; ?>%</span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-xs">
                                    <span class="font-semibold text-red-700"><?php echo $row['unserviceable']; ?></span> <span
                                        class="text-gray-400"><?php echo $unsPct; ?>%</span>
                                </td>
                            </tr>
                        <?php endforeach; else: ?>
                        <tr>
                            <td colspan="6" class="px-6 py-10 text-center text-gray-500">No data found.</td>
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
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Location</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Operational</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Under
                            Repair</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Unserviceable</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (!empty($locationStatsArray)):
                        foreach ($locationStatsArray as $row):
                            $isCurrentLoc = ($row['location_name'] === $userLocation);
                            $rowClass = $isCurrentLoc ? 'bg-blue-50 border-l-4 border-blue-500' : 'hover:bg-gray-50';
                            ?>
                            <tr class="<?php echo $rowClass; ?> transition-all duration-200">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900">
                                    <?php echo htmlspecialchars($row['location_name']); ?>
                                    <?php if ($isCurrentLoc): ?><span class="ml-2 text-[10px] text-blue-600 font-medium">Your
                                            Location</span><?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900">
                                    <?php echo $row['units']; ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-xs font-semibold text-green-700">
                                    <?php echo $row['operational']; ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-xs font-semibold text-amber-700">
                                    <?php echo $row['under_repair']; ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-xs font-semibold text-red-700">
                                    <?php echo $row['unserviceable']; ?></td>
                            </tr>
                        <?php endforeach; else: ?>
                        <tr>
                            <td colspan="5" class="text-center py-6 text-gray-500">No location data.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    (function () {
        // --- 1. EQUIPMENT STATUS OVERVIEW (Doughnut Chart) ---
        const equipStatusCtx = document.getElementById('equipmentStatusChartBar');
        if (equipStatusCtx) {
            const totalEquip = <?php echo $totalEquip; ?>;
            const statusValues = [<?php echo $operationalCount; ?>, <?php echo $repairCount; ?>, <?php echo $unserviceableCount; ?>];
            const percentages = statusValues.map(v => totalEquip > 0 ? Math.round((v / totalEquip) * 100) : 0);
            
            new Chart(equipStatusCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Operational', 'Under Repair', 'Unserviceable'],
                    datasets: [{
                        data: statusValues,
                        backgroundColor: ['#10b981', '#f59e0b', '#ef4444'],
                        borderWidth: 0,
                        hoverOffset: 10
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '55%',
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const value = context.raw;
                                    const pct = totalEquip > 0 ? ((value / totalEquip) * 100).toFixed(1) : 0;
                                    return `${context.label}: ${value} (${pct}%)`;
                                }
                            }
                        }
                    }
                },
                plugins: [{
                    id: 'equipStatusCenterText',
                    beforeDraw: function(chart) {
                        if (totalEquip === 0) return;
                        
                        const ctx = chart.ctx;
                        const centerX = (chart.chartArea.left + chart.chartArea.right) / 2;
                        const centerY = (chart.chartArea.top + chart.chartArea.bottom) / 2;
                        
                        ctx.save();
                        ctx.textAlign = 'center';
                        ctx.textBaseline = 'middle';
                        ctx.font = 'bold 28px sans-serif';
                        ctx.fillStyle = '#1f2937';
                        ctx.fillText(totalEquip, centerX, centerY - 6);
                        ctx.font = '11px sans-serif';
                        ctx.fillStyle = '#6b7280';
                        ctx.fillText('Total', centerX, centerY + 14);
                        ctx.restore();
                    }
                }, {
                    id: 'percentageLabels',
                    afterDraw: function(chart) {
                        if (totalEquip === 0) return;
                        
                        const ctx = chart.ctx;
                        const meta = chart.getDatasetMeta(0);
                        
                        meta.data.forEach(function(element, index) {
                            const percentage = percentages[index];
                            if (percentage < 5) return;
                            
                            const center = element.getCenterPoint();
                            
                            ctx.save();
                            ctx.font = 'bold 12px sans-serif';
                            ctx.fillStyle = '#fff';
                            ctx.textAlign = 'center';
                            ctx.textBaseline = 'middle';
                            ctx.fillText(percentage + '%', center.x, center.y);
                            ctx.restore();
                        });
                    }
                }]
            });
        }

        // --- 3. INVENTORY STATUS CHART ---
        const invStatusCtx = document.getElementById('inventoryStatusChart');
        if (invStatusCtx) {
            const invTotal = <?php echo $invStats['total']; ?>;
            const invStatusValues = [
                <?php echo $invStats['available']; ?>,
                <?php echo $invStats['low_stock']; ?>,
                <?php echo $invStats['worn_out']; ?>,
                <?php echo $invStats['out_of_stock']; ?>
            ];
            const invPercentages = invStatusValues.map(v => invTotal > 0 ? Math.round((v / invTotal) * 100) : 0);

            new Chart(invStatusCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Available', 'Low Stock', 'Worn Out', 'Out of Stock'],
                    datasets: [{
                        data: invStatusValues,
                        backgroundColor: ['#10b981', '#f59e0b', '#ef4444', '#6b7280'],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'right', labels: { boxWidth: 10, padding: 15, font: { size: 11 } } },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const value = context.raw;
                                    const pct = invTotal > 0 ? ((value / invTotal) * 100).toFixed(1) : 0;
                                    return `${context.label}: ${value} (${pct}%)`;
                                }
                            }
                        }
                    },
                    cutout: '55%'
                },
                plugins: [{
                    id: 'invPercentageLabels',
                    afterDraw: function(chart) {
                        if (invTotal === 0) return;
                        
                        const ctx = chart.ctx;
                        const meta = chart.getDatasetMeta(0);
                        
                        meta.data.forEach(function(element, index) {
                            const percentage = invPercentages[index];
                            if (percentage < 5) return;
                            
                            const center = element.getCenterPoint();
                            
                            ctx.save();
                            ctx.font = 'bold 11px sans-serif';
                            ctx.fillStyle = '#fff';
                            ctx.textAlign = 'center';
                            ctx.textBaseline = 'middle';
                            ctx.fillText(percentage + '%', center.x, center.y);
                            ctx.restore();
                        });
                    }
                }]
            });
        }

        // --- 3. INVENTORY TREND CHART ---
        const invTrendCtx = document.getElementById('inventoryTrendChart');
        if (invTrendCtx) {
            const trendDates = <?php echo json_encode(array_map(function ($d) {
                return date('M d', strtotime($d)); }, $activityData['dates'])); ?>;
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
                        legend: { display: false }
                    },
                    scales: {
                        y: { beginAtZero: true, ticks: { stepSize: 1 } }
                    }
                }
            });
        }

        // --- 4. TAB SWITCHING FUNCTION ---
        window.showTab = function (tabName) {
            document.querySelectorAll('.report-tab').forEach(el => el.classList.add('hidden'));

            const btn1 = document.getElementById('preRepairBtn');
            const btn2 = document.getElementById('summaryBtn');
            const inactiveClass = "text-gray-700 hover:text-gray-900 bg-transparent";
            const activeClass = "bg-white text-blue-700 shadow-sm ring-1 ring-gray-200";

            // Reset
            if (btn1) btn1.className = `px-4 py-2 text-sm font-medium rounded-md transition-all duration-200 ${inactiveClass}`;
            if (btn2) btn2.className = `px-4 py-2 text-sm font-medium rounded-md transition-all duration-200 ${inactiveClass}`;

            if (tabName === 'preRepair') {
                const tab = document.getElementById('preRepairTab');
                if (tab) tab.classList.remove('hidden');
                if (btn1) btn1.className = `px-4 py-2 text-sm font-medium rounded-md transition-all duration-200 ${activeClass}`;
            } else {
                const tab = document.getElementById('summaryTab');
                if (tab) tab.classList.remove('hidden');
                if (btn2) btn2.className = `px-4 py-2 text-sm font-medium rounded-md transition-all duration-200 ${activeClass}`;
            }
        };

        // --- 5. CALENDAR INITIALIZATION ---
        var calendarEl = document.getElementById('adminCalendar');
        if (calendarEl) {
            var calendarEvents = <?php echo $calendarEventsJSON; ?>;
            var calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,listWeek'
                },
                height: 'auto',
                events: calendarEvents,
                selectable: true,
                eventClick: function (info) {
                    const props = info.event.extendedProps;
                    const remarks = props.remarks ? `\nRemarks: ${props.remarks}` : '';
                    alert(`Activity: ${info.event.title}\nTime: ${props.time}${remarks}`);
                }
            });
            calendar.render();
        }

    })(); // End IIFE
</script>

<?php
// End of side_dashboard.php layout
?>