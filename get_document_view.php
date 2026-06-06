<?php
// get_document_view.php
// ---------------------------------------------------------
// This file outputs HTML with internal CSS to mimic Bootstrap
// layout without conflicting with the main Tailwind page.
// ---------------------------------------------------------

require_once __DIR__ . '/config.php';

if (!isset($_GET['id'])) {
    echo "No ID provided";
    exit;
}

$id = intval($_GET['id']);
$query = $mysqli->prepare("SELECT * FROM documents WHERE id = ?");
$query->bind_param("i", $id);
$query->execute();
$result = $query->get_result();
$row = $result->fetch_assoc();

if (!$row) {
    echo "Document not found";
    exit;
}
?>

<style>
    .bs-container {
        font-family: 'Inter', sans-serif;
        color: #333;
        padding: 20px;
    }
    .bs-header {
        text-align: center;
        margin-bottom: 20px;
        border-bottom: 2px solid #333;
        padding-bottom: 10px;
    }
    .bs-row {
        display: flex;
        flex-wrap: wrap;
        margin-right: -10px;
        margin-left: -10px;
    }
    .bs-col-6 {
        flex: 0 0 50%;
        max-width: 50%;
        padding: 0 10px;
        box-sizing: border-box;
    }
    .bs-col-12 {
        flex: 0 0 100%;
        max-width: 100%;
        padding: 0 10px;
        box-sizing: border-box;
    }
    .bs-table {
        width: 100%;
        margin-bottom: 1rem;
        color: #212529;
        border-collapse: collapse;
    }
    .bs-table th, .bs-table td {
        padding: 0.75rem;
        vertical-align: top;
        border-top: 1px solid #dee2e6;
    }
    .bs-table-bordered th, .bs-table-bordered td {
        border: 1px solid #dee2e6;
    }
    .bs-label {
        font-weight: bold;
        color: #555;
        font-size: 0.85rem;
        text-transform: uppercase;
        display: block;
        margin-bottom: 4px;
    }
    .bs-value {
        font-size: 1rem;
        font-weight: 500;
        color: #000;
        background: #f8f9fa;
        padding: 8px;
        border: 1px solid #e9ecef;
        border-radius: 4px;
        display: block;
        width: 100%;
        margin-bottom: 15px;
    }
    .bs-signature-box {
        text-align: center;
        margin-top: 40px;
    }
    .bs-signature-line {
        border-top: 1px solid #000;
        width: 80%;
        margin: 0 auto;
        padding-top: 5px;
        font-weight: bold;
        text-transform: uppercase;
    }
</style>

<div class="bs-container">
    <div class="bs-header">
        <h3 style="margin:0; font-size:1.2rem; font-weight:bold;">PROVINCIAL GOVERNMENT OF OCCIDENTAL MINDORO</h3>
        <p style="margin:0; font-size:0.9rem;">General Services Office</p>
        <h2 style="margin-top:10px; font-weight:bold; text-decoration: underline;">CERTIFICATION</h2>
    </div>

    <div class="bs-row">
        <div class="bs-col-6">
            <label class="bs-label">Pre-Repair No.</label>
            <div class="bs-value"><?= htmlspecialchars($row['pre_repair_no'] ?? '') ?></div>
        </div>

        <div class="bs-col-6">
            <label class="bs-label">Date Requested</label>
            <div class="bs-value">
                <?= $row['date_requested'] ? date('F d, Y', strtotime($row['date_requested'])) : 'N/A' ?>
            </div>
        </div>
    </div>

    <div class="bs-row">
        <div class="bs-col-6">
            <label class="bs-label">Property Number</label>
            <div class="bs-value"><?= htmlspecialchars($row['property_no'] ?? '') ?></div>
        </div>
        <div class="bs-col-6">
            <label class="bs-label">Acquisition Date</label>
            <div class="bs-value"><?= htmlspecialchars($row['acquisition_date'] ?? 'N/A') ?></div>
        </div>
    </div>

    <div class="bs-row">
        <div class="bs-col-12">
            <label class="bs-label">Description / Model</label>
            <div class="bs-value"><?= htmlspecialchars($row['description'] ?? 'N/A') ?></div>
        </div>
    </div>

    <div class="bs-row">
        <div class="bs-col-6">
            <label class="bs-label">Designation</label>
            <div class="bs-value"><?= htmlspecialchars($row['designation'] ?? 'Provincial Equipment Pool') ?></div>
        </div>
        <div class="bs-col-6">
            <label class="bs-label">Last Repair Date</label>
            <div class="bs-value"><?= htmlspecialchars($row['last_repair_date'] ?? 'N/A') ?></div>
        </div>
    </div>

    <div class="bs-row" style="margin-top: 20px;">
        <div class="bs-col-12">
            <label class="bs-label" style="margin-bottom: 10px;">MATERIALS & PARTS</label>
            <table class="bs-table bs-table-bordered">
                <thead>
                    <tr style="background-color: #f1f1f1;">
                        <th style="width: 10%;">#</th>
                        <th>Material Description</th>
                        <th style="width: 20%;">Quantity</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    // Example loop - replace with actual materials logic if needed
                    for($i=1; $i<=5; $i++): 
                        if (!empty($row["material_$i"])):
                    ?>
                    <tr>
                        <td style="text-align: center;"><?= $i ?></td>
                        <td><?= htmlspecialchars($row["material_$i"]) ?></td>
                        <td style="text-align: center;"><?= htmlspecialchars($row["quantity_$i"] ?? '-') ?></td>
                    </tr>
                    <?php 
                        endif;
                    endfor; 
                    ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="bs-row" style="margin-top: 30px;">
        <div class="bs-col-6 bs-signature-box">
            <div style="height: 50px;"></div> <div class="bs-signature-line"><?= htmlspecialchars($row['certified_by'] ?? 'Jack Darryl C. Gernale') ?></div>
            <div style="font-size: 0.8rem; color: #666;">Property Custodian Officer</div>
        </div>
        <div class="bs-col-6 bs-signature-box">
            <div style="height: 50px;"></div> <div class="bs-signature-line">Approved By</div>
            <div style="font-size: 0.8rem; color: #666;">Head of Office</div>
        </div>
    </div>
</div>