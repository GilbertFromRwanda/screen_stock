<?php
require_once 'config.php';

if (!isLoggedIn()) redirect('login.php');
if (!hasPermission('loans')) { $_SESSION['flash_error'] = "You don't have permission to access Loans."; redirect('dashboard.php'); }

// ── AJAX: Add New Client ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_new_client'])) {
    $name  = mysqli_real_escape_string($conn, trim($_POST['client_name']));
    $phone = mysqli_real_escape_string($conn, trim($_POST['client_phone']));

    if (empty($name)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Client name is required.']);
        exit;
    }

    $cid_sql = cidSql();
    $ph = $phone !== '' ? "'$phone'" : 'NULL';
    $ok = (bool)mysqli_query($conn, "
        INSERT INTO loan_clients (company_id, name, phone, total_loans, unpaid_amount, paid_amount)
        VALUES ($cid_sql, '$name', $ph, 0, 0, 0)
    ");

    header('Content-Type: application/json');
    if ($ok) {
        touchCacheStore($conn, 'clients');
        echo json_encode(['success' => true]);
    } else {
        $err = mysqli_error($conn);
        $msg = strpos($err, 'Duplicate') !== false ? 'A client with this name and phone already exists.' : $err;
        echo json_encode(['success' => false, 'message' => $msg]);
    }
    exit;
}

// ── AJAX: Add Loan ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_loan'])) {
    $product_id = (int)$_POST['product_id'];
    $qty        = (int)$_POST['qty'];
    $amount     = mysqli_real_escape_string($conn, $_POST['amount']);
    $client     = mysqli_real_escape_string($conn, trim($_POST['client']));
    $phone      = mysqli_real_escape_string($conn, trim($_POST['phone']));
    $loan_date  = mysqli_real_escape_string($conn, $_POST['loan_date']);

    if ($product_id <= 0 || $qty <= 0 || empty($client) || empty($loan_date) || $amount <= 0) {
        $msg = "Product, quantity, amount, client and date are required.";
        header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => $msg]); exit;
    }

    $given_by = (int)$_SESSION['user_id'];
    $ph = $phone !== '' ? "'$phone'" : 'NULL';

    mysqli_begin_transaction($conn);
    $ok = true;

    $cid_sql = cidSql(); $cid_and = cidAnd();
    $ok = (bool)mysqli_query($conn, "
        INSERT INTO loans (company_id, product_id, qty, amount, client, phone, loan_date, given_by)
        VALUES ($cid_sql, '$product_id','$qty','$amount','$client','$phone','$loan_date',$given_by)
    ");
    $loan_id = $ok ? (int)mysqli_insert_id($conn) : 0;

    if ($ok) $ok = (bool)mysqli_query($conn, "UPDATE retail_stock SET pieces_quantity = pieces_quantity - $qty WHERE product_id = $product_id $cid_and");

    if ($ok) $ok = (bool)mysqli_query($conn, "
        INSERT INTO loan_clients (company_id, name, phone, total_loans, unpaid_amount)
        VALUES ($cid_sql, '$client', $ph, 1, '$amount')
        ON DUPLICATE KEY UPDATE
            id            = LAST_INSERT_ID(id),
            total_loans   = total_loans + 1,
            unpaid_amount = unpaid_amount + '$amount'
    ");
    $client_id = $ok ? (int)mysqli_insert_id($conn) : 0;

    if ($ok) $ok = (bool)mysqli_query($conn, "UPDATE loans SET client_id = $client_id WHERE id = $loan_id");

    if ($ok) {
        mysqli_commit($conn);
        touchCacheStore($conn, 'products');
        touchCacheStore($conn, 'clients');
        header('Content-Type: application/json'); echo json_encode(['success' => true]); exit;
    }
    mysqli_rollback($conn);
    header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => mysqli_error($conn)]); exit;
}

// ── AJAX: Add Payment ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_payment'])) {
    $loan_id      = (int)$_POST['loan_id'];
    $amount_paid  = mysqli_real_escape_string($conn, $_POST['amount_paid']);
    $payment_date = mysqli_real_escape_string($conn, $_POST['payment_date']);

    if ($loan_id <= 0 || $amount_paid <= 0 || empty($payment_date)) {
        header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Loan, amount and date are required.']); exit;
    }

    // Check loan exists and get balance + client info for aggregate update
    $loan = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT l.amount, l.client_id, l.client, l.phone,
               COALESCE(SUM(lp.amount_paid),0) AS paid,
               l.bulk_id, l.retail_id, l.external_id
        FROM loans l
        LEFT JOIN loan_payments lp ON lp.loan_id = l.id
        WHERE l.id = $loan_id
        GROUP BY l.id
    "));

    if (!$loan) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Loan not found.']); exit; }

    $balance = $loan['amount'] - $loan['paid'];
    if ($amount_paid > $balance) {
        header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Payment exceeds remaining balance of RWF ' . number_format($balance, 0)]); exit;
    }

    $received_by = (int)$_SESSION['user_id'];
    $lc_id      = (int)$loan['client_id'];

    // Resolve missing client_id by looking up loan_clients via name + phone
    if ($lc_id <= 0 && !empty($loan['client'])) {
        $esc_name  = mysqli_real_escape_string($conn, $loan['client']);
        $phone_cnd = !empty($loan['phone'])
            ? "AND phone = '" . mysqli_real_escape_string($conn, $loan['phone']) . "'"
            : "AND (phone IS NULL OR phone = '')";
        $lc_row = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT id FROM loan_clients WHERE name = '$esc_name' $phone_cnd " . cidAnd() . " LIMIT 1"
        ));
        if ($lc_row) {
            $lc_id = (int)$lc_row['id'];
            // Persist the correct client_id so future payments resolve instantly
            mysqli_query($conn, "UPDATE loans SET client_id = $lc_id WHERE id = $loan_id");
        }
    }

    $fully_paid = (($loan['paid'] + $amount_paid) >= $loan['amount']);

    mysqli_begin_transaction($conn);
    $ok = true;

    $ok = (bool)mysqli_query($conn, "INSERT INTO loan_payments (loan_id, amount_paid, payment_date, received_by) VALUES ('$loan_id','$amount_paid','$payment_date',$received_by)");

    if ($ok && $lc_id > 0) {
        $cid_sql = cidSql();
        mysqli_query($conn, "INSERT INTO client_payments (company_id, client_id, amount, payment_date, recorded_by) VALUES ($cid_sql, $lc_id, $amount_paid, '$payment_date', $received_by)");
    
        }

    if ($ok && $lc_id > 0) {
        $ok = (bool)mysqli_query($conn, "
            UPDATE loan_clients lc
            JOIN (
                SELECT COALESCE(SUM(l.amount),0)    AS total_loaned,
                       COALESCE(SUM(lp_s.paid),0)   AS total_paid_sum,
                       COUNT(DISTINCT l.id)          AS cnt
                FROM loans l
                LEFT JOIN (SELECT loan_id, SUM(amount_paid) AS paid FROM loan_payments GROUP BY loan_id) lp_s
                       ON lp_s.loan_id = l.id
                WHERE l.client_id = $lc_id
            ) agg
            SET lc.paid_amount   = agg.total_paid_sum,
                lc.unpaid_amount = agg.total_loaned - agg.total_paid_sum,
                lc.total_loans   = agg.cnt
            WHERE lc.id = $lc_id
        ");
    }

    if ($ok && $loan['bulk_id']) {
        $q = $fully_paid
            ? "UPDATE sales_bulk SET loan_amount = 0, has_loan = 0 WHERE id = {$loan['bulk_id']}"
            : "UPDATE sales_bulk SET loan_amount = GREATEST(0, loan_amount - $amount_paid) WHERE id = {$loan['bulk_id']}";
        $ok = (bool)mysqli_query($conn, $q);
    }
    if ($ok && $loan['retail_id']) {
        $q = $fully_paid
            ? "UPDATE sales_retail SET loan_amount = 0, has_loan = 0 WHERE id = {$loan['retail_id']}"
            : "UPDATE sales_retail SET loan_amount = GREATEST(0, loan_amount - $amount_paid) WHERE id = {$loan['retail_id']}";
        $ok = (bool)mysqli_query($conn, $q);
    }
    if ($ok && $loan['external_id']) {
        $ok = (bool)mysqli_query($conn, "UPDATE sales_external SET loan_amount = GREATEST(0, loan_amount - $amount_paid) WHERE id = {$loan['external_id']}");
    }

    if ($ok) {
        mysqli_commit($conn);
        touchCacheStore($conn, 'clients');
        header('Content-Type: application/json'); echo json_encode(['success' => true]); exit;
    }
    mysqli_rollback($conn);
    header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => mysqli_error($conn)]); exit;
}

// ── AJAX: Preview Global Loan Payment ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['preview_global_loan_payment'])) {
   
$total         = (float)$_POST['total_amount'];
    $client_id = (int)($_POST['client_id'] ?? 0);
    $gloan_sort    = $_POST['gloan_sort'] ?? 'date_asc';

    $cid_and = cidAnd();
    $client_where = $client_id > 0 ? "AND l.client_id = $client_id" : "";

    $order_by = match($gloan_sort) {
        'date_desc'    => 'l.loan_date DESC, l.id DESC',
        'balance_desc' => 'balance DESC, l.id ASC',
        'balance_asc'  => 'balance ASC, l.id ASC',
        default        => 'l.loan_date ASC, l.id ASC',
    };

    $unpaid = mysqli_query($conn, "
        SELECT l.id,
               COALESCE(p.name, l.product_name) AS product_name,
               CASE WHEN p.category IS NOT NULL THEN p.category
                    WHEN l.bulk_id   IS NOT NULL THEN 'Bulk Sale'
                    WHEN l.retail_id IS NOT NULL THEN 'Retail Sale'
                    ELSE 'External' END AS category,
               l.client, l.loan_date, l.amount,
               COALESCE(lp_sum.paid, 0) AS total_paid,
               (l.amount - COALESCE(lp_sum.paid, 0)) AS balance
        FROM loans l
        LEFT JOIN products p ON p.id = l.product_id
        LEFT JOIN (SELECT loan_id, SUM(amount_paid) AS paid FROM loan_payments GROUP BY loan_id) lp_sum ON lp_sum.loan_id = l.id
        WHERE l.amount > COALESCE(lp_sum.paid, 0) $cid_and $client_where
        ORDER BY $order_by
    ");

    $remaining = $total;
    $rows = [];
    $total_outstanding = 0;

    while ($row = mysqli_fetch_assoc($unpaid)) {
        $total_outstanding += $row['balance'];
        if ($remaining > 0) {
            $pay = min($remaining, $row['balance']);
            $rows[] = ['id' => $row['id'], 'label' => $row['category'].'-'.$row['product_name'],
                'client' => $row['client'], 'date' => date('Y-m-d', strtotime($row['loan_date'])),
                'balance' => $row['balance'], 'will_pay' => $pay, 'full' => ($pay >= $row['balance'])];
            $remaining -= $pay;
        } else {
            $rows[] = ['id' => $row['id'], 'label' => $row['category'].'-'.$row['product_name'],
                'client' => $row['client'], 'date' => date('Y-m-d', strtotime($row['loan_date'])),
                'balance' => $row['balance'], 'will_pay' => 0, 'full' => false];
        }
    }
    header('Content-Type: application/json');
    echo json_encode(['rows' => $rows, 'total_outstanding' => $total_outstanding, 'leftover' => max(0, $remaining)]);
    exit;
}

