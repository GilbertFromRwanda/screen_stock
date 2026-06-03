<?php
require_once 'config.php';
if (!isLoggedIn()) { http_response_code(403); exit; }
header('Content-Type: application/json');

require_once 'stock_value.php';
recalcStockValue($conn, cid());

$ca  = cidAnd();
$row = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COALESCE(SUM(cost_wh), 0) cost_wh,
           COALESCE(SUM(cost_rt), 0) cost_rt,
           COALESCE(SUM(sell_wh), 0) sell_wh,
           COALESCE(SUM(sell_rt), 0) sell_rt,
           MAX(updated_at)           updated_at
    FROM stock_value_cache
    WHERE 1=1 $ca
"));

echo json_encode([
    'ok'         => true,
    'cost_wh'    => (float)$row['cost_wh'],
    'cost_rt'    => (float)$row['cost_rt'],
    'cost_total' => (float)$row['cost_wh'] + (float)$row['cost_rt'],
    'sell_wh'    => (float)$row['sell_wh'],
    'sell_rt'    => (float)$row['sell_rt'],
    'sell_total' => (float)$row['sell_wh'] + (float)$row['sell_rt'],
    'updated_at' => $row['updated_at'],
]);
