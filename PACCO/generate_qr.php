<?php
// ============================================
// 1. INITIALIZATION & DATABASE CONNECTION
// ============================================
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
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


// ============================================
// 2. QR CODE GENERATION
// ============================================
require_once '../phpqrcode/qrlib.php';

// Generate Dynamic URL (Works on phones connected to the same network)
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$domainName = $_SERVER['HTTP_HOST'];
$qrData = $protocol . $domainName . "/GSO/generate_qr.php?id={$equipment['id']}";

$tempDir = "temp_qr/";
if (!file_exists($tempDir)) {
    mkdir($tempDir);
}

$fileName = "equipment_{$equipment['id']}.png";
$filePath = $tempDir . $fileName;

// Create QR Code
QRcode::png($qrData, $filePath, QR_ECLEVEL_L, 5);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QR Code - <?= htmlspecialchars($equipment['property_no']) ?></title>

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                    },
                    colors: {
                        brand: {
                            50: '#f0fdf4',
                            100: '#dcfce7',
                            500: '#22c55e',
                            600: '#16a34a',
                            700: '#15803d',
                            900: '#14532d',
                        }
                    }
                }
            }
        }
    </script>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>

    
        a {
            text-decoration: none;
        }

        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 6px;
        }

        ::-webkit-scrollbar-track {
            background: #f1f1f1;
        }

        ::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        /* PRINT STYLES */
        /* @media print {
            body * { visibility: hidden; }
            .print-section, .print-section * { visibility: visible; }
            .print-section {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                display: flex !important;
                justify-content: center;
                align-items: center;
                height: 100vh;
            }
       
            .no-print { display: none !important; }
        } */


        /* 1. Fix Tailwind resetting Bootstrap form styles */
        .form-control {
            border: 1px solid #dee2e6 !important;
            /* Force border back */
            padding: 0.375rem 0.75rem !important;
            border-radius: 0.375rem !important;
        }

        /* 2. Fix the scrollbar */
        ::-webkit-scrollbar {
            width: 6px;
        }

        ::-webkit-scrollbar-track {
            background: #f1f1f1;
        }

        ::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        /* 3. Print Styles */
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
                display: flex !important;
                justify-content: center;
                align-items: center;
                height: 100vh;
            }

            .no-print {
                display: none !important;
            }
        }
    </style>
    
 

</head>

