<?php    
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$mysqli = new mysqli('localhost', 'root', '', 'user_management');
if ($mysqli->connect_errno) {
    die("Failed to connect to MySQL: " . $mysqli->connect_error);
}

// ✅ Show only Approved documents
$validStatuses = [
    "Approved" => ["label" => "Approved", "class" => "bg-warning text-dark"],
];

// ✅ Query only Approved documents
$sql = "
    SELECT 
        d.id, 
        c.category_name, 
        e.property_no, 
        d.pre_repair_no, 
        d.status
    FROM documents AS d
    LEFT JOIN equipment_category AS c ON d.category_id = c.id
    LEFT JOIN equipment AS e ON d.property_no = e.property_no
    WHERE d.status = 'Approved'
    ORDER BY d.id DESC
";
$result = $mysqli->query($sql);
?>


<div class="card p-3">
    <h3 class="text-center mb-4">DOCUMENTS</h3>

    <!-- ✅ Search & Filter -->
    <div class="d-flex justify-content-between mb-3">
        <div class="input-group" style="width: 300px;">
            <input type="text" class="form-control" placeholder="PRE-REPAIR NO" id="searchInput">
            <button class="btn btn-outline-secondary"><i class="fa fa-search"></i></button>
        </div>

        <div>
            <!-- Status Filter Dropdown -->
            <!-- <select class="form-select" style="width: 200px; display:inline-block;">
                <option value="">STATUS</option>
                <?php foreach ($validStatuses as $key => $status): ?>
                    <option value="<?= $key; ?>"><?= $status["label"]; ?></option>
                <?php endforeach; ?>
            </select> -->

            <!-- Add Document Button -->
            <!-- <button type="button" class="btn btn-success ms-2" data-bs-toggle="modal" data-bs-target="#addDocumentModal">
                <i class="fa fa-plus"></i> ADD DOCUMENT
            </button> -->

            <!-- View Request -->
            <a href="./view_request.php" class="btn btn-primary ms-2">
                <i class="fa fa-eye"></i> VIEW REQUEST
            </a>
        </div>
    </div>

    <!-- ✅ Documents Table -->
    <table class="table table-bordered table-striped align-middle text-center">
        <thead class="table-light">
            <tr>
                <th>Equipment</th>
                <th>Property No</th>
                <th>Pre-Repair No</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($result && $result->num_rows > 0): ?>
                <?php while ($row = $result->fetch_assoc()): 
                    $status = $row['status'];
                    $badge = $validStatuses[$status];
                ?>
                    <tr>
                        <td><?= htmlspecialchars($row['category_name'] ?? 'N/A'); ?></td>
                        <td><?= htmlspecialchars($row['property_no'] ?? 'N/A'); ?></td>
                        <td><?= htmlspecialchars($row['pre_repair_no'] ?? 'N/A'); ?></td>
                        <td>
                            <span class="badge <?= $badge['class']; ?>">
                                <?= $badge['label']; ?>
                            </span>
                        </td>
                        <td>
                            <!-- <a href="view_document.php?id=<?= urlencode($row['id']); ?>" class="btn btn-sm btn-outline-primary" title="View">
                                <i class="fa fa-eye"></i>
                            </a> -->
                            <a href="edit_document.php?id=<?= urlencode($row['id']); ?>" class="btn btn-sm btn-outline-success" title="update">
                                <i class="fa fa-edit"></i>
                            </a>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr>
                    <td colspan="5">No documents found.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>


<!-- Add Document Modal -->
<div class="modal fade" id="addDocumentModal" tabindex="-1" aria-labelledby="addDocumentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <form method="POST" action="add_document.php">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title" id="addDocumentModalLabel"><i class="bi bi-plus-circle"></i> Add New Document</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="card border-0 shadow-sm p-3">
                        <!-- Pre-Repair & Property Info -->
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Pre-Repair No.</label>
                                <input type="text" name="pre_repair_no" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Property No.</label>
                                <input type="text" name="property_no" class="form-control" required>
                            </div>
                        </div>

                        <!-- Category -->
                        <div class="mb-3">
                            <label class="form-label fw-bold">Category</label>
                            <select name="category_id" class="form-select" required>
                                <option value="">Select Category</option>
                                <?php
                                $categories = $mysqli->query("SELECT * FROM equipment_category ORDER BY category_name ASC");
                                while ($cat = $categories->fetch_assoc()):
                                ?>
                                    <option value="<?= $cat['id']; ?>"><?= htmlspecialchars($cat['category_name']); ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <!-- Inspector Info -->
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Inspector Name</label>
                                <input type="text" name="inspector_name" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Inspector Position</label>
                                <input type="text" name="inspector_position" class="form-control">
                            </div>
                        </div>

                        <!-- Defect, Findings, Recommendation -->
                        <div class="mb-3">
                            <label class="form-label fw-bold">Defect / Complaint</label>
                            <textarea name="defect" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Findings</label>
                            <textarea name="findings" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Recommendation</label><br>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="recommendation" value="For In-House Repair">
                                <label class="form-check-label">For In-House Repair</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="recommendation" value="For Outside Repair">
                                <label class="form-check-label">For Outside Repair</label>
                            </div>
                        </div>

                        <!-- Materials & Parts -->

                        <h6 class="fw-bold mt-3">Materials & Parts</h6>
                        <table class="table table-bordered text-center">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Material/Part</th>
                                    <th>Quantity</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php for ($i = 1; $i <= 10; $i++): ?>
                                    <tr>
                                        <td><?= $i ?></td>
                                        <td><input type="text" name="material_<?= $i ?>" class="form-control"></td>
                                        <td><input type="text" name="quantity_<?= $i ?>" class="form-control"></td>
                                    </tr>
                                <?php endfor; ?>
                            </tbody>
                        </table>

                        <!-- Signature Fields -->
                        <div class="row text-center mt-4">
                            <div class="col-md-6 mb-3">
                                <label class="fw-bold">Pre-Inspected by</label>
                                <input type="text" name="inspected_by" class="form-control text-center">
                                <small>Inspector</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="fw-bold">Approved by PEPO</label>
                                <input type="text" name="approved_by_pepo" class="form-control text-center">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="fw-bold">Witnessed by</label>
                                <input type="text" name="witnessed_by" class="form-control text-center">
                                <small>PGDH-PACCO</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="fw-bold">Approved by GSO</label>
                                <input type="text" name="approved_by_gso" class="form-control text-center">
                                <small>PGDH-GSO</small>
                            </div>
                        </div>

                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" name="add_document" class="btn btn-success"><i class="bi bi-plus-circle"></i> Add Document</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> Close</button>
                </div>
            </form>
        </div>
    </div>
</div>
