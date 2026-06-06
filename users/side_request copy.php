<?php
// Start session
require_once __DIR__ . '/../includes/session_helper.php';
if (session_status() === PHP_SESSION_NONE) {
    start_user_session();
}
// Database connection
$mysqli = new mysqli('localhost', 'root', '', 'user_management');
if ($mysqli->connect_errno) {
    die("Database connection failed: " . $mysqli->connect_error);
}

// ✅ Check and add 'officer_signature' column if missing
$checkCol = $mysqli->query("SHOW COLUMNS FROM documents LIKE 'signature'");
if ($checkCol->num_rows == 0) {
    $mysqli->query("ALTER TABLE documents ADD COLUMN signature VARCHAR(255) DEFAULT NULL");
}

// ✅ Check and add new columns if missing
$columns_to_check = ['location', 'photo_path', 'attached_file_path', 'remarks'];
foreach ($columns_to_check as $column) {

    $checkCol = $mysqli->query("SHOW COLUMNS FROM documents LIKE '$column'");
    if ($checkCol->num_rows == 0) {
        if ($column == 'remarks') {
            $mysqli->query("ALTER TABLE documents ADD COLUMN $column TEXT DEFAULT NULL");
        } else {
            $mysqli->query("ALTER TABLE documents ADD COLUMN $column VARCHAR(255) DEFAULT NULL");
        }
    }
}

// ✅ Handle form submission
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Sanitize inputs
    $pre_repair_no = trim($_POST['prerepair_no'] ?? '');
    $property_no = trim($_POST['property_number'] ?? '');
    $carrying_amount = !empty(trim($_POST['carrying_amount'] ?? '')) ? trim($_POST['carrying_amount']) : null;
    $location = trim($_POST['location'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');

    // Basic validation - only require Property Number and Location (admin will fill Pre-Repair No and Carrying Amount)
    if (empty($property_no) || empty($location)) {
        echo "<script>alert('⚠️ Please fill in all required fields (Property Number and Location).'); window.history.back();</script>";
        exit;
    }

    // File upload handling
    $photo_path = null;
    $attached_file_path = null;

    // Upload directory
    $upload_dir = __DIR__ . '/../uploads/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    // Handle photo upload
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] == UPLOAD_ERR_OK) {
        $photo_name = time() . '_' . basename($_FILES['photo']['name']);
        $photo_target = $upload_dir . $photo_name;

        // Validate file type
        $photo_type = strtolower(pathinfo($photo_target, PATHINFO_EXTENSION));
        $allowed_photo_types = ['jpg', 'jpeg', 'png', 'gif'];

        if (in_array($photo_type, $allowed_photo_types)) {
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $photo_target)) {
                $photo_path = 'uploads/' . $photo_name;
            }
        }
    }

    // Handle attached file upload
    if (isset($_FILES['attached_file']) && $_FILES['attached_file']['error'] == UPLOAD_ERR_OK) {
        $attached_name = time() . '_' . basename($_FILES['attached_file']['name']);
        $attached_target = $upload_dir . $attached_name;

        // Validate file type (allow common document types)
        $attached_type = strtolower(pathinfo($attached_target, PATHINFO_EXTENSION));
        $allowed_attached_types = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'zip', 'rar'];

        if (in_array($attached_type, $allowed_attached_types)) {
            if (move_uploaded_file($_FILES['attached_file']['tmp_name'], $attached_target)) {
                $attached_file_path = 'uploads/' . $attached_name;
            }
        }
    }

    // ✅ Check if Pre-Repair No already exists (only if provided by user)
    if (!empty($pre_repair_no)) {
        $check_stmt = $mysqli->prepare("SELECT COUNT(*) FROM documents WHERE pre_repair_no = ?");
        $check_stmt->bind_param("s", $pre_repair_no);
        $check_stmt->execute();
        $check_stmt->bind_result($count);
        $check_stmt->fetch();
        $check_stmt->close();

        if ($count > 0) {
            echo "<script>alert('⚠️ Pre-Repair No. already exists! Please use a unique number.'); window.history.back();</script>";
            exit;
        }
    }

    // ✅ Get category_id automatically from equipment table
    $cat_stmt = $mysqli->prepare("SELECT category_id FROM equipment WHERE property_no = ?");
    $cat_stmt->bind_param("s", $property_no);
    $cat_stmt->execute();
    $cat_stmt->bind_result($category_id);
    $cat_stmt->fetch();
    $cat_stmt->close();
    if (empty($category_id))
        $category_id = 0; // Fallback to prevent DB error

    // Default status
    $status = "Pending";
    $date_requested = date("Y-m-d H:i:s");
    $userId = $_SESSION['user_id'] ?? 0; // Get current user ID or 0 if not set

    // ✅ Fetch officer name directly from database to ensure it saves correctly
    $user_stmt = $mysqli->prepare("SELECT username FROM users WHERE id = ?");
    $user_stmt->bind_param("i", $userId);
    $user_stmt->execute();
    $user_stmt->bind_result($officer_name);
    $user_stmt->fetch();
    $user_stmt->close();
    if (empty($officer_name))
        $officer_name = "Unknown";

    // ✅ Fetch Digital Signature from User Profile
    $officer_signature = null;
    $sig_stmt = $mysqli->prepare("SELECT signature FROM users WHERE id = ?");
    if ($sig_stmt) {
        $sig_stmt->bind_param("i", $userId);
        $sig_stmt->execute();
        $sig_stmt->bind_result($officer_signature);
        $sig_stmt->fetch();
        $sig_stmt->close();
    }

    // ✅ Handle admin_note
    $admin_note = null;
    if (($_SESSION['role'] ?? '') === 'admin') {
        $admin_note = trim($_POST['admin_note'] ?? '');
    }

    // ✅ Insert full data (including carrying_amount, officer_name & signature)
    $insert_stmt = $mysqli->prepare(
        "INSERT INTO documents 
        (user_id, category_id, property_no, pre_repair_no, carrying_amount, location, 
         photo_path, attached_file_path, remarks, officer_name, signature, status, date_requested, admin_note)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    if (!$insert_stmt) {
        die("Database Error: " . $mysqli->error); // Stops execution and shows the specific SQL error
    }

    $insert_stmt->bind_param(
        "isssssssssssss",
        $userId,
        $category_id,
        $property_no,
        $pre_repair_no,
        $carrying_amount,
        $location,
        $photo_path,
        $attached_file_path,
        $remarks,
        $officer_name,
        $officer_signature,
        $status,
        $date_requested,
        $admin_note
    );

    if ($insert_stmt->execute()) {
        echo "<script>alert('✅ Request submitted successfully!'); window.location.href='user_dashboard.php?view=request';</script>";
    } else {
        echo "<script>alert('❌ Error submitting request: " . addslashes($insert_stmt->error) . "'); window.history.back();</script>";
    }

    $insert_stmt->close();
}
?>

<!-- ✅ Include Bootstrap Icons and Select2 -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

