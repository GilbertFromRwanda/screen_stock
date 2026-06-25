<?php
require_once 'config.php';
if (!isLoggedIn()) redirect('login.php');

// ── AJAX: daily breakdown rows ────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'daily') {
    header('Content-Type: application/json');
    $from = isset($_GET['from']) ? mysqli_real_escape_string($conn, $_GET['from']) : date('Y-m-01');
    $to   = isset($_GET['to'])   ? mysqli_real_escape_string($conn, $_GET['to'])   : date('Y-m-d');
    $ca   = cidAnd();

    $daily_bulk   = [];
    $bulk_q = mysqli_query($conn, "SELECT sale_date, SUM(total_amount) v FROM sales_bulk WHERE sale_date BETWEEN '$from' AND '$to' AND refunded=0 AND has_loan=0 $ca GROUP BY sale_date");
    while ($r = mysqli_fetch_assoc($bulk_q)) $daily_bulk[$r['sale_date']] = (float)$r['v'];

    $daily_retail = [];
    $ret_q = mysqli_query($conn, "SELECT sale_date, SUM(total_amount) v FROM sales_retail WHERE sale_date BETWEEN '$from' AND '$to' AND refunded=0 AND has_loan=0 $ca GROUP BY sale_date");
    while ($r = mysqli_fetch_assoc($ret_q)) $daily_retail[$r['sale_date']] = (float)$r['v'];

    $daily_exp = [];
    $exp_q = mysqli_query($conn, "SELECT expense_date d, SUM(amount) v FROM expenses WHERE expense_date BETWEEN '$from' AND '$to' $ca GROUP BY expense_date");
    if ($exp_q) while ($r = mysqli_fetch_assoc($exp_q)) $daily_exp[$r['d']] = (float)$r['v'];

    // Build sorted date list
    $all_dates = array_unique(array_merge(array_keys($daily_bulk), array_keys($daily_retail), array_keys($daily_exp)));
    sort($all_dates);

    $rows = [];
    foreach ($all_dates as $date) {
        $bulk = $daily_bulk[$date] ?? 0;
        $retail = $daily_retail[$date] ?? 0;
        $exp = $daily_exp[$date] ?? 0;
        $rows[] = ['date' => $date, 'bulk' => $bulk, 'retail' => $retail, 'expense' => $exp, 'total' => $bulk + $retail - $exp];
    }
    echo json_encode($rows);
    exit;
}

// Default: current month
$from = isset($_GET['from']) && $_GET['from'] ? mysqli_real_escape_string($conn, $_GET['from']) : date('Y-m-01');
$to   = isset($_GET['to'])   && $_GET['to']   ? mysqli_real_escape_string($conn, $_GET['to'])   : date('Y-m-d');

$cid_and = cidAnd();

// ── Bulk sales total ───────────────────────────────────────────────────────────
$bulk = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT
        COALESCE(SUM(sb.total_amount), 0) AS revenue,
        COALESCE(SUM(
            (SELECT pu.cost_price FROM purchases pu
             WHERE pu.product_id = sb.product_id $cid_and
               AND pu.purchase_date <= sb.sale_date
             ORDER BY pu.purchase_date DESC LIMIT 1) * sb.quantity / COALESCE(NULLIF(sb.level_divisor, 0), 1)
        ), 0) AS cost
    FROM sales_bulk sb
    WHERE sb.sale_date BETWEEN '$from' AND '$to' AND sb.refunded = 0 AND sb.has_loan = 0 $cid_and
"));

// ── Retail sales total ─────────────────────────────────────────────────────────
$retail = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT
        COALESCE(SUM(sr.total_amount), 0) AS revenue,
        COALESCE(SUM(
            (SELECT pu.cost_price / NULLIF(s.pieces_per_package, 0)
             FROM purchases pu JOIN stock s ON s.product_id = pu.product_id AND s.company_id = pu.company_id
             WHERE pu.product_id = sr.product_id " . cidAndFor('pu') . "
               AND pu.purchase_date <= sr.sale_date
             ORDER BY pu.purchase_date DESC LIMIT 1) * sr.pieces_sold
        ), 0) AS cost
    FROM sales_retail sr
    WHERE sr.sale_date BETWEEN '$from' AND '$to' AND sr.refunded = 0 AND sr.has_loan = 0 $cid_and
