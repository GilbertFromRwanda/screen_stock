<?php
$current_page = basename($_SERVER['PHP_SELF']);
$role = $_SESSION['role'] ?? 'staff';
try {
    $sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    socket_connect($sock, '8.8.8.8', 80);
    socket_getsockname($sock, $_nav_server_ip);
    socket_close($sock);
} catch (Throwable $e) {
    $_nav_server_ip = gethostbyname(gethostname());
}
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
            <a href="products.php"  class="tn-item<?= $current_page==='products.php'  ? ' active':'' ?>">&#9643; Products</a>
            <a href="stock.php"     class="tn-item<?= $current_page==='stock.php'      ? ' active':'' ?>">&#8862; Stock</a>

            <?php $pa = in_array($current_page,['purchases.php','new-purchase.php','purchase_advice.php']); ?>
            <div class="tn-dropdown<?= $pa?' active':'' ?>">
                <button class="tn-item tn-drop-btn" type="button">&#10549; Purchases <span class="tn-chev">&#9660;</span></button>
                <div class="tn-drop-menu">
                    <a href="purchases.php"       class="tn-drop-item<?= $current_page==='purchases.php'       ?' active':'' ?>">View All</a>
                    <a href="new-purchase.php"    class="tn-drop-item<?= $current_page==='new-purchase.php'    ?' active':'' ?>">New Purchase</a>
                    <a href="purchase_advice.php" class="tn-drop-item<?= $current_page==='purchase_advice.php' ?' active':'' ?>">Purchase Advice</a>
                </div>
            </div>

            <?php $sa = in_array($current_page,['sales.php','sale_bulk.php','sale_retail.php','sale_external.php']); ?>
            <div class="tn-dropdown<?= $sa?' active':'' ?>">
                <button class="tn-item tn-drop-btn" type="button">&#10548; Sales <span class="tn-chev">&#9660;</span></button>
                <div class="tn-drop-menu">
                    <a href="sales.php"         class="tn-drop-item<?= $current_page==='sales.php'         ?' active':'' ?>">View All</a>
                    <a href="sale_bulk.php"     class="tn-drop-item<?= $current_page==='sale_bulk.php'     ?' active':'' ?>">New Bulk Sale</a>
                    <a href="sale_retail.php"   class="tn-drop-item<?= $current_page==='sale_retail.php'   ?' active':'' ?>">New Retail Sale</a>
                    <a href="sale_external.php" class="tn-drop-item<?= $current_page==='sale_external.php' ?' active':'' ?>">New External Sale</a>
                </div>
            </div>
              <?php $fa = in_array($current_page,['loans.php','all_loans.php']); ?>
            <div class="tn-dropdown<?= $fa?' active':'' ?>">
                <button class="tn-item tn-drop-btn" type="button">Loan <span class="tn-chev">&#9660;</span></button>
                <div class="tn-drop-menu">
                    
                    <a href="loans.php"    class="tn-drop-item<?= $current_page==='loans.php'    ?' active':'' ?>">Loans by Client</a>
                    <a href="all_loans.php"class="tn-drop-item<?= $current_page==='all_loans.php'?' active':'' ?>">All Loans</a>
                </div>
            </div>
           

            <a href="wishlist.php" class="tn-item<?= $current_page==='wishlist.php'?' active':'' ?>">&#9733; Wishlist</a>
            <a href="notes.php"    class="tn-item<?= $current_page==='notes.php'   ?' active':'' ?>">&#10000; Notes</a>
            <a href="losses.php"   class="tn-item<?= $current_page==='losses.php'   ?' active':'' ?>">&#10005; Loss</a>


            <?php if (in_array($role,['admin','manager','superadmin'])): ?>
            <?php $ra = in_array($current_page,['summary-revenue.php','revenue.php']); ?>
            <div class="tn-dropdown<?= $ra?' active':'' ?>">
                <button class="tn-item tn-drop-btn" type="button">Reports <span class="tn-chev">&#9660;</span></button>
                <div class="tn-drop-menu">
                    <a href="summary-revenue.php" class="tn-drop-item<?= $current_page==='summary-revenue.php'?' active':'' ?>">Revenue Summary</a>
                    <a href="revenue.php"         class="tn-drop-item<?= $current_page==='revenue.php'        ?' active':'' ?>">Profit Analysis</a>
                </div>
            </div>
            <?php endif; ?>

            <?php if (in_array($role,['admin','manager','superadmin'])): ?>
            <?php $aa = in_array($current_page,['companies.php','users.php','run_update.php','database.php','audit_log.php','qr_call.php']); ?>
            <div class="tn-dropdown<?= $aa?' active':'' ?>">
                <button class="tn-item tn-drop-btn" type="button">&#9881; Admin <span class="tn-chev">&#9660;</span></button>
                <div class="tn-drop-menu">
                    <?php if ($role==='superadmin'): ?>
                    <a href="companies.php"  class="tn-drop-item<?= $current_page==='companies.php' ?' active':'' ?>">Companies</a>
                    <?php endif; ?>
                    <a href="users.php"      class="tn-drop-item<?= $current_page==='users.php'     ?' active':'' ?>">Users</a>
                    <a href="audit_log.php"  class="tn-drop-item<?= $current_page==='audit_log.php' ?' active':'' ?>">Audit Log</a>
                    <a href="qr_call.php"    class="tn-drop-item<?= $current_page==='qr_call.php'   ?' active':'' ?>">&#128222; QR Code</a>
                    <?php if (in_array($role,['admin','superadmin'])): ?>
                    <a href="run_update.php" class="tn-drop-item<?= $current_page==='run_update.php'?' active':'' ?>">Run Updates</a>
                    <a href="database.php"   class="tn-drop-item<?= $current_page==='database.php'  ?' active':'' ?>">Database</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

        </div><!-- /topnav-menu -->

        <!-- User + Logout -->
        <div class="topnav-user">
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
    <a href="sales.php"         class="qb-btn qb-sale">Sales</a>
    <a href="sale_bulk.php"     class="qb-btn qb-sale">+ Bulk Sale</a>
    <a href="sale_retail.php"   class="qb-btn qb-sale">+ Retail Sale</a>
    <a href="sale_external.php" class="qb-btn qb-sale">+ Ext. Sale</a>
    <a href="new-purchase.php"  class="qb-btn qb-buy">+ Purchase</a>
    <a href="expenses.php"      class="qb-btn qb-exp">+ Expense</a>
    <a href="loans.php"         class="qb-btn qb-loan">+ Loan by Client</a>
    <span class="qb-ip">
        &#128187; <span id="qb-ip-text"><?= htmlspecialchars($_nav_server_ip) ?></span>
        <button class="qb-ip-copy" onclick="qbCopyIP()" title="Copy IP">&#128203;</button>
    </span>
