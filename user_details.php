<?php
// user_details.php - Dedicated User Details & System Accounts Management Page
$pageTitle = 'User Details & System Accounts - FabriqPro';
require_once __DIR__ . '/includes/auth.php';
requireAuth();

$pdo = Database::getConnection();
$currentUser = getCurrentUser();
if ($currentUser && $currentUser['role'] === 'operator') {
    $target = !empty($currentUser['worker_id']) ? "daily_output.php?worker_id=" . (int)$currentUser['worker_id'] : "daily_output.php";
    header("Location: " . $target);
    exit;
}

// Fetch all active production units dynamically
$unitsStmt = $pdo->query("SELECT * FROM production_units WHERE status = 'Active' ORDER BY id ASC");
$productionUnits = $unitsStmt->fetchAll(PDO::FETCH_ASSOC);

$validUnitNames = array_column($productionUnits, 'unit_name');

$requestedUnit = trim($_GET['unit'] ?? '');
if (!empty($requestedUnit) && !in_array($requestedUnit, $validUnitNames)) {
    $requestedUnit = '';
}

// Fetch all registered users with linked worker profile data
$usersQuery = "
    SELECT 
        u.id AS user_id,
        u.username,
        u.full_name,
        u.role AS system_role,
        u.unit AS user_unit,
        u.created_at AS user_created_at,
        w.id AS worker_id,
        w.unique_id,
        w.name AS worker_name,
        w.unit AS worker_unit,
        w.age,
        w.mobile_num,
        w.alt_mobile_num,
        w.address,
        w.role AS worker_designation,
        w.experience_years,
        w.extra_skills,
        w.total_pieces,
        COALESCE(w.status, 'Active') AS worker_status,
        (SELECT COUNT(*) FROM piece_logs WHERE worker_id = w.id) AS log_count,
        (SELECT MAX(work_date) FROM piece_logs WHERE worker_id = w.id) AS last_active_date
    FROM users u
    LEFT JOIN workers w ON (
        LOWER(u.username) = LOWER(w.unique_id) 
        OR LOWER(u.full_name) = LOWER(w.name)
        OR REPLACE(u.username, '-', '') = REPLACE(w.unique_id, '-', '')
    )
    ORDER BY u.id ASC
";
$usersStmt = $pdo->query($usersQuery);
$allUsers = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all worker category stitching statistics for instant breakdown loading
$workerCategoryLogs = [];
try {
    $workerCategoryLogs = $pdo->query("
        SELECT 
            worker_id,
            material_name,
            GROUP_CONCAT(DISTINCT COALESCE(unit, '')) as units_stitched,
            SUM(piece_count) as total_pieces,
            COUNT(*) as log_count,
            MAX(work_date) as last_date
        FROM piece_logs
        GROUP BY worker_id, material_name
        ORDER BY worker_id ASC, total_pieces DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $workerCategoryLogs = [];
}

$workerCategoryMap = [];
foreach ($workerCategoryLogs as $clog) {
    $wid = (int)$clog['worker_id'];
    if (!isset($workerCategoryMap[$wid])) {
        $workerCategoryMap[$wid] = [];
    }
    $workerCategoryMap[$wid][] = [
        'material_name' => $clog['material_name'],
        'total_pieces' => (int)$clog['total_pieces'],
        'log_count' => (int)$clog['log_count'],
        'last_date' => $clog['last_date'],
        'last_date_formatted' => !empty($clog['last_date']) ? date('M d, Y', strtotime($clog['last_date'])) : '—',
        'units_stitched' => !empty($clog['units_stitched']) ? trim($clog['units_stitched'], ', ') : ''
    ];
}

// Fetch all worker unit-wise stitching statistics for instant unit summary breakdown
$workerUnitLogs = [];
try {
    $workerUnitLogs = $pdo->query("
        SELECT 
            worker_id,
            COALESCE(NULLIF(TRIM(unit), ''), 'Unit 1') as unit_name,
            SUM(piece_count) as total_pieces,
            COUNT(*) as log_count
        FROM piece_logs
        GROUP BY worker_id, COALESCE(NULLIF(TRIM(unit), ''), 'Unit 1')
        ORDER BY worker_id ASC, total_pieces DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $workerUnitLogs = [];
}

$workerUnitMap = [];
foreach ($workerUnitLogs as $ulog) {
    $wid = (int)$ulog['worker_id'];
    if (!isset($workerUnitMap[$wid])) {
        $workerUnitMap[$wid] = [];
    }
    $workerUnitMap[$wid][] = [
        'unit_name' => $ulog['unit_name'],
        'total_pieces' => (int)$ulog['total_pieces'],
        'log_count' => (int)$ulog['log_count']
    ];
}

// Calculate dynamic unit-wise stats
$unitStats = [];
foreach ($productionUnits as $pu) {
    $uName = $pu['unit_name'];
    $uCount = 0;
    foreach ($allUsers as $u) {
        $userUnit = !empty($u['worker_unit']) ? $u['worker_unit'] : (!empty($u['user_unit']) ? $u['user_unit'] : '');
        if (strcasecmp($userUnit, $uName) === 0) {
            $uCount++;
        }
    }
    $unitStats[$uName] = [
        'user_count' => $uCount,
        'pu' => $pu
    ];
}

// Company metrics calculation
$totalUsersCount = count($allUsers);
$adminCount = 0;
$supervisorCount = 0;
$operatorCount = 0;
$totalPiecesSum = 0;

foreach ($allUsers as $u) {
    $r = strtolower($u['system_role']);
    if ($r === 'admin') $adminCount++;
    elseif ($r === 'supervisor') $supervisorCount++;
    elseif ($r === 'operator') $operatorCount++;
    if (!empty($u['total_pieces'])) {
        $totalPiecesSum += (int)$u['total_pieces'];
    }
}

include __DIR__ . '/includes/header.php';
?>

<style>
/* ===================================================================
   MODERN USER DIRECTORY DESIGN SYSTEM
   =================================================================== */
.user-dir-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
    margin-bottom: 1.25rem;
}
.user-dir-brand {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}
.user-dir-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    background: linear-gradient(135deg, rgba(6,182,212,0.22), rgba(59,130,246,0.18));
    border: 1px solid rgba(6,182,212,0.35);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--accent-cyan);
    font-size: 1.25rem;
    box-shadow: 0 0 20px rgba(6,182,212,0.18);
}
.user-dir-title {
    font-size: 1.35rem;
    font-weight: 800;
    color: #fff;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.6rem;
    letter-spacing: -0.01em;
}
.user-dir-sub {
    margin: 0.2rem 0 0;
    font-size: 0.78rem;
    color: var(--text-muted);
}

/* Segmented Unit Navigation Bar */
.segmented-unit-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    flex-wrap: wrap;
    margin-bottom: 1.15rem;
}
.segmented-nav {
    display: inline-flex;
    background: rgba(15,23,42,0.65);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 12px;
    padding: 4px;
    gap: 4px;
    flex-wrap: wrap;
    backdrop-filter: blur(8px);
}
.unit-tab-pill {
    padding: 0.5rem 0.95rem;
    border: 1px solid transparent;
    background: transparent;
    border-radius: 8px;
    color: var(--text-secondary);
    font-size: 0.82rem;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    white-space: nowrap;
}
.unit-tab-pill:hover {
    color: #fff;
    background: rgba(255,255,255,0.04);
}
.unit-tab-pill.active {
    background: linear-gradient(135deg, rgba(6,182,212,0.25), rgba(59,130,246,0.2));
    color: #fff;
    border-color: rgba(6,182,212,0.45);
    box-shadow: 0 4px 15px rgba(6,182,212,0.2);
}
.pill-count {
    font-size: 0.7rem;
    padding: 0.1rem 0.45rem;
    border-radius: 999px;
    background: rgba(255,255,255,0.08);
    font-weight: 700;
}
.unit-tab-pill.active .pill-count {
    background: rgba(6,182,212,0.3);
    color: #e0f2fe;
}

/* Control Toolbar */
.control-toolbar-card {
    padding: 0.85rem 1rem;
    margin-bottom: 1.15rem;
    background: rgba(15,23,42,0.6);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: var(--radius-md);
    backdrop-filter: blur(8px);
}
.role-chip {
    padding: 0.35rem 0.75rem;
    border-radius: 20px;
    border: 1px solid var(--border-color);
    background: rgba(255,255,255,0.03);
    color: var(--text-muted);
    font-size: 0.76rem;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    transition: all 0.15s ease;
    white-space: nowrap;
}
.role-chip:hover {
    border-color: rgba(255,255,255,0.25);
    color: #fff;
    background: rgba(255,255,255,0.06);
}
.role-chip.active {
    background: rgba(255,255,255,0.12);
    border-color: rgba(255,255,255,0.35);
    color: #fff;
}
.role-chip.active.chip-admin {
    background: rgba(168,85,247,0.18);
    border-color: rgba(168,85,247,0.45);
    color: #c084fc;
}
.role-chip.active.chip-supervisor {
    background: rgba(6,182,212,0.18);
    border-color: rgba(6,182,212,0.45);
    color: #06b6d4;
}
.role-chip.active.chip-operator {
    background: rgba(16,185,129,0.18);
    border-color: rgba(16,185,129,0.45);
    color: #10b981;
}
.chip-count {
    font-size: 0.68rem;
    padding: 0.05rem 0.35rem;
    border-radius: 999px;
    background: rgba(255,255,255,0.1);
}

/* View Mode Switcher */
.view-mode-toggle {
    display: inline-flex;
    background: rgba(0,0,0,0.3);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 8px;
    padding: 3px;
    gap: 3px;
}
.view-toggle-btn {
    padding: 0.35rem 0.65rem;
    border: none;
    background: transparent;
    border-radius: 6px;
    color: var(--text-muted);
    font-size: 0.76rem;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    font-weight: 500;
    transition: all 0.15s ease;
}
.view-toggle-btn:hover {
    color: #fff;
}
.view-toggle-btn.active {
    background: rgba(6,182,212,0.2);
    color: var(--accent-cyan);
    font-weight: 700;
    box-shadow: 0 0 10px rgba(6,182,212,0.15);
}

/* Table Column Widths & Compact Rules */
.col-name-id { min-width: 175px; }
.col-phone { min-width: 120px; }
.col-age { min-width: 65px; text-align: center; }
.col-unit { min-width: 95px; }
.col-status { min-width: 80px; text-align: center; }
.col-actions { min-width: 260px; text-align: right; }

#userDetailsTable {
    table-layout: auto !important;
    width: 100% !important;
}
#userDetailsTable th {
    padding: 0.65rem 0.6rem !important;
    font-size: 0.72rem !important;
    letter-spacing: 0.04em !important;
    text-transform: uppercase;
}
#userDetailsTable td {
    padding: 0.65rem 0.6rem !important;
    font-size: 0.82rem !important;
    vertical-align: middle;
}

/* 3 Action Buttons Styling */
.user-action-group {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    justify-content: flex-end;
    white-space: nowrap;
}
.btn-action-view {
    padding: 0.32rem 0.6rem;
    font-size: 0.73rem;
    font-weight: 600;
    border-radius: 6px;
    border: 1px solid rgba(6,182,212,0.4);
    background: rgba(6,182,212,0.1);
    color: #38bdf8;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    transition: all 0.18s ease;
}
.btn-action-view:hover {
    background: rgba(6,182,212,0.25);
    border-color: #38bdf8;
    color: #fff;
    box-shadow: 0 0 10px rgba(6,182,212,0.25);
}

.btn-action-edit {
    padding: 0.32rem 0.6rem;
    font-size: 0.73rem;
    font-weight: 600;
    border-radius: 6px;
    border: 1px solid rgba(168,85,247,0.38);
    background: rgba(168,85,247,0.09);
    color: #c084fc;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    transition: all 0.18s ease;
}
.btn-action-edit:hover {
    background: rgba(168,85,247,0.25);
    border-color: #c084fc;
    color: #fff;
    box-shadow: 0 0 10px rgba(168,85,247,0.3);
}

.btn-action-delete {
    padding: 0.32rem 0.6rem;
    font-size: 0.73rem;
    font-weight: 600;
    border-radius: 6px;
    border: 1px solid rgba(244,63,94,0.35);
    background: rgba(244,63,94,0.08);
    color: #fb7185;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    transition: all 0.18s ease;
}
.btn-action-delete:hover:not(:disabled) {
    background: rgba(244,63,94,0.25);
    border-color: #f43f5e;
    color: #fff;
    box-shadow: 0 0 10px rgba(244,63,94,0.3);
}

.btn-action-toggle {
    padding: 0.32rem 0.6rem;
    font-size: 0.73rem;
    font-weight: 600;
    border-radius: 6px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    transition: all 0.18s ease;
}
.btn-action-toggle.is-active {
    border: 1px solid rgba(16,185,129,0.4);
    background: rgba(16,185,129,0.12);
    color: #34d399;
}
.btn-action-toggle.is-active:hover {
    background: rgba(16,185,129,0.25);
    border-color: #10b981;
    color: #fff;
    box-shadow: 0 0 10px rgba(16,185,129,0.25);
}
.btn-action-toggle.is-inactive {
    border: 1px solid rgba(245,158,11,0.35);
    background: rgba(245,158,11,0.08);
    color: #fbbf24;
}
.btn-action-toggle.is-inactive:hover {
    background: rgba(245,158,11,0.22);
    border-color: #f59e0b;
    color: #fff;
    box-shadow: 0 0 10px rgba(245,158,11,0.25);
}

/* Status Pill */
.status-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.2rem 0.55rem;
    border-radius: 999px;
    font-size: 0.72rem;
    font-weight: 700;
}
.status-pill.status-active {
    background: rgba(16,185,129,0.15);
    color: #34d399;
    border: 1px solid rgba(16,185,129,0.35);
}
.status-pill.status-inactive {
    background: rgba(245,158,11,0.12);
    color: #fbbf24;
    border: 1px solid rgba(245,158,11,0.35);
}
.status-indicator-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    display: inline-block;
}
.status-active .status-indicator-dot {
    background: #10b981;
    box-shadow: 0 0 8px #10b981;
}
.status-inactive .status-indicator-dot {
    background: #f59e0b;
}

/* Detail Modal Styles */
.detail-info-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 0.75rem;
    margin-bottom: 1.15rem;
}
.detail-info-item {
    background: rgba(0,0,0,0.25);
    border: 1px solid rgba(255,255,255,0.06);
    border-radius: 8px;
    padding: 0.65rem 0.85rem;
}
.detail-info-label {
    font-size: 0.72rem;
    color: var(--text-muted);
    margin-bottom: 0.2rem;
    display: flex;
    align-items: center;
    gap: 0.35rem;
}
.detail-info-val {
    font-size: 0.88rem;
    font-weight: 600;
    color: #fff;
}

/* Category Breakdown in Detail Modal */
.user-detail-category-card {
    background: rgba(15,23,42,0.85);
    border: 1px solid rgba(6,182,212,0.3);
    border-radius: 10px;
    padding: 1rem;
    box-shadow: 0 4px 20px -2px rgba(0,0,0,0.35);
    margin-bottom: 1.15rem;
}
.category-breakdown-row {
    background: rgba(0,0,0,0.32);
    border: 1px solid rgba(255,255,255,0.07);
    border-radius: 8px;
    padding: 0.5rem 0.75rem;
    transition: transform 0.15s ease, border-color 0.15s ease, background 0.15s ease;
}
.category-breakdown-row:hover {
    border-color: rgba(6,182,212,0.45) !important;
    background: rgba(6,182,212,0.06) !important;
    transform: translateY(-1px);
}

.btn-unit-transfer-quick {
    background: rgba(6,182,212,0.12);
    border: 1px solid rgba(6,182,212,0.3);
    color: var(--accent-cyan);
    width: 24px;
    height: 24px;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.7rem;
    cursor: pointer;
    transition: all 0.2s ease;
}
.btn-unit-transfer-quick:hover {
    background: rgba(6,182,212,0.28) !important;
    border-color: rgba(6,182,212,0.6) !important;
    color: #fff !important;
    transform: scale(1.1);
    box-shadow: 0 0 10px rgba(6,182,212,0.3);
}

/* Sleek Modern Scrollbar for Modal and Breakdown Container */
#userDetailsProfileModal .modal-body-scroll::-webkit-scrollbar,
#detailModalCategoryBreakdownContainer::-webkit-scrollbar {
    width: 6px;
}
#userDetailsProfileModal .modal-body-scroll::-webkit-scrollbar-track,
#detailModalCategoryBreakdownContainer::-webkit-scrollbar-track {
    background: rgba(15,23,42,0.6);
    border-radius: 6px;
}
#userDetailsProfileModal .modal-body-scroll::-webkit-scrollbar-thumb,
#detailModalCategoryBreakdownContainer::-webkit-scrollbar-thumb {
    background: rgba(6,182,212,0.35);
    border-radius: 6px;
}
#userDetailsProfileModal .modal-body-scroll::-webkit-scrollbar-thumb:hover,
#detailModalCategoryBreakdownContainer::-webkit-scrollbar-thumb:hover {
    background: rgba(6,182,212,0.7);
}

/* User Card Items (Grid View) */
.user-card-item {
    background: rgba(15,23,42,0.65);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: var(--radius-md);
    padding: 1.15rem;
    transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    display: flex;
    flex-direction: column;
    gap: 0.85rem;
    backdrop-filter: blur(8px);
}
.user-card-item:hover {
    border-color: rgba(6,182,212,0.35);
    transform: translateY(-2px);
    box-shadow: 0 12px 30px -8px rgba(0,0,0,0.4), 0 0 15px rgba(6,182,212,0.12);
}

@media (max-width: 1200px) {
    .dashboard-container {
        padding: 1rem 0.75rem !important;
    }
}
@media (max-width: 680px) {
    #detailModalOverallSummaryCards {
        grid-template-columns: 1fr !important;
    }
}
</style>

<!-- ==========================================
     1. MODERN USER DIRECTORY HEADER
     ========================================== -->
<div class="user-dir-header">
    <div class="user-dir-brand">
        <div class="user-dir-icon">
            <i class="fa-solid fa-users-gear"></i>
        </div>
        <div>
            <h1 class="user-dir-title">
                User Directory &amp; Accounts
                <span class="badge-pill" id="totalUsersBadge" style="background: rgba(6,182,212,0.15); color: #06b6d4; font-size: 0.74rem; font-weight: 700;">
                    <?= count($allUsers) ?> Active
                </span>
            </h1>
        </div>
    </div>

    <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
        <!-- View Mode Switcher -->
        <div class="view-mode-toggle">
            <button type="button" class="view-toggle-btn active" id="viewModeTableBtn" title="Switch to Table List View">
                <i class="fa-solid fa-table-list"></i> Table
            </button>
            <button type="button" class="view-toggle-btn" id="viewModeCardsBtn" title="Switch to Visual Grid Cards">
                <i class="fa-solid fa-grip"></i> Cards
            </button>
        </div>

        <?php if ($currentUser && in_array($currentUser['role'], ['admin', 'supervisor'])): ?>
            <button type="button" class="btn btn-primary btn-sm" onclick="openAddUserModal()" style="font-size: 0.82rem; padding: 0.45rem 0.95rem; display: inline-flex; align-items: center; gap: 0.45rem; box-shadow: 0 0 18px rgba(6,182,212,0.25);">
                <i class="fa-solid fa-user-plus"></i> Add User
            </button>
        <?php endif; ?>

        <?php if ($currentUser && $currentUser['role'] === 'admin'): ?>
            <button type="button" onclick="openClearDataModal()" class="btn btn-outline btn-sm text-rose" style="font-size: 0.8rem; padding: 0.45rem 0.75rem; border-color: rgba(244,63,94,0.3); background: rgba(244,63,94,0.05);" title="Clear Test Data">
                <i class="fa-solid fa-trash-can"></i>
            </button>
        <?php endif; ?>
    </div>
