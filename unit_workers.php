<?php
// unit_workers.php - Dedicated Unit-Wise Registered Workers List Page
require_once __DIR__ . '/db.php';
$pdo = Database::getConnection();

// Fetch all production units dynamically
$unitsStmt = $pdo->query("
    SELECT 
        pu.*,
        (SELECT COUNT(*) FROM workers WHERE unit = pu.unit_name) as worker_count,
        (SELECT COALESCE(SUM(pl.piece_count), 0) 
         FROM piece_logs pl 
         JOIN workers w ON pl.worker_id = w.id 
         WHERE w.unit = pu.unit_name) as total_pieces
    FROM production_units pu 
    ORDER BY pu.id ASC
");
$productionUnits = $unitsStmt->fetchAll(PDO::FETCH_ASSOC);

$validUnits = array_column($productionUnits, 'unit_name');
if (empty($validUnits)) {
    $validUnits = ['Unit 1', 'Unit 2'];
}

$requestedUnit = trim($_GET['unit'] ?? ($validUnits[0] ?? 'Unit 1'));
if (!in_array($requestedUnit, $validUnits)) {
    $requestedUnit = $validUnits[0] ?? 'Unit 1';
}

$currentUnitData = null;
foreach ($productionUnits as $pu) {
    if ($pu['unit_name'] === $requestedUnit) {
        $currentUnitData = $pu;
        break;
    }
}
if (!$currentUnitData) {
    $currentUnitData = [
        'unit_name' => $requestedUnit,
        'unit_title' => 'Production ' . $requestedUnit,
        'unit_code' => 'PU001',
        'description' => 'Garment Manufacturing Line',
        'color' => 'cyan',
        'icon' => 'fa-industry'
    ];
}

$pageTitle = $currentUnitData['unit_title'] . ' Registered Workers - FabriqPro';

// Unit Metrics
$totalWorkersStmt = $pdo->prepare("SELECT COUNT(*) FROM workers WHERE unit = :unit");
$totalWorkersStmt->execute([':unit' => $requestedUnit]);
$unitWorkerCount = $totalWorkersStmt->fetchColumn() ?: 0;

$unitPiecesStmt = $pdo->prepare("
    SELECT COALESCE(SUM(pl.piece_count), 0) 
    FROM piece_logs pl 
    JOIN workers w ON pl.worker_id = w.id 
    WHERE w.unit = :unit
");
$unitPiecesStmt->execute([':unit' => $requestedUnit]);
$unitTotalPieces = $unitPiecesStmt->fetchColumn() ?: 0;

$topWorkerStmt = $pdo->prepare("
    SELECT name, unique_id, total_pieces, role 
    FROM workers 
    WHERE unit = :unit 
    ORDER BY total_pieces DESC, experience_years DESC 
    LIMIT 1
");
$topWorkerStmt->execute([':unit' => $requestedUnit]);
$topWorker = $topWorkerStmt->fetch();

$avgExpStmt = $pdo->prepare("SELECT AVG(experience_years) FROM workers WHERE unit = :unit");
$avgExpStmt->execute([':unit' => $requestedUnit]);
$avgExp = round($avgExpStmt->fetchColumn() ?: 0, 1);

// Fetch all registered workers for this unit including last_login and assigned work
$workersStmt = $pdo->prepare("
    SELECT 
        w.*,
        (SELECT COUNT(*) FROM piece_logs WHERE worker_id = w.id) as total_entries,
        (SELECT material_name FROM piece_logs WHERE worker_id = w.id ORDER BY id DESC LIMIT 1) as latest_material,
        (SELECT last_login FROM users WHERE LOWER(username) = LOWER(w.unique_id) OR REPLACE(username, '-', '') = REPLACE(w.unique_id, '-', '') OR (LOWER(full_name) = LOWER(w.name) AND NOT EXISTS (SELECT 1 FROM users u_sub WHERE LOWER(u_sub.username) = LOWER(w.unique_id))) LIMIT 1) as user_last_login,
        (SELECT GROUP_CONCAT(DISTINCT category_name SEPARATOR ', ') FROM worker_work_assignments WHERE worker_id = w.id AND assigned_date = CURDATE() AND status = 'Active') as today_assigned_cats,
        (SELECT category_name FROM worker_work_assignments WHERE worker_id = w.id AND assigned_date = CURDATE() ORDER BY id DESC LIMIT 1) as today_assigned_cat,
        (SELECT subcategory_name FROM worker_work_assignments WHERE worker_id = w.id AND assigned_date = CURDATE() ORDER BY id DESC LIMIT 1) as today_assigned_subcat,
        (SELECT sub_subcategory_name FROM worker_work_assignments WHERE worker_id = w.id AND assigned_date = CURDATE() ORDER BY id DESC LIMIT 1) as today_assigned_subsub
    FROM workers w
    WHERE w.unit = :unit
    ORDER BY w.total_pieces DESC, w.name ASC
");
$workersStmt->execute([':unit' => $requestedUnit]);
$workersList = $workersStmt->fetchAll();

// Fetch active garment categories for work assignment
$catStmt = $pdo->query("
    SELECT id, category_name, subcategory_name, sub_subcategory_name, status 
    FROM garment_categories 
    WHERE status = 'Active' 
    ORDER BY category_name ASC, subcategory_name ASC, sub_subcategory_name ASC
");
$activeGarmentCategories = $catStmt->fetchAll(PDO::FETCH_ASSOC);

// Map unit codes for transfer detection
$unitsLookup = [];
foreach ($productionUnits as $pu) {
    if (!empty($pu['unit_code']) && !empty($pu['unit_name'])) {
        $unitsLookup[strtoupper($pu['unit_code'])] = $pu['unit_name'];
    }
}
$unitsLookup['PU001'] = 'Unit 1';
$unitsLookup['PU002'] = 'Unit 2';

$activeWorkersCount = 0;
foreach ($workersList as &$w) {
    $w['is_switched'] = false;
    $transFrom = !empty($w['transferred_from']) ? trim($w['transferred_from']) : '';
    $w['transfer_type'] = !empty($w['transfer_type']) ? trim($w['transfer_type']) : 'Emergency Support';

    if (!empty($transFrom) && strcasecmp($transFrom, $w['unit']) !== 0) {
        $w['is_switched'] = true;
        $w['transferred_from'] = $transFrom;
    } elseif (!empty($w['unique_id'])) {
        $uidUpper = strtoupper($w['unique_id']);
        foreach ($unitsLookup as $code => $uName) {
            if (!empty($code) && strpos($uidUpper, $code) === 0) {
                if (strcasecmp($uName, $w['unit']) !== 0) {
                    $w['is_switched'] = true;
                    $w['transferred_from'] = $uName;
                }
                break;
            }
        }
    }

    // Effective login detection (today's login)
    $effLogin = !empty($w['last_login']) ? $w['last_login'] : (!empty($w['user_last_login']) ? $w['user_last_login'] : null);
    $w['effective_login'] = $effLogin;
    $w['is_logged_in_today'] = (!empty($effLogin) && date('Y-m-d', strtotime($effLogin)) === date('Y-m-d'));

    if ($w['is_logged_in_today']) {
        $activeWorkersCount++;
    }

    // Current assigned work
    if (!empty($w['today_assigned_cats'])) {
        $w['active_assignment'] = $w['today_assigned_cats'];
    } else {
        $assignedParts = array_filter([$w['today_assigned_cat'] ?? '', $w['today_assigned_subcat'] ?? '', $w['today_assigned_subsub'] ?? '']);
        if (!empty($assignedParts)) {
            $w['active_assignment'] = implode(' - ', $assignedParts);
        } else {
            $w['active_assignment'] = !empty($w['assigned_work']) ? $w['assigned_work'] : '';
        }
    }
}
unset($w);

include __DIR__ . '/includes/header.php';
?>

<style>
/* ===================================================================
   ACTIVE WORKER TICK, FILTER & ASSIGN WORK STYLES
   =================================================================== */
@keyframes tickPulse {
    0% { transform: scale(1); filter: drop-shadow(0 0 4px rgba(16,185,129,0.7)); }
    50% { transform: scale(1.18); filter: drop-shadow(0 0 10px rgba(16,185,129,0.95)); }
    100% { transform: scale(1); filter: drop-shadow(0 0 4px rgba(16,185,129,0.7)); }
}

@keyframes pulseGlowEmerald {
    0% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
    70% { box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); }
    100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
}

.worker-login-tick-badge {
    color: #10b981;
    font-size: 1.05rem;
    margin-left: 0.35rem;
    display: inline-flex;
    align-items: center;
    vertical-align: middle;
    animation: tickPulse 2.4s infinite ease-in-out;
    cursor: help;
}

.active-pulse-dot, .pulse-emerald-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #10b981;
    display: inline-block;
    animation: pulseGlowEmerald 1.8s infinite;
}

.active-status-filter-group {
    display: inline-flex;
    align-items: center;
    background: rgba(15,23,42,0.8);
    padding: 3px;
    border-radius: 8px;
    border: 1px solid rgba(255,255,255,0.08);
    gap: 4px;
}

.unit-filter-pill {
    background: transparent;
    color: var(--text-muted);
    border: 1px solid transparent;
    padding: 0.38rem 0.8rem;
    border-radius: 6px;
    font-size: 0.8rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
}
.unit-filter-pill:hover {
    color: #fff;
    background: rgba(255,255,255,0.05);
}
.unit-filter-pill.active {
    background: rgba(6,182,212,0.18);
    color: #06b6d4;
    border-color: rgba(6,182,212,0.4);
    box-shadow: 0 0 12px rgba(6,182,212,0.15);
}
.unit-filter-pill.filter-pill-active.active {
    background: rgba(16,185,129,0.18);
    color: #34d399;
    border-color: rgba(16,185,129,0.45);
    box-shadow: 0 0 14px rgba(16,185,129,0.2);
}

.btn-assign-work {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%) !important;
    border: 1px solid rgba(16,185,129,0.7) !important;
    color: #fff !important;
    font-weight: 700 !important;
    font-size: 0.76rem !important;
    padding: 0.35rem 0.68rem !important;
    border-radius: 6px !important;
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    box-shadow: 0 0 14px rgba(16,185,129,0.3) !important;
    transition: all 0.2s ease !important;
}
.btn-assign-work.is-hidden,
.worker-login-tick-badge.is-hidden {
    display: none !important;
}
.btn-assign-work:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 18px rgba(16,185,129,0.45) !important;
}

.btn-quick-active {
    border: 1px solid rgba(255,255,255,0.1) !important;
    background: rgba(0,0,0,0.25) !important;
    color: var(--text-muted) !important;
    padding: 0.35rem 0.58rem !important;
    border-radius: 6px !important;
    font-size: 0.74rem !important;
    cursor: pointer !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 0.35rem !important;
    transition: all 0.2s ease !important;
}
.btn-quick-active.is-active-btn {
    border-color: rgba(16,185,129,0.4) !important;
    color: #34d399 !important;
    background: rgba(16,185,129,0.1) !important;
}
.btn-quick-active.is-active-btn:hover {
    background: rgba(16,185,129,0.2) !important;
    border-color: #10b981 !important;
    color: #fff !important;
}
.btn-quick-active.is-inactive-btn:hover {
    background: rgba(6,182,212,0.15) !important;
    border-color: rgba(6,182,212,0.4) !important;
    color: #06b6d4 !important;
}

/* Category cards inside Assign Work Modal */
.assign-cat-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
    gap: 0.85rem;
    max-height: 420px;
    overflow-y: auto;
    padding: 0.35rem;
}
.assign-cat-grid::-webkit-scrollbar {
    width: 6px;
}
.assign-cat-grid::-webkit-scrollbar-thumb {
    background: rgba(16,185,129,0.4);
    border-radius: 6px;
}
.assign-cat-card {
    background: rgba(15,23,42,0.85);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 10px;
    padding: 0.9rem;
    transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    gap: 0.65rem;
    position: relative;
    overflow: hidden;
    cursor: pointer;
    user-select: none;
}
.assign-cat-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    background: linear-gradient(90deg, #10b981, #06b6d4);
    opacity: 0;
    transition: opacity 0.2s ease;
}
.assign-cat-card:hover {
    border-color: rgba(16,185,129,0.45);
    transform: translateY(-2px);
    box-shadow: 0 8px 24px -4px rgba(0,0,0,0.5), 0 0 15px rgba(16,185,129,0.15);
    background: rgba(15,23,42,0.95);
}
.assign-cat-card:hover::before {
    opacity: 1;
}
.assign-cat-card.is-selected {
    border-color: #10b981 !important;
    background: rgba(16,185,129,0.16) !important;
    box-shadow: 0 0 20px rgba(16,185,129,0.35) !important;
}
.assign-cat-card.is-selected::before {
    opacity: 1;
}
.cat-card-check-box {
    width: 22px;
    height: 22px;
    border-radius: 6px;
    border: 1.5px solid rgba(255,255,255,0.25);
    background: rgba(0,0,0,0.3);
    color: transparent;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.72rem;
    transition: all 0.2s ease;
    flex-shrink: 0;
}
.assign-cat-card.is-selected .cat-card-check-box {
    border-color: #10b981;
    background: #10b981;
    color: #fff;
    box-shadow: 0 0 10px rgba(16,185,129,0.5);
}
.btn-select-cat.is-selected {
    background: #10b981 !important;
    border-color: #10b981 !important;
    color: #fff !important;
}
</style>

