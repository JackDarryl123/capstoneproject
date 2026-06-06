<?php
require_once __DIR__ . '/../config.php';
include_once '../profile_modal.php';

// ✅ Check and add 'signature' column if missing
$checkCol = $mysqli->query("SHOW COLUMNS FROM documents LIKE 'signature'");
if ($checkCol->num_rows == 0) {
    $mysqli->query("ALTER TABLE documents ADD COLUMN signature VARCHAR(255) DEFAULT NULL");
}

// ✅ Query only Approved documents for main table (Location filter removed)
$sql = "
    SELECT 
        d.id, 
        c.category_name, 
        e.property_no, 
        d.pre_repair_no, 
        d.date_requested,
        d.location
    FROM documents AS d
    LEFT JOIN equipment_category AS c ON d.category_id = c.id
    LEFT JOIN equipment AS e ON d.property_no = e.property_no
    WHERE d.status = 'Approved'
    ORDER BY d.id DESC
";

$result = $mysqli->query($sql);
?>

<!-- Tailwind CSS -->
<script src="https://cdn.tailwindcss.com"></script>
<!-- Font Awesome -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<!-- Bootstrap Icons -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
    .glass-card {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255, 255, 255, 0.2);
    }

    .hover-row:hover {
        background-color: rgba(59, 130, 246, 0.05);
    }

    .status-badge {
        padding: 2px 10px;
        border-radius: 12px;
        font-size: 0.75rem;
        font-weight: 500;
    }

    .search-input:focus {
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }

    .action-btn {
        transition: all 0.2s ease;
    }

    .action-btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
    }
</style>

<!-- Header Section -->
<div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 gap-4">
    <div>
        <h2 class="text-black md:text-2xl font-bold">MANAGE AND VIEW ALL APPROVED DOCUMENTS</h2>
        <p class="text-black">This section is where documents are inspected for maintenance and repair purposes.</p>
    </div>

    <div class="flex items-center bg-white rounded-lg shadow-sm px-4 py-3 border border-gray-200">
        <div class="w-10 h-10 rounded-full bg-blue-50 flex items-center justify-center mr-3">
            <i class="bi bi-geo-alt text-blue-600"></i>
        </div>
        <div>
            <p class="text-xs text-gray-500 uppercase tracking-wider">MAINTENANCE DEPARTMENT</p>
            <p class="font-semibold text-gray-800">OCCIDENTAL MINDORO</p>
        </div>
    </div>
</div>

<!-- Search and Filter Bar -->
<div class="glass-card rounded-xl shadow-sm mb-6 p-4">
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
        <!-- Search Bar -->
        <div class="flex-1 w-full">
            <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                    <i class="fas fa-search text-gray-400"></i>
                </div>
                <input type="text" id="searchInput"
                    class="search-input w-full pl-10 pr-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-200"
                    placeholder="Search by Property No, Equipment, or Location...">
            </div>
        </div>
    </div>
</div>

<!-- Main Table -->
<div class="bg-white rounded-xl shadow-sm overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-200 bg-white-50">
        <div class="flex justify-between items-center">
            <h2 class="text-lg font-semibold text-gray-800">DOCUMENT MANAGEMENT</h2>
            <div class="flex items-center space-x-4">
                <span class="text-sm text-gray-600">
                    Showing <?php echo $result ? $result->num_rows : 0; ?> requests
                </span>
            </div>
        </div>
        <p>This section manages all approved documents for inspection before they are scheduled for repair and
            maintenance.</p>
    </div>

    <div class="overflow-auto max-h-[500px]">
        <table class="min-w-full divide-y divide-gray-200" id="documentsTable">
            <thead class="bg-gray-50 sticky top-0 z-10">
                <tr>
                    <th scope="col"
                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Equipment
                    </th>
                    <th scope="col"
                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Property No
                    </th>
                    <th scope="col"
                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Location
                    </th>
                    <th scope="col"
                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Pre-Repair No
                    </th>
                    <th scope="col"
                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Date Requested
                    </th>
                    <th scope="col"
                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Actions
                    </th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php if ($result && $result->num_rows > 0): ?>
                    <?php while ($row = $result->fetch_assoc()): ?>
                        <tr class="hover-row transition duration-150">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center">
                                    <div
                                        class="flex-shrink-0 h-10 w-10 rounded-lg bg-blue-50 flex items-center justify-center mr-3">
                                        <i class="fas fa-tools text-blue-600"></i>
                                    </div>
                                    <div>
                                        <div class="text-sm font-medium text-gray-900">
                                            <?= htmlspecialchars($row['category_name'] ?? 'N/A'); ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-semibold text-gray-900">
                                    <?= htmlspecialchars($row['property_no'] ?? 'N/A'); ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                    <?= htmlspecialchars($row['location'] ?? 'N/A'); ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-semibold text-gray-900">
                                    <?= htmlspecialchars($row['pre_repair_no'] ?? 'N/A'); ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php
                                $dateRequested = $row['date_requested'] ?? '';
                                if (!empty($dateRequested)) {
                                    echo '<div class="text-sm font-medium text-gray-900">';
                                    echo date('M d, Y', strtotime($dateRequested));
                                    echo '</div>';
                                    echo '<div class="text-xs text-gray-500">';
                                    echo date('h:i A', strtotime($dateRequested));
                                    echo '</div>';
                                } else {
                                    echo '<span class="text-sm text-gray-400 italic">Not set</span>';
                                }
                                ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                <div class="flex items-center space-x-2">
                                    <a href="../edit_document.php?id=<?= urlencode($row['id']); ?>"
                                        class="inline-flex items-center px-3 py-2 border border-blue-300 text-blue-700 bg-blue-50 rounded-lg hover:bg-blue-100 transition duration-200"
                                        title="Edit document">
                                        <i class="fas fa-edit mr-1"></i>
                                        MANAGE
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" class="px-6 py-12 text-center">
                            <div class="text-gray-500">
                                <i class="fas fa-inbox text-4xl mb-4 opacity-30"></i>
                                <h3 class="text-lg font-medium text-gray-900 mb-2">No documents found</h3>
                                <p class="text-gray-600">There are no approved documents available at this time.</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
$mysqli->close();
?>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const searchInput = document.getElementById('searchInput');
        if (searchInput) {
            searchInput.addEventListener('keyup', function (e) {
                const searchTerm = searchInput.value.toLowerCase().trim();
                const rows = document.querySelectorAll('#documentsTable tbody tr');
                rows.forEach(row => {
                    const cells = row.querySelectorAll('td');
                    if (cells.length >= 4) {
                        const equipment = cells[0].textContent.toLowerCase();
                        const propertyNo = cells[1].textContent.toLowerCase();
                        const location = cells[2].textContent.toLowerCase();
                        const preRepairNo = cells[3].textContent.toLowerCase();
                        if (equipment.includes(searchTerm) ||
                            propertyNo.includes(searchTerm) ||
                            location.includes(searchTerm) ||
                            preRepairNo.includes(searchTerm)) {
                            row.style.display = '';
                        } else {
                            row.style.display = 'none';
                        }
                    }
                });
            });
        }
    });
</script>