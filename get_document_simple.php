<?php
// get_document_simple.php - SIMPLIFIED VERSION
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/mail_config.php';

// Error reporting for production - log errors instead of displaying
error_reporting(0);
ini_set('display_errors', 0);

$id = intval($_GET['id'] ?? 0);
$edit_mode = isset($_GET['edit']) && $_GET['edit'] === 'true';

// Return JSON if requested
if (isset($_GET['json'])) {
    header('Content-Type: application/json');

    if ($id == 0) {
        echo json_encode(['error' => 'Invalid document ID']);
        exit;
    }

    $query = "
        SELECT 
            d.*,
            e.property_no,
            e.description,
            e.designation,  
            e.acquisition_date,
            e.acquisition_cost,
            e.last_repair_date,
            c.category_name
        FROM documents d
        LEFT JOIN equipment e ON d.property_no = e.property_no
        LEFT JOIN equipment_category c ON d.category_id = c.id
        WHERE d.id = ?
    ";

    $stmt = $mysqli->prepare($query);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $document = $result->fetch_assoc();
    $stmt->close();
    $mysqli->close();

    if ($document) {
        echo json_encode($document);
    } else {
        echo json_encode(['error' => 'Document not found']);
    }
    exit;
}

if ($id == 0) {
    echo "<div class='alert alert-danger'>Invalid document ID.</div>";
    exit;
}

// Simple query without complex joins
$query = "
    SELECT 
        d.*,
        e.property_no,
        e.description,
        e.designation,  
        e.acquisition_date,
        e.acquisition_cost,
        e.last_repair_date,
        c.category_name
    FROM documents d
    LEFT JOIN equipment e ON d.property_no = e.property_no
    LEFT JOIN equipment_category c ON d.category_id = c.id
    WHERE d.id = ?
";

$stmt = $mysqli->prepare($query);
if (!$stmt) {
    // Try an even simpler query
    $query = "SELECT * FROM documents WHERE id = ?";
    $stmt = $mysqli->prepare($query);

    if (!$stmt) {
        error_log("Query prepare failed: " . $mysqli->error);
        echo "<div class='alert alert-danger'>Unable to load document details. Please try again later.</div>";
        exit;
    }
}

$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$document = $result->fetch_assoc();

// ✅ Check for supply_id parameter to override status and fetch supply request data
$supply_id = isset($_GET['supply_id']) ? (int) $_GET['supply_id'] : null;
$is_supply_view = false;
$current_status = $document['status'] ?? 'Pending';
$supply_remarks = null;
$supply_approved_at = null;
$supply_complied_at = null;
$supply_received_at = null;
$supply_created_at = null;

if ($supply_id) {
    $supply_query = "SELECT status, remarks, approved_at, complied_at, received_at, created_at FROM supply_requests WHERE id = ?";
    $supply_stmt = $mysqli->prepare($supply_query);
    $supply_stmt->bind_param("i", $supply_id);
    $supply_stmt->execute();
    $supply_res = $supply_stmt->get_result();
    if ($supply_row = $supply_res->fetch_assoc()) {
        $current_status = $supply_row['status'];
        $is_supply_view = true;
        $supply_remarks = $supply_row['remarks'];
        $supply_approved_at = $supply_row['approved_at'];
        $supply_complied_at = $supply_row['complied_at'];
        $supply_received_at = $supply_row['received_at'];
        $supply_created_at = $supply_row['created_at'];
    }
    $supply_stmt->close();
}

// ✅ Check if a supply request already exists for this document's pre_repair_no
$supplyRequestExists = false;
if (!empty($document['pre_repair_no'])) {
    $checkQuery = "SELECT id FROM supply_requests WHERE pre_repair_no = ?";
    $checkStmt = $mysqli->prepare($checkQuery);
    $checkStmt->bind_param("s", $document['pre_repair_no']);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    if ($checkResult->num_rows > 0) {
        $supplyRequestExists = true;
    }
    $checkStmt->close();
}

if (!$document) {
    echo "<div class='alert alert-danger'>Document not found.</div>";
    exit;
}

// Close statement
$stmt->close();

