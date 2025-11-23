<?php
// view_document.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
 
$mysqli = new mysqli("localhost", "root", "", "user_management");

if ($mysqli->connect_error) {
    die("Database connection failed: " . $mysqli->connect_error);
}

 // <-- change to your DB connection

// Example: fetch equipment info if viewing an existing doc
$id = $_GET['id'] ?? null;
$equipment = null;
if ($id) {
    $stmt = $mysqli->prepare("SELECT * FROM equipment WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $equipment = $stmt->get_result()->fetch_assoc();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Pre-Repair Inspection Form</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background:#f8f9fa; }
    .form-section { border:1px solid #dee2e6; padding:20px; border-radius:8px; background:#fff; margin-bottom:20px; }
    .form-header { background:#f1f1f1; padding:10px; border-radius:5px; font-weight:bold; }
    table th, table td { vertical-align:middle; }
  </style>
</head>
<body class="container my-4">

  <h3 class="text-center mb-4">Pre-Repair Inspection Form</h3>

  <!-- Certification Section -->
  <div class="form-section">
    <div class="form-header">CERTIFICATION</div>
    <div class="row mt-3">
      <div class="col-md-4">
        <label>Pre-Repair No.</label>
        <input type="text" name="pre_repair_no" class="form-control" value="2025-PDRRMO-XXXX">
      </div>
      <div class="col-md-4">
        <label>Type</label>
        <select name="type" class="form-select">
          <option>Motor Vehicle</option>
          <option>Office Equipment</option>
          <option>Heavy Equipment</option>
          <option>ICTS</option>
          <option>Other</option>
        </select>
      </div>
    </div>

    <div class="mt-3">
      <label>Description of Property</label>
      <input type="text" class="form-control" name="description" value="<?= $equipment['description'] ?? '' ?>">
    </div>

    <div class="row mt-3">
      <div class="col-md-6">
        <label>Property Number</label>
        <input type="text" class="form-control" name="property_no" value="<?= $equipment['property_no'] ?? '' ?>">
      </div>
      <div class="col-md-6">
        <label>Serial/Engine No.</label>
        <input type="text" class="form-control" name="serial_no" value="<?= $equipment['serial_no'] ?? '' ?>">
      </div>
    </div>

    <div class="row mt-3">
      <div class="col-md-6">
        <label>Acquisition Cost</label>
        <input type="number" class="form-control" name="acquisition_cost">
      </div>
      <div class="col-md-6">
        <label>Acquisition Date</label>
        <input type="date" class="form-control" name="acquisition_date">
      </div>
    </div>
  </div>

  <!-- Pre Repair Inspection -->
  <div class="form-section">
    <div class="form-header">PRE-REPAIR INSPECTION</div>

    <label>Defect/Complaint</label>
    <textarea class="form-control mb-2" name="defect"></textarea>

    <label>Findings</label>
    <textarea class="form-control mb-2" name="findings"></textarea>

    <label>Recommendation</label>
    <div class="form-check">
      <input class="form-check-input" type="radio" name="recommendation" value="In-House">
      <label class="form-check-label">For In-House Repair</label>
    </div>
    <div class="form-check mb-3">
      <input class="form-check-input" type="radio" name="recommendation" value="Outside">
      <label class="form-check-label">For Outside Repair</label>
    </div>

    <!-- Materials Table -->
    <table class="table table-bordered">
      <thead>
        <tr>
          <th style="width:10%">Item #</th>
          <th style="width:60%">Material/Parts</th>
          <th style="width:30%">Quantity</th>
        </tr>
      </thead>
      <tbody>
        <?php for ($i=1; $i<=6; $i++): ?>
        <tr>
          <td><?= $i ?></td>
          <td><input type="text" class="form-control" name="materials[]"></td>
          <td><input type="number" class="form-control" name="quantity[]"></td>
        </tr>
        <?php endfor; ?>
      </tbody>
    </table>
  </div>

  <!-- Signatures -->
  <div class="form-section">
    <div class="row text-center">
      <div class="col-md-4">
        <label>Pre-Inspected by</label>
        <input type="text" class="form-control" name="inspector">
        <small>Inspector</small>
      </div>
      <div class="col-md-4">
        <label>Approved by</label>
        <input type="text" class="form-control" name="pgdh_pepo">
        <small>PGDH-PEPO</small>
      </div>
      <div class="col-md-4">
        <label>Approved by</label>
        <input type="text" class="form-control" name="pgdh_gso">
        <small>PGDH-GSO</small>
      </div>
    </div>
  </div>

  <div class="text-center mb-5">
    <button class="btn btn-success">Save</button>
    <button class="btn btn-primary">Print</button>
  </div>

</body>
</html>