<style>
    /* ✅ Ensure Icons are visible and centered */
    .btn i,
    .btn-action i {
        vertical-align: middle;
        line-height: 1;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    .action-icon-btn {
        width: 32px;
        height: 32px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0;
        border-radius: 8px;
        transition: all 0.2s ease;
    }

    .action-icon-btn:hover {
        transform: scale(1.1);
        background-color: #f0f4f8;
    }

    .signature-input {
        border: none !important;
        border-bottom: 1px solid #000 !important;
        border-radius: 0 !important;
        background: transparent !important;
        box-shadow: none !important;
        padding-bottom: 0 !important;
        margin-bottom: 5px !important;
    }

    /* Improved styles for the new section */
    .attachment-section {
        background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        border-radius: 10px;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
    }

    .attachment-card {
        border: none;
        border-radius: 8px;
        transition: all 0.3s ease;
        background: white;
    }

    .attachment-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 12px rgba(0, 0, 0, 0.1);
    }

    .section-title {
        color: #2c3e50;
        font-weight: 600;
        position: relative;
        padding-bottom: 10px;
    }

    .section-title:after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        width: 60px;
        height: 3px;
        background: #3498db;
        border-radius: 2px;
    }

    .file-upload-wrapper {
        position: relative;
        overflow: hidden;
        cursor: pointer;
    }

    .file-upload-wrapper input[type="file"] {
        position: absolute;
        top: 0;
        right: 0;
        min-width: 100%;
        min-height: 100%;
        font-size: 100px;
        text-align: right;
        filter: alpha(opacity=0);
        opacity: 0;
        outline: none;
        cursor: inherit;
        display: block;
    }

    .file-upload-label {
        display: block;
        padding: 10px 15px;
        background: #f8f9fa;
        border: 2px dashed #dee2e6;
        border-radius: 6px;
        text-align: center;
        transition: all 0.3s ease;
        cursor: pointer;
    }

    .file-upload-label:hover {
        background: #e9ecef;
        border-color: #3498db;
    }

    .file-info {
        font-size: 0.85rem;
        color: #6c757d;
        margin-top: 5px;
    }

    .required-field:after {
        content: " *";
        color: #e74c3c;
    }


    /* Custom styling for Select2 */
    .select2-container .select2-selection--single {
        height: 38px !important;
        border: 1px solid #dee2e6 !important;
        border-radius: 0.25rem !important;
        font-size: 0.85rem !important;
    }

    .select2-container--default .select2-selection--single .select2-selection__rendered {
        line-height: 36px !important;
        padding-left: 12px !important;
    }

    .select2-container--default .select2-selection--single .select2-selection__arrow {
        height: 36px !important;
    }

    .select2-container--default .select2-search--dropdown .select2-search__field {
        border: 1px solid #dee2e6 !important;
        border-radius: 0.25rem !important;
        font-size: 0.85rem !important;
    }

    .select2-container--default .select2-results__option--highlighted[aria-selected] {
        background-color: #3498db !important;
    }

    .select2-dropdown {
        border: 1px solid #dee2e6 !important;
        border-radius: 0.25rem !important;
    }

    /* Make dropdown wider for better readability */
    .select2-container {
        width: 100% !important;
    }

    /* Property info styling */
    .property-option {
        display: flex;
        flex-direction: column;
        padding: 5px 0;
    }

    .property-no {
        font-weight: 600;
        color: #2c3e50;
        font-size: 0.9rem;
    }

    .property-details {
        font-size: 0.8rem;
        color: #7f8c8d;
        margin-top: 2px;
    }

    .property-designation {
        color: #27ae60;
        font-weight: 500;
    }

    /* Loading indicator */
    .select2-selection__placeholder {
        color: #6c757d !important;
    }

    /* Generate Button Responsive Styles */
    .generate-btn {
        white-space: nowrap;
        transition: all 0.2s ease;
    }

    /* Photo Modal Z-Index Fix - Ensure it displays on top of other modals */
    #photoModal {
        z-index: 1060 !important;
    }

    #photoModal .modal-dialog {
        z-index: 1061 !important;
        position: relative;
    }

    #photoModal .modal-content {
        z-index: 1062 !important;
    }

    /* Custom backdrop for photo modal */
    .custom-photo-backdrop {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.85);
        z-index: 1055 !important;
    }

    /* Ensure photo modal content is above backdrop */
    #photoModal .modal-dialog {
        position: relative;
        z-index: 1060 !important;
    }

    @media (max-width: 576px) {
        .generate-btn {
            padding: 6px 10px;
            font-size: 0.8rem;
        }

        .generate-btn i {
            margin-right: 0;
        }

        /* Responsive adjustments for View Details modal header badges */
        #modalLocationBadge,
        #modalDateBadge {
            font-size: 0.65rem !important;
            padding: 4px 8px !important;
        }

        .modal-header .d-flex.align-items-center {
            flex-wrap: wrap !important;
            gap: 8px !important;
        }
        
        .modal-title {
            width: 100%;
            margin-bottom: 5px !important;
        }
    }

    @media (max-width: 400px) {
        .generate-btn {
            padding: 4px 8px;
            font-size: 0.75rem;
        }
    }
</style>

<!-- <div class="container-fluid my-3"> -->
<div class="row">
    <div class="col-12">
        <!-- ✅ Compose Message Button -->
        <div class="sticky-top mb-3">
            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#composeModal">
                Compose Request
            </button>
        </div>

        <!-- ========== MODERN FILTER & SEARCH BAR ========== -->
        <div class="bg-white p-4 rounded-xl shadow-sm border border-gray-100 mb-6 transition-all hover:shadow-md">
            <div class="row g-3 align-items-center">
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-gray-600 mb-1">Status Filter</label>
                    <select id="statusFilter"
                        class="form-select form-select-sm border-gray-200 rounded-lg focus:border-brand-500 focus:ring-2 focus:ring-brand-200"
                        onchange="applyFilters()">
                        <option value="All" <?= (!isset($_GET['status']) || $_GET['status'] == 'All') ? 'selected' : '' ?>>
                            All Requests</option>
                        <option value="Pending" <?= (isset($_GET['status']) && $_GET['status'] == 'Pending') ? 'selected' : '' ?>>⏳ Pending</option>
                        <option value="Approved" <?= (isset($_GET['status']) && $_GET['status'] == 'Approved') ? 'selected' : '' ?>>✅ Approved</option>
                        <option value="Done" <?= (isset($_GET['status']) && $_GET['status'] == 'Done') ? 'selected' : '' ?>>🏁 Completed</option>
                    </select>
                </div>
                <div class="col-md-9">
                    <label class="form-label small fw-bold text-gray-600 mb-1">Search & Filter</label>
                    <div class="d-flex gap-2">
                        <div class="input-group flex-grow-1">
                            <input type="text" id="searchInput"
                                class="form-control border-gray-200 rounded-l-lg focus:ring-0 focus:border-brand-500"
                                style="height: 38px; font-size: 0.9rem;"
                                placeholder="Search by Pre-Repair No. or Property No..."
                                value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
                            <button
                                class="btn btn-primary d-flex align-items-center justify-content-center px-3 rounded-r-lg"
                                style="height: 38px;" onclick="applyFilters()" title="Execute Search">
                                <i class="bi bi-search fs-6"></i>
                            </button>
                        </div>
                        <?php
                        $reset_url = '?';
                        if (isset($_GET['view'])) {
                            $reset_url = '?view=' . urlencode($_GET['view']);
                        }
                        ?>
                        <a href="<?= $reset_url ?>"
                            class="btn btn-outline-secondary d-flex align-items-center justify-content-center rounded-lg"
                            style="height: 38px; width: 42px; min-width: 42px;" title="Reset Filters">
                            <i class="bi bi-arrow-counterclockwise fs-5"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Photo Modal (moved to top level to avoid nesting issues) -->
