<?php
$current_page = basename($_SERVER['PHP_SELF']);
$role = $_SESSION['role'] ?? 'staff';
$_is_super = ($role === 'superadmin');

$nav = [
    'main' => [
        'label' => 'Main',
        'items' => [
            ['href' => 'dashboard.php',  'icon' => '▣',  'label' => 'Dashboard'],
            ['href' => 'products.php',   'icon' => '◫',  'label' => 'Products'],
            ['href' => 'stock.php',      'icon' => '⊞',  'label' => 'Stock'],
            [
                'href'    => 'purchases.php',
                'icon'    => '⤵',
                'label'   => 'Purchases',
                'submenu' => [
                    ['href' => 'purchases.php',    'label' => 'View All'],
                    ['href' => 'new-purchase.php', 'label' => 'New Purchase'],
                ],
            ],
            [
                'href'    => 'sales.php',
                'icon'    => '⤴',
                'label'   => 'Sales',
                'submenu' => [
                    ['href' => 'sales.php',         'label' => 'View All '],
                    ['href' => 'sale_bulk.php',      'label' => 'New Bulk Sale'],
                    ['href' => 'sale_retail.php',    'label' => 'New Retail Sale'],
                    ['href' => 'sale_external.php',  'label' => 'New External Sale'],
                ],
            ],
            // ['href' => 'suppliers.php',  'icon' => '⊙',  'label' => 'Suppliers'],
        ]
    ],
    'finance' => [
        'label' => 'Finance',
        'items' => [
            // ['href' => 'consumption.php','icon' => '⌂',  'label' => 'Home Consumption'],
            ['href' => 'expenses.php',   'icon' => '−',  'label' => 'Expenses'],
            [
                'href'    => 'loans.php',
                'icon'    => '⇄',
                'label'   => 'Loans',
                'submenu' => [
                    ['href' => 'loans.php',     'label' => 'By Client'],
                    ['href' => 'all_loans.php', 'label' => 'All Loans'],
                ],
            ],
            ['href' => 'losses.php',     'icon' => '↓',  'label' => 'Losses'],
            // ['href' => 'boaster.php',    'icon' => '↑',  'label' => 'Top Up'],
        ]
    ],
    'reports' => [
        'label' => 'Reports',
        'roles' => ['admin', 'manager', 'superadmin'],
        'items' => [
            ['href' => 'summary-revenue.php','icon' => '◈', 'label' => 'Revenue Summary'],
            ['href' => 'revenue.php',        'icon' => '◉', 'label' => 'Profit Analysis'],
        ]
    ],
    'admin' => [
        'label' => 'Admin',
        'roles' => ['admin', 'manager', 'superadmin'],
        'items' => [
            ['href' => 'companies.php', 'icon' => '◫', 'label' => 'Companies', 'roles_item' => ['superadmin']],
            ['href' => 'users.php',     'icon' => '◎', 'label' => 'Users',     'roles_item' => ['admin','manager','superadmin']],
            ['href' => 'run_update.php','icon' => '⚙', 'label' => 'Run Updates','roles_item' => ['admin','superadmin']],
            ['href' => 'database.php',  'icon' => '⊗', 'label' => 'Database',  'roles_item' => ['admin','superadmin']],
        ]
    ],
];
?>
<button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle navigation">&#9776;</button>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<div class="sidebar">
    <div class="sidebar-brand">
        <div class="sidebar-brand-icon">SS</div>
        <div>
            <div class="sidebar-brand-name">Screen</div>
            <div class="sidebar-brand-sub">Stock</div>
        </div>
    </div>

    <nav class="sidebar-nav">
        <?php foreach ($nav as $section): ?>
            <?php if (isset($section['roles']) && !in_array($role, $section['roles'])) continue; ?>
            <div class="nav-section-label"><?php echo $section['label']; ?></div>
            <?php foreach ($section['items'] as $item): ?>
                <?php
                if (isset($item['roles_item']) && !in_array($role, $item['roles_item'])) continue;

                if (!empty($item['submenu'])):
                    $sub_active = false;
                    foreach ($item['submenu'] as $sub) {
                        if ($current_page === $sub['href']) { $sub_active = true; break; }
                    }
                    $group_open = $sub_active;
                ?>
                <div class="nav-item-group<?php echo $group_open ? ' open' : ''; ?>">
                    <button type="button"
                            class="nav-item nav-item-toggle<?php echo $group_open ? ' active' : ''; ?>"
                            onclick="toggleSubmenu(this)">
                        <span class="nav-icon"><?php echo $item['icon']; ?></span>
                        <span class="nav-label"><?php echo $item['label']; ?></span>
                        <span class="nav-chevron">&#8250;</span>
                    </button>
                    <div class="nav-submenu">
                        <?php foreach ($item['submenu'] as $sub):
                            $sub_is_active = $current_page === $sub['href'];
                        ?>
                        <a href="<?php echo $sub['href']; ?>"
                           class="nav-subitem<?php echo $sub_is_active ? ' active' : ''; ?>">
                            <span class="nav-sub-dot"></span>
                            <?php echo $sub['label']; ?>
                            <?php if ($sub_is_active): ?><span class="nav-active-dot"></span><?php endif; ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php else:
                    $active = $current_page === $item['href'];
                ?>
                <a href="<?php echo $item['href']; ?>" class="nav-item<?php echo $active ? ' active' : ''; ?>">
                    <span class="nav-icon"><?php echo $item['icon']; ?></span>
                    <span class="nav-label"><?php echo $item['label']; ?></span>
                    <?php if ($active): ?><span class="nav-active-dot"></span><?php endif; ?>
                </a>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="sidebar-avatar"><?php echo strtoupper(substr($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'U', 0, 1)); ?></div>
            <div class="sidebar-user-info">
                <div class="sidebar-user-name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username']); ?></div>
                <div class="sidebar-user-role"><?php echo $role === 'superadmin' ? 'Super Admin' : ucfirst($role); ?></div>
            </div>
        </div>
        <a href="logout.php" class="sidebar-logout" title="Logout">⏻</a>
    </div>