<!-- Page Header -->
<div class="page-header">
    <div class="page-header-title">
        <h1>
            <i class="fa-solid <?= htmlspecialchars($currentUnitData['icon'] ?: 'fa-industry') ?>" style="color: var(--accent-<?= htmlspecialchars($currentUnitData['color'] ?: 'cyan') ?>, #06b6d4);"></i> 
            <?= htmlspecialchars($currentUnitData['unit_title']) ?> - Registered Workers
        </h1>
        <p>Directory of enrolled operators, skill specializations, and production output for <strong><?= htmlspecialchars($currentUnitData['unit_title']) ?></strong> (Worker ID Prefix: <span class="text-cyan font-bold"><?= htmlspecialchars($currentUnitData['unit_code']) ?>-1, 2, 3...</span>).</p>
    </div>
    <div class="page-header-actions">
        <button type="button" class="btn btn-outline" id="manageUnitsBtn" onclick="openUnitsModal()">
            <i class="fa-solid fa-plus-circle text-cyan"></i> Add / Manage Units
        </button>
        <?php if (($currentUnitData['status'] ?? 'Active') === 'Disabled'): ?>
            <button type="button" class="btn btn-outline" onclick="toggleCurrentUnitStatus(<?= (int)$currentUnitData['id'] ?>, 'Active')" style="color: var(--accent-emerald); border-color: rgba(16,185,129,0.4);" title="Click to Enable this Production Unit">
                <i class="fa-solid fa-circle-check"></i> Enable Unit
            </button>
        <?php else: ?>
            <button type="button" class="btn btn-outline" onclick="toggleCurrentUnitStatus(<?= (int)$currentUnitData['id'] ?>, 'Disabled')" style="color: var(--accent-rose); border-color: rgba(244,63,94,0.4);" title="Click to Disable this Production Unit">
                <i class="fa-solid fa-ban"></i> Disable Unit
            </button>
        <?php endif; ?>
        <?php if (($currentUnitData['status'] ?? 'Active') !== 'Disabled'): ?>
            <a href="index.php?unit=<?= urlencode($requestedUnit) ?>" class="btn btn-primary">
                <i class="fa-solid fa-user-plus"></i> Register New <?= htmlspecialchars($requestedUnit) ?> Worker
            </a>
        <?php endif; ?>
        <a href="daily_output.php?unit=<?= urlencode($requestedUnit) ?>" class="btn btn-outline" title="Open Today's Workstation for <?= htmlspecialchars($requestedUnit) ?>">
            <i class="fa-solid fa-shirt text-cyan"></i> <?= htmlspecialchars($requestedUnit) ?> Workstation
        </a>
    </div>
</div>

<?php if (($currentUnitData['status'] ?? 'Active') === 'Disabled'): ?>
<div class="alert-box alert-warning" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.75rem; margin-bottom: 1.5rem; background: rgba(245, 158, 11, 0.1); border: 1px solid rgba(245, 158, 11, 0.3); color: #f59e0b; padding: 0.85rem 1.25rem; border-radius: var(--radius-md);">
    <div style="display: flex; align-items: center; gap: 0.65rem;">
        <i class="fa-solid fa-triangle-exclamation" style="font-size: 1.2rem;"></i>
        <div>
            <strong>This Production Unit is Currently DISABLED:</strong>
            <span style="font-size: 0.85rem; color: var(--text-muted); display: block;">Registration of new workers and new production entries are paused for this unit.</span>
        </div>
    </div>
    <button type="button" class="btn btn-primary btn-sm" onclick="toggleCurrentUnitStatus(<?= (int)$currentUnitData['id'] ?>, 'Active')">
        <i class="fa-solid fa-circle-check"></i> Click to Re-Enable Unit
    </button>
</div>
<?php endif; ?>

<!-- Dynamic Unit Switcher Tabs -->
<div class="unit-tabs-container mb-4">
    <?php foreach ($productionUnits as $pu): 
        $isActive = ($requestedUnit === $pu['unit_name']);
        $isDisabled = (($pu['status'] ?? 'Active') === 'Disabled');
        $uColor = !empty($pu['color']) ? $pu['color'] : 'cyan';
        $uIcon = !empty($pu['icon']) ? $pu['icon'] : 'fa-industry';
        $uWorkers = (int)$pu['worker_count'];
    ?>
    <a href="unit_workers.php?unit=<?= urlencode($pu['unit_name']) ?>" 
       class="unit-tab-btn <?= $isActive ? 'active-unit-' . htmlspecialchars($uColor) : '' ?>"
       style="<?= $isDisabled ? 'opacity: 0.75; border-style: dashed;' : '' ?>">
        <div class="tab-icon" style="<?= $isDisabled ? 'filter: grayscale(80%);' : '' ?>">
            <i class="fa-solid <?= htmlspecialchars($uIcon) ?>"></i>
        </div>
        <div class="tab-text">
            <span class="tab-title" style="display: flex; align-items: center; gap: 0.5rem;">
                <?= htmlspecialchars($pu['unit_title']) ?>
                <?php if ($isDisabled): ?>
                    <span class="badge-pill" style="font-size: 0.68rem; background: rgba(244,63,94,0.15); color: #f43f5e; border: 1px solid rgba(244,63,94,0.3); padding: 0.1rem 0.45rem;">Disabled</span>
                <?php endif; ?>
            </span>
            <span class="tab-sub"><?= htmlspecialchars($pu['description']) ?></span>
        </div>
        <span class="tab-badge unit-<?= htmlspecialchars($uColor) ?>-pill"><?= number_format($uWorkers) ?> Workers</span>
    </a>
    <?php endforeach; ?>
