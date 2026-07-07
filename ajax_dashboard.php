<?php
require_once 'config.php';
if (!isLoggedIn()) { http_response_code(403); exit; }
$has_financials = hasPermission('financials');
header('Content-Type: application/json');

$today       = date('Y-m-d');
$week_start  = date('Y-m-d', strtotime('monday this week'));
$week_end    = date('Y-m-d', strtotime('sunday this week'));
$month_start = date('Y-m-01');
$month_end   = date('Y-m-t');
$yesterday   = date('Y-m-d', strtotime('-1 day'));
$ca          = cidAnd();

// ── Stock ─────────────────────────────────────────────────────────────────────
$total_products = (int)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM products"))['c'];

$rt_pcs = (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT SUM(pieces_quantity) v FROM retail_stock WHERE 1=1 $ca"))['v'] ?? 0);

// Read FIFO cost + selling values from cache; auto-seed on first load
$cache_count = (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM stock_value_cache WHERE 1=1 $ca"))['c'] ?? 0);
if ($cache_count === 0) {
    require_once 'stock_value.php';
    recalcStockValue($conn, cid());
}
$sv = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COALESCE(SUM(cost_wh),0) cost_wh,
           COALESCE(SUM(cost_rt),0) cost_rt,
           COALESCE(SUM(sell_wh),0) sell_wh,
           COALESCE(SUM(sell_rt),0) sell_rt,
           MAX(updated_at)          cache_updated
    FROM stock_value_cache WHERE 1=1 $ca
")) ?? [];

$sell_wh = (float)($sv['sell_wh'] ?? 0);
$sell_rt = (float)($sv['sell_rt'] ?? 0);
$cost_wh = (float)($sv['cost_wh'] ?? 0);
$cost_rt = (float)($sv['cost_rt'] ?? 0);

// ── Sales ─────────────────────────────────────────────────────────────────────
$ts = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT
      COALESCE((SELECT SUM(total_amount) FROM sales_bulk   WHERE sale_date='$today' AND refunded=0 AND has_loan=0 $ca),0)
     +COALESCE((SELECT SUM(total_amount) FROM sales_retail WHERE sale_date='$today' AND refunded=0 AND has_loan=0 $ca),0)
     +COALESCE((SELECT SUM(my_revenue)   FROM sales_external WHERE sale_date='$today' AND refunded=0 $ca),0) today_t,
      COALESCE((SELECT SUM(total_amount) FROM sales_bulk   WHERE sale_date='$today' AND refunded=0 AND has_loan=0 $ca),0) today_bulk,
      COALESCE((SELECT SUM(total_amount) FROM sales_retail WHERE sale_date='$today' AND refunded=0 AND has_loan=0 $ca),0) today_rt
"));
$today_t    = (float)$ts['today_t'];
$today_bulk = (float)$ts['today_bulk'];
$today_rt   = (float)$ts['today_rt'];

$week_sales  = (float)(mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COALESCE((SELECT SUM(total_amount) FROM sales_bulk   WHERE sale_date BETWEEN '$week_start' AND '$week_end' AND refunded=0 AND has_loan=0 $ca),0)
          +COALESCE((SELECT SUM(total_amount) FROM sales_retail WHERE sale_date BETWEEN '$week_start' AND '$week_end' AND refunded=0 AND has_loan=0 $ca),0)
          +COALESCE((SELECT SUM(my_revenue)   FROM sales_external WHERE sale_date BETWEEN '$week_start' AND '$week_end' AND refunded=0 $ca),0) v
"))['v'] ?? 0);

$month_sales = (float)(mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COALESCE((SELECT SUM(total_amount) FROM sales_bulk   WHERE sale_date BETWEEN '$month_start' AND '$month_end' AND refunded=0 AND has_loan=0 $ca),0)
          +COALESCE((SELECT SUM(total_amount) FROM sales_retail WHERE sale_date BETWEEN '$month_start' AND '$month_end' AND refunded=0 AND has_loan=0 $ca),0)
          +COALESCE((SELECT SUM(my_revenue)   FROM sales_external WHERE sale_date BETWEEN '$month_start' AND '$month_end' AND refunded=0 $ca),0) v
"))['v'] ?? 0);

$yesterday_t = (float)(mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COALESCE((SELECT SUM(total_amount) FROM sales_bulk   WHERE sale_date='$yesterday' AND refunded=0 AND has_loan=0 $ca),0)
          +COALESCE((SELECT SUM(total_amount) FROM sales_retail WHERE sale_date='$yesterday' AND refunded=0 AND has_loan=0 $ca),0) v
"))['v'] ?? 0);

// ── Payment breakdown ─────────────────────────────────────────────────────────
$pay = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COALESCE(SUM(cash_amount),0) cash, COALESCE(SUM(momo_amount),0) momo, COALESCE(SUM(loan_amount),0) loan FROM (
        SELECT cash_amount, momo_amount, loan_amount FROM sales_bulk     WHERE sale_date='$today' AND refunded=0 $ca
        UNION ALL
        SELECT cash_amount, momo_amount, loan_amount FROM sales_retail   WHERE sale_date='$today' AND refunded=0 $ca
        UNION ALL
        SELECT cash_amount, momo_amount, loan_amount FROM sales_external WHERE sale_date='$today' AND refunded=0 $ca
    ) x
"));
$today_cash = (float)$pay['cash'];
$today_momo = (float)$pay['momo'];
$today_loan = (float)$pay['loan'];

// ── Profit ────────────────────────────────────────────────────────────────────
// cost_total is a snapshot taken at sale time (see bulkSaleCost()/retailSaleCost()
// in functions.php) — no correlated purchase lookup needed here anymore.
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

// External my_revenue is pure profit (no COGS), already in today_t
$today_profit = $today_t - $today_bulk_cost - $today_retail_cost;

$week_profit = (float)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(total_profit),0) v FROM weekly_revenue WHERE week_start_date='$week_start' $ca"))['v'] ?? 0);

// ── Chart data (current week: Sunday – Saturday) ──────────────────────────────
$chart_labels = [];
$chart_revenue = [];
$chart_cost = [];
$chart_profit = [];

// Calculate week range (Sunday to Saturday)
$today_weekday = date('w'); // 0 = Sunday, 6 = Saturday

if ($today_weekday == 0) {
    // Today is Sunday - current week is today to next Saturday
    $week_sun_chart = date('Y-m-d');
    $week_sat_chart = date('Y-m-d', strtotime('+6 days'));
} else {
    // Today is Monday-Saturday - current week is last Sunday to this Saturday
    $week_sun_chart = date('Y-m-d', strtotime('last sunday'));
    $week_sat_chart = date('Y-m-d', strtotime('saturday this week'));
}

// Escape values for SQL safety
$week_sun_chart_escaped = mysqli_real_escape_string($conn, $week_sun_chart);
$week_sat_chart_escaped = mysqli_real_escape_string($conn, $week_sat_chart);

// Build the query
$chart_query = "
    SELECT 
        dates.date,
        COALESCE(bulk.revenue, 0) + COALESCE(retail.revenue, 0) + COALESCE(ext.revenue, 0) AS total_revenue,
        COALESCE(bulk.cost, 0) + COALESCE(retail.cost, 0) AS total_cost,
        (COALESCE(bulk.revenue, 0) + COALESCE(retail.revenue, 0) + COALESCE(ext.revenue, 0))
        - (COALESCE(bulk.cost, 0) + COALESCE(retail.cost, 0)) AS profit
    FROM (
        SELECT DATE('$week_sun_chart_escaped') + INTERVAL seq DAY AS date
        FROM (
            SELECT 0 seq UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 
            UNION SELECT 4 UNION SELECT 5 UNION SELECT 6
        ) x
    ) dates
    LEFT JOIN (
        SELECT
            sale_date,
            SUM(total_amount) AS revenue,
            SUM(cost_total) AS cost
        FROM sales_bulk sb
        WHERE sb.refunded = 0
          AND sb.has_loan = 0
          " . cidAndFor('sb') . "
        GROUP BY sale_date
    ) AS bulk ON bulk.sale_date = dates.date
    LEFT JOIN (
        SELECT
            sale_date,
            SUM(total_amount) AS revenue,
            SUM(cost_total) AS cost
        FROM sales_retail sr
        WHERE sr.refunded = 0
          AND sr.has_loan = 0
          " . cidAndFor('sr') . "
        GROUP BY sale_date
    ) AS retail ON retail.sale_date = dates.date
    LEFT JOIN (
        SELECT 
            sale_date, 
            SUM(my_revenue) AS revenue
        FROM sales_external se
        WHERE se.refunded = 0 
        " . cidAndFor('se') . "
        GROUP BY sale_date
    ) AS ext ON ext.sale_date = dates.date
    ORDER BY dates.date ASC
";

// Execute query with error handling
$chart_q = mysqli_query($conn, $chart_query);

if (!$chart_q) {
    // Log error and handle gracefully
    error_log("Chart query failed: " . mysqli_error($conn));
    // You might want to set default values or show an error message
    $chart_labels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    $chart_revenue = [0, 0, 0, 0, 0, 0, 0];
    $chart_cost = [0, 0, 0, 0, 0, 0, 0];
    $chart_profit = [0, 0, 0, 0, 0, 0, 0];
} else {
    // Process results
    while ($r = mysqli_fetch_assoc($chart_q)) {
        $chart_labels[] = date('D', strtotime($r['date'])); // Mon, Tue, Wed, etc.
        $chart_revenue[] = (float)$r['total_revenue'];
        $chart_cost[] = (float)$r['total_cost'];
        $chart_profit[] = (float)$r['profit'];
    }
    
    // Ensure we have exactly 7 days of data (fill missing if needed)
    while (count($chart_labels) < 7) {
        $chart_labels[] = date('D', strtotime('+' . count($chart_labels) . ' days', strtotime($week_sun_chart)));
        $chart_revenue[] = 0;
        $chart_cost[] = 0;
        $chart_profit[] = 0;
    }
}

// Optional: Debug info (remove in production)
/*
echo "Week range: $week_sun_chart to $week_sat_chart<br>";
echo "Labels: " . implode(', ', $chart_labels) . "<br>";
echo "Revenue: " . implode(', ', $chart_revenue) . "<br>";
echo "Cost: " . implode(', ', $chart_cost) . "<br>";
echo "Profit: " . implode(', ', $chart_profit) . "<br>";
*/

// ── Low stock ─────────────────────────────────────────────────────────────────
$low_stock = [];
$lsq = mysqli_query($conn, "SELECT p.name, s.quantity, p.reorder_level FROM stock s JOIN products p ON s.product_id=p.id WHERE s.quantity<=p.reorder_level " . cidAndFor('s') . " ORDER BY s.quantity ASC LIMIT 5");
while ($r = mysqli_fetch_assoc($lsq)) $low_stock[] = $r;

$retail_empty = (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM retail_stock WHERE pieces_quantity=0 $ca"))['c'] ?? 0);

// ── Movements & suppliers ─────────────────────────────────────────────────────
$mov = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) cnt, COALESCE(SUM(pieces_moved),0) pcs FROM stock_movements WHERE moved_date BETWEEN '$week_start' AND '$week_end' $ca"));
$total_suppliers = (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM suppliers WHERE 1=1 $ca"))['c'] ?? 0);
$outstanding_loans = (float)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(amount),0) v FROM loans WHERE 1=1 $ca"))['v'] ?? 0);

// ── Users for filter ──────────────────────────────────────────────────────────
$users = [];
$uq = mysqli_query($conn, "SELECT id, full_name FROM users WHERE 1=1 $ca ORDER BY full_name ASC");
while ($u = mysqli_fetch_assoc($uq)) $users[] = ['id' => (int)$u['id'], 'name' => $u['full_name']];

// ── Top products ──────────────────────────────────────────────────────────────
$top_products = [];
$tpq = mysqli_query($conn, "
    SELECT p.name, p.category,
        COALESCE(SUM(sb.quantity),0) bulk_qty,
        COALESCE(SUM(sr.pieces_sold),0) retail_qty,
        COALESCE(SUM(sb.total_amount),0)+COALESCE(SUM(sr.total_amount),0) revenue
    FROM products p
    LEFT JOIN sales_bulk sb ON p.id=sb.product_id " . cidAndFor('sb') . "
    LEFT JOIN sales_retail sr ON p.id=sr.product_id " . cidAndFor('sr') . "
    GROUP BY p.id ORDER BY revenue DESC LIMIT 5
");
while ($r = mysqli_fetch_assoc($tpq)) $top_products[] = $r;

// ── Response ──────────────────────────────────────────────────────────────────
echo json_encode([
    'total_products'   => $total_products,
    'sell_wh'          => $has_financials ? $sell_wh          : null,
    'sell_rt'          => $has_financials ? $sell_rt          : null,
    'sell_total'       => $has_financials ? ($sell_wh + $sell_rt) : null,
    'cost_wh'          => $has_financials ? $cost_wh          : null,
    'cost_rt'          => $has_financials ? $cost_rt          : null,
    'cost_total'       => $has_financials ? ($cost_wh + $cost_rt) : null,
    'cache_updated'    => $has_financials ? ($sv['cache_updated'] ?? null) : null,
    'rt_pcs'           => $rt_pcs,
    'today_t'          => $today_t,
    'today_bulk'       => $today_bulk,
    'today_rt'         => $today_rt,
    'today_cash'       => $today_cash,
    'today_momo'       => $today_momo,
    'today_loan'       => $today_loan,
    'today_profit'     => $has_financials ? $today_profit     : null,
    'yesterday_t'      => $yesterday_t,
    'week_sales'       => $week_sales,
    'week_profit'      => $has_financials ? $week_profit      : null,
    'month_sales'      => $month_sales,
    'outstanding'      => $outstanding_loans,
    'total_suppliers'  => $total_suppliers,
    'mov_count'        => (int)$mov['cnt'],
    'mov_pieces'       => (int)$mov['pcs'],
    'chart_labels'     => $chart_labels,
    'chart_revenue'    => $chart_revenue,
    'chart_cost'       => $has_financials ? $chart_cost       : null,
    'chart_profit'     => $has_financials ? $chart_profit     : null,
    'low_stock'        => $low_stock,
    'retail_empty'     => $retail_empty,
    'users'            => $users,
    'top_products'     => $top_products,
    'today'            => $today,
    'user_id'          => (int)$_SESSION['user_id'],
    'role'             => $_SESSION['role'],
    'has_financials'   => $has_financials,
]);
