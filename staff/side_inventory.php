<?php
// ✅ AJAX handler for specific item logs
if (isset($_GET['fetch_item_logs']) && isset($_GET['item_id'])) {
    if (ob_get_length())
        ob_clean();
    header('Content-Type: application/json');
    require_once __DIR__ . '/../config.php';
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

// ✅ AJAX handler for activity drawer logs
if (isset($_GET['fetch_drawer_logs'])) {
    if (ob_get_length())

        ob_clean();
    header('Content-Type: application/json');
    require_once __DIR__ . '/../config.php';

    $user_location = 'Mamburao';
    if (isset($_SESSION['user_id'])) {
        $stmt = $mysqli->prepare("SELECT location FROM users WHERE id = ?");
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $user_location = $row['location'];
        }
        $stmt->close();
    }

    $logs = [];
    $stmt = $mysqli->prepare("SELECT id, inventory_id, action_type, item_name, quantity_changed, performed_by, date_time FROM inventory_activity_log WHERE location = ? ORDER BY date_time DESC LIMIT 20");
    $stmt->bind_param("s", $user_location);
    $stmt->execute();
    $log_res = $stmt->get_result();

    while ($row = $log_res->fetch_assoc()) {
        $logs[] = $row;
    }
    $stmt->close();
    echo json_encode(['success' => true, 'logs' => $logs, 'location' => $user_location]);
    exit;
}

// ✅ AJAX handler for inventory item status
if (isset($_GET['fetch_inventory_status'])) {
    if (ob_get_length())
        ob_clean();
    header('Content-Type: application/json');
    require_once __DIR__ . '/../config.php';

    $item_ids = $_GET['item_ids'] ?? '';
    if (empty($item_ids)) {
        echo json_encode(['success' => false, 'message' => 'No item IDs provided']);
        exit;
    }

    $ids = array_filter(array_map('intval', explode(',', $item_ids)));
    if (empty($ids)) {
        echo json_encode(['success' => false, 'message' => 'Invalid item IDs']);
        exit;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $mysqli->prepare("SELECT id, log_stats, status FROM inventory WHERE id IN ($placeholders)");
    $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
    $stmt->execute();
    $result = $stmt->get_result();

    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[$row['id']] = [
            'log_stats' => $row['log_stats'],
            'status' => $row['status'],
            'is_borrowed' => strpos($row['log_stats'] ?? '', 'BORROWED') === 0
        ];
    }
    $stmt->close();
    echo json_encode(['success' => true, 'items' => $items]);
    exit;
}

// Ensure user session is started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit();
}

// Database connection
require_once __DIR__ . '/../config.php';

// Error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', '1'); // Temporarily show errors to diagnose failures

// Security: Check user role
$allowed_roles = ['admin', 'staff', 'supply'];
if (!in_array($_SESSION['role'] ?? '', $allowed_roles)) {
    header("Location: ../index.php?error=unauthorized");
    exit();
}

// Get user's location from database (NOT from session - fetch it fresh)
$user_location = 'Mamburao'; // Default fallback
$user_id = $_SESSION['user_id'];

// Fetch user's location from users table
$user_query = $mysqli->prepare("SELECT location, username FROM users WHERE id = ?");
if (!$user_query) {
    error_log("Prepare failed: " . $mysqli->error);
    die('System Error. Please try again later.');
}
$user_query->bind_param('i', $user_id);
if (!$user_query->execute()) {
    error_log("Execute failed: " . $mysqli->error);
    die('System Error. Please try again later.');
}
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

    try {
        $mysqli->begin_transaction();

        $stmt = $mysqli->prepare("INSERT INTO inventory (category, item, model_no, allocation, status, date_added) VALUES (?, ?, ?, ?, ?, NOW())");
        if (!$stmt)
            throw new Exception("Prepare failed: " . $mysqli->error);

        $stmt->bind_param("sssss", $category, $item, $model_no, $allocation, $status);
        if (!$stmt->execute())
            throw new Exception("Execute failed: " . $stmt->error);

        $new_item_id = $stmt->insert_id;
        $stmt->close();

        // ✅ Log activity: Item Added (action_type MUST match ENUM: ADDED, UPDATED, ISSUED, RETURNED)
        $log_stmt = $mysqli->prepare("INSERT INTO inventory_activity_log (inventory_id, action_type, item_name, quantity_changed, performed_by, location, date_time) VALUES (?, 'ADDED', ?, 1, ?, ?, NOW())");
        if (!$log_stmt)
            throw new Exception("Log prepare failed: " . $mysqli->error);

        $log_stmt->bind_param("isss", $new_item_id, $item, $performed_by, $allocation);
        if (!$log_stmt->execute())
            throw new Exception("Log execute failed: " . $log_stmt->error);

        $log_stmt->close();
        $mysqli->commit();

        if (isset($_POST['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'New inventory item added successfully!']);
            exit;
        }
        echo "<script>alert('New inventory item added successfully!'); window.location.href=window.location.href;</script>";
    } catch (Exception $e) {
        $mysqli->rollback();
        error_log($e->getMessage());
        if (isset($_POST['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Database Error: ' . $e->getMessage()]);
            exit;
        }
        echo "<script>alert('Error: " . addslashes($e->getMessage()) . "'); window.location.href=window.location.href;</script>";
    }
    exit;
}

// ✅ Handle add activity log (Updated for Dynamic Rows)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_activity_log'])) {
    $item_ids = $_POST['log_inventory_id'] ?? [];
    $log_action_type = strtoupper(trim($_POST['log_action_type'] ?? '')); // Ensure uppercase for ENUM
    $log_performed_by = trim($_POST['log_performed_by'] ?? '');

    if (empty($item_ids) || empty($log_action_type) || empty($log_performed_by)) {
        if (isset($_POST['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Please fill in all required fields!']);
            exit;
        }
        echo "<script>alert('Please fill in all required fields!'); window.location.href=window.location.href;</script>";
        exit;
    }

    $unit_qty = ($log_action_type === 'ISSUED') ? -1 : 1;
    $success_count = 0;
    $errors = [];

    foreach ($item_ids as $id) {
        if (empty($id))
            continue;
        $log_inventory_id = intval($id);

        try {
            $mysqli->begin_transaction();

            $item_stmt = $mysqli->prepare("SELECT item, allocation, log_stats FROM inventory WHERE id = ? FOR UPDATE");
            $item_stmt->bind_param("i", $log_inventory_id);
            $item_stmt->execute();
            $item_result = $item_stmt->get_result();

            if ($item_result->num_rows === 0) {
                throw new Exception("Item ID $log_inventory_id not found.");
            }

            $item_data = $item_result->fetch_assoc();
            $item_name = $item_data['item'] ?? 'Unknown';
            $item_location = $user_location;
            $item_stmt->close();

            $is_currently_borrowed = (strpos($item_data['log_stats'] ?? '', 'BORROWED') !== false);
            if ($log_action_type === 'ISSUED' && $is_currently_borrowed) {
                throw new Exception("Item \"$item_name\" is already borrowed.");
            }
            if ($log_action_type === 'RETURNED' && !$is_currently_borrowed) {
                throw new Exception("Item \"$item_name\" is not currently borrowed.");
            }

            // Insert into inventory_activity_log
            $log_stmt = $mysqli->prepare("INSERT INTO inventory_activity_log (inventory_id, action_type, item_name, quantity_changed, performed_by, location, date_time) VALUES (?, ?, ?, ?, ?, ?, NOW())");
            $log_stmt->bind_param("isssss", $log_inventory_id, $log_action_type, $item_name, $unit_qty, $log_performed_by, $user_location);
            if (!$log_stmt->execute())
                throw new Exception("Log entry failed: " . $log_stmt->error);
            $log_stmt->close();

            // Update Inventory status
            if ($log_action_type === 'ISSUED') {
                $status_label = "BORROWED BY: " . $log_performed_by;
                $update_inv = $mysqli->prepare("UPDATE inventory SET log_stats = ?, borrowed_date = NOW(), returned_date = NULL WHERE id = ?");
                $update_inv->bind_param("si", $status_label, $log_inventory_id);
            } else {
                $update_inv = $mysqli->prepare("UPDATE inventory SET log_stats = 'AVAILABLE', returned_date = NOW() WHERE id = ?");
                $update_inv->bind_param("i", $log_inventory_id);
            }
            if (!$update_inv->execute())
                throw new Exception("Inventory update failed.");
            $update_inv->close();

            $mysqli->commit();
            $success_count++;
        } catch (Exception $e) {
            $mysqli->rollback();
            $errors[] = $e->getMessage();
            error_log("Inventory Log Error: " . $e->getMessage());
        }
    }

    if (isset($_POST['ajax'])) {
        header('Content-Type: application/json');
        if (empty($errors)) {
            echo json_encode(['success' => true, 'message' => "Successfully processed $success_count items."]);
        } else {
            echo json_encode(['success' => false, 'message' => "Errors: " . implode(", ", $errors)]);
        }
        exit;
    }

    if (!empty($errors)) {
        echo "<script>alert('Errors: " . addslashes(implode(", ", $errors)) . "'); window.location.href=window.location.href;</script>";
    } else {
        echo "<script>alert('Activity logs added successfully!'); window.location.href=window.location.href;</script>";
    }
    exit;
}

