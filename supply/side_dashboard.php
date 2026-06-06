<?php
// Supply Dashboard - Side Content
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/session_helper.php';
start_user_session();

// Security Check - Only allow supply role
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'supply') {
    header('Location: ../index.php?login');
    exit();
}

// Include profile modal
include_once __DIR__ . '/../profile_modal.php';

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

// Get supply request counts for the bar chart
$docCountQuery = $mysqli->prepare('
    SELECT 
        status,
        COUNT(*) as count
    FROM supply_requests 
    WHERE supply_location = ?
    GROUP BY status
');
$docCountQuery->bind_param('s', $userLocation);
$docCountQuery->execute();
$docCountResult = $docCountQuery->get_result();

$documentStats = [
    'Pending' => 0,
    'Approved' => 0,
    'Complied' => 0,
    'Received' => 0
];

$totalDocs = 0;
while ($row = $docCountResult->fetch_assoc()) {
    $status = ucfirst(strtolower($row['status']));
    if (array_key_exists($status, $documentStats)) {
        $documentStats[$status] = (int) $row['count'];
    }
    $totalDocs += $row['count'];
}
$docCountQuery->close();

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

$_SESSION['user_location'] = $userLocation;
$view = $_GET['view'] ?? 'dashboard';

?>

<style>
    :root {
        --bs-zindex: 1160;
        --bs-backdrop-zindex: 1150;
    }

    .modal-backdrop {
        z-index: var(--bs-backdrop-zindex) !important;
    }

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

    .stat-card-interactive {
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .stat-card-interactive:hover {
        transform: translateY(-4px) scale(1.02);
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
    }

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
</style>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdn.tailwindcss.com"></script>

<?php if ($view === 'dashboard'): ?>
    <!-- Inventory Stats Summary -->
    <div class="mb-8">
        <div class="flex items-center justify-between mb-6">
            <div class="flex items-center gap-3">
                <div class="p-3 rounded-xl bg-gradient-to-br from-blue-600 to-blue-700 shadow-lg">
                    <i class="fas fa-boxes-stacked text-white text-xl"></i>
                </div>
                <div>
                    <h3 class="text-xl font-bold text-gray-900">Inventory Analytics</h3>
                    <p class="text-sm text-gray-500"><?php echo htmlspecialchars($userLocation); ?></p>
                </div>
            </div>
            <div class="text-right">
                <p class="text-xs text-gray-400 uppercase tracking-wide">Last updated</p>
                <p class="text-sm font-semibold text-gray-600"><?php echo date('M d, Y h:i A'); ?></p>
            </div>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
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
                    class="group relative bg-white rounded-2xl shadow-md hover:shadow-xl transition-all duration-300 border border-gray-100 overflow-hidden <?= $isZero ? 'opacity-60' : '' ?>">
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
                                class="text-xs font-semibold text-<?= $stat[2] ?>-600 bg-<?= $stat[2] ?>-50 px-2 py-1 rounded-full"><?= $stat[4] ?></span>
                        </div>
                        <h4 class="text-3xl font-black text-gray-900 mb-1"><?= $stat[1] ?></h4>
                        <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider"><?= $stat[0] ?></p>
                    </div>
                    <div class="h-1 bg-gradient-to-r from-<?= $stat[2] ?>-400 to-<?= $stat[2] ?>-600"></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Inventory Analytics Charts -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
        <!-- Status Distribution Chart -->
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

        <!-- Activity Trend Chart -->
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

    <!-- Recent Activity & Top Categories -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
        <!-- Recent Activity -->
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
                                        class="font-medium text-<?php echo strtolower($log['action_type']) === 'added' ? 'blue' : (strtolower($log['action_type']) === 'issued' ? 'orange' : (strtolower($log['action_type']) === 'returned' ? 'emerald' : 'gray')); ?>-600"><?php echo htmlspecialchars($log['action_type']); ?></span>
                                    by <?php echo htmlspecialchars($log['performed_by']); ?>
                                </p>
                            </div>
                            <span
                                class="text-xs text-gray-400 whitespace-nowrap"><?php echo date('h:i A', strtotime($log['date_time'])); ?></span>
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

        <!-- Category Breakdown -->
        <div
            class="bg-white rounded-2xl shadow-lg border border-gray-100 p-6 hover:shadow-xl transition-shadow duration-300">
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center gap-2">
                    <div class="p-2 rounded-lg bg-pink-100">
                        <i class="fas fa-tags text-pink-600"></i>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900">Category Breakdown</h3>
                </div>
                <span
                    class="text-xs font-medium text-gray-400 bg-gray-50 px-3 py-1 rounded-full"><?php echo count($invCategories); ?>
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
                                <span class="text-sm font-semibold text-gray-700 flex items-center gap-2">
                                    <span
                                        class="w-2 h-2 rounded-full bg-<?php echo explode(' ', $colors[$colorIndex])[1]; ?>"></span>
                                    <?php echo htmlspecialchars($cat['category']); ?>
                                </span>
                                <div class="text-right">
                                    <span class="text-sm font-bold text-gray-900"><?php echo $cat['count']; ?></span>
                                    <span class="text-xs text-gray-400 ml-1">(<?php echo $percentage; ?>%)</span>
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



    <!-- Supply Request Row -->
    <div
        class="bg-white rounded-2xl shadow-lg border border-gray-100 p-8 mb-8 hover:shadow-xl transition-shadow duration-300">
        <div class="flex items-center justify-between mb-8 pb-6 border-b border-gray-100">
            <div class="flex items-center gap-3">
                <div class="p-3 rounded-xl bg-gradient-to-br from-violet-500 to-violet-600 shadow-lg">
                    <i class="fas fa-file-signature text-white text-xl"></i>
                </div>
                <div>
                    <h3 class="text-xl font-black text-gray-900">Supply Request Status</h3>
                    <p class="text-sm text-gray-500">Overview of all supply requests</p>
                </div>
            </div>
            <div class="flex bg-gray-100 p-1.5 rounded-xl">
                <button id="barChartBtn"
                    class="px-5 py-2.5 text-xs font-black uppercase rounded-lg bg-white text-violet-700 shadow-sm transition-all duration-200 hover:scale-105"
                    onclick="showBarChart()">
                    <i class="fas fa-chart-bar mr-1"></i> Bar
                </button>
                <button id="pieChartBtn"
                    class="px-5 py-2.5 text-xs font-black uppercase rounded-lg text-gray-500 hover:text-gray-700 transition-all duration-200"
                    onclick="showPieChart()">
                    <i class="fas fa-chart-pie mr-1"></i> Pie
                </button>
            </div>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
            <?php
            $totalDocs = array_sum($documentStats);
            $supplyItems = [
                ['Pending', $documentStats['Pending'], 'amber', 'fa-clock', round(($documentStats['Pending'] / ($totalDocs ?: 1)) * 100) . '%'],
                ['Approved', $documentStats['Approved'], 'sky', 'fa-check-double', round(($documentStats['Approved'] / ($totalDocs ?: 1)) * 100) . '%'],
                ['Complied', $documentStats['Complied'], 'emerald', 'fa-circle-check', round(($documentStats['Complied'] / ($totalDocs ?: 1)) * 100) . '%'],
                ['Received', $documentStats['Received'], 'slate', 'fa-box-archive', round(($documentStats['Received'] / ($totalDocs ?: 1)) * 100) . '%']
            ];
            foreach ($supplyItems as $item):
                $isZero = $item[1] == 0;
                ?>
                <div
                    class="group relative p-5 rounded-2xl border-2 border-gray-100 hover:border-<?= $item[2] ?>-300 transition-all duration-300 <?= $isZero ? 'opacity-50' : 'bg-gradient-to-br from-' . $item[2] . '-50 to-white' ?>">
                    <div
                        class="absolute top-0 right-0 w-16 h-16 bg-gradient-to-br from-<?= $item[2] ?>-200 to-transparent rounded-bl-full opacity-30">
                    </div>
                    <div class="flex items-center justify-between mb-3">
                        <div
                            class="p-2.5 rounded-xl bg-<?= $item[2] ?>-100 text-<?= $item[2] ?>-600 group-hover:scale-110 transition-transform duration-300">
                            <i class="fas <?= $item[3] ?>"></i>
                        </div>
                        <span
                            class="text-xs font-bold text-<?= $item[2] ?>-600 bg-white px-2 py-1 rounded-full shadow-sm"><?= $item[4] ?></span>
                    </div>
                    <div class="text-3xl font-black text-gray-900 mb-1"><?= $item[1] ?></div>
                    <p class="text-xs font-bold text-gray-400 uppercase tracking-wider"><?= $item[0] ?></p>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="relative">
            <div class="absolute inset-0 bg-gradient-to-t from-white via-transparent to-transparent z-10 pointer-events-none rounded-b-2xl"
                style="bottom: -20px;"></div>
            <div class="h-[380px] relative">
                <canvas id="documentChart"></canvas>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Equipment Status Report -->
<div
    class="bg-white rounded-2xl shadow-lg border border-gray-100 p-6 mb-8 hover:shadow-xl transition-shadow duration-300">
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-3">
            <div class="p-3 rounded-xl bg-gradient-to-br from-teal-500 to-teal-600 shadow-lg">
                <i class="fas fa-tools text-white text-lg"></i>
            </div>
            <div>
                <h2 class="text-xl font-bold text-gray-900">Equipment Status Report</h2>
                <p class="text-sm text-gray-500">Equipment breakdown by category and location</p>
            </div>
        </div>
        <div class="flex space-x-2 bg-gray-100 p-1.5 rounded-xl">
            <button id="preRepairBtn"
                class="px-4 py-2 text-sm font-semibold rounded-lg bg-white text-teal-700 shadow-sm transition-all duration-200"
                onclick="showTab('preRepair')">
                <i class="fas fa-list mr-1"></i> Pre-Repair
            </button>
            <button id="summaryBtn"
                class="px-4 py-2 text-sm font-semibold rounded-lg text-gray-500 hover:text-gray-700 transition-all duration-200"
                onclick="showTab('summary')">
                <i class="fas fa-chart-bar mr-1"></i> Summary
            </button>
        </div>
    </div>

    <div id="preRepairTab" class="report-tab overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50 uppercase text-xs">
                <tr>
                    <th class="px-6 py-3 text-left">Category</th>
                    <th class="px-6 py-3 text-left">Units</th>
                    <th class="px-6 py-3 text-left">Operational</th>
                    <th class="px-6 py-3 text-left">Repair</th>
                    <th class="px-6 py-3 text-left">Unserviceable</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 text-sm">
                <?php foreach ($categoryStats as $stat): ?>
                    <tr>
                        <td class="px-6 py-3 font-medium"><?php echo htmlspecialchars($stat['equipment_name']); ?></td>
                        <td class="px-6 py-3"><?php echo $stat['units']; ?></td>
                        <td class="px-6 py-3 text-emerald-600 font-bold"><?php echo $stat['operational']; ?></td>
                        <td class="px-6 py-3 text-amber-600 font-bold"><?php echo $stat['under_repair']; ?></td>
                        <td class="px-6 py-3 text-rose-600 font-bold"><?php echo $stat['unserviceable']; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div id="summaryTab" class="report-tab hidden overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50 uppercase text-xs">
                <tr>
                    <th class="px-6 py-3 text-left">Location</th>
                    <th class="px-6 py-3 text-left">Units</th>
                    <th class="px-6 py-3 text-left">Operational</th>
                    <th class="px-6 py-3 text-left">Repair</th>
                    <th class="px-6 py-3 text-left">Unserviceable</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 text-sm">
                <?php foreach ($locationStatsArray as $stat): ?>
                    <tr>
                        <td class="px-6 py-3 font-medium"><?php echo htmlspecialchars($stat['location_name']); ?></td>
                        <td class="px-6 py-3"><?php echo $stat['units']; ?></td>
                        <td class="px-6 py-3 text-emerald-600 font-bold"><?php echo $stat['operational']; ?></td>
                        <td class="px-6 py-3 text-amber-600 font-bold"><?php echo $stat['under_repair']; ?></td>
                        <td class="px-6 py-3 text-rose-600 font-bold"><?php echo $stat['unserviceable']; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>


<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    window.showBarChart = null;
    window.showPieChart = null;
    window.showTab = null;
    window.repairChartInstance = null;
    window.currentChart = null;

    function initializeDashboard() {
        console.log("Initializing Dashboard...");

        // Animations
        document.querySelectorAll('[data-target]').forEach(el => {
            const target = el.dataset.target;
            if (el.classList.contains('progress-bar') || el.classList.contains('equip-progress-bar') || el.classList.contains('stat-progress-bar')) {
                setTimeout(() => el.style.width = target + '%', 100);
            }
        });

        // Document Chart
        const ctx = document.getElementById('documentChart')?.getContext('2d');
        if (ctx) {
            window.showBarChart = () => {
                if (window.currentChart) window.currentChart.destroy();
                window.currentChart = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: ['Pending', 'Approved', 'Complied', 'Received'],
                        datasets: [{
                            data: [
                                <?php echo $documentStats['Pending']; ?>,
                                <?php echo $documentStats['Approved']; ?>,
                                <?php echo $documentStats['Complied']; ?>,
                                <?php echo $documentStats['Received']; ?>
                            ],
                            backgroundColor: ['#f59e0b', '#0ea5e9', '#10b981', '#475569'],
                            borderRadius: 8,
                            borderSkipped: false,
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                backgroundColor: 'rgba(0,0,0,0.8)',
                                padding: 12,
                                cornerRadius: 8,
                                titleFont: { size: 14, weight: 'bold' },
                                bodyFont: { size: 13 }
                            }
                        },
                        scales: {
                            y: { beginAtZero: true, ticks: { stepSize: 1 } }
                        }
                    }
                });
            };

            window.showPieChart = () => {
                if (window.currentChart) window.currentChart.destroy();
                window.currentChart = new Chart(ctx, {
                    type: 'doughnut',
                    data: {
                        labels: ['Pending', 'Approved', 'Complied', 'Received'],
                        datasets: [{
                            data: [
                                <?php echo $documentStats['Pending']; ?>,
                                <?php echo $documentStats['Approved']; ?>,
                                <?php echo $documentStats['Complied']; ?>,
                                <?php echo $documentStats['Received']; ?>
                            ],
                            backgroundColor: ['#f59e0b', '#0ea5e9', '#10b981', '#475569'],
                            borderWidth: 0,
                            hoverOffset: 10
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '55%',
                        plugins: {
                            legend: {
                                position: 'right',
                                labels: {
                                    padding: 20,
                                    usePointStyle: true,
                                    pointStyle: 'circle',
                                    font: { size: 12 }
                                }
                            },
                            tooltip: {
                                backgroundColor: 'rgba(0,0,0,0.8)',
                                padding: 12,
                                cornerRadius: 8,
                                callbacks: {
                                    label: function (context) {
                                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                        const value = context.raw;
                                        const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                        return `${context.label}: ${value} (${percentage}%)`;
                                    }
                                }
                            }
                        }
                    }
                });
            };

            window.showBarChart();

            // Update button styles on click
            const barBtn = document.getElementById('barChartBtn');
            const pieBtn = document.getElementById('pieChartBtn');

            if (barBtn && pieBtn) {
                barBtn.addEventListener('click', () => {
                    barBtn.classList.add('bg-white', 'text-violet-700', 'shadow-sm');
                    barBtn.classList.remove('text-gray-500');
                    pieBtn.classList.remove('bg-white', 'text-violet-700', 'shadow-sm');
                    pieBtn.classList.add('text-gray-500');
                });
                pieBtn.addEventListener('click', () => {
                    pieBtn.classList.add('bg-white', 'text-violet-700', 'shadow-sm');
                    pieBtn.classList.remove('text-gray-500');
                    barBtn.classList.remove('bg-white', 'text-violet-700', 'shadow-sm');
                    barBtn.classList.add('text-gray-500');
                });
            }
        }

        // Repair Chart
        const repairCtx = document.getElementById('repairChart')?.getContext('2d');
        if (repairCtx) {
            if (window.repairChartInstance) window.repairChartInstance.destroy();
            window.repairChartInstance = new Chart(repairCtx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode($repairChartData['locations']); ?>,
                    datasets: [
                        { label: 'Operational', data: <?php echo json_encode($repairChartData['operational']); ?>, borderColor: '#10b981', tension: 0.4 },
                        { label: 'Repair', data: <?php echo json_encode($repairChartData['repair']); ?>, borderColor: '#f59e0b', tension: 0.4 }
                    ]
                },
                options: { responsive: true, maintainAspectRatio: false }
            });
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

            // Calculate percentages for display
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
                    layout: {
                        padding: 20
                    }
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

                        // Total count
                        ctx.font = 'bold 32px sans-serif';
                        ctx.fillStyle = '#1f2937';
                        ctx.fillText(total, centerX, centerY - 10);

                        // Label
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

                            // Only show percentage if the slice is big enough (>=5%)
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

        window.showTab = (tab) => {
            document.querySelectorAll('.report-tab').forEach(t => t.classList.add('hidden'));
            document.getElementById(tab + 'Tab').classList.remove('hidden');

            // Update button styles
            const preRepairBtn = document.getElementById('preRepairBtn');
            const summaryBtn = document.getElementById('summaryBtn');

            if (preRepairBtn && summaryBtn) {
                if (tab === 'preRepair') {
                    preRepairBtn.classList.add('bg-white', 'text-teal-700', 'shadow-sm');
                    preRepairBtn.classList.remove('text-gray-500');
                    summaryBtn.classList.remove('bg-white', 'text-teal-700', 'shadow-sm');
                    summaryBtn.classList.add('text-gray-500');
                } else {
                    summaryBtn.classList.add('bg-white', 'text-teal-700', 'shadow-sm');
                    summaryBtn.classList.remove('text-gray-500');
                    preRepairBtn.classList.remove('bg-white', 'text-teal-700', 'shadow-sm');
                    preRepairBtn.classList.add('text-gray-500');
                }
            }
        };
    }

    if (document.readyState === 'complete' || document.readyState === 'interactive') {
        initializeDashboard();
    } else {
        document.addEventListener('DOMContentLoaded', initializeDashboard);
    }
</script>

<?php if (isset($mysqli))
    $mysqli->close(); ?>