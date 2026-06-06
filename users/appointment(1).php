<?php
// appointment.php - User appointment request system - AJAX COMPATIBLE VERSION

if (!isset($mysqli) || !$mysqli) {
    require_once __DIR__ . '/../config.php';
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    die("Unauthorized access");
}

$userId = $_SESSION['user_id'];

// Get current user info for auto-fill
$current_username = $_SESSION['username'] ?? '';

// Check if documents table exists and has the required columns for pre-repair data
$documents_result = null;

// Check if equipment table exists and get property numbers for dropdown
$equipment_result = null;
$equipment_query = "SELECT property_no, description, designation, location 
                   FROM equipment 
                   WHERE property_no IS NOT NULL AND property_no != '' 
                   ORDER BY property_no";
$equipment_result = $mysqli->query($equipment_query);

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
    // Sanitize inputs
    $requester_name = $mysqli->real_escape_string($_POST['requester_name'] ?? '');
    $pre_repair_no = $mysqli->real_escape_string($_POST['pre_repair_no'] ?? '');
    $property_no = $mysqli->real_escape_string($_POST['property_no'] ?? '');
    $location = $mysqli->real_escape_string($_POST['location'] ?? '');
    $appointment_date = $mysqli->real_escape_string($_POST['appointment_date'] ?? '');
    $appointment_time = $mysqli->real_escape_string($_POST['appointment_time'] ?? '');
    $remarks = $mysqli->real_escape_string($_POST['remarks'] ?? '');

    // Validate required fields
    if (empty($requester_name) || empty($property_no) || empty($location)) {
        $_SESSION['error_message'] = "Please fill in all required fields.";
        header("Location: user_dashboard.php?view=appointment");
        exit();
    }

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


    // Handle Cancellation Request
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_appointment_id'])) {
        $cancel_id = intval($_POST['cancel_appointment_id']);

        // Security check: Ensure the appointment belongs to the logged-in user
        $cancel_stmt = $mysqli->prepare("UPDATE appointment_requests SET status = 'cancelled' WHERE id = ? AND user_id = ? AND status = 'pending'");

        if ($cancel_stmt) {
            $cancel_stmt->bind_param("ii", $cancel_id, $userId);
            if ($cancel_stmt->execute() && $cancel_stmt->affected_rows > 0) {
                $_SESSION['success_message'] = "Appointment request cancelled successfully.";
            } else {
                $_SESSION['error_message'] = "Could not cancel appointment. It may already be processed.";
            }
            $cancel_stmt->close();
        }

        // Refresh page to show changes
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
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

    // Insert into appointment_requests table using direct query
    $pending_status = 'pending';
    
    $sql = "INSERT INTO appointment_requests 
            (user_id, requester_name, pre_repair_no, property_no, location, appointment_date, appointment_time, remarks, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $mysqli->prepare($sql);
    
    // Insert using direct query to avoid prepared statement issues
    $query = "INSERT INTO appointment_requests 
              (user_id, requester_name, pre_repair_no, property_no, location, appointment_date, appointment_time, remarks, status) 
              VALUES (
                  " . intval($userId) . ",
                  '" . $mysqli->real_escape_string($requester_name) . "',
                  '" . $mysqli->real_escape_string($pre_repair_no) . "',
                  '" . $mysqli->real_escape_string($property_no) . "',
                  '" . $mysqli->real_escape_string($location) . "',
                  '" . $mysqli->real_escape_string($mysql_date) . "',
                  '" . $mysqli->real_escape_string($appointment_time) . "',
                  '" . $mysqli->real_escape_string($remarks) . "',
                  'pending'
              )";
    
    if ($mysqli->query($query)) {
        $_SESSION['success_message'] = "Appointment request submitted successfully!";
    } else {
        $_SESSION['error_message'] = "Failed to submit appointment request: " . $mysqli->error;
    }

    // Redirect to prevent form resubmission
    header("Location: user_dashboard.php?view=appointment");
    exit();

    // Redirect to prevent form resubmission
    header("Location: user_dashboard.php?view=appointment");
    exit();
}




