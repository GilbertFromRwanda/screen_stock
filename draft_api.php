<?php
// Server-side backup for in-progress sale carts saved via the "Save Draft"
// button on sale_bulk/sale_retail/sale_external.php. js/cart-drafts.js treats
// IndexedDB as the primary store and uses this endpoint just to sync drafts so
// they survive a browser/device switch — see db/updates.sql (`cart_drafts`).
require_once 'config.php';

if (!isLoggedIn()) { http_response_code(401); echo json_encode(['success' => false, 'message' => 'Not logged in.']); exit; }
if (!hasPermission('sales')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'No permission.']); exit; }

header('Content-Type: application/json');

$VALID_TYPES = ['bulk', 'retail', 'external'];

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'list') {
    $type = $_GET['type'] ?? '';
    if (!in_array($type, $VALID_TYPES, true)) { echo json_encode(['success' => false, 'message' => 'Invalid type.']); exit; }

    $type_sql = mysqli_real_escape_string($conn, $type);
    $rows = [];
    $q = mysqli_query($conn, "
        SELECT draft_ref, sale_type, customer_name, items_count, total_amount, draft_json, updated_at
        FROM cart_drafts
        WHERE sale_type = '$type_sql' " . cidAnd() . "
        ORDER BY updated_at DESC
        LIMIT 50
    ");
    while ($r = mysqli_fetch_assoc($q)) $rows[] = $r;
    echo json_encode(['success' => true, 'data' => $rows]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $draft_ref = trim($_POST['draft_ref'] ?? '');
    $type      = $_POST['sale_type'] ?? '';
    $draft_json = $_POST['draft_json'] ?? '';

    if ($draft_ref === '' || !in_array($type, $VALID_TYPES, true) || json_decode($draft_json) === null) {
        echo json_encode(['success' => false, 'message' => 'Invalid draft payload.']);
        exit;
    }

    try {
        $cid_sql = cidSql();
    } catch (AggregateViewWriteBlocked $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }

    $ref_sql       = mysqli_real_escape_string($conn, $draft_ref);
    $type_sql      = mysqli_real_escape_string($conn, $type);
    $customer_sql  = mysqli_real_escape_string($conn, trim($_POST['customer_name'] ?? ''));
    $items_count   = (int)($_POST['items_count'] ?? 0);
    $total_amount  = (float)($_POST['total_amount'] ?? 0);
    $json_sql      = mysqli_real_escape_string($conn, $draft_json);
    $created_by    = (int)$_SESSION['user_id'];

    $ok = (bool)mysqli_query($conn, "
        INSERT INTO cart_drafts (company_id, draft_ref, sale_type, customer_name, items_count, total_amount, draft_json, created_by)
        VALUES ($cid_sql, '$ref_sql', '$type_sql', '$customer_sql', $items_count, $total_amount, '$json_sql', $created_by)
        ON DUPLICATE KEY UPDATE
            customer_name = VALUES(customer_name),
            items_count   = VALUES(items_count),
            total_amount  = VALUES(total_amount),
            draft_json    = VALUES(draft_json),
            updated_at    = CURRENT_TIMESTAMP
    ");

    echo json_encode($ok
        ? ['success' => true, 'draft_ref' => $draft_ref]
        : ['success' => false, 'message' => mysqli_error($conn)]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $draft_ref = trim($_POST['draft_ref'] ?? '');
    if ($draft_ref === '') { echo json_encode(['success' => false, 'message' => 'Missing draft_ref.']); exit; }

    $ref_sql = mysqli_real_escape_string($conn, $draft_ref);
    $ok = (bool)mysqli_query($conn, "DELETE FROM cart_drafts WHERE draft_ref = '$ref_sql' " . cidAnd());
    echo json_encode(['success' => $ok]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Unknown action.']);
