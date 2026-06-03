<?php
/**
 * Recalculates and caches stock values per product per company.
 *
 * FIFO cost (warehouse): newest purchases fill warehouse qty first.
 * FIFO cost (retail):    continues from where warehouse left off.
 * Selling values:        qty × current price from stock / retail_stock tables.
 *
 * @param mysqli   $conn
 * @param int|null $company_id  null = superadmin scope (company_id IS NULL rows)
 * @param int|null $product_id  null = recalc all products for this company
 */
function recalcStockValue($conn, $company_id, $product_id = null) {
    $cid_s   = $company_id !== null ? "AND s.company_id  = $company_id"  : "AND s.company_id  IS NULL";
    $cid_rs  = $company_id !== null ? "AND rs.company_id = $company_id"  : "AND rs.company_id IS NULL";
    $cid_p   = $company_id !== null ? "AND company_id    = $company_id"  : "AND company_id    IS NULL";
    $pid_flt = $product_id !== null ? "AND s.product_id  = $product_id"  : "";
    $cid_val = $company_id !== null ? (int)$company_id : 'NULL';

    $rows = mysqli_query($conn, "
        SELECT s.product_id,
               s.quantity                                       AS wh_qty,
               GREATEST(1, s.pieces_per_package)               AS ppp,
               s.package_price,
               COALESCE(rs.pieces_quantity, 0)                 AS rt_pcs,
               COALESCE(rs.retail_price, s.retail_price, 0)    AS rt_price
        FROM stock s
        LEFT JOIN retail_stock rs
               ON rs.product_id = s.product_id $cid_rs
        WHERE 1=1 $cid_s $pid_flt
    ");

    if (!$rows) return;

    while ($row = mysqli_fetch_assoc($rows)) {
        $pid    = (int)$row['product_id'];
        $wh_qty = max(0, (int)$row['wh_qty']);
        $rt_pcs = max(0, (int)$row['rt_pcs']);
        $ppp    = max(1, (int)$row['ppp']);

        // Selling values — straight multiplication, no FIFO needed
        $sell_wh = round($wh_qty * (float)$row['package_price'], 2);
        $sell_rt = round($rt_pcs * (float)$row['rt_price'], 2);

        // ── FIFO cost ─────────────────────────────────────────────────────────
        // Phase 1: fill warehouse (newest packages first)
        // Phase 2: continue filling retail (package-equivalents = rt_pcs / ppp)
        $wh_rem  = (float)$wh_qty;
        $rt_rem  = (float)$rt_pcs / $ppp;   // package-equivalents
        $cost_wh = 0.0;
        $cost_rt = 0.0;

        $pq = mysqli_query($conn, "
            SELECT quantity, cost_price
            FROM purchases
            WHERE product_id = $pid
              AND cost_price IS NOT NULL
              AND cost_price > 0
              $cid_p
            ORDER BY purchase_date DESC, id DESC
        ");

        while (($wh_rem > 0 || $rt_rem > 0) && ($p = mysqli_fetch_assoc($pq))) {
            $avail = (float)$p['quantity'];
            $cost  = (float)$p['cost_price'];

            if ($wh_rem > 0 && $avail > 0) {
                $take     = min($avail, $wh_rem);
                $cost_wh += $take * $cost;
                $wh_rem  -= $take;
                $avail   -= $take;
            }
            if ($rt_rem > 0 && $avail > 0) {
                $take     = min($avail, $rt_rem);
                // cost_rt is in package-cost × pkg-equivalents = total RWF for those pieces
                $cost_rt += $take * $cost;
                $rt_rem  -= $take;
            }
        }

        $cost_wh = round($cost_wh, 2);
        $cost_rt = round($cost_rt, 2);

        // ── Upsert (manual SELECT + INSERT/UPDATE to handle nullable company_id) ─
        $cid_where = $company_id !== null
            ? "company_id = $company_id"
            : "company_id IS NULL";

        $existing = mysqli_fetch_assoc(mysqli_query($conn, "
            SELECT id FROM stock_value_cache
            WHERE $cid_where AND product_id = $pid
            LIMIT 1
        "));

        if ($existing) {
            mysqli_query($conn, "
                UPDATE stock_value_cache
                   SET cost_wh = $cost_wh,
                       cost_rt = $cost_rt,
                       sell_wh = $sell_wh,
                       sell_rt = $sell_rt,
                       updated_at = NOW()
                 WHERE id = {$existing['id']}
            ");
        } else {
            mysqli_query($conn, "
                INSERT INTO stock_value_cache
                    (company_id, product_id, cost_wh, cost_rt, sell_wh, sell_rt)
                VALUES
                    ($cid_val, $pid, $cost_wh, $cost_rt, $sell_wh, $sell_rt)
            ");
        }
    }
}
