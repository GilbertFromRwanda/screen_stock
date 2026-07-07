<?php
require_once 'config.php';
if (!isLoggedIn()) redirect('login.php');
if (!hasPermission('financials')) { $_SESSION['flash_error'] = "You don't have permission to access Profit Analysis."; redirect('dashboard.php'); }

// ── AJAX: sales data for the date range ─────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'data') {
    header('Content-Type: application/json');

    $from     = isset($_GET['from']) && $_GET['from'] ? mysqli_real_escape_string($conn, $_GET['from']) : date('Y-m-01');
    $to       = isset($_GET['to'])   && $_GET['to']   ? mysqli_real_escape_string($conn, $_GET['to'])   : date('Y-m-d');
    $show_all = isset($_GET['show_all']) && $_GET['show_all'] === '1';

    // One row per individual sale (bulk or retail) in range, so each loss can be traced
    // back to the exact transaction it came from. cost is the COGS snapshot taken at
    // sale time (sales_bulk.cost_total / sales_retail.cost_total).
    $q = mysqli_query($conn, "
        SELECT 'bulk' AS sale_type, sb.id AS sale_id, sb.purchase_id, sb.sale_date, p.name AS product_name, p.category,
            sb.quantity AS qty, sb.total_amount AS revenue, sb.cost_total AS cost
        FROM sales_bulk sb
        JOIN products p ON p.id = sb.product_id
        WHERE sb.sale_date BETWEEN '$from' AND '$to' AND sb.refunded = 0 AND sb.has_loan = 0 " . cidAndFor('sb') . "

        UNION ALL

        SELECT 'retail' AS sale_type, sr.id AS sale_id, sr.purchase_id, sr.sale_date, p.name AS product_name, p.category,
            sr.pieces_sold AS qty, sr.total_amount AS revenue, sr.cost_total AS cost
        FROM sales_retail sr
        JOIN products p ON p.id = sr.product_id
        WHERE sr.sale_date BETWEEN '$from' AND '$to' AND sr.refunded = 0 AND sr.has_loan = 0 " . cidAndFor('sr') . "

        ORDER BY sale_date DESC, sale_id DESC
    ");

    $rows = [];
    $loss_count = 0;
    $total_loss = 0.0;
    $total_revenue_all = 0.0;
    $total_cost_all = 0.0;

    while ($r = mysqli_fetch_assoc($q)) {
        $profit  = (float)$r['revenue'] - (float)$r['cost'];
        $r['profit'] = $profit;
        $r['margin'] = $r['revenue'] > 0 ? round($profit / $r['revenue'] * 100, 1) : 0;
        $r['sale_id']     = (int)$r['sale_id'];
        $r['purchase_id'] = $r['purchase_id'] !== null ? (int)$r['purchase_id'] : null;
        $r['qty']         = (float)$r['qty'];
        $r['revenue']     = (float)$r['revenue'];
        $r['cost']        = (float)$r['cost'];

        $total_revenue_all += $r['revenue'];
        $total_cost_all    += $r['cost'];
        if ($profit < 0) {
            $loss_count++;
            $total_loss += abs($profit);
        }

        if (!$show_all && $profit >= 0) continue;
        $rows[] = $r;
    }

    echo json_encode([
        'rows' => $rows,
        'summary' => [
            'loss_count'         => $loss_count,
            'total_loss'         => $total_loss,
            'total_revenue_all'  => $total_revenue_all,
            'total_cost_all'     => $total_cost_all,
            'net_all'            => $total_revenue_all - $total_cost_all,
        ],
        'show_all' => $show_all,
    ]);
    exit;
}

