<?php
// ============================================================================
// DYNAMIC CATEGORY-WISE PRODUCTION LEADERBOARDS (ALL UNITS, UNIT 1, UNIT 2 SLIDER)
// ============================================================================
// Automatically aggregates piece logs across all workers & units, dynamically
// mapping to active garment categories, and presents an interactive carousel.
// ============================================================================

// Ensure Database connection is initialized
if (!isset($pdo) || !$pdo) {
    require_once __DIR__ . '/../db.php';
    $pdo = Database::getConnection();
}

// 1. Fetch all active garment categories
$gcStmt = $pdo->query("SELECT category_name, subcategory_name, sub_subcategory_name FROM garment_categories WHERE status = 'Active'");
$gcRows = $gcStmt ? $gcStmt->fetchAll(PDO::FETCH_ASSOC) : [];

$catStmt = $pdo->query("SELECT DISTINCT category_name FROM garment_categories WHERE status = 'Active' ORDER BY category_name ASC");
$activeCategories = $catStmt ? $catStmt->fetchAll(PDO::FETCH_COLUMN) : [];

if (empty($activeCategories)) {
    $matCatStmt = $pdo->query("SELECT DISTINCT material_name FROM piece_logs WHERE material_name IS NOT NULL AND material_name != ''");
    $activeCategories = $matCatStmt ? $matCatStmt->fetchAll(PDO::FETCH_COLUMN) : [];
}

if (empty($activeCategories)) {
    $activeCategories = ['General'];
}

// 2. Intelligent Category Mapping Function (Strictly Admin-Added Active Categories)
$mapMaterialToCategory = function($materialName) use ($gcRows, $activeCategories) {
    if (empty($materialName)) return $activeCategories[0] ?? 'General';
    $cleanMat = strtolower(trim($materialName));

    foreach ($gcRows as $row) {
        if (!empty($row['sub_subcategory_name']) && strtolower(trim($row['sub_subcategory_name'])) === $cleanMat) {
            return $row['category_name'];
        }
        if (!empty($row['subcategory_name']) && strtolower(trim($row['subcategory_name'])) === $cleanMat) {
            return $row['category_name'];
        }
    }

    foreach ($activeCategories as $cat) {
        if (strtolower(trim($cat)) === $cleanMat) return $cat;
    }

    foreach ($activeCategories as $cat) {
        if (stripos($cleanMat, strtolower(trim($cat))) !== false) return $cat;
    }

    return $activeCategories[0] ?? 'General';
};

