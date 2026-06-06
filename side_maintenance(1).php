<?php
require_once __DIR__ . '/config.php';

include_once 'profile_modal.php';

// Get user location from session - check both session keys for compatibility
$userLocation = $_SESSION['location'] ?? $_SESSION['user_location'] ?? 'Mamburao';

// ✅ Fetch completed maintenance requests for the current location
$completed_sql = "
    SELECT d.*, e.description as equipment_name 
    FROM documents d
    LEFT JOIN equipment e ON d.property_no = e.property_no
    WHERE d.status = 'Complete' 
    AND (e.location = ? OR d.location = ?)
    ORDER BY d.date_completed DESC
";

$completed_stmt = $mysqli->prepare($completed_sql);
if ($completed_stmt) {
    $completed_stmt->bind_param("ss", $userLocation, $userLocation);
    $completed_stmt->execute();
    $completed_result = $completed_stmt->get_result();
} else {
    // Handle error
    echo "Error preparing query: " . $mysqli->error;
    $completed_result = false;
}




// Handle Add History form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_history'])) {
    $pre_repair_no = trim($_POST['pre_repair_no'] ?? '');
    $recommendation = trim($_POST['recommendation'] ?? '');
    $status = trim($_POST['status'] ?? '');
    $date = $_POST['date'] ?? '';

    if ($pre_repair_no && $recommendation && $status && $date) {
        // JOIN to get equipment name - WITH LOCATION FILTER
        $query = "
            INSERT INTO maintenance (equipment, property_no, pre_repair_no, recommendation, date, status)
            SELECT c.category_name, d.property_no, d.pre_repair_no, ?, ?, ?
            FROM documents d
            JOIN equipment_category c ON d.category_id = c.id
            WHERE d.pre_repair_no = ?
            AND d.location = ?
            LIMIT 1
        ";

        if ($stmt = $mysqli->prepare($query)) {
            $stmt->bind_param("sssss", $recommendation, $date, $status, $pre_repair_no, $userLocation);

            if ($stmt->execute()) {
                $_SESSION['maintenance_message'] = $stmt->affected_rows > 0
                    ? "<div class='alert alert-success m-3 mt-0'>Maintenance record copied successfully!</div>"
                    : "<div class='alert alert-warning m-3 mt-0'>No matching document found for Pre-Repair No: " . htmlspecialchars($pre_repair_no) . "</div>";
            } else {
                $_SESSION['maintenance_message'] = "<div class='alert alert-danger m-3 mt-0'>Error inserting record: " . htmlspecialchars($stmt->error) . "</div>";
            }
            $stmt->close();
        } else {
            $_SESSION['maintenance_message'] = "<div class='alert alert-danger m-3 mt-0'>Failed to prepare query: " . htmlspecialchars($mysqli->error) . "</div>";
        }
    } else {
        $_SESSION['maintenance_message'] = "<div class='alert alert-warning m-3 mt-0'>All fields are required.</div>";
    }

    header("Location: side_maintenance.php");
    exit();
}

// Fetch only "Done" documents FILTERED BY USER'S LOCATION
$result = null;
$stmt = null;

// Fetch only "Done" (not "Complete") documents FILTERED BY USER'S LOCATION
$result = null;
$stmt = null;

if (!empty($userLocation)) {
    $query_done = "
        SELECT 
            d.id,
            c.category_name AS equipment,
            d.property_no,
            d.pre_repair_no,
            d.recommendation, 
            d.status,
            (SELECT COUNT(*) FROM supply_requests sr WHERE sr.pre_repair_no = d.pre_repair_no) as supply_sent_count
        FROM documents d
        LEFT JOIN equipment_category c ON d.category_id = c.id
        WHERE (d.status LIKE 'Done%' OR d.status LIKE 'done%')
        AND d.location = ?
        ORDER BY d.id DESC
    ";

    $stmt = $mysqli->prepare($query_done);
    if ($stmt) {
        $stmt->bind_param("s", $userLocation);
        $stmt->execute();
        $result = $stmt->get_result();
    }
}

// Fetch "Complete" documents for the modal
$complete_result = null;
$complete_stmt = null;

if (!empty($userLocation)) {
    $query_complete = "
        SELECT 
            d.id,
            c.category_name AS equipment,
            d.property_no,
            d.pre_repair_no,
            d.recommendation, 
            d.status,
            d.date_requested
        FROM documents d
        LEFT JOIN equipment_category c ON d.category_id = c.id
        WHERE d.status = 'Complete'
        AND d.location = ?
        ORDER BY d.date_requested DESC
    ";

    $complete_stmt = $mysqli->prepare($query_complete);
    if ($complete_stmt) {
        $complete_stmt->bind_param("s", $userLocation);
        $complete_stmt->execute();
        $complete_result = $complete_stmt->get_result();
    }
}

