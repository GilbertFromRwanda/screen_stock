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

// Values are minutes so short (30 min) and long (30 day) spans share one unit.
// 'never' = link has no expiry; 'custom' = staff picks an exact date & time below.
$EXPIRY_OPTIONS = [
    '1440'  => '1 day',
    '30'    => '30 minutes',
    '60'    => '1 hour',
    '120'   => '2 hours',
    '2880'  => '2 days',
    '10080' => '7 days',
    '20160' => '14 days',
    '43200' => '30 days',
    'never' => 'Never expires',
    'custom'=> 'Custom date & time…',
];

$created = null;
$error   = null;

// ── Create link (draft or activate) ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_link'])) {
    $owner_id      = (int)($_POST['order_owner_id'] ?? 0);
    $show_prices   = isset($_POST['show_prices']) ? 1 : 0;
    $is_reusable   = isset($_POST['is_reusable']) ? 1 : 0;
    $activate      = isset($_POST['activate']);
    $expiry_choice = (string)($_POST['expiry_minutes'] ?? '1440');
    if (!isset($EXPIRY_OPTIONS[$expiry_choice])) $expiry_choice = '1440';

    $owner = $owner_id
        ? mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM order_owners WHERE id=$owner_id"))
        : null;

    $owner_name  = mysqli_real_escape_string($conn, $owner['name']  ?? '');
    $owner_phone = mysqli_real_escape_string($conn, $owner['phone'] ?? '');
    $owner_id_sql = $owner ? $owner_id : 'NULL';
    $status = $activate ? 'open' : 'new';

    $order_number = generateOrderNumber($conn);
    $ins_ok = mysqli_query($conn, "INSERT INTO `orders`
        (company_id, order_owner_id, product_id, quantity, level_divisor, selling_price, total_amount,
         order_owner, phone, status, show_prices, is_reusable, created_by, in_charge_id, order_number)
        VALUES (" . cidSql() . ", $owner_id_sql, NULL, 0, 1, 0, 0,
                '$owner_name', '$owner_phone', '$status', $show_prices, $is_reusable, $user_id, $user_id, '$order_number')");

    if (!$ins_ok) {
        $error = 'Could not create link: ' . mysqli_error($conn);
    } else {
        $order_id = (int)mysqli_insert_id($conn);

        $link_code = null;
        $expires_at = null;
        if ($activate) {
            $link_code = generateOrderLinkCode($conn);

            if ($expiry_choice === 'never') {
                $expires_at = null;
            } elseif ($expiry_choice === 'custom') {
                $custom_raw = $_POST['expiry_custom'] ?? '';
                $ts = $custom_raw !== '' ? strtotime($custom_raw) : false;
                if ($ts === false || $ts <= time()) $ts = time() + 1440 * 60; // fallback: 1 day
                $expires_at = date('Y-m-d H:i:s', $ts);
            } else {
                $expires_at = date('Y-m-d H:i:s', time() + (int)$expiry_choice * 60);
            }

            $expires_sql = $expires_at !== null ? "'$expires_at'" : 'NULL';
            mysqli_query($conn, "UPDATE `orders` SET link_code='$link_code', link_expires_at=$expires_sql WHERE id=$order_id");
        }

        logActivity($conn, $user_id, $activate ? 'CREATE_LINK' : 'CREATE_DRAFT',
            "Order $order_number " . ($activate ? "customer link generated (code $link_code)" : "saved as draft"),
            'orders', $order_id, [], ['status'=>$status]);

        $created = [
            'order_id'     => $order_id,
            'order_number' => $order_number,
            'status'       => $status,
            'link_code'    => $link_code,
            'expires_at'   => $expires_at,
            'is_reusable'  => $is_reusable,
        ];
    }
}

// ── Load owners for autocomplete ────────────────────────────────────────────────
$owners_arr = [];
$r = mysqli_query($conn, "SELECT * FROM order_owners " . cidWhere() . " ORDER BY name");
while ($row = mysqli_fetch_assoc($r)) $owners_arr[] = $row;

$scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$baseUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Customer Order Link</title>
<link rel="stylesheet" href="css/style.css">
<link rel="stylesheet" href="css/sales.css">
<style>
.order-wrap {
    background:var(--white); border-radius:var(--radius-lg);
    box-shadow:var(--shadow-md); padding:32px; max-width:720px;
}
.page-hdr { display:flex; align-items:center; gap:16px; margin-bottom:28px; }
.page-hdr h1 { margin:0; font-size:22px; }
.back-btn {
    display:inline-flex; align-items:center; gap:6px; padding:7px 14px;
    border-radius:var(--radius); background:var(--gray-100); color:var(--dark);
    text-decoration:none; font-size:13px; font-weight:500; border:1px solid var(--gray-300);
}
.back-btn:hover { background:var(--gray-200); }

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

.sec-lbl { font-size:12px; font-weight:700; color:var(--secondary); text-transform:uppercase; letter-spacing:.5px; margin:22px 0 10px; }

.toggle-row {
    display:flex; align-items:center; justify-content:space-between; gap:12px;
    padding:12px 16px; border:1px solid var(--gray-200); border-radius:var(--radius);
    background:var(--gray-50);
}
.toggle-row-lbl { font-size:14px; font-weight:600; }
.toggle-row-desc { font-size:12px; color:var(--secondary); margin-top:2px; }
.switch { position:relative; display:inline-block; width:44px; height:24px; flex-shrink:0; }
.switch input { opacity:0; width:0; height:0; }
.slider { position:absolute; cursor:pointer; inset:0; background:var(--gray-300); transition:.2s; border-radius:24px; }
.slider:before { position:absolute; content:""; height:18px; width:18px; left:3px; bottom:3px; background:#fff; transition:.2s; border-radius:50%; }
input:checked + .slider { background:var(--primary); }
input:checked + .slider:before { transform:translateX(20px); }

select.ss-input { appearance:auto; }

.ms-nav { display:flex; align-items:center; gap:12px; margin-top:28px; }
.ms-btn { display:inline-flex; align-items:center; gap:6px; padding:10px 22px; border-radius:var(--radius); font-size:14px; font-weight:600; cursor:pointer; border:none; transition:.15s; }
.ms-draft { background:var(--gray-100); color:var(--dark); border:1px solid var(--gray-300); }
.ms-draft:hover { background:var(--gray-200); }
.ms-activate { background:#059669; color:#fff; margin-left:auto; }
.ms-activate:hover { background:#047857; }

/* result card */
.result-card { text-align:center; padding:12px 4px; }
.result-icon { font-size:40px; margin-bottom:10px; }
.result-title { font-size:18px; font-weight:800; margin-bottom:4px; }
.result-sub { font-size:13px; color:var(--secondary); margin-bottom:20px; }
.link-box {
    display:flex; align-items:center; gap:8px; background:var(--gray-50);
    border:1px solid var(--gray-200); border-radius:var(--radius); padding:10px 12px; margin-bottom:14px;
}
.link-box input { flex:1; border:none; background:none; font-size:13px; font-family:monospace; }
.link-box input:focus { outline:none; }
.copy-btn { padding:8px 14px; border-radius:var(--radius); background:var(--primary); color:#fff; border:none; font-size:13px; font-weight:600; cursor:pointer; }
.copy-btn:hover { background:var(--primary-dark); }
.code-display { font-size:28px; font-weight:800; letter-spacing:6px; color:var(--primary); margin:10px 0; }
.expiry-note { font-size:12px; color:var(--secondary); margin-bottom:20px; }
.draft-note { background:#fffbeb; border:1px solid #fde68a; color:#854d0e; border-radius:var(--radius); padding:14px 16px; font-size:13px; text-align:left; }

#lnToast { display:none; position:fixed; bottom:24px; right:24px; padding:12px 20px; border-radius:8px; font-size:14px; font-weight:600; z-index:9999; box-shadow:0 4px 16px rgba(0,0,0,.15); max-width:320px; }
#lnToast.show { display:block; }
#lnToast.ok  { background:#ecfdf5; color:#059669; border:1px solid #a7f3d0; }
</style>
</head>
<body>
<div class="dashboard-container">
<?php include 'sidebar.php'; ?>
<div class="main-content">

<?php if ($error): ?>
<div class="alert alert-danger" style="max-width:720px;"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="order-wrap">
    <div class="page-hdr">
        <a href="orders.php" class="back-btn">&#8592; Orders</a>
        <h1>Customer Order Link</h1>
    </div>

    <?php if ($created): ?>
        <div class="result-card">
            <?php if ($created['status'] === 'open'): ?>
                <div class="result-icon">&#128279;</div>
                <div class="result-title">Link is live</div>
                <div class="result-sub"><?php echo htmlspecialchars($created['order_number']); ?> &mdash; share this with the customer</div>

                <div class="code-display"><?php echo htmlspecialchars($created['link_code']); ?></div>

                <div class="link-box">
                    <input type="text" id="link_url" readonly
                           value="<?php echo htmlspecialchars($baseUrl . '/order_customer.php?code=' . $created['link_code']); ?>">
                    <button type="button" class="copy-btn" onclick="copyLink()">Copy</button>
                </div>
                <div class="expiry-note">
                    <?php echo $created['expires_at']
                        ? 'Expires ' . date('d M Y, H:i', strtotime($created['expires_at']))
                        : 'Never expires'; ?>
                    <?php if ($created['is_reusable']): ?>
                    &middot; Reusable — good for multiple orders
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="result-icon">&#128221;</div>
                <div class="result-title">Draft saved</div>
                <div class="result-sub"><?php echo htmlspecialchars($created['order_number']); ?></div>
                <div class="draft-note">
                    No link generated yet. Open this order from the Orders list and use
                    <strong>Activate Link</strong> whenever you're ready to send it to the customer.
                </div>
            <?php endif; ?>

            <div class="ms-nav" style="justify-content:center;">
                <a href="order_link_new.php" class="ms-btn ms-draft">+ Create Another</a>
                <a href="orders.php" class="ms-btn ms-activate" style="margin-left:0;">Go to Orders</a>
            </div>
        </div>
    <?php else: ?>

    <form method="POST" id="linkForm">
        <input type="hidden" name="create_link" value="1">
        <input type="hidden" name="order_owner_id" id="order_owner_id">

        <div class="sec-lbl" style="margin-top:0;">Customer (optional)</div>
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
                       placeholder="Search existing customers, or leave blank…" autocomplete="off">
                <div class="ss-drop" id="owner_ss_drop">
                    <?php if (empty($owners_arr)): ?>
                    <div class="ss-opt" style="color:var(--secondary);cursor:default;font-style:italic;">
                        No customers yet — create one below, or leave blank
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
                <span id="no_icon">＋</span> Create new customer
            </button>
            <div class="new-owner-panel" id="new_owner_panel">
                <div style="font-size:13px;font-weight:700;margin-bottom:10px;">New Customer</div>
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
                        style="padding:8px 20px;" onclick="saveNewOwner()">Save Customer</button>
                <button type="button" class="btn" style="padding:8px 14px;margin-left:8px;"
                        onclick="toggleNewOwner()">Cancel</button>
            </div>
            <div style="font-size:12px;color:var(--secondary);margin-top:8px;">
                Leave blank to let the customer enter their own name and phone on the order page.
            </div>
        </div>

        <div class="sec-lbl">Link Settings</div>
        <div class="toggle-row">
            <div>
                <div class="toggle-row-lbl">Show product prices</div>
                <div class="toggle-row-desc">When off, the customer sees product names and quantities only — no prices or totals.</div>
            </div>
            <label class="switch">
                <input type="checkbox" name="show_prices" checked>
                <span class="slider"></span>
            </label>
        </div>

        <div class="toggle-row" style="margin-top:10px;">
            <div>
                <div class="toggle-row-lbl">Allow multiple orders through this link</div>
                <div class="toggle-row-desc">When on, the link isn't used up after one order — the customer can keep coming back and placing new orders with it until it expires or you cancel it.</div>
            </div>
            <label class="switch">
                <input type="checkbox" name="is_reusable">
                <span class="slider"></span>
            </label>
        </div>

        <div class="form-group" style="margin-top:14px;">
            <label>Link expires in</label>
            <select name="expiry_minutes" id="expiry_select" class="ss-input" onchange="toggleCustomExpiry()">
                <?php foreach ($EXPIRY_OPTIONS as $m => $label): ?>
                <option value="<?php echo $m; ?>" <?php echo $m === '1440' ? 'selected' : ''; ?>><?php echo $label; ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group" id="expiry_custom_wrap" style="display:none;">
            <label>Custom expiry date &amp; time</label>
            <input type="datetime-local" name="expiry_custom" id="expiry_custom" class="ss-input">
        </div>

        <div class="ms-nav">
            <button type="submit" class="ms-btn ms-draft">Save as Draft</button>
            <button type="submit" name="activate" value="1" class="ms-btn ms-activate">Generate &amp; Activate Link</button>
        </div>
    </form>

    <?php endif; ?>
</div><!-- /.order-wrap -->
</div>
</div>

<div id="lnToast"></div>
<script src="script.js"></script>
<script>
function showToast(msg, ok) {
    var t = document.getElementById('lnToast');
    t.textContent = msg; t.className = 'show ' + (ok ? 'ok' : 'err');
    clearTimeout(t._tid);
    t._tid = setTimeout(function(){ t.className = ''; }, 4000);
}
function escH(s){ return (s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function copyLink() {
    var el = document.getElementById('link_url');
    if (!el) return;
    el.select(); el.setSelectionRange(0, 99999);
    navigator.clipboard && navigator.clipboard.writeText(el.value).then(function(){ showToast('Link copied!', true); })
        .catch(function(){ document.execCommand('copy'); showToast('Link copied!', true); });
}

function toggleCustomExpiry() {
    var sel  = document.getElementById('expiry_select');
    var wrap = document.getElementById('expiry_custom_wrap');
    var isCustom = sel.value === 'custom';
    wrap.style.display = isCustom ? 'block' : 'none';
    if (isCustom && !document.getElementById('expiry_custom').value) {
        var d = new Date(Date.now() + 24*60*60*1000);
        var pad = function(n){ return String(n).padStart(2,'0'); };
        var local = d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate())
                  + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
        document.getElementById('expiry_custom').value = local;
    }
}

(function(){
    var search = document.getElementById('owner_search');
    if (!search) return;
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

function selectOwner(id, name, phone, location) {
    var meta = [];
    if (phone)    meta.push('\u{1F4DE} ' + phone);
    if (location) meta.push('\u{1F4CD} ' + location);
    document.getElementById('order_owner_id').value      = id;
    document.getElementById('owner_card_name').textContent = name;
    document.getElementById('owner_card_meta').textContent = meta.join('   ');
    document.getElementById('owner_card').classList.add('show');
    document.getElementById('owner_select_area').style.display = 'none';
}

function clearOwner() {
    document.getElementById('order_owner_id').value = '';
    document.getElementById('owner_card').classList.remove('show');
    document.getElementById('owner_select_area').style.display = '';
    document.getElementById('owner_search').value = '';
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
    fetch('order_link_new.php',{method:'POST',body:fd})
        .then(function(r){ return r.json(); })
        .then(function(res){
            if (res.success) {
                selectOwner(res.id,res.name,res.phone||'',res.location||'');
                document.getElementById('no_name').value='';
                document.getElementById('no_phone').value='';
                document.getElementById('no_location').value='';
                showToast('Customer "'+res.name+'" created.', true);
            } else { showToast(res.message, false); }
            btn.disabled=false; btn.textContent='Save Customer';
        })
        .catch(function(){ showToast('Network error.',false); btn.disabled=false; btn.textContent='Save Customer'; });
}
</script>
</body>
</html>
