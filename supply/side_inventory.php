<?php
// Supply Dashboard - Side Content
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/session_helper.php';
start_user_session();

// Security Check - Only allow supply role
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'supply') {
    if (isset($_GET['ajax']) || isset($_POST['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Session expired. Please login again.']);
        exit;
    }
    header('Location: ../index.php?login');
    exit();
}

// ✅ AJAX handler for specific item logs (ABSOLUTE TOP TO PREVENT INTERFERENCE)
if (isset($_GET['fetch_item_logs']) && isset($_GET['item_id'])) {
    if (ob_get_length())
        ob_clean();
    header('Content-Type: application/json');
    require_once __DIR__ . '/../db_connect.php';
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

// Fetch all activity logs for drawer
if (isset($_GET['fetch_all_logs'])) {
    if (ob_get_length())
        ob_clean();
    header('Content-Type: application/json');
    require_once __DIR__ . '/../db_connect.php';

    $user_location = 'Mamburao';
    if (!empty($_SESSION['user_id'])) {
        $user_id = $_SESSION['user_id'];
        $user_query = $mysqli->prepare("SELECT location FROM users WHERE id = ?");
        $user_query->bind_param("i", $user_id);
        $user_query->execute();
        $user_result = $user_query->get_result();
        if ($user_result && $user_result->num_rows > 0) {
            $user_data = $user_result->fetch_assoc();
            $user_location = $user_data['location'] ?? 'Mamburao';
        }
        $user_query->close();
    }

    $logs = [];
    $stmt = $mysqli->prepare("SELECT id, inventory_id, action_type, item_name, quantity_changed, performed_by, location, date_time FROM inventory_activity_log WHERE location = ? ORDER BY date_time DESC LIMIT 50");
    $stmt->bind_param("s", $user_location);
    $stmt->execute();
    $log_res = $stmt->get_result();

    while ($row = $log_res->fetch_assoc()) {
        $row['formatted_date'] = date('M d, Y h:i A', strtotime($row['date_time']));
        $logs[] = $row;
    }
    $stmt->close();
    echo json_encode(['success' => true, 'logs' => $logs, 'location' => $user_location]);
    exit;
}

require_once __DIR__ . '/../db_connect.php';

error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

function getCachedData($key, $callback, $ttl = 60)
{
    // Adjusted to root cache for shared inventory data
    $cacheDir = __DIR__ . '/../cache/';
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }
    $cacheFile = $cacheDir . md5($key) . '.cache';

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
        return unserialize(file_get_contents($cacheFile));
    }

    $data = $callback();
    file_put_contents($cacheFile, serialize($data));
    return $data;
}

function clearInventoryCache()
{
    $cacheDir = __DIR__ . '/../cache/';
    if (is_dir($cacheDir)) {
        array_map('unlink', glob($cacheDir . "inventory_*.cache"));
    }
}

// ✅ DATA FETCHING & POST HANDLERS BEFORE ANY HTML OUTPUT
$user_location = 'Mamburao';
$user_id = $_SESSION['user_id'];

$user_query = $mysqli->prepare("SELECT location, username FROM users WHERE id = ?");
$user_query->bind_param("i", $user_id);
$user_query->execute();
$user_result = $user_query->get_result();

if ($user_result && $user_result->num_rows > 0) {
    $user_data = $user_result->fetch_assoc();
    $user_location = $user_data['location'] ?? 'Mamburao';
    $performed_by = $user_data['username'] ?? 'Admin';
} else {
    $performed_by = $_SESSION['username'] ?? 'Admin';
}
$user_query->close();

// ✅ Handle add item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_item'])) {
    $category = trim($_POST['category']);
    $item = trim($_POST['item']);
    $model_no = trim($_POST['model_no']);
    $allocation = $user_location;
    $status = trim($_POST['status']);

    if ($category === 'Other' && isset($_POST['other_category']) && !empty(trim($_POST['other_category']))) {
        $category = trim($_POST['other_category']);
    }

    $stmt = $mysqli->prepare("INSERT INTO inventory (category, item, model_no, allocation, status, date_added) VALUES (?, ?, ?, ?, ?, NOW())");
    $stmt->bind_param("sssss", $category, $item, $model_no, $allocation, $status);
    $stmt->execute();
    $new_item_id = $stmt->insert_id;
    $stmt->close();

    $log_stmt = $mysqli->prepare("INSERT INTO inventory_activity_log (inventory_id, action_type, item_name, quantity_changed, performed_by, location, date_time) VALUES (?, 'ADDED', ?, 1, ?, ?, NOW())");
    $log_stmt->bind_param("isss", $new_item_id, $item, $performed_by, $allocation);
    $log_stmt->execute();
    $log_stmt->close();

    clearInventoryCache();

    if (isset($_POST['ajax'])) {
        if (ob_get_length())
            ob_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'New inventory item added successfully!']);
        exit;
    }

    echo "<script>alert('New inventory item added successfully!'); window.location.href=window.location.href;</script>";
    exit;
}

// ✅ Handle add activity log (With Transaction & Business Validation)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_activity_log'])) {
    $item_ids = $_POST['log_inventory_id'] ?? [];
    $log_action_type = strtoupper(trim($_POST['log_action_type'] ?? ''));
    $log_performed_by = trim($_POST['log_performed_by'] ?? '');

    if (empty($item_ids) || empty($log_action_type) || empty($log_performed_by)) {
        if (isset($_POST['ajax'])) {
            if (ob_get_length())
                ob_clean();
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Please fill in all required fields!',
                'debug' => ['item_ids' => $item_ids, 'action_type' => $log_action_type, 'performed_by' => $log_performed_by]
            ]);
            exit;
        }
        echo "<script>alert('Please fill in all required fields!'); window.location.href=window.location.href;</script>";
        exit;
    }

    // Ensure item_ids is an array
    if (!is_array($item_ids)) {
        $item_ids = [$item_ids];
    }

    // Filter out empty values
    $item_ids = array_filter($item_ids, function ($val) {
        return !empty($val);
    });

    if (empty($item_ids)) {
        if (isset($_POST['ajax'])) {
            if (ob_get_length())
                ob_clean();
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Please select at least one item!']);
            exit;
        }
        echo "<script>alert('Please select at least one item.'); window.location.href=window.location.href;</script>";
        exit;
    }

    $unit_qty = ($log_action_type === 'ISSUED') ? -1 : 1;
    $success_count = 0;
    $errors = [];

    $mysqli->begin_transaction();

    try {
        // Fetch all items with FOR UPDATE lock
        $placeholders = implode(',', array_fill(0, count($item_ids), '?'));
        $types = str_repeat('i', count($item_ids));
        $item_stmt = $mysqli->prepare("SELECT id, item, allocation, log_stats FROM inventory WHERE id IN ($placeholders) FOR UPDATE");
        $item_stmt->bind_param($types, ...$item_ids);
        $item_stmt->execute();
        $item_result = $item_stmt->get_result();

        $itemMap = [];
        while ($row = $item_result->fetch_assoc()) {
            $itemMap[$row['id']] = $row;
        }
        $item_stmt->close();

        // Process each item
        foreach ($item_ids as $id) {
            if (empty($id) || !isset($itemMap[$id]))
                continue;

            $item_data = $itemMap[$id];
            $log_inventory_id = intval($id);
            $item_name = $item_data['item'] ?? 'Unknown';
            $item_location = $item_data['allocation'] ?? $user_location;
            $item_log_stats = $item_data['log_stats'] ?? '';

            // Business validation
            $is_currently_borrowed = (strpos($item_log_stats, 'BORROWED') !== false);
            if ($log_action_type === 'ISSUED' && $is_currently_borrowed) {
                throw new Exception("Item \"$item_name\" is already borrowed.");
            }
            if ($log_action_type === 'RETURNED' && !$is_currently_borrowed) {
                throw new Exception("Item \"$item_name\" is not currently borrowed.");
            }

            // Insert activity log
            $log_stmt = $mysqli->prepare("INSERT INTO inventory_activity_log (inventory_id, action_type, item_name, quantity_changed, performed_by, location, date_time) VALUES (?, ?, ?, ?, ?, ?, NOW())");
            $log_stmt->bind_param("isssss", $log_inventory_id, $log_action_type, $item_name, $unit_qty, $log_performed_by, $item_location);
            if (!$log_stmt->execute()) {
                throw new Exception("Failed to insert log for item: $item_name");
            }
            $log_stmt->close();

            // Update inventory status
            if ($log_action_type === 'ISSUED') {
                $status_label = "BORROWED BY: " . $log_performed_by;
                $update_inv = $mysqli->prepare("UPDATE inventory SET log_stats = ?, borrowed_date = NOW(), returned_date = NULL WHERE id = ?");
                $update_inv->bind_param("si", $status_label, $log_inventory_id);
            } else {
                $update_inv = $mysqli->prepare("UPDATE inventory SET log_stats = 'AVAILABLE', returned_date = NOW() WHERE id = ?");
                $update_inv->bind_param("i", $log_inventory_id);
            }
            if (!$update_inv->execute()) {
                throw new Exception("Failed to update inventory for item: $item_name");
            }
            $update_inv->close();

            $success_count++;
        }

        $mysqli->commit();

        if (isset($_POST['ajax'])) {
            if (ob_get_length())
                ob_clean();
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => "Successfully processed $success_count items."]);
            exit;
        }

        clearInventoryCache();
        echo "<script>alert('Activity logs added successfully!'); window.location.href=window.location.href;</script>";
        exit;

    } catch (Exception $e) {
        $mysqli->rollback();
        $error_msg = $e->getMessage();

        // Log detailed error for debugging
        error_log("Add Activity Log Error: " . $error_msg . " | Item IDs: " . implode(',', $item_ids) . " | Action: " . $log_action_type);

        if (isset($_POST['ajax'])) {
            if (ob_get_length())
                ob_clean();
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => $error_msg,
                'debug' => ['item_ids' => $item_ids, 'action_type' => $log_action_type]
            ]);
            exit;
        }

        echo "<script>alert('Error: " . addslashes($error_msg) . "'); window.location.href=window.location.href;</script>";
        exit;
    }
}

// ✅ Handle edit item update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_item'])) {
    $id = intval($_POST['edit_id']);
    $category = trim($_POST['edit_category'] ?? '');
    $item = trim($_POST['edit_item'] ?? '');
    $model_no = trim($_POST['edit_model_no'] ?? '');
    $allocation = trim($_POST['edit_allocation'] ?? '');
    $status = trim($_POST['edit_status'] ?? '');

    // Check if "Other" category is selected and a custom category is provided
    if ($category === 'Other' && isset($_POST['edit_other_category']) && !empty(trim($_POST['edit_other_category']))) {
        $category = trim($_POST['edit_other_category']);
    }

    $stmt = $mysqli->prepare("UPDATE inventory SET category=?, item=?, model_no=?, allocation=?, status=? WHERE id=?");
    $stmt->bind_param("sssssi", $category, $item, $model_no, $allocation, $status, $id);
    $stmt->execute();
    $stmt->close();

    // ✅ Log activity: Item Updated
    $log_stmt = $mysqli->prepare("INSERT INTO inventory_activity_log (inventory_id, action_type, item_name, quantity_changed, performed_by, location, date_time) VALUES (?, 'UPDATED', ?, 0, ?, ?, NOW())");
    $log_stmt->bind_param("isss", $id, $item, $performed_by, $allocation);
    $log_stmt->execute();
    $log_stmt->close();

    clearInventoryCache();

    if (isset($_POST['ajax'])) {
        if (ob_get_length())
            ob_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Item updated successfully!']);
        exit;
    }

    echo "<script>alert('Item updated successfully!'); window.location.href=window.location.href;</script>";
    exit;
}