$from     = isset($_GET['from']) && $_GET['from'] ? $_GET['from'] : date('Y-m-01');
$to       = isset($_GET['to'])   && $_GET['to']   ? $_GET['to']   : date('Y-m-d');
$show_all = isset($_GET['show_all']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Causing Loss</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/revenue.css">
    <style>
        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 16px;
            margin-bottom: 28px;
        }
        .summary-card {
            background: var(--white);
            border-radius: var(--radius);
            padding: 20px 18px;
            box-shadow: var(--shadow);
            border-left: 4px solid var(--primary);
        }
        .summary-card.red   { border-left-color: var(--danger); }
        .summary-card.green { border-left-color: var(--success); }
        .summary-card label { font-size: 12px; color: var(--secondary); text-transform: uppercase; letter-spacing: .5px; }
        .summary-card .val  { font-size: 22px; font-weight: 700; color: var(--dark); margin-top: 6px; }
        .action-btns {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }
        .action-btns .btn-sm {
            white-space: nowrap;
        }
        @media print {
            .sidebar, .action-bar, .date-filter-bar, .no-print { display: none !important; }
            .main-content { margin: 0 !important; }
        }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php include 'sidebar.php'; ?>
    <div class="main-content">
        <h1>Sales Causing Loss</h1>
        <p style="color:var(--secondary);font-size:13px;margin-bottom:20px;">
            Individual sales made below cost (revenue &lt; cost of goods sold) &mdash;
            <strong id="rangeLabel"></strong>
        </p>

        <!-- Date filter -->
        <form method="GET" class="date-filter-bar" id="filterForm">
            <div class="filter-group">
                <label>From</label>
                <input type="date" name="from" id="fFrom" value="<?php echo htmlspecialchars($from); ?>">
            </div>
            <div class="filter-group">
                <label>To</label>
                <input type="date" name="to" id="fTo" value="<?php echo htmlspecialchars($to); ?>">
            </div>
            <label style="display:flex;align-items:center;gap:6px;font-size:13px;color:var(--secondary);margin:0 4px;">
                <input type="checkbox" name="show_all" id="fShowAll" value="1" <?php echo $show_all ? 'checked' : ''; ?>>
                Show all sales (not just losses)
            </label>
            <button type="submit" class="btn btn-primary">Filter</button>
            <button type="button" class="btn btn-secondary no-print" onclick="window.print()">Print</button>
        </form>

        <div class="summary-cards">
            <div class="summary-card red">
                <label>Loss-Making Sales</label>
                <div class="val" id="sumLossCount">&mdash;</div>
            </div>
            <div class="summary-card red">
                <label>Total Loss</label>
                <div class="val" id="sumTotalLoss">&mdash;</div>
            </div>
            <div class="summary-card">
                <label>Total Revenue (all sales)</label>
                <div class="val" id="sumTotalRevenue">&mdash;</div>
            </div>
            <div class="summary-card">
                <label>Total Cost (all sales)</label>
                <div class="val" id="sumTotalCost">&mdash;</div>
            </div>
            <div class="summary-card" id="sumNetCard">
                <label>Net Profit (all sales)</label>
                <div class="val" id="sumNet">&mdash;</div>
            </div>
        </div>

        <div class="comparison-table">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Sale Date</th>
                            <th>Type</th>
                            <th>Product</th>
                            <th>Category</th>
                            <th>Qty</th>
                            <th>Revenue</th>
                            <th>Cost</th>
                            <th>Profit / Loss</th>
                            <th>Margin</th>
                            <th>Sale</th>
                        </tr>
                    </thead>
                    <tbody id="rowsBody">
                        <tr>
                            <td colspan="10" style="text-align:center;padding:30px;color:var(--secondary);">
                                <i class="fas fa-spinner fa-spin"></i> Loading&hellip;
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<script src="script.js"></script>
<script>
function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
function fmtDate(s) {
    var d = new Date(s + 'T12:00:00');
    return d.toLocaleDateString('en-US', {month:'short', day:'2-digit', year:'numeric'});
}
function fmtMoney(n) {
    return 'RWF ' + Math.round(n).toLocaleString();
}

function loadLossData() {
    var from     = document.getElementById('fFrom').value;
    var to       = document.getElementById('fTo').value;
    var showAll  = document.getElementById('fShowAll').checked;

    document.getElementById('rangeLabel').textContent = fmtDate(from) + ' — ' + fmtDate(to);
    document.getElementById('rowsBody').innerHTML =
        '<tr><td colspan="10" style="text-align:center;padding:30px;color:var(--secondary);"><i class="fas fa-spinner fa-spin"></i> Loading&hellip;</td></tr>';

    var qs = 'action=data&from=' + encodeURIComponent(from) + '&to=' + encodeURIComponent(to) +
        (showAll ? '&show_all=1' : '');

    fetch('loss_products.php?' + qs)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            renderSummary(data.summary);
            renderRows(data.rows, data.show_all);
        })
        .catch(function() {
            document.getElementById('rowsBody').innerHTML =
                '<tr><td colspan="10" style="text-align:center;padding:30px;color:var(--danger);">Failed to load. <a href="javascript:loadLossData()">Retry</a></td></tr>';
        });
}

