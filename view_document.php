<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

<?php
if (session_status() === PHP_SESSION_NONE) session_start();

$mysqli = new mysqli('localhost', 'root', '', 'user_management');
if ($mysqli->connect_errno) {
    die("Database connection failed: " . $mysqli->connect_error);
}

$id = $_GET['id'] ?? 0;

$stmt = $mysqli->prepare("
    SELECT d.*, e.description, e.designation, e.acquisition_date, 
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
?>

<div class="container mt-4 mb-5">
    <!-- ✅ Back Button -->
    <div class="mb-3">
        <a href="admin_dashboard.php?view=documents" class="btn btn-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Back
        </a>
    </div>

    <div class="card p-4 shadow-sm border-0">
        <header class="d-flex align-items-center justify-content-center mb-4 text-center">
            <img src="../rs/pepologo.png" alt="PEPO Logo" style="width:100px; height:auto; margin-right: 15px;">
            <div>
                <small class="d-block">Republic of the Philippines</small>
                <small class="d-block">PROVINCIAL GOVERNMENT OF OCCIDENTAL MINDORO</small>
                <small class="d-block fw-bold">GENERAL SERVICES OFFICE</small>
            </div>
            <img src="../rs/occmin.png" alt="Occidental Mindoro Logo" style="width:100px; height:auto; margin-left: 15px;">
        </header>

        <section class="mb-4">
            <h5 class="text-center fw-bold text-success border-bottom border-success pb-2">CERTIFICATION</h5>

            <div class="text-start mb-3 mt-2">
                <small class="fw-bold">Pre-Repair No.:</small>
                <input type="text" class="form-control form-control-lg" value="<?= htmlspecialchars($doc['pre_repair_no']) ?>" readonly>
            </div>

            <table class="table table-bordered mb-2 align-middle">
                <tbody>
                    <tr>
                        <td class="fw-bold w-25">Property Number:</td>
                        <td><input type="text" class="form-control form-control-lg" value="<?= htmlspecialchars($doc['property_no']) ?>" readonly></td>
                    </tr>
                    <tr>
                        <td class="fw-bold">Description of Property:</td>
                        <td><textarea class="form-control form-control-lg" rows="3" readonly><?= htmlspecialchars($doc['description']) ?></textarea></td>
                    </tr>
                    <tr>
                        <td class="fw-bold">Designation of Property:</td>
                        <td><input type="text" class="form-control form-control-lg" value="<?= htmlspecialchars($doc['designation']) ?>" readonly></td>
                    </tr>
                    <tr>
                        <td class="fw-bold">Acquisition Date:</td>
                        <td><input type="text" class="form-control form-control-lg" value="<?= htmlspecialchars($doc['acquisition_date']) ?>" readonly></td>
                    </tr>
                    <tr>
                        <td class="fw-bold">Acquisition Cost:</td>
                        <td><input type="text" class="form-control form-control-lg" value="<?= htmlspecialchars($doc['acquisition_cost']) ?>" readonly></td>
                    </tr>
                    <tr>
                        <td class="fw-bold">Date of Last Repair:</td>
                        <td><input type="text" class="form-control form-control-lg" value="<?= htmlspecialchars($doc['last_repair_date']) ?>" readonly></td>
                    </tr>
                </tbody>
            </table>

            <div class="text-start mb-3 mt-2">
                <small class="fw-bold">Carrying Amount:</small>
                <input type="text" class="form-control form-control-lg" value="<?= htmlspecialchars($doc['carrying_amount'] ?? '') ?>" readonly>
            </div>

            <p>(Attach a copy of latest job order)</p>
            <p>This document serves to confirm that the PPE/ICS belongs to the Provincial Government of Occidental Mindoro.</p>

            <div class="d-flex justify-content-center mt-3">
                <div class="text-center" style="width: 60%;">
                    <input type="text" class="form-control form-control-lg text-center" value="<?= htmlspecialchars($doc['officer_name'] ?? '') ?>" readonly>

                    <small>Property Custodian Officer</small>
                </div>
            </div>
        </section>

        <section class="mb-4">
            <h5 class="text-center fw-bold bg-success text-white py-2">PRE-REPAIR INSPECTION</h5>
            <p class="text-muted fst-italic text-center">This part of the document is for the admin inspection team only.</p>

            <div class="mb-3">
                <label class="fw-bold">Defect/Complaint:</label>
                <textarea class="form-control form-control-lg" rows="3" readonly><?= htmlspecialchars($doc['defect'] ?? '') ?></textarea>
            </div>

            <div class="mb-3">
                <label class="fw-bold">Findings:</label>
                <textarea class="form-control form-control-lg" rows="3" readonly><?= htmlspecialchars($doc['findings'] ?? '') ?></textarea>
            </div>

            <div class="mb-3">
                <label class="fw-bold">Recommendation:</label><br>
                <input type="radio" <?= ($doc['recommendation'] ?? '') === 'For In-House Repair' ? 'checked' : '' ?> disabled> For In-House Repair
                <br>
                <input type="radio" <?= ($doc['recommendation'] ?? '') === 'For Outside Repair' ? 'checked' : '' ?> disabled> For Outside Repair
            </div>
        </section>

        <section class="mb-4">
            <h5 class="text-center fw-bold">MATERIALS & PARTS</h5>
            <table class="table table-bordered text-center align-middle">
                <thead class="bg-light">
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
                            <td><input type="text" class="form-control form-control-lg text-center" value="<?= htmlspecialchars($doc['material_'.$i] ?? '') ?>" readonly></td>
                            <td><input type="text" class="form-control form-control-lg text-center" value="<?= htmlspecialchars($doc['quantity_'.$i] ?? '') ?>" readonly></td>
                        </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </section>

        <div class="row text-center mt-5">
            <div class="col-md-6 mb-3">
                <label class="fw-bold">Pre-Inspected by:</label>
                <input type="text" class="form-control form-control-lg text-center" value="<?= htmlspecialchars($doc['inspected_by'] ?? '') ?>" readonly>
                <small>Inspector</small>
            </div>
            <div class="col-md-6 mb-3">
                <label class="fw-bold">Approved:</label>
                <input type="text" class="form-control form-control-lg text-center" value="<?= htmlspecialchars($doc['approved_by_pepo'] ?? '') ?>" readonly>
                <small>PGDH-PEPO</small>
            </div>
            <div class="col-md-6 mb-3">
                <label class="fw-bold">Witnessed:</label>
                <input type="text" class="form-control form-control-lg text-center" value="<?= htmlspecialchars($doc['witnessed_by'] ?? '') ?>" readonly>
                <small>PGDH-PACCO</small>
            </div>
            <div class="col-md-6 mb-3">
                <label class="fw-bold">Approved:</label>
                <input type="text" class="form-control form-control-lg text-center" value="<?= htmlspecialchars($doc['approved_by_gso'] ?? '') ?>" readonly>
                <small>PGDH-GSO</small>
            </div>
        </div>

        <!-- ✅ Print Button -->
        <div class="text-center mt-5">
            <button class="btn btn-success btn-lg px-5" onclick="window.print()">
                <i class="bi bi-printer"></i> Print Document
            </button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
