<?php
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include_once '../profile_modal.php';


// Add Category Logic
if (isset($_POST['add_category'])) {
    $category_name = strtoupper(trim($_POST['category_name']));

    $check_stmt = $mysqli->prepare("SELECT COUNT(*) FROM equipment_category WHERE category_name = ?");
    $check_stmt->bind_param("s", $category_name);
    $check_stmt->execute();
    $check_stmt->bind_result($count);
    $check_stmt->fetch();
    $check_stmt->close();

    if ($count > 0) {
        $_SESSION['equipment_message'] = "<div class='alert alert-warning m-3 mt-0'>Category already exists!</div>";
    } else {
        $stmt = $mysqli->prepare("INSERT INTO equipment_category (category_name) VALUES (?)");
        $stmt->bind_param("s", $category_name);
        if ($stmt->execute()) {
            $_SESSION['equipment_message'] = "<div class='alert alert-success m-3 mt-0'>Category added successfully!</div>";

            // ✅ LOG ACTIVITY
            $performed_by = $_SESSION['username'] ?? 'System';
            $log_query = "INSERT INTO activity_log (activity_type, property_no, location, status, performed_by, remarks, date_time) VALUES ('CATEGORY_ADDED', 'N/A', 'Global', 'Done', ?, ?, NOW())";
            $l_stmt = $mysqli->prepare($log_query);
            $remarks = "New category created: " . $category_name;
            $l_stmt->bind_param("ss", $performed_by, $remarks);
            $l_stmt->execute();
            $l_stmt->close();
        } else {
            $_SESSION['equipment_message'] = "<div class='alert alert-danger m-3 mt-0'>Error adding category.</div>";
        }
        $stmt->close();
    }

    // ✅ Dynamic Redirect
    $dashboard = ($_SESSION['role'] === 'staff') ? '../staff/staff_dashboard.php' : 'admin_dashboard.php';
    header("Location: $dashboard?view=equipment");
    exit();
}

