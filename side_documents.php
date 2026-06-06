<?php
require_once __DIR__ . '/config.php';
include_once 'profile_modal.php';

// Use CSRF token from admin_dashboard.php (passed via include scope)
// $csrfToken is already available from the parent file

// ✅ VIEW REQUEST MODAL - Get user location and handle actions
$userLocation = $_SESSION['location'] ?? $_SESSION['user_location'] ?? '';

// If location is empty, try to fetch it from database
if (empty($userLocation) && isset($_SESSION['user_id'])) {
    $userId = $_SESSION['user_id'];
    $query = $mysqli->prepare("SELECT location FROM users WHERE id = ?");
    $query->bind_param("i", $userId);
    $query->execute();
    $result = $query->get_result();

    if ($row = $result->fetch_assoc()) {
        $_SESSION['location'] = $row['location'];
        $userLocation = $row['location'];
    }
    $query->close();
}

// ✅ Handle Approve, Archive, and Unarchive actions for View Request Modal
if (isset($_POST['action']) && isset($_POST['id'])) {
    // CSRF validation
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $submittedToken)) {
        die('CSRF validation failed');
    }

    $id = intval($_POST['id']);
    $action = $_POST['action'];
    $status = '';

    if ($action === 'approve') {
        $status = 'Approved';
    } elseif ($action === 'archive') {
        $status = 'Archived';
    } elseif ($action === 'unarchive') {
        $status = 'Pending';
    }

    if ($status !== '') {
        $date_approved = ($action === 'approve') ? date('Y-m-d') : null;

        if ($action === 'approve') {
            $update = $mysqli->prepare("UPDATE documents SET status = ?, date_approved = ? WHERE id = ?");
            $update->bind_param('ssi', $status, $date_approved, $id);
        } else {
            $update = $mysqli->prepare("UPDATE documents SET status = ? WHERE id = ?");
            $update->bind_param('si', $status, $id);
        }

        if ($update->execute()) {
            $_SESSION['view_request_message'] = "<div class='alert alert-success m-3 mt-0'>Request #$id has been successfully marked as <strong>$status</strong>.</div>";
        } else {
            $_SESSION['view_request_message'] = "<div class='alert alert-danger m-3 mt-0'>Failed to update request.</div>";
        }
        $update->close();

        $redirect_url = 'side_documents.php';
        if (basename($_SERVER['PHP_SELF']) !== 'side_documents.php') {
            $redirect_url = $_SERVER['PHP_SELF'] . '?view=documents';
        }

        header("Location: " . $redirect_url);
        exit();
    }
}


// ✅ MAIN TABLE QUERY for View Request Modal: Filtered by User's Location and ONLY PENDING status
$viewRequestMessage = '';
if (isset($_SESSION['view_request_message'])) {
    $viewRequestMessage = $_SESSION['view_request_message'];
    unset($_SESSION['view_request_message']);
}

$stmtMain = null;
$mainResult = null;
$archivedResult = null;

