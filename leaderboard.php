<?php
// leaderboard.php - Workers Leaderboard & Production Competition
$pageTitle = 'Workers Leaderboard - FabriqPro';
require_once __DIR__ . '/includes/auth.php';
requireAuth();
require_once __DIR__ . '/db.php';
$pdo = Database::getConnection();

// Fetch summary metrics
$totalWorkers = $pdo->query("SELECT COUNT(*) FROM workers")->fetchColumn() ?: 0;
$totalPieces = $pdo->query("SELECT COALESCE(SUM(piece_count), 0) FROM piece_logs")->fetchColumn() ?: 0;

$unit1Pieces = $pdo->query("
    SELECT COALESCE(SUM(pl.piece_count), 0) 
    FROM piece_logs pl 
    JOIN workers w ON pl.worker_id = w.id 
    WHERE w.unit = 'Unit 1'
")->fetchColumn() ?: 0;

$unit2Pieces = $pdo->query("
    SELECT COALESCE(SUM(pl.piece_count), 0) 
    FROM piece_logs pl 
    JOIN workers w ON pl.worker_id = w.id 
    WHERE w.unit = 'Unit 2'
")->fetchColumn() ?: 0;

$unit1Pct = ($totalPieces > 0) ? round(($unit1Pieces / $totalPieces) * 100, 1) : 50;
$unit2Pct = ($totalPieces > 0) ? round(($unit2Pieces / $totalPieces) * 100, 1) : 50;

include __DIR__ . '/includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="page-header-title">
        <h1><i class="fa-solid fa-trophy text-amber"></i> Workforce Production Leaderboard</h1>
    </div>
    <div class="page-header-actions">
        <a href="daily_output.php" class="btn btn-outline">
            <i class="fa-solid fa-shirt"></i> Today's Clothes
        </a>
    </div>
</div>

<!-- Macro Stats -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon emerald">
            <i class="fa-solid fa-gauge-high"></i>
        </div>
        <div class="stat-info">
            <div class="stat-label">Total Output</div>
            <div class="stat-value text-emerald"><?= number_format($totalPieces) ?></div>
            <div class="stat-sub">Across all shifts &amp; units</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon cyan">
            <i class="fa-solid fa-industry"></i>
        </div>
        <div class="stat-info">
            <div class="stat-label">Unit 1 Output</div>
            <div class="stat-value text-cyan"><?= number_format($unit1Pieces) ?></div>
            <div class="stat-sub"><?= $unit1Pct ?>% of total production</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon pink">
            <i class="fa-solid fa-building-gear"></i>
        </div>
        <div class="stat-info">
            <div class="stat-label">Unit 2 Output</div>
            <div class="stat-value" style="color: var(--unit2-color);"><?= number_format($unit2Pieces) ?></div>
            <div class="stat-sub"><?= $unit2Pct ?>% of total production</div>
        </div>
    </div>
</div>

<!-- Unit vs Unit Battle Bar -->
<div class="card mb-4 unit-battle-card">
    <div class="unit-battle-header">
        <div class="unit-1-text">
            <i class="fa-solid fa-industry"></i> Unit 1 (<?= number_format($unit1Pieces) ?> pcs • <?= $unit1Pct ?>%)
        </div>
        <div class="unit-2-text">
            Unit 2 (<?= number_format($unit2Pieces) ?> pcs • <?= $unit2Pct ?>%) <i class="fa-solid fa-gear"></i>
        </div>
    </div>
    <div class="unit-battle-bar-wrapper">
        <div class="unit-battle-bar-u1" style="width: <?= $unit1Pct ?>%;"></div>
        <div class="unit-battle-bar-u2" style="width: <?= $unit2Pct ?>%;"></div>
    </div>
</div>

<!-- TOP 3 PODIUM SECTION -->
<div style="margin-bottom: 1rem;">
    <h2 class="podium-section-heading">
        <i class="fa-solid fa-medal text-amber"></i> Top Performers Podium
    </h2>

    <div class="podium-container">
        <!-- Rank 2: Silver -->
        <div class="podium-card rank-2" id="podiumRank2">
            <!-- Dynamically populated via AJAX -->
        </div>

        <!-- Rank 1: Gold (Center & Elevated) -->
        <div class="podium-card rank-1" id="podiumRank1">
            <!-- Dynamically populated via AJAX -->
        </div>

        <!-- Rank 3: Bronze -->
        <div class="podium-card rank-3" id="podiumRank3">
            <!-- Dynamically populated via AJAX -->
        </div>
    </div>
</div>

<!-- UNIT 1 & UNIT 2 CATEGORY-WISE COMPETITION SLIDER -->
<?php include __DIR__ . '/includes/unit_category_slider.php'; ?>

<!-- FULL LEADERBOARD TABLE -->
<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fa-solid fa-ranking-star"></i> Complete Workers Ranking Table
        </div>
        <span class="text-muted" style="font-size: 0.85rem;">Ranked by cumulative piece counts</span>
    </div>

    <!-- Filter & Search Toolbar -->
    <div class="filter-toolbar">
        <div class="filter-group">
            <div class="search-input-wrapper">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" id="lbSearchInput" class="form-control" placeholder="Search worker name, ID, role...">
            </div>

            <!-- Cloth / Material Filter -->
            <select id="lbMaterialFilter" class="form-control">
                <option value="">👕 All Cloths / Fabrics</option>
                <?php
                $matStmt = $pdo->query("SELECT DISTINCT material_name FROM piece_logs WHERE material_name IS NOT NULL AND material_name != '' ORDER BY material_name ASC");
                $leaderboardMaterials = $matStmt->fetchAll(PDO::FETCH_COLUMN);
                foreach ($leaderboardMaterials as $m):
                ?>
                    <option value="<?= htmlspecialchars($m) ?>"><?= htmlspecialchars($m) ?></option>
                <?php endforeach; ?>
            </select>

            <select id="lbUnitFilter" class="form-control">
                <option value="">All Units</option>
                <?php 
                $allUnits = $pdo->query("SELECT * FROM production_units ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($allUnits as $u): 
                ?>
                    <option value="<?= htmlspecialchars($u['unit_name']) ?>"><?= htmlspecialchars($u['unit_title']) ?><?= (($u['status'] ?? 'Active') === 'Disabled') ? ' (Disabled)' : '' ?></option>
                <?php endforeach; ?>
            </select>

            <select id="lbSortFilter" class="form-control">
                <option value="pieces_desc">Highest Pieces (Rank)</option>
                <option value="experience">Most Experience</option>
                <option value="recent">Recently Added</option>
                <option value="name">Alphabetical (Name)</option>
            </select>
        </div>
    </div>

    <!-- Table -->
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 60px;">Rank</th>
                    <th>Worker Details</th>
                    <th>Production Unit</th>
                    <th>Experience</th>
                    <th>Total Output</th>
                    <th>Contact</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="leaderboardTableBody">
                <!-- Dynamically populated via AJAX in app.js -->
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
