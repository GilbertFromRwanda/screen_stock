<?php
require_once 'config.php';
if (!isLoggedIn()) redirect('login.php');
if (!hasPermission('financials')) { $_SESSION['flash_error'] = "You don't have permission to access Profit Analysis."; redirect('dashboard.php'); }

$from       = isset($_GET['from']) && $_GET['from'] ? mysqli_real_escape_string($conn, $_GET['from']) : date('Y-m-01');
$to         = isset($_GET['to'])   && $_GET['to']   ? mysqli_real_escape_string($conn, $_GET['to'])   : date('Y-m-d');
$show_all   = isset($_GET['show_all']);
$cid_and    = cidAnd();

// One row per product with revenue/cost/qty summed across bulk + retail sales in range.
// cost is the COGS snapshot taken at sale time (sales_bulk.cost_total / sales_retail.cost_total).
$q = mysqli_query($conn, "
    SELECT p.id, p.name, p.category,
        COALESCE(SUM(x.revenue), 0) AS revenue,
        COALESCE(SUM(x.cost), 0)    AS cost,
        COALESCE(SUM(x.qty), 0)     AS qty,
        COUNT(*)                   AS sale_count
    FROM products p
    JOIN (
        SELECT product_id, total_amount AS revenue, cost_total AS cost, quantity AS qty
        FROM sales_bulk
        WHERE sale_date BETWEEN '$from' AND '$to' AND refunded = 0 AND has_loan = 0 $cid_and
        UNION ALL
        SELECT product_id, total_amount AS revenue, cost_total AS cost, pieces_sold AS qty
        FROM sales_retail
        WHERE sale_date BETWEEN '$from' AND '$to' AND refunded = 0 AND has_loan = 0 $cid_and
    ) x ON x.product_id = p.id
    GROUP BY p.id, p.name, p.category
    ORDER BY (COALESCE(SUM(x.revenue), 0) - COALESCE(SUM(x.cost), 0)) ASC
");

$rows = [];
$loss_count = 0;
$total_loss = 0.0;
$total_revenue_all = 0.0;
$total_cost_all = 0.0;

while ($r = mysqli_fetch_assoc($q)) {
    $profit = (float)$r['revenue'] - (float)$r['cost'];
    $r['profit'] = $profit;
    $r['margin'] = $r['revenue'] > 0 ? round($profit / $r['revenue'] * 100, 1) : 0;

    $total_revenue_all += (float)$r['revenue'];
    $total_cost_all    += (float)$r['cost'];
    if ($profit < 0) {
        $loss_count++;
        $total_loss += abs($profit);
    }

    if (!$show_all && $profit >= 0) continue;
    $rows[] = $r;
}
$net_all = $total_revenue_all - $total_cost_all;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Products Causing Loss</title>
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
        <h1>Products Causing Loss</h1>
        <p style="color:var(--secondary);font-size:13px;margin-bottom:20px;">
            Products sold below cost (revenue &lt; cost of goods sold) &mdash;
            <strong><?php echo date('M d, Y', strtotime($from)); ?></strong>
            &mdash;
            <strong><?php echo date('M d, Y', strtotime($to)); ?></strong>
        </p>

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
            <label style="display:flex;align-items:center;gap:6px;font-size:13px;color:var(--secondary);margin:0 4px;">
                <input type="checkbox" name="show_all" value="1" <?php echo $show_all ? 'checked' : ''; ?>>
                Show all products (not just losses)
            </label>
            <button type="submit" class="btn btn-primary">Filter</button>
            <button type="button" class="btn btn-secondary no-print" onclick="window.print()">Print</button>
        </form>

        <div class="summary-cards">
            <div class="summary-card red">
                <label>Loss-Making Products</label>
                <div class="val"><?php echo number_format($loss_count); ?></div>
            </div>
            <div class="summary-card red">
                <label>Total Loss</label>
                <div class="val">RWF <?php echo number_format($total_loss, 0); ?></div>
            </div>
            <div class="summary-card">
                <label>Total Revenue (all products)</label>
                <div class="val">RWF <?php echo number_format($total_revenue_all, 0); ?></div>
            </div>
            <div class="summary-card">
                <label>Total Cost (all products)</label>
                <div class="val">RWF <?php echo number_format($total_cost_all, 0); ?></div>
            </div>
            <div class="summary-card <?php echo $net_all >= 0 ? 'green' : 'red'; ?>">
                <label>Net Profit (all products)</label>
                <div class="val <?php echo $net_all < 0 ? 'profit-negative' : 'profit-positive'; ?>">
                    RWF <?php echo number_format($net_all, 0); ?>
                </div>
            </div>
        </div>

        <div class="comparison-table">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Category</th>
                            <th>Qty Sold</th>
                            <th>Sales</th>
                            <th>Revenue</th>
                            <th>Cost</th>
                            <th>Profit / Loss</th>
                            <th>Margin</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($rows)): foreach ($rows as $r): $is_loss = $r['profit'] < 0; ?>
                        <tr class="<?php echo $is_loss ? 'highlight-loss' : 'highlight-profit'; ?>">
                            <td><strong><?php echo htmlspecialchars($r['name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($r['category'] ?: '—'); ?></td>
                            <td><?php echo number_format($r['qty']); ?></td>
                            <td><?php echo number_format($r['sale_count']); ?></td>
                            <td class="revenue-value">RWF <?php echo number_format($r['revenue'], 0); ?></td>
                            <td class="cost-value">RWF <?php echo number_format($r['cost'], 0); ?></td>
                            <td class="<?php echo $is_loss ? 'profit-negative' : 'profit-positive'; ?>">
                                RWF <?php echo number_format($r['profit'], 0); ?>
                            </td>
                            <td>
                                <span class="margin-badge <?php
                                    if ($r['margin'] >= 30) echo 'margin-high';
                                    elseif ($r['margin'] >= 0) echo 'margin-medium';
                                    else echo 'margin-low';
                                ?>"><?php echo number_format($r['margin'], 1); ?>%</span>
                            </td>
                        </tr>
                        <?php endforeach; else: ?>
                        <tr>
                            <td colspan="8" style="text-align:center;padding:30px;color:var(--secondary);">
                                <?php echo $show_all ? 'No sales found for the selected period.' : 'No loss-making products in this period. 🎉'; ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<script src="script.js"></script>
</body>
</html>
