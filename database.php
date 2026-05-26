<?php
require_once 'config.php';

if (!isLoggedIn()) redirect('login.php');
if (($_SESSION['role'] ?? '') !== 'admin') {
    $_SESSION['flash_error'] = "Admin access only.";
    redirect('dashboard.php');
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

// ── Clear database ────────────────────────────────────────────────────────────
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_db'])) {
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
            if (mysqli_query($conn, "TRUNCATE TABLE `$t`")) {
                $cleared[] = $t;
            } else {
                $flash_err .= "Error clearing $t: " . mysqli_error($conn) . " ";
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

        <!-- ── Clear Database ──────────────────────────────────────────────────── -->
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
</script>
</body>
</html>
