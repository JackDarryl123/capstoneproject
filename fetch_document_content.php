<?php
// fetch_document_content.php
require_once __DIR__ . '/config.php';
require_once 'includes/session_helper.php';

$id = $_GET['id'] ?? 0;
$source = $_GET['source'] ?? 'qr';

// Fetch logic (same as your original)
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

// Fetch Signature logic (Same as original)
$curr_user_id = $_SESSION['user_id'] ?? 0;
$my_signature = ''; 
// ... (Your existing signature fetching logic goes here) ...
?>

<style>
    /* Modal Specific Adjustments */
    .modal-document-wrapper {
        background: #f5f7fa;
        padding: 20px;
        border-radius: 10px;
    }
    .printable-area {
        background: white;
        padding: 30px;
        border: 1px solid #dee2e6;
        border-radius: 8px;
        box-shadow: 0 4px 6px rgba(0,0,0,0.05);
    }
    .signature-input {
        border: none !important;
        border-bottom: 1px solid #000 !important;
        background: transparent !important;
        border-radius: 0 !important;
    }
    /* Hide specific elements when printing from modal */
    @media print {
        body * { visibility: hidden; }
        #modalPrintArea, #modalPrintArea * { visibility: visible; }
        #modalPrintArea { position: absolute; left: 0; top: 0; width: 100%; }
        .no-print { display: none !important; }
    }
</style>

<div class="container-fluid modal-document-wrapper">
    <div class="row">
        <div class="col-lg-8">
            <div id="modalPrintArea">
                <form method="POST" action="process_document.php" id="modalDocForm">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="source" value="<?= $source ?>">
                    
                    <div class='printable-area'>
                        <header class='d-flex align-items-center justify-content-center mb-4 text-center'>
                            <img src='rs/Pepo_Logo.png' style='width:80px; height:auto; margin-right:20px;'>
                            <div>
                                <small class='d-block'>Republic of the Philippines</small>
                                <small class='d-block fw-bold'>GENERAL SERVICES OFFICE</small>
                            </div>
                            <img src='rs/BAGONG-PILIPINAS-LOGO.png' style='width:100px; height:auto; margin-left:15px;'>
                        </header>

                        <div class="mb-3">
                             <label class="fw-bold">Pre-Repair No:</label>
                             <input type="text" class="form-control signature-input" value="<?= htmlspecialchars($doc['pre_repair_no']) ?>" readonly>
                        </div>

                        </div>
                </form>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="sticky-top" style="top: 20px; z-index: 1;">
                <h5 class="mb-3 text-success"><i class="bi bi-gear"></i> Actions</h5>
                
                <div class="d-grid gap-2">
                    <button type="submit" form="modalDocForm" class="btn btn-primary">
                        <i class="bi bi-save"></i> Save Changes
                    </button>

                    <button type="button" class="btn btn-success" onclick="window.print()">
                        <i class="bi bi-printer"></i> Print
                    </button>

                    <button type="submit" name="complete" value="1" form="modalDocForm" class="btn btn-outline-success">
                        <i class="bi bi-check-circle"></i> Mark Complete
                    </button>

                    <button type="submit" name="add_to_maintenance" value="1" form="modalDocForm" class="btn btn-outline-warning">
                        <i class="bi bi-tools"></i> Add to Maintenance
                    </button>
                    
                     <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle"></i> Close
                    </button>
                </div>

                <div class="card mt-3 bg-light border-0">
                    <div class="card-body">
                        <small><strong>Status:</strong> <?= $doc['status'] ?></small><br>
                        <small><strong>Carrying Amount:</strong> <?= $doc['carrying_amount'] ?></small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>