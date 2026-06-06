<?php
require_once __DIR__ . '/../config.php';

// Redirect checks
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'user') {
    header("Location: ../index.php?login");
    exit();
}

// Fetch user details
$userId = $_SESSION['user_id'];
$userStmt = $mysqli->prepare("SELECT username, email, status, role, signature FROM users WHERE id = ?");
if (!$userStmt) {
    $userStmt = $mysqli->prepare("SELECT username, email, status, role FROM users WHERE id = ?");
}
if (!$userStmt) {
    die("Database Error: " . $mysqli->error);
}
$userStmt->bind_param("i", $userId);
$userStmt->execute();
$currentUser = $userStmt->get_result()->fetch_assoc();
$userStmt->close();

// ================= SIMPLIFIED NOTIFICATION SYSTEM =================
$notificationCount = 0;
$notifications = [];
$username = $currentUser['username'] ?? '';

// Check and add notification_seen column if it doesn't exist
$checkColumn = $mysqli->query("SHOW COLUMNS FROM documents LIKE 'notification_seen'");
if ($checkColumn->num_rows == 0) {
    $mysqli->query("ALTER TABLE documents ADD COLUMN notification_seen TINYINT(1) DEFAULT 0");
}

// SIMPLIFIED: Fetch notifications for the current user
try {
    $cleanUsername = trim($username);

    $countQuery = "SELECT COUNT(*) as approved_count 
                    FROM documents 
                    WHERE (officer_name = ? OR officer_name LIKE ?) 
                    AND status IN ('Approved', 'APPROVED', 'Archived')
                    AND (notification_seen = 0 OR notification_seen IS NULL)";

    $countStmt = $mysqli->prepare($countQuery);
    if ($countStmt) {
        $searchExact = $cleanUsername;
        $searchLike = "%" . $cleanUsername . "%";
        $countStmt->bind_param("ss", $searchExact, $searchLike);
        $countStmt->execute();
        $countResult = $countStmt->get_result()->fetch_assoc();
        $notificationCount = $countResult['approved_count'] ?? 0;
        $countStmt->close();
    }

    $notificationQuery = "SELECT 
            id, 
            officer_name,
            pre_repair_no,
            property_no,
            equipment,
            date_requested,
            status,
            location,
            notification_seen
        FROM documents 
        WHERE (officer_name = ? OR officer_name LIKE ?) 
        AND status IN ('Approved', 'APPROVED', 'Archived')
        AND (notification_seen = 0 OR notification_seen IS NULL)
        ORDER BY date_requested DESC
        LIMIT 10";

    $notificationStmt = $mysqli->prepare($notificationQuery);
    if ($notificationStmt) {
        $searchExact = $cleanUsername;
        $searchLike = "%" . $cleanUsername . "%";
        $notificationStmt->bind_param("ss", $searchExact, $searchLike);
        $notificationStmt->execute();
        $notificationResult = $notificationStmt->get_result();

        while ($row = $notificationResult->fetch_assoc()) {
            $notifications[] = $row;
        }
        $notificationStmt->close();
    }

} catch (Exception $e) {
    error_log("Error fetching notification data: " . $e->getMessage());
}

// Mark notifications as read when requested
if (isset($_GET['mark_notifications_read']) && $_GET['mark_notifications_read'] == 1) {
    $cleanUsername = trim($username);
    $markReadQuery = "UPDATE documents 
                        SET notification_seen = 1 
                        WHERE (officer_name = ? OR officer_name LIKE ?) 
                        AND status IN ('Approved', 'APPROVED', 'Archived')
                        AND (notification_seen = 0 OR notification_seen IS NULL)";

    $markReadStmt = $mysqli->prepare($markReadQuery);
    if ($markReadStmt) {
        $searchExact = $cleanUsername;
        $searchLike = "%" . $cleanUsername . "%";
        $markReadStmt->bind_param("ss", $searchExact, $searchLike);
        if ($markReadStmt->execute()) {
            $notificationCount = 0;
            $notifications = [];
        }
        $markReadStmt->close();

        $redirectUrl = "user_dashboard.php?view=" . ($_GET['view'] ?? 'dashboard');
        header("Location: " . $redirectUrl);
        exit();
    }
}

