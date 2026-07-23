<?php
require_once 'config.php';
// No login gate — this page is reached via a 5-digit code + expiry link shared with the customer.
global $conn;

function loadOrderByCode(mysqli $conn, string $code): ?array {
    if ($code === '') return null;
    $ce = mysqli_real_escape_string($conn, $code);
    $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM `orders` WHERE link_code='$ce' ORDER BY id DESC LIMIT 1"));
    if (!$row) return null;
    // Lazy expiry: hand off to the normal pending/approve flow the moment anyone hits an expired link.
    if ($row['status'] === 'open' && $row['link_expires_at'] && strtotime($row['link_expires_at']) < time()) {
        mysqli_query($conn, "UPDATE `orders` SET status='pending', updated_at=NOW() WHERE id={$row['id']}");
        $row['status'] = 'pending';
    }
    return $row;
}

// ── Language: Kinyarwanda by default, English as the alternative ────────────────
$lang = $_GET['lang'] ?? ($_COOKIE['order_lang'] ?? 'rw');
if (!in_array($lang, ['rw', 'en'], true)) $lang = 'rw';
if (!headers_sent()) setcookie('order_lang', $lang, time() + 60 * 60 * 24 * 30, '/');

$STRINGS = [
    'rw' => [
        'page_title'       => 'Gutumiza',
        'enter_code_title' => 'Andika Kode',
        'enter_code_sub'   => "Andika kode y'imibare 5 wahawe kugira ngo utangire gutumiza.",
        'code_placeholder' => 'Urugero: 48213',
        'open_btn'         => 'Fungura',
        'invalid_code_error' => 'Kode ntabwo ari yo cyangwa yarangiye igihe. Ongera ugerageze.',
        'invalid_title'    => 'Ihuza Ritariho',
        'invalid_sub'      => "Iri huza ry'itumiza ntiribaho. Reba neza kode cyangwa usabe indi.",
        'cancelled_title'  => 'Itumiza Ryahagaritswe',
        'cancelled_sub'    => 'Iri tumiza ryahagaritswe kandi ntiryakira ibicuruzwa bishya.',
        'rejected_title'   => 'Itumiza Ryanzwe',
        'rejected_sub'     => "Twihanganyeho, iri tumiza ryanzwe n'itsinda ryacu.",
        'submitted_title'  => 'Itumiza Ryoherejwe',
        'submitted_sub'    => 'Murakoze%s! Itumiza ryawe ritegereje kwemezwa n\'itsinda ryacu.',
        'submitted_banner' => 'Itumiza ryawe ryoherejwe neza! Urashobora gutumiza indi nk\'uko ukeneye.',
        'order_number_label' => 'Nimero y\'itumiza',
        'order_number_note'  => 'Bika iyi nimero — uyihe abakozi igihe ushaka kubaza ku itumiza ryawe.',
        'track_order_link'   => 'Reba aho itumiza ryawe rigeze',
        'items_ordered'    => 'Ibicuruzwa Byatumijwe',
        'total'            => 'Igiteranyo',
        'place_order'      => 'Gutumiza',
        'place_order_sub'  => "Shakisha ibicuruzwa hepfo, wongereho ibyo ukeneye, hanyuma wohereze itumiza ryawe.",
        'your_name'        => 'Amazina Yawe *',
        'full_name'        => 'Amazina yombi',
        'phone'            => 'Telefoni',
        'no_price_note'    => "Ibiciro ntibigaragara hano — itsinda ryacu rizabyemeza namwe.",
        'add_product'      => 'Ongeraho Igicuruzwa',
        'category'         => 'Icyiciro',
        'all_categories'   => 'Ibyiciro Byose',
        'search_placeholder' => 'Shakisha igicuruzwa…',
        'warehouse'        => 'Amapaki',
        'retail'           => 'Ibice',
        'quantity'         => 'Umubare',
        'quantity_pkg'     => 'Umubare (amapaki)',
        'quantity_pcs'     => 'Umubare (ibice)',
        'price_rwf'        => 'Igiciro (RWF)',
        'add_to_order'     => '+ Ongeraho mu Itumiza',
        'cant_find'        => "Ntabwo wabonye? Ongeraho izina ryacyo",
        'product_name'     => "Izina ry'igicuruzwa",
        'describe_item'    => 'Sobanura icyo gicuruzwa',
        'add_custom_item'  => 'Ongeraho Igicuruzwa',
        'your_order'       => 'Itumiza Ryawe',
        'no_items_yet'     => 'Nta bicuruzwa urabona — koresha ishakiro hejuru wongereho ibicuruzwa.',
        'submit_order'     => 'Ohereza Itumiza',
        'custom_tag'       => '(cyanditswe)',
        'lang_switch'      => 'English',
        'js' => [
            'select_product'   => 'Banza uhitemo igicuruzwa.',
            'valid_quantity'   => 'Andika umubare unyuze.',
            'only_available'   => 'Hasigaye gusa %s.',
            'added'            => '"%s" yongewemo.',
            'enter_name'       => "Andika izina ry'igicuruzwa.",
            'enter_your_name'  => 'Andika amazina yawe.',
            'searching'        => 'Birashakishwa…',
            'no_products'      => 'Nta gicuruzwa cyabonetse.',
            'failed_load'      => 'Byanze gupakira.',
            'available_suffix' => 'birahari',
        ],
    ],
    'en' => [
        'page_title'       => 'Place Your Order',
        'enter_code_title' => 'Enter Code',
        'enter_code_sub'   => 'Enter the 5-digit code you were given to start your order.',
        'code_placeholder' => 'e.g. 48213',
        'open_btn'         => 'Open',
        'invalid_code_error' => 'That code is invalid or has expired. Please try again.',
        'invalid_title'    => 'Invalid Link',
        'invalid_sub'      => "This order link doesn't exist. Please check the link or code and try again.",
        'cancelled_title'  => 'Order Cancelled',
        'cancelled_sub'    => 'This order was cancelled and is no longer accepting items.',
        'rejected_title'   => 'Order Rejected',
        'rejected_sub'     => 'Sorry, this order was declined by our team.',
        'submitted_title'  => 'Order Submitted',
        'submitted_sub'    => 'Thanks%s! Your order is awaiting confirmation from our team.',
        'submitted_banner' => 'Your order was submitted! Feel free to place another one whenever you need.',
        'order_number_label' => 'Order Number',
        'order_number_note'  => 'Save this number — give it to staff whenever you need to ask about your order.',
        'track_order_link'   => 'Track your order status',
        'items_ordered'    => 'Items Ordered',
        'total'            => 'Total',
        'place_order'      => 'Place Your Order',
        'place_order_sub'  => 'Search for products below, add what you need, then submit your order.',
        'your_name'        => 'Your Name *',
        'full_name'        => 'Full name',
        'phone'            => 'Phone',
        'no_price_note'    => "Prices aren't shown here — our team will confirm pricing with you.",
        'add_product'      => 'Add Product',
        'category'         => 'Category',
        'all_categories'   => 'All categories',
        'search_placeholder' => 'Search product…',
        'warehouse'        => 'Warehouse',
        'retail'           => 'Retail',
        'quantity'         => 'Quantity',
        'quantity_pkg'     => 'Quantity (packages)',
        'quantity_pcs'     => 'Quantity (pieces)',
        'price_rwf'        => 'Price (RWF)',
        'add_to_order'     => '+ Add to Order',
        'cant_find'        => "Can't find it? Add it by name",
        'product_name'     => 'Product name',
        'describe_item'    => 'Describe the item',
        'add_custom_item'  => 'Add Custom Item',
        'your_order'       => 'Your Order',
        'no_items_yet'     => 'No items yet — search above to add products.',
        'submit_order'     => 'Submit Order',
        'custom_tag'       => '(custom)',
        'lang_switch'      => 'Kinyarwanda',
        'js' => [
            'select_product'   => 'Select a product first.',
            'valid_quantity'   => 'Enter a valid quantity.',
            'only_available'   => 'Only %s available.',
            'added'            => '"%s" added.',
            'enter_name'       => 'Enter a product name.',
            'enter_your_name'  => 'Please enter your name.',
            'searching'        => 'Searching…',
            'no_products'      => 'No products found.',
            'failed_load'      => 'Failed to load.',
            'available_suffix' => 'available',
        ],
    ],
];
$t = $STRINGS[$lang];

