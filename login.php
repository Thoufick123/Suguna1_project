<?php
// login.php - Sign In Page for FabriqPro Production Tracker
require_once __DIR__ . '/includes/auth.php';

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$redirect = trim($_POST['redirect'] ?? $_GET['redirect'] ?? '');
if (isLoggedIn()) {
    $cUser = getCurrentUser();
    if ($cUser && in_array($cUser['role'], ['admin', 'supervisor'])) {
        header("Location: " . (!empty($redirect) ? $redirect : 'dashboard.php'));
    } else {
        header("Location: " . (!empty($redirect) ? $redirect : 'daily_output.php'));
    }
    exit;
}

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || isset($_POST['ajax']) || isset($_GET['ajax']);

$username = '';
$errorMessage = '';
$successMessage = '';

if (isset($_GET['error']) && $_GET['error'] === 'inactive') {
    $errorMessage = 'Your account has been deactivated / marked Inactive. Please contact the administrator.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    $jsonInput = json_decode($rawInput, true) ?? [];
    $data = array_merge($_POST, $jsonInput);

    if (!empty($data['redirect'])) {
        $redirect = trim($data['redirect']);
    }

    $action = trim($data['action'] ?? 'login');
    $username = trim($data['username'] ?? '');
    $password = trim($data['password'] ?? '');

    $response = ['success' => false, 'message' => ''];

    if (empty($username) || empty($password)) {
        $response['message'] = 'Please enter both Username/Emp ID and Password.';
    } else {
        try {
            $pdo = Database::getConnection();

            if ($action === 'register') {
                if (strlen($password) < 4 || strlen($password) > 6) {
                    $response['message'] = 'Password must be between 4 and 6 characters long.';
                } else {
                    $checkStmt = $pdo->prepare("SELECT id FROM users WHERE LOWER(username) = LOWER(:u) LIMIT 1");
                    $checkStmt->execute([':u' => $username]);
                    if ($checkStmt->fetch()) {
                        $response['message'] = "Username or Emp ID '{$username}' is already registered.";
                    } else {
                        $cleanUser = str_replace([' ', '-', '+', '91'], '', strtolower($username));
                        $wStmt = $pdo->prepare("
                            SELECT * FROM workers 
                            WHERE unique_id = :uid 
                               OR REPLACE(unique_id, '-', '') = REPLACE(:uid_clean, '-', '')
                               OR LOWER(name) = LOWER(:name) 
                               OR LOWER(REPLACE(name, ' ', '')) = LOWER(REPLACE(:name_nospace, ' ', ''))
                               OR mobile_num LIKE :mob 
                               OR REPLACE(REPLACE(REPLACE(mobile_num, ' ', ''), '-', ''), '+', '') LIKE :clean_mob
                            LIMIT 1
                        ");
                        $wStmt->execute([
                            ':uid' => $username,
                            ':uid_clean' => $username,
                            ':name' => $username,
                            ':name_nospace' => $username,
                            ':mob' => '%' . $username . '%',
                            ':clean_mob' => '%' . $cleanUser . '%'
                        ]);
                        $worker = $wStmt->fetch();

                        if ($worker && isset($worker['status']) && strcasecmp($worker['status'], 'Inactive') === 0) {
                            $displayName = $worker['name'] ?: $worker['unique_id'];
                            $response['message'] = "Cannot register: Worker '{$displayName}' ({$worker['unique_id']}) is currently marked as Inactive. Please contact the administrator.";
                        } else {
                            $fullName = $worker ? $worker['name'] : $username;
                            $unit = $worker ? $worker['unit'] : 'Unit 1';
                            $workerId = $worker ? (int)$worker['id'] : 0;

                        if (!$workerId) {
                            $lastUidStmt = $pdo->query("SELECT unique_id FROM workers WHERE unit = 'Unit 1' ORDER BY id DESC LIMIT 1");
                            $lastUid = $lastUidStmt->fetchColumn() ?: 'PU001-0';
                            $parts = explode('-', $lastUid);
                            $nextNum = isset($parts[1]) ? (intval($parts[1]) + 1) : rand(10, 999);
                            $genUid = (strtoupper(substr($username, 0, 2)) === 'PU' && strpos($username, '-') !== false) ? $username : ('PU001-' . $nextNum);

                            $wIns = $pdo->prepare("
                                INSERT INTO workers (unique_id, name, unit, age, mobile_num, address, role, experience_years, status)
                                VALUES (:uid, :name, 'Unit 1', 25, 'N/A', 'Production Floor', 'Operator', 1.0, 'Active')
                            ");
                            $wIns->execute([
                                ':uid' => $genUid,
                                ':name' => $username
                            ]);
                            $workerId = (int)$pdo->lastInsertId();
                        }

                        $passHash = password_hash($password, PASSWORD_DEFAULT);
                        $ins = $pdo->prepare("
                            INSERT INTO users (username, password, full_name, role, unit)
                            VALUES (:username, :password, :full_name, 'operator', :unit)
                        ");
                        $ins->execute([
                            ':username' => $username,
                            ':password' => $passHash,
                            ':full_name' => $fullName,
                            ':unit' => $unit
                        ]);
                        $newUserId = (int)$pdo->lastInsertId();
                        $regNow = date('Y-m-d H:i:s');
                        try {
                            $pdo->prepare("UPDATE users SET last_login = :now WHERE id = :id")->execute([':now' => $regNow, ':id' => $newUserId]);
                            if ($workerId) {
                                $pdo->prepare("UPDATE workers SET last_login = :now WHERE id = :id")->execute([':now' => $regNow, ':id' => $workerId]);
                            }
                        } catch (Exception $e) {}

                        $_SESSION['user_id'] = $newUserId;
                        $_SESSION['username'] = $username;
                        $_SESSION['full_name'] = $fullName;
                        $_SESSION['role'] = 'operator';
                        $_SESSION['unit'] = $unit;
                        $_SESSION['worker_id'] = $workerId;

                        $targetUrl = "daily_output.php?worker_id=" . $workerId;
                        $response = [
                            'success' => true,
                            'redirect' => $targetUrl,
                            'message' => "Registration successful! Welcome, {$fullName}."
                        ];
                    }
                }
            }

            } elseif ($action === 'set_password') {
                if (strlen($password) < 4 || strlen($password) > 6) {
                    $response['message'] = 'Password must be between 4 and 6 characters long.';
                } else {
                    $passHash = password_hash($password, PASSWORD_DEFAULT);
                    $uStmt = $pdo->prepare("
                        SELECT * FROM users 
                        WHERE LOWER(username) = LOWER(:u) 
                           OR LOWER(full_name) = LOWER(:u)
                           OR REPLACE(username, '-', '') = REPLACE(:u_clean, '-', '')
                        LIMIT 1
                    ");
                    $uStmt->execute([':u' => $username, ':u_clean' => $username]);
                    $user = $uStmt->fetch(PDO::FETCH_ASSOC);

                    if ($user) {
                        if (isset($user['status']) && strcasecmp($user['status'], 'Inactive') === 0) {
                            $displayName = $user['full_name'] ?: $user['username'];
                            $response['message'] = "Cannot change password: User '{$displayName}' is currently marked as Inactive. Please contact the administrator.";
                        } else {
                            $upd = $pdo->prepare("UPDATE users SET password = :p WHERE id = :id");
                            $upd->execute([':p' => $passHash, ':id' => $user['id']]);
                            $displayName = $user['full_name'] ?: $user['username'];
                            $response = [
                                'success' => true,
                                'message' => "Password successfully set for '{$displayName}'! Please sign in.",
                                'action_type' => 'set_password'
                            ];
                        }
                    } else {
                        $cleanUser = str_replace([' ', '-', '+', '91'], '', strtolower($username));
                        $wStmt = $pdo->prepare("
                            SELECT * FROM workers 
                            WHERE unique_id = :uid 
                               OR REPLACE(unique_id, '-', '') = REPLACE(:uid_clean, '-', '')
                               OR LOWER(name) = LOWER(:name) 
                               OR LOWER(REPLACE(name, ' ', '')) = LOWER(REPLACE(:name_nospace, ' ', ''))
                               OR mobile_num LIKE :mob 
                               OR REPLACE(REPLACE(REPLACE(mobile_num, ' ', ''), '-', ''), '+', '') LIKE :clean_mob
                            LIMIT 1
                        ");
                        $wStmt->execute([
                            ':uid' => $username,
                            ':uid_clean' => $username,
                            ':name' => $username,
                            ':name_nospace' => $username,
                            ':mob' => '%' . $username . '%',
                            ':clean_mob' => '%' . $cleanUser . '%'
                        ]);
                        $worker = $wStmt->fetch(PDO::FETCH_ASSOC);

                        if ($worker) {
                            if (isset($worker['status']) && strcasecmp($worker['status'], 'Inactive') === 0) {
                                $displayName = $worker['name'] ?: $worker['unique_id'];
                                $response['message'] = "Cannot set password: Worker '{$displayName}' ({$worker['unique_id']}) is currently marked as Inactive. Please contact the administrator.";
                            } else {
                                $ins = $pdo->prepare("
                                    INSERT INTO users (username, password, full_name, role, unit, status)
                                    VALUES (:uid, :pass, :name, 'operator', :unit, 'Active')
                                ");
                                $ins->execute([
                                    ':uid' => $worker['unique_id'],
                                    ':pass' => $passHash,
                                    ':name' => $worker['name'],
                                    ':unit' => $worker['unit']
                                ]);
                                $response = [
                                    'success' => true,
                                    'message' => "Password created for employee {$worker['name']} ({$worker['unique_id']})! Please sign in.",
                                    'action_type' => 'set_password'
                                ];
                            }
                        } else {
                            $response['message'] = "No user or employee found matching '{$username}'.";
                        }
                    }
                }

            } else {
                // Step 1: Direct lookup in 'users' table
                $uStmt = $pdo->prepare("
                    SELECT * FROM users 
                    WHERE LOWER(username) = LOWER(:u) 
                       OR LOWER(full_name) = LOWER(:u) 
                       OR LOWER(REPLACE(full_name, ' ', '')) = LOWER(REPLACE(:u_ns, ' ', ''))
                       OR REPLACE(username, '-', '') = REPLACE(:u_clean, '-', '')
                    LIMIT 1
                ");
                $uStmt->execute([
                    ':u' => $username,
                    ':u_ns' => $username,
                    ':u_clean' => $username
                ]);
                $user = $uStmt->fetch(PDO::FETCH_ASSOC);

                // Step 2: If not in users table yet, check 'workers' table
                if (!$user) {
                    $cleanUser = str_replace([' ', '-', '+', '91'], '', strtolower($username));
                    $wStmt = $pdo->prepare("
                        SELECT * FROM workers 
                        WHERE unique_id = :uid 
                           OR REPLACE(unique_id, '-', '') = REPLACE(:uid_clean, '-', '')
                           OR LOWER(name) = LOWER(:name) 
                           OR LOWER(REPLACE(name, ' ', '')) = LOWER(REPLACE(:name_nospace, ' ', ''))
                           OR mobile_num LIKE :mob 
                           OR REPLACE(REPLACE(REPLACE(mobile_num, ' ', ''), '-', ''), '+', '') LIKE :clean_mob
                        LIMIT 1
                    ");
                    $wStmt->execute([
                        ':uid' => $username,
                        ':uid_clean' => $username,
                        ':name' => $username,
                        ':name_nospace' => $username,
                        ':mob' => '%' . $username . '%',
                        ':clean_mob' => '%' . $cleanUser . '%'
                    ]);
                    $worker = $wStmt->fetch(PDO::FETCH_ASSOC);

                    if ($worker) {
                        if (isset($worker['status']) && strcasecmp($worker['status'], 'Inactive') === 0) {
                            $displayName = $worker['name'] ?: $worker['unique_id'];
                            $response['message'] = "Account deactivated: Worker '{$displayName}' ({$worker['unique_id']}) is currently marked as Inactive. Login is disabled. Please contact the administrator.";
                            $user = null;
                        } else {
                            $checkExisting = $pdo->prepare("SELECT * FROM users WHERE LOWER(username) = LOWER(:uid) OR LOWER(full_name) = LOWER(:name) LIMIT 1");
                            $checkExisting->execute([':uid' => $worker['unique_id'], ':name' => $worker['name']]);
                            $user = $checkExisting->fetch(PDO::FETCH_ASSOC);

                            if (!$user) {
                                $passHash = password_hash($password, PASSWORD_DEFAULT);
                                $ins = $pdo->prepare("
                                    INSERT INTO users (username, password, full_name, role, unit, status)
                                    VALUES (:uid, :pass, :name, 'operator', :unit, 'Active')
                                ");
                                $ins->execute([
                                    ':uid' => $worker['unique_id'],
                                    ':pass' => $passHash,
                                    ':name' => $worker['name'],
                                    ':unit' => $worker['unit']
                                ]);
                                $newUid = $pdo->lastInsertId();
                                $user = [
                                    'id' => $newUid,
                                    'username' => $worker['unique_id'],
                                    'password' => $passHash,
                                    'full_name' => $worker['name'],
                                    'role' => 'operator',
                                    'unit' => $worker['unit'],
                                    'status' => 'Active'
                                ];
                            }
                        }
                    }
                }

                // Step 3: Verify credentials
                if ($user) {
                    $isUserInactive = (isset($user['status']) && strcasecmp($user['status'], 'Inactive') === 0);

                    // Cross-check associated worker profile status
                    $wFind = $pdo->prepare("SELECT id, unit, status FROM workers WHERE LOWER(unique_id) = LOWER(:uid) OR LOWER(name) = LOWER(:name) LIMIT 1");
                    $wFind->execute([':uid' => $user['username'], ':name' => $user['full_name']]);
                    $workerRow = $wFind->fetch(PDO::FETCH_ASSOC);
                    $isWorkerInactive = ($workerRow && isset($workerRow['status']) && strcasecmp($workerRow['status'], 'Inactive') === 0);

                    if ($isUserInactive || $isWorkerInactive) {
                        $displayName = $user['full_name'] ?: $user['username'];
                        $response['message'] = "Account deactivated: '{$displayName}' ({$user['username']}) is currently marked as Inactive. Login is disabled. Please contact the administrator.";
                    } elseif (password_verify($password, $user['password'])) {
                        $now = date('Y-m-d H:i:s');
                        try {
                            $updUser = $pdo->prepare("UPDATE users SET last_login = :now WHERE id = :id");
                            $updUser->execute([':now' => $now, ':id' => $user['id']]);
                        } catch (Exception $e) {}

                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['full_name'] = $user['full_name'];
                        $_SESSION['role'] = $user['role'];
                        $_SESSION['unit'] = $user['unit'];

                        $wFind = $pdo->prepare("SELECT id, unit FROM workers WHERE LOWER(unique_id) = LOWER(:uid) OR REPLACE(unique_id, '-', '') = REPLACE(:uid_clean, '-', '') LIMIT 1");
                        $wFind->execute([':uid' => $user['username'], ':uid_clean' => $user['username']]);
                        $workerRow = $wFind->fetch(PDO::FETCH_ASSOC);
                        if (!$workerRow && !empty($user['full_name'])) {
                            $wFind2 = $pdo->prepare("SELECT id, unit FROM workers WHERE LOWER(name) = LOWER(:name) ORDER BY id DESC LIMIT 1");
                            $wFind2->execute([':name' => $user['full_name']]);
                            $workerRow = $wFind2->fetch(PDO::FETCH_ASSOC);
                        }
                        if ($workerRow) {
                            $_SESSION['worker_id'] = (int)$workerRow['id'];
                            if (empty($_SESSION['unit']) || $_SESSION['unit'] === 'All') {
                                $_SESSION['unit'] = $workerRow['unit'];
                            }
                            try {
                                $updWorker = $pdo->prepare("UPDATE workers SET last_login = :now WHERE id = :wid");
                                $updWorker->execute([':now' => $now, ':wid' => $workerRow['id']]);
                            } catch (Exception $e) {}
                        }

                        // Determine smart redirect target based on user role and permissions
                        $operatorAllowedPages = ['daily_output.php', 'leaderboard.php'];
                        $redirectClean = strtok($redirect ?? '', '?');

                        if ($user['role'] === 'operator') {
                            if (!empty($redirect) && in_array($redirectClean, $operatorAllowedPages)) {
                                $targetUrl = $redirect;
                            } else {
                                $targetUrl = "daily_output.php" . (!empty($_SESSION['worker_id']) ? "?worker_id=" . (int)$_SESSION['worker_id'] : "");
                            }
                        } elseif (!empty($redirect) && !in_array($redirectClean, ['login.php', 'index.php', 'dashboard.php'])) {
                            $targetUrl = $redirect;
                        } elseif ($user['role'] === 'admin' || $user['role'] === 'supervisor') {
                            $targetUrl = "dashboard.php";
                        } else {
                            $targetUrl = "dashboard.php";
                        }

                        $displayName = $user['full_name'] ?: $user['username'];
                        $roleLabel = ucfirst($user['role']);
                        $response = [
                            'success' => true,
                            'redirect' => $targetUrl,
                            'message' => "Welcome back, {$displayName}! Signed in as {$roleLabel}."
                        ];
                    } else {
                        $displayName = $user['full_name'] ?: $user['username'];
                        $response['message'] = "Incorrect password for '{$displayName}'. Please check and try again.";
                    }
                } else {
                    $response['message'] = "User name or Emp ID '{$username}' not found. Please verify your credentials.";
                }
            }

        } catch (Exception $e) {
            $response['message'] = 'Authentication server error: ' . $e->getMessage();
        }
    }

    if ($isAjax || isset($data['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }

    if ($response['success']) {
        if (!empty($response['redirect'])) {
            header("Location: " . $response['redirect']);
            exit;
        } else {
            $successMessage = $response['message'];
        }
    } else {
        $errorMessage = $response['message'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In - FabriqPro Production Tracker</title>
    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%232563eb'%3E%3Cpath d='M12 2L2 7l10 5 10-5-10-5zm0 9l2.5-1.25L12 8.5l-2.5 1.25L12 11zm0 2.5l-5-2.5-5 2.5 10 5 10-5-5-2.5-5 2.5z'/%3E%3C/svg%3E">
    <!-- Fonts & Icons -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
    <style>
        .login-wrapper {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            position: relative;
            z-index: 1;
        }
        .login-card {
            width: 100%;
            max-width: 380px;
            background: var(--bg-card);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 1.75rem 1.75rem 1.5rem;
            box-shadow: var(--shadow-card);
        }
        .login-brand {
            text-align: center;
            margin-bottom: 1.25rem;
        }
        .login-brand .brand-icon {
            margin: 0 auto 0.5rem;
            width: 44px;
            height: 44px;
            font-size: 1.25rem;
            border-radius: var(--radius-md);
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.2), rgba(99, 102, 241, 0.15));
            border: 1px solid rgba(59, 130, 246, 0.3);
            color: var(--primary);
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .login-title {
            font-family: var(--font-heading);
            font-size: 1.4rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            letter-spacing: -0.02em;
            line-height: 1.2;
            color: var(--text-primary);
        }
        .login-subtitle {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin: 0;
            font-weight: 400;
        }
        .login-card .form-group {
            margin-bottom: 0.9rem;
            gap: 0.3rem;
        }
        .login-card .form-label {
            font-size: 0.78rem;
            margin-bottom: 0.15rem;
        }
        .login-card .form-control {
            padding: 0.55rem 0.8rem;
            font-size: 0.86rem;
            border-radius: var(--radius-md);
        }
        .login-card .btn-primary {
            padding: 0.62rem 0.85rem;
            font-size: 0.88rem;
            border-radius: var(--radius-md);
        }
        .password-field-wrapper {
            position: relative;
        }
        .password-toggle-btn {
            position: absolute;
            right: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            font-size: 0.88rem;
            padding: 0.2rem;
        }
        .password-toggle-btn:hover {
            color: var(--text-primary);
        }
        .alert-box {
            padding: 0.55rem 0.75rem;
            border-radius: var(--radius-md);
            font-size: 0.8rem;
            margin-bottom: 0.65rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            animation: alertShake 0.3s ease-out;
        }
        @keyframes alertShake {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-6px); }
            40%, 80% { transform: translateX(6px); }
        }
        .alert-danger {
            background: rgba(244, 63, 94, 0.15);
            border: 1px solid rgba(244, 63, 94, 0.3);
            color: #fb7185;
        }
        .alert-success {
            background: rgba(16, 185, 129, 0.15);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: #34d399;
        }
    </style>
</head>
<body class="theme-dark">
    <!-- Ambient Glow -->
    <div class="bg-glow bg-glow-1"></div>
    <div class="bg-glow bg-glow-2"></div>

    <div class="login-wrapper">
        <div class="login-card">
            <!-- Brand Logo -->
            <div class="login-brand">
                <div class="brand-icon">
                    <i class="fa-solid fa-industry"></i>
                </div>
                <h1 class="login-title">FABRIQ<span style="color: var(--primary);">PRO</span></h1>
                <p class="login-subtitle">Production &amp; Worker Tracker</p>
            </div>

            <!-- Dynamic Alert Box -->
            <div id="authAlertBox" class="alert-box <?= !empty($errorMessage) ? 'alert-danger' : (!empty($successMessage) ? 'alert-success' : 'alert-danger') ?>" style="display: <?= (!empty($errorMessage) || !empty($successMessage)) ? 'flex' : 'none' ?>;">
                <i class="fa-solid <?= !empty($successMessage) ? 'fa-circle-check' : 'fa-circle-exclamation' ?>" id="alertIcon"></i>
                <span id="alertMessage"><?= htmlspecialchars($errorMessage ?: $successMessage) ?></span>
            </div>

            <!-- Sign In (Login) Form -->
            <div id="loginFormContainer">
                <form id="ajaxLoginForm" method="POST" autocomplete="off">
                    <input type="hidden" name="ajax" value="1">
                    <input type="hidden" name="action" value="login">
                    <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">

                    <!-- Hidden traps to swallow browser password autofill -->
                    <input type="text" name="fake_user_trap" style="position:absolute;opacity:0;height:0;width:0;pointer-events:none;" tabindex="-1" autocomplete="off" aria-hidden="true">
                    <input type="password" name="fake_pass_trap" style="position:absolute;opacity:0;height:0;width:0;pointer-events:none;" tabindex="-1" autocomplete="new-password" aria-hidden="true">

                    <!-- Field 1: Username or Emp ID -->
                    <div class="form-group">
                        <label class="form-label" for="loginUsernameInput">
                            <i class="fa-solid fa-id-badge text-cyan"></i> User name or Emp id <span class="required">*</span>
                        </label>
                        <input type="text" id="loginUsernameInput" name="username" class="form-control" autocomplete="off" autocapitalize="none" spellcheck="false" placeholder="Enter username or Emp ID (e.g. admin, PU001-1)" value="" required>
                    </div>

                    <!-- Field 2: Password -->
                    <div class="form-group">
                        <label class="form-label" for="loginPasswordInput">
                            <i class="fa-solid fa-lock text-cyan"></i> Password <span class="required">*</span>
                        </label>
                        <div class="password-field-wrapper">
                            <input type="password" id="loginPasswordInput" name="password" class="form-control" autocomplete="new-password" placeholder="Enter your password" value="" required>
                            <button type="button" class="password-toggle-btn" onclick="togglePassVisibility('loginPasswordInput', 'loginEyeIcon')" title="Show / Hide Password">
                                <i class="fa-solid fa-eye" id="loginEyeIcon"></i>
                            </button>
                        </div>
                    </div>

                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.95rem; font-size: 0.8rem; margin-top: 0.4rem;">
                        <label style="display: flex; align-items: center; gap: 0.4rem; cursor: pointer; color: var(--text-secondary);">
                            <input type="checkbox" name="remember" style="accent-color: var(--primary);"> Remember Me
                        </label>
                        <a href="javascript:void(0)" onclick="openResetModal()" style="color: var(--accent-cyan, #06b6d4); text-decoration: none; font-weight: 600; font-size: 0.78rem; transition: all 0.2s;">
                            <i class="fa-solid fa-key" style="font-size: 0.72rem;"></i> Forgot Password?
                        </a>
                    </div>

                    <button type="submit" id="loginSubmitBtn" class="btn btn-primary" style="width: 100%;">
                        <i class="fa-solid fa-right-to-bracket"></i> <span id="loginBtnText">Sign In to Dashboard</span>
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Forgot / Reset Password Modal -->
    <div id="resetPasswordModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.75); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); z-index: 9999; align-items: center; justify-content: center; padding: 1.5rem;">
        <div style="width: 100%; max-width: 380px; background: var(--bg-card, #1e293b); border: 1px solid var(--border-color, rgba(255,255,255,0.1)); border-radius: var(--radius-lg, 1rem); padding: 1.75rem; box-shadow: 0 20px 40px rgba(0,0,0,0.6); position: relative;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem;">
                <h3 style="margin: 0; font-size: 1.15rem; font-weight: 700; color: #fff; display: flex; align-items: center; gap: 0.5rem;">
                    <i class="fa-solid fa-key" style="color: var(--accent-cyan, #06b6d4);"></i> Reset Password
                </h3>
                <button type="button" onclick="closeResetModal()" style="background: none; border: none; color: var(--text-muted, #94a3b8); font-size: 1.1rem; cursor: pointer; padding: 0.25rem;">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <div id="resetAlertBox" class="alert-box alert-danger" style="display: none; margin-bottom: 1rem;">
                <i class="fa-solid fa-circle-exclamation" id="resetAlertIcon"></i>
                <span id="resetAlertMessage"></span>
            </div>

            <form id="ajaxResetPasswordForm" autocomplete="off">
                <input type="hidden" name="ajax" value="1">
                <input type="hidden" name="action" value="set_password">

                <div class="form-group" style="margin-bottom: 1rem;">
                    <label class="form-label" for="resetUsernameInput" style="font-size: 0.82rem; font-weight: 600; color: #cbd5e1; display: block; margin-bottom: 0.35rem;">
                        <i class="fa-solid fa-id-badge text-cyan"></i> Username, Emp ID or Name <span style="color: #f43f5e;">*</span>
                    </label>
                    <input type="text" id="resetUsernameInput" name="username" class="form-control" placeholder="e.g. PU001-4 or sugi" required style="width: 100%; padding: 0.65rem 0.85rem; background: rgba(15,23,42,0.6); border: 1px solid rgba(255,255,255,0.12); border-radius: var(--radius-md, 0.5rem); color: #fff; font-size: 0.88rem;">
                </div>

                <div class="form-group" style="margin-bottom: 0.75rem;">
                    <label class="form-label" for="resetPasswordInput" style="font-size: 0.82rem; font-weight: 600; color: #cbd5e1; display: block; margin-bottom: 0.35rem;">
                        <i class="fa-solid fa-lock text-cyan"></i> New Password (4 to 6 chars) <span style="color: #f43f5e;">*</span>
                    </label>
                    <div class="password-field-wrapper" style="position: relative;">
                        <input type="password" id="resetPasswordInput" name="password" class="form-control" placeholder="4 - 6 characters" minlength="4" maxlength="6" required style="width: 100%; padding: 0.65rem 2.5rem 0.65rem 0.85rem; background: rgba(15,23,42,0.6); border: 1px solid rgba(255,255,255,0.12); border-radius: var(--radius-md, 0.5rem); color: #fff; font-size: 0.88rem;">
                        <button type="button" class="password-toggle-btn" onclick="togglePassVisibility('resetPasswordInput', 'resetEyeIcon')" title="Show / Hide Password" style="position: absolute; right: 0.75rem; top: 50%; transform: translateY(-50%); background: none; border: none; color: #94a3b8; cursor: pointer;">
                            <i class="fa-solid fa-eye" id="resetEyeIcon"></i>
                        </button>
                    </div>
                    <small id="resetPassHelp" style="color: var(--text-muted, #94a3b8); font-size: 0.72rem; display: block; margin-top: 0.25rem;">
                        Password must be 4 to 6 characters long.
                    </small>
                </div>

                <div style="display: flex; gap: 0.5rem; margin-top: 1.25rem;">
                    <button type="button" onclick="closeResetModal()" class="btn btn-secondary" style="flex: 1; padding: 0.65rem; background: rgba(148,163,184,0.15); border: 1px solid rgba(148,163,184,0.25); border-radius: var(--radius-md, 0.5rem); color: #cbd5e1; font-weight: 600; cursor: pointer;">
                        Cancel
                    </button>
                    <button type="submit" id="resetSubmitBtn" class="btn btn-primary" style="flex: 2; padding: 0.65rem; background: var(--primary, #2563eb); border: none; border-radius: var(--radius-md, 0.5rem); color: #fff; font-weight: 600; cursor: pointer;">
                        <span id="resetBtnText"><i class="fa-solid fa-floppy-disk"></i> Save New Password</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Clear login credentials on load, logout, or bfcache navigation
        let userHasTyped = false;
        ['input', 'keydown', 'paste'].forEach(evt => {
            document.addEventListener(evt, (e) => {
                if (e.target && (e.target.id === 'loginUsernameInput' || e.target.id === 'loginPasswordInput')) {
                    userHasTyped = true;
                }
            }, { capture: true, passive: true });
        });

        function clearLoginCredentials(force = false) {
            if (userHasTyped && !force) return;
            const uInput = document.getElementById('loginUsernameInput');
            const pInput = document.getElementById('loginPasswordInput');
            const form = document.getElementById('ajaxLoginForm');
            if (form) form.reset();
            if (uInput) {
                uInput.value = '';
                uInput.defaultValue = '';
            }
            if (pInput) {
                pInput.value = '';
                pInput.defaultValue = '';
            }
        }

        clearLoginCredentials(true);
        document.addEventListener('DOMContentLoaded', () => clearLoginCredentials(false));
        window.addEventListener('load', () => {
            clearLoginCredentials(false);
            setTimeout(() => clearLoginCredentials(false), 50);
            setTimeout(() => clearLoginCredentials(false), 150);
            setTimeout(() => clearLoginCredentials(false), 300);
        });
        window.addEventListener('pageshow', (event) => {
            clearLoginCredentials(true);
            setTimeout(() => clearLoginCredentials(true), 50);
        });

        // Show/Hide Password Visibility Toggle
        function togglePassVisibility(inputId, iconId) {
            const input = document.getElementById(inputId);
            const icon = document.getElementById(iconId);
            if (input && icon) {
                const isPass = input.type === 'password';
                input.type = isPass ? 'text' : 'password';
                icon.className = isPass ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
            }
        }

        // AJAX Form Handler for Login
        const loginForm = document.getElementById('ajaxLoginForm');
        const submitBtn = document.getElementById('loginSubmitBtn');
        const btnText = document.getElementById('loginBtnText');
        const alertBox = document.getElementById('authAlertBox');
        const alertMessage = document.getElementById('alertMessage');
        const alertIcon = document.getElementById('alertIcon');

        if (loginForm) {
            loginForm.addEventListener('submit', async (e) => {
                e.preventDefault();

                submitBtn.disabled = true;
                btnText.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Signing in...';
                alertBox.style.display = 'none';

                const formData = new FormData(loginForm);

                try {
                    const fetchUrl = 'login.php' + (window.location.search || '');
                    const res = await fetch(fetchUrl, {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });

                    const data = await res.json();

                    if (data.success) {
                        alertBox.className = 'alert-box alert-success';
                        alertIcon.className = 'fa-solid fa-circle-check';
                        alertMessage.innerText = data.message || 'Success! Redirecting...';
                        alertBox.style.display = 'flex';

                        setTimeout(() => {
                            window.location.href = data.redirect || 'dashboard.php';
                        }, 500);
                    } else {
                        alertBox.className = 'alert-box alert-danger';
                        alertIcon.className = 'fa-solid fa-circle-exclamation';
                        alertMessage.innerText = data.message || 'Operation failed. Please try again.';
                        alertBox.style.display = 'flex';

                        submitBtn.disabled = false;
                        btnText.innerText = 'Sign In to Dashboard';
                    }
                } catch (err) {
                    alertBox.className = 'alert-box alert-danger';
                    alertIcon.className = 'fa-solid fa-circle-exclamation';
                    alertMessage.innerText = 'Network connection error. Please try again.';
                    alertBox.style.display = 'flex';

                    submitBtn.disabled = false;
                    btnText.innerText = 'Sign In to Dashboard';
                }
            });
        }

        // Reset Password Modal Functions
        function openResetModal() {
            const modal = document.getElementById('resetPasswordModal');
            const alertBox = document.getElementById('resetAlertBox');
            if (alertBox) alertBox.style.display = 'none';
            const form = document.getElementById('ajaxResetPasswordForm');
            if (form) form.reset();
            const currUser = document.getElementById('loginUsernameInput')?.value;
            if (currUser && document.getElementById('resetUsernameInput')) {
                document.getElementById('resetUsernameInput').value = currUser;
            }
            if (modal) modal.style.display = 'flex';
        }

        function closeResetModal() {
            const modal = document.getElementById('resetPasswordModal');
            if (modal) modal.style.display = 'none';
        }

        const resetForm = document.getElementById('ajaxResetPasswordForm');
        const resetSubmitBtn = document.getElementById('resetSubmitBtn');
        const resetBtnText = document.getElementById('resetBtnText');
        const resetAlertBox = document.getElementById('resetAlertBox');
        const resetAlertMessage = document.getElementById('resetAlertMessage');
        const resetAlertIcon = document.getElementById('resetAlertIcon');

        if (resetForm) {
            resetForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                resetSubmitBtn.disabled = true;
                resetBtnText.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';
                resetAlertBox.style.display = 'none';

                const formData = new FormData(resetForm);
                try {
                    const res = await fetch('login.php', {
                        method: 'POST',
                        body: formData,
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    });
                    const data = await res.json();
                    if (data.success) {
                        closeResetModal();
                        // Show success alert in main login card
                        alertBox.className = 'alert-box alert-success';
                        alertIcon.className = 'fa-solid fa-circle-check';
                        alertMessage.innerText = data.message || 'Password successfully updated! Please sign in.';
                        alertBox.style.display = 'flex';

                        // Pre-fill username and password in main form
                        const uVal = document.getElementById('resetUsernameInput')?.value || '';
                        const pVal = document.getElementById('resetPasswordInput')?.value || '';
                        if (document.getElementById('loginUsernameInput')) document.getElementById('loginUsernameInput').value = uVal;
                        if (document.getElementById('loginPasswordInput')) document.getElementById('loginPasswordInput').value = pVal;
                    } else {
                        resetAlertBox.className = 'alert-box alert-danger';
                        resetAlertIcon.className = 'fa-solid fa-circle-exclamation';
                        resetAlertMessage.innerText = data.message || 'Failed to reset password.';
                        resetAlertBox.style.display = 'flex';
                    }
                } catch (err) {
                    resetAlertBox.className = 'alert-box alert-danger';
                    resetAlertIcon.className = 'fa-solid fa-circle-exclamation';
                    resetAlertMessage.innerText = 'Network error: ' + err.message;
                    resetAlertBox.style.display = 'flex';
                } finally {
                    resetSubmitBtn.disabled = false;
                    resetBtnText.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save New Password';
                }
            });
        }
    </script>
</body>
</html>
