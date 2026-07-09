<?php
// Shared helper functions used across the app. Kept separate from config.php
// (which is gitignored because it holds DB credentials) so these are version
// controlled.

// Function to check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isSuperAdmin() {
    return ($_SESSION['role'] ?? '') === 'superadmin';
}

// Returns the session company_id as int, or null for superadmin
function cid(): ?int {
    return isset($_SESSION['company_id']) && $_SESSION['company_id'] !== null
        ? (int)$_SESSION['company_id'] : null;
}
// Returns company_id for SQL INSERT values ("5" or "NULL")
function cidSql(): string {
    $c = cid(); return $c !== null ? (string)$c : 'NULL';
}
// Returns "AND company_id = X" or "" (superadmin sees all)
function cidAnd(): string {
    $c = cid(); return $c !== null ? "AND company_id = $c" : '';
}
// Returns "AND alias.company_id = X" or "" — use when query has multiple joined tables
function cidAndFor(string $alias): string {
    $c = cid(); return $c !== null ? "AND $alias.company_id = $c" : '';
}
// Returns "WHERE company_id = X" or "" (superadmin sees all)
function cidWhere(): string {
    $c = cid(); return $c !== null ? "WHERE company_id = $c" : '';
}

// Returns a "MATCH(search_text) AGAINST (... IN BOOLEAN MODE)" condition for the
// products.search_text fulltext column, or "1=1" when $raw is empty/has no usable terms.
// Each word becomes a required prefix match (e.g. "ka in" -> +ka* +in*).
function productSearchSql($conn, string $raw): string {
    $raw = trim($raw);
    if ($raw === '') return '1=1';
    $terms = [];
    foreach (preg_split('/\s+/', $raw) as $w) {
        $w = preg_replace('/[+\-><()~*"@]+/', '', $w);
        if ($w === '') continue;
        $terms[] = '+' . mysqli_real_escape_string($conn, $w) . '*';
    }
    if (!$terms) return '1=1';
    $match_sql = implode(' ', $terms);
    return "MATCH(search_text) AGAINST ('$match_sql' IN BOOLEAN MODE)";
}

// Returns ['purchase_id' => ?int, 'cost_price' => float, 'pieces_per_qty' => int] from
// the most recent purchase of $product_id on/before $date (scoped to the current
// company), or null/zeros/1 if the product has never been purchased. Used to snapshot
// COGS onto a sale row at the moment it's recorded (sales.php, orders.php) instead of
// re-deriving it later from whatever the product's packaging looks like today.
// purchase_id is stored alongside the snapshot so that if that purchase is later
// edited (purchases.php), the sales costed against it can be found and re-synced.
function lastPurchaseCost(mysqli $conn, int $product_id, string $date): array {
    $date_esc = mysqli_real_escape_string($conn, $date);
    $r = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT id, cost_price, pieces_per_qty FROM purchases pu
        WHERE pu.product_id = $product_id AND pu.purchase_date <= '$date_esc' " . cidAndFor('pu') . "
        ORDER BY pu.purchase_date DESC LIMIT 1
    "));
    return [
        'purchase_id'    => $r ? (int)$r['id'] : null,
        'cost_price'     => $r ? (float)$r['cost_price'] : 0.0,
        'pieces_per_qty' => $r ? max(1, (int)$r['pieces_per_qty']) : 1,
    ];
}

// COGS for a bulk sale of $quantity units at a level that's $level_divisor pieces
// per top-level purchase unit (e.g. selling individual pieces out of a box).
// Returns ['cost_total' => float, 'purchase_id' => ?int].
function bulkSaleCost(mysqli $conn, int $product_id, string $date, float $quantity, int $level_divisor): array {
    $lp = lastPurchaseCost($conn, $product_id, $date);
    return [
        'cost_total'  => round($lp['cost_price'] * $quantity / max(1, $level_divisor), 2),
        'purchase_id' => $lp['purchase_id'],
    ];
}

// COGS for a retail sale of $pieces_sold individual pieces.
// Returns ['cost_total' => float, 'purchase_id' => ?int].
function retailSaleCost(mysqli $conn, int $product_id, string $date, int $pieces_sold): array {
    $lp = lastPurchaseCost($conn, $product_id, $date);
    return [
        'cost_total'  => round($lp['cost_price'] / $lp['pieces_per_qty'] * $pieces_sold, 2),
        'purchase_id' => $lp['purchase_id'],
    ];
}

