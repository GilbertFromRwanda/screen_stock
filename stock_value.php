<?php
/**
 * FIFO stock value cache — cost and selling values per product per company.
 * Including this file auto-creates the cache table if missing.
 */
global $conn;


/**
 * @param mysqli   $conn
 * @param int|null $company_id  null = recalc all companies
 * @param int|null $product_id  null = recalc all products
 */
function recalcStockValue(mysqli $conn, ?int $company_id = null, ?int $product_id = null): void {
    $cid_flt = $company_id !== null ? "AND s.company_id = $company_id" : "";
    $pid_flt = $product_id !== null ? "AND s.product_id = $product_id" : "";

    $rows = mysqli_query($conn, "
        SELECT s.product_id,
               s.company_id,
               GREATEST(0, s.quantity)               AS wh_qty,
               GREATEST(1, s.pieces_per_package)     AS ppp,
               s.package_price,
               COALESCE(rs.pieces_quantity, 0)        AS rt_pcs,
               COALESCE(rs.retail_price, s.retail_price, 0) AS rt_price
        FROM stock s
        LEFT JOIN retail_stock rs ON rs.product_id = s.product_id AND rs.company_id = s.company_id
        WHERE 1=1 $cid_flt $pid_flt
    ");

    if (!$rows) return;

    $stock_rows = [];
    while ($row = mysqli_fetch_assoc($rows)) $stock_rows[] = $row;
    if (!$stock_rows) return;

    // Pull every candidate purchase row once (was previously one query per
    // product here), bucketed by product_id in the same purchase_date DESC/id
    // DESC order the FIFO walk below relies on.
    $pu_pid_flt = $product_id !== null ? "AND product_id = $product_id" : "";
    $pu_cid_flt = $company_id !== null ? "AND company_id = $company_id" : "";
    $purchases_by_product = [];
    $pu_rows = mysqli_query($conn, "
        SELECT product_id, quantity, cost_price FROM purchases
        WHERE cost_price > 0 $pu_pid_flt $pu_cid_flt
        ORDER BY product_id, purchase_date DESC, id DESC
    ");
    while ($p = mysqli_fetch_assoc($pu_rows)) {
        $purchases_by_product[(int)$p['product_id']][] = $p;
    }

    $upsert_values = [];

    foreach ($stock_rows as $row) {
        $pid    = (int)$row['product_id'];
        $cid    = (int)$row['company_id'];
        $wh_qty = max(0, (float)$row['wh_qty']);
        $rt_pcs = max(0, (float)$row['rt_pcs']);
        $ppp    = max(1, (int)$row['ppp']);

        $sell_wh = round($wh_qty * (float)$row['package_price'], 2);
        $sell_rt = round($rt_pcs * (float)$row['rt_price'], 2);

        $wh_rem  = (float)$wh_qty;
        $rt_rem  = (float)$rt_pcs / $ppp;
        $cost_wh = 0.0;
        $cost_rt = 0.0;

        foreach ($purchases_by_product[$pid] ?? [] as $p) {
            if ($wh_rem <= 0 && $rt_rem <= 0) break;
            $avail = (float)$p['quantity'];
            $cp    = (float)$p['cost_price'];

            if ($wh_rem > 0 && $avail > 0) {
                $take     = min($avail, $wh_rem);
                $cost_wh += $take * $cp;
                $wh_rem  -= $take;
                $avail   -= $take;
            }
            if ($rt_rem > 0 && $avail > 0) {
                $take     = min($avail, $rt_rem);
                $cost_rt += $take * $cp;
                $rt_rem  -= $take;
            }
        }

        $cost_wh = round($cost_wh, 2);
        $cost_rt = round($cost_rt, 2);

        $upsert_values[] = "($pid, $cid, $cost_wh, $cost_rt, $sell_wh, $sell_rt)";
    }

    // Single batched upsert instead of one SELECT + INSERT/UPDATE per product
    // (the table's uq_product unique key makes ON DUPLICATE KEY UPDATE safe here).
    foreach (array_chunk($upsert_values, 500) as $chunk) {
        mysqli_query($conn, "
            INSERT INTO stock_value_cache (product_id, company_id, cost_wh, cost_rt, sell_wh, sell_rt)
            VALUES " . implode(',', $chunk) . "
            ON DUPLICATE KEY UPDATE
                cost_wh = VALUES(cost_wh), cost_rt = VALUES(cost_rt),
                sell_wh = VALUES(sell_wh), sell_rt = VALUES(sell_rt),
                updated_at = NOW()
        ");
    }
}
