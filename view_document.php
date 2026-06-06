<?php
require_once __DIR__ . '/config.php';
require_once 'includes/session_helper.php';
require_once 'includes/mail_helper.php';
start_user_session();

// Store the original source in session to preserve it
if (isset($_GET['source'])) {
    $_SESSION['original_source'] = $_GET['source'];
}

// Debug: Check what's being posted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("POST data received: " . print_r($_POST, true));
    error_log("Files data: " . print_r($_FILES, true));
}

$id = $_GET['id'] ?? 0;
$source = $_GET['source'] ?? 'qr';

// ✅ Handle "Add to Maintenance" button
if (isset($_POST['add_to_maintenance'])) {
    $date_done = date('Y-m-d');
    $stmt = $mysqli->prepare("UPDATE documents SET status = 'Done', date_done = ? WHERE id = ?");
    $stmt->bind_param("si", $date_done, $id);

    if ($stmt->execute()) {
        header("Location: /admin_dashboard.php?view=documents");
        exit();
    } else {
        echo "<div class='alert alert-danger text-center'>❌ Failed to add document to maintenance.</div>";
    }
    $stmt->close();
}

// ✅ Handle "Archive" button
if (isset($_POST['archive'])) {
    $stmt = $mysqli->prepare("UPDATE documents SET status = 'Archived' WHERE id = ?");
    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {
        header("Location: /admin_dashboard.php?view=documents");
        exit();
    } else {
        echo "<div class='alert alert-danger text-center'>❌ Failed to archive document.</div>";
    }
    $stmt->close();
}

// ✅ Handle "Complete" button
if (isset($_POST['complete'])) {
    error_log("Complete button clicked for document ID: $id");

    // Get current date in YYYY-MM-DD format
    $date_completed = date('Y-m-d');

    // Update both status and date_completed
    $stmt = $mysqli->prepare("UPDATE documents SET status = 'Complete', date_completed = ? WHERE id = ?");
    $stmt->bind_param("si", $date_completed, $id);

    if ($stmt->execute()) {
        // Also update the equipment table if needed (optional)
        $equipment_stmt = $mysqli->prepare("
            UPDATE equipment e 
            JOIN documents d ON e.property_no = d.property_no 
            SET e.status = 'Operational', e.last_repair_date = ? 
            WHERE d.id = ?
        ");
        $equipment_stmt->bind_param("si", $date_completed, $id);
        $equipment_stmt->execute();
        $equipment_stmt->close();

        // Send email notification to user
        sendRepairCompleteEmail($id, $mysqli);

        header("Location: /admin_dashboard.php?view=maintenance");
        exit();
    } else {
        echo "<div class='alert alert-danger text-center'>❌ Failed to mark as complete.</div>";
    }
    $stmt->close();
}

// ✅ Handle form submission (add or update)
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    !isset($_POST['add_to_maintenance']) &&
    !isset($_POST['archive']) &&
    !isset($_POST['complete']) &&
    !isset($_POST['supply_location']) &&
    basename($_SERVER['PHP_SELF']) === 'view_document.php'
) {

    $params = [
        $_POST['inspector_name'] ?? '',
        $_POST['inspector_position'] ?? '',
        $_POST['defect'] ?? '',
        $_POST['findings'] ?? '',
        $_POST['recommendation'] ?? '',
        $_POST['carrying_amount'] ?? '0.00',
        $_POST['officer_name'] ?? '',
        $_POST['inspected_by'] ?? '',
        $_POST['inspected_by_sig'] ?? '',
        $_POST['approved_by_pepo'] ?? '',
        $_POST['approved_by_pepo_sig'] ?? '',
        $_POST['witnessed_by'] ?? '',
        $_POST['witnessed_by_sig'] ?? '',
        $_POST['approved_by_gso'] ?? '',
        $_POST['approved_by_gso_sig'] ?? ''
    ];

    $isAdmin = ($_SESSION['role'] ?? '') === 'admin';
    if ($isAdmin) {
        $params[] = $_POST['admin_note'] ?? '';
    }

    for ($i = 1; $i <= 10; $i++) {
        $params[] = $_POST["material_$i"] ?? '';
        $params[] = $_POST["quantity_$i"] ?? '';
    }

    $check = $mysqli->prepare("SELECT COUNT(*) FROM documents WHERE id = ?");
    $check->bind_param("i", $id);
    $check->execute();
    $check->bind_result($exists);
    $check->fetch();
    $check->close();

    if ($exists) {
        $sql = "UPDATE documents SET 
            inspector_name=?, inspector_position=?, defect=?, findings=?, recommendation=?, carrying_amount=?,
            officer_name=?, inspected_by=?, inspected_by_sig=?, 
            approved_by_pepo=?, approved_by_pepo_sig=?, 
            witnessed_by=?, witnessed_by_sig=?, 
            approved_by_gso=?, approved_by_gso_sig=?, ";

        if ($isAdmin) {
            $sql .= "admin_note=?, ";
        }

        $sql .= implode(', ', array_map(fn($i) => "material_$i=?, quantity_$i=?", range(1, 10))) . "
            WHERE id=?";

        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            die("SQL Error: " . $mysqli->error);
        }

        $update_params = $params;
        $update_params[] = $id;

        $types = str_repeat('s', count($params)) . 'i';
        $stmt->bind_param($types, ...$update_params);
        $stmt->execute();
        $stmt->close();
        echo "<div class='alert alert-success text-center'>✅ Document updated successfully!</div>";
    }
}

