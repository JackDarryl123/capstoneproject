<?php
// appointment.php - User appointment request system - AJAX COMPATIBLE VERSION
// session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    die("Unauthorized access");
}

$userId = $_SESSION['user_id'];

// Get database connection from parent file or create new
if (!isset($mysqli) || !$mysqli) {
    // Create new connection if not passed
    $mysqli = new mysqli('localhost', 'root', '', 'user_management');
    if ($mysqli->connect_errno) {
        die("Database connection failed: " . $mysqli->connect_error);
    }
}

// Check if documents table exists and has the required columns for pre-repair data
$documents_result = null;
$documents_query = "SELECT DISTINCT pre_repair_no, property_no, location FROM documents 
                    WHERE pre_repair_no IS NOT NULL AND pre_repair_no != '' 
                    AND property_no IS NOT NULL AND property_no != ''
                    ORDER BY pre_repair_no";
$documents_result = $mysqli->query($documents_query);

if (!$documents_result) {
    echo "<div class='alert alert-warning'>Could not fetch pre-repair data from documents table. Make sure your documents table has pre_repair_no and property_no columns.</div>";
    $documents_result = null;
}

// Initialize user appointments array
$user_appointments = [];

// Check if appointment_requests table exists before querying
$table_check = $mysqli->query("SHOW TABLES LIKE 'appointment_requests'");
if ($table_check && $table_check->num_rows > 0) {
    // Fetch user's appointment requests
    $appointment_stmt = $mysqli->prepare("
        SELECT ar.* 
        FROM appointment_requests ar
        WHERE ar.user_id = ?
        ORDER BY ar.appointment_date DESC, ar.created_at DESC
    ");

    if ($appointment_stmt) {
        $appointment_stmt->bind_param("i", $userId);
        $appointment_stmt->execute();
        $result = $appointment_stmt->get_result();
        $user_appointments = $result->fetch_all(MYSQLI_ASSOC);
        $appointment_stmt->close();
    } else {
        echo "<div class='alert alert-warning'>Could not prepare appointment query: " . $mysqli->error . "</div>";
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_appointment'])) {
    // Sanitize inputs - Updated to use pre_repair_no instead of property_no
    $pre_repair_no = $mysqli->real_escape_string($_POST['pre_repair_no'] ?? '');
    $property_no = $mysqli->real_escape_string($_POST['property_no'] ?? '');
    $location = $mysqli->real_escape_string($_POST['location'] ?? '');
    $appointment_date = $mysqli->real_escape_string($_POST['appointment_date'] ?? '');
    $appointment_time = $mysqli->real_escape_string($_POST['appointment_time'] ?? '');
    $remarks = $mysqli->real_escape_string($_POST['remarks'] ?? '');

    // Convert date to MySQL format (DD/MM/YYYY to YYYY-MM-DD)
    if (!empty($appointment_date)) {
        $date_parts = explode('/', $appointment_date);
        if (count($date_parts) === 3) {
            $mysql_date = $date_parts[2] . '-' . $date_parts[1] . '-' . $date_parts[0];
        } else {
            $mysql_date = date('Y-m-d', strtotime($appointment_date));
        }
    } else {
        $mysql_date = date('Y-m-d');
    }

    // Check if appointment_requests table exists, if not create it
    $table_check = $mysqli->query("SHOW TABLES LIKE 'appointment_requests'");
    if (!$table_check || $table_check->num_rows === 0) {
        // Create appointment_requests table
        $create_table = $mysqli->query("
            CREATE TABLE IF NOT EXISTS appointment_requests (
                id INT PRIMARY KEY AUTO_INCREMENT,
                user_id INT NOT NULL,
                pre_repair_no VARCHAR(100) NOT NULL,
                property_no VARCHAR(100) NOT NULL,
                location VARCHAR(100) NOT NULL,
                appointment_date DATE NOT NULL,
                appointment_time TIME NOT NULL,
                remarks TEXT,
                status ENUM('pending', 'approved', 'rejected', 'cancelled') DEFAULT 'pending',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )
        ");

        if (!$create_table) {
            $_SESSION['error_message'] = "Failed to create appointment_requests table: " . $mysqli->error;
            header("Location: user_dashboard.php?view=appointment");
            exit();
        }
    }

    // Insert into appointment_requests table
    $insert_stmt = $mysqli->prepare("
        INSERT INTO appointment_requests 
        (user_id, pre_repair_no, property_no, location, appointment_date, appointment_time, remarks, status) 
        VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
    ");

    if ($insert_stmt) {
        $insert_stmt->bind_param("issssss", $userId, $pre_repair_no, $property_no, $location, $mysql_date, $appointment_time, $remarks);

        if ($insert_stmt->execute()) {
            $_SESSION['success_message'] = "Appointment request submitted successfully!";
        } else {
            $_SESSION['error_message'] = "Failed to submit appointment request: " . $insert_stmt->error;
        }
        $insert_stmt->close();
    } else {
        $_SESSION['error_message'] = "Database error: " . $mysqli->error;
    }

    // Redirect to prevent form resubmission
    header("Location: user_dashboard.php?view=appointment");
    exit();
}
?>


<style>
    /* Enhanced Mobile Styles for Appointment Page */

    /* Calendar Container */
    #appointmentCalendar {
        margin-top: 15px;
        min-height: 400px;
        overflow: hidden;
    }

    /* Mobile-specific calendar styles */
    @media (max-width: 768px) {

        /* Calendar toolbar optimization */
        .fc .fc-toolbar {
            flex-direction: column;
            gap: 8px;
            padding: 10px 0;
        }

        .fc-toolbar-chunk {
            width: 100%;
            text-align: center;
            margin-bottom: 8px;
        }

        /* Title font size for mobile */
        .fc .fc-toolbar-title {
            font-size: 1.2rem !important;
            margin: 5px 0;
            line-height: 1.2;
            word-break: break-word;
        }

        /* Button styles for mobile */
        .fc .fc-button {
            padding: 0.25rem 0.5rem !important;
            font-size: 0.75rem !important;
            height: auto;
            min-height: 32px;
            margin: 2px;
        }

        .fc .fc-button-group {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 4px;
        }

        /* Event styles for mobile */
        .fc .fc-event {
            font-size: 0.7rem !important;
            padding: 2px 3px !important;
            margin: 1px 0;
            border-radius: 3px;
        }

        .fc .fc-event-title {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            font-weight: 500;
        }

        /* Day cell optimization */
        .fc .fc-daygrid-day-frame {
            min-height: 60px;
        }

        .fc .fc-daygrid-day-number {
            font-size: 0.85rem;
            padding: 3px;
        }

        /* List view optimization */
        .fc .fc-list-event {
            padding: 6px 8px;
        }

        .fc .fc-list-event-time {
            font-size: 0.8rem;
            min-width: 60px;
        }

        .fc .fc-list-event-title {
            font-size: 0.85rem;
        }

        /* Header cell optimization */
        .fc .fc-col-header-cell-cushion {
            font-size: 0.85rem;
            padding: 4px;
        }

        /* Calendar height adjustments */
        .fc .fc-view-harness {
            min-height: 350px;
        }

        /* Modal optimizations */
        #appointmentModal .modal-dialog {
            margin: 10px;
            max-width: calc(100% - 20px);
        }

        #appointmentModal .modal-content {
            border-radius: 12px;
        }

        #appointmentModal .modal-body {
            padding: 15px;
        }

        /* Form field optimizations */
        .form-select,
        .form-control {
            font-size: 0.9rem;
            padding: 0.5rem 0.75rem;
        }

        /* Table optimizations */
        #appointmentTable {
            font-size: 0.85rem;
        }

        #appointmentTable th,
        #appointmentTable td {
            padding: 8px 6px;
            vertical-align: middle;
        }

        #appointmentTable th {
            font-size: 0.8rem;
            white-space: nowrap;
        }

        #appointmentTable .badge {
            font-size: 0.7rem;
            padding: 0.25rem 0.5rem;
        }

        /* Action buttons in table */
        #appointmentTable .btn-sm {
            padding: 0.2rem 0.4rem;
            font-size: 0.8rem;
            min-width: 32px;
        }

        /* Card adjustments */
        .card {
            border-radius: 12px;
            margin-bottom: 15px;
        }

        .card-header {
            padding: 12px 15px;
        }

        .card-body {
            padding: 15px;
        }

        /* Button spacing */
        .d-flex.gap-2 {
            gap: 4px !important;
        }

        /* Hide some columns on very small screens */
        @media (max-width: 576px) {

            #appointmentTable th:nth-child(3),
            /* Location */
            #appointmentTable td:nth-child(3),
            #appointmentTable th:nth-child(7),
            /* Requested On */
            #appointmentTable td:nth-child(7) {
                display: none;
            }

            /* Make action buttons more compact */
            .d-flex.gap-2 {
                flex-direction: column;
                align-items: center;
            }
        }

        /* Ensure no horizontal scroll */
        .main-content {
            padding-left: 10px;
            padding-right: 10px;
        }

        /* Improve button visibility */
        .btn-primary,
        .btn-secondary {
            font-size: 0.9rem;
            padding: 0.4rem 0.8rem;
        }

        /* Loading spinner size */
        .spinner-border {
            width: 2rem;
            height: 2rem;
        }

        /* Tooltip adjustments */
        .tooltip {
            font-size: 0.8rem;
            max-width: 250px;
        }
    }

    /* Tablet specific adjustments */
    @media (min-width: 769px) and (max-width: 991px) {
        .fc .fc-toolbar {
            flex-wrap: wrap;
        }

        .fc-toolbar-chunk {
            flex: 1 0 100%;
            margin-bottom: 5px;
        }

        .fc .fc-button {
            padding: 0.3rem 0.6rem !important;
            font-size: 0.8rem !important;
        }

        #appointmentTable {
            font-size: 0.9rem;
        }
    }

    /* Hover effects only on desktop */
    @media (min-width: 992px) {
        #appointmentCalendar .fc-event:hover {
            opacity: 0.9;
            transform: translateY(-1px);
            transition: all 0.2s;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        #appointmentTable tbody tr:hover {
            background-color: rgba(25, 135, 84, 0.05);
        }
    }

    /* Common improvements for all devices */
    .datepicker {
        font-family: monospace;
    }

    #appointmentTable .badge {
        font-size: 0.75em;
        padding: 0.35em 0.65em;
    }

    /* Loading state improvements */
    .loading-overlay {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(255, 255, 255, 0.9);
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        z-index: 1000;
        border-radius: 12px;
    }

    /* Touch-friendly elements */
    .fc .fc-button,
    .btn,
    .form-select,
    .form-control {
        -webkit-tap-highlight-color: transparent;
        touch-action: manipulation;
    }

    /* Prevent text selection on interactive elements */
    .fc .fc-button,
    .btn {
        user-select: none;
    }

    /* Smooth transitions */
    .fc .fc-button,
    .fc .fc-event,
    .btn {
        transition: all 0.2s ease;
    }

    /* Ensure proper scrolling on mobile */
    .fc .fc-scroller {
        -webkit-overflow-scrolling: touch;
    }

    /* Modal scroll improvements */
    .modal-body {
        max-height: 70vh;
        overflow-y: auto;
    }

    /* Event color coding */
    .fc-event-inspection {
        background-color: #cfd2d9;
        border-color: #0a58ca;
    }

    .fc-event-maintenance {
        background-color: #198754;
        border-color: #146c43;
    }

    .fc-event-appointment {
        background-color: #e8e7e4;
        border-color: #e0a800;
        color: #000;
    }

    /* --- ADD THIS TO YOUR <style> SECTION --- */
    .dashboard-card {
        background: #fff;
        border-radius: 16px;
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
        border: 1px solid #f1f5f9;
        transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1), box-shadow 0.3s ease;
        height: 100%;
        overflow: hidden;
    }

    .dashboard-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 12px 24px rgba(0, 0, 0, 0.08);
        border-color: #e2e8f0;
    }

    /* Color Utilities to match Dashboard */
    .bg-emerald-600 {
        background-color: #059669 !important;
    }

    .text-slate-800 {
        color: #1e293b !important;
    }

    .text-slate-500 {
        color: #64748b !important;
    }

    /* --- Vertical Scrollbar & Sticky Header --- */
    .table-scrollable {
        max-height: 400px;
        /* Adjust this height as needed */
        overflow-y: auto;
        /* Enables vertical scrolling */
        overflow-x: auto;
        /* Keeps horizontal scrolling for mobile */
        border-radius: 8px;
        /* Optional: smooth corners */
    }

    /* Make the table header sticky */
    .table-scrollable thead th {
        position: sticky;
        top: 0;
        background-color: #f8f9fa;
        /* Matches .table-light color */
        z-index: 1;
        /* Ensures header stays on top of content */
        box-shadow: 0 2px 2px -1px rgba(0, 0, 0, 0.1);
        /* Optional: subtle shadow */
    }

    /* Ensures the search dropdown appears ON TOP of the modal */
    .ts-dropdown,
    .ts-control {
        z-index: 9999 !important;
    }

    /* Allows the modal body to show the full dropdown list */
    #appointmentModal .modal-body {
        overflow-y: visible !important;
    }