// ── AJAX: Execute Global Loan Payment ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['exec_global_loan_payment'])) {
    $total         = (float)mysqli_real_escape_string($conn, $_POST['total_amount']);
    $client_id     = (int)($_POST['client_id'] ?? 0);
    $payment_date  = date('Y-m-d');
    $cid_and       = cidAnd();
    $gloan_sort    = $_POST['gloan_sort'] ?? 'date_asc';

    if ($total <= 0) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Amount must be greater than 0.']);
        exit;
    }

    $client_where = $client_id > 0 ? "AND l.client_id = $client_id" : "";

    $manual_ids = [];
    if (isset($_POST['manual_loan_ids']) && is_array($_POST['manual_loan_ids'])) {
        $manual_ids = array_values(array_filter(array_map('intval', $_POST['manual_loan_ids'])));
    }

    if (!empty($manual_ids)) {
        $ids_str = implode(',', $manual_ids);
        $unpaid = mysqli_query($conn, "
            SELECT l.id, l.amount, l.client_id,
                   l.bulk_id, l.retail_id, l.external_id,
                   COALESCE(lp_sum.paid, 0) AS total_paid,
                   (l.amount - COALESCE(lp_sum.paid, 0)) AS balance
            FROM loans l
            LEFT JOIN (SELECT loan_id, SUM(amount_paid) AS paid FROM loan_payments GROUP BY loan_id) lp_sum ON lp_sum.loan_id = l.id
            WHERE l.id IN ($ids_str) $cid_and
              AND l.amount > COALESCE(lp_sum.paid, 0)
            ORDER BY FIELD(l.id, $ids_str)
        ");
    } else {
        $order_by = match($gloan_sort) {
            'date_desc'    => 'l.loan_date DESC, l.id DESC',
            'balance_desc' => 'balance DESC, l.id ASC',
            'balance_asc'  => 'balance ASC, l.id ASC',
            default        => 'l.loan_date ASC, l.id ASC',
        };
        $unpaid = mysqli_query($conn, "
            SELECT l.id, l.amount, l.client_id,
                   l.bulk_id, l.retail_id, l.external_id,
                   COALESCE(lp_sum.paid, 0) AS total_paid,
                   (l.amount - COALESCE(lp_sum.paid, 0)) AS balance
            FROM loans l
            LEFT JOIN (SELECT loan_id, SUM(amount_paid) AS paid FROM loan_payments GROUP BY loan_id) lp_sum ON lp_sum.loan_id = l.id
            WHERE l.amount > COALESCE(lp_sum.paid, 0) $cid_and $client_where
            ORDER BY $order_by
        ");
    }

    $remaining     = $total;
    $count         = 0;
    $client_deltas = [];
    $received_by   = (int)$_SESSION['user_id'];

    // Collect all rows first so the cursor is free before we write
    $rows = [];
    while ($row = mysqli_fetch_assoc($unpaid)) $rows[] = $row;

    mysqli_begin_transaction($conn);
    $ok = true;

    foreach ($rows as $row) {
        if (!$ok || $remaining <= 0) break;
        $pay        = min($remaining, (float)$row['balance']);
        $fully_paid = ($pay >= (float)$row['balance']);

        $ok = (bool)mysqli_query($conn, "INSERT INTO loan_payments (loan_id, amount_paid, payment_date, received_by) VALUES ({$row['id']}, $pay, '$payment_date', $received_by)");

        if ($ok && (int)$row['client_id'] > 0) {
            $cid_sql = cidSql();
            mysqli_query($conn, "INSERT INTO client_payments (company_id, client_id, amount, payment_date, recorded_by) VALUES ($cid_sql, {$row['client_id']}, $pay, '$payment_date', $received_by)");
        }

        if ($ok && $row['bulk_id']) {
            $q = $fully_paid
                ? "UPDATE sales_bulk SET loan_amount = 0, has_loan = 0 WHERE id = {$row['bulk_id']}"
                : "UPDATE sales_bulk SET loan_amount = GREATEST(0, loan_amount - $pay) WHERE id = {$row['bulk_id']}";
            $ok = (bool)mysqli_query($conn, $q);
        }
        if ($ok && $row['retail_id']) {
            $q = $fully_paid
                ? "UPDATE sales_retail SET loan_amount = 0, has_loan = 0 WHERE id = {$row['retail_id']}"
                : "UPDATE sales_retail SET loan_amount = GREATEST(0, loan_amount - $pay) WHERE id = {$row['retail_id']}";
            $ok = (bool)mysqli_query($conn, $q);
        }
        if ($ok && $row['external_id']) {
            $ok = (bool)mysqli_query($conn, "UPDATE sales_external SET loan_amount = GREATEST(0, loan_amount - $pay) WHERE id = {$row['external_id']}");
        }

        if ($ok) {
            $remaining -= $pay;
            $count++;
            $cid = (int)$row['client_id'];
            $client_deltas[$cid] = ($client_deltas[$cid] ?? 0.0) + $pay;
        }
    }
    $now=date('Y-m-d H:i:s');

    foreach ($client_deltas as $cid => $d) {
        if (!$ok) break;
        $ok = (bool)mysqli_query($conn, "UPDATE loan_clients SET updated_at = '$now', paid_amount = paid_amount + $d, unpaid_amount = unpaid_amount - $d WHERE id = $cid");
    }

    if ($ok) {
        mysqli_commit($conn);
        touchCacheStore($conn, 'clients');
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'count' => $count, 'applied' => $total - $remaining]);
    } else {
        mysqli_rollback($conn);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Payment failed. No changes were applied.']);
    }
    exit;
}

// ── AJAX: Get Client Loans ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['get_client_loans'])) {
 // Simulate delay for testing
$client_id = (int)$_POST['client_id'];
    if ($client_id <= 0) { header('Content-Type: application/json'); echo json_encode([]); exit; }

    $loans_q = mysqli_query($conn, "
        SELECT l.*,
            COALESCE(p.name, l.product_name)     AS product_name,
            CASE WHEN p.category IS NOT NULL THEN p.category
                 WHEN l.bulk_id   IS NOT NULL THEN 'Bulk Sale'
                 WHEN l.retail_id IS NOT NULL THEN 'Retail Sale'
                 ELSE 'External' END              AS product_category,
            COALESCE(SUM(lp.amount_paid), 0)      AS total_paid,
            u.full_name AS given_by_name
        FROM loans l
        LEFT JOIN products p ON p.id = l.product_id
        LEFT JOIN loan_payments lp ON lp.loan_id = l.id
        LEFT JOIN users u ON l.given_by = u.id
        WHERE l.client_id = $client_id
        GROUP BY l.id
        ORDER BY l.id DESC
    ");
    $rows = [];
    while ($r = mysqli_fetch_assoc($loans_q)) $rows[] = $r;
    header('Content-Type: application/json');
    echo json_encode($rows);
    exit;
}

// ── AJAX: Get Client Payments ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['get_client_payments'])) {
    $client_id = (int)$_POST['client_id'];
    $date_from = mysqli_real_escape_string($conn, $_POST['date_from'] ?? date('Y-m-d', strtotime('-1 month')));
    $date_to   = mysqli_real_escape_string($conn, $_POST['date_to']   ?? date('Y-m-d'));
    if ($client_id <= 0) { header('Content-Type: application/json'); echo json_encode([]); exit; }

    $q = mysqli_query($conn, "
        SELECT lp.id, lp.amount_paid, lp.payment_date,
               COALESCE(p.name, l.product_name)  AS product_name,
               COALESCE(p.category, 'External')   AS product_category,
               l.amount AS loan_amount,
               u.full_name AS received_by_name,
               lp.created_at
        FROM loan_payments lp
        JOIN loans l ON l.id = lp.loan_id
        LEFT JOIN products p ON p.id = l.product_id
        LEFT JOIN users u ON u.id = lp.received_by
        WHERE l.client_id = $client_id
          AND lp.payment_date BETWEEN '$date_from' AND '$date_to'
        ORDER BY lp.payment_date DESC, lp.id DESC
    ");
    $rows = [];
    while ($r = mysqli_fetch_assoc($q)) $rows[] = $r;
    header('Content-Type: application/json');
    echo json_encode($rows);
    exit;
}

// ── AJAX: Get Direct Client Payments ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['get_client_payments_direct'])) {
    $client_id = (int)$_POST['client_id'];
    $date_from = mysqli_real_escape_string($conn, $_POST['date_from'] ?? date('Y-m-d', strtotime('-1 month')));
    $date_to   = mysqli_real_escape_string($conn, $_POST['date_to']   ?? date('Y-m-d'));
    if ($client_id <= 0) { header('Content-Type: application/json'); echo json_encode([]); exit; }

    $q = mysqli_query($conn, "
        SELECT cp.id, cp.amount, cp.payment_date, cp.note, cp.created_at,
               u.full_name AS recorded_by_name
        FROM client_payments cp
        LEFT JOIN users u ON u.id = cp.recorded_by
        WHERE cp.client_id = $client_id
          AND cp.payment_date BETWEEN '$date_from' AND '$date_to'
        ORDER BY cp.payment_date DESC, cp.id DESC
    ");
    $rows = [];
    while ($r = mysqli_fetch_assoc($q)) $rows[] = $r;
    header('Content-Type: application/json');
    echo json_encode($rows);
    exit;
}

// ── AJAX: Recalculate Client Balance ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['recalc_client_balance'])) {
    $client_id = (int)$_POST['client_id'];
    if ($client_id <= 0) { header('Content-Type: application/json'); echo json_encode(['success'=>false,'message'=>'Invalid client.']); exit; }
    $ok = (bool)mysqli_query($conn, "
        UPDATE loan_clients lc
        JOIN (
            SELECT COUNT(DISTINCT l.id)       AS cnt,
                   COALESCE(SUM(l.amount),0)  AS loaned,
                   COALESCE(SUM(lp_s.paid),0) AS paid_sum
            FROM loans l
            LEFT JOIN (SELECT loan_id, SUM(amount_paid) AS paid FROM loan_payments GROUP BY loan_id) lp_s
                   ON lp_s.loan_id = l.id
            WHERE l.client_id = $client_id
        ) agg
        SET lc.total_loans   = agg.cnt,
            lc.paid_amount   = agg.paid_sum,
            lc.unpaid_amount = agg.loaned - agg.paid_sum
        WHERE lc.id = $client_id
    ");
    if ($ok) touchCacheStore($conn, 'clients');
    header('Content-Type: application/json');
    echo json_encode($ok ? ['success'=>true] : ['success'=>false,'message'=>mysqli_error($conn)]);
    exit;
}

// ── AJAX: Get Loan Stats (client list itself comes from DataCache.getClients) ──
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['get_loan_stats'])) {
    $ca = cidAnd();
    $st = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT COUNT(DISTINCT l.id)        AS total_loans,
               COUNT(DISTINCT l.client)    AS total_clients,
               COALESCE(SUM(l.amount), 0)  AS total_amount,
               COALESCE(SUM(lp_s.paid), 0) AS total_paid
        FROM loans l
        LEFT JOIN (SELECT loan_id, SUM(amount_paid) AS paid FROM loan_payments GROUP BY loan_id) lp_s
               ON lp_s.loan_id = l.id
        WHERE 1=1 $ca
    "));
    header('Content-Type: application/json');
    echo json_encode([
        'stats'       => $st,
        'outstanding' => (float)$st['total_amount'] - (float)$st['total_paid'],
    ]);
    exit;
}

// ── Delete Loan ────────────────────────────────────────────────────────────────
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $del_id = (int)$_GET['delete'];
    $loan = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM loans WHERE id=$del_id"));
    if ($loan) {
        $del_client_id = (int)$loan['client_id'];

        mysqli_begin_transaction($conn);
        $ok = true;

        if ($loan['product_id']) {
            $ok = (bool)mysqli_query($conn, "UPDATE retail_stock SET pieces_quantity = pieces_quantity + {$loan['qty']} WHERE product_id = {$loan['product_id']} " . cidAnd());
        }
        if ($ok) $ok = (bool)mysqli_query($conn, "DELETE FROM loan_payments WHERE loan_id = $del_id");
        if ($ok) $ok = (bool)mysqli_query($conn, "DELETE FROM loans WHERE id = $del_id");
        if ($ok) $ok = (bool)mysqli_query($conn, "
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

        if ($ok) {
            mysqli_commit($conn);
            touchCacheStore($conn, 'products');
            touchCacheStore($conn, 'clients');
            $_SESSION['flash_success'] = "Loan deleted.";
            logActivity($conn, (int)$_SESSION['user_id'], 'Delete Loan', "Deleted loan #{$del_id} for {$loan['client']}",
                'loans', $del_id,
                ['id' => $loan['id'], 'client' => $loan['client'], 'phone' => $loan['phone'], 'qty' => $loan['qty'], 'amount' => $loan['amount'], 'loan_date' => $loan['loan_date']],
                []
            );
        } else { mysqli_rollback($conn); $_SESSION['flash_error'] = "Could not delete loan. Please try again."; }
    }
    header("Location: loans.php"); exit;
}

// Flash messages
if (isset($_SESSION['flash_success'])) { $success = $_SESSION['flash_success']; unset($_SESSION['flash_success']); }

$cid_and = cidAnd();

