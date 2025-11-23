<!DOCTYPE html> 
<html lang="en">
<head>
	<meta charset="UTF-8">
	<title>View Requests</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
	<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body style="background:#f8f9fa;">

<div class="container my-4">
<div class="card shadow-sm">
	<div class="card-header bg-dark text-white fw-bold">REQUESTS</div>
	<div class="card-body p-0">

		<!-- ✅ Toolbar -->
		<div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom">
			<div>
				<!-- 🔹 Back Button -->
				<a href="http://localhost/PEPO/admin_dashboard.php?view=documents" class="btn btn-sm btn-outline-dark">
					<i class="bi bi-arrow-left-circle"></i> Back
				</a>
				<!-- 🔹 Button to Open Archived Modal -->
				<button class="btn btn-sm btn-secondary" data-bs-toggle="modal" data-bs-target="#archivedModal">
					<i class="bi bi-archive"></i> Archived Documents
				</button>
			</div>

			<div class="d-flex align-items-center gap-2">
				<input type="date" class="form-control form-control-sm">
				<input type="text" class="form-control form-control-sm" placeholder="Pre-repair no.">
				<button class="btn btn-sm btn-primary">
					<i class="bi bi-search"></i>
				</button>
			</div>
		</div>

		<!-- ✅ PHP Backend -->
		<?php
		$mysqli = new mysqli('localhost', 'root', '', 'user_management');
		if ($mysqli->connect_errno) {
			die("<div class='alert alert-danger m-3'>Database connection failed: " . $mysqli->connect_error . "</div>");
		}

		// ✅ Handle Approve, Archive, and Unarchive actions
		if (isset($_POST['action']) && isset($_POST['id'])) {
			$id = intval($_POST['id']);
			$action = $_POST['action'];

			if ($action === 'approve') {
				$status = 'Approved';
			} elseif ($action === 'archive') {
				$status = 'Archived';
			} elseif ($action === 'unarchive') {
				$status = 'Pending';
			}

			$update = $mysqli->prepare("UPDATE documents SET status = ? WHERE id = ?");
			$update->bind_param('si', $status, $id);
			if ($update->execute()) {
				echo "<div class='alert alert-success m-3'>Request #$id has been <strong>$status</strong>.</div>";
			} else {
				echo "<div class='alert alert-danger m-3'>Failed to update request.</div>";
			}
			$update->close();
		}

		// ✅ Main table: show only Pending or Approved requests
		$mainResult = $mysqli->query("
			SELECT id, pre_repair_no, property_no, date_requested, status, designation_of_property
			FROM documents
			WHERE status IN ('Pending', 'Approved')
			ORDER BY date_requested DESC
		");

		// ✅ Archived documents (for modal)
		$archivedResult = $mysqli->query("
			SELECT id, pre_repair_no, property_no, date_requested, status, designation_of_property
			FROM documents
			WHERE status = 'Archived'
			ORDER BY date_requested DESC
		");
		?>

		<!-- ✅ Main Table -->
		<div class="table-responsive">
			<table class="table table-hover table-striped align-middle mb-0">
				<thead class="table-light">
					<tr>
						<th scope="col">Pre-Repair No.</th>
						<th scope="col">Designation of Property</th>
						<th scope="col">Property No.</th>
						<th scope="col">Date Requested</th>
						<th scope="col">Status</th>
						<th scope="col">Action</th>
					</tr>
				</thead>
				<tbody>
				<?php
				if ($mainResult && $mainResult->num_rows > 0) {
					while ($row = $mainResult->fetch_assoc()) {
						$statusClass = match ($row['status']) {
							'Approved' => 'success',
							'Pending' => 'warning',
							default => 'light'
						};

						echo "<tr>";
						echo "<td>" . htmlspecialchars($row['pre_repair_no']) . "</td>";
						echo "<td>" . htmlspecialchars($row['designation_of_property'] ?? '-') . "</td>";
						echo "<td>" . htmlspecialchars($row['property_no']) . "</td>";
						echo "<td>" . htmlspecialchars($row['date_requested']) . "</td>";
						echo "<td><span class='badge bg-$statusClass'>" . htmlspecialchars($row['status']) . "</span></td>";
						echo "<td>
								<form method='POST' class='d-inline'>
									<input type='hidden' name='id' value='" . $row['id'] . "'>
									<button type='submit' name='action' value='approve' class='btn btn-success btn-sm' title='Approve'>
										<i class='bi bi-check-circle'></i>
									</button>
									<button type='submit' name='action' value='archive' class='btn btn-secondary btn-sm' title='Archive'>
										<i class='bi bi-archive'></i>
									</button>
								</form>
							  </td>";
						echo "</tr>";
					}
				} else {
					echo "<tr><td colspan='6' class='text-center text-muted'>No Pending or Approved requests found.</td></tr>";
				}
				?>
				</tbody>
			</table>
		</div>

		<!-- ✅ Pagination Placeholder -->
		<div class="d-flex justify-content-between align-items-center px-3 py-2 border-top">
			<small class="text-muted">Showing Pending and Approved requests</small>
			<div>
				<a href="#">First</a> | 
				<a href="#">Previous</a> | 
				<a href="#">Next</a> | 
				<a href="#">Last</a>
			</div>
		</div>
	</div>
</div>
</div>

<!-- ✅ Archived Documents Modal -->
<div class="modal fade" id="archivedModal" tabindex="-1" aria-labelledby="archivedModalLabel" aria-hidden="true">
	<div class="modal-dialog modal-lg modal-dialog-scrollable">
		<div class="modal-content">
			<div class="modal-header bg-secondary text-white">
				<h5 class="modal-title" id="archivedModalLabel"><i class="bi bi-archive"></i> Archived Documents</h5>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
			</div>
			<div class="modal-body">
				<div class="table-responsive">
					<table class="table table-hover table-striped align-middle mb-0">
						<thead class="table-light">
							<tr>
								<th scope="col">Pre-Repair No.</th>
								<th scope="col">Designation of Property</th>
								<th scope="col">Property No.</th>
								<th scope="col">Date Requested</th>
								<th scope="col">Status</th>
								<th scope="col">Action</th>
							</tr>
						</thead>
						<tbody>
						<?php
						if ($archivedResult && $archivedResult->num_rows > 0) {
							while ($row = $archivedResult->fetch_assoc()) {
								echo "<tr>";
								echo "<td>" . htmlspecialchars($row['pre_repair_no']) . "</td>";
								echo "<td>" . htmlspecialchars($row['designation_of_property'] ?? '-') . "</td>";
								echo "<td>" . htmlspecialchars($row['property_no']) . "</td>";
								echo "<td>" . htmlspecialchars($row['date_requested']) . "</td>";
								echo "<td><span class='badge bg-secondary'>Archived</span></td>";
								echo "<td>
										<form method='POST' class='d-inline'>
											<input type='hidden' name='id' value='" . $row['id'] . "'>
											<button type='submit' name='action' value='unarchive' class='btn btn-warning btn-sm' title='Unarchive'>
												<i class='bi bi-arrow-up-circle'></i>
											</button>
										</form>
									</td>";
								echo "</tr>";
							}
						} else {
							echo "<tr><td colspan='6' class='text-center text-muted'>No archived documents found.</td></tr>";
						}
						?>
						</tbody>
					</table>
				</div>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
					<i class="bi bi-x-circle"></i> Close
				</button>
			</div>
		</div>
	</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