// ✅ Handle edit item update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_item'])) {
    // ... (logic remains same)
}

// ✅ NEW: Handle AJAX request for specific item logs
if (isset($_GET['fetch_item_logs']) && isset($_GET['item_id'])) {
    header('Content-Type: application/json');
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
    echo json_encode(['success' => true, 'logs' => $logs]);
    exit;
}

include_once __DIR__ . '/../profile_modal.php';

// ✅ Fetch items only for user's location
$stmt = $mysqli->prepare("SELECT * FROM inventory WHERE allocation = ? ORDER BY id DESC");
$stmt->bind_param("s", $user_location);
$stmt->execute();
$result = $stmt->get_result();

// ✅ Fetch distinct categories for the filter (Dynamic)
$cat_stmt = $mysqli->prepare("SELECT DISTINCT category FROM inventory WHERE allocation = ? AND category IS NOT NULL AND category != '' ORDER BY category ASC");
$cat_stmt->bind_param("s", $user_location);
$cat_stmt->execute();
$cat_result = $cat_stmt->get_result();
$unique_categories = [];
while ($cat_row = $cat_result->fetch_assoc()) {
    $unique_categories[] = $cat_row['category'];
}
$cat_stmt->close();

// ✅ Fetch activity logs for display
$activity_logs = [];
$log_stmt = $mysqli->prepare("SELECT * FROM inventory_activity_log WHERE location = ? ORDER BY date_time DESC LIMIT 20");
if (!$log_stmt) {
    error_log("Prepare failed for activity logs: " . $mysqli->error);
} else {
    $log_stmt->bind_param("s", $user_location);
    if (!$log_stmt->execute()) {
        error_log("Execute failed for activity logs: " . $log_stmt->error);
    } else {
        $activity_result = $log_stmt->get_result();
        while ($log = $activity_result->fetch_assoc()) {
            $activity_logs[] = $log;
        }
    }
    $log_stmt->close();
}

// ✅ Fetch Summary Statistics for Borrowed and Returned Items
$total_borrowed = 0;
$total_returned = 0;

$borrow_stmt = $mysqli->prepare("SELECT COUNT(*) as total FROM inventory_activity_log WHERE location = ? AND action_type = 'ISSUED'");
if ($borrow_stmt) {
    $borrow_stmt->bind_param("s", $user_location);
    $borrow_stmt->execute();
    $borrow_res = $borrow_stmt->get_result()->fetch_assoc();
    $total_borrowed = $borrow_res['total'] ?? 0;
    $borrow_stmt->close();
}

$return_stmt = $mysqli->prepare("SELECT COUNT(*) as total FROM inventory_activity_log WHERE location = ? AND action_type = 'RETURNED'");
if ($return_stmt) {
    $return_stmt->bind_param("s", $user_location);
    $return_stmt->execute();
    $return_res = $return_stmt->get_result()->fetch_assoc();
    $total_returned = $return_res['total'] ?? 0;
    $return_stmt->close();
}

// ✅ NEW: Fetch Current Inventory Status Counts
$current_borrowed = 0;
$current_available = 0;
$current_returned = 0;

