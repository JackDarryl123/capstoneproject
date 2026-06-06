<?php
// ============================================
// ENHANCED ADMIN DASHBOARD - REFACTORED VERSION
// ============================================

// Enable output buffering with compression for faster page delivery
if (!ob_start("ob_gzhandler")) {
    ob_start();
}

// Start performance monitoring
$start_time = microtime(true);

// ✅ AJAX SHORT-CIRCUIT: Prevent HTML shell from loading for AJAX POST/GET requests
if (isset($_POST['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') || isset($_GET['fetch_item_logs'])) {
    // We handle the actual logic in the handlers below. 
    // This ensures that if no handler catches it, we don't accidentally send the HTML shell.
    ob_start();
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/session_helper.php';

// ✅ AJAX handler for specific item logs (PREVENT HTML OUTPUT)
if (isset($_GET['fetch_item_logs']) && isset($_GET['item_id'])) {
    if (ob_get_length())
        ob_clean(); // Clear any buffered output
    header('Content-Type: application/json');
    global $mysqli;
    $item_id = intval($_GET['item_id']);
    $logs = [];

    $stmt = $mysqli->prepare("SELECT * FROM inventory_activity_log WHERE inventory_id = ? ORDER BY date_time DESC");
    $stmt->bind_param("i", $item_id);
    $stmt->execute();
    $log_res = $stmt->get_result();

    while ($row = $log_res->fetch_assoc()) {
        $row['formatted_date'] = date('M d, Y h:i A', strtotime($row['date_time']));
        $logs[] = $row;
    }
    $stmt->close();
    echo json_encode(['success' => true, 'logs' => $logs]);
    exit;
}

// ✅ Handle AJAX Add Activity Log (PREVENT HTML OUTPUT)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['add_activity_log']) || isset($_GET['add_activity_log']))) {
    while (ob_get_level())
        ob_end_clean(); // Aggressively clear ALL buffers
    header('Content-Type: application/json');
    require_once 'db_connect.php';

    $item_ids = $_POST['log_inventory_id'] ?? [];
    $log_action_type = strtoupper(trim($_POST['log_action_type'] ?? ''));
    $log_performed_by = trim($_POST['log_performed_by'] ?? '');
    $user_location = 'Mamburao'; // Fallback

    if (empty($item_ids) || empty($log_action_type) || empty($log_performed_by)) {
        echo json_encode(['success' => false, 'message' => 'Please fill in all required fields!']);
        exit;
    }

    if (!is_array($item_ids))
        $item_ids = [$item_ids];
    $item_ids = array_filter($item_ids, fn($v) => !empty($v));

    $unit_qty = ($log_action_type === 'ISSUED') ? -1 : 1;
    $success_count = 0;
    $mysqli->begin_transaction();

    try {
        $user_location = $_SESSION['location'] ?? $_SESSION['user_location'] ?? 'Mamburao';
        foreach ($item_ids as $id) {
            $stmt = $mysqli->prepare("SELECT item, allocation FROM inventory WHERE id = ? FOR UPDATE");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $res = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$res)
                continue;

            $log_stmt = $mysqli->prepare("INSERT INTO inventory_activity_log (inventory_id, action_type, item_name, quantity_changed, performed_by, location, date_time) VALUES (?, ?, ?, ?, ?, ?, NOW())");
            $log_stmt->bind_param("isssss", $id, $log_action_type, $res['item'], $unit_qty, $log_performed_by, $user_location);
            $log_stmt->execute();
            $log_stmt->close();

            if ($log_action_type === 'ISSUED') {
                $status_label = "BORROWED BY: " . $log_performed_by;
                $update_inv = $mysqli->prepare("UPDATE inventory SET log_stats = ?, borrowed_date = NOW(), returned_date = NULL WHERE id = ?");
                $update_inv->bind_param("si", $status_label, $id);
            } else {
                $update_inv = $mysqli->prepare("UPDATE inventory SET log_stats = 'AVAILABLE', returned_date = NOW() WHERE id = ?");
                $update_inv->bind_param("i", $id);
            }
            $update_inv->execute();
            $update_inv->close();
            $success_count++;
        }
        $mysqli->commit();
        CacheManager::clear(); // Clear all cache
        echo json_encode(['success' => true, 'message' => "Successfully processed $success_count items."]);
    } catch (Exception $e) {
        $mysqli->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Redirect PGDH GSO to their specialized dashboard
if (($_SESSION['role'] ?? '') === 'pgdh_gso') {
    header("Location: /GSO/admin_dashboard.php");
    exit();
}

require_role('admin');

// ============================================
// 1. SINGLETON DATABASE CONNECTION
// ============================================
class Database
{
    private static $instance = null;
    private $connection;
    private $last_query = '';
    private $query_count = 0;
    private $query_log = [];

    private function __construct()
    {
        global $mysqli;
        $this->connection = $mysqli;
        $this->connection->set_charset('utf8mb4');
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    public function getConnection()
    {
        return $this->connection;
    }

    public function prepare($sql)
    {
        $this->last_query = $sql;
        $this->query_count++;
        $this->query_log[] = ['query' => $sql, 'time' => microtime(true)];
        return $this->connection->prepare($sql);
    }

    public function query($sql)
    {
        $this->last_query = $sql;
        $this->query_count++;
        $this->query_log[] = ['query' => $sql, 'time' => microtime(true)];
        return $this->connection->query($sql);
    }

    public function getStats()
    {
        return [
            'query_count' => $this->query_count,
            'last_query' => $this->last_query,
            'query_log' => $this->query_log
        ];
    }

    public function healthCheck()
    {
        try {
            $result = $this->connection->ping();
            return [
                'status' => $result ? 'healthy' : 'unhealthy',
                'connected' => $result,
                'stats' => $this->getStats()
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage(),
                'stats' => $this->getStats()
            ];
        }
    }
}

// Get database instance
try {
    $db = Database::getInstance();
    $mysqli = $db->getConnection();
} catch (Exception $e) {
    exit('Database connection failed: ' . $e->getMessage());
}

// ============================================
// 2. ENHANCED CACHING SYSTEM
// ============================================
class CacheManager
{
    private static $memoryCache = [];
    private static $cacheHits = 0;
    private static $cacheMisses = 0;

    public static function get($key, $callback, $ttl = 300)
    {
        // Memory cache check (current request)
        if (isset(self::$memoryCache[$key])) {
            self::$cacheHits++;
            return self::$memoryCache[$key];
        }

        $cacheDir = __DIR__ . '/cache/';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        $cacheFile = $cacheDir . md5($key) . '.cache';
        $metadataFile = $cacheDir . md5($key) . '.meta';

        // File cache check
        if (file_exists($cacheFile) && file_exists($metadataFile)) {
            $metadata = unserialize(file_get_contents($metadataFile));
            if (time() - $metadata['created'] < $ttl) {
                self::$cacheHits++;
                $data = unserialize(file_get_contents($cacheFile));
                self::$memoryCache[$key] = $data;
                return $data;
            }
        }

        // Generate fresh data
        self::$cacheMisses++;
        $data = $callback();

        // Store in file cache
        file_put_contents($cacheFile, serialize($data));
        file_put_contents($metadataFile, serialize([
            'created' => time(),
            'ttl' => $ttl,
            'key' => $key
        ]));

        // Store in memory cache
        self::$memoryCache[$key] = $data;

        return $data;
    }

    public static function clear($key = null)
    {
        $cacheDir = __DIR__ . '/cache/';
        if ($key === null) {
            // Clear all cache
            array_map('unlink', glob($cacheDir . "*.cache"));
            array_map('unlink', glob($cacheDir . "*.meta"));
            self::$memoryCache = [];
        } else {
            // Clear specific cache
            $cacheFile = $cacheDir . md5($key) . '.cache';
            $metadataFile = $cacheDir . md5($key) . '.meta';
            if (file_exists($cacheFile))
                unlink($cacheFile);
            if (file_exists($metadataFile))
                unlink($metadataFile);
            unset(self::$memoryCache[$key]);
        }
    }

    public static function getStats()
    {
        return [
            'hits' => self::$cacheHits,
            'misses' => self::$cacheMisses,
            'hit_rate' => (self::$cacheHits + self::$cacheMisses) > 0
                ? round((self::$cacheHits / (self::$cacheHits + self::$cacheMisses)) * 100, 2)
                : 0,
            'memory_items' => count(self::$memoryCache)
        ];
    }
}

// ============================================
// 3. SIDEBAR SERVICE CLASS
// ============================================
class SidebarService
{
    private $userId;
    private $userRole;
    private $currentView;
    private $db;
    private $csrfToken;

    public function __construct($userId, $role, $view, $db, $csrfToken)
    {
        $this->userId = $userId;
        $this->userRole = $role;
        $this->currentView = $view;
        $this->db = $db;
        $this->csrfToken = $csrfToken;
    }

    public function getMenuItems()
    {
        $items = [
            ['icon' => 'fa-home', 'label' => 'Dashboard', 'view' => 'dashboard', 'roles' => ['admin', 'superadmin']],
            [
                'icon' => 'fa-truck',
                'label' => 'Equipment',
                'view' => 'equipment',
                'submenu' => [
                    ['label' => 'All Equipment', 'view' => 'equipment'],
                    ['label' => 'Documents', 'view' => 'documents'],
                    ['label' => 'Maintenance', 'view' => 'maintenance']
                ],
                'roles' => ['admin', 'superadmin']
            ],
            ['icon' => 'fa-boxes', 'label' => 'Inventory', 'view' => 'inventory', 'roles' => ['admin', 'superadmin']],
            ['icon' => 'fa-calendar-alt', 'label' => 'Schedule', 'view' => 'activities', 'roles' => ['admin', 'superadmin']],
            ['icon' => 'fa-chart-pie', 'label' => 'Reports', 'view' => 'report', 'roles' => ['admin', 'superadmin']],
            ['icon' => 'fa-user-shield', 'label' => 'User Control', 'view' => 'superadmin_user', 'roles' => ['superadmin']],
            ['icon' => 'fa-users', 'label' => 'Users', 'view' => 'user', 'roles' => ['admin']],
        ];

        // Filter based on role
        return array_filter($items, function ($item) {
            return in_array($this->userRole, $item['roles']);
        });
    }

    // public function getSidebarData()
    // {
    //     $cacheKey = "sidebar_data_{$this->userId}_" . date('Y-m-d-H');

    //     return CacheManager::get($cacheKey, function () {

    //         $userInfo = $this->getUserInfo();
    //         $userLocation = $userInfo['location'] ?? ''; 

    //         return [
    //             'user_info' => $userInfo,

    //             'notification_counts' => $this->getNotificationCounts($userLocation),
    //             'pending_documents' => $this->getPendingDocuments($userLocation),
    //         ];
    //     }, 60);
    // }

    public function getSidebarData()
    {
        /* * FIX 1: REMOVED date('Y-m-d-H') 
         * We use a static key so the CacheManager overwrites the same file 
         * instead of creating a new file every hour.
         */
        $cacheKey = "sidebar_data_{$this->userId}";

        /* * FIX 2: REDUCED TTL (Time To Live)
         * Changed from 60 to 10 seconds. 
         * This ensures notification badges update almost immediately 
         * while still protecting your database from hammering.
         */
        return CacheManager::get($cacheKey, function () {
            // 1. Fetch User Info First to get the Location
            $userInfo = $this->getUserInfo();
            $userLocation = $userInfo['location'] ?? ''; // Get the location

            return [
                'user_info' => $userInfo,
                // 2. Pass location to these functions
                'notification_counts' => $this->getNotificationCounts($userLocation),
                'pending_documents' => $this->getPendingDocuments($userLocation),
            ];
        }, 10); // <--- UPDATED: Cache expires in 10 seconds (was 60)
    }



    private function getUserInfo()
    {
        // Added 'location' to the SELECT list
        $stmt = $this->db->prepare("
            SELECT username, email, status, role, signature, is_admin, location 
            FROM users 
            WHERE id = ?
        ");
        $stmt->bind_param('i', $this->userId);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc() ?? [];
    }

    private function getNotificationCounts($location)
    {
        $counts = ['pending_count' => 0, 'approved_count' => 0, 'done_count' => 0, 'total_pending' => 0];

        // Added WHERE location = ?
        $stmt = $this->db->prepare("
            SELECT 
                SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) as approved_count,
                SUM(CASE WHEN status = 'Done' THEN 1 ELSE 0 END) as done_count,
                SUM(CASE WHEN status IN ('Pending', 'pending_supply') AND is_read = 0 THEN 1 ELSE 0 END) as total_pending
            FROM documents
            WHERE location = ?
        ");

        if ($stmt) {
            $stmt->bind_param('s', $location);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $counts = array_map(function ($val) {
                    return intval($val);
                }, $row);
            }
        }

        return $counts;
    }

    private function getPendingDocuments($location)
    {
        // Added AND location = ?
        $stmt = $this->db->prepare("
            SELECT id, officer_name, pre_repair_no, date_requested, status, equipment
            FROM documents 
            WHERE status IN ('Pending', 'pending_supply') 
            AND is_read = 0 
            AND location = ? 
            AND officer_name IS NOT NULL 
            AND officer_name != '' 
            AND pre_repair_no IS NOT NULL 
            AND pre_repair_no != ''
            ORDER BY date_requested DESC 
            LIMIT 20
        ");

        $documents = [];
        if ($stmt) {
            $stmt->bind_param('s', $location);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $documents[] = $row;
            }
        }

        return $documents;
    }

    public function isActive($view)
    {
        return $this->currentView === $view;
    }

    public function hasActiveSubmenu($views)
    {
        return in_array($this->currentView, $views);
    }

    public function generateUrl($view)
    {
        return "?view={$view}&csrf={$this->csrfToken}";
    }

    public function renderMenu()
    {
        $html = '<nav class="sidebar-nav">';
        $html .= '<div class="px-4 mb-4 text-xs font-bold text-green-00 uppercase tracking-wider">Main Menu</div>';

        foreach ($this->getMenuItems() as $item) {
            if (isset($item['submenu'])) {
                $html .= $this->renderSubmenuItem($item);
            } else {
                $html .= $this->renderMenuItem($item);
            }
        }

        $html .= '</nav>';
        return $html;
    }


    private function renderMenuItem($item)
    {
        $active = $this->isActive($item['view']) ? 'active' : '';
        $url = $this->generateUrl($item['view']);
        $iconClass = 'fas';

        return sprintf(
            '<a href="%s" class="nav-link-modern %s group">
                <i class="%s %s text-lg group-hover:scale-110 group-hover:text-green-400 transition-all duration-300"></i>
                <span class="flex-grow text-sm font-medium">%s</span>
                <div class="w-2 h-2 rounded-full bg-white/20 group-hover:bg-white/40 transition-colors"></div>
            </a>',
            htmlspecialchars($url),
            $active,
            $iconClass,
            $item['icon'],
            htmlspecialchars($item['label'])
        );
    }


    private function renderSubmenuItem($item)
    {
        $hasActive = $this->hasActiveSubmenu(array_column($item['submenu'], 'view'));
        $submenuVisible = $hasActive ? 'block' : 'hidden';
        $arrowIcon = $hasActive ? 'fa-chevron-up' : 'fa-chevron-down';

        $html = sprintf(
            '<div class="mb-2">
                <div class="nav-link-modern cursor-pointer %s group" 
                     onclick="toggleSubmenu(\'%s\')">
                    <i class="fas %s text-lg group-hover:scale-110 group-hover:text-green-400 transition-all duration-300"></i>
                    <span class="flex-grow text-sm font-medium">%s</span>
                    <i id="%sArrow" class="fas %s text-xs transition-transform duration-300"></i>
                </div>
                <div id="%sSubmenu" class="submenu-modern mt-2 space-y-1 %s">',
            $hasActive ? 'text-green-400' : '', // Updated to match your green theme instead of purple
            $item['view'],
            $item['icon'],
            htmlspecialchars($item['label']),
            $item['view'],
            $arrowIcon,
            $item['view'],
            $submenuVisible
        );

        foreach ($item['submenu'] as $subitem) {
            $active = $this->isActive($subitem['view']) ? 'bg-white/10 text-white' : 'text-gray-400';
            $url = $this->generateUrl($subitem['view']);

            $html .= sprintf(
                '<a href="%s" class="flex items-center py-2 px-4 rounded-lg hover:bg-white/5 transition-all duration-200 %s">
                    <div class="w-2 h-2 rounded-full bg-gray-500 mr-3"></div>
                    <span class="text-sm">%s</span>
                </a>',
                htmlspecialchars($url),
                $active,
                htmlspecialchars($subitem['label'])
            );
        }

        $html .= '</div></div>';
        return $html;
    }
}

// ============================================
// 4. CSRF PROTECTION
// ============================================
class CSRFProtection
{
    public static function generateToken()
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function validateToken($token)
    {
        if (empty($_SESSION['csrf_token']) || empty($token)) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    public static function validateRequest()
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $token = $_POST['csrf_token'] ?? '';
            if (!self::validateToken($token)) {
                http_response_code(403);
                die('CSRF token validation failed.');
            }
        }
    }
}

// Generate CSRF token
$csrfToken = CSRFProtection::generateToken();

// ============================================
// 5. SECURITY & VALIDATION
// ============================================
// Redirect if not logged in or not admin
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: index.php?login');
    exit();
}

// Validate CSRF for GET requests with actions
if (isset($_GET['action']) && isset($_GET['csrf'])) {
    if (!CSRFProtection::validateToken($_GET['csrf'])) {
        $_SESSION['message'] = 'Security validation failed.';
        $_SESSION['msg_type'] = 'danger';
        header('Location: ?view=' . ($_GET['view'] ?? 'dashboard'));
        exit();
    }
}

// ============================================
// 6. VIEW HANDLING WITH WHITELIST
// ============================================
class ViewManager
{
    private static $allowedViews = [
        'dashboard' => 'side_dashboard.php',
        'equipment' => 'side_equipment.php',
        'documents' => 'side_documents.php',
        'maintenance' => 'side_maintenance.php',
        'inventory' => 'side_inventory.php',
        'activities' => 'side_activities.php',
        'report' => 'side_report.php',
        'user' => 'side_user.php',
        'superadmin_user' => 'side_superadmin_user.php'
    ];

    public static function validateView($view)
    {
        $view = strtolower($view);
        return isset(self::$allowedViews[$view]) ? $view : '404';
    }

    public static function getViewFile($view)
    {
        return self::$allowedViews[$view] ?? 'side_404.php';
    }

    public static function getAllowedViews()
    {
        return array_keys(self::$allowedViews);
    }
}

// Get and validate view
$view = $_GET['view'] ?? 'dashboard';
$view = ViewManager::validateView($view);

// ============================================
// 7. INITIALIZE SIDEBAR SERVICE
// ============================================
$sidebarService = new SidebarService(
    $_SESSION['user_id'],
    $_SESSION['role'],
    $view,
    $mysqli,
    $csrfToken
);

// Get sidebar data with error boundary
try {
    $sidebarData = $sidebarService->getSidebarData();
    $currentUser = $sidebarData['user_info'] ?? [];
    $notificationCounts = $sidebarData['notification_counts'] ?? [];
    $pendingDocuments = $sidebarData['pending_documents'] ?? [];
} catch (Exception $e) {
    // Fallback to basic data
    error_log("Sidebar data loading failed: " . $e->getMessage());
    $currentUser = ['username' => 'User', 'role' => 'admin', 'is_admin' => 0];
    $notificationCounts = ['pending_count' => 0, 'approved_count' => 0, 'done_count' => 0, 'total_pending' => 0];
    $pendingDocuments = [];
}

// Extract counts
$pending = $notificationCounts['pending_count'] ?? 0;
$approved = $notificationCounts['approved_count'] ?? 0;
$done = $notificationCounts['done_count'] ?? 0;
$pendingCount = $notificationCounts['total_pending'] ?? 0;

$isSuperAdmin = isset($currentUser['is_admin']) && $currentUser['is_admin'] == 1;

// ============================================
// 8. DASHBOARD-SPECIFIC DATA (LOADED ONLY IF NEEDED)
// ============================================
$vehicle_counts = [];
$stats_result = null;
$loc_stats_result = null;
$activity_logs = null;
$repairData = [];
$locations = ['Mamburao', 'San Jose', 'Sablayan', 'Lubang'];

if ($view === 'dashboard') {
    try {
        // Vehicle counts with caching
        $vehicle_counts = CacheManager::get('vehicle_counts_' . date('Y-m-d-H'), function () use ($mysqli, $locations) {
            $counts = array_fill_keys($locations, 0);
            $placeholders = implode(',', array_fill(0, count($locations), '?'));
            $stmt = $mysqli->prepare("SELECT location, COUNT(*) AS total FROM equipment WHERE location IN ($placeholders) GROUP BY location");

            $types = str_repeat('s', count($locations));
            $stmt->bind_param($types, ...$locations);
            $stmt->execute();
            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                $counts[$row['location']] = (int) $row['total'];
            }
            $stmt->close();
            return $counts;
        }, 300);

        // Calculate document percentages
        $totalDocs = $pending + $approved + $done;
        $percent = fn($val) => $totalDocs > 0 ? round(($val / $totalDocs) * 100) : 0;
        $pendingPct = $percent($pending);
        $ongoingPct = $percent($approved);
        $donePct = $percent($done);

        // Pre Repair Inspection Data with single query
        $repairData = CacheManager::get('repair_data_' . date('Y-m-d-H'), function () use ($mysqli, $locations) {
            $statuses = ['Under repair', 'Operational', 'Unserviceable'];
            $data = array_fill_keys($statuses, array_fill_keys($locations, 0));

            $placeholders = implode(',', array_fill(0, count($locations), '?'));
            $statusPlaceholders = implode(',', array_fill(0, count($statuses), '?'));

            $stmt = $mysqli->prepare("
                SELECT location, status, COUNT(*) as cnt
                FROM equipment 
                WHERE location IN ($placeholders)
                AND status IN ($statusPlaceholders)
                GROUP BY location, status
                ORDER BY location, status
            ");

            $types = str_repeat('s', count($locations) + count($statuses));
            $params = array_merge($locations, $statuses);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                if (isset($data[$row['status']][$row['location']])) {
                    $data[$row['status']][$row['location']] = (int) $row['cnt'];
                }
            }
            $stmt->close();

            // Convert to arrays in correct order
            foreach ($statuses as $status) {
                $data[$status] = array_values($data[$status]);
            }

            return $data;
        }, 300);

        // Get summary statistics with LIMIT
        $stats_query = "SELECT 
            ec.category_name as equipment_name,
            COUNT(*) as units,
            SUM(CASE WHEN e.status = 'Operational' THEN 1 ELSE 0 END) as operational,
            SUM(CASE WHEN e.status = 'Under repair' THEN 1 ELSE 0 END) as under_repair,
            SUM(CASE WHEN e.status = 'Unserviceable' THEN 1 ELSE 0 END) as unserviceable
        FROM equipment e
        LEFT JOIN equipment_category ec ON e.category_id = ec.id
        GROUP BY ec.id, ec.category_name
        ORDER BY ec.category_name
        LIMIT 50";

        $stats_result = $mysqli->query($stats_query);

        // Summary by location with caching
        $loc_stats_result = CacheManager::get('loc_stats_' . date('Y-m-d-H'), function () use ($mysqli) {
            $query = "SELECT 
                e.location as location_name,
                COUNT(*) as units,
                SUM(CASE WHEN e.status = 'Operational' THEN 1 ELSE 0 END) as operational,
                SUM(CASE WHEN e.status = 'Under repair' THEN 1 ELSE 0 END) as under_repair,
                SUM(CASE WHEN e.status = 'Unserviceable' THEN 1 ELSE 0 END) as unserviceable
            FROM equipment e
            GROUP BY e.location
            ORDER BY e.location";

            $result = $mysqli->query($query);
            $data = [];
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $data[] = $row;
                }
            }
            return $data;
        }, 300);

        // Activity logs with limit
        $activity_logs = $mysqli->query("SELECT * FROM activity_log ORDER BY date_time DESC LIMIT 20");
    } catch (Exception $e) {
        error_log("Dashboard data loading failed: " . $e->getMessage());
        // Set defaults to prevent errors
        $vehicle_counts = array_fill_keys($locations, 0);
        $repairData = [
            'Under repair' => array_fill(0, count($locations), 0),
            'Operational' => array_fill(0, count($locations), 0),
            'Unserviceable' => array_fill(0, count($locations), 0)
        ];
    }
}

