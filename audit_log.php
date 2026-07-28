<?php
require_once 'config.php';

if (!isLoggedIn()) redirect('login.php');
if (!hasPermission('audit_log')) { $_SESSION['flash_error'] = "You don't have permission to access the Audit Log."; redirect('dashboard.php'); }

$is_super = isSuperAdmin();
$cid_and  = cidAnd();

// ── Filters ───────────────────────────────────────────────────────────────────
$filter_action = trim($_GET['action'] ?? '');
$filter_table  = trim($_GET['table']  ?? '');
$filter_user   = (int)($_GET['user_id'] ?? 0);
$date_from     = $_GET['date_from'] ?? date('Y-m-d', strtotime('monday this week'));
$date_to       = $_GET['date_to']   ?? date('Y-m-d');

$where_parts = ["al.created_at BETWEEN '$date_from 00:00:00' AND '$date_to 23:59:59'"];
if (!$is_super) {
    $cid_list = cidList();
    $cid_condition = $cid_list !== null ? "IN (" . implode(',', $cid_list) . ")" : "= " . (int)cid();
    $where_parts[] = "(al.company_id $cid_condition OR al.company_id IS NULL)";
}
if ($filter_action !== '') $where_parts[] = "al.action = '" . mysqli_real_escape_string($conn, $filter_action) . "'";
if ($filter_table  !== '') $where_parts[] = "al.table_name = '" . mysqli_real_escape_string($conn, $filter_table) . "'";
if ($filter_user   >  0)  $where_parts[] = "al.user_id = $filter_user";

$where = 'WHERE ' . implode(' AND ', $where_parts);

