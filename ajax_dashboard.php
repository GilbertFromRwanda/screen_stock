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

$sell_wh  = (float)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT SUM(quantity*package_price) v FROM stock WHERE 1=1 $ca"))['v'] ?? 0);
$sell_rt  = (float)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT SUM(pieces_quantity*retail_price) v FROM retail_stock WHERE 1=1 $ca"))['v'] ?? 0);
$rt_pcs   = (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT SUM(pieces_quantity) v FROM retail_stock WHERE 1=1 $ca"))['v'] ?? 0);

$cost_wh = (float)(mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COALESCE(SUM(s.quantity * ac.wac),0) v FROM stock s
    JOIN (SELECT product_id, SUM(quantity*cost_price)/NULLIF(SUM(quantity),0) wac
          FROM purchases WHERE cost_price IS NOT NULL " . cidAndFor('purchases') . " GROUP BY product_id) ac
    ON ac.product_id = s.product_id WHERE 1=1 " . cidAndFor('s') . "
"))['v'] ?? 0);

$cost_rt = (float)(mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COALESCE(SUM(rs.pieces_quantity*(ac.wac/NULLIF(st.pieces_per_package,0))),0) v
    FROM retail_stock rs
    JOIN (SELECT product_id, SUM(quantity*cost_price)/NULLIF(SUM(quantity),0) wac
          FROM purchases WHERE cost_price IS NOT NULL " . cidAndFor('purchases') . " GROUP BY product_id) ac
    ON ac.product_id = rs.product_id
    LEFT JOIN stock st ON st.product_id=rs.product_id AND st.company_id=rs.company_id
    WHERE 1=1 " . cidAndFor('rs') . "
"))['v'] ?? 0);

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

// ── Chart data ────────────────────────────────────────────────────────────────
$chart_labels = []; $chart_bulk = []; $chart_retail = [];
$cid_val = cid();
$chart_cond_b = $cid_val !== null ? "AND sb.company_id=$cid_val" : "";
$chart_cond_r = $cid_val !== null ? "AND sr.company_id=$cid_val" : "";
$chart_q = mysqli_query($conn, "
    SELECT dates.date,
        COALESCE(SUM(CASE WHEN sb.id IS NOT NULL THEN sb.total_amount ELSE 0 END),0) bulk,
        COALESCE(SUM(CASE WHEN sr.id IS NOT NULL THEN sr.total_amount ELSE 0 END),0) retail
    FROM (SELECT CURDATE()-INTERVAL n DAY date FROM (SELECT 0 n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6) x) dates
    LEFT JOIN sales_bulk   sb ON sb.sale_date=dates.date AND sb.refunded=0 $chart_cond_b
    LEFT JOIN sales_retail sr ON sr.sale_date=dates.date AND sr.refunded=0 $chart_cond_r
    GROUP BY dates.date ORDER BY dates.date
");
while ($r = mysqli_fetch_assoc($chart_q)) {
    $chart_labels[] = date('D', strtotime($r['date']));
    $chart_bulk[]   = (float)$r['bulk'];
    $chart_retail[] = (float)$r['retail'];
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

// ── Performance metrics ───────────────────────────────────────────────────────
$avg_daily = (float)(mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT AVG(daily_total) v FROM (
        SELECT sale_date, SUM(total_amount) daily_total FROM (
            SELECT sale_date, total_amount FROM sales_bulk   WHERE 1=1 $ca
            UNION ALL SELECT sale_date, total_amount FROM sales_retail WHERE 1=1 $ca
        ) s GROUP BY sale_date ORDER BY sale_date DESC LIMIT 30
    ) t
"))['v'] ?? 0);

$best_day_row = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT DAYNAME(sale_date) dn, COUNT(*) c FROM (
        SELECT sale_date FROM sales_bulk   WHERE 1=1 $ca
        UNION ALL SELECT sale_date FROM sales_retail WHERE 1=1 $ca
    ) s GROUP BY DAYOFWEEK(sale_date) ORDER BY c DESC LIMIT 1
"));
$best_day = $best_day_row['dn'] ?? 'N/A';

$total_trans = (int)(mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT (SELECT COUNT(*) FROM sales_bulk WHERE 1=1 $ca)+(SELECT COUNT(*) FROM sales_retail WHERE 1=1 $ca) v
"))['v'] ?? 0);

$trn = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COALESCE(SUM(sb.quantity),0) sold, COALESCE((SELECT SUM(quantity) FROM stock WHERE 1=1 $ca),1) stk
    FROM products p
    LEFT JOIN sales_bulk sb ON p.id=sb.product_id " . cidAndFor('sb') . "
    LEFT JOIN sales_retail sr ON p.id=sr.product_id " . cidAndFor('sr') . "
"));
$stock_turnover = $trn['stk'] > 0 ? round(($trn['sold'] / $trn['stk']) * 100) : 0;

$bulk_all   = (float)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(total_amount),0) v FROM sales_bulk   WHERE 1=1 $ca"))['v'] ?? 0);
$retail_all = (float)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(total_amount),0) v FROM sales_retail WHERE 1=1 $ca"))['v'] ?? 0);
$total_all  = $bulk_all + $retail_all;

$avg_trans_row = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT AVG(amount) v FROM (
        SELECT total_amount amount FROM sales_bulk   WHERE 1=1 $ca
        UNION ALL SELECT total_amount FROM sales_retail WHERE 1=1 $ca
    ) s
"));
$avg_trans = (float)($avg_trans_row['v'] ?? 0);

$peak_row = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT HOUR(created_at) h, COUNT(*) c FROM (
        SELECT created_at FROM sales_bulk   WHERE 1=1 $ca
        UNION ALL SELECT created_at FROM sales_retail WHERE 1=1 $ca
    ) s GROUP BY h ORDER BY c DESC LIMIT 1
"));
$peak_hour = $peak_row ? date('g A', strtotime($peak_row['h'] . ':00')) : 'N/A';

$avg_items_row = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT AVG(items) v FROM (
        SELECT quantity AS items FROM sales_bulk   WHERE 1=1 $ca
        UNION ALL SELECT pieces_sold FROM sales_retail WHERE 1=1 $ca
    ) s
"));
$avg_items = round((float)($avg_items_row['v'] ?? 0), 1);

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
    'chart_bulk'       => $chart_bulk,
    'chart_retail'     => $chart_retail,
    'low_stock'        => $low_stock,
    'retail_empty'     => $retail_empty,
    'avg_daily'        => $avg_daily,
    'best_day'         => $best_day,
    'total_trans'      => $total_trans,
    'stock_turnover'   => $stock_turnover,
    'bulk_pct'         => $total_all > 0 ? round($bulk_all / $total_all * 100) : 0,
    'retail_pct'       => $total_all > 0 ? round($retail_all / $total_all * 100) : 0,
    'avg_trans'        => $avg_trans,
    'peak_hour'        => $peak_hour,
    'avg_items'        => $avg_items,
    'users'            => $users,
    'top_products'     => $top_products,
    'today'            => $today,
    'user_id'          => (int)$_SESSION['user_id'],
    'role'             => $_SESSION['role'],
]);
