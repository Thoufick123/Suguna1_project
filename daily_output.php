<?php
// daily_output.php - Dedicated User Stitching Workstation & Today's Clothes Tracker
$pageTitle = "My Stitched Clothes & Daily Output - FabriqPro";
require_once __DIR__ . '/includes/auth.php';
requireAuth();

$pdo = Database::getConnection();
$currentUser = getCurrentUser();
$stitchingCooldownSeconds = (int)Database::getSetting('stitching_cooldown_seconds', 30);

// Fetch all registered production units
$unitsStmt = $pdo->query("SELECT * FROM production_units WHERE COALESCE(NULLIF(status, ''), 'Active') != 'Disabled' ORDER BY id ASC");
$productionUnits = $unitsStmt->fetchAll(PDO::FETCH_ASSOC);
if (empty($productionUnits)) {
    $productionUnits = [
        ['unit_name' => 'Unit 1', 'unit_title' => 'Unit 1', 'color' => 'cyan', 'icon' => 'fa-industry'],
        ['unit_name' => 'Unit 2', 'unit_title' => 'Unit 2', 'color' => 'pink', 'icon' => 'fa-building-gear'],
    ];
}

// User-assigned unit constraint (if operator or supervisor is locked to a unit)
$userAssignedUnit = (!empty($currentUser['unit']) && $currentUser['unit'] !== 'All') ? $currentUser['unit'] : null;

// Determine active unit filter from GET query or user's assigned unit
$activeUnit = trim($_GET['unit'] ?? '');
if ($userAssignedUnit) {
    $activeUnit = $userAssignedUnit;
}

// Selected Date (defaults to today)
$selectedDate = trim($_GET['date'] ?? date('Y-m-d'));

// Fetch all registered workers
$workersStmt = $pdo->query("SELECT id, unique_id, name, unit, role, total_pieces FROM workers ORDER BY name ASC");
$allWorkers = $workersStmt->fetchAll(PDO::FETCH_ASSOC);

// Count workers per unit for pill badges
$unitWorkerCounts = [];
foreach ($allWorkers as $w) {
    $u = $w['unit'];
    $unitWorkerCounts[$u] = ($unitWorkerCounts[$u] ?? 0) + 1;
}

// Determine selected worker
$isOperator = ($currentUser && $currentUser['role'] === 'operator');
$sessionWorkerId = (int)($currentUser['worker_id'] ?? $_SESSION['worker_id'] ?? 0);

if ($isOperator && $sessionWorkerId > 0) {
    // Dedicated operator is ALWAYS locked to their own worker profile
    $selectedWorkerId = $sessionWorkerId;
} else {
    $selectedWorkerId = intval($_GET['worker_id'] ?? 0);
    if ($selectedWorkerId <= 0) {
        if ($sessionWorkerId > 0) {
            $selectedWorkerId = $sessionWorkerId;
        } elseif (!empty($activeUnit) && $activeUnit !== 'all') {
            foreach ($allWorkers as $w) {
                if (strcasecmp($w['unit'], $activeUnit) === 0) {
                    $selectedWorkerId = (int)$w['id'];
                    break;
                }
            }
        } elseif (!empty($allWorkers)) {
            $selectedWorkerId = (int)$allWorkers[0]['id'];
        }
    }
}

if ($selectedWorkerId > 0) {
    $wStmt = $pdo->prepare("SELECT * FROM workers WHERE id = :id");
    $wStmt->execute([':id' => $selectedWorkerId]);
    $worker = $wStmt->fetch(PDO::FETCH_ASSOC);
    if ($worker && empty($_GET['unit']) && !$userAssignedUnit) {
        $activeUnit = $worker['unit'];
    }
} else {
    $worker = null;
}

// =========================================================================
// CSV EXPORT ENGINE FOR OPERATOR / WORKER COMPLETE HISTORY
// =========================================================================
if (isset($_GET['export']) && $_GET['export'] === 'csv' && $worker) {
    $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower($worker['name']));
    $safeUid  = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower($worker['unique_id']));
    $filename = "fabriqpro_history_{$safeUid}_{$safeName}_" . date('Ymd_His') . ".csv";

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    // UTF-8 BOM for Microsoft Excel compatibility
    fputs($out, "\xEF\xBB\xBF");

    // CSV Headers
    fputcsv($out, [
        'Log ID',
        'Work Date',
        'Shift',
        'Worker ID',
        'Worker Name',
        'Production Unit',
        'Role / Designation',
        'Garment / Subcategory Stitched',
        'Pieces Stitched',
        'Logged At (Timestamp)',
        'Notes'
    ]);

    $csvStmt = $pdo->prepare("
        SELECT pl.id, pl.work_date, pl.shift, w.unique_id, w.name, 
               COALESCE(NULLIF(pl.unit, ''), w.unit) as unit, w.role,
               pl.material_name, pl.piece_count, pl.created_at, pl.notes
        FROM piece_logs pl
        JOIN workers w ON w.id = pl.worker_id
        WHERE pl.worker_id = :wid
        ORDER BY pl.work_date DESC, pl.id DESC
    ");
    $csvStmt->execute([':wid' => $worker['id']]);

    $totalExportPieces = 0;
    $totalExportRows   = 0;

    while ($r = $csvStmt->fetch(PDO::FETCH_ASSOC)) {
        $totalExportRows++;
        $totalExportPieces += (int)$r['piece_count'];
        fputcsv($out, [
            $r['id'],
            $r['work_date'],
            $r['shift'] ?: 'Day Shift',
            $r['unique_id'],
            $r['name'],
            $r['unit'],
            $r['role'] ?: 'Operator',
            $r['material_name'],
            (int)$r['piece_count'],
            $r['created_at'],
            $r['notes'] ?: ''
        ]);
    }

    // Blank row & Totals Summary
    fputcsv($out, []);
    fputcsv($out, [
        'TOTAL SUMMARY',
        "Records: $totalExportRows",
        '',
        $worker['unique_id'],
        $worker['name'],
        $worker['unit'],
        $worker['role'],
        'LIFETIME PIECES:',
        $totalExportPieces,
        date('Y-m-d H:i:s'),
        'Full Worker History'
    ]);

    fclose($out);
    exit;
}

$workerTransferredFrom = '';
$workerTransferType = '';
if ($worker) {
    $workerTransferredFrom = trim($worker['transferred_from'] ?? '');
    $workerTransferType = trim($worker['transfer_type'] ?? 'temporary');
    if (empty($workerTransferredFrom) && !empty($worker['unique_id'])) {
        if (preg_match('/^(PU\d{3})/i', $worker['unique_id'], $m)) {
            $code = strtoupper($m[1]);
            foreach ($productionUnits as $pu) {
                if (strtoupper($pu['unit_code']) === $code) {
                    $orig = $pu['unit_name'];
                    if (strcasecmp(preg_replace('/[^a-z0-9]/', '', $orig), preg_replace('/[^a-z0-9]/', '', $worker['unit'])) !== 0) {
                        $workerTransferredFrom = $orig;
                    }
                    break;
                }
            }
        }
    }
}

if (empty($activeUnit)) {
    $activeUnit = 'all';
}

// Build filtered list of workers to display in select dropdown
$displayWorkers = [];
if ($activeUnit !== 'all') {
    foreach ($allWorkers as $w) {
        if (strcasecmp($w['unit'], $activeUnit) === 0) {
            $displayWorkers[] = $w;
        }
    }
} else {
    $displayWorkers = $allWorkers;
}