<body class="bg-gray-50 text-gray-800 font-sans antialiased">

    <div class="print-section hidden">
        <div class="text-center border-4 border-dashed border-gray-800 p-8 rounded-xl max-w-sm mx-auto">
            <h2 class="text-2xl font-bold uppercase tracking-wider mb-4">Property Tag</h2>
            <img src="<?= $filePath ?>" alt="QR Code" class="w-64 h-64 mx-auto mb-4 object-contain block">
            <div class="text-3xl font-black mb-2"><?= htmlspecialchars($equipment['property_no']) ?></div>
            <div class="text-xl text-gray-600 mb-4"><?= htmlspecialchars($equipment['category_name']) ?></div>
            <div class="text-sm text-gray-400">Generated: <?= date('Y-m-d') ?></div>
        </div>
    </div>

    <div class="min-h-screen py-10 px-4 sm:px-6 lg:px-8 no-print">

        <div class="max-w-7xl mx-auto mb-8 flex items-center justify-between">
            <div>
                <a href="admin_dashboard.php?view=equipment"
                    class="text-sm font-medium text-gray-500 hover:text-brand-600 transition-colors flex items-center gap-2 mb-1">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
                <h1 class="text-3xl font-bold text-gray-900">Equipment Details</h1>
            </div>
            <div class="hidden sm:block">
                <span
                    class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-white shadow-sm border border-gray-200 text-gray-600">
                    <i class="fas fa-tag mr-2 text-brand-500"></i> Property #:
                    <?= htmlspecialchars($equipment['property_no']) ?>
                </span>
            </div>
        </div>

        <div class="max-w-7xl mx-auto grid grid-cols-1 lg:grid-cols-12 gap-8">

            <div class="lg:col-span-5 space-y-6">

                <div class="bg-white rounded-3xl shadow-xl overflow-hidden border border-gray-100 relative group">
                    <div class="absolute top-0 left-0 w-full h-2 bg-gradient-to-r from-brand-500 to-emerald-600"></div>

                    <div class="p-8 text-center">
                        <h2 class="text-lg font-semibold text-gray-400 uppercase tracking-widest text-xs mb-4">Scan to
                            View History</h2>

                        <div
                            class="relative inline-block group-hover:scale-105 transition-transform duration-300 ease-out">
                            <div class="absolute inset-0 bg-brand-100 rounded-xl transform rotate-3 scale-105"></div>
                            <img src="<?= $filePath ?>" alt="QR Code"
                                class="relative w-56 h-56 object-contain bg-white p-2 rounded-xl border border-gray-200 shadow-sm mx-auto">
                        </div>

                        <h3 class="mt-6 text-2xl font-bold text-gray-900">
                            <?= htmlspecialchars($equipment['property_no']) ?></h3>
                        <p class="text-brand-600 font-medium"><?= htmlspecialchars($equipment['category_name']) ?></p>

                        <?php
                        $status = $equipment['status'];
                        $statusColor = match ($status) {
                            'Operational' => 'bg-green-100 text-green-800 border-green-200',
                            'Under repair' => 'bg-amber-100 text-amber-800 border-amber-200',
                            'Unserviceable' => 'bg-red-100 text-red-800 border-red-200',
                            default => 'bg-gray-100 text-gray-800 border-gray-200'
                        };
                        $statusIcon = match ($status) {
                            'Operational' => 'fa-check-circle',
                            'Under repair' => 'fa-tools',
                            'Unserviceable' => 'fa-ban',
                            default => 'fa-info-circle'
                        };
                        ?>
                        <div class="mt-4 flex justify-center">
                            <span
                                class="inline-flex items-center px-4 py-1.5 rounded-full text-sm font-bold border <?= $statusColor ?>">
                                <i class="fas <?= $statusIcon ?> mr-2"></i> <?= htmlspecialchars($status) ?>
                            </span>
                        </div>
                    </div>

                    <div class="bg-gray-50 px-8 py-6 border-t border-gray-100 grid grid-cols-2 gap-4">
                        <a href="<?= $filePath ?>" download="QR_<?= htmlspecialchars($equipment['property_no']) ?>.png"
                            class="flex items-center justify-center w-full px-4 py-3 bg-white border border-gray-300 rounded-xl shadow-sm text-sm font-bold text-gray-700 hover:bg-gray-50 hover:text-brand-600 transition-all group">
                            <i class="fas fa-download mr-2 group-hover:-translate-y-0.5 transition-transform"></i> Save
                        </a>
                        <button onclick="printQRCode()"
                            class="flex items-center justify-center w-full px-4 py-3 bg-brand-600 border border-transparent rounded-xl shadow-md text-sm font-bold text-white hover:bg-brand-700 hover:shadow-lg transition-all transform hover:-translate-y-0.5">
                            <i class="fas fa-print mr-2"></i> Print
                        </button>
                    </div>
                </div>

                <div class="bg-white rounded-2xl shadow-lg border border-gray-100 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-100 bg-gray-50/50">
                        <h3 class="font-bold text-gray-900">Technical Specifications</h3>
                    </div>
                    <div class="p-0">
                        <table class="min-w-full divide-y divide-gray-100">
                            <tbody class="divide-y divide-gray-100">
                                <?php
                                $fields = [
                                    'Description' => $equipment['description'],
                                    'Designation' => $equipment['designation'],
                                    'Location' => $equipment['location'],
                                    'Type' => $equipment['type'],
                                    'Acquisition Date' => $equipment['acquisition_date'],
                                    'Cost' => $equipment['acquisition_cost'],
                                    'Last Repair' => $equipment['last_repair_date'] ?? 'N/A'
                                ];
                                foreach ($fields as $label => $value):
                                    ?>
                                    <tr class="hover:bg-gray-50 transition-colors">
                                        <td
                                            class="px-6 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider w-1/3 bg-gray-50/30">
                                            <?= $label ?></td>
                                        <td class="px-6 py-3 text-sm text-gray-700 font-medium">
                                            <?= htmlspecialchars($value) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>

            <div class="lg:col-span-7">
                <div class="bg-white rounded-3xl shadow-xl border border-gray-100 overflow-hidden h-full">
                    <div class="px-8 py-6 border-b border-gray-100 flex justify-between items-center bg-gray-50/30">
                        <div>
                            <h3 class="text-xl font-bold text-gray-900">Document History</h3>
                            <p class="text-sm text-gray-500 mt-1">Recent requests and reports for this unit</p>
                        </div>
                        <div class="w-10 h-10 rounded-full bg-blue-50 flex items-center justify-center text-blue-600">
                            <i class="fas fa-history text-lg"></i>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col"
                                        class="px-6 py-4 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">
                                        Ref No.</th>
                                    <th scope="col"
                                        class="px-6 py-4 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">
                                        Property #</th>
                                    <th scope="col"
                                        class="px-6 py-4 text-center text-xs font-bold text-gray-500 uppercase tracking-wider">
                                        Status</th>
                                    <th scope="col"
                                        class="px-6 py-4 text-right text-xs font-bold text-gray-500 uppercase tracking-wider">
                                        Action</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-100">
                                <?php
                                // 1. FETCH DOCUMENTS LOGIC (Corrected: No LIMIT, TRIM used)
                                $documents_query = "
                                    SELECT d.id, d.property_no, d.status, d.date_requested, d.pre_repair_no
                                    FROM documents d
                                    WHERE TRIM(d.property_no) = TRIM(?)
                                    ORDER BY d.id DESC
                                ";

                                $doc_stmt = $mysqli->prepare($documents_query);
                                $doc_stmt->bind_param("s", $equipment['property_no']);
                                $doc_stmt->execute();
                                $documents_result = $doc_stmt->get_result();

                                if ($documents_result && $documents_result->num_rows > 0):
                                    while ($row = $documents_result->fetch_assoc()):
                                        $statusRaw = strtoupper($row['status']);
                                        $statusClass = match ($statusRaw) {
                                            'DONE' => 'bg-green-100 text-green-700 ring-green-600/20',
                                            'APPROVED' => 'bg-blue-100 text-blue-700 ring-blue-600/20',
                                            'PENDING' => 'bg-amber-100 text-amber-700 ring-amber-600/20',
                                            'COMPLETE' => 'bg-purple-100 text-purple-700 ring-purple-600/20',
                                            default => 'bg-gray-100 text-gray-600 ring-gray-500/10'
                                        };
                                        $refNo = !empty($row['pre_repair_no']) ? $row['pre_repair_no'] : 'PRE-' . $row['id'];
                                        ?>
                                        <tr class="hover:bg-blue-50/40 transition-colors group">
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                <?= htmlspecialchars($refNo) ?>
                                                <div class="text-[10px] text-gray-400 font-normal">
                                                    <?= $row['date_requested'] ? date('M d, Y', strtotime($row['date_requested'])) : 'No Date' ?>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                                <?= htmlspecialchars($row['property_no']) ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-center">
                                                <span
                                                    class="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset <?= $statusClass ?>">
                                                    <?= htmlspecialchars($row['status']) ?>
                                                </span>
                                            </td>
                                            <!-- <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        <a href="view_document.php?id=<?= $row['id'] ?>" class="text-brand-600 hover:text-brand-900 bg-brand-50 hover:bg-brand-100 px-3 py-1.5 rounded-lg transition-colors inline-flex items-center gap-1">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                    </td> -->
                                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                <button onclick="openDocumentModal(<?= $row['id'] ?>)"
                                                    class="text-brand-600 hover:text-brand-900 bg-brand-50 hover:bg-brand-100 px-3 py-1.5 rounded-lg transition-colors inline-flex items-center gap-1 border-0">
                                                    <i class="fas fa-eye"></i> View
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" class="px-6 py-12 text-center text-gray-500">
                                            <div class="flex flex-col items-center justify-center">
                                                <div
                                                    class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mb-3">
                                                    <i class="fas fa-folder-open text-gray-300 text-2xl"></i>
                                                </div>
                                                <p class="font-medium">No documents found</p>
                                                <p class="text-xs mt-1">This property has no recorded history yet.</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php
                                endif;
                                $doc_stmt->close();
                                // Clean up main connection
                                $mysqli->close();
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>


    <div id="docModal" class="modal fade" tabindex="-1" role="dialog" aria-hidden="true" style="background: rgba(0,0,0,0.5);">
    <div class="modal-dialog modal-xl modal-dialog-scrollable" role="document">
        <div class="modal-content shadow-2xl">
            
            <div class="modal-header bg-primary text-white d-flex justify-content-between align-items-center px-4 py-3" style="background-color: #0d6efd;">
                <h5 class="modal-title fw-bold flex items-center gap-2">
                    <i class="fas fa-file-alt"></i> Document Details
                </h5>
                <button type="button" onclick="closeDocumentModal()" class="text-white hover:text-gray-200 bg-transparent border-0" style="font-size: 1.5rem;">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div id="modalContent" class="modal-body bg-light p-4">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary mb-3" role="status" style="width: 3rem; height: 3rem;"></div>
                    <p class="text-muted fw-medium">Loading document details...</p>
                </div>
            </div>

            <div class="modal-footer bg-light border-top-0 px-4 py-3">
                <!-- <button type="button" onclick="printModalContent()" class="btn btn-success text-white d-flex align-items-center gap-2">
                    <i class="fas fa-print"></i> Print Document
                </button> -->
                <button type="button" onclick="closeDocumentModal()" class="btn btn-secondary text-white">
                    Close
                </button>
            </div>
        </div>
    </div>