</div>

<!-- Unit KPI Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon <?= ($currentUnitData['color'] === 'pink') ? 'pink' : (($currentUnitData['color'] === 'emerald') ? 'emerald' : 'cyan') ?>">
            <i class="fa-solid fa-users-gear"></i>
        </div>
        <div class="stat-info">
            <div class="stat-label">Enrolled Workforce</div>
            <div class="stat-value"><?= number_format($unitWorkerCount) ?></div>
            <div class="stat-sub">Active in <?= htmlspecialchars($requestedUnit) ?></div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon emerald">
            <i class="fa-solid fa-boxes-stacked"></i>
        </div>
        <div class="stat-info">
            <div class="stat-label">Total Unit Pieces Produced</div>
            <div class="stat-value text-emerald"><?= number_format($unitTotalPieces) ?></div>
            <div class="stat-sub">Cumulative pieces count</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon amber">
            <i class="fa-solid fa-crown"></i>
        </div>
        <div class="stat-info">
            <div class="stat-label">Unit Top Performer</div>
            <div class="stat-value" style="font-size: 1.15rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%; display: block;" title="<?= $topWorker ? htmlspecialchars($topWorker['name']) : 'N/A' ?>"><?= $topWorker ? htmlspecialchars($topWorker['name']) : 'N/A' ?></div>
            <div class="stat-sub" style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%;"><?= $topWorker ? number_format($topWorker['total_pieces']) . ' pcs (' . htmlspecialchars($topWorker['unique_id']) . ')' : 'No entries yet' ?></div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon primary">
            <i class="fa-solid fa-award"></i>
        </div>
        <div class="stat-info">
            <div class="stat-label">Avg. Experience</div>
            <div class="stat-value"><?= $avgExp ?> <span style="font-size: 1rem; font-weight: normal;">Years</span></div>
            <div class="stat-sub">Unit operational seniority</div>
        </div>
    </div>
</div>

