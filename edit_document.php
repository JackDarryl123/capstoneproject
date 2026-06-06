<?php
require_once __DIR__ . '/config.php';
require_once 'includes/session_helper.php';
start_user_session();

$id = $_GET['id'] ?? 0;

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

// ✅ Handle form submission (add or update)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['add_to_maintenance']) && !isset($_POST['archive'])) {
    $params = [
        $_POST['pre_repair_no'] ?? '',
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
            pre_repair_no=?, inspector_name=?, inspector_position=?, defect=?, findings=?, recommendation=?, carrying_amount=?,
            officer_name=?, inspected_by=?, inspected_by_sig=?, 
            approved_by_pepo=?, approved_by_pepo_sig=?, 
            witnessed_by=?, witnessed_by_sig=?, 
            approved_by_gso=?, approved_by_gso_sig=?, ";

        // Add materials 1-10 (20 placeholders)
        for ($i = 1; $i <= 10; $i++) {
            $sql .= "material_$i=?, quantity_$i=?, ";
        }

        // Remove trailing comma and space, then add WHERE clause
        $sql = rtrim($sql, ', ') . " WHERE id=?";

        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            die("SQL Error: " . $mysqli->error);
        }

        $update_params = $params;
        $update_params[] = $id;

        $types = str_repeat('s', count($params)) . 'i';

        // Use call_user_func_array for robust binding of dynamic params
        $stmt->bind_param($types, ...$update_params);

        if ($stmt->execute()) {
            echo "<div class='alert alert-success text-center mt-3 shadow-sm'>
                    <i class='bi bi-check-circle-fill me-2'></i> ✅ Document updated successfully!
                  </div>";
        } else {
            echo "<div class='alert alert-danger text-center mt-3 shadow-sm'>
                    <i class='bi bi-exclamation-triangle-fill me-2'></i> ❌ Error updating document: " . $stmt->error . "
                  </div>";
        }
        $stmt->close();
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
$my_role = $_SESSION['role'] ?? '';
$my_is_admin = $_SESSION['is_admin'] ?? 0;

// Determine user's signature authority
$user_can_sign = [];
if ($my_role === 'admin' && $my_is_admin == 1) {
    $user_can_sign = ['pepo'];
} elseif ($my_role === 'admin' && $my_is_admin == 0) {
    $user_can_sign = ['inspector'];
} elseif ($my_role === 'pgdh_gso') {
    $user_can_sign = ['gso'];
} elseif ($my_role === 'pgdh_pacco') {
    $user_can_sign = ['pacco'];
}
$user_can_sign_json = json_encode($user_can_sign);

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
?>

<!DOCTYPE html>
<html lang='en'>