</div>

<style>
/* ── Top Navigation Bar ────────────────────────────────────────────────────── */
:root { --tn-h: 52px; --tn-bg: #0f172a; --qb-h: 34px; }

.topnav {
    position: fixed; top: 0; left: 0; right: 0; height: var(--tn-h);
    background: var(--tn-bg);
    box-shadow: 0 2px 16px rgba(0,0,0,.28);
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
    width: 28px; height: 28px; border-radius: 7px;
    background: linear-gradient(135deg,#3b82f6,#6366f1);
    display: flex; align-items: center; justify-content: center;
    font-size: 10px; font-weight: 800; color: #fff; flex-shrink: 0;
}
.topnav-brand-text {
    font-size: 13px; font-weight: 700; color: #f1f5f9; line-height: 1;
}
.topnav-brand-text span { color: #64748b; font-weight: 500; margin-left: 2px; }

/* Nav menu */
.topnav-menu {
    display: flex; align-items: center; gap: 2px;
    flex: 1; overflow: visible;
}

/* Nav items */
.tn-item {
    display: flex; align-items: center; gap: 5px;
    padding: 5px 10px; border-radius: 6px;
    color: #94a3b8; text-decoration: none;
    font-size: 12.5px; font-weight: 500; white-space: nowrap;
    transition: background .15s, color .15s;
    background: none; border: none; cursor: pointer; font-family: inherit;
}
.tn-item:hover { background: rgba(255,255,255,.08); color: #f1f5f9; }
.tn-item.active { background: rgba(59,130,246,.18); color: #93c5fd; }

/* Dropdown */
.tn-dropdown { position: relative; }
.tn-dropdown > .tn-item.active { background: rgba(59,130,246,.18); color: #93c5fd; }
.tn-chev { font-size: 9px; opacity: .6; margin-left: 2px; }

.tn-drop-menu {
    display: none;
    position: absolute; top: calc(100% + 6px); left: 0;
    background: #1e293b; border: 1px solid rgba(255,255,255,.08);
    border-radius: 8px; min-width: 170px;
    box-shadow: 0 8px 24px rgba(0,0,0,.35);
    padding: 4px; z-index: 200;
}
.tn-dropdown.open .tn-drop-menu { display: block; }

.tn-drop-item {
    display: block; padding: 7px 12px; border-radius: 6px;
    font-size: 12.5px; font-weight: 500; color: #94a3b8;
    text-decoration: none; white-space: nowrap;
    transition: background .12s, color .12s;
}
.tn-drop-item:hover { background: rgba(255,255,255,.07); color: #f1f5f9; }
.tn-drop-item.active { color: #93c5fd; background: rgba(59,130,246,.14); }

/* User section */
.topnav-user {
    display: flex; align-items: center; gap: 8px;
    flex-shrink: 0; margin-left: 8px;
    border-left: 1px solid rgba(255,255,255,.08); padding-left: 12px;
}
.topnav-avatar {
    width: 28px; height: 28px; border-radius: 50%; flex-shrink: 0;
    background: linear-gradient(135deg,#3b82f6,#6366f1);
    display: flex; align-items: center; justify-content: center;
    font-size: 11px; font-weight: 700; color: #fff;
}
.topnav-user-info { line-height: 1.2; }
.topnav-uname { font-size: 12px; font-weight: 600; color: #f1f5f9; white-space: nowrap; max-width: 120px; overflow: hidden; text-overflow: ellipsis; }
.topnav-urole { font-size: 10px; color: #64748b; }
.topnav-logout {
    font-size: 16px; color: #64748b; text-decoration: none;
    padding: 4px; border-radius: 5px;
    transition: color .15s, background .15s; line-height: 1; flex-shrink: 0;
}
.topnav-logout:hover { color: #f87171; background: rgba(248,113,113,.1); }

/* Hamburger (hidden on desktop) */
.topnav-toggle {
    display: none; background: none; border: none; color: #f1f5f9;
    font-size: 20px; cursor: pointer; padding: 4px 8px; border-radius: 6px;
    margin-left: auto; flex-shrink: 0;
}

/* Quick-access bar */
.quickbar {
    position: fixed; top: var(--tn-h); left: 0; right: 0; height: var(--qb-h);
    background: #fff; border-bottom: 1px solid #e2e8f0;
    display: flex; align-items: center; gap: 6px;
    padding: 0 16px; z-index: 999;
    box-shadow: 0 1px 4px rgba(0,0,0,.06);
}
.qb-label {
    font-size: 11px; font-weight: 600; color: #94a3b8;
    text-transform: uppercase; letter-spacing: .6px;
    margin-right: 2px; flex-shrink: 0;
}
.qb-btn {
    display: inline-flex; align-items: center;
    padding: 3px 10px; border-radius: 99px;
    font-size: 11.5px; font-weight: 600; text-decoration: none;
    white-space: nowrap; transition: opacity .15s, transform .1s;
    border: 1px solid transparent;
}
.qb-btn:hover { opacity: .82; transform: translateY(-1px); }
.qb-sale { background: #dbeafe; color: #1d4ed8; border-color: #bfdbfe; }
.qb-buy  { background: #dcfce7; color: #15803d; border-color: #bbf7d0; }
.qb-exp  { background: #fef3c7; color: #b45309; border-color: #fde68a; }
.qb-loan { background: #f3e8ff; color: #7e22ce; border-color: #e9d5ff; }
.qb-ip {
    margin-left: auto; flex-shrink: 0;
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 11px; font-weight: 600; color: #475569;
    font-family: monospace; letter-spacing: .3px;
    background: #f1f5f9; border: 1px solid #e2e8f0;
    border-radius: 99px; padding: 2px 6px 2px 10px;
    white-space: nowrap;
}
.qb-ip-copy {
    background: none; border: none; cursor: pointer;
    padding: 1px 3px; border-radius: 4px; font-size: 12px;
    line-height: 1; color: #94a3b8;
    transition: color .15s, background .15s;
}
.qb-ip-copy:hover { color: #3b82f6; background: #dbeafe; }

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
        background: #0f172a; padding: 8px 12px 16px;
        border-bottom: 1px solid rgba(255,255,255,.08);
        gap: 2px; overflow-y: auto; max-height: calc(100vh - var(--tn-h));
        box-shadow: 0 8px 24px rgba(0,0,0,.4);
    }
    .topnav-menu.is-open { display: flex; }
    .topnav-toggle { display: flex; align-items: center; }

    .tn-dropdown { width: 100%; }
    .tn-dropdown > .tn-item { width: 100%; }
    .tn-drop-menu {
        position: static; box-shadow: none; border: none;
        background: rgba(255,255,255,.04); border-radius: 6px;
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