// Add Equipment Logic
if (isset($_POST['add_equipment'])) {
    $category_id = intval($_POST['category_id']);
    $property_no = trim($_POST['property_no']);
    $location = trim($_POST['location']);
    $type = trim($_POST['type']);
    $status = trim($_POST['status']);

    // New fields
    $description = trim($_POST['description']);
    $designation = trim($_POST['designation']);
    $acquisition_date = trim($_POST['acquisition_date']);
    $acquisition_cost = trim($_POST['acquisition_cost']);
    $last_repair_date = trim($_POST['last_repair_date']);

    // Validate required fields
    if (empty($category_id) || empty($property_no) || empty($location) || empty($type) || empty($status)) {
        $_SESSION['equipment_message'] = "<div class='alert alert-warning m-3 mt-0'>Please fill out all required fields.</div>";
    } else {
        $checkStmt = $mysqli->prepare("SELECT id FROM equipment WHERE property_no = ?");
        $checkStmt->bind_param("s", $property_no);
        $checkStmt->execute();
        $checkStmt->store_result();

        if ($checkStmt->num_rows > 0) {
            $_SESSION['equipment_message'] = "<div class='alert alert-warning m-3 mt-0'>Duplicate Property No. This equipment already exists.</div>";
        } else {
            $stmt = $mysqli->prepare("
                INSERT INTO equipment 
                (category_id, property_no, location, type, status, description, designation, acquisition_date, acquisition_cost, last_repair_date) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("isssssssss", $category_id, $property_no, $location, $type, $status, $description, $designation, $acquisition_date, $acquisition_cost, $last_repair_date);

            if ($stmt->execute()) {
                $_SESSION['equipment_message'] = "<div class='alert alert-success m-3 mt-0'>Equipment added successfully!</div>";

                // ✅ LOG ACTIVITY
                $performed_by = $_SESSION['username'] ?? 'System';
                $log_query = "INSERT INTO activity_log (activity_type, property_no, location, status, performed_by, remarks, date_time) VALUES ('EQUIPMENT_ADDED', ?, ?, ?, ?, ?, NOW())";
                $l_stmt = $mysqli->prepare($log_query);
                $remarks = "New equipment registered: " . $property_no . " (" . $type . ")";
                $l_stmt->bind_param("sssss", $property_no, $location, $status, $performed_by, $remarks);
                $l_stmt->execute();
                $l_stmt->close();
            } else {
                $_SESSION['equipment_message'] = "<div class='alert alert-danger m-3 mt-0'>Error adding equipment. Please try again.</div>";
            }
            $stmt->close();
        }
        $checkStmt->close();
    }

    // ✅ Dynamic Redirect
    $dashboard = ($_SESSION['role'] === 'staff') ? '../staff/staff_dashboard.php' : 'admin_dashboard.php';
    header("Location: $dashboard?view=equipment");
    exit();
}

// Edit Equipment
if (isset($_POST['edit_equipment'])) {
    $id = intval($_POST['equipment_id']);
    $category_id = intval($_POST['category_id']);
    $property_no = trim($_POST['property_no']);
    $location = trim($_POST['location']);
    $type = trim($_POST['type']);
    $status = trim($_POST['status']);
    $description = trim($_POST['description']);
    $designation = trim($_POST['designation']);
    $acquisition_date = trim($_POST['acquisition_date']);
    $acquisition_cost = trim($_POST['acquisition_cost']);
    $last_repair_date = trim($_POST['last_repair_date']);

    $stmt = $mysqli->prepare("UPDATE equipment SET category_id=?, property_no=?, location=?, type=?, status=?, description=?, designation=?, acquisition_date=?, acquisition_cost=?, last_repair_date=? WHERE id=?");
    $stmt->bind_param("isssssssssi", $category_id, $property_no, $location, $type, $status, $description, $designation, $acquisition_date, $acquisition_cost, $last_repair_date, $id);

    if ($stmt->execute()) {
        $_SESSION['equipment_message'] = "<div class='alert alert-success m-3 mt-0'>Equipment updated successfully!</div>";

        // ✅ LOG ACTIVITY
        $performed_by = $_SESSION['username'] ?? 'System';
        $log_query = "INSERT INTO activity_log (activity_type, property_no, location, status, performed_by, remarks, date_time) VALUES ('EQUIPMENT_UPDATED', ?, ?, ?, ?, ?, NOW())";
        $l_stmt = $mysqli->prepare($log_query);
        $remarks = "Updated details for property: " . $property_no . ". New status: " . $status;
        $l_stmt->bind_param("sssss", $property_no, $location, $status, $performed_by, $remarks);
        $l_stmt->execute();
        $l_stmt->close();
    } else {
        $_SESSION['equipment_message'] = "<div class='alert alert-danger m-3 mt-0'>Error updating equipment.</div>";
    }
    $stmt->close();

    // ✅ Dynamic Redirect
    $dashboard = ($_SESSION['role'] === 'staff') ? '../staff/staff_dashboard.php' : 'admin_dashboard.php';
    header("Location: $dashboard?view=equipment");
    exit();
}

// Fetch categories for dropdowns
$categories_result = $mysqli->query("SELECT id, category_name FROM equipment_category ORDER BY category_name ASC");

// Get success/error message
$equipment_message = '';
if (isset($_SESSION['equipment_message'])) {
    $equipment_message = $_SESSION['equipment_message'];
    unset($_SESSION['equipment_message']);
}
?>

<!-- Tailwind CSS -->
<script src="https://cdn.tailwindcss.com"></script>
<!-- Font Awesome -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<!-- Bootstrap Icons -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
    /* OPTION 3: Animated Gradient with Grid Texture */



    .glass-card {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255, 255, 255, 0.2);
    }

    .hover-row:hover {
        background-color: rgba(59, 130, 246, 0.05);
    }

    .status-badge {
        padding: 2px 10px;
        border-radius: 12px;
        font-size: 0.75rem;
        font-weight: 500;
    }

    .search-input:focus {
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }

    .action-btn {
        transition: all 0.2s ease;
    }

    .action-btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
    }

    .table-scroll-container {
        max-height: 500px;
        scrollbar-width: thin;
    }

    .table-scroll-container::-webkit-scrollbar {
        width: 8px;
    }

    .table-scroll-container::-webkit-scrollbar-track {
        background: #f9fafb;
        border-radius: 4px;
    }

    .table-scroll-container::-webkit-scrollbar-thumb {
        background: #d1d5db;
        border-radius: 4px;
    }

    .table-scroll-container::-webkit-scrollbar-thumb:hover {
        background: #9ca3af;
    }

    .sticky-header {
        position: sticky;
        top: 0;
        z-index: 10;
        background-color: #f9fafb;
    }
</style>

<!-- <div class="container mx-auto px-4 py-8"> -->
<!-- Success/Error Messages -->
<?php if (!empty($equipment_message)): ?>
    <?php echo $equipment_message; ?>
<?php endif; ?>

<!-- Header Section -->
<div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 gap-4">
    <div>
        <h2 class="text-black md:text-2xl font-bold">MANAGE AND VIEW ALL EQUIPMENTS</h2>
    </div>

    <div class="flex items-center space-x-4">
        <div class="hidden md:flex items-center space-x-6">
            <div class="text-center">
                <p class="text-lg text-black-300 uppercase tracking-wider">Total Equipment</p>
                <?php
                $total_query = "SELECT COUNT(*) as total FROM equipment";
                $total_result = $mysqli->query($total_query);
                $total_count = $total_result->fetch_assoc()['total'] ?? 0;
                ?>
                <p class="text-2xl font-bold text-black"><?php echo $total_count; ?></p>
            </div>
            <div class="h-8 w-px bg-black/30"></div>
            <div class="text-center">
                <p class="text-lg text-black-300 uppercase tracking-wider">Categories</p>
                <?php
                $cat_query = "SELECT COUNT(*) as total FROM equipment_category";
                $cat_result = $mysqli->query($cat_query);
                $cat_count = $cat_result->fetch_assoc()['total'] ?? 0;
                ?>
                <p class="text-2xl font-bold text-black"><?php echo $cat_count; ?></p>
            </div>
        </div>
    </div>