// Get success/error messages
$maintenance_message = '';
if (isset($_SESSION['maintenance_message'])) {
    $maintenance_message = $_SESSION['maintenance_message'];
    unset($_SESSION['maintenance_message']);
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
        max-height: 90vh;
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


    /* 
MODAL */


    /* Add to your existing <style> block */
    .modal-backdrop {
        backdrop-filter: blur(4px);
    }

    .modal-content {
        border-radius: 12px;
        overflow: hidden;
    }
</style>

<!-- <div class="container mx-auto px-4 py-8"> -->

<?php if (!empty($maintenance_message)): ?>
    <?php echo $maintenance_message; ?>
<?php endif; ?>





<!-- Header Section -->
<div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 gap-4">
    <div>
        <h2 class="text-black md:text-2xl font-bold">MANAGE AND VIEW ALL EQUIPMENT UNDER REPAIR AND MAINTENANCE </h2>
        <!-- <p class="text-black">This section is where documents are allocated to deliver maintenance and repair request
            fullfillment.</p> -->
    </div>

    <?php if (!empty($userLocation)): ?>
        <div class="flex items-center bg-white rounded-lg shadow-sm px-4 py-3 border border-gray-200">
            <div class="w-10 h-10 rounded-full bg-blue-50 flex items-center justify-center mr-3">
                <i class="bi bi-geo-alt text-blue-600"></i>
            </div>
            <div>
                <p class="text-xs text-gray-500 uppercase tracking-wider">MAINTENANCE DEPARTMENT</p>
                <p class="font-semibold text-gray-800"><?php echo htmlspecialchars(strtoupper($userLocation)); ?>
                </p>
            </div>
        </div>
    <?php endif; ?>
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
                <input type="text" id="search_pre_repair"
                    class="search-input w-full pl-10 pr-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-200"
                    placeholder="Search by Pre-Repair No, Equipment, or Property No...">
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="flex items-center space-x-3">
            <!-- Complete Requests Button -->
            <button type="button"
                class="action-btn bg-blue-600 hover:bg-green-500 text-white px-5 py-3 rounded-lg font-medium flex items-center transition duration-200"
                data-bs-toggle="modal" data-bs-target="#completeRequestsModal">
                <i class="fas fa-check-circle mr-2"></i>
                Complete Requests
            </button>

            <!-- NEW: Supply Request Button -->
            <button type="button"
                class="action-btn bg-blue-600 hover:bg-green-500 text-white px-5 py-3 rounded-lg font-medium flex items-center transition duration-200"
                data-bs-toggle="modal" data-bs-target="#supplyRequestsModal">
                <i class="fas fa-boxes mr-2"></i>
                Supply Requests
            </button>
        </div>
    </div>
</div>
<!-- Main Table -->
<div class="bg-white rounded-xl shadow-sm overflow-hidden">


    <!-- Table Header -->
    <div class="px-6 py-4 border-b border-gray-200 bg-white-50">
        <div class="flex justify-between items-center">
            <h2 class="text-lg font-semibold text-gray-800">REPAIR AND MAINTENANCE REQUESTS</h2>
            <div class="flex items-center space-x-4">

                <span class="text-sm text-gray-600">
                    Showing <?php echo $result ? $result->num_rows : 0; ?> requests
                </span>
            </div>
        </div>
        <!-- <p>This section manages all documents marked as 'Done' that are currently scheduled for repair and
            maintenance.</p> -->
    </div>

    <!-- Maintenance Table -->

    <div class="table-scroll-container overflow-auto">
        <table class="min-w-full divide-y divide-gray-200" id="maintenanceTable">
            <thead class="sticky-header bg-gray-50">
                <tr>
                    <th scope="col"
                        class="px-6 py-3 text-left text-xs font-bold text-black-500 uppercase tracking-wider">
                        Equipment
                    </th>
                    <th scope="col"
                        class="px-6 py-3 text-left text-xs font-bold text-black-500 uppercase tracking-wider">
                        Property No
                    </th>
                    <th scope="col"
                        class="px-6 py-3 text-left text-xs font-bold text-black-500 uppercase tracking-wider">
                        Pre-Repair No
                    </th>
                    <th scope="col"
                        class="px-6 py-3 text-left text-xs font-bold text-black-500 uppercase tracking-wider">
                        Recommendation
                    </th>
                    <!-- <th scope="col"
                            class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Status
                        </th> -->
                    <th scope="col"
                        class="px-6 py-3 text-left text-xs font-boldtext-sm font-semibold text-gray-900 text-black-500 uppercase tracking-wider">
                        Actions
                    </th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php if ($result && $result->num_rows > 0): ?>
                    <?php while ($row = $result->fetch_assoc()):
                        $pre_repair_no = htmlspecialchars($row['pre_repair_no'] ?? '');
                        $status = $row['status'] ?? 'Done';
                        $statusVal = strtolower(trim($status));

                        // Determine Badge Color
                        if ($status === 'Complete') {
                            $badgeClass = 'bg-blue-100 text-blue-800';
                        } elseif (strpos(strtolower($status), 'done') !== false) {
                            $badgeClass = 'bg-green-100 text-green-800';
                        } elseif ($status === 'In Progress') {
                            $badgeClass = 'bg-yellow-100 text-yellow-800';
                        } else {
                            $badgeClass = 'bg-gray-100 text-gray-800';
                        }
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
                                            <?= htmlspecialchars($row['equipment'] ?? 'N/A'); ?>
                                            <?php if (($row['supply_sent_count'] ?? 0) > 0): ?>
                                                <span
                                                    class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">
                                                    <i class="fas fa-paper-plane mr-1"></i>
                                                    Sent to Supply
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </td>

                            <!-- Property No -->
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-900 font-mono">
                                    <?= htmlspecialchars($row['property_no'] ?? 'N/A'); ?>
                                </div>
                            </td>

                            <!-- Pre-Repair No -->
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-semibold text-gray-900">
                                    <?= $pre_repair_no; ?>
                                </div>
                            </td>

                            <!-- Recommendation -->
                            <td class="px-6 py-4">
                                <div class="text-sm text-gray-900">
                                    <?= htmlspecialchars($row['recommendation'] ?? 'N/A'); ?>
                                </div>
                            </td>

                            <!-- Actions -->
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                <div class="flex items-center space-x-2">
                                    <button type="button"
                                        class="inline-flex items-center px-3 py-2 border border-blue-300 text-blue-700 bg-blue-50 rounded-lg hover:bg-blue-100 transition duration-200"
                                        title="View Details" onclick="loadDocumentView(<?= $row['id']; ?>, 'manage');">
                                        <i class="fas fa-edit mr-1"></i>

                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" class="px-6 py-12 text-center">
                            <div class="text-gray-500">
                                <i class="fas fa-clipboard-list text-4xl mb-4 opacity-30"></i>
                                <h3 class="text-lg font-medium text-gray-900 mb-2">No maintenance records found</h3>
                                <p class="text-gray-600 mb-4">
                                    <?php if (empty($userLocation)): ?>
                                        User location not set. Please contact administrator.
                                    <?php else: ?>
                                        No completed maintenance records for <?php echo htmlspecialchars($userLocation); ?>
                                        location.
                                    <?php endif; ?>
                                </p>
                                <?php if (isset($_SESSION['user_id'])): ?>
                                    <p class="text-sm text-gray-500">
                                        Only documents marked as "Done" or "Complete" appear here.
                                    </p>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>


<!-- COMPLETE REQUESTS MODAL -->
<div class="modal fade" id="completeRequestsModal" tabindex="-1" aria-labelledby="completeRequestsModalLabel"
    aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-xl">
            <!-- Modal Header -->
            <div
                class="modal-header bg-gradient-to-r from-blue-600 to-blue-800 text-white p-6 border-b border-blue-700">
                <div class="flex items-center">
                    <div class="bg-white/20 rounded-lg p-3 mr-4">
                        <i class="bi bi-check-circle-fill text-2xl"></i>
                    </div>
                    <div>
                        <h5 class="modal-title text-xl font-bold">COMPLETE REQUESTS</h5>
                        <p class="text-blue-100 text-sm mt-1">
                            Showing all completed maintenance requests for
                            <?php echo htmlspecialchars($userLocation); ?>
                        </p>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white opacity-100 hover:opacity-75 transition-opacity"
                    data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <!-- Modal Body -->
            <div class="modal-body p-0">
                <!-- Search Bar -->
                <div class="sticky top-0 bg-white border-b border-gray-200 px-6 py-4 shadow-sm z-10">
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-search text-gray-400"></i>
                        </div>
                        <input type="text" id="completeSearchInput"
                            class="w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-200"
                            placeholder="Search by Equipment, Property No, or Pre-Repair No...">
                    </div>
                </div>

                <!-- Complete Requests Table -->
                <div class="table-scroll-container overflow-auto max-h-[90vh]">
                    <table class="min-w-full divide-y divide-gray-200" id="completeRequestsTable">
                        <thead class="sticky-header bg-gray-50">
                            <tr>
                                <th scope="col"
                                    class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Property No</th>
                                <th scope="col"
                                    class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Pre-Repair No</th>
                                <th scope="col"
                                    class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Recommendation</th>
                                <th scope="col"
                                    class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Date Completed</th>
                                <th scope="col"
                                    class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200" id="completeRequestsBody">
                            <?php if ($completed_result && $completed_result->num_rows > 0): ?>
                                <?php while ($row = $completed_result->fetch_assoc()):
                                    $propertyNo = htmlspecialchars($row['property_no']);
                                    $preRepairNo = htmlspecialchars($row['pre_repair_no']);
                                    $recommendation = htmlspecialchars($row['recommendation'] ?? 'N/A');
                                    $dateCompleted = !empty($row['date_completed']) ? date('m/d/Y', strtotime($row['date_completed'])) : 'N/A';
                                    ?>
                                    <tr class="complete-row hover-row transition duration-150">
                                        <!-- Property No -->
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-900 font-mono">
                                                <?php echo $propertyNo; ?>
                                            </div>
                                        </td>

                                        <!-- Pre-Repair No -->
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm font-mono text-gray-900">
                                                <?php echo $preRepairNo; ?>
                                            </div>
                                        </td>

                                        <!-- Recommendation -->
                                        <td class="px-6 py-4">
                                            <div class="text-sm text-gray-900">
                                                <?php echo $recommendation; ?>
                                            </div>
                                        </td>

                                        <!-- Date Completed -->
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-900">
                                                <?php echo $dateCompleted; ?>
                                            </div>
                                        </td>

                                        <!-- Actions -->
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <div class="flex items-center space-x-2">
                                                <button
                                                    class="view-complete-btn inline-flex items-center px-3 py-2 border border-blue-300 text-blue-700 bg-blue-50 rounded-lg hover:bg-blue-100 transition duration-200"
                                                    data-id="<?php echo $row['id']; ?>" title="View Details">
                                                    <i class="fas fa-eye mr-1.5"></i>
                                                    View
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="px-6 py-12 text-center">
                                        <div class="text-gray-500">
                                            <i class="fas fa-clipboard-check text-4xl mb-4 opacity-30"></i>
                                            <h3 class="text-lg font-medium text-gray-900 mb-2">No complete requests
                                                found</h3>
                                            <p class="text-gray-600 mb-4">
                                                <?php if (empty($userLocation)): ?>
                                                    User location not set. Please contact administrator.
                                                <?php else: ?>
                                                    No completed maintenance records for
                                                    <?php echo htmlspecialchars($userLocation); ?> location.
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Modal Footer -->
                <div class="bg-gray-50 px-6 py-3 border-t border-gray-200">
                    <div class="flex items-center justify-between text-sm text-gray-600">
                        <div class="flex items-center">
                            <i class="bi bi-info-circle mr-2"></i>
                            <span id="completeCount">
                                <?php
                                $complete_count = $completed_result ? $completed_result->num_rows : 0;
                                echo "Showing " . $complete_count . " complete requests";
                                ?>
                            </span>
                        </div>
                        <div>
                            <span class="font-medium">Location: </span>
                            <span class="text-blue-600"><?php echo htmlspecialchars($userLocation); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>






<!-- SUPPLY REQUESTS MODAL -->
<div class="modal fade" id="supplyRequestsModal" tabindex="-1" aria-labelledby="supplyRequestsModalLabel"
    aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-xl">
            <!-- Modal Header -->
            <div
                class="modal-header bg-gradient-to-r from-green-600 to-green-800 text-white p-6 border-b border-green-700">
                <div class="flex items-center">
                    <div class="bg-white/20 rounded-lg p-3 mr-4">
                        <i class="bi bi-box-seam-fill text-2xl"></i>
                    </div>
                    <div>
                        <h5 class="modal-title text-xl font-bold">SUPPLY REQUESTS</h5>
                        <p class="text-green-100 text-sm mt-1">
                            This section manages all documents sent to supply department for ordering requested
                            equipment and materials.
                        </p>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white opacity-100 hover:opacity-75 transition-opacity"
                    data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <!-- Modal Body -->
            <div class="modal-body p-0">
                <!-- Search and Filter Bar -->
                <div class="sticky top-0 bg-white border-b border-gray-200 px-6 py-4 shadow-sm z-10">
                    <div class="flex flex-col md:flex-row gap-4">
                        <!-- Search Bar -->
                        <div class="flex-1">
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <i class="fas fa-search text-gray-400"></i>
                                </div>
                                <input type="text" id="supplySearchInput"
                                    class="w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500 transition duration-200"
                                    placeholder="Search by Pre-Repair No, Property No, or Supply Location...">
                            </div>
                        </div>

                        <!-- Status Filter -->
                        <div class="flex items-center space-x-4">
                            <select
                                class="filter-select px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500 transition duration-200"
                                id="supplyStatusFilter" style="min-width: 150px;">
                                <option value="">All Status</ woption>
                                <option value="pending">PENDING</option>
                                <option value="approved">APPROVED</option>
                                <!-- <option value="ordered">ORDERED</option> -->
                                <!-- <option value="delivered">DELIVERED</option>  -->
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Supply Requests Table -->
                <div class="table-scroll-container overflow-auto max-h-[90vh]">
                    <table class="min-w-full divide-y divide-gray-200" id="supplyRequestsTable">
                        <thead class="sticky-header bg-gray-50">
                            <tr>
                                <th scope="col"
                                    class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Pre-Repair No</th>
                                <th scope="col"
                                    class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Property No</th>
                                <th scope="col"
                                    class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Supply Location</th>
                                <th scope="col"
                                    class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Status</th>
                                <th scope="col"
                                    class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Requested By</th>
                                <th scope="col"
                                    class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200" id="supplyRequestsBody">
                            <?php
                            // Query supply requests based on user's location
                            if (!empty($userLocation)) {
                                $supply_query = "
                                    SELECT 
                                        sr.id as supply_id,
                                        sr.pre_repair_no,
                                        sr.property_no,
                                        sr.supply_location,
                                        sr.status,
                                        sr.requested_by,
                                        sr.created_at,
                                        d.id as document_id
                                    FROM supply_requests sr
                                    LEFT JOIN documents d ON sr.pre_repair_no = d.pre_repair_no
                                    WHERE sr.admin_location = ?
                                    ORDER BY sr.created_at DESC
                                ";

                                $supply_stmt = $mysqli->prepare($supply_query);
                                if ($supply_stmt) {
                                    $supply_stmt->bind_param("s", $userLocation);
                                    $supply_stmt->execute();
                                    $supply_result = $supply_stmt->get_result();

                                    if ($supply_result && $supply_result->num_rows > 0) {
                                        while ($supply = $supply_result->fetch_assoc()) {
                                            // Determine status badge color
                                            $statusClass = match ($supply['status']) {
                                                'pending' => 'bg-yellow-100 text-yellow-800',
                                                'approved' => 'bg-blue-100 text-blue-800',
                                                'ordered' => 'bg-purple-100 text-purple-800',
                                                'delivered' => 'bg-green-100 text-green-800',
                                                default => 'bg-gray-100 text-gray-800',
                                            };

                                            // Format status display
                                            $statusDisplay = ucfirst($supply['status']);
                                            ?>
                                            <tr class="supply-row hover-row transition duration-150">
                                                <!-- Pre-Repair No -->
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="text-sm font-semibold text-gray-900">
                                                        <?= htmlspecialchars($supply['pre_repair_no'] ?? 'N/A'); ?>
                                                    </div>
                                                </td>

                                                <!-- Property No -->
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="text-sm text-gray-900 font-mono">
                                                        <?= htmlspecialchars($supply['property_no'] ?? 'N/A'); ?>
                                                    </div>
                                                </td>

                                                <!-- Supply Location -->
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div
                                                        class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                                        <i class="fas fa-map-marker-alt mr-1.5 text-xs"></i>
                                                        <?= htmlspecialchars($supply['supply_location'] ?? 'N/A'); ?>
                                                    </div>
                                                </td>

                                                <!-- Status -->
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <span
                                                        class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium <?= $statusClass; ?>">
                                                        <i class="fas fa-circle text-xs mr-1.5 opacity-70"></i>
                                                        <?= $statusDisplay; ?>
                                                    </span>
                                                </td>

                                                <!-- Requested By -->
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="text-sm text-gray-900">
                                                        <?= htmlspecialchars($supply['requested_by'] ?? 'N/A'); ?>
                                                    </div>
                                                    <?php if (!empty($supply['created_at'])): ?>
                                                        <div class="text-xs text-gray-500">
                                                            <?= date('M d, Y', strtotime($supply['created_at'])); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>

                                                <!-- Actions -->
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                    <div class="flex items-center space-x-2">
                                                        <button
                                                            class="view-supply-doc-btn inline-flex items-center px-3 py-2 border border-green-300 text-green-700 bg-green-50 rounded-lg hover:bg-green-100 transition duration-200"
                                                            data-id="<?= $supply['document_id']; ?>"
                                                            data-supply-id="<?= $supply['supply_id']; ?>" title="View Details">
                                                            <i class="fas fa-eye mr-1.5"></i>
                                                            View
                                                        </button>
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
                                                    <i class="fas fa-box text-4xl mb-4 opacity-30"></i>
                                                    <h3 class="text-lg font-medium text-gray-900 mb-2">No supply requests found
                                                    </h3>
                                                    <p class="text-gray-600 mb-4">
                                                        No supply requests for <?php echo htmlspecialchars($userLocation); ?>
                                                        location.
                                                    </p>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php
                                    }
                                    $supply_stmt->close();
                                }
                            } else {
                                ?>
                                <tr>
                                    <td colspan="6" class="px-6 py-12 text-center">
                                        <div class="text-gray-500">
                                            <i class="fas fa-exclamation-circle text-4xl mb-4 opacity-30"></i>
                                            <h3 class="text-lg font-medium text-gray-900 mb-2">Location Required</h3>
                                            <p class="text-gray-600 mb-4">
                                                User location not set. Please contact administrator.
                                            </p>
                                        </div>
                                    </td>
                                </tr>
                                <?php
                            }
                            ?>
                        </tbody>
                    </table>
                </div>

                <!-- Modal Footer -->
                <div class="bg-gray-50 px-6 py-3 border-t border-gray-200">
                    <div class="flex items-center justify-between text-sm text-gray-600">
                        <div class="flex items-center">
                            <i class="bi bi-info-circle mr-2"></i>
                            <span id="supplyRequestCount">
                                <?php
                                if (!empty($userLocation)) {
                                    $supply_count_query = "SELECT COUNT(*) as count FROM supply_requests WHERE admin_location = ?";
                                    $supply_stmt = $mysqli->prepare($supply_count_query);
                                    $supply_stmt->bind_param("s", $userLocation);
                                    $supply_stmt->execute();
                                    $supply_count_result = $supply_stmt->get_result();
                                    $supply_count = $supply_count_result->fetch_assoc()['count'] ?? 0;
                                    $supply_stmt->close();
                                    echo "Showing " . $supply_count . " supply requests";
                                } else {
                                    echo "0 requests";
                                }
                                ?>
                            </span>
                        </div>
                        <div>
                            <span class="font-medium">Location: </span>
                            <span class="text-green-600"><?php echo htmlspecialchars($userLocation); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- SEND SUPPLY REQUEST MODAL -->
<div class="modal fade" id="sendSupplyModal" tabindex="-1" aria-labelledby="sendSupplyModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="send_supply_request.php" id="sendSupplyForm"
                onsubmit="return handleSupplySubmit(event)">
                <input type="hidden" name="document_id" id="sendSupplyDocId" value="">
                <input type="hidden" name="pre_repair_no" id="sendSupplyPreRepairNo" value="">

                <div class="modal-header bg-gradient-to-r from-green-600 to-green-800 text-white">
                    <h5 class="modal-title" id="sendSupplyModalLabel">
                        <i class="bi bi-send"></i> Send Request to Supply Department
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                        aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <!-- Document Info -->
                    <div class="alert alert-info mb-4">
                        <div class="row">
                            <div class="col-md-6">
                                <small><strong>Pre-Repair No:</strong> <span
                                        id="supplyPreRepairDisplay">-</span></small>
                            </div>
                            <div class="col-md-6">
                                <small><strong>Property No:</strong> <span id="supplyPropertyDisplay">-</span></small>
                            </div>
                        </div>
                    </div>

                    <!-- Location Selection -->
                    <div class="mb-4">
                        <label class="form-label fw-bold">Select Supply Department Location:</label>
                        <select name="supply_location" class="form-select form-select-lg" required>
                            <option value="">-- Select Supply Location --</option>
                            <option value="mamburao">Mamburao Supply Department</option>
                            <option value="sablayan">Sablayan Supply Department</option>
                            <option value="san_jose">San Jose Supply Department</option>
                            <option value="lubang">Lubang Supply Department</option>
                        </select>
                        <small class="text-muted">
                            The request will be sent to supply officers at the selected location.
                        </small>
                    </div>

                    <!-- Remarks -->
                    <div class="mb-3">
                        <label class="form-label fw-bold">Additional Remarks:</label>
                        <textarea name="remarks" class="form-control" rows="4"
                            placeholder="Add any additional information or instructions for the supply department..."></textarea>
                    </div>

                    <!-- Info Box -->
                    <div class="alert alert-secondary">
                        <small>
                            <i class="bi bi-info-circle"></i>
                            <strong>Request Information:</strong><br>
                            • Request from:
                            <strong><?= htmlspecialchars($_SESSION['username'] ?? 'Admin') ?></strong><br>
                            • Your location:
                            <strong><?= htmlspecialchars(ucfirst($_SESSION['location'] ?? 'mamburao')) ?></strong>
                        </small>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-send"></i> Send Request
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>


<script>
    document.addEventListener('DOMContentLoaded', function () {
        // Search functionality
        const searchInput = document.getElementById('search_pre_repair');
        const clearSearchBtn = document.getElementById('clearSearch');

        if (searchInput) {
            searchInput.addEventListener('keyup', function () {
                const searchTerm = this.value.toLowerCase().trim();
                const rows = document.querySelectorAll('#maintenanceTable tbody tr');

                let visibleCount = 0;

                rows.forEach(row => {
                    if (row.cells.length >= 6) {
                        const equipment = row.cells[0].querySelector('.text-sm.font-medium')
                            ?.textContent.toLowerCase() || '';
                        const propertyNo = row.cells[1].textContent.toLowerCase();
                        const preRepairNo = row.cells[2].textContent.toLowerCase();
                        const recommendation = row.cells[3].textContent.toLowerCase();

                        if (equipment.includes(searchTerm) ||
                            propertyNo.includes(searchTerm) ||
                            preRepairNo.includes(searchTerm) ||
                            recommendation.includes(searchTerm)) {
                            row.style.display = '';
                            visibleCount++;
                        } else {
                            row.style.display = 'none';
                        }
                    }
                });

                // Update showing count
                const showingCount = document.querySelector('.text-sm.text-gray-600 span');
                if (showingCount) {
                    const totalRows = rows.length - 1; // Subtract 1 for the "no records" row
                    showingCount.textContent = visibleCount + ' maintenance records found';

                    const entriesSpan = document.querySelector('.text-sm.text-gray-600:not(.hidden)');
                    if (entriesSpan && !entriesSpan.classList.contains('hidden')) {
                        const text = entriesSpan.textContent;
                        const newText = text.replace(/\d+/, visibleCount);
                        entriesSpan.textContent = newText;
                    }
                }
            });

            // Also trigger search on Enter key
            searchInput.addEventListener('keypress', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    this.dispatchEvent(new Event('keyup'));
                }
            });
        }


        // Initialize with current filter state
        if (searchInput) {
            searchInput.dispatchEvent(new Event('keyup'));
        }

        // Auto-focus search input on page load
        if (searchInput && window.location.hash !== '#add-form') {
            searchInput.focus();
        }



    });





    // Complete Requests Modal Functionality
    document.addEventListener('DOMContentLoaded', function () {
        const completeSearchInput = document.getElementById('completeSearchInput');
        const completeRows = document.querySelectorAll('#completeRequestsTable .complete-row');
        const completeCount = document.getElementById('completeCount');

        if (completeSearchInput) {
            completeSearchInput.addEventListener('input', function () {
                const searchTerm = this.value.toLowerCase().trim();
                let visibleCount = 0;

                completeRows.forEach(row => {
                    if (row.cells.length >= 6) {
                        const equipment = row.cells[0].querySelector('.text-sm.font-medium')
                            ?.textContent.toLowerCase() || '';
                        const propertyNo = row.cells[1].textContent.toLowerCase();
                        const preRepairNo = row.cells[2].textContent.toLowerCase();
                        const recommendation = row.cells[3].textContent.toLowerCase();

                        if (equipment.includes(searchTerm) ||
                            propertyNo.includes(searchTerm) ||
                            preRepairNo.includes(searchTerm) ||
                            recommendation.includes(searchTerm)) {
                            row.style.display = '';
                            visibleCount++;
                        } else {
                            row.style.display = 'none';
                        }
                    }
                });

                // Update count
                if (completeCount) {
                    completeCount.textContent = `Showing ${visibleCount} complete requests`;
                }

                // Show empty state if no results
                const tableBody = document.querySelector('#completeRequestsTable tbody');
                const existingEmptyRow = tableBody.querySelector('.no-complete-results');

                if (visibleCount === 0 && completeRows.length > 0) {
                    if (!existingEmptyRow) {
                        const emptyRow = document.createElement('tr');
                        emptyRow.className = 'no-complete-results';
                        emptyRow.innerHTML = `
                        <td colspan="6" class="px-6 py-12 text-center">
                            <div class="text-gray-500">
                                <i class="fas fa-search text-4xl mb-4 opacity-30"></i>
                                <h3 class="text-lg font-medium text-gray-900 mb-2">No complete requests found</h3>
                                <p class="text-gray-600 mb-4">
                                    No complete requests match your search.
                                </p>
                            </div>
                        </td>
                    `;
                        tableBody.appendChild(emptyRow);
                    }
                } else {
                    if (existingEmptyRow) {
                        existingEmptyRow.remove();
                    }
                }
            });

            // Auto-focus search input when modal opens
            const completeModal = document.getElementById('completeRequestsModal');
            if (completeModal) {
                completeModal.addEventListener('shown.bs.modal', function () {
                    setTimeout(() => {
                        completeSearchInput.focus();
                    }, 100);
                });
            }
        }
    });



    // Supply Requests Modal Functionality
    document.addEventListener('DOMContentLoaded', function () {
        // Get all supply rows
        const supplyRows = document.querySelectorAll('#supplyRequestsBody .supply-row');
        const supplySearchInput = document.getElementById('supplySearchInput');
        const supplyStatusFilter = document.getElementById('supplyStatusFilter');
        const supplyRequestCount = document.getElementById('supplyRequestCount');

        // Function to apply filters to supply table
        function applySupplyFilters() {
            const searchTerm = supplySearchInput ? supplySearchInput.value.toLowerCase().trim() : '';
            const statusValue = supplyStatusFilter ? supplyStatusFilter.value.toLowerCase() : '';

            let visibleCount = 0;

            supplyRows.forEach(row => {
                if (row.cells.length < 6) return;

                const preRepairNo = row.cells[0].textContent.toLowerCase();
                const propertyNo = row.cells[1].textContent.toLowerCase();
                const supplyLocation = row.cells[2].querySelector('div')?.textContent?.toLowerCase() || '';
                const statusCell = row.cells[3];
                const rowStatus = statusCell.querySelector('span')?.textContent?.toLowerCase().trim() || '';

                // Check search matches
                const searchMatch = !searchTerm ||
                    preRepairNo.includes(searchTerm) ||
                    propertyNo.includes(searchTerm) ||
                    supplyLocation.includes(searchTerm);

                // Check status filter match
                const statusMatch = !statusValue || rowStatus.includes(statusValue);

                // Show/hide row based on all conditions
                if (searchMatch && statusMatch) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });

            // Update result count
            if (supplyRequestCount) {
                const originalText = supplyRequestCount.textContent;
                const baseText = originalText.includes('Showing') ?
                    originalText.replace(/\d+/, visibleCount) :
                    `Showing ${visibleCount} supply requests`;
                supplyRequestCount.textContent = baseText;
            }
        }

        // Add event listeners
        if (supplySearchInput) {
            supplySearchInput.addEventListener('input', applySupplyFilters);
            supplySearchInput.addEventListener('keypress', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    applySupplyFilters();
                }
            });
        }

        if (supplyStatusFilter) {
            supplyStatusFilter.addEventListener('change', applySupplyFilters);
        }

        // View complete document button
        document.querySelectorAll('.view-complete-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                const docId = this.dataset.id;
                loadDocumentView(docId, 'view');
            });
        });

        // View supply details button
        document.querySelectorAll('.view-supply-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                const supplyId = this.dataset.id;
                // You can implement modal or redirect to view page
                alert('View supply request ID: ' + supplyId);
                // window.location.href = `view_supply_request.php?id=${supplyId}`;
            });
        });

        // Initialize filters
        applySupplyFilters();

        // Auto-focus search input when modal opens
        const supplyModal = document.getElementById('supplyRequestsModal');
        if (supplyModal) {
            supplyModal.addEventListener('shown.bs.modal', function () {
                setTimeout(() => {
                    if (supplySearchInput) {
                        supplySearchInput.focus();
                    }
                }, 100);
            });
        }
    });
