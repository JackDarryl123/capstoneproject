<?php
// staff_side_scan.php - Staff Side Scanner
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if $mysqli is available
if (!isset($mysqli)) {
    error_log("side_scan.php: \$mysqli is not set!");
    die("Database connection not available. Please refresh the page.");
}

if ($mysqli->connect_errno) {
    error_log("side_scan.php: DB connection error: " . $mysqli->connect_error);
    die("Database connection failed. Please refresh the page.");
}

// Handle property_no lookup for manual entry
if (isset($_GET['lookup_property_no']) && !empty($_GET['lookup_property_no'])) {
    // 1. Safety: Clear any previous output to ensure clean JSON
    if (ob_get_length())
        ob_clean();

    // 2. Header: Tell browser this is JSON
    header('Content-Type: application/json');

    $property_no = trim($mysqli->real_escape_string($_GET['lookup_property_no']));

    // 3. CORRECT QUERY: Changed 'equipment_name' to 'description' based on your DB table
    $query = "SELECT id, property_no, description, status FROM equipment WHERE property_no = ?";
    $stmt = $mysqli->prepare($query);

    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Database Error (Prepare): ' . $mysqli->error]);
        exit();
    }


    $stmt->bind_param("s", $property_no);

    if (!$stmt->execute()) {
        echo json_encode(['success' => false, 'message' => 'Database Error (Execute): ' . $stmt->error]);
        exit();
    }

    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();

        // 4. MAPPING: We map the DB column 'description' to the JS expectation 'equipment_name'
        echo json_encode([
            'success' => true,
            'id' => $row['id'],
            'property_no' => $row['property_no'],
            'equipment_name' => $row['description'], // <--- Fixed here
            'status' => $row['status'],
            'message' => 'Equipment found',
            'redirect_url' => 'generate_qr.php?id=' . $row['id']
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Equipment with property number "' . htmlspecialchars($property_no) . '" not found'
        ]);
    }
    $stmt->close();
    exit();
}

// If ID is provided, redirect to generate_qr.php
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $requestedId = intval($_GET['id']);

    // Validate equipment exists before redirecting
    $query = "SELECT id FROM equipment WHERE id = ?";
    $stmt = $mysqli->prepare($query);
    $stmt->bind_param("i", $requestedId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        // Equipment exists, redirect to generate_qr.php
        header("Location: generate_qr.php?id=" . $requestedId);
        exit();
    }
    $stmt->close();
}
?>



<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<!-- html5-qrcode library -->
<script src="https://unpkg.com/html5-qrcode"></script>