// Fetch clothes / materials stitched by this worker on the selected date
$logsStmt = $pdo->prepare("
    SELECT * FROM piece_logs 
    WHERE worker_id = :worker_id AND work_date = :work_date 
    ORDER BY id DESC
");
$logsStmt->execute([
    ':worker_id' => $selectedWorkerId,
    ':work_date' => $selectedDate
]);
$todayLogs = $logsStmt->fetchAll();

// Calculate today's stats for this worker
$todayPieces = array_sum(array_column($todayLogs, 'piece_count'));
$todayMaterials = count(array_unique(array_column($todayLogs, 'material_name')));

// Lifetime material summary with multi-unit support
$allMaterials = [];
try {
    $allMatStmt = $pdo->prepare("
        SELECT material_name, 
               SUM(piece_count) as total, 
               SUM(CASE WHEN LOWER(COALESCE(unit, '')) LIKE '%unit 1%' OR unit = '1' THEN piece_count ELSE 0 END) as unit1_total,
               SUM(CASE WHEN LOWER(COALESCE(unit, '')) LIKE '%unit 2%' OR unit = '2' THEN piece_count ELSE 0 END) as unit2_total,
               GROUP_CONCAT(DISTINCT COALESCE(unit, '')) as units_stitched,
               COUNT(*) as entries 
        FROM piece_logs 
        WHERE worker_id = :worker_id 
        GROUP BY material_name 
        ORDER BY total DESC
    ");
    $allMatStmt->execute([':worker_id' => $selectedWorkerId]);
    $allMaterials = $allMatStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $allMaterials = [];
}

// Lifetime unit piece totals for this worker
$workerUnitTotals = [];
try {
    $unitTotalsStmt = $pdo->prepare("
        SELECT 
            COALESCE(NULLIF(TRIM(unit), ''), 'Unit 1') as unit_name,
            SUM(piece_count) as unit_pieces
        FROM piece_logs
        WHERE worker_id = :worker_id
        GROUP BY COALESCE(NULLIF(TRIM(unit), ''), 'Unit 1')
    ");
    $unitTotalsStmt->execute([':worker_id' => $selectedWorkerId]);
    $workerUnitTotals = $unitTotalsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $workerUnitTotals = [];
}

$u1Lifetime = 0;
$u2Lifetime = 0;
foreach ($workerUnitTotals as $ut) {
    if (stripos($ut['unit_name'], 'unit 2') !== false) {
        $u2Lifetime += (int)$ut['unit_pieces'];
    } else {
        $u1Lifetime += (int)$ut['unit_pieces'];
    }
}

// Build a lookup map of material_name -> current_count for today (summing across shifts & entries for 12:00 AM - 11:59 PM)
$todayMaterialCounts = [];
foreach ($todayLogs as $l) {
    $matKey = strtolower(trim($l['material_name']));
    $shiftKey = strtolower(trim($l['shift']));
    $c = (int)$l['piece_count'];
    $todayMaterialCounts[$matKey . '|' . $shiftKey] = ($todayMaterialCounts[$matKey . '|' . $shiftKey] ?? 0) + $c;
    $todayMaterialCounts[$matKey] = ($todayMaterialCounts[$matKey] ?? 0) + $c;
}

// Fetch active 3-tier categories added by admin only
$catStmt = $pdo->query("SELECT id, category_name, subcategory_name, sub_subcategory_name, stitch_time_seconds FROM garment_categories WHERE status = 'Active' ORDER BY category_name ASC, subcategory_name ASC, sub_subcategory_name ASC");
$garmentTree = [];
$adminCategoryChips = [];
$adminSubcategoryChips = [];
$catPalette = [
    ['icon' => 'fa-vest-patches', 'color' => '#10b981'],
    ['icon' => 'fa-baby', 'color' => '#ec4899'],
    ['icon' => 'fa-socks', 'color' => '#38bdf8'],
    ['icon' => 'fa-person-pregnant', 'color' => '#f43f5e'],
    ['icon' => 'fa-shirt', 'color' => '#a855f7'],
    ['icon' => 'fa-moon', 'color' => '#fbbf24'],
    ['icon' => 'fa-tag', 'color' => '#06b6d4'],
    ['icon' => 'fa-layer-group', 'color' => '#6366f1'],
    ['icon' => 'fa-vest', 'color' => '#14b8a6'],
    ['icon' => 'fa-cubes', 'color' => '#e11d48']
];
$palIdx = 0;

// Fetch all active work assignments for selected worker
$workerAssignments = [];
$assignedCategoryNames = [];
if ($selectedWorkerId > 0) {
    try {
        $assignStmt = $pdo->prepare("
            SELECT * FROM worker_work_assignments 
            WHERE worker_id = :wid AND assigned_date = :adate AND status = 'Active' 
            ORDER BY id ASC
        ");
        $assignStmt->execute([
            ':wid' => $selectedWorkerId,
            ':adate' => $selectedDate
        ]);
        $workerAssignments = $assignStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($workerAssignments)) {
            $assignStmt2 = $pdo->prepare("
                SELECT * FROM worker_work_assignments 
                WHERE worker_id = :wid AND status = 'Active' 
                ORDER BY id DESC LIMIT 10
            ");
            $assignStmt2->execute([':wid' => $selectedWorkerId]);
            $workerAssignments = $assignStmt2->fetchAll(PDO::FETCH_ASSOC);
        }

        $assignedSubcategoryNames = [];
        foreach ($workerAssignments as $wa) {
            if (!empty($wa['subcategory_name'])) {
                $sClean = trim($wa['subcategory_name']);
                $assignedSubcategoryNames[strtolower($sClean)] = $sClean;
            }
            if (!empty($wa['category_name'])) {
                $cClean = trim($wa['category_name']);
                $assignedCategoryNames[strtolower($cClean)] = $cClean;
            }
        }

        if (empty($assignedCategoryNames) && !empty($worker['assigned_category_id'])) {
            $cStmt = $pdo->prepare("SELECT * FROM garment_categories WHERE id = :cid LIMIT 1");
            $cStmt->execute([':cid' => (int)$worker['assigned_category_id']]);
            $cRow = $cStmt->fetch(PDO::FETCH_ASSOC);
            if ($cRow && !empty($cRow['category_name'])) {
                $cClean = trim($cRow['category_name']);
                $assignedCategoryNames[strtolower($cClean)] = $cClean;
                $workerAssignments[] = $cRow;
            }
        }

        if (empty($assignedCategoryNames) && !empty($worker['assigned_work'])) {
            $chunks = preg_split('/[,|]/', $worker['assigned_work']);
            foreach ($chunks as $chunk) {
                $catPart = trim(explode('-', $chunk)[0] ?? '');
                if (!empty($catPart)) {
                    $assignedCategoryNames[strtolower($catPart)] = $catPart;
                }
            }
        }
    } catch (Exception $e) {}
}

$workerAssignment = !empty($workerAssignments) ? $workerAssignments[0] : null;
$hasAssignedWork = !empty($assignedCategoryNames);
$workerAssignedSubcategories = [];

while ($catRow = $catStmt->fetch(PDO::FETCH_ASSOC)) {
    $c = trim($catRow['category_name']);
    $s = trim($catRow['subcategory_name']);
    $subsub = trim($catRow['sub_subcategory_name'] ?? '');
    if (!isset($garmentTree[$c])) $garmentTree[$c] = [];
    if (!isset($garmentTree[$c][$s])) $garmentTree[$c][$s] = [];
    if ($subsub !== '') {
        $garmentTree[$c][$s][] = [
            'id' => (int)$catRow['id'],
            'name' => $subsub
        ];
    }

    $cKey = strtolower($c);
    if (!isset($adminCategoryChips[$cKey])) {
        $ic = $catPalette[$palIdx % count($catPalette)]['icon'];
        $col = $catPalette[$palIdx % count($catPalette)]['color'];

        $lowC = strtolower($c);
        if (strpos($lowC, 'kurti') !== false || strpos($lowC, 'cotton') !== false) { $ic = 'fa-vest-patches'; $col = '#10b981'; }
        elseif (strpos($lowC, 'feed') !== false) { $ic = 'fa-baby'; $col = '#ec4899'; }
        elseif (strpos($lowC, 'jean') !== false || strpos($lowC, 'denim') !== false) { $ic = 'fa-socks'; $col = '#38bdf8'; }
        elseif (strpos($lowC, 'matern') !== false) { $ic = 'fa-person-pregnant'; $col = '#f43f5e'; }
        elseif (strpos($lowC, 'shirt') !== false || strpos($lowC, 'tunic') !== false) { $ic = 'fa-shirt'; $col = '#a855f7'; }
        elseif (strpos($lowC, 'night') !== false) { $ic = 'fa-moon'; $col = '#fbbf24'; }

        $adminCategoryChips[$cKey] = [
            'name' => $c,
            'icon' => $ic,
            'color' => $col
        ];
        $palIdx++;
    }

    // Build Subcategory Chips
    if ($s !== '') {
        $sKey = strtolower($s);
        if (!isset($adminSubcategoryChips[$sKey])) {
            $sIcon = 'fa-tag';
            $sCol = '#06b6d4';
            $lowS = strtolower($s);
            $lowC = strtolower($c);
            if (strpos($lowS, 'cotton') !== false || strpos($lowC, 'cotton') !== false || strpos($lowC, 'kurti') !== false) { $sIcon = 'fa-vest-patches'; $sCol = '#10b981'; }
            elseif (strpos($lowS, 'zip') !== false || strpos($lowC, 'feed') !== false) { $sIcon = 'fa-baby'; $sCol = '#ec4899'; }
            elseif (strpos($lowS, 'jean') !== false || strpos($lowC, 'denim') !== false) { $sIcon = 'fa-socks'; $sCol = '#38bdf8'; }
            elseif (strpos($lowS, 'matern') !== false || strpos($lowC, 'matern') !== false) { $sIcon = 'fa-person-pregnant'; $sCol = '#f43f5e'; }
            elseif (strpos($lowS, 'neck') !== false || strpos($lowC, 'shirt') !== false) { $sIcon = 'fa-shirt'; $sCol = '#a855f7'; }
            elseif (strpos($lowS, 'frock') !== false || strpos($lowC, 'frock') !== false) { $sIcon = 'fa-child-dress'; $sCol = '#f59e0b'; }
            elseif (strpos($lowS, 'pajama') !== false || strpos($lowC, 'pajama') !== false) { $sIcon = 'fa-moon'; $sCol = '#6366f1'; }

            $subChipItem = [
                'name' => $s,
                'category_name' => $c,
                'garment_type' => $subsub,
                'icon' => $sIcon,
                'color' => $sCol,
                'stitch_time_seconds' => (int)($catRow['stitch_time_seconds'] ?? 0)
            ];
            $adminSubcategoryChips[$sKey] = $subChipItem;

            if ($hasAssignedWork) {
                if (isset($assignedCategoryNames[strtolower($c)])) {
                    $workerAssignedSubcategories[$sKey] = $subChipItem;
                }
            }
        }
    }
}

// User role check (operator/worker or non-admin)
$isUserRole = ($currentUser && $currentUser['role'] !== 'admin');
$showSubcategoriesOnly = $isUserRole || $hasAssignedWork;

if ($showSubcategoriesOnly) {
    if ($hasAssignedWork && !empty($workerAssignedSubcategories)) {
        $chipsToDisplay = $workerAssignedSubcategories;
    } else {
        $chipsToDisplay = $adminSubcategoryChips;
    }
} else {
    $chipsToDisplay = $adminCategoryChips;
}

include __DIR__ . '/includes/header.php';
?>
<script>
window.todayMaterialCounts = <?= json_encode($todayMaterialCounts) ?>;
window.allWorkersList = <?= json_encode($allWorkers) ?>;
window.currentSelectedWorkerId = <?= (int)$selectedWorkerId ?>;
window.currentActiveUnit = <?= json_encode($activeUnit) ?>;
window.currentSelectedDate = <?= json_encode($selectedDate) ?>;
window.isCurrentDateToday = <?= json_encode($selectedDate === date('Y-m-d')) ?>;
window.lastLoggedMaterial = <?= json_encode(!empty($todayLogs) ? $todayLogs[0]['material_name'] : null) ?>;
window.isUserRole = <?= json_encode($isUserRole) ?>;
window.hasAssignedWork = <?= json_encode($hasAssignedWork) ?>;
window.showSubcategoriesOnly = <?= json_encode($showSubcategoriesOnly) ?>;
window.assignedCategory = <?= json_encode($workerAssignment['category_name'] ?? null) ?>;
window.assignedCategoryNames = <?= json_encode(array_values($assignedCategoryNames)) ?>;
window.assignedSubcategory = <?= json_encode($workerAssignment['subcategory_name'] ?? null) ?>;
window.adminCategoryChips = <?= json_encode(array_values($adminCategoryChips)) ?>;
window.adminSubcategoryChips = <?= json_encode(array_values($adminSubcategoryChips)) ?>;
window.workerAssignedSubcategories = <?= json_encode(array_values($workerAssignedSubcategories)) ?>;

function filterWorkersByUnit(unitName) {
    const unitInput = document.getElementById('activeUnitInput');
    if (unitInput) unitInput.value = unitName;

    // Highlight button
    document.querySelectorAll('.unit-filter-btn').forEach(btn => {
        const btnUnit = btn.getAttribute('data-unit');
        if (btnUnit === unitName) {
            btn.classList.add('active');
            const col = btn.getAttribute('data-color') || 'cyan';
            btn.classList.add('active-' + col);
        } else {
            btn.classList.remove('active', 'active-cyan', 'active-pink', 'active-emerald');
        }
    });

    const select = document.getElementById('operatorWorkerSelect');
    if (!select) return;

    const curWorkerId = parseInt(select.value) || 0;
    const matching = window.allWorkersList.filter(w => {
        return (unitName === 'all' || !unitName) || (w.unit.toLowerCase() === unitName.toLowerCase());
    });

    select.innerHTML = '';
    let foundCurrent = false;

    matching.forEach(w => {
        const opt = document.createElement('option');
        opt.value = w.id;
        opt.setAttribute('data-unit', w.unit);
        opt.textContent = `${w.name} (${w.unit})`;
        if (w.id === curWorkerId) {
            opt.selected = true;
            foundCurrent = true;
        }
        select.appendChild(opt);
    });

    // If current worker doesn't belong to the newly selected unit, switch worker and reload
    if (!foundCurrent && matching.length > 0) {
        select.value = matching[0].id;
        window.location.href = `daily_output.php?worker_id=${matching[0].id}&unit=${encodeURIComponent(unitName)}&date=${encodeURIComponent(window.currentSelectedDate)}`;
    } else if (foundCurrent) {
        const url = new URL(window.location.href);
        url.searchParams.set('unit', unitName);
        window.history.replaceState({}, '', url.toString());
    }
}
</script>

<!-- Styles for User-Friendly Workstation -->
<style>
/* Modern Luxury Operator Header Card */
.uf-operator-bar {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    padding: 1rem 1.25rem;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.25);
    backdrop-filter: blur(20px);
    margin-bottom: 1.25rem;
}

.uf-operator-flex {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.85rem;
    flex-wrap: wrap;
}

.uf-profile-left {
    display: flex;
    align-items: center;
    gap: 0.85rem;
    min-width: 0;
}

.uf-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: linear-gradient(135deg, #06b6d4 0%, #2563eb 100%);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: 1.25rem;
    border: 2px solid rgba(255, 255, 255, 0.2);
    box-shadow: 0 4px 14px rgba(6, 182, 212, 0.35);
    flex-shrink: 0;
}

.uf-name-row {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.uf-worker-name {
    font-size: 1.15rem;
    font-weight: 800;
    color: var(--text-primary);
    letter-spacing: -0.3px;
}

.uf-sub-row {
    font-size: 0.78rem;
    color: var(--text-muted);
    display: flex;
    align-items: center;
    gap: 0.45rem;
    margin-top: 0.2rem;
}

.uf-actions-right {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.date-toggle-group {
    display: inline-flex;
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-sm);
    padding: 2px;
    gap: 2px;
}

.date-toggle-group .btn {
    border: none;
    border-radius: calc(var(--radius-sm) - 2px);
    font-weight: 700;
    transition: all 0.2s ease;
}

.date-toggle-group .btn.btn-primary {
    background: var(--primary);
    color: #fff;
    box-shadow: 0 2px 8px rgba(37, 99, 235, 0.35);
}

.uf-worker-switch-row {
    margin-top: 0.75rem;
    padding-top: 0.75rem;
    border-top: 1px solid rgba(255, 255, 255, 0.06);
    display: flex;
    align-items: center;
    gap: 0.65rem;
    flex-wrap: wrap;
}

.uf-worker-switch-form {
    display: flex;
    gap: 0.45rem;
    flex: 1;
    min-width: 220px;
}

/* User-Friendly Workstation Form */
.uf-workstation-card {
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    background: var(--bg-card);
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.28);
    backdrop-filter: blur(20px);
    margin-bottom: 2.25rem !important;
}

.uf-section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 0.75rem;
    flex-wrap: wrap;
    gap: 0.5rem;
}

.uf-section-label {
    font-size: 0.95rem;
    font-weight: 800;
    color: var(--text-primary);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    letter-spacing: -0.2px;
}

.uf-step-badge {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    background: linear-gradient(135deg, #06b6d4, #0284c7);
    color: #fff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.75rem;
    font-weight: 800;
    box-shadow: 0 2px 8px rgba(6, 182, 212, 0.4);
}

.uf-garment-actions {
    display: flex;
    align-items: center;
    gap: 0.35rem;
    flex-wrap: wrap;
}

/* Garment Quick Chips Grid */
.garment-quick-chips-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 0.65rem;
}
@media (min-width: 520px) {
    .garment-quick-chips-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}
@media (min-width: 860px) {
    .garment-quick-chips-grid {
        grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    }
}

.garment-chip-btn {
    border: 1px solid var(--border-color);
    background: rgba(255, 255, 255, 0.03);
    border-radius: 12px;
    padding: 0.7rem 0.8rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    cursor: pointer;
    transition: all 0.22s cubic-bezier(0.16, 1, 0.3, 1);
    color: var(--text-primary);
    min-height: 52px;
    width: 100%;
    box-sizing: border-box;
}

.garment-chip-btn:hover {
    background: rgba(255, 255, 255, 0.08);
    border-color: rgba(56, 189, 248, 0.6);
    transform: translateY(-2px);
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.25);
}

.garment-chip-btn.active {
    background: rgba(6, 182, 212, 0.15) !important;
    border-color: #06b6d4 !important;
    color: #fff !important;
    box-shadow: 0 0 16px rgba(6, 182, 212, 0.35) !important;
    transform: translateY(-1px);
}

.garment-chip-icon {
    font-size: 1.15rem;
    flex-shrink: 0;
}

.garment-chip-title {
    font-size: 0.85rem;
    font-weight: 700;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    line-height: 1.2;
}

/* Empty State Card */
.uf-empty-categories-card {
    grid-column: 1 / -1;
    padding: 1.75rem 1.25rem;
    text-align: center;
    background: rgba(255, 255, 255, 0.02);
    border: 1px dashed rgba(255, 255, 255, 0.12);
    border-radius: var(--radius-md);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
}
.uf-empty-icon-ring {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    background: rgba(6, 182, 212, 0.1);
    border: 1px solid rgba(6, 182, 212, 0.3);
    color: var(--accent-cyan);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.35rem;
    margin-bottom: 0.65rem;
    box-shadow: 0 0 16px rgba(6, 182, 212, 0.2);
}
.uf-empty-title {
    font-size: 0.95rem;
    font-weight: 700;
    color: var(--text-primary);
    margin: 0 0 0.25rem 0;
}
.uf-empty-desc {
    font-size: 0.78rem;
    color: var(--text-muted);
    margin: 0 0 0.85rem 0;
    max-width: 280px;
    line-height: 1.4;
}

/* Tactile Big Touch Stepper */
.user-friendly-counter-wrap {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.85rem;
    margin: 0.5rem 0;
}

.uf-counter-btn {
    width: 56px;
    height: 56px;
    border-radius: 14px;
    border: 1px solid var(--border-color);
    background: rgba(255, 255, 255, 0.04);
    color: var(--text-primary);
    font-size: 1.4rem;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.18s cubic-bezier(0.16, 1, 0.3, 1);
}

.uf-counter-btn:active {
    transform: scale(0.92);
}

.uf-counter-btn.dec-btn:hover {
    background: rgba(244, 63, 94, 0.15);
    border-color: #f43f5e;
    color: #f43f5e;
}

.uf-counter-btn.inc-btn:hover {
    background: rgba(16, 185, 129, 0.15);
    border-color: #10b981;
    color: #10b981;
}

.uf-counter-input {
    width: 140px;
    height: 56px;
    font-size: 2.2rem;
    font-weight: 900;
    font-family: var(--font-heading, sans-serif);
    text-align: center;
    color: #10b981;
    background: rgba(0, 0, 0, 0.35);
    border: 2px solid rgba(16, 185, 129, 0.35);
    border-radius: 14px;
    box-shadow: inset 0 2px 8px rgba(0,0,0,0.4);
    cursor: default;
    user-select: none;
    font-variant-numeric: tabular-nums;
    letter-spacing: 0.5px;
}

/* Submit Action Row */
.uf-submit-row {
    display: flex;
    gap: 0.65rem;
    align-items: center;
}

.uf-submit-btn {
    transition: all 0.22s cubic-bezier(0.16, 1, 0.3, 1);
}

/* ===================================================================
   MOBILE RESPONSIVE TWEAKS (< 600px)
   =================================================================== */
@media (max-width: 600px) {
    .uf-operator-bar {
        padding: 0.85rem !important;
        margin-bottom: 0.85rem !important;
    }
    .uf-operator-flex {
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 0.75rem !important;
    }
    .uf-profile-left {
        width: 100% !important;
    }
    .uf-actions-right {
        width: 100% !important;
        display: flex !important;
        gap: 0.45rem !important;
    }
    .date-toggle-group {
        flex: 1 1 auto !important;
        display: flex !important;
    }
    .date-toggle-group .btn {
        flex: 1 !important;
        text-align: center !important;
        padding: 0.35rem 0.4rem !important;
        font-size: 0.78rem !important;
    }
    .uf-actions-right a[href="leaderboard.php"] {
        flex: 1 1 auto !important;
        text-align: center !important;
        padding: 0.35rem 0.5rem !important;
        font-size: 0.78rem !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        gap: 0.35rem !important;
    }
    .uf-worker-switch-row {
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 0.35rem !important;
    }
    .uf-worker-switch-form {
        width: 100% !important;
        min-width: 0 !important;
    }
    .uf-worker-switch-form select {
        width: 100% !important;
        height: 36px !important;
        font-size: 0.82rem !important;
    }
    #userStitchForm {
        padding: 0.95rem 0.85rem !important;
    }
    .uf-section-header {
        flex-direction: row !important;
        align-items: center !important;
    }
    .uf-garment-actions {
        gap: 0.25rem !important;
    }
    .uf-garment-actions .btn {
        padding: 0.22rem 0.45rem !important;
        font-size: 0.7rem !important;
    }
    .uf-submit-row {
        display: grid !important;
        grid-template-columns: auto 1fr !important;
        gap: 0.55rem !important;
    }
    #userResetFormBtn {
        grid-column: 1 !important;
        grid-row: 1 !important;
        height: 50px !important;
        width: 50px !important;
        padding: 0 !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
    }
    #userCompleteBtn {
        grid-column: 2 !important;
        grid-row: 1 !important;
        height: 50px !important;
        min-width: 0 !important;
        font-size: 1.05rem !important;
        padding: 0 0.85rem !important;
    }
    #userSaveBtn {
        grid-column: 1 / -1 !important;
        grid-row: 2 !important;
        height: 44px !important;
        width: 100% !important;
        font-size: 0.9rem !important;
        min-width: 0 !important;
    }
}
</style>

<!-- Success Banner Container -->
<div id="stitchSuccessBanner" class="card stitch-success-card" style="display: none;">
    <div class="stitch-success-inner">
        <div class="stitch-success-msg">
            <div class="stitch-success-icon">
                <i class="fa-solid fa-circle-check"></i>
            </div>
            <div>
                <h3 class="stitch-success-title">Stitched Piece Completed &amp; Recorded!</h3>
                <p class="text-muted stitch-success-sub">
                    Saved for <strong id="loggedWorkerName" class="text-cyan"><?= htmlspecialchars($worker['name'] ?? 'Worker') ?></strong>: 
                    <span id="loggedPieceSummary" class="text-emerald font-bold">+1 pcs</span> • 
                    <span id="loggedNewTotal">Today: <?= $todayPieces + 1 ?> pcs</span>
                </p>
            </div>
        </div>
        <div>
            <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('stitchSuccessBanner').style.display='none'">
                <i class="fa-solid fa-xmark"></i> Dismiss
            </button>
        </div>
    </div>
</div>