// ✅ Fetch document details
$stmt = $mysqli->prepare("
    SELECT d.*, e.description, e.designation, e.acquisition_date, e.acquisition_cost, e.last_repair_date
    FROM documents d
    LEFT JOIN equipment e ON d.property_no = e.property_no
    WHERE d.id = ?
");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$doc = $result->fetch_assoc();
$stmt->close();

// ✅ Safe & Professional Signature Fetching
$curr_user_id = $_SESSION['user_id'] ?? 0;
$my_signature = '';
$my_name = $_SESSION['username'] ?? 'Unauthorized User';

if ($curr_user_id > 0) {
    $sig_stmt = $mysqli->prepare("SELECT signature FROM users WHERE id = ?");
    $sig_stmt->bind_param("i", $curr_user_id);
    $sig_stmt->execute();
    $sig_res = $sig_stmt->get_result();

    if ($user_row = $sig_res->fetch_assoc()) {
        $db_path = $user_row['signature'] ?? '';
        if (!empty($db_path)) {
            $my_signature = '/' . ltrim($db_path, '/');
        }
    }
    $sig_stmt->close();
}

// ✅ Build status timeline based on document status
$status_steps = [
    'Pending' => [
        'date' => $doc['date_requested'] ?? null,
        'label' => 'Received Request',
        'description' => 'Requested by Property Custodian'
    ],
    'Approved' => [
        'date' => $doc['date_approved'] ?? null,
        'label' => 'Request Approval',
        'description' => 'Request approved by PGDH-GSO'
    ],
    'Done' => [
        'date' => $doc['date_done'] ?? null,
        'label' => 'Requests Verification',
        'description' => 'The request has passed verification and inspection'
    ],
    'Complete' => [
        'date' => $doc['date_completed'] ?? null,
        'label' => 'Repair/Maintenance',
        'description' => 'Done with repair and maintenance equipment is now fully operational'
    ]
];

$current_status = $doc['status'] ?? 'Pending';
$status_to_index = [
    'Pending' => 0,
    'Approved' => 2,
    'Done' => 3,
    'Complete' => 3
];
$current_index = $status_to_index[$current_status] ?? 0;

// Display success/error messages
if (isset($_SESSION['success'])) {
    echo '<div class="alert alert-success alert-dismissible fade show text-center mx-auto" role="alert" style="max-width: 80%;">
            <i class="bi bi-check-circle"></i> ' . $_SESSION['success'] . '
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>';
    unset($_SESSION['success']);
}

if (isset($_SESSION['error'])) {
    echo '<div class="alert alert-danger alert-dismissible fade show text-center mx-auto" role="alert" style="max-width: 80%;">
            <i class="bi bi-exclamation-triangle"></i> ' . $_SESSION['error'] . '
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>';
    unset($_SESSION['error']);
}
?>

<!DOCTYPE html>
<html lang='en'>

<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>View Document - Admin</title>
    <link href='https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css' rel='stylesheet'>
    <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css' rel='stylesheet'>
    <style>
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #e4edf5 100%);
            min-height: 100vh;
            padding: 20px;
        }

        /* Page Header Styles */
        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: white;
            padding: 15px 25px;
            border-radius: 16px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .btn-back-header {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background: #f8f9fa;
            color: #495057;
            text-decoration: none;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.3s ease;
            border: 1px solid #e9ecef;
        }

        .btn-back-header:hover {
            background: #e9ecef;
            color: #212529;
            transform: translateX(-3px);
        }

        .page-title {
            display: flex;
            align-items: center;
            gap: 15px;
            flex: 1;
            justify-content: center;
        }

        .page-title h1 {
            font-size: 1.4rem;
            font-weight: 700;
            color: #1f2937;
            margin: 0;
        }

        .property-badge {
            background: linear-gradient(135deg, #0d6efd 0%, #0b5ed7 100%);
            color: white;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
        }

        .status-badge {
            padding: 8px 20px;
            border-radius: 20px;
            font-weight: 700;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
            border: 2px solid #ffc107;
        }

        .status-approved {
            background: #cfe2ff;
            color: #084298;
            border: 2px solid #0d6efd;
        }

        .status-done {
            background: #d1e7dd;
            color: #0f5132;
            border: 2px solid #198754;
        }

        .status-complete {
            background: #d1e7dd;
            color: #0f5132;
            border: 2px solid #198754;
        }

        /* Timeline in Header */
        .header-timeline {
            display: flex;
            align-items: center;
            gap: 0;
            background: #f8f9fa;
            padding: 12px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            justify-content: center;
        }

        .header-timeline-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 0 15px;
            position: relative;
        }

        .header-timeline-item:not(:last-child)::after {
            content: '';
            position: absolute;
            right: -5px;
            top: 50%;
            transform: translateY(-50%);
            width: 20px;
            height: 2px;
            background: #dee2e6;
        }

        .header-timeline-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #dee2e6;
        }

        .header-timeline-dot.completed {
            background: #198754;
        }

        .header-timeline-dot.current {
            background: #0d6efd;
            animation: pulse 1.5s infinite;
        }

        @keyframes pulse {

            0%,
            100% {
                box-shadow: 0 0 0 0 rgba(13, 110, 253, 0.4);
            }

            50% {
                box-shadow: 0 0 0 6px rgba(13, 110, 253, 0);
            }
        }

        .header-timeline-label {
            font-size: 0.85rem;
            font-weight: 600;
            color: #6c757d;
        }

        .header-timeline-label.completed {
            color: #198754;
        }

        .header-timeline-label.current {
            color: #0d6efd;
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

        .printed-signature {
            mix-blend-mode: multiply;
            filter: contrast(1.5) brightness(1.1);
            max-height: 80px !important;
        }

        @media print {
            @page {
                size: 8.5in 14in;
                margin: 0.3in;
            }

            body * {
                visibility: hidden;
            }

            .printable-area,
            .printable-area * {
                visibility: visible;
            }

            .printable-area {
                position: absolute;
                left: 0;
                top: 0;
                width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
                box-shadow: none !important;
                border: none !important;
            }

            .printable-area h5 {
                font-size: 11pt !important;
                margin: 2px 0 !important;
            }

            .printable-area p,
            .printable-area label,
            .printable-area small,
            .printable-area td,
            .printable-area th {
                font-size: 8.5pt !important;
            }

            .printable-area .form-control {
                font-size: 8.5pt !important;
                padding: 0px 4px !important;
                min-height: 0 !important;
            }

            .bg-success {
                background-color: #198754 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            .text-white {
                color: white !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            .printable-area {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            .printable-area header img {
                height: 50px !important;
                width: auto !important;
            }

            .printable-area header {
                margin-bottom: 10px !important;
            }

            .table {
                margin-bottom: 5px !important;
            }

            .table th,
            .table td {
                padding: 1px 4px !important;
            }

            .mb-4 {
                margin-bottom: 8px !important;
            }

            .mt-5 {
                margin-top: 10px !important;
            }

            .mb-3 {
                margin-bottom: 5px !important;
            }

            .no-print {
                display: none !important;
            }

            .floating-buttons {
                display: none !important;
            }

            /* Property Custodian Officer Signature - Print */
            .signature-container {
                position: relative !important;
                width: auto !important;
                min-width: 200px !important;
            }

            /* Keep absolute positioning for overlap effect */
            .signature-wrapper {
                position: absolute !important;
                bottom: 18px !important;
                left: 0 !important;
                right: 0 !important;
                z-index: 10 !important;
                display: flex !important;
                justify-content: center !important;
                pointer-events: none !important;
            }

            /* Signature image sizing */
            .signature-img {
                height: 55px !important;
                width: auto !important;
                mix-blend-mode: multiply !important;
                filter: contrast(1.3) !important;
            }

            /* Input field styling for print */
            .formal-signature-input {
                font-size: 9pt !important;
                border: none !important;
                border-bottom: 1px solid #000 !important;
                background: transparent !important;
                color: #000 !important;
                padding-bottom: 2px !important;
                font-weight: bold !important;
            }

            /* Ensure small label shows */
            .signature-container small {
                font-size: 8pt !important;
                color: #333 !important;
            }
        }


        .sig-img {
            height: 60px;
            width: auto;
            mix-blend-mode: multiply;
            filter: contrast(1.2);
            display: block;
            margin: 0 auto -15px auto;
        }

        .signature-trigger {
            cursor: pointer;
            background-color: #f8f9fa !important;
            border-bottom: 2px solid #000 !important;
            border-top: none;
            border-left: none;
            border-right: none;
        }

        /* New Layout Styles */
        .document-container {
            display: flex;
            gap: 30px;
            align-items: stretch;
            min-height: 100%;
        }

        .document-viewer {
            flex: 1;
            background: white;
            border-radius: 20px;
            box-shadow: 0 12px 35px rgba(0, 0, 0, 0.08);
            padding: 30px;
            overflow-y: auto;
            max-height: 80vh;
            border: 1px solid #e9ecef;
            order: 1;
        }

        .floating-buttons {
            width: 300px;
            position: sticky;
            top: 20px;
            height: fit-content;
            background: white;
            border-radius: 20px;
            padding: 25px;
            box-shadow: 0 12px 35px rgba(0, 0, 0, 0.08);
            border: 1px solid #e9ecef;
            order: 2;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        /* Quick Info Cards */
        .quick-info-cards {
            display: flex;
            gap: 15px;
            margin-top: 20px;
            flex-wrap: wrap;
        }

        .quick-card {
            flex: 1;
            min-width: 150px;
            background: white;
            border-radius: 12px;
            padding: 15px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            border: 1px solid #e5e7eb;
        }

        .quick-card-header {
            font-size: 0.85rem;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .quick-card-header i {
            color: #10b981;
        }

        .quick-photo {
            position: relative;
            height: 80px;
            border-radius: 8px;
            overflow: hidden;
            cursor: pointer;
        }

        .quick-photo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .quick-photo-overlay {
            position: absolute;
            inset: 0;
            background: rgba(16, 185, 129, 0.85);
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.3s;
            color: white;
            font-size: 1.2rem;
        }

        .quick-photo:hover .quick-photo-overlay {
            opacity: 1;
        }

        .quick-file {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 15px;
            background: #f3f4f6;
            border-radius: 8px;
            color: #10b981;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 600;
            transition: all 0.3s;
        }

        .quick-file:hover {
            background: #e5e7eb;
        }

        .quick-file i {
            font-size: 1.2rem;
        }

        .quick-remarks {
            font-size: 0.8rem;
            color: #4b5563;
            max-height: 80px;
            overflow-y: auto;
            line-height: 1.5;
        }

        .quick-empty {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 15px;
            background: #f9fafb;
            border-radius: 8px;
            color: #9ca3af;
            font-size: 0.75rem;
            gap: 5px;
        }

        .quick-empty i {
            font-size: 1.2rem;
        }

        .btn-action {
            width: 100%;
            padding: 18px 25px;
            border-radius: 14px;
            font-size: 17px;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: flex-start;
            gap: 16px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: 2px solid transparent;
            text-align: left;
            position: relative;
            overflow: hidden;
        }

        .btn-action:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.12);
        }

        .btn-action i {
            font-size: 24px;
            width: 30px;
            position: relative;
            z-index: 1;
        }

        .btn-back {
            background: #f8f9fa;
            color: #495057;
            border-color: #e9ecef;
        }

        .btn-back:hover {
            background: #e9ecef;
            border-color: #dee2e6;
            color: #212529;
        }

        .btn-save {
            background: linear-gradient(135deg, #0d6efd 0%, #0b5ed7 100%);
            color: white;
            border: none;
        }

        .btn-save::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, #0b5ed7 0%, #0a58ca 100%);
            opacity: 0;
            transition: opacity 0.3s ease;
            z-index: -1;
            border-radius: 12px;
        }

        .btn-save:hover::before {
            opacity: 1;
        }

        .btn-print {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            border: none;
        }

        .btn-print::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, #218838 0%, #1aa179 100%);
            opacity: 0;
            transition: opacity 0.3s ease;
            z-index: -1;
            border-radius: 12px;
        }

        .btn-print:hover::before {
            opacity: 1;
        }

        .btn-maintenance {
            background: linear-gradient(135deg, #343a40 0%, #212529 100%);
            color: white;
            border: none;
        }

        .btn-maintenance::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, #212529 0%, #1c1f23 100%);
            opacity: 0;
            transition: opacity 0.3s ease;
            z-index: -1;
            border-radius: 12px;
        }

        .btn-maintenance:hover::before {
            opacity: 1;
        }

        .btn-archive {
            background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
            color: white;
            border: none;
        }

        .btn-archive::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, #495057 0%, #343a40 100%);
            opacity: 0;
            transition: opacity 0.3s ease;
            z-index: -1;
            border-radius: 12px;
        }

        .btn-archive:hover::before {
            opacity: 1;
        }

        /* Send Request Button - Blue */
        .btn-supply {
            background: linear-gradient(135deg, #0d6efd 0%, #0b5ed7 100%);
            color: white;
            border: none;
        }

        .btn-supply::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, #0b5ed7 0%, #0a58ca 100%);
            opacity: 0;
            transition: opacity 0.3s ease;
            z-index: -1;
            border-radius: 12px;
        }

        .btn-supply:hover::before {
            opacity: 1;
        }

        /* View Document Button - Purple */
        .btn-view {
            background: linear-gradient(135deg, #6f42c1 0%, #5a32a3 100%);
            color: white;
            border: none;
        }

        .btn-view::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, #5a32a3 0%, #4a288a 100%);
            opacity: 0;
            transition: opacity 0.3s ease;
            z-index: -1;
            border-radius: 12px;
        }

        .btn-view:hover::before {
            opacity: 1;
        }

        /* Complete Button - Green */
        .btn-complete {
            background: linear-gradient(135deg, #198754 0%, #146c43 100%);
            color: white;
            border: none;
        }

        .btn-complete::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, #146c43 0%, #0f5132 100%);
            opacity: 0;
            transition: opacity 0.3s ease;
            z-index: -1;
            border-radius: 12px;
        }

        .btn-complete:hover::before {
            opacity: 1;
        }

        .document-info {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 16px;
            padding: 25px;
            margin: 0;
            border: 1px solid #e9ecef;
        }

        .document-info h6 {
            color: #495057;
            font-weight: 700;
            font-size: 17px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .info-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 14px;
            padding-bottom: 14px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }

        .info-item:last-child {
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: none;
        }

        .info-label {
            font-weight: 600;
            color: #495057;
            font-size: 15px;
        }

        .info-value {
            color: #212529;
            text-align: right;
            font-size: 15px;
            font-weight: 500;
        }

        .badge {
            padding: 6px 14px;
            font-size: 13px;
            font-weight: 600;
            border-radius: 8px;
        }

        .printable-area {
            border: 1px solid #e9ecef;
            border-radius: 16px;
            padding: 35px;
            background: white;
        }

        .floating-buttons h4 {
            color: #198754;
            font-weight: 700;
            font-size: 22px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            padding-bottom: 20px;
            border-bottom: 2px solid #e9ecef;
        }

        .floating-buttons h4 i {
            font-size: 26px;
        }

        .tip-box {
            background: linear-gradient(135deg, #fefefe 0%, #f8f9fa 100%);
            border-radius: 14px;
            padding: 20px;
            border: 1px solid #e9ecef;
            text-align: left;
            margin-top: 15px;
        }

        .tip-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 0;
            border-bottom: 1px solid #f3f4f6;
            color: #6b7280;
            font-size: 0.85rem;
        }

        .tip-item:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .tip-item:first-child {
            padding-top: 0;
        }

        .tip-item i {
            color: #10b981;
            font-size: 1rem;
            width: 24px;
            text-align: center;
        }

        .tip-box small {
            color: #6c757d;
            font-size: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .tip-box i {
            color: #ffc107;
            font-size: 18px;
        }

        @media (max-width: 1200px) {
            .floating-buttons {
                width: 320px;
            }
        }

        @media (max-width: 992px) {
            .document-container {
                flex-direction: column;
                gap: 20px;
            }

            .floating-buttons {
                width: 100%;
                position: static;
                margin-bottom: 0;
                order: 1;
            }

            .document-viewer {
                max-height: none;
                order: 2;
            }

            .btn-action {
                padding: 16px 22px;
            }
        }

        @media (max-width: 768px) {
            body {
                padding: 15px;
            }

            .document-viewer,
            .floating-buttons {
                padding: 25px;
                border-radius: 18px;
            }

            .printable-area {
                padding: 25px;
            }
        }

        /* Scrollbar styling for document viewer */
        .document-viewer::-webkit-scrollbar {
            width: 8px;
        }

        .document-viewer::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }

        .document-viewer::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 4px;
        }

        .document-viewer::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }

        /* Edit mode specific styles */
        .form-control:focus {
            border-color: #86b7fe;
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
        }

        .signature-trigger:hover {
            background-color: #e9ecef !important;
        }

        /* Alert styling */
        .alert {
            border-radius: 12px;
            border: none;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        /* Vertical Timeline Styles */
        .timeline {
            position: relative;
            padding-left: 30px;
        }

        .timeline::before {
            content: '';
            position: absolute;
            left: 8px;
            top: 5px;
            bottom: 5px;
            width: 2px;
            background: linear-gradient(to bottom, #6c757d, #dee2e6);
        }

        .timeline-item {
            position: relative;
            padding-bottom: 15px;
        }

        .timeline-item:last-child {
            padding-bottom: 0;
        }

        .timeline-dot {
            position: absolute;
            left: -26px;
            top: 2px;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            border: 2px solid white;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.15);
        }

        .timeline-dot.pending {
            background: #ffc107;
        }

        .timeline-dot.approved {
            background: #0d6efd;
        }

        .timeline-dot.done {
            background: #198754;
        }

        .timeline-dot.complete {
            background: #082a4d;
        }

        .timeline-dot.archived {
            background: #6c757d;
        }

        .timeline-dot.default {
            background: #20c997;
        }

        .timeline-dot.completed {
            background: #198754 !important;
        }

        .timeline-dot.current {
            animation: pulse 1.5s infinite;
        }

        @keyframes pulse {
            0% {
                box-shadow: 0 0 0 0 rgba(13, 110, 253, 0.7);
            }

            70% {
                box-shadow: 0 0 0 6px rgba(13, 110, 253, 0);
            }

            100% {
                box-shadow: 0 0 0 0 rgba(13, 110, 253, 0);
            }
        }

        .timeline-content.border-primary {
            border: 1px solid #0d6efd !important;
            background: #f0f7ff;
        }

        .timeline-content {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 10px 12px;
        }

        .timeline-title {
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 4px;
            color: #212529;
        }

        .timeline-time {
            font-size: 11px;
            color: #6c757d;
            margin-bottom: 4px;
        }

        .timeline-remarks {
            font-size: 12px;
            color: #495057;
            font-style: italic;
        }

        .timeline-empty {
            text-align: center;
            padding: 20px;
            color: #6c757d;
            font-size: 13px;
        }

        /* Container Layout */
        .info-cards-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        /* Individual Card Styling */
        .info-card {
            background: #ffffff;
            border-radius: 12px;
            /* Smooth edges */
            padding: 1.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.04);
            border: 1px solid #f3f4f6;
            border-top: 4px solid #10b981;
            /* Green accent line */
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            display: flex;
            flex-direction: column;
        }

        .info-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 20px rgba(0, 0, 0, 0.08);
        }

        /* Typography */
        .card-header {
            margin-bottom: 1.25rem;
        }

        .info-card h6 {
            font-size: 1.1rem;
            color: #1f2937;
            font-weight: 600;
            margin: 0 0 0.25rem 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .info-card h6 i {
            color: #10b981;
            /* Matching green icon */
        }

        .card-description {
            font-size: 0.85rem;
            color: #6b7280;
            display: block;
        }

        /* Photo Overlay Interactions */
        .photo-overlay {
            position: relative;
            cursor: pointer;
            border-radius: 8px;
            overflow: hidden;
            flex-grow: 1;
            display: flex;
        }

        .photo-overlay img {
            width: 100%;
            height: 180px;
            object-fit: cover;
            border-radius: 8px;
            transition: transform 0.3s ease;
        }

        .photo-overlay:hover img {
            transform: scale(1.05);
        }

        .overlay-text {
            position: absolute;
            inset: 0;
            background: rgba(16, 185, 129, 0.85);
            /* Green gradient overlay */
            color: white;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            opacity: 0;
            transition: opacity 0.3s ease;
            font-weight: 500;
            font-size: 1.1rem;
        }

        .photo-overlay:hover .overlay-text {
            opacity: 1;
        }

        /* Professional File Button */
        .file-action-wrapper {
            flex-grow: 1;
            display: flex;
            align-items: flex-start;
        }

        .file-link-btn {
            display: flex;
            align-items: center;
            width: 100%;
            gap: 1rem;
            padding: 1rem;
            background-color: #f0fdf4;
            /* Very light green */
            border: 1px solid #bbf7d0;
            color: #166534;
            border-radius: 8px;
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .file-link-btn:hover {
            background-color: #dcfce7;
            border-color: #86efac;
            text-decoration: none;
            color: #14532d;
        }

        .file-icon i {
            font-size: 1.8rem;
            color: #10b981;
        }

        .file-text {
            display: flex;
            flex-direction: column;
            flex-grow: 1;
        }

        .file-text strong {
            font-size: 1rem;
        }

        .file-text span {
            font-size: 0.75rem;
            opacity: 0.8;
        }

        .external-icon {
            font-size: 1.2rem;
            opacity: 0.5;
        }

        /* Remarks Box Styling */
        .remarks-box {
            background: #f9fafb;
            padding: 1.25rem;
            border-radius: 8px;
            color: #4b5563;
            font-size: 0.95rem;
            line-height: 1.6;
            flex-grow: 1;
            position: relative;
            border: 1px solid #e5e7eb;
        }

        .quote-icon {
            position: absolute;
            top: -10px;
            left: 15px;
            background: #ffffff;
            padding: 0 5px;
            color: #d1d5db;
            font-size: 1.2rem;
        }

        .card-value {
            margin: 0;
        }

        /* Empty States */
        .empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 2.5rem 1rem;
            background: #fbfbfc;
            border: 2px dashed #d1d5db;
            border-radius: 8px;
            text-align: center;
            flex-grow: 1;
            color: #9ca3af;
        }

        .empty-state i {
            font-size: 2.5rem;
            margin-bottom: 0.75rem;
            color: #d1d5db;
        }

        .empty-state p {
            margin: 0 0 0.25rem 0;
            font-size: 1rem;
            font-weight: 600;
            color: #6b7280;
        }

        .empty-state span {
            font-size: 0.8rem;
        }

        /* Photo Modal - Enhanced */
        #photoModal .modal-content {
            border-radius: 16px;
            border: none;
            overflow: hidden;
        }

        #photoModal .modal-header {
            background: linear-gradient(135deg, #198754 0%, #146c43 100%);
            color: white;
            padding: 15px 20px;
            border: none;
        }

        #photoModal .modal-header .btn-close {
            filter: brightness(0) invert(1);
        }

        #photoModal .modal-body {
            padding: 0;
            background: #1a1a1a;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 60vh;
        }

        #photoModal .modal-body img {
            max-width: 100%;
            max-height: 75vh;
            object-fit: contain;
        }

        #photoModal .modal-footer {
            background: #1a1a1a;
            border: none;
            padding: 15px 20px;
        }

        #photoModal .modal-footer .btn-primary {
            background: #198754;
            border: none;
            padding: 10px 25px;
            border-radius: 8px;
        }

        #photoModal .modal-footer .btn-primary:hover {
            background: #146c43;
        }

        #photoModal .modal-footer .btn-secondary {
            background: #6c757d;
            border: none;
            padding: 10px 25px;
            border-radius: 8px;
        }


        /* Container to hold the overlapping elements */
        .signature-container {
            position: relative;
            padding-top: 30px;

        }

        /* Positions the signature exactly over the input field */
        .signature-wrapper {
            position: absolute;
            bottom: 25px;
            /* Adjust this value up or down to change the overlap height */
            left: 0;
            right: 0;
            z-index: 10;
            /* Ensures the signature is on top */
            pointer-events: none;
            /* Allows the user to click/highlight the text underneath the image */
            display: flex;
            justify-content: center;
        }

        .signature-img {
            height: 90px;
            width: auto;
            mix-blend-mode: multiply;
            /* Removes white backgrounds from signatures for a natural look */
            filter: contrast(1.3) drop-shadow(0px 2px 2px rgba(0, 0, 0, 0.1));
            /* Adds realism */
        }

        /* Formal document styling for the input field */
        .formal-signature-input {
            border: none !important;
            border-bottom: 2px solid #1f2937 !important;
            /* Thick dark bottom line */
            border-radius: 0 !important;
            background-color: transparent !important;
            font-weight: bold;
            color: #1f2937;
            padding-bottom: 5px;
            box-shadow: none !important;
            position: relative;
            z-index: 5;
            font-size: 1.1rem;
            cursor: default;
        }

        /* Remove outline on focus to maintain the printed document look */
        .formal-signature-input:focus {
            outline: none;
            box-shadow: none;
            background-color: transparent;
        }
    </style>