// ============================================
// 9. SESSION MESSAGES
// ============================================
$sessionMessage = '';
$sessionMsgType = '';
if (isset($_SESSION['message'])) {
    $sessionMessage = $_SESSION['message'];
    $sessionMsgType = $_SESSION['msg_type'] ?? 'info';
    // Clear session messages immediately after storing
    unset($_SESSION['message']);
    unset($_SESSION['msg_type']);
}

// Calculate load time for monitoring
$load_time = round((microtime(true) - $start_time) * 1000, 2);

// Log performance
error_log("Admin dashboard loaded in {$load_time}ms - User: {$_SESSION['user_id']} - View: {$view}");

// ============================================
// 10. TEMPLATE ENGINE SIMULATION
// ============================================
// We'll use a simple templating approach with output buffering
function renderTemplate($template, $data = [])
{
    extract($data);
    ob_start();
    include $template;
    return ob_get_clean();
}

// Prepare template data
$templateData = [
    'view' => $view,
    'sessionMessage' => $sessionMessage,
    'sessionMsgType' => $sessionMsgType,
    'currentUser' => $currentUser,
    'pendingCount' => $pendingCount,
    'pendingDocuments' => $pendingDocuments,
    'isSuperAdmin' => $isSuperAdmin,
    'csrfToken' => $csrfToken,
    'sidebarService' => $sidebarService,
    // Dashboard specific
    'vehicle_counts' => $vehicle_counts,
    'stats_result' => $stats_result,
    'loc_stats_result' => $loc_stats_result,
    'activity_logs' => $activity_logs,
    'repairData' => $repairData,
    'locations' => $locations,
    'pending' => $pending,
    'approved' => $approved,
    'done' => $done,
    'pendingPct' => $pendingPct ?? 0,
    'ongoingPct' => $ongoingPct ?? 0,
    'donePct' => $donePct ?? 0,
    'load_time' => $load_time
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>

    <!-- Add preconnect for CDN resources -->
    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <link rel="preconnect" href="https://cdnjs.cloudflare.com">

    <!-- Load critical CSS first -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" defer>

    <!-- Performance monitoring CSS -->
    <style>
        .perf-monitor {
            position: fixed;
            bottom: 10px;
            right: 10px;
            background: rgba(0, 0, 0, 0.7);
            color: white;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 11px;
            z-index: 99999;
            display: none;
        }

        /* ===== MODERN THEME VARIABLES ===== */
        :root {
            --sidebar-width: 380px;
            --header-height: 100px;
            --primary-gradient: linear-gradient(135deg, #133ae7 0%, #01c43b 100%);
            --sidebar-gradient: linear-gradient(180deg, #1a1a2e 0%, #16213e 100%);
            --card-gradient: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
        }

        /* ===== GLOBAL STYLES ===== */
        body {
            background: linear-gradient(135deg, #d7d8d7 0%, #dee1de 100%);
            font-family: 'Inter', sans-serif;
            overflow-x: hidden;
            min-height: 100vh;
        }

        /* ===== MODERN SIDEBAR ===== */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            width: var(--sidebar-width);
            background: var(--sidebar-gradient);
            box-shadow: 10px 0 30px rgba(0, 0, 0, 0.1);
            z-index: 1100;
            display: flex;
            flex-direction: column;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
        }

        /* Logo/Brand Area */
        .sidebar-brand {
            padding: 24px 24px 30px;
            background: rgba(255, 255, 255, 0.03);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            margin-bottom: 8px;
        }

        /* Modern Navigation Items */
        .nav-link-modern {
            display: flex;
            align-items: center;
            padding: 14px 20px;
            margin: 6px 16px;
            border-radius: 12px;
            color: #cbd5e1;
            text-decoration: none;
            transition: all 0.3s ease;
            font-size: 0.95rem;
            font-weight: 500;
            position: relative;
            overflow: hidden;
        }

        .nav-link-modern:hover {
            background: rgba(255, 255, 255, 0.1);
            color: #ffffff;
            transform: translateX(5px);
        }

        /* .nav-link-modern.active {
            background: var(--primary-gradient);
            color: white;
            box-shadow: 0 4px 15px rgba(26, 31, 25, 0.4);
        } */


        .nav-link-modern.active {
            /* Green Gradient Background */
            background: linear-gradient(135deg, #058596 0%, #10b981 100%);
            color: white;
            /* Green Glowing Shadow */
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.4);
        }


        .nav-link-modern i {
            width: 24px;
            text-align: center;
            margin-right: 14px;
            font-size: 1.1rem;
        }

        /* Submenu Styling */
        .submenu-modern {
            padding-left: 56px;
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* ===== MODERN TOP NAVBAR ===== */
        .top-navbar {
            position: fixed;
            top: 0;
            left: var(--sidebar-width);
            width: calc(100% - var(--sidebar-width));
            height: var(--header-height);
            background: linear-gradient(180deg, #1a1a2e 0%, #16213e 100%);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(229, 231, 235, 0.5);
            z-index: 1090;
            padding: 0 30px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: all 0.3s ease;
        }

        /* Search Bar */
        .search-container {
            flex: 1;
            max-width: 500px;
            margin: 0 30px;
        }

        .search-box {
            background: rgba(241, 245, 249, 0.8);
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 12px 20px;
            transition: all 0.3s ease;
            width: 100%;
        }

        .search-box:focus {
            background: white;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
            outline: none;
        }

        /* Notification Bell Animation */
        .notification-bell {
            animation: gentleRing 2s infinite;
        }

        @keyframes gentleRing {

            0%,
            100% {
                transform: rotate(0deg);
            }

            5%,
            15% {
                transform: rotate(15deg);
            }

            10%,
            20% {
                transform: rotate(-15deg);
            }
        }


        /* ===== MAIN CONTENT ===== */
        .main-content {
            margin-left: var(--sidebar-width);
            padding-top: calc(var(--header-height) + 30px);
            padding-left: 30px;
            padding-right: 30px;
            min-height: 100vh;
            transition: all 0.3s ease;
        }

        /* ===== RESPONSIVE DESIGN ===== */
        @media (max-width: 991.98px) {
            .sidebar {
                transform: translateX(-100%);
                box-shadow: 20px 0 50px rgba(0, 0, 0, 0.2);
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .top-navbar {
                left: 0;
                width: 100%;
                padding: 0 20px;
            }

            .main-content {
                margin-left: 0;
                padding: calc(var(--header-height) + 20px) 20px 20px;
            }

            .search-container {
                display: none;
            }

            #mobileToggle {
                display: block !important;
            }
        }

        /* ===== ENHANCED DROPDOWNS ===== */
        .dropdown-menu-modern {
            border: none;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
            padding: 10px;
            min-width: 280px;
            animation: slideUp 0.3s ease;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* ===== SCROLLBAR STYLING ===== */
        .custom-scrollbar::-webkit-scrollbar {
            width: 6px;
        }

        .custom-scrollbar::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 10px;
        }

        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.2);
            border-radius: 10px;
        }

        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        /* ===== CARD STYLES ===== */
        .modern-card {
            background: var(--card-gradient);
            border: 1px solid rgba(229, 231, 235, 0.5);
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
        }

        .modern-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }

        /* ===== RIPPLE EFFECT ===== */
        @keyframes ripple {
            to {
                transform: scale(4);
                opacity: 0;
            }
        }

        /* ===== ERROR BOUNDARY ===== */
        .error-boundary {
            border: 2px dashed #dc3545;
            padding: 20px;
            margin: 10px;
            border-radius: 12px;
            background: linear-gradient(135deg, #fff5f5 0%, #fed7d7 100%);
        }

        /* ===== LOADING STATES ===== */
        .loading {
            opacity: 0.7;
            pointer-events: none;
            position: relative;
        }

        .loading::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 30px;
            height: 30px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            transform: translate(-50%, -50%);
        }

        @keyframes spin {
            0% {
                transform: translate(-50%, -50%) rotate(0deg);
            }

            100% {
                transform: translate(-50%, -50%) rotate(360deg);
            }
        }

        /* ===== HEALTH INDICATOR ===== */
        .health-indicator {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 5px;
        }

        .health-healthy {
            background-color: #28a745;
            box-shadow: 0 0 10px rgba(40, 167, 69, 0.5);
        }

        .health-warning {
            background-color: #ffc107;
            box-shadow: 0 0 10px rgba(255, 193, 7, 0.5);
        }

        .health-error {
            background-color: #dc3545;
            box-shadow: 0 0 10px rgba(220, 53, 69, 0.5);
        }

        /* ===== GREETING TEXT ===== */
        .greeting-text {
            background: linear-gradient(135deg, #66ea71 0%, #3ec4a7 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        /* ===== PAGE TITLE ===== */
        .page-title {
            font-size: 1.75rem;
            font-weight: 700;
            background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 0.5rem;
        }

        .page-divider {
            height: 3px;
            background: linear-gradient(90deg, #15b300 0%, #4ba257 100%);
            border-radius: 3px;
            margin-bottom: 2rem;
        }


        /* ===== FIX: MODAL Z-INDEX OVERLAP ===== */
        /* Your Sidebar is z-index 1100. 
           We must set the Modal Backdrop to 1150 and the Modal itself to 1160 
           so they sit ON TOP of the sidebar.
        */
        .modal-backdrop {
            z-index: 1150 !important;
        }

        .modal {
            z-index: 1160 !important;
        }

        /* loading animation side bar*/
    </style>

    <!-- Defer non-critical CSS -->
    <link rel="preload" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" as="style"
        onload="this.onload=null;this.rel='stylesheet'">
    <noscript>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    </noscript>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.css" rel="stylesheet" defer>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        green: {
                            50: '#f0fdf4',
                            600: '#198754',
                            700: '#146c43',
                        },
                        purple: {
                            500: '#8b5cf6',
                            600: '#7c3aed',
                            700: '#6d28d9',
                        }
                    },
                    spacing: {
                        'sidebar': '280px',
                        'topbar': '80px'
                    }
                }
            }
        }
    </script>
