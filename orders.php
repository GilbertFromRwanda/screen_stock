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
        "SELECT * FROM `orders` WHERE id=$id AND status IN ('new','open','pending')"));
    if (!$row) {
        echo json_encode(['success'=>false,'message'=>'Order not found or already processed.']);
        exit;
    }
    $reason_sql = $reason !== '' ? "'$reason'" : 'NULL';
    mysqli_query($conn,
        "UPDATE `orders` SET status='cancelled', cancel_reason=$reason_sql, cancelled_by=$user_id, updated_at=NOW() WHERE id=$id");
    logActivity($conn,$_SESSION['user_id'],'CANCEL','orders',"Order #$id cancelled",$id,
        ['status'=>$row['status']],['status'=>'cancelled','reason'=>$reason]);
    echo json_encode(['success'=>true,'message'=>"Order #$id cancelled.",'reason'=>$reason]);
    exit;
}

// ── AJAX: Activate a draft ('new') order link ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['activate_link'])) {
    header('Content-Type: application/json');
    $id      = (int)$_POST['order_id'];
    $minutes = (int)($_POST['expiry_minutes'] ?? 1440);
    if (!in_array($minutes, [30,60,120,1440,2880,10080,20160,43200])) $minutes = 1440;

    $row = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT * FROM `orders` WHERE id=$id AND status='new' " . cidAnd()));
    if (!$row) {
        echo json_encode(['success'=>false,'message'=>'Order not found or not a draft.']);
        exit;
    }
    $link_code  = generateOrderLinkCode($conn);
    $expires_at = date('Y-m-d H:i:s', time() + $minutes * 60);
    mysqli_query($conn,
        "UPDATE `orders` SET status='open', link_code='$link_code', link_expires_at='$expires_at', updated_at=NOW() WHERE id=$id");
    logActivity($conn, $user_id, 'CREATE_LINK', "Order #$id customer link activated (code $link_code)", 'orders', $id, ['status'=>'new'], ['status'=>'open']);

    $scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $baseUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']);
    echo json_encode(['success'=>true,'message'=>'Link activated.',
        'link_code'=>$link_code, 'expires_at'=>$expires_at,
        'link_url'=>$baseUrl.'/order_customer.php?code='.$link_code]);
    exit;
}

// ── AJAX: Finalize ordering (open → pending, hand off to approval flow) ─────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['finalize_link'])) {
    header('Content-Type: application/json');
    $id  = (int)$_POST['order_id'];
    $row = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT * FROM `orders` WHERE id=$id AND status='open' " . cidAnd()));
    if (!$row) {
        echo json_encode(['success'=>false,'message'=>'Order not found or not open.']);
        exit;
    }
    mysqli_query($conn, "UPDATE `orders` SET status='pending', updated_at=NOW() WHERE id=$id");
    logActivity($conn, $user_id, 'FINALIZE_LINK', "Order #$id ordering closed manually, moved to pending", 'orders', $id, ['status'=>'open'], ['status'=>'pending']);
    echo json_encode(['success'=>true,'message'=>'Ordering closed — order is now pending approval.']);
    exit;
}

// ── AJAX: Reassign who's in charge of an order ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reassign_order'])) {
    header('Content-Type: application/json');
    $id        = (int)$_POST['order_id'];
    $new_user  = (int)($_POST['in_charge_id'] ?? 0);
    $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM `orders` WHERE id=$id " . cidAnd()));
    if (!$row) { echo json_encode(['success'=>false,'message'=>'Order not found.']); exit; }
    $u = $new_user > 0 ? mysqli_fetch_assoc(mysqli_query($conn, "SELECT username FROM users WHERE id=$new_user")) : null;
    if ($new_user > 0 && !$u) { echo json_encode(['success'=>false,'message'=>'User not found.']); exit; }
    $in_charge_sql = $new_user > 0 ? $new_user : 'NULL';
    mysqli_query($conn, "UPDATE `orders` SET in_charge_id=$in_charge_sql, updated_at=NOW() WHERE id=$id");
    logActivity($conn, $user_id, 'REASSIGN', "Order #$id in-charge changed" . ($u ? " to {$u['username']}" : ' (cleared)'), 'orders', $id, ['in_charge_id'=>$row['in_charge_id']], ['in_charge_id'=>$new_user ?: null]);
    echo json_encode(['success'=>true,'message'=>'In charge updated.','name'=>$u['username'] ?? '—']);
    exit;
}

// ── AJAX: Advance delivery status ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_delivery_status'])) {
    header('Content-Type: application/json');
    $id        = (int)$_POST['order_id'];
    $new_stage = $_POST['delivery_status'] ?? '';
    if (!in_array($new_stage, ['placed','packed','ready','delivered'], true)) {
        echo json_encode(['success'=>false,'message'=>'Invalid delivery stage.']); exit;
    }
    $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM `orders` WHERE id=$id " . cidAnd()));
    if (!$row) { echo json_encode(['success'=>false,'message'=>'Order not found.']); exit; }
    mysqli_query($conn, "UPDATE `orders` SET delivery_status='$new_stage', updated_at=NOW() WHERE id=$id");
    logActivity($conn, $user_id, 'DELIVERY_STATUS', "Order #$id delivery status set to $new_stage", 'orders', $id, ['delivery_status'=>$row['delivery_status']], ['delivery_status'=>$new_stage]);
    echo json_encode(['success'=>true,'message'=>'Delivery status updated.']);
    exit;
}

