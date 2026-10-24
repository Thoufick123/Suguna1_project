<?php
/**
 * API Controller for Workers, Piece Logs, Leads & Leaderboard
 */
if (!headers_sent()) {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
}

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth.php';

// Authentication Guard: Ensure user is logged in
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized. Please sign in to access this resource.'
    ]);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Support JSON input in POST requests
$rawInput = file_get_contents('php://input');
$jsonInput = json_decode($rawInput, true) ?? [];
$requestData = array_merge($_GET, $_POST, $jsonInput);

if (empty($action) && isset($requestData['action'])) {
    $action = $requestData['action'];
}

// Role Guard for Administrative Actions
$adminActions = [
    'add_category', 'toggle_category_status', 'delete_category',
    'add_role', 'delete_role',
    'add_system_role', 'delete_system_role',
    'add_production_unit', 'delete_production_unit', 'toggle_unit_status', 'edit_production_unit',
    'update_user_credentials', 'clear_system_data', 'quick_add_user',
    'toggle_user_status', 'delete_user_worker', 'transfer_user_unit',
    'update_cooldown_settings', 'assign_worker_category', 'toggle_worker_active'
];

if (in_array($action, $adminActions)) {
    $currentUser = getCurrentUser();
    if (!$currentUser || ($currentUser['role'] !== 'admin' && $currentUser['role'] !== 'supervisor')) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Forbidden: Administrative privileges required.'
        ]);
        exit;
    }
}

