<?php
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include_once '../profile_modal.php';

// Fetch categories for dropdowns
$categories_result = $mysqli->query("SELECT id, category_name FROM equipment_category ORDER BY category_name ASC");

// Get success/error message
$equipment_message = '';
if (isset($_SESSION['equipment_message'])) {
    $equipment_message = $_SESSION['equipment_message'];
    unset($_SESSION['equipment_message']);
}
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

    .table-scroll-container {
        max-height: 500px;
        scrollbar-width: thin;
    }

    .table-scroll-container::-webkit-scrollbar {
        width: 8px;
    }

    .table-scroll-container::-webkit-scrollbar-track {
        background: #f9fafb;
        border-radius: 4px;
    }

    .table-scroll-container::-webkit-scrollbar-thumb {
        background: #d1d5db;
        border-radius: 4px;
    }

    .table-scroll-container::-webkit-scrollbar-thumb:hover {
        background: #9ca3af;
    }

    .sticky-header {
        position: sticky;
        top: 0;
        z-index: 10;
        background-color: #f9fafb;
    }
</style>

<!-- <div class="container mx-auto px-4 py-8"> -->
<!-- Success/Error Messages -->
<?php if (!empty($equipment_message)): ?>
    <?php echo $equipment_message; ?>
<?php endif; ?>

<!-- Header Section -->
<div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 gap-4">
    <div>
        <h2 class="text-black md:text-2xl font-bold">MANAGE AND VIEW ALL EQUIPMENTS</h2>
    </div>

    <div class="flex items-center space-x-4">
        <div class="hidden md:flex items-center space-x-6">
            <div class="text-center">
                <p class="text-lg text-black-300 uppercase tracking-wider">Total Equipment</p>
                <?php
                $total_query = "SELECT COUNT(*) as total FROM equipment";
                $total_result = $mysqli->query($total_query);
                $total_count = $total_result->fetch_assoc()['total'] ?? 0;
                ?>
                <p class="text-2xl font-bold text-black"><?php echo $total_count; ?></p>
            </div>
            <div class="h-8 w-px bg-black/30"></div>
            <div class="text-center">
                <p class="text-lg text-black-300 uppercase tracking-wider">Categories</p>
                <?php
                $cat_query = "SELECT COUNT(*) as total FROM equipment_category";
                $cat_result = $mysqli->query($cat_query);
                $cat_count = $cat_result->fetch_assoc()['total'] ?? 0;
                ?>
                <p class="text-2xl font-bold text-black"><?php echo $cat_count; ?></p>
            </div>
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
                    placeholder="Search by Property No or Equipment...">
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="flex items-center space-x-3">
            <!-- View Logs Button -->
            <button type="button" onclick="toggleEquipmentDrawer(true)"
                class="action-btn bg-slate-800 hover:bg-slate-700 text-white px-5 py-3 rounded-lg font-medium flex items-center transition duration-200">
                <i class="fas fa-history mr-2"></i>
                View Logs
            </button>
        </div>
    </div>

    <!-- Filter Row -->
    <div class="mt-4 flex flex-wrap gap-3">
        <!-- Location Filter -->
        <select
            class="filter-select px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-200"
            id="locationFilter">
            <option value="">All Locations</option>
            <option value="Mamburao">MAMBURAO</option>
            <option value="Sablayan">SABLAYAN</option>
            <option value="Lubang">LUBANG</option>
            <option value="San Jose">SAN JOSE</option>
        </select>

        <!-- Status Filter -->
        <select
            class="filter-select px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-200"
            id="statusFilter">
            <option value="">All Status</option>
            <option value="Operational">OPERATIONAL</option>
            <option value="Under repair">UNDER REPAIR</option>
            <option value="Unserviceable">UNSERVICEABLE</option>
        </select>

        <!-- Type Filter -->
        <select
            class="filter-select px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-200"
            id="typeFilter">
            <option value="">All Types</option>
            <option value="Heavy Equipment">HEAVY EQUIPMENT</option>
            <option value="Light Equipment">LIGHT EQUIPMENT</option>
        </select>

        <!-- Clear Filters Button -->
        <button id="clearFilters"
            class="action-btn border border-gray-300 hover:bg-gray-50 text-gray-700 px-4 py-2.5 rounded-lg font-medium flex items-center transition duration-200">
            <i class="fas fa-times-circle mr-2"></i>
            Clear Filters
        </button>

        <!-- Results Count -->
        <div class="ml-auto flex items-center">
            <span class="text-sm text-gray-600" id="resultCount">
                <?php
                $count_query = "SELECT COUNT(*) as count FROM equipment";
                $count_result = $mysqli->query($count_query);
                $count = $count_result->fetch_assoc()['count'] ?? 0;
                echo "Showing " . $count . " equipment";
                ?>
            </span>
        </div>
    </div>
