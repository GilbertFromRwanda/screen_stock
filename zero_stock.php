<?php
require_once 'config.php';
require_once __DIR__ . '/stock_value.php';
if (!isLoggedIn()) redirect('login.php');
if (!hasPermission('stock_adjust')) { $_SESSION['flash_error'] = "You don't have permission to restock items."; redirect('dashboard.php'); }

// ── AJAX: restock a product directly (no purchase flow) ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restock'])) {
    header('Content-Type: application/json');

    $pid             = (int)($_POST['product_id'] ?? 0);
    $wh_add          = max(0, (int)($_POST['wh_add'] ?? 0));
    $wh_ppp          = max(1, (int)($_POST['wh_ppp'] ?? 1));
    $wh_pkg_price    = max(0, (float)($_POST['wh_pkg_price'] ?? 0));
    $wh_retail_price = max(0, (float)($_POST['wh_retail_price'] ?? 0));
    $rt_add          = max(0, (int)($_POST['rt_add'] ?? 0));
    $rt_price        = max(0, (float)($_POST['rt_price'] ?? 0));
    $cost_price      = max(0, (float)($_POST['cost_price'] ?? 0));

    if ($pid < 1 || ($wh_add < 1 && $rt_add < 1)) {
        echo json_encode(['ok' => false, 'message' => 'Enter a quantity to add.']);
        exit;
    }

    $cid_and = cidAnd();
    $cid_sql = cidSql();

    if ($wh_add > 0) {
        $existing = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM stock WHERE product_id = $pid $cid_and"));
        if ($existing) {
            mysqli_query($conn, "
                UPDATE stock
                SET quantity = quantity + $wh_add,
                    pieces_per_package = $wh_ppp,
                    package_price = $wh_pkg_price,
                    retail_price = $wh_retail_price
                WHERE id = {$existing['id']}
            ");
        } else {
            mysqli_query($conn, "
                INSERT INTO stock (company_id, product_id, quantity, pieces_per_package, package_price, retail_price)
                VALUES ($cid_sql, $pid, $wh_add, $wh_ppp, $wh_pkg_price, $wh_retail_price)
            ");
        }
        mysqli_query($conn, "
            INSERT INTO stock_movements (company_id, product_id, pieces_moved, moved_date, notes)
            VALUES ($cid_sql, $pid, 0, CURDATE(), 'Restock: +$wh_add package(s) added from Zero Stock page')
        ");
    }

    if ($rt_add > 0) {
        $existing_rt = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM retail_stock WHERE product_id = $pid $cid_and"));
        if ($existing_rt) {
            mysqli_query($conn, "
                UPDATE retail_stock
                SET pieces_quantity = pieces_quantity + $rt_add,
                    retail_price = $rt_price
                WHERE id = {$existing_rt['id']}
            ");
        } else {
            mysqli_query($conn, "
                INSERT INTO retail_stock (company_id, product_id, pieces_quantity, retail_price)
                VALUES ($cid_sql, $pid, $rt_add, $rt_price)
            ");
        }
        mysqli_query($conn, "
            INSERT INTO stock_movements (company_id, product_id, pieces_moved, moved_date, notes)
            VALUES ($cid_sql, $pid, $rt_add, CURDATE(), 'Restock: +$rt_add piece(s) added from Zero Stock page')
        ");
    }

    // Record a purchases row too, so cost basis (FIFO in recalcStockValue) and
    // purchase history (purchases.php, purchase_advice.php) stay in sync with
    // quantity added here instead of silently drifting out of sync.
    $rt_pkg_equiv  = $rt_add > 0 ? max(1, (int)ceil($rt_add / $wh_ppp)) : 0;
    $total_pkg_qty = $wh_add + $rt_pkg_equiv;
    $pur_pkg_price = $wh_add > 0 ? $wh_pkg_price : $wh_retail_price;
    $pur_rt_price  = $rt_add > 0 ? $rt_price : $wh_retail_price;
    mysqli_query($conn, "
        INSERT INTO purchases (company_id, product_id, supplier_id, quantity, pieces_per_qty,
            cost_price, package_price, retail_price, purchase_date)
        VALUES ($cid_sql, $pid, NULL, $total_pkg_qty, $wh_ppp,
            $cost_price, $pur_pkg_price, $pur_rt_price, CURDATE())
    ");

    recalcStockValue($conn, cid(), $pid);
    touchCacheStore($conn, 'products');

    logActivity($conn, (int)$_SESSION['user_id'], 'STOCK_RESTOCK',
        "Restocked product_id=$pid: +$wh_add package(s), +$rt_add piece(s), cost=$cost_price via Zero Stock page",
        'stock', $pid, [], ['wh_add' => $wh_add, 'rt_add' => $rt_add, 'cost_price' => $cost_price]);

    echo json_encode(['ok' => true]);
    exit;
}

// ── AJAX: list zero-stock products (both warehouse and retail qty are 0) ─────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'list') {
    header('Content-Type: application/json');

    $cid_and_s  = cidAndFor('s');
    $cid_and_rs = cidAndFor('rs');

    $sql = "
    SELECT p.id, p.name, p.category, p.reorder_level,
           COALESCE(s.pieces_per_package, lp.pieces_per_qty, 1) AS ppp,
           COALESCE(s.package_price,      lp.package_price,  0) AS pkg_price,
           COALESCE(s.retail_price,       lp.retail_price,   0) AS stock_retail_price,
           COALESCE(rs.retail_price,      lp.retail_price,   0) AS rt_price,
           lp.purchase_date AS last_purchase_date,
           COALESCE(lp.cost_price, 0)     AS last_cost_price
    FROM products p
    LEFT JOIN stock s        ON s.product_id  = p.id $cid_and_s
    LEFT JOIN retail_stock rs ON rs.product_id = p.id $cid_and_rs
    LEFT JOIN (
        SELECT pu1.product_id, pu1.package_price, pu1.retail_price, pu1.pieces_per_qty, pu1.purchase_date, pu1.cost_price
        FROM purchases pu1
        INNER JOIN (SELECT product_id, MAX(id) AS max_id FROM purchases GROUP BY product_id) mx
            ON mx.product_id = pu1.product_id AND mx.max_id = pu1.id
    ) lp ON lp.product_id = p.id
    WHERE p.deleted = 0
      AND COALESCE(s.quantity, 0) = 0
      AND COALESCE(rs.pieces_quantity, 0) = 0
      AND lp.purchase_date IS NOT NULL
    ORDER BY p.name
    ";
    $res  = mysqli_query($conn, $sql);
    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) {
        $rows[] = [
            'id'                 => (int)$r['id'],
            'name'               => $r['name'],
            'category'           => $r['category'],
            'reorder_level'      => (int)$r['reorder_level'],
            'ppp'                => (int)$r['ppp'],
            'pkg_price'          => (float)$r['pkg_price'],
            'stock_retail_price' => (float)$r['stock_retail_price'],
            'rt_price'           => (float)$r['rt_price'],
            'last_purchase_date' => $r['last_purchase_date'],
            'last_cost_price'    => (float)$r['last_cost_price'],
        ];
    }
    echo json_encode(['ok' => true, 'rows' => $rows]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zero Stock - Small Stock Management</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .zs-badge {
            display:inline-block; padding:2px 10px; border-radius:12px;
            font-weight:700; font-size:12px; background:#fee2e2; color:#b91c1c;
        }
        .zs-sub { font-size:11px; color:var(--secondary); margin-top:2px; }

        .modal-overlay {
            display:none; position:fixed; inset:0;
            background:rgba(0,0,0,.45); z-index:1000;
            align-items:center; justify-content:center;
        }
        .modal-overlay.open { display:flex; }
        .modal-box {
            background:#fff; border-radius:16px; padding:28px 32px;
            width:100%; max-width:560px; box-shadow:0 20px 60px rgba(0,0,0,.2);
            max-height:90vh; overflow-y:auto;
        }
        .modal-box h3 { margin:0 0 4px; font-size:16px; }
        .modal-box .msub { margin:0 0 18px; font-size:12px; color:var(--secondary); }
        .modal-section { border:1px solid var(--gray-200); border-radius:10px; padding:14px 16px; margin-bottom:14px; }
        .modal-section h4 { margin:0 0 10px; font-size:13px; color:var(--dark); }
        .modal-field { margin-bottom:12px; }
        .modal-field:last-child { margin-bottom:0; }
        .modal-field label { display:block; font-size:12px; font-weight:600; margin-bottom:5px; color:var(--secondary); }
        .modal-field input {
            width:100%; padding:8px 12px; border:1px solid var(--gray-200);
            border-radius:8px; font-size:13px; box-sizing:border-box;
        }
        .modal-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        .modal-actions { display:flex; gap:10px; justify-content:flex-end; margin-top:18px; }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php include 'sidebar.php'; ?>
    <div class="main-content">

        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px;">
            <div>
                <h1 style="margin:0;">Zero Stock</h1>
                <p style="margin:4px 0 0;color:var(--secondary);font-size:12px;">
                    Previously purchased products with no warehouse or retail quantity left. Restock directly here — no need to open New Purchase.
                </p>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <input type="text" id="srch" placeholder="Search product…"
                       oninput="applyFilter()"
                       style="padding:7px 12px;border:1px solid var(--gray-200);border-radius:8px;font-size:13px;min-width:220px;">
                <select id="catFilter" onchange="applyFilter()"
                        style="padding:7px 12px;border:1px solid var(--gray-200);border-radius:8px;font-size:13px;background:#fff;">
                    <option value="">All categories</option>
                </select>
            </div>
        </div>

        <?php if (isset($_SESSION['flash_success'])): ?>
        <div class="alert alert-success" style="margin-bottom:16px;">
            <?php echo htmlspecialchars($_SESSION['flash_success']); unset($_SESSION['flash_success']); ?>
        </div>
        <?php endif; ?>

        <div class="table-responsive">
            <table class="table" id="zsTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Product</th>
                        <th>Category</th>
                        <th>Reorder Level</th>
                        <th>Last Purchase</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr id="zsLoadingRow"><td colspan="7" style="text-align:center;color:var(--secondary);padding:24px;">Loading…</td></tr>
                </tbody>
            </table>
        </div>

    </div>
</div>

<!-- Restock modal -->
<div class="modal-overlay" id="modal">
    <div class="modal-box">
        <h3 id="modalTitle">Restock</h3>
        <p class="msub">Add quantity to warehouse and/or retail stock. Leave a section at 0 to skip it.</p>
        <form id="restockForm">
            <input type="hidden" name="restock" value="1">
            <input type="hidden" name="product_id" id="fPid">

            <div class="modal-section">
                <h4>⊞ Warehouse (packages)</h4>
                <div class="modal-grid">
                    <div class="modal-field">
                        <label>Add qty (packages)</label>
                        <input type="number" name="wh_add" id="fWhAdd" min="0" value="0">
                    </div>
                    <div class="modal-field">
                        <label>Pieces per package</label>
                        <input type="number" name="wh_ppp" id="fWhPpp" min="1" value="1">
                    </div>
                    <div class="modal-field">
                        <label>Bulk price / pkg (RWF)</label>
                        <input type="number" name="wh_pkg_price" id="fWhPkgPrice" min="0" step="1">
                    </div>
                    <div class="modal-field">
                        <label>Retail price / pkg-unit (RWF)</label>
                        <input type="number" name="wh_retail_price" id="fWhRetailPrice" min="0" step="1">
                    </div>
                </div>
            </div>

            <div class="modal-section">
                <h4>◫ Retail shop (pieces)</h4>
                <div class="modal-grid">
                    <div class="modal-field">
                        <label>Add qty (pieces)</label>
                        <input type="number" name="rt_add" id="fRtAdd" min="0" value="0">
                    </div>
                    <div class="modal-field">
                        <label>Retail price / piece (RWF)</label>
                        <input type="number" name="rt_price" id="fRtPrice" min="0" step="1">
                    </div>
                </div>
            </div>

            <div class="modal-section">
                <h4>💰 Purchase Cost</h4>
                <div class="modal-field">
                    <label>Cost price / package (RWF)</label>
                    <input type="number" name="cost_price" id="fCostPrice" min="0" step="1">
                    <div class="zs-sub" id="fCostHint"></div>
                </div>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" id="saveBtn">Save</button>
            </div>
        </form>
    </div>
</div>

<script>window.APP_COMPANY_ID = <?php echo json_encode(cid()); ?>;</script>
<script src="js/data-cache.js"></script>
<script src="script.js"></script>
<script>
var ZS_ROWS = [];

function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function loadZeroStock() {
    var tbody = document.querySelector('#zsTable tbody');
    tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:var(--secondary);padding:24px;">Loading…</td></tr>';

    fetch('zero_stock.php?action=list')
        .then(function(r) { return r.json(); })
        .then(function(j) {
            ZS_ROWS = (j && j.ok) ? j.rows : [];
            populateCategoryFilter();
            renderZeroStock();
        })
        .catch(function() {
            tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:var(--secondary);padding:24px;">Could not load data. Please refresh.</td></tr>';
        });
}

function populateCategoryFilter() {
    var sel = document.getElementById('catFilter');
    var current = sel.value;
    var cats = Array.from(new Set(ZS_ROWS.map(function(r) { return r.category; }).filter(Boolean))).sort();

    sel.innerHTML = '<option value="">All categories</option>' + cats.map(function(c) {
        return '<option value="' + esc(c) + '">' + esc(c) + '</option>';
    }).join('');
    sel.value = cats.includes(current) ? current : '';
}

function renderZeroStock() {
    var tbody = document.querySelector('#zsTable tbody');
    var q   = document.getElementById('srch').value.toLowerCase();
    var cat = document.getElementById('catFilter').value;
    var filtered = ZS_ROWS.filter(function(r) {
        return r.name.toLowerCase().includes(q) && (!cat || r.category === cat);
    });

    if (filtered.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:var(--secondary);padding:24px;">'
            + (ZS_ROWS.length === 0 ? 'No products are out of stock. Everything has quantity.' : 'No matches.')
            + '</td></tr>';
        return;
    }

    var html = '';
    filtered.forEach(function(r, i) {
        var lastPurchase = r.last_purchase_date
            ? new Date(r.last_purchase_date).toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' })
                + '<div class="zs-sub">RWF ' + Math.round(r.pkg_price).toLocaleString() + ' / pkg</div>'
            : '<span style="color:var(--secondary);">Never purchased</span>';

        html += '<tr data-name="' + esc(r.name.toLowerCase()) + '">'
            + '<td style="color:var(--secondary);font-size:11px;">' + (i + 1) + '</td>'
            + '<td style="font-weight:600;">' + esc(r.name) + '</td>'
            + '<td>' + esc(r.category || '—') + '</td>'
            + '<td>' + r.reorder_level + '</td>'
            + '<td>' + lastPurchase + '</td>'
            + '<td><span class="zs-badge">Out of Stock</span></td>'
            + '<td><button class="btn btn-primary btn-sm" onclick="openRestock(' + r.id + ')">+ Restock</button></td>'
            + '</tr>';
    });
    tbody.innerHTML = html;
}

function openRestock(pid) {
    var r = ZS_ROWS.find(function(row) { return row.id === pid; });
    if (!r) return;
    document.getElementById('modalTitle').textContent = 'Restock — ' + r.name;
    document.getElementById('fPid').value = r.id;
    document.getElementById('fWhAdd').value = 0;
    document.getElementById('fWhPpp').value = r.ppp || 1;
    document.getElementById('fWhPkgPrice').value = r.pkg_price || 0;
    document.getElementById('fWhRetailPrice').value = r.stock_retail_price || 0;
    document.getElementById('fRtAdd').value = 0;
    document.getElementById('fRtPrice').value = r.rt_price || 0;
    document.getElementById('fCostPrice').value = r.last_cost_price || 0;
    document.getElementById('fCostHint').textContent = r.last_cost_price
        ? 'Auto-filled from last purchase cost. Adjust if the price changed.'
        : 'No previous cost on record — enter this purchase\'s cost per package.';
    document.getElementById('modal').classList.add('open');
    document.getElementById('fWhAdd').focus();
}

function closeModal() {
    document.getElementById('modal').classList.remove('open');
}

document.getElementById('modal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

document.getElementById('restockForm').addEventListener('submit', function(e) {
    e.preventDefault();
    var whAdd = parseInt(document.getElementById('fWhAdd').value || '0', 10);
    var rtAdd = parseInt(document.getElementById('fRtAdd').value || '0', 10);
    if (whAdd < 1 && rtAdd < 1) {
        alert('Enter a quantity to add to warehouse and/or retail stock.');
        return;
    }

    var btn = document.getElementById('saveBtn');
    btn.disabled = true;
    btn.textContent = 'Saving…';

    var data = new FormData(this);
    fetch('zero_stock.php', { method: 'POST', body: data })
        .then(function(r) { return r.json(); })
        .then(function(j) {
            if (j.ok) {
                closeModal();
                DataCache.invalidate('products').then(loadZeroStock);
            } else {
                alert(j.message || 'Could not save.');
            }
        })
        .catch(function() {
            alert('Could not save. Please try again.');
        })
        .finally(function() {
            btn.disabled = false;
            btn.textContent = 'Save';
        });
});

function applyFilter() {
    renderZeroStock();
}

loadZeroStock();
</script>
</body>
</html>