</div>

<!-- ==========================================
     2. SEGMENTED UNIT PILL NAVIGATOR
     ========================================== -->
<div class="segmented-unit-bar">
    <div class="segmented-nav">
        <!-- All Factory Units -->
        <button type="button" class="unit-tab-pill <?= empty($requestedUnit) ? 'active' : '' ?>" data-unit="" id="tabAllUnits">
            <i class="fa-solid fa-layer-group"></i>
            <span>All Units</span>
            <span class="pill-count" id="countAllUnits"><?= count($allUsers) ?></span>
        </button>

        <!-- Dynamic Factory Units -->
        <?php foreach ($productionUnits as $pu): 
            $isActive = ($requestedUnit === $pu['unit_name']);
            $uName = $pu['unit_name'];
            $uCount = $unitStats[$uName]['user_count'] ?? 0;
            $uIcon = !empty($pu['icon']) ? $pu['icon'] : 'fa-industry';
            if ($uIcon === 'fa-building-gear') $uIcon = 'fa-warehouse';
        ?>
            <button type="button" class="unit-tab-pill <?= $isActive ? 'active' : '' ?>" data-unit="<?= htmlspecialchars($uName) ?>" id="tabUnit_<?= htmlspecialchars(preg_replace('/[^a-zA-Z0-9]/', '_', $uName)) ?>">
                <i class="fa-solid <?= htmlspecialchars($uIcon) ?>"></i>
                <span><?= htmlspecialchars($pu['unit_title']) ?></span>
                <span class="pill-count" id="count_<?= htmlspecialchars(preg_replace('/[^a-zA-Z0-9]/', '_', $uName)) ?>"><?= $uCount ?></span>
            </button>
        <?php endforeach; ?>
    </div>

    <!-- Active Filter Summary & Quick Reset -->
    <div style="display: flex; align-items: center; gap: 0.65rem; font-size: 0.78rem;">
        <span id="activeFilterSummary" style="color: var(--text-muted);">
            Showing all factory accounts
        </span>
        <button type="button" id="quickResetFiltersBtn" class="btn btn-outline btn-xs" style="display: none; padding: 0.2rem 0.5rem; font-size: 0.72rem;">
            <i class="fa-solid fa-rotate-left"></i> Reset
        </button>
    </div>
</div>

<!-- ==========================================
     3. UNIFIED SEARCH & ROLE FILTER BAR
     ========================================== -->
<div class="control-toolbar-card">
    <div style="display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; flex-wrap: wrap;">
        <!-- Search Input -->
        <div class="search-input-wrapper" style="flex: 2 1 240px; min-width: 220px;">
            <i class="fa-solid fa-magnifying-glass" style="color: var(--accent-cyan);"></i>
            <input type="text" id="userTableSearch" class="form-control" placeholder="Search by name, username, ID, phone, designation..." style="height: 38px; font-size: 0.82rem; background: rgba(0,0,0,0.25); border-color: rgba(255,255,255,0.1);">
        </div>

        <!-- Interactive Role Filter Chips (Stats + Filters Combined!) -->
        <div class="role-filter-strip" style="display: inline-flex; gap: 0.35rem; flex-wrap: wrap; align-items: center;">
            <button type="button" class="role-chip active" data-role="" id="roleChipAll">
                All Roles <span class="chip-count" id="chipTotalUsers"><?= count($allUsers) ?></span>
            </button>
            <button type="button" class="role-chip chip-admin" data-role="admin" id="roleChipAdmin">
                <i class="fa-solid fa-shield-halved text-purple"></i> Admins <span class="chip-count" id="chipAdminCount"><?= $adminCount ?></span>
            </button>
            <button type="button" class="role-chip chip-supervisor" data-role="supervisor" id="roleChipSupervisor">
                <i class="fa-solid fa-user-tie text-cyan"></i> Supervisors <span class="chip-count" id="chipSupervisorCount"><?= $supervisorCount ?></span>
            </button>
            <button type="button" class="role-chip chip-operator" data-role="operator" id="roleChipOperator">
                <i class="fa-solid fa-laptop-code text-emerald"></i> Operators <span class="chip-count" id="chipOperatorCount"><?= $operatorCount ?></span>
            </button>
        </div>

        <!-- Status Filter Select -->
        <div style="width: 125px;">
            <select id="userStatusFilter" class="form-control" style="height: 38px; font-size: 0.78rem; background: rgba(0,0,0,0.25); border-color: rgba(255,255,255,0.1);">
                <option value="">All Statuses</option>
                <option value="active">Active Only</option>
                <option value="disabled">Disabled</option>
            </select>
        </div>
    </div>
</div>

<!-- ==========================================
     4. DIRECTORY CONTENT (TABLE & CARDS)
     ========================================== -->
<div class="card" id="mainDirectoryCard" style="overflow: hidden; max-width: 100%; box-sizing: border-box; padding: 1.15rem 1rem;">

    <!-- A. TABLE LIST VIEW (DEFAULT) -->
    <div class="table-responsive" id="tableViewContainer">
        <table class="data-table" id="userDetailsTable">
            <thead>
                <tr>
                    <th class="col-name-id">Name &amp; ID</th>
                    <th class="col-phone">Phone Number</th>
                    <th class="col-age">Age</th>
                    <th class="col-unit">Production Unit</th>
                    <th class="col-status">Status</th>
                    <th class="col-actions">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($allUsers)): ?>
                    <tr id="tableZeroUsersFallback">
                        <td colspan="6" style="text-align: center; padding: 2.5rem 1rem; color: var(--text-muted);">
                            <i class="fa-solid fa-user-slash" style="font-size: 2rem; opacity: 0.3; margin-bottom: 0.5rem;"></i>
                            <div>No registered users found.</div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php 
                    $allUserRecordsJson = [];
                    foreach ($allUsers as $user): 
                        $sRole = strtolower($user['system_role']);
                        $displayName = !empty($user['full_name']) ? $user['full_name'] : $user['username'];
                        $firstLetter = strtoupper(substr($displayName, 0, 1));
                        $unitVal = !empty($user['worker_unit']) ? $user['worker_unit'] : (!empty($user['user_unit']) ? $user['user_unit'] : 'All');
                        $designation = !empty($user['worker_designation']) ? $user['worker_designation'] : (ucfirst($sRole) . ' Account');
                        $phone = !empty($user['mobile_num']) ? $user['mobile_num'] : 'N/A';
                        $ageVal = !empty($user['age']) ? (int)$user['age'] : 0;
                        $pieces = isset($user['total_pieces']) ? (int)$user['total_pieces'] : 0;
                        $workerId = (int)($user['worker_id'] ?? 0);
                        $userId = (int)$user['user_id'];
                        $workerStatus = !empty($user['worker_status']) ? $user['worker_status'] : 'Active';
                        $isActive = (strcasecmp($workerStatus, 'Active') === 0);
                        $isRootAdmin = ($userId === 1 || strcasecmp($user['username'], 'admin') === 0);
                        $uColor = (stripos($unitVal, 'unit 2') !== false) ? 'pink' : ((stripos($unitVal, 'unit 1') !== false) ? 'cyan' : 'emerald');

                        // Avatar gradient per role
                        $avatarBg = 'linear-gradient(135deg, #2563eb, #3b82f6)';
                        if ($sRole === 'admin') {
                            $avatarBg = 'linear-gradient(135deg, #9333ea, #c084fc)';
                        } elseif ($sRole === 'supervisor') {
                            $avatarBg = 'linear-gradient(135deg, #0891b2, #06b6d4)';
                        } elseif ($sRole === 'operator') {
                            $avatarBg = 'linear-gradient(135deg, #059669, #10b981)';
                        }

                        $userRecordJson = [
                            'user_id' => $userId,
                            'worker_id' => $workerId,
                            'username' => $user['username'],
                            'name' => $displayName,
                            'role' => $sRole,
                            'designation' => $designation,
                            'unit' => $unitVal,
                            'phone' => $phone,
                            'raw_phone' => ($phone !== 'N/A' && !empty($phone)) ? $phone : '',
                            'alt_phone' => !empty($user['alt_mobile_num']) ? $user['alt_mobile_num'] : '—',
                            'raw_alt_phone' => (!empty($user['alt_mobile_num']) && $user['alt_mobile_num'] !== '—') ? $user['alt_mobile_num'] : '',
                            'age' => $ageVal > 0 ? ($ageVal . ' yrs') : '—',
                            'raw_age' => $ageVal > 0 ? $ageVal : '',
                            'raw_role' => $designation,
                            'address' => !empty($user['address']) ? $user['address'] : 'Factory Floor',
                            'experience' => !empty($user['experience_years']) ? ($user['experience_years'] . ' y') : '—',
                            'extra_skills' => !empty($user['extra_skills']) ? $user['extra_skills'] : 'Standard Operations',
                            'pieces' => number_format($pieces),
                            'status' => $isActive ? 'Active' : 'Inactive',
                            'created_at' => !empty($user['user_created_at']) ? date('M d, Y', strtotime($user['user_created_at'])) : 'Recent',
                            'categories' => (!empty($workerId) && isset($workerCategoryMap[$workerId])) ? $workerCategoryMap[$workerId] : [],
                            'unit_breakdown' => (!empty($workerId) && isset($workerUnitMap[$workerId])) ? $workerUnitMap[$workerId] : [],
                            'is_admin' => $isRootAdmin
                        ];
                        $allUserRecordsJson[$userId . '_' . $workerId] = $userRecordJson;
                    ?>
                    <tr class="user-row user-record" id="userRow_<?= $userId ?>_<?= $workerId ?>"
                        data-name="<?= htmlspecialchars(strtolower($displayName)) ?>"
                        data-username="<?= htmlspecialchars(strtolower($user['username'])) ?>"
                        data-role="<?= htmlspecialchars($sRole) ?>"
                        data-unit="<?= htmlspecialchars(strtolower($unitVal)) ?>"
                        data-status="<?= htmlspecialchars(strtolower($workerStatus)) ?>"
                        data-phone="<?= htmlspecialchars(strtolower($phone)) ?>"
                        data-designation="<?= htmlspecialchars(strtolower($designation)) ?>">
                        
                        <!-- 1. Name & ID -->
                        <td>
                            <div class="worker-meta-cell">
                                <div class="worker-meta-avatar" style="background: <?= $avatarBg ?>;">
                                    <?= $firstLetter ?>
                                </div>
                                <div style="min-width: 0;">
                                    <div class="worker-meta-name" style="font-weight: 700; font-size: 0.92rem; color: #fff; white-space: nowrap;">
                                        <?= htmlspecialchars($displayName) ?>
                                    </div>
                                    <div style="display: flex; align-items: center; gap: 0.35rem; margin-top: 0.15rem; flex-wrap: wrap;">
                                        <span class="badge-pill" style="font-family: monospace; font-size: 0.72rem; padding: 0.05rem 0.35rem; background: rgba(6,182,212,0.08); color: var(--accent-cyan); border: 1px solid rgba(6,182,212,0.25);">
                                            <i class="fa-solid fa-id-badge"></i> <?= htmlspecialchars($user['username']) ?>
                                        </span>
                                        <span style="font-size: 0.72rem; color: var(--text-muted);">
                                            <?= htmlspecialchars($designation) ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </td>

                        <!-- 2. Phone Number -->
                        <td>
                            <?php if ($phone !== 'N/A' && !empty($phone)): ?>
                                <a href="tel:<?= htmlspecialchars($phone) ?>" style="color: var(--text-primary); text-decoration: none; font-size: 0.82rem; font-weight: 600; display: inline-flex; align-items: center; gap: 0.35rem;" title="Call contact number">
                                    <i class="fa-solid fa-phone text-cyan" style="font-size: 0.72rem;"></i> <?= htmlspecialchars($phone) ?>
                                </a>
                            <?php else: ?>
                                <span class="text-muted" style="font-size: 0.8rem;">—</span>
                            <?php endif; ?>
                        </td>

                        <!-- 3. Age -->
                        <td style="text-align: center;">
                            <?php if ($ageVal > 0): ?>
                                <span style="font-weight: 700; font-size: 0.86rem; color: #e2e8f0;">
                                    <?= $ageVal ?> <span style="font-size: 0.7rem; color: var(--text-muted); font-weight: 400;">yrs</span>
                                </span>
                            <?php else: ?>
                                <span class="text-muted" style="font-size: 0.8rem;">—</span>
                            <?php endif; ?>
                        </td>

                        <!-- 4. Production Unit -->
                        <td>
                            <div style="display: inline-flex; align-items: center; gap: 0.35rem;">
                                <span class="badge-pill unit-<?= $uColor ?>-pill" id="tableUnitBadge_<?= $userId ?>_<?= $workerId ?>" style="font-size: 0.75rem;">
                                    <i class="fa-solid fa-building"></i> <?= htmlspecialchars($unitVal) ?>
                                </span>
                                <?php if ($currentUser && in_array($currentUser['role'], ['admin', 'supervisor']) && !$isRootAdmin): ?>
                                    <button type="button" class="btn-unit-transfer-quick" 
                                        onclick="quickTransferUserUnit(<?= htmlspecialchars(json_encode($userRecordJson), ENT_QUOTES, 'UTF-8') ?>)" 
                                        title="Transfer user between Unit 1 &amp; Unit 2 (Emergency or Permanent)">
                                        <i class="fa-solid fa-arrow-right-arrow-left"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>

                        <!-- 5. Status -->
                        <td style="text-align: center;">
                            <span class="status-pill status-<?= $isActive ? 'active' : 'inactive' ?>" id="statusPill_<?= $userId ?>_<?= $workerId ?>">
                                <span class="status-indicator-dot"></span>
                                <span id="statusText_<?= $userId ?>_<?= $workerId ?>"><?= $isActive ? 'Active' : 'Inactive' ?></span>
                            </span>
                        </td>

                        <!-- 6. Actions: 1) View Details, 2) Edit, 3) Delete, 4) Active|Inactive -->
                        <td style="text-align: right;">
                            <div class="user-action-group">
                                <!-- 1. View Details Button -->
                                <button type="button" class="btn-action-view" 
                                    onclick="openUserDetailsModal(<?= htmlspecialchars(json_encode($userRecordJson), ENT_QUOTES, 'UTF-8') ?>)" 
                                    title="View Full Profile Details">
                                    <i class="fa-solid fa-eye"></i> View Details
                                </button>

                                <!-- 2. Edit Button -->
                                <button type="button" class="btn-action-edit" 
                                    onclick="openEditUserModal(<?= htmlspecialchars(json_encode($userRecordJson), ENT_QUOTES, 'UTF-8') ?>)" 
                                    title="Edit User &amp; Worker Details">
                                    <i class="fa-solid fa-pen-to-square"></i> Edit
                                </button>

                                <!-- 3. Delete Button -->
                                <?php if ($isRootAdmin): ?>
                                    <button type="button" class="btn-action-delete" style="opacity: 0.35; cursor: not-allowed;" title="Root Administrator account cannot be deleted" disabled>
                                        <i class="fa-solid fa-trash-can"></i> Delete
                                    </button>
                                <?php else: ?>
                                    <button type="button" class="btn-action-delete" 
                                        onclick="confirmDeleteUser(<?= $userId ?>, <?= $workerId ?>, '<?= htmlspecialchars(addslashes($user['username'])) ?>', '<?= htmlspecialchars(addslashes($displayName)) ?>')" 
                                        title="Delete User and Worker Account">
                                        <i class="fa-solid fa-trash-can"></i> Delete
                                    </button>
                                <?php endif; ?>

                                <!-- 4. Active | Inactive Button -->
                                <button type="button" class="btn-action-toggle <?= $isActive ? 'is-active' : 'is-inactive' ?>"
                                    id="toggleBtn_<?= $userId ?>_<?= $workerId ?>"
                                    onclick="toggleUserStatus(<?= $userId ?>, <?= $workerId ?>, '<?= htmlspecialchars(addslashes($user['username'])) ?>')"
                                    title="Toggle Active / Inactive Status">
                                    <i class="fa-solid <?= $isActive ? 'fa-toggle-on' : 'fa-toggle-off' ?>" id="toggleIcon_<?= $userId ?>_<?= $workerId ?>"></i>
                                    <span id="toggleText_<?= $userId ?>_<?= $workerId ?>"><?= $isActive ? 'Active' : 'Inactive' ?></span>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- B. VISUAL USER CARDS GRID (ALTERNATIVE VIEW) -->
    <div id="cardsViewContainer" style="display: none; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1rem;">
        <?php foreach ($allUsers as $user): 
            $sRole = strtolower($user['system_role']);
            $displayName = !empty($user['full_name']) ? $user['full_name'] : $user['username'];
            $firstLetter = strtoupper(substr($displayName, 0, 1));
            $unitVal = !empty($user['worker_unit']) ? $user['worker_unit'] : (!empty($user['user_unit']) ? $user['user_unit'] : 'All');
            $designation = !empty($user['worker_designation']) ? $user['worker_designation'] : (ucfirst($sRole) . ' Account');
            $phone = !empty($user['mobile_num']) ? $user['mobile_num'] : 'N/A';
            $ageVal = !empty($user['age']) ? (int)$user['age'] : 0;
            $pieces = isset($user['total_pieces']) ? (int)$user['total_pieces'] : 0;
            $workerId = (int)($user['worker_id'] ?? 0);
            $userId = (int)$user['user_id'];
            $workerStatus = !empty($user['worker_status']) ? $user['worker_status'] : 'Active';
            $isActive = (strcasecmp($workerStatus, 'Active') === 0);
            $isRootAdmin = ($userId === 1 || strcasecmp($user['username'], 'admin') === 0);
            $uColor = (stripos($unitVal, 'unit 2') !== false) ? 'pink' : ((stripos($unitVal, 'unit 1') !== false) ? 'cyan' : 'emerald');

            $avatarBg = 'linear-gradient(135deg, #2563eb, #3b82f6)';
            if ($sRole === 'admin') $avatarBg = 'linear-gradient(135deg, #9333ea, #c084fc)';
            elseif ($sRole === 'supervisor') $avatarBg = 'linear-gradient(135deg, #0891b2, #06b6d4)';
            elseif ($sRole === 'operator') $avatarBg = 'linear-gradient(135deg, #059669, #10b981)';

            $userRecordJson = [
                'user_id' => $userId,
                'worker_id' => $workerId,
                'username' => $user['username'],
                'name' => $displayName,
                'role' => $sRole,
                'designation' => $designation,
                'unit' => $unitVal,
                'phone' => $phone,
                'alt_phone' => !empty($user['alt_mobile_num']) ? $user['alt_mobile_num'] : '—',
                'age' => $ageVal > 0 ? ($ageVal . ' yrs') : '—',
                'address' => !empty($user['address']) ? $user['address'] : 'Factory Floor',
                'experience' => !empty($user['experience_years']) ? ($user['experience_years'] . ' y') : '—',
                'extra_skills' => !empty($user['extra_skills']) ? $user['extra_skills'] : 'Standard Operations',
                'pieces' => number_format($pieces),
                'status' => $isActive ? 'Active' : 'Inactive',
                'created_at' => !empty($user['user_created_at']) ? date('M d, Y', strtotime($user['user_created_at'])) : 'Recent',
                'categories' => (!empty($workerId) && isset($workerCategoryMap[$workerId])) ? $workerCategoryMap[$workerId] : [],
                'unit_breakdown' => (!empty($workerId) && isset($workerUnitMap[$workerId])) ? $workerUnitMap[$workerId] : [],
                'is_admin' => $isRootAdmin
            ];
        ?>
        <div class="user-card-item user-card-record user-record" id="userCard_<?= $userId ?>_<?= $workerId ?>"
            data-name="<?= htmlspecialchars(strtolower($displayName)) ?>"
            data-username="<?= htmlspecialchars(strtolower($user['username'])) ?>"
            data-role="<?= htmlspecialchars($sRole) ?>"
            data-unit="<?= htmlspecialchars(strtolower($unitVal)) ?>"
            data-status="<?= htmlspecialchars(strtolower($workerStatus)) ?>"
            data-phone="<?= htmlspecialchars(strtolower($phone)) ?>"
            data-designation="<?= htmlspecialchars(strtolower($designation)) ?>">
            
            <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 0.75rem;">
                <div style="display: flex; align-items: center; gap: 0.75rem;">
                    <div class="worker-meta-avatar" style="width: 44px; height: 44px; font-size: 1.15rem; background: <?= $avatarBg ?>; box-shadow: 0 0 15px rgba(6,182,212,0.2);">
                        <?= $firstLetter ?>
                    </div>
                    <div>
                        <div style="font-weight: 700; font-size: 0.95rem; color: #fff;">
                            <?= htmlspecialchars($displayName) ?>
                        </div>
                        <div style="font-family: monospace; font-size: 0.76rem; color: var(--accent-cyan); margin-top: 0.15rem;">
                            @<?= htmlspecialchars($user['username']) ?>
                        </div>
                    </div>
                </div>

                <span class="status-pill status-<?= $isActive ? 'active' : 'inactive' ?>" id="cardStatusPill_<?= $userId ?>_<?= $workerId ?>">
                    <span class="status-indicator-dot"></span>
                    <span id="cardStatusText_<?= $userId ?>_<?= $workerId ?>"><?= $isActive ? 'Active' : 'Inactive' ?></span>
                </span>
            </div>

            <div style="padding: 0.65rem 0.85rem; background: rgba(0,0,0,0.22); border: 1px solid rgba(255,255,255,0.05); border-radius: var(--radius-sm); font-size: 0.78rem; display: flex; flex-direction: column; gap: 0.45rem;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span class="text-muted"><i class="fa-solid fa-phone" style="font-size: 0.7rem;"></i> Phone:</span>
                    <strong style="color: #fff;"><?= htmlspecialchars($phone) ?></strong>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span class="text-muted"><i class="fa-solid fa-cake-candles" style="font-size: 0.7rem;"></i> Age:</span>
                    <strong style="color: #fff;"><?= $ageVal > 0 ? ($ageVal . ' yrs') : '—' ?></strong>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span class="text-muted"><i class="fa-solid fa-building" style="font-size: 0.7rem;"></i> Unit:</span>
                    <div style="display: inline-flex; align-items: center; gap: 0.35rem;">
                        <span class="badge-pill unit-<?= $uColor ?>-pill" id="cardUnitBadge_<?= $userId ?>_<?= $workerId ?>" style="font-size: 0.72rem;"><?= htmlspecialchars($unitVal) ?></span>
                        <?php if ($currentUser && in_array($currentUser['role'], ['admin', 'supervisor']) && !$isRootAdmin): ?>
                            <button type="button" class="btn-unit-transfer-quick" 
                                onclick="quickTransferUserUnit(<?= htmlspecialchars(json_encode($userRecordJson), ENT_QUOTES, 'UTF-8') ?>)" 
                                title="Transfer between Unit 1 &amp; Unit 2">
                                <i class="fa-solid fa-arrow-right-arrow-left"></i>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span class="text-muted"><i class="fa-solid fa-briefcase" style="font-size: 0.7rem;"></i> Role:</span>
                    <span style="color: var(--text-secondary); font-weight: 500; text-align: right;"><?= htmlspecialchars($designation) ?></span>
                </div>
            </div>

            <div style="display: flex; gap: 0.35rem; justify-content: flex-end; align-items: center; margin-top: auto; padding-top: 0.25rem; flex-wrap: wrap;">
                <!-- 1. View Details Button -->
                <button type="button" class="btn-action-view" 
                    onclick="openUserDetailsModal(<?= htmlspecialchars(json_encode($userRecordJson), ENT_QUOTES, 'UTF-8') ?>)" 
                    title="View Full Profile Details">
                    <i class="fa-solid fa-eye"></i> View Details
                </button>

                <!-- 2. Edit Button -->
                <button type="button" class="btn-action-edit" 
                    onclick="openEditUserModal(<?= htmlspecialchars(json_encode($userRecordJson), ENT_QUOTES, 'UTF-8') ?>)" 
                    title="Edit User &amp; Worker Details">
                    <i class="fa-solid fa-pen-to-square"></i> Edit
                </button>

                <!-- 3. Delete Button -->
                <?php if ($isRootAdmin): ?>
                    <button type="button" class="btn-action-delete" style="opacity: 0.35; cursor: not-allowed;" title="Root Administrator account cannot be deleted" disabled>
                        <i class="fa-solid fa-trash-can"></i> Delete
                    </button>
                <?php else: ?>
                    <button type="button" class="btn-action-delete" 
                        onclick="confirmDeleteUser(<?= $userId ?>, <?= $workerId ?>, '<?= htmlspecialchars(addslashes($user['username'])) ?>', '<?= htmlspecialchars(addslashes($displayName)) ?>')" 
                        title="Delete User and Worker Account">
                        <i class="fa-solid fa-trash-can"></i> Delete
                    </button>
                <?php endif; ?>

                <!-- 4. Active | Inactive Button -->
                <button type="button" class="btn-action-toggle <?= $isActive ? 'is-active' : 'is-inactive' ?>"
                    id="cardToggleBtn_<?= $userId ?>_<?= $workerId ?>"
                    onclick="toggleUserStatus(<?= $userId ?>, <?= $workerId ?>, '<?= htmlspecialchars(addslashes($user['username'])) ?>')"
                    title="Toggle Active / Inactive Status">
                    <i class="fa-solid <?= $isActive ? 'fa-toggle-on' : 'fa-toggle-off' ?>" id="cardToggleIcon_<?= $userId ?>_<?= $workerId ?>"></i>
                    <span id="cardToggleText_<?= $userId ?>_<?= $workerId ?>"><?= $isActive ? 'Active' : 'Inactive' ?></span>
                </button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- C. INSPIRING EMPTY STATE -->
    <div id="noUsersEmptyState" style="display: none; text-align: center; padding: 3rem 1.5rem; background: rgba(15,23,42,0.4); border: 1px dashed rgba(255,255,255,0.12); border-radius: var(--radius-lg); margin-top: 0.5rem;">
        <div style="width: 58px; height: 58px; border-radius: 50%; background: rgba(6,182,212,0.1); border: 1px solid rgba(6,182,212,0.25); display: inline-flex; align-items: center; justify-content: center; color: var(--accent-cyan); font-size: 1.6rem; margin-bottom: 0.85rem; box-shadow: 0 0 25px rgba(6,182,212,0.18);">
            <i class="fa-solid fa-users-slash"></i>
        </div>
        <h3 style="color: #fff; font-size: 1.15rem; font-weight: 700; margin: 0 0 0.35rem;" id="emptyStateTitle">No Users Found</h3>
        <p style="color: var(--text-muted); font-size: 0.82rem; max-width: 440px; margin: 0 auto 1.25rem;" id="emptyStateDesc">
            There are currently no workforce or supervisor accounts matching this facility or filter.
        </p>
        <?php if ($currentUser && in_array($currentUser['role'], ['admin', 'supervisor'])): ?>
            <button type="button" class="btn btn-primary btn-sm" onclick="openAddUserModal()" style="display: inline-flex; align-items: center; gap: 0.45rem; padding: 0.45rem 1.15rem; box-shadow: 0 0 20px rgba(6,182,212,0.25);">
                <i class="fa-solid fa-user-plus"></i> <span id="emptyStateBtnText">Add User to This Facility</span>
            </button>
        <?php endif; ?>
    </div>

