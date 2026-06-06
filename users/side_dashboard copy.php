    <?php
    // ob_start();
    // require_once __DIR__ . '/../includes/session_helper.php';
    // start_user_session();

    // Database connection
    $mysqli = new mysqli('localhost', 'root', '', 'user_management');
    if ($mysqli->connect_error) {
        error_log("Database connection failed: " . $mysqli->connect_error);
        exit("Database connection error. Please try again later.");
    }

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
                    AND status IN ('Approved', 'APPROVED')
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
        AND status IN ('Approved', 'APPROVED')
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
                        AND status IN ('Approved', 'APPROVED')
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
    $allowedViews = ['dashboard', 'scan', 'request','appointment'];
    $view = $_GET['view'] ?? 'dashboard';
    if (!in_array($view, $allowedViews, true)) {
        $view = '404';
    }

    // === DASHBOARD LOGIC ===
    $vehicle_counts = ['Mamburao' => 0, 'San Jose' => 0, 'Sablayan' => 0, 'Lubang' => 0];
    $pendingPct = 0; $ongoingPct = 0; $donePct = 0;
    $locations = [];
    $repairData = ['Under repair' => [], 'Operational' => [], 'Unserviceable' => []];

    if ($view === 'dashboard') {
        // Vehicle Count
        $stmt = $mysqli->prepare("SELECT location, COUNT(*) AS total FROM equipment WHERE location IN ('Mamburao','San Jose','Sablayan','Lubang') GROUP BY location");
        if ($stmt) {
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $vehicle_counts[$row['location']] = (int)$row['total'];
            }
            $stmt->close();
        }

        // Document Counts - Wrap function declaration to prevent redeclaration
        if (!function_exists('getDocCount')) {
            function getDocCount($mysqli, $status) {
                $stmt = $mysqli->prepare("SELECT COUNT(*) as c FROM documents WHERE status=?");
                $stmt->bind_param("s", $status);
                $stmt->execute();
                $res = $stmt->get_result()->fetch_assoc();
                return (int)($res['c'] ?? 0);
            }
        }

        $pending = getDocCount($mysqli, 'PENDING');
        $ongoing = getDocCount($mysqli, 'APPROVED');
        $done    = getDocCount($mysqli, 'DONE');
        $totalDocs = $pending + $ongoing + $done;
        
        if ($totalDocs > 0) {
            $pendingPct = round(($pending / $totalDocs) * 100);
            $ongoingPct = round(($ongoing / $totalDocs) * 100);
            $donePct    = round(($done / $totalDocs) * 100);
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
                $repairData[$status][] = (int)$stmt->get_result()->fetch_assoc()['cnt'];
                $stmt->close();
            }
        }
    }
    ?>

    <!-- Main Content Only - No HTML, HEAD, BODY tags -->



    <?php if ($view === 'dashboard'): ?>
    <div class="row g-4 mb-4">
        <div class="col-lg-3 col-md-6">
            <div class="dashboard-card p-4 text-center d-flex flex-column align-items-center justify-content-center">
                <div class="rounded-circle bg-success bg-opacity-10 p-3 mb-3 d-flex align-items-center justify-content-center"
                    style="width:80px; height:80px;">
                    <i class="fa fa-car fa-2x text-success"></i>
                </div>
                <h2 class="fw-bold mb-0"><?= array_sum($vehicle_counts) ?></h2>
                <p class="text-muted">Total Vehicles</p>
                <div class="small text-muted w-100 mt-2 pt-2 border-top">
                    <?php foreach ($vehicle_counts as $loc => $count): ?>
                    <span class="d-inline-block mx-1"><?= substr($loc,0,3) ?>: <strong><?= $count ?></strong></span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-4 col-md-6">
            <div class="dashboard-card p-4">
                <h6 class="fw-bold text-center mb-3">Document Status</h6>
                <div style="height: 200px; position: relative;">
                    <canvas id="documentChart"></canvas>
                </div>
            </div>
        </div>

        <div class="col-lg-5 col-md-12">
            <div class="dashboard-card p-4">
                <h6 class="fw-bold text-center mb-3">Pre-Repair Inspection</h6>
                <div style="height: 200px; position: relative;">
                    <canvas id="repairChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4 p-4">
        <div id="userCalendar"></div>
        <div id="userActivityList" class="mt-3"></div>
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
    </div>

    <!-- Modals should remain in main layout, but include them here for completeness -->
    <div class="modal fade" id="viewActivityModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content rounded-4 border-0">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">Activity Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-2"><label class="small text-muted">Type</label><input type="text" id="activity_type"
                            class="form-control" disabled></div>
                    <div class="mb-2"><label class="small text-muted">Property No.</label><input type="text"
                            id="property_no" class="form-control" disabled></div>
                    <div class="mb-2"><label class="small text-muted">Location</label><input type="text"
                            id="activity_location" class="form-control" disabled></div>
                    <div class="row">
                        <div class="col-6 mb-2"><label class="small text-muted">Date</label><input type="text"
                                id="activity_date" class="form-control" disabled></div>
                        <div class="col-6 mb-2"><label class="small text-muted">Time</label><input type="text"
                                id="activity_time" class="form-control" disabled></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="profileModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content rounded-4 border-0">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Edit Profile</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="../process.php" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" name="redirect_to"
                            value="users/user_dashboard.php?view=<?= htmlspecialchars($view) ?>">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Username</label>
                            <input type="text" name="username"
                                value="<?= htmlspecialchars($currentUser['username'] ?? '') ?>" class="form-control"
                                required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Digital Signature</label>
                            <?php if (!empty($currentUser['signature'])): ?>
                            <div class="mb-2 text-center p-2 border rounded bg-light">
                                <img src="../<?= htmlspecialchars($currentUser['signature']) ?>" alt="Current Signature"
                                    style="max-height: 60px;">
                            </div>
                            <?php endif; ?>
                            <input type="file" name="signature" class="form-control" accept="image/*">
                            <div class="form-text text-muted small">Upload a PNG/JPG image of your signature.</div>
                        </div>
                        <h6 class="text-primary fw-bold">Security</h6>
                        <div class="mb-2">
                            <input type="password" name="current_password" class="form-control"
                                placeholder="Current Password">
                        </div>
                        <div class="mb-2">
                            <input type="password" name="password" class="form-control"
                                placeholder="New Password (8 chars)" minlength="8" maxlength="8">
                        </div>
                        <div class="mb-2">
                            <input type="password" name="confirm_password" class="form-control"
                                placeholder="Confirm Password" minlength="8" maxlength="8">
                        </div>
                    </div>
                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
