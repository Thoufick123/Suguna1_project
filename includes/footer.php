        </main> <!-- /main-content -->

        <!-- Mobile Bottom Navigation Bar -->
        <?php
        $footerUser = getCurrentUser();
        $curPage = basename($_SERVER['PHP_SELF'], '.php');
        if ($curPage === 'index') $curPage = 'register';
        ?>
        <nav class="mobile-bottom-nav">
            <?php if ($footerUser && ($footerUser['role'] === 'admin' || $footerUser['role'] === 'supervisor')): ?>
                <a href="dashboard.php" class="mobile-nav-item <?= ($curPage === 'dashboard') ? 'active' : '' ?>">
                    <i class="fa-solid fa-gauge-high"></i>
                    <span>Dashboard</span>
                </a>
            <?php endif; ?>
            <?php
            $footerStitchedUrl = 'daily_output.php';
            if ($footerUser && !empty($footerUser['worker_id'])) {
                $footerStitchedUrl = 'daily_output.php?worker_id=' . (int)$footerUser['worker_id'];
            }
            ?>
            <a href="<?= $footerStitchedUrl ?>" class="mobile-nav-item <?= ($curPage === 'daily_output') ? 'active' : '' ?>">
                <i class="fa-solid fa-shirt"></i>
                <span><?= ($footerUser && ($footerUser['role'] === 'admin' || $footerUser['role'] === 'supervisor')) ? "Today" : "My Stitches" ?></span>
            </a>
            <?php if ($footerUser && ($footerUser['role'] === 'admin' || $footerUser['role'] === 'supervisor')): ?>
                <a href="unit_workers.php?unit=Unit+1" class="mobile-nav-item <?= ($curPage === 'unit_workers') ? 'active' : '' ?>">
                    <i class="fa-solid fa-building-user"></i>
                    <span>Units</span>
                </a>
                <a href="user_details.php" class="mobile-nav-item <?= ($curPage === 'user_details') ? 'active' : '' ?>">
                    <i class="fa-solid fa-address-card"></i>
                    <span>Users</span>
                </a>
            <?php endif; ?>
            <a href="leaderboard.php" class="mobile-nav-item <?= ($curPage === 'leaderboard') ? 'active' : '' ?>">
                <i class="fa-solid fa-trophy"></i>
                <span>Ranks</span>
            </a>

            <a href="logout.php" class="mobile-nav-item" title="Sign out">
                <i class="fa-solid fa-right-from-bracket"></i>
                <span>Logout</span>
            </a>
        </nav>


    </div> <!-- /app-wrapper -->

    <!-- Worker Profile Modal -->
    <div id="workerModal" class="modal-overlay" aria-hidden="true">
        <div class="modal-card">
            <div class="modal-header">
                <div class="modal-title-group">
                    <div class="worker-avatar-lg" id="modalAvatar">W</div>
                    <div>
                        <h3 id="modalWorkerName">Worker Name</h3>
                        <p class="text-muted" id="modalWorkerSub">PU001-1 • Role</p>
                    </div>
                </div>
                <button class="modal-close-btn" id="closeModalBtn" aria-label="Close modal">&times;</button>
            </div>
            <div class="modal-body" id="modalBody">
                <!-- Dynamically loaded content -->
                <div class="loading-spinner"></div>
            </div>
        </div>
    </div>

    <!-- Toast Notification Container -->
    <div id="toastContainer" class="toast-container"></div>

    <!-- Main JavaScript App -->
    <script src="assets/js/app.js?v=<?= filemtime(__DIR__ . '/../assets/js/app.js') ?>"></script>
</body>
</html>
