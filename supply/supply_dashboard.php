<?php
// supply_dashboard.php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/session_helper.php';

// 1. Session Logic
if (function_exists('start_user_session')) {
    start_user_session();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 2. Security Check
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'supply') {
    header("Location: ../index.php?login");
    exit();
}

// 4. Fetch User Details
$userId = $_SESSION['user_id'];
$currentUser = [];
$userStmt = $mysqli->prepare('SELECT username, email, status, role, signature, location FROM users WHERE id = ?');
$userStmt->bind_param('i', $userId);
$userStmt->execute();
$currentUser = $userStmt->get_result()->fetch_assoc();
$userStmt->close();

// Set location in session if not already set
if (empty($_SESSION['location']) && !empty($currentUser['location'])) {
    $_SESSION['location'] = $currentUser['location'];
}

// Get pending and received supply request count for notification badge
$pendingCount = 0;
if (!empty($currentUser['location'])) {
    $notifStmt = $mysqli->prepare("SELECT COUNT(*) as cnt FROM supply_requests WHERE status IN ('pending', 'received') AND LOWER(supply_location) = LOWER(?)");
    $notifStmt->bind_param("s", $currentUser['location']);
    $notifStmt->execute();
    $notifResult = $notifStmt->get_result()->fetch_assoc();
    $pendingCount = $notifResult['cnt'] ?? 0;
    $notifStmt->close();
}

// 5. Determine View
$allowedViews = ['dashboard', 'equipment', 'documents', 'report', 'inventory'];
$view = $_GET['view'] ?? 'dashboard';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supply Dashboard | PEPO</title>

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@500;600;700&display=swap"
        rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>

    <style>
        :root {
            --sidebar-width: 380px;
            --header-height: 80px;
            --sidebar-bg: #0f172a;
            /* Slate 900 */
            --sidebar-text: #94a3b8;
            /* Slate 400 */
        }

        /* Slide-up animation for content loading */
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .content-slide-up {
            animation: slideUp 0.4s ease-out forwards;
        }

        body {
            background-color: #f8fafc;
            font-family: 'Inter', sans-serif;
            color: #334155;
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
            z-index: 1050;
            display: flex;
            flex-direction: column;
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 4px 0 24px rgba(0, 0, 0, 0.1);
        }

        .sidebar-header {
            height: 80px;
            display: flex;
            align-items: center;
            padding: 0 24px;
            background: #0f172a;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }

        /* Navigation Links */
        .nav-link-custom {
            display: flex;
            align-items: center;
            padding: 12px 18px;
            margin: 6px 16px;
            border-radius: 14px;
            color: var(--sidebar-text);
            font-weight: 500;
            text-decoration: none;
            transition: all 0.3s ease;
            font-size: 0.95rem;
            cursor: pointer;
            border: 1px solid transparent;
        }

        .nav-link-custom:not(.active):hover {
            background-color: rgba(255, 255, 255, 0.05);
            color: #f8fafc;
        }

        /* Active State */
        .nav-link-custom.active {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            font-weight: 600;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
            border: none;
        }

        .nav-link-custom i {
            width: 24px;
            text-align: center;
            margin-right: 14px;
            font-size: 1.1rem;
            transition: transform 0.2s ease;
        }

        .nav-link-custom.active i {
            transform: scale(1.15);
            color: #ffffff;
        }

        /* --- TOP NAVBAR --- */
        .top-navbar {
            position: fixed;
            top: 0;
            left: var(--sidebar-width);
            width: calc(100% - var(--sidebar-width));
            height: var(--header-height);
            background: rgba(2, 5, 19, 0.9);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-bottom: 1px solid #10b981;
            z-index: 1040;
            padding: 0 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: all 0.3s ease;
        }

        /* --- LAYOUT --- */
        .main-content {
            margin-left: var(--sidebar-width);
            padding-top: calc(var(--header-height) + 1.5rem);
            min-height: 100vh;
            transition: all 0.3s ease;
        }

        /* Custom Scrollbar */
        .custom-scrollbar::-webkit-scrollbar {
            width: 4px;
        }

        .custom-scrollbar::-webkit-scrollbar-track {
            background: transparent;
        }

        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: #334155;
            border-radius: 10px;
        }

        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            background: #10b981;
        }

        .dropdown-toggle::after {
            display: none !important;
        }

        /* Mobile Responsive */
        @media (max-width: 991.98px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.active {
                transform: translateX(0);
                box-shadow: 10px 0 30px rgba(0, 0, 0, 0.5);
            }

            .top-navbar {
                left: 0;
                width: 100%;
                padding: 0 1rem;
            }

            .main-content {
                margin-left: 0;
                padding-top: 90px;
            }
        }
    </style>