</script>

<!-- View Document Modal -->
<div class="modal fade" id="viewDocumentModal" tabindex="-1" aria-labelledby="viewDocumentModalLabel"
    aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg rounded-xl overflow-hidden">
            <div class="modal-header bg-gray-50 border-b border-gray-100 px-4 py-3">
                <h5 class="modal-title fs-6 fw-bold text-gray-800 d-flex align-items-center gap-2 mb-0">
                    <i class="bi bi-file-text-fill text-purple"></i>
                    Request Specification Details
                </h5>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body p-0" id="viewDocumentContent" style="background: white;">
                <!-- Content loaded via AJAX -->
            </div>

            <div class="modal-footer bg-gray-50 border-t border-gray-100 px-4 py-3" id="viewModalFooter">
                <button type="button" class="btn btn-sm btn-outline-secondary px-4 rounded-lg fw-semibold"
                    data-bs-dismiss="modal">
                    <i class="bi bi-x-circle"></i> Close
                </button>
                <button type="button" class="btn btn-sm btn-warning px-4 rounded-lg fw-semibold"
                    onclick="openSupplyFromView()">
                    <i class="bi bi-send"></i> Send to Supply
                </button>
                <button type="button" class="btn btn-sm btn-success px-4 rounded-lg fw-semibold"
                    onclick="markCompleteFromModal()">
                    <i class="bi bi-check-circle"></i> Complete
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Photo Modal -->
<div class="modal fade" id="photoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content" style="background:#1a1a1a;border:none;">
            <div class="modal-header"
                style="background:linear-gradient(135deg, #198754 0%, #146c43 100%);color:white;border:none;">
                <h5 class="modal-title"><i class="bi bi-image"></i> Photo Preview</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0"
                style="display:flex;align-items:center;justify-content:center;min-height:60vh;background:#1a1a1a;">
                <img id="modalPhoto" src="" alt="Photo" style="max-width:100%;max-height:75vh;object-fit:contain;">
            </div>
            <div class="modal-footer" style="background:#1a1a1a;border:none;">
                <a id="downloadPhoto" href="" download class="btn btn-primary">
                    <i class="bi bi-download"></i> Download
                </a>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
    // Store current document ID for actions
    let currentViewDocId = null;

    // Helper function to clean up stuck backdrops
    function cleanupBackdrops() {
        // Remove any existing modal backdrops
        document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
        // Clean up body classes and styles
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('overflow');
        document.body.style.removeProperty('padding-right');
    }

    // Add event listener to clean up backdrops when ANY modal is closed (hidden)
    document.addEventListener('hidden.bs.modal', function (event) {
        // Only cleanup if no other modals are showing
        const openModals = document.querySelectorAll('.modal.show');
        if (openModals.length === 0) {
            cleanupBackdrops();
        }
    });

    // Open Supply Modal from View
    function openSupplyFromView() {
        if (!currentViewDocId) {
            alert('Please select a document first');
            return;
        }

        // Get document info from the meta element loaded in the view content
        const modalContent = document.getElementById('viewDocumentContent');
        const meta = modalContent ? modalContent.querySelector('#documentMeta') : null;

        if (meta && meta.dataset.supplyRequestExists === 'true') {
            showAlert('warning', 'A supply request has already been sent for this document.');
            return;
        }

        let preRepairNo = '';
        let propertyNo = '';

        if (meta) {
            preRepairNo = meta.dataset.preRepairNo || '';
            propertyNo = meta.dataset.propertyNo || '';
        } else {
            // Fallback: Try to find pre-repair no in the content if meta is missing
            const preRepairInputs = modalContent.querySelectorAll('input[value*="PR-"]');
            if (preRepairInputs.length > 0) {
                preRepairNo = preRepairInputs[0].value;
            }

            // Look for property no
            const propertyInputs = modalContent.querySelectorAll('input[value*="PROP-"], input[value*="PN-"]');
            if (propertyInputs.length > 0) {
                propertyNo = propertyInputs[0].value;
            }
        }

        // Populate the send supply modal
        document.getElementById('sendSupplyDocId').value = currentViewDocId;
        document.getElementById('sendSupplyPreRepairNo').value = preRepairNo;
        document.getElementById('supplyPreRepairDisplay').textContent = preRepairNo || '-';
        document.getElementById('supplyPropertyDisplay').textContent = propertyNo || '-';

        // Close view modal first
        const viewModalEl = document.getElementById('viewDocumentModal');
        if (viewModalEl) {
            const viewModal = bootstrap.Modal.getInstance(viewModalEl);
            if (viewModal) {
                viewModal.hide();
            }
        }

        // Open send supply modal after a short delay
        setTimeout(() => {
            cleanupBackdrops();
            const sendSupplyModalEl = document.getElementById('sendSupplyModal');
            if (sendSupplyModalEl) {
                const sendSupplyModal = bootstrap.Modal.getOrCreateInstance(sendSupplyModalEl);
                sendSupplyModal.show();
            }
        }, 350);
    }

    // Mark as Complete from View
    function markCompleteFromView() {
        if (!currentViewDocId) {
            alert('Please select a document first');
            return;
        }
        if (confirm('Are you sure you want to mark this request as Complete?')) {
            fetch('complete_document.php?id=' + currentViewDocId, {
                method: 'POST'
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Document marked as Complete!');
                        location.reload();
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred. Please try again.');
                });
        }
    }

    // Function to load document data into view modal
    async function loadDocumentView(docId, source = 'manage', supplyId = null) {
        currentViewDocId = docId;

        const modalElement = document.getElementById('viewDocumentModal');
        const modalContent = document.getElementById('viewDocumentContent');
        const actionButtons = document.querySelectorAll('.action-buttons');
        const footerButtons = document.querySelectorAll('#viewDocumentModal .modal-footer .btn');

        // Show/hide action buttons based on source (only show for 'manage' source)
        if (source === 'manage') {
            actionButtons.forEach(btn => btn.style.display = '');
            // Show edit mode footer buttons
            footerButtons.forEach(btn => btn.style.display = '');
        } else {
            actionButtons.forEach(btn => btn.style.display = 'none');
            // Hide edit mode footer buttons for non-manage sources
            footerButtons.forEach(btn => {
                if (!btn.closest('[data-bs-dismiss="modal"]')) {
                    btn.style.display = 'none';
                }
            });
        }

        // Show loading state
        modalContent.innerHTML = `
            <div class="text-center py-5">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="mt-2 text-muted">Fetching document details...</p>
            </div>
        `;

        const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
        modal.show();

        try {
            // Load in view mode (readonly) for maintenance documents
            let url = `get_document_simple.php?id=${docId}`;
            if (supplyId) {
                url += `&supply_id=${supplyId}`;
            }

            const response = await fetch(url);
            if (!response.ok) throw new Error('Failed to fetch document');

            const html = await response.text();
            modalContent.innerHTML = html;

        } catch (error) {
            console.error('Error loading document:', error);
            modalContent.innerHTML = `
                <div class="alert alert-danger m-3">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    Failed to load document. Please try again.
                </div>
            `;
        }
    }

    // View complete document button
    document.querySelectorAll('.view-complete-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            const docId = this.dataset.id;
            loadDocumentView(docId, 'view');
        });
    });

    // View supply document button
    document.querySelectorAll('.view-supply-doc-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            const docId = this.dataset.id;
            const supplyId = this.dataset.supplyId;
            loadDocumentView(docId, 'view', supplyId);
        });
    });

    // Handle Send to Supply form submission via AJAX
    function handleSupplySubmit(event) {
        if (event) {
            event.preventDefault();
            event.stopPropagation();
        }

        const form = document.getElementById('sendSupplyForm');
        if (!form) {
            console.error('Form not found');
            return false;
        }

        const formData = new FormData(form);
        const submitBtn = form.querySelector('button[type="submit"]');
        if (!submitBtn) {
            console.error('Submit button not found');
            return false;
        }

        const originalBtnText = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Sending...';

        fetch('send_supply_request.php', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
            .then(response => {
                const contentType = response.headers.get('content-type');

                if (contentType && contentType.includes('application/json')) {
                    return response.json().then(data => ({ json: true, data, response }));
                }
                return { json: false, response };
            })
            .then(result => {
                if (result.json) {
                    if (result.data.success) {
                        const modalEl = document.getElementById('sendSupplyModal');
                        if (modalEl) {
                            const modal = bootstrap.Modal.getInstance(modalEl);
                            if (modal) modal.hide();
                        }
                        cleanupBackdrops();
                        showAlert('success', result.data.message || 'Request sent successfully!');
                        // Instead of redirecting, just close modals and refresh if needed
                        const supplyModalEl = document.getElementById('supplyRequestsModal');
                        if (supplyModalEl) {
                            const supplyModal = bootstrap.Modal.getInstance(supplyModalEl);
                            if (supplyModal) supplyModal.hide();
                        }

                        // Small delay before reloading data (or page if standalone)
                        setTimeout(() => {
                            if (window.location.pathname.includes('admin_dashboard.php')) {
                                // If inside dashboard, we can reload the view or refresh
                                location.reload();
                            } else {
                                location.reload();
                            }
                        }, 1500);
                    } else {
                        showAlert('danger', result.data.message || 'Failed to send request.');
                    }
                } else {
                    if (result.response.ok) {
                        const modalEl = document.getElementById('sendSupplyModal');
                        if (modalEl) {
                            const modal = bootstrap.Modal.getInstance(modalEl);
                            if (modal) modal.hide();
                        }
                        cleanupBackdrops();
                        showAlert('success', 'Request sent successfully!');
                        setTimeout(() => {
                            window.location.href = 'side_maintenance.php';
                        }, 1500);
                    } else {
                        showAlert('danger', 'Failed to send request.');
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('danger', 'An error occurred. Please try again.');
            })
            .finally(() => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalBtnText;
            });

        return false;
    }

    function showAlert(type, message) {
        const alertDiv = document.createElement('div');
        alertDiv.className = `alert alert-${type} alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3 z-index-9999`;
        alertDiv.style.zIndex = '9999';
        alertDiv.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        document.body.appendChild(alertDiv);

        setTimeout(() => {
            alertDiv.remove();
        }, 5000);
    }

    let currentZoom = 1;

    function showPhoto(photoPath) {
        currentZoom = 1;
        const myModal = new bootstrap.Modal(document.getElementById('photoModal'));
        const img = document.getElementById('modalPhoto');
        img.style.transform = 'scale(1)';
        img.src = photoPath;
        document.getElementById('downloadPhoto').href = photoPath;
        myModal.show();
    }

    function zoomPhoto(delta) {
        currentZoom += delta;
        if (currentZoom < 0.3) currentZoom = 0.3;
        if (currentZoom > 3) currentZoom = 3;
        document.getElementById('modalPhoto').style.transform = 'scale(' + currentZoom + ')';
    }

    function resetZoom() {
        currentZoom = 1;
        document.getElementById('modalPhoto').style.transform = 'scale(1)';
    }

    // Save Document from Modal
    function saveDocumentFromModal() {
        if (!currentViewDocId) {
            alert('No document selected');
            return;
        }

        const form = document.getElementById('editDocumentForm');
        if (!form) {
            alert('Form not found');
            return;
        }

        if (!confirm('Save changes to this document?')) return;

        const formData = new FormData(form);
        formData.append('id', currentViewDocId);

        fetch('update_document_ajax.php', {
            method: 'POST',
            body: formData
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert('success', 'Document saved successfully!');
                } else {
                    showAlert('danger', 'Failed to save: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('Error saving document:', error);
                showAlert('danger', 'Error saving document');
            });
    }

    // Add to Maintenance from Modal
    function addToMaintenanceFromModal() {
        if (!currentViewDocId) {
            alert('No document selected');
            return;
        }
        if (!confirm('Add this document to maintenance?')) return;

        fetch('update_document_status.php?id=' + currentViewDocId + '&action=maintenance', {
            method: 'POST'
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert('success', 'Added to maintenance!');
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    showAlert('danger', 'Failed: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('danger', 'Error processing request');
            });
    }

    // Print Document from Modal
    function printDocumentFromModal() {
        window.print();
    }

    // Archive Document from Modal
    function archiveDocumentFromModal() {
        if (!currentViewDocId) {
            alert('No document selected');
            return;
        }
        if (!confirm('Archive this document?')) return;

        fetch('update_document_status.php?id=' + currentViewDocId + '&action=archive', {
            method: 'POST'
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert('success', 'Document archived!');
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    showAlert('danger', 'Failed: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('danger', 'Error processing request');
            });
    }

    // Mark Complete from Modal (renamed from markCompleteFromView)
    function markCompleteFromModal() {
        if (!currentViewDocId) {
            alert('No document selected');
            return;
        }
        if (!confirm('Are you sure you want to mark this request as Complete?')) return;

        fetch('complete_document.php?id=' + currentViewDocId, {
            method: 'POST'
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert('success', 'Document marked as Complete!');
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    showAlert('danger', 'Failed: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('danger', 'Error processing request');
            });
    }
</script>

<?php
// Close prepared statements
if (isset($stmt)) {
    $stmt->close();
}

// Close database connection
$mysqli->close();
?>