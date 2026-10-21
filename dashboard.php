<?php
// dashboard.php - Central Production & Operational Dashboard for FabriqPro
$pageTitle = 'Production Dashboard & History - FabriqPro';
require_once __DIR__ . '/includes/auth.php';
requireAuth();

$currentUser = getCurrentUser();
// Operators are directed to their personal stitching console
if ($currentUser && $currentUser['role'] === 'operator') {
    $target = !empty($currentUser['worker_id']) ? "daily_output.php?worker_id=" . (int)$currentUser['worker_id'] : "daily_output.php";
    header("Location: " . $target);
    exit;
}

$pdo = Database::getConnection();

// =========================================================================
// 1. DATE & PERIOD FILTER RESOLUTION
// =========================================================================
$todayDate     = date('Y-m-d');
$yesterdayDate = date('Y-m-d', strtotime('-1 day'));
$thisMonthStr  = date('Y-m');
$thisYearStr   = date('Y');

// Extract query parameters
$paramPeriod   = trim($_GET['period'] ?? '');
$paramDate     = trim($_GET['date'] ?? '');
$paramMonth    = trim($_GET['month'] ?? '');
$paramYear     = trim($_GET['year'] ?? '');
$paramFromDate = trim($_GET['from_date'] ?? '');
$paramToDate   = trim($_GET['to_date'] ?? '');
$paramShift    = trim($_GET['shift'] ?? '');
$paramUnit     = trim($_GET['unit'] ?? '');

$startDate = null;
$endDate   = null;
$activePeriodKey = 'today';
$periodLabel = 'Today (' . date('M j, Y') . ')';

// Determine active timeframe
if (!empty($paramDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $paramDate)) {
    // Specific single date
    $startDate = $paramDate;
    $endDate   = $paramDate;
    $activePeriodKey = 'date';
    if ($paramDate === $todayDate) {
        $periodLabel = 'Today (' . date('M j, Y') . ')';
        $activePeriodKey = 'today';
    } elseif ($paramDate === $yesterdayDate) {
        $periodLabel = 'Yesterday (' . date('M j, Y', strtotime($paramDate)) . ')';
        $activePeriodKey = 'yesterday';
    } else {
        $periodLabel = date('M j, Y', strtotime($paramDate));
    }
} elseif (!empty($paramMonth) && preg_match('/^\d{4}-\d{2}$/', $paramMonth)) {
    // Specific month
    $startDate = $paramMonth . '-01';
    $endDate   = date('Y-m-t', strtotime($startDate));
    $activePeriodKey = 'month';
    $periodLabel = ($paramMonth === $thisMonthStr)
        ? 'Whole Month (' . date('F Y') . ')'
        : 'Month: ' . date('F Y', strtotime($startDate));
} elseif (!empty($paramYear) && preg_match('/^\d{4}$/', $paramYear)) {
    // Specific year
    $startDate = $paramYear . '-01-01';
    $endDate   = $paramYear . '-12-31';
    $activePeriodKey = 'year';
    $periodLabel = ($paramYear === $thisYearStr)
        ? 'Whole Year (' . $paramYear . ')'
        : 'Year: ' . $paramYear;
} elseif (!empty($paramFromDate) && !empty($paramToDate)) {
    // Custom date range
    $startDate = $paramFromDate;
    $endDate   = $paramToDate;
    $activePeriodKey = 'range';
    $periodLabel = date('M j, Y', strtotime($startDate)) . ' to ' . date('M j, Y', strtotime($endDate));
} elseif ($paramPeriod === 'yesterday') {
    $startDate = $yesterdayDate;
    $endDate   = $yesterdayDate;
    $activePeriodKey = 'yesterday';
    $periodLabel = 'Yesterday (' . date('M j, Y', strtotime($yesterdayDate)) . ')';
} elseif ($paramPeriod === 'this_month') {
    $startDate = date('Y-m-01');
    $endDate   = date('Y-m-t');
    $activePeriodKey = 'this_month';
    $paramMonth = $thisMonthStr;
    $periodLabel = 'Whole Month (' . date('F Y') . ')';
} elseif ($paramPeriod === 'this_year') {
    $startDate = date('Y-01-01');
    $endDate   = date('Y-12-31');
    $activePeriodKey = 'this_year';
    $paramYear = $thisYearStr;
    $periodLabel = 'Whole Year (' . $thisYearStr . ')';
} elseif ($paramPeriod === 'all') {
    // Complete history (no date filter)
    $startDate = null;
    $endDate   = null;
    $activePeriodKey = 'all';
    $periodLabel = 'Complete Plant History (All-Time)';
} else {
    // Default: Today
    $startDate = $todayDate;
    $endDate   = $todayDate;
    $activePeriodKey = 'today';
    $paramDate = $todayDate;
    $periodLabel = 'Today (' . date('M j, Y') . ')';
}

// Determine active date mode tab: 'day', 'month', 'year', 'all'
$currentDateMode = 'day';
if (in_array($activePeriodKey, ['month', 'this_month']) || !empty($paramMonth)) {
    $currentDateMode = 'month';
} elseif (in_array($activePeriodKey, ['year', 'this_year']) || !empty($paramYear)) {
    $currentDateMode = 'year';
} elseif ($activePeriodKey === 'all') {
    $currentDateMode = 'all';
}

// Build SQL WHERE condition & parameters
$whereClauses = ["1=1"];
$params = [];

if ($startDate !== null && $endDate !== null) {
    if ($startDate === $endDate) {
        $whereClauses[] = "pl.work_date = :start_date";
        $params[':start_date'] = $startDate;
    } else {
        $whereClauses[] = "pl.work_date >= :start_date AND pl.work_date <= :end_date";
        $params[':start_date'] = $startDate;
        $params[':end_date']   = $endDate;
    }
}

if (!empty($paramShift)) {
    $whereClauses[] = "pl.shift = :param_shift";
    $params[':param_shift'] = $paramShift;
}

if (!empty($paramUnit)) {
    $whereClauses[] = "w.unit = :param_unit";
    $params[':param_unit'] = $paramUnit;
}

$whereSql = " WHERE " . implode(" AND ", $whereClauses);

// Base parameters array for export query string
$currentFilterParams = [
    'period'    => $paramPeriod,
    'date'      => $paramDate,
    'month'     => $paramMonth,
    'year'      => $paramYear,
    'from_date' => $paramFromDate,
    'to_date'   => $paramToDate,
    'shift'     => $paramShift,
    'unit'      => $paramUnit
];

// =========================================================================
// 2. CSV EXPORT ENGINE
// =========================================================================
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $safePeriod = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower($periodLabel));
    $filename = "fabriqpro_production_" . $safePeriod . "_" . date('Ymd_His') . ".csv";

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
        'Worker Designation',
        'Garment / Material Name',
        'Pieces Stitched',
        'Logged At (Timestamp)',
        'Notes'
    ]);

    $csvStmt = $pdo->prepare("
        SELECT pl.id, pl.work_date, pl.shift, w.unique_id, w.name, w.unit, w.role,
               pl.material_name, pl.piece_count, pl.created_at, pl.notes
        FROM piece_logs pl
        JOIN workers w ON w.id = pl.worker_id
        $whereSql
        ORDER BY pl.work_date DESC, pl.id DESC
    ");
    $csvStmt->execute($params);

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
        '',
        '',
        '',
        '',
        'TOTAL PIECES:',
        $totalExportPieces,
        '',
        "Period: $periodLabel"
    ]);

    fclose($out);
    exit;
}

