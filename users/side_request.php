<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

<?php
// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database connection
$mysqli = new mysqli('localhost', 'root', '', 'user_management');
if ($mysqli->connect_errno) {
    die("Database connection failed: " . $mysqli->connect_error);
}

// ✅ Handle form submission
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Sanitize inputs
    $pre_repair_no   = trim($_POST['prerepair_no'] ?? '');
    $property_no     = trim($_POST['property_number'] ?? '');
    $carrying_amount = trim($_POST['carrying_amount'] ?? '');
    $officer_name    = trim($_POST['officer_name'] ?? '');

    // Basic validation
    if (empty($pre_repair_no) || empty($property_no)) {
        echo "<script>alert('⚠️ Please fill in all required fields (Pre-Repair No and Property Number).'); window.history.back();</script>";
        exit;
    }

    // ✅ Check if Pre-Repair No already exists
    $check_stmt = $mysqli->prepare("SELECT COUNT(*) FROM documents WHERE pre_repair_no = ?");
    $check_stmt->bind_param("s", $pre_repair_no);
    $check_stmt->execute();
    $check_stmt->bind_result($count);
    $check_stmt->fetch();
    $check_stmt->close();

    if ($count > 0) {
        echo "<script>alert('⚠️ Pre-Repair No. already exists! Please use a unique number.'); window.history.back();</script>";
        exit;
    }

    // ✅ Get category_id automatically from equipment table
    $cat_stmt = $mysqli->prepare("SELECT category_id FROM equipment WHERE property_no = ?");
    $cat_stmt->bind_param("s", $property_no);
    $cat_stmt->execute();
    $cat_stmt->bind_result($category_id);
    $cat_stmt->fetch();
    $cat_stmt->close();

    // Default status
    $status = "Pending";
    $date_requested = date("Y-m-d H:i:s");

    // ✅ Insert full data (including carrying_amount & officer_name)
    $insert_stmt = $mysqli->prepare(
        "INSERT INTO documents 
        (category_id, property_no, pre_repair_no, carrying_amount, officer_name, status, date_requested)
        VALUES (?, ?, ?, ?, ?, ?, ?)"
    );

    $insert_stmt->bind_param(
        "sssssss",
        $category_id,
        $property_no,
        $pre_repair_no,
        $carrying_amount,
        $officer_name,
        $status,
        $date_requested
    );

    if ($insert_stmt->execute()) {
        echo "<script>alert('✅ Request submitted successfully!'); window.location.href='user_dashboard.php?view=request';</script>";
    } else {
        echo "<script>alert('❌ Error submitting request: " . addslashes($insert_stmt->error) . "'); window.history.back();</script>";
    }

    $insert_stmt->close();
}
?>


<div class="container-fluid my-3">
    <div class="row">
        <div class="col-12">
            <!-- ✅ Compose Message Button -->
            <div class="mb-3">
                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#composeModal">
                    Compose Request
                </button>
            </div>

            <!-- ✅ Table Card -->
            <div class="card shadow-sm">
                <div class="card-header bg-dark text-white fw-bold">REQUEST</div>
                <div class="card-body p-0">

                <!-- ✅ Toolbar with Search, Date & Status Filter -->
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 px-3 py-2 border-bottom">
    <div>
        <button type="button" class="btn btn-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#archivedModal">
            <i class="bi bi-archive"></i> Archived
        </button>
    </div>

    <div class="d-flex align-items-center gap-2 flex-wrap">
        <!-- Date Filter -->
        <input type="date" class="form-control form-control-sm" style="width: 180px;">

        <!-- Pre-Repair No. Search -->
        <div class="input-group input-group-sm" style="width: 220px;">
            <input type="text" class="form-control" placeholder="Pre-repair no.">
            <button class="btn btn-primary"><i class="bi bi-search"></i></button>
        </div>

        <!-- ✅ Status Filter Dropdown -->
        <select id="statusFilter" class="form-select form-select-sm" style="width: 160px;">
            <option value="All" <?= !isset($_GET['status']) || $_GET['status'] === 'All' ? 'selected' : '' ?>>All Status</option>
            <option value="Pending" <?= ($_GET['status'] ?? '') === 'Pending' ? 'selected' : '' ?>>Pending</option>
            <option value="Approved" <?= ($_GET['status'] ?? '') === 'Approved' ? 'selected' : '' ?>>Approved</option>
            <option value="Done" <?= ($_GET['status'] ?? '') === 'Done' ? 'selected' : '' ?>>Done</option>
        </select>
    </div>