// View Handling
$allowedViews = ['dashboard', 'scan', 'request', 'appointment'];
$view = $_GET['view'] ?? 'dashboard';
if (!in_array($view, $allowedViews, true)) {
    $view = '404';
}

// === DASHBOARD LOGIC ===
$vehicle_counts = ['Mamburao' => 0, 'San Jose' => 0, 'Sablayan' => 0, 'Lubang' => 0];
$pendingPct = 0;
$ongoingPct = 0;
$donePct = 0;
$locations = [];
$repairData = ['Under repair' => [], 'Operational' => [], 'Unserviceable' => []];

if ($view === 'dashboard') {
    // Vehicle Count
    $stmt = $mysqli->prepare("SELECT location, COUNT(*) AS total FROM equipment WHERE location IN ('Mamburao','San Jose','Sablayan','Lubang') GROUP BY location");
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $vehicle_counts[$row['location']] = (int) $row['total'];
        }
        $stmt->close();
    }

    // Document Counts - Wrap function declaration to prevent redeclaration
    if (!function_exists('getDocCount')) {
        function getDocCount($mysqli, $status)
        {
            $stmt = $mysqli->prepare("SELECT COUNT(*) as c FROM documents WHERE status=?");
            $stmt->bind_param("s", $status);
            $stmt->execute();
            $res = $stmt->get_result()->fetch_assoc();
            return (int) ($res['c'] ?? 0);
        }
    }
    // Status Counts

    $pending = getDocCount($mysqli, 'PENDING');
    $ongoing = getDocCount($mysqli, 'APPROVED');
    $done = getDocCount($mysqli, 'DONE');
    $complete = getDocCount($mysqli, 'COMPLETE'); // <--- NEW LINE

    $totalDocs = $pending + $ongoing + $done + $complete;

    $pendingPct = 0;
    $ongoingPct = 0;
    $donePct = 0;
    $completePct = 0; // <--- NEW LINE

    if ($totalDocs > 0) {
        $pendingPct = round(($pending / $totalDocs) * 100);
        $ongoingPct = round(($ongoing / $totalDocs) * 100);
        $donePct = round(($done / $totalDocs) * 100);
        $completePct = round(($complete / $totalDocs) * 100); // <--- NEW LINE
    }

    // Repair Inspection Data
    $stmt = $mysqli->prepare("SELECT DISTINCT location FROM equipment ORDER BY location");
    if ($stmt) {
        $stmt->execute();
        $resLoc = $stmt->get_result();
        while ($r = $resLoc->fetch_assoc()) {
            $locations[] = $r['location'];
        }
        $stmt->close();
    }

    foreach (['Under repair', 'Operational', 'Unserviceable'] as $status) {
        foreach ($locations as $loc) {
            $stmt = $mysqli->prepare("SELECT COUNT(*) as cnt FROM equipment WHERE location=? AND status=?");
            $stmt->bind_param("ss", $loc, $status);
            $stmt->execute();
            $repairData[$status][] = (int) $stmt->get_result()->fetch_assoc()['cnt'];
            $stmt->close();
        }
    }
}
;
?>