</div>

<!-- ==========================================
     USER DETAILS PROFILE MODAL
     ========================================== -->
<div id="userDetailsProfileModal" class="modal-overlay" style="display: none; position: fixed; inset: 0; z-index: 9999; background: rgba(0,0,0,0.8); backdrop-filter: blur(8px); align-items: center; justify-content: center; padding: 1rem;">
    <div class="modal-card" style="background: var(--bg-card); border: 1px solid rgba(6,182,212,0.35); border-radius: var(--radius-lg); width: 100%; max-width: 620px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5), 0 0 35px rgba(6,182,212,0.18); overflow: hidden;">
        <!-- Header -->
        <div class="modal-header" style="padding: 1.15rem 1.35rem; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; background: rgba(6,182,212,0.06);">
            <div style="display: flex; align-items: center; gap: 0.85rem;">
                <div id="detailModalAvatar" class="worker-meta-avatar" style="width: 44px; height: 44px; font-size: 1.2rem; background: linear-gradient(135deg, #0891b2, #06b6d4); box-shadow: 0 0 15px rgba(6,182,212,0.25);">
                    U
                </div>
                <div>
                    <h3 id="detailModalName" style="margin: 0; font-size: 1.1rem; font-weight: 700; color: #fff;">User Name</h3>
                    <div style="display: flex; align-items: center; gap: 0.45rem; margin-top: 0.2rem;">
                        <span id="detailModalUsername" style="font-family: monospace; font-size: 0.78rem; color: var(--accent-cyan);">@username</span>
                        <span id="detailModalUnitBadge" class="badge-pill unit-cyan-pill" style="font-size: 0.68rem;">Unit</span>
                        <span id="detailModalStatusBadge" class="status-pill status-active" style="font-size: 0.68rem; padding: 0.1rem 0.45rem;">
                            <span class="status-indicator-dot"></span> <span id="detailModalStatusText">Active</span>
                        </span>
                    </div>
                </div>
            </div>
            <button type="button" id="closeDetailModalBtn" onclick="closeUserDetailsModal()" style="background: none; border: none; color: var(--text-muted); cursor: pointer; font-size: 1.25rem; line-height: 1; padding: 0.25rem;">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <!-- Body -->
        <div class="modal-body-scroll" style="padding: 1.15rem 1.25rem; max-height: 85vh; overflow-y: auto;">
            
            <!-- Compact User Metadata Grid (3 columns) -->
            <div class="detail-info-grid" style="grid-template-columns: repeat(3, 1fr); gap: 0.5rem; margin-bottom: 0.85rem;">
                <div class="detail-info-item" style="padding: 0.5rem 0.65rem;">
                    <div class="detail-info-label" style="font-size: 0.68rem;"><i class="fa-solid fa-phone text-cyan"></i> Phone</div>
                    <div class="detail-info-val" id="detailModalPhone" style="font-size: 0.82rem;">—</div>
                </div>
                <div class="detail-info-item" style="padding: 0.5rem 0.65rem;">
                    <div class="detail-info-label" style="font-size: 0.68rem;"><i class="fa-solid fa-cake-candles text-cyan"></i> Age</div>
                    <div class="detail-info-val" id="detailModalAge" style="font-size: 0.82rem;">—</div>
                </div>
                <div class="detail-info-item" style="padding: 0.5rem 0.65rem;">
                    <div class="detail-info-label" style="font-size: 0.68rem;"><i class="fa-solid fa-briefcase text-cyan"></i> Designation</div>
                    <div class="detail-info-val" id="detailModalDesignation" style="font-size: 0.82rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">—</div>
                </div>
                <div class="detail-info-item" style="padding: 0.5rem 0.65rem;">
                    <div class="detail-info-label" style="font-size: 0.68rem;"><i class="fa-solid fa-shield-halved text-cyan"></i> System Role</div>
                    <div class="detail-info-val" id="detailModalRole" style="font-size: 0.82rem;">—</div>
                </div>
                <div class="detail-info-item" style="padding: 0.5rem 0.65rem;">
                    <div class="detail-info-label" style="font-size: 0.68rem;"><i class="fa-solid fa-user-clock text-cyan"></i> Experience</div>
                    <div class="detail-info-val" id="detailModalExp" style="font-size: 0.82rem;">—</div>
                </div>
                <div class="detail-info-item" style="padding: 0.5rem 0.65rem;">
                    <div class="detail-info-label" style="font-size: 0.68rem;"><i class="fa-solid fa-calendar-check text-cyan"></i> Date Joined</div>
                    <div class="detail-info-val" id="detailModalCreated" style="font-size: 0.82rem;">—</div>
                </div>
            </div>

            <!-- Hidden field for compatibility with pieces query if referenced -->
            <span id="detailModalPieces" style="display: none;">0 pcs</span>

            <!-- =========================================================
                 ADMIN UNIT ASSIGNMENT & EMERGENCY REALLOCATION PANEL
                 ========================================================= -->
            <?php if ($currentUser && in_array($currentUser['role'], ['admin', 'supervisor'])): ?>
            <div id="detailModalUnitTransferSection" class="user-unit-transfer-card" style="margin-bottom: 0.85rem; background: rgba(6,182,212,0.06); border: 1px solid rgba(6,182,212,0.28); border-radius: 8px; padding: 0.75rem 0.85rem;">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.55rem; flex-wrap: wrap; gap: 0.4rem;">
                    <div style="display: flex; align-items: center; gap: 0.45rem;">
                        <i class="fa-solid fa-arrow-right-arrow-left text-cyan" style="font-size: 0.82rem;"></i>
                        <span style="font-size: 0.82rem; font-weight: 700; color: #fff;">Production Unit Management</span>
                        <span id="detailModalCurrentUnitDisplay" class="badge-pill unit-cyan-pill" style="font-size: 0.68rem; padding: 0.08rem 0.45rem;">Unit 1</span>
                    </div>
                    <span style="font-size: 0.7rem; color: var(--text-muted);">
                        <i class="fa-solid fa-bolt text-amber"></i> Emergency Support or Permanent
                    </span>
                </div>

                <!-- Transfer Controls Form -->
                <div style="display: grid; grid-template-columns: 1.2fr 1fr auto; gap: 0.5rem; align-items: flex-end;">
                    <div>
                        <label for="detailModalTargetUnitSelect" style="font-size: 0.68rem; color: var(--text-muted); display: block; margin-bottom: 0.2rem;">Target Unit:</label>
                        <select id="detailModalTargetUnitSelect" class="form-control" style="height: 34px; font-size: 0.78rem; background: rgba(15,23,42,0.85); border-color: rgba(6,182,212,0.35); color: #fff;">
                            <?php foreach ($productionUnits as $pu): ?>
                                <option value="<?= htmlspecialchars($pu['unit_name']) ?>" data-base-title="<?= htmlspecialchars($pu['unit_title']) ?>"><?= htmlspecialchars($pu['unit_title']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="detailModalTransferTypeSelect" style="font-size: 0.68rem; color: var(--text-muted); display: block; margin-bottom: 0.2rem;">Assignment Type:</label>
                        <select id="detailModalTransferTypeSelect" class="form-control" style="height: 34px; font-size: 0.78rem; background: rgba(15,23,42,0.85); border-color: rgba(6,182,212,0.35); color: #fff;">
                            <option value="temporary">Emergency Support (Temporary)</option>
                            <option value="permanent">Permanent Reassignment</option>
                        </select>
                    </div>

                    <div>
                        <button type="button" id="detailModalTransferSubmitBtn" onclick="submitModalUnitTransfer()" class="btn btn-sm" style="height: 34px; font-size: 0.78rem; padding: 0 0.85rem; display: inline-flex; align-items: center; gap: 0.35rem; background: linear-gradient(135deg, #0891b2, #06b6d4); border: none; color: #fff; font-weight: 700; border-radius: 6px; cursor: pointer;">
                            <i class="fa-solid fa-arrow-right-arrow-left"></i> Transfer Unit
                        </button>
                    </div>
                </div>
                <div id="detailModalTransferNotice" style="display: none; margin-top: 0.45rem; font-size: 0.72rem; padding: 0.35rem 0.65rem; border-radius: 6px; background: rgba(16,185,129,0.15); color: #34d399; border: 1px solid rgba(16,185,129,0.3);"></div>
            </div>
            <?php endif; ?>

            <!-- =========================================================
                 FEATURED SECTION: CATEGORY-WISE STITCHED PRODUCTS & BREAKDOWN
                 ========================================================= -->
            <div class="user-detail-category-card" style="margin-bottom: 0.85rem;">
                <!-- Header with Title and Category Count -->
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.75rem; padding-bottom: 0.55rem; border-bottom: 1px solid rgba(255,255,255,0.08);">
                    <div style="display: flex; align-items: center; gap: 0.55rem;">
                        <div style="width: 32px; height: 32px; border-radius: 8px; background: rgba(6,182,212,0.18); border: 1px solid rgba(6,182,212,0.35); display: flex; align-items: center; justify-content: center; color: var(--accent-cyan); font-size: 0.95rem;">
                            <i class="fa-solid fa-layer-group"></i>
                        </div>
                        <div>
                            <h4 style="margin: 0; font-size: 0.95rem; font-weight: 700; color: #fff; letter-spacing: -0.01em;">Category Stitched Products</h4>
                            <p style="margin: 0.1rem 0 0; font-size: 0.72rem; color: var(--text-muted);">Volume and piece distribution across garment categories</p>
                        </div>
                    </div>
                    <span id="detailModalCategoryCountBadge" class="badge-pill unit-cyan-pill" style="font-size: 0.72rem; padding: 0.2rem 0.55rem; font-weight: 700;">
                        0 Categories
                    </span>
                </div>

                <!-- Grand Total & Unit 1 / Unit 2 Overall Stitched Cards -->
                <div id="detailModalOverallSummaryCards" style="display: grid; grid-template-columns: 1.15fr 1fr 1fr; gap: 0.55rem; margin-bottom: 0.65rem;">
                    <!-- Card 1: All Units Combined Grand Total -->
                    <div style="background: linear-gradient(135deg, rgba(6,182,212,0.14), rgba(59,130,246,0.09)); border: 1px solid rgba(6,182,212,0.35); border-radius: 8px; padding: 0.65rem 0.8rem; display: flex; flex-direction: column; justify-content: space-between;">
                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 0.25rem;">
                            <span style="font-size: 0.68rem; color: var(--accent-cyan); font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; display: flex; align-items: center; gap: 0.35rem; white-space: nowrap;">
                                <i class="fa-solid fa-cubes-stacked"></i> Grand Total
                            </span>
                            <span class="badge-pill unit-cyan-pill" style="font-size: 0.62rem; padding: 0.08rem 0.35rem; font-weight: 700;">
                                Total
                            </span>
                        </div>
                        <div id="detailModalGrandPieces" style="font-size: 1.38rem; font-weight: 800; color: #38bdf8; margin: 0.15rem 0;">
                            0 pcs
                        </div>
                        <div id="detailModalActiveCatSummary" style="font-size: 0.68rem; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                            0 Categories • 0 records
                        </div>
                        <span id="detailModalShiftsCount" style="display: none;">0</span>
                    </div>

                    <!-- Card 2: Production Unit 1 Overall Stitched -->
                    <div style="background: linear-gradient(135deg, rgba(6,182,212,0.1), rgba(16,185,129,0.06)); border: 1px solid rgba(6,182,212,0.3); border-radius: 8px; padding: 0.65rem 0.8rem; display: flex; flex-direction: column; justify-content: space-between;">
                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 0.25rem;">
                            <span class="badge-pill unit-cyan-pill" style="font-size: 0.62rem; padding: 0.08rem 0.42rem; font-weight: 700; display: inline-flex; align-items: center; gap: 0.25rem;">
                                <i class="fa-solid fa-industry"></i> Unit 1
                            </span>
                            <span id="detailModalUnit1PctBadge" class="badge-pill" style="font-size: 0.62rem; padding: 0.08rem 0.35rem; background: rgba(6,182,212,0.15); color: #22d3ee; border: 1px solid rgba(6,182,212,0.3); font-weight: 700;">
                                0%
                            </span>
                        </div>
                        <div id="detailModalUnit1Pieces" style="font-size: 1.38rem; font-weight: 800; color: #22d3ee; margin: 0.15rem 0;">
                            0 pcs
                        </div>
                        <div style="font-size: 0.68rem; color: var(--text-muted); display: flex; align-items: center; gap: 0.3rem; white-space: nowrap;">
                            <i class="fa-solid fa-chart-simple" style="font-size: 0.62rem; color: #22d3ee;"></i> Unit 1 Overall Stitched
                        </div>
                    </div>

                    <!-- Card 3: Production Unit 2 Overall Stitched -->
                    <div style="background: linear-gradient(135deg, rgba(236,72,153,0.1), rgba(244,63,94,0.06)); border: 1px solid rgba(236,72,153,0.3); border-radius: 8px; padding: 0.65rem 0.8rem; display: flex; flex-direction: column; justify-content: space-between;">
                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 0.25rem;">
                            <span class="badge-pill unit-pink-pill" style="font-size: 0.62rem; padding: 0.08rem 0.42rem; font-weight: 700; display: inline-flex; align-items: center; gap: 0.25rem;">
                                <i class="fa-solid fa-warehouse"></i> Unit 2
                            </span>
                            <span id="detailModalUnit2PctBadge" class="badge-pill" style="font-size: 0.62rem; padding: 0.08rem 0.35rem; background: rgba(236,72,153,0.15); color: #f472b6; border: 1px solid rgba(236,72,153,0.3); font-weight: 700;">
                                0%
                            </span>
                        </div>
                        <div id="detailModalUnit2Pieces" style="font-size: 1.38rem; font-weight: 800; color: #f472b6; margin: 0.15rem 0;">
                            0 pcs
                        </div>
                        <div style="font-size: 0.68rem; color: var(--text-muted); display: flex; align-items: center; gap: 0.3rem; white-space: nowrap;">
                            <i class="fa-solid fa-chart-simple" style="font-size: 0.62rem; color: #f472b6;"></i> Unit 2 Overall Stitched
                        </div>
                    </div>
                </div>

                <!-- Multi-Unit Visual Output Ratio Bar -->
                <div id="detailModalUnitRatioBarWrapper" style="background: rgba(0,0,0,0.22); border: 1px solid rgba(255,255,255,0.06); border-radius: 8px; padding: 0.45rem 0.75rem; margin-bottom: 0.75rem;">
                    <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.68rem; margin-bottom: 0.3rem;">
                        <span style="color: var(--text-secondary); font-weight: 600;"><i class="fa-solid fa-chart-pie text-cyan"></i> Facility Output Distribution</span>
                        <span id="detailModalUnitRatioText" style="color: var(--text-muted); font-size: 0.68rem; font-weight: 500;">Unit 1: 0 pcs • Unit 2: 0 pcs</span>
                    </div>
                    <div style="width: 100%; height: 7px; background: rgba(255,255,255,0.08); border-radius: 999px; overflow: hidden; display: flex;" id="detailModalUnitRatioBar">
                        <div id="detailModalRatioUnit1" style="width: 50%; height: 100%; background: linear-gradient(90deg, #06b6d4, #3b82f6); transition: width 0.4s ease;"></div>
                        <div id="detailModalRatioUnit2" style="width: 50%; height: 100%; background: linear-gradient(90deg, #ec4899, #f43f5e); transition: width 0.4s ease;"></div>
                    </div>
                </div>

                <!-- Category Breakdown Rows Container -->
                <div id="detailModalCategoryBreakdownContainer" style="display: flex; flex-direction: column; gap: 0.45rem; max-height: 280px; overflow-y: auto; padding-right: 0.25rem;">
                    <!-- Dynamically populated -->
                </div>
            </div>

            <!-- Factory Location & Skills Footer Strip -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; margin-bottom: 0.85rem;">
                <div style="background: rgba(0,0,0,0.22); border: 1px solid rgba(255,255,255,0.06); border-radius: 8px; padding: 0.5rem 0.75rem;">
                    <div class="detail-info-label" style="font-size: 0.68rem; margin-bottom: 0.15rem;"><i class="fa-solid fa-location-dot text-cyan"></i> Factory Address</div>
                    <div id="detailModalAddress" style="font-size: 0.78rem; font-weight: 500; color: #cbd5e1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">—</div>
                </div>
                <div style="background: rgba(0,0,0,0.22); border: 1px solid rgba(255,255,255,0.06); border-radius: 8px; padding: 0.5rem 0.75rem;">
                    <div class="detail-info-label" style="font-size: 0.68rem; margin-bottom: 0.15rem;"><i class="fa-solid fa-star text-amber"></i> Special Skills</div>
                    <div id="detailModalSkills" style="font-size: 0.78rem; font-weight: 500; color: #cbd5e1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">Standard Production Line Operations</div>
                </div>
            </div>

            <!-- Modal Footer Actions -->
            <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid var(--border-color); padding-top: 1rem; flex-wrap: wrap; gap: 0.65rem;">
                <div id="detailModalWorkstationLinkContainer">
                    <a href="#" id="detailModalWorkstationLink" class="btn btn-outline btn-sm" style="font-size: 0.78rem; display: inline-flex; align-items: center; gap: 0.35rem; border-color: rgba(6,182,212,0.4); color: var(--accent-cyan);">
                        <i class="fa-solid fa-shirt"></i> Open Stitches Workstation
                    </a>
                </div>
                <div style="display: flex; gap: 0.5rem; align-items: center; margin-left: auto;">
                    <button type="button" class="btn btn-outline btn-sm" onclick="closeUserDetailsModal()">Close</button>
                    <?php if ($currentUser && in_array($currentUser['role'], ['admin', 'supervisor'])): ?>
                        <button type="button" class="btn btn-outline btn-sm" style="color: #c084fc; border-color: rgba(168,85,247,0.4);" onclick="openEditFromProfileModal()">
                            <i class="fa-solid fa-pen-to-square"></i> Edit
                        </button>
                    <?php endif; ?>
                    <button type="button" id="detailModalToggleStatusBtn" class="btn btn-sm" style="background: rgba(16,185,129,0.2); border: 1px solid rgba(16,185,129,0.4); color: #34d399; font-weight: 600;">
                        <i class="fa-solid fa-toggle-on"></i> Toggle Status
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ==========================================
     4B. QUICK PRODUCTION UNIT TRANSFER MODAL
     ========================================== -->
<?php if ($currentUser && in_array($currentUser['role'], ['admin', 'supervisor'])): ?>
<div id="quickUnitTransferModal" class="modal-overlay" style="display: none; position: fixed; inset: 0; z-index: 9999; background: rgba(0,0,0,0.78); backdrop-filter: blur(8px); align-items: center; justify-content: center; padding: 1rem;">
    <div class="modal-card" style="background: var(--bg-card); border: 1px solid rgba(6,182,212,0.35); border-radius: var(--radius-lg); width: 100%; max-width: 480px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5), 0 0 35px rgba(6,182,212,0.18); overflow: hidden;">
        <div class="modal-header" style="padding: 1.15rem 1.25rem; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; background: rgba(6,182,212,0.06);">
            <div style="display: flex; align-items: center; gap: 0.65rem;">
                <div style="width: 36px; height: 36px; border-radius: 10px; background: rgba(6,182,212,0.18); display: flex; align-items: center; justify-content: center; color: var(--accent-cyan); font-size: 1.05rem;">
                    <i class="fa-solid fa-arrow-right-arrow-left"></i>
                </div>
                <div>
                    <h3 style="margin: 0; font-size: 1.05rem; font-weight: 700; color: #fff;">Production Unit Reallocation</h3>
                    <p style="margin: 0.15rem 0 0; font-size: 0.74rem; color: var(--text-muted);">Emergency piece support or permanent unit transfer</p>
                </div>
            </div>
            <button type="button" onclick="closeQuickTransferModal()" style="background: none; border: none; color: var(--text-muted); cursor: pointer; font-size: 1.1rem; line-height: 1; padding: 0.25rem;">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form id="quickUnitTransferForm" style="padding: 1.25rem;">
            <input type="hidden" id="quickTransferUserId" value="0">
            <input type="hidden" id="quickTransferWorkerId" value="0">
            <input type="hidden" id="quickTransferUsername" value="">

            <!-- Target User Header Info Card -->
            <div style="background: rgba(255,255,255,0.03); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 0.75rem 0.95rem; margin-bottom: 1.15rem; display: flex; align-items: center; justify-content: space-between; gap: 0.75rem;">
                <div style="display: flex; align-items: center; gap: 0.75rem; min-width: 0;">
                    <div class="worker-meta-avatar" id="quickTransferAvatar" style="width: 38px; height: 38px; font-size: 1rem; background: var(--accent-cyan); color: #000; flex-shrink: 0;">
                        U
                    </div>
                    <div style="min-width: 0;">
                        <div id="quickTransferUserName" style="font-weight: 700; color: #fff; font-size: 0.95rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">User Name</div>
                        <div id="quickTransferUserLogin" style="font-size: 0.75rem; color: var(--text-muted); font-family: monospace;">@username</div>
                    </div>
                </div>
                <div style="text-align: right; flex-shrink: 0;">
                    <div style="font-size: 0.68rem; color: var(--text-muted); margin-bottom: 0.2rem;">Current Unit:</div>
                    <span id="quickTransferCurrentUnitBadge" class="badge-pill unit-cyan-pill" style="font-size: 0.75rem;">Unit 1</span>
                </div>
            </div>

            <!-- Target Production Unit -->
            <div class="form-group" style="margin-bottom: 1rem;">
                <label class="form-label" for="quickTransferTargetUnitSelect">
                    <i class="fa-solid fa-building text-cyan"></i> Transfer To Production Unit *
                </label>
                <select id="quickTransferTargetUnitSelect" class="form-control" style="height: 42px; font-weight: 600;">
                    <?php foreach ($productionUnits as $pu): ?>
                        <option value="<?= htmlspecialchars($pu['unit_name']) ?>" data-base-title="<?= htmlspecialchars($pu['unit_title']) ?>"><?= htmlspecialchars($pu['unit_title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Transfer Mode -->
            <div class="form-group" style="margin-bottom: 1rem;">
                <label class="form-label" for="quickTransferTypeSelect">
                    <i class="fa-solid fa-clock-rotate-left text-cyan"></i> Allocation Type *
                </label>
                <select id="quickTransferTypeSelect" class="form-control" style="height: 42px; font-weight: 600;">
                    <option value="temporary">Emergency Support (Temporary - for high piece demand)</option>
                    <option value="permanent">Permanent Unit Assignment</option>
                </select>
            </div>

            <!-- Reason or Order Reference -->
            <div class="form-group" style="margin-bottom: 1.25rem;">
                <label class="form-label" for="quickTransferNotes">
                    <i class="fa-solid fa-clipboard-list text-cyan"></i> Emergency Notes / Order Ref (Optional)
                </label>
                <input type="text" id="quickTransferNotes" class="form-control" placeholder="e.g. Urgent piece quota support / Denim production" style="height: 40px; font-size: 0.82rem;">
            </div>

            <!-- Zero loss info callout -->
            <div style="background: rgba(16,185,129,0.08); border: 1px solid rgba(16,185,129,0.25); border-radius: var(--radius-md); padding: 0.65rem 0.85rem; margin-bottom: 1.25rem; display: flex; gap: 0.55rem; align-items: flex-start;">
                <i class="fa-solid fa-circle-check text-emerald" style="font-size: 0.9rem; margin-top: 0.1rem;"></i>
                <div style="font-size: 0.74rem; color: #cbd5e1; line-height: 1.45;">
                    <strong style="color: #34d399;">Order-Wise Piece Tracking Preserved:</strong> All past stitched products and category volumes in both units remain stored under this user's profile. You can transfer back to Unit 1 anytime.
                </div>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; border-top: 1px solid var(--border-color); padding-top: 1rem;">
                <button type="button" class="btn btn-outline" onclick="closeQuickTransferModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" id="quickTransferSubmitBtn" style="background: linear-gradient(135deg, #0891b2, #06b6d4); border: none; font-weight: 700;">
                    <i class="fa-solid fa-arrow-right-arrow-left"></i> Confirm Transfer
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

</div>

<!-- ==========================================
     5. QUICK ADD USER MODAL
     ========================================== -->
<?php if ($currentUser && in_array($currentUser['role'], ['admin', 'supervisor'])): ?>
<div id="addUserModal" class="modal-overlay" style="display: none; position: fixed; inset: 0; z-index: 9999; background: rgba(0,0,0,0.78); backdrop-filter: blur(8px); align-items: center; justify-content: center; padding: 1rem;">
    <div class="modal-card" style="background: var(--bg-card); border: 1px solid rgba(6,182,212,0.35); border-radius: var(--radius-lg); width: 100%; max-width: 500px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5), 0 0 35px rgba(6,182,212,0.15); overflow: hidden;">
        <div class="modal-header" style="padding: 1.15rem 1.25rem; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; background: rgba(6,182,212,0.06);">
            <div style="display: flex; align-items: center; gap: 0.65rem;">
                <div style="width: 36px; height: 36px; border-radius: 10px; background: rgba(6,182,212,0.15); display: flex; align-items: center; justify-content: center; color: var(--accent-cyan); font-size: 1.1rem;">
                    <i class="fa-solid fa-user-plus"></i>
                </div>
                <div>
                    <h3 style="margin: 0; font-size: 1.05rem; font-weight: 700; color: #fff;">Register Factory User</h3>
                    <p style="margin: 0.15rem 0 0; font-size: 0.75rem; color: var(--text-muted);">Quickly create a login and assign facility credentials</p>
                </div>
            </div>
            <button type="button" id="closeAddUserModalBtn" style="background: none; border: none; color: var(--text-muted); cursor: pointer; font-size: 1.1rem; line-height: 1; padding: 0.25rem;">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form id="addUserForm" style="padding: 1.25rem;">
            <div class="form-group" style="margin-bottom: 1rem;">
                <label class="form-label" for="addUserName"><i class="fa-solid fa-signature text-cyan"></i> Full Name *</label>
                <input type="text" id="addUserName" name="name" class="form-control" required placeholder="e.g. Rajesh Kumar" style="height: 42px;">
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.85rem; margin-bottom: 1rem;">
                <div class="form-group">
                    <label class="form-label" for="addUserRole"><i class="fa-solid fa-shield-halved text-cyan"></i> System Role</label>
                    <select id="addUserRole" name="role" class="form-control" style="height: 42px;">
                        <option value="operator">Operator (Workstation)</option>
                        <option value="supervisor">Supervisor (Shift Lead)</option>
                        <option value="admin">Administrator</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="addUserUnit"><i class="fa-solid fa-building text-cyan"></i> Assigned Unit</label>
                    <select id="addUserUnit" name="unit" class="form-control" style="height: 42px;">
                        <option value="All">All Factory Units</option>
                        <?php foreach ($productionUnits as $pu): ?>
                            <option value="<?= htmlspecialchars($pu['unit_name']) ?>"><?= htmlspecialchars($pu['unit_title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.85rem; margin-bottom: 1rem;">
                <div class="form-group">
                    <label class="form-label" for="addUserUsername"><i class="fa-solid fa-id-badge text-cyan"></i> Username / ID</label>
                    <input type="text" id="addUserUsername" name="username" class="form-control" placeholder="Auto-generated if blank" style="height: 42px;">
                </div>

                <div class="form-group">
                    <label class="form-label" for="addUserPhone"><i class="fa-solid fa-phone text-cyan"></i> Contact Phone</label>
                    <input type="tel" id="addUserPhone" name="phone" class="form-control" placeholder="e.g. 9876543210" style="height: 42px;">
                </div>
            </div>

            <div class="form-group" style="margin-bottom: 1.25rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.35rem;">
                    <label class="form-label" for="addUserPassword" style="margin-bottom: 0;">
                        <i class="fa-solid fa-key text-cyan"></i> Login Password (4-6 chars)
                    </label>
                    <button type="button" class="btn btn-outline btn-xs" onclick="generateAddUserPassword()" style="font-size: 0.72rem; padding: 0.2rem 0.5rem; color: var(--accent-cyan);">
                        <i class="fa-solid fa-wand-magic-sparkles"></i> Auto-Generate
                    </button>
                </div>
                <input type="text" id="addUserPassword" name="password" class="form-control" value="1234" minlength="4" maxlength="6" required style="height: 42px;">
                <div style="font-size: 0.74rem; color: var(--text-muted); margin-top: 0.25rem;">Default is 1234. User can change it upon signing in.</div>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.65rem; border-top: 1px solid var(--border-color); padding-top: 1rem;">
                <button type="button" class="btn btn-outline btn-sm" id="cancelAddUserModalBtn">Cancel</button>
                <button type="submit" class="btn btn-primary btn-sm" id="submitAddUserBtn" style="padding: 0.45rem 1.15rem;">
                    <i class="fa-solid fa-user-plus"></i> Save User Account
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ==========================================
     6. EDIT USER & WORKER PROFILE MODAL
     ========================================== -->
<?php if ($currentUser && in_array($currentUser['role'], ['admin', 'supervisor'])): ?>
<div id="editUserModal" class="modal-overlay" style="display: none; position: fixed; inset: 0; z-index: 9999; background: rgba(0,0,0,0.78); backdrop-filter: blur(8px); align-items: center; justify-content: center; padding: 1rem;">
    <div class="modal-card" style="background: var(--bg-card); border: 1px solid rgba(168,85,247,0.35); border-radius: var(--radius-lg); width: 100%; max-width: 530px; max-height: 90vh; overflow-y: auto; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5), 0 0 35px rgba(168,85,247,0.15);">
        <div class="modal-header" style="padding: 1.15rem 1.25rem; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; background: rgba(168,85,247,0.06); position: sticky; top: 0; z-index: 10; backdrop-filter: blur(8px);">
            <div style="display: flex; align-items: center; gap: 0.65rem;">
                <div style="width: 36px; height: 36px; border-radius: 10px; background: rgba(168,85,247,0.18); display: flex; align-items: center; justify-content: center; color: #c084fc; font-size: 1.05rem;">
                    <i class="fa-solid fa-user-pen"></i>
                </div>
                <div>
                    <h3 style="margin: 0; font-size: 1.05rem; font-weight: 700; color: #fff;">Edit Profile &amp; Credentials</h3>
                    <p style="margin: 0.15rem 0 0; font-size: 0.75rem; color: var(--text-muted);">Update personal details, contact, role or credentials</p>
                </div>
            </div>
            <button type="button" id="closeEditModalBtn" style="background: none; border: none; color: var(--text-muted); cursor: pointer; font-size: 1.1rem; line-height: 1; padding: 0.25rem;">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form id="editUserForm" style="padding: 1.25rem;">
            <input type="hidden" id="editUserId" name="user_id">
            <input type="hidden" id="editWorkerId" name="worker_id">
            <input type="hidden" id="editUsername" name="username">

            <!-- Target User Header Info Card -->
            <div style="background: rgba(255,255,255,0.03); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 0.75rem 0.95rem; margin-bottom: 1.15rem; display: flex; align-items: center; gap: 0.75rem;">
                <div class="worker-meta-avatar" id="modalTargetAvatar" style="width: 40px; height: 40px; font-size: 1.1rem; background: linear-gradient(135deg, #a855f7, #6366f1); color: #fff; font-weight: 700;">
                    U
                </div>
                <div style="min-width: 0; flex: 1;">
                    <div id="modalTargetUserName" style="font-weight: 700; color: #fff; font-size: 0.95rem;">Target User</div>
                    <div style="display: flex; align-items: center; gap: 0.4rem; margin-top: 0.15rem;">
                        <span id="modalTargetUserLogin" style="font-size: 0.75rem; color: var(--accent-cyan); font-family: monospace;">@username</span>
                        <span id="modalTargetWorkerBadge" class="badge-pill" style="font-size: 0.7rem; padding: 0.05rem 0.35rem; background: rgba(255,255,255,0.06); color: var(--text-muted);">Worker Profile</span>
                    </div>
                </div>
            </div>

            <!-- Full Name -->
            <div class="form-group" style="margin-bottom: 1rem;">
                <label class="form-label" for="editFullName">
                    <i class="fa-solid fa-user text-cyan"></i> Full Name *
                </label>
                <input type="text" id="editFullName" name="name" class="form-control" required style="height: 42px; font-weight: 600;">
            </div>

            <!-- 2-column: Mobile & Alt Mobile -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-bottom: 1rem;">
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label" for="editMobileNum">
                        <i class="fa-solid fa-phone text-cyan"></i> Phone Number
                    </label>
                    <input type="tel" id="editMobileNum" name="phone" class="form-control" maxlength="10" inputmode="numeric" placeholder="10-digit mobile" style="height: 42px;">
                    <div style="font-size: 0.72rem; color: var(--text-muted); margin-top: 0.2rem;">Strict 10-digit number</div>
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label" for="editAltMobileNum">
                        <i class="fa-solid fa-phone-volume text-cyan"></i> Alternate Phone
                    </label>
                    <input type="tel" id="editAltMobileNum" name="alt_phone" class="form-control" maxlength="10" inputmode="numeric" placeholder="Optional 10-digit" style="height: 42px;">
                    <div style="font-size: 0.72rem; color: var(--text-muted); margin-top: 0.2rem;">Optional emergency</div>
                </div>
            </div>

            <!-- 2-column: Age & Designation -->
            <div style="display: grid; grid-template-columns: 1fr 1.6fr; gap: 0.75rem; margin-bottom: 1rem;">
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label" for="editAge">
                        <i class="fa-solid fa-cake-candles text-cyan"></i> Age
                    </label>
                    <input type="number" id="editAge" name="age" min="16" max="99" class="form-control" placeholder="e.g. 25" style="height: 42px;">
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label" for="editDesignation">
                        <i class="fa-solid fa-briefcase text-cyan"></i> Designation / Role
                    </label>
                    <input type="text" id="editDesignation" name="designation" class="form-control" placeholder="e.g. Master Tailor" style="height: 42px;">
                </div>
            </div>

            <!-- 2-column: Unit & System Role -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-bottom: 1rem;">
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label" for="editUserUnitSelect">
                        <i class="fa-solid fa-building text-cyan"></i> Factory Unit
                    </label>
                    <select id="editUserUnitSelect" name="unit" class="form-control" style="height: 42px; font-weight: 600;">
                        <option value="All">All Factory Units</option>
                        <?php foreach ($productionUnits as $pu): ?>
                            <option value="<?= htmlspecialchars($pu['unit_name']) ?>"><?= htmlspecialchars($pu['unit_title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label" for="editUserRoleSelect">
                        <i class="fa-solid fa-shield-halved text-cyan"></i> System Role
                    </label>
                    <select id="editUserRoleSelect" name="role" class="form-control" style="height: 42px; font-weight: 600;">
                        <option value="operator">Operator (Workstation)</option>
                        <option value="supervisor">Supervisor</option>
                        <option value="admin">Administrator</option>
                    </select>
                </div>
            </div>

            <!-- Password Reset -->
            <div class="form-group" style="margin-bottom: 1.15rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.45rem;">
                    <label class="form-label" for="editUserNewPassword" style="margin-bottom: 0;">
                        <i class="fa-solid fa-key text-cyan"></i> Reset Password (Optional)
                    </label>
                    <button type="button" class="btn btn-outline btn-xs" onclick="generateEditModalPassword()" style="font-size: 0.72rem; padding: 0.2rem 0.5rem; color: var(--accent-cyan);">
                        <i class="fa-solid fa-wand-magic-sparkles"></i> Auto-Generate
                    </button>
                </div>
                <input type="text" id="editUserNewPassword" name="password" class="form-control" placeholder="Leave blank to keep current password (4-6 chars)" minlength="4" maxlength="6" autocomplete="new-password" style="height: 42px;" oninput="validateEditPassword(this.value)">
                <div id="editPassCounter" style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.35rem;">
                    Only enter if you wish to reset this user's password (must be 4 to 6 characters).
                </div>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.5rem; border-top: 1px solid var(--border-color); padding-top: 1rem;">
                <button type="button" class="btn btn-outline" id="cancelEditModalBtn">Cancel</button>
                <button type="submit" class="btn btn-primary" id="saveEditUserBtn" style="background: linear-gradient(135deg, #9333ea, #a855f7); border: none; font-weight: 700; box-shadow: 0 0 15px rgba(168,85,247,0.3);">
                    <i class="fa-solid fa-check"></i> Save Changes
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ==========================================
     7. CLEAR SYSTEM DATA MODAL
     ========================================== -->
<?php if ($currentUser && $currentUser['role'] === 'admin'): ?>
<div id="clearDataModal" class="modal-overlay" style="display: none; position: fixed; inset: 0; z-index: 9999; background: rgba(0,0,0,0.78); backdrop-filter: blur(6px); align-items: center; justify-content: center; padding: 1rem;">
    <div class="modal-card" style="background: var(--bg-card); border: 1px solid rgba(244,63,94,0.35); border-radius: var(--radius-lg); width: 100%; max-width: 530px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5), 0 0 35px rgba(244,63,94,0.15); overflow: hidden;">
        <div class="modal-header" style="padding: 1.15rem 1.25rem; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; background: rgba(244,63,94,0.08);">
            <div style="display: flex; align-items: center; gap: 0.65rem;">
                <div style="width: 34px; height: 34px; border-radius: 50%; background: rgba(244,63,94,0.18); display: flex; align-items: center; justify-content: center; color: #f43f5e; font-size: 1rem;">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                </div>
                <div>
                    <h3 style="margin: 0; font-size: 1.05rem; font-weight: 700; color: #fff;">Clear System Data</h3>
                    <p style="margin: 0.15rem 0 0; font-size: 0.75rem; color: var(--text-muted);">Safely reset test piece logs, workers, or accounts with automatic backup</p>
                </div>
            </div>
            <button type="button" id="closeClearModalBtn" style="background: none; border: none; color: var(--text-muted); cursor: pointer; font-size: 1.1rem; line-height: 1; padding: 0.25rem;">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form id="clearDataForm" style="padding: 1.25rem;">
            <div style="background: rgba(6,182,212,0.08); border: 1px solid rgba(6,182,212,0.25); border-radius: var(--radius-md); padding: 0.75rem 0.95rem; margin-bottom: 1.25rem; display: flex; gap: 0.65rem; align-items: flex-start;">
                <i class="fa-solid fa-shield-halved text-cyan" style="font-size: 1rem; margin-top: 0.15rem;"></i>
                <div style="font-size: 0.78rem; color: var(--text-secondary); line-height: 1.45;">
                    <strong style="color: var(--accent-cyan);">Zero Data Risk Guarantee:</strong> An automatic JSON backup of all existing database records will be saved to your project directory before any data is cleared.
                </div>
            </div>

            <label class="form-label" style="font-size: 0.82rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.65rem; display: block;">
                Select Clear Mode:
            </label>

            <!-- Option 1: Logs Only -->
            <label style="display: flex; align-items: flex-start; gap: 0.75rem; padding: 0.75rem 0.85rem; border: 1px solid var(--border-color); border-radius: var(--radius-md); margin-bottom: 0.65rem; cursor: pointer; background: rgba(255,255,255,0.02); transition: all 0.15s ease;" class="clear-option-label">
                <input type="radio" name="clear_mode" value="logs_only" checked style="margin-top: 0.2rem; accent-color: #06b6d4;">
                <div>
                    <div style="font-weight: 700; font-size: 0.85rem; color: #fff;">Clear Piece Output Logs Only (Recommended)</div>
                    <div style="font-size: 0.74rem; color: var(--text-muted); margin-top: 0.15rem;">
                        Wipes all production piece logs and resets output counts to 0 pcs. Keeps worker profiles and supervisor logins intact.
                    </div>
                </div>
            </label>

            <!-- Option 2: Workers & Logs -->
            <label style="display: flex; align-items: flex-start; gap: 0.75rem; padding: 0.75rem 0.85rem; border: 1px solid var(--border-color); border-radius: var(--radius-md); margin-bottom: 0.65rem; cursor: pointer; background: rgba(255,255,255,0.02); transition: all 0.15s ease;" class="clear-option-label">
                <input type="radio" name="clear_mode" value="workers_and_logs" style="margin-top: 0.2rem; accent-color: #f43f5e;">
                <div>
                    <div style="font-weight: 700; font-size: 0.85rem; color: #fff;">Clear Test Workers &amp; Production Logs</div>
                    <div style="font-size: 0.74rem; color: var(--text-muted); margin-top: 0.15rem;">
                        Wipes test workers and operator logins. Keeps Administrator and Shift Supervisors.
                    </div>
                </div>
            </label>

            <!-- Option 3: Full Reset -->
            <label style="display: flex; align-items: flex-start; gap: 0.75rem; padding: 0.75rem 0.85rem; border: 1px solid var(--border-color); border-radius: var(--radius-md); margin-bottom: 1.15rem; cursor: pointer; background: rgba(255,255,255,0.02); transition: all 0.15s ease;" class="clear-option-label">
                <input type="radio" name="clear_mode" value="full_reset" style="margin-top: 0.2rem; accent-color: #f43f5e;">
                <div>
                    <div style="font-weight: 700; font-size: 0.85rem; color: #f43f5e;">Complete Factory Reset</div>
                    <div style="font-size: 0.74rem; color: var(--text-muted); margin-top: 0.15rem;">
                        Wipes all workers, logs, and non-admin logins. Only your primary Administrator (admin) and factory units remain.
                    </div>
                </div>
            </label>

            <div style="display: flex; justify-content: flex-end; gap: 0.65rem; border-top: 1px solid var(--border-color); padding-top: 1rem;">
                <button type="button" class="btn btn-outline btn-sm" id="cancelClearModalBtn">Cancel</button>
                <button type="submit" class="btn btn-sm" id="confirmClearBtn" style="background: #e11d48; color: #fff; font-weight: 700; border: none; padding: 0.45rem 1rem;">
                    <i class="fa-solid fa-trash-can"></i> Confirm &amp; Clear Data
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ==========================================
     8. JAVASCRIPT CONTROLLERS
     ========================================== -->
<script>
window.userRecordsMap = <?= json_encode($allUserRecordsJson ?? []) ?> || {};
document.addEventListener('DOMContentLoaded', () => {
    // DOM Elements
    const searchInput = document.getElementById('userTableSearch');
    const statusFilter = document.getElementById('userStatusFilter');
    const roleChips = document.querySelectorAll('.role-chip');
    const unitPills = document.querySelectorAll('.unit-tab-pill');
    const resetBtn = document.getElementById('quickResetFiltersBtn');
    const filterSummary = document.getElementById('activeFilterSummary');

    // Records
    const userRecords = document.querySelectorAll('.user-record');
    const tableContainer = document.getElementById('tableViewContainer');
    const cardsContainer = document.getElementById('cardsViewContainer');
    const emptyState = document.getElementById('noUsersEmptyState');
    const emptyTitle = document.getElementById('emptyStateTitle');
    const emptyDesc = document.getElementById('emptyStateDesc');
    const emptyBtnText = document.getElementById('emptyStateBtnText');

    // Count Elements
    const totalUsersBadge = document.getElementById('totalUsersBadge');
    const chipTotal = document.getElementById('chipTotalUsers');
    const chipAdmins = document.getElementById('chipAdminCount');
    const chipSups = document.getElementById('chipSupervisorCount');
    const chipOps = document.getElementById('chipOperatorCount');

    // View Mode Toggle
    const viewTableBtn = document.getElementById('viewModeTableBtn');
    const viewCardsBtn = document.getElementById('viewModeCardsBtn');

    let currentSelectedUnit = "<?= htmlspecialchars($requestedUnit) ?>";
    let currentSelectedRole = "";
    let currentViewMode = localStorage.getItem('fabriq_user_view_mode') || 'table';

    // Set initial view mode
    setViewMode(currentViewMode);

    function setViewMode(mode) {
        currentViewMode = mode;
        localStorage.setItem('fabriq_user_view_mode', mode);
        if (mode === 'cards') {
            viewCardsBtn.classList.add('active');
            viewTableBtn.classList.remove('active');
            if (cardsContainer) cardsContainer.style.display = 'grid';
            if (tableContainer) tableContainer.style.display = 'none';
        } else {
            viewTableBtn.classList.add('active');
            viewCardsBtn.classList.remove('active');
            if (tableContainer) tableContainer.style.display = 'block';
            if (cardsContainer) cardsContainer.style.display = 'none';
        }
    }

    if (viewTableBtn) viewTableBtn.addEventListener('click', () => setViewMode('table'));
    if (viewCardsBtn) viewCardsBtn.addEventListener('click', () => setViewMode('cards'));

    // Unit Navigation
    window.selectUnit = function(unitName, updateUrl = true) {
        currentSelectedUnit = unitName;

        unitPills.forEach(pill => {
            const pUnit = pill.getAttribute('data-unit') || '';
            if (pUnit === unitName) {
                pill.classList.add('active');
            } else {
                pill.classList.remove('active');
            }
        });

        if (updateUrl) {
            const url = new URL(window.location);
            if (unitName) {
                url.searchParams.set('unit', unitName);
            } else {
                url.searchParams.delete('unit');
            }
            window.history.pushState({ unit: unitName }, '', url);
        }

        applyFilters();
    };

    unitPills.forEach(pill => {
        pill.addEventListener('click', function(e) {
            e.preventDefault();
            const u = this.getAttribute('data-unit') || '';
            selectUnit(u, true);
        });
    });

    // Role Filter Chips
    roleChips.forEach(chip => {
        chip.addEventListener('click', function() {
            roleChips.forEach(c => c.classList.remove('active'));
            this.classList.add('active');
            currentSelectedRole = this.getAttribute('data-role') || '';
            applyFilters();
        });
    });

    // Search and Status inputs
    if (searchInput) searchInput.addEventListener('input', applyFilters);
    if (statusFilter) statusFilter.addEventListener('change', applyFilters);

    if (resetBtn) {
        resetBtn.addEventListener('click', () => {
            if (searchInput) searchInput.value = '';
            if (statusFilter) statusFilter.value = '';
            roleChips.forEach(c => c.classList.remove('active'));
            const allChip = document.getElementById('roleChipAll');
            if (allChip) allChip.classList.add('active');
            currentSelectedRole = '';
            selectUnit('', true);
        });
    }

    function applyFilters() {
        const query = (searchInput ? searchInput.value : '').toLowerCase().trim();
        const selRole = currentSelectedRole.toLowerCase();
        const selUnit = currentSelectedUnit.toLowerCase();
        const selStatus = (statusFilter ? statusFilter.value : '').toLowerCase();

        let visibleCount = 0;
        let visibleAdmins = 0;
        let visibleSupervisors = 0;
        let visibleOperators = 0;

        const allRecords = document.querySelectorAll('.user-record');
        allRecords.forEach(el => {
            const name = el.getAttribute('data-name') || '';
            const username = el.getAttribute('data-username') || '';
            const role = el.getAttribute('data-role') || '';
            const unit = el.getAttribute('data-unit') || '';
            const status = el.getAttribute('data-status') || '';
            const phone = el.getAttribute('data-phone') || '';
            const designation = el.getAttribute('data-designation') || '';

            const matchesQuery = !query || 
                name.includes(query) || 
                username.includes(query) || 
                role.includes(query) || 
                unit.includes(query) || 
                phone.includes(query) || 
                designation.includes(query);

            const matchesRole = !selRole || role === selRole;
            const matchesUnit = !selUnit || unit === selUnit;
            const matchesStatus = !selStatus || status === selStatus;

            if (matchesQuery && matchesRole && matchesUnit && matchesStatus) {
                el.style.display = '';
                // If it's a table row, ensure display is table-row
                if (el.tagName === 'TR') {
                    el.style.display = 'table-row';
                    visibleCount++;
                    if (role === 'admin') visibleAdmins++;
                    else if (role === 'supervisor') visibleSupervisors++;
                    else if (role === 'operator') visibleOperators++;
                }
            } else {
                el.style.display = 'none';
            }
        });

        // Update badge counts
        if (totalUsersBadge) totalUsersBadge.innerText = `${visibleCount} Active`;
        if (chipTotal) chipTotal.innerText = visibleCount;
        if (chipAdmins) chipAdmins.innerText = visibleAdmins;
        if (chipSups) chipSups.innerText = visibleSupervisors;
        if (chipOps) chipOps.innerText = visibleOperators;

        // Show/hide empty state
        if (visibleCount === 0) {
            if (tableContainer) tableContainer.style.display = 'none';
            if (cardsContainer) cardsContainer.style.display = 'none';
            if (emptyState) {
                emptyState.style.display = 'block';
                if (currentSelectedUnit) {
                    emptyTitle.innerText = `No Users in ${currentSelectedUnit} Yet`;
                    emptyDesc.innerText = `This facility currently has no shift supervisors or machine operators assigned.`;
                    if (emptyBtnText) emptyBtnText.innerText = `Add User to ${currentSelectedUnit}`;
                } else if (query) {
                    emptyTitle.innerText = `No Users Match "${query}"`;
                    emptyDesc.innerText = `Try searching with a different name, username, ID, or clearing the search query.`;
                    if (emptyBtnText) emptyBtnText.innerText = `Add New User`;
                } else {
                    emptyTitle.innerText = `No Users Found`;
                    emptyDesc.innerText = `No registered users match the selected filters.`;
                    if (emptyBtnText) emptyBtnText.innerText = `Add User`;
                }
            }
        } else {
            if (emptyState) emptyState.style.display = 'none';
            if (currentViewMode === 'cards') {
                if (cardsContainer) cardsContainer.style.display = 'grid';
                if (tableContainer) tableContainer.style.display = 'none';
            } else {
                if (tableContainer) tableContainer.style.display = 'block';
                if (cardsContainer) cardsContainer.style.display = 'none';
            }
        }

        // Summary text and reset button visibility
        const isFiltered = !!(currentSelectedUnit || currentSelectedRole || query || selStatus);
        if (resetBtn) resetBtn.style.display = isFiltered ? 'inline-flex' : 'none';

        if (filterSummary) {
            if (currentSelectedUnit && currentSelectedRole) {
                filterSummary.innerHTML = `Filtering: <strong style="color: var(--accent-cyan);">${currentSelectedUnit}</strong> • <strong style="color: #fff;">${selRole.toUpperCase()}</strong> (${visibleCount} accounts)`;
            } else if (currentSelectedUnit) {
                filterSummary.innerHTML = `Filtering by <strong style="color: var(--accent-cyan);">${currentSelectedUnit}</strong> (${visibleCount} accounts)`;
            } else if (currentSelectedRole) {
                filterSummary.innerHTML = `Showing <strong style="color: #fff;">${selRole.toUpperCase()}</strong> accounts (${visibleCount})`;
            } else if (query) {
                filterSummary.innerHTML = `Search: "<strong style="color: #fff;">${query}</strong>" (${visibleCount} matches)`;
            } else {
                filterSummary.innerText = `Showing all factory accounts (${visibleCount})`;
            }
        }
    }

    window.applyFilters = applyFilters;

    // Handle browser back/forward buttons
    window.addEventListener('popstate', () => {
        const params = new URLSearchParams(window.location.search);
        const u = params.get('unit') || '';
        selectUnit(u, false);
    });

    // Initial run
    if (currentSelectedUnit) {
        selectUnit(currentSelectedUnit, false);
    } else {
        applyFilters();
    }

    // ==========================================
    // QUICK ADD USER MODAL HANDLERS
    // ==========================================
    const addModal = document.getElementById('addUserModal');
    const closeAddBtn = document.getElementById('closeAddUserModalBtn');
    const cancelAddBtn = document.getElementById('cancelAddUserModalBtn');
    const addForm = document.getElementById('addUserForm');

    window.openAddUserModal = function() {
        if (!addModal) return;
        // Pre-select current unit if one is active
        if (currentSelectedUnit) {
            const unitSelect = document.getElementById('addUserUnit');
            if (unitSelect) unitSelect.value = currentSelectedUnit;
        }
        addModal.style.display = 'flex';
        addModal.classList.add('active');
        addModal.style.opacity = '1';
        const nameInput = document.getElementById('addUserName');
        if (nameInput) setTimeout(() => nameInput.focus(), 100);
    };

    function closeAddUserModal() {
        if (!addModal) return;
        addModal.style.display = 'none';
        addModal.classList.remove('active');
        addModal.style.opacity = '0';
    }

    if (closeAddBtn) closeAddBtn.addEventListener('click', closeAddUserModal);
    if (cancelAddBtn) cancelAddBtn.addEventListener('click', closeAddUserModal);
    if (addModal) {
        addModal.addEventListener('click', (e) => {
            if (e.target === addModal) closeAddUserModal();
        });
    }

    if (addForm) {
        addForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const submitBtn = document.getElementById('submitAddUserBtn');
            const origHtml = submitBtn.innerHTML;

            const name = document.getElementById('addUserName').value.trim();
            const role = document.getElementById('addUserRole').value;
            const unit = document.getElementById('addUserUnit').value;
            const username = document.getElementById('addUserUsername').value.trim();
            const phone = document.getElementById('addUserPhone').value.trim();
            const password = document.getElementById('addUserPassword').value.trim();

            if (!name) {
                alert('Please enter a full name.');
                return;
            }

            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';

            try {
                const res = await fetch('api.php?action=quick_add_user', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ name, role, unit, username, phone, password })
                });
                const data = await res.json();
                if (data.success) {
                    alert(data.message);
                    window.location.reload();
                } else {
                    alert('Error: ' + (data.message || 'Failed to create user.'));
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = origHtml;
                }
            } catch (err) {
                alert('Network or server error: ' + err.message);
                submitBtn.disabled = false;
                submitBtn.innerHTML = origHtml;
            }
        });
    }

    // ==========================================
    // EDIT USER MODAL HANDLERS
    // ==========================================
    const editModal = document.getElementById('editUserModal');
    const closeEditBtn = document.getElementById('closeEditModalBtn');
    const cancelEditBtn = document.getElementById('cancelEditModalBtn');
    const editForm = document.getElementById('editUserForm');

    function closeEditModal() {
        if (editModal) {
            editModal.style.display = 'none';
            editModal.classList.remove('active');
            editModal.style.opacity = '0';
        }
    }

    if (closeEditBtn) closeEditBtn.addEventListener('click', closeEditModal);
    if (cancelEditBtn) cancelEditBtn.addEventListener('click', closeEditModal);
    if (editModal) {
        editModal.addEventListener('click', (e) => {
            if (e.target === editModal) closeEditModal();
        });
    }

    if (editForm) {
        // Enforce numeric only for 10-digit phone inputs in Edit Modal
        ['editMobileNum', 'editAltMobileNum'].forEach(id => {
            const el = document.getElementById(id);
            if (el) {
                el.addEventListener('input', function() {
                    this.value = this.value.replace(/\D/g, '').slice(0, 10);
                });
            }
        });

        editForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const userId = document.getElementById('editUserId').value;
            const workerId = document.getElementById('editWorkerId').value;
            const username = document.getElementById('editUsername').value;
            const name = document.getElementById('editFullName').value.trim();
            const phone = document.getElementById('editMobileNum').value.trim().replace(/\D/g, '');
            const altPhone = document.getElementById('editAltMobileNum').value.trim().replace(/\D/g, '');
            const age = document.getElementById('editAge').value.trim();
            const designation = document.getElementById('editDesignation').value.trim();
            const role = document.getElementById('editUserRoleSelect').value;
            const unit = document.getElementById('editUserUnitSelect').value;
            const pass = document.getElementById('editUserNewPassword').value.trim();

            if (!name) {
                alert('Please enter a full name.');
                return;
            }

            if (phone && phone.length !== 10) {
                alert('Phone number must be exactly 10 digits.');
                document.getElementById('editMobileNum').focus();
                return;
            }

            if (altPhone && altPhone.length !== 10) {
                alert('Alternate phone number must be exactly 10 digits.');
                document.getElementById('editAltMobileNum').focus();
                return;
            }

            if (pass && (pass.length < 4 || pass.length > 6)) {
                alert('Reset password must be between 4 and 6 characters.');
                document.getElementById('editUserNewPassword').focus();
                return;
            }

            const saveBtn = document.getElementById('saveEditUserBtn');
            const origHtml = saveBtn.innerHTML;

            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving Changes...';

            try {
                const res = await fetch('api.php?action=update_user_credentials', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        user_id: userId,
                        worker_id: workerId,
                        username: username,
                        name: name,
                        phone: phone,
                        alt_phone: altPhone,
                        age: age ? parseInt(age) : 0,
                        designation: designation,
                        role: role,
                        unit: unit,
                        password: pass
                    })
                });
                const data = await res.json();
                if (data.success) {
                    if (typeof showToast === 'function') {
                        showToast(data.message, 'success');
                    } else {
                        alert(data.message);
                    }
                    closeEditModal();
                    setTimeout(() => window.location.reload(), 450);
                } else {
                    alert('Save Failed: ' + (data.message || 'Error updating user profile.'));
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = origHtml;
                }
            } catch (err) {
                alert('Network or server error updating profile: ' + err.message);
                saveBtn.disabled = false;
                saveBtn.innerHTML = origHtml;
            }
        });
    }

    // ==========================================
    // CLEAR SYSTEM DATA MODAL HANDLERS
    // ==========================================
    const clearModal = document.getElementById('clearDataModal');
    const closeClearBtn = document.getElementById('closeClearModalBtn');
    const cancelClearBtn = document.getElementById('cancelClearModalBtn');
    const clearForm = document.getElementById('clearDataForm');

    window.openClearDataModal = function() {
        if (clearModal) {
            clearModal.style.display = 'flex';
            clearModal.classList.add('active');
            clearModal.style.opacity = '1';
        }
    };

    function closeClearModal() {
        if (clearModal) {
            clearModal.style.display = 'none';
            clearModal.classList.remove('active');
            clearModal.style.opacity = '0';
        }
    }

    if (closeClearBtn) closeClearBtn.addEventListener('click', closeClearModal);
    if (cancelClearBtn) cancelClearBtn.addEventListener('click', closeClearModal);
    if (clearModal) {
        clearModal.addEventListener('click', (e) => {
            if (e.target === clearModal) closeClearModal();
        });
    }

    if (clearForm) {
        clearForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const mode = document.querySelector('input[name="clear_mode"]:checked')?.value || 'logs_only';
            const confirmBtn = document.getElementById('confirmClearBtn');
            const origHtml = confirmBtn.innerHTML;

            const modeLabels = {
                'logs_only': 'piece output logs and reset production counts to 0 pcs',
                'workers_and_logs': 'test workers, production logs, and operator accounts',
                'full_reset': 'ALL data (full factory reset, leaving only the admin account)'
            };

            const confirmMsg = `Are you sure you want to clear ${modeLabels[mode] || mode}?\n\nAn automatic JSON backup will be created before clearing.`;
            if (!confirm(confirmMsg)) {
                return;
            }

            confirmBtn.disabled = true;
            confirmBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Clearing Data...';

            try {
                const res = await fetch('api.php?action=clear_system_data', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ mode })
                });
                const data = await res.json();
                if (data.success) {
                    alert('Data Cleared Successfully!\n' + data.message + (data.backup_file ? '\n\nBackup saved: ' + data.backup_file : ''));
                    window.location.reload();
                } else {
                    alert('Clear Failed: ' + (data.message || 'Unknown error.'));
                    confirmBtn.disabled = false;
                    confirmBtn.innerHTML = origHtml;
                }
            } catch (err) {
                alert('Network or server error while clearing data: ' + err.message);
                confirmBtn.disabled = false;
                confirmBtn.innerHTML = origHtml;
            }
        });
    }

    // ==========================================
    // QUICK UNIT TRANSFER MODAL HANDLERS
    // ==========================================
    const quickTransferModal = document.getElementById('quickUnitTransferModal');
    const quickTransferForm = document.getElementById('quickUnitTransferForm');
    if (quickTransferModal) {
        quickTransferModal.addEventListener('click', (e) => {
            if (e.target === quickTransferModal) closeQuickTransferModal();
        });
    }
    if (quickTransferForm) {
        quickTransferForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const userId = parseInt(document.getElementById('quickTransferUserId').value, 10) || 0;
            const workerId = parseInt(document.getElementById('quickTransferWorkerId').value, 10) || 0;
            const username = document.getElementById('quickTransferUsername').value.trim();
            const targetSelect = document.getElementById('quickTransferTargetUnitSelect');
            const targetUnit = targetSelect ? targetSelect.value : '';
            const transferType = document.getElementById('quickTransferTypeSelect').value;
            const notes = document.getElementById('quickTransferNotes').value.trim();

            if (!targetUnit) {
                alert('Please select a target production unit.');
                return;
            }

            const chosenOpt = targetSelect ? targetSelect.options[targetSelect.selectedIndex] : null;
            if (chosenOpt && chosenOpt.disabled) {
                alert('Invalid Selection: You cannot transfer a user to their currently assigned unit. Please select a different unit.');
                return;
            }

            const curUnit = (currentQuickTransferUserData && currentQuickTransferUserData.unit) ? currentQuickTransferUserData.unit : '';
            if (curUnit && typeof isMatchingUnit === 'function' && isMatchingUnit(targetUnit, chosenOpt ? chosenOpt.text : '', curUnit)) {
                alert(`This user is already currently assigned to ${curUnit}. Please choose a different target unit.`);
                return;
            }

            const submitBtn = document.getElementById('quickTransferSubmitBtn');
            const origHtml = submitBtn ? submitBtn.innerHTML : '';
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Transferring...';
            }

            try {
                const res = await fetch('api.php?action=transfer_user_unit', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        user_id: userId,
                        worker_id: workerId,
                        username: username,
                        new_unit: targetUnit,
                        transfer_type: transferType,
                        notes: notes || (transferType === 'temporary' ? 'Emergency Piece Support' : 'Permanent Transfer')
                    })
                });
                const data = await res.json();
                if (data.success) {
                    if (currentQuickTransferUserData) {
                        currentQuickTransferUserData.unit = targetUnit;
                    }
                    applyUnitTransferToDOM(userId, workerId, targetUnit);
                    closeQuickTransferModal();
                    if (typeof showToast === 'function') {
                        showToast(data.message, 'success');
                    } else {
                        alert(data.message);
                    }
                } else {
                    alert('Transfer failed: ' + (data.message || 'Unknown error.'));
                }
            } catch (err) {
                alert('Network or server error during unit transfer: ' + err.message);
            } finally {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = origHtml;
                }
            }
        });
    }
});

