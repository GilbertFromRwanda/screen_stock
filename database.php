<?php
require_once 'config.php';

if (!isLoggedIn()) redirect('login.php');
if (($_SESSION['role'] ?? '') !== 'superadmin' && $_SESSION['role']!== 'admin') {
    $_SESSION['flash_error'] = "Super admin access only.";
    redirect('dashboard.php');
}

// ── AJAX: slow queries from performance_schema ───────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'slow_queries') {
    header('Content-Type: application/json');

    // 1. Verify performance_schema exists
    $ps_check = mysqli_query($conn, "SELECT 1 FROM performance_schema.events_statements_summary_by_digest LIMIT 1");
    if (!$ps_check) {
        echo json_encode(['error' => 'performance_schema is not available on this MySQL server. Enable it in my.ini: performance_schema=ON']);
        exit;
    }

    // 2. Check if the statements_digest consumer is enabled; if not, enable it
    $consumer_row = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT ENABLED FROM performance_schema.setup_consumers WHERE NAME = 'statements_digest'"
    ));
    $consumer_was_off = $consumer_row && $consumer_row['ENABLED'] === 'NO';
    if ($consumer_was_off) {
        mysqli_query($conn, "UPDATE performance_schema.setup_consumers SET ENABLED='YES' WHERE NAME='statements_digest'");
    }

    // 3. Check if statement instruments are enabled
    mysqli_query($conn, "UPDATE performance_schema.setup_instruments SET ENABLED='YES', TIMED='YES' WHERE NAME LIKE 'statement/%'");

    // 4. How many total rows exist in the digest table (for diagnostics)
    $total_rows = (int)(mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COUNT(*) c FROM performance_schema.events_statements_summary_by_digest"
    ))['c'] ?? 0);

    // 5. Query slowest digests — include current DB and NULL-schema system queries
    $db   = DB_NAME;
    $rows = [];
    $q = mysqli_query($conn, "
        SELECT
            DIGEST_TEXT                                         AS query,
            SCHEMA_NAME                                         AS db_name,
            COUNT_STAR                                          AS exec_count,
            ROUND(AVG_TIMER_WAIT  / 1000000000, 2)             AS avg_ms,
            ROUND(MAX_TIMER_WAIT  / 1000000000, 2)             AS max_ms,
            ROUND(SUM_TIMER_WAIT  / 1000000000, 2)             AS total_ms,
            SUM_ROWS_EXAMINED                                   AS rows_examined,
            ROUND(SUM_ROWS_EXAMINED / NULLIF(COUNT_STAR, 0), 0) AS avg_rows_examined,
            LAST_SEEN
        FROM performance_schema.events_statements_summary_by_digest
        WHERE DIGEST_TEXT IS NOT NULL
          AND COUNT_STAR > 0
          AND (SCHEMA_NAME = '$db' OR SCHEMA_NAME IS NULL)
        ORDER BY AVG_TIMER_WAIT DESC
        LIMIT 25
    ");
    if ($q) while ($r = mysqli_fetch_assoc($q)) $rows[] = $r;

    // 6. Slow query log variables
    $log_status = [];
    $lq = mysqli_query($conn, "SHOW VARIABLES LIKE 'slow_query%'");
    if ($lq) while ($r = mysqli_fetch_assoc($lq)) $log_status[$r['Variable_name']] = $r['Value'];
    $lq2 = mysqli_query($conn, "SHOW VARIABLES LIKE 'long_query_time'");
    if ($lq2) { $r = mysqli_fetch_assoc($lq2); if ($r) $log_status['long_query_time'] = $r['Value']; }

    // 7. Always include table stats + index analysis as fallback / supplement
    $table_stats = [];
    $ts_q = mysqli_query($conn, "
        SELECT TABLE_NAME,
               COALESCE(TABLE_ROWS, 0)                           AS row_est,
               ROUND(DATA_LENGTH  / 1024 / 1024, 2)             AS data_mb,
               ROUND(INDEX_LENGTH / 1024 / 1024, 2)             AS index_mb,
               ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2) AS total_mb
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = '$db'
        ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC
    ");
    if ($ts_q) while ($r = mysqli_fetch_assoc($ts_q)) $table_stats[] = $r;

    // 8. Tables with no non-primary indexes (potential full-scan risk)
    $no_index_tables = [];
    foreach ($table_stats as $t) {
        $tn = $t['TABLE_NAME'];
        $idx_q = mysqli_query($conn, "SHOW INDEX FROM `$tn`");
        $indexes = [];
        if ($idx_q) while ($ir = mysqli_fetch_assoc($idx_q)) {
            if ($ir['Key_name'] !== 'PRIMARY') $indexes[] = $ir['Key_name'];
        }
        if (empty($indexes) && (int)$t['row_est'] > 100) {
            $no_index_tables[] = ['table' => $tn, 'rows' => (int)$t['row_est']];
        }
    }

    // 9. Global slow-query counter
    $global_status = [];
    $gs_q = mysqli_query($conn, "SHOW GLOBAL STATUS WHERE Variable_name IN ('Slow_queries','Questions','Uptime','Threads_connected')");
    if ($gs_q) while ($r = mysqli_fetch_assoc($gs_q)) $global_status[$r['Variable_name']] = $r['Value'];

    echo json_encode([
        'queries'          => $rows,
        'log'              => $log_status,
        'total_in_digest'  => $total_rows,
        'consumer_enabled' => !$consumer_was_off,
        'consumer_fixed'   => $consumer_was_off,
        'table_stats'      => $table_stats,
        'no_index_tables'  => $no_index_tables,
        'global_status'    => $global_status,
    ]);
    exit;
}

