<?php
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $mysqli = new mysqli('localhost', 'root', '', 'user_management');

    if ($mysqli->connect_errno) {
        die("Database connection failed: " . $mysqli->connect_error);
    }

    $id = intval($_POST['id']);

    // Update status from Archived → Pending
    $update = $mysqli->query("UPDATE document_request SET status='Pending' WHERE id=$id");

    if ($update) {
        echo "Document successfully unarchived!";
    } else {
        echo "Error unarchiving document: " . $mysqli->error;
    }

    $mysqli->close();
}
?>