// All loan clients — direct read from cached aggregate columns, no joins needed
$clients_qr = mysqli_query($conn, "
    SELECT id AS client_id, name, phone, created_at AS registered_at,
           total_loans AS loan_count,
           paid_amount + unpaid_amount AS total_loaned,
           paid_amount AS total_paid,
           updated_at AS last_update
    FROM loan_clients WHERE 1=1 $cid_and
    ORDER BY updated_at DESC
");
$clients_data = [];
while ($row = mysqli_fetch_assoc($clients_qr)) $clients_data[] = $row;

// Product list and existing-client list for the "New Loan" modal pickers are
// no longer queried here — they're loaded client-side from DataCache
// (js/data-cache.js) so the same catalog/client data fetched by one page is
// reused instantly on every other page instead of being re-queried per file.

// Summary stats (always full, ignoring date filter)
$stats = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT
        COUNT(DISTINCT l.id)                          AS total_loans,
        COUNT(DISTINCT l.client)                      AS total_clients,
        COALESCE(SUM(l.amount), 0)                    AS total_amount,
        COALESCE(SUM(lp_sum.paid), 0)                 AS total_paid
    FROM loans l
    LEFT JOIN (
        SELECT loan_id, SUM(amount_paid) AS paid FROM loan_payments GROUP BY loan_id
    ) lp_sum ON lp_sum.loan_id = l.id
    WHERE 1=1 $cid_and
"));
$stats_outstanding = $stats['total_amount'] - $stats['total_paid'];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loans</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/loans.css">
    <link rel="stylesheet" href="css/all.min.css">
</head>
<body>
<div class="dashboard-container">
    <?php include 'sidebar.php'; ?>
    <div class="main-content">

        <!-- Header -->
        <div class="loans-header">
            <h1>Loans</h1>
            <div style="display:flex;gap:10px;">
                <div id="global-pay-btn-wrap">
                <?php if ($stats_outstanding > 0): ?>
                <button onclick="openGlobalLoanPay()" class="btn btn-secondary"
                    style="border-color:var(--warning);color:var(--warning);font-weight:600;">
                    Global Pay &nbsp;<span style="background:var(--warning);color:#fff;border-radius:99px;padding:1px 8px;font-size:12px;">RWF <?php echo number_format($stats_outstanding, 0); ?></span>
                </button>
                <?php endif; ?>
                </div>
                <button onclick="openModal('addClientModal')" class="btn btn-secondary">+ Add New Client</button>
                <button onclick="openModal('addLoanModal')" class="btn btn-primary">+ New Loan</button>
            </div>
        </div>

        <!-- Summary cards -->
        <div class="loans-summary">
            <div class="loan-card">
                <div class="loan-card-label">Total Loans</div>
                <div class="loan-card-value" id="stat-total-loans"><?php echo number_format($stats['total_loans']); ?></div>
                <div class="loan-card-sub" id="stat-clients-sub"><?php echo $stats['total_clients']; ?> client<?php echo $stats['total_clients'] != 1 ? 's' : ''; ?></div>
            </div>
            <div class="loan-card green">
                <div class="loan-card-label">Total Loaned</div>
                <div class="loan-card-value" id="stat-total-loaned">RWF <?php echo number_format($stats['total_amount'], 0); ?></div>
            </div>
            <div class="loan-card orange">
                <div class="loan-card-label">Total Collected</div>
                <div class="loan-card-value success" id="stat-total-paid">RWF <?php echo number_format($stats['total_paid'], 0); ?></div>
            </div>
            <div class="loan-card red">
                <div class="loan-card-label">Outstanding</div>
                <div class="loan-card-value <?php echo $stats_outstanding > 0 ? 'danger' : 'success'; ?>" id="stat-outstanding">
                    RWF <?php echo number_format($stats_outstanding, 0); ?>
                </div>
            </div>
        </div>

        <!-- Search bar + status filter -->
        <div style="margin-bottom:14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <input type="text" id="clientTableSearch" placeholder="Search client name or phone..."
                   oninput="filterClientTable()"
                   style="flex:1;min-width:200px;max-width:360px;padding:8px 12px;border:1px solid var(--gray-300);border-radius:var(--radius);font-size:14px;">
            <div style="display:flex;gap:6px;">
                <button class="filter-status-btn active" data-status="unpaid-partial" onclick="setStatusFilter(this)">Unpaid &amp; Partial</button>
                <button class="filter-status-btn"        data-status="all"    onclick="setStatusFilter(this)">All</button>
                <button class="filter-status-btn"        data-status="unpaid" onclick="setStatusFilter(this)">Unpaid</button>
                <button class="filter-status-btn"        data-status="partial" onclick="setStatusFilter(this)">Partial</button>
                <button class="filter-status-btn"        data-status="paid"   onclick="setStatusFilter(this)">Paid</button>
            </div>
            <span id="clientCountBadge" style="font-size:13px;color:var(--secondary);"></span>
        </div>

        <div id="pageAlert" class="alert" style="display:none;"></div>
        <?php if (isset($success)): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>

        <!-- Loan clients table -->
        <div id="clients-table-wrap">
        <?php if (empty($clients_data)): ?>
            <div style="text-align:center;padding:48px;color:var(--secondary);">No loan clients found.</div>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table class="table" id="tbl-loan-clients" style="min-width:700px;">
            <thead>
                <tr>
                    <th>Actions</th>
                    <th>#</th>
                    <th>Client</th>
                    <th>Phone</th>
                    <th>Loans</th>
                    <th>Total Loaned</th>
                    <th>Paid</th>
                    <th>Outstanding</th>
                    <th>Status</th>
                    <th>Last Update</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($clients_data as $i => $c):
                $outstanding = $c['total_loaned'] - $c['total_paid'];
                if ($outstanding <= 0)           { $status = 'Paid';    $badge_cls = 'badge-paid'; }
                elseif ($c['total_paid'] > 0)    { $status = 'Partial'; $badge_cls = 'badge-partial'; }
                else                             { $status = 'Unpaid';  $badge_cls = 'badge-unpaid'; }
            ?>
            <tr data-status="<?php echo strtolower($status); ?>">
                <td>
                    <div class="act-menu-wrap">
                        <button class="act-btn" title="Actions" onclick="toggleActMenu(this)">⋮</button>
                        <div class="act-menu">
                              <?php if ($outstanding > 0): ?>
                            <div class="act-menu-sep"></div>
                            <button class="act-item" style="color:#d97706;" onclick="openGlobalLoanPayFor(<?php echo (int)$c['client_id']; ?>, <?php echo (float)$outstanding; ?>);closeActMenus()"><i class="fas fa-money-bill-wave"></i> Pay</button>
                            <?php endif; ?>
                            <button class="act-item" onclick="viewClientLoans(<?php echo htmlspecialchars(json_encode($c['name']), ENT_QUOTES); ?>, <?php echo (int)$c['client_id']; ?>);closeActMenus()"><i class="fas fa-eye"></i> View Loans</button>
                            <button class="act-item" onclick="viewClientPayments(<?php echo (int)$c['client_id']; ?>, <?php echo htmlspecialchars(json_encode($c['name']), ENT_QUOTES); ?>);closeActMenus()"><i class="fas fa-clock-rotate-left"></i> Payments</button>

                            <div class="act-menu-sep"></div>
                            <button class="act-item" style="color:#0ea5e9;" onclick="recalcClientBalance(<?php echo (int)$c['client_id']; ?>, this);closeActMenus()"><i class="fas fa-rotate"></i> Recalculate</button>
                          
                        </div>
                    </div>
                </td>
                <td style="color:var(--secondary);"><?php echo $i + 1; ?></td>
                <td style="font-weight:600;"><?php echo htmlspecialchars($c['name']); ?></td>
                <td style="color:var(--secondary);"><?php echo htmlspecialchars($c['phone'] ?: '—'); ?></td>
                <td><?php echo $c['loan_count']; ?></td>
                <td>RWF <?php echo number_format($c['total_loaned'], 0); ?></td>
                <td>RWF <?php echo number_format($c['total_paid'], 0); ?></td>
                <td class="<?php echo $outstanding > 0 ? 'has-balance' : 'cleared'; ?>">
                    <strong>RWF <?php echo number_format(abs($outstanding), 0); ?></strong>
                </td>
                <td><span class="<?php echo $badge_cls; ?>"><?php echo $status; ?></span></td>
                <td style="color:var(--secondary);font-size:12.5px;white-space:nowrap;"><?php echo date('M d, Y g:i A', strtotime($c['last_update'])); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
        </div><!-- /clients-table-wrap -->
    </div>
</div>

<!-- Global Loan Payment Modal -->
<div id="globalLoanPayModal" class="modal">
    <div class="modal-content" style="max-width:600px;max-height:90vh;overflow-y:auto;">
        <span class="close" onclick="closeModal('globalLoanPayModal')">&times;</span>
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;padding-right:34px;">
            <h2 style="margin:0;">Global Loan Payment</h2>
            <button id="gloanExportBtn" onclick="exportGlobalLoanPreview()" style="display:none;background:#475569;color:#fff;border:none;border-radius:var(--radius);padding:7px 14px;font-size:13px;font-weight:600;cursor:pointer;">&#8681; Export</button>
        </div>
        <div class="form-group" style="margin-bottom:16px;">
            <label>Prioritize loans by</label>
            <select id="gloan_sort" onchange="scheduleLoanPreview()" style="max-width:280px;">
                <option value="date_asc">Oldest first</option>
                <option value="date_desc">Newest first</option>
                <option value="balance_desc">Highest balance first</option>
                <option value="balance_asc">Lowest balance first</option>
                <option value="manual">Manual selection (choose loans)</option>
            </select>
        </div>
        <div id="globalLoanPayAlert" class="alert" style="display:none;"></div>
        <div style="display:flex;gap:12px;margin-bottom:16px;flex-wrap:wrap;">
            <div class="form-group" style="flex:1;min-width:160px;margin-bottom:0;">
                <label>Amount to Pay (RWF)*</label>
                <input type="text" id="gloan_amount" min="1" step="1" placeholder="Enter amount..."
                    oninput="scheduleLoanPreview()">
            </div>
            <div class="form-group" style="flex:1;min-width:160px;margin-bottom:0;">
                <label>Filter by Client <small style="font-weight:400;">(optional)</small></label>
                <select id="gloan_client" onchange="scheduleLoanPreview()">
                    <option value="">All clients</option>
                    <?php foreach ($clients_data as $c): ?>
                    <option value="<?php echo (int)$c['client_id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div id="globalLoanPreview" style="display:none;">
            <div style="font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--secondary);margin-bottom:8px;">
                Payment Distribution Preview
            </div>
            <div style="max-height:280px;overflow-y:auto;border:1px solid var(--gray-200);border-radius:10px;">
                <table style="width:100%;border-collapse:collapse;font-size:13px;">
                    <thead>
                        <tr style="background:var(--gray-100);">
                            <th id="gloan_chk_th" style="display:none;padding:9px 8px;width:32px;"><input type="checkbox" id="gloan_select_all" title="Select / deselect all" onchange="gloanSelectAll(this.checked)" style="width:15px;height:15px;cursor:pointer;"></th>
                            <th style="padding:9px 12px;text-align:left;font-size:11px;color:var(--secondary);text-transform:uppercase;">Date</th>
                            <th style="padding:9px 12px;text-align:left;font-size:11px;color:var(--secondary);text-transform:uppercase;">Product</th>
                            <th style="padding:9px 12px;text-align:left;font-size:11px;color:var(--secondary);text-transform:uppercase;">Client</th>
                            <th style="padding:9px 12px;text-align:right;font-size:11px;color:var(--secondary);text-transform:uppercase;">Balance</th>
                            <th style="padding:9px 12px;text-align:right;font-size:11px;color:var(--secondary);text-transform:uppercase;">Will Pay</th>
                        </tr>
                    </thead>
                    <tbody id="loanPreviewBody"></tbody>
                </table>
            </div>
            <div id="loanPreviewSummary" style="margin-top:10px;font-size:13px;color:var(--secondary);"></div>
        </div>
        <div style="margin-top:20px;display:flex;gap:10px;flex-wrap:wrap;position:sticky;bottom:0;background:var(--white);padding-bottom:4px;">
            <button id="globalLoanPayBtn" class="btn btn-primary" onclick="execGlobalLoanPay()" disabled>Apply Payment</button>
            <button class="btn btn-secondary" onclick="closeModal('globalLoanPayModal')">Cancel</button>
        </div>
    </div>
</div>

<!-- Client Loans Modal -->
<div id="clientLoansModal" class="modal">
    <div class="modal-content" style="max-width:800px;">
        <span class="close" onclick="closeModal('clientLoansModal')">&times;</span>
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;padding-right:34px;">
            <h2 id="clientLoansTitle" style="margin:0;">Loans</h2>
            <button id="clLoansExportBtn" onclick="exportClientLoans()" style="display:none;background:#475569;color:#fff;border:none;border-radius:var(--radius);padding:7px 14px;font-size:13px;font-weight:600;cursor:pointer;gap:6px;align-items:center;">
                &#8681; Export
            </button>
        </div>
        <div id="clientLoansFilter" style="display:none;margin:12px 0 8px;gap:6px;align-items:center;flex-wrap:wrap;">
            <button class="filter-status-btn active" data-status="unpaid" onclick="setClientLoanFilter(this)">Unpaid</button>
            <button class="filter-status-btn" data-status="all" onclick="setClientLoanFilter(this)">All</button>
            <span id="clientLoansCount" style="font-size:13px;color:var(--secondary);margin-left:4px;"></span>
        </div>
        <div id="clientLoansBody" style="overflow-x:auto;max-height:420px;overflow-y:auto;"></div>
        <div id="clientLoansPagination" style="display:none;margin-top:10px;gap:6px;justify-content:center;align-items:center;flex-wrap:wrap;"></div>
    </div>
</div>

<!-- Client Payments Modal -->
<div id="clientPaymentsModal" class="modal">
    <div class="modal-content" style="max-width:720px;">
        <span class="close" onclick="closeModal('clientPaymentsModal')">&times;</span>
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;padding-right:34px;">
            <h2 id="clientPaymentsTitle" style="margin:0;">Payments</h2>
            <button id="cpayExportBtn" onclick="exportClientPayments()" style="display:none;background:#475569;color:#fff;border:none;border-radius:var(--radius);padding:7px 14px;font-size:13px;font-weight:600;cursor:pointer;">
                &#8681; Export
            </button>
        </div>
        <div style="display:flex;gap:10px;margin:12px 0 10px;align-items:flex-end;flex-wrap:wrap;">
            <div class="form-group" style="margin:0;min-width:140px;">
                <label style="font-size:12px;">From</label>
                <input type="date" id="cpay_from">
            </div>
            <div class="form-group" style="margin:0;min-width:140px;">
                <label style="font-size:12px;">To</label>
                <input type="date" id="cpay_to">
            </div>
            <button class="btn btn-primary" style="padding:7px 18px;" onclick="loadClientPayments()">Find</button>
            <span id="clientPaymentsCount" style="font-size:13px;color:var(--secondary);padding-bottom:6px;"></span>
        </div>
        <!-- Tabs -->
        <div style="display:flex;border-bottom:2px solid var(--gray-200);margin-bottom:12px;">
            <button id="cpay-tab-loan" onclick="switchCpayTab('loan')"
                style="padding:8px 18px;border:none;border-bottom:3px solid var(--primary);background:none;font-size:13px;font-weight:700;color:var(--primary);cursor:pointer;margin-bottom:-2px;">
                Loan Payments <span id="cpay-badge-loan" style="font-size:11px;font-weight:400;"></span>
            </button>
            <button id="cpay-tab-direct" onclick="switchCpayTab('direct')"
                style="padding:8px 18px;border:none;border-bottom:3px solid transparent;background:none;font-size:13px;font-weight:400;color:var(--secondary);cursor:pointer;margin-bottom:-2px;">
                Direct Payments <span id="cpay-badge-direct" style="font-size:11px;font-weight:400;"></span>
            </button>
        </div>
        <div id="cpay-panel-loan" style="overflow-x:auto;max-height:380px;overflow-y:auto;"></div>
        <div id="cpay-panel-direct" style="display:none;overflow-x:auto;max-height:380px;overflow-y:auto;"></div>
    </div>
</div>

<!-- Add Loan Modal -->
<div id="addLoanModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('addLoanModal')">&times;</span>
        <h2>New Loan</h2>
        <div id="addLoanAlert" class="alert" style="display:none;"></div>
        <form id="addLoanForm">
            <div class="form-group">
                <label>Date*</label>
                <input type="date" id="loan_date" name="loan_date" value="<?php echo date('Y-m-d'); ?>" required>
            </div>
            <div class="form-group">
                <label>Product*</label>
                <div class="searchable-select" id="loanProductWrap">
                    <input type="hidden" id="loan_product_id" name="product_id">
                    <input type="text" class="searchable-select-input" id="loan_product_search" placeholder="Search product..." autocomplete="off">
                    <div class="searchable-select-dropdown" id="loan_product_dropdown"></div>
                </div>
                <small id="loanPriceHint" style="color:var(--secondary);margin-top:4px;display:block;"></small>
            </div>
            <div class="form-group">
                <label>Quantity*</label>
                <input type="text" id="loan_qty" name="qty" min="1" required value="1">
            </div>
            <div class="form-group">
                <label>Amount (RWF)*</label>
                <input type="text" id="loan_amount" name="amount" min="1" step="1" required value="0">
            </div>
            <div class="form-group" id="clientPickerGroup" style="display:none;">
                <label>Existing Client</label>
                <div class="searchable-select" id="clientPickerWrap">
                    <input type="text" class="searchable-select-input" id="client_picker_search"
                        placeholder="Search registered client..." autocomplete="off">
                    <div class="searchable-select-dropdown" id="client_picker_dropdown"></div>
                </div>
                <small style="color:var(--secondary);margin-top:3px;display:block;">Pick to auto-fill, or type a new name below.</small>
            </div>
            <div class="form-group">
                <label>Client Name*</label>
                <input type="text" id="loan_client" name="client" required placeholder="Full name">
            </div>
            <div class="form-group">
                <label>Phone</label>
                <input type="text" id="loan_phone" name="phone" placeholder="e.g. 07XXXXXXXX">
            </div>
            <button type="submit" name="add_loan" class="btn btn-primary">Save Loan</button>
        </form>
    </div>
</div>

<!-- Payment Modal -->
<div id="paymentModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('paymentModal')">&times;</span>
        <h2>Record Payment</h2>
        <div id="paymentAlert" class="alert" style="display:none;"></div>
        <div id="paymentInfo" class="payment-info-box" style="display:none;"></div>
        <form id="paymentForm">
            <input type="hidden" id="pay_loan_id" name="loan_id">
            <div class="form-group">
                <label>Payment Date*</label>
                <input type="date" id="payment_date" name="payment_date" value="<?php echo date('Y-m-d'); ?>" required>
            </div>
            <div class="form-group">
                <label>Amount Paid (RWF)*</label>
                <input type="text" id="pay_amount" name="amount_paid" min="1" step="1" required>
            </div>
            <button type="submit" name="add_payment" class="btn btn-primary">Save Payment</button>
        </form>
    </div>
</div>

<!-- Share Modal -->
<div id="shareModal" class="modal">
    <div class="modal-content" style="max-width:620px;">
        <span class="close" onclick="closeModal('shareModal')">&times;</span>
        <h2 id="shareModalTitle" style="margin-bottom:4px;">Export &amp; Share</h2>
        <p id="shareModalSubtitle" style="color:var(--secondary);font-size:13px;margin:0 0 14px;"></p>
        <div id="shareTablePreview" style="overflow-x:auto;max-height:340px;overflow-y:auto;border:1px solid var(--gray-200);border-radius:var(--radius);margin-bottom:16px;"></div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <button onclick="printSharePDF()" style="background:#2563eb;color:#fff;border:none;border-radius:var(--radius);padding:9px 18px;font-size:14px;font-weight:700;cursor:pointer;">
                &#128438; Save as PDF
            </button>
            <button onclick="shareToWhatsApp()" style="background:#475569;color:#fff;border:none;border-radius:var(--radius);padding:9px 18px;font-size:14px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:8px;">
                <svg width="16" height="16" viewBox="0 0 32 32" fill="currentColor"><path d="M16 3C8.8 3 3 8.8 3 16c0 2.6.7 5 2 7.1L3 29l6.1-2A13 13 0 0 0 16 29c7.2 0 13-5.8 13-13S23.2 3 16 3zm6.5 18.1c-.3.8-1.6 1.5-2.2 1.6-.6.1-1.2.3-3.8-.8-3.2-1.3-5.2-4.5-5.4-4.7-.2-.2-1.4-1.9-1.4-3.6 0-1.7.9-2.5 1.2-2.9.3-.4.7-.4.9-.4h.7c.2 0 .5-.1.8.6.3.7 1 2.5 1.1 2.7.1.2.2.4 0 .7-.1.3-.2.4-.4.7-.2.3-.4.5-.2.9.2.4.9 1.5 2 2.4 1.3 1.1 2.4 1.5 2.8 1.6.4.2.6.1.8-.1.2-.2.9-1 1.1-1.4.2-.4.5-.3.8-.2.3.1 2 .9 2.3 1.1.4.2.6.3.7.5.1.3.1 1.2-.2 2z"/></svg>
                WhatsApp
            </button>
            <button onclick="closeModal('shareModal')" class="btn btn-secondary">Close</button>
        </div>
    </div>
</div>

<!-- Add New Client Modal -->
<div id="addClientModal" class="modal">
    <div class="modal-content" style="max-width:420px;">
        <span class="close" onclick="closeModal('addClientModal')">&times;</span>
        <h2>Add New Client</h2>
        <div id="addClientAlert" class="alert" style="display:none;"></div>
        <form id="addClientForm">
            <div class="form-group">
                <label>Client Name*</label>
                <input type="text" id="new_client_name" name="client_name" required placeholder="Full name">
            </div>
            <div class="form-group">
                <label>Phone</label>
                <input type="text" id="new_client_phone" name="client_phone" placeholder="e.g. 07XXXXXXXX">
            </div>
            <button type="submit" name="add_new_client" class="btn btn-primary">Save Client</button>
        </form>
    </div>
</div>

<script>window.APP_COMPANY_ID = <?php echo json_encode(cid()); ?>;</script>
<script src="js/data-cache.js?v=<?php echo filemtime(__DIR__ . '/js/data-cache.js'); ?>"></script>
<script src="script.js"></script>
<?php if (isset($success)): ?>
<script>DataCache.invalidate('products');</script>
<?php endif; ?>
<script>
var loanUnitPrice = 0;
var _cpayRows = [];
var _cpayClientName = '';
var _gloanPreviewRows = [];
var _pdfExportData = null;
var _waText = '';

function openShareModal(pdfData, waText) {
    _pdfExportData = pdfData;
    _waText = waText || '';
    document.getElementById('shareModalTitle').textContent = pdfData.title;
    document.getElementById('shareModalSubtitle').textContent = pdfData.subtitle || '';
    // render preview table
    var preview = document.getElementById('shareTablePreview');
    var html = '<table style="width:100%;border-collapse:collapse;font-size:13px;">';
    html += '<thead><tr>';
    pdfData.headers.forEach(function(h) {
        html += '<th style="padding:9px 12px;background:#1e40af;color:#fff;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap;">' + h + '</th>';
    });
    html += '</tr></thead><tbody>';
    pdfData.rows.forEach(function(row, ri) {
        html += '<tr style="background:' + (ri % 2 === 0 ? '#fff' : '#f8fafc') + ';">';
        row.forEach(function(cell, ci) {
            var align = (typeof cell === 'number' || (ci > 0 && String(cell).match(/^[\d,]+$/))) ? 'right' : 'left';
            html += '<td style="padding:8px 12px;border-bottom:1px solid #e2e8f0;text-align:' + align + ';">' + cell + '</td>';
        });
        html += '</tr>';
    });
    html += '</tbody>';
    if (pdfData.footer) {
        html += '<tfoot><tr style="background:#f1f5f9;font-weight:700;">';
        pdfData.footer.forEach(function(cell) {
            html += '<td style="padding:9px 12px;border-top:2px solid #cbd5e1;">' + cell + '</td>';
        });
        html += '</tr></tfoot>';
    }
    html += '</table>';
    preview.innerHTML = html;
    openModal('shareModal');
}

function printSharePDF() {
    if (!_pdfExportData) return;
    var d = _pdfExportData;
    var win = window.open('', '_blank', 'width=1000,height=750');
    var html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>' + d.title + '</title><style>' +
        'body{font-family:Arial,sans-serif;padding:28px 32px;color:#1e293b;font-size:13px;}' +
        'h1{margin:0 0 4px;font-size:18px;}' +
        '.sub{color:#64748b;font-size:12px;margin-bottom:6px;}' +
        '.meta{text-align:right;font-size:11px;color:#94a3b8;margin-bottom:18px;}' +
        'table{width:100%;border-collapse:collapse;}' +
        'th{background:#1e40af;color:#fff;padding:9px 12px;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap;}' +
        'td{padding:8px 12px;border-bottom:1px solid #e2e8f0;}' +
        'tr:nth-child(even) td{background:#f8fafc;}' +
        'tfoot td{background:#f1f5f9;font-weight:700;border-top:2px solid #cbd5e1;border-bottom:none;}' +
        '.r{text-align:right;}' +
        '@media print{.print-btn{display:none;}body{padding:0;}@page{margin:15mm;}}' +
        '</style></head><body>';
    html += '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;">' +
        '<div class="meta" style="margin:0;">Generated: ' + new Date().toLocaleString() + '</div>' +
        '<button class="print-btn" onclick="window.print()" style="background:#2563eb;color:#fff;border:none;border-radius:6px;padding:8px 20px;font-size:14px;font-weight:700;cursor:pointer;">&#128438; Print / Save PDF</button>' +
        '</div>';
    html += '<h1>' + d.title + '</h1>';
    if (d.subtitle) html += '<div class="sub">' + d.subtitle + '</div>';
    html += '<br><table><thead><tr>';
    d.headers.forEach(function(h) { html += '<th>' + h + '</th>'; });
    html += '</tr></thead><tbody>';
    d.rows.forEach(function(row, ri) {
        html += '<tr>';
        row.forEach(function(cell, ci) {
            var cls = (ci > 0 && String(cell).match(/^[\d,]+$/)) ? ' class="r"' : '';
            html += '<td' + cls + '>' + cell + '</td>';
        });
        html += '</tr>';
    });
    html += '</tbody>';
    if (d.footer) {
        html += '<tfoot><tr>';
        d.footer.forEach(function(cell) { html += '<td>' + cell + '</td>'; });
        html += '</tr></tfoot>';
    }
    html += '</table></body></html>';
    win.document.write(html);
    win.document.close();
    win.focus();
}

function shareToWhatsApp() {
    window.open('https://wa.me/?text=' + encodeURIComponent(_waText), '_blank');
}

function copyShareText() {
    navigator.clipboard && navigator.clipboard.writeText(_waText);
}

function exportClientLoans() {
    var filtered = _clLoans.filter(function(l) {
        return _clFilter === 'all' || (parseFloat(l.amount) - parseFloat(l.total_paid)) > 0;
    });
    if (!filtered.length) { alert('No data to export.'); return; }

    var totalLoaned = 0, totalPaid = 0;
    var waLines = ['Loans — ' + _clName, new Date().toLocaleDateString(), ''];
    var pdfRows = [];
    filtered.forEach(function(l, i) {
        var balance = parseFloat(l.amount) - parseFloat(l.total_paid);
        var status  = balance <= 0 ? 'Paid' : (parseFloat(l.total_paid) > 0 ? 'Partial' : 'Unpaid');
        totalLoaned += parseFloat(l.amount);
        totalPaid   += parseFloat(l.total_paid);
        waLines.push((i+1) + '. ' + l.loan_date + ' | ' + (l.product_category||'') + '-' + (l.product_name||'?') +
            '\n   Loaned: RWF ' + parseFloat(l.amount).toLocaleString() +
            '  Paid: RWF ' + parseFloat(l.total_paid).toLocaleString() +
            '  Balance: RWF ' + Math.abs(balance).toLocaleString() + ' (' + status + ')');
        var createdAt = l.created_at ? l.created_at.replace('T',' ').substring(0,16) : '—';
        pdfRows.push([(i+1), l.loan_date, (l.product_category||'')+'-'+(l.product_name||'?'),
            parseFloat(l.amount).toLocaleString(), parseFloat(l.total_paid).toLocaleString(),
            Math.abs(balance).toLocaleString(), status, createdAt, l.given_by_name||'—']);
    });
    var outstanding = totalLoaned - totalPaid;
    waLines.push('', 'Total Loaned : RWF ' + totalLoaned.toLocaleString(),
        'Total Paid   : RWF ' + totalPaid.toLocaleString(),
        'Outstanding  : RWF ' + Math.abs(outstanding).toLocaleString());
    openShareModal({
        title: 'Loans — ' + _clName,
        subtitle: new Date().toLocaleDateString(),
        headers: ['#', 'Date', 'Product', 'Loaned (RWF)', 'Paid (RWF)', 'Balance (RWF)', 'Status', 'Created At', 'Given By'],
        rows: pdfRows,
        footer: ['', '', 'Total (' + filtered.length + ')', totalLoaned.toLocaleString(), totalPaid.toLocaleString(), Math.abs(outstanding).toLocaleString(), '', '', '']
    }, waLines.join('\n'));
}

function exportClientPayments() {
    if (!_cpayRows.length) { alert('No data to export.'); return; }
    var from = document.getElementById('cpay_from').value;
    var to   = document.getElementById('cpay_to').value;
    var waLines = ['Payments — ' + _cpayClientName, 'Period: ' + from + ' to ' + to, ''];
    var total = 0, pdfRows = [];
    _cpayRows.forEach(function(p, i) {
        var amt = parseFloat(p.amount_paid);
        total += amt;
        waLines.push((i+1) + '. ' + p.payment_date + ' | ' + (p.product_category||'') + '-' + (p.product_name||'?') +
            '  RWF ' + amt.toLocaleString() + (p.received_by_name ? '  (by ' + p.received_by_name + ')' : ''));
        pdfRows.push([(i+1), p.payment_date, (p.product_category||'')+'-'+(p.product_name||'?'),
            amt.toLocaleString(), p.received_by_name || '—',
            p.created_at ? p.created_at.replace('T',' ').substring(0,16) : '—']);
    });
    waLines.push('', 'Total: RWF ' + total.toLocaleString());
    openShareModal({
        title: 'Payments — ' + _cpayClientName,
        subtitle: 'Period: ' + from + ' to ' + to,
        headers: ['#', 'Date', 'Product', 'Amount Paid (RWF)', 'Received By', 'Created At'],
        rows: pdfRows,
        footer: ['', '', 'Total (' + _cpayRows.length + ')', total.toLocaleString(), '', '']
    }, waLines.join('\n'));
}

function exportGlobalLoanPreview() {
    if (!_gloanPreviewRows.length) { alert('No preview data to export.'); return; }
    var amount = document.getElementById('gloan_amount').value;
    var clientSel = document.getElementById('gloan_client');
    var client = clientSel.value ? clientSel.options[clientSel.selectedIndex].text : '';
    var subtitle = 'Amount: RWF ' + parseFloat(amount).toLocaleString() + (client ? '  |  Client: ' + client : '');
    var waLines = ['Global Loan Payment Preview', subtitle, new Date().toLocaleDateString(), ''];
    var totalBalance = 0, totalWillPay = 0, pdfRows = [];
    _gloanPreviewRows.forEach(function(row, i) {
        var willPay = parseFloat(row.will_pay);
        var balance = parseFloat(row.balance);
        totalBalance += balance; totalWillPay += willPay;
        var status = willPay <= 0 ? 'Not covered' : (willPay >= balance ? 'Full' : 'Partial');
        waLines.push((i+1) + '. ' + row.date + ' | ' + row.label + ' | ' + (row.client||'—') +
            '\n   Balance: RWF ' + balance.toLocaleString() +
            '  Will pay: ' + (willPay > 0 ? 'RWF ' + willPay.toLocaleString() : '—') + ' (' + status + ')');
        pdfRows.push([(i+1), row.date, row.label, row.client||'—',
            balance.toLocaleString(), willPay > 0 ? willPay.toLocaleString() : '—']);
    });
    waLines.push('', 'Total Outstanding : RWF ' + totalBalance.toLocaleString(),
        'Total Will Pay    : RWF ' + totalWillPay.toLocaleString());
    openShareModal({
        title: 'Global Loan Payment Preview',
        subtitle: subtitle,
        headers: ['#', 'Date', 'Product', 'Client', 'Balance (RWF)', 'Will Pay (RWF)'],
        rows: pdfRows,
        footer: ['', '', '', 'Total (' + pdfRows.length + ')', totalBalance.toLocaleString(), totalWillPay.toLocaleString()]
    }, waLines.join('\n'));
}

function calcLoanAmount() {
    var qty = parseInt(document.getElementById('loan_qty').value) || 0;
    if (loanUnitPrice > 0) document.getElementById('loan_amount').value = qty * loanUnitPrice;
}

// Searchable select for loan product — options rendered from DataCache once
// the (shared, cross-page) product list resolves, instead of a PHP loop.
(function() {
    var hidden   = document.getElementById('loan_product_id');
    var search   = document.getElementById('loan_product_search');
    var dropdown = document.getElementById('loan_product_dropdown');
    var hi = -1;

    DataCache.getProducts().then(function(list) {
        dropdown.innerHTML = list.map(function(p) {
            return '<div class="searchable-select-option" data-value="' + p.id +
                '" data-price="' + p.retail_price + '" data-stock="' + p.retail_qty + '">' +
                _escHtml((p.category || '') + '-' + p.name) + '</div>';
        }).join('');
        dropdown.querySelectorAll('.searchable-select-option').forEach(function(o) {
            o.addEventListener('click', function() { pick(o); });
        });
    });

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
        if (!e.target.closest('#loanProductWrap')) dropdown.classList.remove('open');
    });

    function filter() {
        var term = search.value.toLowerCase();
        dropdown.querySelectorAll('.searchable-select-option').forEach(function(o) {
            o.classList.toggle('hidden', o.textContent.trim().toLowerCase().indexOf(term)===-1);
        });
    }
    function hl(vis) {
        dropdown.querySelectorAll('.searchable-select-option').forEach(function(o) { o.classList.remove('highlighted'); });
        if (vis[hi]) { vis[hi].classList.add('highlighted'); vis[hi].scrollIntoView({block:'nearest'}); }
    }
    function pick(opt) {
        hidden.value = opt.getAttribute('data-value');
        search.value = opt.textContent.trim();
        dropdown.classList.remove('open'); hi = -1;
        loanUnitPrice = parseFloat(opt.getAttribute('data-price')) || 0;
        var stock = opt.getAttribute('data-stock');
        var hint = document.getElementById('loanPriceHint');
        hint.textContent = loanUnitPrice > 0
            ? 'Unit price: RWF ' + loanUnitPrice.toLocaleString() + '  |  Stock: ' + stock + ' pcs'
            : 'No price set — enter amount manually.';
        calcLoanAmount();
    }
})();

