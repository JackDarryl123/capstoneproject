<?php
// --- 1. SETUP & SAFETY CHECKS ---
// Since this file is included in admin_dashboard.php, $mysqli and $_SESSION already exist.
// We only add a fallback just in case.
if (!isset($mysqli)) {
    include 'config.php';
    if (session_status() === PHP_SESSION_NONE)
        session_start();
}

// --- 2. GET CURRENT USER DATA ---
$user_id = $_SESSION['user_id'];

// Fetch admin status and location
// Note: We use the existing $mysqli connection
$checkAdminStmt = $mysqli->prepare("SELECT is_admin, location FROM users WHERE id = ?");
$checkAdminStmt->bind_param("i", $user_id);
$checkAdminStmt->execute();
$adminResult = $checkAdminStmt->get_result();
$currentUserData = $adminResult->fetch_assoc();

$is_current_user_admin = ($currentUserData['is_admin'] == 1);

// Determine valid location
$logged_in_location = isset($_SESSION['user_location']) ? $_SESSION['user_location'] : $currentUserData['location'];

// --- 3. HANDLE ADMIN LOCATION CHANGE (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_admin_location'])) {
    if (!$is_current_user_admin) {
        die("Unauthorized access.");
    }

    $new_location = $_POST['admin_location'];

    $updateLocStmt = $mysqli->prepare("UPDATE users SET location = ? WHERE id = ?");
    $updateLocStmt->bind_param("si", $new_location, $user_id);

    if ($updateLocStmt->execute()) {
        $_SESSION['user_location'] = $new_location;
        // CORRECT REDIRECT: Reload the current view in the dashboard
        echo "<script>window.location.href='?view=user';</script>";
        exit();
    }
}

// --- 4. FETCH DATA LOGIC ---

// A. Fetch Signup Requests
if ($logged_in_location) {
    $requestQuery = "SELECT * FROM signup_requests WHERE status = 'pending' AND designation = ? ORDER BY requested_at DESC";
    $requestStmt = $mysqli->prepare($requestQuery);
    if ($requestStmt) {
        $requestStmt->bind_param("s", $logged_in_location);
        $requestStmt->execute();
        $requestResult = $requestStmt->get_result();
    } else {
        $requestResult = $mysqli->query("SELECT * FROM signup_requests WHERE status = 'pending' ORDER BY requested_at DESC");
    }
} else {
    $requestResult = $mysqli->query("SELECT * FROM signup_requests WHERE status = 'pending' ORDER BY requested_at DESC");
}

// B. Handle Filters
$selected_role = isset($_GET['role']) ? $_GET['role'] : 'all';
$selected_status = isset($_GET['status']) ? $_GET['status'] : 'all';

$whereConditions = [];

if ($logged_in_location) {
    $whereConditions[] = "location = '" . $mysqli->real_escape_string($logged_in_location) . "'";
}
if ($selected_role !== 'all') {
    $whereConditions[] = "role = '" . $mysqli->real_escape_string($selected_role) . "'";
}
if ($selected_status !== 'all') {
    $whereConditions[] = "status = '" . $mysqli->real_escape_string($selected_status) . "'";
}

if (count($whereConditions) > 0) {
    $userQuery = "SELECT * FROM users WHERE " . implode(' AND ', $whereConditions);
} else {
    $userQuery = "SELECT * FROM users";
}

$userResult = $mysqli->query($userQuery) or die($mysqli->error);
$pendingCount = $requestResult->num_rows;

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
include_once 'profile_modal.php';
?>