"));

// ── Expenses total ─────────────────────────────────────────────────────────────
$exp_check = mysqli_query($conn, "SHOW TABLES LIKE 'expenses'");
$total_expenses = 0;
if (mysqli_num_rows($exp_check) > 0) {
    $exp = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT COALESCE(SUM(amount), 0) AS total FROM expenses
        WHERE expense_date BETWEEN '$from' AND '$to' $cid_and
    "));
    $total_expenses = $exp['total'];
}

// ── Consumption total ──────────────────────────────────────────────────────────
$con_check = mysqli_query($conn, "SHOW TABLES LIKE 'consumption'");
$total_consumption = 0; $total_consumption_unpaid = 0;
if (mysqli_num_rows($con_check) > 0) {
    $con = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT COALESCE(SUM(amount), 0) AS total, COALESCE(SUM(amount - paid_amount), 0) AS unpaid
        FROM consumption WHERE consumption_date BETWEEN '$from' AND '$to' $cid_and
    "));
    $total_consumption        = $con['total'];
    $total_consumption_unpaid = $con['unpaid'];
}

// ── External commission total ──────────────────────────────────────────────────
$ext_commission = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COALESCE(SUM(my_revenue), 0) AS commission
    FROM sales_external WHERE sale_date BETWEEN '$from' AND '$to' AND refunded = 0 $cid_and
"))['commission'] ?? 0;

// ── Derived totals ─────────────────────────────────────────────────────────────
$total_revenue      = $bulk['revenue']  + $retail['revenue'] + $ext_commission;
$total_cost         = $bulk['cost']     + $retail['cost'];
$gross_profit       = $total_revenue    - $total_cost;
$net_profit         = $gross_profit     - $total_expenses - $total_consumption_unpaid;
$profit_margin      = $total_revenue > 0 ? round(($gross_profit / $total_revenue) * 100, 1) : 0;
$net_margin         = $total_revenue > 0 ? round(($net_profit   / $total_revenue) * 100, 1) : 0;

