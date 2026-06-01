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

        <!-- Summary cards -->
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

        <!-- Daily breakdown — populated via AJAX -->
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
    </div>
</div>
<style>@keyframes spin{to{transform:rotate(360deg);}}</style>
<script src="script.js"></script>
<script>
(function() {
    var params = new URLSearchParams(window.location.search);
    var from = params.get('from') || '<?= date('Y-m-01'); ?>';
    var to   = params.get('to')   || '<?= date('Y-m-d'); ?>';

    fetch('summary-revenue.php?action=daily&from='+from+'&to='+to)
        .then(function(r){ return r.json(); })
        .then(function(rows) {
            var fmt = function(n) { return n > 0 ? 'RWF '+Math.round(n).toLocaleString() : '-'; };
            var gtBulk=0, gtRet=0, gtExp=0;

            if (rows.length === 0) {
                document.getElementById('daily-tbody').innerHTML =
                    '<tr><td colspan="8" style="padding:32px;text-align:center;color:var(--secondary);">No data for this period.</td></tr>';
                return;
            }

            var html = '';
            rows.slice().reverse().forEach(function(r) {
                var rev = r.bulk + r.retail;
                var net = rev - r.expense;
                gtBulk += r.bulk; gtRet += r.retail; gtExp += r.expense;
                var d = new Date(r.date+'T12:00:00');
                var label = d.toLocaleDateString('en-US',{weekday:'short',month:'short',day:'numeric'});
                html += '<tr>'+
                    '<td>'+label+'</td>'+
                    '<td>'+fmt(r.bulk)+'</td>'+
                    '<td>'+fmt(r.retail)+'</td>'+
                    '<td><strong>'+(rev>0?'RWF '+Math.round(rev).toLocaleString():'-')+'</strong></td>'+
                    '<td>'+fmt(r.expense)+'</td>'+
                    '<td>-</td>'+ // consumption not in simplified AJAX
                    '<td>-</td>'+
                    '<td style="color:'+(net>=0?'var(--success)':'var(--danger)')+';font-weight:600;">RWF '+Math.round(net).toLocaleString()+'</td>'+
                    '</tr>';
            });
            document.getElementById('daily-tbody').innerHTML = html;

            var gtRev = gtBulk + gtRet;
            document.getElementById('daily-tfoot').innerHTML =
                '<tr><td><strong>Total</strong></td>'+
                '<td>RWF '+Math.round(gtBulk).toLocaleString()+'</td>'+
                '<td>RWF '+Math.round(gtRet).toLocaleString()+'</td>'+
                '<td><strong>RWF '+Math.round(gtRev).toLocaleString()+'</strong></td>'+
                '<td>RWF '+Math.round(gtExp).toLocaleString()+'</td>'+
                '<td>-</td><td>-</td>'+
                '<td style="color:var(--success);font-weight:700;">RWF '+Math.round(gtRev-gtExp).toLocaleString()+'</td>'+
                '</tr>';
        })
        .catch(function() {
            document.getElementById('daily-tbody').innerHTML =
                '<tr><td colspan="8" style="color:var(--danger);padding:16px;">Could not load daily breakdown.</td></tr>';
        });
})();
</script>
</body>
</html>