// =========================================================================
// 3. STATISTICAL CALCULATIONS FOR SELECTED TIMEFRAME
// =========================================================================

// Total pieces for selected timeframe
$periodPiecesStmt = $pdo->prepare("
    SELECT COALESCE(SUM(pl.piece_count), 0)
    FROM piece_logs pl
    JOIN workers w ON w.id = pl.worker_id
    $whereSql
");
$periodPiecesStmt->execute($params);
$periodPieces = (int)$periodPiecesStmt->fetchColumn();

// Total entries / batches in timeframe
$totalBatchesStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM piece_logs pl
    JOIN workers w ON w.id = pl.worker_id
    $whereSql
");
$totalBatchesStmt->execute($params);
$totalBatchesCount = (int)$totalBatchesStmt->fetchColumn();

// Active operators participating in timeframe
$activeOperatorsStmt = $pdo->prepare("
    SELECT COUNT(DISTINCT pl.worker_id)
    FROM piece_logs pl
    JOIN workers w ON w.id = pl.worker_id
    $whereSql
");
$activeOperatorsStmt->execute($params);
$activeOperatorsCount = (int)$activeOperatorsStmt->fetchColumn();

// Total registered active workers across plant
$totalWorkersCount = (int)$pdo->query("SELECT COUNT(*) FROM workers WHERE status = 'Active'")->fetchColumn();

// Plant lifetime volume (all time)
$allTimePieces = (int)$pdo->query("SELECT COALESCE(SUM(piece_count), 0) FROM piece_logs")->fetchColumn();

// Dynamic Year & Month options for dropdowns
$availableYears = $pdo->query("
    SELECT DISTINCT SUBSTR(work_date, 1, 4) as yr 
    FROM piece_logs 
    WHERE work_date IS NOT NULL AND work_date != '' 
    ORDER BY yr DESC
")->fetchAll(PDO::FETCH_COLUMN) ?: [];
if (!in_array($thisYearStr, $availableYears)) {
    array_unshift($availableYears, $thisYearStr);
}

// Available production units for filter dropdown
$unitsStmt = $pdo->query("SELECT * FROM production_units WHERE status = 'Active' ORDER BY id ASC");
$productionUnits = $unitsStmt->fetchAll(PDO::FETCH_ASSOC);

// =========================================================================
// 4. UNIT BREAKDOWN FOR SELECTED TIMEFRAME
// =========================================================================
$unitStats = [];
foreach ($productionUnits as $u) {
    $uName = $u['unit_name'];

    // Registered workers count in unit
    $wCountStmt = $pdo->prepare("SELECT COUNT(*) FROM workers WHERE unit = :u AND status = 'Active'");
    $wCountStmt->execute([':u' => $uName]);
    $workerCount = (int)$wCountStmt->fetchColumn();

    // Pieces produced in unit within selected timeframe
    $uWhere = $whereClauses;
    $uWhere[] = "w.unit = :unit_name";
    $uWhereSql = " WHERE " . implode(" AND ", $uWhere);
    $uParams = array_merge($params, [':unit_name' => $uName]);

    $uPiecesStmt = $pdo->prepare("
        SELECT COALESCE(SUM(pl.piece_count), 0)
        FROM piece_logs pl
        JOIN workers w ON w.id = pl.worker_id
        $uWhereSql
    ");
    $uPiecesStmt->execute($uParams);
    $unitPieces = (int)$uPiecesStmt->fetchColumn();

    // All-time pieces for this unit
    $uAllTimeStmt = $pdo->prepare("
        SELECT COALESCE(SUM(pl.piece_count), 0)
        FROM piece_logs pl
        JOIN workers w ON w.id = pl.worker_id
        WHERE w.unit = :uname
    ");
    $uAllTimeStmt->execute([':uname' => $uName]);
    $uAllTime = (int)$uAllTimeStmt->fetchColumn();

    // Top operator in unit for selected timeframe
    $topOpStmt = $pdo->prepare("
        SELECT w.name, w.unique_id, SUM(pl.piece_count) as pcs
        FROM piece_logs pl
        JOIN workers w ON w.id = pl.worker_id
        $uWhereSql
        GROUP BY w.id
        ORDER BY pcs DESC
        LIMIT 1
    ");
    $topOpStmt->execute($uParams);
    $topOp = $topOpStmt->fetch(PDO::FETCH_ASSOC);

    $sharePct = ($periodPieces > 0) ? round(($unitPieces / $periodPieces) * 100) : 0;

    $unitStats[] = [
        'unit'            => $u,
        'worker_count'    => $workerCount,
        'period_pieces'   => $unitPieces,
        'all_time_pieces' => $uAllTime,
        'share_pct'       => $sharePct,
        'top_operator'    => $topOp
    ];
}

// =========================================================================
// 5. TOP OPERATORS & GARMENTS IN SELECTED TIMEFRAME
// =========================================================================
$topOperatorsStmt = $pdo->prepare("
    SELECT w.id, w.unique_id, w.name, w.unit, w.role, COALESCE(SUM(pl.piece_count), 0) as pieces_sum
    FROM piece_logs pl
    JOIN workers w ON w.id = pl.worker_id
    $whereSql
    GROUP BY w.id
    ORDER BY pieces_sum DESC
    LIMIT 5
");
$topOperatorsStmt->execute($params);
$topOperators = $topOperatorsStmt->fetchAll(PDO::FETCH_ASSOC);

// Garment breakdown in timeframe
$garmentBreakdownStmt = $pdo->prepare("
    SELECT pl.material_name, SUM(pl.piece_count) as total_qty, COUNT(*) as batch_cnt
    FROM piece_logs pl
    JOIN workers w ON w.id = pl.worker_id
    $whereSql
    GROUP BY pl.material_name
    ORDER BY total_qty DESC
    LIMIT 6
");
$garmentBreakdownStmt->execute($params);
$garmentBreakdown = $garmentBreakdownStmt->fetchAll(PDO::FETCH_ASSOC);

// =========================================================================
// 6. COMPLETE PRODUCTION HISTORY RECORDS FOR SELECTED TIMEFRAME
// =========================================================================
$historyStmt = $pdo->prepare("
    SELECT pl.id, pl.work_date, pl.shift, pl.material_name, pl.piece_count, pl.created_at, pl.notes,
           w.id as worker_db_id, w.unique_id, w.name as worker_name, w.unit, w.role
    FROM piece_logs pl
    JOIN workers w ON w.id = pl.worker_id
    $whereSql
    ORDER BY pl.work_date DESC, pl.created_at DESC, pl.id DESC
");
$historyStmt->execute($params);
$historyRecords = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

// CSV Download URL for the current filters
$exportUrl = "dashboard.php?" . http_build_query(array_merge($currentFilterParams, ['export' => 'csv']));

// Arduino IoT Status
$arduinoStatus = 'Standby';
$arduinoTotalPresses = 0;
$arduinoLastPress = 'None';
$arduinoQueueFile = __DIR__ . '/arduino_queue.json';
if (file_exists($arduinoQueueFile)) {
    $queueData = json_decode(@file_get_contents($arduinoQueueFile), true);
    if (!empty($queueData)) {
        $arduinoStatus = ucfirst($queueData['bridge_status'] ?? 'Ready');
        $arduinoTotalPresses = (int)($queueData['total_presses'] ?? 0);
        $arduinoLastPress = !empty($queueData['last_press_time']) ? date('M d, H:i', strtotime($queueData['last_press_time'])) : 'N/A';
    }
}

include __DIR__ . '/includes/header.php';
?>

<style>
/* ===================================================================
   FABRIQPRO EXECUTIVE COMMAND DASHBOARD - LUXURY ENTERPRISE SUITE
   =================================================================== */

/* Header Area */
.dash-header-wrap {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1.25rem;
    margin-bottom: 1.25rem;
    flex-wrap: wrap;
}

.dash-header-left {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.dash-header-icon {
    width: 46px;
    height: 46px;
    border-radius: var(--radius-md);
    background: linear-gradient(135deg, rgba(37, 99, 235, 0.22) 0%, rgba(99, 102, 241, 0.16) 100%);
    border: 1px solid rgba(59, 130, 246, 0.35);
    color: #60a5fa;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    flex-shrink: 0;
    box-shadow: 0 4px 16px rgba(37, 99, 235, 0.25);
}

.dash-header-left h1 {
    font-size: 1.55rem;
    font-weight: 800;
    letter-spacing: -0.03em;
    color: var(--text-primary);
    margin: 0;
    line-height: 1.2;
}

.dash-header-meta {
    display: flex;
    align-items: center;
    gap: 0.55rem;
    font-size: 0.82rem;
    color: var(--text-secondary);
    margin-top: 0.25rem;
    flex-wrap: wrap;
}

.dash-period-badge {
    background: rgba(59, 130, 246, 0.12);
    color: #60a5fa;
    border: 1px solid rgba(59, 130, 246, 0.28);
    border-radius: 50px;
    padding: 0.16rem 0.65rem;
    font-size: 0.74rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
}

.dash-live-badge {
    background: rgba(239, 68, 68, 0.12);
    color: #f87171;
    border: 1px solid rgba(239, 68, 68, 0.28);
    border-radius: 50px;
    padding: 0.16rem 0.6rem;
    font-size: 0.7rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}

.dash-live-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #ef4444;
    box-shadow: 0 0 6px #ef4444;
    animation: livePulseDot 1.8s infinite;
}

.dash-header-actions {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    flex-wrap: wrap;
}

.btn-action-primary {
    background: linear-gradient(135deg, #2563eb 0%, #4f46e5 100%);
    color: #ffffff !important;
    border: 1px solid rgba(255, 255, 255, 0.2);
    box-shadow: 0 3px 12px rgba(37, 99, 235, 0.35);
    border-radius: var(--radius-sm);
    padding: 0.48rem 0.95rem;
    font-size: 0.8rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    text-decoration: none;
    transition: all var(--transition-fast);
}
.btn-action-primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 18px rgba(37, 99, 235, 0.5);
    color: #ffffff;
}

.btn-action-success {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: #ffffff !important;
    border: 1px solid rgba(255, 255, 255, 0.2);
    box-shadow: 0 3px 12px rgba(16, 185, 129, 0.3);
    border-radius: var(--radius-sm);
    padding: 0.48rem 0.9rem;
    font-size: 0.8rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    text-decoration: none;
    transition: all var(--transition-fast);
}
.btn-action-success:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 18px rgba(16, 185, 129, 0.45);
    color: #ffffff;
}

.btn-action-outline {
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-sm);
    padding: 0.48rem 0.85rem;
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--text-secondary);
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    cursor: pointer;
    transition: all var(--transition-fast);
}
.btn-action-outline:hover {
    background: rgba(255, 255, 255, 0.09);
    color: #ffffff;
    border-color: var(--border-color-hover);
}

/* Streamlined Filter Toolbar */
.dash-toolbar-card {
    background: var(--bg-card);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    padding: 0.65rem 1rem;
    margin-bottom: 1.5rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 0.85rem;
    flex-wrap: wrap;
    box-shadow: 0 4px 20px -6px rgba(0, 0, 0, 0.35);
}
body.theme-light .dash-toolbar-card {
    background: #ffffff;
    border-color: rgba(0, 0, 0, 0.08);
    box-shadow: 0 4px 16px -4px rgba(0, 0, 0, 0.04);
}

.toolbar-left-group {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    flex-wrap: wrap;
}

.date-pill-tabs {
    display: inline-flex;
    align-items: center;
    background: rgba(0, 0, 0, 0.28);
    border: 1px solid rgba(255, 255, 255, 0.07);
    border-radius: 50px;
    padding: 0.2rem;
    gap: 0.15rem;
}
body.theme-light .date-pill-tabs {
    background: rgba(0, 0, 0, 0.04);
    border-color: rgba(0, 0, 0, 0.08);
}

.date-pill-tab {
    padding: 0.32rem 0.75rem;
    border-radius: 50px;
    color: var(--text-secondary);
    font-size: 0.76rem;
    font-weight: 600;
    font-family: var(--font-main);
    background: transparent;
    border: none;
    cursor: pointer;
    transition: all var(--transition-fast);
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    white-space: nowrap;
}
.date-pill-tab:hover {
    color: #ffffff;
    background: rgba(255, 255, 255, 0.08);
}
.date-pill-tab.active {
    background: linear-gradient(135deg, #2563eb 0%, #4f46e5 100%);
    color: #ffffff;
    box-shadow: 0 2px 10px rgba(37, 99, 235, 0.35);
    font-weight: 600;
}

.dynamic-date-panels {
    display: inline-flex;
    align-items: center;
}

.date-mode-panel {
    display: none;
    align-items: center;
    gap: 0.4rem;
}
.date-mode-panel.active {
    display: inline-flex;
}

.preset-pill-group {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
}

.preset-pill {
    padding: 0.32rem 0.65rem;
    border-radius: var(--radius-sm);
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid var(--border-color);
    color: var(--text-secondary);
    font-size: 0.75rem;
    font-weight: 600;
    text-decoration: none;
    transition: all var(--transition-fast);
    display: inline-flex;
    align-items: center;
    white-space: nowrap;
}
.preset-pill:hover {
    color: #ffffff;
    background: rgba(255, 255, 255, 0.09);
    border-color: var(--border-color-hover);
}
.preset-pill.active {
    background: rgba(59, 130, 246, 0.15);
    border-color: rgba(59, 130, 246, 0.4);
    color: #60a5fa;
    font-weight: 700;
}

.compact-picker-wrap {
    position: relative;
    display: inline-flex;
    align-items: center;
}
.compact-picker-wrap i {
    position: absolute;
    left: 0.65rem;
    color: var(--text-muted);
    font-size: 0.76rem;
    pointer-events: none;
}
.compact-picker-wrap input,
.compact-picker-wrap select {
    height: 32px;
    background: rgba(0, 0, 0, 0.32);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-sm);
    padding: 0 0.65rem 0 1.85rem;
    color: #ffffff;
    font-size: 0.78rem;
    font-weight: 600;
    font-family: var(--font-main);
    outline: none;
    cursor: pointer;
    transition: border-color var(--transition-fast);
}
body.theme-light .compact-picker-wrap input,
body.theme-light .compact-picker-wrap select {
    background: #ffffff;
    border-color: rgba(0, 0, 0, 0.14);
    color: #0f172a;
}
.compact-picker-wrap input:focus,
.compact-picker-wrap select:focus {
    border-color: var(--primary);
}

.toolbar-right-group {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.filter-select-pill {
    position: relative;
    display: inline-flex;
    align-items: center;
    background: rgba(0, 0, 0, 0.32);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-sm);
    height: 32px;
    padding: 0 0.35rem 0 0.65rem;
    gap: 0.3rem;
}
body.theme-light .filter-select-pill {
    background: #ffffff;
    border-color: rgba(0, 0, 0, 0.14);
}
.filter-select-pill i {
    font-size: 0.75rem;
    color: var(--text-muted);
}
.filter-select-pill label {
    font-size: 0.72rem;
    font-weight: 700;
    color: var(--text-muted);
    margin-right: 0.2rem;
    cursor: pointer;
}
.filter-select-pill select {
    background: transparent;
    border: none;
    color: #ffffff;
    font-size: 0.78rem;
    font-weight: 600;
    font-family: var(--font-main);
    outline: none;
    cursor: pointer;
}
body.theme-light .filter-select-pill select {
    color: #0f172a;
}
.filter-select-pill select option {
    background: #0f1527;
    color: #ffffff;
}

.btn-reset-filter {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    padding: 0 0.65rem;
    height: 32px;
    background: rgba(239, 68, 68, 0.12);
    border: 1px solid rgba(239, 68, 68, 0.28);
    border-radius: var(--radius-sm);
    color: #f87171;
    font-size: 0.74rem;
    font-weight: 600;
    text-decoration: none;
    transition: all var(--transition-fast);
}
.btn-reset-filter:hover {
    background: rgba(239, 68, 68, 0.25);
    color: #fca5a5;
}

/* 4 Modern KPI Cards Grid */
.kpi-stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1.15rem;
    margin-bottom: 1.65rem;
}
@media (max-width: 1024px) {
    .kpi-stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}
@media (max-width: 640px) {
    .kpi-stats-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 0.65rem;
        margin-bottom: 1.25rem;
    }
}

.kpi-metric-card {
    background: var(--bg-card);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    padding: 1.15rem 1.25rem;
    position: relative;
    box-shadow: 0 4px 18px -4px rgba(0, 0, 0, 0.3);
    transition: all 0.24s ease;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    gap: 0.85rem;
}
body.theme-light .kpi-metric-card {
    background: #ffffff;
    border-color: rgba(0, 0, 0, 0.08);
    box-shadow: 0 2px 12px -2px rgba(0, 0, 0, 0.04);
}
.kpi-metric-card:hover {
    transform: translateY(-2px);
    border-color: rgba(255, 255, 255, 0.18);
    box-shadow: 0 8px 26px -6px rgba(0, 0, 0, 0.5);
}

.kpi-top-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.kpi-label {
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--text-muted);
}

.kpi-icon-pill {
    width: 36px;
    height: 36px;
    border-radius: var(--radius-sm);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.95rem;
}
.kpi-icon-pill.emerald {
    background: rgba(16, 185, 129, 0.14);
    color: #34d399;
    border: 1px solid rgba(16, 185, 129, 0.28);
}
.kpi-icon-pill.cyan {
    background: rgba(6, 182, 212, 0.14);
    color: #38bdf8;
    border: 1px solid rgba(6, 182, 212, 0.28);
}
.kpi-icon-pill.amber {
    background: rgba(245, 158, 11, 0.14);
    color: #fbbf24;
    border: 1px solid rgba(245, 158, 11, 0.28);
}
.kpi-icon-pill.purple {
    background: rgba(139, 92, 246, 0.14);
    color: #c084fc;
    border: 1px solid rgba(139, 92, 246, 0.28);
}

.kpi-value-block {
    display: flex;
    align-items: baseline;
    gap: 0.35rem;
}
.kpi-value-num {
    font-family: var(--font-heading);
    font-size: 2rem;
    font-weight: 800;
    line-height: 1.1;
    color: var(--text-primary);
    letter-spacing: -0.03em;
}
.kpi-unit-tag {
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--text-muted);
}

.kpi-bottom-meta {
    font-size: 0.75rem;
    color: var(--text-secondary);
    display: flex;
    align-items: center;
    gap: 0.35rem;
}

/* Production Units Section */
.unit-cards-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1.25rem;
    margin-bottom: 1.75rem;
}
@media (max-width: 860px) {
    .unit-cards-grid {
        grid-template-columns: 1fr;
    }
}
.unit-dash-card {
    background: var(--bg-card);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    padding: 1.25rem 1.35rem;
    position: relative;
    overflow: hidden;
    box-shadow: 0 4px 18px -4px rgba(0, 0, 0, 0.3);
    transition: all 0.25s ease;
}
body.theme-light .unit-dash-card {
    background: #ffffff;
    border-color: rgba(0, 0, 0, 0.08);
    box-shadow: 0 2px 12px -2px rgba(0, 0, 0, 0.04);
}
.unit-dash-card:hover {
    transform: translateY(-2px);
    border-color: rgba(255, 255, 255, 0.18);
    box-shadow: 0 10px 28px -8px rgba(0, 0, 0, 0.5);
}
.unit-dash-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.95rem;
}
.unit-title-group {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}
.unit-badge-icon {
    width: 42px;
    height: 42px;
    border-radius: var(--radius-md);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.15rem;
}
.unit-badge-icon.cyan {
    background: rgba(6, 182, 212, 0.15);
    color: #38bdf8;
    border: 1px solid rgba(56, 189, 248, 0.3);
}
.unit-badge-icon.pink {
    background: rgba(244, 114, 182, 0.15);
    color: #f472b6;
    border: 1px solid rgba(244, 114, 182, 0.3);
}
.dash-progress-wrap {
    background: rgba(0, 0, 0, 0.32);
    border-radius: 20px;
    height: 8px;
    overflow: hidden;
    margin: 0.75rem 0 1.1rem;
    border: 1px solid rgba(255, 255, 255, 0.06);
}
.dash-progress-fill {
    height: 100%;
    border-radius: 20px;
    transition: width 0.8s cubic-bezier(0.16, 1, 0.3, 1);
}