// Function to initialize dashboard components (to be called after Ajax load)
function initDashboard() {
    // === CHARTS ===
    if (document.getElementById('documentChart')) {
        // Destroy existing chart if it exists
        const chartInstance = Chart.getChart('documentChart');
        if (chartInstance) {
            chartInstance.destroy();
        }

        new Chart(document.getElementById('documentChart'), {
            type: 'doughnut',
            data: {
                labels: ['Pending', 'Approved', 'Done'],
                datasets: [{
                    data: [<?= $pendingPct ?>, <?= $ongoingPct ?>, <?= $donePct ?>],
                    backgroundColor: ['#0d6efd', '#ffc107', '#198754'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right'
                    }
                }
            }
        });
    }

    if (document.getElementById('repairChart')) {
        // Destroy existing chart if it exists
        const chartInstance = Chart.getChart('repairChart');
        if (chartInstance) {
            chartInstance.destroy();
        }

        new Chart(document.getElementById('repairChart'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($locations) ?>,
                datasets: [{
                        label: 'Repair',
                        data: <?= json_encode($repairData['Under repair']) ?>,
                        backgroundColor: '#0d6efd'
                    },
                    {
                        label: 'Operational',
                        data: <?= json_encode($repairData['Operational']) ?>,
                        backgroundColor: '#198754'
                    },
                    {
                        label: 'Unserviceable',
                        data: <?= json_encode($repairData['Unserviceable']) ?>,
                        backgroundColor: '#dc3545'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        stacked: false
                    },
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
    }

    // === CALENDAR FUNCTIONALITY ===
    if (document.getElementById('userCalendar')) {
        // Destroy existing calendar if it exists
        const calendarEl = document.getElementById('userCalendar');
        if (window.userCalendarInstance) {
            window.userCalendarInstance.destroy();
        }

        fetch('../fetch_activities.php')
            .then(r => r.json())
            .then(events => {
                let listHtml = '<ul class="list-group list-group-flush">';
                if (events.length === 0) listHtml +=
                    '<li class="list-group-item text-center text-muted">No activities found</li>';
                events.forEach(e => {
                    let d = new Date(e.start);
                    listHtml += `<li class="list-group-item d-flex justify-content-between align-items-center">
                                <div><strong>${e.title}</strong><br><small class="text-muted">${d.toLocaleDateString()}</small></div>
                                <button class="btn btn-sm btn-outline-primary" onclick="showUserActivityDetails(${e.id})">View</button>
                            </li>`;
                });
                listHtml += '</ul>';
                document.getElementById('userActivityList').innerHTML = listHtml;

                var isMobile = window.innerWidth < 768;
                window.userCalendarInstance = new FullCalendar.Calendar(calendarEl, {
                    initialView: isMobile ? 'listMonth' : 'dayGridMonth',
                    height: isMobile ? 'auto' : 400,
                    headerToolbar: {
                        left: 'prev,next',
                        center: 'title',
                        right: 'today dayGridMonth,listMonth'
                    },
                    buttonText: {
                        dayGridMonth: 'Grid',
                        listMonth: 'List'
                    },
                    eventTimeFormat: {
                        hour: 'numeric',
                        minute: '2-digit',
                        meridiem: 'short'
                    },
                    events: events,
                    eventClick: function(info) {
                        showUserActivityDetails(info.event.id);
                    }
                });
                window.userCalendarInstance.render();
            })
            .catch(e => console.error(e));
    }

    // === APPOINTMENT FUNCTIONALITY ===
    updateAppointmentFields();

    // Add date formatting for appointment date inputs
    const dateInputs = document.querySelectorAll('input[name="appointment_date"]');
    dateInputs.forEach(input => {
        input.addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');

            if (value.length >= 2) {
                value = value.substring(0, 2) + '/' + value.substring(2);
            }
            if (value.length >= 5) {
                value = value.substring(0, 5) + '/' + value.substring(5, 9);
            }

            e.target.value = value;
        });
    });
}

// === APPOINTMENT FUNCTIONALITY ===
function updateAppointmentFields() {
    const preRepairSelect = document.getElementById('preRepairSelect');
    if (!preRepairSelect) return;

    preRepairSelect.addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        const propertyNo = selectedOption.getAttribute('data-property') || '';
        const location = selectedOption.getAttribute('data-location') || '';

        const propertyInput = document.getElementById('propertyNoInput');
        const locationInput = document.getElementById('locationInput');

        if (propertyInput) propertyInput.value = propertyNo;
        if (locationInput) locationInput.value = location;
    });
}

