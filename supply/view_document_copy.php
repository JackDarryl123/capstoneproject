<?php
require_once __DIR__ . '/../includes/session_helper.php';
start_user_session();

// Database connection
$mysqli = new mysqli('localhost', 'root', '', 'user_management');
if ($mysqli->connect_errno) {
    die("Database connection failed: " . $mysqli->connect_error);
}

$id = $_GET['id'] ?? 0;
$reqId = $_GET['req'] ?? 0;


// 1. FETCH DOCUMENT DATA (for the main document form)
$stmt = $mysqli->prepare("
    SELECT d.*, e.description, e.designation, e.acquisition_date, 
           e.acquisition_cost, e.last_repair_date
    FROM documents d
    LEFT JOIN equipment e ON d.property_no = e.property_no
    WHERE d.id = ?
");
$stmt->bind_param( 'i', $id );
$stmt->execute();
$result = $stmt->get_result();
$documentData = $result->fetch_assoc(); // Store in $documentData
$stmt->close();

if ( !$documentData ) {
    die( 'Document not found.' );
}

// 2. FETCH SUPPLY REQUESTS DATA (for floating buttons)
$stmt2 = $mysqli->prepare("
    SELECT 
        id,
        requested_by, 
        admin_location,
        remarks,
        status,
        created_at,
        updated_at
    FROM supply_requests 
    WHERE id = ?
");
$stmt2->bind_param('i', $reqId);
$stmt2->execute();
$result2 = $stmt2->get_result();
$supplyData = $result2->fetch_assoc(); // Store in $supplyData
$stmt2->close();

// If no supply data found, create empty array
if ( !$supplyData ) {
    $supplyData = [];
}

?>
<!DOCTYPE html>
<html lang='en'>

<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>View Document</title>
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

    /* Signature Image Clean-up */
    .printed-signature {
        mix-blend-mode: multiply;
        filter: contrast(1.5) brightness(1.1);
        max-height: 80px !important;
    }

    @media print {
        @page {
            /* Set to Legal size */
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

        /* Compression to fit one page */
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

        /* Force background colors and text colors to appear */
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

        /* Ensure the printable area container also allows colors */
        .printable-area {
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        /* Shrink the headers/logos */
        .printable-area header img {
            height: 50px !important;
            width: auto !important;
        }

        .printable-area header {
            margin-bottom: 10px !important;
        }

        /* Reduce table spacing */
        .table {
            margin-bottom: 5px !important;
        }

        .table th,
        .table td {
            padding: 1px 4px !important;
        }

        /* Reduce spacing between sections */
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
    }

    .sig-img {
        height: 60px;
        width: auto;
        mix-blend-mode: multiply;
        filter: contrast(1.2);
        display: block;
        margin: 0 auto -15px auto;
    }

    /* New Layout Styles */
    .document-container {
        display: flex;
        gap: 30px;
        min-height: calc(100vh - 40px);
    }

    .document-viewer {
        flex: 1;
        background: white;
        border-radius: 20px;
        box-shadow: 0 12px 35px rgba(0, 0, 0, 0.08);
        padding: 30px;
        overflow-y: auto;
        max-height: calc(100vh - 40px);
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

    .btn-print {
        background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
        color: white;
        border: none;
        position: relative;
        z-index: 1;
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

    /* Status Tracker Styles */
    .status-tracker {
        display: flex;
        flex-direction: column;
        padding-left: 10px;
    }

    .status-step {
        display: flex;
        align-items: flex-start;
        min-height: 45px;
    }

    .status-line {
        display: flex;
        flex-direction: column;
        align-items: center;
        margin-right: 15px;
    }

    .status-dot {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 14px;
        z-index: 2;
        transition: all 0.3s ease;
    }

    .status-dot.completed,
    .status-dot.bg-success { background: #198754; }
    .status-dot.bg-primary { background: #0d6efd; }
    .status-dot.bg-warning { background: #ffc107; color: #000; }
    .status-dot.bg-secondary { background: #6c757d; }

    .status-connector {
        width: 3px;
        height: 20px;
        background: #e9ecef;
        margin: 2px 0;
    }

    .status-connector.active {
        background: #198754;
    }

    .status-content {
        padding-top: 5px;
        flex: 1;
    }

    .status-label {
        font-size: 14px;
        transition: all 0.3s ease;
    }

    .status-step.completed .status-label {
        font-weight: 600;
    }

    .status-step.current .status-dot {
        box-shadow: 0 0 0 4px rgba(25, 135, 84, 0.2);
        animation: pulse 2s infinite;
    }

    @keyframes pulse {
        0% { box-shadow: 0 0 0 0 rgba(25, 135, 84, 0.4); }
        70% { box-shadow: 0 0 0 8px rgba(25, 135, 84, 0); }
        100% { box-shadow: 0 0 0 0 rgba(25, 135, 84, 0); }
    }

    .status-timestamp {
        display: block;
        font-size: 11px;
        color: #6c757d;
        margin-top: 2px;
        font-weight: 400;
    }
    </style>
</head>

<body>
    <div class='document-container'>
        <!-- Document Content on Left Side -->
        <div class='document-viewer'>
            <div class='printable-area'>
                <!-- Header -->
                <header class='d-flex align-items-center justify-content-center mb-4 text-center'>
                    <img src='../rs/Pepo_Logo.png' alt='PEPO Logo' style='width:100px; height:auto; margin-right:30px;'>
                    <div>
                        <small class='d-block'>Republic of the Philippines</small>
                        <small class='d-block'>PROVINCIAL GOVERNMENT OF OCCIDENTAL MINDORO</small>
                        <small class='d-block fw-bold'>GENERAL SERVICES OFFICE</small>
                    </div>
                    <img src='../rs/BAGONG-PILIPINAS-LOGO.png' alt='Occidental Mindoro Logo'
                        style='width:140px; height:auto; margin-left:15px;'>
                </header>

                <!-- Certification Section -->
                <section class='mb-4'>
                    <h5 class='text-center fw-bold text-success border-bottom border-success pb-2'>CERTIFICATION</h5>

                    <div class='text-start mb-3 mt-2 d-flex align-items-center'>
                        <small class='fw-bold' style='white-space: nowrap; font-size: 20px;'>Pre-Repair No:</small>
                        <input type='text' class='form-control form-control-lg signature-input'
                            value="<?= htmlspecialchars($documentData['pre_repair_no']) ?>" readonly>
                    </div>

                    <table class='table table-bordered mb-2 align-middle'>
                        <tbody>
                            <tr>
                                <td class='fw-bold w-25'>Property Number:</td>
                                <td><input type='text' class='form-control form-control-lg'
                                        value="<?= htmlspecialchars($documentData['property_no']) ?>" readonly></td>
                            </tr>
                            <tr>
                                <td class='fw-bold'>Description of Property:</td>
                                <td><textarea class='form-control form-control-lg' rows='3'
                                        readonly><?= htmlspecialchars($documentData['description']) ?></textarea></td>
                            </tr>
                            <tr>
                                <td class='fw-bold'>Designation of Property:</td>
                                <td><input type='text' class='form-control form-control-lg'
                                        value="<?= htmlspecialchars($documentData['designation']) ?>" readonly></td>
                            </tr>
                            <tr>
                                <td class='fw-bold'>Acquisition Date:</td>
                                <td><input type='text' class='form-control form-control-lg'
                                        value="<?= htmlspecialchars($documentData['acquisition_date']) ?>" readonly>
                                </td>
                            </tr>
                            <tr>
                                <td class='fw-bold'>Acquisition Cost:</td>
                                <td><input type='text' class='form-control form-control-lg'
                                        value="<?= htmlspecialchars($documentData['acquisition_cost']) ?>" readonly>
                                </td>
                            </tr>
                            <tr>
                                <td class='fw-bold'>Date of Last Repair:</td>
                                <td><input type='text' class='form-control form-control-lg'
                                        value="<?= htmlspecialchars($documentData['last_repair_date']) ?>" readonly>
                                </td>
                            </tr>
                            <tr>
                                <td class='fw-bold'>Carrying Amount:</td>
                                <td><input type='text' class='form-control form-control-lg'
                                        value="<?= htmlspecialchars($documentData['carrying_amount'] ?? '') ?>"
                                        readonly></td>
                            </tr>
                        </tbody>
                    </table>

                    <p>(Attach a copy of latest job order)
                        This document serves to confirm that the PPE/ICS belongs to the Provincial Government of
                        Occidental Mindoro.
                    </p>

                    <!-- Property Custodian Officer -->
                    <div class='d-flex justify-content-center mt-3'>
                        <div class='text-center' style='width: 50%;'>
                            <?php if (!empty($documentData['signature'])): ?>
                            <div class='mb-2 text-center'>
                                <img src="../<?= htmlspecialchars($documentData['signature']) ?>" alt='Signature'
                                    style='height: 50px; width: auto; mix-blend-mode: multiply; filter: contrast(1.2);'>
                            </div>
                            <?php endif; ?>

                            <input type='text' class='form-control form-control-lg text-center signature-input'
                                value="<?= htmlspecialchars($documentData['officer_name'] ?? '') ?>" readonly>
                            <small>Property Custodian Officer</small>
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
                            <input type='text' class='form-control d-inline mx-2 signature-input'
                                style="width: 32%; display: inline-block; font-family: 'Times New Roman', Times, serif; font-size: 20px;"
                                value="<?= htmlspecialchars($documentData['inspector_name'] ?? '') ?>" readonly> certify
                            under
                            penalty of law that as (Position)

                            <input type='text' class='form-control d-inline mx-2 signature-input'
                                style="width: 25%; display: inline-block; font-family: 'Times New Roman', Times, serif; font-size: 20px;"
                                value="<?= htmlspecialchars($documentData['inspector_position'] ?? '') ?>" readonly> ,

                            I have carefully examined the Above-Mentioned Property of the Provincial Government of
                            Occidental Mindoro.
                        </p>
                    </div>

                    <div class='mb-3'>
                        <label class='fw-bold'>Defect/Complaint:</label>
                        <textarea class='form-control form-control-lg signature-input' rows='3'
                            readonly><?= htmlspecialchars($documentData['defect'] ?? '') ?></textarea>
                    </div>

                    <div class='mb-3'>
                        <label class='fw-bold'>Findings:</label>
                        <textarea class='form-control form-control-lg signature-input' rows='3'
                            readonly><?= htmlspecialchars($documentData['findings'] ?? '') ?></textarea>
                    </div>

                    <div class='mb-3'>
                        <label class='fw-bold'>Recommendation:</label><br>
                        <input type='radio'
                            <?= ($documentData['recommendation'] ?? '') === 'For In-House Repair' ? 'checked' : '' ?>
                            disabled>
                        For In-House Repair
                        <br>
                        <input type='radio'
                            <?= ($documentData['recommendation'] ?? '') === 'For Outside Repair' ? 'checked' : '' ?>
                            disabled>
                        For Outside Repair
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
                            <?php for ($i = 1; $i <= 10; $i++): ?>
                            <tr>
                                <td><?= $i ?></td>
                                <td>
                                    <input type='text' class='form-control form-control-lg text-center'
                                        value="<?= htmlspecialchars($documentData["material_$i"] ?? '') ?>" readonly>
                                </td>
                                <td>
                                    <input type='text' class='form-control form-control-lg text-center'
                                        value="<?= htmlspecialchars($documentData["quantity_$i"] ?? '') ?>" readonly>
                                </td>
                            </tr>
                            <?php endfor; ?>
                        </tbody>
                    </table>
                </section>

                <!-- Signature Fields -->
                <div class='row text-center mt-5'>
                    <div class='col-6 mb-3'>
                        <label class='fw-bold'>Pre-Inspected by:</label>
                        <div class='signature-container' style='min-height: 100px;'>
                            <div class='sig-display'>
                                <?php if (!empty($documentData['inspected_by_sig'])): ?>
                                <img src="../<?= htmlspecialchars($documentData['inspected_by_sig']) ?>"
                                    style='height:30px; mix-blend-mode:multiply;'>
                                <?php endif; ?>
                            </div>
                            <input type='text' class='form-control text-center signature-input' readonly
                                value="<?= htmlspecialchars($documentData['inspected_by'] ?? '') ?>">
                            <small>Inspector</small>
                        </div>
                    </div>

                    <div class='col-6 mb-3'>
                        <label class='fw-bold'>Approved:</label>
                        <div class='signature-container' style='min-height: 100px;'>
                            <div class='sig-display'>
                                <?php if (!empty($documentData['approved_by_pepo_sig'])): ?>
                                <img src="../<?= htmlspecialchars($documentData['approved_by_pepo_sig']) ?>"
                                    style='height:30px; mix-blend-mode:multiply;'>
                                <?php endif; ?>
                            </div>
                            <input type='text' class='form-control text-center signature-input' readonly
                                value="<?= htmlspecialchars($documentData['approved_by_pepo'] ?? '') ?>">
                            <small>PGDH-PEPO</small>
                        </div>
                    </div>

                    <div class='col-6 mb-3'>
                        <label class='fw-bold'>Witnessed:</label>
                        <div class='signature-container' style='min-height: 100px;'>
                            <div class='sig-display'>
                                <?php if (!empty($documentData['witnessed_by_sig'])): ?>
                                <img src="../<?= htmlspecialchars($documentData['witnessed_by_sig']) ?>"
                                    style='height:30px; mix-blend-mode:multiply;'>
                                <?php endif; ?>
                            </div>
                            <input type='text' class='form-control text-center signature-input' readonly
                                value="<?= htmlspecialchars($documentData['witnessed_by'] ?? '') ?>">
                            <small>PGDH-PACCO</small>
                        </div>
                    </div>

                    <div class='col-6 mb-3'>
                        <label class='fw-bold'>Approved:</label>
                        <div class='signature-container' style='min-height: 100px;'>
                            <div class='sig-display'>
                                <?php if (!empty($documentData['approved_by_gso_sig'])): ?>
                                <img src="../<?= htmlspecialchars($documentData['approved_by_gso_sig']) ?>"
                                    style='height:30px; mix-blend-mode:multiply;'>
                                <?php endif; ?>
                            </div>
                            <input type='text' class='form-control text-center signature-input' readonly
                                value="<?= htmlspecialchars($documentData['approved_by_gso'] ?? '') ?>">
                            <small>PGDH-GSO</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>


        <!-- Floating Action Buttons on Right Side -->
        <div class='floating-buttons no-print'>
            <h4>
                <i class='bi bi-file-text'></i> Action Buttons
            </h4>

            <button class='btn-action btn-back' onclick="window.location.href='supply_dashboard.php?view=documents'">
                <i class='bi bi-arrow-left'></i>
                <span>Back to Documents</span>
            </button>

            <button class='btn-action btn-print' onclick='window.print()'>
                <i class='bi bi-printer'></i>
                <span>Print Document</span>
            </button>

            <!-- Document Info with Progress Tracker -->
            <div class='document-info'>
                <h6><i class='bi bi-info-circle'></i> Document Information</h6>

                <div class='info-item'>
                    <span class='info-label'>Requested By:</span>
                    <span class='info-value'><?= htmlspecialchars($supplyData['requested_by'] ?? 'N/A') ?></span>
                </div>
                <div class='info-item'>
                    <span class='info-label'>Admin Location:</span>
                    <span class='info-value'><?= htmlspecialchars($supplyData['admin_location'] ?? 'N/A') ?></span>
                </div>
                <div class='info-item'>
                    <span class='info-label'>Remarks:</span>
                    <span class='info-value'><?= htmlspecialchars($supplyData['remarks'] ?? 'N/A') ?></span>
                </div>

                <?php
                $currentStatus = $supplyData['status'] ?? 'pending';
                $createdAt = $supplyData['created_at'] ?? null;
                $updatedAt = $supplyData['updated_at'] ?? null;

                $statuses = [
                    'pending' => ['label' => 'Pending', 'icon' => 'bi-clock', 'color' => 'secondary', 'timestamp' => $createdAt],
                    'approved' => ['label' => 'Approved', 'icon' => 'bi-check-circle', 'color' => 'primary', 'timestamp' => $updatedAt],
                    'ordered' => ['label' => 'Ordered', 'icon' => 'bi-cart', 'color' => 'warning', 'timestamp' => null],
                    'delivered' => ['label' => 'Delivered', 'icon' => 'bi-truck', 'color' => 'success', 'timestamp' => null]
                ];

                $statusOrder = ['pending', 'approved', 'ordered', 'delivered'];
                $currentIndex = array_search($currentStatus, $statusOrder);
                if ($currentIndex === false) $currentIndex = -1;
                ?>

                <div class='info-item'>
                    <span class='info-label'>Status:</span>
                </div>
                <div class='status-tracker mt-2'>
                    <?php foreach ($statusOrder as $index => $statusKey): ?>
                        <?php
                        $isCompleted = $index <= $currentIndex;
                        $isCurrent = $index === $currentIndex;
                        $statusInfo = $statuses[$statusKey];
                        $timestamp = $statusInfo['timestamp'];
                        ?>
                        <div class='status-step <?= $isCompleted ? 'completed' : '' ?> <?= $isCurrent ? 'current' : '' ?>'>
                            <div class='status-line <?= $index === 0 ? 'first' : '' ?>'>
                                <div class='status-dot <?= $isCompleted ? 'bg-' . $statusInfo['color'] : 'bg-secondary' ?>'>
                                    <?php if ($isCompleted): ?>
                                        <i class='bi <?= $statusInfo['icon'] ?>'></i>
                                    <?php endif; ?>
                                </div>
                                <?php if ($index < count($statusOrder) - 1): ?>
                                    <div class='status-connector <?= $index < $currentIndex ? 'active' : '' ?>'></div>
                                <?php endif; ?>
                            </div>
                            <div class='status-content'>
                                <span class='status-label <?= $isCompleted ? 'text-' . $statusInfo['color'] : 'text-muted' ?> <?= $isCurrent ? 'fw-bold' : '' ?>'>
                                    <?= $statusInfo['label'] ?>
                                </span>
                                <?php if ($timestamp && $isCompleted): ?>
                                    <span class='status-timestamp'><?= date('M j, Y g:i A', strtotime($timestamp)) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

        <script src='https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js'></script>
</body>

</html>