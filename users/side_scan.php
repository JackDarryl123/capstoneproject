<?php 
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$mysqli = new mysqli('localhost', 'root', '', 'user_management');
if ($mysqli->connect_errno) {
    die("Database connection failed: " . $mysqli->connect_error);
}

require_once __DIR__ . '/../phpqrcode/qrlib.php';

$equipment = null;

if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $requestedId = intval($_GET['id']);

    $query = "
        SELECT e.*, c.category_name 
        FROM equipment e
        JOIN equipment_category c ON e.category_id = c.id
        WHERE e.id = ?
    ";
    $stmt = $mysqli->prepare($query);
    $stmt->bind_param("i", $requestedId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $equipment = $result->fetch_assoc();
    }
    $stmt->close();

    if ($equipment) {
        $qrData = "http://localhost/PEPO/users/side_scan.php?id={$equipment['id']}";
        $tempDir = "temp_qr/";
        if (!file_exists($tempDir)) {
            mkdir($tempDir);
        }
        $fileName = "equipment_{$equipment['id']}.png";
        $filePath = $tempDir . $fileName;
        QRcode::png($qrData, $filePath, QR_ECLEVEL_L, 5);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Scan Equipment QR Code</title>
    <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
    <style>
        body {
            background-color: #f4f6f9;
            font-family: "Segoe UI", sans-serif;
        }
        .scan-container {
            max-width: 600px;
            margin: 60px auto;
            background: #fff;
            padding: 40px;
            border-radius: 16px;
            box-shadow: 0 5px 18px rgba(0,0,0,0.1);
        }
        #qr-reader {
            width: 100%;
            border: 2px dashed #007bff;
            border-radius: 10px;
            overflow: hidden;
        }
        h3 {
            font-weight: 600;
        }
        table th {
            width: 250px;
            background: #f8f9fa;
        }
        .btn {
            border-radius: 8px;
        }
        .qr-img {
            width: 180px;
            height: 180px;
            border-radius: 10px;
            border: 1px solid #ddd;
        }
    </style>
</head>
<body>

<div class="container">
    <?php if (!$equipment): ?>
    <!-- QR Scanner Interface -->
    <div class="scan-container text-center">
        <h3 class="mb-2"><i class="bi bi-qr-code-scan"></i> Scan Equipment QR Code</h3>
        <p class="text-muted mb-4">Please allow camera access. Hold your camera steady to scan.</p>

        <div id="qr-reader"></div>

        <div class="mt-3">
            <h6 class="fw-bold">Last Decoded Text:</h6>
            <p id="decodedOutput" class="text-primary fw-semibold mb-0">None yet</p>
            <small id="redirectHint" class="text-muted"></small>
        </div>
    </div>

    <?php else: ?>
    <!-- Equipment Details -->
    <div class="scan-container">
        <div class="text-center mb-4">
            <h4 class="fw-bold text-dark">Equipment QR Code</h4>
            <img src="<?= htmlspecialchars($filePath) ?>" alt="QR Code" class="qr-img mb-2">
            <h5 class="mb-0"><?= htmlspecialchars($equipment['property_no']) ?></h5>
            <p class="text-muted"><?= htmlspecialchars($equipment['category_name']) ?></p>
        </div>

        <table class="table table-bordered">
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

        <div class="text-center mt-4">
            <a href="side_scan.php" class="btn btn-outline-secondary me-2">🔄 Scan Again</a>
            <a href="../admin_dashboard.php?view=equipment" class="btn btn-outline-dark me-2">← Back</a>
            <a href="<?= htmlspecialchars($filePath) ?>" download="<?= htmlspecialchars($equipment['property_no']) ?>.png" class="btn btn-success me-2">⬇ Download QR</a>
            <button onclick="window.print()" class="btn btn-primary">🖨️ Print</button>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
const decodedEl = document.getElementById('decodedOutput');
const hintEl = document.getElementById('redirectHint');
let scanner = null;

function stopScannerAndRedirect(url) {
    if (scanner) {
        scanner.stop().then(() => window.location.href = url)
        .catch(() => window.location.href = url);
    } else {
        window.location.href = url;
    }
}

function onScanSuccess(decodedText) {
    decodedEl.textContent = decodedText;
    const trimmed = decodedText.trim();
    const idMatch = trimmed.match(/[?&]id=(\d+)/);
    if (idMatch) {
        const id = idMatch[1];
        const url = 'http://localhost/PEPO/generate_qr.php?id=' + encodeURIComponent(id);
        hintEl.textContent = 'Detected ID in link → Redirecting...';
        stopScannerAndRedirect(url);
        return;
    }

    if (/^\d+$/.test(trimmed)) {
        const url = 'http://localhost/PEPO/generate_qr.php?id=' + encodeURIComponent(trimmed);
        hintEl.textContent = 'Detected numeric ID → Redirecting...';
        stopScannerAndRedirect(url);
        return;
    }

    hintEl.textContent = 'Recognized QR. Please ensure it encodes a valid link or numeric ID.';
}

function onScanError(errorMessage) {}

document.addEventListener('DOMContentLoaded', () => {
    const qrReader = document.getElementById('qr-reader');
    if (!qrReader) return;
    scanner = new Html5Qrcode("qr-reader");
    const config = { fps: 10, qrbox: { width: 280, height: 280 } };
    scanner.start({ facingMode: "environment" }, config, onScanSuccess, onScanError)
        .then(() => hintEl.textContent = 'Scanner started. Point your camera at a QR code.')
        .catch(err => {
            console.error("Camera error:", err);
            hintEl.textContent = 'Unable to access camera. Please allow camera permission.';
        });
});
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.js"></script>
</body>
</html>
