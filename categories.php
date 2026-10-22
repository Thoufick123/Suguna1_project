<?php
// categories.php - Dynamic 3-Tier Production Category, Subcategory & Garment Management
$pageTitle = 'Garment Categories - FabriqPro';
require_once __DIR__ . '/includes/auth.php';
requireAuth();

$currentUser = getCurrentUser();
if ($currentUser['role'] !== 'admin' && $currentUser['role'] !== 'supervisor') {
    header("Location: daily_output.php?error=unauthorized");
    exit;
}

$pdo = Database::getConnection();
$cooldownSec = (int)Database::getSetting('stitching_cooldown_seconds', 30);

// Fetch 3-tier tree from garment_categories
$stmt = $pdo->query("SELECT id, category_name, subcategory_name, sub_subcategory_name, status, created_at FROM garment_categories ORDER BY category_name ASC, subcategory_name ASC, sub_subcategory_name ASC");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Initial summary calculations
$allCategories = [];
$allSubcategories = [];
$activeCategories = [];
$totalGarmentTypes = 0;

foreach ($rows as $r) {
    $c = $r['category_name'];
    $s = $r['subcategory_name'];
    $subsub = trim($r['sub_subcategory_name'] ?? '');
    $st = !empty($r['status']) ? $r['status'] : 'Active';

    $allCategories[$c] = true;
    $allSubcategories[$s] = true;
    if ($st === 'Active') {
        $activeCategories[$c] = true;
    }
    $totalGarmentTypes++;
}

$totalCategories = count($allCategories);
$totalSubcategories = count($allSubcategories);
$totalActiveCategories = count($activeCategories);

include __DIR__ . '/includes/header.php';
?>

<!-- Page Header -->
<div class="page-header" style="margin-bottom: 1.5rem;">
    <div class="page-header-title">
        <h1><i class="fa-solid fa-layer-group text-cyan"></i> Garment Category Management</h1>
        <p>Configure the 3-tier hierarchy: <strong>Category ➔ Subcategory ➔ Garment Type</strong>. One side manages the master directory; the other side tracks live active items for workstations.</p>
    </div>
    <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
        <button type="button" id="btnToggleAddCard" class="btn btn-primary">
            <i class="fa-solid fa-circle-plus"></i> <span id="toggleAddText">Add New Category / Garment</span>
        </button>
        <a href="daily_output.php" class="btn btn-outline">
            <i class="fa-solid fa-shirt"></i> Open Workstation
        </a>
    </div>
</div>

<!-- Admin Workstation Cooldown Setting Card -->
<div class="card mb-4" style="border: 1px dashed rgba(245, 158, 11, 0.4); background: linear-gradient(180deg, rgba(245, 158, 11, 0.05) 0%, rgba(18, 24, 38, 0.6) 100%);">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.75rem; padding: 1rem 1.25rem;">
        <div style="display: flex; align-items: center; gap: 0.75rem;">
            <div style="width: 42px; height: 42px; border-radius: 10px; background: rgba(245, 158, 11, 0.15); color: #fbbf24; display: flex; align-items: center; justify-content: center; font-size: 1.2rem;">
                <i class="fa-solid fa-stopwatch"></i>
            </div>
            <div>
                <h3 style="font-size: 0.95rem; font-weight: 700; color: #fff; margin: 0; display: flex; align-items: center; gap: 0.45rem;">
                    Complete Button Waiting Time (Workstation Cooldown)
                    <span class="badge-pill" style="font-size: 0.7rem; background: rgba(245, 158, 11, 0.2); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.4);">Admin Setting</span>
                </h3>
                <p style="font-size: 0.8rem; color: var(--text-muted); margin: 0.2rem 0 0 0;">
                    Controls how many seconds operators must wait between consecutive piece completions in Today's Clothes.
                </p>
            </div>
        </div>
        <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
            <span style="font-size: 0.78rem; color: var(--text-secondary); font-weight: 600;">Current: <strong id="catCurrentCooldownVal" class="text-amber"><?= $cooldownSec ?>s</strong></span>
            
            <button type="button" class="btn btn-xs btn-outline cat-cooldown-preset" data-sec="0" title="Instant (0s)">0s</button>
            <button type="button" class="btn btn-xs btn-outline cat-cooldown-preset" data-sec="5">5s</button>
            <button type="button" class="btn btn-xs btn-outline cat-cooldown-preset" data-sec="10">10s</button>
            <button type="button" class="btn btn-xs btn-outline cat-cooldown-preset" data-sec="15">15s</button>
            <button type="button" class="btn btn-xs btn-outline cat-cooldown-preset" data-sec="30">30s</button>
            <button type="button" class="btn btn-xs btn-outline cat-cooldown-preset" data-sec="60">60s</button>

            <div style="display: inline-flex; align-items: center; background: rgba(0,0,0,0.35); border: 1px solid var(--border-color); border-radius: 6px; padding: 2px 6px;">
                <button type="button" id="catDecCooldownBtn" style="background: none; border: none; color: var(--text-muted); cursor: pointer; padding: 2px 5px;"><i class="fa-solid fa-minus" style="font-size: 0.7rem;"></i></button>
                <input type="number" id="catCooldownInput" value="<?= $cooldownSec ?>" min="0" max="600" step="1" style="width: 50px; background: transparent; border: none; text-align: center; color: #fff; font-size: 0.85rem; font-weight: 700; outline: none;">
                <span style="font-size: 0.75rem; color: var(--text-muted); margin-right: 4px;">sec</span>
                <button type="button" id="catIncCooldownBtn" style="background: none; border: none; color: var(--text-muted); cursor: pointer; padding: 2px 5px;"><i class="fa-solid fa-plus" style="font-size: 0.7rem;"></i></button>
            </div>

            <button type="button" id="catSaveCooldownBtn" class="btn btn-sm btn-primary" style="background: linear-gradient(135deg, #f59e0b, #d97706); border: none; color: #000; font-weight: 700; padding: 0.35rem 0.85rem;">
                <i class="fa-solid fa-check"></i> Save Time
            </button>
        </div>
    </div>
