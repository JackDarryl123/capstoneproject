<?php
ob_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/session_helper.php';
require_once __DIR__ . '/../includes/mail_config.php';
start_user_session();

// Redirect checks
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'user') {
    header("Location: ../index.php?login");
    exit();
}

// Fetch user details
$userId = $_SESSION['user_id'];
$userStmt = $mysqli->prepare("SELECT username, email, status, role, signature FROM users WHERE id = ?");
if (!$userStmt) {
    $userStmt = $mysqli->prepare("SELECT username, email, status, role FROM users WHERE id = ?");
}
if (!$userStmt) {
    die("Database Error: " . $mysqli->error);
}
$userStmt->bind_param("i", $userId);
$userStmt->execute();
$currentUser = $userStmt->get_result()->fetch_assoc();
$userStmt->close();

// ================= NOTIFICATION SYSTEM =================
$notificationCount = 0;
$notifications = [];
$userId = $_SESSION['user_id'] ?? 0;

// Create table for read notifications if not exists
$createTable = $mysqli->query("
    CREATE TABLE IF NOT EXISTS user_notifications_read (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        document_id INT NOT NULL,
        read_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_read (user_id, document_id),
        INDEX idx_user_id (user_id)
    )
");

// Fetch notifications using user_id (the actual requester)
try {
    // Count query - exclude already read notifications
    $countQuery = "SELECT COUNT(*) as approved_count 
                   FROM documents 
                   WHERE user_id = ?
                   AND status IN ('Approved', 'APPROVED', 'Archived', 'Complete', 'Done')
                   AND id NOT IN (
                       SELECT document_id FROM user_notifications_read WHERE user_id = ?
                   )";

    $countStmt = $mysqli->prepare($countQuery);
    if ($countStmt) {
        $countStmt->bind_param("ii", $userId, $userId);
        $countStmt->execute();
        $countResult = $countStmt->get_result()->fetch_assoc();
        $notificationCount = $countResult['approved_count'] ?? 0;
        $countStmt->close();
    }

    // Notification query - exclude already read notifications
    $notificationQuery = "SELECT 
        id, 
        user_id,
        pre_repair_no,
        property_no,
        equipment,
        date_requested,
        status,
        date_completed,
        location
    FROM documents 
    WHERE user_id = ?
    AND status IN ('Approved', 'APPROVED', 'Archived', 'Complete', 'Done')
    AND id NOT IN (
        SELECT document_id FROM user_notifications_read WHERE user_id = ?
    )
    ORDER BY date_requested DESC
    LIMIT 10";

    $notificationStmt = $mysqli->prepare($notificationQuery);
    if ($notificationStmt) {
        $notificationStmt->bind_param("ii", $userId, $userId);
        $notificationStmt->execute();
        $notificationResult = $notificationStmt->get_result();

        while ($row = $notificationResult->fetch_assoc()) {
            $notifications[] = $row;
        }
        $notificationStmt->close();
    }

} catch (Exception $e) {
    error_log("Error fetching notification data: " . $e->getMessage());
}

// Redirect to mark all notifications as read via API
if (isset($_GET['mark_notifications_read']) && $_GET['mark_notifications_read'] == 1) {
    // Call the API to mark all as read
    $ch = curl_init();
    $internalBaseUrl = rtrim(getenv('APP_INTERNAL_BASE_URL') ?: 'http://localhost', '/');
    curl_setopt($ch, CURLOPT_URL, $internalBaseUrl . '/users/api/notifications.php');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, 'action=mark_all_read');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);

    $redirectUrl = "user_dashboard.php?view=" . ($_GET['view'] ?? 'dashboard');
    header("Location: " . $redirectUrl);
    exit();
}

// Determine initial view
$allowedViews = ['dashboard', 'scan', 'request', 'appointment'];
$view = $_GET['view'] ?? 'dashboard';
if (!in_array($view, $allowedViews, true)) {
    $view = 'dashboard';
}

// Fetch dashboard data for initial load if needed
$vehicle_counts = ['Mamburao' => 0, 'San Jose' => 0, 'Sablayan' => 0, 'Lubang' => 0];
$pendingPct = 0;
$ongoingPct = 0;
$donePct = 0;
$locations = [];
$repairData = ['Under repair' => [], 'Operational' => [], 'Unserviceable' => []];

if ($view === 'dashboard') {
    // Vehicle Count
    $stmt = $mysqli->prepare("SELECT location, COUNT(*) AS total FROM equipment WHERE location IN ('Mamburao','San Jose','Sablayan','Lubang') GROUP BY location");
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $vehicle_counts[$row['location']] = (int) $row['total'];
        }
        $stmt->close();
    }

    // Document Counts
    function getDocCount($mysqli, $status)
    {
        $stmt = $mysqli->prepare("SELECT COUNT(*) as c FROM documents WHERE status=?");
        $stmt->bind_param("s", $status);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        return (int) ($res['c'] ?? 0);
    }

    $pending = getDocCount($mysqli, 'PENDING');
    $ongoing = getDocCount($mysqli, 'APPROVED');
    $done = getDocCount($mysqli, 'DONE');
    $totalDocs = $pending + $ongoing + $done;

    if ($totalDocs > 0) {
        $pendingPct = round(($pending / $totalDocs) * 100);
        $ongoingPct = round(($ongoing / $totalDocs) * 100);
        $donePct = round(($done / $totalDocs) * 100);
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
            $repairData[$status][] = (int) $stmt->get_result()->fetch_assoc()['cnt'];
            $stmt->close();
        }
    }
}
?>



