<?php
require_once 'config.php';

if (!isLoggedIn()) redirect('login.php');
if (!hasPermission('financials')) { $_SESSION['flash_error'] = "You don't have permission to access Profit Analysis."; redirect('dashboard.php'); }

// ── AJAX: product profitability + all-time totals ────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'profitability') {
    header('Content-Type: application/json');
    $cid_and = cidAnd();

    $products = [];
    $q = mysqli_query($conn, "
        SELECT p.id, p.name, p.category,
            COUNT(DISTINCT sb.id) bulk_cnt,
            COALESCE(SUM(sb.quantity),0) bulk_qty,
            COALESCE(SUM(sb.total_amount),0) bulk_rev,
            COUNT(DISTINCT sr.id) retail_cnt,
            COALESCE(SUM(sr.pieces_sold),0) retail_qty,
            COALESCE(SUM(sr.total_amount),0) retail_rev,
            COALESCE(SUM(sb.total_amount),0)+COALESCE(SUM(sr.total_amount),0) total_rev
        FROM products p
        LEFT JOIN sales_bulk sb ON p.id=sb.product_id AND sb.refunded=0 AND sb.has_loan=0 " . cidAndFor('sb') . "
        LEFT JOIN sales_retail sr ON p.id=sr.product_id AND sr.refunded=0 AND sr.has_loan=0 " . cidAndFor('sr') . "
        GROUP BY p.id HAVING total_rev > 0 ORDER BY total_rev DESC
    ");
    while ($r = mysqli_fetch_assoc($q)) $products[] = $r;

    $ati = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT
            COALESCE((SELECT SUM(total_amount) FROM sales_bulk   WHERE 1=1 " . cidAndFor('sales_bulk')   . "),0)+
            COALESCE((SELECT SUM(total_amount) FROM sales_retail WHERE 1=1 " . cidAndFor('sales_retail') . "),0) total_rev,
            COALESCE((SELECT SUM(cost_total) FROM sales_bulk   WHERE 1=1 " . cidAndFor('sales_bulk')   . "),0) bulk_cost,
            COALESCE((SELECT SUM(cost_total) FROM sales_retail WHERE 1=1 " . cidAndFor('sales_retail') . "),0) retail_cost
    "));
    $total_cost   = ($ati['bulk_cost'] ?? 0) + ($ati['retail_cost'] ?? 0);
    $total_profit = ($ati['total_rev'] ?? 0) - $total_cost;

    echo json_encode(['products' => $products, 'total_rev' => (float)($ati['total_rev'] ?? 0),
        'total_cost' => $total_cost, 'total_profit' => $total_profit]);
    exit;
}