<style>
    /* --- Modern Dashboard Styling --- */

    .dashboard-card {
        background: #fff;
        border-radius: 16px;
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
        border: 1px solid #f1f5f9;
        transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1), box-shadow 0.3s ease;
        height: 100%;
        overflow: hidden;
    }

    .dashboard-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 24px rgba(0, 0, 0, 0.08);
        border-color: #e2e8f0;
    }

    /* Scrollable Container */
    .scrollable-container {
        max-height: 400px;
        overflow-y: auto;
        overflow-x: hidden;
        padding-right: 6px;
    }

    /* Modern Slim Scrollbar */
    .scrollable-container::-webkit-scrollbar {
        width: 5px;
    }

    .scrollable-container::-webkit-scrollbar-track {
        background: transparent;
    }

    .scrollable-container::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 10px;
    }

    .scrollable-container::-webkit-scrollbar-thumb:hover {
        background: #94a3b8;
    }

    /* Timeline Style Activity List */
    .activity-timeline .list-group-item {
        border: none;
        padding: 1rem 0;
        position: relative;
        padding-left: 1.5rem;
    }

    .activity-timeline .list-group-item::before {
        content: '';
        position: absolute;
        left: 0;
        top: 1.5rem;
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background-color: #10b981;
        /* Green */
    }

    .activity-timeline .list-group-item:not(:last-child)::after {
        content: '';
        position: absolute;
        left: 3px;
        top: 2rem;
        bottom: -1rem;
        width: 2px;
        background-color: #f1f5f9;
    }

    /* Mobile Calendar Fixes */
    @media (max-width: 768px) {
        .fc .fc-toolbar {
            flex-direction: column;
            gap: 10px;
        }

        .fc .fc-toolbar-title {
            font-size: 1.2rem !important;
        }

        .fc .fc-toolbar-chunk {
            display: flex;
            justify-content: space-between;
            width: 100%;
        }

        .fc-event-title {
            font-size: 0.75rem !important;
        }
    }

    .dashboard-card {
        word-wrap: break-word;
        overflow-wrap: break-word;
    }
</style>