<style>
    /* RESET AND BASE STYLES */
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        background-color: #eef2f7;
        font-family: "Segoe UI", sans-serif;
        margin: 0;
        padding: 0;
        min-height: 100vh;
    }

    .top-nav-left {
        display: flex;
        align-items: center;
        gap: 20px;
    }

    .top-nav-right {
        display: flex;
        align-items: center;
        gap: 15px;
    }

    .nav-item {
        color: #555;
        text-decoration: none;
        padding: 5px 10px;
        border-radius: 4px;
        transition: all 0.3s;
        font-size: 14px;
        white-space: nowrap;
    }

    .nav-item:hover {
        background: #f5f5f5;
        color: #3498db;
    }

    .nav-item.active {
        background: #3498db;
        color: white;
    }

    .nav-divider {
        color: #ddd;
        margin: 0 5px;
    }

    .badge {
        background: #e74c3c;
        color: white;
        padding: 2px 8px;
        border-radius: 10px;
        font-size: 12px;
        margin-left: 5px;
    }

    /* MAIN CONTENT AREA */
    .main-content {
        padding: 20px;
    }

    .staff-header {
        background: linear-gradient(135deg, #2c3e50, #4a6491);
        color: white;
        padding: 15px 25px;
        border-radius: 10px;
        margin: 0 auto 20px auto;
        max-width: 1200px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    }

    .scan-container {
        max-width: 600px;
        width: 100%;
        margin: 0 auto;
        background: white;
        padding: 30px;
        border-radius: 16px;
        box-shadow: 0 6px 20px rgba(0, 0, 0, 0.1);
    }

    #qr-reader {
        width: 100%;
        min-height: 300px;
        border: 2px solid #3498db;
        border-radius: 12px;
        overflow: hidden;
        margin: 20px 0;
        background: #f8f9fa;
        position: relative;
    }

    #qr-reader video {
        width: 100% !important;
        height: auto !important;
        border-radius: 10px;
    }

    .status-box {
        background: #f8f9fa;
        border-left: 4px solid #3498db;
        padding: 15px;
        border-radius: 8px;
        margin-top: 20px;
    }

    .action-buttons {
        display: flex;
        gap: 10px;
        margin-top: 20px;
        justify-content: center;
        flex-wrap: wrap;
    }

    .scan-btn {
        padding: 10px 20px;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        font-weight: 600;
        transition: all 0.3s;
        font-size: 14px;
    }

    .btn-primary {
        background: #3498db;
        color: white;
    }

    .btn-primary:hover {
        background: #2980b9;
    }

    .btn-secondary {
        background: #7f8c8d;
        color: white;
    }

    .btn-secondary:hover {
        background: #6c7b7d;
    }

    .btn-danger {
        background: #e74c3c;
        color: white;
    }

    .btn-danger:hover {
        background: #c0392b;
    }

    .debug-panel {
        background: #2c3e50;
        color: white;
        padding: 15px;
        border-radius: 8px;
        margin-top: 20px;
        font-family: monospace;
        font-size: 12px;
        display: none;
    }

    .debug-toggle {
        position: fixed;
        bottom: 20px;
        right: 20px;
        background: #2c3e50;
        color: white;
        border: none;
        border-radius: 50%;
        width: 40px;
        height: 40px;
        cursor: pointer;
        z-index: 1000;
    }

    #decodedOutput {
        font-family: monospace;
        background: #2d3436;
        color: #00cec9;
        padding: 10px;
        border-radius: 6px;
        word-break: break-all;
        min-height: 20px;
    }

    #redirectHint {
        color: #e74c3c;
        font-style: italic;
    }

    .scanning-status {
        text-align: center;
        color: #3498db;
        font-weight: 600;
        margin: 10px 0;
    }

    .scanning-status.active {
        color: #27ae60;
    }

    .scanning-status.error {
        color: #e74c3c;
    }

    .permission-hint {
        text-align: center;
        margin: 15px 0;
    }

    /* Modal styles for manual entry */
    .modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.7);
        display: none;
        justify-content: center;
        align-items: center;
        z-index: 2000;
    }

    .modal-content {
        background: white;
        padding: 25px;
        border-radius: 12px;
        width: 90%;
        max-width: 400px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
    }

    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        padding-bottom: 15px;
        border-bottom: 1px solid #eee;
    }

    .modal-title {
        font-size: 20px;
        color: #2c3e50;
        font-weight: 600;
    }

    .modal-close {
        background: none;
        border: none;
        font-size: 24px;
        cursor: pointer;
        color: #7f8c8d;
        line-height: 1;
    }

    .modal-close:hover {
        color: #e74c3c;
    }

    .form-group {
        margin-bottom: 20px;
    }

    .form-label {
        display: block;
        margin-bottom: 8px;
        font-weight: 500;
        color: #2c3e50;
    }

    .form-control {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid #ddd;
        border-radius: 6px;
        font-size: 16px;
        transition: border-color 0.3s;
    }

    .form-control:focus {
        outline: none;
        border-color: #3498db;
        box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
    }

    .modal-actions {
        display: flex;
        gap: 10px;
        justify-content: flex-end;
        margin-top: 25px;
    }

    .btn-success {
        background: #27ae60;
        color: white;
    }

    .btn-success:hover {
        background: #219653;
    }

    /* Loading indicator */
    .loading-spinner {
        display: none;
        text-align: center;
        margin: 10px 0;
    }

    .spinner {
        width: 20px;
        height: 20px;
        border: 3px solid #f3f3f3;
        border-top: 3px solid #3498db;
        border-radius: 50%;
        animation: spin 1s linear infinite;
        display: inline-block;
        margin-right: 10px;
        vertical-align: middle;
    }

    @keyframes spin {
        0% {
            transform: rotate(0deg);
        }

        100% {
            transform: rotate(360deg);
        }
    }

    /* Success message box */
    .success-box {
        background: #d4edda;
        border: 1px solid #c3e6cb;
        color: #155724;
        padding: 15px;
        border-radius: 8px;
        margin: 15px 0;
        display: none;
    }

    /* Equipment info preview */
    .equipment-preview {
        background: #f8f9fa;
        border: 1px solid #dee2e6;
        border-radius: 8px;
        padding: 15px;
        margin: 15px 0;
        display: none;
    }

    .equipment-preview h5 {
        color: #2c3e50;
        margin-bottom: 10px;
    }

    .equipment-preview p {
        margin: 5px 0;
    }

    /* Redirect countdown */
    .redirect-countdown {
        background: #e3f2fd;
        border: 1px solid #bbdefb;
        color: #1565c0;
        padding: 10px;
        border-radius: 8px;
        margin: 10px 0;
        text-align: center;
        display: none;
    }

    /* Responsive adjustments */
    @media (max-width: 768px) {
        .top-navigation {
            padding: 10px;
            height: auto;
            min-height: 60px;
        }

        .top-nav-left,
        .top-nav-right {
            gap: 10px;
        }

        .nav-item {
            padding: 5px 8px;
            font-size: 12px;
        }

        .main-content {
            padding: 10px;
        }

        .scan-container {
            padding: 20px;
        }

        .modal-content {
            width: 95%;
            margin: 10px;
        }

        .action-buttons {
            flex-direction: column;
        }

        .scan-btn {
            width: 100%;
        }
    }

    /* Troubleshooting Panel */
    .troubleshooting-panel {
        text-align: left;
    }

    .troubleshooting-panel .alert {
        border-radius: 8px;
    }

    /* Modal Equipment Preview - Fixed Description Overflow */
    #modalEquipmentPreview {
        max-height: 200px;
        overflow-y: auto;
    }

    #modalEquipmentPreview p {
        word-wrap: break-word;
        overflow-wrap: break-word;
    }

    #modalPreviewEquipmentName {
        display: block;
        max-width: 100%;
        word-break: break-word;
    }

    /* Equipment Preview - Fixed Description Overflow */
    #equipmentPreview {
        max-height: 200px;
        overflow-y: auto;
    }

    #equipmentPreview p {
        word-wrap: break-word;
        overflow-wrap: break-word;
    }

    #previewEquipmentName {
        display: block;
        max-width: 100%;
        word-break: break-word;
    }
