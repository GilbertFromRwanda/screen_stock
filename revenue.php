<?php
require_once 'config.php';

if (!isLoggedIn()) redirect('login.php');

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
        LEFT JOIN sales_bulk sb ON p.id=sb.product_id " . cidAndFor('sb') . "
        LEFT JOIN sales_retail sr ON p.id=sr.product_id " . cidAndFor('sr') . "
        GROUP BY p.id HAVING total_rev > 0 ORDER BY total_rev DESC
    ");
    while ($r = mysqli_fetch_assoc($q)) $products[] = $r;

    $ati = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT
            COALESCE((SELECT SUM(total_amount) FROM sales_bulk   WHERE 1=1 " . cidAndFor('sales_bulk')   . "),0)+
            COALESCE((SELECT SUM(total_amount) FROM sales_retail WHERE 1=1 " . cidAndFor('sales_retail') . "),0) total_rev,
            COALESCE((SELECT SUM(pu.cost_price*sb.quantity) FROM sales_bulk sb JOIN purchases pu ON pu.product_id=sb.product_id AND pu.company_id=sb.company_id
             WHERE 1=1 " . cidAndFor('sb') . " AND pu.id=(SELECT id FROM purchases p2 WHERE p2.product_id=sb.product_id " . cidAndFor('p2') . " AND p2.purchase_date<=sb.sale_date ORDER BY p2.purchase_date DESC LIMIT 1)),0) bulk_cost,
            COALESCE((SELECT SUM((pu.cost_price/NULLIF(s.pieces_per_package,1))*sr.pieces_sold) FROM sales_retail sr JOIN purchases pu ON pu.product_id=sr.product_id AND pu.company_id=sr.company_id LEFT JOIN stock s ON sr.product_id=s.product_id AND s.company_id=sr.company_id
             WHERE 1=1 " . cidAndFor('sr') . " AND pu.id=(SELECT id FROM purchases p2 WHERE p2.product_id=sr.product_id " . cidAndFor('p2') . " AND p2.purchase_date<=sr.sale_date ORDER BY p2.purchase_date DESC LIMIT 1)),0) retail_cost
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

    $bulk_query = mysqli_query($conn, "
        SELECT sb.*,
            p.name as product_name,
            (SELECT cost_price FROM purchases WHERE product_id = sb.product_id $cid_and ORDER BY purchase_date DESC LIMIT 1) as last_cost_price,
            (SELECT package_price FROM stock WHERE product_id = sb.product_id $cid_and) as default_price,
            COALESCE(NULLIF(sb.level_divisor, 0), 1) as effective_divisor
        FROM sales_bulk sb
        JOIN products p ON sb.product_id = p.id
        WHERE sb.sale_date BETWEEN '$week_start' AND '$week_end'
          AND sb.refunded = 0 AND sb.has_loan = 0 $cid_and
    ");
    
    $bulk_total = 0;
    $bulk_cost_total = 0;
    $bulk_profit = 0;
    
    while ($sale = mysqli_fetch_assoc($bulk_query)) {
        $bulk_total += $sale['total_amount'];
        $cost = ($sale['last_cost_price'] ?? 0) * $sale['quantity'] / ($sale['effective_divisor'] ?? 1);
        $bulk_cost_total += $cost;
        $bulk_profit += ($sale['total_amount'] - $cost);
    }
    
    $retail_query = mysqli_query($conn, "
        SELECT sr.*,
            p.name as product_name,
            (SELECT cost_price FROM purchases pur WHERE pur.product_id = sr.product_id " . cidAndFor('pur') . " ORDER BY purchase_date DESC LIMIT 1) as last_cost_price,
            COALESCE(s.pieces_per_package, 1) as pieces_per_package,
            s.retail_price as default_price
        FROM sales_retail sr
        JOIN products p ON sr.product_id = p.id
        LEFT JOIN stock s ON sr.product_id = s.product_id AND s.company_id = sr.company_id
        WHERE sr.sale_date BETWEEN '$week_start' AND '$week_end'
          AND sr.refunded = 0 AND sr.has_loan = 0 " . cidAndFor('sr') . "
    ");
    
    $retail_total = 0;
    $retail_cost_total = 0;
    $retail_profit = 0;
    
    while ($sale = mysqli_fetch_assoc($retail_query)) {
        $retail_total += $sale['total_amount'];
        // Calculate cost per piece
        $pieces_per_package = $sale['pieces_per_package'] ?? 1;
        $cost_per_piece = ($sale['last_cost_price'] ?? 0) / $pieces_per_package;
        $cost = $cost_per_piece * $sale['pieces_sold'];
        $retail_cost_total += $cost;
        $retail_profit += ($sale['total_amount'] - $cost);
    }
    
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
$current_week_sales = mysqli_query($conn, "
    -- Bulk sales with profit
    SELECT
        'Bulk' as sale_type,
        sb.sale_date,
        p.name as product_name,
        sb.quantity as quantity,
        sb.package_price as selling_price,
        sb.total_amount,
        COALESCE(pu.cost_price, 0) as purchase_price,
        COALESCE(pu.cost_price * sb.quantity, 0) as total_cost,
        (sb.total_amount - COALESCE(pu.cost_price * sb.quantity, 0)) as profit,
        ROUND(
            CASE
                WHEN sb.total_amount > 0
                THEN ((sb.total_amount - COALESCE(pu.cost_price * sb.quantity, 0)) / sb.total_amount * 100)
                ELSE 0
            END, 2
        ) as margin,
        sb.customer_name
    FROM sales_bulk sb
    JOIN products p ON sb.product_id = p.id
    LEFT JOIN purchases pu ON pu.product_id = sb.product_id AND pu.company_id = sb.company_id
    WHERE sb.sale_date BETWEEN '$filter_from' AND '$filter_to' " . cidAndFor('sb') . "
    AND pu.id = (
        SELECT id FROM purchases p2
        WHERE p2.product_id = sb.product_id " . cidAndFor('p2') . "
        AND p2.purchase_date <= sb.sale_date
        ORDER BY p2.purchase_date DESC LIMIT 1
    )

    UNION ALL

    -- Retail sales with profit
    SELECT
        'Retail' as sale_type,
        sr.sale_date,
        p.name as product_name,
        sr.pieces_sold as quantity,
        sr.retail_price as selling_price,
        sr.total_amount,
        COALESCE(pu.cost_price, 0) as purchase_price,
        COALESCE((pu.cost_price / NULLIF(s.pieces_per_package, 0)) * sr.pieces_sold, 0) as total_cost,
        (sr.total_amount - COALESCE((pu.cost_price / NULLIF(s.pieces_per_package, 0)) * sr.pieces_sold, 0)) as profit,
        ROUND(
            CASE
                WHEN sr.total_amount > 0
                THEN ((sr.total_amount - COALESCE((pu.cost_price / NULLIF(s.pieces_per_package, 0)) * sr.pieces_sold, 0)) / sr.total_amount * 100)
                ELSE 0
            END, 2
        ) as margin,
        sr.customer_name
    FROM sales_retail sr
    JOIN products p ON sr.product_id = p.id
    LEFT JOIN purchases pu ON pu.product_id = sr.product_id AND pu.company_id = sr.company_id
    LEFT JOIN stock s ON sr.product_id = s.product_id AND s.company_id = sr.company_id
    WHERE sr.sale_date BETWEEN '$filter_from' AND '$filter_to' " . cidAndFor('sr') . "
    AND pu.id = (
        SELECT id FROM purchases p2
        WHERE p2.product_id = sr.product_id " . cidAndFor('p2') . "
        AND p2.purchase_date <= sr.sale_date
        ORDER BY p2.purchase_date DESC LIMIT 1
    )
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

// Product profitability + all-time totals are loaded via AJAX (?action=profitability)
// to avoid blocking initial page render with heavy correlated subqueries.
$total_all_time = ['total_revenue' => 0, 'total_cost' => 0, 'total_profit' => 0];
$total_all_time['total_profit'] = ($total_all_time['total_revenue'] ?? 0) - $total_all_time['total_cost'];

// Daily revenue for current week (Sunday to Saturday)
$week_sun = date('Y-m-d', strtotime('sunday last week'));
$week_sat = date('Y-m-d', strtotime('saturday this week'));
// If today is Sunday, adjust
if (date('w') == 0) {
    $week_sun = date('Y-m-d');
    $week_sat = date('Y-m-d', strtotime('saturday next week'));
}

$daily_revenue_query = mysqli_query($conn, "
    SELECT
        dates.date,
        DAYNAME(dates.date) as day_name,
        COALESCE(bulk.revenue, 0) as bulk_revenue,
        COALESCE(retail.revenue, 0) as retail_revenue,
        COALESCE(bulk.revenue, 0) + COALESCE(retail.revenue, 0) as total_revenue,
        COALESCE(bulk.cost, 0) + COALESCE(retail.cost, 0) as total_cost,
        (COALESCE(bulk.revenue, 0) + COALESCE(retail.revenue, 0)) - (COALESCE(bulk.cost, 0) + COALESCE(retail.cost, 0)) as profit
    FROM (
        SELECT DATE('$week_sun') + INTERVAL seq DAY as date
        FROM (SELECT 0 as seq UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6) as s
    ) as dates
    LEFT JOIN (
        SELECT sale_date,
            SUM(total_amount) as revenue,
            SUM(COALESCE((SELECT cost_price FROM purchases pu2 WHERE pu2.product_id = sb.product_id " . cidAndFor('pu2') . " ORDER BY purchase_date DESC LIMIT 1), 0) * sb.quantity) as cost
        FROM sales_bulk sb WHERE 1=1 " . cidAndFor('sb') . "
        GROUP BY sale_date
    ) as bulk ON bulk.sale_date = dates.date
    LEFT JOIN (
        SELECT sale_date,
            SUM(total_amount) as revenue,
            SUM(COALESCE(
                (SELECT pu.cost_price / NULLIF(s.pieces_per_package, 0) FROM purchases pu JOIN stock s ON s.product_id = pu.product_id AND s.company_id = pu.company_id WHERE pu.product_id = sr.product_id " . cidAndFor('pu') . " ORDER BY pu.purchase_date DESC LIMIT 1), 0
            ) * sr.pieces_sold) as cost
        FROM sales_retail sr WHERE 1=1 " . cidAndFor('sr') . "
        GROUP BY sale_date
    ) as retail ON retail.sale_date = dates.date
    ORDER BY dates.date ASC
");

$daily_data = [];
while ($row = mysqli_fetch_assoc($daily_revenue_query)) {
    $daily_data[] = $row;
}

// Today's profit
$today = date('Y-m-d');
$today_profit_query = mysqli_query($conn, "
    SELECT
        COALESCE((SELECT SUM(total_amount) FROM sales_bulk WHERE sale_date = '$today'), 0) +
        COALESCE((SELECT SUM(total_amount) FROM sales_retail WHERE sale_date = '$today'), 0) as today_sales,
        COALESCE((
            SELECT SUM(sb.total_amount - (COALESCE((SELECT cost_price FROM purchases WHERE product_id = sb.product_id ORDER BY purchase_date DESC LIMIT 1), 0) * sb.quantity))
            FROM sales_bulk sb WHERE sb.sale_date = '$today'
        ), 0) +
        COALESCE((
            SELECT SUM(sr.total_amount - (COALESCE(
                (SELECT cost_price / NULLIF(s.pieces_per_package, 0) FROM purchases pu, stock s WHERE pu.product_id = sr.product_id AND s.product_id = sr.product_id ORDER BY pu.purchase_date DESC LIMIT 1), 0
            ) * sr.pieces_sold))
            FROM sales_retail sr WHERE sr.sale_date = '$today'
        ), 0) as today_profit
");
$today_data = mysqli_fetch_assoc($today_profit_query);
$today_sales = $today_data['today_sales'] ?? 0;
$today_profit = $today_data['today_profit'] ?? 0;
$today_cost = $today_sales - $today_profit;
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
                    <div class="profit-amount">RWF <?php echo number_format($total_all_time['total_revenue'] ?? 0, 0); ?></div>
                    <div class="profit-stats">
                        <span>Profit: RWF <?php echo number_format($total_all_time['total_profit'] ?? 0, 0); ?></span>
                        <span class="<?php echo ($total_all_time['total_profit'] ?? 0) >= 0 ? 'profit-positive' : 'profit-negative'; ?>">
                            <?php 
                                $all_time_margin = ($total_all_time['total_revenue'] ?? 0) > 0 
                                    ? (($total_all_time['total_profit'] ?? 0) / ($total_all_time['total_revenue'] ?? 1)) * 100 
                                    : 0;
                                echo number_format($all_time_margin, 1); ?>%
                        </span>
                    </div>
                </div>
            </div>
             <!-- Daily Revenue Chart (Sun - Sat) -->
            <div class="chart-container">
                <h2>Daily Revenue (Sunday - Saturday)</h2>
                <div class="chart-wrapper">
                    <canvas id="dailyRevenueChart"></canvas>
                </div>
            </div>
            
            <!-- Revenue vs Cost Chart -->
            <div class="chart-container">
                <h2>Revenue vs Cost Analysis</h2>
                <div class="chart-wrapper">
                    <canvas id="revenueCostChart"></canvas>
                </div>
            </div>
            
           

            <!-- Sales Profit Analysis with Date Filter -->
            <div class="revenue-table">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; margin-bottom: 20px;">
                    <h2 style="margin: 0;">Sales - Profit Analysis</h2>
                    <form method="GET" action="revenue.php" style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                        <label style="font-size: 14px; color: #6c757d;">From:</label>
                        <input type="date" name="from" value="<?php echo htmlspecialchars($filter_from); ?>"
                            style="padding: 8px 12px; border: 1px solid #ddd; border-radius: 8px; font-size: 14px;">
                        <label style="font-size: 14px; color: #6c757d;">To:</label>
                        <input type="date" name="to" value="<?php echo htmlspecialchars($filter_to); ?>"
                            style="padding: 8px 12px; border: 1px solid #ddd; border-radius: 8px; font-size: 14px;">
                        <button type="submit" style="padding: 8px 20px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 8px; cursor: pointer; font-size: 14px;">
                            Filter
                        </button>
                        <a href="revenue.php" style="padding: 8px 16px; background: #f8f9fa; color: #6c757d; border: 1px solid #ddd; border-radius: 8px; text-decoration: none; font-size: 14px;">
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
                            <tr class="<?php echo ($sale['profit'] ?? 0) >= 0 ? 'highlight-profit' : 'highlight-loss'; ?>">
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
                                <td colspan="11" style="text-align: center; padding: 30px;">
                                    No sales found for the selected period
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                        <tfoot>
                            <tr style="background: #f8f9fa; font-weight: bold;">
                                <td colspan="6"></td>
                                <td class="revenue-value">Total Revenue</td>
                                <td class="cost-value">Total Cost</td>
                                <td class="<?php echo $filtered_profit >= 0 ? 'profit-positive' : 'profit-negative'; ?>">
                                    Total Profit
                                </td>
                                <td colspan="2"></td>
                            </tr>
                            <tr style="background: #f8f9fa; font-weight: bold;">
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
            </div>
            
            <!-- Product Profitability Analysis — loaded via AJAX -->
            <div class="revenue-table" style="margin-top:30px;" id="profitability-section">
                <h2>Product Profitability Analysis</h2>
                <div id="profitability-body" style="padding:20px 0;text-align:center;color:var(--secondary);">
                    <div style="display:inline-block;width:30px;height:30px;border:3px solid #e5e7eb;border-top-color:var(--primary);border-radius:50%;animation:spin .7s linear infinite;margin-bottom:8px;"></div>
                    <div>Loading profitability data…</div>
                </div>
            </div>
            <style>@keyframes spin{to{transform:rotate(360deg);}}</style>
            
            <!-- Weekly Performance Summary -->
            <div class="revenue-table" style="margin-top: 30px;">
                <h2>Weekly Performance Summary</h2>
                <div class="table-responsive">
                    <table class="table" >
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
                                <td>
                                    <?php echo date('M d', strtotime($week['week_start_date'])); ?> - 
                                    <?php echo date('M d', strtotime($week['week_end_date'])); ?>
                                </td>
                                <td>RWF <?php echo number_format($week['bulk_sales_total'], 0); ?></td>
                                <td>RWF <?php echo number_format($week['retail_sales_total'], 0); ?></td>
                                <td class="revenue-value">RWF <?php echo number_format($week['total_revenue'], 0); ?></td>
                                <td class="cost-value">RWF <?php echo number_format($week['total_cost'] ?? 0, 0); ?></td>
                                <td class="<?php echo $week_profit >= 0 ? 'profit-positive' : 'profit-negative'; ?>">
                                    RWF <?php echo number_format($week_profit, 0); ?>
                                </td>
                                <td>
                                    <span class="margin-badge <?php 
                                        $margin = $week['profit_margin'] ?? (($week['total_revenue'] > 0 ? ($week_profit / $week['total_revenue'] * 100) : 0));
                                        if($margin >= 30) echo 'margin-high';
                                        elseif($margin >= 15) echo 'margin-medium';
                                        else echo 'margin-low';
                                    ?>">
                                        <?php echo number_format($margin, 1); ?>%
                                    </span>
                                </td>
                                <td class="<?php echo $trend_class; ?>">
                                    <?php if($trend > 0): ?>
                                        ↑ +RWF <?php echo number_format($trend, 0); ?>
                                    <?php elseif($trend < 0): ?>
                                        ↓ RWF <?php echo number_format(abs($trend), 0); ?>
                                    <?php else: ?>
                                        →
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php 
                                endwhile; 
                            else:
                            ?>
                            <tr>
                                <td colspan="9" style="text-align: center; padding: 30px;">
                                    No weekly data available
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
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

    // Daily Revenue Chart (Sun - Sat)
    (function() {
        'use strict';

        let dailyChart = null;

        function initDailyRevenueChart() {
            const canvas = document.getElementById('dailyRevenueChart');
            if (!canvas) return;

            if (dailyChart) {
                dailyChart.destroy();
                dailyChart = null;
            }

            const ctx = canvas.getContext('2d');
            ctx.clearRect(0, 0, canvas.width, canvas.height);

            const dayLabels = [];
            const revenueData = [];
            const costData = [];
            const profitData = [];

            <?php foreach ($daily_data as $day): ?>
                dayLabels.push('<?php echo $day['day_name']; ?>');
                revenueData.push(<?php echo $day['total_revenue']; ?>);
                costData.push(<?php echo $day['total_cost']; ?>);
                profitData.push(<?php echo $day['profit']; ?>);
            <?php endforeach; ?>

            try {
                dailyChart = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: dayLabels,
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
                                tension: 0.3,
                                fill: true
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        animation: { duration: 0 },
                        interaction: {
                            mode: 'index',
                            intersect: false,
                        },
                        plugins: {
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return context.dataset.label + ': RWF ' + context.parsed.y.toLocaleString();
                                    }
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: 'Amount (RWF)'
                                },
                                ticks: {
                                    callback: function(value) {
                                        return 'RWF ' + value.toLocaleString();
                                    }
                                }
                            }
                        }
                    }
                });
            } catch (e) {
                console.error('Daily revenue chart error:', e);
            }
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initDailyRevenueChart);
        } else {
            initDailyRevenueChart();
        }
    })();