</div>

    <script>
        function printQRCode() {
            window.print();
        }

// Existing QR Print
    function printQRCode() {
        window.print();
    }

    // --- UPDATED MODAL FUNCTIONS ---

    function openDocumentModal(id) {
        const modal = document.getElementById('docModal');
        
        // 1. Show Modal (Manual CSS toggle)
        modal.classList.add('show');
        modal.style.display = 'block';
        document.body.style.overflow = 'hidden'; // Stop background scrolling

        // 2. Reset Content
        const contentDiv = document.getElementById('modalContent');
        contentDiv.innerHTML = `
            <div class="text-center py-5">
                <div class="spinner-border text-primary mb-3" role="status" style="width: 3rem; height: 3rem;"></div>
                <p class="text-muted fw-medium">Loading document details...</p>
            </div>`;

        // 3. Fetch Data
        fetch(`../get_document_simple.php?id=${id}`)
            .then(response => {
                if (!response.ok) throw new Error('Network response was not ok');
                return response.text();
            })
            .then(html => {
                contentDiv.innerHTML = html;
            })
            .catch(error => {
                contentDiv.innerHTML = `
                    <div class="alert alert-danger text-center m-3">
                        <i class="fas fa-exclamation-triangle fa-2x mb-2"></i><br>
                        <strong>Error loading document</strong><br>
                        <small>${error.message}</small>
                    </div>`;
            });
    }

    function closeDocumentModal() {
        const modal = document.getElementById('docModal');
        modal.classList.remove('show');
        modal.style.display = 'none';
        document.body.style.overflow = ''; 
    }

    function printModalContent() {
        const content = document.getElementById('modalContent').innerHTML;
        const printWindow = window.open('', '', 'height=600,width=800');
        
        printWindow.document.write('<html><head><title>Print Document</title>');
        // Load Bootstrap for the print window so it looks correct
        printWindow.document.write('<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">');
        printWindow.document.write('<style>body { padding: 20px; } .signature-input { border: none !important; border-bottom: 1px solid #000 !important; }</style>');
        printWindow.document.write('</head><body>');
        printWindow.document.write(content);
        printWindow.document.write('</body></html>');
        
        printWindow.document.close();
        printWindow.focus();
        
        setTimeout(() => {
            printWindow.print();
            printWindow.close();
        }, 500);
    }
    
    // Close on Escape
    document.addEventListener('keydown', function(event) {
        if (event.key === "Escape") {
            closeDocumentModal();
        }
    });
    </script>




</body>



</html>