<!-- Workers Directory Card -->
<div class="card">
    <div class="card-header" style="flex-wrap: wrap; gap: 0.75rem; align-items: center; justify-content: space-between;">
        <div class="card-title" style="flex: 1 1 auto; display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
            <span><i class="fa-solid fa-address-book text-cyan"></i> Registered Workers Directory (<?= htmlspecialchars($requestedUnit) ?>)</span>
            <span class="badge-pill" id="headerWorkerCountBadge" style="font-size: 0.75rem; background: rgba(6,182,212,0.12); color: #06b6d4; border: 1px solid rgba(6,182,212,0.3);">
                <?= count($workersList) ?> Total
            </span>
        </div>

        <div style="display: flex; align-items: center; gap: 0.65rem; flex-wrap: wrap;">
            <!-- Active Filter Segmented Toggle Buttons -->
            <div class="active-status-filter-group">
                <button type="button" class="unit-filter-pill active" id="btnFilterAllWorkers" onclick="filterByActiveStatus('all')">
                    <i class="fa-solid fa-users"></i> All (<span id="allCountDisplay"><?= count($workersList) ?></span>)
                </button>
                <button type="button" class="unit-filter-pill filter-pill-active" id="btnFilterActiveWorkers" onclick="filterByActiveStatus('active')">
                    <span class="active-pulse-dot"></span> Active Today (<span id="activeCountDisplay"><?= $activeWorkersCount ?></span>)
                </button>
            </div>

            <!-- Search Input Wrapper -->
            <div class="search-input-wrapper" style="min-width: 240px;">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" id="unitWorkerSearch" class="form-control" placeholder="Search name, ID, role, skills...">
            </div>
        </div>
    </div>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 50px;">#</th>
                    <th>Worker Info</th>
                    <th>Role & Designation</th>
                    <th>Age / Exp</th>
                    <th>Contact Info</th>
                    <th>Extra Skills</th>
                    <th>Total Output</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="unitWorkersTableBody">
                <?php if (empty($workersList)): ?>
                    <tr>
                        <td colspan="8" class="text-center" style="padding: 3rem;">
                            <i class="fa-solid fa-user-slash fa-3x text-muted" style="opacity: 0.5;"></i>
                            <p class="text-muted mt-4 font-bold">No Workers Registered in <?= htmlspecialchars($requestedUnit) ?></p>
                            <a href="index.php?unit=<?= urlencode($requestedUnit) ?>" class="btn btn-primary btn-sm mt-4">
                                <i class="fa-solid fa-user-plus"></i> Register First Worker in <?= htmlspecialchars($requestedUnit) ?>
                            </a>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($workersList as $idx => $w): ?>
                        <tr class="unit-worker-row" 
                            id="workerRow_<?= $w['id'] ?>"
                            data-is-active="<?= !empty($w['is_logged_in_today']) ? '1' : '0' ?>"
                            data-worker-id="<?= $w['id'] ?>"
                            data-worker-name="<?= htmlspecialchars($w['name']) ?>"
                            data-worker-uid="<?= htmlspecialchars($w['unique_id']) ?>"
                            data-worker-unit="<?= htmlspecialchars($w['unit']) ?>"
                            data-worker-role="<?= htmlspecialchars($w['role']) ?>"
                            data-assigned-work="<?= htmlspecialchars($w['active_assignment'] ?? '') ?>">
                            <td class="text-muted font-bold">#<?= $idx + 1 ?></td>
                            <td>
                                <div class="worker-meta-cell">
                                    <div class="worker-avatar"><?= strtoupper(substr($w['name'], 0, 1)) ?></div>
                                    <div>
                                        <div style="display: flex; align-items: center; gap: 0.35rem; flex-wrap: wrap;">
                                            <a href="javascript:void(0)" onclick="openWorkerModal(<?= $w['id'] ?>)" class="worker-meta-name" style="text-decoration:none;">
                                                <?= htmlspecialchars($w['name']) ?>
                                            </a>
                                            <!-- Automatic Tick Symbol generated ONLY for user logged in today -->
                                            <span class="worker-tick-container" id="workerTickContainer_<?= $w['id'] ?>">
                                                <?php if (!empty($w['is_logged_in_today'])): ?>
                                                    <span class="worker-login-tick-badge" id="workerTick_<?= $w['id'] ?>" title="Active Today • Logged in at <?= !empty($w['effective_login']) ? date('h:i A', strtotime($w['effective_login'])) : 'Today' ?>">
                                                        <i class="fa-solid fa-circle-check"></i>
                                                    </span>
                                                <?php endif; ?>
                                            </span>
                                        </div>

                                        <div style="display: flex; align-items: center; gap: 0.35rem; flex-wrap: wrap; margin-top: 0.2rem;">
                                            <span class="worker-meta-sub text-cyan font-bold"><?= htmlspecialchars($w['unique_id']) ?></span>
                                            
                                            <!-- Active / Inactive Status Indicator -->
                                            <?php if (!empty($w['is_logged_in_today'])): ?>
                                                <span class="badge-pill unit-emerald-pill worker-active-pill" id="activePill_<?= $w['id'] ?>" style="font-size: 0.65rem; padding: 0.08rem 0.42rem;">
                                                    <span class="pulse-emerald-dot"></span> Active
                                                </span>
                                            <?php else: ?>
                                                <span class="badge-pill worker-active-pill" id="activePill_<?= $w['id'] ?>" style="font-size: 0.65rem; padding: 0.08rem 0.42rem; background: rgba(148,163,184,0.12); color: #94a3b8; border: 1px solid rgba(148,163,184,0.25);">
                                                    Inactive
                                                </span>
                                            <?php endif; ?>

                                            <?php if (!empty($w['is_switched']) && !empty($w['transferred_from'])): ?>
                                                <span class="badge-pill" style="font-size: 0.65rem; padding: 0.08rem 0.38rem; background: rgba(245, 158, 11, 0.14); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.35); border-radius: 9999px; display: inline-flex; align-items: center; gap: 0.2rem;" title="Switched from <?= htmlspecialchars($w['transferred_from']) ?> (<?= htmlspecialchars($w['transfer_type'] ?? 'Emergency Support') ?>)">
                                                    <i class="fa-solid fa-arrow-right-arrow-left" style="font-size: 0.55rem;"></i> Switched from <?= htmlspecialchars($w['transferred_from']) ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($w['role']) ?></strong>
                                <?php if (!empty($w['active_assignment'])): ?>
                                    <div class="worker-assigned-box-tag" id="assignedTag_<?= $w['id'] ?>" style="margin-top: 0.25rem;">
                                        <span class="badge-pill" style="background: rgba(16,185,129,0.15); color: #34d399; border: 1px solid rgba(16,185,129,0.35); font-size: 0.72rem; display: inline-flex; align-items: center; gap: 0.3rem;" title="Assigned Work Category">
                                            <i class="fa-solid fa-clipboard-check"></i> Work: <strong style="color: #fff;"><?= htmlspecialchars($w['active_assignment']) ?></strong>
                                        </span>
                                    </div>
                                <?php else: ?>
                                    <div class="worker-assigned-box-tag" id="assignedTag_<?= $w['id'] ?>" style="margin-top: 0.25rem; display: none;"></div>
                                    <?php if ($w['latest_material']): ?>
                                        <div class="text-muted" style="font-size: 0.75rem;">Latest: <?= htmlspecialchars($w['latest_material']) ?></div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div><strong><?= $w['age'] ?></strong> yrs old</div>
                                <span class="text-muted" style="font-size: 0.78rem;"><?= $w['experience_years'] ?> yrs exp</span>
                            </td>
                            <td>
                                <div><i class="fa-solid fa-phone text-muted" style="font-size: 0.75rem;"></i> <?= htmlspecialchars($w['mobile_num']) ?></div>
                                <?php if (!empty($w['alt_mobile_num'])): ?>
                                    <div class="text-muted" style="font-size: 0.75rem;"><i class="fa-solid fa-phone-volume" style="font-size: 0.7rem;"></i> <?= htmlspecialchars($w['alt_mobile_num']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($w['extra_skills'])): ?>
                                    <span class="text-muted" style="font-size: 0.82rem;"><?= htmlspecialchars($w['extra_skills']) ?></span>
                                <?php else: ?>
                                    <span class="text-muted" style="font-size: 0.78rem;">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="piece-count-highlight"><?= number_format($w['total_pieces']) ?></span>
                                <span class="text-muted" style="font-size: 0.75rem;"> pcs</span>
                            </td>
                            <td>
                                <div style="display: flex; gap: 0.45rem; align-items: center; flex-wrap: wrap;">
                                    <!-- Assign Work Button: ONLY for users who logged in today! -->
                                    <span class="assign-work-container" id="assignWorkContainer_<?= $w['id'] ?>">
                                        <?php if (!empty($w['is_logged_in_today'])): ?>
                                            <button type="button" 
                                                    class="btn btn-sm btn-assign-work" 
                                                    id="assignWorkBtn_<?= $w['id'] ?>"
                                                    onclick="openAssignWorkModal(<?= $w['id'] ?>, '<?= htmlspecialchars(addslashes($w['name'])) ?>', '<?= htmlspecialchars(addslashes($w['unique_id'])) ?>', '<?= htmlspecialchars(addslashes($w['unit'])) ?>', '<?= htmlspecialchars(addslashes($w['role'])) ?>', '<?= htmlspecialchars(addslashes($w['active_assignment'] ?? '')) ?>')"
                                                    title="Assign Garment Production Categories">
                                                <i class="fa-solid fa-clipboard-list"></i> Assign Work
                                            </button>
                                        <?php else: ?>
                                            <span class="badge-pill" style="font-size: 0.72rem; background: rgba(245,158,11,0.12); color: #fbbf24; border: 1px solid rgba(245,158,11,0.3); padding: 0.28rem 0.55rem; display: inline-flex; align-items: center; gap: 0.35rem;" title="Daily login required before work can be assigned">
                                                <i class="fa-solid fa-user-clock"></i> Login Required
                                            </span>
                                        <?php endif; ?>
                                    </span>

                                    <!-- Quick Active Toggle Button (Activate / Deactivate) -->
                                    <button type="button" 
                                            class="btn btn-outline btn-sm btn-quick-active <?= !empty($w['is_logged_in_today']) ? 'is-active-btn' : 'is-inactive-btn' ?>"
                                            id="toggleActiveBtn_<?= $w['id'] ?>"
                                            onclick="quickToggleWorkerActive(<?= $w['id'] ?>)"
                                            title="<?= !empty($w['is_logged_in_today']) ? 'Active Today • Click to mark Inactive' : 'Click to Activate for Today & generate tick' ?>">
                                        <i class="fa-solid <?= !empty($w['is_logged_in_today']) ? 'fa-circle-check text-emerald' : 'fa-power-off text-muted' ?>"></i>
                                        <span class="btn-active-label"><?= !empty($w['is_logged_in_today']) ? 'Active' : 'Activate' ?></span>
                                    </button>

                                    <button type="button" class="btn btn-outline btn-sm" onclick="openWorkerModal(<?= $w['id'] ?>)" title="View Full Profile">
                                        <i class="fa-solid fa-eye"></i> Details
                                    </button>
                                    <a href="daily_output.php?worker_id=<?= $w['id'] ?>&unit=<?= urlencode($requestedUnit) ?>" class="btn btn-primary btn-sm" title="Open Stitched Clothes Workstation">
                                        <i class="fa-solid fa-shirt"></i> Workstation
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Assign Work Box & Categories for Logged-In Worker -->
<div id="assignWorkModal" class="modal-overlay" style="display: none;" aria-hidden="true">
    <div class="modal-card" style="max-width: 820px; width: 95%; max-height: 90vh; display: flex; flex-direction: column;">
        <div class="modal-header" style="border-bottom: 1px solid rgba(255,255,255,0.08); padding: 1.15rem 1.4rem;">
            <div class="modal-title-group" style="display: flex; align-items: center; gap: 0.75rem;">
                <div style="width: 44px; height: 44px; border-radius: 12px; background: rgba(16,185,129,0.15); border: 1px solid rgba(16,185,129,0.35); display: flex; align-items: center; justify-content: center; color: #10b981; font-size: 1.25rem; box-shadow: 0 0 18px rgba(16,185,129,0.25);">
                    <i class="fa-solid fa-clipboard-check"></i>
                </div>
                <div>
                    <h3 style="margin: 0; font-size: 1.15rem; font-weight: 700; color: #fff; display: flex; align-items: center; gap: 0.5rem;">
                        Assign Work to Worker
                        <span class="badge-pill unit-emerald-pill" style="font-size: 0.7rem;">Active Today</span>
                    </h3>
                    <p class="text-muted" style="margin: 0.15rem 0 0 0; font-size: 0.78rem;">
                        Select garment category, subcategory and garment type to assign work to this operator.
                    </p>
                </div>
            </div>
            <button type="button" class="modal-close-btn" onclick="closeAssignWorkModal()">&times;</button>
        </div>

        <div class="modal-body" style="padding: 1.25rem 1.4rem; overflow-y: auto; flex: 1 1 auto;">
            <!-- Target Worker Banner -->
            <div style="background: rgba(15,23,42,0.9); border: 1px solid rgba(16,185,129,0.3); border-radius: 10px; padding: 0.95rem 1.15rem; margin-bottom: 1.15rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.75rem;">
                <div style="display: flex; align-items: center; gap: 0.75rem;">
                    <div id="assignModalAvatar" style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, #06b6d4, #3b82f6); color: #fff; font-weight: 800; display: flex; align-items: center; justify-content: center; font-size: 1.1rem;">
                        W
                    </div>
                    <div>
                        <div style="display: flex; align-items: center; gap: 0.45rem;">
                            <strong id="assignModalWorkerName" style="color: #fff; font-size: 0.98rem;">Worker Name</strong>
                            <span class="worker-login-tick-badge" title="Logged in today"><i class="fa-solid fa-circle-check"></i></span>
                            <span id="assignModalWorkerId" class="text-cyan font-bold" style="font-size: 0.8rem;">PU001-1</span>
                        </div>
                        <div style="font-size: 0.75rem; color: var(--text-muted); display: flex; align-items: center; gap: 0.5rem; margin-top: 0.1rem;">
                            <span id="assignModalWorkerUnit"><i class="fa-solid fa-industry"></i> Unit 1</span>
                            <span>•</span>
                            <span id="assignModalWorkerRole">Operator</span>
                        </div>
                    </div>
                </div>

                <div style="text-align: right;">
                    <div style="font-size: 0.72rem; color: var(--text-muted); margin-bottom: 0.15rem;">Current Work Assignment:</div>
                    <div id="assignModalCurrentWorkBadge" class="badge-pill" style="background: rgba(16,185,129,0.15); color: #34d399; border: 1px solid rgba(16,185,129,0.35); font-weight: 700; font-size: 0.8rem;">
                        <i class="fa-solid fa-clipboard-check"></i> <span id="assignModalCurrentWorkText">None</span>
                    </div>
                </div>
            </div>

            <!-- Categories Multi-Selection Toolbar & Search Box -->
            <div style="display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; flex-wrap: wrap; margin-bottom: 0.85rem;">
                <div style="display: flex; align-items: center; gap: 0.55rem; flex-wrap: wrap;">
                    <h4 style="font-size: 0.92rem; font-weight: 700; color: #fff; margin: 0; display: flex; align-items: center; gap: 0.45rem;">
                        <i class="fa-solid fa-layer-group text-cyan"></i> Select Production Categories
                    </h4>
                    <span class="badge-pill unit-emerald-pill" id="selectedCatCounterBadge" style="font-size: 0.75rem; font-weight: 700;">
                        <i class="fa-solid fa-check-double"></i> <span id="selectedCatCountText">0 Selected</span>
                    </span>
                    <button type="button" class="btn btn-outline btn-xs" onclick="selectAllCategories()" style="font-size: 0.72rem; padding: 0.18rem 0.55rem; color: #38bdf8;">
                        Select All
                    </button>
                    <button type="button" class="btn btn-outline btn-xs" onclick="clearSelectedCategories()" style="font-size: 0.72rem; padding: 0.18rem 0.55rem; color: #f43f5e;">
                        Clear
                    </button>
                </div>
                <div class="search-input-wrapper" style="min-width: 200px; max-width: 260px;">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" id="assignCatSearchInput" class="form-control" placeholder="Search categories..." style="height: 34px; font-size: 0.78rem;">
                </div>
            </div>

            <!-- Category Selection Grid -->
            <div class="assign-cat-grid" id="assignCatCardsContainer">
                <?php if (empty($activeGarmentCategories)): ?>
                    <div style="grid-column: 1 / -1; text-align: center; padding: 2rem; color: var(--text-muted);">
                        <i class="fa-solid fa-boxes-stacked fa-2x" style="opacity: 0.3; margin-bottom: 0.5rem;"></i>
                        <p>No active categories configured. Add categories in <a href="categories.php" class="text-cyan">Garment Categories</a>.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($activeGarmentCategories as $cat): 
                        $fullTitle = htmlspecialchars($cat['category_name']);
                        $sub = htmlspecialchars($cat['subcategory_name']);
                        $subsub = htmlspecialchars($cat['sub_subcategory_name'] ?? '');
                        $comboKey = $cat['category_name'] . ($sub ? ' - ' . $sub : '') . ($subsub ? ' - ' . $subsub : '');
                    ?>
                        <div class="assign-cat-card" 
                             id="assignCard_<?= (int)$cat['id'] ?>"
                             data-cat-id="<?= (int)$cat['id'] ?>"
                             data-cat-name="<?= htmlspecialchars($cat['category_name']) ?>"
                             data-subcat-name="<?= htmlspecialchars($cat['subcategory_name']) ?>"
                             data-subsub-name="<?= htmlspecialchars($cat['sub_subcategory_name'] ?? '') ?>"
                             data-search="<?= strtolower(htmlspecialchars($comboKey)) ?>"
                             onclick="toggleCategoryCardSelection(<?= (int)$cat['id'] ?>)">
                            <div>
                                <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 0.5rem; margin-bottom: 0.35rem;">
                                    <div style="display: flex; align-items: center; gap: 0.45rem;">
                                        <div style="width: 28px; height: 28px; border-radius: 7px; background: rgba(6,182,212,0.14); color: #06b6d4; display: flex; align-items: center; justify-content: center; font-size: 0.85rem;">
                                            <i class="fa-solid fa-shirt"></i>
                                        </div>
                                        <strong style="color: #fff; font-size: 0.9rem;"><?= $fullTitle ?></strong>
                                    </div>
                                    <div class="cat-card-check-box" id="catCheck_<?= (int)$cat['id'] ?>">
                                        <i class="fa-solid fa-check"></i>
                                    </div>
                                </div>
                                <div style="font-size: 0.78rem; color: var(--text-muted); margin-bottom: 0.2rem;">
                                    <i class="fa-solid fa-tag text-cyan" style="font-size: 0.68rem;"></i> <?= $sub ?>
                                </div>
                                <?php if (!empty($subsub)): ?>
                                    <div style="font-size: 0.72rem; color: var(--text-secondary); background: rgba(0,0,0,0.25); padding: 0.15rem 0.4rem; border-radius: 4px; display: inline-block;">
                                        Type: <?= $subsub ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <button type="button" 
                                    class="btn btn-outline btn-sm btn-select-cat" 
                                    id="catSelectBtn_<?= (int)$cat['id'] ?>"
                                    onclick="event.stopPropagation(); toggleCategoryCardSelection(<?= (int)$cat['id'] ?>)"
                                    style="width: 100%; font-size: 0.75rem; padding: 0.32rem 0.5rem; justify-content: center;">
                                <i class="fa-solid fa-plus"></i> Select
                            </button>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Optional Custom Target / Notes Field -->
            <div style="margin-top: 1.15rem; background: rgba(0,0,0,0.25); border: 1px solid rgba(255,255,255,0.06); border-radius: 8px; padding: 0.85rem;">
                <label style="font-size: 0.78rem; font-weight: 600; color: #fff; margin-bottom: 0.35rem; display: flex; align-items: center; gap: 0.35rem;">
                    <i class="fa-solid fa-pen text-cyan"></i> Optional Production Instructions / Batch Notes
                </label>
                <input type="text" id="assignModalNotesInput" class="form-control" placeholder="e.g. Target: 50 pieces today, check stitching alignment..." style="font-size: 0.8rem; height: 36px;">
            </div>
        </div>

        <div class="modal-footer" style="border-top: 1px solid rgba(255,255,255,0.08); padding: 0.85rem 1.4rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.75rem;">
            <span class="text-muted" style="font-size: 0.75rem;">
                <i class="fa-solid fa-circle-info text-cyan"></i> Multi-select categories for operator. Workstation will show subcategories for all selected.
            </span>
            <div style="display: flex; align-items: center; gap: 0.65rem;">
                <button type="button" class="btn btn-outline btn-sm" onclick="closeAssignWorkModal()">
                    Close
                </button>
                <button type="button" class="btn btn-primary btn-sm" id="btnSaveCategoryAssignments" onclick="saveSelectedCategoryAssignments()" style="padding: 0.45rem 1.35rem; font-weight: 700; background: linear-gradient(135deg, #10b981, #059669); border: none; box-shadow: 0 4px 14px rgba(16,185,129,0.35); display: inline-flex; align-items: center; gap: 0.45rem;">
                    <i class="fa-solid fa-floppy-disk"></i> Save Assignment
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Manage Production Units Modal -->
<div id="unitsModal" class="modal-overlay" aria-hidden="true" style="display: none;">
    <div class="modal-card" style="max-width: 720px; width: 95%;">
        <div class="modal-header">
            <div class="modal-title-group">
                <div class="stat-icon cyan" style="width: 42px; height: 42px; font-size: 1.25rem;">
                    <i class="fa-solid fa-industry"></i>
                </div>
                <div>
                    <h3>Production Units Configuration</h3>
                    <p class="text-muted" style="font-size: 0.8rem;">Add and manage manufacturing production units and worker ID series</p>
                </div>
            </div>
            <button class="modal-close-btn" onclick="closeUnitsModal()">&times;</button>
        </div>

        <div class="modal-body" style="padding: 1.5rem;">
            <div id="unitFormAlert" class="alert-box" style="display: none; margin-bottom: 1.25rem;"></div>

            <!-- Form: Add New Unit -->
            <div class="card" style="margin-bottom: 1.5rem; background: rgba(255,255,255,0.02); border-color: rgba(6, 182, 212, 0.3);">
                <div class="card-header" style="padding: 0.85rem 1.25rem;">
                    <div class="card-title" style="font-size: 0.95rem;">
                        <i class="fa-solid fa-plus-circle text-cyan"></i> Add New Production Unit
                    </div>
                </div>
                <div style="padding: 1.25rem;">
                    <form id="addUnitForm" autocomplete="off">
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                            <div>
                                <label class="form-label" style="font-size: 0.82rem;"><i class="fa-solid fa-tag"></i> Unit Short Name <span class="required">*</span></label>
                                <input type="text" id="newUnitNameInput" class="form-control" placeholder="e.g. Unit 3" required>
                                <span class="text-muted" style="font-size: 0.72rem;">Database identifier (e.g. Unit 3)</span>
                            </div>
                            <div>
                                <label class="form-label" style="font-size: 0.82rem;"><i class="fa-solid fa-building"></i> Display Title <span class="required">*</span></label>
                                <input type="text" id="newUnitTitleInput" class="form-control" placeholder="e.g. Production Unit 3" required>
                                <span class="text-muted" style="font-size: 0.72rem;">Shown on badges and headers</span>
                            </div>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                            <div>
                                <label class="form-label" style="font-size: 0.82rem;"><i class="fa-solid fa-fingerprint"></i> Worker ID Prefix <span class="required">*</span></label>
                                <input type="text" id="newUnitCodeInput" class="form-control font-bold text-cyan" placeholder="e.g. PU003" required>
                                <span class="text-muted" style="font-size: 0.72rem;">Workers will get: <strong><span id="prefixPreview">PU003-1, 2, 3...</span></strong></span>
                            </div>
                            <div>
                                <label class="form-label" style="font-size: 0.82rem;"><i class="fa-solid fa-palette"></i> Theme Color</label>
                                <select id="newUnitColorInput" class="form-control">
                                    <option value="cyan">Cyan Blue</option>
                                    <option value="pink">Rose Pink</option>
                                    <option value="emerald" selected>Emerald Green</option>
                                    <option value="amber">Amber Gold</option>
                                    <option value="purple">Purple</option>
                                    <option value="blue">Royal Blue</option>
                                </select>
                            </div>
                        </div>

                        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1rem; margin-bottom: 1.25rem;">
                            <div>
                                <label class="form-label" style="font-size: 0.82rem;"><i class="fa-solid fa-circle-info"></i> Department Specialization / Description</label>
                                <input type="text" id="newUnitDescInput" class="form-control" placeholder="e.g. Embroidery, Finishing & Quality Control">
                            </div>
                            <div>
                                <label class="form-label" style="font-size: 0.82rem;"><i class="fa-solid fa-icons"></i> Icon</label>
                                <select id="newUnitIconInput" class="form-control">
                                    <option value="fa-industry">Factory (fa-industry)</option>
                                    <option value="fa-building-gear">Building Cogs (fa-building-gear)</option>
                                    <option value="fa-warehouse">Warehouse (fa-warehouse)</option>
                                    <option value="fa-scissors">Scissors (fa-scissors)</option>
                                    <option value="fa-shirt">Garment (fa-shirt)</option>
                                    <option value="fa-boxes-stacked">Boxes (fa-boxes-stacked)</option>
                                </select>
                            </div>
                        </div>

                        <div style="display: flex; justify-content: flex-end;">
                            <button type="submit" id="saveUnitBtn" class="btn btn-primary">
                                <i class="fa-solid fa-plus"></i> Save & Activate Production Unit
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- List: Existing Units -->
            <div>
                <h4 style="font-size: 0.95rem; margin-bottom: 0.75rem; display: flex; align-items: center; justify-content: space-between;">
                    <span><i class="fa-solid fa-layer-group text-cyan"></i> Active Production Units</span>
                    <span class="badge-pill" id="unitsModalCount"><?= count($productionUnits) ?> Units Registered</span>
                </h4>
                <div class="table-responsive" style="max-height: 250px; overflow-y: auto;">
                    <table class="data-table" style="font-size: 0.85rem;">
                        <thead>
                            <tr>
                                <th>Unit Title</th>
                                <th>ID Prefix</th>
                                <th>Status</th>
                                <th>Workforce</th>
                                <th>Pieces</th>
                                <th style="text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="unitsModalTableBody">
                            <?php foreach ($productionUnits as $uRow): 
                                $isUnitDisabled = (($uRow['status'] ?? 'Active') === 'Disabled');
                            ?>
                                <tr id="unit-row-<?= $uRow['id'] ?>" style="<?= $isUnitDisabled ? 'opacity: 0.75;' : '' ?>">
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                                            <i class="fa-solid <?= htmlspecialchars($uRow['icon'] ?: 'fa-industry') ?> text-cyan"></i>
                                            <div>
                                                <strong><?= htmlspecialchars($uRow['unit_title']) ?></strong>
                                                <div class="text-muted" style="font-size: 0.72rem;"><?= htmlspecialchars($uRow['description']) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge-pill font-bold text-cyan"><?= htmlspecialchars($uRow['unit_code']) ?>-X</span>
                                    </td>
                                    <td>
                                        <?php if ($isUnitDisabled): ?>
                                            <span class="badge-pill" style="background: rgba(244,63,94,0.15); color: #f43f5e; border: 1px solid rgba(244,63,94,0.3);">
                                                <i class="fa-solid fa-ban"></i> Disabled
                                            </span>
                                        <?php else: ?>
                                            <span class="badge-pill unit-emerald-pill">
                                                <i class="fa-solid fa-circle-check"></i> Active
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge-pill"><?= number_format((int)$uRow['worker_count']) ?> workers</span>
                                    </td>
                                    <td>
                                        <strong><?= number_format((int)$uRow['total_pieces']) ?></strong>
                                    </td>
                                    <td style="text-align: right;">
                                        <div style="display: flex; gap: 0.4rem; justify-content: flex-end;">
                                            <?php if ($isUnitDisabled): ?>
                                                <button type="button" class="btn btn-outline btn-sm" onclick="toggleModalUnitStatus(<?= $uRow['id'] ?>, 'Active', '<?= htmlspecialchars(addslashes($uRow['unit_title'])) ?>')" style="color: var(--accent-emerald); border-color: rgba(16,185,129,0.4);" title="Click to Enable this Unit">
                                                    <i class="fa-solid fa-circle-check"></i> Enable
                                                </button>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-outline btn-sm" onclick="toggleModalUnitStatus(<?= $uRow['id'] ?>, 'Disabled', '<?= htmlspecialchars(addslashes($uRow['unit_title'])) ?>')" style="color: #f59e0b; border-color: rgba(245,158,11,0.4);" title="Click to Disable this Unit">
                                                    <i class="fa-solid fa-ban"></i> Disable
                                                </button>
                                            <?php endif; ?>

                                            <?php if ((int)$uRow['worker_count'] > 0): ?>
                                                <button class="btn btn-outline btn-sm" disabled title="Cannot delete: <?= (int)$uRow['worker_count'] ?> active workers" style="opacity: 0.45; cursor: not-allowed;">
                                                    <i class="fa-solid fa-lock"></i>
                                                </button>
                                            <?php else: ?>
                                                <button class="btn btn-outline btn-sm" onclick="deleteProductionUnit(<?= $uRow['id'] ?>, '<?= htmlspecialchars(addslashes($uRow['unit_title'])) ?>')" style="color: var(--accent-rose); border-color: rgba(244,63,94,0.3);" title="Delete Unit">
                                                    <i class="fa-solid fa-trash"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // 1. Worker search filtering
    const searchInput = document.getElementById('unitWorkerSearch');
    const rows = document.querySelectorAll('.unit-worker-row');

    if (searchInput && rows.length > 0) {
        searchInput.addEventListener('input', () => {
            const query = searchInput.value.toLowerCase().trim();
            rows.forEach(row => {
                const text = row.innerText.toLowerCase();
                row.style.display = text.includes(query) ? '' : 'none';
            });
        });
    }

    // 2. Auto-suggest unit code as user types unit name
    const unitNameInput = document.getElementById('newUnitNameInput');
    const unitTitleInput = document.getElementById('newUnitTitleInput');
    const unitCodeInput = document.getElementById('newUnitCodeInput');
    const prefixPreview = document.getElementById('prefixPreview');

    if (unitNameInput && unitCodeInput) {
        unitNameInput.addEventListener('input', () => {
            const val = unitNameInput.value.trim();
            if (val) {
                // Auto fill display title if empty or matching default
                if (unitTitleInput && (!unitTitleInput.value || unitTitleInput.value.startsWith('Production Unit'))) {
                    unitTitleInput.value = val.toLowerCase().startsWith('production') ? val : 'Production ' + val;
                }
                
                // Auto generate prefix
                const match = val.match(/(\d+)/);
                if (match) {
                    const num = parseInt(match[1], 10);
                    const code = 'PU' + String(num).padStart(3, '0');
                    unitCodeInput.value = code;
                    if (prefixPreview) prefixPreview.innerText = code + '-1, 2, 3...';
                } else {
                    const clean = val.replace(/[^a-zA-Z0-9]/g, '').toUpperCase().slice(0, 3);
                    const code = 'PU' + (clean || '003');
                    unitCodeInput.value = code;
                    if (prefixPreview) prefixPreview.innerText = code + '-1, 2, 3...';
                }
            }
        });

        unitCodeInput.addEventListener('input', () => {
            const code = unitCodeInput.value.trim().toUpperCase();
            if (prefixPreview) prefixPreview.innerText = (code || 'PU003') + '-1, 2, 3...';
        });
    }

    // 3. Form submission to add unit
    const addUnitForm = document.getElementById('addUnitForm');
    const unitFormAlert = document.getElementById('unitFormAlert');
    const saveUnitBtn = document.getElementById('saveUnitBtn');

    if (addUnitForm) {
        addUnitForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const unitName = document.getElementById('newUnitNameInput').value.trim();
            const unitTitle = document.getElementById('newUnitTitleInput').value.trim();
            const unitCode = document.getElementById('newUnitCodeInput').value.trim().toUpperCase();
            const description = document.getElementById('newUnitDescInput').value.trim();
            const color = document.getElementById('newUnitColorInput').value;
            const icon = document.getElementById('newUnitIconInput').value;

            if (!unitName || !unitCode) return;

            if (saveUnitBtn) saveUnitBtn.disabled = true;
            try {
                const res = await fetch('api.php?action=add_production_unit', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        unit_name: unitName,
                        unit_title: unitTitle,
                        unit_code: unitCode,
                        description: description,
                        color: color,
                        icon: icon
                    })
                });
                const data = await res.json();
                if (data.success) {
                    if (unitFormAlert) {
                        unitFormAlert.className = 'alert-box alert-success';
                        unitFormAlert.style.display = 'block';
                        unitFormAlert.innerHTML = '<i class="fa-solid fa-circle-check"></i> ' + data.message;
                    }
                    if (typeof showToast === 'function') {
                        showToast(data.message, 'success');
                    }
                    setTimeout(() => {
                        window.location.href = 'unit_workers.php?unit=' + encodeURIComponent(data.unit_name);
                    }, 1200);
                } else {
                    if (unitFormAlert) {
                        unitFormAlert.className = 'alert-box alert-danger';
                        unitFormAlert.style.display = 'block';
                        unitFormAlert.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> ' + (data.message || 'Failed to add unit.');
                    }
                }
            } catch (err) {
                console.error(err);
                if (unitFormAlert) {
                    unitFormAlert.className = 'alert-box alert-danger';
                    unitFormAlert.style.display = 'block';
                    unitFormAlert.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> Server or network error.';
                }
            } finally {
                if (saveUnitBtn) saveUnitBtn.disabled = false;
            }
        });
    }
});

