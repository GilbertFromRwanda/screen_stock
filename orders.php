<?php
require_once 'config.php';
require_once __DIR__ . '/stock_value.php';
if (!isLoggedIn()) redirect('login.php');
if (!hasPermission('orders')) { $_SESSION['flash_error'] = "You don't have permission to access Orders."; redirect('dashboard.php'); }

global $conn;
$user_id = (int)$_SESSION['user_id'];
$role    = $_SESSION['role'] ?? 'staff';

// ── AJAX: Delete ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_order'])) {
    header('Content-Type: application/json');
    $id     = (int)$_POST['order_id'];
    $reason = mysqli_real_escape_string($conn, trim($_POST['delete_reason'] ?? ''));

    if (empty($reason)) {
        echo json_encode(['success'=>false,'message'=>'A reason is required to delete an order.']);
        exit;
    }

    $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM `orders` WHERE id=$id " . cidAnd()));
    if (!$row) {
        echo json_encode(['success'=>false,'message'=>'Order not found.']);
        exit;
    }

    $order_num = $row['order_number'] ?: "#$id";

    logActivity($conn, $_SESSION['user_id'], 'DELETE', 'orders',
        "Order $order_num permanently deleted. Reason: $reason", $id,
        ['status'=>$row['status'],'total_amount'=>$row['total_amount'],'order_owner'=>$row['order_owner']],
        []
    );

    mysqli_query($conn, "DELETE FROM order_items WHERE order_id=$id");
    mysqli_query($conn, "DELETE FROM `orders` WHERE id=$id");

    echo json_encode(['success'=>true,'message'=>"Order $order_num deleted permanently."]);
    exit;
}

// ── AJAX: Cancel ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_order'])) {
    header('Content-Type: application/json');
    $id     = (int)$_POST['order_id'];
    $reason = mysqli_real_escape_string($conn, trim($_POST['cancel_reason'] ?? ''));
    $row    = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT * FROM `orders` WHERE id=$id AND status='pending'"));
    if (!$row) {
        echo json_encode(['success'=>false,'message'=>'Order not found or already processed.']);
        exit;
    }
    $reason_sql = $reason !== '' ? "'$reason'" : 'NULL';
    mysqli_query($conn,
        "UPDATE `orders` SET status='cancelled', cancel_reason=$reason_sql, cancelled_by=$user_id, updated_at=NOW() WHERE id=$id");
    logActivity($conn,$_SESSION['user_id'],'CANCEL','orders',"Order #$id cancelled",$id,
        ['status'=>'pending'],['status'=>'cancelled','reason'=>$reason]);
    echo json_encode(['success'=>true,'message'=>"Order #$id cancelled.",'reason'=>$reason]);
    exit;
}