<div class="max-w-[1600px] mx-auto p-6 bg-gray-50 min-h-screen font-sans">

    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-8">
        <div>
            <h2 class="text-2xl font-bold text-gray-800 tracking-tight flex items-center gap-3">
                <span class="bg-emerald-100 text-emerald-600 p-2 rounded-lg">
                    <i class="bi bi-people-fill text-xl"></i>
                </span>
                User Management
            </h2>

            <?php if ($is_current_user_admin): ?>
                <form method="POST" class="mt-2 flex items-center gap-2">
                    <label class="text-xs font-bold text-gray-500 uppercase">My Location:</label>
                    <div class="relative">
                        <input type="hidden" name="update_admin_location" value="1">
                        <select name="admin_location" onchange="this.form.submit()"
                            class="appearance-none bg-white border border-emerald-200 text-emerald-700 text-sm font-bold py-1.5 pl-3 pr-8 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 cursor-pointer hover:bg-emerald-50 transition-colors">
                            <option value="Mamburao" <?= $logged_in_location === 'Mamburao' ? 'selected' : '' ?>>Mamburao
                            </option>
                            <option value="Sablayan" <?= $logged_in_location === 'Sablayan' ? 'selected' : '' ?>>Sablayan
                            </option>
                            <option value="San Jose" <?= $logged_in_location === 'San Jose' ? 'selected' : '' ?>>San Jose
                            </option>
                            <option value="Lubang" <?= $logged_in_location === 'Lubang' ? 'selected' : '' ?>>Lubang</option>
                        </select>
                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-emerald-600">
                            <i class="bi bi-chevron-down text-xs"></i>
                        </div>
                    </div>
                </form>
            <?php else: ?>
                <p class="text-gray-500 text-sm mt-1 ml-14">Manage accounts, roles, and access permissions.</p>
            <?php endif; ?>
        </div>

        <button type="button"
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
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 mb-6">

            <div class="flex flex-col lg:flex-row justify-between items-center gap-4">

                <div class="flex items-center gap-2 overflow-x-auto pb-2 lg:pb-0 w-full lg:w-auto min-h-[38px]">

                    <span class="text-xs font-bold text-gray-400 uppercase tracking-wider mr-1">Maintenance
                        Department:</span>

                    <?php if (!$is_filter_active && !$logged_in_location): ?>
                        <span
                            class="text-gray-400 text-sm italic bg-gray-50 px-3 py-1.5 rounded-lg border border-gray-100 border-dashed">
                            None active
                        </span>
                    <?php endif; ?>

                    <?php if ($logged_in_location): ?>
                        <div
                            class="group flex items-center gap-2 px-3 py-1.5 bg-blue-50 text-blue-700 rounded-lg text-xs font-bold border border-blue-100 transition-all hover:shadow-sm">
                            <i class="bi bi-geo-alt-fill"></i>
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
                            <span>Role: <?= ucfirst($selected_role); ?></span>
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
                            class="group flex items-center gap-2 px-4 py-2 bg-white border border-red-100 text-gray-500 rounded-lg text-sm font-medium hover:bg-red-50 hover:text-red-600 hover:border-red-200 hover:shadow-md transition-all duration-200 mr-2">
                            <i class="bi bi-funnel-x-fill text-red-400 group-hover:text-red-600 transition-colors"></i>
                            <span>Clear Filters</span>
                        </a>
                        <div class="h-6 w-px bg-gray-200 mx-1"></div> <?php endif; ?>

                    <div class="dropdown">
                        <button
                            class="btn btn-outline-secondary dropdown-toggle flex items-center gap-2 px-4 py-2 bg-white border border-gray-200 text-gray-700 rounded-lg hover:bg-gray-50 hover:border-emerald-300 hover:text-emerald-700 text-sm font-medium transition-all shadow-sm"
                            type="button" id="roleFilterDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <i
                                class="bi bi-person-badge <?= $selected_role !== 'all' ? 'text-emerald-600' : 'text-gray-400' ?>"></i>
                            <?= ucfirst($selected_role === 'all' ? 'Role' : $selected_role); ?>
                        </button>
                        <ul class="dropdown-menu shadow-xl border-0 rounded-xl mt-2 p-1 w-48 animate-fade-in-down"
                            aria-labelledby="roleFilterDropdown">
                            <li><a class="dropdown-item rounded-lg px-3 py-2 text-sm font-medium text-gray-600 hover:bg-emerald-50 hover:text-emerald-700"
                                    href="<?= getFilterUrl('role', 'all'); ?>">All Roles</a></li>
                            <li>
                                <hr class="dropdown-divider my-1 border-gray-100">
                            </li>
                            <li><a class="dropdown-item rounded-lg px-3 py-2 text-sm hover:bg-emerald-50 hover:text-emerald-700"
                                    href="<?= getFilterUrl('role', 'admin'); ?>">Admin</a></li>
                            <li><a class="dropdown-item rounded-lg px-3 py-2 text-sm hover:bg-emerald-50 hover:text-emerald-700"
                                    href="<?= getFilterUrl('role', 'staff'); ?>">Staff</a></li>
                            <li><a class="dropdown-item rounded-lg px-3 py-2 text-sm hover:bg-emerald-50 hover:text-emerald-700"
                                    href="<?= getFilterUrl('role', 'supply'); ?>">Supply</a></li>
                            <li><a class="dropdown-item rounded-lg px-3 py-2 text-sm hover:bg-emerald-50 hover:text-emerald-700"
                                    href="<?= getFilterUrl('role', 'user'); ?>">User</a></li>
                        </ul>
                    </div>

                    <div class="dropdown">
                        <button
                            class="btn btn-outline-secondary dropdown-toggle flex items-center gap-2 px-4 py-2 bg-white border border-gray-200 text-gray-700 rounded-lg hover:bg-gray-50 hover:border-purple-300 hover:text-purple-700 text-sm font-medium transition-all shadow-sm"
                            type="button" id="statusFilterDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <i
                                class="bi bi-toggle-on <?= $selected_status !== 'all' ? 'text-purple-600' : 'text-gray-400' ?>"></i>
                            <?= ucfirst($selected_status === 'all' ? 'Status' : $selected_status); ?>
                        </button>
                        <ul class="dropdown-menu shadow-xl border-0 rounded-xl mt-2 p-1 w-48 animate-fade-in-down"
                            aria-labelledby="statusFilterDropdown">
                            <li><a class="dropdown-item rounded-lg px-3 py-2 text-sm font-medium text-gray-600 hover:bg-purple-50 hover:text-purple-700"
                                    href="<?= getFilterUrl('status', 'all'); ?>">All Status</a></li>
                            <li>
                                <hr class="dropdown-divider my-1 border-gray-100">
                            </li>
                            <li><a class="dropdown-item rounded-lg px-3 py-2 text-sm hover:bg-purple-50 hover:text-purple-700"
                                    href="<?= getFilterUrl('status', 'active'); ?>">Active</a></li>
                            <li><a class="dropdown-item rounded-lg px-3 py-2 text-sm hover:bg-purple-50 hover:text-purple-700"
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

        <div class="mt-4 pt-4 border-t border-gray-100 text-xs text-gray-500">
            Showing <strong class="text-emerald-700"><?= $userResult->num_rows; ?></strong> results based on your
            current selection.
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

                            // specific flag to lock editing if it is a Master Admin OR it is Yourself
                            $is_locked = ($is_row_admin || $is_current_user);
                            ?>

                            <tr
                                class="hover:bg-gray-50/60 transition-colors group <?= $is_current_user ? 'bg-emerald-50/30' : '' ?>">

                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        <div
                                            class="h-10 w-10 rounded-full <?= $is_row_admin ? 'bg-amber-100 text-amber-600 ring-2 ring-amber-200' : 'bg-emerald-100 text-emerald-600' ?> flex items-center justify-center font-bold text-sm shadow-sm">
                                            <?php if ($is_row_admin): ?>
                                                <i class="bi bi-shield-lock-fill"></i>
                                            <?php else: ?>
                                                <?= strtoupper(substr($user['username'], 0, 2)); ?>
                                            <?php endif; ?>
                                        </div>

                                        <div>
                                            <div class="font-semibold text-gray-900 flex items-center gap-2">
                                                <?= htmlspecialchars($user['username']); ?>

                                                <?php if ($is_row_admin): ?>
                                                    <span
                                                        class="text-[10px] bg-amber-100 text-amber-700 px-1.5 py-0.5 rounded border border-amber-200 font-bold tracking-wide">PROVINCIAL</span>
                                                <?php endif; ?>

                                                <?php if ($is_current_user): ?>
                                                    <span
                                                        class="text-[10px] bg-blue-100 text-blue-700 px-1.5 py-0.5 rounded border border-blue-200 font-bold tracking-wide">YOU</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-xs text-gray-500"><?= htmlspecialchars($user['email']); ?></div>
                                        </div>
                                    </div>
                                </td>

                                <td class="px-6 py-4">
                                    <?php
                                    $roleColors = [
                                        'admin' => 'bg-purple-100 text-purple-700 border-purple-200',
                                        'staff' => 'bg-blue-100 text-blue-700 border-blue-200',
                                        'supply' => 'bg-orange-100 text-orange-700 border-orange-200',
                                        'user' => 'bg-gray-100 text-gray-600 border-gray-200'
                                    ];
                                    $roleClass = $roleColors[$user['role']] ?? $roleColors['user'];
                                    ?>
                                    <span class="px-2.5 py-1 rounded-md text-xs font-bold uppercase border <?= $roleClass ?>">
                                        <?= ucfirst($user['role']); ?>
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
                                    <?php if ($is_locked): ?>
                                        <div
                                            class="flex items-center gap-2 text-gray-400 text-xs italic bg-gray-50 px-3 py-1.5 rounded-lg border border-gray-100 cursor-not-allowed">
                                            <i class="bi bi-lock-fill"></i>
                                            <?= $is_current_user ? 'Your Account' : 'Role Locked' ?>
                                        </div>
                                    <?php else: ?>
                                        <form method="POST" action="process.php">
                                            <input type="hidden" name="user_id" value="<?= $user['id']; ?>">
                                            <select name="update_role" onchange="this.form.submit()"
                                                class="form-select form-select-sm block w-full pl-3 pr-8 py-1.5 text-xs font-medium border-gray-200 focus:outline-none focus:ring-emerald-500 focus:border-emerald-500 rounded-lg text-gray-600 bg-gray-50 group-hover:bg-white transition-colors cursor-pointer">
                                                <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                                                <option value="staff" <?= $user['role'] === 'staff' ? 'selected' : '' ?>>Staff</option>
                                                <option value="supply" <?= $user['role'] === 'supply' ? 'selected' : '' ?>>Supply
                                                </option>
                                                <option value="user" <?= $user['role'] === 'user' ? 'selected' : '' ?>>User</option>
                                            </select>
                                        </form>
                                    <?php endif; ?>
                                </td>

                                <td class="px-6 py-4">
                                    <?php if ($is_locked): ?>
                                        <div
                                            class="flex items-center gap-2 text-gray-400 text-xs italic bg-gray-50 px-3 py-1.5 rounded-lg border border-gray-100 cursor-not-allowed">
                                            <i class="bi bi-lock-fill"></i>
                                            <?= $is_current_user ? 'Your Account' : 'Status Locked' ?>
                                        </div>
                                    <?php else: ?>
                                        <form method="POST" action="process.php">
                                            <input type="hidden" name="user_id" value="<?= $user['id']; ?>">
                                            <select name="update_status" onchange="this.form.submit()"
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
</div>

<div class="modal fade" id="signupRequestsModal" tabindex="-1" aria-labelledby="signupRequestsModalLabel"
    aria-hidden="true">
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
                                            <div class="text-xs text-gray-500"><?= htmlspecialchars($request['email']); ?></div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <span
                                                class="px-2 py-1 bg-blue-50 text-blue-700 border border-blue-100 rounded text-xs font-bold uppercase">
                                                <?= ucfirst($request['requested_role']); ?>
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
                                                <input type="hidden" name="signup_request_id" value="<?= $request['id']; ?>">

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