<?php
require_once 'config.php';
if (!isLoggedIn()) redirect('login.php');
$today = date('Y-m-d');
$hour  = (int)date('H');
$greeting = $hour < 12 ? 'Good Morning' : ($hour < 17 ? 'Good Afternoon' : 'Good Evening');
$first_name = htmlspecialchars(explode(' ', $_SESSION['full_name'] ?? $_SESSION['username'])[0]);
$user_role  = $_SESSION['role'] ?? 'user';
$user_id    = (int)$_SESSION['user_id'];
try {
    $sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    socket_connect($sock, '8.8.8.8', 80);
    socket_getsockname($sock, $server_ip);
    socket_close($sock);
} catch (Throwable $e) {
    $server_ip = gethostbyname(gethostname());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Screen Stock</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/dashboard.css">
    <style>
        /* Skeleton shimmer */
        .skel {
            display: inline-block;
            background: linear-gradient(90deg,#e5e7eb 25%,#f3f4f6 50%,#e5e7eb 75%);
            background-size: 200% 100%;
            animation: skel-anim 1.4s infinite;
            border-radius: 4px;
            min-width: 50px;
            min-height: 14px;
            vertical-align: middle;
        }
        @keyframes skel-anim {
            0%   { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }
        .skel-num { min-width: 80px; min-height: 22px; }
        .skel-sm  { min-width: 40px; min-height: 12px; }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php include 'sidebar.php'; ?>

    <div class="main-content">

        <!-- Welcome -->
        <div class="welcome-message">
            <div>
                <h2>Welcome back, <?= $first_name; ?>! 👋</h2>
                <p><?= date('l, F j, Y'); ?> - Here's your business overview</p>
                <p style="margin:4px 0 0;font-size:12px;color:var(--secondary);">
                    🖥 Server IP:
                    <span style="font-family:monospace;font-weight:600;color:var(--dark);
                                 background:var(--gray-100);border:1px solid var(--gray-200);
                                 border-radius:5px;padding:1px 7px;">
                        <?= htmlspecialchars($server_ip) ?>
                    </span>
                </p>
            </div>
            <div style="display:flex;align-items:center;gap:12px;">
                <button id="moneyToggleBtn" onclick="toggleMoneyFormat()"
                    style="padding:6px 14px;border:1px solid var(--gray-300);border-radius:99px;background:#fff;font-size:12px;cursor:pointer;color:var(--secondary);white-space:nowrap;">
                    Show full
                </button>
                <div class="welcome-time"><?= $greeting; ?></div>
            </div>
        </div>

        <!-- Stats Grid -->
        <div class="stats-grid">

            <div class="stat-card">
                <div class="stat-icon">📦</div>
                <div class="stat-label">Total Products</div>
                <div class="stat-number" id="d-products"><span class="skel skel-num">&nbsp;</span></div>
                <div class="stat-trend"><span>Active in inventory</span></div>
                <div class="stat-footer"><a href="products.php" style="color:#667eea;text-decoration:none;">View all products →</a></div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">💰</div>
                <div class="stat-label">Stock Value (Selling)</div>
                <div class="stat-number" id="d-sell-total"><span class="skel skel-num">&nbsp;</span></div>
                <div class="stat-trend">
                    <span class="stock-status">
                        <span id="d-sell-dot" class="stock-dot"></span>
                        Warehouse + Retail selling price
                    </span>
                </div>
                <div class="stat-footer" id="d-sell-footer"><span class="skel skel-sm">&nbsp;</span></div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">🏷️</div>
                <div class="stat-label">Stock Value (Purchase)</div>
                <div class="stat-number" id="d-cost-total"><span class="skel skel-num">&nbsp;</span></div>
                <div class="stat-footer" id="d-cost-footer"><span class="skel skel-sm">&nbsp;</span></div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">🛒</div>
                <div class="stat-label">Today's Sales</div>
                <div class="stat-number" id="d-today-total"><span class="skel skel-num">&nbsp;</span></div>
                <div class="stat-trend" id="d-trend"><span class="skel skel-sm">&nbsp;</span></div>
                <div class="stat-footer" id="d-today-footer"><span class="skel skel-sm">&nbsp;</span></div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">💵</div>
                <div class="stat-label">Today's Revenue</div>
                <div class="stat-number" id="d-today-profit"><span class="skel skel-num">&nbsp;</span></div>
                <div class="stat-footer" id="d-profit-footer"><span class="skel skel-sm">&nbsp;</span></div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">📊</div>
                <div class="stat-label">This Week</div>
                <div class="stat-number" id="d-week-sales"><span class="skel skel-num">&nbsp;</span></div>
                <div class="stat-footer" id="d-week-footer"><span class="skel skel-sm">&nbsp;</span></div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">📈</div>
                <div class="stat-label">This Month</div>
                <div class="stat-number" id="d-month-sales"><span class="skel skel-num">&nbsp;</span></div>
                <div class="stat-trend" id="d-month-trend"><span class="skel skel-sm">&nbsp;</span></div>
                <div class="stat-footer" id="d-month-footer"><span class="skel skel-sm">&nbsp;</span></div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">🏪</div>
                <div class="stat-label">Retail Shop</div>
                <div class="stat-number" id="d-retail-pcs"><span class="skel skel-num">&nbsp;</span></div>
                <div class="stat-trend"><span>Pieces available for retail</span></div>
                <div class="stat-footer" id="d-retail-footer"><span class="skel skel-sm">&nbsp;</span></div>
            </div>

        </div>

        <!-- Collection Breakdown -->
        <div style="margin-bottom:24px;">
            <div class="chart-container" style="padding:20px 24px;">
                <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:18px;">
                    <div>
                        <h3 style="margin:0 0 2px;">Collection Breakdown</h3>
                        <p id="coll-subtitle" style="font-size:12px;color:var(--secondary);margin:0;">Today</p>
                    </div>
                    <form id="coll-form" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                        <input type="date" id="coll-from" value="<?= $today; ?>"
                            style="padding:6px 10px;border:1px solid var(--gray-300);border-radius:var(--radius);font-size:13px;">
                        <span style="font-size:12px;color:var(--secondary);">to</span>
                        <input type="date" id="coll-to" value="<?= $today; ?>"
                            style="padding:6px 10px;border:1px solid var(--gray-300);border-radius:var(--radius);font-size:13px;">
                        <?php if ($user_role === 'admin' || $user_role === 'superadmin'): ?>
                        <select id="coll-user" style="padding:6px 10px;border:1px solid var(--gray-300);border-radius:var(--radius);font-size:13px;background:var(--white);">
                            <option value="0">— All users —</option>
                        </select>
                        <?php else: ?>
                        <input type="hidden" id="coll-user" value="<?= $user_id; ?>">
                        <?php endif; ?>
                        <button type="button" onclick="fetchCollection()" style="padding:6px 14px;background:var(--primary);color:#fff;border:none;border-radius:var(--radius);font-size:13px;cursor:pointer;">Filter</button>
                        <button type="button" id="coll-today-btn" onclick="fetchCollection('<?= $today; ?>','<?= $today; ?>',0)"
                            style="display:none;padding:6px 10px;background:var(--gray-200);color:var(--dark);border:none;border-radius:var(--radius);font-size:13px;cursor:pointer;">Today</button>
                        <span id="coll-loader" style="display:none;font-size:12px;color:var(--secondary);">Loading…</span>
                    </form>
                </div>

                <div id="coll-data" style="display:none;">
                    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:16px;">
                        <div style="background:#f0fdf4;border-radius:12px;padding:16px;border-left:4px solid #22c55e;">
                            <div style="display:flex;align-items:center;gap:7px;margin-bottom:10px;">
                                <span style="font-size:18px;">💵</span>
                                <span style="font-size:12px;font-weight:700;color:#15803d;text-transform:uppercase;letter-spacing:.5px;">Cash</span>
                            </div>
                            <div id="coll-cash-amount" style="font-size:18px;font-weight:800;color:#111;margin-bottom:10px;">RWF 0</div>
                            <div style="background:#dcfce7;border-radius:99px;height:6px;margin-bottom:5px;">
                                <div id="coll-cash-bar" style="background:#22c55e;height:6px;border-radius:99px;width:0%;transition:width .4s;"></div>
                            </div>
                            <div id="coll-cash-pct" style="font-size:11px;color:#15803d;font-weight:600;">0% of total</div>
                        </div>
                        <div style="background:#eff6ff;border-radius:12px;padding:16px;border-left:4px solid #3b82f6;">
                            <div style="display:flex;align-items:center;gap:7px;margin-bottom:10px;">
                                <span style="font-size:18px;">📱</span>
                                <span style="font-size:12px;font-weight:700;color:#1d4ed8;text-transform:uppercase;letter-spacing:.5px;">Momo</span>
                            </div>
                            <div id="coll-momo-amount" style="font-size:18px;font-weight:800;color:#111;margin-bottom:10px;">RWF 0</div>
                            <div style="background:#dbeafe;border-radius:99px;height:6px;margin-bottom:5px;">
                                <div id="coll-momo-bar" style="background:#3b82f6;height:6px;border-radius:99px;width:0%;transition:width .4s;"></div>
                            </div>
                            <div id="coll-momo-pct" style="font-size:11px;color:#1d4ed8;font-weight:600;">0% of total</div>
                        </div>
                        <div style="background:#fffbeb;border-radius:12px;padding:16px;border-left:4px solid #f59e0b;">
                            <div style="display:flex;align-items:center;gap:7px;margin-bottom:10px;">
                                <span style="font-size:18px;">🔖</span>
                                <span style="font-size:12px;font-weight:700;color:#b45309;text-transform:uppercase;letter-spacing:.5px;">Loan</span>
                            </div>
                            <div id="coll-loan-amount" style="font-size:18px;font-weight:800;color:#111;margin-bottom:10px;">RWF 0</div>
                            <div style="background:#fef3c7;border-radius:99px;height:6px;margin-bottom:5px;">
                                <div id="coll-loan-bar" style="background:#f59e0b;height:6px;border-radius:99px;width:0%;transition:width .4s;"></div>
                            </div>
                            <div id="coll-loan-pct" style="font-size:11px;color:#b45309;font-weight:600;">0% deferred</div>
                        </div>
                    </div>
                    <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 16px;background:#fafafa;border-radius:10px;border:1px solid #f3f4f6;">
                        <div style="display:flex;align-items:center;gap:8px;">
                            <span style="font-size:16px;">⚠️</span>
                            <div>
                                <div style="font-size:13px;font-weight:600;color:#374151;">Total Outstanding Loans</div>
                                <div style="font-size:11px;color:var(--secondary);">All unpaid client balances</div>
                            </div>
                        </div>
                        <div style="text-align:right;">
                            <div id="coll-outstanding-amount" style="font-size:18px;font-weight:800;color:#f59e0b;">RWF 0</div>
                            <a href="loans.php" style="font-size:11px;color:var(--primary);text-decoration:none;">View details →</a>
                        </div>
                    </div>
                </div>

                <div id="coll-empty" style="text-align:center;padding:32px 0;color:var(--secondary);">
                    <div style="font-size:32px;margin-bottom:8px;">💳</div>
                    <div style="font-size:13px;">Loading collection data…</div>
                    <div id="coll-empty-loans" style="display:none;margin-top:16px;padding:12px 16px;background:#fffbeb;border-radius:10px;border:1px solid #fde68a;gap:12px;align-items:center;">
                        <span style="font-size:13px;color:#92400e;">⚠️ Outstanding Loans:</span>
                        <strong id="coll-empty-outstanding" style="color:#f59e0b;">RWF 0</strong>
                        <a href="loans.php" style="font-size:11px;color:var(--primary);">View →</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Alerts -->
        <div id="d-alerts"></div>

        <!-- Dashboard Row: Chart + Stock Health -->
        <div class="dashboard-row">
            <div class="chart-container">
                <h3>Sales Trend (Last 7 Days) <small>Bulk vs Retail</small></h3>
                <div class="chart-wrapper" style="height:300px;">
                    <canvas id="salesChart"></canvas>
                </div>
            </div>
            <div>
                <div class="chart-container" style="margin-bottom:20px;">
                    <h3>Quick Actions</h3>
                    <div class="quick-actions">
                        <a href="sales.php"     class="quick-action-btn"><span>💰</span>New Sale</a>
                        <a href="purchases.php" class="quick-action-btn"><span>📦</span>Purchase</a>
                        <a href="stock.php"     class="quick-action-btn"><span>🔄</span>Move Stock</a>
                        <a href="products.php"  class="quick-action-btn"><span>🏷️</span>Add Product</a>
                    </div>
                </div>
                <div class="chart-container">
                    <h3>Stock Health</h3>
                    <div id="d-low-stock">
                        <div style="text-align:center;padding:24px 0;">
                            <span class="skel" style="width:80%;display:block;margin:8px auto;height:16px;"></span>
                            <span class="skel" style="width:60%;display:block;margin:8px auto;height:16px;"></span>
                        </div>
                    </div>
                    <div class="mini-stats">
                        <div class="mini-stat">
                            <div class="mini-stat-value" id="d-mov-count"><span class="skel skel-sm">&nbsp;</span></div>
                            <div class="mini-stat-label">Movements this week</div>
                        </div>
                        <div class="mini-stat">
                            <div class="mini-stat-value" style="color:var(--success);" id="d-suppliers"><span class="skel skel-sm">&nbsp;</span></div>
                            <div class="mini-stat-label">Active Suppliers</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Top Products -->
        <div style="margin-bottom:24px;">
            <div class="chart-container">
                <h3>Top Selling Products</h3>
                <div style="overflow-x:auto;">
                    <table class="top-table">
                        <thead>
                            <tr>
                                <th>Product</th><th>Category</th>
                                <th style="text-align:center;">Bulk</th>
                                <th style="text-align:center;">Retail</th>
                                <th style="text-align:right;">Revenue</th>
                            </tr>
                        </thead>
                        <tbody id="d-top-tbody">
                            <tr><td colspan="5" style="padding:30px;text-align:center;">
                                <span class="skel" style="width:70%;display:block;margin:auto;height:16px;"></span>
                            </td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Performance Summary -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:8px;">
            <div class="perf-box">
                <h4>Performance Summary</h4>
                <div class="perf-grid">
                    <div class="perf-item">
                        <div class="perf-item-label">Avg. Daily Sales</div>
                        <div class="perf-item-value" id="d-avg-daily"><span class="skel skel-sm">&nbsp;</span></div>
                    </div>
                    <div class="perf-item">
                        <div class="perf-item-label">Best Day</div>
                        <div class="perf-item-value" id="d-best-day"><span class="skel skel-sm">&nbsp;</span></div>
                    </div>
                    <div class="perf-item">
                        <div class="perf-item-label">Total Transactions</div>
                        <div class="perf-item-value" id="d-total-trans"><span class="skel skel-sm">&nbsp;</span></div>
                    </div>
                    <div class="perf-item">
                        <div class="perf-item-label">Stock Turnover</div>
                        <div class="perf-item-value" id="d-turnover"><span class="skel skel-sm">&nbsp;</span></div>
                    </div>
                </div>
            </div>
            <div class="perf-box">
                <h4>Quick Insights</h4>
                <div class="insight-row">
                    <span class="insight-label">Bulk vs Retail</span>
                    <span class="insight-value" id="d-bulk-retail"><span class="skel skel-sm">&nbsp;</span></span>
                </div>
                <div class="insight-row">
                    <span class="insight-label">Avg. Transaction</span>
                    <span class="insight-value" id="d-avg-trans"><span class="skel skel-sm">&nbsp;</span></span>
                </div>
                <div class="insight-row">
                    <span class="insight-label">Peak Hour</span>
                    <span class="insight-value" id="d-peak-hour"><span class="skel skel-sm">&nbsp;</span></span>
                </div>
                <div class="insight-row">
                    <span class="insight-label">Items per Sale</span>
                    <span class="insight-value" id="d-avg-items"><span class="skel skel-sm">&nbsp;</span></span>
                </div>
                <div class="insight-row" style="border-top:1px solid var(--gray-100);margin-top:4px;padding-top:8px;">
                    <span class="insight-label">💵 Cash (today)</span>
                    <span class="insight-value" style="color:#16a34a;" id="d-ins-cash"><span class="skel skel-sm">&nbsp;</span></span>
                </div>
                <div class="insight-row">
                    <span class="insight-label">📱 Momo (today)</span>
                    <span class="insight-value" style="color:#2563eb;" id="d-ins-momo"><span class="skel skel-sm">&nbsp;</span></span>
                </div>
                <div class="insight-row">
                    <span class="insight-label">🔖 Loans (today)</span>
                    <span class="insight-value" style="color:#d97706;" id="d-ins-loan"><span class="skel skel-sm">&nbsp;</span></span>
                </div>
            </div>
        </div>

    </div><!-- /main-content -->
</div><!-- /dashboard-container -->

<script src="chart.js"></script>
<script src="script.js"></script>
<script>
(function () {
'use strict';

var collToday = '<?= $today; ?>';
var moneyFull = localStorage.getItem('moneyFull') === '1';
var chartInstance = null;

// ── Money formatting ─────────────────────────────────────────────────────────
function numAbbr(n) {
    n = Math.round(n); var abs = Math.abs(n), sign = n < 0 ? '-' : '';
    if (abs >= 1e6) return sign + parseFloat((abs/1e6).toFixed(1)) + 'M';
    if (abs >= 1e3) return sign + parseFloat((abs/1e3).toFixed(1)) + 'K';
    return sign + abs.toLocaleString();
}
function numFull(n) { return (n < 0 ? '-' : '') + Math.abs(Math.round(n)).toLocaleString(); }
function moneySpan(n) {
    var abbr = numAbbr(n), full = numFull(n), shown = moneyFull ? full : abbr;
    return '<span class="money-val" data-abbr="'+abbr+'" data-full="'+full+'">'+shown+'</span>';
}
function applyMoneyFormat(full) {
    moneyFull = full;
    document.querySelectorAll('.money-val').forEach(function(el) {
        el.textContent = full ? el.dataset.full : el.dataset.abbr;
    });
    var btn = document.getElementById('moneyToggleBtn');
    if (btn) btn.textContent = full ? 'Show abbr' : 'Show full';
}
window.toggleMoneyFormat = function() {
    var next = !moneyFull;
    localStorage.setItem('moneyFull', next ? '1' : '0');
    applyMoneyFormat(next);
};
applyMoneyFormat(moneyFull);

// ── Set helpers ──────────────────────────────────────────────────────────────
function set(id, html) { var el = document.getElementById(id); if (el) el.innerHTML = html; }
function setText(id, val) { var el = document.getElementById(id); if (el) el.textContent = val; }

// ── Populate dashboard ───────────────────────────────────────────────────────
function populate(d) {
    // Stat cards
    set('d-products', d.total_products.toLocaleString());

    var sellTotal = d.sell_wh + d.sell_rt;
    set('d-sell-total', 'RWF ' + moneySpan(sellTotal));
    var dot = document.getElementById('d-sell-dot');
    if (dot) dot.className = 'stock-dot ' + (sellTotal > 1e6 ? 'green' : sellTotal > 5e5 ? 'yellow' : 'red');
    set('d-sell-footer', 'Warehouse: RWF ' + moneySpan(d.sell_wh) + ' | Retail: RWF ' + moneySpan(d.sell_rt));

    set('d-cost-total', 'RWF ' + moneySpan(d.cost_total));
    var updatedLabel = d.cache_updated ? 'Updated ' + new Date(d.cache_updated).toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'}) : '';
    set('d-cost-footer',
        'Warehouse: RWF ' + moneySpan(d.cost_wh) + ' | Retail: RWF ' + moneySpan(d.cost_rt) +
        '<div style="margin-top:4px;display:flex;align-items:center;gap:8px;">' +
        '<span style="font-size:10px;color:#9ca3af;">' + updatedLabel + '</span>' +
        '<a href="#" id="d-cost-recalc" style="font-size:10px;color:#667eea;text-decoration:none;" onclick="recalcStockValues(event)">Recalculate</a>' +
        '</div>');

    set('d-today-total', 'RWF ' + moneySpan(d.today_t));
    var trend = d.today_t - d.yesterday_t;
    var trendPct = d.yesterday_t > 0 ? Math.abs(trend / d.yesterday_t * 100).toFixed(1) : 0;
    var trendHtml = trend > 0
        ? '<span class="trend-up">↑ '+trendPct+'% vs yesterday</span>'
        : trend < 0
            ? '<span class="trend-down">↓ '+trendPct+'% vs yesterday</span>'
            : '<span>→ Same as yesterday</span>';
    set('d-trend', trendHtml);
    set('d-today-footer',
        'Bulk: RWF '+moneySpan(d.today_bulk)+' | Retail: RWF '+moneySpan(d.today_rt)+
        '<div style="margin-top:4px;display:flex;gap:8px;flex-wrap:wrap;">'+
        '<span style="color:#16a34a;">💵 '+moneySpan(d.today_cash)+'</span>'+
        '<span style="color:#2563eb;">📱 '+moneySpan(d.today_momo)+'</span>'+
        '<span style="color:#d97706;">🔖 '+moneySpan(d.today_loan)+'</span>'+
        '</div>');

    set('d-today-profit', 'RWF ' + moneySpan(d.today_profit));
    set('d-profit-footer', 'Sales: RWF '+moneySpan(d.today_t)+' | Cost: RWF '+moneySpan(d.today_t - d.today_profit));

    set('d-week-sales', 'RWF ' + moneySpan(d.week_sales));
    set('d-week-footer', 'Profit: RWF ' + moneySpan(d.week_profit));

    set('d-month-sales', 'RWF ' + moneySpan(d.month_sales));
    set('d-month-trend', '<span>Monthly target: RWF ' + moneySpan(d.month_sales * 1.2) + '</span>');
    var dayProgress = Math.round((new Date().getDate() / new Date(new Date().getFullYear(), new Date().getMonth()+1, 0).getDate()) * 100);
    set('d-month-footer', 'Month progress: ' + dayProgress + '%');

    set('d-retail-pcs', d.rt_pcs.toLocaleString() + ' pcs');
    set('d-retail-footer', 'Value: RWF ' + moneySpan(d.sell_rt));

    // Mini stats
    set('d-mov-count', d.mov_count.toLocaleString());
    set('d-suppliers', d.total_suppliers.toLocaleString());

    // Performance
    set('d-avg-daily', 'RWF ' + moneySpan(d.avg_daily));
    setText('d-best-day', d.best_day);
    set('d-total-trans', d.total_trans.toLocaleString());
    setText('d-turnover', d.stock_turnover + '%');
    setText('d-bulk-retail', d.bulk_pct + '% / ' + d.retail_pct + '%');
    set('d-avg-trans', 'RWF ' + moneySpan(d.avg_trans));
    setText('d-peak-hour', d.peak_hour);
    setText('d-avg-items', d.avg_items.toFixed(1));
    set('d-ins-cash', 'RWF ' + moneySpan(d.today_cash));
    set('d-ins-momo', 'RWF ' + moneySpan(d.today_momo));
    set('d-ins-loan', 'RWF ' + moneySpan(d.today_loan));

    // Low stock
    buildLowStock(d.low_stock, d.retail_empty);

    // Top products
    buildTopProducts(d.top_products);

    // Alerts
    buildAlerts(d);

    // Chart
    buildChart(d.chart_labels, d.chart_bulk, d.chart_retail);

    // Users dropdown for collection
    var sel = document.getElementById('coll-user');
    if (sel && sel.tagName === 'SELECT') {
        var html = '<option value="0">— All users —</option>';
        d.users.forEach(function(u) {
            html += '<option value="'+u.id+'">'+escHtml(u.name)+'</option>';
        });
        sel.innerHTML = html;
    }

    // Trigger collection widget
    fetchCollection(collToday, collToday, sel && sel.tagName !== 'SELECT' ? sel.value : 0);
}

function buildLowStock(items, emptyCount) {
    var html = '';
    if (items.length > 0) {
        items.forEach(function(item) {
            html += '<div class="low-stock-item"><div>'+
                '<div class="low-stock-name">'+escHtml(item.name)+'</div>'+
                '<div class="low-stock-sub">Reorder at '+item.reorder_level+'</div>'+
                '</div><span class="low-stock-badge">'+item.quantity+' left</span></div>';
        });
    } else {
        html = '<div style="text-align:center;padding:24px 0;color:var(--success);">'+
            '<div style="font-size:36px;">✓</div>'+
            '<div style="font-size:13px;color:var(--secondary);margin-top:6px;">All products well stocked</div></div>';
    }
    set('d-low-stock', html);
}

function buildTopProducts(products) {
    var html = '';
    if (products.length > 0) {
        products.forEach(function(p) {
            html += '<tr>'+
                '<td style="font-weight:600;">'+escHtml(p.name)+'</td>'+
                '<td><span class="cat-badge">'+escHtml(p.category || 'N/A')+'</span></td>'+
                '<td style="text-align:center;color:var(--secondary);">'+p.bulk_qty+' pkg</td>'+
                '<td style="text-align:center;color:var(--secondary);">'+p.retail_qty+' pcs</td>'+
                '<td style="text-align:right;font-weight:700;color:var(--success);">RWF '+moneySpan(p.revenue)+'</td>'+
                '</tr>';
        });
    } else {
        html = '<tr><td colspan="5" style="padding:30px;text-align:center;color:var(--secondary);">No sales data yet</td></tr>';
    }
    set('d-top-tbody', html);
}

function buildAlerts(d) {
    var alerts = [];
    if (d.low_stock.length > 0) alerts.push({type:'warning', icon:'⚠️', title:'Low Stock Alert',
        message:'You have '+d.low_stock.length+' product(s) below reorder level. Check stock management.', link:'stock.php'});
    if (d.retail_empty > 0) alerts.push({type:'info', icon:'🛒', title:'Retail Stock Empty',
        message:d.retail_empty+' product(s) have no pieces in retail shop. Move stock from warehouse.', link:'stock.php'});
    if (d.today_t === 0) alerts.push({type:'info', icon:'📉', title:'No Sales Today',
        message:"You haven't recorded any sales today. Start selling!", link:'sales.php'});

    if (alerts.length === 0) { set('d-alerts', ''); return; }

    var html = '<div class="alerts-container">'+
        '<h3 onclick="toggleAlerts()" style="cursor:pointer;user-select:none;">'+
        '<span style="display:flex;align-items:center;gap:10px;">Notifications &amp; Alerts '+
        '<span class="badge-count">'+alerts.length+' new</span></span>'+
        '<span id="alertsChevron" style="font-size:12px;color:var(--secondary);transition:transform .2s;">&#9654;</span></h3>'+
        '<div id="alertsBody" style="display:none;">';
    alerts.forEach(function(a) {
        html += '<div class="alert-item '+a.type+'">'+
            '<div class="alert-icon">'+a.icon+'</div>'+
            '<div class="alert-content">'+
            '<div class="alert-title">'+escHtml(a.title)+'</div>'+
            '<div class="alert-message">'+escHtml(a.message)+'</div>'+
            '<a href="'+a.link+'" class="alert-link">Take action →</a>'+
            '</div></div>';
    });
    html += '</div></div>';
    set('d-alerts', html);
}

function buildChart(labels, bulk, retail) {
    var canvas = document.getElementById('salesChart');
    if (!canvas) return;
    if (chartInstance) { chartInstance.destroy(); chartInstance = null; }
    try {
        chartInstance = new Chart(canvas.getContext('2d'), {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {label:'Bulk Sales', data:bulk, borderColor:'rgba(102,126,234,1)',
                     backgroundColor:'rgba(102,126,234,0.1)', borderWidth:2, tension:0.4, pointRadius:4,
                     pointBackgroundColor:'rgba(102,126,234,1)'},
                    {label:'Retail Sales', data:retail, borderColor:'rgba(245,87,108,1)',
                     backgroundColor:'rgba(245,87,108,0.1)', borderWidth:2, tension:0.4, pointRadius:4,
                     pointBackgroundColor:'rgba(245,87,108,1)'}
                ]
            },
            options: {responsive:true, maintainAspectRatio:false,
                animation:{duration:400},
                plugins:{legend:{display:true, position:'top'}}}
        });
    } catch(e) { console.error(e); }
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Collection widget ────────────────────────────────────────────────────────
function dateLabel(from, to) {
    if (from === to) {
        if (from === collToday) return 'Today';
        return new Date(from+'T12:00:00').toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'});
    }
    return new Date(from+'T12:00:00').toLocaleDateString('en-US',{month:'short',day:'numeric'})
        + ' – ' + new Date(to+'T12:00:00').toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'});
}
function renderCollection(d) {
    var total = d.cash + d.momo + d.loan;
    document.getElementById('coll-subtitle').textContent = dateLabel(d.from, d.to);
    document.getElementById('coll-today-btn').style.display = (d.from === collToday && d.to === collToday) ? 'none' : '';
    document.getElementById('coll-outstanding-amount').textContent = 'RWF ' + numAbbr(d.outstanding);
    document.getElementById('coll-empty-outstanding').textContent  = 'RWF ' + numAbbr(d.outstanding);
    document.getElementById('coll-empty-loans').style.display = d.outstanding > 0 ? 'inline-flex' : 'none';
    if (total > 0) {
        var cPct = Math.round(d.cash/total*100), mPct = Math.round(d.momo/total*100), lPct = 100-cPct-mPct;
        document.getElementById('coll-cash-amount').textContent = 'RWF '+numAbbr(d.cash);
        document.getElementById('coll-cash-bar').style.width    = cPct+'%';
        document.getElementById('coll-cash-pct').textContent    = cPct+'% of total';
        document.getElementById('coll-momo-amount').textContent = 'RWF '+numAbbr(d.momo);
        document.getElementById('coll-momo-bar').style.width    = mPct+'%';
        document.getElementById('coll-momo-pct').textContent    = mPct+'% of total';
        document.getElementById('coll-loan-amount').textContent = 'RWF '+numAbbr(d.loan);
        document.getElementById('coll-loan-bar').style.width    = lPct+'%';
        document.getElementById('coll-loan-pct').textContent    = lPct+'% deferred';
        document.getElementById('coll-data').style.display  = 'block';
        document.getElementById('coll-empty').style.display = 'none';
    } else {
        document.getElementById('coll-data').style.display  = 'none';
        document.getElementById('coll-empty').style.display = 'block';
        document.getElementById('coll-empty').querySelector('div:nth-child(2)').textContent = 'No sales recorded for this period';
    }
}
window.fetchCollection = function(from, to, userId) {
    from   = from   !== undefined ? from   : document.getElementById('coll-from').value;
    to     = to     !== undefined ? to     : document.getElementById('coll-to').value;
    var userEl = document.getElementById('coll-user');
    userId = userId !== undefined ? userId : (userEl ? userEl.value : 0);
    if (userEl && userEl.tagName === 'INPUT') userId = userEl.value;
    document.getElementById('coll-from').value = from;
    document.getElementById('coll-to').value   = to;
    document.getElementById('coll-loader').style.display = 'inline';
    fetch('ajax_collection.php?coll_from='+from+'&coll_to='+to+'&user_id='+userId)
        .then(function(r){return r.json();})
        .then(renderCollection)
        .catch(function(){})
        .finally(function(){ document.getElementById('coll-loader').style.display='none'; });
};

// ── Alerts toggle ────────────────────────────────────────────────────────────
window.toggleAlerts = function() {
    var body = document.getElementById('alertsBody');
    var chevron = document.getElementById('alertsChevron');
    if (!body) return;
    var open = body.style.display !== 'none';
    body.style.display    = open ? 'none' : 'block';
    if (chevron) chevron.style.transform = open ? '' : 'rotate(90deg)';
};

// ── Recalculate stock values ──────────────────────────────────────────────────
window.recalcStockValues = function(e) {
    if (e) e.preventDefault();
    var link = document.getElementById('d-cost-recalc');
    if (link) link.textContent = 'Calculating…';
    fetch('ajax_recalc_stock.php')
        .then(function(r) { return r.json(); })
        .then(function(d) {
            set('d-cost-total', 'RWF ' + moneySpan(d.cost_total));
            set('d-sell-total', 'RWF ' + moneySpan(d.sell_total));
            var updatedLabel = d.updated_at ? 'Updated ' + new Date(d.updated_at).toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'}) : '';
            set('d-cost-footer',
                'Warehouse: RWF ' + moneySpan(d.cost_wh) + ' | Retail: RWF ' + moneySpan(d.cost_rt) +
                '<div style="margin-top:4px;display:flex;align-items:center;gap:8px;">' +
                '<span style="font-size:10px;color:#9ca3af;">' + updatedLabel + '</span>' +
                '<a href="#" id="d-cost-recalc" style="font-size:10px;color:#667eea;text-decoration:none;" onclick="recalcStockValues(event)">Recalculate</a>' +
                '</div>');
            set('d-sell-footer', 'Warehouse: RWF ' + moneySpan(d.sell_wh) + ' | Retail: RWF ' + moneySpan(d.sell_rt));
        })
        .catch(function() { if (link) link.textContent = 'Recalculate'; });
};

// ── Boot: fetch all data ──────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    fetch('ajax_dashboard.php')
        .then(function(r) { return r.json(); })
        .then(populate)
        .catch(function(err) {
            console.error('Dashboard load failed', err);
            set('d-products', '—');
        });
});

})();
</script>
</body>
</html>