// Daily breakdown is loaded via AJAX (?action=daily) to avoid blocking render
$all_dates = [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Revenue Summary</title>
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
        .summary-card.green  { border-left-color: var(--success); }
        .summary-card.red    { border-left-color: var(--danger); }
        .summary-card.orange { border-left-color: var(--warning); }
        .summary-card.purple { border-left-color: #7c3aed; }
        .summary-card label  { font-size: 12px; color: var(--secondary); text-transform: uppercase; letter-spacing: .5px; }
        .summary-card .val   { font-size: 22px; font-weight: 700; color: var(--dark); margin-top: 6px; }
        .summary-card .sub   { font-size: 11px; color: var(--secondary); margin-top: 4px; }
        .val.neg             { color: var(--danger); }
        .tbl-day td, .tbl-day th { font-size: 13px; }
        .tbl-day tfoot td    { font-weight: 700; background: var(--light); }
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
        <h1>Revenue Summary</h1>

        <!-- Date filter -->
        <form method="GET" class="date-filter-bar">
            <div class="filter-group">
                <label>From</label>
                <input type="date" name="from" value="<?php echo htmlspecialchars($from); ?>">
            </div>
            <div class="filter-group">
                <label>To</label>
                <input type="date" name="to" value="<?php echo htmlspecialchars($to); ?>">
            </div>
            <button type="submit" class="btn btn-primary">Filter</button>
            <button type="button" class="btn btn-secondary no-print" onclick="window.print()">Print</button>
        </form>

        <p style="color:var(--secondary);font-size:13px;margin-bottom:20px;">
            Period: <strong><?php echo date('M d, Y', strtotime($from)); ?></strong>
            &mdash;
            <strong><?php echo date('M d, Y', strtotime($to)); ?></strong>
        </p>

        <!-- Tabbed content -->
        <div class="rev-tabs-wrap">
            <div class="rev-tabs-bar">
                <button class="rev-tab active" onclick="switchRevTab('overview', this)">Overview</button>
                <button class="rev-tab" onclick="switchRevTab('daily', this)">Daily Breakdown</button>
            </div>

            <!-- Tab 1: Overview -->
            <div class="rev-tab-panel active" id="revTab-overview">
                <p class="rev-tab-title">Summary</p>
                <div class="summary-cards">
                    <div class="summary-card">
                        <label>Bulk Sales</label>
                        <div class="val">RWF <?php echo number_format($bulk['revenue'], 0); ?></div>
                    </div>
                    <div class="summary-card">
                        <label>Retail Sales</label>
                        <div class="val">RWF <?php echo number_format($retail['revenue'], 0); ?></div>
                    </div>
                    <div class="summary-card green">
                        <label>Total Revenue</label>
                        <div class="val">RWF <?php echo number_format($total_revenue, 0); ?></div>
                    </div>
                    <div class="summary-card red">
                        <label>Total Cost</label>
                        <div class="val">RWF <?php echo number_format($total_cost, 0); ?></div>
                    </div>
                    <div class="summary-card green">
                        <label>Gross Profit</label>
                        <div class="val <?php echo $gross_profit < 0 ? 'neg' : ''; ?>">
                            RWF <?php echo number_format($gross_profit, 0); ?>
                        </div>
                        <div class="sub">Margin <?php echo $profit_margin; ?>%</div>
                    </div>
                    <div class="summary-card orange">
                        <label>Expenses</label>
                        <div class="val">RWF <?php echo number_format($total_expenses, 0); ?></div>
                    </div>
                    <div class="summary-card orange">
                        <label>Consumption</label>
                        <div class="val">RWF <?php echo number_format($total_consumption, 0); ?></div>
                        <div class="sub" style="color:var(--danger);font-weight:600;">
                            Unpaid: RWF <?php echo number_format($total_consumption_unpaid, 0); ?>
                        </div>
                        <div class="sub">Paid: RWF <?php echo number_format($total_consumption - $total_consumption_unpaid, 0); ?></div>
                    </div>
                    <div class="summary-card purple">
                        <label>Net Profit</label>
                        <div class="val <?php echo $net_profit < 0 ? 'neg' : ''; ?>">
                            RWF <?php echo number_format($net_profit, 0); ?>
                        </div>
                        <div class="sub">Margin <?php echo $net_margin; ?>%</div>
                    </div>
                </div>
            </div>

            <!-- Tab 2: Daily Breakdown -->
            <div class="rev-tab-panel" id="revTab-daily">
                <p class="rev-tab-title">Daily Breakdown</p>
                <div class="table-responsive">
                    <table class="table tbl-day">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Bulk Sales</th>
                                <th>Retail Sales</th>
                                <th>Total Revenue</th>
                                <th>Expenses</th>
                                <th>Consumption</th>
                                <th>Con. Unpaid</th>
                                <th>Net</th>
                            </tr>
                        </thead>
                        <tbody id="daily-tbody">
                            <tr><td colspan="8" style="padding:24px;text-align:center;">
                                <div style="display:inline-block;width:24px;height:24px;border:3px solid #e5e7eb;border-top-color:var(--primary);border-radius:50%;animation:spin .7s linear infinite;"></div>
                            </td></tr>
                        </tbody>
                        <tfoot id="daily-tfoot"></tfoot>
                    </table>
                </div>
                <div id="dailyPagBar" class="rev-pag-bar" style="display:none;">
                    <button id="dailyPrevBtn" class="rev-pag-btn" onclick="changeDailyPage(-1)">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                        Prev
                    </button>
                    <span id="dailyPageInfo" class="rev-pag-info"></span>
                    <button id="dailyNextBtn" class="rev-pag-btn" onclick="changeDailyPage(1)">
                        Next
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                    </button>
                    <div class="rev-pag-size-wrap">
                        <span class="rev-pag-size-label">Per page:</span>
                        <select id="dailyPageSizeSel" class="rev-pag-size-sel" onchange="changeDailyPageSize()">
                            <option value="15">15</option>
                            <option value="31">31</option>
                            <option value="9999">All</option>
                        </select>
                    </div>
                </div>
            </div>

        </div><!-- /.rev-tabs-wrap -->
    </div>
</div>
<script src="script.js"></script>
<script>
// ── Tab switching ─────────────────────────────────────────────────────────────
function switchRevTab(name, btn) {
    document.querySelectorAll('.rev-tab').forEach(function(b) { b.classList.remove('active'); });
    document.querySelectorAll('.rev-tab-panel').forEach(function(p) { p.classList.remove('active'); });
    btn.classList.add('active');
    document.getElementById('revTab-' + name).classList.add('active');
}

// ── Daily breakdown — AJAX + pagination ───────────────────────────────────────
var _dailyRows = [], _dailyPage = 1, _dailyPageSize = 15;

function _fmt(n) { return n > 0 ? 'RWF ' + Math.round(n).toLocaleString() : '-'; }

function renderDailyPage() {
    var total      = _dailyRows.length;
    var totalPages = Math.max(1, Math.ceil(total / _dailyPageSize));
    if (_dailyPage > totalPages) _dailyPage = totalPages;

    var start = (_dailyPage - 1) * _dailyPageSize;
    var end   = Math.min(start + _dailyPageSize, total);

    var html = '';
    for (var i = start; i < end; i++) {
        var r   = _dailyRows[i];
        var rev = r.bulk + r.retail;
        var net = rev - r.expense;
        var d   = new Date(r.date + 'T12:00:00');
        var lbl = d.toLocaleDateString('en-US', {weekday:'short', month:'short', day:'numeric'});
        html += '<tr>' +
            '<td>' + lbl + '</td>' +
            '<td>' + _fmt(r.bulk) + '</td>' +
            '<td>' + _fmt(r.retail) + '</td>' +
            '<td><strong>' + (rev > 0 ? 'RWF ' + Math.round(rev).toLocaleString() : '-') + '</strong></td>' +
            '<td>' + _fmt(r.expense) + '</td>' +
            '<td>-</td><td>-</td>' +
            '<td style="color:' + (net >= 0 ? 'var(--success)' : 'var(--danger)') + ';font-weight:600;">RWF ' + Math.round(net).toLocaleString() + '</td>' +
            '</tr>';
    }
    document.getElementById('daily-tbody').innerHTML = html || '<tr><td colspan="8" style="padding:24px;text-align:center;color:var(--secondary);">No data for this period.</td></tr>';

    var bar = document.getElementById('dailyPagBar');
    bar.style.display = total === 0 ? 'none' : 'flex';
    document.getElementById('dailyPrevBtn').disabled = _dailyPage <= 1;
    document.getElementById('dailyNextBtn').disabled = _dailyPage >= totalPages;
    document.getElementById('dailyPageInfo').innerHTML =
        'Page <strong>' + _dailyPage + '</strong> of <strong>' + totalPages +
        '</strong> &nbsp;&middot;&nbsp; ' + total + ' day' + (total !== 1 ? 's' : '');
}

function changeDailyPage(dir) { _dailyPage += dir; renderDailyPage(); }
function changeDailyPageSize() {
    _dailyPageSize = parseInt(document.getElementById('dailyPageSizeSel').value);
    _dailyPage = 1;
    renderDailyPage();
}

(function() {
    var params = new URLSearchParams(window.location.search);
    var from = params.get('from') || '<?= date('Y-m-01'); ?>';
    var to   = params.get('to')   || '<?= date('Y-m-d'); ?>';

    fetch('summary-revenue.php?action=daily&from=' + from + '&to=' + to)
        .then(function(r) { return r.json(); })
        .then(function(rows) {
            _dailyRows = rows.slice().reverse();

            var gtBulk = 0, gtRet = 0, gtExp = 0;
            _dailyRows.forEach(function(r) { gtBulk += r.bulk; gtRet += r.retail; gtExp += r.expense; });
            var gtRev = gtBulk + gtRet;
            document.getElementById('daily-tfoot').innerHTML =
                '<tr><td><strong>Total</strong></td>' +
                '<td>RWF ' + Math.round(gtBulk).toLocaleString() + '</td>' +
                '<td>RWF ' + Math.round(gtRet).toLocaleString() + '</td>' +
                '<td><strong>RWF ' + Math.round(gtRev).toLocaleString() + '</strong></td>' +
                '<td>RWF ' + Math.round(gtExp).toLocaleString() + '</td>' +
                '<td>-</td><td>-</td>' +
                '<td style="color:var(--success);font-weight:700;">RWF ' + Math.round(gtRev - gtExp).toLocaleString() + '</td>' +
                '</tr>';

            renderDailyPage();
        })
        .catch(function() {
            document.getElementById('daily-tbody').innerHTML =
                '<tr><td colspan="8" style="color:var(--danger);padding:16px;">Could not load daily breakdown.</td></tr>';
        });
})();
</script>
</body>
</html>
