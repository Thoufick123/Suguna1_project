<?php
// piece_entry.php - Permanent redirect to Today's Clothes & Workstation (daily_output.php)
require_once __DIR__ . '/includes/auth.php';
requireAuth();

$queryParams = [];
if (!empty($_GET['worker_id'])) {
    $queryParams['worker_id'] = intval($_GET['worker_id']);
}
if (!empty($_GET['unit'])) {
    $queryParams['unit'] = trim($_GET['unit']);
}
if (!empty($_GET['date'])) {
    $queryParams['date'] = trim($_GET['date']);
}

$targetUrl = 'daily_output.php' . (!empty($queryParams) ? ('?' . http_build_query($queryParams)) : '');

header("Location: " . $targetUrl, true, 302);
exit;
