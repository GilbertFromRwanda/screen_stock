<?php
require_once 'config.php';

if (!isLoggedIn()) redirect('login.php');
if (!hasPermission('losses')) { $_SESSION['flash_error'] = "You don't have permission to access Losses."; redirect('dashboard.php'); }

// Date filter
$date_from = $_GET['date_from'] ?? '';
$date_to   = $_GET['date_to']   ?? '';
$type_filter = in_array($_GET['type'] ?? '', ['bulk','retail','external']) ? $_GET['type'] : '';

$cid_and = cidAndFor('r');
$where_parts = ["r.back_to_stock = 0 $cid_and"];
if ($date_from) $where_parts[] = "r.refund_date >= '" . mysqli_real_escape_string($conn, $date_from) . "'";
if ($date_to)   $where_parts[] = "r.refund_date <= '" . mysqli_real_escape_string($conn, $date_to)   . "'";
if ($type_filter) $where_parts[] = "r.sale_type = '" . mysqli_real_escape_string($conn, $type_filter) . "'";
$where = "WHERE " . implode(" AND ", $where_parts);

// Summary stats
$stats = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COUNT(*)                       AS total_count,
           COALESCE(SUM(r.loss_amount),0) AS total_loss,
           COALESCE(SUM(CASE WHEN r.sale_type='bulk'     THEN r.loss_amount ELSE 0 END),0) AS bulk_loss,
           COALESCE(SUM(CASE WHEN r.sale_type='retail'   THEN r.loss_amount ELSE 0 END),0) AS retail_loss,
           COALESCE(SUM(CASE WHEN r.sale_type='external' THEN r.loss_amount ELSE 0 END),0) AS external_loss
    FROM refunds r
    WHERE r.back_to_stock = 0 $cid_and
"));

