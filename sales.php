<?php
ob_start();
require_once 'config.php';

if (!isLoggedIn()) redirect('login.php');
if (!hasPermission('sales')) { $_SESSION['flash_error'] = "You don't have permission to access Sales."; redirect('dashboard.php'); }

$cid_sql = cidSql(); $cid_and = cidAnd();

// ── DELETE Bulk Sale ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_bulk_sale'])) {
    $id = (int)$_POST['sale_id'];
    $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM sales_bulk WHERE id=$id"));
    if ($row) {
        mysqli_begin_transaction($conn);
        $ok = true;

        $ok = (bool)mysqli_query($conn, "UPDATE stock SET quantity = quantity + {$row['quantity']} WHERE product_id = {$row['product_id']} $cid_and");

        $del_client_id = 0;
        if ($ok && $row['loan_amount'] > 0) {
            $client_e = mysqli_real_escape_string($conn, $row['customer_name']);
            $loan_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id, client_id FROM loans WHERE product_id={$row['product_id']} AND client='$client_e' AND amount={$row['loan_amount']} AND loan_date='{$row['sale_date']}' LIMIT 1"));
            $del_client_id = $loan_row ? (int)$loan_row['client_id'] : 0;
            $ok = (bool)mysqli_query($conn, "DELETE FROM loans WHERE product_id={$row['product_id']} AND client='$client_e' AND amount={$row['loan_amount']} AND loan_date='{$row['sale_date']}' LIMIT 1");
            if ($ok && $del_client_id > 0) {
                $ok = (bool)mysqli_query($conn, "
                    UPDATE loan_clients lc
                    JOIN (
                        SELECT COUNT(DISTINCT l.id)       AS cnt,
                               COALESCE(SUM(l.amount),0)  AS loaned,
                               COALESCE(SUM(lp_s.paid),0) AS paid_sum
                        FROM loans l
                        LEFT JOIN (SELECT loan_id, SUM(amount_paid) AS paid FROM loan_payments GROUP BY loan_id) lp_s
                               ON lp_s.loan_id = l.id
                        WHERE l.client_id = $del_client_id
                    ) agg
                    SET lc.total_loans   = agg.cnt,
                        lc.paid_amount   = agg.paid_sum,
                        lc.unpaid_amount = agg.loaned - agg.paid_sum
                    WHERE lc.id = $del_client_id
                ");
            }
        }

        if ($ok) $ok = (bool)mysqli_query($conn, "DELETE FROM sales_bulk WHERE id=$id");

        if ($ok) {
            mysqli_commit($conn);
            // require_once 'stock_value.php';
            // recalcStockValue($conn, cid(), (int)$row['product_id']);
            touchCacheStore($conn, 'products');
            if ($del_client_id > 0) touchCacheStore($conn, 'clients');

            $_SESSION['flash_success'] = "Bulk sale deleted and stock restored.";
            logActivity($conn, (int)$_SESSION['user_id'], 'Delete Bulk Sale', "Deleted bulk sale #{$id} for " . ($row['customer_name'] ?: 'unknown'),
                'sales_bulk', $id,
                ['product_id' => $row['product_id'], 'quantity' => $row['quantity'], 'package_price' => $row['package_price'], 'total_amount' => $row['total_amount'], 'customer_name' => $row['customer_name'], 'sale_date' => $row['sale_date']],
                []
            );
        } else {
            mysqli_rollback($conn);
            $_SESSION['flash_error'] = "Could not delete bulk sale. Please try again.";
        }
    }
    header("Location: sales.php?tab=bulk"); exit;
}

// ── EDIT Bulk Sale ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_bulk_sale'])) {
    $id            = (int)$_POST['sale_id'];
    $new_qty       = max(1, (int)$_POST['quantity']);
    $new_price     = max(0, (float)$_POST['selling_price']);
    $customer_name = mysqli_real_escape_string($conn, trim($_POST['customer_name']));
    $cash_amount   = max(0, (float)$_POST['cash_amount']);
    $momo_amount   = max(0, (float)$_POST['momo_amount']);
    $loan_amount   = max(0, (float)$_POST['loan_amount']);
    $sale_date     = mysqli_real_escape_string($conn, $_POST['sale_date']);
    $total_amount  = $new_qty * $new_price;

    $old = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM sales_bulk WHERE id=$id"));
    if ($old) {
        $qty_diff = $old['quantity'] - $new_qty;
        if ($qty_diff !== 0) {
            mysqli_query($conn, "UPDATE stock SET quantity = quantity + $qty_diff WHERE product_id = {$old['product_id']} $cid_and");
        }
        // Keep the per-unit cost frozen from sale time; just scale it to the new quantity.
        $old_unit_cost = $old['quantity'] > 0 ? $old['cost_total'] / $old['quantity'] : 0;
        $new_cost_total = round($old_unit_cost * $new_qty, 2);
        mysqli_query($conn, "UPDATE sales_bulk SET quantity=$new_qty, package_price=$new_price, total_amount=$total_amount, cost_total=$new_cost_total, customer_name='$customer_name', cash_amount=$cash_amount, momo_amount=$momo_amount, loan_amount=$loan_amount, sale_date='$sale_date' WHERE id=$id");
        // require_once 'stock_value.php';
        // recalcStockValue($conn, cid(), (int)$old['product_id']);
        touchCacheStore($conn, 'products');
        $_SESSION['flash_success'] = "Bulk sale updated.";
        logActivity($conn, (int)$_SESSION['user_id'], 'Edit Bulk Sale', "Edited bulk sale #{$id}",
            'sales_bulk', $id,
            ['quantity' => $old['quantity'], 'package_price' => $old['package_price'], 'total_amount' => $old['total_amount'], 'customer_name' => $old['customer_name'], 'cash_amount' => $old['cash_amount'], 'momo_amount' => $old['momo_amount'], 'loan_amount' => $old['loan_amount'], 'sale_date' => $old['sale_date']],
            ['quantity' => $new_qty, 'package_price' => $new_price, 'total_amount' => $total_amount, 'customer_name' => $customer_name, 'cash_amount' => $cash_amount, 'momo_amount' => $momo_amount, 'loan_amount' => $loan_amount, 'sale_date' => $sale_date]
        );
    }
    header("Location: sales.php?tab=bulk"); exit;
}

// ── DELETE Retail Sale ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_retail_sale'])) {
    $id = (int)$_POST['sale_id'];
    $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM sales_retail WHERE id=$id"));
    if ($row) {
        mysqli_begin_transaction($conn);
        $ok = true;

        $ok = (bool)mysqli_query($conn, "UPDATE retail_stock SET pieces_quantity = pieces_quantity + {$row['pieces_sold']} WHERE product_id = {$row['product_id']} $cid_and");

        $del_client_id = 0;
        if ($ok && $row['loan_amount'] > 0) {
            $client_e = mysqli_real_escape_string($conn, $row['customer_name']);
            $loan_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id, client_id FROM loans WHERE product_id={$row['product_id']} AND client='$client_e' AND amount={$row['loan_amount']} AND loan_date='{$row['sale_date']}' LIMIT 1"));
            $del_client_id = $loan_row ? (int)$loan_row['client_id'] : 0;
            $ok = (bool)mysqli_query($conn, "DELETE FROM loans WHERE product_id={$row['product_id']} AND client='$client_e' AND amount={$row['loan_amount']} AND loan_date='{$row['sale_date']}' LIMIT 1");
            if ($ok && $del_client_id > 0) {
                $ok = (bool)mysqli_query($conn, "
                    UPDATE loan_clients lc
                    JOIN (
                        SELECT COUNT(DISTINCT l.id)       AS cnt,
                               COALESCE(SUM(l.amount),0)  AS loaned,
                               COALESCE(SUM(lp_s.paid),0) AS paid_sum
                        FROM loans l
                        LEFT JOIN (SELECT loan_id, SUM(amount_paid) AS paid FROM loan_payments GROUP BY loan_id) lp_s
                               ON lp_s.loan_id = l.id
                        WHERE l.client_id = $del_client_id
                    ) agg
                    SET lc.total_loans   = agg.cnt,
                        lc.paid_amount   = agg.paid_sum,
                        lc.unpaid_amount = agg.loaned - agg.paid_sum
                    WHERE lc.id = $del_client_id
                ");
            }
        }

        if ($ok) $ok = (bool)mysqli_query($conn, "DELETE FROM sales_retail WHERE id=$id");

        if ($ok) {
            mysqli_commit($conn);
            // require_once 'stock_value.php';
            // recalcStockValue($conn, cid(), (int)$row['product_id']);
            touchCacheStore($conn, 'products');
            if ($del_client_id > 0) touchCacheStore($conn, 'clients');

            $_SESSION['flash_success'] = "Retail sale deleted and stock restored.";
            logActivity($conn, (int)$_SESSION['user_id'], 'Delete Retail Sale', "Deleted retail sale #{$id} for " . ($row['customer_name'] ?: 'unknown'),
                'sales_retail', $id,
                ['product_id' => $row['product_id'], 'pieces_sold' => $row['pieces_sold'], 'retail_price' => $row['retail_price'], 'total_amount' => $row['total_amount'], 'customer_name' => $row['customer_name'], 'sale_date' => $row['sale_date']],
                []
            );
        } else {
            mysqli_rollback($conn);
            $_SESSION['flash_error'] = "Could not delete retail sale. Please try again.";
        }
    }
    header("Location: sales.php?tab=retail"); exit;
}

// ── EDIT Retail Sale ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_retail_sale'])) {
    $id            = (int)$_POST['sale_id'];
    $new_qty       = max(1, (int)$_POST['pieces_sold']);
    $new_price     = max(0, (float)$_POST['selling_price']);
    $customer_name = mysqli_real_escape_string($conn, trim($_POST['customer_name']));
    $cash_amount   = max(0, (float)$_POST['cash_amount']);
    $momo_amount   = max(0, (float)$_POST['momo_amount']);
    $loan_amount   = max(0, (float)$_POST['loan_amount']);
    $sale_date     = mysqli_real_escape_string($conn, $_POST['sale_date']);
    $total_amount  = $new_qty * $new_price;

    $old = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM sales_retail WHERE id=$id"));
    if ($old) {
        $qty_diff = $old['pieces_sold'] - $new_qty;
        if ($qty_diff !== 0) {
            mysqli_query($conn, "UPDATE retail_stock SET pieces_quantity = pieces_quantity + $qty_diff WHERE product_id = {$old['product_id']} $cid_and");
        }
        // Keep the per-piece cost frozen from sale time; just scale it to the new quantity.
        $old_unit_cost = $old['pieces_sold'] > 0 ? $old['cost_total'] / $old['pieces_sold'] : 0;
        $new_cost_total = round($old_unit_cost * $new_qty, 2);
        mysqli_query($conn, "UPDATE sales_retail SET pieces_sold=$new_qty, retail_price=$new_price, total_amount=$total_amount, cost_total=$new_cost_total, customer_name='$customer_name', cash_amount=$cash_amount, momo_amount=$momo_amount, loan_amount=$loan_amount, sale_date='$sale_date' WHERE id=$id");
        // require_once 'stock_value.php';
        // recalcStockValue($conn, cid(), (int)$old['product_id']);
        touchCacheStore($conn, 'products');
        $_SESSION['flash_success'] = "Retail sale updated.";
        logActivity($conn, (int)$_SESSION['user_id'], 'Edit Retail Sale', "Edited retail sale #{$id}",
            'sales_retail', $id,
            ['pieces_sold' => $old['pieces_sold'], 'retail_price' => $old['retail_price'], 'total_amount' => $old['total_amount'], 'customer_name' => $old['customer_name'], 'cash_amount' => $old['cash_amount'], 'momo_amount' => $old['momo_amount'], 'loan_amount' => $old['loan_amount'], 'sale_date' => $old['sale_date']],
            ['pieces_sold' => $new_qty, 'retail_price' => $new_price, 'total_amount' => $total_amount, 'customer_name' => $customer_name, 'cash_amount' => $cash_amount, 'momo_amount' => $momo_amount, 'loan_amount' => $loan_amount, 'sale_date' => $sale_date]
        );
    }
    header("Location: sales.php?tab=retail"); exit;
}

// ── DELETE External Sale ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_external_sale'])) {
    $id = (int)$_POST['sale_id'];
    $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM sales_external WHERE id=$id"));
    if ($row) {
        if ($row['loan_amount'] > 0) {
            $client_e = mysqli_real_escape_string($conn, $row['customer_name']);
            mysqli_query($conn, "DELETE FROM loans WHERE product_id IS NULL AND client='$client_e' AND amount={$row['loan_amount']} AND loan_date='{$row['sale_date']}' LIMIT 1");
        }
        mysqli_query($conn, "DELETE FROM sales_external WHERE id=$id");
        $_SESSION['flash_success'] = "External sale deleted.";
        logActivity($conn, (int)$_SESSION['user_id'], 'Delete External Sale', "Deleted external sale #{$id} for " . ($row['customer_name'] ?: 'unknown'),
            'sales_external', $id,
            ['product_name' => $row['product_name'], 'quantity' => $row['quantity'], 'unit_price' => $row['unit_price'], 'total_amount' => $row['total_amount'], 'customer_name' => $row['customer_name'], 'sale_date' => $row['sale_date']],
            []
        );
    }
    header("Location: sales.php?tab=external"); exit;
}

// ── EDIT External Sale ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_external_sale'])) {
    $id            = (int)$_POST['sale_id'];
    $product_name  = mysqli_real_escape_string($conn, trim($_POST['product_name']));
    $quantity      = max(1, (int)$_POST['quantity']);
    $unit_price    = max(0, (float)$_POST['unit_price']);
    $customer_name = mysqli_real_escape_string($conn, trim($_POST['customer_name']));
    $cash_amount   = max(0, (float)$_POST['cash_amount']);
    $momo_amount   = max(0, (float)$_POST['momo_amount']);
    $loan_amount   = max(0, (float)$_POST['loan_amount']);
    $my_revenue    = max(0, (float)($_POST['my_revenue'] ?? 0));
    $sale_date     = mysqli_real_escape_string($conn, $_POST['sale_date']);
    $total_amount  = $quantity * $unit_price;

    $old_ext = mysqli_fetch_assoc(mysqli_query($conn, "SELECT product_name,quantity,unit_price,total_amount,customer_name,cash_amount,momo_amount,loan_amount,my_revenue,sale_date FROM sales_external WHERE id=$id")) ?: [];
    mysqli_query($conn, "UPDATE sales_external SET product_name='$product_name', quantity=$quantity, unit_price=$unit_price, total_amount=$total_amount, customer_name='$customer_name', cash_amount=$cash_amount, momo_amount=$momo_amount, loan_amount=$loan_amount, my_revenue=$my_revenue, sale_date='$sale_date' WHERE id=$id");
    $_SESSION['flash_success'] = "External sale updated.";
    logActivity($conn, (int)$_SESSION['user_id'], 'Edit External Sale', "Edited external sale #{$id}",
        'sales_external', $id,
        $old_ext,
        ['product_name' => $product_name, 'quantity' => $quantity, 'unit_price' => $unit_price, 'total_amount' => $total_amount, 'customer_name' => $customer_name, 'cash_amount' => $cash_amount, 'momo_amount' => $momo_amount, 'loan_amount' => $loan_amount, 'my_revenue' => $my_revenue, 'sale_date' => $sale_date]
    );
    header("Location: sales.php?tab=external"); exit;
}