function openUnitsModal() {
    const modal = document.getElementById('unitsModal');
    if (modal) {
        modal.style.display = 'flex';
        const nameInput = document.getElementById('newUnitNameInput');
        if (nameInput) nameInput.focus();
    }
}

function closeUnitsModal() {
    const modal = document.getElementById('unitsModal');
    if (modal) modal.style.display = 'none';
}

window.addEventListener('click', (e) => {
    const modal = document.getElementById('unitsModal');
    if (e.target === modal) closeUnitsModal();
});

async function toggleCurrentUnitStatus(unitId, desiredStatus) {
    const actionLabel = (desiredStatus === 'Disabled') ? 'disable' : 'enable';
    if (!confirm(`Are you sure you want to ${actionLabel} this Production Unit?`)) return;

    try {
        const res = await fetch('api.php?action=toggle_unit_status', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: unitId, status: desiredStatus })
        });
        const data = await res.json();
        if (data.success) {
            if (typeof showToast === 'function') showToast(data.message, 'success');
            setTimeout(() => window.location.reload(), 500);
        } else {
            alert(data.message || 'Failed to update status.');
        }
    } catch (e) {
        console.error(e);
        alert('Server error.');
    }
}

async function toggleModalUnitStatus(unitId, desiredStatus, unitTitle) {
    const actionLabel = (desiredStatus === 'Disabled') ? 'disable' : 'enable';
    if (!confirm(`Are you sure you want to ${actionLabel} "${unitTitle}"?`)) return;

    try {
        const res = await fetch('api.php?action=toggle_unit_status', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: unitId, status: desiredStatus })
        });
        const data = await res.json();
        if (data.success) {
            if (typeof showToast === 'function') showToast(data.message, 'success');
            setTimeout(() => window.location.reload(), 500);
        } else {
            alert(data.message || 'Failed to update status.');
        }
    } catch (e) {
        console.error(e);
        alert('Server error.');
    }
}