document.getElementById('loan_qty').addEventListener('input', calcLoanAmount);

// Client picker — options rendered from DataCache.getClients() (same cached
// loan_clients data used across every page's client pickers).
(function() {
    var wrap     = document.getElementById('clientPickerWrap');
    if (!wrap) return;
    var group    = document.getElementById('clientPickerGroup');
    var search   = document.getElementById('client_picker_search');
    var dropdown = document.getElementById('client_picker_dropdown');
    var hi = -1;

    DataCache.getClients().then(function(list) {
        if (!list.length) return;
        group.style.display = '';
        dropdown.innerHTML = list.map(function(c) {
            var visits = parseInt(c.total_loans) || 0;
            return '<div class="searchable-select-option" data-client="' + _escHtml(c.name).replace(/"/g,'&quot;') +
                '" data-phone="' + _escHtml(c.phone || '').replace(/"/g,'&quot;') + '">' +
                _escHtml(c.name) + (c.phone ? ' — ' + _escHtml(c.phone) : '') +
                '<small style="color:var(--secondary);"> (' + visits + ' visit' + (visits !== 1 ? 's' : '') + ')</small></div>';
        }).join('');
        dropdown.querySelectorAll('.searchable-select-option').forEach(function(o) {
            o.addEventListener('click', function() { pick(o); });
        });
    });

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
        if (!e.target.closest('#clientPickerWrap')) dropdown.classList.remove('open');
    });

    function filter() {
        var term = search.value.toLowerCase();
        dropdown.querySelectorAll('.searchable-select-option').forEach(function(o) {
            o.classList.toggle('hidden', o.textContent.trim().toLowerCase().indexOf(term)===-1);
        });
    }
    function hl(vis) {
        dropdown.querySelectorAll('.searchable-select-option').forEach(function(o) { o.classList.remove('highlighted'); });
        if (vis[hi]) { vis[hi].classList.add('highlighted'); vis[hi].scrollIntoView({block:'nearest'}); }
    }
    function pick(opt) {
        document.getElementById('loan_client').value = opt.getAttribute('data-client');
        document.getElementById('loan_phone').value  = opt.getAttribute('data-phone');
        search.value = opt.getAttribute('data-client');
        dropdown.classList.remove('open'); hi = -1;
    }
})();

