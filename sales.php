<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}
// Handle External Sale (product not from stock — tracking only)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['external_sale'])) {
    $product_name  = mysqli_real_escape_string($conn, trim($_POST['ext_product_name'] ?? ''));
    $owner_name    = mysqli_real_escape_string($conn, trim($_POST['ext_owner_name'] ?? ''));
    $owner_phone   = mysqli_real_escape_string($conn, trim($_POST['ext_owner_phone'] ?? ''));
    $quantity      = max(1, (int)($_POST['ext_quantity'] ?? 1));
    $unit_price    = max(0, (float)($_POST['ext_unit_price'] ?? 0));
    $customer_name = mysqli_real_escape_string($conn, trim($_POST['ext_customer_name'] ?? ''));
    $cash_amount   = max(0, (float)($_POST['ext_cash_amount'] ?? 0));
    $momo_amount   = max(0, (float)($_POST['ext_momo_amount'] ?? 0));
    $loan_amount   = max(0, (float)($_POST['ext_loan_amount'] ?? 0));
    $phone         = mysqli_real_escape_string($conn, trim($_POST['ext_phone'] ?? ''));
    $total_amount  = $quantity * $unit_price;

    if (empty($product_name)) {
        $_SESSION['flash_error'] = "Product name is required for external sale.";
        header("Location: sales.php"); exit;
    }
    if ($unit_price < 1) {
        $_SESSION['flash_error'] = "Unit price must be greater than 0.";
        header("Location: sales.php"); exit;
    }
    if (abs($cash_amount + $momo_amount + $loan_amount - $total_amount) > 1) {
        $_SESSION['flash_error'] = "Payment split (RWF " . number_format($cash_amount + $momo_amount + $loan_amount, 0) . ") must equal total (RWF " . number_format($total_amount, 0) . ").";
        header("Location: sales.php"); exit;
    }
    if ($loan_amount > 0 && empty($phone)) {
        $_SESSION['flash_error'] = "Client phone is required when loan amount is set.";
        header("Location: sales.php"); exit;
    }

    // Resolve owner_id — insert new owner if name provided and not yet registered
    $owner_id_val = 'NULL';
    if ($owner_name !== '') {
        $existing = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT id FROM product_owners WHERE name='$owner_name' AND (phone='$owner_phone' OR (phone IS NULL AND '$owner_phone'=''))"
        ));
        if ($existing) {
            $owner_id_val = (int)$existing['id'];
        } else {
            $op = $owner_phone !== '' ? "'$owner_phone'" : 'NULL';
            mysqli_query($conn, "INSERT INTO product_owners (name, phone) VALUES ('$owner_name', $op)");
            $owner_id_val = (int)mysqli_insert_id($conn);
        }
    }

    $sold_by = (int)$_SESSION['user_id'];
    $ins = mysqli_query($conn, "INSERT INTO sales_external (product_name, owner_id, quantity, unit_price, total_amount, cash_amount, momo_amount, loan_amount, customer_name, phone, sale_date, sold_by)
                   VALUES ('$product_name', $owner_id_val, $quantity, $unit_price, $total_amount, $cash_amount, $momo_amount, $loan_amount, '$customer_name', '$phone', CURDATE(), $sold_by)");
    if ($ins) {
        if ($loan_amount > 0) {
            $loan_date = date('Y-m-d');
            mysqli_query($conn, "INSERT INTO loans (product_id, qty, amount, client, phone, loan_date, given_by) VALUES (0, $quantity, $loan_amount, '$customer_name', '$phone', '$loan_date', $sold_by)");
        }
        $parts = [];
        if ($cash_amount > 0) $parts[] = "Cash: RWF " . number_format($cash_amount, 0);
        if ($momo_amount > 0) $parts[] = "Momo: RWF " . number_format($momo_amount, 0);
        if ($loan_amount > 0) $parts[] = "Loan: RWF " . number_format($loan_amount, 0);
        $_SESSION['flash_success'] = "External sale recorded — " . implode(", ", $parts);
        $_SESSION['flash_sale_type'] = 'external';
    }
    header("Location: sales.php"); exit;
}

// Handle Bulk Sale (with split payment: Cash + Momo + Loan)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['bulk_sale'])) {
    $product_id    = (int)$_POST['product_id'];
    $quantity      = (int)$_POST['quantity'];
    $selling_price = (float)$_POST['selling_price'];
    $customer_name = mysqli_real_escape_string($conn, trim($_POST['customer_name']));
    $cash_amount   = max(0, (float)($_POST['cash_amount'] ?? 0));
    $momo_amount   = max(0, (float)($_POST['momo_amount'] ?? 0));
    $loan_amount   = max(0, (float)($_POST['loan_amount'] ?? 0));
    $phone         = mysqli_real_escape_string($conn, trim($_POST['phone'] ?? ''));
    $total_amount  = $quantity * $selling_price;

    if (abs($cash_amount + $momo_amount + $loan_amount - $total_amount) > 1) {
        $_SESSION['flash_error'] = "Payment split (RWF " . number_format($cash_amount + $momo_amount + $loan_amount, 0) . ") must equal total (RWF " . number_format($total_amount, 0) . ").";
        header("Location: sales.php"); exit;
    }
    if ($loan_amount > 0 && empty($phone)) {
        $_SESSION['flash_error'] = "Client phone is required when loan amount is set.";
        header("Location: sales.php"); exit;
    }
    $stock = mysqli_fetch_assoc(mysqli_query($conn, "SELECT quantity FROM stock WHERE product_id = $product_id"));
    if (!$stock || $stock['quantity'] < $quantity) {
        $_SESSION['flash_error'] = "Insufficient stock available";
        header("Location: sales.php"); exit;
    }
    $sold_by = (int)$_SESSION['user_id'];
    $ins = mysqli_query($conn, "INSERT INTO sales_bulk (product_id, quantity, package_price, total_amount, sale_date, customer_name, cash_amount, momo_amount, loan_amount, sold_by)
                   VALUES ($product_id, $quantity, $selling_price, $total_amount, CURDATE(), '$customer_name', $cash_amount, $momo_amount, $loan_amount, $sold_by)");
    if ($ins) {
        mysqli_query($conn, "UPDATE stock SET quantity = quantity - $quantity WHERE product_id = $product_id");
        if ($loan_amount > 0) {
            $loan_date = date('Y-m-d');
            mysqli_query($conn, "INSERT INTO loans (product_id, qty, amount, client, phone, loan_date, given_by) VALUES ('$product_id','$quantity','$loan_amount','$customer_name','$phone','$loan_date',$sold_by)");
        }
        $parts = [];
        if ($cash_amount > 0) $parts[] = "Cash: RWF " . number_format($cash_amount, 0);
        if ($momo_amount > 0) $parts[] = "Momo: RWF " . number_format($momo_amount, 0);
        if ($loan_amount > 0) $parts[] = "Loan: RWF " . number_format($loan_amount, 0);
        $_SESSION['flash_success'] = "Bulk sale recorded — " . implode(", ", $parts);
        $_SESSION['flash_sale_type'] = 'bulk';
    }
    header("Location: sales.php"); exit;
}

// Handle Retail Sale (with split payment: Cash + Momo + Loan)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['retail_sale'])) {
    $product_id    = (int)$_POST['product_id'];
    $pieces_sold   = (int)$_POST['pieces_sold'];
    $selling_price = (float)$_POST['selling_price'];
    $customer_name = mysqli_real_escape_string($conn, trim($_POST['customer_name']));
    $cash_amount   = max(0, (float)($_POST['cash_amount'] ?? 0));
    $momo_amount   = max(0, (float)($_POST['momo_amount'] ?? 0));
    $loan_amount   = max(0, (float)($_POST['loan_amount'] ?? 0));
    $phone         = mysqli_real_escape_string($conn, trim($_POST['phone'] ?? ''));
    $total_amount  = $pieces_sold * $selling_price;

    if (abs($cash_amount + $momo_amount + $loan_amount - $total_amount) > 1) {
        $_SESSION['flash_error'] = "Payment split (RWF " . number_format($cash_amount + $momo_amount + $loan_amount, 0) . ") must equal total (RWF " . number_format($total_amount, 0) . ").";
        header("Location: sales.php"); exit;
    }
    if ($loan_amount > 0 && empty($phone)) {
        $_SESSION['flash_error'] = "Client phone is required when loan amount is set.";
        header("Location: sales.php"); exit;
    }
    $retail = mysqli_fetch_assoc(mysqli_query($conn, "SELECT pieces_quantity FROM retail_stock WHERE product_id = $product_id"));
    if (!$retail || $retail['pieces_quantity'] < $pieces_sold) {
        $_SESSION['flash_error'] = "Insufficient retail stock available";
        header("Location: sales.php"); exit;
    }
    $sold_by = (int)$_SESSION['user_id'];
    $ins = mysqli_query($conn, "INSERT INTO sales_retail (product_id, pieces_sold, retail_price, total_amount, sale_date, customer_name, cash_amount, momo_amount, loan_amount, sold_by)
                   VALUES ($product_id, $pieces_sold, $selling_price, $total_amount, CURDATE(), '$customer_name', $cash_amount, $momo_amount, $loan_amount, $sold_by)");
    if ($ins) {
        mysqli_query($conn, "UPDATE retail_stock SET pieces_quantity = pieces_quantity - $pieces_sold WHERE product_id = $product_id");
        if ($loan_amount > 0) {
            $loan_date = date('Y-m-d');
            mysqli_query($conn, "INSERT INTO loans (product_id, qty, amount, client, phone, loan_date, given_by) VALUES ('$product_id','$pieces_sold','$loan_amount','$customer_name','$phone','$loan_date',$sold_by)");
        }
        $parts = [];
        if ($cash_amount > 0) $parts[] = "Cash: RWF " . number_format($cash_amount, 0);
        if ($momo_amount > 0) $parts[] = "Momo: RWF " . number_format($momo_amount, 0);
        if ($loan_amount > 0) $parts[] = "Loan: RWF " . number_format($loan_amount, 0);
        $_SESSION['flash_success'] = "Retail sale recorded — " . implode(", ", $parts);
        $_SESSION['flash_sale_type'] = 'retail';
    }
    header("Location: sales.php"); exit;
}

