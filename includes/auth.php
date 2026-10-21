<?php
// includes/auth.php - Authentication & Session Helper
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../db.php';

function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function getCurrentUser(): ?array {
    if (!isLoggedIn()) return null;
    $workerId = $_SESSION['worker_id'] ?? null;
    if (!empty($_SESSION['username'])) {
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare("SELECT id, unit FROM workers WHERE id = :wid OR LOWER(unique_id) = LOWER(:u) OR LOWER(name) = LOWER(:n) LIMIT 1");
            $stmt->execute([':wid' => (int)$workerId, ':u' => $_SESSION['username'], ':n' => $_SESSION['full_name'] ?? '']);
            $w = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($w) {
                $workerId = (int)$w['id'];
                $_SESSION['worker_id'] = $workerId;
                if (($_SESSION['role'] ?? '') === 'operator' || empty($_SESSION['unit']) || $_SESSION['unit'] === 'All') {
                    $_SESSION['unit'] = $w['unit'];
                }
            }
        } catch (Exception $e) {}
    }
    return [
        'id' => $_SESSION['user_id'] ?? null,
        'worker_id' => $workerId,
        'username' => $_SESSION['username'] ?? '',
        'full_name' => $_SESSION['full_name'] ?? 'User',
        'role' => $_SESSION['role'] ?? 'user',
        'unit' => $_SESSION['unit'] ?? 'All'
    ];
}

function requireAuth(): void {
    if (!isLoggedIn()) {
        $currentPage = basename($_SERVER['PHP_SELF']);
        $queryString = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
        $redirectParam = ($currentPage === 'index.php' && empty($queryString)) ? '' : '?redirect=' . urlencode($currentPage . $queryString);
        header("Location: login.php" . $redirectParam);
        exit;
    }
}

function requireAdmin(): void {
    requireAuth();
    $user = getCurrentUser();
    if ($user['role'] !== 'admin') {
        header("Location: leaderboard.php?error=unauthorized");
        exit;
    }
}

function logoutUser(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}