</div>

<!-- Main Table -->
<div class="bg-white rounded-xl shadow-sm overflow-hidden">

    <!-- Equipment Table -->
    <div class="table-scroll-container overflow-auto">
        <table class="min-w-full divide-y divide-gray-200" id="equipmentTable">
            <thead class="sticky-header bg-gray-50">
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
                        Allocation
                    </th>
                    <th scope="col"
                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Type
                    </th>
                    <th scope="col"
                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Status
                    </th>
                    <th scope="col"
                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Actions
                    </th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200" id="equipmentTableBody">
                <?php
                // Reset categories result pointer
                $categories_result->data_seek(0);

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
                $totalRows = $result ? $result->num_rows : 0;

                if ($result && $totalRows > 0) {
                    while ($row = $result->fetch_assoc()) {
                        // Assign badge color for status
                        $statusClass = match (strtolower($row['status'])) {
                            'operational' => 'bg-green-100 text-green-800',
                            'under repair' => 'bg-yellow-100 text-yellow-800',
                            'unserviceable' => 'bg-red-100 text-red-800',
                            default => 'bg-gray-100 text-gray-800',
                        };

                        // Check if QR code button should be shown
                        $showQR = (($_SESSION['role'] ?? '') !== 'staff');
                        ?>
                        <tr class="hover-row transition duration-150">
                            <!-- Equipment -->
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center">
                                    <div
                                        class="flex-shrink-0 h-10 w-10 rounded-lg bg-blue-50 flex items-center justify-center mr-3">
                                        <i class="fas fa-tools text-blue-600"></i>
                                    </div>
                                    <div>
                                        <div class="text-sm font-medium text-gray-900">
                                            <?= htmlspecialchars($row['category_name']); ?>
                                        </div>
                                        <?php if (!empty($row['description'])): ?>
                                            <div class="text-xs text-gray-500 truncate max-w-[200px]"
                                                title="<?= htmlspecialchars($row['description']); ?>">
                                                <?= htmlspecialchars(substr($row['description'], 0, 50)) . (strlen($row['description']) > 50 ? '...' : ''); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>

                            <!-- Property No -->
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-900 font-mono">
                                    <?= htmlspecialchars($row['property_no']); ?>
                                </div>
                            </td>

                            <!-- Location -->
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div
                                    class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-blue-50 text-blue-700">
                                    <i class="fas fa-map-marker-alt mr-1.5"></i>
                                    <?= htmlspecialchars($row['location']); ?>
                                </div>
                            </td>

                            <!-- Type -->
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-900">
                                    <?= htmlspecialchars($row['type']); ?>
                                </div>
                            </td>

                            <!-- Status -->
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span
                                    class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium <?= $statusClass; ?>">
                                    <?= htmlspecialchars($row['status']); ?>
                                </span>
                            </td>

                            <!-- Actions -->
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                <div class="flex items-center space-x-2">
                                    <!-- QR Code Button (if allowed) -->
                                    <?php if ($showQR): ?>
                                        <button onclick="window.open('generate_qr.php?id=<?= $row['id']; ?>', '_blank')"
                                            class="inline-flex items-center px-3 py-2 border border-green-300 text-green-700 bg-green-50 rounded-lg hover:bg-green-100 transition duration-200"
                                            title="View QR Code">
                                            <i class="fas fa-qrcode mr-1.5"></i>
                                            QR Code
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php
                    }
                } else {
                    ?>
                    <tr>
                        <td colspan="6" class="px-6 py-12 text-center">
                            <div class="text-gray-500">
                                <i class="fas fa-inbox text-4xl mb-4 opacity-30"></i>
                                <h3 class="text-lg font-medium text-gray-900 mb-2">No equipment found</h3>
                                <p class="text-gray-600 mb-4">
                                    There are no equipment records in the system yet.
                                </p>
                            </div>
                        </td>
                    </tr>
                    <?php
                }
                ?>
            </tbody>
        </table>
    </div>