// First, check if the weekly_revenue table has the profit columns
$check_columns = mysqli_query($conn, "SHOW COLUMNS FROM weekly_revenue LIKE 'total_cost'");
if (mysqli_num_rows($check_columns) == 0) {
    // Add missing columns
    mysqli_query($conn, "ALTER TABLE weekly_revenue 
        ADD COLUMN total_cost DECIMAL(10,2) DEFAULT 0 AFTER total_revenue,
        ADD COLUMN total_profit DECIMAL(10,2) DEFAULT 0 AFTER total_cost,
        ADD COLUMN profit_margin DECIMAL(5,2) DEFAULT 0 AFTER total_profit");
}

// Calculate and store weekly revenue with profit analysis
function updateWeeklyRevenue(mysqli $conn): void {
    $week_start = date('Y-m-d', strtotime('monday this week'));
    $week_end   = date('Y-m-d', strtotime('sunday this week'));
    $cid_and    = cidAnd();

    $bulk_row = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT COALESCE(SUM(total_amount),0) revenue, COALESCE(SUM(cost_total),0) cost
        FROM sales_bulk sb
        WHERE sb.sale_date BETWEEN '$week_start' AND '$week_end'
          AND sb.refunded = 0 AND sb.has_loan = 0 $cid_and
    "));
    $bulk_total      = (float)$bulk_row['revenue'];
    $bulk_cost_total = (float)$bulk_row['cost'];
    $bulk_profit     = $bulk_total - $bulk_cost_total;

    $retail_row = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT COALESCE(SUM(total_amount),0) revenue, COALESCE(SUM(cost_total),0) cost
        FROM sales_retail sr
        WHERE sr.sale_date BETWEEN '$week_start' AND '$week_end'
          AND sr.refunded = 0 AND sr.has_loan = 0 " . cidAndFor('sr') . "
    "));
    $retail_total      = (float)$retail_row['revenue'];
    $retail_cost_total = (float)$retail_row['cost'];
    $retail_profit     = $retail_total - $retail_cost_total;

    // External commission — pure profit, no cost
    $ext_q = mysqli_query($conn, "SELECT COALESCE(SUM(my_revenue),0) AS commission FROM sales_external WHERE sale_date BETWEEN '$week_start' AND '$week_end' AND refunded = 0 $cid_and");
    $ext_commission = (float)(mysqli_fetch_assoc($ext_q)['commission'] ?? 0);

    $total_revenue = $bulk_total + $retail_total + $ext_commission;
    $total_cost = $bulk_cost_total + $retail_cost_total;
    $total_profit = $bulk_profit + $retail_profit + $ext_commission;
    $profit_margin = $total_revenue > 0 ? ($total_profit / $total_revenue) * 100 : 0;
    
    $cid_sql = cidSql();
    $check = mysqli_query($conn, "SELECT id FROM weekly_revenue WHERE week_start_date = '$week_start' $cid_and");

    if (mysqli_num_rows($check) > 0) {
        mysqli_query($conn, "
            UPDATE weekly_revenue
            SET bulk_sales_total = $bulk_total, retail_sales_total = $retail_total,
                total_revenue = $total_revenue, total_cost = $total_cost,
                total_profit = $total_profit, profit_margin = $profit_margin
            WHERE week_start_date = '$week_start' $cid_and
        ");
    } else {
        mysqli_query($conn, "
            INSERT INTO weekly_revenue (company_id, week_start_date, week_end_date,
                bulk_sales_total, retail_sales_total, total_revenue, total_cost, total_profit, profit_margin)
            VALUES ($cid_sql, '$week_start', '$week_end',
                $bulk_total, $retail_total, $total_revenue, $total_cost, $total_profit, $profit_margin)
        ");
    }
}

// Update revenue data
updateWeeklyRevenue($conn);

$cid_and = cidAnd();

$weekly_data = mysqli_query($conn, "
    SELECT * FROM weekly_revenue WHERE 1=1 $cid_and
    ORDER BY week_start_date DESC LIMIT 8
");

$current_week_query = mysqli_query($conn, "
    SELECT * FROM weekly_revenue
    WHERE week_start_date = '" . date('Y-m-d', strtotime('monday this week')) . "' $cid_and
");
$current_week = mysqli_fetch_assoc($current_week_query);

// If no current week data, initialize with zeros
if (!$current_week) {
    $current_week = [
        'total_revenue' => 0,
        'bulk_sales_total' => 0,
        'retail_sales_total' => 0,
        'total_cost' => 0,
        'total_profit' => 0,
        'profit_margin' => 0
    ];
}

// Date filter for sales profit table
$filter_from = isset($_GET['from']) ? mysqli_real_escape_string($conn, $_GET['from']) : date('Y-m-d', strtotime('monday this week'));
$filter_to = isset($_GET['to']) ? mysqli_real_escape_string($conn, $_GET['to']) : date('Y-m-d', strtotime('sunday this week'));

// Get detailed sales with profit analysis for filtered date range
// purchase_price/total_cost/profit/margin all read from cost_total, the COGS
// snapshot taken at sale time (see bulkSaleCost()/retailSaleCost() in functions.php).
$current_week_sales = mysqli_query($conn, "
    -- Bulk sales with profit
    SELECT
        'Bulk' as sale_type,
        sb.sale_date,
        p.name as product_name,
        sb.quantity as quantity,
        sb.package_price as selling_price,
        sb.total_amount,
        CASE WHEN sb.quantity > 0 THEN sb.cost_total / sb.quantity ELSE 0 END as purchase_price,
        sb.cost_total as total_cost,
        (sb.total_amount - sb.cost_total) as profit,
        ROUND(
            CASE
                WHEN sb.total_amount > 0
                THEN ((sb.total_amount - sb.cost_total) / sb.total_amount * 100)
                ELSE 0
            END, 2
        ) as margin,
        sb.customer_name
    FROM sales_bulk sb
    JOIN products p ON sb.product_id = p.id
    WHERE sb.sale_date BETWEEN '$filter_from' AND '$filter_to'
      AND sb.refunded=0 AND sb.has_loan=0 " . cidAndFor('sb') . "

    UNION ALL

    -- Retail sales with profit
    SELECT
        'Retail' as sale_type,
        sr.sale_date,
        p.name as product_name,
        sr.pieces_sold as quantity,
        sr.retail_price as selling_price,
        sr.total_amount,
        CASE WHEN sr.pieces_sold > 0 THEN sr.cost_total / sr.pieces_sold ELSE 0 END as purchase_price,
        sr.cost_total as total_cost,
        (sr.total_amount - sr.cost_total) as profit,
        ROUND(
            CASE
                WHEN sr.total_amount > 0
                THEN ((sr.total_amount - sr.cost_total) / sr.total_amount * 100)
                ELSE 0
            END, 2
        ) as margin,
        sr.customer_name
    FROM sales_retail sr
    JOIN products p ON sr.product_id = p.id
    WHERE sr.sale_date BETWEEN '$filter_from' AND '$filter_to'
      AND sr.refunded=0 AND sr.has_loan=0 " . cidAndFor('sr') . "
    ORDER BY sale_date DESC
");

// Calculate filtered totals
$filtered_revenue = 0;
$filtered_cost = 0;
$filtered_profit = 0;
$filtered_sales_data = [];
if ($current_week_sales && mysqli_num_rows($current_week_sales) > 0) {
    while ($row = mysqli_fetch_assoc($current_week_sales)) {
        $filtered_revenue += $row['total_amount'] ?? 0;
        $filtered_cost += $row['total_cost'] ?? 0;
        $filtered_profit += $row['profit'] ?? 0;
        $filtered_sales_data[] = $row;
    }
}

// All-time totals are loaded via AJAX (?action=profitability) and injected into the card by JS.



// Today's profit — company-filtered, COALESCE(0) for missing purchases, correct divisor
$today = date('Y-m-d');
$today_sales = (float)(mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COALESCE((SELECT SUM(total_amount) FROM sales_bulk   WHERE sale_date='$today' AND refunded=0 AND has_loan=0 $cid_and),0)
          +COALESCE((SELECT SUM(total_amount) FROM sales_retail WHERE sale_date='$today' AND refunded=0 AND has_loan=0 $cid_and),0) v
"))['v'] ?? 0);

$today_bulk_cost = (float)(mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COALESCE(SUM(sb.cost_total),0) v
    FROM sales_bulk sb
    WHERE sb.sale_date='$today' AND sb.refunded=0 AND sb.has_loan=0 " . cidAndFor('sb') . "
"))['v'] ?? 0);

$today_retail_cost = (float)(mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COALESCE(SUM(sr.cost_total),0) v
    FROM sales_retail sr
    WHERE sr.sale_date='$today' AND sr.refunded=0 AND sr.has_loan=0 " . cidAndFor('sr') . "
"))['v'] ?? 0);

$today_cost   = $today_bulk_cost + $today_retail_cost;
$today_profit = $today_sales - $today_cost;
$today_margin = $today_sales > 0 ? ($today_profit / $today_sales) * 100 : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profit & Revenue Analysis - Small Stock Management</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/revenue.css">
   
</head>
<body>
    <div class="dashboard-container">
        <?php include 'sidebar.php'; ?>
        
        <div class="main-content">
            <h1>Profit & Revenue Analysis</h1>
            
            <!-- Current Week Profit Summary -->
            <div class="profit-summary">
                <div class="profit-card">
                    <h4>💵 Today's Profit</h4>
                    <div class="profit-amount <?php echo $today_profit >= 0 ? 'profit-positive' : 'profit-negative'; ?>">
                        RWF <?php echo number_format($today_profit, 0); ?>
                    </div>
                    <div class="profit-stats">
                        <span>Sales: RWF <?php echo number_format($today_sales, 0); ?></span>
                        <span>Cost: RWF <?php echo number_format($today_cost, 0); ?></span>
                        <span>Margin:
                            <span class="margin-badge <?php
                                if($today_margin >= 30) echo 'margin-high';
                                elseif($today_margin >= 15) echo 'margin-medium';
                                else echo 'margin-low';
                            ?>">
                                <?php echo number_format($today_margin, 1); ?>%
                            </span>
                        </span>
                    </div>
                </div>

                <div class="profit-card">
                    <h4>📊 This Week's Revenue</h4>
                    <div class="profit-amount">RWF <?php echo number_format($current_week['total_revenue'] ?? 0, 0); ?></div>
                    <div class="profit-stats">
                        <span>Bulk: RWF <?php echo number_format($current_week['bulk_sales_total'] ?? 0, 0); ?></span>
                        <span>Retail: RWF <?php echo number_format($current_week['retail_sales_total'] ?? 0, 0); ?></span>
                    </div>
                </div>
                
                <div class="profit-card">
                    <h4>💰 This Week's Cost</h4>
                    <div class="profit-amount" style="color: #dc3545;">
                        RWF <?php echo number_format($current_week['total_cost'] ?? 0, 0); ?>
                    </div>
                    <div class="profit-stats">
                        <span>Purchase cost of goods sold</span>
                    </div>
                </div>
                
                <div class="profit-card">
                    <h4>💵 This Week's Profit</h4>
                    <div class="profit-amount <?php echo ($current_week['total_profit'] ?? 0) >= 0 ? 'profit-positive' : 'profit-negative'; ?>">
                        RWF <?php echo number_format($current_week['total_profit'] ?? 0, 0); ?>
                    </div>
                    <div class="profit-stats">
                        <span>Margin: 
                            <span class="margin-badge <?php 
                                $margin = $current_week['profit_margin'] ?? 0;
                                if($margin >= 30) echo 'margin-high';
                                elseif($margin >= 15) echo 'margin-medium';
                                else echo 'margin-low';
                            ?>">
                                <?php echo number_format($margin, 1); ?>%
                            </span>
                        </span>
                    </div>
                </div>
                
                <div class="profit-card">
                    <h4>📈 All Time Performance</h4>
                    <div class="profit-amount" id="at-revenue">—</div>
                    <div class="profit-stats">
                        <span>Profit: <span id="at-profit">—</span></span>
                        <span id="at-margin" class="profit-positive">—%</span>
                    </div>
                </div>
            </div>
            <!-- Revenue vs Cost Chart -->
            <div class="chart-container">
                <h2>Revenue vs Cost Analysis</h2>
                <div class="chart-wrapper">
                    <canvas id="revenueCostChart"></canvas>
                </div>
            </div>
            
           

            <!-- ── Tabbed tables ──────────────────────────────────────────────────── -->
            <div class="rev-tabs-wrap">

                <div class="rev-tabs-bar">
                    <button class="rev-tab active" onclick="switchRevTab('sales', this)">Sales Analysis</button>
                    <button class="rev-tab" onclick="switchRevTab('products', this)">Product Profitability</button>
                    <button class="rev-tab" onclick="switchRevTab('weekly', this)">Weekly Summary</button>
                </div>

                <!-- Tab 1: Sales Profit Analysis -->
                <div class="rev-tab-panel active" id="revTab-sales">
                    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:18px;">
                        <p class="rev-tab-title">Sales — Profit Analysis</p>
                        <form method="GET" action="revenue.php" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                            <label style="font-size:13px;color:#6c757d;">From:</label>
                            <input type="date" name="from" value="<?php echo htmlspecialchars($filter_from); ?>"
                                style="padding:7px 11px;border:1px solid var(--profit-border);border-radius:8px;font-size:13px;">
                            <label style="font-size:13px;color:#6c757d;">To:</label>
                            <input type="date" name="to" value="<?php echo htmlspecialchars($filter_to); ?>"
                                style="padding:7px 11px;border:1px solid var(--profit-border);border-radius:8px;font-size:13px;">
                            <button type="submit" style="padding:7px 18px;background:var(--profit-primary);color:#fff;border:none;border-radius:8px;cursor:pointer;font-size:13px;font-weight:600;">
                                Filter
                            </button>
                            <a href="revenue.php" style="padding:7px 14px;background:#f8f9fa;color:#6c757d;border:1px solid var(--profit-border);border-radius:8px;text-decoration:none;font-size:13px;">
                                Reset
                            </a>
                        </form>
                    </div>
                    <div class="table-responsive">
                        <table class="table comparison-table" id="tblProfit">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Type</th>
                                    <th>Product</th>
                                    <th>Quantity</th>
                                    <th>Selling Price</th>
                                    <th>Purchase Cost</th>
                                    <th>Total Revenue</th>
                                    <th>Total Cost</th>
                                    <th>Profit</th>
                                    <th>Margin</th>
                                    <th>Customer</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($filtered_sales_data)):
                                    foreach($filtered_sales_data as $sale):
                                        $profit_class = ($sale['profit'] ?? 0) >= 0 ? 'profit-positive' : 'profit-negative';
                                ?>
                                <tr class="data-row <?php echo ($sale['profit'] ?? 0) >= 0 ? 'highlight-profit' : 'highlight-loss'; ?>">
                                    <td><?php echo date('M d', strtotime($sale['sale_date'])); ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo $sale['sale_type'] == 'Bulk' ? 'primary' : 'info'; ?>">
                                            <?php echo $sale['sale_type']; ?>
                                        </span>
                                    </td>
                                    <td><strong><?php echo htmlspecialchars($sale['product_name'] ?? ''); ?></strong></td>
                                    <td><?php echo $sale['quantity'] ?? 0; ?> <?php echo ($sale['sale_type'] ?? '') == 'Bulk' ? 'pkg' : 'pcs'; ?></td>
                                    <td>RWF <?php echo number_format($sale['selling_price'] ?? 0, 0); ?></td>
                                    <td class="cost-value">RWF <?php echo number_format($sale['purchase_price'] ?? 0, 0); ?></td>
                                    <td class="revenue-value">RWF <?php echo number_format($sale['total_amount'] ?? 0, 0); ?></td>
                                    <td class="cost-value">RWF <?php echo number_format($sale['total_cost'] ?? 0, 0); ?></td>
                                    <td class="<?php echo $profit_class; ?>">
                                        RWF <?php echo number_format($sale['profit'] ?? 0, 0); ?>
                                    </td>
                                    <td>
                                        <span class="margin-badge <?php
                                            $margin_value = $sale['margin'] ?? 0;
                                            if($margin_value >= 30) echo 'margin-high';
                                            elseif($margin_value >= 15) echo 'margin-medium';
                                            else echo 'margin-low';
                                        ?>">
                                            <?php echo number_format($margin_value, 1); ?>%
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($sale['customer_name'] ?? 'N/A'); ?></td>
                                </tr>
                                <?php
                                    endforeach;
                                else:
                                ?>
                                <tr>
                                    <td colspan="11" style="text-align:center;padding:30px;color:#64748b;">
                                        No sales found for the selected period
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                            <tfoot>
                                <tr style="background:#f8fafc;font-weight:700;">
                                    <td colspan="6"></td>
                                    <td class="revenue-value">Total Revenue</td>
                                    <td class="cost-value">Total Cost</td>
                                    <td class="<?php echo $filtered_profit >= 0 ? 'profit-positive' : 'profit-negative'; ?>">Total Profit</td>
                                    <td colspan="2"></td>
                                </tr>
                                <tr style="background:#f8fafc;font-weight:700;">
                                    <td colspan="6"></td>
                                    <td>RWF <?php echo number_format($filtered_revenue, 0); ?></td>
                                    <td>RWF <?php echo number_format($filtered_cost, 0); ?></td>
                                    <td class="<?php echo $filtered_profit >= 0 ? 'profit-positive' : 'profit-negative'; ?>">
                                        RWF <?php echo number_format($filtered_profit, 0); ?>
                                    </td>
                                    <td colspan="2"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <div id="profitPagBar" class="rev-pag-bar">
                        <button id="profitPrevBtn" class="rev-pag-btn" onclick="changeProfitPage(-1)">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                            Prev
                        </button>
                        <span id="profitPageInfo" class="rev-pag-info"></span>
                        <button id="profitNextBtn" class="rev-pag-btn" onclick="changeProfitPage(1)">
                            Next
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                        </button>
                        <div class="rev-pag-size-wrap">
                            <span class="rev-pag-size-label">Per page:</span>
                            <select id="profitPageSizeSel" class="rev-pag-size-sel" onchange="changeProfitPageSize()">
                                <option value="25">25</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                                <option value="9999">All</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Tab 2: Product Profitability (AJAX) -->
                <div class="rev-tab-panel" id="revTab-products">
                    <p class="rev-tab-title">Product Profitability</p>
                    <div id="profitability-body" style="padding:20px 0;text-align:center;color:var(--secondary);">
                        <div style="display:inline-block;width:30px;height:30px;border:3px solid #e5e7eb;border-top-color:var(--profit-primary);border-radius:50%;animation:spin .7s linear infinite;margin-bottom:8px;"></div>
                        <div>Loading profitability data…</div>
                    </div>
                    <div id="prodPagBar" class="rev-pag-bar" style="display:none;">
                        <button id="prodPrevBtn" class="rev-pag-btn" onclick="changeProdPage(-1)">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                            Prev
                        </button>
                        <span id="prodPageInfo" class="rev-pag-info"></span>
                        <button id="prodNextBtn" class="rev-pag-btn" onclick="changeProdPage(1)">
                            Next
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                        </button>
                        <div class="rev-pag-size-wrap">
                            <span class="rev-pag-size-label">Per page:</span>
                            <select id="prodPageSizeSel" class="rev-pag-size-sel" onchange="changeProdPageSize()">
                                <option value="25">25</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                                <option value="9999">All</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Tab 3: Weekly Performance Summary -->
                <div class="rev-tab-panel" id="revTab-weekly">
                    <p class="rev-tab-title">Weekly Performance Summary</p>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Week</th>
                                    <th>Period</th>
                                    <th>Bulk Sales</th>
                                    <th>Retail Sales</th>
                                    <th>Total Revenue</th>
                                    <th>Total Cost</th>
                                    <th>Profit</th>
                                    <th>Margin</th>
                                    <th>Trend</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $previous_profit = 0;
                                if ($weekly_data && mysqli_num_rows($weekly_data) > 0):
                                    while($week = mysqli_fetch_assoc($weekly_data)):
                                        $week_profit = $week['total_profit'] ?? ($week['total_revenue'] * 0.2);
                                        $trend = $week_profit - $previous_profit;
                                        $trend_class = $trend > 0 ? 'profit-positive' : ($trend < 0 ? 'profit-negative' : 'profit-neutral');
                                        $previous_profit = $week_profit;
                                ?>
                                <tr>
                                    <td><strong>Week <?php echo date('W', strtotime($week['week_start_date'])); ?></strong></td>
                                    <td><?php echo date('M d', strtotime($week['week_start_date'])); ?> – <?php echo date('M d', strtotime($week['week_end_date'])); ?></td>
                                    <td>RWF <?php echo number_format($week['bulk_sales_total'], 0); ?></td>
                                    <td>RWF <?php echo number_format($week['retail_sales_total'], 0); ?></td>
                                    <td class="revenue-value">RWF <?php echo number_format($week['total_revenue'], 0); ?></td>
                                    <td class="cost-value">RWF <?php echo number_format($week['total_cost'] ?? 0, 0); ?></td>
                                    <td class="<?php echo $week_profit >= 0 ? 'profit-positive' : 'profit-negative'; ?>">
                                        RWF <?php echo number_format($week_profit, 0); ?>
                                    </td>
                                    <td>
                                        <span class="margin-badge <?php
                                            $margin = $week['profit_margin'] ?? ($week['total_revenue'] > 0 ? ($week_profit / $week['total_revenue'] * 100) : 0);
                                            if($margin >= 30) echo 'margin-high';
                                            elseif($margin >= 15) echo 'margin-medium';
                                            else echo 'margin-low';
                                        ?>"><?php echo number_format($margin, 1); ?>%</span>
                                    </td>
                                    <td class="<?php echo $trend_class; ?>">
                                        <?php if($trend > 0): ?>↑ +RWF <?php echo number_format($trend, 0);
                                        elseif($trend < 0): ?>↓ RWF <?php echo number_format(abs($trend), 0);
                                        else: ?>→<?php endif; ?>
                                    </td>
                                </tr>
                                <?php
                                    endwhile;
                                else:
                                ?>
                                <tr>
                                    <td colspan="9" style="text-align:center;padding:30px;color:#64748b;">No weekly data available</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div><!-- /.rev-tabs-wrap -->
        </div>
    </div>
    
    <script src="chart.js"></script>
<script>
    (function() {
        'use strict';
        
        let revenueChart = null;
        
        function initRevenueChart() {
            const canvas = document.getElementById('revenueCostChart');
            if (!canvas) return;
            
            // Destroy existing chart
            if (revenueChart) {
                revenueChart.destroy();
                revenueChart = null;
            }
            
            // Clear canvas
            const ctx = canvas.getContext('2d');
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            
            // Prepare chart data
            const weekLabels = [];
            const revenueData = [];
            const costData = [];
            const profitData = [];
            
            <?php
            if (isset($weekly_data) && mysqli_num_rows($weekly_data) > 0) {
                mysqli_data_seek($weekly_data, 0);
                $chart_data = mysqli_fetch_all($weekly_data, MYSQLI_ASSOC);
                $chart_data = array_reverse($chart_data);
                
                foreach($chart_data as $row): 
                    $week_profit = $row['total_profit'] ?? ($row['total_revenue'] * 0.2);
                    $week_cost = $row['total_cost'] ?? ($row['total_revenue'] * 0.8);
                ?>
                    weekLabels.push('Week <?php echo date('W', strtotime($row['week_start_date'])); ?>');
                    revenueData.push(<?php echo $row['total_revenue'] ?? 0; ?>);
                    costData.push(<?php echo $week_cost; ?>);
                    profitData.push(<?php echo $week_profit; ?>);
                <?php 
                endforeach; 
            }
            ?>
            
            // Only create chart if we have data
            if (weekLabels.length > 0) {
                try {
                    revenueChart = new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: weekLabels,
                            datasets: [
                                {
                                    label: 'Revenue',
                                    data: revenueData,
                                    backgroundColor: 'rgba(40, 167, 69, 0.7)',
                                    borderColor: 'rgba(40, 167, 69, 1)',
                                    borderWidth: 1
                                },
                                {
                                    label: 'Cost',
                                    data: costData,
                                    backgroundColor: 'rgba(220, 53, 69, 0.7)',
                                    borderColor: 'rgba(220, 53, 69, 1)',
                                    borderWidth: 1
                                },
                                {
                                    label: 'Profit',
                                    data: profitData,
                                    type: 'line',
                                    borderColor: 'rgba(102, 126, 234, 1)',
                                    backgroundColor: 'rgba(102, 126, 234, 0.1)',
                                    borderWidth: 3,
                                    pointRadius: 5,
                                    pointBackgroundColor: 'rgba(102, 126, 234, 1)',
                                    tension: 0.1,
                                    yAxisID: 'y1'
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            animation: {
                                duration: 0
                            },
                            interaction: {
                                mode: 'index',
                                intersect: false,
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    position: 'left',
                                    title: {
                                        display: true,
                                        text: 'Amount (RWF)'
                                    },
                                    ticks: {
                                        callback: function(value) {
                                            return 'RWF ' + value.toLocaleString();
                                        }
                                    }
                                },
                                y1: {
                                    beginAtZero: true,
                                    position: 'right',
                                    title: {
                                        display: true,
                                        text: 'Profit (RWF)'
                                    },
                                    ticks: {
                                        callback: function(value) {
                                            return 'RWF ' + value.toLocaleString();
                                        }
                                    },
                                    grid: {
                                        drawOnChartArea: false,
                                    },
                                }
                            }
                        }
                    });
                } catch (e) {
                    console.error('Revenue chart error:', e);
                }
            }
        }
        
        // Initialize when DOM is ready
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initRevenueChart);
        } else {
            initRevenueChart();
        }
        
    })();

</script>
    <script src="script.js"></script>
<script>
// ── Tab switching ─────────────────────────────────────────────────────────────
function switchRevTab(name, btn) {
    document.querySelectorAll('.rev-tab').forEach(function(b) { b.classList.remove('active'); });
    document.querySelectorAll('.rev-tab-panel').forEach(function(p) { p.classList.remove('active'); });
    btn.classList.add('active');
    document.getElementById('revTab-' + name).classList.add('active');
}

// ── Sales Profit table pagination ─────────────────────────────────────────────
var _profitPage = 1, _profitPageSize = 25;

function renderProfitPage() {
    var tbody   = document.querySelector('#tblProfit tbody');
    if (!tbody) return;
    var allRows = Array.from(tbody.querySelectorAll('tr.data-row'));
    var total   = allRows.length;

    if (total === 0) {
        document.getElementById('profitPagBar').style.display = 'none';
        return;
    }
    document.getElementById('profitPagBar').style.display = 'flex';

    var totalPages = Math.max(1, Math.ceil(total / _profitPageSize));
    if (_profitPage > totalPages) _profitPage = totalPages;

    var start = (_profitPage - 1) * _profitPageSize;
    var end   = Math.min(start + _profitPageSize, total);

    allRows.forEach(function(row, i) {
        row.style.display = (i >= start && i < end) ? '' : 'none';
    });

    document.getElementById('profitPrevBtn').disabled = _profitPage <= 1;
    document.getElementById('profitNextBtn').disabled = _profitPage >= totalPages;
    document.getElementById('profitPageInfo').innerHTML =
        'Page <strong>' + _profitPage + '</strong> of <strong>' + totalPages +
        '</strong> &nbsp;&middot;&nbsp; ' + total + ' sale' + (total !== 1 ? 's' : '');
}

function changeProfitPage(dir) { _profitPage += dir; renderProfitPage(); }
function changeProfitPageSize() {
    _profitPageSize = parseInt(document.getElementById('profitPageSizeSel').value);
    _profitPage = 1;
    renderProfitPage();
}

renderProfitPage();
</script>

<script>
// ── Product Profitability — AJAX + pagination ─────────────────────────────────
var _prodData = [], _prodTotalRev = 0, _prodPage = 1, _prodPageSize = 25;

function _prodFmt(n) { return Math.round(n).toLocaleString(); }
function _prodEsc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function renderProdPage() {
    var total      = _prodData.length;
    var totalPages = Math.max(1, Math.ceil(total / _prodPageSize));
    if (_prodPage > totalPages) _prodPage = totalPages;

    var start = (_prodPage - 1) * _prodPageSize;
    var end   = Math.min(start + _prodPageSize, total);

    var rows = '';
    if (total === 0) {
        rows = '<tr><td colspan="6" style="padding:24px;text-align:center;color:var(--secondary);">No product sales data available.</td></tr>';
    } else {
        for (var i = start; i < end; i++) {
            var p   = _prodData[i];
            var rev = parseFloat(p.total_rev) || 0;
            rows += '<tr>'+
                '<td><strong>'+_prodEsc(p.name)+'</strong></td>'+
                '<td>'+_prodEsc(p.category||'N/A')+'</td>'+
                '<td>'+p.bulk_qty+' pkg<br><small>RWF '+_prodFmt(p.bulk_rev)+'</small></td>'+
                '<td>'+p.retail_qty+' pcs<br><small>RWF '+_prodFmt(p.retail_rev)+'</small></td>'+
                '<td class="revenue-value">RWF '+_prodFmt(rev)+'</td>'+
                '<td>'+p.bulk_cnt+' bulk / '+p.retail_cnt+' retail</td>'+
                '</tr>';
        }
    }

    document.querySelector('#tblProductRevenue tbody').innerHTML = rows;

    var bar = document.getElementById('prodPagBar');
    bar.style.display = total === 0 ? 'none' : 'flex';
    document.getElementById('prodPrevBtn').disabled = _prodPage <= 1;
    document.getElementById('prodNextBtn').disabled = _prodPage >= totalPages;
    document.getElementById('prodPageInfo').innerHTML =
        'Page <strong>' + _prodPage + '</strong> of <strong>' + totalPages +
        '</strong> &nbsp;&middot;&nbsp; ' + total + ' product' + (total !== 1 ? 's' : '');
}

function changeProdPage(dir) { _prodPage += dir; renderProdPage(); }
function changeProdPageSize() {
    _prodPageSize = parseInt(document.getElementById('prodPageSizeSel').value);
    _prodPage = 1;
    renderProdPage();
}

(function() {
    var params = new URLSearchParams(window.location.search);
    var from = params.get('from') || '';
    var to   = params.get('to')   || '';

    fetch('revenue.php?action=profitability' + (from ? '&from='+from : '') + (to ? '&to='+to : ''))
        .then(function(r) { return r.json(); })
        .then(function(d) {
            _prodData     = d.products || [];
            _prodTotalRev = d.total_rev || 0;

            // Populate All Time Performance card
            var atRev    = parseFloat(d.total_rev)    || 0;
            var atProfit = parseFloat(d.total_profit) || 0;
            var atMargin = atRev > 0 ? (atProfit / atRev * 100) : 0;
            document.getElementById('at-revenue').textContent = 'RWF ' + _prodFmt(atRev);
            document.getElementById('at-profit').textContent  = 'RWF ' + _prodFmt(atProfit);
            var atMarginEl = document.getElementById('at-margin');
            atMarginEl.textContent = atMargin.toFixed(1) + '%';
            atMarginEl.className   = atProfit >= 0 ? 'profit-positive' : 'profit-negative';

            var html = '<div class="table-responsive">'+
                '<table class="table" id="tblProductRevenue">'+
                '<thead><tr>'+
                '<th>Product</th><th>Category</th><th>Bulk Sales</th>'+
                '<th>Retail Sales</th><th>Revenue</th><th>Transactions</th>'+
                '</tr></thead><tbody></tbody>'+
                '<tfoot><tr><td colspan="4" style="font-weight:700;text-align:right;">All-time Total</td>'+
                '<td class="revenue-value">RWF '+_prodFmt(_prodTotalRev)+'</td>'+
                '<td></td></tr></tfoot>'+
                '</table></div>';
            document.getElementById('profitability-body').innerHTML = html;
            renderProdPage();
        })
        .catch(function() {
            document.getElementById('profitability-body').innerHTML =
                '<p style="color:var(--danger);padding:16px;">Could not load profitability data.</p>';
        });
})();
</script>
</body>
</html>