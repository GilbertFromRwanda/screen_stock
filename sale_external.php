<?php
require_once 'config.php';
if (!isLoggedIn()) redirect('login.php');
if (!hasPermission('sales', 'create')) { $_SESSION['flash_error'] = "You don't have permission to record external sales."; redirect('dashboard.php'); }

$cid_sql = cidSql(); $cid_and = cidAnd();

// Product search/categories and the loan-client picker are loaded
// client-side from DataCache (js/data-cache.js) instead of these per-page
// AJAX endpoints.

if (isset($_SESSION['flash_success'])) { $success = $_SESSION['flash_success']; unset($_SESSION['flash_success']); }
if (isset($_SESSION['flash_error']))   { $error   = $_SESSION['flash_error'];   unset($_SESSION['flash_error']); }

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
            box-shadow: 0 0 0 3px rgba(16,48,96,.15);
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

        /* ── Client card (step 1) ───────────────────────────────────────────── */
        .client-card {
            display: none; background: #e8edf5; border: 1px solid #c9d6ea;
            border-radius: var(--radius); padding: 12px 16px;
            align-items: center; justify-content: space-between; gap: 10px;
        }
        .client-card.show { display: flex; }
        .client-card-name { font-weight: 700; color: #103060; font-size: 15px; }
        .client-card-meta { color: var(--secondary); font-size: 12px; margin-top: 3px; }
        .client-card-clear { background: none; border: none; color: #94a3b8; cursor: pointer; font-size: 20px; line-height: 1; padding: 0 4px; flex-shrink: 0; }
        .client-card-clear:hover { color: #dc2626; }

        /* ── Payment shortcut chips (compact) ─────────────────────────────────── */
        .shortcut-chips { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
        .shortcut-chip {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 6px 12px; border: 1.5px solid var(--gray-300); border-radius: 999px;
            background: var(--gray-50); cursor: pointer; font-size: 13px; font-weight: 600;
            white-space: nowrap;
        }
        .shortcut-chip input { width: 15px; height: 15px; cursor: pointer; margin: 0; }

        /* ── Desktop 3-column layout: client/recent-sales, sale details, cart/payment ── */
        .sale-3col { display: grid; grid-template-columns: 280px 1fr 340px; gap: 20px; align-items: start; }
        .sale-col-client, .sale-col-details, .sale-col-cart {
            border-radius: var(--radius-lg); padding: 16px; border: 1px solid transparent;
        }
        .sale-col-client  { background: #f8fafc; border-color: #e2e8f0; }
        .sale-col-details { background: #fdf4ff; border-color: #f3e8ff; }
        .sale-col-cart    { background: #f0fdf4; border-color: #bbf7d0; position: sticky; top: 16px; }
        @media (max-width: 1150px) {
            .sale-3col { grid-template-columns: 1fr 1fr; }
            .sale-col-cart { grid-column: 1 / -1; position: static; }
        }
        @media (max-width: 900px) {
            .sale-3col { grid-template-columns: 1fr; }
            .sale-col-cart { grid-column: auto; }
        }

        .cart-panel { border: 1px solid var(--gray-200); border-radius: var(--radius-lg); overflow: hidden; }
        .cart-header { display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; background: var(--gray-50); border-bottom: 1px solid var(--gray-200); font-size: 13px; font-weight: 700; }
        .cart-badge { background: var(--primary); color: #fff; font-size: 11px; font-weight: 700; min-width: 20px; height: 20px; border-radius: 10px; padding: 0 5px; display: inline-flex; align-items: center; justify-content: center; }
        .cart-badge.zero { background: var(--gray-300); }
        .cart-body { min-height: 80px; max-height: 380px; overflow-y: auto; }
        .cart-empty { padding: 28px 16px; text-align: center; font-size: 13px; color: var(--secondary); line-height: 1.6; }
        .cart-item { display: flex; align-items: flex-start; padding: 10px 14px; gap: 8px; border-bottom: 1px solid var(--gray-100); }
        .cart-item:last-child { border-bottom: none; }
        .cart-item-info { flex: 1; min-width: 0; }
        .cart-item-name { font-size: 13px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .cart-item-sub { font-size: 12px; color: var(--secondary); margin-top: 2px; }
        .cart-item-right { flex-shrink: 0; display: flex; flex-direction: column; align-items: flex-end; gap: 4px; }
        .cart-item-total { font-size: 13px; font-weight: 700; }
        .cart-rm { background: none; border: none; color: #cbd5e1; cursor: pointer; font-size: 15px; padding: 0; line-height: 1; }
        .cart-rm:hover { color: #ef4444; }
        .cart-foot { display: flex; justify-content: space-between; align-items: center; padding: 13px 16px; background: #e8edf5; border-top: 1px solid #c9d6ea; }
        .cart-foot-lbl { font-size: 12px; font-weight: 700; color: #103060; }
        .cart-foot-val { font-size: 20px; font-weight: 800; color: #0a2148; }
        .add-item-btn {
            width: 100%; padding: 11px; margin-top: 4px; background: #0ea5e9;
            color: #fff; border: none; border-radius: var(--radius); font-size: 14px; font-weight: 700; cursor: pointer;
        }
        .add-item-btn:hover { background: #0284c7; }

        /* ── Recent Sales panel ───────────────────────────────────────────────── */
        .recent-sales-panel { background: var(--white); border: 1px solid var(--gray-200); border-radius: var(--radius-lg); margin-bottom: 20px; overflow: hidden; }
        .recent-sales-header { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; background: var(--gray-100); cursor: pointer; font-size: 13px; font-weight: 700; gap: 8px; }
        .recent-sales-header:hover { background: var(--gray-200); }
        .recent-sales-header-lbl { display: flex; align-items: center; gap: 8px; }
        .recent-toggle-icon { font-size: 11px; color: var(--secondary); }
        .recent-sales-body { padding: 12px 16px; border-top: 1px solid var(--gray-200); }
        .recent-sales-list { background: var(--white); max-height: 260px; overflow-y: auto; }
        .recent-sale-row { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; padding: 8px 6px; border-bottom: 1px solid var(--gray-100); border-left: 3px solid transparent; font-size: 13px; cursor: pointer; border-radius: 6px; }
        .recent-sale-row:last-child { border-bottom: none; }
        .recent-sale-row:hover { background: var(--gray-100); }
        .recent-sale-row.selected { background: #e8edf5; border-left-color: var(--primary); }
        .recent-sale-row.selected:hover { background: #e8edf5; }
        .recent-sale-main { min-width: 0; }
        .recent-sale-name { font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .recent-sale-sub { font-size: 12px; color: var(--secondary); margin-top: 2px; }
        .recent-sale-right { flex-shrink: 0; text-align: right; }
        .recent-sale-total { font-weight: 700; }
        .recent-sale-time { font-size: 11px; color: var(--secondary); margin-top: 2px; }

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

            <form method="POST" action="sales.php" id="externalSaleForm">
                <input type="hidden" id="ext_items_json" name="items_json" value="[]">
                <input type="hidden" id="ext_product_name" name="ext_product_name">
                <input type="hidden" id="ext_product_id"   name="ext_product_id" value="0">

                <div class="sale-3col">
                    <!-- ═══════════ Column 1: Client + Recent Sales ═══════════ -->
                    <div class="sale-col-client">
                        <div id="ext_step_panel_1">

                            <div class="client-card" id="ext_client_card">
                                <div>
                                    <div class="client-card-name" id="ext_client_card_name"></div>
                                    <div class="client-card-meta" id="ext_client_card_meta"></div>
                                </div>
                                <button type="button" class="client-card-clear" onclick="clearExtClient()" title="Change client">&times;</button>
                            </div>

                            <div id="ext_client_select_area">
                                <div class="form-group" id="extClientPickerGroup" style="display:none;">
                                    <label>Existing Client</label>
                                    <div class="searchable-select" id="extClientPickerWrap">
                                        <input type="text" class="searchable-select-input" id="ext_client_picker_search"
                                            placeholder="Search registered client..." autocomplete="off">
                                        <div class="searchable-select-dropdown" id="ext_client_picker_dropdown"></div>
                                    </div>
                                    <small style="color:var(--secondary);margin-top:3px;display:block;">Pick to auto-fill, or type a new name below.</small>
                                </div>
                                <div id="ext_client_fields">
                                    <div class="form-group">
                                        <label>Client Name</label>
                                        <input type="text" id="ext_customer_name" name="ext_customer_name" placeholder="Enter customer name (defaults to &quot;client&quot;)">
                                    </div>
                                    <div class="form-group">
                                        <label>Client Phone <small style="font-weight:400;color:var(--secondary);">(required only for loans)</small></label>
                                        <input type="text" id="ext_phone" name="ext_phone" placeholder="e.g. 07XXXXXXXX">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="recent-sales-panel" id="ext_recent_panel">
                            <div class="recent-sales-header" onclick="toggleRecentSales('ext')">
                                <span class="recent-sales-header-lbl">Recent Sales <span id="ext_recent_badge" class="cart-badge zero">0</span></span>
                                <span class="recent-toggle-icon" id="ext_recent_toggle_icon">&#9650;</span>
                            </div>
                            <div class="recent-sales-body" id="ext_recent_body">
                                <input type="text" class="searchable-select-input" id="ext_recent_search" placeholder="Search recent sales (product or customer)...">
                                <div id="ext_recent_list" class="recent-sales-list" style="margin-top:10px;">
                                    <div class="cart-empty">Loading&hellip;</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ═══════════ Column 2: Sale Details ═══════════ -->
                    <div class="sale-col-details">
                        <div id="ext_step_panel_2">
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
                                <input type="number" id="ext_quantity" name="ext_quantity" required min="1" value="1" oninput="calcExtValidity()">
                            </div>
                            <div class="form-group">
                                <label>Unit Price (RWF)*</label>
                                <input type="number" id="ext_unit_price" name="ext_unit_price" required min="1" step="1" placeholder="0" oninput="calcExtValidity()">
                            </div>
                            <div class="form-group">
                                <label>My Commission (RWF)</label>
                                <input type="number" id="ext_my_revenue" name="ext_my_revenue" min="0" step="1" value="0" placeholder="Your commission">
                            </div>

                            <button type="button" class="add-item-btn" id="ext_add_cart_btn" disabled onclick="addExtToCart()">
                                + Add to Sale
                            </button>
                        </div>
                    </div>

                    <!-- ═══════════ Column 3: Cart + Payment ═══════════ -->
                    <div class="sale-col-cart">
                        <div class="cart-panel">
                            <div class="cart-header">
                                Items to Sell
                                <span id="ext_cart_badge" class="cart-badge zero">0</span>
                            </div>
                            <div class="cart-body" id="ext_cart_body">
                                <div class="cart-empty">No items yet.<br>Search and add products from the left.</div>
                            </div>
                            <div class="cart-foot">
                                <span class="cart-foot-lbl">Sale Total</span>
                                <span class="cart-foot-val" id="ext_cart_total">RWF 0</span>
                            </div>
                        </div>

                        <!-- ═══════════ Payment (under the cart card) ═══════════ -->
                        <div id="ext_step_panel_3" style="margin-top:16px;">
                            <div class="shortcut-chips">
                                <label class="shortcut-chip" title="Full amount goes to loan">
                                    <input type="checkbox" id="ext_is_loan" onchange="toggleExtShortcut('loan')" style="accent-color:var(--primary);">
                                    Is Loan?
                                </label>
                                <label class="shortcut-chip" title="Full amount goes to cash">
                                    <input type="checkbox" id="ext_is_cash" onchange="toggleExtShortcut('cash')" style="accent-color:#16a34a;">
                                    Is Cash?
                                </label>
                                <label class="shortcut-chip" title="Full amount goes to momo">
                                    <input type="checkbox" id="ext_is_momo" onchange="toggleExtShortcut('momo')" style="accent-color:#103060;">
                                    Is Momo?
                                </label>
                            </div>

                            <div id="ext_loan_phone_warn" style="display:none;font-size:12px;color:#dc2626;background:#fef2f2;border:1px solid #fca5a5;border-radius:var(--radius);padding:9px 12px;margin-bottom:12px;">
                                &#9888; Client phone is required when part of the sale goes to loan. Add it above.
                            </div>

                            <div id="ext_payment_section">
                                <div class="form-group">
                                    <label>Payment Breakdown</label>
                                    <div class="split-payment-box">
                                        <div class="split-row">
                                            <span class="split-label">Cash</span>
                                            <input type="number" id="ext_cash" name="ext_cash_amount" min="0" step="1" value="0" oninput="calcExtSplit('cash')">
                                        </div>
                                        <div class="split-row">
                                            <span class="split-label">Momo</span>
                                            <input type="number" id="ext_momo" name="ext_momo_amount" min="0" step="1" value="0" oninput="calcExtSplit('momo')">
                                        </div>
                                        <div class="split-row">
                                            <span class="split-label">Loan</span>
                                            <input type="number" id="ext_loan" name="ext_loan_amount" min="0" step="1" value="0" oninput="calcExtSplit('loan')">
                                        </div>
                                        <div class="split-row split-remaining-row" id="ext_remaining_row">
                                            <span class="split-label">Remaining</span>
                                            <span id="ext_remaining">—</span>
                                        </div>
                                    </div>
                                </div>
                                <button type="button" id="ext_submit_btn" class="btn btn-primary" disabled onclick="handleExtSubmit()"
                                        style="background:var(--warning,#f59e0b);border-color:var(--warning,#f59e0b);width:100%;padding:12px;">
                                    Save Sale
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

            </form>
        </div>
    </div>
</div>

<script>window.APP_COMPANY_ID = <?php echo json_encode(cid()); ?>;</script>
<script src="js/data-cache.js?v=<?php echo filemtime(__DIR__ . '/js/data-cache.js'); ?>"></script>
<script src="js/sale-queue.js?v=<?php echo filemtime(__DIR__ . '/js/sale-queue.js'); ?>"></script>
<script src="script.js"></script>
<script>
SaleQueue.init();
if (window.matchMedia('(max-width: 640px)').matches) toggleRecentSales('ext');
var extSelectedCat   = '';
var extAllCategories = [];
var extCart          = [];

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
        document.getElementById('ext_product_id').value   = '0';
        document.getElementById('ext_product_name').value = '';
        var ps = document.getElementById('ext_product_search');
        ps.value = '';
        ps.dispatchEvent(new Event('input'));
        ps.focus();
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
    DataCache.getCategoriesList().then(function(cats) { extAllCategories = cats.map(function(c) { return c.name; }); });
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
        var term = q.toLowerCase();
        DataCache.getProducts()
            .then(function(list) {
                var data = list.filter(function(p) {
                    if (extSelectedCat && p.category !== extSelectedCat) return false;
                    if (term && (p.search_text || '').toLowerCase().indexOf(term) === -1) return false;
                    return true;
                }).slice(0, 60).map(function(p) {
                    return { id: p.id, name: p.name, category: p.category,
                             bulk_price: p.bulk_price, retail_price: p.retail_price };
                });
                renderOptions(data);
                dropdown.classList.add('open');
            })
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
        calcExtValidity();
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
    calcExtValidity();
}
function extSwitchToPicker() {
    document.getElementById('ext_manual_mode').style.display = 'none';
    document.getElementById('ext_picker_mode').style.display = 'block';
    document.getElementById('ext_product_name').value = '';
    calcExtValidity();
}
function extSetManualName(val) {
    document.getElementById('ext_product_name').value = val.trim();
    calcExtValidity();
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

function initLoanClientPicker(wrapId, searchId, dropdownId, clientInputId, phoneInputId, afterPick) {
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
        else if (e.key === 'ArrowUp') { e.preventDefault(); hi = Math.max(hi-1, 0); hl(vis); }
        else if (e.key === 'Enter')  { e.preventDefault(); if (hi>=0&&vis[hi]) pick(vis[hi]); }
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
        // Setting .value directly doesn't fire 'input', so the payment-split
        // recheck (which clears the "phone required for loan" warning) never
        // ran on its own — dispatch it so picking a client re-validates immediately.
        phoneEl.dispatchEvent(new Event('input'));
        search.value = opt.getAttribute('data-client');
        dropdown.classList.remove('open'); hi = -1;
        if (afterPick) afterPick(opt);
    }
}

// Shows the compact "picked client" card in place of the search/name/phone
// fields. `prefix` matches the id prefix used by that page's client fields
// (bulk/retail/ext).
function showClientCard(prefix, opt) {
    var name        = opt.getAttribute('data-client');
    var phone       = opt.getAttribute('data-phone');
    var visits      = parseInt(opt.getAttribute('data-visits')) || 0;
    var outstanding = parseFloat(opt.getAttribute('data-outstanding')) || 0;

    var meta = [];
    if (phone) meta.push(phone);
    meta.push(visits + ' visit' + (visits !== 1 ? 's' : ''));
    if (outstanding > 0) meta.push('Owes RWF ' + outstanding.toLocaleString());

    document.getElementById(prefix + '_client_card_name').textContent = name;
    document.getElementById(prefix + '_client_card_meta').textContent = meta.join(' · ');
    document.getElementById(prefix + '_client_card').classList.add('show');
    // Only collapse the "Existing Client" search box — the name/phone inputs
    // below it stay visible (and now hold the picked client's values) even
    // after a client is selected.
    var pickerGroup = document.getElementById(prefix + 'ClientPickerGroup');
    if (pickerGroup) pickerGroup.style.display = 'none';
}

function _escH(s) { return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

DataCache.getClients().then(function(list) {
    if (!list.length) return;
    document.getElementById('extClientPickerGroup').style.display = '';
    document.getElementById('ext_client_picker_dropdown').innerHTML = list.map(function(c) {
        var visits = parseInt(c.total_loans) || 0;
        var outstanding = parseFloat(c.unpaid_amount) || 0;
        return '<div class="searchable-select-option" data-client="' + _escH(c.name) +
            '" data-phone="' + _escH(c.phone) + '" data-visits="' + visits + '" data-outstanding="' + outstanding + '">' + _escH(c.name) +
            (c.phone ? ' — ' + _escH(c.phone) : '') +
            '<small style="color:var(--secondary);"> (' + visits + ' visit' + (visits !== 1 ? 's' : '') + ')</small>' +
            (outstanding > 0 ? '<small style="color:#dc2626;font-weight:600;"> · Owes: RWF ' + outstanding.toLocaleString() + '</small>' : '') +
            '</div>';
    }).join('');
    initLoanClientPicker('extClientPickerWrap', 'ext_client_picker_search', 'ext_client_picker_dropdown', 'ext_customer_name', 'ext_phone',
        function(opt) { showClientCard('ext', opt); });
});



function clearExtClient() {
    document.getElementById('ext_customer_name').value = '';
    document.getElementById('ext_phone').value = '';
    document.getElementById('ext_client_card').classList.remove('show');
    var pickerGroup = document.getElementById('extClientPickerGroup');
    if (pickerGroup) pickerGroup.style.display = '';
    document.getElementById('ext_client_picker_search') && (document.getElementById('ext_client_picker_search').value = '');
}

// --- Core logic ---
function calcExtValidity() {
    var name  = document.getElementById('ext_product_name').value.trim();
    var qty   = parseInt(document.getElementById('ext_quantity').value) || 0;
    var price = parseFloat(document.getElementById('ext_unit_price').value) || 0;
    var valid = name.length > 0 && qty > 0 && price > 0;
    document.getElementById('ext_add_cart_btn').disabled = !valid;
}

function addExtToCart() {
    var name  = document.getElementById('ext_product_name').value.trim();
    var pid   = parseInt(document.getElementById('ext_product_id').value) || 0;
    var qty   = parseInt(document.getElementById('ext_quantity').value) || 0;
    var price = parseFloat(document.getElementById('ext_unit_price').value) || 0;
    var myRev = parseFloat(document.getElementById('ext_my_revenue').value) || 0;
    var ownerName  = document.getElementById('ext_owner_name').value.trim();
    var ownerPhone = document.getElementById('ext_owner_phone').value.trim();
    if (!name || qty < 1 || price < 1) return;

    extCart.push({ pid: pid, name: name, qty: qty, price: price, myRevenue: myRev, ownerName: ownerName, ownerPhone: ownerPhone });
    renderExtCart();
    showSaleToast('"' + name + '" added to sale.', true);

    // Reset picker for next product (keep owner selection — often the same supplier for multiple items)
    document.getElementById('ext_product_search').value = '';
    document.getElementById('ext_product_name').value = '';
    document.getElementById('ext_product_id').value = '0';
    document.getElementById('ext_manual_name').value = '';
    document.getElementById('ext_quantity').value = '1';
    document.getElementById('ext_unit_price').value = '';
    document.getElementById('ext_my_revenue').value = '0';
    calcExtValidity();
}

function removeExtCartItem(idx) {
    extCart.splice(idx, 1);
    renderExtCart();
}

function renderExtCart() {
    var total = extCart.reduce(function(s,i){ return s + i.qty*i.price; }, 0);
    var badge = document.getElementById('ext_cart_badge');
    badge.textContent = extCart.length;
    badge.className   = 'cart-badge' + (extCart.length===0 ? ' zero' : '');
    document.getElementById('ext_cart_total').textContent = 'RWF ' + Math.round(total).toLocaleString();

    var body = document.getElementById('ext_cart_body');
    if (extCart.length === 0) {
        body.innerHTML = '<div class="cart-empty">No items yet.<br>Search and add products from the left.</div>';
    } else {
        body.innerHTML = extCart.map(function(item, idx){
            var sub = item.qty * item.price;
            var ownerTag = item.ownerName ? (' &middot; ' + escExtHtml(item.ownerName)) : '';
            return '<div class="cart-item">' +
                '<div class="cart-item-info">' +
                    '<div class="cart-item-name">' + escExtHtml(item.name) + '</div>' +
                    '<div class="cart-item-sub">' + item.qty.toLocaleString() + ' &times; RWF ' + item.price.toLocaleString() + ownerTag + '</div>' +
                '</div>' +
                '<div class="cart-item-right">' +
                    '<span class="cart-item-total">RWF ' + Math.round(sub).toLocaleString() + '</span>' +
                    '<button type="button" class="cart-rm" onclick="removeExtCartItem(' + idx + ')" title="Remove">&times;</button>' +
                '</div>' +
            '</div>';
        }).join('');
    }

    document.getElementById('ext_items_json').value = JSON.stringify(
        extCart.map(function(i){
            return {
                product_id: i.pid, product_name: i.name, quantity: i.qty, unit_price: i.price,
                my_revenue: i.myRevenue, owner_name: i.ownerName, owner_phone: i.ownerPhone
            };
        })
    );
    updateExtPaymentDefaults();
}

function escExtHtml(s) { return (s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

// ── Recent Sales panel, backed by DataCache (instant from IndexedDB, refreshed
// in the background when the server reports newer data) ────────────────────
var extRecentSales = [];

function toggleRecentSales(prefix) {
    var body = document.getElementById(prefix + '_recent_body');
    var icon = document.getElementById(prefix + '_recent_toggle_icon');
    var open = body.style.display !== 'none';
    body.style.display = open ? 'none' : 'block';
    icon.innerHTML = open ? '&#9660;' : '&#9650;';
}

function relSaleTime(ts) {
    var d = new Date(String(ts||'').replace(' ', 'T'));
    if (isNaN(d.getTime())) return '';
    var diff = Math.floor((Date.now() - d.getTime()) / 1000);
    if (diff < 60) return 'just now';
    if (diff < 3600) return Math.floor(diff/60) + 'm ago';
    if (diff < 86400) return Math.floor(diff/3600) + 'h ago';
    if (diff < 172800) return 'Yesterday';
    return d.toLocaleDateString();
}

function loadExtRecentSales(force) {
    DataCache.getRecentSales('external', force ? {force:true} : {}).then(function(list) {
        extRecentSales = list || [];
        renderExtRecentSales(document.getElementById('ext_recent_search').value.trim());
    });
}
loadExtRecentSales();

document.getElementById('ext_recent_search').addEventListener('input', function() {
    renderExtRecentSales(this.value.trim());
});

function renderExtRecentSales(filter) {
    var term = (filter || '').toLowerCase();
    var rows = extRecentSales.filter(function(r) {
        if (!term) return true;
        return (r.product_name || '').toLowerCase().indexOf(term) !== -1 ||
               (r.customer_name || '').toLowerCase().indexOf(term) !== -1 ||
               (r.owner_name || '').toLowerCase().indexOf(term) !== -1;
    });
    var badge = document.getElementById('ext_recent_badge');
    badge.textContent = extRecentSales.length;
    badge.className = 'cart-badge' + (extRecentSales.length === 0 ? ' zero' : '');

    var list = document.getElementById('ext_recent_list');
    if (!rows.length) {
        list.innerHTML = '<div class="cart-empty">' + (extRecentSales.length ? 'No matches.' : 'No recent sales yet.') + '</div>';
        return;
    }
    list.innerHTML = rows.map(function(r) {
        var qty = parseInt(r.quantity) || 0;
        var ownerTag = r.owner_name ? (' &middot; ' + escExtHtml(r.owner_name)) : '';
        return '<div class="recent-sale-row" title="Click to refill the form with this sale">' +
            '<div class="recent-sale-main">' +
                '<div class="recent-sale-name">' + escExtHtml(r.product_name) + (r.refunded == 1 ? ' <small style="color:#dc2626;">(refunded)</small>' : '') + '</div>' +
                '<div class="recent-sale-sub">' + qty.toLocaleString() + ' &times; RWF ' + Number(r.unit_price).toLocaleString() + ' &middot; ' + escExtHtml(r.customer_name || 'client') + ownerTag + '</div>' +
            '</div>' +
            '<div class="recent-sale-right">' +
                '<div class="recent-sale-total">RWF ' + Math.round(r.total_amount).toLocaleString() + '</div>' +
                '<div class="recent-sale-time">' + relSaleTime(r.created_at) + '</div>' +
            '</div>' +
        '</div>';
    }).join('');
    list.querySelectorAll('.recent-sale-row').forEach(function(el, i) {
        el.addEventListener('click', function() {
            list.querySelectorAll('.recent-sale-row.selected').forEach(function(o) { o.classList.remove('selected'); });
            el.classList.add('selected');
            reuseExtSale(rows[i]);
        });
    });
}

// Refills the manual product name + quantity/price/customer/owner fields from
// a past sale so a repeat sale can be entered with one click instead of
// re-typing everything. The user still has to hit "+ Add to Sale" and submit.
// External sales have no product_id to look up in the catalog, so this always
// uses the manual-entry mode (the product_name text is all we ever stored).
function reuseExtSale(r) {
    extSwitchToManual();
    document.getElementById('ext_manual_name').value = r.product_name;
    extSetManualName(r.product_name);
    document.getElementById('ext_quantity').value = 1;
    document.getElementById('ext_unit_price').value = r.unit_price;
    if (r.owner_name) {
        document.getElementById('ext_owner_name_input').value  = r.owner_name;
        document.getElementById('ext_owner_phone_input').value = r.owner_phone || '';
        extSyncOwner();
    }
    calcExtValidity();
    document.getElementById('ext_manual_name').scrollIntoView({ behavior: 'smooth', block: 'center' });
}

// Applies the Is Loan/Cash/Momo shortcut defaults against the current cart
// total. Runs on every cart change (not just once on a step transition) so
// the split stays correct as items are added or removed.
function updateExtPaymentDefaults() {
    var total = extCart.reduce(function(s,i){ return s + i.qty*i.price; }, 0);

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
    calcExtSplit();
}

function calcExtSplit(changed) {
    var total = extCart.reduce(function(s,i){ return s + i.qty*i.price; }, 0);
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
    var splitOk   = remaining === 0;
    document.getElementById('ext_remaining').textContent = 'RWF ' + remaining.toLocaleString();
    document.getElementById('ext_remaining_row').className = 'split-row split-remaining-row ' + (splitOk ? 'valid' : 'invalid');

    var phoneOk = loan <= 0 || document.getElementById('ext_phone').value.trim().length > 0;
    document.getElementById('ext_loan_phone_warn').style.display = (loan > 0 && !phoneOk) ? 'block' : 'none';

    document.getElementById('ext_submit_btn').disabled = !(extCart.length > 0 && splitOk && phoneOk);
}

function toggleExtShortcut(type) {
    if (type !== 'loan') document.getElementById('ext_is_loan').checked = false;
    if (type !== 'cash') document.getElementById('ext_is_cash').checked = false;
    if (type !== 'momo') document.getElementById('ext_is_momo').checked = false;

    var total = extCart.reduce(function(s,i){ return s + i.qty*i.price; }, 0);
    var checked = document.getElementById('ext_is_' + type).checked;

    if (checked) {
        document.getElementById('ext_cash').value = type === 'cash' ? total : 0;
        document.getElementById('ext_momo').value = type === 'momo' ? total : 0;
        document.getElementById('ext_loan').value = type === 'loan' ? total : 0;
    }
    calcExtSplit();
}

document.getElementById('ext_phone').addEventListener('input', function() { calcExtSplit(); });

function handleExtSubmit() {
    if (extCart.length === 0) return;
    var total = extCart.reduce(function(s,i){ return s + i.qty*i.price; }, 0);
    var cash  = parseFloat(document.getElementById('ext_cash').value)||0;
    var momo  = parseFloat(document.getElementById('ext_momo').value)||0;
    var loan  = parseFloat(document.getElementById('ext_loan').value)||0;
    var customer = document.getElementById('ext_customer_name').value.trim() || 'client';
    var parts = [];
    if (cash > 0) parts.push('Cash: RWF ' + cash.toLocaleString());
    if (momo > 0) parts.push('Momo: RWF ' + momo.toLocaleString());
    if (loan > 0) parts.push('Loan: RWF ' + loan.toLocaleString() + ' (deferred)');
    var itemLines = extCart.map(function(i){ return '- ' + i.name + ': ' + i.qty + ' @ RWF ' + i.price.toLocaleString(); }).join('\n');
    var ok = confirm(
        'Confirm External Sale for ' + customer + '?\n\n' +
        itemLines + '\n\n' +
        'Total: RWF ' + total.toLocaleString() + '\n' +
        'Payment: ' + parts.join(' | ') + '\n\n' +
        'No stock will be deducted.'
    );
    if (!ok) return;

    var btn = document.getElementById('ext_submit_btn');
    btn.disabled = true; btn.textContent = 'Saving...';

    var form = document.getElementById('externalSaleForm');
    SaleQueue.enqueue('external', form, { external_sale: '1', ajax: '1' }).then(function(res) {
        if (res.immediate) {
            showSaleToast(res.message, res.ok);
            if (res.ok) {
                // External sale doesn't touch owned stock, but may create/update
                // a loan client, and always adds a row to recent_sales_external —
                // invalidate both before reload.
                Promise.all([DataCache.invalidate('clients'), DataCache.invalidate('recent_sales_external')])
                    .then(function() { location.reload(); });
            } else {
                btn.textContent = 'Save Sale';
                btn.disabled = false;
            }
        } else {
            // Network is too slow/unavailable — the sale is safely queued in
            // IndexedDB and will sync automatically. Clear the cart so the
            // cashier can move straight on to the next customer.
            showSaleToast(res.message, true);
            extCart = [];
            form.reset();
            renderExtCart();
            btn.textContent = 'Save Sale';
            btn.disabled = false;
        }
    });
}
</script>
</body>
</html>
