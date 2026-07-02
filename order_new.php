<?php
require_once 'config.php';
if (!isLoggedIn()) redirect('login.php');
if (!hasPermission('orders', 'create')) { $_SESSION['flash_error'] = "You don't have permission to create orders."; redirect('dashboard.php'); }
global $conn;

$user_id = (int)$_SESSION['user_id'];

// ── AJAX: create owner ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_owner'])) {
    header('Content-Type: application/json');
    $name  = trim($_POST['owner_name']     ?? '');
    $phone = trim($_POST['owner_phone']    ?? '');
    $loc   = trim($_POST['owner_location'] ?? '');
    if (!$name) { echo json_encode(['success'=>false,'message'=>'Name is required.']); exit; }
    $ne=mysqli_real_escape_string($conn,$name);
    $pe=mysqli_real_escape_string($conn,$phone);
    $le=mysqli_real_escape_string($conn,$loc);
    mysqli_query($conn,"INSERT INTO order_owners(company_id,name,phone,location) VALUES(" . cidSql() . ",'$ne','$pe','$le')");
    echo json_encode(['success'=>true,'id'=>(int)mysqli_insert_id($conn),
                      'name'=>$name,'phone'=>$phone,'location'=>$loc]);
    exit;
}

// ── Order submission ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_order'])) {
    $owner_id      = (int)($_POST['order_owner_id'] ?? 0);
    $items_raw     = $_POST['order_items_json'] ?? '[]';
    $prepaid_cash  = max(0, (float)($_POST['prepaid_cash']  ?? 0));
    $prepaid_momo  = max(0, (float)($_POST['prepaid_momo']  ?? 0));
    $prepaid_loan  = max(0, (float)($_POST['prepaid_loan']  ?? 0));
    $prepaid_bank  = max(0, (float)($_POST['prepaid_bank']  ?? 0));
    $total_prepaid = $prepaid_cash + $prepaid_momo + $prepaid_loan + $prepaid_bank;
    $note          = trim($_POST['note'] ?? '');

    $items = json_decode($items_raw, true) ?: [];
    $total_amount = 0.0;
    foreach ($items as $it) $total_amount += (float)($it['item_total'] ?? 0);
    $total_amount = round($total_amount, 2);

    $owner = $owner_id
        ? mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM order_owners WHERE id=$owner_id"))
        : null;

    $err = '';
    if (!$owner)                                 $err = 'Please select or create an order owner.';
    elseif (count($items) < 1)                   $err = 'Add at least one product to the order.';
    elseif ($total_amount <= 0)                  $err = 'Order total must be greater than zero.';
    elseif ($total_prepaid > $total_amount + 1)  $err = 'Prepaid cannot exceed order total.';
    elseif ($prepaid_loan > 0 && !$owner['phone']) $err = 'Owner phone required when prepaid includes a loan.';

    if ($err) {
        $error = $err;
    } else {
        $oe  = mysqli_real_escape_string($conn, $owner['name']);
        $phe = mysqli_real_escape_string($conn, $owner['phone']);
        $ne  = mysqli_real_escape_string($conn, $note);

        $ins_ok = mysqli_query($conn, "INSERT INTO `orders`
            (company_id, order_owner_id, product_id, quantity, level_divisor, selling_price, total_amount,
             order_owner, phone, prepaid_cash, prepaid_momo, prepaid_loan, prepaid_bank,
             total_prepaid, note, created_by)
            VALUES (" . cidSql() . ", $owner_id, NULL, 0, 1, 0, $total_amount,
                    '$oe', '$phe', $prepaid_cash, $prepaid_momo, $prepaid_loan, $prepaid_bank,
                    $total_prepaid, '$ne', $user_id)");

        if (!$ins_ok) {
            $error = 'Could not save order: ' . mysqli_error($conn);
        } else {
        $order_id     = (int)mysqli_insert_id($conn);
        $order_number = 'ORD-' . str_pad($order_id, 5, '0', STR_PAD_LEFT);
        $upd_ok = mysqli_query($conn, "UPDATE `orders` SET order_number='$order_number' WHERE id=$order_id");
        if (!$upd_ok || mysqli_affected_rows($conn) === 0) {
            error_log("orders.php: failed to set order_number for id=$order_id — " . mysqli_error($conn));
        }

        foreach ($items as $it) {
            $pid  = (int)$it['product_id'];
            $qty  = (float)$it['quantity'];
            $div  = max(1, (int)($it['level_divisor'] ?? 1));
            $prce = (float)$it['selling_price'];
            $itot = round($qty * $prce, 2);
            $src  = in_array($it['stock_type'] ?? 'wh', ['wh','rt']) ? $it['stock_type'] : 'wh';
            if ($pid > 0 && $qty > 0 && $prce > 0)
                mysqli_query($conn,
                    "INSERT INTO order_items(order_id,product_id,stock_source,quantity,level_divisor,selling_price,item_total)
                     VALUES($order_id,$pid,'$src',$qty,$div,$prce,$itot)");
        }
            if ($total_prepaid > 0) {
                mysqli_query($conn, "INSERT INTO order_payments
                    (company_id,order_id,cash,momo,bank,loan,total,recorded_by,note)
                    VALUES(" . cidSql() . ",$order_id,$prepaid_cash,$prepaid_momo,$prepaid_bank,$prepaid_loan,$total_prepaid,$user_id,'Initial prepaid')");
            }
            $success = "Order $order_number created successfully for {$owner['name']}.";
        } // end else (ins_ok)
    }
}
if (isset($_SESSION['flash_error'])) { $error = $_SESSION['flash_error']; unset($_SESSION['flash_error']); }

// ── AJAX: product search ──────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'search_products') {
    header('Content-Type: application/json');
    $search_sql = productSearchSql($conn, $_GET['q'] ?? '');
    $res = mysqli_query($conn, "
        SELECT p.id, p.name, p.category,
               COALESCE(s.quantity,0)           AS stock_qty,
               COALESCE(s.package_price,0)      AS default_price,
               COALESCE(s.pieces_per_package,1) AS ppp,
               COALESCE(rs.pieces_quantity,0)   AS rt_qty,
               COALESCE(rs.retail_price,0)      AS rt_price
        FROM products p
        LEFT JOIN stock        s  ON s.product_id  = p.id
        LEFT JOIN retail_stock rs ON rs.product_id = p.id
        WHERE p.deleted = 0
        AND $search_sql
        HAVING stock_qty > 0 OR rt_qty > 0
        ORDER BY p.category, p.name LIMIT 60");
    $rows = [];
    while ($row = mysqli_fetch_assoc($res)) $rows[] = $row;
    echo json_encode($rows);
    exit;
}

// ── Load data ─────────────────────────────────────────────────────────────────
$owners_arr = [];
$r = mysqli_query($conn, "SELECT * FROM order_owners " . cidWhere() . " ORDER BY name");
while ($row = mysqli_fetch_assoc($r)) $owners_arr[] = $row;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>New Order</title>
<link rel="stylesheet" href="css/style.css">
<link rel="stylesheet" href="css/sales.css">
<style>
.order-wrap {
    background:var(--white); border-radius:var(--radius-lg);
    box-shadow:var(--shadow-md); padding:32px; max-width:1060px;
}
.page-hdr { display:flex; align-items:center; gap:16px; margin-bottom:32px; }
.page-hdr h1 { margin:0; font-size:22px; }
.back-btn {
    display:inline-flex; align-items:center; gap:6px; padding:7px 14px;
    border-radius:var(--radius); background:var(--gray-100); color:var(--dark);
    text-decoration:none; font-size:13px; font-weight:500; border:1px solid var(--gray-300);
}
.back-btn:hover { background:var(--gray-200); }

/* ── Step indicator ───────────────────────────────────────────────────────── */
.ms-bar { display:flex; align-items:center; margin-bottom:36px; }
.ms-step { display:flex; flex-direction:column; align-items:center; gap:6px; }
.ms-bubble {
    width:40px; height:40px; border-radius:50%;
    background:var(--gray-200); color:var(--secondary);
    display:flex; align-items:center; justify-content:center;
    font-size:15px; font-weight:700; transition:.2s; border:2px solid transparent;
}
.ms-step.active .ms-bubble {
    background:var(--primary); color:#fff; border-color:var(--primary);
    box-shadow:0 0 0 4px rgba(37,99,235,.12);
}
.ms-step.done .ms-bubble { background:#10b981; color:#fff; border-color:#10b981; }
.ms-label { font-size:12px; font-weight:600; color:var(--secondary); white-space:nowrap; }
.ms-step.active .ms-label { color:var(--primary); }
.ms-step.done   .ms-label { color:#10b981; }
.ms-line { flex:1; height:2px; background:var(--gray-200); margin:0 8px; margin-bottom:20px; min-width:32px; transition:.2s; }
.ms-line.done { background:#10b981; }

/* ── Step panels ──────────────────────────────────────────────────────────── */
.ms-panel { display:none; }
.ms-panel.active { display:block; animation:msIn .22s ease; }
@keyframes msIn { from{opacity:0;transform:translateY(8px)} to{opacity:1;transform:none} }

/* ── Navigation ───────────────────────────────────────────────────────────── */
.ms-nav { display:flex; align-items:center; justify-content:space-between; margin-top:28px; gap:12px; }
.ms-btn { display:inline-flex; align-items:center; gap:6px; padding:10px 22px; border-radius:var(--radius); font-size:14px; font-weight:600; cursor:pointer; border:none; transition:.15s; }
.ms-back { background:var(--gray-100); color:var(--dark); border:1px solid var(--gray-300); }
.ms-back:hover { background:var(--gray-200); }
.ms-next { background:var(--primary); color:#fff; margin-left:auto; }
.ms-next:hover:not(:disabled) { background:var(--primary-dark); }
.ms-next:disabled,.ms-submit:disabled { opacity:.42; cursor:default; }
.ms-submit { background:#059669; color:#fff; }
.ms-submit:hover:not(:disabled) { background:#047857; }

/* ── Owner select / card ──────────────────────────────────────────────────── */
.ss-wrap { position:relative; }
.ss-input {
    width:100%; padding:10px 12px; border:1px solid var(--gray-300);
    border-radius:var(--radius); font-size:14px; background:var(--white); box-sizing:border-box;
}
.ss-input:focus { outline:none; border-color:var(--primary); box-shadow:0 0 0 3px rgba(37,99,235,.15); }
.ss-drop {
    display:none; position:absolute; top:100%; left:0; right:0;
    max-height:230px; overflow-y:auto; background:var(--white);
    border:1px solid var(--gray-300); border-top:none;
    border-radius:0 0 var(--radius) var(--radius); z-index:1000; box-shadow:var(--shadow-md);
}
.ss-drop.open { display:block; }
.ss-opt { padding:9px 12px; cursor:pointer; font-size:14px; }
.ss-opt:hover,.ss-opt.hi { background:var(--gray-100); color:var(--primary); }
.ss-opt.hidden { display:none; }
.ss-sub { font-size:11px; color:var(--secondary); }
.sd-loading { display:flex; align-items:center; gap:8px; padding:10px 12px; font-size:13px; color:var(--secondary); }
.sd-spinner { width:14px; height:14px; border:2px solid var(--gray-300); border-top-color:var(--primary); border-radius:50%; animation:sd-spin .7s linear infinite; flex-shrink:0; }
@keyframes sd-spin { to { transform:rotate(360deg); } }

.owner-card {
    display:none; background:#eff6ff; border:1px solid #bfdbfe;
    border-radius:var(--radius); padding:12px 16px;
    align-items:center; justify-content:space-between; gap:10px;
}
.owner-card.show { display:flex; }
.owner-card-name { font-weight:700; color:#1e40af; font-size:15px; }
.owner-card-meta { color:var(--secondary); font-size:12px; margin-top:3px; }
.owner-card-clear { background:none; border:none; color:#94a3b8; cursor:pointer; font-size:20px; line-height:1; padding:0 4px; flex-shrink:0; }
.owner-card-clear:hover { color:#dc2626; }

.new-owner-toggle {
    display:inline-flex; align-items:center; gap:5px; margin-top:10px;
    font-size:13px; color:var(--primary); cursor:pointer;
    font-weight:600; background:none; border:none; padding:0;
}
.new-owner-toggle:hover { text-decoration:underline; }
.new-owner-panel {
    display:none; margin-top:12px; background:var(--gray-50);
    border:1px solid var(--gray-200); border-radius:var(--radius); padding:16px;
}
.new-owner-panel.open { display:block; }
.no-2col { display:grid; grid-template-columns:1fr 1fr; gap:0 14px; }
@media(max-width:600px){ .no-2col { grid-template-columns:1fr; } }

/* ── Step 2: product + cart ───────────────────────────────────────────────── */
.order-split { display:grid; grid-template-columns:1fr 320px; gap:24px; align-items:start; }
@media(max-width:820px){ .order-split { grid-template-columns:1fr; } }
.sec-lbl { font-size:12px; font-weight:700; color:var(--secondary); text-transform:uppercase; letter-spacing:.5px; margin-bottom:10px; }
.default-price-badge {
    font-size:11px; background:var(--gray-100); color:var(--secondary);
    border:1px solid var(--gray-300); border-radius:4px; padding:3px 8px;
    cursor:pointer; white-space:nowrap; margin-left:6px;
}
.default-price-badge:hover { background:var(--primary); color:#fff; border-color:var(--primary); }
/* ── Stock type toggle ──────────────────────────────────────────────────────── */
.stype-toggle { display:flex; gap:8px; }
.stype-btn {
    flex:1; padding:9px 12px; border:2px solid var(--gray-200);
    border-radius:var(--radius); background:#fff; cursor:pointer;
    font-size:13px; font-weight:600; color:var(--secondary);
    transition:.15s; text-align:center; line-height:1.3;
}
.stype-btn:hover:not(.active):not(:disabled) { border-color:var(--gray-300); background:var(--gray-100); }
.stype-btn.active { border-color:var(--primary); background:#eff6ff; color:var(--primary); }
.stype-btn:disabled { opacity:.35; cursor:not-allowed; }
.stype-avail { display:block; font-size:11px; font-weight:500; margin-top:3px; color:var(--secondary); }
.stype-btn.active .stype-avail { color:var(--primary); }
.add-item-btn {
    width:100%; padding:11px; margin-top:12px; background:#0ea5e9;
    color:#fff; border:none; border-radius:var(--radius); font-size:14px; font-weight:700; cursor:pointer;
}
.add-item-btn:hover { background:#0284c7; }
.form-2col { display:grid; grid-template-columns:1fr 1fr; gap:0 20px; }
@media(max-width:600px){ .form-2col { grid-template-columns:1fr; } }

.cart-panel { border:1px solid var(--gray-200); border-radius:var(--radius-lg); overflow:hidden; position:sticky; top:16px; }
.cart-header { display:flex; justify-content:space-between; align-items:center; padding:12px 16px; background:var(--gray-50); border-bottom:1px solid var(--gray-200); font-size:13px; font-weight:700; }
.cart-badge { background:var(--primary); color:#fff; font-size:11px; font-weight:700; min-width:20px; height:20px; border-radius:10px; padding:0 5px; display:inline-flex; align-items:center; justify-content:center; }
.cart-badge.zero { background:var(--gray-300); }
.cart-body { min-height:80px; max-height:380px; overflow-y:auto; }
.cart-empty { padding:28px 16px; text-align:center; font-size:13px; color:var(--secondary); line-height:1.6; }
.cart-item { display:flex; align-items:flex-start; padding:10px 14px; gap:8px; border-bottom:1px solid var(--gray-100); }
.cart-item:last-child { border-bottom:none; }
.cart-item-info { flex:1; min-width:0; }
.cart-item-name { font-size:13px; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.cart-item-sub { font-size:12px; color:var(--secondary); margin-top:2px; }
.cart-item-right { flex-shrink:0; display:flex; flex-direction:column; align-items:flex-end; gap:4px; }
.cart-item-total { font-size:13px; font-weight:700; }
.cart-rm { background:none; border:none; color:#cbd5e1; cursor:pointer; font-size:15px; padding:0; line-height:1; }
.cart-rm:hover { color:#ef4444; }
.cart-foot { display:flex; justify-content:space-between; align-items:center; padding:13px 16px; background:#eff6ff; border-top:1px solid #bfdbfe; }
.cart-foot-lbl { font-size:12px; font-weight:700; color:#1e40af; }
.cart-foot-val { font-size:20px; font-weight:800; color:#1d4ed8; }

/* ── Step 3: review ───────────────────────────────────────────────────────── */
.review-block { margin-bottom:20px; }
.review-lbl { font-size:12px; font-weight:700; color:var(--secondary); text-transform:uppercase; letter-spacing:.5px; margin-bottom:8px; }
.review-owner { background:#eff6ff; border:1px solid #bfdbfe; border-radius:var(--radius); padding:11px 16px; }
.review-owner-name { font-weight:700; color:#1e40af; font-size:14px; }
.review-owner-meta { font-size:12px; color:var(--secondary); margin-top:2px; }
.review-items { border:1px solid var(--gray-200); border-radius:var(--radius); overflow:hidden; }
.review-items-head { padding:8px 14px; background:var(--gray-50); border-bottom:1px solid var(--gray-200); font-size:11px; font-weight:700; color:var(--secondary); text-transform:uppercase; letter-spacing:.5px; }
.review-row { display:flex; justify-content:space-between; align-items:center; padding:10px 14px; border-bottom:1px solid var(--gray-100); font-size:13px; }
.review-row:last-child { border-bottom:none; }
.review-row-name { font-weight:600; }
.review-row-sub { font-size:11px; color:var(--secondary); margin-top:1px; }
.review-row-amt { font-weight:700; flex-shrink:0; margin-left:12px; }
.review-total { display:flex; justify-content:space-between; padding:12px 14px; background:#eff6ff; border-top:2px solid #bfdbfe; font-size:15px; font-weight:800; color:#1d4ed8; }

/* ── Prepaid ──────────────────────────────────────────────────────────────── */
.pay-lbl-row { font-size:12px; font-weight:700; color:var(--secondary); text-transform:uppercase; letter-spacing:.5px; margin:22px 0 10px; }
.pay-shortcuts { display:flex; flex-direction:column; gap:6px; margin-bottom:12px; }
.pay-shortcut-lbl {
    display:inline-flex; align-items:center; gap:10px; cursor:pointer;
    padding:9px 14px; border:1.5px solid var(--gray-300);
    border-radius:var(--radius); background:var(--gray-50);
    transition:border-color .15s, background .15s;
}
.pay-shortcut-lbl:has(input:checked) { border-color:var(--primary); background:#eff6ff; }
.pay-shortcut-lbl input[type="checkbox"] { width:17px; height:17px; cursor:pointer; accent-color:var(--primary); flex-shrink:0; }
.pay-shortcut-name { font-weight:700; font-size:14px; color:var(--dark); }
.pay-shortcut-desc { font-size:12px; color:var(--secondary); }
.pay-box { border:1px solid var(--gray-200); border-radius:var(--radius); overflow:hidden; }
.pay-row { display:flex; align-items:center; padding:9px 14px; gap:12px; border-bottom:1px solid var(--gray-100); }
.pay-row:last-child { border-bottom:none; }
.pay-lbl { width:52px; font-size:13px; font-weight:600; flex-shrink:0; color:var(--secondary); }
.pay-row input { flex:1; padding:7px 10px; border:1px solid var(--gray-200); border-radius:var(--radius); font-size:14px; }
.pay-row input:focus { outline:none; border-color:var(--primary); }
.pay-summary { display:flex; justify-content:space-between; align-items:center; padding:10px 14px; font-size:13px; font-weight:700; background:var(--gray-50); border-top:1px solid var(--gray-200); border-radius:0 0 var(--radius) var(--radius); }
.pay-summary.ok   { background:#ecfdf5; color:#059669; }
.pay-summary.over { background:#fef2f2; color:#dc2626; }

/* ── Toast ────────────────────────────────────────────────────────────────── */
#onToast { display:none; position:fixed; bottom:24px; right:24px; padding:12px 20px; border-radius:8px; font-size:14px; font-weight:600; z-index:9999; box-shadow:0 4px 16px rgba(0,0,0,.15); max-width:320px; }
#onToast.show { display:block; }
#onToast.ok  { background:#ecfdf5; color:#059669; border:1px solid #a7f3d0; }
#onToast.err { background:#fef2f2; color:#dc2626; border:1px solid #fca5a5; }
</style>
</head>
<body>
<div class="dashboard-container">
<?php include 'sidebar.php'; ?>
<div class="main-content">

<?php if (isset($success)): ?>
<div class="alert alert-success" style="max-width:1060px;"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>
<?php if (isset($error)): ?>
<div class="alert alert-danger" style="max-width:1060px;"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="order-wrap">
    <div class="page-hdr">
        <a href="orders.php" class="back-btn">&#8592; Orders</a>
        <h1>New Order</h1>
    </div>

    <!-- Step bar -->
    <div class="ms-bar">
        <div class="ms-step active" id="si-1">
            <div class="ms-bubble">1</div>
            <div class="ms-label">Owner</div>
        </div>
        <div class="ms-line" id="sl-1"></div>
        <div class="ms-step" id="si-2">
            <div class="ms-bubble">2</div>
            <div class="ms-label">Products</div>
        </div>
        <div class="ms-line" id="sl-2"></div>
        <div class="ms-step" id="si-3">
            <div class="ms-bubble">3</div>
            <div class="ms-label">Payment</div>
        </div>
    </div>

    <form method="POST" id="orderForm">
        <input type="hidden" name="order_owner_id" id="order_owner_id">
        <input type="hidden" name="order_items_json" id="order_items_json" value="[]">

        <!-- ════════════════════════════════════════════════════════════════
             STEP 1 — Order Owner
             ════════════════════════════════════════════════════════════════ -->
        <div class="ms-panel active" id="sp-1">

            <div class="owner-card" id="owner_card">
                <div>
                    <div class="owner-card-name" id="owner_card_name"></div>
                    <div class="owner-card-meta" id="owner_card_meta"></div>
                </div>
                <button type="button" class="owner-card-clear" onclick="clearOwner()" title="Change owner">&times;</button>
            </div>

            <div id="owner_select_area">
                <div class="ss-wrap" id="owner_ss_wrap">
                    <input type="text" id="owner_search" class="ss-input"
                           placeholder="Search existing owners…" autocomplete="off">
                    <div class="ss-drop" id="owner_ss_drop">
                        <?php if (empty($owners_arr)): ?>
                        <div class="ss-opt" style="color:var(--secondary);cursor:default;font-style:italic;">
                            No owners yet — create one below
                        </div>
                        <?php endif; ?>
                        <?php foreach ($owners_arr as $ow): ?>
                        <div class="ss-opt"
                             data-id="<?php echo $ow['id']; ?>"
                             data-name="<?php echo htmlspecialchars($ow['name'], ENT_QUOTES); ?>"
                             data-phone="<?php echo htmlspecialchars($ow['phone'], ENT_QUOTES); ?>"
                             data-location="<?php echo htmlspecialchars($ow['location'], ENT_QUOTES); ?>">
                            <strong><?php echo htmlspecialchars($ow['name']); ?></strong>
                            <?php if ($ow['phone'] || $ow['location']): ?>
                            <span class="ss-sub">
                                &nbsp;·&nbsp;<?php if ($ow['phone']) echo htmlspecialchars($ow['phone']); ?>
                                <?php if ($ow['phone'] && $ow['location']): ?>&nbsp;·&nbsp;<?php endif; ?>
                                <?php if ($ow['location']) echo htmlspecialchars($ow['location']); ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <button type="button" class="new-owner-toggle" onclick="toggleNewOwner()">
                    <span id="no_icon">＋</span> Create new owner
                </button>
                <div class="new-owner-panel" id="new_owner_panel">
                    <div style="font-size:13px;font-weight:700;margin-bottom:10px;">New Owner</div>
                    <div class="no-2col">
                        <div class="form-group">
                            <label>Name *</label>
                            <input type="text" id="no_name" placeholder="Full name">
                        </div>
                        <div class="form-group">
                            <label>Phone</label>
                            <input type="text" id="no_phone" placeholder="07XXXXXXXX">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Location</label>
                        <input type="text" id="no_location" placeholder="District, sector…">
                    </div>
                    <button type="button" id="no_save_btn" class="btn btn-primary"
                            style="padding:8px 20px;" onclick="saveNewOwner()">Save Owner</button>
                    <button type="button" class="btn" style="padding:8px 14px;margin-left:8px;"
                            onclick="toggleNewOwner()">Cancel</button>
                </div>
            </div>

            <div class="ms-nav">
                <div></div>
                <button type="button" class="ms-btn ms-next" id="step1_next" disabled onclick="goStep(2)">
                    Add Products &#8594;
                </button>
            </div>
        </div>

        <!-- ════════════════════════════════════════════════════════════════
             STEP 2 — Products
             ════════════════════════════════════════════════════════════════ -->
        <div class="ms-panel" id="sp-2">
            <div class="order-split">

                <!-- Left: product search + inputs -->
                <div>
                    <div class="sec-lbl">Add Product</div>
                    <input type="hidden" id="product_id" name="product_id">

                    <div class="ss-wrap" id="ss_wrap">
                        <input type="text" id="ss_search" class="ss-input"
                               placeholder="Search product…" autocomplete="off">
                        <div class="ss-drop" id="ss_drop"></div>
                    </div>

                    <!-- Stock type toggle (shown after product selection) -->
                    <div id="stype_wrap" style="display:none;margin-top:12px;">
                        <div class="sec-lbl" style="margin-bottom:6px;">Stock Source</div>
                        <div class="stype-toggle">
                            <button type="button" class="stype-btn" id="stype_wh" onclick="setStockType('wh')">
                                Warehouse
                                <span class="stype-avail" id="stype_wh_avail"></span>
                            </button>
                            <button type="button" class="stype-btn" id="stype_rt" onclick="setStockType('rt')">
                                Retail
                                <span class="stype-avail" id="stype_rt_avail"></span>
                            </button>
                        </div>
                    </div>

                    <div class="form-2col" style="margin-top:12px;">
                        <div class="form-group">
                            <label id="qty_label">Quantity</label>
                            <input type="number" id="qty" min="0.001" step="any" placeholder="0">
                            <small id="stock_hint" style="font-size:12px;color:var(--secondary);display:block;margin-top:3px;"></small>
                        </div>
                        <div class="form-group">
                            <label id="price_label">Price (RWF)
                                <span class="default-price-badge" onclick="useDefaultPrice()">Use default</span>
                            </label>
                            <input type="number" id="price" min="1" step="any" placeholder="0">
                        </div>
                    </div>

                    <button type="button" class="add-item-btn" onclick="addToCart()">
                        + Add to Order
                    </button>
                </div>

                <!-- Right: cart -->
                <div>
                    <div class="cart-panel">
                        <div class="cart-header">
                            Order Items
                            <span id="cart_badge" class="cart-badge zero">0</span>
                        </div>
                        <div class="cart-body" id="cart_body">
                            <div class="cart-empty">No items yet.<br>Search and add products from the left.</div>
                        </div>
                        <div class="cart-foot">
                            <span class="cart-foot-lbl">Order Total</span>
                            <span class="cart-foot-val" id="cart_total">RWF 0</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="ms-nav">
                <button type="button" class="ms-btn ms-back" onclick="goStep(1)">&#8592; Back</button>
                <button type="button" class="ms-btn ms-next" id="step2_next" disabled onclick="goStep(3)">
                    Review &amp; Payment &#8594;
                </button>
            </div>
        </div>

        <!-- ════════════════════════════════════════════════════════════════
             STEP 3 — Review & Payment
             ════════════════════════════════════════════════════════════════ -->
        <div class="ms-panel" id="sp-3">

            <!-- Owner summary -->
            <div class="review-block">
                <div class="review-lbl">Order Owner</div>
                <div class="review-owner">
                    <div class="review-owner-name" id="review_owner_name"></div>
                    <div class="review-owner-meta" id="review_owner_meta"></div>
                </div>
            </div>

            <!-- Items summary -->
            <div class="review-block">
                <div class="review-lbl">Order Items</div>
                <div class="review-items">
                    <div class="review-items-head">Products</div>
                    <div id="review_items_body"></div>
                    <div class="review-total">
                        <span>Order Total</span>
                        <span id="review_total">RWF 0</span>
                    </div>
                </div>
            </div>

            <!-- Prepaid + Note -->
            <div class="form-2col" style="align-items:start;">
                <div>
                    <div class="pay-lbl-row">Payment <span style="font-weight:400;text-transform:none;">(optional)</span></div>

                    <!-- Shortcuts -->
                    <div class="pay-shortcuts">
                        <label class="pay-shortcut-lbl">
                            <input type="checkbox" id="o_is_cash" onchange="toggleOrderShortcut('cash')">
                            <span class="pay-shortcut-name">Is Cash?</span>
                            <span class="pay-shortcut-desc">Full amount to cash</span>
                        </label>
                        <label class="pay-shortcut-lbl">
                            <input type="checkbox" id="o_is_momo" onchange="toggleOrderShortcut('momo')">
                            <span class="pay-shortcut-name">Is Momo?</span>
                            <span class="pay-shortcut-desc">Full amount to momo</span>
                        </label>
                        <label class="pay-shortcut-lbl">
                            <input type="checkbox" id="o_is_bank" onchange="toggleOrderShortcut('bank')">
                            <span class="pay-shortcut-name">Is Bank?</span>
                            <span class="pay-shortcut-desc">Full amount to bank</span>
                        </label>
                        <label class="pay-shortcut-lbl">
                            <input type="checkbox" id="o_is_loan" onchange="toggleOrderShortcut('loan')">
                            <span class="pay-shortcut-name">Is Loan?</span>
                            <span class="pay-shortcut-desc">Full amount to loan</span>
                        </label>
                    </div>

                    <div class="pay-box">
                        <div class="pay-row">
                            <span class="pay-lbl">Cash</span>
                            <input type="number" name="prepaid_cash" id="p_cash" min="0" step="any" value="0" oninput="calcPrepaid('cash')">
                        </div>
                        <div class="pay-row">
                            <span class="pay-lbl">Momo</span>
                            <input type="number" name="prepaid_momo" id="p_momo" min="0" step="any" value="0" oninput="calcPrepaid('momo')">
                        </div>
                        <div class="pay-row">
                            <span class="pay-lbl">Bank</span>
                            <input type="number" name="prepaid_bank" id="p_bank" min="0" step="any" value="0" oninput="calcPrepaid('bank')">
                        </div>
                        <div class="pay-row">
                            <span class="pay-lbl">Loan</span>
                            <input type="number" name="prepaid_loan" id="p_loan" min="0" step="any" value="0" oninput="calcPrepaid('loan')">
                        </div>
                    </div>
                    <div class="pay-summary" id="prepaid_summary_row">
                        <span>Prepaid: <strong id="prepaid_total_val">RWF 0</strong></span>
                        <span>Remaining: <strong id="prepaid_rem_val">—</strong></span>
                    </div>
                    <div id="phone_warn" style="display:none;font-size:12px;color:#dc2626;margin-top:8px;">
                        &#9888; Owner has no phone — required when prepaid includes a loan.
                    </div>
                </div>
                <div class="form-group" style="margin-top:0;">
                    <div class="pay-lbl-row">Note</div>
                    <textarea name="note" rows="6"
                        placeholder="Delivery date, special instructions…"
                        style="width:100%;padding:9px 12px;border:1px solid var(--gray-200);
                               border-radius:var(--radius);font-size:14px;resize:vertical;box-sizing:border-box;"></textarea>
                </div>
            </div>

            <div class="ms-nav">
                <button type="button" class="ms-btn ms-back" onclick="goStep(2)">&#8592; Edit Products</button>
                <button type="submit" name="add_order" id="submit_btn" class="ms-btn ms-submit" disabled>
                    &#10003; Create Order
                </button>
            </div>
        </div>

    </form>
</div><!-- /.order-wrap -->
</div>
</div>

<div id="onToast"></div>
<script src="script.js"></script>
<script>
// ── Helpers ───────────────────────────────────────────────────────────────────
function showToast(msg, ok) {
    var t = document.getElementById('onToast');
    t.textContent = msg; t.className = 'show ' + (ok ? 'ok' : 'err');
    clearTimeout(t._tid);
    t._tid = setTimeout(function(){ t.className = ''; }, 4000);
}
function escH(s){ return (s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

// ── Multi-step ────────────────────────────────────────────────────────────────
var _step = 1;

function goStep(n) {
    if (n > _step && !validateStep(_step)) return;
    _step = n;
    [1,2,3].forEach(function(i) {
        document.getElementById('si-'+i).className =
            'ms-step' + (i===_step ? ' active' : i < _step ? ' done' : '');
        document.getElementById('sp-'+i).className =
            'ms-panel' + (i===_step ? ' active' : '');
    });
    [1,2].forEach(function(i){
        document.getElementById('sl-'+i).className = 'ms-line' + (i < _step ? ' done' : '');
    });
    if (n === 3) renderReview();
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function validateStep(s) {
    if (s === 1 && !document.getElementById('order_owner_id').value) {
        showToast('Please select or create an owner first.', false); return false;
    }
    if (s === 2 && _cart.length === 0) {
        showToast('Add at least one product to continue.', false); return false;
    }
    return true;
}

// ── Owner ─────────────────────────────────────────────────────────────────────
(function(){
    var search = document.getElementById('owner_search');
    var drop   = document.getElementById('owner_ss_drop');
    var hi = -1;
    search.addEventListener('focus', function(){ drop.classList.add('open'); filter(); });
    search.addEventListener('input', function(){ drop.classList.add('open'); hi=-1; filter(); });
    search.addEventListener('keydown', function(e){
        var v = vis();
        if      (e.key==='ArrowDown'){ e.preventDefault(); hi=Math.min(hi+1,v.length-1); hl(v); }
        else if (e.key==='ArrowUp')  { e.preventDefault(); hi=Math.max(hi-1,0); hl(v); }
        else if (e.key==='Enter')    { e.preventDefault(); if(hi>=0&&v[hi]) pick(v[hi]); }
        else if (e.key==='Escape')   { drop.classList.remove('open'); }
    });
    document.addEventListener('click', function(e){
        if (!e.target.closest('#owner_ss_wrap')) drop.classList.remove('open');
    });
    drop.querySelectorAll('.ss-opt[data-id]').forEach(function(o){
        o.addEventListener('click', function(){ pick(o); });
    });
    function vis(){ return Array.from(drop.querySelectorAll('.ss-opt[data-id]:not(.hidden)')); }
    function filter(){
        var t = search.value.toLowerCase();
        drop.querySelectorAll('.ss-opt[data-id]').forEach(function(o){
            o.classList.toggle('hidden',
                o.dataset.name.toLowerCase().indexOf(t)===-1 &&
                (o.dataset.phone||'').indexOf(t)===-1 &&
                (o.dataset.location||'').toLowerCase().indexOf(t)===-1);
        });
    }
    function hl(v){
        drop.querySelectorAll('.ss-opt').forEach(function(o){ o.classList.remove('hi'); });
        if (v[hi]) { v[hi].classList.add('hi'); v[hi].scrollIntoView({block:'nearest'}); }
    }
    function pick(o){ selectOwner(o.dataset.id, o.dataset.name, o.dataset.phone||'', o.dataset.location||''); }
})();

var _ownerPhone = '', _ownerName = '', _ownerMeta = '';

function selectOwner(id, name, phone, location) {
    _ownerPhone = phone; _ownerName = name;
    var meta = [];
    if (phone)    meta.push('\u{1F4DE} ' + phone);
    if (location) meta.push('\u{1F4CD} ' + location);
    _ownerMeta = meta.join('   ');
    document.getElementById('order_owner_id').value      = id;
    document.getElementById('owner_card_name').textContent = name;
    document.getElementById('owner_card_meta').textContent = _ownerMeta;
    document.getElementById('owner_card').classList.add('show');
    document.getElementById('owner_select_area').style.display = 'none';
    document.getElementById('new_owner_panel').classList.remove('open');
    document.getElementById('no_icon').textContent = '＋';
    document.getElementById('step1_next').disabled = false;
    calcPrepaid();
}

function clearOwner() {
    _ownerPhone = ''; _ownerName = ''; _ownerMeta = '';
    document.getElementById('order_owner_id').value = '';
    document.getElementById('owner_card').classList.remove('show');
    document.getElementById('owner_select_area').style.display = '';
    document.getElementById('owner_search').value = '';
    document.getElementById('step1_next').disabled = true;
}

function toggleNewOwner() {
    var panel = document.getElementById('new_owner_panel');
    var open  = panel.classList.toggle('open');
    document.getElementById('no_icon').textContent = open ? '−' : '＋';
    if (open) document.getElementById('no_name').focus();
}

function saveNewOwner() {
    var name = document.getElementById('no_name').value.trim();
    var phone= document.getElementById('no_phone').value.trim();
    var loc  = document.getElementById('no_location').value.trim();
    if (!name) { showToast('Name is required.', false); return; }
    var btn = document.getElementById('no_save_btn');
    btn.disabled = true; btn.textContent = 'Saving…';
    var fd = new FormData();
    fd.append('add_owner','1'); fd.append('owner_name',name);
    fd.append('owner_phone',phone); fd.append('owner_location',loc);
    fetch('order_new.php',{method:'POST',body:fd})
        .then(function(r){ return r.json(); })
        .then(function(res){
            if (res.success) {
                var drop = document.getElementById('owner_ss_drop');
                var opt  = document.createElement('div');
                opt.className='ss-opt';
                opt.dataset.id=res.id; opt.dataset.name=res.name;
                opt.dataset.phone=res.phone||''; opt.dataset.location=res.location||'';
                opt.innerHTML='<strong>'+escH(res.name)+'</strong>';
                opt.addEventListener('click',function(){
                    selectOwner(opt.dataset.id,opt.dataset.name,opt.dataset.phone,opt.dataset.location);
                });
                drop.appendChild(opt);
                document.getElementById('no_name').value='';
                document.getElementById('no_phone').value='';
                document.getElementById('no_location').value='';
                selectOwner(res.id,res.name,res.phone||'',res.location||'');
                showToast('Owner "'+res.name+'" created.', true);
            } else { showToast(res.message, false); }
            btn.disabled=false; btn.textContent='Save Owner';
        })
        .catch(function(){ showToast('Network error.',false); btn.disabled=false; btn.textContent='Save Owner'; });
}

// ── Product search ────────────────────────────────────────────────────────────
var _selectedProd = null;

(function(){
    var search  = document.getElementById('ss_search');
    var drop    = document.getElementById('ss_drop');
    var hidden  = document.getElementById('product_id');
    var hi = -1, _timer = null, _last = '';

    function showLoading() {
        drop.innerHTML = '<div class="sd-loading"><span class="sd-spinner"></span>Loading…</div>';
        drop.classList.add('open');
    }
    function renderOptions(rows) {
        hi = -1;
        if (!rows.length) {
            drop.innerHTML = '<div class="sd-loading">No results found.</div>';
            return;
        }
        drop.innerHTML = '';
        rows.forEach(function(p) {
            var label = p.category + '-' + p.name;
            var parts = [];
            if (parseInt(p.stock_qty) > 0) parts.push(Number(p.stock_qty).toLocaleString() + ' pkgs WH');
            if (parseInt(p.rt_qty)    > 0) parts.push(Number(p.rt_qty).toLocaleString()    + ' pcs RT');
            var o = document.createElement('div');
            o.className = 'ss-opt';
            o.dataset.id      = p.id;
            o.dataset.name    = label;
            o.dataset.price   = p.default_price;
            o.dataset.stock   = p.stock_qty;
            o.dataset.ppp     = p.ppp;
            o.dataset.rtStock = p.rt_qty;
            o.dataset.rtPrice = p.rt_price;
            o.innerHTML = esc(label) + (parts.length ? '<span class="ss-sub"> &middot; ' + parts.join(' &middot; ') + '</span>' : '');
            o.addEventListener('click', function(){ pick(o); });
            drop.appendChild(o);
        });
    }
    function esc(s) { return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
    function doSearch(q) {
        showLoading();
        var xhr = new XMLHttpRequest();
        xhr.open('GET', '?action=search_products&q=' + encodeURIComponent(q));
        xhr.onload = function() {
            if (xhr.status === 200) {
                try { renderOptions(JSON.parse(xhr.responseText)); }
                catch(e) { drop.innerHTML = '<div class="sd-loading">Failed to load.</div>'; }
            } else { drop.innerHTML = '<div class="sd-loading">Failed to load.</div>'; }
        };
        xhr.onerror = function(){ drop.innerHTML = '<div class="sd-loading">Failed to load.</div>'; };
        xhr.send();
    }
    function vis(){ return Array.from(drop.querySelectorAll('.ss-opt')); }
    function hl(v){
        drop.querySelectorAll('.ss-opt').forEach(function(o){ o.classList.remove('hi'); });
        if (v[hi]) { v[hi].classList.add('hi'); v[hi].scrollIntoView({block:'nearest'}); }
    }
    function pick(opt){
        search.value = opt.dataset.name;
        hidden.value = opt.dataset.id;
        _selectedProd = {
            id:      opt.dataset.id,
            name:    opt.dataset.name,
            price:   parseFloat(opt.dataset.price)   || 0,
            stock:   parseInt(opt.dataset.stock)      || 0,
            ppp:     parseInt(opt.dataset.ppp)        || 1,
            rtStock: parseInt(opt.dataset.rtStock)    || 0,
            rtPrice: parseFloat(opt.dataset.rtPrice)  || 0
        };
        drop.classList.remove('open'); hi = -1;
        onProductChange();
    }
    search.addEventListener('focus', function(){ if (!drop.classList.contains('open')) { doSearch(search.value); } });
    search.addEventListener('input', function(){
        var q = search.value;
        _selectedProd = null; hidden.value = '';
        drop.classList.add('open'); hi = -1;
        clearTimeout(_timer);
        _timer = setTimeout(function(){ doSearch(q); }, 250);
    });
    search.addEventListener('keydown', function(e){
        var v = vis();
        if      (e.key==='ArrowDown'){ e.preventDefault(); hi=Math.min(hi+1,v.length-1); hl(v); }
        else if (e.key==='ArrowUp')  { e.preventDefault(); hi=Math.max(hi-1,0); hl(v); }
        else if (e.key==='Enter')    { e.preventDefault(); if(hi>=0&&v[hi]) pick(v[hi]); }
        else if (e.key==='Escape')   { drop.classList.remove('open'); }
    });
    document.addEventListener('click', function(e){
        if (!e.target.closest('#ss_wrap')) drop.classList.remove('open');
    });
})();

var _stockType = 'wh';

function onProductChange() {
    var hint = document.getElementById('stock_hint');
    var stypeWrap = document.getElementById('stype_wrap');
    if (!_selectedProd) {
        hint.textContent = '';
        stypeWrap.style.display = 'none';
        return;
    }

    var whQty  = _selectedProd.stock;
    var rtQty  = _selectedProd.rtStock;
    var whPr   = _selectedProd.price;
    var rtPr   = _selectedProd.rtPrice;

    // show/enable toggle buttons
    stypeWrap.style.display = 'block';
    var whBtn = document.getElementById('stype_wh');
    var rtBtn = document.getElementById('stype_rt');
    whBtn.disabled = whQty === 0;
    rtBtn.disabled = rtQty === 0;
    document.getElementById('stype_wh_avail').textContent = whQty.toLocaleString() + ' pkgs';
    document.getElementById('stype_rt_avail').textContent = rtQty.toLocaleString() + ' pcs';

    // auto-select a valid type
    if (_stockType === 'wh' && whQty === 0 && rtQty > 0) _stockType = 'rt';
    if (_stockType === 'rt' && rtQty === 0 && whQty > 0) _stockType = 'wh';

    setStockType(_stockType, true);
}

function setStockType(type, skipPriceClear) {
    _stockType = type;
    document.getElementById('stype_wh').classList.toggle('active', type === 'wh');
    document.getElementById('stype_rt').classList.toggle('active', type === 'rt');

    if (!_selectedProd) return;

    var whQty = _selectedProd.stock;
    var rtQty = _selectedProd.rtStock;
    var whPr  = _selectedProd.price;
    var rtPr  = _selectedProd.rtPrice;

    var hint = document.getElementById('stock_hint');
    var qtyLbl = document.getElementById('qty_label');
    var priceLbl = document.getElementById('price_label');
    var priceEl = document.getElementById('price');

    var qtyEl = document.getElementById('qty');
    if (type === 'wh') {
        hint.textContent  = 'Available: ' + whQty.toLocaleString() + ' packages';
        qtyLbl.innerHTML  = 'Quantity <small style="color:var(--secondary)">(packages)</small>';
        priceLbl.innerHTML = 'Price / package (RWF) <span class="default-price-badge" onclick="useDefaultPrice()">Use default</span>';
        if (!skipPriceClear || !priceEl.value) priceEl.value = whPr > 0 ? whPr : '';
        qtyEl.max = whQty;
    } else {
        hint.textContent  = 'Available: ' + rtQty.toLocaleString() + ' pieces';
        qtyLbl.innerHTML  = 'Quantity <small style="color:var(--secondary)">(pieces)</small>';
        priceLbl.innerHTML = 'Price / piece (RWF) <span class="default-price-badge" onclick="useDefaultPrice()">Use default</span>';
        if (!skipPriceClear || !priceEl.value) priceEl.value = rtPr > 0 ? rtPr : '';
        qtyEl.max = rtQty;
    }
    // clamp current value if it exceeds the new max
    var curQty = parseFloat(qtyEl.value) || 0;
    var maxQty = type === 'wh' ? whQty : rtQty;
    if (curQty > maxQty) qtyEl.value = maxQty;
}

function useDefaultPrice() {
    if (!_selectedProd) return;
    var pr = _stockType === 'wh' ? _selectedProd.price : _selectedProd.rtPrice;
    if (pr > 0) document.getElementById('price').value = pr;
}

// ── Cart ──────────────────────────────────────────────────────────────────────
var _cart = [];

function addToCart() {
    var pid   = _selectedProd ? _selectedProd.id : '';
    var pname = document.getElementById('ss_search').value.trim();
    var qty   = parseFloat(document.getElementById('qty').value) || 0;
    var price = parseFloat(document.getElementById('price').value) || 0;
    var ppp   = _selectedProd ? (_selectedProd.ppp || 1) : 1;
    var type  = _stockType || 'wh';
    var unit  = type === 'wh' ? 'pkg' : 'pcs';

    var maxQty = parseFloat(document.getElementById('qty').max) || 0;
    if (!pid)           { showToast('Select a product first.', false); return; }
    if (qty <= 0)       { showToast('Enter a valid quantity.', false); return; }
    if (maxQty > 0 && qty > maxQty) {
        showToast('Only ' + maxQty.toLocaleString() + ' ' + unit + '(s) available in stock.', false);
        document.getElementById('qty').value = maxQty;
        return;
    }
    if (price<=0){ showToast('Enter a valid price.', false); return; }

    var existing = _cart.find(function(i){ return i.pid===pid && i.type===type; });
    if (existing) {
        existing.qty  += qty;
        showToast('Updated: '+pname+' → '+existing.qty.toLocaleString()+' '+unit+'(s)', true);
    } else {
        _cart.push({ pid:pid, name:pname, qty:qty, price:price, ppp:ppp, type:type, unit:unit });
        showToast('"'+pname+'" added ('+unit+').', true);
    }
    renderCart();
    document.getElementById('ss_search').value = '';
    document.getElementById('product_id').value = '';
    _selectedProd = null;
    var qtyEl2 = document.getElementById('qty');
    qtyEl2.value = ''; qtyEl2.max = '';
    document.getElementById('price').value = '';
    document.getElementById('stock_hint').textContent = '';
    document.getElementById('stype_wrap').style.display = 'none';
    document.getElementById('qty_label').innerHTML = 'Quantity';
    document.getElementById('price_label').innerHTML = 'Price (RWF) <span class="default-price-badge" onclick="useDefaultPrice()">Use default</span>';
    _stockType = 'wh';
}

function removeFromCart(pid, type) {
    _cart = _cart.filter(function(i){ return !(i.pid===pid && i.type===type); });
    renderCart();
}

function renderCart() {
    var total = _cart.reduce(function(s,i){ return s+i.qty*i.price; }, 0);
    var badge = document.getElementById('cart_badge');
    badge.textContent = _cart.length;
    badge.className   = 'cart-badge'+(_cart.length===0?' zero':'');
    document.getElementById('cart_total').textContent = 'RWF '+Math.round(total).toLocaleString();

    var body = document.getElementById('cart_body');
    if (_cart.length===0) {
        body.innerHTML = '<div class="cart-empty">No items yet.<br>Search and add products from the left.</div>';
    } else {
        body.innerHTML = _cart.map(function(item){
            var sub    = item.qty * item.price;
            var unit   = item.unit || 'pkg';
            var badge  = item.type === 'rt'
                ? '<span style="font-size:10px;background:#fef3c7;color:#854d0e;border:1px solid #fde68a;border-radius:4px;padding:1px 5px;margin-left:4px;font-weight:600;">RT</span>'
                : '<span style="font-size:10px;background:#dbeafe;color:#1e40af;border:1px solid #93c5fd;border-radius:4px;padding:1px 5px;margin-left:4px;font-weight:600;">WH</span>';
            return '<div class="cart-item">'+
                '<div class="cart-item-info">'+
                    '<div class="cart-item-name">'+escH(item.name)+badge+'</div>'+
                    '<div class="cart-item-sub">'+item.qty.toLocaleString()+' '+unit+' &times; RWF '+item.price.toLocaleString()+'</div>'+
                '</div>'+
                '<div class="cart-item-right">'+
                    '<span class="cart-item-total">RWF '+Math.round(sub).toLocaleString()+'</span>'+
                    '<button type="button" class="cart-rm" onclick="removeFromCart('+JSON.stringify(item.pid)+','+JSON.stringify(item.type)+')" title="Remove">&times;</button>'+
                '</div>'+
            '</div>';
        }).join('');
    }

    document.getElementById('order_items_json').value = JSON.stringify(
        _cart.map(function(i){
            var divisor = i.type === 'rt' ? 1 : (i.ppp || 1);
            return { product_id:i.pid, quantity:i.qty, level_divisor:divisor, stock_type:i.type, selling_price:i.price, item_total:i.qty*i.price };
        })
    );
    document.getElementById('step2_next').disabled = _cart.length===0;
    calcPrepaid();
}

// ── Step 3 review render ──────────────────────────────────────────────────────
function renderReview() {
    document.getElementById('review_owner_name').textContent = _ownerName;
    document.getElementById('review_owner_meta').textContent = _ownerMeta;
    var total = _cart.reduce(function(s,i){ return s+i.qty*i.price; }, 0);
    document.getElementById('review_items_body').innerHTML = _cart.map(function(item){
        var unit  = item.unit || 'pkg';
        var badge = item.type === 'rt'
            ? '<span style="font-size:10px;background:#fef3c7;color:#854d0e;border:1px solid #fde68a;border-radius:4px;padding:1px 5px;margin-left:4px;font-weight:600;">RT</span>'
            : '<span style="font-size:10px;background:#dbeafe;color:#1e40af;border:1px solid #93c5fd;border-radius:4px;padding:1px 5px;margin-left:4px;font-weight:600;">WH</span>';
        return '<div class="review-row">'+
            '<div><div class="review-row-name">'+escH(item.name)+badge+'</div>'+
            '<div class="review-row-sub">'+item.qty.toLocaleString()+' '+unit+' &times; RWF '+item.price.toLocaleString()+'</div></div>'+
            '<div class="review-row-amt">RWF '+Math.round(item.qty*item.price).toLocaleString()+'</div>'+
        '</div>';
    }).join('');
    document.getElementById('review_total').textContent = 'RWF '+Math.round(total).toLocaleString();
    applyOrderShortcut();
    calcPrepaid();
}

// ── Payment shortcuts ─────────────────────────────────────────────────────────
function applyOrderShortcut() {
    var isCash = document.getElementById('o_is_cash').checked;
    var isMomo = document.getElementById('o_is_momo').checked;
    var isBank = document.getElementById('o_is_bank').checked;
    var isLoan = document.getElementById('o_is_loan').checked;
    if (!isCash && !isMomo && !isBank && !isLoan) return;
    var total = Math.round(_cart.reduce(function(s,i){ return s+i.qty*i.price; }, 0));
    document.getElementById('p_cash').value = isCash ? total : 0;
    document.getElementById('p_momo').value = isMomo ? total : 0;
    document.getElementById('p_bank').value = isBank ? total : 0;
    document.getElementById('p_loan').value = isLoan ? total : 0;
}

function toggleOrderShortcut(type) {
    if (type !== 'cash') document.getElementById('o_is_cash').checked = false;
    if (type !== 'momo') document.getElementById('o_is_momo').checked = false;
    if (type !== 'bank') document.getElementById('o_is_bank').checked = false;
    if (type !== 'loan') document.getElementById('o_is_loan').checked = false;
    applyOrderShortcut();
    calcPrepaid();
}

// ── Prepaid ───────────────────────────────────────────────────────────────────
function calcPrepaid(changed) {
    // when user edits a field manually, uncheck any active shortcut
    if (changed) {
        document.getElementById('o_is_cash').checked = false;
        document.getElementById('o_is_momo').checked = false;
        document.getElementById('o_is_bank').checked = false;
        document.getElementById('o_is_loan').checked = false;
    }

    var total = _cart.reduce(function(s,i){ return s+i.qty*i.price; }, 0);
    var cashEl = document.getElementById('p_cash');
    var momoEl = document.getElementById('p_momo');
    var bankEl = document.getElementById('p_bank');
    var loanEl = document.getElementById('p_loan');
    var cash = parseFloat(cashEl.value)||0;
    var momo = parseFloat(momoEl.value)||0;
    var bank = parseFloat(bankEl.value)||0;
    var loan = parseFloat(loanEl.value)||0;

    // auto-distribute remainder into the next field in the chain
    if (changed === 'cash') { momo = Math.max(0, total-cash-bank-loan); momoEl.value = momo; }
    else if (changed === 'momo') { bank = Math.max(0, total-cash-momo-loan); bankEl.value = bank; }
    else if (changed === 'bank') { loan = Math.max(0, total-cash-momo-bank); loanEl.value = loan; }
    else if (changed === 'loan') { momo = Math.max(0, total-cash-loan-bank); momoEl.value = momo; }

    var prep  = cash+momo+bank+loan;
    var rem   = total-prep;

    document.getElementById('prepaid_total_val').textContent = 'RWF '+prep.toLocaleString();
    var remEl = document.getElementById('prepaid_rem_val');
    if (total>0) {
        remEl.textContent = 'RWF '+Math.max(0,rem).toLocaleString();
        remEl.style.color = rem<=0 ? '#059669' : '#854d0e';
    } else { remEl.textContent='—'; remEl.style.color=''; }
    document.getElementById('prepaid_summary_row').className =
        'pay-summary'+(prep>0&&rem<=0?' ok':prep>total+1?' over':'');

    var oid  = document.getElementById('order_owner_id').value;
    document.getElementById('phone_warn').style.display =
        (loan>0 && !_ownerPhone && oid) ? '' : 'none';
    checkSubmit();
}

function checkSubmit() {
    var ownerOk = !!document.getElementById('order_owner_id').value;
    var itemsOk = _cart.length>0;
    var total   = _cart.reduce(function(s,i){ return s+i.qty*i.price; }, 0);
    var loan    = parseFloat(document.getElementById('p_loan').value)||0;
    var prep    = (parseFloat(document.getElementById('p_cash').value)||0)
                + (parseFloat(document.getElementById('p_momo').value)||0)
                + (parseFloat(document.getElementById('p_bank').value)||0) + loan;
    var prepOk  = prep <= total+1;
    var loanOk  = !(loan>0 && !_ownerPhone && ownerOk);
    document.getElementById('submit_btn').disabled = !(ownerOk&&itemsOk&&total>0&&prepOk&&loanOk);
}
</script>
</body>
</html>