// ==========================================
// USER DETAILS PROFILE MODAL & 3 ACTIONS
// ==========================================
let currentModalUserData = null;

function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function renderCategoryBreakdown(categories, fallbackPieces, unitsBreakdown) {
    const container = document.getElementById('detailModalCategoryBreakdownContainer');
    const grandPiecesEl = document.getElementById('detailModalGrandPieces');
    const catCountBadge = document.getElementById('detailModalCategoryCountBadge');
    const catSummaryEl = document.getElementById('detailModalActiveCatSummary');
    const shiftsCountEl = document.getElementById('detailModalShiftsCount');
    const topPiecesEl = document.getElementById('detailModalPieces');

    const u1PiecesEl = document.getElementById('detailModalUnit1Pieces');
    const u1PctEl = document.getElementById('detailModalUnit1PctBadge');
    const u2PiecesEl = document.getElementById('detailModalUnit2Pieces');
    const u2PctEl = document.getElementById('detailModalUnit2PctBadge');
    const ratioTextEl = document.getElementById('detailModalUnitRatioText');
    const barU1 = document.getElementById('detailModalRatioUnit1');
    const barU2 = document.getElementById('detailModalRatioUnit2');

    if (!container) return;

    // Determine unit breakdown counts
    let unit1Total = 0;
    let unit2Total = 0;

    if (Array.isArray(unitsBreakdown) && unitsBreakdown.length > 0) {
        unitsBreakdown.forEach(ub => {
            const uName = String(ub.unit_name || '').toLowerCase();
            const p = parseInt(ub.total_pieces, 10) || 0;
            if (uName.includes('unit 1') || uName === '1') {
                unit1Total += p;
            } else if (uName.includes('unit 2') || uName === '2') {
                unit2Total += p;
            }
        });
    } else if (Array.isArray(categories) && categories.length > 0) {
        // Fallback: aggregate from category units_stitched
        categories.forEach(c => {
            const p = parseInt(c.total_pieces, 10) || 0;
            const uStr = String(c.units_stitched || '').toLowerCase();
            if (uStr.includes('unit 1') && !uStr.includes('unit 2')) {
                unit1Total += p;
            } else if (uStr.includes('unit 2') && !uStr.includes('unit 1')) {
                unit2Total += p;
            } else {
                if (currentModalUserData && String(currentModalUserData.unit || '').toLowerCase().includes('unit 2')) {
                    unit2Total += p;
                } else {
                    unit1Total += p;
                }
            }
        });
    }

    if (!categories || categories.length === 0) {
        const rawPieces = parseInt(String(fallbackPieces || '0').replace(/[^0-9]/g, ''), 10) || 0;
        
        if (catCountBadge) catCountBadge.innerText = '0 Categories';
        if (grandPiecesEl) grandPiecesEl.innerText = `${rawPieces.toLocaleString()} pcs`;
        if (catSummaryEl) catSummaryEl.innerText = '0 Categories • 0 records';
        if (shiftsCountEl) shiftsCountEl.innerText = 'No production batches recorded';
        if (topPiecesEl) topPiecesEl.innerText = `${rawPieces.toLocaleString()} pcs`;

        if (u1PiecesEl) u1PiecesEl.innerText = `${unit1Total.toLocaleString()} pcs`;
        if (u1PctEl) u1PctEl.innerText = '0%';
        if (u2PiecesEl) u2PiecesEl.innerText = `${unit2Total.toLocaleString()} pcs`;
        if (u2PctEl) u2PctEl.innerText = '0%';
        if (ratioTextEl) ratioTextEl.innerText = `Unit 1: ${unit1Total.toLocaleString()} pcs • Unit 2: ${unit2Total.toLocaleString()} pcs`;
        if (barU1) barU1.style.width = '50%';
        if (barU2) barU2.style.width = '50%';

        container.innerHTML = `
            <div style="padding: 1.25rem 1rem; text-align: center; background: rgba(0,0,0,0.25); border: 1px dashed rgba(255,255,255,0.12); border-radius: 8px;">
                <i class="fa-solid fa-shirt" style="font-size: 1.6rem; color: var(--text-muted); opacity: 0.45; margin-bottom: 0.4rem; display: block;"></i>
                <div style="font-size: 0.84rem; font-weight: 600; color: #cbd5e1;">No Stitched Category Breakdown Recorded</div>
                <div style="font-size: 0.72rem; color: var(--text-muted); margin-top: 0.25rem; line-height: 1.4;">When this operator logs stitched garments on the factory floor, category volume and pieces will automatically display here.</div>
            </div>
        `;
        return;
    }

    // Compute Grand Total pieces across all categories
    let grandTotal = 0;
    let totalLogs = 0;
    categories.forEach(c => {
        grandTotal += (parseInt(c.total_pieces, 10) || 0);
        totalLogs += (parseInt(c.log_count, 10) || 1);
    });

    // If unit1Total + unit2Total is 0 but grandTotal > 0, attribute by worker's current unit
    if (unit1Total === 0 && unit2Total === 0 && grandTotal > 0) {
        if (currentModalUserData && String(currentModalUserData.unit || '').toLowerCase().includes('unit 2')) {
            unit2Total = grandTotal;
        } else {
            unit1Total = grandTotal;
        }
    }

    const u1Pct = grandTotal > 0 ? ((unit1Total / grandTotal) * 100).toFixed(1) : '0';
    const u2Pct = grandTotal > 0 ? ((unit2Total / grandTotal) * 100).toFixed(1) : '0';

    if (catCountBadge) catCountBadge.innerText = `${categories.length} ${categories.length === 1 ? 'Category' : 'Categories'}`;
    if (grandPiecesEl) grandPiecesEl.innerText = `${grandTotal.toLocaleString()} pcs`;
    if (catSummaryEl) catSummaryEl.innerText = `${categories.length} ${categories.length === 1 ? 'Category' : 'Categories'} • ${totalLogs} ${totalLogs === 1 ? 'record' : 'records'}`;
    if (shiftsCountEl) shiftsCountEl.innerText = `${totalLogs} production ${totalLogs === 1 ? 'record' : 'records'}`;
    if (topPiecesEl) topPiecesEl.innerText = `${grandTotal.toLocaleString()} pcs`;

    if (u1PiecesEl) u1PiecesEl.innerText = `${unit1Total.toLocaleString()} pcs`;
    if (u1PctEl) u1PctEl.innerText = `${u1Pct}%`;
    if (u2PiecesEl) u2PiecesEl.innerText = `${unit2Total.toLocaleString()} pcs`;
    if (u2PctEl) u2PctEl.innerText = `${u2Pct}%`;

    if (ratioTextEl) {
        ratioTextEl.innerText = `Unit 1: ${unit1Total.toLocaleString()} pcs (${u1Pct}%) • Unit 2: ${unit2Total.toLocaleString()} pcs (${u2Pct}%)`;
    }
    if (barU1 && barU2) {
        if (grandTotal > 0) {
            barU1.style.width = `${u1Pct}%`;
            barU2.style.width = `${u2Pct}%`;
        } else {
            barU1.style.width = '50%';
            barU2.style.width = '50%';
        }
    }

    const gradients = [
        'linear-gradient(90deg, #06b6d4, #3b82f6)',
        'linear-gradient(90deg, #10b981, #14b8a6)',
        'linear-gradient(90deg, #a855f7, #6366f1)',
        'linear-gradient(90deg, #f59e0b, #ef4444)',
        'linear-gradient(90deg, #ec4899, #8b5cf6)',
        'linear-gradient(90deg, #0ea5e9, #10b981)'
    ];

    const iconColors = [
        'var(--accent-cyan)',
        '#34d399',
        '#c084fc',
        '#fbbf24',
        '#f472b6',
        '#38bdf8'
    ];

    let html = '';
    categories.forEach((cat, index) => {
        const pCount = parseInt(cat.total_pieces, 10) || 0;
        const pct = grandTotal > 0 ? ((pCount / grandTotal) * 100).toFixed(1) : 0;
        const grad = gradients[index % gradients.length];
        const iconColor = iconColors[index % iconColors.length];
        const logCount = parseInt(cat.log_count, 10) || 1;
        const lastDate = cat.last_date_formatted || cat.last_date || 'Recent';

        // Auto select icon
        let icon = 'fa-shirt';
        const mLower = (cat.material_name || '').toLowerCase();
        if (mLower.includes('jean') || mLower.includes('pant') || mLower.includes('chino') || mLower.includes('trouser')) {
            icon = 'fa-vest';
        } else if (mLower.includes('jacket') || mLower.includes('denim')) {
            icon = 'fa-hat-cowboy';
        } else if (mLower.includes('t-shirt') || mLower.includes('tee') || mLower.includes('polo')) {
            icon = 'fa-shirt';
        }

        // Render unit badges with exact stitched pieces per unit
        let unitBadgeHtml = '';
        if (cat.unit1_pieces !== undefined && (cat.unit1_pieces > 0 || cat.unit2_pieces > 0)) {
            if (cat.unit1_pieces > 0) {
                unitBadgeHtml += `<span class="badge-pill unit-cyan-pill" style="font-size: 0.65rem; padding: 0.08rem 0.42rem; display: inline-flex; align-items: center; gap: 0.22rem;"><i class="fa-solid fa-industry" style="font-size: 0.55rem;"></i> Unit 1: ${cat.unit1_pieces.toLocaleString()} pcs</span> `;
            }
            if (cat.unit2_pieces > 0) {
                unitBadgeHtml += `<span class="badge-pill unit-pink-pill" style="font-size: 0.65rem; padding: 0.08rem 0.42rem; display: inline-flex; align-items: center; gap: 0.22rem;"><i class="fa-solid fa-warehouse" style="font-size: 0.55rem;"></i> Unit 2: ${cat.unit2_pieces.toLocaleString()} pcs</span> `;
            }
        } else if (cat.units_stitched) {
            const uList = String(cat.units_stitched).split(',').map(s => s.trim()).filter(Boolean);
            unitBadgeHtml = uList.map(u => {
                const uCol = u.toLowerCase().includes('unit 2') ? 'pink' : (u.toLowerCase().includes('unit 1') ? 'cyan' : 'emerald');
                return `<span class="badge-pill unit-${uCol}-pill" style="font-size: 0.65rem; padding: 0.06rem 0.38rem; display: inline-flex; align-items: center; gap: 0.22rem;"><i class="fa-solid fa-building" style="font-size: 0.55rem;"></i> ${escapeHtml(u)}</span>`;
            }).join(' ');
        }
        if (cat.subcategory_label) {
            unitBadgeHtml += `<span class="badge-pill" style="font-size: 0.65rem; padding: 0.06rem 0.35rem; background: rgba(168,85,247,0.12); color: #c084fc; border: 1px solid rgba(168,85,247,0.25);">${escapeHtml(cat.subcategory_label)}</span>`;
        }

        html += `
            <div class="category-breakdown-row">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.35rem; gap: 0.5rem;">
                    <div style="display: flex; align-items: center; gap: 0.5rem; min-width: 0; flex-wrap: wrap;">
                        <span style="display: inline-flex; align-items: center; justify-content: center; width: 24px; height: 24px; border-radius: 6px; background: rgba(255,255,255,0.06); color: ${iconColor}; font-size: 0.78rem; flex-shrink: 0;">
                            <i class="fa-solid ${icon}"></i>
                        </span>
                        <span style="font-size: 0.85rem; font-weight: 700; color: #f8fafc;">
                            ${escapeHtml(cat.material_name || 'Standard Garment')}
                        </span>
                        ${unitBadgeHtml}
                    </div>
                    <div style="display: flex; align-items: center; gap: 0.45rem; flex-shrink: 0;">
                        <span style="font-size: 0.92rem; font-weight: 800; color: #fff;">
                            ${pCount.toLocaleString()} <span style="font-size: 0.72rem; font-weight: 500; color: var(--text-muted);">pcs</span>
                        </span>
                        <span class="badge-pill" style="font-size: 0.68rem; font-weight: 700; padding: 0.1rem 0.45rem; background: rgba(6,182,212,0.12); color: var(--accent-cyan); border: 1px solid rgba(6,182,212,0.3);">
                            ${pct}%
                        </span>
                    </div>
                </div>

                <!-- Modern Progress Bar -->
                <div style="width: 100%; height: 6px; background: rgba(255,255,255,0.08); border-radius: 999px; overflow: hidden; margin-bottom: 0.3rem;">
                    <div style="width: ${pct}%; height: 100%; background: ${grad}; border-radius: 999px; transition: width 0.4s ease;"></div>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.68rem; color: var(--text-muted);">
                    <span><i class="fa-solid fa-clock-rotate-left" style="font-size: 0.65rem;"></i> ${logCount} ${logCount === 1 ? 'batch' : 'batches'} stitched</span>
                    <span><i class="fa-solid fa-calendar-check" style="font-size: 0.65rem;"></i> Last: ${escapeHtml(lastDate)}</span>
                </div>
            </div>
        `;
    });

    container.innerHTML = html;
}

