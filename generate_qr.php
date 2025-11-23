<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$mysqli = new mysqli('localhost', 'root', '', 'user_management');
if ($mysqli->connect_errno) {
    die("Database connection failed: " . $mysqli->connect_error);
}

// Get equipment ID
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Invalid equipment ID.");
}
$id = intval($_GET['id']);

// Fetch equipment details
$query = "
    SELECT e.*, c.category_name 
    FROM equipment e
    JOIN equipment_category c ON e.category_id = c.id
    WHERE e.id = ?
";
$stmt = $mysqli->prepare($query);
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    die("Equipment not found.");
}
$equipment = $result->fetch_assoc();
$stmt->close();

// QR Code library (requires composer or downloaded library)
require_once 'phpqrcode/qrlib.php'; // Adjust path if needed

// QR content – link back to this page or just display data
// $qrData = "Property No: {$equipment['property_no']}\n"
//          . "Category: {$equipment['category_name']}\n"
//          . "Location: {$equipment['location']}\n"
//          . "Type: {$equipment['type']}\n"
//          . "Status: {$equipment['status']}\n";

// ✅ Encode a direct link to your side_scan.php page
$qrData = "http://localhost/PEPO/generate_qr.php?id={$equipment['id']}";




$tempDir = "temp_qr/";
if (!file_exists($tempDir)) {
    mkdir($tempDir);
}

$fileName = "equipment_{$equipment['id']}.png";
$filePath = $tempDir . $fileName;

QRcode::png($qrData, $filePath, QR_ECLEVEL_L, 5);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>QR Code - <?= htmlspecialchars($equipment['property_no']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f9f9f9; }
        .container { max-width: 700px; margin-top: 40px; }
        .card { box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        .qr-img { width: 200px; height: 200px; object-fit: contain; }
    </style>
</head>
<body>
<div class="container">
    <div class="card p-4">
        <div class="text-center">
            <h4 class="mb-3">Equipment QR Code</h4>
            <img src="<?= $filePath ?>" alt="QR Code" class="qr-img mb-3">
            
            <h5><?= htmlspecialchars($equipment['property_no']) ?></h5>
            <p class="text-muted"><?= htmlspecialchars($equipment['category_name']) ?></p>
        </div>

        <hr>

        <h6>Equipment Details</h6>
        <table class="table table-bordered mt-3">
            <tr><th>Property No</th><td><?= htmlspecialchars($equipment['property_no']) ?></td></tr>
            <tr><th>Category</th><td><?= htmlspecialchars($equipment['category_name']) ?></td></tr>
            <tr><th>Location</th><td><?= htmlspecialchars($equipment['location']) ?></td></tr>
            <tr><th>Type</th><td><?= htmlspecialchars($equipment['type']) ?></td></tr>
            <tr><th>Status</th><td><?= htmlspecialchars($equipment['status']) ?></td></tr>
            <tr><th>Description</th><td><?= htmlspecialchars($equipment['description']) ?></td></tr>
            <tr><th>Designation</th><td><?= htmlspecialchars($equipment['designation']) ?></td></tr>
            <tr><th>Acquisition Date</th><td><?= htmlspecialchars($equipment['acquisition_date']) ?></td></tr>
            <tr><th>Acquisition Cost</th><td><?= htmlspecialchars($equipment['acquisition_cost']) ?></td></tr>
            <tr><th>Last Repair Date</th><td><?= htmlspecialchars($equipment['last_repair_date']) ?></td></tr>
        </table>



    </div>

    <div class="text-center mt-3">
    <a href="admin_dashboard.php?view=equipment" class="btn btn-secondary">← Back to Equipment List</a>

    <!-- ✅ Download Button -->
<a href="<?= $filePath ?>" 
   download="<?= htmlspecialchars($equipment['property_no']) ?>.png" 
   class="btn btn-success me-2">
    <i class="bi bi-download"></i> Download QR
</a>

<!-- ✅ Print Button -->
<button onclick="window.print()" class="btn btn-primary">
    <i class="bi bi-printer"></i> Print
</button>

</div>
</div>
</body>
</html>