<div class="modal fade" id="photoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-camera-fill"></i> Photo Preview</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                    aria-label="Close"></button>
            </div>
            <div class="modal-body text-center p-0" style="background: #1a1a1a;">
                <img id="modalPhoto" src="" alt="Full Size Photo" style="max-width: 100%; max-height: 70vh;">
            </div>
            <div class="modal-footer" style="background: #1a1a1a;">
                <a id="downloadPhoto" href="" class="btn btn-success" download><i class="bi bi-download"></i>
                    Download</a>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ✅ PROFESSIONAL TABLE DESIGN -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
    <div class="overflow-x-auto" style="max-height: 650px;">
        <table class="w-full text-sm text-left border-collapse">
            <thead class="sticky top-0 z-20">
                <tr class="bg-gray-50/90 backdrop-blur-sm border-b border-gray-200">
                    <th class="px-6 py-4 font-bold text-gray-700 uppercase tracking-wider text-xs">Pre-Repair
                        No.</th>
                    <th class="px-6 py-4 font-bold text-gray-700 uppercase tracking-wider text-xs">Maintenance
                        Dept</th>
                    <th class="px-6 py-4 font-bold text-gray-700 uppercase tracking-wider text-xs">Property No.
                    </th>
                    <th class="px-6 py-4 font-bold text-gray-700 uppercase tracking-wider text-xs">Requested On
                    </th>
                    <th class="px-6 py-4 font-bold text-gray-700 uppercase tracking-wider text-xs text-center">
                        Status</th>
                    <th class="px-6 py-4 font-bold text-gray-700 uppercase tracking-wider text-xs text-center"
                        style="width: 80px;">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php
                // ... SQL and Logic ...
                $mysqli = new mysqli('localhost', 'root', '', 'user_management');
                if ($mysqli->connect_errno) {
                    echo '<tr><td colspan="6" class="px-6 py-10 text-center text-gray-400 italic">Database connection failed.</td></tr>';
                } else {
                    $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
                    $statusParam = $_GET['status'] ?? 'All';
                    $status_filter = match ($statusParam) {
                        'Pending' => "('Pending')",
                        'Approved' => "('Approved')",
                        'Done' => "('Done', 'Complete')",
                        default => "('Pending', 'Approved', 'Done', 'Complete')"
                    };

                    if (isset($_GET['view']) && $_GET['view'] === 'archived') {
                        $status_filter = "('Archived')";
                    }

                    $searchTerm = isset($_GET['search']) ? trim($_GET['search']) : '';
                    $searchCondition = !empty($searchTerm) ? " AND (d.pre_repair_no LIKE '%" . $mysqli->real_escape_string($searchTerm) . "%' OR d.property_no LIKE '%" . $mysqli->real_escape_string($searchTerm) . "%')" : '';

                    $sql = "SELECT d.id, d.pre_repair_no, COALESCE(d.property_no, d.equipment) AS property_no, d.location, d.date_requested, d.status FROM documents d WHERE d.status IN $status_filter AND d.user_id = $userId $searchCondition ORDER BY d.date_requested DESC";
                    $result = $mysqli->query($sql);

                    if ($result && $result->num_rows > 0) {
                        while ($row = $result->fetch_assoc()) {
                            $id = $row['id'];
                            $pre = htmlspecialchars($row['pre_repair_no'] ?? '');
                            $location = htmlspecialchars($row['location'] ?? '');
                            $prop = htmlspecialchars($row['property_no'] ?? '');
                            $date = !empty($row['date_requested']) ? date('M d, Y', strtotime($row['date_requested'])) : '---';
                            $status = $row['status'];

                            $location_display = match (strtolower($location)) {
                                'mamburao' => 'Mamburao',
                                'sablayan' => 'Sablayan',
                                'san jose' => 'San Jose',
                                'lubang' => 'Lubang',
                                default => ucfirst($location)
                            };

                            $statusConfig = match ($status) {
                                'Pending' => ['bg' => 'bg-amber-50', 'text' => 'text-amber-700', 'dot' => 'bg-amber-400', 'icon' => 'bi-clock-history'],
                                'Approved' => ['bg' => 'bg-emerald-50', 'text' => 'text-emerald-700', 'dot' => 'bg-emerald-400', 'icon' => 'bi-check2-circle'],
                                'Done' => ['bg' => 'bg-blue-50', 'text' => 'text-blue-700', 'dot' => 'bg-blue-400', 'icon' => 'bi-flag-fill'],
                                default => ['bg' => 'bg-gray-50', 'text' => 'text-gray-700', 'dot' => 'bg-gray-400', 'icon' => 'bi-question-circle']
                            };

                            echo "<tr class='group hover:bg-gray-50/80 transition-all cursor-default'>";
                            echo "<td class='px-6 py-4 font-medium text-gray-900'>{$pre}</td>";
                            echo "<td class='px-6 py-4 text-gray-600'><span class='inline-flex items-center'><i class='bi bi-geo-alt me-2 text-gray-400'></i>{$location_display}</span></td>";
                            echo "<td class='px-6 py-4 text-gray-600'>{$prop}</td>";
                            echo "<td class='px-6 py-4 text-gray-500 text-xs'>{$date}</td>";
                            echo "<td class='px-6 py-4 text-center'>";
                            echo "<span class='inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold {$statusConfig['bg']} {$statusConfig['text']} ring-1 ring-inset ring-opacity-20'>";
                            echo "<span class='h-1.5 w-1.5 rounded-full {$statusConfig['dot']}'></span>";
                            echo "<i class='bi {$statusConfig['icon']}'></i>" . htmlspecialchars($status);
                            echo "</span>";
                            echo "</td>";
                            echo "<td class='px-6 py-4 text-center'>";
                            echo "<button onclick='viewRequestDetails({$id})' class='action-icon-btn text-brand-600 hover:text-brand-700' title='View Details'><i class='bi bi-eye-fill fs-5'></i></button>";
                            echo "</td>";
                            echo "</tr>";
                        }
                    } else {
                        echo '<tr><td colspan="6" class="px-6 py-16 text-center text-gray-400 italic bg-gray-50/30">No requests found matching your criteria.</td></tr>';
                    }
                    $mysqli->close();
                }
                ?>
            </tbody>
        </table>
    </div>
