<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/session_helper.php';
require_once __DIR__ . '/../includes/mail_config.php';
start_user_session();

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'staff') {
    header('Location: ../index.php?login');
    exit();
}

// Get equipment ID
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    error_log("[" . date('Y-m-d H:i:s') . "] Invalid equipment ID in generate_qr.php");
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
    error_log("[" . date('Y-m-d H:i:s') . "] Equipment not found with ID: " . $id);
    die("Equipment not found.");
}
$equipment = $result->fetch_assoc();
$stmt->close();

// Base URL configuration
$baseUrl = BASE_URL;

// Try multiple paths for QR code library
$possiblePaths = [
    __DIR__ . '/phpqrcode/qrlib.php',
    __DIR__ . '/../phpqrcode/qrlib.php',
    __DIR__ . '/../../phpqrcode/qrlib.php',
    'C:/xampp/htdocs/phpqrcode/qrlib.php',
    'C:/xampp/phpqrcode/qrlib.php',
];

$qrLibraryFound = false;
foreach ($possiblePaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $qrLibraryFound = true;
        break;
    }
}

$qrFileGenerated = false;
$serverPath = '';
$webPath = '';

$tempDir = "temp_qr/";
$tempUrl = "temp_qr/";  // adjust if your temp folder is not directly accessible
if (!file_exists($tempDir)) {
    mkdir($tempDir, 0755, true);
}

$fileName = "equipment_{$equipment['id']}.png";
$serverPath = $tempDir . $fileName;
$webPath = $tempUrl . $fileName;

if (!$qrLibraryFound) {
    // No QR library found - show error message
    error_log("[" . date('Y-m-d H:i:s') . "] QR library not found in generate_qr.php");
} else {
    // QR content - point to a view page
    $qrData = $baseUrl . "/view_equipment.php?id={$equipment['id']}";

    // Generate QR code using phpqrcode library
    QRcode::png($qrData, $serverPath, QR_ECLEVEL_L, 5);
    $qrFileGenerated = true;
}

// If QR generation failed, use a placeholder
if (!$qrFileGenerated || !file_exists($serverPath)) {
    $serverPath = $tempDir . "placeholder.png";
    $webPath = $tempUrl . "placeholder.png";
    if (!file_exists($serverPath)) {
        // Create a simple placeholder image
        $placeholder = imagecreatetruecolor(250, 250);
        $white = imagecolorallocate($placeholder, 255, 255, 255);
        $gray = imagecolorallocate($placeholder, 200, 200, 200);
        $black = imagecolorallocate($placeholder, 0, 0, 0);

        imagefill($placeholder, 0, 0, $white);
        imagerectangle($placeholder, 10, 10, 240, 240, $gray);
        imagestring($placeholder, 5, 75, 115, "QR CODE", $black);
        imagestring($placeholder, 3, 50, 140, "NOT AVAILABLE", $black);

        imagepng($placeholder, $serverPath);
        imagedestroy($placeholder);
    }
}

// ========== COMPOSITE IMAGE FOR DOWNLOAD (with text) ==========
$downloadServerPath = $serverPath;   // fallback to original
$downloadWebPath = $webPath;

if ($qrFileGenerated && file_exists($serverPath) && strpos($serverPath, 'placeholder.png') === false) {
    if (function_exists('imagecreatefrompng')) {
        $propertyNo = $equipment['property_no'];
        $category = $equipment['category_name'];

        $downloadFileName = "qr_{$propertyNo}_with_text.png";
        $downloadServerPath = $tempDir . $downloadFileName;
        $downloadWebPath = $tempUrl . $downloadFileName;

        $qrImg = imagecreatefrompng($serverPath);
        if ($qrImg !== false) {
            $qrWidth = imagesx($qrImg);
            $qrHeight = imagesy($qrImg);

            $textHeight = 50; // extra space for two lines of text
            $newHeight = $qrHeight + $textHeight;

            $composite = imagecreatetruecolor($qrWidth, $newHeight);
            $white = imagecolorallocate($composite, 255, 255, 255);
            $black = imagecolorallocate($composite, 0, 0, 0);

            imagefilledrectangle($composite, 0, 0, $qrWidth, $newHeight, $white);
            imagecopy($composite, $qrImg, 0, 0, 0, 0, $qrWidth, $qrHeight);

            // Use built‑in GD font (size 5)
            $fontSize = 5;
            $textY = $qrHeight + 8;

            // Center property number
            $textWidth = strlen($propertyNo) * imagefontwidth($fontSize);
            $x = (int) (($qrWidth - $textWidth) / 2);
            imagestring($composite, $fontSize, $x, $textY, $propertyNo, $black);

            // Center category
            $textWidth = strlen($category) * imagefontwidth($fontSize);
            $x = (int) (($qrWidth - $textWidth) / 2);
            imagestring($composite, $fontSize, $x, $textY + imagefontheight($fontSize) + 4, $category, $black);

            imagepng($composite, $downloadServerPath);
            imagedestroy($qrImg);
            imagedestroy($composite);
        }
    }
}
// ===============================================================
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QR Code - <?= htmlspecialchars($equipment['property_no']) ?></title>
    <!-- Tailwind CSS via CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <!-- Tiny print styles (unchanged) -->
    <style>
        @media print {
            body * {
                visibility: hidden;
            }

            .print-section,
            .print-section * {
                visibility: visible;
            }

            .print-section {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                height: 100%;
                display: flex;
                align-items: center;
                justify-content: center;
                background: white;
            }

            .no-print {
                display: none !important;
            }
        }

        .print-section {
            display: none;
        }
    </style>
