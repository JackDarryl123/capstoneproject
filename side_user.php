<?php
// --- 1. SETUP & SAFETY CHECKS ---
date_default_timezone_set('Asia/Manila');

require_once 'includes/session_helper.php';
start_user_session();

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

if (($_SESSION['role'] ?? '') !== 'admin') {
    die('Unauthorized access. Admin access required.');
}

if (!isset($mysqli)) {
    include 'config.php';
}

// --- 2. GENERATE CSRF TOKEN ---
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// --- 3. GET CURRENT USER DATA ---
$user_id = $_SESSION['user_id'];

// Fetch admin status and location
// Note: We use the existing $mysqli connection
$checkAdminStmt = $mysqli->prepare("SELECT is_admin, location FROM users WHERE id = ?");
$checkAdminStmt->bind_param("i", $user_id);
$checkAdminStmt->execute();
$adminResult = $checkAdminStmt->get_result();
$currentUserData = $adminResult->fetch_assoc();
$checkAdminStmt->close();

$is_current_user_admin = ($currentUserData['is_admin'] == 1);

// Determine valid location - use session or fallback to DB
$logged_in_location = $_SESSION['user_location'] ?? $currentUserData['location'] ?? null;

// Role display labels (only for display, database values remain unchanged)
$roleDisplayLabels = [
    'admin' => 'Maintenance Dept Admin',
    'supply' => 'Supply Dept Admin',
    'user' => 'Property Custodian',
    'staff' => 'Maintenance Staff'
];

function getRoleDisplayLabel($role) {
    global $roleDisplayLabels;
    return $roleDisplayLabels[$role] ?? ucfirst($role);
}

// --- 3. HANDLE ADMIN LOCATION CHANGE (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_admin_location'])) {
    if (!$is_current_user_admin) {
        $_SESSION['message'] = 'Unauthorized access.';
        $_SESSION['msg_type'] = 'danger';
        header('Location: admin_dashboard.php?view=user');
        exit();
    }

    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf'] ?? '')) {
        $_SESSION['message'] = 'Invalid CSRF token.';
        $_SESSION['msg_type'] = 'danger';
        header('Location: admin_dashboard.php?view=user');
        exit();
    }

    $new_location = $_POST['admin_location'];

    $updateLocStmt = $mysqli->prepare("UPDATE users SET location = ? WHERE id = ?");
    $updateLocStmt->bind_param("si", $new_location, $user_id);

    if ($updateLocStmt->execute()) {
        $_SESSION['user_location'] = $new_location;
        $_SESSION['location'] = $new_location;
        $_SESSION['message'] = 'Location updated successfully.';
        $_SESSION['msg_type'] = 'success';
        header('Location: admin_dashboard.php?view=user');
        exit();
    }
}

// --- 4. FETCH DATA LOGIC ---

// A. Fetch Signup Requests - Filter by location (designation column matches user's location)
if ($logged_in_location) {
    // User has location - show only requests with matching designation
    $requestQuery = "SELECT * FROM signup_requests WHERE status = 'pending' AND designation = ? ORDER BY requested_at DESC";
    $requestStmt = $mysqli->prepare($requestQuery);
    if ($requestStmt) {
        $requestStmt->bind_param("s", $logged_in_location);
        $requestStmt->execute();
        $requestResult = $requestStmt->get_result();
        $requestStmt->close();
    } else {
        $requestResult = false;
    }
} else {
    // No location set - show all pending requests
    $requestStmt = $mysqli->prepare("SELECT * FROM signup_requests WHERE status = 'pending' ORDER BY requested_at DESC");
    if ($requestStmt) {
        $requestStmt->execute();
        $requestResult = $requestStmt->get_result();
        $requestStmt->close();
    } else {
        $requestResult = false;
    }
}

// B. Handle Filters
$selected_role = isset($_GET['role']) ? $_GET['role'] : 'all';
$selected_status = isset($_GET['status']) ? $_GET['status'] : 'all';

$allowedRoles = ['admin', 'staff', 'supply', 'user'];
$allowedStatuses = ['active', 'inactive'];

if (!in_array($selected_role, $allowedRoles, true) && $selected_role !== 'all') {
    $selected_role = 'all';
}
if (!in_array($selected_status, $allowedStatuses, true) && $selected_status !== 'all') {
    $selected_status = 'all';
}