<?php if ($worker): ?>
<!-- 1. Sleek Compact Operator & Date Bar -->
<div class="card mb-3 uf-operator-bar">
    <div class="uf-operator-flex">
        <div class="uf-profile-left">
            <div class="uf-avatar">
                <?= strtoupper(substr($worker['name'], 0, 1)) ?>
            </div>
            <div>
                <div class="uf-name-row">
                    <span class="uf-worker-name"><?= htmlspecialchars($worker['name']) ?></span>
                    <span class="unit-badge <?= ($worker['unit'] === 'Unit 1') ? 'unit-1' : 'unit-2' ?>" style="font-size: 0.72rem; padding: 0.15rem 0.45rem;">
                        <?= htmlspecialchars($worker['unit']) ?>
                    </span>
                    <?php if (!empty($workerTransferredFrom)): ?>
                        <span class="badge-pill" style="font-size: 0.7rem; padding: 0.12rem 0.5rem; background: rgba(245, 158, 11, 0.18); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.45); font-weight: 700; display: inline-flex; align-items: center; gap: 0.25rem;" title="Transferred from <?= htmlspecialchars($workerTransferredFrom) ?> (<?= ($workerTransferType === 'permanent') ? 'Permanent Transfer' : 'Emergency Support' ?>)">
                            <i class="fa-solid fa-arrow-right-arrow-left"></i> Switched from <?= htmlspecialchars($workerTransferredFrom) ?>
                        </span>
                    <?php endif; ?>
                    <span class="text-cyan font-bold" style="font-size: 0.82rem;"><?= htmlspecialchars($worker['unique_id']) ?></span>
                </div>
                <div class="uf-sub-row" style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                    <span><?= htmlspecialchars($worker['role']) ?></span>
                    <span>•</span>
                    <span class="text-primary font-bold"><?= date('d M Y', strtotime($selectedDate)) ?></span>
                    <?php if (!empty($workerUnitTotals)): ?>
                        <span>•</span>
                        <span style="display: inline-flex; align-items: center; gap: 0.35rem; flex-wrap: wrap;" title="Lifetime Pieces Stored By Production Unit">
                            <i class="fa-solid fa-industry text-cyan" style="font-size: 0.72rem;"></i>
                            <?php foreach ($workerUnitTotals as $wut): ?>
                                <span class="badge-pill" style="font-size: 0.68rem; padding: 0.08rem 0.45rem; background: rgba(6,182,212,0.12); color: #22d3ee; border: 1px solid rgba(6,182,212,0.3); font-weight: 700; border-radius: 9999px;">
                                    <?= htmlspecialchars($wut['unit_name']) ?>: <?= number_format($wut['unit_pieces']) ?> pcs
                                </span>
                            <?php endforeach; ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="uf-actions-right">
            <!-- Today / Yesterday Date Switch -->
            <div class="date-toggle-group">
                <a href="daily_output.php?worker_id=<?= $selectedWorkerId ?>&unit=<?= urlencode($activeUnit) ?>&date=<?= date('Y-m-d') ?>" class="btn btn-outline btn-sm <?= ($selectedDate === date('Y-m-d')) ? 'btn-primary' : '' ?>" style="padding: 0.28rem 0.65rem; font-size: 0.78rem;">
                    Today
                </a>
                <a href="daily_output.php?worker_id=<?= $selectedWorkerId ?>&unit=<?= urlencode($activeUnit) ?>&date=<?= date('Y-m-d', strtotime('-1 day')) ?>" class="btn btn-outline btn-sm <?= ($selectedDate === date('Y-m-d', strtotime('-1 day'))) ? 'btn-primary' : '' ?>" style="padding: 0.28rem 0.65rem; font-size: 0.78rem;">
                    Yesterday
                </a>
            </div>

            <!-- Download Complete User History CSV Button -->
            <a href="daily_output.php?worker_id=<?= $selectedWorkerId ?>&export=csv" class="btn btn-outline btn-sm" style="padding: 0.28rem 0.65rem; font-size: 0.78rem; border-color: rgba(16,185,129,0.45); color: #34d399; background: rgba(16,185,129,0.08); font-weight: 700; display: inline-flex; align-items: center; gap: 0.35rem;" title="Download complete stitching history of <?= htmlspecialchars($worker['name']) ?> as Excel / CSV">
                <i class="fa-solid fa-file-arrow-down"></i> Download History
            </a>

            <!-- Leaderboard Quick Link -->
            <a href="leaderboard.php" class="btn btn-outline btn-sm" style="padding: 0.28rem 0.65rem; font-size: 0.78rem; border-color: rgba(245,158,11,0.4); color: #fbbf24;">
                <i class="fa-solid fa-trophy"></i> Leaderboard
            </a>
        </div>
    </div>

    <?php if ($currentUser['role'] === 'admin' || $currentUser['role'] === 'supervisor'): ?>
        <!-- Admin/Supervisor Switcher Bar -->
        <div class="uf-worker-switch-row">
            <span class="text-muted" style="font-size: 0.78rem; font-weight: 700; display: flex; align-items: center; gap: 0.35rem;">
                <i class="fa-solid fa-user-gear text-cyan"></i> Switch Worker:
            </span>
            <form method="GET" action="daily_output.php" id="workerFilterForm" class="uf-worker-switch-form">
                <input type="hidden" name="date" value="<?= htmlspecialchars($selectedDate) ?>">
                <input type="hidden" name="unit" id="activeUnitInput" value="<?= htmlspecialchars($activeUnit) ?>">
                <select name="worker_id" id="operatorWorkerSelect" class="form-control" onchange="this.form.submit()" style="font-size: 0.82rem; padding: 0.4rem 0.65rem; border-radius: var(--radius-sm);">
                    <?php 
                    $workersByUnit = [];
                    foreach ($allWorkers as $w) {
                        $u = $w['unit'] ?: 'Other';
                        $workersByUnit[$u][] = $w;
                    }
                    foreach ($workersByUnit as $uName => $uWorkers):
                    ?>
                        <optgroup label="── <?= htmlspecialchars($uName) ?> (<?= count($uWorkers) ?>) ──">
                            <?php foreach ($uWorkers as $w): ?>
                                <option value="<?= $w['id'] ?>" data-unit="<?= htmlspecialchars($w['unit']) ?>" <?= ($selectedWorkerId === (int)$w['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($w['name']) ?> (<?= htmlspecialchars($w['unit'] . ' - ' . $w['unique_id']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
    <?php endif; ?>
</div>

<!-- 2. Fast User-Friendly Stitching Workstation -->
<div class="card mb-4 uf-workstation-card">
    <!-- Hidden status bindings -->
    <span id="clothStatusBadge" style="display: none !important;"><strong id="clothCurrentLoggedCount"></strong></span>

    <form id="userStitchForm" autocomplete="off" novalidate style="padding: 1.15rem;">
        <input type="hidden" name="worker_id" value="<?= $worker['id'] ?>">
        <input type="hidden" name="work_date" value="<?= htmlspecialchars($selectedDate) ?>">
        <input type="hidden" name="unit" id="workstationUnitInput" value="<?= htmlspecialchars($worker['unit'] ?: 'Unit 1') ?>">
        <input type="hidden" id="userClothSelect" name="material_name" required>

        <!-- STEP 1: Quick Garment / Subcategory Tap Chips -->
        <div style="margin-bottom: 1.25rem;">
            <!-- If worker has assigned work for today, show prominent Assigned Work Banner -->
            <?php if ($hasAssignedWork && !empty($assignedCategoryNames)): ?>
                <div class="assigned-work-callout" style="display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 0.65rem 1rem; margin-bottom: 0.95rem; background: rgba(16,185,129,0.08); border: 1px solid rgba(16,185,129,0.35); border-radius: var(--radius-md); box-shadow: 0 0 16px rgba(16,185,129,0.12); flex-wrap: wrap;">
                    <div style="display: flex; align-items: center; gap: 0.65rem; font-size: 0.84rem; flex-wrap: wrap;">
                        <span style="display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 50%; background: rgba(16,185,129,0.2); color: #34d399; font-size: 0.88rem; flex-shrink: 0;">
                            <i class="fa-solid fa-circle-check"></i>
                        </span>
                        <div style="display: flex; align-items: center; gap: 0.4rem; flex-wrap: wrap;">
                            <span style="color: #34d399; font-weight: 700;">Assigned Work for Today:</span>
                            <?php 
                            $assignedDisplayItems = !empty($assignedSubcategoryNames) ? $assignedSubcategoryNames : $assignedCategoryNames;
                            foreach ($assignedDisplayItems as $itemDisplayName): ?>
                                <span class="badge-pill" style="background: rgba(16,185,129,0.2); color: #34d399; font-weight: 700; border: 1px solid rgba(16,185,129,0.4); font-size: 0.78rem;">
                                    <i class="fa-solid fa-tag"></i> <?= htmlspecialchars($itemDisplayName) ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <span class="badge-pill" style="font-size: 0.72rem; background: rgba(16,185,129,0.2); color: #34d399; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; border: 1px solid rgba(16,185,129,0.35); flex-shrink: 0;">
                        <i class="fa-solid fa-bolt"></i> <?= count($assignedCategoryNames) ?> Assigned Active
                    </span>
                </div>
            <?php endif; ?>

            <div class="uf-section-header">
                <label class="uf-section-label">
                    <span class="uf-step-badge">1</span>
                    <span id="step1LabelTitle"><?= $showSubcategoriesOnly ? 'Select Subcategory' : 'Select Garment' ?></span>
                    <span class="required" style="color: var(--accent-rose);">*</span>
                </label>
                <div class="uf-garment-actions">
                    <!-- For admin/supervisor: switch between Subcategories & Categories view -->
                    <?php if (!$isUserRole): ?>
                        <div class="mode-toggle-pill-group" style="display: inline-flex; background: rgba(255,255,255,0.04); border: 1px solid var(--border-color); border-radius: 50px; padding: 2px;">
                            <button type="button" class="btn-mode-pill <?= $showSubcategoriesOnly ? 'active' : '' ?>" id="btnModeSubcat" onclick="switchGarmentViewMode('subcategory')" style="border: none; background: <?= $showSubcategoriesOnly ? 'rgba(16,185,129,0.25)' : 'transparent' ?>; color: <?= $showSubcategoriesOnly ? '#34d399' : 'var(--text-muted)' ?>; font-size: 0.72rem; font-weight: 700; padding: 0.2rem 0.6rem; border-radius: 50px; cursor: pointer;">
                                Subcategories
                            </button>
                            <button type="button" class="btn-mode-pill <?= !$showSubcategoriesOnly ? 'active' : '' ?>" id="btnModeCat" onclick="switchGarmentViewMode('category')" style="border: none; background: <?= !$showSubcategoriesOnly ? 'rgba(6,182,212,0.25)' : 'transparent' ?>; color: <?= !$showSubcategoriesOnly ? '#06b6d4' : 'var(--text-muted)' ?>; font-size: 0.72rem; font-weight: 700; padding: 0.2rem 0.6rem; border-radius: 50px; cursor: pointer;">
                                All Categories
                            </button>
                        </div>
                    <?php endif; ?>

                    <?php if ($currentUser && ($currentUser['role'] === 'admin' || $currentUser['role'] === 'supervisor')): ?>
                        <button type="button" class="btn btn-outline btn-xs" id="btnToggleInlineAddCat" style="font-size: 0.74rem; padding: 0.25rem 0.55rem; border-radius: 8px; color: var(--accent-cyan); border-color: rgba(6,182,212,0.4);" title="Quick add category">
                            <i class="fa-solid fa-circle-plus text-cyan"></i> + Add
                        </button>
                        <button type="button" class="btn btn-outline btn-xs" id="openManageCategoriesBtn" style="font-size: 0.74rem; padding: 0.25rem 0.55rem; border-radius: 8px;" title="Manage all categories">
                            <i class="fa-solid fa-layer-group"></i> Manage
                        </button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-outline btn-xs" id="btnToggleAllCategories" style="font-size: 0.74rem; padding: 0.25rem 0.55rem; border-radius: 8px;">
                        <i class="fa-solid fa-sliders"></i> <span id="moreCategoriesBtnLabel">More</span>
                    </button>
                </div>
            </div>

            <!-- Admin Inline Quick Add Category / Subcategory Box -->
            <?php if ($currentUser && ($currentUser['role'] === 'admin' || $currentUser['role'] === 'supervisor')): ?>
            <div id="inlineAddCategoryBox" style="display: none; margin-bottom: 0.85rem; padding: 0.85rem 1rem; background: rgba(6,182,212,0.06); border: 1px solid rgba(6,182,212,0.3); border-radius: var(--radius-md);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.65rem;">
                    <span style="font-size: 0.82rem; font-weight: 700; color: var(--accent-cyan); display: flex; align-items: center; gap: 0.4rem;">
                        <i class="fa-solid fa-circle-plus"></i> Add New Category &amp; Subcategory
                    </span>
                    <div style="display: flex; gap: 0.5rem; align-items: center;">
                        <a href="categories.php" class="btn btn-outline btn-xs" style="font-size: 0.7rem; padding: 0.15rem 0.45rem;">
                            <i class="fa-solid fa-folder-tree"></i> Full Category Directory
                        </a>
                        <button type="button" id="closeInlineAddCatBtn" style="background: none; border: none; color: var(--text-muted); cursor: pointer; font-size: 0.85rem;" title="Close">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>
                </div>
                <div id="inlineQuickAddCatForm" style="display: grid; grid-template-columns: 1fr 1fr 1fr auto; gap: 0.65rem; align-items: end;">
                    <div>
                        <label style="font-size: 0.74rem; font-weight: 600; color: var(--text-muted); display: block; margin-bottom: 0.2rem;">
                            1. Category Name <span class="required" style="color: var(--accent-rose);">*</span>
                        </label>
                        <input type="text" id="inlineNewCatInput" list="existingCategoriesList" class="form-control" placeholder="e.g. Polo T-Shirts, Kids Wear" style="font-size: 0.82rem; height: 38px;">
                    </div>
                    <div>
                        <label style="font-size: 0.74rem; font-weight: 600; color: var(--text-muted); display: block; margin-bottom: 0.2rem;">
                            2. Subcategory <span class="required" style="color: var(--accent-rose);">*</span>
                        </label>
                        <input type="text" id="inlineNewSubcatInput" list="existingSubcategoriesList" class="form-control" placeholder="e.g. Collar, Round Neck" style="font-size: 0.82rem; height: 38px;">
                    </div>
                    <div>
                        <label style="font-size: 0.74rem; font-weight: 600; color: var(--text-muted); display: block; margin-bottom: 0.2rem;">
                            3. Specific Garment <span style="font-weight: normal; color: var(--text-muted);">(Optional)</span>
                        </label>
                        <input type="text" id="inlineNewSubSubcatInput" class="form-control" placeholder="e.g. Striped, Full Sleeve" style="font-size: 0.82rem; height: 38px;">
                    </div>
                    <div>
                        <button type="button" id="inlineSaveCatBtn" class="btn btn-primary" style="height: 38px; padding: 0 1rem; font-size: 0.82rem; font-weight: 700; white-space: nowrap; display: flex; align-items: center; gap: 0.4rem;">
                            <i class="fa-solid fa-check"></i> Save &amp; Select
                        </button>
                    </div>
                </div>
                <div id="inlineCatAlert" style="display: none; margin-top: 0.5rem; font-size: 0.78rem;"></div>
            </div>
            <?php endif; ?>

            <!-- Quick Chips Grid -->
            <div class="garment-quick-chips-grid" id="garmentQuickChipsGrid">
                <?php if (empty($chipsToDisplay)): ?>
                    <div class="uf-empty-categories-card">
                        <div class="uf-empty-icon-ring">
                            <i class="fa-solid fa-shirt"></i>
                        </div>
                        <div class="uf-empty-title">No Subcategories Available</div>
                        <div class="uf-empty-desc">Ask your supervisor or admin to assign a work category in Units.</div>
                        <?php if ($currentUser && ($currentUser['role'] === 'admin' || $currentUser['role'] === 'supervisor')): ?>
                            <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('btnToggleInlineAddCat')?.click();" style="border-radius: 99px; padding: 0.4rem 1.15rem; font-size: 0.8rem; font-weight: 700; display: inline-flex; align-items: center; gap: 0.4rem;">
                                <i class="fa-solid fa-circle-plus"></i> Add First Garment Category
                            </button>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <?php foreach ($chipsToDisplay as $g): 
                        $gCleanKey = strtolower(trim($g['name']));
                        $gTodayCount = $todayMaterialCounts[$gCleanKey] ?? 0;
                        $badgeId = 'chipBadge_' . preg_replace('/[^a-z0-9_]/', '', $gCleanKey);
                        $parentCat = $g['category_name'] ?? '';
                    ?>
                        <button type="button" class="garment-chip-btn" 
                            data-garment="<?= htmlspecialchars($g['name']) ?>" 
                            data-category="<?= htmlspecialchars($parentCat) ?>" 
                            data-stitch-time="<?= (int)($g['stitch_time_seconds'] ?? 0) ?>"
                            onclick="selectGarmentChip('<?= htmlspecialchars(addslashes($g['name'])) ?>', '<?= htmlspecialchars(addslashes($parentCat)) ?>', <?= (int)($g['stitch_time_seconds'] ?? 0) ?>)" 
                            title="Tap to select <?= htmlspecialchars($g['name']) ?><?= !empty($g['stitch_time_seconds']) ? ' (' . $g['stitch_time_seconds'] . 's piece time)' : '' ?>">
                            <div style="display: flex; align-items: center; gap: 0.5rem; min-width: 0;">
                                <i class="fa-solid <?= $g['icon'] ?> garment-chip-icon" style="color: <?= $g['color'] ?>;"></i>
                                <div style="display: flex; flex-direction: column; text-align: left; min-width: 0;">
                                    <span class="garment-chip-title" style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?= htmlspecialchars($g['name']) ?></span>
                                </div>
                            </div>
                            <span class="garment-chip-count-badge" id="<?= $badgeId ?>" style="font-size: 0.72rem; font-weight: 700; padding: 0.15rem 0.5rem; border-radius: 50px; background: <?= $gTodayCount > 0 ? 'rgba(16,185,129,0.22)' : 'rgba(255,255,255,0.06)' ?>; color: <?= $gTodayCount > 0 ? '#10b981' : 'var(--text-muted)' ?>; border: 1px solid <?= $gTodayCount > 0 ? 'rgba(16,185,129,0.45)' : 'rgba(255,255,255,0.08)' ?>; white-space: nowrap; flex-shrink: 0;">
                                <?= $gTodayCount > 0 ? ($gTodayCount . ' pcs') : '0 pcs' ?>
                            </span>
                        </button>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Active Selected Indicator -->
            <div id="selectedCategoryBreadcrumb" style="display: none; align-items: center; justify-content: space-between; margin-top: 0.65rem; padding: 0.5rem 0.85rem; background: rgba(6, 182, 212, 0.1); border: 1px solid rgba(6, 182, 212, 0.3); border-radius: var(--radius-sm); flex-wrap: wrap; gap: 0.5rem;">
                <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                    <i class="fa-solid fa-circle-check text-cyan" style="font-size: 0.9rem;"></i>
                    <span style="font-size: 0.78rem; color: var(--text-muted);">Selected:</span>
                    <strong id="selectedGarmentDisplayName" class="text-cyan" style="font-size: 0.95rem;">--</strong>
                    <span id="breadcrumbText" style="font-size: 0.75rem; color: var(--text-muted);"></span>
                </div>
                <!-- Live Category Stitching Time Badge -->
                <div id="selectedCatTimeBadge" style="display: none; align-items: center; gap: 0.35rem; font-size: 0.78rem; font-weight: 700; color: #fbbf24; background: rgba(245, 158, 11, 0.15); border: 1px solid rgba(245, 158, 11, 0.4); border-radius: 9999px; padding: 0.15rem 0.65rem;">
                    <i class="fa-solid fa-stopwatch"></i>
                    <span>Stitch Cooldown: <strong id="selectedCatTimeVal">0s</strong></span>
                </div>
            </div>

            <!-- Expandable Advanced 3-Tier Selectors -->
            <div id="advancedCategorySection" style="display: none; margin-top: 0.75rem; padding: 0.85rem; background: rgba(0,0,0,0.25); border: 1px dashed rgba(255,255,255,0.12); border-radius: var(--radius-sm);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                    <span style="font-size: 0.78rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Advanced Garment Directory</span>
                    <?php if ($currentUser && ($currentUser['role'] === 'admin' || $currentUser['role'] === 'supervisor')): ?>
                        <a href="categories.php" class="btn btn-outline btn-xs" style="font-size: 0.72rem; padding: 0.15rem 0.45rem;">
                            <i class="fa-solid fa-list-check"></i> Manage Categories
                        </a>
                    <?php endif; ?>
                </div>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 0.65rem;">
                    <div>
                        <label class="form-label" for="tier1CategorySelect" style="font-size: 0.75rem; margin-bottom: 0.2rem;">Main Category</label>
                        <select id="tier1CategorySelect" class="form-control" style="font-size: 0.82rem; padding: 0.35rem 0.6rem;">
                            <option value="">-- Choose Category --</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label" for="tier2SubcategorySelect" style="font-size: 0.75rem; margin-bottom: 0.2rem;">Subcategory</label>
                        <select id="tier2SubcategorySelect" class="form-control" style="font-size: 0.82rem; padding: 0.35rem 0.6rem;" disabled>
                            <option value="">-- Choose Subcategory --</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label" for="tier3GarmentSelect" style="font-size: 0.75rem; margin-bottom: 0.2rem;">Specific Variety (Optional)</label>
                        <select id="tier3GarmentSelect" class="form-control" style="font-size: 0.82rem; padding: 0.35rem 0.6rem;" disabled>
                            <option value="">-- Optional --</option>
                        </select>
                    </div>
                </div>

                <!-- Custom Garment Input for rare clothes -->
                <div id="userCustomClothBox" style="display: none; margin-top: 0.5rem;">
                    <input type="text" id="userCustomClothInput" class="form-control" placeholder="Type custom garment name..." style="font-size: 0.85rem;">
                </div>
            </div>
        </div>

        <!-- STEP 2: Large Tactile Counter + Speed Buttons -->
        <div style="margin-bottom: 1.15rem; background: rgba(255, 255, 255, 0.02); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 0.95rem;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.45rem;">
                <label style="font-size: 0.86rem; font-weight: 700; color: var(--text-primary); margin: 0;">
                    <i class="fa-solid fa-hashtag text-cyan"></i> 2. Pieces Stitched (Quantity) <span class="required">*</span>
                </label>
                <div id="stepperSavedTodayHint" style="font-size: 0.78rem; color: var(--text-muted);">
                    <!-- Dynamic hint -->
                </div>
            </div>

            <!-- Big Touch Stepper -->
            <div class="user-friendly-counter-wrap" style="margin-bottom: 0.25rem;">
                <button type="button" class="uf-counter-btn dec-btn" id="userPieceDecBtn" title="Decrease count (-1)">
                    <i class="fa-solid fa-minus"></i>
                </button>
                <input type="number" id="userPieceCountInput" name="piece_count" class="uf-counter-input" min="0" step="1" max="50000" value="0" placeholder="0" readonly inputmode="none" tabindex="-1" autocomplete="off" onkeydown="return false;" title="Live Piece Count">
            </div>
        </div>

        <!-- STEP 3: Shift & Notes in 1 Compact Row -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-bottom: 1.15rem;">
            <div>
                <label for="userShiftSelect" style="font-size: 0.78rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.25rem; display: block;">
                    <i class="fa-solid fa-clock"></i> Shift
                </label>
                <select id="userShiftSelect" name="shift" class="form-control" style="font-size: 0.85rem; padding: 0.4rem 0.65rem;">
                    <option value="Day Shift" selected>Day Shift</option>
                    <option value="Morning Shift">Morning Shift</option>
                    <option value="Evening Shift">Evening Shift</option>
                    <option value="Night Shift">Night Shift</option>
                    <option value="Overtime">Overtime</option>
                </select>
            </div>
            <div>
                <label for="userNotesInput" style="font-size: 0.78rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.25rem; display: block;">
                    <i class="fa-regular fa-comment-dots"></i> Note (Optional)
                </label>
                <input type="text" id="userNotesInput" name="notes" class="form-control" placeholder="e.g. Batch #1" style="font-size: 0.85rem; padding: 0.4rem 0.65rem;">
            </div>
        </div>

        <!-- STEP 4: Complete (+1) Instant Save Button -->
        <div class="uf-submit-row">
            <button type="button" class="btn btn-outline" id="userResetFormBtn" title="Reset Counter">
                <i class="fa-solid fa-rotate-left"></i>
            </button>

            <!-- Complete Button: Click directly increments count & instantly saves piece to DB under user name! -->
            <button type="button" id="userCompleteBtn" class="btn btn-success uf-submit-btn" style="flex: 1; padding: 0.85rem 1.15rem; font-size: 1.05rem; font-weight: 800; border-radius: var(--radius-sm); display: flex; align-items: center; justify-content: center; gap: 0.55rem; box-shadow: 0 4px 14px rgba(16,185,129,0.3); white-space: nowrap;">
                <i class="fa-solid fa-circle-check" id="userCompleteBtnIcon" style="font-size: 1.15rem;"></i>
                <span id="userCompleteBtnLabel" style="white-space: nowrap;">Complete (+1)</span>
            </button>
        </div>
    </form>
</div>

<!-- 2B. Real-Time Category Priority Leaderboard (e.g. Maternity & Garment-wise Ranking) -->
<div id="clothLeaderboardCard" class="card mb-4" style="display: none; margin-top: 2rem; border-color: rgba(245, 158, 11, 0.35); background: linear-gradient(180deg, rgba(245, 158, 11, 0.04) 0%, rgba(18, 24, 38, 0.95) 100%);">
    <div class="card-header" style="flex-wrap: wrap; gap: 0.75rem;">
        <div>
            <div class="card-title" style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                <span class="badge-pill" style="background: rgba(245, 158, 11, 0.2); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.4); font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px;">
                    <i class="fa-solid fa-trophy"></i> Live Priority Ranking
                </span>
                <span id="clothLbHeadingGarment" class="text-amber" style="font-weight: 800; font-size: 1.05rem;">Maternity Category</span>
                <span id="clothLbUnitBadge" class="badge-pill <?= (($worker['unit'] ?? '') === 'Unit 2') ? 'unit-2' : 'unit-1' ?>" style="font-size: 0.75rem; font-weight: 700;">
                    <?= htmlspecialchars($worker['unit'] ?? 'Unit 1') ?>
                </span>
            </div>
            <p class="text-muted" style="font-size: 0.82rem; margin-top: 0.25rem;">
                Showing operators in <strong id="clothLbUnitSubtitle" class="text-cyan"><?= htmlspecialchars($worker['unit'] ?? 'Unit 1') ?></strong> priority-wise (Highest pieces = 1st Place 🥇).
            </p>
        </div>
        <div style="display: flex; align-items: center; gap: 0.65rem; flex-wrap: wrap;">
            <!-- Unit Filter Tabs for Priority Leaderboard -->
            <div id="clothLbUnitTabs" style="display: inline-flex; background: rgba(0, 0, 0, 0.35); border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 3px; gap: 3px;">
                <?php foreach ($productionUnits as $pu): 
                    $uName = $pu['unit_name'];
                    $isCur = ($worker && $worker['unit'] === $uName);
                    $uColor = ($uName === 'Unit 1') ? 'cyan' : 'pink';
                ?>
                <button type="button" class="btn btn-xs cloth-lb-unit-btn <?= $isCur ? 'active active-' . $uColor : '' ?>" data-unit="<?= htmlspecialchars($uName) ?>" data-color="<?= $uColor ?>" style="padding: 0.25rem 0.65rem; font-size: 0.75rem; border-radius: var(--radius-sm); font-weight: 700;">
                    <i class="fa-solid <?= htmlspecialchars($pu['icon'] ?? 'fa-industry') ?>" style="font-size: 0.7rem;"></i> <?= htmlspecialchars($pu['unit_title']) ?>
                </button>
                <?php endforeach; ?>
                <button type="button" class="btn btn-xs cloth-lb-unit-btn" data-unit="All" data-color="emerald" style="padding: 0.25rem 0.65rem; font-size: 0.75rem; border-radius: var(--radius-sm); font-weight: 600; opacity: 0.85;">
                    <i class="fa-solid fa-layer-group" style="font-size: 0.7rem;"></i> All Units
                </button>
            </div>

            <div id="clothLbActiveBadge" class="badge-pill" style="background: rgba(6, 182, 212, 0.12); color: var(--accent-cyan); border: 1px solid rgba(6, 182, 212, 0.3); font-size: 0.85rem; padding: 0.4rem 0.85rem;">
                <i class="fa-solid fa-users"></i> <span id="clothLbParticipantsCount">0</span> Operators Ranked
            </div>
        </div>
    </div>

    <!-- Category Micro Podium (Top 3 Priority Performers) -->
    <div style="padding: 1rem 1.25rem 0.5rem 1.25rem;">
        <div id="clothLbPodium" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-bottom: 1rem;">
            <!-- Dynamically populated via AJAX -->
        </div>
    </div>

    <!-- Complete Priority Table for Category -->
    <div class="table-responsive">
        <table class="data-table" style="font-size: 0.88rem;">
            <thead>
                <tr>
                    <th style="width: 70px; text-align: center;">Priority</th>
                    <th>Worker Details</th>
                    <th>Unit</th>
                    <th>Role</th>
                    <th style="text-align: right;">Pieces Stitched</th>
                    <th style="text-align: center;">Rank &amp; Status</th>
                </tr>
            </thead>
            <tbody id="clothLbTableBody">
                <!-- Dynamically populated via AJAX -->
            </tbody>
        </table>
    </div>
</div>

<!-- 3. Daily Output KPI Grid -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon emerald">
            <i class="fa-solid fa-shirt"></i>
        </div>
        <div class="stat-info">
            <div class="stat-label">Pieces Stitched On <?= date('d M', strtotime($selectedDate)) ?></div>
            <div class="stat-value text-emerald"><span id="todayPiecesVal"><?= number_format($todayPieces) ?></span> <span style="font-size: 1rem; font-weight: normal;">pcs</span></div>
            <div class="stat-sub"><?= ($selectedDate === date('Y-m-d')) ? "Today's completed output" : "Selected date output" ?></div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon cyan">
            <i class="fa-solid fa-layer-group"></i>
        </div>
        <div class="stat-info">
            <div class="stat-label">Cloth Types Stitched</div>
            <div class="stat-value text-cyan"><span id="todayMaterialsVal"><?= $todayMaterials ?></span></div>
            <div class="stat-sub">Distinct garment batches stitched</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon primary">
            <i class="fa-solid fa-boxes-stacked"></i>
        </div>
        <div class="stat-info">
            <div class="stat-label">Worker Lifetime Total</div>
            <div class="stat-value"><span id="lifetimePiecesVal"><?= number_format($worker['total_pieces']) ?></span> <span style="font-size: 1rem; font-weight: normal;">pcs</span></div>
            <div class="stat-sub">Across all shifts &amp; dates</div>
        </div>
    </div>
</div>

<!-- 5. Lifetime Fabric Breakdown -->
<div class="card" id="lifetimeFabricCardSection" style="<?= empty($allMaterials) ? 'display:none;' : '' ?>">
    <div class="card-header" style="flex-wrap: wrap; gap: 0.65rem;">
        <div class="card-title">
            <i class="fa-solid fa-chart-pie"></i> Lifetime Fabric Breakdown for <?= htmlspecialchars($worker['name']) ?>
        </div>
        <div style="display: flex; align-items: center; gap: 0.45rem; flex-wrap: wrap;">
            <span class="badge-pill unit-cyan-pill" style="font-size: 0.72rem; padding: 0.15rem 0.5rem;">
                <i class="fa-solid fa-industry"></i> Unit 1: <strong id="lifetimeUnit1TotalHeader"><?= number_format($u1Lifetime) ?></strong> pcs
            </span>
            <span class="badge-pill unit-pink-pill" style="font-size: 0.72rem; padding: 0.15rem 0.5rem;">
                <i class="fa-solid fa-warehouse"></i> Unit 2: <strong id="lifetimeUnit2TotalHeader"><?= number_format($u2Lifetime) ?></strong> pcs
            </span>
            <span class="text-muted" style="font-size: 0.82rem;" id="lifetimeFabricTotalHeader">
                Combined Total: <strong style="color: var(--text-primary);"><?= number_format($worker['total_pieces']) ?></strong> pcs
            </span>
        </div>
    </div>

    <div id="lifetimeFabricCardsContainer" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem;">
        <?php foreach ($allMaterials as $m): ?>
            <div style="background: rgba(255,255,255,0.03); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 1rem;">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.45rem;">
                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fa-solid fa-cube text-cyan"></i>
                        <strong style="font-size: 0.92rem;"><?= htmlspecialchars($m['material_name']) ?></strong>
                    </div>
                </div>
                <div class="text-emerald" style="font-family: var(--font-heading); font-size: 1.3rem; font-weight: 800;">
                    <?= number_format($m['total']) ?> <span style="font-size: 0.8rem; color: var(--text-muted); font-weight: normal;">pcs</span>
                </div>
                <div style="display: flex; gap: 0.35rem; flex-wrap: wrap; margin-top: 0.45rem;">
                    <?php if (!empty($m['unit1_total'])): ?>
                        <span class="badge-pill unit-cyan-pill" style="font-size: 0.65rem; padding: 0.08rem 0.38rem;">
                            <i class="fa-solid fa-industry" style="font-size: 0.55rem;"></i> Unit 1: <?= number_format($m['unit1_total']) ?>
                        </span>
                    <?php endif; ?>
                    <?php if (!empty($m['unit2_total'])): ?>
                        <span class="badge-pill unit-pink-pill" style="font-size: 0.65rem; padding: 0.08rem 0.38rem;">
                            <i class="fa-solid fa-warehouse" style="font-size: 0.55rem;"></i> Unit 2: <?= number_format($m['unit2_total']) ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="text-muted" style="font-size: 0.72rem; margin-top: 0.3rem;">
                    <?= $m['entries'] ?> logged batch(es)
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php endif; ?>

<!-- 6. UNIT 1 & UNIT 2 CATEGORY PRODUCTION LEADERBOARDS SLIDER -->
<?php include __DIR__ . '/includes/unit_category_slider.php'; ?>

<script>
// Client-side controller for Dedicated User Stitching Workstation
document.addEventListener('DOMContentLoaded', () => {
    const stitchForm = document.getElementById('userStitchForm');
    const clothSelect = document.getElementById('userClothSelect');
    const tier1Select = document.getElementById('tier1CategorySelect');
    const tier2Select = document.getElementById('tier2SubcategorySelect');
    const tier3Select = document.getElementById('tier3GarmentSelect');
    const breadcrumbBox = document.getElementById('selectedCategoryBreadcrumb');
    const breadcrumbText = document.getElementById('breadcrumbText');
    let dynamicGarmentTree = <?= json_encode($garmentTree) ?>;
    const customClothBox = document.getElementById('userCustomClothBox');
    const customClothInput = document.getElementById('userCustomClothInput');
    const userShiftSelect = document.getElementById('userShiftSelect');
    const pieceInput = document.getElementById('userPieceCountInput');
    const decBtn = document.getElementById('userPieceDecBtn');
    const incBtn = document.getElementById('userPieceIncBtn');
    const clearBtn = document.getElementById('userPieceClearBtn');
    const resetFormBtn = document.getElementById('userResetFormBtn');
    const completeBtn = document.getElementById('userCompleteBtn');
    const completeBtnLabel = document.getElementById('userCompleteBtnLabel');
    const completeBtnIcon = document.getElementById('userCompleteBtnIcon');
    const saveBtn = document.getElementById('userSaveBtn');
    const saveBtnLabel = document.getElementById('userSaveBtnLabel');
    const saveBtnIcon = document.getElementById('userSaveBtnIcon');
    const cooldownContainer = document.getElementById('stitchCooldownContainer');
    const cooldownSecondsText = document.getElementById('cooldownSecondsText');
    const cooldownProgressBar = document.getElementById('cooldownProgressBar');
    const stepBtns = document.querySelectorAll('.user-step-btn');
    const successBanner = document.getElementById('stitchSuccessBanner');
    const clothStatusBadge = document.getElementById('clothStatusBadge');
    const clothCurrentLoggedCount = document.getElementById('clothCurrentLoggedCount');

    let currentCooldownDuration = 0;
    try { localStorage.removeItem('fabriqpro_cooldown_' + currentWorkerId); } catch (e) {}
    let cooldownTimerInterval = null;
    let isCooldownActive = false;

    function getCooldownRemaining() {
        const key = 'fabriqpro_cooldown_' + currentWorkerId;
        const savedEndTime = parseInt(localStorage.getItem(key) || '0');
        const now = Date.now();
        if (savedEndTime > now) {
            return Math.ceil((savedEndTime - now) / 1000);
        }
        return 0;
    }

    function startCooldownTimer(seconds) {
        if (seconds === undefined || seconds === null) {
            seconds = currentCooldownDuration;
        }
        if (cooldownTimerInterval) clearInterval(cooldownTimerInterval);

        if (seconds <= 0) {
            isCooldownActive = false;
            const key = 'fabriqpro_cooldown_' + currentWorkerId;
            localStorage.removeItem(key);
            if (cooldownContainer) cooldownContainer.style.display = 'none';
            if (completeBtn) {
                completeBtn.disabled = false;
                completeBtn.style.opacity = '1';
                completeBtn.style.cursor = 'pointer';
                completeBtn.classList.add('btn-ready-pulse');
            }
            if (completeBtnLabel) completeBtnLabel.innerText = 'Complete (+1)';
            if (completeBtnIcon) completeBtnIcon.className = 'fa-solid fa-circle-check';
            return;
        }

        const key = 'fabriqpro_cooldown_' + currentWorkerId;
        const endTime = Date.now() + (seconds * 1000);
        localStorage.setItem(key, endTime);
        isCooldownActive = true;

        if (cooldownContainer) cooldownContainer.style.display = 'flex';

        function updateTick() {
            const remaining = getCooldownRemaining();
            if (remaining > 0) {
                if (completeBtn) {
                    completeBtn.disabled = true;
                    completeBtn.style.opacity = '0.65';
                    completeBtn.style.cursor = 'not-allowed';
                    completeBtn.classList.remove('btn-ready-pulse');
                }
                if (completeBtnLabel) completeBtnLabel.innerText = `Wait (${remaining}s)`;
                if (completeBtnIcon) completeBtnIcon.className = 'fa-solid fa-hourglass-half fa-spin';
                if (cooldownSecondsText) cooldownSecondsText.innerText = remaining + 's';
                if (cooldownProgressBar) {
                    const totalSec = currentCooldownDuration > 0 ? currentCooldownDuration : 30;
                    const pct = Math.max(0, Math.min(100, (remaining / totalSec) * 100));
                    cooldownProgressBar.style.width = pct + '%';
                }
            } else {
                clearInterval(cooldownTimerInterval);
                cooldownTimerInterval = null;
                isCooldownActive = false;
                localStorage.removeItem(key);

                if (cooldownContainer) cooldownContainer.style.display = 'none';

                if (completeBtn) {
                    completeBtn.disabled = false;
                    completeBtn.style.opacity = '1';
                    completeBtn.style.cursor = 'pointer';
                    completeBtn.classList.add('btn-ready-pulse');
                }
                if (completeBtnLabel) completeBtnLabel.innerText = 'Complete (+1)';
                if (completeBtnIcon) completeBtnIcon.className = 'fa-solid fa-circle-check';
                if (cooldownProgressBar) cooldownProgressBar.style.width = '100%';
            }
        }

        updateTick();
        cooldownTimerInterval = setInterval(updateTick, 1000);
    }

    // Helper to get today's existing piece count for a selected cloth & shift
    function getMaterialCurrentCount(matName, shiftName) {
        if (!matName || !window.todayMaterialCounts) return 0;
        const cleanMat = matName.toLowerCase().trim();
        const cleanShift = (shiftName || '').toLowerCase().trim();
        // Check shift specific first if positive
        if (window.todayMaterialCounts[cleanMat + '|' + cleanShift] !== undefined && window.todayMaterialCounts[cleanMat + '|' + cleanShift] > 0) {
            return window.todayMaterialCounts[cleanMat + '|' + cleanShift];
        }
        // Fallback to overall daily total for this garment across today (12:00 AM - 11:59 PM)
        if (window.todayMaterialCounts[cleanMat] !== undefined) {
            return window.todayMaterialCounts[cleanMat];
        }
        return 0;
    }

    const currentWorkerId = <?= (int)$worker['id'] ?>;
    let currentClothLbUnit = "<?= htmlspecialchars($worker['unit'] ?? 'Unit 1') ?>";
    let lastFetchedClothMaterial = '';

    // Fetch and render dynamic Priority Leaderboard for selected category (e.g. Maternity)
    async function fetchClothLeaderboard(materialName, targetUnit = null) {
        const card = document.getElementById('clothLeaderboardCard');
        const headingGarment = document.getElementById('clothLbHeadingGarment');
        const unitBadge = document.getElementById('clothLbUnitBadge');
        const unitSubtitle = document.getElementById('clothLbUnitSubtitle');
        const participantsCount = document.getElementById('clothLbParticipantsCount');
        const podiumContainer = document.getElementById('clothLbPodium');
        const tableBody = document.getElementById('clothLbTableBody');
        
        if (targetUnit !== null) {
            currentClothLbUnit = targetUnit;
        }

        if (!materialName || materialName === '__custom__') {
            if (card) card.style.display = 'none';
            return;
        }

        lastFetchedClothMaterial = materialName;

        if (card) card.style.display = 'block';
        if (headingGarment) {
            const isMaternity = materialName.toLowerCase().includes('maternity');
            headingGarment.innerHTML = (isMaternity ? '🤰 ' : '👕 ') + escapeHtml(materialName) + ' Priority Ranking';
        }

        if (unitBadge) {
            if (currentClothLbUnit && currentClothLbUnit !== 'All') {
                const isU1 = currentClothLbUnit === 'Unit 1';
                unitBadge.style.display = 'inline-block';
                unitBadge.className = 'badge-pill ' + (isU1 ? 'unit-1' : 'unit-2');
                unitBadge.innerText = currentClothLbUnit;
            } else {
                unitBadge.style.display = 'inline-block';
                unitBadge.className = 'badge-pill';
                unitBadge.style.background = 'rgba(255,255,255,0.08)';
                unitBadge.style.color = 'var(--text-secondary)';
                unitBadge.innerText = 'All Units';
            }
        }

        if (unitSubtitle) {
            unitSubtitle.innerText = (currentClothLbUnit && currentClothLbUnit !== 'All') ? currentClothLbUnit : 'All Production Units';
        }

        // Highlight active unit button
        document.querySelectorAll('.cloth-lb-unit-btn').forEach(btn => {
            const btnUnit = btn.getAttribute('data-unit');
            const col = btn.getAttribute('data-color') || 'cyan';
            if (btnUnit === currentClothLbUnit) {
                btn.classList.add('active', 'active-' + col);
            } else {
                btn.classList.remove('active', 'active-cyan', 'active-pink', 'active-emerald');
            }
        });

        const unitLabel = (currentClothLbUnit && currentClothLbUnit !== 'All') ? currentClothLbUnit : 'all units';
        if (tableBody) tableBody.innerHTML = `<tr><td colspan="6" class="text-center" style="padding: 2rem;"><i class="fa-solid fa-spinner fa-spin text-cyan"></i> Assembling ${escapeHtml(unitLabel)} operator rankings priority-wise...</td></tr>`;

        try {
            const unitParam = (currentClothLbUnit && currentClothLbUnit !== 'All') ? `&unit=${encodeURIComponent(currentClothLbUnit)}` : '';
            const dateParam = (window.currentSelectedDate) ? `&date=${encodeURIComponent(window.currentSelectedDate)}` : '';
            const res = await fetch(`api.php?action=get_cloth_leaderboard&material=${encodeURIComponent(materialName)}${unitParam}${dateParam}`);
            const result = await res.json();

            if (result.success && result.data) {
                const workers = result.data;
                const activeWorkers = workers.filter(w => parseInt(w.category_pieces) > 0);
                if (participantsCount) participantsCount.innerText = activeWorkers.length;

                // Render Micro Podium (Top 3 Priority Performers with pieces > 0)
                if (podiumContainer) {
                    const top3 = activeWorkers.slice(0, 3);
                    if (top3.length === 0) {
                        podiumContainer.innerHTML = `
                            <div style="grid-column: 1 / -1; text-align: center; padding: 1.5rem; background: rgba(255,255,255,0.02); border: 1px dashed rgba(255,255,255,0.1); border-radius: var(--radius-md);">
                                <i class="fa-solid fa-shirt text-muted" style="font-size: 1.5rem; margin-bottom: 0.5rem; opacity: 0.5;"></i>
                                <div style="color: var(--text-muted); font-size: 0.88rem;">No operators have completed pieces for <strong>${escapeHtml(materialName)}</strong> yet. Log pieces above to take 1st place!</div>
                            </div>
                        `;
                    } else {
                        let podiumHtml = '';
                        top3.forEach((w, idx) => {
                            const rankNum = idx + 1;
                            const isCurrentUser = (w.id == currentWorkerId);
                            let medalBadge = '';
                            let borderColor = 'rgba(255,255,255,0.08)';
                            let bgGlow = 'rgba(255,255,255,0.02)';

                            if (rankNum === 1) {
                                medalBadge = '<span style="background: rgba(251, 191, 36, 0.2); color: #fbbf24; border: 1px solid rgba(251, 191, 36, 0.4); padding: 0.25rem 0.6rem; border-radius: 9999px; font-weight: 800; font-size: 0.75rem;"><i class="fa-solid fa-crown"></i> 1st Place (Priority #1)</span>';
                                borderColor = 'rgba(251, 191, 36, 0.45)';
                                bgGlow = 'linear-gradient(180deg, rgba(251, 191, 36, 0.12) 0%, rgba(18, 24, 38, 0.95) 100%)';
                            } else if (rankNum === 2) {
                                medalBadge = '<span style="background: rgba(203, 213, 225, 0.2); color: #cbd5e1; border: 1px solid rgba(203, 213, 225, 0.4); padding: 0.25rem 0.6rem; border-radius: 9999px; font-weight: 800; font-size: 0.75rem;"><i class="fa-solid fa-medal"></i> 2nd Place (Priority #2)</span>';
                                borderColor = 'rgba(203, 213, 225, 0.4)';
                                bgGlow = 'linear-gradient(180deg, rgba(203, 213, 225, 0.08) 0%, rgba(18, 24, 38, 0.95) 100%)';
                            } else if (rankNum === 3) {
                                medalBadge = '<span style="background: rgba(217, 119, 6, 0.2); color: #f59e0b; border: 1px solid rgba(217, 119, 6, 0.4); padding: 0.25rem 0.6rem; border-radius: 9999px; font-weight: 800; font-size: 0.75rem;"><i class="fa-solid fa-award"></i> 3rd Place (Priority #3)</span>';
                                borderColor = 'rgba(217, 119, 6, 0.35)';
                                bgGlow = 'linear-gradient(180deg, rgba(217, 119, 6, 0.06) 0%, rgba(18, 24, 38, 0.95) 100%)';
                            }

                            podiumHtml += `
                                <div style="background: ${bgGlow}; border: 1px solid ${borderColor}; border-radius: var(--radius-md); padding: 1.15rem; position: relative; transition: all 0.3s ease; box-shadow: ${rankNum === 1 ? '0 0 20px rgba(251,191,36,0.15)' : 'none'};">
                                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.75rem;">
                                        ${medalBadge}
                                        ${isCurrentUser ? '<span class="badge-pill" style="background: rgba(6, 182, 212, 0.2); color: var(--accent-cyan); font-size: 0.72rem; border: 1px solid rgba(6, 182, 212, 0.4);"><i class="fa-solid fa-user-check"></i> YOU</span>' : ''}
                                    </div>
                                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                                        <div style="width: 42px; height: 42px; border-radius: 50%; background: ${rankNum === 1 ? 'linear-gradient(135deg, #f59e0b, #d97706)' : 'rgba(255,255,255,0.08)'}; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 1.1rem; color: #fff; border: 2px solid ${borderColor}; flex-shrink: 0;">
                                            ${escapeHtml(w.name.charAt(0).toUpperCase())}
                                        </div>
                                        <div style="min-width: 0; flex: 1;">
                                            <div style="font-weight: 700; font-size: 0.98rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: ${isCurrentUser ? 'var(--accent-cyan)' : 'var(--text-primary)'};">
                                                ${escapeHtml(w.name)}
                                            </div>
                                            <div style="display: flex; align-items: center; gap: 0.4rem; font-size: 0.78rem; color: var(--text-muted); flex-wrap: wrap;">
                                                <span class="badge-pill" style="font-size: 0.68rem; padding: 0.15rem 0.45rem; background: ${w.unit === 'Unit 1' ? 'var(--unit1-bg)' : 'var(--unit2-bg)'}; color: ${w.unit === 'Unit 1' ? 'var(--unit1-color)' : 'var(--unit2-color)'}; font-weight: 700;">
                                                    <i class="fa-solid fa-building" style="font-size: 0.6rem;"></i> ${escapeHtml(w.unit)}
                                                </span>
                                                ${w.is_switched && w.transferred_from ? `
                                                    <span class="badge-pill" style="font-size: 0.65rem; padding: 0.1rem 0.4rem; background: rgba(245, 158, 11, 0.16); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.4); font-weight: 700; display: inline-flex; align-items: center; gap: 0.2rem;" title="Switched from ${escapeHtml(w.transferred_from)}">
                                                        <i class="fa-solid fa-arrow-right-arrow-left" style="font-size: 0.55rem;"></i> Switched from ${escapeHtml(w.transferred_from)}
                                                    </span>
                                                ` : ''}
                                                <span>• ${escapeHtml(w.role || 'Tailor')}</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div style="margin-top: 0.9rem; padding-top: 0.75rem; border-top: 1px dashed rgba(255,255,255,0.08); display: flex; align-items: baseline; justify-content: space-between;">
                                        <span class="text-muted" style="font-size: 0.8rem;">Stitched Pieces:</span>
                                        <span style="font-family: var(--font-heading); font-size: 1.35rem; font-weight: 800; color: ${rankNum === 1 ? '#fbbf24' : 'var(--accent-emerald)'};">
                                            ${w.category_pieces.toLocaleString()} <span style="font-size: 0.8rem; font-weight: normal; color: var(--text-muted);">pcs</span>
                                        </span>
                                    </div>
                                </div>
                            `;
                        });
                        podiumContainer.innerHTML = podiumHtml;
                    }
                }

                // Render Full Priority Ranking Table
                if (tableBody) {
                    if (workers.length === 0) {
                        tableBody.innerHTML = `<tr><td colspan="6" class="text-center text-muted" style="padding: 2rem;">No operator records found for this garment.</td></tr>`;
                        return;
                    }

                    let rowsHtml = '';
                    let activeRank = 1;
                    workers.forEach((w) => {
                        const hasPieces = (parseInt(w.category_pieces) > 0);
                        const rankNum = hasPieces ? activeRank++ : null;
                        const isCurrentUser = (w.id == currentWorkerId);
                        
                        let rankBadge = '';
                        let statusLabel = '';
                        let rowStyle = isCurrentUser ? 'background: rgba(6, 182, 212, 0.08); border-left: 3px solid var(--accent-cyan);' : '';

                        if (rankNum === 1) {
                            rankBadge = `<span class="badge-pill" style="background: rgba(251, 191, 36, 0.2); color: #fbbf24; border: 1px solid rgba(251, 191, 36, 0.5); font-weight: 800; font-size: 0.85rem;"><i class="fa-solid fa-crown"></i> #1</span>`;
                            statusLabel = `<span class="badge-pill" style="background: rgba(251, 191, 36, 0.15); color: #fbbf24; font-size: 0.78rem; font-weight: 700;"><i class="fa-solid fa-trophy"></i> 1st Place (Priority Leader)</span>`;
                        } else if (rankNum === 2) {
                            rankBadge = `<span class="badge-pill" style="background: rgba(203, 213, 225, 0.2); color: #cbd5e1; border: 1px solid rgba(203, 213, 225, 0.5); font-weight: 800; font-size: 0.85rem;"><i class="fa-solid fa-medal"></i> #2</span>`;
                            statusLabel = `<span class="badge-pill" style="background: rgba(203, 213, 225, 0.15); color: #cbd5e1; font-size: 0.78rem; font-weight: 700;">🥈 2nd Place</span>`;
                        } else if (rankNum === 3) {
                            rankBadge = `<span class="badge-pill" style="background: rgba(217, 119, 6, 0.2); color: #f59e0b; border: 1px solid rgba(217, 119, 6, 0.5); font-weight: 800; font-size: 0.85rem;"><i class="fa-solid fa-award"></i> #3</span>`;
                            statusLabel = `<span class="badge-pill" style="background: rgba(217, 119, 6, 0.15); color: #f59e0b; font-size: 0.78rem; font-weight: 700;">🥉 3rd Place</span>`;
                        } else if (rankNum) {
                            rankBadge = `<span class="text-muted font-bold" style="font-size: 0.9rem;">#${rankNum}</span>`;
                            statusLabel = `<span class="text-muted" style="font-size: 0.78rem;">Rank #${rankNum}</span>`;
                        } else {
                            rankBadge = `<span class="text-muted" style="font-size: 0.82rem;">-</span>`;
                            statusLabel = `<span class="text-muted" style="font-size: 0.78rem;">No pieces yet</span>`;
                        }

                        if (isCurrentUser) {
                            statusLabel += ` <span class="badge-pill" style="background: rgba(6, 182, 212, 0.2); color: var(--accent-cyan); font-size: 0.75rem; border: 1px solid rgba(6, 182, 212, 0.4); margin-left: 0.35rem;"><i class="fa-solid fa-user-check"></i> YOU</span>`;
                        }

                        rowsHtml += `
                            <tr style="${rowStyle}">
                                <td style="text-align: center;">${rankBadge}</td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 0.65rem;">
                                        <div style="width: 32px; height: 32px; border-radius: 50%; background: ${isCurrentUser ? 'var(--primary)' : 'rgba(255,255,255,0.06)'}; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.85rem; color: #fff;">
                                            ${escapeHtml(w.name.charAt(0).toUpperCase())}
                                        </div>
                                        <div>
                                            <div style="font-weight: 700; color: ${isCurrentUser ? 'var(--accent-cyan)' : 'var(--text-primary)'};">
                                                ${escapeHtml(w.name)}
                                            </div>
                                            <div style="display: flex; align-items: center; gap: 0.35rem; margin-top: 0.15rem; flex-wrap: wrap;">
                                                <span class="text-muted" style="font-size: 0.75rem; font-family: monospace;">${escapeHtml(w.unique_id || '')}</span>
                                                ${w.is_switched && w.transferred_from ? `
                                                    <span style="font-size: 0.65rem; font-weight: 700; color: #fbbf24; background: rgba(245, 158, 11, 0.12); padding: 0.05rem 0.35rem; border-radius: 4px; border: 1px solid rgba(245, 158, 11, 0.3); display: inline-flex; align-items: center; gap: 0.2rem;">
                                                        <i class="fa-solid fa-bolt" style="font-size: 0.55rem;"></i> Emergency Support
                                                    </span>
                                                ` : ''}
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div style="display: flex; flex-direction: column; gap: 0.25rem; align-items: flex-start;">
                                        <span class="badge-pill" style="font-size: 0.78rem; background: ${w.unit === 'Unit 1' ? 'var(--unit1-bg)' : 'var(--unit2-bg)'}; color: ${w.unit === 'Unit 1' ? 'var(--unit1-color)' : 'var(--unit2-color)'}; font-weight: 700;">
                                            <i class="fa-solid fa-building" style="font-size: 0.7rem;"></i> ${escapeHtml(w.unit)}
                                        </span>
                                        ${w.is_switched && w.transferred_from ? `
                                            <span class="badge-pill" style="font-size: 0.68rem; padding: 0.12rem 0.45rem; background: rgba(245, 158, 11, 0.16); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.4); font-weight: 700; display: inline-flex; align-items: center; gap: 0.25rem;" title="Switched from ${escapeHtml(w.transferred_from)} (${escapeHtml(w.transfer_type === 'permanent' ? 'Permanent Transfer' : 'Emergency Support')})">
                                                <i class="fa-solid fa-arrow-right-arrow-left" style="font-size: 0.6rem;"></i> Switched from ${escapeHtml(w.transferred_from)}
                                            </span>
                                        ` : ''}
                                    </div>
                                </td>
                                <td>
                                    <span class="text-muted" style="font-size: 0.82rem;">${escapeHtml(w.role || 'Tailor')}</span>
                                </td>
                                <td style="text-align: right;">
                                    <span class="piece-count-highlight" style="font-size: 1.15rem; color: ${w.category_pieces > 0 ? 'var(--accent-emerald)' : 'var(--text-muted)'};">
                                        <strong>${w.category_pieces.toLocaleString()}</strong> <span style="font-size: 0.78rem; font-weight: normal; color: var(--text-muted);">pcs</span>
                                    </span>
                                </td>
                                <td style="text-align: center;">
                                    ${statusLabel}
                                </td>
                            </tr>
                        `;
                    });
                    tableBody.innerHTML = rowsHtml;
                }
            }
        } catch (e) {
            console.error('Error fetching cloth priority leaderboard:', e);
            if (tableBody) tableBody.innerHTML = `<tr><td colspan="6" class="text-center text-rose" style="padding: 2rem;">Failed to load priority leaderboard.</td></tr>`;
        }
    }

    // Unit Filter Tabs click listener for Priority Leaderboard
    document.querySelectorAll('.cloth-lb-unit-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const u = btn.getAttribute('data-unit');
            if (lastFetchedClothMaterial) {
                fetchClothLeaderboard(lastFetchedClothMaterial, u);
            } else {
                currentClothLbUnit = u;
                document.querySelectorAll('.cloth-lb-unit-btn').forEach(b => {
                    const col = b.getAttribute('data-color') || 'cyan';
                    if (b.getAttribute('data-unit') === u) {
                        b.classList.add('active', 'active-' + col);
                    } else {
                        b.classList.remove('active', 'active-cyan', 'active-pink', 'active-emerald');
                    }
                });
            }
        });
    });

    // Cloth dropdown change listener: loads existing piece count into box & loads Priority Leaderboard
    // Cloth dropdown change listener: loads existing piece count into box & loads Priority Leaderboard
    if (clothSelect) {
        clothSelect.addEventListener('change', () => {
            if (clothSelect.value === '__manage_categories__') {
                clothSelect.value = '';
                if (typeof openCategoryModal === 'function') {
                    openCategoryModal();
                }
                return;
            }
            if (clothSelect.value === '__custom__') {
                if (customClothBox) customClothBox.style.display = 'block';
                if (customClothInput) customClothInput.focus();
            } else {
                if (customClothBox) customClothBox.style.display = 'none';
            }

            const selectedMat = clothSelect.value.trim();
            const currentShift = userShiftSelect ? userShiftSelect.value : 'Day Shift';
            if (selectedMat && selectedMat !== '__custom__') {
                const existingCount = getMaterialCurrentCount(selectedMat, currentShift);
                
                if (clothStatusBadge) clothStatusBadge.style.display = 'flex';
                if (clothCurrentLoggedCount) clothCurrentLoggedCount.innerText = existingCount + ' pcs';

                if (pieceInput) {
                    pieceInput.value = existingCount;
                }

                updateBtn();
                fetchClothLeaderboard(selectedMat);
            } else {
                if (clothStatusBadge) clothStatusBadge.style.display = 'none';
                updateBtn();
                const card = document.getElementById('clothLeaderboardCard');
                if (card) card.style.display = 'none';
            }
        });
    }

    // Shift change listener: updates counter for selected cloth in that shift
    if (userShiftSelect) {
        userShiftSelect.addEventListener('change', () => {
            const selectedMat = clothSelect ? clothSelect.value.trim() : '';
            if (selectedMat && selectedMat !== '__custom__') {
                const existingCount = getMaterialCurrentCount(selectedMat, userShiftSelect.value);
                if (clothStatusBadge) clothStatusBadge.style.display = 'flex';
                if (clothCurrentLoggedCount) clothCurrentLoggedCount.innerText = existingCount + ' pcs';
                updateBtn();
            }
        });
    }

    // Fast 1-Tap Garment Chip Selection
    window.selectGarmentChip = function(matName, parentCatName = '', explicitStitchTime = null) {
        if (!matName) return;

        // 1. Highlight clicked chip & extract category stitch time
        const allChips = document.querySelectorAll('.garment-chip-btn');
        let foundParentCat = parentCatName;
        let catStitchTime = (explicitStitchTime !== null && explicitStitchTime !== undefined) ? parseInt(explicitStitchTime) : 0;

        allChips.forEach(btn => {
            if (btn.dataset.garment && btn.dataset.garment.toLowerCase() === matName.toLowerCase()) {
                btn.classList.add('active');
                if (!foundParentCat && btn.dataset.category) {
                    foundParentCat = btn.dataset.category;
                }
                if (!catStitchTime && btn.dataset.stitchTime) {
                    catStitchTime = parseInt(btn.dataset.stitchTime) || 0;
                }
            } else {
                btn.classList.remove('active');
            }
        });

        if (!foundParentCat && window.assignedCategory) {
            foundParentCat = window.assignedCategory;
        }

        currentCooldownDuration = catStitchTime;

        // 2. Update category standard time badge in breadcrumb
        const timeBadge = document.getElementById('selectedCatTimeBadge');
        const timeVal = document.getElementById('selectedCatTimeVal');
        if (timeBadge && timeVal) {
            if (catStitchTime > 0) {
                timeVal.innerText = catStitchTime + 's';
                timeBadge.style.display = 'inline-flex';
            } else {
                timeBadge.style.display = 'none';
            }
        }

        // 3. Set hidden cloth select value
        if (clothSelect) clothSelect.value = matName;

        // 4. Update breadcrumb and active indicator
        const displayNameEl = document.getElementById('selectedGarmentDisplayName');
        if (displayNameEl) displayNameEl.innerText = matName;
        if (breadcrumbText) {
            breadcrumbText.innerText = '';
        }
        if (breadcrumbBox) breadcrumbBox.style.display = 'flex';

        // 5. Load today's count for this garment & shift
        const currentShift = userShiftSelect ? userShiftSelect.value : 'Day Shift';
        const existingCount = getMaterialCurrentCount(matName, currentShift);
        if (pieceInput) {
            pieceInput.value = existingCount;
        }
        if (clothCurrentLoggedCount) {
            clothCurrentLoggedCount.innerText = existingCount + ' pcs';
        }
        if (clothStatusBadge) {
            clothStatusBadge.style.display = 'flex';
        }

        updateBtn();
        fetchClothLeaderboard(matName);

        // Store active garment selection in localStorage to persist across refreshes / logins
        try {
            localStorage.setItem('fabriqpro_last_garment_' + currentWorkerId, matName);
        } catch (e) {}
    };

    window.currentChipsMode = window.showSubcategoriesOnly ? 'subcategory' : 'category';

    window.switchGarmentViewMode = function(mode) {
        window.currentChipsMode = mode;
        const btnSub = document.getElementById('btnModeSubcat');
        const btnCat = document.getElementById('btnModeCat');
        const titleLabel = document.getElementById('step1LabelTitle');

        if (btnSub && btnCat) {
            if (mode === 'subcategory') {
                btnSub.classList.add('active');
                btnSub.style.background = 'rgba(16,185,129,0.25)';
                btnSub.style.color = '#34d399';
                btnCat.classList.remove('active');
                btnCat.style.background = 'transparent';
                btnCat.style.color = 'var(--text-muted)';
                if (titleLabel) titleLabel.innerText = 'Select Subcategory';
            } else {
                btnCat.classList.add('active');
                btnCat.style.background = 'rgba(6,182,212,0.25)';
                btnCat.style.color = '#06b6d4';
                btnSub.classList.remove('active');
                btnSub.style.background = 'transparent';
                btnSub.style.color = 'var(--text-muted)';
                if (titleLabel) titleLabel.innerText = 'Select Garment';
            }
        }

        renderGarmentChips(dynamicGarmentTree);
    };

    // Toggle Expandable Advanced 3-Tier Garment Selectors
    const btnToggleCat = document.getElementById('btnToggleAllCategories');
    const advCatSection = document.getElementById('advancedCategorySection');
    const moreCatLabel = document.getElementById('moreCategoriesBtnLabel');

    if (btnToggleCat && advCatSection) {
        btnToggleCat.addEventListener('click', () => {
            const isHidden = (advCatSection.style.display === 'none' || !advCatSection.style.display);
            advCatSection.style.display = isHidden ? 'block' : 'none';
            if (moreCatLabel) {
                moreCatLabel.innerText = isHidden ? 'Hide Advanced' : 'More / Custom';
            }
        });
    }

    // Dynamic Button State Controller
    function updateBtn() {
        const stepperHint = document.getElementById('stepperSavedTodayHint');

        let selectedMat = clothSelect ? clothSelect.value.trim() : '';
        if (!selectedMat) {
            if (completeBtn) {
                completeBtn.classList.remove('btn-ready-pulse');
                completeBtn.disabled = true;
                completeBtn.style.opacity = '0.65';
                completeBtn.style.cursor = 'not-allowed';
            }
            if (completeBtnLabel) completeBtnLabel.innerText = 'Select Garment';
            if (saveBtn) {
                saveBtn.disabled = true;
                saveBtn.style.opacity = '0.65';
                saveBtn.style.cursor = 'not-allowed';
            }
            if (stepperHint) stepperHint.innerHTML = '<span class="text-amber">👆 Tap a garment above to start</span>';
            return;
        }

        const currentShift = userShiftSelect ? userShiftSelect.value : 'Day Shift';
        const existingCount = parseInt(getMaterialCurrentCount(selectedMat, currentShift)) || 0;
        const currentInBox = parseInt(pieceInput ? pieceInput.value : 0) || 0;

        // Complete button state
        if (completeBtn) {
            completeBtn.disabled = false;
            completeBtn.style.opacity = '1';
            completeBtn.style.cursor = 'pointer';
            completeBtn.classList.add('btn-ready-pulse');
            const diff = (currentInBox > existingCount) ? (currentInBox - existingCount) : 1;
            if (completeBtnLabel) {
                completeBtnLabel.innerText = (diff > 1) ? `Complete (+${diff})` : 'Complete (+1)';
            }
        }

        if (stepperHint) {
            const dateTxt = window.isCurrentDateToday ? "today" : "on " + (window.currentSelectedDate || "this date");
            if (currentInBox > existingCount) {
                const diff = currentInBox - existingCount;
                stepperHint.innerHTML = `Saved in DB: <strong class="text-muted">${existingCount} pcs</strong> • Ready to Add: <strong class="text-cyan">+${diff} pcs</strong> (Click Complete).`;
            } else if (existingCount > 0) {
                stepperHint.innerHTML = `Saved in DB (${dateTxt}): <strong class="text-emerald">${existingCount} pcs</strong>. Click Complete (+1) after stitching.`;
            } else {
                stepperHint.innerHTML = `Starts at <strong>0</strong>. Click <strong class="text-emerald">Complete (+1)</strong> when garment finished.`;
            }
        }
    }

    if (pieceInput) {
        // Block manual typing, paste, and drag-drop into the piece counter
        pieceInput.addEventListener('keydown', (e) => e.preventDefault());
        pieceInput.addEventListener('paste', (e) => e.preventDefault());
        pieceInput.addEventListener('drop', (e) => e.preventDefault());
        pieceInput.addEventListener('input', () => {
            const v = parseInt(pieceInput.value);
            if (isNaN(v) || v < 0) pieceInput.value = 0;
            updateBtn();
        });
        pieceInput.addEventListener('change', updateBtn);
    }

    if (decBtn && pieceInput) {
        decBtn.addEventListener('click', () => {
            const cur = parseInt(pieceInput.value) || 0;
            pieceInput.value = Math.max(0, cur - 1);
            updateBtn();
        });
    }

    if (incBtn && pieceInput) {
        incBtn.addEventListener('click', () => {
            const cur = parseInt(pieceInput.value) || 0;
            pieceInput.value = cur + 1;
            updateBtn();
        });
    }



    if (resetFormBtn && pieceInput) {
        resetFormBtn.addEventListener('click', () => {
            let selectedMat = clothSelect ? clothSelect.value.trim() : '';
            const currentShift = userShiftSelect ? userShiftSelect.value : 'Day Shift';
            pieceInput.value = getMaterialCurrentCount(selectedMat, currentShift);
            updateBtn();
        });
    }

    // Helper function to update all workstation and lifetime UI counters on save success
    function applyLoggedPieceSuccess(data, materialVal, shiftVal, notesVal) {
        const cleanMat = materialVal.toLowerCase().trim();
        const cleanShift = shiftVal.toLowerCase().trim();
        if (!window.todayMaterialCounts) window.todayMaterialCounts = {};
        window.todayMaterialCounts[cleanMat + '|' + cleanShift] = data.current_material_count;
        window.todayMaterialCounts[cleanMat] = data.current_material_count;

        // Update live count badge on the corresponding garment chip
        const cleanBadgeId = 'chipBadge_' + cleanMat.replace(/[^a-z0-9_]/g, '');
        const chipBadgeEl = document.getElementById(cleanBadgeId);
        if (chipBadgeEl) {
            chipBadgeEl.innerText = data.current_material_count + ' pcs';
            chipBadgeEl.style.background = 'rgba(16,185,129,0.22)';
            chipBadgeEl.style.color = '#10b981';
            chipBadgeEl.style.border = '1px solid rgba(16,185,129,0.45)';
        }

        // Show success banner
        if (successBanner) {
            successBanner.style.display = 'block';
            const summaryEl = document.getElementById('loggedPieceSummary');
            if (summaryEl) summaryEl.innerText = `${materialVal}: ${data.formula || data.current_material_count + ' pcs'} (Stored for ${data.worker_name})`;
            const totalEl = document.getElementById('loggedNewTotal');
            if (totalEl) totalEl.innerText = `Lifetime Total: ${data.new_total_pieces} pcs`;
        }

        // Update stats counters directly with exact server counts
        const todayValEl = document.getElementById('todayPiecesVal');
        if (todayValEl && data.today_pieces !== undefined) {
            todayValEl.innerText = data.today_pieces.toLocaleString();
        }
        const lifetimeEl = document.getElementById('lifetimePiecesVal');
        if (lifetimeEl && data.new_total_pieces !== undefined) {
            lifetimeEl.innerText = data.new_total_pieces.toLocaleString();
        }

        // Update Lifetime Fabric Breakdown section dynamically
        const lifetimeSection = document.getElementById('lifetimeFabricCardSection');
        const lifetimeHeader = document.getElementById('lifetimeFabricTotalHeader');
        const lifetimeContainer = document.getElementById('lifetimeFabricCardsContainer');

        if (lifetimeSection && data.lifetime_materials && data.lifetime_materials.length > 0) {
            lifetimeSection.style.display = 'block';
            let u1Sum = 0;
            let u2Sum = 0;
            data.lifetime_materials.forEach(m => {
                u1Sum += (parseInt(m.unit1_total) || 0);
                u2Sum += (parseInt(m.unit2_total) || 0);
            });
            const u1H = document.getElementById('lifetimeUnit1TotalHeader');
            const u2H = document.getElementById('lifetimeUnit2TotalHeader');
            if (u1H) u1H.innerText = u1Sum.toLocaleString();
            if (u2H) u2H.innerText = u2Sum.toLocaleString();
            if (lifetimeHeader) {
                lifetimeHeader.innerHTML = 'Combined Total: <strong style="color: var(--text-primary);">' + (data.new_total_pieces ? data.new_total_pieces.toLocaleString() : (u1Sum + u2Sum).toLocaleString()) + '</strong> pcs';
            }
            if (lifetimeContainer) {
                let html = '';
                data.lifetime_materials.forEach(m => {
                    let uPills = '';
                    if (m.unit1_total && parseInt(m.unit1_total) > 0) {
                        uPills += `<span class="badge-pill unit-cyan-pill" style="font-size: 0.65rem; padding: 0.08rem 0.38rem;"><i class="fa-solid fa-industry" style="font-size: 0.55rem;"></i> Unit 1: ${parseInt(m.unit1_total).toLocaleString()}</span> `;
                    }
                    if (m.unit2_total && parseInt(m.unit2_total) > 0) {
                        uPills += `<span class="badge-pill unit-pink-pill" style="font-size: 0.65rem; padding: 0.08rem 0.38rem;"><i class="fa-solid fa-warehouse" style="font-size: 0.55rem;"></i> Unit 2: ${parseInt(m.unit2_total).toLocaleString()}</span>`;
                    }
                    html += `
                        <div style="background: rgba(255,255,255,0.03); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 1rem;">
                            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.45rem;">
                                <div style="display: flex; align-items: center; gap: 0.5rem;">
                                    <i class="fa-solid fa-cube text-cyan"></i>
                                    <strong style="font-size: 0.92rem;">${escapeHtml(m.material_name)}</strong>
                                </div>
                            </div>
                            <div class="text-emerald" style="font-family: var(--font-heading); font-size: 1.3rem; font-weight: 800;">
                                ${parseInt(m.total).toLocaleString()} <span style="font-size: 0.8rem; color: var(--text-muted); font-weight: normal;">pcs</span>
                            </div>
                            <div style="display: flex; gap: 0.35rem; flex-wrap: wrap; margin-top: 0.45rem;">
                                ${uPills}
                            </div>
                            <div class="text-muted" style="font-size: 0.72rem; margin-top: 0.3rem;">
                                ${m.entries} logged batch(es)
                            </div>
                        </div>
                    `;
                });
                lifetimeContainer.innerHTML = html;
            }
        }

        // Dynamically refresh Category Production Leaderboards Slider
        if (data.category_slider_html) {
            const sliderEl = document.getElementById('unitCategorySliderSection');
            if (sliderEl) {
                const tempDiv = document.createElement('div');
                tempDiv.innerHTML = data.category_slider_html;
                const newSlider = tempDiv.querySelector('#unitCategorySliderSection');
                if (newSlider) {
                    sliderEl.innerHTML = newSlider.innerHTML;
                    if (typeof window.switchUnitSlide === 'function') {
                        window.switchUnitSlide(window.currentUnitSlideIndex || 0);
                    }
                }
            }
        } else if (typeof window.refreshCategorySlider === 'function') {
            window.refreshCategorySlider();
        }

        // Update or prepend row in Today's Table
        const tbody = document.getElementById('todayStitchedTableBody');
        const emptyRow = document.getElementById('emptyRowPlaceholder');
        if (emptyRow) emptyRow.remove();

        const existingRow = tbody ? tbody.querySelector(`tr[data-material="${cleanMat}"][data-shift="${cleanShift}"]`) : null;

        const now = new Date();
        const timeStr = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

        if (existingRow) {
            const countCell = existingRow.querySelector('.piece-count-cell');
            if (countCell) {
                countCell.innerHTML = `<strong>${data.current_material_count.toLocaleString()} pcs</strong> <span style="font-size:0.78rem; color:var(--accent-emerald);">(${data.formula})</span>`;
            }
            const timeCell = existingRow.querySelector('.time-cell');
            if (timeCell) {
                timeCell.innerHTML = `<span class="text-emerald font-bold">${timeStr} (Just now)</span>`;
            }
            if (notesVal) {
                const notesCell = existingRow.querySelector('.notes-cell');
                if (notesCell) notesCell.innerHTML = `<span style="font-size: 0.85rem;"><i class="fa-regular fa-comment-dots text-muted"></i> ${escapeHtml(notesVal)}</span>`;
            }

            // Flash highlight
            existingRow.style.background = 'rgba(16, 185, 129, 0.22)';
            existingRow.style.transform = 'scale(1.01)';
            existingRow.style.transition = 'all 0.4s ease';
            setTimeout(() => {
                existingRow.style.background = 'transparent';
                existingRow.style.transform = 'none';
            }, 2200);

        } else if (tbody) {
            const newRow = document.createElement('tr');
            newRow.setAttribute('data-material', cleanMat);
            newRow.setAttribute('data-shift', cleanShift);
            newRow.style.background = 'rgba(16, 185, 129, 0.15)';
            newRow.style.transition = 'all 0.5s ease';
            newRow.innerHTML = `
                <td class="text-emerald font-bold"><i class="fa-solid fa-check"></i></td>
                <td>
                    <div style="display: flex; align-items: center; gap: 0.65rem;">
                        <span class="material-badge" style="font-size: 0.88rem; padding: 0.35rem 0.75rem; background: rgba(16, 185, 129, 0.2); border-color: var(--accent-emerald);">
                            <i class="fa-solid fa-shirt"></i> ${escapeHtml(materialVal)}
                        </span>
                    </div>
                </td>
                <td>
                    <span class="piece-count-highlight piece-count-cell" style="font-size: 1.25rem;">
                        <strong>${data.current_material_count.toLocaleString()} pcs</strong>
                    </span>
                </td>
                <td>
                    <span class="badge-pill" style="background: rgba(255,255,255,0.08); font-size: 0.8rem; padding: 0.3rem 0.65rem;">
                        <i class="fa-solid fa-clock"></i> ${escapeHtml(shiftVal)}
                    </span>
                </td>
                <td class="time-cell">
                    <span class="text-emerald font-bold">${timeStr} (Just now)</span>
                </td>
                <td class="notes-cell">
                    ${notesVal ? `<span style="font-size: 0.85rem;"><i class="fa-regular fa-comment-dots text-muted"></i> ${escapeHtml(notesVal)}</span>` : '<span class="text-muted">-</span>'}
                </td>
            `;
            tbody.insertBefore(newRow, tbody.firstChild);

            setTimeout(() => {
                newRow.style.background = 'transparent';
            }, 2500);
        }

        // Sync pieceInput directly with exact database count
        if (pieceInput) {
            pieceInput.value = data.current_material_count;
        }
        if (clothStatusBadge) clothStatusBadge.style.display = 'flex';
        if (clothCurrentLoggedCount) clothCurrentLoggedCount.innerText = data.current_material_count + ' pcs';
        updateBtn();

        const notesField = document.getElementById('userNotesInput');
        if (notesField) notesField.value = '';

        // Real-time recalculation: refresh Category Priority Leaderboard so worker immediately sees updated rank
        if (typeof fetchClothLeaderboard === 'function') {
            fetchClothLeaderboard(materialVal);
        }
    }

    // Complete Button: Click increments count, IMMEDIATELY saves to database under user's name, and starts cooldown
    if (completeBtn) {
        completeBtn.addEventListener('click', async (e) => {
            e.preventDefault();

            if (isCooldownActive) {
                const rem = getCooldownRemaining();
                if (rem > 0) {
                    if (typeof showToast === 'function') {
                        showToast(`Please wait ${rem}s cooldown before logging next piece.`, 'warning');
                    }
                    return;
                }
            }

            let materialVal = clothSelect ? clothSelect.value.trim() : '';
            if (materialVal === '__custom__') {
                materialVal = customClothInput ? customClothInput.value.trim() : '';
            }
            if (!materialVal) {
                if (tier3Select && tier3Select.value) materialVal = tier3Select.value.trim();
                else if (tier2Select && tier2Select.value) materialVal = tier2Select.value.trim();
                else if (tier1Select && tier1Select.value) materialVal = tier1Select.value.trim();
                if (clothSelect && materialVal) clothSelect.value = materialVal;
            }

            if (!materialVal) {
                if (typeof showToast === 'function') showToast('Please select a garment chip above first.', 'error');
                return;
            }

            // Disable button and show saving indicator
            completeBtn.disabled = true;
            if (completeBtnLabel) completeBtnLabel.innerText = 'Saving piece...';
            if (completeBtnIcon) completeBtnIcon.className = 'fa-solid fa-spinner fa-spin';

            const shiftVal = userShiftSelect ? userShiftSelect.value : 'Day Shift';
            const notesVal = document.getElementById('userNotesInput') ? document.getElementById('userNotesInput').value : '';

            const currentInBox = parseInt(pieceInput ? pieceInput.value : 0) || 0;
            const existingCount = parseInt(getMaterialCurrentCount(materialVal, shiftVal)) || 0;
            const piecesToAdd = (currentInBox > existingCount) ? (currentInBox - existingCount) : 1;

            const formData = new FormData(stitchForm);
            formData.set('material_name', materialVal);
            formData.set('piece_count', piecesToAdd);
            formData.set('mode', 'add');
            formData.set('shift', shiftVal);
            formData.set('notes', notesVal);
            formData.append('action', 'log_pieces');

            try {
                const res = await fetch('api.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();

                if (data.success) {
                    applyLoggedPieceSuccess(data, materialVal, shiftVal, notesVal);

                    if (typeof showToast === 'function') {
                        showToast(`✔ Piece #${data.current_material_count} saved for ${data.worker_name}! (${materialVal})`, 'success', 4000);
                    }

                    if (currentCooldownDuration > 0) {
                        startCooldownTimer(currentCooldownDuration);
                    } else {
                        completeBtn.disabled = false;
                        if (completeBtnLabel) completeBtnLabel.innerText = 'Complete (+1)';
                        if (completeBtnIcon) completeBtnIcon.className = 'fa-solid fa-circle-check';
                    }
                } else {
                    if (typeof showToast === 'function') {
                        showToast(data.message || 'Failed to save piece.', 'error');
                    }
                    completeBtn.disabled = false;
                    if (completeBtnLabel) completeBtnLabel.innerText = 'Complete (+1)';
                    if (completeBtnIcon) completeBtnIcon.className = 'fa-solid fa-circle-check';
                }
            } catch (err) {
                console.error('Complete & Save error:', err);
                if (typeof showToast === 'function') {
                    showToast('Network error while saving piece.', 'error');
                }
                completeBtn.disabled = false;
                if (completeBtnLabel) completeBtnLabel.innerText = 'Complete (+1)';
                if (completeBtnIcon) completeBtnIcon.className = 'fa-solid fa-circle-check';
            }
        });
    }

    // Submit Complete Form via AJAX
    if (stitchForm) {
        stitchForm.addEventListener('submit', (e) => {
            e.preventDefault();
            if (completeBtn && !completeBtn.disabled) {
                completeBtn.click();
            }
        });
    }

    // 3-Tier Category Management Modal Controller
    const manageCatBtn = document.getElementById('openManageCategoriesBtn');
    const catModal = document.getElementById('categoryModal');
    const closeCatModalBtn = document.getElementById('closeCategoryModalBtn');
    const addCatForm = document.getElementById('addCategoryForm');
    const newCatInput = document.getElementById('newCategoryInput');
    const newSubInput = document.getElementById('newSubcategoryInput');
    const newSubSubInput = document.getElementById('newSubSubcategoryInput');
    const catFormAlert = document.getElementById('categoryFormAlert');
    const categoriesListContainer = document.getElementById('categoriesListContainer');
    const catTotalCount = document.getElementById('catTotalCount');
    const existingCategoriesList = document.getElementById('existingCategoriesList');
    const existingSubcategoriesList = document.getElementById('existingSubcategoriesList');

    window.openCategoryModal = function() {
        if (catModal) {
            catModal.style.display = 'flex';
            loadCategoriesList();
            if (newCatInput) newCatInput.focus();
        }
    };

    function closeCategoryModal() {
        if (catModal) catModal.style.display = 'none';
        if (catFormAlert) catFormAlert.style.display = 'none';
    }

    if (manageCatBtn) manageCatBtn.addEventListener('click', window.openCategoryModal);
    if (closeCatModalBtn) closeCatModalBtn.addEventListener('click', closeCategoryModal);
    if (catModal) {
        catModal.addEventListener('click', (e) => {
            if (e.target === catModal) closeCategoryModal();
        });
    }

    async function loadCategoriesList() {
        if (!categoriesListContainer) return;
        categoriesListContainer.innerHTML = '<div style="padding: 1rem; text-align: center; color: var(--text-muted);"><i class="fa-solid fa-spinner fa-spin text-cyan"></i> Loading hierarchy...</div>';
        try {
            const res = await fetch('api.php?action=get_categories');
            const data = await res.json();
            if (data.success) {
                renderCategoriesInModal(data.tree, data.total);
                updateDropdownCategories(data.tree);
            } else {
                categoriesListContainer.innerHTML = '<div class="text-rose text-center">Failed to load categories.</div>';
            }
        } catch (err) {
            console.error('Error loading categories:', err);
            categoriesListContainer.innerHTML = '<div class="text-rose text-center">Connection error.</div>';
        }
    }

    function renderCategoriesInModal(tree, total) {
        if (catTotalCount) catTotalCount.innerText = total || 0;
        
        // Update datalists
        if (existingCategoriesList) {
            existingCategoriesList.innerHTML = Object.keys(tree).map(c => `<option value="${escapeHtml(c)}">`).join('');
        }
        if (existingSubcategoriesList) {
            let subs = new Set();
            Object.keys(tree).forEach(c => Object.keys(tree[c]).forEach(s => subs.add(s)));
            existingSubcategoriesList.innerHTML = Array.from(subs).map(s => `<option value="${escapeHtml(s)}">`).join('');
        }

        if (!categoriesListContainer) return;
        const catKeys = Object.keys(tree);
        if (catKeys.length === 0) {
            categoriesListContainer.innerHTML = '<div style="padding: 1.25rem; text-align: center; color: var(--text-muted); font-size: 0.85rem; border: 1px dashed var(--border-color); border-radius: var(--radius-md);"><i class="fa-solid fa-inbox" style="font-size: 1.5rem; margin-bottom: 0.5rem; display: block; opacity: 0.5;"></i> No categories or garments added yet.<br>Add your first one using the form above.</div>';
            return;
        }

        let html = '';
        catKeys.forEach(catName => {
            const subcats = tree[catName];
            html += `
                <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 0.75rem 0.9rem;">
                    <div style="font-weight: 700; font-size: 0.88rem; color: var(--accent-cyan); display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.5rem; border-bottom: 1px dashed rgba(255,255,255,0.08); padding-bottom: 0.35rem;">
                        <span><i class="fa-solid fa-folder-open"></i> ${escapeHtml(catName)}</span>
                        <button type="button" class="btn-delete-cat text-rose" data-type="category" data-cat="${escapeHtml(catName)}" title="Delete Category" style="background: none; border: none; cursor: pointer; padding: 0.1rem 0.3rem; font-size: 0.75rem;">
                            <i class="fa-solid fa-trash-can"></i> Delete Category
                        </button>
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 0.5rem;">
            `;

            Object.keys(subcats).forEach(subcatName => {
                const rawSub = subcats[subcatName];
                const items = Array.isArray(rawSub) ? rawSub : ((rawSub && rawSub.items) ? rawSub.items : []);
                html += `
                    <div style="background: rgba(255,255,255,0.03); border-left: 2px solid var(--accent-purple, #8b5cf6); padding: 0.45rem 0.65rem; border-radius: var(--radius-sm);">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.35rem;">
                            <span style="font-weight: 600; font-size: 0.8rem; color: #c4b5fd;">
                                <i class="fa-solid fa-folder-tree" style="font-size: 0.75rem;"></i> ${escapeHtml(subcatName)}
                            </span>
                            <button type="button" class="btn-delete-cat text-rose" data-type="subcategory" data-cat="${escapeHtml(catName)}" data-sub="${escapeHtml(subcatName)}" title="Delete Subcategory" style="background: none; border: none; cursor: pointer; padding: 0.1rem 0.3rem; font-size: 0.72rem;">
                                <i class="fa-solid fa-trash-can"></i>
                            </button>
                        </div>
                        <div style="display: flex; flex-wrap: wrap; gap: 0.35rem;">
                `;

                if (items.length === 0) {
                    html += `
                        <span style="display: inline-flex; align-items: center; gap: 0.35rem; background: rgba(16,185,129,0.08); border: 1px solid rgba(16,185,129,0.25); border-radius: 50px; padding: 0.2rem 0.55rem; font-size: 0.75rem; color: #10b981;">
                            <i class="fa-solid fa-circle-check"></i> Direct Garment
                        </span>
                    `;
                } else {
                    items.forEach(item => {
                        html += `
                            <span style="display: inline-flex; align-items: center; gap: 0.35rem; background: rgba(6,182,212,0.1); border: 1px solid rgba(6,182,212,0.25); border-radius: 50px; padding: 0.2rem 0.55rem; font-size: 0.78rem; color: var(--text-primary);">
                                <i class="fa-solid fa-tag text-cyan" style="font-size: 0.68rem;"></i>
                                ${escapeHtml(item.name)}
                                <button type="button" class="btn-delete-cat text-rose" data-type="single" data-id="${item.id}" title="Delete" style="background: none; border: none; cursor: pointer; padding: 0; font-size: 0.75rem;">
                                    <i class="fa-solid fa-xmark"></i>
                                </button>
                            </span>
                        `;
                    });
                }

                html += `</div></div>`;
            });

            html += `</div></div>`;
        });
        categoriesListContainer.innerHTML = html;

        // Attach delete listeners
        categoriesListContainer.querySelectorAll('.btn-delete-cat').forEach(btn => {
            btn.addEventListener('click', async () => {
                const type = btn.dataset.type;
                const id = btn.dataset.id || 0;
                const cat = btn.dataset.cat || '';
                const sub = btn.dataset.sub || '';

                if (!confirm(`Are you sure you want to delete this ${type}?`)) return;
                try {
                    btn.disabled = true;
                    const res = await fetch(`api.php?action=delete_category`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ type: type, id: id, category_name: cat, subcategory_name: sub })
                    });
                    const resData = await res.json();
                    if (resData.success) {
                        renderCategoriesInModal(resData.tree, resData.total);
                        renderGarmentChips(resData.tree);
                        updateDropdownCategories(resData.tree);
                        if (typeof showToast === 'function') showToast(resData.message, 'success');
                    } else {
                        alert(resData.message || 'Failed to delete');
                    }
                } catch (e) {
                    console.error(e);
                }
            });
        });
    }

    function populateTier1(tree, preselectCat = null, preselectSub = null, preselectGarment = null) {
        if (!tier1Select) return;
        dynamicGarmentTree = tree;
        const catKeys = Object.keys(tree);

        tier1Select.innerHTML = '<option value="">-- Choose Category --</option>';
        if (tier2Select) {
            tier2Select.innerHTML = '<option value="">-- Choose Subcategory --</option>';
            tier2Select.disabled = true;
        }
        if (tier3Select) {
            tier3Select.innerHTML = '<option value="">-- Choose Garment --</option>';
            tier3Select.disabled = true;
        }
        if (clothSelect) clothSelect.value = '';
        if (breadcrumbBox) breadcrumbBox.style.display = 'none';

        if (catKeys.length === 0) {
            tier1Select.innerHTML = '<option value="">-- No Categories Added Yet --</option>';
            return;
        }

        catKeys.forEach(cat => {
            const sel = (preselectCat && cat === preselectCat) ? 'selected' : '';
            tier1Select.innerHTML += `<option value="${escapeHtml(cat)}" ${sel}>${escapeHtml(cat)}</option>`;
        });

        // Auto-select if preselected or single category
        if (preselectCat && tree[preselectCat]) {
            tier1Select.value = preselectCat;
        } else if (catKeys.length === 1) {
            tier1Select.value = catKeys[0];
        }

        if (tier1Select.value) {
            populateTier2(tier1Select.value, preselectSub, preselectGarment);
        }
    }

    function populateTier2(catName, preselectSub = null, preselectGarment = null) {
        if (!tier2Select) return;
        tier2Select.innerHTML = '<option value="">-- Choose Subcategory --</option>';
        if (tier3Select) {
            tier3Select.innerHTML = '<option value="">-- Choose Garment --</option>';
            tier3Select.disabled = true;
        }
        if (clothSelect) clothSelect.value = '';
        if (breadcrumbBox) breadcrumbBox.style.display = 'none';

        if (!catName || !dynamicGarmentTree[catName]) {
            tier2Select.disabled = true;
            return;
        }

        const subcats = Object.keys(dynamicGarmentTree[catName]);
        if (subcats.length === 0) {
            tier2Select.disabled = true;
            return;
        }

        subcats.forEach(sub => {
            const sel = (preselectSub && sub === preselectSub) ? 'selected' : '';
            tier2Select.innerHTML += `<option value="${escapeHtml(sub)}" ${sel}>${escapeHtml(sub)}</option>`;
        });
        tier2Select.disabled = false;

        if (preselectSub && dynamicGarmentTree[catName][preselectSub]) {
            tier2Select.value = preselectSub;
        } else if (subcats.length === 1) {
            tier2Select.value = subcats[0];
        }

        if (tier2Select.value) {
            populateTier3(catName, tier2Select.value, preselectGarment);
        }
    }

    function populateTier3(catName, subcatName, preselectGarment = null) {
        if (!tier3Select) return;
        tier3Select.innerHTML = '<option value="">-- Choose Garment (Optional) --</option>';

        if (!catName || !subcatName || !dynamicGarmentTree[catName] || !dynamicGarmentTree[catName][subcatName]) {
            tier3Select.disabled = true;
            if (catName && subcatName) {
                applySelectedGarment(catName, subcatName, subcatName);
            }
            return;
        }

        const rawSub = dynamicGarmentTree[catName][subcatName];
        const rawItems = Array.isArray(rawSub) ? rawSub : ((rawSub && rawSub.items) ? rawSub.items : []);
        // Filter only non-empty garment names
        const items = rawItems.filter(it => it && it.name && it.name.trim() !== '');

        if (!items || items.length === 0) {
            // No sub-subcategories: Subcategory is used directly as garment
            tier3Select.innerHTML = `<option value="">-- Direct: Using ${escapeHtml(subcatName)} --</option>`;
            tier3Select.disabled = true;
            applySelectedGarment(catName, subcatName, subcatName);
            return;
        }

        tier3Select.innerHTML = '<option value="">-- Optional: Specific Garment (or leave for Subcategory) --</option>';
        items.forEach(item => {
            const sel = (preselectGarment && item.name === preselectGarment) ? 'selected' : '';
            tier3Select.innerHTML += `<option value="${escapeHtml(item.name)}" ${sel}>${escapeHtml(item.name)}</option>`;
        });
        tier3Select.disabled = false;

        if (preselectGarment && items.some(i => i.name === preselectGarment)) {
            tier3Select.value = preselectGarment;
            applySelectedGarment(catName, subcatName, tier3Select.value);
        } else if (items.length === 1) {
            tier3Select.value = items[0].name;
            applySelectedGarment(catName, subcatName, tier3Select.value);
        } else {
            // By default use subcategory until specific garment is picked
            applySelectedGarment(catName, subcatName, subcatName);
        }
    }

    function applySelectedGarment(catName, subcatName, garmentName) {
        if (!clothSelect) return;
        const prevGarment = clothSelect.value;
        const effectiveGarment = (garmentName && garmentName.trim() !== '') ? garmentName.trim() : (subcatName || catName);
        clothSelect.value = effectiveGarment;

        const currentShift = userShiftSelect ? userShiftSelect.value : 'Day Shift';
        const existingCount = getMaterialCurrentCount(effectiveGarment, currentShift);

        if (pieceInput) {
            pieceInput.value = existingCount;
        }

        const displayNameEl = document.getElementById('selectedGarmentDisplayName');
        if (displayNameEl) {
            displayNameEl.innerText = effectiveGarment;
        }

        if (clothCurrentLoggedCount) {
            clothCurrentLoggedCount.innerText = existingCount + ' pcs';
        }
        if (clothStatusBadge) {
            clothStatusBadge.style.display = 'flex';
        }

        if (breadcrumbBox && breadcrumbText) {
            if (garmentName && garmentName.trim() !== '' && garmentName !== subcatName) {
                breadcrumbText.innerText = `(${catName} › ${subcatName} › ${garmentName})`;
            } else {
                breadcrumbText.innerText = `(${catName} › ${subcatName})`;
            }
            breadcrumbBox.style.display = 'flex';
        }

        // Highlight matching chip if present
        const allChips = document.querySelectorAll('.garment-chip-btn');
        allChips.forEach(btn => {
            if (btn.dataset.garment && btn.dataset.garment.toLowerCase() === effectiveGarment.toLowerCase()) {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
        });

        clothSelect.dispatchEvent(new Event('change'));
        updateBtn();
        fetchClothLeaderboard(effectiveGarment);
    }

    if (tier1Select) {
        tier1Select.addEventListener('change', () => {
            populateTier2(tier1Select.value);
        });
    }

    if (tier2Select) {
        tier2Select.addEventListener('change', () => {
            populateTier3(tier1Select.value, tier2Select.value);
        });
    }

    if (tier3Select) {
        tier3Select.addEventListener('change', () => {
            if (tier3Select.value && tier3Select.value.trim() !== '') {
                applySelectedGarment(tier1Select.value, tier2Select.value, tier3Select.value);
            } else {
                applySelectedGarment(tier1Select.value, tier2Select.value, tier2Select.value);
            }
        });
    }

    function updateDropdownCategories(tree, selectedCat = null, selectedSub = null, selectedGarment = null) {
        populateTier1(tree, selectedCat, selectedSub, selectedGarment);
    }

    // Dynamic Client-side Quick Garment Chips Renderer (Subcategories vs Categories)
    function renderGarmentChips(tree, targetToSelect = null) {
        const grid = document.getElementById('garmentQuickChipsGrid');
        if (!grid) return;

        dynamicGarmentTree = tree || {};
        const mode = window.currentChipsMode || (window.showSubcategoriesOnly ? 'subcategory' : 'category');

        let chipsList = [];
        if (mode === 'subcategory') {
            if (window.hasAssignedWork && window.workerAssignedSubcategories && window.workerAssignedSubcategories.length > 0) {
                chipsList = window.workerAssignedSubcategories;
            } else if (window.adminSubcategoryChips && window.adminSubcategoryChips.length > 0) {
                chipsList = window.adminSubcategoryChips;
            } else {
                Object.keys(dynamicGarmentTree).forEach(cName => {
                    Object.keys(dynamicGarmentTree[cName]).forEach(sName => {
                        chipsList.push({
                            name: sName,
                            category_name: cName,
                            icon: 'fa-tag',
                            color: '#06b6d4'
                        });
                    });
                });
            }
        } else {
            if (window.adminCategoryChips && window.adminCategoryChips.length > 0) {
                chipsList = window.adminCategoryChips;
            } else {
                Object.keys(dynamicGarmentTree).forEach(cName => {
                    chipsList.push({
                        name: cName,
                        category_name: '',
                        icon: 'fa-shirt',
                        color: '#a855f7'
                    });
                });
            }
        }

        if (chipsList.length === 0) {
            grid.innerHTML = `
                <div style="grid-column: 1 / -1; padding: 1.25rem; text-align: center; color: var(--text-muted); background: rgba(255,255,255,0.02); border: 1px dashed var(--border-color); border-radius: var(--radius-md); font-size: 0.85rem;">
                    <i class="fa-solid fa-inbox" style="font-size: 1.5rem; display: block; margin-bottom: 0.4rem; opacity: 0.5;"></i>
                    No items available.
                </div>
            `;
            return;
        }

        let html = '';
        chipsList.forEach(item => {
            const itemName = item.name;
            const parentCat = item.category_name || '';
            const gCleanKey = itemName.toLowerCase().trim();
            const gTodayCount = (window.todayMaterialCounts && window.todayMaterialCounts[gCleanKey]) ? window.todayMaterialCounts[gCleanKey] : 0;
            const badgeId = 'chipBadge_' + gCleanKey.replace(/[^a-z0-9_]/g, '');

            const isSelected = clothSelect && clothSelect.value && clothSelect.value.toLowerCase() === itemName.toLowerCase();
            const activeClass = isSelected ? 'active' : '';

            html += `
                <button type="button" class="garment-chip-btn ${activeClass}" data-garment="${escapeHtml(itemName)}" data-category="${escapeHtml(parentCat)}" title="Tap to select ${escapeHtml(itemName)}">
                    <div style="display: flex; align-items: center; gap: 0.5rem; min-width: 0;">
                        <i class="fa-solid ${item.icon || 'fa-tag'} garment-chip-icon" style="color: ${item.color || '#06b6d4'};"></i>
                        <div style="display: flex; flex-direction: column; text-align: left; min-width: 0;">
                            <span class="garment-chip-title" style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${escapeHtml(itemName)}</span>
                        </div>
                    </div>
                    <span class="garment-chip-count-badge" id="${badgeId}" style="font-size: 0.72rem; font-weight: 700; padding: 0.15rem 0.5rem; border-radius: 50px; background: ${gTodayCount > 0 ? 'rgba(16,185,129,0.22)' : 'rgba(255,255,255,0.06)'}; color: ${gTodayCount > 0 ? '#10b981' : 'var(--text-muted)'}; border: 1px solid ${gTodayCount > 0 ? 'rgba(16,185,129,0.45)' : 'rgba(255,255,255,0.08)'}; white-space: nowrap; flex-shrink: 0;">
                        ${gTodayCount > 0 ? (gTodayCount + ' pcs') : '0 pcs'}
                    </span>
                </button>
            `;
        });

        grid.innerHTML = html;

        grid.querySelectorAll('.garment-chip-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                if (btn.dataset.garment && typeof window.selectGarmentChip === 'function') {
                    window.selectGarmentChip(btn.dataset.garment, btn.dataset.category || '');
                }
            });
        });

        if (targetToSelect && typeof window.selectGarmentChip === 'function') {
            window.selectGarmentChip(targetToSelect);
        }
    }
    window.renderGarmentChips = renderGarmentChips;

    // Admin Inline Quick Add Category / Subcategory Controller
    const btnToggleInline = document.getElementById('btnToggleInlineAddCat');
    const inlineBox = document.getElementById('inlineAddCategoryBox');
    const closeInlineBtn = document.getElementById('closeInlineAddCatBtn');
    const inlineForm = document.getElementById('inlineQuickAddCatForm');
    const inlineNewCat = document.getElementById('inlineNewCatInput');
    const inlineNewSub = document.getElementById('inlineNewSubcatInput');
    const inlineNewSubSub = document.getElementById('inlineNewSubSubcatInput');
    const inlineAlert = document.getElementById('inlineCatAlert');
    const inlineSaveBtn = document.getElementById('inlineSaveCatBtn');

    if (btnToggleInline && inlineBox) {
        btnToggleInline.addEventListener('click', () => {
            const isHidden = (inlineBox.style.display === 'none' || !inlineBox.style.display);
            inlineBox.style.display = isHidden ? 'block' : 'none';
            if (isHidden && inlineNewCat) {
                inlineNewCat.focus();
            }
        });
    }

    if (closeInlineBtn && inlineBox) {
        closeInlineBtn.addEventListener('click', () => {
            inlineBox.style.display = 'none';
            if (inlineAlert) inlineAlert.style.display = 'none';
        });
    }

    async function submitInlineQuickAddCategory() {
        const catVal = inlineNewCat ? inlineNewCat.value.trim() : '';
        const subVal = inlineNewSub ? inlineNewSub.value.trim() : '';
        const subSubVal = inlineNewSubSub ? inlineNewSubSub.value.trim() : '';

        if (!catVal || !subVal) {
            if (inlineAlert) {
                inlineAlert.className = 'alert-box alert-danger';
                inlineAlert.style.display = 'block';
                inlineAlert.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> Category Name and Subcategory are required.';
            }
            return;
        }

        if (inlineSaveBtn) inlineSaveBtn.disabled = true;

        try {
            const res = await fetch('api.php?action=add_category', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    category_name: catVal,
                    subcategory_name: subVal,
                    sub_subcategory_name: subSubVal
                })
            });
            const data = await res.json();
            if (data.success) {
                renderGarmentChips(data.tree, catVal);
                updateDropdownCategories(data.tree, catVal, subVal, subSubVal);
                renderCategoriesInModal(data.tree, data.total);

                if (inlineNewCat) inlineNewCat.value = '';
                if (inlineNewSub) inlineNewSub.value = '';
                if (inlineNewSubSub) inlineNewSubSub.value = '';
                if (inlineBox) inlineBox.style.display = 'none';
                if (inlineAlert) inlineAlert.style.display = 'none';

                if (typeof showToast === 'function') {
                    showToast(`Category "${catVal} › ${subVal}" created and selected!`, 'success');
                }
            } else {
                if (inlineAlert) {
                    inlineAlert.className = 'alert-box alert-danger';
                    inlineAlert.style.display = 'block';
                    inlineAlert.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> ' + escapeHtml(data.message);
                }
            }
        } catch (err) {
            console.error(err);
            if (inlineAlert) {
                inlineAlert.className = 'alert-box alert-danger';
                inlineAlert.style.display = 'block';
                inlineAlert.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> Connection error.';
            }
        } finally {
            if (inlineSaveBtn) inlineSaveBtn.disabled = false;
        }
    }
    window.submitInlineQuickAddCategory = submitInlineQuickAddCategory;

    if (inlineSaveBtn) {
        inlineSaveBtn.addEventListener('click', submitInlineQuickAddCategory);
    }

    [inlineNewCat, inlineNewSub, inlineNewSubSub].forEach(input => {
        if (input) {
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    submitInlineQuickAddCategory();
                }
            });
        }
    });

    if (addCatForm) {
        addCatForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const catVal = newCatInput ? newCatInput.value.trim() : '';
            const subVal = newSubInput ? newSubInput.value.trim() : '';
            const subSubVal = newSubSubInput ? newSubSubInput.value.trim() : '';
            if (!catVal || !subVal) return;

            const saveBtn = document.getElementById('saveCategoryBtn');
            if (saveBtn) saveBtn.disabled = true;

            try {
                const res = await fetch('api.php?action=add_category', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        category_name: catVal,
                        subcategory_name: subVal,
                        sub_subcategory_name: subSubVal
                    })
                });
                const data = await res.json();
                if (data.success) {
                    if (catFormAlert) {
                        catFormAlert.className = 'alert-box alert-success';
                        catFormAlert.style.display = 'block';
                        catFormAlert.innerHTML = '<i class="fa-solid fa-circle-check"></i> ' + escapeHtml(data.message);
                        setTimeout(() => { if (catFormAlert) catFormAlert.style.display = 'none'; }, 2500);
                    }
                    if (addCatForm) addCatForm.reset();
                    if (newCatInput) newCatInput.value = '';
                    if (newSubInput) newSubInput.value = '';
                    if (newSubSubInput) newSubSubInput.value = '';
                    if (newCatInput) newCatInput.focus();
                    renderCategoriesInModal(data.tree, data.total);
                    renderGarmentChips(data.tree, catVal);
                    updateDropdownCategories(data.tree, catVal, subVal, subSubVal);
                    if (typeof showToast === 'function') showToast(`Added "${subSubVal || subVal}" to ${catVal}!`, 'success');
                } else {
                    if (catFormAlert) {
                        catFormAlert.className = 'alert-box alert-danger';
                        catFormAlert.style.display = 'block';
                        catFormAlert.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> ' + escapeHtml(data.message);
                    }
                }
            } catch (err) {
                console.error(err);
                if (catFormAlert) {
                    catFormAlert.className = 'alert-box alert-danger';
                    catFormAlert.style.display = 'block';
                    catFormAlert.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> Failed to connect to server.';
                }
            } finally {
                if (saveBtn) saveBtn.disabled = false;
            }
        });
    }

    // Initialize 3-tier cascading category selection on page load
    populateTier1(dynamicGarmentTree);

    // Auto-select garment: restores existing active category on refresh / login
    setTimeout(() => {
        let targetGarment = '';
        // 1. Priority 1: Worker's assigned work subcategory for today
        if (window.assignedSubcategory) {
            targetGarment = window.assignedSubcategory;
        }

        if (!targetGarment) {
            try {
                targetGarment = localStorage.getItem('fabriqpro_last_garment_' + currentWorkerId);
            } catch (e) {}
        }

        if (!targetGarment && window.lastLoggedMaterial) {
            targetGarment = window.lastLoggedMaterial;
        }

        // If still not set, select whichever garment has logged pieces today
        if (!targetGarment && window.todayMaterialCounts) {
            let maxCount = -1;
            for (const [k, count] of Object.entries(window.todayMaterialCounts)) {
                if (!k.includes('|') && count > maxCount) {
                    maxCount = count;
                    const matchingChip = document.querySelector(`.garment-chip-btn[data-garment="${k}" i]`);
                    if (matchingChip && matchingChip.dataset.garment) {
                        targetGarment = matchingChip.dataset.garment;
                    }
                }
            }
        }

        // Default to first available chip
        if (!targetGarment) {
            const firstChip = document.querySelector('.garment-chip-btn');
            if (firstChip && firstChip.dataset.garment) {
                targetGarment = firstChip.dataset.garment;
            }
        }

        if (targetGarment && typeof window.selectGarmentChip === 'function') {
            const matchingChip = document.querySelector(`.garment-chip-btn[data-garment="${targetGarment}" i]`);
            const pCat = matchingChip ? (matchingChip.dataset.category || '') : (window.assignedCategory || '');
            window.selectGarmentChip(targetGarment, pCat);
        }

        // Check if cooldown timer was active from previous action
        const existingCooldownRemaining = getCooldownRemaining();
        if (existingCooldownRemaining > 0 && existingCooldownRemaining <= 30) {
            startCooldownTimer(existingCooldownRemaining);
        }
    }, 30);
});
</script>