</head>

<body class="antialiased">

    <div class="fixed inset-0 bg-slate-900/20 z-[1040] hidden backdrop-blur-[1px] transition-opacity"
        id="sidebarOverlay"></div>

    <aside class="sidebar flex flex-col" id="sidebar">
        <div class="sidebar-header">
            <img src="../rs/Pepo_Logo.png" alt="PEPO" class="w-10 h-10 object-contain mr-3 drop-shadow-sm">
            <div class="flex flex-col mt-1">
                <span class="font-bold text-xl text-white tracking-tight leading-none mb-1">PEPO</span>
                <span class="text-[9px] font-bold text-emerald-400 uppercase tracking-widest leading-none">Supply
                    Panel</span>
            </div>
            <button class="lg:hidden ml-auto text-slate-400 hover:text-white bg-transparent border-0 transition-colors"
                id="sidebarCloseBtn">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>

        <div class="px-5 mt-5 mb-2">
            <div class="bg-slate-800/40 rounded-2xl p-3 border border-slate-700/50 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div
                        class="w-8 h-8 rounded-xl bg-emerald-500/10 flex items-center justify-center border border-emerald-500/20">
                        <i class="fas fa-box-open text-emerald-400 text-xs"></i>
                    </div>
                    <div>
                        <p class="text-[10px] text-slate-400 uppercase font-semibold m-0 leading-tight">System Status
                        </p>
                        <p class="text-sm font-bold text-white m-0 leading-tight">Supply Active</p>
                    </div>
                </div>
                <div
                    class="flex items-center gap-1.5 bg-slate-900/50 px-2 py-1 rounded-full border border-slate-700/50">
                    <span
                        class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse shadow-[0_0_8px_rgba(16,185,129,0.5)]"></span>
                    <span class="text-[10px] text-emerald-400 font-bold uppercase tracking-wider">Online</span>
                </div>
            </div>
        </div>

        <nav class="flex-1 overflow-y-auto py-3 custom-scrollbar">
            <div class="px-6 mb-3 mt-2 text-[10px] font-bold text-slate-500 uppercase tracking-widest">Main Menu</div>

            <a href="#" data-view="dashboard"
                class="nav-link-custom ajax-nav <?= $view === 'dashboard' ? 'active' : '' ?>">
                <i class="fas fa-layer-group"></i> <span>Dashboard</span>
            </a>
               <a href="#" data-view="inventory"
                class="nav-link-custom ajax-nav <?= $view === 'inventory' ? 'active' : '' ?>">
                <i class="fas fa-warehouse"></i> <span>Inventory</span>
            </a>

            <a href="#" data-view="equipment"
                class="nav-link-custom ajax-nav <?= $view === 'equipment' ? 'active' : '' ?>">
                <i class="fas fa-boxes-stacked"></i> <span>Equipment</span>
            </a>

            <a href="#" data-view="documents"
                class="nav-link-custom ajax-nav <?= $view === 'documents' ? 'active' : '' ?>">
                <i class="fas fa-file-invoice"></i> <span>Requests</span>
            </a>

            <a href="#" data-view="report" class="nav-link-custom ajax-nav <?= $view === 'report' ? 'active' : '' ?>">
                <i class="fas fa-chart-pie"></i> <span>Reports</span>
            </a>

            <div class="my-5 mx-6 border-t border-slate-700/50"></div>
            <div class="px-6 mb-3 text-[10px] font-bold text-slate-500 uppercase tracking-widest">Account</div>
        </nav>

        <div class="p-4 border-t border-slate-800 shrink-0 bg-[#0b1120]">
            <a href="../logout.php"
                class="flex items-center justify-center w-full py-3 px-4 text-sm font-semibold text-white bg-slate-800 hover:bg-orange-600 rounded-2xl transition-all duration-300 no-underline group hover:shadow-lg hover:shadow-orange-600/20">
                <i class="fas fa-power-off mr-2 group-hover:scale-110 transition-transform duration-300"></i>
                Sign Out
            </a>
        </div>
    </aside>


    <nav class="top-navbar">
        <div class="flex items-center gap-4">
            <button id="mobileToggle"
                class="lg:hidden flex items-center justify-center w-10 h-10 rounded-xl bg-emerald-500 text-white hover:bg-emerald-600 transition-colors shadow-sm border-0">
                <i class="fas fa-bars text-lg"></i>
            </button>

            <h4 class="font-bold text-slate-800 text-lg m-0 tracking-tight hidden md:block" id="page-title-display">
                <?php
                $titles = [
                    'dashboard' => 'Overview',
                    'equipment' => 'Equipment Inventory',
                    'inventory' => 'Inventory Management',
                    'documents' => 'Request Documents',
                    'report' => 'Supply Analytics'
                ];
                echo $titles[$view] ?? 'Dashboard';
                ?>
            </h4>
        </div>

        <div class="flex items-center gap-5 ms-auto">

            <!-- Notifications -->
            <div class="dropdown relative" id="notificationsContainer">
                <a href="#"
                    class="relative p-2 text-slate-400 hover:text-red-500 transition-colors border-0 bg-transparent notification-bell"
                    data-bs-toggle="dropdown">
                    <i class="far fa-bell text-xl"></i>
                    <?php if ($pendingCount > 0): ?>
                        <span
                            class="absolute -top-1 -right-1 w-5 h-5 bg-red-500 text-white text-xs rounded-full flex items-center justify-center font-bold">
                            <?= $pendingCount ?>
                        </span>
                    <?php endif; ?>
                </a>

                <ul class="dropdown-menu dropdown-menu-end border-0 shadow-xl rounded-xl mt-3 p-0 !min-w-[350px] sm:!min-w-[400px] overflow-hidden"
                    style="width: max-content;">
                    <li class="px-4 py-3 border-b border-gray-100 flex justify-between items-center bg-white">
                        <h6 class="font-bold text-gray-800 m-0">Notifications</h6>
                        <?php if ($pendingCount > 0): ?>
                            <span class="badge bg-red-100 text-red-700 rounded-md px-2 py-1 text-xs font-medium">
                                <span id="supplyNotificationCount"><?= $pendingCount ?></span> Total
                            </span>
                        <?php endif; ?>
                    </li>
                    <li>
                        <div id="supplyNotificationsList"
                            class="max-h-[400px] overflow-y-auto custom-scrollbar bg-white">
                            <div class="text-center p-4">
                                <div class="spinner-border spinner-border-sm text-success" role="status"></div>
                                <p class="text-muted mt-2 mb-0">Loading notifications...</p>
                            </div>
                        </div>
                    </li>
                    <li class="p-3 bg-white border-t border-gray-100">
                        <div class="flex gap-2">
                            <button onclick="markAllNotificationsAsRead()"
                                class="flex-1 py-2 px-3 bg-emerald-500 border border-emerald-500 text-white rounded-lg text-sm font-semibold text-center hover:bg-emerald-600 transition-colors">
                                <i class="fas fa-check-double me-1"></i> Mark as Read
                            </button>
                            <a href="?view=documents"
                                class="flex-1 py-2 px-3 bg-gray-500 border border-gray-500 text-white rounded-lg text-sm font-semibold text-center hover:bg-gray-600 transition-colors no-underline block">
                                View Requests
                            </a>
                        </div>
                    </li>
                </ul>
            </div>

            <div class="dropdown">
                <a href="#"
                    class="dropdown-toggle flex items-center gap-3 text-decoration-none bg-white border border-slate-100 rounded-full pl-2 pr-2 py-1.5 md:pl-4 hover:shadow-sm transition-all group"
                    data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="hidden md:flex flex-col text-right">
                        <span
                            class="text-sm font-bold text-slate-700 group-hover:text-emerald-600 transition-colors leading-tight">
                            <?= htmlspecialchars($currentUser['username'] ?? 'Supply') ?>
                        </span>
                        <span class="text-[10px] text-slate-400 font-bold uppercase tracking-wider leading-tight">
                            <?= ucfirst($currentUser['role'] ?? 'Officer') ?>
                        </span>
                    </div>

                    <div
                        class="h-10 w-10 rounded-full bg-[#dcfce7] border border-emerald-200 flex items-center justify-center text-emerald-600 shadow-sm">
                        <span
                            class="font-bold text-base"><?= substr(strtoupper($currentUser['username'] ?? 'S'), 0, 1) ?></span>
                    </div>
                </a>

                <ul class="dropdown-menu dropdown-menu-end shadow-lg border border-slate-100 rounded-2xl mt-3 p-2 w-56">
                    <li class="px-4 py-2 border-b border-slate-50 mb-1">
                        <span class="text-xs text-slate-400 font-semibold uppercase">Account</span>
                    </li>
                    <li>
                        <a class="dropdown-item rounded-xl py-2 px-4 text-sm font-medium text-slate-600 hover:bg-emerald-50 hover:text-emerald-700 flex items-center gap-2 transition-colors"
                            href="#" data-bs-toggle="modal" data-bs-target="#profileModal">
                            <i class="fas fa-sliders-h w-5"></i> Settings
                        </a>
                    </li>
                    <li>
                        <a class="dropdown-item rounded-xl py-2 px-4 text-sm font-medium text-orange-600 hover:bg-orange-50 flex items-center gap-2 transition-colors"
                            href="../logout.php">
                            <i class="fas fa-sign-out-alt w-5"></i> Logout
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <main class="main-content">
        <div class="container-fluid px-6 lg:px-8 pb-8">
            <div id="content-container">
            </div>
        </div>
    </main>

    <div class="modal fade" id="viewActivityModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content rounded-xl border-0 shadow-2xl">
                <div class="modal-header bg-white border-b border-slate-100">
                    <h5 class="modal-title font-bold text-slate-800">Activity Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="viewActivityForm">
                    <div class="modal-body p-6 bg-slate-50/50">
                        <input type="hidden" name="id" id="activity_id">
                            
                        <div class="mb-3">
                            <label class="form-label text-xs font-bold text-slate-500 uppercase">Type</label>
                            <select name="activity_type" id="activity_type"
                                class="form-select border-slate-200 shadow-sm" disabled required>
                                <option value="Inspection">Inspection</option>
                                <option value="Maintenance/Repair">Maintenance/Repair</option>
                                <option value="Appointment">Appointment</option>
                            </select>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-6">
                                <label class="form-label text-xs font-bold text-slate-500 uppercase">Date</label>
                                <input type="date" name="activity_date" id="activity_date"
                                    class="form-control border-slate-200 shadow-sm" disabled required>
                            </div>
                            <div class="col-6">
                                <label class="form-label text-xs font-bold text-slate-500 uppercase">Time</label>
                                <input type="time" name="activity_time" id="activity_time"
                                    class="form-control border-slate-200 shadow-sm" disabled required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-xs font-bold text-slate-500 uppercase">Location</label>
                            <input type="text" name="location" id="activity_location"
                                class="form-control border-slate-200 shadow-sm" disabled required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-xs font-bold text-slate-500 uppercase">Property</label>
                            <select name="property_no" id="property_no_select"
                                class="form-select border-slate-200 shadow-sm" disabled required>
                                <option value="" disabled>Select Property No.</option>
                                <?php
                                global $mysqli;
                                $prop_q = $mysqli->query("SELECT property_no FROM equipment ORDER BY property_no ASC");
                                if ($prop_q && $prop_q->num_rows > 0) {
                                    while ($p = $prop_q->fetch_assoc()) {
                                        echo '<option value="' . htmlspecialchars($p['property_no']) . '">' . htmlspecialchars($p['property_no']) . '</option>';
                                    }
                                }
                                ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-xs font-bold text-slate-500 uppercase">Remarks</label>
                            <textarea name="remarks" id="activity_remarks"
                                class="form-control border-slate-200 shadow-sm" rows="3" disabled></textarea>
                        </div>
                    </div>
                    <div class="modal-footer border-t border-slate-100 bg-white">
                        <button type="button" id="editToggleBtn"
                            class="btn btn-outline-success px-4 rounded-lg">Edit</button>
                        <button type="submit" id="saveActivityBtn" class="btn btn-success px-4 rounded-lg"
                            style="display:none;">Save Changes</button>
                        <button type="button" class="btn btn-light text-slate-500"
                            data-bs-dismiss="modal">Close</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Include Profile Modal -->
    <?php include_once __DIR__ . '/../profile_modal.php'; ?>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels"></script>

    <script>
        console.log('Supply Dashboard JS loaded');
        
        const view = "<?php echo htmlspecialchars($view); ?>";
        const titles = {
            'dashboard': 'Overview',
            'equipment': 'Equipment Inventory',
            'inventory': 'Inventory Management',
            'documents': 'Request Documents',
            'report': 'Supply Analytics'
        };
        const titleDisplay = document.getElementById('page-title-display');

        // AJAX Load Content with Slide-Up Animation
        window.loadContent = function(view) {
            const contentContainer = document.getElementById('content-container');
            if (!contentContainer) return;

            // Extract base view name and preserve other params (location, tab, etc.)
            const viewParts = view.split('&');
            const baseView = viewParts[0];
            
            // Build URL with all parameters
            let fetchUrl = `load_content.php?view=${baseView}`;
            
            // Add additional parameters (location, tab, etc.) if present
            if (viewParts.length > 1) {
                for (let i = 1; i < viewParts.length; i++) {
                    const param = viewParts[i];
                    const [key, value] = param.split('=');
                    if (key && value !== undefined) {
                        fetchUrl += `&${key}=${encodeURIComponent(value)}`;
                    }
                }
            }

            fetch(fetchUrl)
                .then(response => {
                    if (!response.ok) throw new Error('Network response was not ok');
                    return response.text();
                })
                .then(html => {
                    // Insert content with slide-up animation
                    contentContainer.innerHTML = html;
                    contentContainer.classList.add('content-slide-up');

                    // Remove animation class after it completes (for next load)
                    setTimeout(() => {
                        contentContainer.classList.remove('content-slide-up');
                    }, 400);

                    // Re-execute scripts - only inline scripts, skip external libs (already loaded)
                    const scripts = contentContainer.querySelectorAll('script');
                    scripts.forEach(script => {
                        if (script.src) {
                            // Skip external scripts - jQuery, Select2, Bootstrap already loaded in main page
                            const src = script.src.toLowerCase();
                            if (src.includes('jquery') || src.includes('select2') || src.includes('bootstrap')) {
                                return;
                            }
                        }
                        try {
                            const newScript = document.createElement('script');
                            if (script.src) {
                                newScript.src = script.src;
                            } else {
                                newScript.textContent = script.textContent;
                            }
                            document.body.appendChild(newScript);
                        } catch (e) {
                            console.error('Error executing injected script:', e);
                        }
                    });

                    // Update UI state (use base view for nav highlighting)
                    setActiveNav(baseView);

                    if (baseView === 'dashboard') {
                        setTimeout(() => {
                            if (typeof initializeDashboard === 'function') {
                                try {
                                    initializeDashboard();
                                } catch (e) {
                                    console.error('Error in initializeDashboard:', e);
                                }
                            } else {
                                console.warn('initializeDashboard function not found');
                            }
                        }, 100);
                    }

                    if (baseView === 'inventory') {
                        setTimeout(() => {
                            if (typeof initializeInventory === 'function') {
                                try {
                                    initializeInventory();
                                } catch (e) {
                                    console.error('Error in initializeInventory:', e);
                                }
                            } else {
                                console.warn('initializeInventory function not found');
                            }
                        }, 100);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    contentContainer.innerHTML = `<div class="alert alert-danger">Error loading content.</div>`;
                });
        }

        // Sidebar Elements
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        const showBtn = document.getElementById('mobileToggle');
        const closeBtn = document.getElementById('sidebarCloseBtn');

        // Sidebar Functions
        function openSidebar() {
            sidebar.classList.add('active');
            overlay.classList.remove('hidden');
            setTimeout(() => overlay.classList.remove('opacity-0'), 10); // Fade in
        }

        function closeSidebar() {
            sidebar.classList.remove('active');
            overlay.classList.add('opacity-0'); // Fade out
            setTimeout(() => overlay.classList.add('hidden'), 300);
        }

        if (showBtn) showBtn.addEventListener('click', openSidebar);
        if (overlay) overlay.addEventListener('click', closeSidebar);
        if (closeBtn) closeBtn.addEventListener('click', closeSidebar);

        // Highlight Active Nav
        function setActiveNav(view) {
            document.querySelectorAll('.ajax-nav').forEach(link => {
                link.classList.remove('active');
                if (link.getAttribute('data-view') === view) {
                    link.classList.add('active');
                }
            });
            if (titleDisplay) titleDisplay.textContent = titles[view] || 'Dashboard';
        }

        // --- NAVIGATION HANDLER (Preserved) ---
        document.querySelectorAll('.ajax-nav').forEach(link => {
            link.addEventListener('click', function (e) {
                e.preventDefault();
                const view = this.getAttribute('data-view');
                const url = new URL(window.location);
                url.searchParams.set('view', view);
                window.history.pushState({}, '', url);

                loadContent(view);
                if (window.innerWidth < 992) closeSidebar();
            });
        });

        // Browser Back Support
        window.addEventListener('popstate', function () {
            const urlParams = new URLSearchParams(window.location.search);
            const view = urlParams.get('view') || 'dashboard';
            loadContent(view);
        });

        // Initial Load
        document.addEventListener('DOMContentLoaded', () => {
            loadContent(view);
            
            // Attach notification click handler
            document.querySelector('.notification-bell')?.addEventListener('click', function(e) {
                e.preventDefault();
                if (typeof window.loadSupplyNotifications === 'function') {
                    window.loadSupplyNotifications();
                } else {
                    console.error('loadSupplyNotifications not defined');
                }
            });
        });

        // Open profile modal via URL parameter
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('modal') === 'profile') {
            const profileModal = document.getElementById('profileModal');
            if (profileModal) {
                new bootstrap.Modal(profileModal).show();
            }
        }

        // --- ACTIVITY MODAL LOGIC (Preserved) ---
        document.getElementById('editToggleBtn')?.addEventListener('click', function (e) {
            e.preventDefault();
            const saveBtn = document.getElementById('saveActivityBtn');
            const isEditing = saveBtn.style.display !== 'none';
            toggleViewMode(!isEditing);
        });

        function toggleViewMode(editing) {
            const inputs = ['activity_type', 'property_no_select', 'activity_location', 'activity_date', 'activity_time', 'activity_remarks'];
            inputs.forEach(id => {
                const el = document.getElementById(id);
                if (el) {
                    el.disabled = !editing;
                    if (editing) el.classList.add('bg-white');
                    else el.classList.remove('bg-white');
                }
            });

            const saveBtn = document.getElementById('saveActivityBtn');
            const editBtn = document.getElementById('editToggleBtn');
            saveBtn.style.display = editing ? 'inline-block' : 'none';
            editBtn.textContent = editing ? 'Cancel' : 'Edit';

            if (editing) editBtn.classList.replace('btn-outline-success', 'btn-outline-secondary');
            else editBtn.classList.replace('btn-outline-secondary', 'btn-outline-success');
        }

        document.getElementById('viewActivityForm')?.addEventListener('submit', function (e) {
            e.preventDefault();
            fetch('../update_activity.php', {
                method: 'POST',
                body: new FormData(this)
            })
                .then(r => r.json())
                .then(resp => {
                    if (resp.success) {
                        alert('Activity updated successfully');
                        const modal = bootstrap.Modal.getInstance(document.getElementById('viewActivityModal'));
                        modal.hide();
                        location.reload();
                    } else {
                        alert('Error: ' + (resp.message || 'Unknown error'));
                    }
                })
                .catch(err => alert('Save failed: ' + err));
        });

        // Supply Notifications System
        window.loadSupplyNotifications = function() {
            const notificationsList = document.getElementById('supplyNotificationsList');
            if (!notificationsList) return;

            // Always reload when clicking the bell
            notificationsList.innerHTML = `
                <div class="text-center p-4">
                    <div class="spinner-border spinner-border-sm text-success" role="status"></div>
                    <p class="text-muted mt-2 mb-0">Loading notifications...</p>
                </div>
            `;

            fetch('../fetch_supply_notifications.php')
                .then(response => response.json())
                .then(data => {
                    console.log('Notification response:', data);
                    if (data.success) {
                        renderSupplyNotifications(data.notifications);

                        // Update badge count
                        const badge = document.querySelector('#notificationsContainer .absolute.-top-1');
                        if (badge && data.count > 0) {
                            badge.textContent = data.count > 9 ? '9+' : data.count;
                        }
                    } else {
                        notificationsList.innerHTML = `
                            <div class="p-4 text-center">
                                <p class="text-danger text-sm">Error: ${data.message || 'Unknown error'}</p>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Error loading notifications:', error);
                    notificationsList.innerHTML = `
                        <div class="p-4 text-center">
                            <p class="text-danger text-sm">Failed to load notifications</p>
                        </div>
                    `;
                });
        }

        function renderSupplyNotifications(notifications) {
            const notificationsList = document.getElementById('supplyNotificationsList');
            if (!notificationsList) return;

            if (notifications.length === 0) {
                notificationsList.innerHTML = `
                    <div class="p-8 text-center">
                        <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-gray-100 mb-3">
                            <i class="far fa-check-circle text-gray-400 text-xl"></i>
                        </div>
                        <p class="text-gray-500 text-sm m-0">No pending or received requests</p>
                    </div>
                `;
                return;
            }

            let html = '';
            notifications.forEach(req => {
                const date = new Date(req.created_at).toLocaleDateString();
                const viewLink = `?view=documents&req_id=${req.id}`;
                
                // Different styling for received vs pending
                const isReceived = req.status === 'received';
                let notificationStyle = '';
                let iconElement = '';
                
                if (isReceived) {
                    // Received notification - emerald/green style with checkmark
                    notificationStyle = 'background: linear-gradient(to right, rgba(16, 185, 129, 0.1), transparent); border-left: 3px solid #10b981;';
                    iconElement = `<div class="mt-1"><div class="w-8 h-8 rounded-full bg-emerald-100 flex items-center justify-center"><i class="fas fa-check text-emerald-600"></i></div></div>`;
                } else {
                    // Pending notification - orange style with file icon
                    notificationStyle = 'background: linear-gradient(to right, rgba(249, 115, 22, 0.1), transparent); border-left: 3px solid #f97316;';
                    iconElement = `<div class="mt-1"><div class="w-8 h-8 rounded-full bg-orange-100 flex items-center justify-center"><i class="fas fa-file-invoice text-orange-600"></i></div></div>`;
                }

                const title = isReceived ? 'Received Supply Request' : 'Pending Supply Request';
                const statusBadge = isReceived 
                    ? `<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-emerald-100 text-emerald-700">Received</span>`
                    : `<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-orange-100 text-orange-700">Pending</span>`;

                html += `
                    <a href="${viewLink}" class="block px-4 py-3 border-b hover:bg-gray-50 transition-colors no-underline" style="${notificationStyle}">
                        <div class="flex items-start gap-3">
                            ${iconElement}
                            <div>
                                <div class="flex items-center gap-2 mb-1">
                                    <p class="text-sm font-bold text-gray-800 mb-0">${title}</p>
                                    ${statusBadge}
                                </div>
                                <p class="text-xs text-gray-600 mb-1">${req.pre_repair_no || 'N/A'} - ${req.requested_by || 'Unknown'}</p>
                                <p class="text-[10px] text-gray-400">${date}</p>
                            </div>
                        </div>
                    </a>
                `;
            });

            notificationsList.innerHTML = html;
        }

        // Mark all notifications as read
        function markAllNotificationsAsRead() {
            const formData = new FormData();
            formData.append('action', 'mark_as_read');

            fetch('../fetch_supply_notifications.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Hide badge
                        const badge = document.querySelector('#notificationsContainer .absolute.-top-1');
                        if (badge) {
                            badge.style.display = 'none';
                        }

                        // Update badge count to 0
                        const countSpan = document.getElementById('supplyNotificationCount');
                        if (countSpan) {
                            countSpan.textContent = '0';
                        }

                        // Show empty state
                        const notificationsList = document.getElementById('supplyNotificationsList');
                        if (notificationsList) {
                            notificationsList.innerHTML = `
                                <div class="p-8 text-center">
                                    <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-gray-100 mb-3">
                                        <i class="far fa-check-circle text-gray-400 text-xl"></i>
                                    </div>
                                    <p class="text-gray-500 text-sm m-0">No pending or received requests</p>
                                </div>
                            `;
                        }

                        // Show success message
                        alert('All notifications marked as read!');
                    } else {
                        alert('Failed to mark notifications as read');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred');
                });
        }
    </script>
</body>

</html>
<?php if (isset($mysqli)) $mysqli->close(); ?>