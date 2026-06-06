<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/session_helper.php';
start_user_session();

require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../profile_modal.php';

if (isset($_GET['action']) && $_GET['action'] === 'pdf') {
    generatePDF($mysqli);
    exit;
}

// Configuration
$items_per_page = 5;
$current_page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$offset = ($current_page - 1) * $items_per_page;

// Get filters
$filters = [
    'location' => isset($_GET['location']) ? $_GET['location'] : '',
    'type' => isset($_GET['type']) ? $_GET['type'] : '',
    'view' => isset($_GET['view']) ? $_GET['view'] : 'pre-repair',
    'tab' => isset($_GET['tab']) ? $_GET['tab'] : 'pre-repair'
];

// Get unique locations and types for dropdowns
function getUniqueValues($mysqli, string $column, string $table = 'equipment'): array
{
    $allowedTables = ['equipment', 'inventory'];
    $allowedColumns = ['location', 'type'];
    
    if (!in_array($table, $allowedTables, true) || !in_array($column, $allowedColumns, true)) {
        return [];
    }
    
    $query = "SELECT DISTINCT `$column` FROM `$table` WHERE `$column` IS NOT NULL AND `$column` != '' ORDER BY `$column`";
    $result = $mysqli->query($query);
    $values = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $values[] = $row[$column];
        }
    }
    return $values;
}

$locations = getUniqueValues($mysqli, 'location');
$types = getUniqueValues($mysqli, 'type');

// Function to get equipment statistics
function getEquipmentStats($mysqli, $location = null, $type = null, $category_group = false)
{
    $query = "SELECT ";

    if ($category_group) {
        $query .= "ec.category_name as equipment_name, ";
    } else {
        $query .= "e.location as location_name, ";
    }

    $query .= "COUNT(*) as units,
               SUM(CASE WHEN e.status = 'Operational' THEN 1 ELSE 0 END) as operational,
               SUM(CASE WHEN e.status = 'Under repair' THEN 1 ELSE 0 END) as under_repair,
               SUM(CASE WHEN e.status = 'Unserviceable' THEN 1 ELSE 0 END) as unserviceable
               FROM equipment e
               LEFT JOIN equipment_category ec ON e.category_id = ec.id
               WHERE 1=1 ";

    $params = [];
    $types_str = '';


    if ($location) {
        $query .= "AND e.location = ? ";
        $params[] = $location;
        $types_str .= 's';
    }

    if ($type) {
        $query .= "AND e.type = ? ";
        $params[] = $type;
        $types_str .= 's';
    }

    if ($category_group) {
        $query .= "GROUP BY ec.id, ec.category_name ORDER BY ec.category_name";
    } else {
        $query .= "GROUP BY e.location ORDER BY e.location";
    }

    $stmt = $mysqli->prepare($query);
    if (!empty($params)) {
        $stmt->bind_param($types_str, ...$params);
    }
    $stmt->execute();
    return $stmt->get_result();
}

// Get all required data
$target_locations = !empty($filters['location']) ? [$filters['location']] : $locations;
$equipment_data = [];

foreach ($target_locations as $location) {
    $equipment_data[$location] = [
        'heavy' => getEquipmentStats($mysqli, $location, 'Heavy Equipment', true),
        'light' => getEquipmentStats($mysqli, $location, 'Light Equipment', true)
    ];
}

// Get summary statistics (also filtered by location if selected)
$loc_filter = !empty($filters['location']) ? $filters['location'] : null;
$all_equipment_stats = getEquipmentStats($mysqli, $loc_filter, null, false);
$heavy_equipment_stats = getEquipmentStats($mysqli, $loc_filter, 'Heavy Equipment', false);
$light_equipment_stats = getEquipmentStats($mysqli, $loc_filter, 'Light Equipment', false);