async function fetchWorkerCategoryDetails(data) {
    if (!data) return;
    try {
        const workerId = data.worker_id || 0;
        const username = data.username || '';
        const url = `api.php?action=get_worker_details&id=${workerId}&unique_id=${encodeURIComponent(username)}&username=${encodeURIComponent(username)}`;
        const res = await fetch(url);
        const json = await res.json();
        if (json.success && json.materials_breakdown) {
            renderCategoryBreakdown(json.materials_breakdown, json.grand_total_pieces, json.units_breakdown);
        }
    } catch (err) {
        console.warn('Could not fetch live category details:', err);
    }
}

function openUserDetailsModal(data) {
    currentModalUserData = data;
    const modal = document.getElementById('userDetailsProfileModal');
    if (!modal) return;

    document.getElementById('detailModalName').innerText = data.name || data.username;
    document.getElementById('detailModalUsername').innerText = '@' + data.username;
    const avatar = document.getElementById('detailModalAvatar');
    if (avatar) avatar.innerText = (data.name || data.username).charAt(0).toUpperCase();

    // Unit Badge
    const unitBadge = document.getElementById('detailModalUnitBadge');
    if (unitBadge) {
        unitBadge.innerText = data.unit || 'All Units';
        const uColor = (data.unit && data.unit.toLowerCase().includes('unit 2')) ? 'pink' : ((data.unit && data.unit.toLowerCase().includes('unit 1')) ? 'cyan' : 'emerald');
        unitBadge.className = `badge-pill unit-${uColor}-pill`;
    }

    // Status Badge
    updateModalStatusBadge(data.status);

    // Fields
    document.getElementById('detailModalPhone').innerText = data.phone || '—';
    document.getElementById('detailModalAge').innerText = data.age || '—';
    document.getElementById('detailModalDesignation').innerText = data.designation || '—';
    document.getElementById('detailModalRole').innerText = (data.role || 'operator').toUpperCase();
    document.getElementById('detailModalAddress').innerText = data.address || '—';
    document.getElementById('detailModalExp').innerText = data.experience || '—';
    document.getElementById('detailModalPieces').innerText = data.pieces ? `${data.pieces} pcs` : '0 pcs';
    document.getElementById('detailModalCreated').innerText = data.created_at || '—';
    document.getElementById('detailModalSkills').innerText = data.extra_skills || 'Standard Production Line Operations';

    // Admin Unit Management inside Profile Modal
    const unitSection = document.getElementById('detailModalUnitTransferSection');
    const currUnitDisplay = document.getElementById('detailModalCurrentUnitDisplay');
    const targetUnitSelect = document.getElementById('detailModalTargetUnitSelect');
    const transferNotice = document.getElementById('detailModalTransferNotice');

    if (unitSection) {
        if (data.username && data.username.toLowerCase() === 'admin') {
            unitSection.style.display = 'none';
        } else {
            unitSection.style.display = 'block';
            if (currUnitDisplay) {
                currUnitDisplay.innerText = data.unit || 'Unit 1';
                const uCol = (data.unit && data.unit.toLowerCase().includes('unit 2')) ? 'pink' : ((data.unit && data.unit.toLowerCase().includes('unit 1')) ? 'cyan' : 'emerald');
                currUnitDisplay.className = `badge-pill unit-${uCol}-pill`;
            }
            if (targetUnitSelect) {
                syncTargetUnitDropdown(targetUnitSelect, data.unit || 'Unit 1');
            }
            if (transferNotice) {
                transferNotice.style.display = 'none';
                transferNotice.innerText = '';
            }
        }
    }

    // 1. Render immediate category breakdown from preloaded PHP data (instant zero latency)
    renderCategoryBreakdown(data.categories || [], data.pieces, data.unit_breakdown || []);

    // 2. Fetch live data from API in background to ensure sync with newly logged stitches
    fetchWorkerCategoryDetails(data);

    // Workstation Link
    const wsContainer = document.getElementById('detailModalWorkstationLinkContainer');
    const wsLink = document.getElementById('detailModalWorkstationLink');
    if (wsContainer && wsLink) {
        if (data.worker_id && data.worker_id > 0) {
            wsLink.href = `daily_output.php?worker_id=${data.worker_id}`;
            wsContainer.style.display = 'block';
        } else {
            wsContainer.style.display = 'none';
        }
    }

    // Toggle Button inside Modal
    const modalToggleBtn = document.getElementById('detailModalToggleStatusBtn');
    if (modalToggleBtn) {
        modalToggleBtn.onclick = () => {
            toggleUserStatus(data.user_id, data.worker_id, data.username);
        };
    }

    modal.style.display = 'flex';
    modal.classList.add('active');
    modal.style.opacity = '1';
}