</div>

<!-- Stats Counter -->
<div class="stats-grid stats-grid-4 mb-4">
    <div class="stat-card">
        <div class="stat-icon primary">
            <i class="fa-solid fa-folder"></i>
        </div>
        <div class="stat-info">
            <span class="stat-label">All Categories</span>
            <span class="stat-value" id="statsCatCount"><?= $totalCategories ?></span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon warning">
            <i class="fa-solid fa-folder-tree"></i>
        </div>
        <div class="stat-info">
            <span class="stat-label">All Subcategories</span>
            <span class="stat-value" id="statsSubcatCount"><?= $totalSubcategories ?></span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon success">
            <i class="fa-solid fa-circle-check"></i>
        </div>
        <div class="stat-info">
            <span class="stat-label">Active in Workstation</span>
            <span class="stat-value" id="statsActiveCatCount"><?= $totalActiveCategories ?></span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background: rgba(168,85,247,0.15); color: #c084fc;">
            <i class="fa-solid fa-tags"></i>
        </div>
        <div class="stat-info">
            <span class="stat-label">Garment Definitions</span>
            <span class="stat-value" id="statsItemCount"><?= $totalGarmentTypes ?></span>
        </div>
    </div>
</div>

<!-- Collapsible Add New Category / Subcategory Form Card -->
<div class="card mb-4" id="addGarmentCard" style="display: none; border: 1px solid rgba(6,182,212,0.3); background: rgba(15,23,42,0.85); box-shadow: 0 8px 24px rgba(0,0,0,0.35);">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; border-bottom: 1px solid rgba(255,255,255,0.08); padding-bottom: 0.75rem; flex-wrap: wrap; gap: 0.65rem;">
        <div style="min-width: 0; flex: 1 1 auto;">
            <div style="font-weight: 700; font-size: 1.05rem; color: var(--text-primary); display: flex; align-items: center; gap: 0.55rem; flex-wrap: wrap; word-break: break-word;">
                <i class="fa-solid fa-plus-circle text-cyan"></i> Add New Category, Subcategory or Garment
            </div>
            <p class="text-muted" style="font-size: 0.8rem; margin: 0.2rem 0 0 0; word-break: break-word; line-height: 1.35;">
                Sub-subcategory is optional. If left blank, the subcategory itself functions directly as the garment in workstations.
            </p>
        </div>
        <button type="button" id="btnCloseAddCard" class="btn btn-outline btn-xs" style="color: var(--text-muted); flex-shrink: 0;">
            <i class="fa-solid fa-chevron-up"></i> Hide Form
        </button>
    </div>

    <form id="pageAddCategoryForm" autocomplete="off">
        <div class="cat-add-form-grid">
            <!-- 1. Category -->
            <div class="form-group mb-0">
                <label class="form-label" for="pageCatInput" style="font-size: 0.82rem; font-weight: 600;">
                    1. Main Category <span class="required" style="color: var(--accent-rose);">*</span>
                </label>
                <input type="text" id="pageCatInput" list="catSuggestions" class="form-control" placeholder="e.g. Maternity, Shirts, Pants, Kids" required>
                <datalist id="catSuggestions">
                    <?php foreach (array_keys($allCategories) as $c): ?>
                        <option value="<?= htmlspecialchars($c) ?>">
                    <?php endforeach; ?>
                </datalist>
                <small class="text-muted" style="font-size: 0.73rem;">Top-level department</small>
            </div>

            <!-- 2. Subcategory -->
            <div class="form-group mb-0">
                <label class="form-label" for="pageSubcatInput" style="font-size: 0.82rem; font-weight: 600;">
                    2. Subcategory <span class="required" style="color: var(--accent-rose);">*</span>
                </label>
                <input type="text" id="pageSubcatInput" list="subcatSuggestions" class="form-control" placeholder="e.g. Feeding Wear, Formal, Denim" required>
                <datalist id="subcatSuggestions">
                    <?php foreach (array_keys($allSubcategories) as $s): ?>
                        <option value="<?= htmlspecialchars($s) ?>">
                    <?php endforeach; ?>
                </datalist>
                <small class="text-muted" style="font-size: 0.73rem;">Group under main category</small>
            </div>

            <!-- 3. Sub-Subcategory / Garment -->
            <div class="form-group mb-0">
                <label class="form-label" for="pageSubSubcatInput" style="font-size: 0.82rem; font-weight: 600;">
                    3. Sub-Subcategory (Garment Name) <span class="text-muted" style="font-weight: normal;">(Optional)</span>
                </label>
                <input type="text" id="pageSubSubcatInput" class="form-control" placeholder="e.g. Nighty, Cotton Kurti (Optional)">
                <small class="text-muted" style="font-size: 0.73rem;">Leave empty if subcategory is garment</small>
            </div>

            <!-- 4. Category Stitching Time / Workstation Cooldown -->
            <div class="form-group mb-0">
                <label class="form-label" for="pageStitchTimeInput" style="font-size: 0.82rem; font-weight: 600; color: #fbbf24;">
                    <i class="fa-solid fa-stopwatch"></i> 4. Stitching Time (Cooldown)
                </label>
                <div style="display: flex; align-items: center; gap: 0.45rem;">
                    <input type="number" id="pageStitchTimeInput" class="form-control" min="0" max="600" step="1" value="0" placeholder="0" style="font-weight: 700; text-align: center; max-width: 110px;">
                    <span style="font-size: 0.8rem; color: var(--text-muted); font-weight: 600;">sec</span>
                </div>
                <small class="text-muted" style="font-size: 0.73rem;">Waiting time between pieces at workstation</small>
            </div>

            <!-- Submit Button -->
            <div>
                <button type="submit" id="pageSaveBtn" class="btn btn-primary" style="height: 42px; padding: 0 1.25rem; font-weight: 700; font-size: 0.9rem; white-space: nowrap; display: flex; align-items: center; gap: 0.5rem;">
                    <i class="fa-solid fa-plus"></i> Save to System
                </button>
            </div>
        </div>

        <div id="pageFormAlert" style="display: none; padding: 0.65rem 0.9rem; border-radius: var(--radius-sm); font-size: 0.82rem; margin-top: 0.85rem;"></div>
    </form>
