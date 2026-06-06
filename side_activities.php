<?php
require_once __DIR__ . '/config.php';
include_once 'profile_modal.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Authentication check - must be logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
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

// Fetch appointment requests based on user's location
$user_appointments = [];
$is_staff_user = ($currentUser['role'] ?? '') === 'staff';

// If user is admin/staff and has a location, filter by that location
if ($user_location && ($user_role === 'admin' || $user_role === 'staff')) {
    // Admin/staff with specific location - only show appointments from their location
    $stmt = $mysqli->prepare("SELECT * FROM appointment_requests WHERE location = ? ORDER BY created_at DESC");
    $stmt->bind_param("s", $user_location);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $user_appointments[] = $row;
    }
    $stmt->close();
} elseif ($user_id && $user_role === 'user') {
    // Regular user - show only their own appointments
    $stmt = $mysqli->prepare("SELECT * FROM appointment_requests WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $user_appointments[] = $row;
    }
    $stmt->close();
} else {
    // Fallback for users without location or other roles - show all appointments
    $result = $mysqli->query("SELECT * FROM appointment_requests ORDER BY created_at DESC");
    while ($row = $result->fetch_assoc()) {
        $user_appointments[] = $row;
    }
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

    /* Location filter indicator */
    .location-filter-indicator {
        background: #e7f3ff;
        border-left: 4px solid #0d6efd;
        padding: 0.75rem 1rem;
        border-radius: 8px;
        margin-bottom: 1rem;
    }

    /* Button Styles */
    .view-appointment-btn {
        width: 32px;
        height: 32px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0;
        font-size: 14px;
        border-radius: 4px;
        transition: all 0.2s;
        background-color: transparent;
        border: 1px solid #0d6efd;
        color: #0d6efd;
    }

    .view-appointment-btn:hover {
        background-color: #0d6efd;
        color: white;
    }

    .approve-btn,
    .reject-btn {
        width: 32px;
        height: 32px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0;
        font-size: 14px;
        border-radius: 4px;
        transition: all 0.2s;
    }

    .approve-btn {
        background-color: transparent;
        border: 1px solid #198754;
        color: #198754;
    }

    .approve-btn:hover {
        background-color: #198754;
        color: white;
        border-color: #198754;
    }

    .reject-btn {
        background-color: transparent;
        border: 1px solid #dc3545;
        color: #dc3545;
    }

    .reject-btn:hover {
        background-color: #dc3545;
        color: white;
        border-color: #dc3545;
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

<!-- Load Dependencies -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>


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
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
                <div>
                    <h3 class="text-xl font-bold text-gray-900">Schedule Calendar</h3>
                    <p class="text-xs text-gray-500">Activities in <?php echo htmlspecialchars($user_location); ?></p>
                </div>
                
                <!-- Legend Block -->
                <div class="flex items-center gap-6 px-4 py-2 bg-gray-50 rounded-2xl border border-gray-100">
                    <div class="flex items-center gap-2">
                        <span class="h-3 w-3 rounded-full bg-emerald-500 shadow-[0_0_8px_rgba(16,185,129,0.4)]"></span>
                        <span class="text-[10px] font-black uppercase tracking-widest text-gray-500">Inspection</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="h-3 w-3 rounded-full bg-amber-500 shadow-[0_0_8px_rgba(245,158,11,0.4)]"></span>
                        <span class="text-[10px] font-black uppercase tracking-widest text-gray-500">Repair</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="h-3 w-3 rounded-full bg-blue-500 shadow-[0_0_8px_rgba(59,130,246,0.4)]"></span>
                        <span class="text-[10px] font-black uppercase tracking-widest text-gray-500">Appointment</span>
                    </div>
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
                    <div id="calendar" class="min-h-[500px]"></div>
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
                                    <span class="text-[9px] font-bold text-gray-400 uppercase tracking-tighter">Verified
                                        Log</span>
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

<div class="mt-8">
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="p-6 border-b border-gray-100 flex justify-between items-center">
            <div>
                <h3 class="text-lg font-bold text-gray-800">Appointment Requests</h3>
                <p class="text-sm text-gray-500">Pending and approved requests from users</p>
            </div>
            <div class="flex items-center gap-3">
                <!-- Status Filters -->
                <div class="flex bg-gray-100 p-1 rounded-xl border border-gray-200">
                    <button onclick="filterAppointments('pending')" id="filter-pending" class="status-filter-btn px-4 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest transition-all bg-white text-amber-600 shadow-sm border border-amber-200">
                        Pending
                    </button>
                    <button onclick="filterAppointments('approved')" id="filter-approved" class="status-filter-btn px-4 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest transition-all text-gray-500 hover:text-emerald-600">
                        Approved
                    </button>
                    <button onclick="filterAppointments('rejected')" id="filter-rejected" class="status-filter-btn px-4 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest transition-all text-gray-500 hover:text-red-600">
                        Rejected
                    </button>
                </div>

                <?php if ($user_location && ($user_role === 'admin' || $user_role === 'staff')): ?>
                    <div
                        class="flex items-center gap-2 px-3 py-1.5 bg-blue-50 text-blue-700 rounded-lg text-xs font-medium border border-blue-100">
                        <i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars(ucfirst($user_location)) ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="overflow-hidden">
            <?php if (empty($user_appointments)): ?>
                <div class="text-center py-16 bg-white">
                    <div class="bg-gray-50 rounded-full h-20 w-20 flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-inbox text-3xl text-gray-300"></i>
                    </div>
                    <h4 class="text-gray-900 font-bold">No Requests Found</h4>
                    <p class="text-gray-500 text-sm mt-1">There are no pending appointment requests at this time.</p>
                </div>
            <?php else: ?>
                <!-- Scrollable Table Container -->
                <div class="max-h-[500px] overflow-y-auto custom-scrollbar relative">
                    <table class="w-full text-left border-separate border-spacing-0">
                        <thead class="sticky top-0 z-20">
                            <tr class="bg-gray-100 shadow-sm">
                                <th
                                    class="px-8 py-5 text-xs uppercase tracking-[0.1em] text-black-500 font-black border-b border-gray-200">
                                    Pre-Repair #</th>
                                <th
                                    class="px-8 py-5 text-xs uppercase tracking-[0.1em] text-black-500 font-black border-b border-gray-200">
                                    Property</th>
                                <th
                                    class="px-8 py-5 text-xs uppercase tracking-[0.1em] text-black-500 font-black border-b border-gray-200">
                                    Date & Time</th>
                                <th
                                    class="px-8 py-5 text-xs uppercase tracking-[0.1em] text-black-500 font-black border-b border-gray-200">
                                    Status</th>
                                <th
                                    class="px-8 py-5 text-xs uppercase tracking-[0.1em] text-black-500 font-black border-b border-gray-200 text-center">
                                    Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 bg-white">
                            <?php foreach ($user_appointments as $appt): ?>
                                <tr id="appointment-row-<?= $appt['id'] ?>" data-status="<?= strtolower($appt['status']) ?>" class="hover:bg-blue-50/30 transition-colors group appointment-row">
                                    <td class="px-8 py-6 text-base text-gray-700 font-medium">
                                        <span
                                            class="bg-gray-50 px-3 py-1 rounded-lg border border-gray-100"><?= htmlspecialchars($appt['pre_repair_no']) ?></span>
                                    </td>
                                    <td class="px-8 py-6 text-base text-gray-600">
                                        <div class="flex items-center gap-3">
                                            <div
                                                class="h-10 w-10 rounded-xl bg-emerald-50 flex items-center justify-center text-emerald-600">
                                                <i class="fas fa-box text-sm"></i>
                                            </div>
                                            <?= htmlspecialchars($appt['property_no']) ?>
                                        </div>
                                    </td>
                                    <td class="px-8 py-6 text-base text-gray-600">
                                        <div class="flex flex-col gap-0.5">
                                            <span
                                                class="text-gray-900"><?= !empty($appt['appointment_date']) ? date('M d, Y', strtotime($appt['appointment_date'])) : 'N/A' ?></span>
                                            <span
                                                class="text-xs text-gray-400 font-medium tracking-wide"><?= !empty($appt['appointment_time']) ? date('h:i A', strtotime($appt['appointment_time'])) : '' ?></span>
                                        </div>
                                    </td>
                                    <td class="px-8 py-6">
                                        <?php
                                        $statusStyles = [
                                            'pending' => 'bg-amber-50 text-amber-700 border-amber-200',
                                            'approved' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                                            'rejected' => 'bg-red-50 text-red-700 border-red-200',
                                            'cancelled' => 'bg-gray-50 text-gray-600 border-gray-200'
                                        ];
                                        $style = $statusStyles[$appt['status']] ?? $statusStyles['cancelled'];
                                        ?>
                                        <span id="status-badge-<?= $appt['id'] ?>"
                                            class="px-4 py-1.5 rounded-full text-[10px] font-black uppercase tracking-widest border shadow-sm <?= $style ?>">
                                            <?= ucfirst($appt['status']) ?>
                                        </span>
                                    </td>
                                    <td class="px-8 py-6 text-center">
                                        <div class="flex justify-center items-center gap-3">
                                            <button type="button"
                                                class="view-appointment-btn h-10 w-10 flex items-center justify-center bg-white border border-gray-200 text-blue-600 hover:bg-blue-600 hover:text-white hover:border-blue-600 rounded-xl transition-all shadow-sm"
                                                title="View Details" data-id="<?= htmlspecialchars($appt['id']) ?>"
                                                data-pre-repair="<?= htmlspecialchars($appt['pre_repair_no']) ?>"
                                                data-property="<?= htmlspecialchars($appt['property_no']) ?>"
                                                data-location="<?= htmlspecialchars($appt['location']) ?>"
                                                data-date="<?= !empty($appt['appointment_date']) ? date('d/m/Y', strtotime($appt['appointment_date'])) : 'N/A' ?>"
                                                data-time="<?= !empty($appt['appointment_time']) ? date('h:i A', strtotime($appt['appointment_time'])) : 'N/A' ?>"
                                                data-status="<?= htmlspecialchars($appt['status']) ?>"
                                                data-created="<?= !empty($appt['created_at']) ? date('d/m/Y h:i A', strtotime($appt['created_at'])) : 'N/A' ?>"
                                                data-remarks="<?= htmlspecialchars($appt['remarks']) ?>">
                                                <i class="fas fa-eye"></i>
                                            </button>

                                            <?php if (($user_role === 'admin' || $user_role === 'staff') && $appt['status'] === 'pending'): ?>
                                                <button type="button"
                                                    class="approve-btn h-10 w-10 flex items-center justify-center bg-white border border-gray-200 text-emerald-600 hover:bg-emerald-600 hover:text-white hover:border-emerald-600 rounded-xl transition-all shadow-sm"
                                                    data-id="<?= htmlspecialchars($appt['id']) ?>" title="Approve">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                                <button type="button"
                                                    class="reject-btn h-10 w-10 flex items-center justify-center bg-white border border-gray-200 text-red-500 hover:bg-red-500 hover:text-white hover:border-red-500 rounded-xl transition-all shadow-sm"
                                                    data-id="<?= htmlspecialchars($appt['id']) ?>" title="Reject">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
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

            <form method="POST" action="<?= $pathPrefix ?>add_activity.php" class="flex flex-col">
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

            <form method="POST" action="<?= $pathPrefix ?>add_activity_log.php" class="flex flex-col">
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

<!-- ✅ Appointment Details Modal - Modern Interactive Design -->
<div class="modal fade" id="appointmentDetailsModal" tabindex="-1" aria-labelledby="appointmentDetailsLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-2xl rounded-3xl overflow-hidden">
            <!-- Modal Header -->
            <div class="p-6 bg-white border-b border-gray-100 flex justify-between items-center">
                <div class="flex items-center gap-4">
                    <div class="h-12 w-12 rounded-2xl bg-blue-50 flex items-center justify-center text-blue-600 shadow-sm">
                        <i class="fas fa-file-invoice text-xl"></i>
                    </div>
                    <div>
                        <h5 class="text-xl font-extrabold text-gray-900 tracking-tight" id="appointmentDetailsLabel">Request Details</h5>
                        <p class="text-xs text-blue-600 font-bold uppercase tracking-widest">Appointment Management</p>
                    </div>
                </div>
                <button type="button" class="h-10 w-10 flex items-center justify-center rounded-full hover:bg-gray-100 transition-colors text-gray-400 hover:text-gray-600" data-bs-dismiss="modal" aria-label="Close">
                    <i class="fas fa-times text-lg"></i>
                </button>
            </div>

            <div class="modal-body p-8 space-y-6 bg-gray-50/30">
                <input type="hidden" id="detail-appointment-id">
                <!-- Status & ID Header -->
                <div class="flex justify-between items-center bg-white p-4 rounded-2xl border border-gray-100 shadow-sm">
                    <div class="flex items-center gap-2">
                        <span class="text-xs font-black text-gray-400 uppercase tracking-widest">Status:</span>
                        <span class="badge status-badge px-3 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest border shadow-sm" id="detail-status-badge">-</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-xs font-black text-gray-400 uppercase tracking-widest">Pre-Repair:</span>
                        <span class="text-sm font-bold text-gray-900" id="detail-pre-repair">-</span>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Property Info -->
                    <div class="space-y-2">
                        <label class="block text-xs font-black text-gray-400 uppercase tracking-widest px-1">Property Reference</label>
                        <div class="flex items-center gap-3 bg-white p-4 rounded-2xl border border-gray-100 shadow-sm">
                            <div class="h-10 w-10 rounded-xl bg-emerald-50 flex items-center justify-center text-emerald-600">
                                <i class="fas fa-barcode"></i>
                            </div>
                            <span class="text-sm font-bold text-gray-800" id="detail-property">-</span>
                        </div>
                    </div>

                    <!-- Location Info -->
                    <div class="space-y-2">
                        <label class="block text-xs font-black text-gray-400 uppercase tracking-widest px-1">Target Location</label>
                        <div class="flex items-center gap-3 bg-white p-4 rounded-2xl border border-gray-100 shadow-sm">
                            <div class="h-10 w-10 rounded-xl bg-amber-50 flex items-center justify-center text-amber-600">
                                <i class="fas fa-map-marker-alt"></i>
                            </div>
                            <span class="text-sm font-bold text-gray-800" id="detail-location">-</span>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Appointment Date -->
                    <div class="space-y-2">
                        <label class="block text-xs font-black text-gray-400 uppercase tracking-widest px-1">Scheduled Date</label>
                        <div class="flex items-center gap-3 bg-white p-4 rounded-2xl border border-gray-100 shadow-sm">
                            <div class="h-10 w-10 rounded-xl bg-blue-50 flex items-center justify-center text-blue-600">
                                <i class="fas fa-calendar-day"></i>
                            </div>
                            <span class="text-sm font-bold text-gray-800" id="detail-date">-</span>
                        </div>
                    </div>

                    <!-- Appointment Time -->
                    <div class="space-y-2">
                        <label class="block text-xs font-black text-gray-400 uppercase tracking-widest px-1">Time Window</label>
                        <div class="flex items-center gap-3 bg-white p-4 rounded-2xl border border-gray-100 shadow-sm">
                            <div class="h-10 w-10 rounded-xl bg-purple-50 flex items-center justify-center text-purple-600">
                                <i class="fas fa-clock"></i>
                            </div>
                            <span class="text-sm font-bold text-gray-800" id="detail-time">-</span>
                        </div>
                    </div>
                </div>

                <!-- Remarks -->
                <div class="space-y-2">
                    <label class="block text-xs font-black text-gray-400 uppercase tracking-widest px-1">User Remarks & Notes</label>
                    <div class="bg-white p-5 rounded-3xl border border-gray-100 shadow-sm min-h-[100px] relative">
                        <div class="absolute top-5 left-5 text-gray-300">
                            <i class="fas fa-quote-left"></i>
                        </div>
                        <div class="pl-8 text-sm text-gray-600 leading-relaxed italic" id="detail-remarks">
                            No additional remarks provided by the requestor.
                        </div>
                    </div>
                </div>

                <!-- Audit Footer -->
                <div class="pt-4 border-t border-gray-100 flex items-center gap-2 text-gray-400">
                    <i class="fas fa-history text-[10px]"></i>
                    <span class="text-[9px] font-black uppercase tracking-widest">Requested On:</span>
                    <span class="text-[9px] font-bold text-gray-600" id="detail-created">-</span>
                </div>
            </div>

            <div class="modal-footer p-6 bg-white border-t border-gray-100 flex flex-col sm:flex-row gap-3">
                <div id="modal-action-buttons" class="hidden flex-1 flex gap-3 w-full">
                    <button type="button" onclick="handleModalAction('approved')" class="flex-1 h-12 bg-emerald-600 text-white rounded-2xl hover:bg-emerald-700 font-bold transition-all shadow-lg shadow-emerald-200 flex items-center justify-center gap-2 transform active:scale-[0.98]">
                        <i class="fas fa-check-circle"></i> Approve Request
                    </button>
                    <button type="button" onclick="handleModalAction('rejected')" class="flex-1 h-12 bg-red-500 text-white rounded-2xl hover:bg-red-600 font-bold transition-all shadow-lg shadow-red-200 flex items-center justify-center gap-2 transform active:scale-[0.98]">
                        <i class="fas fa-times-circle"></i> Reject Request
                    </button>
                </div>
                <button type="button" class="flex-1 h-12 bg-gray-100 text-gray-600 rounded-2xl hover:bg-gray-200 font-bold transition-all transform active:scale-[0.98]" data-bs-dismiss="modal">Close Details</button>
            </div>
        </div>
    </div>
</div>



    <script src="https://cdn.tailwindcss.com"></script>

    <!-- FullCalendar -->
    <script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js'></script>

    <script>
        // Determine path prefix for API calls
        var pathPrefix = '<?= $pathPrefix ?>';
        var isStaffDashboard = <?= $isStaffDashboard ? 'true' : 'false' ?>;
        var currentDate = new Date();
        var userLocation = "<?= $user_location ? htmlspecialchars($user_location) : '' ?>";

        // ✅ Function to filter Appointment Requests by status
        function filterAppointments(status) {
            const rows = document.querySelectorAll('.appointment-row');
            const buttons = document.querySelectorAll('.status-filter-btn');
            
            // 1. Filter rows
            rows.forEach(row => {
                if (row.getAttribute('data-status') === status) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });

            // 2. Update button styles
            buttons.forEach(btn => {
                btn.className = 'status-filter-btn px-4 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest transition-all text-gray-500 hover:text-emerald-600';
            });

            // 3. Highlight active button
            const activeBtn = document.getElementById('filter-' + status);
            if (activeBtn) {
                const colorClass = status === 'pending' ? 'text-amber-600 border-amber-200' : 
                                 (status === 'approved' ? 'text-emerald-600 border-emerald-200' : 'text-red-600 border-red-200');
                activeBtn.className = `status-filter-btn px-4 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest transition-all bg-white shadow-sm border ${colorClass}`;
            }
        }

        // ✅ Function to toggle the Activity Log Drawer
        function toggleActivityDrawer(open) {
            const drawer = document.getElementById('activityDrawer');
            const backdrop = document.getElementById('activityDrawerBackdrop');

            if (open) {
                drawer.classList.remove('translate-x-full');
                backdrop.classList.remove('opacity-0', 'pointer-events-none');
                document.body.style.overflow = 'hidden'; // Prevent background scrolling
            } else {
                drawer.classList.add('translate-x-full');
                backdrop.classList.add('opacity-0', 'pointer-events-none');
                document.body.style.overflow = ''; // Restore scrolling
            }
        }

        // Close drawer on Escape key
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') toggleActivityDrawer(false);
        });

        // Function to show status message with proper error handling
        function showStatusMessage(message, type) {
            const alertDiv = document.getElementById('statusUpdateAlert');
            const messageSpan = document.getElementById('statusUpdateMessage');

            // Check if elements exist
            if (!alertDiv || !messageSpan) {
                console.error('Status message elements not found');
                return;
            }

            alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
            messageSpan.textContent = message;

            // Show the alert
            alertDiv.style.display = 'block';

            // Auto-hide after 5 seconds
            setTimeout(() => {
                const bsAlert = new bootstrap.Alert(alertDiv);
                bsAlert.close();
            }, 5000);
        }

        // FullCalendar initialization with emerald theme
        function renderCalendar() {
            var calendarEl = document.getElementById('calendar');
            if (!calendarEl) return;

            var fetchUrl = pathPrefix + 'fetch_activities.php';
            if (userLocation) {
                fetchUrl += '?location=' + encodeURIComponent(userLocation);
            }

            fetch(fetchUrl)
                .then(r => r.json())
                .then(events => {
                    // Destroy existing calendar if it exists
                    if (window.calendarInstance) {
                        window.calendarInstance.destroy();
                    }

                    window.calendarInstance = new FullCalendar.Calendar(calendarEl, {
                        initialView: 'dayGridMonth',
                        headerToolbar: {
                            left: 'prev,next today',
                            center: 'title',
                            right: 'dayGridMonth,listWeek'
                        },
                        height: 'auto',
                        dayMaxEvents: 3, // Reduced to prevent overlap in smaller viewports
                        moreLinkClick: 'popover',
                        events: events,
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
                        },
                        eventTimeFormat: {
                            hour: 'numeric',
                            minute: '2-digit',
                            meridiem: 'short'
                        }
                    });

                    window.calendarInstance.render();
                })
                .catch(err => {
                    console.error('Error loading calendar:', err);
                });
        }

        function previousMonth() {
            if (window.calendarInstance) {
                window.calendarInstance.prev();
            }
        }

        function nextMonth() {
            if (window.calendarInstance) {
                window.calendarInstance.next();
            }
        }

        function goToday() {
            if (window.calendarInstance) {
                window.calendarInstance.today();
            }
        }

        // Global function to show activity details in modal
        function showActivityDetailsSide(activityId) {
            // ✅ Close any open FullCalendar popovers to prevent overlap
            document.querySelectorAll('.fc-popover').forEach(popover => {
                // Find the close button and click it, or just remove the element
                const closeBtn = popover.querySelector('.fc-popover-close');
                if (closeBtn) closeBtn.click();
                else popover.remove();
            });

            var fetchUrl = pathPrefix + 'fetch_single_activity.php?id=' + activityId;

            fetch(fetchUrl)
                .then(r => {
                    if (!r.ok) {
                        if (r.status === 401 || r.status === 403) {
                            showStatusMessage('Session expired. Please login again.', 'danger');
                            return Promise.reject(new Error('Unauthorized access'));
                        }
                        throw new Error('Network response was not ok');
                    }
                    return r.json();
                })
                .then(data => {
                    if (!data.id) {
                        showStatusMessage('Activity not found', 'warning');
                        return;
                    }

                    // Populate form
                    document.getElementById('activity_id_side').value = data.id;
                    document.getElementById('activity_type_side').value = data.activity_type || '';
                    document.getElementById('property_no_select_side').value = data.property_no || '';
                    document.getElementById('location_display_side').value = data.location || '';
                    document.getElementById('edit_location_hidden').value = data.location || '';
                    document.getElementById('activity_date_side').value = data.activity_date || '';
                    document.getElementById('activity_time_side').value = data.activity_time || '';
                    document.getElementById('activity_remarks_side').value = data.remarks || '';

                    // ✅ Set the property tag in the header
                    const propertyTag = document.getElementById('modal_property_tag');
                    if (propertyTag) {
                        propertyTag.textContent = data.property_no || 'N/A';
                    }

                    // ✅ Set the ID badge
                    const idBadge = document.getElementById('modal_id_badge');
                    if (idBadge) {
                        idBadge.textContent = '#' + data.id;
                    }

                    // ✅ Set the Audit Info
                    const createdBy = document.getElementById('modal_created_by');
                    if (createdBy) {
                        createdBy.textContent = data.user_name || 'System';
                    }

                    const registeredOn = document.getElementById('modal_registered_on');
                    if (registeredOn) {
                        if (data.created_at) {
                            const date = new Date(data.created_at);
                            registeredOn.textContent = date.toLocaleDateString('en-GB') + ' ' + date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                        } else {
                            registeredOn.textContent = 'N/A';
                        }
                    }

                    // Reset to view mode
                    toggleViewModeSide(false);

                    // Show modal
                    var modal = new bootstrap.Modal(document.getElementById('viewActivityModalSide'));
                    modal.show();
                })
                .catch(err => {
                    console.error('Error loading activity:', err);
                    if (!err.message.includes('Unauthorized')) {
                        showStatusMessage('Error loading activity details', 'danger');
                    }
                });
        }

        // Helper to toggle form edit mode
        function toggleViewModeSide(editing) {
            // ✅ Removed property_no_select_side from editable fields
            var fields = ['activity_type_side', 'activity_date_side', 'activity_time_side',
                'activity_remarks_side'
            ];

            fields.forEach(function (id) {
                var el = document.getElementById(id);
                if (el) {
                    el.disabled = !editing;
                    // For the textarea, ensure we also handle the 'disabled' class if using custom styling
                    if (editing) {
                        el.classList.remove('disabled:border-transparent', 'disabled:shadow-none');
                    } else {
                        el.classList.add('disabled:border-transparent', 'disabled:shadow-none');
                    }
                }
            });

            var saveBtn = document.getElementById('saveActivityBtnSide');
            var editBtn = document.getElementById('editToggleBtnSide');

            if (saveBtn) saveBtn.style.display = editing ? 'flex' : 'none';
            if (editBtn) {
                editBtn.innerHTML = editing ? '<i class="fas fa-times"></i> Cancel' : '<i class="fas fa-edit"></i> Edit Schedule';
                if (editing) {
                    editBtn.classList.remove('border-emerald-500', 'text-emerald-600');
                    editBtn.classList.add('border-gray-300', 'text-gray-500');
                } else {
                    editBtn.classList.add('border-emerald-500', 'text-emerald-600');
                    editBtn.classList.remove('border-gray-300', 'text-gray-500');
                }
            }
        }

        function renderActivityList(events) {
            const listEl = document.getElementById('calendar-activity-list');
            if (!listEl) return;

            listEl.innerHTML = '';

            if (!events || events.length === 0) {
                var locationText = userLocation ? ' for ' + userLocation : '';
                listEl.innerHTML = '<div class="text-muted p-3 text-center">No scheduled activities' + locationText + '.</div>';
                return;
            }

            // Sort by date + time
            events.sort((a, b) => new Date(a.start) - new Date(b.start));

            events.forEach(e => {
                const date = new Date(e.start);
                const formattedDate = date.toLocaleDateString('en-US', {
                    month: 'short',
                    day: '2-digit',
                    year: 'numeric'
                });
                const formattedTime = date.toLocaleTimeString('en-US', {
                    hour: '2-digit',
                    minute: '2-digit'
                });

                const item = document.createElement('div');
                item.className = 'list-group-item list-group-item-action';
                item.style.cursor = 'pointer';
                item.onclick = () => showActivityDetailsSide(e.id);

                item.innerHTML = `
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fw-bold text-success">${e.title || 'Untitled Activity'}</div>
                        <small class="text-muted">
                            ${formattedDate} • ${formattedTime}
                        </small>
                        ${e.location ? `<div><small class="badge bg-info bg-opacity-10 text-info">${e.location}</small></div>` : ''}
                    </div>
                    <span class="badge bg-success">View</span>
                </div>
            `;

                listEl.appendChild(item);
            });
        }

        // Function to show appointment details
        function showAppointmentDetails(button) {
            // Get data from button attributes
            const appointmentId = button.getAttribute('data-id');
            const preRepair = button.getAttribute('data-pre-repair');
            const property = button.getAttribute('data-property');
            const location = button.getAttribute('data-location');
            const date = button.getAttribute('data-date');
            const time = button.getAttribute('data-time');
            const status = button.getAttribute('data-status');
            const created = button.getAttribute('data-created');
            const remarks = button.getAttribute('data-remarks');

            // Set status badge color
            let badgeClass = 'bg-secondary';
            switch (status.toLowerCase()) {
                case 'pending':
                    badgeClass = 'bg-warning text-dark';
                    break;
                case 'approved':
                    badgeClass = 'bg-success';
                    break;
                case 'rejected':
                    badgeClass = 'bg-danger';
                    break;
                case 'cancelled':
                    badgeClass = 'bg-secondary';
                    break;
            }

            // Populate modal with data
            document.getElementById('detail-appointment-id').value = appointmentId;
            document.getElementById('detail-pre-repair').textContent = preRepair || '-';
            document.getElementById('detail-property').textContent = property || '-';
            document.getElementById('detail-location').textContent = location || '-';
            document.getElementById('detail-date').textContent = date || '-';
            document.getElementById('detail-time').textContent = time || '-';
            document.getElementById('detail-created').textContent = created || '-';

            // Show/Hide action buttons based on status and role
            const actionButtons = document.getElementById('modal-action-buttons');
            const userRole = "<?= $user_role ?>";
            if (status.toLowerCase() === 'pending' && (userRole === 'admin' || userRole === 'staff')) {
                actionButtons.classList.remove('hidden');
            } else {
                actionButtons.classList.add('hidden');
            }

            // Set status badge
            const statusBadge = document.getElementById('detail-status-badge');
            statusBadge.textContent = status ? status.charAt(0).toUpperCase() + status.slice(1) : '-';
            statusBadge.className = `badge status-badge ${badgeClass}`;

            // Set remarks
            const remarksEl = document.getElementById('detail-remarks');
            if (remarks && remarks.trim() !== '') {
                remarksEl.innerHTML = `<p class="mb-0">${remarks}</p>`;
            } else {
                remarksEl.innerHTML = '<span class="text-muted">No remarks provided</span>';
            }

            // Show the modal
            const modal = new bootstrap.Modal(document.getElementById('appointmentDetailsModal'));
            modal.show();
        }

        // Helper for modal actions
        function handleModalAction(newStatus) {
            const appointmentId = document.getElementById('detail-appointment-id').value;
            if (appointmentId) {
                updateAppointmentStatus(appointmentId, newStatus);
                // Close modal after initiating update
                const modalEl = document.getElementById('appointmentDetailsModal');
                const modal = bootstrap.Modal.getInstance(modalEl);
                if (modal) modal.hide();
            }
        }

        // Function to update appointment status
        // In the JavaScript section, update the updateAppointmentStatus function:

        // Function to update appointment status
        function updateAppointmentStatus(appointmentId, newStatus) {
            const action = newStatus === 'approved' ? 'approve' : 'reject';
            if (!confirm(`Are you sure you want to ${action} this appointment request?`)) {
                return;
            }

            console.log(`Updating appointment ${appointmentId} to ${newStatus}`);

            // Use FormData to send the data
            const formData = new FormData();
            formData.append('appointment_id', appointmentId);
            formData.append('new_status', newStatus);
            formData.append('ajax_request', 'true'); // Add this flag

            fetch('update_appointment_status.php', { // Remove pathPrefix since it's in same directory
                method: 'POST',
                body: formData,
                credentials: 'same-origin' // This is important for sending cookies
            })
                .then(response => {
                    console.log('Response status:', response.status);

                    // First check if response is JSON
                    const contentType = response.headers.get('content-type');
                    if (contentType && contentType.includes('application/json')) {
                        return response.json();
                    } else {
                        return response.text().then(text => {
                            console.error('Non-JSON response:', text.substring(0, 200));
                            throw new Error('Server returned non-JSON response');
                        });
                    }
                })
                .then(data => {
                    console.log('Response data:', data);

                    if (data.success) {
                        // Update the status badge
                        const statusBadge = document.getElementById('status-badge-' + appointmentId);
                        const row = document.getElementById('appointment-row-' + appointmentId);

                        if (statusBadge) {
                            // Update badge class and text
                            statusBadge.className = 'badge status-badge ';
                            statusBadge.textContent = newStatus.charAt(0).toUpperCase() + newStatus.slice(1);

                            if (newStatus === 'approved') {
                                statusBadge.classList.add('bg-success');
                            } else if (newStatus === 'rejected') {
                                statusBadge.classList.add('bg-danger');
                            }

                            // Update the data-status attribute on the view button
                            const viewBtn = row.querySelector(`.view-appointment-btn[data-id="${appointmentId}"]`);
                            if (viewBtn) {
                                viewBtn.setAttribute('data-status', newStatus);
                            }

                            // Remove action buttons (approve/reject)
                            const approveBtn = row.querySelector(`.approve-btn[data-id="${appointmentId}"]`);
                            const rejectBtn = row.querySelector(`.reject-btn[data-id="${appointmentId}"]`);

                            if (approveBtn) approveBtn.remove();
                            if (rejectBtn) rejectBtn.remove();
                        }

                        // Show success message
                        const alertDiv = document.getElementById('statusUpdateAlert');
                        const messageSpan = document.getElementById('statusUpdateMessage');

                        if (alertDiv && messageSpan) {
                            alertDiv.className = 'alert alert-success alert-dismissible fade show';
                            messageSpan.textContent = `Appointment request has been ${newStatus} successfully!`;
                            alertDiv.style.display = 'block';

                            // Auto-hide after 5 seconds
                            setTimeout(() => {
                                const bsAlert = new bootstrap.Alert(alertDiv);
                                bsAlert.close();
                            }, 5000);
                        }

                    } else {
                        console.error('Server error:', data.message);
                        // Show error message
                        const alertDiv = document.getElementById('statusUpdateAlert');
                        const messageSpan = document.getElementById('statusUpdateMessage');

                        if (alertDiv && messageSpan) {
                            alertDiv.className = 'alert alert-danger alert-dismissible fade show';
                            messageSpan.textContent = 'Error: ' + (data.message || 'Unknown error');
                            alertDiv.style.display = 'block';

                            setTimeout(() => {
                                const bsAlert = new bootstrap.Alert(alertDiv);
                                bsAlert.close();
                            }, 5000);
                        }
                    }
                })
                .catch(error => {
                    console.error('Fetch error:', error);

                    // Show error message
                    const alertDiv = document.getElementById('statusUpdateAlert');
                    const messageSpan = document.getElementById('statusUpdateMessage');

                    if (alertDiv && messageSpan) {
                        alertDiv.className = 'alert alert-danger alert-dismissible fade show';
                        messageSpan.textContent =
                            'An error occurred while updating the appointment status. Please try again.';
                        alertDiv.style.display = 'block';

                        setTimeout(() => {
                            const bsAlert = new bootstrap.Alert(alertDiv);
                            bsAlert.close();
                        }, 5000);
                    }
                });
        }
        // Use event delegation for appointment buttons (handles dynamically loaded content)
        document.addEventListener('click', function (event) {
            // Handle view button clicks
            if (event.target.closest('.view-appointment-btn')) {
                event.preventDefault();
                const button = event.target.closest('.view-appointment-btn');
                showAppointmentDetails(button);
            }

            // Handle approve button clicks
            if (event.target.closest('.approve-btn')) {
                event.preventDefault();
                const button = event.target.closest('.approve-btn');
                const appointmentId = button.getAttribute('data-id');
                updateAppointmentStatus(appointmentId, 'approved');
            }

            // Handle reject button clicks
            if (event.target.closest('.reject-btn')) {
                event.preventDefault();
                const button = event.target.closest('.reject-btn');
                const appointmentId = button.getAttribute('data-id');
                updateAppointmentStatus(appointmentId, 'rejected');
            }
        });

        // Initialize on DOM ready
        document.addEventListener('DOMContentLoaded', function () {
            // ✅ Set default filter to pending
            filterAppointments('pending');

            renderCalendar();

            // ✅ Initialize search for Add Schedule Modal
            if (typeof $ !== 'undefined') {
                $('#add_property_no_select').select2({
                    dropdownParent: $('#addActivityModal'),
                    width: '100%',
                    placeholder: "Search Property No..."
                });

                // ✅ Initialize search for Add Activity Log Modal
                $('#add_log_property_no_select').select2({
                    dropdownParent: $('#addActivityLogModal'),
                    width: '100%',
                    placeholder: "Search Property No..."
                });
            }

            // Wire up edit toggle button
            var editBtn = document.getElementById('editToggleBtnSide');
            if (editBtn) {
                editBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    var isEditing = document.getElementById('saveActivityBtnSide').style.display !== 'none';
                    if (isEditing) {
                        // Cancel - reload original values
                        var id = document.getElementById('activity_id_side').value;
                        if (id) {
                            showActivityDetailsSide(id);
                        }
                    } else {
                        // Enable edit mode
                        toggleViewModeSide(true);
                    }
                });
            }

            // Wire up save form
            var viewForm = document.getElementById('viewActivityFormSide');
            if (viewForm) {
                viewForm.addEventListener('submit', function (e) {
                    e.preventDefault();
                    var formData = new FormData(viewForm);
                    fetch(pathPrefix + 'update_activity.php', {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin'
                    })
                        .then(r => {
                            if (r.status === 401 || r.status === 403) {
                                showStatusMessage('Session expired. Please login again.', 'danger');
                                return Promise.reject(new Error('Unauthorized access'));
                            }
                            return r.json();
                        })
                        .then(resp => {
                            if (resp.success) {
                                showStatusMessage('Activity updated successfully', 'success');
                                var modal = bootstrap.Modal.getInstance(document.getElementById(
                                    'viewActivityModalSide'));
                                if (modal) modal.hide();
                                renderCalendar(); // Refresh calendar
                            } else {
                                showStatusMessage('Error: ' + (resp.message || 'Unknown error'), 'danger');
                            }
                        })
                        .catch(err => {
                            console.error('Save failed:', err);
                            if (!err.message.includes('Unauthorized')) {
                                showStatusMessage('Save failed: ' + err.message, 'danger');
                            }
                        });
                });
            }

            // Set min date for activity date inputs to today
            var today = new Date().toISOString().split('T')[0];
            document.querySelectorAll('input[type="date"]').forEach(input => {
                if (!input.id.includes('activity_date_side')) {
                    input.min = today;
                }
            });

            // ✅ Auto-select user's location in add activity modal
            if (userLocation) {
                const locationSelect = document.querySelector('#addActivityModal select[name="location"]');
                if (locationSelect) {
                    // The location is already set via PHP, but we ensure it's selected 
                    Array.from(locationSelect.options).forEach(option => {
                        if (option.value === userLocation) {
                            option.selected = true;
                        }
                    });
                }
            }
        });

        // Close alerts after 5 seconds
        setTimeout(function () {
            var alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                try {
                    var bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                } catch (e) {
                    // Ignore errors if alert doesn't exist or can't be closed
                }
            });
        }, 5000);
    </script>