<?php
session_start();

// Unset all session variables related to the admin
unset($_SESSION['admin_id']);
unset($_SESSION['admin_logged_in']);

// Redirect to the admin login page
header("Location: login.php");
exit;
?>
