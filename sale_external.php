<?php
require_once 'config.php';
if (!isLoggedIn()) redirect('login.php');
if (!hasPermission('sales', 'create')) { $_SESSION['flash_error'] = "You don't have permission to record external sales."; redirect('dashboard.php'); }

$cid_sql = cidSql(); $cid_and = cidAnd();

// ── AJAX: product search ──────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'search_products') {
    header('Content-Type: application/json');
    $q          = '%' . mysqli_real_escape_string($conn, trim($_GET['q'] ?? '')) . '%';
    $cat_filter = trim($_GET['cat'] ?? '');
    $cat_sql    = $cat_filter ? " AND p.category = '" . mysqli_real_escape_string($conn, $cat_filter) . "'" : '';
    $res = mysqli_query($conn, "
        SELECT p.id, p.name, p.category,
               COALESCE(s.package_price, 0) AS bulk_price,
               COALESCE(r.retail_price, 0)  AS retail_price
        FROM products p
        LEFT JOIN stock s        ON s.product_id = p.id " . cidAndFor('s') . "
        LEFT JOIN retail_stock r ON r.product_id = p.id " . cidAndFor('r') . "
        WHERE p.deleted = 0 AND (p.name LIKE '$q' OR p.category LIKE '$q') $cat_sql
        ORDER BY p.category, p.name LIMIT 60
    ");
    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    echo json_encode($rows);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'get_categories') {
    header('Content-Type: application/json');
    $res  = mysqli_query($conn,
        "SELECT DISTINCT category FROM products
         WHERE deleted = 0 AND category IS NOT NULL AND category != ''
         ORDER BY category"
    );
    $cats = [];
    while ($r = mysqli_fetch_assoc($res)) $cats[] = $r['category'];
    echo json_encode($cats);
    exit;
}

if (isset($_SESSION['flash_success'])) { $success = $_SESSION['flash_success']; unset($_SESSION['flash_success']); }
if (isset($_SESSION['flash_error']))   { $error   = $_SESSION['flash_error'];   unset($_SESSION['flash_error']); }

