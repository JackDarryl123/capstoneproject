<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/session_helper.php';
start_user_session();


// ADD THIS DEBUG CODE
error_log("staff_dashboard.php - Session user_id: " . ($_SESSION['user_id'] ?? 'NOT SET'));
error_log("staff_dashboard.php - Session location: " . ($_SESSION['location'] ?? 'NOT SET'));

if (empty($_SESSION['user_id'])) {
    error_log("staff_dashboard.php - REDIRECTING TO LOGIN because user_id is empty");
    header('Location: ../index.php');
    exit();
}

// Redirect if not logged in or not staff
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'staff') {
    header("Location: ../index.php?login");
    exit();
}
// Allowed views
$allowedViews = ['dashboard', 'equipment', 'scan', 'inventory', 'activities', 'report'];
$view = $_GET['view'] ?? 'dashboard';
if (!in_array($view, $allowedViews, true)) {
    $view = '404';
}


// Fetch user details for profile dropdown
$userId = $_SESSION['user_id'] ?? 0;
$currentUser = [];
if ($userId) {
    $userStmt = $mysqli->prepare("SELECT username, email, status, role, signature FROM users WHERE id = ?");
    $userStmt->bind_param("i", $userId);
    $userStmt->execute();
    $currentUser = $userStmt->get_result()->fetch_assoc();
    $userStmt->close();
}
?>



<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Dashboard | PEPO</title>

    <script src="../rs/js/fullcalendar.6.1.8.min.js?v=<?= time() ?>"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- jQuery and Select2 (Global) -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js" type="text/javascript"></script>
    <script src="https://cdn.tailwindcss.com"></script>


    <style>
        :root {
            --sidebar-width: 280px;
            --header-height: 70px;
            --sidebar-bg: #0f172a;

            --sidebar-text: #94a3b8;

            --active-green: #10b981;

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

        /* --- SMOOTH LOADER STYLING --- */
        #page-loader {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(243, 244, 246, 0.7);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 50;
            backdrop-filter: blur(2px);
            border-radius: 24px;
        }

        .loader-spinner {
            width: 40px;
            height: 40px;
            border: 3px solid #e2e8f0;
            border-top: 3px solid #10b981;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        .fade-out {
            opacity: 0;
            transition: opacity 0.2s ease-out;
        }

        .fade-in {
            opacity: 1;
            transition: opacity 0.3s ease-in;
        }

        body {
            background-color: #f3f4f6;
            /* Light gray background */
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
            z-index: 1050;
            display: flex;
            flex-direction: column;
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 4px 0 24px rgba(0, 0, 0, 0.1);
        }

        /* Logo Area */
        .sidebar-header {
            height: 80px;
            display: flex;
            align-items: center;
            padding: 0 24px;
            background: #0f172a;
            /* Seamless match */
        }

        /* Navigation Links - Matching User Dashboard Format */
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

        /* --- GLASSMORMISM TOP NAVBAR --- */
        .top-navbar {
            position: fixed;
            top: 0;
            left: var(--sidebar-width);
            width: calc(100% - var(--sidebar-width));
            height: var(--header-height);
            background: rgba(2, 12, 29, 0.98);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-bottom: 1px solid #07a83f;
            z-index: 1040;
            padding: 0 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: all 0.3s ease;
        }

        /* --- MAIN CONTENT --- */
        .main-content {
            margin-left: var(--sidebar-width);
            padding: 2rem;
            padding-top: calc(var(--header-height) + 2rem);
            min-height: 100vh;
            transition: all 0.3s ease;
        }

        /* Custom Scrollbar */
        .custom-scrollbar::-webkit-scrollbar {
            width: 5px;
        }

        .custom-scrollbar::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.02);
        }

        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: #334155;
            border-radius: 10px;
        }

        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            background: #10b981;
        }

        /* Mobile Responsive */
        @media (max-width: 991.98px) {
            .sidebar {
                transform: translateX(-100%);
                width: 280px;
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
                padding: 1rem;
                padding-top: 85px;
            }

            #mobileToggle {
                display: flex !important;
            }

            #sidebarCloseBtn {
                display: flex !important;
            }
        }

        /* Burger Button handling */
        #mobileToggle.hidden-burger {
            opacity: 0;
            pointer-events: none;
        }
    </style>


</head>