// Generic AJAX form helper
function ajaxForm(formId, alertId, actionName, onSuccess) {
    document.getElementById(formId).addEventListener('submit', function(e) {
        e.preventDefault();
        var form = this;
        var btn = form.querySelector('button[type="submit"]');
        var alertBox = document.getElementById(alertId);
        var orig = btn.textContent;
        btn.disabled = true; btn.textContent = 'Saving...';
        alertBox.style.display = 'none';

        function resetBtn() {
            btn.disabled = false;
            btn.classList.remove('btn-loading');
            btn.textContent = orig;
        }

        var data = new FormData(form);
        data.append(actionName, '1');

        fetch('loans.php', { method: 'POST', body: data })
            .then(function(r) { return r.text(); })
            .then(function(raw) {
                console.log('[ajaxForm:' + actionName + '] raw response:', raw);
                var res;
                try { res = JSON.parse(raw); } catch(e) {
                    console.error('[ajaxForm:' + actionName + '] JSON parse failed:', e);
                    alertBox.className = 'alert alert-danger';
                    alertBox.textContent = 'Server returned unexpected response. Check console.';
                    alertBox.style.display = 'block';
                    resetBtn();
                    return;
                }
                if (res.success) { resetBtn(); onSuccess(); }
                else {
                    alertBox.className = 'alert alert-danger';
                    alertBox.textContent = res.message || 'An error occurred.';
                    alertBox.style.display = 'block';
                    resetBtn();
                }
            })
            .catch(function(err) {
                console.error('[ajaxForm:' + actionName + '] fetch error:', err);
                alertBox.className = 'alert alert-danger';
                alertBox.textContent = 'Network error. Please try again.';
                alertBox.style.display = 'block';
                resetBtn();
            });
    });
}

ajaxForm('addLoanForm', 'addLoanAlert', 'add_loan', function() {
    closeModal('addLoanModal');
    document.getElementById('addLoanForm').reset();
    loanUnitPrice = 0;
    document.getElementById('loanPriceHint').textContent = '';
    document.getElementById('loan_product_search').value = '';
    document.getElementById('loan_product_id').value = '';
    DataCache.invalidate('products'); // loan reduces retail stock
    reloadClientsTable();
});

ajaxForm('paymentForm', 'paymentAlert', 'add_payment', function() {
    closeModal('paymentModal');
    reloadClientsTable();
    refreshOpenClientLoans();
});

ajaxForm('addClientForm', 'addClientAlert', 'add_new_client', function() {
    closeModal('addClientModal');
    document.getElementById('addClientForm').reset();
    var allBtn = document.querySelector('.filter-status-btn[data-status="all"]');
    if (allBtn) setStatusFilter(allBtn);
    reloadClientsTable();
});