</div>
</div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Get all equipment rows
            const equipmentRows = document.querySelectorAll('#equipmentTable tbody tr');
            const resultCount = document.getElementById('resultCount');

            // Update result count initially
            updateResultCount();

            // Search and Filter functionality
            const searchInput = document.getElementById('searchInput');
            const locationFilter = document.getElementById('locationFilter');
            const statusFilter = document.getElementById('statusFilter');
            const typeFilter = document.getElementById('typeFilter');
            const clearFiltersBtn = document.getElementById('clearFilters');

            function applyAllFilters() {
                const searchTerm = searchInput.value.toLowerCase().trim();
                const locationValue = locationFilter.value;
                const statusValue = statusFilter.value;
                const typeValue = typeFilter.value;

                let visibleCount = 0;

                equipmentRows.forEach(row => {
                    if (row.cells.length < 6) return; // Skip empty rows

                    const equipmentName = row.cells[0].querySelector('.text-sm.font-medium').textContent
                        .toLowerCase();
                    const propertyNo = row.cells[1].textContent.toLowerCase();
                    const rowLocation = row.cells[2].textContent;
                    const rowType = row.cells[3].textContent;
                    const rowStatus = row.cells[4].textContent;

                    // Check search match
                    const searchMatch = !searchTerm ||
                        equipmentName.includes(searchTerm) ||
                        propertyNo.includes(searchTerm);

                    // Check filter matches
                    const locationMatch = !locationValue || rowLocation.includes(locationValue);
                    const statusMatch = !statusValue || rowStatus.includes(statusValue);
                    const typeMatch = !typeValue || rowType.includes(typeValue);

                    // Show/hide row based on all conditions
                    if (searchMatch && locationMatch && statusMatch && typeMatch) {
                        row.style.display = '';
                        visibleCount++;
                    } else {
                        row.style.display = 'none';
                    }
                });

                updateResultCount(visibleCount);

                // Show empty state if no results
                const tbody = document.getElementById('equipmentTableBody');
                const existingEmptyRow = tbody.querySelector('.no-results');

                if (visibleCount === 0) {
                    if (!existingEmptyRow) {
                        const emptyRow = document.createElement('tr');
                        emptyRow.className = 'no-results';
                        emptyRow.innerHTML = `
                    <td colspan="6" class="px-6 py-12 text-center">
                        <div class="text-gray-500">
                            <i class="fas fa-search text-4xl mb-4 opacity-30"></i>
                            <h3 class="text-lg font-medium text-gray-900 mb-2">No equipment found</h3>
                            <p class="text-gray-600 mb-4">
                                No equipment matches your search criteria.
                            </p>
                        </div>
                    </td>
                `;
                        tbody.appendChild(emptyRow);
                    }
                } else {
                    if (existingEmptyRow) {
                        existingEmptyRow.remove();
                    }
                }
            }

            function updateResultCount(count = null) {
                if (count !== null) {
                    resultCount.textContent = `Showing ${count} equipment`;
                } else {
                    const visibleRows = Array.from(equipmentRows).filter(row =>
                        row.style.display !== 'none' && row.cells.length >= 6
                    ).length;
                    resultCount.textContent = `Showing ${visibleRows} equipment`;
                }
            }

            // Add event listeners
            searchInput.addEventListener('input', applyAllFilters);
            locationFilter.addEventListener('change', applyAllFilters);
            statusFilter.addEventListener('change', applyAllFilters);
            typeFilter.addEventListener('change', applyAllFilters);

            // Clear all filters button
            if (clearFiltersBtn) {
                clearFiltersBtn.addEventListener('click', function () {
                    searchInput.value = '';
                    locationFilter.value = '';
                    statusFilter.value = '';
                    typeFilter.value = '';
                    applyAllFilters();
                });
            }

            // Initialize with current filter state
            applyAllFilters();
        });
    </script>

    <?php
    // Close database connection
    $mysqli->close();
    ?>