<body>


    <div class="sidebar-overlay fixed inset-0 bg-black/50 z-[1045] hidden" id="sidebarOverlay"></div>

    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <img src="../rs/Pepo_Logo.png" alt="PEPO" class="w-10 h-10 object-contain mr-3 drop-shadow-md">
            <div class="flex flex-col">
                <span class="font-bold text-xl text-white tracking-tight">PEPO</span>
                <span class="text-[10px] font-bold text-emerald-400 uppercase tracking-widest">Staff Panel</span>
            </div>
            <button
                class="lg:hidden ml-auto text-slate-400 hover:text-white bg-transparent border-0 transition-colors hidden"
                id="sidebarCloseBtn">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>

        <div class="px-4 mb-2 mt-2">
            <div class="bg-slate-800/50 rounded-xl p-3 border border-slate-700/50 flex items-center justify-between">
                <div>
                    <p class="text-[10px] text-slate-400 uppercase font-semibold m-0">Status</p>
                    <p class="text-sm font-bold text-white m-0">Staff Active</p>
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

            <a href="#" data-view="inventory"
                class="nav-link-custom ajax-nav <?= $view === 'inventory' ? 'active' : '' ?>">
                <i class="fas fa-boxes"></i> <span>Inventory</span>
            </a>

            <a href="#" data-view="activities"
                class="nav-link-custom ajax-nav <?= $view === 'activities' ? 'active' : '' ?>">
                <i class="far fa-calendar-alt"></i> <span>Schedule</span>
            </a>

            <div class="my-4 mx-6 border-t border-slate-700/50"></div>
            <div class="px-6 mb-3 text-[11px] font-bold text-slate-500 uppercase tracking-widest">Account</div>
        </nav>

        <div class="p-4 border-t border-slate-800 shrink-0 bg-[#0b1120]">
            <a href="../logout.php"
                class="flex items-center justify-center w-full py-3 px-4 text-sm font-semibold text-white bg-slate-800 hover:bg-red-600 hover:shadow-lg hover:shadow-red-900/40 rounded-xl transition-all duration-300 no-underline group">
                <i class="fas fa-sign-out-alt mr-2 group-hover:rotate-180 transition-transform duration-300"></i>
                Log out
            </a>
        </div>
    </aside>



    <nav class="top-navbar">
        <button id="mobileToggle"
            class="btn lg:hidden me-auto text-slate-600 hover:text-emerald-600 border-0 flex align-items-center justify-content-center p-2 transition-colors">
            <i class="fas fa-bars text-2xl"></i>
        </button>

        <!-- <h4 class="font-bold text-slate-700 text-lg m-0 hidden md:block" id="page-title-display">
            Staff Console
        </h4> -->

        <div class="flex items-center gap-5 ms-auto">
            <div class="dropdown">
                <a href="#"
                    class="flex items-center gap-3 text-decoration-none bg-white border border-slate-200 rounded-full pl-1 pr-1 py-1 md:pl-4 hover:shadow-md transition-all group"
                    data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="text-end d-none d-md-block">
                        <div class="text-sm font-bold text-slate-700 leading-tight group-hover:text-emerald-700">
                            <?= htmlspecialchars($currentUser['username'] ?? 'Staff') ?>
                        </div>
                        <div class="text-[10px] text-slate-400 uppercase font-bold tracking-wider">
                            <?= ucfirst($currentUser['role'] ?? 'Staff') ?>
                        </div>
                    </div>
                    <div
                        class="h-8 w-8 rounded-full bg-emerald-100 border border-white flex items-center justify-center text-emerald-600 shadow-sm">
                        <span
                            class="font-bold text-sm"><?= substr(strtoupper($currentUser['username'] ?? 'S'), 0, 1) ?></span>
                    </div>
                </a>

                <ul
                    class="dropdown-menu dropdown-menu-end shadow-[0_10px_40px_-10px_rgba(0,0,0,0.1)] border-0 rounded-2xl mt-3 p-2 w-[240px]">
                    <li class="d-md-none px-4 py-3 border-b border-gray-50 mb-2 bg-slate-50 rounded-xl">
                        <div class="fw-bold text-slate-800"><?= htmlspecialchars($currentUser['username'] ?? 'Staff') ?>
                        </div>
                        <div class="text-xs text-slate-500"><?= htmlspecialchars($currentUser['email'] ?? '') ?></div>
                    </li>
                    <li>
                        <a class="dropdown-item rounded-xl py-2 px-4 text-sm font-medium text-slate-600 hover:bg-emerald-50 hover:text-emerald-700"
                            href="#" data-bs-toggle="modal" data-bs-target="#profileModal">
                            <i class="fas fa-cog w-4 me-2 text-slate-400"></i> Settings
                        </a>
                    </li>
                    <li>
                        <hr class="dropdown-divider my-1">
                    </li>
                    <li>
                        <a class="dropdown-item rounded-xl py-2 px-4 text-sm font-medium text-red-600 hover:bg-red-50"
                            href="../logout.php">
                            <i class="fas fa-sign-out-alt w-4 me-2"></i> Logout
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>



    <main class="main-content">
        <div class="container-fluid p-0">
            <h4 class="fw-bold text-gray-800 mb-2" id="page-title">
                <?php
                if ($view === 'dashboard')
                    echo '';
                elseif ($view === 'scan')
                    echo '';
                elseif ($view === 'inventory')
                    echo '';
                elseif ($view === 'activities')
                    echo '';
                else
                    echo '';
                ?>
            </h4>
            <!-- <div class="h-[3px] bg-green-600 w-[60px] rounded-full mb-6"></div> -->
        </div>

        <div id="content-container" class="relative min-h-[400px]">
            <!-- Page Loader Overlay -->
            <div id="page-loader">
                <div class="flex flex-col items-center">
                    <div class="loader-spinner mb-3"></div>
                    <span class="text-[10px] font-black uppercase tracking-widest text-slate-400">Loading
                        Content...</span>
                </div>
            </div>

            <?php
            // Initial content will be loaded via AJAX
            ?>
        </div>
    </main>



    <!-- Profile Settings Modal -->
    <div class="modal fade" id="profileModal" tabindex="-1" aria-hidden="true" style="z-index: 2000;">
        <div class="modal-dialog" style="z-index: 2001;">
            <div class="modal-content rounded-4">
                <div class="modal-header bg-gradient-to-r from-emerald-600 to-teal-500 text-white">
                    <h5 class="modal-title"><i class="fas fa-user-cog me-2"></i>Profile Settings</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="../process.php" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" name="redirect_to" value="staff/staff_dashboard.php">
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
                            <div class="form-text text-muted small">Upload a PNG/JPG image of your signature.
                            </div>
                        </div>

                        <h6 class="fw-bold text-primary mb-3">Change Password</h6>
                        <div class="mb-3">
                            <label class="form-label">Current Password</label>
                            <input type="password" name="current_password" class="form-control"
                                placeholder="Current Password">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">New Password</label>
                            <input type="password" name="password" class="form-control"
                                placeholder="Leave blank to keep current" minlength="8" maxlength="8">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Confirm New Password</label>
                            <input type="password" name="confirm_password" class="form-control" minlength="8"
                                maxlength="8">
                        </div>
                    </div>
                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" name="update" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels"></script>


    <script>
        // Define view variable from PHP
        const view = "<?php echo htmlspecialchars($view); ?>";
        const titles = {
            'dashboard': ' ',
            'scan': ' ',
            'inventory': ' ',
            'activities': ' ',
            'report': ' '
        };

        // Update page title
        document.getElementById('page-title').textContent = titles[view] || 'Dashboard';

        // ... existing variables ...

        // Sidebar Logic
        const sidebar = document.getElementById('sidebar'); // Changed selector to ID
        const overlay = document.getElementById('sidebarOverlay');
        const showBtn = document.getElementById('mobileToggle');
        const closeBtn = document.getElementById('sidebarCloseBtn');

        function openSidebar() {
            sidebar.classList.add('active');
            overlay.classList.remove('hidden'); // Tailwind hidden class
            overlay.classList.add('active'); // CSS active class
            showBtn.classList.add('hidden-burger');
        }

        function closeSidebar() {
            sidebar.classList.remove('active');
            overlay.classList.add('hidden');
            overlay.classList.remove('active');
            showBtn.classList.remove('hidden-burger');
        }

        showBtn.addEventListener('click', openSidebar);
        overlay.addEventListener('click', closeSidebar);
        if (closeBtn) closeBtn.addEventListener('click', closeSidebar);

        // Navigation Logic
        function setActiveNav(view) {
            document.querySelectorAll('.nav-link-custom').forEach(link => {
                link.classList.remove('active');
                if (link.getAttribute('data-view') === view) {
                    link.classList.add('active');
                }
            });
        }

        // ... Keep your loadContent logic here, but remove the Ripple Effect code blocks ...

        // Add Keyframe for Ripple if not exists
        const styleSheet = document.createElement("style");
        styleSheet.innerText = `
    @keyframes ripple {
        to { transform: scale(4); opacity: 0; }
    }
`;
        document.head.appendChild(styleSheet);

        // Handle navigation clicks
        document.querySelectorAll('.ajax-nav').forEach(link => {
            link.addEventListener('click', function (e) {
                e.preventDefault();
                const view = this.getAttribute('data-view');

                // Update URL without page reload
                const url = new URL(window.location);
                url.searchParams.set('view', view);
                window.history.pushState({}, '', url);

                // Update title
                document.getElementById('page-title').textContent = titles[view] || 'Dashboard';

                // Load content
                loadContent(view);

                // Set active nav link
                setActiveNav(view);

                // Close sidebar on mobile
                if (window.innerWidth < 992) {
                    closeSidebar();
                }
            });
        });

        // Handle browser back/forward
        window.addEventListener('popstate', function () {
            const urlParams = new URLSearchParams(window.location.search);
            const view = urlParams.get('view') || 'dashboard';
            // Update title
            document.getElementById('page-title').textContent = titles[view] || 'Dashboard';
            loadContent(view);
            setActiveNav(view);
        });

        function setActiveNav(view) {
            document.querySelectorAll('.ajax-nav').forEach(link => {
                link.classList.remove('active');
                if (link.getAttribute('data-view') === view) {
                    link.classList.add('active');
                }
            });
        }

        function loadContent(view) {
            const container = document.getElementById('content-container');
            const loader = document.getElementById('page-loader');

            // 1. Show loader immediately
            if (loader) loader.style.display = 'flex';

            // 2. Start fading out current content (except loader)
            const oldContent = container.querySelector('.view-wrapper') || container.firstElementChild;
            if (oldContent && oldContent !== loader) {
                oldContent.style.opacity = '0.3';
                oldContent.style.filter = 'blur(1px)';
            }

            // Fetch content
            fetch(`load_content.php?view=${view}`)
                .then(response => {
                    if (!response.ok) throw new Error('Network response was not ok');
                    return response.text();
                })
                .then(html => {
                    // Create a wrapper for the new content to handle the fade-in
                    const wrappedHtml = `<div class="view-wrapper opacity-0 transition-opacity duration-300">${html}</div>`;

                    // Insert content but KEEP the loader
                    container.innerHTML = '';
                    container.appendChild(loader);
                    container.insertAdjacentHTML('beforeend', wrappedHtml);

                    const wrapper = container.querySelector('.view-wrapper');

                    // Execute scripts
                    const scripts = wrapper.querySelectorAll('script');
                    scripts.forEach((script, idx) => {
                        const newScript = document.createElement('script');
                        if (script.src) {
                            newScript.src = script.src;
                        } else {
                            newScript.text = script.innerHTML;
                        }
                        if (newScript.src || newScript.text.trim().length > 0) {
                            document.body.appendChild(newScript);
                        }
                    });

                    // Trigger fade-in and hide loader
                    setTimeout(() => {
                        if (wrapper) wrapper.classList.remove('opacity-0');
                        if (loader) loader.style.display = 'none';
                    }, 50);
                })
                .catch(error => {
                    console.error('Error loading content:', error);
                    if (loader) loader.style.display = 'none';
                    container.innerHTML = `<div class="alert alert-danger">Error loading content. Please refresh the page.</div>`;
                });
        }
        // ADD THIS: Trigger initial content load
        // ==========================================
        document.addEventListener('DOMContentLoaded', () => {
            // Load the view defined by PHP immediately
            loadContent(view);

            // Highlight the correct sidebar item
            setActiveNav(view);

            // Open profile modal via URL parameter
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('modal') === 'profile') {
                const profileModal = document.getElementById('profileModal');
                if (profileModal) {
                    new bootstrap.Modal(profileModal).show();
                }
            }

            // Fix backdrop z-index when profile modal is shown
            const profileModalEl = document.getElementById('profileModal');
            if (profileModalEl) {
                profileModalEl.addEventListener('shown.bs.modal', function () {
                    const backdrops = document.querySelectorAll('.modal-backdrop');
                    backdrops.forEach(bp => {
                        bp.style.zIndex = '1999';
                        bp.style.position = 'fixed';
                    });
                });
                // Clean up backdrop on close
                profileModalEl.addEventListener('hidden.bs.modal', function () {
                    const backdrops = document.querySelectorAll('.modal-backdrop');
                    backdrops.forEach(backdrop => backdrop.remove());
                    document.body.classList.remove('modal-open');
                    document.body.style.overflow = '';
                });
            }
        });
    </script>
</body>

</html>