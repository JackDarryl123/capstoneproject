<?php
require_once __DIR__ . '/config.php';

session_start();

// Redirect if not logged in or not admin
if (!isset($_SESSION['user_id']) || $_SESSION['is_admin'] != 1) {
    header("Location: index.php?login");
    exit();
}

// Get user details for editing
if (isset($_GET['id'])) {
    $userId = $_GET['id'];
    $stmt = $mysqli->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    if (!$user) {
        $_SESSION['message'] = "User not found.";
        $_SESSION['msg_type'] = "danger";
        header("Location: admin_dashboard.php");
        exit();
    }
} else {
    header("Location: admin_dashboard.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit User</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body>

<div class="container mt-5">
    <h2>Edit User</h2>

    <form action="process.php" method="POST">
        <input type="hidden" name="update_user" value="1">
        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">

        <div class="mb-3">
            <label>Username:</label>
            <input type="text" name="username" value="<?php echo $user['username']; ?>" class="form-control" required>
        </div>

        <div class="mb-3">
            <label>Email:</label>
            <input type="email" name="email" value="<?php echo $user['email']; ?>" class="form-control" required>
        </div>

        <div class="mb-3">
            <label>Role:</label>
            <select name="is_admin" class="form-control">
                <option value="0" <?php echo ($user['is_admin'] == 0) ? 'selected' : ''; ?>>User</option>
                <option value="1" <?php echo ($user['is_admin'] == 1) ? 'selected' : ''; ?>>Admin</option>
            </select>
        </div>

        <button type="submit" class="btn btn-success">Update</button>
        <a href="admin_dashboard.php" class="btn btn-secondary">Cancel</a>
    </form>
</div>

</body>
</html>