<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Dashboard | PEPO</title>

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- jQuery (Required for some plugins) -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>

    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js" type="text/javascript"></script>
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- FullCalendar -->
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.js"></script>

    <style>
        :root {
            --sidebar-width: 280px;
            /* Slightly wider for better card fit */
            --header-height: 70px;
            --sidebar-bg: #0f172a;
            /* Slate 900 - Dark Navy */
            --sidebar-text: #94a3b8;
            /* Slate 400 */
            --active-green: #10b981;
            /* Emerald 500 */
        }

        body {
            background-color: #f3f4f6;
            font-family: 'Inter', sans-serif;
            overflow-x: hidden;
        }

        /* --- SIDEBAR STYLING --- */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            width: var(--sidebar-width);
            background: var(--sidebar-bg);
            /* Dark Theme */
            z-index: 1050;
            display: flex;
            flex-direction: column;
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 4px 0 24px rgba(0, 0, 0, 0.1);
            /* Subtle shadow */
        }

        /* Reference Theme - Active Card Styling */
        .nav-link-custom {
            display: flex;
            align-items: center;
            padding: 14px 20px;
            margin: 6px 16px;
            border-radius: 12px;
            color: var(--sidebar-text);
            font-weight: 500;
            text-decoration: none;
            transition: all 0.3s ease;
            font-size: 0.95rem;
            cursor: pointer;
            border: 1px solid transparent;
        }

        .nav-link-custom:hover {
            background-color: rgba(255, 255, 255, 0.05);
            color: #f8fafc;
            transform: translateX(4px);
        }

        /* The "Green Button" look for active items */
        .nav-link-custom.active {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            font-weight: 600;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4);
            /* Green Glow */
            border: none;
        }

        .nav-link-custom i {
            width: 24px;
            text-align: center;
            margin-right: 12px;
            font-size: 1.1rem;
            transition: transform 0.2s;
        }

        .nav-link-custom.active i {
            transform: scale(1.1);
        }

        /* --- NOTIFICATION DRAWER (Mobile) --- */
        .notification-drawer {
            position: fixed;
            top: 0;
            right: 0;
            height: 100vh;
            width: 350px;
            max-width: 90%;
            background: white;
            z-index: 2000;
            transform: translateX(100%);
            visibility: hidden;
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1), visibility 0.3s;
            box-shadow: -10px 0 30px rgba(0, 0, 0, 0.1);
            display: flex;
            flex-direction: column;
        }

        .notification-drawer.active {
            transform: translateX(0);
            visibility: visible;
        }

        .notification-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(0, 0, 0, 0.4);
            backdrop-filter: blur(2px);
            z-index: 1999;
            display: none;
        }

        .notification-overlay.active {
            display: block;
        }

        /* --- NAVBAR STYLING --- */
        .top-navbar {
            position: fixed;
            top: 0;
            left: var(--sidebar-width);
            width: calc(100% - var(--sidebar-width));
            height: var(--header-height);
            background: rgba(3, 2, 10, 0.85);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-bottom: 1px solid #e2e8f0;
            z-index: 1040;
            padding: 0 2rem;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            transition: all 0.3s ease;
        }

        /* --- MAIN CONTENT & MOBILE --- */
        .main-content {
            margin-left: var(--sidebar-width);
            padding: 2rem;
            padding-top: calc(var(--header-height) + 2rem);
            min-height: 100vh;
            transition: all 0.3s ease;
        }

        /* Mobile Responsive Logic */
        @media (max-width: 991.98px) {
            .sidebar {
                transform: translateX(-100%);
                width: 280px;
                /* Ensure fixed width on mobile */
            }

            /* When active, slide it in */
            .sidebar.active {
                transform: translateX(0) !important;
                box-shadow: 10px 0 30px rgba(0, 0, 0, 0.5);
                /* Stronger shadow on mobile */
            }

            .top-navbar {
                left: 0 !important;
                width: 100% !important;
                justify-content: flex-start;
                /* Align burger to left */
                padding: 0 1rem;
                /* Adjust padding for mobile */
            }

            .main-content {
                margin-left: 0 !important;
                padding-top: 85px;
                padding-left: 1rem;
                padding-right: 1rem;
            }

            /* Ensure the close button inside sidebar is visible */
            #sidebarCloseBtn {
                display: flex !important;
                align-items: center;
                justify-content: center;
            }
        }

        /* Hide the burger button when the sidebar is open to prevent overlapping */
        #mobileToggle.hidden-burger {
            opacity: 0;
            pointer-events: none;
        }

        #loadingSpinner.active {
            display: flex !important;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, 0.8);
            border-radius: 50%;
            padding: 20px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }
    </style>
</head>

