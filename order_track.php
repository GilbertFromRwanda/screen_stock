<?php
require_once 'config.php';
// No login gate — public order-status lookup by order number + phone.
global $conn;

$lang = $_GET['lang'] ?? ($_COOKIE['order_lang'] ?? 'rw');
if (!in_array($lang, ['rw', 'en'], true)) $lang = 'rw';
if (!headers_sent()) setcookie('order_lang', $lang, time() + 60 * 60 * 24 * 30, '/');

$STRINGS = [
    'rw' => [
        'page_title'      => 'Kureba Itumiza',
        'title'           => 'Kureba Aho Itumiza Rigeze',
        'sub'             => "Andika nimero y'itumiza n'telefoni wakoresheje mu gutumiza.",
        'order_number'    => "Nimero y'itumiza",
        'order_number_ph' => 'Urugero: ORD-00042',
        'phone'           => 'Telefoni',
        'phone_ph'        => 'Telefoni wakoresheje mu gutumiza',
        'track_btn'       => 'Reba Aho Rigeze',
        'not_found'       => "Ntawitumiza ryabonetse rihuye n'iyi nimero na telefoni. Reba neza wongere ugerageze.",
        'lang_switch'     => 'English',
        'status_lbl'      => 'Uko Rimeze',
        'stage_placed'    => 'Ryemejwe',
        'stage_packed'    => 'Ryapakiwe',
        'stage_ready'     => 'Riteguye Koherezwa',
        'stage_delivered' => 'Ryageze',
        'stage_received'  => 'Ryakiriwe',
        'order_status'    => [
            'new'        => 'Ritarangira',
            'open'       => 'Ritarangira',
            'pending'    => 'Ritegereje Kwemezwa',
            'processing' => 'Ritegurwa',
            'completed'  => 'Ryarangiye Neza',
            'rejected'   => 'Ryanze',
            'approved'   => 'Ryemejwe',
            'cancelled'  => 'Ryahagaritswe',
            'closed'     => 'Ryarangiye',
        ],
        'items_ordered'   => 'Ibicuruzwa Byatumijwe',
        'total'           => 'Igiteranyo',
        'cancel_reason_lbl' => 'Impamvu',
        'rejected_title'  => 'Itumiza Ryanzwe',
        'track_another'   => 'Reba irindi itumiza',
        'recent_orders'   => 'Amatumiza Yawe Aheruka',
        'no_recent'       => "Nta itumiza rirabikwa kuri iyi terefone/mudasobwa.",
        'remove_saved'    => 'Kuraho',
        'status_changed'  => "Uko itumiza ryawe rimeze byahindutse: %s",
    ],
    'en' => [
        'page_title'      => 'Track Order',
        'title'           => 'Track Your Order',
        'sub'             => 'Enter your order number and the phone number you used to order.',
        'order_number'    => 'Order Number',
        'order_number_ph' => 'e.g. ORD-00042',
        'phone'           => 'Phone',
        'phone_ph'        => 'The phone number you ordered with',
        'track_btn'       => 'Track Order',
        'not_found'       => "No order matches that number and phone. Please check and try again.",
        'lang_switch'     => 'Kinyarwanda',
        'status_lbl'      => 'Status',
        'stage_placed'    => 'Placed',
        'stage_packed'    => 'Packed',
        'stage_ready'     => 'Ready to Deliver',
        'stage_delivered' => 'Delivered',
        'stage_received'  => 'Received',
        'order_status'    => [
            'new'        => 'Not Yet Submitted',
            'open'       => 'Not Yet Submitted',
            'pending'    => 'Awaiting Confirmation',
            'processing' => 'Being Prepared',
            'completed'  => 'Completed',
            'rejected'   => 'Rejected',
            'approved'   => 'Confirmed',
            'cancelled'  => 'Cancelled',
            'closed'     => 'Closed',
        ],
        'items_ordered'   => 'Items Ordered',
        'total'           => 'Total',
        'cancel_reason_lbl' => 'Reason',
        'rejected_title'  => 'Order Rejected',
        'track_another'   => 'Track another order',
        'recent_orders'   => 'Your Recent Orders',
        'no_recent'       => "No orders saved on this device yet.",
        'remove_saved'    => 'Remove',
        'status_changed'  => "Your order status changed: %s",
    ],
];
$t = $STRINGS[$lang];