<!-- Admin 3-Tier Category Management Modal -->
<?php if ($currentUser && ($currentUser['role'] === 'admin' || $currentUser['role'] === 'supervisor')): ?>
<div id="categoryModal" class="modal-overlay" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.75); z-index: 9999; align-items: center; justify-content: center; backdrop-filter: blur(4px);">
    <div class="modal-container" style="background: var(--card-bg, #1e293b); border: 1px solid var(--border-color, #334155); border-radius: var(--radius-lg, 12px); width: 92%; max-width: 620px; max-height: 90vh; overflow-y: auto; padding: 1.5rem; box-shadow: 0 20px 40px rgba(0,0,0,0.5);">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.75rem;">
            <div>
                <h3 style="margin: 0; font-size: 1.25rem; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 0.5rem;">
                    <i class="fa-solid fa-layer-group text-cyan"></i> Manage 3-Tier Garment Categories
                </h3>
                <p class="text-muted" style="margin: 0.35rem 0 0 0; font-size: 0.82rem;">
                    Category ➔ Subcategory ➔ Garment Type. Only admin-added items show up in selection fields.
                </p>
            </div>
            <button type="button" id="closeCategoryModalBtn" style="background: none; border: none; font-size: 1.25rem; color: var(--text-muted); cursor: pointer; padding: 0.25rem 0.5rem;">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <!-- Add Category / Subcategory / Sub-Subcategory Form -->
        <form id="addCategoryForm" style="margin-bottom: 1.25rem; background: rgba(255,255,255,0.03); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 1rem;">
            <div style="font-weight: 700; font-size: 0.88rem; margin-bottom: 0.75rem; color: var(--accent-cyan); display: flex; align-items: center; justify-content: space-between;">
                <span><i class="fa-solid fa-plus-circle"></i> Add New Garment Item</span>
                <a href="categories.php" target="_blank" style="font-size: 0.75rem; color: var(--accent-purple); text-decoration: none;">
                    Open Full Manager <i class="fa-solid fa-arrow-up-right-from-square"></i>
                </a>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-bottom: 0.75rem;">
                <div>
                    <label style="display: block; font-size: 0.78rem; font-weight: 600; margin-bottom: 0.25rem; color: var(--text-secondary);">
                        1. Main Category <span class="required">*</span>
                    </label>
                    <input type="text" id="newCategoryInput" list="existingCategoriesList" class="form-control" placeholder="e.g. Maternity, Shirts, Pants" required style="font-size: 0.85rem; padding: 0.5rem 0.75rem;">
                    <datalist id="existingCategoriesList"></datalist>
                </div>
                <div>
                    <label style="display: block; font-size: 0.78rem; font-weight: 600; margin-bottom: 0.25rem; color: var(--text-secondary);">
                        2. Subcategory <span class="required">*</span>
                    </label>
                    <input type="text" id="newSubcategoryInput" list="existingSubcategoriesList" class="form-control" placeholder="e.g. Feeding Wear, Formal" required style="font-size: 0.85rem; padding: 0.5rem 0.75rem;">
                    <datalist id="existingSubcategoriesList"></datalist>
                </div>
            </div>

            <div style="margin-bottom: 0.75rem;">
                <label style="display: block; font-size: 0.78rem; font-weight: 600; margin-bottom: 0.25rem; color: var(--text-secondary);">
                    3. Sub-Subcategory (Garment Name) <span class="text-muted" style="font-weight: normal;">(Optional)</span>
                </label>
                <input type="text" id="newSubSubcategoryInput" class="form-control" placeholder="e.g. Nighty, Cotton Feeding Kurti (Optional)" style="font-size: 0.85rem; padding: 0.5rem 0.75rem;">
            </div>

            <div id="categoryFormAlert" style="display: none; padding: 0.5rem 0.75rem; border-radius: var(--radius-sm); font-size: 0.8rem; margin-bottom: 0.75rem;"></div>

            <button type="submit" id="saveCategoryBtn" class="btn btn-primary btn-sm" style="width: 100%; display: flex; justify-content: center; align-items: center; gap: 0.45rem;">
                <i class="fa-solid fa-plus"></i> Add Garment to Dropdown
            </button>
        </form>

        <!-- Existing Hierarchy List -->
        <div>
            <div style="font-weight: 700; font-size: 0.88rem; margin-bottom: 0.5rem; color: var(--text-primary); display: flex; justify-content: space-between; align-items: center;">
                <span>Current Active Garments (<span id="catTotalCount">0</span>)</span>
            </div>
            <div id="categoriesListContainer" style="display: flex; flex-direction: column; gap: 0.6rem; max-height: 280px; overflow-y: auto; padding-right: 0.25rem;">
                <!-- Dynamically rendered -->
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