function updateModalStatusBadge(status) {
    const isAct = (status.toLowerCase() === 'active');
    const statusBadge = document.getElementById('detailModalStatusBadge');
    const statusText = document.getElementById('detailModalStatusText');
    const toggleBtn = document.getElementById('detailModalToggleStatusBtn');

    if (statusBadge && statusText) {
        statusBadge.className = `status-pill status-${isAct ? 'active' : 'inactive'}`;
        statusText.innerText = isAct ? 'Active' : 'Inactive';
    }
    if (toggleBtn) {
        if (isAct) {
            toggleBtn.style.background = 'rgba(245,158,11,0.2)';
            toggleBtn.style.borderColor = 'rgba(245,158,11,0.4)';
            toggleBtn.style.color = '#fbbf24';
            toggleBtn.innerHTML = '<i class="fa-solid fa-toggle-off"></i> Mark Inactive';
        } else {
            toggleBtn.style.background = 'rgba(16,185,129,0.2)';
            toggleBtn.style.borderColor = 'rgba(16,185,129,0.4)';
            toggleBtn.style.color = '#34d399';
            toggleBtn.innerHTML = '<i class="fa-solid fa-toggle-on"></i> Mark Active';
        }
    }
}

function closeUserDetailsModal() {
    const modal = document.getElementById('userDetailsProfileModal');
    if (modal) {
        modal.style.display = 'none';
        modal.classList.remove('active');
        modal.style.opacity = '0';
    }
}