</div>

<!-- Search and Filter Bar -->
<div class="glass-card rounded-xl shadow-sm mb-6 p-4">
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
        <!-- Search Bar -->
        <div class="flex-1 w-full">
            <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                    <i class="fas fa-search text-gray-400"></i>
                </div>
                <input type="text" id="searchInput"
                    class="search-input w-full pl-10 pr-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-200"
                    placeholder="Search by Property No or Equipment...">
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="flex items-center space-x-3">
            <!-- View Logs Button -->
            <button type="button" onclick="toggleEquipmentDrawer(true)"
                class="action-btn bg-slate-800 hover:bg-slate-700 text-white px-5 py-3 rounded-lg font-medium flex items-center transition duration-200">
                <i class="fas fa-history mr-2"></i>
                View Logs
            </button>

            <!-- Add Category Button -->
            <button type="button"
                class="action-btn bg-blue-600 hover:bg-green-500 text-white px-5 py-3 rounded-lg font-medium flex items-center transition duration-200"
                data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                <i class="fas fa-plus-circle mr-2"></i>
                Add Category
            </button>

            <!-- Add Equipment Button -->
            <button type="button"
                class="action-btn bg-blue-600 hover:bg-green-500 text-white px-5 py-3 rounded-lg font-medium flex items-center transition duration-200"
                data-bs-toggle="modal" data-bs-target="#addEquipmentModal">
                <i class="fas fa-plus-circle mr-2"></i>
                Add Equipment
            </button>
        </div>
    </div>

    <!-- Filter Row -->
    <div class="mt-4 flex flex-wrap gap-3">
        <!-- Location Filter -->
        <select
            class="filter-select px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-200"
            id="locationFilter">
            <option value="">All Locations</option>
            <option value="Mamburao">MAMBURAO</option>
            <option value="Sablayan">SABLAYAN</option>
            <option value="Lubang">LUBANG</option>
            <option value="San Jose">SAN JOSE</option>
        </select>

        <!-- Status Filter -->
        <select
            class="filter-select px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-200"
            id="statusFilter">
            <option value="">All Status</option>
            <option value="Operational">OPERATIONAL</option>
            <option value="Under repair">UNDER REPAIR</option>
            <option value="Unserviceable">UNSERVICEABLE</option>
        </select>

        <!-- Type Filter -->
        <select
            class="filter-select px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-200"
            id="typeFilter">
            <option value="">All Types</option>
            <option value="Heavy Equipment">HEAVY EQUIPMENT</option>
            <option value="Light Equipment">LIGHT EQUIPMENT</option>
        </select>

        <!-- Clear Filters Button -->
        <button id="clearFilters"
            class="action-btn border border-gray-300 hover:bg-gray-50 text-gray-700 px-4 py-2.5 rounded-lg font-medium flex items-center transition duration-200">
            <i class="fas fa-times-circle mr-2"></i>
            Clear Filters
        </button>

        <!-- Results Count -->
        <div class="ml-auto flex items-center">
            <span class="text-sm text-gray-600" id="resultCount">
                <?php
                $count_query = "SELECT COUNT(*) as count FROM equipment";
                $count_result = $mysqli->query($count_query);
                $count = $count_result->fetch_assoc()['count'] ?? 0;
                echo "Showing " . $count . " equipment";
                ?>
            </span>
        </div>
    </div>
</div>