// PDF Generation Function - SIMPLIFIED VERSION
function generatePDF($mysqli)
{
    $tab = $_GET['tab'] ?? 'pre-repair';
    $selected_location = $_GET['location'] ?? '';
    
    // Get dynamic locations
    $query = "SELECT DISTINCT location FROM equipment WHERE location IS NOT NULL AND location != '' ORDER BY location";
    $loc_result = $mysqli->query($query);
    $dynamic_locations = [];
    while ($l_row = $loc_result->fetch_assoc()) {
        $dynamic_locations[] = $l_row['location'];
    }

    // Filter locations based on selection
    $target_pdf_locations = !empty($selected_location) ? [$selected_location] : $dynamic_locations;

    // Generate simple HTML for PDF
    $html = '<!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>' . ($tab === 'pre-repair' ? 'PRE-REPAIR INSPECTION REPORT' : 'SUMMARY STATUS REPORT') . '</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
            th, td { border: 1px solid #000; padding: 8px; text-align: center; }
            th { background-color: #4caf50; color: white; }
            .subheader { background-color: #ffc109; font-weight: bold; text-align: center; }
            .total-row { background-color: #f2f2f2; font-weight: bold; }
            h1 { text-align: center; font-size: 24px; margin-bottom: 30px; }
            h2 { font-size: 20px; margin-top: 30px; border-bottom: 2px solid #000; padding-bottom: 5px; }
            @page { margin: 0.5in; }
        </style>
    </head>
    <body>';

    if ($tab === 'pre-repair') {
        $html .= '<h1>PRE-REPAIR INSPECTION REPORT</h1>';

        foreach ($target_pdf_locations as $location) {
            $html .= "<h2>$location</h2>";

            foreach (['Heavy Equipment', 'Light Equipment'] as $equip_type) {
                $result = getEquipmentStats($mysqli, $location, $equip_type, true);
                $html .= "<h3 class='subheader'>" . strtoupper($equip_type) . "</h3>";

                if ($result && $result->num_rows > 0) {
                    $html .= '<table>
                        <thead>
                            <tr>
                                <th>NO#</th>
                                <th>EQUIPMENT</th>
                                <th>UNITS</th>
                                <th>OPERATIONAL</th>
                                <th>UNDER-REPAIR</th>
                                <th>UNSERVICEABLE</th>
                            </tr>
                        </thead>
                        <tbody>';

                    $row_number = 1;
                    $totals = ['units' => 0, 'operational' => 0, 'under_repair' => 0, 'unserviceable' => 0];

                    while ($row = $result->fetch_assoc()) {
                        $totals['units'] += $row['units'];
                        $totals['operational'] += $row['operational'] ?? 0;
                        $totals['under_repair'] += $row['under_repair'] ?? 0;
                        $totals['unserviceable'] += $row['unserviceable'] ?? 0;

                        $html .= "<tr>
                            <td>$row_number</td>
                            <td style='text-align: left;'>" . htmlspecialchars($row['equipment_name'] ?? 'N/A') . "</td>
                            <td>{$row['units']}</td>
                            <td>" . ($row['operational'] ?? 0) . "</td>
                            <td>" . ($row['under_repair'] ?? 0) . "</td>
                            <td>" . ($row['unserviceable'] ?? 0) . "</td>
                        </tr>";
                        $row_number++;
                    }

                    $html .= "<tr class='total-row'>
                        <td colspan='2'>TOTAL</td>
                        <td>{$totals['units']}</td>
                        <td>{$totals['operational']}</td>
                        <td>{$totals['under_repair']}</td>
                        <td>{$totals['unserviceable']}</td>
                    </tr>";
                    $html .= '</tbody></table>';
                } else {
                    $html .= "<p>No $equip_type found in $location</p>";
                }
            }
        }
    } else {
        $html .= '<h1>SUMMARY STATUS REPORT</h1>';
        $loc_filter = !empty($selected_location) ? $selected_location : null;

        $summary_tables = [
            ['title' => 'ALL EQUIPMENT ALLOCATION', 'data' => getEquipmentStats($mysqli, $loc_filter, null, false)],
            ['title' => 'HEAVY EQUIPMENT ALLOCATION', 'data' => getEquipmentStats($mysqli, $loc_filter, 'Heavy Equipment', false)],
            ['title' => 'LIGHT EQUIPMENT ALLOCATION', 'data' => getEquipmentStats($mysqli, $loc_filter, 'Light Equipment', false)]
        ];

        foreach ($summary_tables as $table) {
            $result = $table['data'];
            $html .= "<h3 class='subheader'>{$table['title']}</h3>";

            if ($result && $result->num_rows > 0) {
                $html .= '<table>
                    <thead>
                        <tr>
                            <th>LOCATION</th>
                            <th>TOTAL UNITS</th>
                            <th>OPERATIONAL</th>
                            <th>UNDER-REPAIR</th>
                            <th>UNSERVICEABLE</th>
                        </tr>
                    </thead>
                    <tbody>';

                $totals = ['units' => 0, 'operational' => 0, 'under_repair' => 0, 'unserviceable' => 0];

                while ($row = $result->fetch_assoc()) {
                    $totals['units'] += $row['units'];
                    $totals['operational'] += $row['operational'] ?? 0;
                    $totals['under_repair'] += $row['under_repair'] ?? 0;
                    $totals['unserviceable'] += $row['unserviceable'] ?? 0;

                    $html .= "<tr>
                        <td style='text-align: left;'>" . htmlspecialchars($row['location_name']) . "</td>
                        <td>{$row['units']}</td>
                        <td>" . ($row['operational'] ?? 0) . "</td>
                        <td>" . ($row['under_repair'] ?? 0) . "</td>
                        <td>" . ($row['unserviceable'] ?? 0) . "</td>
                    </tr>";
                }

                $html .= "<tr class='total-row'>
                    <td>TOTAL:</td>
                    <td>{$totals['units']}</td>
                    <td>{$totals['operational']}</td>
                    <td>{$totals['under_repair']}</td>
                    <td>{$totals['unserviceable']}</td>
                </tr>";
                $html .= '</tbody></table>';
            } else {
                $html .= "<p>No equipment data found</p>";
            }
        }
    }

    header('Content-Type: text/html');
    echo $html;
    exit;
}
?>


<style>
    /* Reset and base styles */
    /* * {
        box-sizing: border-box;
        margin: 0;
        padding: 0;
    }

    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background: #f5f5f5;
        color: #333;
    } */
        
   

    /* Header and Tabs */
    .sticky-header {
        position: sticky;
        top: 90px;
        background: white;
        z-index: 100;
        border-bottom: 2px solid #e0e0e0;
    }

    .tab-container {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 1rem;
        background: white;
    }

    .tabs {
        display: flex;
        background: #f8f9fa;
        border-radius: 8px;
        border: 1px solid #dee2e6;
        overflow: hidden;
    }

    .tab-btn {
        padding: 0.75rem 1.5rem;
        background: transparent;
        border: none;
        border-right: 1px solid #dee2e6;
        font-size: 12px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #495057;
        cursor: pointer;
        transition: all 0.3s ease;
        white-space: nowrap;
    }

    .tab-btn:last-child {
        border-right: none;
    }

    .tab-btn:hover {
        background: #e9ecef;
    }

    .tab-btn.active {
        background: #28a745;
        color: white;
    }

    .pdf-btn {
        padding: 0.75rem 1.5rem;
        background: #dc3545;
        color: white;
        border: none;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        cursor: pointer;
        transition: background 0.3s ease;
        margin-left: 10px;
    }

    .pdf-btn:hover {
        background: #c82333;
    }

    .print-btn {
        padding: 0.75rem 1.5rem;
        background: #28a745;
        color: white;
        border: none;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        cursor: pointer;
        transition: background 0.3s ease;
    }

    .print-btn:hover {
        background: #218838;
    }

    /* Main Content */
    .main-content {
        max-width: 1400px;
        margin: 0 auto;
        padding: 2rem;
    }

    /* Tab Content */
    .tab-content {
        display: none;
        animation: fadeIn 0.3s ease;
    }

    .tab-content.active {
        display: block;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
        }

        to {
            opacity: 1;
        }
    }

    /* Section Headers */
    .section-header {
        text-align: left;
        margin-bottom: 2rem;
        padding-bottom: 1rem;
        border-bottom: 2px solid #1b0f0f;
    }

    .section-title {
        font-size: 22px;
        font-weight: bold;
        text-transform: uppercase;
        color: #333;
        margin: 0;
        padding: 0;
    }

    /* Tables */
    .report-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 2rem;
        background: white;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }

    .report-table th {
        background: #4caf50;
        color: white;
        font-weight: 600;
        text-align: center;
        padding: 12px;
        border: 1px solid #1b0f0f;
        font-size: 13px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .report-table td {
        padding: 12px;
        border: 1px solid #1b0f0f;
        text-align: center;
        font-size: 14px;
    }

    .report-table tbody tr:nth-child(even) {
        background: #fafafa;
    }

    .report-table tbody tr:hover {
        background: #f5f5f5;
    }

    .subheader-row {
        background: #ffc109 !important;
        color: black !important;
        font-size: 1.2rem !important;
        font-weight: bold !important;
    }

    .total-row {
        background: #f2f2f2 !important;
        font-weight: bold !important;
    }

    .no-data {
        text-align: center;
        padding: 2rem;
        color: #999;
        font-style: italic;
    }

    /* PRINT STYLES - UPDATED */
    @media print {

        /* Don't hide body - just hide non-printable elements */
        body {
            background: white !important;
            color: black !important;
            margin: 0 !important;
            padding: 20px !important;
        }

        /* Hide non-printable elements */
        .no-print {
            display: none !important;
        }

        /* Show only the active tab */
        .tab-content:not(.active) {
            display: none !important;
        }

        .tab-content.active {
            display: block !important;
            position: static !important;
        }

        /* Ensure tables look good when printed */
        .report-table {
            border: 2px solid #000 !important;
            border-collapse: collapse !important;
            width: 100% !important;
            margin-bottom: 20px !important;
            page-break-inside: avoid;
        }

        .report-table th,
        .report-table td {
            border: 1px solid #000 !important;
            padding: 8px !important;
            text-align: center !important;
        }

        .report-table th {
            background-color: #4caf50 !important;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
            color: white !important;
        }

        .subheader-row {
            background-color: #ffc109 !important;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        .total-row {
            background-color: #f2f2f2 !important;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        /* Section headers for print */
        .section-header {
            border-bottom: 2px solid #000 !important;
        }

        /* Page setup */
        @page {
            margin: 0.5in !important;
        }
    }

    /* Button container */
    .button-container {
        display: flex;
        gap: 10px;
        align-items: center;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .tab-container {
            flex-direction: column;
            gap: 1rem;
            align-items: stretch;
        }

        .tabs {
            flex-wrap: wrap;
        }

        .tab-btn {
            flex: 1;
            min-width: 120px;
            text-align: center;
        }

        .button-container {
            width: 100%;
            justify-content: center;
        }

        .main-content {
            padding: 1rem;
        }

        .report-table {
            font-size: 12px;
        }

        .report-table th,
        .report-table td {
            padding: 8px;
        }
    }

    @media (max-width: 480px) {
        .report-table {
            display: block;
            overflow-x: auto;
        }

        .section-title {
            font-size: 18px;
        }

        .button-container {
            flex-direction: column;
        }

        .pdf-btn,
        .print-btn {
            width: 100%;
        }
    }


    .tab-content {
        width: 100%;
        overflow-x: auto;

        -webkit-overflow-scrolling: touch;
    }

    .report-table {
        min-width: 600px;

    }




    /* Add this to your existing CSS */
    .main-content-wrapper {
        margin-left: 250px;
        /* Match sidebar width */
        padding: 20px;
        width: calc(100% - 250px);
        min-height: 100vh;
        background: #f5f5f5;
    }

    .container.mx-auto {
        max-width: 1400px;
        /* Or your preferred max width */
        margin-left: auto;
        margin-right: auto;
    }

    /* Ensure your sticky header respects the sidebar */
    .sticky-header {
        left: 250px;
        /* Same as sidebar width */
        right: 0;
        width: calc(100% - 250px);
    }

    /* Mobile responsiveness */
    @media (max-width: 768px) {
        .main-content-wrapper {
            margin-left: 0 !important;
            width: 100% !important;
            padding: 10px !important;
        }

        .sticky-header {
            left: 0 !important;
            width: 100% !important;
        }
    }

    /* Print styles */
    @media print {
        .main-content-wrapper {
            margin-left: 0 !important;
            width: 100% !important;
            padding: 0 !important;
            background: white !important;
        }
    }
</style>

<!-- <div class="container mx-auto px-4 py-8"> -->

<div class="main-content-wrapper">
    <!-- Sticky Header with Tabs -->
    <div class="sticky-header no-print" style="width: auto;">
        <div class="tab-container">
            <div class="flex items-center gap-4">
                <div class="tabs">
                    <button class="tab-btn <?php echo ($filters['tab'] === 'pre-repair' ? 'active' : ''); ?>"
                        onclick="switchTab('pre-repair', this)" id="pre-repair-tab">
                        <span class="desktop-text">PRE-REPAIR INSPECTION</span>
                        <span class="mobile-text">PRE-REPAIR</span>
                    </button>
                    <button class="tab-btn <?php echo ($filters['tab'] === 'summary' ? 'active' : ''); ?>"
                        onclick="switchTab('summary', this)" id="summary-tab">
                        <span class="desktop-text">SUMMARY STATUS REPORT</span>
                        <span class="mobile-text">SUMMARY</span>
                    </button>
                </div>
                
                <!-- Location Filter Dropdown -->
                <div class="flex items-center gap-2 bg-slate-50 border border-slate-200 rounded-lg px-3 py-1">
                    <label for="locationFilter" class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Location:</label>
                    <select id="locationFilter" onchange="filterByLocation(this.value)" class="bg-transparent border-0 text-xs font-bold text-slate-700 focus:ring-0 cursor-pointer">
                        <option value="">ALL LOCATIONS</option>
                        <?php foreach ($locations as $loc): ?>
                            <option value="<?php echo htmlspecialchars($loc); ?>" <?php echo ($filters['location'] === $loc ? 'selected' : ''); ?>>
                                <?php echo strtoupper(htmlspecialchars($loc)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="button-container">
                <button class="print-btn" onclick="printReport()">
                    PRINT REPORT
                </button>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="container mx-auto px-6 py-8" style="max-width: 1400px;">

        <div id="pre-repair" class="tab-content <?php echo ($filters['tab'] === 'pre-repair' ? 'active' : ''); ?>">
            <h2 class="section-title font-semibold text-2xl mb-6">
                PRE-REPAIR INSPECTION REPORT
            </h2>

            <p class="clock text-xs text-black-400"></p>

            <script>
                setInterval(() => {
                    const now = new Date();
                    const timeStr = now.toLocaleDateString('en-US', {
                            month: 'short',
                            day: '2-digit',
                            year: 'numeric'
                        }) + " " +
                        now.toLocaleTimeString('en-US', {
                            hour: '2-digit',
                            minute: '2-digit'
                        });
                    document.querySelectorAll('.clock').forEach(el => el.innerText = timeStr);
                }, 1000);
            </script>

        <?php foreach ($target_locations as $location): ?>
                <div class="section-header flex justify-between items-center">

                    <!-- Left: Location name -->
                    <h3 class="section-title font-semibold">
                        <?php echo htmlspecialchars($location); ?>
                    </h3>
                </div>



                <?php foreach (['Heavy Equipment', 'Light Equipment'] as $equip_type):
                    $result = $equipment_data[$location][strtolower(explode(' ', $equip_type)[0])];
                    $row_number = 1;
                    $totals = ['units' => 0, 'operational' => 0, 'under_repair' => 0, 'unserviceable' => 0];
                    ?>
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th colspan="6" class="subheader-row"><?php echo strtoupper($equip_type); ?></th>
                            </tr>
                            <tr>
                                <th>NO#</th>
                                <th>EQUIPMENT</th>
                                <th>UNITS</th>
                                <th>OPERATIONAL</th>
                                <th>UNDER-REPAIR</th>
                                <th>UNSERVICEABLE</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($result && $result->num_rows > 0):
                                $result->data_seek(0);
                                while ($row = $result->fetch_assoc()):
                                    $totals['units'] += $row['units'];
                                    $totals['operational'] += $row['operational'] ?? 0;
                                    $totals['under_repair'] += $row['under_repair'] ?? 0;
                                    $totals['unserviceable'] += $row['unserviceable'] ?? 0;
                                    ?>
                                    <tr>
                                        <td><?php echo $row_number++; ?></td>
                                        <td style="text-align: left;">
                                            <?php echo htmlspecialchars($row['equipment_name'] ?? 'N/A'); ?>
                                        </td>
                                        <td><?php echo $row['units']; ?></td>
                                        <td><?php echo $row['operational'] ?? 0; ?></td>
                                        <td><?php echo $row['under_repair'] ?? 0; ?></td>
                                        <td><?php echo $row['unserviceable'] ?? 0; ?></td>
                                    </tr>
                                <?php endwhile; ?>
                                <tr class="total-row">
                                    <td colspan="2">TOTAL</td>
                                    <td><?php echo $totals['units']; ?></td>
                                    <td><?php echo $totals['operational']; ?></td>
                                    <td><?php echo $totals['under_repair']; ?></td>
                                    <td><?php echo $totals['unserviceable']; ?></td>
                                </tr>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="no-data">No <?php echo $equip_type; ?> found in
                                        <?php echo $location; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </div>

    <!-- SUMMARY STATUS TAB -->
    <div id="summary" class="tab-content <?php echo ($filters['tab'] === 'summary' ? 'active' : ''); ?>">
        <div class="section-header">
            <h3 class="section-title">SUMMARY INSPECTION REPORT</h3>

            <p class="clock text-xs text-black-400"></p>
        </div>




        <?php
        $summary_tables = [
            ['title' => 'ALL EQUIPMENT ALLOCATION', 'data' => $all_equipment_stats],
            ['title' => 'HEAVY EQUIPMENT ALLOCATION', 'data' => $heavy_equipment_stats],
            ['title' => 'LIGHT EQUIPMENT ALLOCATION', 'data' => $light_equipment_stats]
        ];

        foreach ($summary_tables as $table):
            $result = $table['data'];
            $totals = ['units' => 0, 'operational' => 0, 'under_repair' => 0, 'unserviceable' => 0];
            ?>
            <table class="report-table">
                <thead>
                    <tr>
                        <th colspan="5" class="subheader-row"><?php echo $table['title']; ?></th>
                    </tr>
                    <tr>
                        <th>LOCATION</th>
                        <th>TOTAL UNITS</th>
                        <th>OPERATIONAL</th>
                        <th>UNDER-REPAIR</th>
                        <th>UNSERVICEABLE</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result && $result->num_rows > 0):
                        $result->data_seek(0);
                        while ($row = $result->fetch_assoc()):
                            $totals['units'] += $row['units'];
                            $totals['operational'] += $row['operational'] ?? 0;
                            $totals['under_repair'] += $row['under_repair'] ?? 0;
                            $totals['unserviceable'] += $row['unserviceable'] ?? 0;
                            ?>
                            <tr>
                                <td style="text-align: left;"><?php echo htmlspecialchars($row['location_name']); ?></td>
                                <td><?php echo $row['units']; ?></td>
                                <td><?php echo $row['operational'] ?? 0; ?></td>
                                <td><?php echo $row['under_repair'] ?? 0; ?></td>
                                <td><?php echo $row['unserviceable'] ?? 0; ?></td>
                            </tr>
                        <?php endwhile; ?>
                        <tr class="total-row">
                            <td>TOTAL:</td>
                            <td><?php echo $totals['units']; ?></td>
                            <td><?php echo $totals['operational']; ?></td>
                            <td><?php echo $totals['under_repair']; ?></td>
                            <td><?php echo $totals['unserviceable']; ?></td>
                        </tr>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="no-data">No equipment data found</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        <?php endforeach; ?>
    </div>
</div>




<script>
    // Tab Management
    function switchTab(tabId, button) {
        // Hide all tabs
        document.querySelectorAll('.tab-content').forEach(tab => {
            tab.classList.remove('active');
        });

        // Remove active class from all buttons
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.classList.remove('active');
        });

        // Show selected tab and activate button
        const targetTab = document.getElementById(tabId);
        if (targetTab) {
            targetTab.classList.add('active');
        }
        button.classList.add('active');

        // Update URL without page reload
        const url = new URL(window.location);
        url.searchParams.set('tab', tabId);
        window.history.pushState({}, '', url);

        // Scroll to top
        window.scrollTo({
            top: 0,
            behavior: 'smooth'
        });
    }

    function filterByLocation(location) {
        const url = new URL(window.location);
        const currentTab = url.searchParams.get('tab') || 'pre-repair';
        const currentView = url.searchParams.get('view') || 'report';

        if (location) {
            url.searchParams.set('location', location);
        } else {
            url.searchParams.delete('location');
        }
        url.searchParams.set('tab', currentTab);

        // Update URL in browser
        window.history.pushState({}, '', url);

        // Reload content with filters
        if (typeof loadContent === 'function') {
            loadContent(`${currentView}&location=${location}&tab=${currentTab}`);
        } else {
            window.location.href = url.toString();
        }
    }

    /// IMPROVED PRINT FUNCTION
    function printReport() {
        // Create a new window for printing
        const printWindow = window.open('', '_blank');

        // Get the active tab content
        const activeTab = document.querySelector('.tab-content.active');

        if (!activeTab) {
            alert('No active tab found to print.');
            return;
        }

        // Clone the active tab content
        const printContent = activeTab.cloneNode(true);

        // Remove elements that shouldn't be printed
        const noPrintElements = printContent.querySelectorAll('.no-print');
        noPrintElements.forEach(el => el.remove());

        // Create the HTML for printing
        const printHTML = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>Equipment Report</title>
            <meta charset="UTF-8">
            <style>
                body {
                    font-family: Arial, sans-serif;
                    margin: 0.5in;
                    font-size: 10pt;
                }
                table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-bottom: 20px;
                    page-break-inside: avoid;
                }
                th, td {
                    border: 1px solid #000;
                    padding: 6px;
                    text-align: center;
                    font-size: 9pt;
                }
                th {
                    background-color: #4caf50 !important;
                    color: white !important;
                    font-weight: bold;
                    -webkit-print-color-adjust: exact;
                    print-color-adjust: exact;
                }
                .location-header {
                    font-size: 16pt;
                    font-weight: bold;
                    text-align: left;
                    margin: 20px 0 10px 0;
                    border-bottom: 2px solid #000;
                    padding-bottom: 5px;
                }
                .subheader-row {
                    background-color: #ffc109 !important;
                    font-weight: bold;
                    text-align: center;
                    font-size: 11pt;
                    -webkit-print-color-adjust: exact;
                    print-color-adjust: exact;
                }
                .total-row {
                    background-color: #f2f2f2 !important;
                    font-weight: bold;
                    -webkit-print-color-adjust: exact;
                    print-color-adjust: exact;
                }
                .no-data {
                    text-align: center;
                    padding: 10px;
                    font-style: italic;
                    color: #666;
                }
                @page {
                    margin: 0.5in;
                    size: letter portrait;
                }
                @media print {
                    body { margin: 0; }
                    table { 
                        border: 1px solid #000 !important; 
                    }
                    th, td { 
                        border: 1px solid #000 !important; 
                    }
                }
            </style>
        </head>
        <body>
            ${printContent.innerHTML}
        </body>
        </html>
    `;

        // Write the content to the new window
        printWindow.document.open();
        printWindow.document.write(printHTML);
        printWindow.document.close();

        // Wait for content to load, then print
        printWindow.onload = function () {
            setTimeout(() => {
                printWindow.print();
                printWindow.close();
            }, 500);
        };
    }

    // Updated generatePDF function
    function generatePDF() {
        const activeTab = document.querySelector('.tab-content.active');
        if (!activeTab) {
            alert('No active tab found to generate PDF.');
            return;
        }

        const tabId = activeTab.id;

        // Open PDF generation in new window
        window.open(`?action=pdf&tab=${tabId}`, '_blank');
    }
    // Handle print event to restore page after printing
    window.addEventListener('afterprint', function () {
        document.body.classList.remove('printing');
    });

    // Mobile-responsive text handling
    function handleResponsiveText() {
        const width = window.innerWidth;
        const desktopTexts = document.querySelectorAll('.desktop-text');
        const mobileTexts = document.querySelectorAll('.mobile-text');

        if (width < 768) {
            desktopTexts.forEach(el => el.style.display = 'none');
            mobileTexts.forEach(el => el.style.display = 'inline');
        } else {
            desktopTexts.forEach(el => el.style.display = 'inline');
            mobileTexts.forEach(el => el.style.display = 'none');
        }
    }

    // Initialize
    document.addEventListener('DOMContentLoaded', function () {
        handleResponsiveText();

        // Set initial active tab if none is active
        if (!document.querySelector('.tab-content.active')) {
            const firstTab = document.querySelector('.tab-content');
            const firstBtn = document.querySelector('.tab-btn');
            if (firstTab && firstBtn) {
                firstTab.classList.add('active');
                firstBtn.classList.add('active');
            }
        }

        // Handle browser back/forward buttons
        window.addEventListener('popstate', function () {
            const urlParams = new URLSearchParams(window.location.search);
            const tab = urlParams.get('tab') || 'pre-repair';
            const tabBtn = document.getElementById(tab + '-tab');
            if (tabBtn) {
                switchTab(tab, tabBtn);
            }
        });
    });

    // Handle window resize
    window.addEventListener('resize', handleResponsiveText);


</script>


<?php
if (isset($mysqli)) {
    $mysqli->close();
}