// ==========================================
// ADMIN PRODUCTION UNIT ASSIGNMENT / REALLOCATION
// ==========================================
async function submitModalUnitTransfer() {
    if (!currentModalUserData) return;
    const targetUnitSelect = document.getElementById('detailModalTargetUnitSelect');
    const transferTypeSelect = document.getElementById('detailModalTransferTypeSelect');
    const submitBtn = document.getElementById('detailModalTransferSubmitBtn');
    const noticeEl = document.getElementById('detailModalTransferNotice');

    const newUnit = targetUnitSelect ? targetUnitSelect.value : '';
    const transferType = transferTypeSelect ? transferTypeSelect.value : 'temporary';
    if (!newUnit) {
        alert('Please select a target production unit.');
        return;
    }

    const chosenOpt = targetUnitSelect ? targetUnitSelect.options[targetUnitSelect.selectedIndex] : null;
    if (chosenOpt && chosenOpt.disabled) {
        alert('Invalid Selection: You cannot transfer a user to their currently assigned unit. Please select a different unit.');
        return;
    }

    const curUnit = (currentModalUserData && currentModalUserData.unit) ? currentModalUserData.unit : '';
    if (curUnit && typeof isMatchingUnit === 'function' && isMatchingUnit(newUnit, chosenOpt ? chosenOpt.text : '', curUnit)) {
        alert(`This user is already currently assigned to ${curUnit}. Please choose a different unit to transfer.`);
        return;
    }

    const origBtnHtml = submitBtn ? submitBtn.innerHTML : '';
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Transferring...';
    }

    try {
        const res = await fetch('api.php?action=transfer_user_unit', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                user_id: currentModalUserData.user_id || 0,
                worker_id: currentModalUserData.worker_id || 0,
                username: currentModalUserData.username || '',
                new_unit: newUnit,
                transfer_type: transferType,
                notes: transferType === 'temporary' ? 'Emergency Piece Support' : 'Permanent Transfer'
            })
        });
        const data = await res.json();
        if (data.success) {
            currentModalUserData.unit = newUnit;

            // Update modal display
            const unitBadge = document.getElementById('detailModalUnitBadge');
            const currUnitDisplay = document.getElementById('detailModalCurrentUnitDisplay');
            const uColor = newUnit.toLowerCase().includes('unit 2') ? 'pink' : (newUnit.toLowerCase().includes('unit 1') ? 'cyan' : 'emerald');
            
            if (unitBadge) {
                unitBadge.innerText = newUnit;
                unitBadge.className = `badge-pill unit-${uColor}-pill`;
            }
            if (currUnitDisplay) {
                currUnitDisplay.innerText = newUnit;
                currUnitDisplay.className = `badge-pill unit-${uColor}-pill`;
            }

            // Sync the dropdown so the newly transferred unit is immediately disabled
            if (targetUnitSelect && typeof syncTargetUnitDropdown === 'function') {
                syncTargetUnitDropdown(targetUnitSelect, newUnit);
            }

            if (noticeEl) {
                noticeEl.style.display = 'block';
                noticeEl.innerHTML = `<i class="fa-solid fa-circle-check"></i> ${escapeHtml(data.message)}`;
            }

            // Update Table Row & Card
            applyUnitTransferToDOM(currentModalUserData.user_id, currentModalUserData.worker_id, newUnit);

            // Refresh category breakdown from server
            fetchWorkerCategoryDetails(currentModalUserData);

            if (typeof showToast === 'function') {
                showToast(data.message, 'success');
            }
        } else {
            alert('Transfer failed: ' + (data.message || 'Unknown error'));
        }
    } catch (err) {
        alert('Network or server error during transfer: ' + err.message);
    } finally {
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = origBtnHtml;
        }
    }
}

// Quick Unit Transfer Modal Handlers
let currentQuickTransferUserData = null;
window.userRecordsMap = window.userRecordsMap || {};

