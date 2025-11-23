<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$mysqli = new mysqli('localhost', 'root', '', 'user_management');
if ($mysqli->connect_errno) {
    die("Failed to connect to MySQL: " . $mysqli->connect_error);
}

// Fetch users who reported an activity
$users = $mysqli->query("SELECT * FROM users ORDER BY id DESC LIMIT 3");

// Fetch events for calendar
$events = $mysqli->query("SELECT * FROM activities ORDER BY activity_date ASC");
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Activities | PEPO Dashboard</title>
    <style>
        body {
            background-color: #f7fdf9;
            font-family: "Poppins", sans-serif;
        }

        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
            background: #fff;
        }

        .calendar-container {
            background: #fff;
            border-radius: 15px;
            padding: 20px;
        }

        .schedule-btn {
            background-color: #007bff;
            color: white;
            border: none;
            border-radius: 8px;
            padding: 8px 14px;
            font-weight: 500;
        }

        .schedule-btn:hover {
            background-color: #0056b3;
        }

        .fc-daygrid-event {
            background-color: #8fd19e !important;
            border: none !important;
            color: #fff !important;
            border-radius: 8px;
            font-weight: 500;
            padding: 3px 5px;
        }

        .fc .fc-button {
            background-color: #198754 !important;
            border: none !important;
            color: white !important;
            font-weight: 500;
            border-radius: 6px;
        }

        .fc .fc-button:hover {
            background-color: #157347 !important;
        }

        .fc-day-today {
            background-color: #d9f7e3 !important;
        }

        .fc-toolbar-title {
            color: #198754 !important;
            font-weight: 600;
        }

        .fc-theme-standard td,
        .fc-theme-standard th {
            border-color: #d3f2da !important;
        }

        .fc-daygrid-day-number {
            color: #198754 !important;
            font-weight: 500;
        }

        .fc-col-header-cell-cushion {
            color: #198754 !important;
            font-weight: 600;
        }
    </style>
</head>