// ── Pagination ────────────────────────────────────────────────────────────────
$per_page    = 50;
$page        = max(1, (int)($_GET['page'] ?? 1));
$total       = (int)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM audit_log al $where"))['cnt'];
$total_pages = max(1, (int)ceil($total / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

$rows = mysqli_query($conn, "
    SELECT al.*, u.username, u.full_name
    FROM audit_log al
    LEFT JOIN users u ON u.id = al.user_id
    $where
    ORDER BY al.created_at DESC
    LIMIT $per_page OFFSET $offset
");

// Dropdown options for filter selects
$actions = mysqli_query($conn, "SELECT DISTINCT action FROM audit_log ORDER BY action");
$tables  = mysqli_query($conn, "SELECT DISTINCT table_name FROM audit_log WHERE table_name IS NOT NULL AND table_name <> '' ORDER BY table_name");
$users   = mysqli_query($conn, "SELECT id, username, full_name FROM users ORDER BY full_name");

function fmt_json(string $json): string {
    $data = json_decode($json, true);
    if (!is_array($data)) return htmlspecialchars($json);
    $parts = [];
    foreach ($data as $k => $v) {
        $val = $v === null ? '<em>null</em>' : htmlspecialchars((string)$v);
        $parts[] = '<span class="al-key">' . htmlspecialchars($k) . '</span>: ' . $val;
    }
    return implode('<br>', $parts);
}

function action_badge(string $action): string {
    $lower = strtolower($action);
    if (str_contains($lower, 'delete')) return 'badge-red';
    if (str_contains($lower, 'edit') || str_contains($lower, 'update')) return 'badge-amber';
    return 'badge-blue';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Log - Screen Stock</title>
    <link rel="stylesheet" href="css/style.css?v=<?php echo filemtime(__DIR__ . '/css/style.css'); ?>">
    <style>
        .al-filters { display:flex; flex-wrap:wrap; gap:10px; margin-bottom:18px; align-items:flex-end; }
        .al-filters .fg { display:flex; flex-direction:column; gap:4px; }
        .al-filters label { font-size:11px; font-weight:600; color:var(--secondary); text-transform:uppercase; letter-spacing:.4px; }
        .al-filters input, .al-filters select {
            padding:6px 10px; border:1px solid var(--gray-300); border-radius:6px;
            font-size:13px; background:#fff; min-width:130px;
        }
        .al-filters .btn { align-self:flex-end; }

        .al-table { width:100%; border-collapse:collapse; font-size:13px; }
        .al-table th { background:#f8fafc; font-size:11px; font-weight:700; text-transform:uppercase;
            letter-spacing:.5px; color:var(--secondary); padding:8px 12px;
            border-bottom:2px solid var(--gray-200); white-space:nowrap; }
        .al-table td { padding:8px 12px; border-bottom:1px solid var(--gray-200); vertical-align:top; }
        .al-table tr:hover td { background:#f8fafc; }

        .badge { display:inline-block; padding:2px 8px; border-radius:99px; font-size:11px; font-weight:600; }
        .badge-red   { background:#fee2e2; color:#b91c1c; }
        .badge-amber { background:#fef3c7; color:#b45309; }
        .badge-blue  { background:#e8edf5; color:#0a2148; }

        .al-vals { font-size:11.5px; line-height:1.7; color:#374151; }
        .al-key  { font-weight:600; color:#6b7280; }
        details summary { cursor:pointer; font-size:11.5px; color:#1a4280; user-select:none; }
        details[open] summary { margin-bottom:4px; }

        .al-empty { text-align:center; padding:48px; color:var(--secondary); }
        .al-meta  { font-size:11px; color:#9ca3af; white-space:nowrap; }

        .pager { display:flex; gap:6px; align-items:center; flex-wrap:wrap; margin-top:16px; }
        .pager a, .pager span {
            padding:4px 10px; border-radius:6px; font-size:12px; font-weight:500;
            border:1px solid var(--gray-300); text-decoration:none; color:var(--dark);
        }
        .pager a:hover { background:#f1f5f9; }
        .pager .cur { background:#1a4280; color:#fff; border-color:#1a4280; }
        .pager .disabled { opacity:.4; pointer-events:none; }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php include 'sidebar.php'; ?>

    <div class="main-content">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:10px;">
            <h1 style="margin:0;">&#9741; Audit Log</h1>
            <span style="font-size:13px;color:var(--secondary);"><?= number_format($total) ?> record<?= $total !== 1 ? 's' : '' ?> found</span>
        </div>

        <!-- Filters -->
        <form method="GET" class="al-filters">
            <div class="fg">
                <label>From</label>
                <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
            </div>
            <div class="fg">
                <label>To</label>
                <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
            </div>
            <div class="fg">
                <label>Action</label>
                <select name="action">
                    <option value="">All actions</option>
                    <?php while ($r = mysqli_fetch_assoc($actions)): ?>
                    <option value="<?= htmlspecialchars($r['action']) ?>" <?= $filter_action === $r['action'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($r['action']) ?>
                    </option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="fg">
                <label>Table</label>
                <select name="table">
                    <option value="">All tables</option>
                    <?php while ($r = mysqli_fetch_assoc($tables)): ?>
                    <option value="<?= htmlspecialchars($r['table_name']) ?>" <?= $filter_table === $r['table_name'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($r['table_name']) ?>
                    </option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="fg">
                <label>User</label>
                <select name="user_id">
                    <option value="">All users</option>
                    <?php while ($r = mysqli_fetch_assoc($users)): ?>
                    <option value="<?= $r['id'] ?>" <?= $filter_user === (int)$r['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($r['full_name'] ?: $r['username']) ?>
                    </option>
                    <?php endwhile; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Filter</button>
            <a href="audit_log.php" class="btn btn-secondary">Reset</a>
        </form>

        <!-- Table -->
        <div class="table-responsive">
            <table class="al-table">
                <thead>
                    <tr>
                        <th>Date &amp; Time</th>
                        <th>User</th>
                        <th>Action</th>
                        <th>Table / Record</th>
                        <th>Old Values</th>
                        <th>New Values</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (mysqli_num_rows($rows) === 0): ?>
                    <tr><td colspan="7" class="al-empty">No audit records found for the selected filters.</td></tr>
                <?php else: ?>
                <?php while ($row = mysqli_fetch_assoc($rows)): ?>
                    <tr>
                        <td class="al-meta">
                            <?= date('d M Y', strtotime($row['created_at'])) ?><br>
                            <?= date('H:i:s', strtotime($row['created_at'])) ?>
                        </td>
                        <td style="white-space:nowrap;">
                            <?php if ($row['full_name']): ?>
                                <?= htmlspecialchars($row['full_name']) ?><br>
                                <span class="al-meta"><?= htmlspecialchars($row['username'] ?? '') ?></span>
                            <?php else: ?>
                                <span class="al-meta">User #<?= $row['user_id'] ?></span>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge <?= action_badge($row['action']) ?>"><?= htmlspecialchars($row['action']) ?></span></td>
                        <td class="al-meta">
                            <?php if ($row['table_name']): ?>
                                <strong><?= htmlspecialchars($row['table_name']) ?></strong><br>
                                <?php if ($row['record_id']): ?>ID: <?= $row['record_id'] ?><?php endif; ?>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td>
                            <?php if ($row['old_values']): ?>
                            <details>
                                <summary>Show old</summary>
                                <div class="al-vals"><?= fmt_json($row['old_values']) ?></div>
                            </details>
                            <?php else: ?><span class="al-meta">—</span><?php endif; ?>
                        </td>
                        <td>
                            <?php if ($row['new_values']): ?>
                            <details>
                                <summary>Show new</summary>
                                <div class="al-vals"><?= fmt_json($row['new_values']) ?></div>
                            </details>
                            <?php else: ?><span class="al-meta">—</span><?php endif; ?>
                        </td>
                        <td class="al-meta"><?= htmlspecialchars($row['ip_address'] ?? '—') ?></td>
                    </tr>
                <?php endwhile; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1):
            $q = array_filter(['action'=>$filter_action,'table'=>$filter_table,'user_id'=>$filter_user?:null,'date_from'=>$date_from,'date_to'=>$date_to]);
        ?>
        <div class="pager">
            <?php if ($page > 1): ?>
                <a href="?<?= http_build_query(array_merge($q,['page'=>$page-1])) ?>">&lsaquo; Prev</a>
            <?php else: ?>
                <span class="disabled">&lsaquo; Prev</span>
            <?php endif; ?>

            <?php for ($p = max(1,$page-2); $p <= min($total_pages,$page+2); $p++): ?>
                <?php if ($p === $page): ?>
                    <span class="cur"><?= $p ?></span>
                <?php else: ?>
                    <a href="?<?= http_build_query(array_merge($q,['page'=>$p])) ?>"><?= $p ?></a>
                <?php endif; ?>
            <?php endfor; ?>

            <?php if ($page < $total_pages): ?>
                <a href="?<?= http_build_query(array_merge($q,['page'=>$page+1])) ?>">Next &rsaquo;</a>
            <?php else: ?>
                <span class="disabled">Next &rsaquo;</span>
            <?php endif; ?>

            <span style="font-size:12px;color:var(--secondary);margin-left:8px;">
                Page <?= $page ?> of <?= $total_pages ?>
            </span>
        </div>
        <?php endif; ?>

    </div><!-- /main-content -->
</div>
</body>
</html>
