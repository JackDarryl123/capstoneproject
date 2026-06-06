<?php
// load_content.php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/session_helper.php';
start_user_session();

// Database connection removed here because included files handle their own connections.

// Clear inventory cache for fresh data (cache-busting)
if (isset($_GET['view']) && strpos($_GET['view'], 'inventory') !== false) {
    $cacheDir = __DIR__ . '/../cache/';
    if (is_dir($cacheDir)) {
        array_map('unlink', glob($cacheDir . "inventory_*.cache"));
    }
}

// Allowed views
$allowedViews = ['dashboard', 'equipment', 'documents', 'report', 'inventory'];
$view = $_GET['view'] ?? 'dashboard';

// Extract base view name (remove cache-busting params like &_=timestamp)
$viewBase = explode('&', $view)[0];
if (!in_array($viewBase, $allowedViews, true)) {
    $viewBase = '404';
}

// Include the corresponding content file
$contentFile = __DIR__ . "/side_{$viewBase}.php";
if (file_exists($contentFile)) {
    include $contentFile;
} else {
    // Fallback 404 content
    echo '<div class="card shadow-sm p-5 text-center border-0 rounded-4">';
    echo '<h2 class="fw-bold mb-3">Page Not Found</h2>';
    echo '<p class="text-muted mb-4">The page you requested does not exist.</p>';
    echo '<a href="#" onclick="loadContent(\'dashboard\'); return false;" class="btn btn-success">Go to Dashboard</a>';
    echo '</div>';
}

?>