include_once __DIR__ . '/../profile_modal.php';

// ✅ Combined Query: Fetch items with specific columns + stats + categories
$cacheKey = "inventory_{$user_location}";
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 50;
$offset = ($page - 1) * $limit;

$data = getCachedData($cacheKey . "_{$page}", function () use ($mysqli, $user_location, $limit, $offset) {
    $inventoryData = [];

    // Fetch items with specific columns + pagination
    $stmt = $mysqli->prepare("SELECT id, category, item, model_no, allocation, status, log_stats, date_added, borrowed_date, returned_date FROM inventory WHERE allocation = ? ORDER BY id DESC LIMIT ? OFFSET ?");
    $stmt->bind_param("sii", $user_location, $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $inventoryData['items'][] = $row;
    }
    $stmt->close();

    // Get total count
    $count_stmt = $mysqli->prepare("SELECT COUNT(*) as total FROM inventory WHERE allocation = ?");
    $count_stmt->bind_param("s", $user_location);
    $count_stmt->execute();
    $inventoryData['total'] = $count_stmt->get_result()->fetch_assoc()['total'] ?? 0;
    $count_stmt->close();

    // Fetch distinct categories
    $cat_stmt = $mysqli->prepare("SELECT DISTINCT category FROM inventory WHERE allocation = ? AND category IS NOT NULL AND category != '' ORDER BY category ASC");
    $cat_stmt->bind_param("s", $user_location);
    $cat_stmt->execute();
    $cat_result = $cat_stmt->get_result();
    $inventoryData['categories'] = [];
    while ($cat_row = $cat_result->fetch_assoc()) {
        $inventoryData['categories'][] = $cat_row['category'];
    }
    $cat_stmt->close();

    // Fetch distinct item names
    $item_stmt = $mysqli->prepare("SELECT DISTINCT item FROM inventory WHERE allocation = ? AND item IS NOT NULL AND item != '' ORDER BY item ASC");
    $item_stmt->bind_param("s", $user_location);
    $item_stmt->execute();
    $item_result = $item_stmt->get_result();
    $inventoryData['item_names'] = [];
    while ($item_row = $item_result->fetch_assoc()) {
        $inventoryData['item_names'][] = $item_row['item'];
    }
    $item_stmt->close();

    // Fetch activity logs
    $log_stmt = $mysqli->prepare("SELECT id, inventory_id, action_type, item_name, quantity_changed, performed_by, date_time FROM inventory_activity_log WHERE location = ? ORDER BY date_time DESC LIMIT 20");
    $log_stmt->bind_param("s", $user_location);
    $log_stmt->execute();
    $log_result = $log_stmt->get_result();
    $inventoryData['activity_logs'] = [];
    while ($log = $log_result->fetch_assoc()) {
        $inventoryData['activity_logs'][] = $log;
    }
    $log_stmt->close();

    // Combined stats query
    $stats_stmt = $mysqli->prepare("
        SELECT 
            (SELECT COUNT(*) FROM inventory WHERE allocation = ? AND log_stats LIKE 'BORROWED%') as borrowed,
            (SELECT COUNT(*) FROM inventory WHERE allocation = ? AND (log_stats = 'AVAILABLE' OR log_stats IS NULL OR log_stats = '')) as available,
            (SELECT COUNT(*) FROM inventory_activity_log WHERE location = ? AND action_type = 'ISSUED') as total_borrowed,
            (SELECT COUNT(*) FROM inventory_activity_log WHERE location = ? AND action_type = 'RETURNED') as total_returned
    ");
    $stats_stmt->bind_param("ssss", $user_location, $user_location, $user_location, $user_location);
    $stats_stmt->execute();
    $stats_result = $stats_stmt->get_result()->fetch_assoc();
    $inventoryData['stats'] = [
        'borrowed' => $stats_result['borrowed'] ?? 0,
        'available' => $stats_result['available'] ?? 0,
        'total_borrowed' => $stats_result['total_borrowed'] ?? 0,
        'total_returned' => $stats_result['total_returned'] ?? 0
    ];
    $stats_stmt->close();

    return $inventoryData;
}, 60);

$items = $data['items'] ?? [];
$unique_categories = $data['categories'] ?? [];
$unique_item_names = $data['item_names'] ?? [];
$activity_logs = $data['activity_logs'] ?? [];
$current_borrowed = $data['stats']['borrowed'] ?? 0;
$current_available = $data['stats']['available'] ?? 0;
$total_borrowed = $data['stats']['total_borrowed'] ?? 0;
$total_returned = $data['stats']['total_returned'] ?? 0;
$total_items = $data['total'] ?? 0;
?>

<style>
    .hidden {
        display: none !important;
    }

    /* OPTION 3: Animated Gradient with Grid Texture */



    /* Tailwind-like utility classes */
    .glass-card {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255, 255, 255, 0.2);
    }

    .hover-row:hover {
        background-color: rgba(59, 130, 246, 0.05);
    }

    .search-input:focus {
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }

    .action-btn {
        transition: all 0.2s ease;
        height: 48px;
        /* Explicit height to match filters */
    }

    .action-btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
    }

    .table-scroll-container {
        max-height: 800px;
        overflow-y: auto;
        overflow-x: hidden;
        /* Remove horizontal scrollbar */
        scrollbar-width: thin;
        border-radius: 0 0 12px 12px;
    }

    /* Professional Table Styles */
    #inventoryTable {
        border-collapse: separate;
        border-spacing: 0;
        width: 100%;
    }

    #inventoryTable thead th {
        background: #f8fafc;
        color: #475569;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        font-size: 11px;
        padding: 16px 24px;
        border-bottom: 1px solid #e2e8f0;
        white-space: nowrap;
        position: sticky;
        top: 0;
        z-index: 20;
    }

    .inventory-row {
        transition: all 0.2s ease;
    }

    .inventory-row:hover {
        background-color: #f1f5f9 !important;
    }

    .inventory-row td {
        padding: 16px 24px;
        vertical-align: middle;
        border-bottom: 1px solid #f1f5f9;
        color: #1e293b;
        font-size: 14px;
    }

    /* Modern Status Badges */
    .status-badge {
        display: inline-flex;
        align-items: center;
        padding: 4px 12px;
        border-radius: 8px;
        font-size: 10px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        border-width: 1px;
    }

    .status-badge.good {
        background: #ecfdf5;
        color: #065f46;
        border-color: #a7f3d0;
    }

    .status-badge.low {
        background: #fffbeb;
        color: #92400e;
        border-color: #fde68a;
    }

    .status-badge.worn_out {
        background: #fef2f2;
        color: #991b1b;
        border-color: #fecaca;
    }

    /* Compact Edit Button */
    .edit-action-btn {
        padding: 8px 16px;
        border-radius: 10px;
        font-weight: 700;
        font-size: 12px;
        transition: all 0.2s;
        border: 1px solid #e2e8f0;
        background: white;
        color: #475569;
    }

    .edit-action-btn:hover {
        background: #2563eb;
        color: white;
        border-color: #2563eb;
        transform: translateY(-1px);
        box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.1);
    }

    .logs-action-btn {
        padding: 8px 16px;
        border-radius: 10px;
        font-weight: 700;
        font-size: 12px;
        transition: all 0.2s;
        border: 1px solid #0d6efd;
        background: white;
        color: #0d6efd;
    }

    .logs-action-btn:hover {
        background: #0d6efd;
        color: white;
        border-color: #0d6efd;
        transform: translateY(-1px);
        box-shadow: 0 4px 6px -1px rgba(13, 110, 253, 0.1);
    }

    .filter-select {
        min-width: 140px;
        transition: all 0.2s ease;
        font-weight: 600;
        font-size: 14px;
        height: 48px;
        /* Match action-btn height */
        padding-top: 0 !important;
        padding-bottom: 0 !important;
    }

    /* Layout Update */
    .inventory-wrapper {
        display: block;
        padding: 4px;
        position: relative;
    }

    .count-box {
        background: #1e293b;
        color: white;
        padding: 12px 24px;
        border-radius: 14px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        border: 1px solid rgba(255, 255, 255, 0.1);
        min-width: 120px;
        transition: transform 0.2s ease;
    }

    .count-box.borrowed {
        background: #991b1b;
        /* Dark red for borrowed items */
    }

    .count-box.returned {
        background: #166534;
        /* Dark green for returned items */
    }

    .count-box:hover {
        transform: translateY(-2px);
    }

    .count-number {
        font-size: 28px;
        font-weight: 800;
        line-height: 1;
    }

    .count-label {
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        margin-top: 4px;
        color: #94a3b8;
    }

    .inventory-table-section {
        width: 100%;
        transition: all 0.3s ease;
    }

    /* Drawer System */
    .log-drawer {
        position: fixed;
        top: 0;
        right: -100% !important;
        width: 100%;
        max-width: 500px;
        height: 100vh;
        background: white;
        z-index: 99999 !important;
        box-shadow: -10px 0 40px rgba(0, 0, 0, 0.3);
        transition: right 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        display: flex !important;
        flex-direction: column;
        overflow: visible !important;
    }

    .log-drawer.open {
        right: 0 !important;
    }

    .drawer-backdrop.show {
        display: block !important;
        opacity: 1 !important;
    }

    #logDrawer + .drawer-backdrop {
        position: fixed;
        top: 0;
        left: 0;
        width: 100vw;
        height: 100vh;
        background: rgba(15, 23, 42, 0.75);
        backdrop-filter: blur(10px);
        z-index: 99998 !important;
        display: none;
        opacity: 0;
        transition: opacity 0.3s ease;
    }

    .drawer-backdrop.show {
        display: block;
        opacity: 1;
    }

    .blur-content {
        filter: blur(8px);
        transition: filter 0.3s ease;
        pointer-events: none;
    }

    /* Modal z-index for inventory modals only */
    #addModal, #addLogModal, #editModal, #itemHistoryModal {
        z-index: 100001 !important;
    }

    /* Activity Log Panel - Dark Theme */
    .activity-log-card {
        background: white;
        height: 100%;
        display: flex;
        flex-direction: column;
        border: none;
        border-radius: 0;
    }

    .activity-log-header {
        padding: 16px 20px;
        background: #1e293b;
        border-bottom: 1px solid #334155;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .activity-log-header h5 {
        margin: 0;
        font-size: 15px;
        font-weight: 800;
        color: #ffffff;
        display: flex;
        align-items: center;
        gap: 10px;
        text-transform: uppercase;
    }

    .drawer-close-btn {
        width: 38px;
        height: 38px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #94a3b8;
        cursor: pointer;
        transition: all 0.2s;
        border: 1px solid #475569;
        background: #334155;
    }

    .drawer-close-btn:hover {
        background: #ef4444;
        color: white;
        border-color: #ef4444;
    }

    .activity-log-body {
        overflow-y: auto;
        padding: 15px;
        position: relative;
    }

    /* Timeline Connector */
    .activity-log-body::before {
        content: "";
        position: absolute;
        top: 0;
        left: 33px;
        bottom: 0;
        width: 2px;
        background: #f1f5f9;
        z-index: 0;
    }

    .activity-item {
        position: relative;
        display: flex;
        align-items: flex-start;
        padding: 12px 0;
        transition: all 0.3s ease;
        z-index: 1;
    }

    .activity-item:hover {
        transform: translateX(4px);
    }

    .activity-icon {
        width: 32px;
        height: 32px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-right: 12px;
        flex-shrink: 0;
        font-size: 12px;
        background: white;
        border: 2px solid white;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
    }

    .activity-icon.added {
        background: #ecfdf5;
        color: #059669;
        border-color: #d1fae5;
    }

    .activity-icon.updated {
        background: #eff6ff;
        color: #2563eb;
        border-color: #dbeafe;
    }

    .activity-icon.issued {
        background: #fff7ed;
        color: #ea580c;
        border-color: #ffedd5;
    }

    .activity-icon.returned {
        background: #fdf2f8;
        color: #db2777;
        border-color: #fce7f3;
    }

    .activity-content {
        flex: 1;
        min-width: 0;
        padding-bottom: 8px;
        border-bottom: 1px solid #f8fafc;
    }

    .activity-title {
        font-size: 14px;
        font-weight: 700;
        color: #0f172a;
        margin-bottom: 4px;
    }

    .activity-meta {
        font-size: 12px;
        color: #475569;
        display: flex;
        align-items: center;
        gap: 8px;
        font-weight: 600;
    }

    .activity-quantity {
        font-size: 11px;
        font-weight: 800;
        padding: 2px 8px;
        border-radius: 9999px;
    }

    .activity-quantity.positive {
        background: #d1fae5;
        color: #065f46;
    }

    .activity-quantity.negative {
        background: #fee2e2;
        color: #991b1b;
    }

    .activity-empty {
        padding: 40px 20px;
        text-align: center;
    }

    .activity-empty i {
        font-size: 48px;
        color: #e2e8f0;
        margin-bottom: 16px;
    }

    .activity-empty p {
        color: #94a3b8;
        font-size: 14px;
        font-weight: 500;
    }

    @media (max-width: 1200px) {
        .inventory-wrapper {
            flex-direction: column;
        }

        .inventory-table-section,
        .activity-log-section {
            flex: 0 0 100%;
            width: 100%;
        }

        .activity-log-section {
            position: static;
        }
    }

    @media (max-width: 768px) {
        .log-drawer {
            max-width: 100%;
        }
    }
