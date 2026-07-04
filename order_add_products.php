<?php
require_once 'config.php';
if (!isLoggedIn()) redirect('login.php');
if (!hasPermission('orders')) { $_SESSION['flash_error'] = "You don't have permission to access Orders."; redirect('dashboard.php'); }

global $conn;
$user_id = (int)$_SESSION['user_id'];

// ── Load order ────────────────────────────────────────────────────────────────
$order_id = (int)($_GET['order_id'] ?? $_POST['order_id'] ?? 0);
if (!$order_id) { $_SESSION['flash_error'] = 'No order specified.'; redirect('orders.php'); }

$order = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT o.*, oo.location AS owner_location
     FROM `orders` o
     LEFT JOIN order_owners oo ON o.order_owner_id = oo.id
     WHERE o.id=$order_id AND o.status IN ('pending','open')" . cidAndFor('o')));

if (!$order) {
    $_SESSION['flash_error'] = 'Order not found or not editable.';
    redirect('orders.php');
}

// ── Fetch existing items ───────────────────────────────────────────────────────
$existing_items = [];
$ei_res = mysqli_query($conn,
    "SELECT oi.*, p.name AS product_name, p.category, ab.username AS added_by_name
     FROM order_items oi
     LEFT JOIN products p ON oi.product_id=p.id
     LEFT JOIN users ab   ON oi.added_by=ab.id
     WHERE oi.order_id=$order_id ORDER BY oi.id");
while ($ei = mysqli_fetch_assoc($ei_res)) {
    if ($ei['product_name'] === null && $ei['custom_name']) $ei['product_name'] = $ei['custom_name'];
    $existing_items[] = $ei;
}

// ── AJAX: edit an existing item's price / availability ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_item'])) {
    header('Content-Type: application/json');
    $item_id   = (int)($_POST['item_id'] ?? 0);
    $price     = max(0, (float)($_POST['selling_price'] ?? 0));
    $available = ($_POST['available'] ?? '1') === '1';

    $item = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM order_items WHERE id=$item_id AND order_id=$order_id"));
    if (!$item) { echo json_encode(['success'=>false,'message'=>'Item not found.']); exit; }

    $qty        = (float)$item['quantity'];
    $old_total  = (float)$item['item_total'];
    $new_total  = round($qty * $price, 2);
    $new_status = $available ? 'pending' : 'out_of_stock';
    $delta      = round($new_total - $old_total, 2);

    mysqli_query($conn, "UPDATE order_items SET selling_price=$price, item_total=$new_total, status='$new_status' WHERE id=$item_id");
    mysqli_query($conn, "UPDATE `orders` SET total_amount = total_amount + ($delta), updated_at=NOW() WHERE id=$order_id");

    logActivity($conn, $user_id, 'UPDATE',
        "Order item #$item_id on order #$order_id: price set to $price, marked " . ($available ? 'available' : 'unavailable'),
        'orders', $order_id, ['item_total'=>$old_total], ['item_total'=>$new_total]);

    echo json_encode(['success'=>true,'message'=>'Item updated.',
        'new_item_total'=>$new_total, 'new_order_total'=>round((float)$order['total_amount'] + $delta, 2)]);
    exit;
}