// Loan clients
$loan_clients_query = mysqli_query($conn, "
    SELECT name AS client, phone, total_loans AS visits, unpaid_amount AS outstanding
    FROM loan_clients WHERE 1=1 $cid_and ORDER BY updated_at DESC
");
$loan_clients_arr = [];
while ($c = mysqli_fetch_assoc($loan_clients_query)) $loan_clients_arr[] = $c;

// Product owners
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
    <title>External Sale</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/sales.css">
    <style>
        .sale-page-card {
            background: var(--white);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            padding: 10px;
        }
        .form-2col {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0 20px;
        }
        .sale-page-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 18px;
        }
        .sale-page-header h1 { margin: 0; font-size: 18px; }
        .back-btn {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 7px 14px; border-radius: var(--radius);
            background: var(--gray-100); color: var(--dark);
            text-decoration: none; font-size: 13px; font-weight: 500;
            border: 1px solid var(--gray-300);
        }
        .back-btn:hover { background: var(--gray-200); }
        .searchable-select { position: relative; }
        .searchable-select-input {
            width: 100%; padding: 10px 12px;
            border: 1px solid var(--gray-300); border-radius: var(--radius);
            font-size: 14px; background: var(--white); cursor: text;
        }
        .searchable-select-input:focus {
            outline: none; border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37,99,235,.15);
        }
        .searchable-select-dropdown {
            display: none; position: absolute; top: 100%; left: 0; right: 0;
            max-height: 220px; overflow-y: auto; background: var(--white);
            border: 1px solid var(--gray-300); border-top: none;
            border-radius: 0 0 var(--radius) var(--radius);
            z-index: 1000; box-shadow: var(--shadow-md);
        }
        .searchable-select-dropdown.open { display: block; }
        .searchable-select-option { padding: 9px 12px; cursor: pointer; font-size: 14px; }
        .searchable-select-option:hover,
        .searchable-select-option.highlighted { background: var(--gray-100); color: var(--primary); }
        .searchable-select-option.hidden { display: none; }
        .sd-loading {
            padding: 10px 12px; font-size: 13px; color: var(--secondary);
            display: flex; align-items: center; gap: 8px;
        }
        .sd-spinner {
            display: inline-block; width: 14px; height: 14px; flex-shrink: 0;
            border: 2px solid var(--gray-300); border-top-color: var(--primary);
            border-radius: 50%; animation: sd-spin .6s linear infinite;
        }
        @keyframes sd-spin { to { transform: rotate(360deg); } }
        .split-payment-box { border: 1px solid var(--gray-300); border-radius: var(--radius); overflow: hidden; }
        .split-row { display: flex; align-items: center; padding: 8px 12px; gap: 10px; border-bottom: 1px solid var(--gray-100); }
        .split-row:last-child { border-bottom: none; }
        .split-label { width: 70px; font-size: 13px; font-weight: 500; flex-shrink: 0; }
        .split-row input[type="text"] { flex: 1; padding: 6px 10px; border: 1px solid var(--gray-300); border-radius: var(--radius); font-size: 14px; }
        .split-remaining-row { justify-content: space-between; background: var(--gray-50); font-weight: 600; }
        .split-remaining-row.valid  { background: #ecfdf5; color: #059669; }
        .split-remaining-row.invalid { background: #fef2f2; color: #dc2626; }

        /* Step indicator */
        .steps-indicator {
            display: flex;
            align-items: center;
            margin-bottom: 32px;
        }
        .step-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 5px;
        }
        .step-circle {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            border: 2px solid var(--gray-300);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 15px;
            color: var(--secondary);
            background: var(--white);
            transition: background .2s, border-color .2s, color .2s;
        }
        .step-item.active .step-circle {
            border-color: var(--primary);
            background: var(--primary);
            color: #fff;
        }
        .step-item.done .step-circle {
            border-color: #16a34a;
            background: #16a34a;
            color: #fff;
        }
        .step-lbl {
            font-size: 12px;
            font-weight: 600;
            color: var(--gray-400, #9ca3af);
            white-space: nowrap;
        }
        .step-item.active .step-lbl { color: var(--primary); }
        .step-item.done  .step-lbl  { color: #16a34a; }
        .step-connector {
            flex: 1;
            height: 2px;
            background: var(--gray-200);
            margin: 0 10px 20px;
            transition: background .3s;
        }
        .step-connector.done { background: #16a34a; }
        .step-nav {
            display: flex;
            gap: 12px;
            margin-top: 24px;
        }
        .btn-step-back {
            padding: 10px 22px;
            border: 1.5px solid var(--gray-300);
            border-radius: var(--radius);
            background: var(--white);
            color: var(--dark);
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background .15s, border-color .15s;
            flex-shrink: 0;
        }
        .btn-step-back:hover { background: var(--gray-100); border-color: var(--gray-400); }

        /* ── 3-col grid (replaces inline style so media queries can override) ── */
        .form-3col { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0 24px; align-items: start; }

        /* ── Responsive ─────────────────────────────────────────────────────── */
        @media (max-width: 900px) {
            .form-3col { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 640px) {
            .sale-page-card { padding: 14px 12px; }
            .sale-page-header { margin-bottom: 16px; gap: 8px; }
            .sale-page-header h1 { font-size: 16px; }
            .form-2col, .form-3col { grid-template-columns: 1fr; }
            .steps-indicator { margin-bottom: 20px; }
            .step-lbl { display: none; }
            .step-circle { width: 28px; height: 28px; font-size: 12px; }
            .step-connector { margin-bottom: 14px; }
            .step-nav { flex-direction: column; }
            .step-nav > * { width: 100%; }
            .split-row { padding: 7px 8px; gap: 6px; }
            .split-label { width: 52px; font-size: 12px; }
            .price-input-group { flex-wrap: wrap; }
        }
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

        <div class="sale-page-card">
            <div class="sale-page-header">
                <a href="sales.php?tab=external" class="back-btn">&#8592; Back</a>
                <div>
                    <h1>External Sale</h1>
                    <p style="color:var(--secondary);font-size:13px;margin:2px 0 0;">Product not from your stock &mdash; recorded for collection tracking only.</p>
                </div>
            </div>
            <div id="ext_sale_alert" class="alert" style="display:none;margin-bottom:16px;"></div>

            <!-- Step indicator -->
            <div class="steps-indicator">
                <div class="step-item active" id="ext_step_dot_1">
                    <div class="step-circle">1</div>
                    <div class="step-lbl">Product</div>
                </div>
                <div class="step-connector" id="ext_step_connector"></div>
                <div class="step-item" id="ext_step_dot_2">
                    <div class="step-circle">2</div>
                    <div class="step-lbl">Payment</div>
                </div>
            </div>

            <form method="POST" action="sales.php" id="externalSaleForm">
                <input type="hidden" id="ext_product_name" name="ext_product_name">
                <input type="hidden" id="ext_product_id"   name="ext_product_id" value="0">

                <!-- ═══════════ STEP 1 ═══════════ -->
                <div id="ext_step_panel_1">

                    <!-- 3-column: Product | Owner + Qty | Price + Commission + Customer -->
                    <div class="form-3col">

                        <!-- Col 1: Product picker / manual -->
                        <div>
                            <div class="form-group">
                                <label>Category</label>
                                <div class="searchable-select" id="extCatWrap">
                                    <input type="text" class="searchable-select-input" id="ext_cat_search"
                                           placeholder="All categories…" autocomplete="off" readonly style="cursor:pointer;"
                                           onfocus="this.removeAttribute('readonly')">
                                    <div class="searchable-select-dropdown" id="ext_cat_dropdown"></div>
                                </div>
                            </div>
                            <div class="form-group" id="ext_picker_mode">
                                <label>Product*</label>
                                <div class="searchable-select" id="extProductSearchable">
                                    <input type="text" class="searchable-select-input" id="ext_product_search"
                                           placeholder="Search product..." autocomplete="off">
                                    <div class="searchable-select-dropdown" id="ext_product_dropdown"></div>
                                </div>
                                <button type="button" class="btn btn-sm" style="margin-top:6px;background:var(--gray-200);color:var(--dark);"
                                        onclick="extSwitchToManual()">+ Not in list? Type manually</button>
                            </div>
                            <div class="form-group" id="ext_manual_mode" style="display:none;">
                                <label>Product Name*</label>
                                <div style="display:flex;gap:8px;align-items:center;">
                                    <input type="text" id="ext_manual_name" placeholder="Type product name..." style="flex:1;" oninput="extSetManualName(this.value)">
                                    <button type="button" class="btn btn-sm" style="background:var(--gray-200);color:var(--dark);white-space:nowrap;"
                                            onclick="extSwitchToPicker()">Back to list</button>
                                </div>
                            </div>
                        </div>

                        <!-- Col 2: Owner + Quantity -->
                        <div>
                            <input type="hidden" id="ext_owner_name"  name="ext_owner_name">
                            <input type="hidden" id="ext_owner_phone" name="ext_owner_phone">
                            <div class="form-group">
                                <label>Product Owner</label>
                                <?php if ($ext_owners_arr): ?>
                                <div class="searchable-select" id="extOwnerPickerWrap">
                                    <input type="text" class="searchable-select-input" id="ext_owner_search"
                                           placeholder="Search registered owner..." autocomplete="off">
                                    <div class="searchable-select-dropdown" id="ext_owner_dropdown">
                                        <?php foreach ($ext_owners_arr as $o): ?>
                                            <div class="searchable-select-option"
                                                 data-owner="<?php echo htmlspecialchars($o['owner_name'], ENT_QUOTES); ?>"
                                                 data-phone="<?php echo htmlspecialchars($o['owner_phone'] ?? '', ENT_QUOTES); ?>">
                                                <?php echo htmlspecialchars($o['owner_name']); ?>
                                                <?php if ($o['owner_phone']): ?> — <?php echo htmlspecialchars($o['owner_phone']); ?><?php endif; ?>
                                                <small style="color:var(--secondary);"> (<?php echo $o['total_sales']; ?> sale<?php echo $o['total_sales']>1?'s':''; ?>)</small>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <small style="color:var(--secondary);margin-top:3px;display:block;">Pick existing or fill in a new owner below.</small>
                                <?php endif; ?>
                                <div style="display:flex;gap:8px;margin-top:6px;">
                                    <input type="text" id="ext_owner_name_input"  placeholder="Owner name"       style="flex:2;" oninput="extSyncOwner()">
                                    <input type="text" id="ext_owner_phone_input" placeholder="Phone (optional)" style="flex:1;" oninput="extSyncOwner()">
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Quantity*</label>
                                <input type="text" id="ext_quantity" name="ext_quantity" required min="1" value="1" oninput="calcExtTotal()">
                            </div>
                        </div>

                        <!-- Col 3: Price + Commission + Customer -->
                        <div>
                            <div class="form-group">
                                <label>Unit Price (RWF)*</label>
                                <input type="text" id="ext_unit_price" name="ext_unit_price" required min="1" step="1" placeholder="0" oninput="calcExtTotal()">
                            </div>
                            <div class="form-group">
                                <label>My Commission (RWF)</label>
                                <input type="text" id="ext_my_revenue" name="ext_my_revenue" min="0" step="1" value="0" placeholder="Your commission">
                            </div>
                           
                        </div>

                    </div>

                    <!-- Step 1 nav -->
                    <div class="step-nav" style="justify-content:flex-end;">
                        <button type="button" id="ext_next_btn" class="btn btn-primary" disabled onclick="goToExtStep2()" style="padding:10px 28px;">
                            Next &rarr;
                        </button>
                    </div>
                </div>

                <!-- ═══════════ STEP 2 ═══════════ -->
                <div id="ext_step_panel_2" style="display:none;">

                    <!-- Three-column layout -->
                    <div class="form-3col">

                        <!-- Col 1: Sale summary -->
                        <div>
                            <div class="sale-summary" id="ext_summary">
                                <div class="summary-row"><span>Product</span><strong id="ext_sum_product"></strong></div>
                                <div class="summary-row"><span>Quantity</span><strong id="ext_sum_qty"></strong></div>
                                <div class="summary-row"><span>Unit Price</span><strong id="ext_sum_price"></strong></div>
                                <div class="summary-row summary-total"><span>Total Amount</span><strong id="ext_sum_total"></strong></div>
                            </div>
                        </div>

                        <!-- Col 2: Payment shortcuts + loan fields -->
                        <div>
                            <div class="form-group" style="margin:0 0 16px;display:flex;flex-direction:column;gap:8px;">
                                <label style="display:inline-flex;align-items:center;gap:10px;cursor:pointer;padding:10px 14px;border:1.5px solid var(--gray-300);border-radius:var(--radius);background:var(--gray-50);">
                                    <input type="checkbox" id="ext_is_loan" onchange="toggleExtShortcut('loan')" style="width:17px;height:17px;cursor:pointer;accent-color:var(--primary);">
                                    <span style="font-weight:700;font-size:14px;">Is Loan?</span>
                                    <span style="font-size:12px;color:var(--secondary);">Full amount goes to loan</span>
                                </label>
                                <label style="display:inline-flex;align-items:center;gap:10px;cursor:pointer;padding:10px 14px;border:1.5px solid var(--gray-300);border-radius:var(--radius);background:var(--gray-50);">
                                    <input type="checkbox" id="ext_is_cash" onchange="toggleExtShortcut('cash')" style="width:17px;height:17px;cursor:pointer;accent-color:#16a34a;">
                                    <span style="font-weight:700;font-size:14px;">Is Cash?</span>
                                    <span style="font-size:12px;color:var(--secondary);">Full amount goes to cash</span>
                                </label>
                                <label style="display:inline-flex;align-items:center;gap:10px;cursor:pointer;padding:10px 14px;border:1.5px solid var(--gray-300);border-radius:var(--radius);background:var(--gray-50);">
                                    <input type="checkbox" id="ext_is_momo" onchange="toggleExtShortcut('momo')" style="width:17px;height:17px;cursor:pointer;accent-color:#2563eb;">
                                    <span style="font-weight:700;font-size:14px;">Is Momo?</span>
                                    <span style="font-size:12px;color:var(--secondary);">Full amount goes to momo</span>
                                </label>
                            </div>

                            <!-- Loan fields -->
                            <div id="ext_loan_fields" style="display:none;">
                                <?php if ($loan_clients_arr): ?>
                                <div class="form-group">
                                    <label>Existing Client</label>
                                    <div class="searchable-select" id="extClientPickerWrap">
                                        <input type="text" class="searchable-select-input" id="ext_client_picker_search"
                                            placeholder="Search registered client..." autocomplete="off">
                                        <div class="searchable-select-dropdown" id="ext_client_picker_dropdown">
                                            <?php foreach ($loan_clients_arr as $c): ?>
                                                <div class="searchable-select-option"
                                                    data-client="<?php echo htmlspecialchars($c['client'], ENT_QUOTES); ?>"
                                                    data-phone="<?php echo htmlspecialchars($c['phone'], ENT_QUOTES); ?>">
                                                    <?php echo htmlspecialchars($c['client']); ?>
                                                    <?php if ($c['phone']): ?> — <?php echo htmlspecialchars($c['phone']); ?><?php endif; ?>
                                                    <small style="color:var(--secondary);"> (<?php echo $c['visits']; ?> visit<?php echo $c['visits']>1?'s':''; ?>)</small>
                                                    <?php if ($c['outstanding'] > 0): ?><small style="color:#dc2626;font-weight:600;"> · Owes: RWF <?php echo number_format($c['outstanding'],0); ?></small><?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <small style="color:var(--secondary);margin-top:3px;display:block;">Pick to auto-fill, or type a new name below.</small>
                                </div>
                                <?php endif; ?>
                                 <div class="form-group">
                                <label>Customer Name</label>
                                <input type="text" id="ext_customer_name" name="ext_customer_name" value="client" placeholder="Enter customer name">
                            </div>
                                <div class="form-group">
                                    <label>Client Phone*</label>
                                    <input type="text" id="ext_phone" name="ext_phone" placeholder="e.g. 07XXXXXXXX" oninput="calcExtSplit()">
                                </div>
                            </div>
                        </div>

                        <!-- Col 3: Payment breakdown + Save -->
                        <div id="ext_payment_section">
                            <div class="form-group">
                                <label>Payment Breakdown</label>
                                <div class="split-payment-box">
                                    <div class="split-row">
                                        <span class="split-label">Cash</span>
                                        <input type="text" id="ext_cash" name="ext_cash_amount" min="0" step="1" value="0" oninput="calcExtSplit('cash')">
                                    </div>
                                    <div class="split-row">
                                        <span class="split-label">Momo</span>
                                        <input type="text" id="ext_momo" name="ext_momo_amount" min="0" step="1" value="0" oninput="calcExtSplit('momo')">
                                    </div>
                                    <div class="split-row">
                                        <span class="split-label">Loan</span>
                                        <input type="text" id="ext_loan" name="ext_loan_amount" min="0" step="1" value="0" oninput="calcExtSplit('loan')">
                                    </div>
                                    <div class="split-row split-remaining-row" id="ext_remaining_row">
                                        <span class="split-label">Remaining</span>
                                        <span id="ext_remaining">—</span>
                                    </div>
                                </div>
                            </div>
                            <button type="button" id="ext_submit_btn" class="btn btn-primary" disabled onclick="handleExtSubmit()"
                                    style="background:var(--warning,#f59e0b);border-color:var(--warning,#f59e0b);width:100%;padding:12px;">
                                Save External Sale
                            </button>
                        </div>

                    </div>

                    <!-- Step 2 nav -->
                    <div class="step-nav">
                        <button type="button" class="btn-step-back" onclick="goToExtStep1()">&#8592; Back</button>
                    </div>
                </div>

            </form>
        </div>
    </div>
</div>

<script src="script.js"></script>
<script>
var extSelectedCat   = '';
var extAllCategories = [];

// ── External category searchable select ──────────────────────────────────────
(function() {
    var catSearch   = document.getElementById('ext_cat_search');
    var catDropdown = document.getElementById('ext_cat_dropdown');

    function renderCatOpts(filter) {
        catDropdown.innerHTML = '';
        var allDiv = document.createElement('div');
        allDiv.className = 'searchable-select-option';
        allDiv.textContent = '— All Categories —';
        allDiv.addEventListener('click', function() { pickCat('', ''); });
        catDropdown.appendChild(allDiv);
        var q = filter.toLowerCase();
        var matched = extAllCategories.filter(function(c) { return c.toLowerCase().indexOf(q) !== -1; });
        matched.forEach(function(cat) {
            var div = document.createElement('div');
            div.className = 'searchable-select-option';
            div.textContent = cat;
            div.addEventListener('click', function() { pickCat(cat, cat); });
            catDropdown.appendChild(div);
        });
        if (!matched.length) {
            var none = document.createElement('div');
            none.className = 'searchable-select-option';
            none.style.color = 'var(--secondary)'; none.style.cursor = 'default';
            none.textContent = 'No categories found';
            catDropdown.appendChild(none);
        }
    }

    function pickCat(value, label) {
        extSelectedCat = value;
        catSearch.value = label;
        catSearch.setAttribute('readonly', '');
        catDropdown.classList.remove('open');
        document.getElementById('ext_product_search').dispatchEvent(new Event('input'));
        document.getElementById('ext_product_search').focus();
    }

    catSearch.addEventListener('focus', function() { renderCatOpts(''); catDropdown.classList.add('open'); });
    catSearch.addEventListener('input', function() { renderCatOpts(this.value.trim()); catDropdown.classList.add('open'); });
    catSearch.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') { catDropdown.classList.remove('open'); catSearch.setAttribute('readonly',''); }
    });
    document.addEventListener('click', function(e) {
        if (!e.target.closest('#extCatWrap')) {
            catDropdown.classList.remove('open');
            catSearch.value = extSelectedCat || '';
            catSearch.setAttribute('readonly', '');
        }
    });
})();

function loadExtCategories() {
    fetch('sale_external.php?action=get_categories')
        .then(function(r) { return r.json(); })
        .then(function(cats) { extAllCategories = cats; })
        .catch(function() {});
}
loadExtCategories();

// --- Product picker (AJAX) ---
(function() {
    var search   = document.getElementById('ext_product_search');
    var dropdown = document.getElementById('ext_product_dropdown');
    var hi = -1;
    var debounce = null;

    function getOptions() { return dropdown.querySelectorAll('.searchable-select-option'); }

    function renderOptions(items) {
        dropdown.innerHTML = '';
        if (!items.length) {
            dropdown.innerHTML = '<div class="searchable-select-option" style="color:var(--secondary);cursor:default;">No results</div>';
            return;
        }
        items.forEach(function(p) {
            var label = (p.category ? p.category + '-' : '') + p.name;
            var hint  = '';
            if (p.bulk_price > 0)   hint += ' bulk:'   + Number(p.bulk_price).toLocaleString();
            if (p.retail_price > 0) hint += ' retail:' + Number(p.retail_price).toLocaleString();
            var div = document.createElement('div');
            div.className = 'searchable-select-option';
            div.dataset.id     = p.id;
            div.dataset.name   = label;
            div.dataset.bulk   = p.bulk_price;
            div.dataset.retail = p.retail_price;
            div.innerHTML = escHtmlExt(label) + (hint ? '<small style="color:var(--secondary);">' + hint + '</small>' : '');
            div.addEventListener('click', function() { pick(div); });
            dropdown.appendChild(div);
        });
        hi = -1;
    }

    function showLoading() {
        dropdown.innerHTML = '<div class="sd-loading"><span class="sd-spinner"></span> Searching…</div>';
        dropdown.classList.add('open');
    }

    function doSearch(q) {
        showLoading();
        var url = 'sale_external.php?action=search_products&q=' + encodeURIComponent(q);
        if (extSelectedCat) url += '&cat=' + encodeURIComponent(extSelectedCat);
        fetch(url)
            .then(function(r) { return r.json(); })
            .then(function(data) { renderOptions(data); dropdown.classList.add('open'); })
            .catch(function() {
                dropdown.innerHTML = '<div class="searchable-select-option" style="color:var(--danger);cursor:default;">Failed to load</div>';
            });
    }

    search.addEventListener('focus', function() {
        if (!search.value.trim()) doSearch('');
        else dropdown.classList.add('open');
    });
    search.addEventListener('input', function() {
        document.getElementById('ext_product_name').value = '';
        document.getElementById('ext_product_id').value   = '0';
        hi = -1;
        clearTimeout(debounce);
        debounce = setTimeout(function() { doSearch(search.value.trim()); }, 250);
    });
    search.addEventListener('keydown', function(e) {
        var vis = getOptions();
        if (e.key === 'ArrowDown') { e.preventDefault(); hi = Math.min(hi+1, vis.length-1); hl(vis); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); hi = Math.max(hi-1, 0); hl(vis); }
        else if (e.key === 'Enter')  { e.preventDefault(); if (hi>=0&&vis[hi]) pick(vis[hi]); }
        else if (e.key === 'Escape') dropdown.classList.remove('open');
    });
    document.addEventListener('click', function(e) {
        if (!e.target.closest('#extProductSearchable')) dropdown.classList.remove('open');
    });

    function hl(vis) {
        getOptions().forEach(function(o) { o.classList.remove('highlighted'); });
        if (vis[hi]) { vis[hi].classList.add('highlighted'); vis[hi].scrollIntoView({block:'nearest'}); }
    }
    function pick(opt) {
        var name   = opt.dataset.name;
        var id     = opt.dataset.id   || '0';
        var bulk   = parseFloat(opt.dataset.bulk)   || 0;
        var retail = parseFloat(opt.dataset.retail) || 0;
        document.getElementById('ext_product_name').value = name;
        document.getElementById('ext_product_id').value   = id;
        search.value = name;
        dropdown.classList.remove('open'); hi = -1;
        var priceEl = document.getElementById('ext_unit_price');
        if ((priceEl.value === '' || parseFloat(priceEl.value) === 0) && (bulk > 0 || retail > 0)) {
            priceEl.value = bulk > 0 ? bulk : retail;
        }
        calcExtTotal();
    }

    function escHtmlExt(s) {
        return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
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
    document.getElementById('ext_product_name').value = '';
    calcExtTotal();
}
function extSetManualName(val) {
    document.getElementById('ext_product_name').value = val.trim();
    calcExtTotal();
}

// --- Owner picker ---
(function() {
    var wrap = document.getElementById('extOwnerPickerWrap');
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
        else if (e.key === 'Enter')  { e.preventDefault(); if (hi>=0&&vis[hi]) pick(vis[hi]); }
        else if (e.key === 'Escape') dropdown.classList.remove('open');
    });
    document.addEventListener('click', function(e) {
        if (!e.target.closest('#extOwnerPickerWrap')) dropdown.classList.remove('open');
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

function extSyncOwner() {
    document.getElementById('ext_owner_name').value  = document.getElementById('ext_owner_name_input').value.trim();
    document.getElementById('ext_owner_phone').value = document.getElementById('ext_owner_phone_input').value.trim();
    var search = document.getElementById('ext_owner_search');
    if (search) search.value = '';
}

// --- Loan client picker ---
(function() {
    var wrap = document.getElementById('extClientPickerWrap');
    if (!wrap) return;
    var search   = document.getElementById('ext_client_picker_search');
    var dropdown = document.getElementById('ext_client_picker_dropdown');
    var options  = dropdown.querySelectorAll('.searchable-select-option');
    var hi = -1;

    search.addEventListener('focus', function() { dropdown.classList.add('open'); filter(); });
    search.addEventListener('input', function() { dropdown.classList.add('open'); hi = -1; filter(); });
    search.addEventListener('keydown', function(e) {
        var vis = dropdown.querySelectorAll('.searchable-select-option:not(.hidden)');
        if (e.key === 'ArrowDown') { e.preventDefault(); hi = Math.min(hi+1, vis.length-1); hl(vis); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); hi = Math.max(hi-1, 0); hl(vis); }
        else if (e.key === 'Enter')  { e.preventDefault(); if (hi>=0&&vis[hi]) pick(vis[hi]); }
        else if (e.key === 'Escape') dropdown.classList.remove('open');
    });
    document.addEventListener('click', function(e) {
        if (!e.target.closest('#extClientPickerWrap')) dropdown.classList.remove('open');
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
        document.getElementById('ext_customer_name').value = opt.getAttribute('data-client');
        var phoneEl = document.getElementById('ext_phone');
        phoneEl.value = opt.getAttribute('data-phone');
        phoneEl.dispatchEvent(new Event('input'));
        search.value = opt.getAttribute('data-client');
        dropdown.classList.remove('open'); hi = -1;
    }
})();

// --- Step navigation ---
var extCoreValid = false;

function goToExtStep2() {
    if (!extCoreValid) return;
    document.getElementById('ext_step_panel_1').style.display = 'none';
    document.getElementById('ext_step_panel_2').style.display = 'block';
    document.getElementById('ext_step_dot_1').classList.remove('active');
    document.getElementById('ext_step_dot_1').classList.add('done');
    document.getElementById('ext_step_dot_2').classList.add('active');
    document.getElementById('ext_step_connector').classList.add('done');
    calcExtSplit();
}

function goToExtStep1() {
    document.getElementById('ext_step_panel_2').style.display = 'none';
    document.getElementById('ext_step_panel_1').style.display = 'block';
    document.getElementById('ext_step_dot_2').classList.remove('active');
    document.getElementById('ext_step_dot_1').classList.remove('done');
    document.getElementById('ext_step_dot_1').classList.add('active');
    document.getElementById('ext_step_connector').classList.remove('done');
}

// --- Core logic ---
function calcExtTotal() {
    var name  = document.getElementById('ext_product_name').value.trim();
    var qty   = parseInt(document.getElementById('ext_quantity').value) || 0;
    var price = parseFloat(document.getElementById('ext_unit_price').value) || 0;
    var total = qty * price;
    var valid = name.length > 0 && qty > 0 && price > 0;
    extCoreValid = valid;
    document.getElementById('ext_next_btn').disabled = !valid;

    if (valid) {
        document.getElementById('ext_sum_product').textContent = name;
        document.getElementById('ext_sum_qty').textContent = qty;
        document.getElementById('ext_sum_price').textContent = 'RWF ' + price.toLocaleString();
        document.getElementById('ext_sum_total').textContent = 'RWF ' + total.toLocaleString();
        var isLoan = document.getElementById('ext_is_loan').checked;
        var isCash = document.getElementById('ext_is_cash').checked;
        var isMomo = document.getElementById('ext_is_momo').checked;
        var cash = parseFloat(document.getElementById('ext_cash').value)||0;
        var momo = parseFloat(document.getElementById('ext_momo').value)||0;
        var loan = parseFloat(document.getElementById('ext_loan').value)||0;
        if (isLoan) {
            document.getElementById('ext_cash').value = 0;
            document.getElementById('ext_momo').value = 0;
            document.getElementById('ext_loan').value = total;
        } else if (isCash) {
            document.getElementById('ext_cash').value = total;
            document.getElementById('ext_momo').value = 0;
            document.getElementById('ext_loan').value = 0;
        } else if (isMomo) {
            document.getElementById('ext_cash').value = 0;
            document.getElementById('ext_momo').value = total;
            document.getElementById('ext_loan').value = 0;
        } else if (cash===0 && momo===0 && loan===0) {
            document.getElementById('ext_momo').value = total;
        }
    } else {
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
    var cash = parseFloat(cashEl.value)||0;
    var momo = parseFloat(momoEl.value)||0;
    var loan = parseFloat(loanEl.value)||0;

    if (changed === 'cash')      { momo = Math.max(0, total-cash-loan); momoEl.value = momo; }
    else if (changed === 'momo') { loan = Math.max(0, total-cash-momo); loanEl.value = loan; }
    else if (changed === 'loan') { momo = Math.max(0, total-cash-loan); momoEl.value = momo; }

    var remaining = Math.round(total - cash - momo - loan);
    var splitOk   = extCoreValid && remaining === 0;
    var remEl  = document.getElementById('ext_remaining');
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

function toggleExtShortcut(type) {
    if (type !== 'loan') document.getElementById('ext_is_loan').checked = false;
    if (type !== 'cash') document.getElementById('ext_is_cash').checked = false;
    if (type !== 'momo') document.getElementById('ext_is_momo').checked = false;

    var qty   = parseInt(document.getElementById('ext_quantity').value)   || 0;
    var price = parseFloat(document.getElementById('ext_unit_price').value) || 0;
    var total = qty * price;
    var checked = document.getElementById('ext_is_' + type).checked;

    if (checked) {
        document.getElementById('ext_cash').value = type === 'cash' ? total : 0;
        document.getElementById('ext_momo').value = type === 'momo' ? total : 0;
        document.getElementById('ext_loan').value = type === 'loan' ? total : 0;
    }
    calcExtSplit();
    if (type === 'loan' && document.getElementById('ext_is_loan').checked) {
        var lf = document.getElementById('ext_loan_fields');
        setTimeout(function() { lf.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 50);
    }
}

function handleExtSubmit() {
    var name  = document.getElementById('ext_product_name').value.trim();
    var qty   = document.getElementById('ext_quantity').value;
    var price = parseFloat(document.getElementById('ext_unit_price').value);
    var total = qty * price;
    var cash  = parseFloat(document.getElementById('ext_cash').value)||0;
    var momo  = parseFloat(document.getElementById('ext_momo').value)||0;
    var loan  = parseFloat(document.getElementById('ext_loan').value)||0;
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
    if (!ok) return;

    var btn = document.getElementById('ext_submit_btn');
    btn.disabled = true; btn.textContent = 'Saving...';

    var fd = new FormData(document.getElementById('externalSaleForm'));
    fd.append('external_sale', '1');
    fd.append('ajax', '1');

    fetch('sales.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            showSaleToast(res.message, res.success);
            if (res.success) {
                location.reload();
            }
            btn.textContent = 'Save External Sale';
            btn.disabled = true;
        })
        .catch(function() {
            showSaleToast('Network error. Please try again.', false);
            btn.textContent = 'Save External Sale';
            btn.disabled = false;
        });
}
</script>
</body>
</html>