// Handle External Sale (product not from stock — tracking only). One client can take
// several external items in a single sale; each row is inserted separately (loop insert)
// but the overall payment split is attributed to the first item only — see sales.php's
// bulk_sale handler below for the same convention.
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['external_sale'])) {
    $customer_name = trim($_POST['ext_customer_name'] ?? '');
    if ($customer_name === '') $customer_name = 'client';
    $customer_name = mysqli_real_escape_string($conn, $customer_name);
    $phone         = mysqli_real_escape_string($conn, trim($_POST['ext_phone'] ?? ''));
    $cash_amount   = max(0, (float)($_POST['ext_cash_amount'] ?? 0));
    $momo_amount   = max(0, (float)($_POST['ext_momo_amount'] ?? 0));
    $loan_amount   = max(0, (float)($_POST['ext_loan_amount'] ?? 0));

    $items = json_decode($_POST['items_json'] ?? '[]', true) ?: [];
    $cart  = [];
    foreach ($items as $it) {
        $pname = trim((string)($it['product_name'] ?? ''));
        $qty   = (int)($it['quantity'] ?? 0);
        $price = (float)($it['unit_price'] ?? 0);
        if ($pname !== '' && $qty > 0 && $price > 0) {
            $cart[] = [
                'product_id'   => max(0, (int)($it['product_id'] ?? 0)),
                'product_name' => $pname,
                'quantity'     => $qty,
                'unit_price'   => $price,
                'my_revenue'   => max(0, (float)($it['my_revenue'] ?? 0)),
                'owner_name'   => trim((string)($it['owner_name'] ?? '')),
                'owner_phone'  => trim((string)($it['owner_phone'] ?? '')),
            ];
        }
    }
    if (empty($cart)) saleResp(false, "Add at least one product to the sale.", 'external');

    $total_amount = 0.0;
    foreach ($cart as $it) $total_amount += $it['quantity'] * $it['unit_price'];
    $total_amount = round($total_amount, 2);

    if (abs($cash_amount + $momo_amount + $loan_amount - $total_amount) > 1)
        saleResp(false, "Payment split must equal total (RWF " . number_format($total_amount, 0) . ").", 'external');
    if ($loan_amount > 0 && empty($phone))
        saleResp(false, "Client phone is required when loan amount is set.", 'external');

    $sold_by   = (int)$_SESSION['user_id'];
    $loan_date = date('Y-m-d');
    $ph_lc_ext = $phone !== '' ? "'$phone'" : 'NULL';

    // The loan record must reflect everything the client took, not just the first cart
    // item — so when there's more than one product, point it at no single product_id and
    // describe the whole cart in product_name instead.
    $loan_product_id_sql = 'NULL';
    $loan_product_name   = '';
    $loan_cart_sql       = 'NULL';
    $loan_qty            = array_sum(array_column($cart, 'quantity'));
    if (count($cart) === 1 && $cart[0]['product_id'] > 0) {
        $loan_product_id_sql = $cart[0]['product_id'];
    }
    if ($loan_amount > 0) {
        $loan_names = [];
        $loan_cart_items = [];
        foreach ($cart as $it) {
            $loan_names[] = $it['product_name'] . " ({$it['quantity']})";
            $loan_cart_items[] = [
                'product_id' => $it['product_id'], 'name' => $it['product_name'],
                'quantity' => $it['quantity'], 'price' => $it['unit_price'],
                'item_total' => round($it['quantity'] * $it['unit_price'], 2),
            ];
        }
        $loan_product_name = mysqli_real_escape_string($conn, implode(', ', $loan_names));
        $loan_cart_sql = "'" . mysqli_real_escape_string($conn, json_encode($loan_cart_items)) . "'";
    }

    mysqli_begin_transaction($conn);
    $ok = true;

    foreach ($cart as $i => $it) {
        $product_name    = mysqli_real_escape_string($conn, $it['product_name']);
        $ext_product_id  = $it['product_id'];
        $quantity        = $it['quantity'];
        $unit_price      = $it['unit_price'];
        $my_revenue      = $it['my_revenue'];
        $item_total      = round($quantity * $unit_price, 2);
        $owner_name      = mysqli_real_escape_string($conn, $it['owner_name']);
        $owner_phone     = mysqli_real_escape_string($conn, $it['owner_phone']);
        $row_cash        = $i === 0 ? $cash_amount : 0;
        $row_momo        = $i === 0 ? $momo_amount : 0;
        $row_loan        = $i === 0 ? $loan_amount : 0;

        // Resolve owner for this item
        $owner_id_val   = 'NULL';
        $need_new_owner = false;
        $op = $owner_phone !== '' ? "'$owner_phone'" : 'NULL';
        if ($owner_name !== '') {
            $existing = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT id FROM product_owners WHERE name='$owner_name' AND (phone='$owner_phone' OR (phone IS NULL AND '$owner_phone'=''))"
            ));
            if ($existing) { $owner_id_val = (int)$existing['id']; }
            else            { $need_new_owner = true; }
        }
        if ($need_new_owner) {
            $ok = (bool)mysqli_query($conn, "INSERT INTO product_owners (company_id, name, phone) VALUES ($cid_sql, '$owner_name', $op)");
            if ($ok) $owner_id_val = (int)mysqli_insert_id($conn);
        }
        if (!$ok) break;

        $ok = (bool)mysqli_query($conn, "INSERT INTO sales_external (company_id, product_name, owner_id, quantity, unit_price, total_amount, cash_amount, momo_amount, loan_amount, my_revenue, customer_name, phone, sale_date, sold_by)
                       VALUES ($cid_sql, '$product_name', $owner_id_val, $quantity, $unit_price, $item_total, $row_cash, $row_momo, $row_loan, $my_revenue, '$customer_name', '$phone', CURDATE(), $sold_by)");
        if (!$ok) break;
        $ext_sale_id = (int)mysqli_insert_id($conn);

        if ($i === 0 && $loan_amount > 0) {
            $ok = (bool)mysqli_query($conn, "INSERT INTO loans (company_id, product_id, product_name, cart, qty, amount, client, phone, loan_date, given_by, external_id) VALUES ($cid_sql, $loan_product_id_sql, '$loan_product_name', $loan_cart_sql, $loan_qty, $loan_amount, '$customer_name', '$phone', '$loan_date', $sold_by, $ext_sale_id)");
            if ($ok) {
                $new_loan_id = (int)mysqli_insert_id($conn);
                $ok = (bool)mysqli_query($conn, "
                    INSERT INTO loan_clients (company_id, name, phone, total_loans, paid_amount, unpaid_amount)
                    VALUES ($cid_sql, '$customer_name', $ph_lc_ext, 1, 0, $loan_amount)
                    ON DUPLICATE KEY UPDATE
                        id            = LAST_INSERT_ID(id),
                        total_loans   = total_loans   + 1,
                        unpaid_amount = unpaid_amount + $loan_amount
                ");
                $new_client_id = $ok ? (int)mysqli_insert_id($conn) : 0;
                if ($ok) $ok = (bool)mysqli_query($conn, "UPDATE loans SET client_id = $new_client_id WHERE id = $new_loan_id");
           $amount_paid=$cash_amount+$momo_amount;
             if($amount_paid>0){
         mysqli_query($conn, "INSERT INTO client_payments (company_id, client_id, amount, payment_date, recorded_by, note) VALUES ($cid_sql, $new_client_id, $amount_paid, '$loan_date', $sold_by, 'Partial payments On LoanId# $new_loan_id')");
             }
                }
            if (!$ok) break;
        }

        // Auto-add to wishlist — increment client_count if already pending, else insert
        $wl = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM wishlist WHERE product_name='$product_name' AND status='pending' LIMIT 1"));
        if ($wl) {
            mysqli_query($conn, "UPDATE wishlist SET client_count = client_count + 1 WHERE id=" . (int)$wl['id']);
        } else {
            mysqli_query($conn, "INSERT INTO wishlist (product_name, client_count) VALUES ('$product_name', 1)");
        }
    }

    if ($ok) {
        mysqli_commit($conn);
        touchCacheStore($conn, 'recent_sales_external');
        if ($loan_amount > 0) touchCacheStore($conn, 'clients');
        $parts = [];
        if ($cash_amount > 0) $parts[] = "Cash: RWF " . number_format($cash_amount, 0);
        if ($momo_amount > 0) $parts[] = "Momo: RWF " . number_format($momo_amount, 0);
        if ($loan_amount > 0) $parts[] = "Loan: RWF " . number_format($loan_amount, 0);
        $n = count($cart);
        saleResp(true, "External sale recorded ($n item" . ($n > 1 ? 's' : '') . ") — " . implode(", ", $parts), 'external');
    } else {
        mysqli_rollback($conn);
        saleResp(false, "Sale could not be recorded. Please try again.", 'external');
    }
}

// ── AJAX-aware response helper ────────────────────────────────────────────────
function saleResp(bool $ok, string $msg, string $tab = '') {
    if (!empty($_POST['ajax'])) {
        ob_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => $ok, 'message' => $msg]);
        exit;
    }
    if ($ok) { $_SESSION['flash_success'] = $msg; if ($tab) $_SESSION['flash_sale_type'] = $tab; }
    else      { $_SESSION['flash_error']   = $msg; }
    header('Location: sales.php' . ($tab ? '?tab='.$tab : ''));
    exit;
}

// Handle Bulk Sale — one client can take several products in a single sale. Each cart
// item is inserted as its own sales_bulk row (loop insert), but the overall payment
// split (cash/momo/loan) is attributed to the first item's row only; other rows carry
// zero payment amounts so per-row totals stay correct while SUM(cash/momo/loan) across
// the whole sale still equals what was actually collected.
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['bulk_sale'])) {
    $customer_name = trim($_POST['customer_name'] ?? '');
    if ($customer_name === '') $customer_name = 'client';
    $customer_name = mysqli_real_escape_string($conn, $customer_name);
    $phone         = mysqli_real_escape_string($conn, trim($_POST['phone'] ?? ''));
    $cash_amount   = max(0, (float)($_POST['cash_amount'] ?? 0));
    $momo_amount   = max(0, (float)($_POST['momo_amount'] ?? 0));
    $loan_amount   = max(0, (float)($_POST['loan_amount'] ?? 0));

    $items = json_decode($_POST['items_json'] ?? '[]', true) ?: [];
    $cart  = [];
    foreach ($items as $it) {
        $pid   = (int)($it['product_id'] ?? 0);
        $qty   = (int)($it['quantity'] ?? 0);
        $price = (float)($it['selling_price'] ?? 0);
        if ($pid > 0 && $qty > 0 && $price > 0) {
            $cart[] = ['product_id' => $pid, 'quantity' => $qty, 'selling_price' => $price, 'level_divisor' => max(1, (int)($it['level_divisor'] ?? 1))];
        }
    }
    if (empty($cart)) saleResp(false, "Add at least one product to the sale.", 'bulk');

    $total_amount = 0.0;
    foreach ($cart as $it) $total_amount += $it['quantity'] * $it['selling_price'];
    $total_amount = round($total_amount, 2);

    if (abs($cash_amount + $momo_amount + $loan_amount - $total_amount) > 1)
        saleResp(false, "Payment split must equal total (RWF " . number_format($total_amount, 0) . ").", 'bulk');
    if ($loan_amount > 0 && empty($phone))
        saleResp(false, "Client phone is required when loan amount is set.", 'bulk');

    // Aggregate packages needed per product (a product may appear at more than one level)
    $needed_pkgs = [];
    foreach ($cart as $it) {
        $needed_pkgs[$it['product_id']] = ($needed_pkgs[$it['product_id']] ?? 0) + (int)ceil($it['quantity'] / $it['level_divisor']);
    }
    foreach ($needed_pkgs as $pid => $needed) {
        $stock = mysqli_fetch_assoc(mysqli_query($conn, "SELECT quantity FROM stock WHERE product_id = $pid $cid_and"));
        if (!$stock || $stock['quantity'] < $needed)
            saleResp(false, "Insufficient stock available for one of the selected products.", 'bulk');
    }

    $sold_by   = (int)$_SESSION['user_id'];
    $loan_date = date('Y-m-d');
    $ph_lc     = $phone !== '' ? "'$phone'" : 'NULL';

    // The loan record must reflect everything the client took, not just the first cart
    // item — so when there's more than one product, point it at no single product_id,
    // describe the whole cart in product_name, and store the itemized list in `cart`
    // (same fallback loans.php already uses for external sales, plus structured detail).
    $loan_product_names = [];
    $loan_cart_sql = 'NULL';
    if ($loan_amount > 0) {
        $cart_pids = array_unique(array_column($cart, 'product_id'));
        $names_by_id = [];
        $pids_sql = implode(',', array_map('intval', $cart_pids));
        $nres = mysqli_query($conn, "SELECT id, name FROM products WHERE id IN ($pids_sql)");
        while ($nr = mysqli_fetch_assoc($nres)) $names_by_id[$nr['id']] = $nr['name'];
        $loan_cart_items = [];
        foreach ($cart as $it) {
            $pname = $names_by_id[$it['product_id']] ?? ('#' . $it['product_id']);
            $loan_product_names[] = "$pname ({$it['quantity']})";
            $loan_cart_items[] = [
                'product_id' => $it['product_id'], 'name' => $pname,
                'quantity' => $it['quantity'], 'price' => $it['selling_price'],
                'item_total' => round($it['quantity'] * $it['selling_price'], 2),
            ];
        }
        $loan_cart_sql = "'" . mysqli_real_escape_string($conn, json_encode($loan_cart_items)) . "'";
    }
    $loan_product_id_sql = count($cart) === 1 ? $cart[0]['product_id'] : 'NULL';
    $loan_qty            = array_sum(array_column($cart, 'quantity'));
    $loan_product_name   = mysqli_real_escape_string($conn, implode(', ', $loan_product_names));

    mysqli_begin_transaction($conn);
    $ok = true;
    $touched_products = [];

    foreach ($cart as $i => $it) {
        $product_id    = $it['product_id'];
        $quantity      = $it['quantity'];
        $selling_price = $it['selling_price'];
        $level_divisor = $it['level_divisor'];
        $item_total    = round($quantity * $selling_price, 2);
        $cost          = bulkSaleCost($conn, $product_id, $loan_date, $quantity, $level_divisor);
        $cost_total    = $cost['cost_total'];
        $purchase_id_sql = $cost['purchase_id'] !== null ? $cost['purchase_id'] : 'NULL';
        $packages_to_deduct = (int)ceil($quantity / $level_divisor);
        $row_cash = $i === 0 ? $cash_amount : 0;
        $row_momo = $i === 0 ? $momo_amount : 0;
        $row_loan = $i === 0 ? $loan_amount : 0;

        $ok = (bool)mysqli_query($conn, "INSERT INTO sales_bulk (company_id, product_id, quantity, level_divisor, package_price, total_amount, cost_total, purchase_id, sale_date, customer_name, cash_amount, momo_amount, loan_amount, has_loan, amount, sold_by)
                       VALUES ($cid_sql, $product_id, $quantity, $level_divisor, $selling_price, $item_total, $cost_total, $purchase_id_sql, CURDATE(), '$customer_name', $row_cash, $row_momo, $row_loan, " . ($row_loan > 0 ? 1 : 0) . ", $row_loan, $sold_by)");
        if (!$ok) break;
        $bulk_sale_id = (int)mysqli_insert_id($conn);
        $touched_products[$product_id] = true;

        $ok = (bool)mysqli_query($conn, "UPDATE stock SET quantity = quantity - $packages_to_deduct WHERE product_id = $product_id $cid_and");
        if (!$ok) break;

        if ($i === 0 && $loan_amount > 0) {
            $ok = (bool)mysqli_query($conn, "INSERT INTO loans (company_id, product_id, product_name, cart, qty, amount, client, phone, loan_date, given_by, bulk_id) VALUES ($cid_sql, $loan_product_id_sql, '$loan_product_name', $loan_cart_sql, $loan_qty, $loan_amount, '$customer_name', '$phone', '$loan_date', $sold_by, $bulk_sale_id)");
            if ($ok) {
                $new_loan_id = (int)mysqli_insert_id($conn);
                $ok = (bool)mysqli_query($conn, "
                    INSERT INTO loan_clients (company_id, name, phone, total_loans, paid_amount, unpaid_amount)
                    VALUES ($cid_sql, '$customer_name', $ph_lc, 1, 0, $loan_amount)
                    ON DUPLICATE KEY UPDATE
                        id            = LAST_INSERT_ID(id),
                        total_loans   = total_loans   + 1,
                        unpaid_amount = unpaid_amount + $loan_amount
                ");
                $new_client_id = $ok ? (int)mysqli_insert_id($conn) : 0;
                if ($ok) $ok = (bool)mysqli_query($conn, "UPDATE loans SET client_id = $new_client_id WHERE id = $new_loan_id");
             $amount_paid=$cash_amount+$momo_amount;
             if($amount_paid>0){
                mysqli_query($conn, "INSERT INTO client_payments (company_id, client_id, amount, payment_date, recorded_by, note) VALUES ($cid_sql, $new_client_id, $amount_paid, '$loan_date', $sold_by, 'Partial payments On LoanId# $new_loan_id')");
            //  mysqli_query($conn, "INSERT INTO client_payments (company_id, client_id, amount, payment_date, recorded_by,note) VALUES ($cid_sql, $new_client_id, $amount_paid, '$loan_date', $sold_by,'Partial payments On LoanId#'.$new_loan_id)");
             }
                }
            if (!$ok) break;
        }
    }

    if ($ok) {
        mysqli_commit($conn);
      //  require_once 'stock_value.php';
        // foreach (array_keys($touched_products) as $pid) recalcStockValue($conn, cid(), (int)$pid);
        touchCacheStore($conn, 'products');
        touchCacheStore($conn, 'recent_sales_bulk');
        if ($loan_amount > 0) touchCacheStore($conn, 'clients');

        $parts = [];
        if ($cash_amount > 0) $parts[] = "Cash: RWF " . number_format($cash_amount, 0);
        if ($momo_amount > 0) $parts[] = "Momo: RWF " . number_format($momo_amount, 0);
        if ($loan_amount > 0) $parts[] = "Loan: RWF " . number_format($loan_amount, 0);
        $n = count($cart);
        saleResp(true, "Bulk sale recorded ($n item" . ($n > 1 ? 's' : '') . ") — " . implode(", ", $parts), 'bulk');
    } else {
        mysqli_rollback($conn);
        saleResp(false, "Sale could not be recorded. Please try again.", 'bulk');
    }
}

// Handle Retail Sale — one client can take several products in a single sale. Each cart
// item becomes its own sales_retail row (loop insert); the overall payment split is
// attributed to the first item's row only, matching the bulk_sale handler's convention.
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['retail_sale'])) {
    $customer_name = trim($_POST['customer_name'] ?? '');
    if ($customer_name === '') $customer_name = 'client';
    $customer_name = mysqli_real_escape_string($conn, $customer_name);
    $phone         = mysqli_real_escape_string($conn, trim($_POST['phone'] ?? ''));
    $cash_amount   = max(0, (float)($_POST['cash_amount'] ?? 0));
    $momo_amount   = max(0, (float)($_POST['momo_amount'] ?? 0));
    $loan_amount   = max(0, (float)($_POST['loan_amount'] ?? 0));

    $items = json_decode($_POST['items_json'] ?? '[]', true) ?: [];
    $cart  = [];
    foreach ($items as $it) {
        $pid   = (int)($it['product_id'] ?? 0);
        $qty   = (int)($it['pieces_sold'] ?? 0);
        $price = (float)($it['selling_price'] ?? 0);
        if ($pid > 0 && $qty > 0 && $price > 0) {
            $cart[] = ['product_id' => $pid, 'qty_sold' => $qty, 'selling_price' => $price, 'level_multiplier' => max(1, (int)($it['level_multiplier'] ?? 1))];
        }
    }
    if (empty($cart)) saleResp(false, "Add at least one product to the sale.", 'retail');

    $total_amount = 0.0;
    foreach ($cart as $it) $total_amount += $it['qty_sold'] * $it['selling_price'];
    $total_amount = round($total_amount, 2);

    if (abs($cash_amount + $momo_amount + $loan_amount - $total_amount) > 1)
        saleResp(false, "Payment split must equal total (RWF " . number_format($total_amount, 0) . ").", 'retail');
    if ($loan_amount > 0 && empty($phone))
        saleResp(false, "Client phone is required when loan amount is set.", 'retail');

    // Aggregate pieces needed per product (a product may appear at more than one level)
    $needed_pieces = [];
    foreach ($cart as $it) {
        $needed_pieces[$it['product_id']] = ($needed_pieces[$it['product_id']] ?? 0) + $it['qty_sold'] * $it['level_multiplier'];
    }
    foreach ($needed_pieces as $pid => $needed) {
        $retail = mysqli_fetch_assoc(mysqli_query($conn, "SELECT pieces_quantity FROM retail_stock WHERE product_id = $pid $cid_and"));
        if (!$retail || $retail['pieces_quantity'] < $needed)
            saleResp(false, "Insufficient retail stock available for one of the selected products.", 'retail');
    }

    $sold_by   = (int)$_SESSION['user_id'];
    $loan_date = date('Y-m-d');
    $ph_lc     = $phone !== '' ? "'$phone'" : 'NULL';

    // The loan record must reflect everything the client took, not just the first cart
    // item — so when there's more than one product, point it at no single product_id and
    // describe the whole cart in product_name instead (same fallback loans.php already
    // uses for external sales).
    $loan_product_names = [];
    $loan_cart_sql = 'NULL';
    if ($loan_amount > 0) {
        $cart_pids = array_unique(array_column($cart, 'product_id'));
        $names_by_id = [];
        $pids_sql = implode(',', array_map('intval', $cart_pids));
        $nres = mysqli_query($conn, "SELECT id, name FROM products WHERE id IN ($pids_sql)");
        while ($nr = mysqli_fetch_assoc($nres)) $names_by_id[$nr['id']] = $nr['name'];
        $loan_cart_items = [];
        foreach ($cart as $it) {
            $pname = $names_by_id[$it['product_id']] ?? ('#' . $it['product_id']);
            $loan_product_names[] = "$pname ({$it['qty_sold']})";
            $loan_cart_items[] = [
                'product_id' => $it['product_id'], 'name' => $pname,
                'quantity' => $it['qty_sold'], 'price' => $it['selling_price'],
                'item_total' => round($it['qty_sold'] * $it['selling_price'], 2),
            ];
        }
        $loan_cart_sql = "'" . mysqli_real_escape_string($conn, json_encode($loan_cart_items)) . "'";
    }
    $loan_product_id_sql = count($cart) === 1 ? $cart[0]['product_id'] : 'NULL';
    $loan_qty            = array_sum(array_column($cart, 'qty_sold'));
    $loan_product_name   = mysqli_real_escape_string($conn, implode(', ', $loan_product_names));

    mysqli_begin_transaction($conn);
    $ok = true;
    $touched_products = [];

    foreach ($cart as $i => $it) {
        $product_id       = $it['product_id'];
        $qty_sold         = $it['qty_sold'];
        $selling_price    = $it['selling_price'];
        $level_multiplier = $it['level_multiplier'];
        $pieces_to_deduct = $qty_sold * $level_multiplier;   // actual pieces removed from retail_stock
        $item_total       = round($qty_sold * $selling_price, 2);
        $cost             = retailSaleCost($conn, $product_id, $loan_date, $pieces_to_deduct);
        $cost_total       = $cost['cost_total'];
        $purchase_id_sql  = $cost['purchase_id'] !== null ? $cost['purchase_id'] : 'NULL';
        $row_cash = $i === 0 ? $cash_amount : 0;
        $row_momo = $i === 0 ? $momo_amount : 0;
        $row_loan = $i === 0 ? $loan_amount : 0;

        // Store pieces_to_deduct as pieces_sold so edit/delete correctly restore stock
        $ok = (bool)mysqli_query($conn, "INSERT INTO sales_retail (company_id, product_id, pieces_sold, retail_price, total_amount, cost_total, purchase_id, sale_date, customer_name, cash_amount, momo_amount, loan_amount, has_loan, amount, sold_by)
                       VALUES ($cid_sql, $product_id, $pieces_to_deduct, $selling_price, $item_total, $cost_total, $purchase_id_sql, CURDATE(), '$customer_name', $row_cash, $row_momo, $row_loan, " . ($row_loan > 0 ? 1 : 0) . ", $row_loan, $sold_by)");
        if (!$ok) break;
        $retail_sale_id = (int)mysqli_insert_id($conn);
        $touched_products[$product_id] = true;

        $ok = (bool)mysqli_query($conn, "UPDATE retail_stock SET pieces_quantity = pieces_quantity - $pieces_to_deduct WHERE product_id = $product_id $cid_and");
        if (!$ok) break;

        if ($i === 0 && $loan_amount > 0) {
            $ok = (bool)mysqli_query($conn, "INSERT INTO loans (company_id, product_id, product_name, cart, qty, amount, client, phone, loan_date, given_by, retail_id) VALUES ($cid_sql, $loan_product_id_sql, '$loan_product_name', $loan_cart_sql, $loan_qty, $loan_amount, '$customer_name', '$phone', '$loan_date', $sold_by, $retail_sale_id)");
            if ($ok) {
                $new_loan_id = (int)mysqli_insert_id($conn);
                $ok = (bool)mysqli_query($conn, "
                    INSERT INTO loan_clients (company_id, name, phone, total_loans, paid_amount, unpaid_amount)
                    VALUES ($cid_sql, '$customer_name', $ph_lc, 1, 0, $loan_amount)
                    ON DUPLICATE KEY UPDATE
                        id            = LAST_INSERT_ID(id),
                        total_loans   = total_loans   + 1,
                        unpaid_amount = unpaid_amount + $loan_amount
                ");
                $new_client_id = $ok ? (int)mysqli_insert_id($conn) : 0;
                if ($ok) $ok = (bool)mysqli_query($conn, "UPDATE loans SET client_id = $new_client_id WHERE id = $new_loan_id");
            $amount_paid=$cash_amount+$momo_amount;
             if($amount_paid>0){
                mysqli_query($conn, "INSERT INTO client_payments (company_id, client_id, amount, payment_date, recorded_by, note) VALUES ($cid_sql, $new_client_id, $amount_paid, '$loan_date', $sold_by, 'Partial payments On LoanId# $new_loan_id')");
            //  mysqli_query($conn, "INSERT INTO client_payments (company_id, client_id, amount, payment_date, recorded_by,note) VALUES ($cid_sql, $new_client_id, $amount_paid, '$loan_date', $sold_by,'Partial payments On LoanId#'.$new_loan_id)");
             }
                }
            if (!$ok) break;
        }
    }

    if ($ok) {
        mysqli_commit($conn);
        // require_once 'stock_value.php';
        // foreach (array_keys($touched_products) as $pid) recalcStockValue($conn, cid(), (int)$pid);
        touchCacheStore($conn, 'products');
        touchCacheStore($conn, 'recent_sales_retail');
        if ($loan_amount > 0) touchCacheStore($conn, 'clients');

        $parts = [];
        if ($cash_amount > 0) $parts[] = "Cash: RWF " . number_format($cash_amount, 0);
        if ($momo_amount > 0) $parts[] = "Momo: RWF " . number_format($momo_amount, 0);
        if ($loan_amount > 0) $parts[] = "Loan: RWF " . number_format($loan_amount, 0);
        $n = count($cart);
        saleResp(true, "Retail sale recorded ($n item" . ($n > 1 ? 's' : '') . ") — " . implode(", ", $parts), 'retail');
    } else {
        mysqli_rollback($conn);
        saleResp(false, "Sale could not be recorded. Please try again.", 'retail');
    }
}

// ── AJAX: Sales summary cards ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['get_sales_summary'])) {
    $from = mysqli_real_escape_string($conn, $_POST['date_from'] ?? date('Y-m-d'));
    $to   = mysqli_real_escape_string($conn, $_POST['date_to']   ?? date('Y-m-d'));

    $bulk = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT COUNT(*) AS cnt, COALESCE(SUM(total_amount),0) AS total,
               COALESCE(SUM(cash_amount),0) AS cash, COALESCE(SUM(momo_amount),0) AS momo,
               COALESCE(SUM(loan_amount),0) AS loan
        FROM sales_bulk WHERE sale_date BETWEEN '$from' AND '$to' " . cidAndFor('sales_bulk')));

    $retail = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT COUNT(*) AS cnt, COALESCE(SUM(total_amount),0) AS total,
               COALESCE(SUM(cash_amount),0) AS cash, COALESCE(SUM(momo_amount),0) AS momo,
               COALESCE(SUM(loan_amount),0) AS loan
        FROM sales_retail WHERE sale_date BETWEEN '$from' AND '$to' " . cidAndFor('sales_retail')));

    $external = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT COUNT(*) AS cnt, COALESCE(SUM(total_amount),0) AS total,
               COALESCE(SUM(cash_amount),0) AS cash, COALESCE(SUM(momo_amount),0) AS momo,
               COALESCE(SUM(loan_amount),0) AS loan
        FROM sales_external WHERE sale_date BETWEEN '$from' AND '$to' " . cidAndFor('sales_external')));

    header('Content-Type: application/json');
    echo json_encode([
        'bulk'        => $bulk,
        'retail'      => $retail,
        'external'    => $external,
        'grand_total' => (float)$bulk['total']  + (float)$retail['total']  + (float)$external['total'],
        'grand_cash'  => (float)$bulk['cash']   + (float)$retail['cash']   + (float)$external['cash'],
        'grand_momo'  => (float)$bulk['momo']   + (float)$retail['momo']   + (float)$external['momo'],
        'grand_loan'  => (float)$bulk['loan']   + (float)$retail['loan']   + (float)$external['loan'],
    ]);
    exit;
}

// ── AJAX: Get purchase cost (WAC) for a product ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['get_purchase_cost'])) {
    $pid = (int)$_POST['product_id'];
    $row = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT SUM(quantity * cost_price) / NULLIF(SUM(quantity), 0) AS wac
        FROM purchases WHERE product_id = $pid AND cost_price IS NOT NULL
    "));
    header('Content-Type: application/json');
    echo json_encode(['cost' => (float)($row['wac'] ?? 0)]);
    exit;
}

