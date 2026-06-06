<?php
// session_start();
// session_destroy();
// header("Location: index.php");
// exit();


require_once 'includes/session_helper.php';
start_user_session();
$_SESSION = array(); // Clear all variables
session_destroy();   // Destroy the session
header("Location: index.php?logged_out");
exit();

?>