// 3. Query all positive piece logs with worker details (LEFT JOIN ensures logs are never dropped)
$logsStmt = $pdo->query("
    SELECT pl.id, pl.worker_id, pl.material_name, pl.piece_count, pl.unit as pl_unit,
           w.name, w.unique_id, w.unit as worker_unit, w.role
    FROM piece_logs pl
    LEFT JOIN workers w ON pl.worker_id = w.id
    WHERE pl.piece_count > 0
");
$allLogs = $logsStmt ? $logsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

// Make sure any mapped category that has piece logs is included in active categories list so cards are displayed
foreach ($allLogs as $log) {
    $catMapped = $mapMaterialToCategory($log['material_name']);
    if (!in_array($catMapped, $activeCategories)) {
        $activeCategories[] = $catMapped;
    }
}

// 4. Build Category Leaderboard structure for All Units, Unit 1, and Unit 2
$slideKeys = ['All Units', 'Unit 1', 'Unit 2'];
$categoryData = [];

foreach ($slideKeys as $sKey) {
    $categoryData[$sKey] = [];
    foreach ($activeCategories as $cat) {
        $categoryData[$sKey][$cat] = [
            'total_pieces' => 0,
            'workers' => []
        ];
    }
}

foreach ($allLogs as $log) {
    $unitVal = !empty($log['pl_unit']) ? $log['pl_unit'] : (!empty($log['worker_unit']) ? $log['worker_unit'] : 'Unit 1');
    $u = (strcasecmp($unitVal, 'Unit 2') === 0 || stripos($unitVal, 'PU002') !== false) ? 'Unit 2' : 'Unit 1';
    $cat = $mapMaterialToCategory($log['material_name']);
    if (!isset($categoryData[$u][$cat])) {
        $categoryData[$u][$cat] = ['total_pieces' => 0, 'workers' => []];
    }
    if (!isset($categoryData['All Units'][$cat])) {
        $categoryData['All Units'][$cat] = ['total_pieces' => 0, 'workers' => []];
    }

    $wId = (int)$log['worker_id'];
    $count = (int)$log['piece_count'];
    $wName = !empty($log['name']) ? $log['name'] : ('Operator #' . $wId);
    $wUid  = !empty($log['unique_id']) ? $log['unique_id'] : ('EMP-' . $wId);
    $wRole = !empty($log['role']) ? $log['role'] : 'Operator';

    // Unit-specific tally
    $categoryData[$u][$cat]['total_pieces'] += $count;
    if (!isset($categoryData[$u][$cat]['workers'][$wId])) {
        $categoryData[$u][$cat]['workers'][$wId] = [
            'worker_id' => $wId,
            'name' => $wName,
            'unique_id' => $wUid,
            'unit' => $u,
            'role' => $wRole,
            'pieces' => 0
        ];
    }
    $categoryData[$u][$cat]['workers'][$wId]['pieces'] += $count;

    // All Units combined tally
    $categoryData['All Units'][$cat]['total_pieces'] += $count;
    if (!isset($categoryData['All Units'][$cat]['workers'][$wId])) {
        $categoryData['All Units'][$cat]['workers'][$wId] = [
            'worker_id' => $wId,
            'name' => $wName,
            'unique_id' => $wUid,
            'unit' => $u,
            'role' => $wRole,
            'pieces' => 0
        ];
    }
    $categoryData['All Units'][$cat]['workers'][$wId]['pieces'] += $count;
}

// Sort workers within each category descending by pieces
foreach ($slideKeys as $sKey) {
    foreach ($categoryData[$sKey] as $cat => &$catInfo) {
        $wList = array_values($catInfo['workers']);
        usort($wList, function($a, $b) {
            return $b['pieces'] - $a['pieces'];
        });
        $catInfo['workers'] = $wList;
    }
}

// Category icons helper
if (!function_exists('getCategoryIcon')) {
    function getCategoryIcon($catName) {
        $c = strtolower(trim($catName));
        if (strpos($c, 'maternity') !== false) return ['icon' => 'fa-person-pregnant', 'color' => '#f43f5e', 'badge' => '🤰'];
        if (strpos($c, 'jeans') !== false || strpos($c, 'denim') !== false) return ['icon' => 'fa-socks', 'color' => '#38bdf8', 'badge' => '👖'];
        if (strpos($c, 'shirt') !== false || strpos($c, 'tunic') !== false) return ['icon' => 'fa-shirt', 'color' => '#a855f7', 'badge' => '👔'];
        if (strpos($c, 'nighty') !== false) return ['icon' => 'fa-moon', 'color' => '#f59e0b', 'badge' => '🌙'];
        if (strpos($c, 'feeding') !== false) return ['icon' => 'fa-baby', 'color' => '#ec4899', 'badge' => '👶'];
        return ['icon' => 'fa-vest-patches', 'color' => '#10b981', 'badge' => '👕'];
    }
}

$allTotalPieces = array_sum(array_column($categoryData['All Units'], 'total_pieces'));
$u1TotalPieces  = array_sum(array_column($categoryData['Unit 1'], 'total_pieces'));
$u2TotalPieces  = array_sum(array_column($categoryData['Unit 2'], 'total_pieces'));

$slidesConfig = [
    [
        'idx' => 0,
        'key' => 'All Units',
        'title' => 'All Production Units Combined',
        'badge_label' => 'All Units (PU001 & PU002)',
        'badge_class' => 'unit-all',
        'color' => '#10b981',
        'border_color' => 'rgba(16,185,129,0.3)',
        'header_bg' => 'rgba(16,185,129,0.08)',
        'icon' => 'fa-globe',
        'slide_label' => 'Slide 1 of 3',
        'total' => $allTotalPieces
    ],
    [
        'idx' => 1,
        'key' => 'Unit 1',
        'title' => 'Production Unit 1 (PU001)',
        'badge_label' => 'Production Unit 1 (PU001)',
        'badge_class' => 'unit-1',
        'color' => 'var(--accent-cyan)',
        'border_color' => 'rgba(6,182,212,0.25)',
        'header_bg' => 'rgba(6,182,212,0.08)',
        'icon' => 'fa-industry',
        'slide_label' => 'Slide 2 of 3',
        'total' => $u1TotalPieces
    ],
    [
        'idx' => 2,
        'key' => 'Unit 2',
        'title' => 'Production Unit 2 (PU002)',
        'badge_label' => 'Production Unit 2 (PU002)',
        'badge_class' => 'unit-2',
        'color' => 'var(--unit2-color)',
        'border_color' => 'rgba(236,72,153,0.25)',
        'header_bg' => 'rgba(236,72,153,0.08)',
        'icon' => 'fa-gear',
        'slide_label' => 'Slide 3 of 3',
        'total' => $u2TotalPieces
    ],
];
?>

<!-- ========================================================================= -->
<!-- COMPREHENSIVE CATEGORY-WISE PRODUCTION LEADERBOARDS (ALL UNITS SLIDER) -->
<!-- ========================================================================= -->
<div class="card mb-4 category-slider-section" id="unitCategorySliderSection" style="border: 1px solid rgba(6,182,212,0.3); background: linear-gradient(180deg, rgba(6,182,212,0.04) 0%, rgba(15,23,42,0.95) 100%);">

    <!-- Section Header & Controls -->
    <div class="card-header" style="flex-wrap: wrap; gap: 0.85rem; border-bottom: 1px solid rgba(255,255,255,0.08); padding-bottom: 0.85rem; width: 100%; box-sizing: border-box;">
        <div style="min-width: 0; flex: 1 1 260px; max-width: 100%; box-sizing: border-box;">
            <div class="card-title" style="display: flex; align-items: center; gap: 0.65rem; font-size: 1.1rem; font-weight: 800; flex-wrap: wrap; word-break: break-word;">
                <span style="font-size: 1.3rem;">🏆</span>
                <span>Category Production Leaderboards</span>
            </div>
            <p class="text-muted" style="margin: 0.25rem 0 0 0; font-size: 0.8rem; word-break: break-word; line-height: 1.35;">
                See who stitched the most in each garment category across <strong>All Units</strong>, <strong>Unit 1</strong>, and <strong>Unit 2</strong>.
            </p>
        </div>

        <!-- Slider Navigation & Unit Switcher -->
        <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; min-width: 0; flex: 1 1 260px; justify-content: flex-end; box-sizing: border-box; width: 100%;">
            <!-- All Units / Unit 1 / Unit 2 Tabs -->
            <div class="unit-slider-pill-nav">
                <button type="button" class="unit-slide-tab-btn active" id="btnSlideAll" onclick="switchUnitSlide(0)">
                    <i class="fa-solid fa-globe text-emerald"></i> 
                    <span class="unit-tab-name">All Units</span>
                    <span class="unit-tab-count text-emerald"><?= number_format($allTotalPieces) ?> pcs</span>
                </button>
                <button type="button" class="unit-slide-tab-btn" id="btnSlideUnit1" onclick="switchUnitSlide(1)">
                    <i class="fa-solid fa-industry text-cyan"></i> 
                    <span class="unit-tab-name">Unit 1</span>
                    <span class="unit-tab-count text-cyan"><?= number_format($u1TotalPieces) ?> pcs</span>
                </button>
                <button type="button" class="unit-slide-tab-btn" id="btnSlideUnit2" onclick="switchUnitSlide(2)">
                    <i class="fa-solid fa-gear text-pink"></i> 
                    <span class="unit-tab-name">Unit 2</span>
                    <span class="unit-tab-count text-pink"><?= number_format($u2TotalPieces) ?> pcs</span>
                </button>
            </div>

            <!-- Prev / Next Slider Arrows -->
            <div style="display: flex; gap: 0.35rem; flex-shrink: 0;">
                <button type="button" class="btn btn-outline btn-xs" id="btnPrevUnitSlide" title="Previous Slide" onclick="prevUnitSlide()" style="padding: 0.4rem 0.65rem; border-radius: 8px;">
                    <i class="fa-solid fa-chevron-left"></i> Prev
                </button>
                <button type="button" class="btn btn-outline btn-xs" id="btnNextUnitSlide" title="Next Slide" onclick="nextUnitSlide()" style="padding: 0.4rem 0.65rem; border-radius: 8px;">
                    Next <i class="fa-solid fa-chevron-right"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- CAROUSEL TRACK WRAPPER -->
    <div class="category-carousel-viewport" style="overflow: hidden; width: 100%; position: relative; padding: 1.25rem 0;">
        <div class="category-carousel-track" id="categoryCarouselTrack" style="display: flex; width: 300%; transition: transform 0.45s cubic-bezier(0.4, 0, 0.2, 1);">

            <?php foreach ($slidesConfig as $cfg): 
                $sKey = $cfg['key'];
                $sIdx = $cfg['idx'];
            ?>
                <!-- SLIDE PANE: <?= htmlspecialchars($cfg['title']) ?> -->
                <div class="category-slide-pane" style="width: 33.333333%; padding: 0 1rem; box-sizing: border-box;">
                    <div class="slide-summary-bar" style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem; padding: 0.6rem 1rem; background: <?= $cfg['header_bg'] ?>; border-radius: var(--radius-sm); border: 1px solid <?= $cfg['border_color'] ?>;">
                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                            <span class="unit-badge <?= $cfg['badge_class'] ?>" style="font-size: 0.85rem; padding: 0.25rem 0.65rem;">
                                <i class="fa-solid <?= $cfg['icon'] ?>"></i> <?= htmlspecialchars($cfg['badge_label']) ?>
                            </span>
                            <span class="text-muted" style="font-size: 0.82rem;">• <?= $cfg['slide_label'] ?></span>
                        </div>
                        <div style="font-size: 0.85rem; font-weight: 700; color: <?= $cfg['color'] ?>;">
                            Total Output: <?= number_format($cfg['total']) ?> pcs
                        </div>
                    </div>

                    <!-- Split-Screen Category Cards Grid -->
                    <div class="category-cards-grid">
                        <?php foreach ($activeCategories as $cat): 
                            $cData = $categoryData[$sKey][$cat] ?? ['total_pieces' => 0, 'workers' => []];
                            $workers = $cData['workers'];
                            $catMeta = getCategoryIcon($cat);
                            $hasWorkers = !empty($workers);
                            $leader = $hasWorkers ? $workers[0] : null;
                        ?>
                            <div class="cat-leader-card <?= $hasWorkers ? 'has-production' : 'empty-production' ?>" style="border-color: <?= $cfg['border_color'] ?>;">
                                <!-- Card Header -->
                                <div class="cat-card-header">
                                    <div style="display: flex; align-items: center; gap: 0.6rem; min-width: 0;">
                                        <div class="cat-card-icon" style="background: rgba(<?= hexdec(substr($catMeta['color'],1,2)) ?>, <?= hexdec(substr($catMeta['color'],3,2)) ?>, <?= hexdec(substr($catMeta['color'],5,2)) ?>, 0.15); color: <?= $catMeta['color'] ?>;">
                                            <i class="fa-solid <?= $catMeta['icon'] ?>"></i>
                                        </div>
                                        <div style="min-width: 0;">
                                            <div class="cat-card-title"><?= htmlspecialchars(ucwords($cat)) ?></div>
                                            <div class="cat-card-subtitle">
                                                <?= count($workers) ?> operator<?= count($workers) === 1 ? '' : 's' ?> active
                                            </div>
                                        </div>
                                    </div>
                                    <div class="cat-card-total-badge">
                                        <strong><?= number_format($cData['total_pieces']) ?></strong> pcs
                                    </div>
                                </div>

                                <!-- Card Body: Leader & Rankings -->
                                <?php if ($hasWorkers && $leader): ?>
                                    <!-- 🥇 1st Place Leading Operator Banner -->
                                    <div class="cat-gold-leader-banner">
                                        <div class="leader-banner-top">
                                            <div class="leader-badge-group">
                                                <i class="fa-solid fa-crown gold-crown-icon"></i>
                                                <span class="leader-pill-badge">#1 Leading</span>
                                            </div>
                                            <div class="leader-pieces-count">
                                                <?= number_format($leader['pieces']) ?> <span class="leader-pcs-unit">pcs</span>
                                            </div>
                                        </div>
                                        <div class="leader-banner-bottom">
                                            <div class="leader-avatar-sm">
                                                <?= strtoupper(substr($leader['name'], 0, 1)) ?>
                                            </div>
                                            <div class="leader-info-block">
                                                <div class="leader-name-text" title="<?= htmlspecialchars($leader['name']) ?>">
                                                    <?= htmlspecialchars(ucwords($leader['name'])) ?>
                                                </div>
                                                <div class="leader-meta-sub">
                                                    <span class="leader-uid-badge" style="flex-shrink: 0;"><?= htmlspecialchars($leader['unique_id']) ?></span>
                                                    <?php if ($sKey === 'All Units'): ?>
                                                        <span class="leader-unit-tag" style="background: rgba(255,255,255,0.06); padding: 0.1rem 0.35rem; border-radius: 4px; font-size: 0.68rem; font-weight: 700; color: #38bdf8; flex-shrink: 0;"><?= htmlspecialchars($leader['unit'] ?? '') ?></span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($leader['role'])): ?>
                                                        <span class="leader-dot-sep">•</span>
                                                        <span class="leader-role-text" title="<?= htmlspecialchars($leader['role']) ?>"><?= htmlspecialchars($leader['role']) ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Ranked Operator List (Runners-Up) -->
                                    <?php if (count($workers) > 1): ?>
                                        <div class="cat-runners-list">
                                            <?php foreach (array_slice($workers, 1) as $idx => $w): 
                                                $rank = $idx + 2;
                                            ?>
                                                <div class="cat-runner-row">
                                                    <div class="runner-left-group">
                                                        <div class="runner-rank-col">
                                                            <?php if ($rank === 2): ?>
                                                                <span class="medal-circle medal-silver">2</span>
                                                            <?php elseif ($rank === 3): ?>
                                                                <span class="medal-circle medal-bronze">3</span>
                                                            <?php else: ?>
                                                                <span class="medal-circle medal-default"><?= $rank ?></span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="runner-name-group">
                                                            <div style="display: flex; align-items: center; gap: 0.35rem;">
                                                                <span class="runner-name" title="<?= htmlspecialchars($w['name']) ?>"><?= htmlspecialchars(ucwords($w['name'])) ?></span>
                                                                <?php if ($sKey === 'All Units'): ?>
                                                                    <span style="font-size: 0.65rem; padding: 0.05rem 0.3rem; border-radius: 3px; background: rgba(255,255,255,0.07); color: #94a3b8;"><?= htmlspecialchars($w['unit'] ?? '') ?></span>
                                                                <?php endif; ?>
                                                            </div>
                                                            <span class="runner-uid text-muted"><?= htmlspecialchars($w['unique_id']) ?></span>
                                                        </div>
                                                    </div>
                                                    <div class="runner-pieces-badge">
                                                        <strong><?= number_format($w['pieces']) ?></strong> <span class="runner-pcs-lbl">pcs</span>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                            <?php if (count($workers) === 2): ?>
                                                <div class="cat-runner-row runner-slot-empty">
                                                    <div class="runner-left-group">
                                                        <span class="medal-circle medal-empty"><i class="fa-solid fa-plus" style="font-size: 0.6rem; opacity: 0.45;"></i></span>
                                                        <span style="font-size: 0.74rem; color: var(--text-muted); opacity: 0.7;">3rd place open</span>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="cat-solo-card-block">
                                            <div class="cat-solo-badge">
                                                <i class="fa-solid fa-trophy" style="color: #fbbf24; font-size: 1.15rem; flex-shrink: 0;"></i>
                                                <div style="min-width: 0;">
                                                    <div style="font-weight: 700; font-size: 0.8rem; color: #fff; line-height: 1.2;">Sole Active Operator</div>
                                                    <div style="font-size: 0.7rem; color: var(--text-muted); margin-top: 2px;">100% of category output</div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                <?php else: ?>
                                    <!-- Empty State for Category with Zero Production -->
                                    <div class="cat-empty-state">
                                        <div class="empty-icon-ring">
                                            <i class="fa-solid <?= $catMeta['icon'] ?>"></i>
                                        </div>
                                        <div class="empty-title">Open Category</div>
                                        <div class="empty-sub">
                                            No pieces stitched in <?= htmlspecialchars($cfg['badge_label']) ?> yet
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>

        </div>
    </div>

    <!-- Slider Dots & Bottom Control Bar -->
    <div style="display: flex; align-items: center; justify-content: center; gap: 0.5rem; padding: 0.85rem 1rem; border-top: 1px solid rgba(255,255,255,0.06);">
        <button type="button" class="unit-slide-dot active" id="slideDot0" onclick="switchUnitSlide(0)" title="Slide 1: All Units"></button>
        <button type="button" class="unit-slide-dot" id="slideDot1" onclick="switchUnitSlide(1)" title="Slide 2: Unit 1"></button>
        <button type="button" class="unit-slide-dot" id="slideDot2" onclick="switchUnitSlide(2)" title="Slide 3: Unit 2"></button>
    </div>

