        <?php
        if (session_status() === PHP_SESSION_NONE) {
          session_start();
        }

        $mysqli = new mysqli('localhost', 'root', '', 'user_management');

        // Add Category Logic
        if (isset($_POST['add_category'])) {
          $category_name = strtoupper(trim($_POST['category_name']));

          $check_stmt = $mysqli->prepare("SELECT COUNT(*) FROM equipment_category WHERE category_name = ?");
          $check_stmt->bind_param("s", $category_name);
          $check_stmt->execute();
          $check_stmt->bind_result($count);
          $check_stmt->fetch();
          $check_stmt->close();

          if ($count > 0) {
            echo "<script>alert('Category already exists!');</script>";
          } else {
            $stmt = $mysqli->prepare("INSERT INTO equipment_category (category_name) VALUES (?)");
            $stmt->bind_param("s", $category_name);
            if ($stmt->execute()) {
              echo "<script>alert('Category added successfully!'); location.reload();</script>";
            } else {
              echo "<script>alert('Error adding category.');</script>";
            }
            $stmt->close();
          }
        }

        // Add Equipment Logic
        if (isset($_POST['add_equipment'])) {
          $category_id = intval($_POST['category_id']);
          $property_no = trim($_POST['property_no']);
          $location = trim($_POST['location']);
          $type = trim($_POST['type']);
          $status = trim($_POST['status']);

          // New fields
          $description = trim($_POST['description']);
          $designation = trim($_POST['designation']);
          $acquisition_date = trim($_POST['acquisition_date']);
          $acquisition_cost = trim($_POST['acquisition_cost']);
          $last_repair_date = trim($_POST['last_repair_date']);

          // Validate required fields
          if (empty($category_id) || empty($property_no) || empty($location) || empty($type) || empty($status)) {
            echo "<script>alert('Please fill out all required fields.');</script>";
          } else {
            $checkStmt = $mysqli->prepare("SELECT id FROM equipment WHERE property_no = ?");
            $checkStmt->bind_param("s", $property_no);
            $checkStmt->execute();
            $checkStmt->store_result();

            if ($checkStmt->num_rows > 0) {
              echo "<script>alert('Duplicate Property No. This equipment already exists.');</script>";
            } else {
              $stmt = $mysqli->prepare("
        INSERT INTO equipment 
        (category_id, property_no, location, type, status, description, designation, acquisition_date, acquisition_cost, last_repair_date) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
      ");
              $stmt->bind_param("isssssssss", $category_id, $property_no, $location, $type, $status, $description, $designation, $acquisition_date, $acquisition_cost, $last_repair_date);

              if ($stmt->execute()) {
                echo "<script>alert('Equipment added successfully!'); window.location.href=window.location.href;</script>";
              } else {
                echo "<script>alert('Error adding equipment. Please try again.');</script>";
              }

              $stmt->close();
            }

            $checkStmt->close();
          }
        }

        // Edit Equipment
        if (isset($_POST['edit_equipment'])) {
          $id = intval($_POST['equipment_id']);
          $category_id = intval($_POST['category_id']);
          $property_no = trim($_POST['property_no']);
          $location = trim($_POST['location']);
          $type = trim($_POST['type']);
          $status = trim($_POST['status']);

          $stmt = $mysqli->prepare("UPDATE equipment SET category_id=?, property_no=?, location=?, type=?, status=? WHERE id=?");
          $stmt->bind_param("issssi", $category_id, $property_no, $location, $type, $status, $id);
          echo $stmt->execute()
            ? "<script>alert('Equipment updated successfully!'); window.location.href=window.location.href;</script>"
            : "<script>alert('Error updating equipment.');</script>";
          $stmt->close();
        }

        ?>

        
        <!-- Jack Darryl Gernale -->

        <body>

          <div class="card p-3">
            <h3 class="text-center mb-4">EQUIPMENT MONITORING</h3>



            <div class="d-flex justify-content-between mb-3">
              <!-- Search Input -->
              <div class="input-group" style="width: 300px;">
                <input type="text" class="form-control" placeholder="Search by Property No">
                <button class="btn btn-outline-secondary"><i class="fa fa-search"></i></button>
              </div>

              <!-- Filters + Buttons -->
              <div class="d-flex gap-2">
                <select class="form-select" style="width: 200px;">
                  <option>LOCATION</option>
                  <option>MAMBURAO</option>
                  <option>SABLAYAN</option>
                  <option>LUBANG</option>
                  <option>SAN JOSE</option>
                </select>

                <select class="form-select" style="width: 200px;">
                  <option>STATUS</option>
                  <option>OPERATIONAL</option>
                  <option>UNDER REPAIR</option>
                  <option>UNSERVICEABLE</option>

                </select>

                <select class="form-select" style="width: 200px;">
                  <option>TYPE</option>
                  <option>HEAVY EQUIPMENT</option>
                  <option>LIGHT EQUIPMENT</option>

                </select>

                <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                  <i class="fa fa-plus"></i> ADD CATEGORY
                </button>

                <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addEquipmentModal">
                  <i class="fa fa-plus"></i> ADD
                </button>
              </div>
            </div>



            <br>

            <!-- ADD CATEGORY MODAL -->
            <div class="modal fade" id="addCategoryModal" tabindex="-1" aria-labelledby="addCategoryModalLabel" aria-hidden="true">
              <div class="modal-dialog">
                <form method="post" action="">
                  <div class="modal-content">
                    <div class="modal-header">
                      <h5 class="modal-title">Add Equipment Category</h5>
                      <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                      <input type="text" name="category_name" class="form-control" placeholder="Enter category name" required>
                    </div>
                    <div class="modal-footer">
                      <button type="submit" name="add_category" class="btn btn-primary">Add</button>
                      <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    </div>
                  </div>
                </form>
              </div>
            </div>

            <!-- ADD EQUIPMENT MODAL -->
            <div class="modal fade" id="addEquipmentModal" tabindex="-1" aria-labelledby="addEquipmentModalLabel" aria-hidden="true">
              <div class="modal-dialog">
                <form method="post" action="">
                  <div class="modal-content">
                    <div class="modal-header">
                      <h5 class="modal-title">Add Equipment</h5>
                      <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                      <!-- Category Dropdown -->
                      <div class="mb-3">
                        <label class="form-label">Equipment Category</label>
                        <select name="category_id" class="form-select" required>
                          <option value="">Select Category</option>
                          <?php
                          $result = $mysqli->query("SELECT id, category_name FROM equipment_category ORDER BY category_name ASC");
                          while ($row = $result->fetch_assoc()) {
                            echo "<option value='{$row['id']}'>{$row['category_name']}</option>";
                          }
                          ?>
                        </select>
                      </div>

                      <!-- Property No -->
                      <div class="mb-3">
                        <label class="form-label">Property No</label>
                        <input type="text" name="property_no" class="form-control" placeholder="Enter Property No" required>
                      </div>

                      <!-- Location -->
                      <div class="mb-3">
                        <label class="form-label">Location</label>
                        <select name="location" class="form-select" required>
                          <option value="">Select Location</option>
                          <option value="Mamburao">Mamburao</option>
                          <option value="Sablayan">Sablayan</option>
                          <option value="San Jose">San Jose</option>
                          <option value="Lubang">Lubang</option>
                        </select>
                      </div>

                      <!-- Type -->
                      <div class="mb-3">
                        <label class="form-label">Type</label>
                        <select name="type" class="form-select" required>
                          <option value="">Select Type</option>
                          <option value="Heavy Equipment">Heavy Equipment</option>
                          <option value="Light Equipment">Light Equipment</option>
                        </select>
                      </div>

                      <!-- Status -->
                      <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select" required>
                          <option value="">Select Status</option>
                          <option value="Operational">Operational</option>
                          <option value="Under repair">Under repair</option>
                          <option value="Unserviceable">Unserviceable</option>
                        </select>
                      </div>



                      <!-- Description of Property -->
                      <div class="mb-3">
                        <label class="form-label">Description of Property</label>
                        <input type="text" name="description" class="form-control" placeholder="e.g., Remove Truck ISUZU OLRYTE...">
                      </div>

                      <!-- Designation of Property -->
                      <div class="mb-3">
                        <label class="form-label">Designation of Property</label>
                        <input type="text" name="designation" class="form-control" placeholder="e.g., Provincial Social Welfare and Development">
                      </div>

                      <!-- Acquisition Date -->
                      <div class="mb-3">
                        <label class="form-label">Acquisition Date</label>
                        <input type="date" name="acquisition_date" class="form-control">
                      </div>

                      <!-- Acquisition Cost -->
                      <div class="mb-3">
                        <label class="form-label">Acquisition Cost</label>
                        <input type="number" name="acquisition_cost" step="0.01" class="form-control" placeholder="e.g., 000.00">
                      </div>

                      <!-- Date of Last Repair -->
                      <div class="mb-3">
                        <label class="form-label">Date of Last Repair</label>
                        <input type="date" name="last_repair_date" class="form-control">
                      </div>

                    </div>
                    <div class="modal-footer">
                      <button type="submit" name="add_equipment" class="btn btn-primary">Add</button>
                      <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    </div>
                  </div>
                </form>
              </div>
            </div>

            <!-- Edit Equipment Modal -->
            <div class="modal fade" id="editEquipmentModal" tabindex="-1">
              <div class="modal-dialog">
                <form method="post">
                  <div class="modal-content">
                    <div class="modal-header">
                      <h5 class="modal-title">Edit Equipment</h5>
                      <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                      <input type="hidden" name="equipment_id" id="edit_equipment_id">

                      <div class="mb-3">
                        <label class="form-label">Equipment Category</label>
                        <select name="category_id" id="edit_category_id" class="form-select" required>
                          <option value="">Select Category</option>
                          <?php
                          $result = $mysqli->query("SELECT id, category_name FROM equipment_category ORDER BY category_name ASC");
                          while ($row = $result->fetch_assoc()) {
                            echo "<option value='{$row['id']}'>{$row['category_name']}</option>";
                          }
                          ?>
                        </select>
                      </div>
                      <div class="mb-3">
                        <label class="form-label">Property No</label>
                        <input type="text" name="property_no" id="edit_property_no" class="form-control" required>
                      </div>
                      <div class="mb-3">
                        <label class="form-label">Location</label>
                        <select name="location" id="edit_location" class="form-select" required>
                          <option value="Mamburao">Mamburao</option>
                          <option value="Sablayan">Sablayan</option>
                          <option value="San Jose">San Jose</option>
                          <option value="Lubang">Lubang</option>
                        </select>
                      </div>
                      <div class="mb-3">
                        <label class="form-label">Type</label>
                        <select name="type" id="edit_type" class="form-select" required>
                          <option value="Heavy Equipment">Heavy Equipment</option>
                          <option value="Light Equipment">Light Equipment</option>
                        </select>
                      </div>
                      <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select name="status" id="edit_status" class="form-select" required>
                          <option value="Operational">Operational</option>
                          <option value="Under repair">Under repair</option>
                          <option value="Unserviceable">Unserviceable</option>
                        </select>
                      </div>


                      

                      <!-- Description of Property -->
                      <div class="mb-3">
                        <label class="form-label">Description of Property</label>
                        <input type="text" name="description" id="edit_description" class="form-control">
                      </div>

                      <!-- Designation of Property -->
                      <div class="mb-3">
                        <label class="form-label">Designation of Property</label>
                        <input type="text" name="designation" id="edit_designation" class="form-control">
                      </div>

                      <!-- Acquisition Date -->
                      <div class="mb-3">
                        <label class="form-label">Acquisition Date</label>
                        <input type="date" name="acquisition_date" id="edit_acquisition_date" class="form-control">
                      </div>

                      <!-- Acquisition Cost -->
                      <div class="mb-3">
                        <label class="form-label">Acquisition Cost</label>
                        <input type="number" name="acquisition_cost" id="edit_acquisition_cost" step="0.01" class="form-control">
                      </div>

                      <!-- Date of Last Repair -->
                      <div class="mb-3">
                        <label class="form-label">Date of Last Repair</label>
                        <input type="date" name="last_repair_date" id="edit_last_repair_date" class="form-control">
                      </div>

                    </div>
                    <div class="modal-footer">
                      <button type="submit" name="edit_equipment" class="btn btn-primary">Update</button>
                      <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    </div>
                  </div>
                </form>
              </div>
            </div>


            <!-- Table  -->
            <table class="table table-bordered text-center">
              <thead class="table-light">
                <tr>
                  <th>ID</th>
                  <th>EQUIPMENT</th>
                  <th>PROPERTY NO:</th>
                  <th>LOCATION</th>
                  <th>TYPE</th>
                  <th>STATUS</th>
                  <th>ACTION</th>
                </tr>
              </thead>
              <tbody>
<?php
$query = "
  SELECT 
    e.id, 
    e.category_id, 
    c.category_name, 
    e.property_no, 
    e.location, 
    e.type, 
    e.status,
    e.description,
    e.designation,
    e.acquisition_date,
    e.acquisition_cost,
    e.last_repair_date
  FROM equipment e
  JOIN equipment_category c ON e.category_id = c.id 
  ORDER BY e.id DESC
";

$result = $mysqli->query($query);
if ($result && $result->num_rows > 0) {
  while ($row = $result->fetch_assoc()) {

    // ✅ Assign badge color for status
    $statusClass = match (strtolower($row['status'])) {
      'operational' => 'bg-success text-white',
      'under repair' => 'bg-primary text-white',
      'unserviceable' => 'bg-danger text-white',
      default => 'bg-secondary text-white',
    };

    echo "
    <tr>
      <td>{$row['id']}</td>
      <td>{$row['category_name']}</td>
      <td>{$row['property_no']}</td>
      <td>{$row['location']}</td>
      <td>{$row['type']}</td>
      <td><span class='badge {$statusClass}'>{$row['status']}</span></td>
      <td>
        <!-- Edit Button -->
        <a href='#' class='fas fa-edit edit-btn'
          style='color: orange; cursor: pointer; margin-right: 10px;'
          title='Edit'
          data-id='{$row['id']}'
          data-category-id='{$row['category_id']}'
          data-property-no='{$row['property_no']}'
          data-location='{$row['location']}'
          data-type='{$row['type']}'
          data-status='{$row['status']}'
          data-description='{$row['description']}'
          data-designation='{$row['designation']}'
          data-acquisition-date='{$row['acquisition_date']}'
          data-acquisition-cost='{$row['acquisition_cost']}'
          data-last-repair-date='{$row['last_repair_date']}'>
        </a>

        <!-- QR Code Button -->
        <a href='generate_qr.php?id={$row['id']}' class='fas fa-qrcode'
          style='color: green; cursor: pointer;'
          title='View QR Code'>
        </a>
      </td>
    </tr>";
  }
} else {
  echo "<tr><td colspan='7'>No equipment found.</td></tr>";
}
?>
</tbody>

            </table>


            <!-- Pagination -->
            <!-- <div class="d-flex justify-content-center mt-3">
              <nav aria-label="Equipment table pagination">
                <ul class="pagination">
                  <li class="page-item disabled"><a class="page-link" href="#">Previous</a></li>
                  <li class="page-item active"><a class="page-link" href="#">1</a></li>
                  <li class="page-item"><a class="page-link" href="#">2</a></li>
                  <li class="page-item"><a class="page-link" href="#">3</a></li>
                  <li class="page-item"><a class="page-link" href="#">Next</a></li>
                </ul>
              </nav>
            </div> -->

            <!-- Pagination Placeholder -->
            <nav>
              <ul class="pagination justify-content-center">
                <li class="page-item disabled"><a class="page-link">First</a></li>
                <li class="page-item disabled"><a class="page-link">«</a></li>
                <li class="page-item active"><a class="page-link">1</a></li>
                <li class="page-item"><a class="page-link" href="#">2</a></li>
                <li class="page-item"><a class="page-link" href="#">3</a></li>
                <li class="page-item"><a class="page-link" href="#">»</a></li>
                <li class="page-item"><a class="page-link" href="#">Last</a></li>
              </ul>
            </nav>

          </div>



          <!-- Scripts  -->
          <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
          <script>
            document.addEventListener('DOMContentLoaded', function() {
              document.querySelectorAll('.edit-btn').forEach(btn => {
                btn.addEventListener('click', function(e) {
                  e.preventDefault();

                  document.getElementById('edit_equipment_id').value = this.dataset.id;
                  document.getElementById('edit_category_id').value = this.dataset.categoryId;
                  document.getElementById('edit_property_no').value = this.dataset.propertyNo;
                  document.getElementById('edit_location').value = this.dataset.location;
                  document.getElementById('edit_type').value = this.dataset.type;
                  document.getElementById('edit_status').value = this.dataset.status;
                  document.getElementById('edit_description').value = this.dataset.description || '';
                  document.getElementById('edit_designation').value = this.dataset.designation || '';
                  document.getElementById('edit_acquisition_date').value = this.dataset.acquisitionDate || '';
                  document.getElementById('edit_acquisition_cost').value = this.dataset.acquisitionCost || '';
                  document.getElementById('edit_last_repair_date').value = this.dataset.lastRepairDate || '';


                  new bootstrap.Modal(document.getElementById('editEquipmentModal')).show();
                });
              });
            });
          </script>



          <!-- Font Awesome -->
          <script src="https://kit.fontawesome.com/a076d05399.js" crossorigin="anonymous"></script>




        </body>

        </html>