<?php if ($view === 'dashboard'): ?>
    <!-- <div class="container-fluid p-0"> -->
    <div class="container-fluid px-2 px-sm-3 px-md-4">
        <div class="row g-4 mb-4">
            <div class="col-12 col-md-6 col-lg-3 animate-card delay-1">
                <div class="dashboard-card overflow-hidden border-0 shadow-sm h-100">
                    <div class="p-4 bg-white d-flex align-items-center justify-content-between">
                        <div>
                            <p class="text-uppercase text-slate-400 fw-bold text-xs tracking-wider mb-1">Total Fleet</p>
                            <h2 class="display-5 fw-bolder text-slate-800 mb-0 tracking-tight">
                                <?= array_sum($vehicle_counts) ?>
                            </h2>
                        </div>
                        <div class="rounded-circle bg-emerald-50 p-3 d-flex align-items-center justify-content-center"
                            style="width: 60px; height: 60px;">
                            <i class="fa fa-car text-emerald-500 fs-3"></i>
                        </div>
                    </div>

                    <div class="bg-slate-50 p-3 border-top border-slate-100 h-100">
                        <div class="row g-2">
                            <?php
                            // Custom labels for better readability
                            $locLabels = [
                                'Mamburao' => 'MAM',
                                'San Jose' => 'SJ',
                                'Sablayan' => 'SBY',
                                'Lubang' => 'LUB'
                            ];

                            foreach ($vehicle_counts as $loc => $count):
                                $label = $locLabels[$loc] ?? substr($loc, 0, 3); // Fallback to substring if not in list
                                // Dim zero counts slightly
                                $opacityClass = ($count == 0) ? 'opacity-50' : '';
                                $badgeClass = ($count > 0) ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-500';
                                ?>
                                <div class="col-6">
                                    <div
                                        class="bg-white px-3 py-2 rounded-3 border border-slate-100 shadow-sm d-flex align-items-center justify-content-between <?= $opacityClass ?>">
                                        <span class="text-xs fw-bold text-slate-600"><?= $label ?></span>
                                        <span class="badge <?= $badgeClass ?> rounded-pill font-monospace"><?= $count ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

            </div>

            <div class="col-12 col-md-6 col-lg-4 animate-card delay-2">
                <div class="dashboard-card p-4 d-flex flex-column">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h6 class="fw-bold text-slate-800 m-0">Repair and Maintenance Request</h6>
                        <select class="form-select form-select-sm" style="width: 130px; font-size: 0.8rem;"
                            onchange="updateDocumentChart(this.value)">
                            <option value="">All Locations</option>
                            <?php
                            // Define the exact locations you want to see
                            $targetLocations = ['Mamburao', 'Sablayan', 'San Jose', 'Lubang'];

                            foreach ($targetLocations as $loc) {
                                echo '<option value="' . htmlspecialchars($loc) . '">' . htmlspecialchars($loc) . '</option>';
                            }
                            ?>
                        </select>
                    </div>

                    <div class="flex-grow-1 position-relative" style="min-height: 200px;">
                        <canvas id="documentChart"></canvas>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-5 animate-card delay-3">
                <div class="dashboard-card p-4 d-flex flex-column">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h6 class="fw-bold text-slate-800 m-0">Equipment Status Report</h6>
                        <button class="btn btn-sm text-slate-400 hover:text-emerald-600 p-0"><i
                                class="fas fa-external-link-alt"></i></button>
                    </div>
                    <div class="flex-grow-1 position-relative" style="min-height: 200px;">
                        <canvas id="repairChart"></canvas>
                    </div>
                </div>
            </div>
        </div>



        <div class="col-12 col-xl-12 animate-card delay-1">
            <div class="dashboard-card p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="fw-bold text-slate-800 m-0">Activity Calendar</h6>
                    <div class="d-flex align-items-center gap-2">
                        <label class="small fw-bold text-slate-500">Filter:</label>
                        <select id="calendarLocationFilter" class="form-select form-select-sm" style="width: 150px;"
                            onchange="loadCalendarEvents(this.value)">
                            <option value="">All Locations</option>
                            <?php
                            // Ensure $mysqli is available
                            if (isset($mysqli)) {
                                $locStmt = $mysqli->prepare("SELECT DISTINCT location FROM activities ORDER BY location ASC");
                                $locStmt->execute();
                                $locRes = $locStmt->get_result();
                                while ($l = $locRes->fetch_assoc()) {
                                    echo '<option value="' . htmlspecialchars($l['location']) . '">' . htmlspecialchars($l['location']) . '</option>';
                                }
                            }
                            ?>
                        </select>
                    </div>
                </div>
                <div id="userCalendar" style="min-height: 600px;"></div>
            </div>
        </div>
    </div>
<?php elseif ($view === 'scan'): ?>
    <?php include "side_scan.php"; ?>

<?php elseif ($view === 'request'): ?>
    <?php include "side_request.php"; ?>

<?php elseif ($view === 'appointment'): ?>
    <?php include "appointment.php"; ?>

<?php else: ?>
    <div class="alert alert-danger">Page not found.</div>
<?php endif; ?>