function showUserActivityDetails(id) {
    fetch('../fetch_single_activity.php?id=' + id).then(r => r.json()).then(data => {
        if (!data.id) return alert('Error loading details');
        document.getElementById('activity_type').value = data.activity_type || '';
        document.getElementById('property_no').value = data.property_no || '';
        document.getElementById('activity_location').value = data.location || '';
        document.getElementById('activity_date').value = data.activity_date || '';
        document.getElementById('activity_time').value = data.time_started || '';

        // Initialize Bootstrap modal if needed
        const modalElement = document.getElementById('viewActivityModal');
        if (modalElement) {
            const modal = new bootstrap.Modal(modalElement);
            modal.show();
        }
    });
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', initDashboard);

// For Ajax navigation, also call initDashboard after content is loaded
// This will be called by your Ajax success callback
window.initDashboardOnAjax = initDashboard;

// Auto-refresh notifications every 60 seconds
setInterval(function() {
    if (document.getElementById('notificationDropdown')) {
        fetch(window.location.href)
            .then(response => response.text())
            .then(html => {
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                const newBadge = doc.querySelector('.notification-badge');
                const currentBadge = document.querySelector('.notification-badge');

                if (newBadge && currentBadge && newBadge.textContent !== currentBadge.textContent) {
                    window.location.reload();
                }
            })
            .catch(err => console.error('Notification check error:', err));
    }
}, 60000);
    </script>

    <?php ob_end_flush(); ?>