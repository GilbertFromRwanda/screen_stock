<?php
$current_page = basename($_SERVER['PHP_SELF']);
$role = $_SESSION['role'] ?? 'staff';

// Ensure $conn is defined. Prefer existing global connection, otherwise try
// a local default connection to avoid undefined variable errors.
if (!isset($conn)) {
    if (isset($GLOBALS['conn'])) {
        $conn = $GLOBALS['conn'];
    } else {
        // Best-effort fallback; adjust credentials/db as appropriate for your env.
        $conn = @mysqli_connect('127.0.0.1', 'root', '', '');
    }
}

// Companies this user can switch their view to. Superadmin can browse "as" any
// active company (plus an explicit "All Companies" option, cid()=null); everyone
// else is limited to their own + whatever they were granted (own + granted). Only
// shown when there's an actual choice — a single-company user gets no dropdown.
$_nav_accessible_companies = [];
$_nav_show_all_option = false;
if (isLoggedIn()) {
    if ($role === 'superadmin') {
        $res_sc = mysqli_query($conn, "SELECT id, name FROM companies WHERE status='active' ORDER BY name");
        while ($row = mysqli_fetch_assoc($res_sc)) {
            $row['id'] = (int)$row['id'];
            $_nav_accessible_companies[] = $row;
        }
        $_nav_show_all_option = true;
    } else {
        $_nav_accessible_companies = getAccessibleCompanies($conn, (int)$_SESSION['user_id']);
    }
}
$_nav_viewing_company_id = cid();
// True when a non-superadmin, multi-company user is viewing the combined
// "All (My) Companies" aggregate (cidList() !== null) rather than one company.
$_nav_viewing_all_mine = $role !== 'superadmin' && cidList() !== null;

