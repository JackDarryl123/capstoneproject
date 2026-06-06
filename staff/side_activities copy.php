<?php
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/../includes/session_helper.php';
start_user_session();

// Database connection
require_once __DIR__ . '/../db_connect.php';

// Authentication check - must be logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit();
}

// Fetch current user data for profile modal
$user_id = $_SESSION['user_id'];
$currentUser = [];

// Get user data with better error handling
$stmt = $mysqli->prepare("SELECT * FROM users WHERE id = ?");
if (!$stmt) {
    die("Prepare failed: " . $mysqli->error);
}
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$currentUser = $result->fetch_assoc() ?? [];
$stmt->close();

// ✅ Get current user's location for filtering
$user_location = $currentUser['location'] ?? null;
$user_role = $currentUser['role'] ?? 'user';

// Set user location in session for the activity log modal
if (!isset($_SESSION['user_location']) && $user_location) {
    $_SESSION['user_location'] = $user_location;
}

// Determine path prefix based on where this file is being included from
$currentPath = $_SERVER['PHP_SELF'] ?? '';
$pathPrefix = '';
if (strpos($currentPath, '/staff/') !== false) {
    $pathPrefix = '../';
    $isStaffDashboard = true;
} elseif (strpos($currentPath, '/users/') !== false) {
    $pathPrefix = '../';
    $isStaffDashboard = false;
} else {
    $pathPrefix = '';
    $isStaffDashboard = false;
}

// Fetch users who reported an activity
$users = $mysqli->query("SELECT * FROM users ORDER BY id DESC LIMIT 3");

// ✅ Fetch events for calendar - FILTERED BY USER'S LOCATION
if ($user_location) {
    // Filter activities by user's location
    $stmt = $mysqli->prepare("SELECT * FROM activities WHERE location = ? ORDER BY activity_date ASC");
    $stmt->bind_param("s", $user_location);
    $stmt->execute();
    $events_result = $stmt->get_result();
    $events = $events_result;
    $stmt->close();
} else {
    // Fallback for users without location
    $events = $mysqli->query("SELECT * FROM activities ORDER BY activity_date ASC");
}

// FETCH ACTIVITY LOGS WITH LOCATION FILTERING
$activity_logs = [];
if ($user_location && ($user_role === 'admin' || $user_role === 'staff' || $user_role === 'maintenance')) {
    // Staff users only see logs from their location
    $stmt = $mysqli->prepare("SELECT * FROM activity_log WHERE location = ? ORDER BY date_time DESC LIMIT 50");
    $stmt->bind_param("s", $user_location);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $activity_logs[] = $row;
    }
    $stmt->close();
} else {
    // Admins or users without location can see all logs
    $result = $mysqli->query("SELECT * FROM activity_log ORDER BY date_time DESC LIMIT 50");
    while ($row = $result->fetch_assoc()) {
        $activity_logs[] = $row;
    }
}
?>

<style>
    /* Ensure modals overlap the activity drawer (drawer is at z-index 2010) */
    .modal-backdrop.show {
        z-index: 2050 !important;
    }

    .modal.show {
        z-index: 2060 !important;
    }

    /* Ensure FullCalendar popover stays below the drawer and modals */
    .fc-popover {
        z-index: 1500 !important;
    }

    /* Only keep the CSS that's specific to this component */
    .activity-scroll {
        height: 900px;
        /* Match calendar height */
        overflow-y: auto;
        overflow-x: hidden;
        border-radius: 0 0 15px 15px;
    }


    /* Custom Scrollbar for Green Theme */
    .custom-scrollbar::-webkit-scrollbar {
        width: 6px;
        height: 6px;
    }

    .custom-scrollbar::-webkit-scrollbar-track {
        background: #f0fdf4;
        /* Emerald-50 */
        border-radius: 8px;
    }

    .custom-scrollbar::-webkit-scrollbar-thumb {
        background-color: #34d399;
        /* Emerald-400 */
        border-radius: 8px;
    }

    .custom-scrollbar::-webkit-scrollbar-thumb:hover {
        background-color: #059669;
        /* Emerald-600 */
    }

    /* Calendar Grid Specifics */
    .calendar-grid {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        gap: 0.5rem;
    }

    .calendar-day {
        min-height: 100px;
        transition: all 0.2s ease;
    }

    .calendar-day:hover {
        background-color: #ecfdf5;
        /* Emerald-50 */
        transform: translateY(-2px);
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    }





    /* Sticky Table Header */
    .activity-scroll thead th {
        position: sticky;
        top: 0;
        z-index: 2;
        box-shadow: 0 2px 2px -1px rgba(0, 0, 0, 0.1);
        background-color: #d1e7dd;
    }


    /* List below calendar */
    #calendar-activity-list {
        max-height: 250px;
        /* Reduced slightly to fit better */
        overflow-y: auto;
        overflow-x: hidden;
        border-radius: 10px;
        border: 1px solid #f0f0f0;
    }

    /* Calendar Table adjustments */
    .calendar-table td {
        vertical-align: top;
        padding: 5px !important;
    }

    .calendar-day-number {
        font-size: 14px;
        margin-bottom: 5px;
        display: block;
    }

    /* Today's date highlight */
    .today-highlight {
        background-color: #e8f5e9 !important;
        font-weight: bold;
        color: #198754 !important;
    }

    /* Consistent status badge size */
    .status-badge {
        min-width: 70px;
        padding: 0.35em 0.65em;
        display: inline-block;
        text-align: center;
    }

    /* Constraint for the scrollable area */
    .activity-scroll {
        max-height: 850px;
        /* Adjust this value to your liking */
        overflow-y: auto;
        /* Enables vertical scrolling */
        overflow-x: hidden;
        /* Prevents horizontal shifting */
    }

    /* Optional: Custom Scrollbar Styling (Modern Look) */
    .activity-scroll::-webkit-scrollbar {
        width: 6px;
    }

    .activity-scroll::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 10px;
    }

    .activity-scroll::-webkit-scrollbar-thumb {
        background: #198754;
        /* Matches your Bootstrap 'success' green */
        border-radius: 10px;
    }

    .activity-scroll::-webkit-scrollbar-thumb:hover {
        background: #146c43;
        /* Darker green on hover */
    }

    /* Make sure button container doesn't wrap */
    .d-flex.flex-nowrap.gap-1 {
        flex-wrap: nowrap;
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
        margin: 2px 4px !important;
        padding: 2px 4px !important;
        transition: all 0.2s ease;
        cursor: pointer;
    }

    .fc-event:hover {
        background-color: #f3f4f6 !important;
        transform: translateX(2px);
    }

    .fc-event-title {
        color: #111827 !important;
        font-weight: 600 !important;
        font-size: 0.75rem !important;
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

    /* Modern Select2 Styling for Modal Integration */
    .select2-container--default .select2-selection--single {
        height: 48px !important;
        padding: 10px 12px 10px 40px !important;
        /* Extra padding for the absolute icon */
        border: 1px solid #e5e7eb !important;
        border-radius: 1rem !important;
        background-color: #ffffff !important;
        transition: all 0.2s ease;
        display: flex;
        align-items: center;
    }

    .select2-container--default .select2-selection--single .select2-selection__rendered {
        color: #1f2937 !important;
        font-weight: 600;
        font-size: 0.875rem;
        padding-left: 0 !important;
    }

    .select2-container--default .select2-selection--single .select2-selection__arrow {
        height: 46px !important;
        right: 10px !important;
    }

    .select2-dropdown {
        border: 1px solid #e5e7eb !important;
        border-radius: 1rem !important;
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1) !important;
        overflow: hidden !important;
        z-index: 100002 !important;
        margin-top: 4px;
    }

    .select2-search--dropdown {
        padding: 12px !important;
    }

    .select2-search--dropdown .select2-search__field {
        padding: 8px 12px !important;
        border-radius: 0.75rem !important;
        border: 1px solid #e5e7eb !important;
        outline: none !important;
    }

    .select2-results__option--highlighted[aria-selected] {
        background-color: #10b981 !important;
    }

    /* Handle disabled state to match your info-block style */
    .select2-container--default.select2-container--disabled .select2-selection--single {
        background-color: #ffffff !important;
        border-color: transparent !important;
        box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05) !important;
        cursor: default !important;
        padding-left: 44px !important;
    }

    .select2-container--default.select2-container--disabled .select2-selection__arrow {
        display: none !important;
    }
