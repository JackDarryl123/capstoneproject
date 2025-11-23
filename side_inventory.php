<?php  
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

// ✅ Connect to database
$mysqli = new mysqli('localhost', 'root', '', 'user_management');
if ($mysqli->connect_errno) {
    die("Failed to connect to MySQL: " . $mysqli->connect_error);
}

// ✅ Handle add item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_item'])) {
    $item = trim($_POST['item']);
    $model_no = trim($_POST['model_no']);
    $allocation = trim($_POST['allocation']);
    $quantity = trim($_POST['quantity']);
    $status = trim($_POST['status']); // ✅ Trim extra spaces

    $stmt = $mysqli->prepare("INSERT INTO inventory (item, model_no, allocation, quantity, status, date_added) VALUES (?, ?, ?, ?, ?, NOW())");
    $stmt->bind_param("sssds", $item, $model_no, $allocation, $quantity, $status);
    $stmt->execute();
    $stmt->close();

    echo "<script>alert('New inventory item added successfully!'); window.location.href=window.location.href;</script>";
    exit;
}

// ✅ Handle edit item update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_item'])) {
    $id = $_POST['edit_id'];
    $item = trim($_POST['edit_item']);
    $model_no = trim($_POST['edit_model_no']);
    $allocation = trim($_POST['edit_allocation']);
    $quantity = trim($_POST['edit_quantity']);
    $status = trim($_POST['edit_status']); // ✅ Trim extra spaces

    $stmt = $mysqli->prepare("UPDATE inventory SET item=?, model_no=?, allocation=?, quantity=?, status=? WHERE id=?");
    $stmt->bind_param("sssisi", $item, $model_no, $allocation, $quantity, $status, $id);
    $stmt->execute();
    $stmt->close();

    echo "<script>alert('Item updated successfully!'); window.location.href=window.location.href;</script>";
    exit;
}

// ✅ Fetch items
$result = $mysqli->query("SELECT * FROM inventory ORDER BY id DESC");
?>

<div class="card p-3">
    <h4 class="text-center mb-4">INVENTORY</h4>

    <div class="d-flex mb-3">
        <input type="text" class="form-control me-2" placeholder="Search Model No" style="max-width: 200px;">
        <select class="form-select me-2" style="max-width: 150px;">
            <option value="">STATUS</option>
            <option value="Good">Good</option>
            <option value="In Use">In Use</option>
            <option value="Worn Out">Worn Out</option>
        </select>
        <button class="btn btn-success ms-auto" data-bs-toggle="modal" data-bs-target="#addModal"><i class="bi bi-plus-lg"></i> ADD</button>
        <a href="view_supply_dept.php" class="btn btn-primary ms-2"><i class="fa fa-eye"></i> VIEW SUPPLY DEPT.</a>
    </div>

    <table class="table table-bordered table-hover text-center align-middle">
        <thead class="table-light">
            <tr>
                <th>No#</th>
                <th>Item / Name</th>
                <th>Model No.</th>
                <th>Allocation</th>
                <th>Quantity</th>
                <th>Status</th>
                <th>Date Added</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php $counter = 1; ?>
            <?php while ($part = $result->fetch_assoc()): ?>
                <tr>
                    <td><?= $counter++ ?></td>
                    <td><?= htmlspecialchars($part['item']) ?></td>
                    <td><?= htmlspecialchars($part['model_no']) ?></td>
                    <td><?= htmlspecialchars($part['allocation']) ?></td>
                    <td><?= htmlspecialchars($part['quantity']) ?></td>
                    <td>
                        <?php 
                            $status = trim($part['status']);
                            if (strcasecmp($status, 'Good') === 0): ?>
                                <span class="badge bg-success">Good</span>
                            <?php elseif (strcasecmp($status, 'In Use') === 0): ?>
                                <span class="badge bg-warning text-dark">In Use</span>
                            <?php elseif (strcasecmp($status, 'Worn Out') === 0): ?>
                                <span class="badge bg-danger">Worn Out</span>
                            <?php else: ?>
                                <span class="badge bg-secondary"><?= htmlspecialchars($status) ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($part['date_added']) ?></td>
                    <td>
                        <button 
                            class="btn btn-sm btn-outline-success editBtn"
                            data-id="<?= $part['id'] ?>"
                            data-item="<?= htmlspecialchars($part['item']) ?>"
                            data-model="<?= htmlspecialchars($part['model_no']) ?>"
                            data-allocation="<?= htmlspecialchars($part['allocation']) ?>"
                            data-quantity="<?= htmlspecialchars($part['quantity']) ?>"
                            data-status="<?= htmlspecialchars($part['status']) ?>"
                        ><i class="fa fa-pen"></i></button>
                    </td>
                </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>

<!-- ✅ Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1" aria-labelledby="addModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <div class="modal-header">
          <h5 class="modal-title">Add New Inventory Item</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label>Item / Name</label>
            <input type="text" name="item" class="form-control" required>
          </div>
          <div class="mb-3">
            <label>Model No.</label>
            <input type="text" name="model_no" class="form-control" required>
          </div>
          <div class="mb-3">
            <label>Allocation</label>
            <select name="allocation" class="form-select" required>
              <option value="" selected disabled>Select Location</option>
              <option value="Mamburao">Mamburao</option>
              <option value="Sablayan">Sablayan</option>
              <option value="San Jose">San Jose</option>
              <option value="Lubang">Lubang</option>
            </select>
          </div>
          <div class="mb-3">
            <label>Quantity</label>
            <input type="number" name="quantity" class="form-control" min="0" required>
          </div>
          <div class="mb-3">
            <label>Status</label>
            <select name="status" class="form-select" required>
              <option value="Good">Good</option>
              <option value="In Use">In Use</option>
              <option value="Worn Out">Worn Out</option>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" name="add_item" class="btn btn-success">Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ✅ Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <div class="modal-header">
          <h5 class="modal-title">Edit Inventory Item</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="edit_id" id="edit_id">
          <div class="mb-3">
            <label>Item / Name</label>
            <input type="text" name="edit_item" id="edit_item" class="form-control" required>
          </div>
          <div class="mb-3">
            <label>Model No.</label>
            <input type="text" name="edit_model_no" id="edit_model_no" class="form-control" required>
          </div>
          <div class="mb-3">
            <label>Allocation</label>
            <select name="edit_allocation" id="edit_allocation" class="form-select" required>
              <option value="Mamburao">Mamburao</option>
              <option value="Sablayan">Sablayan</option>
              <option value="San Jose">San Jose</option>
              <option value="Lubang">Lubang</option>
            </select>
          </div>
          <div class="mb-3">
            <label>Quantity</label>
            <input type="number" name="edit_quantity" id="edit_quantity" class="form-control" min="0" required>
          </div>
          <div class="mb-3">
            <label>Status</label>
            <select name="edit_status" id="edit_status" class="form-select" required>
              <option value="Good">Good</option>
              <option value="In Use">In Use</option>
              <option value="Worn Out">Worn Out</option>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" name="update_item" class="btn btn-success">Update</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ✅ JS Script -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll('.editBtn').forEach(btn => {
  btn.addEventListener('click', function() {
    document.getElementById('edit_id').value = this.dataset.id;
    document.getElementById('edit_item').value = this.dataset.item;
    document.getElementById('edit_model_no').value = this.dataset.model;
    document.getElementById('edit_allocation').value = this.dataset.allocation;
    document.getElementById('edit_quantity').value = this.dataset.quantity;
    document.getElementById('edit_status').value = this.dataset.status.trim();

    new bootstrap.Modal(document.getElementById('editModal')).show();
  });
});
</script>