</div>

<!-- ============================================================= -->
<!-- SPLIT SCREEN: ALL CATEGORIES (LEFT) vs ACTIVE CATEGORIES (RIGHT) -->
<!-- ============================================================= -->
<div class="cat-split-grid">

    <!-- ============================================================= -->
    <!-- LEFT SIDE: ALL CATEGORIES (MASTER DIRECTORY & MANAGEMENT)     -->
    <!-- ============================================================= -->
    <div class="card" style="border: 1px solid rgba(255,255,255,0.08); background: rgba(15,23,42,0.65);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.85rem; flex-wrap: wrap; gap: 0.75rem;">
            <div>
                <h3 style="margin: 0; font-size: 1.1rem; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 0.55rem;">
                    <i class="fa-solid fa-list-check text-cyan"></i> All Categories (Master Directory)
                </h3>
                <span class="text-muted" style="font-size: 0.78rem;">Full directory of all categories. Toggle Active/Disabled or Delete.</span>
            </div>
            <div>
                <input type="text" id="allCategoriesSearch" class="form-control" placeholder="🔍 Search all categories..." style="font-size: 0.82rem; padding: 0.4rem 0.75rem; width: 200px;">
            </div>
        </div>

        <div id="allCategoriesContainer" style="display: flex; flex-direction: column; gap: 1rem;">
            <!-- Rendered by JS -->
        </div>
    </div>

    <!-- ============================================================= -->
    <!-- RIGHT SIDE: ACTIVE CATEGORIES HIERARCHY (WORKSTATION VIEW)    -->
    <!-- ============================================================= -->
    <div class="card" style="border: 1px solid rgba(16,185,129,0.25); background: rgba(15,23,42,0.65);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.85rem; flex-wrap: wrap; gap: 0.75rem;">
            <div>
                <h3 style="margin: 0; font-size: 1.1rem; font-weight: 700; color: #10b981; display: flex; align-items: center; gap: 0.55rem;">
                    <i class="fa-solid fa-sitemap"></i> Active Categories (Workstation View)
                </h3>
                <span class="text-muted" style="font-size: 0.78rem;">Only active items available dynamically in operator workstation dropdowns.</span>
            </div>
            <div>
                <input type="text" id="activeCategoriesSearch" class="form-control" placeholder="🔍 Filter active hierarchy..." style="font-size: 0.82rem; padding: 0.4rem 0.75rem; width: 200px;">
            </div>
        </div>

        <div id="activeCategoriesContainer" style="display: flex; flex-direction: column; gap: 1rem;">
            <!-- Rendered by JS -->
        </div>
    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // Initial data from PHP
    let currentRows = <?= json_encode($rows) ?>;

    // Elements
    const allContainer = document.getElementById('allCategoriesContainer');
    const activeContainer = document.getElementById('activeCategoriesContainer');
    const allSearchInput = document.getElementById('allCategoriesSearch');
    const activeSearchInput = document.getElementById('activeCategoriesSearch');

    // Add Garment Form Elements
    const form = document.getElementById('pageAddCategoryForm');
    const catInput = document.getElementById('pageCatInput');
    const subcatInput = document.getElementById('pageSubcatInput');
    const subsubcatInput = document.getElementById('pageSubSubcatInput');
    const saveBtn = document.getElementById('pageSaveBtn');
    const formAlert = document.getElementById('pageFormAlert');
    const addGarmentCard = document.getElementById('addGarmentCard');
    const btnToggleAddCard = document.getElementById('btnToggleAddCard');
    const btnCloseAddCard = document.getElementById('btnCloseAddCard');
    const toggleAddText = document.getElementById('toggleAddText');

    // Stats
    const statsCat = document.getElementById('statsCatCount');
    const statsSub = document.getElementById('statsSubcatCount');
    const statsActiveCat = document.getElementById('statsActiveCatCount');
    const statsItem = document.getElementById('statsItemCount');

    // Toggle Add Card visibility
    if (btnToggleAddCard && addGarmentCard) {
        btnToggleAddCard.addEventListener('click', () => {
            const isHidden = window.getComputedStyle(addGarmentCard).display === 'none';
            addGarmentCard.style.display = isHidden ? 'block' : 'none';
            if (toggleAddText) {
                toggleAddText.innerText = isHidden ? 'Hide Add Form' : 'Add New Category / Garment';
            }
            if (isHidden && catInput) {
                catInput.focus();
                addGarmentCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    }
    if (btnCloseAddCard && addGarmentCard) {
        btnCloseAddCard.addEventListener('click', () => {
            addGarmentCard.style.display = 'none';
            if (toggleAddText) toggleAddText.innerText = 'Add New Category / Garment';
        });
    }

    /**
     * Builds structured catalog directory from flat rows
     */
    function buildDirectoryData(rows) {
        const directory = {};

        rows.forEach(r => {
            const cat = r.category_name;
            const sub = r.subcategory_name;
            const subsub = (r.sub_subcategory_name || '').trim();
            const status = r.status || 'Active';
            const rowId = parseInt(r.id, 10);

            if (!directory[cat]) {
                directory[cat] = {
                    name: cat,
                    subcategories: {},
                    totalGarments: 0,
                    isActive: false
                };
            }

            const stTime = parseInt(r.stitch_time_seconds, 10) || 0;

            if (!directory[cat].subcategories[sub]) {
                directory[cat].subcategories[sub] = {
                    name: sub,
                    status: status,
                    stitch_time_seconds: stTime,
                    items: [],
                    rowId: rowId // used if direct garment
                };
            } else if (stTime > 0) {
                directory[cat].subcategories[sub].stitch_time_seconds = stTime;
            }

            // If subcategory has active status
            if (status === 'Active') {
                directory[cat].subcategories[sub].status = 'Active';
                directory[cat].isActive = true;
            }

            if (subsub !== '') {
                directory[cat].subcategories[sub].items.push({
                    id: rowId,
                    name: subsub,
                    status: status,
                    stitch_time_seconds: stTime
                });
                directory[cat].totalGarments++;
            }
        });

        return directory;
    }

    /**
     * Render Left Side: ALL Categories (Master Directory)
     */
    function renderAllCategories(directory, filter = '') {
        const catKeys = Object.keys(directory);
        const cleanFilter = filter.toLowerCase().trim();

        if (catKeys.length === 0) {
            allContainer.innerHTML = `
                <div style="text-align: center; padding: 3rem 1.5rem; color: var(--text-muted);">
                    <i class="fa-solid fa-inbox" style="font-size: 2.5rem; opacity: 0.4; margin-bottom: 0.75rem; display: block;"></i>
                    <h4 style="color: var(--text-primary); margin-bottom: 0.35rem;">No Categories in Master Directory</h4>
                    <p style="font-size: 0.85rem;">Use the form above to register your first category.</p>
                </div>
            `;
            return;
        }

        let html = '';
        let matchCount = 0;

        catKeys.forEach(catName => {
            const catObj = directory[catName];
            const subKeys = Object.keys(catObj.subcategories);

            // Filtering check
            const catMatches = catName.toLowerCase().includes(cleanFilter);
            const matchingSubKeys = subKeys.filter(subName => {
                const subObj = catObj.subcategories[subName];
                const itemMatches = subObj.items.some(it => it.name.toLowerCase().includes(cleanFilter));
                return catMatches || subName.toLowerCase().includes(cleanFilter) || itemMatches;
            });

            if (matchingSubKeys.length === 0 && !catMatches) return;
            matchCount++;

            const isCatActive = catObj.isActive;

            html += `
                <div class="category-block" style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 1.15rem; transition: all 0.2s;">
                    <!-- Category Header -->
                    <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid rgba(255,255,255,0.07); padding-bottom: 0.65rem; margin-bottom: 0.85rem; flex-wrap: wrap; gap: 0.5rem;">
                        <div style="display: flex; align-items: center; gap: 0.65rem;">
                            <div style="width: 34px; height: 34px; border-radius: var(--radius-sm); background: rgba(6,182,212,0.15); color: var(--accent-cyan); display: flex; align-items: center; justify-content: center; font-size: 1rem;">
                                <i class="fa-solid fa-folder"></i>
                            </div>
                            <div>
                                <div style="display: flex; align-items: center; gap: 0.5rem;">
                                    <h4 style="margin: 0; font-size: 1.05rem; font-weight: 700; color: var(--text-primary);">${escapeHtml(catName)}</h4>
                                    ${isCatActive 
                                        ? `<span class="badge" style="background: rgba(16,185,129,0.15); color: #10b981; border: 1px solid rgba(16,185,129,0.3); font-size: 0.72rem; padding: 0.15rem 0.5rem; border-radius: 50px;"><i class="fa-solid fa-check"></i> Active</span>`
                                        : `<span class="badge" style="background: rgba(245,158,11,0.15); color: #f59e0b; border: 1px solid rgba(245,158,11,0.3); font-size: 0.72rem; padding: 0.15rem 0.5rem; border-radius: 50px;"><i class="fa-solid fa-ban"></i> Disabled</span>`
                                    }
                                </div>
                                <span class="text-muted" style="font-size: 0.75rem;">Main Category • ${subKeys.length} Subcategor${subKeys.length === 1 ? 'y' : 'ies'}</span>
                            </div>
                        </div>

                        <!-- Category Master Actions -->
                        <div style="display: flex; align-items: center; gap: 0.45rem;">
                            <button type="button" class="btn btn-outline btn-xs btn-toggle-cat" data-type="category" data-cat="${escapeHtml(catName)}" data-status="${isCatActive ? 'Disabled' : 'Active'}" style="font-size: 0.75rem; padding: 0.25rem 0.55rem; color: ${isCatActive ? 'var(--accent-amber)' : 'var(--accent-emerald)'};">
                                <i class="fa-solid fa-${isCatActive ? 'ban' : 'circle-check'}"></i> ${isCatActive ? 'Disable All' : 'Enable All'}
                            </button>
                            <button type="button" class="btn btn-outline btn-xs text-rose btn-delete-cat" data-type="category" data-cat="${escapeHtml(catName)}" title="Delete entire category" style="font-size: 0.75rem; padding: 0.25rem 0.55rem;">
                                <i class="fa-solid fa-trash-can"></i> Delete
                            </button>
                        </div>
                    </div>

                    <!-- Subcategories List -->
                    <div style="display: flex; flex-direction: column; gap: 0.65rem; padding-left: 0.35rem;">
            `;

            const subkeysToRender = cleanFilter ? matchingSubKeys : subKeys;

            subkeysToRender.forEach(subName => {
                const subObj = catObj.subcategories[subName];
                const isSubActive = subObj.status === 'Active';
                const items = subObj.items;

                html += `
                    <div style="background: rgba(255,255,255,0.02); border-left: 3px solid ${isSubActive ? 'var(--accent-purple, #8b5cf6)' : '#64748b'}; border-radius: var(--radius-sm); padding: 0.65rem 0.85rem;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.4rem; flex-wrap: wrap; gap: 0.4rem;">
                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                <span style="font-weight: 700; font-size: 0.88rem; color: ${isSubActive ? '#c4b5fd' : '#94a3b8'}; display: flex; align-items: center; gap: 0.4rem;">
                                    <i class="fa-solid fa-folder-tree" style="font-size: 0.8rem;"></i> ${escapeHtml(subName)}
                                </span>
                                ${isSubActive
                                    ? `<span style="font-size: 0.68rem; padding: 0.1rem 0.45rem; border-radius: 4px; background: rgba(16,185,129,0.12); color: #10b981; border: 1px solid rgba(16,185,129,0.25);">Active</span>`
                                    : `<span style="font-size: 0.68rem; padding: 0.1rem 0.45rem; border-radius: 4px; background: rgba(245,158,11,0.12); color: #f59e0b; border: 1px solid rgba(245,158,11,0.25);">Disabled</span>`
                                }
                                <span class="badge-pill" style="font-size: 0.68rem; padding: 0.1rem 0.45rem; border-radius: 4px; background: rgba(245,158,11,0.12); color: #fbbf24; border: 1px solid rgba(245,158,11,0.3); font-weight: 700;" title="Workstation Stitching Waiting Time (Seconds)">
                                    <i class="fa-solid fa-stopwatch"></i> ${subObj.stitch_time_seconds > 0 ? (subObj.stitch_time_seconds + 's') : '0s'}
                                </span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 0.35rem;">
                                <button type="button" class="btn btn-outline btn-xs btn-toggle-cat" data-type="subcategory" data-cat="${escapeHtml(catName)}" data-sub="${escapeHtml(subName)}" data-status="${isSubActive ? 'Disabled' : 'Active'}" style="font-size: 0.7rem; padding: 0.15rem 0.45rem; color: ${isSubActive ? 'var(--accent-amber)' : 'var(--accent-emerald)'};">
                                    <i class="fa-solid fa-${isSubActive ? 'pause' : 'play'}"></i> ${isSubActive ? 'Disable' : 'Enable'}
                                </button>
                                <button type="button" class="btn btn-outline btn-xs text-rose btn-delete-cat" data-type="subcategory" data-cat="${escapeHtml(catName)}" data-sub="${escapeHtml(subName)}" title="Delete subcategory" style="font-size: 0.7rem; padding: 0.15rem 0.45rem;">
                                    <i class="fa-solid fa-trash-can"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Garments Badges / Direct Garment Indicator -->
                        <div style="display: flex; flex-wrap: wrap; gap: 0.35rem; align-items: center;">
                `;

                if (items.length === 0) {
                    html += `
                        <span style="display: inline-flex; align-items: center; gap: 0.35rem; background: rgba(148,163,184,0.08); border: 1px dashed rgba(148,163,184,0.3); border-radius: 50px; padding: 0.2rem 0.6rem; font-size: 0.76rem; color: #94a3b8;">
                            <i class="fa-solid fa-shirt" style="font-size: 0.68rem;"></i> Direct Garment (No sub-subcategories)
                        </span>
                    `;
                } else {
                    items.forEach(item => {
                        const itemActive = item.status === 'Active';
                        html += `
                            <span style="display: inline-flex; align-items: center; gap: 0.4rem; background: rgba(6,182,212,0.08); border: 1px solid rgba(6,182,212,0.25); border-radius: 50px; padding: 0.2rem 0.65rem; font-size: 0.78rem; color: var(--text-primary);">
                                <i class="fa-solid fa-tag text-cyan" style="font-size: 0.68rem;"></i>
                                <span>${escapeHtml(item.name)}</span>
                                <button type="button" class="btn-delete-cat text-rose" data-type="single" data-id="${item.id}" title="Remove garment" style="background: none; border: none; cursor: pointer; padding: 0 0.15rem; font-size: 0.75rem;">
                                    <i class="fa-solid fa-xmark"></i>
                                </button>
                            </span>
                        `;
                    });
                }

                html += `
                        </div>
                    </div>
                `;
            });

            html += `
                    </div>
                </div>
            `;
        });

        if (matchCount === 0 && cleanFilter) {
            allContainer.innerHTML = `
                <div style="text-align: center; padding: 2.5rem; color: var(--text-muted);">
                    <i class="fa-solid fa-magnifying-glass" style="font-size: 2rem; opacity: 0.4; margin-bottom: 0.5rem; display: block;"></i>
                    No categories match "<strong>${escapeHtml(cleanFilter)}</strong>" in master directory.
                </div>
            `;
        } else {
            allContainer.innerHTML = html;
        }
    }

    /**
     * Render Right Side: ACTIVE Categories Hierarchy (Workstation View)
     */
    function renderActiveCategories(directory, filter = '') {
        const catKeys = Object.keys(directory);
        const cleanFilter = filter.toLowerCase().trim();

        // Filter ONLY active categories
        const activeCatKeys = catKeys.filter(catName => directory[catName].isActive);

        if (activeCatKeys.length === 0) {
            activeContainer.innerHTML = `
                <div style="text-align: center; padding: 3rem 1.5rem; color: var(--text-muted); border: 1px dashed rgba(16,185,129,0.3); border-radius: var(--radius-md);">
                    <i class="fa-solid fa-circle-exclamation text-amber" style="font-size: 2.5rem; opacity: 0.7; margin-bottom: 0.75rem; display: block;"></i>
                    <h4 style="color: var(--text-primary); margin-bottom: 0.35rem;">No Active Categories</h4>
                    <p style="font-size: 0.85rem;">All categories are currently disabled. Click <strong>"Enable All"</strong> on the left side to activate categories for workstations.</p>
                </div>
            `;
            return;
        }

        let html = '';
        let matchCount = 0;

        activeCatKeys.forEach(catName => {
            const catObj = directory[catName];
            const subKeys = Object.keys(catObj.subcategories);

            // Filter ONLY active subcategories
            const activeSubKeys = subKeys.filter(subName => catObj.subcategories[subName].status === 'Active');
            if (activeSubKeys.length === 0) return;

            // Search filter check
            const catMatches = catName.toLowerCase().includes(cleanFilter);
            const matchingSubKeys = activeSubKeys.filter(subName => {
                const subObj = catObj.subcategories[subName];
                const itemMatches = subObj.items.some(it => it.name.toLowerCase().includes(cleanFilter));
                return catMatches || subName.toLowerCase().includes(cleanFilter) || itemMatches;
            });

            if (matchingSubKeys.length === 0 && !catMatches) return;
            matchCount++;

            html += `
                <div class="category-block" style="background: rgba(16,185,129,0.03); border: 1px solid rgba(16,185,129,0.3); border-radius: var(--radius-md); padding: 1.15rem; transition: all 0.2s;">
                    <!-- Category Header -->
                    <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid rgba(16,185,129,0.15); padding-bottom: 0.65rem; margin-bottom: 0.85rem;">
                        <div style="display: flex; align-items: center; gap: 0.65rem;">
                            <div style="width: 34px; height: 34px; border-radius: var(--radius-sm); background: rgba(16,185,129,0.15); color: #10b981; display: flex; align-items: center; justify-content: center; font-size: 1rem;">
                                <i class="fa-solid fa-folder-tree"></i>
                            </div>
                            <div>
                                <div style="display: flex; align-items: center; gap: 0.5rem;">
                                    <h4 style="margin: 0; font-size: 1.05rem; font-weight: 700; color: var(--text-primary);">${escapeHtml(catName)}</h4>
                                    <span class="badge" style="background: rgba(16,185,129,0.18); color: #10b981; border: 1px solid rgba(16,185,129,0.4); font-size: 0.72rem; padding: 0.15rem 0.5rem; border-radius: 50px;">
                                        <i class="fa-solid fa-circle text-emerald" style="font-size: 0.5rem; vertical-align: middle;"></i> Live Active
                                    </span>
                                </div>
                                <span class="text-muted" style="font-size: 0.75rem;">Main Category • Workstation Ready</span>
                            </div>
                        </div>

                        <button type="button" class="btn btn-outline btn-xs text-amber btn-toggle-cat" data-type="category" data-cat="${escapeHtml(catName)}" data-status="Disabled" title="Deactivate this category from workstations" style="font-size: 0.75rem; padding: 0.25rem 0.55rem;">
                            <i class="fa-solid fa-pause"></i> Deactivate
                        </button>
                    </div>

                    <!-- Subcategories Group -->
                    <div style="display: flex; flex-direction: column; gap: 0.75rem; padding-left: 0.35rem;">
            `;

            const subkeysToRender = cleanFilter ? matchingSubKeys : activeSubKeys;

            subkeysToRender.forEach(subName => {
                const subObj = catObj.subcategories[subName];
                const items = subObj.items;

                html += `
                    <div style="background: rgba(255,255,255,0.02); border-left: 3px solid var(--accent-emerald, #10b981); border-radius: var(--radius-sm); padding: 0.75rem 0.9rem;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                            <span style="font-weight: 700; font-size: 0.9rem; color: #a7f3d0; display: flex; align-items: center; gap: 0.45rem;">
                                <i class="fa-solid fa-tags" style="font-size: 0.8rem; color: #10b981;"></i> ${escapeHtml(subName)}
                                <span class="badge-pill" style="font-size: 0.68rem; padding: 0.1rem 0.45rem; border-radius: 4px; background: rgba(245,158,11,0.15); color: #fbbf24; border: 1px solid rgba(245,158,11,0.35); font-weight: 700;" title="Workstation Stitching Waiting Time (Seconds)">
                                    <i class="fa-solid fa-stopwatch"></i> ${subObj.stitch_time_seconds > 0 ? (subObj.stitch_time_seconds + 's') : '0s'}
                                </span>
                            </span>
                            <button type="button" class="btn btn-outline btn-xs text-amber btn-toggle-cat" data-type="subcategory" data-cat="${escapeHtml(catName)}" data-sub="${escapeHtml(subName)}" data-status="Disabled" title="Deactivate subcategory" style="font-size: 0.7rem; padding: 0.15rem 0.45rem;">
                                <i class="fa-solid fa-eye-slash"></i> Disable
                            </button>
                        </div>

                        <!-- Garments -->
                        <div style="display: flex; flex-wrap: wrap; gap: 0.45rem; align-items: center;">
                `;

                if (items.length === 0) {
                    // Direct Garment (no sub-subcategories)
                    html += `
                        <div style="display: inline-flex; align-items: center; gap: 0.45rem; background: rgba(16,185,129,0.12); border: 1px solid rgba(16,185,129,0.3); border-radius: 50px; padding: 0.3rem 0.8rem; font-size: 0.8rem; color: #10b981;">
                            <i class="fa-solid fa-circle-check"></i>
                            <strong>Direct Garment (Active)</strong>
                            <button type="button" class="btn-delete-cat text-rose" data-type="subcategory" data-cat="${escapeHtml(catName)}" data-sub="${escapeHtml(subName)}" title="Remove subcategory" style="background: none; border: none; cursor: pointer; padding: 0 0.15rem; font-size: 0.8rem; display: flex; align-items: center;">
                                <i class="fa-solid fa-xmark"></i>
                            </button>
                        </div>
                    `;
                } else {
                    items.forEach(item => {
                        html += `
                            <div style="display: inline-flex; align-items: center; gap: 0.45rem; background: rgba(6,182,212,0.1); border: 1px solid rgba(6,182,212,0.3); border-radius: 50px; padding: 0.3rem 0.75rem; font-size: 0.82rem; color: var(--text-primary);">
                                <i class="fa-solid fa-tag text-cyan" style="font-size: 0.72rem;"></i>
                                <strong>${escapeHtml(item.name)}</strong>
                                <button type="button" class="btn-delete-cat text-rose" data-type="single" data-id="${item.id}" title="Remove ${escapeHtml(item.name)}" style="background: none; border: none; cursor: pointer; padding: 0 0.15rem; font-size: 0.8rem; display: flex; align-items: center;">
                                    <i class="fa-solid fa-xmark"></i>
                                </button>
                            </div>
                        `;
                    });
                }

                html += `
                        </div>
                    </div>
                `;
            });

            html += `
                    </div>
                </div>
            `;
        });

        if (matchCount === 0 && cleanFilter) {
            activeContainer.innerHTML = `
                <div style="text-align: center; padding: 2.5rem; color: var(--text-muted);">
                    <i class="fa-solid fa-magnifying-glass" style="font-size: 2rem; opacity: 0.4; margin-bottom: 0.5rem; display: block;"></i>
                    No active garments match "<strong>${escapeHtml(cleanFilter)}</strong>".
                </div>
            `;
        } else {
            activeContainer.innerHTML = html;
        }
    }

    /**
     * Update stats cards
     */
    function updateStats(directory) {
        let totalCats = Object.keys(directory).length;
        let subcatSet = new Set();
        let activeCatsCount = 0;
        let totalItems = 0;

        Object.keys(directory).forEach(catName => {
            const catObj = directory[catName];
            if (catObj.isActive) activeCatsCount++;
            Object.keys(catObj.subcategories).forEach(subName => {
                subcatSet.add(subName);
                const itemsCount = catObj.subcategories[subName].items.length;
                totalItems += (itemsCount > 0) ? itemsCount : 1;
            });
        });

        if (statsCat) statsCat.innerText = totalCats;
        if (statsSub) statsSub.innerText = subcatSet.size;
        if (statsActiveCat) statsActiveCat.innerText = activeCatsCount;
        if (statsItem) statsItem.innerText = totalItems;
    }

    /**
     * Update datalists for autocomplete
     */
    function updateDatalists(directory) {
        const catDatalist = document.getElementById('catSuggestions');
        const subDatalist = document.getElementById('subcatSuggestions');
        if (catDatalist) {
            catDatalist.innerHTML = Object.keys(directory).map(c => `<option value="${escapeHtml(c)}">`).join('');
        }
        if (subDatalist) {
            const subs = new Set();
            Object.keys(directory).forEach(c => Object.keys(directory[c].subcategories).forEach(s => subs.add(s)));
            subDatalist.innerHTML = Array.from(subs).map(s => `<option value="${escapeHtml(s)}">`).join('');
        }
    }

    /**
     * Re-render both sides and attach action handlers
     */
    function refreshViews() {
        const directory = buildDirectoryData(currentRows);
        renderAllCategories(directory, allSearchInput ? allSearchInput.value : '');
        renderActiveCategories(directory, activeSearchInput ? activeSearchInput.value : '');
        updateStats(directory);
        updateDatalists(directory);
        attachActionHandlers();
    }

    /**
     * Attach click handlers for Toggle Status and Delete
     */
    function attachActionHandlers() {
        // Toggle Active / Disabled buttons (Category or Subcategory)
        document.querySelectorAll('.btn-toggle-cat').forEach(btn => {
            btn.onclick = async () => {
                const type = btn.dataset.type;
                const cat = btn.dataset.cat || '';
                const sub = btn.dataset.sub || '';
                const desiredStatus = btn.dataset.status || 'Active';

                btn.disabled = true;
                const origHtml = btn.innerHTML;
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';

                try {
                    const res = await fetch('api.php?action=toggle_category_status', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            category_name: cat,
                            subcategory_name: sub,
                            status: desiredStatus
                        })
                    });
                    const data = await res.json();
                    if (data.success) {
                        currentRows = data.rows;
                        refreshViews();
                        if (typeof showToast === 'function') showToast(data.message, 'success');
                    } else {
                        alert(data.message || 'Error updating status');
                        btn.disabled = false;
                        btn.innerHTML = origHtml;
                    }
                } catch (e) {
                    console.error(e);
                    alert('Network error while toggling status.');
                    btn.disabled = false;
                    btn.innerHTML = origHtml;
                }
            };
        });

        // Delete buttons
        document.querySelectorAll('.btn-delete-cat').forEach(btn => {
            btn.onclick = async () => {
                const type = btn.dataset.type;
                const id = btn.dataset.id || 0;
                const cat = btn.dataset.cat || '';
                const sub = btn.dataset.sub || '';

                let promptMsg = 'Are you sure you want to delete this garment?';
                if (type === 'category') promptMsg = `Are you sure you want to delete Category "${cat}" and ALL its subcategories?`;
                if (type === 'subcategory') promptMsg = `Are you sure you want to delete Subcategory "${sub}"?`;

                if (!confirm(promptMsg)) return;

                btn.disabled = true;
                try {
                    const res = await fetch('api.php?action=delete_category', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ type: type, id: id, category_name: cat, subcategory_name: sub })
                    });
                    const data = await res.json();
                    if (data.success) {
                        currentRows = data.rows;
                        refreshViews();
                        if (typeof showToast === 'function') showToast(data.message, 'success');
                    } else {
                        alert(data.message || 'Error deleting category');
                        btn.disabled = false;
                    }
                } catch (e) {
                    console.error(e);
                    alert('Network error while deleting.');
                    btn.disabled = false;
                }
            };
        });
    }

    // Search input listeners
    if (allSearchInput) {
        allSearchInput.addEventListener('input', () => {
            const dir = buildDirectoryData(currentRows);
            renderAllCategories(dir, allSearchInput.value);
            attachActionHandlers();
        });
    }
    if (activeSearchInput) {
        activeSearchInput.addEventListener('input', () => {
            const dir = buildDirectoryData(currentRows);
            renderActiveCategories(dir, activeSearchInput.value);
            attachActionHandlers();
        });
    }

    // Add category form submission
    if (form) {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const cat = catInput.value.trim();
            const sub = subcatInput.value.trim();
            const subsub = subsubcatInput.value.trim();
            const stitchTime = parseInt(document.getElementById('pageStitchTimeInput')?.value) || 0;

            if (!cat || !sub) return;

            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';

            try {
                const res = await fetch('api.php?action=add_category', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        category_name: cat,
                        subcategory_name: sub,
                        sub_subcategory_name: subsub,
                        stitch_time_seconds: stitchTime
                    })
                });
                const data = await res.json();

                if (data.success) {
                    formAlert.className = 'alert-box alert-success';
                    formAlert.style.display = 'block';
                    formAlert.innerHTML = '<i class="fa-solid fa-circle-check"></i> ' + escapeHtml(data.message);
                    setTimeout(() => { formAlert.style.display = 'none'; }, 3500);

                    if (form) form.reset();
                    catInput.value = '';
                    subcatInput.value = '';
                    subsubcatInput.value = '';
                    catInput.focus();

                    currentRows = data.rows;
                    refreshViews();
                    if (typeof showToast === 'function') showToast(data.message, 'success');
                } else {
                    formAlert.className = 'alert-box alert-danger';
                    formAlert.style.display = 'block';
                    formAlert.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> ' + escapeHtml(data.message);
                }
            } catch (err) {
                console.error(err);
                formAlert.className = 'alert-box alert-danger';
                formAlert.style.display = 'block';
                formAlert.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> Connection error.';
            } finally {
                saveBtn.disabled = false;
                saveBtn.innerHTML = '<i class="fa-solid fa-plus"></i> Save to System';
            }
        });
    }

    // Admin Cooldown Setting Handlers in Categories Page
    const catCooldownInput = document.getElementById('catCooldownInput');
    const catCurrentCooldownVal = document.getElementById('catCurrentCooldownVal');
    const catSaveCooldownBtn = document.getElementById('catSaveCooldownBtn');
    const catDecCooldownBtn = document.getElementById('catDecCooldownBtn');
    const catIncCooldownBtn = document.getElementById('catIncCooldownBtn');

    if (catDecCooldownBtn && catCooldownInput) {
        catDecCooldownBtn.addEventListener('click', () => {
            let val = parseInt(catCooldownInput.value) || 0;
            catCooldownInput.value = Math.max(0, val - 5);
        });
    }

    if (catIncCooldownBtn && catCooldownInput) {
        catIncCooldownBtn.addEventListener('click', () => {
            let val = parseInt(catCooldownInput.value) || 0;
            catCooldownInput.value = Math.min(600, val + 5);
        });
    }

    document.querySelectorAll('.cat-cooldown-preset').forEach(btn => {
        btn.addEventListener('click', () => {
            const sec = parseInt(btn.dataset.sec) || 0;
            if (catCooldownInput) catCooldownInput.value = sec;
            if (catSaveCooldownBtn) catSaveCooldownBtn.click();
        });
    });

    if (catSaveCooldownBtn && catCooldownInput) {
        catSaveCooldownBtn.addEventListener('click', async () => {
            const sec = Math.max(0, parseInt(catCooldownInput.value) || 0);
            catSaveCooldownBtn.disabled = true;
            catSaveCooldownBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';

            try {
                const res = await fetch('api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'update_cooldown_settings',
                        cooldown_seconds: sec
                    })
                });
                const data = await res.json();
                if (data.success) {
                    if (catCurrentCooldownVal) catCurrentCooldownVal.innerText = sec + 's';
                    if (typeof showToast === 'function') {
                        showToast(`✔ Workstation waiting time updated to ${sec}s!`, 'success');
                    }
                } else {
                    if (typeof showToast === 'function') {
                        showToast(data.message || 'Failed to update waiting time.', 'error');
                    }
                }
            } catch (err) {
                console.error(err);
                if (typeof showToast === 'function') {
                    showToast('Network error while saving waiting time.', 'error');
                }
            } finally {
                catSaveCooldownBtn.disabled = false;
                catSaveCooldownBtn.innerHTML = '<i class="fa-solid fa-check"></i> Save Time';
            }
        });
    }

    // Initial render
    refreshViews();
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