if (!empty($userLocation)) {
    // Active requests query - ONLY PENDING status
    $stmtMain = $mysqli->prepare("
    SELECT d.id, d.pre_repair_no, d.property_no, d.date_requested, d.status, d.signature, d.officer_name, c.category_name
    FROM documents d
    LEFT JOIN equipment_category c ON d.category_id = c.id
    WHERE d.status = 'Pending'
    AND d.location = ? 
    ORDER BY d.date_requested ASC
");
    $stmtMain->bind_param("s", $userLocation);
    $stmtMain->execute();
    $mainResult = $stmtMain->get_result();

    // Archived requests query
    $stmtArchived = $mysqli->prepare("
    SELECT d.id, d.pre_repair_no, d.property_no, d.date_requested, d.status, d.signature, d.officer_name, c.category_name
    FROM documents d
    LEFT JOIN equipment_category c ON d.category_id = c.id
    WHERE d.status = 'Archived' 
    AND d.location = ?
    ORDER BY d.date_requested ASC
");
    $stmtArchived->bind_param("s", $userLocation);
    $stmtArchived->execute();
    $archivedResult = $stmtArchived->get_result();
}

// ✅ Show only Approved documents for main table
$validStatuses = [
    "Approved" => ["label" => "Approved", "class" => "bg-green-100 text-green-800"],
];

// ✅ Query only Approved documents for main table FILTERED BY USER'S LOCATION
$result = null;
$stmtMainApproved = null;

if (!empty($userLocation)) {
    // Modified query to filter by user's location
// New query (shows date_requested instead of status):
    $sql = "
    SELECT 
        d.id, 
        c.category_name, 
        e.property_no, 
        d.pre_repair_no, 
        d.date_requested,
        d.officer_name,
        e.location
    FROM documents AS d
    LEFT JOIN equipment_category AS c ON d.category_id = c.id
    LEFT JOIN equipment AS e ON d.property_no = e.property_no
    WHERE d.status = 'Approved'
    AND d.location = ?
    ORDER BY d.id DESC
    ";

    $stmtMainApproved = $mysqli->prepare($sql);
    if ($stmtMainApproved) {
        $stmtMainApproved->bind_param("s", $userLocation);
        $stmtMainApproved->execute();
        $result = $stmtMainApproved->get_result();
    }
} else {
    // If location is empty, show message instead of all documents
    $result = false; // This will trigger the "No documents found" message
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
</style>


<!-- <body class="bg-gray-50 min-h-screen"> -->
<!-- <div class="container mx-auto px-4 py-8"> -->
<!-- Header Section -->
<div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 gap-4">
    <div>
        <h2 class="text-black md:text-2xl font-bold">DOCUMENT REQUESTS MANAGEMENT</h2>
        <!-- <p class="text-black">This section is where documents are inspected for maintenance and repair purposes.</p> -->
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
                <input type="text" id="searchInput"
                    class="search-input w-full pl-10 pr-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-200"
                    placeholder="Search by Property No or Equipment...">
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="flex items-center space-x-3">
            <!-- <div class="text-right hidden md:block">
                    <p class="text-sm text-gray-600">
                        <?php if ($result && $result->num_rows > 0): ?>
                        <span class="font-semibold"><?php echo $result->num_rows; ?></span> documents found
                        <?php else: ?>
                        No documents found
                        <?php endif; ?>
                    </p>
                </div> -->

            <button type="button"
                class="action-btn bg-blue-600 hover:bg-green-500 text-white px-5 py-3 rounded-lg font-medium flex items-center transition duration-200"
                data-bs-toggle="modal" data-bs-target="#viewRequestModal">
                <i class="fas fa-eye mr-2"></i>
                View Requests
            </button>
        </div>
    </div>
</div>

<!-- Main Table -->
<div class="bg-white rounded-xl shadow-sm overflow-hidden">
    <!-- Table Header -->
    <div class="px-6 py-4 border-b border-gray-200 bg-white-50">
        <div class="flex justify-between items-center">
            <h2 class="text-lg font-semibold text-gray-800">MANAGE AND VIEW ALL REPAIR AND MAINTENANCE REQUEST</h2>
            <div class="flex items-center space-x-4">

                <span class="text-sm text-gray-600">
                    Showing <?php echo $result ? $result->num_rows : 0; ?> requests
                </span>
            </div>
        </div>
        <!-- <p>This section manages all approved documents for inspection before they are scheduled for repair and
            maintenance.</p> -->
    </div>
    <!--documents  Table -->
    <div class="overflow-auto max-h-[500px]">
        <table class="min-w-full divide-y divide-gray-200" id="documentsTable">
            <thead class="bg-gray-50 sticky top-0 z-10">
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
                        REQUESTED BY
                    </th>
                    <th scope="col"
                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Allocation
                    </th>
                    <th scope="col"
                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Date Requested
                    </th>
                    <th scope="col"
                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Actions
                    </th>
                </tr>
            </thead>
            <!-- <tbody class="bg-white divide-y divide-gray-200"> -->
            <tbody class="bg-white divide-y divide-gray-200">
                <?php if ($result && $result->num_rows > 0): ?>
                    <?php while ($row = $result->fetch_assoc()): ?>
                        <tr class="hover-row transition duration-150">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center">
                                    <div
                                        class="flex-shrink-0 h-10 w-10 rounded-lg bg-blue-50 flex items-center justify-center mr-3">
                                        <i class="fas fa-tools text-blue-600"></i>
                                    </div>
                                    <div>
                                        <div class="text-sm font-medium text-gray-900">
                                            <?= htmlspecialchars($row['category_name'] ?? 'N/A'); ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-semibold text-gray-900">
                                    <?= htmlspecialchars($row['property_no'] ?? 'N/A'); ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-900">
                                    <?= htmlspecialchars($row['officer_name'] ?? 'N/A'); ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-900">
                                    <?php
                                    $location = $row['location'] ?? 'N/A';
                                    $location = str_replace('Mamburao', 'Mamburao-Maintenance dept', $location);
                                    echo htmlspecialchars($location);
                                    ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php
                                $dateRequested = $row['date_requested'] ?? '';
                                if (!empty($dateRequested)) {
                                    echo '<div class="text-sm font-medium text-gray-900">';
                                    echo date('M d, Y', strtotime($dateRequested)); // Format: Jan 15, 2024
                                    echo '</div>';
                                    echo '<div class="text-xs text-gray-500">';
                                    echo date('h:i A', strtotime($dateRequested)); // Format: 02:30 PM
                                    echo '</div>';
                                } else {
                                    echo '<span class="text-sm text-gray-400 italic">Not set</span>';
                                }
                                ?>
                            </td>

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
                        <td colspan="5" class="px-6 py-12 text-center">
                            <div class="text-gray-500">
                                <i class="fas fa-inbox text-4xl mb-4 opacity-30"></i>
                                <h3 class="text-lg font-medium text-gray-900 mb-2">No documents found</h3>
                                <p class="text-gray-600 mb-4">
                                    <?php if (empty($userLocation)): ?>
                                        User location not set. Please contact administrator.
                                    <?php else: ?>
                                        No approved documents for <?php echo htmlspecialchars($userLocation); ?>
                                        location.
                                    <?php endif; ?>
                                </p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</div>

<!-- Modals (Keep your existing modals, just update the styling) -->
<!-- Add Document Modal -->
<div class="modal fade" id="addDocumentModal" tabindex="-1" aria-labelledby="addDocumentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <form method="POST" action="add_document.php" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken ?? ''; ?>">
                <div class="modal-header bg-green-600 text-white">
                    <h5 class="modal-title" id="addDocumentModalLabel"><i class="bi bi-plus-circle"></i> Add New
                        Document</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                        aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="card border-0 shadow-sm p-3">
                        <!-- File Upload and Pre-Repair Info -->
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Attached File (PDF/Photo)</label>
                            <input type="file" name="attached_file" class="w-full px-3 py-2 border border-gray-300 rounded-lg" accept=".pdf,image/*">
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Pre-Repair No.</label>
                                <input type="text" name="pre_repair_no"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg" required>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Property No.</label>
                                <input type="text" name="property_no"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg" required>
                            </div>
                        </div>

                        <!-- Category -->
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Category</label>
                            <select name="category_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg"
                                required>
                                <option value="">Select Category</option>
                                <?php
                                $categories = $mysqli->query("SELECT * FROM equipment_category ORDER BY category_name ASC");
                                while ($cat = $categories->fetch_assoc()):
                                    ?>
                                    <option value="<?= $cat['id']; ?>"><?= htmlspecialchars($cat['category_name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <!-- Inspector Info -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Inspector Name</label>
                                <input type="text" name="inspector_name"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Inspector
                                    Position</label>
                                <input type="text" name="inspector_position"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                            </div>
                        </div>

                        <!-- Defect, Findings, Recommendation -->
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Defect / Complaint</label>
                            <textarea name="defect" class="w-full px-3 py-2 border border-gray-300 rounded-lg"
                                rows="2"></textarea>
                        </div>
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Findings</label>
                            <textarea name="findings" class="w-full px-3 py-2 border border-gray-300 rounded-lg"
                                rows="2"></textarea>
                        </div>
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Recommendation</label>
                            <div class="flex space-x-4">
                                <label class="inline-flex items-center">
                                    <input type="radio" name="recommendation" value="For In-House Repair"
                                        class="form-radio">
                                    <span class="ml-2">For In-House Repair</span>
                                </label>
                                <label class="inline-flex items-center">
                                    <input type="radio" name="recommendation" value="For Outside Repair"
                                        class="form-radio">
                                    <span class="ml-2">For Outside Repair</span>
                                </label>
                            </div>
                        </div>

                        <!-- Materials & Parts -->
                        <h6 class="font-bold mt-3 mb-2">Materials & Parts</h6>
                        <div class="overflow-x-auto">
                            <table class="min-w-full border border-gray-300">
                                <thead class="bg-gray-100">
                                    <tr>
                                        <th class="border border-gray-300 px-4 py-2">#</th>
                                        <th class="border border-gray-300 px-4 py-2">Material/Part</th>
                                        <th class="border border-gray-300 px-4 py-2">Quantity</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php for ($i = 1; $i <= 10; $i++): ?>
                                        <tr>
                                            <td class="border border-gray-300 px-4 py-2 text-center"><?= $i ?></td>
                                            <td class="border border-gray-300 px-4 py-2">
                                                <input type="text" name="material_<?= $i ?>"
                                                    class="w-full px-2 py-1 border border-gray-300 rounded">
                                            </td>
                                            <td class="border border-gray-300 px-4 py-2">
                                                <input type="text" name="quantity_<?= $i ?>"
                                                    class="w-full px-2 py-1 border border-gray-300 rounded">
                                            </td>
                                        </tr>
                                    <?php endfor; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Signature Fields -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-6">
                            <div class="text-center">
                                <label class="block font-medium mb-2">Pre-Inspected by</label>
                                <input type="text" name="inspected_by"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg text-center">
                                <small class="text-gray-500">Inspector</small>
                            </div>
                            <div class="text-center">
                                <label class="block font-medium mb-2">Approved by PEPO</label>
                                <input type="text" name="approved_by_pepo"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg text-center">
                            </div>
                            <div class="text-center">
                                <label class="block font-medium mb-2">Witnessed by</label>
                                <input type="text" name="witnessed_by"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg text-center">
                                <small class="text-gray-500">PGDH-PACCO</small>
                            </div>
                            <div class="text-center">
                                <label class="block font-medium mb-2">Approved by GSO</label>
                                <input type="text" name="approved_by_gso"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg text-center">
                                <small class="text-gray-500">PGDH-GSO</small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-gray-50 px-4 py-3">
                    <button type="submit" name="add_document"
                        class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg font-medium">
                        <i class="bi bi-plus-circle"></i> Add Document
                    </button>
                    <button type="button"
                        class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-4 py-2 rounded-lg font-medium"
                        data-bs-dismiss="modal">
                        <i class="bi bi-x-circle"></i> Close
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- VIEW REQUEST MODAL - NOW ONLY SHOWS PENDING DOCUMENTS -->
<!-- VIEW REQUEST MODAL - PENDING DOCUMENTS -->
<div class="modal fade" id="viewRequestModal" tabindex="-1" aria-labelledby="viewRequestModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-xl">
            <!-- Modal Header -->
            <div
                class="modal-header bg-gradient-to-r from-green-600 to-blue-800 text-white p-6 border-b border-blue-700">
                <div class="flex items-center">
                    <div class="bg-white/20 rounded-lg p-3 mr-4">
                        <i class="bi bi-file-earmark-text text-2xl"></i>
                    </div>
                    <div>
                        <h5 class="modal-title text-xl font-bold" id="viewRequestModalLabel">
                            PENDING REQUESTS
                        </h5>
                        <?php if (!empty($userLocation)): ?>
                            <div class="flex items-center mt-2">
                                <span
                                    class="bg-white/20 backdrop-blur-sm px-3 py-1 rounded-full text-sm font-medium border border-white/30">
                                    <i class="bi bi-geo-alt-fill mr-2"></i>
                                    <?php echo strtoupper(htmlspecialchars($userLocation)); ?>
                                </span>
                                <span class="text-sm text-blue-100 ml-3">Manage pending requests</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white opacity-100 hover:opacity-75 transition-opacity"
                    data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <!-- Success/Error Messages -->
            <?php if (!empty($viewRequestMessage)): ?>
                <div class="px-6 pt-4">
                    <?php echo $viewRequestMessage; ?>
                </div>
            <?php endif; ?>

            <?php if (empty($userLocation)): ?>
                <!-- No Location Error -->
                <div class="p-6">
                    <div class="bg-red-50 border-l-4 border-red-500 p-4 rounded-r-lg">
                        <div class="flex items-center">
                            <div class="flex-shrink-0">
                                <i class="bi bi-exclamation-triangle-fill text-red-500 text-xl"></i>
                            </div>
                            <div class="ml-3">
                                <h3 class="text-sm font-semibold text-red-800">Access Denied</h3>
                                <div class="mt-1 text-sm text-red-700">
                                    <p>User location not found. Please contact your administrator to set your location.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            <?php else: ?>
                <!-- Modal Body -->
                <div class="modal-body p-0">
                    <!-- Search and Stats Bar -->
                    <div class="sticky top-0 bg-white border-b border-gray-200 px-6 py-4 shadow-sm z-10">
                        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                            <!-- Search Input -->
                            <div class="flex-1 w-full">
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                        <i class="bi bi-search text-gray-400"></i>
                                    </div>
                                    <input type="text"
                                        class="w-full pl-10 pr-4 py-2.5 bg-gray-50 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-200"
                                        placeholder="Search by Pre-repair # or Property No..." id="searchPendingInput">
                                </div>
                            </div>

                            <!-- Stats and Actions -->
                            <div class="flex items-center space-x-4">
                                <!-- View Archives Button -->
                                <button
                                    class="inline-flex items-center px-4 py-2.5 border border-gray-400 text-gray-800 bg-gray-200 rounded-lg hover:bg-gray-300 transition duration-200 shadow-sm"
                                    data-bs-toggle="modal" data-bs-target="#archivedModal">
                                    <i class="bi bi-archive mr-2"></i>
                                    <span>View Archives</span>
                                </button>
                                <!-- Divider -->
                                <div class="hidden md:block h-8 w-px bg-gray-300"></div>

                                <!-- Total Pending -->
                                <div class="text-right">
                                    <p class="text-xs text-gray-500 uppercase tracking-wider font-medium">TOTAL PENDING</p>
                                    <p class="text-2xl font-bold text-gray-800">
                                        <?php echo $mainResult ? $mainResult->num_rows : 0; ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Table Container with Vertical Scrollbar -->
                    <div class="overflow-auto" style="max-height: 90vh;">
                        <table class="min-w-full divide-y divide-gray-200" id="pendingRequestsTable">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col"
                                        class="px-6 py-3.5 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                        Equipment
                                    </th>
                                    <th scope="col"
                                        class="px-6 py-3.5 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                        Property No.
                                    </th>
                                    <th scope="col"
                                        class="px-6 py-3.5 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                        Requested By
                                    </th>
                                    <th scope="col"
                                        class="px-6 py-3.5 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                        Date Requested
                                    </th>
                                    <th scope="col"
                                        class="px-6 py-3.5 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php if ($mainResult && $mainResult->num_rows > 0): ?>

                                    <?php while ($row = $mainResult->fetch_assoc()): ?>
                                        <tr class="hover:bg-blue-50/50 transition duration-150">
                                            <!-- Equipment -->
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="flex items-center">
                                                    <div
                                                        class="flex-shrink-0 h-9 w-9 rounded-lg bg-blue-100 flex items-center justify-center mr-3">
                                                        <i class="bi bi-tools text-blue-600"></i>
                                                    </div>
                                                    <div>
                                                        <div class="text-sm font-semibold text-gray-900 truncate max-w-[180px]"
                                                            title="<?php echo htmlspecialchars($row['category_name'] ?? 'N/A'); ?>">
                                                            <?php echo htmlspecialchars($row['category_name'] ?? 'N/A'); ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>

                                            <!-- Property No -->
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div
                                                    class="text-sm text-gray-900 font-mono bg-gray-50 px-3 py-1.5 rounded-md border border-gray-200">
                                                    <?php echo htmlspecialchars($row['property_no']); ?>
                                                </div>
                                            </td>

                                            <!-- Requested By -->
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="text-sm text-gray-900">
                                                    <?php echo htmlspecialchars($row['officer_name'] ?? 'N/A'); ?>
                                                </div>
                                            </td>

                                            <!-- Date -->
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="text-sm">
                                                    <div class="font-medium text-gray-900">
                                                        <?php echo date('M d, Y', strtotime($row['date_requested'])); ?>
                                                    </div>
                                                    <div class="text-gray-500 text-xs">
                                                        <?php echo date('h:i A', strtotime($row['date_requested'])); ?>
                                                    </div>
                                                </div>
                                            </td>

                                            <!-- Actions -->
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="flex items-center space-x-2">
                                                    <!-- View Button -->
                                                    <button
                                                        class="view-document-btn inline-flex items-center px-3 py-2 border border-blue-300 text-blue-700 bg-blue-50 rounded-lg hover:bg-blue-100 transition duration-200"
                                                        data-id="<?php echo $row['id']; ?>"
                                                        data-pre-repair-no="<?php echo htmlspecialchars($row['pre_repair_no']); ?>"
                                                        data-view-mode="pending" title="View Full Details">
                                                        <i class="bi bi-eye-fill mr-1.5"></i>
                                                        View
                                                    </button>

                                                    <!-- Approve Button -->
                                                    <form method="POST" class="inline" data-confirm="Approve this request?">
                                                        <input type="hidden" name="csrf_token"
                                                            value="<?php echo $csrfToken ?? ''; ?>">
                                                        <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                                                        <button type="submit" name="action" value="approve"
                                                            class="inline-flex items-center px-3 py-2 border border-green-500 bg-green-500 text-white rounded-lg hover:bg-green-600 transition duration-200"
                                                            title="Approve">
                                                            <i class="bi bi-check-lg mr-1.5"></i>
                                                            Approve
                                                        </button>
                                                    </form>

                                                    <!-- Archive Button -->
                                                    <form method="POST" class="inline" data-confirm="Archive this request?">
                                                        <input type="hidden" name="csrf_token"
                                                            value="<?php echo $csrfToken ?? ''; ?>">
                                                        <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                                                        <button type="submit" name="action" value="archive"
                                                            class="inline-flex items-center px-3 py-2 border border-gray-300 text-gray-700 bg-white rounded-lg hover:bg-gray-50 transition duration-200"
                                                            title="Archive">
                                                            <i class="bi bi-archive mr-1.5"></i>
                                                            Archive
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>


                                <?php else: ?>
                                    <!-- Empty State -->
                                    <tr>
                                        <td colspan="4" class="px-6 py-16 text-center">
                                            <div class="inline-flex flex-col items-center">
                                                <div
                                                    class="w-20 h-20 rounded-full bg-gray-100 flex items-center justify-center mb-4">
                                                    <i class="bi bi-inbox text-3xl text-gray-400"></i>
                                                </div>
                                                <h3 class="text-lg font-semibold text-gray-900 mb-2">No pending requests</h3>
                                                <p class="text-gray-600 max-w-md mb-4">
                                                    No pending requests found for
                                                    <span
                                                        class="font-semibold text-blue-600"><?php echo htmlspecialchars($userLocation); ?></span>
                                                    location.
                                                </p>
                                                <p class="text-sm text-gray-500">
                                                    New requests will appear here automatically.
                                                </p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Table Footer -->
                    <?php if ($mainResult && $mainResult->num_rows > 0): ?>
                        <div class="bg-gray-50 px-6 py-3 border-t border-gray-200">
                            <div class="flex items-center justify-between text-sm text-gray-600">
                                <div class="flex items-center">
                                    <i class="bi bi-info-circle mr-2"></i>
                                    <span>Showing <?php echo $mainResult->num_rows; ?> pending requests</span>
                                </div>
                                <div>
                                    <span class="font-medium">Location: </span>
                                    <span class="text-blue-600"><?php echo htmlspecialchars($userLocation); ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

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
                <button type="button" class="btn btn-sm btn-success px-4 rounded-lg fw-semibold"
                    onclick="approveFromModal()">
                    <i class="bi bi-check-lg"></i> Approve
                </button>
                <button type="button" class="btn btn-sm btn-primary px-4 rounded-lg fw-semibold"
                    onclick="saveDocumentFromModal()">
                    <i class="bi bi-save"></i> Save Changes
                </button>
                <button type="button" class="btn btn-sm btn-dark px-4 rounded-lg fw-semibold"
                    onclick="addToMaintenanceFromModal()">
                    <i class="bi bi-tools"></i> Add to Maintenance
                </button>
                <button type="button" class="btn btn-sm btn-danger px-4 rounded-lg fw-semibold"
                    onclick="archiveDocumentFromModal()">
                    <i class="bi bi-archive"></i> Archive
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ARCHIVED MODAL -->
<div class="modal fade" id="archivedModal" tabindex="-1" aria-labelledby="archivedModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-gray-700 text-white">
                <h5 class="modal-title"><i class="bi bi-archive me-2"></i>Archived Requests
                    (<?php echo !empty($userLocation) ? htmlspecialchars($userLocation) : 'No Location'; ?>)</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div class="p-3 border-b">
                    <input type="text" class="w-full px-3 py-2 border border-gray-300 rounded-lg"
                        placeholder="Search archived requests..." id="searchArchivedInput">
                </div>
                <div class="overflow-auto max-h-96">
                    <table class="min-w-full divide-y divide-gray-200" id="archivedRequestsTable">
                        <thead class="bg-gray-100">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                    Equipment</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Property
                                    No.</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Requested By</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date
                                    Requested</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status
                                </th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Action
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php
                            if ($archivedResult && $archivedResult->num_rows > 0) {
                                while ($row = $archivedResult->fetch_assoc()) {
                                    echo "<tr>
                                                <td class='px-4 py-3'>" . htmlspecialchars($row['category_name'] ?? 'N/A') . "</td>
                                                <td class='px-4 py-3'>" . htmlspecialchars($row['property_no']) . "</td>
                                                <td class='px-4 py-3'>" . htmlspecialchars($row['officer_name'] ?? 'N/A') . "</td>
                                                <td class='px-4 py-3'>" . date('M d, Y', strtotime($row['date_requested'])) . "</td>
                                                <td class='px-4 py-3'><span class='bg-gray-200 text-gray-800 px-2 py-1 rounded text-xs'>" . htmlspecialchars($row['status']) . "</span></td>
                                                <td class='px-4 py-3'>
                                                    <form method='POST' data-confirm='Restore this request to Pending?'>
                                                        <input type='hidden' name='csrf_token' value='" . ($csrfToken ?? '') . "'>
                                                        <input type='hidden' name='id' value='" . $row['id'] . "'>
                                                        <button type='submit' name='action' value='unarchive' class='bg-yellow-500 hover:bg-yellow-600 text-white px-3 py-1 rounded text-sm flex items-center'>
                                                            <i class='bi bi-arrow-counterclockwise mr-1'></i> Restore
                                                        </button>
                                                    </form>
                                                </td>
                                              </tr>";
                                }
                            } else {
                                echo "<tr><td colspan='6' class='px-4 py-8 text-center text-gray-500'>No archived records for this location.</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer bg-gray-50 px-4 py-3">
                <button type="button"
                    class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-4 py-2 rounded-lg font-medium"
                    data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Custom Confirm/Alert Modal -->
<div id="customDialog" class="fixed inset-0 z-[9999] hidden items-center justify-center" style="background:rgba(0,0,0,0.5);backdrop-filter:blur(4px);">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md mx-4 overflow-hidden animate-fade-in-up">
        <div class="px-6 py-5 border-b border-gray-100 flex items-center gap-3" id="dialogHeader">
            <div id="dialogIcon" class="w-10 h-10 rounded-full flex items-center justify-center text-xl"></div>
            <h3 id="dialogTitle" class="text-lg font-bold text-gray-900"></h3>
        </div>
        <div class="px-6 py-5">
            <p id="dialogMessage" class="text-gray-600 text-sm leading-relaxed"></p>
            <ul id="dialogErrorList" class="mt-3 space-y-1 hidden"></ul>
        </div>
        <div class="px-6 py-4 bg-gray-50 border-t border-gray-100 flex justify-end gap-3" id="dialogFooter">
            <button id="dialogCancelBtn" class="px-5 py-2.5 text-sm font-semibold text-gray-700 bg-gray-100 rounded-xl hover:bg-gray-200 transition hidden">Cancel</button>
            <button id="dialogConfirmBtn" class="px-5 py-2.5 text-sm font-semibold text-white bg-emerald-500 rounded-xl hover:bg-emerald-600 transition">OK</button>
        </div>
    </div>
</div>
<style>
@keyframes fadeInUp { from { opacity:0; transform:translateY(20px); } to { opacity:1; transform:translateY(0); } }
.animate-fade-in-up { animation: fadeInUp 0.25s ease-out; }
</style>

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

<?php
// ✅ Close prepared statements
if (isset($stmtMain))
    $stmtMain->close();
if (isset($stmtArchived))
    $stmtArchived->close();
if (isset($stmtMainApproved))
    $stmtMainApproved->close();

// ✅ Close database connection
$mysqli->close();
?>

<script>
    // Global function to load document view - must be accessible from onclick
    let currentViewDocId = null;
    let hasUnsavedChanges = false;

    function cleanupBackdrops() {
        document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('overflow');
        document.body.style.removeProperty('padding-right');
    }

    async function loadDocumentView(docId, source = 'manage', supplyId = null) {
        currentViewDocId = docId;
        hasUnsavedChanges = false; // Reset on load

        const modalElement = document.getElementById('viewDocumentModal');
        const modalContent = document.getElementById('viewDocumentContent');
        const footerButtons = document.querySelectorAll('#viewDocumentModal .modal-footer .btn');

        // Reset all buttons first
        footerButtons.forEach(btn => btn.style.display = '');

        // If source is 'view' (from PENDING REQUESTS), show only Approve and Archive
        if (source === 'view') {
            footerButtons.forEach(btn => {
                if (btn.textContent.includes('Save Changes') || btn.textContent.includes('Add to Maintenance')) {
                    btn.style.display = 'none';
                }
            });
        }
        // If source is 'manage' (main table), hide Approve button
        else if (source === 'manage') {
            footerButtons.forEach(btn => {
                if (btn.textContent.includes('Approve')) {
                    btn.style.display = 'none';
                }
            });
        }

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
            // Only use edit=true for 'manage' source (main table)
            let url = source === 'manage' ? `get_document_simple.php?id=${docId}&edit=true` : `get_document_simple.php?id=${docId}`;
            if (supplyId) {
                url += `&supply_id=${supplyId}`;
            }

            const response = await fetch(url);
            if (!response.ok) throw new Error('Failed to fetch document');

            const html = await response.text();
            modalContent.innerHTML = html;

            // Track changes in the modal content
            const form = modalContent.querySelector('form');
            if (form) {
                form.addEventListener('input', () => {
                    hasUnsavedChanges = true;
                    console.log('Unsaved changes detected');
                });
                form.addEventListener('change', () => {
                    hasUnsavedChanges = true;
                    console.log('Unsaved changes detected (change)');
                });
            }

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

    // Custom Dialog System
    function showDialog(type, message, onOk) {
        const dialog = document.getElementById('customDialog');
        document.getElementById('dialogMessage').textContent = message;
        document.getElementById('dialogErrorList').classList.add('hidden');
        document.getElementById('dialogCancelBtn').classList.add('hidden');
        document.getElementById('dialogConfirmBtn').textContent = 'OK';
        document.getElementById('dialogConfirmBtn').className = 'px-5 py-2.5 text-sm font-semibold text-white rounded-xl transition';

        const header = document.getElementById('dialogHeader');
        const icon = document.getElementById('dialogIcon');
        const title = document.getElementById('dialogTitle');

        const configs = {
            success: { icon: 'bi-check-circle-fill', bg: 'bg-emerald-100 text-emerald-600', title: 'Success', btn: 'bg-emerald-500 hover:bg-emerald-600' },
            error: { icon: 'bi-x-circle-fill', bg: 'bg-red-100 text-red-600', title: 'Error', btn: 'bg-red-500 hover:bg-red-600' },
            warning: { icon: 'bi-exclamation-circle-fill', bg: 'bg-amber-100 text-amber-600', title: 'Warning', btn: 'bg-amber-500 hover:bg-amber-600' },
            info: { icon: 'bi-info-circle-fill', bg: 'bg-blue-100 text-blue-600', title: 'Notice', btn: 'bg-blue-500 hover:bg-blue-600' }
        };
        const cfg = configs[type] || configs.info;
        icon.className = `w-10 h-10 rounded-full flex items-center justify-center text-xl ${cfg.bg}`;
        icon.innerHTML = `<i class="bi ${cfg.icon}"></i>`;
        title.textContent = cfg.title;
        document.getElementById('dialogConfirmBtn').className = `px-5 py-2.5 text-sm font-semibold text-white rounded-xl transition ${cfg.btn}`;

        dialog.classList.remove('hidden');
        dialog.classList.add('flex');
        const newBtn = document.getElementById('dialogConfirmBtn').cloneNode(true);
        document.getElementById('dialogConfirmBtn').replaceWith(newBtn);
        newBtn.addEventListener('click', function handler() {
            dialog.classList.add('hidden');
            dialog.classList.remove('flex');
            if (onOk) onOk();
        });
    }

    function showConfirm(message, onConfirm) {
        const dialog = document.getElementById('customDialog');
        document.getElementById('dialogMessage').textContent = message;
        document.getElementById('dialogErrorList').classList.add('hidden');
        document.getElementById('dialogCancelBtn').classList.remove('hidden');

        const icon = document.getElementById('dialogIcon');
        icon.className = 'w-10 h-10 rounded-full flex items-center justify-center text-xl bg-amber-100 text-amber-600';
        icon.innerHTML = '<i class="bi bi-question-circle-fill"></i>';
        document.getElementById('dialogTitle').textContent = 'Confirm';
        document.getElementById('dialogConfirmBtn').textContent = 'Continue';
        document.getElementById('dialogConfirmBtn').className = 'px-5 py-2.5 text-sm font-semibold text-white bg-emerald-500 rounded-xl hover:bg-emerald-600 transition';

        dialog.classList.remove('hidden');
        dialog.classList.add('flex');

        const confirmBtn = document.getElementById('dialogConfirmBtn').cloneNode(true);
        document.getElementById('dialogConfirmBtn').replaceWith(confirmBtn);
        const cancelBtn = document.getElementById('dialogCancelBtn').cloneNode(true);
        document.getElementById('dialogCancelBtn').replaceWith(cancelBtn);

        confirmBtn.addEventListener('click', function handler() {
            dialog.classList.add('hidden');
            dialog.classList.remove('flex');
            if (onConfirm) onConfirm();
        });
        cancelBtn.addEventListener('click', function() {
            dialog.classList.add('hidden');
            dialog.classList.remove('flex');
        });
    }

    function showValidationErrors(errors) {
        const dialog = document.getElementById('customDialog');
        document.getElementById('dialogTitle').textContent = 'Missing Information';
        const icon = document.getElementById('dialogIcon');
        icon.className = 'w-10 h-10 rounded-full flex items-center justify-center text-xl bg-red-100 text-red-600';
        icon.innerHTML = '<i class="bi bi-exclamation-triangle-fill"></i>';
        document.getElementById('dialogMessage').textContent = 'Please complete the following before adding to maintenance:';
        document.getElementById('dialogCancelBtn').classList.add('hidden');
        document.getElementById('dialogConfirmBtn').textContent = 'Got it';
        document.getElementById('dialogConfirmBtn').className = 'px-5 py-2.5 text-sm font-semibold text-white bg-red-500 rounded-xl hover:bg-red-600 transition';

        const list = document.getElementById('dialogErrorList');
        list.innerHTML = errors.map(e => `<li class="flex items-start gap-2 text-sm text-red-700"><i class="bi bi-dot text-red-500 mt-0.5"></i>${e}</li>`).join('');
        list.classList.remove('hidden');

        dialog.classList.remove('hidden');
        dialog.classList.add('flex');
        const newBtn = document.getElementById('dialogConfirmBtn').cloneNode(true);
        document.getElementById('dialogConfirmBtn').replaceWith(newBtn);
        newBtn.addEventListener('click', function() {
            dialog.classList.add('hidden');
            dialog.classList.remove('flex');
        });
    }

    function addToMaintenanceFromModal() {
        if (!currentViewDocId) {
            showDialog('error', 'No document selected');
            return;
        }

        if (hasUnsavedChanges) {
            showDialog('warning', 'You have unsaved changes. Please click "Save Changes" before adding to maintenance.');
            return;
        }

        // Scope validation to the specific modal content
        const container = document.getElementById('viewDocumentContent');
        if (!container) return;

        // Validation logic
        const docMeta = container.querySelector('#documentMeta');
        const attachedFile = docMeta ? docMeta.dataset.attachedFilePath : '';
        const preRepairNo = container.querySelector('input[name="pre_repair_no"]')?.value?.trim() || '';
        const defect = container.querySelector('textarea[name="defect"]')?.value?.trim() || '';
        const findings = container.querySelector('textarea[name="findings"]')?.value?.trim() || '';
        const recommendation = container.querySelector('input[name="recommendation"]:checked')?.value || '';
        
        // Check materials - at least one material AND its quantity must have a value
        let hasMaterial = false;
        for (let i = 1; i <= 10; i++) {
            const material = container.querySelector(`input[name="material_${i}"]`)?.value?.trim();
            const quantity = container.querySelector(`input[name="quantity_${i}"]`)?.value?.trim();
            if (material && quantity) {
                hasMaterial = true;
                break;
            }
        }

        let errors = [];
        if (!attachedFile || attachedFile === '') errors.push('Attached File (Missing PDF/Photo proof)');
        if (!preRepairNo) errors.push('Pre-Repair No.');
        if (!defect) errors.push('Defect/Complaint');
        if (!findings) errors.push('Findings');
        if (!recommendation) errors.push('Recommendation (Select In-House or Outside Repair)');
        if (!hasMaterial) errors.push('At least one Material/Part WITH its Quantity');

        if (errors.length > 0) {
            showValidationErrors(errors);
            return;
        }

        showConfirm('Have you completed the document process? Proceed to add to maintenance?', function() {
            const formData = new FormData();
            formData.append('add_to_maintenance', '1');
            formData.append('id', currentViewDocId);

            const submitBtn = document.querySelector('#viewDocumentModal button[onclick="addToMaintenanceFromModal()"]');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Processing...';
            }

            fetch('update_document_status.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showDialog('success', 'Document added to maintenance successfully!', function() {
                            const modal = bootstrap.Modal.getInstance(document.getElementById('viewDocumentModal'));
                            if (modal) modal.hide();
                            location.reload();
                        });
                    } else {
                        showDialog('error', 'Error: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showDialog('error', 'An error occurred. Please try again.');
                })
                .finally(() => {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = '<i class="bi bi-tools"></i> Add to Maintenance';
                    }
                });
        });
    }

    function approveFromModal() {
        if (!currentViewDocId) {
            showDialog('error', 'No document selected');
            return;
        }

        showConfirm('Are you sure you want to approve this document?', function() {
            const formData = new FormData();
            formData.append('approve', '1');
            formData.append('id', currentViewDocId);

            const submitBtn = document.querySelector('#viewDocumentModal button[onclick="approveFromModal()"]');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Approving...';
            }

            fetch('update_document_status.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showDialog('success', 'Document approved successfully!', function() {
                            const modal = bootstrap.Modal.getInstance(document.getElementById('viewDocumentModal'));
                            if (modal) modal.hide();
                            location.reload();
                        });
                    } else {
                        showDialog('error', 'Error: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showDialog('error', 'An error occurred. Please try again.');
                })
                .finally(() => {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = '<i class="bi bi-check-lg"></i> Approve';
                    }
                });
        });
    }

    function archiveDocumentFromModal() {
        if (!currentViewDocId) {
            showDialog('error', 'No document selected');
            return;
        }

        showConfirm('Are you sure you want to archive this document?', function() {
            const formData = new FormData();
            formData.append('archive', '1');
            formData.append('id', currentViewDocId);

            const submitBtn = document.querySelector('#viewDocumentModal button[onclick="archiveDocumentFromModal()"]');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Archiving...';
            }

            fetch('update_document_status.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showDialog('success', 'Document archived successfully!', function() {
                            const modal = bootstrap.Modal.getInstance(document.getElementById('viewDocumentModal'));
                            if (modal) modal.hide();
                            location.reload();
                        });
                    } else {
                        showDialog('error', 'Error: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showDialog('error', 'An error occurred. Please try again.');
                })
                .finally(() => {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = '<i class="bi bi-archive"></i> Archive';
                    }
                });
        });
    }

    // Search and other initialization code inside DOMContentLoaded
    document.addEventListener('DOMContentLoaded', function () {
        // View document button in PENDING REQUESTS modal
        document.querySelectorAll('.view-document-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                const docId = this.getAttribute('data-id');
                const viewMode = this.getAttribute('data-view-mode');
                loadDocumentView(docId, viewMode === 'pending' ? 'view' : 'manage');
            });
        });

        // Search functionality for main table
        const searchInput = document.getElementById('searchInput');

        if (searchInput) {
            searchInput.addEventListener('keyup', function (e) {
                const searchTerm = searchInput.value.toLowerCase().trim();
                const rows = document.querySelectorAll('#documentsTable tbody tr');

                rows.forEach(row => {
                    const cells = row.querySelectorAll('td');
                    if (cells.length >= 3) {
                        const equipment = cells[0].textContent.toLowerCase();
                        const propertyNo = cells[1].textContent.toLowerCase();
                        const preRepairNo = cells[2].textContent.toLowerCase();

                        if (equipment.includes(searchTerm) ||
                            propertyNo.includes(searchTerm) ||
                            preRepairNo.includes(searchTerm)) {
                            row.style.display = '';
                        } else {
                            row.style.display = 'none';
                        }
                    }
                });
            });
        }

        // Search functionality for PENDING requests
        const searchPendingInput = document.getElementById('searchPendingInput');
        if (searchPendingInput) {
            searchPendingInput.addEventListener('keyup', function () {
                const searchTerm = this.value.toLowerCase().trim();
                const rows = document.querySelectorAll('#pendingRequestsTable tbody tr');

                rows.forEach(row => {
                    const cells = row.querySelectorAll('td');
                    if (cells.length >= 3) { // Changed from 4 to 3
                        const preRepairNo = cells[0].textContent.toLowerCase();
                        const propertyNo = cells[1].textContent
                            .toLowerCase(); // Changed from cells[2] to cells[1]

                        if (preRepairNo.includes(searchTerm) ||
                            propertyNo.includes(searchTerm)) {
                            row.style.display = '';
                        } else {
                            row.style.display = 'none';
                        }
                    }
                });
            });
        }

        // Search functionality for ARCHIVED requests
        const searchArchivedInput = document.getElementById('searchArchivedInput');
        if (searchArchivedInput) {
            searchArchivedInput.addEventListener('keyup', function () {
                const searchTerm = this.value.toLowerCase().trim();
                const rows = document.querySelectorAll('#archivedRequestsTable tbody tr');

                rows.forEach(row => {
                    const cells = row.querySelectorAll('td');
                    if (cells.length >= 2) {
                        const preRepairNo = cells[0].textContent.toLowerCase();
                        const propertyNo = cells[1].textContent.toLowerCase();

                        if (preRepairNo.includes(searchTerm) ||
                            propertyNo.includes(searchTerm)) {
                            row.style.display = '';
                        } else {
                            row.style.display = 'none';
                        }
                    }
                });
            });
        }

        // Handle archived modal closing
        const archivedModal = document.getElementById('archivedModal');
        if (archivedModal) {
            archivedModal.addEventListener('hidden.bs.modal', function () {
                const viewRequestModal = new bootstrap.Modal(document.getElementById(
                    'viewRequestModal'));
                viewRequestModal.show();
            });
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

        // Custom confirm for forms with data-confirm attribute
        document.querySelectorAll('form[data-confirm]').forEach(form => {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                const msg = this.getAttribute('data-confirm');
                showConfirm(msg, function() {
                    form.submit();
                });
            });
        });
    });
</script>