</style>

<!-- Main Content -->
<div class="main-content">
    <!-- <div class="staff-header text-center">
        <h2><i class="fas fa-camera"></i> Equipment QR Code Scanner</h2>
        <p class="mb-0">Scan equipment QR codes to view detailed information</p>
    </div> -->

    <!-- QR Scanner Interface for Staff -->
    <div class="scan-container text-center">
        <h4 class="mb-4">📱 Point camera at equipment QR code</h4>

        <!-- Camera Permission Hint -->
        <div class="permission-hint" id="permissionHint">
            <p class="text-muted">Camera permission required for scanning</p>
            <button onclick="requestCameraPermission()" class="scan-btn btn-primary">
                <i class="fas fa-camera"></i> Enable Camera Access
            </button>
        </div>

        <!-- Scanning Status -->
        <div class="scanning-status" id="scanningStatus">
            Scanner initializing...
        </div>

        <!-- QR Reader Container -->
        <div id="qr-reader"></div>

        <!-- Camera Selection -->
        <div id="cameraSelection" style="display: none;">
            <label for="cameraSelect" class="form-label">Select Camera:</label>
            <select id="cameraSelect" class="form-control mt-2" onchange="changeCamera(this.value)">
                <option value="">Loading cameras...</option>
            </select>
        </div>

        <div class="status-box">
            <p class="mb-2"><strong>Scanned Result:</strong></p>
            <div id="decodedOutput">No QR code scanned yet</div>
            <div id="redirectHint" class="text-muted"></div>
        </div>

        <!-- Equipment Preview (for manual entry) -->
        <div class="equipment-preview" id="equipmentPreview">
            <h5><i class="fas fa-info-circle"></i> Equipment Found</h5>
            <p><strong>Property No:</strong> <span id="previewPropertyNo"></span></p>
            <p><strong>Name:</strong> <span id="previewEquipmentName"></span></p>
            <p><strong>Status:</strong> <span id="previewStatus"></span></p>
            <button onclick="redirectToEquipment()" class="scan-btn btn-success" style="margin-top: 10px;">
                <i class="fas fa-external-link-alt"></i> View Equipment Details
            </button>
        </div>

        <!-- Redirect Countdown -->
        <div class="redirect-countdown" id="redirectCountdown">
            <i class="fas fa-spinner fa-spin"></i> Redirecting in <span id="countdownTimer">3</span> seconds...
        </div>

        <div class="action-buttons">
            <button onclick="switchCamera()" class="scan-btn btn-secondary" id="switchCameraBtn" style="display: none;">
                <i class="fas fa-sync-alt"></i> Switch Camera
            </button>
            <button onclick="toggleScanner()" class="scan-btn btn-secondary" id="toggleScannerBtn">
                <i class="fas fa-pause"></i> Pause Scanner
            </button>
            <button onclick="showManualEntryModal()" class="scan-btn btn-primary" id="manualEntryBtn">
                <i class="fas fa-keyboard"></i> Manual Entry
            </button>
            <button onclick="restartScanner()" class="scan-btn btn-danger">
                <i class="fas fa-redo"></i> Restart Scanner
            </button>
        </div>

        <!-- Troubleshooting Panel -->
        <div class="troubleshooting-panel" id="troubleshootingPanel" style="display: none;">
            <div class="alert alert-warning mt-3 mb-2">
                <h6 class="mb-2"><i class="fas fa-exclamation-triangle"></i> Scanner Not Working?</h6>
                <div class="small">
                    <p class="mb-1"><strong>Common issues and fixes:</strong></p>
                    <ul class="mb-2 ps-3">
                        <li><strong>Camera not showing:</strong> Make sure no other app is using the camera</li>
                        <li><strong>Permission denied:</strong> Click the camera icon in browser address bar and allow access</li>
                        <li><strong>Using HTTPS:</strong> Camera only works on secure connections (or localhost)</li>
                        <li><strong>Wrong browser:</strong> Try Chrome, Firefox, or Edge</li>
                    </ul>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <button onclick="restartScanner()" class="btn btn-sm btn-outline-warning">
                        <i class="fas fa-redo"></i> Restart Scanner
                    </button>
                    <button onclick="showManualEntryModal()" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-keyboard"></i> Use Manual Entry
                    </button>
                    <button onclick="toggleTroubleshooting()" class="btn btn-sm btn-link">Hide this</button>
                </div>
            </div>
        </div>

        <!-- Debug Panel -->
        <div class="debug-panel" id="debugPanel">
            <h6>Debug Information:</h6>
            <div id="debugInfo"></div>
        </div>

        <!-- Instructions -->
        <div class="mt-4 p-3 bg-light rounded">
            <h6><i class="fas fa-info-circle"></i> How to use:</h6>
            <ul class="text-start small">
                <li>Point camera at equipment QR code</li>
                <li>QR code should contain equipment ID or URL with ID parameter</li>
                <li>Scan will automatically redirect to equipment details page</li>
                <li>Use "Manual Entry" if QR code cannot be scanned</li>
            </ul>
        </div>
    </div>