async function deleteProductionUnit(unitId, unitTitle) {
    if (!confirm(`Are you sure you want to delete "${unitTitle}"? This cannot be undone.`)) {
        return;
    }

    try {
        const res = await fetch('api.php?action=delete_production_unit', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: unitId })
        });
        const data = await res.json();
        if (data.success) {
            if (typeof showToast === 'function') {
                showToast(data.message, 'success');
            }
            const row = document.getElementById('unit-row-' + unitId);
            if (row) row.remove();
            setTimeout(() => {
                window.location.href = 'unit_workers.php';
            }, 800);
        } else {
            alert(data.message || 'Cannot delete unit.');
        }
    } catch (err) {
        console.error(err);
        alert('Server error while deleting unit.');
    }
}

// ===================================================================
// ACTIVE WORKERS FILTER & ASSIGN WORK LOGIC
// ===================================================================
let currentActiveFilter = 'all';
let currentAssignWorkerId = null;

function filterByActiveStatus(mode) {
    currentActiveFilter = mode;
    const btnAll = document.getElementById('btnFilterAllWorkers');
    const btnActive = document.getElementById('btnFilterActiveWorkers');

    if (btnAll && btnActive) {
        if (mode === 'active') {
            btnAll.classList.remove('active');
            btnActive.classList.add('active');
        } else {
            btnActive.classList.remove('active');
            btnAll.classList.add('active');
        }
    }

    applyWorkersTableFilters();
}

