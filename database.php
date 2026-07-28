<?php
require_once 'config.php';

if (!isLoggedIn()) redirect('login.php');
if (($_SESSION['role'] ?? '') !== 'superadmin') {
    $_SESSION['flash_error'] = "Super admin access only.";
    redirect('dashboard.php');
}

// ── Allowed tables whitelist (prevents SQL injection) ─────────────────────────
$allowed_tables_q = mysqli_query($conn, "SHOW TABLES");
$allowed_tables   = [];
while ($r = mysqli_fetch_array($allowed_tables_q)) $allowed_tables[] = $r[0];

$DANGER_TABLES = ['products','users','user_permissions','companies','user_company_access','categories','currency_rates'];

// ── AJAX: table list with row counts ──────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'list_tables') {
    header('Content-Type: application/json');
    $rows = [];
    foreach ($allowed_tables as $tbl) {
        $cnt = (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM `$tbl`"))['c'] ?? 0);
        $rows[] = ['name' => $tbl, 'count' => $cnt, 'danger' => in_array($tbl, $DANGER_TABLES)];
    }
    echo json_encode($rows);
    exit;
}

$flash_ok  = '';
$flash_err = '';

// ── Clear database (superadmin only) ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_db'])) {
    $chosen   = $_POST['clear_tables'] ?? [];
    $to_clear = array_values(array_intersect($chosen, $allowed_tables));

    if (empty($to_clear)) {
        $flash_err = "No tables selected — nothing was cleared.";
    } else {
        mysqli_query($conn, "SET FOREIGN_KEY_CHECKS = 0");
        $cleared = [];
        foreach ($to_clear as $t) {
            // DELETE + reset auto_increment is more FK-safe than TRUNCATE
            if (mysqli_query($conn, "DELETE FROM `$t`")) {
                mysqli_query($conn, "ALTER TABLE `$t` AUTO_INCREMENT = 1");
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
    <link rel="stylesheet" href="css/style.css?v=<?php echo filemtime(__DIR__ . '/css/style.css'); ?>">
    <style>
        .db-header {
            display: flex; align-items: center; justify-content: space-between;
            flex-wrap: wrap; gap: 12px; margin-bottom: 24px;
        }
        .db-header h1 { margin: 0; font-size: 22px; font-weight: 700; }

        .flash-ok  { background:#ecfdf5; border:1px solid #6ee7b7; color:#065f46; padding:12px 16px; border-radius:8px; margin-bottom:16px; }
        .flash-err { background:#fef2f2; border:1px solid #fca5a5; color:#991b1b; padding:12px 16px; border-radius:8px; margin-bottom:16px; }

        /* Clear panel */
        .clear-panel {
            background: #fff; border: 2px solid #fca5a5;
            border-radius: 12px; padding: 20px 22px; margin-bottom: 20px;
        }
        .clear-panel h3 { margin: 0 0 4px; font-size: 15px; font-weight: 700; color: #991b1b; }
        .clear-panel > p { font-size: 12.5px; color: #7f1d1d; margin: 0 0 16px; }
        .clear-table-search {
            width: 100%; padding: 8px 12px; margin-bottom: 8px;
            border: 1px solid var(--gray-300); border-radius: var(--radius);
            font-size: 13px; box-sizing: border-box;
        }
        .clear-table-search:focus {
            outline: none; border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(16,48,96,.12);
        }
        .clear-table-list {
            width: 100%; height: 320px; overflow-y: auto;
            border: 1px solid var(--gray-200); border-radius: 10px;
            padding: 6px; box-sizing: border-box;
            background: #fff; box-shadow: inset 0 1px 2px rgba(0,0,0,.03);
        }
        .clear-row {
            display: flex; align-items: center; gap: 10px;
            padding: 8px 10px; margin: 1px 0; border-radius: 6px;
            font-size: 13px; cursor: pointer; user-select: none;
        }
        .clear-row:hover { background: var(--gray-100); }
        .clear-row .crow-chk {
            width: 16px; height: 16px; border-radius: 4px; flex: none;
            border: 1.5px solid var(--gray-300); position: relative;
        }
        .clear-row .crow-name { flex: 1; color: var(--dark); }
        .clear-row .crow-cnt { font-size: 11.5px; color: var(--secondary); }
        .clear-row.danger .crow-name { color: #b91c1c; font-weight: 500; }
        .clear-row.selected { background: #eef2ff; }
        .clear-row.selected .crow-chk {
            background: #103060; border-color: #103060;
        }
        .clear-row.selected .crow-chk::after {
            content: ''; position: absolute; left: 4px; top: 1px;
            width: 4px; height: 8px; border: solid #fff;
            border-width: 0 2px 2px 0; transform: rotate(45deg);
        }
        .clear-row.danger.selected .crow-chk { background: #7f1d1d; border-color: #7f1d1d; }
        .clear-row.selected .crow-cnt { color: var(--secondary); }
        .clear-table-list::-webkit-scrollbar { width: 10px; }
        .clear-table-list::-webkit-scrollbar-track { background: transparent; }
        .clear-table-list::-webkit-scrollbar-thumb { background: var(--gray-200); border-radius: 10px; }
        .clear-table-list::-webkit-scrollbar-thumb:hover { background: var(--gray-300); }

        .selected-tables-box { margin-top: 10px; }
        .stb-title { font-size: 12px; font-weight: 700; color: var(--secondary); margin-bottom: 6px; }
        .stb-chips { display: flex; flex-wrap: wrap; gap: 6px; }
        .chip {
            display: inline-flex; align-items: center; gap: 6px;
            background: #103060; color: #fff; border-radius: 20px;
            padding: 4px 6px 4px 12px; font-size: 12px; font-weight: 500;
        }
        .chip.danger { background: #7f1d1d; }
        .chip .chip-x {
            width: 16px; height: 16px; border-radius: 50%; border: none;
            background: rgba(255,255,255,.2); color: #fff; cursor: pointer;
            font-size: 12px; line-height: 1; display: flex; align-items: center; justify-content: center;
        }
        .chip .chip-x:hover { background: rgba(255,255,255,.35); }
        .btn-clear {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 6px 14px; border-radius: var(--radius); font-size: 12.5px;
            font-weight: 600; cursor: pointer; border: 1px solid #dc2626;
            background: #dc2626; color: #fff; transition: background .15s;
        }
        .btn-clear:hover { background: #b91c1c; }
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

        <!-- ── Clear Database (superadmin only) ──────────────────────────────── -->
        <div class="clear-panel">
            <h3>🗑️ Clear Database Data</h3>
            <p>Click tables one by one to select them, then delete all rows in the selected ones.</p>
            <form method="POST" onsubmit="return confirmClearTables()">
                <input type="text" id="clear-table-search" class="clear-table-search"
                       placeholder="Filter tables…" oninput="renderTableList()" autocomplete="off">
                <div class="clear-table-list" id="clear-table-list">
                    <p style="padding:8px;font-size:12.5px;color:var(--secondary);">Loading tables…</p>
                </div>

                <div class="selected-tables-box" id="selected-tables-box" style="display:none;">
                    <div class="stb-title">Selected (<span id="selected-count">0</span>)</div>
                    <div class="stb-chips" id="selected-chips"></div>
                </div>

                <div style="margin-top:14px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                    <button type="button" class="btn btn-secondary" style="font-size:12px;padding:5px 12px;"
                            onclick="toggleAllClearTables(true)">Select All</button>
                    <button type="button" class="btn btn-secondary" style="font-size:12px;padding:5px 12px;"
                            onclick="toggleAllClearTables(false)">Select None</button>
                    <button type="button" class="btn btn-secondary" style="font-size:12px;padding:5px 12px;"
                            onclick="selectTablesWhere(function(t){ return t.count > 0; })">Select Tables With Rows</button>
                    <button type="button" class="btn btn-secondary" style="font-size:12px;padding:5px 12px;"
                            onclick="selectTablesWhere(function(t){ return !t.danger; })">Select Safe Tables</button>
                    <button type="submit" name="clear_db" class="btn-clear" style="margin-left:auto;">
                        🗑 Delete Selected Tables
                    </button>
                </div>
            </form>
        </div>

    </div>
</div>

<script>
function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

var allTables     = [];
var selectedNames = new Set();

function loadTables() {
    var list = document.getElementById('clear-table-list');
    fetch('database.php?action=list_tables')
        .then(function(r) { return r.json(); })
        .then(function(tables) {
            if (!tables || tables.length === 0) {
                list.innerHTML = '<p style="padding:8px;font-size:12.5px;color:var(--secondary);">No tables found.</p>';
                return;
            }
            tables.sort(function(a, b) { return b.count - a.count; });
            allTables = tables;
            renderTableList();
        })
        .catch(function() {
            list.innerHTML = '<p style="padding:8px;font-size:12.5px;color:var(--danger);">Failed to load tables.</p>';
        });
}
loadTables();

function renderTableList() {
    var list = document.getElementById('clear-table-list');
    var q    = document.getElementById('clear-table-search').value.trim().toLowerCase();
    var filtered = q ? allTables.filter(function(t) { return t.name.toLowerCase().indexOf(q) !== -1; }) : allTables;

    if (filtered.length === 0) {
        list.innerHTML = '<p style="padding:8px;font-size:12.5px;color:var(--secondary);">No matching tables.</p>';
        return;
    }
    list.innerHTML = filtered.map(function(t) {
        var isSel = selectedNames.has(t.name);
        return '<div class="clear-row' + (t.danger ? ' danger' : '') + (isSel ? ' selected' : '') + '" data-name="' + esc(t.name) + '">' +
            '<span class="crow-chk"></span>' +
            '<span class="crow-name">' + (t.danger ? '⚠️ ' : '') + esc(t.name) + '</span>' +
            '<span class="crow-cnt">' + Number(t.count).toLocaleString() + ' rows</span>' +
            '</div>';
    }).join('');
    renderSelectedChips();
}

document.getElementById('clear-table-list').addEventListener('click', function(e) {
    var row = e.target.closest('.clear-row');
    if (!row) return;
    var name = row.dataset.name;
    if (selectedNames.has(name)) selectedNames.delete(name); else selectedNames.add(name);
    row.classList.toggle('selected');
    renderSelectedChips();
});

function renderSelectedChips() {
    var box   = document.getElementById('selected-tables-box');
    var chips = document.getElementById('selected-chips');
    var count = document.getElementById('selected-count');
    var names = Array.from(selectedNames);

    count.textContent = names.length;
    box.style.display = names.length ? '' : 'none';

    chips.innerHTML = names.map(function(name) {
        var t = allTables.find(function(x) { return x.name === name; });
        var danger = t && t.danger;
        return '<span class="chip' + (danger ? ' danger' : '') + '">' + esc(name) +
            '<button type="button" class="chip-x" data-name="' + esc(name) + '" title="Remove">×</button></span>';
    }).join('');
}

document.getElementById('selected-chips').addEventListener('click', function(e) {
    var btn = e.target.closest('.chip-x');
    if (!btn) return;
    selectedNames.delete(btn.dataset.name);
    renderTableList();
});

function toggleAllClearTables(state) {
    var q = document.getElementById('clear-table-search').value.trim().toLowerCase();
    var filtered = q ? allTables.filter(function(t) { return t.name.toLowerCase().indexOf(q) !== -1; }) : allTables;
    filtered.forEach(function(t) {
        if (state) selectedNames.add(t.name); else selectedNames.delete(t.name);
    });
    renderTableList();
}

function selectTablesWhere(predicate) {
    var q = document.getElementById('clear-table-search').value.trim().toLowerCase();
    var filtered = q ? allTables.filter(function(t) { return t.name.toLowerCase().indexOf(q) !== -1; }) : allTables;
    selectedNames.clear();
    filtered.forEach(function(t) {
        if (predicate(t)) selectedNames.add(t.name);
    });
    renderTableList();
}

function confirmClearTables() {
    var names = Array.from(selectedNames);
    if (names.length === 0) { alert('Select at least one table.'); return false; }

    // Selection lives in JS state, not real form controls — submit via hidden inputs.
    var form = document.getElementById('clear-table-list').closest('form');
    form.querySelectorAll('input.clear-table-hidden').forEach(function(el) { el.remove(); });
    names.forEach(function(name) {
        var inp = document.createElement('input');
        inp.type  = 'hidden';
        inp.className = 'clear-table-hidden';
        inp.name  = 'clear_tables[]';
        inp.value = name;
        form.appendChild(inp);
    });

    var msg = 'PERMANENTLY DELETE all data in: ' + names.join(', ') + '?';
    if (names.indexOf('users') !== -1) msg += '\n\n⚠️ This will log you out immediately!';
    msg += '\n\nThis CANNOT be undone. Type YES to confirm:';
    var answer = prompt(msg);
    return answer !== null && answer.trim().toUpperCase() === 'YES';
}
</script>
</body>
</html>