function renderSummary(s) {
    document.getElementById('sumLossCount').textContent    = s.loss_count.toLocaleString();
    document.getElementById('sumTotalLoss').textContent    = fmtMoney(s.total_loss);
    document.getElementById('sumTotalRevenue').textContent = fmtMoney(s.total_revenue_all);
    document.getElementById('sumTotalCost').textContent    = fmtMoney(s.total_cost_all);

    var netEl  = document.getElementById('sumNet');
    var card   = document.getElementById('sumNetCard');
    netEl.textContent = fmtMoney(s.net_all);
    netEl.className   = 'val ' + (s.net_all < 0 ? 'profit-negative' : 'profit-positive');
    card.className    = 'summary-card ' + (s.net_all >= 0 ? 'green' : 'red');
}

function renderRows(rows, showAll) {
    var body = document.getElementById('rowsBody');
    if (!rows || !rows.length) {
        body.innerHTML = '<tr><td colspan="10" style="text-align:center;padding:30px;color:var(--secondary);">' +
            (showAll ? 'No sales found for the selected period.' : 'No loss-making sales in this period. 🎉') +
            '</td></tr>';
        return;
    }

    var html = rows.map(function(r) {
        var isLoss = r.profit < 0;
        var marginCls = r.margin >= 30 ? 'margin-high' : (r.margin >= 0 ? 'margin-medium' : 'margin-low');
        var viewPurchase = r.purchase_id
            ? '<a href="purchases.php?highlight=' + r.purchase_id + '" target="_blank" class="btn btn-secondary btn-sm">View Purchase</a>'
            : '<button type="button" class="btn btn-secondary btn-sm" disabled title="No purchase record was matched for this sale">View Purchase</button>';

        return '<tr class="' + (isLoss ? 'highlight-loss' : 'highlight-profit') + '">' +
            '<td style="white-space:nowrap;">' + fmtDate(r.sale_date) + '</td>' +
            '<td><span class="badge badge-' + (r.sale_type === 'bulk' ? 'primary' : 'info') + '">' +
                (r.sale_type.charAt(0).toUpperCase() + r.sale_type.slice(1)) + '</span></td>' +
            '<td><strong>' + escHtml(r.product_name) + '</strong></td>' +
            '<td>' + escHtml(r.category || '—') + '</td>' +
            '<td>' + r.qty.toLocaleString() + '</td>' +
            '<td class="revenue-value">' + fmtMoney(r.revenue) + '</td>' +
            '<td class="cost-value">' + fmtMoney(r.cost) + '</td>' +
            '<td class="' + (isLoss ? 'profit-negative' : 'profit-positive') + '">' + fmtMoney(r.profit) + '</td>' +
            '<td><span class="margin-badge ' + marginCls + '">' + r.margin.toFixed(1) + '%</span></td>' +
            '<td><div class="action-btns">' +
                '<a href="sales.php?tab=' + r.sale_type + '&highlight=' + r.sale_id + '" target="_blank" class="btn btn-secondary btn-sm">View Sale</a>' +
                viewPurchase +
            '</div></td>' +
            '</tr>';
    }).join('');

    body.innerHTML = html;
}

document.getElementById('filterForm').addEventListener('submit', function(e) {
    e.preventDefault();
    var params = new URLSearchParams();
    params.set('from', document.getElementById('fFrom').value);
    params.set('to', document.getElementById('fTo').value);
    if (document.getElementById('fShowAll').checked) params.set('show_all', '1');
    history.replaceState(null, '', 'loss_products.php?' + params.toString());
    loadLossData();
});

loadLossData();
</script>
</body>
</html>
