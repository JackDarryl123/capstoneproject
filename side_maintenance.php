<?php 
// Start session + output buffering
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database connection
$mysqli = new mysqli('localhost', 'root', '', 'user_management');
if ($mysqli->connect_errno) {
    die("Connection failed: " . $mysqli->connect_error);
}

// Handle Add History form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_history'])) {
    $pre_repair_no  = trim($_POST['pre_repair_no'] ?? '');
    $recommendation = trim($_POST['recommendation'] ?? '');
    $status         = trim($_POST['status'] ?? '');
    $date           = $_POST['date'] ?? '';

    if ($pre_repair_no && $recommendation && $status && $date) {
        // JOIN to get equipment name
        $query = "
            INSERT INTO maintenance (equipment, property_no, pre_repair_no, recommendation, date, status)
            SELECT c.category_name, d.property_no, d.pre_repair_no, ?, ?, ?
            FROM documents d
            JOIN equipment_category c ON d.category_id = c.id
            WHERE d.pre_repair_no = ?
            LIMIT 1
        ";

        if ($stmt = $mysqli->prepare($query)) {
            $stmt->bind_param("ssss", $recommendation, $date, $status, $pre_repair_no);

            if ($stmt->execute()) {
                $_SESSION['success'] = $stmt->affected_rows > 0 
                    ? "Maintenance record copied successfully!"
                    : "No matching document found for Pre-Repair No: " . htmlspecialchars($pre_repair_no);
            } else {
                $_SESSION['error'] = "Error inserting record: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $_SESSION['error'] = "Failed to prepare query: " . $mysqli->error;
        }
    } else {
        $_SESSION['error'] = "All fields are required.";
    }
}

// Fetch only "Done" documents
// $query_done = "
//     SELECT 
//         c.category_name AS equipment,
//         d.property_no,
//         d.pre_repair_no,
//         d.recommendation,
//         d.status
//     FROM documents d
//     LEFT JOIN equipment_category c ON d.category_id = c.id
//     WHERE LOWER(COALESCE(d.status, '')) = 'done'
//     ORDER BY d.pre_repair_no DESC
// ";

$query_done = "
    SELECT 
        d.id,
        c.category_name AS equipment,
        d.property_no,
        d.pre_repair_no,
        d.recommendation,
        d.status
    FROM documents d
    LEFT JOIN equipment_category c ON d.category_id = c.id
    WHERE LOWER(COALESCE(d.status, '')) = 'done'
    ORDER BY d.pre_repair_no DESC
";


$result = $mysqli->query($query_done);

if ($result === false) {
    error_log("Query failed (done): " . $mysqli->error);
    $result = null;
}
?>

<!-- Add History Modal -->
<div class="modal fade" id="addHistoryModal" tabindex="-1" aria-labelledby="addHistoryModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addHistoryModalLabel">Add Maintenance History</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="pre_repair_no" class="form-label">Pre-Repair No.</label>
                        <select class="form-select" id="pre_repair_no" name="pre_repair_no" required>
                            <option value="">Select Pre-Repair No</option>
                            <?php
                            $pr_result = $mysqli->query("
                                SELECT d.pre_repair_no, c.category_name 
                                FROM documents d
                                LEFT JOIN equipment_category c ON d.category_id = c.id
                                WHERE d.pre_repair_no IS NOT NULL AND d.pre_repair_no != ''
                                ORDER BY d.pre_repair_no ASC
                            ");
                            if ($pr_result && $pr_result->num_rows > 0) {
                                while ($pr = $pr_result->fetch_assoc()) {
                                    $display = htmlspecialchars($pr['pre_repair_no']) . ' - ' . htmlspecialchars($pr['category_name'] ?? 'Unknown');
                                    echo '<option value="' . htmlspecialchars($pr['pre_repair_no']) . '">' . $display . '</option>';
                                }
                            } else {
                                echo '<option value="">No pre-repair numbers available</option>';
                            }
                            if ($pr_result instanceof mysqli_result) $pr_result->free();
                            ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="recommendation" class="form-label">Recommendation</label>
                        <select class="form-select" id="recommendation" name="recommendation" required>
                            <option value="">Add Recommendation</option>
                            <option value="In-House Repair">In-House Repair</option>
                            <option value="Outside Repair">Outside Repair</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="date" class="form-label">Date</label>
                        <input type="date" class="form-control" id="date" name="date" required>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" name="add_history" class="btn btn-success">Add History</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="card p-3">
    <h3 class="text-center mb-4">MAINTENANCE AND REPAIR HISTORY</h3>

    <div class="d-flex justify-content-between mb-3 align-items-center">
        <div class="d-flex">
            <div class="input-group me-3" style="width: 300px;">
                <input type="text" class="form-control" placeholder="Pre-Repair No" id="search_pre_repair">
                <button class="btn btn-outline-secondary" id="btn_search"><i class="fa fa-search"></i></button>
            </div>
        </div>
        <!-- <button class="btn btn-success mb-3" data-bs-toggle="modal" data-bs-target="#addHistoryModal">
            <i class="fa fa-plus"></i> Add History
        </button> -->
    </div>

    <table class="table table-bordered text-center align-middle">
        <thead class="table-light">
            <tr>
                <th>Equipment</th>
                <th>Property No.</th>
                <th>Pre-Repair No.</th>
                <th>Recommendation</th>
                <th>Status</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php
            if ($result === null || $result->num_rows === 0) {
                echo '<tr><td colspan="6">No records found.</td></tr>';
            } else {
                while ($row = $result->fetch_assoc()):
                    $pre_repair_no = htmlspecialchars($row['pre_repair_no'] ?? '');
                    $statusVal = strtolower(trim($row['status'] ?? ''));
                    if ($statusVal !== 'done') continue;
            ?>
                <tr>
                    <td><?= htmlspecialchars($row['equipment'] ?? '') ?></td>
                    <td><?= htmlspecialchars($row['property_no'] ?? '') ?></td>
                    <td><?= $pre_repair_no ?></td>
                    <td><?= htmlspecialchars($row['recommendation'] ?? '') ?></td>
                    <td><span class="badge bg-success">Done</span></td>
                    <td>
                 
                         <a href="view_document.php?id=<?= urlencode($row['id']); ?>" class="btn btn-sm btn-outline-primary" title="View">
    <i class="fa fa-eye"></i>
</a>

                    </td>
                </tr>
            <?php
                endwhile;
                $result->free();
            }
            ?>
        </tbody>
    </table>
</div>

<script>
// Client-side search (optional)
document.getElementById('btn_search').addEventListener('click', function(e){
    e.preventDefault();
    const search = document.getElementById('search_pre_repair').value.toLowerCase();
    document.querySelectorAll('table tbody tr').forEach(row => {
        const pre = (row.children[2]?.textContent || '').toLowerCase();
        row.style.display = search && !pre.includes(search) ? 'none' : '';
    });
});
</script>