</style>


<link href="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">


<!-- Main Content -->

<div class="row">
    <div class="col-12 mb-4">
        <div class="dashboard-card p-4">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-center mb-4 gap-3">
                <h6 class="fw-bold text-slate-800 m-0">
                    <i class="fas fa-calendar-alt me-2 text-success"></i>Appointment Calendar
                </h6>

                <div class="d-flex align-items-center gap-2 flex-wrap justify-content-center">
                    <button class="btn btn-success btn-sm bg-emerald-600 border-0 shadow-sm px-3" data-bs-toggle="modal"
                        data-bs-target="#appointmentModal">
                        <i class="fas fa-plus me-1"></i> Request Appointment
                    </button>

                    <div class="d-flex align-items-center gap-2 bg-light rounded px-2 py-1 border">
                        <label class="small fw-bold text-slate-500 mb-0">Location Filter:</label>
                        <select id="calendarLocationFilter" class="form-select form-select-sm border-0 bg-transparent"
                            style="width: 140px; box-shadow:none;" onchange="renderAppointmentCalendar(this.value)">
                            <option value="">All Locations</option>
                            <?php
                            // Fetch distinct locations for the filter
                            $locStmt = $mysqli->prepare("SELECT DISTINCT location FROM activities ORDER BY location ASC");
                            if ($locStmt) {
                                $locStmt->execute();
                                $locRes = $locStmt->get_result();
                                while ($l = $locRes->fetch_assoc()) {
                                    echo '<option value="' . htmlspecialchars($l['location']) . '">' . htmlspecialchars($l['location']) . '</option>';
                                }
                            }
                            ?>
                        </select>
                    </div>
                </div>
            </div>

            <div id="appointmentCalendar" style="min-height: 600px;"></div>
        </div>
    </div>
