<?php
require_once 'config.php';
if (!isLoggedIn()) redirect('login.php');
if (!hasPermission('sales', 'create')) { $_SESSION['flash_error'] = "You don't have permission to record bulk sales."; redirect('dashboard.php'); }

$cid_sql = cidSql(); $cid_and = cidAnd();

// Product search/categories and the loan-client picker are loaded
// client-side from DataCache (js/data-cache.js) instead of these per-page
// AJAX endpoints.

// Flash messages
if (isset($_SESSION['flash_success'])) { $success = $_SESSION['flash_success']; unset($_SESSION['flash_success']); }
if (isset($_SESSION['flash_error']))   { $error   = $_SESSION['flash_error'];   unset($_SESSION['flash_error']); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kuranguza - Bulk Sale</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/sales.css">
    <style>
        .sale-page-card {
            background: var(--white);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            padding: 32px;
        }
        .form-2col {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0 20px;
        }
        .sale-page-header {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 28px;
        }
        .sale-page-header h1 { margin: 0; font-size: 22px; }
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
        .field-hint  { display: block; font-size: 12px; color: var(--secondary); margin-top: 4px; }
        .field-error { display: none; font-size: 12px; color: #dc2626; margin-top: 4px; }
        .price-warning { display: none; font-size: 12px; color: #d97706; margin-top: 4px; background: #fffbeb; padding: 4px 8px; border-radius: 4px; }
        .price-input-group { display: flex; gap: 8px; align-items: center; }
        .price-input-group input { flex: 1; }
        .default-price-badge {
            font-size: 11px; background: var(--gray-100); color: var(--secondary);
            border: 1px solid var(--gray-300); border-radius: 4px;
            padding: 4px 8px; cursor: pointer; white-space: nowrap;
        }
        .default-price-badge:hover { background: var(--primary); color: #fff; border-color: var(--primary); }

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
            display: none; background: #eff6ff; border: 1px solid #bfdbfe;
            border-radius: var(--radius); padding: 12px 16px;
            align-items: center; justify-content: space-between; gap: 10px;
        }
        .client-card.show { display: flex; }
        .client-card-name { font-weight: 700; color: #1e40af; font-size: 15px; }
        .client-card-meta { color: var(--secondary); font-size: 12px; margin-top: 3px; }
        .client-card-clear { background: none; border: none; color: #94a3b8; cursor: pointer; font-size: 20px; line-height: 1; padding: 0 4px; flex-shrink: 0; }
        .client-card-clear:hover { color: #dc2626; }
        .client-fields-toggle-btn {
            background: none; border: none; color: var(--primary); font-size: 13px; font-weight: 600;
            cursor: pointer; padding: 6px 0; text-align: left;
        }
        .client-fields-toggle-btn:hover { text-decoration: underline; }

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
        .cart-foot { display: flex; justify-content: space-between; align-items: center; padding: 13px 16px; background: #eff6ff; border-top: 1px solid #bfdbfe; }
        .cart-foot-lbl { font-size: 12px; font-weight: 700; color: #1e40af; }
        .cart-foot-val { font-size: 20px; font-weight: 800; color: #1d4ed8; }
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
        .recent-sale-row.selected { background: #eff6ff; border-left-color: var(--primary); }
        .recent-sale-row.selected:hover { background: #eff6ff; }
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
            .lvl-btn { min-width: 72px; padding: 6px 10px; }
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
                <a href="sales.php?tab=bulk" class="back-btn">&#8592; Back</a>
                <h1>Kuranguza &mdash; Bulk Sale</h1>
            </div>
            <div id="bulk_sale_alert" class="alert" style="display:none;margin-bottom:16px;"></div>

            <form method="POST" action="sales.php" id="bulkSaleForm">
                <input type="hidden" id="bulk_items_json" name="items_json" value="[]">

                <div class="sale-3col">
                    <!-- ═══════════ Column 1: Client + Recent Sales ═══════════ -->
                    <div class="sale-col-client">
                        <div id="bulk_step_panel_1">

                            <div class="client-card" id="bulk_client_card">
                                <div>
                                    <div class="client-card-name" id="bulk_client_card_name"></div>
                                    <div class="client-card-meta" id="bulk_client_card_meta"></div>
                                </div>
                                <button type="button" class="client-card-clear" onclick="clearBulkClient()" title="Change client">&times;</button>
                            </div>

                            <div id="bulk_client_select_area">
                                <div class="form-group" id="bulkClientPickerGroup" style="display:none;">
                                    <label>Existing Client</label>
                                    <div class="searchable-select" id="bulkClientPickerWrap">
                                        <input type="text" class="searchable-select-input" id="bulk_client_picker_search"
                                            placeholder="Search registered client..." autocomplete="off">
                                        <div class="searchable-select-dropdown" id="bulk_client_picker_dropdown"></div>
                                    </div>
                                    <small style="color:var(--secondary);margin-top:3px;display:block;">Pick to auto-fill, or type a new name below.</small>
                                </div>
                                <button type="button" class="client-fields-toggle-btn" id="bulk_client_fields_toggle" onclick="toggleClientFields('bulk')">+ Set client name / phone</button>
                                <div id="bulk_client_fields" style="display:none;">
                                    <div class="form-group">
                                        <label>Client Name</label>
                                         <input type="text" id="bulk_customer" name="customer_name" placeholder="Enter customer name (defaults to &quot;client&quot;)">
                                    </div>
                                    <div class="form-group">
                                        <label>Client Phone <small style="font-weight:400;color:var(--secondary);">(required only for loans)</small></label>
                                        <input type="number" id="bulk_phone" name="phone" placeholder="e.g. 07XXXXXXXX">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="recent-sales-panel" id="bulk_recent_panel">
                            <div class="recent-sales-header" onclick="toggleRecentSales('bulk')">
                                <span class="recent-sales-header-lbl">Recent Sales <span id="bulk_recent_badge" class="cart-badge zero">0</span></span>
                                <span class="recent-toggle-icon" id="bulk_recent_toggle_icon">&#9650;</span>
                            </div>
                            <div class="recent-sales-body" id="bulk_recent_body">
                                <input type="text" class="searchable-select-input" id="bulk_recent_search" placeholder="Search recent sales (product or customer)...">
                                <div id="bulk_recent_list" class="recent-sales-list" style="margin-top:10px;">
                                    <div class="cart-empty">Loading&hellip;</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ═══════════ Column 2: Sale Details ═══════════ -->
                    <div class="sale-col-details">
                        <div id="bulk_step_panel_2">
                            <div class="form-group">
                                <label>Category</label>
                                <div class="searchable-select" id="bulkCatWrap">
                                    <input type="text" class="searchable-select-input" id="bulk_cat_search"
                                           placeholder="All categories…" autocomplete="off" readonly style="cursor:pointer;"
                                           onfocus="this.removeAttribute('readonly')">
                                    <div class="searchable-select-dropdown" id="bulk_cat_dropdown"></div>
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Select Product*</label>
                                <input type="hidden" id="bulk_product_id" name="product_id">
                                <div class="searchable-select" id="bulkProductSearchable">
                                    <input type="text" class="searchable-select-input" id="bulk_product_search" placeholder="Search product..." autocomplete="off">
                                    <div class="searchable-select-dropdown" id="bulk_product_dropdown"></div>
                                </div>
                            </div>
                            <div id="bulk_product_details" class="price-history" style="display:none;">
                                <strong>Product Info:</strong> <span id="bulk_product_info"></span>
                            </div>
                            <div id="bulk_level_selector" style="display:none;margin-bottom:16px;">
                                <label style="font-size:13px;font-weight:600;display:block;margin-bottom:8px;">Select Selling Level</label>
                                <div id="bulk_level_buttons" style="display:flex;flex-wrap:wrap;gap:8px;"></div>
                            </div>

                            <div class="form-group">
                                <label id="bulk_qty_label">Quantity (Packages)*</label>
                                <input type="number" id="bulk_quantity" name="quantity" required min="1" oninput="calculateBulkTotal()">
                                <small id="bulk_stock_info" class="field-hint"></small>
                                <small id="bulk_qty_error" class="field-error"></small>
                            </div>
                            <div class="form-group">
                                <label>Selling Price (per package)*</label>
                                <div class="price-input-group">
                                    <input type="number" id="bulk_selling_price" name="selling_price" required min="1" oninput="calculateBulkTotal()">
                                    <span class="default-price-badge" onclick="setBulkDefaultPrice()">Use Default</span>
                                </div>
                                <div id="bulk_price_warning" class="price-warning"></div>
                            </div>

                            <button type="button" class="add-item-btn" id="bulk_add_cart_btn" disabled onclick="addBulkToCart()">
                                + Add to Sale
                            </button>
                        </div>
                    </div>

                    <!-- ═══════════ Column 3: Cart + Payment ═══════════ -->
                    <div class="sale-col-cart">
                        <div class="cart-panel">
                            <div class="cart-header">
                                Items to Sell
                                <span id="bulk_cart_badge" class="cart-badge zero">0</span>
                            </div>
                            <div class="cart-body" id="bulk_cart_body">
                                <div class="cart-empty">No items yet.<br>Search and add products from the left.</div>
                            </div>
                            <div class="cart-foot">
                                <span class="cart-foot-lbl">Sale Total</span>
                                <span class="cart-foot-val" id="bulk_cart_total">RWF 0</span>
                            </div>
                        </div>

                        <!-- ═══════════ Payment (under the cart card) ═══════════ -->
                        <div id="bulk_step_panel_3" style="margin-top:16px;">
                            <div class="shortcut-chips">
                                <label class="shortcut-chip" title="Full amount goes to loan">
                                    <input type="checkbox" id="bulk_is_loan" onchange="toggleBulkShortcut('loan')" style="accent-color:var(--primary);">
                                    Is Loan?
                                </label>
                                <label class="shortcut-chip" title="Full amount goes to cash">
                                    <input type="checkbox" id="bulk_is_cash" onchange="toggleBulkShortcut('cash')" style="accent-color:#16a34a;">
                                    Is Cash?
                                </label>
                                <label class="shortcut-chip" title="Full amount goes to momo">
                                    <input type="checkbox" id="bulk_is_momo" onchange="toggleBulkShortcut('momo')" style="accent-color:#2563eb;">
                                    Is Momo?
                                </label>
                            </div>

                            <div id="bulk_loan_phone_warn" style="display:none;font-size:12px;color:#dc2626;background:#fef2f2;border:1px solid #fca5a5;border-radius:var(--radius);padding:9px 12px;margin-bottom:12px;">
                                &#9888; Client phone is required when part of the sale goes to loan. Add it above.
                            </div>

                            <div id="bulk_payment_section">
                                <div class="form-group">
                                    <label>Payment Breakdown</label>
                                    <div class="split-payment-box">
                                        <div class="split-row">
                                            <span class="split-label">Cash</span>
                                            <input type="number" id="bulk_cash" name="cash_amount" min="0" step="1" value="0" oninput="calcBulkSplit('cash')">
                                        </div>
                                        <div class="split-row">
                                            <span class="split-label">Momo</span>
                                            <input type="number" id="bulk_momo" name="momo_amount" min="0" step="1" value="0" oninput="calcBulkSplit('momo')">
                                        </div>
                                        <div class="split-row">
                                            <span class="split-label">Loan</span>
                                            <input type="number" id="bulk_loan_split" name="loan_amount" min="0" step="1" value="0" oninput="calcBulkSplit('loan')">
                                        </div>
                                        <div class="split-row split-remaining-row" id="bulk_remaining_row">
                                            <span class="split-label">Remaining</span>
                                            <span id="bulk_remaining">—</span>
                                        </div>
                                    </div>
                                </div>
                                <button type="button" id="bulk_submit_btn" class="btn btn-primary" disabled onclick="handleBulkSubmit()" style="width:100%;padding:12px;">
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
<script src="js/data-cache.js"></script>
<script src="script.js"></script>
<script>
// ── Product picker, backed by DataCache (js/data-cache.js) ─────────────────────
var bulkSelectedProduct = null;
var bulkSelectedCat     = '';
var bulkAllCategories   = [];
var bulkCart            = [];

// ── Bulk category searchable select ──────────────────────────────────────────
(function() {
    var catSearch   = document.getElementById('bulk_cat_search');
    var catDropdown = document.getElementById('bulk_cat_dropdown');

    function renderCatOpts(filter) {
        catDropdown.innerHTML = '';
        var allDiv = document.createElement('div');
        allDiv.className = 'searchable-select-option';
        allDiv.textContent = '— All Categories —';
        allDiv.addEventListener('click', function() { pickCat('', ''); });
        catDropdown.appendChild(allDiv);
        var q = filter.toLowerCase();
        var matched = bulkAllCategories.filter(function(c) { return c.toLowerCase().indexOf(q) !== -1; });
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
        bulkSelectedCat = value;
        catSearch.value = label;
        catSearch.setAttribute('readonly', '');
        catDropdown.classList.remove('open');
        bulkSelectedProduct = null;
        document.getElementById('bulk_product_id').value = '';
        var ps = document.getElementById('bulk_product_search');
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
        if (!e.target.closest('#bulkCatWrap')) {
            catDropdown.classList.remove('open');
            catSearch.value = bulkSelectedCat || '';
            catSearch.setAttribute('readonly', '');
        }
    });
})();

function loadBulkCategories() {
    DataCache.getCategoriesList().then(function(cats) { bulkAllCategories = cats.map(function(c) { return c.name; }); });
}
loadBulkCategories();

(function() {
    var search   = document.getElementById('bulk_product_search');
    var dropdown = document.getElementById('bulk_product_dropdown');
    var hidden   = document.getElementById('bulk_product_id');
    var hi = -1, debounce = null;

    function getOptions() { return dropdown.querySelectorAll('.searchable-select-option'); }

    function escH(s) { return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

    function renderOptions(items) {
        dropdown.innerHTML = '';
        if (!items.length) {
            dropdown.innerHTML = '<div class="searchable-select-option" style="color:var(--secondary);cursor:default;">No results</div>';
            return;
        }
        items.forEach(function(p) {
            var label = (p.category ? p.category + '-' : '') + p.name;
            var div = document.createElement('div');
            div.className = 'searchable-select-option';
            div.dataset.id    = p.id;
            div.dataset.name  = label;
            div.dataset.price = p.package_price;
            div.dataset.stock = p.quantity;
            div.dataset.unit  = p.unit_measure || '';
            div.innerHTML = escH(label) + '<small style="color:var(--secondary);"> · ' +
                p.quantity + ' pkgs · RWF ' + Number(p.package_price).toLocaleString() + '</small>';
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
                    if (!(parseFloat(p.wh_qty) > 0)) return false;
                    if (bulkSelectedCat && p.category !== bulkSelectedCat) return false;
                    if (term && (p.search_text || '').toLowerCase().indexOf(term) === -1) return false;
                    return true;
                }).slice(0, 60).map(function(p) {
                    return { id: p.id, name: p.name, category: p.category, unit_measure: p.unit_measure,
                             package_price: p.bulk_price, quantity: p.wh_qty };
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
        bulkSelectedProduct = null; hidden.value = '';
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
        if (!e.target.closest('#bulkProductSearchable')) dropdown.classList.remove('open');
    });

    function hl(vis) {
        getOptions().forEach(function(o) { o.classList.remove('highlighted'); });
        if (vis[hi]) { vis[hi].classList.add('highlighted'); vis[hi].scrollIntoView({block:'nearest'}); }
    }
    function pick(opt) {
        if (!opt.dataset.id) return;
        hidden.value = opt.dataset.id;
        bulkSelectedProduct = {
            id:    opt.dataset.id,
            name:  opt.dataset.name,
            price: parseFloat(opt.dataset.price) || 0,
            stock: parseInt(opt.dataset.stock)   || 0,
            unit:  opt.dataset.unit || ''
        };
        search.value = opt.dataset.name;
        dropdown.classList.remove('open'); hi = -1;
        updateBulkProductDetails();
    }
})();

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
    document.getElementById(prefix + '_client_select_area').style.display = 'none';
}
function _escH(s) { return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

DataCache.getClients().then(function(list) {
    if (!list.length) return;
    document.getElementById('bulkClientPickerGroup').style.display = '';
    document.getElementById('bulk_client_picker_dropdown').innerHTML = list.map(function(c) {
        var visits = parseInt(c.total_loans) || 0;
        var outstanding = parseFloat(c.unpaid_amount) || 0;
        return '<div class="searchable-select-option" data-client="' + _escH(c.name) +
            '" data-phone="' + _escH(c.phone) + '" data-visits="' + visits + '" data-outstanding="' + outstanding + '">' + _escH(c.name) +
            (c.phone ? ' — ' + _escH(c.phone) : '') +
            '<small style="color:var(--secondary);"> (' + visits + ' visit' + (visits !== 1 ? 's' : '') + ')</small>' +
            (outstanding > 0 ? '<small style="color:#dc2626;font-weight:600;"> · Owes: RWF ' + outstanding.toLocaleString() + '</small>' : '') +
            '</div>';
    }).join('');
    initLoanClientPicker('bulkClientPickerWrap', 'bulk_client_picker_search', 'bulk_client_picker_dropdown', 'bulk_customer', 'bulk_phone',
        function(opt) { showClientCard('bulk', opt); });
});

// Client name/phone are collapsed behind a toggle by default — most sales are
// walk-in/anonymous, so only reveal these fields on demand (or automatically
// once a loan amount makes the phone number actually required).
function toggleClientFields(prefix) {
    var wrap = document.getElementById(prefix + '_client_fields');
    var open = wrap.style.display !== 'none';
    wrap.style.display = open ? 'none' : 'block';
    document.getElementById(prefix + '_client_fields_toggle').textContent = open ? '+ Set client name / phone' : '− Hide client name / phone';
}
function showClientFields(prefix) {
    var wrap = document.getElementById(prefix + '_client_fields');
    if (wrap.style.display === 'none') {
        wrap.style.display = 'block';
        document.getElementById(prefix + '_client_fields_toggle').textContent = '− Hide client name / phone';
    }
}

function clearBulkClient() {
    document.getElementById('bulk_customer').value = '';
    document.getElementById('bulk_phone').value = '';
    document.getElementById('bulk_client_card').classList.remove('show');
    document.getElementById('bulk_client_select_area').style.display = '';
    document.getElementById('bulk_client_picker_search') && (document.getElementById('bulk_client_picker_search').value = '');
}

function updateBulkProductDetails() {
    var addBtn = document.getElementById('bulk_add_cart_btn');
    if (!bulkSelectedProduct) {
        document.getElementById('bulk_product_details').style.display = 'none';
        document.getElementById('bulk_stock_info').innerHTML = '';
        document.getElementById('bulk_selling_price').value = '';
        document.getElementById('bulk_quantity').value = '';
        document.getElementById('bulk_quantity').max = '';
        document.getElementById('bulk_level_selector').style.display = 'none';
        document.getElementById('bulk_level_buttons').innerHTML = '';
        document.getElementById('bulk_qty_label').textContent = 'Quantity (Packages)*';
        addBtn.disabled = true;
        return Promise.resolve();
    }
    var price = bulkSelectedProduct.price;
    var stock = bulkSelectedProduct.stock;
    var name  = bulkSelectedProduct.name;

    document.getElementById('bulk_selling_price').value = price;
    document.getElementById('bulk_quantity').value = '';
    document.getElementById('bulk_quantity').max = stock;
    document.getElementById('bulk_stock_info').innerHTML = 'Available: ' + stock + ' packages';
    document.getElementById('bulk_product_details').style.display = 'block';
    document.getElementById('bulk_product_info').innerHTML = name + ' &mdash; Default price: RWF ' + parseFloat(price).toLocaleString();
    calculateBulkTotal();

    // Returned so callers (e.g. reuseBulkSale()) can apply their own qty/price
    // only after the async level lookup below has finished populating the
    // level buttons — otherwise the level auto-click would clobber them.
    return fetch('ajax_levels.php?product_id=' + bulkSelectedProduct.id)
        .then(function(r){ return r.json(); })
        .then(function(data) {
            var sel  = document.getElementById('bulk_level_selector');
            var btns = document.getElementById('bulk_level_buttons');
            btns.innerHTML = '';
            if (data.ok && data.levels && data.levels.length > 0) {
                var running = parseInt(data.stock_qty) || 0;
                var divisor = 1;
                data.levels.forEach(function(lvl, i) {
                    if (i > 0) { divisor *= (parseInt(lvl.qty_per_parent)||1); running *= (parseInt(lvl.qty_per_parent)||1); }
                    var btn = document.createElement('button');
                    btn.type = 'button'; btn.className = 'lvl-btn';
                    btn.innerHTML =
                        '<span class="lvl-btn-name">'  + lvl.level_name + '</span>' +
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
                        document.getElementById('bulk_stock_info').innerHTML = 'Available: ' + parseInt(this.dataset.stock).toLocaleString() + ' ' + this.dataset.name;
                        document.getElementById('bulk_qty_label').textContent = 'Quantity (' + this.dataset.name + ')*';
                        calculateBulkTotal();
                    };
                    btns.appendChild(btn);
                });
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
    } else if (bulkSelectedProduct) {
        document.getElementById('bulk_selling_price').value = bulkSelectedProduct.price;
    }
    calculateBulkTotal();
}

function calculateBulkTotal() {
    var addBtn = document.getElementById('bulk_add_cart_btn');
    if (!bulkSelectedProduct) { addBtn.disabled = true; return; }

    var qtyInput = document.getElementById('bulk_quantity');
    var stock = parseInt(qtyInput.max) > 0 ? parseInt(qtyInput.max) : bulkSelectedProduct.stock;
    var activeBtn = document.querySelector('#bulk_level_buttons .lvl-btn.active');
    var defaultPrice = activeBtn ? (parseFloat(activeBtn.dataset.price)||0) : bulkSelectedProduct.price;
    var qty   = parseInt(document.getElementById('bulk_quantity').value) || 0;
    var price = parseFloat(document.getElementById('bulk_selling_price').value) || 0;
    var qtyError     = document.getElementById('bulk_qty_error');
    var priceWarning = document.getElementById('bulk_price_warning');
    var valid = true;

    if (qty > stock) {
        qtyError.innerHTML = 'Exceeds available stock (' + stock + ')!';
        qtyError.style.display = 'block'; valid = false;
    } else {
        qtyError.style.display = 'none';
    }

    if (price > 0 && defaultPrice > 0 && price !== defaultPrice) {
        var diff = ((price - defaultPrice) / defaultPrice * 100).toFixed(1);
        priceWarning.innerHTML = 'Price is ' + (price > defaultPrice ? '+' : '') + diff + '% from default (RWF ' + defaultPrice.toLocaleString() + ')';
        priceWarning.style.display = 'block';
    } else {
        priceWarning.style.display = 'none';
    }

    if (qty < 1 || price < 1) valid = false;
    addBtn.disabled = !valid;
}

function addBulkToCart() {
    if (!bulkSelectedProduct) return;
    var qty   = parseInt(document.getElementById('bulk_quantity').value) || 0;
    var price = parseFloat(document.getElementById('bulk_selling_price').value) || 0;
    var stock = parseInt(document.getElementById('bulk_quantity').max) || bulkSelectedProduct.stock;
    if (qty < 1 || price < 1 || qty > stock) return;

    var activeBtn  = document.querySelector('#bulk_level_buttons .lvl-btn.active');
    var divisor    = activeBtn ? (parseInt(activeBtn.dataset.divisor) || 1) : 1;
    var levelName  = activeBtn ? activeBtn.dataset.name : 'Package';
    var pid        = bulkSelectedProduct.id;
    var name       = bulkSelectedProduct.name;

    var existing = bulkCart.find(function(i){ return i.pid === pid && i.divisor === divisor; });
    if (existing) {
        existing.qty += qty;
        existing.price = price;
    } else {
        bulkCart.push({ pid: pid, name: name, qty: qty, price: price, divisor: divisor, levelName: levelName });
    }

    renderBulkCart();
    showSaleToast('"' + name + '" added to sale.', true);

    // Reset picker for next product
    bulkSelectedProduct = null;
    document.getElementById('bulk_product_search').value = '';
    document.getElementById('bulk_product_id').value = '';
    updateBulkProductDetails();
}

function removeBulkCartItem(pid, divisor) {
    bulkCart = bulkCart.filter(function(i){ return !(i.pid === pid && i.divisor === divisor); });
    renderBulkCart();
}

function renderBulkCart() {
    var total = bulkCart.reduce(function(s,i){ return s + i.qty*i.price; }, 0);
    var badge = document.getElementById('bulk_cart_badge');
    badge.textContent = bulkCart.length;
    badge.className   = 'cart-badge' + (bulkCart.length===0 ? ' zero' : '');
    document.getElementById('bulk_cart_total').textContent = 'RWF ' + Math.round(total).toLocaleString();

    var body = document.getElementById('bulk_cart_body');
    if (bulkCart.length === 0) {
        body.innerHTML = '<div class="cart-empty">No items yet.<br>Search and add products from the left.</div>';
    } else {
        body.innerHTML = bulkCart.map(function(item){
            var sub = item.qty * item.price;
            return '<div class="cart-item">' +
                '<div class="cart-item-info">' +
                    '<div class="cart-item-name">' + escBulkHtml(item.name) + '</div>' +
                    '<div class="cart-item-sub">' + item.qty.toLocaleString() + ' ' + item.levelName + ' &times; RWF ' + item.price.toLocaleString() + '</div>' +
                '</div>' +
                '<div class="cart-item-right">' +
                    '<span class="cart-item-total">RWF ' + Math.round(sub).toLocaleString() + '</span>' +
                    '<button type="button" class="cart-rm" onclick="removeBulkCartItem(\'' + item.pid + '\',' + item.divisor + ')" title="Remove">&times;</button>' +
                '</div>' +
            '</div>';
        }).join('');
    }

    document.getElementById('bulk_items_json').value = JSON.stringify(
        bulkCart.map(function(i){
            return { product_id: i.pid, quantity: i.qty, selling_price: i.price, level_divisor: i.divisor };
        })
    );
    updateBulkPaymentDefaults();
}

function escBulkHtml(s) { return (s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

// ── Recent Sales panel, backed by DataCache (instant from IndexedDB, refreshed
// in the background when the server reports newer data) ────────────────────
var bulkRecentSales = [];

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

function loadBulkRecentSales(force) {
    DataCache.getRecentSales('bulk', force ? {force:true} : {}).then(function(list) {
        bulkRecentSales = list || [];
        renderBulkRecentSales(document.getElementById('bulk_recent_search').value.trim());
    });
}
loadBulkRecentSales();

document.getElementById('bulk_recent_search').addEventListener('input', function() {
    renderBulkRecentSales(this.value.trim());
});

function renderBulkRecentSales(filter) {
    var term = (filter || '').toLowerCase();
    var rows = bulkRecentSales.filter(function(r) {
        if (!term) return true;
        return (r.product_name || '').toLowerCase().indexOf(term) !== -1 ||
               (r.customer_name || '').toLowerCase().indexOf(term) !== -1;
    });
    var badge = document.getElementById('bulk_recent_badge');
    badge.textContent = bulkRecentSales.length;
    badge.className = 'cart-badge' + (bulkRecentSales.length === 0 ? ' zero' : '');

    var list = document.getElementById('bulk_recent_list');
    if (!rows.length) {
        list.innerHTML = '<div class="cart-empty">' + (bulkRecentSales.length ? 'No matches.' : 'No recent sales yet.') + '</div>';
        return;
    }
    list.innerHTML = rows.map(function(r) {
        var qty = parseInt(r.quantity) || 0;
        return '<div class="recent-sale-row" title="Click to refill the form with this sale">' +
            '<div class="recent-sale-main">' +
                '<div class="recent-sale-name">' + escBulkHtml(r.product_name) + (r.refunded == 1 ? ' <small style="color:#dc2626;">(refunded)</small>' : '') + '</div>' +
                '<div class="recent-sale-sub">' + qty.toLocaleString() + ' &times; RWF ' + Number(r.package_price).toLocaleString() + ' &middot; ' + escBulkHtml(r.customer_name || 'client') + '</div>' +
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
            reuseBulkSale(rows[i]);
        });
    });
}

// Refills the picker + quantity/price/customer fields from a past sale so a
// repeat sale can be entered with one click instead of re-searching from
// scratch. The user still has to hit "+ Add to Sale" and submit.
function reuseBulkSale(r) {
    DataCache.getProducts().then(function(list) {
        var p = list.find(function(x) { return String(x.id) === String(r.product_id); });
        if (!p) { showSaleToast('That product is no longer available.', false); return; }

        var label = (p.category ? p.category + '-' : '') + p.name;
        bulkSelectedCat = p.category || '';
        document.getElementById('bulk_cat_search').value = bulkSelectedCat;
        document.getElementById('bulk_cat_search').setAttribute('readonly', '');
        bulkSelectedProduct = { id: p.id, name: label, price: parseFloat(p.bulk_price) || 0, stock: parseInt(p.wh_qty) || 0, unit: p.unit_measure || '' };
        document.getElementById('bulk_product_search').value = label;
        document.getElementById('bulk_product_id').value = p.id;

        Promise.resolve(updateBulkProductDetails()).then(function() {
            document.getElementById('bulk_quantity').value = 1;
            document.getElementById('bulk_selling_price').value = r.package_price;
            calculateBulkTotal();
        });

        document.getElementById('bulk_product_search').scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
}

// Applies the Is Loan/Cash/Momo shortcut defaults against the current cart
// total. Runs on every cart change (not just once on a step transition) so
// the split stays correct as items are added or removed.
function updateBulkPaymentDefaults() {
    var total = bulkCart.reduce(function(s,i){ return s + i.qty*i.price; }, 0);

    var isLoan = document.getElementById('bulk_is_loan').checked;
    var isCash = document.getElementById('bulk_is_cash').checked;
    var isMomo = document.getElementById('bulk_is_momo').checked;
    var cash = parseFloat(document.getElementById('bulk_cash').value)||0;
    var momo = parseFloat(document.getElementById('bulk_momo').value)||0;
    var loan = parseFloat(document.getElementById('bulk_loan_split').value)||0;
    if (isLoan) {
        document.getElementById('bulk_cash').value = 0;
        document.getElementById('bulk_momo').value = 0;
        document.getElementById('bulk_loan_split').value = total;
    } else if (isCash) {
        document.getElementById('bulk_cash').value = total;
        document.getElementById('bulk_momo').value = 0;
        document.getElementById('bulk_loan_split').value = 0;
    } else if (isMomo) {
        document.getElementById('bulk_cash').value = 0;
        document.getElementById('bulk_momo').value = total;
        document.getElementById('bulk_loan_split').value = 0;
    } else if (cash===0 && momo===0 && loan===0) {
        document.getElementById('bulk_momo').value = total;
    }
    calcBulkSplit();
}

function calcBulkSplit(changed) {
    var total = bulkCart.reduce(function(s,i){ return s + i.qty*i.price; }, 0);
    var cashEl = document.getElementById('bulk_cash');
    var momoEl = document.getElementById('bulk_momo');
    var loanEl = document.getElementById('bulk_loan_split');
    var cash = parseFloat(cashEl.value)||0;
    var momo = parseFloat(momoEl.value)||0;
    var loan = parseFloat(loanEl.value)||0;

    if (changed === 'cash')      { momo = Math.max(0, total-cash-loan); momoEl.value = momo; }
    else if (changed === 'momo') { loan = Math.max(0, total-cash-momo); loanEl.value = loan; }
    else if (changed === 'loan') { momo = Math.max(0, total-cash-loan); momoEl.value = momo; }

    var remaining = Math.round(total - cash - momo - loan);
    var splitOk   = remaining === 0;
    document.getElementById('bulk_remaining').textContent = 'RWF ' + remaining.toLocaleString();
    document.getElementById('bulk_remaining_row').className = 'split-row split-remaining-row ' + (splitOk ? 'valid' : 'invalid');

    if (loan > 0) showClientFields('bulk');
    var phoneOk = loan <= 0 || document.getElementById('bulk_phone').value.trim().length > 0;
    document.getElementById('bulk_loan_phone_warn').style.display = (loan > 0 && !phoneOk) ? 'block' : 'none';

    document.getElementById('bulk_submit_btn').disabled = !(bulkCart.length > 0 && splitOk && phoneOk);
}

function toggleBulkShortcut(type) {
    if (type !== 'loan') document.getElementById('bulk_is_loan').checked = false;
    if (type !== 'cash') document.getElementById('bulk_is_cash').checked = false;
    if (type !== 'momo') document.getElementById('bulk_is_momo').checked = false;

    var total = bulkCart.reduce(function(s,i){ return s + i.qty*i.price; }, 0);
    var checked = document.getElementById('bulk_is_' + type).checked;

    if (checked) {
        document.getElementById('bulk_cash').value       = type === 'cash' ? total : 0;
        document.getElementById('bulk_momo').value       = type === 'momo' ? total : 0;
        document.getElementById('bulk_loan_split').value = type === 'loan' ? total : 0;
    }
    calcBulkSplit();
}

document.getElementById('bulk_phone').addEventListener('input', function() { calcBulkSplit(); });

function handleBulkSubmit() {
    if (bulkCart.length === 0) return;
    var total = bulkCart.reduce(function(s,i){ return s + i.qty*i.price; }, 0);
    var cash  = parseFloat(document.getElementById('bulk_cash').value)||0;
    var momo  = parseFloat(document.getElementById('bulk_momo').value)||0;
    var loan  = parseFloat(document.getElementById('bulk_loan_split').value)||0;
    var customer = document.getElementById('bulk_customer').value.trim() || 'client';
    var parts = [];
    if (cash > 0) parts.push('Cash: RWF ' + cash.toLocaleString());
    if (momo > 0) parts.push('Momo: RWF ' + momo.toLocaleString());
    if (loan > 0) parts.push('Loan: RWF ' + loan.toLocaleString() + ' (deferred)');
    var itemLines = bulkCart.map(function(i){ return '- ' + i.name + ': ' + i.qty + ' ' + i.levelName + ' @ RWF ' + i.price.toLocaleString(); }).join('\n');
    var ok = confirm(
        'Confirm Sale for ' + customer + '?\n\n' +
        itemLines + '\n\n' +
        'Total: RWF ' + total.toLocaleString() + '\n' +
        'Payment: ' + parts.join(' | ')
    );
    if (!ok) return;

    var btn = document.getElementById('bulk_submit_btn');
    btn.disabled = true; btn.textContent = 'Saving...';

    var fd = new FormData(document.getElementById('bulkSaleForm'));
    fd.append('bulk_sale', '1');
    fd.append('ajax', '1');

    fetch('sales.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            showSaleToast(res.message, res.success);
            if (res.success) {
                // Sale reduces stock and (on loan) touches loan_clients, and adds a row
                // to recent_sales_bulk — invalidate all three before reload so other
                // pages/panels don't read stale cached data.
                Promise.all([DataCache.invalidate('products'), DataCache.invalidate('clients'), DataCache.invalidate('recent_sales_bulk')])
                    .then(function() { location.reload(); });
            } else {
                btn.textContent = 'Save Sale';
                btn.disabled = false;
            }
        })
        .catch(function() {
            showSaleToast('Network error. Please try again.', false);
            btn.textContent = 'Save Sale';
            btn.disabled = false;
        });
}
</script>
</body>
</html>