<div class="modal fade" id="viewActivityModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow-lg">
            <div class="modal-header bg-emerald-600 text-white border-0">
                <h5 class="modal-title fw-bold">Activity Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-1">Type</label>
                    <input type="text" id="activity_type"
                        class="form-control bg-slate-50 border-slate-200 fw-semibold text-slate-700" disabled>
                </div>
                <div class="mb-3">
                    <label class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-1">Property No.</label>
                    <input type="text" id="property_no" class="form-control bg-slate-50 border-slate-200" disabled>
                </div>
                <div class="mb-3">
                    <label class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-1">Location</label>
                    <input type="text" id="activity_location" class="form-control bg-slate-50 border-slate-200"
                        disabled>
                </div>
                <div class="row g-3">
                    <div class="col-6">
                        <label class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-1">Date</label>
                        <input type="text" id="activity_date" class="form-control bg-slate-50 border-slate-200"
                            disabled>
                    </div>
                    <div class="col-6">
                        <label class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-1">Time</label>
                        <input type="text" id="activity_time" class="form-control bg-slate-50 border-slate-200"
                            disabled>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-light text-slate-600 font-medium px-4"
                    data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="profileModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow-lg">
            <div class="modal-header bg-emerald-600 text-white border-0">
                <h5 class="modal-title fw-bold">Edit Profile</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="../process.php" enctype="multipart/form-data">
                <div class="modal-body p-4">
                    <input type="hidden" name="redirect_to"
                        value="users/user_dashboard.php?view=<?= htmlspecialchars($view) ?>">

                    <div class="mb-4">
                        <label class="form-label text-sm fw-bold text-slate-700">Username</label>
                        <input type="text" name="username"
                            value="<?= htmlspecialchars($currentUser['username'] ?? '') ?>" class="form-control"
                            required>
                    </div>

                    <div class="mb-4">
                        <label class="form-label text-sm fw-bold text-slate-700">Digital Signature</label>
                        <?php if (!empty($currentUser['signature'])): ?>
                            <div class="mb-2 text-center p-3 border border-slate-200 border-dashed rounded-xl bg-slate-50">
                                <img src="../<?= htmlspecialchars($currentUser['signature']) ?>" alt="Current Signature"
                                    style="max-height: 60px;">
                            </div>
                        <?php endif; ?>
                        <input type="file" name="signature" class="form-control" accept="image/*">
                        <div class="form-text text-slate-400 small">Upload a PNG/JPG image of your signature.</div>
                    </div>

                    <div class="border-t border-slate-100 my-4"></div>
                    <h6 class="text-emerald-600 fw-bold mb-3">Security</h6>

                    <div class="mb-3">
                        <input type="password" name="current_password" class="form-control"
                            placeholder="Current Password">
                    </div>
                    <div class="row g-3">
                        <div class="col-6">
                            <input type="password" name="password" class="form-control" placeholder="New Password"
                                minlength="8" maxlength="8">
                        </div>
                        <div class="col-6">
                            <input type="password" name="confirm_password" class="form-control"
                                placeholder="Confirm Password" minlength="8" maxlength="8">
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-slate-50 border-0">
                    <button type="button" class="btn btn-light text-slate-600 font-medium"
                        data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update" class="btn btn-success bg-emerald-600 border-0 px-4">Save
                        Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // 1. Safe Data Injection from PHP
    let dashboardData = {
        pending: <?= $pendingPct ?? 0 ?>,
        approved: <?= $ongoingPct ?? 0 ?>,
        done: <?= $donePct ?? 0 ?>,
        complete: <?= $completePct ?? 0 ?>, // <--- NEW DATA POINT
        locations: <?= json_encode($locations ?? []) ?>,
        repair: <?= json_encode($repairData['Under repair'] ?? []) ?>,
        operational: <?= json_encode($repairData['Operational'] ?? []) ?>,
        unserviceable: <?= json_encode($repairData['Unserviceable'] ?? []) ?>
    };

    // 2. Initialize Charts
    function initCharts() {
        // Document Chart
        const docCtx = document.getElementById('documentChart');
        if (docCtx) {
            const existing = Chart.getChart(docCtx);
            if (existing) existing.destroy();

            new Chart(docCtx, {
                type: 'doughnut',
                data: {
                    // Added 'Complete' to labels
                    labels: ['Pending', 'Approved', 'Done', 'Complete'],
                    datasets: [{
                        // Added dashboardData.complete to data array
                        data: [
                            dashboardData.pending,
                            dashboardData.approved,
                            dashboardData.done,
                            dashboardData.complete
                        ],
                        // Added Purple color (#8b5cf6) for Complete
                        backgroundColor: ['#3b82f6', '#f59e0b', '#10b981', '#8b5cf6'],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 20,
                                usePointStyle: true
                            }
                        }
                    },
                    layout: { padding: 10 }
                }
            });
        }

        // Repair Chart
        const repairCtx = document.getElementById('repairChart');
        if (repairCtx) {
            const existingRep = Chart.getChart(repairCtx);
            if (existingRep) existingRep.destroy();

            new Chart(repairCtx, {
                type: 'bar',
                data: {
                    labels: dashboardData.locations,
                    datasets: [
                        { label: 'Repair', data: dashboardData.repair, backgroundColor: '#3b82f6' },
                        { label: 'Operational', data: dashboardData.operational, backgroundColor: '#10b981' },
                        { label: 'Unserviceable', data: dashboardData.unserviceable, backgroundColor: '#ef4444' }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: { y: { beginAtZero: true } }
                }
            });
        }
    }



    // 3. Initialize Calendar
    window.loadCalendarEvents = function (locationFilter = '') {
        const calendarEl = document.getElementById('userCalendar');
        if (!calendarEl) return;

        let url = 'fetch_activities.php'; // Adjusted for relative path from user_dashboard.php

        if (locationFilter) {
            url += '?location=' + encodeURIComponent(locationFilter);
        }

        fetch(url)
            .then(response => {
                if (!response.ok) throw new Error("HTTP error " + response.status);
                return response.json();
            })
            .then(events => {
                // Destroy old instance to avoid duplicates
                if (window.userCalendarInstance) {
                    window.userCalendarInstance.destroy();
                }

                var isMobile = window.innerWidth < 768;
                window.userCalendarInstance = new FullCalendar.Calendar(calendarEl, {
                    initialView: isMobile ? 'listMonth' : 'dayGridMonth',
                    height: 'auto',
                    headerToolbar: {
                        left: 'prev,next today',
                        center: 'title',
                        right: 'dayGridMonth,listMonth'
                    },
                    buttonText: { dayGridMonth: 'Grid', listMonth: 'List', today: 'Today' },
                    events: events,
                    eventColor: '#10b981',
                    eventClick: function (info) {
                        showUserActivityDetails(info.event.id);
                    }
                });
                window.userCalendarInstance.render();
            })
            .catch(err => {
                console.error("Calendar Load Error:", err);
                calendarEl.innerHTML = `<div class="alert alert-danger">Error loading data: ${err.message}</div>`;
            });
    };

    // 4. Show Details Modal
    function showUserActivityDetails(id) {
        fetch('fetch_single_activity.php?id=' + id)
            .then(r => r.json())
            .then(data => {
                if (!data || data.error) return alert('Details not found');

                // Safe Set Value function
                const setVal = (id, val) => {
                    const el = document.getElementById(id);
                    if (el) el.value = val || '';
                };

                setVal('activity_type', data.activity_type);
                setVal('property_no', data.property_no);
                setVal('activity_location', data.location);
                setVal('activity_date', data.activity_date);
                setVal('activity_time', data.activity_time);

                // Use the modal from user_dashboard.php if it exists, otherwise use local
                const modalEl = document.getElementById('viewActivityModal');
                if (modalEl) {
                    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
                    modal.show();
                }
            })
            .catch(e => console.error("Detail Error:", e));
    }

    // Initialize everything
    function initDashboard() {
        initCharts();
        if (document.getElementById('userCalendar')) {
            // Check if FullCalendar is loaded, if not wait a bit
            if (typeof FullCalendar === 'undefined') {
                setTimeout(initDashboard, 100);
            } else {
                loadCalendarEvents();
            }
        }
    }

    // Run on load
    if (document.readyState === 'complete') {
        initDashboard();
    } else {
        window.addEventListener('load', initDashboard);
    }

    // Support for AJAX navigation re-init
    window.initDashboardOnAjax = initDashboard;


    // Add this function to your existing <script> block
    window.updateDocumentChart = function (location) {
        // Fetch new data
        fetch('fetch_document_status.php?location=' + encodeURIComponent(location))
            .then(response => response.json())
            .then(data => {
                const chart = Chart.getChart('documentChart');
                if (chart) {
                    // Update chart data
                    chart.data.datasets[0].data = [
                        data.Pending,
                        data.Approved,
                        data.Done,
                        data.Complete
                    ];
                    chart.update(); // Redraw the chart
                }
            })
            .catch(err => console.error('Error updating chart:', err));
    };


    // === DASHBOARD INITIALISATION FUNCTIONS ===
    function initDashboardCharts() {
        // 1. Document status chart (Doughnut)
        const docCtx = document.getElementById('documentChart');
        if (docCtx) {
            const existing = Chart.getChart(docCtx);
            if (existing) existing.destroy();

            new Chart(docCtx, {
                type: 'doughnut',
                // Register the plugin specifically for this chart
                plugins: [ChartDataLabels],
                data: {
                    labels: ['Pending', 'Approved', 'Done', 'Complete'],
                    datasets: [{
                        data: [
                            <?= $pendingPct ?? 0 ?>,
                            <?= $ongoingPct ?? 0 ?>,
                            <?= $donePct ?? 0 ?>,
                            <?= $completePct ?? 0 ?>
                        ],
                        backgroundColor: ['#0d6efd', '#f59e0b', '#10b981', '#8b5cf6'],
                        borderWidth: 2,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { usePointStyle: true, padding: 20, font: { size: 11 } }
                        },
                        // --- NEW: Percentage Labels Configuration ---
                        datalabels: {
                            color: '#fff',
                            font: {
                                weight: 'bold',
                                size: 12
                            },
                            formatter: (value, ctx) => {
                                // Calculate percentage based on the dataset sum
                                let sum = 0;
                                let dataArr = ctx.chart.data.datasets[0].data;
                                dataArr.map(data => { sum += Number(data); });

                                // Prevent division by zero
                                if (sum === 0) return '';

                                let percentage = (value * 100 / sum).toFixed(1) + "%";

                                // Only show label if value is greater than 0
                                return (value > 0) ? percentage : '';
                            },
                            // Add a slight drop shadow for better readability on lighter colors
                            textShadowBlur: 4,
                            textShadowColor: 'rgba(0, 0, 0, 0.3)'
                        }
                    },
                    layout: { padding: { top: 0, bottom: 10, left: 10, right: 10 } },
                    cutout: '65%' // Makes the doughnut slightly thinner for a modern look
                }
            });
        }

        // 2. Pre‑repair inspection chart (Bar) - No changes needed here
        const repairCtx = document.getElementById('repairChart');
        if (repairCtx) {
            const existingRep = Chart.getChart(repairCtx);
            if (existingRep) existingRep.destroy();

            new Chart(repairCtx, {
                type: 'bar',
                data: {
                    labels: <?= json_encode($locations ?? []) ?>,
                    datasets: [
                        { label: 'Under Repair', data: <?= json_encode($repairData['Under repair'] ?? []) ?>, backgroundColor: '#0d6efd' },
                        { label: 'Operational', data: <?= json_encode($repairData['Operational'] ?? []) ?>, backgroundColor: '#198754' },
                        { label: 'Unserviceable', data: <?= json_encode($repairData['Unserviceable'] ?? []) ?>, backgroundColor: '#dc3545' }
                    ]
                },
                options: {
                    responsive: true,
                    scales: { y: { beginAtZero: true } },
                    plugins: {
                        // Disable datalabels for the bar chart if you prefer it clean
                        datalabels: { display: false }
                    }
                }
            });
        }
    }


    function initDashboardCalendar() {
        if (document.getElementById('userCalendar')) {
            // loadCalendarEvents is defined in the main layout – ensure it's global
            loadCalendarEvents();
        }
    }

    // Auto‑run on initial page load
    document.addEventListener('DOMContentLoaded', function () {
        initDashboardCharts();
        initDashboardCalendar();
    });

    // Expose globally so AJAX can call them
    window.initDashboardCharts = initDashboardCharts;
    window.initDashboardCalendar = initDashboardCalendar;
</script>

<?php ob_end_flush(); ?>