</style>

<!-- Load Dependencies (Only what's missing in parent) -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />


<!-- 
replace -->

<!-- 
<div class="max-w-[1600px] mx-auto p-6 bg-gray-50 min-h-screen font-sans"> -->

<div id="statusUpdateAlert"
    class="hidden fixed top-4 right-4 z-50 p-4 rounded-xl shadow-lg border-l-4 transform transition-all duration-300"
    role="alert">
    <div class="flex items-center">
        <span id="statusIcon" class="mr-2 text-xl"></span>
        <span id="statusUpdateMessage" class="font-medium"></span>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start h-full">

    <!-- Schedule (Expanded to Full Width) -->
    <div class="col-span-1 lg:col-span-12 flex flex-col h-full relative">
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 h-full flex flex-col">

            <!-- Header with Title and Buttons in one row -->
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-4 gap-4">
                <div>
                    <h3 class="text-xl font-bold text-gray-900">Schedule Calendar</h3>
                    <p class="text-xs text-gray-500">Activities in <?php echo htmlspecialchars($user_location); ?></p>
                </div>
                <div class="flex items-center gap-3">
                    <button onclick="toggleActivityDrawer(true)"
                        class="h-11 px-6 bg-black text-white rounded-xl hover:bg-gray-800 transition-all text-sm font-bold flex items-center shadow-md whitespace-nowrap">
                        <i class="fas fa-history mr-2 text-lg"></i> View Activity Log
                    </button>
                    <?php if (!$isStaffDashboard): ?>
                        <button data-bs-toggle="modal" data-bs-target="#addActivityModal"
                            class="h-11 px-6 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl shadow-lg shadow-emerald-200 transition-all transform hover:-translate-y-0.5 text-sm font-bold flex items-center gap-2 whitespace-nowrap">
                            <i class="fas fa-plus"></i> Add Schedule
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <div class="flex flex-col lg:flex-row gap-6">
                <!-- Main Calendar -->
                <div class="flex-grow">
                    <div id="calendar" style="min-height: 650px; background: white;"
                        class="rounded-2xl shadow-sm border border-gray-100 p-4"></div>
                </div>

                <!-- Upcoming Sidebar -->
                <div class="w-full lg:w-80 border-l border-gray-100 lg:pl-6 pt-6 lg:pt-0">
                    <?php
                    // Fetch upcoming events for the next 7 days
                    $today_date = date('Y-m-d');
                    $next_week_date = date('Y-m-d', strtotime('+7 days'));
                    $upcoming_events = [];

                    if ($user_location) {
                        $stmt = $mysqli->prepare("SELECT * FROM activities WHERE location = ? AND activity_date BETWEEN ? AND ? ORDER BY activity_date ASC, activity_time ASC");
                        $stmt->bind_param("sss", $user_location, $today_date, $next_week_date);
                        $stmt->execute();
                        $res = $stmt->get_result();
                        while ($row = $res->fetch_assoc())
                            $upcoming_events[] = $row;
                        $stmt->close();
                    }
                    ?>
                    <div class="flex justify-between items-center mb-4">
                        <h4 class="font-bold text-gray-800">Next 7 Days</h4>
                        <span class="text-xs bg-emerald-100 text-emerald-700 px-2 py-0.5 rounded-full font-semibold">
                            <?php echo count($upcoming_events); ?> Upcoming
                        </span>
                    </div>

                    <div class="space-y-3 max-h-[600px] overflow-y-auto pr-2 custom-scrollbar">
                        <?php if (empty($upcoming_events)): ?>
                            <div class="text-center py-8 bg-gray-50 rounded-2xl border border-dashed border-gray-200">
                                <i class="fas fa-calendar-check text-gray-300 text-3xl mb-2"></i>
                                <p class="text-sm text-gray-500">No upcoming events</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($upcoming_events as $event):
                                $dateObj = new DateTime($event['activity_date']);
                                $isToday = $event['activity_date'] === $today_date;
                                ?>
                                <div onclick="showActivityDetailsSide(<?= $event['id'] ?>)"
                                    class="group flex gap-3 p-3 rounded-xl border border-gray-100 hover:border-gray-200 hover:bg-gray-50 transition-all duration-200 cursor-pointer">
                                    <div class="flex-shrink-0 w-12 text-center">
                                        <div class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">
                                            <?php echo $dateObj->format('M'); ?>
                                        </div>
                                        <div class="text-xl font-bold text-black">
                                            <?php echo $dateObj->format('d'); ?>
                                        </div>
                                    </div>

                                    <div class="flex-grow min-w-0">
                                        <h5
                                            class="text-sm font-bold text-black group-hover:text-emerald-600 transition-colors truncate">
                                            <?php echo htmlspecialchars($event['activity_type']); ?>
                                        </h5>
                                        <div class="flex items-center text-[10px] text-gray-600 mt-0.5">
                                            <i class="far fa-clock mr-1 text-gray-400"></i>
                                            <?php echo date('h:i A', strtotime($event['activity_time'])); ?>
                                        </div>
                                        <div class="text-[10px] text-gray-500 mt-1 truncate">
                                            #<?php echo htmlspecialchars($event['property_no']); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- ✅ Activity Log Drawer Overlay -->
<div id="activityDrawerBackdrop" onclick="toggleActivityDrawer(false)"
    class="fixed inset-0 bg-black/60 backdrop-blur-md z-[2000] transition-opacity duration-300 opacity-0 pointer-events-none">
</div>

<!-- ✅ Activity Log Drawer (Matched to Sidebar Design) -->
<div id="activityDrawer"
    class="fixed inset-y-0 right-0 w-full sm:w-[480px] z-[2010] shadow-[0_0_50px_rgba(0,0,0,0.5)] transform translate-x-full transition-transform duration-500 ease-[cubic-bezier(0.4,0,0.2,1)] flex flex-col border-l border-white/10"
    style="background: linear-gradient(180deg, #1a1a2e 0%, #16213e 100%);">

    <!-- Drawer Header (Matched to Sidebar Brand) -->
    <div class="p-8 border-b border-white/5 bg-white/5">
        <div class="flex justify-between items-center">
            <div class="flex items-center gap-4">
                <div
                    class="h-14 w-14 rounded-2xl bg-emerald-500/10 flex items-center justify-center text-emerald-400 border border-emerald-500/20 shadow-[0_0_20px_rgba(16,185,129,0.1)]">
                    <i class="fas fa-history text-2xl"></i>
                </div>
                <div>
                    <h5 class="text-xl font-bold text-white tracking-tight">Activity Log</h5>
                    <?php if ($user_location): ?>
                        <div class="flex items-center gap-2 mt-1">
                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                            <span class="text-[10px] text-emerald-400/80 font-bold uppercase tracking-widest">
                                <?= htmlspecialchars(ucfirst($user_location)) ?> Terminal
                            </span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <button onclick="toggleActivityDrawer(false)"
                class="h-10 w-10 flex items-center justify-center rounded-xl bg-white/5 text-white/40 hover:bg-red-500/20 hover:text-red-400 transition-all border border-white/10 group">
                <i class="fas fa-times transition-transform group-hover:rotate-90"></i>
            </button>
        </div>

        <!-- Header Action -->
        <div class="mt-8">
            <button data-bs-toggle="modal" data-bs-target="#addActivityLogModal"
                class="w-full h-14 bg-gradient-to-r from-emerald-600 to-teal-600 text-white rounded-2xl hover:from-emerald-500 hover:to-teal-500 font-extrabold transition-all shadow-xl shadow-emerald-900/20 flex items-center justify-center gap-3 transform active:scale-[0.98] group">
                <div
                    class="h-8 w-8 rounded-lg bg-white/20 flex items-center justify-center group-hover:scale-110 transition-transform">
                    <i class="fas fa-plus text-xs"></i>
                </div>
                <span class="tracking-wide uppercase text-sm">Create New Log Entry</span>
            </button>
        </div>
    </div>

    <!-- Drawer Content (Light Theme for Data) -->
    <div class="flex-1 overflow-y-auto custom-scrollbar p-6 space-y-4 bg-gray-50">
        <?php if (!empty($activity_logs)): ?>
            <?php foreach ($activity_logs as $log): ?>
                <?php
                $statusConfig = match ($log['status']) {
                    'Done' => ['bg' => 'bg-emerald-100', 'text' => 'text-emerald-700', 'border' => 'border-emerald-200'],
                    'Ongoing' => ['bg' => 'bg-amber-100', 'text' => 'text-amber-700', 'border' => 'border-amber-200'],
                    default => ['bg' => 'bg-blue-100', 'text' => 'text-blue-700', 'border' => 'border-blue-200']
                };
                $icon = match ($log['activity_type']) {
                    'Maintenance/Repair' => 'fa-tools',
                    'Inspection' => 'fa-search',
                    default => 'fa-clipboard-check'
                };
                ?>
                <div
                    class="group relative bg-white p-5 rounded-2xl border border-gray-100 shadow-sm hover:shadow-xl hover:border-emerald-200 transition-all duration-300">
                    <div class="flex items-start gap-4">
                        <div
                            class="h-12 w-12 rounded-xl bg-gray-50 flex items-center justify-center text-gray-400 group-hover:bg-emerald-500 group-hover:text-white transition-all shadow-sm">
                            <i class="fas <?= $icon ?> text-lg"></i>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex justify-between items-start">
                                <p
                                    class="text-sm font-extrabold text-gray-800 group-hover:text-emerald-600 transition-colors truncate">
                                    <?= htmlspecialchars($log['property_no']) ?>
                                </p>
                                <span
                                    class="text-[10px] text-gray-400 font-bold whitespace-nowrap ml-2 bg-gray-100 px-2 py-0.5 rounded-full">
                                    <?= date('M d, H:i', strtotime($log['date_time'])) ?>
                                </span>
                            </div>
                            <p class="text-xs text-gray-500 mt-1 font-medium italic">
                                <?= htmlspecialchars($log['activity_type']) ?>
                            </p>
                            <div class="mt-4 flex items-center justify-between">
                                <span
                                    class="px-3 py-1 text-[9px] font-black uppercase tracking-[0.15em] rounded-lg border <?= $statusConfig['bg'] ?> <?= $statusConfig['text'] ?> <?= $statusConfig['border'] ?>">
                                    <?= htmlspecialchars($log['status']) ?>
                                </span>
                                <div class="flex items-center gap-1.5 opacity-40 group-hover:opacity-100 transition-opacity">
                                    <div class="h-6 w-6 rounded-full bg-gray-100 flex items-center justify-center">
                                        <i class="fas fa-user text-[8px] text-gray-500"></i>
                                    </div>
                                    <span class="text-[9px] font-bold text-gray-400 uppercase tracking-tighter">
                                        <?= htmlspecialchars($log['performed_by'] ?? 'System') ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- Sidebar Active Indicator Style -->
                    <div
                        class="absolute left-0 top-1/2 -translate-y-1/2 w-1 h-0 bg-emerald-500 rounded-r-full group-hover:h-8 transition-all duration-300">
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="flex flex-col items-center justify-center h-full text-gray-300">
                <div
                    class="h-24 w-24 bg-white rounded-full flex items-center justify-center mb-6 shadow-sm border border-gray-100">
                    <i class="fas fa-clipboard-list text-5xl"></i>
                </div>
                <p class="font-bold uppercase tracking-widest text-xs">Zero Logs Found</p>
                <p class="text-[10px] mt-2 text-gray-400">Synchronize your terminal to see updates</p>
            </div>
        <?php endif; ?>
    </div>
</div>


<!-- ✅ Add Activity Modal - Modern Interactive Design -->
<div class="modal fade" id="addActivityModal" tabindex="-1" aria-labelledby="addActivityLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-2xl rounded-3xl overflow-hidden">
            <!-- Modal Header -->
            <div class="p-6 bg-white border-b border-gray-100 flex justify-between items-center">
                <div class="flex items-center gap-4">
                    <div
                        class="h-12 w-12 rounded-2xl bg-emerald-600 flex items-center justify-center text-white shadow-lg shadow-emerald-200">
                        <i class="fas fa-plus text-xl"></i>
                    </div>
                    <div>
                        <h5 class="text-xl font-extrabold text-gray-900 tracking-tight" id="addActivityLabel">Create
                            Schedule</h5>
                        <p class="text-xs text-emerald-600 font-bold uppercase tracking-widest">New Entry</p>
                    </div>
                </div>
                <button type="button"
                    class="h-10 w-10 flex items-center justify-center rounded-full hover:bg-gray-100 transition-colors text-gray-400 hover:text-gray-600"
                    data-bs-dismiss="modal" aria-label="Close">
                    <i class="fas fa-times text-lg"></i>
                </button>
            </div>

            <form method="POST" action="add_activity.php" class="flex flex-col">
                <input type="hidden" name="user_role" value="<?= $isStaffDashboard ? 'staff' : 'admin' ?>">

                <div class="modal-body p-8 space-y-6 bg-gray-50/30">
                    <!-- Activity Type -->
                    <div class="group">
                        <label
                            class="block text-xs font-bold text-gray-400 uppercase tracking-widest mb-2 px-1">Activity
                            Classification</label>
                        <div class="relative">
                            <div
                                class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-gray-400 group-focus-within:text-emerald-500 transition-colors">
                                <i class="fas fa-tag"></i>
                            </div>
                            <select name="activity_type"
                                class="form-select pl-11 h-12 bg-white border-gray-200 rounded-2xl focus:ring-emerald-500/20 focus:border-emerald-500 font-semibold text-gray-800 transition-all shadow-sm"
                                required>
                                <option value="" selected disabled>Select Activity Type</option>
                                <option value="Inspection">Inspection</option>
                                <option value="Maintenance/Repair">Maintenance/Repair</option>
                                <option value="Appointment">Appointment</option>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Property Reference -->
                        <div class="group">
                            <label
                                class="block text-xs font-bold text-gray-400 uppercase tracking-widest mb-2 px-1">Property
                                Reference</label>
                            <div class="relative">
                                <div
                                    class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-gray-400 group-focus-within:text-emerald-500">
                                    <i class="fas fa-barcode"></i>
                                </div>
                                <select name="property_no" id="add_property_no_select"
                                    class="form-select pl-11 h-12 bg-white border-gray-200 rounded-2xl focus:ring-emerald-500/20 focus:border-emerald-500 font-semibold text-gray-800 transition-all shadow-sm"
                                    required>
                                    <option value="" disabled selected>Select Property No.</option>
                                    <?php
                                    $property_query = $mysqli->query("SELECT property_no FROM equipment ORDER BY property_no ASC");
                                    if ($property_query && $property_query->num_rows > 0) {
                                        while ($row = $property_query->fetch_assoc()) {
                                            echo '<option value="' . htmlspecialchars($row['property_no']) . '">' . htmlspecialchars($row['property_no']) . '</option>';
                                        }
                                    } else {
                                        echo '<option disabled>No Property Records Found</option>';
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>

                        <!-- Location -->
                        <div class="group">
                            <label
                                class="block text-xs font-bold text-gray-400 uppercase tracking-widest mb-2 px-1">Target
                                Location</label>
                            <div class="relative">
                                <div
                                    class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-gray-400 group-focus-within:text-emerald-500">
                                    <i class="fas fa-map-marker-alt"></i>
                                </div>
                                <?php if ($user_location): ?>
                                    <input type="text"
                                        class="form-control pl-11 h-12 bg-gray-100 border-transparent rounded-2xl font-bold text-gray-600 cursor-not-allowed"
                                        value="<?= htmlspecialchars($user_location) ?>" readonly
                                        title="Your location is fixed">
                                    <input type="hidden" name="location" value="<?= htmlspecialchars($user_location) ?>">
                                <?php else: ?>
                                    <select name="location"
                                        class="form-select pl-11 h-12 bg-white border-gray-200 rounded-2xl focus:ring-emerald-500/20 focus:border-emerald-500 font-semibold text-gray-800 transition-all shadow-sm"
                                        required>
                                        <option value="" disabled selected>Select Location</option>
                                        <option value="Mamburao">Mamburao</option>
                                        <option value="Sablayan">Sablayan</option>
                                        <option value="San Jose">San Jose</option>
                                        <option value="Lubang">Lubang</option>
                                    </select>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Execution Date -->
                        <div class="group">
                            <label
                                class="block text-xs font-bold text-gray-400 uppercase tracking-widest mb-2 px-1">Execution
                                Date</label>
                            <div class="relative">
                                <div
                                    class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-gray-400 group-focus-within:text-emerald-500">
                                    <i class="fas fa-calendar-day"></i>
                                </div>
                                <input type="date" name="activity_date"
                                    class="form-control pl-11 h-12 bg-white border-gray-200 rounded-2xl focus:ring-emerald-500/20 focus:border-emerald-500 font-semibold text-gray-800 transition-all shadow-sm"
                                    required>
                            </div>
                        </div>

                        <!-- Execution Time -->
                        <div class="group">
                            <label
                                class="block text-xs font-bold text-gray-400 uppercase tracking-widest mb-2 px-1">Execution
                                Time</label>
                            <div class="relative">
                                <div
                                    class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-gray-400 group-focus-within:text-emerald-500">
                                    <i class="fas fa-clock"></i>
                                </div>
                                <input type="time" name="activity_time"
                                    class="form-control pl-11 h-12 bg-white border-gray-200 rounded-2xl focus:ring-emerald-500/20 focus:border-emerald-500 font-semibold text-gray-800 transition-all shadow-sm"
                                    required>
                            </div>
                        </div>
                    </div>

                    <!-- Remarks -->
                    <div class="group">
                        <label
                            class="block text-xs font-bold text-gray-400 uppercase tracking-widest mb-2 px-1">Execution
                            Details / Remarks</label>
                        <div class="relative">
                            <div
                                class="absolute top-4 left-4 pointer-events-none text-gray-400 group-focus-within:text-emerald-500">
                                <i class="fas fa-comment-alt"></i>
                            </div>
                            <textarea name="remarks"
                                class="form-control pl-11 pt-3 min-h-[120px] bg-white border-gray-200 rounded-3xl focus:ring-emerald-500/20 focus:border-emerald-500 font-medium text-gray-700 transition-all shadow-sm resize-none"
                                placeholder="Enter additional details or specific instructions here..."></textarea>
                        </div>
                    </div>
                </div>

                <!-- Modal Footer -->
                <div class="modal-footer p-6 bg-white border-t border-gray-100 flex items-center gap-3">
                    <button type="button"
                        class="h-12 px-8 bg-gray-100 text-gray-600 rounded-2xl hover:bg-gray-200 font-bold transition-all"
                        data-bs-dismiss="modal">Discard</button>
                    <button type="submit"
                        class="flex-1 h-12 bg-emerald-600 text-white rounded-2xl hover:bg-emerald-700 font-bold transition-all shadow-lg shadow-emerald-200 flex items-center justify-center gap-2 transform active:scale-[0.98]">
                        <i class="fas fa-check-circle"></i> Confirm Schedule
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Activity Log Modal - Modern Interactive Design -->
<div class="modal fade" id="addActivityLogModal" tabindex="-1" aria-labelledby="addActivityLogLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-2xl rounded-3xl overflow-hidden">
            <!-- Modal Header -->
            <div class="p-6 bg-white border-b border-gray-100 flex justify-between items-center">
                <div class="flex items-center gap-4">
                    <div
                        class="h-12 w-12 rounded-2xl bg-emerald-600 flex items-center justify-center text-white shadow-lg shadow-emerald-200">
                        <i class="fas fa-clipboard-list text-xl"></i>
                    </div>
                    <div>
                        <h5 class="text-xl font-extrabold text-gray-900 tracking-tight" id="addActivityLogLabel">Add
                            Activity Log</h5>
                        <p class="text-xs text-emerald-600 font-bold uppercase tracking-widest">New Log Entry</p>
                    </div>
                </div>
                <button type="button"
                    class="h-10 w-10 flex items-center justify-center rounded-full hover:bg-gray-100 transition-colors text-gray-400 hover:text-gray-600"
                    data-bs-dismiss="modal" aria-label="Close">
                    <i class="fas fa-times text-lg"></i>
                </button>
            </div>

            <form id="addActivityLogForm" class="flex flex-col">
                <div class="modal-body p-8 space-y-6 bg-gray-50/30">

                    <!-- Activity Type -->
                    <div class="group">
                        <label
                            class="block text-xs font-bold text-gray-400 uppercase tracking-widest mb-2 px-1">Activity
                            Classification</label>
                        <div class="relative">
                            <div
                                class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-gray-400 group-focus-within:text-emerald-500 transition-colors">
                                <i class="fas fa-tag"></i>
                            </div>
                            <select name="activity_type"
                                class="form-select pl-11 h-12 bg-white border-gray-200 rounded-2xl focus:ring-emerald-500/20 focus:border-emerald-500 font-semibold text-gray-800 transition-all shadow-sm"
                                required>
                                <option value="" disabled selected>Select Activity Type</option>
                                <option value="Maintenance/Repair">Maintenance/Repair</option>
                                <option value="Inspection">Inspection</option>
                            </select>
                        </div>
                    </div>

                    <!-- Property No -->
                    <div class="group">
                        <label
                            class="block text-xs font-bold text-gray-400 uppercase tracking-widest mb-2 px-1">Property
                            Reference</label>
                        <div class="relative">
                            <div
                                class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-gray-400 group-focus-within:text-emerald-500">
                                <i class="fas fa-barcode"></i>
                            </div>
                            <select name="property_no" id="add_log_property_no_select"
                                class="form-select pl-11 h-12 bg-white border-gray-200 rounded-2xl focus:ring-emerald-500/20 focus:border-emerald-500 font-semibold text-gray-800 transition-all shadow-sm"
                                required>
                                <option value="" disabled selected>Select Property No.</option>
                                <?php
                                $properties = $mysqli->query("SELECT property_no FROM equipment ORDER BY property_no ASC");
                                if ($properties && $properties->num_rows > 0) {
                                    while ($prop = $properties->fetch_assoc()) {
                                        echo '<option value="' . htmlspecialchars($prop['property_no']) . '">' . htmlspecialchars($prop['property_no']) . '</option>';
                                    }
                                }
                                ?>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Status -->
                        <div class="group">
                            <label
                                class="block text-xs font-bold text-gray-400 uppercase tracking-widest mb-2 px-1">Current
                                Status</label>
                            <div class="relative">
                                <div
                                    class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-gray-400 group-focus-within:text-emerald-500">
                                    <i class="fas fa-tasks"></i>
                                </div>
                                <select name="status"
                                    class="form-select pl-11 h-12 bg-white border-gray-200 rounded-2xl focus:ring-emerald-500/20 focus:border-emerald-500 font-semibold text-gray-800 transition-all shadow-sm"
                                    required>
                                    <option value="" disabled selected>Select Status</option>
                                    <option value="Done">Done</option>
                                    <option value="Ongoing">Ongoing</option>
                                    <option value="Pending">Pending</option>
                                </select>
                            </div>
                        </div>

                        <!-- Location Block -->
                        <div class="group">
                            <label class="block text-xs font-bold text-gray-400 uppercase tracking-widest mb-2 px-1">Log
                                Location</label>
                            <?php
                            $userLocation = $currentUser['location'] ?? '';
                            $displayLocation = 'Not Set';
                            if ($userLocation) {
                                $locationDisplay = [
                                    'maintenance_mamburao' => 'Mamburao',
                                    'sablayan' => 'Sablayan',
                                    'lubang' => 'Lubang',
                                    'san_jose' => 'San Jose',
                                    'mamburao' => 'Mamburao',
                                    'Maintenance Mamburao' => 'Mamburao'
                                ];
                                $displayLocation = isset($locationDisplay[$userLocation]) ? $locationDisplay[$userLocation] : ucfirst(str_replace('_', ' ', $userLocation));
                            }
                            ?>
                            <div class="relative">
                                <div
                                    class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-gray-400">
                                    <i class="fas fa-map-marker-alt"></i>
                                </div>
                                <input type="text"
                                    class="form-control pl-11 h-12 bg-gray-100 border-transparent rounded-2xl font-bold text-gray-600 cursor-not-allowed"
                                    value="<?= htmlspecialchars($displayLocation) ?>" readonly>
                                <input type="hidden" name="location" value="<?= htmlspecialchars($userLocation) ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Details/Remarks -->
                    <div class="group">
                        <label class="block text-xs font-bold text-gray-400 uppercase tracking-widest mb-2 px-1">Log
                            Details /
                            Remarks</label>
                        <div class="relative">
                            <div
                                class="absolute top-4 left-4 pointer-events-none text-gray-400 group-focus-within:text-emerald-500">
                                <i class="fas fa-comment-dots"></i>
                            </div>
                            <textarea name="details"
                                class="form-control pl-11 pt-3 min-h-[100px] bg-white border-gray-200 rounded-3xl focus:ring-emerald-500/20 focus:border-emerald-500 font-medium text-gray-700 transition-all shadow-sm resize-none"
                                placeholder="What happened? Enter details here..."></textarea>
                        </div>
                    </div>

                    <input type="hidden" name="date_time" value="<?= date('Y-m-d H:i:s') ?>">
                </div>

                <!-- Modal Footer -->
                <div class="modal-footer p-6 bg-white border-t border-gray-100 flex items-center gap-3">
                    <button type="button"
                        class="h-12 px-8 bg-gray-100 text-gray-600 rounded-2xl hover:bg-gray-200 font-bold transition-all"
                        data-bs-dismiss="modal">Discard</button>
                    <button type="submit"
                        class="flex-1 h-12 bg-emerald-600 text-white rounded-2xl hover:bg-emerald-700 font-bold transition-all shadow-lg shadow-emerald-200 flex items-center justify-center gap-2 transform active:scale-[0.98]">
                        <i class="fas fa-save"></i> Save Log Entry
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ✅ View/Edit Activity Modal - Modern Interactive Design -->
<div class="modal fade" id="viewActivityModalSide" tabindex="-1" aria-labelledby="viewActivityLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-2xl rounded-3xl overflow-hidden">
            <!-- Modal Header with subtle pattern -->
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
                            <p class="text-xs text-gray-500 font-medium uppercase tracking-widest">Activity Management
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

            <form id="viewActivityFormSide" class="flex flex-col h-full">
                <div class="modal-body p-8 space-y-6 bg-gray-50/30">
                    <input type="hidden" name="id" id="activity_id_side">
                    <input type="hidden" name="location" id="edit_location_hidden">

                    <!-- Activity Type Selection -->
                    <div class="group">
                        <label
                            class="block text-xs font-bold text-gray-400 uppercase tracking-widest mb-2 px-1">Activity
                            Classification</label>
                        <div class="relative">
                            <div
                                class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-gray-400 group-focus-within:text-emerald-500 transition-colors">
                                <i class="fas fa-tag"></i>
                            </div>
                            <select name="activity_type" id="activity_type_side"
                                class="form-select pl-11 h-12 bg-white border-gray-200 rounded-2xl focus:ring-emerald-500/20 focus:border-emerald-500 font-semibold text-gray-800 transition-all shadow-sm disabled:bg-white disabled:opacity-100 disabled:border-transparent disabled:shadow-none disabled:px-11 disabled:cursor-default"
                                disabled required>
                                <option value="">Select Type</option>
                                <option value="Inspection">Inspection</option>
                                <option value="Maintenance/Repair">Maintenance/Repair</option>
                                <option value="Appointment">Appointment</option>
                            </select>
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
                                    class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-gray-400 group-focus-within:text-emerald-500">
                                    <i class="fas fa-barcode"></i>
                                </div>
                                <select name="property_no" id="property_no_select_side"
                                    class="form-select pl-11 h-12 bg-white border-gray-200 rounded-2xl focus:ring-emerald-500/20 focus:border-emerald-500 font-semibold text-gray-800 transition-all shadow-sm disabled:bg-white disabled:opacity-100 disabled:border-transparent disabled:shadow-none disabled:px-11"
                                    disabled required>
                                    <option value="" disabled>Select Property No.</option>
                                    <?php
                                    $prop_q = $mysqli->query("SELECT property_no FROM equipment ORDER BY property_no ASC");
                                    if ($prop_q && $prop_q->num_rows > 0) {
                                        while ($p = $prop_q->fetch_assoc()) {
                                            echo '<option value="' . htmlspecialchars($p['property_no']) . '">' . htmlspecialchars($p['property_no']) . '</option>';
                                        }
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>

                        <!-- Location -->
                        <div class="group">
                            <label
                                class="block text-xs font-bold text-gray-400 uppercase tracking-widest mb-2 px-1">Current
                                Location</label>
                            <div class="relative">
                                <div
                                    class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-gray-400 group-focus-within:text-emerald-500">
                                    <i class="fas fa-map-marker-alt"></i>
                                </div>
                                <input type="text" id="location_display_side"
                                    class="form-control pl-11 h-12 bg-white border-gray-200 rounded-2xl font-semibold text-gray-800 shadow-sm border-transparent bg-white cursor-default disabled:bg-white disabled:shadow-none"
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
                                    class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-gray-400 group-focus-within:text-emerald-500">
                                    <i class="fas fa-calendar-day"></i>
                                </div>
                                <input type="date" name="activity_date" id="activity_date_side"
                                    class="form-control pl-11 h-12 bg-white border-gray-200 rounded-2xl focus:ring-emerald-500/20 focus:border-emerald-500 font-semibold text-gray-800 transition-all shadow-sm disabled:bg-white disabled:opacity-100 disabled:border-transparent disabled:shadow-none"
                                    disabled required>
                            </div>
                        </div>

                        <!-- Time -->
                        <div class="group">
                            <label
                                class="block text-xs font-bold text-gray-400 uppercase tracking-widest mb-2 px-1">Time
                                Window</label>
                            <div class="relative">
                                <div
                                    class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-gray-400 group-focus-within:text-emerald-500">
                                    <i class="fas fa-clock"></i>
                                </div>
                                <input type="time" name="activity_time" id="activity_time_side"
                                    class="form-control pl-11 h-12 bg-white border-gray-200 rounded-2xl focus:ring-emerald-500/20 focus:border-emerald-500 font-semibold text-gray-800 transition-all shadow-sm disabled:bg-white disabled:opacity-100 disabled:border-transparent disabled:shadow-none"
                                    disabled required>
                            </div>
                        </div>
                    </div>

                    <!-- Remarks Area -->
                    <div class="group">
                        <label
                            class="block text-xs font-bold text-gray-400 uppercase tracking-widest mb-2 px-1">Additional
                            Remarks</label>
                        <div class="relative">
                            <div
                                class="absolute top-4 left-4 pointer-events-none text-gray-400 group-focus-within:text-emerald-500">
                                <i class="fas fa-comment-alt"></i>
                            </div>
                            <textarea name="remarks" id="activity_remarks_side"
                                class="form-control pl-11 pt-3 min-h-[120px] bg-white border-gray-200 rounded-3xl focus:ring-emerald-500/20 focus:border-emerald-500 font-medium text-gray-700 transition-all shadow-sm disabled:bg-white disabled:opacity-100 disabled:border-transparent disabled:shadow-none resize-none"
                                placeholder="No additional notes provided..." disabled></textarea>
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

                <div class="modal-footer p-6 bg-white border-t border-gray-100 flex items-center gap-3">
                    <?php if (!$isStaffDashboard): ?>
                        <button type="button" id="editToggleBtnSide"
                            class="flex-1 h-12 bg-white border-2 border-emerald-500 text-emerald-600 rounded-2xl hover:bg-emerald-50 font-bold transition-all flex items-center justify-center gap-2">
                            <i class="fas fa-edit"></i> Edit Schedule
                        </button>
                        <button type="submit" id="saveActivityBtnSide"
                            class="flex-1 h-12 bg-emerald-600 text-white rounded-2xl hover:bg-emerald-700 font-bold transition-all shadow-lg shadow-emerald-200 flex items-center justify-center gap-2"
                            style="display:none;">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                    <?php endif; ?>
                    <button type="button"
                        class="h-12 px-8 bg-gray-100 text-gray-600 rounded-2xl hover:bg-gray-200 font-bold transition-all"
                        data-bs-dismiss="modal">Close</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // Diagnostic Logging
    console.log('--- Schedule View Script Loading ---');

    // 1. CONFIGURATION & STATE
    var pathPrefix = '<?= $pathPrefix ?>';
    var isStaffDashboard = <?= $isStaffDashboard ? 'true' : 'false' ?>;
    var userLocation = "<?= $user_location ? htmlspecialchars($user_location) : '' ?>";
    var userRole = "<?= $user_role ?>";

    // 2. UI HELPERS
    window.toggleActivityDrawer = function (open) {
        const drawer = document.getElementById('activityDrawer');
        const backdrop = document.getElementById('activityDrawerBackdrop');
        if (!drawer || !backdrop) return;
        if (open) {
            drawer.classList.remove('translate-x-full');
            backdrop.classList.remove('opacity-0', 'pointer-events-none');
            document.body.style.overflow = 'hidden';
        } else {
            drawer.classList.add('translate-x-full');
            backdrop.classList.add('opacity-0', 'pointer-events-none');
            document.body.style.overflow = '';
        }
    };

    window.showStatusMessage = function (message, type) {
        const alertDiv = document.getElementById('statusUpdateAlert');
        const messageSpan = document.getElementById('statusUpdateMessage');
        if (!alertDiv || !messageSpan) return;
        alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
        messageSpan.textContent = message;
        alertDiv.style.display = 'block';
        setTimeout(() => {
            try {
                const bsAlert = bootstrap.Alert.getOrCreateInstance(alertDiv);
                bsAlert.close();
            } catch (e) { }
        }, 5000);
    };

    // 3. CALENDAR LOGIC
    window.renderCalendar = function () {
        console.log('Attempting to render calendar...');
        const calendarEl = document.getElementById('calendar');

        if (!calendarEl) {
            console.error('CRITICAL: #calendar element not found in DOM.');
            return;
        }

        if (typeof FullCalendar === 'undefined') {
            console.error('CRITICAL: FullCalendar library is not loaded. Check dashboard head.');
            calendarEl.innerHTML = '<div class="alert alert-danger">Error: FullCalendar library missing.</div>';
            return;
        }

        let fetchUrl = 'fetch_activities.php';
        if (userLocation) fetchUrl += '?location=' + encodeURIComponent(userLocation);

        console.log('Fetching events from:', fetchUrl);

        fetch(fetchUrl)
            .then(r => {
                if (!r.ok) throw new Error('Network response was not ok');
                return r.json();
            })
            .then(events => {
                console.log('Events received:', events.length);
                // Properly destroy and reset calendar instance
                if (window.calendarInstance) {
                    window.calendarInstance.destroy();
                    window.calendarInstance = null;
                }

                // Ensure calendar element is clean
                calendarEl.innerHTML = '';

                window.calendarInstance = new FullCalendar.Calendar(calendarEl, {
                    initialView: 'dayGridMonth',
                    headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,listWeek' },
                    height: 650,
                    events: events,
                    eventClick: function (info) { window.showActivityDetailsSide(info.event.id); },
                    eventDidMount: function (info) {
                        const title = info.event.title || '';
                        let icon = 'fa-calendar-alt';
                        if (title.includes('Inspection')) icon = 'fa-search';
                        else if (title.includes('Repair') || title.includes('Maintenance')) icon = 'fa-tools';
                        const titleEl = info.el.querySelector('.fc-event-title');
                        if (titleEl) titleEl.innerHTML = `<i class="fas ${icon} mr-1.5 opacity-60"></i> ${title}`;
                    }
                });
                window.calendarInstance.render();
                console.log('FullCalendar rendering complete.');
            })
            .catch(err => {
                console.error('Calendar Fetch Error:', err);
                calendarEl.innerHTML = '<div class="p-10 text-center text-red-500 font-bold">Failed to load events. Check console.</div>';
            });
    };

    // 4. ACTIVITY DETAILS
    window.showActivityDetailsSide = function (activityId) {
        console.log('Showing details for activity:', activityId);
        document.querySelectorAll('.fc-popover').forEach(p => p.remove());
        const fetchUrl = pathPrefix + 'fetch_single_activity.php?id=' + activityId;
        fetch(fetchUrl).then(r => r.json()).then(data => {
            if (!data.id) return;
            document.getElementById('activity_id_side').value = data.id;
            document.getElementById('activity_type_side').value = data.activity_type || '';
            document.getElementById('property_no_select_side').value = data.property_no || '';
            document.getElementById('location_display_side').value = data.location || '';
            document.getElementById('activity_date_side').value = data.activity_date || '';
            document.getElementById('activity_time_side').value = data.activity_time || '';
            document.getElementById('activity_remarks_side').value = data.remarks || '';

            const pTag = document.getElementById('modal_property_tag');
            if (pTag) pTag.textContent = data.property_no || 'N/A';

            window.toggleViewModeSide(false);
            const modalEl = document.getElementById('viewActivityModalSide');
            const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
            modal.show();
        });
    };

    window.toggleViewModeSide = function (editing) {
        ['activity_type_side', 'activity_date_side', 'activity_time_side', 'activity_remarks_side'].forEach(id => {
            const el = document.getElementById(id); if (el) el.disabled = !editing;
        });
        const saveBtn = document.getElementById('saveActivityBtnSide');
        const editBtn = document.getElementById('editToggleBtnSide');
        if (saveBtn) saveBtn.style.display = editing ? 'flex' : 'none';
        if (editBtn) editBtn.innerHTML = editing ? '<i class="fas fa-times"></i> Cancel' : '<i class="fas fa-edit"></i> Edit Schedule';
    };

    // 6. INITIALIZATION CALL
    // Short delay to ensure DOM is ready after AJAX injection
    setTimeout(function () {
        console.log('Executing initialization calls...');
        window.renderCalendar();

        // Initialize Select2 if jQuery and Select2 are available
        if (typeof jQuery !== 'undefined' && typeof jQuery.fn.select2 !== 'undefined') {
            jQuery('#add_property_no_select').select2({ dropdownParent: jQuery('#addActivityModal'), width: '100%' });
            jQuery('#add_log_property_no_select').select2({ dropdownParent: jQuery('#addActivityLogModal'), width: '100%' });
        } else {
            console.warn('Select2 or jQuery not available for modal dropdowns.');
        }
    }, 600);

    // Global Esc listener
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') window.toggleActivityDrawer(false);
    });

    // 8. ADD ACTIVITY LOG AJAX HANDLER
    const addLogForm = document.getElementById('addActivityLogForm');
    if (addLogForm) {
        addLogForm.addEventListener('submit', function (e) {
            e.preventDefault();
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalBtnText = submitBtn.innerHTML;

            // Show loading state
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> SAVING...';

            const formData = new FormData(this);

            fetch('add_activity_log.php', {
                method: 'POST',
                body: formData
            })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        window.showStatusMessage(data.message, 'success');
                        // Reset form and close modal
                        addLogForm.reset();
                        const modalEl = document.getElementById('addActivityLogModal');
                        const modal = bootstrap.Modal.getInstance(modalEl);
                        if (modal) modal.hide();

                        // Refresh the view to show new log
                        if (typeof window.loadContent === 'function') {
                            setTimeout(() => window.loadContent('activities'), 1000);
                        } else {
                            window.location.reload();
                        }
                    } else {
                        window.showStatusMessage(data.message, 'danger');
                    }
                })
                .catch(err => {
                    console.error('Log submission failed:', err);
                    window.showStatusMessage('Submission failed. Check console.', 'danger');
                })
                .finally(() => {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalBtnText;
                });
        });
    }

    console.log('--- Schedule View Script Loaded Successfully ---');
</script>