</div>

<!-- Appointment Requests Table -->
<div class="row mt-4">
    <div class="col-12">
        <h5 class="fw-bold mb-0">My Appointment Requests</h5>
        <div class="card border-0 shadow-sm rounded-4">

            <div class="card-body p-0 p-md-3">
                <?php if (empty($user_appointments)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-calendar-times fa-3x text-muted mb-3"></i>
                        <p class="text-muted">No appointment requests found.</p>
                        <p class="small text-muted">Click "Request Appointment" to create your first request.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive table-scrollable">
                        <table class="table table-hover mb-0" id="appointmentTable">
                            <thead class="table-light">
                                <tr>
                                    <th class="d-none d-sm-table-cell">Pre-Repair No.</th>
                                    <th class="d-table-cell d-sm-table-cell">Property No.</th>
                                    <th class="d-none d-md-table-cell">Location</th>
                                    <th>Date</th>
                                    <th class="d-none d-md-table-cell">Time</th>
                                    <th>Status</th>
                                    <th class="d-none d-lg-table-cell">Requested On</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($user_appointments as $appointment): ?>
                                    <tr>
                                        <td class="d-none d-sm-table-cell fw-medium">
                                            <?= htmlspecialchars($appointment['pre_repair_no']) ?>
                                        </td>
                                        <td class="d-table-cell d-sm-table-cell">
                                            <?= htmlspecialchars($appointment['property_no']) ?>
                                        </td>
                                        <td class="d-none d-md-table-cell">
                                            <span class="badge bg-info bg-opacity-10 text-info">
                                                <?= htmlspecialchars($appointment['location']) ?>
                                            </span>
                                        </td>
                                        <td><?= !empty($appointment['appointment_date']) ? date('d/m/Y', strtotime($appointment['appointment_date'])) : 'N/A' ?>
                                        </td>
                                        <td class="d-none d-md-table-cell">
                                            <?= !empty($appointment['appointment_time']) ? date('h:i A', strtotime($appointment['appointment_time'])) : 'N/A' ?>
                                        </td>
                                        <td>
                                            <?php
                                            $status_badge = [
                                                'pending' => 'bg-warning text-dark',
                                                'approved' => 'bg-success',
                                                'rejected' => 'bg-danger',
                                                'cancelled' => 'bg-secondary'
                                            ];
                                            $status_class = $status_badge[$appointment['status']] ?? 'bg-secondary';
                                            ?>
                                            <span class="badge <?= $status_class ?>">
                                                <?= ucfirst($appointment['status']) ?>
                                            </span>
                                        </td>
                                        <td class="d-none d-lg-table-cell">
                                            <?= !empty($appointment['created_at']) ? date('d/m/Y h:i A', strtotime($appointment['created_at'])) : 'N/A' ?>
                                        </td>
                                        <td>
                                            <div class="d-flex flex-nowrap gap-1">
                                                <button type="button"
                                                    class="btn btn-sm btn-outline-primary view-appointment-btn p-1"
                                                    data-id="<?= htmlspecialchars($appointment['id']) ?>"
                                                    data-pre-repair="<?= htmlspecialchars($appointment['pre_repair_no']) ?>"
                                                    data-property="<?= htmlspecialchars($appointment['property_no']) ?>"
                                                    data-location="<?= htmlspecialchars($appointment['location']) ?>"
                                                    data-date="<?= !empty($appointment['appointment_date']) ? date('d/m/Y', strtotime($appointment['appointment_date'])) : 'N/A' ?>"
                                                    data-time="<?= !empty($appointment['appointment_time']) ? date('h:i A', strtotime($appointment['appointment_time'])) : 'N/A' ?>"
                                                    data-status="<?= htmlspecialchars($appointment['status']) ?>"
                                                    data-created="<?= !empty($appointment['created_at']) ? date('d/m/Y h:i A', strtotime($appointment['created_at'])) : 'N/A' ?>"
                                                    data-remarks="<?= htmlspecialchars($appointment['remarks']) ?>"
                                                    title="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- View Appointment Details Modal -->
<div class="modal fade" id="viewAppointmentModal" tabindex="-1" aria-labelledby="viewAppointmentModalLabel"
    aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4">
            <div class="modal-header bg-primary text-white rounded-top-4">
                <h5 class="modal-title fw-bold" id="viewAppointmentModalLabel">Appointment Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                    aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row mb-3">
                    <div class="col-12 col-sm-6 mb-2 mb-sm-0">
                        <strong>Pre-Repair No.:</strong>
                        <p id="viewPreRepairNo" class="mb-0 text-truncate">-</p>
                    </div>
                    <div class="col-12 col-sm-6">
                        <strong>Property No.:</strong>
                        <p id="viewPropertyNo" class="mb-0 text-truncate">-</p>
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-12 col-sm-6 mb-2 mb-sm-0">
                        <strong>Location:</strong>
                        <p id="viewLocation" class="mb-0 text-truncate">-</p>
                    </div>
                    <div class="col-12 col-sm-6">
                        <strong>Status:</strong>
                        <p id="viewStatus" class="mb-0">-</p>
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-12 col-sm-6 mb-2 mb-sm-0">
                        <strong>Appointment Date:</strong>
                        <p id="viewDate" class="mb-0">-</p>
                    </div>
                    <div class="col-12 col-sm-6">
                        <strong>Appointment Time:</strong>
                        <p id="viewTime" class="mb-0">-</p>
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-12">
                        <strong>Requested On:</strong>
                        <p id="viewCreated" class="mb-0">-</p>
                    </div>
                </div>
                <div class="row">
                    <div class="col-12">
                        <strong>Remarks:</strong>
                        <p id="viewRemarks" class="mb-0 text-muted">-</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light rounded-bottom-4">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Calendar Activity View Modal -->
<div class="modal fade" id="viewActivityModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0">
            <div class="modal-header bg-success text-white rounded-top-4">
                <h5 class="modal-title">Activity Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="small text-muted">Type</label>
                    <input type="text" id="activity_type" class="form-control" disabled>
                </div>
                <div class="mb-3">
                    <label class="small text-muted">Property No.</label>
                    <input type="text" id="property_no" class="form-control" disabled>
                </div>
                <div class="mb-3">
                    <label class="small text-muted">Location</label>
                    <input type="text" id="activity_location" class="form-control" disabled>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-12 col-sm-6">
                        <label class="small text-muted">Date</label>
                        <input type="text" id="activity_date" class="form-control" disabled>
                    </div>
                    <div class="col-12 col-sm-6">
                        <label class="small text-muted">Time</label>
                        <input type="text" id="activity_time" class="form-control" disabled>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="small text-muted">Remarks</label>
                    <textarea id="activity_remarks" class="form-control" rows="3" disabled></textarea>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Appointment Request Modal -->
<div class="modal fade" id="appointmentModal" tabindex="-1" aria-labelledby="appointmentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content rounded-4 border-0">
            <form method="POST" action="">
                <div class="modal-header bg-primary text-white rounded-top-4">
                    <h5 class="modal-title" id="appointmentModalLabel">
                        <i class="fas fa-calendar-plus me-2"></i>Request New Appointment
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                        aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <?php if (isset($_SESSION['success_message'])): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <?= htmlspecialchars($_SESSION['success_message']) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                        <?php unset($_SESSION['success_message']); ?>
                    <?php endif; ?>

                    <?php if (isset($_SESSION['error_message'])): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <?= htmlspecialchars($_SESSION['error_message']) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                        <?php unset($_SESSION['error_message']); ?>
                    <?php endif; ?>

                    <div class="row g-3">
                        <!-- Pre-Repair No. -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Pre-Repair No. <span class="text-danger">*</span></label>
                            <select class="form-select" id="preRepairSelect" name="pre_repair_no" required>
                                <option value="" selected disabled>Select Pre-Repair No.</option>
                                <?php if ($documents_result && $documents_result->num_rows > 0): ?>
                                    <?php while ($doc = $documents_result->fetch_assoc()): ?>
                                        <option value="<?= htmlspecialchars($doc['pre_repair_no']) ?>"
                                            data-property="<?= htmlspecialchars($doc['property_no']) ?>"
                                            data-location="<?= htmlspecialchars($doc['location']) ?>">
                                            <?= htmlspecialchars($doc['pre_repair_no']) ?>
                                        </option>
                                    <?php endwhile; ?>
                                <?php endif; ?>
                            </select>
                            <input type="hidden" id="propertyNoInput" name="property_no">
                        </div>

                        <!-- Property No. (Auto-filled) -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Property No.</label>
                            <input type="text" class="form-control" id="propertyNoDisplay" readonly>
                        </div>

                        <!-- Location (Auto-filled) -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Send To:</label>
                            <input type="text" class="form-control" id="locationDisplay" readonly>
                            <input type="hidden" id="locationInput" name="location">
                        </div>

                        <!-- Date -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Date <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-calendar-alt"></i></span>
                                <input type="text" class="form-control datepicker" name="appointment_date"
                                    id="appointmentDate" placeholder="DD/MM/YYYY" required pattern="\d{2}/\d{2}/\d{4}"
                                    title="Please enter date in DD/MM/YYYY format">
                            </div>
                            <small class="text-muted">Format: DD/MM/YYYY</small>
                        </div>

                        <!-- Time -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Time <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-clock"></i></span>
                                <select class="form-select" name="appointment_time" required>
                                    <option value="" selected disabled>--:-- --</option>
                                    <?php
                                    // Generate time slots from 8:00 AM to 5:00 PM
                                    $start_hour = 8;
                                    $end_hour = 17;
                                    for ($hour = $start_hour; $hour <= $end_hour; $hour++) {
                                        foreach (['00', '30'] as $minute) {
                                            if ($hour == $end_hour && $minute == '30')
                                                continue; // Skip 5:30 PM
                                    
                                            $time_24 = sprintf('%02d:%s', $hour, $minute);
                                            $time_12 = date('h:i A', strtotime($time_24));
                                            echo "<option value='$time_24'>$time_12</option>";
                                        }
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>

                        <!-- Remarks -->
                        <div class="col-12">
                            <label class="form-label fw-bold">Remarks</label>
                            <textarea class="form-control" name="remarks" rows="3"
                                placeholder="Enter additional details..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light rounded-bottom-4">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="request_appointment" class="btn btn-primary">
                        <i class="fas fa-paper-plane me-1"></i> Submit Request
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<!-- Appointment Request Modal -->
<div class="modal fade" id="appointmentModal" tabindex="-1" aria-labelledby="appointmentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content rounded-4 border-0">
            <form method="POST" action="">
                <div class="modal-header bg-primary text-white rounded-top-4">
                    <h5 class="modal-title" id="appointmentModalLabel">
                        <i class="fas fa-calendar-plus me-2"></i>Request New Appointment
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                        aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <?php if (isset($_SESSION['success_message'])): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <?= htmlspecialchars($_SESSION['success_message']) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                        <?php unset($_SESSION['success_message']); ?>
                    <?php endif; ?>

                    <?php if (isset($_SESSION['error_message'])): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <?= htmlspecialchars($_SESSION['error_message']) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                        <?php unset($_SESSION['error_message']); ?>
                    <?php endif; ?>

                    <div class="row g-3">
                        <!-- Pre-Repair No. -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Pre-Repair No. <span class="text-danger">*</span></label>
                            <select class="form-select" id="preRepairSelect" name="pre_repair_no" required>
                                <option value="" selected disabled>Select Pre-Repair No.</option>
                                <?php if ($documents_result && $documents_result->num_rows > 0): ?>
                                    <?php while ($doc = $documents_result->fetch_assoc()): ?>
                                        <option value="<?= htmlspecialchars($doc['pre_repair_no']) ?>"
                                            data-property="<?= htmlspecialchars($doc['property_no']) ?>"
                                            data-location="<?= htmlspecialchars($doc['location']) ?>">
                                            <?= htmlspecialchars($doc['pre_repair_no']) ?>
                                        </option>
                                    <?php endwhile; ?>
                                <?php endif; ?>
                            </select>
                            <input type="hidden" id="propertyNoInput" name="property_no">
                        </div>

                        <!-- Property No. (Auto-filled) -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Property No.</label>
                            <input type="text" class="form-control" id="propertyNoDisplay" readonly>
                        </div>

                        <!-- Location (Auto-filled) -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Send To:</label>
                            <input type="text" class="form-control" id="locationDisplay" readonly>
                            <input type="hidden" id="locationInput" name="location">
                        </div>

                        <!-- Date -->
                        <!-- <div class="col-md-6">
                            <label class="form-label fw-bold">Date <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-calendar-alt"></i></span>
                                <input type="text" class="form-control datepicker" name="appointment_date"
                                    id="appointmentDate" placeholder="DD/MM/YYYY" required pattern="\d{2}/\d{2}/\d{4}"
                                    title="Please enter date in DD/MM/YYYY format">
                            </div>
                            <small class="text-muted">Format: DD/MM/YYYY</small>
                        </div> -->

                        <div class="col-md-6">
                            <label class="form-label fw-bold">Date <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-white text-secondary"><i
                                        class="fas fa-calendar-alt"></i></span>
                                <input type="text" class="form-control bg-white" name="appointment_date"
                                    id="appointmentDate" placeholder="Select Appointment Date.." required>
                            </div>
                        </div>

                        <!-- Time -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Time <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-clock"></i></span>
                                <select class="form-select" name="appointment_time" required>
                                    <option value="" selected disabled>--:-- --</option>
                                    <?php
                                    // Generate time slots from 8:00 AM to 5:00 PM
                                    $start_hour = 8;
                                    $end_hour = 17;
                                    for ($hour = $start_hour; $hour <= $end_hour; $hour++) {
                                        foreach (['00', '30'] as $minute) {
                                            if ($hour == $end_hour && $minute == '30')
                                                continue; // Skip 5:30 PM
                                    
                                            $time_24 = sprintf('%02d:%s', $hour, $minute);
                                            $time_12 = date('h:i A', strtotime($time_24));
                                            echo "<option value='$time_24'>$time_12</option>";
                                        }
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>

                        <!-- Remarks -->
                        <div class="col-12">
                            <label class="form-label fw-bold">Remarks</label>
                            <textarea class="form-control" name="remarks" rows="3"
                                placeholder="Enter additional details..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light rounded-bottom-4">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="request_appointment" class="btn btn-primary">
                        <i class="fas fa-paper-plane me-1"></i> Submit Request
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>


<script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js'></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>

<!-- JavaScript - AJAX Compatible Version -->
<script>
    // Appointment System - AJAX Compatible Version

    // Global variables
    let appointmentCalendar = null;

    // Initialize when content is loaded via AJAX
    function initAppointmentPage() {
        console.log('Initializing appointment page...');

        // Auto-fill Property No and Location when Pre-Repair No is selected
        const preRepairSelect = document.getElementById('preRepairSelect');
        if (preRepairSelect) {
            preRepairSelect.addEventListener('change', function () {
                updateAppointmentFields();
            });
        }

        // Date formatting for DD/MM/YYYY
        if (document.getElementById('appointmentDate')) {
            flatpickr("#appointmentDate", {
                dateFormat: "d/m/Y",   // Matches your PHP format
                defaultDate: "today",  // Sets the input to today automatically
                minDate: "today",      // Prevents booking in the past
                disableMobile: "true", // Ensures the nice calendar shows on phones too
                allowInput: true       // Allows user to type if they prefer
            });
        }
        // Reset modal fields when opened
        const modal = document.getElementById('appointmentModal');
        if (modal) {
            modal.addEventListener('show.bs.modal', function () {
                // Reset form
                if (preRepairSelect) preRepairSelect.selectedIndex = 0;
                document.getElementById('propertyNoDisplay').value = '';
                document.getElementById('propertyNoInput').value = '';
                document.getElementById('locationDisplay').value = '';
                document.getElementById('locationInput').value = '';

                // Reset time select
                const timeSelect = document.querySelector('select[name="appointment_time"]');
                if (timeSelect) timeSelect.selectedIndex = 0;

                // Reset remarks
                const remarks = document.querySelector('textarea[name="remarks"]');
                if (remarks) remarks.value = '';
            });
        }

        // Initialize appointment view buttons
        initAppointmentViewButtons();

        // Initialize calendar
        renderAppointmentCalendar();

        // Set up window resize handler
        setupResizeHandler();
    }

    // Function to update fields based on pre-repair selection
    function updateAppointmentFields() {
        const select = document.getElementById('preRepairSelect');
        if (!select) return;

        const selectedOption = select.options[select.selectedIndex];

        if (selectedOption && selectedOption.value) {
            // Get data attributes
            const propertyNo = selectedOption.getAttribute('data-property') || '';
            const location = selectedOption.getAttribute('data-location') || '';

            // Update display fields
            document.getElementById('propertyNoDisplay').value = propertyNo;
            document.getElementById('locationDisplay').value = location;

            // Update hidden inputs
            document.getElementById('propertyNoInput').value = propertyNo;
            document.getElementById('locationInput').value = location;
        } else {
            // Clear fields
            document.getElementById('propertyNoDisplay').value = '';
            document.getElementById('propertyNoInput').value = '';
            document.getElementById('locationDisplay').value = '';
            document.getElementById('locationInput').value = '';
        }
    }

    // Initialize appointment view buttons
    function initAppointmentViewButtons() {
        // Use event delegation for dynamically loaded buttons
        document.addEventListener('click', function (event) {
            if (event.target.closest('.view-appointment-btn')) {
                const button = event.target.closest('.view-appointment-btn');
                showAppointmentDetails(button);
            }
        });
    }

    // Show appointment details in modal
    function showAppointmentDetails(button) {
        const preRepairNo = button.getAttribute('data-pre-repair');
        const propertyNo = button.getAttribute('data-property');
        const location = button.getAttribute('data-location');
        const date = button.getAttribute('data-date');
        const time = button.getAttribute('data-time');
        const status = button.getAttribute('data-status');
        const created = button.getAttribute('data-created');
        const remarks = button.getAttribute('data-remarks');

        // Populate modal fields
        document.getElementById('viewPreRepairNo').textContent = preRepairNo || '-';
        document.getElementById('viewPropertyNo').textContent = propertyNo || '-';
        document.getElementById('viewLocation').textContent = location || '-';
        document.getElementById('viewDate').textContent = date || '-';
        document.getElementById('viewTime').textContent = time || '-';

        // Format status with appropriate badge
        let statusBadge = '';
        switch (status) {
            case 'pending':
                statusBadge = '<span class="badge bg-warning">Pending</span>';
                break;
            case 'approved':
                statusBadge = '<span class="badge bg-success">Approved</span>';
                break;
            case 'rejected':
                statusBadge = '<span class="badge bg-danger">Rejected</span>';
                break;
            case 'cancelled':
                statusBadge = '<span class="badge bg-secondary">Cancelled</span>';
                break;
            default:
                statusBadge = '<span class="badge bg-secondary">' + (status ? status.charAt(0).toUpperCase() + status.slice(
                    1) : 'Unknown') + '</span>';
        }
        document.getElementById('viewStatus').innerHTML = statusBadge;

        document.getElementById('viewCreated').textContent = created || '-';
        document.getElementById('viewRemarks').textContent = remarks || 'No remarks';

        // Update modal title
        document.getElementById('viewAppointmentModalLabel').textContent =
            'Appointment Details - ' + (preRepairNo || '');

        // Show the modal
        const modal = new bootstrap.Modal(document.getElementById('viewAppointmentModal'));
        modal.show();
    }

    // Setup resize handler for calendar
    function setupResizeHandler() {
        let resizeTimer;
        window.addEventListener('resize', function () {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function () {
                if (appointmentCalendar) {
                    const isMobile = window.innerWidth < 768;
                    const newView = isMobile ? 'listMonth' : 'dayGridMonth';

                    if (appointmentCalendar.view.type !== newView) {
                        appointmentCalendar.changeView(newView);
                    }
                    appointmentCalendar.render();
                }
            }, 250);
        });
    }

    // Appointment Calendar with Dashboard Design & Filter Support
    function renderAppointmentCalendar(locationFilter = '') {
        const calendarEl = document.getElementById('appointmentCalendar');
        if (!calendarEl) return;

        // Check FullCalendar
        if (typeof FullCalendar === 'undefined') {
            calendarEl.innerHTML = '<div class="alert alert-danger">Calendar library not loaded.</div>';
            return;
        }

        // Determine path to fetch_activities.php
        const currentPath = window.location.pathname;
        const isInUsersDir = currentPath.includes('/users/') || currentPath.includes('\\users\\');
        let fetchUrl = isInUsersDir ? '../fetch_activities.php' : 'fetch_activities.php';

        // Apply Filter if selected
        if (locationFilter) {
            fetchUrl += '?location=' + encodeURIComponent(locationFilter);
        }

        // Fetch activities
        fetch(fetchUrl)
            .then(r => {
                if (!r.ok) throw new Error('Network response was not ok');
                return r.json();
            })
            .then(events => {
                // Destroy existing calendar if re-rendering
                if (appointmentCalendar) {
                    appointmentCalendar.destroy();
                }

                const isMobile = window.innerWidth < 768;

                appointmentCalendar = new FullCalendar.Calendar(calendarEl, {
                    initialView: isMobile ? 'listMonth' : 'dayGridMonth',
                    height: 'auto',
                    // Updated Toolbar to match Dashboard
                    headerToolbar: {
                        left: 'prev,next',
                        center: 'title',
                        right: 'today dayGridMonth,listMonth'
                    },
                    // Set Initial Date (Optional: Remove this line to default to today)
                    initialDate: '2025-11-01',
                    buttonText: {
                        today: 'Today',
                        dayGridMonth: 'Grid',
                        listMonth: 'List'
                    },
                    events: events.map(event => ({
                        // ... (Keep your existing mapping logic here) ...
                        id: event.id,
                        title: event.title || 'Activity',
                        start: event.start,
                        end: event.end || event.start,
                        extendedProps: {
                            location: event.location || '',
                            remarks: event.remarks || '',
                            property_no: event.property_no || '',
                            activity_type: event.activity_type || ''
                        },
                        backgroundColor: getEventColor(event.activity_type),
                        borderColor: getEventBorderColor(event.activity_type),
                        textColor: event.activity_type === 'Appointment' ? '#000' : '#fff'
                    })),
                    eventClick: function (info) {
                        showUserActivityDetails(info.event.id);
                    },
                    // Keep your existing eventDidMount for tooltips
                    eventDidMount: function (info) {
                        // ... (Keep your existing tooltip logic) ...
                        // If you want to use the exact tooltip code from before, 
                        // ensure you copy the logic from your previous code block here.
                        if (!isMobile && typeof bootstrap !== 'undefined') {
                            // (Your tooltip code goes here)
                            new bootstrap.Tooltip(info.el, {
                                title: `<strong>${info.event.title}</strong><br>Location: ${info.event.extendedProps.location}`,
                                html: true,
                                placement: 'top',
                                trigger: 'hover',
                                container: 'body'
                            });
                        }
                    }
                });

                appointmentCalendar.render();
            })
            .catch(error => {
                console.error('Error loading calendar:', error);
                calendarEl.innerHTML = '<div class="alert alert-danger">Failed to load calendar data.</div>';
            });
    }


    // Helper function to assign colors based on activity type
    function getEventColor(activityType) {
        if (!activityType) return '#6c757d';

        const colors = {
            'Inspection': '#0d6efd',
            'Maintenance/Repair': '#198754',
            'Appointment': '#ffc107',
            'Meeting': '#6f42c1',
            'Training': '#fd7e14'
        };

        return colors[activityType] || '#6c757d';
    }

    function getEventBorderColor(activityType) {
        if (!activityType) return '#495057';

        const colors = {
            'Inspection': '#0a58ca',
            'Maintenance/Repair': '#146c43',
            'Appointment': '#e0a800',
            'Meeting': '#59359a',
            'Training': '#dc650f'
        };

        return colors[activityType] || '#495057';
    }

    // Function to show activity details in modal
    function showUserActivityDetails(id) {
        console.log('Fetching activity details for ID:', id);

        // Fix for AJAX path
        const currentPath = window.location.pathname;
        const isInUsersDir = currentPath.includes('/users/') || currentPath.includes('\\users\\');
        const fetchUrl = isInUsersDir ?
            '../fetch_single_activity.php?id=' + id :
            'fetch_single_activity.php?id=' + id;

        // Show loading state
        const modal = document.getElementById('viewActivityModal');
        const modalBody = modal.querySelector('.modal-body');
        const originalContent = modalBody.innerHTML;
        modalBody.innerHTML = `
        <div class="text-center p-4">
            <div class="spinner-border text-primary"></div>
            <p class="mt-2">Loading details...</p>
        </div>
    `;

        // Fetch single activity details
        fetch(fetchUrl)
            .then(r => {
                if (!r.ok) throw new Error('Network response was not ok');
                return r.json();
            })
            .then(data => {
                console.log('Activity details:', data);

                if (!data || !data.id) {
                    throw new Error('No data returned');
                }

                // Populate modal fields
                document.getElementById('activity_type').value = data.activity_type || '';
                document.getElementById('property_no').value = data.property_no || '';
                document.getElementById('activity_location').value = data.location || '';
                document.getElementById('activity_date').value = data.activity_date || '';
                document.getElementById('activity_time').value = data.time_started || data.activity_time || '';
                document.getElementById('activity_remarks').value = data.remarks || '';

                // Show the modal
                const bsModal = new bootstrap.Modal(modal);
                bsModal.show();
            })
            .catch(error => {
                console.error('Error loading activity details:', error);

                // Restore original content
                modalBody.innerHTML = originalContent;

                // Show error message
                const errorAlert = document.createElement('div');
                errorAlert.className = 'alert alert-danger';
                errorAlert.innerHTML = `
                <i class="fas fa-exclamation-triangle me-2"></i>
                Failed to load activity details. Please try again.
            `;
                modalBody.prepend(errorAlert);

                // Show the modal
                const bsModal = new bootstrap.Modal(modal);
                bsModal.show();
            });
    }

    // Initialize when DOM is ready
    document.addEventListener('DOMContentLoaded', initAppointmentPage);

    // Also initialize if loaded via AJAX (DOMContentLoaded might have already fired)
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAppointmentPage);
    } else {
        // DOM already loaded, initialize immediately
        setTimeout(initAppointmentPage, 100);
    }

    // Clean up when navigating away (for AJAX)
    document.addEventListener('ajaxPageChange', function () {
        if (appointmentCalendar) {
            appointmentCalendar.destroy();
            appointmentCalendar = null;
        }
    });

    // Handle orientation changes
    window.addEventListener('orientationchange', function () {
        setTimeout(() => {
            if (appointmentCalendar) {
                appointmentCalendar.render();
            }
        }, 300);
    });

    // Touch-friendly improvements for mobile
    document.addEventListener('touchstart', function () { }, {
        passive: true
    });

    // Prevent zoom on double-tap for buttons
    document.querySelectorAll('button, .fc-button').forEach(button => {
        button.addEventListener('touchstart', function (e) {
            if (e.touches.length > 1) {
                e.preventDefault();
            }
        }, {
            passive: false
        });
    });

    // Auto-initialize when script loads (for AJAX)
    setTimeout(function () {
        if (document.getElementById('appointmentCalendar') && !isInitialized) {
            console.log('Auto-initializing appointment calendar...');
            initAppointmentPage();
        }
    }, 500);

    document.addEventListener("DOMContentLoaded", function () {
        // Initialize the Searchable Dropdown
        new TomSelect("#preRepairSelect", {
            create: false,
            sortField: {
                field: "text",
                direction: "asc"
            },
            placeholder: "Search or Select Pre-Repair No...",

            // This function runs every time you select an option
            onChange: function (value) {
                // 1. Find the original <select> element
                var selectElement = document.getElementById('preRepairSelect');

                // 2. Find the specific <option> that was selected to get its data attributes
                // We use CSS escaping to handle values safely
                var selectedOption = selectElement.querySelector('option[value="' + CSS.escape(value) + '"]');

                if (selectedOption) {
                    // 3. Get the data stored in the PHP data-property and data-location attributes
                    var propertyNo = selectedOption.getAttribute('data-property') || '';
                    var location = selectedOption.getAttribute('data-location') || '';

                    // 4. Fill the visible text boxes
                    document.getElementById('propertyNoDisplay').value = propertyNo;
                    document.getElementById('locationDisplay').value = location;

                    // 5. Fill the hidden inputs (for the database)
                    document.getElementById('propertyNoInput').value = propertyNo;
                    document.getElementById('locationInput').value = location;
                } else {
                    // Clear fields if selection is cleared
                    document.getElementById('propertyNoDisplay').value = '';
                    document.getElementById('locationDisplay').value = '';
                    document.getElementById('propertyNoInput').value = '';
                    document.getElementById('locationInput').value = '';
                }
            }
        });
    });
</script>