<?php
// index.php - Worker Registration Page
require_once __DIR__ . '/includes/auth.php';
requireAuth();

$currentUser = getCurrentUser();
// Operators are not allowed to access worker registration; direct them immediately to "My Stitched Clothes"
if ($currentUser && $currentUser['role'] === 'operator') {
    $target = !empty($currentUser['worker_id']) ? "daily_output.php?worker_id=" . (int)$currentUser['worker_id'] : "daily_output.php";
    header("Location: " . $target);
    exit;
}

$pageTitle = 'Worker Registration - FabriqPro';
require_once __DIR__ . '/db.php';
$pdo = Database::getConnection();

// Quick stats for top ticker
$totalWorkers = $pdo->query("SELECT COUNT(*) FROM workers")->fetchColumn() ?: 0;
$unit1Count = $pdo->query("SELECT COUNT(*) FROM workers WHERE unit = 'Unit 1'")->fetchColumn() ?: 0;
$unit2Count = $pdo->query("SELECT COUNT(*) FROM workers WHERE unit = 'Unit 2'")->fetchColumn() ?: 0;

// Fetch all production units
$productionUnits = $pdo->query("
    SELECT 
        pu.*,
        (SELECT COUNT(*) FROM workers WHERE unit = pu.unit_name) as worker_count
    FROM production_units pu 
    ORDER BY pu.id ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Active units for registration selection
$activeUnits = array_filter($productionUnits, function($u) {
    return ($u['status'] ?? 'Active') !== 'Disabled';
});
$activeUnitNames = array_column($activeUnits, 'unit_name');
if (empty($activeUnitNames)) {
    $activeUnitNames = array_column($productionUnits, 'unit_name');
}

$preselectedUnit = trim($_GET['unit'] ?? ($activeUnitNames[0] ?? 'Unit 1'));
if (!in_array($preselectedUnit, $activeUnitNames)) {
    $preselectedUnit = $activeUnitNames[0] ?? 'Unit 1';
}

// Fetch admin-defined roles
$rolesStmt = $pdo->query("SELECT id, role_name FROM worker_roles ORDER BY role_name ASC");
$adminRoles = $rolesStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch admin-defined system access roles
try {
    $sysRolesStmt = $pdo->query("SELECT id, role_name, role_key, access_tier, description, is_system_default FROM system_access_roles ORDER BY id ASC");
    $allSystemRoles = $sysRolesStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $allSystemRoles = [];
}
if (empty($allSystemRoles)) {
    $allSystemRoles = [
        ['id' => 1, 'role_name' => 'Operator (Workstation Output & IoT Only)', 'role_key' => 'operator', 'access_tier' => 'operator', 'description' => 'Workstation & IoT Only - Default Privileges', 'is_system_default' => 1],
        ['id' => 2, 'role_name' => 'Supervisor (Unit Management & Approvals)', 'role_key' => 'supervisor', 'access_tier' => 'supervisor', 'description' => 'Unit Management & Approvals', 'is_system_default' => 1],
        ['id' => 3, 'role_name' => 'Administrator (Full Factory Access & Configuration)', 'role_key' => 'admin', 'access_tier' => 'admin', 'description' => 'Full Factory Access & Configuration', 'is_system_default' => 1]
    ];
}

// Calculate next sequential worker ID for any unit dynamically
function getNextWorkerId($pdo, $unit) {
    $prefix = 'PU001';
    $uStmt = $pdo->prepare("SELECT unit_code FROM production_units WHERE unit_name = :unit OR unit_title = :unit LIMIT 1");
    $uStmt->execute([':unit' => $unit]);
    $foundCode = $uStmt->fetchColumn();
    if ($foundCode) {
        $prefix = strtoupper(trim($foundCode));
    } elseif (preg_match('/(\d+)/', $unit, $m)) {
        $prefix = 'PU' . sprintf('%03d', (int)$m[1]);
    }

    $stmt = $pdo->prepare("SELECT unique_id FROM workers WHERE unit = :unit OR unique_id LIKE :prefix_like");
    $stmt->execute([':unit' => $unit, ':prefix_like' => $prefix . '%']);
    $allIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $maxNum = 0;
    foreach ($allIds as $existingId) {
        if (preg_match('/^' . preg_quote($prefix, '/') . '[-_]?(\d+)$/i', trim($existingId), $m)) {
            $num = (int)$m[1];
            if ($num > $maxNum) {
                $maxNum = $num;
            }
        }
    }
    return $prefix . '-' . ($maxNum + 1);
}

$initialUniqueId = getNextWorkerId($pdo, $preselectedUnit);

include __DIR__ . '/includes/header.php';
?>

<!-- Page Header Banner -->
<div class="page-header">
    <div class="page-header-title">
        <h1><i class="fa-solid fa-user-plus text-cyan"></i> Worker Profile Registration</h1>
        <p>Onboard workforce to Production Unit 1 or Unit 2 to track piece production and leaderboard rankings.</p>
    </div>
    <div class="page-header-actions">
        <a href="daily_output.php" class="btn btn-outline">
            <i class="fa-solid fa-shirt"></i> Today's Clothes
        </a>
        <a href="leaderboard.php" class="btn btn-primary">
            <i class="fa-solid fa-trophy"></i> Open Leaderboard
        </a>
    </div>
</div>


<!-- Mini Stats Overview (Clickable Unit Cards) -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon primary">
            <i class="fa-solid fa-users"></i>
        </div>
        <div class="stat-info">
            <div class="stat-label">Total Registered Workers</div>
            <div class="stat-value"><?= number_format($totalWorkers) ?></div>
            <div class="stat-sub">Across All Units</div>
        </div>
    </div>

    <?php foreach ($productionUnits as $pu): 
        $uColor = !empty($pu['color']) ? $pu['color'] : 'cyan';
        $uIcon = !empty($pu['icon']) ? $pu['icon'] : 'fa-industry';
        $isDis = (($pu['status'] ?? 'Active') === 'Disabled');
    ?>
    <a href="unit_workers.php?unit=<?= urlencode($pu['unit_name']) ?>" class="stat-card" style="border-left: 3px solid var(--accent-<?= htmlspecialchars($uColor) ?>, #06b6d4); <?= $isDis ? 'opacity: 0.7; border-style: dashed;' : '' ?>" title="Click to view <?= htmlspecialchars($pu['unit_title']) ?> Registered Workers">
        <div class="stat-icon <?= ($uColor === 'pink') ? 'pink' : (($uColor === 'emerald') ? 'emerald' : 'cyan') ?>">
            <i class="fa-solid <?= htmlspecialchars($uIcon) ?>"></i>
        </div>
        <div class="stat-info">
            <div class="stat-label" style="display: flex; align-items: center; gap: 0.35rem;">
                <?= htmlspecialchars($pu['unit_title']) ?>
                <?php if ($isDis): ?>
                    <span class="badge-pill" style="font-size: 0.65rem; background: rgba(244,63,94,0.15); color: #f43f5e; padding: 0.05rem 0.35rem;">Disabled</span>
                <?php endif; ?>
            </div>
            <div class="stat-value" style="color: var(--accent-<?= htmlspecialchars($uColor) ?>, #06b6d4);"><?= number_format((int)$pu['worker_count']) ?></div>
            <div class="card-click-hint" style="color: var(--accent-<?= htmlspecialchars($uColor) ?>, #06b6d4);">
                <span>View Registered Workers</span> <i class="fa-solid fa-arrow-right"></i>
            </div>
        </div>
    </a>
    <?php endforeach; ?>
</div>

<!-- Success Banner Container (Hidden until registered) -->
<div id="registrationSuccessBox" class="card" style="display: none; margin-bottom: 2rem; border-color: var(--accent-emerald); background: rgba(16, 185, 129, 0.08);">
    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
        <div style="display: flex; align-items: center; gap: 1rem;">
            <div style="width: 48px; height: 48px; border-radius: 50%; background: var(--accent-emerald); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 1.5rem;">
                <i class="fa-solid fa-check"></i>
            </div>
            <div>
                <div style="display: flex; align-items: center; gap: 0.6rem; flex-wrap: wrap;">
                    <h3 style="font-size: 1.15rem; color: var(--accent-emerald); margin: 0;">Worker Successfully Enrolled!</h3>
                    <span class="badge-pill" style="background: rgba(6,182,212,0.15); color: #06b6d4; border: 1px solid rgba(6,182,212,0.3); font-size: 0.78rem;">
                        <i class="fa-solid fa-user-shield"></i> Role: <strong id="newWorkerSuccessRole">Operator</strong>
                    </span>
                </div>
                <p class="text-muted" style="font-size: 0.9rem; margin-top: 0.35rem;">
                    <strong id="newWorkerSuccessName">Worker</strong> (ID: <span id="newWorkerSuccessId" class="text-cyan font-bold">PU001-1</span>) is now active in <strong id="newWorkerSuccessUnit">Unit 1</strong>. Login credentials created &amp; active!
                </p>
            </div>
        </div>
        <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
            <a href="login.php" class="btn btn-outline btn-sm" target="_blank" title="Test User Sign In">
                <i class="fa-solid fa-arrow-right-to-bracket text-cyan"></i> Open Login Page
            </a>
            <a id="quickLogLink" href="daily_output.php" class="btn btn-success btn-sm">
                <i class="fa-solid fa-shirt"></i> Open Today's Clothes
            </a>
        </div>
    </div>
</div>

<!-- Main Registration Form Card -->
<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fa-solid fa-id-card"></i> Workforce Registration Form
        </div>
        <div style="display: flex; gap: 0.5rem; align-items: center;">
            <span class="text-muted" style="font-size: 0.85rem; margin-right: 0.5rem;"><span class="required" style="color:var(--accent-rose)">*</span> Required fields</span>
        </div>
    </div>

    <form id="workerRegisterForm" autocomplete="off">
        <div class="form-grid">

            <!-- 1. Production Unit Selector -->
            <div class="col-12">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                    <label class="form-label" style="margin-bottom: 0;"><i class="fa-solid fa-building-flag"></i> Select Production Unit <span class="required">*</span></label>
                    <div style="font-size: 0.8rem; display: flex; gap: 0.75rem;">
                        <a href="unit_workers.php" style="color: var(--accent-cyan); text-decoration: none; font-weight: 600;">
                            <i class="fa-solid fa-building-user"></i> View All Units (<?= count($productionUnits) ?>)
                        </a>
                    </div>
                </div>

                <div class="unit-selector-grid">
                    <?php foreach ($productionUnits as $pu): 
                        $isChecked = ($preselectedUnit === $pu['unit_name']);
                        $isDis = (($pu['status'] ?? 'Active') === 'Disabled');
                        $uColor = !empty($pu['color']) ? $pu['color'] : 'cyan';
                        $uIcon = !empty($pu['icon']) ? $pu['icon'] : 'fa-industry';
                    ?>
                    <label class="unit-card-option" style="<?= $isDis ? 'opacity: 0.55; cursor: not-allowed;' : '' ?>">
                        <input type="radio" name="unit" value="<?= htmlspecialchars($pu['unit_name']) ?>" <?= $isChecked ? 'checked' : '' ?> <?= $isDis ? 'disabled' : '' ?> required>
                        <div class="unit-card-box unit-<?= htmlspecialchars($uColor) ?>-box">
                            <div class="unit-card-icon">
                                <i class="fa-solid <?= htmlspecialchars($uIcon) ?>"></i>
                            </div>
                            <div style="flex: 1;">
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <span class="unit-card-title">
                                        <?= htmlspecialchars($pu['unit_title']) ?>
                                        <?php if ($isDis): ?>
                                            <span class="badge-pill" style="font-size: 0.68rem; background: rgba(244,63,94,0.2); color: #f43f5e; border: 1px solid rgba(244,63,94,0.3); margin-left: 0.35rem;">(Disabled)</span>
                                        <?php endif; ?>
                                    </span>
                                    <span class="badge-pill unit-<?= htmlspecialchars($uColor) ?>-pill"><?= number_format((int)$pu['worker_count']) ?> Workers</span>
                                </div>
                                <span class="unit-card-desc"><?= htmlspecialchars($pu['description']) ?></span>
                            </div>
                        </div>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- 2. Unique Worker ID & Full Name -->
            <div class="col-6">
                <div class="form-group">
                    <div style="display: flex; justify-content: space-between; align-items: center; min-height: 28px; margin-bottom: 0.45rem;">
                        <label class="form-label" for="uniqueIdInput" style="margin-bottom: 0;">
                            <i class="fa-solid fa-fingerprint text-cyan"></i> Unique Worker ID <span class="required">*</span>
                        </label>
                        <button type="button" id="generateIdBtn" class="btn btn-outline btn-xs" title="Auto Generate Next ID" style="font-size: 0.72rem; padding: 0.15rem 0.55rem; height: 26px; border-radius: 4px; display: inline-flex; align-items: center; gap: 0.35rem; color: var(--accent-cyan); border-color: rgba(6,182,212,0.35);">
                            <i class="fa-solid fa-dice"></i> Auto-Generate
                        </button>
                    </div>
                    <input type="text" id="uniqueIdInput" name="unique_id" class="form-control" placeholder="e.g. PU001-1" value="<?= htmlspecialchars($initialUniqueId) ?>" required style="height: 42px;">
                    <span class="text-muted" style="font-size: 0.75rem; margin-top: 0.35rem; display: block;">Unique barcode / employee registration ID</span>
                </div>
            </div>

            <div class="col-6">
                <div class="form-group">
                    <div style="display: flex; align-items: center; min-height: 28px; margin-bottom: 0.45rem;">
                        <label class="form-label" for="nameInput" style="margin-bottom: 0;">
                            <i class="fa-solid fa-user text-cyan"></i> Full Worker Name <span class="required">*</span>
                        </label>
                    </div>
                    <input type="text" id="nameInput" name="name" class="form-control" autocomplete="name" placeholder="e.g. Rajesh Kumar" required style="height: 42px;">
                    <span class="text-muted" style="font-size: 0.75rem; margin-top: 0.35rem; display: block;">Full legal name as per factory records</span>
                </div>
            </div>

            <!-- 3. Age & Experience -->
            <div class="col-6">
                <div class="form-group">
                    <div style="display: flex; align-items: center; min-height: 28px; margin-bottom: 0.45rem;">
                        <label class="form-label" for="ageInput" style="margin-bottom: 0;">
                            <i class="fa-solid fa-calendar-days text-cyan"></i> Age (Years) <span class="required">*</span>
                        </label>
                    </div>
                    <input type="number" id="ageInput" name="age" class="form-control" min="18" max="80" placeholder="e.g. 28" required style="height: 42px;">
                    <span class="text-muted" style="font-size: 0.75rem; margin-top: 0.35rem; display: block;">Minimum age limit: 18 years</span>
                </div>
            </div>

            <div class="col-6">
                <div class="form-group">
                    <div style="display: flex; align-items: center; min-height: 28px; margin-bottom: 0.45rem;">
                        <label class="form-label" for="experienceInput" style="margin-bottom: 0;">
                            <i class="fa-solid fa-briefcase text-cyan"></i> Experience (Years) <span class="required">*</span>
                        </label>
                    </div>
                    <input type="number" step="0.5" id="experienceInput" name="experience_years" class="form-control" min="0" max="50" placeholder="e.g. 4.5" required style="height: 42px;">
                    <span class="text-muted" style="font-size: 0.75rem; margin-top: 0.35rem; display: block;">Total textile / manufacturing experience</span>
                </div>
            </div>

            <!-- 4. Mobile & Alternative Mobile -->
            <div class="col-6">
                <div class="form-group">
                    <div style="display: flex; align-items: center; min-height: 28px; margin-bottom: 0.45rem;">
                        <label class="form-label" for="mobileInput" style="margin-bottom: 0;">
                            <i class="fa-solid fa-phone text-cyan"></i> Primary Mobile Number <span class="required">*</span>
                        </label>
                    </div>
                    <input type="tel" id="mobileInput" name="mobile_num" class="form-control" autocomplete="tel" placeholder="e.g. 9876543210" required maxlength="10" inputmode="numeric" pattern="[6-9][0-9]{9}" style="height: 42px;">
                    <div id="mobileFeedback" style="display: none; margin-top: 0.35rem; font-size: 0.8rem; font-weight: 600; align-items: center; gap: 0.35rem;"></div>
                    <span id="mobileDefaultHint" class="text-muted" style="font-size: 0.75rem; margin-top: 0.35rem; display: block;">10-digit mobile number starting with 6, 7, 8, or 9</span>
                </div>
            </div>

            <div class="col-6">
                <div class="form-group">
                    <div style="display: flex; align-items: center; min-height: 28px; margin-bottom: 0.45rem;">
                        <label class="form-label" for="altMobileInput" style="margin-bottom: 0;">
                            <i class="fa-solid fa-phone-volume text-cyan"></i> Alternative Mobile Number
                        </label>
                    </div>
                    <input type="tel" id="altMobileInput" name="alt_mobile_num" class="form-control" autocomplete="tel" placeholder="e.g. 9876543211 (Optional)" maxlength="10" inputmode="numeric" pattern="[6-9][0-9]{9}" style="height: 42px;">
                    <div id="altMobileFeedback" style="display: none; margin-top: 0.35rem; font-size: 0.8rem; font-weight: 600; align-items: center; gap: 0.35rem;"></div>
                    <span id="altMobileDefaultHint" class="text-muted" style="font-size: 0.75rem; margin-top: 0.35rem; display: block;">Emergency contact or secondary phone (10 digits)</span>
                </div>
            </div>

            <!-- 5. Address -->
            <div class="col-12">
                <div class="form-group">
                    <div style="display: flex; align-items: center; min-height: 28px; margin-bottom: 0.45rem;">
                        <label class="form-label" for="addressInput" style="margin-bottom: 0;">
                            <i class="fa-solid fa-location-dot text-cyan"></i> Residential / Factory Address <span class="required">*</span>
                        </label>
                    </div>
                    <textarea id="addressInput" name="address" class="form-control" rows="2" autocomplete="street-address" placeholder="e.g. Plot No. 42, Weavers Colony, Sector 4 Industrial Area" required></textarea>
                </div>
            </div>

            <!-- 6. Designation / Factory Role (Col-12 Full Width) -->
            <div class="col-12">
                <div class="form-group">
                    <div style="display: flex; justify-content: space-between; align-items: center; min-height: 28px; margin-bottom: 0.45rem;">
                        <label class="form-label" for="roleSelect" style="margin-bottom: 0;">
                            <i class="fa-solid fa-user-tag text-cyan"></i> Designation / Factory Role <span class="required">*</span>
                        </label>
                        <?php if (isset($currentUser) && ($currentUser['role'] === 'admin' || $currentUser['role'] === 'supervisor')): ?>
                            <button type="button" class="btn btn-outline btn-xs" id="openManageRolesBtn" style="font-size: 0.72rem; padding: 0.15rem 0.55rem; height: 26px; border-radius: 4px; display: inline-flex; align-items: center; gap: 0.35rem; color: var(--accent-cyan); border-color: rgba(6,182,212,0.35);">
                                <i class="fa-solid fa-plus-circle text-cyan"></i> Manage Roles
                            </button>
                        <?php endif; ?>
                    </div>
                    <select id="roleSelect" name="role" class="form-control" required style="font-weight: 600; font-size: 0.9rem; height: 42px;">
                        <?php if (empty($adminRoles)): ?>
                            <option value="">-- No Roles Added Yet (Only Admin Added Roles Will Appear) --</option>
                        <?php else: ?>
                            <option value="">-- Select Designation / Role from Dropdown --</option>
                            <?php foreach ($adminRoles as $ar): ?>
                                <option value="<?= htmlspecialchars($ar['role_name']) ?>">
                                    <?= htmlspecialchars($ar['role_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <span class="text-muted" style="font-size: 0.75rem; margin-top: 0.35rem; display: block;">Worker's primary factory job title</span>
                    <input type="hidden" id="extraSkillsInput" name="extra_skills" value="">
                </div>
            </div>

            <!-- 7. Section Divider: User Login Credentials & Access Role -->
            <div class="col-12" style="margin-top: 0.4rem; margin-bottom: 0.1rem;">
                <div style="display: flex; align-items: center; justify-content: space-between; padding-bottom: 0.45rem; border-bottom: 1px solid rgba(255, 255, 255, 0.08);">
                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fa-solid fa-key text-cyan" style="font-size: 0.92rem;"></i>
                        <span style="font-weight: 700; font-size: 0.92rem; color: var(--text-primary);">
                            User Login Credentials &amp; Access Role
                        </span>
                    </div>
                    <span class="badge-pill" style="font-size: 0.7rem; padding: 0.15rem 0.55rem; background: rgba(6,182,212,0.1); color: var(--accent-cyan); border: 1px solid rgba(6,182,212,0.25); display: inline-flex; align-items: center; gap: 0.35rem; text-transform: uppercase; font-weight: 600; letter-spacing: 0.02em;">
                        <i class="fa-solid fa-shield-halved"></i> Access Security
                    </span>
                </div>
            </div>

            <!-- 8. Login Password (Col-6) & Assign System Access Role (Col-6) Side-by-Side -->
            <div class="col-6">
                <div class="form-group">
                    <div style="display: flex; justify-content: space-between; align-items: center; min-height: 28px; margin-bottom: 0.45rem;">
                        <label class="form-label" for="workerPasswordInput" style="margin-bottom: 0;">
                            <i class="fa-solid fa-lock text-cyan"></i> Login Password <span class="required">*</span>
                        </label>
                        <button type="button" class="btn btn-outline btn-xs" onclick="generateRandomPassword()" style="font-size: 0.72rem; padding: 0.15rem 0.55rem; height: 26px; border-radius: 4px; display: inline-flex; align-items: center; gap: 0.35rem; color: var(--accent-cyan); border-color: rgba(6,182,212,0.35);" title="Auto-generate a secure valid password">
                            <i class="fa-solid fa-wand-magic-sparkles text-cyan"></i> Auto-Generate
                        </button>
                    </div>
                    <div class="password-field-wrapper" style="position: relative;">
                        <input type="password" id="workerPasswordInput" name="password" class="form-control" placeholder="4 to 6 characters (e.g. 1234, pass5)" minlength="4" maxlength="6" autocomplete="new-password" required style="font-weight: 600; font-size: 0.88rem; height: 42px; padding-right: 2.75rem;" oninput="validateWorkerPassword(this.value)">
                        <button type="button" class="password-toggle-btn" onclick="togglePassVisibility('workerPasswordInput', 'regWorkerEyeIcon')" title="Show / Hide Password" style="position: absolute; right: 0.85rem; top: 50%; transform: translateY(-50%); background: none; border: none; color: var(--text-muted); cursor: pointer; z-index: 2; font-size: 0.9rem;">
                            <i class="fa-solid fa-eye" id="regWorkerEyeIcon"></i>
                        </button>
                    </div>
                    <div id="workerPassFeedback" style="display: flex; justify-content: space-between; align-items: center; margin-top: 0.35rem; font-size: 0.75rem;">
                        <span id="ruleWorkerLen" class="badge-pill" style="padding: 0.12rem 0.45rem; font-size: 0.72rem; background: rgba(255,255,255,0.05); color: var(--text-muted); border: 1px solid rgba(255,255,255,0.12); display: inline-flex; align-items: center; gap: 0.3rem;">
                            <i id="ruleWorkerLenIcon" class="fa-regular fa-circle" style="font-size: 0.62rem;"></i> <span id="ruleWorkerLenText">Must be 4 to 6 characters</span>
                        </span>
                        <span id="workerPassCounter" style="font-weight: 600; color: var(--text-muted); font-variant-numeric: tabular-nums;">0 / 4-6 chars</span>
                    </div>
                </div>
            </div>

            <div class="col-6">
                <div class="form-group">
                    <div style="display: flex; justify-content: space-between; align-items: center; min-height: 28px; margin-bottom: 0.45rem;">
                        <label class="form-label" for="systemRoleSelect" style="margin-bottom: 0;">
                            <i class="fa-solid fa-user-shield text-cyan"></i> Assign System Access Role <span class="required">*</span>
                        </label>
                        <div style="display: inline-flex; align-items: center; gap: 0.45rem;">
                            <span id="roleAccessTier" class="badge-pill" style="font-size: 0.72rem; padding: 0.15rem 0.55rem; height: 26px; border-radius: 4px; background: rgba(6,182,212,0.1); color: var(--accent-cyan); border: 1px solid rgba(6,182,212,0.25); display: inline-flex; align-items: center; text-transform: uppercase; font-weight: 600;">
                                Operator Scope
                            </span>
                            <?php if (isset($currentUser) && ($currentUser['role'] === 'admin' || $currentUser['role'] === 'supervisor')): ?>
                                <button type="button" class="btn btn-outline btn-xs" id="openAddSystemRoleBtn" style="font-size: 0.72rem; padding: 0.15rem 0.55rem; height: 26px; border-radius: 4px; display: inline-flex; align-items: center; gap: 0.35rem; color: var(--accent-cyan); border-color: rgba(6,182,212,0.35);" title="Add New System Role">
                                    <i class="fa-solid fa-plus-circle text-cyan"></i> Add New
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <select id="systemRoleSelect" name="system_role" class="form-control" required style="font-weight: 600; font-size: 0.9rem; height: 42px;" onchange="updateRoleBadge(this.value)">
                        <?php foreach ($allSystemRoles as $sr): ?>
                            <option value="<?= htmlspecialchars($sr['role_key']) ?>" data-tier="<?= htmlspecialchars($sr['access_tier']) ?>" data-desc="<?= htmlspecialchars($sr['description'] ?? '') ?>" <?= $sr['role_key'] === 'operator' ? 'selected' : '' ?>>
                                <?= htmlspecialchars($sr['role_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 0.35rem; font-size: 0.75rem;">
                        <span id="roleAccessBadge" style="display: inline-flex; align-items: center; gap: 0.3rem; color: var(--accent-cyan); font-weight: 500;">
                            <i id="roleAccessIcon" class="fa-solid fa-laptop-code" style="font-size: 0.65rem;"></i> <span id="roleAccessText">Workstation &amp; IoT Only</span>
                        </span>
                        <span id="roleDescText" class="text-muted">Default Privileges</span>
                    </div>
                </div>
            </div>

            <!-- Sign-in Identity Guidelines Bar (Col-12 full width, slim, matching user UI design) -->
            <div class="col-12" style="margin-top: -0.25rem; margin-bottom: 0.25rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.45rem 0.85rem; background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.07); border-radius: var(--radius-sm); font-size: 0.78rem; color: var(--text-secondary);">
                    <span style="display: flex; align-items: center; gap: 0.45rem;">
                        <i class="fa-solid fa-circle-info text-cyan" style="font-size: 0.75rem;"></i>
                        Sign in using <strong style="color: var(--text-primary); margin: 0 0.15rem;">Worker ID</strong> (e.g. PU001-1) or <strong style="color: var(--text-primary); margin: 0 0.15rem;">Name</strong> + this password
                    </span>
                    <span style="display: inline-flex; align-items: center; gap: 0.35rem; color: var(--accent-emerald); font-size: 0.74rem; font-weight: 600;">
                        <i class="fa-solid fa-lock" style="font-size: 0.7rem;"></i> Secure Bcrypt Hash
                    </span>
                </div>
            </div>

            <!-- 8. Submit Action Button -->
            <div class="col-12" style="margin-top: 1.25rem; display: flex; justify-content: flex-end; align-items: center; gap: 0.85rem; flex-wrap: wrap;">
                <button type="reset" class="btn btn-outline" style="height: 44px; padding: 0 1.4rem; font-size: 0.92rem; font-weight: 600; display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; border-radius: var(--radius-sm);" onclick="handleReset()">
                    <i class="fa-solid fa-rotate-left"></i> Reset Form
                </button>
                <button type="submit" class="btn btn-primary" id="saveWorkerBtn" style="height: 44px; padding: 0 1.75rem; font-size: 0.92rem; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; gap: 0.55rem; border-radius: var(--radius-sm);">
                    <i class="fa-solid fa-user-check"></i> Register Worker Profile
                </button>
            </div>

        </div>
    </form>
</div>

<!-- Admin Worker Roles Management Modal -->
<?php if (isset($currentUser) && ($currentUser['role'] === 'admin' || $currentUser['role'] === 'supervisor')): ?>
<div id="rolesModal" class="modal-overlay" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.78); z-index: 9999; align-items: center; justify-content: center; backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); opacity: 1;">
    <div class="modal-container" style="background: var(--card-bg, #1e293b); border: 1px solid var(--border-color, #334155); border-radius: var(--radius-lg, 12px); width: 92%; max-width: 520px; max-height: 90vh; overflow-y: auto; padding: 1.5rem; box-shadow: 0 25px 60px -15px rgba(0,0,0,0.85); animation: modalPop 0.22s cubic-bezier(0.16, 1, 0.3, 1);">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.75rem;">
            <div>
                <h3 style="margin: 0; font-size: 1.2rem; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 0.5rem;">
                    <i class="fa-solid fa-user-tag text-cyan"></i> Manage Worker Designations / Roles
                </h3>
                <p class="text-muted" style="margin: 0.35rem 0 0 0; font-size: 0.82rem;">
                    Only roles added here will appear in the Designation / Role selection dropdown.
                </p>
            </div>
            <button type="button" id="closeRolesModalBtn" style="background: none; border: none; font-size: 1.25rem; color: var(--text-muted); cursor: pointer; padding: 0.25rem 0.5rem;" aria-label="Close modal">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <!-- Add Role Form -->
        <form id="addRoleForm" style="margin-bottom: 1.25rem; background: rgba(255,255,255,0.03); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 1rem;">
            <div style="font-weight: 700; font-size: 0.88rem; margin-bottom: 0.75rem; color: var(--accent-cyan); display: flex; align-items: center; gap: 0.4rem;">
                <i class="fa-solid fa-plus-circle"></i> Add New Designation / Role
            </div>

            <div style="margin-bottom: 0.75rem;">
                <label style="display: block; font-size: 0.78rem; font-weight: 600; margin-bottom: 0.25rem; color: var(--text-secondary);">
                    Role Name <span class="required">*</span>
                </label>
                <input type="text" id="newRoleInput" class="form-control" placeholder="e.g. Master Tailor, Pattern Cutter, Quality Checker" required style="font-size: 0.88rem; padding: 0.55rem 0.75rem; height: 40px;">
            </div>

            <!-- Quick Suggestions -->
            <div style="margin-bottom: 0.85rem;">
                <span class="text-muted" style="font-size: 0.72rem; display: block; margin-bottom: 0.35rem;">Quick suggestions (click to fill):</span>
                <div style="display: flex; flex-wrap: wrap; gap: 0.35rem;" id="quickRoleSuggestions">
                    <button type="button" class="btn btn-outline btn-xs role-chip-btn" data-role="Master Tailor" style="font-size: 0.72rem; padding: 0.15rem 0.45rem;">+ Master Tailor</button>
                    <button type="button" class="btn btn-outline btn-xs role-chip-btn" data-role="Pattern Cutter" style="font-size: 0.72rem; padding: 0.15rem 0.45rem;">+ Pattern Cutter</button>
                    <button type="button" class="btn btn-outline btn-xs role-chip-btn" data-role="Quality Checker" style="font-size: 0.72rem; padding: 0.15rem 0.45rem;">+ Quality Checker</button>
                    <button type="button" class="btn btn-outline btn-xs role-chip-btn" data-role="Sewing Operator" style="font-size: 0.72rem; padding: 0.15rem 0.45rem;">+ Sewing Operator</button>
                    <button type="button" class="btn btn-outline btn-xs role-chip-btn" data-role="Ironing &amp; Packing" style="font-size: 0.72rem; padding: 0.15rem 0.45rem;">+ Ironing &amp; Packing</button>
                    <button type="button" class="btn btn-outline btn-xs role-chip-btn" data-role="Helper / Trainee" style="font-size: 0.72rem; padding: 0.15rem 0.45rem;">+ Helper / Trainee</button>
                </div>
            </div>

            <div id="roleFormAlert" style="display: none; padding: 0.5rem 0.75rem; border-radius: var(--radius-sm); font-size: 0.8rem; margin-bottom: 0.75rem;"></div>

            <button type="submit" id="saveRoleBtn" class="btn btn-primary btn-sm" style="width: 100%; display: flex; justify-content: center; align-items: center; gap: 0.45rem; height: 38px;">
                <i class="fa-solid fa-plus"></i> Save Role to System
            </button>
        </form>

        <!-- Existing Roles List -->
        <div>
            <div style="font-weight: 700; font-size: 0.88rem; margin-bottom: 0.5rem; color: var(--text-primary); display: flex; justify-content: space-between; align-items: center;">
                <span>Active Roles (<span id="rolesCountLabel"><?= count($adminRoles) ?></span>)</span>
            </div>
            <div id="rolesListContainer" style="display: flex; flex-direction: column; gap: 0.45rem; max-height: 240px; overflow-y: auto; padding-right: 0.25rem;">
                <!-- Dynamically rendered -->
            </div>
        </div>
    </div>
</div>

<!-- Admin System Access Roles Management Modal -->
<div id="systemRolesModal" class="modal-overlay" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.78); z-index: 9999; align-items: center; justify-content: center; backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); opacity: 1;">
    <div class="modal-container" style="background: var(--card-bg, #1e293b); border: 1px solid var(--border-color, #334155); border-radius: var(--radius-lg, 12px); width: 92%; max-width: 540px; max-height: 90vh; overflow-y: auto; padding: 1.5rem; box-shadow: 0 25px 60px -15px rgba(0,0,0,0.85); animation: modalPop 0.22s cubic-bezier(0.16, 1, 0.3, 1);">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.75rem;">
            <div>
                <h3 style="margin: 0; font-size: 1.2rem; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 0.5rem;">
                    <i class="fa-solid fa-user-shield text-cyan"></i> Add New System Access Role
                </h3>
                <p class="text-muted" style="margin: 0.35rem 0 0 0; font-size: 0.82rem;">
                    Create custom system roles and assign their base factory permission scope.
                </p>
            </div>
            <button type="button" id="closeSystemRolesModalBtn" style="background: none; border: none; font-size: 1.25rem; color: var(--text-muted); cursor: pointer; padding: 0.25rem 0.5rem;" aria-label="Close modal">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <!-- Add System Role Form -->
        <form id="addSystemRoleForm" style="margin-bottom: 1.25rem; background: rgba(255,255,255,0.03); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 1rem;">
            <div style="font-weight: 700; font-size: 0.88rem; margin-bottom: 0.75rem; color: var(--accent-cyan); display: flex; align-items: center; gap: 0.4rem;">
                <i class="fa-solid fa-plus-circle"></i> New Role Configuration
            </div>

            <div style="margin-bottom: 0.75rem;">
                <label style="display: block; font-size: 0.78rem; font-weight: 600; margin-bottom: 0.25rem; color: var(--text-secondary);">
                    Role Name / Title <span class="required">*</span>
                </label>
                <input type="text" id="newSystemRoleNameInput" class="form-control" placeholder="e.g. Quality Controller, Maintenance Tech, Floor Lead" required style="font-size: 0.88rem; padding: 0.55rem 0.75rem; height: 40px;">
            </div>

            <!-- Quick Suggestions -->
            <div style="margin-bottom: 0.85rem;">
                <span class="text-muted" style="font-size: 0.72rem; display: block; margin-bottom: 0.35rem;">Quick suggestions (click to fill):</span>
                <div style="display: flex; flex-wrap: wrap; gap: 0.35rem;" id="quickSystemRoleSuggestions">
                    <button type="button" class="btn btn-outline btn-xs sysrole-chip-btn" data-role="Quality Controller" data-tier="supervisor" data-desc="Quality inspection and defect logging" style="font-size: 0.72rem; padding: 0.15rem 0.45rem;">+ Quality Controller</button>
                    <button type="button" class="btn btn-outline btn-xs sysrole-chip-btn" data-role="Floor Supervisor" data-tier="supervisor" data-desc="Floor management and shift approvals" style="font-size: 0.72rem; padding: 0.15rem 0.45rem;">+ Floor Supervisor</button>
                    <button type="button" class="btn btn-outline btn-xs sysrole-chip-btn" data-role="Maintenance Tech" data-tier="operator" data-desc="Machine maintenance and IoT monitoring" style="font-size: 0.72rem; padding: 0.15rem 0.45rem;">+ Maintenance Tech</button>
                    <button type="button" class="btn btn-outline btn-xs sysrole-chip-btn" data-role="Line Incharge" data-tier="supervisor" data-desc="Assembly line oversight" style="font-size: 0.72rem; padding: 0.15rem 0.45rem;">+ Line Incharge</button>
                    <button type="button" class="btn btn-outline btn-xs sysrole-chip-btn" data-role="Factory Auditor" data-tier="admin" data-desc="Comprehensive audit and compliance oversight" style="font-size: 0.72rem; padding: 0.15rem 0.45rem;">+ Factory Auditor</button>
                </div>
            </div>

            <!-- Access Tier Scope -->
            <div style="margin-bottom: 0.75rem;">
                <label style="display: block; font-size: 0.78rem; font-weight: 600; margin-bottom: 0.25rem; color: var(--text-secondary);">
                    Permission Scope Tier <span class="required">*</span>
                </label>
                <select id="newSystemRoleTierSelect" class="form-control" style="font-size: 0.88rem; height: 40px; font-weight: 600;">
                    <option value="operator">Operator Scope (Workstation Output &amp; IoT Only)</option>
                    <option value="supervisor" selected>Supervisor Scope (Unit Management &amp; Approvals)</option>
                    <option value="admin">Administrator Scope (Full Factory Access &amp; Configuration)</option>
                </select>
            </div>

            <!-- Description -->
            <div style="margin-bottom: 0.85rem;">
                <label style="display: block; font-size: 0.78rem; font-weight: 600; margin-bottom: 0.25rem; color: var(--text-secondary);">
                    Privilege Summary / Description (Optional)
                </label>
                <input type="text" id="newSystemRoleDescInput" class="form-control" placeholder="e.g. Authorized for shift approvals & line quality checks" style="font-size: 0.85rem; height: 38px;">
            </div>

            <div id="systemRoleFormAlert" style="display: none; padding: 0.5rem 0.75rem; border-radius: var(--radius-sm); font-size: 0.8rem; margin-bottom: 0.75rem;"></div>

            <button type="submit" id="saveSystemRoleBtn" class="btn btn-primary btn-sm" style="width: 100%; display: flex; justify-content: center; align-items: center; gap: 0.45rem; height: 38px;">
                <i class="fa-solid fa-plus"></i> Save System Role
            </button>
        </form>

        <!-- Existing System Roles List -->
        <div>
            <div style="font-weight: 700; font-size: 0.88rem; margin-bottom: 0.5rem; color: var(--text-primary); display: flex; justify-content: space-between; align-items: center;">
                <span>Active System Roles (<span id="systemRolesCountLabel"><?= count($allSystemRoles) ?></span>)</span>
            </div>
            <div id="systemRolesListContainer" style="display: flex; flex-direction: column; gap: 0.45rem; max-height: 220px; overflow-y: auto; padding-right: 0.25rem;">
                <!-- Dynamically rendered -->
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
    function safeEscape(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    let rolesData = <?= json_encode($adminRoles) ?>;
    const roleSelect = document.getElementById('roleSelect');
    const openRolesModalBtn = document.getElementById('openManageRolesBtn');
    const closeRolesModalBtn = document.getElementById('closeRolesModalBtn');
    const rolesModal = document.getElementById('rolesModal');
    const addRoleForm = document.getElementById('addRoleForm');
    const newRoleInput = document.getElementById('newRoleInput');
    const saveRoleBtn = document.getElementById('saveRoleBtn');
    const roleFormAlert = document.getElementById('roleFormAlert');
    const rolesListContainer = document.getElementById('rolesListContainer');
    const rolesCountLabel = document.getElementById('rolesCountLabel');

    function renderRolesModalList(roles) {
        rolesData = roles;
        if (rolesCountLabel) rolesCountLabel.innerText = roles.length;
        if (!rolesListContainer) return;

        if (roles.length === 0) {
            rolesListContainer.innerHTML = '<div style="padding: 1.25rem; text-align: center; color: var(--text-muted); font-size: 0.84rem; border: 1px dashed var(--border-color); border-radius: var(--radius-sm);"><i class="fa-solid fa-circle-info" style="margin-right: 0.35rem;"></i> No roles added yet. Click a quick suggestion above or type a name to add your first role.</div>';
            return;
        }

        let html = '';
        roles.forEach(r => {
            html += `
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0.85rem; background: rgba(255,255,255,0.03); border: 1px solid var(--border-color); border-radius: var(--radius-sm); font-size: 0.86rem;">
                    <span style="font-weight: 600; color: var(--text-primary); display: flex; align-items: center; gap: 0.45rem;">
                        <i class="fa-solid fa-user-tag text-cyan" style="font-size: 0.78rem;"></i>
                        ${safeEscape(r.role_name)}
                    </span>
                    <button type="button" class="btn-delete-role text-rose" data-id="${r.id}" data-name="${safeEscape(r.role_name)}" title="Delete Role" style="background: none; border: none; cursor: pointer; padding: 0.25rem 0.45rem; font-size: 0.84rem; transition: transform 0.15s ease;">
                        <i class="fa-solid fa-trash-can"></i>
                    </button>
                </div>
            `;
        });
        rolesListContainer.innerHTML = html;

        // Delete click handlers
        rolesListContainer.querySelectorAll('.btn-delete-role').forEach(btn => {
            btn.addEventListener('click', async () => {
                const id = btn.dataset.id;
                const name = btn.dataset.name;
                if (!confirm(`Are you sure you want to delete role "${name}"?`)) return;

                btn.disabled = true;
                try {
                    const res = await fetch('api.php?action=delete_role', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ id: id, role_name: name })
                    });
                    const data = await res.json();
                    if (data.success) {
                        updateRoleSelect(data.roles);
                        renderRolesModalList(data.roles);
                        if (typeof showToast === 'function') showToast(data.message, 'success');
                    } else {
                        alert(data.message || 'Error deleting role.');
                    }
                } catch (e) {
                    console.error(e);
                }
            });
        });
    }

    function updateRoleSelect(roles, selectedRole = null) {
        if (!roleSelect) return;
        const currentVal = selectedRole || roleSelect.value;
        if (!roles || roles.length === 0) {
            roleSelect.innerHTML = '<option value="">-- No Roles Added Yet (Only Admin Added Roles Will Appear) --</option>';
            return;
        }

        let optHtml = '<option value="">-- Select Designation / Role from Dropdown --</option>';
        roles.forEach(r => {
            const isSel = (r.role_name === currentVal) ? 'selected' : '';
            optHtml += `<option value="${safeEscape(r.role_name)}" ${isSel}>${safeEscape(r.role_name)}</option>`;
        });
        roleSelect.innerHTML = optHtml;
    }

    // Quick suggestion chip buttons
    document.querySelectorAll('.role-chip-btn').forEach(chip => {
        chip.addEventListener('click', () => {
            const role = chip.dataset.role;
            if (newRoleInput) {
                newRoleInput.value = role;
                newRoleInput.focus();
            }
        });
    });

    function openRolesModal() {
        if (!rolesModal) return;
        rolesModal.classList.add('active');
        rolesModal.classList.add('open');
        rolesModal.style.display = 'flex';
        rolesModal.style.opacity = '1';
        renderRolesModalList(rolesData);

        // Fetch fresh roles from server
        fetch('api.php?action=get_roles')
            .then(res => res.json())
            .then(data => {
                if (data.success && Array.isArray(data.roles)) {
                    rolesData = data.roles;
                    renderRolesModalList(rolesData);
                    updateRoleSelect(rolesData);
                }
            })
            .catch(err => console.error('Error fetching roles:', err));

        if (newRoleInput) {
            setTimeout(() => newRoleInput.focus(), 60);
        }
    }

    function closeRolesModal() {
        if (!rolesModal) return;
        rolesModal.classList.remove('active');
        rolesModal.classList.remove('open');
        rolesModal.style.display = 'none';
        rolesModal.style.opacity = '0';
        if (roleFormAlert) roleFormAlert.style.display = 'none';
    }

    if (openRolesModalBtn && rolesModal) {
        openRolesModalBtn.addEventListener('click', (e) => {
            e.preventDefault();
            openRolesModal();
        });
    }

    if (closeRolesModalBtn && rolesModal) {
        closeRolesModalBtn.addEventListener('click', (e) => {
            e.preventDefault();
            closeRolesModal();
        });
    }

    if (rolesModal) {
        rolesModal.addEventListener('click', (e) => {
            if (e.target === rolesModal) closeRolesModal();
        });
    }

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && rolesModal && (rolesModal.classList.contains('active') || rolesModal.style.display === 'flex')) {
            closeRolesModal();
        }
    });

    if (addRoleForm) {
        addRoleForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const roleName = newRoleInput ? newRoleInput.value.trim() : '';
            if (!roleName) return;

            if (saveRoleBtn) saveRoleBtn.disabled = true;
            try {
                const res = await fetch('api.php?action=add_role', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ role_name: roleName })
                });
                const data = await res.json();
                if (data.success) {
                    if (roleFormAlert) {
                        roleFormAlert.className = 'alert-box alert-success';
                        roleFormAlert.style.display = 'flex';
                        roleFormAlert.innerHTML = '<i class="fa-solid fa-circle-check"></i> ' + safeEscape(data.message);
                        setTimeout(() => { if (roleFormAlert) roleFormAlert.style.display = 'none'; }, 2500);
                    }
                    if (newRoleInput) {
                        newRoleInput.value = '';
                        newRoleInput.focus();
                    }
                    updateRoleSelect(data.roles, roleName);
                    renderRolesModalList(data.roles);
                    if (typeof showToast === 'function') showToast(`Added role: ${roleName}`, 'success');
                } else {
                    if (roleFormAlert) {
                        roleFormAlert.className = 'alert-box alert-danger';
                        roleFormAlert.style.display = 'flex';
                        roleFormAlert.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> ' + safeEscape(data.message);
                    }
                }
            } catch (err) {
                console.error(err);
                if (roleFormAlert) {
                    roleFormAlert.className = 'alert-box alert-danger';
                    roleFormAlert.style.display = 'flex';
                    roleFormAlert.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> Network error adding role.';
                }
            } finally {
                if (saveRoleBtn) saveRoleBtn.disabled = false;
            }
        });
    }

    // ==========================================
    // System Access Roles Modal & Management
    // ==========================================
    let systemRolesData = <?= json_encode($allSystemRoles) ?>;
    const systemRoleSelect = document.getElementById('systemRoleSelect');
    const openAddSystemRoleBtn = document.getElementById('openAddSystemRoleBtn');
    const closeSystemRolesModalBtn = document.getElementById('closeSystemRolesModalBtn');
    const systemRolesModal = document.getElementById('systemRolesModal');
    const addSystemRoleForm = document.getElementById('addSystemRoleForm');
    const newSystemRoleNameInput = document.getElementById('newSystemRoleNameInput');
    const newSystemRoleTierSelect = document.getElementById('newSystemRoleTierSelect');
    const newSystemRoleDescInput = document.getElementById('newSystemRoleDescInput');
    const saveSystemRoleBtn = document.getElementById('saveSystemRoleBtn');
    const systemRoleFormAlert = document.getElementById('systemRoleFormAlert');
    const systemRolesListContainer = document.getElementById('systemRolesListContainer');
    const systemRolesCountLabel = document.getElementById('systemRolesCountLabel');

    function openSystemModal() {
        if (!systemRolesModal) return;
        systemRolesModal.classList.add('active', 'open');
        systemRolesModal.style.display = 'flex';
        systemRolesModal.style.opacity = '1';
        renderSystemRolesList(systemRolesData);

        // Fetch latest system roles from API
        fetch('api.php?action=get_system_roles')
            .then(res => res.json())
            .then(data => {
                if (data.success && Array.isArray(data.roles)) {
                    systemRolesData = data.roles;
                    renderSystemRolesList(systemRolesData);
                    updateSystemRoleSelect(systemRolesData);
                }
            })
            .catch(err => console.error('Error fetching system roles:', err));

        if (newSystemRoleNameInput) {
            setTimeout(() => newSystemRoleNameInput.focus(), 60);
        }
    }

    function closeSystemModal() {
        if (!systemRolesModal) return;
        systemRolesModal.classList.remove('active', 'open');
        systemRolesModal.style.display = 'none';
        systemRolesModal.style.opacity = '0';
        if (systemRoleFormAlert) systemRoleFormAlert.style.display = 'none';
        if (addSystemRoleForm) addSystemRoleForm.reset();
    }

    if (openAddSystemRoleBtn && systemRolesModal) {
        openAddSystemRoleBtn.addEventListener('click', (e) => {
            e.preventDefault();
            openSystemModal();
        });
    }

    if (closeSystemRolesModalBtn && systemRolesModal) {
        closeSystemRolesModalBtn.addEventListener('click', (e) => {
            e.preventDefault();
            closeSystemModal();
        });
    }

    if (systemRolesModal) {
        systemRolesModal.addEventListener('click', (e) => {
            if (e.target === systemRolesModal) closeSystemModal();
        });
    }

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && systemRolesModal && (systemRolesModal.classList.contains('active') || systemRolesModal.style.display === 'flex')) {
            closeSystemModal();
        }
    });

    // Quick suggestion pills
    document.querySelectorAll('.sysrole-chip-btn').forEach(chip => {
        chip.addEventListener('click', () => {
            if (newSystemRoleNameInput) {
                newSystemRoleNameInput.value = chip.dataset.role || '';
                newSystemRoleNameInput.focus();
            }
            if (newSystemRoleTierSelect && chip.dataset.tier) {
                newSystemRoleTierSelect.value = chip.dataset.tier;
            }
            if (newSystemRoleDescInput && chip.dataset.desc) {
                newSystemRoleDescInput.value = chip.dataset.desc;
            }
        });
    });

    function renderSystemRolesList(roles) {
        systemRolesData = roles;
        if (systemRolesCountLabel) systemRolesCountLabel.innerText = roles.length;
        if (!systemRolesListContainer) return;

        if (!roles || roles.length === 0) {
            systemRolesListContainer.innerHTML = '<div style="padding: 1rem; text-align: center; color: var(--text-muted); font-size: 0.82rem;">No system roles configured.</div>';
            return;
        }

        let html = '';
        roles.forEach(r => {
            const isDef = (r.is_system_default == 1 || ['operator', 'supervisor', 'admin'].includes(r.role_key));
            let tierBadge = '';
            if (r.access_tier === 'admin') {
                tierBadge = '<span style="font-size: 0.68rem; padding: 0.12rem 0.45rem; border-radius: 3px; background: rgba(239, 68, 68, 0.15); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.3); font-weight: 600;">Admin Scope</span>';
            } else if (r.access_tier === 'supervisor') {
                tierBadge = '<span style="font-size: 0.68rem; padding: 0.12rem 0.45rem; border-radius: 3px; background: rgba(245, 158, 11, 0.15); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.3); font-weight: 600;">Supervisor Scope</span>';
            } else {
                tierBadge = '<span style="font-size: 0.68rem; padding: 0.12rem 0.45rem; border-radius: 3px; background: rgba(6, 182, 212, 0.12); color: var(--accent-cyan); border: 1px solid rgba(6, 182, 212, 0.25); font-weight: 600;">Operator Scope</span>';
            }

            html += `
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.55rem 0.85rem; background: rgba(255,255,255,0.03); border: 1px solid var(--border-color); border-radius: var(--radius-sm); font-size: 0.86rem;">
                    <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                        <i class="fa-solid fa-user-shield text-cyan" style="font-size: 0.78rem;"></i>
                        <span style="font-weight: 600; color: var(--text-primary);">${safeEscape(r.role_name)}</span>
                        ${tierBadge}
                    </div>
                    <div>
                        ${isDef ? '<span class="text-muted" style="font-size: 0.72rem; font-style: italic; opacity: 0.75;">Default</span>' : `
                        <button type="button" class="btn-delete-sysrole text-rose" data-id="${r.id}" data-key="${safeEscape(r.role_key)}" data-name="${safeEscape(r.role_name)}" title="Delete Role" style="background: none; border: none; cursor: pointer; padding: 0.25rem 0.45rem; font-size: 0.84rem; transition: transform 0.15s ease;">
                            <i class="fa-solid fa-trash-can"></i>
                        </button>`}
                    </div>
                </div>
            `;
        });
        systemRolesListContainer.innerHTML = html;

        // Delete click handlers
        systemRolesListContainer.querySelectorAll('.btn-delete-sysrole').forEach(btn => {
            btn.addEventListener('click', async () => {
                const id = btn.dataset.id;
                const name = btn.dataset.name;
                const key = btn.dataset.key;
                if (!confirm(`Are you sure you want to delete system role "${name}"?`)) return;

                btn.disabled = true;
                try {
                    const res = await fetch('api.php?action=delete_system_role', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ id: id, role_key: key })
                    });
                    const data = await res.json();
                    if (data.success) {
                        updateSystemRoleSelect(data.roles);
                        renderSystemRolesList(data.roles);
                        if (typeof showToast === 'function') showToast(data.message, 'success');
                    } else {
                        alert(data.message || 'Error deleting role.');
                    }
                } catch (e) {
                    console.error(e);
                }
            });
        });
    }

    function updateSystemRoleSelect(roles, selectedRoleKey = null) {
        if (!systemRoleSelect) return;
        const currentVal = selectedRoleKey || systemRoleSelect.value;
        let optHtml = '';
        roles.forEach(r => {
            const isSel = (r.role_key === currentVal) ? 'selected' : '';
            optHtml += `<option value="${safeEscape(r.role_key)}" data-tier="${safeEscape(r.access_tier)}" data-desc="${safeEscape(r.description || '')}" ${isSel}>${safeEscape(r.role_name)}</option>`;
        });
        systemRoleSelect.innerHTML = optHtml;
        if (typeof updateRoleBadge === 'function') {
            updateRoleBadge(systemRoleSelect.value);
        }
    }

    if (addSystemRoleForm) {
        addSystemRoleForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const roleName = newSystemRoleNameInput ? newSystemRoleNameInput.value.trim() : '';
            const accessTier = newSystemRoleTierSelect ? newSystemRoleTierSelect.value : 'operator';
            const desc = newSystemRoleDescInput ? newSystemRoleDescInput.value.trim() : '';

            if (!roleName) return;

            if (saveSystemRoleBtn) {
                saveSystemRoleBtn.disabled = true;
                saveSystemRoleBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Adding...';
            }
            if (systemRoleFormAlert) systemRoleFormAlert.style.display = 'none';

            try {
                const res = await fetch('api.php?action=add_system_role', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ role_name: roleName, access_tier: accessTier, description: desc })
                });
                const data = await res.json();
                if (data.success) {
                    updateSystemRoleSelect(data.roles, data.new_role_key);
                    renderSystemRolesList(data.roles);
                    addSystemRoleForm.reset();
                    if (typeof showToast === 'function') showToast(`System Role "${roleName}" added successfully!`, 'success');
                    closeSystemModal();
                } else {
                    if (systemRoleFormAlert) {
                        systemRoleFormAlert.className = 'alert-box alert-danger';
                        systemRoleFormAlert.style.display = 'flex';
                        systemRoleFormAlert.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> ' + safeEscape(data.message || 'Error adding system role.');
                    }
                }
            } catch (err) {
                console.error(err);
                if (systemRoleFormAlert) {
                    systemRoleFormAlert.className = 'alert-box alert-danger';
                    systemRoleFormAlert.style.display = 'flex';
                    systemRoleFormAlert.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> Network error adding system role.';
                }
            } finally {
                if (saveSystemRoleBtn) {
                    saveSystemRoleBtn.disabled = false;
                    saveSystemRoleBtn.innerHTML = '<i class="fa-solid fa-plus"></i> Save System Role';
                }
            }
        });
    }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
