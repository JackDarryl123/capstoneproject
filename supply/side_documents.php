<?php
// Handle direct access (when form submits to this file)
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/session_helper.php';
require_once __DIR__ . '/../includes/mail_helper.php';
start_user_session();
require_role('supply');

$userId = $_SESSION['user_id'] ?? null;
$userRole = $_SESSION['role'] ?? null;
// Generate CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// ALWAYS fetch location from database to ensure it's correct
$userLocation = null;
if (!empty($userId)) {
    $query = $mysqli->prepare("SELECT location FROM users WHERE id = ?");
    $query->bind_param("i", $userId);
    $query->execute();
    $result = $query->get_result();

    if ($row = $result->fetch_assoc()) {
        $userLocation = $row['location'];
        $_SESSION['location'] = $row['location'];
    }

    $query->close();
}

$isLocationValid = !empty($userLocation);
if (isset($_POST['action']) && isset($_POST['id'])) {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['view_request_message'] = "<div class='alert alert-danger m-3 mt-0'>Invalid CSRF token. Please try again.</div>";
        header("Location: supply_dashboard.php?view=documents");
        exit();
    }

    $id = intval($_POST['id']);
    $action = $_POST['action'];
    $status = '';

    if ($action === 'approve') {
        $status = 'approved';
    } elseif ($action === 'comply') {
        $status = 'complied';
    } elseif ($action === 'archive') {
        $status = 'archived'; // Assuming archive means received or finalized
    } elseif ($action === 'unarchive') {
        $status = 'pending';
    }

    if ($status !== '') {
        // Handle different actions with specific timestamp updates
        if ($action === 'approve') {
            $update = $mysqli->prepare("UPDATE supply_requests SET status = ?, approved_at = NOW() WHERE id = ? AND LOWER(supply_location) = LOWER(?)");
            $update->bind_param('sis', $status, $id, $userLocation);
        } elseif ($action === 'comply') {
            $update = $mysqli->prepare("UPDATE supply_requests SET status = ?, complied_at = NOW() WHERE id = ? AND LOWER(supply_location) = LOWER(?)");
            $update->bind_param('sis', $status, $id, $userLocation);
        } elseif ($action === 'archive') {
            // If archive represents 'received' status in your workflow
            $update = $mysqli->prepare("UPDATE supply_requests SET status = ?, received_at = NOW() WHERE id = ? AND LOWER(supply_location) = LOWER(?)");
            $update->bind_param('sis', $status, $id, $userLocation);
        } else {
            $update = $mysqli->prepare("UPDATE supply_requests SET status = ? WHERE id = ? AND LOWER(supply_location) = LOWER(?)");
            $update->bind_param('sis', $status, $id, $userLocation);
        }

        if ($update->execute()) {
            // Send email notification for approved and complied status
            if ($status === 'approved' || $status === 'complied') {
                sendStatusUpdateEmail($id, $status, $mysqli);
            }
            $_SESSION['view_request_message'] = "<div class='alert alert-success m-3 mt-0'>Request #$id has been successfully marked as <strong>$status</strong>.</div>";
        } else {
            $_SESSION['view_request_message'] = "<div class='alert alert-danger m-3 mt-0'>Failed to update request.</div>";
        }
        $update->close();

        $redirect_url = 'supply_dashboard.php?view=documents';
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

if ($isLocationValid) {

    // Active requests query - ONLY PENDING status - Filtered by supply_location (case-insensitive)
    $stmtMain = $mysqli->prepare("
    SELECT id, document_id, pre_repair_no, property_no, requested_by, admin_location, status, updated_at
    FROM supply_requests
    WHERE status = 'pending' 
    AND LOWER(supply_location) = LOWER(?)
    ORDER BY created_at ASC
");
    $stmtMain->bind_param("s", $userLocation);
    $stmtMain->execute();
    $mainResult = $stmtMain->get_result();

    // Archived requests query (Optional: if you want to see archived items)
    $stmtArchived = $mysqli->prepare("
    SELECT id, document_id, pre_repair_no, property_no, requested_by, admin_location, status, updated_at
    FROM supply_requests
    WHERE status = 'archived' 
    AND LOWER(supply_location) = LOWER(?)
    ORDER BY updated_at ASC
");
    $stmtArchived->bind_param("s", $userLocation);
    $stmtArchived->execute();
    $archivedResult = $stmtArchived->get_result();

}

// ✅ Show only Approved supply requests for main table
$validStatuses = [
    "approved" => ["label" => "Approved", "class" => "bg-green-100 text-green-800"],
];

// ✅ Query only Approved supply requests for main table FILTERED BY USER'S LOCATION
$result = null;
$stmtMainApproved = null;

if ($isLocationValid) {
    // Query supply_requests for approved and complied requests filtered by supply_location
    $sql = "
    SELECT 
        id, 
        document_id,
        pre_repair_no, 
        property_no, 
        requested_by, 
        admin_location, 
        status,
        updated_at,
        complied_at,
        received_at
    FROM supply_requests
    WHERE status IN ('approved', 'complied', 'received')
    AND LOWER(supply_location) = LOWER(?)
    ORDER BY 
        CASE 
            WHEN status = 'received' THEN received_at 
            WHEN status = 'complied' THEN complied_at 
            ELSE updated_at 
        END DESC
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

<!-- Header Section -->
<div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 gap-4">
    <div>
        <h2 class="text-gray-700 md:text-2xl font-bold">MANAGE AND VIEW ALL APPROVED SUPPLY REQUESTS</h2>
        <p>This section is where approved supply requests are managed and tracked.</p>
    </div>

    <?php if ($isLocationValid): ?>
        <div class="flex items-center bg-white rounded-lg shadow-sm px-4 py-3 border border-gray-200">
            <div class="w-10 h-10 rounded-full bg-blue-50 flex items-center justify-center mr-3">
                <i class="bi bi-geo-alt text-blue-600"></i>
            </div>
            <div>
                <p class="text-xs text-gray-500 uppercase tracking-wider">SUPPLY DEPARTMENT</p>
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
                    placeholder="Search by Pre-Repair No, Property No, or Requested By...">
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="flex items-center space-x-3">
            <!-- Status Filter Dropdown -->
            <select id="statusFilter" class="px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-200 bg-white">
                <option value="all">All Status</option>
                <option value="approved" selected>Approved</option>
                <option value="complied">Complied</option>
                <option value="received">Received</option>
            </select>
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
            <h2 class="text-lg font-semibold text-gray-800">SUPPLY REQUEST MANAGEMENT</h2>
            <div class="flex items-center space-x-4">

                <span class="text-sm text-gray-600">
                    Showing <?php echo $result ? $result->num_rows : 0; ?> requests
                </span>
            </div>
        </div>
        <p>This section manages all approved supply requests for processing and delivery tracking.</p>
    </div>
    <!--documents  Table -->
    <div class="overflow-auto max-h-[500px]">
        <table class="min-w-full divide-y divide-gray-200" id="documentsTable">
            <thead class="bg-gray-50 sticky top-0 z-10">
                <tr>
                    <th scope="col"
                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Pre-Repair No
                    </th>
                    <th scope="col"
                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Property No
                    </th>
                    <th scope="col"
                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Requested By
                    </th>
                    <th scope="col"
                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Maintenance Dept
                    </th>
                    <th scope="col"
                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Status
                    </th>
                    <th scope="col"
                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Date Updated
                    </th>
                    <th scope="col"
                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Actions
                    </th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php if ($result && $result->num_rows > 0): ?>
                    <?php while ($row = $result->fetch_assoc()): ?>
                        <tr class="hover-row transition duration-150">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-semibold text-gray-900">
                                    <?= htmlspecialchars($row['pre_repair_no'] ?? 'N/A'); ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-semibold text-gray-900">
                                    <?= htmlspecialchars($row['property_no'] ?? 'N/A'); ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center">
                                    <div
                                        class="flex-shrink-0 h-8 w-8 rounded-full bg-indigo-100 flex items-center justify-center text-indigo-600 text-xs font-bold mr-2">
                                        <?php
                                        $name = $row['requested_by'] ?? '?';
                                        echo strtoupper(substr($name, 0, 1));
                                        ?>
                                    </div>
                                    <div class="text-sm font-medium text-gray-900">
                                        <?= htmlspecialchars($row['requested_by'] ?? 'N/A'); ?>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-600">
                                    <?= htmlspecialchars($row['admin_location'] ?? 'N/A'); ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php
                                $status = $row['status'] ?? '';
                                $statusClass = '';
                                if ($status === 'received') {
                                    $statusClass = 'bg-blue-100 text-blue-800';
                                } elseif ($status === 'complied') {
                                    $statusClass = 'bg-purple-100 text-purple-800';
                                } else {
                                    $statusClass = 'bg-green-100 text-green-800';
                                }
                                ?>
                                <span data-status="<?= htmlspecialchars($status); ?>"
                                    class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $statusClass; ?>">
                                    <?= htmlspecialchars(ucfirst($status ?? 'N/A')); ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php
                                $status = $row['status'] ?? '';
                                $displayDate = '';
                                if ($status === 'received' && !empty($row['received_at'])) {
                                    $displayDate = $row['received_at'];
                                } elseif ($status === 'complied' && !empty($row['complied_at'])) {
                                    $displayDate = $row['complied_at'];
                                } else {
                                    $displayDate = $row['updated_at'] ?? '';
                                }
                                if (!empty($displayDate)): ?>
                                    <div class="text-sm font-medium text-gray-900">
                                        <?= date('M d, Y', strtotime($displayDate)); ?>
                                    </div>
                                    <div class="text-xs text-gray-500">
                                        <?= date('h:i A', strtotime($displayDate)); ?>
                                    </div>
                                <?php else: ?>
                                    <span class="text-sm text-gray-400 italic">Not set</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                <div class="flex items-center space-x-2">
                                    <button type="button"
                                        class="view-document-btn inline-flex items-center px-3 py-2 border border-blue-300 text-blue-700 bg-blue-50 rounded-lg hover:bg-blue-100 transition duration-200"
                                        data-document-id="<?= $row['document_id']; ?>"
                                        data-supply-request-id="<?= $row['id']; ?>" title="View Document">
                                        <i class="fas fa-eye mr-1"></i>
                                        VIEW
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" class="px-6 py-12 text-center">
                            <div class="text-gray-500">
                                <i class="fas fa-inbox text-4xl mb-4 opacity-30"></i>
                                <h3 class="text-lg font-medium text-gray-900 mb-2">No approved/complied/received requests
                                    found</h3>
                                <p class="text-gray-600 mb-4">
                                    <?php if (!$isLocationValid): ?>
                                        User location not set. Please contact administrator.
                                    <?php else: ?>
                                        No approved, complied, or received supply requests for
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
</div>
</div>

<!-- Modals (Keep your existing modals, just update the styling) -->
<!-- Add Document Modal -->
<div class="modal fade" id="addDocumentModal" tabindex="-1" aria-labelledby="addDocumentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <form method="POST" action="add_document.php">
                <div class="modal-header bg-green-600 text-white">
                    <h5 class="modal-title" id="addDocumentModalLabel"><i class="bi bi-plus-circle"></i> Add New
                        Document</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                        aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="card border-0 shadow-sm p-3">
                        <!-- Pre-Repair & Property Info -->
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
                        <?php if ($isLocationValid): ?>
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

            <?php if (!$isLocationValid): ?>
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
                            <div class="flex items-center space-x-4">
                                <button
                                    class="inline-flex items-center px-4 py-2.5 border border-gray-400 text-gray-800 bg-gray-200 rounded-lg hover:bg-gray-300 transition duration-200 shadow-sm"
                                    data-bs-toggle="modal" data-bs-target="#archivedModal">
                                    <i class="bi bi-archive mr-2"></i><span>View Archives</span>
                                </button>
                                <div class="hidden md:block h-8 w-px bg-gray-300"></div>
                                <div class="text-right">
                                    <p class="text-xs text-gray-500 uppercase tracking-wider font-medium">TOTAL PENDING</p>
                                    <p class="text-2xl font-bold text-gray-800">
                                        <?php echo $mainResult ? $mainResult->num_rows : 0; ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Table -->
                    <div class="overflow-x-auto" style="max-height: 400px;">
                        <table class="w-full divide-y divide-gray-200" id="pendingRequestsTable">
                            <thead class="bg-gray-50 sticky top-0">
                                <tr>
                                    <th class="px-3 py-2 text-left text-xs font-semibold text-gray-700 uppercase">Pre-Repair
                                        No</th>
                                    <th class="px-3 py-2 text-left text-xs font-semibold text-gray-700 uppercase">Property
                                        No</th>
                                    <th class="px-3 py-2 text-left text-xs font-semibold text-gray-700 uppercase">Requested
                                        By</th>
                                    <th class="px-3 py-2 text-left text-xs font-semibold text-gray-700 uppercase">Location
                                    </th>
                                    <th class="px-3 py-2 text-left text-xs font-semibold text-gray-700 uppercase">Updated
                                    </th>
                                    <th class="px-3 py-2 text-center text-xs font-semibold text-gray-700 uppercase">Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php if ($mainResult && $mainResult->num_rows > 0): ?>
                                    <?php while ($row = $mainResult->fetch_assoc()): ?>
                                        <tr class="hover:bg-blue-50/50 transition duration-150">
                                            <td class="px-3 py-2 whitespace-nowrap">
                                                <span
                                                    class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars($row['pre_repair_no']); ?></span>
                                            </td>
                                            <td class="px-3 py-2 whitespace-nowrap">
                                                <span
                                                    class="text-xs text-gray-700 font-mono bg-gray-100 px-2 py-1 rounded"><?php echo htmlspecialchars($row['property_no']); ?></span>
                                            </td>
                                            <td class="px-3 py-2 whitespace-nowrap">
                                                <div class="flex items-center">
                                                    <div
                                                        class="flex-shrink-0 h-6 w-6 rounded-full bg-indigo-100 flex items-center justify-center text-indigo-600 text-xs font-bold mr-2">
                                                        <?php echo strtoupper(substr($row['requested_by'] ?? '?', 0, 1)); ?>
                                                    </div>
                                                    <span class="text-sm text-gray-900 truncate max-w-[120px]"
                                                        title="<?php echo htmlspecialchars($row['requested_by'] ?? ''); ?>"><?php echo htmlspecialchars($row['requested_by'] ?? 'N/A'); ?></span>
                                                </div>
                                            </td>
                                            <td class="px-3 py-2 whitespace-nowrap">
                                                <span
                                                    class="text-sm text-gray-600"><?php echo htmlspecialchars($row['admin_location'] ?? 'N/A'); ?></span>
                                            </td>
                                            <td class="px-3 py-2 whitespace-nowrap">
                                                <span
                                                    class="text-xs text-gray-900"><?php echo date('M d, Y', strtotime($row['updated_at'])); ?></span>
                                            </td>
                                            <td class="px-3 py-2 whitespace-nowrap">
                                                <div class="flex items-center justify-center gap-1">
                                                    <!-- View Button - uses document_id to fetch document from documents table -->
                                                    <button
                                                        class="view-document-btn inline-flex items-center px-2 py-1.5 text-xs border border-blue-300 text-blue-700 bg-blue-50 rounded hover:bg-blue-100 transition"
                                                        data-document-id="<?php echo $row['document_id']; ?>"
                                                        data-supply-request-id="<?php echo $row['id']; ?>"
                                                        data-pre-repair-no="<?php echo htmlspecialchars($row['pre_repair_no']); ?>"
                                                        title="View Document">
                                                        <i class="bi bi-eye-fill mr-1"></i>View
                                                    </button>

                                                    <!-- Approve Button - updates supply_requests table -->
                                                    <form method="POST" action="side_documents.php" class="inline"
                                                        onsubmit="return confirm('Approve this request?');">
                                                        <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                                                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                                        <button type="submit" name="action" value="approve"
                                                            class="inline-flex items-center px-2 py-1.5 text-xs bg-green-500 text-white rounded hover:bg-green-600 transition"
                                                            title="Approve">
                                                            <i class="bi bi-check-lg mr-1"></i>Approve
                                                        </button>
                                                    </form>

                                                    <!-- Archive Button - updates supply_requests table -->
                                                    <form method="POST" action="side_documents.php" class="inline"
                                                        onsubmit="return confirm('Archive this request?');">
                                                        <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                                                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                                        <button type="submit" name="action" value="archive"
                                                            class="inline-flex items-center px-2 py-1.5 text-xs border border-gray-300 text-gray-700 bg-white rounded hover:bg-gray-50 transition"
                                                            title="Archive">
                                                            <i class="bi bi-archive mr-1"></i>Archive
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="px-6 py-12 text-center">
                                            <div class="inline-flex flex-col items-center">
                                                <div
                                                    class="w-16 h-16 rounded-full bg-gray-100 flex items-center justify-center mb-3">
                                                    <i class="bi bi-inbox text-2xl text-gray-400"></i>
                                                </div>
                                                <h3 class="text-base font-semibold text-gray-900 mb-1">No pending requests</h3>
                                                <p class="text-sm text-gray-600">No pending requests for <span
                                                        class="font-semibold text-blue-600"><?php echo htmlspecialchars($userLocation); ?></span>
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
                                <div class="flex items-center"><i class="bi bi-info-circle mr-2"></i><span>Showing
                                        <?php echo $mainResult->num_rows; ?> pending requests</span></div>
                                <div><span class="font-medium">Location: </span><span
                                        class="text-blue-600"><?php echo htmlspecialchars($userLocation); ?></span></div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- INTEGRATED DOCUMENT VIEW MODAL - Request Specification Details -->
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

            <div class="modal-body p-0" id="documentFullContent" style="background: white;">
                <!-- Content loaded via AJAX -->
            </div>

            <div class="modal-footer bg-gray-50 border-t border-gray-100 px-4 py-3" id="viewModalFooter">
                <button type="button" class="btn btn-sm btn-outline-secondary px-4 rounded-lg fw-semibold"
                    data-bs-dismiss="modal">
                    <i class="bi bi-x-circle"></i> Close
                </button>
                <button type="button" class="btn btn-sm btn-success px-4 rounded-lg fw-semibold"
                    onclick="markApprovedFromView()">
                    <i class="bi bi-check-circle"></i> Approve
                </button>
                <button type="button" class="btn btn-sm btn-primary px-4 rounded-lg fw-semibold"
                    onclick="markCompliedFromView()">
                    <i class="bi bi-check2-all"></i> Complied
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
                    (<?php echo $isLocationValid ? htmlspecialchars($userLocation) : 'No Location'; ?>)</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div class="p-3 border-b">
                    <input type="text" class="w-full px-3 py-2 border border-gray-300 rounded-lg"
                        placeholder="Search archived requests..." id="searchArchivedInput">
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200" id="archivedRequestsTable" style="width: 100%;">
                        <thead class="bg-gray-100">
                            <tr>
                                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase w-auto">
                                    Pre-Repair No.</th>
                                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase w-auto">
                                    Property No.</th>
                                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase w-auto">
                                    Requested By</th>
                                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase w-auto">Admin
                                    Location</th>
                                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase w-auto">
                                    Updated At</th>
                                <th class="px-3 py-3 text-center text-xs font-medium text-gray-500 uppercase w-24">
                                    Action</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php
                            if ($archivedResult && $archivedResult->num_rows > 0) {
                                while ($row = $archivedResult->fetch_assoc()) {
                                    echo "<tr>
                                                <td class='px-3 py-3 whitespace-nowrap'>" . htmlspecialchars($row['pre_repair_no']) . "</td>
                                                <td class='px-3 py-3 whitespace-nowrap'>" . htmlspecialchars($row['property_no'] ?? 'N/A') . "</td>
                                                <td class='px-3 py-3 whitespace-nowrap'>" . htmlspecialchars($row['requested_by'] ?? 'N/A') . "</td>
                                                <td class='px-3 py-3 whitespace-nowrap'>" . htmlspecialchars($row['admin_location'] ?? 'N/A') . "</td>
                                                <td class='px-3 py-3 whitespace-nowrap'>" . date('M d, Y', strtotime($row['updated_at'])) . "</td>
                                                <td class='px-3 py-3 text-center'>
                                                    <button type='button' class='view-document-btn inline-flex items-center px-2 py-1.5 text-xs border border-blue-300 text-blue-700 bg-blue-50 rounded hover:bg-blue-100 mr-1' data-document-id='" . $row['document_id'] . "' data-supply-request-id='" . $row['id'] . "' title='View Document'>
                                                        <i class='bi bi-eye-fill mr-1'></i>View
                                                    </button>
                                                    <form method='POST' action='side_documents.php' onsubmit='return confirm(\"Restore this request to Pending?\")' class='inline'>
                                                        <input type='hidden' name='id' value='" . $row['id'] . "'>
                                                        <input type='hidden' name='csrf_token' value='" . $csrfToken . "'>
                                                        <button type='submit' name='action' value='unarchive' class='bg-yellow-500 hover:bg-yellow-600 text-white px-2 py-1 rounded text-xs inline-flex items-center'>
                                                            <i class='bi bi-arrow-counterclockwise mr-1'></i> Restore
                                                        </button>
                                                    </form>
                                                </td>
                                              </tr>";
                                }
                            } else {
                                echo "<tr><td colspan='6' class='px-3 py-8 text-center text-gray-500'>No archived records for this location.</td></tr>";
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
if (isset($stmtMain) && $stmtMain)
    $stmtMain->close();
if (isset($stmtArchived) && $stmtArchived)
    $stmtArchived->close();
if (isset($stmtMainApproved) && $stmtMainApproved)
    $stmtMainApproved->close();

// ✅ Close database connection
$mysqli->close();
?>

<script>
    // Store current document ID for actions
    var currentViewDocId = null;
    var currentSupplyRequestId = null;

    // Helper function to clean up stuck backdrops
    function cleanupBackdrops() {
        // Remove any existing modal backdrops
        document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
        // Clean up body classes and styles
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('overflow');
        document.body.style.removeProperty('padding-right');
    }

    // Add event listener to clean up backdrops when ANY modal is closed
    document.addEventListener('hidden.bs.modal', function (event) {
        const openModals = document.querySelectorAll('.modal.show');
        if (openModals.length === 0) {
            cleanupBackdrops();
        }
    });

    // Show alert/toast notification
    function showAlert(type, message) {
        const alertDiv = document.createElement('div');
        alertDiv.className = `alert alert-${type} alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3 z-index-9999`;
        alertDiv.style.zIndex = '9999';
        alertDiv.innerHTML = `
            ${message}
            <button type="buttosn" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        document.body.appendChild(alertDiv);

        setTimeout(() => {
            alertDiv.remove();
        }, 5000);
    }

    // Mark as Approved from View
    function markApprovedFromView() {
        if (!currentViewDocId || !currentSupplyRequestId) {
            showAlert('warning', 'Please select a document first');
            return;
        }

        if (confirm('Are you sure you want to approve this supply request?')) {
            const formData = new FormData();
            formData.append('id', currentSupplyRequestId);
            formData.append('action', 'approve');
            formData.append('csrf_token', '<?php echo $csrfToken; ?>');

            fetch('side_documents.php', {
                method: 'POST',
                body: formData
            })
                .then(response => {
                    if (response.ok) {
                        showAlert('success', 'Request approved successfully!');

                        // Close modal and reload
                        const viewModalEl = document.getElementById('viewDocumentModal');
                        if (viewModalEl) {
                            const viewModal = bootstrap.Modal.getInstance(viewModalEl);
                            if (viewModal) viewModal.hide();
                        }

                        setTimeout(() => {
                            location.reload();
                        }, 1500);
                    } else {
                        showAlert('danger', 'Failed to approve request.');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showAlert('danger', 'An error occurred. Please try again.');
                });
        }
    }

    // Mark as Complied from View
    function markCompliedFromView() {
        if (!currentViewDocId || !currentSupplyRequestId) {
            showAlert('warning', 'Please select a document first');
            return;
        }

        if (confirm('Are you sure you want to mark this supply request as complied?')) {
            const formData = new FormData();
            formData.append('id', currentSupplyRequestId);
            formData.append('action', 'comply');
            formData.append('csrf_token', '<?php echo $csrfToken; ?>');

            fetch('side_documents.php', {
                method: 'POST',
                body: formData
            })
                .then(response => {
                    if (response.ok) {
                        showAlert('success', 'Request marked as complied!');

                        // Close modal and reload
                        const viewModalEl = document.getElementById('viewDocumentModal');
                        if (viewModalEl) {
                            const viewModal = bootstrap.Modal.getInstance(viewModalEl);
                            if (viewModal) viewModal.hide();
                        }

                        setTimeout(() => {
                            location.reload();
                        }, 1500);
                    } else {
                        showAlert('danger', 'Failed to comply request.');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showAlert('danger', 'An error occurred. Please try again.');
                });
        }
    }

    // Function to load document data into view modal
    async function loadDocumentView(docId, supplyRequestId, source = 'main') {
        currentViewDocId = docId;
        currentSupplyRequestId = supplyRequestId;

        const modalElement = document.getElementById('viewDocumentModal');
        const modalContent = document.getElementById('documentFullContent');
        const approveBtn = modalElement.querySelector('button[onclick="markApprovedFromView()"]');
        const complyBtn = modalElement.querySelector('button[onclick="markCompliedFromView()"]');

        // Toggle Approve button visibility - show only for pending requests
        if (approveBtn) {
            approveBtn.style.display = (source === 'pending') ? '' : 'none';
        }

        // Toggle Comply button visibility - show for approved requests (from main table), hide for archived
        if (complyBtn) {
            complyBtn.style.display = (source === 'main') ? '' : 'none';
        }

        // For archived items, hide both buttons (view only)
        if (source === 'archived') {
            if (approveBtn) approveBtn.style.display = 'none';
            if (complyBtn) complyBtn.style.display = 'none';
        }

        // Toggle Comply button visibility - show for approved requests (from main table)
        if (complyBtn) {
            complyBtn.style.display = (source === 'main') ? '' : 'none';
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
            // Pass supply_id to get supply request data (remarks, status dates, and progress timeline)
            const url = `get_document_simple.php?id=${docId}&supply_id=${supplyRequestId}`;
            console.log("Fetching URL:", url); // Debug log

            const response = await fetch(url);
            if (!response.ok) throw new Error('Failed to fetch document');

            const html = await response.text();
            modalContent.innerHTML = html;

            // Optional: Scroll to top of modal content
            modalElement.querySelector('.modal-body').scrollTop = 0;

        } catch (error) {
            console.error('Error loading document:', error);
            modalContent.innerHTML = `
                <div class="alert alert-danger m-3">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    Failed to load document details. Please check your connection or contact administrator.
                </div>
            `;
        }
    }

    // Self-executing function - runs immediately when script loads (for AJAX compatibility)
    (function () {
        // Search functionality for main table
        const searchInput = document.getElementById('searchInput');

        if (searchInput) {
            searchInput.addEventListener('keyup', function (e) {
                const searchTerm = searchInput.value.toLowerCase().trim();
                const rows = document.querySelectorAll('#documentsTable tbody tr');

                rows.forEach(row => {
                    const cells = row.querySelectorAll('td');
                    if (cells.length >= 4) {
                        const preRepairNo = cells[0].textContent.toLowerCase();
                        const propertyNo = cells[1].textContent.toLowerCase();
                        const requestedBy = cells[2].textContent.toLowerCase();
                        const adminLocation = cells[3].textContent.toLowerCase();

                        if (preRepairNo.includes(searchTerm) ||
                            propertyNo.includes(searchTerm) ||
                            requestedBy.includes(searchTerm) ||
                            adminLocation.includes(searchTerm)) {
                            row.style.display = '';
                        } else {
                            row.style.display = 'none';
                        }
                    }
                });
            });
        }

        // Status filter functionality for main table
        const statusFilter = document.getElementById('statusFilter');
        if (statusFilter) {
            statusFilter.addEventListener('change', function () {
                const selectedStatus = this.value;
                const rows = document.querySelectorAll('#documentsTable tbody tr');

                rows.forEach(row => {
                    const statusBadge = row.querySelector('span[data-status]');
                    const rowStatus = statusBadge ? statusBadge.getAttribute('data-status') : '';

                    if (selectedStatus === 'all' || rowStatus === selectedStatus) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });

                // Update count
                const visibleRows = Array.from(document.querySelectorAll('#documentsTable tbody tr')).filter(row => row.style.display !== 'none');
                const countSpan = document.querySelector('#documentsTable')?.closest('.bg-white')?.querySelector('.text-sm.text-gray-600');
                if (countSpan) {
                    countSpan.textContent = `Showing ${visibleRows.length} requests`;
                }
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
                    if (cells.length >= 3) {
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

        // View Document functionality - Using Event Delegation
        const viewDocumentModalEl = document.getElementById('viewDocumentModal');
        const pendingModalEl = document.getElementById('viewRequestModal');

        // Event Delegation - handles both existing and dynamically added buttons
        document.addEventListener('click', function (e) {
            const btn = e.target.closest('.view-document-btn');
            if (!btn) return;

            const documentId = btn.getAttribute('data-document-id');
            const supplyRequestId = btn.getAttribute('data-supply-request-id');

            // Check if the button is inside the Pending Requests table, View Request modal, or Archived modal
            let source = 'main';
            if (btn.closest('#pendingRequestsTable') || btn.closest('#viewRequestModal')) {
                source = 'pending';
            } else if (btn.closest('#archivedModal')) {
                source = 'archived';
            }

            // NOTE: We no longer hide the pending modal. 
            // Bootstrap 5 handles stacked modals by default.
            // This preserves the backdrop/blur.

            // Load document using the new function
            loadDocumentView(documentId, supplyRequestId, source);
        });

        // Handle archived modal closing
        const archivedModal = document.getElementById('archivedModal');
        if (archivedModal) {
            archivedModal.addEventListener('hidden.bs.modal', function () {
                // If we're closing archive and want to return to pending modal
                if (pendingModalEl && !pendingModalEl.classList.contains('show')) {
                    const pendingModal = new bootstrap.Modal(pendingModalEl);
                    pendingModal.show();
                }
            });
        }

        // Photo modal functions
        let currentZoom = 1;

        window.showPhoto = function (photoPath) {
            currentZoom = 1;
            const myModal = new bootstrap.Modal(document.getElementById('photoModal'));
            const img = document.getElementById('modalPhoto');
            img.style.transform = 'scale(1)';
            img.src = photoPath;
            document.getElementById('downloadPhoto').href = photoPath;
            myModal.show();
        }

        window.zoomPhoto = function (delta) {
            currentZoom += delta;
            if (currentZoom < 0.3) currentZoom = 0.3;
            if (currentZoom > 3) currentZoom = 3;
            document.getElementById('modalPhoto').style.transform = 'scale(' + currentZoom + ')';
        }

        window.resetZoom = function () {
            currentZoom = 1;
            document.getElementById('modalPhoto').style.transform = 'scale(1)';
        }
    })();
</script>