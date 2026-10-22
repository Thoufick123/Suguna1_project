<?php
/**
 * Database Handler with MySQL PDO (XAMPP Default Configuration)
 * Automatically creates the database, tables, and seeds initial data
 */

class Database {
    private static ?PDO $pdo = null;

    // XAMPP Default MySQL Credentials (supports port 3308 and 3306)
    private static string $host = '127.0.0.1';
    private static string $port = '3308';
    private static string $dbname = 'fabriqpro_db';
    private static string $username = 'root';
    private static string $password = '';

    public static function getConnection(): PDO {
        if (self::$pdo === null) {
            // Load config.php if present
            if (file_exists(__DIR__ . '/config.php')) {
                require_once __DIR__ . '/config.php';
            }

            self::$host = defined('DB_HOST') ? DB_HOST : (getenv('DB_HOST') ?: self::$host);
            self::$port = defined('DB_PORT') ? (string)DB_PORT : (getenv('DB_PORT') ?: self::$port);
            self::$dbname = defined('DB_NAME') ? DB_NAME : (getenv('DB_NAME') ?: self::$dbname);
            self::$username = defined('DB_USER') ? DB_USER : (getenv('DB_USER') ?: self::$username);
            self::$password = defined('DB_PASS') ? DB_PASS : (getenv('DB_PASS') ?: self::$password);

            $portsToTry = [self::$port, '3306', '3308'];
            $portsToTry = array_values(array_unique(array_filter($portsToTry)));
            $connected = false;
            $lastException = null;

            // 1. Direct connection with dbname (standard for Hostinger and shared hosting)
            foreach ($portsToTry as $p) {
                try {
                    self::$pdo = new PDO(
                        "mysql:host=" . self::$host . ";port=" . $p . ";dbname=" . self::$dbname . ";charset=utf8mb4",
                        self::$username,
                        self::$password,
                        [
                            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                            PDO::ATTR_TIMEOUT => 3
                        ]
                    );
                    self::$port = $p;
                    $connected = true;
                    break;
                } catch (PDOException $e) {
                    $lastException = $e;
                }
            }

            // 2. If direct connection fails (e.g., local fresh XAMPP where DB is not yet created)
            if (!$connected) {
                foreach ($portsToTry as $p) {
                    try {
                        $initPdo = new PDO(
                            "mysql:host=" . self::$host . ";port=" . $p . ";charset=utf8mb4",
                            self::$username,
                            self::$password,
                            [
                                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                                PDO::ATTR_TIMEOUT => 2
                            ]
                        );
                        $initPdo->exec("CREATE DATABASE IF NOT EXISTS `" . self::$dbname . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
                        self::$pdo = new PDO(
                            "mysql:host=" . self::$host . ";port=" . $p . ";dbname=" . self::$dbname . ";charset=utf8mb4",
                            self::$username,
                            self::$password,
                            [
                                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                            ]
                        );
                        self::$port = $p;
                        $connected = true;
                        break;
                    } catch (PDOException $e) {
                        $lastException = $e;
                    }
                }
            }

            if (!$connected || !self::$pdo) {
                die(json_encode([
                    'success' => false,
                    'message' => 'Database Connection Error: ' . ($lastException ? $lastException->getMessage() : 'Unable to connect') . '. Please ensure MySQL is running and credentials are correct.'
                ]));
            }

            // Initialize tables and seed initial data if needed
            self::initializeTables();
        }
        return self::$pdo;
    }

    private static function initializeTables(): void {
        // Table: workers
        $sqlWorkers = "
        CREATE TABLE IF NOT EXISTS workers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            unique_id VARCHAR(50) NOT NULL UNIQUE,
            name VARCHAR(150) NOT NULL,
            unit VARCHAR(50) NOT NULL,
            age INT NOT NULL,
            mobile_num VARCHAR(30) NOT NULL,
            alt_mobile_num VARCHAR(30) NULL,
            address TEXT NOT NULL,
            role VARCHAR(150) NOT NULL,
            experience_years DECIMAL(4,1) NOT NULL DEFAULT 0.0,
            extra_skills TEXT NULL,
            total_pieces INT NOT NULL DEFAULT 0,
            status VARCHAR(30) NOT NULL DEFAULT 'Active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_unit (unit),
            INDEX idx_unique_id (unique_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        self::$pdo->exec($sqlWorkers);

        try {
            self::$pdo->exec("ALTER TABLE workers ADD COLUMN origin_unit VARCHAR(50) NULL AFTER unit");
        } catch (Exception $e) {}
        try {
            self::$pdo->exec("ALTER TABLE workers ADD COLUMN transferred_from VARCHAR(50) NULL AFTER origin_unit");
        } catch (Exception $e) {}
        try {
            self::$pdo->exec("ALTER TABLE workers ADD COLUMN transfer_type VARCHAR(50) NULL AFTER transferred_from");
        } catch (Exception $e) {}

        try {
            self::$pdo->exec("ALTER TABLE workers ADD COLUMN last_login TIMESTAMP NULL DEFAULT NULL AFTER status");
        } catch (Exception $e) {}
        try {
            self::$pdo->exec("ALTER TABLE workers ADD COLUMN assigned_work VARCHAR(255) NULL DEFAULT NULL AFTER last_login");
        } catch (Exception $e) {}
        try {
            self::$pdo->exec("ALTER TABLE workers ADD COLUMN assigned_category_id INT NULL DEFAULT NULL AFTER assigned_work");
        } catch (Exception $e) {}
        try {
            self::$pdo->exec("ALTER TABLE workers ADD COLUMN assigned_at TIMESTAMP NULL DEFAULT NULL AFTER assigned_category_id");
        } catch (Exception $e) {}

        // Table: piece_logs
        $sqlLogs = "
        CREATE TABLE IF NOT EXISTS piece_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            worker_id INT NOT NULL,
            unit VARCHAR(50) DEFAULT 'Unit 1',
            material_name VARCHAR(200) NOT NULL,
            piece_count INT NOT NULL,
            work_date DATE NOT NULL,
            shift VARCHAR(50) DEFAULT 'Day Shift',
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_worker_id (worker_id),
            INDEX idx_unit (unit),
            INDEX idx_material (material_name),
            INDEX idx_work_date (work_date),
            FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        self::$pdo->exec($sqlLogs);

        try {
            self::$pdo->exec("ALTER TABLE piece_logs ADD COLUMN unit VARCHAR(50) DEFAULT 'Unit 1' AFTER worker_id");
        } catch (Exception $e) {}
        try {
            self::$pdo->exec("ALTER TABLE piece_logs ADD INDEX idx_unit (unit)");
        } catch (Exception $e) {}

        // Table: users (Authentication & Roles)
        $sqlUsers = "
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            full_name VARCHAR(100) NOT NULL,
            role ENUM('admin', 'supervisor', 'operator') NOT NULL DEFAULT 'admin',
            unit VARCHAR(50) DEFAULT 'All',
            status VARCHAR(20) NOT NULL DEFAULT 'Active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        self::$pdo->exec($sqlUsers);

        try {
            self::$pdo->exec("ALTER TABLE users ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'Active' AFTER unit");
        } catch (Exception $e) {}
        try {
            self::$pdo->exec("ALTER TABLE users ADD COLUMN last_login TIMESTAMP NULL DEFAULT NULL AFTER status");
        } catch (Exception $e) {}

        // Table: worker_work_assignments
        $sqlAssignments = "
        CREATE TABLE IF NOT EXISTS worker_work_assignments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            worker_id INT NOT NULL,
            category_id INT NULL,
            category_name VARCHAR(150) NOT NULL,
            subcategory_name VARCHAR(150) DEFAULT '',
            sub_subcategory_name VARCHAR(150) DEFAULT '',
            assigned_date DATE NOT NULL,
            assigned_by VARCHAR(100) DEFAULT 'admin',
            status VARCHAR(50) DEFAULT 'Active',
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_worker (worker_id),
            INDEX idx_date (assigned_date),
            FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        try {
            self::$pdo->exec($sqlAssignments);
        } catch (Exception $e) {}

        // Table: garment_categories (Admin-managed Category, Subcategory, Sub-Subcategory)
        $sqlCategories = "
        CREATE TABLE IF NOT EXISTS garment_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            category_name VARCHAR(150) NOT NULL,
            subcategory_name VARCHAR(150) NOT NULL,
            sub_subcategory_name VARCHAR(150) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'Active',
            created_by VARCHAR(100) DEFAULT 'admin',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_cat (category_name),
            INDEX idx_subcat (subcategory_name),
            INDEX idx_subsubcat (sub_subcategory_name),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        self::$pdo->exec($sqlCategories);

        try {
            self::$pdo->exec("ALTER TABLE garment_categories ADD COLUMN sub_subcategory_name VARCHAR(150) NOT NULL DEFAULT '' AFTER subcategory_name");
        } catch (Exception $e) {}
        try {
            self::$pdo->exec("ALTER TABLE garment_categories ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'Active' AFTER sub_subcategory_name");
        } catch (Exception $e) {}
        try {
            self::$pdo->exec("ALTER TABLE garment_categories ADD COLUMN stitch_time_seconds INT NOT NULL DEFAULT 0 AFTER status");
        } catch (Exception $e) {}

        // Seed initial garment categories if table is empty
        try {
            $catCount = (int)self::$pdo->query("SELECT COUNT(*) FROM garment_categories")->fetchColumn();
            if ($catCount === 0) {
                $seedCats = [
                    ['Nighty', 'Regular Cotton', 'Daily Wear'],
                    ['Feeding Nighty', 'Front Zip', 'Comfort Stretch'],
                    ['Kurti', 'Cotton Kurti', 'A-Line / Printed'],
                    ['Pajama', 'Casual Pajama', 'Elastic Waist'],
                    ['T-Shirt', 'Round Neck', 'Cotton Blend'],
                    ['Frock', 'Kids Frock', 'Summer Wear'],
                ];
                $insCat = self::$pdo->prepare("INSERT INTO garment_categories (category_name, subcategory_name, sub_subcategory_name, status, created_by) VALUES (?, ?, ?, 'Active', 'system')");
                foreach ($seedCats as $sc) {
                    $insCat->execute($sc);
                }
            }
        } catch (Exception $e) {}

        // Table: worker_roles (Admin-managed Worker Designations / Roles - starts empty until admin adds)
        $sqlRoles = "
        CREATE TABLE IF NOT EXISTS worker_roles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            role_name VARCHAR(150) NOT NULL UNIQUE,
            created_by VARCHAR(100) DEFAULT 'admin',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        self::$pdo->exec($sqlRoles);

        // Table: system_access_roles (Admin-managed System Access Roles)
        $sqlSystemRoles = "
        CREATE TABLE IF NOT EXISTS system_access_roles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            role_name VARCHAR(150) NOT NULL UNIQUE,
            role_key VARCHAR(100) NOT NULL UNIQUE,
            access_tier ENUM('operator', 'supervisor', 'admin') NOT NULL DEFAULT 'operator',
            description VARCHAR(255) DEFAULT '',
            is_system_default TINYINT(1) DEFAULT 0,
            created_by VARCHAR(100) DEFAULT 'admin',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        self::$pdo->exec($sqlSystemRoles);

        try {
            $sysCount = self::$pdo->query("SELECT COUNT(*) FROM system_access_roles")->fetchColumn();
            if ($sysCount == 0) {
                $seedSys = self::$pdo->prepare("INSERT INTO system_access_roles (role_name, role_key, access_tier, description, is_system_default, created_by) VALUES (?, ?, ?, ?, 1, 'system')");
                $seedSys->execute(['Operator (Workstation Output & IoT Only)', 'operator', 'operator', 'Workstation & IoT Only - Default Privileges']);
                $seedSys->execute(['Supervisor (Unit Management & Approvals)', 'supervisor', 'supervisor', 'Unit Management & Approvals']);
                $seedSys->execute(['Administrator (Full Factory Access & Configuration)', 'admin', 'admin', 'Full Factory Access & Configuration']);
            }
        } catch (Exception $e) {}
        $sqlUnits = "
        CREATE TABLE IF NOT EXISTS production_units (
            id INT AUTO_INCREMENT PRIMARY KEY,
            unit_name VARCHAR(50) NOT NULL UNIQUE,
            unit_title VARCHAR(100) NOT NULL,
            unit_code VARCHAR(20) NOT NULL UNIQUE,
            description VARCHAR(255) DEFAULT '',
            color VARCHAR(30) DEFAULT 'cyan',
            icon VARCHAR(50) DEFAULT 'fa-industry',
            status VARCHAR(20) NOT NULL DEFAULT 'Active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        self::$pdo->exec($sqlUnits);

        try {
            self::$pdo->exec("ALTER TABLE production_units ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'Active' AFTER icon");
        } catch (Exception $e) {}

        try {
            self::$pdo->exec("UPDATE production_units SET icon = 'fa-warehouse' WHERE icon = 'fa-building-gear'");
        } catch (Exception $e) {}

        $unitCount = self::$pdo->query("SELECT COUNT(*) FROM production_units")->fetchColumn();
        if ($unitCount == 0) {
            $unitIns = self::$pdo->prepare("
                INSERT IGNORE INTO production_units (unit_name, unit_title, unit_code, description, color, icon)
                VALUES (:name, :title, :code, :desc, :color, :icon)
            ");
            $unitIns->execute([
                ':name' => 'Unit 1',
                ':title' => 'Production Unit 1',
                ':code' => 'PU001',
                ':desc' => 'Apparel, Stitching & Pattern Making',
                ':color' => 'cyan',
                ':icon' => 'fa-industry'
            ]);
            $unitIns->execute([
                ':name' => 'Unit 2',
                ':title' => 'Production Unit 2',
                ':code' => 'PU002',
                ':desc' => 'Heavy Fabrics, Denim & Pressing',
                ':color' => 'pink',
                ':icon' => 'fa-warehouse'
            ]);
        }

        // Table: app_settings (Key-Value Configuration for System / Admin)
        $sqlSettings = "
        CREATE TABLE IF NOT EXISTS app_settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value TEXT NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        self::$pdo->exec($sqlSettings);

        // Seed default stitching cooldown seconds if missing
        $stmtCheck = self::$pdo->query("SELECT COUNT(*) FROM app_settings WHERE setting_key = 'stitching_cooldown_seconds'");
        if ($stmtCheck && $stmtCheck->fetchColumn() == 0) {
            $seedCooldown = self::$pdo->prepare("INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES ('stitching_cooldown_seconds', '30')");
            $seedCooldown->execute();
        }

        // Seed users if empty
        $userCount = self::$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        if ($userCount == 0) {
            self::seedUsers();
        }
    }

    private static function seedUsers(): void {
        $users = [
            [
                'username' => 'admin',
                'password' => password_hash('admin123', PASSWORD_DEFAULT),
                'full_name' => 'System Administrator',
                'role' => 'admin',
                'unit' => 'All'
            ],
            [
                'username' => 'supervisor',
                'password' => password_hash('super123', PASSWORD_DEFAULT),
                'full_name' => 'Production Supervisor',
                'role' => 'supervisor',
                'unit' => 'All'
            ],
            [
                'username' => 'unit1_lead',
                'password' => password_hash('unit123', PASSWORD_DEFAULT),
                'full_name' => 'Unit 1 Shift Manager',
                'role' => 'supervisor',
                'unit' => 'Unit 1'
            ],
            [
                'username' => 'unit2_lead',
                'password' => password_hash('unit123', PASSWORD_DEFAULT),
                'full_name' => 'Unit 2 Shift Manager',
                'role' => 'supervisor',
                'unit' => 'Unit 2'
            ]
        ];

        $stmt = self::$pdo->prepare("
            INSERT INTO users (username, password, full_name, role, unit)
            VALUES (:username, :password, :full_name, :role, :unit)
        ");

        foreach ($users as $u) {
            $stmt->execute($u);
        }
    }



    public static function recalculateWorkerTotals(): void {
        $db = self::getConnection();
        $db->exec("
            UPDATE workers w
            SET w.total_pieces = COALESCE(
                (SELECT SUM(pl.piece_count) FROM piece_logs pl WHERE pl.worker_id = w.id),
                0
            )
        ");
    }

    public static function getSetting(string $key, $default = null) {
        try {
            $db = self::getConnection();
            $stmt = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key = :k LIMIT 1");
            $stmt->execute([':k' => $key]);
            $val = $stmt->fetchColumn();
            return ($val !== false && $val !== null) ? $val : $default;
        } catch (Exception $e) {
            return $default;
        }
    }

    public static function setSetting(string $key, $value): bool {
        try {
            $db = self::getConnection();
            $stmt = $db->prepare("
                INSERT INTO app_settings (setting_key, setting_value) 
                VALUES (:k, :v) 
                ON DUPLICATE KEY UPDATE setting_value = :v2
            ");
            return $stmt->execute([':k' => $key, ':v' => (string)$value, ':v2' => (string)$value]);
        } catch (Exception $e) {
            return false;
        }
    }
}