.unit-stats-split {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.75rem;
    margin-bottom: 1rem;
}
.unit-stat-box {
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid rgba(255, 255, 255, 0.05);
    border-radius: var(--radius-sm);
    padding: 0.65rem 0.8rem;
}
body.theme-light .unit-stat-box {
    background: #f8fafc;
    border-color: rgba(0, 0, 0, 0.06);
}
.unit-stat-box-label {
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    color: var(--text-muted);
}
.unit-stat-box-val {
    font-family: var(--font-heading);
    font-size: 1.35rem;
    font-weight: 800;
    color: var(--text-primary);
    margin-top: 0.15rem;
}

.unit-card-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-top: 1px solid var(--border-color-subtle);
    padding-top: 0.85rem;
    gap: 0.65rem;
}
.unit-card-op-info {
    font-size: 0.82rem;
    min-width: 0;
    flex: 1;
}

/* Section Header Styles */
.dash-section-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    margin-bottom: 0.85rem;
    gap: 0.5rem;
    flex-wrap: wrap;
}
.dash-section-title {
    font-size: 1.12rem;
    font-weight: 800;
    color: var(--text-primary);
    display: flex;
    align-items: center;
    gap: 0.6rem;
}
.dash-section-subtitle {
    font-size: 0.82rem;
    color: var(--text-secondary);
}

