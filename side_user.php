<?php
// NOTE: Don't use session_start or HTML <head>/<body> here — this is included from admin_dashboard.php

// Fetch user data
$userResult = $mysqli->query("SELECT * FROM users") or die($mysqli->error);
?>

<br>

<h3 class="text-center mb-4">User Management</h3>
<p class="text-center">Manage user roles below:</p>

<table class="table table-bordered align-middle text-center">
    <thead class="table-light">
        <tr>
            <th>ID</th>
            <th>Username</th>
            <th>Email</th>
            <th>Role</th>
            <th>Status</th> 
            <th>Change Role</th>
            <th>Change Status</th> 
        </tr>
    </thead>
    <tbody>
        <?php while ($user = $userResult->fetch_assoc()): ?>
            <tr>
                <td><?= $user['id']; ?></td>
                <td><?= htmlspecialchars($user['username']); ?></td>
                <td><?= htmlspecialchars($user['email']); ?></td>
                <td><?= ucfirst($user['role']); ?></td>
                <td>
                    <span class="badge bg-<?= $user['status'] === 'active' ? 'success' : 'secondary' ?>">
                        <?= ucfirst($user['status']); ?>
                    </span>
                </td>
                <td>
                    <form method="POST" action="process.php" onChange="this.submit();">
                        <input type="hidden" name="user_id" value="<?= $user['id']; ?>">
                        <select name="update_role" class="form-select form-select-sm">
                            <option value="admin"   <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                            <option value="staff"   <?= $user['role'] === 'staff' ? 'selected' : '' ?>>Staff</option>
                            <option value="supply"  <?= $user['role'] === 'supply' ? 'selected' : '' ?>>Supply</option>
                            <option value="user"    <?= $user['role'] === 'user' ? 'selected' : '' ?>>User</option>
                        </select>
                    </form>
                </td>
                <td>
                    <form method="POST" action="process.php" onChange="this.submit();">
                        <input type="hidden" name="user_id" value="<?= $user['id']; ?>">
                        <select name="update_status" class="form-select form-select-sm">
                            <option value="active"   <?= $user['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= $user['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </form>
                </td>
            </tr>
        <?php endwhile; ?>
    </tbody>
</table>