</div>

<!-- Styles for Split-Screen Leader Carousel -->
<style>
.unit-badge.unit-all {
    background: rgba(16, 185, 129, 0.15);
    color: #10b981;
    border: 1px solid rgba(16, 185, 129, 0.35);
}

.category-cards-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 1.1rem;
    align-items: stretch;
}

@media (min-width: 1600px) {
    .category-cards-grid {
        grid-template-columns: repeat(6, minmax(215px, 1fr));
    }
}

.cat-leader-card {
    background: linear-gradient(180deg, rgba(22, 31, 54, 0.7) 0%, rgba(13, 18, 32, 0.85) 100%);
    backdrop-filter: blur(16px);
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-top: 1px solid rgba(255, 255, 255, 0.16);
    border-radius: var(--radius-lg);
    padding: 1.15rem;
    display: flex;
    flex-direction: column;
    min-height: 310px;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.35);
    transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
}

.cat-leader-card:hover {
    transform: translateY(-4px);
    border-color: rgba(59, 130, 246, 0.45);
    box-shadow: 0 16px 36px rgba(0, 0, 0, 0.5), 0 0 20px rgba(59, 130, 246, 0.2);
}

.cat-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.4rem;
    margin-bottom: 0.75rem;
}

.cat-card-icon {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    flex-shrink: 0;
}

