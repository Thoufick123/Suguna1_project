<?php
// includes/header.php
require_once __DIR__ . '/auth.php';
requireAuth();

$currentUser = getCurrentUser();
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
if ($currentPage === 'index') $currentPage = 'register';


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?? 'Worker Management & Production Leaderboard' ?></title>
    <meta name="description" content="Production worker registration, piece count tracking, material leads and real-time performance leaderboard.">
    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%232563eb'%3E%3Cpath d='M12 2L2 7l10 5 10-5-10-5zm0 9l2.5-1.25L12 8.5l-2.5 1.25L12 11zm0 2.5l-5-2.5-5 2.5 10 5 10-5-5-2.5-5 2.5z'/%3E%3C/svg%3E">
    <!-- Google Fonts: Inter & Plus Jakarta Sans -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Main Style -->
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
</head>
<body class="theme-dark">
    <!-- Background Glow Elements -->
    <div class="bg-glow bg-glow-1"></div>
    <div class="bg-glow bg-glow-2"></div>

    <div class="app-wrapper">
        <!-- Top Navigation Bar -->
        <header class="app-header">
            <div class="nav-container">
                <a href="<?= ($currentUser && in_array($currentUser['role'], ['admin', 'supervisor'])) ? 'dashboard.php' : 'daily_output.php' ?>" class="brand-logo">
                    <div class="brand-icon">
                        <i class="fa-solid fa-industry"></i>
                    </div>
                    <div class="brand-text">
                        <span class="brand-title">FABRIQ<span class="highlight">PRO</span></span>
                        <span class="brand-subtitle">Production & Worker Tracker</span>
                    </div>
                </a>

                <nav class="main-nav">
                    <?php if ($currentUser && ($currentUser['role'] === 'admin' || $currentUser['role'] === 'supervisor')): ?>
                        <a href="dashboard.php" class="nav-link <?= ($currentPage === 'dashboard') ? 'active' : '' ?>">
                            <i class="fa-solid fa-gauge-high text-cyan"></i>
                            <span>Dashboard</span>
                        </a>
                    <?php endif; ?>
                    <?php
                    $navStitchedUrl = 'daily_output.php';
                    if ($currentUser && !empty($currentUser['worker_id'])) {
                        $navStitchedUrl = 'daily_output.php?worker_id=' . (int)$currentUser['worker_id'];
                    }
                    ?>
                    <a href="<?= $navStitchedUrl ?>" class="nav-link <?= ($currentPage === 'daily_output') ? 'active' : '' ?>">
                        <i class="fa-solid fa-shirt"></i>
                        <span><?= ($currentUser['role'] === 'admin' || $currentUser['role'] === 'supervisor') ? "Today's Clothes" : "My Stitched Clothes" ?></span>
                    </a>
                    <?php if ($currentUser['role'] === 'admin' || $currentUser['role'] === 'supervisor'): ?>
                        <a href="arduino.php" class="nav-link <?= ($currentPage === 'arduino') ? 'active' : '' ?>">
                            <i class="fa-solid fa-microchip text-cyan"></i>
                            <span>Arduino</span>
                        </a>
                        <a href="categories.php" class="nav-link <?= ($currentPage === 'categories') ? 'active' : '' ?>">
                            <i class="fa-solid fa-layer-group text-cyan"></i>
                            <span>Categories</span>
                        </a>
                        <a href="unit_workers.php?unit=Unit+1" class="nav-link <?= ($currentPage === 'unit_workers') ? 'active' : '' ?>">
                            <i class="fa-solid fa-building-user"></i>
                            <span>Units</span>
                        </a>
                        <a href="index.php" class="nav-link <?= ($currentPage === 'register') ? 'active' : '' ?>">
                            <i class="fa-solid fa-user-plus text-cyan"></i>
                            <span>Register</span>
                        </a>
                        <a href="user_details.php" class="nav-link <?= ($currentPage === 'user_details') ? 'active' : '' ?>">
                            <i class="fa-solid fa-address-card text-emerald"></i>
                            <span>User Details</span>
                        </a>
                    <?php endif; ?>
                    <a href="leaderboard.php" class="nav-link <?= ($currentPage === 'leaderboard') ? 'active' : '' ?>">
                        <i class="fa-solid fa-trophy"></i>
                        <span>Leaderboard</span>
                        <span class="nav-live-dot" title="Live Tracking"></span>
                    </a>
                </nav>

                <div class="header-actions">
                    <!-- 1. Interactive User Profile Badge & Dropdown -->
                    <div class="user-profile-dropdown-wrapper" id="userProfileNavWrap">
                        <button type="button" class="user-profile-badge user-profile-btn" id="userProfileBtn" title="Account Menu (<?= htmlspecialchars($currentUser['full_name'] ?? $currentUser['username']) ?>)" aria-haspopup="true" aria-expanded="false">
                            <span class="user-avatar-sm-circle"><?= strtoupper(substr($currentUser['username'] ?? 'U', 0, 1)) ?></span>
                            <span class="user-name-text"><strong><?= htmlspecialchars($currentUser['username'] ?? 'User') ?></strong></span>
                            <i class="fa-solid fa-chevron-down nav-caret"></i>
                        </button>

                        <!-- User Profile Dropdown Menu -->
                        <div class="user-dropdown-menu" id="userProfileDropdownMenu">
                            <!-- User Card Header -->
                            <div class="user-dropdown-header">
                                <div class="user-dropdown-avatar">
                                    <?= strtoupper(substr($currentUser['full_name'] ?? $currentUser['username'] ?? 'U', 0, 1)) ?>
                                </div>
                                <div class="user-dropdown-info">
                                    <div class="user-dropdown-name"><?= htmlspecialchars($currentUser['full_name'] ?? $currentUser['username']) ?></div>
                                    <div class="user-dropdown-sub">
                                        <span class="user-dropdown-uid">@<?= htmlspecialchars($currentUser['username']) ?></span>
                                        <span class="user-dropdown-role-badge role-<?= strtolower($currentUser['role'] ?? 'operator') ?>">
                                            <?= htmlspecialchars(ucfirst($currentUser['role'] ?? 'Operator')) ?>
                                        </span>
                                    </div>
                                    <?php if (!empty($currentUser['unit']) && $currentUser['unit'] !== 'All'): ?>
                                        <div class="user-dropdown-unit-tag">
                                            <i class="fa-solid fa-building-user text-cyan"></i> Assigned: <?= htmlspecialchars($currentUser['unit']) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="user-dropdown-divider"></div>

                            <!-- Links -->
                            <div class="user-dropdown-links">
                                <?php if (!empty($currentUser['worker_id'])): ?>
                                    <a href="daily_output.php?worker_id=<?= (int)$currentUser['worker_id'] ?>" class="user-dropdown-item">
                                        <i class="fa-solid fa-shirt text-cyan"></i>
                                        <span>My Stitched Clothes</span>
                                    </a>
                                <?php endif; ?>
                                <?php if ($currentUser && ($currentUser['role'] === 'admin' || $currentUser['role'] === 'supervisor')): ?>
                                    <a href="categories.php" class="user-dropdown-item">
                                        <i class="fa-solid fa-layer-group text-cyan"></i>
                                        <span>Garment Categories</span>
                                    </a>
                                <?php endif; ?>
                                <a href="user_details.php" class="user-dropdown-item">
                                    <i class="fa-solid fa-address-card text-emerald"></i>
                                    <span>User Directory &amp; Accounts</span>
                                </a>
                                <a href="leaderboard.php" class="user-dropdown-item">
                                    <i class="fa-solid fa-trophy text-amber"></i>
                                    <span>Production Leaderboard</span>
                                </a>
                            </div>

                            <div class="user-dropdown-divider"></div>

                            <!-- Logout Button inside Dropdown -->
                            <a href="logout.php" class="user-dropdown-logout-btn" onclick="openLogoutModal(event)">
                                <i class="fa-solid fa-right-from-bracket"></i>
                                <div>
                                    <div style="font-weight: 700; line-height: 1.2;">Log Out</div>
                                    <div style="font-size: 0.72rem; opacity: 0.75;">End your active session</div>
                                </div>
                            </a>
                        </div>
                    </div>

                    <!-- 2. Prominent Standalone Logout Button -->
                    <a href="logout.php" class="header-logout-btn prominent-logout-btn" title="Sign out of account" onclick="openLogoutModal(event)">
                        <i class="fa-solid fa-right-from-bracket"></i>
                        <span>Logout</span>
                    </a>

                    <!-- 3. Mobile Navigation Menu Toggle Button -->
                    <button type="button" class="mobile-menu-toggle-btn" id="mobileMenuToggleBtn" aria-label="Open Navigation Menu" title="Open Navigation Menu">
                        <i class="fa-solid fa-bars"></i>
                    </button>
                </div>
            </div>

            <script>
            document.addEventListener('DOMContentLoaded', function() {
                // User Profile Dropdown Controller
                const pWrap = document.getElementById('userProfileNavWrap');
                const pBtn = document.getElementById('userProfileBtn');
                if (pWrap && pBtn) {
                    pBtn.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        pWrap.classList.toggle('open');
                        const isExpanded = pWrap.classList.contains('open');
                        pBtn.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
                    });

                    pWrap.querySelectorAll('.user-dropdown-item').forEach(function(item) {
                        item.addEventListener('click', function() {
                            pWrap.classList.remove('open');
                        });
                    });
                }

                // Mobile Menu Drawer Controller
                const menuBtn = document.getElementById('mobileMenuToggleBtn');
                const drawer = document.getElementById('mobileNavDrawer');
                const drawerBackdrop = document.getElementById('mobileDrawerBackdrop');
                const closeDrawerBtn = document.getElementById('closeMobileDrawerBtn');

                function openMobileDrawer() {
                    if (drawer) drawer.classList.add('open');
                    if (drawerBackdrop) drawerBackdrop.classList.add('open');
                    document.body.style.overflow = 'hidden';
                }

                function closeMobileDrawer() {
                    if (drawer) drawer.classList.remove('open');
                    if (drawerBackdrop) drawerBackdrop.classList.remove('open');
                    document.body.style.overflow = '';
                }

                if (menuBtn) menuBtn.addEventListener('click', openMobileDrawer);
                if (closeDrawerBtn) closeDrawerBtn.addEventListener('click', closeMobileDrawer);
                if (drawerBackdrop) drawerBackdrop.addEventListener('click', closeMobileDrawer);

                // Close menus when clicking outside
                document.addEventListener('click', function(e) {
                    if (pWrap && !pWrap.contains(e.target)) {
                        pWrap.classList.remove('open');
                        if (pBtn) pBtn.setAttribute('aria-expanded', 'false');
                    }
                });

                // ESC key closes any open dropdowns or modal
                document.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape') {
                        if (pWrap) pWrap.classList.remove('open');
                        closeLogoutModal();
                        closeMobileDrawer();
                    }
                });
            });

            // Global Logout Modal Helpers
            function openLogoutModal(e) {
                if (e) {
                    e.preventDefault();
                    e.stopPropagation();
                }
                const pWrap = document.getElementById('userProfileNavWrap');
                if (pWrap) pWrap.classList.remove('open');

                const modal = document.getElementById('logoutConfirmModal');
                if (modal) {
                    modal.classList.add('active');
                } else {
                    window.location.href = 'logout.php';
                }
                return false;
            }

            function closeLogoutModal() {
                const modal = document.getElementById('logoutConfirmModal');
                if (modal) {
                    modal.classList.remove('active');
                }
            }

            document.addEventListener('click', function(e) {
                const modal = document.getElementById('logoutConfirmModal');
                if (modal && e.target === modal) {
                    closeLogoutModal();
                }
            });
            </script>
        </header>

        <!-- Mobile Navigation Drawer Backdrop -->
        <div id="mobileDrawerBackdrop" class="mobile-drawer-backdrop"></div>

        <!-- Mobile Slide-Out Navigation Drawer -->
        <aside id="mobileNavDrawer" class="mobile-nav-drawer" aria-label="Mobile Navigation Menu">
            <div class="drawer-header">
                <div class="drawer-user-card">
                    <div class="drawer-user-avatar">
                        <?= strtoupper(substr($currentUser['full_name'] ?? $currentUser['username'] ?? 'U', 0, 1)) ?>
                    </div>
                    <div class="drawer-user-details">
                        <div class="drawer-user-name"><?= htmlspecialchars($currentUser['full_name'] ?? $currentUser['username']) ?></div>
                        <div class="drawer-user-meta">
                            <span class="user-dropdown-role-badge role-<?= strtolower($currentUser['role'] ?? 'operator') ?>">
                                <?= htmlspecialchars(ucfirst($currentUser['role'] ?? 'Operator')) ?>
                            </span>
                            <?php if (!empty($currentUser['unit']) && $currentUser['unit'] !== 'All'): ?>
                                <span class="drawer-unit-badge"><i class="fa-solid fa-building-user"></i> <?= htmlspecialchars($currentUser['unit']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <button type="button" class="drawer-close-btn" id="closeMobileDrawerBtn" aria-label="Close menu">&times;</button>
            </div>

            <!-- Quick Action for Categories if Supervisor/Admin -->
            <?php if ($currentUser && in_array($currentUser['role'], ['admin', 'supervisor'])): ?>
                <div class="drawer-quick-action">
                    <a href="categories.php" class="btn btn-primary btn-sm drawer-quick-btn">
                        <i class="fa-solid fa-circle-plus"></i>
                        <span>Create / Manage Categories</span>
                    </a>
                </div>
            <?php endif; ?>

            <div class="drawer-links-scroll">
                <div class="drawer-nav-group-title">Navigation</div>
                <nav class="drawer-nav-list">
                    <?php if ($currentUser && in_array($currentUser['role'], ['admin', 'supervisor'])): ?>
                        <a href="dashboard.php" class="drawer-nav-link <?= ($currentPage === 'dashboard') ? 'active' : '' ?>">
                            <i class="fa-solid fa-gauge-high text-cyan"></i>
                            <span>Production Dashboard</span>
                        </a>
                    <?php endif; ?>

                    <a href="<?= $navStitchedUrl ?>" class="drawer-nav-link <?= ($currentPage === 'daily_output') ? 'active' : '' ?>">
                        <i class="fa-solid fa-shirt text-emerald"></i>
                        <span><?= (in_array($currentUser['role'], ['admin', 'supervisor'])) ? "Today's Clothes / Workstation" : "My Stitched Clothes" ?></span>
                    </a>

                    <?php if ($currentUser && in_array($currentUser['role'], ['admin', 'supervisor'])): ?>
                        <a href="categories.php" class="drawer-nav-link <?= ($currentPage === 'categories') ? 'active' : '' ?>" style="border-left: 3px solid var(--accent-cyan, #06b6d4);">
                            <i class="fa-solid fa-layer-group text-cyan"></i>
                            <span style="font-weight: 700;">Garment Categories</span>
                            <span class="badge-pill unit-cyan-pill" style="margin-left: auto; font-size: 0.65rem;">Manage</span>
                        </a>

                        <?php
                        $drawerUnitTarget = (!empty($currentUser['unit']) && $currentUser['unit'] !== 'All') ? urlencode($currentUser['unit']) : 'Unit+1';
                        ?>
                        <a href="unit_workers.php?unit=<?= $drawerUnitTarget ?>" class="drawer-nav-link <?= ($currentPage === 'unit_workers') ? 'active' : '' ?>">
                            <i class="fa-solid fa-building-user text-amber"></i>
                            <span>Production Units (<?= htmlspecialchars($currentUser['unit'] ?? 'Units') ?>)</span>
                        </a>

                        <a href="index.php" class="drawer-nav-link <?= ($currentPage === 'register') ? 'active' : '' ?>">
                            <i class="fa-solid fa-user-plus text-cyan"></i>
                            <span>Register New Worker</span>
                        </a>

                        <a href="user_details.php" class="drawer-nav-link <?= ($currentPage === 'user_details') ? 'active' : '' ?>">
                            <i class="fa-solid fa-address-card text-emerald"></i>
                            <span>User Details &amp; Directory</span>
                        </a>

                        <a href="arduino.php" class="drawer-nav-link <?= ($currentPage === 'arduino') ? 'active' : '' ?>">
                            <i class="fa-solid fa-microchip text-cyan"></i>
                            <span>Arduino IoT Sensor</span>
                        </a>
                    <?php endif; ?>

                    <a href="leaderboard.php" class="drawer-nav-link <?= ($currentPage === 'leaderboard') ? 'active' : '' ?>">
                        <i class="fa-solid fa-trophy text-amber"></i>
                        <span>Live Production Leaderboard</span>
                    </a>
                </nav>

                <div class="drawer-divider"></div>

                <div class="drawer-footer-actions">
                    <a href="logout.php" class="drawer-logout-btn" onclick="openLogoutModal(event)">
                        <i class="fa-solid fa-right-from-bracket"></i>
                        <span>Sign Out of Session</span>
                    </a>
                </div>
            </div>
        </aside>

        <!-- Global Fullscreen Logout Confirmation Modal (Outside header for clean viewport positioning) -->
        <div id="logoutConfirmModal" class="logout-modal-backdrop">
            <div class="logout-modal-dialog">
                <div class="logout-modal-icon">
                    <i class="fa-solid fa-right-from-bracket"></i>
                </div>
                <div class="logout-modal-title">Sign Out of FabriqPro?</div>
                <div class="logout-modal-desc">
                    You are currently signed in as <strong><?= htmlspecialchars($currentUser['full_name'] ?? $currentUser['username']) ?></strong> (<code><?= htmlspecialchars($currentUser['username']) ?></code>).
                    <br>All your completed manufacturing piece records are saved.
                </div>
                <div class="logout-modal-actions">
                    <button type="button" class="btn btn-outline" onclick="closeLogoutModal()">Cancel</button>
                    <a href="logout.php" class="btn btn-danger" id="confirmLogoutSubmitBtn">
                        <i class="fa-solid fa-right-from-bracket"></i> Yes, Log Out
                    </a>
                </div>
            </div>
        </div>

        <!-- Main Content Container -->
        <main class="main-content">