<!-- Main Table -->
<div class="bg-white rounded-xl shadow-sm overflow-hidden">

    <!-- Equipment Table -->
    <div class="table-scroll-container overflow-auto">
        <table class="min-w-full divide-y divide-gray-200" id="equipmentTable">
            <thead class="sticky-header bg-gray-50">
                <tr>
                    <th scope="col"
                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Equipment
                    </th>
                    <th scope="col"
                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Property No
                    </th>
                    <th scope="col"
                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Allocation
                    </th>
                    <th scope="col"
                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Type
                    </th>
                    <th scope="col"
                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Status
                    </th>
                    <th scope="col"
                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Actions
                    </th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200" id="equipmentTableBody">
                <?php
                // Reset categories result pointer
                $categories_result->data_seek(0);

                $query = "
                        SELECT 
                            e.id, 
                            e.category_id, 
                            c.category_name, 
                            e.property_no, 
                            e.location, 
                            e.type, 
                            e.status,
                            e.description,
                            e.designation,
                            e.acquisition_date,
                            e.acquisition_cost,
                            e.last_repair_date
                        FROM equipment e
                        JOIN equipment_category c ON e.category_id = c.id 
                        ORDER BY e.id DESC
                    ";

                $result = $mysqli->query($query);
                $totalRows = $result ? $result->num_rows : 0;

                if ($result && $totalRows > 0) {
                    while ($row = $result->fetch_assoc()) {
                        // Assign badge color for status
                        $statusClass = match (strtolower($row['status'])) {
                            'operational' => 'bg-green-100 text-green-800',
                            'under repair' => 'bg-yellow-100 text-yellow-800',
                            'unserviceable' => 'bg-red-100 text-red-800',
                            default => 'bg-gray-100 text-gray-800',
                        };

                        // Check if QR code button should be shown
                        $showQR = (($_SESSION['role'] ?? '') !== 'staff');
                        ?>
                        <tr class="hover-row transition duration-150">
                            <!-- Equipment -->
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center">
                                    <div
                                        class="flex-shrink-0 h-10 w-10 rounded-lg bg-blue-50 flex items-center justify-center mr-3">
                                        <i class="fas fa-tools text-blue-600"></i>
                                    </div>
                                    <div>
                                        <div class="text-sm font-medium text-gray-900">
                                            <?= htmlspecialchars($row['category_name']); ?>
                                        </div>
                                        <?php if (!empty($row['description'])): ?>
                                            <div class="text-xs text-gray-500 truncate max-w-[200px]"
                                                title="<?= htmlspecialchars($row['description']); ?>">
                                                <?= htmlspecialchars(substr($row['description'], 0, 50)) . (strlen($row['description']) > 50 ? '...' : ''); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>

                            <!-- Property No -->
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-900 font-mono">
                                    <?= htmlspecialchars($row['property_no']); ?>
                                </div>
                            </td>

                            <!-- Location -->
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div
                                    class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-blue-50 text-blue-700">
                                    <i class="fas fa-map-marker-alt mr-1.5"></i>
                                    <?= htmlspecialchars($row['location']); ?>
                                </div>
                            </td>

                            <!-- Type -->
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-900">
                                    <?= htmlspecialchars($row['type']); ?>
                                </div>
                            </td>

                            <!-- Status -->
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span
                                    class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium <?= $statusClass; ?>">
                                    <?= htmlspecialchars($row['status']); ?>
                                </span>
                            </td>

                            <!-- Actions -->
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                <div class="flex items-center space-x-2">
                                    <!-- Edit Button -->
                                    <button
                                        class="edit-btn inline-flex items-center px-3 py-2 border border-blue-300 text-blue-800 bg-blue-50 rounded-lg hover:bg-blue-100 transition duration-200"
                                        title="Edit Equipment" data-id="<?= $row['id']; ?>"
                                        data-category-id="<?= $row['category_id']; ?>"
                                        data-property-no="<?= htmlspecialchars($row['property_no']); ?>"
                                        data-location="<?= htmlspecialchars($row['location']); ?>"
                                        data-type="<?= htmlspecialchars($row['type']); ?>"
                                        data-status="<?= htmlspecialchars($row['status']); ?>"
                                        data-description="<?= htmlspecialchars($row['description'] ?? ''); ?>"
                                        data-designation="<?= htmlspecialchars($row['designation'] ?? ''); ?>"
                                        data-acquisition-date="<?= htmlspecialchars($row['acquisition_date'] ?? ''); ?>"
                                        data-acquisition-cost="<?= htmlspecialchars($row['acquisition_cost'] ?? ''); ?>"
                                        data-last-repair-date="<?= htmlspecialchars($row['last_repair_date'] ?? ''); ?>">
                                        <i class="fas fa-edit mr-1.5"></i>
                                        Edit
                                    </button>

                                    <!-- QR Code Button (if allowed) -->
                                    <?php if ($showQR): ?>
                                        <button onclick="window.open('generate_qr.php?id=<?= $row['id']; ?>', '_blank')"
                                            class="inline-flex items-center px-3 py-2 border border-green-300 text-green-700 bg-green-50 rounded-lg hover:bg-green-100 transition duration-200"
                                            title="View QR Code">
                                            <i class="fas fa-qrcode mr-1.5"></i>
                                            QR Code
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php
                    }
                } else {
                    ?>
                    <tr>
                        <td colspan="6" class="px-6 py-12 text-center">
                            <div class="text-gray-500">
                                <i class="fas fa-inbox text-4xl mb-4 opacity-30"></i>
                                <h3 class="text-lg font-medium text-gray-900 mb-2">No equipment found</h3>
                                <p class="text-gray-600 mb-4">
                                    There are no equipment records in the system yet.
                                </p>
                                <button
                                    class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-medium inline-flex items-center"
                                    data-bs-toggle="modal" data-bs-target="#addEquipmentModal">
                                    <i class="fas fa-plus-circle mr-2"></i>
                                    Add First Equipment
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php
                }
                ?>
            </tbody>
        </table>
    </div>