// ── POST: add items ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_items'])) {
    $items_raw = $_POST['order_items_json'] ?? '[]';
    $items     = json_decode($items_raw, true) ?: [];

    if (empty($items)) {
        $_SESSION['flash_error'] = 'Add at least one product before saving.';
        redirect("order_add_products.php?order_id=$order_id");
    }

    $added_total = 0.0;
    $added_names = [];
    foreach ($items as $it) {
        if (!empty($it['custom'])) {
            $cname = trim((string)($it['name'] ?? ''));
            $qty   = (float)($it['quantity'] ?? 0);
            if ($cname === '' || $qty <= 0) continue;
            $ce = mysqli_real_escape_string($conn, $cname);
            mysqli_query($conn, "INSERT INTO order_items
                (order_id,product_id,custom_name,stock_source,quantity,level_divisor,selling_price,item_total,source,added_by)
                VALUES($order_id,NULL,'$ce','custom',$qty,1,0,0,'staff',$user_id)");
            $added_names[] = $cname . ' (custom)';
            continue;
        }

        $pid  = (int)$it['product_id'];
        $qty  = (float)$it['quantity'];
        $div  = max(1, (int)($it['level_divisor'] ?? 1));
        $prce = (float)$it['selling_price'];
        $src  = in_array($it['stock_type'] ?? 'wh', ['wh','rt']) ? $it['stock_type'] : 'wh';
        $itot = round($qty * $prce, 2);

        if ($pid <= 0 || $qty <= 0 || $prce <= 0) continue;

        $prow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT name FROM products WHERE id=$pid AND deleted=0"));
        if (!$prow) continue;

        mysqli_query($conn, "INSERT INTO order_items
            (order_id,product_id,stock_source,quantity,level_divisor,selling_price,item_total,source,added_by)
            VALUES($order_id,$pid,'$src',$qty,$div,$prce,$itot,'staff',$user_id)");

        $added_total += $itot;
        $added_names[] = $prow['name'];
    }

    if ($added_total > 0) {
        mysqli_query($conn, "UPDATE `orders` SET total_amount=total_amount+$added_total, updated_at=NOW() WHERE id=$order_id");
        logActivity($conn, $user_id, 'UPDATE', 'orders',
            count($added_names).' product(s) added to order #'.$order_id.': '.implode(', ',$added_names),
            $order_id,
            ['total_amount'=>$order['total_amount']],
            ['total_amount'=>(float)$order['total_amount']+$added_total]);
    }

    $order_num = $order['order_number'] ?: "#$order_id";
    $_SESSION['flash_success'] = count($added_names).' product(s) added to '.$order_num.'.';
    redirect('orders.php');
}

$order_num = $order['order_number'] ?: '#'.$order_id;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Add Products — <?php echo htmlspecialchars($order_num); ?></title>
<link rel="stylesheet" href="css/style.css">
<link rel="stylesheet" href="css/sales.css">
<style>
.page-wrap { max-width:1060px; }
.page-hdr  { display:flex; align-items:center; gap:16px; margin-bottom:24px; }
.page-hdr h1 { margin:0; font-size:22px; }
.back-btn {
    display:inline-flex; align-items:center; gap:6px; padding:7px 14px;
    border-radius:var(--radius); background:var(--gray-100); color:var(--dark);
    text-decoration:none; font-size:13px; font-weight:500; border:1px solid var(--gray-300);
}
.back-btn:hover { background:var(--gray-200); }

/* Order summary card */
.order-card {
    background:var(--white); border:1px solid var(--gray-200);
    border-radius:var(--radius-lg); padding:18px 22px; margin-bottom:24px;
}
.order-card-head { display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:8px; margin-bottom:14px; }
.order-card-num  { font-size:16px; font-weight:800; color:var(--primary); }
.order-card-owner { font-size:13px; color:var(--secondary); margin-top:2px; }
.order-card-total { font-size:18px; font-weight:800; color:var(--dark); white-space:nowrap; }
/* Existing items as cart list */
.ei-list { border:1px solid var(--gray-200); border-radius:var(--radius); overflow:hidden; }
.ei-item { display:flex; align-items:center; padding:9px 14px; gap:10px; border-bottom:1px solid var(--gray-100); font-size:13px; flex-wrap:wrap; }
.ei-item:last-child { border-bottom:none; }
.ei-name  { flex:1; font-weight:600; min-width:160px; }
.ei-meta  { font-size:12px; color:var(--secondary); white-space:nowrap; }
.ei-amt   { font-weight:700; white-space:nowrap; min-width:90px; text-align:right; }

.ei-edit-row { display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-left:auto; }
.ei-price-input { width:100px; padding:6px 8px; border:1px solid var(--gray-300); border-radius:var(--radius); font-size:13px; }
.ei-price-input:focus { outline:none; border-color:var(--primary); }
.ei-avail-toggle { display:flex; border:1px solid var(--gray-300); border-radius:var(--radius); overflow:hidden; }
.ei-avail-btn { padding:6px 10px; font-size:12px; font-weight:600; border:none; background:var(--white); color:var(--secondary); cursor:pointer; }
.ei-avail-btn.on-yes.active { background:#dcfce7; color:#166534; }
.ei-avail-btn.on-no.active  { background:#fee2e2; color:#991b1b; }
.ei-save-btn { padding:6px 12px; font-size:12px; font-weight:700; border:none; border-radius:var(--radius); background:var(--primary); color:#fff; cursor:pointer; }
.ei-save-btn:hover { background:var(--primary-dark); }
.ei-save-btn:disabled { opacity:.5; cursor:default; }
.ei-item.unavailable { background:#fef2f2; }

/* Product + cart split */
.add-split { display:grid; grid-template-columns:1fr 320px; gap:24px; align-items:start; margin-bottom:24px; }
@media(max-width:820px){ .add-split { grid-template-columns:1fr; } }

.sec-lbl { font-size:12px; font-weight:700; color:var(--secondary); text-transform:uppercase; letter-spacing:.5px; margin-bottom:10px; }

/* Product search */
.ss-wrap { position:relative; }
.ss-input {
    width:100%; padding:10px 12px; border:1px solid var(--gray-300);
    border-radius:var(--radius); font-size:14px; background:var(--white); box-sizing:border-box;
}
.ss-input:focus { outline:none; border-color:var(--primary); box-shadow:0 0 0 3px rgba(37,99,235,.12); }
.ss-drop {
    display:none; position:absolute; top:100%; left:0; right:0;
    max-height:240px; overflow-y:auto; background:var(--white);
    border:1px solid var(--gray-300); border-top:none;
    border-radius:0 0 var(--radius) var(--radius); z-index:1000; box-shadow:var(--shadow-md);
}
.ss-drop.open { display:block; }
.ss-opt { padding:9px 12px; cursor:pointer; font-size:13px; }
.ss-opt:hover, .ss-opt.hi { background:var(--gray-100); color:var(--primary); }
.ss-sub { font-size:11px; color:var(--secondary); }
.sd-loading { display:flex; align-items:center; gap:8px; padding:10px 12px; font-size:13px; color:var(--secondary); }
.sd-spinner { width:14px; height:14px; border:2px solid var(--gray-300); border-top-color:var(--primary); border-radius:50%; animation:sd-spin .7s linear infinite; flex-shrink:0; }
@keyframes sd-spin { to { transform:rotate(360deg); } }

/* Stock type toggle */
.stype-toggle { display:flex; gap:8px; margin-top:12px; margin-bottom:12px; }
.stype-btn {
    flex:1; padding:9px 12px; border:2px solid var(--gray-200);
    border-radius:var(--radius); background:#fff; cursor:pointer;
    font-size:13px; font-weight:600; color:var(--secondary); transition:.15s; text-align:center; line-height:1.3;
}
.stype-btn:hover:not(.active):not(:disabled) { border-color:var(--gray-300); background:var(--gray-100); }
.stype-btn.active  { border-color:var(--primary); background:#eff6ff; color:var(--primary); }
.stype-btn:disabled { opacity:.35; cursor:not-allowed; }
.stype-avail { display:block; font-size:11px; font-weight:500; margin-top:3px; color:var(--secondary); }
.stype-btn.active .stype-avail { color:var(--primary); }

.form-2col { display:grid; grid-template-columns:1fr 1fr; gap:0 20px; }
@media(max-width:600px){ .form-2col { grid-template-columns:1fr; } }

.default-price-badge {
    font-size:11px; background:var(--gray-100); color:var(--secondary);
    border:1px solid var(--gray-300); border-radius:4px; padding:3px 8px;
    cursor:pointer; white-space:nowrap; margin-left:6px;
}
.default-price-badge:hover { background:var(--primary); color:#fff; border-color:var(--primary); }

.add-item-btn {
    width:100%; padding:11px; margin-top:12px; background:#0ea5e9;
    color:#fff; border:none; border-radius:var(--radius); font-size:14px; font-weight:700; cursor:pointer;
}
.add-item-btn:hover { background:#0284c7; }

/* Cart */
.cart-panel { border:1px solid var(--gray-200); border-radius:var(--radius-lg); overflow:hidden; position:sticky; top:16px; }
.cart-header { display:flex; justify-content:space-between; align-items:center; padding:12px 16px; background:var(--gray-50); border-bottom:1px solid var(--gray-200); font-size:13px; font-weight:700; }
.cart-badge  { background:var(--primary); color:#fff; font-size:11px; font-weight:700; min-width:20px; height:20px; border-radius:10px; padding:0 5px; display:inline-flex; align-items:center; justify-content:center; }
.cart-badge.zero { background:var(--gray-300); }
.cart-body   { min-height:80px; max-height:360px; overflow-y:auto; }
.cart-empty  { padding:28px 16px; text-align:center; font-size:13px; color:var(--secondary); line-height:1.6; }
.cart-item   { display:flex; align-items:flex-start; padding:10px 14px; gap:8px; border-bottom:1px solid var(--gray-100); }
.cart-item:last-child { border-bottom:none; }
.cart-item-info  { flex:1; min-width:0; }
.cart-item-name  { font-size:13px; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.cart-item-sub   { font-size:12px; color:var(--secondary); margin-top:2px; }
.cart-item-right { flex-shrink:0; display:flex; flex-direction:column; align-items:flex-end; gap:4px; }
.cart-item-total { font-size:13px; font-weight:700; }
.cart-rm  { background:none; border:none; color:#cbd5e1; cursor:pointer; font-size:15px; padding:0; line-height:1; }
.cart-rm:hover { color:#ef4444; }
.cart-foot { display:flex; justify-content:space-between; align-items:center; padding:13px 16px; background:#eff6ff; border-top:1px solid #bfdbfe; }
.cart-foot-lbl { font-size:12px; font-weight:700; color:#1e40af; }
.cart-foot-val { font-size:20px; font-weight:800; color:#1d4ed8; }

/* Submit bar */
.submit-bar {
    background:var(--white); border:1px solid var(--gray-200); border-radius:var(--radius-lg);
    padding:16px 22px; display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap;
}
.new-total-preview { font-size:13px; color:var(--secondary); }
.new-total-preview strong { color:var(--dark); font-size:15px; }

#addToast { display:none; position:fixed; bottom:24px; right:24px; padding:12px 20px;
            border-radius:8px; font-size:14px; font-weight:600; z-index:9999;
            box-shadow:0 4px 16px rgba(0,0,0,.15); max-width:320px; }
#addToast.show { display:block; }
#addToast.ok  { background:#ecfdf5; color:#059669; border:1px solid #a7f3d0; }
#addToast.err { background:#fef2f2; color:#dc2626; border:1px solid #fca5a5; }
</style>
</head>
<body>
<div class="dashboard-container">
<?php include 'sidebar.php'; ?>
<div class="main-content">
<div class="page-wrap">

<div class="page-hdr">
    <a href="orders.php" class="back-btn">&#8592; Orders</a>
    <div>
        <h1>Add Products</h1>
        <p style="margin:2px 0 0;font-size:13px;color:var(--secondary);">
            <?php echo htmlspecialchars($order_num); ?> &mdash; <?php echo htmlspecialchars($order['order_owner']); ?>
        </p>
    </div>
</div>

<!-- Order summary -->
<div class="order-card">
    <div class="order-card-head">
        <div>
            <div class="order-card-num"><?php echo htmlspecialchars($order_num); ?></div>
            <div class="order-card-owner">
                <?php echo htmlspecialchars($order['order_owner']); ?>
                <?php if ($order['phone']): ?> &middot; <?php echo htmlspecialchars($order['phone']); ?><?php endif; ?>
                <?php if (!empty($order['owner_location'])): ?> &middot; <?php echo htmlspecialchars($order['owner_location']); ?><?php endif; ?>
            </div>
        </div>
        <div style="text-align:right;">
            <div class="order-card-total">RWF <?php echo number_format((float)$order['total_amount'],0); ?></div>
            <div style="font-size:12px;color:var(--secondary);margin-top:2px;">Current total</div>
        </div>
    </div>
</div>

<!-- Add products form -->
<form method="POST" id="addForm">
    <input type="hidden" name="add_items" value="1">
    <input type="hidden" name="order_id"  value="<?php echo $order_id; ?>">
    <input type="hidden" name="order_items_json" id="order_items_json" value="[]">

    <div class="add-split">

        <!-- Left: search + inputs -->
        <div>
            <div class="sec-lbl">Search & Add Product</div>
            <input type="hidden" id="product_id">

            <div class="ss-wrap" id="ss_wrap">
                <input type="text" id="ss_search" class="ss-input"
                       placeholder="Search by name or category…" autocomplete="off">
                <div class="ss-drop" id="ss_drop"></div>
            </div>

            <div id="stype_wrap" style="display:none;">
                <div class="sec-lbl" style="margin-top:14px;margin-bottom:6px;">Stock Source</div>
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

            <div class="form-2col" style="margin-top:14px;">
                <div class="form-group">
                    <label id="qty_label">Quantity</label>
                    <input type="number" id="qty" min="0.001" step="any" placeholder="0">
                    <small id="stock_hint" style="font-size:12px;color:var(--secondary);display:block;margin-top:3px;"></small>
                </div>
                <div class="form-group">
                    <label id="price_label">
                        Price (RWF)
                        <span class="default-price-badge" onclick="useDefaultPrice()">Use default</span>
                    </label>
                    <input type="number" id="price" min="1" step="any" placeholder="0">
                </div>
            </div>

            <button type="button" class="add-item-btn" onclick="addToCart()">
                + Add to Cart
            </button>

            <button type="button" class="new-owner-toggle" style="margin-top:12px;display:inline-flex;align-items:center;gap:5px;font-size:13px;color:var(--primary);cursor:pointer;font-weight:600;background:none;border:none;padding:0;" onclick="toggleCustom()">
                <span id="cp_icon">＋</span> Can't find it? Add it by name
            </button>
            <div class="new-owner-panel" id="custom_panel" style="display:none;margin-top:10px;background:var(--gray-50);border:1px solid var(--gray-200);border-radius:var(--radius);padding:14px;">
                <div class="form-group">
                    <label>Product name</label>
                    <input type="text" id="custom_name" placeholder="Describe the item">
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label>Quantity</label>
                    <input type="number" id="custom_qty" min="0.001" step="any" placeholder="0">
                </div>
                <button type="button" class="add-item-btn" style="background:#8b5cf6;margin-top:10px;" onclick="addCustomToCart()">Add Custom Item</button>
            </div>
        </div>

        <!-- Right: cart -->
        <div>
            <div class="cart-panel">
                <div class="cart-header">
                    New Items to Add
                    <span id="cart_badge" class="cart-badge zero">0</span>
                </div>
                <div class="cart-body" id="cart_body">
                    <div class="cart-empty">No new items yet.<br>Search and add products from the left.</div>
                </div>
                <div class="cart-foot">
                    <span class="cart-foot-lbl">Items Total</span>
                    <span class="cart-foot-val" id="cart_total">RWF 0</span>
                </div>
            </div>
        </div>

    </div><!-- /.add-split -->

    <!-- Submit bar -->
    <div class="submit-bar">
        <div class="new-total-preview">
            New order total: <strong id="new_total_preview">RWF <?php echo number_format((float)$order['total_amount'],0); ?></strong>
        </div>
        <div style="display:flex;gap:10px;">
            <a href="orders.php" class="btn">Cancel</a>
            <button type="submit" id="submit_btn" class="btn btn-primary" style="padding:10px 28px;" disabled>
                Save Products to Order
            </button>
        </div>
    </div>

</form>

<!-- Current order items (below the form) -->
<?php if (!empty($existing_items)): ?>
<div style="margin-top:28px;">
    <div class="sec-lbl" style="margin-bottom:10px;">
        Current Items on this Order
        <span style="font-weight:400;text-transform:none;letter-spacing:0;color:var(--secondary);margin-left:6px;">(<?php echo count($existing_items); ?>)</span>
    </div>
    <div class="ei-list">
        <?php foreach ($existing_items as $ei):
            $src = $ei['stock_source'] ?? 'wh';
            $src_badge = $src === 'rt'
                ? '<span style="font-size:10px;background:#fef3c7;color:#854d0e;border:1px solid #fde68a;border-radius:4px;padding:1px 5px;margin-left:6px;font-weight:600;">RT</span>'
                : ($src === 'custom'
                    ? '<span style="font-size:10px;background:#ede9fe;color:#5b21b6;border:1px solid #ddd6fe;border-radius:4px;padding:1px 5px;margin-left:6px;font-weight:600;">CUSTOM</span>'
                    : '<span style="font-size:10px;background:#dbeafe;color:#1e40af;border:1px solid #93c5fd;border-radius:4px;padding:1px 5px;margin-left:6px;font-weight:600;">WH</span>');
            $unit = $src === 'rt' ? 'pcs' : ($src === 'custom' ? '' : 'pkg');
            $isAvailable = ($ei['status'] ?? 'pending') !== 'out_of_stock';
        ?>
        <div class="ei-item<?php echo $isAvailable ? '' : ' unavailable'; ?>" id="ei_row_<?php echo $ei['id']; ?>">
            <div class="ei-name">
                <?php if (!empty($ei['category'])): ?>
                <span style="font-size:11px;color:var(--secondary);font-weight:400;"><?php echo htmlspecialchars($ei['category']); ?> &rsaquo;</span>
                <?php endif; ?>
                <?php echo htmlspecialchars($ei['product_name']); ?>
                <?php echo $src_badge; ?>
            </div>
            <div class="ei-meta">
                <?php echo number_format((float)$ei['quantity'],0); ?> <?php echo $unit; ?>
                <?php if (($ei['source'] ?? 'staff') === 'customer'): ?>
                <span style="font-size:10px;background:#ede9fe;color:#5b21b6;border-radius:4px;padding:1px 5px;margin-left:4px;font-weight:600;">Customer</span>
                <?php else: ?>
                <span style="font-size:10px;background:#dbeafe;color:#1e40af;border-radius:4px;padding:1px 5px;margin-left:4px;font-weight:600;">Staff<?php echo !empty($ei['added_by_name']) ? ' &middot; '.htmlspecialchars($ei['added_by_name']) : ''; ?></span>
                <?php endif; ?>
            </div>
            <div class="ei-edit-row">
                <span>RWF</span>
                <input type="number" class="ei-price-input" id="ei_price_<?php echo $ei['id']; ?>"
                       value="<?php echo (float)$ei['selling_price']; ?>" min="0" step="any"
                       onchange="markItemDirty(<?php echo $ei['id']; ?>)">
                <div class="ei-avail-toggle">
                    <button type="button" class="ei-avail-btn on-yes<?php echo $isAvailable ? ' active' : ''; ?>" id="ei_avail_yes_<?php echo $ei['id']; ?>" onclick="setItemAvailable(<?php echo $ei['id']; ?>, true)">Available</button>
                    <button type="button" class="ei-avail-btn on-no<?php echo $isAvailable ? '' : ' active'; ?>" id="ei_avail_no_<?php echo $ei['id']; ?>" onclick="setItemAvailable(<?php echo $ei['id']; ?>, false)">Unavailable</button>
                </div>
                <button type="button" class="ei-save-btn" id="ei_save_<?php echo $ei['id']; ?>" disabled onclick="saveItem(<?php echo $ei['id']; ?>)">Save</button>
            </div>
            <div class="ei-amt" id="ei_amt_<?php echo $ei['id']; ?>">RWF <?php echo number_format((float)$ei['item_total'],0); ?></div>
        </div>
        <?php endforeach; ?>
        <div class="ei-item" style="background:var(--gray-50);font-weight:700;">
            <div class="ei-name" style="color:var(--secondary);">Total</div>
            <div class="ei-meta"></div>
            <div class="ei-amt" style="color:var(--primary);">RWF <?php echo number_format((float)$order['total_amount'],0); ?></div>
        </div>
    </div>
</div>
<?php endif; ?>

</div><!-- /.page-wrap -->
</div><!-- /.main-content -->
</div><!-- /.dashboard-container -->

<div id="addToast"></div>
<script src="script.js"></script>
<script>
var _orderBasetotal = <?php echo (float)$order['total_amount']; ?>;

function showToast(msg, ok) {
    var t = document.getElementById('addToast');
    t.textContent = msg; t.className = 'show ' + (ok ? 'ok' : 'err');
    clearTimeout(t._tid);
    t._tid = setTimeout(function(){ t.className=''; }, 4000);
}
function escH(s){ return (s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

// ── Product search ────────────────────────────────────────────────────────────
var _selectedProd = null, _stockType = 'wh';

(function(){
    var search = document.getElementById('ss_search');
    var drop   = document.getElementById('ss_drop');
    var hidden = document.getElementById('product_id');
    var hi = -1, _timer = null;

    function showLoading() {
        drop.innerHTML = '<div class="sd-loading"><span class="sd-spinner"></span>Searching…</div>';
        drop.classList.add('open');
    }
    function renderOptions(rows) {
        hi = -1;
        if (!rows.length) {
            drop.innerHTML = '<div class="sd-loading">No products found.</div>';
            return;
        }
        drop.innerHTML = '';
        rows.forEach(function(p) {
            var label = p.category + '-' + p.name;
            var parts = [];
            if (parseInt(p.stock_qty) > 0) parts.push(Number(p.stock_qty).toLocaleString() + ' pkgs WH');
            if (parseInt(p.rt_qty)    > 0) parts.push(Number(p.rt_qty).toLocaleString()    + ' pcs RT');
            var o = document.createElement('div');
            o.className        = 'ss-opt';
            o.dataset.id       = p.id;
            o.dataset.name     = label;
            o.dataset.price    = p.default_price;
            o.dataset.stock    = p.stock_qty;
            o.dataset.ppp      = p.ppp;
            o.dataset.rtStock  = p.rt_qty;
            o.dataset.rtPrice  = p.rt_price;
            o.innerHTML = escH(label) + (parts.length ? '<span class="ss-sub"> &middot; '+parts.join(' &middot; ')+'</span>' : '');
            o.addEventListener('click', function(){ pick(o); });
            drop.appendChild(o);
        });
    }
    function doSearch(q) {
        showLoading();
        fetch('order_new.php?action=search_products&q=' + encodeURIComponent(q))
            .then(function(r){ return r.json(); })
            .then(renderOptions)
            .catch(function(){ drop.innerHTML = '<div class="sd-loading">Failed to load.</div>'; });
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
            price:   parseFloat(opt.dataset.price)  || 0,
            stock:   parseInt(opt.dataset.stock)     || 0,
            ppp:     parseInt(opt.dataset.ppp)       || 1,
            rtStock: parseInt(opt.dataset.rtStock)   || 0,
            rtPrice: parseFloat(opt.dataset.rtPrice) || 0,
        };
        drop.classList.remove('open'); hi = -1;
        onProductChange();
    }
    search.addEventListener('focus', function(){ if (!drop.classList.contains('open')) doSearch(search.value); });
    search.addEventListener('input', function(){
        _selectedProd = null; hidden.value = '';
        clearTimeout(_timer);
        _timer = setTimeout(function(){ doSearch(search.value); }, 250);
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

function onProductChange() {
    if (!_selectedProd) {
        document.getElementById('stock_hint').textContent = '';
        document.getElementById('stype_wrap').style.display = 'none';
        return;
    }
    var whQty = _selectedProd.stock, rtQty = _selectedProd.rtStock;
    document.getElementById('stype_wrap').style.display = 'block';
    var whBtn = document.getElementById('stype_wh');
    var rtBtn = document.getElementById('stype_rt');
    whBtn.disabled = whQty === 0;
    rtBtn.disabled = rtQty === 0;
    document.getElementById('stype_wh_avail').textContent = whQty.toLocaleString() + ' pkgs';
    document.getElementById('stype_rt_avail').textContent = rtQty.toLocaleString() + ' pcs';
    if (_stockType === 'wh' && whQty === 0 && rtQty > 0) _stockType = 'rt';
    if (_stockType === 'rt' && rtQty === 0 && whQty > 0) _stockType = 'wh';
    setStockType(_stockType, true);
}

function setStockType(type, skipPriceClear) {
    _stockType = type;
    document.getElementById('stype_wh').classList.toggle('active', type === 'wh');
    document.getElementById('stype_rt').classList.toggle('active', type === 'rt');
    if (!_selectedProd) return;
    var qtyEl   = document.getElementById('qty');
    var priceEl = document.getElementById('price');
    var hint    = document.getElementById('stock_hint');
    if (type === 'wh') {
        hint.textContent = 'Available: ' + _selectedProd.stock.toLocaleString() + ' packages';
        document.getElementById('qty_label').innerHTML   = 'Quantity <small style="color:var(--secondary)">(packages)</small>';
        document.getElementById('price_label').innerHTML = 'Price / package (RWF) <span class="default-price-badge" onclick="useDefaultPrice()">Use default</span>';
        if (!skipPriceClear || !priceEl.value) priceEl.value = _selectedProd.price > 0 ? _selectedProd.price : '';
        qtyEl.max = _selectedProd.stock;
    } else {
        hint.textContent = 'Available: ' + _selectedProd.rtStock.toLocaleString() + ' pieces';
        document.getElementById('qty_label').innerHTML   = 'Quantity <small style="color:var(--secondary)">(pieces)</small>';
        document.getElementById('price_label').innerHTML = 'Price / piece (RWF) <span class="default-price-badge" onclick="useDefaultPrice()">Use default</span>';
        if (!skipPriceClear || !priceEl.value) priceEl.value = _selectedProd.rtPrice > 0 ? _selectedProd.rtPrice : '';
        qtyEl.max = _selectedProd.rtStock;
    }
    var curQty = parseFloat(qtyEl.value) || 0;
    var maxQty = type === 'wh' ? _selectedProd.stock : _selectedProd.rtStock;
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
    if (!_selectedProd)                 { showToast('Select a product first.', false); return; }
    var qty   = parseFloat(document.getElementById('qty').value)   || 0;
    var price = parseFloat(document.getElementById('price').value) || 0;
    var maxQty= parseFloat(document.getElementById('qty').max)     || 0;
    var unit  = _stockType === 'rt' ? 'pcs' : 'pkg';

    if (qty <= 0)                       { showToast('Enter a valid quantity.', false); return; }
    if (maxQty > 0 && qty > maxQty) {
        showToast('Only ' + maxQty.toLocaleString() + ' ' + unit + '(s) available.', false);
        document.getElementById('qty').value = maxQty;
        return;
    }
    if (price <= 0)                     { showToast('Enter a valid price.', false); return; }

    var pid   = _selectedProd.id;
    var pname = document.getElementById('ss_search').value.trim();
    var ppp   = _selectedProd.ppp || 1;
    var type  = _stockType;

    var existing = _cart.find(function(i){ return i.pid===pid && i.type===type; });
    if (existing) {
        existing.qty += qty;
        showToast('Updated: '+pname+' → '+existing.qty.toLocaleString()+' '+unit+'(s)', true);
    } else {
        _cart.push({ pid:pid, name:pname, qty:qty, price:price, ppp:ppp, type:type, unit:unit });
        showToast('"'+pname+'" added.', true);
    }

    renderCart();

    // Reset inputs
    document.getElementById('ss_search').value  = '';
    document.getElementById('product_id').value = '';
    document.getElementById('qty').value         = '';
    document.getElementById('qty').max           = '';
    document.getElementById('price').value       = '';
    document.getElementById('stock_hint').textContent = '';
    document.getElementById('stype_wrap').style.display = 'none';
    document.getElementById('qty_label').innerHTML   = 'Quantity';
    document.getElementById('price_label').innerHTML = 'Price (RWF) <span class="default-price-badge" onclick="useDefaultPrice()">Use default</span>';
    _selectedProd = null;
    _stockType    = 'wh';
}

function removeFromCart(pid, type) {
    _cart = _cart.filter(function(i){ return !(!i.custom && i.pid===pid && i.type===type); });
    renderCart();
}
function removeCustomFromCart(idx) {
    _cart = _cart.filter(function(i){ return !(i.custom && i._idx===idx); });
    renderCart();
}

function toggleCustom() {
    var panel = document.getElementById('custom_panel');
    var open  = panel.style.display !== 'block';
    panel.style.display = open ? 'block' : 'none';
    document.getElementById('cp_icon').textContent = open ? '−' : '＋';
}

function addCustomToCart() {
    var name = document.getElementById('custom_name').value.trim();
    var qty  = parseFloat(document.getElementById('custom_qty').value) || 0;
    if (!name) { showToast('Enter a product name.', false); return; }
    if (qty <= 0) { showToast('Enter a valid quantity.', false); return; }
    _cart.push({ custom:true, _idx:Date.now(), name:name, qty:qty, price:0, unit:'' });
    renderCart();
    document.getElementById('custom_name').value = '';
    document.getElementById('custom_qty').value = '';
    showToast('"'+name+'" added.', true);
}

function renderCart() {
    var cartTotal = _cart.reduce(function(s,i){ return s + i.qty*i.price; }, 0);
    var badge = document.getElementById('cart_badge');
    badge.textContent = _cart.length;
    badge.className   = 'cart-badge'+(_cart.length===0?' zero':'');
    document.getElementById('cart_total').textContent = 'RWF '+Math.round(cartTotal).toLocaleString();
    document.getElementById('new_total_preview').textContent =
        'RWF '+Math.round(_orderBasetotal + cartTotal).toLocaleString();
    document.getElementById('submit_btn').disabled = _cart.length === 0;

    var body = document.getElementById('cart_body');
    if (_cart.length === 0) {
        body.innerHTML = '<div class="cart-empty">No new items yet.<br>Search and add products from the left.</div>';
    } else {
        body.innerHTML = _cart.map(function(item){
            if (item.custom) {
                return '<div class="cart-item">'
                    + '<div class="cart-item-info">'
                    +   '<div class="cart-item-name">'+escH(item.name)+' <span style="font-size:10px;color:#8b5cf6;font-weight:600;">(custom)</span></div>'
                    +   '<div class="cart-item-sub">'+item.qty.toLocaleString()+'</div>'
                    + '</div>'
                    + '<div class="cart-item-right">'
                    +   '<button type="button" class="cart-rm" onclick="removeCustomFromCart('+item._idx+')" title="Remove">&times;</button>'
                    + '</div>'
                    + '</div>';
            }
            var sub   = item.qty * item.price;
            var badge = item.type === 'rt'
                ? '<span style="font-size:10px;background:#fef3c7;color:#854d0e;border:1px solid #fde68a;border-radius:4px;padding:1px 5px;margin-left:4px;font-weight:600;">RT</span>'
                : '<span style="font-size:10px;background:#dbeafe;color:#1e40af;border:1px solid #93c5fd;border-radius:4px;padding:1px 5px;margin-left:4px;font-weight:600;">WH</span>';
            return '<div class="cart-item">'
                + '<div class="cart-item-info">'
                +   '<div class="cart-item-name">'+escH(item.name)+badge+'</div>'
                +   '<div class="cart-item-sub">'+item.qty.toLocaleString()+' '+item.unit+' &times; RWF '+item.price.toLocaleString()+'</div>'
                + '</div>'
                + '<div class="cart-item-right">'
                +   '<span class="cart-item-total">RWF '+Math.round(sub).toLocaleString()+'</span>'
                +   '<button type="button" class="cart-rm" onclick="removeFromCart('+JSON.stringify(item.pid)+','+JSON.stringify(item.type)+')" title="Remove">&times;</button>'
                + '</div>'
                + '</div>';
        }).join('');
    }

    document.getElementById('order_items_json').value = JSON.stringify(
        _cart.map(function(i){
            return i.custom
                ? { custom:true, name:i.name, quantity:i.qty }
                : {
                    product_id:    i.pid,
                    quantity:      i.qty,
                    level_divisor: i.type === 'rt' ? 1 : (i.ppp || 1),
                    stock_type:    i.type,
                    selling_price: i.price,
                    item_total:    i.qty * i.price,
                };
        })
    );
}

// ── Edit existing item price / availability ─────────────────────────────────────
var _itemAvail = {};

function markItemDirty(id) {
    document.getElementById('ei_save_' + id).disabled = false;
}

function setItemAvailable(id, available) {
    _itemAvail[id] = available;
    document.getElementById('ei_avail_yes_' + id).classList.toggle('active', available);
    document.getElementById('ei_avail_no_' + id).classList.toggle('active', !available);
    markItemDirty(id);
}

function saveItem(id) {
    var btn   = document.getElementById('ei_save_' + id);
    var price = parseFloat(document.getElementById('ei_price_' + id).value) || 0;
    var row   = document.getElementById('ei_row_' + id);
    var available = _itemAvail.hasOwnProperty(id) ? _itemAvail[id] : !row.classList.contains('unavailable');

    btn.disabled = true; btn.textContent = 'Saving…';
    var fd = new FormData();
    fd.append('update_item', '1');
    fd.append('order_id', <?php echo (int)$order_id; ?>);
    fd.append('item_id', id);
    fd.append('selling_price', price);
    fd.append('available', available ? '1' : '0');

    fetch('order_add_products.php', {method:'POST', body:fd})
        .then(function(r){ return r.json(); })
        .then(function(res){
            showToast(res.message, res.success);
            if (res.success) {
                document.getElementById('ei_amt_' + id).textContent = 'RWF ' + Math.round(res.new_item_total).toLocaleString();
                row.classList.toggle('unavailable', !available);
                _orderBasetotal = res.new_order_total;
                document.querySelector('.order-card-total').textContent = 'RWF ' + Math.round(res.new_order_total).toLocaleString();
                renderCart();
                btn.textContent = 'Save'; btn.disabled = true;
            } else {
                btn.textContent = 'Save'; btn.disabled = false;
            }
        })
        .catch(function(){ showToast('Network error.', false); btn.textContent = 'Save'; btn.disabled = false; });
}
</script>
</body>
</html>