// Helper to check if an option corresponds to a user's current unit
function isMatchingUnit(optVal, optTitle, currentUnit) {
    if (!currentUnit) return false;
    const cur = currentUnit.toString().trim().toLowerCase();
    const val = (optVal || '').toString().trim().toLowerCase();
    const title = (optTitle || '').toString().trim().toLowerCase();

    if (val === cur || title === cur) return true;

    const curNorm = cur.replace(/[^a-z0-9]/g, '');
    const valNorm = val.replace(/[^a-z0-9]/g, '');
    const titleNorm = title.replace(/[^a-z0-9]/g, '');

    if (valNorm === curNorm || titleNorm === curNorm) return true;

    const isCurUnit1 = (curNorm === 'unit1' || curNorm === 'u1' || curNorm === 'productionunit1');
    const isCurUnit2 = (curNorm === 'unit2' || curNorm === 'u2' || curNorm === 'productionunit2');
    const isCurKadampadi = curNorm.includes('kadampadi');

    if (isCurKadampadi) {
        return valNorm.includes('kadampadi') || titleNorm.includes('kadampadi');
    }

    if (isCurUnit1) {
        if (valNorm.includes('kadampadi') || titleNorm.includes('kadampadi')) return false;
        return (valNorm === 'unit1' || valNorm === 'productionunit1' || titleNorm === 'productionunit1' || titleNorm === 'unit1');
    }

    if (isCurUnit2) {
        if (valNorm.includes('kadampadi') || titleNorm.includes('kadampadi')) return false;
        return (valNorm === 'unit2' || valNorm === 'productionunit2' || titleNorm === 'productionunit2' || titleNorm === 'unit2');
    }

    return false;
}

// Synchronize target unit select dropdown so the user's CURRENT unit is DISABLED
function syncTargetUnitDropdown(selectEl, currentUnit) {
    if (!selectEl) return;
    const curClean = (currentUnit || '').trim().toLowerCase();
    let firstValidIndex = -1;

    for (let i = 0; i < selectEl.options.length; i++) {
        const opt = selectEl.options[i];
        let baseTitle = opt.getAttribute('data-base-title');
        if (!baseTitle) {
            baseTitle = opt.text.replace(/\s*\(Current Unit.*?\)/gi, '').trim();
            opt.setAttribute('data-base-title', baseTitle);
        }

        const isCurrent = isMatchingUnit(opt.value, baseTitle, currentUnit);

        if (isCurrent) {
            opt.disabled = true;
            opt.text = `${baseTitle} (Current Unit - Disabled)`;
            opt.title = "User is currently assigned to this unit";
            opt.style.color = '#94a3b8';
            opt.style.backgroundColor = 'rgba(255,255,255,0.06)';
        } else {
            opt.disabled = false;
            opt.text = baseTitle;
            opt.title = "";
            opt.style.color = '';
            opt.style.backgroundColor = '';
            if (firstValidIndex === -1) {
                firstValidIndex = i;
            }
        }
    }

    // Auto-select alternate enabled unit:
    let selectedIdx = -1;
    if (curClean.includes('unit 1') || curClean === 'u1') {
        for (let i = 0; i < selectEl.options.length; i++) {
            if (!selectEl.options[i].disabled && (selectEl.options[i].value.toLowerCase().includes('unit 2') || selectEl.options[i].text.toLowerCase().includes('unit 2'))) {
                selectedIdx = i;
                break;
            }
        }
    } else if (curClean.includes('unit 2') || curClean === 'u2') {
        for (let i = 0; i < selectEl.options.length; i++) {
            if (!selectEl.options[i].disabled && (selectEl.options[i].value.toLowerCase().includes('unit 1') || selectEl.options[i].text.toLowerCase().includes('unit 1'))) {
                selectedIdx = i;
                break;
            }
        }
    }

    if (selectedIdx === -1) {
        selectedIdx = firstValidIndex;
    }

    if (selectedIdx !== -1) {
        selectEl.selectedIndex = selectedIdx;
    }
}

function quickTransferUserUnit(data) {
    if (!data) return;
    const key = `${data.user_id}_${data.worker_id}`;
    if (window.userRecordsMap && window.userRecordsMap[key]) {
        data = Object.assign({}, window.userRecordsMap[key], data, { unit: window.userRecordsMap[key].unit });
    } else {
        window.userRecordsMap = window.userRecordsMap || {};
        window.userRecordsMap[key] = Object.assign({}, data);
    }
    currentQuickTransferUserData = data;
    const modal = document.getElementById('quickUnitTransferModal');
    if (!modal) return;

    document.getElementById('quickTransferUserId').value = data.user_id || 0;
    document.getElementById('quickTransferWorkerId').value = data.worker_id || 0;
    document.getElementById('quickTransferUsername').value = data.username || '';
    document.getElementById('quickTransferUserName').innerText = data.name || data.username;
    document.getElementById('quickTransferUserLogin').innerText = '@' + data.username;
    
    const avatar = document.getElementById('quickTransferAvatar');
    if (avatar) avatar.innerText = (data.name || data.username).charAt(0).toUpperCase();

    const currBadge = document.getElementById('quickTransferCurrentUnitBadge');
    if (currBadge) {
        const cUnit = data.unit || 'Unit 1';
        currBadge.innerText = cUnit;
        const uColor = cUnit.toLowerCase().includes('unit 2') ? 'pink' : (cUnit.toLowerCase().includes('unit 1') ? 'cyan' : 'emerald');
        currBadge.className = `badge-pill unit-${uColor}-pill`;
    }

    const targetSelect = document.getElementById('quickTransferTargetUnitSelect');
    if (targetSelect) {
        syncTargetUnitDropdown(targetSelect, data.unit || 'Unit 1');
    }

    const notesInput = document.getElementById('quickTransferNotes');
    if (notesInput) notesInput.value = '';

    modal.style.display = 'flex';
    modal.classList.add('active');
    modal.style.opacity = '1';
}

function closeQuickTransferModal() {
    const modal = document.getElementById('quickUnitTransferModal');
    if (modal) {
        modal.style.display = 'none';
        modal.classList.remove('active');
        modal.style.opacity = '0';
    }
}

function applyUnitTransferToDOM(userId, workerId, newUnit) {
    const key = `${userId}_${workerId}`;
    if (window.userRecordsMap && window.userRecordsMap[key]) {
        window.userRecordsMap[key].unit = newUnit;
    }

    const uColor = newUnit.toLowerCase().includes('unit 2') ? 'pink' : (newUnit.toLowerCase().includes('unit 1') ? 'cyan' : 'emerald');

    // 1. Update Table Row
    const row = document.getElementById(`userRow_${userId}_${workerId}`);
    const tableBadge = document.getElementById(`tableUnitBadge_${userId}_${workerId}`);
    if (row) {
        row.setAttribute('data-unit', newUnit.toLowerCase());
        row.setAttribute('data-current-unit', newUnit);
    }
    if (tableBadge) {
        tableBadge.className = `badge-pill unit-${uColor}-pill`;
        tableBadge.innerHTML = `<i class="fa-solid fa-building"></i> ${escapeHtml(newUnit)}`;
    }

    // 2. Update Card
    const card = document.getElementById(`userCard_${userId}_${workerId}`);
    const cardBadge = document.getElementById(`cardUnitBadge_${userId}_${workerId}`);
    if (card) {
        card.setAttribute('data-unit', newUnit.toLowerCase());
        card.setAttribute('data-current-unit', newUnit);
    }
    if (cardBadge) {
        cardBadge.className = `badge-pill unit-${uColor}-pill`;
        cardBadge.innerText = newUnit;
    }

    // 3. Recalculate tab counts
    recalculateUnitCounts();

    // 4. Trigger filter update
    if (typeof window.applyFilters === 'function') {
        window.applyFilters();
    }
}

function recalculateUnitCounts() {
    const rows = document.querySelectorAll('tr.user-record');
    const counts = {};
    let total = 0;
    rows.forEach(r => {
        const u = (r.getAttribute('data-unit') || '').trim();
        counts[u] = (counts[u] || 0) + 1;
        total++;
    });

    const allEl = document.getElementById('countAllUnits');
    if (allEl) allEl.innerText = total;

    document.querySelectorAll('.unit-tab-pill[data-unit]').forEach(pill => {
        const uName = (pill.getAttribute('data-unit') || '').trim();
        if (uName) {
            const cleanId = 'count_' + uName.replace(/[^a-zA-Z0-9]/g, '_');
            const cEl = document.getElementById(cleanId);
            if (cEl) {
                cEl.innerText = counts[uName] || 0;
            }
        }
    });
}

// 3 Action Buttons Handlers: Toggle Status
async function toggleUserStatus(userId, workerId, username) {
    const btn = document.getElementById(`toggleBtn_${userId}_${workerId}`);
    const cardBtn = document.getElementById(`cardToggleBtn_${userId}_${workerId}`);
    
    if (btn) btn.disabled = true;
    if (cardBtn) cardBtn.disabled = true;

    try {
        const res = await fetch('api.php?action=toggle_user_status', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ user_id: userId, worker_id: workerId, username: username })
        });
        const data = await res.json();
        if (data.success) {
            const newStatus = data.new_status;
            const isAct = (newStatus.toLowerCase() === 'active');

            // 1. Update Table Row
            const toggleIcon = document.getElementById(`toggleIcon_${userId}_${workerId}`);
            const toggleText = document.getElementById(`toggleText_${userId}_${workerId}`);
            const statusPill = document.getElementById(`statusPill_${userId}_${workerId}`);
            const statusText = document.getElementById(`statusText_${userId}_${workerId}`);
            const row = document.getElementById(`userRow_${userId}_${workerId}`);

            if (btn && toggleIcon && toggleText) {
                btn.className = `btn-action-toggle ${isAct ? 'is-active' : 'is-inactive'}`;
                toggleIcon.className = `fa-solid ${isAct ? 'fa-toggle-on' : 'fa-toggle-off'}`;
                toggleText.innerText = newStatus;
            }
            if (statusPill && statusText) {
                statusPill.className = `status-pill status-${isAct ? 'active' : 'inactive'}`;
                statusText.innerText = newStatus;
            }
            if (row) {
                row.setAttribute('data-status', newStatus.toLowerCase());
            }

            // 2. Update Card
            const cardToggleIcon = document.getElementById(`cardToggleIcon_${userId}_${workerId}`);
            const cardToggleText = document.getElementById(`cardToggleText_${userId}_${workerId}`);
            const cardStatusPill = document.getElementById(`cardStatusPill_${userId}_${workerId}`);
            const cardStatusText = document.getElementById(`cardStatusText_${userId}_${workerId}`);
            const card = document.getElementById(`userCard_${userId}_${workerId}`);

            if (cardBtn && cardToggleIcon && cardToggleText) {
                cardBtn.className = `btn-action-toggle ${isAct ? 'is-active' : 'is-inactive'}`;
                cardToggleIcon.className = `fa-solid ${isAct ? 'fa-toggle-on' : 'fa-toggle-off'}`;
                cardToggleText.innerText = newStatus;
            }
            if (cardStatusPill && cardStatusText) {
                cardStatusPill.className = `status-pill status-${isAct ? 'active' : 'inactive'}`;
                cardStatusText.innerText = newStatus;
            }
            if (card) {
                card.setAttribute('data-status', newStatus.toLowerCase());
            }

            // 3. Update Modal if open
            if (currentModalUserData && (currentModalUserData.user_id == userId || currentModalUserData.username == username)) {
                currentModalUserData.status = newStatus;
                updateModalStatusBadge(newStatus);
            }

            if (typeof showToast === 'function') {
                showToast(`${username} is now marked as ${newStatus}`, 'success');
            }
        } else {
            alert('Error: ' + (data.message || 'Failed to update status.'));
        }
    } catch (err) {
        alert('Network or server error updating status: ' + err.message);
    } finally {
        if (btn) btn.disabled = false;
        if (cardBtn) cardBtn.disabled = false;
    }
}

// 3 Action Buttons Handlers: Delete User
async function confirmDeleteUser(userId, workerId, username, name) {
    if (userId === 1 || username.toLowerCase() === 'admin') {
        alert('The root Administrator account cannot be deleted.');
        return;
    }

    const confirmMsg = `Are you sure you want to delete ${name} (@${username})?\n\nThis will permanently remove this user account, worker profile, and production logs.`;
    if (!confirm(confirmMsg)) {
        return;
    }

    try {
        const res = await fetch('api.php?action=delete_user_worker', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ user_id: userId, worker_id: workerId, username: username })
        });
        const data = await res.json();
        if (data.success) {
            // Animate and remove from DOM
            const row = document.getElementById(`userRow_${userId}_${workerId}`);
            const card = document.getElementById(`userCard_${userId}_${workerId}`);

            if (row) {
                row.style.transition = 'all 0.3s ease';
                row.style.opacity = '0';
                row.style.transform = 'translateX(20px)';
                setTimeout(() => row.remove(), 300);
            }
            if (card) {
                card.style.transition = 'all 0.3s ease';
                card.style.opacity = '0';
                card.style.transform = 'scale(0.9)';
                setTimeout(() => card.remove(), 300);
            }

            // Close modal if deleting current modal user
            closeUserDetailsModal();

            if (typeof showToast === 'function') {
                showToast(`User ${name} (@${username}) was deleted.`, 'success');
            }

            // Re-apply filters to update counts
            setTimeout(() => {
                if (typeof window.applyFilters === 'function') {
                    window.applyFilters();
                }
            }, 350);
        } else {
            alert('Delete Failed: ' + (data.message || 'Unknown error.'));
        }
    } catch (err) {
        alert('Network or server error deleting user: ' + err.message);
    }
}

// Edit Modal Functions
function openEditFromProfileModal() {
    if (typeof currentModalUserData !== 'undefined' && currentModalUserData) {
        closeUserDetailsModal();
        openEditUserModal(currentModalUserData);
    }
}

function openEditUserModal(dataOrId, username, name, role, unit) {
    const modal = document.getElementById('editUserModal');
    if (!modal) return;

    let data = {};
    if (typeof dataOrId === 'object' && dataOrId !== null) {
        data = dataOrId;
    } else {
        data = {
            user_id: dataOrId || 0,
            username: username || '',
            name: name || username || '',
            role: role || 'operator',
            unit: unit || 'All'
        };
    }

    const userId = data.user_id || 0;
    const workerId = data.worker_id || 0;
    const uname = data.username || '';
    const dispName = data.name || uname || 'User';

    document.getElementById('editUserId').value = userId;
    const workerIdInput = document.getElementById('editWorkerId');
    if (workerIdInput) workerIdInput.value = workerId;
    const unameInput = document.getElementById('editUsername');
    if (unameInput) unameInput.value = uname;

    // Header info
    document.getElementById('modalTargetUserName').innerText = dispName;
    document.getElementById('modalTargetUserLogin').innerText = '@' + uname;
    const avatar = document.getElementById('modalTargetAvatar');
    if (avatar) avatar.innerText = dispName.charAt(0).toUpperCase();

    const workerBadge = document.getElementById('modalTargetWorkerBadge');
    if (workerBadge) {
        if (workerId > 0) {
            workerBadge.innerHTML = `<i class="fa-solid fa-id-badge text-cyan"></i> Worker #${workerId}`;
            workerBadge.style.display = 'inline-flex';
        } else {
            workerBadge.innerHTML = `<i class="fa-solid fa-shield-halved text-purple"></i> System User`;
            workerBadge.style.display = 'inline-flex';
        }
    }

    // Full Name
    const nameInput = document.getElementById('editFullName');
    if (nameInput) nameInput.value = dispName;

    // Phone (10 digits clean)
    const phoneInput = document.getElementById('editMobileNum');
    if (phoneInput) {
        let p = (data.raw_phone || data.phone || '').toString().replace(/\D/g, '');
        phoneInput.value = p;
    }

    // Alternate Phone
    const altPhoneInput = document.getElementById('editAltMobileNum');
    if (altPhoneInput) {
        let ap = (data.raw_alt_phone || data.alt_phone || '').toString().replace(/\D/g, '');
        altPhoneInput.value = ap;
    }

    // Age
    const ageInput = document.getElementById('editAge');
    if (ageInput) {
        let a = data.raw_age || parseInt(data.age) || '';
        ageInput.value = (a && !isNaN(a)) ? a : '';
    }

    // Designation / Role
    const desigInput = document.getElementById('editDesignation');
    if (desigInput) {
        let des = data.raw_role || data.designation || '';
        if (des.toLowerCase().includes('account')) des = ''; // Clear generic placeholder like "Admin Account"
        desigInput.value = des;
    }

    // Unit
    const unitSelect = document.getElementById('editUserUnitSelect');
    if (unitSelect) {
        unitSelect.value = data.unit || 'All';
    }

    // System Role
    const roleSelect = document.getElementById('editUserRoleSelect');
    if (roleSelect) {
        roleSelect.value = data.role || 'operator';
        const isRootAdmin = (data.is_admin || userId === 1 || uname.toLowerCase() === 'admin');
        if (isRootAdmin) {
            roleSelect.disabled = true;
            roleSelect.title = "Root Administrator system role cannot be changed.";
        } else {
            roleSelect.disabled = false;
            roleSelect.title = "";
        }
    }

    // Password reset field
    const passInput = document.getElementById('editUserNewPassword');
    if (passInput) passInput.value = '';
    const counter = document.getElementById('editPassCounter');
    if (counter) {
        counter.innerText = 'Only enter if you wish to reset this user\'s password (must be 4 to 6 characters).';
        counter.style.color = 'var(--text-muted)';
    }

    modal.style.display = 'flex';
    modal.classList.add('active');
    modal.style.opacity = '1';
}

function validateEditPassword(val) {
    const counter = document.getElementById('editPassCounter');
    if (!counter) return;
    if (!val) {
        counter.innerHTML = 'Only enter if you wish to reset this user\'s password (must be 4 to 6 characters).';
        counter.style.color = 'var(--text-muted)';
        return;
    }
    const len = val.length;
    if (len >= 4 && len <= 6) {
        counter.innerHTML = `<span style="color: #10b981;"><i class="fa-solid fa-check"></i> Valid password (${len} / 4-6 characters)</span>`;
    } else {
        counter.innerHTML = `<span style="color: #f43f5e;"><i class="fa-solid fa-triangle-exclamation"></i> Must be 4 to 6 characters (currently ${len})</span>`;
    }
}

function generateEditModalPassword() {
    const chars = 'abcdefghjkmnpqrstuvwxyz23456789';
    let pass = '';
    for (let i = 0; i < 5; i++) {
        pass += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    const input = document.getElementById('editUserNewPassword');
    if (input) {
        input.value = pass;
        input.type = 'text';
        validateEditPassword(pass);
    }
}

function generateAddUserPassword() {
    const chars = 'abcdefghjkmnpqrstuvwxyz23456789';
    let pass = '';
    for (let i = 0; i < 5; i++) {
        pass += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    const input = document.getElementById('addUserPassword');
    if (input) {
        input.value = pass;
        input.type = 'text';
    }
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