function applyWorkersTableFilters() {
    const searchInput = document.getElementById('unitWorkerSearch');
    const query = searchInput ? searchInput.value.toLowerCase().trim() : '';
    const rows = document.querySelectorAll('.unit-worker-row');
    let visibleCount = 0;

    rows.forEach(row => {
        const isActive = row.getAttribute('data-is-active') === '1';
        const text = row.innerText.toLowerCase();
        const matchesSearch = !query || text.includes(query);
        const matchesActive = (currentActiveFilter === 'all') || (currentActiveFilter === 'active' && isActive);

        if (matchesSearch && matchesActive) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });

    const tbody = document.getElementById('unitWorkersTableBody');
    let emptyRow = document.getElementById('filterEmptyStateRow');

    if (visibleCount === 0 && rows.length > 0) {
        if (!emptyRow && tbody) {
            emptyRow = document.createElement('tr');
            emptyRow.id = 'filterEmptyStateRow';
            emptyRow.innerHTML = `
                <td colspan="8" class="text-center" style="padding: 2.5rem 1rem; color: var(--text-muted);">
                    <i class="fa-solid fa-user-clock fa-2x" style="opacity: 0.35; margin-bottom: 0.5rem; display: block;"></i>
                    <strong style="color: #fff;">No Active Workers Found Today in This Unit</strong>
                    <p style="font-size: 0.8rem; margin: 0.35rem 0 0 0;">Workers must sign in today or click 'Activate' to appear here.</p>
                </td>
            `;
            tbody.appendChild(emptyRow);
        }
    } else if (emptyRow) {
        emptyRow.remove();
    }
}

// Update table search listener to respect active filter
document.addEventListener('DOMContentLoaded', () => {
    const searchInput = document.getElementById('unitWorkerSearch');
    if (searchInput) {
        searchInput.addEventListener('input', applyWorkersTableFilters);
    }

    // Modal category search filter
    const catSearch = document.getElementById('assignCatSearchInput');
    if (catSearch) {
        catSearch.addEventListener('input', () => {
            const q = catSearch.value.toLowerCase().trim();
            const cards = document.querySelectorAll('.assign-cat-card');
            cards.forEach(card => {
                const s = card.getAttribute('data-search') || '';
                card.style.display = (!q || s.includes(q)) ? '' : 'none';
            });
        });
    }

    // Close assign modal on outside click
    window.addEventListener('click', (e) => {
        const assignModal = document.getElementById('assignWorkModal');
        if (e.target === assignModal) closeAssignWorkModal();
    });
});