</div>

<!-- Manual Entry Modal -->
<div class="modal-overlay" id="manualEntryModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fas fa-keyboard"></i> Manual Equipment Entry</h3>
            <button class="modal-close" onclick="hideManualEntryModal()">&times;</button>
        </div>

        <div class="modal-body">
            <div class="form-group">
                <label for="propertyNoInput" class="form-label">Equipment Property Number:</label>
                <input type="text" id="propertyNoInput" class="form-control"
                    placeholder="Enter equipment property number" autocomplete="off">
                <small class="text-muted">Enter the exact property number of the equipment</small>
            </div>

            <!-- Equipment Preview in Modal -->
            <div class="equipment-preview" id="modalEquipmentPreview" style="display: none;">
                <h5><i class="fas fa-check-circle text-success"></i> Equipment Found</h5>
                <p><strong>Property No:</strong> <span id="modalPreviewPropertyNo"></span></p>
                 <p><strong>Status:</strong> <span id="modalPreviewStatus"></span></p>
                <p><strong>Description:</strong> <span id="modalPreviewEquipmentName"></span></p>
            </div>

            <div class="loading-spinner" id="manualEntryLoading">
                <div class="spinner"></div>
                <span>Looking up equipment...</span>
            </div>

            <div id="manualEntryError" class="text-danger" style="display: none; margin: 10px 0;"></div>

            <!-- Success message -->
            <div class="success-box" id="manualEntrySuccess" style="display: none;">
                <i class="fas fa-check-circle"></i> Equipment found! Redirecting...
            </div>
        </div>

        <div class="modal-actions">
            <button onclick="hideManualEntryModal()" class="scan-btn btn-secondary">
                Cancel
            </button>
            <button onclick="submitManualEntry()" class="scan-btn btn-success" id="submitManualBtn">
                <i class="fas fa-search"></i> Lookup Equipment
            </button>
            <button onclick="redirectFromModal()" class="scan-btn btn-primary" id="redirectFromModalBtn"
                style="display: none;">
                <i class="fas fa-external-link-alt"></i> View Details
            </button>
        </div>
    </div>
</div>

<!-- Debug Toggle Button -->
<button class="debug-toggle" onclick="toggleDebug()">🐛</button>