$codeSubmitted = isset($_GET['code']) || isset($_POST['code']);
$code = preg_replace('/\D/', '', $_GET['code'] ?? $_POST['code'] ?? '');

// ── AJAX: category list (scoped to the order's own company, no session) ────────
if (isset($_GET['action']) && $_GET['action'] === 'get_categories') {
    header('Content-Type: application/json');
    $order = loadOrderByCode($conn, $code);
    if (!$order || $order['status'] !== 'open') { echo json_encode([]); exit; }
    $company_filter = $order['company_id'] !== null ? "AND s.company_id=" . (int)$order['company_id'] : '';
    $res = mysqli_query($conn, "
        SELECT DISTINCT p.category FROM stock s
        JOIN products p ON s.product_id = p.id
        WHERE s.quantity > 0 $company_filter
        AND p.category IS NOT NULL AND p.category != ''
        ORDER BY p.category
    ");
    $cats = [];
    while ($r = mysqli_fetch_assoc($res)) $cats[] = $r['category'];
    echo json_encode($cats);
    exit;
}

// ── AJAX: product search (scoped to the order's own company, no session) ────────
if (isset($_GET['action']) && $_GET['action'] === 'search_products') {
    header('Content-Type: application/json');
    $order = loadOrderByCode($conn, $code);
    if (!$order || $order['status'] !== 'open') { echo json_encode([]); exit; }
    $company_filter = $order['company_id'] !== null ? "AND s.company_id=" . (int)$order['company_id'] : '';
    $company_filter_rs = $order['company_id'] !== null ? "AND rs.company_id=" . (int)$order['company_id'] : '';
    $cat_filter = trim($_GET['cat'] ?? '');
    $cat_sql    = $cat_filter ? " AND p.category = '" . mysqli_real_escape_string($conn, $cat_filter) . "'" : '';
    $search_sql = productSearchSql($conn, $_GET['q'] ?? '');
    $res = mysqli_query($conn, "
        SELECT p.id, p.name, p.category,
               COALESCE(s.quantity,0)           AS stock_qty,
               COALESCE(s.package_price,0)      AS default_price,
               COALESCE(s.pieces_per_package,1) AS ppp,
               COALESCE(rs.pieces_quantity,0)   AS rt_qty,
               COALESCE(rs.retail_price,0)      AS rt_price
        FROM products p
        LEFT JOIN stock        s  ON s.product_id  = p.id $company_filter
        LEFT JOIN retail_stock rs ON rs.product_id = p.id $company_filter_rs
        WHERE p.deleted = 0
        AND $search_sql
        $cat_sql
        HAVING stock_qty > 0 OR rt_qty > 0
        ORDER BY p.category, p.name LIMIT 60");
    $rows = [];
    while ($row = mysqli_fetch_assoc($res)) $rows[] = $row;
    echo json_encode($rows);
    exit;
}

// ── Submit the order ─────────────────────────────────────────────────────────
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_order'])) {
    $order = loadOrderByCode($conn, $code);
    if (!$order || $order['status'] !== 'open') {
        $error = 'This link is no longer active.';
    } else {
        $name  = trim($_POST['customer_name']  ?? '');
        $phone = trim($_POST['customer_phone'] ?? '');
        $items = json_decode($_POST['order_items_json'] ?? '[]', true) ?: [];

        if ($name === '') {
            $error = $t['js']['enter_your_name'];
        } elseif (empty($items)) {
            $error = $lang === 'rw' ? 'Ongeraho nibura igicuruzwa kimwe mbere yo kohereza.' : 'Add at least one product before submitting.';
        } else {
            $ne = mysqli_real_escape_string($conn, $name);
            $pe = mysqli_real_escape_string($conn, $phone);
            $reusable = !empty($order['is_reusable']);
            $merged   = false;

            // A reusable link never gets consumed — the link's own row stays 'open' so
            // it can be used again. Submissions through it merge into whichever child
            // order this link last spawned, as long as that child is still 'pending'
            // (untouched by staff). Only once staff has moved that child past pending
            // (processing/completed/rejected/closed) does the next submission spawn a
            // fresh child order instead of trying to append to one already in review.
            if ($reusable) {
                $existing = mysqli_fetch_assoc(mysqli_query($conn, "
                    SELECT id, order_number FROM `orders`
                    WHERE source_order_id={$order['id']} AND status='pending'
                    ORDER BY id DESC LIMIT 1"));

                if ($existing) {
                    $target_id  = (int)$existing['id'];
                    $target_num = $existing['order_number'];
                    $merged     = true;
                    mysqli_query($conn, "UPDATE `orders` SET order_owner='$ne', phone='$pe', updated_at=NOW() WHERE id=$target_id");
                } else {
                    $comp_sql       = $order['company_id']    !== null ? (int)$order['company_id']    : 'NULL';
                    $owner_id_sql   = $order['order_owner_id'] ? (int)$order['order_owner_id'] : 'NULL';
                    $created_by_sql = $order['created_by']     ? (int)$order['created_by']     : 'NULL';
                    // Inherit whoever is currently in charge of the link, so reassigning the link
                    // going forward routes future orders to the new person too.
                    $in_charge_sql  = $order['in_charge_id'] ? (int)$order['in_charge_id'] : $created_by_sql;
                    $target_num = generateOrderNumber($conn);
                    mysqli_query($conn, "INSERT INTO `orders`
                        (company_id, order_owner_id, product_id, quantity, level_divisor, selling_price, total_amount,
                         order_owner, phone, status, show_prices, source_order_id, created_by, in_charge_id, order_number)
                        VALUES ($comp_sql, $owner_id_sql, NULL, 0, 1, 0, 0,
                                '$ne', '$pe', 'pending', {$order['show_prices']}, {$order['id']}, $created_by_sql, $in_charge_sql, '$target_num')");
                    $target_id = (int)mysqli_insert_id($conn);
                }
            } else {
                $target_id = (int)$order['id'];
            }

            $added_total = 0.0;

            foreach ($items as $it) {
                $isCustom = !empty($it['custom']);
                if ($isCustom) {
                    $cname = trim(mysqli_real_escape_string($conn, (string)($it['name'] ?? '')));
                    $qty   = (float)($it['quantity'] ?? 0);
                    if ($cname === '' || $qty <= 0) continue;
                    mysqli_query($conn, "INSERT INTO order_items
                        (order_id,product_id,custom_name,stock_source,quantity,level_divisor,selling_price,item_total,source)
                        VALUES($target_id,NULL,'$cname','custom',$qty,1,0,0,'customer')");
                } else {
                    $pid = (int)($it['product_id'] ?? 0);
                    $qty = (float)($it['quantity'] ?? 0);
                    $div = max(1, (int)($it['level_divisor'] ?? 1));
                    $src = in_array($it['stock_type'] ?? 'wh', ['wh','rt']) ? $it['stock_type'] : 'wh';
                    if ($pid <= 0 || $qty <= 0) continue;
                    $prow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM products WHERE id=$pid AND deleted=0"));
                    if (!$prow) continue;

                    // Always price the item off the system's current price — never trust the
                    // client, and never drop pricing just because it was hidden from the customer.
                    $comp_filter = $order['company_id'] !== null ? " AND company_id=" . (int)$order['company_id'] : '';
                    if ($src === 'rt') {
                        $prow2 = mysqli_fetch_assoc(mysqli_query($conn, "SELECT retail_price FROM retail_stock WHERE product_id=$pid$comp_filter"));
                        $price = $prow2 ? (float)$prow2['retail_price'] : 0;
                    } else {
                        $prow2 = mysqli_fetch_assoc(mysqli_query($conn, "SELECT package_price FROM stock WHERE product_id=$pid$comp_filter"));
                        $price = $prow2 ? (float)$prow2['package_price'] : 0;
                    }
                    $itot  = round($qty * $price, 2);
                    mysqli_query($conn, "INSERT INTO order_items
                        (order_id,product_id,stock_source,quantity,level_divisor,selling_price,item_total,source)
                        VALUES($target_id,$pid,'$src',$qty,$div,$price,$itot,'customer')");
                    $added_total += $itot;
                }
            }

            if ($reusable) {
                mysqli_query($conn, "UPDATE `orders` SET total_amount=total_amount+$added_total, updated_at=NOW() WHERE id=$target_id");
                $desc = $merged
                    ? "Customer added items to order $target_num via reusable link (code {$order['link_code']}, source order #{$order['id']})"
                    : "Customer placed order $target_num via reusable link (code {$order['link_code']}, source order #{$order['id']})";
                logActivity($conn, (int)$order['created_by'], 'CUSTOMER_SUBMIT', $desc,
                    'orders', $target_id, [], ['status'=>'pending']);
                notifyOrderSubmitted($conn, $target_id);
                header('Location: order_customer.php?code=' . urlencode($code) . '&lang=' . urlencode($lang) . '&submitted=1&order_num=' . urlencode($target_num) . '&order_phone=' . urlencode($phone));
            } else {
                mysqli_query($conn, "UPDATE `orders` SET
                    order_owner='$ne', phone='$pe',
                    total_amount=total_amount+$added_total,
                    status='pending', updated_at=NOW()
                    WHERE id=$target_id");
                logActivity($conn, (int)$order['created_by'], 'CUSTOMER_SUBMIT',
                    "Customer submitted order via link (code {$order['link_code']})",
                    'orders', $target_id, ['status'=>'open'], ['status'=>'pending']);
                notifyOrderSubmitted($conn, $target_id);
                header('Location: order_customer.php?code=' . urlencode($code) . '&lang=' . urlencode($lang));
            }
            exit;
        }
    }
}

$order = loadOrderByCode($conn, $code);
$submitted_items = [];
if ($order && $order['status'] !== 'open') {
    $res = mysqli_query($conn, "SELECT oi.*, p.name AS product_name, p.category
        FROM order_items oi LEFT JOIN products p ON oi.product_id=p.id
        WHERE oi.order_id={$order['id']} ORDER BY oi.id");
    while ($r = mysqli_fetch_assoc($res)) $submitted_items[] = $r;
}

// A reusable link stays 'open' after each submission (a fresh order is spawned
// per submit — see the POST handler above), so the redirect target here can't
// rely on $order/$submitted_items to know what was just placed. Look the
// just-submitted order up by its own number instead, purely to save it into
// the customer's browser-side order history (js/order-history.js).
$justSubmittedOrder = null;
$justSubmittedItems = [];
if (($_GET['submitted'] ?? '') === '1' && !empty($_GET['order_num'])) {
    $one = mysqli_real_escape_string($conn, $_GET['order_num']);
    $justSubmittedOrder = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM `orders` WHERE order_number='$one' LIMIT 1"));
    if ($justSubmittedOrder) {
        $res = mysqli_query($conn, "SELECT oi.*, p.name AS product_name, p.category
            FROM order_items oi LEFT JOIN products p ON oi.product_id=p.id
            WHERE oi.order_id={$justSubmittedOrder['id']} ORDER BY oi.id");
        while ($r = mysqli_fetch_assoc($res)) $justSubmittedItems[] = $r;
    }
}

$otherLang = $lang === 'rw' ? 'en' : 'rw';
$langUrl   = '?code=' . urlencode($code) . '&lang=' . $otherLang;
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($t['page_title']); ?></title>
<style>
:root {
    --primary:#103060; --primary-dark:#0a2148; --dark:#0f172a; --secondary:#64748b;
    --gray-50:#f8fafc; --gray-100:#f1f5f9; --gray-200:#e2e8f0; --gray-300:#cbd5e1;
    --radius:8px; --radius-lg:14px; --white:#fff;
}
* { box-sizing:border-box; }
body { margin:0; background:var(--gray-50); font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif; color:var(--dark); }
.wrap { max-width:520px; margin:0 auto; padding:20px 16px 60px; }
.lang-switch { text-align:right; margin-bottom:10px; }
.lang-switch a { font-size:12px; font-weight:700; color:var(--primary); text-decoration:none; background:var(--white); border:1px solid var(--gray-300); border-radius:20px; padding:5px 12px; }
.lang-switch a:hover { background:var(--gray-100); }
.card { background:var(--white); border-radius:var(--radius-lg); box-shadow:0 2px 10px rgba(0,0,0,.06); padding:22px 20px; margin-bottom:16px; }
h1 { font-size:19px; margin:0 0 4px; }
.sub { font-size:13px; color:var(--secondary); margin:0 0 18px; }
.msg-card { text-align:center; padding:44px 20px; }
.msg-icon { font-size:40px; margin-bottom:12px; }
.msg-title { font-size:17px; font-weight:800; margin-bottom:6px; }
.msg-sub { font-size:13px; color:var(--secondary); }
.order-num-box { text-align:center; background:#e8edf5; border:1px solid #c9d6ea; border-radius:var(--radius); padding:14px; margin-top:16px; }
.order-num-lbl { font-size:11px; font-weight:700; color:#0a2148; text-transform:uppercase; letter-spacing:.5px; }
.order-num-val { font-size:24px; font-weight:800; color:#1e3a8a; letter-spacing:1px; margin-top:2px; }
.order-num-note { font-size:12px; color:var(--secondary); margin-top:8px; }
.track-link { display:inline-block; font-size:13px; font-weight:700; color:var(--primary); text-decoration:none; margin-top:10px; }
.track-link:hover { text-decoration:underline; }

.form-group { margin-bottom:12px; }
.form-group label { display:block; font-size:12px; font-weight:700; color:var(--secondary); text-transform:uppercase; letter-spacing:.4px; margin-bottom:5px; }
.form-group input { width:100%; padding:10px 12px; border:1px solid var(--gray-300); border-radius:var(--radius); font-size:14px; }
.form-group input:focus { outline:none; border-color:var(--primary); box-shadow:0 0 0 3px rgba(16,48,96,.12); }

.sec-lbl { font-size:12px; font-weight:700; color:var(--secondary); text-transform:uppercase; letter-spacing:.4px; margin:18px 0 8px; }

.ss-wrap { position:relative; }
.ss-input { width:100%; padding:11px 12px; border:1px solid var(--gray-300); border-radius:var(--radius); font-size:14px; background:var(--white); }
.ss-input:focus { outline:none; border-color:var(--primary); box-shadow:0 0 0 3px rgba(16,48,96,.12); }
.ss-drop { display:none; position:absolute; top:100%; left:0; right:0; max-height:240px; overflow-y:auto; background:var(--white); border:1px solid var(--gray-300); border-top:none; border-radius:0 0 var(--radius) var(--radius); z-index:1000; box-shadow:0 8px 24px rgba(0,0,0,.12); }
.ss-drop.open { display:block; }
.ss-opt { padding:10px 12px; cursor:pointer; font-size:13px; border-bottom:1px solid var(--gray-100); }
.ss-opt:last-child { border-bottom:none; }
.ss-opt:hover, .ss-opt.hi { background:var(--gray-100); color:var(--primary); }
.ss-sub { font-size:11px; color:var(--secondary); display:block; margin-top:1px; }
.sd-loading { padding:10px 12px; font-size:13px; color:var(--secondary); }

.stype-toggle { display:flex; gap:8px; margin-top:10px; }
.stype-btn { flex:1; padding:8px 10px; border:2px solid var(--gray-200); border-radius:var(--radius); background:#fff; cursor:pointer; font-size:12px; font-weight:600; color:var(--secondary); text-align:center; }
.stype-btn.active { border-color:var(--primary); background:#e8edf5; color:var(--primary); }
.stype-btn:disabled { opacity:.35; cursor:not-allowed; }

.form-2col { display:grid; grid-template-columns:1fr 1fr; gap:0 10px; margin-top:10px; }
.add-item-btn { width:100%; padding:12px; margin-top:12px; background:#0ea5e9; color:#fff; border:none; border-radius:var(--radius); font-size:14px; font-weight:700; cursor:pointer; }
.add-item-btn:hover { background:#0284c7; }

.custom-toggle { display:inline-flex; align-items:center; gap:5px; margin-top:12px; font-size:13px; color:var(--primary); cursor:pointer; font-weight:600; background:none; border:none; padding:0; }
.custom-toggle:hover { text-decoration:underline; }
.custom-panel { display:none; margin-top:10px; background:var(--gray-50); border:1px solid var(--gray-200); border-radius:var(--radius); padding:14px; }
.custom-panel.open { display:block; }

.cart-item { display:flex; align-items:flex-start; padding:11px 0; gap:8px; border-bottom:1px solid var(--gray-100); }
.cart-item:last-child { border-bottom:none; }
.cart-item-info { flex:1; min-width:0; }
.cart-item-name { font-size:13px; font-weight:600; }
.cart-item-sub { font-size:12px; color:var(--secondary); margin-top:2px; }
.cart-item-right { flex-shrink:0; display:flex; align-items:center; gap:10px; }
.cart-item-total { font-size:13px; font-weight:700; }
.cart-rm { background:none; border:none; color:#cbd5e1; cursor:pointer; font-size:16px; padding:0; }
.cart-rm:hover { color:#ef4444; }
.cart-empty { padding:20px 0; text-align:center; font-size:13px; color:var(--secondary); }
.cart-total-row { display:flex; justify-content:space-between; align-items:center; padding-top:12px; margin-top:4px; border-top:2px solid var(--gray-200); font-weight:800; }
.cart-total-row .v { color:var(--primary-dark); font-size:17px; }

.submit-btn { width:100%; padding:13px; background:#059669; color:#fff; border:none; border-radius:var(--radius); font-size:15px; font-weight:700; cursor:pointer; margin-top:16px; }
.submit-btn:hover:not(:disabled) { background:#047857; }
.submit-btn:disabled { opacity:.4; cursor:not-allowed; }

.badge { display:inline-block; padding:3px 9px; border-radius:20px; font-size:11px; font-weight:700; }
.no-price-note { font-size:12px; color:var(--secondary); background:var(--gray-50); border:1px solid var(--gray-200); border-radius:var(--radius); padding:9px 12px; margin-top:10px; }
.submitted-banner { background:#ecfdf5; color:#059669; border:1px solid #a7f3d0; border-radius:var(--radius); padding:11px 14px; font-size:13px; font-weight:600; margin-bottom:16px; }

#toast { display:none; position:fixed; bottom:20px; left:50%; transform:translateX(-50%); padding:11px 18px; border-radius:8px; font-size:13px; font-weight:600; z-index:9999; box-shadow:0 4px 16px rgba(0,0,0,.15); max-width:90%; }
#toast.show { display:block; }
#toast.ok  { background:#ecfdf5; color:#059669; border:1px solid #a7f3d0; }
#toast.err { background:#fef2f2; color:#dc2626; border:1px solid #fca5a5; }

.code-input { width:100%; text-align:center; font-size:26px; font-weight:800; letter-spacing:10px; padding:14px 10px 14px 20px; border:1px solid var(--gray-300); border-radius:var(--radius); }
.code-input:focus { outline:none; border-color:var(--primary); box-shadow:0 0 0 3px rgba(16,48,96,.12); }
.code-error { background:#fef2f2; color:#dc2626; border:1px solid #fca5a5; border-radius:var(--radius); padding:10px 12px; font-size:13px; margin-bottom:14px; text-align:center; }
</style>
</head>
<body>
<div class="wrap">

<div class="lang-switch"><a href="<?php echo htmlspecialchars($langUrl); ?>"><?php echo htmlspecialchars($t['lang_switch']); ?></a></div>

<?php if (!$order): ?>
    <div class="card">
        <h1><?php echo htmlspecialchars($t['enter_code_title']); ?></h1>
        <p class="sub"><?php echo htmlspecialchars($t['enter_code_sub']); ?></p>

        <?php if ($codeSubmitted && $code !== ''): ?>
        <div class="code-error"><?php echo htmlspecialchars($t['invalid_code_error']); ?></div>
        <?php endif; ?>

        <form method="GET">
            <input type="hidden" name="lang" value="<?php echo htmlspecialchars($lang); ?>">
            <div class="form-group">
                <input type="text" name="code" class="code-input" inputmode="numeric" pattern="\d{5}" maxlength="5"
                       placeholder="<?php echo htmlspecialchars($t['code_placeholder']); ?>" autofocus required>
            </div>
            <button type="submit" class="submit-btn"><?php echo htmlspecialchars($t['open_btn']); ?></button>
        </form>
        <a class="track-link" href="order_track.php?lang=<?php echo $lang; ?>" style="display:block;text-align:center;"><?php echo htmlspecialchars($t['track_order_link']); ?> &rarr;</a>
    </div>

<?php elseif ($order['status'] === 'cancelled'): ?>
    <div class="card msg-card">
        <div class="msg-icon">&#128683;</div>
        <div class="msg-title"><?php echo htmlspecialchars($t['cancelled_title']); ?></div>
        <div class="msg-sub"><?php echo htmlspecialchars($t['cancelled_sub']); ?></div>
    </div>

<?php elseif ($order['status'] === 'rejected'): ?>
    <div class="card msg-card">
        <div class="msg-icon">&#128683;</div>
        <div class="msg-title"><?php echo htmlspecialchars($t['rejected_title']); ?></div>
        <div class="msg-sub"><?php echo htmlspecialchars($t['rejected_sub']); ?></div>
        <?php if (!empty($order['cancel_reason'])): ?>
        <div class="order-num-note" style="margin-top:10px;"><?php echo htmlspecialchars($order['cancel_reason']); ?></div>
        <?php endif; ?>
    </div>

<?php elseif ($order['status'] !== 'open'): ?>
    <div class="card msg-card">
        <div class="msg-icon">&#9989;</div>
        <div class="msg-title"><?php echo htmlspecialchars($t['submitted_title']); ?></div>
        <div class="msg-sub"><?php echo htmlspecialchars(sprintf($t['submitted_sub'], $order['order_owner'] ? ', ' . $order['order_owner'] : '')); ?></div>
        <?php if ($order['order_number']): ?>
        <div class="order-num-box">
            <div class="order-num-lbl"><?php echo htmlspecialchars($t['order_number_label']); ?></div>
            <div class="order-num-val"><?php echo htmlspecialchars($order['order_number']); ?></div>
            <div class="order-num-note"><?php echo htmlspecialchars($t['order_number_note']); ?></div>
        </div>
        <a class="track-link" href="order_track.php?lang=<?php echo $lang; ?>&order_number=<?php echo urlencode($order['order_number']); ?>&phone=<?php echo urlencode($order['phone']); ?>"><?php echo htmlspecialchars($t['track_order_link']); ?> &rarr;</a>
        <?php endif; ?>
    </div>
    <div class="card">
        <div class="sec-lbl" style="margin-top:0;"><?php echo htmlspecialchars($t['items_ordered']); ?></div>
        <?php foreach ($submitted_items as $it): ?>
        <div class="cart-item">
            <div class="cart-item-info">
                <div class="cart-item-name"><?php echo htmlspecialchars($it['product_name'] ?? $it['custom_name'] ?? 'Item'); ?></div>
                <div class="cart-item-sub"><?php echo number_format((float)$it['quantity'],0); ?> <?php echo ($it['stock_source']??'')==='rt' ? 'pcs' : (($it['stock_source']??'')==='custom' ? '' : 'pkg'); ?></div>
            </div>
            <?php if ($order['show_prices'] && (float)$it['item_total'] > 0): ?>
            <div class="cart-item-total">RWF <?php echo number_format((float)$it['item_total'],0); ?></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php if ($order['show_prices']): ?>
        <div class="cart-total-row"><span><?php echo htmlspecialchars($t['total']); ?></span><span class="v">RWF <?php echo number_format((float)$order['total_amount'],0); ?></span></div>
        <?php endif; ?>
    </div>

<?php else: ?>

    <?php if (($_GET['submitted'] ?? '') === '1'): ?>
    <div class="submitted-banner">
        &#9989; <?php echo htmlspecialchars($t['submitted_banner']); ?>
        <?php if (!empty($_GET['order_num'])): ?>
        <div class="order-num-box" style="margin-top:10px;">
            <div class="order-num-lbl"><?php echo htmlspecialchars($t['order_number_label']); ?></div>
            <div class="order-num-val"><?php echo htmlspecialchars($_GET['order_num']); ?></div>
            <div class="order-num-note"><?php echo htmlspecialchars($t['order_number_note']); ?></div>
        </div>
        <a class="track-link" href="order_track.php?lang=<?php echo $lang; ?>&order_number=<?php echo urlencode($_GET['order_num']); ?>&phone=<?php echo urlencode($_GET['order_phone'] ?? ''); ?>"><?php echo htmlspecialchars($t['track_order_link']); ?> &rarr;</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="card">
        <h1><?php echo htmlspecialchars($t['place_order']); ?></h1>
        <p class="sub"><?php echo htmlspecialchars($t['place_order_sub']); ?></p>

        <?php if ($error): ?>
        <div style="background:#fef2f2;color:#dc2626;border:1px solid #fca5a5;border-radius:var(--radius);padding:10px 12px;font-size:13px;margin-bottom:14px;"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" id="orderForm">
            <input type="hidden" name="submit_order" value="1">
            <input type="hidden" name="code" value="<?php echo htmlspecialchars($code); ?>">
            <input type="hidden" name="order_items_json" id="order_items_json" value="[]">

            <div class="form-group">
                <label><?php echo htmlspecialchars($t['your_name']); ?></label>
                <input type="text" name="customer_name" id="customer_name" value="<?php echo htmlspecialchars($order['order_owner']); ?>" placeholder="<?php echo htmlspecialchars($t['full_name']); ?>" required>
            </div>
            <div class="form-group">
                <label><?php echo htmlspecialchars($t['phone']); ?></label>
                <input type="text" name="customer_phone" id="customer_phone" value="<?php echo htmlspecialchars($order['phone']); ?>" placeholder="07XXXXXXXX">
            </div>

            <?php if (!$order['show_prices']): ?>
            <div class="no-price-note"><?php echo htmlspecialchars($t['no_price_note']); ?></div>
            <?php endif; ?>

            <div class="sec-lbl"><?php echo htmlspecialchars($t['add_product']); ?></div>

            <div class="form-group">
                <label><?php echo htmlspecialchars($t['category']); ?></label>
                <select id="cat_select" class="ss-input">
                    <option value=""><?php echo htmlspecialchars($t['all_categories']); ?></option>
                </select>
            </div>

            <div class="ss-wrap" id="ss_wrap">
                <input type="text" id="ss_search" class="ss-input" placeholder="<?php echo htmlspecialchars($t['search_placeholder']); ?>" autocomplete="off">
                <div class="ss-drop" id="ss_drop"></div>
            </div>

            <div id="stype_wrap" style="display:none;">
                <div class="stype-toggle">
                    <button type="button" class="stype-btn" id="stype_wh" onclick="setStockType('wh')"><?php echo htmlspecialchars($t['warehouse']); ?> <span id="stype_wh_avail"></span></button>
                    <button type="button" class="stype-btn" id="stype_rt" onclick="setStockType('rt')"><?php echo htmlspecialchars($t['retail']); ?> <span id="stype_rt_avail"></span></button>
                </div>
            </div>

            <div class="form-2col">
                <div class="form-group" style="margin-bottom:0;">
                    <label id="qty_label"><?php echo htmlspecialchars($t['quantity']); ?></label>
                    <input type="number" id="qty" min="0.001" step="any" placeholder="0">
                </div>
                <?php if ($order['show_prices']): ?>
                <div class="form-group" style="margin-bottom:0;">
                    <label id="price_label"><?php echo htmlspecialchars($t['price_rwf']); ?></label>
                    <input type="number" id="price" min="0" step="any" placeholder="0" readonly>
                </div>
                <?php endif; ?>
            </div>
            <button type="button" class="add-item-btn" onclick="addToCart()"><?php echo htmlspecialchars($t['add_to_order']); ?></button>

            <button type="button" class="custom-toggle" onclick="toggleCustom()">
                <span id="cp_icon">＋</span> <?php echo htmlspecialchars($t['cant_find']); ?>
            </button>
            <div class="custom-panel" id="custom_panel">
                <div class="form-group">
                    <label><?php echo htmlspecialchars($t['product_name']); ?></label>
                    <input type="text" id="custom_name" placeholder="<?php echo htmlspecialchars($t['describe_item']); ?>">
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label><?php echo htmlspecialchars($t['quantity']); ?></label>
                    <input type="number" id="custom_qty" min="0.001" step="any" placeholder="0">
                </div>
                <button type="button" class="add-item-btn" style="background:#8b5cf6;" onclick="addCustomToCart()"><?php echo htmlspecialchars($t['add_custom_item']); ?></button>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="sec-lbl" style="margin-top:0;"><?php echo htmlspecialchars($t['your_order']); ?> (<span id="cart_count">0</span>)</div>
        <div id="cart_body"><div class="cart-empty"><?php echo htmlspecialchars($t['no_items_yet']); ?></div></div>
        <?php if ($order['show_prices']): ?>
        <div class="cart-total-row"><span><?php echo htmlspecialchars($t['total']); ?></span><span class="v" id="cart_total">RWF 0</span></div>
        <?php endif; ?>
        <button type="button" class="submit-btn" id="submit_btn" disabled onclick="submitOrder()"><?php echo htmlspecialchars($t['submit_order']); ?></button>
    </div>

<?php endif; ?>

</div>

<div id="toast"></div>
<script src="js/order-history.js"></script>
<?php if ($justSubmittedOrder): ?>
<script>
OrderHistory.saveOrder(<?php echo json_encode(orderHistoryPayload($justSubmittedOrder, $justSubmittedItems), JSON_UNESCAPED_UNICODE); ?>);
</script>
<?php endif; ?>
<?php if ($order && $order['status'] !== 'open'): ?>
<script>
OrderHistory.saveOrder(<?php echo json_encode(orderHistoryPayload($order, $submitted_items), JSON_UNESCAPED_UNICODE); ?>);
</script>
<?php endif; ?>
<?php if ($order && $order['status'] === 'open'): ?>
<script>
var SHOW_PRICES = <?php echo $order['show_prices'] ? 'true' : 'false'; ?>;
var CODE = <?php echo json_encode($code); ?>;
var T = <?php echo json_encode($t['js'], JSON_UNESCAPED_UNICODE); ?>;
var T_CUSTOM_TAG = <?php echo json_encode($t['custom_tag'], JSON_UNESCAPED_UNICODE); ?>;
var T_NO_ITEMS = <?php echo json_encode($t['no_items_yet'], JSON_UNESCAPED_UNICODE); ?>;
var T_QTY_PKG = <?php echo json_encode($t['quantity_pkg'], JSON_UNESCAPED_UNICODE); ?>;
var T_QTY_PCS = <?php echo json_encode($t['quantity_pcs'], JSON_UNESCAPED_UNICODE); ?>;

function fmt(str, val) { return str.replace('%s', val); }

function showToast(msg, ok) {
    var t = document.getElementById('toast');
    t.textContent = msg; t.className = 'show ' + (ok ? 'ok' : 'err');
    clearTimeout(t._tid);
    t._tid = setTimeout(function(){ t.className=''; }, 3500);
}
function escH(s){ return (s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

// ── Category filter ───────────────────────────────────────────────────────────
var _selectedCat = '';

(function(){
    var sel = document.getElementById('cat_select');
    fetch('order_customer.php?code=' + encodeURIComponent(CODE) + '&action=get_categories')
        .then(function(r){ return r.json(); })
        .then(function(cats){
            cats.forEach(function(c){
                var o = document.createElement('option');
                o.value = c; o.textContent = c;
                sel.appendChild(o);
            });
        })
        .catch(function(){});
    sel.addEventListener('change', function(){
        _selectedCat = sel.value;
        document.getElementById('ss_search').value = '';
        _selectedProd = null;
        document.getElementById('stype_wrap').style.display = 'none';
        document.getElementById('ss_search').dispatchEvent(new Event('focus'));
    });
})();

// ── Product search ────────────────────────────────────────────────────────────
var _selectedProd = null, _stockType = 'wh';

(function(){
    var search = document.getElementById('ss_search');
    var drop   = document.getElementById('ss_drop');
    var hi = -1, _timer = null;

    function showLoading() {
        drop.innerHTML = '<div class="sd-loading">' + escH(T.searching) + '</div>';
        drop.classList.add('open');
    }
    function renderOptions(rows) {
        hi = -1;
        if (!rows.length) { drop.innerHTML = '<div class="sd-loading">' + escH(T.no_products) + '</div>'; return; }
        drop.innerHTML = '';
        rows.forEach(function(p) {
            var label = p.category + ' - ' + p.name;
            var parts = [];
            if (parseInt(p.stock_qty) > 0) parts.push(Number(p.stock_qty).toLocaleString() + ' pkgs');
            if (parseInt(p.rt_qty)    > 0) parts.push(Number(p.rt_qty).toLocaleString()    + ' pcs');
            var o = document.createElement('div');
            o.className = 'ss-opt';
            o.dataset.id = p.id; o.dataset.name = label;
            o.dataset.price = p.default_price; o.dataset.stock = p.stock_qty; o.dataset.ppp = p.ppp;
            o.dataset.rtStock = p.rt_qty; o.dataset.rtPrice = p.rt_price;
            o.innerHTML = escH(label) + (parts.length ? '<span class="ss-sub">' + parts.join(' · ') + ' ' + escH(T.available_suffix) + '</span>' : '');
            o.addEventListener('click', function(){ pick(o); });
            drop.appendChild(o);
        });
    }
    function doSearch(q) {
        showLoading();
        var url = 'order_customer.php?code=' + encodeURIComponent(CODE) + '&action=search_products&q=' + encodeURIComponent(q);
        if (_selectedCat) url += '&cat=' + encodeURIComponent(_selectedCat);
        fetch(url)
            .then(function(r){ return r.json(); }).then(renderOptions)
            .catch(function(){ drop.innerHTML = '<div class="sd-loading">' + escH(T.failed_load) + '</div>'; });
    }
    function vis(){ return Array.from(drop.querySelectorAll('.ss-opt')); }
    function hl(v){ drop.querySelectorAll('.ss-opt').forEach(function(o){ o.classList.remove('hi'); }); if (v[hi]) { v[hi].classList.add('hi'); v[hi].scrollIntoView({block:'nearest'}); } }
    function pick(opt){
        search.value = opt.dataset.name;
        _selectedProd = {
            id: opt.dataset.id, name: opt.dataset.name,
            price: parseFloat(opt.dataset.price) || 0, stock: parseInt(opt.dataset.stock) || 0,
            ppp: parseInt(opt.dataset.ppp) || 1,
            rtStock: parseInt(opt.dataset.rtStock) || 0, rtPrice: parseFloat(opt.dataset.rtPrice) || 0
        };
        drop.classList.remove('open'); hi = -1;
        onProductChange();
    }
    search.addEventListener('focus', function(){ if (!drop.classList.contains('open')) doSearch(search.value); });
    search.addEventListener('input', function(){
        _selectedProd = null; clearTimeout(_timer);
        _timer = setTimeout(function(){ doSearch(search.value); }, 250);
    });
    search.addEventListener('keydown', function(e){
        var v = vis();
        if      (e.key==='ArrowDown'){ e.preventDefault(); hi=Math.min(hi+1,v.length-1); hl(v); }
        else if (e.key==='ArrowUp')  { e.preventDefault(); hi=Math.max(hi-1,0); hl(v); }
        else if (e.key==='Enter')    { e.preventDefault(); if(hi>=0&&v[hi]) pick(v[hi]); }
        else if (e.key==='Escape')   { drop.classList.remove('open'); }
    });
    document.addEventListener('click', function(e){ if (!e.target.closest('#ss_wrap')) drop.classList.remove('open'); });
})();

function onProductChange() {
    if (!_selectedProd) { document.getElementById('stype_wrap').style.display = 'none'; return; }
    var whQty = _selectedProd.stock, rtQty = _selectedProd.rtStock;
    document.getElementById('stype_wrap').style.display = 'block';
    var whBtn = document.getElementById('stype_wh'), rtBtn = document.getElementById('stype_rt');
    whBtn.disabled = whQty === 0; rtBtn.disabled = rtQty === 0;
    document.getElementById('stype_wh_avail').textContent = '(' + whQty.toLocaleString() + ')';
    document.getElementById('stype_rt_avail').textContent = '(' + rtQty.toLocaleString() + ')';
    if (_stockType === 'wh' && whQty === 0 && rtQty > 0) _stockType = 'rt';
    if (_stockType === 'rt' && rtQty === 0 && whQty > 0) _stockType = 'wh';
    setStockType(_stockType);
}

function setStockType(type) {
    _stockType = type;
    document.getElementById('stype_wh').classList.toggle('active', type === 'wh');
    document.getElementById('stype_rt').classList.toggle('active', type === 'rt');
    if (!_selectedProd) return;
    var qtyEl = document.getElementById('qty');
    var priceEl = document.getElementById('price');
    document.getElementById('qty_label').textContent = type === 'wh' ? T_QTY_PKG : T_QTY_PCS;
    qtyEl.max = type === 'wh' ? _selectedProd.stock : _selectedProd.rtStock;
    if (priceEl) priceEl.value = type === 'wh' ? _selectedProd.price : _selectedProd.rtPrice;
    var curQty = parseFloat(qtyEl.value) || 0;
    if (curQty > qtyEl.max) qtyEl.value = qtyEl.max;
}

// ── Cart ──────────────────────────────────────────────────────────────────────
var _cart = [];

function addToCart() {
    if (!_selectedProd) { showToast(T.select_product, false); return; }
    var qty = parseFloat(document.getElementById('qty').value) || 0;
    var maxQty = parseFloat(document.getElementById('qty').max) || 0;
    if (qty <= 0) { showToast(T.valid_quantity, false); return; }
    if (maxQty > 0 && qty > maxQty) { showToast(fmt(T.only_available, maxQty.toLocaleString()), false); return; }

    var price = SHOW_PRICES ? (_stockType === 'wh' ? _selectedProd.price : _selectedProd.rtPrice) : 0;
    var unit  = _stockType === 'rt' ? 'pcs' : 'pkg';
    var pname = document.getElementById('ss_search').value.trim();
    var ppp   = _selectedProd.ppp || 1;

    var existing = _cart.find(function(i){ return !i.custom && i.pid===_selectedProd.id && i.type===_stockType; });
    if (existing) existing.qty += qty;
    else _cart.push({ custom:false, pid:_selectedProd.id, name:pname, qty:qty, price:price, ppp:ppp, type:_stockType, unit:unit });

    renderCart();
    document.getElementById('ss_search').value = '';
    document.getElementById('qty').value = '';
    _selectedProd = null;
    document.getElementById('stype_wrap').style.display = 'none';
    showToast(fmt(T.added, pname), true);
}

function toggleCustom() {
    var panel = document.getElementById('custom_panel');
    var open  = panel.classList.toggle('open');
    document.getElementById('cp_icon').textContent = open ? '−' : '＋';
}

function addCustomToCart() {
    var name = document.getElementById('custom_name').value.trim();
    var qty  = parseFloat(document.getElementById('custom_qty').value) || 0;
    if (!name) { showToast(T.enter_name, false); return; }
    if (qty <= 0) { showToast(T.valid_quantity, false); return; }
    _cart.push({ custom:true, name:name, qty:qty, price:0, unit:'' });
    renderCart();
    document.getElementById('custom_name').value = '';
    document.getElementById('custom_qty').value = '';
    showToast(fmt(T.added, name), true);
}

function removeFromCart(idx) { _cart.splice(idx, 1); renderCart(); }

function renderCart() {
    var total = _cart.reduce(function(s,i){ return s + i.qty*i.price; }, 0);
    document.getElementById('cart_count').textContent = _cart.length;
    var totalEl = document.getElementById('cart_total');
    if (totalEl) totalEl.textContent = 'RWF ' + Math.round(total).toLocaleString();

    var body = document.getElementById('cart_body');
    if (_cart.length === 0) {
        body.innerHTML = '<div class="cart-empty">' + escH(T_NO_ITEMS) + '</div>';
    } else {
        body.innerHTML = _cart.map(function(item, idx){
            var sub = item.qty * item.price;
            return '<div class="cart-item">'
                + '<div class="cart-item-info">'
                +   '<div class="cart-item-name">' + escH(item.name) + (item.custom ? ' <span style="color:#8b5cf6;font-size:11px;">' + escH(T_CUSTOM_TAG) + '</span>' : '') + '</div>'
                +   '<div class="cart-item-sub">' + item.qty.toLocaleString() + ' ' + item.unit + '</div>'
                + '</div>'
                + '<div class="cart-item-right">'
                +   (SHOW_PRICES && !item.custom ? '<span class="cart-item-total">RWF ' + Math.round(sub).toLocaleString() + '</span>' : '')
                +   '<button type="button" class="cart-rm" onclick="removeFromCart(' + idx + ')">&times;</button>'
                + '</div>'
                + '</div>';
        }).join('');
    }

    document.getElementById('order_items_json').value = JSON.stringify(
        _cart.map(function(i){
            return i.custom
                ? { custom:true, name:i.name, quantity:i.qty }
                : { product_id:i.pid, quantity:i.qty, level_divisor:i.type==='rt'?1:(i.ppp||1), stock_type:i.type, selling_price:i.price };
        })
    );
    document.getElementById('submit_btn').disabled = _cart.length === 0;
}

function submitOrder() {
    if (!document.getElementById('customer_name').value.trim()) {
        showToast(T.enter_your_name, false);
        document.getElementById('customer_name').focus();
        return;
    }
    document.getElementById('orderForm').submit();
}
</script>
<?php endif; ?>
</body>
</html>
