<?php
require_once __DIR__ . '/includes/session_helper.php';
start_user_session('admin');

// Database connection
$mysqli = new mysqli('localhost', 'root', '', 'user_management');
if ($mysqli->connect_error) {
    exit("Database connection failed: " . $mysqli->connect_error);
}

// Redirect if not logged in or not admin
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: index.php?login");
    exit();
}

// Allowed views
$allowedViews = ['dashboard', 'equipment', 'documents', 'maintenance', 'inventory', 'activities', 'report', 'user'];
$view = $_GET['view'] ?? 'dashboard';
if (!in_array($view, $allowedViews, true)) {
    $view = '404';
}

// Vehicle counts
$vehicle_counts = [
    'Mamburao' => 0,
    'San Jose' => 0,
    'Sablayan' => 0,
    'Lubang'   => 0
];

if ($view === 'dashboard') {
    $stmt = $mysqli->prepare("SELECT location, COUNT(*) AS total 
                              FROM equipment 
                              WHERE location IN ('Mamburao','San Jose','Sablayan','Lubang')
                              GROUP BY location");
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $vehicle_counts[$row['location']] = (int)$row['total'];
    }
    $stmt->close();
}

// Document counts
function getDocCount($mysqli, $status)
{
    $stmt = $mysqli->prepare("SELECT COUNT(*) as c FROM documents WHERE status=?");
    $stmt->bind_param("s", $status);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    return (int)($res['c'] ?? 0);
}

$pending = getDocCount($mysqli, 'PENDING');
$ongoing = getDocCount($mysqli, 'APPROVED');
$done    = getDocCount($mysqli, 'DONE');

$totalDocs = $pending + $ongoing + $done;
$percent = fn($val) => $totalDocs > 0 ? round(($val / $totalDocs) * 100) : 0;

$pendingPct = $percent($pending);
$ongoingPct = $percent($ongoing);
$donePct    = $percent($done);

// Pre Repair Inspection Data
$locations = [];
$resLoc = $mysqli->query("SELECT DISTINCT location FROM equipment ORDER BY location");
while ($r = $resLoc->fetch_assoc()) {
    $locations[] = $r['location'];
}

$statuses = ['Under repair', 'Operational', 'Unserviceable'];
$repairData = [];
foreach ($statuses as $status) {
    $repairData[$status] = [];
    foreach ($locations as $loc) {
        $stmt = $mysqli->prepare("SELECT COUNT(*) as cnt FROM equipment WHERE location=? AND status=?");
        $stmt->bind_param("ss", $loc, $status);
        $stmt->execute();
        $repairData[$status][] = (int)$stmt->get_result()->fetch_assoc()['cnt'];
    }
}