// Simplified and more reliable statistics queries
$stats_stmt = $mysqli->prepare("
    SELECT 
        (SELECT COUNT(*) FROM inventory WHERE allocation = ? AND log_stats LIKE 'BORROWED%') as borrowed,
        (SELECT COUNT(*) FROM inventory WHERE allocation = ? AND (log_stats = 'AVAILABLE' OR log_stats IS NULL OR log_stats = '')) as available,
        (SELECT COUNT(*) FROM inventory WHERE allocation = ? AND returned_date IS NOT NULL) as returned
");
if ($stats_stmt) {
    $stats_stmt->bind_param("sss", $user_location, $user_location, $user_location);
    if ($stats_stmt->execute()) {
        $stats_res = $stats_stmt->get_result()->fetch_assoc();
        $current_borrowed = $stats_res['borrowed'] ?? 0;
        $current_available = $stats_res['available'] ?? 0;
        $current_returned = $stats_res['returned'] ?? 0;
    }
    $stats_stmt->close();
}
?>

<style>
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
    }

    .action-btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
    }

    .table-scroll-container {
        max-height: 800px;
        overflow-y: auto;
        overflow-x: auto;
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

    .filter-select {
        min-width: 140px;
        transition: all 0.2s ease;
        font-weight: 600;
        font-size: 14px;
        height: 48px;
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
        min-width: 110px;
        transition: transform 0.2s ease;
    }

    .count-box.borrowed {
        background: #991b1b;
    }

    .count-box.returned {
        background: #166534;
    }

    .count-box:hover {
        transform: translateY(-2px);
    }

    .count-number {
        font-size: 24px;
        font-weight: 800;
        line-height: 1;
    }

    .count-label {
        font-size: 9px;
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

    .drawer-backdrop {
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

    .modal {
        z-index: 100001 !important;
    }

    .modal-backdrop {
        z-index: 100000 !important;
    }

    /* Activity Log Panel */
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
        color: #0f172a;
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
        flex-wrap: wrap;
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

    /* Mobile Specific Improvements */
    @media (max-width: 768px) {
        .count-box {
            padding: 8px 12px;
            min-width: 80px;
        }

        .count-number {
            font-size: 18px;
        }

        .count-label {
            font-size: 8px;
        }

        .action-btn {
            height: 40px;
            font-size: 12px;
            padding: 0 12px !important;
        }

        .filter-select {
            height: 40px;
            font-size: 12px;
            min-width: 100px;
        }

        #inventoryTable thead th {
            padding: 12px 16px;
            font-size: 10px;
        }

        .inventory-row td {
            padding: 12px 16px;
            font-size: 13px;
        }

        .px-6 {
            padding-left: 1rem !important;
            padding-right: 1rem !important;
        }

        .py-5 {
            padding-top: 1rem !important;
            padding-bottom: 1rem !important;
        }

        .flex-row-mobile {
            flex-direction: row !important;
            flex-wrap: wrap;
            justify-content: center;
            gap: 8px !important;
        }

        .stats-text-mobile {
            font-size: 11px !important;
        }

        .log-drawer {
            max-width: 100%;
        }

        /* Hide some columns on very small screens to maintain visibility */
        @media (max-width: 480px) {
            .hide-on-mobile {
                display: none !important;
            }
        }
    }

    /* Custom Select2 Styling for Log Modal */
    .select2-container--default .select2-selection--single {
        height: 38px !important;
        padding: 5px 8px !important;
        border-radius: 10px !important;
        border: 1px solid #dee2e6 !important;
        font-size: 12px !important;
        font-weight: 600 !important;
    }

    .select2-container--default .select2-selection--single .select2-selection__rendered {
        line-height: 26px !important;
    }

    .select2-container--default .select2-selection--single .select2-selection__arrow {
        height: 36px !important;
    }

    .select2-dropdown {
        border-radius: 12px !important;
        border: 1px solid #e5e7eb !important;
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1) !important;
        z-index: 100002 !important;
    }
</style>

<!-- Load Select2 CSS and jQuery/JS -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<div class="inventory-wrapper">
    <div class="inventory-table-section">
        <div class="card p-2 p-md-3 shadow-sm">
            <div class="bg-white rounded-xl shadow-sm overflow-hidden">
                <div class="px-4 px-md-6 py-3 py-md-4 border-b border-gray-200 bg-gray-50">
                    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-2">
                        <div>
                            <h2 class="text-base md:text-lg font-bold text-gray-800 uppercase tracking-tight">INVENTORY
                            </h2>
                            <p class="text-gray-500 text-xs mt-0.5">Manage stock for your location.</p>
                        </div>
                        <div class="flex items-center">
                            <span class="text-xs font-bold text-blue-600 bg-blue-50 px-3 py-1 rounded-full"
                                id="resultCountDisplay">
                                <?php echo ($result ? $result->num_rows : 0); ?> items
                            </span>
                        </div>
                    </div>
                </div>

                <div class="px-4 px-md-6 py-4 border-b border-gray-200 bg-gray-50 space-y-4">
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-search text-gray-400"></i>
                        </div>
                        <input type="text" id="searchInput"
                            class="w-full pl-9 pr-4 py-2 md:py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 shadow-sm text-sm"
                            placeholder="Search items...">
                    </div>

                    <div class="flex flex-col gap-4">
                        <div
                            class="flex items-right justify-between md:justify-start gap-2 md:gap-4 overflow-x-auto pb-2 scrollbar-none flex-row-mobile">
                            <div class="count-box">
                                <span class="count-number" id="totalCountBox">0</span>
                                <span class="count-label">Total</span>
                            </div>
                            <div
                                class="flex flex-col md:flex-row md:items-center gap-2 md:gap-4 ml-0 md:ml-4 stats-text-mobile">
                                <div class="flex items-center gap-1.5">
                                    <i class="fas fa-arrow-up text-red-500 text-[10px]"></i>
                                    <span class="text-[10px] font-bold text-slate-400 uppercase">Out:</span>
                                    <span class="text-xs font-black text-slate-700"
                                        id="borrowedCountBox"><?php echo $current_borrowed; ?></span>
                                </div>
                                <div class="flex items-center gap-1.5">
                                    <i class="fas fa-arrow-down text-green-500 text-[10px]"></i>
                                    <span class="text-[10px] font-bold text-slate-400 uppercase">stocks:</span>
                                    <span class="text-xs font-black text-slate-700"
                                        id="returnedCountBox"><?php echo $current_returned; ?></span>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 md:flex md:flex-row gap-2">
                            <select
                                class="filter-select px-3 py-2 border border-gray-300 rounded-xl bg-white font-bold text-xs"
                                id="statusFilter">
                                <option value="">ALL STATUS</option>
                                <option value="GOOD">GOOD</option>
                                <option value="LOW">LOW</option>
                                <option value="WORN_OUT">WORN OUT</option>
                            </select>

                            <select
                                class="filter-select px-3 py-2 border border-gray-300 rounded-xl bg-white font-bold text-xs"
                                id="categoryFilter">
                                <option value="">ALL CATEGORIES</option>
                                <?php foreach ($unique_categories as $cat): ?>
                                    <option value="<?= htmlspecialchars(strtolower(trim($cat))) ?>">
                                        <?= strtoupper(htmlspecialchars($cat)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <button id="clearFilters"
                                class="action-btn border border-gray-300 bg-white text-gray-700 px-4 rounded-xl font-bold text-xs hover:bg-gray-50 flex-1 md:flex-none">
                                <i class="fas fa-undo-alt mr-1.5"></i> RESET
                            </button>

                            <button id="openLogDrawer"
                                class="action-btn bg-slate-800 text-white px-4 rounded-xl font-bold text-xs shadow-sm flex-1 md:flex-none">
                                <i class="fas fa-history mr-1.5"></i> LOGS
                            </button>

                            <!-- <button
                                class="action-btn bg-blue-600 text-white px-4 rounded-xl font-bold text-xs shadow-sm col-span-2 md:flex-none"
                                data-bs-toggle="modal" data-bs-target="#addModal">
                                <i class="fas fa-plus-circle mr-1.5"></i> ADD NEW
                            </button> -->
                        </div>
                    </div>
                </div>

                <div class="table-scroll-container">
                    <table class="min-w-full divide-y divide-gray-200" id="inventoryTable">
                        <thead>
                            <tr>
                                <th class="px-4 py-3 text-left">#</th>
                                <th class="px-4 py-3 text-left hide-on-mobile">CATEGORY</th>
                                <th class="px-4 py-3 text-left">ITEM NAME</th>
                                <th class="px-4 py-3 text-left">MODEL NO.</th>
                                <th class="px-2 py-3 text-center w-8"></th>
                                <th class="px-4 py-3 text-left">STATUS</th>
                                <th class="px-4 py-3 text-center">ACTION</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white" id="inventoryTableBody">
                            <?php
                            $counter = 1;
                            if ($result && $result->num_rows > 0):
                                while ($part = $result->fetch_assoc()):
                                    $rawStatus = trim($part['status'] ?? 'GOOD');
                                    $is_borrowed = (strpos(($part['log_stats'] ?? ''), 'BORROWED') === 0);

                                    $statusText = strtoupper($rawStatus);
                                    $badgeClass = match ($statusText) {
                                        'LOW' => 'low',
                                        'WORN_OUT', 'WORN OUT' => 'worn_out',
                                        default => 'good'
                                    };
                                    ?>
                                    <tr class="inventory-row" data-id="<?= $part['id'] ?>"
                                        data-borrowed="<?= $is_borrowed ? '1' : '0' ?>">
                                        <td class="px-4 py-4 font-bold text-slate-400">#<?= $counter++ ?></td>
                                        <td class="px-4 py-4 hide-on-mobile">
                                            <span
                                                class="text-xs font-bold text-slate-500 uppercase"><?= htmlspecialchars($part['category'] ?? 'N/A') ?></span>
                                        </td>
                                        <td class="px-4 py-4 font-bold text-slate-800"><?= htmlspecialchars($part['item']) ?>
                                        </td>
                                        <td class="px-4 py-4 text-slate-600 text-xs font-medium">
                                            <?= htmlspecialchars($part['model_no']) ?>
                                        </td>
                                        <td class="px-2 py-4 text-center borrowed-icon-cell">
                                            <?php if ($is_borrowed): ?>
                                                <i class="fas fa-arrow-up text-red-500" title="Borrowed"></i>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-4 status-cell">
                                            <span class="status-badge <?= $badgeClass ?>"><?= $statusText ?></span>
                                        </td>
                                        <td class="px-4 py-4 text-center">
                                            <button
                                                class="btn btn-lg btn-outline-primary rounded-pill px-3 py-1 font-bold text-[10px] view-history-btn"
                                                data-id="<?= $part['id'] ?>" data-name="<?= htmlspecialchars($part['item']) ?>">
                                                <i class="fas fa-history mr-1"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="px-6 py-12 text-center bg-slate-50/50">
                                        <div class="flex flex-col items-center">
                                            <i class="fas fa-box-open text-3xl text-slate-300 mb-3"></i>
                                            <h3 class="text-sm font-bold text-slate-800">No inventory found</h3>
                                            <p class="text-[10px] text-slate-500 mb-4">Add your first item to start.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="bg-gray-50 px-4 py-3 border-t border-gray-200">
                    <div
                        class="flex items-center justify-between text-[10px] font-bold text-slate-500 uppercase tracking-widest">
                        <div class="flex items-center">
                            <i class="fas fa-map-marker-alt mr-1.5 text-blue-500"></i>
                            <span><?= htmlspecialchars($user_location); ?></span>
                        </div>
                        <div id="filteredCount">
                            Showing <?= ($result ? $result->num_rows : 0); ?> items
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Log Drawer System -->
    <div class="drawer-backdrop" id="drawerBackdrop"></div>
    <div class="log-drawer" id="logDrawer">
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
            <div class="activity-log-body">
                <?php if (!empty($activity_logs)): ?>
                    <?php foreach ($activity_logs as $log): ?>
                        <?php
                        $action_class = strtolower($log['action_type']);
                        $action_icon = match ($log['action_type']) {
                            'ADDED' => 'fa-plus',
                            'UPDATED' => 'fa-edit',
                            'ISSUED' => 'fa-arrow-up',
                            'RETURNED' => 'fa-arrow-down',
                            default => 'fa-circle'
                        };
                        $quantity_change = $log['quantity_changed'] ?? 0;
                        $qty_class = $quantity_change > 0 ? 'positive' : ($quantity_change < 0 ? 'negative' : '');
                        ?>
                        <div class="activity-item">
                            <div class="activity-icon <?= $action_class ?>">
                                <i class="fas <?= $action_icon ?>"></i>
                            </div>
                            <div class="activity-content">
                                <div class="activity-title">
                                    <span
                                        class="font-black uppercase text-[10px]"><?= htmlspecialchars($log['action_type']) ?></span>
                                    <span class="text-slate-500 font-bold ml-1">:
                                        <?= htmlspecialchars($log['item_name']) ?></span>
                                </div>
                                <div class="activity-meta">
                                    <span class="flex items-center gap-1"><i class="far fa-user opacity-50"></i>
                                        <?= htmlspecialchars($log['performed_by']) ?></span>
                                    <span class="flex items-center gap-1"><i class="far fa-clock opacity-50"></i>
                                        <?= date('M d, h:i A', strtotime($log['date_time'])) ?></span>
                                    <?php if ($quantity_change != 0): ?>
                                        <span class="activity-quantity <?= $qty_class ?> ml-auto">
                                            <?= $quantity_change > 0 ? '+' : '' ?>             <?= $quantity_change ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="flex flex-col items-center py-10">
                        <i class="fas fa-history text-slate-200 text-3xl mb-3"></i>
                        <p class="text-xs font-bold text-slate-400">No records found.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ✅ Add Modal -->
    <div class="modal fade" id="addModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg">
                <form method="POST" id="addItemForm">
                    <div class="modal-header bg-blue-600 text-white py-3">
                        <h5 class="modal-title text-sm font-black uppercase tracking-wider">
                            <i class="fas fa-box mr-2"></i>Add Item
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body p-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="space-y-1">
                                <label class="text-xs font-bold text-slate-500 uppercase">Category</label>
                                <select name="category" id="categorySelect"
                                    class="w-full px-3 py-2 border rounded-xl text-sm font-medium focus:ring-2 focus:ring-blue-500 outline-none"
                                    required>
                                    <option value="" selected disabled>Select...</option>
                                    <option value="Electronics">Electronics</option>
                                    <option value="Office Supplies">Office Supplies</option>
                                    <option value="Tools">Tools</option>
                                    <option value="Equipment">Equipment</option>
                                    <option value="Other">Other</option>
                                </select>
                                <div id="otherCategoryDiv" class="mt-2" style="display: none;">
                                    <input type="text" name="other_category" id="otherCategoryInput"
                                        class="w-full px-3 py-2 border rounded-xl text-sm font-medium"
                                        placeholder="Custom category...">
                                </div>
                            </div>

                            <div class="space-y-1">
                                <label class="text-xs font-bold text-slate-500 uppercase">Item Name</label>
                                <input type="text" name="item"
                                    class="w-full px-3 py-2 border rounded-xl text-sm font-medium focus:ring-2 focus:ring-blue-500 outline-none"
                                    required placeholder="Name...">
                            </div>

                            <div class="space-y-1">
                                <label class="text-xs font-bold text-slate-500 uppercase">Model No.</label>
                                <input type="text" name="model_no"
                                    class="w-full px-3 py-2 border rounded-xl text-sm font-medium focus:ring-2 focus:ring-blue-500 outline-none"
                                    required>
                            </div>

                            <div class="space-y-1">
                                <label class="text-xs font-bold text-slate-500 uppercase">Allocation</label>
                                <input type="text"
                                    class="w-full px-3 py-2 border rounded-xl bg-gray-50 text-slate-500 text-sm font-bold"
                                    value="<?= htmlspecialchars($user_location); ?>" readonly>
                                <input type="hidden" name="allocation" value="<?= htmlspecialchars($user_location); ?>">
                            </div>

                            <div class="space-y-1">
                                <label class="text-xs font-bold text-slate-500 uppercase">Status</label>
                                <select name="status"
                                    class="w-full px-3 py-2 border rounded-xl text-sm font-medium focus:ring-2 focus:ring-blue-500 outline-none"
                                    required>
                                    <option value="GOOD">Good</option>
                                    <option value="LOW">Low</option>
                                    <option value="WORN_OUT">Worn Out</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer bg-gray-50 py-3 px-4">
                        <button type="button" class="btn btn-light px-4 font-bold text-xs"
                            data-bs-dismiss="modal">CANCEL</button>
                        <button type="submit" name="add_item" class="btn btn-primary px-4 font-bold text-xs">SAVE
                            ITEM</button>
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
                    <div class="modal-header bg-success text-white py-3">
                        <h5 class="modal-title text-sm font-black uppercase tracking-wider">
                            <i class="fas fa-plus-circle mr-2"></i>Add Log
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body p-4">
                        <label class="text-xs font-bold text-slate-500 uppercase mb-2 block">Items</label>
                        <div id="logItemsContainer" class="space-y-2 mb-4 pr-1 overflow-y-auto"
                            style="max-height: 250px;">
                            <div
                                class="log-item-row flex items-center gap-2 bg-slate-50 p-2 rounded-xl border border-slate-200">
                                <div class="flex-1">
                                    <select name="log_inventory_id[]"
                                        class="form-select text-xs font-bold log-item-select" required>
                                        <option value="">Select...</option>
                                        <?php
                                        $items_stmt = $mysqli->prepare("SELECT id, item, model_no, log_stats FROM inventory WHERE allocation = ? ORDER BY item ASC");
                                        if ($items_stmt) {
                                            $items_stmt->bind_param("s", $user_location);
                                            $items_stmt->execute();
                                            $items_result = $items_stmt->get_result();
                                            while ($item_row = $items_result->fetch_assoc()):
                                                $is_borrowed_opt = (strpos(($item_row['log_stats'] ?? ''), 'BORROWED') !== false);
                                                ?>
                                                <option value="<?= $item_row['id'] ?>"
                                                    data-model="<?= htmlspecialchars($item_row['model_no'] ?? '') ?>"
                                                    data-status="<?= $is_borrowed_opt ? 'BORROWED' : 'AVAILABLE' ?>">
                                                    <?= htmlspecialchars($item_row['item'] ?? 'Unknown') . ' - ' . htmlspecialchars($item_row['model_no'] ?? 'N/A') . ($is_borrowed_opt ? ' (Borrowed)' : ' (Available)') ?>
                                                </option>
                                            <?php endwhile;
                                            $items_stmt->close();
                                        } else {
                                            error_log("Failed to prepare items statement: " . $mysqli->error);
                                        }
                                        ?>
                                    </select>
                                </div>
                                <button type="button"
                                    class="add-row-btn w-8 h-8 bg-blue-600 text-white rounded-lg flex items-center justify-center hover:bg-blue-700 transition-colors shadow-sm">
                                    <i class="fas fa-plus text-[10px]"></i>
                                </button>
                            </div>
                        </div>

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

                        <div class="mb-1">
                            <label class="text-xs font-bold text-slate-500 uppercase mb-1 block">Performed By</label>
                            <input type="text" name="log_performed_by" class="form-control text-xs font-bold"
                                value="<?= htmlspecialchars($performed_by) ?>" required>
                        </div>
                    </div>

                    <div class="modal-footer bg-light py-3">
                        <button type="button" class="btn btn-secondary text-xs font-bold"
                            data-bs-dismiss="modal">CANCEL</button>
                        <button type="submit" name="add_activity_log"
                            class="btn btn-success text-xs font-bold px-4">SAVE LOG</button>
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

    <!-- Scripts -->
    <script>
        (function () {
            console.log("Inventory script initialized!");

            // ✅ History Modal Handler
            const itemHistoryModal = new bootstrap.Modal(document.getElementById('itemHistoryModal'));
            const logsContainer = document.getElementById('itemLogsList');
            const historyTitle = document.getElementById('historyItemName');
            const currentBadgeContainer = document.getElementById('historyCurrentBadge');

            document.addEventListener('click', function (e) {
                const btn = e.target.closest('.view-history-btn');
                if (btn) {
                    const itemId = btn.getAttribute('data-id');
                    const itemName = btn.getAttribute('data-name');

                    // Find the status from the table row
                    const row = btn.closest('tr');
                    const statusBadge = row.querySelector('.status-badge');
                    const isBorrowed = row.getAttribute('data-borrowed') === '1';

                    historyTitle.textContent = itemName;
                    currentBadgeContainer.innerHTML = isBorrowed
                        ? `<span class="px-2 py-1 bg-red-100 text-red-700 text-[10px] font-black rounded-lg border border-red-200 uppercase">ISSUED / OUT</span>`
                        : `<span class="px-2 py-1 bg-green-100 text-green-700 text-[10px] font-black rounded-lg border border-green-200 uppercase">AVAILABLE / IN</span>`;

                    logsContainer.innerHTML = '<div class="flex flex-col items-center py-10"><i class="fas fa-spinner fa-spin text-2xl text-blue-500 mb-2"></i><p class="text-[10px] font-bold text-slate-400">LOADING LOGS...</p></div>';

                    itemHistoryModal.show();

                    fetch(`load_content.php?view=inventory&fetch_item_logs=1&item_id=${itemId}`)
                        .then(res => res.json())
                        .then(data => {
                            if (data.success && data.logs.length > 0) {
                                logsContainer.innerHTML = data.logs.map(log => {
                                    const action = log.action_type.toUpperCase();
                                    let badgeColor = 'bg-blue-100 text-blue-600 border-blue-200';
                                    let icon = 'fa-info-circle';
                                    let iconBg = 'bg-blue-50 text-blue-500';

                                    if (action === 'ISSUED') {
                                        badgeColor = 'bg-orange-100 text-orange-700 border-orange-200';
                                        icon = 'fa-arrow-up';
                                        iconBg = 'bg-orange-50 text-orange-500';
                                    } else if (action === 'RETURNED') {
                                        badgeColor = 'bg-emerald-100 text-emerald-700 border-emerald-200';
                                        icon = 'fa-arrow-down';
                                        iconBg = 'bg-emerald-50 text-emerald-500';
                                    } else if (action === 'ADDED') {
                                        badgeColor = 'bg-indigo-100 text-indigo-700 border-indigo-200';
                                        icon = 'fa-plus';
                                        iconBg = 'bg-indigo-50 text-indigo-500';
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
                                            </div>
                                        `;
                                }).join('');
                            } else {
                                logsContainer.innerHTML = '<div class="flex flex-col items-center py-10"><i class="fas fa-history text-slate-200 text-3xl mb-3"></i><p class="text-xs font-bold text-slate-400">No logs found for this item.</p></div>';
                            }
                        })
                        .catch(err => {
                            console.error(err);
                            logsContainer.innerHTML = '<div class="p-10 text-center text-red-500 text-xs font-bold">Failed to load logs.</div>';
                        });
                }
            });


            // Function to initialize Select2 on a specific element
            function initSelect2(element) {
                if (window.jQuery && jQuery.fn.select2) {
                    const $el = jQuery(element);

                    // Destroy if already initialized
                    if ($el.data('select2')) {
                        $el.select2('destroy');
                    }

                    $el.select2({
                        dropdownParent: jQuery('#addLogModal'),
                        placeholder: "Search for an item...",
                        width: '100%',
                        allowClear: true,
                        dropdownAutoWidth: true
                    });
                }
            }

            // Log Drawer Functionality
            const openLogDrawerBtn = document.getElementById('openLogDrawer');
            const closeLogDrawerBtn = document.getElementById('closeLogDrawer');
            const logDrawer = document.getElementById('logDrawer');
            const drawerBackdrop = document.getElementById('drawerBackdrop');

            function openDrawer() {
                if (logDrawer && drawerBackdrop) {
                    logDrawer.classList.add('open');
                    drawerBackdrop.classList.add('show');
                    document.body.style.overflow = 'hidden';
                }
            }

            function closeDrawer() {
                if (logDrawer && drawerBackdrop) {
                    logDrawer.classList.remove('open');
                    drawerBackdrop.classList.remove('show');
                    document.body.style.overflow = '';
                }
            }

            if (openLogDrawerBtn) openLogDrawerBtn.onclick = openDrawer;
            if (closeLogDrawerBtn) closeLogDrawerBtn.onclick = closeDrawer;
            if (drawerBackdrop) drawerBackdrop.onclick = closeDrawer;

            // Item Row Logic
            const container = document.getElementById('logItemsContainer');
            const totalQtyInput = document.getElementById('log_quantity');
            const actionTypeInput = document.getElementById('log_action_type');

            function refreshItemOptions() {
                const action = actionTypeInput.value;
                const selects = container.querySelectorAll('.log-item-select');

                // Get all currently selected values in this modal
                const selectedValues = Array.from(selects).map(s => s.value).filter(v => v !== "");

                selects.forEach(select => {
                    const options = select.options;
                    const currentVal = select.value;
                    let hasChanged = false;

                    for (let i = 0; i < options.length; i++) {
                        const opt = options[i];
                        if (!opt.value) continue;

                        const status = opt.getAttribute('data-status');
                        let shouldDisable = false;

                        // 1. Action Type Validation
                        if (action === 'ISSUED') { // BORROWing
                            if (status === 'BORROWED') shouldDisable = true;
                        } else { // RETURNing
                            if (status === 'AVAILABLE') shouldDisable = true;
                        }

                        // 2. Duplicate Selection Validation
                        if (selectedValues.includes(opt.value) && opt.value !== currentVal) {
                            shouldDisable = true;
                        }

                        if (opt.disabled !== shouldDisable) {
                            opt.disabled = shouldDisable;
                            hasChanged = true;
                        }
                    }

                    // Notify Select2 of changes to underlying select
                    if (hasChanged && window.jQuery && jQuery(select).data('select2')) {
                        jQuery(select).trigger('change.select2');
                    }
                });
            }

            function updateQty() {
                if (!container || !totalQtyInput || !actionTypeInput) return;
                const count = container.querySelectorAll('.log-item-row').length;
                totalQtyInput.value = (actionTypeInput.value === 'ISSUED' ? -count : count);
                refreshItemOptions();
            }

            if (container) {
                container.onclick = function (e) {
                    if (e.target.closest('.add-row-btn')) {
                        const originalRow = container.querySelector('.log-item-row');

                        // Clone row
                        const newRow = originalRow.cloneNode(true);
                        const newSelect = newRow.querySelector('select');

                        // IMPORTANT: Manual Cleanup of Cloned Select2 Elements
                        // When cloning, Select2's hidden select and container are cloned. We must scrub them.
                        if (window.jQuery) {
                            const $newSelect = jQuery(newSelect);
                            // Remove Select2 classes and ID
                            $newSelect.removeClass('select2-hidden-accessible').removeAttr('data-select2-id');
                            // Remove the cloned Select2 visual container (the span after the select)
                            newRow.querySelectorAll('.select2-container').forEach(el => el.remove());
                            // Clear values
                            $newSelect.val('');
                            // Scrub option IDs
                            $newSelect.find('option').removeAttr('data-select2-id');
                        }

                        const btn = newRow.querySelector('.add-row-btn');
                        btn.classList.replace('bg-blue-600', 'bg-red-500');
                        btn.classList.add('remove-row-btn');
                        btn.classList.remove('add-row-btn');
                        btn.innerHTML = '<i class="fas fa-trash text-[10px]"></i>';

                        container.appendChild(newRow);

                        // Initialize Select2 on the fresh clean select
                        initSelect2(newSelect);

                        updateQty();
                    } else if (e.target.closest('.remove-row-btn')) {
                        const row = e.target.closest('.log-item-row');
                        if (window.jQuery) {
                            const $sel = jQuery(row).find('select');
                            if ($sel.data('select2')) $sel.select2('destroy');
                        }
                        row.remove();
                        updateQty();
                    }
                };

                // Trigger refresh whenever a selection is made
                jQuery(container).on('change', '.log-item-select', function () {
                    updateQty();
                });
            }

            if (actionTypeInput) {
                actionTypeInput.onchange = function () {
                    const selects = container.querySelectorAll('.log-item-select');
                    selects.forEach(s => {
                        s.value = '';
                        if (window.jQuery && jQuery(s).data('select2')) {
                            jQuery(s).val('').trigger('change.select2');
                        }
                    });
                    updateQty();
                };
            }

            // --- Aggressive Initialization for First Row ---
            function ensureInit() {
                if (window.jQuery && jQuery.fn.select2) {
                    const selects = container.querySelectorAll('.log-item-select');
                    if (selects.length > 0) {
                        selects.forEach(initSelect2);
                        console.log("Select2 initialized on " + selects.length + " selects.");
                    }
                } else {
                    // Retry if libraries not ready
                    setTimeout(ensureInit, 100);
                }
            }

            // Run once libraries are likely ready
            setTimeout(ensureInit, 200);

            updateQty();

            // Search and Filter Logic
            const searchInput = document.getElementById('searchInput');
            const statusFilter = document.getElementById('statusFilter');
            const categoryFilter = document.getElementById('categoryFilter');
            const clearFiltersBtn = document.getElementById('clearFilters');

            function applyFilters() {
                const term = searchInput.value.toLowerCase().trim();
                const status = statusFilter.value.toUpperCase();
                const cat = categoryFilter.value.toLowerCase().trim();
                const rows = document.querySelectorAll('.inventory-row');

                let visible = 0;
                let visibleAvailable = 0;
                let visibleBorrowed = 0;

                rows.forEach(row => {
                    const text = row.textContent.toLowerCase();
                    const rowCat = row.cells[1] ? row.cells[1].textContent.toLowerCase() : '';
                    const rowStatus = row.cells[5] ? row.cells[5].textContent.toUpperCase() : '';
                    const isBorrowed = row.getAttribute('data-borrowed') === '1';

                    const matchesSearch = !term || text.includes(term);
                    const matchesStatus = !status || rowStatus.includes(status);
                    const matchesCat = !cat || rowCat.includes(cat);

                    if (matchesSearch && matchesStatus && matchesCat) {
                        row.style.display = '';
                        visible++;

                        // Update logical counts based on data-borrowed attribute
                        if (isBorrowed) {
                            visibleBorrowed++;
                        } else {
                            visibleAvailable++;
                        }

                        row.cells[0].textContent = '#' + visible;
                    } else {
                        row.style.display = 'none';
                    }
                });

                // Update Display Text
                const countDisp = document.getElementById('resultCountDisplay');
                const filtDisp = document.getElementById('filteredCount');
                if (countDisp) countDisp.textContent = visible + ' items';
                if (filtDisp) filtDisp.textContent = 'Showing ' + visible + ' items';

                // Update Top Count Boxes
                const totalBox = document.getElementById('totalCountBox');
                const availBox = document.getElementById('availableCountBox');
                const borrowBox = document.getElementById('borrowedCountBox');
                const inBox = document.getElementById('returnedCountBox'); // This is the "In:" label

                if (totalBox) totalBox.textContent = visible;
                if (availBox) availBox.textContent = visibleAvailable;
                if (borrowBox) borrowBox.textContent = visibleBorrowed;
                if (inBox) inBox.textContent = visibleAvailable;
            }

            if (searchInput) searchInput.oninput = applyFilters;
            if (statusFilter) statusFilter.onchange = applyFilters;
            if (categoryFilter) categoryFilter.onchange = applyFilters;
            if (clearFiltersBtn) {
                clearFiltersBtn.onclick = () => {
                    searchInput.value = '';
                    statusFilter.value = '';
                    categoryFilter.value = '';
                    applyFilters();
                };
            }

            // Initial call to set counts correctly on page load
            applyFilters();

            // Category "Other" Toggle
            const categorySelect = document.getElementById('categorySelect');
            if (categorySelect) {
                categorySelect.onchange = function () {
                    const otherDiv = document.getElementById('otherCategoryDiv');
                    if (otherDiv) {
                        otherDiv.style.display = (this.value === 'Other' ? 'block' : 'none');
                        const otherInput = document.getElementById('otherCategoryInput');
                        if (otherInput) otherInput.required = (this.value === 'Other');
                    }
                };
            }

            // ✅ Function to refresh inventory table rows (staff version)
            function refreshInventoryTable(itemIds) {
                if (!itemIds || itemIds.length === 0) return;

                const idsParam = itemIds.join(',');
                fetch('side_inventory.php?fetch_inventory_status=1&item_ids=' + idsParam, {
                    credentials: 'include'
                })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success && data.items) {
                            itemIds.forEach(id => {
                                const item = data.items[id];
                                if (!item) return;

                                const row = document.querySelector('tr[data-id="' + id + '"]');
                                if (!row) return;

                                row.setAttribute('data-borrowed', item.is_borrowed ? '1' : '0');

                                // Update borrowed icon cell
                                const iconCell = row.querySelector('.borrowed-icon-cell');
                                if (iconCell) {
                                    iconCell.innerHTML = item.is_borrowed
                                        ? '<i class="fas fa-arrow-up text-red-500" title="Borrowed"></i>'
                                        : '';
                                }

                                // Update status cell with actual item status (no badge)
                                const statusCell = row.querySelector('.status-cell');
                                if (statusCell && item.status) {
                                    statusCell.textContent = item.status;
                                }
                            });
                        }
                    })
                    .catch(err => console.error('Error refreshing table:', err));
            }

            // ✅ Function to refresh activity drawer (staff version)
            function refreshActivityDrawer() {
                fetch('side_inventory.php?fetch_drawer_logs=1', {
                    credentials: 'include'
                })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success && data.logs) {
                            const drawerBody = document.querySelector('.activity-log-body');
                            if (!drawerBody) return;

                            if (data.logs.length === 0) {
                                drawerBody.innerHTML = '<div class="activity-empty"><p class="text-slate-400 text-sm">No activity logs yet.</p></div>';
                                return;
                            }

                            let html = '';
                            data.logs.forEach(log => {
                                const actionClass = log.action_type.toLowerCase();
                                const actionIcon = {
                                    'ADDED': 'fa-plus',
                                    'UPDATED': 'fa-edit',
                                    'ISSUED': 'fa-arrow-up',
                                    'RETURNED': 'fa-arrow-down'
                                }[log.action_type] || 'fa-circle';

                                const qty = log.quantity_changed || 0;
                                const qtyClass = qty > 0 ? 'positive' : (qty < 0 ? 'negative' : '');

                                const date = new Date(log.date_time);
                                const formattedDate = date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true });

                                html += '<div class="activity-item">' +
                                    '<div class="activity-icon ' + actionClass + '">' +
                                    '<i class="fas ' + actionIcon + '"></i>' +
                                    '</div>' +
                                    '<div class="activity-content">' +
                                    '<div class="activity-title">' +
                                    '<span class="font-bold">' + log.action_type + '</span>:' +
                                    '<span class="text-slate-600">' + log.item_name + '</span>' +
                                    '</div>' +
                                    '<div class="activity-meta">' +
                                    '<span class="flex items-center gap-2">' +
                                    '<i class="far fa-user"></i> ' + log.performed_by +
                                    '</span>' +
                                    '<span class="w-1.5 h-1.5 rounded-full bg-slate-200"></span>' +
                                    '<span class="flex items-center gap-2">' +
                                    '<i class="far fa-clock"></i> ' + formattedDate +
                                    '</span>' +
                                    (qty != 0 ? '<span class="activity-quantity ' + qtyClass + ' ml-auto">' + (qty > 0 ? '+' : '') + qty + '</span>' : '') +
                                    '</div>' +
                                    '</div>' +
                                    '</div>';
                            });
                            drawerBody.innerHTML = html;
                        }
                    })
                    .catch(err => console.error('Error refreshing drawer:', err));
            }

            // AJAX Form Submission for Add Item
            const addItemForm = document.getElementById('addItemForm');
            if (addItemForm) {
                addItemForm.onsubmit = function (e) {
                    e.preventDefault();
                    const formData = new FormData(this);
                    formData.append('ajax', '1');
                    formData.append('add_item', '1');

                    const submitBtn = this.querySelector('button[type="submit"]');
                    const originalText = submitBtn.innerHTML;
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> SAVING...';

                    fetch('load_content.php?view=inventory', {
                        method: 'POST',
                        body: formData
                    })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                // 1. Close modal
                                const modalEl = document.getElementById('addModal');
                                const modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
                                modal.hide();

                                // 2. Reset form
                                addItemForm.reset();
                                if (document.getElementById('otherCategoryDiv')) {
                                    document.getElementById('otherCategoryDiv').style.display = 'none';
                                }

                                // 3. Alert and Refresh
                                alert(data.message);
                                if (typeof window.loadContent === 'function') {
                                    window.loadContent('inventory');
                                } else {
                                    window.location.reload();
                                }
                            } else {
                                alert('Error: ' + (data.message || 'Failed to add item.'));
                            }
                        })
                        .catch(error => {
                            console.error('Submission error:', error);
                            alert('An unexpected error occurred. Please check console.');
                        })
                        .finally(() => {
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = originalText;
                        });
                };
            }

            // AJAX Form Submission for Add Log
            var addLogForm = document.getElementById('addLogForm');
            if (!addLogForm) return;
            if (addLogForm) {
                addLogForm.onsubmit = function (e) {
                    e.preventDefault();
                    const formData = new FormData(this);
                    formData.append('ajax', '1');
                    formData.append('add_activity_log', '1');

                    // Get item IDs before form reset
                    const formDataObj = {};
                    formData.forEach((value, key) => {
                        if (formDataObj[key]) {
                            if (Array.isArray(formDataObj[key])) {
                                formDataObj[key].push(value);
                            } else {
                                formDataObj[key] = [formDataObj[key], value];
                            }
                        } else {
                            formDataObj[key] = value;
                        }
                    });
                    const processedIds = Array.isArray(formDataObj['log_inventory_id']) ? formDataObj['log_inventory_id'] : [formDataObj['log_inventory_id']];

                    const submitBtn = this.querySelector('button[type="submit"]');
                    const originalText = submitBtn.innerHTML;
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> SAVING LOGS...';

                    fetch('load_content.php?view=inventory', {
                        method: 'POST',
                        body: formData
                    })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                // Close modal
                                const modalEl = document.getElementById('addLogModal');
                                const modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
                                modal.hide();

                                // Reset form and remove extra rows
                                addLogForm.reset();
                                const container = document.getElementById('logItemsContainer');
                                const rows = container.querySelectorAll('.log-item-row');
                                for (let i = 1; i < rows.length; i++) {
                                    rows[i].remove();
                                }

                                // Refresh View - INSTANT UPDATE without page reload
                                alert(data.message);
                                refreshInventoryTable(processedIds);
                                refreshActivityDrawer();
                            } else {
                                alert('Error: ' + (data.message || 'Failed to save logs.'));
                            }
                        })
                        .catch(error => {
                            console.error('Submission error:', error);
                            alert('An unexpected error occurred. Please check console.');
                        })
                        .finally(() => {
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = originalText;
                        });
                };
            }
        })();
    </script>
</div>