// Function to redirect
function redirect($url) {
    header("Location: $url");
    exit();
}

// Returns true if the current session user can perform $action on $module.
// superadmin and admin always return true; manager/user check user_permissions table.
function hasPermission(string $module, string $action = 'view'): bool {
    static $cache = null;

    $role = $_SESSION['role'] ?? '';
    if (in_array($role, ['superadmin', 'admin'])) return true;

    $valid  = ['view', 'create', 'edit', 'delete'];
    $action = in_array($action, $valid) ? $action : 'view';
    $uid    = (int)($_SESSION['user_id'] ?? 0);

    if ($cache === null) {
        $cache = getUserPermissions($uid);
    }

    return $cache[$module][$action] ?? false;
}

// Returns all permissions for a user keyed by module → ['view'=>bool,'edit'=>bool,'delete'=>bool]
function getUserPermissions(int $user_id): array {
    global $conn;
    $perms = [];
    $r = mysqli_query($conn, "SELECT module, can_view, can_create, can_edit, can_delete FROM user_permissions WHERE user_id=$user_id");
    while ($row = mysqli_fetch_assoc($r)) {
        $perms[$row['module']] = [
            'view'   => (bool)$row['can_view'],
            'create' => (bool)$row['can_create'],
            'edit'   => (bool)$row['can_edit'],
            'delete' => (bool)$row['can_delete'],
        ];
    }
    return $perms;
}

// Returns a 5-digit numeric code not currently used by any active (new/open, unexpired) order link.
function generateOrderLinkCode(mysqli $conn): string {
    for ($i = 0; $i < 20; $i++) {
        $code = (string)random_int(10000, 99999);
        $r = mysqli_query($conn, "SELECT id FROM orders
            WHERE link_code='$code' AND status IN ('new','open') AND (link_expires_at IS NULL OR link_expires_at > NOW())
            LIMIT 1");
        if (mysqli_num_rows($r) === 0) return $code;
    }
    return (string)random_int(10000, 99999);
}

// Generates a customer-facing order number like ORD-20260705-7F3K: the date
// prefix is just for readability/sorting, the 4-char suffix is random so
// order numbers can't be enumerated or used to infer how many orders exist
// (unlike the old zero-padded-primary-key scheme, ORD-00001, ORD-00002...).
// Excludes visually-confusable characters (0/O, 1/I).
function generateOrderNumber(mysqli $conn): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    for ($i = 0; $i < 20; $i++) {
        $suffix = '';
        for ($j = 0; $j < 4; $j++) $suffix .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        $number = 'ORD-' . date('Ymd') . '-' . $suffix;
        $r = mysqli_query($conn, "SELECT id FROM orders WHERE order_number='$number' LIMIT 1");
        if ($r && mysqli_num_rows($r) === 0) return $number;
    }
    return 'ORD-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));
}

// Category lookup: find-or-create by name, returns [id, name]. Keeps `categories` as
// the single managed list while products.category stays a denormalized copy (used by
// search_text's FULLTEXT index and every other page that reads/filters it as plain text).
function resolve_category(mysqli $conn, string $name): array {
    $name = trim($name);
    if ($name === '') return [null, ''];
    $n = mysqli_real_escape_string($conn, $name);
    mysqli_query($conn, "INSERT IGNORE INTO categories (name) VALUES ('$n')");
    // Only bump the cache when a category was actually just created (affected_rows
    // is 0 when INSERT IGNORE skips an existing name) so js/data-cache.js's
    // 'categories' store refreshes and picks up brand-new categories immediately.
    if (mysqli_affected_rows($conn) === 1) touchCacheStore($conn, 'categories');
    $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id, name FROM categories WHERE name='$n' LIMIT 1"));
    return [$row['id'], $row['name']];
}

function get_categories(mysqli $conn): array {
    $rows = [];
    $r = mysqli_query($conn, "SELECT id, name FROM categories ORDER BY name ASC");
    while ($row = mysqli_fetch_assoc($r)) $rows[] = $row;
    return $rows;
}

