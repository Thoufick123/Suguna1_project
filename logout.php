<?php
// logout.php - Destroy session and redirect to login
require_once __DIR__ . '/includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!empty($_SESSION['user_id'])) {
    try {
        $pdo = Database::getConnection();
        $uid = (int)$_SESSION['user_id'];
        $wid = (int)($_SESSION['worker_id'] ?? 0);
        $uname = trim($_SESSION['username'] ?? '');

        // Clear active login timestamp so user/worker is marked inactive/logged out
        $pdo->prepare("UPDATE users SET last_login = NULL WHERE id = :id")->execute([':id' => $uid]);

        if ($wid > 0) {
            $pdo->prepare("UPDATE workers SET last_login = NULL WHERE id = :id")->execute([':id' => $wid]);
        }
        if (!empty($uname)) {
            $pdo->prepare("UPDATE workers SET last_login = NULL WHERE LOWER(unique_id) = LOWER(:u) OR REPLACE(unique_id, '-', '') = REPLACE(:u_clean, '-', '')")
                ->execute([':u' => $uname, ':u_clean' => $uname]);
        }
    } catch (Exception $e) {}
}

logoutUser();
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");
header("Location: login.php?logged_out=1");
exit;

