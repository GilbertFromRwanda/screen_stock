<?php
require_once 'config.php';

if (!isLoggedIn()) redirect('login.php');
if (!hasPermission('inventory')) { $_SESSION['flash_error'] = "You don't have permission to access Inventory."; redirect('dashboard.php'); }

// ── Products (global catalog) ───────────────────────────────────────────────
$products = mysqli_query($conn, "
    SELECT id, name, category, unit_measure, unit_price
    FROM products
    WHERE deleted = 0
    ORDER BY COALESCE(category,'zzz') ASC, name ASC
");

// ── Latest purchase's packaging levels per product (company-scoped) ────────
$level_rows = mysqli_query($conn, "
    SELECT p.id AS product_id, pl.level_order, pl.level_name, pl.selling_price
    FROM products p
    JOIN purchases pu ON pu.product_id = p.id
        AND pu.id = (SELECT MAX(id) FROM purchases pu2 WHERE pu2.product_id = p.id " . cidAndFor('pu2') . ")
    JOIN purchase_levels pl ON pl.purchase_id = pu.id
    WHERE p.deleted = 0 " . cidAndFor('pu') . "
    ORDER BY p.id, pl.level_order
");
$levels_by_product = [];
while ($r = mysqli_fetch_assoc($level_rows)) {
    $levels_by_product[$r['product_id']][] = $r;
}

// ── Warehouse/retail stock prices (company-scoped) fallback ────────────────
$stock_rows = mysqli_query($conn, "SELECT product_id, package_price, retail_price FROM stock WHERE 1=1 " . cidAnd());
$stock_by_product = [];
while ($r = mysqli_fetch_assoc($stock_rows)) {
    $stock_by_product[$r['product_id']] = $r;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#103060">
    <link rel="icon" type="image/png" href="icons/favicon-32.png">
    <link rel="apple-touch-icon" href="icons/apple-touch-icon.png">
    <script src="pwa.js" defer></script>
    <title>Price List - GilStock</title>
    <link rel="stylesheet" href="css/style.css?v=<?php echo filemtime(__DIR__ . '/css/style.css'); ?>">
    <style>
        .page-header { display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:28px; }
        .page-header h1 { font-size:24px; font-weight:700; color:var(--dark); margin:0; }
        .page-subtitle { font-size:14px; color:var(--secondary); margin-top:4px; }
        .pr-chain { display:flex; flex-wrap:wrap; gap:4px; }
        .pr-node {
            display:flex; flex-direction:column; align-items:center;
            background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px;
            padding:5px 10px; min-width:72px; text-align:center;
        }
        .pr-name { font-size:11px; font-weight:600; color:#475569; text-transform:uppercase; letter-spacing:.4px; }
        .pr-price { font-size:14px; font-weight:700; color:#1a4280; margin-top:1px; }
        .pr-arrow { color:#94a3b8; font-size:14px; align-self:center; padding:0 2px; }
        .pr-fallback { font-size:13px; color:#334155; }
        .pr-none { color:var(--secondary); }
        @media print {
            .dashboard-container > *:not(.main-content) { display:none !important; }
            .main-content { margin:0 !important; padding:0 !important; }
            .advanced-table-search, .pr-toolbar, .act-menu-wrap { display:none !important; }
        }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php include 'sidebar.php'; ?>

    <div class="main-content">
        <div class="page-header">
            <div>
                <h1>Price List</h1>
                <p class="page-subtitle">Current selling prices for all products</p>
            </div>
        </div>

        <div class="pr-toolbar" style="margin-bottom:16px;">
            <button class="btn btn-secondary" onclick="window.print()">Print Price List</button>
        </div>

        <div class="table-responsive">
            <table class="table" id="tblPricing">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Product</th>
                        <th>Category</th>
                        <th>Unit Measure</th>
                        <th>Price</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $i = 0; while ($row = mysqli_fetch_assoc($products)):
                        $i++;
                        $lvls  = $levels_by_product[$row['id']] ?? [];
                        $stock = $stock_by_product[$row['id']] ?? null;
                    ?>
                    <tr>
                        <td style="color:var(--secondary);"><?= $i ?></td>
                        <td><?= htmlspecialchars($row['name']) ?></td>
                        <td><?= htmlspecialchars($row['category'] ?: 'Uncategorized') ?></td>
                        <td><?= htmlspecialchars($row['unit_measure'] ?: '—') ?></td>
                        <td>
                            <?php if (!empty($lvls)): ?>
                            <div class="pr-chain">
                                <?php foreach ($lvls as $li => $lvl): ?>
                                    <?php if ($li > 0): ?><span class="pr-arrow">&rarr;</span><?php endif; ?>
                                    <span class="pr-node">
                                        <span class="pr-name"><?= htmlspecialchars($lvl['level_name']) ?></span>
                                        <span class="pr-price">RWF <?= number_format($lvl['selling_price'], 0) ?></span>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                            <?php elseif ($stock && ((float)$stock['package_price'] > 0 || (float)$stock['retail_price'] > 0)): ?>
                            <div class="pr-fallback">
                                Bulk: RWF <?= number_format($stock['package_price'], 0) ?>
                                &nbsp;|&nbsp; Retail: RWF <?= number_format($stock['retail_price'], 0) ?>
                            </div>
                            <?php elseif ($row['unit_price'] !== null): ?>
                            <div class="pr-fallback">RWF <?= number_format($row['unit_price'], 0) ?></div>
                            <?php else: ?>
                            <span class="pr-none">&mdash;</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="script.js"></script>
<script>
createAdvancedTableSearch('txtSearchPricing', 'tblPricing', [
    { index: 1, name: 'Product' },
    { index: 2, name: 'Category' }
]);
</script>
</body>
</html>