// Read flash messages from session
if (isset($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (isset($_SESSION['flash_error'])) {
    $error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}
$last_sale_type = $_SESSION['flash_sale_type'] ?? null;
unset($_SESSION['flash_sale_type']);

// Get products for bulk sale
$bulk_products = mysqli_query($conn, "
    SELECT s.*, p.name, p.unit_measure ,p.category
    FROM stock s
    JOIN products p ON s.product_id = p.id
    WHERE s.quantity > 0
");

// Get products for retail sale
$retail_products = mysqli_query($conn, "
    SELECT r.*, p.name, p.unit_measure,p.category
    FROM retail_stock r
    JOIN products p ON r.product_id = p.id
    WHERE r.pieces_quantity > 0
");

// Date filter (default: today)
$date_from = $_GET['date_from'] ?? date('Y-m-d');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$date_from_safe = mysqli_real_escape_string($conn, $date_from);
$date_to_safe = mysqli_real_escape_string($conn, $date_to);
$owner_filter = max(0, (int)($_GET['owner_id'] ?? 0));

// Get sales filtered by date
$recent_bulk_sales = mysqli_query($conn, "
    SELECT sb.*, p.name, u.full_name AS seller_name
    FROM sales_bulk sb
    JOIN products p ON sb.product_id = p.id
    LEFT JOIN users u ON sb.sold_by = u.id
    WHERE sb.sale_date BETWEEN '$date_from_safe' AND '$date_to_safe'
    ORDER BY sb.created_at DESC
");

$recent_retail_sales = mysqli_query($conn, "
    SELECT sr.*, p.name, u.full_name AS seller_name
    FROM sales_retail sr
    JOIN products p ON sr.product_id = p.id
    LEFT JOIN users u ON sr.sold_by = u.id
    WHERE sr.sale_date BETWEEN '$date_from_safe' AND '$date_to_safe'
    ORDER BY sr.created_at DESC
");

$ext_owner_where = $owner_filter > 0 ? "AND se.owner_id = $owner_filter" : "";
$recent_external_sales = mysqli_query($conn, "
    SELECT se.*, u.full_name AS seller_name,
           po.name AS owner_name, po.phone AS owner_phone
    FROM sales_external se
    LEFT JOIN users u ON se.sold_by = u.id
    LEFT JOIN product_owners po ON se.owner_id = po.id
    WHERE se.sale_date BETWEEN '$date_from_safe' AND '$date_to_safe'
    $ext_owner_where
    ORDER BY se.created_at DESC
");

// All products for external sale picker (with price hints)
$all_products_query = mysqli_query($conn, "
    SELECT p.id, p.name, p.category, p.unit_measure,
           COALESCE(s.package_price, 0) as bulk_price,
           COALESCE(r.retail_price, 0)  as retail_price
    FROM products p
    LEFT JOIN stock s        ON s.product_id = p.id
    LEFT JOIN retail_stock r ON r.product_id = p.id
    ORDER BY p.category, p.name
");
$all_products_arr = [];
while ($ap = mysqli_fetch_assoc($all_products_query)) $all_products_arr[] = $ap;

// Existing loan clients for picker
$loan_clients_query = mysqli_query($conn, "
    SELECT client, phone, MAX(loan_date) AS last_visit, COUNT(*) AS visits
    FROM loans GROUP BY client, phone ORDER BY last_visit DESC
");
$loan_clients_arr = [];
while ($c = mysqli_fetch_assoc($loan_clients_query)) $loan_clients_arr[] = $c;

// Product owners for picker
$ext_owners_query = mysqli_query($conn, "
    SELECT po.id, po.name AS owner_name, po.phone AS owner_phone,
           COUNT(se.id) AS total_sales
    FROM product_owners po
    LEFT JOIN sales_external se ON se.owner_id = po.id
    GROUP BY po.id
    ORDER BY total_sales DESC, po.name ASC
");
$ext_owners_arr = [];
while ($o = mysqli_fetch_assoc($ext_owners_query)) $ext_owners_arr[] = $o;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales - Small Stock Management</title>
        <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/sales.css">
    <style>
        .searchable-select {
            position: relative;
        }
        .searchable-select-input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--gray-300);
            border-radius: var(--radius);
            font-size: 14px;
            background: var(--white);
            cursor: text;
        }
        .searchable-select-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
        }
        .searchable-select-dropdown {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            max-height: 200px;
            overflow-y: auto;
            background: var(--white);
            border: 1px solid var(--gray-300);
            border-top: none;
            border-radius: 0 0 var(--radius) var(--radius);
            z-index: 1000;
            box-shadow: var(--shadow-md);
        }
        .searchable-select-dropdown.open {
            display: block;
        }
        .searchable-select-option {
            padding: 9px 12px;
            cursor: pointer;
            font-size: 14px;
        }
        .searchable-select-option:hover,
        .searchable-select-option.highlighted {
            background: var(--gray-100);
            color: var(--primary);
        }
        .searchable-select-option.hidden {
            display: none;
        }
        .split-payment-box {
            border: 1px solid var(--gray-300);
            border-radius: var(--radius);
            overflow: hidden;
        }
        .split-row {
            display: flex;
            align-items: center;
            padding: 8px 12px;
            gap: 10px;
            border-bottom: 1px solid var(--gray-100);
        }
        .split-row:last-child { border-bottom: none; }
        .split-label {
            width: 70px;
            font-size: 13px;
            font-weight: 500;
            flex-shrink: 0;
        }
        .split-row input[type="text"] {
            flex: 1;
            padding: 6px 10px;
            border: 1px solid var(--gray-300);
            border-radius: var(--radius);
            font-size: 14px;
        }
        .split-remaining-row {
            justify-content: space-between;
            background: var(--gray-50);
            font-weight: 600;
        }
        .split-remaining-row.valid  { background: #ecfdf5; color: #059669; }
        .split-remaining-row.invalid { background: #fef2f2; color: #dc2626; }
        .sales-tab-nav {
            display: flex;
            gap: 4px;
            border-bottom: 2px solid var(--gray-200);
            margin-bottom: 16px;
        }
        .sales-tab-btn {
            padding: 8px 20px;
            border: none;
            background: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            color: var(--secondary);
            border-bottom: 2px solid transparent;
            margin-bottom: -2px;
            border-radius: var(--radius) var(--radius) 0 0;
            transition: color .15s, border-color .15s;
        }
        .sales-tab-btn:hover { color: var(--primary); }
        .sales-tab-btn.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
            background: var(--white);
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php include 'sidebar.php'; ?>
        
        <div class="main-content">
            <h1>Sales Management</h1>
            
            <?php if (isset($success)): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <div class="sale-action-buttons">
                <button class="btn btn-primary btn-lg" onclick="openModal('bulkSaleModal')">+ Kuranguza</button>
                <button class="btn btn-success btn-lg" onclick="openModal('retailSaleModal')">+ Gucuruza Detaye</button>
                <button class="btn btn-lg" style="background:var(--warning,#f59e0b);color:#fff;" onclick="openModal('externalSaleModal')">+ External Sale</button>
            </div>
              <?php
                $active_tab = in_array($last_sale_type, ['bulk','retail','external'])
                    ? $last_sale_type
                    : (in_array($_GET['tab'] ?? '', ['bulk','retail','external']) ? $_GET['tab'] : 'bulk');
                ?>
            <div class="recent-sales">
                <h2>Recent Sales</h2>
                <form method="GET" class="date-filter-form">
                    <div class="date-filter-group">
                        <label for="date_from">From</label>
                        <input type="date" id="date_from" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                    </div>
                    <div class="date-filter-group">
                        <label for="date_to">To</label>
                        <input type="date" id="date_to" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                    </div>
                    <div class="date-filter-group" id="owner_filter_group" style="<?php echo $active_tab !== 'external' ? 'display:none' : ''; ?>">
                        <label for="owner_id">Owner</label>
                        <select name="owner_id" id="owner_id" style="padding:6px 10px;border:1px solid var(--gray-300);border-radius:var(--radius);font-size:13px;">
                            <option value="0">— All owners —</option>
                            <?php foreach ($ext_owners_arr as $o): ?>
                                <option value="<?php echo $o['id']; ?>" <?php echo $owner_filter === (int)$o['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($o['owner_name']); ?>
                                    <?php if ($o['owner_phone']): ?>(<?php echo htmlspecialchars($o['owner_phone']); ?>)<?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <input type="hidden" id="filter_tab" name="tab" value="<?php echo htmlspecialchars($active_tab); ?>">
                    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                    <a href="sales.php?tab=<?php echo htmlspecialchars($active_tab); ?>" class="btn btn-sm" style="background:var(--gray-200);color:var(--dark);">Today</a>
                </form>
              
                <div class="sales-tab-nav">
                    <button class="sales-tab-btn<?php echo $active_tab==='bulk'     ? ' active' : ''; ?>" onclick="switchSalesTab('bulk')"    >Ibyaranguwe</button>
                    <button class="sales-tab-btn<?php echo $active_tab==='retail'   ? ' active' : ''; ?>" onclick="switchSalesTab('retail')"  >Ibyacurujwe detaye</button>
                    <button class="sales-tab-btn<?php echo $active_tab==='external' ? ' active' : ''; ?>" onclick="switchSalesTab('external')" >External</button>
                </div>

                <!-- Bulk tab -->
                <div class="sales-tab-panel" id="stab-bulk" <?php echo $active_tab!=='bulk'     ? 'style="display:none"' : ''; ?>>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Date</th><th>Product</th><th>Qty</th><th>Unit Price</th><th>Default Price</th><th>Difference</th><th>Total</th><th>Customer</th><th>Sold By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $bulk_grand_total = 0; while($row = mysqli_fetch_assoc($recent_bulk_sales)):
                            $bulk_grand_total += $row['total_amount'];
                            $default_price_query = mysqli_query($conn, "SELECT package_price FROM stock WHERE product_id = {$row['product_id']}");
                            $default_price = mysqli_fetch_assoc($default_price_query)['package_price'];
                            $price_diff = $row['package_price'] - $default_price;
                            $diff_class = $price_diff > 0 ? 'text-danger' : ($price_diff < 0 ? 'text-success' : '');
                        ?>
                        <tr>
                            <td><?php echo date('Y-m-d', strtotime($row['sale_date'])); ?></td>
                            <td><?php echo htmlspecialchars($row['name']); ?></td>
                            <td><?php echo $row['quantity']; ?></td>
                            <td><strong>RWF <?php echo number_format($row['package_price'], 0); ?></strong></td>
                            <td>RWF <?php echo number_format($default_price, 0); ?></td>
                            <td class="<?php echo $diff_class; ?>"><?php echo $price_diff != 0 ? ($price_diff > 0 ? '+' : '') . number_format($price_diff, 0) : '-'; ?></td>
                            <td>RWF <?php echo number_format($row['total_amount'], 0); ?></td>
                            <td><?php echo htmlspecialchars($row['customer_name'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($row['seller_name'] ?? '—'); ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-total-row">
                            <td colspan="6" style="text-align:right;"><strong>Total:</strong></td>
                            <td><strong>RWF <?php echo number_format($bulk_grand_total, 0); ?></strong></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
                </div>

                <!-- Retail tab -->
                <div class="sales-tab-panel" id="stab-retail" <?php echo $active_tab!=='retail'   ? 'style="display:none"' : ''; ?>>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Date</th><th>Product</th><th>Pieces</th><th>Price/Piece</th><th>Default Price</th><th>Difference</th><th>Total</th><th>Customer</th><th>Sold By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $retail_grand_total = 0; while($row = mysqli_fetch_assoc($recent_retail_sales)):
                            $retail_grand_total += $row['total_amount'];
                            $default_price_query = mysqli_query($conn, "SELECT retail_price FROM retail_stock WHERE product_id = {$row['product_id']}");
                            $default_price = mysqli_fetch_assoc($default_price_query)['retail_price'];
                            $price_diff = $row['retail_price'] - $default_price;
                            $diff_class = $price_diff > 0 ? 'text-danger' : ($price_diff < 0 ? 'text-success' : '');
                        ?>
                        <tr>
                            <td><?php echo date('Y-m-d', strtotime($row['sale_date'])); ?></td>
                            <td><?php echo htmlspecialchars($row['name']); ?></td>
                            <td><?php echo $row['pieces_sold']; ?></td>
                            <td><strong>RWF <?php echo number_format($row['retail_price'], 0); ?></strong></td>
                            <td>RWF <?php echo number_format($default_price, 0); ?></td>
                            <td class="<?php echo $diff_class; ?>"><?php echo $price_diff != 0 ? ($price_diff > 0 ? '+' : '') . number_format($price_diff, 0) : '-'; ?></td>
                            <td>RWF <?php echo number_format($row['total_amount'], 0); ?></td>
                            <td><?php echo htmlspecialchars($row['customer_name'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($row['seller_name'] ?? '—'); ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-total-row">
                            <td colspan="6" style="text-align:right;"><strong>Total:</strong></td>
                            <td><strong>RWF <?php echo number_format($retail_grand_total, 0); ?></strong></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
                </div>

                <!-- External tab -->
                <div class="sales-tab-panel" id="stab-external" <?php echo $active_tab!=='external' ? 'style="display:none"' : ''; ?>>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Date</th><th>Product</th><th>Owner</th><th>Qty</th><th>Unit Price</th><th>Total</th><th>Cash</th><th>Momo</th><th>Loan</th><th>Customer</th><th>Sold By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $ext_grand_total = 0; while($row = mysqli_fetch_assoc($recent_external_sales)):
                            $ext_grand_total += $row['total_amount'];
                        ?>
                        <tr>
                            <td><?php echo date('Y-m-d', strtotime($row['sale_date'])); ?></td>
                            <td><?php echo htmlspecialchars($row['product_name']); ?></td>
                            <td><?php
                                $ow = htmlspecialchars($row['owner_name'] ?? '');
                                $op = htmlspecialchars($row['owner_phone'] ?? '');
                                echo $ow ? ($op ? "$ow<br><small style='color:var(--secondary)'>$op</small>" : $ow) : '—';
                            ?></td>
                            <td><?php echo $row['quantity']; ?></td>
                            <td>RWF <?php echo number_format($row['unit_price'], 0); ?></td>
                            <td><strong>RWF <?php echo number_format($row['total_amount'], 0); ?></strong></td>
                            <td><?php echo $row['cash_amount'] > 0 ? 'RWF '.number_format($row['cash_amount'],0) : '—'; ?></td>
                            <td><?php echo $row['momo_amount'] > 0 ? 'RWF '.number_format($row['momo_amount'],0) : '—'; ?></td>
                            <td><?php echo $row['loan_amount'] > 0 ? 'RWF '.number_format($row['loan_amount'],0) : '—'; ?></td>
                            <td><?php echo htmlspecialchars($row['customer_name'] ?: 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($row['seller_name'] ?? '—'); ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-total-row">
                            <td colspan="5" style="text-align:right;"><strong>Total:</strong></td>
                            <td><strong>RWF <?php echo number_format($ext_grand_total, 0); ?></strong></td>
                            <td colspan="5"></td>
                        </tr>
                    </tfoot>
                </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Bulk Sale Modal -->
    <div id="bulkSaleModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('bulkSaleModal')">&times;</span>
            <h2>New Bulk Sale (Full Package)</h2>
            <form method="POST" action="" id="bulkSaleForm">
                <div class="form-group">
                    <label for="bulk_product_id">Select Product*</label>
                    <select id="bulk_product_id" name="product_id" required onchange="updateBulkProductDetails()" style="display:none">
                        <option value="">Choose product...</option>
                        <?php
                        mysqli_data_seek($bulk_products, 0);
                        while($row = mysqli_fetch_assoc($bulk_products)):
                        ?>
                            <option value="<?php echo $row['product_id']; ?>"
                                    data-price="<?php echo $row['package_price']; ?>"
                                    data-stock="<?php echo $row['quantity']; ?>"
                                    data-product-name="<?php echo htmlspecialchars($row['category'].'-'.$row['name']); ?>"
                                    data-unit="<?php echo htmlspecialchars($row['unit_measure']); ?>">
                                <?php echo htmlspecialchars($row['category']).'- '.  htmlspecialchars($row['name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                    <div class="searchable-select" id="bulkProductSearchable">
                        <input type="text" class="searchable-select-input" id="bulk_product_search" placeholder="Search product..." autocomplete="off">
                        <div class="searchable-select-dropdown" id="bulk_product_dropdown">
                            <?php
                            mysqli_data_seek($bulk_products, 0);
                            while($row = mysqli_fetch_assoc($bulk_products)):
                            ?>
                                <div class="searchable-select-option"
                                     data-value="<?php echo $row['product_id']; ?>"
                                     data-price="<?php echo $row['package_price']; ?>"
                                     data-stock="<?php echo $row['quantity']; ?>"
                                     data-product-name="<?php echo htmlspecialchars($row['category'].'-'.$row['name']); ?>"
                                     data-unit="<?php echo htmlspecialchars($row['unit_measure']); ?>">
                                    <?php echo htmlspecialchars($row['category'].'-'.$row['name']); ?>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    </div>
                </div>

                <div id="bulk_product_details" class="price-history" style="display: none;">
                    <strong>Product Info:</strong>
                    <span id="bulk_product_info"></span>
                </div>

                <div class="form-group">
                    <label for="bulk_quantity">Quantity (Packages)*</label>
                    <input type="text" id="bulk_quantity" name="quantity" required min="1" oninput="calculateBulkTotal()">
                    <small id="bulk_stock_info" class="field-hint"></small>
                    <small id="bulk_qty_error" class="field-error"></small>
                </div>

                <div class="form-group">
                    <label for="bulk_selling_price">Selling Price (per package)*</label>
                    <div class="price-input-group">
                        <input type="text" id="bulk_selling_price" name="selling_price"
                               step="1" required min="1"
                               oninput="calculateBulkTotal()">
                        <span class="default-price-badge" onclick="setBulkDefaultPrice()">Use Default</span>
                    </div>
                    <div id="bulk_price_warning" class="price-warning"></div>
                </div>

                <div class="form-group">
                    <label for="bulk_customer">Customer Name</label>
                    <input type="text" id="bulk_customer" name="customer_name" value="client"
                           placeholder="Enter customer name">
                </div>

                <div id="bulk_payment_section" style="display:none;">
                    <div class="form-group">
                        <label>Payment Breakdown</label>
                        <div class="split-payment-box">
                            <div class="split-row">
                                <span class="split-label">Cash</span>
                                <input type="text" id="bulk_cash" name="cash_amount" min="0" step="1" value="0" oninput="calcBulkSplit('cash')">
                            </div>
                            <div class="split-row">
                                <span class="split-label">Momo</span>
                                <input type="text" id="bulk_momo" name="momo_amount" min="0" step="1" value="0" oninput="calcBulkSplit('momo')">
                            </div>
                            <div class="split-row">
                                <span class="split-label">Loan</span>
                                <input type="text" id="bulk_loan_split" name="loan_amount" min="0" step="1" value="0" oninput="calcBulkSplit('loan')">
                            </div>
                            <div class="split-row split-remaining-row" id="bulk_remaining_row">
                                <span class="split-label">Remaining</span>
                                <span id="bulk_remaining">—</span>
                            </div>
                        </div>
                    </div>
                    <div id="bulk_loan_fields" style="display:none;">
                        <?php if ($loan_clients_arr): ?>
                        <div class="form-group">
                            <label>Existing Client</label>
                            <div class="searchable-select" id="bulkClientPickerWrap">
                                <input type="text" class="searchable-select-input" id="bulk_client_picker_search"
                                    placeholder="Search registered client..." autocomplete="off">
                                <div class="searchable-select-dropdown" id="bulk_client_picker_dropdown">
                                    <?php foreach ($loan_clients_arr as $c): ?>
                                        <div class="searchable-select-option"
                                            data-client="<?php echo htmlspecialchars($c['client'], ENT_QUOTES); ?>"
                                            data-phone="<?php echo htmlspecialchars($c['phone'], ENT_QUOTES); ?>">
                                            <?php echo htmlspecialchars($c['client']); ?>
                                            <?php if ($c['phone']): ?> — <?php echo htmlspecialchars($c['phone']); ?><?php endif; ?>
                                            <small style="color:var(--secondary);"> (<?php echo $c['visits']; ?> visit<?php echo $c['visits']>1?'s':''; ?>)</small>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <small style="color:var(--secondary);margin-top:3px;display:block;">Pick to auto-fill, or type a new name below.</small>
                        </div>
                        <?php endif; ?>
                        <div class="form-group">
                            <label for="bulk_phone">Client Phone*</label>
                            <input type="text" id="bulk_phone" name="phone" placeholder="e.g. 07XXXXXXXX" oninput="calcBulkSplit()">
                        </div>
                    </div>
                </div>

                <div class="sale-summary" id="bulk_summary" style="display:none;">
                    <div class="summary-row"><span>Product</span><strong id="bulk_sum_product"></strong></div>
                    <div class="summary-row"><span>Packages</span><strong id="bulk_sum_qty"></strong></div>
                    <div class="summary-row"><span>Price/Package</span><strong id="bulk_sum_price"></strong></div>
                    <div class="summary-row summary-total"><span>Total Amount</span><strong id="bulk_sum_total"></strong></div>
                </div>

                <button type="button" name="bulk_sale" id="bulk_submit_btn" class="btn btn-primary" disabled onclick="handleBulkSubmit()">Save Bulk Sale</button>
            </form>
        </div>
    </div>

    <!-- Retail Sale Modal -->
    <div id="retailSaleModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('retailSaleModal')">&times;</span>
            <h2>New Retail Sale (Piece by Piece)</h2>
            <form method="POST" action="" id="retailSaleForm">
                <div class="form-group">
                    <label for="retail_product_id">Select Product*</label>
                    <select id="retail_product_id" name="product_id" required onchange="updateRetailProductDetails()" style="display:none">
                        <option value="">Choose product...</option>
                        <?php
                        mysqli_data_seek($retail_products, 0);
                        while($row = mysqli_fetch_assoc($retail_products)):
                        ?>
                            <option value="<?php echo $row['product_id']; ?>"
                                    data-price="<?php echo $row['retail_price']; ?>"
                                    data-stock="<?php echo $row['pieces_quantity']; ?>"
                                    data-product-name="<?php echo htmlspecialchars($row['category'].'-'.$row['name']); ?>"
                                    data-unit="<?php echo htmlspecialchars($row['unit_measure']); ?>">
                                <?php echo htmlspecialchars($row['category'].'-'.$row['name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                    <div class="searchable-select" id="retailProductSearchable">
                        <input type="text" class="searchable-select-input" id="retail_product_search" placeholder="Search product..." autocomplete="off">
                        <div class="searchable-select-dropdown" id="retail_product_dropdown">
                            <?php
                            mysqli_data_seek($retail_products, 0);
                            while($row = mysqli_fetch_assoc($retail_products)):
                            ?>
                                <div class="searchable-select-option"
                                     data-value="<?php echo $row['product_id']; ?>"
                                     data-price="<?php echo $row['retail_price']; ?>"
                                     data-stock="<?php echo $row['pieces_quantity']; ?>"
                                     data-product-name="<?php echo htmlspecialchars($row['category'].'-'.$row['name']); ?>"
                                     data-unit="<?php echo htmlspecialchars($row['unit_measure']); ?>">
                                    <?php echo htmlspecialchars($row['category'].'-'.$row['name']); ?>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    </div>
                </div>

                <div id="retail_product_details" class="price-history" style="display: none;">
                    <strong>Product Info:</strong>
                    <span id="retail_product_info"></span>
                </div>

                <div class="form-group">
                    <label for="pieces_sold">Number of Pieces*</label>
                    <input type="text" id="pieces_sold" name="pieces_sold" required min="1" oninput="calculateRetailTotal()">
                    <small id="retail_stock_info" class="field-hint"></small>
                    <small id="retail_qty_error" class="field-error"></small>
                </div>

                <div class="form-group">
                    <label for="retail_selling_price">Selling Price (per piece)*</label>
                    <div class="price-input-group">
                        <input type="text" id="retail_selling_price" name="selling_price"
                               step="1" required min="1"
                               oninput="calculateRetailTotal()">
                        <span class="default-price-badge" onclick="setRetailDefaultPrice()">Use Default</span>
                    </div>
                    <div id="retail_price_warning" class="price-warning"></div>
                </div>

                <div class="form-group">
                    <label for="retail_customer">Customer Name</label>
                    <input type="text" id="retail_customer" name="customer_name" value="client"
                           placeholder="Enter customer name">
                </div>

                <div id="retail_payment_section" style="display:none;">
                    <div class="form-group">
                        <label>Payment Breakdown</label>
                        <div class="split-payment-box">
                            <div class="split-row">
                                <span class="split-label">Cash</span>
                                <input type="text" id="retail_cash" name="cash_amount" min="0" step="1" value="0" oninput="calcRetailSplit('cash')">
                            </div>
                            <div class="split-row">
                                <span class="split-label">Momo</span>
                                <input type="text" id="retail_momo" name="momo_amount" min="0" step="1" value="0" oninput="calcRetailSplit('momo')">
                            </div>
                            <div class="split-row">
                                <span class="split-label">Loan</span>
                                <input type="text" id="retail_loan_split" name="loan_amount" min="0" step="1" value="0" oninput="calcRetailSplit('loan')">
                            </div>
                            <div class="split-row split-remaining-row" id="retail_remaining_row">
                                <span class="split-label">Remaining</span>
                                <span id="retail_remaining">—</span>
                            </div>
                        </div>
                    </div>
                    <div id="retail_loan_fields" style="display:none;">
                        <?php if ($loan_clients_arr): ?>
                        <div class="form-group">
                            <label>Existing Client</label>
                            <div class="searchable-select" id="retailClientPickerWrap">
                                <input type="text" class="searchable-select-input" id="retail_client_picker_search"
                                    placeholder="Search registered client..." autocomplete="off">
                                <div class="searchable-select-dropdown" id="retail_client_picker_dropdown">
                                    <?php foreach ($loan_clients_arr as $c): ?>
                                        <div class="searchable-select-option"
                                            data-client="<?php echo htmlspecialchars($c['client'], ENT_QUOTES); ?>"
                                            data-phone="<?php echo htmlspecialchars($c['phone'], ENT_QUOTES); ?>">
                                            <?php echo htmlspecialchars($c['client']); ?>
                                            <?php if ($c['phone']): ?> — <?php echo htmlspecialchars($c['phone']); ?><?php endif; ?>
                                            <small style="color:var(--secondary);"> (<?php echo $c['visits']; ?> visit<?php echo $c['visits']>1?'s':''; ?>)</small>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <small style="color:var(--secondary);margin-top:3px;display:block;">Pick to auto-fill, or type a new name below.</small>
                        </div>
                        <?php endif; ?>
                        <div class="form-group">
                            <label for="retail_phone">Client Phone*</label>
                            <input type="text" id="retail_phone" name="phone" placeholder="e.g. 07XXXXXXXX" oninput="calcRetailSplit()">
                        </div>
                    </div>
                </div>

                <div class="sale-summary" id="retail_summary" style="display:none;">
                    <div class="summary-row"><span>Product</span><strong id="retail_sum_product"></strong></div>
                    <div class="summary-row"><span>Pieces</span><strong id="retail_sum_qty"></strong></div>
                    <div class="summary-row"><span>Price/Piece</span><strong id="retail_sum_price"></strong></div>
                    <div class="summary-row summary-total"><span>Total Amount</span><strong id="retail_sum_total"></strong></div>
                </div>

                <button type="button" name="retail_sale" id="retail_submit_btn" class="btn btn-primary" disabled onclick="handleRetailSubmit()">Save Retail Sale</button>
            </form>
        </div>
    </div>

    <!-- External Sale Modal -->
    <div id="externalSaleModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('externalSaleModal')">&times;</span>
            <h2>External Sale (Track Only)</h2>
            <p style="color:var(--secondary);font-size:13px;margin-top:-8px;margin-bottom:16px;">Product is not from your stock — transaction is recorded for collection tracking only.</p>
            <form method="POST" action="" id="externalSaleForm">
                <!-- hidden field that actually gets submitted -->
                <input type="hidden" id="ext_product_name" name="ext_product_name">

                <div class="form-group" id="ext_picker_mode">
                    <label>Product*</label>
                    <div class="searchable-select" id="extProductSearchable">
                        <input type="text" class="searchable-select-input" id="ext_product_search"
                               placeholder="Search product..." autocomplete="off">
                        <div class="searchable-select-dropdown" id="ext_product_dropdown">
                            <?php foreach ($all_products_arr as $ap): ?>
                                <div class="searchable-select-option"
                                     data-name="<?php echo htmlspecialchars($ap['category'].'-'.$ap['name'], ENT_QUOTES); ?>"
                                     data-bulk="<?php echo $ap['bulk_price']; ?>"
                                     data-retail="<?php echo $ap['retail_price']; ?>">
                                    <?php echo htmlspecialchars($ap['category'].'-'.$ap['name']); ?>
                                    <?php if ($ap['bulk_price'] > 0 || $ap['retail_price'] > 0): ?>
                                        <small style="color:var(--secondary);">
                                            <?php if ($ap['bulk_price'] > 0): ?> bulk:<?php echo number_format($ap['bulk_price'],0); ?><?php endif; ?>
                                            <?php if ($ap['retail_price'] > 0): ?> retail:<?php echo number_format($ap['retail_price'],0); ?><?php endif; ?>
                                        </small>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <button type="button" class="btn btn-sm" style="margin-top:6px;background:var(--gray-200);color:var(--dark);"
                            onclick="extSwitchToManual()">+ Not in list? Type manually</button>
                </div>

                <div class="form-group" id="ext_manual_mode" style="display:none;">
                    <label for="ext_manual_name">Product Name*</label>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <input type="text" id="ext_manual_name" placeholder="Type product name..." style="flex:1;" oninput="extSetManualName(this.value)">
                        <button type="button" class="btn btn-sm" style="background:var(--gray-200);color:var(--dark);white-space:nowrap;"
                                onclick="extSwitchToPicker()">Back to list</button>
                    </div>
                </div>

                <!-- Product Owner -->
                <input type="hidden" id="ext_owner_name" name="ext_owner_name">
                <input type="hidden" id="ext_owner_phone" name="ext_owner_phone">
                <div class="form-group">
                    <label>Product Owner</label>
                    <?php if ($ext_owners_arr): ?>
                    <div class="searchable-select" id="extOwnerPickerWrap">
                        <input type="text" class="searchable-select-input" id="ext_owner_search"
                               placeholder="Search registered owner..." autocomplete="off">
                        <div class="searchable-select-dropdown" id="ext_owner_dropdown">
                            <?php foreach ($ext_owners_arr as $o): ?>
                                <div class="searchable-select-option"
                                     data-id="<?php echo $o['id']; ?>"
                                     data-owner="<?php echo htmlspecialchars($o['owner_name'], ENT_QUOTES); ?>"
                                     data-phone="<?php echo htmlspecialchars($o['owner_phone'] ?? '', ENT_QUOTES); ?>">
                                    <?php echo htmlspecialchars($o['owner_name']); ?>
                                    <?php if ($o['owner_phone']): ?> — <?php echo htmlspecialchars($o['owner_phone']); ?><?php endif; ?>
                                    <small style="color:var(--secondary);"> (<?php echo $o['total_sales']; ?> sale<?php echo $o['total_sales']>1?'s':''; ?>)</small>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <small style="color:var(--secondary);margin-top:3px;display:block;">Pick existing or fill in a new owner below.</small>
                    <?php endif; ?>
                    <div style="display:flex;gap:8px;margin-top:6px;">
                        <input type="text" id="ext_owner_name_input" placeholder="Owner name"
                               style="flex:2;" oninput="extSyncOwner()">
                        <input type="text" id="ext_owner_phone_input" placeholder="Phone (optional)"
                               style="flex:1;" oninput="extSyncOwner()">
                    </div>
                </div>

                <div class="form-group">
                    <label for="ext_quantity">Quantity*</label>
                    <input type="text" id="ext_quantity" name="ext_quantity" required min="1" value="1" oninput="calcExtTotal()">
                </div>
                <div class="form-group">
                    <label for="ext_unit_price">Unit Price (RWF)*</label>
                    <input type="text" id="ext_unit_price" name="ext_unit_price" required min="1" step="1" placeholder="0" oninput="calcExtTotal()">
                </div>
                <div class="form-group">
                    <label for="ext_customer_name">Customer Name</label>
                    <input type="text" id="ext_customer_name" name="ext_customer_name" value="client" placeholder="Enter customer name">
                </div>

                <div id="ext_payment_section">
                    <div class="form-group">
                        <label>Payment Breakdown</label>
                        <div class="split-payment-box">
                            <div class="split-row">
                                <span class="split-label">Cash</span>
                                <input type="text" id="ext_cash" name="ext_cash_amount" min="0" step="1" value="0" oninput="calcExtSplit('cash')">
                            </div>
                            <div class="split-row">
                                <span class="split-label">Momo</span>
                                <input type="text" id="ext_momo" name="ext_momo_amount" min="0" step="1" value="0" oninput="calcExtSplit('momo')">
                            </div>
                            <div class="split-row">
                                <span class="split-label">Loan</span>
                                <input type="text" id="ext_loan" name="ext_loan_amount" min="0" step="1" value="0" oninput="calcExtSplit('loan')">
                            </div>
                            <div class="split-row split-remaining-row" id="ext_remaining_row">
                                <span class="split-label">Remaining</span>
                                <span id="ext_remaining">—</span>
                            </div>
                        </div>
                    </div>
                    <div id="ext_loan_fields" style="display:none;">
                        <?php if ($loan_clients_arr): ?>
                        <div class="form-group">
                            <label>Existing Client</label>
                            <div class="searchable-select" id="extClientPickerWrap">
                                <input type="text" class="searchable-select-input" id="ext_client_picker_search"
                                    placeholder="Search registered client..." autocomplete="off">
                                <div class="searchable-select-dropdown" id="ext_client_picker_dropdown">
                                    <?php foreach ($loan_clients_arr as $c): ?>
                                        <div class="searchable-select-option"
                                            data-client="<?php echo htmlspecialchars($c['client'], ENT_QUOTES); ?>"
                                            data-phone="<?php echo htmlspecialchars($c['phone'], ENT_QUOTES); ?>">
                                            <?php echo htmlspecialchars($c['client']); ?>
                                            <?php if ($c['phone']): ?> — <?php echo htmlspecialchars($c['phone']); ?><?php endif; ?>
                                            <small style="color:var(--secondary);"> (<?php echo $c['visits']; ?> visit<?php echo $c['visits']>1?'s':''; ?>)</small>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <small style="color:var(--secondary);margin-top:3px;display:block;">Pick to auto-fill, or type a new name below.</small>
                        </div>
                        <?php endif; ?>
                        <div class="form-group">
                            <label for="ext_phone">Client Phone*</label>
                            <input type="text" id="ext_phone" name="ext_phone" placeholder="e.g. 07XXXXXXXX" oninput="calcExtSplit()">
                        </div>
                    </div>
                </div>

                <div class="sale-summary" id="ext_summary" style="display:none;">
                    <div class="summary-row"><span>Product</span><strong id="ext_sum_product"></strong></div>
                    <div class="summary-row"><span>Quantity</span><strong id="ext_sum_qty"></strong></div>
                    <div class="summary-row"><span>Unit Price</span><strong id="ext_sum_price"></strong></div>
                    <div class="summary-row summary-total"><span>Total Amount</span><strong id="ext_sum_total"></strong></div>
                </div>

                <button type="button" id="ext_submit_btn" class="btn btn-primary" disabled onclick="handleExtSubmit()" style="background:var(--warning,#f59e0b);border-color:var(--warning,#f59e0b);">Save External Sale</button>
            </form>
        </div>
    </div>

    <script src="script.js"></script>
    <script>
        // --- Searchable Select Init ---
        function initSearchableSelect(wrapperId, searchInputId, dropdownId, hiddenSelectId) {
            var wrapper = document.getElementById(wrapperId);
            var searchInput = document.getElementById(searchInputId);
            var dropdown = document.getElementById(dropdownId);
            var hiddenSelect = document.getElementById(hiddenSelectId);
            var options = dropdown.querySelectorAll('.searchable-select-option');
            var highlightedIndex = -1;

            searchInput.addEventListener('focus', function() {
                dropdown.classList.add('open');
                filterOptions();
            });

            searchInput.addEventListener('input', function() {
                dropdown.classList.add('open');
                highlightedIndex = -1;
                filterOptions();
            });

            searchInput.addEventListener('keydown', function(e) {
                var visible = dropdown.querySelectorAll('.searchable-select-option:not(.hidden)');
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    highlightedIndex = Math.min(highlightedIndex + 1, visible.length - 1);
                    updateHighlight(visible);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    highlightedIndex = Math.max(highlightedIndex - 1, 0);
                    updateHighlight(visible);
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    if (highlightedIndex >= 0 && visible[highlightedIndex]) {
                        selectOption(visible[highlightedIndex]);
                    }
                } else if (e.key === 'Escape') {
                    dropdown.classList.remove('open');
                    searchInput.blur();
                }
            });

            document.addEventListener('click', function(e) {
                if (!e.target.closest('#' + wrapperId)) {
                    dropdown.classList.remove('open');
                }
            });

            options.forEach(function(opt) {
                opt.addEventListener('click', function() {
                    selectOption(opt);
                });
            });

            function filterOptions() {
                var term = searchInput.value.toLowerCase();
                options.forEach(function(opt) {
                    if (opt.textContent.trim().toLowerCase().indexOf(term) > -1) {
                        opt.classList.remove('hidden');
                    } else {
                        opt.classList.add('hidden');
                    }
                });
            }

            function updateHighlight(visible) {
                options.forEach(function(o) { o.classList.remove('highlighted'); });
                if (visible[highlightedIndex]) {
                    visible[highlightedIndex].classList.add('highlighted');
                    visible[highlightedIndex].scrollIntoView({ block: 'nearest' });
                }
            }

            function selectOption(opt) {
                var value = opt.getAttribute('data-value');
                searchInput.value = opt.textContent.trim();
                dropdown.classList.remove('open');
                highlightedIndex = -1;

                // Sync hidden select and trigger its onchange
                hiddenSelect.value = value;
                hiddenSelect.dispatchEvent(new Event('change'));
            }
        }

        initSearchableSelect('bulkProductSearchable', 'bulk_product_search', 'bulk_product_dropdown', 'bulk_product_id');
        initSearchableSelect('retailProductSearchable', 'retail_product_search', 'retail_product_dropdown', 'retail_product_id');

        // --- Bulk Sale ---
        var bulkCoreValid = false;

        function updateBulkProductDetails() {
            const select = document.getElementById('bulk_product_id');
            const opt = select.options[select.selectedIndex];
            if (!opt.value) {
                document.getElementById('bulk_product_details').style.display = 'none';
                document.getElementById('bulk_stock_info').innerHTML = '';
                document.getElementById('bulk_selling_price').value = '';
                document.getElementById('bulk_quantity').value = '';
                document.getElementById('bulk_summary').style.display = 'none';
                document.getElementById('bulk_payment_section').style.display = 'none';
                document.getElementById('bulk_submit_btn').disabled = true;
                return;
            }
            const price = opt.dataset.price;
            const stock = opt.dataset.stock;
            const name = opt.dataset.productName;

            document.getElementById('bulk_selling_price').value = price;
            document.getElementById('bulk_quantity').value = '';
            document.getElementById('bulk_quantity').max = stock;
            document.getElementById('bulk_stock_info').innerHTML = 'Available: ' + stock + ' packages';
            document.getElementById('bulk_product_details').style.display = 'block';
            document.getElementById('bulk_product_info').innerHTML = name + ' &mdash; Default price: RWF ' + parseFloat(price).toLocaleString();
            // Reset split fields on product change
            document.getElementById('bulk_cash').value = 0;
            document.getElementById('bulk_momo').value = 0;
            document.getElementById('bulk_loan_split').value = 0;
            document.getElementById('bulk_payment_section').style.display = 'none';
            calculateBulkTotal();
        }

        function setBulkDefaultPrice() {
            const opt = document.getElementById('bulk_product_id').options[document.getElementById('bulk_product_id').selectedIndex];
            if (opt.value) {
                document.getElementById('bulk_selling_price').value = opt.dataset.price;
                calculateBulkTotal();
            }
        }

        function calculateBulkTotal() {
            const select = document.getElementById('bulk_product_id');
            const opt = select.options[select.selectedIndex];
            if (!opt.value) return;

            const stock = parseInt(opt.dataset.stock) || 0;
            const defaultPrice = parseFloat(opt.dataset.price) || 0;
            const qty = parseInt(document.getElementById('bulk_quantity').value) || 0;
            const price = parseFloat(document.getElementById('bulk_selling_price').value) || 0;
            const total = qty * price;
            const qtyError = document.getElementById('bulk_qty_error');
            const priceWarning = document.getElementById('bulk_price_warning');
            const summary = document.getElementById('bulk_summary');
            let valid = true;

            if (qty > stock) {
                qtyError.innerHTML = 'Exceeds available stock (' + stock + ')!';
                qtyError.style.display = 'block';
                valid = false;
            } else {
                qtyError.style.display = 'none';
            }

            if (price > 0 && defaultPrice > 0 && price !== defaultPrice) {
                const diff = ((price - defaultPrice) / defaultPrice * 100).toFixed(1);
                priceWarning.innerHTML = 'Price is ' + (price > defaultPrice ? '+' : '') + diff + '% from default (RWF ' + defaultPrice.toLocaleString() + ')';
                priceWarning.style.display = 'block';
            } else {
                priceWarning.style.display = 'none';
            }

            if (qty < 1 || price < 1) valid = false;

            bulkCoreValid = valid;

            if (valid) {
                document.getElementById('bulk_sum_product').textContent = opt.dataset.productName;
                document.getElementById('bulk_sum_qty').textContent = qty;
                document.getElementById('bulk_sum_price').textContent = 'RWF ' + price.toLocaleString();
                document.getElementById('bulk_sum_total').textContent = 'RWF ' + total.toLocaleString();
                summary.style.display = 'block';
                // Show payment section; default Cash = total if all splits are 0
                var paySection = document.getElementById('bulk_payment_section');
                var cash = parseFloat(document.getElementById('bulk_cash').value) || 0;
                var momo = parseFloat(document.getElementById('bulk_momo').value) || 0;
                var loan = parseFloat(document.getElementById('bulk_loan_split').value) || 0;
                if (cash === 0 && momo === 0 && loan === 0) {
                    document.getElementById('bulk_momo').value = total;
                }
                paySection.style.display = 'block';
                calcBulkSplit();
            } else {
                summary.style.display = 'none';
                document.getElementById('bulk_payment_section').style.display = 'none';
                document.getElementById('bulk_submit_btn').disabled = true;
            }
        }

        function calcBulkSplit(changed) {
            var qty   = parseInt(document.getElementById('bulk_quantity').value) || 0;
            var price = parseFloat(document.getElementById('bulk_selling_price').value) || 0;
            var total = qty * price;
            var cashEl = document.getElementById('bulk_cash');
            var momoEl = document.getElementById('bulk_momo');
            var loanEl = document.getElementById('bulk_loan_split');
            var cash = parseFloat(cashEl.value) || 0;
            var momo = parseFloat(momoEl.value) || 0;
            var loan = parseFloat(loanEl.value) || 0;

            // Cascade: auto-fill the next field with the remaining
            if (changed === 'cash') {
                momo = Math.max(0, total - cash - loan);
                momoEl.value = momo;
            } else if (changed === 'momo') {
                loan = Math.max(0, total - cash - momo);
                loanEl.value = loan;
            } else if (changed === 'loan') {
                momo = Math.max(0, total - cash - loan);
                momoEl.value = momo;
            }

            var remaining = Math.round(total - cash - momo - loan);
            var splitOk = remaining === 0;

            var remEl = document.getElementById('bulk_remaining');
            var remRow = document.getElementById('bulk_remaining_row');
            remEl.textContent = 'RWF ' + remaining.toLocaleString();
            remRow.className = 'split-row split-remaining-row ' + (splitOk ? 'valid' : 'invalid');

            document.getElementById('bulk_loan_fields').style.display = loan > 0 ? 'block' : 'none';

            var clientOk = loan <= 0 || (
                document.getElementById('bulk_customer').value.trim().length > 0 &&
                document.getElementById('bulk_phone').value.trim().length > 0
            );
            document.getElementById('bulk_submit_btn').disabled = !(bulkCoreValid && splitOk && clientOk);
        }

        function confirmBulkSale() {
            var opt = document.getElementById('bulk_product_id').options[document.getElementById('bulk_product_id').selectedIndex];
            var qty = document.getElementById('bulk_quantity').value;
            var price = parseFloat(document.getElementById('bulk_selling_price').value);
            var total = qty * price;
            var cash = parseFloat(document.getElementById('bulk_cash').value) || 0;
            var momo = parseFloat(document.getElementById('bulk_momo').value) || 0;
            var loan = parseFloat(document.getElementById('bulk_loan_split').value) || 0;
            var parts = [];
            if (cash > 0) parts.push('Cash: RWF ' + cash.toLocaleString());
            if (momo > 0) parts.push('Momo: RWF ' + momo.toLocaleString());
            if (loan > 0) parts.push('Loan: RWF ' + loan.toLocaleString() + ' (deferred)');
            return confirm(
                'Confirm Bulk Sale?\n\n' +
                'Product: ' + opt.dataset.productName + '\n' +
                'Packages: ' + qty + '\n' +
                'Price/Package: RWF ' + price.toLocaleString() + '\n' +
                'Total: RWF ' + total.toLocaleString() + '\n' +
                'Payment: ' + parts.join(' | ') + '\n\n' +
                'This will deduct ' + qty + ' package(s) from warehouse stock.'
            );
        }

        // --- Retail Sale ---
        var retailCoreValid = false;

        function updateRetailProductDetails() {
            const select = document.getElementById('retail_product_id');
            const opt = select.options[select.selectedIndex];
            if (!opt.value) {
                document.getElementById('retail_product_details').style.display = 'none';
                document.getElementById('retail_stock_info').innerHTML = '';
                document.getElementById('retail_selling_price').value = '';
                document.getElementById('pieces_sold').value = '';
                document.getElementById('retail_summary').style.display = 'none';
                document.getElementById('retail_payment_section').style.display = 'none';
                document.getElementById('retail_submit_btn').disabled = true;
                return;
            }
            const price = opt.dataset.price;
            const stock = opt.dataset.stock;
            const name = opt.dataset.productName;

            document.getElementById('retail_selling_price').value = price;
            document.getElementById('pieces_sold').value = '';
            document.getElementById('pieces_sold').max = stock;
            document.getElementById('retail_stock_info').innerHTML = 'Available: ' + stock + ' pieces';
            document.getElementById('retail_product_details').style.display = 'block';
            document.getElementById('retail_product_info').innerHTML = name + ' &mdash; Default price: RWF ' + parseFloat(price).toLocaleString() + '/piece';
            // Reset split fields on product change
            document.getElementById('retail_cash').value = 0;
            document.getElementById('retail_momo').value = 0;
            document.getElementById('retail_loan_split').value = 0;
            document.getElementById('retail_payment_section').style.display = 'none';
            calculateRetailTotal();
        }

        function setRetailDefaultPrice() {
            const opt = document.getElementById('retail_product_id').options[document.getElementById('retail_product_id').selectedIndex];
            if (opt.value) {
                document.getElementById('retail_selling_price').value = opt.dataset.price;
                calculateRetailTotal();
            }
        }

        function calculateRetailTotal() {
            const select = document.getElementById('retail_product_id');
            const opt = select.options[select.selectedIndex];
            if (!opt.value) return;

            const stock = parseInt(opt.dataset.stock) || 0;
            const defaultPrice = parseFloat(opt.dataset.price) || 0;
            const qty = parseInt(document.getElementById('pieces_sold').value) || 0;
            const price = parseFloat(document.getElementById('retail_selling_price').value) || 0;
            const total = qty * price;
            const qtyError = document.getElementById('retail_qty_error');
            const priceWarning = document.getElementById('retail_price_warning');
            const summary = document.getElementById('retail_summary');
            let valid = true;

            if (qty > stock) {
                qtyError.innerHTML = 'Exceeds available stock (' + stock + ' pieces)!';
                qtyError.style.display = 'block';
                valid = false;
            } else {
                qtyError.style.display = 'none';
            }

            if (price > 0 && defaultPrice > 0 && price !== defaultPrice) {
                const diff = ((price - defaultPrice) / defaultPrice * 100).toFixed(1);
                priceWarning.innerHTML = 'Price is ' + (price > defaultPrice ? '+' : '') + diff + '% from default (RWF ' + defaultPrice.toLocaleString() + ')';
                priceWarning.style.display = 'block';
            } else {
                priceWarning.style.display = 'none';
            }

            if (qty < 1 || price < 1) valid = false;

            retailCoreValid = valid;

            if (valid) {
                document.getElementById('retail_sum_product').textContent = opt.dataset.productName;
                document.getElementById('retail_sum_qty').textContent = qty;
                document.getElementById('retail_sum_price').textContent = 'RWF ' + price.toLocaleString();
                document.getElementById('retail_sum_total').textContent = 'RWF ' + total.toLocaleString();
                summary.style.display = 'block';
                var cash = parseFloat(document.getElementById('retail_cash').value) || 0;
                var momo = parseFloat(document.getElementById('retail_momo').value) || 0;
                var loan = parseFloat(document.getElementById('retail_loan_split').value) || 0;
                if (cash === 0 && momo === 0 && loan === 0) {
                    document.getElementById('retail_momo').value = total;
                }
                document.getElementById('retail_payment_section').style.display = 'block';
                calcRetailSplit();
            } else {
                summary.style.display = 'none';
                document.getElementById('retail_payment_section').style.display = 'none';
                document.getElementById('retail_submit_btn').disabled = true;
            }
        }

        function calcRetailSplit(changed) {
            var qty   = parseInt(document.getElementById('pieces_sold').value) || 0;
            var price = parseFloat(document.getElementById('retail_selling_price').value) || 0;
            var total = qty * price;
            var cashEl = document.getElementById('retail_cash');
            var momoEl = document.getElementById('retail_momo');
            var loanEl = document.getElementById('retail_loan_split');
            var cash = parseFloat(cashEl.value) || 0;
            var momo = parseFloat(momoEl.value) || 0;
            var loan = parseFloat(loanEl.value) || 0;

            // Cascade: auto-fill the next field with the remaining
            if (changed === 'cash') {
                momo = Math.max(0, total - cash - loan);
                momoEl.value = momo;
            } else if (changed === 'momo') {
                loan = Math.max(0, total - cash - momo);
                loanEl.value = loan;
            } else if (changed === 'loan') {
                momo = Math.max(0, total - cash - loan);
                momoEl.value = momo;
            }

            var remaining = Math.round(total - cash - momo - loan);
            var splitOk = remaining === 0;

            var remEl = document.getElementById('retail_remaining');
            var remRow = document.getElementById('retail_remaining_row');
            remEl.textContent = 'RWF ' + remaining.toLocaleString();
            remRow.className = 'split-row split-remaining-row ' + (splitOk ? 'valid' : 'invalid');

            document.getElementById('retail_loan_fields').style.display = loan > 0 ? 'block' : 'none';

            var clientOk = loan <= 0 || (
                document.getElementById('retail_customer').value.trim().length > 0 &&
                document.getElementById('retail_phone').value.trim().length > 0
            );
            document.getElementById('retail_submit_btn').disabled = !(retailCoreValid && splitOk && clientOk);
        }

        function confirmRetailSale() {
            var opt = document.getElementById('retail_product_id').options[document.getElementById('retail_product_id').selectedIndex];
            var qty = document.getElementById('pieces_sold').value;
            var price = parseFloat(document.getElementById('retail_selling_price').value);
            var total = qty * price;
            var cash = parseFloat(document.getElementById('retail_cash').value) || 0;
            var momo = parseFloat(document.getElementById('retail_momo').value) || 0;
            var loan = parseFloat(document.getElementById('retail_loan_split').value) || 0;
            var parts = [];
            if (cash > 0) parts.push('Cash: RWF ' + cash.toLocaleString());
            if (momo > 0) parts.push('Momo: RWF ' + momo.toLocaleString());
            if (loan > 0) parts.push('Loan: RWF ' + loan.toLocaleString() + ' (deferred)');
            return confirm(
                'Confirm Retail Sale?\n\n' +
                'Product: ' + opt.dataset.productName + '\n' +
                'Pieces: ' + qty + '\n' +
                'Price/Piece: RWF ' + price.toLocaleString() + '\n' +
                'Total: RWF ' + total.toLocaleString() + '\n' +
                'Payment: ' + parts.join(' | ') + '\n\n' +
                'This will deduct ' + qty + ' piece(s) from retail stock.'
            );
        }

        function initLoanClientPicker(wrapId, searchId, dropdownId, clientInputId, phoneInputId) {
            var wrap = document.getElementById(wrapId);
            if (!wrap) return;
            var search   = document.getElementById(searchId);
            var dropdown = document.getElementById(dropdownId);
            var options  = dropdown.querySelectorAll('.searchable-select-option');
            var hi = -1;

            search.addEventListener('focus', function() { dropdown.classList.add('open'); filter(); });
            search.addEventListener('input', function() { dropdown.classList.add('open'); hi = -1; filter(); });
            search.addEventListener('keydown', function(e) {
                var vis = dropdown.querySelectorAll('.searchable-select-option:not(.hidden)');
                if (e.key === 'ArrowDown') { e.preventDefault(); hi = Math.min(hi+1, vis.length-1); hl(vis); }
                else if (e.key === 'ArrowUp') { e.preventDefault(); hi = Math.max(hi-1,0); hl(vis); }
                else if (e.key === 'Enter') { e.preventDefault(); if (hi>=0&&vis[hi]) pick(vis[hi]); }
                else if (e.key === 'Escape') dropdown.classList.remove('open');
            });
            document.addEventListener('click', function(e) {
                if (!e.target.closest('#' + wrapId)) dropdown.classList.remove('open');
            });
            options.forEach(function(o) { o.addEventListener('click', function() { pick(o); }); });

            function filter() {
                var term = search.value.toLowerCase();
                options.forEach(function(o) { o.classList.toggle('hidden', o.textContent.trim().toLowerCase().indexOf(term)===-1); });
            }
            function hl(vis) {
                options.forEach(function(o) { o.classList.remove('highlighted'); });
                if (vis[hi]) { vis[hi].classList.add('highlighted'); vis[hi].scrollIntoView({block:'nearest'}); }
            }
            function pick(opt) {
                document.getElementById(clientInputId).value = opt.getAttribute('data-client');
                var phoneEl = document.getElementById(phoneInputId);
                phoneEl.value = opt.getAttribute('data-phone');
                phoneEl.dispatchEvent(new Event('input'));
                search.value = opt.getAttribute('data-client');
                dropdown.classList.remove('open'); hi = -1;
            }
        }

        initLoanClientPicker('bulkClientPickerWrap',   'bulk_client_picker_search',   'bulk_client_picker_dropdown',   'bulk_customer',   'bulk_phone');
        initLoanClientPicker('retailClientPickerWrap', 'retail_client_picker_search', 'retail_client_picker_dropdown', 'retail_customer', 'retail_phone');

        function submitFormWithAction(formId, actionName) {
            var form = document.getElementById(formId);
            var inp = document.createElement('input');
            inp.type = 'hidden'; inp.name = actionName; inp.value = '1';
            form.appendChild(inp);
            form.submit();
        }
        function handleBulkSubmit() {
            if (confirmBulkSale()) submitFormWithAction('bulkSaleForm', 'bulk_sale');
        }
        function handleRetailSubmit() {
            if (confirmRetailSale()) submitFormWithAction('retailSaleForm', 'retail_sale');
        }

        // --- External Sale product picker ---
        (function() {
            var search   = document.getElementById('ext_product_search');
            var dropdown = document.getElementById('ext_product_dropdown');
            var options  = dropdown.querySelectorAll('.searchable-select-option');
            var hi = -1;

            search.addEventListener('focus', function() { dropdown.classList.add('open'); filter(); });
            search.addEventListener('input',  function() { dropdown.classList.add('open'); hi = -1; filter(); });
            search.addEventListener('keydown', function(e) {
                var vis = dropdown.querySelectorAll('.searchable-select-option:not(.hidden)');
                if (e.key === 'ArrowDown') { e.preventDefault(); hi = Math.min(hi+1, vis.length-1); hl(vis); }
                else if (e.key === 'ArrowUp') { e.preventDefault(); hi = Math.max(hi-1, 0); hl(vis); }
                else if (e.key === 'Enter') { e.preventDefault(); if (hi >= 0 && vis[hi]) pick(vis[hi]); }
                else if (e.key === 'Escape') dropdown.classList.remove('open');
            });
            document.addEventListener('click', function(e) {
                if (!e.target.closest('#extProductSearchable')) dropdown.classList.remove('open');
            });
            options.forEach(function(o) { o.addEventListener('click', function() { pick(o); }); });

            function filter() {
                var term = search.value.toLowerCase();
                options.forEach(function(o) { o.classList.toggle('hidden', o.textContent.trim().toLowerCase().indexOf(term) === -1); });
            }
            function hl(vis) {
                options.forEach(function(o) { o.classList.remove('highlighted'); });
                if (vis[hi]) { vis[hi].classList.add('highlighted'); vis[hi].scrollIntoView({block:'nearest'}); }
            }
            function pick(opt) {
                var name  = opt.getAttribute('data-name');
                var bulk  = parseFloat(opt.getAttribute('data-bulk'))  || 0;
                var retail= parseFloat(opt.getAttribute('data-retail')) || 0;
                document.getElementById('ext_product_name').value = name;
                search.value = name;
                dropdown.classList.remove('open'); hi = -1;
                // Suggest price: prefer bulk price, fallback retail
                var priceEl = document.getElementById('ext_unit_price');
                if ((priceEl.value === '' || parseFloat(priceEl.value) === 0) && (bulk > 0 || retail > 0)) {
                    priceEl.value = bulk > 0 ? bulk : retail;
                }
                calcExtTotal();
            }
        })();

        function extSwitchToManual() {
            document.getElementById('ext_picker_mode').style.display = 'none';
            document.getElementById('ext_manual_mode').style.display = 'block';
            document.getElementById('ext_product_name').value = '';
            document.getElementById('ext_manual_name').focus();
            calcExtTotal();
        }
        function extSwitchToPicker() {
            document.getElementById('ext_manual_mode').style.display = 'none';
            document.getElementById('ext_picker_mode').style.display = 'block';
            document.getElementById('ext_product_name').value = document.getElementById('ext_product_search').getAttribute('data-selected') || '';
            calcExtTotal();
        }
        function extSetManualName(val) {
            document.getElementById('ext_product_name').value = val.trim();
            calcExtTotal();
        }

        // --- External Sale ---
        var extCoreValid = false;

        function calcExtTotal() {
            var name  = document.getElementById('ext_product_name').value.trim();
            var qty   = parseInt(document.getElementById('ext_quantity').value) || 0;
            var price = parseFloat(document.getElementById('ext_unit_price').value) || 0;
            var total = qty * price;
            var valid = name.length > 0 && qty > 0 && price > 0;
            extCoreValid = valid;

            if (valid) {
                document.getElementById('ext_sum_product').textContent = name;
                document.getElementById('ext_sum_qty').textContent = qty;
                document.getElementById('ext_sum_price').textContent = 'RWF ' + price.toLocaleString();
                document.getElementById('ext_sum_total').textContent = 'RWF ' + total.toLocaleString();
                document.getElementById('ext_summary').style.display = 'block';
                // Auto-default momo to total when all splits are still 0
                var cash = parseFloat(document.getElementById('ext_cash').value) || 0;
                var momo = parseFloat(document.getElementById('ext_momo').value) || 0;
                var loan = parseFloat(document.getElementById('ext_loan').value) || 0;
                if (cash === 0 && momo === 0 && loan === 0) {
                    document.getElementById('ext_momo').value = total;
                }
            } else {
                document.getElementById('ext_summary').style.display = 'none';
                document.getElementById('ext_submit_btn').disabled = true;
            }
            calcExtSplit();
        }

        function calcExtSplit(changed) {
            var qty   = parseInt(document.getElementById('ext_quantity').value) || 0;
            var price = parseFloat(document.getElementById('ext_unit_price').value) || 0;
            var total = qty * price;
            var cashEl = document.getElementById('ext_cash');
            var momoEl = document.getElementById('ext_momo');
            var loanEl = document.getElementById('ext_loan');
            var cash = parseFloat(cashEl.value) || 0;
            var momo = parseFloat(momoEl.value) || 0;
            var loan = parseFloat(loanEl.value) || 0;

            if (changed === 'cash') {
                momo = Math.max(0, total - cash - loan);
                momoEl.value = momo;
            } else if (changed === 'momo') {
                loan = Math.max(0, total - cash - momo);
                loanEl.value = loan;
            } else if (changed === 'loan') {
                momo = Math.max(0, total - cash - loan);
                momoEl.value = momo;
            }

            var remaining = Math.round(total - cash - momo - loan);
            var splitOk = extCoreValid && remaining === 0;
            var remEl = document.getElementById('ext_remaining');
            var remRow = document.getElementById('ext_remaining_row');
            if (!extCoreValid) {
                remEl.textContent = '—';
                remRow.className = 'split-row split-remaining-row';
            } else {
                remEl.textContent = 'RWF ' + remaining.toLocaleString();
                remRow.className = 'split-row split-remaining-row ' + (splitOk ? 'valid' : 'invalid');
            }

            document.getElementById('ext_loan_fields').style.display = loan > 0 ? 'block' : 'none';

            var clientOk = loan <= 0 || (
                document.getElementById('ext_customer_name').value.trim().length > 0 &&
                document.getElementById('ext_phone').value.trim().length > 0
            );
            document.getElementById('ext_submit_btn').disabled = !(extCoreValid && splitOk && clientOk);
        }

        function handleExtSubmit() {
            var name  = document.getElementById('ext_product_name').value.trim();
            var qty   = document.getElementById('ext_quantity').value;
            var price = parseFloat(document.getElementById('ext_unit_price').value);
            var total = qty * price;
            var cash  = parseFloat(document.getElementById('ext_cash').value) || 0;
            var momo  = parseFloat(document.getElementById('ext_momo').value) || 0;
            var loan  = parseFloat(document.getElementById('ext_loan').value) || 0;
            var parts = [];
            if (cash > 0) parts.push('Cash: RWF ' + cash.toLocaleString());
            if (momo > 0) parts.push('Momo: RWF ' + momo.toLocaleString());
            if (loan > 0) parts.push('Loan: RWF ' + loan.toLocaleString() + ' (deferred)');
            var ok = confirm(
                'Confirm External Sale?\n\n' +
                'Product: ' + name + '\n' +
                'Quantity: ' + qty + '\n' +
                'Unit Price: RWF ' + price.toLocaleString() + '\n' +
                'Total: RWF ' + total.toLocaleString() + '\n' +
                'Payment: ' + parts.join(' | ') + '\n\n' +
                'No stock will be deducted.'
            );
            if (ok) submitFormWithAction('externalSaleForm', 'external_sale');
        }

        initLoanClientPicker('extClientPickerWrap', 'ext_client_picker_search', 'ext_client_picker_dropdown', 'ext_customer_name', 'ext_phone');

        // --- External Sale Owner Picker ---
        function extSyncOwner() {
            var name  = document.getElementById('ext_owner_name_input').value.trim();
            var phone = document.getElementById('ext_owner_phone_input').value.trim();
            document.getElementById('ext_owner_name').value  = name;
            document.getElementById('ext_owner_phone').value = phone;
            // clear search box if user is typing a fresh name
            var search = document.getElementById('ext_owner_search');
            if (search && search.value !== name) search.value = '';
        }

        (function() {
            var wrap     = document.getElementById('extOwnerPickerWrap');
            if (!wrap) return;
            var search   = document.getElementById('ext_owner_search');
            var dropdown = document.getElementById('ext_owner_dropdown');
            var options  = dropdown.querySelectorAll('.searchable-select-option');
            var hi = -1;

            search.addEventListener('focus', function() { dropdown.classList.add('open'); filter(); });
            search.addEventListener('input',  function() { dropdown.classList.add('open'); hi = -1; filter(); });
            search.addEventListener('keydown', function(e) {
                var vis = dropdown.querySelectorAll('.searchable-select-option:not(.hidden)');
                if (e.key === 'ArrowDown') { e.preventDefault(); hi = Math.min(hi+1, vis.length-1); hl(vis); }
                else if (e.key === 'ArrowUp') { e.preventDefault(); hi = Math.max(hi-1, 0); hl(vis); }
                else if (e.key === 'Enter') { e.preventDefault(); if (hi >= 0 && vis[hi]) pick(vis[hi]); }
                else if (e.key === 'Escape') dropdown.classList.remove('open');
            });
            document.addEventListener('click', function(e) {
                if (!e.target.closest('#extOwnerPickerWrap')) dropdown.classList.remove('open');
            });
            options.forEach(function(o) { o.addEventListener('click', function() { pick(o); }); });

            function filter() {
                var term = search.value.toLowerCase();
                options.forEach(function(o) { o.classList.toggle('hidden', o.textContent.trim().toLowerCase().indexOf(term) === -1); });
            }
            function hl(vis) {
                options.forEach(function(o) { o.classList.remove('highlighted'); });
                if (vis[hi]) { vis[hi].classList.add('highlighted'); vis[hi].scrollIntoView({block:'nearest'}); }
            }
            function pick(opt) {
                var owner = opt.getAttribute('data-owner');
                var phone = opt.getAttribute('data-phone');
                document.getElementById('ext_owner_name_input').value  = owner;
                document.getElementById('ext_owner_phone_input').value = phone;
                document.getElementById('ext_owner_name').value  = owner;
                document.getElementById('ext_owner_phone').value = phone;
                search.value = owner;
                dropdown.classList.remove('open'); hi = -1;
            }
        })();

        // --- Sales Tabs ---
        function switchSalesTab(tab) {
            ['bulk','retail','external'].forEach(function(t) {
                document.getElementById('stab-' + t).style.display = t === tab ? '' : 'none';
            });
            document.querySelectorAll('.sales-tab-btn').forEach(function(btn, i) {
                btn.classList.toggle('active', ['bulk','retail','external'][i] === tab);
            });
            document.getElementById('filter_tab').value = tab;
            var ownerGroup = document.getElementById('owner_filter_group');
            if (ownerGroup) ownerGroup.style.display = tab === 'external' ? '' : 'none';
        }

        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(a) {
                setTimeout(function() {
                    a.style.opacity = '0';
                    setTimeout(function() { a.style.display = 'none'; }, 300);
                }, 5000);
            });
        });
    </script>
</body>
</html>