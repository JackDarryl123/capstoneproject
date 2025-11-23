<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'supply') {
    header("Location: index.php?login");
    exit();
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Staff Dashboard</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body>
<div class="container mt-5">
    <h2>Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?> Supply Department</h2>
    <p>This is your dashboard.</p>
    <a href="../logout.php" class="btn btn-danger">Logout</a>
</div>
</body>
</html>