.cat-card-title {
    font-size: 0.96rem;
    font-weight: 700;
    color: var(--text-primary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.cat-card-subtitle {
    font-size: 0.74rem;
    color: var(--text-muted);
}

.cat-card-total-badge {
    background: linear-gradient(135deg, rgba(6, 182, 212, 0.2) 0%, rgba(59, 130, 246, 0.15) 100%);
    color: #38bdf8;
    border: 1px solid rgba(56, 189, 248, 0.4);
    padding: 0.22rem 0.65rem;
    border-radius: 50px;
    font-size: 0.78rem;
    font-weight: 700;
    white-space: nowrap;
    flex-shrink: 0;
    box-shadow: 0 0 10px rgba(6, 182, 212, 0.2);
}

/* 🥇 Top Leader Gold Card Styling */
.cat-gold-leader-banner {
    background: linear-gradient(135deg, rgba(251, 191, 36, 0.18) 0%, rgba(245, 158, 11, 0.08) 100%);
    border: 1px solid rgba(251, 191, 36, 0.45);
    border-radius: var(--radius-md);
    padding: 0.75rem 0.85rem;
    margin-bottom: 0.75rem;
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    box-shadow: 0 4px 14px rgba(251, 191, 36, 0.12), inset 0 1px 0 rgba(254, 240, 138, 0.25);
}

.leader-banner-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
}

.leader-badge-group {
    display: flex;
    align-items: center;
    gap: 0.4rem;
}

.gold-crown-icon {
    color: #fbbf24;
    font-size: 0.95rem;
}

.leader-pill-badge {
    background: rgba(251, 191, 36, 0.22);
    color: #fbbf24;
    padding: 0.12rem 0.45rem;
    border-radius: 4px;
    font-size: 0.68rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}

.leader-pieces-count {
    color: #fbbf24;
    font-size: 0.92rem;
    font-weight: 800;
    text-align: right;
    white-space: nowrap;
}

.leader-pcs-unit {
    font-size: 0.7rem;
    font-weight: 600;
    opacity: 0.85;
}

.leader-banner-bottom {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    width: 100%;
}

.leader-avatar-sm {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: linear-gradient(135deg, #fbbf24 0%, #d97706 100%);
    color: #0f172a;
    font-weight: 900;
    font-size: 0.85rem;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    box-shadow: 0 2px 8px rgba(251, 191, 36, 0.35);
}

.leader-info-block {
    min-width: 0;
    flex: 1;
}

.leader-name-text {
    font-size: 0.88rem;
    font-weight: 800;
    color: #fff;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    line-height: 1.2;
}

.leader-meta-sub {
    display: flex;
    align-items: center;
    gap: 0.35rem;
    margin-top: 2px;
    font-size: 0.72rem;
    color: var(--text-muted);
    min-width: 0;
}

.leader-uid-badge {
    color: var(--primary);
    font-weight: 700;
    font-size: 0.72rem;
    flex-shrink: 0;
}

.leader-dot-sep {
    opacity: 0.4;
    flex-shrink: 0;
}

.leader-role-text {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: var(--text-muted);
    min-width: 0;
}

/* 🥈 🥉 Runners-Up List */
.cat-runners-list {
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
    flex: 1;
}

.cat-runner-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.4rem 0.55rem;
    background: rgba(255, 255, 255, 0.02);
    border: 1px solid rgba(255, 255, 255, 0.05);
    border-radius: var(--radius-sm);
    font-size: 0.78rem;
    transition: background 0.2s ease;
}

.cat-runner-row:hover {
    background: rgba(255, 255, 255, 0.05);
}

.runner-left-group {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    min-width: 0;
    flex: 1;
}

.runner-rank-col {
    flex-shrink: 0;
}

.medal-circle {
    width: 20px;
    height: 20px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.7rem;
    font-weight: 800;
}

.medal-silver {
    background: rgba(148, 163, 184, 0.25);
    color: #e2e8f0;
    border: 1px solid rgba(148, 163, 184, 0.5);
}

.medal-bronze {
    background: rgba(217, 119, 6, 0.25);
    color: #f59e0b;
    border: 1px solid rgba(217, 119, 6, 0.5);
}

.medal-default {
    background: rgba(255, 255, 255, 0.05);
    color: var(--text-muted);
}

.medal-empty {
    background: rgba(255, 255, 255, 0.02);
    border: 1px dashed rgba(255, 255, 255, 0.15);
}

.runner-name-group {
    min-width: 0;
    flex: 1;
}

.runner-name {
    font-weight: 700;
    color: var(--text-primary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    display: block;
}

.runner-uid {
    font-size: 0.68rem;
    display: block;
    line-height: 1.1;
}

.runner-pieces-badge {
    color: #fff;
    font-size: 0.8rem;
    font-weight: 700;
    text-align: right;
    white-space: nowrap;
    margin-left: 0.4rem;
}

.runner-pcs-lbl {
    font-size: 0.68rem;
    color: var(--text-muted);
    font-weight: 500;
}

.runner-slot-empty {
    border-style: dashed;
    border-color: rgba(255, 255, 255, 0.06);
    background: transparent;
}

/* Solo Active Operator Badge */
.cat-solo-card-block {
    margin-top: auto;
    padding-top: 0.5rem;
}

.cat-solo-badge {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    padding: 0.6rem 0.75rem;
    background: rgba(251, 191, 36, 0.06);
    border: 1px dashed rgba(251, 191, 36, 0.28);
    border-radius: var(--radius-sm);
}

/* Empty State */
.cat-empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 2.2rem 1rem;
    margin: auto 0;
}

.empty-icon-ring {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.03);
    border: 1px dashed rgba(255, 255, 255, 0.12);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
    color: var(--text-muted);
    margin-bottom: 0.6rem;
}