function openPayment(btn) {
    var d = btn.dataset;
    document.getElementById('pay_loan_id').value = d.loanId;
    document.getElementById('pay_amount').value  = d.balance;
    document.getElementById('pay_amount').max    = d.balance;
    var info = document.getElementById('paymentInfo');
    info.innerHTML =
        '<span><strong>Client</strong>' + d.client + '</span>' +
        '<span><strong>Balance</strong>RWF ' + parseFloat(d.balance).toLocaleString() + '</span>';
    info.style.display = 'flex';
    document.getElementById('paymentAlert').style.display = 'none';
    document.getElementById('payment_date').value = new Date().toISOString().split('T')[0];
    openModal('paymentModal');
}

// ── Filter client table ────────────────────────────────────────────────────────
var _activeStatus = 'unpaid-partial';

function setStatusFilter(btn) {
    document.querySelectorAll('.filter-status-btn').forEach(function(b) { b.classList.remove('active'); });
    btn.classList.add('active');
    _activeStatus = btn.getAttribute('data-status');
    filterClientTable();
}

function filterClientTable() {
    var term = (document.getElementById('clientTableSearch').value || '').trim().toLowerCase();
    var rows = document.querySelectorAll('#tbl-loan-clients tbody tr');
    var visible = 0;
    rows.forEach(function(row) {
        var name   = (row.cells[1] || {}).textContent || '';
        var phone  = (row.cells[2] || {}).textContent || '';
        var status = row.getAttribute('data-status') || '';
        var matchText   = !term || name.toLowerCase().indexOf(term) !== -1 || phone.toLowerCase().indexOf(term) !== -1;
        var matchStatus = _activeStatus === 'all' ||
                          (_activeStatus === 'unpaid-partial' ? (status === 'unpaid' || status === 'partial') : status === _activeStatus);
        var show = matchText && matchStatus;
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    var badge = document.getElementById('clientCountBadge');
    if (badge) {
        var isFiltered = term || _activeStatus !== 'all';
        badge.textContent = isFiltered ? (visible + ' match' + (visible !== 1 ? 'es' : '')) : '';
    }
}
filterClientTable();

// ── Recalculate client balance ─────────────────────────────────────────────────
function recalcClientBalance(clientId, btn) {
    var row = btn.closest('tr');
    var fd = new FormData();
    fd.append('recalc_client_balance', '1');
    fd.append('client_id', clientId);
    fetch('loans.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.success) { reloadClientsTable(); }
            else { alert('Recalculate failed: ' + (res.message || 'unknown error')); }
        });
}

// ── View client loans modal ────────────────────────────────────────────────────
var _clLoans = [], _clFilter = 'unpaid', _clPage = 1, _clPageSize = 10, _clName = '', _clClientId = 0;

function viewClientLoans(clientName, clientId) {
    _clName = clientName;
    _clClientId = clientId;
    _clFilter = 'unpaid';
    _clPage = 1;
    _clLoans = [];
    document.getElementById('clientLoansTitle').textContent = clientName + ' — Loans';
    document.querySelectorAll('#clientLoansFilter [data-status]').forEach(function(b) {
        b.classList.toggle('active', b.getAttribute('data-status') === 'unpaid');
    });
    document.getElementById('clientLoansFilter').style.display = 'none';
    document.getElementById('clientLoansPagination').style.display = 'none';
    document.getElementById('clLoansExportBtn').style.display = 'none';
    var body = document.getElementById('clientLoansBody');
    body.innerHTML = '<p style="text-align:center;padding:20px;color:var(--secondary);">Loading...</p>';
    openModal('clientLoansModal');

    var data = new FormData();
    data.append('get_client_loans', '1');
    data.append('client_id', clientId);

    fetch('loans.php', { method: 'POST', body: data })
        .then(function(r) { return r.json(); })
        .then(function(rows) {
            if (!rows || rows.length === 0) {
                body.innerHTML = '<p style="text-align:center;padding:24px;color:var(--secondary);">No loans found for this client.</p>';
                return;
            }
            _clLoans = rows;
            document.getElementById('clientLoansFilter').style.display = 'flex';
            renderClientLoans();
        })
        .catch(function() {
            body.innerHTML = '<p style="color:var(--danger);padding:20px;">Failed to load loans. Please try again.</p>';
        });
}

function setClientLoanFilter(btn) {
    document.querySelectorAll('#clientLoansFilter [data-status]').forEach(function(b) { b.classList.remove('active'); });
    btn.classList.add('active');
    _clFilter = btn.getAttribute('data-status');
    _clPage = 1;
    renderClientLoans();
}

function clientLoanGoPage(p) { _clPage = p; renderClientLoans(); }

// Re-fetch and re-render the currently open "View Loans" modal so a just-recorded
// payment shows up in that row without needing to close/reopen the modal.
function refreshOpenClientLoans() {
    var modal = document.getElementById('clientLoansModal');
    if (!modal || modal.style.display !== 'block' || !_clClientId) return;

    var data = new FormData();
    data.append('get_client_loans', '1');
    data.append('client_id', _clClientId);

    fetch('loans.php', { method: 'POST', body: data })
        .then(function(r) { return r.json(); })
        .then(function(rows) {
            _clLoans = rows || [];
            renderClientLoans();
        });
}

function renderClientLoans() {
    var filtered = _clLoans.filter(function(l) {
        return _clFilter === 'all' || (parseFloat(l.amount) - parseFloat(l.total_paid)) > 0;
    });

    var totalPages = Math.max(1, Math.ceil(filtered.length / _clPageSize));
    if (_clPage > totalPages) _clPage = totalPages;
    var start = (_clPage - 1) * _clPageSize;
    var pageRows = filtered.slice(start, start + _clPageSize);

    document.getElementById('clientLoansCount').textContent =
        filtered.length + ' loan' + (filtered.length !== 1 ? 's' : '');

    var body = document.getElementById('clientLoansBody');
    if (filtered.length === 0) {
        body.innerHTML = '<p style="text-align:center;padding:24px;color:var(--secondary);">No unpaid loans for this client.</p>';
        document.getElementById('clientLoansPagination').style.display = 'none';
        return;
    }

    var totalLoaned = 0, totalPaid = 0;
    filtered.forEach(function(l) { totalLoaned += parseFloat(l.amount); totalPaid += parseFloat(l.total_paid); });

    var html = '<table class="table" style="font-size:13px;min-width:860px;">' +
        '<thead><tr><th></th><th>#</th><th>Loan Date</th><th>Product</th><th>Amount</th><th>Paid</th><th>Balance</th><th>Status</th><th>Created At</th><th>Given By</th></tr></thead><tbody>';

    pageRows.forEach(function(l, idx) {
        var balance = parseFloat(l.amount) - parseFloat(l.total_paid);
        var statusCls = balance <= 0 ? 'badge-paid' : (parseFloat(l.total_paid) > 0 ? 'badge-partial' : 'badge-unpaid');
        var statusTxt = balance <= 0 ? 'Paid' : (parseFloat(l.total_paid) > 0 ? 'Partial' : 'Unpaid');
        var createdAt = l.created_at ? l.created_at.replace('T',' ').substring(0,16) : '—';
        var givenBy   = l.given_by_name || '—';
        var saleTab = '', saleId = 0;
        if (l.external_id) { saleTab = 'external'; saleId = l.external_id; }
        else if (l.bulk_id) { saleTab = 'bulk'; saleId = l.bulk_id; }
        else if (l.retail_id) { saleTab = 'retail'; saleId = l.retail_id; }
        var saleLink = saleId
            ? '<a href="sales.php?tab=' + saleTab + '&highlight=' + saleId + '" target="_blank" ' +
              'style="font-size:12px;color:var(--primary);text-decoration:none;padding:3px 6px;border:1px solid var(--primary);border-radius:4px;margin-right:4px;">' +
              saleTab.charAt(0).toUpperCase() + saleTab.slice(1) + ' ↗</a>' : '';
        var cartItems = null;
        try { cartItems = l.cart ? JSON.parse(l.cart) : null; } catch (e) { cartItems = null; }
        var productCell;
        if (cartItems && cartItems.length > 1) {
            productCell =
                '<div style="font-size:11px;color:var(--secondary);margin-bottom:2px;">' +
                    _escHtml(l.product_category || '') + ' &middot; ' + cartItems.length + ' items' +
                '</div>' +
                cartItems.map(function(ci) { return _escHtml(ci.name) + ' &times;' + ci.quantity; }).join('<br>');
        } else {
            productCell = _escHtml(l.product_category || '') + '-' + _escHtml(l.product_name || '—');
        }
        html += '<tr>' +
            '<td style="white-space:nowrap;">' + saleLink +
            (balance > 0 ? '<button class="btn-pay" data-loan-id="' + l.id + '" data-balance="' + balance + '" data-client="' + _clName.replace(/"/g,'&quot;') + '" onclick="openPayment(this)" style="margin-right:4px;">Pay</button>' : '') +
            '<a href="loans.php?delete=' + l.id + '" onclick="return confirm(\'Delete this loan?\');" style="font-size:12px;color:var(--danger);text-decoration:none;padding:3px 6px;border:1px solid var(--danger);border-radius:4px;">Del</a>' +
            '</td>' +
            '<td style="color:var(--secondary);">' + (start + idx + 1) + '</td>' +
            '<td style="white-space:nowrap;">' + l.loan_date + '</td>' +
            '<td>' + productCell + '</td>' +
            '<td>RWF ' + parseFloat(l.amount).toLocaleString() + '</td>' +
            '<td>RWF ' + parseFloat(l.total_paid).toLocaleString() + '</td>' +
            '<td class="' + (balance > 0 ? 'has-balance' : 'cleared') + '"><strong>RWF ' + Math.abs(balance).toLocaleString() + '</strong></td>' +
            '<td><span class="' + statusCls + '">' + statusTxt + '</span></td>' +
            '<td style="color:var(--secondary);font-size:12px;white-space:nowrap;">' + createdAt + '</td>' +
            '<td style="color:var(--secondary);">' + givenBy + '</td>' +
            '</tr>';
    });

    var outstanding = totalLoaned - totalPaid;
    html += '</tbody><tfoot><tr style="font-weight:600;background:var(--gray-50);">' +
        '<td colspan="4" style="padding:10px 12px;">Total (' + filtered.length + ')</td>' +
        '<td>RWF ' + totalLoaned.toLocaleString() + '</td>' +
        '<td>RWF ' + totalPaid.toLocaleString() + '</td>' +
        '<td class="' + (outstanding > 0 ? 'has-balance' : 'cleared') + '"><strong>RWF ' + Math.abs(outstanding).toLocaleString() + '</strong></td>' +
        '<td colspan="3"></td></tr></tfoot></table>';
    body.innerHTML = html;
    document.getElementById('clLoansExportBtn').style.display = 'inline-flex';

    // Pagination
    var pag = document.getElementById('clientLoansPagination');
    if (totalPages <= 1) { pag.style.display = 'none'; return; }
    pag.style.display = 'flex';
    var ph = '<button onclick="clientLoanGoPage(' + Math.max(1, _clPage - 1) + ')" ' + (_clPage <= 1 ? 'disabled ' : '') +
        'style="padding:4px 10px;border:1px solid var(--gray-300);border-radius:6px;cursor:pointer;background:#fff;">&#8249;</button>';
    var last = 0;
    for (var p = 1; p <= totalPages; p++) {
        if (p === 1 || p === totalPages || (p >= _clPage - 2 && p <= _clPage + 2)) {
            if (last && p - last > 1) ph += '<span style="padding:4px 6px;">…</span>';
            var isActive = p === _clPage;
            ph += '<button onclick="clientLoanGoPage(' + p + ')" style="padding:4px 10px;border:1px solid ' +
                (isActive ? 'var(--primary)' : 'var(--gray-300)') + ';border-radius:6px;cursor:pointer;background:' +
                (isActive ? 'var(--primary)' : '#fff') + ';color:' + (isActive ? '#fff' : 'inherit') + ';">' + p + '</button>';
            last = p;
        }
    }
    ph += '<button onclick="clientLoanGoPage(' + Math.min(totalPages, _clPage + 1) + ')" ' + (_clPage >= totalPages ? 'disabled ' : '') +
        'style="padding:4px 10px;border:1px solid var(--gray-300);border-radius:6px;cursor:pointer;background:#fff;">&#8250;</button>' +
        '<span style="font-size:13px;color:var(--secondary);">Page ' + _clPage + ' of ' + totalPages + '</span>';
    pag.innerHTML = ph;
}

// ── Global Loan Payment ────────────────────────────────────────────────────────
function openGlobalLoanPay() {
    document.getElementById('gloan_amount').value  = '';
    document.getElementById('gloan_client').value  = '';
    document.getElementById('gloan_sort').value    = 'date_asc';
    document.getElementById('globalLoanPreview').style.display = 'none';
    document.getElementById('globalLoanPayAlert').style.display = 'none';
    document.getElementById('globalLoanPayBtn').disabled = true;
    document.getElementById('gloanExportBtn').style.display = 'none';
    _gloanPreviewRows = [];
    openModal('globalLoanPayModal');
}

function openGlobalLoanPayFor(clientId, outstanding) {
    document.getElementById('gloan_amount').value  = outstanding;
    document.getElementById('gloan_client').value  = clientId;
    document.getElementById('globalLoanPreview').style.display = 'none';
    document.getElementById('globalLoanPayAlert').style.display = 'none';
    document.getElementById('globalLoanPayBtn').disabled = true;
    openModal('globalLoanPayModal');
    scheduleLoanPreview();
}

var loanPreviewTimer = null;
function scheduleLoanPreview() {
    clearTimeout(loanPreviewTimer);
    var amount = parseFloat(document.getElementById('gloan_amount').value) || 0;
    if (amount > 0) {
        var sort = document.getElementById('gloan_sort').value;
        var isManual = sort === 'manual';
        document.getElementById('loanPreviewBody').innerHTML =
            '<tr><td colspan="' + (isManual ? 6 : 5) + '" style="padding:20px;text-align:center;color:var(--secondary);">' +
            '<span style="display:inline-block;width:16px;height:16px;border:2px solid var(--gray-200);border-top-color:var(--primary);border-radius:50%;animation:spin .7s linear infinite;vertical-align:middle;margin-right:8px;"></span>' +
            'Loading preview…</td></tr>';
        document.getElementById('loanPreviewSummary').innerHTML = '';
        document.getElementById('globalLoanPreview').style.display = 'block';
        document.getElementById('globalLoanPayBtn').disabled = true;
    }
    loanPreviewTimer = setTimeout(loadLoanPreview, 400);
}

function loadLoanPreview() {
    var amount  = parseFloat(document.getElementById('gloan_amount').value) || 0;
    var client  = document.getElementById('gloan_client').value.trim();
    var preview = document.getElementById('globalLoanPreview');
    var btn     = document.getElementById('globalLoanPayBtn');

    if (amount <= 0) { preview.style.display = 'none'; btn.disabled = true; document.getElementById('gloanExportBtn').style.display = 'none'; return; }

    var sort     = document.getElementById('gloan_sort').value;
    var isManual = sort === 'manual';
    document.getElementById('gloan_chk_th').style.display = isManual ? '' : 'none';
    if (isManual) { _gloanCheckOrder = []; document.getElementById('gloan_select_all').checked = false; }

    document.getElementById('loanPreviewBody').innerHTML =
        '<tr><td colspan="' + (isManual ? 6 : 5) + '" style="padding:20px;text-align:center;color:var(--secondary);">' +
        '<span style="display:inline-block;width:16px;height:16px;border:2px solid var(--gray-200);border-top-color:var(--primary);border-radius:50%;animation:spin .7s linear infinite;vertical-align:middle;margin-right:8px;"></span>' +
        'Loading preview…</td></tr>';
    document.getElementById('loanPreviewSummary').innerHTML = '';
    preview.style.display = 'block';
    btn.disabled = true;

    var data = new FormData();
    data.append('preview_global_loan_payment', '1');
    data.append('total_amount', amount);
    data.append('client_id', client);
    data.append('gloan_sort', isManual ? 'date_asc' : sort);

    fetch('loans.php', { method: 'POST', body: data })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            var body    = document.getElementById('loanPreviewBody');
            var summary = document.getElementById('loanPreviewSummary');
            body.innerHTML = '';

            _gloanPreviewRows = res.rows || [];
            document.getElementById('gloanExportBtn').style.display = _gloanPreviewRows.length ? 'inline-block' : 'none';

            if (!res.rows || res.rows.length === 0) {
                body.innerHTML = '<tr><td colspan="' + (isManual ? 6 : 5) + '" style="padding:16px;text-align:center;color:var(--secondary);">No unpaid loans found.</td></tr>';
                btn.disabled = true;
                preview.style.display = 'block';
                return;
            }

            var covered = 0, partial = 0, skipped = 0;
            res.rows.forEach(function(row) {
                var willPay = parseFloat(row.will_pay);
                var tr = document.createElement('tr');
                tr.style.borderBottom = '1px solid var(--gray-200)';
                var statusColor = willPay <= 0 ? '#94a3b8' : (row.full ? 'var(--success)' : 'var(--warning)');
                var payLabel    = willPay <= 0 ? '—' : 'RWF ' + willPay.toLocaleString();

                var chkCell = isManual
                    ? '<td style="padding:9px 8px;white-space:nowrap;">' +
                      '<input type="checkbox" class="gloan-row-chk" ' +
                      'data-id="' + row.id + '" data-balance="' + row.balance + '" ' +
                      'data-label="' + String(row.label || '').replace(/"/g, '&quot;') + '" ' +
                      'data-client="' + String(row.client || '').replace(/"/g, '&quot;') + '" ' +
                      'data-date="' + row.date + '" ' +
                      'onchange="gloanChkChange(this)" style="width:15px;height:15px;cursor:pointer;vertical-align:middle;">' +
                      '<span class="gloan-order-badge" style="display:none;background:var(--primary);color:#fff;border-radius:99px;padding:0 6px;font-size:11px;font-weight:700;margin-left:4px;vertical-align:middle;line-height:18px;"></span>' +
                      '</td>'
                    : '';

                tr.innerHTML = chkCell +
                    '<td style="padding:9px 12px;">' + row.date + '</td>' +
                    '<td style="padding:9px 12px;">' + row.label + '</td>' +
                    '<td style="padding:9px 12px;color:var(--secondary);">' + (row.client || '-') + '</td>' +
                    '<td style="padding:9px 12px;text-align:right;">RWF ' + parseFloat(row.balance).toLocaleString() + '</td>' +
                    '<td class="gloan-pay-cell" style="padding:9px 12px;text-align:right;font-weight:600;color:' + statusColor + ';">' + payLabel + '</td>';
                body.appendChild(tr);

                if (!isManual) {
                    if (willPay >= row.balance) covered++;
                    else if (willPay > 0)       partial++;
                    else                        skipped++;
                }
            });

            if (isManual) { recalcManualSummary(); return; }

            var applied = amount - res.leftover;
            var parts = [];
            if (covered) parts.push('<span style="color:var(--success);">&#10003; ' + covered + ' fully paid</span>');
            if (partial) parts.push('<span style="color:var(--warning);">~ ' + partial + ' partial</span>');
            if (skipped) parts.push('<span style="color:var(--secondary);">' + skipped + ' not covered</span>');
            if (res.leftover > 0) parts.push('<span style="color:var(--danger);">RWF ' + parseFloat(res.leftover).toLocaleString() + ' leftover</span>');

            summary.innerHTML = parts.join('&nbsp;&nbsp;&middot;&nbsp;&nbsp;') +
                '<br><small>Total applied: <strong>RWF ' + applied.toLocaleString() + '</strong> of RWF ' + parseFloat(res.total_outstanding).toLocaleString() + ' outstanding</small>';

            preview.style.display = 'block';
            btn.disabled = (applied <= 0);
        });
}

function recalcManualSummary() {
    var amount    = parseFloat(document.getElementById('gloan_amount').value) || 0;
    var remaining = amount;
    var covered = 0, partial = 0, skipped = 0;
    var newRows   = [];

    document.querySelectorAll('#loanPreviewBody .gloan-pay-cell').forEach(function(cell) {
        cell.style.color = '#94a3b8'; cell.textContent = '—';
    });

    _gloanCheckOrder.forEach(function(id) {
        var chk = document.querySelector('#loanPreviewBody .gloan-row-chk[data-id="' + id + '"]');
        if (!chk || !chk.checked) return;
        var balance = parseFloat(chk.dataset.balance);
        var payCell = chk.closest('tr').querySelector('.gloan-pay-cell');
        var pay  = Math.min(remaining, balance);
        var full = pay >= balance;
        payCell.style.color = pay <= 0 ? '#94a3b8' : (full ? 'var(--success)' : 'var(--warning)');
        payCell.textContent = pay <= 0 ? '—' : 'RWF ' + pay.toLocaleString();
        remaining -= pay;
        if (full) covered++; else if (pay > 0) partial++; else skipped++;
        newRows.push({ id: chk.dataset.id, label: chk.dataset.label, client: chk.dataset.client,
            date: chk.dataset.date, balance: balance, will_pay: pay, full: full });
    });
    document.querySelectorAll('#loanPreviewBody .gloan-row-chk:not(:checked)').forEach(function(chk) {
        skipped++;
    });

    _gloanPreviewRows = newRows;
    var applied = amount - Math.max(0, remaining);
    var parts = [];
    if (covered) parts.push('<span style="color:var(--success);">&#10003; ' + covered + ' fully paid</span>');
    if (partial) parts.push('<span style="color:var(--warning);">~ ' + partial + ' partial</span>');
    if (skipped) parts.push('<span style="color:var(--secondary);">' + skipped + ' skipped</span>');
    if (remaining > 0) parts.push('<span style="color:var(--danger);">RWF ' + Math.round(remaining).toLocaleString() + ' leftover</span>');
    document.getElementById('loanPreviewSummary').innerHTML =
        parts.join('&nbsp;&nbsp;&middot;&nbsp;&nbsp;') +
        '<br><small>Total applied: <strong>RWF ' + Math.round(applied).toLocaleString() + '</strong></small>';
    document.getElementById('globalLoanPreview').style.display = 'block';
    document.getElementById('globalLoanPayBtn').disabled = (applied <= 0);
    document.getElementById('gloanExportBtn').style.display = newRows.length ? 'inline-block' : 'none';
}

var _gloanCheckOrder = [];

function gloanChkChange(chk) {
    var id = chk.dataset.id;
    if (chk.checked) {
        if (_gloanCheckOrder.indexOf(id) === -1) _gloanCheckOrder.push(id);
    } else {
        _gloanCheckOrder = _gloanCheckOrder.filter(function(x) { return x !== id; });
    }
    updateCheckBadges();
    recalcManualSummary();
}

function gloanSelectAll(allChecked) {
    _gloanCheckOrder = [];
    document.querySelectorAll('.gloan-row-chk').forEach(function(c) {
        c.checked = allChecked;
        if (allChecked) _gloanCheckOrder.push(c.dataset.id);
    });
    updateCheckBadges();
    recalcManualSummary();
}

function updateCheckBadges() {
    document.querySelectorAll('.gloan-row-chk').forEach(function(chk) {
        var badge = chk.parentNode.querySelector('.gloan-order-badge');
        if (!badge) return;
        var idx = _gloanCheckOrder.indexOf(chk.dataset.id);
        if (idx === -1) {
            badge.style.display = 'none';
        } else {
            badge.textContent = idx + 1;
            badge.style.display = 'inline-block';
        }
    });
}

function execGlobalLoanPay() {
    var amount  = parseFloat(document.getElementById('gloan_amount').value) || 0;
    var client  = document.getElementById('gloan_client').value.trim();
    var btn     = document.getElementById('globalLoanPayBtn');
    var alertBox = document.getElementById('globalLoanPayAlert');

    if (amount <= 0) return;
    if (!confirm('Apply payment of RWF ' + amount.toLocaleString() + '?\nThis cannot be undone.')) return;
    btn.disabled = true; btn.textContent = 'Applying...';
    alertBox.style.display = 'none';

    var sort = document.getElementById('gloan_sort').value;
    var data = new FormData();
    data.append('exec_global_loan_payment', '1');
    data.append('total_amount', amount);
    data.append('client_id', client);

    if (sort === 'manual') {
        _gloanCheckOrder.forEach(function(id) {
            data.append('manual_loan_ids[]', id);
        });
    } else {
        data.append('gloan_sort', sort);
    }

    fetch('loans.php', { method: 'POST', body: data })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.success) {
                closeModal('globalLoanPayModal');
                reloadClientsTable();
            } else {
                alertBox.className = 'alert alert-danger';
                alertBox.textContent = res.message || 'An error occurred.';
                alertBox.style.display = 'block';
                btn.disabled = false; btn.textContent = 'Apply Payment';
            }
        });
}