// ── AJAX: Approve ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_order'])) {
    header('Content-Type: application/json');

    $order_id = (int)$_POST['order_id'];

    $order = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT * FROM `orders` WHERE id=$order_id AND status='pending'"));
    if (!$order) {
        echo json_encode(['success'=>false,'message'=>'Order not found or already processed.']);
        exit;
    }

    $total_amount  = (float)$order['total_amount'];
    $customer_name = mysqli_real_escape_string($conn, $order['order_owner']);
    $phone         = mysqli_real_escape_string($conn, $order['phone']);
    $cash_amount   = (float)$order['prepaid_cash'];
    $momo_amount   = (float)$order['prepaid_momo'] + (float)$order['prepaid_bank'];
    $loan_amount   = (float)$order['prepaid_loan'];
    $total_pay     = $cash_amount + $momo_amount + $loan_amount;

    // Fetch order items (fall back to single-product for old orders)
    // LEFT JOIN so custom (non-catalog) items — product_id IS NULL — aren't dropped
    $items_q = mysqli_query($conn,
        "SELECT oi.*, p.name AS product_name FROM order_items oi
         LEFT JOIN products p ON oi.product_id=p.id WHERE oi.order_id=$order_id");
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
        $pname = htmlspecialchars($it['product_name'] ?? $it['custom_name'] ?? "Product #$pid");

        if (($it['status'] ?? 'pending') === 'out_of_stock') {
            // Staff manually marked this item unavailable before approval — trust that over live stock.
            if (!empty($it['id'])) $oos_item_ids[] = (int)$it['id'];
            $oos_names[] = $pname;
            continue;
        }

        if ($src === 'custom') {
            // Custom (non-catalog) item typed by the customer — no stock to check, always fulfillable.
            $fulfillable[] = $it;
            continue;
        }

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

        if ($src === 'custom') {
            // No product/stock behind this item — nothing to deduct or record as a sale.
            // Its item_total still counted in $fulfilled_total above for the payment split.
        } else if ($src === 'rt') {
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
        foreach (array_unique($affected_pids) as $pid) if ($pid > 0) recalcStockValue($conn, $pid);
        touchCacheStore($conn, 'products');
        if ($loan_amount > 0) touchCacheStore($conn, 'clients');
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

// ── AJAX: Add Payment ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_payment'])) {
    header('Content-Type: application/json');
    $order_id  = (int)$_POST['order_id'];
    $add_cash  = max(0, (float)($_POST['add_cash'] ?? 0));
    $add_momo  = max(0, (float)($_POST['add_momo'] ?? 0));
    $add_bank  = max(0, (float)($_POST['add_bank'] ?? 0));
    $add_loan  = max(0, (float)($_POST['add_loan'] ?? 0));
    $total_add = round($add_cash + $add_momo + $add_bank + $add_loan, 2);

    if ($total_add <= 0) {
        echo json_encode(['success'=>false,'message'=>'Enter at least one payment amount.']); exit;
    }
    $order = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT * FROM `orders` WHERE id=$order_id AND status='pending' " . cidAnd()));
    if (!$order) {
        echo json_encode(['success'=>false,'message'=>'Order not found or not pending.']); exit;
    }
    $new_prepaid = round((float)$order['total_prepaid'] + $total_add, 2);
    if ($new_prepaid > (float)$order['total_amount'] + 1) {
        echo json_encode(['success'=>false,'message'=>'Payment would exceed order total (RWF '.number_format((float)$order['total_amount'],0).').']); exit;
    }
    mysqli_query($conn, "UPDATE `orders` SET
        prepaid_cash  = prepaid_cash  + $add_cash,
        prepaid_momo  = prepaid_momo  + $add_momo,
        prepaid_bank  = prepaid_bank  + $add_bank,
        prepaid_loan  = prepaid_loan  + $add_loan,
        total_prepaid = total_prepaid + $total_add,
        updated_at    = NOW()
        WHERE id=$order_id");
    mysqli_query($conn, "INSERT INTO order_payments
        (company_id,order_id,cash,momo,bank,loan,total,recorded_by)
        VALUES(" . cidSql() . ",$order_id,$add_cash,$add_momo,$add_bank,$add_loan,$total_add,$user_id)");
    logActivity($conn, $_SESSION['user_id'], 'UPDATE', 'orders',
        "Payment added to order #{$order_id}: Cash=$add_cash Momo=$add_momo Bank=$add_bank Loan=$add_loan",
        $order_id, ['total_prepaid'=>$order['total_prepaid']], ['total_prepaid'=>$new_prepaid]);
    $new_remaining = max(0, (float)$order['total_amount'] - $new_prepaid);
    echo json_encode(['success'=>true,'message'=>'Payment recorded.','new_prepaid'=>$new_prepaid,'new_remaining'=>$new_remaining]);
    exit;
}

// ── AJAX: Add Product to Order ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_product_to_order'])) {
    header('Content-Type: application/json');
    $order_id      = (int)$_POST['order_id'];
    $product_id    = (int)$_POST['product_id'];
    $qty           = (float)$_POST['quantity'];
    $price         = (float)$_POST['selling_price'];
    $level_divisor = max(1, (int)($_POST['level_divisor'] ?? 1));
    $src           = in_array($_POST['stock_source'] ?? 'wh', ['wh','rt']) ? $_POST['stock_source'] : 'wh';
    $item_total    = round($qty * $price, 2);

    if ($product_id <= 0 || $qty <= 0 || $price <= 0) {
        echo json_encode(['success'=>false,'message'=>'Invalid product, quantity, or price.']); exit;
    }
    $order = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT * FROM `orders` WHERE id=$order_id AND status IN ('pending','open') " . cidAnd()));
    if (!$order) {
        echo json_encode(['success'=>false,'message'=>'Order not found or not editable.']); exit;
    }
    $prod = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT name,category FROM products WHERE id=$product_id AND deleted=0"));
    if (!$prod) {
        echo json_encode(['success'=>false,'message'=>'Product not found.']); exit;
    }
    mysqli_query($conn, "INSERT INTO order_items
        (order_id,product_id,stock_source,quantity,level_divisor,selling_price,item_total,source,added_by)
        VALUES($order_id,$product_id,'$src',$qty,$level_divisor,$price,$item_total,'staff',$user_id)");
    $item_id = (int)mysqli_insert_id($conn);
    mysqli_query($conn, "UPDATE `orders` SET total_amount=total_amount+$item_total, updated_at=NOW() WHERE id=$order_id");
    logActivity($conn, $_SESSION['user_id'], 'UPDATE', 'orders',
        "Product '{$prod['name']}' x$qty added to order #{$order_id}",
        $order_id, ['total_amount'=>$order['total_amount']], ['total_amount'=>(float)$order['total_amount']+$item_total]);
    echo json_encode([
        'success'       => true,
        'message'       => "'{$prod['name']}' added to order.",
        'item_id'       => $item_id,
        'product_name'  => $prod['name'],
        'quantity'      => $qty,
        'item_total'    => $item_total,
        'new_total'     => round((float)$order['total_amount'] + $item_total, 2),
    ]);
    exit;
}

// ── AJAX: Close Order ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['close_order'])) {
    header('Content-Type: application/json');
    $order_id = (int)$_POST['order_id'];
    $order = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT * FROM `orders` WHERE id=$order_id AND status='pending' " . cidAnd()));
    if (!$order) {
        echo json_encode(['success'=>false,'message'=>'Order not found or not pending.']); exit;
    }
    mysqli_query($conn, "UPDATE `orders` SET status='closed', updated_at=NOW() WHERE id=$order_id");
    logActivity($conn, $_SESSION['user_id'], 'CLOSE', 'orders',
        "Order #{$order_id} closed (locked from further editing)",
        $order_id, ['status'=>'pending'], ['status'=>'closed']);
    $order_num = $order['order_number'] ?: "#$order_id";
    echo json_encode(['success'=>true,'message'=>"Order $order_num has been closed."]);
    exit;
}

// ── AJAX: Fetch payment history ───────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'order_payments') {
    header('Content-Type: application/json');
    $oid = (int)($_GET['order_id'] ?? 0);
    $order = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT id, order_number, order_owner, total_amount, total_prepaid, status
         FROM `orders` WHERE id=$oid " . cidAnd()));
    if (!$order) { echo json_encode(['success'=>false,'message'=>'Order not found.']); exit; }
    $res = mysqli_query($conn,
        "SELECT op.*, u.username AS by_name
         FROM order_payments op
         LEFT JOIN users u ON op.recorded_by = u.id
         WHERE op.order_id = $oid
         ORDER BY op.created_at ASC");
    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    echo json_encode(['success'=>true,'payments'=>$rows,'order'=>$order]);
    exit;
}

// ── Page load ─────────────────────────────────────────────────────────────────
if (isset($_SESSION['flash_success'])) { $success = $_SESSION['flash_success']; unset($_SESSION['flash_success']); }
if (isset($_SESSION['flash_error']))   { $error   = $_SESSION['flash_error'];   unset($_SESSION['flash_error']); }

// Lazy expiry sweep: any 'open' link past its expiry hands off to the normal pending/approve flow.
mysqli_query($conn, "UPDATE `orders` SET status='pending', updated_at=NOW() WHERE status='open' AND link_expires_at < NOW()");

$status_filter = in_array($_GET['status'] ?? '', ['new','open','pending','approved','cancelled','closed'])
    ? $_GET['status'] : '';
$date_from = isset($_GET['date_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_from'])
    ? $_GET['date_from'] : date('Y-m-d');
$date_to = isset($_GET['date_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_to'])
    ? $_GET['date_to'] : date('Y-m-d');

$where = "WHERE DATE(o.created_at) BETWEEN '$date_from' AND '$date_to'";
if ($status_filter) $where .= " AND o.status='$status_filter'";
$where .= ' ' . cidAndFor('o');

// Staff list for the "in charge" reassignment dropdown
$staff_users = [];
$su_res = mysqli_query($conn, "SELECT id, username FROM users WHERE status='active' " . cidAnd() . " ORDER BY username");
while ($su = mysqli_fetch_assoc($su_res)) $staff_users[] = $su;

$orders_res = mysqli_query($conn, "
    SELECT o.*, p.name AS product_name, p.category,
           u.username   AS created_by_name,
           ua.username  AS approved_by_name,
           uc.username  AS cancelled_by_name,
           ic.username  AS in_charge_name,
           oo.location  AS owner_location,
           src.order_number AS source_order_number,
           (SELECT COUNT(*) FROM `orders` c WHERE c.source_order_id = o.id) AS reuse_count
    FROM `orders` o
    LEFT JOIN products p       ON o.product_id     = p.id
    LEFT JOIN users u          ON o.created_by     = u.id
    LEFT JOIN users ua         ON o.approved_by    = ua.id
    LEFT JOIN users uc         ON o.cancelled_by   = uc.id
    LEFT JOIN users ic         ON o.in_charge_id   = ic.id
    LEFT JOIN order_owners oo  ON o.order_owner_id = oo.id
    LEFT JOIN `orders` src     ON o.source_order_id = src.id
    $where
    ORDER BY FIELD(o.status,'open','new','pending','closed','approved','cancelled'), o.created_at DESC
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
        "SELECT oi.*, p.name AS product_name, p.category, ab.username AS added_by_name
         FROM order_items oi
         LEFT JOIN products p ON oi.product_id = p.id
         LEFT JOIN users ab   ON oi.added_by   = ab.id
         WHERE oi.order_id IN ($ids_str)
         ORDER BY oi.order_id, oi.id");
    while ($it = mysqli_fetch_assoc($ir)) {
        if ($it['product_name'] === null && $it['custom_name']) $it['product_name'] = $it['custom_name'].' (custom)';
        $item_data[(int)$it['order_id']][] = $it;
    }
}

$stats = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT
        SUM(status='pending')   AS cnt_pending,
        SUM(status='approved')  AS cnt_approved,
        SUM(status='cancelled') AS cnt_cancelled,
        SUM(status='open')      AS cnt_open,
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
.badge-closed    { background:#f1f5f9; color:#475569; }
.badge-new       { background:#e0e7ff; color:#3730a3; }
.badge-open      { background:#dbeafe; color:#1e40af; }

.link-info { margin-top:5px; font-size:11px; color:var(--secondary); }
.link-code-pill { display:inline-block; font-family:monospace; font-weight:700; letter-spacing:1px;
    background:var(--gray-100); border:1px solid var(--gray-300); border-radius:4px; padding:1px 6px; }

.delivery-select, .incharge-select {
    font-size:11px; padding:2px 4px; border:1px solid var(--gray-300); border-radius:4px;
    background:var(--white); color:var(--dark); max-width:140px;
}

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


@keyframes aprod-spin { to { transform:rotate(360deg); } }

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
    <div style="display:flex;gap:10px;">
        <a href="order_link_new.php" class="btn">&#128279; Customer Link</a>
        <a href="order_new.php" class="btn btn-primary">+ New Order</a>
    </div>
</div>

<!-- Stats -->
<div class="orders-stats">
    <div class="ostat">
        <div class="ostat-val" id="stat_open"><?php echo (int)$stats['cnt_open']; ?></div>
        <div class="ostat-lbl">Open Links</div>
    </div>
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
            <option value="new"       <?php if($status_filter==='new')       echo 'selected'; ?>>New (draft)</option>
            <option value="open"      <?php if($status_filter==='open')      echo 'selected'; ?>>Open (link active)</option>
            <option value="pending"   <?php if($status_filter==='pending')   echo 'selected'; ?>>Pending</option>
            <option value="approved"  <?php if($status_filter==='approved')  echo 'selected'; ?>>Approved</option>
            <option value="cancelled" <?php if($status_filter==='cancelled') echo 'selected'; ?>>Cancelled</option>
            <option value="closed"    <?php if($status_filter==='closed')    echo 'selected'; ?>>Closed</option>
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
    $isOpen    = $o['status'] === 'open';
    $isNew     = $o['status'] === 'new';
    $order_num = $o['order_number'] ?: '#'.$o['id'];
    $scheme    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $link_url  = $o['link_code'] ? $scheme.'://'.$_SERVER['HTTP_HOST'].dirname($_SERVER['SCRIPT_NAME']).'/order_customer.php?code='.$o['link_code'] : '';

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
                'source'        => $it['source'] ?? 'staff',
                'added_by_name' => $it['added_by_name'] ?? null,
            ];
        }
    } elseif ($o['product_name']) {
        $modal_items[] = [
            'product'       => ($o['category']??'').'-'.($o['product_name']??''),
            'quantity'      => (float)$o['quantity'],
            'selling_price' => (float)$o['selling_price'],
            'item_total'    => (float)$o['total_amount'],
            'status'        => '',
            'source'        => 'staff',
            'added_by_name' => $o['created_by_name'] ?? null,
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
                <button class="act-item" onclick='openPaymentsModal(<?php echo $o["id"]; ?>,<?php echo json_encode($order_num); ?>,<?php echo $isPending ? "true" : "false"; ?>);closeActMenus()'><i class="fas fa-coins"></i> Payments</button>
                <?php if ($isNew): ?>
                <div class="act-menu-sep"></div>
                <button class="act-item" style="color:#1d4ed8;" onclick='activateLink(<?php echo $o["id"]; ?>,<?php echo json_encode($order_num); ?>);closeActMenus()'><i class="fas fa-link"></i> Activate Link</button>
                <button class="act-item danger" onclick='cancelOrder(<?php echo $o["id"]; ?>,<?php echo json_encode($order_num); ?>);closeActMenus()'><i class="fas fa-times"></i> Cancel</button>
                <?php endif; ?>
                <?php if ($isOpen): ?>
                <div class="act-menu-sep"></div>
                <button class="act-item" style="color:#1d4ed8;" onclick='copyOrderLink(<?php echo json_encode($link_url); ?>)'><i class="fas fa-copy"></i> Copy Link</button>
                <?php if (!$o['is_reusable']): ?>
                <a class="act-item" href="order_add_products.php?order_id=<?php echo $o['id']; ?>" style="text-decoration:none;"><i class="fas fa-plus-circle"></i> Add Product</a>
                <button class="act-item" style="color:#d97706;" onclick='finalizeOrdering(<?php echo $o["id"]; ?>,<?php echo json_encode($order_num); ?>);closeActMenus()'><i class="fas fa-flag-checkered"></i> Close Ordering</button>
                <?php endif; ?>
                <button class="act-item danger" onclick='cancelOrder(<?php echo $o["id"]; ?>,<?php echo json_encode($order_num); ?>);closeActMenus()'><i class="fas fa-times"></i> Cancel</button>
                <?php echo $o['is_reusable'] ? '<div style="font-size:11px;color:var(--secondary);padding:6px 14px;">Cancel deactivates this reusable link.</div>' : ''; ?>
                <?php endif; ?>
                <?php if ($isPending): ?>
                <div class="act-menu-sep"></div>
                <button class="act-item" onclick='openAddPaymentModal(<?php echo $o["id"]; ?>,<?php echo json_encode($order_num); ?>,<?php echo (float)$o["total_amount"]; ?>,<?php echo (float)$o["total_prepaid"]; ?>);closeActMenus()'><i class="fas fa-money-bill-wave"></i> Add Payment</button>
                <a class="act-item" href="order_add_products.php?order_id=<?php echo $o['id']; ?>" style="text-decoration:none;"><i class="fas fa-plus-circle"></i> Add Product</a>
                <div class="act-menu-sep"></div>
                <button class="act-item" style="color:#16a34a;" onclick='openApproveModal(<?php echo $data; ?>);closeActMenus()'><i class="fas fa-check"></i> Approve</button>
                <button class="act-item danger" onclick='cancelOrder(<?php echo $o["id"]; ?>,<?php echo json_encode($order_num); ?>);closeActMenus()'><i class="fas fa-times"></i> Cancel</button>
                <button class="act-item" style="color:#d97706;" onclick='openCloseOrderModal(<?php echo $o["id"]; ?>,<?php echo json_encode($order_num); ?>);closeActMenus()'><i class="fas fa-lock"></i> Close Order</button>
                <?php endif; ?>
                <?php if (($isPending || $isNew || $isOpen) && in_array($role, ['admin','manager','superadmin'])): ?>
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
        <?php if (!empty($o['source_order_number'])): ?>
        <br><span class="col-sub" title="Placed through a reusable customer link">&#128279; via <?php echo htmlspecialchars($o['source_order_number']); ?></span>
        <?php endif; ?>
        <?php if ($isOpen && !empty($o['is_reusable']) && (int)$o['reuse_count'] > 0): ?>
        <br><span class="col-sub">Used <?php echo (int)$o['reuse_count']; ?>&times;</span>
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
        <?php if (in_array($o['status'], ['pending','approved'])): ?>
        <div style="margin-top:4px;">
            <select class="delivery-select" onchange="updateDeliveryStatus(<?php echo $o['id']; ?>, this.value)" title="Delivery stage">
                <?php foreach (['placed'=>'Placed','packed'=>'Packed','ready'=>'Ready to Deliver','delivered'=>'Delivered'] as $dv => $dl): ?>
                <option value="<?php echo $dv; ?>" <?php echo $o['delivery_status']===$dv?'selected':''; ?>><?php echo $dl; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <?php if ($isOpen && $o['link_code']): ?>
        <div class="link-info">
            Code <span class="link-code-pill"><?php echo htmlspecialchars($o['link_code']); ?></span>
            <?php if ($o['is_reusable']): ?><span class="badge" style="background:#ede9fe;color:#5b21b6;font-size:10px;padding:1px 6px;margin-left:3px;">Reusable</span><?php endif; ?>
            <br><?php echo $o['link_expires_at'] ? 'Expires ' . date('d M, H:i', strtotime($o['link_expires_at'])) : 'Never expires'; ?>
            <?php echo $o['show_prices'] ? '' : '<br>Prices hidden'; ?>
        </div>
        <?php endif; ?>
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
        <?php if (!in_array($o['status'], ['cancelled'])): ?>
        <div style="margin-top:4px;">
            <div class="col-sub" style="margin-bottom:2px;">In charge</div>
            <select class="incharge-select" onchange="reassignOrder(<?php echo $o['id']; ?>, this.value)" title="In charge of this order">
                <option value="">— Unassigned —</option>
                <?php foreach ($staff_users as $su): ?>
                <option value="<?php echo $su['id']; ?>" <?php echo (int)$o['in_charge_id']===(int)$su['id']?'selected':''; ?>><?php echo htmlspecialchars($su['username']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
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
            <span>Collected</span>
            <div style="text-align:right;">
                <strong id="appr_prepaid"></strong>
                <div id="appr_prepaid_detail" style="font-size:11px;color:var(--secondary);"></div>
            </div>
        </div>
        <div class="dr">
            <span>Remaining Balance</span>
            <strong id="appr_remaining" style="font-size:15px;"></strong>
        </div>
    </div>

    <button id="appr_submit" class="btn btn-success"
            style="width:100%;padding:11px;margin-top:16px;"
            onclick="submitApprove()">
        Approve Order
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

<!-- Payments history modal -->
<div id="paymentsModal" class="modal">
<div class="modal-box" style="max-width:620px;">
    <button class="modal-close" onclick="closeModal('paymentsModal')">&times;</button>
    <div class="modal-title">Payments &mdash; <span id="pm_order_num" style="color:var(--primary);"></span></div>

    <div class="order-detail-box" style="margin-bottom:16px;">
        <div class="dr"><span>Customer</span><strong id="pm_owner"></strong></div>
        <div class="dr"><span>Order Total</span><strong id="pm_total"></strong></div>
        <div class="dr"><span>Total Collected</span><strong id="pm_collected" style="color:#059669;"></strong></div>
        <div class="dr"><span>Balance Due</span><strong id="pm_balance" style="color:var(--primary);"></strong></div>
    </div>

    <div id="pm_loading" style="text-align:center;padding:24px;color:var(--secondary);font-size:13px;">Loading…</div>
    <div id="pm_table_wrap" style="display:none;">
        <table style="width:100%;border-collapse:collapse;font-size:13px;">
            <thead>
                <tr style="border-bottom:2px solid var(--gray-200);">
                    <th style="text-align:left;padding:6px 4px;font-size:11px;color:var(--secondary);text-transform:uppercase;">Date</th>
                    <th style="text-align:right;padding:6px 4px;font-size:11px;color:var(--secondary);text-transform:uppercase;">Cash</th>
                    <th style="text-align:right;padding:6px 4px;font-size:11px;color:var(--secondary);text-transform:uppercase;">Momo</th>
                    <th style="text-align:right;padding:6px 4px;font-size:11px;color:var(--secondary);text-transform:uppercase;">Bank</th>
                    <th style="text-align:right;padding:6px 4px;font-size:11px;color:var(--secondary);text-transform:uppercase;">Loan</th>
                    <th style="text-align:right;padding:6px 4px;font-size:11px;color:var(--secondary);text-transform:uppercase;">Total</th>
                    <th style="text-align:left;padding:6px 4px;font-size:11px;color:var(--secondary);text-transform:uppercase;">By</th>
                </tr>
            </thead>
            <tbody id="pm_tbody"></tbody>
            <tfoot id="pm_tfoot"></tfoot>
        </table>
        <div id="pm_empty" style="display:none;text-align:center;padding:20px;color:var(--secondary);font-size:13px;">No payment records yet.</div>
    </div>

    <!-- Inline Add Payment (pending orders only) -->
    <div id="pm_add_section" style="display:none;margin-top:20px;border-top:1px solid var(--gray-200);padding-top:16px;">
        <div class="section-lbl">Add Payment</div>
        <div class="pay-box">
            <div class="pay-row"><span class="pay-lbl">Cash</span><input type="number" id="pm_cash" min="0" step="any" value="0" oninput="pmCalc()"></div>
            <div class="pay-row"><span class="pay-lbl">Momo</span><input type="number" id="pm_momo" min="0" step="any" value="0" oninput="pmCalc()"></div>
            <div class="pay-row"><span class="pay-lbl">Bank</span><input type="number" id="pm_bank" min="0" step="any" value="0" oninput="pmCalc()"></div>
            <div class="pay-row"><span class="pay-lbl">Loan</span><input type="number" id="pm_loan" min="0" step="any" value="0" oninput="pmCalc()"></div>
            <div class="pay-remaining" id="pm_rem_row">
                <span>New Balance After</span>
                <span id="pm_rem_val">—</span>
            </div>
        </div>
        <button id="pm_submit" class="btn btn-success" style="width:100%;padding:11px;margin-top:12px;" disabled onclick="submitPaymentFromModal()">
            Record Payment
        </button>
    </div>
</div>
</div>

<!-- Add Payment modal -->
<div id="addPaymentModal" class="modal">
<div class="modal-box" style="max-width:420px;">
    <button class="modal-close" onclick="closeModal('addPaymentModal')">&times;</button>
    <div class="modal-title">Add Payment &mdash; <span id="apm_order_num" style="color:var(--primary);"></span></div>
    <div class="order-detail-box">
        <div class="dr"><span>Order Total</span><strong id="apm_total"></strong></div>
        <div class="dr"><span>Already Prepaid</span><strong id="apm_prepaid"></strong></div>
        <div class="dr"><span>Remaining</span><strong id="apm_remaining" style="color:var(--primary);"></strong></div>
    </div>
    <div class="section-lbl">Additional Payment</div>
    <div class="pay-box">
        <div class="pay-row"><span class="pay-lbl">Cash</span><input type="number" id="apm_cash" min="0" step="any" value="0" oninput="apmCalc()"></div>
        <div class="pay-row"><span class="pay-lbl">Momo</span><input type="number" id="apm_momo" min="0" step="any" value="0" oninput="apmCalc()"></div>
        <div class="pay-row"><span class="pay-lbl">Bank</span><input type="number" id="apm_bank" min="0" step="any" value="0" oninput="apmCalc()"></div>
        <div class="pay-row"><span class="pay-lbl">Loan</span><input type="number" id="apm_loan" min="0" step="any" value="0" oninput="apmCalc()"></div>
        <div class="pay-remaining" id="apm_rem_row">
            <span>New Balance After</span>
            <span id="apm_rem_val">—</span>
        </div>
    </div>
    <button id="apm_submit" class="btn btn-success" style="width:100%;padding:11px;margin-top:16px;" disabled onclick="submitAddPayment()">
        Record Payment
    </button>
</div>
</div>

<!-- Add Product modal -->
<div id="addProductModal" class="modal">
<div class="modal-box" style="max-width:500px;">
    <button class="modal-close" onclick="closeModal('addProductModal')">&times;</button>
    <div class="modal-title">Add Product &mdash; <span id="aprod_order_num" style="color:var(--primary);"></span></div>
    <div style="margin-bottom:14px;position:relative;">
        <label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px;">Search Product</label>
        <input type="text" id="aprod_search" placeholder="Type product name or category&hellip;"
               style="width:100%;padding:9px 12px;border:1px solid var(--gray-300);border-radius:var(--radius);font-size:14px;box-sizing:border-box;"
               oninput="aprodSearch(this.value)">
        <div id="aprod_results"
             style="display:none;position:absolute;left:0;right:0;background:var(--white);border:1px solid var(--gray-300);border-top:none;border-radius:0 0 var(--radius) var(--radius);max-height:200px;overflow-y:auto;z-index:100;"></div>
    </div>
    <div id="aprod_selected_card" style="display:none;background:#eff6ff;border:1px solid #bfdbfe;border-radius:var(--radius);padding:12px 16px;margin-bottom:14px;">
        <div style="font-weight:700;color:#1e40af;font-size:14px;" id="aprod_sel_name"></div>
        <div style="font-size:12px;color:var(--secondary);margin-top:2px;" id="aprod_sel_stock"></div>
    </div>
    <div id="aprod_form" style="display:none;">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
            <div>
                <label style="font-size:13px;font-weight:600;display:block;margin-bottom:5px;">Quantity</label>
                <input type="number" id="aprod_qty" min="0.01" step="any" placeholder="0"
                       style="width:100%;padding:9px 12px;border:1px solid var(--gray-300);border-radius:var(--radius);font-size:14px;box-sizing:border-box;"
                       oninput="aprodCalc()">
            </div>
            <div>
                <label style="font-size:13px;font-weight:600;display:block;margin-bottom:5px;">Unit Price</label>
                <input type="number" id="aprod_price" min="0.01" step="any" placeholder="0"
                       style="width:100%;padding:9px 12px;border:1px solid var(--gray-300);border-radius:var(--radius);font-size:14px;box-sizing:border-box;"
                       oninput="aprodCalc()">
            </div>
        </div>
        <div style="display:flex;justify-content:space-between;padding:9px 14px;background:var(--gray-50);border-radius:var(--radius);font-weight:700;font-size:13px;margin-bottom:14px;">
            <span>Item Total</span>
            <span id="aprod_item_total" style="color:var(--primary);">RWF 0</span>
        </div>
        <button id="aprod_submit" class="btn btn-primary" style="width:100%;padding:11px;" disabled onclick="submitAddProduct()">
            Add to Order
        </button>
    </div>
</div>
</div>

<!-- Close Order modal -->
<div id="closeOrderModal" class="modal">
<div class="modal-box" style="max-width:400px;">
    <button class="modal-close" onclick="closeModal('closeOrderModal')">&times;</button>
    <div class="modal-title">Close Order <span id="co_order_num" style="color:#d97706;"></span></div>
    <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:var(--radius);padding:12px 14px;margin-bottom:18px;font-size:13px;color:#92400e;">
        &#128274; Closing this order locks it. No payments, products, approvals, or cancellations will be possible after this.
    </div>
    <div style="display:flex;gap:10px;">
        <button class="btn" style="flex:1;" onclick="closeModal('closeOrderModal')">Back</button>
        <button class="btn" style="flex:1;background:#d97706;color:#fff;" id="co_submit_btn" onclick="submitCloseOrder()">Confirm Close</button>
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

// ── Link actions (new/open orders) ──────────────────────────────────────────────
function activateLink(id, orderNum) {
    var fd = new FormData();
    fd.append('activate_link', '1');
    fd.append('order_id', id);
    fetch('orders.php', {method:'POST', body:fd})
        .then(function(r){ return r.json(); })
        .then(function(res){
            showToast(res.message, res.success);
            if (res.success) location.reload();
        })
        .catch(function(){ showToast('Network error.', false); });
}

function finalizeOrdering(id, orderNum) {
    if (!confirm('Close ordering for ' + orderNum + '? The customer link will stop accepting new items and the order moves to Pending for approval.')) return;
    var fd = new FormData();
    fd.append('finalize_link', '1');
    fd.append('order_id', id);
    fetch('orders.php', {method:'POST', body:fd})
        .then(function(r){ return r.json(); })
        .then(function(res){
            showToast(res.message, res.success);
            if (res.success) location.reload();
        })
        .catch(function(){ showToast('Network error.', false); });
}

function copyOrderLink(url) {
    if (!url) return;
    var ta = document.createElement('textarea');
    ta.value = url; ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta); ta.select();
    try {
        navigator.clipboard && navigator.clipboard.writeText(url).then(function(){ showToast('Link copied!', true); })
            .catch(function(){ document.execCommand('copy'); showToast('Link copied!', true); });
    } finally { document.body.removeChild(ta); }
}

// ── In charge / delivery status ─────────────────────────────────────────────────
function reassignOrder(id, userId) {
    var fd = new FormData();
    fd.append('reassign_order', '1');
    fd.append('order_id', id);
    fd.append('in_charge_id', userId);
    fetch('orders.php', {method:'POST', body:fd})
        .then(function(r){ return r.json(); })
        .then(function(res){ showToast(res.message, res.success); })
        .catch(function(){ showToast('Network error.', false); });
}

function updateDeliveryStatus(id, stage) {
    var fd = new FormData();
    fd.append('update_delivery_status', '1');
    fd.append('order_id', id);
    fd.append('delivery_status', stage);
    fetch('orders.php', {method:'POST', body:fd})
        .then(function(r){ return r.json(); })
        .then(function(res){ showToast(res.message, res.success); })
        .catch(function(){ showToast('Network error.', false); });
}

// ── Approve modal ─────────────────────────────────────────────────────────────
var _apprId = 0;

function openApproveModal(o) {
    _apprId = o.id;

    document.getElementById('appr_order_num').textContent = o.order_num;
    document.getElementById('appr_total').textContent     = 'RWF ' + o.total_amount.toLocaleString();

    var rem = o.total_amount - o.total_prepaid;
    var remEl = document.getElementById('appr_remaining');
    remEl.textContent = 'RWF ' + Math.max(0, rem).toLocaleString();
    remEl.style.color = rem <= 0 ? '#059669' : 'var(--primary)';

    var items = o.items || [];
    document.getElementById('appr_items_body').innerHTML = items.map(function(it){
        return '<div style="font-size:13px;padding:2px 0;">'
            + escH(it.product)
            + ' <span style="color:var(--secondary);font-size:12px;">&times;' + it.quantity.toLocaleString() + '</span>'
            + ' &nbsp;<strong>RWF ' + it.item_total.toLocaleString() + '</strong></div>';
    }).join('');

    if (o.total_prepaid > 0) {
        document.getElementById('appr_prep_row').style.display = '';
        document.getElementById('appr_prepaid').textContent    = 'RWF ' + o.total_prepaid.toLocaleString();
        var parts = [];
        if (o.prepaid_cash>0) parts.push('Cash: '+o.prepaid_cash.toLocaleString());
        if (o.prepaid_momo>0) parts.push('Momo: '+o.prepaid_momo.toLocaleString());
        if (o.prepaid_bank>0) parts.push('Bank: '+o.prepaid_bank.toLocaleString());
        if (o.prepaid_loan>0) parts.push('Loan: '+o.prepaid_loan.toLocaleString());
        document.getElementById('appr_prepaid_detail').textContent = parts.join(' · ');
    } else {
        document.getElementById('appr_prep_row').style.display = 'none';
    }

    var btn = document.getElementById('appr_submit');
    btn.disabled = false; btn.textContent = 'Approve Order';
    openModal('approveModal');
}

// ── Approve submit ────────────────────────────────────────────────────────────
function submitApprove() {
    var btn = document.getElementById('appr_submit');
    btn.disabled=true; btn.textContent='Processing…';
    var fd = new FormData();
    fd.append('approve_order','1');
    fd.append('order_id', _apprId);
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
                if (ac) {
                    var items = ac.querySelectorAll('.act-menu .act-item');
                    Array.from(items).slice(2).forEach(function(el){ el.remove(); });
                    ac.querySelectorAll('.act-menu .act-menu-sep').forEach(function(el){ el.remove(); });
                }
                if (rc) { rc.className = 'remaining-pill rp-zero'; rc.textContent = 'RWF 0'; }
                adjustStat('pending',  -1);
                adjustStat('approved', +1);
            } else {
                btn.disabled=false; btn.textContent='Approve Order';
            }
        })
        .catch(function(){
            showToast('Network error.',false);
            btn.disabled=false; btn.textContent='Approve Order';
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
            '<th style="text-align:center">Added By</th>' +
            '</tr></thead><tbody>';
        o.items.forEach(function(it) {
            var st = it.status || '';
            var stLabel = st === 'fulfilled' ? 'Fulfilled' : st === 'out_of_stock' ? 'Out of Stock' : st === 'pending' ? 'Pending' : '';
            var stBg    = st === 'fulfilled' ? '#dcfce7'  : st === 'out_of_stock' ? '#fee2e2'       : '#fef9c3';
            var stColor = st === 'fulfilled' ? '#166534'  : st === 'out_of_stock' ? '#991b1b'       : '#854d0e';
            var stCell  = stLabel
                ? '<span style="display:inline-block;padding:2px 9px;border-radius:10px;font-size:11px;font-weight:600;background:' + stBg + ';color:' + stColor + ';">' + stLabel + '</span>'
                : '';
            var isCustomer = it.source === 'customer';
            var srcLabel = isCustomer ? 'Customer' : 'Staff' + (it.added_by_name ? ' (' + escH(it.added_by_name) + ')' : '');
            var srcCell  = '<span style="display:inline-block;padding:2px 9px;border-radius:10px;font-size:11px;font-weight:600;background:' +
                (isCustomer ? '#ede9fe' : '#dbeafe') + ';color:' + (isCustomer ? '#5b21b6' : '#1e40af') + ';">' + srcLabel + '</span>';
            html += '<tr>' +
                '<td style="padding:7px 0;">' + escH(it.product) + '</td>' +
                '<td class="num">' + it.quantity.toLocaleString() + '</td>' +
                '<td class="num">RWF ' + it.selling_price.toLocaleString() + '</td>' +
                '<td class="num">RWF ' + it.item_total.toLocaleString() + '</td>' +
                '<td style="text-align:center;padding:7px 4px;">' + stCell + '</td>' +
                '<td style="text-align:center;padding:7px 4px;">' + srcCell + '</td>' +
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

// ── Payments history modal ────────────────────────────────────────────────────
var _pmId = 0, _pmIsPending = false, _pmTotal = 0, _pmCollected = 0;

function openPaymentsModal(id, orderNum, isPending) {
    _pmId = id; _pmIsPending = isPending;
    document.getElementById('pm_order_num').textContent         = orderNum;
    document.getElementById('pm_owner').textContent             = '';
    document.getElementById('pm_total').textContent             = '—';
    document.getElementById('pm_collected').textContent         = '—';
    document.getElementById('pm_balance').textContent           = '—';
    document.getElementById('pm_loading').style.display         = '';
    document.getElementById('pm_table_wrap').style.display      = 'none';
    document.getElementById('pm_add_section').style.display     = 'none';
    ['cash','momo','bank','loan'].forEach(function(t){ document.getElementById('pm_'+t).value = 0; });
    openModal('paymentsModal');
    loadPayments(id);
}

function loadPayments(id) {
    fetch('orders.php?action=order_payments&order_id=' + id)
        .then(function(r){ return r.json(); })
        .then(function(res){
            document.getElementById('pm_loading').style.display = 'none';
            if (!res.success) { document.getElementById('pm_loading').textContent = res.message || 'Failed to load.'; document.getElementById('pm_loading').style.display=''; return; }

            var o = res.order;
            _pmTotal     = parseFloat(o.total_amount);
            _pmCollected = parseFloat(o.total_prepaid);

            document.getElementById('pm_owner').textContent     = o.order_owner || '—';
            document.getElementById('pm_total').textContent     = 'RWF ' + Math.round(_pmTotal).toLocaleString();
            document.getElementById('pm_collected').textContent = 'RWF ' + Math.round(_pmCollected).toLocaleString();
            document.getElementById('pm_balance').textContent   = 'RWF ' + Math.round(Math.max(0, _pmTotal - _pmCollected)).toLocaleString();

            var payments = res.payments || [];
            var tbody = document.getElementById('pm_tbody');
            var tfoot = document.getElementById('pm_tfoot');

            if (!payments.length) {
                tbody.innerHTML = '';
                tfoot.innerHTML = '';
                document.getElementById('pm_empty').style.display = '';
            } else {
                document.getElementById('pm_empty').style.display = 'none';
                var sumCash=0,sumMomo=0,sumBank=0,sumLoan=0,sumTotal=0;
                tbody.innerHTML = payments.map(function(p){
                    sumCash  += parseFloat(p.cash)||0;
                    sumMomo  += parseFloat(p.momo)||0;
                    sumBank  += parseFloat(p.bank)||0;
                    sumLoan  += parseFloat(p.loan)||0;
                    sumTotal += parseFloat(p.total)||0;
                    var dt = new Date(p.created_at);
                    var dateStr = dt.toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'});
                    var timeStr = dt.toLocaleTimeString('en-GB',{hour:'2-digit',minute:'2-digit'});
                    var isInitial = p.note === 'Initial prepaid';
                    function fmt(v){ return parseFloat(v)>0 ? 'RWF '+Math.round(v).toLocaleString() : '<span style="color:var(--gray-300)">—</span>'; }
                    return '<tr style="border-bottom:1px solid var(--gray-100);">'
                        + '<td style="padding:7px 4px;">'
                        + (isInitial ? '<span style="font-size:11px;font-weight:700;background:#eff6ff;color:#1e40af;padding:1px 6px;border-radius:8px;">Initial</span>' : dateStr+'<br><span style="font-size:11px;color:var(--secondary);">'+timeStr+'</span>')
                        + '</td>'
                        + '<td style="text-align:right;padding:7px 4px;">'+fmt(p.cash)+'</td>'
                        + '<td style="text-align:right;padding:7px 4px;">'+fmt(p.momo)+'</td>'
                        + '<td style="text-align:right;padding:7px 4px;">'+fmt(p.bank)+'</td>'
                        + '<td style="text-align:right;padding:7px 4px;">'+fmt(p.loan)+'</td>'
                        + '<td style="text-align:right;padding:7px 4px;font-weight:700;">RWF '+Math.round(p.total).toLocaleString()+'</td>'
                        + '<td style="padding:7px 4px;font-size:12px;color:var(--secondary);">'+escH(p.by_name||'—')+'</td>'
                        + '</tr>';
                }).join('');
                tfoot.innerHTML = '<tr style="border-top:2px solid var(--gray-200);font-weight:700;">'
                    + '<td style="padding:7px 4px;">Total</td>'
                    + '<td style="text-align:right;padding:7px 4px;">'+(sumCash>0?'RWF '+Math.round(sumCash).toLocaleString():'—')+'</td>'
                    + '<td style="text-align:right;padding:7px 4px;">'+(sumMomo>0?'RWF '+Math.round(sumMomo).toLocaleString():'—')+'</td>'
                    + '<td style="text-align:right;padding:7px 4px;">'+(sumBank>0?'RWF '+Math.round(sumBank).toLocaleString():'—')+'</td>'
                    + '<td style="text-align:right;padding:7px 4px;">'+(sumLoan>0?'RWF '+Math.round(sumLoan).toLocaleString():'—')+'</td>'
                    + '<td style="text-align:right;padding:7px 4px;color:var(--primary);">RWF '+Math.round(sumTotal).toLocaleString()+'</td>'
                    + '<td></td></tr>';
            }

            document.getElementById('pm_table_wrap').style.display = '';
            if (_pmIsPending) {
                document.getElementById('pm_add_section').style.display = '';
                pmCalc();
            }
        })
        .catch(function(){ document.getElementById('pm_loading').textContent='Network error.'; document.getElementById('pm_loading').style.display=''; });
}

function pmCalc() {
    var cash = parseFloat(document.getElementById('pm_cash').value)||0;
    var momo = parseFloat(document.getElementById('pm_momo').value)||0;
    var bank = parseFloat(document.getElementById('pm_bank').value)||0;
    var loan = parseFloat(document.getElementById('pm_loan').value)||0;
    var add  = cash+momo+bank+loan;
    var newRem = Math.max(0, _pmTotal - _pmCollected - add);
    var over   = (_pmCollected + add) > _pmTotal + 1;
    document.getElementById('pm_rem_val').textContent = 'RWF ' + Math.round(newRem).toLocaleString();
    document.getElementById('pm_rem_row').className   = 'pay-remaining ' + (over ? 'invalid' : (newRem<=0 ? 'valid' : ''));
    document.getElementById('pm_submit').disabled     = (add<=0 || over);
}

function submitPaymentFromModal() {
    var btn = document.getElementById('pm_submit');
    btn.disabled=true; btn.textContent='Saving…';
    var fd = new FormData();
    fd.append('add_payment','1'); fd.append('order_id',_pmId);
    fd.append('add_cash', document.getElementById('pm_cash').value);
    fd.append('add_momo', document.getElementById('pm_momo').value);
    fd.append('add_bank', document.getElementById('pm_bank').value);
    fd.append('add_loan', document.getElementById('pm_loan').value);
    fetch('orders.php',{method:'POST',body:fd})
        .then(function(r){ return r.json(); })
        .then(function(res){
            showToast(res.message, res.success);
            if (res.success) {
                _pmCollected = res.new_prepaid;
                // Update the summary row
                document.getElementById('pm_collected').textContent = 'RWF ' + Math.round(_pmCollected).toLocaleString();
                document.getElementById('pm_balance').textContent   = 'RWF ' + Math.round(Math.max(0,_pmTotal-_pmCollected)).toLocaleString();
                // Update the remaining pill in the orders table row
                var rc = document.getElementById('rem_cell_'+_pmId);
                if (rc) {
                    rc.className   = 'remaining-pill '+(res.new_remaining<=0?'rp-zero':'rp-part');
                    rc.textContent = 'RWF '+Math.round(res.new_remaining).toLocaleString();
                }
                // Reset form and reload payment rows
                ['cash','momo','bank','loan'].forEach(function(t){ document.getElementById('pm_'+t).value=0; });
                pmCalc();
                document.getElementById('pm_loading').style.display  = '';
                document.getElementById('pm_table_wrap').style.display = 'none';
                loadPayments(_pmId);
            }
            btn.disabled=false; btn.textContent='Record Payment';
        })
        .catch(function(){ showToast('Network error.',false); btn.disabled=false; btn.textContent='Record Payment'; });
}

// ── Add Payment ───────────────────────────────────────────────────────────────
var _apmId = 0, _apmTotal = 0, _apmPrepaid = 0;
function openAddPaymentModal(id, orderNum, total, prepaid) {
    _apmId = id; _apmTotal = total; _apmPrepaid = prepaid;
    document.getElementById('apm_order_num').textContent   = orderNum;
    document.getElementById('apm_total').textContent       = 'RWF ' + total.toLocaleString();
    document.getElementById('apm_prepaid').textContent     = 'RWF ' + prepaid.toLocaleString();
    document.getElementById('apm_remaining').textContent   = 'RWF ' + Math.max(0, total - prepaid).toLocaleString();
    ['cash','momo','bank','loan'].forEach(function(t){ document.getElementById('apm_'+t).value = 0; });
    apmCalc();
    openModal('addPaymentModal');
}
function apmCalc() {
    var cash = parseFloat(document.getElementById('apm_cash').value)||0;
    var momo = parseFloat(document.getElementById('apm_momo').value)||0;
    var bank = parseFloat(document.getElementById('apm_bank').value)||0;
    var loan = parseFloat(document.getElementById('apm_loan').value)||0;
    var total_add    = cash + momo + bank + loan;
    var new_prepaid  = _apmPrepaid + total_add;
    var new_rem      = Math.max(0, _apmTotal - new_prepaid);
    var over         = new_prepaid > _apmTotal + 1;
    document.getElementById('apm_rem_val').textContent = 'RWF ' + Math.round(new_rem).toLocaleString();
    document.getElementById('apm_rem_row').className   = 'pay-remaining ' + (over ? 'invalid' : (new_rem <= 0 ? 'valid' : ''));
    document.getElementById('apm_submit').disabled     = (total_add <= 0 || over);
}
function submitAddPayment() {
    var btn = document.getElementById('apm_submit');
    btn.disabled = true; btn.textContent = 'Saving…';
    var fd = new FormData();
    fd.append('add_payment','1'); fd.append('order_id',_apmId);
    fd.append('add_cash', document.getElementById('apm_cash').value);
    fd.append('add_momo', document.getElementById('apm_momo').value);
    fd.append('add_bank', document.getElementById('apm_bank').value);
    fd.append('add_loan', document.getElementById('apm_loan').value);
    fetch('orders.php',{method:'POST',body:fd})
        .then(function(r){ return r.json(); })
        .then(function(res){
            showToast(res.message, res.success);
            if (res.success) {
                closeModal('addPaymentModal');
                var rc = document.getElementById('rem_cell_'+_apmId);
                if (rc) {
                    rc.className   = 'remaining-pill ' + (res.new_remaining<=0 ? 'rp-zero' : 'rp-part');
                    rc.textContent = 'RWF ' + Math.round(res.new_remaining).toLocaleString();
                }
            } else { btn.disabled=false; btn.textContent='Record Payment'; }
        })
        .catch(function(){ showToast('Network error.',false); btn.disabled=false; btn.textContent='Record Payment'; });
}

// ── Add Product ───────────────────────────────────────────────────────────────
var _aprodId=0, _aprodPid=0, _aprodDiv=1, _aprodSrc='wh', _aprodTimer;
function openAddProductModal(id, orderNum) {
    _aprodId=id; _aprodPid=0;
    document.getElementById('aprod_order_num').textContent      = orderNum;
    document.getElementById('aprod_search').value               = '';
    document.getElementById('aprod_results').style.display      = 'none';
    document.getElementById('aprod_results').innerHTML          = '';
    document.getElementById('aprod_selected_card').style.display= 'none';
    document.getElementById('aprod_form').style.display         = 'none';
    document.getElementById('aprod_qty').value                  = '';
    document.getElementById('aprod_price').value                = '';
    document.getElementById('aprod_item_total').textContent     = 'RWF 0';
    document.getElementById('aprod_submit').disabled            = true;
    openModal('addProductModal');
}
function aprodSearch(q) {
    clearTimeout(_aprodTimer);
    var box = document.getElementById('aprod_results');
    if (q.length < 2) { box.style.display='none'; return; }
    box.innerHTML = '<div style="display:flex;align-items:center;gap:8px;padding:10px 12px;font-size:13px;color:var(--secondary);"><span style="width:14px;height:14px;border:2px solid var(--gray-300);border-top-color:var(--primary);border-radius:50%;display:inline-block;animation:aprod-spin .7s linear infinite;flex-shrink:0;"></span>Searching…</div>';
    box.style.display = 'block';
    _aprodTimer = setTimeout(function(){
        fetch('order_new.php?action=search_products&q=' + encodeURIComponent(q))
            .then(function(r){ return r.json(); })
            .then(function(rows){
                if (!rows.length) {
                    box.innerHTML = '<div style="padding:10px 12px;font-size:13px;color:var(--secondary);">No products found.</div>';
                } else {
                    box.innerHTML = rows.map(function(p){
                        var stock = [];
                        if (p.stock_qty>0) stock.push(p.stock_qty+' pkg (WH, RWF '+Number(p.default_price).toLocaleString()+')');
                        if (p.rt_qty>0)    stock.push(p.rt_qty+' pcs (Retail, RWF '+Number(p.rt_price).toLocaleString()+')');
                        return '<div style="padding:8px 12px;cursor:pointer;font-size:13px;border-bottom:1px solid var(--gray-100);" onmouseover="this.style.background=\'var(--gray-50)\'" onmouseout="this.style.background=\'\'" onclick=\'aprodSelectProduct('+JSON.stringify(p)+')\'>'
                            + '<strong>'+escH(p.name)+'</strong>'
                            + (p.category ? ' <span style="font-size:11px;color:var(--secondary);">'+escH(p.category)+'</span>' : '')
                            + '<div style="font-size:11px;color:var(--secondary);margin-top:2px;">'+escH(stock.join(' · '))+'</div>'
                            + '</div>';
                    }).join('');
                }
                box.style.display = 'block';
            });
    }, 280);
}
function aprodSelectProduct(p) {
    _aprodPid = p.id;
    _aprodDiv = parseInt(p.ppp)||1;
    _aprodSrc = p.stock_qty>0 ? 'wh' : 'rt';
    var stockInfo = [];
    if (p.stock_qty>0) stockInfo.push(p.stock_qty+' pkg in warehouse');
    if (p.rt_qty>0)    stockInfo.push(p.rt_qty+' pcs retail');
    document.getElementById('aprod_sel_name').textContent  = p.name + (p.category ? ' — '+p.category : '');
    document.getElementById('aprod_sel_stock').textContent = stockInfo.join(' · ');
    document.getElementById('aprod_selected_card').style.display = 'block';
    document.getElementById('aprod_form').style.display          = 'block';
    document.getElementById('aprod_results').style.display       = 'none';
    document.getElementById('aprod_price').value = p.stock_qty>0 ? p.default_price : p.rt_price;
    document.getElementById('aprod_qty').value   = '';
    document.getElementById('aprod_qty').focus();
    aprodCalc();
}
function aprodCalc() {
    var qty   = parseFloat(document.getElementById('aprod_qty').value)||0;
    var price = parseFloat(document.getElementById('aprod_price').value)||0;
    document.getElementById('aprod_item_total').textContent = 'RWF ' + Math.round(qty*price).toLocaleString();
    document.getElementById('aprod_submit').disabled = !(_aprodPid>0 && qty>0 && price>0);
}
function submitAddProduct() {
    var btn = document.getElementById('aprod_submit');
    btn.disabled=true; btn.textContent='Adding…';
    var fd = new FormData();
    fd.append('add_product_to_order','1'); fd.append('order_id',_aprodId);
    fd.append('product_id',    _aprodPid);
    fd.append('quantity',      document.getElementById('aprod_qty').value);
    fd.append('selling_price', document.getElementById('aprod_price').value);
    fd.append('level_divisor', _aprodDiv);
    fd.append('stock_source',  _aprodSrc);
    fetch('orders.php',{method:'POST',body:fd})
        .then(function(r){ return r.json(); })
        .then(function(res){
            showToast(res.message, res.success);
            if (res.success) {
                closeModal('addProductModal');
                var row = document.getElementById('order_row_'+_aprodId);
                if (row) {
                    var itemsCell = row.cells[2];
                    var d = document.createElement('div');
                    d.style.cssText = 'font-size:13px;line-height:1.6;';
                    d.innerHTML = '<strong>'+escH(res.product_name)+'</strong>'
                        + '<span class="col-sub">&nbsp;&times;'+Number(res.quantity).toLocaleString()+'</span>';
                    itemsCell.appendChild(d);
                    row.cells[3].innerHTML = '<strong>RWF '+Math.round(res.new_total).toLocaleString()+'</strong>';
                }
            } else { btn.disabled=false; btn.textContent='Add to Order'; }
        })
        .catch(function(){ showToast('Network error.',false); btn.disabled=false; btn.textContent='Add to Order'; });
}

// ── Close Order ───────────────────────────────────────────────────────────────
var _coId = 0;
function openCloseOrderModal(id, orderNum) {
    _coId = id;
    document.getElementById('co_order_num').textContent    = orderNum;
    document.getElementById('co_submit_btn').disabled      = false;
    document.getElementById('co_submit_btn').textContent   = 'Confirm Close';
    openModal('closeOrderModal');
}
function submitCloseOrder() {
    var btn = document.getElementById('co_submit_btn');
    btn.disabled=true; btn.textContent='Closing…';
    var fd = new FormData();
    fd.append('close_order','1'); fd.append('order_id',_coId);
    fetch('orders.php',{method:'POST',body:fd})
        .then(function(r){ return r.json(); })
        .then(function(res){
            showToast(res.message, res.success);
            if (res.success) {
                closeModal('closeOrderModal');
                var sc = document.querySelector('.status_cell_'+_coId);
                var ac = document.querySelector('.actions_cell_'+_coId);
                if (sc) sc.innerHTML = '<span class="badge badge-closed">Closed</span>';
                if (ac) {
                    var items = ac.querySelectorAll('.act-menu .act-item');
                    Array.from(items).slice(2).forEach(function(el){ el.remove(); });
                    ac.querySelectorAll('.act-menu .act-menu-sep').forEach(function(el){ el.remove(); });
                }
                adjustStat('pending', -1);
            } else { btn.disabled=false; btn.textContent='Confirm Close'; }
        })
        .catch(function(){ showToast('Network error.',false); btn.disabled=false; btn.textContent='Confirm Close'; });
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
