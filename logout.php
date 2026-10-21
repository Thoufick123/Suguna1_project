<?php
// logout.php - Destroy session and redirect to login
require_once __DIR__ . '/includes/auth.php';
logoutUser();
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");
header("Location: login.php?logged_out=1");
exit;