// ── AJAX: Process Refund ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['process_refund'])) {
    $sale_type     = in_array($_POST['sale_type'] ?? '', ['bulk','retail','external']) ? $_POST['sale_type'] : null;
    $sale_id       = (int)($_POST['sale_id'] ?? 0);
    $back_to_stock = isset($_POST['back_to_stock']) && $_POST['back_to_stock'] == '1' ? 1 : 0;
    $reason        = mysqli_real_escape_string($conn, trim($_POST['reason'] ?? ''));
    $loss_amount   = max(0, (float)($_POST['loss_amount'] ?? 0));
    $refund_date   = mysqli_real_escape_string($conn, $_POST['refund_date'] ?? date('Y-m-d'));
    $processed_by  = (int)$_SESSION['user_id'];

    if (!$sale_type || $sale_id <= 0) {
        header('Content-Type: application/json'); echo json_encode(['success'=>false,'message'=>'Invalid sale reference.']); exit;
    }
    if (!$back_to_stock && empty($reason)) {
        header('Content-Type: application/json'); echo json_encode(['success'=>false,'message'=>'Reason is required when recording a loss.']); exit;
    }

    // Fetch sale row
    $product_id = null; $product_name = ''; $qty = 0;
    if ($sale_type === 'bulk') {
        $s = mysqli_fetch_assoc(mysqli_query($conn, "SELECT sb.product_id, p.name AS pname, sb.quantity FROM sales_bulk sb JOIN products p ON p.id=sb.product_id WHERE sb.id=$sale_id"));
        if ($s) { $product_id = $s['product_id']; $product_name = $s['pname']; $qty = $s['quantity']; }
    } elseif ($sale_type === 'retail') {
        $s = mysqli_fetch_assoc(mysqli_query($conn, "SELECT sr.product_id, p.name AS pname, sr.pieces_sold AS qty FROM sales_retail sr JOIN products p ON p.id=sr.product_id WHERE sr.id=$sale_id"));
        if ($s) { $product_id = $s['product_id']; $product_name = $s['pname']; $qty = $s['qty']; }
    } else {
        $s = mysqli_fetch_assoc(mysqli_query($conn, "SELECT product_name, quantity FROM sales_external WHERE id=$sale_id"));
        if ($s) { $product_name = $s['product_name']; $qty = $s['quantity']; }
    }

    if (!$s) { header('Content-Type: application/json'); echo json_encode(['success'=>false,'message'=>'Sale not found.']); exit; }

    // Guard: already refunded?
    $sale_table = ['bulk'=>'sales_bulk','retail'=>'sales_retail','external'=>'sales_external'][$sale_type];
    $already = mysqli_fetch_assoc(mysqli_query($conn, "SELECT refunded FROM $sale_table WHERE id=$sale_id"));
    if ($already && $already['refunded']) {
        header('Content-Type: application/json'); echo json_encode(['success'=>false,'message'=>'This sale has already been refunded.']); exit;
    }

    $pname_e  = mysqli_real_escape_string($conn, $product_name);
    $pid_sql  = $product_id ? $product_id : 'NULL';
    $loss_sql = $back_to_stock ? 'NULL' : $loss_amount;
    $rsn_sql  = $reason !== '' ? "'$reason'" : 'NULL';

    $ins = mysqli_query($conn, "
        INSERT INTO refunds (company_id, sale_type, sale_id, product_id, product_name, quantity, refund_amount, loss_amount, reason, back_to_stock, refund_date, processed_by)
        VALUES ($cid_sql, '$sale_type', $sale_id, $pid_sql, '$pname_e', $qty, 0, $loss_sql, $rsn_sql, $back_to_stock, '$refund_date', $processed_by)
    ");
    if (!$ins) { header('Content-Type: application/json'); echo json_encode(['success'=>false,'message'=>mysqli_error($conn)]); exit; }

    if ($back_to_stock && $product_id) {
        if ($sale_type === 'bulk') {
            mysqli_query($conn, "UPDATE stock SET quantity = quantity + $qty WHERE product_id = $product_id $cid_and");
        } elseif ($sale_type === 'retail') {
            mysqli_query($conn, "UPDATE retail_stock SET pieces_quantity = pieces_quantity + $qty WHERE product_id = $product_id $cid_and");
        }
    }

    // Remove any loans tied to this sale and recompute loan_clients aggregates
    $fk_col = $sale_type . '_id'; // bulk_id | retail_id | external_id
    $related_loans = mysqli_query($conn, "
        SELECT id, client_id FROM loans WHERE $fk_col = $sale_id
    ");
    $affected_client_ids = [];
    while ($rl = mysqli_fetch_assoc($related_loans)) {
        if ($rl['client_id']) $affected_client_ids[(int)$rl['client_id']] = true;
        mysqli_query($conn, "DELETE FROM loan_payments WHERE loan_id = {$rl['id']}");
        mysqli_query($conn, "DELETE FROM loans WHERE id = {$rl['id']}");
    }
    // Recompute aggregates from scratch for each affected client
    foreach (array_keys($affected_client_ids) as $cid) {
        mysqli_query($conn, "
            UPDATE loan_clients lc
            JOIN (
                SELECT COUNT(DISTINCT l.id)       AS cnt,
                       COALESCE(SUM(l.amount),0)  AS loaned,
                       COALESCE(SUM(lp_s.paid),0) AS paid_sum
                FROM loans l
                LEFT JOIN (SELECT loan_id, SUM(amount_paid) AS paid FROM loan_payments GROUP BY loan_id) lp_s
                       ON lp_s.loan_id = l.id
                WHERE l.client_id = $cid
            ) agg
            SET lc.total_loans   = agg.cnt,
                lc.paid_amount   = agg.paid_sum,
                lc.unpaid_amount = agg.loaned - agg.paid_sum
            WHERE lc.id = $cid
        ");
    }

    // Mark the sale as refunded (sale_table already set above)
    mysqli_query($conn, "UPDATE $sale_table SET refunded = 1 WHERE id = $sale_id");

    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}

// Read flash messages from session
if (isset($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (isset($_SESSION['flash_error'])) {
    $error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}
$last_sale_type = $_SESSION['flash_sale_type'] ?? null;
unset($_SESSION['flash_sale_type']);

// Bulk/retail/external product pickers and loan-client pickers below are no
// longer queried here — they're loaded client-side from DataCache
// (js/data-cache.js), which is shared across every page in the app.

$highlight_sale_id  = (int)($_GET['highlight'] ?? 0);
$highlight_sale_tab = in_array($_GET['tab'] ?? '', ['bulk','retail','external']) ? $_GET['tab'] : '';
$owner_filter = max(0, (int)($_GET['owner_id'] ?? 0));

if ($highlight_sale_id > 0 && $highlight_sale_tab !== '') {
    // Load only the one highlighted row; empty the other two tables
    $empty_set = mysqli_query($conn, "SELECT 1 FROM dual WHERE 0");
    if ($highlight_sale_tab === 'bulk') {
        $recent_bulk_sales = mysqli_query($conn, "
            SELECT sb.*, p.name, u.full_name AS seller_name
            FROM sales_bulk sb
            JOIN products p ON sb.product_id = p.id
            LEFT JOIN users u ON sb.sold_by = u.id
            WHERE sb.id = $highlight_sale_id
        ");
        $recent_retail_sales   = $empty_set;
        $recent_external_sales = $empty_set;
    } elseif ($highlight_sale_tab === 'retail') {
        $recent_retail_sales = mysqli_query($conn, "
            SELECT sr.*, p.name, u.full_name AS seller_name
            FROM sales_retail sr
            JOIN products p ON sr.product_id = p.id
            LEFT JOIN users u ON sr.sold_by = u.id
            WHERE sr.id = $highlight_sale_id
        ");
        $recent_bulk_sales     = $empty_set;
        $recent_external_sales = $empty_set;
    } else {
        $recent_external_sales = mysqli_query($conn, "
            SELECT se.*, u.full_name AS seller_name,
                   po.name AS owner_name, po.phone AS owner_phone
            FROM sales_external se
            LEFT JOIN users u ON se.sold_by = u.id
            LEFT JOIN product_owners po ON se.owner_id = po.id
            WHERE se.id = $highlight_sale_id
        ");
        $recent_bulk_sales   = $empty_set;
        $recent_retail_sales = $empty_set;
    }
    // Keep date inputs showing today so the form looks normal
    $date_from = date('Y-m-d');
    $date_to   = date('Y-m-d');
} else {
    // Normal date-filtered load
    $date_from = $_GET['date_from'] ?? date('Y-m-d');
    $date_to   = $_GET['date_to']   ?? date('Y-m-d');
    $date_from_safe = mysqli_real_escape_string($conn, $date_from);
    $date_to_safe   = mysqli_real_escape_string($conn, $date_to);

    $recent_bulk_sales = mysqli_query($conn, "
        SELECT sb.*, p.name, u.full_name AS seller_name
        FROM sales_bulk sb
        JOIN products p ON sb.product_id = p.id
        LEFT JOIN users u ON sb.sold_by = u.id
        WHERE sb.sale_date BETWEEN '$date_from_safe' AND '$date_to_safe' " . cidAndFor('sb') . "
        ORDER BY sb.created_at DESC
    ");

    $recent_retail_sales = mysqli_query($conn, "
        SELECT sr.*, p.name, u.full_name AS seller_name
        FROM sales_retail sr
        JOIN products p ON sr.product_id = p.id
        LEFT JOIN users u ON sr.sold_by = u.id
        WHERE sr.sale_date BETWEEN '$date_from_safe' AND '$date_to_safe' " . cidAndFor('sr') . "
        ORDER BY sr.created_at DESC
    ");

    $ext_owner_where = $owner_filter > 0 ? "AND se.owner_id = $owner_filter" : "";
    $recent_external_sales = mysqli_query($conn, "
        SELECT se.*, u.full_name AS seller_name,
               po.name AS owner_name, po.phone AS owner_phone
        FROM sales_external se
        LEFT JOIN users u ON se.sold_by = u.id
        LEFT JOIN product_owners po ON se.owner_id = po.id
        WHERE se.sale_date BETWEEN '$date_from_safe' AND '$date_to_safe' " . cidAndFor('se') . "
        $ext_owner_where
        ORDER BY se.created_at DESC
    ");
}

// Product owners for picker
$ext_owners_query = mysqli_query($conn, "
    SELECT po.id, po.name AS owner_name, po.phone AS owner_phone,
           COUNT(se.id) AS total_sales
    FROM product_owners po
    LEFT JOIN sales_external se ON se.owner_id = po.id
    GROUP BY po.id
    ORDER BY total_sales DESC, po.name ASC
");
$ext_owners_arr = [];
while ($o = mysqli_fetch_assoc($ext_owners_query)) $ext_owners_arr[] = $o;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales - Small Stock Management</title>
        <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/sales.css">
    <link rel="stylesheet" href="css/all.min.css">
    <style>
        .searchable-select {
            position: relative;
        }
        .searchable-select-input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--gray-300);
            border-radius: var(--radius);
            font-size: 14px;
            background: var(--white);
            cursor: text;
        }
        .searchable-select-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
        }
        .searchable-select-dropdown {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            max-height: 200px;
            overflow-y: auto;
            background: var(--white);
            border: 1px solid var(--gray-300);
            border-top: none;
            border-radius: 0 0 var(--radius) var(--radius);
            z-index: 1000;
            box-shadow: var(--shadow-md);
        }
        .searchable-select-dropdown.open {
            display: block;
        }
        .searchable-select-option {
            padding: 9px 12px;
            cursor: pointer;
            font-size: 14px;
        }
        .searchable-select-option:hover,
        .searchable-select-option.highlighted {
            background: var(--gray-100);
            color: var(--primary);
        }
        .searchable-select-option.hidden {
            display: none;
        }
        .split-payment-box {
            border: 1px solid var(--gray-300);
            border-radius: var(--radius);
            overflow: hidden;
        }
        .split-row {
            display: flex;
            align-items: center;
            padding: 8px 12px;
            gap: 10px;
            border-bottom: 1px solid var(--gray-100);
        }
        .split-row:last-child { border-bottom: none; }
        .split-label {
            width: 70px;
            font-size: 13px;
            font-weight: 500;
            flex-shrink: 0;
        }
        .split-row input[type="text"] {
            flex: 1;
            padding: 6px 10px;
            border: 1px solid var(--gray-300);
            border-radius: var(--radius);
            font-size: 14px;
        }
        .split-remaining-row {
            justify-content: space-between;
            background: var(--gray-50);
            font-weight: 600;
        }
        .split-remaining-row.valid  { background: #ecfdf5; color: #059669; }
        .split-remaining-row.invalid { background: #fef2f2; color: #dc2626; }
        .lvl-btn {
            display: inline-flex; flex-direction: column; align-items: center;
            padding: 8px 14px; border: 1.5px solid var(--gray-300);
            border-radius: var(--radius); cursor: pointer; background: var(--white);
            transition: all .15s; min-width: 100px; gap: 2px;
        }
        .lvl-btn:hover { border-color: var(--primary); background: #eff6ff; }
        .lvl-btn.active { border-color: var(--primary); background: #eff6ff; }
        .lvl-btn-name  { font-size: 13px; font-weight: 700; color: var(--dark); }
        .lvl-btn-stock { font-size: 11px; color: var(--secondary); }
        .lvl-btn-price { font-size: 14px; font-weight: 700; color: var(--primary); }
        .lvl-btn.active .lvl-btn-name,
        .lvl-btn.active .lvl-btn-stock { color: var(--primary); }

        .loan-card {
            background: var(--white);
            border-radius: 14px;
            padding: 20px 18px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
            border-top: 4px solid var(--primary);
            transition: box-shadow .2s, transform .2s;
        }
        .loan-card:hover { box-shadow: var(--shadow-md); transform: translateY(-2px); }
        .loan-card.green  { border-top-color: var(--success); }
        .loan-card.red    { border-top-color: var(--danger); }
        .loan-card.orange { border-top-color: var(--warning); }
        .loan-card-label {
            font-size: 11px; font-weight: 600; text-transform: uppercase;
            letter-spacing: .6px; color: var(--secondary); margin-bottom: 8px;
        }
        .loan-card-value { font-size: 22px; font-weight: 700; color: var(--dark); line-height: 1.2; }
        .loan-card-value.danger  { color: var(--danger); }
        .loan-card-value.success { color: var(--success); }
        .loan-card-sub { font-size: 11px; color: var(--secondary); margin-top: 5px; }

        .sales-tab-nav {
            display: flex;
            gap: 4px;
            border-bottom: 2px solid var(--gray-200);
            margin-bottom: 16px;
        }
        .sales-tab-btn {
            padding: 8px 20px;
            border: none;
            background: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            color: var(--secondary);
            border-bottom: 2px solid transparent;
            margin-bottom: -2px;
            border-radius: var(--radius) var(--radius) 0 0;
            transition: color .15s, border-color .15s;
        }
        .sales-tab-btn:hover { color: var(--primary); }
        .sales-tab-btn.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
            background: var(--white);
        }
        .has-loan-row { background: #fffbeb !important; }
        .has-loan-row:hover { background: #fef3c7 !important; }
        .loan-badge {
            display: inline-block;
            padding: 1px 6px;
            background: #fef3c7;
            color: #b45309;
            border: 1px solid #fde68a;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .4px;
            vertical-align: middle;
            margin-right: 2px;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php include 'sidebar.php'; ?>
        
        <div class="main-content">
            <h1>Sales Management</h1>
            
            <?php if (isset($success)): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <div class="sale-action-buttons">
                <a href="sale_bulk.php" class="btn btn-primary btn-lg">+ Kuranguza</a>
                <a href="sale_retail.php" class="btn btn-success btn-lg">+ Gucuruza Detaye</a>
                <a href="sale_external.php" class="btn btn-lg" style="background:var(--warning,#f59e0b);color:#fff;">+ External Sale</a>
            </div>

            <!-- Summary Cards -->
            <div id="salesSummaryCards" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin:18px 0;">
                <div class="loan-card" style="position:relative;">
                    <div class="loan-card-label">All Sales</div>
                    <div class="loan-card-value" id="sc-grand-total">—</div>
                    <div class="loan-card-sub" id="sc-grand-sub" style="font-size:11px;margin-top:4px;"></div>
                </div>
                <div class="loan-card green">
                    <div class="loan-card-label">Bulk (Kuranguza)</div>
                    <div class="loan-card-value" id="sc-bulk-total">—</div>
                    <div class="loan-card-sub" id="sc-bulk-sub" style="font-size:11px;margin-top:4px;"></div>
                </div>
                <div class="loan-card orange">
                    <div class="loan-card-label">Retail (Detaye)</div>
                    <div class="loan-card-value" id="sc-retail-total">—</div>
                    <div class="loan-card-sub" id="sc-retail-sub" style="font-size:11px;margin-top:4px;"></div>
                </div>
                <div class="loan-card">
                    <div class="loan-card-label">External</div>
                    <div class="loan-card-value" id="sc-ext-total">—</div>
                    <div class="loan-card-sub" id="sc-ext-sub" style="font-size:11px;margin-top:4px;"></div>
                </div>
                <div class="loan-card" style="border-left:4px solid #0ea5e9;">
                    <div class="loan-card-label">Cash Collected</div>
                    <div class="loan-card-value" id="sc-cash" style="color:#0ea5e9;">—</div>
                </div>
                <div class="loan-card" style="border-left:4px solid #8b5cf6;">
                    <div class="loan-card-label">Momo Collected</div>
                    <div class="loan-card-value" id="sc-momo" style="color:#8b5cf6;">—</div>
                </div>
                <div class="loan-card red">
                    <div class="loan-card-label">On Loan</div>
                    <div class="loan-card-value danger" id="sc-loan">—</div>
                </div>
            </div>
              <?php
                $active_tab = in_array($last_sale_type, ['bulk','retail','external'])
                    ? $last_sale_type
                    : (in_array($_GET['tab'] ?? '', ['bulk','retail','external']) ? $_GET['tab'] : 'retail');
                ?>
            <div class="recent-sales">
                <h2>Recent Sales</h2>
                <form method="GET" class="date-filter-form">
                    <div class="date-filter-group">
                        <label for="date_from">From</label>
                        <input type="date" id="date_from" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                    </div>
                    <div class="date-filter-group">
                        <label for="date_to">To</label>
                        <input type="date" id="date_to" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                    </div>
                    <div class="date-filter-group" id="owner_filter_group" style="<?php echo $active_tab !== 'external' ? 'display:none' : ''; ?>">
                        <label for="owner_id">Owner</label>
                        <select name="owner_id" id="owner_id" style="padding:6px 10px;border:1px solid var(--gray-300);border-radius:var(--radius);font-size:13px;">
                            <option value="0">— All owners —</option>
                            <?php foreach ($ext_owners_arr as $o): ?>
                                <option value="<?php echo $o['id']; ?>" <?php echo $owner_filter === (int)$o['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($o['owner_name']); ?>
                                    <?php if ($o['owner_phone']): ?>(<?php echo htmlspecialchars($o['owner_phone']); ?>)<?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <input type="hidden" id="filter_tab" name="tab" value="<?php echo htmlspecialchars($active_tab); ?>">
                    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                    <a href="sales.php?tab=<?php echo htmlspecialchars($active_tab); ?>" class="btn btn-sm" style="background:var(--gray-200);color:var(--dark);">Today</a>
                </form>
              
                <div class="sales-tab-nav">
                    <button class="sales-tab-btn<?php echo $active_tab==='bulk'     ? ' active' : ''; ?>" onclick="switchSalesTab('bulk')"    >Ibyaranguwe</button>
                    <button class="sales-tab-btn<?php echo $active_tab==='retail'   ? ' active' : ''; ?>" onclick="switchSalesTab('retail')"  >Ibyacurujwe detaye</button>
                    <button class="sales-tab-btn<?php echo $active_tab==='external' ? ' active' : ''; ?>" onclick="switchSalesTab('external')" >External</button>
                </div>

                <!-- Bulk tab -->
                <div class="sales-tab-panel" id="stab-bulk" <?php echo $active_tab!=='bulk'     ? 'style="display:none"' : ''; ?>>
                <div style="margin-bottom:10px;">
                    <input type="text" id="search-bulk" placeholder="Search customer, product..." oninput="filterTable('tbl-bulk', this.value)" style="width:100%;max-width:360px;padding:7px 12px;border:1px solid var(--gray-300);border-radius:var(--radius);font-size:14px;">
                </div>
                <table class="table" id="tbl-bulk">
                    <thead>
                        <tr>
                            <th>Actions</th><th>Date</th><th>Product</th><th>Qty</th><th>Unit Price</th><th>Default Price</th><th>Difference</th><th>Total</th><th>Cash</th><th>Momo</th><th>Loan</th><th>Customer</th><th>Sold By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $bulk_grand_total = 0; while($row = mysqli_fetch_assoc($recent_bulk_sales)):
                            $bulk_grand_total += $row['total_amount'];
                            $default_price_query = mysqli_query($conn, "SELECT package_price FROM stock WHERE product_id = {$row['product_id']} $cid_and");
                            $default_price = mysqli_fetch_assoc($default_price_query)['package_price'] ?? 0;
                            $price_diff = $row['package_price'] - $default_price;
                            $diff_class = $price_diff > 0 ? 'text-danger' : ($price_diff < 0 ? 'text-success' : '');
                        ?>
                        <tr id="sale-row-<?php echo $row['id']; ?>" <?php if($row['refunded']): ?>style="opacity:.6;"<?php elseif($row['loan_amount']>0): ?>class="has-loan-row"<?php endif; ?>>
                            <td>
                                <?php if($row['refunded']): ?>
                                <span style="display:inline-block;padding:3px 10px;background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;border-radius:5px;font-size:12px;font-weight:600;">&#10006; Refunded</span>
                                <?php else: ?>
                                <div class="act-menu-wrap">
                                    <button class="act-btn" title="Actions" onclick="toggleActMenu(this)">⋮</button>
                                    <div class="act-menu">
                                        <button class="act-item" onclick="openEditBulk(<?php echo $row['id']; ?>,'<?php echo htmlspecialchars($row['sale_date'],ENT_QUOTES); ?>',<?php echo $row['quantity']; ?>,<?php echo $row['package_price']; ?>,'<?php echo htmlspecialchars($row['customer_name']??'',ENT_QUOTES); ?>',<?php echo $row['cash_amount']; ?>,<?php echo $row['momo_amount']; ?>,<?php echo $row['loan_amount']; ?>,'<?php echo htmlspecialchars($row['name'],ENT_QUOTES); ?>');closeActMenus()"><i class="fas fa-pen"></i> Edit</button>
                                        <button class="act-item" onclick="printSaleReceipt('bulk',<?php echo $row['id']; ?>,'<?php echo htmlspecialchars(date('Y-m-d', strtotime($row['sale_date'])),ENT_QUOTES); ?>','<?php echo htmlspecialchars($row['name'],ENT_QUOTES); ?>',<?php echo $row['quantity']; ?>,'pkg',<?php echo $row['package_price']; ?>,<?php echo $row['total_amount']; ?>,<?php echo $row['cash_amount']; ?>,<?php echo $row['momo_amount']; ?>,<?php echo $row['loan_amount']; ?>,'<?php echo htmlspecialchars($row['customer_name']??'',ENT_QUOTES); ?>','<?php echo htmlspecialchars($row['seller_name']??'',ENT_QUOTES); ?>','');closeActMenus()"><i class="fas fa-print"></i> Print</button>
                                        <button class="act-item" onclick="openRefundModal('bulk',<?php echo $row['id']; ?>,<?php echo $row['product_id']; ?>,'<?php echo htmlspecialchars($row['name'],ENT_QUOTES); ?>',<?php echo $row['quantity']; ?>,<?php echo $row['total_amount']; ?>);closeActMenus()"><i class="fas fa-rotate-left"></i> Refund</button>
                                        <div class="act-menu-sep"></div>
                                        <form method="POST" onsubmit="return confirm('Delete this bulk sale and restore stock?')">
                                            <input type="hidden" name="sale_id" value="<?php echo $row['id']; ?>">
                                            <button type="submit" name="delete_bulk_sale" class="act-item danger"><i class="fas fa-trash"></i> Delete</button>
                                        </form>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo date('Y-m-d', strtotime($row['sale_date'])); ?></td>
                            <td><?php echo htmlspecialchars($row['name']); ?></td>
                            <td><?php echo $row['quantity']; ?></td>
                            <td><strong>RWF <?php echo number_format($row['package_price'], 0); ?></strong></td>
                            <td>RWF <?php echo number_format($default_price, 0); ?></td>
                            <td class="<?php echo $diff_class; ?>"><?php echo $price_diff != 0 ? ($price_diff > 0 ? '+' : '') . number_format($price_diff, 0) : '-'; ?></td>
                            <td>RWF <?php echo number_format($row['total_amount'], 0); ?></td>
                            <td><?php echo $row['cash_amount'] > 0 ? 'RWF '.number_format($row['cash_amount'],0) : '—'; ?></td>
                            <td><?php echo $row['momo_amount'] > 0 ? 'RWF '.number_format($row['momo_amount'],0) : '—'; ?></td>
                            <td><?php if($row['loan_amount'] > 0): ?><span class="loan-badge">LOAN</span> RWF <?php echo number_format($row['loan_amount'],0); ?><?php else: ?>—<?php endif; ?></td>
                            <td><?php echo htmlspecialchars($row['customer_name'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($row['seller_name'] ?? '—'); ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-total-row">
                            <td colspan="6" style="text-align:right;"><strong>Total:</strong></td>
                            <td><strong>RWF <?php echo number_format($bulk_grand_total, 0); ?></strong></td>
                            <td colspan="6"></td>
                        </tr>
                    </tfoot>
                </table>
                </div>

                <!-- Retail tab -->
                <div class="sales-tab-panel" id="stab-retail" <?php echo $active_tab!=='retail'   ? 'style="display:none"' : ''; ?>>
                <div style="margin-bottom:10px;">
                    <input type="text" id="search-retail" placeholder="Search customer, product..." oninput="filterTable('tbl-retail', this.value)" style="width:100%;max-width:360px;padding:7px 12px;border:1px solid var(--gray-300);border-radius:var(--radius);font-size:14px;">
                </div>
                <table class="table" id="tbl-retail">
                    <thead>
                        <tr>
                            <th>Actions</th><th>Date</th><th>Product</th><th>Pieces</th><th>Price/Piece</th><th>Default Price</th><th>Difference</th><th>Total</th><th>Cash</th><th>Momo</th><th>Loan</th><th>Customer</th><th>Sold By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $retail_grand_total = 0; while($row = mysqli_fetch_assoc($recent_retail_sales)):
                            $retail_grand_total += $row['total_amount'];
                            $default_price_query = mysqli_query($conn, "SELECT retail_price FROM retail_stock WHERE product_id = {$row['product_id']}");
                            $default_price = mysqli_fetch_assoc($default_price_query)['retail_price'];
                            // Normalize to per-piece so middle-level sales compare correctly
                            $actual_per_piece = $row['pieces_sold'] > 0 ? ($row['total_amount'] / $row['pieces_sold']) : $row['retail_price'];
                            $price_diff = $actual_per_piece - $default_price;
                            $diff_class = $price_diff > 0.005 ? 'text-danger' : ($price_diff < -0.005 ? 'text-success' : '');
                        ?>
                        <tr id="sale-row-<?php echo $row['id']; ?>" <?php if($row['refunded']): ?>style="opacity:.6;"<?php elseif($row['loan_amount']>0): ?>class="has-loan-row"<?php endif; ?>>
                            <td>
                                <?php if($row['refunded']): ?>
                                <span style="display:inline-block;padding:3px 10px;background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;border-radius:5px;font-size:12px;font-weight:600;">&#10006; Refunded</span>
                                <?php else: ?>
                                <div class="act-menu-wrap">
                                    <button class="act-btn" title="Actions" onclick="toggleActMenu(this)">⋮</button>
                                    <div class="act-menu">
                                        <button class="act-item" onclick="openEditRetail(<?php echo $row['id']; ?>,'<?php echo htmlspecialchars($row['sale_date'],ENT_QUOTES); ?>',<?php echo $row['pieces_sold']; ?>,<?php echo $row['retail_price']; ?>,'<?php echo htmlspecialchars($row['customer_name']??'',ENT_QUOTES); ?>',<?php echo $row['cash_amount']; ?>,<?php echo $row['momo_amount']; ?>,<?php echo $row['loan_amount']; ?>,'<?php echo htmlspecialchars($row['name'],ENT_QUOTES); ?>');closeActMenus()"><i class="fas fa-pen"></i> Edit</button>
                                        <button class="act-item" onclick="printSaleReceipt('retail',<?php echo $row['id']; ?>,'<?php echo htmlspecialchars(date('Y-m-d', strtotime($row['sale_date'])),ENT_QUOTES); ?>','<?php echo htmlspecialchars($row['name'],ENT_QUOTES); ?>',<?php echo $row['pieces_sold']; ?>,'pcs',<?php echo $actual_per_piece; ?>,<?php echo $row['total_amount']; ?>,<?php echo $row['cash_amount']; ?>,<?php echo $row['momo_amount']; ?>,<?php echo $row['loan_amount']; ?>,'<?php echo htmlspecialchars($row['customer_name']??'',ENT_QUOTES); ?>','<?php echo htmlspecialchars($row['seller_name']??'',ENT_QUOTES); ?>','');closeActMenus()"><i class="fas fa-print"></i> Print</button>
                                        <button class="act-item" onclick="openRefundModal('retail',<?php echo $row['id']; ?>,<?php echo $row['product_id']; ?>,'<?php echo htmlspecialchars($row['name'],ENT_QUOTES); ?>',<?php echo $row['pieces_sold']; ?>,<?php echo $row['total_amount']; ?>);closeActMenus()"><i class="fas fa-rotate-left"></i> Refund</button>
                                        <div class="act-menu-sep"></div>
                                        <form method="POST" onsubmit="return confirm('Delete this retail sale and restore stock?')">
                                            <input type="hidden" name="sale_id" value="<?php echo $row['id']; ?>">
                                            <button type="submit" name="delete_retail_sale" class="act-item danger"><i class="fas fa-trash"></i> Delete</button>
                                        </form>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo date('Y-m-d', strtotime($row['sale_date'])); ?></td>
                            <td><?php echo htmlspecialchars($row['name']); ?></td>
                            <td><?php echo $row['pieces_sold']; ?></td>
                            <td><strong>RWF <?php echo number_format($actual_per_piece, 0); ?></strong></td>
                            <td>RWF <?php echo number_format($default_price, 0); ?></td>
                            <td class="<?php echo $diff_class; ?>"><?php echo abs($price_diff) > 0.005 ? ($price_diff > 0 ? '+' : '') . number_format($price_diff, 0) : '-'; ?></td>
                            <td>RWF <?php echo number_format($row['total_amount'], 0); ?></td>
                            <td><?php echo $row['cash_amount'] > 0 ? 'RWF '.number_format($row['cash_amount'],0) : '—'; ?></td>
                            <td><?php echo $row['momo_amount'] > 0 ? 'RWF '.number_format($row['momo_amount'],0) : '—'; ?></td>
                            <td><?php if($row['loan_amount'] > 0): ?><span class="loan-badge">LOAN</span> RWF <?php echo number_format($row['loan_amount'],0); ?><?php else: ?>—<?php endif; ?></td>
                            <td><?php echo htmlspecialchars($row['customer_name'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($row['seller_name'] ?? '—'); ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-total-row">
                            <td colspan="6" style="text-align:right;"><strong>Total:</strong></td>
                            <td><strong>RWF <?php echo number_format($retail_grand_total, 0); ?></strong></td>
                            <td colspan="6"></td>
                        </tr>
                    </tfoot>
                </table>
                </div>

                <!-- External tab -->
                <div class="sales-tab-panel" id="stab-external" <?php echo $active_tab!=='external' ? 'style="display:none"' : ''; ?>>
                <div style="margin-bottom:10px;">
                    <input type="text" id="search-external" placeholder="Search customer, product, owner..." oninput="filterTable('tbl-external', this.value)" style="width:100%;max-width:360px;padding:7px 12px;border:1px solid var(--gray-300);border-radius:var(--radius);font-size:14px;">
                </div>
                <table class="table" id="tbl-external">
                    <thead>
                        <tr>
                            <th>Actions</th><th>Date</th><th>Product</th><th>Owner</th><th>Qty</th><th>Unit Price</th><th>Total</th><th>My Commission</th><th>Cash</th><th>Momo</th><th>Loan</th><th>Customer</th><th>Sold By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $ext_grand_total = 0; while($row = mysqli_fetch_assoc($recent_external_sales)):
                            $ext_grand_total += $row['total_amount'];
                        ?>
                        <tr id="sale-row-<?php echo $row['id']; ?>" <?php if($row['refunded']): ?>style="opacity:.6;"<?php endif; ?>>
                            <td>
                                <?php if($row['refunded']): ?>
                                <span style="display:inline-block;padding:3px 10px;background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;border-radius:5px;font-size:12px;font-weight:600;">&#10006; Refunded</span>
                                <?php else: ?>
                                <div class="act-menu-wrap">
                                    <button class="act-btn" title="Actions" onclick="toggleActMenu(this)">⋮</button>
                                    <div class="act-menu">
                                        <button class="act-item" onclick="openEditExternal(<?php echo $row['id']; ?>,'<?php echo htmlspecialchars($row['sale_date'],ENT_QUOTES); ?>','<?php echo htmlspecialchars($row['product_name'],ENT_QUOTES); ?>',<?php echo $row['quantity']; ?>,<?php echo $row['unit_price']; ?>,'<?php echo htmlspecialchars($row['customer_name']??'',ENT_QUOTES); ?>',<?php echo $row['cash_amount']; ?>,<?php echo $row['momo_amount']; ?>,<?php echo $row['loan_amount']; ?>,<?php echo (float)($row['my_revenue'] ?? 0); ?>);closeActMenus()"><i class="fas fa-pen"></i> Edit</button>
                                        <button class="act-item" onclick="printSaleReceipt('external',<?php echo $row['id']; ?>,'<?php echo htmlspecialchars(date('Y-m-d', strtotime($row['sale_date'])),ENT_QUOTES); ?>','<?php echo htmlspecialchars($row['product_name'],ENT_QUOTES); ?>',<?php echo $row['quantity']; ?>,'pkg',<?php echo $row['unit_price']; ?>,<?php echo $row['total_amount']; ?>,<?php echo $row['cash_amount']; ?>,<?php echo $row['momo_amount']; ?>,<?php echo $row['loan_amount']; ?>,'<?php echo htmlspecialchars($row['customer_name']??'',ENT_QUOTES); ?>','<?php echo htmlspecialchars($row['seller_name']??'',ENT_QUOTES); ?>','<?php echo htmlspecialchars($row['owner_name']??'',ENT_QUOTES); ?>');closeActMenus()"><i class="fas fa-print"></i> Print</button>
                                        <button class="act-item" onclick="openRefundModal('external',<?php echo $row['id']; ?>,0,'<?php echo htmlspecialchars($row['product_name'],ENT_QUOTES); ?>',<?php echo $row['quantity']; ?>,<?php echo $row['total_amount']; ?>);closeActMenus()"><i class="fas fa-rotate-left"></i> Refund</button>
                                        <div class="act-menu-sep"></div>
                                        <form method="POST" onsubmit="return confirm('Delete this external sale?')">
                                            <input type="hidden" name="sale_id" value="<?php echo $row['id']; ?>">
                                            <button type="submit" name="delete_external_sale" class="act-item danger"><i class="fas fa-trash"></i> Delete</button>
                                        </form>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo date('Y-m-d', strtotime($row['sale_date'])); ?></td>
                            <td><?php echo htmlspecialchars($row['product_name']); ?></td>
                            <td><?php
                                $ow = htmlspecialchars($row['owner_name'] ?? '');
                                $op = htmlspecialchars($row['owner_phone'] ?? '');
                                echo $ow ? ($op ? "$ow<br><small style='color:var(--secondary)'>$op</small>" : $ow) : '—';
                            ?></td>
                            <td><?php echo $row['quantity']; ?></td>
                            <td>RWF <?php echo number_format($row['unit_price'], 0); ?></td>
                            <td><strong>RWF <?php echo number_format($row['total_amount'], 0); ?></strong></td>
                            <td><?php echo $row['my_revenue'] > 0 ? '<strong>RWF '.number_format($row['my_revenue'],0).'</strong>' : '—'; ?></td>
                            <td><?php echo $row['cash_amount'] > 0 ? 'RWF '.number_format($row['cash_amount'],0) : '—'; ?></td>
                            <td><?php echo $row['momo_amount'] > 0 ? 'RWF '.number_format($row['momo_amount'],0) : '—'; ?></td>
                            <td><?php echo $row['loan_amount'] > 0 ? 'RWF '.number_format($row['loan_amount'],0) : '—'; ?></td>
                            <td><?php echo htmlspecialchars($row['customer_name'] ?: 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($row['seller_name'] ?? '—'); ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-total-row">
                            <td colspan="5" style="text-align:right;"><strong>Total:</strong></td>
                            <td><strong>RWF <?php echo number_format($ext_grand_total, 0); ?></strong></td>
                            <td colspan="7"></td>
                        </tr>
                    </tfoot>
                </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Refund Modal -->
    <div id="refundModal" class="modal">
        <div class="modal-content" style="max-width:480px;">
            <span class="close" onclick="closeModal('refundModal')">&times;</span>
            <h2>Refund Sale</h2>
            <div id="refundInfo" style="background:var(--gray-100);border-radius:8px;padding:10px 14px;margin-bottom:16px;font-size:14px;color:var(--secondary);"></div>
            <div id="refundAlert" class="alert" style="display:none;"></div>

            <input type="hidden" id="ref_sale_type">
            <input type="hidden" id="ref_sale_id">
            <input type="hidden" id="ref_product_id">
            <input type="hidden" id="ref_product_name">
            <input type="hidden" id="ref_qty">

            <div class="form-group">
                <label>Disposition</label>
                <div style="display:flex;gap:20px;margin-top:6px;">
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                        <input type="radio" name="refund_disposition" id="refund_disp_loss" value="0" checked onchange="toggleRefundMode(0)">
                        Record as Loss
                    </label>
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;" id="refund_back_stock_label">
                        <input type="radio" name="refund_disposition" id="refund_disp_stock" value="1" onchange="toggleRefundMode(1)">
                        Back to Stock
                    </label>
                </div>
            </div>

            <div id="refundLossFields">
                <div class="form-group">
                    <label>Loss Amount (RWF)*</label>
                    <input type="number" id="refund_loss_amount" min="0" step="1" placeholder="Defaults to purchase cost...">
                    <small style="color:var(--secondary);">Auto-filled with weighted-average purchase cost.</small>
                </div>
                <div class="form-group">
                    <label>Reason*</label>
                    <input type="text" id="refund_reason" placeholder="e.g. Damaged, returned by customer...">
                </div>
            </div>

            <div class="form-group">
                <label>Date*</label>
                <input type="date" id="refund_date">
            </div>

            <div style="display:flex;gap:10px;margin-top:4px;">
                <button class="btn btn-primary" id="refundSubmitBtn" onclick="submitRefund()">Process Refund</button>
                <button class="btn btn-secondary" onclick="closeModal('refundModal')">Cancel</button>
            </div>
        </div>
    </div>

    <!-- Edit Bulk Sale Modal -->
    <div id="editBulkModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('editBulkModal')">&times;</span>
            <h2>Edit Bulk Sale</h2>
            <form method="POST" id="editBulkForm">
                <input type="hidden" name="sale_id" id="edit_bulk_id">
                <div class="form-group">
                    <label>Product</label>
                    <input type="text" id="edit_bulk_product_label" disabled style="background:var(--gray-100);color:var(--secondary);">
                </div>
                <div class="form-group">
                    <label for="edit_bulk_date">Date*</label>
                    <input type="date" id="edit_bulk_date" name="sale_date" required>
                </div>
                <div class="form-group">
                    <label for="edit_bulk_qty">Quantity (Packages)*</label>
                    <input type="number" id="edit_bulk_qty" name="quantity" required min="1">
                </div>
                <div class="form-group">
                    <label for="edit_bulk_price">Price per Package*</label>
                    <input type="number" id="edit_bulk_price" name="selling_price" required min="1" step="1">
                </div>
                <div class="form-group">
                    <label for="edit_bulk_customer">Customer Name</label>
                    <input type="text" id="edit_bulk_customer" name="customer_name">
                </div>
                <div class="form-group">
                    <label>Payment Split</label>
                    <div class="split-payment-box">
                        <div class="split-row"><span class="split-label">Cash</span><input type="number" id="edit_bulk_cash" name="cash_amount" min="0" step="1" value="0"></div>
                        <div class="split-row"><span class="split-label">Momo</span><input type="number" id="edit_bulk_momo" name="momo_amount" min="0" step="1" value="0"></div>
                        <div class="split-row"><span class="split-label">Loan</span><input type="number" id="edit_bulk_loan" name="loan_amount" min="0" step="1" value="0"></div>
                    </div>
                </div>
                <button type="submit" name="edit_bulk_sale" class="btn btn-primary">Save Changes</button>
            </form>
        </div>
    </div>

    <!-- Edit Retail Sale Modal -->
    <div id="editRetailModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('editRetailModal')">&times;</span>
            <h2>Edit Retail Sale</h2>
            <form method="POST" id="editRetailForm">
                <input type="hidden" name="sale_id" id="edit_retail_id">
                <div class="form-group">
                    <label>Product</label>
                    <input type="text" id="edit_retail_product_label" disabled style="background:var(--gray-100);color:var(--secondary);">
                </div>
                <div class="form-group">
                    <label for="edit_retail_date">Date*</label>
                    <input type="date" id="edit_retail_date" name="sale_date" required>
                </div>
                <div class="form-group">
                    <label for="edit_retail_qty">Pieces Sold*</label>
                    <input type="number" id="edit_retail_qty" name="pieces_sold" required min="1">
                </div>
                <div class="form-group">
                    <label for="edit_retail_price">Price per Piece*</label>
                    <input type="number" id="edit_retail_price" name="selling_price" required min="1" step="1">
                </div>
                <div class="form-group">
                    <label for="edit_retail_customer">Customer Name</label>
                    <input type="text" id="edit_retail_customer" name="customer_name">
                </div>
                <div class="form-group">
                    <label>Payment Split</label>
                    <div class="split-payment-box">
                        <div class="split-row"><span class="split-label">Cash</span><input type="number" id="edit_retail_cash" name="cash_amount" min="0" step="1" value="0"></div>
                        <div class="split-row"><span class="split-label">Momo</span><input type="number" id="edit_retail_momo" name="momo_amount" min="0" step="1" value="0"></div>
                        <div class="split-row"><span class="split-label">Loan</span><input type="number" id="edit_retail_loan" name="loan_amount" min="0" step="1" value="0"></div>
                    </div>
                </div>
                <button type="submit" name="edit_retail_sale" class="btn btn-primary">Save Changes</button>
            </form>
        </div>
    </div>

    <!-- Edit External Sale Modal -->
    <div id="editExternalModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('editExternalModal')">&times;</span>
            <h2>Edit External Sale</h2>
            <form method="POST" id="editExternalForm">
                <input type="hidden" name="sale_id" id="edit_ext_id">
                <div class="form-group">
                    <label for="edit_ext_date">Date*</label>
                    <input type="date" id="edit_ext_date" name="sale_date" required>
                </div>
                <div class="form-group">
                    <label for="edit_ext_product">Product Name*</label>
                    <input type="text" id="edit_ext_product" name="product_name" required>
                </div>
                <div class="form-group">
                    <label for="edit_ext_qty">Quantity*</label>
                    <input type="number" id="edit_ext_qty" name="quantity" required min="1">
                </div>
                <div class="form-group">
                    <label for="edit_ext_price">Unit Price*</label>
                    <input type="number" id="edit_ext_price" name="unit_price" required min="1" step="1">
                </div>
                <div class="form-group">
                    <label for="edit_ext_revenue">My Commission (RWF)</label>
                    <input type="number" id="edit_ext_revenue" name="my_revenue" min="0" step="1" value="0">
                </div>
                <div class="form-group">
                    <label for="edit_ext_customer">Customer Name</label>
                    <input type="text" id="edit_ext_customer" name="customer_name">
                </div>
                <div class="form-group">
                    <label>Payment Split</label>
                    <div class="split-payment-box">
                        <div class="split-row"><span class="split-label">Cash</span><input type="number" id="edit_ext_cash" name="cash_amount" min="0" step="1" value="0"></div>
                        <div class="split-row"><span class="split-label">Momo</span><input type="number" id="edit_ext_momo" name="momo_amount" min="0" step="1" value="0"></div>
                        <div class="split-row"><span class="split-label">Loan</span><input type="number" id="edit_ext_loan" name="loan_amount" min="0" step="1" value="0"></div>
                    </div>
                </div>
                <button type="submit" name="edit_external_sale" class="btn btn-primary">Save Changes</button>
            </form>
        </div>
    </div>

    <script src="script.js"></script>
    <script>
        // --- Searchable Select Init ---
        function initSearchableSelect(wrapperId, searchInputId, dropdownId, hiddenSelectId) {
            var wrapper = document.getElementById(wrapperId);
            var searchInput = document.getElementById(searchInputId);
            var dropdown = document.getElementById(dropdownId);
            var hiddenSelect = document.getElementById(hiddenSelectId);
            if (!wrapper || !searchInput || !dropdown || !hiddenSelect) return;
            var options = dropdown.querySelectorAll('.searchable-select-option');
            var highlightedIndex = -1;

            searchInput.addEventListener('focus', function() {
                dropdown.classList.add('open');
                filterOptions();
            });

            searchInput.addEventListener('input', function() {
                dropdown.classList.add('open');
                highlightedIndex = -1;
                filterOptions();
            });

            searchInput.addEventListener('keydown', function(e) {
                var visible = dropdown.querySelectorAll('.searchable-select-option:not(.hidden)');
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    highlightedIndex = Math.min(highlightedIndex + 1, visible.length - 1);
                    updateHighlight(visible);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    highlightedIndex = Math.max(highlightedIndex - 1, 0);
                    updateHighlight(visible);
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    if (highlightedIndex >= 0 && visible[highlightedIndex]) {
                        selectOption(visible[highlightedIndex]);
                    }
                } else if (e.key === 'Escape') {
                    dropdown.classList.remove('open');
                    searchInput.blur();
                }
            });

            document.addEventListener('click', function(e) {
                if (!e.target.closest('#' + wrapperId)) {
                    dropdown.classList.remove('open');
                }
            });

            options.forEach(function(opt) {
                opt.addEventListener('click', function() {
                    selectOption(opt);
                });
            });

            function filterOptions() {
                var term = searchInput.value.toLowerCase();
                options.forEach(function(opt) {
                    if (opt.textContent.trim().toLowerCase().indexOf(term) > -1) {
                        opt.classList.remove('hidden');
                    } else {
                        opt.classList.add('hidden');
                    }
                });
            }

            function updateHighlight(visible) {
                options.forEach(function(o) { o.classList.remove('highlighted'); });
                if (visible[highlightedIndex]) {
                    visible[highlightedIndex].classList.add('highlighted');
                    visible[highlightedIndex].scrollIntoView({ block: 'nearest' });
                }
            }

            function selectOption(opt) {
                var value = opt.getAttribute('data-value');
                searchInput.value = opt.textContent.trim();
                dropdown.classList.remove('open');
                highlightedIndex = -1;

                // Sync hidden select and trigger its onchange
                hiddenSelect.value = value;
                hiddenSelect.dispatchEvent(new Event('change'));
            }
        }

        initSearchableSelect('bulkProductSearchable', 'bulk_product_search', 'bulk_product_dropdown', 'bulk_product_id');
        initSearchableSelect('retailProductSearchable', 'retail_product_search', 'retail_product_dropdown', 'retail_product_id');

        // --- Bulk Sale ---
        var bulkCoreValid = false;

        function updateBulkProductDetails() {
            const select = document.getElementById('bulk_product_id');
            const opt = select.options[select.selectedIndex];
            if (!opt.value) {
                document.getElementById('bulk_product_details').style.display = 'none';
                document.getElementById('bulk_stock_info').innerHTML = '';
                document.getElementById('bulk_selling_price').value = '';
                document.getElementById('bulk_quantity').value = '';
                document.getElementById('bulk_quantity').max = '';
                document.getElementById('bulk_summary').style.display = 'none';
                document.getElementById('bulk_payment_section').style.display = 'none';
                document.getElementById('bulk_level_selector').style.display = 'none';
                document.getElementById('bulk_level_buttons').innerHTML = '';
                document.getElementById('bulk_qty_label').textContent = 'Quantity (Packages)*';
                document.getElementById('bulk_level_divisor').value = 1;
                document.getElementById('bulk_submit_btn').disabled = true;
                return;
            }
            const price = opt.dataset.price;
            const stock = opt.dataset.stock;
            const name = opt.dataset.productName;

            document.getElementById('bulk_selling_price').value = price;
            document.getElementById('bulk_quantity').value = '';
            document.getElementById('bulk_quantity').max = stock;
            document.getElementById('bulk_stock_info').innerHTML = 'Available: ' + stock + ' packages';
            document.getElementById('bulk_product_details').style.display = 'block';
            document.getElementById('bulk_product_info').innerHTML = name + ' &mdash; Default price: RWF ' + parseFloat(price).toLocaleString();
            // Reset split fields on product change
            document.getElementById('bulk_cash').value = 0;
            document.getElementById('bulk_momo').value = 0;
            document.getElementById('bulk_loan_split').value = 0;
            document.getElementById('bulk_payment_section').style.display = 'none';
            calculateBulkTotal();

            // Fetch packaging levels
            document.getElementById('bulk_level_divisor').value = 1;
            fetch('ajax_levels.php?product_id=' + opt.value)
                .then(function(r){ return r.json(); })
                .then(function(data) {
                    var sel  = document.getElementById('bulk_level_selector');
                    var btns = document.getElementById('bulk_level_buttons');
                    btns.innerHTML = '';
                    if (data.ok && data.levels && data.levels.length > 0) {
                        var running  = parseInt(data.stock_qty) || 0;
                        var divisor  = 1;
                        data.levels.forEach(function(lvl, i) {
                            if (i > 0) {
                                divisor *= (parseInt(lvl.qty_per_parent) || 1);
                                running  *= (parseInt(lvl.qty_per_parent) || 1);
                            }
                            var btn = document.createElement('button');
                            btn.type = 'button';
                            btn.className = 'lvl-btn';
                            btn.innerHTML =
                                '<span class="lvl-btn-name">' + lvl.level_name + '</span>' +
                                '<span class="lvl-btn-stock">' + running.toLocaleString() + ' avail.</span>' +
                                '<span class="lvl-btn-price">RWF ' + parseInt(lvl.selling_price).toLocaleString() + '</span>';
                            btn.dataset.price   = lvl.selling_price;
                            btn.dataset.stock   = running;
                            btn.dataset.name    = lvl.level_name;
                            btn.dataset.divisor = divisor;
                            btn.onclick = function() {
                                btns.querySelectorAll('.lvl-btn').forEach(function(b){ b.classList.remove('active'); });
                                this.classList.add('active');
                                document.getElementById('bulk_selling_price').value = this.dataset.price;
                                document.getElementById('bulk_quantity').max = this.dataset.stock;
                                document.getElementById('bulk_level_divisor').value = this.dataset.divisor;
                                document.getElementById('bulk_stock_info').innerHTML = 'Available: ' + parseInt(this.dataset.stock).toLocaleString() + ' ' + this.dataset.name;
                                document.getElementById('bulk_qty_label').textContent = 'Quantity (' + this.dataset.name + ')*';
                                calculateBulkTotal();
                            };
                            btns.appendChild(btn);
                        });
                        // Auto-select first level
                        btns.querySelector('.lvl-btn') && btns.querySelector('.lvl-btn').click();
                        sel.style.display = 'block';
                    } else {
                        sel.style.display = 'none';
                    }
                })
                .catch(function(){ document.getElementById('bulk_level_selector').style.display = 'none'; });
        }

        function setBulkDefaultPrice() {
            var activeBtn = document.querySelector('#bulk_level_buttons .lvl-btn.active');
            if (activeBtn) {
                document.getElementById('bulk_selling_price').value = activeBtn.dataset.price;
            } else {
                const opt = document.getElementById('bulk_product_id').options[document.getElementById('bulk_product_id').selectedIndex];
                if (opt.value) document.getElementById('bulk_selling_price').value = opt.dataset.price;
            }
            calculateBulkTotal();
        }

        function calculateBulkTotal() {
            const select = document.getElementById('bulk_product_id');
            const opt = select.options[select.selectedIndex];
            if (!opt.value) return;

            const qtyInput = document.getElementById('bulk_quantity');
            const stock = parseInt(qtyInput.max) > 0 ? parseInt(qtyInput.max) : (parseInt(opt.dataset.stock) || 0);
            var activeBulkBtn = document.querySelector('#bulk_level_buttons .lvl-btn.active');
            const defaultPrice = activeBulkBtn ? (parseFloat(activeBulkBtn.dataset.price) || 0) : (parseFloat(opt.dataset.price) || 0);
            const qty = parseInt(document.getElementById('bulk_quantity').value) || 0;
            const price = parseFloat(document.getElementById('bulk_selling_price').value) || 0;
            const total = qty * price;
            const qtyError = document.getElementById('bulk_qty_error');
            const priceWarning = document.getElementById('bulk_price_warning');
            const summary = document.getElementById('bulk_summary');
            let valid = true;

            if (qty > stock) {
                qtyError.innerHTML = 'Exceeds available stock (' + stock + ')!';
                qtyError.style.display = 'block';
                valid = false;
            } else {
                qtyError.style.display = 'none';
            }

            if (price > 0 && defaultPrice > 0 && price !== defaultPrice) {
                const diff = ((price - defaultPrice) / defaultPrice * 100).toFixed(1);
                priceWarning.innerHTML = 'Price is ' + (price > defaultPrice ? '+' : '') + diff + '% from default (RWF ' + defaultPrice.toLocaleString() + ')';
                priceWarning.style.display = 'block';
            } else {
                priceWarning.style.display = 'none';
            }

            if (qty < 1 || price < 1) valid = false;

            bulkCoreValid = valid;

            if (valid) {
                document.getElementById('bulk_sum_product').textContent = opt.dataset.productName;
                document.getElementById('bulk_sum_qty').textContent = qty;
                document.getElementById('bulk_sum_price').textContent = 'RWF ' + price.toLocaleString();
                document.getElementById('bulk_sum_total').textContent = 'RWF ' + total.toLocaleString();
                summary.style.display = 'block';
                // Show payment section; default Cash = total if all splits are 0
                var paySection = document.getElementById('bulk_payment_section');
                var cash = parseFloat(document.getElementById('bulk_cash').value) || 0;
                var momo = parseFloat(document.getElementById('bulk_momo').value) || 0;
                var loan = parseFloat(document.getElementById('bulk_loan_split').value) || 0;
                if (cash === 0 && momo === 0 && loan === 0) {
                    document.getElementById('bulk_momo').value = total;
                }
                paySection.style.display = 'block';
                calcBulkSplit();
            } else {
                summary.style.display = 'none';
                document.getElementById('bulk_payment_section').style.display = 'none';
                document.getElementById('bulk_submit_btn').disabled = true;
            }
        }

        function calcBulkSplit(changed) {
            var qty   = parseInt(document.getElementById('bulk_quantity').value) || 0;
            var price = parseFloat(document.getElementById('bulk_selling_price').value) || 0;
            var total = qty * price;
            var cashEl = document.getElementById('bulk_cash');
            var momoEl = document.getElementById('bulk_momo');
            var loanEl = document.getElementById('bulk_loan_split');
            var cash = parseFloat(cashEl.value) || 0;
            var momo = parseFloat(momoEl.value) || 0;
            var loan = parseFloat(loanEl.value) || 0;

            // Cascade: auto-fill the next field with the remaining
            if (changed === 'cash') {
                momo = Math.max(0, total - cash - loan);
                momoEl.value = momo;
            } else if (changed === 'momo') {
                loan = Math.max(0, total - cash - momo);
                loanEl.value = loan;
            } else if (changed === 'loan') {
                momo = Math.max(0, total - cash - loan);
                momoEl.value = momo;
            }

            var remaining = Math.round(total - cash - momo - loan);
            var splitOk = remaining === 0;

            var remEl = document.getElementById('bulk_remaining');
            var remRow = document.getElementById('bulk_remaining_row');
            remEl.textContent = 'RWF ' + remaining.toLocaleString();
            remRow.className = 'split-row split-remaining-row ' + (splitOk ? 'valid' : 'invalid');

            document.getElementById('bulk_loan_fields').style.display = loan > 0 ? 'block' : 'none';

            var clientOk = loan <= 0 || (
                document.getElementById('bulk_customer').value.trim().length > 0 &&
                document.getElementById('bulk_phone').value.trim().length > 0
            );
            document.getElementById('bulk_submit_btn').disabled = !(bulkCoreValid && splitOk && clientOk);
        }

        function confirmBulkSale() {
            var opt = document.getElementById('bulk_product_id').options[document.getElementById('bulk_product_id').selectedIndex];
            var qty = parseInt(document.getElementById('bulk_quantity').value) || 0;
            var price = parseFloat(document.getElementById('bulk_selling_price').value);
            var total = qty * price;
            var cash = parseFloat(document.getElementById('bulk_cash').value) || 0;
            var momo = parseFloat(document.getElementById('bulk_momo').value) || 0;
            var loan = parseFloat(document.getElementById('bulk_loan_split').value) || 0;
            var divisor = parseInt(document.getElementById('bulk_level_divisor').value) || 1;
            var pkgsDeducted = Math.ceil(qty / divisor);
            var activeBtn = document.querySelector('#bulk_level_buttons .lvl-btn.active');
            var levelName = activeBtn ? activeBtn.dataset.name : 'Package';
            var parts = [];
            if (cash > 0) parts.push('Cash: RWF ' + cash.toLocaleString());
            if (momo > 0) parts.push('Momo: RWF ' + momo.toLocaleString());
            if (loan > 0) parts.push('Loan: RWF ' + loan.toLocaleString() + ' (deferred)');
            return confirm(
                'Confirm Sale?\n\n' +
                'Product: ' + opt.dataset.productName + '\n' +
                'Qty: ' + qty + ' ' + levelName + '\n' +
                'Price/' + levelName + ': RWF ' + price.toLocaleString() + '\n' +
                'Total: RWF ' + total.toLocaleString() + '\n' +
                'Payment: ' + parts.join(' | ') + '\n\n' +
                'Stock deduction: ' + pkgsDeducted + ' package(s) from warehouse.'
            );
        }

        // --- Retail Sale ---
        var retailCoreValid = false;

        function updateRetailProductDetails() {
            const select = document.getElementById('retail_product_id');
            const opt = select.options[select.selectedIndex];
            if (!opt.value) {
                document.getElementById('retail_product_details').style.display = 'none';
                document.getElementById('retail_stock_info').innerHTML = '';
                document.getElementById('retail_selling_price').value = '';
                document.getElementById('pieces_sold').value = '';
                document.getElementById('pieces_sold').max = '';
                document.getElementById('retail_summary').style.display = 'none';
                document.getElementById('retail_payment_section').style.display = 'none';
                document.getElementById('retail_level_selector').style.display = 'none';
                document.getElementById('retail_level_buttons').innerHTML = '';
                document.getElementById('retail_qty_label').textContent = 'Number of Pieces*';
                document.getElementById('retail_level_multiplier').value = 1;
                document.getElementById('retail_submit_btn').disabled = true;
                return;
            }
            const price = opt.dataset.price;
            const stock = opt.dataset.stock;
            const name = opt.dataset.productName;

            document.getElementById('retail_selling_price').value = price;
            document.getElementById('pieces_sold').value = '';
            document.getElementById('pieces_sold').max = stock;
            document.getElementById('retail_stock_info').innerHTML = 'Available: ' + stock + ' pieces';
            document.getElementById('retail_product_details').style.display = 'block';
            document.getElementById('retail_product_info').innerHTML = name + ' &mdash; Default price: RWF ' + parseFloat(price).toLocaleString() + '/piece';
            // Reset split fields on product change
            document.getElementById('retail_cash').value = 0;
            document.getElementById('retail_momo').value = 0;
            document.getElementById('retail_loan_split').value = 0;
            document.getElementById('retail_payment_section').style.display = 'none';
            calculateRetailTotal();

            // Fetch packaging levels for level selector
            var retail_pieces = parseInt(opt.dataset.stock) || 0;
            document.getElementById('retail_level_multiplier').value = 1;
            fetch('ajax_levels.php?product_id=' + opt.value)
                .then(function(r){ return r.json(); })
                .then(function(data) {
                    var sel  = document.getElementById('retail_level_selector');
                    var btns = document.getElementById('retail_level_buttons');
                    btns.innerHTML = '';
                    if (data.ok && data.levels && data.levels.length > 0) {
                        // Build multipliers from last level upward
                        // mults[i] = how many retail pieces equal one unit of level i
                        var mults = new Array(data.levels.length).fill(1);
                        for (var i = data.levels.length - 1; i >= 1; i--) {
                            mults[i - 1] = mults[i] * (parseInt(data.levels[i].qty_per_parent) || 1);
                        }
                        data.levels.forEach(function(lvl, i) {
                            var available = Math.floor(retail_pieces / mults[i]);
                            var btn = document.createElement('button');
                            btn.type = 'button';
                            btn.className = 'lvl-btn';
                            btn.innerHTML =
                                '<span class="lvl-btn-name">' + lvl.level_name + '</span>' +
                                '<span class="lvl-btn-stock">' + available.toLocaleString() + ' avail.</span>' +
                                '<span class="lvl-btn-price">RWF ' + parseInt(lvl.selling_price).toLocaleString() + '</span>';
                            btn.dataset.price      = lvl.selling_price;
                            btn.dataset.available  = available;
                            btn.dataset.multiplier = mults[i];
                            btn.dataset.name       = lvl.level_name;
                            btn.onclick = function() {
                                btns.querySelectorAll('.lvl-btn').forEach(function(b){ b.classList.remove('active'); });
                                this.classList.add('active');
                                document.getElementById('retail_selling_price').value = this.dataset.price;
                                document.getElementById('pieces_sold').max = this.dataset.available;
                                document.getElementById('retail_level_multiplier').value = this.dataset.multiplier;
                                document.getElementById('retail_stock_info').innerHTML =
                                    'Available: ' + parseInt(this.dataset.available).toLocaleString() + ' ' + this.dataset.name;
                                document.getElementById('retail_qty_label').textContent =
                                    'Quantity (' + this.dataset.name + ')*';
                                calculateRetailTotal();
                            };
                            btns.appendChild(btn);
                        });
                        // Auto-select last level (smallest unit) by default
                        var allBtns = btns.querySelectorAll('.lvl-btn');
                        allBtns[allBtns.length - 1] && allBtns[allBtns.length - 1].click();
                        sel.style.display = 'block';
                    } else {
                        sel.style.display = 'none';
                    }
                })
                .catch(function() { document.getElementById('retail_level_selector').style.display = 'none'; });
        }

        function setRetailDefaultPrice() {
            var activeBtn = document.querySelector('#retail_level_buttons .lvl-btn.active');
            if (activeBtn) {
                document.getElementById('retail_selling_price').value = activeBtn.dataset.price;
            } else {
                const opt = document.getElementById('retail_product_id').options[document.getElementById('retail_product_id').selectedIndex];
                if (opt.value) document.getElementById('retail_selling_price').value = opt.dataset.price;
            }
            calculateRetailTotal();
        }

        function calculateRetailTotal() {
            const select = document.getElementById('retail_product_id');
            const opt = select.options[select.selectedIndex];
            if (!opt.value) return;

            const piecesInput = document.getElementById('pieces_sold');
            const stock = parseInt(piecesInput.max) > 0 ? parseInt(piecesInput.max) : (parseInt(opt.dataset.stock) || 0);
            var activeRetailBtn = document.querySelector('#retail_level_buttons .lvl-btn.active');
            const defaultPrice = activeRetailBtn ? (parseFloat(activeRetailBtn.dataset.price) || 0) : (parseFloat(opt.dataset.price) || 0);
            const qty = parseInt(document.getElementById('pieces_sold').value) || 0;
            const price = parseFloat(document.getElementById('retail_selling_price').value) || 0;
            const total = qty * price;
            const qtyError = document.getElementById('retail_qty_error');
            const priceWarning = document.getElementById('retail_price_warning');
            const summary = document.getElementById('retail_summary');
            let valid = true;

            if (qty > stock) {
                qtyError.innerHTML = 'Exceeds available stock (' + stock + ' pieces)!';
                qtyError.style.display = 'block';
                valid = false;
            } else {
                qtyError.style.display = 'none';
            }

            if (price > 0 && defaultPrice > 0 && price !== defaultPrice) {
                const diff = ((price - defaultPrice) / defaultPrice * 100).toFixed(1);
                priceWarning.innerHTML = 'Price is ' + (price > defaultPrice ? '+' : '') + diff + '% from default (RWF ' + defaultPrice.toLocaleString() + ')';
                priceWarning.style.display = 'block';
            } else {
                priceWarning.style.display = 'none';
            }

            if (qty < 1 || price < 1) valid = false;

            retailCoreValid = valid;

            if (valid) {
                document.getElementById('retail_sum_product').textContent = opt.dataset.productName;
                document.getElementById('retail_sum_qty').textContent = qty;
                document.getElementById('retail_sum_price').textContent = 'RWF ' + price.toLocaleString();
                document.getElementById('retail_sum_total').textContent = 'RWF ' + total.toLocaleString();
                summary.style.display = 'block';
                var cash = parseFloat(document.getElementById('retail_cash').value) || 0;
                var momo = parseFloat(document.getElementById('retail_momo').value) || 0;
                var loan = parseFloat(document.getElementById('retail_loan_split').value) || 0;
                if (cash === 0 && momo === 0 && loan === 0) {
                    document.getElementById('retail_momo').value = total;
                }
                document.getElementById('retail_payment_section').style.display = 'block';
                calcRetailSplit();
            } else {
                summary.style.display = 'none';
                document.getElementById('retail_payment_section').style.display = 'none';
                document.getElementById('retail_submit_btn').disabled = true;
            }
        }

        function calcRetailSplit(changed) {
            var qty   = parseInt(document.getElementById('pieces_sold').value) || 0;
            var price = parseFloat(document.getElementById('retail_selling_price').value) || 0;
            var total = qty * price;
            var cashEl = document.getElementById('retail_cash');
            var momoEl = document.getElementById('retail_momo');
            var loanEl = document.getElementById('retail_loan_split');
            var cash = parseFloat(cashEl.value) || 0;
            var momo = parseFloat(momoEl.value) || 0;
            var loan = parseFloat(loanEl.value) || 0;

            // Cascade: auto-fill the next field with the remaining
            if (changed === 'cash') {
                momo = Math.max(0, total - cash - loan);
                momoEl.value = momo;
            } else if (changed === 'momo') {
                loan = Math.max(0, total - cash - momo);
                loanEl.value = loan;
            } else if (changed === 'loan') {
                momo = Math.max(0, total - cash - loan);
                momoEl.value = momo;
            }

            var remaining = Math.round(total - cash - momo - loan);
            var splitOk = remaining === 0;

            var remEl = document.getElementById('retail_remaining');
            var remRow = document.getElementById('retail_remaining_row');
            remEl.textContent = 'RWF ' + remaining.toLocaleString();
            remRow.className = 'split-row split-remaining-row ' + (splitOk ? 'valid' : 'invalid');

            document.getElementById('retail_loan_fields').style.display = loan > 0 ? 'block' : 'none';

            var clientOk = loan <= 0 || (
                document.getElementById('retail_customer').value.trim().length > 0 &&
                document.getElementById('retail_phone').value.trim().length > 0
            );
            document.getElementById('retail_submit_btn').disabled = !(retailCoreValid && splitOk && clientOk);
        }

        function confirmRetailSale() {
            var opt = document.getElementById('retail_product_id').options[document.getElementById('retail_product_id').selectedIndex];
            var qty = document.getElementById('pieces_sold').value;
            var price = parseFloat(document.getElementById('retail_selling_price').value);
            var total = qty * price;
            var cash = parseFloat(document.getElementById('retail_cash').value) || 0;
            var momo = parseFloat(document.getElementById('retail_momo').value) || 0;
            var loan = parseFloat(document.getElementById('retail_loan_split').value) || 0;
            var parts = [];
            if (cash > 0) parts.push('Cash: RWF ' + cash.toLocaleString());
            if (momo > 0) parts.push('Momo: RWF ' + momo.toLocaleString());
            if (loan > 0) parts.push('Loan: RWF ' + loan.toLocaleString() + ' (deferred)');
            return confirm(
                'Confirm Retail Sale?\n\n' +
                'Product: ' + opt.dataset.productName + '\n' +
                'Pieces: ' + qty + '\n' +
                'Price/Piece: RWF ' + price.toLocaleString() + '\n' +
                'Total: RWF ' + total.toLocaleString() + '\n' +
                'Payment: ' + parts.join(' | ') + '\n\n' +
                'This will deduct ' + qty + ' piece(s) from retail stock.'
            );
        }

        function initLoanClientPicker(wrapId, searchId, dropdownId, clientInputId, phoneInputId) {
            var wrap = document.getElementById(wrapId);
            if (!wrap) return;
            var search   = document.getElementById(searchId);
            var dropdown = document.getElementById(dropdownId);
            var options  = dropdown.querySelectorAll('.searchable-select-option');
            var hi = -1;

            search.addEventListener('focus', function() { dropdown.classList.add('open'); filter(); });
            search.addEventListener('input', function() { dropdown.classList.add('open'); hi = -1; filter(); });
            search.addEventListener('keydown', function(e) {
                var vis = dropdown.querySelectorAll('.searchable-select-option:not(.hidden)');
                if (e.key === 'ArrowDown') { e.preventDefault(); hi = Math.min(hi+1, vis.length-1); hl(vis); }
                else if (e.key === 'ArrowUp') { e.preventDefault(); hi = Math.max(hi-1,0); hl(vis); }
                else if (e.key === 'Enter') { e.preventDefault(); if (hi>=0&&vis[hi]) pick(vis[hi]); }
                else if (e.key === 'Escape') dropdown.classList.remove('open');
            });
            document.addEventListener('click', function(e) {
                if (!e.target.closest('#' + wrapId)) dropdown.classList.remove('open');
            });
            options.forEach(function(o) { o.addEventListener('click', function() { pick(o); }); });

            function filter() {
                var term = search.value.toLowerCase();
                options.forEach(function(o) { o.classList.toggle('hidden', o.textContent.trim().toLowerCase().indexOf(term)===-1); });
            }
            function hl(vis) {
                options.forEach(function(o) { o.classList.remove('highlighted'); });
                if (vis[hi]) { vis[hi].classList.add('highlighted'); vis[hi].scrollIntoView({block:'nearest'}); }
            }
            function pick(opt) {
                document.getElementById(clientInputId).value = opt.getAttribute('data-client');
                var phoneEl = document.getElementById(phoneInputId);
                phoneEl.value = opt.getAttribute('data-phone');
                phoneEl.dispatchEvent(new Event('input'));
                search.value = opt.getAttribute('data-client');
                dropdown.classList.remove('open'); hi = -1;
            }
        }

        initLoanClientPicker('bulkClientPickerWrap',   'bulk_client_picker_search',   'bulk_client_picker_dropdown',   'bulk_customer',   'bulk_phone');
        initLoanClientPicker('retailClientPickerWrap', 'retail_client_picker_search', 'retail_client_picker_dropdown', 'retail_customer', 'retail_phone');

        function submitFormWithAction(formId, actionName) {
            var form = document.getElementById(formId);
            var inp = document.createElement('input');
            inp.type = 'hidden'; inp.name = actionName; inp.value = '1';
            form.appendChild(inp);
            form.submit();
        }
        function handleBulkSubmit() {
            if (confirmBulkSale()) submitFormWithAction('bulkSaleForm', 'bulk_sale');
        }
        function handleRetailSubmit() {
            if (confirmRetailSale()) submitFormWithAction('retailSaleForm', 'retail_sale');
        }

        // --- External Sale product picker ---
        (function() {
            var search   = document.getElementById('ext_product_search');
            var dropdown = document.getElementById('ext_product_dropdown');
            if (!search || !dropdown) return;
            var options  = dropdown.querySelectorAll('.searchable-select-option');
            var hi = -1;

            search.addEventListener('focus', function() { dropdown.classList.add('open'); filter(); });
            search.addEventListener('input',  function() { dropdown.classList.add('open'); hi = -1; filter(); });
            search.addEventListener('keydown', function(e) {
                var vis = dropdown.querySelectorAll('.searchable-select-option:not(.hidden)');
                if (e.key === 'ArrowDown') { e.preventDefault(); hi = Math.min(hi+1, vis.length-1); hl(vis); }
                else if (e.key === 'ArrowUp') { e.preventDefault(); hi = Math.max(hi-1, 0); hl(vis); }
                else if (e.key === 'Enter') { e.preventDefault(); if (hi >= 0 && vis[hi]) pick(vis[hi]); }
                else if (e.key === 'Escape') dropdown.classList.remove('open');
            });
            document.addEventListener('click', function(e) {
                if (!e.target.closest('#extProductSearchable')) dropdown.classList.remove('open');
            });
            options.forEach(function(o) { o.addEventListener('click', function() { pick(o); }); });

            function filter() {
                var term = search.value.toLowerCase();
                options.forEach(function(o) { o.classList.toggle('hidden', o.textContent.trim().toLowerCase().indexOf(term) === -1); });
            }
            function hl(vis) {
                options.forEach(function(o) { o.classList.remove('highlighted'); });
                if (vis[hi]) { vis[hi].classList.add('highlighted'); vis[hi].scrollIntoView({block:'nearest'}); }
            }
            function pick(opt) {
                var name  = opt.getAttribute('data-name');
                var id    = opt.getAttribute('data-id') || '0';
                var bulk  = parseFloat(opt.getAttribute('data-bulk'))  || 0;
                var retail= parseFloat(opt.getAttribute('data-retail')) || 0;
                document.getElementById('ext_product_name').value = name;
                document.getElementById('ext_product_id').value = id;
                search.value = name;
                dropdown.classList.remove('open'); hi = -1;
                // Suggest price: prefer bulk price, fallback retail
                var priceEl = document.getElementById('ext_unit_price');
                if ((priceEl.value === '' || parseFloat(priceEl.value) === 0) && (bulk > 0 || retail > 0)) {
                    priceEl.value = bulk > 0 ? bulk : retail;
                }
                calcExtTotal();
            }
        })();

        function extSwitchToManual() {
            document.getElementById('ext_picker_mode').style.display = 'none';
            document.getElementById('ext_manual_mode').style.display = 'block';
            document.getElementById('ext_product_name').value = '';
            document.getElementById('ext_product_id').value = '0';
            document.getElementById('ext_manual_name').focus();
            calcExtTotal();
        }
        function extSwitchToPicker() {
            document.getElementById('ext_manual_mode').style.display = 'none';
            document.getElementById('ext_picker_mode').style.display = 'block';
            document.getElementById('ext_product_name').value = document.getElementById('ext_product_search').getAttribute('data-selected') || '';
            calcExtTotal();
        }
        function extSetManualName(val) {
            document.getElementById('ext_product_name').value = val.trim();
            calcExtTotal();
        }

        // --- External Sale ---
        var extCoreValid = false;

        function calcExtTotal() {
            var name  = document.getElementById('ext_product_name').value.trim();
            var qty   = parseInt(document.getElementById('ext_quantity').value) || 0;
            var price = parseFloat(document.getElementById('ext_unit_price').value) || 0;
            var total = qty * price;
            var valid = name.length > 0 && qty > 0 && price > 0;
            extCoreValid = valid;

            if (valid) {
                document.getElementById('ext_sum_product').textContent = name;
                document.getElementById('ext_sum_qty').textContent = qty;
                document.getElementById('ext_sum_price').textContent = 'RWF ' + price.toLocaleString();
                document.getElementById('ext_sum_total').textContent = 'RWF ' + total.toLocaleString();
                document.getElementById('ext_summary').style.display = 'block';
                // Auto-default momo to total when all splits are still 0
                var cash = parseFloat(document.getElementById('ext_cash').value) || 0;
                var momo = parseFloat(document.getElementById('ext_momo').value) || 0;
                var loan = parseFloat(document.getElementById('ext_loan').value) || 0;
                if (cash === 0 && momo === 0 && loan === 0) {
                    document.getElementById('ext_momo').value = total;
                }
            } else {
                document.getElementById('ext_summary').style.display = 'none';
                document.getElementById('ext_submit_btn').disabled = true;
            }
            calcExtSplit();
        }

        function calcExtSplit(changed) {
            var qty   = parseInt(document.getElementById('ext_quantity').value) || 0;
            var price = parseFloat(document.getElementById('ext_unit_price').value) || 0;
            var total = qty * price;
            var cashEl = document.getElementById('ext_cash');
            var momoEl = document.getElementById('ext_momo');
            var loanEl = document.getElementById('ext_loan');
            var cash = parseFloat(cashEl.value) || 0;
            var momo = parseFloat(momoEl.value) || 0;
            var loan = parseFloat(loanEl.value) || 0;

            if (changed === 'cash') {
                momo = Math.max(0, total - cash - loan);
                momoEl.value = momo;
            } else if (changed === 'momo') {
                loan = Math.max(0, total - cash - momo);
                loanEl.value = loan;
            } else if (changed === 'loan') {
                momo = Math.max(0, total - cash - loan);
                momoEl.value = momo;
            }

            var remaining = Math.round(total - cash - momo - loan);
            var splitOk = extCoreValid && remaining === 0;
            var remEl = document.getElementById('ext_remaining');
            var remRow = document.getElementById('ext_remaining_row');
            if (!extCoreValid) {
                remEl.textContent = '—';
                remRow.className = 'split-row split-remaining-row';
            } else {
                remEl.textContent = 'RWF ' + remaining.toLocaleString();
                remRow.className = 'split-row split-remaining-row ' + (splitOk ? 'valid' : 'invalid');
            }

            document.getElementById('ext_loan_fields').style.display = loan > 0 ? 'block' : 'none';

            var clientOk = loan <= 0 || (
                document.getElementById('ext_customer_name').value.trim().length > 0 &&
                document.getElementById('ext_phone').value.trim().length > 0
            );
            document.getElementById('ext_submit_btn').disabled = !(extCoreValid && splitOk && clientOk);
        }

        function handleExtSubmit() {
            var name  = document.getElementById('ext_product_name').value.trim();
            var qty   = document.getElementById('ext_quantity').value;
            var price = parseFloat(document.getElementById('ext_unit_price').value);
            var total = qty * price;
            var cash  = parseFloat(document.getElementById('ext_cash').value) || 0;
            var momo  = parseFloat(document.getElementById('ext_momo').value) || 0;
            var loan  = parseFloat(document.getElementById('ext_loan').value) || 0;
            var parts = [];
            if (cash > 0) parts.push('Cash: RWF ' + cash.toLocaleString());
            if (momo > 0) parts.push('Momo: RWF ' + momo.toLocaleString());
            if (loan > 0) parts.push('Loan: RWF ' + loan.toLocaleString() + ' (deferred)');
            var ok = confirm(
                'Confirm External Sale?\n\n' +
                'Product: ' + name + '\n' +
                'Quantity: ' + qty + '\n' +
                'Unit Price: RWF ' + price.toLocaleString() + '\n' +
                'Total: RWF ' + total.toLocaleString() + '\n' +
                'Payment: ' + parts.join(' | ') + '\n\n' +
                'No stock will be deducted.'
            );
            if (ok) submitFormWithAction('externalSaleForm', 'external_sale');
        }

        initLoanClientPicker('extClientPickerWrap', 'ext_client_picker_search', 'ext_client_picker_dropdown', 'ext_customer_name', 'ext_phone');

        // --- External Sale Owner Picker ---
        function extSyncOwner() {
            var name  = document.getElementById('ext_owner_name_input').value.trim();
            var phone = document.getElementById('ext_owner_phone_input').value.trim();
            document.getElementById('ext_owner_name').value  = name;
            document.getElementById('ext_owner_phone').value = phone;
            // clear search box if user is typing a fresh name
            var search = document.getElementById('ext_owner_search');
            if (search && search.value !== name) search.value = '';
        }

        (function() {
            var wrap     = document.getElementById('extOwnerPickerWrap');
            if (!wrap) return;
            var search   = document.getElementById('ext_owner_search');
            var dropdown = document.getElementById('ext_owner_dropdown');
            var options  = dropdown.querySelectorAll('.searchable-select-option');
            var hi = -1;

            search.addEventListener('focus', function() { dropdown.classList.add('open'); filter(); });
            search.addEventListener('input',  function() { dropdown.classList.add('open'); hi = -1; filter(); });
            search.addEventListener('keydown', function(e) {
                var vis = dropdown.querySelectorAll('.searchable-select-option:not(.hidden)');
                if (e.key === 'ArrowDown') { e.preventDefault(); hi = Math.min(hi+1, vis.length-1); hl(vis); }
                else if (e.key === 'ArrowUp') { e.preventDefault(); hi = Math.max(hi-1, 0); hl(vis); }
                else if (e.key === 'Enter') { e.preventDefault(); if (hi >= 0 && vis[hi]) pick(vis[hi]); }
                else if (e.key === 'Escape') dropdown.classList.remove('open');
            });
            document.addEventListener('click', function(e) {
                if (!e.target.closest('#extOwnerPickerWrap')) dropdown.classList.remove('open');
            });
            options.forEach(function(o) { o.addEventListener('click', function() { pick(o); }); });

            function filter() {
                var term = search.value.toLowerCase();
                options.forEach(function(o) { o.classList.toggle('hidden', o.textContent.trim().toLowerCase().indexOf(term) === -1); });
            }
            function hl(vis) {
                options.forEach(function(o) { o.classList.remove('highlighted'); });
                if (vis[hi]) { vis[hi].classList.add('highlighted'); vis[hi].scrollIntoView({block:'nearest'}); }
            }
            function pick(opt) {
                var owner = opt.getAttribute('data-owner');
                var phone = opt.getAttribute('data-phone');
                document.getElementById('ext_owner_name_input').value  = owner;
                document.getElementById('ext_owner_phone_input').value = phone;
                document.getElementById('ext_owner_name').value  = owner;
                document.getElementById('ext_owner_phone').value = phone;
                search.value = owner;
                dropdown.classList.remove('open'); hi = -1;
            }
        })();

        // --- Sales Tabs ---
        function switchSalesTab(tab) {
            ['bulk','retail','external'].forEach(function(t) {
                document.getElementById('stab-' + t).style.display = t === tab ? '' : 'none';
            });
            document.querySelectorAll('.sales-tab-btn').forEach(function(btn, i) {
                btn.classList.toggle('active', ['bulk','retail','external'][i] === tab);
            });
            document.getElementById('filter_tab').value = tab;
            var ownerGroup = document.getElementById('owner_filter_group');
            if (ownerGroup) ownerGroup.style.display = tab === 'external' ? '' : 'none';
        }

        function openEditBulk(id, date, qty, price, customer, cash, momo, loan, productName) {
            document.getElementById('edit_bulk_id').value = id;
            document.getElementById('edit_bulk_date').value = date;
            document.getElementById('edit_bulk_qty').value = qty;
            document.getElementById('edit_bulk_price').value = price;
            document.getElementById('edit_bulk_customer').value = customer;
            document.getElementById('edit_bulk_cash').value = cash;
            document.getElementById('edit_bulk_momo').value = momo;
            document.getElementById('edit_bulk_loan').value = loan;
            document.getElementById('edit_bulk_product_label').value = productName;
            openModal('editBulkModal');
        }

        function openEditRetail(id, date, qty, price, customer, cash, momo, loan, productName) {
            document.getElementById('edit_retail_id').value = id;
            document.getElementById('edit_retail_date').value = date;
            document.getElementById('edit_retail_qty').value = qty;
            document.getElementById('edit_retail_price').value = price;
            document.getElementById('edit_retail_customer').value = customer;
            document.getElementById('edit_retail_cash').value = cash;
            document.getElementById('edit_retail_momo').value = momo;
            document.getElementById('edit_retail_loan').value = loan;
            document.getElementById('edit_retail_product_label').value = productName;
            openModal('editRetailModal');
        }

        function openEditExternal(id, date, productName, qty, price, customer, cash, momo, loan, myRevenue) {
            document.getElementById('edit_ext_id').value = id;
            document.getElementById('edit_ext_date').value = date;
            document.getElementById('edit_ext_product').value = productName;
            document.getElementById('edit_ext_qty').value = qty;
            document.getElementById('edit_ext_price').value = price;
            document.getElementById('edit_ext_revenue').value = myRevenue || 0;
            document.getElementById('edit_ext_customer').value = customer;
            document.getElementById('edit_ext_cash').value = cash;
            document.getElementById('edit_ext_momo').value = momo;
            document.getElementById('edit_ext_loan').value = loan;
            openModal('editExternalModal');
        }

        function escH(s) { return (s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

        // ── Thermal receipt print (single-item sale: bulk/retail/external) ──────
        function printSaleReceipt(type, id, date, product, qty, qtyUnit, unitPrice, total, cash, momo, loan, customer, seller, owner) {
            var typeLabel = type === 'bulk' ? 'Wholesale Sale' : type === 'retail' ? 'Retail Sale' : 'External Sale';

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
                '.grand td{font-weight:bold;border-top:1px solid #000;padding-top:3px;font-size:11px;}' +
                '.footer{text-align:center;font-size:9px;margin-top:4px;}' +
                '@media print{@page{margin:0;size:80mm auto;}body{padding:2mm;}}' +
                '</style></head><body>';

            h += '<h2>SMART STOCK</h2>';
            h += '<div class="sub">' + typeLabel + ' Receipt</div>';
            h += '<hr>';

            h += '<table>';
            h += '<tr><td class="b">Sale #</td><td class="r b">' + id + '</td></tr>';
            h += '<tr><td>Date</td><td class="r">' + escH(date) + '</td></tr>';
            if (owner)    h += '<tr><td>Owner</td><td class="r">' + escH(owner) + '</td></tr>';
            h += '<tr><td>Customer</td><td class="r">' + escH(customer || 'N/A') + '</td></tr>';
            h += '</table>';
            h += '<hr>';

            h += '<table>';
            h += '<tr><td colspan="2" class="item-name">' + escH(product) + '</td></tr>';
            h += '<tr><td>' + Number(qty).toLocaleString() + ' ' + qtyUnit + ' &times; RWF ' + Number(unitPrice).toLocaleString() + '</td>' +
                 '<td class="r b">RWF ' + Number(total).toLocaleString() + '</td></tr>';
            h += '<tr class="grand"><td>TOTAL</td><td class="r">RWF ' + Number(total).toLocaleString() + '</td></tr>';
            h += '</table>';

            if (cash > 0 || momo > 0 || loan > 0) {
                h += '<hr>';
                h += '<table>';
                h += '<tr><td colspan="2" class="b">Payment Received</td></tr>';
                if (cash > 0) h += '<tr><td style="padding-left:6px">Cash</td><td class="r">RWF ' + Number(cash).toLocaleString() + '</td></tr>';
                if (momo > 0) h += '<tr><td style="padding-left:6px">Momo</td><td class="r">RWF ' + Number(momo).toLocaleString() + '</td></tr>';
                if (loan > 0) h += '<tr><td style="padding-left:6px">Loan</td><td class="r">RWF ' + Number(loan).toLocaleString() + '</td></tr>';
                h += '</table>';
            }

            h += '<hr>';
            if (seller) h += '<div class="footer">Sold by ' + escH(seller) + '</div>';
            h += '<div class="footer">Thank you for your business!</div>';
            h += '</body></html>';

            var w = window.open('', '_blank', 'width=340,height=520,toolbar=0,menubar=0,scrollbars=1,resizable=1');
            if (!w) { alert('Allow popups to print receipts.'); return; }
            w.document.write(h);
            w.document.close();
            w.focus();
            setTimeout(function() { w.print(); }, 350);
        }

        function filterTable(tableId, term) {
            var tbl = document.getElementById(tableId);
            if (!tbl) return;
            var rows = tbl.querySelectorAll('tbody tr');
            term = term.toLowerCase().trim();
            rows.forEach(function(row) {
                row.style.display = !term || row.textContent.toLowerCase().indexOf(term) > -1 ? '' : 'none';
            });
        }

        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(a) {
                setTimeout(function() {
                    a.style.opacity = '0';
                    setTimeout(function() { a.style.display = 'none'; }, 300);
                }, 5000);
            });

            // ── Refund modal ────────────────────────────────────────────────────
            // (defined outside DOMContentLoaded so onclick attributes can reach them)

            // Highlight a specific sale row when coming from loans.php
            var params = new URLSearchParams(location.search);
            var hlId = params.get('highlight');
            if (hlId) {
                var row = document.getElementById('sale-row-' + hlId);
                if (row) {
                    row.scrollIntoView({ block: 'center', behavior: 'smooth' });
                    row.style.outline = '2px solid var(--warning, #f59e0b)';
                    row.style.background = '#fef9c3';
                    setTimeout(function() {
                        row.style.transition = 'background 1.5s, outline 1.5s';
                        row.style.background = '';
                        row.style.outline = '';
                    }, 2200);
                }
            }
        });

        function toggleRefundMode(val) {
            document.getElementById('refundLossFields').style.display = val == 0 ? '' : 'none';
        }

        function openRefundModal(saleType, saleId, productId, productName, qty, totalAmount) {
            document.getElementById('ref_sale_type').value    = saleType;
            document.getElementById('ref_sale_id').value      = saleId;
            document.getElementById('ref_product_id').value   = productId || '';
            document.getElementById('ref_product_name').value = productName;
            document.getElementById('ref_qty').value          = qty;
            document.getElementById('refund_date').value      = new Date().toISOString().split('T')[0];
            document.getElementById('refund_loss_amount').value = '';
            document.getElementById('refund_reason').value    = '';
            document.getElementById('refundAlert').style.display = 'none';
            document.getElementById('refundSubmitBtn').disabled = false;
            document.getElementById('refundSubmitBtn').textContent = 'Process Refund';

            // Show sale summary
            document.getElementById('refundInfo').textContent =
                saleType.charAt(0).toUpperCase() + saleType.slice(1) + ' — ' +
                productName + ' × ' + qty + '   RWF ' + parseFloat(totalAmount).toLocaleString();

            // External sales cannot go back to stock
            var backStockLabel = document.getElementById('refund_back_stock_label');
            var backStockRadio = document.getElementById('refund_disp_stock');
            var lossRadio      = document.getElementById('refund_disp_loss');
            if (saleType === 'external') {
                backStockRadio.disabled = true;
                backStockLabel.style.opacity = '0.4';
            } else {
                backStockRadio.disabled = false;
                backStockLabel.style.opacity = '';
            }
            lossRadio.checked = true;
            toggleRefundMode(0);

            // Fetch WAC purchase cost to pre-fill loss amount
            if (productId) {
                var fd = new FormData();
                fd.append('get_purchase_cost', '1');
                fd.append('product_id', productId);
                fetch('sales.php', { method: 'POST', body: fd })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        if (res.cost > 0) {
                            document.getElementById('refund_loss_amount').value = Math.round(res.cost * qty);
                        }
                    });
            }

            openModal('refundModal');
        }

        function submitRefund() {
            var saleType    = document.getElementById('ref_sale_type').value;
            var saleId      = document.getElementById('ref_sale_id').value;
            var backToStock = document.querySelector('input[name="refund_disposition"]:checked').value;
            var lossAmount  = document.getElementById('refund_loss_amount').value;
            var reason      = document.getElementById('refund_reason').value.trim();
            var refundDate  = document.getElementById('refund_date').value;
            var alertBox    = document.getElementById('refundAlert');
            var btn         = document.getElementById('refundSubmitBtn');

            if (!refundDate) {
                alertBox.className = 'alert alert-danger'; alertBox.textContent = 'Date is required.'; alertBox.style.display = 'block'; return;
            }
            if (backToStock == '0' && !reason) {
                alertBox.className = 'alert alert-danger'; alertBox.textContent = 'Reason is required for a loss.'; alertBox.style.display = 'block'; return;
            }

            btn.disabled = true; btn.textContent = 'Processing...';
            alertBox.style.display = 'none';

            var fd = new FormData();
            fd.append('process_refund',  '1');
            fd.append('sale_type',       saleType);
            fd.append('sale_id',         saleId);
            fd.append('back_to_stock',   backToStock);
            fd.append('loss_amount',     lossAmount || 0);
            fd.append('reason',          reason);
            fd.append('refund_date',     refundDate);

            fetch('sales.php', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (res.success) {
                        closeModal('refundModal');
                        location.reload();
                    } else {
                        alertBox.className = 'alert alert-danger';
                        alertBox.textContent = res.message || 'An error occurred.';
                        alertBox.style.display = 'block';
                        btn.disabled = false; btn.textContent = 'Process Refund';
                    }
                })
                .catch(function() {
                    alertBox.className = 'alert alert-danger'; alertBox.textContent = 'Network error.'; alertBox.style.display = 'block';
                    btn.disabled = false; btn.textContent = 'Process Refund';
                });
        }

    // ── Sales summary cards ──────────────────────────────────────────────────
    function fmt(n) { return 'RWF ' + parseFloat(n).toLocaleString(undefined, {maximumFractionDigits:0}); }

    function loadSummaryCards() {
        var from = document.getElementById('date_from').value;
        var to   = document.getElementById('date_to').value;
        var ids  = ['sc-grand-total','sc-bulk-total','sc-retail-total','sc-ext-total','sc-cash','sc-momo','sc-loan'];
        ids.forEach(function(id) {
            var el = document.getElementById(id);
            if (el) { el.textContent = '…'; el.style.opacity = '0.4'; }
        });

        var fd = new FormData();
        fd.append('get_sales_summary', '1');
        fd.append('date_from', from);
        fd.append('date_to',   to);

        fetch('sales.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                function sub(s) {
                    var parts = [];
                    if (+s.cash > 0) parts.push('Cash: ' + fmt(s.cash));
                    if (+s.momo > 0) parts.push('Momo: ' + fmt(s.momo));
                    if (+s.loan > 0) parts.push('Loan: ' + fmt(s.loan));
                    return s.cnt + ' sale' + (s.cnt != 1 ? 's' : '') + (parts.length ? '  ·  ' + parts.join('  ') : '');
                }
                function set(id, val) {
                    var el = document.getElementById(id);
                    if (el) { el.textContent = val; el.style.opacity = ''; }
                }
                set('sc-grand-total', fmt(d.grand_total));
                set('sc-bulk-total',  fmt(d.bulk.total));
                set('sc-retail-total',fmt(d.retail.total));
                set('sc-ext-total',   fmt(d.external.total));
                set('sc-cash',        fmt(d.grand_cash));
                set('sc-momo',        fmt(d.grand_momo));
                set('sc-loan',        fmt(d.grand_loan));

                var grandSub = document.getElementById('sc-grand-sub');
                if (grandSub) grandSub.textContent =
                    (+d.bulk.cnt + +d.retail.cnt + +d.external.cnt) + ' total sales';

                var bSub = document.getElementById('sc-bulk-sub');
                if (bSub) bSub.textContent = sub(d.bulk);
                var rSub = document.getElementById('sc-retail-sub');
                if (rSub) rSub.textContent = sub(d.retail);
                var eSub = document.getElementById('sc-ext-sub');
                if (eSub) eSub.textContent = sub(d.external);
            })
            .catch(function() {
                ids.forEach(function(id) {
                    var el = document.getElementById(id);
                    if (el) { el.textContent = '—'; el.style.opacity = ''; }
                });
            });
    }

    loadSummaryCards();

    // Re-fetch when the filter form is submitted
    document.querySelector('.date-filter-form').addEventListener('submit', function() {
        setTimeout(loadSummaryCards, 50);
    });

    </script>
</body>
</html>