try {
    $pdo = Database::getConnection();

    $unitsMap = [];
    try {
        $pUnits = $pdo->query("SELECT unit_name, unit_code FROM production_units")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($pUnits as $pu) {
            $unitsMap[strtoupper($pu['unit_code'])] = $pu['unit_name'];
        }
    } catch (Exception $e) {}

    $deriveSwitched = function(&$w) use ($unitsMap) {
        $transFrom = trim($w['transferred_from'] ?? '');
        if (empty($transFrom)) {
            if (preg_match('/^(PU\d{3})/i', $w['unique_id'] ?? '', $m)) {
                $code = strtoupper($m[1]);
                if (isset($unitsMap[$code])) {
                    $orig = $unitsMap[$code];
                    $origNorm = preg_replace('/[^a-z0-9]/', '', strtolower($orig));
                    $currNorm = preg_replace('/[^a-z0-9]/', '', strtolower($w['unit'] ?? ''));
                    if ($origNorm !== $currNorm) {
                        $transFrom = $orig;
                    }
                }
            }
        }
        $w['is_switched'] = !empty($transFrom);
        $w['transferred_from'] = $transFrom;
        $w['transfer_type'] = $w['transfer_type'] ?? ($w['is_switched'] ? 'temporary' : '');
    };

    if (!function_exists('getNextWorkerId')) {
        function getNextWorkerId($pdo, $unit) {
            $prefix = 'PU001';
            $unitName = $unit;
            $unitTitle = $unit;

            $uStmt = $pdo->prepare("SELECT unit_name, unit_title, unit_code FROM production_units WHERE unit_name = :unit OR unit_title = :unit OR unit_code = :unit LIMIT 1");
            $uStmt->execute([':unit' => $unit]);
            $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
            if ($uRow) {
                $unitName = $uRow['unit_name'];
                $unitTitle = $uRow['unit_title'];
                if (!empty($uRow['unit_code'])) {
                    $prefix = strtoupper(trim($uRow['unit_code']));
                }
            } elseif (preg_match('/(\d+)/', $unit, $m)) {
                $prefix = 'PU' . sprintf('%03d', (int)$m[1]);
                $unitName = 'Unit ' . (int)$m[1];
            }

            // 1. Count active workers currently assigned to this unit
            $cStmt = $pdo->prepare("SELECT COUNT(*) FROM workers WHERE unit = :u1 OR unit = :u2");
            $cStmt->execute([':u1' => $unitName, ':u2' => $unitTitle]);
            $currentCount = (int)$cStmt->fetchColumn();

            // Sequential ID corresponding to (current count + 1)-th worker
            $candidateNum = $currentCount + 1;
            if ($candidateNum < 1) {
                $candidateNum = 1;
            }

            // 2. Fetch all existing unique IDs with this prefix from both workers and users
            $stmt = $pdo->prepare("
                SELECT unique_id FROM workers WHERE unique_id LIKE :p1
                UNION
                SELECT username AS unique_id FROM users WHERE username LIKE :p2
            ");
            $stmt->execute([':p1' => $prefix . '%', ':p2' => $prefix . '%']);
            $existingIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $usedNumbers = [];
            foreach ($existingIds as $eid) {
                if (preg_match('/^' . preg_quote($prefix, '/') . '[-_]?(\d+)$/i', trim($eid), $m)) {
                    $usedNumbers[(int)$m[1]] = true;
                }
            }

            while (isset($usedNumbers[$candidateNum])) {
                $candidateNum++;
            }

            return $prefix . '-' . $candidateNum;
        }
    }

    switch ($action) {

        // ==========================================
        // 0. CHECK IF MOBILE NUMBER ALREADY EXISTS
        // ==========================================
        case 'check_mobile':
            $mobile = trim($requestData['mobile'] ?? $requestData['mobile_num'] ?? '');
            if (empty($mobile)) {
                echo json_encode(['exists' => false]);
                exit;
            }
            $cleanDigits = preg_replace('/\D+/', '', $mobile);
            if (!preg_match('/^[6-9]\d{9}$/', $cleanDigits)) {
                echo json_encode([
                    'exists' => false,
                    'invalid' => true,
                    'message' => 'Must be a valid 10-digit mobile number starting with 6, 7, 8, or 9.'
                ]);
                exit;
            }

            $checkStmt = $pdo->prepare("
                SELECT id, name, unique_id, mobile_num, unit 
                FROM workers 
                WHERE mobile_num = :exact_mob
                   OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(mobile_num, ' ', ''), '-', ''), '+', ''), '(', ''), ')', '') LIKE :clean_pattern
                LIMIT 1
            ");
            $checkStmt->execute([
                ':exact_mob' => $cleanDigits,
                ':clean_pattern' => '%' . $cleanDigits
            ]);
            $found = $checkStmt->fetch();

            if ($found) {
                echo json_encode([
                    'exists' => true,
                    'message' => 'This mobile number already exists.',
                    'worker_name' => $found['name'],
                    'unique_id' => $found['unique_id'],
                    'unit' => $found['unit']
                ]);
            } else {
                echo json_encode(['exists' => false]);
            }
            exit;

        // ==========================================
        // 1. REGISTER A NEW WORKER
        // ==========================================
        case 'register_worker':
            $name = trim($requestData['name'] ?? '');
            $unit = trim($requestData['unit'] ?? 'Unit 1');
            $age = intval($requestData['age'] ?? 0);
            $mobile_num = trim($requestData['mobile_num'] ?? '');
            $alt_mobile_num = trim($requestData['alt_mobile_num'] ?? '');
            $unique_id = trim($requestData['unique_id'] ?? '');
            $address = trim($requestData['address'] ?? '');
            $role = trim($requestData['role'] ?? '');
            $experience_years = floatval($requestData['experience_years'] ?? 0);
            $extra_skills = trim($requestData['extra_skills'] ?? '');
            $password = trim($requestData['password'] ?? '');
            $raw_system_role = trim($requestData['system_role'] ?? 'operator');
            $system_role = strtolower($raw_system_role);
            $user_enum_role = 'operator';

            try {
                $sysCheck = $pdo->prepare("SELECT role_key, access_tier FROM system_access_roles WHERE LOWER(role_key) = LOWER(:k) OR LOWER(role_name) = LOWER(:k) LIMIT 1");
                $sysCheck->execute([':k' => $system_role]);
                $sysData = $sysCheck->fetch(PDO::FETCH_ASSOC);
                if ($sysData) {
                    $system_role = $sysData['role_key'];
                    $user_enum_role = in_array($sysData['access_tier'], ['admin', 'supervisor', 'operator']) ? $sysData['access_tier'] : 'operator';
                } elseif (in_array($system_role, ['admin', 'supervisor', 'operator'])) {
                    $user_enum_role = $system_role;
                } else {
                    $system_role = 'operator';
                    $user_enum_role = 'operator';
                }
            } catch (Exception $e) {
                if (!in_array($system_role, ['operator', 'supervisor', 'admin'])) {
                    $system_role = 'operator';
                }
                $user_enum_role = $system_role;
            }

            // Validation
            if (empty($name)) {
                echo json_encode(['success' => false, 'message' => 'Worker Name is required.']);
                exit;
            }
            
            // Check unit against dynamic production_units table
            $allUnitsRows = $pdo->query("SELECT unit_name, unit_title FROM production_units")->fetchAll(PDO::FETCH_ASSOC);
            $matchedUnitName = null;
            foreach ($allUnitsRows as $ur) {
                if (strcasecmp($ur['unit_name'], $unit) === 0 || strcasecmp($ur['unit_title'], $unit) === 0) {
                    $matchedUnitName = $ur['unit_name'];
                    break;
                }
            }
            if ($matchedUnitName !== null) {
                $unit = $matchedUnitName;
            } else {
                $validUnits = array_column($allUnitsRows, 'unit_name');
                if (empty($validUnits)) {
                    $validUnits = ['Unit 1', 'Unit 2'];
                }
                if (!in_array($unit, $validUnits)) {
                    echo json_encode(['success' => false, 'message' => 'Please select a valid Production Unit.']);
                    exit;
                }
            }

            $uStatusStmt = $pdo->prepare("SELECT status FROM production_units WHERE unit_name = :u LIMIT 1");
            $uStatusStmt->execute([':u' => $unit]);
            $uStatus = $uStatusStmt->fetchColumn();
            if ($uStatus === 'Disabled') {
                echo json_encode(['success' => false, 'message' => "Production Unit '$unit' is currently Disabled. Registration is paused for this unit."]);
                exit;
            }
            if ($age < 18 || $age > 80) {
                echo json_encode(['success' => false, 'message' => 'Age must be between 18 and 80.']);
                exit;
            }
            $cleanMobile = preg_replace('/\D+/', '', $mobile_num);
            if (!preg_match('/^[6-9]\d{9}$/', $cleanMobile)) {
                echo json_encode(['success' => false, 'message' => 'Primary mobile number must be a valid 10-digit number starting with 6, 7, 8, or 9.']);
                exit;
            }
            $mobile_num = $cleanMobile;

            // Optional Alternative Mobile Validation
            if (!empty($alt_mobile_num)) {
                $cleanAlt = preg_replace('/\D+/', '', $alt_mobile_num);
                if (!empty($cleanAlt)) {
                    if (!preg_match('/^[6-9]\d{9}$/', $cleanAlt)) {
                        echo json_encode(['success' => false, 'message' => 'Alternative mobile number must be a valid 10-digit number starting with 6, 7, 8, or 9.']);
                        exit;
                    }
                    if ($cleanAlt === $cleanMobile) {
                        echo json_encode(['success' => false, 'message' => 'Alternative mobile number cannot be the same as primary mobile number.']);
                        exit;
                    }
                    $alt_mobile_num = $cleanAlt;
                } else {
                    $alt_mobile_num = null;
                }
            } else {
                $alt_mobile_num = null;
            }

            // Check if mobile number already exists in workers table
            $mobCheck = $pdo->prepare("
                SELECT id, name, unique_id, mobile_num 
                FROM workers 
                WHERE mobile_num = :exact_mob
                   OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(mobile_num, ' ', ''), '-', ''), '+', ''), '(', ''), ')', '') LIKE :clean_pattern
                LIMIT 1
            ");
            $mobCheck->execute([
                ':exact_mob' => $mobile_num,
                ':clean_pattern' => '%' . $mobile_num
            ]);
            $existingWorkerWithMob = $mobCheck->fetch();

            if ($existingWorkerWithMob) {
                echo json_encode([
                    'success' => false,
                    'message' => 'This mobile number already exists (registered to ' . $existingWorkerWithMob['name'] . ' - ' . $existingWorkerWithMob['unique_id'] . ').'
                ]);
                exit;
            }
            if (empty($address)) {
                echo json_encode(['success' => false, 'message' => 'Address is required.']);
                exit;
            }
            if (empty($role)) {
                echo json_encode(['success' => false, 'message' => 'Role is required.']);
                exit;
            }
            if (empty($password)) {
                echo json_encode(['success' => false, 'message' => 'Login Password is required to create worker login credentials.']);
                exit;
            }
            if (strlen($password) < 4 || strlen($password) > 6) {
                echo json_encode(['success' => false, 'message' => 'Password must be between 4 and 6 characters long.']);
                exit;
            }

            // Auto-generate Unique ID based on Production Unit
            $prefix = 'PU001';
            $uStmt = $pdo->prepare("SELECT unit_code FROM production_units WHERE unit_name = :unit OR unit_title = :unit LIMIT 1");
            $uStmt->execute([':unit' => $unit]);
            $foundCode = $uStmt->fetchColumn();
            if ($foundCode) {
                $prefix = strtoupper(trim($foundCode));
            } elseif (preg_match('/(\d+)/', $unit, $m)) {
                $prefix = 'PU' . sprintf('%03d', (int)$m[1]);
            }

            // Determine if regeneration is required:
            // 1) unique_id is empty
            // 2) or unique_id has prefix of a DIFFERENT production unit (e.g. PU001-3 when registering for Unit 3)
            $needsRegen = empty($unique_id);
            if (!$needsRegen) {
                $allCodes = $pdo->query("SELECT DISTINCT unit_code FROM production_units")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($allCodes as $cd) {
                    $cdUpper = strtoupper(trim($cd));
                    if (!empty($cdUpper) && $cdUpper !== $prefix && stripos($unique_id, $cdUpper) === 0) {
                        $needsRegen = true;
                        break;
                    }
                }
            }

            if ($needsRegen) {
                $unique_id = getNextWorkerId($pdo, $unit);
            }

            // Check for duplicate unique_id in workers
            $checkStmt = $pdo->prepare("SELECT id FROM workers WHERE unique_id = :unique_id");
            $checkStmt->execute([':unique_id' => $unique_id]);
            if ($checkStmt->fetch()) {
                echo json_encode(['success' => false, 'message' => "Worker ID '$unique_id' already exists. Please choose another ID."]);
                exit;
            }

            // 1. Insert into workers directory
            $stmt = $pdo->prepare("
                INSERT INTO workers (unique_id, name, unit, age, mobile_num, alt_mobile_num, address, role, experience_years, extra_skills, total_pieces)
                VALUES (:unique_id, :name, :unit, :age, :mobile_num, :alt_mobile_num, :address, :role, :experience_years, :extra_skills, 0)
            ");

            $stmt->execute([
                ':unique_id' => $unique_id,
                ':name' => $name,
                ':unit' => $unit,
                ':age' => $age,
                ':mobile_num' => $mobile_num,
                ':alt_mobile_num' => !empty($alt_mobile_num) ? $alt_mobile_num : null,
                ':address' => $address,
                ':role' => $role,
                ':experience_years' => $experience_years,
                ':extra_skills' => $extra_skills
            ]);

            $newId = $pdo->lastInsertId();

            // 2. Sync credentials & assigned role into 'users' table without duplication
            $passHash = password_hash($password, PASSWORD_DEFAULT);
            $uCheck = $pdo->prepare("SELECT id FROM users WHERE LOWER(username) = LOWER(:uid) OR LOWER(full_name) = LOWER(:name) LIMIT 1");
            $uCheck->execute([':uid' => $unique_id, ':name' => $name]);
            $existingUser = $uCheck->fetch(PDO::FETCH_ASSOC);

            if ($existingUser) {
                // Update credentials and role if matching record already exists (zero duplicates)
                $uUpd = $pdo->prepare("UPDATE users SET username = :uid, password = :pass, full_name = :name, role = :role, unit = :unit WHERE id = :id");
                $uUpd->execute([
                    ':uid' => $unique_id,
                    ':pass' => $passHash,
                    ':name' => $name,
                    ':role' => $user_enum_role,
                    ':unit' => $unit,
                    ':id' => $existingUser['id']
                ]);
            } else {
                // Insert new user record
                $uIns = $pdo->prepare("
                    INSERT INTO users (username, password, full_name, role, unit)
                    VALUES (:uid, :pass, :name, :role, :unit)
                ");
                $uIns->execute([
                    ':uid' => $unique_id,
                    ':pass' => $passHash,
                    ':name' => $name,
                    ':role' => $user_enum_role,
                    ':unit' => $unit
                ]);
            }

            // Fetch updated live counts for UI ticker & badges
            $totalWorkersCount = (int)$pdo->query("SELECT COUNT(*) FROM workers")->fetchColumn();
            $unitCounts = [];
            $puStmt = $pdo->query("
                SELECT unit_name, (SELECT COUNT(*) FROM workers WHERE unit = pu.unit_name OR unit = pu.unit_title) as cnt
                FROM production_units pu
            ");
            while ($r = $puStmt->fetch(PDO::FETCH_ASSOC)) {
                $unitCounts[$r['unit_name']] = (int)$r['cnt'];
            }

            echo json_encode([
                'success' => true,
                'message' => "Worker '$name' ($unique_id) registered successfully! Login credentials & role ($system_role) active.",
                'worker_id' => $newId,
                'unique_id' => $unique_id,
                'system_role' => $system_role,
                'counts' => [
                    'total' => $totalWorkersCount,
                    'units' => $unitCounts
                ]
            ]);
            break;

        case 'get_next_worker_id':
            $unit = trim($requestData['unit'] ?? 'Unit 1');
            $nextId = getNextWorkerId($pdo, $unit);
            echo json_encode([
                'success' => true,
                'next_id' => $nextId,
                'unit' => $unit
            ]);
            break;

        // ==========================================
        // 2. LOG PIECE COUNT ENTRY (WITH MATERIAL NAME)
        // ==========================================
        case 'log_pieces':
            $worker_id = intval($requestData['worker_id'] ?? 0);
            $unique_id = trim($requestData['unique_id'] ?? '');
            $material_name = trim($requestData['material_name'] ?? '');
            $piece_count = intval($requestData['piece_count'] ?? 0);
            $work_date = trim($requestData['work_date'] ?? date('Y-m-d'));
            $shift = trim($requestData['shift'] ?? 'Day Shift');
            $notes = trim($requestData['notes'] ?? '');

            // Find worker if unique_id supplied
            if ($worker_id <= 0 && !empty($unique_id)) {
                $wStmt = $pdo->prepare("SELECT id FROM workers WHERE unique_id = :unique_id");
                $wStmt->execute([':unique_id' => $unique_id]);
                $wRow = $wStmt->fetch();
                if ($wRow) {
                    $worker_id = $wRow['id'];
                }
            }

            if ($worker_id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Please select a valid registered worker.']);
                exit;
            }

            if (empty($material_name)) {
                echo json_encode(['success' => false, 'message' => 'Material Name is required.']);
                exit;
            }

            $mode = trim($requestData['mode'] ?? 'set_total');
            if ($piece_count < 0 || ($piece_count == 0 && $mode !== 'set_total')) {
                echo json_encode(['success' => false, 'message' => 'Piece count must be 0 or greater.']);
                exit;
            }

            // Verify worker exists
            $wCheck = $pdo->prepare("SELECT id, name, unique_id, unit, total_pieces, last_login FROM workers WHERE id = :id");
            $wCheck->execute([':id' => $worker_id]);
            $worker = $wCheck->fetch();

            if (!$worker) {
                echo json_encode(['success' => false, 'message' => 'Worker not found in database.']);
                exit;
            }

            // Validation: Daily login required for operator piece entry
            $effLogin = !empty($worker['last_login']) ? $worker['last_login'] : null;
            if (empty($effLogin)) {
                $uStmt = $pdo->prepare("SELECT last_login FROM users WHERE LOWER(username) = LOWER(:uid) OR LOWER(full_name) = LOWER(:name) LIMIT 1");
                $uStmt->execute([':uid' => $worker['unique_id'], ':name' => $worker['name']]);
                $effLogin = $uStmt->fetchColumn();
            }
            $isLoggedInToday = (!empty($effLogin) && date('Y-m-d', strtotime($effLogin)) === date('Y-m-d'));
            if (!$isLoggedInToday && (!$currentUser || $currentUser['role'] !== 'admin')) {
                echo json_encode([
                    'success' => false, 
                    'message' => "Validation Error: {$worker['name']} ({$worker['unique_id']}) has not logged in today. Daily login is mandatory before recording piece output."
                ]);
                exit;
            }

            // Validation: Operator can strictly log pieces ONLY for categories assigned to them for today!
            if ($currentUser && $currentUser['role'] === 'operator') {
                $assignCheck = $pdo->prepare("
                    SELECT COUNT(*) FROM worker_work_assignments 
                    WHERE worker_id = :wid 
                      AND assigned_date = :adate 
                      AND status = 'Active' 
                      AND (LOWER(TRIM(category_name)) = LOWER(TRIM(:mat)) 
                           OR LOWER(TRIM(subcategory_name)) = LOWER(TRIM(:mat))
                           OR LOWER(TRIM(sub_subcategory_name)) = LOWER(TRIM(:mat)))
                ");
                $assignCheck->execute([':wid' => $worker_id, ':adate' => $work_date, ':mat' => $material_name]);
                $isCatAssigned = ((int)$assignCheck->fetchColumn() > 0);

                if (!$isCatAssigned && !empty($worker['assigned_work'])) {
                    if (stripos($worker['assigned_work'], $material_name) !== false) {
                        $isCatAssigned = true;
                    }
                }

                if (!$isCatAssigned) {
                    echo json_encode([
                        'success' => false,
                        'message' => "Validation Error: Work category '{$material_name}' has not been assigned to {$worker['name']} for today. Please wait for your supervisor to assign work."
                    ]);
                    exit;
                }
            }

            $currentUnit = !empty($requestData['unit']) ? trim($requestData['unit']) : (!empty($worker['unit']) ? $worker['unit'] : 'Unit 1');

            // Find existing entry for this worker, date, and material
            $existStmt = $pdo->prepare("
                SELECT id, piece_count, notes, unit 
                FROM piece_logs 
                WHERE worker_id = :worker_id 
                  AND work_date = :work_date 
                  AND LOWER(TRIM(material_name)) = LOWER(TRIM(:material_name))
                ORDER BY id DESC LIMIT 1
            ");
            $existStmt->execute([
                ':worker_id' => $worker_id,
                ':work_date' => $work_date,
                ':material_name' => $material_name
            ]);
            $existingLog = $existStmt->fetch();

            $prevCount = 0;
            $newCount = $piece_count;
            $added = $piece_count;
            $logId = 0;

            if ($existingLog) {
                $logId = (int)$existingLog['id'];
                $prevCount = (int)$existingLog['piece_count'];

                if ($mode === 'set_total') {
                    $newCount = $piece_count;
                    $added = $newCount - $prevCount;
                } else {
                    $newCount = $prevCount + $piece_count;
                    $added = $piece_count;
                }

                if ($newCount <= 0) {
                    $pdo->prepare("DELETE FROM piece_logs WHERE id = :id")->execute([':id' => $logId]);
                    $newCount = 0;
                } else {
                    $upStmt = $pdo->prepare("
                        UPDATE piece_logs 
                        SET piece_count = :piece_count, unit = :unit, created_at = CURRENT_TIMESTAMP 
                        WHERE id = :id
                    ");
                    $upStmt->execute([
                        ':piece_count' => $newCount,
                        ':unit' => $currentUnit,
                        ':id' => $logId
                    ]);
                }

                // Clean up any remaining duplicate rows for this worker, date, and material
                $pdo->prepare("
                    DELETE FROM piece_logs 
                    WHERE worker_id = :worker_id 
                      AND work_date = :work_date 
                      AND LOWER(TRIM(material_name)) = LOWER(TRIM(:material_name))
                      AND id != :keep_id
                ")->execute([
                    ':worker_id' => $worker_id,
                    ':work_date' => $work_date,
                    ':material_name' => $material_name,
                    ':keep_id' => $logId
                ]);
            } elseif ($newCount > 0) {
                $logStmt = $pdo->prepare("
                    INSERT INTO piece_logs (worker_id, unit, material_name, piece_count, work_date, shift, notes)
                    VALUES (:worker_id, :unit, :material_name, :piece_count, :work_date, :shift, :notes)
                ");

                $logStmt->execute([
                    ':worker_id' => $worker_id,
                    ':unit' => $currentUnit,
                    ':material_name' => $material_name,
                    ':piece_count' => $newCount,
                    ':work_date' => $work_date,
                    ':shift' => !empty($shift) ? $shift : 'Day Shift',
                    ':notes' => $notes
                ]);
                $logId = (int)$pdo->lastInsertId();
            }

            // Recalculate worker's total pieces
            Database::recalculateWorkerTotals();

            // Fetch new total and rank
            $updatedW = $pdo->prepare("SELECT total_pieces FROM workers WHERE id = :id");
            $updatedW->execute([':id' => $worker_id]);
            $newTotal = $updatedW->fetchColumn() ?: 0;

            // Worker's today pieces sum
            $todaySumStmt = $pdo->prepare("
                SELECT COALESCE(SUM(piece_count), 0) 
                FROM piece_logs 
                WHERE worker_id = :worker_id AND work_date = :work_date
            ");
            $todaySumStmt->execute([
                ':worker_id' => $worker_id,
                ':work_date' => $work_date
            ]);
            $todayTotalPieces = (int)$todaySumStmt->fetchColumn();

            // Today's total for this specific material
            $matSumStmt = $pdo->prepare("
                SELECT COALESCE(SUM(piece_count), 0) 
                FROM piece_logs 
                WHERE worker_id = :worker_id 
                  AND work_date = :work_date 
                  AND LOWER(TRIM(material_name)) = LOWER(TRIM(:material_name))
            ");
            $matSumStmt->execute([
                ':worker_id' => $worker_id,
                ':work_date' => $work_date,
                ':material_name' => $material_name
            ]);
            $materialTodayTotal = (int)$matSumStmt->fetchColumn();

            // Lifetime fabric breakdown for this worker with multi-unit breakdown
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
            $allMatStmt->execute([':worker_id' => $worker_id]);
            $lifetimeMaterials = $allMatStmt->fetchAll(PDO::FETCH_ASSOC);

            $msgFormula = ($prevCount > 0 && $added > 0) 
                ? "{$material_name}: {$prevCount} + {$added} = {$newCount} pcs completed today!"
                : "{$material_name}: {$newCount} pcs completed today!";

            ob_start();
            include __DIR__ . '/includes/unit_category_slider.php';
            $categorySliderHtml = ob_get_clean();

            echo json_encode([
                'success' => true,
                'message' => $msgFormula,
                'worker_name' => $worker['name'],
                'unique_id' => $worker['unique_id'],
                'material_name' => $material_name,
                'log_id' => $logId,
                'previous_count' => $prevCount,
                'added_pieces' => $added,
                'current_material_count' => $newCount,
                'material_today_total' => $materialTodayTotal,
                'today_pieces' => $todayTotalPieces,
                'formula' => ($prevCount > 0 && $added > 0) ? "{$prevCount} + {$added} = {$newCount} pcs" : "{$newCount} pcs",
                'shift' => $shift,
                'new_total_pieces' => (int)$newTotal,
                'lifetime_materials' => $lifetimeMaterials,
                'category_slider_html' => $categorySliderHtml
            ]);
            break;

        case 'get_unit_category_slider':
        case 'get_category_slider':
            ob_start();
            include __DIR__ . '/includes/unit_category_slider.php';
            $categorySliderHtml = ob_get_clean();
            echo json_encode([
                'success' => true,
                'html' => $categorySliderHtml,
                'all_total' => $allTotalPieces ?? 0,
                'u1_total' => $u1TotalPieces ?? 0,
                'u2_total' => $u2TotalPieces ?? 0
            ]);
            break;

        case 'get_cooldown_settings':
            $sec = (int)Database::getSetting('stitching_cooldown_seconds', 30);
            echo json_encode([
                'success' => true,
                'cooldown_seconds' => $sec
            ]);
            break;

        case 'update_cooldown_settings':
            $sec = intval($requestData['cooldown_seconds'] ?? 30);
            if ($sec < 0) $sec = 0;
            if ($sec > 600) $sec = 600;
            Database::setSetting('stitching_cooldown_seconds', $sec);
            echo json_encode([
                'success' => true,
                'message' => "Stitching waiting time updated to {$sec}s successfully!",
                'cooldown_seconds' => $sec
            ]);
            break;

        // ==========================================
        // 3. GET WORKERS LIST (WITH LEADERBOARD RANKING)
        // ==========================================
        case 'get_workers':
            $unit = trim($requestData['unit'] ?? '');
            $material = trim($requestData['material'] ?? '');
            $search = trim($requestData['search'] ?? '');
            $sortBy = trim($requestData['sort'] ?? 'pieces_desc');

            $params = [];
            $matJoinCondition = '';
            if (!empty($material)) {
                $matJoinCondition = " AND LOWER(TRIM(pl.material_name)) = LOWER(TRIM(:material)) ";
                $params[':material'] = $material;
            }

            $sql = "
                SELECT 
                    w.*,
                    COALESCE((SELECT SUM(pl.piece_count) FROM piece_logs pl WHERE pl.worker_id = w.id $matJoinCondition), 0) as material_pieces,
                    (SELECT COUNT(*) FROM piece_logs WHERE worker_id = w.id) as log_count,
                    (SELECT material_name FROM piece_logs WHERE worker_id = w.id ORDER BY id DESC LIMIT 1) as latest_material
                FROM workers w
                WHERE 1=1
            ";

            if (!empty($unit) && $unit !== 'All') {
                $sql .= " AND w.unit = :unit";
                $params[':unit'] = $unit;
            }

            if (!empty($search)) {
                $sql .= " AND (w.name LIKE :search OR w.unique_id LIKE :search OR w.role LIKE :search OR w.mobile_num LIKE :search)";
                $params[':search'] = '%' . $search . '%';
            }

            if (!empty($material)) {
                $sql .= " ORDER BY material_pieces DESC, w.total_pieces DESC, w.name ASC";
            } elseif ($sortBy === 'pieces_asc') {
                $sql .= " ORDER BY w.total_pieces ASC, w.name ASC";
            } elseif ($sortBy === 'name') {
                $sql .= " ORDER BY w.name ASC";
            } elseif ($sortBy === 'recent') {
                $sql .= " ORDER BY w.id DESC";
            } elseif ($sortBy === 'experience') {
                $sql .= " ORDER BY w.experience_years DESC";
            } else {
                $sql .= " ORDER BY w.total_pieces DESC, w.experience_years DESC";
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $workers = $stmt->fetchAll();


            // Add ranks
            $rank = 1;
            foreach ($workers as &$w) {
                $w['rank'] = $rank++;
                $w['filtered_material'] = $material;
                $deriveSwitched($w);
            }

            echo json_encode([
                'success' => true,
                'count' => count($workers),
                'filtered_material' => $material,
                'data' => $workers
            ]);
            break;

        // ==========================================
        // 3B. GET REAL-TIME LEADERBOARD FOR A SPECIFIC CLOTH / CATEGORY (e.g. Maternity)
        // ==========================================
        case 'get_cloth_leaderboard':
            $material = trim($requestData['material'] ?? '');
            $unit = trim($requestData['unit'] ?? '');
            $date = trim($requestData['date'] ?? '');
            
            if (empty($material)) {
                echo json_encode(['success' => false, 'message' => 'Material/Category is required.']);
                exit;
            }

            // Match exact material name OR category like '%maternity%'
            $isMaternityCategory = (stripos($material, 'maternity') !== false);
            
            $whereClause = "WHERE 1=1";
            $params = [];
            
            if ($isMaternityCategory) {
                $matSql = "AND (LOWER(pl.material_name) LIKE '%maternity%' OR LOWER(TRIM(pl.material_name)) = LOWER(TRIM(:exact_mat)))";
                $params[':exact_mat'] = $material;
            } else {
                $matSql = "AND LOWER(TRIM(pl.material_name)) = LOWER(TRIM(:exact_mat))";
                $params[':exact_mat'] = $material;
            }

            $dateSql = "";
            if (!empty($date)) {
                $dateSql = "AND pl.work_date = :work_date";
                $params[':work_date'] = $date;
            }

            if (!empty($unit) && strtolower($unit) !== 'all') {
                $whereClause .= " AND LOWER(TRIM(w.unit)) = LOWER(TRIM(:unit))";
                $params[':unit'] = $unit;
            }

            $sql = "
                SELECT 
                    w.*,
                    COALESCE(SUM(pl.piece_count), 0) as category_pieces,
                    COUNT(pl.id) as batch_count,
                    MAX(pl.created_at) as last_stitch_time
                FROM workers w
                LEFT JOIN piece_logs pl ON w.id = pl.worker_id $matSql $dateSql
                $whereClause
                GROUP BY w.id, w.unique_id, w.name, w.unit, w.role, w.experience_years
                ORDER BY category_pieces DESC, w.total_pieces DESC, w.name ASC
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $workers = $stmt->fetchAll();

            $rank = 1;
            $totalCategoryPieces = 0;
            $activeParticipants = 0;

            foreach ($workers as &$w) {
                $w['rank'] = $rank++;
                $w['category_pieces'] = (int)$w['category_pieces'];
                $totalCategoryPieces += $w['category_pieces'];
                if ($w['category_pieces'] > 0) {
                    $activeParticipants++;
                }
                $deriveSwitched($w);
            }

            echo json_encode([
                'success' => true,
                'material' => $material,
                'is_maternity' => $isMaternityCategory,
                'total_pieces' => $totalCategoryPieces,
                'active_participants' => $activeParticipants,
                'count' => count($workers),
                'data' => $workers
            ]);
            break;

        // ==========================================
        // 4. GET DASHBOARD LEADS (PIECE LOGS WITH MATERIALS)
        // ==========================================
        case 'get_leads':
            $unit = trim($requestData['unit'] ?? '');
            $material = trim($requestData['material'] ?? '');
            $worker_id = intval($requestData['worker_id'] ?? 0);
            $search = trim($requestData['search'] ?? '');
            $date = trim($requestData['date'] ?? '');
            $limit = intval($requestData['limit'] ?? 100);

            $sql = "
                SELECT 
                    pl.id as log_id,
                    pl.material_name,
                    pl.piece_count,
                    pl.work_date,
                    pl.shift,
                    pl.notes,
                    pl.created_at as logged_at,
                    w.id as worker_id,
                    w.unique_id,
                    w.name as worker_name,
                    w.unit,
                    w.role,
                    w.mobile_num,
                    w.total_pieces as worker_cumulative_pieces
                FROM piece_logs pl
                JOIN workers w ON pl.worker_id = w.id
                WHERE 1=1
            ";
            $params = [];

            if (!empty($unit) && $unit !== 'All') {
                $sql .= " AND w.unit = :unit";
                $params[':unit'] = $unit;
            }

            if (!empty($material)) {
                $sql .= " AND pl.material_name = :material";
                $params[':material'] = $material;
            }

            if ($worker_id > 0) {
                $sql .= " AND w.id = :worker_id";
                $params[':worker_id'] = $worker_id;
            }

            if (!empty($date)) {
                $sql .= " AND pl.work_date = :date";
                $params[':date'] = $date;
            }

            if (!empty($search)) {
                $sql .= " AND (w.name LIKE :search OR w.unique_id LIKE :search OR pl.material_name LIKE :search OR w.role LIKE :search)";
                $params[':search'] = '%' . $search . '%';
            }

            $sql .= " ORDER BY pl.work_date DESC, pl.id DESC LIMIT :limit";

            $stmt = $pdo->prepare($sql);
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $leads = $stmt->fetchAll();

            echo json_encode([
                'success' => true,
                'count' => count($leads),
                'data' => $leads
            ]);
            break;

        // ==========================================
        // 5. GET DISTINCT MATERIALS LIST
        // ==========================================
        case 'get_materials':
            $stmt = $pdo->query("
                SELECT 
                    material_name, 
                    COUNT(*) as log_count,
                    SUM(piece_count) as total_pieces
                FROM piece_logs
                GROUP BY material_name
                ORDER BY total_pieces DESC
            ");
            $materials = $stmt->fetchAll();

            echo json_encode([
                'success' => true,
                'data' => $materials
            ]);
            break;

        // ==========================================
        // 6. GET AGGREGATE SYSTEM STATS
        // ==========================================
        case 'get_stats':
            // Worker Counts
            $totalWorkers = $pdo->query("SELECT COUNT(*) FROM workers")->fetchColumn() ?: 0;
            $unit1Workers = $pdo->query("SELECT COUNT(*) FROM workers WHERE unit = 'Unit 1'")->fetchColumn() ?: 0;
            $unit2Workers = $pdo->query("SELECT COUNT(*) FROM workers WHERE unit = 'Unit 2'")->fetchColumn() ?: 0;

            // Total Pieces
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

            // Today's Pieces
            $todayPieces = $pdo->query("
                SELECT COALESCE(SUM(piece_count), 0) 
                FROM piece_logs 
                WHERE work_date = CURRENT_DATE
            ")->fetchColumn() ?: 0;

            // Top Material
            $topMaterialStmt = $pdo->query("
                SELECT material_name, SUM(piece_count) as total 
                FROM piece_logs 
                GROUP BY material_name 
                ORDER BY total DESC 
                LIMIT 1
            ");
            $topMaterial = $topMaterialStmt->fetch();

            // Top 3 Leaderboard Workers
            $topWorkersStmt = $pdo->query("
                SELECT id, unique_id, name, unit, role, total_pieces, experience_years
                FROM workers
                ORDER BY total_pieces DESC, experience_years DESC
                LIMIT 3
            ");
            $topWorkers = $topWorkersStmt->fetchAll();

            echo json_encode([
                'success' => true,
                'stats' => [
                    'total_workers' => (int)$totalWorkers,
                    'unit1_workers' => (int)$unit1Workers,
                    'unit2_workers' => (int)$unit2Workers,
                    'total_pieces' => (int)$totalPieces,
                    'unit1_pieces' => (int)$unit1Pieces,
                    'unit2_pieces' => (int)$unit2Pieces,
                    'today_pieces' => (int)$todayPieces,
                    'top_material' => $topMaterial ? $topMaterial['material_name'] : 'N/A',
                    'top_material_pieces' => $topMaterial ? (int)$topMaterial['total'] : 0,
                    'top_performers' => $topWorkers
                ]
            ]);
            break;

        // ==========================================
        // 7. GET SINGLE WORKER DETAILS & HISTORY
        // ==========================================
        case 'get_worker_details':
            $id = intval($requestData['id'] ?? 0);
            $unique_id = trim($requestData['unique_id'] ?? '');
            $username = trim($requestData['username'] ?? '');

            $worker = null;
            if ($id > 0) {
                $wStmt = $pdo->prepare("SELECT * FROM workers WHERE id = :id");
                $wStmt->execute([':id' => $id]);
                $worker = $wStmt->fetch(PDO::FETCH_ASSOC);
            }
            if (!$worker && !empty($unique_id)) {
                $wStmt = $pdo->prepare("SELECT * FROM workers WHERE unique_id = :unique_id OR LOWER(unique_id) = LOWER(:uid_lower)");
                $wStmt->execute([':unique_id' => $unique_id, ':uid_lower' => strtolower($unique_id)]);
                $worker = $wStmt->fetch(PDO::FETCH_ASSOC);
            }
            if (!$worker && !empty($username)) {
                $wStmt = $pdo->prepare("
                    SELECT w.* FROM workers w
                    JOIN users u ON (
                        LOWER(u.username) = LOWER(w.unique_id)
                        OR REPLACE(u.username, '-', '') = REPLACE(w.unique_id, '-', '')
                        OR (
                            LOWER(u.full_name) = LOWER(w.name)
                            AND NOT EXISTS (
                                SELECT 1 FROM workers w_sub 
                                WHERE LOWER(w_sub.unique_id) = LOWER(u.username) 
                                   OR REPLACE(w_sub.unique_id, '-', '') = REPLACE(u.username, '-', '')
                            )
                        )
                    )
                    WHERE u.username = :username OR u.id = :uid
                    LIMIT 1
                ");
                $wStmt->execute([':username' => $username, ':uid' => intval($username)]);
                $worker = $wStmt->fetch(PDO::FETCH_ASSOC);
            }

            if (!$worker) {
                echo json_encode([
                    'success' => true,
                    'worker' => null,
                    'is_operator' => false,
                    'grand_total_pieces' => 0,
                    'category_count' => 0,
                    'materials_breakdown' => [],
                    'logs' => [],
                    'message' => 'No factory floor stitching logs associated with this system account.'
                ]);
                exit;
            }

            // Material/Category breakdown for this worker with multi-unit support
            $matStmt = $pdo->prepare("
                SELECT 
                    material_name, 
                    GROUP_CONCAT(DISTINCT COALESCE(unit, '')) as units_stitched,
                    SUM(CASE WHEN LOWER(COALESCE(unit, '')) LIKE '%unit 1%' OR unit = '1' THEN piece_count ELSE 0 END) as unit1_pieces,
                    SUM(CASE WHEN LOWER(COALESCE(unit, '')) LIKE '%unit 2%' OR unit = '2' THEN piece_count ELSE 0 END) as unit2_pieces,
                    SUM(piece_count) as total_pieces, 
                    COUNT(*) as log_count,
                    MAX(work_date) as last_date
                FROM piece_logs
                WHERE worker_id = :worker_id
                GROUP BY material_name
                ORDER BY total_pieces DESC
            ");
            $matStmt->execute([':worker_id' => $worker['id']]);
            $materials = $matStmt->fetchAll(PDO::FETCH_ASSOC);

            // Fetch category/subcategory lookup map to enrich items
            $catMapStmt = $pdo->query("SELECT category_name, subcategory_name, sub_subcategory_name FROM garment_categories WHERE status = 'Active'");
            $catHierarchy = $catMapStmt->fetchAll(PDO::FETCH_ASSOC);

            // Compute grand total across all categories
            $grandTotal = 0;
            foreach ($materials as $m) {
                $grandTotal += (int)$m['total_pieces'];
            }

            // Enrich materials with percentage, unit breakdowns, & category/subcategory tags
            foreach ($materials as &$m) {
                $m['total_pieces'] = (int)$m['total_pieces'];
                $m['unit1_pieces'] = (int)$m['unit1_pieces'];
                $m['unit2_pieces'] = (int)$m['unit2_pieces'];
                $m['log_count'] = (int)$m['log_count'];
                $m['percentage'] = $grandTotal > 0 ? round(($m['total_pieces'] / $grandTotal) * 100, 1) : 0;
                $m['last_date_formatted'] = !empty($m['last_date']) ? date('M d, Y', strtotime($m['last_date'])) : '—';
                $m['units_stitched'] = !empty($m['units_stitched']) ? trim($m['units_stitched'], ', ') : '';

                // Try to resolve category and subcategory
                $matLower = strtolower(trim($m['material_name']));
                $m['category_label'] = '';
                $m['subcategory_label'] = '';
                foreach ($catHierarchy as $ch) {
                    $cLow = strtolower(trim($ch['category_name']));
                    $sLow = strtolower(trim($ch['subcategory_name']));
                    $ssLow = strtolower(trim($ch['sub_subcategory_name'] ?? ''));
                    if ($matLower === $sLow || $matLower === $ssLow) {
                        $m['category_label'] = $ch['category_name'];
                        $m['subcategory_label'] = $ch['subcategory_name'];
                        break;
                    } elseif ($matLower === $cLow) {
                        $m['category_label'] = $ch['category_name'];
                        break;
                    }
                }
            }
            unset($m);

            // Recent logs for this worker
            $logsStmt = $pdo->prepare("
                SELECT * FROM piece_logs 
                WHERE worker_id = :worker_id 
                ORDER BY work_date DESC, id DESC
                LIMIT 30
            ");
            $logsStmt->execute([':worker_id' => $worker['id']]);
            $logs = $logsStmt->fetchAll(PDO::FETCH_ASSOC);

            // Calculate overall rank
            $rankStmt = $pdo->prepare("
                SELECT COUNT(*) + 1 as rank 
                FROM workers 
                WHERE total_pieces > :total OR (total_pieces = :total AND id < :id)
            ");
            $rankStmt->execute([':total' => $worker['total_pieces'], ':id' => $worker['id']]);
            $rank = $rankStmt->fetchColumn();
            $worker['rank'] = (int)$rank;

            // Unit-wise overall stitched breakdown for this worker
            $unitStmt = $pdo->prepare("
                SELECT 
                    COALESCE(NULLIF(TRIM(unit), ''), 'Unit 1') as unit_name,
                    SUM(piece_count) as total_pieces,
                    COUNT(*) as log_count
                FROM piece_logs
                WHERE worker_id = :worker_id
                GROUP BY COALESCE(NULLIF(TRIM(unit), ''), 'Unit 1')
                ORDER BY total_pieces DESC
            ");
            $unitStmt->execute([':worker_id' => $worker['id']]);
            $unitsBreakdown = $unitStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($unitsBreakdown as &$ub) {
                $ub['total_pieces'] = (int)$ub['total_pieces'];
                $ub['log_count'] = (int)$ub['log_count'];
                $ub['percentage'] = $grandTotal > 0 ? round(($ub['total_pieces'] / $grandTotal) * 100, 1) : 0;
            }
            unset($ub);

            echo json_encode([
                'success' => true,
                'worker' => $worker,
                'is_operator' => true,
                'grand_total_pieces' => $grandTotal,
                'category_count' => count($materials),
                'materials_breakdown' => $materials,
                'units_breakdown' => $unitsBreakdown,
                'logs' => $logs
            ]);
            break;

        // ==========================================
        // 8. 3-TIER CATEGORY, SUBCATEGORY & SUB-SUBCATEGORY MANAGEMENT (ADMIN ONLY)
        // ==========================================
        case 'get_categories':
            $stmt = $pdo->query("SELECT id, category_name, subcategory_name, sub_subcategory_name, status, stitch_time_seconds FROM garment_categories ORDER BY category_name ASC, subcategory_name ASC, sub_subcategory_name ASC");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $tree = [];
            $flat = [];
            foreach ($rows as $r) {
                $c = $r['category_name'];
                $s = $r['subcategory_name'];
                $subsub = trim($r['sub_subcategory_name']);
                $st = !empty($r['status']) ? $r['status'] : 'Active';
                $stTime = max(0, (int)($r['stitch_time_seconds'] ?? 0));
                if (!isset($tree[$c])) {
                    $tree[$c] = [];
                }
                if (!isset($tree[$c][$s])) {
                    $tree[$c][$s] = [
                        'status' => $st,
                        'stitch_time_seconds' => $stTime,
                        'items' => []
                    ];
                }
                if ($subsub !== '') {
                    $tree[$c][$s]['items'][] = [
                        'id' => (int)$r['id'],
                        'name' => $subsub,
                        'status' => $st,
                        'stitch_time_seconds' => $stTime
                    ];
                }
                $flat[] = [
                    'id' => (int)$r['id'],
                    'category' => $c,
                    'subcategory' => $s,
                    'sub_subcategory' => $subsub,
                    'status' => $st,
                    'stitch_time_seconds' => $stTime,
                    'full_label' => ($subsub !== '') ? "{$c} › {$s} › {$subsub}" : "{$c} › {$s}"
                ];
            }
            echo json_encode([
                'success' => true,
                'rows' => $rows,
                'tree' => $tree,
                'flat' => $flat,
                'total' => count($rows)
            ]);
            break;

        case 'add_category':
            $catName = trim($requestData['category_name'] ?? '');
            $subName = trim($requestData['subcategory_name'] ?? '');
            $subSubName = trim($requestData['sub_subcategory_name'] ?? '');
            $stitchTime = max(0, (int)($requestData['stitch_time_seconds'] ?? 0));

            if (empty($catName) || empty($subName)) {
                echo json_encode(['success' => false, 'message' => 'Category and Subcategory are required. (Sub-Subcategory is optional).']);
                exit;
            }
            // Check duplicate
            if ($subSubName !== '') {
                $check = $pdo->prepare("SELECT id FROM garment_categories WHERE LOWER(category_name) = LOWER(:cat) AND LOWER(subcategory_name) = LOWER(:sub) AND LOWER(sub_subcategory_name) = LOWER(:subsub) LIMIT 1");
                $check->execute([':cat' => $catName, ':sub' => $subName, ':subsub' => $subSubName]);
            } else {
                $check = $pdo->prepare("SELECT id FROM garment_categories WHERE LOWER(category_name) = LOWER(:cat) AND LOWER(subcategory_name) = LOWER(:sub) AND (sub_subcategory_name = '' OR sub_subcategory_name IS NULL) LIMIT 1");
                $check->execute([':cat' => $catName, ':sub' => $subName]);
            }
            if ($check->fetch()) {
                $dupMsg = ($subSubName !== '') ? 'This Category ➔ Subcategory ➔ Sub-Subcategory already exists.' : 'This Category ➔ Subcategory already exists.';
                echo json_encode(['success' => false, 'message' => $dupMsg]);
                exit;
            }
            $ins = $pdo->prepare("INSERT INTO garment_categories (category_name, subcategory_name, sub_subcategory_name, status, stitch_time_seconds) VALUES (:cat, :sub, :subsub, 'Active', :stitch_time)");
            $ins->execute([
                ':cat' => $catName, 
                ':sub' => $subName, 
                ':subsub' => $subSubName,
                ':stitch_time' => $stitchTime
            ]);

            // Re-fetch tree
            $stmt = $pdo->query("SELECT id, category_name, subcategory_name, sub_subcategory_name, status, stitch_time_seconds FROM garment_categories ORDER BY category_name ASC, subcategory_name ASC, sub_subcategory_name ASC");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $tree = [];
            $flat = [];
            foreach ($rows as $r) {
                $c = $r['category_name'];
                $s = $r['subcategory_name'];
                $subsub = trim($r['sub_subcategory_name']);
                $st = !empty($r['status']) ? $r['status'] : 'Active';
                $stTime = max(0, (int)($r['stitch_time_seconds'] ?? 0));
                if (!isset($tree[$c])) $tree[$c] = [];
                if (!isset($tree[$c][$s])) $tree[$c][$s] = ['status' => $st, 'stitch_time_seconds' => $stTime, 'items' => []];
                if ($subsub !== '') {
                    $tree[$c][$s]['items'][] = ['id' => (int)$r['id'], 'name' => $subsub, 'status' => $st, 'stitch_time_seconds' => $stTime];
                }
                $flat[] = [
                    'id' => (int)$r['id'],
                    'category' => $c,
                    'subcategory' => $s,
                    'sub_subcategory' => $subsub,
                    'status' => $st,
                    'stitch_time_seconds' => $stTime,
                    'full_label' => ($subsub !== '') ? "{$c} › {$s} › {$subsub}" : "{$c} › {$s}"
                ];
            }
            $dispLabel = ($subSubName !== '') ? "{$catName} › {$subName} › {$subSubName}" : "{$catName} › {$subName}";
            if ($stitchTime > 0) {
                $dispLabel .= " ({$stitchTime}s stitch time)";
            }
            echo json_encode([
                'success' => true,
                'message' => "Successfully added: {$dispLabel}",
                'rows' => $rows,
                'tree' => $tree,
                'flat' => $flat,
                'total' => count($rows)
            ]);
            break;

        case 'set_category_time':
            $catName = trim($requestData['category_name'] ?? '');
            $subName = trim($requestData['subcategory_name'] ?? '');
            $id = intval($requestData['id'] ?? 0);
            $stitchTime = max(0, intval($requestData['stitch_time_seconds'] ?? 0));

            if (!empty($catName) && !empty($subName)) {
                $upd = $pdo->prepare("UPDATE garment_categories SET stitch_time_seconds = :sec WHERE category_name = :c AND subcategory_name = :s");
                $upd->execute([':sec' => $stitchTime, ':c' => $catName, ':s' => $subName]);
                $msg = "Stitching time for '{$subName}' set to {$stitchTime}s";
            } elseif (!empty($catName)) {
                $upd = $pdo->prepare("UPDATE garment_categories SET stitch_time_seconds = :sec WHERE category_name = :c");
                $upd->execute([':sec' => $stitchTime, ':c' => $catName]);
                $msg = "Stitching time for category '{$catName}' set to {$stitchTime}s";
            } elseif ($id > 0) {
                $upd = $pdo->prepare("UPDATE garment_categories SET stitch_time_seconds = :sec WHERE id = :id");
                $upd->execute([':sec' => $stitchTime, ':id' => $id]);
                $msg = "Stitching time set to {$stitchTime}s";
            } else {
                echo json_encode(['success' => false, 'message' => 'Missing parameter.']);
                exit;
            }

            $stmt = $pdo->query("SELECT id, category_name, subcategory_name, sub_subcategory_name, status, stitch_time_seconds FROM garment_categories ORDER BY category_name ASC, subcategory_name ASC, sub_subcategory_name ASC");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'message' => $msg, 'rows' => $rows]);
            break;

        case 'toggle_category_status':
            $id = intval($requestData['id'] ?? 0);
            $catName = trim($requestData['category_name'] ?? '');
            $subName = trim($requestData['subcategory_name'] ?? '');
            $desiredStatus = trim($requestData['status'] ?? '');

            if (!empty($catName) && !empty($subName)) {
                $chk = $pdo->prepare("SELECT status FROM garment_categories WHERE category_name = :c AND subcategory_name = :s LIMIT 1");
                $chk->execute([':c' => $catName, ':s' => $subName]);
                $curr = $chk->fetchColumn() ?: 'Active';
                $newSt = !empty($desiredStatus) ? $desiredStatus : (($curr === 'Disabled') ? 'Active' : 'Disabled');
                $upd = $pdo->prepare("UPDATE garment_categories SET status = :st WHERE category_name = :c AND subcategory_name = :s");
                $upd->execute([':st' => $newSt, ':c' => $catName, ':s' => $subName]);
                $msg = "Subcategory '{$subName}' is now {$newSt}!";
            } elseif (!empty($catName)) {
                $chk = $pdo->prepare("SELECT status FROM garment_categories WHERE category_name = :c LIMIT 1");
                $chk->execute([':c' => $catName]);
                $curr = $chk->fetchColumn() ?: 'Active';
                $newSt = !empty($desiredStatus) ? $desiredStatus : (($curr === 'Disabled') ? 'Active' : 'Disabled');
                $upd = $pdo->prepare("UPDATE garment_categories SET status = :st WHERE category_name = :c");
                $upd->execute([':st' => $newSt, ':c' => $catName]);
                $msg = "Category '{$catName}' is now {$newSt}!";
            } elseif ($id > 0) {
                $chk = $pdo->prepare("SELECT status FROM garment_categories WHERE id = :id");
                $chk->execute([':id' => $id]);
                $curr = $chk->fetchColumn() ?: 'Active';
                $newSt = !empty($desiredStatus) ? $desiredStatus : (($curr === 'Disabled') ? 'Active' : 'Disabled');
                $upd = $pdo->prepare("UPDATE garment_categories SET status = :st WHERE id = :id");
                $upd->execute([':st' => $newSt, ':id' => $id]);
                $msg = "Item is now {$newSt}!";
            } else {
                echo json_encode(['success' => false, 'message' => 'Missing parameter.']);
                exit;
            }

            // Re-fetch all
            $stmt = $pdo->query("SELECT id, category_name, subcategory_name, sub_subcategory_name, status FROM garment_categories ORDER BY category_name ASC, subcategory_name ASC, sub_subcategory_name ASC");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $tree = [];
            $flat = [];
            foreach ($rows as $r) {
                $c = $r['category_name'];
                $s = $r['subcategory_name'];
                $subsub = trim($r['sub_subcategory_name']);
                $st = !empty($r['status']) ? $r['status'] : 'Active';
                if (!isset($tree[$c])) $tree[$c] = [];
                if (!isset($tree[$c][$s])) $tree[$c][$s] = ['status' => $st, 'items' => []];
                if ($subsub !== '') {
                    $tree[$c][$s]['items'][] = ['id' => (int)$r['id'], 'name' => $subsub, 'status' => $st];
                }
                $flat[] = [
                    'id' => (int)$r['id'],
                    'category' => $c,
                    'subcategory' => $s,
                    'sub_subcategory' => $subsub,
                    'status' => $st
                ];
            }

            echo json_encode([
                'success' => true,
                'message' => $msg,
                'rows' => $rows,
                'tree' => $tree,
                'flat' => $flat
            ]);
            break;

        case 'delete_category':
            $id = intval($requestData['id'] ?? 0);
            $type = trim($requestData['type'] ?? 'single');
            $catName = trim($requestData['category_name'] ?? '');
            $subName = trim($requestData['subcategory_name'] ?? '');

            if ($type === 'category' && !empty($catName)) {
                $del = $pdo->prepare("DELETE FROM garment_categories WHERE LOWER(TRIM(category_name)) = LOWER(TRIM(:cat))");
                $del->execute([':cat' => $catName]);
                try {
                    $delAss = $pdo->prepare("DELETE FROM worker_work_assignments WHERE LOWER(TRIM(category_name)) = LOWER(TRIM(:cat))");
                    $delAss->execute([':cat' => $catName]);
                } catch (Exception $e) {}
            } elseif ($type === 'subcategory' && !empty($catName) && !empty($subName)) {
                $del = $pdo->prepare("DELETE FROM garment_categories WHERE LOWER(TRIM(category_name)) = LOWER(TRIM(:cat)) AND LOWER(TRIM(subcategory_name)) = LOWER(TRIM(:sub))");
                $del->execute([':cat' => $catName, ':sub' => $subName]);
                try {
                    $delAss = $pdo->prepare("DELETE FROM worker_work_assignments WHERE LOWER(TRIM(category_name)) = LOWER(TRIM(:cat)) AND LOWER(TRIM(subcategory_name)) = LOWER(TRIM(:sub))");
                    $delAss->execute([':cat' => $catName, ':sub' => $subName]);
                } catch (Exception $e) {}
            } elseif ($id > 0) {
                $del = $pdo->prepare("DELETE FROM garment_categories WHERE id = :id");
                $del->execute([':id' => $id]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Invalid delete request parameter.']);
                exit;
            }

            // Re-fetch tree
            $stmt = $pdo->query("SELECT id, category_name, subcategory_name, sub_subcategory_name, status FROM garment_categories ORDER BY category_name ASC, subcategory_name ASC, sub_subcategory_name ASC");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $tree = [];
            $flat = [];
            foreach ($rows as $r) {
                $c = $r['category_name'];
                $s = $r['subcategory_name'];
                $subsub = trim($r['sub_subcategory_name']);
                $st = !empty($r['status']) ? $r['status'] : 'Active';
                if (!isset($tree[$c])) $tree[$c] = [];
                if (!isset($tree[$c][$s])) $tree[$c][$s] = ['status' => $st, 'items' => []];
                if ($subsub !== '') {
                    $tree[$c][$s]['items'][] = ['id' => (int)$r['id'], 'name' => $subsub, 'status' => $st];
                }
                $flat[] = ['id' => (int)$r['id'], 'category' => $c, 'subcategory' => $s, 'sub_subcategory' => $subsub, 'status' => $st];
            }
            echo json_encode([
                'success' => true,
                'message' => 'Deleted successfully.',
                'rows' => $rows,
                'tree' => $tree,
                'flat' => $flat,
                'total' => count($rows)
            ]);
            break;

        // ==========================================
        // 9. DYNAMIC WORKER ROLES / DESIGNATIONS (ADMIN ONLY)
        // ==========================================
        case 'get_roles':
            $rolesStmt = $pdo->query("SELECT id, role_name, created_by, created_at FROM worker_roles ORDER BY role_name ASC");
            $roles = $rolesStmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode([
                'success' => true,
                'roles' => $roles,
                'total' => count($roles)
            ]);
            break;

        case 'add_role':
            $roleName = trim($requestData['role_name'] ?? '');
            if (empty($roleName)) {
                echo json_encode(['success' => false, 'message' => 'Role name is required.']);
                exit;
            }
            $check = $pdo->prepare("SELECT id FROM worker_roles WHERE LOWER(role_name) = LOWER(:r) LIMIT 1");
            $check->execute([':r' => $roleName]);
            if ($check->fetch()) {
                echo json_encode(['success' => false, 'message' => "Role '{$roleName}' already exists."]);
                exit;
            }
            $ins = $pdo->prepare("INSERT INTO worker_roles (role_name) VALUES (:r)");
            $ins->execute([':r' => $roleName]);

            $roles = $pdo->query("SELECT id, role_name, created_by, created_at FROM worker_roles ORDER BY role_name ASC")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode([
                'success' => true,
                'message' => "Successfully added role: {$roleName}",
                'roles' => $roles,
                'total' => count($roles)
            ]);
            break;

        case 'delete_role':
            $id = intval($requestData['id'] ?? 0);
            $roleName = trim($requestData['role_name'] ?? '');
            if ($id > 0) {
                $del = $pdo->prepare("DELETE FROM worker_roles WHERE id = :id");
                $del->execute([':id' => $id]);
            } elseif (!empty($roleName)) {
                $del = $pdo->prepare("DELETE FROM worker_roles WHERE LOWER(role_name) = LOWER(:r)");
                $del->execute([':r' => $roleName]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Role ID or name is required to delete.']);
                exit;
            }

            $roles = $pdo->query("SELECT id, role_name, created_by, created_at FROM worker_roles ORDER BY role_name ASC")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode([
                'success' => true,
                'message' => 'Role deleted successfully.',
                'roles' => $roles,
                'total' => count($roles)
            ]);
            break;

        // ==========================================
        // 9B. SYSTEM ACCESS ROLES MANAGEMENT
        // ==========================================
        case 'get_system_roles':
            try {
                $sysRoles = $pdo->query("SELECT id, role_name, role_key, access_tier, description, is_system_default, created_by, created_at FROM system_access_roles ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                $sysRoles = [];
            }
            echo json_encode([
                'success' => true,
                'roles' => $sysRoles,
                'total' => count($sysRoles)
            ]);
            break;

        case 'add_system_role':
            $roleName = trim($requestData['role_name'] ?? '');
            $accessTier = strtolower(trim($requestData['access_tier'] ?? 'operator'));
            $desc = trim($requestData['description'] ?? '');

            if (empty($roleName)) {
                echo json_encode(['success' => false, 'message' => 'System role name is required.']);
                exit;
            }
            if (!in_array($accessTier, ['operator', 'supervisor', 'admin'])) {
                $accessTier = 'operator';
            }

            // Generate clean unique role_key
            $roleKey = strtolower(preg_replace('/[^a-zA-Z0-9_]+/', '_', trim($roleName)));
            $roleKey = trim($roleKey, '_');
            if (empty($roleKey)) {
                $roleKey = 'role_' . time();
            }

            $check = $pdo->prepare("SELECT id FROM system_access_roles WHERE LOWER(role_key) = LOWER(:k) OR LOWER(role_name) = LOWER(:n) LIMIT 1");
            $check->execute([':k' => $roleKey, ':n' => $roleName]);
            if ($check->fetch()) {
                echo json_encode(['success' => false, 'message' => "System role '{$roleName}' already exists."]);
                exit;
            }

            $ins = $pdo->prepare("
                INSERT INTO system_access_roles (role_name, role_key, access_tier, description, is_system_default, created_by)
                VALUES (:name, :key, :tier, :desc, 0, :created_by)
            ");
            $ins->execute([
                ':name' => $roleName,
                ':key' => $roleKey,
                ':tier' => $accessTier,
                ':desc' => $desc,
                ':created_by' => $currentUser['username'] ?? 'admin'
            ]);

            $sysRoles = $pdo->query("SELECT id, role_name, role_key, access_tier, description, is_system_default, created_by, created_at FROM system_access_roles ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode([
                'success' => true,
                'message' => "Successfully added system role: {$roleName}",
                'roles' => $sysRoles,
                'new_role_key' => $roleKey,
                'new_role' => [
                    'role_name' => $roleName,
                    'role_key' => $roleKey,
                    'access_tier' => $accessTier,
                    'description' => $desc
                ],
                'total' => count($sysRoles)
            ]);
            break;

        case 'delete_system_role':
            $id = intval($requestData['id'] ?? 0);
            $roleKey = trim($requestData['role_key'] ?? '');

            // Ensure system defaults cannot be deleted
            $checkDef = $pdo->prepare("SELECT is_system_default, role_key FROM system_access_roles WHERE id = :id OR role_key = :k LIMIT 1");
            $checkDef->execute([':id' => $id, ':k' => $roleKey]);
            $foundDef = $checkDef->fetch(PDO::FETCH_ASSOC);

            if ($foundDef && ($foundDef['is_system_default'] == 1 || in_array($foundDef['role_key'], ['operator', 'supervisor', 'admin']))) {
                echo json_encode(['success' => false, 'message' => 'Default system access roles (Operator, Supervisor, Administrator) cannot be deleted.']);
                exit;
            }

            if ($id > 0) {
                $del = $pdo->prepare("DELETE FROM system_access_roles WHERE id = :id AND is_system_default = 0");
                $del->execute([':id' => $id]);
            } elseif (!empty($roleKey)) {
                $del = $pdo->prepare("DELETE FROM system_access_roles WHERE role_key = :k AND is_system_default = 0");
                $del->execute([':k' => $roleKey]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Valid role ID or key is required to delete.']);
                exit;
            }

            $sysRoles = $pdo->query("SELECT id, role_name, role_key, access_tier, description, is_system_default, created_by, created_at FROM system_access_roles ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode([
                'success' => true,
                'message' => 'System role deleted successfully.',
                'roles' => $sysRoles,
                'total' => count($sysRoles)
            ]);
            break;

        // ==========================================
        // 10. PRODUCTION UNITS MANAGEMENT
        // ==========================================
        case 'get_production_units':
            $units = $pdo->query("
                SELECT 
                    pu.*,
                    (SELECT COUNT(*) FROM workers WHERE unit = pu.unit_name) as worker_count,
                    (SELECT COALESCE(SUM(pl.piece_count), 0) 
                     FROM piece_logs pl 
                     JOIN workers w ON pl.worker_id = w.id 
                     WHERE w.unit = pu.unit_name) as total_pieces
                FROM production_units pu
                ORDER BY pu.id ASC
            ")->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'data' => $units,
                'total' => count($units)
            ]);
            break;

        case 'add_production_unit':
            $unit_name = trim($requestData['unit_name'] ?? '');
            $unit_title = trim($requestData['unit_title'] ?? '');
            $unit_code = strtoupper(trim($requestData['unit_code'] ?? ''));
            $description = trim($requestData['description'] ?? '');
            $color = trim($requestData['color'] ?? 'cyan');
            $icon = trim($requestData['icon'] ?? 'fa-industry');

            if (empty($unit_name)) {
                echo json_encode(['success' => false, 'message' => 'Unit Name is required (e.g. Unit 3).']);
                exit;
            }
            if (empty($unit_title)) {
                $unit_title = 'Production ' . $unit_name;
            }
            if (empty($unit_code)) {
                if (preg_match('/(\d+)/', $unit_name, $m)) {
                    $unit_code = 'PU' . sprintf('%03d', (int)$m[1]);
                } else {
                    $unit_code = 'PU' . strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $unit_name), 0, 3));
                }
            }

            // Check duplicate unit_name or unit_code
            $chk = $pdo->prepare("SELECT id FROM production_units WHERE LOWER(unit_name) = LOWER(:name) OR LOWER(unit_code) = LOWER(:code) LIMIT 1");
            $chk->execute([':name' => $unit_name, ':code' => $unit_code]);
            if ($chk->fetch()) {
                echo json_encode(['success' => false, 'message' => "A unit with name '$unit_name' or code '$unit_code' already exists."]);
                exit;
            }

            $stmt = $pdo->prepare("
                INSERT INTO production_units (unit_name, unit_title, unit_code, description, color, icon)
                VALUES (:name, :title, :code, :desc, :color, :icon)
            ");
            $stmt->execute([
                ':name' => $unit_name,
                ':title' => $unit_title,
                ':code' => $unit_code,
                ':desc' => $description,
                ':color' => $color,
                ':icon' => $icon
            ]);

            $newUnitId = $pdo->lastInsertId();

            $units = $pdo->query("
                SELECT 
                    pu.*,
                    (SELECT COUNT(*) FROM workers WHERE unit = pu.unit_name) as worker_count,
                    (SELECT COALESCE(SUM(pl.piece_count), 0) 
                     FROM piece_logs pl 
                     JOIN workers w ON pl.worker_id = w.id 
                     WHERE w.unit = pu.unit_name) as total_pieces
                FROM production_units pu
                ORDER BY pu.id ASC
            ")->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'message' => "Production Unit '$unit_title' ($unit_code) added successfully!",
                'unit_id' => $newUnitId,
                'unit_name' => $unit_name,
                'unit_code' => $unit_code,
                'units' => $units
            ]);
            break;

        case 'delete_production_unit':
            $unitId = intval($requestData['id'] ?? 0);
            $unitName = trim($requestData['unit_name'] ?? '');

            $targetUnit = null;
            if ($unitId > 0) {
                $stmt = $pdo->prepare("SELECT * FROM production_units WHERE id = :id");
                $stmt->execute([':id' => $unitId]);
                $targetUnit = $stmt->fetch(PDO::FETCH_ASSOC);
            } elseif (!empty($unitName)) {
                $stmt = $pdo->prepare("SELECT * FROM production_units WHERE unit_name = :u");
                $stmt->execute([':u' => $unitName]);
                $targetUnit = $stmt->fetch(PDO::FETCH_ASSOC);
            }

            if (!$targetUnit) {
                echo json_encode(['success' => false, 'message' => 'Production unit not found.']);
                exit;
            }

            // Check if workers exist under this unit
            $wCount = $pdo->prepare("SELECT COUNT(*) FROM workers WHERE unit = :u");
            $wCount->execute([':u' => $targetUnit['unit_name']]);
            $assignedWorkers = $wCount->fetchColumn();

            if ($assignedWorkers > 0) {
                echo json_encode([
                    'success' => false, 
                    'message' => "Cannot delete '{$targetUnit['unit_title']}': {$assignedWorkers} worker(s) are currently registered under this unit. Please reassign or delete those workers first."
                ]);
                exit;
            }

            $delStmt = $pdo->prepare("DELETE FROM production_units WHERE id = :id");
            $delStmt->execute([':id' => $targetUnit['id']]);

            $units = $pdo->query("
                SELECT 
                    pu.*,
                    (SELECT COUNT(*) FROM workers WHERE unit = pu.unit_name) as worker_count,
                    (SELECT COALESCE(SUM(pl.piece_count), 0) 
                     FROM piece_logs pl 
                     JOIN workers w ON pl.worker_id = w.id 
                     WHERE w.unit = pu.unit_name) as total_pieces
                FROM production_units pu
                ORDER BY pu.id ASC
            ")->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'message' => "Production Unit '{$targetUnit['unit_title']}' was removed successfully.",
                'units' => $units
            ]);
            break;

        case 'toggle_unit_status':
            $unitId = intval($requestData['id'] ?? 0);
            $unitName = trim($requestData['unit_name'] ?? '');
            $newStatus = trim($requestData['status'] ?? '');

            $targetUnit = null;
            if ($unitId > 0) {
                $stmt = $pdo->prepare("SELECT * FROM production_units WHERE id = :id");
                $stmt->execute([':id' => $unitId]);
                $targetUnit = $stmt->fetch(PDO::FETCH_ASSOC);
            } elseif (!empty($unitName)) {
                $stmt = $pdo->prepare("SELECT * FROM production_units WHERE unit_name = :u");
                $stmt->execute([':u' => $unitName]);
                $targetUnit = $stmt->fetch(PDO::FETCH_ASSOC);
            }

            if (!$targetUnit) {
                echo json_encode(['success' => false, 'message' => 'Production unit not found.']);
                exit;
            }

            if (empty($newStatus)) {
                $newStatus = (isset($targetUnit['status']) && $targetUnit['status'] === 'Disabled') ? 'Active' : 'Disabled';
            } else {
                $newStatus = ($newStatus === 'Disabled') ? 'Disabled' : 'Active';
            }

            $upd = $pdo->prepare("UPDATE production_units SET status = :st WHERE id = :id");
            $upd->execute([':st' => $newStatus, ':id' => $targetUnit['id']]);

            echo json_encode([
                'success' => true,
                'message' => "Production Unit '{$targetUnit['unit_title']}' is now {$newStatus}!",
                'unit_id' => $targetUnit['id'],
                'new_status' => $newStatus
            ]);
            break;

        case 'edit_production_unit':
            $unitId = intval($requestData['id'] ?? 0);
            $unitTitle = trim($requestData['unit_title'] ?? '');
            $description = trim($requestData['description'] ?? '');
            $color = trim($requestData['color'] ?? '');
            $icon = trim($requestData['icon'] ?? '');

            if ($unitId <= 0) {
                echo json_encode(['success' => false, 'message' => 'Unit ID is required.']);
                exit;
            }

            $stmt = $pdo->prepare("SELECT * FROM production_units WHERE id = :id");
            $stmt->execute([':id' => $unitId]);
            $unit = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$unit) {
                echo json_encode(['success' => false, 'message' => 'Unit not found.']);
                exit;
            }

            $unitTitle = !empty($unitTitle) ? $unitTitle : $unit['unit_title'];
            $description = ($description !== '') ? $description : $unit['description'];
            $color = !empty($color) ? $color : $unit['color'];
            $icon = !empty($icon) ? $icon : $unit['icon'];

            $upd = $pdo->prepare("UPDATE production_units SET unit_title = :title, description = :desc, color = :color, icon = :icon WHERE id = :id");
            $upd->execute([
                ':title' => $unitTitle,
                ':desc' => $description,
                ':color' => $color,
                ':icon' => $icon,
                ':id' => $unitId
            ]);

            echo json_encode([
                'success' => true,
                'message' => "Production Unit '$unitTitle' updated successfully!"
            ]);
            break;

        // ==========================================
        // 22. UPDATE USER CREDENTIALS & WORKER PROFILE
        // ==========================================
        case 'update_user_credentials':
            $userId = intval($requestData['user_id'] ?? 0);
            $workerId = intval($requestData['worker_id'] ?? 0);
            $newName = trim($requestData['name'] ?? '');
            $newPhone = preg_replace('/\D/', '', trim($requestData['phone'] ?? ''));
            $newAltPhone = preg_replace('/\D/', '', trim($requestData['alt_phone'] ?? ''));
            $newAge = intval($requestData['age'] ?? 0);
            $newDesignation = trim($requestData['designation'] ?? '');
            $newRole = strtolower(trim($requestData['role'] ?? ''));
            $newPassword = trim($requestData['password'] ?? '');
            $newUnit = trim($requestData['unit'] ?? '');

            if (!$userId && !$workerId) {
                echo json_encode(['success' => false, 'message' => 'User ID or Worker ID is required.']);
                exit;
            }

            // Fetch target user if exists
            $targetUser = null;
            if ($userId > 0) {
                $uStmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
                $uStmt->execute([':id' => $userId]);
                $targetUser = $uStmt->fetch(PDO::FETCH_ASSOC);
            }

            // Fetch target worker if exists
            $targetWorker = null;
            if ($workerId > 0) {
                $wStmt = $pdo->prepare("SELECT * FROM workers WHERE id = :id");
                $wStmt->execute([':id' => $workerId]);
                $targetWorker = $wStmt->fetch(PDO::FETCH_ASSOC);
            } elseif ($targetUser) {
                $wStmt = $pdo->prepare("SELECT * FROM workers WHERE LOWER(unique_id) = LOWER(:u) OR LOWER(name) = LOWER(:n) LIMIT 1");
                $wStmt->execute([':u' => $targetUser['username'], ':n' => $targetUser['full_name']]);
                $targetWorker = $wStmt->fetch(PDO::FETCH_ASSOC);
                if ($targetWorker) {
                    $workerId = (int)$targetWorker['id'];
                }
            }

            // 1. Phone validation (Strict 10 digits if provided)
            if (!empty($newPhone)) {
                if (strlen($newPhone) !== 10) {
                    echo json_encode(['success' => false, 'message' => 'Phone number must be exactly 10 digits.']);
                    exit;
                }
                // Check duplicate in workers
                $dupQuery = "SELECT id, name, unique_id FROM workers WHERE mobile_num = :p";
                $dupParams = [':p' => $newPhone];
                if ($workerId > 0) {
                    $dupQuery .= " AND id != :wid";
                    $dupParams[':wid'] = $workerId;
                }
                $dupStmt = $pdo->prepare($dupQuery);
                $dupStmt->execute($dupParams);
                $dupWorker = $dupStmt->fetch(PDO::FETCH_ASSOC);
                if ($dupWorker) {
                    echo json_encode([
                        'success' => false,
                        'message' => "Phone number {$newPhone} is already registered to worker {$dupWorker['name']} ({$dupWorker['unique_id']})."
                    ]);
                    exit;
                }
            }

            // 2. Alt Phone validation (Strict 10 digits if provided)
            if (!empty($newAltPhone)) {
                if (strlen($newAltPhone) !== 10) {
                    echo json_encode(['success' => false, 'message' => 'Alternate phone number must be exactly 10 digits.']);
                    exit;
                }
            }

            // 3. Password validation (4-6 chars if provided)
            if (!empty($newPassword)) {
                if (strlen($newPassword) < 4 || strlen($newPassword) > 6) {
                    echo json_encode(['success' => false, 'message' => 'Password must be between 4 and 6 characters long.']);
                    exit;
                }
            }

            // 4. Update 'users' table
            if ($targetUser) {
                $userUpdates = [];
                $userParams = [':id' => $targetUser['id']];

                if (!empty($newName)) {
                    $userUpdates[] = "full_name = :name";
                    $userParams[':name'] = $newName;
                }

                if (!empty($newRole)) {
                    // Prevent demoting root admin account
                    if ($targetUser['id'] == 1 || strtolower($targetUser['username']) === 'admin') {
                        $newRole = 'admin';
                    }
                    if (in_array($newRole, ['admin', 'supervisor', 'operator'])) {
                        $userUpdates[] = "role = :role";
                        $userParams[':role'] = $newRole;
                    }
                }

                if (!empty($newUnit)) {
                    $userUpdates[] = "unit = :unit";
                    $userParams[':unit'] = $newUnit;
                }

                if (!empty($newPassword)) {
                    $userUpdates[] = "password = :pass";
                    $userParams[':pass'] = password_hash($newPassword, PASSWORD_DEFAULT);
                }

                if (!empty($userUpdates)) {
                    $sql = "UPDATE users SET " . implode(', ', $userUpdates) . " WHERE id = :id";
                    $upd = $pdo->prepare($sql);
                    $upd->execute($userParams);
                }
            }

            // 5. Update 'workers' table
            if ($targetWorker) {
                $workerUpdates = [];
                $workerParams = [':wid' => $targetWorker['id']];

                if (!empty($newName)) {
                    $workerUpdates[] = "name = :wname";
                    $workerParams[':wname'] = $newName;
                }

                if (!empty($newPhone)) {
                    $workerUpdates[] = "mobile_num = :wmob";
                    $workerParams[':wmob'] = $newPhone;
                }

                if (isset($requestData['alt_phone'])) {
                    $workerUpdates[] = "alt_mobile_num = :waltmob";
                    $workerParams[':waltmob'] = !empty($newAltPhone) ? $newAltPhone : null;
                }

                if ($newAge > 0) {
                    $workerUpdates[] = "age = :wage";
                    $workerParams[':wage'] = $newAge;
                }

                if (!empty($newDesignation)) {
                    $workerUpdates[] = "role = :wrole";
                    $workerParams[':wrole'] = $newDesignation;
                }

                if (!empty($newUnit) && $newUnit !== 'All') {
                    $workerUpdates[] = "unit = :wunit";
                    $workerParams[':wunit'] = $newUnit;
                }

                if (!empty($workerUpdates)) {
                    $wSql = "UPDATE workers SET " . implode(', ', $workerUpdates) . " WHERE id = :wid";
                    $wUpd = $pdo->prepare($wSql);
                    $wUpd->execute($workerParams);
                }
            }

            $disp = !empty($newName) ? $newName : ($targetUser ? $targetUser['username'] : ($targetWorker ? $targetWorker['name'] : 'User'));
            echo json_encode([
                'success' => true,
                'message' => "Profile and credentials for '{$disp}' updated successfully!"
            ]);
            break;

        // ==========================================
        // 23. CLEAR SYSTEM DATA (ADMIN ONLY)
        // ==========================================
        case 'clear_system_data':
            $currentUser = getCurrentUser();
            if (!$currentUser || $currentUser['role'] !== 'admin') {
                echo json_encode(['success' => false, 'message' => 'Admin privileges required to clear system data.']);
                exit;
            }

            $mode = trim($requestData['mode'] ?? 'logs_only'); // logs_only | workers_and_logs | full_reset

            // Automatic timestamped backup before any deletion
            try {
                $backupData = [
                    'backup_time' => date('Y-m-d H:i:s'),
                    'cleared_by' => $currentUser['username'],
                    'mode' => $mode,
                    'workers' => $pdo->query("SELECT * FROM workers")->fetchAll(PDO::FETCH_ASSOC),
                    'piece_logs' => $pdo->query("SELECT * FROM piece_logs")->fetchAll(PDO::FETCH_ASSOC),
                    'users' => $pdo->query("SELECT id, username, full_name, role, unit FROM users")->fetchAll(PDO::FETCH_ASSOC)
                ];
                $backupFilename = __DIR__ . '/backup_cleared_' . date('Ymd_His') . '.json';
                file_put_contents($backupFilename, json_encode($backupData, JSON_PRETTY_PRINT));
            } catch (Exception $be) {
                // Ignore backup save error if file write issue
            }

            $clearedWorkers = 0;
            $clearedLogs = 0;

            if ($mode === 'logs_only') {
                // Clear all piece logs and reset worker piece counts to 0
                $clearedLogs = (int)$pdo->query("SELECT COUNT(*) FROM piece_logs")->fetchColumn();
                $pdo->exec("DELETE FROM piece_logs");
                try { $pdo->exec("ALTER TABLE piece_logs AUTO_INCREMENT = 1"); } catch (Exception $e) {}
                $pdo->exec("UPDATE workers SET total_pieces = 0");
                $msg = "Successfully cleared {$clearedLogs} piece logs. All worker output counts reset to 0 pcs.";
            } elseif ($mode === 'workers_and_logs') {
                // Clear piece logs, workers, and operator user accounts
                $clearedLogs = (int)$pdo->query("SELECT COUNT(*) FROM piece_logs")->fetchColumn();
                $clearedWorkers = (int)$pdo->query("SELECT COUNT(*) FROM workers")->fetchColumn();
                $pdo->exec("DELETE FROM piece_logs");
                try { $pdo->exec("ALTER TABLE piece_logs AUTO_INCREMENT = 1"); } catch (Exception $e) {}
                $pdo->exec("DELETE FROM workers");
                try { $pdo->exec("ALTER TABLE workers AUTO_INCREMENT = 1"); } catch (Exception $e) {}
                $pdo->exec("DELETE FROM users WHERE role = 'operator'");
                $msg = "Successfully cleared {$clearedWorkers} workers and {$clearedLogs} production logs. Operator accounts reset.";
            } elseif ($mode === 'full_reset') {
                // Full factory reset - keep only admin account
                $clearedLogs = (int)$pdo->query("SELECT COUNT(*) FROM piece_logs")->fetchColumn();
                $clearedWorkers = (int)$pdo->query("SELECT COUNT(*) FROM workers")->fetchColumn();
                $pdo->exec("DELETE FROM piece_logs");
                try { $pdo->exec("ALTER TABLE piece_logs AUTO_INCREMENT = 1"); } catch (Exception $e) {}
                $pdo->exec("DELETE FROM workers");
                try { $pdo->exec("ALTER TABLE workers AUTO_INCREMENT = 1"); } catch (Exception $e) {}
                $pdo->exec("DELETE FROM users WHERE role != 'admin'");
                $msg = "Full factory reset complete. Cleared {$clearedWorkers} workers, {$clearedLogs} logs, and non-admin logins.";
            } else {
                echo json_encode(['success' => false, 'message' => 'Invalid clear mode specified.']);
                exit;
            }

            echo json_encode([
                'success' => true,
                'message' => $msg,
                'backup_file' => isset($backupFilename) ? basename($backupFilename) : '',
                'cleared_logs' => $clearedLogs,
                'cleared_workers' => $clearedWorkers
            ]);
            break;

        // ==========================================
        // 24. QUICK ADD USER (ADMIN & SUPERVISOR)
        // ==========================================
        case 'quick_add_user':
            $name = trim($requestData['name'] ?? '');
            $role = strtolower(trim($requestData['role'] ?? 'operator'));
            $unit = trim($requestData['unit'] ?? 'Unit 1');
            $username = trim($requestData['username'] ?? '');
            $phone = trim($requestData['phone'] ?? '');
            $password = trim($requestData['password'] ?? '');

            if (empty($name)) {
                echo json_encode(['success' => false, 'message' => 'Full Name is required.']);
                exit;
            }
            if (!in_array($role, ['admin', 'supervisor', 'operator'])) {
                $role = 'operator';
            }

            // Auto-generate username/ID if not supplied
            if (empty($username)) {
                $prefix = 'PU001';
                $uStmt = $pdo->prepare("SELECT unit_code FROM production_units WHERE unit_name = :unit OR unit_title = :unit LIMIT 1");
                $uStmt->execute([':unit' => $unit]);
                $foundCode = $uStmt->fetchColumn();
                if ($foundCode) {
                    $prefix = strtoupper(trim($foundCode));
                } elseif (preg_match('/(\d+)/', $unit, $m)) {
                    $prefix = 'PU' . sprintf('%03d', (int)$m[1]);
                } else {
                    $prefix = ($unit === 'Unit 2') ? 'PU002' : (($unit === 'Unit 1') ? 'PU001' : 'USR');
                }

                if ($role === 'supervisor') {
                    if (preg_match('/(\d+)/', $unit, $m)) {
                        $prefix = 'UNIT' . (int)$m[1] . '_LEAD';
                    } else {
                        $prefix = 'SUP';
                    }
                }

                $stmt = $pdo->prepare("SELECT unique_id FROM workers WHERE unit = :unit OR unique_id LIKE :prefix_like");
                $stmt->execute([':unit' => $unit, ':prefix_like' => $prefix . '%']);
                $allIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
                $maxNum = 0;
                foreach ($allIds as $existingId) {
                    if (preg_match('/^' . preg_quote($prefix, '/') . '[-_]?(\d+)$/i', trim($existingId), $m)) {
                        $num = (int)$m[1];
                        if ($num > $maxNum) $maxNum = $num;
                    }
                }
                $username = $prefix . '-' . ($maxNum + 1);
            }

            // Check if username already exists
            $uCheck = $pdo->prepare("SELECT id FROM users WHERE LOWER(username) = LOWER(:u)");
            $uCheck->execute([':u' => $username]);
            if ($uCheck->fetch()) {
                echo json_encode(['success' => false, 'message' => "Username '$username' already exists. Please choose a different username."]);
                exit;
            }

            if (empty($password)) {
                $password = '1234';
            }
            if (strlen($password) < 4 || strlen($password) > 6) {
                echo json_encode(['success' => false, 'message' => 'Password must be between 4 and 6 characters long.']);
                exit;
            }

            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $insUser = $pdo->prepare("
                INSERT INTO users (username, password, full_name, role, unit)
                VALUES (:u, :p, :n, :r, :unit)
            ");
            $insUser->execute([
                ':u' => $username,
                ':p' => $hashed,
                ':n' => $name,
                ':r' => $role,
                ':unit' => $unit
            ]);
            $newUserId = $pdo->lastInsertId();

            // Link worker profile if operator or has phone number
            if ($role === 'operator' || !empty($phone)) {
                try {
                    $designation = ($role === 'operator') ? 'Machine Operator' : ucfirst($role) . ' Lead';
                    $wIns = $pdo->prepare("
                        INSERT INTO workers (unique_id, name, unit, age, mobile_num, address, role, experience_years, total_pieces, status)
                        VALUES (:uid, :n, :u, 26, :mob, 'Factory Floor', :role_name, 1.5, 0, 'Active')
                    ");
                    $wIns->execute([
                        ':uid' => $username,
                        ':n' => $name,
                        ':u' => ($unit === 'All') ? 'Unit 1' : $unit,
                        ':mob' => !empty($phone) ? $phone : 'N/A',
                        ':role_name' => $designation
                    ]);
                } catch (Exception $we) {}
            }

            echo json_encode([
                'success' => true,
                'message' => "User '$name' ($username) successfully registered!",
                'user_id' => $newUserId,
                'username' => $username
            ]);
            break;

        // ==========================================
        // TOGGLE USER / WORKER ACTIVE STATUS
        // ==========================================
        case 'toggle_user_status':
            $currentUser = getCurrentUser();
            if (!$currentUser || !in_array($currentUser['role'], ['admin', 'supervisor'])) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Unauthorized action.']);
                exit;
            }

            $userId = (int)($requestData['user_id'] ?? 0);
            $workerId = (int)($requestData['worker_id'] ?? 0);
            $username = trim($requestData['username'] ?? '');
            $newStatus = trim($requestData['new_status'] ?? '');

            // Prevent deactivating root administrator
            if ($userId === 1 || strcasecmp($username, 'admin') === 0) {
                echo json_encode(['success' => false, 'message' => 'Primary Administrator account cannot be deactivated.']);
                exit;
            }

            // Find current status if not supplied
            $currentStatus = 'Active';
            if ($workerId > 0) {
                $wStmt = $pdo->prepare("SELECT unique_id, status FROM workers WHERE id = :id");
                $wStmt->execute([':id' => $workerId]);
                $wRow = $wStmt->fetch(PDO::FETCH_ASSOC);
                if ($wRow) {
                    $currentStatus = $wRow['status'] ?? 'Active';
                    if (empty($username)) $username = $wRow['unique_id'];
                }
            } elseif ($userId > 0) {
                $uStmt = $pdo->prepare("SELECT username, status FROM users WHERE id = :id");
                $uStmt->execute([':id' => $userId]);
                $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
                if ($uRow) {
                    $currentStatus = $uRow['status'] ?? 'Active';
                    if (empty($username)) $username = $uRow['username'];
                }
            }

            if (empty($newStatus)) {
                $newStatus = (strcasecmp($currentStatus, 'Active') === 0) ? 'Inactive' : 'Active';
            } else {
                $newStatus = (strcasecmp($newStatus, 'Inactive') === 0) ? 'Inactive' : 'Active';
            }

            // Update workers table
            if ($workerId > 0) {
                $updW = $pdo->prepare("UPDATE workers SET status = :st WHERE id = :id");
                $updW->execute([':st' => $newStatus, ':id' => $workerId]);
            }
            if (!empty($username)) {
                $updW2 = $pdo->prepare("UPDATE workers SET status = :st WHERE LOWER(unique_id) = LOWER(:u) OR LOWER(name) = LOWER(:u)");
                $updW2->execute([':st' => $newStatus, ':u' => $username]);
            }

            // Update users table
            if ($userId > 0) {
                $updU2 = $pdo->prepare("UPDATE users SET status = :st WHERE id = :id");
                $updU2->execute([':st' => $newStatus, ':id' => $userId]);
            }
            if (!empty($username)) {
                $updU = $pdo->prepare("UPDATE users SET status = :st WHERE LOWER(username) = LOWER(:u) OR LOWER(full_name) = LOWER(:u)");
                $updU->execute([':st' => $newStatus, ':u' => $username]);
            }

            echo json_encode([
                'success' => true,
                'new_status' => $newStatus,
                'message' => "User status updated to '$newStatus'."
            ]);
            break;

        // ==========================================
        // TRANSFER USER / WORKER PRODUCTION UNIT (EMERGENCY & PERMANENT)
        // ==========================================
        case 'transfer_user_unit':
            $currentUser = getCurrentUser();
            if (!$currentUser || !in_array($currentUser['role'], ['admin', 'supervisor'])) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Unauthorized: Administrator or Supervisor privileges required.']);
                exit;
            }

            $userId = (int)($requestData['user_id'] ?? 0);
            $workerId = (int)($requestData['worker_id'] ?? 0);
            $username = trim($requestData['username'] ?? '');
            $newUnit = trim($requestData['new_unit'] ?? '');
            $transferType = trim($requestData['transfer_type'] ?? 'temporary'); // 'temporary' or 'permanent'
            $notes = trim($requestData['notes'] ?? '');

            if (empty($newUnit)) {
                echo json_encode(['success' => false, 'message' => 'Target production unit must be specified.']);
                exit;
            }

            // Verify production unit exists or is valid
            $uCheck = $pdo->prepare("SELECT unit_name, unit_title FROM production_units WHERE unit_name = :u OR unit_title = :u LIMIT 1");
            $uCheck->execute([':u' => $newUnit]);
            $unitRow = $uCheck->fetch(PDO::FETCH_ASSOC);
            $canonicalUnit = $unitRow ? $unitRow['unit_name'] : $newUnit;

            // Fetch current user / worker info
            $oldUnit = 'Unknown';
            $workerName = '';

            if ($workerId > 0) {
                $wStmt = $pdo->prepare("SELECT id, unique_id, name, unit FROM workers WHERE id = :id");
                $wStmt->execute([':id' => $workerId]);
                $w = $wStmt->fetch(PDO::FETCH_ASSOC);
                if ($w) {
                    $oldUnit = $w['unit'];
                    $workerName = $w['name'];
                    if (empty($username)) $username = $w['unique_id'];
                }
            }
            if ($userId > 0 && empty($workerName)) {
                $uStmt = $pdo->prepare("SELECT id, username, full_name, unit FROM users WHERE id = :id");
                $uStmt->execute([':id' => $userId]);
                $u = $uStmt->fetch(PDO::FETCH_ASSOC);
                if ($u) {
                    if ($oldUnit === 'Unknown') $oldUnit = $u['unit'];
                    $workerName = $u['full_name'];
                    if (empty($username)) $username = $u['username'];
                }
            }

            // If username is provided, resolve worker if not resolved
            if ($workerId === 0 && !empty($username)) {
                $wLookup = $pdo->prepare("SELECT id, unit, name FROM workers WHERE LOWER(unique_id) = LOWER(:u)");
                $wLookup->execute([':u' => $username]);
                $wFound = $wLookup->fetch(PDO::FETCH_ASSOC);
                if ($wFound) {
                    $workerId = (int)$wFound['id'];
                    if ($oldUnit === 'Unknown') $oldUnit = $wFound['unit'];
                    if (empty($workerName)) $workerName = $wFound['name'];
                }
            }

            // Prevent transferring to the exact same unit
            if (!empty($oldUnit) && $oldUnit !== 'Unknown') {
                $oldNorm = preg_replace('/[^a-z0-9]/', '', strtolower($oldUnit));
                $newNorm = preg_replace('/[^a-z0-9]/', '', strtolower($canonicalUnit));
                $rawNorm = preg_replace('/[^a-z0-9]/', '', strtolower($newUnit));
                if ($oldNorm === $newNorm || $oldNorm === $rawNorm) {
                    echo json_encode([
                        'success' => false, 
                        'message' => "User is already assigned to {$oldUnit}. Target unit must be different from current unit."
                    ]);
                    exit;
                }
            }

            // 1. Update workers table
            if ($workerId > 0) {
                try {
                    $updW = $pdo->prepare("UPDATE workers SET unit = :unit, origin_unit = COALESCE(NULLIF(origin_unit, ''), :old_unit), transferred_from = :from_unit, transfer_type = :ttype, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
                    $updW->execute([':unit' => $canonicalUnit, ':old_unit' => $oldUnit, ':from_unit' => $oldUnit, ':ttype' => $transferType, ':id' => $workerId]);
                } catch (Exception $e) {
                    $updW = $pdo->prepare("UPDATE workers SET unit = :unit, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
                    $updW->execute([':unit' => $canonicalUnit, ':id' => $workerId]);
                }
            }
            if (!empty($username)) {
                try {
                    $updW2 = $pdo->prepare("UPDATE workers SET unit = :unit, origin_unit = COALESCE(NULLIF(origin_unit, ''), :old_unit), transferred_from = :from_unit, transfer_type = :ttype, updated_at = CURRENT_TIMESTAMP WHERE LOWER(unique_id) = LOWER(:u)");
                    $updW2->execute([':unit' => $canonicalUnit, ':old_unit' => $oldUnit, ':from_unit' => $oldUnit, ':ttype' => $transferType, ':u' => $username]);
                } catch (Exception $e) {
                    $updW2 = $pdo->prepare("UPDATE workers SET unit = :unit, updated_at = CURRENT_TIMESTAMP WHERE LOWER(unique_id) = LOWER(:u)");
                    $updW2->execute([':unit' => $canonicalUnit, ':u' => $username]);
                }

                // 2. Update users table
                $updU = $pdo->prepare("UPDATE users SET unit = :unit WHERE LOWER(username) = LOWER(:u)");
                $updU->execute([':unit' => $canonicalUnit, ':u' => $username]);
            } elseif ($userId > 0) {
                $updU2 = $pdo->prepare("UPDATE users SET unit = :unit WHERE id = :id");
                $updU2->execute([':unit' => $canonicalUnit, ':id' => $userId]);
            }

            $transferDesc = ($transferType === 'permanent') ? 'Permanent Assignment' : 'Emergency Temporary Support';
            $auditMsg = "Transferred from {$oldUnit} to {$canonicalUnit} ({$transferDesc}). " . ($notes ? "Reason: {$notes}" : "");

            echo json_encode([
                'success' => true,
                'message' => "Successfully transferred {$workerName} to {$canonicalUnit} ({$transferDesc}).",
                'user_id' => $userId,
                'worker_id' => $workerId,
                'username' => $username,
                'old_unit' => $oldUnit,
                'new_unit' => $canonicalUnit,
                'transfer_type' => $transferType,
                'audit' => $auditMsg
            ]);
            break;

        // ==========================================
        // DELETE USER AND WORKER
        // ==========================================
        case 'delete_user_worker':
            $currentUser = getCurrentUser();
            if (!$currentUser || $currentUser['role'] !== 'admin') {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Admin privileges required.']);
                exit;
            }

            $userId = (int)($requestData['user_id'] ?? 0);
            $workerId = (int)($requestData['worker_id'] ?? 0);
            $username = trim($requestData['username'] ?? '');

            // Protect root admin account
            if ($userId === 1 || strcasecmp($username, 'admin') === 0) {
                echo json_encode(['success' => false, 'message' => 'The root Administrator account cannot be deleted.']);
                exit;
            }

            // Find worker and user details
            if ($userId > 0 && empty($username)) {
                $uStmt = $pdo->prepare("SELECT username FROM users WHERE id = :id");
                $uStmt->execute([':id' => $userId]);
                $username = $uStmt->fetchColumn() ?: '';
                if (strcasecmp($username, 'admin') === 0) {
                    echo json_encode(['success' => false, 'message' => 'The root Administrator account cannot be deleted.']);
                    exit;
                }
            }

            if ($workerId > 0) {
                // Delete piece logs for this worker
                $pdo->prepare("DELETE FROM piece_logs WHERE worker_id = :wid")->execute([':wid' => $workerId]);
                // Delete worker record
                $pdo->prepare("DELETE FROM workers WHERE id = :wid")->execute([':wid' => $workerId]);
            }

            if (!empty($username)) {
                // Find worker by unique_id if not already deleted
                $wRow = $pdo->prepare("SELECT id FROM workers WHERE LOWER(unique_id) = LOWER(:u)");
                $wRow->execute([':u' => $username]);
                $wid = $wRow->fetchColumn();
                if ($wid) {
                    $pdo->prepare("DELETE FROM piece_logs WHERE worker_id = :wid")->execute([':wid' => $wid]);
                    $pdo->prepare("DELETE FROM workers WHERE id = :wid")->execute([':wid' => $wid]);
                }
                // Delete from users table
                $pdo->prepare("DELETE FROM users WHERE LOWER(username) = LOWER(:u)")->execute([':u' => $username]);
            } elseif ($userId > 0) {
                $pdo->prepare("DELETE FROM users WHERE id = :id")->execute([':id' => $userId]);
            }

            echo json_encode([
                'success' => true,
                'message' => 'User deleted successfully.'
            ]);
            break;

        // ==========================================
        // TOGGLE WORKER ACTIVE STATUS (LOGIN SIMULATION / OVERRIDE)
        // ==========================================
        case 'toggle_worker_active':
            $workerId = (int)($requestData['worker_id'] ?? 0);
            if ($workerId <= 0) {
                echo json_encode(['success' => false, 'message' => 'Worker ID is required.']);
                exit;
            }

            $wStmt = $pdo->prepare("SELECT id, name, unique_id, last_login FROM workers WHERE id = :wid");
            $wStmt->execute([':wid' => $workerId]);
            $worker = $wStmt->fetch(PDO::FETCH_ASSOC);

            if (!$worker) {
                echo json_encode(['success' => false, 'message' => 'Worker not found.']);
                exit;
            }

            $isActiveToday = (!empty($worker['last_login']) && date('Y-m-d', strtotime($worker['last_login'])) === date('Y-m-d'));

            if (isset($requestData['status'])) {
                $statusVal = strtolower(trim((string)$requestData['status']));
                $makeActive = in_array($statusVal, ['1', 'true', 'active']);
            } else {
                $makeActive = !$isActiveToday;
            }

            $now = $makeActive ? date('Y-m-d H:i:s') : null;

            $pdo->prepare("UPDATE workers SET last_login = :ll WHERE id = :wid")->execute([':ll' => $now, ':wid' => $workerId]);
            $pdo->prepare("UPDATE users SET last_login = :ll WHERE LOWER(username) = LOWER(:uid) OR LOWER(full_name) = LOWER(:name)")
                ->execute([':ll' => $now, ':uid' => $worker['unique_id'], ':name' => $worker['name']]);

            echo json_encode([
                'success' => true,
                'is_active' => $makeActive,
                'last_login' => $now,
                'formatted_time' => $makeActive ? date('h:i A') : null,
                'worker_id' => $workerId,
                'worker_name' => $worker['name'],
                'message' => $makeActive ? "Worker '{$worker['name']}' is now active for today." : "Worker '{$worker['name']}' is marked inactive for today."
            ]);
            break;

        // ==========================================
        // ASSIGN WORK CATEGORY TO WORKER
        // ==========================================
        case 'assign_worker_category':
            $workerId = (int)($requestData['worker_id'] ?? 0);
            $userId = (int)($requestData['user_id'] ?? 0);
            $username = trim($requestData['username'] ?? '');
            $notes = trim($requestData['notes'] ?? '');

            // Fallback resolution for workerId from userId or username
            if ($workerId <= 0 && $userId > 0) {
                $wFind = $pdo->prepare("
                    SELECT w.id FROM workers w 
                    JOIN users u ON (
                        LOWER(u.username) = LOWER(w.unique_id) 
                        OR REPLACE(u.username, '-', '') = REPLACE(w.unique_id, '-', '') 
                        OR (
                            LOWER(u.full_name) = LOWER(w.name)
                            AND NOT EXISTS (
                                SELECT 1 FROM workers w_sub 
                                WHERE LOWER(w_sub.unique_id) = LOWER(u.username) 
                                   OR REPLACE(w_sub.unique_id, '-', '') = REPLACE(u.username, '-', '')
                            )
                        )
                    ) 
                    WHERE u.id = :uid LIMIT 1
                ");
                $wFind->execute([':uid' => $userId]);
                $foundWid = $wFind->fetchColumn();
                if ($foundWid) {
                    $workerId = (int)$foundWid;
                }
            }
            if ($workerId <= 0 && !empty($username)) {
                $wFind = $pdo->prepare("
                    SELECT id FROM workers 
                    WHERE LOWER(unique_id) = LOWER(:u) OR REPLACE(unique_id, '-', '') = REPLACE(:u, '-', '') 
                    LIMIT 1
                ");
                $wFind->execute([':u' => $username]);
                $foundWid = $wFind->fetchColumn();
                if (!$foundWid) {
                    $wFind2 = $pdo->prepare("SELECT id FROM workers WHERE LOWER(name) = LOWER(:u) ORDER BY id DESC LIMIT 1");
                    $wFind2->execute([':u' => $username]);
                    $foundWid = $wFind2->fetchColumn();
                }
                if ($foundWid) {
                    $workerId = (int)$foundWid;
                }
            }

            if ($workerId <= 0) {
                echo json_encode(['success' => false, 'message' => 'Worker or registered user profile not found.']);
                exit;
            }

            $wStmt = $pdo->prepare("SELECT id, name, unique_id, unit FROM workers WHERE id = :wid");
            $wStmt->execute([':wid' => $workerId]);
            $worker = $wStmt->fetch(PDO::FETCH_ASSOC);

            if (!$worker) {
                echo json_encode(['success' => false, 'message' => 'Worker not found.']);
                exit;
            }

            // Support either multiple categories array or single category
            $categoriesToAssign = [];
            if (!empty($requestData['categories']) && is_array($requestData['categories'])) {
                $categoriesToAssign = $requestData['categories'];
            } elseif (!empty($requestData['category_ids']) && is_array($requestData['category_ids'])) {
                foreach ($requestData['category_ids'] as $cid) {
                    $categoriesToAssign[] = ['category_id' => (int)$cid];
                }
            } elseif (!empty($requestData['category_id']) || !empty($requestData['category_name'])) {
                $categoriesToAssign[] = [
                    'category_id' => !empty($requestData['category_id']) ? (int)$requestData['category_id'] : null,
                    'category_name' => trim($requestData['category_name'] ?? ''),
                    'subcategory_name' => trim($requestData['subcategory_name'] ?? ''),
                    'sub_subcategory_name' => trim($requestData['sub_subcategory_name'] ?? '')
                ];
            }

            $today = date('Y-m-d');
            $now = date('Y-m-d H:i:s');
            $assignedBy = $currentUser['username'] ?? 'admin';

            // Validation: Work can strictly be assigned ONLY to operators/users who have logged in today!
            $effLogin = !empty($worker['last_login']) ? $worker['last_login'] : null;
            if (empty($effLogin)) {
                $uStmt = $pdo->prepare("SELECT last_login FROM users WHERE LOWER(username) = LOWER(:uid) OR LOWER(full_name) = LOWER(:name) LIMIT 1");
                $uStmt->execute([':uid' => $worker['unique_id'], ':name' => $worker['name']]);
                $effLogin = $uStmt->fetchColumn();
            }

            $isLoggedInToday = (!empty($effLogin) && date('Y-m-d', strtotime($effLogin)) === $today);
            if (!$isLoggedInToday && !empty($categoriesToAssign)) {
                echo json_encode([
                    'success' => false,
                    'message' => "Validation Error: {$worker['name']} ({$worker['unique_id']}) has not logged in today. Work can only be assigned to operators who have logged in today."
                ]);
                exit;
            }

            // If empty categories passed, admin is clearing today's assignment
            if (empty($categoriesToAssign)) {
                $pdo->prepare("UPDATE worker_work_assignments SET status = 'Inactive' WHERE worker_id = :wid AND assigned_date = :adate")
                    ->execute([':wid' => $workerId, ':adate' => $today]);

                $pdo->prepare("UPDATE workers SET assigned_work = NULL, assigned_category_id = NULL, assigned_at = :now WHERE id = :wid")
                    ->execute([':now' => $now, ':wid' => $workerId]);

                echo json_encode([
                    'success' => true,
                    'worker_id' => $workerId,
                    'user_id' => $userId,
                    'worker_name' => $worker['name'],
                    'assigned_work' => '',
                    'assigned_categories' => [],
                    'count' => 0,
                    'message' => "Cleared work assignment for {$worker['name']}."
                ]);
                exit;
            }

            // Deactivate previous active assignments for this worker for today to prevent duplicates
            $pdo->prepare("UPDATE worker_work_assignments SET status = 'Inactive' WHERE worker_id = :wid AND assigned_date = :adate")
                ->execute([':wid' => $workerId, ':adate' => $today]);

            $assignedCategoryNames = [];
            $assignedDisplayParts = [];
            $firstCatId = null;
            $insertedIds = [];

            $insStmt = $pdo->prepare("
                INSERT INTO worker_work_assignments 
                    (worker_id, category_id, category_name, subcategory_name, sub_subcategory_name, assigned_date, assigned_by, status, notes)
                VALUES 
                    (:wid, :cid, :cname, :subname, :subsubname, :adate, :aby, 'Active', :notes)
            ");

            foreach ($categoriesToAssign as $cItem) {
                $cid = !empty($cItem['category_id']) ? (int)$cItem['category_id'] : (!empty($cItem['id']) ? (int)$cItem['id'] : null);
                $cname = trim($cItem['category_name'] ?? '');
                $subname = trim($cItem['subcategory_name'] ?? '');
                $subsubname = trim($cItem['sub_subcategory_name'] ?? '');

                if ($cid && (empty($cname) || empty($subname))) {
                    $cFetch = $pdo->prepare("SELECT category_name, subcategory_name, sub_subcategory_name FROM garment_categories WHERE id = :cid LIMIT 1");
                    $cFetch->execute([':cid' => $cid]);
                    $row = $cFetch->fetch(PDO::FETCH_ASSOC);
                    if ($row) {
                        if (empty($cname)) $cname = $row['category_name'];
                        if (empty($subname)) $subname = $row['subcategory_name'];
                        if (empty($subsubname)) $subsubname = $row['sub_subcategory_name'] ?? '';
                    }
                }

                if (empty($cname)) continue;

                if ($firstCatId === null && $cid) {
                    $firstCatId = $cid;
                }

                if (!in_array($cname, $assignedCategoryNames, true)) {
                    $assignedCategoryNames[] = $cname;
                }

                $part = $cname . ($subname ? " - {$subname}" : "");
                if (!in_array($part, $assignedDisplayParts, true)) {
                    $assignedDisplayParts[] = $part;
                }

                $insStmt->execute([
                    ':wid' => $workerId,
                    ':cid' => $cid,
                    ':cname' => $cname,
                    ':subname' => $subname,
                    ':subsubname' => $subsubname,
                    ':adate' => $today,
                    ':aby' => $assignedBy,
                    ':notes' => $notes
                ]);
                $insertedIds[] = $pdo->lastInsertId();
            }

            if (empty($assignedCategoryNames)) {
                echo json_encode(['success' => false, 'message' => 'Please select at least one valid garment category.']);
                exit;
            }

            // Build assigned work summary string (e.g. "Nighty, Kurti")
            $assignedWorkStr = implode(', ', $assignedCategoryNames);

            $updWorker = $pdo->prepare("
                UPDATE workers 
                SET assigned_work = :work, assigned_category_id = :cid, assigned_at = :now 
                WHERE id = :wid
            ");
            $updWorker->execute([
                ':work' => $assignedWorkStr,
                ':cid' => $firstCatId,
                ':now' => $now,
                ':wid' => $workerId
            ]);

            echo json_encode([
                'success' => true,
                'assignment_ids' => $insertedIds,
                'worker_id' => $workerId,
                'user_id' => $userId,
                'worker_name' => $worker['name'],
                'category_name' => $assignedCategoryNames[0],
                'assigned_categories' => $assignedCategoryNames,
                'assigned_work' => $assignedWorkStr,
                'assigned_at' => $now,
                'count' => count($assignedCategoryNames),
                'formatted_time' => date('h:i A'),
                'message' => count($assignedCategoryNames) . " Category(s) (" . implode(', ', $assignedCategoryNames) . ") assigned to {$worker['name']} ({$worker['unique_id']}) successfully!"
            ]);
            break;

        // ==========================================
        // GET WORKER ASSIGNED WORK & ACTIVE CATEGORIES
        // ==========================================
        case 'get_worker_assigned_work':
            $workerId = (int)($requestData['worker_id'] ?? 0);
            if ($workerId <= 0) {
                echo json_encode(['success' => false, 'message' => 'Worker ID is required.']);
                exit;
            }

            $wStmt = $pdo->prepare("
                SELECT w.id, w.name, w.unique_id, w.unit, w.role, w.assigned_work, w.assigned_category_id, w.assigned_at, w.last_login,
                       (SELECT last_login FROM users WHERE LOWER(username) = LOWER(w.unique_id) OR REPLACE(username, '-', '') = REPLACE(w.unique_id, '-', '') OR (LOWER(full_name) = LOWER(w.name) AND NOT EXISTS (SELECT 1 FROM users u_sub WHERE LOWER(u_sub.username) = LOWER(w.unique_id))) LIMIT 1) as user_last_login
                FROM workers w 
                WHERE w.id = :wid
            ");
            $wStmt->execute([':wid' => $workerId]);
            $worker = $wStmt->fetch(PDO::FETCH_ASSOC);

            if (!$worker) {
                echo json_encode(['success' => false, 'message' => 'Worker not found.']);
                exit;
            }

            $effectiveLogin = $worker['last_login'] ?: $worker['user_last_login'];
            $isActiveToday = (!empty($effectiveLogin) && date('Y-m-d', strtotime($effectiveLogin)) === date('Y-m-d'));

            // Fetch today's active assignments strictly for today
            $todayStmt = $pdo->prepare("
                SELECT * FROM worker_work_assignments 
                WHERE worker_id = :wid AND assigned_date = CURDATE() AND status = 'Active' 
                ORDER BY id ASC
            ");
            $todayStmt->execute([':wid' => $workerId]);
            $todayAssignments = $todayStmt->fetchAll(PDO::FETCH_ASSOC);

            $assignedCategoryNames = [];
            $assignedCategoryIds = [];
            foreach ($todayAssignments as $ta) {
                if (!empty($ta['category_name']) && !in_array($ta['category_name'], $assignedCategoryNames, true)) {
                    $assignedCategoryNames[] = $ta['category_name'];
                }
                if (!empty($ta['category_id']) && !in_array((int)$ta['category_id'], $assignedCategoryIds, true)) {
                    $assignedCategoryIds[] = (int)$ta['category_id'];
                }
            }

            $assignStmt = $pdo->prepare("
                SELECT * FROM worker_work_assignments 
                WHERE worker_id = :wid 
                ORDER BY id DESC LIMIT 10
            ");
            $assignStmt->execute([':wid' => $workerId]);
            $history = $assignStmt->fetchAll(PDO::FETCH_ASSOC);

            $categoriesStmt = $pdo->query("SELECT id, category_name, subcategory_name, sub_subcategory_name, status FROM garment_categories WHERE status = 'Active' ORDER BY category_name ASC, subcategory_name ASC");
            $allCats = $categoriesStmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'worker' => [
                    'id' => (int)$worker['id'],
                    'name' => $worker['name'],
                    'unique_id' => $worker['unique_id'],
                    'unit' => $worker['unit'],
                    'role' => $worker['role'],
                    'assigned_work' => $worker['assigned_work'],
                    'assigned_category_id' => $worker['assigned_category_id'],
                    'assigned_at' => $worker['assigned_at'],
                    'last_login' => $effectiveLogin,
                    'is_active_today' => $isActiveToday,
                    'formatted_login' => (!empty($effectiveLogin) ? date('h:i A', strtotime($effectiveLogin)) : null)
                ],
                'today_assignments' => $todayAssignments,
                'assigned_category_names' => $assignedCategoryNames,
                'assigned_category_ids' => $assignedCategoryIds,
                'history' => $history,
                'categories' => $allCats
            ]);
            break;

        // ==========================================
        // DEFAULT FALLBACK
        // ==========================================
        default:
            echo json_encode([
                'success' => false,
                'message' => 'Invalid action parameter.',
                'available_actions' => [
                    'check_mobile', 'register_worker', 'get_next_worker_id', 'log_pieces',
                    'get_workers', 'get_cloth_leaderboard', 'get_leads', 'get_materials',
                    'get_stats', 'get_worker_details', 'get_categories', 'add_category',
                    'toggle_category_status', 'delete_category', 'get_roles', 'add_role',
                    'delete_role', 'get_production_units', 'add_production_unit',
                    'delete_production_unit', 'toggle_unit_status', 'edit_production_unit',
                    'update_user_credentials', 'clear_system_data', 'quick_add_user',
                    'toggle_user_status', 'delete_user_worker'
                ]
            ]);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server Error: ' . $e->getMessage()
    ]);
}