</div>
</div>

<!-- ADD CATEGORY MODAL -->
<div class="modal fade" id="addCategoryModal" tabindex="-1" aria-labelledby="addCategoryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <form method="post" action="">
            <div class="modal-content border-0 shadow-2xl rounded-xl overflow-hidden">

                <div class="modal-header bg-green-600 text-white px-6 py-3 border-0 flex justify-between items-center">
                    <h5 class="text-lg font-bold flex items-center gap-2">
                        <i class="fas fa-plus-circle"></i> Add Category
                    </h5>
                    <button type="button" class="text-white hover:text-green-100 transition-colors focus:outline-none"
                        data-bs-dismiss="modal">
                        <i class="fas fa-times text-lg"></i>
                    </button>
                </div>

                <div class="modal-body p-6 bg-white">
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-2">
                        Category Name
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-tag text-gray-400"></i>
                        </div>
                        <input type="text" name="category_name"
                            class="w-full pl-10 pr-4 py-2.5 bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500 block transition-all"
                            placeholder="e.g. Heavy Equipment" required>
                    </div>
                </div>

                <div class="bg-gray-50 px-6 py-4 flex justify-end gap-3 border-t border-gray-100">
                    <button type="button"
                        class="px-4 py-2 bg-white text-gray-700 text-sm font-medium rounded-lg border border-gray-300 hover:bg-gray-50 focus:ring-2 focus:ring-offset-2 focus:ring-gray-200 transition-all shadow-sm"
                        data-bs-dismiss="modal">
                        Cancel
                    </button>

                    <button type="submit" name="add_category"
                        class="px-4 py-2 bg-green-600 text-white text-sm font-medium rounded-lg hover:bg-green-700 focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition-all shadow-md flex items-center gap-2">
                        <i class="fas fa-check"></i> Save
                    </button>
                </div>

            </div>
        </form>
    </div>
</div>