// ── AJAX: EXPLAIN a query ─────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'explain') {
    header('Content-Type: application/json');
    $digest = trim($_POST['digest'] ?? '');
    if (empty($digest)) { echo json_encode(['error' => 'No query provided.']); exit; }
    // Sanitize: only allow SELECT statements through EXPLAIN
    $first_word = strtoupper(strtok(ltrim($digest), " \t\r\n("));
    if ($first_word !== 'SELECT') {
        echo json_encode(['error' => 'EXPLAIN is only supported for SELECT queries.']);
        exit;
    }
    $rows = [];
    $q = mysqli_query($conn, "EXPLAIN " . $digest);
    if (!$q) { echo json_encode(['error' => mysqli_error($conn)]); exit; }
    while ($r = mysqli_fetch_assoc($q)) $rows[] = $r;
    echo json_encode(['rows' => $rows]);
    exit;
}

// ── Database Backup (download as .sql) ───────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'backup') {
    $db_name  = DB_NAME;
    $filename = 'backup_' . $db_name . '_' . date('Y-m-d_H-i-s') . '.sql';

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "-- Database Backup: $db_name\n";
    echo "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
    echo "SET FOREIGN_KEY_CHECKS = 0;\n\n";

    $tables_q = mysqli_query($conn, "SHOW TABLES");
    while ($tbl_row = mysqli_fetch_array($tables_q)) {
        $table = $tbl_row[0];

        $create_q   = mysqli_query($conn, "SHOW CREATE TABLE `$table`");
        $create_row = mysqli_fetch_assoc($create_q);
        echo "DROP TABLE IF EXISTS `$table`;\n";
        echo $create_row['Create Table'] . ";\n\n";

        $rows_q = mysqli_query($conn, "SELECT * FROM `$table`");
        if (mysqli_num_rows($rows_q) > 0) {
            $fields = mysqli_fetch_fields($rows_q);
            $cols   = array_map(fn($f) => "`{$f->name}`", $fields);
            $prefix = "INSERT INTO `$table` (" . implode(', ', $cols) . ") VALUES\n";
            $batch  = [];

            while ($row = mysqli_fetch_row($rows_q)) {
                $vals = array_map(fn($v) => $v === null ? 'NULL' : "'" . mysqli_real_escape_string($conn, $v) . "'", $row);
                $batch[] = '(' . implode(', ', $vals) . ')';
                if (count($batch) >= 500) {
                    echo $prefix . implode(",\n", $batch) . ";\n";
                    $batch = [];
                }
            }
            if ($batch) echo $prefix . implode(",\n", $batch) . ";\n";
            echo "\n";
        }
    }

    echo "SET FOREIGN_KEY_CHECKS = 1;\n";
    exit;
}

// ── Allowed tables whitelist (prevents SQL injection via tab= param) ──────────
$allowed_tables_q = mysqli_query($conn, "SHOW TABLES");
$allowed_tables   = [];
while ($r = mysqli_fetch_array($allowed_tables_q)) $allowed_tables[] = $r[0];

function safe_table(string $name, array $allowed): string {
    return in_array($name, $allowed) ? $name : 'products';
}

$selected_table = safe_table($_GET['tab'] ?? 'products', $allowed_tables);

$flash_ok  = '';
$flash_err = '';

// ── Run updates.sql ───────────────────────────────────────────────────────────
if (isset($_POST['run_updates'])) {
    $sql_file = __DIR__ . '/db/updates.sql';
    if (!file_exists($sql_file)) {
        $flash_err = "updates.sql not found at db/updates.sql";
    } else {
        $sql   = file_get_contents($sql_file);
        // Split on semicolons, skip blanks and comment-only lines
        $stmts = array_filter(
            array_map('trim', explode(';', $sql)),
            function($s) { return $s !== '' && !preg_match('/^--/', $s); }
        );
        $ok_count  = 0;
        $err_lines = [];
        foreach ($stmts as $stmt) {
            if (mysqli_query($conn, $stmt)) {
                $ok_count++;
            } else {
                $err_lines[] = mysqli_error($conn);
            }
        }
        if (empty($err_lines)) {
            $flash_ok = "Updates applied — $ok_count statement(s) executed successfully.";
        } else {
            $flash_ok  = "$ok_count statement(s) OK.";
            $flash_err = "Errors: " . implode(' | ', $err_lines);
        }
    }
}