<body>
    <div class="sidebar-overlay fixed inset-0 bg-black/50 z-[1045] hidden" id="sidebarOverlay"></div>

    <!-- Notification Overlay (for mobile drawer) -->
    <div class="notification-overlay" id="notificationOverlay"></div>

    <!-- Notification Drawer (Mobile Slide-out) -->
    <div class="notification-drawer p-0" id="notificationDrawer">
        <div class="p-6 border-b border-gray-100 flex items-center justify-between bg-white shrink-0">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                    <i class="far fa-bell text-lg"></i>
                </div>
                <h5 class="fw-bold text-slate-800 m-0">Notifications</h5>
            </div>
            <button class="w-8 h-8 rounded-full bg-slate-50 flex items-center justify-center text-slate-400"
                id="closeNotificationDrawer">
                <i class="fas fa-times text-xs"></i>
            </button>
        </div>

        <div class="flex-grow overflow-y-auto custom-scrollbar" id="notificationDrawerList">
            <!-- Dynamic Content -->
            <div class="p-10 text-center text-slate-400">Loading...</div>
        </div>

        <div class="p-6 bg-slate-50 border-t border-gray-100 mt-auto flex flex-col gap-2">
            <?php if ($notificationCount > 0): ?>
                <button onclick="markAllNotificationsRead()"
                    class="w-full py-3 bg-white border border-slate-200 text-slate-600 rounded-xl text-xs font-bold hover:bg-slate-100 transition-all">
                    Mark all as read
                </button>
            <?php endif; ?>
            <button onclick="loadView('request'); toggleNotificationDrawer(false);"
                class="w-full py-3 bg-emerald-600 text-white rounded-xl text-xs font-bold hover:bg-emerald-700 transition-all shadow-lg shadow-emerald-200">
                View All Requests
            </button>
        </div>
    </div>

    <aside class="sidebar" id="sidebar">
        <div class="h-[80px] flex items-center px-6 shrink-0 bg-[#0f172a]">
            <img src="../rs/Pepo_Logo.png" alt="PEPO" class="w-10 h-10 object-contain mr-3 drop-shadow-md">
            <div class="flex flex-col">
                <span class="font-bold text-xl text-white tracking-tight">PEPO</span>
                <span class="text-[10px] font-bold text-emerald-400 uppercase tracking-widest">User Console</span>
            </div>
            <button class="d-lg-none ms-auto text-slate-400 hover:text-white bg-transparent border-0 transition-colors"
                id="sidebarCloseBtn">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>

        <div class="px-4 mb-2">
            <div class="bg-slate-800/50 rounded-xl p-3 border border-slate-700/50 flex items-center justify-between">
                <div>
                    <p class="text-[10px] text-slate-400 uppercase font-semibold m-0">Current Session</p>
                    <p class="text-sm font-bold text-white m-0">Active</p>
                </div>
                <div class="flex items-center gap-1.5">
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    <span class="text-xs text-emerald-500 font-medium">Online</span>
                </div>
            </div>
        </div>

        <nav class="flex-1 overflow-y-auto py-4 custom-scrollbar">
            <div class="px-6 mb-3 text-[11px] font-bold text-slate-500 uppercase tracking-widest">Main Menu</div>

            <a href="#" data-view="dashboard"
                class="nav-link-custom ajax-nav <?= $view === 'dashboard' ? 'active' : '' ?>">
                <i class="fas fa-home"></i> <span>Dashboard</span>
            </a>

            <a href="#" data-view="scan" class="nav-link-custom ajax-nav <?= $view === 'scan' ? 'active' : '' ?>">
                <i class="fas fa-qrcode"></i> <span>Scan QR</span>
            </a>

            <a href="#" data-view="request" class="nav-link-custom ajax-nav <?= $view === 'request' ? 'active' : '' ?>">
                <i class="fas fa-paper-plane"></i> <span>Request</span>
            </a>

            <a href="#" data-view="appointment"
                class="nav-link-custom ajax-nav <?= $view === 'appointment' ? 'active' : '' ?>">
                <i class="fas fa-calendar-check"></i> <span>Appointment</span>
            </a>

            <div class="my-4 mx-6 border-t border-slate-700/50"></div>
            <div class="px-6 mb-3 text-[11px] font-bold text-slate-500 uppercase tracking-widest">Account</div>
        </nav>

        <div class="p-4 border-t border-slate-800 shrink-0 bg-[#0b1120]">
            <a href="../logout.php"
                class="flex items-center justify-center w-full py-3 px-4 text-sm font-semibold text-white bg-slate-800 hover:bg-red-600 hover:shadow-lg hover:shadow-red-900/40 rounded-xl transition-all duration-300 no-underline group">
                <i class="fas fa-sign-out-alt mr-2 group-hover:rotate-180 transition-transform duration-300"></i> Log
                out
            </a>
            <small class="text-slate-600 block text-center mt-3 text-[10px]">&copy; <?php echo date('Y'); ?> PEPO
                System</small>
        </div>
    </aside>

    <nav class="top-navbar">
        <button id="mobileToggle"
            class="btn d-lg-none me-auto text-white hover:text-emerald-400 border-0 d-flex align-items-center justify-content-center p-2 transition-colors">
            <i class="fas fa-bars text-2xl"></i>
        </button>

        <div class="flex items-center gap-5 ms-auto">
            <div class="dropdown">
                <!-- Notification Trigger - Optimized for Desktop & Mobile -->
                <a href="#"
                    class="relative text-white hover:text-emerald-400 transition-colors p-2 rounded-full hover:bg-white/10"
                    id="notificationDropdownTrigger" onclick="handleNotificationClick(event)">
                    <i class="far fa-bell text-2xl"></i>
                    <?php if ($notificationCount > 0): ?>
                        <span
                            class="notification-badge absolute -top-1 -right-1 min-w-[20px] h-5 flex items-center justify-center rounded-full bg-red-500 text-white text-[10px] font-bold shadow-sm"><?= $notificationCount ?></span>
                    <?php endif; ?>
                </a>

                <!-- Desktop Dropdown -->
                <ul id="desktopNotificationDropdown"
                    class="dropdown-menu dropdown-menu-end shadow-[0_10px_40px_-10px_rgba(0,0,0,0.1)] border-0 rounded-2xl mt-4 p-0 !min-w-[380px] overflow-hidden">
                    <li class="px-5 py-4 border-b border-gray-100 flex justify-between items-center bg-white">
                        <h6 class="font-bold text-slate-800 m-0 text-base">Notifications</h6>
                        <?php if ($notificationCount > 0): ?>
                            <span
                                class="bg-emerald-50 text-emerald-600 rounded-lg px-2.5 py-1 text-xs font-bold border border-emerald-100">
                                <?= $notificationCount ?> New
                            </span>
                        <?php endif; ?>
                    </li>

                    <li>
                        <div class="max-h-[420px] overflow-y-auto custom-scrollbar bg-white" id="notificationList">
                            <?php if ($notificationCount > 0 && !empty($notifications)): ?>
                                <?php foreach ($notifications as $doc): ?>
                                    <?php
                                    $status = $doc['status'] ?? '';
                                    $statusLower = strtolower($status);

                                    // Determine notification type based on status
                                    $isArchived = $statusLower === 'archived';
                                    $isComplete = $statusLower === 'complete';
                                    $isDone = $statusLower === 'done';
                                    $isApproved = $statusLower === 'approved' || $statusLower === 'approved';

                                    if ($isArchived) {
                                        $bgClass = 'bg-slate-100';
                                        $iconBg = 'bg-slate-500';
                                        $textClass = 'text-slate-600';
                                        $iconClass = 'fa-archive';
                                        $titleText = 'Request Archived';
                                    } elseif ($isComplete) {
                                        $bgClass = 'bg-blue-50';
                                        $iconBg = 'bg-blue-500';
                                        $textClass = 'text-blue-700';
                                        $iconClass = 'fa-check-double';
                                        $titleText = 'Repair Completed';
                                    } elseif ($isDone) {
                                        $bgClass = 'bg-amber-50';
                                        $iconBg = 'bg-amber-500';
                                        $textClass = 'text-amber-700';
                                        $iconClass = 'fa-clipboard-check';
                                        $titleText = 'Maintenance Done';
                                    } else {
                                        $bgClass = 'bg-emerald-50';
                                        $iconBg = 'bg-emerald-500';
                                        $textClass = 'text-emerald-700';
                                        $iconClass = 'fa-check-circle';
                                        $titleText = 'Request Approved';
                                    }

                                    // Determine action text
                                    if ($isComplete) {
                                        $actionText = 'has been completed and is ready';
                                    } elseif ($isDone) {
                                        $actionText = 'has been marked as done';
                                    } elseif ($isArchived) {
                                        $actionText = 'has been archived';
                                    } else {
                                        $actionText = 'has been approved';
                                    }
                                    ?>
                                    <a href="side_request.php?id=<?= $doc['id'] ?>"
                                        class="dropdown-item d-flex align-items-start p-4 border-bottom hover:bg-slate-50 transition-all notification-item"
                                        data-id="<?= $doc['id'] ?>">
                                        <div class="<?= $iconBg ?> rounded-xl p-2.5 me-4 flex-shrink-0 shadow-sm mt-1">
                                            <i class="fas <?= $iconClass ?> text-white text-sm"></i>
                                        </div>
                                        <div class="flex-grow-1 min-w-0 me-3">
                                            <div class="flex items-center justify-between mb-1">
                                                <span class="fw-bold <?= $textClass ?> text-sm"><?= $titleText ?></span>
                                                <span class="text-[10px] text-slate-400 font-medium">Just now</span>
                                            </div>
                                            <p class="text-slate-500 m-0 text-xs leading-relaxed line-clamp-2">
                                                Your request <b><?= htmlspecialchars($doc['pre_repair_no'] ?? '') ?></b> for
                                                <b><?= htmlspecialchars($doc['property_no'] ?? $doc['equipment'] ?? 'equipment') ?></b>
                                                <?= $actionText ?>.
                                            </p>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="p-12 text-center">
                                    <div
                                        class="w-16 h-16 bg-slate-50 rounded-full flex items-center justify-center mx-auto mb-4">
                                        <i class="far fa-bell-slash text-slate-300 text-2xl"></i>
                                    </div>
                                    <h6 class="text-slate-800 font-bold mb-1">All caught up!</h6>
                                    <p class="text-slate-500 text-xs m-0">No new notifications to show</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </li>

                    <li class="p-4 bg-slate-50 border-t border-gray-100 flex gap-3">
                        <?php if ($notificationCount > 0): ?>
                            <button onclick="markAllNotificationsRead()"
                                class="flex-1 py-2.5 px-4 bg-white border border-slate-200 text-slate-600 rounded-xl text-xs font-bold hover:bg-emerald-50 hover:text-emerald-600 hover:border-emerald-200 transition-all shadow-sm">
                                Mark all as read
                            </button>
                        <?php endif; ?>
                        <button onclick="loadView('request')"
                            class="flex-1 py-2.5 px-4 bg-emerald-600 text-white rounded-xl text-xs font-bold hover:bg-emerald-700 transition-all shadow-md shadow-emerald-200">
                            View All Requests
                        </button>
                    </li>
                </ul>
            </div>

            <div class="dropdown">
                <a href="#"
                    class="flex items-center gap-3 text-decoration-none bg-white border border-slate-200 rounded-full pl-1 pr-1 py-1 md:pl-4 hover:shadow-md transition-all group"
                    id="profileDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="text-end d-none d-md-block">
                        <div class="text-sm font-bold text-slate-700 leading-tight group-hover:text-emerald-700">
                            <?= htmlspecialchars($currentUser['username'] ?? 'User') ?>
                        </div>
                        <div class="text-[10px] text-slate-400 uppercase font-bold tracking-wider">
                            <?= ucfirst($currentUser['role'] ?? 'User') ?>
                        </div>
                    </div>
                    <div
                        class="h-8 w-8 rounded-full bg-emerald-100 border border-white flex items-center justify-center text-emerald-600 shadow-sm">
                        <i class="fas fa-user text-xs"></i>
                    </div>
                </a>

                <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0 rounded-2xl mt-3 p-2 w-[240px]">
                    <li class="d-md-none px-4 py-3 border-b border-gray-50 mb-2 bg-slate-50 rounded-xl">
                        <div class="fw-bold text-slate-800"><?= htmlspecialchars($currentUser['username'] ?? 'User') ?>
                        </div>
                        <div class="text-xs text-slate-500"><?= htmlspecialchars($currentUser['email'] ?? '') ?></div>
                    </li>
                    <li>
                        <a class="dropdown-item rounded-xl py-2 px-4 text-sm font-medium text-slate-600 hover:bg-emerald-50 hover:text-emerald-700"
                            href="#" data-bs-toggle="modal" data-bs-target="#profileModal">
                            Settings
                        </a>
                    </li>
                    <li>
                        <hr class="dropdown-divider my-1">
                    </li>
                    <li>
                        <a class="dropdown-item rounded-xl py-2 px-4 text-sm font-medium text-red-600 hover:bg-red-50"
                            href="../logout.php">
                            Logout
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <main class="main-content">
        <!-- Loading Spinner (hidden by default) -->
        <div id="loadingSpinner" class="position-fixed top-50 start-50 translate-middle"
            style="z-index: 9999; display: none;">
            <div class="spinner-border text-success" role="status" style="width: 3rem; height: 3rem;">
                <span class="visually-hidden">Loading...</span>
            </div>
        </div>
        <div class="container-fluid p-0">
            <h4 class="fw-bold text-gray-800 mb-2" id="page-title">
                <?php
                if ($view === 'dashboard')
                    echo 'Overview';
                elseif ($view === 'scan')
                    echo 'Scan QR Code';
                elseif ($view === 'request')
                    echo 'General Request';
                elseif ($view === 'appointment')
                    echo 'Appointment';
                else
                    echo 'Dashboard';
                ?>
            </h4>
            <div class="h-[3px] bg-green-600 w-[60px] rounded-full mb-6"></div>
        </div>

        <div id="content-container">
            <?php
            // if ($view === 'dashboard') {
            //     include 'side_dashboard.php';
            //     echo '<script>';
            //     echo 'initializeDocumentChart([' . $pendingPct . ', ' . $ongoingPct . ', ' . $donePct . ']);';
            //     echo 'initializeRepairChart(' . json_encode([
            //         'locations' => $locations,
            //         'underRepair' => $repairData['Under repair'] ?? [],
            //         'operational' => $repairData['Operational'] ?? [],
            //         'unserviceable' => $repairData['Unserviceable'] ?? []
            //     ]) . ');';
            //     echo '</script>';
            if ($view === 'dashboard') {
                include 'side_dashboard.php';

            } elseif ($view === 'scan') {
                include 'side_scan.php';
            } elseif ($view === 'request') {
                include 'side_request.php';
            } elseif ($view === 'appointment') {
                include 'appointment.php';
            }
            ?>
        </div>
    </main>

    <!-- Profile modal -->
    <div class="modal fade" id="profileModal" tabindex="-1" style="z-index: 2000;">
        <div class="modal-dialog" style="z-index: 2001;">
            <div class="modal-content rounded-4 border-0">
                <div class="modal-header bg-gradient-to-r from-emerald-600 to-teal-500 text-white">
                    <h5 class="modal-title">Edit Profile</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="../process.php" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" name="redirect_to" id="redirectTo"
                            value="users/user_dashboard.php?view=<?= htmlspecialchars($view) ?>">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Username</label>
                            <input type="text" name="username"
                                value="<?= htmlspecialchars($currentUser['username'] ?? '') ?>" class="form-control"
                                required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Digital Signature</label>
                            <?php if (!empty($currentUser['signature'])): ?>
                                <div class="mb-2 text-center p-2 border rounded bg-light">
                                    <img src="../<?= htmlspecialchars($currentUser['signature']) ?>" alt="Current Signature"
                                        style="max-height: 60px;">
                                </div>
                            <?php endif; ?>
                            <input type="file" name="signature" class="form-control" accept="image/*">
                            <div class="form-text text-muted small">Upload a PNG/JPG image of your signature.</div>
                        </div>
                        <h6 class="text-primary fw-bold">Security</h6>
                        <div class="mb-2">
                            <input type="password" name="current_password" class="form-control"
                                placeholder="Current Password">
                        </div>
                        <div class="mb-2">
                            <input type="password" name="password" class="form-control"
                                placeholder="New Password (8 chars)" minlength="8" maxlength="8">
                        </div>
                        <div class="mb-2">
                            <input type="password" name="confirm_password" class="form-control"
                                placeholder="Confirm Password" minlength="8" maxlength="8">
                        </div>
                    </div>
                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels"></script>
    <!-- <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.js"></script> -->


    <!-- In your main page head -->
    ?


    <script>
        // Global variables
        let currentView = '<?= $view ?>';
        let currentCalendarDate = new Date();
        let documentChartInstance = null;
        let repairChartInstance = null;

        // === SIDEBAR LOGIC ===
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        const showBtn = document.getElementById('mobileToggle');
        const closeBtn = document.getElementById('sidebarCloseBtn');

        function openSidebar() {
            sidebar.classList.add('active');
            overlay.classList.add('active');
            showBtn.classList.add('hidden-burger');
        }

        function closeSidebar() {
            sidebar.classList.remove('active');
            overlay.classList.remove('active');
            showBtn.classList.remove('hidden-burger');
        }

        showBtn.addEventListener('click', openSidebar);
        overlay.addEventListener('click', closeSidebar);
        if (closeBtn) {
            closeBtn.addEventListener('click', closeSidebar);
        }

        // === AJAX CONTENT LOADING ===
        function loadView(view, pushState = true) {
            if (currentView === view) return;

            const loadingSpinner = document.getElementById('loadingSpinner');
            const contentContainer = document.getElementById('content-container');

            // Show loading spinner
            loadingSpinner.classList.add('active');

            // Update active state in sidebar
            document.querySelectorAll('.nav-link-custom').forEach(link => {
                link.classList.remove('active');
                if (link.getAttribute('data-view') === view) {
                    link.classList.add('active');
                }
            });

            // Update page title
            const titles = {
                'dashboard': 'Overview',
                'scan': 'Scan QR Code',
                'request': 'General Request',
                'appointment': 'Appointment'
            };
            document.getElementById('page-title').textContent = titles[view] || 'Dashboard';

            // AJAX request - use absolute path to avoid issues
            fetch(`load_content.php?view=${view}&t=${new Date().getTime()}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok: ' + response.status);
                    }
                    return response.text();
                })
                .then(html => {
                    // Update content
                    contentContainer.innerHTML = html;
                    currentView = view;

                    // Re-initialize any scripts that were loaded
                    executeScriptsFromHTML(html);

                    // Initialize components based on view
                    initializeViewComponents(view);

                    // Update URL and history
                    if (pushState) {
                        const url = new URL(window.location);
                        url.searchParams.set('view', view);
                        window.history.pushState({
                            view: view
                        }, '', url);
                    }

                    // Update profile modal redirect
                    const redirectInput = document.getElementById('redirectTo');
                    if (redirectInput) {
                        redirectInput.value = `users/user_dashboard.php?view=${view}`;
                    }
                })

                .catch(error => {
                    console.error('Error loading view:', error);
                    contentContainer.innerHTML = `
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <strong>⚠️ Failed to load content</strong>
                    <p class="mb-0">${error.message}</p>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    <button onclick="loadView('${view}')" class="btn btn-sm btn-outline-danger mt-2">Retry</button>
                </div>
            `;
                })
                .finally(() => {
                    // Hide loading spinner
                    loadingSpinner.classList.remove('active');

                    // Close sidebar on mobile
                    if (window.innerWidth < 992) {
                        closeSidebar();
                    }
                });
        }


        function executeScriptsFromHTML(html) {
            const tempDiv = document.createElement('div');
            tempDiv.innerHTML = html;

            // Find all script tags
            const scripts = tempDiv.querySelectorAll('script');
            scripts.forEach(oldScript => {
                const newScript = document.createElement('script');
                // Copy all attributes (src, type, etc.)
                Array.from(oldScript.attributes).forEach(attr => {
                    newScript.setAttribute(attr.name, attr.value);
                });

                // Wrap inline scripts in an IIFE to prevent "already declared" errors
                if (!newScript.src && oldScript.textContent.trim()) {
                    newScript.textContent = `(function() { ${oldScript.textContent} })();`;
                } else {
                    newScript.textContent = oldScript.textContent;
                }

                // Append to body – this executes the script
                document.body.appendChild(newScript);
                // Remove after execution (optional cleanup)
                setTimeout(() => newScript.remove(), 10);
            });
        }

        function initializeViewComponents(view) {
            console.log('Initializing view components for:', view);

            // If the loaded view has a global initializer function, call it
            if (window[`init_${view}`] && typeof window[`init_${view}`] === 'function') {
                window[`init_${view}`]();
            } else {
                // Fallback for older views (optional)
                switch (view) {
                    case 'dashboard':
                        if (typeof initDashboardCharts === 'function') initDashboardCharts();
                        if (typeof initDashboardCalendar === 'function') initDashboardCalendar();
                        break;
                    case 'scan':
                        if (typeof initScanner === 'function') initScanner();
                        break;
                    case 'request':
                        if (typeof initRequestForm === 'function') initRequestForm();
                        break;
                    case 'appointment':
                        if (typeof updateAppointmentFields === 'function') updateAppointmentFields();
                        break;
                }
            }
        }

        function initializeDashboard() {
            // Initialize calendar if on dashboard
            if (document.getElementById('userCalendar')) {
                renderUserCalendar();
            }
        }

        function initializeDocumentChart(data) {
            const canvas = document.getElementById('documentChart');
            if (!canvas) return;

            // Destroy existing chart instance if it exists
            if (documentChartInstance) {
                documentChartInstance.destroy();
            }

            // Create new chart with real data
            documentChartInstance = new Chart(canvas, {
                type: 'doughnut',
                data: {
                    labels: ['Pending', 'Approved', 'Done'],
                    datasets: [{
                        data: data,
                        backgroundColor: ['#0d6efd', '#ffc107', '#198754'],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right'
                        }
                    }
                }
            });
        }

        function initializeRepairChart(data) {
            const canvas = document.getElementById('repairChart');
            if (!canvas) return;

            // Destroy existing chart instance if it exists
            if (repairChartInstance) {
                repairChartInstance.destroy();
            }

            // Create new chart with real data
            repairChartInstance = new Chart(canvas, {
                type: 'bar',
                data: {
                    labels: data.locations,
                    datasets: [{
                        label: 'Under Repair',
                        data: data.underRepair,
                        backgroundColor: '#0d6efd'
                    },
                    {
                        label: 'Operational',
                        data: data.operational,
                        backgroundColor: '#198754'
                    },
                    {
                        label: 'Unserviceable',
                        data: data.unserviceable,
                        backgroundColor: '#dc3545'
                    }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: {
                            stacked: false
                        },
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });
        }

        // === CALENDAR FUNCTIONALITY ===
        function renderUserCalendar() {
            var cal = document.getElementById('userCalendar');
            if (!cal) return;

            fetch('../fetch_activities.php')
                .then(r => r.json())
                .then(events => {
                    let listHtml = '<ul class="list-group list-group-flush">';
                    if (events.length === 0) listHtml +=
                        '<li class="list-group-item text-center text-muted">No activities found</li>';
                    events.forEach(e => {
                        let d = new Date(e.start);
                        listHtml += `<li class="list-group-item d-flex justify-content-between align-items-center">
                            <div><strong>${e.title}</strong><br><small class="text-muted">${d.toLocaleDateString()}</small></div>
                            <button class="btn btn-sm btn-outline-primary" onclick="showUserActivityDetails(${e.id})">View</button>
                        </li>`;
                    });
                    listHtml += '</ul>';
                    document.getElementById('userActivityList').innerHTML = listHtml;

                    var isMobile = window.innerWidth < 768;
                    var calendar = new FullCalendar.Calendar(cal, {
                        initialView: isMobile ? 'listMonth' : 'dayGridMonth',
                        height: isMobile ? 'auto' : 400,
                        headerToolbar: {
                            left: 'prev,next',
                            center: 'title',
                            right: 'today dayGridMonth,listMonth'
                        },
                        buttonText: {
                            dayGridMonth: 'Grid',
                            listMonth: 'List'
                        },
                        eventTimeFormat: {
                            hour: 'numeric',
                            minute: '2-digit',
                            meridiem: 'short'
                        },
                        events: events,
                        eventClick: function (info) {
                            showUserActivityDetails(info.event.id);
                        }
                    });
                    calendar.render();
                })
                .catch(e => console.error(e));
        }

        function showUserActivityDetails(id) {
            fetch('../fetch_single_activity.php?id=' + id).then(r => r.json()).then(data => {
                if (!data.id) return alert('Error loading details');
                document.getElementById('activity_type').value = data.activity_type || '';
                document.getElementById('property_no').value = data.property_no || '';
                document.getElementById('activity_location').value = data.location || '';
                document.getElementById('activity_date').value = data.activity_date || '';
                document.getElementById('activity_time').value = data.time_started || '';
                new bootstrap.Modal(document.getElementById('viewActivityModal')).show();
            });
        }

        // === APPOINTMENT FUNCTIONALITY ===
        function updateAppointmentFields() {
            const preRepairSelect = document.getElementById('preRepairSelect');
            if (!preRepairSelect) return;

            preRepairSelect.addEventListener('change', function () {
                const selectedOption = this.options[this.selectedIndex];
                const propertyNo = selectedOption.getAttribute('data-property') || '';
                const location = selectedOption.getAttribute('data-location') || '';

                const propertyInput = document.getElementById('propertyNoInput');
                const locationInput = document.getElementById('locationInput');

                if (propertyInput) propertyInput.value = propertyNo;
                if (locationInput) locationInput.value = location;
            });
        }

        // === NOTIFICATION LOGIC (Global Scope) ===
        const notificationDrawer = document.getElementById('notificationDrawer');
        const notificationOverlay = document.getElementById('notificationOverlay');
        const closeDrawerBtn = document.getElementById('closeNotificationDrawer');

        function toggleNotificationDrawer(show) {
            const drawer = document.getElementById('notificationDrawer');
            const overlay = document.getElementById('notificationOverlay');
            if (show) {
                drawer.classList.add('active');
                overlay.classList.add('active');
                updateNotificationList(); // Refresh list when opening
            } else {
                drawer.classList.remove('active');
                overlay.classList.remove('active');
            }
        }

        function handleNotificationClick(e) {
            e.preventDefault();
            e.stopPropagation(); // Prevent immediate closing
            if (window.innerWidth < 768) {
                toggleNotificationDrawer(true);
            } else {
                // Desktop still uses dropdown
                const trigger = document.getElementById('notificationDropdownTrigger');
                const dropdown = new bootstrap.Dropdown(trigger);
                dropdown.toggle();
            }
        }

        // Update notification list from API (Unified for Drawer and Dropdown)
        window.updateNotificationList = function () {
            fetch('api/notifications.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const notificationList = document.getElementById('notificationList');
                        const drawerList = document.getElementById('notificationDrawerList');
                        const badge = document.querySelector('.notification-badge');

                        if (badge) {
                            if (data.count > 0) {
                                badge.textContent = data.count;
                                badge.style.display = 'flex';
                            } else {
                                badge.style.display = 'none';
                            }
                        }

                        let html = '';
                        if (data.notifications && data.notifications.length > 0) {
                            data.notifications.forEach(doc => {
                                const status = (doc.status || '').toLowerCase();
                                const isArchived = status === 'archived';
                                const isComplete = status === 'complete';
                                const isDone = status === 'done';
                                const isApproved = status === 'approved';

                                let iconBg, textClass, iconClass, titleText, actionText;

                                if (isArchived) {
                                    iconBg = 'bg-slate-500';
                                    textClass = 'text-slate-600';
                                    iconClass = 'fa-archive';
                                    titleText = 'Request Archived';
                                    actionText = 'has been archived';
                                } else if (isComplete) {
                                    iconBg = 'bg-blue-500';
                                    textClass = 'text-blue-700';
                                    iconClass = 'fa-check-double';
                                    titleText = 'Repair Completed';
                                    actionText = 'has been completed and is ready';
                                } else if (isDone) {
                                    iconBg = 'bg-amber-500';
                                    textClass = 'text-amber-700';
                                    iconClass = 'fa-clipboard-check';
                                    titleText = 'Maintenance Done';
                                    actionText = 'has been marked as done';
                                } else if (isApproved) {
                                    iconBg = 'bg-emerald-500';
                                    textClass = 'text-emerald-700';
                                    iconClass = 'fa-check-circle';
                                    titleText = 'Request Approved';
                                    actionText = 'has been approved';
                                } else {
                                    iconBg = 'bg-emerald-500';
                                    textClass = 'text-emerald-700';
                                    iconClass = 'fa-check-circle';
                                    titleText = 'Request Approved';
                                    actionText = 'has been ' + status;
                                }

                                html += `
                                    <a href="side_request.php?id=${doc.id}"
                                        class="dropdown-item d-flex align-items-start p-4 border-bottom hover:bg-slate-50 transition-all notification-item"
                                        data-id="${doc.id}">
                                        <div class="${iconBg} rounded-xl p-2.5 me-4 flex-shrink-0 shadow-sm mt-1">
                                            <i class="fas ${iconClass} text-white text-sm"></i>
                                        </div>
                                        <div class="flex-grow-1 min-w-0 me-3">
                                            <div class="flex items-center justify-between mb-1">
                                                <span class="fw-bold ${textClass} text-sm">${titleText}</span>
                                                <span class="text-[10px] text-slate-400 font-medium">Just now</span>
                                            </div>
                                            <p class="text-slate-500 m-0 text-xs leading-relaxed line-clamp-2">
                                                Your request <b>${doc.pre_repair_no || ''}</b> for <b>${doc.property_no || doc.equipment || 'equipment'}</b> ${actionText}.
                                            </p>
                                        </div>
                                    </a>`;
                            });
                        } else {
                            html = `
                                <div class="p-12 text-center">
                                    <div class="w-16 h-16 bg-slate-50 rounded-full flex items-center justify-center mx-auto mb-4">
                                        <i class="far fa-bell-slash text-slate-300 text-2xl"></i>
                                    </div>
                                    <h6 class="text-slate-800 font-bold mb-1">All caught up!</h6>
                                    <p class="text-slate-500 text-xs m-0">No new notifications to show</p>
                                </div>`;
                        }

                        if (notificationList) notificationList.innerHTML = html;
                        if (drawerList) drawerList.innerHTML = html;

                        const markReadBtns = document.querySelectorAll('[onclick="markAllNotificationsRead()"]');
                        markReadBtns.forEach(btn => {
                            btn.style.display = data.count > 0 ? 'block' : 'none';
                        });
                    }
                })
                .catch(err => console.error('Error fetching notifications:', err));
        };

        // Mark all notifications as read
        window.markAllNotificationsRead = function () {
            fetch('api/notifications.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=mark_all_read'
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        updateNotificationList();
                        toggleNotificationDrawer(false);
                    }
                })
                .catch(err => console.error('Error marking notifications as read:', err));
        };

        document.addEventListener('DOMContentLoaded', function () {
            console.log('DOM loaded, current view:', currentView);

            // Open profile modal via URL parameter
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('modal') === 'profile') {
                const profileModal = document.getElementById('profileModal');
                if (profileModal) {
                    new bootstrap.Modal(profileModal).show();
                }
            }

            // Clean up backdrop when profile modal closes
            const profileModalEl = document.getElementById('profileModal');
            if (profileModalEl) {
                profileModalEl.addEventListener('shown.bs.modal', function () {
                    const backdrops = document.querySelectorAll('.modal-backdrop');
                    backdrops.forEach(bp => {
                        bp.style.zIndex = '1999';
                        bp.style.position = 'fixed';
                    });
                });
                profileModalEl.addEventListener('hidden.bs.modal', function () {
                    const backdrops = document.querySelectorAll('.modal-backdrop');
                    backdrops.forEach(backdrop => backdrop.remove());
                    document.body.classList.remove('modal-open');
                    document.body.style.overflow = '';
                });
            }

            // Sidebar navigation click handlers
            document.querySelectorAll('.nav-link-custom[data-view]').forEach(link => {
                link.addEventListener('click', function (e) {
                    e.preventDefault();
                    const view = this.getAttribute('data-view');
                    loadView(view);
                });
            });

            // Handle browser back/forward buttons
            window.addEventListener('popstate', function (event) {
                if (event.state && event.state.view) {
                    loadView(event.state.view, false);
                } else {
                    const urlParams = new URLSearchParams(window.location.search);
                    const view = urlParams.get('view') || 'dashboard';
                    loadView(view, false);
                }
            });

            // Initialize current view components
            setTimeout(() => {
                initializeViewComponents(currentView);
            }, 300);

            // Update profile modal redirect
            const redirectInput = document.getElementById('redirectTo');
            if (redirectInput) {
                redirectInput.value = `users/user_dashboard.php?view=${currentView}`;
            }

            // Close notification drawer/dropdown when clicking main content
            document.addEventListener('click', function (e) {
                const drawer = document.getElementById('notificationDrawer');
                const trigger = document.getElementById('notificationDropdownTrigger');
                const isClickInsideDrawer = drawer && drawer.contains(e.target);
                const isClickOnTrigger = trigger && trigger.contains(e.target);

                if (!isClickInsideDrawer && !isClickOnTrigger) {
                    if (drawer && drawer.classList.contains('active')) {
                        toggleNotificationDrawer(false);
                    }
                }
            });

            if (closeDrawerBtn) {
                closeDrawerBtn.addEventListener('click', () => toggleNotificationDrawer(false));
            }

            if (notificationOverlay) {
                notificationOverlay.addEventListener('click', () => toggleNotificationDrawer(false));
            }

            // Handle notification item click
            document.addEventListener('click', function (e) {
                const notificationItem = e.target.closest('.notification-item');
                if (notificationItem) {
                    const docId = notificationItem.dataset.id;
                    if (docId) {
                        fetch('api/notifications.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: 'action=mark_read&document_id=' + docId
                        })
                            .then(response => response.json())
                            .then(data => {
                                if (data.success) {
                                    updateNotificationList();
                                }
                            })
                            .catch(err => console.error('Error marking notification as read:', err));
                    }
                }
            });

            // Auto-refresh notifications
            setInterval(function () {
                updateNotificationList();
            }, 30000);
        });

        // Toggle mobile sidebar via burger
        if (showBtn) {
            showBtn.addEventListener('click', () => {
                sidebar.classList.add('active');
            });
        }
    </script>
</body>

<?php ob_end_flush(); ?>

</html>
