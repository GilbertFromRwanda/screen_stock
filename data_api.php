<?php
// Canonical read-only endpoints backing js/data-cache.js. Returns the full
// (company-scoped) product catalog or loan-client list in one shot so pages
// can cache it client-side instead of each running its own variant query.
require_once 'config.php';

if (!isLoggedIn()) { http_response_code(401); echo json_encode(['success' => false, 'message' => 'Not logged in.']); exit; }

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

if ($action === 'products') {
    $rows = [];
    $q = mysqli_query($conn, "
        SELECT p.id, p.name, p.category, p.search_text, p.unit_measure,
               COALESCE(s.package_price, 0)       AS bulk_price,
               COALESCE(s.quantity, 0)             AS wh_qty,
               COALESCE(s.pieces_per_package, 1)   AS pieces_per_package,
               COALESCE(rs.retail_price, s.retail_price, 0) AS retail_price,
               COALESCE(rs.pieces_quantity, 0)     AS retail_qty
        FROM products p
        LEFT JOIN stock s        ON s.product_id = p.id  " . cidAndFor('s') . "
        LEFT JOIN retail_stock rs ON rs.product_id = p.id " . cidAndFor('rs') . "
        WHERE p.deleted = 0
        ORDER BY p.name
    ");
    while ($r = mysqli_fetch_assoc($q)) $rows[] = $r;
    echo json_encode(['success' => true, 'data' => $rows]);
    exit;
}

if ($action === 'categories') {
    echo json_encode(['success' => true, 'data' => get_categories($conn)]);
    exit;
}

if ($action === 'clients') {
    $rows = [];
    $q = mysqli_query($conn, "
        SELECT id, name, phone, total_loans, paid_amount, unpaid_amount, updated_at
        FROM loan_clients
        WHERE 1=1 " . cidAnd() . "
        ORDER BY updated_at DESC
    ");
    while ($r = mysqli_fetch_assoc($q)) $rows[] = $r;
    echo json_encode(['success' => true, 'data' => $rows]);
    exit;
}

// Recent sales, most-recent-first — backs the "Recent Sales" panel on
// sale_bulk/sale_retail/sale_external.php so it can render instantly from the
// IndexedDB cache instead of waiting on a query each time the form loads.
// product_id/owner_phone are included (even though not all are displayed) so
// a row can be clicked to refill the sale form with the same product/owner.
// One row per product (its latest sale) — a client rebuying the same item
// repeatedly shouldn't crowd the other 14 slots out of the panel.
if ($action === 'recent_sales_bulk') {
    $rows = [];
    $q = mysqli_query($conn, "
        SELECT sb.id, sb.product_id, sb.quantity, sb.level_divisor, sb.package_price, sb.total_amount,
               sb.customer_name, sb.created_at, sb.refunded,
               p.name AS product_name, u.full_name AS seller_name
        FROM sales_bulk sb
        JOIN products p ON sb.product_id = p.id
        LEFT JOIN users u ON sb.sold_by = u.id
        JOIN (
            SELECT product_id, MAX(id) AS max_id
            FROM sales_bulk
            WHERE 1=1 " . cidAnd() . "
            GROUP BY product_id
        ) latest ON latest.product_id = sb.product_id AND latest.max_id = sb.id
        ORDER BY sb.created_at DESC
        LIMIT 20
    ");
    while ($r = mysqli_fetch_assoc($q)) $rows[] = $r;
    echo json_encode(['success' => true, 'data' => $rows]);
    exit;
}

if ($action === 'recent_sales_retail') {
    $rows = [];
    $q = mysqli_query($conn, "
        SELECT sr.id, sr.product_id, sr.pieces_sold, sr.retail_price, sr.total_amount,
               sr.customer_name, sr.created_at, sr.refunded,
               p.name AS product_name, u.full_name AS seller_name
        FROM sales_retail sr
        JOIN products p ON sr.product_id = p.id
        LEFT JOIN users u ON sr.sold_by = u.id
        JOIN (
            SELECT product_id, MAX(id) AS max_id
            FROM sales_retail
            WHERE 1=1 " . cidAnd() . "
            GROUP BY product_id
        ) latest ON latest.product_id = sr.product_id AND latest.max_id = sr.id
        ORDER BY sr.created_at DESC
        LIMIT 20
    ");
    while ($r = mysqli_fetch_assoc($q)) $rows[] = $r;
    echo json_encode(['success' => true, 'data' => $rows]);
    exit;
}

if ($action === 'recent_sales_external') {
    $rows = [];
    $q = mysqli_query($conn, "
        SELECT se.id, se.product_name, se.quantity, se.unit_price, se.total_amount,
               se.customer_name, se.created_at, se.refunded,
               u.full_name AS seller_name, po.name AS owner_name, po.phone AS owner_phone
        FROM sales_external se
        LEFT JOIN users u ON se.sold_by = u.id
        LEFT JOIN product_owners po ON se.owner_id = po.id
        JOIN (
            SELECT product_name, MAX(id) AS max_id
            FROM sales_external
            WHERE 1=1 " . cidAnd() . "
            GROUP BY product_name
        ) latest ON latest.product_name = se.product_name AND latest.max_id = se.id
        ORDER BY se.created_at DESC
        LIMIT 20
    ");
    while ($r = mysqli_fetch_assoc($q)) $rows[] = $r;
    echo json_encode(['success' => true, 'data' => $rows]);
    exit;
}

// Cheap change-tracking check backing js/data-cache.js: returns each store's
// last-changed timestamp (ms) so the client can decide whether its IndexedDB
// copy is stale, instead of refetching the full dataset on a fixed timer.
if ($action === 'meta') {
    $company_id = cid() ?? 0;
    $out = [
        'products' => 0, 'clients' => 0, 'categories' => 0,
        'recent_sales_bulk' => 0, 'recent_sales_retail' => 0, 'recent_sales_external' => 0,
    ];
    $q = mysqli_query($conn, "
        SELECT store_name, UNIX_TIMESTAMP(updated_at) AS ts
        FROM cache_meta
        WHERE company_id = $company_id
    ");
    while ($r = mysqli_fetch_assoc($q)) {
        if (array_key_exists($r['store_name'], $out)) $out[$r['store_name']] = (int)$r['ts'] * 1000;
    }
    echo json_encode(['success' => true, 'data' => $out]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Unknown action.']);
