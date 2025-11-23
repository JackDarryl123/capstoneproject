<?php



require_once __DIR__ . '/../includes/session_helper.php';
start_user_session('user');



// Database connection (fail gracefully)
$mysqli = new mysqli('localhost', 'root', '', 'user_management');
if ($mysqli->connect_error) {
    exit("Database connection failed: " . $mysqli->connect_error);
}

// Redirect if not logged in or not a user
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'user') {
    header("Location: index.php?login");
    exit();
}

// Detect current view safely and add 'request' to allowed views
$allowedViews = ['dashboard', 'scan', 'request'];
$view = $_GET['view'] ?? 'dashboard';
if (!in_array($view, $allowedViews, true)) {
    $view = '404';
}

// === START: ADMIN DASHBOARD DATA LOGIC (COPIED) ===
// Vehicle count
$vehicle_counts = [
    'Mamburao' => 0,
    'San Jose' => 0,
    'Sablayan' => 0,
    'Lubang' => 0
];

if ($view === 'dashboard') {
    $stmt = $mysqli->prepare("SELECT location, COUNT(*) AS total
                             FROM equipment
                             WHERE location IN ('Mamburao','San Jose','Sablayan','Lubang')
                             GROUP BY location");
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $loc = $row['location'];
        $vehicle_counts[$loc] = (int)$row['total'];
    }
    $stmt->close();
}


// Safe document counts (prepared statements)
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

// Pre Repair Inspection (dynamic locations + statuses)
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
// === END: ADMIN DASHBOARD DATA LOGIC (COPIED) ===

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>User Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            margin: 0;
            padding: 0;
            display: flex;
            background-color: #f8f9fa;
        }

        .sidebar {
            width: 250px;
            height: 100vh;
            background: #fff;
            border-right: 1px solid #dee2e6;
            padding: 20px;
            position: fixed;
        }

        .sidebar img {
            width: 80px;
            display: block;
            margin: 0 auto 10px;
        }

        .sidebar h4,
        .sidebar small {
            text-align: center;
            margin: 0;
        }

        .sidebar small {
            color: gray;
        }

        .nav-link {
            color: #333;
            padding: 8px 0;
            font-weight: 500;
        }

        .nav-link:hover {
            color: #19fb007a;
        }

        .main-content {
            margin-left: 260px;
            padding: 30px;
            width: 100%;
        }

        .sidebar-hidden .sidebar {
            display: none;
        }

        .sidebar-hidden .main-content {
            margin-left: 0 !important;
        }

        #hideSidebarBtn {
            font-size: 1rem;
            padding: 5px;
            color: #333;
        }

        #hideSidebarBtn:hover {
            color: #b6b7b9ff;
        }

        .rotate-icon {
            transition: transform 0.3s ease;
        }
    </style>
</head>

<body>
    <div class="sidebar">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div class="d-flex align-items-center">
                <img src="../rs/Pepo_Logo.png" alt="PEPO Logo" style="width:50px; margin-right:10px;">
                <div>
                    <h5 class="mb-0">PEPO</h5>
                    <small>User Dashboard</small>
                </div>
            </div>
            <button class="btn btn-sm btn-light border-0" id="hideSidebarBtn" title="Toggle Sidebar">
                <i class="fas fa-chevron-left" id="sidebarIcon"></i>
            </button>
        </div>

        <nav class="nav flex-column mt-4">
            <a class="nav-link <?= $view === 'dashboard' ? 'fw-bold' : '' ?>" href="?view=dashboard"><i class="fas fa-home me-2"></i>Dashboard</a>
            <a class="nav-link <?= $view === 'scan' ? 'fw-bold' : '' ?>" href="?view=scan"><i class="fas fa-qrcode me-2"></i>Scan QR</a>
            <a class="nav-link <?= $view === 'request' ? 'fw-bold' : '' ?>" href="?view=request"><i class="fas fa-paper-plane me-2"></i>Request</a>
            <a class="nav-link text-danger mt-3" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Log out</a>
        </nav>
    </div>

    <button id="showSidebarBtn" class="btn btn-primary position-fixed" style="top:10px; left:10px; z-index:99; display:none;">
        <i class="fas fa-chevron-right"></i>
    </button>

    <div class="main-content">
        <?php if ($view === 'dashboard'): ?>
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card shadow-sm text-center p-4 d-flex flex-column justify-content-center align-items-center" style="border-radius:20px; background:#ececec; height:400px;">
                        <div class="mb-3 d-flex justify-content-center align-items-center" style="height:90px;">
                            <span style="display:flex;width:70px;height:70px;background:linear-gradient(135deg,#b6e388,#7ed957);border-radius:50%;align-items:center;justify-content:center;
                            border:5px solid #fff;">
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
            <?php elseif ($view === 'scan'): ?>
            <?php include "side_scan.php"; ?>
        <?php elseif ($view === 'request'): ?>
            <?php include "side_request.php"; ?>
        <?php else: ?>
            <h2 class="fw-bold mb-4">Page Not Found</h2>
            <p>The view you requested does not exist.</p>
        <?php endif; ?>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels"></script>
    <script>
        // Sidebar toggle functionality
        const body = document.body;
        document.getElementById('hideSidebarBtn').addEventListener('click', () => {
            body.classList.add('sidebar-hidden');
            document.getElementById('showSidebarBtn').style.display = 'block';
        });
        document.getElementById('showSidebarBtn').addEventListener('click', () => {
            body.classList.remove('sidebar-hidden');
            document.getElementById('showSidebarBtn').style.display = 'none';
        });

        // Document Chart
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
                        legend: {
                            position: 'right',
                            labels: {
                                usePointStyle: true,
                                pointStyle: 'circle'
                            }
                        },
                        datalabels: {
                            color: '#fff',
                            formatter: v => v + '%',
                            font: {
                                weight: 'bold',
                                size: 12
                            }
                        }
                    }
                },
                plugins: [ChartDataLabels]
            });
        }

        // Pre Repair Inspection Chart
        if (document.getElementById('repairChart')) {
            new Chart(document.getElementById('repairChart').getContext('2d'), {
                type: 'bar',
                data: {
                    labels: <?= json_encode($locations) ?>,
                    datasets: [{
                        label: 'Under Repair',
                        data: <?= json_encode($repairData['Under repair']) ?>,
                        backgroundColor: '#2196F3'
                    }, {
                        label: 'Operational',
                        data: <?= json_encode($repairData['Operational']) ?>,
                        backgroundColor: '#4CAF50'
                    }, {
                        label: 'Unserviceable',
                        data: <?= json_encode($repairData['Unserviceable']) ?>,
                        backgroundColor: '#F44336'
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'top'
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });
        }
    </script>
</body>

</html>