.empty-title {
    font-size: 0.82rem;
    font-weight: 700;
    color: var(--text-secondary);
}

.empty-sub {
    font-size: 0.72rem;
    color: var(--text-muted);
    margin-top: 0.2rem;
}

/* Pill Tabs & Dots Controls */
.unit-slide-tab-btn {
    border: none;
    background: transparent;
    color: var(--text-secondary);
    font-size: 0.8rem;
    font-weight: 700;
    padding: 0.35rem 0.85rem;
    border-radius: 50px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    transition: all 0.25s ease;
}

.unit-slide-tab-btn.active {
    background: rgba(255, 255, 255, 0.12);
    color: #fff;
    box-shadow: 0 2px 8px rgba(0,0,0,0.3);
}

.unit-tab-count {
    font-size: 0.72rem;
    font-weight: 700;
    background: rgba(0,0,0,0.35);
    padding: 0.1rem 0.45rem;
    border-radius: 50px;
}

.unit-slide-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    border: none;
    background: rgba(255, 255, 255, 0.2);
    cursor: pointer;
    transition: all 0.25s ease;
    padding: 0;
}

.unit-slide-dot.active {
    width: 26px;
    border-radius: 50px;
    background: var(--accent-cyan);
    box-shadow: 0 0 10px rgba(6, 182, 212, 0.6);
}
</style>

