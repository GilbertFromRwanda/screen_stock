<?php
require_once 'config.php';
if (!isLoggedIn()) { http_response_code(403); exit; }
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
    SELECT COALESCE((SELECT SUM(total_amount) FROM sales_bulk   WHERE sale_date='$yesterday' AND refunded=0 $ca),0)
          +COALESCE((SELECT SUM(total_amount) FROM sales_retail WHERE sale_date='$yesterday' AND refunded=0 $ca),0) v
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
$today_profit = (float)(mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT
      COALESCE((SELECT SUM(sb.total_amount-(pu.cost_price*sb.quantity/COALESCE(NULLIF(sb.level_divisor,0),1)))
                FROM sales_bulk sb JOIN purchases pu ON pu.product_id=sb.product_id
                WHERE sb.sale_date='$today' AND sb.refunded=0 AND sb.has_loan=0
                AND pu.id=(SELECT id FROM purchases p2 WHERE p2.product_id=sb.product_id AND p2.purchase_date<=sb.sale_date ORDER BY p2.purchase_date DESC LIMIT 1)),0)
     +COALESCE((SELECT SUM(sr.total_amount-((pu.cost_price/NULLIF(s.pieces_per_package,1))*sr.pieces_sold))
                FROM sales_retail sr JOIN purchases pu ON pu.product_id=sr.product_id LEFT JOIN stock s ON sr.product_id=s.product_id
                WHERE sr.sale_date='$today' AND sr.refunded=0 AND sr.has_loan=0
                AND pu.id=(SELECT id FROM purchases p2 WHERE p2.product_id=sr.product_id AND p2.purchase_date<=sr.sale_date ORDER BY p2.purchase_date DESC LIMIT 1)),0)
     +COALESCE((SELECT SUM(my_revenue) FROM sales_external WHERE sale_date='$today' AND refunded=0 $ca),0) v
"))['v'] ?? 0);

$week_profit = (float)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(total_profit),0) v FROM weekly_revenue WHERE week_start_date='$week_start' $ca"))['v'] ?? 0);

// ── Chart data (current week: Sunday – Saturday) ──────────────────────────────
$chart_labels = []; $chart_revenue = []; $chart_cost = []; $chart_profit = [];

$week_sun_chart = date('Y-m-d', strtotime('sunday last week'));
$week_sat_chart = date('Y-m-d', strtotime('saturday this week'));
if (date('w') == 0) {
    $week_sun_chart = date('Y-m-d');
    $week_sat_chart = date('Y-m-d', strtotime('saturday next week'));
}

$chart_q = mysqli_query($conn, "
    SELECT dates.date,
        COALESCE(bulk.revenue, 0) + COALESCE(retail.revenue, 0) AS total_revenue,
        COALESCE(bulk.cost,    0) + COALESCE(retail.cost,    0) AS total_cost,
        (COALESCE(bulk.revenue, 0) + COALESCE(retail.revenue, 0))
        - (COALESCE(bulk.cost, 0) + COALESCE(retail.cost, 0))   AS profit
    FROM (
        SELECT DATE('$week_sun_chart') + INTERVAL seq DAY AS date
        FROM (SELECT 0 seq UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6) x
    ) dates
    LEFT JOIN (
        SELECT sale_date,
            SUM(total_amount) AS revenue,
            SUM(COALESCE((SELECT cost_price FROM purchases pu2
                          WHERE pu2.product_id = sb.product_id " . cidAndFor('pu2') . "
                          ORDER BY purchase_date DESC LIMIT 1), 0)
                * sb.quantity / COALESCE(NULLIF(sb.level_divisor,0),1)) AS cost
        FROM sales_bulk sb
        WHERE sb.refunded=0 AND sb.has_loan=0 " . cidAndFor('sb') . "
        GROUP BY sale_date
    ) AS bulk ON bulk.sale_date = dates.date
    LEFT JOIN (
        SELECT sale_date,
            SUM(total_amount) AS revenue,
            SUM(COALESCE(
                (SELECT pu.cost_price / NULLIF(s.pieces_per_package, 0)
                 FROM purchases pu
                 JOIN stock s ON s.product_id = pu.product_id " . cidAndFor('s') . "
                 WHERE pu.product_id = sr.product_id " . cidAndFor('pu') . "
                 ORDER BY pu.purchase_date DESC LIMIT 1), 0
            ) * sr.pieces_sold) AS cost
        FROM sales_retail sr
        WHERE sr.refunded=0 AND sr.has_loan=0 " . cidAndFor('sr') . "
        GROUP BY sale_date
    ) AS retail ON retail.sale_date = dates.date
    ORDER BY dates.date ASC
");
while ($r = mysqli_fetch_assoc($chart_q)) {
    $chart_labels[]  = date('D', strtotime($r['date']));
    $chart_revenue[] = (float)$r['total_revenue'];
    $chart_cost[]    = (float)$r['total_cost'];
    $chart_profit[]  = (float)$r['profit'];
}

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
    'sell_wh'          => $sell_wh,
    'sell_rt'          => $sell_rt,
    'sell_total'       => $sell_wh + $sell_rt,
    'cost_wh'          => $cost_wh,
    'cost_rt'          => $cost_rt,
    'cost_total'       => $cost_wh + $cost_rt,
    'cache_updated'    => $sv['cache_updated'] ?? null,
    'rt_pcs'           => $rt_pcs,
    'today_t'          => $today_t,
    'today_bulk'       => $today_bulk,
    'today_rt'         => $today_rt,
    'today_cash'       => $today_cash,
    'today_momo'       => $today_momo,
    'today_loan'       => $today_loan,
    'today_profit'     => $today_profit,
    'yesterday_t'      => $yesterday_t,
    'week_sales'       => $week_sales,
    'week_profit'      => $week_profit,
    'month_sales'      => $month_sales,
    'outstanding'      => $outstanding_loans,
    'total_suppliers'  => $total_suppliers,
    'mov_count'        => (int)$mov['cnt'],
    'mov_pieces'       => (int)$mov['pcs'],
    'chart_labels'     => $chart_labels,
    'chart_revenue'    => $chart_revenue,
    'chart_cost'       => $chart_cost,
    'chart_profit'     => $chart_profit,
    'low_stock'        => $low_stock,
    'retail_empty'     => $retail_empty,
    'users'            => $users,
    'top_products'     => $top_products,
    'today'            => $today,
    'user_id'          => (int)$_SESSION['user_id'],
    'role'             => $_SESSION['role'],
]);