/* Two-Column Operational Section */
.dash-two-col {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.35rem;
    margin-bottom: 1.75rem;
}
@media (max-width: 1024px) {
    .dash-two-col {
        grid-template-columns: 1fr;
    }
}

.top-performer-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.7rem 0.85rem;
    background: rgba(255, 255, 255, 0.025);
    border: 1px solid var(--border-color-subtle);
    border-radius: var(--radius-md);
    margin-bottom: 0.55rem;
    transition: all var(--transition-fast);
}
body.theme-light .top-performer-item {
    background: #f8fafc;
    border-color: rgba(0, 0, 0, 0.06);
}
.top-performer-item:hover {
    background: rgba(255, 255, 255, 0.05);
    border-color: var(--border-color);
    transform: translateX(3px);
}
.performer-rank {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: 0.8rem;
    flex-shrink: 0;
}
.rank-1-badge { background: linear-gradient(135deg, #fbbf24, #d97706); color: #000; box-shadow: 0 0 10px rgba(251, 191, 36, 0.4); }
.rank-2-badge { background: linear-gradient(135deg, #cbd5e1, #94a3b8); color: #000; }
.rank-3-badge { background: linear-gradient(135deg, #fb923c, #c2410c); color: #fff; }
.rank-other-badge { background: rgba(255, 255, 255, 0.1); color: var(--text-secondary); }

/* History Data Table */
.history-table-card {
    background: var(--bg-card);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    box-shadow: 0 4px 20px -4px rgba(0, 0, 0, 0.3);
    margin-bottom: 1.85rem;
    overflow: hidden;
    width: 100%;
    box-sizing: border-box;
}
body.theme-light .history-table-card {
    background: #ffffff;
    border-color: rgba(0, 0, 0, 0.08);
}
.history-table-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1.15rem 1.35rem;
    border-bottom: 1px solid var(--border-color-subtle);
    flex-wrap: wrap;
    gap: 0.85rem;
    width: 100%;
    box-sizing: border-box;
}
.history-table-title-group {
    min-width: 0;
    flex: 1 1 auto;
}
.history-table-title-group h2 {
    font-size: 1.15rem;
    font-weight: 800;
    color: var(--text-primary);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.55rem;
    flex-wrap: wrap;
    word-break: break-word;
}
.history-table-tools {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    flex-wrap: wrap;
}
.history-search-wrap {
    position: relative;
    width: 260px;
}
.card-footer-action-row {
    border-top: 1px solid var(--border-color-subtle);
    padding-top: 0.85rem;
    font-size: 0.78rem;
    color: var(--text-muted);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.5rem;
    width: 100%;
    box-sizing: border-box;
}
.history-table-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.75rem 1.35rem;
    background: rgba(0,0,0,0.18);
    border-top: 1px solid var(--border-color-subtle);
    font-size: 0.82rem;
    flex-wrap: wrap;
    gap: 0.5rem;
    width: 100%;
    box-sizing: border-box;
}
@media (max-width: 768px) {
    .history-search-wrap {
        width: 100%;
    }
}
.history-search-wrap i {
    position: absolute;
    left: 0.75rem;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-muted);
    font-size: 0.8rem;
}
.history-search-input {
    width: 100%;
    height: 34px;
    background: rgba(0, 0, 0, 0.28);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-sm);
    padding: 0 0.75rem 0 2rem;
    color: #ffffff;
    font-size: 0.8rem;
    font-family: var(--font-main);
    outline: none;
    transition: border-color var(--transition-fast);
}
body.theme-light .history-search-input {
    background: #ffffff;
    border-color: rgba(0, 0, 0, 0.12);
    color: #0f172a;
}
.history-search-input:focus {
    border-color: var(--primary);
}

.data-table-container {
    width: 100%;
    overflow-x: auto;
    max-height: 480px;
    position: relative;
    -webkit-overflow-scrolling: touch;
}
.dash-data-table {
    width: 100%;
    border-collapse: collapse;
    text-align: left;
    font-size: 0.84rem;
}
.dash-data-table th {
    background: rgba(13, 19, 36, 0.96);
    backdrop-filter: blur(10px);
    position: sticky;
    top: 0;
    z-index: 5;
    padding: 0.75rem 1rem;
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--text-muted);
    border-bottom: 1px solid var(--border-color);
    white-space: nowrap;
}
body.theme-light .dash-data-table th {
    background: #f1f5f9;
    color: #475569;
}
.dash-data-table td {
    padding: 0.8rem 1rem;
    border-bottom: 1px solid var(--border-color-subtle);
    color: var(--text-secondary);
    vertical-align: middle;
}
.dash-data-table tr:hover td {
    background: rgba(255, 255, 255, 0.03);
    color: var(--text-primary);
}

/* Compact Launchpad Dock */
.quick-dock-grid {
    display: grid;
    grid-template-columns: repeat(6, 1fr);
    gap: 0.85rem;
    margin-bottom: 2rem;
}
@media (max-width: 1100px) {
    .quick-dock-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}
@media (max-width: 640px) {
    .quick-dock-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

.quick-dock-item {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    padding: 0.95rem 1rem;
    text-decoration: none;
    color: var(--text-primary);
    display: flex;
    align-items: center;
    gap: 0.75rem;
    transition: all var(--transition-fast);
}
body.theme-light .quick-dock-item {
    background: #ffffff;
    border-color: rgba(0, 0, 0, 0.08);
}
.quick-dock-item:hover {
    transform: translateY(-2px);
    border-color: var(--primary);
    background: rgba(255, 255, 255, 0.06);
    box-shadow: 0 6px 18px -4px rgba(0, 0, 0, 0.4);
}
.dock-icon {
    width: 36px;
    height: 36px;
    border-radius: var(--radius-sm);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    flex-shrink: 0;
}
.dock-title {
    font-size: 0.84rem;
    font-weight: 700;
    line-height: 1.2;
}
.dock-sub {
    font-size: 0.7rem;
    color: var(--text-muted);
    margin-top: 0.15rem;
}

/* ===================================================================
   DEDICATED MOBILE VIEWPORT OPTIMIZATIONS (< 768px & < 480px)
   =================================================================== */
@media (max-width: 768px) {
    /* Header Area */
    .dash-header-wrap {
        flex-direction: column;
        align-items: stretch;
        gap: 0.85rem;
        margin-bottom: 1rem;
    }
    .dash-header-left {
        gap: 0.75rem;
    }
    .dash-header-icon {
        width: 38px;
        height: 38px;
        font-size: 1.05rem;
    }
    .dash-header-left h1 {
        font-size: 1.25rem;
    }
    .dash-header-meta {
        font-size: 0.74rem;
        gap: 0.35rem;
    }
    .dash-header-actions {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.45rem;
        width: 100%;
    }
    .btn-action-primary, .btn-action-success {
        justify-content: center;
        padding: 0.52rem 0.55rem;
        font-size: 0.76rem;
        width: 100%;
    }
    .btn-action-outline {
        grid-column: span 2;
        justify-content: center;
        padding: 0.45rem 0.55rem;
        font-size: 0.74rem;
        width: 100%;
    }

    /* Filter Toolbar */
    .dash-toolbar-card {
        padding: 0.7rem 0.75rem;
        gap: 0.6rem;
        margin-bottom: 1.15rem;
    }
    .toolbar-left-group {
        width: 100%;
        gap: 0.45rem;
    }
    .date-pill-tabs {
        width: 100%;
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 0.15rem;
        padding: 0.15rem;
    }
    .date-pill-tab {
        justify-content: center;
        padding: 0.35rem 0.2rem;
        font-size: 0.72rem;
        gap: 0.2rem;
    }
    .date-pill-tab i {
        display: none;
    }
    .dynamic-date-panels {
        width: 100%;
    }
    .date-mode-panel {
        width: 100%;
        gap: 0.4rem;
        align-items: center;
    }
    .date-mode-panel .preset-pill-group {
        display: flex;
        flex: 1;
        gap: 0.25rem;
    }
    .date-mode-panel .preset-pill {
        flex: 1;
        justify-content: center;
        padding: 0.32rem 0.4rem;
        font-size: 0.72rem;
    }
    .date-mode-panel .compact-picker-wrap {
        flex: 1;
    }
    .date-mode-panel .compact-picker-wrap input,
    .date-mode-panel .compact-picker-wrap select {
        width: 100%;
        font-size: 0.75rem;
    }
    .toolbar-right-group {
        width: 100%;
        display: grid;
        grid-template-columns: 1fr 1fr auto;
        gap: 0.4rem;
        align-items: center;
    }
    .filter-select-pill {
        width: 100%;
        padding: 0 0.4rem;
    }
    .filter-select-pill label {
        display: none;
    }
    .filter-select-pill select {
        width: 100%;
        font-size: 0.75rem;
    }
    .btn-reset-filter {
        padding: 0 0.55rem;
        font-size: 0.72rem;
        justify-content: center;
    }

    /* 2x2 High-Contrast KPI Cards Grid */
    .kpi-stats-grid {
        grid-template-columns: repeat(2, 1fr) !important;
        gap: 0.65rem;
        margin-bottom: 1.25rem;
    }
    .kpi-metric-card {
        padding: 0.85rem 0.95rem;
        gap: 0.5rem;
        border-radius: var(--radius-md);
    }
    .kpi-value-num {
        font-size: 1.55rem;
    }
    .kpi-icon-pill {
        width: 32px;
        height: 32px;
        font-size: 0.85rem;
    }
    .kpi-label {
        font-size: 0.65rem;
    }
    .kpi-bottom-meta {
        font-size: 0.68rem;
    }

    /* Section Header */
    .dash-section-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.2rem;
        margin-bottom: 0.65rem;
    }
    .dash-section-title {
        font-size: 1.05rem !important;
    }
    .dash-section-subtitle {
        font-size: 0.74rem !important;
    }

    /* Unit Cards */
    .unit-dash-card {
        padding: 0.95rem 1rem;
    }
    .unit-stats-split {
        gap: 0.5rem;
        margin-bottom: 0.75rem;
    }
    .unit-stat-box {
        padding: 0.55rem 0.65rem;
    }
    .unit-stat-box-val {
        font-size: 1.2rem;
    }
    .unit-card-footer {
        flex-direction: column;
        align-items: stretch;
        gap: 0.55rem;
    }
    .unit-card-footer .unit-manage-btn {
        width: 100%;
        justify-content: center;
    }

    /* Two Column Operational */
    .dash-two-col {
        gap: 1rem;
        margin-bottom: 1.25rem;
    }
    .dash-two-col .card {
        padding: 1rem;
    }

    /* Shift Records Table Header */
    .history-table-header {
        padding: 0.85rem 0.85rem;
        gap: 0.65rem;
        flex-direction: column;
        align-items: stretch;
    }
    .history-table-title-group h2 {
        font-size: 1.05rem;
        gap: 0.35rem;
    }
    .history-table-tools {
        width: 100%;
        display: flex;
        flex-direction: column;
        gap: 0.45rem;
    }
    .history-search-wrap {
        width: 100%;
    }
    .history-table-tools .btn-action-success {
        width: 100%;
        justify-content: center;
        padding: 0.48rem 0.65rem;
        font-size: 0.78rem;
    }
    .card-footer-action-row {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.35rem;
    }
    .history-table-footer {
        flex-direction: column;
        align-items: flex-start;
        padding: 0.65rem 0.85rem;
        gap: 0.35rem;
        font-size: 0.76rem;
    }
    .dash-data-table th, .dash-data-table td {
        padding: 0.65rem 0.75rem;
    }

    /* Quick Action Dock */
    .quick-dock-grid {
        grid-template-columns: repeat(2, 1fr) !important;
        gap: 0.55rem;
        margin-bottom: 1.5rem;
    }
    .quick-dock-item {
        padding: 0.75rem 0.8rem;
        gap: 0.55rem;
    }
    .dock-icon {
        width: 32px;
        height: 32px;
        font-size: 0.9rem;
    }
    .dock-title {
        font-size: 0.78rem;
    }
    .dock-sub {
        font-size: 0.66rem;
    }
}

/* Print Styles */
@media print {
    body { background: #fff !important; color: #000 !important; }
    .app-header, .dash-toolbar-card, .quick-dock-grid, .dash-header-actions, .app-footer, .mobile-bottom-nav { display: none !important; }
    .card, .history-table-card { border: 1px solid #ccc !important; box-shadow: none !important; background: #fff !important; color: #000 !important; }
    .dash-data-table th, .dash-data-table td { color: #000 !important; }
}
</style>

<!-- Modern Executive Header -->
<div class="dash-header-wrap">
    <div class="dash-header-left">
        <div class="dash-header-icon">
            <i class="fa-solid fa-gauge-high"></i>
        </div>
        <div>
            <h1>Production Dashboard</h1>
            <div class="dash-header-meta">
                <span class="dash-period-badge"><i class="fa-regular fa-calendar-check"></i> <?= htmlspecialchars($periodLabel) ?></span>
                <span class="dash-live-badge"><span class="dash-live-dot"></span> LIVE</span>
            </div>
        </div>
    </div>

    <div class="dash-header-actions">
        <!-- Direct Action: Log Today's Clothes -->
        <a href="daily_output.php" class="btn-action-primary" title="Go to Workstation to log output">
            <i class="fa-solid fa-plus-circle"></i>
            <span>Log Output</span>
        </a>

        <!-- Export CSV Button -->
        <a href="<?= htmlspecialchars($exportUrl) ?>" class="btn-action-success" title="Download Complete Production History for this Period as CSV / Excel">
            <i class="fa-solid fa-file-arrow-down"></i>
            <span>Export CSV (<?= number_format($periodPieces) ?> pcs)</span>
        </a>

        <!-- Print Report Button -->
        <button type="button" onclick="window.print()" class="btn-action-outline" title="Print or Save as PDF Report">
            <i class="fa-solid fa-print"></i>
            <span>Print Report</span>
        </button>
    </div>
</div>

<!-- Streamlined Unified Control & Filter Bar -->
<div class="dash-toolbar-card">
    <!-- Left Group: Tabbed Date Mode Selector & Dynamic Input -->
    <div class="toolbar-left-group">
        <!-- Mode Tabs: Day, Month, Year, All-Time -->
        <div class="date-pill-tabs" role="tablist">
            <button type="button" class="date-pill-tab <?= ($currentDateMode === 'day') ? 'active' : '' ?>" onclick="switchDateMode('day')">
                <i class="fa-solid fa-calendar-day"></i> Day
            </button>
            <button type="button" class="date-pill-tab <?= ($currentDateMode === 'month') ? 'active' : '' ?>" onclick="switchDateMode('month')">
                <i class="fa-solid fa-calendar"></i> Month
            </button>
            <button type="button" class="date-pill-tab <?= ($currentDateMode === 'year') ? 'active' : '' ?>" onclick="switchDateMode('year')">
                <i class="fa-solid fa-calendar-days"></i> Year
            </button>
            <button type="button" class="date-pill-tab <?= ($currentDateMode === 'all') ? 'active' : '' ?>" onclick="submitPeriodAll()">
                <i class="fa-solid fa-infinity"></i> All History
            </button>
        </div>

        <!-- Dynamic Input Panels -->
        <div class="dynamic-date-panels">
            <!-- DAY MODE -->
            <div class="date-mode-panel <?= ($currentDateMode === 'day') ? 'active' : '' ?>" id="panel-day">
                <div class="preset-pill-group">
                    <a href="dashboard.php?period=today<?= !empty($paramUnit) ? '&unit=' . urlencode($paramUnit) : '' ?><?= !empty($paramShift) ? '&shift=' . urlencode($paramShift) : '' ?>" 
                       class="preset-pill <?= ($activePeriodKey === 'today') ? 'active' : '' ?>">
                        Today
                    </a>
                    <a href="dashboard.php?period=yesterday<?= !empty($paramUnit) ? '&unit=' . urlencode($paramUnit) : '' ?><?= !empty($paramShift) ? '&shift=' . urlencode($paramShift) : '' ?>" 
                       class="preset-pill <?= ($activePeriodKey === 'yesterday') ? 'active' : '' ?>">
                        Yesterday
                    </a>
                </div>
                <div class="compact-picker-wrap" title="Select specific date">
                    <i class="fa-solid fa-calendar-day text-emerald"></i>
                    <input type="date" id="dateInput" value="<?= htmlspecialchars($paramDate ?: $todayDate) ?>" onchange="submitDateFilter(this.value)">
                </div>
            </div>

            <!-- MONTH MODE -->
            <div class="date-mode-panel <?= ($currentDateMode === 'month') ? 'active' : '' ?>" id="panel-month">
                <div class="preset-pill-group">
                    <a href="dashboard.php?period=this_month<?= !empty($paramUnit) ? '&unit=' . urlencode($paramUnit) : '' ?><?= !empty($paramShift) ? '&shift=' . urlencode($paramShift) : '' ?>" 
                       class="preset-pill <?= ($activePeriodKey === 'this_month') ? 'active' : '' ?>">
                        This Month
                    </a>
                </div>
                <div class="compact-picker-wrap" title="Select specific month">
                    <i class="fa-solid fa-calendar text-cyan"></i>
                    <input type="month" id="monthInput" value="<?= htmlspecialchars($paramMonth ?: $thisMonthStr) ?>" onchange="submitMonthFilter(this.value)">
                </div>
            </div>

            <!-- YEAR MODE -->
            <div class="date-mode-panel <?= ($currentDateMode === 'year') ? 'active' : '' ?>" id="panel-year">
                <div class="preset-pill-group">
                    <a href="dashboard.php?period=this_year<?= !empty($paramUnit) ? '&unit=' . urlencode($paramUnit) : '' ?><?= !empty($paramShift) ? '&shift=' . urlencode($paramShift) : '' ?>" 
                       class="preset-pill <?= ($activePeriodKey === 'this_year') ? 'active' : '' ?>">
                        This Year
                    </a>
                </div>
                <div class="compact-picker-wrap" title="Select specific year">
                    <i class="fa-solid fa-calendar-days text-amber"></i>
                    <select id="yearSelect" onchange="submitYearFilter(this.value)">
                        <?php foreach ($availableYears as $yr): ?>
                            <option value="<?= htmlspecialchars($yr) ?>" <?= (($paramYear ?: $thisYearStr) == $yr) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($yr) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- ALL-TIME MODE -->
            <div class="date-mode-panel <?= ($currentDateMode === 'all') ? 'active' : '' ?>" id="panel-all">
                <span class="preset-pill active" style="cursor: default; display: inline-flex; align-items: center; gap: 0.4rem;">
                    <i class="fa-solid fa-infinity"></i> <span>Complete Plant History</span>
                </span>
            </div>
        </div>
    </div>

    <!-- Right Group: Scope Filters (Unit & Shift) + Reset Button -->
    <div class="toolbar-right-group">
        <!-- Production Unit Pill -->
        <div class="filter-select-pill" title="Filter by Production Unit">
            <i class="fa-solid fa-industry"></i>
            <label for="unitSelect">Unit:</label>
            <select id="unitSelect" onchange="submitUnitFilter(this.value)">
                <option value="">All Units</option>
                <?php foreach ($productionUnits as $u): ?>
                    <option value="<?= htmlspecialchars($u['unit_name']) ?>" <?= ($paramUnit === $u['unit_name']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($u['unit_title']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Shift Pill -->
        <div class="filter-select-pill" title="Filter by Shift">
            <i class="fa-regular fa-clock"></i>
            <label for="shiftSelect">Shift:</label>
            <select id="shiftSelect" onchange="submitShiftFilter(this.value)">
                <option value="">All Shifts</option>
                <option value="Day Shift" <?= ($paramShift === 'Day Shift') ? 'selected' : '' ?>>Day Shift</option>
                <option value="Night Shift" <?= ($paramShift === 'Night Shift') ? 'selected' : '' ?>>Night Shift</option>
            </select>
        </div>

        <!-- Reset Button -->
        <?php if ($activePeriodKey !== 'today' || !empty($paramShift) || !empty($paramUnit)): ?>
            <a href="dashboard.php" class="btn-reset-filter" title="Reset all filters back to Today">
                <i class="fa-solid fa-rotate-left"></i>
                <span>Reset</span>
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- 4 Modern High-Contrast KPI Cards Grid -->
<div class="kpi-stats-grid">
    <!-- Stat 1: Period Output Pieces -->
    <div class="kpi-metric-card">
        <div class="kpi-top-row">
            <span class="kpi-label">Production Output</span>
            <div class="kpi-icon-pill emerald">
                <i class="fa-solid fa-shirt"></i>
            </div>
        </div>
        <div class="kpi-value-block">
            <span class="kpi-value-num" style="color: #34d399;"><?= number_format($periodPieces) ?></span>
            <span class="kpi-unit-tag">pcs</span>
        </div>
        <div class="kpi-bottom-meta">
            <i class="fa-regular fa-calendar-check text-muted"></i>
            <span><?= htmlspecialchars($periodLabel) ?></span>
        </div>
    </div>

    <!-- Stat 2: Active Operators in Timeframe -->
    <div class="kpi-metric-card">
        <div class="kpi-top-row">
            <span class="kpi-label">Active Operators</span>
            <div class="kpi-icon-pill cyan">
                <i class="fa-solid fa-users"></i>
            </div>
        </div>
        <div class="kpi-value-block">
            <span class="kpi-value-num" style="color: #38bdf8;"><?= number_format($activeOperatorsCount) ?></span>
            <span class="kpi-unit-tag">/ <?= $totalWorkersCount ?></span>
        </div>
        <div class="kpi-bottom-meta">
            <i class="fa-solid fa-chart-pie text-muted"></i>
            <span><?= ($totalWorkersCount > 0) ? round(($activeOperatorsCount / $totalWorkersCount) * 100) . '% floor engagement' : 'Registered staff' ?></span>
        </div>
    </div>

    <!-- Stat 3: Batches Logged in Timeframe -->
    <div class="kpi-metric-card">
        <div class="kpi-top-row">
            <span class="kpi-label">Batches Logged</span>
            <div class="kpi-icon-pill amber">
                <i class="fa-solid fa-boxes-stacked"></i>
            </div>
        </div>
        <div class="kpi-value-block">
            <span class="kpi-value-num" style="color: #fbbf24;"><?= number_format($totalBatchesCount) ?></span>
            <span class="kpi-unit-tag">shifts</span>
        </div>
        <div class="kpi-bottom-meta">
            <i class="fa-regular fa-clock text-muted"></i>
            <span>Workstation shift entries</span>
        </div>
    </div>

    <!-- Stat 4: Plant Lifetime Volume -->
    <div class="kpi-metric-card">
        <div class="kpi-top-row">
            <span class="kpi-label">Lifetime Volume</span>
            <div class="kpi-icon-pill purple">
                <i class="fa-solid fa-industry"></i>
            </div>
        </div>
        <div class="kpi-value-block">
            <span class="kpi-value-num" style="color: #c084fc;"><?= number_format($allTimePieces) ?></span>
            <span class="kpi-unit-tag">pcs</span>
        </div>
        <div class="kpi-bottom-meta">
            <i class="fa-solid fa-building-gear text-muted"></i>
            <span>Across <?= count($productionUnits) ?> production units</span>
        </div>
    </div>
</div>

<!-- SECTION 1: Production Units Floor Overview -->
<div class="card mb-4" style="background: transparent; border: none; padding: 0; box-shadow: none;">
    <div class="dash-section-header">
        <div class="dash-section-title">
            <i class="fa-solid fa-layer-group text-cyan"></i>
            Production Lines &amp; Floor Engagement
        </div>
        <div class="dash-section-subtitle">
            Showing <?= count($unitStats) ?> operational units for <?= htmlspecialchars($periodLabel) ?>
        </div>
    </div>

    <div class="unit-cards-grid">
        <?php foreach ($unitStats as $us): 
            $u = $us['unit'];
            $colorClass = ($u['color'] === 'pink') ? 'pink' : 'cyan';
            $barColor = ($u['color'] === 'pink') ? 'linear-gradient(90deg, #ec4899, #f43f5e)' : 'linear-gradient(90deg, #0284c7, #38bdf8)';
            $unitIcon = (!empty($u['icon']) && $u['icon'] !== 'fa-building-gear') ? $u['icon'] : (($u['color'] === 'pink') ? 'fa-warehouse' : 'fa-industry');
        ?>
            <div class="unit-dash-card">
                <div class="unit-dash-header">
                    <div class="unit-title-group">
                        <div class="unit-badge-icon <?= $colorClass ?>">
                            <i class="fa-solid <?= htmlspecialchars($unitIcon) ?>"></i>
                        </div>
                        <div>
                            <h3 style="font-size: 1.1rem; font-weight: 700; margin: 0; color: var(--text-primary);">
                                <?= htmlspecialchars($u['unit_title']) ?>
                            </h3>
                            <span class="text-muted" style="font-size: 0.78rem;">
                                <?= htmlspecialchars($u['unit_name']) ?> • <?= $us['worker_count'] ?> registered workers
                            </span>
                        </div>
                    </div>
                    <span class="badge-pill" style="font-size: 0.75rem; background: rgba(255,255,255,0.08); padding: 0.2rem 0.6rem;">
                        <?= $us['share_pct'] ?>% share
                    </span>
                </div>

                <!-- Progress Bar -->
                <div class="dash-progress-wrap">
                    <div class="dash-progress-fill" style="width: <?= max($us['share_pct'], 4) ?>%; background: <?= $barColor ?>;"></div>
                </div>

                <!-- Metrics Split -->
                <div class="unit-stats-split">
                    <div class="unit-stat-box">
                        <div class="unit-stat-box-label">Selected Output</div>
                        <div class="unit-stat-box-val" style="color: #38bdf8;">
                            <?= number_format($us['period_pieces']) ?> <span style="font-size: 0.75rem; color: var(--text-muted); font-weight: 500;">pcs</span>
                        </div>
                    </div>

                    <div class="unit-stat-box">
                        <div class="unit-stat-box-label">All-Time Volume</div>
                        <div class="unit-stat-box-val" style="color: #fff;">
                            <?= number_format($us['all_time_pieces']) ?> <span style="font-size: 0.75rem; color: var(--text-muted); font-weight: 500;">pcs</span>
                        </div>
                    </div>
                </div>

                <!-- Footer Performer & Action -->
                <div class="unit-card-footer">
                    <div class="unit-card-op-info">
                        <?php if (!empty($us['top_operator'])): ?>
                            <span class="text-muted">Top Operator:</span>
                            <strong style="color: var(--primary-light);"><?= htmlspecialchars($us['top_operator']['name']) ?></strong>
                            <span style="color: var(--text-muted); font-size: 0.75rem;">(<?= $us['top_operator']['pcs'] ?> pcs)</span>
                        <?php else: ?>
                            <span class="text-muted"><i class="fa-regular fa-clock"></i> No logs logged today</span>
                        <?php endif; ?>
                    </div>

                    <a href="unit_workers.php?unit=<?= urlencode($u['unit_name']) ?>" class="btn btn-outline btn-sm unit-manage-btn">
                        <i class="fa-solid fa-users-gear"></i> Manage Unit
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
// Tabbed Date Mode Switcher & URL Navigation Helpers
const activeDateMode = '<?= $currentDateMode ?>';

function switchDateMode(mode) {
    if (mode === activeDateMode) {
        document.querySelectorAll('.date-pill-tab').forEach(tab => {
            tab.classList.toggle('active', tab.getAttribute('onclick') && tab.getAttribute('onclick').includes("'" + mode + "'"));
        });
        document.querySelectorAll('.date-mode-panel').forEach(panel => {
            panel.classList.toggle('active', panel.id === 'panel-' + mode);
        });
        return;
    }

    const url = new URL(window.location.href);
    url.searchParams.delete('date');
    url.searchParams.delete('month');
    url.searchParams.delete('year');
    url.searchParams.delete('from_date');
    url.searchParams.delete('to_date');

    if (mode === 'day') {
        url.searchParams.set('period', 'today');
    } else if (mode === 'month') {
        url.searchParams.set('period', 'this_month');
    } else if (mode === 'year') {
        url.searchParams.set('period', 'this_year');
    } else if (mode === 'all') {
        url.searchParams.set('period', 'all');
    }
    window.location.href = url.toString();
}

function submitDateFilter(val) {
    if (!val) return;
    const url = new URL(window.location.href);
    url.searchParams.delete('period');
    url.searchParams.delete('month');
    url.searchParams.delete('year');
    url.searchParams.set('date', val);
    window.location.href = url.toString();
}

function submitMonthFilter(val) {
    if (!val) return;
    const url = new URL(window.location.href);
    url.searchParams.delete('period');
    url.searchParams.delete('date');
    url.searchParams.delete('year');
    url.searchParams.set('month', val);
    window.location.href = url.toString();
}

function submitYearFilter(val) {
    if (!val) return;
    const url = new URL(window.location.href);
    url.searchParams.delete('period');
    url.searchParams.delete('date');
    url.searchParams.delete('month');
    url.searchParams.set('year', val);
    window.location.href = url.toString();
}

function submitPeriodAll() {
    const url = new URL(window.location.href);
    url.searchParams.delete('date');
    url.searchParams.delete('month');
    url.searchParams.delete('year');
    url.searchParams.set('period', 'all');
    window.location.href = url.toString();
}

function submitUnitFilter(val) {
    const url = new URL(window.location.href);
    if (val) {
        url.searchParams.set('unit', val);
    } else {
        url.searchParams.delete('unit');
    }
    window.location.href = url.toString();
}

function submitShiftFilter(val) {
    const url = new URL(window.location.href);
    if (val) {
        url.searchParams.set('shift', val);
    } else {
        url.searchParams.delete('shift');
    }
    window.location.href = url.toString();
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