</head>

<body class="bg-gradient-to-br from-slate-100 to-slate-300 min-h-screen p-4 md:p-6 font-sans antialiased">

    <!-- Print-only section -->
    <div class="print-section">
        <div class="text-center p-8 max-w-xs mx-auto">
            <h4 class="text-xl font-bold text-gray-800 mb-4">Equipment QR Code</h4>
            <img src="<?= $serverPath ?>" alt="QR Code"
                class="w-64 h-64 mx-auto border border-gray-300 p-4 bg-white mb-4">
            <div class="text-2xl font-bold text-gray-900"><?= htmlspecialchars($equipment['property_no']) ?></div>
            <div class="text-lg text-gray-600"><?= htmlspecialchars($equipment['category_name']) ?></div>
            <div class="text-sm text-gray-500 mt-5">Generated: <?= date('Y-m-d H:i:s') ?></div>
        </div>
    </div>

    <!-- Main content (hidden when printing) -->
    <div class="max-w-7xl mx-auto no-print">

        <!-- Alert if QR library not found -->
        <?php if (!$qrLibraryFound): ?>
            <div class="mb-6 bg-red-50 border-l-4 border-red-400 p-4 rounded shadow-sm flex items-start gap-3">
                <i class="fas fa-exclamation-triangle text-red-500 mt-1"></i>
                <div class="flex-1">
                    <p class="text-red-700 font-medium">QR Library Not Found</p>
                    <p class="text-red-600 text-sm">QR code generation is not available. Please
                        <a href="https://sourceforge.net/projects/phpqrcode/" target="_blank"
                            class="underline hover:text-red-800">download PHP QR Code</a>
                        and place <code class="bg-red-100 px-1 rounded">phpqrcode/qrlib.php</code> in the PEPO root folder.
                    </p>
                </div>
                <button class="text-red-500 hover:text-red-700" onclick="this.parentElement.remove()">✕</button>
            </div>
        <?php endif; ?>

        <!-- Two column grid -->
        <div class="grid grid-cols-1 lg:grid-cols-5 gap-6">

            <!-- Left column (QR + details) - spans 2 on large screens -->
            <div class="lg:col-span-2">
                <div
                    class="bg-white rounded-2xl shadow-xl overflow-hidden transition-transform duration-300 hover:shadow-2xl hover:-translate-y-1">
                    <!-- Card header -->
                    <div class="bg-gradient-to-r from-emerald-600 to-emerald-500 px-6 py-5">
                        <h2 class="text-white text-xl font-semibold flex items-center gap-2">
                            <i class="fas fa-qrcode"></i> Equipment QR Code
                        </h2>
                    </div>

                    <div class="p-6">
                        <!-- QR image container -->
                        <div
                            class="bg-gradient-to-br from-gray-100 to-white p-4 rounded-xl mb-6 flex flex-col items-center">
                            <!-- Display the original QR (without text) -->
                            <img src="<?= $webPath ?>" alt="QR Code"
                                class="w-48 h-48 object-contain mx-auto border-4 border-white shadow-md rounded-lg">
                            <h3 class="mt-4 text-2xl font-bold text-emerald-700">
                                <?= htmlspecialchars($equipment['property_no']) ?>
                            </h3>
                            <p class="text-gray-500 flex items-center gap-1"><i class="fas fa-tag"></i>
                                <?= htmlspecialchars($equipment['category_name']) ?></p>
                            <?php if (!$qrFileGenerated || !file_exists($serverPath)): ?>
                                <p
                                    class="mt-3 text-sm bg-yellow-50 text-yellow-700 px-3 py-2 rounded-lg flex items-center gap-2">
                                    <i class="fas fa-exclamation-circle"></i> QR generated online – install local library
                                    for offline use.
                                </p>
                            <?php endif; ?>
                        </div>

                        <!-- Equipment details - Card layout -->
                        <h4 class="font-semibold text-gray-700 mb-3 flex items-center gap-2">
                            <i class="fas fa-info-circle text-emerald-600"></i> Equipment Details
                        </h4>
                        <div class="space-y-2">
                            <?php
                            $details = [
                                'property_no' => ['icon' => 'fa-hashtag', 'label' => 'Property No'],
                                'category_name' => ['icon' => 'fa-tags', 'label' => 'Category'],
                                'description' => ['icon' => 'fa-file-alt', 'label' => 'Description'],
                                'designation' => ['icon' => 'fa-user-tie', 'label' => 'Designation'],
                                'location' => ['icon' => 'fa-map-marker-alt', 'label' => 'Location'],
                                'type' => ['icon' => 'fa-cogs', 'label' => 'Type'],
                                'status' => ['icon' => 'fa-circle', 'label' => 'Status'],
                                'acquisition_date' => ['icon' => 'fa-calendar-alt', 'label' => 'Acquisition Date'],
                                'acquisition_cost' => ['icon' => 'fa-dollar-sign', 'label' => 'Acquisition Cost'],
                                'last_repair_date' => ['icon' => 'fa-wrench', 'label' => 'Last Repair Date'],
                            ];
                            foreach ($details as $key => $meta):
                                $value = $equipment[$key] ?? 'N/A';
                                if ($key === 'status'):
                                    $status = strtolower($value);
                                    $statusClass = 'bg-gray-100 text-gray-800';
                                    if (str_contains($status, 'operational'))
                                        $statusClass = 'bg-green-100 text-green-800';
                                    elseif (str_contains($status, 'repair'))
                                        $statusClass = 'bg-yellow-100 text-yellow-800';
                                    elseif (str_contains($status, 'unserviceable'))
                                        $statusClass = 'bg-red-100 text-red-800';
                                    ?>
                                    <div
                                        class="flex flex-col sm:flex-row sm:items-center gap-2 p-3 rounded-lg bg-gray-50 hover:bg-emerald-50 transition-all duration-200 border border-transparent hover:border-emerald-200 group">
                                        <span class="flex items-center gap-2 text-gray-500 text-sm min-w-[140px]">
                                            <i
                                                class="fas <?= $meta['icon'] ?> w-5 text-emerald-600 group-hover:text-emerald-700"></i>
                                            <?= $meta['label'] ?>:
                                        </span>
                                        <span class="font-medium">
                                            <span
                                                class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $statusClass ?>">
                                                <?= htmlspecialchars($value) ?>
                                            </span>
                                        </span>
                                    </div>
                                <?php else: ?>
                                    <div
                                        class="flex flex-col sm:flex-row sm:items-center gap-2 p-3 rounded-lg bg-gray-50 hover:bg-emerald-50 transition-all duration-200 border border-transparent hover:border-emerald-200 group">
                                        <span class="flex items-center gap-2 text-gray-500 text-sm min-w-[140px]">
                                            <i
                                                class="fas <?= $meta['icon'] ?> w-5 text-emerald-600 group-hover:text-emerald-700"></i>
                                            <?= $meta['label'] ?>:
                                        </span>
                                        <span
                                            class="text-gray-900 font-medium break-words flex-1"><?= htmlspecialchars($value) ?></span>
                                    </div>
                                <?php endif; endforeach; ?>
                        </div>

                        <!-- Action buttons -->
                        <div class="mt-6 space-y-3">
                            <?php if ($qrFileGenerated && file_exists($serverPath)): ?>
                                <!-- Use the composite image for download -->
                                <a href="<?= $downloadWebPath ?>"
                                    download="<?= htmlspecialchars($equipment['property_no']) ?>.png"
                                    class="block w-full text-center bg-emerald-600 hover:bg-emerald-700 text-white font-semibold py-3 px-4 rounded-xl transition duration-200 shadow-md hover:shadow-lg flex items-center justify-center gap-2">
                                    <i class="fas fa-download"></i> Download QR Code
                                </a>
                            <?php else: ?>
                                <button disabled
                                    class="block w-full text-center bg-gray-300 text-gray-500 font-semibold py-3 px-4 rounded-xl cursor-not-allowed flex items-center justify-center gap-2">
                                    <i class="fas fa-download"></i> Download Not Available
                                </button>
                            <?php endif; ?>

                            <a href="/staff/staff_dashboard.php?view=scan"
                                class="block w-full text-center bg-gray-600 hover:bg-gray-700 text-white font-semibold py-3 px-4 rounded-xl transition duration-200 shadow-md hover:shadow-lg flex items-center justify-center gap-2">
                                <i class="fas fa-arrow-left"></i> Back to Scanner
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right column (Documents) - spans 3 on large screens -->
            <div class="lg:col-span-3">
                <div
                    class="bg-white rounded-2xl shadow-xl overflow-hidden transition-transform duration-300 hover:shadow-2xl hover:-translate-y-1 h-full flex flex-col">
                    <!-- Card header -->
                    <div
                        class="bg-gradient-to-r from-emerald-600 to-emerald-500 px-6 py-5 flex items-center justify-between">
                        <h2 class="text-white text-xl font-semibold flex items-center gap-2">
                            <i class="fas fa-file-alt"></i> Repair/Maintenance History of
                            <?= htmlspecialchars($equipment['property_no']) ?>
                        </h2>
                        <span class="bg-white/20 text-white text-xs px-3 py-1 rounded-full">Latest 10</span>
                    </div>

                    <!-- Documents - Card layout -->
                    <div class="flex-1 overflow-y-auto max-h-[500px] p-4 space-y-3">
                        <?php
                        // Fetch documents with status Done or Complete only
                        $documents_query = "
                            SELECT d.id, d.property_no, d.status
                            FROM documents d
                            WHERE d.property_no = ? AND d.status IN ('Done', 'Complete')
                            ORDER BY d.id DESC
                            LIMIT 10
                        ";
                        $doc_stmt = $mysqli->prepare($documents_query);
                        $doc_stmt->bind_param("s", $equipment['property_no']);
                        $doc_stmt->execute();
                        $documents_result = $doc_stmt->get_result();

                        if ($documents_result && $documents_result->num_rows > 0):
                            while ($row = $documents_result->fetch_assoc()):
                                $status = $row['status'];
                                $badgeColor = 'bg-gray-100 text-gray-800';
                                if (str_contains(strtolower($status), 'approved'))
                                    $badgeColor = 'bg-green-100 text-green-800';
                                elseif (str_contains(strtolower($status), 'pending'))
                                    $badgeColor = 'bg-yellow-100 text-yellow-800';
                                elseif (str_contains(strtolower($status), 'done'))
                                    $badgeColor = 'bg-blue-100 text-blue-800';
                                ?>
                                <div
                                    class="bg-white border border-gray-200 rounded-xl p-4 hover:border-emerald-300 hover:shadow-md transition-all duration-200 group">
                                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center gap-2 mb-1">
                                                <span class="text-xs font-medium text-gray-500">
                                                    <i class="fas fa-hashtag mr-1"></i>Property No
                                                </span>
                                            </div>  
                                            <p class="text-sm font-bold text-gray-900 truncate">
                                                <?= htmlspecialchars($row['property_no']) ?></p>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <span
                                                class="bg-blue-100 text-blue-800 px-2 py-1 rounded text-xs font-medium">PRE-<?= $row['id'] ?></span>
                                            <span class="px-2 py-1 text-xs font-medium rounded-full <?= $badgeColor ?>">
                                                <?= htmlspecialchars($status) ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="mt-3 pt-3 border-t border-gray-100 flex items-center justify-between">
                                        <span class="text-xs text-gray-400">
                                            <i class="fas fa-file-alt mr-1"></i>Document
                                        </span>
                                        <a href="view_document.php?id=<?= $row['id'] ?>"
                                            class="text-emerald-600 hover:text-emerald-900 font-medium flex items-center gap-1 text-sm transition-colors">
                                            <i class="fas fa-eye"></i> View Details
                                        </a>
                                    </div>
                                </div>
                                <?php
                            endwhile;
                        else:
                            ?>
                            <div class="text-center py-12 text-gray-500">
                                <div
                                    class="w-16 h-16 mx-auto mb-4 rounded-full bg-gray-100 flex items-center justify-center">
                                    <i class="fas fa-file-excel text-3xl text-gray-300"></i>
                                </div>
                                <p class="text-gray-600 font-medium">No documents found for this property</p>
                                <p class="text-sm text-gray-400 mt-1">Documents will appear here when created</p>
                            </div>
                            <?php
                        endif;
                        $doc_stmt->close();
                        $mysqli->close();
                        ?>
                    </div>

                    <?php if ($documents_result && $documents_result->num_rows > 0): ?>
                        <div class="bg-gray-50 px-6 py-3 border-t border-gray-200 text-center text-sm text-gray-500">
                            <i class="fas fa-info-circle mr-1"></i> Showing latest 10 documents. Click "View Details" for
                            more.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function printQRCode() {
            window.print();
        }

        // Initialize Bootstrap tooltips (optional)
        document.addEventListener('DOMContentLoaded', function () {
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
        });
    </script>
</body>

</html>