</script>
    <script src="script.js"></script>
         <script>
createAdvancedTableSearch('txtSearchProfit', 'tblProfit', []);
    </script>

<script>
// Load product profitability via AJAX so it doesn't block initial page render
(function() {
    var params = new URLSearchParams(window.location.search);
    var from = params.get('from') || '';
    var to   = params.get('to')   || '';

    fetch('revenue.php?action=profitability' + (from ? '&from='+from : '') + (to ? '&to='+to : ''))
        .then(function(r) { return r.json(); })
        .then(function(d) {
            var fmt = function(n) { return Math.round(n).toLocaleString(); };
            var cls = function(n) { return n >= 0 ? 'profit-positive' : 'profit-negative'; };
            var marginCls = function(m) { return m>=30?'margin-high':m>=15?'margin-medium':'margin-low'; };
            var esc = function(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); };

            var rows = '';
            if (d.products.length === 0) {
                rows = '<tr><td colspan="8" style="padding:24px;text-align:center;color:var(--secondary);">No product sales data available.</td></tr>';
            } else {
                d.products.forEach(function(p) {
                    var rev = parseFloat(p.total_rev) || 0;
                    var margin = rev > 0 ? 0 : 0; // simplified — no cost in this simplified response
                    rows += '<tr>'+
                        '<td><strong>'+esc(p.name)+'</strong></td>'+
                        '<td>'+esc(p.category||'N/A')+'</td>'+
                        '<td>'+p.bulk_qty+' pkg<br><small>RWF '+fmt(p.bulk_rev)+'</small></td>'+
                        '<td>'+p.retail_qty+' pcs<br><small>RWF '+fmt(p.retail_rev)+'</small></td>'+
                        '<td class="revenue-value">RWF '+fmt(rev)+'</td>'+
                        '<td>'+p.bulk_cnt+' bulk / '+p.retail_cnt+' retail</td>'+
                        '</tr>';
                });
            }

            var html = '<div class="table-responsive">'+
                '<table class="table" id="tblProductRevenue">'+
                '<thead><tr>'+
                '<th>Product</th><th>Category</th><th>Bulk Sales</th>'+
                '<th>Retail Sales</th><th>Revenue</th><th>Transactions</th>'+
                '</tr></thead><tbody>'+rows+'</tbody>'+
                '<tfoot><tr><td colspan="4" style="font-weight:700;text-align:right;">All-time Total</td>'+
                '<td class="revenue-value">RWF '+fmt(d.total_rev)+'</td>'+
                '<td></td></tr></tfoot>'+
                '</table></div>';
            document.getElementById('profitability-body').innerHTML = html;
            if (typeof createAdvancedTableSearch === 'function') {
                createAdvancedTableSearch('', 'tblProductRevenue', []);
            }
        })
        .catch(function() {
            document.getElementById('profitability-body').innerHTML =
                '<p style="color:var(--danger);padding:16px;">Could not load profitability data.</p>';
        });
})();
</script>
</body>
</html>