$order_number = trim($_GET['order_number'] ?? $_POST['order_number'] ?? '');
$phone_input  = trim($_GET['phone'] ?? $_POST['phone'] ?? '');
$searched     = $order_number !== '' && $phone_input !== '';
$order        = null;
$order_items  = [];

function normPhone(string $p): string {
    return preg_replace('/\D/', '', $p);
}

if ($searched) {
    $on = mysqli_real_escape_string($conn, $order_number);
    $row = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT * FROM `orders` WHERE order_number='$on' AND status NOT IN ('new','open') LIMIT 1"));
    if ($row && normPhone($row['phone']) !== '' && normPhone($row['phone']) === normPhone($phone_input)) {
        $order = $row;
        $res = mysqli_query($conn, "SELECT oi.*, p.name AS product_name, p.category
            FROM order_items oi LEFT JOIN products p ON oi.product_id=p.id
            WHERE oi.order_id={$row['id']} ORDER BY oi.id");
        while ($r = mysqli_fetch_assoc($res)) $order_items[] = $r;
    }
}

$otherLang = $lang === 'rw' ? 'en' : 'rw';
$langUrl   = '?lang=' . $otherLang . ($searched ? '&order_number=' . urlencode($order_number) . '&phone=' . urlencode($phone_input) : '');

$DELIVERY_STAGES = ['placed', 'packed', 'ready', 'delivered', 'received'];
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($t['page_title']); ?></title>
<style>
:root {
    --primary:#2563eb; --primary-dark:#1d4ed8; --dark:#0f172a; --secondary:#64748b;
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

.form-group { margin-bottom:12px; }
.form-group label { display:block; font-size:12px; font-weight:700; color:var(--secondary); text-transform:uppercase; letter-spacing:.4px; margin-bottom:5px; }
.form-group input { width:100%; padding:10px 12px; border:1px solid var(--gray-300); border-radius:var(--radius); font-size:14px; box-sizing:border-box; }
.form-group input:focus { outline:none; border-color:var(--primary); box-shadow:0 0 0 3px rgba(37,99,235,.12); }

.track-btn { width:100%; padding:13px; background:var(--primary); color:#fff; border:none; border-radius:var(--radius); font-size:15px; font-weight:700; cursor:pointer; margin-top:6px; }
.track-btn:hover { background:var(--primary-dark); }

.not-found { background:#fef2f2; color:#dc2626; border:1px solid #fca5a5; border-radius:var(--radius); padding:11px 14px; font-size:13px; margin-bottom:16px; }

.order-hdr { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:14px; }
.order-hdr-num { font-size:17px; font-weight:800; color:var(--primary-dark); }
.status-badge { display:inline-block; padding:4px 12px; border-radius:20px; font-size:12px; font-weight:700; }
.sb-pending    { background:#fef9c3; color:#854d0e; }
.sb-processing { background:#fef3c7; color:#92400e; }
.sb-approved   { background:#dcfce7; color:#166534; }
.sb-completed  { background:#dcfce7; color:#166534; }
.sb-rejected   { background:#fee2e2; color:#991b1b; }
.sb-cancelled  { background:#fee2e2; color:#991b1b; }
.sb-closed     { background:#f1f5f9; color:#475569; }

.stepper { display:flex; align-items:center; margin:18px 0 6px; }
.step { display:flex; flex-direction:column; align-items:center; flex:1; gap:6px; }
.step-dot { width:26px; height:26px; border-radius:50%; background:var(--gray-200); color:var(--secondary); display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:800; }
.step.done .step-dot { background:#10b981; color:#fff; }
.step-lbl { font-size:10px; color:var(--secondary); text-align:center; }
.step.done .step-lbl { color:#059669; font-weight:700; }
.step-line { flex:1; height:2px; background:var(--gray-200); margin-bottom:20px; }
.step-line.done { background:#10b981; }

.cancel-note { background:#fef2f2; border:1px solid #fca5a5; border-radius:var(--radius); padding:10px 12px; font-size:13px; color:#991b1b; margin-top:10px; }

.cart-item { display:flex; align-items:flex-start; padding:11px 0; gap:8px; border-bottom:1px solid var(--gray-100); }
.cart-item:last-child { border-bottom:none; }
.cart-item-info { flex:1; min-width:0; }
.cart-item-name { font-size:13px; font-weight:600; }
.cart-item-sub { font-size:12px; color:var(--secondary); margin-top:2px; }
.cart-item-total { font-size:13px; font-weight:700; }
.cart-total-row { display:flex; justify-content:space-between; align-items:center; padding-top:12px; margin-top:4px; border-top:2px solid var(--gray-200); font-weight:800; }
.cart-total-row .v { color:var(--primary-dark); font-size:17px; }

.track-another { display:block; text-align:center; font-size:13px; color:var(--primary); text-decoration:none; font-weight:600; margin-top:4px; }
.track-another:hover { text-decoration:underline; }

.recent-lbl { font-size:12px; font-weight:700; color:var(--secondary); text-transform:uppercase; letter-spacing:.4px; margin-bottom:8px; }
.recent-empty { font-size:13px; color:var(--secondary); padding:6px 0; }
.recent-item { display:flex; align-items:center; gap:8px; padding:10px 0; border-bottom:1px solid var(--gray-100); }
.recent-item:last-child { border-bottom:none; }
.recent-item-btn { flex:1; min-width:0; display:block; text-align:left; background:none; border:none; padding:0; cursor:pointer; }
.recent-item-num { font-size:13px; font-weight:700; color:var(--primary-dark); }
.recent-item-sub { font-size:12px; color:var(--secondary); margin-top:2px; }
.recent-item-rm { background:none; border:none; color:#cbd5e1; cursor:pointer; font-size:16px; padding:0; flex-shrink:0; }
.recent-item-rm:hover { color:#ef4444; }

.status-toast { position:fixed; bottom:20px; left:50%; transform:translateX(-50%); max-width:90vw; width:340px; background:#0f172a; color:#fff; border-radius:10px; padding:14px 16px; box-shadow:0 8px 24px rgba(0,0,0,.25); font-size:13px; line-height:1.5; cursor:pointer; z-index:99999; animation:stIn .2s ease-out; }
@keyframes stIn { from{opacity:0;transform:translate(-50%,8px);} to{opacity:1;transform:translate(-50%,0);} }
.status-toast small { display:block; margin-top:6px; color:#93c5fd; font-weight:700; }
</style>
</head>
<body>
<div class="wrap">

<div class="lang-switch"><a href="<?php echo htmlspecialchars($langUrl); ?>"><?php echo htmlspecialchars($t['lang_switch']); ?></a></div>

<?php if ($order): ?>
    <div class="card">
        <div class="order-hdr">
            <div>
                <div class="order-hdr-num"><?php echo htmlspecialchars($order['order_number']); ?></div>
                <?php if ($order['order_owner']): ?>
                <div class="sub" style="margin:2px 0 0;"><?php echo htmlspecialchars($order['order_owner']); ?></div>
                <?php endif; ?>
            </div>
            <span class="status-badge sb-<?php echo $order['status']; ?>"><?php echo htmlspecialchars($t['order_status'][$order['status']] ?? ucfirst($order['status'])); ?></span>
        </div>

        <?php if (in_array($order['status'], ['pending','processing','completed','approved'])):
            $stageIdx = array_search($order['delivery_status'], $DELIVERY_STAGES);
            if ($stageIdx === false) $stageIdx = 0;
        ?>
        <div class="stepper">
            <?php foreach ($DELIVERY_STAGES as $i => $stage):
                $done = $i <= $stageIdx;
                $lbl  = $t['stage_' . $stage];
            ?>
            <div class="step<?php echo $done ? ' done' : ''; ?>">
                <div class="step-dot"><?php echo $done ? '&#10003;' : ($i+1); ?></div>
                <div class="step-lbl"><?php echo htmlspecialchars($lbl); ?></div>
            </div>
            <?php if ($i < count($DELIVERY_STAGES)-1): ?>
            <div class="step-line<?php echo $i < $stageIdx ? ' done' : ''; ?>"></div>
            <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (in_array($order['status'], ['cancelled','rejected']) && !empty($order['cancel_reason'])): ?>
        <div class="cancel-note"><strong><?php echo htmlspecialchars($t['cancel_reason_lbl']); ?>:</strong> <?php echo htmlspecialchars($order['cancel_reason']); ?></div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div style="font-size:12px;font-weight:700;color:var(--secondary);text-transform:uppercase;letter-spacing:.4px;margin-bottom:8px;"><?php echo htmlspecialchars($t['items_ordered']); ?></div>
        <?php foreach ($order_items as $it): ?>
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

    <a class="track-another" href="order_track.php?lang=<?php echo $lang; ?>"><?php echo htmlspecialchars($t['track_another']); ?></a>

<?php else: ?>

    <div class="card">
        <h1><?php echo htmlspecialchars($t['title']); ?></h1>
        <p class="sub"><?php echo htmlspecialchars($t['sub']); ?></p>

        <?php if ($searched): ?>
        <div class="not-found"><?php echo htmlspecialchars($t['not_found']); ?></div>
        <?php endif; ?>

        <form method="GET">
            <input type="hidden" name="lang" value="<?php echo htmlspecialchars($lang); ?>">
            <div class="form-group">
                <label><?php echo htmlspecialchars($t['order_number']); ?></label>
                <input type="text" name="order_number" value="<?php echo htmlspecialchars($order_number); ?>" placeholder="<?php echo htmlspecialchars($t['order_number_ph']); ?>" required>
            </div>
            <div class="form-group">
                <label><?php echo htmlspecialchars($t['phone']); ?></label>
                <input type="text" name="phone" value="<?php echo htmlspecialchars($phone_input); ?>" placeholder="<?php echo htmlspecialchars($t['phone_ph']); ?>" required>
            </div>
            <button type="submit" class="track-btn"><?php echo htmlspecialchars($t['track_btn']); ?></button>
        </form>
    </div>

    <div class="card" id="recent_card" style="display:none;">
        <div class="recent-lbl"><?php echo htmlspecialchars($t['recent_orders']); ?></div>
        <div id="recent_body"></div>
    </div>

<?php endif; ?>

</div>
<script src="js/order-history.js"></script>
<?php if ($order): ?>
<script>
OrderHistory.saveOrder(<?php echo json_encode(orderHistoryPayload($order, $order_items), JSON_UNESCAPED_UNICODE); ?>);
</script>
<script src="js/order-status-watch.js"></script>
<script>
OrderStatusWatch.start({
    orderNumber: <?php echo json_encode($order['order_number'], JSON_UNESCAPED_UNICODE); ?>,
    phone:       <?php echo json_encode($order['phone'], JSON_UNESCAPED_UNICODE); ?>,
    updatedAt:   <?php echo json_encode($order['updated_at'], JSON_UNESCAPED_UNICODE); ?>,
    statusLabels: <?php echo json_encode($t['order_status'], JSON_UNESCAPED_UNICODE); ?>,
    message:     <?php echo json_encode($t['status_changed'], JSON_UNESCAPED_UNICODE); ?>
});
</script>
<?php else: ?>
<script>
(function() {
    var T_NO_RECENT = <?php echo json_encode($t['no_recent'], JSON_UNESCAPED_UNICODE); ?>;
    var T_REMOVE     = <?php echo json_encode($t['remove_saved'], JSON_UNESCAPED_UNICODE); ?>;
    var LANG         = <?php echo json_encode($lang); ?>;
    function escH(s){ return (s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

    OrderHistory.getOrders().then(function(orders) {
        var card = document.getElementById('recent_card');
        var body = document.getElementById('recent_body');
        if (!orders.length) return; // Keep the card hidden entirely when there's nothing saved yet.
        card.style.display = 'block';
        body.innerHTML = orders.map(function(o) {
            var url = 'order_track.php?lang=' + encodeURIComponent(LANG)
                + '&order_number=' + encodeURIComponent(o.order_number)
                + '&phone=' + encodeURIComponent(o.phone || '');
            return '<div class="recent-item" data-num="' + escH(o.order_number) + '">'
                + '<a class="recent-item-btn" href="' + url + '">'
                +   '<div class="recent-item-num">' + escH(o.order_number) + '</div>'
                +   '<div class="recent-item-sub">' + escH(o.order_owner || '') + '</div>'
                + '</a>'
                + '<button type="button" class="recent-item-rm" title="' + escH(T_REMOVE) + '">&times;</button>'
                + '</div>';
        }).join('') || '<div class="recent-empty">' + escH(T_NO_RECENT) + '</div>';

        body.querySelectorAll('.recent-item-rm').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                var item = btn.closest('.recent-item');
                OrderHistory.removeOrder(item.dataset.num).then(function() {
                    item.remove();
                    if (!body.querySelector('.recent-item')) card.style.display = 'none';
                });
            });
        });
    });
})();
</script>
<?php endif; ?>
</body>
</html>