// ── Client Payments Modal ──────────────────────────────────────────────────────
var _cpayClientId = 0;

function viewClientPayments(clientId, clientName) {
    _cpayClientId = clientId;
    _cpayClientName = clientName;
    document.getElementById('clientPaymentsTitle').textContent = clientName + ' — Payments';
    document.getElementById('clientPaymentsCount').textContent = '';
    switchCpayTab('loan');

    var today = new Date();
    var from  = new Date(today.getFullYear(), today.getMonth(), 1);
    document.getElementById('cpay_to').value   = today.toISOString().split('T')[0];
    document.getElementById('cpay_from').value = from.toISOString().split('T')[0];

    openModal('clientPaymentsModal');
    loadClientPayments();
}

// ── AJAX: Reload clients table without full page refresh ───────────────────────
function _escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function _fmtLastUpdate(v) {
    if (!v) return '&mdash;';
    var d = new Date(String(v).replace(' ', 'T'));
    if (isNaN(d.getTime())) return '&mdash;';
    return d.toLocaleDateString('en-US', {month:'short', day:'2-digit', year:'numeric'}) +
        ' ' + d.toLocaleTimeString('en-US', {hour:'numeric', minute:'2-digit'});
}

function reloadClientsTable() {
    var wrap = document.getElementById('clients-table-wrap');
    wrap.innerHTML = '<div style="text-align:center;padding:48px;color:var(--secondary);"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';

    var fd = new FormData();
    fd.append('get_loan_stats', '1');

    Promise.all([
        fetch('loans.php', { method: 'POST', body: fd }).then(function(r) { return r.json(); }),
        DataCache.getClients({ force: true })
    ])
        .then(function(results) {
            var statsRes = results[0];
            var clients = results[1].map(function(c) {
                return {
                    client_id: c.id, name: c.name, phone: c.phone,
                    loan_count: c.total_loans,
                    total_loaned: parseFloat(c.paid_amount) + parseFloat(c.unpaid_amount),
                    total_paid: c.paid_amount,
                    last_update: c.updated_at
                };
            });
            _updateLoanStats(statsRes.stats, statsRes.outstanding);
            _updateClientDropdown(clients);
            _renderClientsTable(clients);
            filterClientTable();
        })
        .catch(function() {
            wrap.innerHTML = '<div style="text-align:center;padding:48px;color:var(--danger);">Failed to reload. <a href="loans.php">Refresh page</a></div>';
        });
}