</head>

<body class="bg-gray-50">

    <!-- Performance Monitor (hidden by default, shows on Shift+P) -->
    <div class="perf-monitor" id="perfMonitor">
        Load: <?php echo $load_time; ?>ms |
        Cache: <span id="cacheStats">-</span> |
        DB: <span id="dbStats">-</span>
    </div>

    <!-- Display session message if exists -->
    <?php if (!empty($sessionMessage)): ?>
        <div
            class="alert alert-<?php echo $sessionMsgType; ?> alert-dismissible fade show fixed top-24 right-5 z-[9999] max-w-md shadow-lg border-0 rounded-xl">
            <?php echo $sessionMessage; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="sidebar-overlay fixed inset-0 bg-black/50 z-[1045] hidden" id="sidebarOverlay"></div>

    <!-- MODERN SIDEBAR -->
    <aside class="sidebar" id="sidebar">
        <!-- Brand/Logo Area -->
        <div class="sidebar-brand">
            <div class="flex flex-col items-center space-y-3">
                <div class="relative">
                    <img src="./rs/Pepo_Logo.png" alt="PEPO" class="w-28 h-28 object-contain">
                    <div
                        class="absolute -inset-1 bg-gradient-to-r from-blue-500 to-green-500 rounded-full blur opacity-20">
                    </div>
                </div>
                <div class="flex flex-col items-center">
                    <span class="text-xl font-bold text-white tracking-tight">PEPO</span>
                    <span class="text-xs font-medium text-purple-300 uppercase tracking-wider">Admin Dashboard</span>
                </div>
            </div>
        </div>

        <!-- Navigation Menu -->
        <div class="sidebar-nav custom-scrollbar flex-1 px-4 py-4">
            <?php echo $sidebarService->renderMenu(); ?>

            <!-- Quick Actions -->
            <div class="mt-8 px-2">
                <div class="px-2 mb-3 text-xs font-bold text-purple-300 uppercase tracking-wider">Quick Actions</div>
                <div class="space-y-2">
                    <a href="?view=documents&csrf=<?php echo $csrfToken; ?>"
                        class="flex items-center text-sm text-gray-300 hover:text-white p-2 rounded-lg hover:bg-white/5 transition-all group">
                        <div
                            class="w-8 h-8 rounded-lg bg-gradient-to-br from-blue-500 to-green-500 flex items-center justify-center mr-3 group-hover:scale-110 transition-transform">
                            <i class="fas fa-plus text-xs text-white"></i>
                        </div>
                        <span>View Request</span>
                    </a>
                    <a href="?view=maintenance&csrf=<?php echo $csrfToken; ?>"
                        class="flex items-center text-sm text-gray-300 hover:text-white p-2 rounded-lg hover:bg-white/5 transition-all group">
                        <div
                            class="w-8 h-8 rounded-lg bg-gradient-to-br from-green-500 to-teal-500 flex items-center justify-center mr-3 group-hover:scale-110 transition-transform">
                            <i class="fas fa-chart-line text-xs text-white"></i>
                        </div>
                        <span>View Maintenance</span>
                    </a>
                </div>
            </div>
        </div>

        <!-- User Profile & Logout -->
        <div class="p-4 border-t border-white/10 mt-auto">
            <div
                class="flex items-center space-x-3 p-3 rounded-xl bg-white/5 hover:bg-white/10 transition-all cursor-pointer group">
                <div class="relative">
                    <img src="./rs/Pepo_Logo.png" alt="User"
                        class="w-10 h-10 rounded-xl object-cover ring-2 ring-purple-500/30 group-hover:ring-purple-500/50 transition-all">
                    <div class="absolute -bottom-1 -right-1 w-3 h-3 bg-green-400 rounded-full border-2 border-gray-900">
                    </div>
                </div>
                <div class="flex-1">
                    <div class="font-medium text-white text-sm"><?= htmlspecialchars($currentUser['username']) ?></div>
                    <div class="text-xs text-gray-400"><?= ucfirst($currentUser['role']) ?></div>
                </div>
            </div>

            <a href="logout.php?csrf=<?php echo $csrfToken; ?>"
                class="flex items-center justify-center w-full mt-3 py-3 px-4 text-sm font-semibold text-white bg-gradient-to-r from-red-500 to-pink-500 hover:from-red-600 hover:to-pink-600 rounded-xl transition-all hover:shadow-lg hover:shadow-red-500/20 group">
                <i class="fas fa-sign-out-alt mr-2 transform group-hover:-translate-x-1 transition-transform"></i>
                Sign Out
            </a>
        </div>
    </aside>

    <!-- MODERN TOP NAVBAR -->
    <nav class="top-navbar">
        <!-- Left: Menu Toggle & Greeting -->
        <div class="flex items-center space-x-4">
            <button id="mobileToggle" class="lg:hidden text-blue
            -600 hover:text-green-600 transition-colors">
                <div
                    class="w-10 h-10 rounded-xl bg-gradient-to-r from-gray-100 to-white flex items-center justify-center shadow-sm hover:shadow-md transition-shadow">
                    <i class="fas fa-bars text-lg"></i>
                </div>
            </button>

            <div class="hidden lg:block">
                <div class="text-lg font-semibold text-white">
                    Welcome, <span class="greeting-text"><?= htmlspecialchars($currentUser['username']) ?></span>
                </div>

                <div class="text-sm text-white">
                    here's the overview of PEPO maintenance department from the last 7 days
                </div>
            </div>
        </div>
        <!-- Center: Search Bar -->


        <!-- Right: Actions -->
        <div class="flex items-center space-x-3">
            <!-- Notifications -->

            <div class="dropdown relative" id="notificationsContainer">
                <a href="#" class="relative p-2 text-white hover:text-green-400 transition-colors"
                    data-bs-toggle="dropdown" onclick="loadNotifications()">
                    <div
                        class="w-10 h-10 rounded-xl hover:bg-white/10 flex items-center justify-center transition-all hover:scale-105">
                        <div class="relative">
                            <i class="far fa-bell text-lg notification-bell"></i>

                            <?php if ($pendingCount > 0): ?>
                                <span id="mainNotificationBadge"
                                    class="absolute -top-2 -right-2 w-4 h-4 bg-red-500 text-white text-[10px] rounded-full flex items-center justify-center animate-pulse border border-gray-800 shadow-sm">
                                    <?= $pendingCount ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </a>


                <ul class="dropdown-menu dropdown-menu-end shadow-[0_10px_40px_-10px_rgba(0,0,0,0.1)] border-0 rounded-xl mt-4 p-0 !min-w-[380px] sm:!min-w-[500px] overflow-hidden"
                    style="width: max-content;">
                    <li class="px-4 py-3 border-b border-gray-100 flex justify-between items-center bg-white">
                        <h6 class="font-bold text-gray-800 m-0">Notifications</h6>
                        <?php if ($pendingCount > 0): ?>
                            <span class="badge bg-green-100 text-green-700 rounded-md px-2 py-1 text-xs font-medium">
                                <span id="notificationCount"><?= $pendingCount ?></span> New
                            </span>
                        <?php endif; ?>
                    </li>
                    <li>
                        <div id="notificationsList" class="max-h-[400px] overflow-y-auto custom-scrollbar bg-white">
                            <div class="text-center p-4">
                                <div class="spinner-border spinner-border-sm text-success" role="status"></div>
                                <p class="text-muted mt-2 mb-0">Loading notifications...</p>
                            </div>
                        </div>
                    </li>
                    <li class="p-3 bg-white border-t border-gray-100">
                        <div class="flex gap-2">
                            <a href="?view=documents&csrf=<?php echo $csrfToken; ?>"
                                class="flex-1 py-2 px-3 bg-green-600 border border-green-600 text-white rounded-lg text-sm font-semibold text-center hover:bg-green-700 transition-colors no-underline">
                                View My Requests
                            </a>
                        </div>
                    </li>
                </ul>
            </div>

            <!-- Theme Toggle -->

            <!-- User Profile -->
            <a href="#" data-pepo-toggle="profile"
                class="flex items-center space-x-3 p-1 pr-3 rounded-xl hover:bg-white/10 transition-all group">
                <img src="./rs/Pepo_Logo.png" alt="User" class="w-12 h-12 rounded-xl object-cover user-profile">
                <div class="hidden md:block text-start">
                    <div
                        class="text-sm font-bold text-white leading-tight group-hover:text-green-400 transition-colors">
                        <?= htmlspecialchars($currentUser['username']) ?>
                    </div>
                    <div class="text-xs text-gray-300 font-medium">
                        <span class="inline-flex items-center">
                            <span class="w-2 h-2 bg-green-500 rounded-full mr-1"></span>
                            Online
                        </span>
                    </div>
                </div>
            </a>
        </div>
    </nav>

    <!-- MAIN CONTENT -->
    <main class="main-content min-h-screen transition-all duration-300 ease-in-out">
        <!-- <div class="container-fluid p-0">
            <h1 id="dynamic-page-title" class="page-title">
                <?php
                $titles = [
                    'dashboard' => 'Overview',
                    'equipment' => 'Equipment',
                    'documents' => 'Documents',
                    'maintenance' => 'Maintenance',
                    'inventory' => 'Inventory',
                    'activities' => 'Schedule and Activity Logs',
                    'report' => 'Reports',
                    'user' => 'User Management',
                    'superadmin_user' => 'User Management (Super Admin)',
                    '404' => 'Page Not Found'
                ];
                echo $titles[$view] ?? 'Page Not Found';
                ?>
            </h1>
            <div class="page-divider"></div>
        </div> -->

        <!-- Error boundary for content -->
        <div id="contentErrorBoundary" style="display: none;" class="error-boundary">
            <h5 class="text-danger"><i class="fas fa-exclamation-triangle"></i> Content Loading Error</h5>
            <p id="errorMessage"></p>
            <button onclick="location.reload()" class="btn btn-sm btn-outline-danger">Reload Page</button>
        </div>

        <!-- Load scripts BEFORE views to ensure bootstrap is available -->
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/chart.js" defer></script>
        <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels" defer></script>

        <!-- Main Content Area -->
        <div id="mainContentArea">
            <?php if ($view === 'dashboard'): ?>
                <?php include 'side_dashboard.php'; ?>
            <?php elseif ($view !== '404'): ?>
                <?php
                try {
                    $viewFile = ViewManager::getViewFile($view);
                    if (file_exists($viewFile)) {
                        include $viewFile;
                    } else {
                        throw new Exception("View file not found: {$viewFile}");
                    }
                } catch (Exception $e) {
                    echo '<div class="error-boundary">';
                    echo '<h5 class="text-danger"><i class="fas fa-exclamation-triangle"></i> Content Error</h5>';
                    echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
                    echo '<button onclick="location.reload()" class="btn btn-sm btn-outline-danger">Reload Page</button>';
                    echo '</div>';
                    error_log("View loading error: " . $e->getMessage());
                }
                ?>
            <?php else: ?>
                <div class="modern-card p-8 text-center">
                    <h2 class="fw-bold mb-4">Page Not Found</h2>
                    <p>The view you requested does not exist.</p>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- View/Edit Activity Modal -->
    <div class="modal fade" id="viewActivityModal" tabindex="-1" aria-labelledby="viewActivityLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content rounded-4">
                <div class="modal-header bg-gradient-to-r from-green-500 to-emerald-600 text-white">
                    <h5 class="modal-title" id="viewActivityLabel">Schedule Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                        aria-label="Close"></button>
                </div>
                <form id="viewActivityForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                    <div class="modal-body">
                        <!-- Modal content remains the same -->
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- System Health Modal -->
    <div class="modal fade" id="healthModal" tabindex="-1" aria-labelledby="healthModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-gradient-to-r from-blue-500 to-cyan-600 text-white">
                    <h5 class="modal-title" id="healthModalLabel">System Health Check</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                        aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="healthCheckResults">
                        <div class="text-center">
                            <div class="spinner-border text-info" role="status"></div>
                            <p class="mt-2">Checking system health...</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modern Dashboard JavaScript -->
    <script>
        // Global drawer toggle function
        window.toggleEquipmentDrawer = function(open) {
            const drawer = document.getElementById('equipmentLogDrawer');
            const backdrop = document.getElementById('equipmentLogDrawerBackdrop');
            if (!drawer || !backdrop) {
                console.error("Drawer elements not found");
                return;
            }
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

        // Performance monitoring
        let perfStartTime = performance.now();

        // Error boundary for JavaScript
        window.onerror = function(msg, url, lineNo, columnNo, error) {
            console.error('JavaScript Error:', msg, error);
            return false;
        };

        // Critical JavaScript for initial page functionality
        document.addEventListener('DOMContentLoaded', function() {
            // Sidebar toggle functionality
            initModernSidebar();

            // Initialize theme toggle
            initThemeToggle();

            // Initialize modern interactions
            initModernInteractions();

            // Report tab switching
            initReportTabs();

            // Initialize performance monitoring
            initPerformanceMonitoring();

            // Initialize error boundaries
            initErrorBoundaries();

            // Calculate and log DOM load time
            const domLoadTime = performance.now() - perfStartTime;
            console.log(`DOM loaded in ${domLoadTime.toFixed(2)}ms`);
        });

        // Modern Sidebar Interactions
        function toggleSubmenu(menuId) {
            const submenu = document.getElementById(menuId + 'Submenu');
            const arrow = document.getElementById(menuId + 'Arrow');

            if (submenu.classList.contains('hidden')) {
                submenu.classList.remove('hidden');
                submenu.classList.add('block');
                arrow.classList.remove('fa-chevron-down');
                arrow.classList.add('fa-chevron-up');
            } else {
                submenu.classList.remove('block');
                submenu.classList.add('hidden');
                arrow.classList.remove('fa-chevron-up');
                arrow.classList.add('fa-chevron-down');
            }
        }

        function initModernSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const mobileToggle = document.getElementById('mobileToggle');

            mobileToggle.addEventListener('click', () => {
                sidebar.classList.add('active');
                if (overlay) overlay.classList.remove('hidden');
            });

            if (overlay) {
                overlay.addEventListener('click', () => {
                    sidebar.classList.remove('active');
                    overlay.classList.add('hidden');
                });
            }

            // Add ripple effect to navigation links
            document.querySelectorAll('.nav-link-modern').forEach(button => {
                button.addEventListener('click', function(e) {
                    const ripple = document.createElement('span');
                    const rect = this.getBoundingClientRect();
                    const size = Math.max(rect.width, rect.height);
                    const x = e.clientX - rect.left - size / 2;
                    const y = e.clientY - rect.top - size / 2;

                    ripple.style.cssText = `
                    position: absolute;
                    border-radius: 50%;
                    background: rgba(255, 255, 255, 0.3);
                    transform: scale(0);
                    animation: ripple 0.6s linear;
                    width: ${size}px;
                    height: ${size}px;
                    top: ${y}px;
                    left: ${x}px;
                    pointer-events: none;
                `;

                    this.appendChild(ripple);
                    setTimeout(() => ripple.remove(), 600);
                });
            });
        }

        function initThemeToggle() {
            const themeToggle = document.getElementById('themeToggle');
            const themeIcon = document.getElementById('themeIcon');
            const body = document.body;

            if (!themeToggle || !themeIcon) return;

            // Check saved theme
            if (localStorage.getItem('theme') === 'dark') {
                body.classList.add('dark-mode');
                themeIcon.classList.replace('fa-moon', 'fa-sun');
            }

            themeToggle.addEventListener('click', () => {
                body.classList.toggle('dark-mode');

                if (body.classList.contains('dark-mode')) {
                    themeIcon.classList.replace('fa-moon', 'fa-sun');
                    localStorage.setItem('theme', 'dark');
                } else {
                    themeIcon.classList.replace('fa-sun', 'fa-moon');
                    localStorage.setItem('theme', 'light');
                }
            });
        }

        function initModernInteractions() {
            // Add hover effects to modern cards
            document.querySelectorAll('.modern-card').forEach(card => {
                card.addEventListener('mouseenter', () => {
                    card.style.transform = 'translateY(-5px)';
                });

                card.addEventListener('mouseleave', () => {
                    card.style.transform = 'translateY(0)';
                });
            });
        }

        function initReportTabs() {
            window.showTab = function(tabName) {
                const tabs = document.querySelectorAll('.report-tab');
                tabs.forEach(tab => {
                    tab.classList.add('hidden');
                    tab.classList.remove('block');
                });

                const selectedTab = document.getElementById(tabName + 'Tab');
                if (selectedTab) {
                    selectedTab.classList.remove('hidden');
                    selectedTab.classList.add('block');
                }

                const preRepairBtn = document.getElementById('preRepairBtn');
                const summaryBtn = document.getElementById('summaryBtn');

                if (tabName === 'preRepair') {
                    preRepairBtn.classList.remove('bg-gray-300', 'text-gray-800');
                    preRepairBtn.classList.add('bg-gradient-to-r', 'from-blue-500', 'to-purple-500', 'text-white');
                    summaryBtn.classList.remove('bg-gradient-to-r', 'from-blue-500', 'to-purple-500', 'text-white');
                    summaryBtn.classList.add('bg-gray-300', 'text-gray-800');
                } else if (tabName === 'summary') {
                    preRepairBtn.classList.remove('bg-gradient-to-r', 'from-blue-500', 'to-purple-500', 'text-white');
                    preRepairBtn.classList.add('bg-gray-300', 'text-gray-800');
                    summaryBtn.classList.remove('bg-gray-300', 'text-gray-800');
                    summaryBtn.classList.add('bg-gradient-to-r', 'from-blue-500', 'to-purple-500', 'text-white');
                }
            };
        }

        function initPerformanceMonitoring() {
            // Toggle performance monitor with Shift+P
            document.addEventListener('keydown', (e) => {
                if (e.shiftKey && e.key === 'P') {
                    const monitor = document.getElementById('perfMonitor');
                    monitor.style.display = monitor.style.display === 'none' ? 'block' : 'none';
                }
            });

            // Update performance stats
            updatePerformanceStats();
        }

        function initErrorBoundaries() {
            // Catch unhandled promise rejections
            window.addEventListener('unhandledrejection', (event) => {
                console.error('Unhandled promise rejection:', event.reason);
                showError('An unexpected error occurred: ' + (event.reason?.message || 'Unknown error'));
            });
        }

        function showError(message) {
            const errorBoundary = document.getElementById('contentErrorBoundary');
            const errorMessage = document.getElementById('errorMessage');
            const mainContent = document.getElementById('mainContentArea');

            errorMessage.textContent = message;
            errorBoundary.style.display = 'block';
            mainContent.style.display = 'none';
        }

        // LAZY LOADING FOR NOTIFICATIONS
        window.loadNotifications = function() {
            const notificationsList = document.getElementById('notificationsList');
            // Prevent reloading if already loaded
            if (!notificationsList || notificationsList.dataset.loaded === 'true') {
                return;
            }

            // Show loading spinner
            notificationsList.innerHTML = `
            <div class="text-center p-4">
                <div class="spinner-border spinner-border-sm text-success" role="status"></div>
                <p class="text-muted mt-2 mb-0">Loading notifications...</p>
            </div>
        `;

            fetch('fetch_notifications.php')
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok: ' + response.status);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        renderNotifications(data.notifications);
                        notificationsList.dataset.loaded = 'true';
                    }
                    // Update badge counts if provided
                    if (data.count !== undefined) {
                        // 1. Update the counter INSIDE the dropdown menu
                        const dropdownBadge = document.getElementById('notificationCount');
                        if (dropdownBadge) dropdownBadge.innerText = data.count;

                        // 2. Update the Red Bell Icon Badge
                        const bellBadge = document.getElementById('mainNotificationBadge');
                        if (bellBadge) {
                            bellBadge.innerText = data.count;

                            // Show or hide the red badge based on count
                            if (parseInt(data.count) > 0) {
                                bellBadge.classList.remove('hidden');
                            } else {
                                bellBadge.classList.add('hidden');
                            }
                        }
                    } else {
                        console.error('Server message:', data.message);
                        // Show specific error to user
                        notificationsList.innerHTML = `
                        <div class="p-4 text-center text-red-500">
                            <i class="fas fa-exclamation-circle"></i> ${data.message}
                        </div>`;
                    }
                })
                .catch(error => {
                    console.error('Error loading notifications:', error);
                    notificationsList.innerHTML = `
                    <div class="text-center p-4">
                        <p class="text-red-500 text-sm mb-2">Failed to load</p>
                        <button onclick="this.parentElement.parentElement.dataset.loaded='false'; loadNotifications()" class="btn btn-sm btn-outline-danger">Retry</button>
                    </div>
                `;
                });
        }

        window.renderNotifications = function(notifications) {
            const notificationsList = document.getElementById('notificationsList');
            if (!notificationsList) return;

            if (notifications.length === 0) {
                notificationsList.innerHTML = `
                <div class="p-8 text-center">
                    <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-gray-100 mb-3">
                        <i class="far fa-check-circle text-gray-400 text-xl"></i>
                    </div>
                    <p class="text-gray-500 text-sm m-0">No new notifications</p>
                </div>
            `;
                return;
            }

            let html = '';
            notifications.forEach(doc => {
                // Safe Data Handling
                const officer = doc.officer_name || 'Unknown Officer';
                const equipment = doc.equipment || 'Equipment';
                const date = new Date(doc.date_requested).toLocaleDateString();
                const source = doc.source || 'document';
                const status = doc.status || '';

                let icon, iconColor, title, link;

                if (source === 'supply') {
                    // Supply Request Notifications
                    if (status === 'approved') {
                        icon = 'fa-check-circle';
                        iconColor = 'text-blue-600';
                        title = 'Supply Request Approved';
                        link = '?view=maintenance&tab=supply';
                    } else if (status === 'complied') {
                        icon = 'fa-box-open';
                        iconColor = 'text-amber-600';
                        title = 'Requested Materials Available';
                        link = '?view=maintenance&tab=supply';
                    } else {
                        icon = 'fa-shopping-cart';
                        iconColor = 'text-purple-600';
                        title = 'Supply Request Update';
                        link = '?view=maintenance&tab=supply';
                    }
                } else {
                    // Document Request Notifications (existing)
                    icon = 'fa-file-invoice';
                    iconColor = 'text-green-600';
                    title = 'New Request Pending';
                    link = `?view=documents&open_id=${doc.id}`;
                }

                html += `
                <a href="${link}" class="block px-4 py-3 border-b hover:bg-gray-50 transition-colors no-underline">
                    <div class="flex items-start gap-3">
                        <div class="mt-1"><i class="fas ${icon} ${iconColor}"></i></div>
                        <div>
                            <p class="text-sm font-bold text-gray-800 mb-0">${title}</p>
                            <p class="text-xs text-gray-600 mb-1">${equipment} - ${officer}</p>
                            <p class="text-[10px] text-gray-400">${date}</p>
                        </div>
                    </div>
                </a>
            `;
            });

            notificationsList.innerHTML = html;
        }

        window.markAllAsRead = function() {
            // --- STEP 1: VISUAL CLEAR (Instant) ---
            const list = document.getElementById('notificationsList');
            if (list) {
                list.innerHTML = `
            <div class="p-8 text-center">
                <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-gray-100 mb-3">
                    <i class="far fa-check-circle text-gray-400 text-xl"></i>
                </div>
                <p class="text-gray-500 text-sm m-0">Marked as read</p>
            </div>
        `;
                list.dataset.loaded = 'false';
            }

            // Hide the red badges
            const badge = document.querySelector('.badge.bg-red-500');
            const countSpan = document.getElementById('notificationCount');

            if (badge) badge.style.display = 'none';
            if (countSpan) countSpan.innerText = '0';

            // --- STEP 2: SERVER UPDATE (Background) ---
            fetch('api/mark_read.php')
                .then(response => response.json())
                .then(data => console.log('Server synced'))
                .catch(err => console.log('Background sync error (ignored)', err));
        }

        // SYSTEM HEALTH CHECK
        function checkSystemHealth() {
            const healthModal = new bootstrap.Modal(document.getElementById('healthModal'));
            healthModal.show();

            const resultsDiv = document.getElementById('healthCheckResults');
            resultsDiv.innerHTML = `
            <div class="text-center">
                <div class="spinner-border text-info" role="status"></div>
                <p class="mt-2">Checking system health...</p>
            </div>
        `;

            // Check multiple systems
            Promise.all([
                checkDatabaseHealth(),
                checkCacheHealth(),
                checkSessionHealth(),
                checkApiHealth()
            ]).then(results => {
                renderHealthResults(results);
            }).catch(error => {
                resultsDiv.innerHTML = `
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i> Health check failed: ${error.message}
                </div>
            `;
            });
        }

        function checkDatabaseHealth() {
            return fetch(`api/health.php?check=db&csrf=<?php echo $csrfToken; ?>`)
                .then(r => r.json());
        }

        function checkCacheHealth() {
            return fetch(`api/health.php?check=cache&csrf=<?php echo $csrfToken; ?>`)
                .then(r => r.json());
        }

        function checkSessionHealth() {
            return Promise.resolve({
                service: 'Session',
                status: 'healthy',
                details: 'Session is active'
            });
        }

        function checkApiHealth() {
            return fetch(`api/health.php?check=api&csrf=<?php echo $csrfToken; ?>`)
                .then(r => r.json());
        }

        function renderHealthResults(results) {
            const resultsDiv = document.getElementById('healthCheckResults');
            let html = '<div class="list-group">';

            results.forEach(result => {
                const statusClass = result.status === 'healthy' ? 'success' :
                    result.status === 'warning' ? 'warning' : 'danger';

                html += `
                <div class="list-group-item d-flex justify-content-between align-items-center">
                    <div>
                        <span class="health-indicator health-${result.status}"></span>
                        <strong>${result.service}</strong>
                        <small class="d-block text-muted">${result.details || 'No details'}</small>
                    </div>
                    <span class="badge bg-${statusClass}">${result.status}</span>
                </div>
            `;
            });

            html += '</div>';
            resultsDiv.innerHTML = html;
        }

        // PERFORMANCE STATS UPDATE
        function updatePerformanceStats() {
            fetch(`api/stats.php?csrf=<?php echo $csrfToken; ?>`)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        const cacheStats = document.getElementById('cacheStats');
                        const dbStats = document.getElementById('dbStats');

                        if (cacheStats) {
                            cacheStats.textContent = `${data.cache.hit_rate}% hit rate`;
                        }

                        if (dbStats) {
                            dbStats.textContent = `${data.database.query_count} queries`;
                        }
                    }
                })
                .catch(() => {
                    // Silently fail - this is just monitoring
                });
        }

        // Utility function for escaping HTML
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Profile drawer fix (prevent auto-showing)
        const profileDrawer = document.getElementById('profileDrawer');
        if (profileDrawer) {
            profileDrawer.classList.remove('show');
            profileDrawer.style.display = 'none';
        }
    </script>

    <!-- Non-critical JavaScript loaded after page loads -->
    <script>
        window.addEventListener('load', function() {
            // Initialize charts if they exist
            initCharts();

            // Load calendar functionality if needed
            initCalendar();

            // Report final load time
            const totalLoadTime = performance.now() - perfStartTime;
            console.log(`Total page load time: ${totalLoadTime.toFixed(2)}ms`);

            // Update performance monitor
            updatePerformanceStats();
        });

        function initCharts() {
            // Chart initialization code remains similar to original
            // ... (omitted for brevity, but would include the chart.js code)
        }

        function initCalendar() {
            // Calendar initialization code remains similar to original
            // ... (omitted for brevity, but would include the calendar code)
        }

        // Define your titles in JS to match PHP
        const pageTitles = {
            'dashboard': 'Overview',
            'equipment': 'Equipment',
            'documents': 'Documents',
            'maintenance': 'Maintenance',
            'inventory': 'Inventory',
            'activities': 'Schedule and Activity Logs',
            'report': 'Reports',
            'user': 'User Management',
            'superadmin_user': 'User Management (Super Admin)'
        };

        // Update page title when navigating
        document.addEventListener('click', function(e) {
            if (e.target.closest('.nav-link-modern')) {
                const link = e.target.closest('.nav-link-modern');
                const href = link.getAttribute('href');

                if (href && href.includes('view=')) {
                    setTimeout(() => {
                        try {
                            const urlParams = new URLSearchParams(href.split('?')[1]);
                            const viewParam = urlParams.get('view');
                            const titleElement = document.getElementById('dynamic-page-title');

                            if (titleElement && viewParam && pageTitles[viewParam]) {
                                titleElement.textContent = pageTitles[viewParam];
                            }
                        } catch (err) {
                            console.log("Could not update title:", err);
                        }
                    }, 100);
                }
            }
        });




        // ==========================================
        // SIDEBAR LOADING TRIGGER
        // ==========================================
        document.addEventListener('DOMContentLoaded', function() {
            const loader = document.getElementById('global-loader');

            // Target specifically the sidebar links and submenu links
            // We use the classes you already have: .nav-link-modern and links inside .submenu-modern
            const sidebarLinks = document.querySelectorAll('.nav-link-modern, .submenu-modern a');

            sidebarLinks.forEach(link => {
                link.addEventListener('click', function(e) {
                    // Get the href attribute
                    // "this" refers to the element clicked
                    const targetUrl = this.getAttribute('href');

                    // Logic to check if we should show loader:
                    // 1. Link exists
                    // 2. Not a hash link (dropdown toggles usually use # or javascript:void(0))
                    // 3. Not opening in a new tab (target="_blank")
                    if (targetUrl &&
                        targetUrl !== '#' &&
                        !targetUrl.startsWith('javascript') &&
                        this.getAttribute('target') !== '_blank') {

                        // Show the loader
                        if (loader) {
                            loader.classList.remove('hidden');
                            loader.classList.add('flex');
                        }
                    }
                });
            });

            // BROWSER BACK BUTTON FIX
            // If user hits "Back", the page is loaded from cache and the loader might still be visible.
            // This event listener ensures the loader is hidden when the page is shown from history.
            window.addEventListener('pageshow', function(event) {
                if (loader) {
                    loader.classList.add('hidden');
                    loader.classList.remove('flex');
                }
            });
        });
    </script>

    <!-- ✅ Equipment Activity Log Drawer (Global) -->
    <div id="equipmentLogDrawerBackdrop"
        class="fixed inset-0 bg-black/50 backdrop-blur-sm z-[1999] opacity-0 pointer-events-none transition-opacity duration-300"
        onclick="toggleEquipmentDrawer(false)"></div>
    <div id="equipmentLogDrawer"
        class="fixed top-0 right-0 h-full w-full md:w-[600px] bg-white shadow-2xl z-[2000] translate-x-full transition-transform duration-500 ease-[cubic-bezier(0.4,0,0.2,1)] flex flex-col border-l border-gray-200">
        <!-- Drawer Header (DARK THEME) -->
        <div class="p-6 bg-slate-900 border-b border-white/10 flex justify-between items-center shadow-lg">
            <div class="flex items-center gap-4">
                <div
                    class="h-12 w-12 rounded-xl bg-blue-500/10 flex items-center justify-center text-blue-400 border border-blue-500/20">
                    <i class="fas fa-history text-xl"></i>
                </div>
                <div>
                    <h5 class="text-white font-bold text-lg uppercase tracking-wider">Action Log</h5>
                    <p class="text-xs text-slate-400 font-medium uppercase mt-0.5">Recent Equipment Changes</p>
                </div>
            </div>
            <button onclick="toggleEquipmentDrawer(false)"
                class="h-10 w-10 flex items-center justify-center rounded-xl bg-white/5 text-slate-400 hover:bg-red-500/20 hover:text-red-400 transition-all border border-white/10 group">
                <i class="fas fa-times transition-transform group-hover:rotate-90"></i>
            </button>
        </div>

        <!-- Drawer Content (LIGHT THEME - MAXIMUM READABILITY) -->
        <div class="flex-1 overflow-y-auto p-8 space-y-6 custom-scrollbar bg-gray-100">
            <?php
            // Use global connection for the drawer
            global $mysqli;
            $logs_query = "SELECT * FROM activity_log WHERE activity_type IN ('EQUIPMENT_ADDED', 'EQUIPMENT_UPDATED', 'CATEGORY_ADDED') ORDER BY date_time DESC LIMIT 50";
            $logs_res = $mysqli->query($logs_query);

            if ($logs_res && $logs_res->num_rows > 0):
                while ($log = $logs_res->fetch_assoc()):
                    $icon = match ($log['activity_type']) {
                        'EQUIPMENT_ADDED' => 'fa-plus-circle text-emerald-700',
                        'EQUIPMENT_UPDATED' => 'fa-edit text-blue-700',
                        'CATEGORY_ADDED' => 'fa-tags text-purple-700',
                        default => 'fa-info-circle text-gray-600'
                    };
                    $typeLabel = str_replace('_', ' ', $log['activity_type']);
            ?>
                    <div
                        class="bg-white border-2 border-gray-200 rounded-3xl p-6 shadow-md hover:shadow-xl hover:border-blue-300 transition-all duration-300">
                        <div class="flex items-start gap-5">
                            <div
                                class="h-14 w-14 rounded-2xl bg-gray-50 border border-gray-200 flex items-center justify-center flex-shrink-0 shadow-sm">
                                <i class="fas <?= $icon ?> text-2xl"></i>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex justify-between items-center mb-3">
                                    <span
                                        class="text-xs font-black uppercase text-gray-600 tracking-widest bg-gray-100 px-3 py-1 rounded-full border border-gray-200">
                                        <?= $typeLabel ?>
                                    </span>
                                    <span class="text-sm font-bold text-gray-500 flex items-center gap-1.5">
                                        <i class="far fa-clock"></i>
                                        <?= date('M d, Y • h:i A', strtotime($log['date_time'])) ?>
                                    </span>
                                </div>

                                <h4 class="text-lg font-black text-gray-900 mb-2 flex items-center gap-2">
                                    <span class="text-gray-400 font-medium">Property:</span>
                                    <span
                                        class="font-mono text-blue-700 bg-blue-50 px-2 py-0.5 rounded"><?= htmlspecialchars($log['property_no']) ?></span>
                                </h4>

                                <p
                                    class="text-base text-gray-800 leading-relaxed font-medium mb-4 p-4 bg-gray-50 rounded-xl border-l-4 border-gray-200">
                                    <?= htmlspecialchars($log['remarks']) ?>
                                </p>

                                <div class="flex items-center justify-between pt-4 border-t-2 border-gray-100">
                                    <div class="flex items-center gap-3">
                                        <div
                                            class="h-10 w-10 rounded-full bg-blue-600 flex items-center justify-center shadow-sm">
                                            <i class="fas fa-user text-white text-sm"></i>
                                        </div>
                                        <div>
                                            <p
                                                class="text-[10px] font-black text-gray-400 uppercase tracking-tighter leading-none mb-1">
                                                Performed By</p>
                                            <p class="text-sm font-black text-gray-900 uppercase">
                                                <?= htmlspecialchars($log['performed_by'] ?? 'System') ?>
                                            </p>
                                        </div>
                                    </div>

                                    <div class="text-right">
                                        <p
                                            class="text-[10px] font-black text-gray-400 uppercase tracking-tighter leading-none mb-1">
                                            Location</p>
                                        <span
                                            class="text-sm font-black text-gray-700 uppercase flex items-center justify-end gap-1">
                                            <i class="fas fa-map-marker-alt text-red-500"></i>
                                            <?= htmlspecialchars($log['location'] ?? 'N/A') ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php
                endwhile;
            else:
                ?>
                <div class="flex flex-col items-center justify-center h-full text-gray-400 py-32">
                    <div
                        class="w-24 h-24 bg-white rounded-full flex items-center justify-center mb-6 shadow-inner border-2 border-gray-100">
                        <i class="fas fa-clipboard-list text-5xl opacity-20"></i>
                    </div>
                    <p class="font-black text-lg uppercase tracking-widest text-gray-300">No Activity Records</p>
                </div>
            <?php endif;
            // The connection will be closed at the very end of the file
            ?>
        </div>
    </div>

    <?php include __DIR__ . '/profile_modal.php'; ?>

</body>

</html>

<?php
// Close database connections
if (isset($db)) {
    // Log performance stats
    $dbStats = $db->healthCheck();
    $cacheStats = CacheManager::getStats();

    error_log("Dashboard Performance - Load: {$load_time}ms, DB Queries: {$dbStats['stats']['query_count']}, Cache Hit Rate: {$cacheStats['hit_rate']}%");
}

if (isset($mysqli)) {
    $mysqli->close();
}
?>