</div>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ── Sidebar redesign ─────────────────────────────────────────────────────── */
.sidebar {
    width: 240px;
    background: #0f172a;
    color: #cbd5e1;
    padding: 0;
    position: fixed;
    height: 100vh;
    display: flex;
    flex-direction: column;
    box-shadow: 4px 0 24px rgba(0,0,0,.18);
    border-right: none;
    z-index: 100;
}

/* Brand */
.sidebar-brand {
    display: flex; align-items: center; gap: 10px;
    padding: 14px 16px 12px;
    border-bottom: 1px solid rgba(255,255,255,.07);
    flex-shrink: 0;
}
.sidebar-brand-icon {
    width: 30px; height: 30px; border-radius: 8px;
    background: linear-gradient(135deg,#3b82f6,#6366f1);
    display: flex; align-items: center; justify-content: center;
    font-size: 11px; font-weight: 800; color: #fff; flex-shrink: 0;
}
.sidebar-brand-name {
    font-size: 13px; font-weight: 700; color: #f1f5f9; line-height: 1.1;
}
.sidebar-brand-sub {
    font-size: 10px; color: #64748b; margin-top: 1px;
}

/* Nav */
.sidebar-nav {
    flex: 1; overflow-y: auto; padding: 6px 8px;
    scrollbar-width: thin; scrollbar-color: #1e293b transparent;
}
.sidebar-nav::-webkit-scrollbar { width: 3px; }
.sidebar-nav::-webkit-scrollbar-thumb { background: #1e293b; border-radius: 3px; }

.nav-section-label {
    font-size: 9.5px; font-weight: 700; text-transform: uppercase;
    letter-spacing: 1px; color: #475569;
    padding: 8px 8px 3px;
}
.nav-section-label:first-child { padding-top: 2px; }

.nav-item {
    display: flex; align-items: center; gap: 8px;
    padding: 6px 10px; border-radius: 7px; margin-bottom: 1px;
    color: #94a3b8; text-decoration: none;
    font-size: 12.5px; font-weight: 500;
    transition: background .15s, color .15s;
    position: relative;
}
.nav-item:hover {
    background: rgba(255,255,255,.06);
    color: #f1f5f9;
}
.nav-item.active {
    background: linear-gradient(90deg,rgba(59,130,246,.22),rgba(99,102,241,.12));
    color: #93c5fd;
}
.nav-icon {
    font-size: 13px; width: 18px; text-align: center;
    flex-shrink: 0; opacity: .85;
}
.nav-label { flex: 0; }
/* Submenu */
.nav-item-toggle {
    width: 100%; background: none; border: none; cursor: pointer; font-family: inherit;
}
.nav-chevron {
    font-size: 16px; margin-left: auto; color: #475569;
    transition: transform .2s; line-height: 1; flex-shrink: 0;
}
.nav-item-group.open .nav-chevron { transform: rotate(90deg); }

.nav-submenu {
    overflow: hidden; max-height: 0;
    transition: max-height .25s ease;
}
.nav-item-group.open .nav-submenu { max-height: 200px; }

.nav-subitem {
    display: flex; align-items: center; gap: 7px;
    padding: 5px 10px 5px 30px;
    font-size: 12px; font-weight: 500; color: #64748b;
    text-decoration: none; border-radius: 7px; margin-bottom: 1px;
    transition: background .15s, color .15s; position: relative;
}
.nav-subitem:hover { background: rgba(255,255,255,.06); color: #f1f5f9; }
.nav-subitem.active { color: #93c5fd; }
.nav-sub-dot {
    width: 4px; height: 4px; border-radius: 50%; flex-shrink: 0;
    background: #334155; transition: background .15s;
}
.nav-subitem:hover .nav-sub-dot,
.nav-subitem.active .nav-sub-dot { background: #3b82f6; }

.nav-active-dot {
    width: 5px; height: 5px; border-radius: 50%;
    background: #3b82f6; flex-shrink: 0;
}

/* Footer */
.sidebar-footer {
    display: flex; align-items: center; gap: 8px;
    padding: 10px 12px;
    border-top: 1px solid rgba(255,255,255,.07);
    background: rgba(0,0,0,.15);
    flex-shrink: 0;
}
.sidebar-avatar {
    width: 28px; height: 28px; border-radius: 50%; flex-shrink: 0;
    background: linear-gradient(135deg,#3b82f6,#6366f1);
    display: flex; align-items: center; justify-content: center;
    font-size: 11px; font-weight: 700; color: #fff;
}
.sidebar-user { display: flex; align-items: center; gap: 8px; flex: 1; min-width: 0; }
.sidebar-user-info { min-width: 0; }
.sidebar-user-name {
    font-size: 12px; font-weight: 600; color: #f1f5f9;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.sidebar-user-role { font-size: 10px; color: #64748b; margin-top: 1px; }
.sidebar-logout {
    font-size: 16px; color: #64748b; text-decoration: none;
    flex-shrink: 0; padding: 3px; border-radius: 5px;
    transition: color .15s, background .15s;
    line-height: 1;
}
.sidebar-logout:hover { color: #f87171; background: rgba(248,113,113,.1); }

/* Adjust main content margin */
.main-content { margin-left: 240px; }

/* Slide-in transition for mobile */
.sidebar { transition: transform 0.3s ease; }

/* Hamburger toggle button (hidden on desktop) */
.sidebar-toggle {
    display: none;
    position: fixed;
    top: 12px;
    left: 12px;
    z-index: 1001;
    background: #0f172a;
    color: #f1f5f9;
    border: none;
    border-radius: 8px;
    width: 42px;
    height: 42px;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    cursor: pointer;
    box-shadow: 0 2px 10px rgba(0,0,0,.35);
    line-height: 1;
}

/* Dark overlay behind open sidebar */
.sidebar-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(15,23,42,.55);
    z-index: 999;
    backdrop-filter: blur(2px);
}
.sidebar-overlay.is-open { display: block; }

@media (max-width: 768px) {
    .sidebar {
        z-index: 1000;
        transform: translateX(-100%);
    }
    .sidebar.is-open {
        transform: translateX(0);
    }
    .sidebar-toggle { display: flex; }
    .main-content { margin-left: 0; }
}
</style>

<script>
(function () {
    var toggle  = document.getElementById('sidebarToggle');
    var sidebar = document.querySelector('.sidebar');
    var overlay = document.getElementById('sidebarOverlay');
    if (!toggle || !sidebar || !overlay) return;

    toggle.addEventListener('click', function () {
        sidebar.classList.toggle('is-open');
        overlay.classList.toggle('is-open');
    });
    overlay.addEventListener('click', function () {
        sidebar.classList.remove('is-open');
        overlay.classList.remove('is-open');
    });
    // Close sidebar on nav link click (mobile)
    sidebar.querySelectorAll('.nav-item, .nav-subitem').forEach(function (link) {
        link.addEventListener('click', function () {
            if (window.innerWidth <= 768) {
                sidebar.classList.remove('is-open');
                overlay.classList.remove('is-open');
            }
        });
    });
})();

function toggleSubmenu(btn) {
    btn.closest('.nav-item-group').classList.toggle('open');
}
</script>