try {
    $sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    socket_connect($sock, '8.8.8.8', 80);
    socket_getsockname($sock, $_nav_server_ip);
    socket_close($sock);
} catch (Throwable $e) {
    $_nav_server_ip = gethostbyname(gethostname());
}
//  $_nav_server_ip='';
?>
<nav class="topnav" id="topnav">
    <div class="topnav-inner">

        <!-- Brand -->
        <a href="dashboard.php" class="topnav-brand">
            <div class="topnav-brand-icon">SS</div>
            <span class="topnav-brand-text">Screen<span>Stock</span></span>
        </a>

        <!-- Hamburger (mobile) -->
        <button class="topnav-toggle" id="topnavToggle" aria-label="Toggle menu">&#9776;</button>

        <!-- Nav links -->
        <div class="topnav-menu" id="topnavMenu">

            <a href="dashboard.php" class="tn-item<?= $current_page==='dashboard.php' ? ' active':'' ?>">&#9635; Dashboard</a>

            <?php $ia = in_array($current_page,['products.php','categories.php','stock.php','stock_adjust.php','zero_stock.php']);
                  $has_inv = hasPermission('inventory'); $has_sa = hasPermission('stock_adjust'); ?>
            <?php if ($has_inv || $has_sa): ?>
            <div class="tn-dropdown<?= $ia?' active':'' ?>">
                <button class="tn-item tn-drop-btn" type="button">&#9643; Inventory <span class="tn-chev">&#9660;</span></button>
                <div class="tn-drop-menu">
                    <?php if ($has_inv): ?>
                    <a href="products.php"   class="tn-drop-item<?= $current_page==='products.php'  ?' active':'' ?>">View Product List</a>
                    <a href="categories.php" class="tn-drop-item<?= $current_page==='categories.php'?' active':'' ?>">Manage Categories</a>
                    <a href="stock.php"      class="tn-drop-item<?= $current_page==='stock.php'     ?' active':'' ?>">View Stock</a>
                    <?php endif; ?>
                    <?php if ($has_sa): ?>
                    <a href="stock_adjust.php" class="tn-drop-item<?= $current_page==='stock_adjust.php'?' active':'' ?>">Adjust Stock</a>
                    <a href="zero_stock.php"   class="tn-drop-item<?= $current_page==='zero_stock.php'  ?' active':'' ?>">Restock</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php $pa = in_array($current_page,['purchases.php','new-purchase.php','purchase_advice.php','wishlist.php']);
                  $has_pur = hasPermission('purchases'); ?>
            <?php if ($has_pur): ?>
            <div class="tn-dropdown<?= $pa?' active':'' ?>">
                <button class="tn-item tn-drop-btn" type="button">&#10549; Purchases <span class="tn-chev">&#9660;</span></button>
                <div class="tn-drop-menu">
                    <a href="purchases.php"       class="tn-drop-item<?= $current_page==='purchases.php'       ?' active':'' ?>">View All</a>
                    <?php if (hasPermission('purchases','create')): ?>
                    <a href="new-purchase.php"    class="tn-drop-item<?= $current_page==='new-purchase.php'    ?' active':'' ?>">New Purchase</a>
                    <?php endif; ?>
                    <a href="purchase_advice.php" class="tn-drop-item<?= $current_page==='purchase_advice.php' ?' active':'' ?>">Purchase Advice</a>
                    <a href="wishlist.php"         class="tn-drop-item<?= $current_page==='wishlist.php'        ?' active':'' ?>">&#9733; Wishlist</a>
                </div>
            </div>
            <?php endif; ?>

            <?php $sa = in_array($current_page,['sales.php','sale_bulk.php','sale_retail.php','sale_external.php']);
                  $has_sal = hasPermission('sales'); ?>
            <?php if ($has_sal): ?>
            <div class="tn-dropdown<?= $sa?' active':'' ?>">
                <button class="tn-item tn-drop-btn" type="button">&#10548; Sales <span class="tn-chev">&#9660;</span></button>
                <div class="tn-drop-menu">
                    <a href="sales.php"         class="tn-drop-item<?= $current_page==='sales.php'         ?' active':'' ?>">View All</a>
                    <?php if (hasPermission('sales','create')): ?>
                    <a href="sale_bulk.php"     class="tn-drop-item<?= $current_page==='sale_bulk.php'     ?' active':'' ?>">Bulk Sale</a>
                    <a href="sale_retail.php"   class="tn-drop-item<?= $current_page==='sale_retail.php'   ?' active':'' ?>">Retail Sale</a>
                    <a href="sale_external.php" class="tn-drop-item<?= $current_page==='sale_external.php' ?' active':'' ?>">External Sale</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php $fa = in_array($current_page,['loans.php','all_loans.php']);
                  $has_loans = hasPermission('loans'); ?>
            <?php if ($has_loans): ?>
            <div class="tn-dropdown<?= $fa?' active':'' ?>">
                <button class="tn-item tn-drop-btn" type="button">Loans <span class="tn-chev">&#9660;</span></button>
                <div class="tn-drop-menu">
                    <a href="loans.php"     class="tn-drop-item<?= $current_page==='loans.php'    ?' active':'' ?>">By Client</a>
                    <a href="all_loans.php" class="tn-drop-item<?= $current_page==='all_loans.php'?' active':'' ?>">All Loans</a>
                </div>
            </div>
            <?php endif; ?>

            <?php $oa = in_array($current_page,['orders.php','order_new.php','order_link_new.php']);
                  $has_ord = hasPermission('orders'); ?>
            <?php if ($has_ord): ?>
            <div class="tn-dropdown<?= $oa?' active':'' ?>">
                <button class="tn-item tn-drop-btn" type="button">Orders <span class="tn-chev">&#9660;</span></button>
                <div class="tn-drop-menu">
                    <a href="orders.php"    class="tn-drop-item<?= $current_page==='orders.php'   ?' active':'' ?>">View Orders</a>
                    <?php if (hasPermission('orders','create')): ?>
                    <a href="order_new.php" class="tn-drop-item<?= $current_page==='order_new.php'?' active':'' ?>">New Order</a>
                    <a href="order_link_new.php" class="tn-drop-item<?= $current_page==='order_link_new.php'?' active':'' ?>">Customer Order Link</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php $ma = in_array($current_page,['losses.php','consumption.php','notes.php','qr_call.php']);
                  $has_los = hasPermission('losses'); $has_con = hasPermission('consumption'); $has_not = hasPermission('notes'); ?>
            <?php if ($has_los || $has_con || $has_not || in_array($role,['admin','superadmin'])): ?>
            <div class="tn-dropdown<?= $ma?' active':'' ?>">
                <button class="tn-item tn-drop-btn" type="button">&#8942; More <span class="tn-chev">&#9660;</span></button>
                <div class="tn-drop-menu">
                    <?php if ($has_los): ?>
                    <a href="losses.php"      class="tn-drop-item<?= $current_page==='losses.php'     ?' active':'' ?>">&#10005; Loss</a>
                    <?php endif; ?>
                    <?php if ($has_con): ?>
                    <a href="consumption.php" class="tn-drop-item<?= $current_page==='consumption.php'?' active':'' ?>">&#9663; Consumption</a>
                    <?php endif; ?>
                    <?php if ($has_not): ?>
                    <a href="notes.php"       class="tn-drop-item<?= $current_page==='notes.php'      ?' active':'' ?>">&#10000; Notes</a>
                    <?php endif; ?>
                    <a href="qr_call.php"    class="tn-drop-item<?= $current_page==='qr_call.php'   ?' active':'' ?>">&#128222; QR Code</a>
                </div>
            </div>
            <?php endif; ?>


            <?php $has_rep = hasPermission('reports'); $has_fin = hasPermission('financials'); ?>
            <?php if ($has_rep || $has_fin): ?>
            <?php $ra = in_array($current_page,['summary-revenue.php','revenue.php','loss_products.php']); ?>
            <div class="tn-dropdown<?= $ra?' active':'' ?>">
                <button class="tn-item tn-drop-btn" type="button">Reports <span class="tn-chev">&#9660;</span></button>
                <div class="tn-drop-menu">
                    <?php if ($has_rep): ?>
                    <a href="summary-revenue.php" class="tn-drop-item<?= $current_page==='summary-revenue.php'?' active':'' ?>">Revenue Summary</a>
                    <?php endif; ?>
                    <?php if ($has_fin): ?>
                    <a href="revenue.php" class="tn-drop-item<?= $current_page==='revenue.php'?' active':'' ?>">Profit Analysis</a>
                    <a href="loss_products.php" class="tn-drop-item<?= $current_page==='loss_products.php'?' active':'' ?>">Sales Causing Loss</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (in_array($role,['admin','manager','superadmin'])): ?>
            <?php $aa = in_array($current_page,['companies.php','users.php','run_update.php','database.php','audit_log.php','qr_call.php','stock_adjust.php']); ?>
            <div class="tn-dropdown<?= $aa?' active':'' ?>">
                <button class="tn-item tn-drop-btn" type="button">&#9881; Admin <span class="tn-chev">&#9660;</span></button>
                <div class="tn-drop-menu">
                    <?php if ($role==='superadmin'): ?>
                    <a href="companies.php"  class="tn-drop-item<?= $current_page==='companies.php' ?' active':'' ?>">Companies</a>
                    <?php endif; ?>
                    <?php if (in_array($role,['admin','superadmin'])): ?>
                    <a href="users.php"      class="tn-drop-item<?= $current_page==='users.php'     ?' active':'' ?>">Users</a>
                    <?php endif; ?>
                    <?php if (hasPermission('audit_log')): ?>
                    <a href="audit_log.php"  class="tn-drop-item<?= $current_page==='audit_log.php' ?' active':'' ?>">Audit Log</a>
                    <?php endif; ?>
                    <?php if (in_array($role,['admin','superadmin'])): ?>
                    <a href="run_update.php" class="tn-drop-item<?= $current_page==='run_update.php'?' active':'' ?>">Run Updates</a>
                    <a href="backup.php" class="tn-drop-item">&#11015; Backup</a>
                    <?php endif; ?>
                    <?php if ($role === 'superadmin'): ?>
                    <a href="database.php"   class="tn-drop-item<?= $current_page==='database.php'  ?' active':'' ?>">Database</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

        </div><!-- /topnav-menu -->

        <!-- User + Logout -->
        <div class="topnav-user">
            <?php if (hasPermission('orders')): ?>
            <div class="tn-notif-wrap" id="tnNotifWrap">
                <button type="button" class="tn-notif" id="tnNotifBell" title="Notifications">
                    &#128276;
                    <span class="tn-notif-badge" id="tnNotifBadge" style="display:none;">0</span>
                </button>
                <div class="tn-notif-panel" id="tnNotifPanel">
                    <div class="tn-notif-panel-hdr">Notifications</div>
                    <div class="tn-notif-list" id="tnNotifList">
                        <div class="tn-notif-empty">No notifications</div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <?php if (count($_nav_accessible_companies) > 1 || $_nav_show_all_option): ?>
            <form method="POST" action="switch_company.php" class="tn-company-switch">
                <input type="hidden" name="return" value="<?= htmlspecialchars($current_page) ?>">
                <select name="company_id" class="tn-company-select" onchange="this.form.submit()"
                        title="<?= $_nav_viewing_all_mine ? 'Viewing combined data — pick one company to create or edit records' : 'Viewing company' ?>">
                    <?php if ($_nav_show_all_option): ?>
                        <option value="" <?= $_nav_viewing_company_id === null ? 'selected' : '' ?>>All Companies</option>
                    <?php endif; ?>
                    <?php if ($role !== 'superadmin' && count($_nav_accessible_companies) > 1): ?>
                        <option value="all_mine" <?= $_nav_viewing_all_mine ? 'selected' : '' ?>>All My Companies</option>
                    <?php endif; ?>
                    <?php foreach ($_nav_accessible_companies as $co): ?>
                        <option value="<?= (int)$co['id'] ?>" <?= (!$_nav_viewing_all_mine && $_nav_viewing_company_id == $co['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($co['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($_nav_viewing_all_mine): ?>
                    <span class="tn-company-agg-tag" title="Viewing combined data — pick one company to create or edit records">&#8942; combined</span>
                <?php endif; ?>
            </form>
            <?php endif; ?>
            <div class="topnav-avatar"><?= strtoupper(substr($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'U', 0, 1)) ?></div>
            <div class="topnav-user-info">
                <div class="topnav-uname"><?= htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username']) ?></div>
                <div class="topnav-urole"><?= $role==='superadmin'?'Super Admin':ucfirst($role) ?></div>
            </div>
            <a href="logout.php" class="topnav-logout" title="Logout">&#9211;</a>
        </div>

    </div>
</nav>

<!-- Quick-access bar -->
<div class="quickbar" id="quickbar">
    <span class="qb-label">Quick:</span>
    
    <a href="sales.php"         class="qb-btn qb-sale<?= $current_page==='sales.php'         ? ' active':'' ?>">Sales</a>
    <a href="sale_bulk.php"     class="qb-btn qb-sale-bulk<?= $current_page==='sale_bulk.php'     ? ' active':'' ?>">+ Bulk Sale</a>
    <a href="sale_retail.php"   class="qb-btn qb-sale-retail<?= $current_page==='sale_retail.php'   ? ' active':'' ?>">+ Retail Sale</a>
    <a href="sale_external.php" class="qb-btn qb-sale-ext<?= $current_page==='sale_external.php' ? ' active':'' ?>">+ Ext. Sale</a>
    <a href="orders.php" class="qb-btn qb-order<?= $current_page==='orders.php' ? ' active':'' ?>">Orders</a>
    <a href="new-purchase.php"  class="qb-btn qb-buy<?= $current_page==='new-purchase.php'  ? ' active':'' ?>">+ New Purchase</a>
    <a href="expenses.php"      class="qb-btn qb-exp<?= $current_page==='expenses.php'      ? ' active':'' ?>">+ Expense</a>
    <a href="loans.php"         class="qb-btn qb-loan<?= $current_page==='loans.php'         ? ' active':'' ?>">+ Loan by Client</a>
    <a href="stock.php"         class="qb-btn qb-stock<?= $current_page==='stock.php'         ? ' active':'' ?>">Stock</a>
    
    <span class="qb-ip">
        &#128187; <span id="qb-ip-text"><?= htmlspecialchars($_nav_server_ip) ?></span>
        <button class="qb-ip-copy" onclick="qbCopyIP()" title="Copy IP">&#128203;</button>
    </span>
    
</div>

<style>
/* ── Top Navigation Bar ────────────────────────────────────────────────────── */
:root { --tn-h: 52px; --tn-bg: #103060; --tn-bg-light: #1a4280; --qb-h: 34px; }

.topnav {
    position: fixed; top: 0; left: 0; right: 0; height: var(--tn-h);
    background: var(--tn-bg);
    border-bottom: 1px solid rgba(255,255,255,.12);
    z-index: 1000;
}
.topnav-inner {
    display: flex; align-items: center; height: 100%;
    padding: 0 16px; gap: 4px;
}

/* Brand */
.topnav-brand {
    display: flex; align-items: center; gap: 8px;
    text-decoration: none; flex-shrink: 0; margin-right: 8px;
}
.topnav-brand-icon {
    width: 28px; height: 28px; border-radius: 4px;
    background: rgba(255,255,255,.15);
    display: flex; align-items: center; justify-content: center;
    font-size: 10px; font-weight: 800; color: #fff; flex-shrink: 0;
}
.topnav-brand-text {
    font-size: 13px; font-weight: 700; color: #fff; line-height: 1;
}
.topnav-brand-text span { color: rgba(255,255,255,.6); font-weight: 500; margin-left: 2px; }

/* Nav menu */
.topnav-menu {
    display: flex; align-items: center; gap: 2px;
    flex: 1; overflow: visible;
}

/* Nav items */
.tn-item {
    display: flex; align-items: center; gap: 5px;
    padding: 5px 10px; border-radius: 4px;
    color: rgba(255,255,255,.8); text-decoration: none;
    font-size: 12.5px; font-weight: 500; white-space: nowrap;
    transition: background .15s, color .15s;
    background: none; border: none; cursor: pointer; font-family: inherit;
}
.tn-item:hover { background: rgba(255,255,255,.1); color: #fff; }
.tn-item.active { background: rgba(255,255,255,.15); color: #fff; }

/* Dropdown */
.tn-dropdown { position: relative; }
.tn-dropdown > .tn-item.active { background: rgba(255,255,255,.15); color: #fff; }
.tn-chev { font-size: 9px; opacity: .6; margin-left: 2px; }

.tn-drop-menu {
    display: none;
    position: absolute; top: calc(100% + 6px); left: 0;
    background: var(--tn-bg-light); border: 1px solid rgba(255,255,255,.12);
    border-radius: 4px; min-width: 170px;
    box-shadow: 0 8px 24px rgba(0,0,0,.22);
    padding: 4px; z-index: 200;
}
.tn-dropdown.open .tn-drop-menu { display: block; }

.tn-drop-item {
    display: block; padding: 7px 12px; border-radius: 4px;
    font-size: 12.5px; font-weight: 500; color: rgba(255,255,255,.8);
    text-decoration: none; white-space: nowrap;
    transition: background .12s, color .12s;
}
.tn-drop-item:hover { background: rgba(255,255,255,.1); color: #fff; }
.tn-drop-item.active { color: #fff; background: rgba(255,255,255,.15); }

/* User section */
.topnav-user {
    display: flex; align-items: center; gap: 8px;
    flex-shrink: 0; margin-left: 8px;
    border-left: 1px solid rgba(255,255,255,.12); padding-left: 12px;
}
.tn-company-switch { display: flex; align-items: center; }
.tn-company-select {
    background: rgba(255,255,255,.08); border: 1px solid rgba(255,255,255,.15);
    color: #fff; font-size: 11.5px; font-weight: 600; font-family: inherit;
    padding: 4px 8px; border-radius: 4px; cursor: pointer; max-width: 140px;
}
.tn-company-select:hover { background: rgba(255,255,255,.15); }
.tn-company-select:focus { outline: none; border-color: rgba(255,255,255,.4); }
.tn-company-select option { background: #fff; color: #1a1a2e; }
.tn-company-agg-tag {
    font-size: 9.5px; font-weight: 700; color: #fbbf24;
    margin-left: 4px; white-space: nowrap; letter-spacing: .3px;
}
.tn-notif-wrap { position: relative; }
.tn-notif {
    position: relative; display: flex; align-items: center; justify-content: center;
    width: 28px; height: 28px; border-radius: 50%; flex-shrink: 0;
    font-size: 15px; text-decoration: none; color: rgba(255,255,255,.85);
    background: none; border: none; cursor: pointer;
    transition: background .15s, color .15s;
}
.tn-notif:hover { background: rgba(255,255,255,.1); color: #fff; }
.tn-notif-badge {
    position: absolute; top: -3px; right: -4px;
    min-width: 15px; height: 15px; padding: 0 3px; border-radius: 99px;
    background: #dc2626; color: #fff; font-size: 10px; font-weight: 700;
    line-height: 15px; text-align: center;
    box-shadow: 0 0 0 2px var(--tn-bg);
}
.tn-notif-panel {
    display: none; position: absolute; top: calc(100% + 10px); right: 0;
    width: 320px; max-height: 380px; overflow-y: auto;
    background: #fff; border: 1px solid #d0d7e3;
    border-radius: 4px; box-shadow: 0 10px 30px rgba(0,0,0,.18);
    z-index: 1100;
}
.tn-notif-panel.open { display: block; }
.tn-notif-panel-hdr {
    padding: 10px 14px; font-size: 12px; font-weight: 700; color: #103060;
    background: #e8edf5;
    border-bottom: 1px solid #d0d7e3;
}
.tn-notif-empty { padding: 20px 14px; font-size: 12.5px; color: #6b7280; text-align: center; }
.tn-notif-row {
    display: flex; flex-direction: column; gap: 4px;
    padding: 10px 14px; border-bottom: 1px solid #f1f5f9;
    font-size: 12.5px; color: #1a1a2e;
}
.tn-notif-row:last-child { border-bottom: none; }
.tn-notif-row-actions { display: flex; gap: 12px; }
.tn-notif-row-actions a, .tn-notif-row-actions span {
    font-size: 11.5px; font-weight: 700; cursor: pointer; text-decoration: none;
}
.tn-notif-row-actions a { color: #103060; }
.tn-notif-row-actions span { color: #6b7280; }
.tn-notif-row-actions a:hover, .tn-notif-row-actions span:hover { text-decoration: underline; }
.topnav-avatar {
    width: 28px; height: 28px; border-radius: 50%; flex-shrink: 0;
    background: rgba(255,255,255,.22);
    display: flex; align-items: center; justify-content: center;
    font-size: 11px; font-weight: 700; color: #fff;
}
.topnav-user-info { line-height: 1.2; }
.topnav-uname { font-size: 12px; font-weight: 600; color: #fff; white-space: nowrap; max-width: 120px; overflow: hidden; text-overflow: ellipsis; }
.topnav-urole { font-size: 10px; color: rgba(255,255,255,.6); }
.topnav-logout {
    font-size: 16px; color: rgba(255,255,255,.6); text-decoration: none;
    padding: 4px; border-radius: 4px;
    transition: color .15s, background .15s; line-height: 1; flex-shrink: 0;
}
.topnav-logout:hover { color: #fca5a5; background: rgba(220,38,38,.2); }

/* Hamburger (hidden on desktop) */
.topnav-toggle {
    display: none; background: none; border: none; color: #fff;
    font-size: 20px; cursor: pointer; padding: 4px 8px; border-radius: 4px;
    margin-left: auto; flex-shrink: 0;
}

/* Quick-access bar */
.quickbar {
    position: fixed; top: var(--tn-h); left: 0; right: 0; height: var(--qb-h);
    background: #fff; border-bottom: 1px solid #d0d7e3;
    display: flex; align-items: center; gap: 6px;
    padding: 0 16px; z-index: 999;
}
.qb-label {
    font-size: 11px; font-weight: 600; color: #6b7280;
    text-transform: uppercase; letter-spacing: .6px;
    margin-right: 2px; flex-shrink: 0;
}
.qb-btn {
    display: inline-flex; align-items: center;
    padding: 3px 10px; border-radius: 4px;
    font-size: 11.5px; font-weight: 600; text-decoration: none;
    white-space: nowrap; transition: opacity .15s, transform .1s;
    border: 1px solid transparent;
}
.qb-btn:hover { opacity: .82; transform: translateY(-1px); }
.qb-btn:active { transform: translateY(0) scale(.96); filter: brightness(.92); }
.qb-sale        { background: #e8edf5; color: #103060; border-color: #c9d6ea; }
.qb-sale-bulk   { background: #ccfbf1; color: #0d9488; border-color: #99f6e4; }
.qb-sale-retail { background: #fce7f3; color: #be185d; border-color: #fbcfe8; }
.qb-sale-ext    { background: #e0e7ff; color: #4338ca; border-color: #c7d2fe; }
.qb-buy  { background: #d1fae5; color: #059669; border-color: #a7f3d0; }
.qb-exp  { background: #fef3c7; color: #d97706; border-color: #fde68a; }
.qb-loan { background: #ede9fe; color: #5b21b6; border-color: #ddd6fe; }
.qb-stock { background: #e0f2fe; color: #0369a1; border-color: #bae6fd; }
.qb-order { background: #e8edf5; color: #1a4280; border-color: #c9d6ea; }

/* Active (current page) state — solid fill of the button's own accent colour */
.qb-btn.active { color: #fff; font-weight: 700; box-shadow: inset 0 0 0 1px rgba(0,0,0,.08); opacity: 1; }
.qb-sale.active        { background: #103060; border-color: #103060; }
.qb-sale-bulk.active   { background: #0d9488; border-color: #0d9488; }
.qb-sale-retail.active { background: #be185d; border-color: #be185d; }
.qb-sale-ext.active    { background: #4338ca; border-color: #4338ca; }
.qb-buy.active         { background: #059669; border-color: #059669; }
.qb-exp.active         { background: #d97706; border-color: #d97706; }
.qb-loan.active        { background: #5b21b6; border-color: #5b21b6; }
.qb-stock.active       { background: #0369a1; border-color: #0369a1; }
.qb-order.active       { background: #1a4280; border-color: #1a4280; }
.qb-ip {
    margin-left: auto; flex-shrink: 0;
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 11px; font-weight: 600; color: #6b7280;
    font-family: monospace; letter-spacing: .3px;
    background: #f4f6f9; border: 1px solid #d0d7e3;
    border-radius: 99px; padding: 2px 6px 2px 10px;
    white-space: nowrap;
}
.qb-ip-copy {
    background: none; border: none; cursor: pointer;
    padding: 1px 3px; border-radius: 4px; font-size: 12px;
    line-height: 1; color: #6b7280;
    transition: color .15s, background .15s;
}
.qb-ip-copy:hover { color: #103060; background: #e8edf5; }

/* Push page content below topnav + quickbar */
.main-content { margin-left: 0 !important; margin-top: calc(var(--tn-h) + var(--qb-h)); }
.dashboard-container { display: block !important; }

@media (max-width: 768px) {
    .quickbar { gap: 4px; padding: 0 10px; overflow-x: auto; scrollbar-width: none; }
    .quickbar::-webkit-scrollbar { display: none; }
    .qb-label { display: none; }
}

/* ── Mobile ────────────────────────────────────────────────────────────────── */
@media (max-width: 900px) {
    .topnav-user .topnav-user-info { display: none; }
}

@media (max-width: 768px) {
    .topnav-menu {
        display: none; flex-direction: column; align-items: stretch;
        position: fixed; top: var(--tn-h); left: 0; right: 0;
        background: var(--tn-bg); padding: 8px 12px 16px;
        border-bottom: 1px solid rgba(255,255,255,.12);
        gap: 2px; overflow-y: auto; max-height: calc(100vh - var(--tn-h));
        box-shadow: 0 8px 24px rgba(0,0,0,.3);
    }
    .topnav-menu.is-open { display: flex; }
    .topnav-toggle { display: flex; align-items: center; }

    .tn-dropdown { width: 100%; }
    .tn-dropdown > .tn-item { width: 100%; }
    .tn-drop-menu {
        position: static; box-shadow: none; border: none;
        background: rgba(255,255,255,.06); border-radius: 4px;
        margin-top: 2px; padding: 2px 0 2px 12px;
        display: none;
    }
    .tn-dropdown.open .tn-drop-menu { display: block; }

    .topnav-user { border-left: none; }
    .topnav-inner { gap: 8px; }
}
</style>

<script>
(function () {
    var toggle = document.getElementById('topnavToggle');
    var menu   = document.getElementById('topnavMenu');
    if (!toggle || !menu) return;

    toggle.addEventListener('click', function () {
        menu.classList.toggle('is-open');
    });

    // Click to open/close dropdowns (all screen sizes)
    menu.querySelectorAll('.tn-drop-btn').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var dd = btn.closest('.tn-dropdown');
            var wasOpen = dd.classList.contains('open');
            menu.querySelectorAll('.tn-dropdown').forEach(function (d) { d.classList.remove('open'); });
            if (!wasOpen) dd.classList.add('open');
        });
    });

    // Click outside closes all dropdowns
    document.addEventListener('click', function () {
        menu.querySelectorAll('.tn-dropdown').forEach(function (d) { d.classList.remove('open'); });
    });

    // Close menu when a link is clicked on mobile
    menu.querySelectorAll('.tn-item, .tn-drop-item').forEach(function (link) {
        link.addEventListener('click', function () {
            if (window.innerWidth <= 768 && link.tagName === 'A') {
                menu.classList.remove('is-open');
            }
        });
    });
})();

window.qbCopyIP = function () {
    var ip = document.getElementById('qb-ip-text').textContent.trim();
    var path = window.location.pathname.replace(/\/[^\/]*$/, '/');
    var url = 'http://' + ip + path;
    navigator.clipboard.writeText(url).then(function () {
        var btn = document.querySelector('.qb-ip-copy');
        btn.textContent = '✓';
        btn.style.color = '#16a34a';
        setTimeout(function () { btn.textContent = '📋'; btn.style.color = ''; }, 1500);
    });
};
</script>
<?php if (hasPermission('orders')): ?>
<script src="js/order-notify.js"></script>
<?php endif; ?>