</div>

<!-- Filter behavior -->
<script>
    document.getElementById('statusFilter').addEventListener('change', function() {
        const selected = this.value;
        const url = new URL(window.location.href);
        url.searchParams.set('status', selected);
        window.location.href = url.toString();
    });
</script>
    



                            <!-- ✅ Table -->
                            <div class="table-responsive">
                                <table class="table table-hover table-striped align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Pre-Repair No.</th>
                                            <th>Designation of Property</th>
                                            <th>Property No.</th>
                                            <th>Date Requested</th>
                                            <th>Status</th> <!-- Added Status column -->
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $mysqli = new mysqli('localhost', 'root', '', 'user_management');
                                        if ($mysqli->connect_errno) {
                                            echo '<tr><td colspan="5">Database connection failed.</td></tr>';
                                        } else {


                                            //                 $sql = "
                                            //     SELECT
                                            //         d.id,
                                            //         d.pre_repair_no,
                                            //         COALESCE(d.property_no, d.equipment) AS property_no,
                                            //         e.designation AS designation_of_property,
                                            //         d.date_requested,
                                            //         d.status
                                            //     FROM documents d
                                            //     LEFT JOIN equipment e
                                            //         ON COALESCE(d.property_no, d.equipment) = e.property_no
                                            //     WHERE d.status IN ('Pending', 'Approved', 'Done')
                                            //     ORDER BY d.date_requested DESC
                                            // ";


                                            // $status_filter = "('Pending', 'Approved', 'Done')"; 

                                            // if (isset($_GET['view']) && $_GET['view'] === 'archived') {
                                            //     $status_filter = "('Archived')";
                                            // }

                                            // ✅ Status Filter Logic
                                            $statusParam = $_GET['status'] ?? 'All';

                                            switch ($statusParam) {
                                                case 'Pending':
                                                    $status_filter = "('Pending')";
                                                    break;
                                                case 'Approved':
                                                    $status_filter = "('Approved')";
                                                    break;
                                                case 'Done':
                                                    $status_filter = "('Done')";
                                                    break;
                                                default:
                                                    $status_filter = "('Pending', 'Approved', 'Done')";
                                            }

                                            if (isset($_GET['view']) && $_GET['view'] === 'archived') {
                                                $status_filter = "('Archived')";
                                            }


                                            $sql = "
    SELECT
        d.id,
        d.pre_repair_no,
        COALESCE(d.property_no, d.equipment) AS property_no,
        e.designation AS designation_of_property,
        d.date_requested,
        d.status
    FROM documents d
    LEFT JOIN equipment e
        ON COALESCE(d.property_no, d.equipment) = e.property_no
    WHERE d.status IN $status_filter
    ORDER BY d.date_requested DESC
";



                                            $result = $mysqli->query($sql);

                                            if ($result && $result->num_rows > 0) {
                                                while ($row = $result->fetch_assoc()) {
                                                    $pre = htmlspecialchars($row['pre_repair_no'] ?? '');
                                                    $designation = htmlspecialchars($row['designation_of_property'] ?? '');
                                                    $prop = htmlspecialchars($row['property_no'] ?? '');
                                                    $date_requested = !empty($row['date_requested']) && $row['date_requested'] !== '0000-00-00 00:00:00'
                                                        ? date('Y-m-d', strtotime($row['date_requested']))
                                                        : '';
                                                    $status = htmlspecialchars($row['status'] ?? '');

                                                    // Determine badge color
                                                    $badgeClass = match ($status) {
                                                        'Pending' => 'bg-primary',
                                                        'Approved' => 'bg-warning text-dark',
                                                        'Done' => 'bg-success',
                                                        default => 'bg-secondary'
                                                    };

                                                    echo '<tr>';
                                                    echo "<td>{$pre}</td>";
                                                    echo "<td>{$designation}</td>";
                                                    echo "<td>{$prop}</td>";
                                                    echo "<td>{$date_requested}</td>";
                                                    echo "<td><span class='badge {$badgeClass}'>{$status}</span></td>";
                                                    echo '</tr>';
                                                }
                                            } else {
                                                echo '<tr><td colspan="5">No requests found.</td></tr>';
                                            }

                                            $mysqli->close();
                                        }
                                        ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Pagination -->
                            <div class="d-flex justify-content-between align-items-center px-3 py-2 border-top">
                                <small class="text-muted">Message 1-11 of 11</small>
                                <div>
                                    <a href="#">First</a> |
                                    <a href="#">Previous</a> |
                                    <a href="#">Next</a> |
                                    <a href="#">Last</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>


        <!-- Archived Documents Modal -->
        <div class="modal fade" id="archivedModal" tabindex="-1" aria-labelledby="archivedModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-xl modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header bg-secondary text-white">
                        <h5 class="modal-title fw-bold" id="archivedModalLabel">
                            <i class="bi bi-archive"></i> Archived Documents
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">

                        <!-- Search -->
                        <div class="mb-3">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text"><i class="bi bi-search"></i></span>
                                <input type="text" id="archivedSearch" class="form-control" placeholder="Search Pre-Repair No.">
                            </div>
                        </div>


                        <div class="table-responsive">
                            <table class="table table-hover table-striped align-middle mb-0" id="archivedTable">
                                <thead class="table-light">
                                    <tr>
                                        <th>Pre-Repair No.</th>
                                        <th>Designation of Property</th>
                                        <th>Property No.</th>
                                        <th>Date Requested</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody id="archivedTableBody">
                                    <?php
                                    $mysqli = new mysqli('localhost', 'root', '', 'user_management');
                                    if ($mysqli->connect_errno) {
                                        echo '<tr><td colspan="5">Database connection failed.</td></tr>';
                                    } else {
                                        $sql = "
                                SELECT
                                    d.id,
                                    d.pre_repair_no,
                                    COALESCE(d.property_no, d.equipment) AS property_no,
                                    e.designation AS designation_of_property,
                                    d.date_requested,
                                    d.status
                                FROM documents d
                                LEFT JOIN equipment e
                                    ON COALESCE(d.property_no, d.equipment) = e.property_no
                                WHERE d.status = 'Archived'
                                ORDER BY d.date_requested DESC
                                ";
                                        $result = $mysqli->query($sql);
                                        $rows = [];
                                        if ($result && $result->num_rows > 0) {
                                            while ($row = $result->fetch_assoc()) {
                                                $rows[] = [
                                                    'pre' => htmlspecialchars($row['pre_repair_no']),
                                                    'designation' => htmlspecialchars($row['designation_of_property']),
                                                    'prop' => htmlspecialchars($row['property_no']),
                                                    'date' => !empty($row['date_requested']) && $row['date_requested'] !== '0000-00-00 00:00:00'
                                                        ? date('Y-m-d', strtotime($row['date_requested']))
                                                        : '',
                                                    'status' => htmlspecialchars($row['status'])
                                                ];
                                            }
                                        }
                                        $mysqli->close();
                                        // Encode rows for JS
                                        echo "<script>const archivedData = " . json_encode($rows) . ";</script>";
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <nav>
                            <ul class="pagination justify-content-center mt-2" id="archivedPagination"></ul>
                        </nav>

                    </div>
                </div>
            </div>
        </div>

        <!-- JS: Search + Pagination -->
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const tableBody = document.getElementById('archivedTableBody');
                const searchInput = document.getElementById('archivedSearch');
                const pagination = document.getElementById('archivedPagination');
                const rowsPerPage = 5;
                let currentPage = 1;

                function renderTable(data, page = 1) {
                    tableBody.innerHTML = '';
                    const start = (page - 1) * rowsPerPage;
                    const paginatedData = data.slice(start, start + rowsPerPage);

                    if (paginatedData.length === 0) {
                        tableBody.innerHTML = '<tr><td colspan="5">No archived documents found.</td></tr>';
                        return;
                    }

                    paginatedData.forEach(row => {
                        const tr = document.createElement('tr');
                        tr.innerHTML = `
                <td>${row.pre}</td>
                <td>${row.designation}</td>
                <td>${row.prop}</td>
                <td>${row.date}</td>
                <td><span class="badge bg-secondary">${row.status}</span></td>
            `;
                        tableBody.appendChild(tr);
                    });

                    renderPagination(data.length, page);
                }

                function renderPagination(totalItems, page) {
                    pagination.innerHTML = '';
                    const totalPages = Math.ceil(totalItems / rowsPerPage);
                    for (let i = 1; i <= totalPages; i++) {
                        const li = document.createElement('li');
                        li.classList.add('page-item');
                        if (i === page) li.classList.add('active');
                        li.innerHTML = `<a class="page-link" href="#">${i}</a>`;
                        li.addEventListener('click', function(e) {
                            e.preventDefault();
                            currentPage = i;
                            renderTable(filteredData, currentPage);
                        });
                        pagination.appendChild(li);
                    }
                }

                let filteredData = archivedData.slice();

                searchInput.addEventListener('input', function() {
                    const searchTerm = this.value.toLowerCase();
                    filteredData = archivedData.filter(row => row.pre.toLowerCase().includes(searchTerm));
                    currentPage = 1;
                    renderTable(filteredData, currentPage);
                });

                // Initial render
                renderTable(filteredData, currentPage);
            });
        </script>


        <!-- ✅ Modal -->
        <div class="modal fade" id="composeModal" tabindex="-1" aria-labelledby="composeModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-xl modal-dialog-scrollable">
                <div class="modal-content">
                    <!-- <div class="modal-header">
                <h5 class="modal-title fw-bold" id="composeModalLabel">Compose Request</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div> -->
                    <div class="modal-body">

                        <div class="container mt-2">
                            <div class="card p-4 border-0 shadow-none">
                                <header class="d-flex align-items-center justify-content-center mb-4 text-center">
                                    <img src="../rs/pepologo.png" alt="PEPO Logo" style="width:100px; height:auto; margin-right: 15px;">
                                    <div>
                                        <small class="d-block">Republic of the Philippines</small>
                                        <small class="d-block">PROVINCIAL GOVERNMENT OF OCCIDENTAL MINDORO</small>
                                        <small class="d-block fw-bold">GENERAL SERVICES OFFICE</small>
                                    </div>
                                    <img src="../rs/occmin.png" alt="Occidental Mindoro Logo" style="width:100px; height:auto; margin-left: 15px;">
                                </header>

                                <form method="POST" action="./side_request.php">
                                    <!-- ===================== CERTIFICATION ===================== -->
                                    <section class="mb-4">
                                        <h5 class="text-center fw-bold text-success border-bottom border-success pb-2">CERTIFICATION</h5>
                                        <!-- <div class="row g-2 mb-2">
            <div class="col-md-6">
                <label for="equipmentType" class="form-label fw-bold">Type:</label>
                <?php
                require_once __DIR__ . '/../config.php';
                $cat_result = $conn->query("SELECT id, category_name FROM equipment_category ORDER BY category_name ASC");
                ?>
                <select class="form-select" id="equipmentType" name="category_id" required>
                    <option value="">Select Category</option>
                    <?php
                    if ($cat_result && $cat_result->num_rows > 0) {
                        while ($cat_row = $cat_result->fetch_assoc()) {
                            echo "<option value='" . htmlspecialchars($cat_row['id']) . "'>" . htmlspecialchars($cat_row['category_name']) . "</option>";
                        }
                    }
                    ?>
                </select>
            </div> -->
                                        <br>
                                        <div class="text-start mb-3 mt-2">
                                            <small class="fw-bold">Pre-Repair No.:</small>
                                            <input type="text" name="prerepair_no" class="form-control d-inline-block w-50" placeholder="e.g., 2025-PDRRMO-1265">
                                        </div>
                            </div>

                            <table class="table table-bordered mb-2">
                                <tbody>
                                    <!-- Property Dropdown -->
                                    <tr>
                                        <td class="fw-bold">Property Number:</td>
                                        <td>
                                            <select class="form-select" id="property_number" name="property_number" required>
                                                <option value="">Select Property Number</option>
                                                <?php
                                                $mysqli = new mysqli('localhost', 'root', '', 'user_management');
                                                if (!$mysqli->connect_errno) {
                                                    $equipment_result = $mysqli->query("SELECT property_no, description, designation, acquisition_date, acquisition_cost, last_repair_date FROM equipment ORDER BY property_no ASC");
                                                    if ($equipment_result && $equipment_result->num_rows > 0) {
                                                        while ($row = $equipment_result->fetch_assoc()) {
                                                            echo "<option value='" . htmlspecialchars($row['property_no']) . "' 
                                            data-description='" . htmlspecialchars($row['description']) . "' 
                                            data-designation='" . htmlspecialchars($row['designation']) . "' 
                                            data-acqdate='" . htmlspecialchars($row['acquisition_date']) . "' 
                                            data-acqcost='" . htmlspecialchars($row['acquisition_cost']) . "' 
                                            data-repairdate='" . htmlspecialchars($row['last_repair_date']) . "'>
                                            " . htmlspecialchars($row['property_no']) . "
                                        </option>";
                                                        }
                                                    }
                                                }
                                                ?>
                                            </select>
                                        </td>
                                    </tr>

                                    <!-- <tr>
                    <td class="fw-bold">Description of Property:</td>
                    <td><input type="text" class="form-control" name="vehicle_type" id="description" readonly></td>
                </tr> -->
                                    <tr>
                                        <td class="fw-bold">Description of Property:</td>
                                        <td>
                                            <input type="text"
                                                class="form-control"
                                                name="vehicle_type"
                                                id="description"
                                                readonly
                                                style="height: 100px;">
                                        </td>
                                    </tr>

                                    <tr>
                                        <td class="fw-bold">Designation of Property:</td>
                                        <td><input type="text" class="form-control" name="property_designation" id="designation" readonly></td>
                                    </tr>
                                    <tr>
                                        <td class="fw-bold">Acquisition Date:</td>
                                        <td><input type="date" class="form-control" name="acquisition_date" id="acquisition_date" readonly></td>
                                    </tr>
                                    <tr>
                                        <td class="fw-bold">Acquisition Cost:</td>
                                        <td><input type="text" class="form-control" name="acquisition_cost" id="acquisition_cost" readonly></td>
                                    </tr>
                                    <tr>
                                        <td class="fw-bold">Date of Last Repair:</td>
                                        <td><input type="date" class="form-control" name="last_repair_date" id="last_repair_date" readonly></td>
                                    </tr>
                                </tbody>

                            </table>

                            <!--         
            <div class="text-start mb-3 mt-2">
                <small class="fw-bold">Carrying Amount:</small>
                <input type="text" name="prerepair_no" class="form-control d-inline-block w-50" placeholder="">
            </div> -->
                            <!-- Carrying Amount -->
                            <div class="text-start mb-3 mt-2">
                                <small class="fw-bold">Carrying Amount:</small>
                                <input type="text" name="carrying_amount" class="form-control d-inline-block w-50" placeholder="">
                            </div>

                            <p>(Attach a copy of latest job order)</p>
                            <p>This document serves to confirm that the PPE/ICS belongs to the Provincial Government of Occidental Mindoro.</p>
                            <!-- <div class="d-flex justify-content-center mt-12">
            <div class="text-center">
                            <input type="text" class="form-control form-control-sm" placeholder="EX: JUAN V. DELA CRUZ JR."   style="width: 1   0%;" />

                <small>Property Custodian Officer</small>   
            </div>
        </div> -->
                            <!-- <div class="d-flex justify-content-center mt-3"> -->
                            <!-- <div class="text-center" style="width: 60%;"> 
    <input type="text" 
           class="form-control form-control-sm text-center" 
           placeholder="EX: JUAN V. DELA CRUZ JR." />
    <small>Property Custodian Officer</small>
  </div> -->


                            <!-- Property Custodian Officer -->
                            <div class="d-flex justify-content-center mt-12  ">
                                <div class="text-center" style="width: 60%;">
                                    <input type="text"
                                        name="officer_name"
                                        class="form-control form-control-sm text-center"
                                        placeholder="EX: JUAN V. DELA CRUZ JR." />
                                    <small>Property Custodian Officer</small>
                                </div>
                            </div>

                        </div>

                        </section>

                        <!-- ===================== PRE-REPAIR INSPECTION ===================== -->
                        <section class="mb-4">
                            <h5 class="text-center fw-bold bg-success text-white py-2">PRE-REPAIR INSPECTION</h5>
                            <p class="text-muted fst-italic text-center">This part of the document is for the admin inspection team only.</p>

                            <div class="row g-3">
                                <div class="col-md-12">
                                    <label class="fw-bold">Defect/Complaint:</label>
                                    <textarea class="form-control bg-light" name="defect" rows="2" readonly></textarea>
                                </div>
                                <div class="col-md-12">
                                    <label class="fw-bold">Findings:</label>
                                    <textarea class="form-control bg-light" name="findings" rows="2" readonly></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label class="fw-bold">Recommendation:</label>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="recommendation" value="For In-House Repair" id="forInHouseRepair" disabled>
                                        <label class="form-check-label" for="forInHouseRepair">For In-House Repair</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="recommendation" value="For Outside Repair" id="forOutsideRepair" disabled>
                                        <label class="form-check-label" for="forOutsideRepair">For Outside Repair</label>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <!-- ===================== MATERIALS ===================== -->
                        <section class="mb-4">
                            <h5 class="text-center fw-bold">MATERIALS & PARTS</h5>
                            <table class="table table-bordered text-center">
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
                                            <td><input type="text" class="form-control bg-light" name="material_<?= $i ?>" readonly></td>
                                            <td><input type="number" class="form-control bg-light" name="quantity_<?= $i ?>" readonly></td>
                                        </tr>
                                    <?php endfor; ?>
                                </tbody>
                            </table>
                        </section>

                        <!-- ===================== SIGNATORIES ===================== -->
                        <div class="row text-center mt-5">
                            <div class="col-md-6 mb-3">
                                <label class="fw-bold">Pre-Inspected by:</label>
                                <input type="text" class="form-control text-center bg-light" name="inspected_by" readonly>
                                <small>Inspector</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="fw-bold">Approved:</label>
                                <input type="text" class="form-control text-center bg-light" name="approved_by_pepo" readonly>
                                <small>PGDH-PEPO</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="fw-bold">Witnessed:</label>
                                <input type="text" class="form-control text-center bg-light" name="witnessed_by" readonly>
                                <small>PGDH-PACCO</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="fw-bold">Approved:</label>
                                <input type="text" class="form-control text-center bg-light" name="approved_by_gso" readonly>
                                <small>PGDH-GSO</small>
                            </div>
                        </div>

                        <div class="text-center mt-4">
                            <button type="submit" class="btn btn-success btn-lg">Submit Request</button>
                        </div>
                        </form>

                        <!-- ✅ Auto-fill Script -->
                        <script>
                            document.getElementById('property_number').addEventListener('change', function() {
                                const selected = this.options[this.selectedIndex];
                                if (selected.value === "") return;

                                document.getElementById('description').value = selected.dataset.description || "";
                                document.getElementById('designation').value = selected.dataset.designation || "";
                                document.getElementById('acquisition_date').value = selected.dataset.acqdate || "";
                                document.getElementById('acquisition_cost').value = selected.dataset.acqcost || "";
                                document.getElementById('last_repair_date').value = selected.dataset.repairdate || "";
                            });
                        </script>

                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- ✅ Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>