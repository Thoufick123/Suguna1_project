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

// Fetch all registered workers for this unit
$workersStmt = $pdo->prepare("
    SELECT 
        w.*,
        (SELECT COUNT(*) FROM piece_logs WHERE worker_id = w.id) as total_entries,
        (SELECT material_name FROM piece_logs WHERE worker_id = w.id ORDER BY id DESC LIMIT 1) as latest_material
    FROM workers w
    WHERE w.unit = :unit
    ORDER BY w.total_pieces DESC, w.name ASC
");
$workersStmt->execute([':unit' => $requestedUnit]);
$workersList = $workersStmt->fetchAll();

include __DIR__ . '/includes/header.php';
?>

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
            <div class="stat-value" style="font-size: 1.25rem;"><?= $topWorker ? htmlspecialchars($topWorker['name']) : 'N/A' ?></div>
            <div class="stat-sub"><?= $topWorker ? number_format($topWorker['total_pieces']) . ' pcs (' . htmlspecialchars($topWorker['unique_id']) . ')' : 'No entries yet' ?></div>
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
    <div class="card-header" style="flex-wrap: wrap; gap: 0.75rem;">
        <div class="card-title" style="flex: 1 1 auto;">
            <i class="fa-solid fa-address-book"></i> Registered Workers Directory (<?= htmlspecialchars($requestedUnit) ?>)
        </div>
        <div class="search-input-wrapper">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" id="unitWorkerSearch" class="form-control" placeholder="Search name, ID, role, skills...">
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
                        <tr class="unit-worker-row">
                            <td class="text-muted font-bold">#<?= $idx + 1 ?></td>
                            <td>
                                <div class="worker-meta-cell">
                                    <div class="worker-avatar"><?= strtoupper(substr($w['name'], 0, 1)) ?></div>
                                    <div>
                                        <a href="javascript:void(0)" onclick="openWorkerModal(<?= $w['id'] ?>)" class="worker-meta-name" style="text-decoration:none;">
                                            <?= htmlspecialchars($w['name']) ?>
                                        </a>
                                        <span class="worker-meta-sub text-cyan font-bold"><?= htmlspecialchars($w['unique_id']) ?></span>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($w['role']) ?></strong>
                                <?php if ($w['latest_material']): ?>
                                    <div class="text-muted" style="font-size: 0.75rem;">Latest: <?= htmlspecialchars($w['latest_material']) ?></div>
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
                                <div style="display: flex; gap: 0.45rem;">
                                    <button class="btn btn-outline btn-sm" onclick="openWorkerModal(<?= $w['id'] ?>)" title="View Full Profile">
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
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