// ── Delete row (POST) ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_row'])) {
    $tbl = safe_table($_POST['table'] ?? '', $allowed_tables);
    $id  = (int)$_POST['row_id'];
    mysqli_query($conn, "DELETE FROM `$tbl` WHERE id = $id");
    $flash_ok = "Row #$id deleted from $tbl.";
}

// ── Trim whitespace on text columns ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['trim_data'])) {
    $tbl = safe_table($_POST['table'] ?? '', $allowed_tables);
    $cols_q = mysqli_query($conn, "SHOW COLUMNS FROM `$tbl`");
    $trimmed = 0;
    while ($col = mysqli_fetch_assoc($cols_q)) {
        if (preg_match('/varchar|text/i', $col['Type'])) {
            $f = mysqli_real_escape_string($conn, $col['Field']);
            mysqli_query($conn, "UPDATE `$tbl` SET `$f` = TRIM(`$f`) WHERE `$f` != TRIM(`$f`)");
            $trimmed += mysqli_affected_rows($conn);
        }
    }
    $flash_ok = "Whitespace trimmed — $trimmed value(s) updated in $tbl.";
}

// ── Clear database (superadmin only) ─────────────────────────────────────────
$is_superadmin = ($_SESSION['role'] ?? '') === 'superadmin';

// Groups: transactional (always offered), products, users
$CLEAR_GROUPS = [
    'transactions' => [
        'label'  => 'All Sales & Finance',
        'desc'   => 'sales_bulk, sales_retail, sales_external, loans, loan_payments, loan_clients, expenses, consumption, weekly_revenue, boaster',
        'tables' => ['sales_bulk','sales_retail','sales_external','loans','loan_payments','loan_clients','expenses','consumption','weekly_revenue','boaster'],
    ],
    'stock_data' => [
        'label'  => 'Stock & Purchases',
        'desc'   => 'stock, retail_stock, stock_movements, purchases, purchase_levels',
        'tables' => ['purchase_levels','purchases','stock_movements','retail_stock','stock'],
    ],
    'products' => [
        'label'  => 'Products',
        'desc'   => 'products table (all product definitions)',
        'tables' => ['products'],
    ],
    'users' => [
        'label'  => 'Users',
        'desc'   => 'users table — you will be logged out',
        'tables' => ['users'],
    ],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_db']) && $is_superadmin) {
    $chosen = $_POST['clear_groups'] ?? [];
    $to_clear = [];
    foreach ($chosen as $grp) {
        if (isset($CLEAR_GROUPS[$grp])) {
            foreach ($CLEAR_GROUPS[$grp]['tables'] as $t) {
                if (in_array($t, $allowed_tables)) $to_clear[] = $t;
            }
        }
    }

    if (empty($to_clear)) {
        $flash_err = "No groups selected — nothing was cleared.";
    } else {
        mysqli_query($conn, "SET FOREIGN_KEY_CHECKS = 0");
        $cleared = [];
        foreach ($to_clear as $t) {
            try {
                // DELETE + reset auto_increment is more FK-safe than TRUNCATE
                mysqli_query($conn, "DELETE FROM `$t`");
                mysqli_query($conn, "ALTER TABLE `$t` AUTO_INCREMENT = 1");
                $cleared[] = $t;
            } catch (mysqli_sql_exception $e) {
                $flash_err .= "Error clearing $t: " . $e->getMessage() . " ";
            }
        }
        mysqli_query($conn, "SET FOREIGN_KEY_CHECKS = 1");
        $flash_ok = "Cleared " . count($cleared) . " table(s): " . implode(', ', $cleared) . ".";

        // If users table was cleared, log out
        if (in_array('users', $to_clear)) {
            session_destroy();
            header("Location: login.php"); exit;
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database — Screen Stock</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .db-header {
            display: flex; align-items: center; justify-content: space-between;
            flex-wrap: wrap; gap: 12px; margin-bottom: 24px;
        }
        .db-header h1 { margin: 0; font-size: 22px; font-weight: 700; }

        /* Table picker */
        .tbl-picker {
            display: flex; flex-wrap: wrap; gap: 6px;
            background: var(--white); border: 1px solid var(--gray-200);
            border-radius: 12px; padding: 14px 16px; margin-bottom: 20px;
        }
        .tbl-pill {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 5px 12px; border-radius: 20px; font-size: 12.5px; font-weight: 500;
            text-decoration: none; color: var(--secondary);
            background: var(--gray-100); border: 1px solid var(--gray-200);
            transition: background .15s, border-color .15s, color .15s;
        }
        .tbl-pill:hover { background: var(--gray-200); color: var(--dark); }
        .tbl-pill.active {
            background: #1e40af; color: #fff; border-color: #1e40af;
        }
        .tbl-pill .cnt {
            font-size: 11px; padding: 1px 6px; border-radius: 10px;
            background: rgba(0,0,0,.12);
        }

        /* Toolbar */
        .db-toolbar {
            display: flex; align-items: center; flex-wrap: wrap; gap: 10px;
            margin-bottom: 16px;
        }
        .db-toolbar input[type=text] {
            flex: 1; min-width: 180px; max-width: 320px;
            padding: 8px 12px; border: 1px solid var(--gray-300);
            border-radius: var(--radius); font-size: 13.5px;
        }
        .db-toolbar input[type=text]:focus {
            outline: none; border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37,99,235,.12);
        }

        /* Updates panel */
        .updates-panel {
            background: #f0fdf4; border: 1px solid #bbf7d0;
            border-radius: 12px; padding: 16px 20px; margin-bottom: 20px;
            display: flex; align-items: center; flex-wrap: wrap; gap: 14px;
        }
        .updates-panel .up-title { font-weight: 700; font-size: 14px; color: #15803d; flex: 1; min-width: 200px; }
        .updates-panel p { font-size: 12.5px; color: #166534; margin: 4px 0 0; }


        /* Table */
        .db-table-wrap { overflow-x: auto; border: 1px solid var(--gray-200); border-radius: 10px; }
        .db-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .db-table thead th {
            background: var(--gray-100); padding: 10px 12px;
            text-align: left; font-size: 11.5px; font-weight: 700;
            text-transform: uppercase; letter-spacing: .4px; color: var(--secondary);
            border-bottom: 1px solid var(--gray-200); white-space: nowrap;
        }
        .db-table tbody td {
            padding: 8px 12px; border-bottom: 1px solid var(--gray-100);
            color: var(--dark); vertical-align: top; max-width: 200px;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .db-table tbody tr:last-child td { border-bottom: none; }
        .db-table tbody tr:hover td { background: var(--gray-100); }

        /* Pagination */
        .pagination {
            display: flex; align-items: center; gap: 4px;
            margin-top: 14px; justify-content: flex-end;
        }
        .pagination a, .pagination span {
            padding: 5px 11px; border-radius: var(--radius);
            font-size: 13px; text-decoration: none;
            border: 1px solid var(--gray-200); color: var(--dark);
        }
        .pagination a:hover { background: var(--gray-100); }
        .pagination .cur { background: #1e40af; color: #fff; border-color: #1e40af; }
        .pagination .disabled { color: var(--gray-300); pointer-events: none; }

        /* Slow queries panel */
        .sq-panel {
            background:#fff; border:1px solid var(--gray-200); border-radius:12px;
            padding:18px 20px; margin-bottom:20px;
        }
        .sq-head {
            display:flex; align-items:center; gap:10px; margin-bottom:14px; flex-wrap:wrap;
        }
        .sq-head h3 { margin:0; font-size:15px; font-weight:700; flex:1; }
        .sq-badge {
            font-size:11px; padding:2px 8px; border-radius:10px; font-weight:600;
        }
        .sq-badge.enabled  { background:#dcfce7; color:#15803d; }
        .sq-badge.disabled { background:#fee2e2; color:#991b1b; }
        .sq-table { width:100%; border-collapse:collapse; font-size:12.5px; }
        .sq-table th {
            background:var(--gray-100); padding:8px 10px; text-align:left;
            font-size:11px; font-weight:700; text-transform:uppercase;
            letter-spacing:.4px; color:var(--secondary); border-bottom:1px solid var(--gray-200);
            white-space:nowrap;
        }
        .sq-table td {
            padding:8px 10px; border-bottom:1px solid var(--gray-100);
            vertical-align:top; color:var(--dark);
        }
        .sq-table tr:last-child td { border-bottom:none; }
        .sq-table tr:hover td { background:var(--gray-100); }
        .sq-query {
            font-family:monospace; font-size:11.5px; color:#1e40af;
            word-break:break-all; max-width:380px; line-height:1.5;
        }
        .sq-slow  { color:#dc2626; font-weight:700; }
        .sq-med   { color:#d97706; font-weight:600; }
        .sq-fast  { color:#16a34a; }
        .sq-bar-wrap { background:var(--gray-100); border-radius:3px; height:6px; width:80px; margin-top:3px; }
        .sq-bar { height:6px; border-radius:3px; background:#3b82f6; }
        .explain-rows { font-size:11.5px; margin-top:6px; padding:10px; background:#f8fafc;
            border-radius:6px; border:1px solid var(--gray-200); overflow-x:auto; }

        .badge-del {
            font-size: 11px; padding: 2px 7px; border-radius: 10px;
            background: #fee2e2; color: #991b1b; font-weight: 600;
        }
        .flash-ok  { background:#ecfdf5; border:1px solid #6ee7b7; color:#065f46; padding:12px 16px; border-radius:8px; margin-bottom:16px; }
        .flash-err { background:#fef2f2; border:1px solid #fca5a5; color:#991b1b; padding:12px 16px; border-radius:8px; margin-bottom:16px; }

        /* Clear panel */
        .clear-panel {
            background: #fff; border: 2px solid #fca5a5;
            border-radius: 12px; padding: 20px 22px; margin-bottom: 20px;
        }
        .clear-panel-head {
            display: flex; align-items: center; gap: 10px; cursor: pointer;
            user-select: none;
        }
        .clear-panel-head h3 { margin: 0; font-size: 15px; font-weight: 700; color: #991b1b; }
        .clear-panel-body { margin-top: 16px; }
        .clear-groups {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
            gap: 10px; margin-bottom: 16px;
        }
        .clear-group-card {
            border: 2px solid var(--gray-200); border-radius: 10px; padding: 12px 14px;
            cursor: pointer; transition: border-color .15s, background .15s;
        }
        .clear-group-card:has(input:checked) {
            border-color: #f87171; background: #fff1f2;
        }
        .clear-group-card label {
            display: flex; align-items: flex-start; gap: 10px; cursor: pointer;
        }
        .clear-group-card input[type=checkbox] { margin-top: 2px; flex-shrink: 0; accent-color: #dc2626; }
        .cg-title { font-size: 13.5px; font-weight: 700; color: var(--dark); }
        .cg-desc  { font-size: 11.5px; color: var(--secondary); margin-top: 3px; line-height: 1.4; }
        .clear-group-card.danger-group:has(input:checked) {
            border-color: #dc2626; background: #fee2e2;
        }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php include 'sidebar.php'; ?>

    <div class="main-content">

        <div class="db-header">
            <h1>Database Management</h1>
            <a href="database.php?action=backup" class="btn btn-primary"
               style="font-size:13.5px;padding:8px 18px;text-decoration:none;">
                ⬇ Backup Database
            </a>
        </div>

        <?php if ($flash_ok):  ?><div class="flash-ok"><?= htmlspecialchars($flash_ok) ?></div><?php endif; ?>
        <?php if ($flash_err): ?><div class="flash-err"><?= htmlspecialchars($flash_err) ?></div><?php endif; ?>

        <!-- ── Run Updates ──────────────────────────────────────────────────── -->
        <div class="updates-panel">
            <div>
                <div class="up-title">Apply DB Updates (updates.sql)</div>
                <p>Runs all ALTER TABLE / CREATE TABLE statements from <code>db/updates.sql</code>. Safe to run multiple times.</p>
            </div>
            <form method="POST">
                <button type="submit" name="run_updates" class="btn btn-primary"
                        onclick="return confirm('Run updates.sql now?')">
                    ▶ Run Updates
                </button>
            </form>
        </div>

        <!-- ── Clear Database (superadmin only) ──────────────────────────────── -->
        <?php if ($is_superadmin): ?>
        <div class="clear-panel">
            <div class="clear-panel-head" onclick="toggleClearPanel()">
                <span style="font-size:18px;">🗑️</span>
                <h3>Clear Database Data</h3>
                <span id="clearChevron" style="margin-left:auto;font-size:12px;color:#991b1b;transition:transform .2s;">&#9654;</span>
            </div>

            <div class="clear-panel-body" id="clearPanelBody" style="display:none;">
                <p style="font-size:13px;color:#7f1d1d;margin:0 0 14px;">
                    Select which groups to wipe. <strong>Products and Users are opt-in</strong> — unchecked by default.
                    This action is <strong>irreversible</strong>.
                </p>

                <form method="POST" id="clearForm" onsubmit="return confirmClear()">
                    <div class="clear-groups">
                        <?php foreach ($CLEAR_GROUPS as $key => $grp):
                            $is_danger = in_array($key, ['products','users']);
                        ?>
                        <div class="clear-group-card<?= $is_danger ? ' danger-group' : '' ?>">
                            <label>
                                <input type="checkbox" name="clear_groups[]" value="<?= $key ?>"
                                    <?= $is_danger ? '' : 'checked' ?>>
                                <div>
                                    <div class="cg-title">
                                        <?= $is_danger ? '⚠️ ' : '' ?>
                                        <?= htmlspecialchars($grp['label']) ?>
                                    </div>
                                    <div class="cg-desc"><?= htmlspecialchars($grp['desc']) ?></div>
                                </div>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                        <button type="submit" name="clear_db" class="btn btn-danger"
                                style="background:#dc2626;border-color:#dc2626;">
                            🗑 Clear Selected Groups
                        </button>
                        <span style="font-size:12px;color:#991b1b;">
                            You will be asked to confirm before anything is deleted.
                        </span>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- ── Slow Queries ────────────────────────────────────────────────────── -->
        <div class="sq-panel">
            <div class="sq-head">
                <span style="font-size:18px;">🐢</span>
                <h3>Slowest Queries</h3>
                <span id="sq-log-badge" class="sq-badge" style="display:none;"></span>
                <span id="sq-log-threshold" style="font-size:12px;color:var(--secondary);display:none;"></span>
                <button onclick="loadSlowQueries()" class="btn btn-secondary"
                    style="font-size:12px;padding:5px 12px;margin-left:auto;" id="sq-refresh-btn">
                    ↻ Load / Refresh
                </button>
            </div>
            <div id="sq-body">
                <p style="font-size:13px;color:var(--secondary);text-align:center;padding:24px 0;">
                    Click <strong>Load / Refresh</strong> to analyse query performance from <code>performance_schema</code>.
                </p>
            </div>
        </div>

        <!-- ── Table picker ─────────────────────────────────────────────────── -->
        <div class="tbl-picker">
            <?php foreach ($allowed_tables as $tbl):
                $cnt = (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM `$tbl`"))['c'] ?? 0);
            ?>
            <a href="?tab=<?= urlencode($tbl) ?>"
               class="tbl-pill<?= $selected_table === $tbl ? ' active' : '' ?>">
                <?= htmlspecialchars($tbl) ?>
                <span class="cnt"><?= number_format($cnt) ?></span>
            </a>
            <?php endforeach; ?>
        </div>

        <!-- ── Toolbar ──────────────────────────────────────────────────────── -->
        <div class="db-toolbar">
            <form method="POST">
                <input type="hidden" name="table" value="<?= htmlspecialchars($selected_table) ?>">
                <button type="submit" name="trim_data" class="btn btn-secondary"
                        style="font-size:12.5px;padding:6px 14px;"
                        onclick="return confirm('Trim whitespace from text columns in <?= htmlspecialchars($selected_table) ?>?')">
                    ✂ Trim Whitespace
                </button>
            </form>
        </div>



    </div>
</div>

<script>
function toggleClearPanel() {
    var body    = document.getElementById('clearPanelBody');
    var chevron = document.getElementById('clearChevron');
    var open    = body.style.display !== 'none';
    body.style.display      = open ? 'none' : 'block';
    chevron.style.transform = open ? '' : 'rotate(90deg)';
}

function confirmClear() {
    var checked = document.querySelectorAll('#clearForm input[type=checkbox]:checked');
    if (checked.length === 0) {
        alert('No groups selected.');
        return false;
    }
    var names = Array.from(checked).map(function(cb) {
        return cb.closest('.clear-group-card').querySelector('.cg-title').textContent.trim();
    });
    var hasUsers    = Array.from(checked).some(function(cb) { return cb.value === 'users'; });
    var hasProducts = Array.from(checked).some(function(cb) { return cb.value === 'products'; });

    var msg = 'You are about to PERMANENTLY DELETE all data in:\n\n  • ' + names.join('\n  • ');
    if (hasUsers)    msg += '\n\n⚠️ Clearing Users will log you out immediately!';
    if (hasProducts) msg += '\n\n⚠️ Clearing Products will also remove all linked stock and sales data!';
    msg += '\n\nThis CANNOT be undone. Type YES to confirm:';

    var answer = prompt(msg);
    return answer !== null && answer.trim().toUpperCase() === 'YES';
}

// ── Slow queries ──────────────────────────────────────────────────────────────
function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function loadSlowQueries() {
    var btn  = document.getElementById('sq-refresh-btn');
    var body = document.getElementById('sq-body');
    btn.textContent = '⏳ Loading…';
    btn.disabled    = true;
    body.innerHTML  =
        '<p style="text-align:center;padding:24px 0;color:var(--secondary);">' +
        '<span style="display:inline-block;width:22px;height:22px;border:3px solid #e5e7eb;border-top-color:var(--primary);' +
        'border-radius:50%;animation:sq-spin .7s linear infinite;vertical-align:middle;margin-right:8px;"></span>' +
        'Querying performance_schema…</p>' +
        '<style>@keyframes sq-spin{to{transform:rotate(360deg);}}</style>';

    fetch('database.php?action=slow_queries')
        .then(function(r){ return r.json(); })
        .then(function(d) {
            btn.textContent = '↻ Refresh';
            btn.disabled    = false;

            if (d.error) {
                body.innerHTML = '<p style="color:var(--danger);padding:12px;">⚠ ' + esc(d.error) + '</p>';
                return;
            }

            // Slow log status badges
            var logBadge  = document.getElementById('sq-log-badge');
            var logThresh = document.getElementById('sq-log-threshold');
            if (d.log && d.log.slow_query_log !== undefined) {
                var on = d.log.slow_query_log === 'ON';
                logBadge.textContent   = on ? 'Slow Log ON' : 'Slow Log OFF';
                logBadge.className     = 'sq-badge ' + (on ? 'enabled' : 'disabled');
                logBadge.style.display = '';
                if (d.log.long_query_time) {
                    logThresh.textContent   = 'Threshold: ' + d.log.long_query_time + 's';
                    logThresh.style.display = '';
                }
            }

            // Show if consumer was just enabled
            if (d.consumer_fixed) {
                var notice = document.createElement('div');
                notice.style.cssText = 'background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px 14px;font-size:12.5px;color:#92400e;margin-bottom:12px;';
                notice.innerHTML = '⚡ <strong>statements_digest consumer was OFF</strong> — it has been enabled automatically. Run your operations now and refresh to see data.';
                body.parentNode.insertBefore(notice, body);
            }

            if (!d.queries || d.queries.length === 0) {
                // Show fallback: table stats + global status instead
                body.innerHTML = renderFallback(d);
                return;
            }

            var maxAvg = Math.max.apply(null, d.queries.map(function(q){ return parseFloat(q.avg_ms) || 0; }));

            var html = '<div style="overflow-x:auto;"><table class="sq-table">' +
                '<thead><tr>' +
                '<th>#</th><th>Query</th><th>Runs</th>' +
                '<th>Avg ms</th><th>Max ms</th><th>Total ms</th>' +
                '<th>Avg Rows Examined</th><th>Last Seen</th><th></th>' +
                '</tr></thead><tbody>';

            d.queries.forEach(function(q, i) {
                var avg    = parseFloat(q.avg_ms)  || 0;
                var max    = parseFloat(q.max_ms)  || 0;
                var avgCls = avg > 500 ? 'sq-slow' : avg > 100 ? 'sq-med' : 'sq-fast';
                var barClr = avg > 500 ? '#ef4444' : avg > 100 ? '#f59e0b' : '#22c55e';
                var barW   = maxAvg > 0 ? Math.round(avg / maxAvg * 100) : 0;
                var shortQ = q.query ? q.query.substring(0, 220) + (q.query.length > 220 ? '…' : '') : '—';
                var lastSeen = q.LAST_SEEN ? new Date(q.LAST_SEEN).toLocaleString() : '—';
                var canExplain = q.query && q.query.trimStart().toUpperCase().startsWith('SELECT');

                html += '<tr id="sq-row-' + i + '">' +
                    '<td style="color:var(--secondary);font-size:11px;">' + (i + 1) + '</td>' +
                    '<td><div class="sq-query">' + esc(shortQ) + '</div></td>' +
                    '<td>' + Number(q.exec_count).toLocaleString() + '</td>' +
                    '<td><span class="' + avgCls + '">' + avg + '</span>' +
                        '<div class="sq-bar-wrap"><div class="sq-bar" style="width:' + barW + '%;background:' + barClr + ';"></div></div></td>' +
                    '<td class="' + avgCls + '">' + max + '</td>' +
                    '<td style="color:var(--secondary);">' + parseFloat(q.total_ms).toLocaleString() + '</td>' +
                    '<td>' + Number(q.avg_rows_examined || 0).toLocaleString() + '</td>' +
                    '<td style="color:var(--secondary);font-size:11px;white-space:nowrap;">' + esc(lastSeen) + '</td>' +
                    '<td>' + (canExplain
                        ? '<button onclick="explainQuery(' + i + ',' + JSON.stringify(q.query) + ')" class="btn btn-secondary" style="font-size:11px;padding:3px 8px;">EXPLAIN</button>'
                        : '') + '</td>' +
                    '</tr>' +
                    '<tr id="sq-explain-' + i + '" style="display:none;">' +
                    '<td colspan="9" id="sq-explain-body-' + i + '" style="padding:0 12px 12px;"></td></tr>';
            });

            html += '</tbody></table></div>';
            body.innerHTML = html;
        })
        .catch(function(err) {
            btn.textContent = '↻ Refresh';
            btn.disabled    = false;
            body.innerHTML  = '<p style="color:var(--danger);padding:12px;">Request failed: ' + esc(String(err)) + '</p>';
        });
}

function renderFallback(d) {
    var fmt = function(n) { return parseFloat(n).toLocaleString(); };

    // Global status bar
    var gs = d.global_status || {};
    var slowQ   = parseInt(gs.Slow_queries    || 0);
    var totalQ  = parseInt(gs.Questions       || 0);
    var uptime  = parseInt(gs.Uptime          || 0);
    var threads = parseInt(gs.Threads_connected || 0);
    var uptimeStr = uptime > 3600
        ? Math.floor(uptime/3600) + 'h ' + Math.floor((uptime%3600)/60) + 'm'
        : Math.floor(uptime/60) + 'm';

    var html = '<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:12px 16px;font-size:12.5px;color:#92400e;margin-bottom:16px;">'+
        '⚠ <strong>performance_schema digest is empty</strong> — '+
        (d.consumer_fixed ? 'tracking was just enabled. ' : 'MySQL may have restarted. ')+
        'Showing table statistics instead. <strong>Use the app normally, then click Refresh</strong> to see live query data.'+
        '</div>';

    // Server status strip
    html += '<div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:18px;">';
    var statusItems = [
        { icon:'⚡', label:'Slow Queries', val: slowQ.toLocaleString(), color: slowQ > 0 ? '#dc2626' : '#16a34a' },
        { icon:'📊', label:'Total Queries', val: totalQ.toLocaleString(), color:'#1e40af' },
        { icon:'⏱', label:'Uptime', val: uptimeStr, color:'#6b7280' },
        { icon:'🔗', label:'Connections', val: threads.toLocaleString(), color:'#7c3aed' },
    ];
    statusItems.forEach(function(s) {
        html += '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px 16px;flex:1;min-width:120px;">'+
            '<div style="font-size:11px;color:var(--secondary);text-transform:uppercase;letter-spacing:.5px;">'+s.icon+' '+esc(s.label)+'</div>'+
            '<div style="font-size:20px;font-weight:700;color:'+s.color+';margin-top:4px;">'+esc(s.val)+'</div>'+
            '</div>';
    });
    html += '</div>';

    // Index warnings
    if (d.no_index_tables && d.no_index_tables.length > 0) {
        html += '<div style="background:#fff1f2;border:1px solid #fca5a5;border-radius:8px;padding:12px 16px;margin-bottom:16px;">'+
            '<strong style="color:#991b1b;">⚠ Tables with no secondary indexes (full-scan risk):</strong>'+
            '<ul style="margin:6px 0 0 16px;font-size:12.5px;color:#7f1d1d;">';
        d.no_index_tables.forEach(function(t) {
            html += '<li><code>'+esc(t.table)+'</code> — ~'+t.rows.toLocaleString()+' rows — consider adding indexes on frequently filtered columns</li>';
        });
        html += '</ul></div>';
    }

    // Table sizes
    if (d.table_stats && d.table_stats.length > 0) {
        var maxMb = Math.max.apply(null, d.table_stats.map(function(t){ return parseFloat(t.total_mb)||0; }));
        html += '<h4 style="font-size:13px;font-weight:700;margin:0 0 10px;color:var(--dark);">Table Sizes &amp; Row Counts</h4>'+
            '<div style="overflow-x:auto;"><table class="sq-table">'+
            '<thead><tr><th>Table</th><th>Rows (est.)</th><th>Data MB</th><th>Index MB</th><th>Total MB</th><th>Size</th></tr></thead><tbody>';
        d.table_stats.forEach(function(t) {
            var mb    = parseFloat(t.total_mb) || 0;
            var barW  = maxMb > 0 ? Math.round(mb / maxMb * 100) : 0;
            var barClr= mb > 10 ? '#ef4444' : mb > 1 ? '#f59e0b' : '#22c55e';
            var rowEst= parseInt(t.row_est) || 0;
            html += '<tr>'+
                '<td><code style="font-size:12px;">'+esc(t.TABLE_NAME)+'</code></td>'+
                '<td>'+rowEst.toLocaleString()+'</td>'+
                '<td style="color:var(--secondary);">'+fmt(t.data_mb)+'</td>'+
                '<td style="color:var(--secondary);">'+fmt(t.index_mb)+'</td>'+
                '<td style="font-weight:600;">'+fmt(t.total_mb)+'</td>'+
                '<td style="min-width:80px;">'+
                    '<div class="sq-bar-wrap" style="width:100px;">'+
                    '<div class="sq-bar" style="width:'+barW+'%;background:'+barClr+';"></div></div>'+
                '</td>'+
                '</tr>';
        });
        html += '</tbody></table></div>';
    }

    return html;
}

function explainQuery(idx, query) {
    var row  = document.getElementById('sq-explain-' + idx);
    var cell = document.getElementById('sq-explain-body-' + idx);
    if (row.style.display !== 'none') { row.style.display = 'none'; return; }

    cell.innerHTML = '<div style="padding:8px;color:var(--secondary);">Running EXPLAIN…</div>';
    row.style.display = '';

    var fd = new FormData();
    fd.append('digest', query);
    fetch('database.php?action=explain', { method: 'POST', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(d) {
            if (d.error) { cell.innerHTML = '<p style="color:var(--danger);padding:8px;">' + esc(d.error) + '</p>'; return; }
            if (!d.rows || d.rows.length === 0) { cell.innerHTML = '<p style="padding:8px;">No EXPLAIN output.</p>'; return; }
            var keys = Object.keys(d.rows[0]);
            var html = '<div class="explain-rows"><table style="border-collapse:collapse;font-size:11.5px;width:100%;">' +
                '<tr>' + keys.map(function(k) {
                    return '<th style="padding:4px 8px;background:#f1f5f9;border:1px solid #e2e8f0;white-space:nowrap;">' + esc(k) + '</th>';
                }).join('') + '</tr>';
            d.rows.forEach(function(row) {
                var rowStyle = '';
                if (row.type && ['ALL', 'index'].includes(row.type)) rowStyle = 'background:#fff7ed;';
                if (row.Extra && String(row.Extra).includes('Using filesort'))  rowStyle = 'background:#fff1f2;';
                html += '<tr style="' + rowStyle + '">' + keys.map(function(k) {
                    var v = row[k] != null ? row[k] : '—';
                    var tdStyle = '';
                    if (k === 'type' && ['ALL', 'index'].includes(String(v))) tdStyle = 'color:#dc2626;font-weight:700;';
                    if (k === 'rows') tdStyle = 'color:#1e40af;font-weight:600;';
                    return '<td style="padding:4px 8px;border:1px solid #e2e8f0;' + tdStyle + '">' + esc(String(v)) + '</td>';
                }).join('') + '</tr>';
            });
            html += '</table></div>';
            cell.innerHTML = html;
        })
        .catch(function() { cell.innerHTML = '<p style="color:var(--danger);padding:8px;">EXPLAIN request failed.</p>'; });
}
</script>
</body>
</html>