// Filtered losses
$losses_q = mysqli_query($conn, "
    SELECT r.*, u.full_name AS processed_by_name,
        COALESCE(sb.customer_name, sr.customer_name, se.customer_name) AS customer_name
    FROM refunds r
    LEFT JOIN users u          ON u.id  = r.processed_by
    LEFT JOIN sales_bulk sb    ON r.sale_type = 'bulk'     AND sb.id = r.sale_id
    LEFT JOIN sales_retail sr  ON r.sale_type = 'retail'   AND sr.id = r.sale_id
    LEFT JOIN sales_external se ON r.sale_type = 'external' AND se.id = r.sale_id
    $where
    ORDER BY r.refund_date DESC, r.id DESC
");
$losses = [];
while ($row = mysqli_fetch_assoc($losses_q)) $losses[] = $row;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Losses</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/loans.css">
</head>
<body>
<div class="dashboard-container">
    <?php include 'sidebar.php'; ?>
    <div class="main-content">

        <div class="loans-header">
            <h1>Losses</h1>
            <span style="color:var(--secondary);font-size:14px;">Sales refunded and recorded as a loss</span>
        </div>

        <!-- Summary cards -->
        <div class="loans-summary">
            <div class="loan-card">
                <div class="loan-card-label">Total Incidents</div>
                <div class="loan-card-value"><?php echo number_format($stats['total_count']); ?></div>
            </div>
            <div class="loan-card red">
                <div class="loan-card-label">Total Loss</div>
                <div class="loan-card-value danger">RWF <?php echo number_format($stats['total_loss'], 0); ?></div>
            </div>
            <div class="loan-card">
                <div class="loan-card-label">Bulk Losses</div>
                <div class="loan-card-value">RWF <?php echo number_format($stats['bulk_loss'], 0); ?></div>
                <div class="loan-card-sub">Warehouse sales</div>
            </div>
            <div class="loan-card">
                <div class="loan-card-label">Retail Losses</div>
                <div class="loan-card-value">RWF <?php echo number_format($stats['retail_loss'], 0); ?></div>
                <div class="loan-card-sub">Retail sales</div>
            </div>
            <div class="loan-card">
                <div class="loan-card-label">External Losses</div>
                <div class="loan-card-value">RWF <?php echo number_format($stats['external_loss'], 0); ?></div>
                <div class="loan-card-sub">External sales</div>
            </div>
        </div>

        <!-- Filters -->
        <form method="GET" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-bottom:18px;">
            <div>
                <label style="font-size:12px;color:var(--secondary);display:block;margin-bottom:4px;">From</label>
                <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>"
                    style="padding:7px 10px;border:1px solid var(--gray-300);border-radius:var(--radius);font-size:13px;">
            </div>
            <div>
                <label style="font-size:12px;color:var(--secondary);display:block;margin-bottom:4px;">To</label>
                <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>"
                    style="padding:7px 10px;border:1px solid var(--gray-300);border-radius:var(--radius);font-size:13px;">
            </div>
            <div>
                <label style="font-size:12px;color:var(--secondary);display:block;margin-bottom:4px;">Type</label>
                <select name="type" style="padding:7px 10px;border:1px solid var(--gray-300);border-radius:var(--radius);font-size:13px;">
                    <option value="">— All —</option>
                    <option value="bulk"     <?php echo $type_filter==='bulk'     ? 'selected':'' ?>>Bulk</option>
                    <option value="retail"   <?php echo $type_filter==='retail'   ? 'selected':'' ?>>Retail</option>
                    <option value="external" <?php echo $type_filter==='external' ? 'selected':'' ?>>External</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Filter</button>
            <?php if ($date_from || $date_to || $type_filter): ?>
            <a href="losses.php" class="btn btn-sm" style="background:var(--gray-200);color:var(--dark);">Clear</a>
            <?php endif; ?>
        </form>

        <!-- Table -->
        <?php if (empty($losses)): ?>
            <div style="text-align:center;padding:60px;color:var(--secondary);">No loss records found.</div>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table class="table" style="min-width:760px;">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Client</th>
                    <th>Product</th>
                    <th>Qty</th>
                    <th>Loss Amount</th>
                    <th>Reason</th>
                    <th>Processed By</th>
                    <th>Sale</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($losses as $i => $l):
                $type_colors = ['bulk'=>'#1a4280','retail'=>'#10b981','external'=>'#f59e0b'];
                $type_color  = $type_colors[$l['sale_type']] ?? '#64748b';
            ?>
            <tr>
                <td style="color:var(--secondary);"><?php echo $i + 1; ?></td>
                <td style="white-space:nowrap;"><?php echo htmlspecialchars($l['refund_date']); ?></td>
                <td>
                    <span style="display:inline-block;padding:2px 8px;border-radius:5px;font-size:11px;font-weight:600;background:<?php echo $type_color; ?>22;color:<?php echo $type_color; ?>;text-transform:capitalize;">
                        <?php echo htmlspecialchars($l['sale_type']); ?>
                    </span>
                </td>
                <td style="font-weight:500;"><?php echo htmlspecialchars($l['customer_name'] ?: '—'); ?></td>
                <td style="font-weight:500;"><?php echo htmlspecialchars($l['product_name'] ?: '—'); ?></td>
                <td><?php echo $l['quantity']; ?></td>
                <td style="color:var(--danger);font-weight:600;">
                    <?php echo $l['loss_amount'] !== null ? 'RWF ' . number_format($l['loss_amount'], 0) : '—'; ?>
                </td>
                <td style="color:var(--secondary);"><?php echo htmlspecialchars($l['reason'] ?: '—'); ?></td>
                <td style="color:var(--secondary);font-size:13px;"><?php echo htmlspecialchars($l['processed_by_name'] ?? '—'); ?></td>
                <td>
                    <a href="sales.php?tab=<?php echo $l['sale_type']; ?>&highlight=<?php echo $l['sale_id']; ?>"
                       style="font-size:12px;color:var(--primary);text-decoration:none;padding:2px 7px;border:1px solid var(--primary);border-radius:4px;">
                        View ↗
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="font-weight:600;background:var(--gray-50);">
                    <td colspan="6" style="padding:10px 12px;text-align:right;">Total</td>
                    <td style="color:var(--danger);">RWF <?php echo number_format(array_sum(array_column($losses, 'loss_amount')), 0); ?></td>
                    <td colspan="3"></td>
                </tr>
            </tfoot>
        </table>
        </div>
        <?php endif; ?>

    </div>
</div>
</body>
</html>