// Fetch schedules for the calendar
    // $schedules = [];
    // $result = $mysqli->query("SELECT id, title, start_date, end_date FROM activities");
    // if ($result) {
    //     while ($row = $result->fetch_assoc()) {
    //         $schedules[] = [
    //             'id'    => $row['id'],
    //             'title' => htmlspecialchars($row['title']),
    //             'start' => $row['start_date'],
    //             'end'   => $row['end_date']
    //         ];
    //     }
    // }

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.css" rel="stylesheet">
    <style>
        body { margin:0; padding:0; display:flex; background-color:#f8f9fa; }
        .sidebar { width:250px; height:100vh; background:#fff; border-right:1px solid #dee2e6; padding:20px; position:fixed; }
        .sidebar img { width:80px; display:block; margin:0 auto 10px; }
        .sidebar h4,.sidebar small { text-align:center; margin:0; }
        .sidebar small { color:gray; }
        .nav-link { color:#333; padding:8px 0; font-weight:500; }
        .nav-link:hover { color:#19fb007a; }
        .main-content { margin-left:260px; padding:30px; width:100%; }
        .sidebar-hidden .sidebar { display:none; }
        .sidebar-hidden .main-content { margin-left:0!important; }
        #hideSidebarBtn { font-size:1rem; padding:5px; color:#333; }
        #hideSidebarBtn:hover { color:#b6b7b9ff; }
        .rotate-icon { transition:transform 0.3s ease; }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div class="d-flex align-items-center">
                <img src="../PEPO/rs/Pepo_Logo.png" alt="PEPO Logo" style="width:50px; margin-right:10px;">
                <div>
                    <h5 class="mb-0">PEPO</h5>
                    <small>Admin Dashboard</small>
                </div>
            </div>
            <button class="btn btn-sm btn-light border-0" id="hideSidebarBtn" title="Toggle Sidebar">
                <i class="fas fa-chevron-left" id="sidebarIcon"></i>
            </button>
        </div>
        <nav class="nav flex-column mt-4">
            <a class="nav-link <?= $view === 'dashboard' ? 'fw-bold' : '' ?>" href="?view=dashboard"><i class="fas fa-home me-2"></i>Dashboard</a>
            <div class="d-flex justify-content-between align-items-center nav-link px-0" data-bs-toggle="collapse" data-bs-target="#equipmentMenu" role="button">
                <a href="?view=equipment" class="nav-link <?= $view === 'equipment' ? 'fw-bold' : '' ?>"><i class="fas fa-truck me-2"></i>Equipment</a>
                <i class="fas fa-caret-down rotate-icon"></i>
            </div>
            <div class="collapse ps-3 <?= in_array($view, ['equipment', 'documents', 'maintenance']) ? 'show' : '' ?>" id="equipmentMenu">
                <a class="nav-link <?= $view === 'documents' ? 'fw-bold' : '' ?>" href="?view=documents">Documents</a>
                <a class="nav-link <?= $view === 'maintenance' ? 'fw-bold' : '' ?>" href="?view=maintenance">Maintenance</a>
            </div>
            <a class="nav-link <?= $view === 'inventory' ? 'fw-bold' : '' ?>" href="?view=inventory"><i class="fas fa-cogs me-2"></i>Inventory</a>
            <a class="nav-link <?= $view === 'activities' ? 'fw-bold' : '' ?>" href="?view=activities"><i class="far fa-calendar-alt me-2"></i>Activities</a>
            <a class="nav-link <?= $view === 'report' ? 'fw-bold' : '' ?>" href="?view=report"><i class="fas fa-chart-bar me-2"></i>Report</a>
            <a class="nav-link <?= $view === 'user' ? 'fw-bold' : '' ?>" href="?view=user"><i class="fas fa-users me-2"></i>User</a>
            <a class="nav-link text-danger mt-3" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Log out</a>
        </nav>
    </div>

    <!-- Show sidebar button -->
    <button id="showSidebarBtn" class="btn btn-primary position-fixed" style="top:10px; left:10px; z-index:99; display:none;">
        <i class="fas fa-chevron-right"></i>
    </button>

    <!-- Main Content -->
    <div class="main-content">
        <?php if ($view === 'dashboard'): ?>
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card shadow-sm text-center p-4 d-flex flex-column justify-content-center align-items-center" style="border-radius:20px; background:#ececec; height:400px;">
                        <div class="mb-3 d-flex justify-content-center align-items-center" style="height:90px;">
                            <span style="display:flex;width:70px;height:70px;background:linear-gradient(135deg,#b6e388,#7ed957);border-radius:50%;align-items:center;justify-content:center;border:5px solid #fff;">
                                <i class="fa fa-car" style="font-size:2.5rem;color:#fff;"></i>
                            </span>
                        </div>
                        <div class="fw-bold mb-2" style="font-size:2rem;color:#3a3847;">
                            <?= array_sum($vehicle_counts) ?> Vehicles
                        </div>
                        <div class="text-center" style="font-size:1rem;">
                            <?php foreach ($vehicle_counts as $loc => $count): ?>
                                <div><?= $loc ?>: <?= $count ?></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card shadow-sm p-3 d-flex align-items-center justify-content-center" style="border-radius:20px; height:400px;">
                        <h6 class="mb-2">Document Status</h6>
                        <div style="width:320px; height:320px;">
                            <canvas id="documentChart"></canvas>
                        </div>
                        <p class="text-muted mt-2 mb-0" style="font-size:0.9rem;">DOCUMENT DATA</p>
                    </div>
                </div>

                <div class="col-md-5">
                    <div class="card shadow-sm p-4" style="border-radius:20px; height:400px;">
                        <h6 class="fw-bold mb-3">Pre Repair Inspection</h6>
                        <canvas id="repairChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- ✅ Activity Log & Calendar -->
            <div class="card p-4 shadow-sm border-0 rounded-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <!-- <h4 class="fw-bold text-success mb-0">Activity Management</h4> -->
                    <!-- <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#addActivityLogModal"> -->
                    </button>
                </div>

                <div class="row g-4">
                    <!-- Activity Log Table -->
                    <div class="col-md-5">
                        <div class="card border-0 shadow-sm rounded-4 h-100">
                            <div class="card-body">
                                <h6 class="fw-bold text-success mb-3">Recent Activity Log</h6>
                                <table class="table table-sm table-bordered align-middle">
                                    <thead class="table-success text-center">
                                        <tr>
                                            <th>Activity Type</th>
                                            <th>Property No.</th>
                                            <th>Status</th>
                                            <th>Time/Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $activity_logs = $mysqli->query("SELECT * FROM activity_log ORDER BY date_time DESC LIMIT 10");
                                        if ($activity_logs && $activity_logs->num_rows > 0):
                                            while ($log = $activity_logs->fetch_assoc()):
                                                $status = htmlspecialchars($log['status']);
                                                $badgeClass = match ($status) {
                                                    'Done' => 'bg-success',
                                                    'Ongoing' => 'bg-warning text-dark',
                                                    'Pending' => 'bg-secondary',
                                                    default => 'bg-light text-dark'
                                                };
                                        ?>
                                        <tr class="text-center">
                                            <td><?= htmlspecialchars($log['activity_type']) ?></td>
                                            <td><?= htmlspecialchars($log['property_no']) ?></td>
                                            <td><span class="badge <?= $badgeClass ?>"><?= $status ?></span></td>
                                            <td><?= date('M d, Y h:i A', strtotime($log['date_time'])) ?></td>
                                        </tr>
                                        <?php endwhile; else: ?>
                                        <tr>
                                            <td colspan="4" class="text-center text-muted">No activities logged yet.</td>
                                        </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Calendar -->
                    <div class="col-md-7">
                        <div class="card border-0 shadow-sm rounded-4 h-100">
                            <div class="card-header bg-light">
                                <h6 class="fw-bold text-success mb-0">Schedule Calendar</h6>
                            </div>
                            <div class="card-body">
                                <div id="adminCalendar"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        <?php elseif ($view !== '404'): ?>
            <?php include "side_$view.php"; ?>
        <?php else: ?>
            <h2 class="fw-bold mb-4">Page Not Found</h2>
            <p>The view you requested does not exist.</p>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels"></script>
    <script>
        // Sidebar toggle
        const body = document.body;
        document.getElementById('hideSidebarBtn').addEventListener('click', () => {
            body.classList.add('sidebar-hidden');
            document.getElementById('showSidebarBtn').style.display = 'block';
        });
        document.getElementById('showSidebarBtn').addEventListener('click', () => {
            body.classList.remove('sidebar-hidden');
            document.getElementById('showSidebarBtn').style.display = 'none';
        });

        // Calendar functionality
        var currentCalendarDate = new Date();

        function renderAdminCalendar() {
            var calendarEl = document.getElementById('adminCalendar');
            if (!calendarEl) {
                console.log('Calendar element not found');
                return;
            }

            fetch('fetch_activities.php')
                .then(r => {
                    if (!r.ok) throw new Error('Network response was not ok: ' + r.status);
                    return r.json();
                })
                .then(events => {
                    if (!Array.isArray(events)) {
                        console.error('Events is not an array:', events);
                        calendarEl.innerHTML = '<p class="text-danger">Error loading calendar events</p>';
                        return;
                    }

                    var eventsByDate = {};
                    events.forEach(e => {
                        var dateStr = e.start ? e.start.split('T')[0] : '';
                        if (!eventsByDate[dateStr]) eventsByDate[dateStr] = [];
                        eventsByDate[dateStr].push(e);
                    });

                    var monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
                    var monthYear = monthNames[currentCalendarDate.getMonth()] + ' ' + currentCalendarDate.getFullYear();
                    
                    var html = '<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">';
                    html += '<button class="btn btn-sm btn-outline-secondary" onclick="previousAdminMonth()">← Prev</button>';
                    html += '<h6 style="margin:0; flex:1; text-align:center; font-weight:bold;">' + monthYear + '</h6>';
                    html += '<button class="btn btn-sm btn-outline-secondary" onclick="nextAdminMonth()">Next →</button>';
                    html += '<button class="btn btn-sm btn-success ms-2" onclick="goToAdminToday()">Today</button>';
                    html += '</div>';
                    
                    html += '<table class="table table-sm table-bordered text-center"><thead class="table-light"><tr>';
                    var days = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
                    days.forEach(d => html += '<th style="font-size:0.85rem;">' + d + '</th>');
                    html += '</tr></thead><tbody><tr>';

                    var year = currentCalendarDate.getFullYear();
                    var month = currentCalendarDate.getMonth();
                    var firstDay = new Date(year, month, 1);
                    var lastDay = new Date(year, month + 1, 0);
                    var startDate = new Date(firstDay);
                    startDate.setDate(startDate.getDate() - firstDay.getDay());

                    var dayCounter = 0;
                    var d = new Date(startDate);
                    
                    while (d <= lastDay) {
                        if (dayCounter % 7 === 0 && dayCounter > 0) html += '</tr><tr>';
                        
                        var dateStr = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
                        var isCurrentMonth = d.getMonth() === month;
                        var isToday = d.toDateString() === new Date().toDateString();
                        var cellClass = isCurrentMonth ? '' : 'text-muted bg-light';
                        if (isToday) cellClass += ' bg-success bg-opacity-10';
                        var eventsForDate = eventsByDate[dateStr] || [];
                        var cellContent = '<strong style="' + (isToday ? 'color:green; font-size:0.9rem;' : 'font-size:0.85rem;') + '">' + d.getDate() + '</strong>';
                        if (eventsForDate.length > 0) {
                            cellContent += '<br/>';
                            eventsForDate.slice(0, 2).forEach(e => {
                                cellContent += '<small class="badge bg-success" style="font-size:0.7rem; display:block; margin:1px 0; cursor:pointer; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" onclick="showAdminActivityDetails(' + e.id + ')">' + e.title.substring(0, 12) + '</small>';
                            });
                            if (eventsForDate.length > 2) {
                                cellContent += '<small style="font-size:0.7rem; color:#666;">+' + (eventsForDate.length - 2) + ' more</small>';
                            }
                        }
                        
                        html += '<td class="' + cellClass + '" style="height:80px; vertical-align:top; overflow-y:auto; padding:4px; font-size:0.8rem;">' + cellContent + '</td>';
                        
                        d.setDate(d.getDate() + 1);
                        dayCounter++;
                    }
                    
                    html += '</tr></tbody></table>';
                    calendarEl.innerHTML = html;
                })
                .catch(err => {
                    console.error('Calendar fetch error:', err);
                    calendarEl.innerHTML = '<p class="text-danger">Error loading calendar: ' + err.message + '</p>';
                });
        }

        function previousAdminMonth() {
            currentCalendarDate.setMonth(currentCalendarDate.getMonth() - 1);
            renderAdminCalendar();
        }

        function nextAdminMonth() {
            currentCalendarDate.setMonth(currentCalendarDate.getMonth() + 1);
            renderAdminCalendar();
        }

        function goToAdminToday() {
            currentCalendarDate = new Date();
            renderAdminCalendar();
        }

        function showAdminActivityDetails(activityId) {
            // Fetch activity details and show in modal
            fetch('fetch_single_activity.php?id=' + activityId)
                .then(r => r.json())
                .then(data => {
                    if (!data.id) {
                        alert('Activity not found');
                        return;
                    }
                    
                    // Populate form
                    document.getElementById('activity_id').value = data.id;
                    document.getElementById('activity_type').value = data.activity_type || '';
                    document.getElementById('property_no_select').value = data.property_no || '';
                    document.getElementById('activity_location').value = data.location || '';
                    document.getElementById('activity_date').value = data.activity_date || '';
                    document.getElementById('activity_time').value = data.activity_time || '';
                    document.getElementById('activity_remarks').value = data.remarks || '';
                    
                    // Reset to view mode
                    toggleViewMode(false);
                    
                    // Show modal
                    var modal = new bootstrap.Modal(document.getElementById('viewActivityModal'));
                    modal.show();
                })
                .catch(err => console.error('Error loading activity:', err));
        }

        document.addEventListener('DOMContentLoaded', function() {
            // Only render calendar if element exists (on dashboard view)
            if (document.getElementById('adminCalendar')) {
                renderAdminCalendar();
            }
        });

        // Helper to toggle form edit mode
        function toggleViewMode(editing) {
            var fields = ['activity_type','property_no_select','activity_location','activity_date','activity_time','activity_remarks'];
            fields.forEach(function(id){
                var el = document.getElementById(id);
                if (!el) return;
                el.disabled = editing ? false : true;
            });
            
            var saveBtn = document.getElementById('saveActivityBtn');
            var editBtn = document.getElementById('editToggleBtn');
            saveBtn.style.display = editing ? '' : 'none';
            editBtn.textContent = editing ? 'Cancel' : 'Edit';
        }

        // Wire up edit toggle button
        document.addEventListener('DOMContentLoaded', function(){
            var editBtn = document.getElementById('editToggleBtn');
            if (editBtn) {
                editBtn.addEventListener('click', function(e){
                    e.preventDefault();
                    var isEditing = document.getElementById('saveActivityBtn').style.display !== 'none';
                    if (isEditing) {
                        // Cancel - reload original values
                        var id = document.getElementById('activity_id').value;
                        if (id) {
                            showAdminActivityDetails(id);
                        }
                    } else {
                        // Enable edit mode
                        toggleViewMode(true);
                    }
                });
            }
            
            // Wire up save form
            var viewForm = document.getElementById('viewActivityForm');
            if (viewForm) {
                viewForm.addEventListener('submit', function(e){
                    e.preventDefault();
                    var formData = new FormData(viewForm);
                    fetch('update_activity.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(r => r.json())
                    .then(resp => {
                        if (resp.success) {
                            alert('Activity updated successfully');
                            var modal = bootstrap.Modal.getInstance(document.getElementById('viewActivityModal'));
                            if (modal) modal.hide();
                            location.reload();
                        } else {
                            alert('Error: ' + (resp.message || 'Unknown error'));
                        }
                    })
                    .catch(err => alert('Save failed: ' + err));
                });
            }
        });

        // Charts
        if (document.getElementById('documentChart')) {
            new Chart(document.getElementById('documentChart').getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: ['Pending', 'Approved', 'Done'],
                    datasets: [{
                        data: [<?= $pendingPct ?>, <?= $ongoingPct ?>, <?= $donePct ?>],
                        backgroundColor: ['#2196F3', '#FFC107', '#4CAF50'],
                        borderWidth: 1
                    }]
                },
                options: {
                    cutout: '65%',
                    plugins: {
                        legend: { position: 'right', labels: { usePointStyle: true, pointStyle: 'circle' } },
                        datalabels: {
                            color: '#fff',
                            formatter: v => v + '%',
                            font: { weight: 'bold', size: 12 }
                        }
                    }
                },
                plugins: [ChartDataLabels]
            });
        }

        if (document.getElementById('repairChart')) {
            new Chart(document.getElementById('repairChart').getContext('2d'), {
                type: 'bar',
                data: {
                    labels: <?= json_encode($locations) ?>,
                    datasets: [
                        { label: 'Under Repair', data: <?= json_encode($repairData['Under repair']) ?>, backgroundColor: '#2196F3' },
                        { label: 'Operational', data: <?= json_encode($repairData['Operational']) ?>, backgroundColor: '#4CAF50' },
                        { label: 'Unserviceable', data: <?= json_encode($repairData['Unserviceable']) ?>, backgroundColor: '#F44336' }
                    ]
                },
                options: { responsive: true, plugins: { legend: { position: 'top' } }, scales: { y: { beginAtZero: true } } }
            });
        }

    </script>

    <!-- View/Edit Activity Modal (pop-up form) -->
    <div class="modal fade" id="viewActivityModal" tabindex="-1" aria-labelledby="viewActivityLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content rounded-4">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title" id="viewActivityLabel">Schedule Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <form id="viewActivityForm">
                    <div class="modal-body">
                        <input type="hidden" name="id" id="activity_id">

                        <div class="mb-3">
                            <label class="form-label fw-bold">Activity Type</label>
                            <select name="activity_type" id="activity_type" class="form-select" disabled required>
                                <option value="Inspection">Inspection</option>
                                <option value="Maintenance/Repair">Maintenance/Repair</option>
                                <option value="Appointment">Appointment</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Property No.</label>
                            <select name="property_no" id="property_no_select" class="form-select" disabled required>
                                <option value="" disabled>Select Property No.</option>
                                <?php
                                $prop_q = $mysqli->query("SELECT property_no FROM equipment ORDER BY property_no ASC");
                                if ($prop_q && $prop_q->num_rows > 0) {
                                    while ($p = $prop_q->fetch_assoc()) {
                                        echo '<option value="' . htmlspecialchars($p['property_no']) . '">' . htmlspecialchars($p['property_no']) . '</option>';
                                    }
                                }
                                ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Location</label>
                            <input type="text" name="location" id="activity_location" class="form-control" disabled required>
                        </div>

                        <div class="row gx-2">
                            <div class="col-6 mb-3">
                                <label class="form-label fw-bold">Date</label>
                                <input type="date" name="activity_date" id="activity_date" class="form-control" disabled required>
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label fw-bold">Time</label>
                                <input type="time" name="activity_time" id="activity_time" class="form-control" disabled required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Remarks</label>
                            <textarea name="remarks" id="activity_remarks" class="form-control" rows="3" disabled></textarea>
                        </div>
                    </div>

                    <div class="modal-footer bg-light">
                        <button type="button" id="editToggleBtn" class="btn btn-outline-success">Edit</button>
                        <button type="submit" id="saveActivityBtn" class="btn btn-success" style="display:none;">Save</button>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </form>

            </div>
        </div>
    </div>

</body>
</html>