?>


<style>
    /* Enhanced Mobile Styles for Appointment Page */
    
    /* Tom Select Dropdown Styles */
    .ts-dropdown {
        z-index: 1050 !important;
        background-color: #fff !important;
        opacity: 1 !important;
    }
    
    .ts-control {
        border-radius: 0.375rem !important;
        border-color: #dee2e6 !important;
        background-color: #fff !important;
        opacity: 1 !important;
    }
    
    .ts-control:focus {
        border-color: #86b7fe !important;
        box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25) !important;
    }
    
    .ts-dropdown .option {
        background-color: #fff !important;
        opacity: 1 !important;
    }
    
    .ts-dropdown .active {
        background-color: #f0f0f0 !important;
    }

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

    /* Custom Scrollbar for Tailwind Table */
    .custom-scrollbar::-webkit-scrollbar {
        width: 6px;
        height: 6px;
    }

    .custom-scrollbar::-webkit-scrollbar-track {
        background: #f1f5f9;
        /* Slate-100 */
        border-radius: 4px;
    }

    .custom-scrollbar::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        /* Slate-300 */
        border-radius: 4px;
    }

    .custom-scrollbar::-webkit-scrollbar-thumb:hover {
        background: #94a3b8;
        /* Slate-400 */
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
                            style="width: 190px; box-shadow:none;" onchange="renderAppointmentCalendar(this.value)">
                            <option value="">Select Locations</option>
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


<script src="https://cdn.tailwindcss.com"></script>

<div class="row mt-5">
    <div class="col-12">
        <div class="flex items-center justify-between mb-4">
            <h5 class="text-xl font-bold text-slate-800 tracking-tight">
                <i class="fas fa-list-ul mr-2 text-indigo-600"></i>Request History
            </h5>
            <span class="text-sm text-slate-500 bg-slate-100 px-3 py-1 rounded-full">
                Total Requests: <strong><?= count($user_appointments) ?></strong>
            </span>
        </div>

        <div class="bg-white rounded-2xl shadow-lg border border-slate-100 overflow-hidden">

            <?php if (empty($user_appointments)): ?>
                <div class="flex flex-col items-center justify-center py-16 px-4 text-center">
                    <div class="bg-indigo-50 p-4 rounded-full mb-4">
                        <i class="fas fa-calendar-plus text-4xl text-indigo-400"></i>
                    </div>
                    <h3 class="text-lg font-semibold text-slate-800">No requests yet</h3>
                    <p class="text-slate-500 max-w-sm mt-1 mb-6">Create your first appointment request to get started with
                        repairs or maintenance.</p>
                    <button
                        class="px-6 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg transition-all shadow-md hover:shadow-lg"
                        data-bs-toggle="modal" data-bs-target="#appointmentModal">
                        New Request
                    </button>
                </div>
            <?php else: ?>

                <div class="bg-white rounded-2xl shadow-lg border border-slate-100 overflow-hidden">

                    <div class="overflow-y-auto max-h-[700px] custom-scrollbar">

                        <table class="w-full text-left border-collapse relative">
                            <thead>
                                <tr
                                    class="bg-slate-50 border-b border-slate-200 text-xs uppercase tracking-wider text-slate-500 font-bold sticky top-0 z-10 shadow-sm">
                                    <th class="px-4 sm:px-6 py-4">Property No.</th>
                                    <th class="px-4 sm:px-6 py-4 hidden sm:table-cell">Location</th>
                                    <th class="px-4 sm:px-6 py-4">Date & Time</th>
                                    <th class="px-4 sm:px-6 py-4 text-right">Status & Action</th>
                                </tr>
                            </thead>

                            <tbody class="divide-y divide-slate-100">
                                <?php
                                // Define status styles once
                                $status_styles = [
                                    'pending' => ['bg' => 'bg-amber-100', 'text' => 'text-amber-700', 'border' => 'border-amber-200', 'icon' => 'fa-clock'],
                                    'approved' => ['bg' => 'bg-emerald-100', 'text' => 'text-emerald-700', 'border' => 'border-emerald-200', 'icon' => 'fa-check-circle'],
                                    'rejected' => ['bg' => 'bg-rose-100', 'text' => 'text-rose-700', 'border' => 'border-rose-200', 'icon' => 'fa-times-circle'],
                                    'cancelled' => ['bg' => 'bg-slate-100', 'text' => 'text-slate-600', 'border' => 'border-slate-200', 'icon' => 'fa-ban']
                                ];
                                ?>

                                <?php foreach ($user_appointments as $appointment): ?>
                                    <?php
                                    $status = $appointment['status'];
                                    $style = $status_styles[$status] ?? $status_styles['pending'];
                                    $dateFormatted = !empty($appointment['appointment_date']) ? date('M d, Y', strtotime($appointment['appointment_date'])) : 'N/A';
                                    $timeFormatted = !empty($appointment['appointment_time']) ? date('h:i A', strtotime($appointment['appointment_time'])) : 'N/A';
                                    ?>
                                    <tr class="group hover:bg-indigo-50/30 transition-colors duration-200">

                                        <td class="px-4 sm:px-6 py-4 align-top">
                                            <div class="flex flex-col">
                                                <span class="text-sm font-bold text-slate-700 break-words">
                                                    <?= htmlspecialchars($appointment['property_no']) ?>
                                                </span>
                                                <span class="sm:hidden text-xs text-slate-500 mt-1 flex items-center">
                                                    <i class="fas fa-map-marker-alt mr-1 text-slate-300"></i>
                                                    <?= htmlspecialchars($appointment['location']) ?>
                                                </span>
                                            </div>
                                        </td>

                                        <td class="px-4 sm:px-6 py-4 hidden sm:table-cell align-top">
                                            <div class="flex items-center">
                                                <div
                                                    class="w-8 h-8 rounded-full bg-slate-100 flex items-center justify-center mr-3 text-slate-400">
                                                    <i class="fas fa-map-marker-alt text-xs"></i>
                                                </div>
                                                <span class="text-sm font-medium text-slate-600">
                                                    <?= htmlspecialchars($appointment['location']) ?>
                                                </span>
                                            </div>
                                        </td>

                                        <td class="px-4 sm:px-6 py-4 align-top">
                                            <div class="flex flex-col">
                                                <span class="text-sm font-semibold text-slate-700 whitespace-nowrap">
                                                    <?= $dateFormatted ?>
                                                </span>
                                                <span class="text-xs text-slate-500 mt-0.5 flex items-center whitespace-nowrap">
                                                    <i class="far fa-clock mr-1.5 opacity-70"></i>
                                                    <?= $timeFormatted ?>
                                                </span>
                                            </div>
                                        </td>

                                        <td class="px-4 sm:px-6 py-4 text-right align-top">
                                            <div class="flex flex-col items-end gap-2">

                                                <span
                                                    class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] sm:text-xs font-bold uppercase tracking-wide <?= $style['bg'] ?> <?= $style['text'] ?> border <?= $style['border'] ?>">
                                                    <i class="fas <?= $style['icon'] ?> mr-1.5"></i>
                                                    <?= $status ?>
                                                </span>

                                                <div class="flex items-center justify-end gap-1 mt-1">

                                                    <button type="button"
                                                        class="view-appointment-btn p-2 rounded-lg text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 border border-transparent hover:border-indigo-100 transition-all"
                                                        data-id="<?= htmlspecialchars($appointment['id']) ?>"
                                                        data-pre-repair="<?= htmlspecialchars($appointment['pre_repair_no']) ?>"
                                                        data-property="<?= htmlspecialchars($appointment['property_no']) ?>"
                                                        data-location="<?= htmlspecialchars($appointment['location']) ?>"
                                                        data-date="<?= $dateFormatted ?>" data-time="<?= $timeFormatted ?>"
                                                        data-status="<?= htmlspecialchars($appointment['status']) ?>"
                                                        data-created="<?= !empty($appointment['created_at']) ? date('M d, Y h:i A', strtotime($appointment['created_at'])) : 'N/A' ?>"
                                                        data-remarks="<?= htmlspecialchars($appointment['remarks']) ?>"
                                                        title="View Details">
                                                        <i class="fas fa-eye text-lg"></i>
                                                    </button>

                                                    <?php if ($status === 'pending'): ?>
                                                        <form method="POST" action=""
                                                            onsubmit="return confirm('Are you sure you want to cancel this request?');"
                                                            class="inline-block">
                                                            <input type="hidden" name="cancel_appointment_id"
                                                                value="<?= $appointment['id'] ?>">
                                                            <button type="submit"
                                                                class="p-2 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50 border border-transparent hover:border-rose-100 transition-all"
                                                                title="Cancel Request">
                                                                <i class="fas fa-trash-alt text-lg"></i>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>

                                                </div>
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


    <!-- View Appointment Details Modal -->
    <div class="modal fade" id="viewAppointmentModal" tabindex="-1" aria-labelledby="viewAppointmentModalLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content rounded-4 shadow-lg border-0">
                <div class="modal-header bg-primary text-white rounded-top-4">
                    <h5 class="modal-title fw-bold" id="viewAppointmentModalLabel">Appointment Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                        aria-label="Close"></button>
                </div>

                <div class="modal-body p-4">
                    <div class="row mb-3">
                        <div class="col-12 col-sm-6">
                            <strong class="text-secondary small text-uppercase">Property No.</strong>
                            <p id="viewPropertyNo" class="mb-0 fw-bold text-dark">-</p>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-12 col-sm-6 mb-2 mb-sm-0">
                            <strong class="text-secondary small text-uppercase">Location</strong>
                            <p id="viewLocation" class="mb-0 fw-medium">-</p>
                        </div>
                        <div class="col-12 col-sm-6">
                            <strong class="text-secondary small text-uppercase">Status</strong>
                            <div id="viewStatus" class="mt-1">-</div>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-12 col-sm-6 mb-2 mb-sm-0">
                            <strong class="text-secondary small text-uppercase">Date</strong>
                            <p id="viewDate" class="mb-0 fw-medium">-</p>
                        </div>
                        <div class="col-12 col-sm-6">
                            <strong class="text-secondary small text-uppercase">Time</strong>
                            <p id="viewTime" class="mb-0 fw-medium">-</p>
                        </div>
                    </div>

                    <div id="viewStatusAlert" class="mb-3"></div>
                    <div class="border-top my-3"></div>

                    <div class="row mb-3">
                        <div class="col-12">
                            <strong class="text-secondary small text-uppercase">Requested On</strong>
                            <p id="viewCreated" class="mb-0 text-muted">-</p>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-12">
                            <strong class="text-secondary small text-uppercase">Remarks</strong>
                            <div class="bg-light p-3 rounded mt-1 border">
                                <p id="viewRemarks" class="mb-0 text-muted small fst-italic">-</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer bg-light rounded-bottom-4">
                    <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">Close</button>
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
    <div class="modal fade" id="appointmentModal" tabindex="-1" aria-labelledby="appointmentModalLabel"
        aria-hidden="true">
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
                            <!-- Requester Name -->
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Requested by:<span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="requesterName" name="requester_name" value="<?= htmlspecialchars($current_username) ?>" readonly>
                            </div>

                            <!-- Property No. (Dropdown) -->
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Property No. <span class="text-danger">*</span></label>
                                <select class="form-select" id="propertyNoSelect" name="property_no" style="background-color: #fff !important; opacity: 1 !important;" required>
                                    <option value="" selected disabled>Select Property No.</option>
                                    <?php if ($equipment_result && $equipment_result->num_rows > 0): ?>
                                        <?php while ($equip = $equipment_result->fetch_assoc()): ?>
                                            <option value="<?= htmlspecialchars($equip['property_no']) ?>"
                                                    data-description="<?= htmlspecialchars($equip['description'] ?? '') ?>"
                                                    data-designation="<?= htmlspecialchars($equip['designation'] ?? '') ?>"
                                                    data-location="<?= htmlspecialchars($equip['location'] ?? '') ?>">
                                                <?= htmlspecialchars($equip['property_no']) ?>
                                            </option>
                                        <?php endwhile; ?>
                                    <?php endif; ?>
                                </select>
                            </div>

                            <!-- Description (Auto-filled) -->
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Description</label>
                                <input type="text" class="form-control" id="descriptionDisplay" readonly>
                            </div>

                            <!-- Send To (Dropdown) -->
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Send To: <span class="text-danger">*</span></label>
                                <select class="form-select" id="locationSelect" name="location" style="background-color: #fff !important; opacity: 1 !important;" required>
                                    <option value="" selected disabled>Select Maintenance Department</option>
                                    <option value="Mamburao">Mamburao - Maintenance Department</option>
                                    <option value="Sablayan">Sablayan - Maintenance Department</option>
                                    <option value="San Jose">San Jose - Maintenance Department</option>
                                    <option value="Lubang">Lubang - Maintenance Department</option>
                                </select>
                            </div>

                            <!-- Pre-Repair No. (Hidden - filled by admin later) -->
                            <input type="hidden" id="preRepairNoInput" name="pre_repair_no" value="">

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




    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>

    <script>
        // Appointment System - AJAX Compatible Version

        // 1. Define Global Render Function (Matches side_dashboard.php pattern)
        window.renderAppointmentCalendar = function (locationFilter = '') {
            const calendarEl = document.getElementById('appointmentCalendar');
            if (!calendarEl) return;

            // Check FullCalendar
            if (typeof FullCalendar === 'undefined') {
                calendarEl.innerHTML = '<div class="alert alert-danger">Calendar library not loaded. Retrying...</div>';
                setTimeout(() => window.renderAppointmentCalendar(locationFilter), 500); // Retry
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
                    // Destroy existing calendar instance to avoid duplicates (Matches side_dashboard.php)
                    if (window.appointmentCalendarInstance) {
                        window.appointmentCalendarInstance.destroy();
                        window.appointmentCalendarInstance = null;
                    }

                    const isMobile = window.innerWidth < 768;

                    // Create new instance
                    window.appointmentCalendarInstance = new FullCalendar.Calendar(calendarEl, {
                        initialView: isMobile ? 'listMonth' : 'dayGridMonth',
                        height: 'auto',
                        headerToolbar: {
                            left: 'prev,next',
                            center: 'title',
                            right: 'today dayGridMonth,listMonth'
                        },
                        buttonText: {
                            today: 'Today',
                            dayGridMonth: 'Grid',
                            listMonth: 'List'
                        },
                        events: events.map(event => ({
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
                        eventDidMount: function (info) {
                            if (!isMobile && typeof bootstrap !== 'undefined') {
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

                    window.appointmentCalendarInstance.render();
                })
                .catch(error => {
                    console.error('Error loading calendar:', error);
                    calendarEl.innerHTML = '<div class="alert alert-danger">Failed to load calendar data.</div>';
                });
        };

        // 2. Initialize Page Components (Matches initDashboardCharts pattern)
        window.initAppointmentPage = function () {
            console.log('Initializing appointment page...');

            // Calendar Setup
            if (document.getElementById('appointmentCalendar')) {
                window.renderAppointmentCalendar();
            }

            // Date Picker Setup
            if (document.getElementById('appointmentDate')) {
                // Check if flatpickr is loaded, retry if not
                if (typeof flatpickr === 'undefined') {
                    console.log('flatpickr not loaded yet, retrying...');
                    setTimeout(window.initAppointmentPage, 200);
                    return;
                }
                flatpickr("#appointmentDate", {
                    dateFormat: "d/m/Y",
                    defaultDate: "today",
                    minDate: "today",
                    disableMobile: "true",
                    allowInput: true,
                    static: true
                });
            }

            // Searchable Dropdown Setup
            if (document.getElementById('propertyNoSelect')) {
                // Destroy existing instance if it exists to prevent duplication
                const existingSelect = document.getElementById('propertyNoSelect').tomselect;
                if (existingSelect) existingSelect.destroy();

                new TomSelect("#propertyNoSelect", {
                    create: false,
                    sortField: { field: "text", direction: "asc" },
                    placeholder: "Search or Select Property No...",
                    plugins: ['dropdown_input'],
                    maxOptions: 50,
                    onChange: function (value) {
                        var selectElement = document.getElementById('propertyNoSelect');
                        var selectedOption = selectElement.querySelector('option[value="' + CSS.escape(value) + '"]');
                        if (selectedOption) {
                            var description = selectedOption.getAttribute('data-description') || '';
                            var designation = selectedOption.getAttribute('data-designation') || '';
                            // Auto-fill Description field
                            document.getElementById('descriptionDisplay').value = description + (designation ? ' - ' + designation : '');
                        } else {
                            document.getElementById('descriptionDisplay').value = '';
                        }
                    }
                });
            }

            // Initialize Tom Select for Location dropdown
            if (document.getElementById('locationSelect')) {
                new TomSelect("#locationSelect", {
                    create: false,
                    placeholder: "Select Maintenance Department",
                    plugins: ['dropdown_input']
                });
            }

            // Modal Reset Logic
            const modal = document.getElementById('appointmentModal');
            if (modal) {
                modal.addEventListener('show.bs.modal', function () {
                    const propertyNoSelect = document.getElementById('propertyNoSelect');
                    if (propertyNoSelect && propertyNoSelect.tomselect) {
                        propertyNoSelect.tomselect.clear();
                    }
                    document.getElementById('descriptionDisplay').value = '';
                    // Reset location dropdown
                    const locationSelect = document.getElementById('locationSelect');
                    if (locationSelect && locationSelect.tomselect) {
                        locationSelect.tomselect.clear();
                    }
                    // ... add other reset logic if needed
                });
            }

            // Initialize view buttons (Event Delegation)
            document.body.addEventListener('click', function (event) {
                if (event.target.closest('.view-appointment-btn')) {
                    const button = event.target.closest('.view-appointment-btn');
                    showAppointmentDetails(button);
                }
            });
        }

        // 3. Helper Functions (Colors & Details)
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

        // UPDATE: Replace your existing 'showAppointmentDetails' function with this
        window.showAppointmentDetails = function (button) {
            // 1. Get Data
            const propertyNo = button.getAttribute('data-property');
            const location = button.getAttribute('data-location');
            const date = button.getAttribute('data-date');
            const time = button.getAttribute('data-time');
            const status = button.getAttribute('data-status');
            const created = button.getAttribute('data-created');
            const remarks = button.getAttribute('data-remarks');

            // 2. Fill Text Fields
            document.getElementById('viewPropertyNo').textContent = propertyNo || '-';
            document.getElementById('viewLocation').textContent = location || '-';
            document.getElementById('viewDate').textContent = date || '-';
            document.getElementById('viewTime').textContent = time || '-';
            document.getElementById('viewCreated').textContent = created || '-';
            document.getElementById('viewRemarks').textContent = remarks || 'No remarks.';
            document.getElementById('viewAppointmentModalLabel').textContent = 'Appointment Details - ' + (propertyNo || '');

            // 3. Handle Bootstrap Status Badge
            let statusBadge = '';
            switch (status) {
                case 'pending': statusBadge = '<span class="badge bg-warning text-dark">Pending</span>'; break;
                case 'approved': statusBadge = '<span class="badge bg-success">Approved</span>'; break;
                case 'rejected': statusBadge = '<span class="badge bg-danger">Rejected</span>'; break;
                case 'cancelled': statusBadge = '<span class="badge bg-secondary">Cancelled</span>'; break;
                default: statusBadge = '<span class="badge bg-secondary">' + status + '</span>';
            }
            document.getElementById('viewStatus').innerHTML = statusBadge;

            // 4. Handle Tailwind Status Note
            const alertContainer = document.getElementById('viewStatusAlert');

            if (alertContainer) {
                let alertHtml = '';
                if (status === 'pending') {
                    alertHtml = `
                <div class="flex p-3 text-sm text-amber-800 bg-amber-50 rounded-lg border border-amber-200" role="alert">
                    <svg aria-hidden="true" class="flex-shrink-0 inline w-5 h-5 mr-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path></svg>
                    <div>
                        <span class="font-bold">Under Review:</span> We are currently checking availability of maintenace department to handle your request.
                    </div>
                </div>`;
                } else if (status === 'approved') {
                    alertHtml = `
                <div class="flex p-3 text-sm text-emerald-800 bg-emerald-50 rounded-lg border border-emerald-200" role="alert">
                    <svg aria-hidden="true" class="flex-shrink-0 inline w-5 h-5 mr-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg>
                    <div>
                        <span class="font-bold">Confirmed:</span> Please be at the location on time.
                    </div>
                </div>`;
                } else if (status === 'rejected') {
                    alertHtml = `
                <div class="flex p-3 text-sm text-rose-800 bg-rose-50 rounded-lg border border-rose-200" role="alert">
                    <svg aria-hidden="true" class="flex-shrink-0 inline w-5 h-5 mr-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path></svg>
                    <div>
                        <span class="font-bold">Declined:</span> This request may not be the best time for appointment you can make another or contact the maintenance department.
                    </div>
                </div>`;
                }
                alertContainer.innerHTML = alertHtml;
            }

            // 5. Show Modal
            new bootstrap.Modal(document.getElementById('viewAppointmentModal')).show();
        }

        // Show Activity Details (Matches side_dashboard.php logic)
        window.showUserActivityDetails = function (id) {
            const currentPath = window.location.pathname;
            const isInUsersDir = currentPath.includes('/users/') || currentPath.includes('\\users\\');
            const fetchUrl = isInUsersDir ? '../fetch_single_activity.php?id=' + id : 'fetch_single_activity.php?id=' + id;

            // Show loading state
            const modal = document.getElementById('viewActivityModal');
            const modalBody = modal.querySelector('.modal-body');

            // Save original content structure if needed or just rebuild it like side_dashboard does.
            // For simplicity, we assume the modal structure exists and we populate values.

            fetch(fetchUrl)
                .then(r => r.json())
                .then(data => {
                    if (!data || data.error) return alert('Details not found');

                    document.getElementById('activity_type').value = data.activity_type || '';
                    document.getElementById('property_no').value = data.property_no || '';
                    document.getElementById('activity_location').value = data.location || '';
                    document.getElementById('activity_date').value = data.activity_date || '';
                    document.getElementById('activity_time').value = data.time_started || data.activity_time || '';
                    // document.getElementById('activity_remarks').value = data.remarks || ''; // If you have this field

                    new bootstrap.Modal(modal).show();
                })
                .catch(e => console.error("Detail Error:", e));
        }


        // 4. Execution Logic (Auto-run on load)
        document.addEventListener('DOMContentLoaded', function () {
            window.initAppointmentPage();
        });

        // Also try running immediately in case DOM is already ready (AJAX support)
        if (document.readyState === 'complete' || document.readyState === 'interactive') {
            setTimeout(window.initAppointmentPage, 100);
        }

    </script>