function _updateLoanStats(stats, outstanding) {
    document.getElementById('stat-total-loans').textContent  = parseInt(stats.total_loans).toLocaleString();
    document.getElementById('stat-clients-sub').textContent  = stats.total_clients + ' client' + (stats.total_clients != 1 ? 's' : '');
    document.getElementById('stat-total-loaned').textContent = 'RWF ' + parseFloat(stats.total_amount).toLocaleString();
    document.getElementById('stat-total-paid').textContent   = 'RWF ' + parseFloat(stats.total_paid).toLocaleString();
    var outEl = document.getElementById('stat-outstanding');
    outEl.textContent = 'RWF ' + Math.abs(parseFloat(outstanding)).toLocaleString();
    outEl.className = 'loan-card-value ' + (outstanding > 0 ? 'danger' : 'success');
    var gpw = document.getElementById('global-pay-btn-wrap');
    gpw.innerHTML = outstanding > 0
        ? '<button onclick="openGlobalLoanPay()" class="btn btn-secondary" style="border-color:var(--warning);color:var(--warning);font-weight:600;">Global Pay &nbsp;<span style="background:var(--warning);color:#fff;border-radius:99px;padding:1px 8px;font-size:12px;">RWF ' + parseFloat(outstanding).toLocaleString() + '</span></button>'
        : '';
}

function _updateClientDropdown(clients) {
    var sel = document.getElementById('gloan_client');
    var cur = sel ? sel.value : '';
    var html = '<option value="">All clients</option>';
    (clients || []).forEach(function(c) {
        html += '<option value="' + c.client_id + '"' + (c.client_id == cur ? ' selected' : '') + '>' + _escHtml(c.name) + '</option>';
    });
    if (sel) sel.innerHTML = html;
}

function _renderClientsTable(clients) {
    var wrap = document.getElementById('clients-table-wrap');
    if (!clients || !clients.length) {
        wrap.innerHTML = '<div style="text-align:center;padding:48px;color:var(--secondary);">No loan clients found.</div>';
        return;
    }
    var html = '<div style="overflow-x:auto;"><table class="table" id="tbl-loan-clients" style="min-width:700px;">' +
        '<thead><tr><th>Actions</th><th>#</th><th>Client</th><th>Phone</th><th>Loans</th><th>Total Loaned</th><th>Paid</th><th>Outstanding</th><th>Status</th><th>Last Update</th></tr></thead><tbody>';

    clients.forEach(function(c, i) {
        var outstanding = parseFloat(c.total_loaned) - parseFloat(c.total_paid);
        var status, badge;
        if (outstanding <= 0)                         { status = 'Paid';    badge = 'badge-paid'; }
        else if (parseFloat(c.total_paid) > 0)        { status = 'Partial'; badge = 'badge-partial'; }
        else                                          { status = 'Unpaid';  badge = 'badge-unpaid'; }

        var payBtn = outstanding > 0
            ? '<div class="act-menu-sep"></div><button class="act-item" style="color:#d97706;" onclick="openGlobalLoanPayFor(' + c.client_id + ',' + outstanding + ');closeActMenus()"><i class="fas fa-money-bill-wave"></i> Pay</button>'
            : '';

        html += '<tr data-status="' + status.toLowerCase() + '">' +
            '<td><div class="act-menu-wrap"><button class="act-btn" title="Actions" onclick="toggleActMenu(this)">&#8942;</button>' +
            '<div class="act-menu">' + payBtn +
            '<button class="act-item" onclick="viewClientLoans(' + _escHtml(JSON.stringify(c.name)) + ',' + parseInt(c.client_id) + ');closeActMenus()"><i class="fas fa-eye"></i> View Loans</button>' +
            '<button class="act-item" onclick="viewClientPayments(' + parseInt(c.client_id) + ',' + _escHtml(JSON.stringify(c.name)) + ');closeActMenus()"><i class="fas fa-clock-rotate-left"></i> Payments</button>' +
            '<div class="act-menu-sep"></div>' +
            '<button class="act-item" style="color:#0ea5e9;" onclick="recalcClientBalance(' + parseInt(c.client_id) + ',this);closeActMenus()"><i class="fas fa-rotate"></i> Recalculate</button>' +
            '</div></div></td>' +
            '<td style="color:var(--secondary);">' + (i + 1) + '</td>' +
            '<td style="font-weight:600;">' + _escHtml(c.name) + '</td>' +
            '<td style="color:var(--secondary);">' + (c.phone ? _escHtml(c.phone) : '&mdash;') + '</td>' +
            '<td>' + parseInt(c.loan_count) + '</td>' +
            '<td>RWF ' + parseFloat(c.total_loaned).toLocaleString() + '</td>' +
            '<td>RWF ' + parseFloat(c.total_paid).toLocaleString() + '</td>' +
            '<td class="' + (outstanding > 0 ? 'has-balance' : 'cleared') + '"><strong>RWF ' + Math.abs(outstanding).toLocaleString() + '</strong></td>' +
            '<td><span class="' + badge + '">' + status + '</span></td>' +
            '<td style="color:var(--secondary);font-size:12.5px;white-space:nowrap;">' + _fmtLastUpdate(c.last_update) + '</td>' +
            '</tr>';
    });

    html += '</tbody></table></div>';
    wrap.innerHTML = html;
}

var _cpayActiveTab = 'loan';

function switchCpayTab(tab) {
    _cpayActiveTab = tab;
    ['loan', 'direct'].forEach(function(t) {
        var btn   = document.getElementById('cpay-tab-' + t);
        var panel = document.getElementById('cpay-panel-' + t);
        var active = t === tab;
        btn.style.fontWeight   = active ? '700' : '400';
        btn.style.color        = active ? 'var(--primary)' : 'var(--secondary)';
        btn.style.borderBottom = active ? '3px solid var(--primary)' : '3px solid transparent';
        panel.style.display    = active ? '' : 'none';
    });
    document.getElementById('cpayExportBtn').style.display =
        (tab === 'loan' && _cpayRows.length) ? 'inline-block' : 'none';
}

function loadClientPayments() {
    _cpayRows = [];
    document.getElementById('cpayExportBtn').style.display = 'none';
    var loading = '<p style="text-align:center;padding:20px;color:var(--secondary);">Loading...</p>';
    document.getElementById('cpay-panel-loan').innerHTML   = loading;
    document.getElementById('cpay-panel-direct').innerHTML = loading;

    var from = document.getElementById('cpay_from').value;
    var to   = document.getElementById('cpay_to').value;

    var fd1 = new FormData();
    fd1.append('get_client_payments', '1');
    fd1.append('client_id', _cpayClientId);
    fd1.append('date_from', from);
    fd1.append('date_to', to);

    var fd2 = new FormData();
    fd2.append('get_client_payments_direct', '1');
    fd2.append('client_id', _cpayClientId);
    fd2.append('date_from', from);
    fd2.append('date_to', to);

    Promise.all([
        fetch('loans.php', { method: 'POST', body: fd1 }).then(function(r) { return r.json(); }),
        fetch('loans.php', { method: 'POST', body: fd2 }).then(function(r) { return r.json(); })
    ]).then(function(results) {
        var loanRows   = results[0] || [];
        var directRows = results[1] || [];
        _cpayRows = loanRows;

        document.getElementById('cpay-badge-loan').textContent   = '(' + loanRows.length + ')';
        document.getElementById('cpay-badge-direct').textContent = '(' + directRows.length + ')';
        document.getElementById('clientPaymentsCount').textContent =
            (loanRows.length + directRows.length) + ' payment' + ((loanRows.length + directRows.length) !== 1 ? 's' : '');

        // Loan payments panel
        var lPanel = document.getElementById('cpay-panel-loan');
        if (loanRows.length) {
            var lTotal = 0;
            var lHtml = '<table class="table" style="font-size:13px;min-width:620px;">' +
                '<thead><tr><th>#</th><th>Date</th><th>Product</th><th>Amount Paid</th><th>Received By</th><th>Created At</th></tr></thead><tbody>';
            loanRows.forEach(function(p, i) {
                lTotal += parseFloat(p.amount_paid);
                var createdAt = p.created_at ? p.created_at.replace('T',' ').substring(0,16) : '—';
                lHtml += '<tr>' +
                    '<td style="color:var(--secondary);">' + (i + 1) + '</td>' +
                    '<td style="white-space:nowrap;">' + p.payment_date + '</td>' +
                    '<td>' + (p.product_category || '') + '-' + (p.product_name || '—') + '</td>' +
                    '<td style="font-weight:600;color:var(--success);">RWF ' + parseFloat(p.amount_paid).toLocaleString() + '</td>' +
                    '<td style="color:var(--secondary);">' + (p.received_by_name || '—') + '</td>' +
                    '<td style="color:var(--secondary);font-size:12px;white-space:nowrap;">' + createdAt + '</td>' +
                    '</tr>';
            });
            lHtml += '</tbody><tfoot><tr style="font-weight:600;background:var(--gray-50);">' +
                '<td colspan="3" style="padding:10px 12px;">Total (' + loanRows.length + ')</td>' +
                '<td style="color:var(--success);">RWF ' + lTotal.toLocaleString() + '</td>' +
                '<td colspan="2"></td></tr></tfoot></table>';
            lPanel.innerHTML = lHtml;
        } else {
            lPanel.innerHTML = '<p style="text-align:center;padding:24px;color:var(--secondary);">No loan payments in this period.</p>';
        }

        // Direct payments panel
        var dPanel = document.getElementById('cpay-panel-direct');
        if (directRows.length) {
            var dTotal = 0;
            var dHtml = '<table class="table" style="font-size:13px;min-width:520px;">' +
                '<thead><tr><th>#</th><th>Date</th><th>Amount</th><th>Note</th><th>Recorded By</th><th>Created At</th></tr></thead><tbody>';
            directRows.forEach(function(p, i) {
                dTotal += parseFloat(p.amount);
                var createdAt = p.created_at ? p.created_at.replace('T',' ').substring(0,16) : '—';
                dHtml += '<tr>' +
                    '<td style="color:var(--secondary);">' + (i + 1) + '</td>' +
                    '<td style="white-space:nowrap;">' + p.payment_date + '</td>' +
                    '<td style="font-weight:600;color:var(--success);">RWF ' + parseFloat(p.amount).toLocaleString() + '</td>' +
                    '<td style="color:var(--secondary);">' + (p.note || '—') + '</td>' +
                    '<td style="color:var(--secondary);">' + (p.recorded_by_name || '—') + '</td>' +
                    '<td style="color:var(--secondary);font-size:12px;white-space:nowrap;">' + createdAt + '</td>' +
                    '</tr>';
            });
            dHtml += '</tbody><tfoot><tr style="font-weight:600;background:var(--gray-50);">' +
                '<td colspan="2" style="padding:10px 12px;">Total (' + directRows.length + ')</td>' +
                '<td style="color:var(--success);">RWF ' + dTotal.toLocaleString() + '</td>' +
                '<td colspan="3"></td></tr></tfoot></table>';
            dPanel.innerHTML = dHtml;
        } else {
            dPanel.innerHTML = '<p style="text-align:center;padding:24px;color:var(--secondary);">No direct payments in this period.</p>';
        }

        if (_cpayActiveTab === 'loan' && loanRows.length) {
            document.getElementById('cpayExportBtn').style.display = 'inline-block';
        }
    }).catch(function() {
        document.getElementById('cpay-panel-loan').innerHTML   = '<p style="color:var(--danger);padding:20px;">Failed to load payments.</p>';
        document.getElementById('cpay-panel-direct').innerHTML = '';
    });
}
</script>
</body>
</html>