<body>

    <div class="container-fluid p-4">
        <div class="row g-4">

            <!-- Recent Activity -->
            <div class="col-md-4">
                <div class="card p-3 h-100">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="fw-bold text-success mb-0">Activity Log</h5>
                        <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#addActivityLogModal">
                            Add Activity
                        </button>
                    </div>

                    <div class="table-responsive">
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

                                if ($activity_logs && $activity_logs->num_rows > 0) {
                                    while ($log = $activity_logs->fetch_assoc()) {
                                        $status = htmlspecialchars($log['status']);

                                        switch ($status) {
                                            case 'Done':
                                                $badgeClass = 'bg-success';
                                                break;
                                            case 'Ongoing':
                                                $badgeClass = 'bg-warning text-dark';
                                                break;
                                            case 'Pending':
                                                $badgeClass = 'bg-secondary';
                                                break;
                                            default:
                                                $badgeClass = 'bg-light text-dark';
                                        }
                                ?>
                                        <tr class="text-center">
                                            <td><?= htmlspecialchars($log['activity_type']) ?></td>
                                            <td><?= htmlspecialchars($log['property_no']) ?></td>
                                            <td><span class="badge <?= $badgeClass ?>"><?= $status ?></span></td>
                                            <td><?= date('M d, Y h:i A', strtotime($log['date_time'])) ?></td>
                                        </tr>
                                <?php
                                    }
                                } else {
                                    echo '<tr><td colspan="4" class="text-center text-muted">No activities logged yet.</td></tr>';
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Calendar -->
            <div class="col-md-8">
                <div class="calendar-container">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="fw-bold text-success mb-0">Schedule Calendar</h5>
                        <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#addActivityModal">
                            Add Schedule
                        </button>
                    </div>
                    <div id="calendar"></div>
                </div>
            </div>

        </div>
    </div>

    <!-- Add Activity Modal -->
    <div class="modal fade" id="addActivityModal" tabindex="-1" aria-labelledby="addActivityLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content rounded-4">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title" id="addActivityLabel">Create Schedule</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <!-- Normal HTML form submission -->
                <form id="addScheduleForm" method="POST" action="add_activity.php">
                    <div class="modal-body">

                        <div class="mb-3">
                            <label class="form-label">Activity Type</label>
                            <select name="activity_type" class="form-select" required>
                                <option value="">Select Type</option>
                                <option>Inspection</option>
                                <option>Maintenance/Repair</option>
                                <option>Appointment</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Property No.</label>
                            <select name="property_no" class="form-select" required>
                                <option value="" disabled selected>Select Property No.</option>
                                <?php
                                $property_query = $mysqli->query("SELECT property_no FROM equipment ORDER BY property_no ASC");
                                if ($property_query && $property_query->num_rows > 0) {
                                    while ($row = $property_query->fetch_assoc()) {
                                        echo '<option value="' . htmlspecialchars($row['property_no']) . '">' . htmlspecialchars($row['property_no']) . '</option>';
                                    }
                                } else {
                                    echo '<option disabled>No Property Records Found</option>';
                                }
                                ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Location</label>
                            <select name="location" class="form-select" required>
                                <option value="" disabled selected>Select Location</option>
                                <option value="Mamburao">Mamburao</option>
                                <option value="Sablayan">Sablayan</option>
                                <option value="San Jose">San Jose</option>
                                <option value="Lubang">Lubang</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Date</label>
                            <input type="date" name="activity_date" class="form-control" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Time</label>
                            <input type="time" name="activity_time" class="form-control" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Remarks</label>
                            <textarea name="remarks" class="form-control" rows="3" placeholder="Enter additional details..."></textarea>
                        </div>

                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-success">Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Activity Log Modal -->
    <div class="modal fade" id="addActivityLogModal" tabindex="-1" aria-labelledby="addActivityLogLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content rounded-4">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title" id="addActivityLogLabel">Add Activity Log</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <form method="POST" action="add_activity_log.php">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Activity Type</label>
                            <select name="activity_type" class="form-select" required>
                                <option value="" disabled selected>Select Activity Type</option>
                                <option value="Maintenance/Repair">Maintenance/Repair</option>
                                <option value="Inspection">Inspection</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Property No.</label>
                            <select name="property_no" class="form-select" required>
                                <option value="" disabled selected>Select Property No.</option>
                                <?php
                                $properties = $mysqli->query("SELECT property_no FROM equipment ORDER BY property_no ASC");
                                if ($properties && $properties->num_rows > 0) {
                                    while ($prop = $properties->fetch_assoc()) {
                                        echo '<option value="' . htmlspecialchars($prop['property_no']) . '">' . htmlspecialchars($prop['property_no']) . '</option>';
                                    }
                                }
                                ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select" required>
                                <option value="" disabled selected>Select Status</option>
                                <option value="Done">Done</option>
                                <option value="Ongoing">Ongoing</option>
                                <option value="Pending">Pending</option>
                            </select>
                        </div>

                        <input type="hidden" name="date_time" value="<?= date('Y-m-d H:i:s') ?>">
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-success">Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

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

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
// Simple calendar table with clickable dates
var currentDate = new Date();

function renderCalendar() {
    var calendarEl = document.getElementById('calendar');
    if (!calendarEl) return;

    // Fetch activities and create simple table view
    fetch('fetch_activities.php')
        .then(r => r.json())
        .then(events => {
            // Group events by date
            var eventsByDate = {};
            events.forEach(e => {
                var dateStr = e.start ? e.start.split('T')[0] : '';
                if (!eventsByDate[dateStr]) eventsByDate[dateStr] = [];
                eventsByDate[dateStr].push(e);
            });

            // Create simple calendar HTML with header
            var monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
            var monthYear = monthNames[currentDate.getMonth()] + ' ' + currentDate.getFullYear();
            
            var html = '<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">';
            html += '<button class="btn btn-sm btn-outline-secondary" onclick="previousMonth()">← Prev</button>';
            html += '<h5 style="margin:0; flex:1; text-align:center;">' + monthYear + '</h5>';
            html += '<button class="btn btn-sm btn-outline-secondary" onclick="nextMonth()">Next →</button>';
            html += '<button class="btn btn-sm btn-success ms-2" onclick="goToday()">Today</button>';
            html += '</div>';
            
            html += '<table class="table table-sm table-bordered text-center"><thead class="table-light"><tr>';
            var days = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
            days.forEach(d => html += '<th>' + d + '</th>');
            html += '</tr></thead><tbody><tr>';

            var year = currentDate.getFullYear();
            var month = currentDate.getMonth();
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
                var cellContent = '<strong style="' + (isToday ? 'color:green;' : '') + '">' + d.getDate() + '</strong>';
                if (eventsForDate.length > 0) {
                    cellContent += '<br/>';
                    eventsForDate.forEach(e => {
                        cellContent += '<small class="badge bg-success cursor-pointer" onclick="showActivityDetails(' + e.id + ')" style="cursor:pointer; display:block; margin:2px 0;">' + e.title.substring(0, 15) + '</small>';
                    });
                }
                
                html += '<td class="' + cellClass + '" style="height:100px; vertical-align:top; overflow-y:auto;">' + cellContent + '</td>';
                
                d.setDate(d.getDate() + 1);
                dayCounter++;
            }
            
            html += '</tr></tbody></table>';
            calendarEl.innerHTML = html;
        });
}

function previousMonth() {
    currentDate.setMonth(currentDate.getMonth() - 1);
    renderCalendar();
}

function nextMonth() {
    currentDate.setMonth(currentDate.getMonth() + 1);
    renderCalendar();
}

function goToday() {
    currentDate = new Date();
    renderCalendar();
}

document.addEventListener('DOMContentLoaded', function () {
    renderCalendar();
});

// Global function to show activity details in modal
function showActivityDetails(activityId) {
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
                    showActivityDetails(id);
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
</script>



<?php if (!empty($_GET['success'])): ?>
    <div class="alert alert-success text-center"><?= htmlspecialchars($_GET['success']) ?></div>
<?php elseif (!empty($_GET['error'])): ?>
    <div class="alert alert-danger text-center"><?= htmlspecialchars($_GET['error']) ?></div>
<?php endif; ?>

</body>
</html>