</head>

<body>
    <!-- Page Header with Back Button -->
    <div class="page-header">
        <a href="<?= $backUrl ?? 'admin_dashboard.php?view=documents' ?>" class="btn-back-header">
            <i class="bi bi-arrow-left"></i> Back
        </a>
        <div class="page-title">
            <h1>Pre-Repair Inspection Request</h1>
            <span class="property-badge">
                <i class="bi bi-hash"></i> <?= htmlspecialchars($doc['property_no'] ?? '') ?>
            </span>
        </div>
        <div class="status-badge status-<?= strtolower($current_status) ?>">
            <?= htmlspecialchars($current_status) ?>
        </div>
    </div>

    <!-- Progress Timeline -->
    <div class="header-timeline">
        <?php foreach ($status_steps as $step_key => $step):
            $step_index = array_search($step_key, array_keys($status_steps));
            $is_completed = $step_index < $current_index;
            $is_current = $step_index === $current_index;
            ?>
            <div class="header-timeline-item">
                <div
                    class="header-timeline-dot <?= $is_completed ? 'completed' : '' ?> <?= $is_current ? 'current' : '' ?>">
                </div>
                <span
                    class="header-timeline-label <?= $is_completed ? 'completed' : '' ?> <?= $is_current ? 'current' : '' ?>">
                    <?= htmlspecialchars($step['label']) ?>
                </span>
            </div>
        <?php endforeach; ?>
    </div>

    <div class='document-container'>
        <!-- Document Content on Left Side -->
        <div class='document-viewer'>
            <form method="POST" id="mainForm">
                <div class='printable-area'>
                    <!-- Header -->
                    <header class='d-flex align-items-center justify-content-center mb-4 text-center'>
                        <img src='rs/Pepo_Logo.png' alt='PEPO Logo'
                            style='width:100px; height:auto; margin-right:30px;'>
                        <div>
                            <small class='d-block'>Republic of the Philippines</small>
                            <small class='d-block'>PROVINCIAL GOVERNMENT OF OCCIDENTAL MINDORO</small>
                            <small class='d-block fw-bold'>GENERAL SERVICES OFFICE</small>
                        </div>
                        <img src='rs/BAGONG-PILIPINAS-LOGO.png' alt='Occidental Mindoro Logo'
                            style='width:140px; height:auto; margin-left:15px;'>
                    </header>

                    <!-- Certification Section -->
                    <section class='mb-4'>
                        <h5 class='text-center fw-bold text-success border-bottom border-success pb-2'>CERTIFICATION
                        </h5>

                        <div class='text-start mb-3 mt-2 d-flex align-items-center'>
                            <small class='fw-bold' style='white-space: nowrap; font-size: 20px;'>Pre-Repair No:</small>
                            <input type='text' class='form-control form-control-lg signature-input'
                                value="<?= htmlspecialchars($doc['pre_repair_no']) ?>" readonly>
                        </div>

                        <table class='table table-bordered mb-2 align-middle'>
                            <tbody>
                                <tr>
                                    <td class='fw-bold w-25'>Property Number:</td>
                                    <td><input type='text' class='form-control form-control-lg'
                                            value="<?= htmlspecialchars($doc['property_no']) ?>" readonly></td>
                                </tr>
                                <tr>
                                    <td class='fw-bold'>Description of Property:</td>
                                    <td><textarea class='form-control form-control-lg' rows='3'
                                            readonly><?= htmlspecialchars($doc['description']) ?></textarea></td>
                                </tr>
                                <tr>
                                    <td class='fw-bold'>Designation of Property:</td>
                                    <td><input type='text' class='form-control form-control-lg'
                                            value="<?= htmlspecialchars($doc['designation']) ?>" readonly></td>
                                </tr>
                                <tr>
                                    <td class='fw-bold'>Acquisition Date:</td>
                                    <td><input type='text' class='form-control form-control-lg'
                                            value="<?= htmlspecialchars($doc['acquisition_date']) ?>" readonly></td>
                                </tr>
                                <tr>
                                    <td class='fw-bold'>Acquisition Cost:</td>
                                    <td><input type='text' class='form-control form-control-lg'
                                            value="<?= htmlspecialchars($doc['acquisition_cost']) ?>" readonly></td>
                                </tr>
                                <tr>
                                    <td class='fw-bold'>Date of Last Repair:</td>
                                    <td><input type='text' class='form-control form-control-lg'
                                            value="<?= htmlspecialchars($doc['last_repair_date']) ?>" readonly></td>
                                </tr>
                                <tr>
                                    <td class='fw-bold'>Carrying Amount:</td>
                                    <td><input type='text' name='carrying_amount' class='form-control form-control-lg'
                                            value="<?= htmlspecialchars($doc['carrying_amount'] ?? '') ?>" readonly>
                                    </td>
                                </tr>
                            </tbody>
                        </table>

                        <p>(Attach a copy of latest job order)
                            This document serves to confirm that the PPE/ICS belongs to the Provincial Government of
                            Occidental Mindoro.
                        </p>

                        <!-- Property Custodian Officer -->
                        <div class="d-flex justify-content-center mt-4 mb-3">
                            <div class="text-center signature-container"
                                style="width: auto; min-width: 250px; max-width: 100%;">

                                <?php if (!empty($doc['signature'])): ?>
                                    <div class="signature-wrapper">
                                        <img src="<?= htmlspecialchars($doc['signature']) ?>" alt="Signature"
                                            class="signature-img">
                                    </div>
                                <?php endif; ?>

                                <?php
                                $current_officer_name = '';
                                if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['officer_name'])) {
                                    $current_officer_name = $_POST['officer_name'];
                                } elseif (isset($doc['officer_name'])) {
                                    $current_officer_name = $doc['officer_name'];
                                }
                                ?>

                                <input type="text" name="officer_name"
                                    class="form-control form-control-lg text-center formal-signature-input"
                                    value="<?= htmlspecialchars($current_officer_name) ?>" readonly>

                                <small class="text-muted fw-bold mt-1 d-block">Property Custodian Officer</small>
                            </div>
                        </div>
                    </section>

                    <!-- Pre-Repair Inspection Section -->
                    <section class='mb-4'>
                        <h5 class='text-center fw-bold bg-success text-white py-2'>PRE-REPAIR INSPECTION</h5>

                        <div class='border p-3 mb-4 rounded bg-light'
                            style="font-family: 'Times New Roman', Times, serif; font-size: 20px;">
                            <p class='mb-0'>
                                I (Name)
                                <input type='text' name='inspector_name'
                                    class='form-control d-inline mx-2 signature-input'
                                    style="width: 32%; display: inline-block; font-family: 'Times New Roman', Times, serif; font-size: 20px;"
                                    value="<?= htmlspecialchars($doc['inspector_name'] ?? '') ?>">
                                certify under penalty of law that as (Position)
                                <input type='text' name='inspector_position'
                                    class='form-control d-inline mx-2 signature-input'
                                    style="width: 25%; display: inline-block; font-family: 'Times New Roman', Times, serif; font-size: 20px;"
                                    value="<?= htmlspecialchars($doc['inspector_position'] ?? '') ?>">,
                                I have carefully examined the Above-Mentioned Property of the Provincial Government of
                                Occidental Mindoro.
                            </p>
                        </div>

                        <div class='mb-3'>
                            <label class='fw-bold'>Defect/Complaint:</label>
                            <textarea name='defect' class='form-control form-control-lg signature-input'
                                rows='3'><?= htmlspecialchars($doc['defect'] ?? '') ?></textarea>
                        </div>

                        <div class='mb-3'>
                            <label class='fw-bold'>Findings:</label>
                            <textarea name='findings' class='form-control form-control-lg signature-input'
                                rows='3'><?= htmlspecialchars($doc['findings'] ?? '') ?></textarea>
                        </div>

                        <div class='mb-3'>
                            <label class='fw-bold'>Recommendation:</label><br>
                            <input type='radio' name='recommendation' value='For In-House Repair'
                                <?= ($doc['recommendation'] ?? '') === 'For In-House Repair' ? 'checked' : '' ?>> For
                            In-House
                            Repair
                            <br>
                            <input type='radio' name='recommendation' value='For Outside Repair'
                                <?= ($doc['recommendation'] ?? '') === 'For Outside Repair' ? 'checked' : '' ?>> For
                            Outside
                            Repair
                        </div>
                    </section>

                    <!-- Materials & Parts -->
                    <section class='mb-4'>
                        <h5 class='text-center fw-bold'>MATERIALS & PARTS</h5>
                        <table class='table table-bordered text-center align-middle'>
                            <thead class='bg-light'>
                                <tr>
                                    <th>ITEM #</th>
                                    <th>MATERIAL/PARTS</th>
                                    <th>QUANTITY</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php for ($i = 1; $i <= 8; $i++): ?>
                                    <tr>
                                        <td><?= $i ?></td>
                                        <td><input type='text' name='material_<?= $i ?>'
                                                class='form-control form-control-lg text-center'
                                                value="<?= htmlspecialchars($doc["material_$i"] ?? '') ?>"></td>
                                        <td><input type='text' name='quantity_<?= $i ?>'
                                                class='form-control form-control-lg text-center'
                                                value="<?= htmlspecialchars($doc["quantity_$i"] ?? '') ?>"></td>
                                    </tr>
                                <?php endfor; ?>
                            </tbody>
                        </table>
                    </section>

                    <!-- Signature Fields -->
                    <div class='row text-center mt-5'>

                        <div class='col-6 mb-4'>
                            <label class='fw-bold mb-4'>Pre-Inspected by:</label>
                            <div class='signature-container'
                                style='position: relative; min-height: 80px; cursor: pointer; display: flex; flex-direction: column; justify-content: flex-end;'>

                                <div class='sig-display'
                                    style='position: absolute; bottom: 20px; left: 0; width: 100%; display: flex; justify-content: center; z-index: 10; pointer-events: none;'>
                                    <?php if (!empty($doc['inspected_by_sig'])): ?>
                                        <img src="<?= htmlspecialchars($doc['inspected_by_sig']) ?>"
                                            style='height: 60px; width: auto; mix-blend-mode: multiply; filter: contrast(1.2);'>
                                    <?php endif; ?>
                                </div>

                                <input type='text' name='inspected_by'
                                    class='form-control text-center click-to-sign signature-input'
                                    placeholder='Tap to sign' readonly data-myname="<?= htmlspecialchars($my_name) ?>"
                                    data-mysig="<?= htmlspecialchars($my_signature) ?>"
                                    value="<?= htmlspecialchars($doc['inspected_by'] ?? '') ?>">

                                <input type='hidden' name='inspected_by_sig'
                                    value="<?= htmlspecialchars($doc['inspected_by_sig'] ?? '') ?>">

                                <small class="mt-1">Inspector</small>
                            </div>
                        </div>

                        <div class='col-6 mb-4'>
                            <label class='fw-bold mb-4'>Approved:</label>
                            <div class='signature-container'
                                style='position: relative; min-height: 80px; cursor: pointer; display: flex; flex-direction: column; justify-content: flex-end;'>

                                <div class='sig-display'
                                    style='position: absolute; bottom: 20px; left: 0; width: 100%; display: flex; justify-content: center; z-index: 10; pointer-events: none;'>
                                    <?php if (!empty($doc['approved_by_pepo_sig'])): ?>
                                        <img src="<?= htmlspecialchars($doc['approved_by_pepo_sig']) ?>"
                                            style='height: 60px; width: auto; mix-blend-mode: multiply; filter: contrast(1.2);'>
                                    <?php endif; ?>
                                </div>

                                <input type='text' name='approved_by_pepo'
                                    class='form-control text-center click-to-sign signature-input'
                                    placeholder='Tap to sign' readonly data-myname="<?= htmlspecialchars($my_name) ?>"
                                    data-mysig="<?= htmlspecialchars($my_signature) ?>"
                                    value="<?= htmlspecialchars($doc['approved_by_pepo'] ?? '') ?>">

                                <input type='hidden' name='approved_by_pepo_sig'
                                    value="<?= htmlspecialchars($doc['approved_by_pepo_sig'] ?? '') ?>">

                                <small class="mt-1">PGDH-PEPO</small>
                            </div>
                        </div>

                        <div class='col-6 mb-4'>
                            <label class='fw-bold mb-4'>Witnessed:</label>
                            <div class='signature-container'
                                style='position: relative; min-height: 80px; cursor: pointer; display: flex; flex-direction: column; justify-content: flex-end;'>

                                <div class='sig-display'
                                    style='position: absolute; bottom: 20px; left: 0; width: 100%; display: flex; justify-content: center; z-index: 10; pointer-events: none;'>
                                    <?php if (!empty($doc['witnessed_by_sig'])): ?>
                                        <img src="<?= htmlspecialchars($doc['witnessed_by_sig']) ?>"
                                            style='height: 60px; width: auto; mix-blend-mode: multiply; filter: contrast(1.2);'>
                                    <?php endif; ?>
                                </div>

                                <input type='text' name='witnessed_by'
                                    class='form-control text-center click-to-sign signature-input'
                                    placeholder='Tap to sign' readonly data-myname="<?= htmlspecialchars($my_name) ?>"
                                    data-mysig="<?= htmlspecialchars($my_signature) ?>"
                                    value="<?= htmlspecialchars($doc['witnessed_by'] ?? '') ?>">

                                <input type='hidden' name='witnessed_by_sig'
                                    value="<?= htmlspecialchars($doc['witnessed_by_sig'] ?? '') ?>">

                                <small class="mt-1">PGDH-PACCO</small>
                            </div>
                        </div>

                        <div class='col-6 mb-4'>
                            <label class='fw-bold mb-4'>Approved:</label>
                            <div class='signature-container'
                                style='position: relative; min-height: 80px; cursor: pointer; display: flex; flex-direction: column; justify-content: flex-end;'>

                                <div class='sig-display'
                                    style='position: absolute; bottom: 20px; left: 0; width: 100%; display: flex; justify-content: center; z-index: 10; pointer-events: none;'>
                                    <?php if (!empty($doc['approved_by_gso_sig'])): ?>
                                        <img src="<?= htmlspecialchars($doc['approved_by_gso_sig']) ?>"
                                            style='height: 60px; width: auto; mix-blend-mode: multiply; filter: contrast(1.2);'>
                                    <?php endif; ?>
                                </div>

                                <input type='text' name='approved_by_gso'
                                    class='form-control text-center click-to-sign signature-input'
                                    placeholder='Tap to sign' readonly data-myname="<?= htmlspecialchars($my_name) ?>"
                                    data-mysig="<?= htmlspecialchars($my_signature) ?>"
                                    value="<?= htmlspecialchars($doc['approved_by_gso'] ?? '') ?>">

                                <input type='hidden' name='approved_by_gso_sig'
                                    value="<?= htmlspecialchars($doc['approved_by_gso_sig'] ?? '') ?>">

                                <small class="mt-1">PGDH-GSO</small>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <!-- Quick Info Cards -->
        <div class="quick-info-cards">
            <div class="quick-card">
                <div class="quick-card-header">
                    <i class="bi bi-camera-fill"></i> Photo
                </div>
                <?php if (!empty($doc['photo_path'])): ?>
                    <div class="quick-photo" onclick="showPhoto('<?= htmlspecialchars($doc['photo_path']) ?>')">
                        <img src="<?= htmlspecialchars($doc['photo_path']) ?>" alt="Photo">
                        <div class="quick-photo-overlay">
                            <i class="bi bi-zoom-in"></i>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="quick-empty">
                        <i class="bi bi-image"></i>
                        <span>No photo</span>
                    </div>
                <?php endif; ?>
            </div>

            <div class="quick-card">
                <div class="quick-card-header">
                    <i class="bi bi-paperclip"></i> Attachment
                </div>
                <?php if (!empty($doc['attached_file_path'])): ?>
                    <a href="<?= htmlspecialchars($doc['attached_file_path']) ?>" target="_blank" class="quick-file">
                        <i class="bi bi-file-earmark-text-fill"></i>
                        <span>View File</span>
                    </a>
                <?php else: ?>
                    <div class="quick-empty">
                        <i class="bi bi-file-earmark-x"></i>
                        <span>No file</span>
                    </div>
                <?php endif; ?>
            </div>

            <div class="quick-card">
                <div class="quick-card-header">
                    <i class="bi bi-chat-left-text-fill"></i> Remarks
                </div>
                <?php if (!empty($doc['remarks'])): ?>
                    <div class="quick-remarks">
                        <?= nl2br(htmlspecialchars($doc['remarks'])) ?>
                    </div>
                <?php else: ?>
                    <div class="quick-empty">
                        <i class="bi bi-chat-dots"></i>
                        <span>No remarks</span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Floating Action Buttons on Right Side -->
        <div class='floating-buttons no-print'>
            <h4>
                <i class='bi bi-file-text'></i> Action Buttons
            </h4>

            <?php
            $backSource = $_SESSION['original_source'] ?? $source;
            $backUrl = '';
            $backTitle = '';

            if ($backSource === 'maintenance') {
                $backUrl = 'admin_dashboard.php?view=maintenance';
                $backTitle = 'Back to Maintenance';
            } elseif ($backSource === 'request') {
                $backUrl = 'view_request.php';
                $backTitle = 'Back to Request';
            } elseif ($doc && !empty($doc['equipment_id'])) {
                $backUrl = 'generate_qr.php?id=' . $doc['equipment_id'];
                $backTitle = 'Back to QR';
            } else {
                $backUrl = 'admin_dashboard.php?view=maintenance';
                $backTitle = 'Back to Mantenance';
            }
            ?>

            <!-- Back button removed - now in page header -->

            <?php if ($source !== 'request'): ?>
                <!-- <button type='submit' form='mainForm' class='btn-action btn-save'>
                <i class='bi bi-save'></i>
                <span>Save Changes</span>
            </button> -->

                <!-- <button class='btn-action btn-print' onclick='window.print()'>
                <i class='bi bi-printer'></i>
                <span>Print Document</span>
            </button> -->

                <!-- <button class='btn-action btn-view' data-bs-toggle='modal' data-bs-target='#viewDocumentModal'>
                <i class='bi bi-eye'></i>
                <span>View Document</span>
            </button> -->

                <button class='btn-action btn-supply' data-bs-toggle='modal' data-bs-target='#supplyRequestModal'>
                    <i class='bi bi-send'></i>
                    <span>Send Request to Supply Department</span>
                </button>

                <button type='submit' name='complete' value='1' form='mainForm' class='btn-action btn-complete'>
                    <i class='bi bi-check-circle'></i>
                    <span>Complete</span>
                </button>
            <?php endif; ?>

            <div class='document-info'>
                <h6><i class='bi bi-info-circle'></i> Document Information</h6>

                <div class='info-item'>
                    <span class='info-label'>Pre-Repair No:</span>
                    <span class='info-value'><?= htmlspecialchars($doc['pre_repair_no']) ?></span>
                </div>

                <div class='info-item'>
                    <span class='info-label'>Property No:</span>
                    <span class='info-value'><?= htmlspecialchars($doc['property_no']) ?></span>
                </div>

                <h6 class='mt-3 mb-2'><i class='bi bi-clock-history'></i> Progress Timeline</h6>

                <div class='timeline'>
                    <?php foreach ($status_steps as $step_key => $step): ?>
                        <?php
                        $step_index = array_search($step_key, array_keys($status_steps));
                        $is_completed = $step_index < $current_index;
                        $is_current = $step_index === $current_index;
                        $has_date = !empty($step['date']);

                        $dot_class = match ($step_key) {
                            'Pending' => 'pending',
                            'Approved' => 'approved',
                            'Done' => 'done',
                            'Complete' => 'complete',
                            default => 'default'
                        };
                        ?>
                        <div class='timeline-item'>
                            <div
                                class='timeline-dot <?= $dot_class ?><?= $is_completed ? ' completed' : '' ?><?= $is_current ? ' current' : '' ?>'>
                            </div>
                            <div class='timeline-content <?= $is_current ? ' border-primary' : '' ?>'>
                                <div class='timeline-title'>
                                    <?php if ($is_completed): ?>
                                        <i class='bi bi-check-circle-fill text-success me-1'></i>
                                    <?php elseif ($is_current): ?>
                                        <i class='bi bi-circle-fill me-1' style='font-size: 10px;'></i>
                                    <?php endif; ?>
                                    <?= htmlspecialchars($step['label']) ?>
                                </div>
                                <div class='timeline-time'>
                                    <i class='bi bi-clock'></i>
                                    <?php if ($has_date): ?>
                                        <?= date('M d, Y', strtotime($step['date'])) ?>
                                    <?php elseif ($is_current): ?>
                                        <span class='text-primary'>In Progress</span>
                                    <?php else: ?>
                                        <span class='text-muted'>Pending</span>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($step['description'])): ?>
                                    <div class='timeline-remarks'><?= htmlspecialchars($step['description']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class='tip-box'>
                <div class='tip-item'>
                    <i class='bi bi-printer'></i>
                    <span>Print Document to generate physical copy</span>
                </div>
                <div class='tip-item'>
                    <i class='bi bi-send'></i>
                    <span>Send to Supply Department for procurement</span>
                </div>
                <div class='tip-item'>
                    <i class='bi bi-check-circle'></i>
                    <span>Mark Complete when repair/maintenance is done</span>
                </div>
            </div>
        </div>
    </div>

    <!-- View Document Modal -->
    <div class="modal fade" id="viewDocumentModal" tabindex="-1" aria-labelledby="viewDocumentModalLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header bg-purple text-white"
                    style="background: linear-gradient(135deg, #6f42c1 0%, #5a32a3 100%);">
                    <h5 class="modal-title" id="viewDocumentModalLabel">
                        <i class="bi bi-eye"></i> View Document - Pre-Repair No:
                        <?= htmlspecialchars($doc['pre_repair_no'] ?? 'N/A') ?>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                        aria-label="Close"></button>
                </div>

                <div class="modal-body p-0">
                    <div class="document-preview-container border rounded m-3" style="background: #f8f9fa;">
                        <div class="card p-4 shadow-sm border-0">
                            <header class="d-flex align-items-center justify-content-center mb-4 text-center">
                                <img src="rs/Pepo_Logo.png" alt="PEPO Logo"
                                    style="width:80px; height:auto; margin-right:20px;">
                                <div>
                                    <small class="d-block">Republic of the Philippines</small>
                                    <small class="d-block">PROVINCIAL GOVERNMENT OF OCCIDENTAL MINDORO</small>
                                    <small class="d-block fw-bold">GENERAL SERVICES OFFICE</small>
                                </div>
                                <img src="rs/BAGONG-PILIPINAS-LOGO.png" alt="Occidental Mindoro Logo"
                                    style="width:100px; height:auto; margin-left:15px;">
                            </header>

                            <section class="mb-3">
                                <h5 class="text-center fw-bold text-success border-bottom border-success pb-2">
                                    CERTIFICATION</h5>

                                <div class="text-start mb-3 mt-2 d-flex align-items-center">
                                    <small class='fw-bold' style='white-space: nowrap; font-size: 16px;'>Pre-Repair
                                        No:</small>
                                    <span class="form-control form-control-sm signature-input bg-light"
                                        style="font-size: 14px;">
                                        <?= htmlspecialchars($doc['pre_repair_no'] ?? 'N/A') ?>
                                    </span>
                                </div>

                                <table class="table table-bordered mb-2 align-middle">
                                    <tbody>
                                        <tr>
                                            <td class="fw-bold w-25">Property Number:</td>
                                            <td><?= htmlspecialchars($doc['property_no'] ?? 'N/A') ?></td>
                                        </tr>
                                        <tr>
                                            <td class="fw-bold">Description of Property:</td>
                                            <td><?= htmlspecialchars($doc['description'] ?? 'N/A') ?></td>
                                        </tr>
                                        <tr>
                                            <td class="fw-bold">Designation of Property:</td>
                                            <td><?= htmlspecialchars($doc['designation'] ?? 'N/A') ?></td>
                                        </tr>
                                        <tr>
                                            <td class="fw-bold">Acquisition Date:</td>
                                            <td><?= htmlspecialchars($doc['acquisition_date'] ?? 'N/A') ?></td>
                                        </tr>
                                        <tr>
                                            <td class="fw-bold">Carrying Amount:</td>
                                            <td><?= htmlspecialchars($doc['carrying_amount'] ?? 'N/A') ?></td>
                                        </tr>
                                        <tr>
                                            <td class="fw-bold">Last Repair Date:</td>
                                            <td><?= htmlspecialchars($doc['last_repair_date'] ?? 'N/A') ?></td>
                                        </tr>
                                    </tbody>
                                </table>

                                <div class="mb-3">
                                    <label class="fw-bold">Defect/Problem:</label>
                                    <div class="border rounded p-2 bg-light">
                                        <?= nl2br(htmlspecialchars($doc['defect'] ?? 'N/A')) ?>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="fw-bold">Findings:</label>
                                    <div class="border rounded p-2 bg-light">
                                        <?= nl2br(htmlspecialchars($doc['findings'] ?? 'N/A')) ?>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="fw-bold">Recommendation:</label>
                                    <div class="border rounded p-2 bg-light">
                                        <?= htmlspecialchars($doc['recommendation'] ?? 'N/A') ?>
                                    </div>
                                </div>

                                <!-- Materials & Parts -->
                                <h6 class="fw-bold mt-4">MATERIALS & PARTS</h6>
                                <table class="table table-bordered table-sm">
                                    <thead>
                                        <tr>
                                            <th>No.</th>
                                            <th>Material/Particulars</th>
                                            <th>Quantity</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php for ($i = 1; $i <= 10; $i++): ?>
                                            <?php $material = $doc["material_$i"] ?? ''; ?>
                                            <?php if (!empty($material)): ?>
                                                <tr>
                                                    <td><?= $i ?></td>
                                                    <td><?= htmlspecialchars($material) ?></td>
                                                    <td><?= htmlspecialchars($doc["quantity_$i"] ?? '') ?></td>
                                                </tr>
                                            <?php endif; ?>
                                        <?php endfor; ?>
                                        <?php if (empty($doc['material_1'])): ?>
                                            <tr>
                                                <td colspan="3" class="text-center text-muted">No materials/parts listed
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>

                                <!-- Signatures Section -->
                                <div class="row mt-4">
                                    <div class="col-md-6">
                                        <div class="border p-2 text-center">
                                            <small class="fw-bold">Inspected By:</small>
                                            <div class="my-2" style="height: 60px;">
                                                <?php if (!empty($doc['inspected_by_sig'])): ?>
                                                    <img src="<?= htmlspecialchars($doc['inspected_by_sig']) ?>"
                                                        style="height:50px; mix-blend-mode:multiply;">
                                                <?php endif; ?>
                                            </div>
                                            <small><?= htmlspecialchars($doc['inspected_by'] ?? '') ?></small>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="border p-2 text-center">
                                            <small class="fw-bold">Approved By (PEPO):</small>
                                            <div class="my-2" style="height: 60px;">
                                                <?php if (!empty($doc['approved_by_pepo_sig'])): ?>
                                                    <img src="<?= htmlspecialchars($doc['approved_by_pepo_sig']) ?>"
                                                        style="height:50px; mix-blend-mode:multiply;">
                                                <?php endif; ?>
                                            </div>
                                            <small><?= htmlspecialchars($doc['approved_by_pepo'] ?? '') ?></small>
                                        </div>
                                    </div>
                                </div>

                                <div class="row mt-3">
                                    <div class="col-md-6">
                                        <div class="border p-2 text-center">
                                            <small class="fw-bold">Witnessed By:</small>
                                            <div class="my-2" style="height: 60px;">
                                                <?php if (!empty($doc['witnessed_by_sig'])): ?>
                                                    <img src="<?= htmlspecialchars($doc['witnessed_by_sig']) ?>"
                                                        style="height:50px; mix-blend-mode:multiply;">
                                                <?php endif; ?>
                                            </div>
                                            <small><?= htmlspecialchars($doc['witnessed_by'] ?? '') ?></small>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="border p-2 text-center">
                                            <small class="fw-bold">Approved By (GSO):</small>
                                            <div class="my-2" style="height: 60px;">
                                                <?php if (!empty($doc['approved_by_gso_sig'])): ?>
                                                    <img src="<?= htmlspecialchars($doc['approved_by_gso_sig']) ?>"
                                                        style="height:50px; mix-blend-mode:multiply;">
                                                <?php endif; ?>
                                            </div>
                                            <small><?= htmlspecialchars($doc['approved_by_gso'] ?? '') ?></small>
                                        </div>
                                    </div>
                                </div>
                            </section>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-outline-primary" onclick="openSupplyModal()">
                        <i class="bi bi-send"></i> Send to Supply
                    </button>
                    <button type="button" class="btn btn-success" onclick="markAsComplete()">
                        <i class="bi bi-check-circle"></i> Complete
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Supply Request Modal (Simplified - no document preview) -->
    <div class="modal fade" id="supplyRequestModal" tabindex="-1" aria-labelledby="supplyRequestModalLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" action="send_supply_request.php" id="supplyRequestForm">
                    <input type="hidden" name="document_id" value="<?= $id ?>">
                    <input type="hidden" name="pre_repair_no" value="<?= htmlspecialchars($doc['pre_repair_no']) ?>">

                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title" id="supplyRequestModalLabel">
                            <i class="bi bi-send"></i> Send Request to Supply Department
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                            aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
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
                                <?php if (isset($_SESSION['location'])): ?>
                                    <br>Your location: <strong
                                        class="text-primary"><?= htmlspecialchars(ucfirst($_SESSION['location'])) ?></strong>
                                <?php endif; ?>
                            </small>
                        </div>

                        <!-- Remarks/Notes -->
                        <div class="mb-3">
                            <label class="form-label fw-bold">Additional Remarks:</label>
                            <textarea name="remarks" class="form-control" rows="4"
                                placeholder="Add any additional information or instructions for the supply department..."></textarea>
                        </div>

                        <!-- Information Box -->
                        <div class="alert alert-info">
                            <small>
                                <i class="bi bi-info-circle"></i>
                                <strong>Request Information:</strong><br>
                                • Request from:
                                <strong><?= htmlspecialchars($_SESSION['username'] ?? 'Admin') ?></strong><br>
                                • Your location:
                                <strong><?= htmlspecialchars(ucfirst($_SESSION['location'] ?? 'mamburao')) ?></strong><br>
                                • All supply requests will be received by supply officers at the selected location.
                            </small>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-send"></i> Send Request
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal-body">
        <!-- Document Preview (Scrollable) -->
        <div class="document-preview-container border rounded mb-4"
            style="background: #f8f9fa; max-height: 300px; overflow-y: auto;">
            <div class="card p-3 shadow-sm border-0 printable-area"
                style="transform: scale(0.85); transform-origin: top center; width: 117.6%; margin-left: -8.8%;">
                <header class="d-flex align-items-center justify-content-center mb-4 text-center">
                    <img src="rs/Pepo_Logo.png" alt="PEPO Logo" style="width:80px; height:auto; margin-right:20px;">
                    <div>
                        <small class="d-block">Republic of the Philippines</small>
                        <small class="d-block">PROVINCIAL GOVERNMENT OF OCCIDENTAL MINDORO</small>
                        <small class="d-block fw-bold">GENERAL SERVICES OFFICE</small>
                    </div>
                    <img src="rs/BAGONG-PILIPINAS-LOGO.png" alt="Occidental Mindoro Logo"
                        style="width:100px; height:auto; margin-left:15px;">
                </header>

                <section class="mb-3">
                    <h5 class="text-center fw-bold text-success border-bottom border-success pb-2"
                        style="font-size: 1.1rem;">CERTIFICATION</h5>

                    <div class="text-start mb-3 mt-2 d-flex align-items-center">
                        <small class="fw-bold" style="white-space: nowrap; font-size: 16px;">Pre-Repair
                            No:</small>
                        <input type="text" class="form-control form-control-sm signature-input"
                            value="<?= htmlspecialchars($doc['pre_repair_no']) ?>" readonly
                            style="font-size: 14px; padding: 0.2rem 0.5rem;">
                    </div>

                    <table class="table table-sm table-bordered mb-2 align-middle">
                        <tbody>
                            <tr>
                                <td class="fw-bold w-25" style="font-size: 12px;">Property Number:</td>
                                <td style="font-size: 12px;"><input type="text"
                                        class="form-control form-control-sm border-0 bg-transparent"
                                        value="<?= htmlspecialchars($doc['property_no']) ?>" readonly>
                                </td>
                            </tr>
                            <tr>
                                <td class="fw-bold" style="font-size: 12px;">Description of Property:
                                </td>
                                <td style="font-size: 12px;"><textarea
                                        class="form-control form-control-sm border-0 bg-transparent" rows="2"
                                        readonly><?= htmlspecialchars($doc['description']) ?></textarea>
                                </td>
                            </tr>
                            <tr>
                                <td class="fw-bold" style="font-size: 12px;">Designation of Property:
                                </td>
                                <td style="font-size: 12px;"><input type="text"
                                        class="form-control form-control-sm border-0 bg-transparent"
                                        value="<?= htmlspecialchars($doc['designation']) ?>" readonly>
                                </td>
                            </tr>
                            <tr>
                                <td class="fw-bold" style="font-size: 12px;">Acquisition Date:</td>
                                <td style="font-size: 12px;"><input type="text"
                                        class="form-control form-control-sm border-0 bg-transparent"
                                        value="<?= htmlspecialchars($doc['acquisition_date']) ?>" readonly></td>
                            </tr>
                            <tr>
                                <td class="fw-bold" style="font-size: 12px;">Acquisition Cost:</td>
                                <td style="font-size: 12px;"><input type="text"
                                        class="form-control form-control-sm border-0 bg-transparent"
                                        value="<?= htmlspecialchars($doc['acquisition_cost']) ?>" readonly></td>
                            </tr>
                            <tr>
                                <td class="fw-bold" style="font-size: 12px;">Date of Last Repair:</td>
                                <td style="font-size: 12px;"><input type="text"
                                        class="form-control form-control-sm border-0 bg-transparent"
                                        value="<?= htmlspecialchars($doc['last_repair_date']) ?>" readonly></td>
                            </tr>
                            <tr>
                                <td class="fw-bold" style="font-size: 12px;">Carrying Amount:</td>
                                <td style="font-size: 12px;"><input type="text" name="carrying_amount"
                                        class="form-control form-control-sm border-0 bg-transparent"
                                        value="<?= htmlspecialchars($doc['carrying_amount'] ?? '') ?>" readonly></td>
                            </tr>
                        </tbody>
                    </table>

                    <p style="font-size: 12px; margin-bottom: 10px;">
                        (Attach a copy of latest job order)
                        This document serves to confirm that the PPE/ICS belongs to the Provincial
                        Government of Occidental Mindoro.
                    </p>

                    <!-- Property Custodian Officer -->
                    <div class="d-flex justify-content-center mt-2">
                        <div class="text-center" style="width: 60%;">
                            <?php if (!empty($doc['signature'])): ?>
                                <div class="mb-1 text-center">
                                    <img src="<?= htmlspecialchars($doc['signature']) ?>" alt="Signature"
                                        style="height: 40px; width: auto; mix-blend-mode: multiply; filter: contrast(1.2);">
                                </div>
                            <?php endif; ?>
                            <input type="text" name="officer_name"
                                class="form-control form-control-sm text-center signature-input"
                                value="<?= htmlspecialchars($current_officer_name) ?>" readonly
                                style="font-size: 12px; padding: 0.2rem;">
                            <small style="font-size: 11px;">Property Custodian Officer</small>
                        </div>
                    </div>
                </section>

                <!-- PRE-REPAIR INSPECTION SECTION -->
                <section class="mb-3">
                    <h5 class="text-center fw-bold bg-success text-white py-2" style="font-size: 1.1rem;">
                        PRE-REPAIR INSPECTION</h5>
                    <div class="border p-2 mb-3 rounded bg-light"
                        style="font-family: 'Times New Roman', Times, serif; font-size: 14px;">
                        <p class="mb-0">
                            I (Name)
                            <input type="text" name="inspector_name" class="form-control d-inline mx-2 signature-input"
                                style="width: 30%; display: inline-block; font-size: 14px; padding: 0.1rem 0.3rem;"
                                value="<?= htmlspecialchars($doc['inspector_name'] ?? '') ?>" readonly>
                            certify under penalty of law that as (Position)
                            <input type="text" name="inspector_position"
                                class="form-control d-inline mx-2 signature-input"
                                style="width: 25%; display: inline-block; font-size: 14px; padding: 0.1rem 0.3rem;"
                                value="<?= htmlspecialchars($doc['inspector_position'] ?? '') ?>" readonly>
                            , I have carefully examined the Above-Mentioned Property
                            of the Provincial Government of Occidental Mindoro.
                        </p>
                    </div>

                    <div class="mb-2">
                        <label class="fw-bold" style="font-size: 12px;">Defect/Complaint:</label>
                        <textarea name="defect" class="form-control form-control-sm signature-input" rows="2" readonly
                            style="font-size: 12px;"><?= htmlspecialchars($doc['defect'] ?? '') ?></textarea>
                    </div>

                    <div class="mb-2">
                        <label class="fw-bold" style="font-size: 12px;">Findings:</label>
                        <textarea name="findings" class="form-control form-control-sm signature-input" rows="2" readonly
                            style="font-size: 12px;"><?= htmlspecialchars($doc['findings'] ?? '') ?></textarea>
                    </div>

                    <div class="mb-2">
                        <label class="fw-bold" style="font-size: 12px;">Recommendation:</label>
                        <div style="font-size: 11px;">
                            <input type="radio" name="recommendation" value="For In-House Repair"
                                <?= ($doc['recommendation'] ?? '') === 'For In-House Repair' ? 'checked' : '' ?> disabled
                                style="transform: scale(0.8);"> For In-House Repair
                            <input type="radio" name="recommendation" value="For Outside Repair"
                                <?= ($doc['recommendation'] ?? '') === 'For Outside Repair' ? 'checked' : '' ?> disabled
                                style="transform: scale(0.8);"> For Outside Repair
                        </div>
                    </div>
                </section>

                <!-- MATERIALS & PARTS -->
                <section class="mb-3">
                    <h5 class="text-center fw-bold" style="font-size: 1.1rem;">MATERIALS & PARTS</h5>
                    <table class="table table-sm table-bordered text-center align-middle">
                        <thead class="bg-light">
                            <tr>
                                <th style="font-size: 11px; padding: 3px;">ITEM #</th>
                                <th style="font-size: 11px; padding: 3px;">MATERIAL/PARTS</th>
                                <th style="font-size: 11px; padding: 3px;">QUANTITY</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php for ($i = 1; $i <= 10; $i++): ?>
                                <tr>
                                    <td style="font-size: 11px; padding: 2px;"><?= $i ?></td>
                                    <td style="font-size: 11px; padding: 2px;">
                                        <input type="text" name="material_<?= $i ?>"
                                            class="form-control form-control-sm text-center border-0 bg-transparent"
                                            value="<?= htmlspecialchars($doc["material_$i"] ?? '') ?>" readonly
                                            style="font-size: 11px; padding: 2px;">
                                    </td>
                                    <td style="font-size: 11px; padding: 2px;">
                                        <input type="text" name="quantity_<?= $i ?>"
                                            class="form-control form-control-sm text-center border-0 bg-transparent"
                                            value="<?= htmlspecialchars($doc["quantity_$i"] ?? '') ?>" readonly
                                            style="font-size: 11px; padding: 2px;">
                                    </td>
                                </tr>
                            <?php endfor; ?>
                        </tbody>
                    </table>
                </section>
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
                <?php if (isset($_SESSION['location'])): ?>
                    <br>Your location: <strong
                        class="text-primary"><?= htmlspecialchars(ucfirst($_SESSION['location'])) ?></strong>
                <?php endif; ?>
            </small>
        </div>

        <!-- Remarks/Notes -->
        <div class="mb-3">
            <label class="form-label fw-bold">Additional Remarks:</label>
            <textarea name="remarks" class="form-control" rows="3"
                placeholder="Add any additional information or instructions for the supply department..."></textarea>
        </div>

        <!-- Information Box -->
        <div class="alert alert-info">
            <small>
                <i class="bi bi-info-circle"></i>
                <strong>Request Information:</strong><br>
                • Request from:
                <strong><?= htmlspecialchars($_SESSION['username'] ?? 'Admin') ?></strong><br>
                • Your location:
                <strong><?= htmlspecialchars(ucfirst($_SESSION['location'] ?? 'mamburao')) ?></strong><br>
                • All supply requests for Mamburao will be received by supply officers located in
                Mamburao.
            </small>
        </div>
    </div>

    <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-send"></i> Send Request
        </button>
    </div>
    </form>
    </div>
    </div>
    </div>

    <script src='https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js'></script>
    <script>
        function closeViewModalAndOpenSupply() {
            const viewModal = bootstrap.Modal.getInstance(document.getElementById('viewDocumentModal'));
            if (viewModal) {
                viewModal.hide();
            }
            setTimeout(() => {
                const supplyModal = new bootstrap.Modal(document.getElementById('supplyRequestModal'));
                supplyModal.show();
            }, 300);
        }

        document.addEventListener('DOMContentLoaded', function () {
            const mainForm = document.getElementById('mainForm');

            if (mainForm) {
                mainForm.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA') {
                        e.preventDefault();
                        return false;
                    }
                });

                mainForm.addEventListener('submit', function (e) {
                    if (!e.submitter || e.submitter.name !== 'complete') {
                        e.preventDefault();
                        return false;
                    }

                    if (!confirm("Are you sure you want to mark this document as Complete?")) {
                        e.preventDefault();
                        return false;
                    }

                    return true;
                });
            }

            const modalForm = document.getElementById('supplyRequestForm');
            if (modalForm) {
                modalForm.addEventListener('submit', function (e) {
                    e.stopPropagation();
                    return confirm("Send this request to the Supply Department?");
                });
            }

            const signFields = document.querySelectorAll('.click-to-sign');
            signFields.forEach(field => {
                field.addEventListener('click', function () {
                    const myName = this.getAttribute('data-myname');
                    const mySig = this.getAttribute('data-mysig');
                    const container = this.closest('.signature-container');
                    const sigDisplay = container.querySelector('.sig-display');
                    const hiddenInput = container.querySelector('input[type="hidden"]');

                    if (!mySig || mySig === "") {
                        alert("No signature found in your profile. Please upload one first.");
                        return;
                    }

                    if (this.value === "") {
                        if (confirm("Apply your name and signature here?")) {
                            this.value = myName;
                            hiddenInput.value = mySig;
                            sigDisplay.innerHTML =
                                `<img src="${mySig}" style="height:70px; mix-blend-mode:multiply; filter:contrast(1.2);">`;
                        }
                    } else {
                        if (confirm("Remove this signature?")) {
                            this.value = "";
                            hiddenInput.value = "";
                            sigDisplay.innerHTML = "";
                        }
                    }
                });
            });
        });
    </script>

    <!-- Photo View Modal - Enhanced -->
    <div class='modal fade' id='photoModal' tabindex='-1' aria-hidden='true'>
        <div class='modal-dialog modal-dialog-centered modal-xl'>
            <div class='modal-content'>
                <div class='modal-header'>
                    <h5 class='modal-title'><i class='bi bi-camera-fill'></i> Photo Preview</h5>
                    <button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='Close'></button>
                </div>
                <div class='modal-body text-center p-0' style='background: #1a1a1a; min-height: 50vh;'>
                    <div class='photo-controls mb-2'>
                        <button class='btn btn-sm btn-outline-light' onclick='zoomPhoto(0.1)'><i
                                class='bi bi-zoom-in'></i></button>
                        <button class='btn btn-sm btn-outline-light' onclick='zoomPhoto(-0.1)'><i
                                class='bi bi-zoom-out'></i></button>
                        <button class='btn btn-sm btn-outline-light' onclick='resetZoom()'><i
                                class='bi bi-arrow-counterclockwise'></i></button>
                    </div>
                    <img id='modalPhoto' src='' alt='Full Size Photo'
                        style='max-width: 100%; max-height: 70vh; transition: transform 0.2s ease;'>
                </div>
                <div class='modal-footer' style='background: #1a1a1a; border: none;'>
                    <a id='downloadPhoto' href='' class='btn btn-success' download target='_blank'><i
                            class='bi bi-download'></i> Download</a>
                    <button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>Close</button>
                </div>
            </div>

            <script src='https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js'></script>
            <script>
                // Current document ID for actions
                let currentDocId = <?= $id ?>;
                var currentZoom = 1;

                // Open Supply Modal
                function openSupplyModal() {
                    const supplyModal = new bootstrap.Modal(document.getElementById('supplyRequestModal'));
                    supplyModal.show();
                }

                // Mark as Complete
                function markAsComplete() {
                    if (confirm('Are you sure you want to mark this request as Complete?')) {
                        // Create and submit a form programmatically
                        const form = document.createElement('form');
                        form.method = 'POST';
                        form.action = '?id=<?= $id ?>&source=<?= $source ?>';

                        const completeInput = document.createElement('input');
                        completeInput.type = 'hidden';
                        completeInput.name = 'complete';
                        completeInput.value = '1';
                        form.appendChild(completeInput);

                        document.body.appendChild(form);
                        form.submit();
                    }
                }

                function showPhoto(photoPath) {
                    currentZoom = 1;
                    var myModal = new bootstrap.Modal(document.getElementById('photoModal'));
                    var img = document.getElementById('modalPhoto');
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
            </script>
</body>

</html>
