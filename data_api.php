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
        SELECT id, name, phone, total_loans, paid_amount, unpaid_amount
        FROM loan_clients
        WHERE 1=1 " . cidAnd() . "
        ORDER BY updated_at DESC
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
    $out = ['products' => 0, 'clients' => 0, 'categories' => 0];
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