<script>
    // Global variables for scanner
    let scanner = null;
    let isScannerActive = false;
    let cameras = [];
    let currentCameraId = null;
    let debugMode = false;
    let currentEquipmentId = null;

    // DOM Elements
    const decodedEl = document.getElementById('decodedOutput');
    const hintEl = document.getElementById('redirectHint');
    const scanningStatus = document.getElementById('scanningStatus');
    const debugPanel = document.getElementById('debugPanel');
    const debugInfo = document.getElementById('debugInfo');
    const permissionHint = document.getElementById('permissionHint');
    const cameraSelect = document.getElementById('cameraSelect');
    const manualEntryModal = document.getElementById('manualEntryModal');
    const propertyNoInput = document.getElementById('propertyNoInput');
    const manualEntryLoading = document.getElementById('manualEntryLoading');
    const manualEntryError = document.getElementById('manualEntryError');
    const manualEntrySuccess = document.getElementById('manualEntrySuccess');
    const equipmentPreview = document.getElementById('equipmentPreview');
    const modalEquipmentPreview = document.getElementById('modalEquipmentPreview');
    const submitManualBtn = document.getElementById('submitManualBtn');
    const redirectFromModalBtn = document.getElementById('redirectFromModalBtn');
    const redirectCountdown = document.getElementById('redirectCountdown');
    const countdownTimer = document.getElementById('countdownTimer');

    // Debug logging
    function debugLog(message, type = 'info') {
        if (!debugMode) return;

        const timestamp = new Date().toLocaleTimeString();
        const logEntry = document.createElement('div');
        logEntry.className = `debug-${type}`;
        logEntry.innerHTML = `[${timestamp}] ${message}`;
        debugInfo.appendChild(logEntry);
        debugInfo.scrollTop = debugInfo.scrollHeight;

        console.log(`[Scanner] ${message}`);
    }

    // Toggle debug panel
    function toggleDebug() {
        debugMode = !debugMode;
        debugPanel.style.display = debugMode ? 'block' : 'none';
        debugLog('Debug mode ' + (debugMode ? 'enabled' : 'disabled'));
    }

    // Manual Entry Modal Functions
    function showManualEntryModal() {
        debugLog('Opening manual entry modal');
        manualEntryModal.style.display = 'flex';
        propertyNoInput.value = '';
        manualEntryError.style.display = 'none';
        manualEntryError.textContent = '';
        manualEntrySuccess.style.display = 'none';
        modalEquipmentPreview.style.display = 'none';
        submitManualBtn.style.display = 'inline-block';
        redirectFromModalBtn.style.display = 'none';
        propertyNoInput.focus();
    }

    function hideManualEntryModal() {
        debugLog('Closing manual entry modal');
        manualEntryModal.style.display = 'none';
        manualEntryError.style.display = 'none';
        manualEntryLoading.style.display = 'none';
        manualEntrySuccess.style.display = 'none';
    }

    // Show equipment preview
    function showEquipmentPreview(data) {
        document.getElementById('previewPropertyNo').textContent = data.property_no;
        document.getElementById('previewEquipmentName').textContent = data.equipment_name;
        document.getElementById('previewStatus').textContent = data.status;

        // Add status color
        const statusElement = document.getElementById('previewStatus');
        if (data.status === 'Available') {
            statusElement.style.color = '#27ae60';
        } else if (data.status === 'In Use') {
            statusElement.style.color = '#e67e22';
        } else if (data.status === 'Maintenance') {
            statusElement.style.color = '#e74c3c';
        }

        equipmentPreview.style.display = 'block';
        currentEquipmentId = data.id;
    }

    // Show equipment preview in modal
    function showModalEquipmentPreview(data) {
        document.getElementById('modalPreviewPropertyNo').textContent = data.property_no;
        document.getElementById('modalPreviewEquipmentName').textContent = data.equipment_name;
        document.getElementById('modalPreviewStatus').textContent = data.status;

        // Add status color
        const statusElement = document.getElementById('modalPreviewStatus');
        if (data.status === 'Available') {
            statusElement.style.color = '#27ae60';
        } else if (data.status === 'In Use') {
            statusElement.style.color = '#e67e22';
        } else if (data.status === 'Maintenance') {
            statusElement.style.color = '#e74c3c';
        }

        modalEquipmentPreview.style.display = 'block';
        currentEquipmentId = data.id;
    }

    // Start redirect countdown
    function startRedirectCountdown(redirectUrl) {
        redirectCountdown.style.display = 'block';
        let countdown = 3;

        const countdownInterval = setInterval(() => {
            countdownTimer.textContent = countdown;
            countdown--;

            if (countdown < 0) {
                clearInterval(countdownInterval);
                debugLog(`Redirecting to: ${redirectUrl}`);
                window.location.href = redirectUrl;
            }
        }, 1000);
    }

    // Submit manual entry - FIXED VERSION
    async function submitManualEntry() {
        const propertyNo = propertyNoInput.value.trim();

        if (!propertyNo) {
            showManualEntryError('Please enter a property number');
            return;
        }

        // Show loading
        manualEntryLoading.style.display = 'block';
        manualEntryError.style.display = 'none';
        manualEntryError.textContent = '';
        manualEntrySuccess.style.display = 'none';
        modalEquipmentPreview.style.display = 'none';

        try {
            debugLog(`Looking up equipment with property_no: ${propertyNo}`);

            // Send AJAX request to look up equipment by property_no
            // We're calling side_scan.php which returns the equipment details including redirect_url
            const response = await fetch(`side_scan.php?lookup_property_no=${encodeURIComponent(propertyNo)}`);

            // Check if response is JSON
            const contentType = response.headers.get("content-type");
            if (contentType && contentType.includes("application/json")) {
                const data = await response.json();

                if (data.success) {
                    debugLog(`Equipment found with ID: ${data.id}, Redirect URL: ${data.redirect_url}`);

                    // Show success message
                    manualEntrySuccess.style.display = 'block';
                    manualEntryLoading.style.display = 'none';

                    // Show equipment preview in modal
                    showModalEquipmentPreview(data);

                    // Update buttons
                    submitManualBtn.style.display = 'none';
                    redirectFromModalBtn.style.display = 'inline-block';

                    // Store equipment ID and redirect URL
                    currentEquipmentId = data.id;
                    currentRedirectUrl = data.redirect_url || `generate_qr.php?id=${data.id}`;

                    debugLog(`Equipment details loaded: ${data.equipment_name}`);

                } else {
                    showManualEntryError(data.message || 'Equipment not found');
                    debugLog(`Equipment not found: ${propertyNo}`, 'error');
                }
            } else {
                // If not JSON, something went wrong
                const text = await response.text();
                debugLog('Unexpected response: ' + text.substring(0, 200), 'error');
                showManualEntryError('Server returned unexpected response. Please try again.');
            }

        } catch (error) {
            debugLog('Error in manual entry lookup: ' + error.message, 'error');
            showManualEntryError('Error looking up equipment. Please try again.');
        } finally {
            manualEntryLoading.style.display = 'none';
        }
    }

    // Redirect from modal
    function redirectFromModal() {
        if (currentEquipmentId) {
            const redirectUrl = `generate_qr.php?id=${currentEquipmentId}`;
            debugLog(`Redirecting to: ${redirectUrl}`);

            // Show success message in modal
            manualEntrySuccess.style.display = 'block';
            manualEntrySuccess.innerHTML = '<i class="fas fa-check-circle"></i> Redirecting to equipment details...';

            // Start countdown before redirect
            setTimeout(() => {
                window.location.href = redirectUrl;
            }, 1000);
        }
    }

    // Redirect to equipment details
    function redirectToEquipment() {
        if (currentEquipmentId) {
            const redirectUrl = `generate_qr.php?id=${currentEquipmentId}`;
            debugLog(`Redirecting to: ${redirectUrl}`);
            startRedirectCountdown(redirectUrl);
        }
    }

    function showManualEntryError(message) {
        manualEntryError.textContent = message;
        manualEntryError.style.display = 'block';
        propertyNoInput.focus();
    }

    // Request camera permission explicitly
    async function requestCameraPermission() {
        try {
            debugLog('Requesting camera permission...');
            const stream = await navigator.mediaDevices.getUserMedia({
                video: {
                    facingMode: 'environment'
                }
            });
            stream.getTracks().forEach(track => track.stop());
            debugLog('Camera permission granted');
            permissionHint.style.display = 'none';
            initScanner();
        } catch (error) {
            debugLog('Camera permission denied: ' + error.message, 'error');
            scanningStatus.textContent = 'Camera permission denied';
            scanningStatus.className = 'scanning-status error';
            permissionHint.style.display = 'block';
        }
    }

    // Get available cameras
    async function getCameras() {
        try {
            debugLog('Getting available cameras...');
            const devices = await Html5Qrcode.getCameras();
            cameras = devices;
            debugLog(`Found ${devices.length} camera(s)`);

            // Update camera select dropdown
            cameraSelect.innerHTML = '';
            devices.forEach((device, index) => {
                const option = document.createElement('option');
                option.value = device.id;
                option.text = device.label || `Camera ${index + 1}`;
                cameraSelect.appendChild(option);
                debugLog(`Camera ${index + 1}: ${device.label || 'Unlabeled'} (${device.id})`);
            });

            if (devices.length > 0) {
                document.getElementById('cameraSelection').style.display = 'block';
                document.getElementById('switchCameraBtn').style.display = 'inline-block';
                return devices;
            } else {
                debugLog('No cameras found', 'error');
                return [];
            }
        } catch (error) {
            debugLog('Error getting cameras: ' + error.message, 'error');
            return [];
        }
    }

    // Initialize scanner
    async function initScanner() {
        try {
            debugLog('Initializing scanner...');
            document.getElementById('troubleshootingPanel').style.display = 'none';

            // Clean up previous scanner if exists
            if (scanner && isScannerActive) {
                await scanner.stop();
            }

            // Create new scanner instance
            scanner = new Html5Qrcode("qr-reader");

            // Get cameras
            await getCameras();

            // Start with first camera or environment
            if (cameras.length > 0) {
                currentCameraId = cameras[0].id;
                await startScanner(currentCameraId);
            } else {
                // Try environment camera as fallback
                await startScanner();
            }

        } catch (error) {
            debugLog('Scanner initialization failed: ' + error.message, 'error');
            scanningStatus.textContent = '❌ Scanner failed to initialize. See troubleshooting below.';
            scanningStatus.className = 'scanning-status error';
            showTroubleshooting();
        }
    }

    // Show troubleshooting panel
    function showTroubleshooting() {
        const panel = document.getElementById('troubleshootingPanel');
        if (panel) {
            panel.style.display = 'block';
            panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }

    // Toggle troubleshooting panel
    function toggleTroubleshooting() {
        const panel = document.getElementById('troubleshootingPanel');
        if (panel) {
            panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
        }
    }

    // Export functions to window for global access
    window.initScanner = initScanner;
    window.restartScanner = restartScanner;
    window.showManualEntryModal = showManualEntryModal;
    window.hideManualEntryModal = hideManualEntryModal;

    // Start scanner with specific camera
    async function startScanner(cameraId = null) {
        const config = {
            fps: 10,
            qrbox: {
                width: 250,
                height: 250
            },
            aspectRatio: 1.0,
            showTorchButtonIfSupported: true,
            showZoomSliderIfSupported: true,
            defaultZoomValueIfSupported: 2
        };

        try {
            scanningStatus.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Starting scanner...';

            if (cameraId) {
                debugLog(`Starting scanner with camera: ${cameraId}`);
                await scanner.start(cameraId, config, onScanSuccess, onScanError);
            } else {
                debugLog('Starting scanner with environment camera');
                await scanner.start({
                    facingMode: "environment"
                }, config, onScanSuccess, onScanError);
            }

            isScannerActive = true;
            scanningStatus.innerHTML = '✅ Scanner active - Point at QR code';
            scanningStatus.className = 'scanning-status active';
            hintEl.textContent = 'Scanner started successfully';
            hintEl.style.color = '#27ae60';
            permissionHint.style.display = 'none';
            document.getElementById('troubleshootingPanel').style.display = 'none';

            debugLog('Scanner started successfully');

        } catch (error) {
            debugLog('Failed to start scanner: ' + error.message, 'error');
            
            let errorMessage = '❌ Failed to start scanner';
            let showPermissionHint = false;
            
            if (error.name === 'NotAllowedError' || error.message.includes('permission')) {
                errorMessage = '🚫 Camera permission denied. Please allow camera access in your browser settings.';
                showPermissionHint = true;
            } else if (error.name === 'NotFoundError' || error.message.includes('NotFound')) {
                errorMessage = '📷 No camera found. Please connect a camera or use Manual Entry.';
            } else if (error.name === 'NotReadableError' || error.message.includes('in use')) {
                errorMessage = '📷 Camera is in use by another application. Close other apps using the camera.';
            } else if (error.message.includes('HTTPS')) {
                errorMessage = '🔒 Camera requires secure connection (HTTPS). This works on localhost.';
            }
            
            scanningStatus.innerHTML = errorMessage;
            scanningStatus.className = 'scanning-status error';
            hintEl.textContent = error.message;
            hintEl.style.color = '#e74c3c';
            
            if (showPermissionHint) {
                permissionHint.innerHTML = `
                    <p class="text-warning mb-2"><strong>Camera Permission Required!</strong></p>
                    <p class="text-muted small mb-2">Click the camera icon in your browser's address bar and allow camera access.</p>
                    <button onclick="restartScanner()" class="scan-btn btn-primary">
                        <i class="fas fa-redo"></i> Try Again
                    </button>
                `;
                permissionHint.style.display = 'block';
            }
            
            showTroubleshooting();
        }
    }

    // Stop scanner
    async function stopScanner() {
        if (scanner && isScannerActive) {
            try {
                await scanner.stop();
                isScannerActive = false;
                scanningStatus.textContent = 'Scanner paused';
                scanningStatus.className = 'scanning-status';
                debugLog('Scanner stopped');
            } catch (error) {
                debugLog('Error stopping scanner: ' + error.message, 'error');
            }
        }
    }

    // Toggle scanner on/off
    function toggleScanner() {
        const toggleBtn = document.getElementById('toggleScannerBtn');
        if (isScannerActive) {
            stopScanner();
            toggleBtn.innerHTML = '<i class="fas fa-play"></i> Resume Scanner';
        } else {
            startScanner(currentCameraId);
            toggleBtn.innerHTML = '<i class="fas fa-pause"></i> Pause Scanner';
        }
    }

    // Switch camera
    async function switchCamera() {
        if (cameras.length < 2) {
            debugLog('Only one camera available', 'warning');
            alert("Only one camera available");
            return;
        }

        try {
            debugLog('Switching camera...');
            await stopScanner();

            // Find next camera
            let currentIndex = 0;
            if (currentCameraId) {
                currentIndex = cameras.findIndex(cam => cam.id === currentCameraId);
            }
            const nextIndex = (currentIndex + 1) % cameras.length;
            currentCameraId = cameras[nextIndex].id;

            debugLog(`Switching to camera ${nextIndex + 1}: ${cameras[nextIndex].label}`);

            // Update dropdown
            cameraSelect.value = currentCameraId;

            // Start with new camera
            await startScanner(currentCameraId);

            hintEl.textContent =
                `Switched to ${cameras[nextIndex].label || 'Camera ' + (nextIndex + 1)}`;

        } catch (error) {
            debugLog('Error switching camera: ' + error.message, 'error');
            alert('Error switching camera: ' + error.message);
        }
    }

    // Change camera from dropdown
    async function changeCamera(cameraId) {
        if (!cameraId) return;

        try {
            await stopScanner();
            currentCameraId = cameraId;
            await startScanner(cameraId);
            debugLog(`Changed to camera: ${cameraId}`);
        } catch (error) {
            debugLog('Error changing camera: ' + error.message, 'error');
        }
    }

    // Restart scanner
    async function restartScanner() {
        debugLog('Restarting scanner...');
        await stopScanner();
        await initScanner();
    }

    // Scan success callback
    async function onScanSuccess(decodedText) {
        debugLog('QR Code detected: ' + decodedText);
        decodedEl.textContent = decodedText;

        const trimmed = decodedText.trim();
        let equipmentId = null;
        let redirectUrl = null;

        // Parse the decoded text to extract equipment ID
        // Case 1: Full URL like http://localhost/PEPO/staff/generate_qr.php?id=123
        const urlMatch = trimmed.match(/generate_qr\.php\?id=(\d+)/i);
        if (urlMatch) {
            equipmentId = urlMatch[1];
            redirectUrl = 'generate_qr.php?id=' + equipmentId;
        }
        // Case 2: Just the ID number
        else if (/^\d+$/.test(trimmed)) {
            equipmentId = trimmed;
            redirectUrl = 'generate_qr.php?id=' + equipmentId;
        }
        // Case 3: URL with ID parameter (any format)
        else {
            const idMatch = trimmed.match(/[?&]id=(\d+)/);
            if (idMatch) {
                equipmentId = idMatch[1];
                redirectUrl = 'generate_qr.php?id=' + equipmentId;
            }
        }

        if (equipmentId && redirectUrl) {
            hintEl.innerHTML = '<span style="color: #27ae60;">✅ Equipment found!</span>';

            // Start redirect countdown
            startRedirectCountdown(redirectUrl);
        } else {
            hintEl.textContent = 'Invalid QR code format. Expected equipment ID or generate_qr.php URL.';
            hintEl.style.color = '#e74c3c';
            debugLog('Invalid QR code format: ' + decodedText, 'warning');
            setTimeout(() => {
                hintEl.textContent = '';
            }, 3000);
        }
    }

    // Scan error callback
    function onScanError(errorMessage) {
        debugLog('Scan error: ' + errorMessage, 'error');
        // Don't show every error to user to avoid spam
    }

    // Close modal when clicking outside
    manualEntryModal.addEventListener('click', (event) => {
        if (event.target === manualEntryModal) {
            hideManualEntryModal();
        }
    });

    // Handle Enter key in manual entry
    propertyNoInput.addEventListener('keypress', (event) => {
        if (event.key === 'Enter') {
            submitManualEntry();
        }
    });

    // Initialize scanner when page loads
    document.addEventListener('DOMContentLoaded', async () => {
        // Check if html5-qrcode library is loaded
        if (typeof Html5Qrcode === 'undefined') {
            console.error('html5-qrcode library not loaded');
            scanningStatus.innerHTML = '<i class="fas fa-exclamation-circle"></i> Scanner library failed to load. Check your internet connection.';
            scanningStatus.className = 'scanning-status error';
            permissionHint.innerHTML = `
                <p class="text-danger mb-2"><strong>Scanner library not loaded!</strong></p>
                <p class="text-muted small mb-2">Please check your internet connection and refresh the page.</p>
                <button onclick="location.reload()" class="scan-btn btn-primary">
                    <i class="fas fa-redo"></i> Refresh Page
                </button>
            `;
            permissionHint.style.display = 'block';
            showTroubleshooting();
            return;
        }

        debugLog('Scanner content loaded, initializing...');

        // Check for camera support
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            debugLog('Browser does not support camera access', 'error');
            scanningStatus.innerHTML = '<i class="fas fa-times-circle"></i> Your browser does not support camera access';
            scanningStatus.className = 'scanning-status error';
            permissionHint.innerHTML = `
                <p class="text-danger mb-2"><strong>Browser Not Supported!</strong></p>
                <p class="text-muted mb-2">Please use a modern browser like Chrome, Firefox, or Edge.</p>
                <button onclick="showManualEntryModal()" class="scan-btn btn-primary">
                    <i class="fas fa-keyboard"></i> Use Manual Entry Instead
                </button>
            `;
            permissionHint.style.display = 'block';
            showTroubleshooting();
            return;
        }

        // Initialize scanner
        await initScanner();
    });

    // Clean up on page unload (when navigating away)
    window.addEventListener('beforeunload', () => {
        if (scanner && isScannerActive) {
            scanner.stop().catch(() => { });
        }
    });
</script>