if (!$is_current_user_admin && in_array($selected_role, ['admin', 'supply'], true)) {
    $selected_role = 'all';
}

$userQuery = "SELECT * FROM users WHERE 1=1";
$params = [];
$types = '';

if (!$is_current_user_admin) {
    $userQuery .= " AND role IN ('staff', 'user')";
}

if ($logged_in_location) {
    $userQuery .= " AND location = ?";
    $params[] = $logged_in_location;
    $types .= 's';
}
if ($selected_role !== 'all') {
    $userQuery .= " AND role = ?";
    $params[] = $selected_role;
    $types .= 's';
}
if ($selected_status !== 'all') {
    $userQuery .= " AND status = ?";
    $params[] = $selected_status;
    $types .= 's';
}

$userQuery .= " ORDER BY is_admin DESC, id DESC";

if (count($params) > 0) {
    $userStmt = $mysqli->prepare($userQuery);
    $userStmt->bind_param($types, ...$params);
    $userStmt->execute();
    $userResult = $userStmt->get_result();
    $userStmt->close();
} else {
    $userResult = $mysqli->query($userQuery);
}
$pendingCount = $requestResult ? $requestResult->num_rows : 0;

// --- 5. URL BUILDER FUNCTIONS ---
// We check if function exists to avoid "Cannot redeclare" error if included multiple times
if (!function_exists('getFilterUrl')) {
    function getFilterUrl($key, $value)
    {
        $params = $_GET;
        if ($value === 'all') {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
        $queryString = http_build_query($params);
        // Correct Link: Point to the dashboard view
        return '?view=user&' . $queryString;
    }
}

if (!function_exists('build_url_with_filter')) {
    function build_url_with_filter($role = null, $status = null)
    {
        $params = $_GET;
        // Preserve the 'view' parameter
        $params['view'] = 'user';

        if ($role !== null)
            $params['role'] = ($role === 'all') ? null : $role;
        if ($status !== null)
            $params['status'] = ($status === 'all') ? null : $status;

        $params = array_filter($params);
        return '?' . http_build_query($params);
    }
}

if (!function_exists('build_clear_filters_url')) {
    function build_clear_filters_url()
    {
        return '?view=user';
    }
}

// ... existing PHP code ...

// Helper to determine if we need to show the button
$is_filter_active = ($selected_role !== 'all' || $selected_status !== 'all');

// Function to generate the reset URL (Back to default user view)
function getClearFilterUrl()
{
    return '?view=user';
}

// MOVE INCLUDE HERE: Include the modal HTML only after logic is done
include_once __DIR__ . '/profile_modal.php';
?>




<div class="max-w-[1600px] mx-auto p-6 bg-gray-50 min-h-screen font-sans">

    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-8">
        <div>
            <h2 class="text-2xl font-bold text-gray-800 tracking-tight flex items-center gap-3">
                <span class="bg-emerald-500 text-emerald-600 p-2 rounded-lg">
                    <i class="bi bi-people-fill text-xl"></i>
                </span>
                User Management
            </h2>


            <?php if ($is_current_user_admin): ?>

                <p>As the Provincial Administrator, you can change and oversee PEPO maintenance department of All locations.
                </p>
                <form method="POST" class="mt-2 flex items-center gap-3">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                    <label class="text-xs font-bold text-gray-500 uppercase flex items-center gap-1">
                        <i class="bi bi-geo-alt-fill text-emerald-500"></i>
                        Location Under supevision:
                    </label>

                    <div class="relative flex items-center">
                        <input type="hidden" name="update_admin_location" value="1">
                        <select name="admin_location" onchange="this.form.submit()"
                            class="appearance-none w-full bg-gradient-to-r from-emerald-50 to-white border-2 border-emerald-200 text-emerald-700 text-sm font-bold py-2 pl-4 pr-12 rounded-xl shadow-md focus:outline-none focus:ring-2 focus:ring-emerald-400 focus:border-emerald-400 cursor-pointer hover:bg-emerald-100 hover:border-emerald-300 transition-all duration-200 min-w-[180px]">
                            <option value="Mamburao" <?= $logged_in_location === 'Mamburao' ? 'selected' : '' ?>>📍 Mamburao</option>
                            <option value="Sablayan" <?= $logged_in_location === 'Sablayan' ? 'selected' : '' ?>>📍 Sablayan</option>
                            <option value="San Jose" <?= $logged_in_location === 'San Jose' ? 'selected' : '' ?>>📍 San Jose</option>
                            <option value="Lubang" <?= $logged_in_location === 'Lubang' ? 'selected' : '' ?>>📍 Lubang</option>
                        </select>
                        <div class="absolute right-1 top-1/2 -translate-y-1/2 pointer-events-none flex items-center justify-center w-8 h-8 bg-emerald-500 rounded-lg">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="white" viewBox="0 0 16 16">
                                <path d="M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z"/>
                            </svg>
                        </div>
                    </div>


                </form>

            <?php else: ?>
                <p>Manage accounts, roles, and access permissions.</p>
            <?php endif; ?>
        </div>

        <button type="button" id="reviewRequestsBtn"
            class="group relative inline-flex items-center justify-center px-5 py-2.5 text-sm font-medium text-white transition-all duration-200 bg-emerald-600 rounded-xl hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-emerald-600 shadow-lg shadow-emerald-200"
            data-bs-toggle="modal" data-bs-target="#signupRequestsModal">

            <?php if ($pendingCount > 0): ?>
                <span class="absolute -top-1 -right-1 flex h-4 w-4">
                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-4 w-4 bg-red-500"></span>
                </span>
            <?php endif; ?>

            <i class="bi bi-person-plus-fill mr-2"></i>
            Review Requests
            <?php if ($pendingCount > 0): ?>
                <span class="ml-2 bg-white/20 px-2 py-0.5 rounded text-xs font-bold"><?= $pendingCount; ?></span>
            <?php endif; ?>
        </button>
    </div>

    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 mb-6">

        <div class="flex flex-col lg:flex-row justify-between items-center gap-4">

            <div class="flex items-center gap-2 overflow-x-auto pb-2 lg:pb-0 w-full lg:w-auto min-h-[38px]">

                <span class="text-xs font-bold text-gray-400 uppercase tracking-wider mr-1">Maintenance
                    Departement:</span>

                <?php if (!$is_filter_active && !$logged_in_location): ?>
                    <span
                        class="text-gray-400 text-sm italic bg-gray-50 px-3 py-1.5 rounded-lg border border-gray-100 border-dashed">
                        None active
                    </span>
                <?php endif; ?>

                <?php if ($logged_in_location): ?>
                    <div
                        class="group flex items-center gap-2 px-3 py-1.5 bg-blue-50 text-blue-700 rounded-lg text-xs font-bold border border-blue-100 transition-all hover:shadow-sm">

                        <i class="bi bi-geo-alt"></i>
                        <?= htmlspecialchars($logged_in_location); ?>
                        <span
                            class="hidden group-hover:inline text-blue-400 text-[10px] ml-1 border-l border-blue-200 pl-2">
                            Location
                        </span>
                    </div>
                <?php endif; ?>

                <?php if ($selected_role !== 'all'): ?>
                    <a href="<?= getFilterUrl('role', 'all'); ?>"
                        class="group flex items-center gap-2 px-3 py-1.5 bg-emerald-50 text-emerald-700 rounded-lg text-xs font-bold border border-emerald-100 hover:bg-red-50 hover:text-red-600 hover:border-red-100 transition-all cursor-pointer"
                        title="Remove this filter">
                        <span>Role: <?= getRoleDisplayLabel($selected_role); ?></span>
                        <i class="bi bi-x text-lg leading-none opacity-50 group-hover:opacity-100"></i>
                    </a>
                <?php endif; ?>

                <?php if ($selected_status !== 'all'): ?>
                    <a href="<?= getFilterUrl('status', 'all'); ?>"
                        class="group flex items-center gap-2 px-3 py-1.5 bg-purple-50 text-purple-700 rounded-lg text-xs font-bold border border-purple-100 hover:bg-red-50 hover:text-red-600 hover:border-red-100 transition-all cursor-pointer"
                        title="Remove this filter">
                        <span>Status: <?= ucfirst($selected_status); ?></span>
                        <i class="bi bi-x text-lg leading-none opacity-50 group-hover:opacity-100"></i>
                    </a>
                <?php endif; ?>
            </div>

            <div class="flex items-center gap-2 w-full lg:w-auto justify-end">

                <?php if ($is_filter_active): ?>
                    <a href="<?= getClearFilterUrl(); ?>"
                        class="group flex items-center gap-2 px-4 py-2 bg-white border border-red-100 text-gray-500 rounded-lg text-sm font-medium 
                            hover:bg-red-50 hover:text-red-600 hover:border-red-200 hover:shadow-md transition-all duration-200 mr-2">
                        <i class="bi bi-funnel-x-fill text-red-400 group-hover:text-red-600 transition-colors"></i>
                        <span>Clear Filters</span>
                    </a>
                    <div class="h-6 w-px bg-gray-200 mx-1"></div> <?php endif; ?>

                <div class="dropdown">
                    <button class="btn btn-outline-secondary dropdown-toggle 
                            flex items-center gap-2 px-4 py-2 
                            bg-emerald-500 border border-emerald-400 
                            text-white rounded-lg 
                            hover:bg-emerald-600 hover:border-emerald-600 
                            focus:ring-2 focus:ring-emerald-300 
                            text-sm font-semibold 
                            transition-all shadow-sm" type="button" id="roleFilterDropdown" data-bs-toggle="dropdown"
                        aria-expanded="false">
                        <i
                            class="bi bi-person-badge <?= $selected_role !== 'all' ? 'text-emerald-600' : 'text-gray-400' ?>"></i>
                        <?= $selected_role === 'all' ? 'Role' : getRoleDisplayLabel($selected_role); ?>
                    </button>
                    <ul class="dropdown-menu shadow-xl border-0 rounded-xl mt-2 p-1 w-48 animate-fade-in-down"
                        aria-labelledby="roleFilterDropdown">
                        <li><a class="dropdown-item rounded-lg px-3 py-2 text-sm font-medium text-gray-600 hover:bg-emerald-50 hover:text-emerald-700"
                                href="<?= getFilterUrl('role', 'all'); ?>">All Roles</a></li>
                        <li>
                            <hr class="dropdown-divider my-1 border-gray-100">
                        </li>
                        <?php if ($is_current_user_admin): ?>
                            <li><a class="dropdown-item rounded-lg px-3 py-2 text-sm hover:bg-emerald-50 hover:text-emerald-700"
                                    href="<?= getFilterUrl('role', 'admin'); ?>">Maintenance Dept Admin</a></li>
                            <li><a class="dropdown-item rounded-lg px-3 py-2 text-sm hover:bg-emerald-50 hover:text-emerald-700"
                                    href="<?= getFilterUrl('role', 'supply'); ?>">Supply Dept Admin</a></li>
                        <?php endif; ?>
                        <li><a class="dropdown-item rounded-lg px-3 py-2 text-sm hover:bg-emerald-50 hover:text-emerald-700"
                                href="<?= getFilterUrl('role', 'staff'); ?>">Maintenance Staff</a></li>
                        <li><a class="dropdown-item rounded-lg px-3 py-2 text-sm hover:bg-emerald-50 hover:text-emerald-700"
                                href="<?= getFilterUrl('role', 'user'); ?>">Property Custodian</a></li>
                    </ul>
                </div>

                <div class="dropdown">
                    <button class="btn btn-outline-secondary dropdown-toggle 
                    flex items-center gap-2 px-4 py-2 
                    bg-emerald-500 border border-emerald-400 
                    text-white rounded-lg 
                    hover:bg-emerald-600 hover:border-emerald-600 
                    focus:ring-2 focus:ring-emerald-300 
                    text-sm font-semibold 
                    transition-all shadow-sm" type="button" id="statusFilterDropdown" data-bs-toggle="dropdown"
                        aria-expanded="false">
                        <i
                            class="bi bi-toggle-on <?= $selected_status !== 'all' ? 'text-green-600' : 'text-white-400' ?>"></i>
                        <?= ucfirst($selected_status === 'all' ? 'Status' : $selected_status); ?>
                    </button>
                    <ul class="dropdown-menu shadow-xl border-0 rounded-xl mt-2 p-1 w-48 animate-fade-in-down"
                        aria-labelledby="statusFilterDropdown">
                        <li><a class="dropdown-item rounded-lg px-3 py-2 text-sm font-medium text-gray-600 hover:bg-green-50 hover:text-green-700"
                                href="<?= getFilterUrl('status', 'all'); ?>">All Status</a></li>
                        <li>
                            <hr class="dropdown-divider my-1 border-gray-100">
                        </li>
                        <li><a class="dropdown-item rounded-lg px-3 py-2 text-sm hover:bg-green-50 hover:text-green-700"
                                href="<?= getFilterUrl('status', 'active'); ?>">Active</a></li>
                        <li><a class="dropdown-item rounded-lg px-3 py-2 text-sm hover:bg-green-50 hover:text-green-700"
                                href="<?= getFilterUrl('status', 'inactive'); ?>">Inactive</a></li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="mt-4 pt-4 border-t border-gray-100 flex items-center justify-between text-xs text-gray-500">
            <div>
                Showing <strong class="text-gray-900 text-sm"><?= $userResult->num_rows; ?></strong>
                result<?= $userResult->num_rows != 1 ? 's' : '' ?>
            </div>
            <?php if ($is_filter_active): ?>
                <div class="text-emerald-600 font-medium flex items-center gap-1 animate-pulse">
                    <i class="bi bi-funnel-fill"></i> Filters applied
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="bg-gray-50/80 border-b border-gray-100">
                        <th class="px-6 py-4 text-xs font-bold text-gray-500 uppercase tracking-wider">User Profile</th>
                        <th class="px-6 py-4 text-xs font-bold text-gray-500 uppercase tracking-wider">Current Role</th>
                        <th class="px-6 py-4 text-xs font-bold text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-4 text-xs font-bold text-gray-500 uppercase tracking-wider">Manage Role</th>
                        <th class="px-6 py-4 text-xs font-bold text-gray-500 uppercase tracking-wider">Manage Status
                        </th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-gray-50">

                    <?php if ($userResult->num_rows > 0): ?>

                        <?php while ($user = $userResult->fetch_assoc()): ?>

                            <?php
                            // Check if row is a Master Admin
                            $is_row_admin = ($user['is_admin'] == 1);

                            // NEW CHECK: Check if this row is YOU (the currently logged-in user)
                            $is_current_user = ($user['id'] == $_SESSION['user_id']);

                            // Check if row role is supply or admin
                            $is_privileged_role = in_array($user['role'], ['supply', 'admin'], true);

                            // Lock role editing if:
                            // - Current user is NOT a Provincial Admin (is_admin = 1) OR
                            // - It is yourself
                            $is_role_locked = (!$is_current_user_admin || $is_current_user);

                            // Lock status editing if it is yourself
                            $is_status_locked = $is_current_user;
                            ?>

                            <tr
                                class="hover:bg-gray-50/60 transition-colors group <?= $is_current_user ? 'bg-emerald-50/30' : '' ?>">

                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        <div
                                            class="h-10 w-10 rounded-full flex items-center justify-center font-bold text-sm shadow-sm <?= $is_row_admin ? '' : 'bg-emerald-100 text-emerald-600' ?>">
                                            <?php if ($is_row_admin): ?>
                                                <img src="rs/Pepo_Logo.png" alt="PEPO Admin" class="h-10 w-10 rounded-full object-cover shadow-sm">
                                            <?php else: ?>
                                                <?= strtoupper(substr($user['username'], 0, 2)); ?>
                                            <?php endif; ?>
                                        </div>

                                        <div>
                                            <div class="font-semibold text-gray-900 flex items-center gap-2">
                                                <?= htmlspecialchars($user['username']); ?>

                                                <?php if ($is_row_admin): ?>
                                                    <span
                                                        class="text-[10px] bg-amber-100 text-amber-700 px-1.5 py-0.5 rounded border border-amber-200 font-bold tracking-wide">PEPO
                                                        PROVINCIAL ADMINISTRATOR</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-xs text-gray-500"><?= htmlspecialchars($user['email']); ?></div>
                                        </div>
                                    </div>
                                </td>

                                <td class="px-6 py-4">
                                    <span class="text-sm font-medium text-gray-700">
                                        <?= getRoleDisplayLabel($user['role']); ?>
                                    </span>
                                </td>

                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <span
                                            class="h-2.5 w-2.5 rounded-full <?= $user['status'] === 'active' ? 'bg-emerald-500 shadow-sm shadow-emerald-200' : 'bg-gray-400' ?>"></span>
                                        <span
                                            class="text-sm font-medium <?= $user['status'] === 'active' ? 'text-emerald-700' : 'text-gray-500' ?>">
                                            <?= ucfirst($user['status']); ?>
                                        </span>
                                    </div>
                                </td>

                                <td class="px-6 py-4">
                                    <?php if ($is_role_locked): ?>
                                        <div
                                            class="flex items-center gap-2 text-gray-400 text-xs italic bg-gray-50 px-3 py-1.5 rounded-lg border border-gray-100 cursor-not-allowed">
                                            <i class="bi bi-lock-fill"></i>
                                            <?= $is_current_user ? 'Your Account' : 'Role Locked' ?>
                                        </div>
                                    <?php else: ?>
                                        <form method="POST" action="process.php" onchange="confirmRoleChange(this)">
                                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                                            <input type="hidden" name="user_id" value="<?= $user['id']; ?>">
                                            <input type="hidden" name="username"
                                                value="<?= htmlspecialchars($user['username']); ?>">
                                            <select name="update_role"
                                                class="form-select form-select-sm block w-full pl-3 pr-8 py-1.5 text-xs font-medium border-gray-200 focus:outline-none focus:ring-emerald-500 focus:border-emerald-500 rounded-lg text-gray-600 bg-gray-50 group-hover:bg-white transition-colors cursor-pointer">
                                                <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Maintenance Dept Admin</option>
                                                <option value="staff" <?= $user['role'] === 'staff' ? 'selected' : '' ?>>Maintenance Staff</option>
                                                <option value="supply" <?= $user['role'] === 'supply' ? 'selected' : '' ?>>Supply Dept Admin</option>
                                                <option value="user" <?= $user['role'] === 'user' ? 'selected' : '' ?>>Property Custodian</option>
                                            </select>
                                        </form>
                                    <?php endif; ?>
                                </td>

                                <td class="px-6 py-4">
                                    <?php if ($is_status_locked): ?>
                                        <div
                                            class="flex items-center gap-2 text-gray-400 text-xs italic bg-gray-50 px-3 py-1.5 rounded-lg border border-gray-100 cursor-not-allowed">
                                            <i class="bi bi-lock-fill"></i>
                                            <?= $is_current_user ? 'Your Account' : 'Status Locked' ?>
                                        </div>
                                    <?php else: ?>
                                        <form method="POST" action="process.php" onchange="confirmStatusChange(this)">
                                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                                            <input type="hidden" name="user_id" value="<?= $user['id']; ?>">
                                            <input type="hidden" name="username"
                                                value="<?= htmlspecialchars($user['username']); ?>">
                                            <select name="update_status"
                                                class="form-select form-select-sm block w-full pl-3 pr-8 py-1.5 text-xs font-medium border-gray-200 focus:outline-none focus:ring-emerald-500 focus:border-emerald-500 rounded-lg text-gray-600 bg-gray-50 group-hover:bg-white transition-colors cursor-pointer">
                                                <option value="active" <?= $user['status'] === 'active' ? 'selected' : '' ?>>Active
                                                </option>
                                                <option value="inactive" <?= $user['status'] === 'inactive' ? 'selected' : '' ?>>
                                                    Inactive</option>
                                            </select>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>

                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center text-gray-500">
                                <div class="flex flex-col items-center justify-center">
                                    <div class="h-16 w-16 bg-gray-50 rounded-full flex items-center justify-center mb-4">
                                        <i class="bi bi-search text-2xl text-gray-300"></i>
                                    </div>
                                    <p class="font-medium text-gray-900">No users found</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>


            </table>
        </div>
    </div>




    <!-- sign up request  modal -->

    <div class="modal fade" id="signupRequestsModal" tabindex="-1" aria-labelledby="signupRequestsModalLabel"
        aria-hidden="true" data-bs-backdrop="true" data-bs-keyboard="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content rounded-2xl border-0 shadow-2xl">
                <div class="modal-header bg-emerald-600 text-white border-0 px-6 py-4">
                    <div>
                        <h5 class="modal-title font-bold text-lg flex items-center gap-2" id="signupRequestsModalLabel">
                            <i class="bi bi-person-plus"></i> Sign-up Requests
                        </h5>
                        <p class="text-emerald-100 text-xs mt-1">
                            <?php if ($logged_in_location): ?>
                                Managing requests for <u><?= htmlspecialchars($logged_in_location); ?></u>
                            <?php else: ?>
                                Managing all pending requests
                            <?php endif; ?>
                        </p>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                        aria-label="Close"></button>
                </div>

                <div class="modal-body p-0">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="bg-gray-50 border-b border-gray-100">
                                    <th class="px-6 py-3 text-xs font-bold text-gray-500 uppercase">Candidate</th>
                                    <th class="px-6 py-3 text-xs font-bold text-gray-500 uppercase">Role Requested</th>
                                    <th class="px-6 py-3 text-xs font-bold text-gray-500 uppercase">Date</th>
                                    <th class="px-6 py-3 text-xs font-bold text-gray-500 uppercase text-right">Decisions
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50">
                                <?php if ($pendingCount > 0): ?>
                                    <?php while ($request = $requestResult->fetch_assoc()): ?>
                                        <tr class="hover:bg-gray-50 transition-colors">
                                            <td class="px-6 py-4">
                                                <div class="font-semibold text-gray-900">
                                                    <?= htmlspecialchars($request['username']); ?>
                                                </div>
                                                <div class="text-xs text-gray-500"><?= htmlspecialchars($request['email']); ?>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4">
                                                <span class="text-sm font-medium text-gray-700">
                                                    <?= getRoleDisplayLabel($request['requested_role']); ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 text-sm text-gray-500">
                                                <?= date('M d, Y', strtotime($request['requested_at'])); ?>
                                                <div class="text-xs text-gray-400">
                                                    <?= date('h:i A', strtotime($request['requested_at'])); ?>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 text-right">
                                                <form method="POST" action="process.php" class="flex justify-end gap-2">
                                                    <input type="hidden" name="csrf"
                                                        value="<?= htmlspecialchars($csrfToken) ?>">
                                                    <input type="hidden" name="signup_request_id"
                                                        value="<?= $request['id']; ?>">

                                                    <button type="submit" name="action" value="approve"
                                                        class="flex items-center gap-1 px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-xs font-bold transition-colors shadow-sm">
                                                        <i class="bi bi-check-lg"></i> Approve
                                                    </button>

                                                    <button type="submit" name="action" value="decline"
                                                        class="flex items-center gap-1 px-3 py-1.5 bg-white border border-red-200 text-red-600 hover:bg-red-50 rounded-lg text-xs font-bold transition-colors">
                                                        <i class="bi bi-x-lg"></i> Decline
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" class="px-6 py-10 text-center text-gray-400">
                                            <i class="bi bi-inbox text-3xl mb-2 block"></i>
                                            No pending requests found
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="modal-footer bg-gray-50 border-t border-gray-100 px-6 py-3">
                    <button type="button"
                        class="px-4 py-2 bg-white border border-gray-300 text-gray-700 rounded-lg text-sm font-medium hover:bg-gray-50 transition-colors"
                        data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>


</div>





<script>
    function confirmRoleChange(form) {
        const select = form.querySelector('select[name="update_role"]');
        const newRole = select.value;
        const username = form.querySelector('input[name="username"]').value;

        if (!confirm('Are you sure you want to change ' + username + "'s role to " + newRole + '?')) {
            select.value = select.dataset.previous || select.value;
            return;
        }
        form.submit();
    }

    function confirmStatusChange(form) {
        const select = form.querySelector('select[name="update_status"]');
        const newStatus = select.value;
        const username = form.querySelector('input[name="username"]').value;

        if (!confirm('Are you sure you want to set ' + username + "'s status to " + newStatus + '?')) {
            select.value = select.dataset.previous || select.value;
            return;
        }
        form.submit();
    }

    // Store previous value before change
    document.querySelectorAll('select[name="update_role"], select[name="update_status"]').forEach(select => {
        select.dataset.previous = select.value;
        select.addEventListener('focus', function () {
            this.dataset.previous = this.value;
        });
    });

    // Ensure modal works properly
    document.addEventListener('DOMContentLoaded', function () {
        const modalEl = document.getElementById('signupRequestsModal');
        if (modalEl) {
            // Clean up any existing backdrops on page load
            document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';

            modalEl.addEventListener('shown.bs.modal', function () {
                console.log('Signup Requests modal opened successfully');
            });

            modalEl.addEventListener('hidden.bs.modal', function () {
                // Force cleanup of backdrop
                setTimeout(() => {
                    document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
                    document.body.classList.remove('modal-open');
                    document.body.style.removeProperty('overflow');
                    document.body.style.removeProperty('padding-right');
                }, 100);
            });

            modalEl.addEventListener('show.bs.modal', function (e) {
                console.log('Opening signup requests modal');
            });
        }
    });
</script>