async function quickToggleWorkerActive(workerId) {
    const row = document.getElementById('workerRow_' + workerId);
    if (!row) return;

    const isCurrentlyActive = (row.getAttribute('data-is-active') === '1');
    const newStatus = !isCurrentlyActive;

    try {
        const res = await fetch('api.php?action=toggle_worker_active', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ worker_id: workerId, status: newStatus ? 'active' : 'inactive' })
        });
        const data = await res.json();

        if (data.success) {
            row.setAttribute('data-is-active', data.is_active ? '1' : '0');

            // 1. Tick Symbol next to worker name (ONLY for active/logged-in worker)
            const tickContainer = document.getElementById('workerTickContainer_' + workerId);
            if (tickContainer) {
                if (data.is_active) {
                    tickContainer.innerHTML = `
                        <span class="worker-login-tick-badge" id="workerTick_${workerId}" title="Active Today • Logged In at ${data.formatted_time || 'Today'}">
                            <i class="fa-solid fa-circle-check"></i>
                        </span>
                    `;
                } else {
                    tickContainer.innerHTML = '';
                }
            }

            // 2. Active status pill next to ID
            const activePill = document.getElementById('activePill_' + workerId);
            if (activePill) {
                if (data.is_active) {
                    activePill.className = 'badge-pill unit-emerald-pill worker-active-pill';
                    activePill.innerHTML = '<span class="pulse-emerald-dot"></span> Active';
                } else {
                    activePill.className = 'badge-pill worker-active-pill';
                    activePill.style.background = 'rgba(148,163,184,0.12)';
                    activePill.style.color = '#94a3b8';
                    activePill.style.border = '1px solid rgba(148,163,184,0.25)';
                    activePill.innerHTML = 'Inactive';
                }
            }

            // 3. Assign Work button: ONLY visible for logged-in / active user!
            const assignContainer = document.getElementById('assignWorkContainer_' + workerId);
            if (assignContainer) {
                if (data.is_active) {
                    const wName = (row.getAttribute('data-worker-name') || '').replace(/'/g, "\\'");
                    const wUid = (row.getAttribute('data-worker-uid') || '').replace(/'/g, "\\'");
                    const wUnit = (row.getAttribute('data-worker-unit') || '').replace(/'/g, "\\'");
                    const wRole = (row.getAttribute('data-worker-role') || '').replace(/'/g, "\\'");
                    const wAssigned = (row.getAttribute('data-assigned-work') || '').replace(/'/g, "\\'");
                    assignContainer.innerHTML = `
                        <button type="button" 
                                class="btn btn-sm btn-assign-work" 
                                id="assignWorkBtn_${workerId}"
                                onclick="openAssignWorkModal(${workerId}, '${wName}', '${wUid}', '${wUnit}', '${wRole}', '${wAssigned}')"
                                title="Assign Garment Production Categories">
                            <i class="fa-solid fa-clipboard-list"></i> Assign Work
                        </button>
                    `;
                } else {
                    assignContainer.innerHTML = `
                        <span class="badge-pill" style="font-size: 0.72rem; background: rgba(245,158,11,0.12); color: #fbbf24; border: 1px solid rgba(245,158,11,0.3); padding: 0.28rem 0.55rem; display: inline-flex; align-items: center; gap: 0.35rem;" title="Daily login required before work can be assigned">
                            <i class="fa-solid fa-user-clock"></i> Login Required
                        </span>
                    `;
                }
            }

            // 4. Quick Active button in actions
            const toggleBtn = document.getElementById('toggleActiveBtn_' + workerId);
            if (toggleBtn) {
                if (data.is_active) {
                    toggleBtn.className = 'btn btn-outline btn-sm btn-quick-active is-active-btn';
                    toggleBtn.title = 'Active Today • Click to mark Inactive';
                    toggleBtn.innerHTML = '<i class="fa-solid fa-circle-check text-emerald"></i> <span class="btn-active-label">Active</span>';
                } else {
                    toggleBtn.className = 'btn btn-outline btn-sm btn-quick-active is-inactive-btn';
                    toggleBtn.title = 'Click to Activate for Today & generate tick';
                    toggleBtn.innerHTML = '<i class="fa-solid fa-power-off text-muted"></i> <span class="btn-active-label">Activate</span>';
                }
            }

            // 5. Update header Active Today counter badge
            const activeCountEl = document.getElementById('activeCountDisplay');
            if (activeCountEl) {
                const totalActive = document.querySelectorAll('.unit-worker-row[data-is-active="1"]').length;
                activeCountEl.innerText = totalActive;
            }

            if (typeof showToast === 'function') {
                showToast(data.message, data.is_active ? 'success' : 'info');
            }

            // Re-apply filter if on active tab
            if (currentActiveFilter === 'active') {
                applyWorkersTableFilters();
            }
        } else {
            alert(data.message || 'Failed to update active status.');
        }
    } catch (err) {
        console.error(err);
        alert('Server error while toggling active status.');
    }
}

let selectedCategoryIds = new Set();

function updateSelectedCategoriesCount() {
    const count = selectedCategoryIds.size;
    const txt = document.getElementById('selectedCatCountText');
    if (txt) {
        txt.innerText = count + (count === 1 ? ' Category Selected' : ' Categories Selected');
    }
}

function toggleCategoryCardSelection(catId) {
    const card = document.getElementById('assignCard_' + catId);
    const btn = document.getElementById('catSelectBtn_' + catId);
    if (!card) return;

    if (selectedCategoryIds.has(catId)) {
        selectedCategoryIds.delete(catId);
        card.classList.remove('is-selected');
        if (btn) {
            btn.className = 'btn btn-outline btn-sm btn-select-cat';
            btn.innerHTML = '<i class="fa-solid fa-plus"></i> Select';
        }
    } else {
        selectedCategoryIds.add(catId);
        card.classList.add('is-selected');
        if (btn) {
            btn.className = 'btn btn-primary btn-sm btn-select-cat is-selected';
            btn.innerHTML = '<i class="fa-solid fa-check"></i> Selected';
        }
    }
    updateSelectedCategoriesCount();
}

function selectAllCategories() {
    const visibleCards = document.querySelectorAll('.assign-cat-card');
    visibleCards.forEach(card => {
        if (card.style.display !== 'none') {
            const cid = parseInt(card.getAttribute('data-cat-id'), 10);
            if (cid && !selectedCategoryIds.has(cid)) {
                toggleCategoryCardSelection(cid);
            }
        }
    });
}

function clearSelectedCategories() {
    const cards = document.querySelectorAll('.assign-cat-card');
    cards.forEach(card => {
        const cid = parseInt(card.getAttribute('data-cat-id'), 10);
        if (cid && selectedCategoryIds.has(cid)) {
            toggleCategoryCardSelection(cid);
        }
    });
    selectedCategoryIds.clear();
    updateSelectedCategoriesCount();
}

function openAssignWorkModal(workerId, name, uid, unit, role, assignedWork) {
    currentAssignWorkerId = workerId;
    const modal = document.getElementById('assignWorkModal');
    if (!modal) return;

    // Fill worker info
    const row = document.getElementById('workerRow_' + workerId);
    const workerName = name || (row ? row.getAttribute('data-worker-name') : 'Worker');
    const workerUid = uid || (row ? row.getAttribute('data-worker-uid') : 'PU001');
    const workerUnit = unit || (row ? row.getAttribute('data-worker-unit') : 'Unit 1');
    const workerRole = role || (row ? row.getAttribute('data-worker-role') : 'Operator');
    const currentWork = assignedWork || (row ? row.getAttribute('data-assigned-work') : '');

    const nameEl = document.getElementById('assignModalWorkerName');
    const uidEl = document.getElementById('assignModalWorkerId');
    const unitEl = document.getElementById('assignModalWorkerUnit');
    const roleEl = document.getElementById('assignModalWorkerRole');
    const avatarEl = document.getElementById('assignModalAvatar');
    const currentWorkEl = document.getElementById('assignModalCurrentWorkText');

    if (nameEl) nameEl.innerText = workerName;
    if (uidEl) uidEl.innerText = workerUid;
    if (unitEl) unitEl.innerHTML = `<i class="fa-solid fa-industry"></i> ${workerUnit}`;
    if (roleEl) roleEl.innerText = workerRole;
    if (avatarEl) avatarEl.innerText = (workerName.charAt(0) || 'W').toUpperCase();
    if (currentWorkEl) {
        currentWorkEl.innerText = currentWork ? currentWork : 'None (Ready for Assignment)';
    }

    const searchInput = document.getElementById('assignCatSearchInput');
    if (searchInput) searchInput.value = '';
    const cards = document.querySelectorAll('.assign-cat-card');
    cards.forEach(c => c.style.display = '');

    // Reset selection state
    clearSelectedCategories();

    // Fetch live assigned work for this worker to pre-select checkboxes
    fetch('api.php?action=get_worker_assigned_work&worker_id=' + workerId)
        .then(res => res.json())
        .then(data => {
            if (data.success && data.assigned_category_names && data.assigned_category_names.length > 0) {
                const names = data.assigned_category_names.map(n => n.toLowerCase());
                cards.forEach(card => {
                    const cname = (card.getAttribute('data-cat-name') || '').toLowerCase();
                    const cid = parseInt(card.getAttribute('data-cat-id'), 10);
                    if (names.includes(cname) && cid && !selectedCategoryIds.has(cid)) {
                        toggleCategoryCardSelection(cid);
                    }
                });
            } else if (currentWork) {
                cards.forEach(card => {
                    const cname = (card.getAttribute('data-cat-name') || '').toLowerCase();
                    const cid = parseInt(card.getAttribute('data-cat-id'), 10);
                    if (currentWork.toLowerCase().includes(cname) && cid && !selectedCategoryIds.has(cid)) {
                        toggleCategoryCardSelection(cid);
                    }
                });
            }
        })
        .catch(err => {
            console.warn('Could not fetch active assignments:', err);
            if (currentWork) {
                cards.forEach(card => {
                    const cname = (card.getAttribute('data-cat-name') || '').toLowerCase();
                    const cid = parseInt(card.getAttribute('data-cat-id'), 10);
                    if (currentWork.toLowerCase().includes(cname) && cid && !selectedCategoryIds.has(cid)) {
                        toggleCategoryCardSelection(cid);
                    }
                });
            }
        });

    modal.style.display = 'flex';
}

function closeAssignWorkModal() {
    const modal = document.getElementById('assignWorkModal');
    if (modal) modal.style.display = 'none';
    currentAssignWorkerId = null;
    clearSelectedCategories();
}

async function saveSelectedCategoryAssignments() {
    if (!currentAssignWorkerId) {
        alert('No worker selected for assignment.');
        return;
    }

    if (selectedCategoryIds.size === 0) {
        if (!confirm('No categories are currently selected. Do you want to clear work assignment for this worker?')) {
            return;
        }
    }

    const categories = [];
    selectedCategoryIds.forEach(cid => {
        const card = document.getElementById('assignCard_' + cid);
        if (card) {
            categories.push({
                category_id: cid,
                category_name: card.getAttribute('data-cat-name') || '',
                subcategory_name: card.getAttribute('data-subcat-name') || '',
                sub_subcategory_name: card.getAttribute('data-subsub-name') || ''
            });
        }
    });

    const notesInput = document.getElementById('assignModalNotesInput');
    const notes = notesInput ? notesInput.value.trim() : '';
    const saveBtn = document.getElementById('btnSaveCategoryAssignments');
    const origHtml = saveBtn ? saveBtn.innerHTML : '';

    if (saveBtn) {
        saveBtn.disabled = true;
        saveBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';
    }

    try {
        const res = await fetch('api.php?action=assign_worker_category', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                worker_id: currentAssignWorkerId,
                categories: categories,
                notes: notes
            })
        });
        const data = await res.json();

        if (data.success) {
            // Update row attributes and tag in table
            const row = document.getElementById('workerRow_' + currentAssignWorkerId);
            if (row) {
                row.setAttribute('data-assigned-work', data.assigned_work);
            }

            const assignedTag = document.getElementById('assignedTag_' + currentAssignWorkerId);
            if (assignedTag) {
                if (data.assigned_work) {
                    assignedTag.style.display = 'block';
                    assignedTag.innerHTML = `
                        <span class="badge-pill" style="background: rgba(16,185,129,0.15); color: #34d399; border: 1px solid rgba(16,185,129,0.35); font-size: 0.72rem; display: inline-flex; align-items: center; gap: 0.3rem;" title="Assigned Work Category">
                            <i class="fa-solid fa-clipboard-check"></i> Work: <strong style="color: #fff;">${data.assigned_work}</strong>
                        </span>
                    `;
                } else {
                    assignedTag.style.display = 'none';
                    assignedTag.innerHTML = '';
                }
            }

            const curText = document.getElementById('assignModalCurrentWorkText');
            if (curText) curText.innerText = data.assigned_work || 'None';

            if (typeof showToast === 'function') {
                showToast(data.message, 'success');
            }

            setTimeout(closeAssignWorkModal, 400);
        } else {
            alert(data.message || 'Failed to save category assignment.');
        }
    } catch (err) {
        console.error(err);
        alert('Server error while saving category assignment: ' + err.message);
    } finally {
        if (saveBtn) {
            saveBtn.disabled = false;
            saveBtn.innerHTML = origHtml;
        }
    }
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
