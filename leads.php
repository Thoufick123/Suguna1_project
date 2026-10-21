<?php
// leads.php - Dedicated Dashboard Leads Page based on Worker Piece Count including Material Name
$pageTitle = 'Dashboard Leads & Production Records - FabriqPro';
require_once __DIR__ . '/db.php';
$pdo = Database::getConnection();

// Pre-fetch distinct materials for the filter dropdown
$distinctMaterials = $pdo->query("SELECT DISTINCT material_name FROM piece_logs ORDER BY material_name ASC")->fetchAll(PDO::FETCH_COLUMN);

include __DIR__ . '/includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="page-header-title">
        <h1><i class="fa-solid fa-table-list text-cyan"></i> Dashboard Leads & Piece Records</h1>
        <p>Real-time production leads tracked by worker, assigned production unit, and specific material batches.</p>
    </div>
    <div class="page-header-actions">
        <a href="leaderboard.php" class="btn btn-outline">
            <i class="fa-solid fa-trophy"></i> View Leaderboard
        </a>
    </div>
</div>

<!-- KPI Stats Grid -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon emerald">
            <i class="fa-solid fa-boxes-stacked"></i>
        </div>
        <div class="stat-info">
            <div class="stat-label">Total Pieces Logged</div>
            <div class="stat-value text-emerald" id="leadTotalPiecesStat">...</div>
            <div class="stat-sub">Across filtered leads</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon cyan">
            <i class="fa-solid fa-layer-group"></i>
        </div>
        <div class="stat-info">
            <div class="stat-label">Active Materials</div>
            <div class="stat-value text-cyan" id="leadTotalMaterialsStat">...</div>
            <div class="stat-sub">Distinct fabric & materials</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon amber">
            <i class="fa-solid fa-file-invoice"></i>
        </div>
        <div class="stat-info">
            <div class="stat-label">Total Production Entries</div>
            <div class="stat-value text-amber" id="leadTotalEntriesStat">...</div>
            <div class="stat-sub">Logged shift records</div>
        </div>
    </div>
</div>

<!-- Main Leads Data Table Card -->
<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fa-solid fa-receipt"></i> Production Leads & Material Breakdown
        </div>
        <button id="leadRefreshBtn" class="btn btn-outline btn-sm" title="Refresh Leads Data">
            <i class="fa-solid fa-rotate"></i> Refresh
        </button>
    </div>

    <!-- Filter & Search Toolbar -->
    <div class="filter-toolbar">
        <div class="filter-group">
            <!-- Search -->
            <div class="search-input-wrapper">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" id="leadSearchInput" class="form-control" placeholder="Search worker, ID, material...">
            </div>

            <!-- Unit Filter -->
            <select id="leadUnitFilter" class="form-control">
                <option value="">All Production Units</option>
                <?php 
                $allUnits = $pdo->query("SELECT * FROM production_units ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($allUnits as $u): 
                ?>
                    <option value="<?= htmlspecialchars($u['unit_name']) ?>"><?= htmlspecialchars($u['unit_title']) ?><?= (($u['status'] ?? 'Active') === 'Disabled') ? ' (Disabled)' : '' ?></option>
                <?php endforeach; ?>
            </select>

            <!-- Material Filter -->
            <select id="leadMaterialFilter" class="form-control">
                <option value="">All Materials</option>
                <?php foreach ($distinctMaterials as $mat): ?>
                    <option value="<?= htmlspecialchars($mat) ?>"><?= htmlspecialchars($mat) ?></option>
                <?php endforeach; ?>
            </select>

            <!-- Date Filter -->
            <input type="date" id="leadDateFilter" class="form-control" title="Filter by date">
        </div>

        <div class="filter-status-indicator">
            <span class="text-muted" style="font-size: 0.82rem;"><i class="fa-solid fa-database text-cyan"></i> Live synced from Database</span>
        </div>
    </div>

    <!-- Leads Responsive Table -->
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 50px;">#</th>
                    <th>Worker Details</th>
                    <th>Production Unit</th>
                    <th>Material Name</th>
                    <th>Pieces Completed</th>
                    <th>Date & Shift</th>
                    <th>Worker Total</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody id="leadsTableBody">
                <!-- Dynamically populated via AJAX in app.js -->
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