// Debug: Log the document data to check what's being fetched
error_log("Document ID: " . $id . " - Inspector: " . ($document['inspector_name'] ?? 'NULL') . " - Defect: " . ($document['defect'] ?? 'NULL'));

// Get the signature from documents table (if it exists there)
$documentSignature = $document['signature'] ?? '';

// If not in documents table, try to get it from users table
if (empty($documentSignature)) {
    // First, get the officer_name from documents table
    $officerName = $document['officer_name'] ?? '';

    if (!empty($officerName)) {
        // Try to find the user by officer_name (username)
        $userQuery = $mysqli->prepare("SELECT signature FROM users WHERE username = ?");
        if ($userQuery) {
            $userQuery->bind_param("s", $officerName);
            $userQuery->execute();
            $userResult = $userQuery->get_result();
            if ($userRow = $userResult->fetch_assoc()) {
                $documentSignature = $userRow['signature'];
            }
            $userQuery->close();
        }
    }
}

// Set default values for missing fields (only use placeholders if explicitly needed)
// Otherwise, leave empty to show real database state
$document['inspector_name'] = $document['inspector_name'] ?? '';
$document['inspector_position'] = $document['inspector_position'] ?? '';
$document['officer_name'] = $document['officer_name'] ?? $document['inspector_name'] ?? '';
$document['signature'] = $documentSignature;

// ✅ Define Workflow Steps
if ($is_supply_view) {
    // Supply Request Workflow - matches supply_requests table status enum
    $status_steps = [
        'pending' => [
            'date' => $supply_created_at ?? null,
            'label' => 'Pending Request',
            'icon' => 'bi-hourglass-split'
        ],
        'approved' => [
            'date' => $supply_approved_at ?? null,
            'label' => 'Approved by Supply',
            'icon' => 'bi-check2-all'
        ],
        'complied' => [
            'date' => $supply_complied_at ?? null,
            'label' => 'Request Complied',
            'icon' => 'bi-cart-check'
        ],
        'received' => [
            'date' => $supply_received_at ?? null,
            'label' => 'Request Received',
            'icon' => 'bi-box-seam'
        ]
    ];

    $status_to_index = [
        'pending' => 0,
        'approved' => 1,
        'complied' => 2,
        'received' => 3
    ];
} else {
    // Standard Document Workflow
    $status_steps = [
        'Pending' => [
            'date' => $document['date_requested'] ?? null,
            'label' => 'Received Request',
            'icon' => 'bi-file-earmark-plus'
        ],
        'Approved' => [
            'date' => $document['date_approved'] ?? null,
            'label' => 'Request Approval',
            'icon' => 'bi-check2-circle'
        ],
        'Done' => [
            'date' => $document['date_done'] ?? null,
            'label' => 'Done Inspection/Verification',
            'icon' => 'bi-clipboard-check'
        ],
        'Complete' => [
            'date' => $document['date_completed'] ?? null,
            'label' => 'Complete Maintenance/Repair',
            'icon' => 'bi-tools'
        ]
    ];

    $status_to_index = [
        'Pending' => 0,
        'Approved' => 1,
        'Done' => 2,
        'Complete' => 3
    ];
}

$current_index = $status_to_index[$current_status] ?? 0;
?>

<!-- Location and Date data for modal header -->
<div id="locationData" data-location="<?php echo htmlspecialchars($document['location'] ?? ''); ?>"
    data-date-requested="<?php echo htmlspecialchars($document['date_requested'] ?? ''); ?>" style="display: none;">
</div>

<!-- Metadata for Supply Request populating -->
<div id="documentMeta" data-pre-repair-no="<?php echo htmlspecialchars($document['pre_repair_no'] ?? ''); ?>"
    data-property-no="<?php echo htmlspecialchars($document['property_no'] ?? ''); ?>"
    data-attached-file-path="<?php echo htmlspecialchars($document['attached_file_path'] ?? ''); ?>"
    data-supply-request-exists="<?php echo $supplyRequestExists ? 'true' : 'false'; ?>"
    data-supply-id="<?php echo htmlspecialchars($supply_id ?? ''); ?>"
    data-supply-status="<?php echo htmlspecialchars($current_status); ?>" style="display: none;"></div>

