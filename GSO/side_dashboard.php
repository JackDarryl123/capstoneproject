<?php

require_once __DIR__ . '/../config.php';

// Redirect if not logged in or not admin/pgdh_gso
if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'pgdh_gso'])) {
    header('Location: ../index.php?login');
    exit();
}

// Include profile_modal.php after $mysqli is defined
include_once '../profile_modal.php';

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




// Fetch Equipment Statistics - GLOBAL for all locations
$equipStatsQuery = $mysqli->prepare('
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = "Operational" THEN 1 ELSE 0 END) as operational,
        SUM(CASE WHEN status = "Under repair" THEN 1 ELSE 0 END) as repair,
        SUM(CASE WHEN status = "Unserviceable" THEN 1 ELSE 0 END) as unserviceable
    FROM equipment
');
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

// Fetch Activities for Calendar & List - GLOBAL
$actQuery = $mysqli->prepare('
    SELECT id, activity_type, activity_date, activity_time, property_no, remarks
    FROM activities 
    ORDER BY activity_date ASC, activity_time ASC
');

// Check if prepare failed (Good practice for debugging)
if ($actQuery === false) {
    die('Prepare failed: ' . $mysqli->error);
}

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


// Fetch Equipment Stats Grouped by Category - GLOBAL
$categoryStatsQuery = $mysqli->prepare('
    SELECT 
        ec.category_name as equipment_name,
        COUNT(e.id) as units,
        SUM(CASE WHEN e.status = "Operational" THEN 1 ELSE 0 END) as operational,
        SUM(CASE WHEN e.status = "Under repair" THEN 1 ELSE 0 END) as under_repair,
        SUM(CASE WHEN e.status = "Unserviceable" THEN 1 ELSE 0 END) as unserviceable
    FROM equipment e
    JOIN equipment_category ec ON e.category_id = ec.id
    GROUP BY ec.category_name
    ORDER BY units DESC
');
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



// --- END OF ADDED BLOCK ---



// Get document counts for the bar chart - GLOBAL
$docCountQuery = $mysqli->prepare('
    SELECT 
        status,
        COUNT(*) as count
    FROM documents 
    WHERE status IN ("Pending", "Approved", "Done", "Complete")
    GROUP BY status
');
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
        --bs-
        -zindex: 1160;
        --bs-backdrop-zindex: 1150;
    }

    .
    -backdrop {
        z-index: var(--bs-backdrop-zindex) !important;
    }

    .
     {
        z-index: var(--bs-
        -zindex) !important;
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
</style>

<!-- Load Font Awesome -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<!-- Tailwind CSS -->
<script src="https://cdn.tailwindcss.com"></script>

<?php if ($view === 'dashboard'): ?>
    <!-- Dashboard Header -->


    <!-- Stats Summary -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">

        <div
            class="bg-white rounded-xl shadow p-6 border-l-4 border-blue-500 hover:shadow-lg transition-shadow duration-200">
            <div class="flex items-center justify-between mb-4">
                <div class="p-3 rounded-xl bg-blue-50">
                    <i class="fas fa-cubes text-blue-600 text-xl"></i>
                </div>
                <span class="text-xs font-semibold px-2.5 py-1 rounded-full bg-blue-50 text-blue-700">
                    100%
                </span>
            </div>
            <h3 class="text-3xl font-bold text-gray-900 mb-1"><?php echo $totalEquip; ?></h3>
            <p class="text-gray-600 text-sm font-medium">Total Equipment</p>
        </div>

        <div
            class="bg-white rounded-xl shadow p-6 border-l-4 border-green-500 hover:shadow-lg transition-shadow duration-200">
            <div class="flex items-center justify-between mb-4">
                <div class="p-3 rounded-xl bg-green-50">
                    <i class="fas fa-power-off text-green-600 text-xl"></i>
                </div>
                <span class="text-xs font-semibold px-2.5 py-1 rounded-full bg-green-50 text-green-700">
                    <?php echo $totalEquip > 0 ? round(($operationalCount / $totalEquip) * 100) : 0; ?>%
                </span>
            </div>
            <h3 class="text-3xl font-bold text-gray-900 mb-1"><?php echo $operationalCount; ?></h3>
            <p class="text-gray-600 text-sm font-medium">Operational</p>
            <p class="text-xs text-green-600 mt-1 flex items-center">
                <i class="fas fa-check-circle mr-1"></i> Good Condition
            </p>
        </div>

        <div
            class="bg-white rounded-xl shadow p-6 border-l-4 border-amber-500 hover:shadow-lg transition-shadow duration-200">
            <div class="flex items-center justify-between mb-4">
                <div class="p-3 rounded-xl bg-amber-50">
                    <i class="fas fa-tools text-amber-600 text-xl"></i>
                </div>
                <span class="text-xs font-semibold px-2.5 py-1 rounded-full bg-amber-50 text-amber-700">
                    <?php echo $totalEquip > 0 ? round(($repairCount / $totalEquip) * 100) : 0; ?>%
                </span>
            </div>
            <h3 class="text-3xl font-bold text-gray-900 mb-1"><?php echo $repairCount; ?></h3>
            <p class="text-gray-600 text-sm font-medium">Under Repair</p>
            <p class="text-xs text-amber-600 mt-1 flex items-center">
                <i class="fas fa-clock mr-1"></i> Maintenance
            </p>
        </div>

        <div
            class="bg-white rounded-xl shadow p-6 border-l-4 border-red-500 hover:shadow-lg transition-shadow duration-200">
            <div class="flex items-center justify-between mb-4">
                <div class="p-3 rounded-xl bg-red-50">
                    <i class="fas fa-ban text-red-600 text-xl"></i>
                </div>
                <span class="text-xs font-semibold px-2.5 py-1 rounded-full bg-red-50 text-red-700">
                    <?php echo $totalEquip > 0 ? round(($unserviceableCount / $totalEquip) * 100) : 0; ?>%
                </span>
            </div>
            <h3 class="text-3xl font-bold text-gray-900 mb-1"><?php echo $unserviceableCount; ?></h3>
            <p class="text-gray-600 text-sm font-medium">Unserviceable</p>
            <p class="text-xs text-red-600 mt-1 flex items-center">
                <i class="fas fa-exclamation-triangle mr-1"></i> Needs Attention
            </p>
        </div>
    </div>
    </div>

    <!-- Charts and Analytics Section -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
        <!-- Document Status Chart - SIMPLIFIED VERSION -->
        <div class="lg:col-span-2 bg-white rounded-xl shadow border border-gray-100 p-6">
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-6">
                <div class="flex-1">
                    <h3 class="text-lg font-semibold text-gray-900">Document Status Overview</h3>
                    <p class="text-sm text-gray-600">
                        Province-wide pre-repair document statistics
                    </p>
                </div>

                <!-- Chart Type Buttons -->
                <div class="chart-type-buttons">
                    <button id="barChartBtn" class="chart-btn active" onclick="showBarChart()">
                        <i class="fas fa-chart-bar mr-2"></i>Bar Chart
                    </button>
                    <button id="pieChartBtn" class="chart-btn" onclick="showPieChart()">
                        <i class="fas fa-chart-pie mr-2"></i>Pie Chart
                    </button>
                </div>
            </div>

            <!-- Chart Container -->
            <div class="chart-container">
                <canvas id="documentChart"></canvas>

                <?php if ($totalDocs == 0): ?>
                    <div class="chart-loading">
                        <div class="text-center">
                            <i class="fas fa-chart-pie text-4xl text-gray-300 mb-3"></i>
                            <p class="text-gray-600">No documents found</p>
                            <p class="text-sm text-gray-500 mt-1">Add documents to see the chart</p>
                        </div>
                    </div>
                <?php else: ?>
                    <div id="chartLoading" class="chart-loading">
                        <div class="text-center">
                            <div
                                class="inline-block animate-spin rounded-full h-8 w-8 border-4 border-green-300 border-t-green-600">
                            </div>
                            <p class="text-sm text-gray-600 mt-2">Loading chart...</p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Statistics Summary -->
            <div class="mt-6 pt-6 border-t border-gray-100">
                <div class="grid grid-cols-4 gap-4">
                    <div class="text-center">
                        <div class="text-2xl font-bold text-black"><?php echo $documentStats['Pending']; ?></div>
                        <div class="text-sm text-black font-bold">Pending</div>
                        <div class="text-xs text-black font-medium mt-1">
                            <?php echo $totalDocs > 0 ? round(($documentStats['Pending'] / $totalDocs) * 100) : 0; ?>%
                        </div>
                    </div>

                    <div class="text-center">
                        <div class="text-2xl font-bold text-black"><?php echo $documentStats['Approved']; ?></div>
                        <div class="text-sm text-black font-bold">Approved</div>
                        <div class="text-xs text-black font-medium mt-1">
                            <?php echo $totalDocs > 0 ? round(($documentStats['Approved'] / $totalDocs) * 100) : 0; ?>%
                        </div>
                    </div>

                    <div class="text-center">
                        <div class="text-2xl font-bold text-black"><?php echo $documentStats['Done']; ?></div>
                        <div class="text-sm text-black font-bold">Done</div>
                        <div class="text-xs text-black font-medium mt-1">
                            <?php echo $totalDocs > 0 ? round(($documentStats['Done'] / $totalDocs) * 100) : 0; ?>%
                        </div>
                    </div>

                    <div class="text-center">
                        <div class="text-2xl font-bold text-black"><?php echo $documentStats['Complete']; ?></div>
                        <div class="text-sm text-black font-bold">Complete</div>
                        <div class="text-xs text-black font-medium mt-1">
                            <?php echo $totalDocs > 0 ? round(($documentStats['Complete'] / $totalDocs) * 100) : 0; ?>%
                        </div>
                    </div>
                </div>

                <!-- Total Summary -->
                <div class="mt-4 text-center">
                    <p class="text-sm text-gray-600">
                        Total documents province-wide:
                        <span class="font-bold text-lg"><?php echo $totalDocs; ?></span>
                    </p>
                </div>
            </div>
        </div>

        <!-- Vehicle Distribution by Location -->

        <div class="bg-white rounded-xl shadow p-6 border border-gray-100 h-full">
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h1 class="text-lg font-bold text-gray-900">Equipment Distribution</h1>
                    <p class="text-xs text-gray-500 mt-1">Volume by location & status</p>
                </div>
                <div class="flex flex-col items-end">
                    <span class="text-2xl font-bold text-gray-800"><?php echo $totalFleet; ?></span>
                    <span class="text-xs text-gray-500 uppercase font-semibold">Total Units</span>
                </div>
            </div>

            <div class="space-y-6">
                <?php foreach ($allLocations as $loc):
                    // Get data
                    $data = $locationStats[$loc] ?? ['total' => 0, 'operational' => 0, 'repair' => 0, 'unserviceable' => 0];
                    $total = $data['total'];

                    // 1. Calculate Width relative to the Largest Location (Max 100%)
                    // We ensure a minimum of 1% so the bar doesn't disappear completely
                    $widthRelativeToMax = $maxTotal > 0 ? ($total / $maxTotal) * 100 : 0;

                    // 2. Calculate Internal Segments (%)
                    $opPct = $total > 0 ? ($data['operational'] / $total) * 100 : 0;
                    $repPct = $total > 0 ? ($data['repair'] / $total) * 100 : 0;
                    $unsPct = $total > 0 ? ($data['unserviceable'] / $total) * 100 : 0;

                    // 3. Global share percentage
                    $globalShare = $totalFleet > 0 ? round(($total / $totalFleet) * 100) : 0;
                    ?>
                    <div class="group">
                        <div class="flex justify-between items-end mb-2">
                            <div class="flex items-center gap-2">
                                <span class="font-bold text-gray-700"><?php echo $loc; ?></span>
                                <span class="text-[10px] px-1.5 py-0.5 rounded bg-gray-100 text-gray-500 font-medium">
                                    <?php echo $globalShare; ?>% of Fleet
                                </span>
                            </div>
                            <div class="text-sm font-semibold text-gray-900">
                                <?php echo $total; ?> <span class="text-gray-400 text-xs font-normal">units</span>
                            </div>
                        </div>

                        <div class="w-full bg-gray-100 rounded-full h-4 p-0.5 relative">

                            <div class="flex h-full rounded-full overflow-hidden transition-all duration-1000 ease-out"
                                style="width: <?php echo max($widthRelativeToMax, 2); ?>%">

                                <?php if ($data['operational'] > 0): ?>
                                    <div class="bg-green-500 h-full relative group/segment first:rounded-l-full"
                                        style="width: <?php echo $opPct; ?>%">
                                        <div
                                            class="absolute bottom-full left-1/2 -translate-x-1/2 mb-2 hidden group-hover/segment:block z-10">
                                            <div
                                                class="bg-gray-800 text-white text-xs rounded py-1 px-2 whitespace-nowrap shadow-lg">
                                                Operational: <?php echo $data['operational']; ?> (<?php echo round($opPct); ?>%)
                                                <div
                                                    class="absolute top-full left-1/2 -translate-x-1/2 border-4 border-transparent border-t-gray-800">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if ($data['repair'] > 0): ?>
                                    <div class="bg-amber-500 h-full relative group/segment" style="width: <?php echo $repPct; ?>%">
                                        <div
                                            class="absolute bottom-full left-1/2 -translate-x-1/2 mb-2 hidden group-hover/segment:block z-10">
                                            <div
                                                class="bg-gray-800 text-white text-xs rounded py-1 px-2 whitespace-nowrap shadow-lg">
                                                Repair: <?php echo $data['repair']; ?> (<?php echo round($repPct); ?>%)
                                                <div
                                                    class="absolute top-full left-1/2 -translate-x-1/2 border-4 border-transparent border-t-gray-800">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if ($data['unserviceable'] > 0): ?>
                                    <div class="bg-red-500 h-full relative group/segment last:rounded-r-full"
                                        style="width: <?php echo $unsPct; ?>%">
                                        <div
                                            class="absolute bottom-full left-1/2 -translate-x-1/2 mb-2 hidden group-hover/segment:block z-10">
                                            <div
                                                class="bg-gray-800 text-white text-xs rounded py-1 px-2 whitespace-nowrap shadow-lg">
                                                Unserviceable: <?php echo $data['unserviceable']; ?>
                                                (<?php echo round($unsPct); ?>%)
                                                <div
                                                    class="absolute top-full left-1/2 -translate-x-1/2 border-4 border-transparent border-t-gray-800">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
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
                    <span class="text-2xl font-bold text-gray-800"><?php echo $sumOperational; ?></span>
                    <span class="text-xs font-medium text-green-600 uppercase tracking-wide mt-1">Operational</span>
                </div>

                <div
                    class="flex flex-col items-center justify-center p-4 rounded-xl bg-amber-50/50 border border-amber-100 transition-transform hover:-translate-y-1">
                    <div class="w-10 h-10 rounded-full bg-amber-100 flex items-center justify-center mb-2 text-amber-600">
                        <i class="fas fa-tools text-lg"></i>
                    </div>
                    <span class="text-2xl font-bold text-gray-800"><?php echo $sumRepair; ?></span>
                    <span class="text-xs font-medium text-amber-600 uppercase tracking-wide mt-1">Under_Repair</span>
                </div>

                <div
                    class="flex flex-col items-center justify-center p-4 rounded-xl bg-red-50/50 border border-red-100 transition-transform hover:-translate-y-1">
                    <div class="w-10 h-10 rounded-full bg-red-100 flex items-center justify-center mb-2 text-red-600">
                        <i class="fas fa-ban text-lg"></i>
                    </div>
                    <span class="text-2xl font-bold text-gray-800"><?php echo $sumUnserviceable; ?></span>
                    <span class="text-xs font-medium text-red-600 uppercase tracking-wide mt-1">Unserviceable</span>
                </div>

            </div>

        </div>



    </div>

    <!-- Calendar Section (Coming Soon) -->
    <div class="bg-white rounded-xl shadow p-12 border border-gray-100 text-center">
        <div class="max-w-md mx-auto">
            <div class="w-20 h-20 bg-blue-50 rounded-full flex items-center justify-center mx-auto mb-6">
                <i class="fas fa-calendar-alt text-3xl text-blue-500"></i>
            </div>
            <h3 class="text-2xl font-bold text-gray-900 mb-2">Schedule Calendar</h3>
            <p class="text-gray-500 mb-6">We're currently working on a specialized scheduling system for the GSO department. This feature will be available soon.</p>
            <div class="inline-flex items-center px-4 py-2 bg-blue-100 text-blue-700 rounded-full text-sm font-semibold">
                <i class="fas fa-tools mr-2"></i> Under Development
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
                                                <span class="font-semibold text-green-700"><?php echo $row['operational']; ?></span>
                                                <span class="text-gray-400"><?php echo $opPct; ?>%</span>
                                            </div>
                                            <div class="w-full bg-gray-100 rounded-full h-1.5 overflow-hidden">
                                                <div class="bg-green-500 h-1.5 rounded-full" style="width: <?php echo $opPct; ?>%">
                                                </div>
                                            </div>
                                        </div>
                                    </td>

                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="w-full max-w-[140px]">
                                            <div class="flex justify-between text-xs mb-1">
                                                <span
                                                    class="font-semibold text-amber-700"><?php echo $row['under_repair']; ?></span>
                                                <span class="text-gray-400"><?php echo $repPct; ?>%</span>
                                            </div>
                                            <div class="w-full bg-gray-100 rounded-full h-1.5 overflow-hidden">
                                                <div class="bg-amber-500 h-1.5 rounded-full" style="width: <?php echo $repPct; ?>%">
                                                </div>
                                            </div>
                                        </div>
                                    </td>

                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="w-full max-w-[140px]">
                                            <div class="flex justify-between text-xs mb-1">
                                                <span class="font-semibold text-red-700"><?php echo $row['unserviceable']; ?></span>
                                                <span class="text-gray-400"><?php echo $unsPct; ?>%</span>
                                            </div>
                                            <div class="w-full bg-gray-100 rounded-full h-1.5 overflow-hidden">
                                                <div class="bg-red-500 h-1.5 rounded-full" style="width: <?php echo $unsPct; ?>%">
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
                                        <p>No equipment categories found.</p>
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
                                <tr class="hover:bg-gray-50 border-l-4 border-transparent transition-all duration-200">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div
                                                class="flex-shrink-0 h-8 w-8 rounded-full bg-gray-100 text-gray-500 flex items-center justify-center mr-3">
                                                <i class="fas fa-map-marker-alt text-sm"></i>
                                            </div>
                                            <div>
                                                <div class="text-sm font-bold text-gray-900">
                                                    <?php echo htmlspecialchars($row['location_name']); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>

                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-bold text-gray-900"><?php echo $units; ?></div>
                                    </td>

                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center gap-2">
                                            <div class="flex flex-col w-24">
                                                <div class="h-1.5 w-full bg-green-100 rounded-full overflow-hidden">
                                                    <div class="h-full bg-green-500" style="width: <?php echo $opPct; ?>%"></div>
                                                </div>
                                            </div>
                                            <div class="text-xs">
                                                <span class="font-bold text-green-700"><?php echo $row['operational']; ?></span>
                                                <span class="text-gray-400 mx-1">/</span>
                                                <span class="text-gray-500"><?php echo $opPct; ?>%</span>
                                            </div>
                                        </div>
                                    </td>

                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center gap-2">
                                            <div class="flex flex-col w-24">
                                                <div class="h-1.5 w-full bg-amber-100 rounded-full overflow-hidden">
                                                    <div class="h-full bg-amber-500" style="width: <?php echo $repPct; ?>%"></div>
                                                </div>
                                            </div>
                                            <div class="text-xs">
                                                <span class="font-bold text-amber-700"><?php echo $row['under_repair']; ?></span>
                                                <span class="text-gray-400 mx-1">/</span>
                                                <span class="text-gray-500"><?php echo $repPct; ?>%</span>
                                            </div>
                                        </div>
                                    </td>

                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center gap-2">
                                            <div class="flex flex-col w-24">
                                                <div class="h-1.5 w-full bg-red-100 rounded-full overflow-hidden">
                                                    <div class="h-full bg-red-500" style="width: <?php echo $unsPct; ?>%"></div>
                                                </div>
                                            </div>
                                            <div class="text-xs">
                                                <span class="font-bold text-red-700"><?php echo $row['unserviceable']; ?></span>
                                                <span class="text-gray-400 mx-1">/</span>
                                                <span class="text-gray-500"><?php echo $unsPct; ?>%</span>
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
            //     colors: [
            //         'rgba(245, 158, 11, 0.8)',    // Amber for Pending
            //         'rgba(16, 185, 129, 0.8)',    // Green for Approved
            //         'rgba(59, 130, 246, 0.8)',    // Blue for Done
            //         'rgba(139, 92, 246, 0.8)'     // Purple for Complete
            //     ],
            //     borderColors: [
            //         'rgb(245, 158, 11)',          // Amber border
            //         'rgb(16, 185, 129)',          // Green border
            //         'rgb(59, 130, 246)',          // Blue border
            //         'rgb(139, 92, 246)'           // Purple border
            //     ]
            // };
            colors: [
                'rgba(245, 158, 11, 0.85)',   // Pending: Amber-500 (Waiting)
                'rgba(14, 165, 233, 0.85)',   // Approved: Sky-500 (Active/Info)
                'rgba(34, 197, 94, 0.85)',    // Done: Green-500 (Success)
                'rgba(7, 61, 136, 0.85)'     // Complete: Slate-600 (Archived/Closed)
            ],
            borderColors: [
                'rgb(245, 158, 11)',
                'rgb(14, 165, 233)',
                'rgb(34, 197, 94)',
                'rgb(71, 85, 105)'
            ]
        };
        let currentChart = null;

        // Initialize with bar chart
        if (<?php echo $totalDocs; ?> > 0) {
            showBarChart();
        }

        function showBarChart() {
            // Update button states
            document.getElementById('barChartBtn').classList.add('active');
            document.getElementById('pieChartBtn').classList.remove('active');

            // Destroy existing chart
            if (currentChart) {
                currentChart.destroy();
            }

            // Hide loading
            const loadingEl = document.getElementById('chartLoading');
            if (loadingEl) {
                loadingEl.style.display = 'none';
            }

            // Get canvas context
            const ctx = document.getElementById('documentChart').getContext('2d');

            // Create bar chart
            currentChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: documentData.labels,
                    datasets: [{
                        label: 'Document Count',
                        data: documentData.values,
                        backgroundColor: documentData.colors,
                        borderColor: documentData.borderColors,
                        borderWidth: 1,
                        borderRadius: 6,
                        borderSkipped: false,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function (context) {
                                    const value = context.raw;
                                    const total = <?php echo $totalDocs; ?>;
                                    const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                    return `${context.label}: ${value} documents (${percentage}%)`;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Number of Documents'
                            },
                            ticks: {
                                stepSize: 1,
                                precision: 0
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'Document Status'
                            }
                        }
                    }
                }
            });
        }

        function showPieChart() {
            // Update button states
            document.getElementById('pieChartBtn').classList.add('active');
            document.getElementById('barChartBtn').classList.remove('active');

            // Destroy existing chart
            if (currentChart) {
                currentChart.destroy();
            }

            // Hide loading
            const loadingEl = document.getElementById('chartLoading');
            if (loadingEl) {
                loadingEl.style.display = 'none';
            }

            // Get canvas context
            const ctx = document.getElementById('documentChart').getContext('2d');

            // Create pie chart
            currentChart = new Chart(ctx, {
                type: 'pie',
                data: {
                    labels: documentData.labels,
                    datasets: [{
                        data: documentData.values,
                        backgroundColor: documentData.colors,
                        borderColor: documentData.borderColors,
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: {
                                padding: 20,
                                usePointStyle: true,
                                pointStyle: 'circle'
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function (context) {
                                    const label = context.label;
                                    const value = context.raw;
                                    const total = <?php echo $totalDocs; ?>;
                                    const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                    return `${label}: ${value} (${percentage}%)`;
                                }
                            }
                        }
                    }
                }
            });
        }

        // Make functions available globally
        window.showBarChart = showBarChart;
        window.showPieChart = showPieChart;
    });



    // calendar
    document.addEventListener('DOMContentLoaded', function () {
        var calendarEl = document.getElementById('adminCalendar');

        // Parse the PHP data
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

            // Interaction settings
            selectable: true,
            editable: false,



            // Tooltip or Action on Click
            eventClick: function (info) {
                // Simple alert for now - you can replace this with a Modal later
                const props = info.event.extendedProps;
                const remarks = props.remarks ? `\nRemarks: ${props.remarks}` : '';
                alert(`Activity: ${info.event.title}\nTime: ${props.time}${remarks}`);
            }
        });

        calendar.render();
    });



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

</script>

<!-- Rest of your existing JavaScript remains the same -->
<?php
// Close database connection
if (isset($mysqli)) {
    $mysqli->close();
}
?>