</style>

<div class="inventory-wrapper">
    <!-- Inventory Table Section (Left - 65%) -->
    <div class="inventory-table-section">
        <div class="card p-3 shadow-sm">
            <!-- Table Section -->
            <div class="bg-white rounded-xl shadow-sm overflow-hidden">
                <!-- Table Header -->
                <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
                    <div class="flex justify-between items-center">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-800">INVENTORY</h2>
                            <p class="text-gray-600 text-sm mt-1">This section manages all inventory items for your
                                location.
                            </p>
                        </div>
                        <div class="flex items-center space-x-4">
                            <span class="text-sm text-gray-600" id="resultCountDisplay">
                                <?php echo $total_items; ?> items found
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Search and Filter Bar -->
                <div class="px-6 py-5 border-b border-gray-200 bg-gray-50 space-y-4">
                    <!-- Top Row: Full-width Search Bar -->
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-search text-lg text-gray-400"></i>
                        </div>
                        <input type="text" id="searchInput"
                            class="w-full pl-10 pr-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 shadow-sm transition duration-200"
                            placeholder="Search by Item name, Category, or Model No...">
                    </div>

                    <!-- Bottom Row: Filters and Action Buttons (Right) -->
                    <div class="flex flex-col md:flex-row justify-between items-center gap-4">
                        <!-- Left side empty for search -->
                        <div class="flex items-center gap-4">
                            <!-- Total Count Box -->
                            <div class="count-box">
                                <span class="count-number" id="totalCountBox">0</span>
                                <span class="count-label">QUANTITY</span>
                            </div>

                            <!-- Borrowed & Returned Summary Stats -->
                            <div class="flex items-center gap-6 ml-4">
                                <div class="flex items-center gap-2">
                                    <i class="fas fa-arrow-up text-red-500 text-xs"></i>
                                    <span
                                        class="text-xs font-bold text-slate-500 uppercase tracking-wider">Borrowed:</span>
                                    <span class="text-sm font-black text-slate-900"
                                        id="borrowedCountBox"><?php echo $current_borrowed; ?></span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <i class="fas fa-arrow-down text-green-500 text-xs"></i>
                                    <span
                                        class="text-xs font-bold text-slate-500 uppercase tracking-wider">Available:</span>
                                    <span class="text-sm font-black text-slate-900"
                                        id="returnedCountBox"><?php echo $current_available; ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- Filters and Action Button Group -->
                        <div class="flex flex-wrap gap-2 items-center">
                            <!-- Status Filter -->
                            <select
                                class="filter-select px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-200 bg-white font-bold text-sm"
                                id="statusFilter">
                                <option value="">ALL STATUS</option>
                                <option value="GOOD">GOOD</option>
                                <option value="LOW">LOW</option>
                                <option value="WORN_OUT">WORN OUT</option>
                                <option value="OUT_OF_STOCK">OUT OF STOCK</option>
                            </select>

                            <!-- Item Name Filter (Dynamic) -->
                            <select
                                class="filter-select px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-200 bg-white font-bold text-sm"
                                id="itemNameFilter">
                                <option value="">ALL ITEMS</option>
                                <?php foreach ($unique_item_names as $itemName): ?>
                                    <option value="<?= htmlspecialchars(strtolower(trim($itemName))) ?>">
                                        <?= strtoupper(htmlspecialchars($itemName)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <!-- Category Filter (Dynamic) -->
                            <select
                                class="filter-select px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-200 bg-white font-bold text-sm"
                                id="categoryFilter">
                                <option value="">ALL CATEGORIES</option>
                                <?php foreach ($unique_categories as $cat): ?>
                                    <option value="<?= htmlspecialchars(strtolower(trim($cat))) ?>">
                                        <?= strtoupper(htmlspecialchars($cat)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <!-- Clear Filters Button -->
                            <button id="clearFilters"
                                class="action-btn border border-gray-300 hover:bg-gray-100 text-gray-700 px-4 py-2 rounded-lg font-bold flex items-center justify-center transition duration-200 bg-white text-sm">
                                <i class="fas fa-undo-alt mr-2"></i>
                                Reset
                            </button>

                            <button id="openLogDrawer"
                                class="action-btn bg-slate-800 hover:bg-slate-900 text-white px-4 py-2 rounded-lg font-bold flex items-center justify-center transition duration-200 shadow-md text-sm">
                                <i class="fas fa-history mr-2"></i>
                                VIEW LOGS
                            </button>
                            <button
                                class="action-btn bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-bold flex items-center justify-center transition duration-200 shadow-md text-sm"
                                data-bs-toggle="modal" data-bs-target="#addModal">
                                <i class="fas fa-plus-circle mr-2"></i>
                                ADD NEW ITEM
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Inventory Table -->
                <div class="table-scroll-container overflow-auto max-h-[800px]">
                    <table class="min-w-full divide-y divide-gray-200" id="inventoryTable">
                        <thead class="sticky-header bg-gray-50">
                            <tr>
                                <th scope="col"
                                    class="px-6 py-3 text-left text-xs font-large text-black-500 uppercase tracking-wider">
                                    #
                                </th>
                                <th scope="col"
                                    class="px-6 py-3 text-left text-xs font-large text-black-500 uppercase tracking-wider">
                                    CATEGORY</th>
                                <th scope="col"
                                    class="px-6 py-3 text-left text-xs font-large text-black-500 uppercase tracking-wider">
                                    ITEM
                                    NAME</th>
                                <th scope="col"
                                    class="px-6 py-3 text-left text-xs font-large text-black-500 uppercase tracking-wider">
                                    MODEL NO.</th>
                                <th class="px-2 py-3 text-center w-8"></th>
                                <th scope="col"
                                    class="px-6 py-3 text-left text-xs font-large text-black-500 uppercase tracking-wider">
                                    STATUS</th>
                                <th scope="col"
                                    class="px-6 py-3 text-left text-xs font-large text-black-500 uppercase tracking-wider">
                                    DATE ADDED</th>
                                <th scope="col"
                                    class="px-6 py-3 text-left text-xs font-large text-black-500 uppercase tracking-wider">
                                    ACTIONS</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white" id="inventoryTableBody">
                            <?php
                            $counter = 1;
                            $items = $data['items'] ?? [];
                            if (!empty($items)):
                                foreach ($items as $part):
                                    $rawStatus = trim($part['status']);
                                    $is_borrowed = (strpos(($part['log_stats'] ?? ''), 'BORROWED') === 0);

                                    // Determine Status Display and Styling
                                    $statusText = 'GOOD';
                                    $statusColor = 'text-emerald-600';

                                    if ($rawStatus === 'LOW') {
                                        $statusText = 'LOW';
                                        $statusColor = 'text-amber-600';
                                    } elseif ($rawStatus === 'WORN_OUT' || $rawStatus === 'WORN OUT') {
                                        $statusText = 'WORN OUT';
                                        $statusColor = 'text-rose-600';
                                    } elseif ($rawStatus === 'OUT_OF_STOCK' || $rawStatus === 'OUT OF STOCK') {
                                        $statusText = 'OUT OF STOCK';
                                        $statusColor = 'text-gray-600';
                                    }
                                    ?>
                                    <tr class="inventory-row border-b border-slate-50" data-id="<?= $part['id'] ?>"
                                        data-borrowed="<?= $is_borrowed ? '1' : '0' ?>">
                                        <td class="px-6 py-4">
                                            <span
                                                class="text-lg font-bold text-slate-700 max-w-[200px] truncate">#<?= $counter++ ?></span>
                                        </td>
                                        <td class="px-6 py-4">
                                            <span class="text-md font-bold text-slate-700 max-w-[200px] truncate">
                                                <?= strtoupper(htmlspecialchars($part['category'] ?? 'Uncategorized')) ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="text-md font-bold text-slate-700 max-w-[200px] truncate"
                                                title="<?= htmlspecialchars($part['item']) ?>">
                                                <?= htmlspecialchars($part['item']) ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="text-md font-bold text-slate-700 max-w-[200px] truncate">
                                                <?= htmlspecialchars($part['model_no']) ?>
                                            </div>
                                        </td>
                                        <td class="px-2 py-4 text-center">
                                            <?php if ($is_borrowed): ?>
                                                <i class="fas fa-arrow-up text-red-500" title="Borrowed"></i>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="text-[11px] font-black uppercase tracking-widest <?= $statusColor ?>">
                                                <?= $statusText ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="text-md font-bold text-slate-700 max-w-[200px] truncate">
                                                <?= date('M d, Y', strtotime($part['date_added'])) ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="flex items-center justify-start gap-2">
                                                <button class="logs-action-btn view-history-btn shadow-sm"
                                                    data-id="<?= $part['id'] ?>"
                                                    data-name="<?= htmlspecialchars($part['item']) ?>">
                                                    <i class="fas fa-history mr-1.5"></i> Logs
                                                </button>
                                                <button class="editBtn edit-action-btn shadow-sm" data-id="<?= $part['id'] ?>"
                                                    data-category="<?= htmlspecialchars($part['category'] ?? '') ?>"
                                                    data-item="<?= htmlspecialchars($part['item']) ?>"
                                                    data-model="<?= htmlspecialchars($part['model_no']) ?>"
                                                    data-allocation="<?= htmlspecialchars($part['allocation']) ?>"
                                                    data-status="<?= htmlspecialchars($part['status']) ?>">
                                                    <i class="fas fa-pen-nib mr-1.5"></i> Edit
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php
                                endforeach;
                            else:
                                ?>
                                <tr>
                                    <td colspan="7" class="px-6 py-12 text-center bg-slate-50/50">
                                        <div class="flex flex-col items-center">
                                            <div
                                                class="w-16 h-16 bg-slate-100 rounded-full flex items-center justify-center mb-4">
                                                <i class="fas fa-box-open text-2xl text-slate-300"></i>
                                            </div>
                                            <h3 class="text-lg font-bold text-slate-800">No inventory found</h3>
                                            <p class="text-sm text-slate-500 mb-6">Start by adding your first item to the
                                                inventory.</p>
                                            <button
                                                class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2.5 rounded-xl font-bold transition-all transform hover:-translate-y-0.5"
                                                data-bs-toggle="modal" data-bs-target="#addModal">
                                                <i class="fas fa-plus-circle mr-2"></i> Add Item
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Footer -->
                <div class="bg-gray-50 px-6 py-3 border-t border-gray-200">
                    <div class="flex items-center justify-between text-sm text-gray-600">
                        <div class="flex items-center">
                            <i class="fas fa-map-marker-alt mr-2"></i>
                            <span>Location: <span
                                    class="font-semibold text-blue-600"><?php echo htmlspecialchars($user_location); ?></span></span>
                        </div>
                        <div>
                            <span class="text-sm text-gray-600" id="filteredCount">
                                Showing <?php echo count($items); ?> of <?php echo $total_items; ?> items
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Log Drawer System -->
    <div class="drawer-backdrop" id="drawerBackdrop"></div>
    <div class="log-drawer" id="logDrawer">
        <!-- Dark Themed Header -->
        <div class="p-4 p-md-5 bg-slate-800 text-white shadow-lg border-b border-slate-700">
            <div class="flex items-center gap-3">
                <div class="w-12 h-12 bg-white/10 rounded-xl flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-history text-xl"></i>
                </div>
                <div>
                    <h6 class="font-black text-base uppercase tracking-wider mb-0">Activity History</h6>
                    <p class="text-xs text-slate-300 mb-0 font-medium">Real-time inventory logs.</p>
                </div>
            </div>
        </div>

        <!-- Activity Log Content -->
        <div class="activity-log-card">
            <div class="activity-log-header bg-slate-800">
                <h5 class="text-white"><i class="fas fa-list-ul"></i> Records</h5>
                <div class="flex items-center gap-3">
                    <button class="btn btn-primary py-2.5 px-5 font-bold text-sm shadow-lg" data-bs-toggle="modal"
                        data-bs-target="#addLogModal">
                        <i class="fas fa-plus-circle mr-2"></i> ADD LOG
                    </button>
                    <div class="drawer-close-btn bg-slate-700 border-slate-600 text-white hover:bg-red-600 hover:border-red-600"
                        id="closeLogDrawer">
                        <i class="fas fa-times"></i>
                    </div>
                </div>
            </div>
            <div class="activity-log-body" id="drawerLogsContainer">
                <div class="flex flex-col items-center justify-center py-12">
                    <i class="fas fa-spinner fa-spin text-2xl text-blue-500 mb-2"></i>
                    <p class="text-xs font-bold text-slate-400">Loading logs...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- ✅ Add Modal (Updated with Other Category Functionality) -->
    <div class="modal fade" id="addModal" tabindex="-1" aria-labelledby="addModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg">
                <form method="POST" id="addItemForm">
                    <!-- Modal Header -->
                    <div class="modal-header bg-blue-600 text-white py-4">
                        <h5 class="modal-title text-lg font-bold">
                            <i class="fas fa-box me-2"></i>Add Inventory Item
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>

                    <!-- Modal Body -->
                    <div class="modal-body p-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- Category -->
                            <div class="space-y-2">
                                <label class="block text-sm font-medium text-gray-700">Category</label>
                                <select name="category" id="categorySelect"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-200"
                                    required>
                                    <option value="" selected disabled>Select Category</option>
                                    <option value="Electronics">Electronics</option>
                                    <option value="Office Supplies">Office Supplies</option>
                                    <option value="Tools">Tools</option>
                                    <option value="Equipment">Equipment</option>
                                    <option value="Other">Other</option>
                                </select>

                                <!-- Other Category Input (Hidden by Default) -->
                                <div id="otherCategoryDiv" class="mt-3" style="display: none;">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Specify New
                                        Category</label>
                                    <input type="text" name="other_category" id="otherCategoryInput"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-200"
                                        placeholder="Enter new category name">
                                </div>
                            </div>

                            <!-- Item Name -->
                            <div class="space-y-2">
                                <label class="block text-sm font-medium text-gray-700">Item Name</label>
                                <input type="text" name="item"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-200"
                                    required placeholder="Enter item name">
                            </div>

                            <!-- Model No. -->
                            <div class="space-y-2">
                                <label class="block text-sm font-medium text-gray-700">Model No.</label>
                                <input type="text" name="model_no"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-200"
                                    required>
                            </div>

                            <!-- Allocation (Location) -->
                            <div class="space-y-2">
                                <label class="block text-sm font-medium text-gray-700">Allocation (Location)</label>
                                <div class="flex items-center space-x-2">
                                    <input type="text"
                                        class="flex-1 px-3 py-2 border border-gray-300 rounded-lg bg-gray-50 text-gray-700"
                                        value="<?php echo htmlspecialchars($user_location); ?>" readonly>
                                    <input type="hidden" name="allocation"
                                        value="<?php echo htmlspecialchars($user_location); ?>">
                                    <span class="bg-blue-100 text-blue-800 p-2 rounded-lg">
                                        <i class="fas fa-map-marker-alt"></i>
                                    </span>
                                </div>
                                <p class="text-xs text-gray-500 mt-1">
                                    This item will be assigned to your location
                                    (<?php echo htmlspecialchars($user_location); ?>)
                                </p>
                            </div>

                            <!-- Status -->
                            <div class="space-y-2">
                                <label class="block text-sm font-medium text-gray-700">Status</label>
                                <select name="status"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-200"
                                    required>
                                    <option value="GOOD">Good</option>
                                    <option value="LOW">Low</option>
                                    <option value="WORN_OUT">Worn Out</option>
                                    <option value="OUT_OF_STOCK">Out of Stock</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Modal Footer -->
                    <div class="modal-footer bg-gray-50 py-4 px-6 border-t border-gray-200">
                        <button type="button"
                            class="px-5 py-2.5 border border-gray-300 text-gray-700 rounded-lg font-medium hover:bg-gray-50 transition duration-200 flex items-center"
                            data-bs-dismiss="modal">
                            <i class="fas fa-times mr-2"></i> Cancel
                        </button>
                        <button type="submit" name="add_item"
                            class="px-5 py-2.5 bg-blue-600 text-white rounded-lg font-medium hover:bg-blue-700 transition duration-200 flex items-center">
                            <i class="fas fa-save mr-2"></i> Save Item
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>



    <!-- ✅ Add Activity Log Modal -->
    <div class="modal fade" id="addLogModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg">
                <form method="POST" id="addLogForm">
                    <!-- Modal Header -->
                    <div class="modal-header bg-success text-white py-3">
                        <h5 class="modal-title text-sm font-black uppercase tracking-wider">
                            <i class="fas fa-plus-circle mr-2"></i>Add Log
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>

                    <!-- Modal Body -->
                    <div class="modal-body p-4">
                        <!-- Dynamic Item Selection Container -->
                        <div class="mb-3">
                            <label class="text-xs font-bold text-slate-500 uppercase mb-2 block">Items</label>
                            <div id="logItemsContainer" class="space-y-2 mb-4 pr-1 overflow-y-auto"
                                style="max-height: 250px;">
                                <!-- Initial Item Row -->
                                <div
                                    class="log-item-row flex items-center gap-2 bg-slate-50 p-2 rounded-xl border border-slate-200">
                                    <div class="flex-1">
                                        <select name="log_inventory_id[]"
                                            class="form-select text-xs font-bold log-item-select" required>
                                            <option value="">Select...</option>
                                            <?php
                                            $items_stmt = $mysqli->prepare("SELECT id, item, model_no, category, log_stats FROM inventory WHERE allocation = ? ORDER BY item ASC");
                                            $items_stmt->bind_param("s", $user_location);
                                            $items_stmt->execute();
                                            $items_result = $items_stmt->get_result();
                                            while ($item_row = $items_result->fetch_assoc()):
                                                $curr_status = $item_row['log_stats'] ?? 'AVAILABLE';
                                                $is_borrowed = ($curr_status !== 'AVAILABLE');
                                                $display_status = $is_borrowed ? ' (BORROWED)' : ' (AVAILABLE)';
                                                ?>
                                                <option value="<?= $item_row['id'] ?>"
                                                    data-model="<?= htmlspecialchars($item_row['model_no']) ?>"
                                                    data-status="<?= $is_borrowed ? 'BORROWED' : 'AVAILABLE' ?>">
                                                    <?= htmlspecialchars($item_row['item']) . $display_status ?>
                                                </option>
                                                <?php
                                            endwhile;
                                            $items_stmt->close();
                                            ?>
                                        </select>
                                    </div>
                                    <button type="button"
                                        class="add-row-btn w-8 h-8 bg-blue-600 text-white rounded-lg flex items-center justify-center hover:bg-blue-700 transition-colors shadow-sm">
                                        <i class="fas fa-plus text-[10px]"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Action Type & Quantity -->
                        <div class="grid grid-cols-2 gap-3 mb-3">
                            <div>
                                <label class="text-xs font-bold text-slate-500 uppercase mb-1 block">Action</label>
                                <select name="log_action_type" id="log_action_type"
                                    class="form-select text-xs font-bold" required>
                                    <option value="ISSUED">BORROW/OUT</option>
                                    <option value="RETURNED">RETURN/IN</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-xs font-bold text-slate-500 uppercase mb-1 block">Total Qty</label>
                                <input type="number" name="log_quantity" id="log_quantity"
                                    class="form-control text-xs font-black bg-slate-50" readonly required>
                            </div>
                        </div>

                        <!-- Performed By -->
                        <div class="mb-1">
                            <label class="text-xs font-bold text-slate-500 uppercase mb-1 block">Performed By</label>
                            <input type="text" name="log_performed_by" class="form-control text-xs font-bold"
                                value="<?= htmlspecialchars($performed_by) ?>" required>
                        </div>
                    </div>

                    <!-- Modal Footer -->
                    <div class="modal-footer bg-light py-3">
                        <button type="button" class="btn btn-secondary text-xs font-bold"
                            data-bs-dismiss="modal">CANCEL</button>
                        <button type="submit" name="add_activity_log" class="btn btn-success text-xs font-bold px-4">
                            <i class="fas fa-save mr-1"></i> SAVE LOG
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ✅ Edit Modal (Updated with Other Category Functionality) -->
    <div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg">
                <form method="POST" id="editItemForm">
                    <!-- Modal Header -->
                    <div class="modal-header bg-blue-600 text-white py-4">
                        <h5 class="modal-title text-lg font-bold">
                            <i class="fas fa-edit me-2"></i>Edit Inventory Item
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>

                    <!-- Modal Body -->
                    <div class="modal-body p-6">
                        <input type="hidden" name="edit_id" id="edit_id">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- Category -->
                            <div class="space-y-2">
                                <label class="block text-sm font-medium text-gray-700">Category</label>
                                <select name="edit_category" id="edit_category"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-200"
                                    required>
                                    <option value="" disabled>Select Category</option>
                                    <option value="Electronics">Electronics</option>
                                    <option value="Office Supplies">Office Supplies</option>
                                    <option value="Tools">Tools</option>
                                    <option value="Equipment">Equipment</option>
                                    <option value="Other">Other</option>
                                </select>

                                <!-- Other Category Input (Hidden by Default) -->
                                <div id="editOtherCategoryDiv" class="mt-3" style="display: none;">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Specify New
                                        Category</label>
                                    <input type="text" name="edit_other_category" id="edit_other_category"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-200"
                                        placeholder="Enter new category name">
                                </div>
                            </div>

                            <!-- Item Name -->
                            <div class="space-y-2">
                                <label class="block text-sm font-medium text-gray-700">Item Name</label>
                                <input type="text" name="edit_item" id="edit_item"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-200"
                                    required>
                            </div>

                            <!-- Model No. -->
                            <div class="space-y-2">
                                <label class="block text-sm font-medium text-gray-700">Model No.</label>
                                <input type="text" name="edit_model_no" id="edit_model_no"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-200"
                                    required>
                            </div>

                            <!-- Allocation (Location) -->
                            <div class="space-y-2">
                                <label class="block text-sm font-medium text-gray-700">Allocation (Location)</label>
                                <div class="flex items-center space-x-2">
                                    <input type="text" id="display_allocation"
                                        class="flex-1 px-3 py-2 border border-gray-300 rounded-lg bg-gray-50 text-gray-700"
                                        readonly>
                                    <input type="hidden" name="edit_allocation" id="edit_allocation">
                                    <span class="bg-blue-100 text-blue-800 p-2 rounded-lg">
                                        <i class="fas fa-map-marker-alt"></i>
                                    </span>
                                </div>
                                <p class="text-xs text-gray-500 mt-1">Location cannot be changed</p>
                            </div>

                            <!-- Status -->
                            <div class="space-y-2">
                                <label class="block text-sm font-medium text-gray-700">Status</label>
                                <select name="edit_status" id="edit_status"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-200"
                                    required>
                                    <option value="GOOD">Good</option>
                                    <option value="LOW">Low</option>
                                    <option value="WORN_OUT">Worn Out</option>
                                    <option value="OUT_OF_STOCK">Out of Stock</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Modal Footer -->
                    <div class="modal-footer bg-gray-50 py-4 px-6 border-t border-gray-200">
                        <button type="button"
                            class="px-5 py-2.5 border border-gray-300 text-gray-700 rounded-lg font-medium hover:bg-gray-50 transition duration-200 flex items-center"
                            data-bs-dismiss="modal">
                            <i class="fas fa-times mr-2"></i> Cancel
                        </button>
                        <button type="submit" name="update_item"
                            class="px-5 py-2.5 bg-blue-600 text-white rounded-lg font-medium hover:bg-blue-700 transition duration-200 flex items-center">
                            <i class="fas fa-save mr-2"></i> Update Item
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ✅ Item History Modal -->
    <div class="modal fade" id="itemHistoryModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header bg-slate-800 text-white py-3">
                    <h5 class="modal-title text-sm font-black uppercase tracking-wider">
                        <i class="fas fa-history mr-2"></i>History: <span id="historyItemName"></span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0">
                    <!-- Current Status Header -->
                    <div id="itemCurrentStatusHeader"
                        class="p-3 bg-slate-50 border-b border-slate-200 flex items-center justify-between">
                        <span class="text-[10px] font-bold text-slate-500 uppercase tracking-widest">Current
                            Status:</span>
                        <div id="historyCurrentBadge"></div>
                    </div>

                    <div id="itemLogsList" class="overflow-y-auto" style="max-height: 400px; min-height: 200px;">
                        <!-- Logs will be injected here -->
                    </div>
                </div>
                <div class="modal-footer bg-gray-50 py-2">
                    <button type="button" class="btn btn-secondary text-[10px] font-bold"
                        data-bs-dismiss="modal">CLOSE</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        function initializeInventory() {
            const container = document.getElementById('logItemsContainer');
            if (!container || container.dataset.initialized === 'true') return;
            container.dataset.initialized = 'true';

            // ✅ Modals
            const itemHistoryModalEl = document.getElementById('itemHistoryModal');
            const itemHistoryModal = itemHistoryModalEl ? new bootstrap.Modal(itemHistoryModalEl) : null;

            const addLogModalEl = document.getElementById('addLogModal');
            const addLogModal = addLogModalEl ? new bootstrap.Modal(addLogModalEl) : null;

            const editModalEl = document.getElementById('editModal');
            const editModal = editModalEl ? new bootstrap.Modal(editModalEl) : null;

            const addModalEl = document.getElementById('addModal');
            const addModal = addModalEl ? new bootstrap.Modal(addModalEl) : null;

            const logsContainer = document.getElementById('itemLogsList');
            const historyTitle = document.getElementById('historyItemName');
            const currentBadgeContainer = document.getElementById('historyCurrentBadge');

            // ✅ Event Delegation for View History / Logs
            document.addEventListener('click', function (e) {
                const btn = e.target.closest('.view-history-btn');
                if (btn && itemHistoryModal) {
                    const itemId = btn.getAttribute('data-id');
                    const itemName = btn.getAttribute('data-name');
                    const row = btn.closest('tr');
                    const isBorrowed = row.getAttribute('data-borrowed') === '1';

                    if (historyTitle) historyTitle.textContent = itemName;
                    if (currentBadgeContainer) {
                        currentBadgeContainer.innerHTML = isBorrowed
                            ? `<span class="px-3 py-1 bg-red-100 text-red-700 text-[10px] font-black rounded-lg border border-red-200 uppercase shadow-sm"><i class="fas fa-arrow-up mr-1.5"></i>ISSUED / OUT</span>`
                            : `<span class="px-3 py-1 bg-emerald-100 text-emerald-700 text-[10px] font-black rounded-lg border border-emerald-200 uppercase shadow-sm"><i class="fas fa-arrow-down mr-1.5"></i>AVAILABLE / IN</span>`;
                    }

                    if (logsContainer) {
                        logsContainer.innerHTML = '<div class="flex flex-col items-center py-10"><i class="fas fa-spinner fa-spin text-2xl text-blue-500 mb-2"></i><p class="text-[10px] font-bold text-slate-400">LOADING LOGS...</p></div>';
                    }

                    itemHistoryModal.show();

                    // Correct AJAX Path for Logs
                    let ajaxUrl = 'side_inventory.php?fetch_item_logs=1&item_id=' + itemId;
                    if (!window.location.pathname.includes('/supply/')) {
                        ajaxUrl = 'supply/' + ajaxUrl;
                    }

                    fetch(ajaxUrl)
                        .then(res => res.json())
                        .then(data => {
                            if (data.success && data.logs && data.logs.length > 0) {
                                logsContainer.innerHTML = data.logs.map(log => {
                                    const action = log.action_type.toUpperCase();
                                    let badgeColor = 'bg-blue-100 text-blue-600 border-blue-200';
                                    let icon = 'fa-info-circle', iconBg = 'bg-blue-50 text-blue-500';

                                    if (action === 'ISSUED') {
                                        badgeColor = 'bg-orange-100 text-orange-700 border-orange-200';
                                        icon = 'fa-arrow-up'; iconBg = 'bg-orange-50 text-orange-500';
                                    } else if (action === 'RETURNED') {
                                        badgeColor = 'bg-emerald-100 text-emerald-700 border-emerald-200';
                                        icon = 'fa-arrow-down'; iconBg = 'bg-emerald-50 text-emerald-500';
                                    } else if (action === 'ADDED') {
                                        badgeColor = 'bg-indigo-100 text-indigo-700 border-indigo-200';
                                        icon = 'fa-plus'; iconBg = 'bg-indigo-50 text-indigo-500';
                                    }

                                    return `
                                        <div class="p-4 border-b border-gray-100 hover:bg-slate-50 transition-colors">
                                            <div class="flex items-center gap-3">
                                                <div class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0 ${iconBg} border border-white shadow-sm">
                                                    <i class="fas ${icon} text-xs"></i>
                                                </div>
                                                <div class="flex-1 min-width-0">
                                                    <div class="flex justify-between items-start mb-1">
                                                        <span class="px-2 py-0.5 border ${badgeColor} text-[9px] font-black rounded-md tracking-tighter uppercase">${action}</span>
                                                        <span class="text-[9px] font-bold text-slate-400"><i class="far fa-clock mr-1"></i>${log.formatted_date}</span>
                                                    </div>
                                                    <div class="text-xs font-bold text-slate-800">
                                                        ${action === 'ISSUED' ? 'Borrowed' : (action === 'RETURNED' ? 'Returned' : 'Action')} by: 
                                                        <span class="text-blue-600">${log.performed_by}</span>
                                                    </div>
                                                    <div class="text-[10px] text-slate-500 font-medium mt-0.5 flex items-center gap-2">
                                                        <span><i class="fas fa-map-marker-alt mr-1 text-slate-300"></i>${log.location}</span>
                                                        ${log.quantity_changed != 0 ? `<span class="text-slate-300">•</span> <span class="font-bold">Qty: ${log.quantity_changed}</span>` : ''}
                                                    </div>
                                                </div>
                                            </div>
                                        </div>`;
                                }).join('');
                            } else {
                                logsContainer.innerHTML = '<div class="flex flex-col items-center py-10"><i class="fas fa-history text-slate-200 text-3xl mb-3"></i><p class="text-xs font-bold text-slate-400">No logs found for this item.</p></div>';
                            }
                        })
                        .catch(err => {
                            console.error(err);
                            if (logsContainer) logsContainer.innerHTML = '<div class="p-10 text-center text-red-500 text-xs font-bold">Failed to load logs.</div>';
                        });
                }
            });

            // ✅ Event Delegation for Edit Buttons
            document.addEventListener('click', function (e) {
                const btn = e.target.closest('.editBtn');
                if (btn && editModal) {
                    const edit_category = document.getElementById('edit_category');
                    const edit_id = document.getElementById('edit_id');
                    const edit_item = document.getElementById('edit_item');
                    const edit_model_no = document.getElementById('edit_model_no');
                    const edit_allocation = document.getElementById('edit_allocation');
                    const display_allocation = document.getElementById('display_allocation');
                    const edit_status = document.getElementById('edit_status');
                    const editOtherCategoryDiv = document.getElementById('editOtherCategoryDiv');
                    const editOtherCategoryInput = document.getElementById('edit_other_category');

                    if (edit_id) edit_id.value = btn.dataset.id;
                    if (edit_item) edit_item.value = btn.dataset.item;
                    if (edit_model_no) edit_model_no.value = btn.dataset.model;
                    if (edit_allocation) edit_allocation.value = btn.dataset.allocation;
                    if (display_allocation) display_allocation.value = btn.dataset.allocation;
                    if (edit_status) edit_status.value = (btn.dataset.status || '').trim();

                    const category = btn.dataset.category;
                    const predefinedCategories = ['Electronics', 'Office Supplies', 'Tools', 'Equipment'];

                    if (edit_category) {
                        if (predefinedCategories.includes(category)) {
                            edit_category.value = category;
                            if (editOtherCategoryDiv) editOtherCategoryDiv.style.display = 'none';
                            if (editOtherCategoryInput) {
                                editOtherCategoryInput.removeAttribute('required');
                                editOtherCategoryInput.value = '';
                            }
                        } else {
                            edit_category.value = 'Other';
                            if (editOtherCategoryDiv) editOtherCategoryDiv.style.display = 'block';
                            if (editOtherCategoryInput) {
                                editOtherCategoryInput.value = category;
                                editOtherCategoryInput.setAttribute('required', 'required');
                            }
                        }
                    }
                    editModal.show();
                }
            });

            // ✅ Log Items Dynamic Management
            // container already declared above (line 1550)
            const totalQtyInput = document.getElementById('log_quantity');
            const actionType = document.getElementById('log_action_type');

            function updateQty() {
                if (!container || !totalQtyInput || !actionType) return;
                const rows = container.querySelectorAll('.log-item-row');
                const count = rows.length;
                const sign = actionType.value === 'ISSUED' ? -1 : 1;
                totalQtyInput.value = count * sign;
                refreshAllDropdowns();
            }

            function refreshAllDropdowns() {
                if (!container || !actionType) return;
                const action = actionType.value;
                const selects = container.querySelectorAll('.log-item-select');
                const selectedValues = Array.from(selects).map(s => s.value).filter(v => v !== "");

                selects.forEach(select => {
                    const currentVal = select.value;
                    Array.from(select.options).forEach(opt => {
                        if (opt.value === "") return;
                        const itemStatus = opt.dataset.status || 'AVAILABLE';
                        const isBorrowed = itemStatus === 'BORROWED';
                        let shouldDisable = false;
                        if (action === 'ISSUED' && isBorrowed) shouldDisable = true;
                        else if (action === 'RETURNED' && !isBorrowed) shouldDisable = true;
                        if (selectedValues.includes(opt.value) && opt.value !== currentVal) shouldDisable = true;
                        opt.disabled = shouldDisable;
                    });
                });
            }

            if (container) {
                container.addEventListener('click', function (e) {
                    if (e.target.closest('.add-row-btn')) {
                        const originalRow = container.querySelector('.log-item-row');
                        if (!originalRow) return;
                        const newRow = originalRow.cloneNode(true);
                        const newSelect = newRow.querySelector('select');

                        if (window.jQuery && jQuery(newSelect).data('select2')) {
                            jQuery(newSelect).select2('destroy');
                        }
                        jQuery(newSelect).removeClass('select2-hidden-accessible').removeAttr('data-select2-id');
                        newRow.querySelectorAll('.select2-container').forEach(el => el.remove());
                        newSelect.value = '';
                        jQuery(newSelect).find('option').removeAttr('data-select2-id');

                        const btn = newRow.querySelector('.add-row-btn');
                        if (btn) {
                            btn.classList.replace('bg-blue-600', 'bg-red-500');
                            btn.classList.replace('hover:bg-blue-700', 'hover:bg-red-600');
                            btn.classList.add('remove-row-btn');
                            btn.classList.remove('add-row-btn');
                            btn.innerHTML = '<i class="fas fa-trash text-[10px]"></i>';
                        }
                        container.appendChild(newRow);

                        if (window.jQuery && jQuery.fn.select2) {
                            jQuery(newSelect).select2({ width: '100%', dropdownParent: jQuery('#addLogModal') });
                        }
                        updateQty();
                        container.scrollTop = container.scrollHeight;
                    } else if (e.target.closest('.remove-row-btn')) {
                        const row = e.target.closest('.log-item-row');
                        if (row) {
                            if (window.jQuery) {
                                const $sel = jQuery(row).find('select');
                                if ($sel.data('select2')) $sel.select2('destroy');
                            }
                            row.remove();
                            updateQty();
                        }
                    }
                });

                container.addEventListener('change', function (e) {
                    if (e.target.classList.contains('log-item-select')) {
                        const row = e.target.closest('.log-item-row');
                        const selectedOption = e.target.options[e.target.selectedIndex];
                        const model = selectedOption.dataset.model || '';
                        if (row) {
                            const modelDisplay = row.querySelector('.model-display');
                            if (modelDisplay) modelDisplay.value = model;
                        }
                        refreshAllDropdowns();
                    }
                });
            }

            if (actionType) {
                actionType.addEventListener('change', function () {
                    if (container) {
                        container.querySelectorAll('.log-item-select').forEach(s => {
                            s.value = '';
                            const row = s.closest('.log-item-row');
                            if (row) {
                                const modelDisplay = row.querySelector('.model-display');
                                if (modelDisplay) modelDisplay.value = '';
                            }
                        });
                    }
                    updateQty();
                });
            }

            function initSelect2OnLogItems() {
                if (window.jQuery && jQuery.fn.select2) {
                    if (container) {
                        const selects = container.querySelectorAll('.log-item-select');
                        selects.forEach(function (select) {
                            if (!jQuery(select).data('select2')) {
                                jQuery(select).select2({ width: '100%', dropdownParent: jQuery('#addLogModal') });
                            }
                        });
                    }
                } else {
                    setTimeout(initSelect2OnLogItems, 100);
                }
            }

            if (addLogModalEl) {
                addLogModalEl.addEventListener('shown.bs.modal', function () {
                    initSelect2OnLogItems();
                    updateQty();
                    refreshAllDropdowns();
                });
            }

            const qtyHelpText = document.getElementById('qtyHelp');
            if (actionType && totalQtyInput) {
                function updateQtyConstraints() {
                    const action = actionType.value;
                    if (action === 'ISSUED') {
                        if (qtyHelpText) qtyHelpText.innerHTML = '<i class="fas fa-minus-circle mr-1"></i> Negative quantity (borrowing)';
                    } else if (action === 'RETURNED') {
                        if (qtyHelpText) qtyHelpText.innerHTML = '<i class="fas fa-plus-circle mr-1"></i> Positive quantity (returning)';
                    }
                    updateQty();
                }
                actionType.addEventListener('change', updateQtyConstraints);
                updateQtyConstraints();
            }

            // ✅ Filter Logic
            const searchInput = document.getElementById('searchInput');
            const statusFilter = document.getElementById('statusFilter');
            const itemNameFilter = document.getElementById('itemNameFilter');
            const categoryFilter = document.getElementById('categoryFilter');
            const clearFiltersBtn = document.getElementById('clearFilters');
            const resultCountDisplay = document.getElementById('resultCountDisplay');
            const filteredCount = document.getElementById('filteredCount');

            function applyAllFilters() {
                const searchTerm = searchInput ? searchInput.value.toLowerCase().trim() : '';
                const statusValue = statusFilter ? statusFilter.value.toUpperCase() : '';
                const itemNameValue = itemNameFilter ? itemNameFilter.value.toLowerCase().trim() : '';
                const categoryValue = categoryFilter ? categoryFilter.value.toLowerCase().trim() : '';

                const totalCountBox = document.getElementById('totalCountBox');
                const borrowedCountBox = document.getElementById('borrowedCountBox');
                const returnedCountBox = document.getElementById('returnedCountBox');

                let visibleCount = 0, catTotal = 0, catBorrowed = 0;

                const rows = document.querySelectorAll('.inventory-row');
                rows.forEach(row => {
                    // Cell indices: 0=#, 1=Category, 2=Item Name, 3=Model No, 4=Borrowed, 5=Status
                    const itemName = row.cells[2].textContent.toLowerCase().trim();
                    const modelNo = row.cells[3].textContent.toLowerCase().trim();
                    const categoryElement = row.cells[1].querySelector('span');
                    const rowCategory = categoryElement ? categoryElement.textContent.toLowerCase().trim() : '';
                    const statusElement = row.cells[5] ? row.cells[5].querySelector('div') : null;
                    const rowStatus = statusElement ? statusElement.textContent.trim().toUpperCase() : '';
                    const isRowBorrowed = row.dataset.borrowed === '1';

                    const itemNameMatch = !itemNameValue || itemName === itemNameValue;
                    const categoryMatch = !categoryValue || rowCategory === categoryValue;

                    if (itemNameMatch && categoryMatch) {
                        catTotal++;
                        if (isRowBorrowed) catBorrowed++;
                    }

                    const searchMatch = !searchTerm || itemName.includes(searchTerm) || modelNo.includes(searchTerm) || rowCategory.includes(searchTerm);
                    const statusMatch = !statusValue ||
                        (statusValue === 'GOOD' && rowStatus === 'GOOD') ||
                        (statusValue === 'LOW' && rowStatus === 'LOW') ||
                        (statusValue === 'WORN_OUT' && (rowStatus === 'WORN OUT' || rowStatus === 'WORN_OUT')) ||
                        (statusValue === 'OUT_OF_STOCK' && (rowStatus === 'OUT OF STOCK' || rowStatus === 'OUT_OF_STOCK'));

                    if (itemNameMatch && categoryMatch && searchMatch && statusMatch) {
                        row.style.display = '';
                        visibleCount++;
                        const numberCell = row.cells[0].querySelector('span');
                        if (numberCell) numberCell.textContent = '#' + visibleCount;
                    } else {
                        row.style.display = 'none';
                    }
                });

                if (totalCountBox) totalCountBox.textContent = catTotal;
                if (borrowedCountBox) borrowedCountBox.textContent = catBorrowed;
                if (returnedCountBox) returnedCountBox.textContent = catTotal - catBorrowed;
                if (resultCountDisplay) resultCountDisplay.textContent = `${visibleCount} items found`;
                if (filteredCount) filteredCount.textContent = `Showing ${visibleCount} of ${catTotal} items`;

                const tableBody = document.getElementById('inventoryTableBody');
                if (tableBody) {
                    let existingEmptyRow = tableBody.querySelector('.no-results');
                    if (visibleCount === 0 && rows.length > 0) {
                        if (!existingEmptyRow) {
                            const emptyRow = document.createElement('tr');
                            emptyRow.className = 'no-results';
                            emptyRow.innerHTML = `<td colspan="8" class="px-6 py-12 text-center"><div class="text-gray-500"><i class="fas fa-search text-4xl mb-4 opacity-30"></i><h3 class="text-lg font-medium text-gray-900 mb-2">No items found</h3><p class="text-gray-600 mb-4">No inventory items match your search criteria.</p><button id="clearSearchFilters" class="border border-gray-300 hover:bg-gray-50 text-gray-700 px-4 py-2 rounded-lg font-medium inline-flex items-center transition duration-200"><i class="fas fa-times-circle mr-2"></i> Clear All Filters</button></div></td>`;
                            tableBody.appendChild(emptyRow);
                            document.getElementById('clearSearchFilters')?.addEventListener('click', function () {
                                if (searchInput) searchInput.value = '';
                                if (statusFilter) statusFilter.value = '';
                                if (categoryFilter) categoryFilter.value = '';
                                applyAllFilters();
                                searchInput?.focus();
                            });
                        } else existingEmptyRow.style.display = '';
                    } else if (existingEmptyRow) existingEmptyRow.remove();
                }
            }

            if (searchInput) searchInput.addEventListener('input', applyAllFilters);
            if (statusFilter) statusFilter.addEventListener('change', applyAllFilters);
            if (itemNameFilter) itemNameFilter.addEventListener('change', applyAllFilters);
            if (categoryFilter) categoryFilter.addEventListener('change', applyAllFilters);
            if (clearFiltersBtn) {
                clearFiltersBtn.addEventListener('click', function () {
                    if (searchInput) searchInput.value = '';
                    if (statusFilter) statusFilter.value = '';
                    if (itemNameFilter) itemNameFilter.value = '';
                    if (categoryFilter) categoryFilter.value = '';
                    applyAllFilters();
                    searchInput?.focus();
                });
            }

            // ✅ Modal Logic
            const categorySelect = document.getElementById('categorySelect');
            const otherCategoryDiv = document.getElementById('otherCategoryDiv');
            const otherCategoryInput = document.getElementById('otherCategoryInput');
            if (categorySelect && otherCategoryDiv) {
                categorySelect.addEventListener('change', function () {
                    if (this.value === 'Other') {
                        otherCategoryDiv.style.display = 'block';
                        otherCategoryInput?.setAttribute('required', 'required');
                        otherCategoryInput?.focus();
                    } else {
                        otherCategoryDiv.style.display = 'none';
                        otherCategoryInput?.removeAttribute('required');
                        if (otherCategoryInput) otherCategoryInput.value = '';
                    }
                });
            }
            if (addModalEl) {
                addModalEl.addEventListener('hidden.bs.modal', function () {
                    document.getElementById('addItemForm')?.reset();
                    if (otherCategoryDiv) otherCategoryDiv.style.display = 'none';
                    if (otherCategoryInput) { otherCategoryInput.removeAttribute('required'); otherCategoryInput.value = ''; }
                });
            }
            if (editModalEl) {
                editModalEl.addEventListener('hidden.bs.modal', function () {
                    const editOtherCategoryDiv = document.getElementById('editOtherCategoryDiv');
                    const editOtherCategoryInput = document.getElementById('edit_other_category');
                    if (editOtherCategoryDiv) editOtherCategoryDiv.style.display = 'none';
                    if (editOtherCategoryInput) { editOtherCategoryInput.removeAttribute('required'); editOtherCategoryInput.value = ''; }
                });
            }

            // ✅ Log Drawer Functionality
            const openLogDrawerBtn = document.getElementById('openLogDrawer');
            const closeLogDrawerBtn = document.getElementById('closeLogDrawer');
            const logDrawer = document.getElementById('logDrawer');
            const drawerBackdrop = document.getElementById('drawerBackdrop');
            const drawerLogsContainer = document.getElementById('drawerLogsContainer');
            window.openDrawer = function () {
                if (logDrawer) logDrawer.classList.add('open');
                if (drawerBackdrop) drawerBackdrop.classList.add('show');
                document.body.style.overflow = 'hidden';
                ['.sidebar', '.top-navbar', '.inventory-table-section'].forEach(sel => document.querySelector(sel)?.classList.add('blur-content'));

                // Fetch fresh logs via AJAX
                if (drawerLogsContainer) {
                    drawerLogsContainer.innerHTML = '<div class="flex flex-col items-center justify-center py-12"><i class="fas fa-spinner fa-spin text-2xl text-blue-500 mb-2"></i><p class="text-xs font-bold text-slate-400">Loading logs...</p></div>';
                    fetch('side_inventory.php?fetch_all_logs=1')
                        .then(res => res.json())
                        .then(data => {
                            if (data.success && data.logs && data.logs.length > 0) {
                                drawerLogsContainer.innerHTML = data.logs.map(log => {
                                    const action = (log.action_type || '').toUpperCase();
                                    let actionClass = 'default';
                                    let actionIcon = 'fa-circle';
                                    if (action === 'ADDED') { actionClass = 'added'; actionIcon = 'fa-plus'; }
                                    else if (action === 'UPDATED') { actionClass = 'updated'; actionIcon = 'fa-edit'; }
                                    else if (action === 'ISSUED') { actionClass = 'issued'; actionIcon = 'fa-arrow-up'; }
                                    else if (action === 'RETURNED') { actionClass = 'returned'; actionIcon = 'fa-arrow-down'; }
                                    const qty = log.quantity_changed || 0;
                                    const qtyClass = qty > 0 ? 'positive' : (qty < 0 ? 'negative' : '');
                                    return `<div class="activity-item">
                                        <div class="activity-icon ${actionClass}"><i class="fas ${actionIcon}"></i></div>
                                        <div class="activity-content">
                                            <div class="activity-title"><span class="font-bold">${action}</span>: <span class="text-slate-600">${log.item_name || ''}</span></div>
                                            <div class="activity-meta">
                                                <span class="flex items-center gap-2"><i class="far fa-user"></i> ${log.performed_by || ''}</span>
                                                <span class="w-1.5 h-1.5 rounded-full bg-slate-200"></span>
                                                <span class="flex items-center gap-2"><i class="far fa-clock"></i> ${log.formatted_date || ''}</span>
                                                ${qty !== 0 ? `<span class="activity-quantity ${qtyClass} ml-auto">${qty > 0 ? '+' : ''}${qty}</span>` : ''}
                                            </div>
                                        </div>
                                    </div>`;
                                }).join('');
                            } else {
                                drawerLogsContainer.innerHTML = '<div class="activity-empty"><div class="w-16 h-16 bg-slate-50 rounded-full flex items-center justify-center mx-auto mb-4 border border-slate-100"><i class="fas fa-history text-slate-200 text-2xl"></i></div><p class="font-bold text-slate-400">No activity recorded yet</p></div>';
                            }
                        })
                        .catch(err => {
                            console.error('Error fetching drawer logs:', err);
                            drawerLogsContainer.innerHTML = '<div class="p-4 text-center text-red-500">Failed to load logs</div>';
                        });
                }
            };

            // Function to refresh drawer logs (called after adding new log)
            window.refreshActivityDrawer = function () {
                if (drawerLogsContainer && logDrawer?.classList.contains('open')) {
                    drawerLogsContainer.innerHTML = '<div class="flex flex-col items-center justify-center py-12"><i class="fas fa-spinner fa-spin text-2xl text-blue-500 mb-2"></i><p class="text-xs font-bold text-slate-400">Loading logs...</p></div>';
                    fetch('side_inventory.php?fetch_all_logs=1')
                        .then(res => res.json())
                        .then(data => {
                            if (data.success && data.logs && data.logs.length > 0) {
                                drawerLogsContainer.innerHTML = data.logs.map(log => {
                                    const action = (log.action_type || '').toUpperCase();
                                    let actionClass = 'default';
                                    let actionIcon = 'fa-circle';
                                    if (action === 'ADDED') { actionClass = 'added'; actionIcon = 'fa-plus'; }
                                    else if (action === 'UPDATED') { actionClass = 'updated'; actionIcon = 'fa-edit'; }
                                    else if (action === 'ISSUED') { actionClass = 'issued'; actionIcon = 'fa-arrow-up'; }
                                    else if (action === 'RETURNED') { actionClass = 'returned'; actionIcon = 'fa-arrow-down'; }
                                    const qty = log.quantity_changed || 0;
                                    const qtyClass = qty > 0 ? 'positive' : (qty < 0 ? 'negative' : '');
                                    return `<div class="activity-item">
                                        <div class="activity-icon ${actionClass}"><i class="fas ${actionIcon}"></i></div>
                                        <div class="activity-content">
                                            <div class="activity-title"><span class="font-bold">${action}</span>: <span class="text-slate-600">${log.item_name || ''}</span></div>
                                            <div class="activity-meta">
                                                <span class="flex items-center gap-2"><i class="far fa-user"></i> ${log.performed_by || ''}</span>
                                                <span class="w-1.5 h-1.5 rounded-full bg-slate-200"></span>
                                                <span class="flex items-center gap-2"><i class="far fa-clock"></i> ${log.formatted_date || ''}</span>
                                                ${qty !== 0 ? `<span class="activity-quantity ${qtyClass} ml-auto">${qty > 0 ? '+' : ''}${qty}</span>` : ''}
                                            </div>
                                        </div>
                                    </div>`;
                                }).join('');
                            } else {
                                drawerLogsContainer.innerHTML = '<div class="activity-empty"><div class="w-16 h-16 bg-slate-50 rounded-full flex items-center justify-center mx-auto mb-4 border border-slate-100"><i class="fas fa-history text-slate-200 text-2xl"></i></div><p class="font-bold text-slate-400">No activity recorded yet</p></div>';
                            }
                        })
                        .catch(err => console.error('Error refreshing drawer logs:', err));
                }
            };

            window.closeDrawer = function () {
                if (logDrawer) logDrawer.classList.remove('open');
                if (drawerBackdrop) drawerBackdrop.classList.remove('show');
                document.body.style.overflow = '';
                ['.sidebar', '.top-navbar', '.inventory-table-section'].forEach(sel => document.querySelector(sel)?.classList.remove('blur-content'));
            };
            if (openLogDrawerBtn) openLogDrawerBtn.addEventListener('click', window.openDrawer);
            if (closeLogDrawerBtn) closeLogDrawerBtn.addEventListener('click', window.closeDrawer);
            if (drawerBackdrop) drawerBackdrop.addEventListener('click', window.closeDrawer);
            document.addEventListener('keydown', e => { if (e.key === 'Escape' && logDrawer?.classList.contains('open')) window.closeDrawer(); });

            // ✅ AJAX Submit for Add Item
            const addItemForm = document.getElementById('addItemForm');
            if (addItemForm) {
                addItemForm.addEventListener('submit', function (e) {
                    e.preventDefault();
                    const formData = new FormData(this);
                    formData.append('ajax', '1');
                    formData.append('add_item', '1');

                    const submitBtn = this.querySelector('button[name="add_item"]');
                    const originalText = submitBtn?.innerHTML;
                    if (submitBtn) {
                        submitBtn.disabled = true;
                        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> SAVING...';
                    }

                    fetch('side_inventory.php', {
                        method: 'POST',
                        body: formData,
                        credentials: 'include'
                    })
                        .then(res => res.text())
                        .then(text => {
                            try {
                                const data = JSON.parse(text);
                                if (data.success) {
                                    // 1. Hide the modal
                                    if (addModalEl) {
                                        const modalInstance = bootstrap.Modal.getInstance(addModalEl) || new bootstrap.Modal(addModalEl);
                                        modalInstance.hide();
                                    }

                                    // 2. Show success message
                                    alert(data.message);

                                    // 3. Reset the form
                                    addItemForm.reset();

                                    // 4. Refresh the inventory table instantly via API
                                    fetch('api/get_inventory.php')
                                        .then(res => res.json())
                                        .then(response => {
                                            if (response.success) {
                                                const tbody = document.getElementById('inventoryTableBody');
                                                const totalCountBox = document.getElementById('totalCountBox');
                                                const borrowedCountBox = document.getElementById('borrowedCountBox');
                                                const returnedCountBox = document.getElementById('returnedCountBox');
                                                const resultCountDisplay = document.getElementById('resultCountDisplay');

                                                if (tbody) {
                                                    const items = response.data.items || [];
                                                    if (items.length > 0) {
                                                        let html = '';
                                                        let counter = 1;
                                                        items.forEach(part => {
                                                            const rawStatus = (part.status || '').trim();
                                                            const is_borrowed = ((part.log_stats || '').indexOf('BORROWED') === 0);
                                                            let statusText = 'GOOD';
                                                            let statusColor = 'text-emerald-600';
                                                            if (rawStatus === 'LOW') {
                                                                statusText = 'LOW';
                                                                statusColor = 'text-amber-600';
                                                            } else if (rawStatus === 'WORN_OUT' || rawStatus === 'WORN OUT') {
                                                                statusText = 'WORN OUT';
                                                                statusColor = 'text-rose-600';
                                                            } else if (rawStatus === 'OUT_OF_STOCK' || rawStatus === 'OUT OF STOCK') {
                                                                statusText = 'OUT OF STOCK';
                                                                statusColor = 'text-gray-600';
                                                            }

                                                            html += `<tr class="inventory-row border-b border-slate-50" data-id="${part.id}" data-borrowed="${is_borrowed ? '1' : '0'}">
                                                                <td class="px-6 py-4">
                                                                    <span class="text-lg font-bold text-slate-700 max-w-[200px] truncate">#${counter++}</span>
                                                                </td>
                                                                <td class="px-6 py-4">
                                                                    <span class="text-md font-bold text-slate-700 max-w-[200px] truncate">
                                                                        ${(part.category || 'Uncategorized').toUpperCase()}
                                                                    </span>
                                                                </td>
                                                                <td class="px-6 py-4">
                                                                    <div class="text-md font-bold text-slate-700 max-w-[200px] truncate" title="${part.item || ''}">
                                                                        ${part.item || ''}
                                                                    </div>
                                                                </td>
                                                                <td class="px-6 py-4">
                                                                    <div class="text-md font-bold text-slate-700 max-w-[200px] truncate">
                                                                        ${part.model_no || ''}
                                                                    </div>
                                                                </td>
                                                                <td class="px-2 py-4 text-center">
                                                                    ${is_borrowed ? '<i class="fas fa-arrow-up text-red-500" title="Borrowed"></i>' : ''}
                                                                </td>
                                                                <td class="px-6 py-4">
                                                                    <div class="text-[11px] font-black uppercase tracking-widest ${statusColor}">
                                                                        ${statusText}
                                                                    </div>
                                                                </td>
                                                                <td class="px-6 py-4">
                                                                    <div class="text-md font-bold text-slate-700 max-w-[200px] truncate">
                                                                        ${part.date_added ? new Date(part.date_added).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : ''}
                                                                    </div>
                                                                </td>
                                                                <td class="px-6 py-4">
                                                                    <div class="flex items-center justify-start gap-2">
                                                                        <button class="logs-action-btn view-history-btn shadow-sm" data-id="${part.id}" data-name="${part.item || ''}">
                                                                            <i class="fas fa-history mr-1.5"></i> Logs
                                                                        </button>
                                                                        <button class="editBtn edit-action-btn shadow-sm" data-id="${part.id}" data-category="${part.category || ''}" data-item="${part.item || ''}" data-model="${part.model_no || ''}" data-allocation="${part.allocation || ''}" data-status="${part.status || ''}">
                                                                            <i class="fas fa-pen-nib mr-1.5"></i> Edit
                                                                        </button>
                                                                    </div>
                                                                </td>
                                                            </tr>`;
                                                        });
                                                        tbody.innerHTML = html;
                                                    } else {
                                                        tbody.innerHTML = `<tr><td colspan="7" class="px-6 py-12 text-center bg-slate-50/50"><div class="flex flex-col items-center"><div class="w-16 h-16 bg-slate-100 rounded-full flex items-center justify-center mb-4"><i class="fas fa-box-open text-2xl text-slate-300"></i></div><h3 class="text-lg font-bold text-slate-800">No inventory found</h3></div></td></tr>`;
                                                    }
                                                }

                                                // Update counts
                                                if (totalCountBox) totalCountBox.textContent = response.data.total || 0;
                                                if (borrowedCountBox) borrowedCountBox.textContent = response.data.stats?.borrowed || 0;
                                                if (returnedCountBox) returnedCountBox.textContent = response.data.stats?.available || 0;
                                                if (resultCountDisplay) resultCountDisplay.textContent = (response.data.total || 0) + ' items found';
                                            }
                                        })
                                        .catch(err => console.error('Error refreshing inventory:', err));
                                } else {
                                    alert('Error: ' + (data.message || 'Failed to add item.'));
                                }
                            } catch (e) {
                                console.error('JSON Parse Error:', e, text);
                                alert('Server error occurred.');
                            }
                        })
                        .catch(err => {
                            console.error('Fetch Error:', err);
                            alert('Request failed.');
                        })
                        .finally(() => {
                            if (submitBtn) {
                                submitBtn.disabled = false;
                                submitBtn.innerHTML = originalText;
                            }
                        });
                });
            }

            // ✅ AJAX Submit for Edit Item
            const editItemForm = document.getElementById('editItemForm');
            if (editItemForm) {
                editItemForm.addEventListener('submit', function (e) {
                    e.preventDefault();
                    const formData = new FormData(this);
                    formData.append('ajax', '1');
                    formData.append('update_item', '1');

                    const submitBtn = this.querySelector('button[name="update_item"]');
                    const originalText = submitBtn?.innerHTML;
                    if (submitBtn) {
                        submitBtn.disabled = true;
                        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> UPDATING...';
                    }

                    fetch('side_inventory.php', {
                        method: 'POST',
                        body: formData,
                        credentials: 'include'
                    })
                        .then(res => res.text())
                        .then(text => {
                            try {
                                const data = JSON.parse(text);
                                if (data.success) {
                                    // Hide modal
                                    if (editModalEl) {
                                        const modal = bootstrap.Modal.getInstance(editModalEl);
                                        if (modal) modal.hide();
                                        setTimeout(() => {
                                            document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
                                            document.body.classList.remove('modal-open');
                                        }, 300);
                                    }

                                    alert(data.message);

                                    // Refresh table via API
                                    fetch('api/get_inventory.php')
                                        .then(res => res.json())
                                        .then(response => {
                                            if (response.success) {
                                                const tbody = document.getElementById('inventoryTableBody');
                                                const totalCountBox = document.getElementById('totalCountBox');
                                                const borrowedCountBox = document.getElementById('borrowedCountBox');
                                                const returnedCountBox = document.getElementById('returnedCountBox');
                                                const resultCountDisplay = document.getElementById('resultCountDisplay');

                                                if (tbody && response.data.items) {
                                                    const items = response.data.items;
                                                    let html = '';
                                                    let counter = 1;
                                                    items.forEach(part => {
                                                        const rawStatus = (part.status || '').trim();
                                                        const is_borrowed = ((part.log_stats || '').indexOf('BORROWED') === 0);
                                                        let statusText = 'GOOD';
                                                        let statusColor = 'text-emerald-600';
                                                        if (rawStatus === 'LOW') { statusText = 'LOW'; statusColor = 'text-amber-600'; }
                                                        else if (rawStatus === 'WORN_OUT' || rawStatus === 'WORN OUT') { statusText = 'WORN OUT'; statusColor = 'text-rose-600'; }
                                                        else if (rawStatus === 'OUT_OF_STOCK' || rawStatus === 'OUT OF STOCK') { statusText = 'OUT OF STOCK'; statusColor = 'text-gray-600'; }

                                                        html += `<tr class="inventory-row border-b border-slate-50" data-id="${part.id}" data-borrowed="${is_borrowed ? '1' : '0'}">
                                                            <td class="px-6 py-4"><span class="text-lg font-bold text-slate-700">#${counter++}</span></td>
                                                            <td class="px-6 py-4"><span class="text-md font-bold text-slate-700">${(part.category || 'Uncategorized').toUpperCase()}</span></td>
                                                            <td class="px-6 py-4"><div class="text-md font-bold text-slate-700">${part.item || ''}</div></td>
                                                            <td class="px-6 py-4"><div class="text-md font-bold text-slate-700">${part.model_no || ''}</div></td>
                                                            <td class="px-2 py-4 text-center">${is_borrowed ? '<i class="fas fa-arrow-up text-red-500" title="Borrowed"></i>' : ''}</td>
                                                            <td class="px-6 py-4"><div class="text-[11px] font-black uppercase tracking-widest ${statusColor}">${statusText}</div></td>
                                                            <td class="px-6 py-4"><div class="text-md font-bold text-slate-700">${part.date_added ? new Date(part.date_added).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : ''}</div></td>
                                                            <td class="px-6 py-4">
                                                                <div class="flex items-center justify-start gap-2">
                                                                    <button class="logs-action-btn view-history-btn shadow-sm" data-id="${part.id}" data-name="${part.item || ''}"><i class="fas fa-history mr-1.5"></i> Logs</button>
                                                                    <button class="editBtn edit-action-btn shadow-sm" data-id="${part.id}" data-category="${part.category || ''}" data-item="${part.item || ''}" data-model="${part.model_no || ''}" data-allocation="${part.allocation || ''}" data-status="${part.status || ''}"><i class="fas fa-pen-nib mr-1.5"></i> Edit</button>
                                                                </div>
                                                            </td>
                                                        </tr>`;
                                                    });
                                                    tbody.innerHTML = html;
                                                }

                                                if (totalCountBox) totalCountBox.textContent = response.data.total || 0;
                                                if (borrowedCountBox) borrowedCountBox.textContent = response.data.stats?.borrowed || 0;
                                                if (returnedCountBox) returnedCountBox.textContent = response.data.stats?.available || 0;
                                                if (resultCountDisplay) resultCountDisplay.textContent = (response.data.total || 0) + ' items found';
                                            }
                                        });
                                } else {
                                    alert('Error: ' + (data.message || 'Failed to update item.'));
                                }
                            } catch (e) {
                                console.error('Parse Error:', e, text);
                                alert('Server error occurred.');
                            }
                        })
                        .catch(err => {
                            console.error('Fetch Error:', err);
                            alert('Request failed.');
                        })
                        .finally(() => {
                            if (submitBtn) {
                                submitBtn.disabled = false;
                                submitBtn.innerHTML = originalText;
                            }
                        });
                });
            }

            // ✅ AJAX Submit for Logs
            const addLogForm = document.getElementById('addLogForm');
            if (addLogForm) {
                addLogForm.addEventListener('submit', function (e) {
                    e.preventDefault();
                    if (window.jQuery) jQuery('.log-item-select').trigger('change');
                    const formData = new FormData(this);
                    formData.append('ajax', '1');
                    formData.append('add_activity_log', '1');
                    const submitBtn = this.querySelector('button[type="submit"]');
                    const originalText = submitBtn?.innerHTML;
                    if (submitBtn) { submitBtn.disabled = true; submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> SAVING...'; }

                    fetch('side_inventory.php', { method: 'POST', body: formData, credentials: 'include' })
                        .then(res => res.text())
                        .then(text => {
                            try {
                                const data = JSON.parse(text);
                                if (data.success) {
                                    if (addLogModalEl) (bootstrap.Modal.getInstance(addLogModalEl) || new bootstrap.Modal(addLogModalEl)).hide();
                                    addLogForm.reset();
                                    if (container) {
                                        container.querySelectorAll('.log-item-row').forEach((row, i) => { if (i > 0) row.remove(); });
                                        const sel = container.querySelector('.log-item-select');
                                        if (window.jQuery && jQuery(sel).data('select2')) jQuery(sel).val('').trigger('change');
                                    }
                                    alert(data.message);
                                    const actionTypeVal = formData.get('log_action_type');
                                    const itemIds = formData.getAll('log_inventory_id[]');
                                    itemIds.forEach(id => {
                                        const row = document.querySelector(`tr[data-id="${id}"]`);
                                        if (row) {
                                            const isBorrowed = (actionTypeVal === 'ISSUED');
                                            row.setAttribute('data-borrowed', isBorrowed ? '1' : '0');
                                            if (row.cells[4]) row.cells[4].innerHTML = isBorrowed ? '<i class="fas fa-arrow-up text-red-500" title="Borrowed"></i>' : '';
                                        }
                                    });
                                    const borrowedCountEl = document.getElementById('borrowedCountBox');
                                    const returnedCountEl = document.getElementById('returnedCountBox');
                                    if (borrowedCountEl && returnedCountEl) {
                                        let currentBorrowed = parseInt(borrowedCountEl.textContent) || 0;
                                        let currentAvailable = parseInt(returnedCountEl.textContent) || 0;
                                        const change = itemIds.length;
                                        if (actionTypeVal === 'ISSUED') { currentBorrowed += change; currentAvailable -= change; }
                                        else { currentBorrowed -= change; currentAvailable += change; }
                                        borrowedCountEl.textContent = Math.max(0, currentBorrowed);
                                        returnedCountEl.textContent = Math.max(0, currentAvailable);
                                    }
                                    if (typeof refreshActivityDrawer === 'function') refreshActivityDrawer();
                                } else alert('Error: ' + (data.message || 'Failed to save logs.'));
                            } catch (e) { alert('Server error occurred.'); }
                        }).finally(() => { if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = originalText; } });
                });
            }
            applyAllFilters();
        }

        // Initialize immediately if DOM is ready, or on DOMContentLoaded
        if (document.readyState === 'complete' || document.readyState === 'interactive') {
            initializeInventory();
        } else {
            document.addEventListener('DOMContentLoaded', initializeInventory);
        }
    </script>