// ── AJAX: Approve ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_order'])) {
    header('Content-Type: application/json');

    $order_id   = (int)$_POST['order_id'];
    $appr_cash  = max(0, (float)($_POST['appr_cash']  ?? 0));
    $appr_momo  = max(0, (float)($_POST['appr_momo']  ?? 0));
    $appr_loan  = max(0, (float)($_POST['appr_loan']  ?? 0));
    $appr_bank  = max(0, (float)($_POST['appr_bank']  ?? 0));
    $appr_phone = mysqli_real_escape_string($conn, trim($_POST['appr_phone'] ?? ''));

    $order = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT * FROM `orders` WHERE id=$order_id AND status='pending'"));
    if (!$order) {
        echo json_encode(['success'=>false,'message'=>'Order not found or already processed.']);
        exit;
    }

    $total_amount  = (float)$order['total_amount'];
    $customer_name = mysqli_real_escape_string($conn, $order['order_owner']);
    $phone         = $appr_phone ?: mysqli_real_escape_string($conn, $order['phone']);
    $cash_amount   = (float)$order['prepaid_cash'] + $appr_cash;
    $momo_amount   = (float)$order['prepaid_momo'] + (float)$order['prepaid_bank'] + $appr_momo + $appr_bank;
    $loan_amount   = (float)$order['prepaid_loan'] + $appr_loan;
    $total_pay     = $cash_amount + $momo_amount + $loan_amount;

    if (abs($total_pay - $total_amount) > 1) {
        echo json_encode(['success'=>false,'message'=>
            'Payment (RWF '.number_format($total_pay,0).') must equal order total (RWF '.number_format($total_amount,0).').']);
        exit;
    }
    if ($loan_amount > 0 && !$phone) {
        echo json_encode(['success'=>false,'message'=>'Phone required for loan payment.']);
        exit;
    }

    // Fetch order items (fall back to single-product for old orders)
    $items_q = mysqli_query($conn,
        "SELECT oi.*, p.name AS product_name FROM order_items oi
         JOIN products p ON oi.product_id=p.id WHERE oi.order_id=$order_id");
    $items = [];
    while ($it = mysqli_fetch_assoc($items_q)) $items[] = $it;

    if (empty($items) && (int)$order['product_id'] > 0) {
        $prow = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT name FROM products WHERE id={$order['product_id']}"));
        $items = [[
            'product_id'    => $order['product_id'],
            'quantity'      => $order['quantity'],
            'level_divisor' => max(1,(int)$order['level_divisor']),
            'selling_price' => $order['selling_price'],
            'item_total'    => $order['total_amount'],
            'product_name'  => $prow ? $prow['name'] : '',
            'stock_source'  => 'wh',
        ]];
    }
    if (empty($items)) {
        echo json_encode(['success'=>false,'message'=>'No products found for this order.']);
        exit;
    }

    // Categorize items: fulfillable vs out-of-stock (partial approval allowed)
    $fulfillable  = [];
    $oos_item_ids = [];
    $oos_names    = [];

    foreach ($items as $it) {
        $pid   = (int)$it['product_id'];
        $qty   = (float)$it['quantity'];
        $div   = max(1,(int)$it['level_divisor']);
        $src   = $it['stock_source'] ?? 'wh';
        $pname = htmlspecialchars($it['product_name'] ?? "Product #$pid");

        if ($src === 'rt') {
            $stk       = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT pieces_quantity FROM retail_stock WHERE product_id=$pid"));
            $has_stock = $stk && $stk['pieces_quantity'] >= $qty;
        } else {
            $pkgs      = (int)ceil($qty / $div);
            $stk       = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT quantity FROM stock WHERE product_id=$pid"));
            $has_stock = $stk && $stk['quantity'] >= $pkgs;
        }

        if ($has_stock) {
            $fulfillable[] = $it;
        } else {
            if (!empty($it['id'])) $oos_item_ids[] = (int)$it['id'];
            $oos_names[] = $pname;
        }
    }

    if (empty($fulfillable)) {
        echo json_encode(['success'=>false,'message'=>
            'All items are out of stock: ' . implode(', ', $oos_names)]);
        exit;
    }

    $loan_date       = date('Y-m-d');
    $ph_lc           = $phone !== '' ? "'$phone'" : 'NULL';
    $n_items         = count($fulfillable);
    $fulfilled_total = (float)array_sum(array_column($fulfillable, 'item_total'));

    mysqli_begin_transaction($conn);
    $ok = true;
    $first_bulk_id      = 0;
    $bulk_ids           = [];
    $affected_pids      = [];
    $fulfilled_item_ids = [];
    $sum_cash = 0.0; $sum_momo = 0.0;

    // Mark out-of-stock items inside the transaction
    if (!empty($oos_item_ids)) {
        $oos_ids_sql = implode(',', $oos_item_ids);
        mysqli_query($conn, "UPDATE order_items SET status='out_of_stock' WHERE id IN ($oos_ids_sql)");
    }

    foreach ($fulfillable as $idx => $item) {
        $pid   = (int)$item['product_id'];
        $qty   = (float)$item['quantity'];
        $div   = max(1,(int)$item['level_divisor']);
        $prce  = (float)$item['selling_price'];
        $itot  = (float)$item['item_total'];
        $pkgs  = (int)ceil($qty / $div);
        $isLast= $idx === $n_items - 1;

        // Proportional payment split across fulfilled items only
        $ratio = $fulfilled_total > 0 ? $itot / $fulfilled_total : 1.0 / $n_items;
        if ($isLast) {
            $i_cash = round($cash_amount - $sum_cash, 2);
            $i_momo = round($momo_amount - $sum_momo, 2);
        } else {
            $i_cash = round($cash_amount * $ratio, 2); $sum_cash += $i_cash;
            $i_momo = round($momo_amount * $ratio, 2); $sum_momo += $i_momo;
        }
        // Loan tracked once via the loan record; first item carries it in sales_bulk
        $i_loan     = $idx === 0 ? $loan_amount : 0;
        $i_has_loan = $i_loan > 0 ? 1 : 0;

        $src = $item['stock_source'] ?? 'wh';

        if ($src === 'rt') {
            // ── Retail item ───────────────────────────────────────────────────
            $pcs = (int)round($qty);

            // Deduct pieces from retail_stock
            if ($ok) $ok = (bool)mysqli_query($conn,
                "UPDATE retail_stock SET pieces_quantity=pieces_quantity-$pcs WHERE product_id=$pid");

            // Record in sales_retail
            if ($ok) {
                $pay_method = ($i_cash>0 && $i_momo==0 && $i_loan==0) ? 'Cash'
                            : (($i_momo>0 && $i_cash==0 && $i_loan==0) ? 'Momo'
                            : (($i_loan>0 && $i_cash==0 && $i_momo==0) ? 'Loan'
                            : 'Mixed'));
                $ok = (bool)mysqli_query($conn, "INSERT INTO sales_retail
                    (company_id,product_id,pieces_sold,retail_price,total_amount,sale_date,
                     customer_name,sold_by,payment_method,cash_amount,momo_amount,loan_amount,has_loan,amount)
                    VALUES (" . cidSql() . ",$pid,$pcs,$prce,$itot,CURDATE(),
                            '$customer_name',$user_id,'$pay_method',$i_cash,$i_momo,$i_loan,$i_has_loan,$i_loan)");
            }
        } else {
            // ── Warehouse item ────────────────────────────────────────────────
            $ok = (bool)mysqli_query($conn, "INSERT INTO sales_bulk
                (company_id,product_id,quantity,level_divisor,package_price,total_amount,sale_date,
                 customer_name,cash_amount,momo_amount,loan_amount,has_loan,amount,sold_by)
                VALUES (" . cidSql() . ",$pid,$qty,$div,$prce,$itot,CURDATE(),
                        '$customer_name',$i_cash,$i_momo,$i_loan,$i_has_loan,$i_loan,$user_id)");
            if (!$ok) break;

            $bulk_id = (int)mysqli_insert_id($conn);
            $bulk_ids[] = $bulk_id;
            if ($idx === 0 || $first_bulk_id === 0) $first_bulk_id = $bulk_id;

            // Deduct packages from warehouse stock
            if ($ok) $ok = (bool)mysqli_query($conn,
                "UPDATE stock SET quantity=quantity-$pkgs WHERE product_id=$pid");

            // Leftover pieces from partial packages go to retail_stock
            if ($ok && $div > 1) {
                $stk2 = mysqli_fetch_assoc(mysqli_query($conn,
                    "SELECT pieces_per_package,retail_price FROM stock WHERE product_id=$pid"));
                if ($stk2) {
                    $ppp      = max(1,(int)$stk2['pieces_per_package']);
                    $leftover = $pkgs * $ppp - (int)round($qty * $ppp / $div);
                    if ($leftover > 0) {
                        $has_rs = mysqli_num_rows(mysqli_query($conn,
                            "SELECT id FROM retail_stock WHERE product_id=$pid")) > 0;
                        if ($has_rs) {
                            mysqli_query($conn,
                                "UPDATE retail_stock SET pieces_quantity=pieces_quantity+$leftover WHERE product_id=$pid");
                        } else {
                            $rp = (float)$stk2['retail_price'];
                            mysqli_query($conn,
                                "INSERT INTO retail_stock(company_id,product_id,pieces_quantity,retail_price) VALUES(" . cidSql() . ",$pid,$leftover,$rp)");
                        }
                    }
                }
            }
        }

        $affected_pids[] = $pid;
        if ($ok) $fulfilled_item_ids[] = (int)$item['id'];
        if (!$ok) break;
    }

    // Mark fulfilled items
    if ($ok && !empty($fulfilled_item_ids)) {
        $f_ids_sql = implode(',', $fulfilled_item_ids);
        $ok = (bool)mysqli_query($conn,
            "UPDATE order_items SET status='fulfilled' WHERE id IN ($f_ids_sql)");
    }

    // One loan record for the whole order (attached to first fulfilled item)
    if ($ok && $loan_amount > 0) {
        $first = $fulfillable[0];
        $f_pid = (int)$first['product_id'];
        $f_qty = (float)$first['quantity'];
        $ok = (bool)mysqli_query($conn,
            "INSERT INTO loans(product_id,qty,amount,client,phone,loan_date,given_by,bulk_id)
             VALUES($f_pid,$f_qty,$loan_amount,'$customer_name','$phone','$loan_date',$user_id,$first_bulk_id)");
        if ($ok) mysqli_query($conn,
            "INSERT INTO loan_clients(name,phone,total_loans,paid_amount,unpaid_amount)
             VALUES('$customer_name',$ph_lc,1,0,$loan_amount)
             ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),total_loans=total_loans+1,unpaid_amount=unpaid_amount+$loan_amount");
    }

    $refund_amt = round($total_amount - $fulfilled_total, 2);
    if ($ok) $ok = (bool)mysqli_query($conn,
        "UPDATE `orders` SET status='approved',approved_by=$user_id,sale_id=$first_bulk_id,
         refund_amount=$refund_amt,updated_at=NOW()
         WHERE id=$order_id");

    if ($ok) {
        mysqli_commit($conn);
        foreach (array_unique($affected_pids) as $pid) recalcStockValue($conn, $pid);
        $n_sales   = count($bulk_ids);
        $n_rt      = count(array_filter($fulfillable, fn($it) => ($it['stock_source'] ?? 'wh') === 'rt'));
        if ($n_sales === 0) {
            $sales_str = $n_rt . ' Retail Sale' . ($n_rt !== 1 ? 's' : '');
        } elseif ($n_sales === 1) {
            $sales_str = "Bulk Sale #$first_bulk_id" . ($n_rt > 0 ? " + $n_rt Retail" : '');
        } else {
            $sales_str = "$n_sales Bulk Sales" . ($n_rt > 0 ? " + $n_rt Retail" : '');
        }
        $order_num = $order['order_number'] ?: "#$order_id";
        $msg       = "Order $order_num approved — $sales_str created.";
        if (!empty($oos_names)) {
            $msg .= ' Out of stock (skipped): ' . implode(', ', $oos_names) . '.';
        }
        echo json_encode(['success'=>true,
            'message'   => $msg,
            'sale_id'   => $first_bulk_id,
            'order_id'  => $order_id,
            'order_num' => $order_num,
            'oos_items' => $oos_names]);
    } else {
        mysqli_rollback($conn);
        echo json_encode(['success'=>false,'message'=>'Approval failed: '.mysqli_error($conn)]);
    }
    exit;
}

// ── Page load ─────────────────────────────────────────────────────────────────
if (isset($_SESSION['flash_success'])) { $success = $_SESSION['flash_success']; unset($_SESSION['flash_success']); }
if (isset($_SESSION['flash_error']))   { $error   = $_SESSION['flash_error'];   unset($_SESSION['flash_error']); }

$status_filter = in_array($_GET['status'] ?? '', ['pending','approved','cancelled'])
    ? $_GET['status'] : '';
$date_from = isset($_GET['date_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_from'])
    ? $_GET['date_from'] : date('Y-m-d');
$date_to = isset($_GET['date_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_to'])
    ? $_GET['date_to'] : date('Y-m-d');

$where = "WHERE DATE(o.created_at) BETWEEN '$date_from' AND '$date_to'";
if ($status_filter) $where .= " AND o.status='$status_filter'";
$where .= ' ' . cidAndFor('o');

$orders_res = mysqli_query($conn, "
    SELECT o.*, p.name AS product_name, p.category,
           u.username   AS created_by_name,
           ua.username  AS approved_by_name,
           uc.username  AS cancelled_by_name,
           oo.location  AS owner_location
    FROM `orders` o
    LEFT JOIN products p       ON o.product_id     = p.id
    LEFT JOIN users u          ON o.created_by     = u.id
    LEFT JOIN users ua         ON o.approved_by    = ua.id
    LEFT JOIN users uc         ON o.cancelled_by   = uc.id
    LEFT JOIN order_owners oo  ON o.order_owner_id = oo.id
    $where
    ORDER BY FIELD(o.status,'pending','approved','cancelled'), o.created_at DESC
");

// Fetch all orders
$all_orders = [];
$order_ids  = [];
while ($o = mysqli_fetch_assoc($orders_res)) {
    $all_orders[] = $o;
    $order_ids[]  = (int)$o['id'];
}

// Fetch all order_items in one query
$item_data = [];
if (!empty($order_ids)) {
    $ids_str = implode(',', $order_ids);
    $ir = mysqli_query($conn,
        "SELECT oi.*, p.name AS product_name, p.category
         FROM order_items oi
         JOIN products p ON oi.product_id = p.id
         WHERE oi.order_id IN ($ids_str)
         ORDER BY oi.order_id, oi.id");
    while ($it = mysqli_fetch_assoc($ir)) {
        $item_data[(int)$it['order_id']][] = $it;
    }
}

$stats = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT
        SUM(status='pending')   AS cnt_pending,
        SUM(status='approved')  AS cnt_approved,
        SUM(status='cancelled') AS cnt_cancelled,
        COALESCE(SUM(CASE WHEN status='pending' THEN total_amount  ELSE 0 END),0) AS val_pending,
        COALESCE(SUM(CASE WHEN status='pending' THEN total_prepaid ELSE 0 END),0) AS val_prepaid
    FROM `orders`
    " . cidWhere() . "
"));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Orders – Smart Stock</title>
<link rel="stylesheet" href="css/style.css">
<link rel="stylesheet" href="css/all.min.css">
<style>
.badge { display:inline-block; padding:3px 10px; border-radius:20px; font-size:12px; font-weight:700; }
.badge-pending   { background:#fef9c3; color:#854d0e; }
.badge-approved  { background:#dcfce7; color:#166534; }
.badge-cancelled { background:#fee2e2; color:#991b1b; }

.orders-stats { display:flex; gap:16px; flex-wrap:wrap; margin-bottom:24px; }
.ostat { flex:1; min-width:150px; background:var(--white); border-radius:var(--radius-lg);
         box-shadow:var(--shadow-sm); padding:16px 20px; }
.ostat-val { font-size:22px; font-weight:800; color:var(--primary); margin-bottom:2px; }
.ostat-lbl { font-size:12px; color:var(--secondary); }

.filter-bar { display:flex; gap:10px; align-items:center; margin-bottom:18px; }
.filter-bar select { padding:7px 12px; border:1px solid var(--gray-300);
    border-radius:var(--radius); font-size:13px; background:var(--white); }

/* Approve modal */
.modal { display:none; position:fixed; inset:0; background:rgba(0,0,0,.45);
         z-index:1000; align-items:center; justify-content:center; }
.modal.open { display:flex; }
.modal-box { background:var(--white); border-radius:var(--radius-lg); padding:28px 30px;
             width:100%; max-width:540px; max-height:90vh; overflow-y:auto;
             box-shadow:0 8px 32px rgba(0,0,0,.2); position:relative; }
.modal-title { font-size:17px; font-weight:700; margin-bottom:18px; }
.modal-close { position:absolute; top:14px; right:16px; background:none; border:none;
               font-size:20px; cursor:pointer; color:var(--secondary); }
.modal-close:hover { color:var(--dark); }

.order-detail-box { background:var(--gray-50); border-radius:var(--radius);
                    padding:14px 16px; margin-bottom:16px; font-size:13px; }
.order-detail-box .dr { display:flex; justify-content:space-between; align-items:flex-start;
                         padding:5px 0; border-bottom:1px solid var(--gray-200); gap:8px; }
.order-detail-box .dr:last-child { border:none; }

.items-tbl { width:100%; border-collapse:collapse; font-size:13px; margin-bottom:2px; }
.items-tbl th { font-size:11px; font-weight:700; color:var(--secondary);
                text-transform:uppercase; padding:4px 0; border-bottom:1px solid var(--gray-200); }
.items-tbl td { padding:5px 0; vertical-align:top; }
.items-tbl .num { text-align:right; font-weight:700; }

.pay-box { border:1px solid var(--gray-300); border-radius:var(--radius); overflow:hidden; }
.pay-row { display:flex; align-items:center; padding:8px 14px; gap:12px; border-bottom:1px solid var(--gray-100); }
.pay-row:last-child { border-bottom:none; }
.pay-lbl { width:52px; font-size:13px; font-weight:600; flex-shrink:0; color:var(--secondary); }
.pay-row input { flex:1; padding:7px 10px; border:1px solid var(--gray-300); border-radius:var(--radius); font-size:14px; }
.pay-remaining { display:flex; justify-content:space-between; align-items:center;
                 padding:9px 14px; font-weight:700; font-size:13px; background:var(--gray-50); }
.pay-remaining.valid   { background:#ecfdf5; color:#059669; }
.pay-remaining.invalid { background:#fef2f2; color:#dc2626; }
.section-lbl { font-size:12px; font-weight:700; color:var(--secondary);
               text-transform:uppercase; letter-spacing:.5px; margin:16px 0 8px; }
.pay-shortcuts { display:flex; flex-direction:column; gap:6px; margin-bottom:12px; }
.pay-shortcut-lbl {
    display:inline-flex; align-items:center; gap:10px; cursor:pointer;
    padding:8px 12px; border:1.5px solid var(--gray-300);
    border-radius:var(--radius); background:var(--gray-50);
    transition:border-color .15s, background .15s;
}
.pay-shortcut-lbl:has(input:checked) { border-color:var(--primary); background:#eff6ff; }
.pay-shortcut-lbl input[type="checkbox"] { width:16px; height:16px; cursor:pointer; accent-color:var(--primary); flex-shrink:0; }
.pay-shortcut-name { font-weight:700; font-size:13px; color:var(--dark); }
.pay-shortcut-desc { font-size:11px; color:var(--secondary); }

.tbl-num { font-weight:700; color:var(--primary); }
.col-sub { font-size:12px; color:var(--secondary); }
.remaining-pill { display:inline-block; padding:2px 8px; border-radius:12px; font-size:11px; font-weight:700; }
.rp-zero { background:#dcfce7; color:#166534; }
.rp-part { background:#fef9c3; color:#854d0e; }


#orderToast { display:none; position:fixed; bottom:24px; right:24px;
              padding:13px 20px; border-radius:8px; font-size:14px; font-weight:600;
              z-index:9999; box-shadow:0 4px 16px rgba(0,0,0,.15); max-width:360px; }
#orderToast.show { display:block; }
#orderToast.ok  { background:#ecfdf5; color:#059669; border:1px solid #a7f3d0; }
#orderToast.err { background:#fef2f2; color:#dc2626; border:1px solid #fca5a5; }
</style>
</head>
<body>
<div class="dashboard-container">
<?php include 'sidebar.php'; ?>
<div class="main-content">

<?php if (isset($success)): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>
<?php if (isset($error)): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:20px;">
    <div>
        <h1 style="margin:0;font-size:22px;">Orders</h1>
        <p style="margin:4px 0 0;font-size:13px;color:var(--secondary);">Customer orders · approve to create bulk sale(s)</p>
    </div>
    <a href="order_new.php" class="btn btn-primary">+ New Order</a>
</div>

<!-- Stats -->
<div class="orders-stats">
    <div class="ostat">
        <div class="ostat-val" id="stat_pending"><?php echo (int)$stats['cnt_pending']; ?></div>
        <div class="ostat-lbl">Pending</div>
    </div>
    <div class="ostat">
        <div class="ostat-val" id="stat_val_pending">RWF <?php echo number_format((float)$stats['val_pending'],0); ?></div>
        <div class="ostat-lbl">Pending Value</div>
    </div>
    <div class="ostat">
        <div class="ostat-val" id="stat_prepaid">RWF <?php echo number_format((float)$stats['val_prepaid'],0); ?></div>
        <div class="ostat-lbl">Prepaid Collected</div>
    </div>
    <div class="ostat">
        <div class="ostat-val" id="stat_approved"><?php echo (int)$stats['cnt_approved']; ?></div>
        <div class="ostat-lbl">Approved</div>
    </div>
    <div class="ostat">
        <div class="ostat-val" id="stat_cancelled"><?php echo (int)$stats['cnt_cancelled']; ?></div>
        <div class="ostat-lbl">Cancelled</div>
    </div>
</div>

<!-- Filter -->
<div class="filter-bar" style="flex-wrap:wrap;">
    <form method="GET" id="filterForm" style="display:contents;">
        <input type="text" id="order_search" placeholder="Search orders…"
               style="padding:7px 12px;border:1px solid var(--gray-300);border-radius:var(--radius);font-size:13px;min-width:180px;"
               oninput="filterOrders()">
        <select name="status" onchange="this.form.submit()">
            <option value="">All statuses</option>
            <option value="pending"   <?php if($status_filter==='pending')   echo 'selected'; ?>>Pending</option>
            <option value="approved"  <?php if($status_filter==='approved')  echo 'selected'; ?>>Approved</option>
            <option value="cancelled" <?php if($status_filter==='cancelled') echo 'selected'; ?>>Cancelled</option>
        </select>
        <label style="font-size:13px;color:var(--secondary);margin:0;">From</label>
        <input type="date" name="date_from" value="<?php echo $date_from; ?>" onchange="this.form.submit()"
               style="padding:7px 10px;border:1px solid var(--gray-300);border-radius:var(--radius);font-size:13px;">
        <label style="font-size:13px;color:var(--secondary);margin:0;">To</label>
        <input type="date" name="date_to" value="<?php echo $date_to; ?>" onchange="this.form.submit()"
               style="padding:7px 10px;border:1px solid var(--gray-300);border-radius:var(--radius);font-size:13px;">
        <a href="orders.php" style="font-size:13px;color:var(--secondary);text-decoration:none;padding:7px 4px;" title="Reset filters">&#x21BA; Reset</a>
    </form>
</div>

<!-- Table -->
<div class="table-responsive">
<table class="table">
<thead>
<tr>
     <th>Actions</th>
    <th>Order #</th>
    <th>Items</th>
    <th>Total</th>
    <th>Prepaid</th>
    <th>Remaining</th>
    <th>Owner</th>
    <th>Status</th>
    <th>Done By</th>
    <th>Created</th>
   
</tr>
</thead>
<tbody id="orders_tbody">
<?php if (empty($all_orders)): ?>
<tr><td colspan="9" style="text-align:center;color:var(--secondary);padding:32px;">No orders found for this period.</td></tr>
<?php endif; ?>
<?php foreach ($all_orders as $o):
    $o_items   = $item_data[(int)$o['id']] ?? [];
    $remaining = $o['status'] === 'pending'
        ? max(0, (float)$o['total_amount'] - (float)$o['total_prepaid'])
        : 0;
    $oos_total = (float)$o['refund_amount'];
    $isPending = $o['status'] === 'pending';
    $order_num = $o['order_number'] ?: '#'.$o['id'];

    // Build items for modal (new orders: from order_items; old orders: from order row)
    $modal_items = [];
    if (!empty($o_items)) {
        foreach ($o_items as $it) {
            $modal_items[] = [
                'product'       => ($it['category']??'').'-'.($it['product_name']??''),
                'quantity'      => (float)$it['quantity'],
                'selling_price' => (float)$it['selling_price'],
                'item_total'    => (float)$it['item_total'],
                'status'        => $it['status'] ?? 'pending',
            ];
        }
    } elseif ($o['product_name']) {
        $modal_items[] = [
            'product'       => ($o['category']??'').'-'.($o['product_name']??''),
            'quantity'      => (float)$o['quantity'],
            'selling_price' => (float)$o['selling_price'],
            'item_total'    => (float)$o['total_amount'],
            'status'        => '',
        ];
    }

    $data = json_encode([
        'id'            => (int)$o['id'],
        'order_num'     => $order_num,
        'items'         => $modal_items,
        'total_amount'  => (float)$o['total_amount'],
        'order_owner'   => $o['order_owner'],
        'phone'         => $o['phone'],
        'prepaid_cash'  => (float)$o['prepaid_cash'],
        'prepaid_momo'  => (float)$o['prepaid_momo'],
        'prepaid_loan'  => (float)$o['prepaid_loan'],
        'prepaid_bank'  => (float)$o['prepaid_bank'],
        'total_prepaid' => (float)$o['total_prepaid'],
    ], JSON_HEX_QUOT|JSON_HEX_APOS);
    $prod_data = json_encode([
        'order_num' => $order_num,
        'owner'     => $o['order_owner'],
        'items'     => $modal_items,
        'total'     => (float)$o['total_amount'],
    ], JSON_HEX_QUOT|JSON_HEX_APOS);
    $print_data = json_encode([
        'order_num'     => $order_num,
        'created_at'    => date('d M Y', strtotime($o['created_at'])),
        'order_owner'   => $o['order_owner'],
        'phone'         => $o['phone'],
        'location'      => $o['owner_location'] ?? '',
        'items'         => $modal_items,
        'total_amount'  => (float)$o['total_amount'],
        'prepaid_cash'  => (float)$o['prepaid_cash'],
        'prepaid_momo'  => (float)$o['prepaid_momo'],
        'prepaid_bank'  => (float)$o['prepaid_bank'],
        'prepaid_loan'  => (float)$o['prepaid_loan'],
        'total_prepaid' => (float)$o['total_prepaid'],
        'status'        => $o['status'],
    ], JSON_HEX_QUOT|JSON_HEX_APOS);
?>
<tr id="order_row_<?php echo $o['id']; ?>">
     <td class="actions_cell_<?php echo $o['id']; ?>">
        <div class="act-menu-wrap">
            <button class="act-btn" onclick="toggleActMenu(this)" title="Actions">&#8942;</button>
            <div class="act-menu">
                <button class="act-item" onclick='openProductsModal(<?php echo $prod_data; ?>);closeActMenus()'><i class="fas fa-box-open"></i> Products</button>
                <button class="act-item" onclick='printReceipt(<?php echo $print_data; ?>);closeActMenus()'><i class="fas fa-print"></i> Print</button>
                <?php if ($isPending): ?>
                <div class="act-menu-sep"></div>
                <button class="act-item" style="color:#16a34a;" onclick='openApproveModal(<?php echo $data; ?>);closeActMenus()'><i class="fas fa-check"></i> Approve</button>
                <button class="act-item danger" onclick='cancelOrder(<?php echo $o["id"]; ?>,<?php echo json_encode($order_num); ?>);closeActMenus()'><i class="fas fa-times"></i> Cancel</button>
                <?php endif; ?>
                <?php if ($isPending && in_array($role, ['admin','manager','superadmin'])): ?>
                <div class="act-menu-sep"></div>
                <button class="act-item danger" onclick='openDeleteOrder(<?php echo $o["id"]; ?>,<?php echo json_encode($order_num); ?>);closeActMenus()'><i class="fas fa-trash"></i> Delete</button>
                <?php endif; ?>
            </div>
        </div>
    </td>
    <td>
        <span class="tbl-num"><?php echo htmlspecialchars($order_num); ?></span>
        <?php if ($o['order_number']): ?>
        <br><span class="col-sub">#<?php echo $o['id']; ?></span>
        <?php endif; ?>
    </td>
    <td>
        <?php if (!empty($o_items)): ?>
            <?php foreach ($o_items as $it): ?>
            <div style="font-size:13px;line-height:1.6;">
                <strong><?php echo htmlspecialchars($it['product_name']); ?></strong>
                <span class="col-sub">&nbsp;&times;<?php echo number_format((float)$it['quantity'],0); ?></span>
            </div>
            <?php endforeach; ?>
        <?php elseif ($o['product_name']): ?>
            <strong><?php echo htmlspecialchars($o['product_name']); ?></strong>
            <br><span class="col-sub"><?php echo htmlspecialchars($o['category']??''); ?></span>
        <?php else: ?>
            <span class="col-sub">—</span>
        <?php endif; ?>
    </td>
    <td><strong>RWF <?php echo number_format((float)$o['total_amount'],0); ?></strong></td>
    <td>
        RWF <?php echo number_format((float)$o['total_prepaid'],0); ?>
        <?php if ((float)$o['total_prepaid'] > 0): ?>
        <div style="font-size:11px;color:var(--secondary);margin-top:2px;line-height:1.7;">
            <?php if ((float)$o['prepaid_cash']>0): ?>Cash: <?php echo number_format((float)$o['prepaid_cash'],0); ?><br><?php endif; ?>
            <?php if ((float)$o['prepaid_momo']>0): ?>Momo: <?php echo number_format((float)$o['prepaid_momo'],0); ?><br><?php endif; ?>
            <?php if ((float)$o['prepaid_bank']>0): ?>Bank: <?php echo number_format((float)$o['prepaid_bank'],0); ?><br><?php endif; ?>
            <?php if ((float)$o['prepaid_loan']>0): ?>Loan: <?php echo number_format((float)$o['prepaid_loan'],0); ?><?php endif; ?>
        </div>
        <?php endif; ?>
    </td>
    <td>
        <span id="rem_cell_<?php echo $o['id']; ?>" class="remaining-pill <?php echo $remaining<=0 ? 'rp-zero' : 'rp-part'; ?>">
            RWF <?php echo number_format(max(0,$remaining),0); ?>
        </span>
        <?php if ($oos_total > 0): ?>
        <br><span class="remaining-pill" style="background:#fee2e2;color:#991b1b;margin-top:3px;cursor:default;" title="Refund owed for out-of-stock items">
            &#8617; Refund RWF <?php echo number_format($oos_total,0); ?>
        </span>
        <?php endif; ?>
    </td>
    <td>
        <?php echo htmlspecialchars($o['order_owner']); ?>
        <?php if ($o['phone']): ?><br><span class="col-sub"><?php echo htmlspecialchars($o['phone']); ?></span><?php endif; ?>
        <?php if (!empty($o['owner_location'])): ?><br><span class="col-sub">📍 <?php echo htmlspecialchars($o['owner_location']); ?></span><?php endif; ?>
    </td>
    <td class="status_cell_<?php echo $o['id']; ?>">
        <span class="badge badge-<?php echo $o['status']; ?>"><?php echo ucfirst($o['status']); ?></span>
        <?php if ($o['status']==='approved' && $o['sale_id']): ?>
        <br><span class="col-sub">Sale #<?php echo $o['sale_id']; ?></span>
        <?php endif; ?>
        <?php if ($o['status']==='cancelled' && !empty($o['cancel_reason'])): ?>
        <br><span class="col-sub" style="cursor:help;" title="<?php echo htmlspecialchars($o['cancel_reason']); ?>">
            ✕ <?php echo htmlspecialchars(mb_strimwidth($o['cancel_reason'],0,35,'…')); ?>
        </span>
        <?php endif; ?>
        <?php if ($o['note']): ?>
        <br><span class="col-sub" title="<?php echo htmlspecialchars($o['note']); ?>">📝 Note</span>
        <?php endif; ?>
    </td>
    <td>
        <?php
        if ($o['status'] === 'approved') {
            echo htmlspecialchars($o['approved_by_name'] ?? '—');
        } elseif ($o['status'] === 'cancelled') {
            echo htmlspecialchars($o['cancelled_by_name'] ?? '—');
        } else {
            echo htmlspecialchars($o['created_by_name'] ?? '—');
        }
        ?>
    </td>
    <td>
        <?php echo date('d M Y', strtotime($o['created_at'])); ?>
        <br><span class="col-sub"><?php echo htmlspecialchars($o['created_by_name'] ?? '—'); ?></span>
    </td>
   
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

</div><!-- /main-content -->
</div><!-- /dashboard-container -->

<div id="orderToast"></div>

<!-- Cancel modal -->
<div id="cancelModal" class="modal">
<div class="modal-box" style="max-width:420px;">
    <button class="modal-close" onclick="closeModal('cancelModal')">&times;</button>
    <div class="modal-title">Cancel Order <span id="cancel_order_num" style="color:var(--primary);"></span></div>
    <div style="padding:8px 0 16px;">
        <label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px;">
            Reason <span style="font-weight:400;color:var(--secondary);">(optional)</span>
        </label>
        <textarea id="cancel_reason_input" rows="3"
            placeholder="e.g. customer changed mind, out of budget…"
            style="width:100%;padding:9px 12px;border:1px solid var(--gray-300);border-radius:var(--radius);font-size:14px;resize:vertical;box-sizing:border-box;"></textarea>
    </div>
    <div style="display:flex;gap:10px;">
        <button class="btn" style="flex:1;" onclick="closeModal('cancelModal')">Back</button>
        <button class="btn btn-danger" style="flex:1;" id="cancel_submit_btn" onclick="submitCancel()">Confirm Cancel</button>
    </div>
</div>
</div>

<!-- Delete modal -->
<div id="deleteModal" class="modal">
<div class="modal-box" style="max-width:420px;">
    <button class="modal-close" onclick="closeModal('deleteModal')">&times;</button>
    <div class="modal-title">Delete Order <span id="delete_order_num" style="color:var(--danger);"></span></div>
    <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:var(--radius);padding:12px 14px;margin-bottom:16px;font-size:13px;color:#991b1b;">
        &#9888; This permanently deletes the order and all its items. This cannot be undone.
    </div>
    <div style="padding:0 0 16px;">
        <label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px;">
            Reason <span style="color:var(--danger);">*</span>
        </label>
        <textarea id="delete_reason_input" rows="3"
            placeholder="e.g. duplicate entry, created by mistake…"
            style="width:100%;padding:9px 12px;border:1px solid var(--gray-300);border-radius:var(--radius);font-size:14px;resize:vertical;box-sizing:border-box;"
            oninput="document.getElementById('delete_submit_btn').disabled = this.value.trim().length < 3;"></textarea>
    </div>
    <div style="display:flex;gap:10px;">
        <button class="btn" style="flex:1;" onclick="closeModal('deleteModal')">Back</button>
        <button class="btn btn-danger" style="flex:1;" id="delete_submit_btn" onclick="submitDelete()" disabled>Delete Permanently</button>
    </div>
</div>
</div>

<!-- Approve modal -->
<div id="approveModal" class="modal">
<div class="modal-box">
    <button class="modal-close" onclick="closeModal('approveModal')">&times;</button>
    <div class="modal-title">Approve Order <span id="appr_order_num" style="color:var(--primary);"></span></div>

    <div class="order-detail-box">
        <div class="dr" id="appr_items_row">
            <span style="font-weight:600;flex-shrink:0;">Items</span>
            <div style="text-align:right;flex:1;" id="appr_items_body"></div>
        </div>
        <div class="dr">
            <span>Order Total</span>
            <strong id="appr_total"></strong>
        </div>
        <div class="dr" id="appr_prep_row" style="display:none;">
            <span>Prepaid</span>
            <div style="text-align:right;">
                <strong id="appr_prepaid"></strong>
                <div id="appr_prepaid_detail" style="font-size:11px;color:var(--secondary);"></div>
            </div>
        </div>
        <div class="dr">
            <span>Remaining to collect</span>
            <strong id="appr_remaining" style="color:var(--primary);font-size:15px;"></strong>
        </div>
    </div>

    <div class="section-lbl" id="appr_collect_lbl">Collect Remaining Payment</div>

    <div id="appr_shortcuts" class="pay-shortcuts">
        <label class="pay-shortcut-lbl">
            <input type="checkbox" id="appr_is_cash" onchange="toggleApprShortcut('cash')">
            <span class="pay-shortcut-name">Is Cash?</span>
            <span class="pay-shortcut-desc">Full remaining to cash</span>
        </label>
        <label class="pay-shortcut-lbl">
            <input type="checkbox" id="appr_is_momo" onchange="toggleApprShortcut('momo')">
            <span class="pay-shortcut-name">Is Momo?</span>
            <span class="pay-shortcut-desc">Full remaining to momo</span>
        </label>
        <label class="pay-shortcut-lbl">
            <input type="checkbox" id="appr_is_bank" onchange="toggleApprShortcut('bank')">
            <span class="pay-shortcut-name">Is Bank?</span>
            <span class="pay-shortcut-desc">Full remaining to bank</span>
        </label>
        <label class="pay-shortcut-lbl">
            <input type="checkbox" id="appr_is_loan" onchange="toggleApprShortcut('loan')">
            <span class="pay-shortcut-name">Is Loan?</span>
            <span class="pay-shortcut-desc">Full remaining to loan</span>
        </label>
    </div>

    <div class="pay-box" id="appr_pay_box">
        <div class="pay-row">
            <span class="pay-lbl">Cash</span>
            <input type="number" id="appr_cash" min="0" step="any" value="0" oninput="apprCalc('cash')">
        </div>
        <div class="pay-row">
            <span class="pay-lbl">Momo</span>
            <input type="number" id="appr_momo" min="0" step="any" value="0" oninput="apprCalc('momo')">
        </div>
        <div class="pay-row">
            <span class="pay-lbl">Bank</span>
            <input type="number" id="appr_bank" min="0" step="any" value="0" oninput="apprCalc('bank')">
        </div>
        <div class="pay-row">
            <span class="pay-lbl">Loan</span>
            <input type="number" id="appr_loan" min="0" step="any" value="0" oninput="apprCalc('loan')">
        </div>
        <div class="pay-remaining" id="appr_rem_row">
            <span>Still unallocated</span>
            <span id="appr_rem_val">—</span>
        </div>
    </div>

    <div id="appr_phone_row" style="display:none;margin-top:12px;">
        <label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px;">Client Phone (required for loan)</label>
        <input type="text" id="appr_phone_input" placeholder="07XXXXXXXX"
               style="width:100%;padding:9px 12px;border:1px solid var(--gray-300);border-radius:var(--radius);font-size:14px;">
    </div>

    <button id="appr_submit" class="btn btn-success"
            style="width:100%;padding:11px;margin-top:16px;" disabled
            onclick="submitApprove()">
        Approve &amp; Create Bulk Sale(s)
    </button>
</div>
</div>

<!-- Products modal -->
<div id="productsModal" class="modal">
<div class="modal-box" style="max-width:620px;">
    <button class="modal-close" onclick="closeModal('productsModal')">&times;</button>
    <div class="modal-title">Order <span id="prod_order_num" style="color:var(--primary);"></span></div>
    <p id="prod_owner" style="margin:-8px 0 14px;font-size:13px;color:var(--secondary);"></p>
    <div id="prod_items_body"></div>
    <div style="text-align:right;font-weight:700;font-size:15px;padding-top:10px;margin-top:10px;border-top:1px solid var(--gray-200);">
        Total: <span id="prod_total" style="color:var(--primary);"></span>
    </div>
</div>
</div>

<script src="script.js"></script>
<script>
function escH(s){ return (s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

// ── Toast ─────────────────────────────────────────────────────────────────────
function showToast(msg, ok) {
    var t = document.getElementById('orderToast');
    t.textContent = msg;
    t.className = 'show ' + (ok ? 'ok' : 'err');
    clearTimeout(t._tmr);
    t._tmr = setTimeout(function() { t.className = ''; }, 4500);
}

// ── Modal ─────────────────────────────────────────────────────────────────────
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal').forEach(function(m) {
    m.addEventListener('click', function(e) { if (e.target === m) m.classList.remove('open'); });
});

// ── Cancel ────────────────────────────────────────────────────────────────────
var _cancelId = 0;
function cancelOrder(id, orderNum) {
    _cancelId = id;
    document.getElementById('cancel_order_num').textContent = orderNum || ('#' + id);
    document.getElementById('cancel_reason_input').value    = '';
    var btn = document.getElementById('cancel_submit_btn');
    btn.disabled = false; btn.textContent = 'Confirm Cancel';
    openModal('cancelModal');
}
function submitCancel() {
    var btn    = document.getElementById('cancel_submit_btn');
    var reason = document.getElementById('cancel_reason_input').value.trim();
    btn.disabled = true; btn.textContent = 'Processing…';
    var fd = new FormData();
    fd.append('cancel_order',  '1');
    fd.append('order_id',      _cancelId);
    fd.append('cancel_reason', reason);
    fetch('orders.php', {method:'POST', body:fd})
        .then(function(r){ return r.json(); })
        .then(function(res){
            showToast(res.message, res.success);
            if (res.success) {
                closeModal('cancelModal');
                var sc = document.querySelector('.status_cell_'  + _cancelId);
                var ac = document.querySelector('.actions_cell_' + _cancelId);
                var reasonHtml = res.reason
                    ? '<br><span class="col-sub" style="cursor:help;" title="' + escH(res.reason) + '">&#10005; ' +
                      escH(res.reason.length > 35 ? res.reason.slice(0,35) + '…' : res.reason) + '</span>'
                    : '';
                if (sc) sc.innerHTML = '<span class="badge badge-cancelled">Cancelled</span>' + reasonHtml;
                if (ac) ac.querySelectorAll('.act-menu-sep, .act-item[onclick*="openApproveModal"], .act-item.danger')
                           .forEach(function(el){ el.remove(); });
                adjustStat('pending',   -1);
                adjustStat('cancelled', +1);
            } else {
                btn.disabled = false; btn.textContent = 'Confirm Cancel';
            }
        })
        .catch(function(){
            showToast('Network error.', false);
            btn.disabled = false; btn.textContent = 'Confirm Cancel';
        });
}

// ── Approve modal ─────────────────────────────────────────────────────────────
var _apprRem = 0, _apprId = 0;

function openApproveModal(o) {
    _apprRem = o.total_amount - o.total_prepaid;
    _apprId  = o.id;

    document.getElementById('appr_order_num').textContent = o.order_num;
    document.getElementById('appr_total').textContent     = 'RWF ' + o.total_amount.toLocaleString();
    document.getElementById('appr_remaining').textContent = 'RWF ' + Math.max(0,_apprRem).toLocaleString();

    // Items list
    var items = o.items || [];
    var itemsHtml = '';
    if (items.length > 0) {
        itemsHtml = items.map(function(it){
            return '<div style="font-size:13px;padding:2px 0;">' +
                escH(it.product) +
                ' <span style="color:var(--secondary);font-size:12px;">&times;' + it.quantity.toLocaleString() + ' pkg</span>' +
                ' &nbsp; <strong>RWF ' + it.item_total.toLocaleString() + '</strong></div>';
        }).join('');
    }
    document.getElementById('appr_items_body').innerHTML = itemsHtml;

    // Prepaid row
    if (o.total_prepaid > 0) {
        document.getElementById('appr_prep_row').style.display = '';
        document.getElementById('appr_prepaid').textContent = 'RWF ' + o.total_prepaid.toLocaleString();
        var parts = [];
        if (o.prepaid_cash>0) parts.push('Cash: '+o.prepaid_cash.toLocaleString());
        if (o.prepaid_momo>0) parts.push('Momo: '+o.prepaid_momo.toLocaleString());
        if (o.prepaid_bank>0) parts.push('Bank: '+o.prepaid_bank.toLocaleString());
        if (o.prepaid_loan>0) parts.push('Loan: '+o.prepaid_loan.toLocaleString());
        document.getElementById('appr_prepaid_detail').textContent = parts.join(' · ');
    } else {
        document.getElementById('appr_prep_row').style.display = 'none';
    }

    // Payment inputs
    ['cash','momo','bank','loan'].forEach(function(t) {
        document.getElementById('appr_is_' + t).checked = false;
    });
    document.getElementById('appr_cash').value  = 0;
    document.getElementById('appr_momo').value  = Math.max(0, _apprRem);
    document.getElementById('appr_bank').value  = 0;
    document.getElementById('appr_loan').value  = 0;
    document.getElementById('appr_phone_input').value = o.phone || '';

    var noRem = _apprRem <= 0;
    document.getElementById('appr_collect_lbl').textContent = noRem
        ? 'Order fully prepaid — no collection needed' : 'Collect Remaining Payment';
    document.getElementById('appr_shortcuts').style.display = noRem ? 'none' : '';
    document.getElementById('appr_pay_box').style.display   = noRem ? 'none' : '';

    apprCalc();
    openModal('approveModal');
}

function toggleApprShortcut(type) {
    ['cash','momo','bank','loan'].forEach(function(t) {
        if (t !== type) document.getElementById('appr_is_' + t).checked = false;
    });
    var checked = document.getElementById('appr_is_' + type).checked;
    var rem = Math.max(0, _apprRem);
    document.getElementById('appr_cash').value = (checked && type==='cash') ? rem : 0;
    document.getElementById('appr_momo').value = (checked && type==='momo') ? rem : 0;
    document.getElementById('appr_bank').value = (checked && type==='bank') ? rem : 0;
    document.getElementById('appr_loan').value = (checked && type==='loan') ? rem : 0;
    apprCalc();
}

function apprCalc(changed) {
    if (changed) {
        ['cash','momo','bank','loan'].forEach(function(t) {
            document.getElementById('appr_is_' + t).checked = false;
        });
    }
    var cashEl = document.getElementById('appr_cash');
    var momoEl = document.getElementById('appr_momo');
    var bankEl = document.getElementById('appr_bank');
    var loanEl = document.getElementById('appr_loan');
    var cash = parseFloat(cashEl.value)||0;
    var momo = parseFloat(momoEl.value)||0;
    var bank = parseFloat(bankEl.value)||0;
    var loan = parseFloat(loanEl.value)||0;

    if (changed==='cash'||changed==='bank'||changed==='loan') {
        momo = Math.max(0, _apprRem - cash - bank - loan);
        momoEl.value = momo;
    }

    var still   = Math.round(_apprRem - cash - momo - bank - loan);
    var splitOk = _apprRem <= 0 || still === 0;
    document.getElementById('appr_rem_val').textContent = 'RWF ' + still.toLocaleString();
    document.getElementById('appr_rem_row').className =
        'pay-remaining ' + (splitOk ? 'valid' : 'invalid');

    document.getElementById('appr_phone_row').style.display = loan > 0 ? '' : 'none';
    var phoneOk = loan<=0 || document.getElementById('appr_phone_input').value.trim().length > 0;
    document.getElementById('appr_submit').disabled = !(splitOk && phoneOk);
}
document.getElementById('appr_phone_input').addEventListener('input', function(){ apprCalc(); });

// ── Approve submit ────────────────────────────────────────────────────────────
function submitApprove() {
    var btn = document.getElementById('appr_submit');
    btn.disabled=true; btn.textContent='Processing…';
    var fd = new FormData();
    fd.append('approve_order','1');
    fd.append('order_id',    _apprId);
    fd.append('appr_cash',   document.getElementById('appr_cash').value);
    fd.append('appr_momo',   document.getElementById('appr_momo').value);
    fd.append('appr_bank',   document.getElementById('appr_bank').value);
    fd.append('appr_loan',   document.getElementById('appr_loan').value);
    fd.append('appr_phone',  document.getElementById('appr_phone_input').value);
    fetch('orders.php',{method:'POST',body:fd})
        .then(function(r){ return r.json(); })
        .then(function(res){
            showToast(res.message, res.success);
            if (res.success) {
                closeModal('approveModal');
                var sc = document.querySelector('.status_cell_' +_apprId);
                var ac = document.querySelector('.actions_cell_'+_apprId);
                var rc = document.getElementById('rem_cell_'+_apprId);
                if (sc) sc.innerHTML =
                    '<span class="badge badge-approved">Approved</span>' +
                    '<br><span class="col-sub">Sale #' + res.sale_id + '</span>';
                if (ac) ac.querySelectorAll('.act-menu-sep, .act-item[onclick*="openApproveModal"], .act-item.danger').forEach(function(el){ el.remove(); });
                if (rc) { rc.className = 'remaining-pill rp-zero'; rc.textContent = 'RWF 0'; }
                adjustStat('pending',  -1);
                adjustStat('approved', +1);
            }
            btn.textContent='Approve & Create Bulk Sale(s)';
            btn.disabled=false; apprCalc();
        })
        .catch(function(){
            showToast('Network error.',false);
            btn.textContent='Approve & Create Bulk Sale(s)'; btn.disabled=false;
        });
}

// ── Stat counters ─────────────────────────────────────────────────────────────
function adjustStat(key, delta) {
    var map = {pending:'stat_pending',approved:'stat_approved',cancelled:'stat_cancelled'};
    var el  = document.getElementById(map[key]);
    if (!el) return;
    el.textContent = Math.max(0, (parseInt(el.textContent)||0) + delta);
}

// ── Products modal ────────────────────────────────────────────────────────────
function openProductsModal(o) {
    document.getElementById('prod_order_num').textContent = o.order_num;
    document.getElementById('prod_owner').textContent     = o.owner || '';
    document.getElementById('prod_total').textContent     = 'RWF ' + o.total.toLocaleString();
    var html = '';
    if (o.items && o.items.length) {
        html = '<table class="items-tbl"><thead><tr>' +
            '<th style="text-align:left">Product</th>' +
            '<th style="text-align:right">Qty</th>' +
            '<th style="text-align:right">Unit Price</th>' +
            '<th style="text-align:right">Total</th>' +
            '<th style="text-align:center">Status</th>' +
            '</tr></thead><tbody>';
        o.items.forEach(function(it) {
            var st = it.status || '';
            var stLabel = st === 'fulfilled' ? 'Fulfilled' : st === 'out_of_stock' ? 'Out of Stock' : st === 'pending' ? 'Pending' : '';
            var stBg    = st === 'fulfilled' ? '#dcfce7'  : st === 'out_of_stock' ? '#fee2e2'       : '#fef9c3';
            var stColor = st === 'fulfilled' ? '#166534'  : st === 'out_of_stock' ? '#991b1b'       : '#854d0e';
            var stCell  = stLabel
                ? '<span style="display:inline-block;padding:2px 9px;border-radius:10px;font-size:11px;font-weight:600;background:' + stBg + ';color:' + stColor + ';">' + stLabel + '</span>'
                : '';
            html += '<tr>' +
                '<td style="padding:7px 0;">' + escH(it.product) + '</td>' +
                '<td class="num">' + it.quantity.toLocaleString() + '</td>' +
                '<td class="num">RWF ' + it.selling_price.toLocaleString() + '</td>' +
                '<td class="num">RWF ' + it.item_total.toLocaleString() + '</td>' +
                '<td style="text-align:center;padding:7px 4px;">' + stCell + '</td>' +
                '</tr>';
        });
        html += '</tbody></table>';
    } else {
        html = '<p style="color:var(--secondary);text-align:center;padding:24px;">No product details available.</p>';
    }
    document.getElementById('prod_items_body').innerHTML = html;
    openModal('productsModal');
}

// ── Search ────────────────────────────────────────────────────────────────────
function filterOrders() {
    var q = document.getElementById('order_search').value.toLowerCase();
    document.querySelectorAll('#orders_tbody tr[id^="order_row_"]').forEach(function(row) {
        row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
}

// ── Delete ────────────────────────────────────────────────────────────────────
var _deleteId = 0;
function openDeleteOrder(id, orderNum) {
    _deleteId = id;
    document.getElementById('delete_order_num').textContent = orderNum || ('#' + id);
    document.getElementById('delete_reason_input').value    = '';
    document.getElementById('delete_submit_btn').disabled   = true;
    document.getElementById('delete_submit_btn').textContent = 'Delete Permanently';
    openModal('deleteModal');
}
function submitDelete() {
    var btn    = document.getElementById('delete_submit_btn');
    var reason = document.getElementById('delete_reason_input').value.trim();
    if (reason.length < 3) return;
    btn.disabled = true; btn.textContent = 'Deleting…';
    var fd = new FormData();
    fd.append('delete_order',  '1');
    fd.append('order_id',      _deleteId);
    fd.append('delete_reason', reason);
    fetch('orders.php', {method:'POST', body:fd})
        .then(function(r){ return r.json(); })
        .then(function(res){
            showToast(res.message, res.success);
            if (res.success) {
                closeModal('deleteModal');
                var row = document.getElementById('order_row_' + _deleteId);
                if (row) row.remove();
            } else {
                btn.disabled = false; btn.textContent = 'Delete Permanently';
            }
        })
        .catch(function(){
            showToast('Network error.', false);
            btn.disabled = false; btn.textContent = 'Delete Permanently';
        });
}

// ── Thermal receipt print ─────────────────────────────────────────────────────
function printReceipt(o) {
    var remaining = Math.max(0, o.total_amount - o.total_prepaid);
    var statusColor = o.status === 'approved' ? '#166534' : o.status === 'cancelled' ? '#991b1b' : '#854d0e';

    var h = '<!DOCTYPE html><html><head><meta charset="UTF-8">' +
        '<style>' +
        'body{font-family:monospace;font-size:11px;width:76mm;margin:0;padding:3mm 4mm;color:#000;}' +
        'h2{text-align:center;font-size:13px;margin:0 0 2px;letter-spacing:1px;}' +
        '.sub{text-align:center;font-size:10px;margin-bottom:4px;}' +
        'hr{border:none;border-top:1px dashed #000;margin:5px 0;}' +
        'table{width:100%;border-collapse:collapse;}' +
        'td{padding:1px 0;vertical-align:top;font-size:10px;}' +
        '.r{text-align:right;}' +
        '.b{font-weight:bold;}' +
        '.item-name{font-weight:bold;font-size:10px;}' +
        '.item-detail{padding-left:6px;color:#444;}' +
        '.grand td{font-weight:bold;border-top:1px solid #000;padding-top:3px;font-size:11px;}' +
        '.status{text-align:center;font-weight:bold;font-size:12px;padding:3px 0;}' +
        '.footer{text-align:center;font-size:9px;margin-top:4px;}' +
        '@media print{@page{margin:0;size:80mm auto;}body{padding:2mm;}}' +
        '</style></head><body>';

    h += '<h2>SMART STOCK</h2>';
    h += '<div class="sub">Order Receipt</div>';
    h += '<hr>';

    h += '<table>';
    h += '<tr><td class="b">Order #</td><td class="r b">' + escH(o.order_num) + '</td></tr>';
    h += '<tr><td>Date</td><td class="r">' + escH(o.created_at) + '</td></tr>';
    h += '<tr><td>Customer</td><td class="r">' + escH(o.order_owner) + '</td></tr>';
    if (o.phone)    h += '<tr><td>Phone</td><td class="r">' + escH(o.phone) + '</td></tr>';
    if (o.location) h += '<tr><td>Location</td><td class="r">' + escH(o.location) + '</td></tr>';
    h += '</table>';
    h += '<hr>';

    // Items
    h += '<table>';
    (o.items || []).forEach(function(it) {
        h += '<tr><td colspan="2" class="item-name">' + escH(it.product) + '</td></tr>';
        h += '<tr><td class="item-detail">' + it.quantity.toLocaleString() + ' pkg × RWF ' +
             Number(it.selling_price).toLocaleString() + '</td>' +
             '<td class="r b">RWF ' + Number(it.item_total).toLocaleString() + '</td></tr>';
    });
    h += '<tr class="grand"><td>TOTAL</td><td class="r">RWF ' + o.total_amount.toLocaleString() + '</td></tr>';
    h += '</table>';

    // Payments
    if (o.total_prepaid > 0) {
        h += '<hr>';
        h += '<table>';
        h += '<tr><td colspan="2" class="b">Payment Received</td></tr>';
        if (o.prepaid_cash > 0) h += '<tr><td style="padding-left:6px">Cash</td><td class="r">RWF ' + o.prepaid_cash.toLocaleString() + '</td></tr>';
        if (o.prepaid_momo > 0) h += '<tr><td style="padding-left:6px">Momo</td><td class="r">RWF ' + o.prepaid_momo.toLocaleString() + '</td></tr>';
        if (o.prepaid_bank > 0) h += '<tr><td style="padding-left:6px">Bank</td><td class="r">RWF ' + o.prepaid_bank.toLocaleString() + '</td></tr>';
        if (o.prepaid_loan > 0) h += '<tr><td style="padding-left:6px">Loan</td><td class="r">RWF ' + o.prepaid_loan.toLocaleString() + '</td></tr>';
        h += '<tr><td colspan="2"><hr style="margin:3px 0;border-top:1px solid #000;"></td></tr>';
        h += '<tr><td class="b">Balance Due</td><td class="r b">RWF ' + remaining.toLocaleString() + '</td></tr>';
        h += '</table>';
    }

    h += '<hr>';
    h += '<div class="status" style="color:' + statusColor + ';">' + o.status.toUpperCase() + '</div>';
    h += '<hr>';
    h += '<div class="footer">Thank you for your business!</div>';
    h += '</body></html>';

    var w = window.open('', '_blank', 'width=340,height=520,toolbar=0,menubar=0,scrollbars=1,resizable=1');
    if (!w) { alert('Allow popups to print receipts.'); return; }
    w.document.write(h);
    w.document.close();
    w.focus();
    setTimeout(function() { w.print(); }, 350);
}
</script>
</body>
</html>