<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Edit Document - Admin</title>
    <link href='https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css' rel='stylesheet'>
    <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css' rel='stylesheet'>
    <style>
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #e4edf5 100%);
            min-height: 100vh;
            padding: 20px;
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
            min-height: calc(100vh - 30px);
        }

        .document-viewer {
            flex: 1;
            background: white;
            border-radius: 20px;
            box-shadow: 0 12px 35px rgba(0, 0, 0, 0.08);
            padding: 30px;
            overflow-y: auto;
            max-height: calc(100vh - 20px);
            border: 1px solid #e9ecef;
            order: 1;
        }

        .floating-buttons {
            width: 340px;
            position: sticky;
            top: 20px;
            height: fit-content;
            background: white;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 12px 35px rgba(0, 0, 0, 0.08);
            border: 1px solid #e9ecef;
            order: 2;
            display: flex;
            flex-direction: column;
            gap: 25px;
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
            text-align: center;
            margin-top: 10px;
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
    <!-- Info Cards Row - Above the Form -->
    <div class="info-cards-row">

        <div class="info-card">
            <div class="card-header">
                <h6><i class="bi bi-camera-fill"></i> Photo Reference</h6>
                <span class="card-description">Visual documentation for this record.</span>
            </div>

            <?php if (!empty($doc['photo_path'])): ?>
                <div class="photo-overlay" onclick="showPhoto('<?= htmlspecialchars($doc['photo_path']) ?>')">
                    <img src="<?= htmlspecialchars($doc['photo_path']) ?>" alt="Document Photo">
                    <div class="overlay-text">
                        <i class="bi bi-zoom-in"></i> Click to enlarge
                    </div>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="bi bi-image"></i>
                    <p>No photo attached</p>
                    <span>Upload an image to see it here.</span>
                </div>
            <?php endif; ?>
        </div>

        <div class="info-card">
            <div class="card-header">
                <h6><i class="bi bi-paperclip"></i> Attached File</h6>
                <span class="card-description">Official document or related files.</span>
            </div>

            <?php if (!empty($doc['attached_file_path'])): ?>
                <div class="file-action-wrapper">
                    <a href="<?= htmlspecialchars($doc['attached_file_path']) ?>" class="file-link-btn" target="_blank"
                        rel="noopener noreferrer">
                        <div class="file-icon"><i class="bi bi-file-earmark-pdf-fill"></i></div>
                        <div class="file-text">
                            <strong>View Document</strong>
                            <span>Opens in a new tab</span>
                        </div>
                        <i class="bi bi-box-arrow-up-right external-icon"></i>
                    </a>

                </div>
                <p>This document serves to confirm that the PPE/ICS belongs to the Provincial Government of Occidental
                    Mindoro.</p>
            <?php else: ?>
                <div class="empty-state">
                    <i class="bi bi-file-earmark-x"></i>
                    <p>No file attached</p>
                    <span>Supported formats will appear here.</span>
                </div>
            <?php endif; ?>
        </div>

        <div class="info-card">
            <div class="card-header">
                <h6><i class="bi bi-chat-left-text-fill"></i> Remarks</h6>
                <span class="card-description">Additional notes and comments.</span>
            </div>

            <?php if (!empty($doc['remarks'])): ?>
                <div class="remarks-box">
                    <i class="bi bi-quote quote-icon"></i>
                    <p class="card-value"><?= nl2br(htmlspecialchars($doc['remarks'])) ?></p>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="bi bi-chat-dots"></i>
                    <p>No remarks added</p>
                    <span>Notes left by the user will display here.</span>
                </div>
            <?php endif; ?>
        </div>

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
                            <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
                                <input type='text' name='pre_repair_no' class='form-control form-control-lg signature-input'
                                    value="<?= htmlspecialchars($doc['pre_repair_no'] ?? '') ?>"
                                    placeholder='Enter Pre-Repair No'>
                            <?php else: ?>
                                <input type='text' class='form-control form-control-lg signature-input'
                                    value="<?= htmlspecialchars($doc['pre_repair_no'] ?? '') ?>" readonly>
                            <?php endif; ?>
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
                                    <td>
                                        <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
                                            <input type='number' name='carrying_amount' class='form-control form-control-lg'
                                                value="<?= htmlspecialchars($doc['carrying_amount'] ?? '') ?>"
                                                placeholder='Enter Carrying Amount' step='0.01' min='0'>
                                        <?php else: ?>
                                            <input type='text' class='form-control form-control-lg'
                                                value="<?= htmlspecialchars($doc['carrying_amount'] ?? '') ?>" readonly>
                                        <?php endif; ?>
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
                                    data-mysig="<?= htmlspecialchars($my_signature) ?>" data-sign-role="inspector"
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
                                    data-mysig="<?= htmlspecialchars($my_signature) ?>" data-sign-role="pepo"
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
                                    data-mysig="<?= htmlspecialchars($my_signature) ?>" data-sign-role="pacco"
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
                                    data-mysig="<?= htmlspecialchars($my_signature) ?>" data-sign-role="gso"
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

        <!-- Floating Action Buttons on Right Side -->
        <div class='floating-buttons no-print'>
            <h4>
                <i class='bi bi-pencil-square'></i> Edit Document
            </h4>

            <a href='admin_dashboard.php?view=documents' class='btn-action btn-back'>
                <i class='bi bi-arrow-left'></i>
                <span>Back to Documents</span>
            </a>

            <button type='submit' form='mainForm' class='btn-action btn-save'>
                <i class='bi bi-save'></i>
                <span>Save Changes</span>
            </button>
            <!-- 
            <button type='button' class='btn-action btn-print' onclick='window.print()'>
                <i class='bi bi-printer'></i>
                <span>Print Document</span>
            </button> -->

            <button type='submit' name='add_to_maintenance' form='mainForm' class='btn-action btn-maintenance'>
                <i class='bi bi-tools'></i>
                <span>Add to Maintenance</span>
            </button>

            <button type='submit' name='archive' form='mainForm' class='btn-action btn-archive'>
                <i class='bi bi-archive'></i>
                <span>Archive Document</span>
            </button>

            <div class='document-info'>
                <h6><i class='bi bi-info-circle'></i> Document Information </h6>

                <div class='info-item'>
                    <span class='info-label'>Pre-Repair No:</span>
                    <span class='info-value'><?= htmlspecialchars($doc['pre_repair_no']) ?></span>
                </div>

                <div class='info-item'>
                    <span class='info-label'>Property No:</span>
                    <span class='info-value'><?= htmlspecialchars($doc['property_no']) ?></span>
                </div>

                <h6 class='mt-3 mb-2'><i class='bi bi-clock-history'></i> Request Progress Timeline</h6>

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
                <small>
                    <i class='bi bi-lightbulb'></i>
                    Note: Click Print Document to generate your physical copy of this request
                </small>
            </div>
        </div>
    </div>

    <script src='https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js'></script>
    <script>
        const userCanSign = <?= $user_can_sign_json ?>;

        function getRoleLabel(role) {
            const labels = {
                'inspector': 'Inspector',
                'pepo': 'PGDH-PEPO',
                'pacco': 'PGDH-PACCO',
                'gso': 'PGDH-GSO'
            };
            return labels[role] || role;
        }

        document.addEventListener('DOMContentLoaded', function () {
            // Select all inputs that have the 'click-to-sign' class
            const signFields = document.querySelectorAll('.click-to-sign');

            signFields.forEach(field => {
                field.addEventListener('click', function () {
                    // 1. Get data from the clicked element's attributes
                    const myName = this.getAttribute('data-myname');
                    const mySig = this.getAttribute('data-mysig');
                    const signRole = this.getAttribute('data-sign-role');
                    const container = this.closest('.signature-container');
                    const sigDisplay = container.querySelector('.sig-display');
                    const hiddenInput = container.querySelector('input[type="hidden"]');

                    // 2. Check if user is authorized to sign this field
                    if (!userCanSign.includes(signRole)) {
                        const allowedFields = userCanSign.map(r => getRoleLabel(r)).join(', ');
                        alert("You are not authorized to sign this field.\n\nYou can only sign: " + allowedFields);
                        return;
                    }

                    // 3. Check if a signature exists for this user
                    if (!mySig || mySig === "") {
                        alert("No signature found in your profile. Please upload one first.");
                        return;
                    }

                    // 4. Confirm and Apply
                    if (this.value === "") {
                        if (confirm("Apply your name and signature here?")) {
                            this.value = myName; // Fills the text box
                            hiddenInput.value = mySig; // Fills hidden path for database
                            sigDisplay.innerHTML =
                                `<img src="${mySig}" style="height:60px; width:auto; mix-blend-mode:multiply; filter:contrast(1.2);">`;
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

            // Add confirmation for Add to Maintenance button
            const maintenanceBtn = document.querySelector('button[name="add_to_maintenance"]');
            if (maintenanceBtn) {
                maintenanceBtn.addEventListener('click', function (e) {
                    if (!confirm('Be sure to complete document process before adding to maintenance')) {
                        e.preventDefault();
                        return false;
                    }
                    return true;
                });
            }

            // Add confirmation for Archive button
            const archiveBtn = document.querySelector('button[name="archive"]');
            if (archiveBtn) {
                archiveBtn.addEventListener('click', function (e) {
                    if (!confirm('Are you sure you want to archive this document?')) {
                        e.preventDefault();
                        return false;
                    }
                    return true;
                });
            }
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
        </div>
    </div>

    <script>
        var currentZoom = 1;

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