</div>
<!-- Pagination is completely removed as requested -->
</div>
</div>
</div>

<!-- Archived Documents Modal -->
<div class="modal fade" id="archivedModal" tabindex="-1" aria-labelledby="archivedModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-secondary text-white">
                <h5 class="modal-title fw-bold" id="archivedModalLabel">
                    <i class="bi bi-archive"></i> Archived Documents
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">

                <!-- Search -->
                <div class="mb-3">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" id="archivedSearch" class="form-control" placeholder="Search Pre-Repair No.">
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover table-striped align-middle mb-0" id="archivedTable">
                        <thead class="table-light">
                            <tr>
                                <th>Pre-Repair No.</th>
                                <th>Location</th>
                                <th>Property No.</th>
                                <th>Date Requested</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="archivedTableBody">
                            <?php
                            $mysqli = new mysqli('localhost', 'root', '', 'user_management');
                            if ($mysqli->connect_errno) {
                                echo '<tr><td colspan="5">Database connection failed.</td></tr>';
                            } else {
                                $sql = "
                                    SELECT
                                        d.id,
                                        d.pre_repair_no,
                                        COALESCE(d.property_no, d.equipment) AS property_no,
                                        d.location,
                                        d.date_requested,
                                        d.status
                                    FROM documents d
                                    WHERE d.status = 'Archived'
                                    ORDER BY d.date_requested DESC
                                    ";
                                $result = $mysqli->query($sql);
                                $rows = [];
                                if ($result && $result->num_rows > 0) {
                                    while ($row = $result->fetch_assoc()) {
                                        // Format location display for archived data
                                        $location = htmlspecialchars($row['location'] ?? '');
                                        $location_display = "";
                                        switch (strtolower($location)) {
                                            case 'mamburao':
                                                $location_display = 'Mamburao - Maintenance Department';
                                                break;
                                            case 'sablayan':
                                                $location_display = 'Sablayan - Maintenance Department';
                                                break;
                                            case 'san jose':
                                                $location_display = 'San Jose - Maintenance Department';
                                                break;
                                            case 'lubang':
                                                $location_display = 'Lubang - Maintenance Department';
                                                break;
                                            default:
                                                $location_display = ucfirst($location) . ' - Maintenance Department';
                                        }

                                        $rows[] = [
                                            'pre' => htmlspecialchars($row['pre_repair_no']),
                                            'location' => $location_display,
                                            'prop' => htmlspecialchars($row['property_no']),
                                            'date' => !empty($row['date_requested']) && $row['date_requested'] !== '0000-00-00 00:00:00'
                                                ? date('Y-m-d', strtotime($row['date_requested']))
                                                : '',
                                            'status' => htmlspecialchars($row['status'])
                                        ];
                                    }
                                }
                                $mysqli->close();
                                // Encode rows for JS
                                echo "<script>const archivedData = " . json_encode($rows) . ";</script>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <nav>
                    <ul class="pagination justify-content-center mt-2" id="archivedPagination"></ul>
                </nav>

            </div>
        </div>
    </div>
</div>

<!-- ✅ Modal -->
<div class="modal fade" id="composeModal" tabindex="-1" aria-labelledby="composeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">

            <div class="modal-body">
                <div class="container mt-2">

                    <!-- ===================== ATTACHMENT SECTION (OUTSIDE FORM) ===================== -->
                    <div class="attachment-section p-4 mb-4">
                        <h5 class="section-title mb-4">Request Details & Attachments</h5>

                        <form method="POST" action="" id="requestForm" enctype="multipart/form-data">
                            <!-- Hidden fields that need to be inside form for submission -->
                            <input type="hidden" name="location" id="location_input">
                            <input type="hidden" name="remarks" id="remarks_input">

                            <div class="row g-4">
                                <!-- Location Selection -->
                                <div class="col-md-6">
                                    <div class="card attachment-card h-100">
                                        <div class="card-body">
                                            <h6 class="card-title fw-bold mb-3">
                                                <i class="fas fa-map-marker-alt text-primary me-2"></i>
                                                Location Selection
                                                <span class="required-field"></span>
                                            </h6>
                                            <div class="mb-3">
                                                <label class="form-label small text-muted mb-2">Select Maintenance
                                                    Department</label>
                                                <select class="form-select" id="location_select"
                                                    style="font-size: 0.9rem;" required>
                                                    <option value="">Choose location...</option>
                                                    <option value="Mamburao">Mamburao - Maintenance Department</option>
                                                    <option value="Sablayan">Sablayan - Maintenance Department</option>
                                                    <option value="San Jose">San Jose - Maintenance Department</option>
                                                    <option value="Lubang">Lubang - Maintenance Department</option>
                                                </select>
                                                <div class="form-text">Select where the repair/maintenance will be
                                                    conducted</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Photo Upload -->
                                <div class="col-md-6">
                                    <div class="card attachment-card h-100">
                                        <div class="card-body">
                                            <h6 class="card-title fw-bold mb-3">
                                                <i class="fas fa-camera text-success me-2"></i>
                                                Equipment Photo
                                            </h6>
                                            <div class="mb-3">
                                                <label class="form-label small text-muted mb-2">Upload equipment photo
                                                    (Optional)</label>
                                                <div class="file-upload-wrapper">
                                                    <label for="photo" class="file-upload-label">
                                                        <i class="fas fa-cloud-upload-alt fa-2x text-muted mb-2"></i>
                                                        <div class="fw-medium">Click to upload photo</div>
                                                        <small class="d-block text-muted">JPG, PNG, GIF (Max
                                                            5MB)</small>
                                                    </label>
                                                    <input type="file" name="photo" id="photo" class="form-control"
                                                        accept="image/*">
                                                </div>
                                                <div id="photoPreview" class="mt-3 text-center d-none">
                                                    <img id="previewImage" src="#" alt="Preview" class="img-thumbnail"
                                                        style="max-height: 150px;">
                                                    <button type="button" class="btn btn-sm btn-outline-danger mt-2"
                                                        onclick="removePhoto()">
                                                        <i class="fas fa-trash me-1"></i> Remove
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- File Attachment -->
                                <div class="col-md-6">
                                    <div class="card attachment-card h-100">
                                        <div class="card-body">
                                            <h6 class="card-title fw-bold mb-3">
                                                <i class="fas fa-paperclip text-warning me-2"></i>
                                                Supporting Documents
                                            </h6>
                                            <div class="mb-3">
                                                <label class="form-label small text-muted mb-2">Attach supporting files
                                                    (Optional)</label>
                                                <div class="file-upload-wrapper">
                                                    <label for="attached_file" class="file-upload-label">
                                                        <i class="fas fa-file-alt fa-2x text-muted mb-2"></i>
                                                        <div class="fw-medium">Click to upload documents</div>
                                                        <small class="d-block text-muted">PDF, DOC, XLS, ZIP (Max
                                                            10MB)</small>
                                                    </label>
                                                    <input type="file" name="attached_file" id="attached_file"
                                                        class="form-control"
                                                        accept=".pdf,.doc,.docx,.xls,.xlsx,.txt,.zip,.rar">
                                                </div>
                                                <div id="fileInfo" class="mt-2"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Remarks -->
                                <div class="col-md-6">
                                    <div class="card attachment-card h-100">
                                        <div class="card-body">
                                            <h6 class="card-title fw-bold mb-3">
                                                <i class="fas fa-sticky-note text-info me-2"></i>
                                                Additional Remarks
                                            </h6>
                                            <div class="mb-3">
                                                <label class="form-label small text-muted mb-2">Additional notes or
                                                    instructions</label>
                                                <textarea class="form-control" id="remarks_textarea" rows="4"
                                                    placeholder="Optional: Add any additional notes, special instructions, or details about the repair request..."
                                                    style="font-size: 0.9rem;"></textarea>
                                                <div class="form-text">This information will help our maintenance team
                                                    understand your request better</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- ===================== CERTIFICATION FORM ===================== -->
                    <div class="card p-4 border-0 shadow-none printable-area">
                        <header class="d-flex align-items-center justify-content-center mb-4 text-center">
                            <img src="../rs/Pepo_Logo.png" alt="PEPO Logo" class="me-2 me-md-3"
                                style="width: clamp(50px, 12vw, 80px); height: auto;">
                            <div>
                                <small class="d-block"
                                    style="font-size: clamp(0.6rem, 2vw, 0.8rem); line-height: 1.2;">Republic of the
                                    Philippines</small>
                                <small class="d-block"
                                    style="font-size: clamp(0.6rem, 2vw, 0.8rem); line-height: 1.2;">PROVINCIAL
                                    GOVERNMENT OF OCCIDENTAL MINDORO</small>
                                <small class="d-block fw-bold"
                                    style="font-size: clamp(0.7rem, 2.5vw, 0.9rem); line-height: 1.2;">GENERAL SERVICES
                                    OFFICE</small>
                            </div>
                            <img src="../rs/BAGONG-PILIPINAS-LOGO.png" alt="Occidental Mindoro Logo"
                                class="ms-2 ms-md-3" style="width: clamp(70px, 15vw, 110px); height: auto;">
                        </header>

                        <!-- ===================== CERTIFICATION ===================== -->
                        <section class="mb-4">
                            <h5 class="text-center fw-bold text-success border-bottom border-success pb-2">
                                CERTIFICATION</h5>
                            <br>
                            <div class="text-start mb-3 mt-2">
                                <small class="fw-bold" style="font-size: 0.75rem;">Pre-Repair No.:</small>
                                <div class="input-group">
                                    <input type="text" name="prerepair_no" id="prerepair_no"
                                        class="form-control signature-input" style="font-size: 0.9rem;"
                                        placeholder="This field will be filled by the admin" form="requestForm" readonly>
                                </div>
                                <!-- <div class="form-text text-info" style="font-size: 0.75rem;">This field will be filled by the admin</div> -->
                            </div>

                            <table class="table table-bordered mb-2 align-middle table-sm"
                                style="font-size: 0.9rem; table-layout: fixed; width: 100%;">
                                <tbody>
                                    <tr>
                                        <td class="fw-bold" style="font-size: 0.75rem; width: 40%;">Property Number:
                                        </td>
                                        <td>


                                            <select class="form-select searchable-dropdown" id="property_number"
                                                name="property_number" style="font-size: 0.85rem;" form="requestForm"
                                                required>
                                                <option value="">Search Property Number or Description...</option>
                                                <?php
                                                $mysqli = new mysqli('localhost', 'root', '', 'user_management');
                                                if (!$mysqli->connect_errno) {
                                                    $equipment_result = $mysqli->query("SELECT property_no, description, designation, acquisition_date, acquisition_cost, last_repair_date FROM equipment ORDER BY property_no ASC");
                                                    if ($equipment_result && $equipment_result->num_rows > 0) {
                                                        while ($row = $equipment_result->fetch_assoc()) {
                                                            $displayText = htmlspecialchars($row['property_no']);

                                                            echo "<option value='" . htmlspecialchars($row['property_no']) . "' 
                    data-description='" . htmlspecialchars($row['description']) . "' 
                    data-designation='" . htmlspecialchars($row['designation']) . "' 
                    data-acqdate='" . htmlspecialchars($row['acquisition_date']) . "' 
                    data-acqcost='" . htmlspecialchars($row['acquisition_cost']) . "' 
                    data-repairdate='" . htmlspecialchars($row['last_repair_date']) . "'
                    data-displaytext='" . $displayText . "'>
                    " . $displayText . "
                </option>";
                                                        }
                                                    }
                                                }
                                                ?>
                                            </select>
                                        </td>
                                    </tr>

                                    <tr>
                                        <td class="fw-bold" style="font-size: 0.75rem;">Description:</td>
                                        <td>
                                            <textarea class="form-control" name="vehicle_type" id="description" readonly
                                                rows="2" style="font-size: 0.85rem;" form="requestForm"></textarea>
                                        </td>
                                    </tr>

                                    <tr>
                                        <td class="fw-bold" style="font-size: 0.75rem;">Designation:</td>
                                        <td><input type="text" class="form-control bg-light" name="property_designation"
                                                id="designation" style="font-size: 0.85rem;" readonly
                                                form="requestForm"></td>
                                    </tr>

                                    <tr>
                                        <td class="fw-bold" style="font-size: 0.75rem;">Acquisition Date:</td>
                                        <td><input type="text" class="form-control bg-light" name="acquisition_date"
                                                id="acquisition_date" style="font-size: 0.85rem;" readonly
                                                form="requestForm"></td>
                                    </tr>

                                    <tr>
                                        <td class="fw-bold" style="font-size: 0.75rem;">Acquisition Cost:</td>
                                        <td><input type="text" class="form-control bg-light" name="acquisition_cost"
                                                id="acquisition_cost" style="font-size: 0.85rem;" readonly
                                                form="requestForm"></td>
                                    </tr>

                                    <tr>
                                        <td class="fw-bold" style="font-size: 0.75rem;">Last Repair:</td>
                                        <td><input type="text" class="form-control" name="last_repair_date"
                                                id="last_repair_date" style="font-size: 0.85rem;" readonly
                                                form="requestForm"></td>
                                    </tr>

                                    <tr>
                                        <td class="fw-bold" style="font-size: 0.75rem;">Carrying Amount:</td>
                                        <td>
                                            <input type="text" name="carrying_amount" class="form-control"
                                                style="font-size: 0.85rem;" form="requestForm" readonly placeholder="This field will be filled by the admin">
                                            <!-- <div class="form-text text-info" style="font-size: 0.7rem;">This field will be filled by the admin</div> -->
                                        </td>
                                    </tr>
                                </tbody>
                            </table>

                            <p>(Attach a copy of latest job order)</p>
                            <p>This document serves to confirm that the PPE/ICS belongs to the Provincial Government
                                of Occidental Mindoro.</p>

                            <!-- Property Custodian Officer -->
                            <div class="d-flex justify-content-center mt-5">
                                <div class="text-center"
                                    style="width: 80%; max-width: 300px; display: flex; flex-direction: column; align-items: center;">

                                    <div
                                        style="min-height: 80px; display: flex; align-items: flex-end; justify-content: center; margin-bottom: -15px;">
                                        <?php
                                        // Get current user's signature if available
                                        $userId = $_SESSION['user_id'] ?? 0;
                                        $currentUser = [];
                                        if ($userId) {
                                            $user_stmt = $mysqli->prepare("SELECT username, signature FROM users WHERE id = ?");
                                            $user_stmt->bind_param("i", $userId);
                                            $user_stmt->execute();
                                            $user_stmt->bind_result($currentUser['username'], $currentUser['signature']);
                                            $user_stmt->fetch();
                                            $user_stmt->close();
                                        }
                                        if (!empty($currentUser['signature'])): ?>
                                            <img src="../<?= htmlspecialchars($currentUser['signature']) ?>" alt="Signature"
                                                style="height: 100px; width: auto; mix-blend-mode: multiply; filter: contrast(1.2);">
                                        <?php endif; ?>
                                    </div>

                                    <input type="text" name="officer_name"
                                        class="form-control text-center border-0 border-bottom bg-transparent fw-bold"
                                        style="font-size: 1rem; padding-bottom: 0;"
                                        value="<?= htmlspecialchars($currentUser['username'] ?? '') ?>" readonly
                                        form="requestForm" />

                                    <small class="text-muted d-block mt-1">Property Custodian Officer</small>
                                </div>
                            </div>

                        </section>

                        <!-- ===================== PRE-REPAIR INSPECTION ===================== -->
                        <section class="mb-4">
                            <h5 class="text-center fw-bold bg-success text-white py-2" style="font-size: 1rem;">
                                PRE-REPAIR INSPECTION</h5>

                            <div class="border p-3 mb-4 rounded bg-light"
                                style="font-family: 'Times New Roman', Times, serif; line-height: 1.6;">

                                <p class="mb-2 text-danger small italic" style="font-size: 1rem;">
                                    * This section is to be accomplished by the Authorized Inspector/Admin only.
                                </p>
                                <div class="border p-3 mb-4 rounded bg-light"
                                    style="font-family: 'Times New Roman', Times, serif; line-height: 1.6;">
                                    <div class="certification-text" style="font-size: 0.95rem; color: #6b6b6bff;">
                                        I,
                                        <input type="text" name="inspector_name"
                                            class="form-control d-inline-block border-0 border-bottom bg-transparent p-0 text-center"
                                            style="width: 140px; font-family: 'Times New Roman', Times, serif; font-weight: bold; font-size: 1rem;"
                                            value="Name Here" readonly form="requestForm">
                                        certify under penalty of law that as
                                        <input type="text" name="inspector_position"
                                            class="form-control d-inline-block border-0 border-bottom bg-transparent p-0 text-center"
                                            style="width: 120px; font-family: 'Times New Roman', Times, serif; font-weight: bold; font-size: 1rem;"
                                            value="Position Here" readonly form="requestForm">,
                                        I have carefully examined the Above-Mentioned Property of the Provincial
                                        Government of Occidental Mindoro.
                                    </div>
                                </div>

                            </div>



                            <div class="mb-3">
                                <label class="fw-bold" style="font-size: 0.75rem;">Defect/Complaint:</label>
                                <textarea class="form-control signature-input bg-light" name="defect" rows="2"
                                    style="font-size: 0.9rem;" readonly form="requestForm"></textarea>
                            </div>

                            <div class="mb-3">
                                <label class="fw-bold" style="font-size: 0.75rem;">Findings:</label>
                                <textarea class="form-control signature-input bg-light" name="findings" rows="2"
                                    style="font-size: 0.9rem;" readonly form="requestForm"></textarea>
                            </div>

                            <div class="mb-3">
                                <label class="fw-bold" style="font-size: 0.75rem;">Recommendation:</label>
                                <div class="d-flex flex-column flex-sm-row gap-2 mt-1">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="recommendation"
                                            value="For In-House Repair" id="forInHouseRepair" disabled
                                            form="requestForm">
                                        <label class="form-check-label" for="forInHouseRepair"
                                            style="font-size: 0.85rem;">For In-House Repair</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="recommendation"
                                            value="For Outside Repair" id="forOutsideRepair" disabled
                                            form="requestForm">
                                        <label class="form-check-label" for="forOutsideRepair"
                                            style="font-size: 0.85rem;">For Outside Repair</label>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <!-- ===================== MATERIALS ===================== -->
                        <section class="mb-4">
                            <h5 class="text-center fw-bold" style="font-size: 1rem;">MATERIALS & PARTS</h5>
                            <div class="table-responsive">
                                <table class="table table-bordered text-center align-middle table-sm"
                                    style="table-layout: fixed; width: 100%;">
                                    <thead class="bg-light">
                                        <tr style="font-size: 0.75rem;">
                                            <th style="width: 15%;">ITEM #</th>
                                            <th style="width: 55%;">MATERIAL/PARTS</th>
                                            <th style="width: 30%;">QTY</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php for ($i = 1; $i <= 10; $i++): ?>
                                            <tr>
                                                <td style="font-size: 0.8rem;"><?= $i ?></td>
                                                <td>
                                                    <input type="text" class="form-control bg-light text-center"
                                                        name="material_<?= $i ?>" style="font-size: 0.8rem; padding: 4px;"
                                                        readonly form="requestForm">
                                                </td>
                                                <td>
                                                    <input type="number" class="form-control bg-light text-center"
                                                        name="quantity_<?= $i ?>" style="font-size: 0.8rem; padding: 4px;"
                                                        readonly form="requestForm">
                                                </td>
                                            </tr>
                                        <?php endfor; ?>
                                    </tbody>
                                </table>
                            </div>
                        </section>

                        <!-- ===================== SIGNATORIES (Readonly for User Request) ===================== -->
                        <div class="row text-center mt-4 gx-2">
                            <div class="col-6 mb-3">
                                <label class="fw-bold small">Pre-Inspected by:</label>
                                <input type="text" class="form-control text-center signature-input" name="inspected_by"
                                    style="font-size: 0.9rem;" readonly form="requestForm">
                                <small class="text-muted" style="font-size: 0.8rem;">Inspector</small>
                            </div>
                            <div class="col-6 mb-3">
                                <label class="fw-bold small">Approved:</label>
                                <input type="text" class="form-control text-center signature-input"
                                    name="approved_by_pepo" style="font-size: 0.9rem;" readonly form="requestForm">
                                <small class="text-muted" style="font-size: 0.8rem;">PGDH-PEPO</small>
                            </div>
                            <div class="col-6 mb-3">
                                <label class="fw-bold small">Witnessed:</label>
                                <input type="text" class="form-control text-center signature-input" name="witnessed_by"
                                    style="font-size: 0.9rem;" readonly form="requestForm">
                                <small class="text-muted" style="font-size: 0.8rem;">PGDH-PACCO</small>
                            </div>
                            <div class="col-6 mb-3">
                                <label class="fw-bold small">Approved:</label>
                                <input type="text" class="form-control text-center signature-input"
                                    name="approved_by_gso" style="font-size: 0.9rem;" readonly form="requestForm">
                                <small class="text-muted" style="font-size: 0.8rem;">PGDH-GSO</small>
                            </div>
                        </div>

                    </div>

                    <!-- Buttons outside the form area -->
                    <div class="row g-2 mt-4">
                        <div class="col-6">
                            <button type="button" class="btn btn-secondary btn-lg w-100" data-bs-dismiss="modal"
                                style="font-size: 0.9rem;">Cancel Request</button>
                        </div>
                        <div class="col-6">
                            <button type="submit" form="requestForm" class="btn btn-success btn-lg w-100"
                                style="font-size: 0.9rem;">Submit Request</button>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>
</div>

<!-- ✅ Auto-fill Script -->


<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
    // Property number change handler
    document.getElementById('property_number').addEventListener('change', function () {
        const selected = this.options[this.selectedIndex];
        if (selected.value === "") return;

        document.getElementById('description').value = selected.dataset.description || "";
        document.getElementById('designation').value = selected.dataset.designation || "";
        document.getElementById('acquisition_date').value = selected.dataset.acqdate || "";
        document.getElementById('acquisition_cost').value = selected.dataset.acqcost || "";
        document.getElementById('last_repair_date').value = selected.dataset.repairdate || "";
    });

    // Sync location from top section to hidden input
    document.getElementById('location_select').addEventListener('change', function () {
        document.getElementById('location_input').value = this.value;
    });

    // Sync remarks from top section to hidden input
    document.getElementById('remarks_textarea').addEventListener('input', function () {
        document.getElementById('remarks_input').value = this.value;
    });

    // Photo preview functionality
    document.getElementById('photo').addEventListener('change', function (e) {
        const file = e.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function (e) {
                const preview = document.getElementById('previewImage');
                preview.src = e.target.result;
                document.getElementById('photoPreview').classList.remove('d-none');
            }
            reader.readAsDataURL(file);
        }
    });

    function removePhoto() {
        document.getElementById('photo').value = '';
        document.getElementById('photoPreview').classList.add('d-none');
        document.getElementById('previewImage').src = '#';
    }

    // File info display
    document.getElementById('attached_file').addEventListener('change', function (e) {
        const file = e.target.files[0];
        const fileInfo = document.getElementById('fileInfo');

        if (file) {
            const fileSize = (file.size / 1024 / 1024).toFixed(2); // MB
            fileInfo.innerHTML = `
            <div class="alert alert-info p-2 mb-0">
                <i class="fas fa-file me-2"></i>
                <strong>${file.name}</strong>
                <span class="text-muted">(${fileSize} MB)</span>
            </div>
        `;
        } else {
            fileInfo.innerHTML = '';
        }
    });

    // Form validation before submission
    document.getElementById('requestForm').addEventListener('submit', function (e) {
        // Sync all values from top section to form
        document.getElementById('location_input').value = document.getElementById('location_select').value;
        document.getElementById('remarks_input').value = document.getElementById('remarks_textarea').value;

        // Validate required fields - only Property Number and Location are required (admin will fill Pre-Repair No and Carrying Amount)
        const location = document.getElementById('location_select').value;
        const propertyNo = document.getElementById('property_number').value;

        if (!location || !propertyNo) {
            e.preventDefault();
            alert('⚠️ Please fill in all required fields (Property Number and Location).');
            return false;
        }

        return true;
    });

    // ✅ VIEW REQUEST DETAILS MODAL LOGIC
    function viewRequestDetails(id) {
        // Show loading state
        const modal = new bootstrap.Modal(document.getElementById('viewDetailsModal'));
        document.getElementById('viewDetailsContent').innerHTML = `
            <div class="text-center py-5">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="mt-2 text-muted">Fetching request details...</p>
            </div>
        `;
        // Hide location badge during loading
        document.getElementById('modalLocationBadge').classList.add('d-none');
        document.getElementById('modalDateBadge').classList.add('d-none');
        modal.show();

        // Fetch details via AJAX
        fetch(`../get_document_simple.php?id=${id}`)
            .then(response => response.text())
            .then(html => {
                document.getElementById('viewDetailsContent').innerHTML = html;

                // Extract location and date from hidden data and display in modal header
                const locationData = document.querySelector('#viewDetailsContent #locationData');
                if (locationData) {
                    // Display location
                    const location = locationData.getAttribute('data-location');
                    if (location) {
                        document.getElementById('modalLocationText').textContent = location;
                        document.getElementById('modalLocationBadge').classList.remove('d-none');
                    }

                    // Display date requested
                    const dateRequested = locationData.getAttribute('data-date-requested');
                    if (dateRequested) {
                        const dateObj = new Date(dateRequested);
                        const formattedDate = dateObj.toLocaleDateString('en-US', {
                            month: 'short',
                            day: 'numeric',
                            year: 'numeric'
                        });
                        document.getElementById('modalDateText').textContent = formattedDate;
                        document.getElementById('modalDateBadge').classList.remove('d-none');
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                document.getElementById('viewDetailsContent').innerHTML = `
                    <div class="alert alert-danger m-3">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        Failed to load details. Please try again.
                    </div>
                `;
            });
    }

    // Archived Documents Search + Pagination
    document.addEventListener('DOMContentLoaded', function () {
        const tableBody = document.getElementById('archivedTableBody');
        const searchInput = document.getElementById('archivedSearch');
        const pagination = document.getElementById('archivedPagination');
        const rowsPerPage = 5;
        let currentPage = 1;

        function renderTable(data, page = 1) {
            tableBody.innerHTML = '';
            const start = (page - 1) * rowsPerPage;
            const paginatedData = data.slice(start, start + rowsPerPage);

            if (paginatedData.length === 0) {
                tableBody.innerHTML = '<tr><td colspan="5">No archived documents found.</td></tr>';
                return;
            }

            paginatedData.forEach(row => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td>${row.pre}</td>
                    <td>${row.location}</td>
                    <td>${row.prop}</td>
                    <td>${row.date}</td>
                    <td><span class="badge bg-secondary">${row.status}</span></td>
                `;
                tableBody.appendChild(tr);
            });

            renderPagination(data.length, page);
        }

        function renderPagination(totalItems, page) {
            pagination.innerHTML = '';
            const totalPages = Math.ceil(totalItems / rowsPerPage);
            for (let i = 1; i <= totalPages; i++) {
                const li = document.createElement('li');
                li.classList.add('page-item');
                if (i === page) li.classList.add('active');
                li.innerHTML = `<a class="page-link" href="#">${i}</a>`;
                li.addEventListener('click', function (e) {
                    e.preventDefault();
                    currentPage = i;
                    renderTable(filteredData, currentPage);
                });
                pagination.appendChild(li);
            }
        }

        let filteredData = archivedData.slice();

        searchInput.addEventListener('input', function () {
            const searchTerm = this.value.toLowerCase();
            filteredData = archivedData.filter(row => row.pre.toLowerCase().includes(searchTerm));
            currentPage = 1;
            renderTable(filteredData, currentPage);
        });

        // Initial render
        renderTable(filteredData, currentPage);
    });



    $(document).ready(function () {
        // Initialize Select2 with custom configuration
        $('#property_number').select2({
            placeholder: "Search Property Number or Description...",
            allowClear: true,
            width: '100%',
            dropdownParent: $('#composeModal'), // Important for modals
            templateResult: formatPropertyOption,
            templateSelection: formatPropertySelection,
            escapeMarkup: function (markup) {
                return markup; // Let our custom formatter work
            },
            minimumResultsForSearch: 1, // Always show search box
            language: {
                noResults: function () {
                    return "No properties found. Try a different search term.";
                },
                searching: function () {
                    return "Searching...";
                },
                inputTooShort: function (args) {
                    return "Please enter at least 2 characters";
                }
            }
        });

        // Custom formatter for dropdown options
        function formatPropertyOption(property) {
            if (!property.id) {
                return property.text;
            }

            var $option = $(
                '<div class="property-option">' +
                '<span class="property-no">' + property.text + '</span>' +
                '</div>'
            );

            // Get additional data
            var description = $(property.element).data('description');
            var designation = $(property.element).data('designation');

            // Add description if available
            if (description && description.trim() !== '') {
                $option.append('<span class="property-details">' + description + '</span>');
            }

            // Add designation if available
            if (designation && designation.trim() !== '') {
                $option.find('.property-details').append(' <span class="property-designation">| ' + designation +
                    '</span>');
            }

            return $option;
        }

        // Custom formatter for selected item
        function formatPropertySelection(property) {
            if (!property.id) {
                return property.text;
            }

            // Show only property number in the selection box
            var propertyNo = property.element.value;
            var description = $(property.element).data('description');
            var displayText = propertyNo;

            if (description && description.trim() !== '') {
                displayText += " - " + description.substring(0, 20) + (description.length > 20 ? '...' : '');
            }

            return displayText;
        }

        // When property is selected, update other fields
        $('#property_number').on('select2:select', function (e) {
            const selected = $(this).find(':selected');
            if (selected.val() === "") return;

            // Update form fields
            $('#description').val(selected.data('description') || "");
            $('#designation').val(selected.data('designation') || "");
            $('#acquisition_date').val(selected.data('acqdate') || "");
            $('#acquisition_cost').val(selected.data('acqcost') || "");
            $('#last_repair_date').val(selected.data('repairdate') || "");
        });

        // Clear other fields when property is cleared
        $('#property_number').on('select2:clear', function (e) {
            $('#description').val("");
            $('#designation').val("");
            $('#acquisition_date').val("");
            $('#acquisition_cost').val("");
            $('#last_repair_date').val("");
        });

        // Handle modal opening to ensure Select2 works properly
        $('#composeModal').on('shown.bs.modal', function () {
            $('#property_number').select2({
                dropdownParent: $('#composeModal .modal-content')
            });
        });
    });

    // Alternative: Vanilla JavaScript version without jQuery (if you prefer)
    document.addEventListener('DOMContentLoaded', function () {
        // If you want a simpler vanilla JS solution, you can use datalist
        // But Select2 provides better UX with search-as-you-type
    });

    // ✅ Photo Enlarge Function (moved to global scope)
    function showPhoto(photoPath) {
        console.log('showPhoto called with path:', photoPath);

        // Find the photo modal at the top level of the DOM
        var photoModal = document.getElementById('photoModal');
        if (!photoModal) {
            console.error('Photo modal not found!');
            return;
        }

        // Create a custom backdrop for the photo modal
        var customBackdrop = document.createElement('div');
        customBackdrop.className = 'custom-photo-backdrop';
        customBackdrop.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.85);z-index:1055;';
        document.body.appendChild(customBackdrop);

        // Store reference to custom backdrop for cleanup
        photoModal.customBackdrop = customBackdrop;

        var myModal = new bootstrap.Modal(photoModal);
        var modalPhoto = document.getElementById('modalPhoto');
        var downloadLink = document.getElementById('downloadPhoto');

        if (modalPhoto) {
            modalPhoto.src = photoPath;
            console.log('Set modal photo src to:', photoPath);
        } else {
            console.error('Modal photo element not found!');
        }

        if (downloadLink) {
            downloadLink.href = photoPath;
            console.log('Set download link href to:', photoPath);
        } else {
            console.error('Download link not found!');
        }

        // Add event listener to clean up custom backdrop when modal closes
        photoModal.addEventListener('hidden.bs.modal', function cleanup() {
            if (photoModal.customBackdrop) {
                document.body.removeChild(photoModal.customBackdrop);
                photoModal.customBackdrop = null;
            }
            // Remove event listener to prevent memory leaks
            photoModal.removeEventListener('hidden.bs.modal', cleanup);
        });

        myModal.show();
        console.log('Modal shown');
    }

    // filter and search

    function applyFilters() {
        // Get current URL parameters
        const urlParams = new URLSearchParams(window.location.search);

        // Update or remove 'status'
        const status = document.getElementById('statusFilter').value;
        if (status === 'All') {
            urlParams.delete('status');
        } else {
            urlParams.set('status', status);
        }

        // Update or remove 'search'
        const search = document.getElementById('searchInput').value.trim();
        if (search === '') {
            urlParams.delete('search');
        } else {
            urlParams.set('search', search);
        }

        // Build new URL (same path, updated params)
        const newUrl = window.location.pathname + (urlParams.toString() ? '?' + urlParams.toString() : '');
        window.location.href = newUrl;
    }
</script>

<!-- ✅ VIEW DETAILS MODAL -->
<div class="modal fade" id="viewDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg rounded-xl overflow-hidden">
            <div class="modal-header bg-gray-50 border-b border-gray-100 px-4 py-3">
                <div class="d-flex align-items-center gap-3">
                    <h5 class="modal-title fs-6 fw-bold text-gray-800 d-flex align-items-center gap-2 mb-0">
                        <i class="bi bi-file-text-fill text-brand-600"></i>
                        Request Specification Details
                    </h5>
                    <span id="modalLocationBadge" class="badge bg-secondary d-none">
                        <i class="bi bi-geo-alt me-1"></i>
                        <span id="modalLocationText"></span>
                    </span>
                    <span id="modalDateBadge" class="badge bg-info text-white d-none">
                        <i class="bi bi-calendar me-1"></i>
                        <span id="modalDateText"></span>
                    </span>
                </div>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0" id="viewDetailsContent">
                <!-- Content loaded via AJAX -->
            </div>
            <div class="modal-footer bg-gray-50 border-t border-gray-100 px-4 py-3">
                <button type="button" class="btn btn-sm btn-outline-secondary px-4 rounded-lg fw-semibold"
                    data-bs-dismiss="modal">Close View</button>
            </div>
        </div>
    </div>
</div>