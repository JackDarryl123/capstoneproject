<?php
// Start session at the VERY TOP
session_name('app_session');
session_start();

// Set headers to prevent caching
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Include necessary files
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/session_helper.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    echo '<div class="alert alert-danger">Session expired. Please <a href="index.php?login">login again</a>.</div>';
    exit();
}

// Get the requested view
$view = $_GET['view'] ?? 'dashboard';

// Validate view
$allowedViews = ['dashboard', 'equipment', 'documents', 'maintenance', 'inventory', 'activities', 'report', 'user', 'superadmin_user'];
if (!in_array($view, $allowedViews, true)) {
    $view = '404';
}

// Load the appropriate view file
$viewFile = "side_$view.php";
if ($view === 'superadmin_user') {
    $viewFile = "side_superadmin_user.php";
}

// Check if view file exists
if (file_exists($viewFile)) {
    // Include the view file
    ob_start();
    include $viewFile;
    $content = ob_get_clean();
    echo $content;
} else if ($view === '404') {
    echo '<div class="p-8">
            <h2 class="fw-bold mb-4">Page Not Found</h2>
            <p>The view you requested does not exist.</p>
          </div>';
} else {
    echo '<div class="p-8">
            <div class="alert alert-danger">
                <h4>Error Loading Content</h4>
                <p>The requested view file was not found.</p>
            </div>
          </div>';
}

// Close database connection
$mysqli->close();
?>