<!-- Main Content Wrapper with White Background -->
<div style="background: white; padding: 20px; min-height: 100%;">

    <style>
        /* Horizontal Timeline Styles */
        .timeline-horizontal {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            position: relative;
            margin-bottom: 30px;
            padding: 0 10px;
        }

        .timeline-horizontal::before {
            content: '';
            position: absolute;
            top: 20px;
            left: 40px;
            right: 40px;
            height: 3px;
            background: #e9ecef;
            z-index: 0;
        }

        .timeline-step {
            position: relative;
            z-index: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            width: 25%;
            text-align: center;
        }

        .step-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: white;
            border: 3px solid #e9ecef;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 8px;
            transition: all 0.3s ease;
            font-size: 18px;
            color: #adb5bd;
        }

        .timeline-step.completed .step-icon {
            background: #198754;
            border-color: #198754;
            color: white;
        }

        .timeline-step.current .step-icon {
            background: #fff;
            border-color: #0d6efd;
            color: #0d6efd;
            box-shadow: 0 0 0 5px rgba(13, 110, 253, 0.1);
            animation: pulse-blue 1.5s infinite;
        }

        @keyframes pulse-blue {
            0% {
                box-shadow: 0 0 0 0 rgba(13, 110, 253, 0.4);
            }

            70% {
                box-shadow: 0 0 0 8px rgba(13, 110, 253, 0);
            }

            100% {
                box-shadow: 0 0 0 0 rgba(13, 110, 253, 0);
            }
        }

        .step-label {
            font-size: 12px;
            font-weight: 600;
            color: #495057;
            margin-bottom: 2px;
            line-height: 1.2;
            padding: 0 4px;
        }

        .timeline-step.completed .step-label {
            color: #198754;
        }

        .timeline-step.current .step-label {
            color: #0d6efd;
        }

        .step-date {
            font-size: 10px;
            color: #6c757d;
            line-height: 1;
        }

        /* Timeline progress bar */
        .timeline-progress {
            position: absolute;
            top: 20px;
            left: 40px;
            height: 3px;
            background: #198754;
            z-index: 0;
            transition: width 0.5s ease;
        }

        /* Minimal Info Cards Styles */
        .info-cards-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }

        .info-card {
            background: #fff;
            border-radius: 10px;
            padding: 15px;
            border: 1px solid #e9ecef;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .card-header {
            margin-bottom: 10px;
            padding-bottom: 8px;
            border-bottom: 2px solid #198754;
        }

        .info-card h6 {
            font-size: 14px;
            color: #198754;
            font-weight: 600;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .card-description {
            font-size: 11px;
            color: #6c757d;
            margin-top: 2px;
        }

        /* Photo */
        .photo-overlay {
            position: relative;
            cursor: pointer;
            border-radius: 6px;
            overflow: hidden;
        }

        .photo-overlay img {
            width: 100%;
            height: 100px;
            object-fit: cover;
            border-radius: 6px;
        }

        .photo-overlay .overlay-text {
            position: absolute;
            inset: 0;
            background: rgba(25, 135, 84, 0.8);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.3s;
            font-size: 12px;
            border-radius: 6px;
        }

        .photo-overlay:hover .overlay-text {
            opacity: 1;
        }

        /* File */
        .file-action-wrapper {
            display: flex;
            flex-direction: column;
        }

        .file-link-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            color: #0d6efd;
            text-decoration: none;
            font-size: 13px;
        }

        .file-link-btn:hover {
            background: #e9ecef;
            color: #0a58ca;
        }

        .file-link-btn .file-icon i {
            font-size: 20px;
            color: #198754;
        }

        /* Remarks */
        .remarks-box {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 6px;
            font-size: 13px;
            color: #495057;
            border-left: 3px solid #198754;
            min-height: 60px;
        }

        /* Empty State */
        .empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: #f8f9fa;
            border: 1px dashed #dee2e6;
            border-radius: 6px;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 24px;
            margin-bottom: 5px;
            opacity: 0.5;
        }

        .empty-state p {
            font-size: 12px;
            margin: 0;
            font-weight: 500;
        }

        .empty-state span {
            font-size: 10px;
            opacity: 0.7;
        }

        @media (max-width: 768px) {
            .info-cards-row {
                grid-template-columns: 1fr;
            }

            .timeline-horizontal::before,
            .timeline-progress {
                top: 15px;
                left: 20px;
                right: 20px;
            }

            .step-icon {
                width: 30px;
                height: 30px;
                font-size: 14px;
                margin-bottom: 5px;
            }

            .step-label {
                font-size: 8.5px;
                min-height: 25px;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 0 2px;
            }

            .step-date {
                font-size: 7.5px;
            }

            .timeline-horizontal {
                padding: 0;
                margin-bottom: 20px;
            }
        }

        @media (max-width: 480px) {
            .step-label {
                font-size: 7px;
            }
        }
    </style>

    <?php if ($edit_mode): ?>
        <form id="editDocumentForm" enctype="multipart/form-data">
        <?php endif; ?>

        <input type="hidden" name="id" value="<?php echo $id; ?>">

        <div class="p-3">
            <!-- Horizontal Timeline -->
            <div class="timeline-horizontal">
                <?php
                $progress_width = ($current_index / (count($status_steps) - 1)) * 100;
                if ($current_status === 'Pending')
                    $progress_width = 0;
                ?>
                <div class="timeline-progress"
                    style="width: calc(<?php echo $progress_width; ?>% - <?php echo ($current_index == 0) ? '0px' : '40px'; ?>);">
                </div>

                <?php foreach ($status_steps as $step_key => $step):
                    $step_index = $status_to_index[$step_key];
                    $is_completed = $step_index < $current_index || ($current_status === 'Complete' && $step_key === 'Complete');
                    $is_current = $step_index === $current_index && $current_status !== 'Complete';
                    if ($current_status === 'Complete' && $step_key === 'Complete')
                        $is_current = false;

                    $class = '';
                    if ($is_completed)
                        $class = 'completed';
                    if ($is_current)
                        $class = 'current';
                    ?>
                    <div class="timeline-step <?php echo $class; ?>">
                        <div class="step-icon">
                            <i class="bi <?php echo $is_completed ? 'bi-check-lg' : $step['icon']; ?>"></i>
                        </div>
                        <div class="step-label"><?php echo $step['label']; ?></div>
                        <div class="step-date">
                            <?php if (!empty($step['date'])): ?>
                                <?php echo date('M d, Y', strtotime($step['date'])); ?>
                            <?php elseif ($is_current): ?>
                                In Progress
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Info Cards Row - Above the Form -->
            <div class="info-cards-row">

                <div class="info-card">
                    <div class="card-header">
                        <h6><i class="bi bi-camera-fill"></i> Photo Reference</h6>
                        <span class="card-description">Visual documentation for this record.</span>
                    </div>

                    <?php if (!empty($document['photo_path'])):
                        $photoPath = htmlspecialchars($document['photo_path']);
                        // Use BASE_URL for reliability
                        $displayPhotoPath = BASE_URL . '/' . $photoPath;
                        // Escape for JavaScript string inside single quotes
                        $photoPathJs = str_replace(['\\', "'"], ['\\\\', "\\'"], $displayPhotoPath);
                        ?>
                        <div class="photo-overlay" onclick="showPhoto('<?php echo $photoPathJs; ?>')">
                            <img src="<?php echo $displayPhotoPath; ?>" alt="Document Photo">
                            <div class="overlay-text">
                                <i class="bi bi-zoom-in"></i> Click to enlarge
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="bi bi-image"></i>
                            <p>No photo attached</p>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="info-card">
                    <div class="card-header">
                        <h6><i class="bi bi-paperclip"></i> Attached File</h6>
                        <span class="card-description">Official document or related files.</span>
                    </div>

                    <?php if (!empty($document['attached_file_path'])): ?>
                        <div class="file-action-wrapper">
                            <a href="<?= BASE_URL ?>/<?= htmlspecialchars($document['attached_file_path']) ?>" class="file-link-btn"
                                target="_blank" rel="noopener noreferrer">
                                <div class="file-icon"><i class="bi bi-file-earmark-pdf-fill"></i></div>
                                <div class="file-text">
                                    <strong>View Document</strong>
                                    <span>Opens in a new tab</span>
                                </div>
                                <i class="bi bi-box-arrow-up-right external-icon"></i>
                            </a>

                        </div>
                        <p>This document serves to confirm that the PPE/ICS belongs to the Provincial Government of
                            Occidental
                            Mindoro.</p>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="bi bi-file-earmark-x"></i>
                            <p>No file attached</p>
                            <span>Supported formats will appear here.</span>
                        </div>
                    <?php endif; ?>

                    <?php if ($edit_mode): ?>
                        <div class="mt-2">
                            <input type="file" name="attached_file_path" class="form-control form-control-sm" accept=".pdf">
                            <input type="hidden" name="existing_attached_file_path"
                                value="<?php echo htmlspecialchars($document['attached_file_path'] ?? ''); ?>">
                        </div>
                    <?php endif; ?>
                </div>

                <div class="info-card">
                    <div class="card-header">
                        <h6><i class="bi bi-chat-left-text-fill"></i> Remarks</h6>
                        <span class="card-description">Additional notes and comments.</span>
                    </div>

                    <?php
                    // Use supply_remarks for supply view, otherwise use document remarks
                    $display_remarks = $is_supply_view ? $supply_remarks : $document['remarks'];
                    ?>

                    <?php if (!empty($display_remarks)): ?>
                        <div class="remarks-box">
                            <i class="bi bi-quote quote-icon"></i>
                            <p class="card-value"><?= nl2br(htmlspecialchars($display_remarks)) ?></p>
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

            <!-- ===================== CERTIFICATION FORM ===================== -->
            <div class="card p-4 border-0 shadow-none printable-area" style="background: white;">
                <header class="d-flex align-items-center justify-content-center mb-4 text-center">
                    <img src="<?= BASE_URL ?>/rs/Pepo_Logo.png" alt="PEPO Logo" class="me-2 me-md-3"
                        style="width: clamp(50px, 12vw, 80px); height: auto;">
                    <div>
                        <small class="d-block" style="font-size: clamp(0.6rem, 2vw, 0.8rem); line-height: 1.2;">Republic
                            of the
                            Philippines</small>
                        <small class="d-block"
                            style="font-size: clamp(0.6rem, 2vw, 0.8rem); line-height: 1.2;">PROVINCIAL
                            GOVERNMENT OF OCCIDENTAL MINDORO</small>
                        <small class="d-block fw-bold"
                            style="font-size: clamp(0.7rem, 2.5vw, 0.9rem); line-height: 1.2;">GENERAL
                            SERVICES
                            OFFICE</small>
                        <div class="d-flex justify-content-center gap-3 mt-2">
                            <small class="text-muted" style="font-size: clamp(0.5rem, 1.2vw, 0.7rem);">
                                <i class="bi bi-geo-alt"></i>
                                <?php echo htmlspecialchars(ucfirst($document['location'] ?? '')); ?>
                            </small>
                            <small class="text-muted" style="font-size: clamp(0.5rem, 1.2vw, 0.7rem);">
                                <i class="bi bi-calendar"></i>
                                <?php echo !empty($document['date_requested']) ? date('M d, Y', strtotime($document['date_requested'])) : ''; ?>
                            </small>
                        </div>
                    </div>
                    <img src="<?= BASE_URL ?>/rs/BAGONG-PILIPINAS-LOGO.png" alt="Occidental Mindoro Logo" class="ms-2 ms-md-3"
                        style="width: clamp(70px, 15vw, 110px); height: auto;">
                </header>

                <!-- ===================== CERTIFICATION ===================== -->
                <section class="mb-4">
                    <h5 class="text-center fw-bold text-success border-bottom border-success pb-2">
                        CERTIFICATION</h5>
                    <br>
                    <div class="text-start mb-3 mt-2">
                        <small class="fw-bold" style="font-size: 0.75rem;">Pre-Repair No.:</small>
                        <input type="text" name="pre_repair_no" class="form-control signature-input"
                            style="font-size: 0.9rem;"
                            value="<?php echo htmlspecialchars($document['pre_repair_no'] ?? ''); ?>" <?php echo $edit_mode ? '' : 'readonly'; ?>>
                    </div>

                    <table class="table table-bordered mb-2 align-middle table-sm"
                        style="font-size: 0.9rem; table-layout: fixed; width: 100%;">
                        <tbody>
                            <tr>
                                <td class="fw-bold" style="font-size: 0.75rem; width: 40%;">Property Number:</td>
                                <td>
                                    <input type="text" class="form-control"
                                        value="<?php echo htmlspecialchars($document['property_no'] ?? ''); ?>"
                                        readonly>
                                </td>
                            </tr>

                            <tr>
                                <td class="fw-bold" style="font-size: 0.75rem;">Description:</td>
                                <td>
                                    <textarea class="form-control" readonly rows="2"
                                        style="font-size: 0.85rem;"><?php echo htmlspecialchars($document['description'] ?? ''); ?></textarea>
                                </td>
                            </tr>

                            <tr>
                                <td class="fw-bold" style="font-size: 0.75rem;">Designation:</td>
                                <td><input type="text" class="form-control bg-light"
                                        value="<?php echo htmlspecialchars($document['designation'] ?? ''); ?>"
                                        style="font-size: 0.85rem;" readonly></td>
                            </tr>

                            <tr>
                                <td class="fw-bold" style="font-size: 0.75rem;">Acquisition Date:</td>
                                <td><input type="text" class="form-control bg-light"
                                        value="<?php echo htmlspecialchars($document['acquisition_date'] ?? ''); ?>"
                                        style="font-size: 0.85rem;" readonly></td>
                            </tr>

                            <tr>
                                <td class="fw-bold" style="font-size: 0.75rem;">Acquisition Cost:</td>
                                <td><input type="text" class="form-control bg-light"
                                        value="<?php echo htmlspecialchars($document['acquisition_cost'] ?? ''); ?>"
                                        style="font-size: 0.85rem;" readonly></td>
                            </tr>

                            <tr>
                                <td class="fw-bold" style="font-size: 0.75rem;">Last Repair:</td>
                                <td><input type="text" class="form-control"
                                        value="<?php echo htmlspecialchars($document['last_repair_date'] ?? ''); ?>"
                                        style="font-size: 0.85rem;" readonly></td>
                            </tr>

                            <tr>
                                <td class="fw-bold" style="font-size: 0.75rem;">Carrying Amount:</td>
                                <td><input type="text" name="carrying_amount" class="form-control"
                                        style="font-size: 0.85rem;"
                                        value="<?php echo htmlspecialchars($document['carrying_amount'] ?? ''); ?>"
                                        <?php echo $edit_mode ? '' : 'readonly'; ?>></td>
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
                            <input type="text"
                                class="form-control text-center border-0 border-bottom bg-transparent fw-bold"
                                style="font-size: 1rem; padding-bottom: 0;"
                                value="<?= htmlspecialchars($document['officer_name'] ?? '') ?>" readonly />

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
                        <div class="certification-text" style="font-size: 0.95rem; color: #6b6b6bff;">
                            I,
                            <input type="text" name="inspector_name"
                                class="form-control d-inline-block border-0 border-bottom bg-transparent p-0 text-center"
                                style="width: 250px; font-family: 'Times New Roman', Times, serif; font-weight: bold; font-size: 1rem;"
                                value="<?php echo htmlspecialchars($document['inspector_name'] ?? ''); ?>" <?php echo $edit_mode ? '' : 'readonly'; ?>>
                            certify under penalty of law that as
                            <input type="text" name="inspector_position"
                                class="form-control d-inline-block border-0 border-bottom bg-transparent p-0 text-center"
                                style="width: 180px; font-family: 'Times New Roman', Times, serif; font-weight: bold; font-size: 1rem;"
                                value="<?php echo htmlspecialchars($document['inspector_position'] ?? ''); ?>" <?php echo $edit_mode ? '' : 'readonly'; ?>>,
                            I have carefully examined the Above-Mentioned Property of the Provincial
                            Government of Occidental Mindoro.
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="fw-bold" style="font-size: 0.75rem;">Defect/Complaint:</label>
                        <textarea name="defect" class="form-control signature-input bg-light" rows="2"
                            style="font-size: 0.9rem;" <?php echo $edit_mode ? '' : 'readonly'; ?>><?php echo htmlspecialchars($document['defect'] ?? ''); ?></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="fw-bold" style="font-size: 0.75rem;">Findings:</label>
                        <textarea name="findings" class="form-control signature-input bg-light" rows="2"
                            style="font-size: 0.9rem;" <?php echo $edit_mode ? '' : 'readonly'; ?>><?php echo htmlspecialchars($document['findings'] ?? ''); ?></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="fw-bold" style="font-size: 0.75rem;">Recommendation:</label>
                        <?php $rec = trim($document['recommendation'] ?? ''); ?>
                        <div class="d-flex flex-column flex-sm-row gap-2 mt-1">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="recommendation"
                                    value="For In-House Repair" <?php echo $rec === 'For In-House Repair' ? 'checked' : ''; ?> <?php echo $edit_mode ? '' : 'disabled'; ?>>
                                <label class="form-check-label" style="font-size: 0.85rem;">For In-House Repair</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="recommendation"
                                    value="For Outside Repair" <?php echo $rec === 'For Outside Repair' ? 'checked' : ''; ?> <?php echo $edit_mode ? '' : 'disabled'; ?>>
                                <label class="form-check-label" style="font-size: 0.85rem;">For Outside Repair</label>
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
                                <?php for ($i = 1; $i <= 10; $i++):
                                    $materialField = "material_$i";
                                    $quantityField = "quantity_$i";
                                    ?>
                                    <tr>
                                        <td style="font-size: 0.8rem;"><?= $i ?></td>
                                        <td>
                                            <input type="text" name="material_<?= $i ?>" class="form-control text-center"
                                                value="<?php echo htmlspecialchars($document[$materialField] ?? ''); ?>"
                                                style="font-size: 0.8rem; padding: 4px;" <?php echo $edit_mode ? '' : 'readonly'; ?>>
                                        </td>
                                        <td>
                                            <input type="number" name="quantity_<?= $i ?>" class="form-control text-center"
                                                value="<?php echo htmlspecialchars($document[$quantityField] ?? ''); ?>"
                                                style="font-size: 0.8rem; padding: 4px;" <?php echo $edit_mode ? '' : 'readonly'; ?>>
                                        </td>
                                    </tr>
                                <?php endfor; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <!-- ===================== SIGNATORIES ===================== -->
                <div class="row text-center mt-4 gx-2">
                    <div class="col-6 mb-3">
                        <label class="fw-bold small">Pre-Inspected by:</label>
                        <input type="text" class="form-control text-center signature-input"
                            value="<?php echo htmlspecialchars($document['inspected_by'] ?? ''); ?>"
                            style="font-size: 0.9rem;" readonly>
                        <small class="text-muted" style="font-size: 0.8rem;">Inspector</small>
                    </div>
                    <div class="col-6 mb-3">
                        <label class="fw-bold small">Approved:</label>
                        <input type="text" class="form-control text-center signature-input"
                            value="<?php echo htmlspecialchars($document['approved_by_pepo'] ?? ''); ?>"
                            style="font-size: 0.9rem;" readonly>
                        <small class="text-muted" style="font-size: 0.8rem;">PGDH-PEPO</small>
                    </div>
                    <div class="col-6 mb-3">
                        <label class="fw-bold small">Witnessed:</label>
                        <input type="text" class="form-control text-center signature-input"
                            value="<?php echo htmlspecialchars($document['witnessed_by'] ?? ''); ?>"
                            style="font-size: 0.9rem;" readonly>
                        <small class="text-muted" style="font-size: 0.8rem;">PGDH-PACCO</small>
                    </div>
                    <div class="col-6 mb-3">
                        <label class="fw-bold small">Approved:</label>
                        <input type="text" class="form-control text-center signature-input"
                            value="<?php echo htmlspecialchars($document['approved_by_gso'] ?? ''); ?>"
                            style="font-size: 0.9rem;" readonly>
                        <small class="text-muted" style="font-size: 0.8rem;">PGDH-GSO</small>
                    </div>
                </div>
            </div>

            <?php if ($edit_mode): ?>
        </form>
    <?php endif; ?>

    <!-- Close Main Content Wrapper -->
</div>