<!-- Slider JavaScript Controller -->
<script>
window.currentUnitSlideIndex = window.currentUnitSlideIndex || 0;

window.switchUnitSlide = function(slideIdx) {
    window.currentUnitSlideIndex = slideIdx;
    const track = document.getElementById('categoryCarouselTrack');
    const btnAll = document.getElementById('btnSlideAll');
    const btn1 = document.getElementById('btnSlideUnit1');
    const btn2 = document.getElementById('btnSlideUnit2');
    const dot0 = document.getElementById('slideDot0');
    const dot1 = document.getElementById('slideDot1');
    const dot2 = document.getElementById('slideDot2');

    if (track) {
        track.style.transform = `translateX(-${slideIdx * (100 / 3)}%)`;
    }

    [btnAll, btn1, btn2].forEach((btn, idx) => {
        if (btn) {
            if (idx === slideIdx) btn.classList.add('active');
            else btn.classList.remove('active');
        }
    });

    [dot0, dot1, dot2].forEach((dot, idx) => {
        if (dot) {
            if (idx === slideIdx) dot.classList.add('active');
            else dot.classList.remove('active');
        }
    });
};

window.prevUnitSlide = function() {
    const cur = window.currentUnitSlideIndex || 0;
    const nextIdx = (cur === 0) ? 2 : cur - 1;
    window.switchUnitSlide(nextIdx);
};

window.nextUnitSlide = function() {
    const cur = window.currentUnitSlideIndex || 0;
    const nextIdx = (cur === 2) ? 0 : cur + 1;
    window.switchUnitSlide(nextIdx);
};

window.refreshCategorySlider = async function() {
    try {
        const res = await fetch('api.php?action=get_unit_category_slider');
        const data = await res.json();
        if (data && data.success && data.html) {
            const container = document.getElementById('unitCategorySliderSection');
            if (container) {
                const tempDiv = document.createElement('div');
                tempDiv.innerHTML = data.html;
                const newSection = tempDiv.querySelector('#unitCategorySliderSection');
                if (newSection) {
                    container.innerHTML = newSection.innerHTML;
                    if (typeof window.switchUnitSlide === 'function') {
                        window.switchUnitSlide(window.currentUnitSlideIndex || 0);
                    }
                }
            }
        }
    } catch (e) {
        console.error('Error refreshing category slider:', e);
    }
};
</script>