<!-- ADD EQUIPMENT MODAL -->
<div class="modal fade" id="addEquipmentModal" tabindex="-1" aria-labelledby="addEquipmentModalLabel"
    aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <form method="post" action="" class="needs-validation" novalidate>
            <div class="modal-content border-0 shadow-xl">
                <!-- Modal Header -->
                <div
                    class="modal-header bg-gradient-to-r from-blue-600 to-blue-800 text-white p-6 border-b border-blue-700">
                    <div class="flex items-center">
                        <div class="bg-white/20 rounded-lg p-3 mr-4">
                            <i class="bi bi-tools text-2xl"></i>
                        </div>
                        <div>
                            <h5 class="modal-title text-xl font-bold">
                                ADD NEW EQUIPMENT
                            </h5>
                            <p class="text-blue-100 text-sm mt-1">Fill in all required fields marked with *</p>
                        </div>
                    </div>
                    <button type="button"
                        class="btn-close btn-close-white opacity-100 hover:opacity-75 transition-opacity"
                        data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <!-- Modal Body -->
                <div class="modal-body p-0">
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-0">
                        <!-- Left Column - Basic Information -->
                        <div class="p-6 border-r border-gray-200">
                            <h6 class="font-bold text-blue-600 mb-4 pb-2 border-b border-blue-100">
                                <i class="bi bi-info-circle me-2"></i>BASIC INFORMATION
                            </h6>

                            <!-- Category -->
                            <div class="mb-5">
                                <label class="block text-sm font-semibold text-gray-700 mb-2">
                                    Equipment Category <span class="text-red-500">*</span>
                                </label>
                                <select name="category_id"
                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-200"
                                    required>
                                    <option value="" disabled selected>Select Category</option>
                                    <?php
                                    $categories_result->data_seek(0);
                                    while ($row = $categories_result->fetch_assoc()) {
                                        echo "<option value='{$row['id']}'>{$row['category_name']}</option>";
                                    }
                                    ?>
                                </select>
                            </div>

                            <!-- Property No -->
                            <div class="mb-5">
                                <label class="block text-sm font-semibold text-gray-700 mb-2">
                                    Property No <span class="text-red-500">*</span>
                                </label>
                                <input type="text" name="property_no"
                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-200"
                                    placeholder="Enter Property Number" required>
                            </div>

                            <!-- Type & Status in one row -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-5">
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-2">
                                        Type <span class="text-red-500">*</span>
                                    </label>
                                    <select name="type"
                                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-200"
                                        required>
                                        <option value="" disabled selected>Select Type</option>
                                        <option value="Heavy Equipment">Heavy Equipment</option>
                                        <option value="Light Equipment">Light Equipment</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-2">
                                        Status <span class="text-red-500">*</span>
                                    </label>
                                    <select name="status"
                                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-200"
                                        required>
                                        <option value="" disabled selected>Select Status</option>
                                        <option value="Operational">Operational</option>
                                        <option value="Under repair">Under repair</option>
                                        <option value="Unserviceable">Unserviceable</option>
                                    </select>
                                </div>
                            </div>

                            <!-- Acquisition Date & Cost -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-5">
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-2">Acquisition
                                        Date</label>
                                    <input type="date" name="acquisition_date"
                                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-200">
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-2">Acquisition
                                        Cost</label>
                                    <div class="relative">
                                        <div
                                            class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                            <span class="text-gray-500">₱</span>
                                        </div>
                                        <input type="number" name="acquisition_cost" step="0.01"
                                            class="w-full pl-10 pr-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-200"
                                            placeholder="0.00">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Right Column - Equipment Details -->
                        <div class="p-6">
                            <h6 class="font-bold text-blue-600 mb-4 pb-2 border-b border-blue-100">
                                <i class="bi bi-gear me-2"></i>EQUIPMENT DETAILS
                            </h6>

                            <!-- Location -->
                            <div class="mb-5">
                                <label class="block text-sm font-semibold text-gray-700 mb-2">
                                    Location <span class="text-red-500">*</span>
                                </label>
                                <select name="location"
                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-200"
                                    required>
                                    <option value="" disabled selected>Select Location</option>
                                    <option value="Mamburao">Mamburao</option>
                                    <option value="Sablayan">Sablayan</option>
                                    <option value="San Jose">San Jose</option>
                                    <option value="Lubang">Lubang</option>
                                </select>
                            </div>

                            <!-- Description -->
                            <div class="mb-5">
                                <label class="block text-sm font-semibold text-gray-700 mb-2">Description</label>
                                <textarea name="description" rows="4"
                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-200"
                                    placeholder="Enter detailed equipment description..."></textarea>
                            </div>

                            <!-- Designation -->
                            <div class="mb-5">
                                <label class="block text-sm font-semibold text-gray-700 mb-2">Designation</label>
                                <input type="text" name="designation"
                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-200"
                                    placeholder="e.g., Provincial Social Welfare and Development">
                            </div>

                            <!-- Last Repair Date -->
                            <div class="mb-5">
                                <label class="block text-sm font-semibold text-gray-700 mb-2">Last Repair Date</label>
                                <input type="date" name="last_repair_date"
                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-200">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Modal Footer -->
                <div class="modal-footer bg-gray-50 px-6 py-4 border-t border-gray-200">
                    <div class="flex justify-end space-x-3 w-full">
                        <button type="button"
                            class="px-6 py-3 border border-gray-300 text-gray-700 bg-white rounded-lg hover:bg-gray-50 transition duration-200 font-medium"
                            data-bs-dismiss="modal">
                            <i class="bi bi-x-circle me-2"></i>Cancel
                        </button>
                        <button type="submit" name="add_equipment"
                            class="px-6 py-3 bg-blue-600 hover:bg-green-700 text-white rounded-lg transition duration-200 font-medium">
                            <i class="bi bi-plus-circle me-2"></i>Add Equipment
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- EDIT EQUIPMENT MODAL -->
<div class="modal fade" id="editEquipmentModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <form method="post">
            <div class="modal-content border-0 shadow-xl">
                <!-- Modal Header -->
                <div
                    class="modal-header bg-gradient-to-r from-yellow-600 to-yellow-800 text-white p-6 border-b border-yellow-700">
                    <div class="flex items-center">
                        <div class="bg-white/20 rounded-lg p-3 mr-4">
                            <i class="bi bi-pencil-square text-2xl"></i>
                        </div>
                        <div>
                            <h5 class="modal-title text-xl font-bold">
                                EDIT EQUIPMENT
                            </h5>
                            <p class="text-yellow-100 text-sm mt-1">Update equipment information</p>
                        </div>
                    </div>
                    <button type="button"
                        class="btn-close btn-close-white opacity-100 hover:opacity-75 transition-opacity"
                        data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <!-- Modal Body -->
                <div class="modal-body p-0">
                    <input type="hidden" name="equipment_id" id="edit_equipment_id">
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-0">
                        <!-- Left Column - Basic Information -->
                        <div class="p-6 border-r border-gray-200">
                            <h6 class="font-bold text-yellow-600 mb-4 pb-2 border-b border-yellow-100">
                                <i class="bi bi-info-circle me-2"></i>BASIC INFORMATION
                            </h6>

                            <!-- Category -->
                            <div class="mb-5">
                                <label class="block text-sm font-semibold text-gray-700 mb-2">
                                    Equipment Category <span class="text-red-500">*</span>
                                </label>
                                <select name="category_id" id="edit_category_id"
                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-500 focus:border-yellow-500 transition duration-200"
                                    required>
                                    <option value="" disabled>Select Category</option>
                                    <?php
                                    $categories_result->data_seek(0);
                                    while ($row = $categories_result->fetch_assoc()) {
                                        echo "<option value='{$row['id']}'>{$row['category_name']}</option>";
                                    }
                                    ?>
                                </select>
                            </div>

                            <!-- Property No -->
                            <div class="mb-5">
                                <label class="block text-sm font-semibold text-gray-700 mb-2">
                                    Property No <span class="text-red-500">*</span>
                                </label>
                                <input type="text" name="property_no" id="edit_property_no"
                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-500 focus:border-yellow-500 transition duration-200"
                                    required>
                            </div>

                            <!-- Type & Status in one row -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-5">
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-2">
                                        Type <span class="text-red-500">*</span>
                                    </label>
                                    <select name="type" id="edit_type"
                                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-500 focus:border-yellow-500 transition duration-200"
                                        required>
                                        <option value="Heavy Equipment">Heavy Equipment</option>
                                        <option value="Light Equipment">Light Equipment</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-2">
                                        Status <span class="text-red-500">*</span>
                                    </label>
                                    <select name="status" id="edit_status"
                                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-500 focus:border-yellow-500 transition duration-200"
                                        required>
                                        <option value="Operational">Operational</option>
                                        <option value="Under repair">Under repair</option>
                                        <option value="Unserviceable">Unserviceable</option>
                                    </select>
                                </div>
                            </div>

                            <!-- Acquisition Date & Cost -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-5">
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-2">Acquisition
                                        Date</label>
                                    <input type="date" name="acquisition_date" id="edit_acquisition_date"
                                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-500 focus:border-yellow-500 transition duration-200">
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-2">Acquisition
                                        Cost</label>
                                    <div class="relative">
                                        <div
                                            class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                            <span class="text-gray-500">₱</span>
                                        </div>
                                        <input type="number" name="acquisition_cost" id="edit_acquisition_cost"
                                            step="0.01"
                                            class="w-full pl-10 pr-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-500 focus:border-yellow-500 transition duration-200"
                                            placeholder="0.00">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Right Column - Equipment Details -->
                        <div class="p-6">
                            <h6 class="font-bold text-yellow-600 mb-4 pb-2 border-b border-yellow-100">
                                <i class="bi bi-gear me-2"></i>EQUIPMENT DETAILS
                            </h6>

                            <!-- Location -->
                            <div class="mb-5">
                                <label class="block text-sm font-semibold text-gray-700 mb-2">
                                    Location <span class="text-red-500">*</span>
                                </label>
                                <select name="location" id="edit_location"
                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-500 focus:border-yellow-500 transition duration-200"
                                    required>
                                    <option value="Mamburao">Mamburao</option>
                                    <option value="Sablayan">Sablayan</option>
                                    <option value="San Jose">San Jose</option>
                                    <option value="Lubang">Lubang</option>
                                </select>
                            </div>

                            <!-- Description -->
                            <div class="mb-5">
                                <label class="block text-sm font-semibold text-gray-700 mb-2">Description</label>
                                <textarea name="description" id="edit_description" rows="4"
                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-500 focus:border-yellow-500 transition duration-200"
                                    placeholder="Enter detailed equipment description..."></textarea>
                            </div>

                            <!-- Designation -->
                            <div class="mb-5">
                                <label class="block text-sm font-semibold text-gray-700 mb-2">Designation</label>
                                <input type="text" name="designation" id="edit_designation"
                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-500 focus:border-yellow-500 transition duration-200"
                                    placeholder="Enter designation...">
                            </div>

                            <!-- Last Repair Date -->
                            <div class="mb-5">
                                <label class="block text-sm font-semibold text-gray-700 mb-2">Last Repair Date</label>
                                <input type="date" name="last_repair_date" id="edit_last_repair_date"
                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-500 focus:border-yellow-500 transition duration-200">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Modal Footer -->
                <div class="modal-footer bg-gray-50 px-6 py-4 border-t border-gray-200">
                    <div class="flex justify-end space-x-3 w-full">
                        <button type="button"
                            class="px-6 py-3 border border-gray-300 text-gray-700 bg-white rounded-lg hover:bg-gray-50 transition duration-200 font-medium"
                            data-bs-dismiss="modal">
                            <i class="bi bi-x-circle me-2"></i>Cancel
                        </button>
                        <button type="submit" name="edit_equipment"
                            class="px-6 py-3 bg-yellow-600 hover:bg-yellow-700 text-white rounded-lg transition duration-200 font-medium">
                            <i class="bi bi-check-circle me-2"></i>Update Equipment
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
    <!-- </div> -->

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Get all equipment rows
            const equipmentRows = document.querySelectorAll('#equipmentTable tbody tr');
            const resultCount = document.getElementById('resultCount');

            // Update result count initially
            updateResultCount();

            // Edit button functionality
            document.querySelectorAll('.edit-btn').forEach(btn => {
                btn.addEventListener('click', function (e) {
                    e.preventDefault();

                    // Set all form values
                    document.getElementById('edit_equipment_id').value = this.dataset.id;
                    document.getElementById('edit_category_id').value = this.dataset.categoryId;
                    document.getElementById('edit_property_no').value = this.dataset.propertyNo;
                    document.getElementById('edit_location').value = this.dataset.location;
                    document.getElementById('edit_type').value = this.dataset.type;
                    document.getElementById('edit_status').value = this.dataset.status;
                    document.getElementById('edit_description').value = this.dataset.description || '';
                    document.getElementById('edit_designation').value = this.dataset.designation || '';
                    document.getElementById('edit_acquisition_date').value = this.dataset
                        .acquisitionDate || '';
                    document.getElementById('edit_acquisition_cost').value = this.dataset
                        .acquisitionCost || '';
                    document.getElementById('edit_last_repair_date').value = this.dataset
                        .lastRepairDate || '';

                    // Show modal
                    const editModal = new bootstrap.Modal(document.getElementById(
                        'editEquipmentModal'));
                    editModal.show();
                });
            });

            // Search and Filter functionality
            const searchInput = document.getElementById('searchInput');
            const locationFilter = document.getElementById('locationFilter');
            const statusFilter = document.getElementById('statusFilter');
            const typeFilter = document.getElementById('typeFilter');
            const clearFiltersBtn = document.getElementById('clearFilters');

            function applyAllFilters() {
                const searchTerm = searchInput.value.toLowerCase().trim();
                const locationValue = locationFilter.value;
                const statusValue = statusFilter.value;
                const typeValue = typeFilter.value;

                let visibleCount = 0;

                equipmentRows.forEach(row => {
                    if (row.cells.length < 6) return; // Skip empty rows

                    const equipmentName = row.cells[0].querySelector('.text-sm.font-medium').textContent
                        .toLowerCase();
                    const propertyNo = row.cells[1].textContent.toLowerCase();
                    const rowLocation = row.cells[2].textContent;
                    const rowType = row.cells[3].textContent;
                    const rowStatus = row.cells[4].textContent;

                    // Check search match
                    const searchMatch = !searchTerm ||
                        equipmentName.includes(searchTerm) ||
                        propertyNo.includes(searchTerm);

                    // Check filter matches
                    const locationMatch = !locationValue || rowLocation.includes(locationValue);
                    const statusMatch = !statusValue || rowStatus.includes(statusValue);
                    const typeMatch = !typeValue || rowType.includes(typeValue);

                    // Show/hide row based on all conditions
                    if (searchMatch && locationMatch && statusMatch && typeMatch) {
                        row.style.display = '';
                        visibleCount++;
                    } else {
                        row.style.display = 'none';
                    }
                });

                updateResultCount(visibleCount);

                // Show empty state if no results
                const tbody = document.getElementById('equipmentTableBody');
                const existingEmptyRow = tbody.querySelector('.no-results');

                if (visibleCount === 0) {
                    if (!existingEmptyRow) {
                        const emptyRow = document.createElement('tr');
                        emptyRow.className = 'no-results';
                        emptyRow.innerHTML = `
                    <td colspan="6" class="px-6 py-12 text-center">
                        <div class="text-gray-500">
                            <i class="fas fa-search text-4xl mb-4 opacity-30"></i>
                            <h3 class="text-lg font-medium text-gray-900 mb-2">No equipment found</h3>
                            <p class="text-gray-600 mb-4">
                                No equipment matches your search criteria.
                            </p>
                        </div>
                    </td>
                `;
                        tbody.appendChild(emptyRow);
                    }
                } else {
                    if (existingEmptyRow) {
                        existingEmptyRow.remove();
                    }
                }
            }

            function updateResultCount(count = null) {
                if (count !== null) {
                    resultCount.textContent = `Showing ${count} equipment`;
                } else {
                    const visibleRows = Array.from(equipmentRows).filter(row =>
                        row.style.display !== 'none' && row.cells.length >= 6
                    ).length;
                    resultCount.textContent = `Showing ${visibleRows} equipment`;
                }
            }

            // Add event listeners
            searchInput.addEventListener('input', applyAllFilters);
            locationFilter.addEventListener('change', applyAllFilters);
            statusFilter.addEventListener('change', applyAllFilters);
            typeFilter.addEventListener('change', applyAllFilters);

            // Clear all filters button
            if (clearFiltersBtn) {
                clearFiltersBtn.addEventListener('click', function () {
                    searchInput.value = '';
                    locationFilter.value = '';
                    statusFilter.value = '';
                    typeFilter.value = '';
                    applyAllFilters();
                });
            }

            // Initialize with current filter state
            applyAllFilters();
        });
    </script>

    <?php
    // Close database connection
    $mysqli->close();
    ?>