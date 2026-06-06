<?php
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE)
    session_start();

$id = $_GET['id'] ?? 0;

$stmt = $mysqli->prepare("
    SELECT d.*, e.id AS equipment_id, e.description, e.designation, e.acquisition_date, 
           e.acquisition_cost, e.last_repair_date
    FROM documents d
    LEFT JOIN equipment e ON d.property_no = e.property_no
    WHERE d.id = ?
");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$doc = $result->fetch_assoc();
$stmt->close();

// Fetch supply request linked to this document
$supplyRequest = null;

// First try by document_id
$supplyQuery = $mysqli->prepare("
    SELECT * FROM supply_requests 
    WHERE document_id = ? 
    ORDER BY created_at DESC
    LIMIT 1
");
$supplyQuery->bind_param("i", $id);
$supplyQuery->execute();
$supplyRequest = $supplyQuery->get_result()->fetch_assoc();
$supplyQuery->close();

// If not found, try by property_no
if (!$supplyRequest && !empty($doc['property_no'])) {
    $supplyQuery2 = $mysqli->prepare("
        SELECT * FROM supply_requests 
        WHERE property_no = ? 
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $supplyQuery2->bind_param("s", $doc['property_no']);
    $supplyQuery2->execute();
    $result2 = $supplyQuery2->get_result();
    $supplyRequest = $result2->fetch_assoc();
    $supplyQuery2->close();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Document</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .signature-input {
            border: none !important;
            border-bottom: 1px solid #000 !important;
            border-radius: 0 !important;
            background: transparent !important;
            box-shadow: none !important;
            padding-bottom: 0 !important;
            margin-bottom: 5px !important;
        }

        @media print {
            @page {
                margin: 5mm;
                size: auto;
            }

            body * {
                visibility: hidden;
            }

            body {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
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
                max-width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
                box-shadow: none !important;
                border: none !important;
                overflow: visible !important;
            }

            /* Compact layout to fit on one page */
            .printable-area h5 {
                font-size: 12pt !important;
                margin: 5px 0 !important;
                padding: 0 !important;
            }

            .printable-area p,
            .printable-area label,
            .printable-area small,
            .printable-area td,
            .printable-area th {
                font-size: 9pt !important;
            }

            .printable-area .form-control {
                font-size: 9pt !important;
                padding: 1px 4px !important;
                height: auto !important;
                min-height: 0 !important;
            }

            .printable-area img {
                height: 40px !important;
                width: auto !important;
            }

            .printable-area .mb-3,
            .printable-area .mb-4 {
                margin-bottom: 4px !important;
            }

            .printable-area .mt-5 {
                margin-top: 10px !important;
            }

            .printable-area header {
                margin-bottom: 5px !important;
            }

            .printable-area table th,
            .printable-area table td {
                padding: 2px !important;
            }

            .d-print-none {
                display: none !important;
            }
        }
    </style>
</head>

<body class="bg-light">
    <div class="container mt-2 mb-5">
        <div class="card p-4 border-0 shadow-none printable-area">
            <!-- Supply Request Status Progress -->
            <?php if ($supplyRequest): ?>
                <?php
                $status = $supplyRequest['status'] ?? 'pending';
                $steps = [
                    ['key' => 'pending', 'label' => 'Pending', 'date' => date('M d, Y', strtotime($supplyRequest['created_at']))],
                    ['key' => 'approved', 'label' => 'Approved', 'date' => !empty($supplyRequest['approved_at']) ? date('M d, Y', strtotime($supplyRequest['approved_at'])) : null],
                    ['key' => 'complied', 'label' => 'Complied', 'date' => !empty($supplyRequest['complied_at']) ? date('M d, Y', strtotime($supplyRequest['complied_at'])) : null],
                    ['key' => 'received', 'label' => 'Received', 'date' => !empty($supplyRequest['received_at']) ? date('M d, Y', strtotime($supplyRequest['received_at'])) : null]
                ];
                
                // Find current step index
                $statusOrder = ['pending', 'approved', 'complied', 'received'];
                $currentIndex = array_search($status, $statusOrder);
                if ($currentIndex === false)
                    $currentIndex = 0;
                ?>
                <div class="card mb-4 border-0 shadow-sm">
                    <div class="card-header bg-success text-white py-2">
                        <h6 class="mb-0 fw-bold"><i class="bi bi-box-seam me-2"></i>Supply Request Status</h6>
                    </div>
                    <div class="card-body p-3">
                        <div class="d-flex justify-content-between align-items-start position-relative">
                            <!-- Progress Line -->
                            <div class="position-absolute top-50 start-0 translate-middle-y"
                                style="width: 100%; height: 3px; z-index: 0;">
                                <div class="h-100 bg-success" style="width: <?= ($currentIndex / 3) * 100 ?>%;"></div>
                            </div>

                            <?php foreach ($steps as $index => $step): ?>
                                <?php
                                $isCompleted = $index <= $currentIndex;
                                $isCurrent = $index === $currentIndex;
                                $stepStatus = $isCompleted ? 'success' : 'secondary';
                                ?>
                                <div class="d-flex flex-column align-items-center position-relative" style="z-index: 1;">
                                    <div class="rounded-circle d-flex align-items-center justify-content-center <?= $isCompleted ? 'bg-success' : 'bg-secondary' ?>"
                                        style="width: 40px; height: 40px; color: white; <?= $isCurrent ? 'box-shadow: 0 0 0 4px rgba(25, 135, 84, 0.3);' : '' ?>">
                                        <?php if ($isCompleted && !$isCurrent): ?>
                                            <i class="bi bi-check-lg"></i>
                                        <?php else: ?>
                                            <i class="bi <?=
                                                $step['key'] === 'pending' ? 'bi-clock' :
                                                ($step['key'] === 'approved' ? 'bi-check-circle' :
                                                    ($step['key'] === 'complied' ? 'bi-box' : 'bi-check2-all'))
                                                ?>"></i>
                                        <?php endif; ?>
                                    </div>
                                    <span class="small fw-bold mt-1 <?= $isCompleted ? 'text-success' : 'text-muted' ?>">
                                        <?= $step['label'] ?>
                                    </span>
                                    <span class="text-muted" style="font-size: 0.65rem;">
                                        <?= $step['date'] ?? '-' ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <header class="d-flex align-items-center justify-content-center mb-4 text-center">
                <img src="../rs/Pepo_Logo.png" alt="PEPO Logo" class="me-2 me-md-3"
                    style="width: clamp(50px, 12vw, 80px); height: auto;">
                <div>
                    <small class="d-block" style="font-size: clamp(0.6rem, 2vw, 0.8rem); line-height: 1.2;">Republic of
                        the Philippines</small>
                    <small class="d-block" style="font-size: clamp(0.6rem, 2vw, 0.8rem); line-height: 1.2;">PROVINCIAL
                        GOVERNMENT OF OCCIDENTAL MINDORO</small>
                    <small class="d-block fw-bold"
                        style="font-size: clamp(0.7rem, 2.5vw, 0.9rem); line-height: 1.2;">GENERAL SERVICES
                        OFFICE</small>
                </div>
                <img src="../rs/BAGONG-PILIPINAS-LOGO.png" alt="Occidental Mindoro Logo" class="ms-2 ms-md-3"
                    style="width: clamp(70px, 15vw, 110px); height: auto;">
            </header>

            <section class="mb-4">
                <h5 class="text-center fw-bold text-success border-bottom border-success pb-2" style="font-size: 1rem;">
                    CERTIFICATION</h5>

                <div class="text-start mb-3 mt-2">
                    <small class="fw-bold" style="font-size: 0.75rem;">Pre-Repair No.:</small>
                    <input type="text" class="form-control border-0 border-bottom bg-transparent p-0"
                        style="font-size: 0.9rem;" value="<?= htmlspecialchars($doc['pre_repair_no']) ?>" readonly>
                </div>

                <table class="table table-bordered mb-2 align-middle table-sm"
                    style="font-size: 0.9rem; table-layout: fixed; width: 100%;">
                    <tbody>
                        <tr>
                            <td class="fw-bold" style="font-size: 0.75rem; width: 40%;">Property Number:</td>
                            <td><input type="text" class="form-control bg-light border-0" style="font-size: 0.85rem;"
                                    value="<?= htmlspecialchars($doc['property_no']) ?>" readonly></td>
                        </tr>
                        <tr>
                            <td class="fw-bold" style="font-size: 0.75rem;">Description:</td>
                            <td><textarea class="form-control bg-light border-0" rows="2" style="font-size: 0.85rem;"
                                    readonly><?= htmlspecialchars($doc['description']) ?></textarea></td>
                        </tr>
                        <tr>
                            <td class="fw-bold" style="font-size: 0.75rem;">Designation:</td>
                            <td><input type="text" class="form-control bg-light border-0" style="font-size: 0.85rem;"
                                    value="<?= htmlspecialchars($doc['designation']) ?>" readonly></td>
                        </tr>
                        <tr>
                            <td class="fw-bold" style="font-size: 0.75rem;">Acquisition Date:</td>
                            <td><input type="text" class="form-control bg-light border-0" style="font-size: 0.85rem;"
                                    value="<?= htmlspecialchars($doc['acquisition_date']) ?>" readonly></td>
                        </tr>
                        <tr>
                            <td class="fw-bold" style="font-size: 0.75rem;">Acquisition Cost:</td>
                            <td><input type="text" class="form-control bg-light border-0" style="font-size: 0.85rem;"
                                    value="<?= htmlspecialchars($doc['acquisition_cost']) ?>" readonly></td>
                        </tr>
                        <tr>
                            <td class="fw-bold" style="font-size: 0.75rem;">Last Repair:</td>
                            <td><input type="text" class="form-control bg-light border-0" style="font-size: 0.85rem;"
                                    value="<?= htmlspecialchars($doc['last_repair_date']) ?>" readonly></td>
                        </tr>
                        <tr>
                            <td class="fw-bold" style="font-size: 0.75rem;">Carrying Amount:</td>
                            <td><input type="text" class="form-control bg-light border-0" style="font-size: 0.85rem;"
                                    value="<?= htmlspecialchars($doc['carrying_amount'] ?? '') ?>" readonly></td>
                        </tr>
                    </tbody>
                </table>
                <!-- 
            <p class="mb-1" style="font-size: 0.85rem;">(Attach a copy of latest job order)</p>
            <p style="font-size: 0.85rem;">This document serves to confirm that the PPE/ICS belongs to the Provincial Government of Occidental Mindoro.</p>

            <div class="d-flex justify-content-center mt-3">
                <div class="text-center" style="width: 80%; max-width: 300px; display: flex; flex-direction: column; align-items: center;">
                    <div style="min-height: 80px; display: flex; align-items: flex-end; justify-content: center; margin-bottom: -15px;">
                        <?php if (!empty($doc['signature'])): ?>
                            <img src="../<?= htmlspecialchars($doc['signature']) ?>" 
                                 alt="Signature" 
                                 style="height: 100px; width: auto; mix-blend-mode: multiply; filter: contrast(1.2);">
                        <?php endif; ?>
                    </div>
                    <input type="text" class="form-control text-center border-0 border-bottom bg-transparent fw-bold"
                           style="font-size: 1rem; padding-bottom: 0;"
                           value="<?= htmlspecialchars($doc['officer_name'] ?? '') ?>" readonly />
                    <small class="text-muted d-block mt-1" style="font-size: 0.75rem;">Property Custodian Officer</small>
                </div> -->

            </section>

            <section class="mb-4">
                <h5 class="text-center fw-bold bg-success text-white py-2" style="font-size: 1rem;">PRE-REPAIR
                    INSPECTION
                </h5>

                <!-- <div class="border p-3 mb-4 rounded bg-light" style="font-family: 'Times New Roman', Times, serif; line-height: 1.6;">
                <div class="certification-text" style="font-size: 0.95rem; color: #6b6b6bff;">
                    I, 
                    <span style="font-weight: bold; border-bottom: 1px solid #000; padding: 0 15px;"><?= htmlspecialchars($doc['inspector_name'] ?? '________________') ?></span>
                    certify under penalty of law that as 
                    <span style="font-weight: bold; border-bottom: 1px solid #000; padding: 0 15px;"><?= htmlspecialchars($doc['inspector_position'] ?? '________________') ?></span>, 
                    I have carefully examined the Above-Mentioned Property of the Provincial Government of Occidental Mindoro.
                </div>
            </div> -->

                <div class="mb-3">
                    <label class="fw-bold" style="font-size: 0.75rem;">Defect/Complaint:</label>
                    <textarea class="form-control bg-light border-0" rows="2" style="font-size: 0.9rem;"
                        readonly><?= htmlspecialchars($doc['defect'] ?? '') ?></textarea>
                </div>

                <div class="mb-3">
                    <label class="fw-bold" style="font-size: 0.75rem;">Findings:</label>
                    <textarea class="form-control bg-light border-0" rows="2" style="font-size: 0.9rem;"
                        readonly><?= htmlspecialchars($doc['findings'] ?? '') ?></textarea>
                </div>

                <div class="mb-3">
                    <label class="fw-bold" style="font-size: 0.75rem;">Recommendation:</label>
                    <div class="d-flex flex-column flex-sm-row gap-3 mt-1" style="font-size: 0.85rem;">
                        <div>
                            <input type="radio" disabled <?= ($doc['recommendation'] ?? '') === 'For In-House Repair' ? 'checked' : '' ?>> For In-House Repair
                        </div>
                        <div>
                            <input type="radio" disabled <?= ($doc['recommendation'] ?? '') === 'For Outside Repair' ? 'checked' : '' ?>> For Outside Repair
                        </div>
                    </div>
                </div>
            </section>

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
                        <tbody style="font-size: 0.8rem;">
                            <?php for ($i = 1; $i <= 10; $i++): ?>
                                <tr>
                                    <td><?= $i ?></td>
                                    <td><?= htmlspecialchars($doc['material_' . $i] ?? '') ?></td>
                                    <td><?= htmlspecialchars($doc['quantity_' . $i] ?? '') ?></td>
                                </tr>
                            <?php endfor; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- <div class="row text-center mt-4 gx-2">
            <div class="col-6 mb-3">
                <label class="fw-bold d-block" style="font-size: 0.75rem;">Pre-Inspected by:</label>
                <div class="border-bottom mx-auto" style="width: 80%; font-size: 0.9rem; font-weight: bold;"><?= htmlspecialchars($doc['inspected_by'] ?? '') ?></div>
                <small class="text-muted" style="font-size: 0.75rem;">Inspector</small>
            </div>
            <div class="col-6 mb-3">
                <label class="fw-bold d-block" style="font-size: 0.75rem;">Approved:</label>
                <div class="border-bottom mx-auto" style="width: 80%; font-size: 0.9rem; font-weight: bold;"><?= htmlspecialchars($doc['approved_by_pepo'] ?? '') ?></div>
                <small class="text-muted" style="font-size: 0.75rem;">PGDH-PEPO</small>
            </div>
            <div class="col-6 mb-3">
                <label class="fw-bold d-block" style="font-size: 0.75rem;">Witnessed:</label>
                <div class="border-bottom mx-auto" style="width: 80%; font-size: 0.9rem; font-weight: bold;"><?= htmlspecialchars($doc['witnessed_by'] ?? '') ?></div>
                <small class="text-muted" style="font-size: 0.75rem;">PGDH-PACCO</small>
            </div>
            <div class="col-6 mb-3">
                <label class="fw-bold d-block" style="font-size: 0.75rem;">Approved:</label>
                <div class="border-bottom mx-auto" style="width: 80%; font-size: 0.9rem; font-weight: bold;"><?= htmlspecialchars($doc['approved_by_gso'] ?? '') ?></div>
                <small class="text-muted" style="font-size: 0.75rem;">PGDH-GSO</small>
            </div>
        </div> -->
        </div>

        <div class="text-center mt-4 d-print-none">
            <?php if (!empty($doc['equipment_id'])): ?>
                <a href="generate_qr.php?view=scan&id=<?= htmlspecialchars($doc['equipment_id']) ?>"
                    class="btn btn-secondary px-4 me-2" style="font-size: 0.9rem;">
                    <i class="bi bi-arrow-left"></i> Back to QR
                </a>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>