// Marks $store ('products' or 'clients') as changed for the current company so
// js/data-cache.js (via data_api.php?action=meta) knows to refetch instead of
// serving its IndexedDB copy. Call this right after any write to products,
// stock, retail_stock, or loan_clients. company_id uses 0 as the superadmin/
// no-tenant sentinel (cid() is NULL there), matching stock_value_cache's convention.
function touchCacheStore(mysqli $conn, string $store): void {
    $store_esc = mysqli_real_escape_string($conn, $store);
    $cid = cid() ?? 0;
    mysqli_query($conn, "
        INSERT INTO cache_meta (store_name, company_id, updated_at)
        VALUES ('$store_esc', $cid, NOW())
        ON DUPLICATE KEY UPDATE updated_at = NOW()
    ");
}

// Shapes an order + its items into the record js/order-history.js stores in the
// customer's browser IndexedDB, so a customer can revisit order_track.php on the
// same device without re-typing their order number and phone.
function orderHistoryPayload(array $order, array $items): array {
    return [
        'order_number' => $order['order_number'],
        'phone'        => $order['phone'],
        'order_owner'  => $order['order_owner'],
        'status'       => $order['status'],
        'show_prices'  => (bool)$order['show_prices'],
        'total_amount' => (float)$order['total_amount'],
        'items'        => array_map(function ($it) {
            return [
                'name'       => $it['product_name'] ?? $it['custom_name'] ?? 'Item',
                'qty'        => (float)$it['quantity'],
                'unit'       => ($it['stock_source'] ?? '') === 'rt' ? 'pcs' : (($it['stock_source'] ?? '') === 'custom' ? '' : 'pkg'),
                'item_total' => (float)$it['item_total'],
            ];
        }, $items),
    ];
}

// Fans out one notifications row per staff member who can view Orders, so
// notifications_poll.php's long-poll (per user_id) picks it up on their next
// check — used right when a customer order lands in 'pending'. Recipients:
// superadmin/admin (always allowed, per hasPermission()) plus anyone with an
// explicit orders.can_view permission row, scoped to the order's own company
// (superadmins see every company's orders, so they're notified regardless).
function notifyOrderSubmitted(mysqli $conn, int $order_id): void {
    $order = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT company_id, order_number, order_owner FROM orders WHERE id=$order_id"));
    if (!$order) return;

    $company_filter = $order['company_id'] !== null
        ? "(u.company_id = " . (int)$order['company_id'] . " OR u.role = 'superadmin')"
        : "u.role = 'superadmin'";

    $recipients = mysqli_query($conn, "
        SELECT DISTINCT u.id FROM users u
        LEFT JOIN user_permissions up ON up.user_id = u.id AND up.module = 'orders'
        WHERE u.status = 'active' AND $company_filter
        AND (u.role IN ('superadmin','admin') OR up.can_view = 1)
    ");
    if (!$recipients) return;

    $company_sql = $order['company_id'] !== null ? (int)$order['company_id'] : 'NULL';
    $order_num   = $order['order_number'] ?: "#$order_id";
    $msg         = mysqli_real_escape_string($conn, "New order $order_num submitted by " . ($order['order_owner'] ?: 'a customer'));
    $order_num_sql = "'" . mysqli_real_escape_string($conn, $order_num) . "'";

    while ($r = mysqli_fetch_assoc($recipients)) {
        mysqli_query($conn, "
            INSERT INTO notifications (user_id, company_id, order_id, order_number, message)
            VALUES ({$r['id']}, $company_sql, $order_id, $order_num_sql, '$msg')
        ");
    }
}

function logActivity(
    mysqli $conn,
    int $user_id,
    string $action,
    string $description,
    string $table_name = '',
    int $record_id = 0,
    array $old_values = [],
    array $new_values = []
): void {
    $company_id = cid();
    $cid_sql    = $company_id !== null ? (int)$company_id : 'NULL';
    $action_esc = mysqli_real_escape_string($conn, $action);
    $table_esc  = mysqli_real_escape_string($conn, $table_name);
    $old_sql    = !empty($old_values)
        ? "'" . mysqli_real_escape_string($conn, json_encode($old_values, JSON_UNESCAPED_UNICODE)) . "'"
        : 'NULL';
    $new_sql    = !empty($new_values)
        ? "'" . mysqli_real_escape_string($conn, json_encode($new_values, JSON_UNESCAPED_UNICODE)) . "'"
        : 'NULL';
    $ip_esc     = mysqli_real_escape_string($conn, $_SERVER['REMOTE_ADDR'] ?? '');
    $rec_id     = $record_id > 0 ? $record_id : 'NULL';

    mysqli_query($conn, "
        INSERT INTO audit_log (company_id, user_id, action, table_name, record_id, old_values, new_values, ip_address)
        VALUES ($cid_sql, $user_id, '$action_esc', '$table_esc', $rec_id, $old_sql, $new_sql, '$ip_esc')
    ");
}
