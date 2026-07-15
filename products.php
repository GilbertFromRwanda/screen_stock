<?php
require_once 'config.php';

if (!isLoggedIn()) redirect('login.php');
if (!hasPermission('inventory')) { $_SESSION['flash_error'] = "You don't have permission to access Inventory."; redirect('dashboard.php'); }

// ── XLSX parser using built-in ZipArchive + SimpleXML ─────────────────────────
function xlsx_col_index($ref) {
    preg_match('/^([A-Z]+)/', strtoupper($ref), $m);
    $col = $m[1] ?? 'A';
    $n = 0;
    for ($i = 0; $i < strlen($col); $i++) $n = $n * 26 + (ord($col[$i]) - 64);
    return $n - 1;
}

function parse_xlsx($file) {
    if (!class_exists('ZipArchive')) return false;
    $zip = new ZipArchive();
    if ($zip->open($file) !== true) return false;

    $strings = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml) {
        $ss = simplexml_load_string($ssXml);
        foreach ($ss->si as $si) {
            $text = '';
            if (isset($si->t)) { $text = (string)$si->t; }
            else { foreach ($si->r as $r) $text .= (string)$r->t; }
            $strings[] = $text;
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if (!$sheetXml) return false;

    $sheet = simplexml_load_string($sheetXml);
    $rows  = [];
    foreach ($sheet->sheetData->row as $row) {
        $cells = [];
        $maxCol = 0;
        foreach ($row->c as $cell) {
            $idx   = xlsx_col_index((string)$cell['r']);
            $type  = (string)$cell['t'];
            $value = isset($cell->v) ? (string)$cell->v : '';
            if ($type === 's') $value = $strings[(int)$value] ?? '';
            $cells[$idx] = $value;
            if ($idx > $maxCol) $maxCol = $idx;
        }
        $rowData = [];
        for ($c = 0; $c <= $maxCol; $c++) $rowData[] = $cells[$c] ?? '';
        $rows[] = $rowData;
    }
    return $rows;
}

// ── Duplicate check: same name + category (exclude_id for edits) ──────────────
function product_exists($conn, $name, $category, $exclude_id = 0) {
    $n = mysqli_real_escape_string($conn, $name);
    $c = mysqli_real_escape_string($conn, $category);
    $r = mysqli_query($conn, "SELECT id FROM products WHERE name='$n' AND category='$c' AND deleted=0 AND id != $exclude_id LIMIT 1");
    return $r && mysqli_num_rows($r) > 0;
}

// ── Export products as CSV ────────────────────────────────────────────────────
if (isset($_GET['export'])) {
    $search_exp     = trim($_GET['search'] ?? '');
    $search_esc_exp = mysqli_real_escape_string($conn, $search_exp);
    $where_exp      = "WHERE deleted = 0" . ($search_esc_exp !== '' ? " AND (name LIKE '%$search_esc_exp%' OR category LIKE '%$search_esc_exp%')" : "");
    $exp_q          = mysqli_query($conn, "SELECT name, category, reorder_level, unit_measure, unit_price FROM products $where_exp ORDER BY name ASC");

    $filename = 'products_' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Name', 'Category', 'Reorder Level', 'Unit Measure', 'Unit Price']);
    while ($r = mysqli_fetch_assoc($exp_q)) {
        fputcsv($out, [$r['name'], $r['category'], $r['reorder_level'], $r['unit_measure'], $r['unit_price']]);
    }
    fclose($out);
    exit;
}

// ── Template download ──────────────────────────────────────────────────────────
if (isset($_GET['template'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="products_template.csv"');
    echo "Name,Category,Reorder Level,Unit Measure,Unit Price\n";
    echo "Example Product,Electronics,5,Box,1500\n";
    exit;
}

// ── Import handler (AJAX) ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['excel_file']) && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    $file = $_FILES['excel_file'];
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if ($file['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'error' => 'Upload failed.']); exit;
    }

    $rows = [];
    if ($ext === 'csv') {
        $handle = fopen($file['tmp_name'], 'r');
        while (($row = fgetcsv($handle)) !== false) $rows[] = $row;
        fclose($handle);
    } elseif ($ext === 'xlsx') {
        $rows = parse_xlsx($file['tmp_name']);
        if ($rows === false) { echo json_encode(['success' => false, 'error' => 'Could not read .xlsx file.']); exit; }
    } else {
        echo json_encode(['success' => false, 'error' => 'Only .xlsx and .csv files are supported.']); exit;
    }

    array_shift($rows); // remove header row
    $added = $skipped = $duplicates = 0;

    foreach ($rows as $row) {
        while (count($row) < 5) $row[] = '';
        $name    = trim($row[0]);
        $cat     = trim($row[1]);
        $reorder = max(0, (int)trim($row[2]));
        $unit_m  = trim($row[3]);
        $price   = max(0, (float)trim($row[4]));

        if ($name === '') { $skipped++; continue; }

        if (product_exists($conn, $name, $cat)) { $duplicates++; continue; }

        [$category_id, $category_name] = resolve_category($conn, $cat);
        $category_id_v = $category_id !== null ? (int)$category_id : 'NULL';

        $n = mysqli_real_escape_string($conn, $name);
        $c = mysqli_real_escape_string($conn, $category_name);
        $u = mysqli_real_escape_string($conn, $unit_m);

        if (mysqli_query($conn, "INSERT INTO products (name,category,category_id,reorder_level,unit_measure,unit_price)
                                  VALUES ('$n','$c',$category_id_v,$reorder,'$u',$price)")) {
            $added++;
        } else {
            $skipped++;
        }
    }

    if ($added > 0) touchCacheStore($conn, 'products');
    echo json_encode(['success' => true, 'added' => $added, 'skipped' => $skipped, 'duplicates' => $duplicates]);
    exit;
}

// Handle Add Product
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_product'])) {
    $name          = mysqli_real_escape_string($conn, $_POST['name']);
    [$category_id, $category_name] = resolve_category($conn, $_POST['category'] ?? '');
    $category      = mysqli_real_escape_string($conn, $category_name);
    $category_id_v = $category_id !== null ? (int)$category_id : 'NULL';
    $reorder_level = mysqli_real_escape_string($conn, $_POST['reorder_level']);
    $unit_measure  = mysqli_real_escape_string($conn, $_POST['unit_measure']);
    $unit_price    = mysqli_real_escape_string($conn, $_POST['unit_price']);

    if (product_exists($conn, $name, $category)) {
        $_SESSION['flash_error'] = "A product named \"$name\" already exists in the \"$category\" category.";
    } elseif (mysqli_query($conn, "INSERT INTO products (name, category, category_id, reorder_level, unit_measure, unit_price)
                              VALUES ('$name','$category',$category_id_v,'$reorder_level','$unit_measure','$unit_price')")) {
        touchCacheStore($conn, 'products');
        $_SESSION['flash_success'] = "Product added successfully";
    } else {
        $_SESSION['flash_error'] = "Error adding product: " . mysqli_error($conn);
    }
    header("Location: products.php");
    exit;
}

if (isset($_SESSION['flash_success'])) { $success = $_SESSION['flash_success']; unset($_SESSION['flash_success']); }
if (isset($_SESSION['flash_error']))   { $error   = $_SESSION['flash_error'];   unset($_SESSION['flash_error']); }

// Handle Edit Product
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_product'])) {
    $id            = (int)$_POST['edit_id'];
    $name          = mysqli_real_escape_string($conn, $_POST['edit_name']);
    [$category_id, $category_name] = resolve_category($conn, $_POST['edit_category'] ?? '');
    $category      = mysqli_real_escape_string($conn, $category_name);
    $category_id_v = $category_id !== null ? (int)$category_id : 'NULL';
    $reorder_level = mysqli_real_escape_string($conn, $_POST['edit_reorder_level']);
    $unit_measure  = mysqli_real_escape_string($conn, $_POST['edit_unit_measure']);
    $unit_price    = mysqli_real_escape_string($conn, $_POST['edit_unit_price']);

    $old_product = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id,name,category,reorder_level,unit_measure,unit_price FROM products WHERE id=$id")) ?: [];

    if (product_exists($conn, $name, $category, $id)) {
        $_SESSION['flash_error'] = "Another product named \"$name\" already exists in the \"$category\" category.";
    } elseif (mysqli_query($conn, "UPDATE products SET name='$name', category='$category', category_id=$category_id_v, reorder_level='$reorder_level',
                              unit_measure='$unit_measure', unit_price='$unit_price' WHERE id=$id")) {
        touchCacheStore($conn, 'products');
        $_SESSION['flash_success'] = "Product updated successfully";
        logActivity($conn, (int)$_SESSION['user_id'], 'Edit Product', "Edited product: $name",
            'products', $id, $old_product,
            ['name' => $name, 'category' => $category, 'reorder_level' => $reorder_level, 'unit_measure' => $unit_measure, 'unit_price' => $unit_price]
        );
    } else {
        $_SESSION['flash_error'] = "Error updating product: " . mysqli_error($conn);
    }
    header("Location: products.php");
    exit;
}

// Handle Delete Product
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id          = (int)$_GET['delete'];
    $old_product = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id,name,category,reorder_level,unit_measure,unit_price FROM products WHERE id=$id")) ?: [];
    mysqli_query($conn, "UPDATE products SET deleted=1 WHERE id=$id");
    touchCacheStore($conn, 'products');
    logActivity($conn, (int)$_SESSION['user_id'], 'Delete Product', "Deleted product: " . ($old_product['name'] ?? ''),
        'products', $id, $old_product, []);
    header("Location: products.php");
    exit;
}

// ── Shared: pagination + search logic ─────────────────────────────────────────
$per_page       = 20;
$page           = max(1, (int)($_GET['page'] ?? 1));
$search         = trim($_GET['search'] ?? '');
$search_esc     = mysqli_real_escape_string($conn, $search);

$where = "WHERE deleted = 0" . ($search_esc !== '' ? " AND (name LIKE '%$search_esc%' OR category LIKE '%$search_esc%')" : "");

$total      = (int)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM products $where"))['cnt'];
$total_pages = max(1, (int)ceil($total / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

$result = mysqli_query($conn, "SELECT * FROM products $where ORDER BY name ASC LIMIT $per_page OFFSET $offset");

// ── Build rows HTML ────────────────────────────────────────────────────────────
function build_rows($result, $offset) {
    $html = '';
    $i = $offset + 1;
    while ($row = mysqli_fetch_assoc($result)) {
        $name     = htmlspecialchars($row['name']);
        $cat      = htmlspecialchars($row['category']);
        $um       = htmlspecialchars($row['unit_measure']);
        $js_name  = addslashes($name);
        $js_um    = addslashes($um);
        $html .= "<tr>
            <td>{$i}</td>
            <td>{$name}</td>
            <td>{$cat}</td>
            <td>{$row['reorder_level']}</td>
            <td>{$um}</td>
            <td>RWF " . number_format($row['unit_price'], 0) . "</td>
            <td>
                <div class='act-menu-wrap'>
                    <button class='act-btn' title='Actions' onclick='toggleActMenu(this)'>⋮</button>
                    <div class='act-menu'>
                        <a class='act-item' href='#' onclick=\"editProduct({$row['id']},'$js_name'," . (int)($row['category_id'] ?? 0) . ",{$row['reorder_level']},'$js_um',{$row['unit_price']});closeActMenus()\"><i class='fas fa-pen'></i> Edit</a>
                        <div class='act-menu-sep'></div>
                        <a class='act-item danger' href='products.php?delete={$row['id']}' onclick=\"return confirm('Are you sure?')\"><i class='fas fa-trash'></i> Delete</a>
                    </div>
                </div>
            </td>
        </tr>";
        $i++;
    }
    if ($html === '') $html = "<tr><td colspan='7' style='text-align:center;color:var(--secondary);padding:32px;'>No products found.</td></tr>";
    return $html;
}

// ── Build pagination HTML ──────────────────────────────────────────────────────
function build_pagination($page, $total_pages) {
    if ($total_pages <= 1) return '';
    $btn = function($p, $label, $disabled = false, $active = false) {
        if ($disabled) return "<span class='disabled'>$label</span>";
        if ($active)   return "<span class='active'>$label</span>";
        return "<a href='#' data-page='$p'>$label</a>";
    };
    $html  = $btn(1,       '&laquo;', $page <= 1);
    $html .= $btn($page-1, '&lsaquo;', $page <= 1);
    $start = max(1, $page - 2);
    $end   = min($total_pages, $page + 2);
    if ($start > 1) $html .= "<span class='disabled'>&hellip;</span>";
    for ($p = $start; $p <= $end; $p++) {
        $html .= $btn($p, $p, false, $p === $page);
    }
    if ($end < $total_pages) $html .= "<span class='disabled'>&hellip;</span>";
    $html .= $btn($page+1, '&rsaquo;', $page >= $total_pages);
    $html .= $btn($total_pages, '&raquo;', $page >= $total_pages);
    return $html;
}

// ── AJAX response ──────────────────────────────────────────────────────────────
if (isset($_GET['ajax'])) {
    $from = $total > 0 ? $offset + 1 : 0;
    $to   = min($offset + $per_page, $total);
    header('Content-Type: application/json');
    echo json_encode([
        'rows'       => build_rows($result, $offset),
        'pagination' => build_pagination($page, $total_pages),
        'info'       => "Showing {$from}–{$to} of " . number_format($total) . " products",
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Products - Small Stock Management</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .products-toolbar { display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:16px; }
        .search-wrap { position:relative; flex:1; min-width:200px; max-width:360px; }
        .search-wrap input { width:100%; padding:8px 36px 8px 12px; border:1px solid var(--gray-200); border-radius:8px; font-size:14px; box-sizing:border-box; }
        .search-wrap .search-clear { position:absolute; right:10px; top:50%; transform:translateY(-50%); background:none; border:none; cursor:pointer; font-size:16px; color:var(--secondary); display:none; line-height:1; }
        .search-wrap input:not(:placeholder-shown) ~ .search-clear { display:block; }
        .pagination { display:flex; align-items:center; gap:4px; flex-wrap:wrap; margin-top:16px; }
        .pagination a, .pagination span {
            display:inline-flex; align-items:center; justify-content:center;
            min-width:34px; height:34px; padding:0 10px;
            border-radius:8px; font-size:13px; font-weight:600; text-decoration:none;
            border:1px solid var(--gray-200); color:var(--dark); background:var(--white);
            cursor:pointer; transition:background .15s, border-color .15s;
        }
        .pagination a:hover { background:var(--gray-100); border-color:var(--primary); color:var(--primary); }
        .pagination span.active   { background:var(--primary); color:#fff; border-color:var(--primary); }
        .pagination span.disabled { color:var(--secondary); background:var(--gray-100); cursor:default; }
        .pagination-info { font-size:13px; color:var(--secondary); margin-top:8px; }
        #tblProducts tbody { transition: opacity .15s; }
        #tblProducts tbody.loading { opacity: .4; pointer-events:none; }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php include 'sidebar.php'; ?>

    <div class="main-content">
        <h1>Product Management</h1>

        <?php if (isset($success)): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>
        <?php if (isset($error)):   ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>

        <div class="products-toolbar">
            <button onclick="openModal('addProductModal')" class="btn btn-primary">Add New Product</button>
            <button onclick="openModal('importModal')" class="btn btn-secondary">Import Excel</button>
            <a id="exportBtn" href="products.php?export=1" class="btn btn-secondary" style="text-decoration:none;">Export CSV</a>
            <div class="search-wrap">
                <input type="text" id="productSearch" placeholder="Search name or category..."
                    value="<?php echo htmlspecialchars($search); ?>" autocomplete="off">
                <button class="search-clear" onclick="clearSearch()" title="Clear">&times;</button>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table" id="tblProducts">
                <thead>
                    <tr>
                        <th>No</th><th>Name</th><th>Category</th>
                        <th>Reorder Level</th><th>Unit Measure</th><th>Unit Price</th><th>Actions</th>
                    </tr>
                </thead>
                <tbody id="productsBody">
                    <?php echo build_rows($result, $offset); ?>
                </tbody>
            </table>
        </div>

        <div class="pagination" id="productsPagination">
            <?php echo build_pagination($page, $total_pages); ?>
        </div>
        <div class="pagination-info" id="productsInfo">
            <?php
            $from = $total > 0 ? $offset + 1 : 0;
            echo "Showing {$from}–" . min($offset + $per_page, $total) . " of " . number_format($total) . " products";
            ?>
        </div>
    </div>
</div>

<!-- Add Product Modal -->
<div id="addProductModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('addProductModal')">&times;</span>
        <h2>Add New Product</h2>
        <form method="POST">
            <div class="form-group"><label>Product Name*</label><input type="text" name="name" required></div>
            <div class="form-group">
                <label>Category</label>
                <select id="category_select" onchange="onCategorySelect(this,'category_hidden','category_new')">
                    <option value="">— None —</option>
                    <option value="__new__">+ Add new category…</option>
                </select>
                <input type="text" id="category_new" placeholder="New category name" style="display:none;margin-top:6px;"
                    oninput="document.getElementById('category_hidden').value=this.value">
                <input type="hidden" name="category" id="category_hidden">
            </div>
            <div class="form-group"><label>Reorder Level*</label><input type="number" name="reorder_level" value="2" required></div>
            <div class="form-group"><label>Unit Measure*</label><input type="text" name="unit_measure" value="Box" required></div>
            <div class="form-group"><label>Unit Price (RWF)*</label><input type="number" name="unit_price" step="0.01" required></div>
            <button type="submit" name="add_product" class="btn btn-primary">Add Product</button>
        </form>
    </div>
</div>

<!-- Edit Product Modal -->
<div id="editProductModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('editProductModal')">&times;</span>
        <h2>Edit Product</h2>
        <form method="POST">
            <input type="hidden" id="edit_id" name="edit_id">
            <div class="form-group"><label>Product Name*</label><input type="text" id="edit_name" name="edit_name" required></div>
            <div class="form-group">
                <label>Category</label>
                <select id="edit_category_select" onchange="onCategorySelect(this,'edit_category_hidden','edit_category_new')">
                    <option value="">— None —</option>
                    <option value="__new__">+ Add new category…</option>
                </select>
                <input type="text" id="edit_category_new" placeholder="New category name" style="display:none;margin-top:6px;"
                    oninput="document.getElementById('edit_category_hidden').value=this.value">
                <input type="hidden" name="edit_category" id="edit_category_hidden">
            </div>
            <div class="form-group"><label>Reorder Level*</label><input type="number" id="edit_reorder_level" name="edit_reorder_level" required></div>
            <div class="form-group"><label>Unit Measure*</label><input type="text" id="edit_unit_measure" name="edit_unit_measure" required></div>
            <div class="form-group"><label>Unit Price (RWF)*</label><input type="number" id="edit_unit_price" name="edit_unit_price" step="0.01" required></div>
            <button type="submit" name="edit_product" class="btn btn-primary">Update Product</button>
        </form>
    </div>
</div>

<!-- Import Modal -->
<div id="importModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('importModal')">&times;</span>
        <h2>Import Products from Excel</h2>
        <p style="font-size:13px;color:var(--secondary);margin-bottom:20px;">
            Upload a <strong>.xlsx</strong> or <strong>.csv</strong> file.
            Columns: <em>Name, Category, Reorder Level, Unit Measure, Unit Price</em>.
            &nbsp;<a href="products.php?template=1" style="color:var(--primary);font-weight:600;">Download template</a>
        </p>
        <form id="importForm">
            <div class="form-group">
                <label>File</label>
                <input type="file" id="excelFile" name="excel_file" accept=".xlsx,.csv" required>
            </div>
            <div id="importResult" style="display:none;margin-bottom:12px;"></div>
            <button type="submit" id="importBtn" class="btn btn-primary">Import</button>
        </form>
    </div>
</div>

<script>window.APP_COMPANY_ID = <?php echo json_encode(cid()); ?>;</script>
<script src="js/data-cache.js?v=<?php echo filemtime(__DIR__ . '/js/data-cache.js'); ?>"></script>
<script src="script.js"></script>
<script>
// Populates both category <select>s from DataCache.getCategoriesList() (the full
// managed list, not just categories already assigned to a product) instead of
// rendering options once server-side, so a category created in another tab shows
// up here without reloading this page.
function populateCategorySelects(cats) {
    ['category_select', 'edit_category_select'].forEach(function(id) {
        var select = document.getElementById(id);
        var current = select.value;
        select.innerHTML = '<option value="">— None —</option>';
        cats.forEach(function(c) {
            var opt = document.createElement('option');
            opt.value = c.id;
            opt.textContent = c.name;
            select.appendChild(opt);
        });
        var newOpt = document.createElement('option');
        newOpt.value = '__new__';
        newOpt.textContent = '+ Add new category…';
        select.appendChild(newOpt);
        if (current && select.querySelector('option[value="' + current + '"]')) select.value = current;
    });
}
var categoriesReady = DataCache.getCategoriesList().then(populateCategorySelects);

// Keeps a <select> of category ids in sync with the hidden `category` field the
// form actually submits (its value is always the category *name*, since the
// server resolves categories by name — see resolve_category() in products.php).
function onCategorySelect(select, hiddenId, newInputId) {
    const hidden   = document.getElementById(hiddenId);
    const newInput = document.getElementById(newInputId);
    if (select.value === '__new__') {
        newInput.style.display = 'block';
        newInput.value = '';
        hidden.value = '';
        newInput.focus();
    } else {
        newInput.style.display = 'none';
        newInput.value = '';
        hidden.value = select.value ? select.options[select.selectedIndex].text : '';
    }
}

function editProduct(id, name, categoryId, reorderLevel, unitMeasure, unitPrice) {
    document.getElementById('edit_id').value = id;
    document.getElementById('edit_name').value = name;
    categoriesReady.then(function() {
        const select = document.getElementById('edit_category_select');
        select.value = categoryId && select.querySelector(`option[value="${categoryId}"]`) ? categoryId : '';
        onCategorySelect(select, 'edit_category_hidden', 'edit_category_new');
    });
    document.getElementById('edit_reorder_level').value = reorderLevel;
    document.getElementById('edit_unit_measure').value = unitMeasure;
    document.getElementById('edit_unit_price').value = unitPrice;
    openModal('editProductModal');
}

// ── AJAX search + pagination ───────────────────────────────────────────────────
let currentPage   = <?php echo $page; ?>;
let currentSearch = <?php echo json_encode($search); ?>;
let debounceTimer = null;

const tbody      = document.getElementById('productsBody');
const pagination = document.getElementById('productsPagination');
const infoEl     = document.getElementById('productsInfo');
const searchInput = document.getElementById('productSearch');

const exportBtn = document.getElementById('exportBtn');
function updateExportLink(search) {
    exportBtn.href = 'products.php?export=1' + (search ? '&search=' + encodeURIComponent(search) : '');
}
updateExportLink(currentSearch);

function fetchProducts(page, search) {
    currentPage   = page;
    currentSearch = search;
    updateExportLink(search);
    tbody.classList.add('loading');

    const url = 'products.php?ajax=1&page=' + page + (search ? '&search=' + encodeURIComponent(search) : '');
    fetch(url)
        .then(r => r.json())
        .then(data => {
            tbody.innerHTML      = data.rows;
            pagination.innerHTML = data.pagination;
            infoEl.textContent   = data.info;
            tbody.classList.remove('loading');
            bindPagination();
        })
        .catch(() => tbody.classList.remove('loading'));
}

function bindPagination() {
    pagination.querySelectorAll('a[data-page]').forEach(a => {
        a.addEventListener('click', e => {
            e.preventDefault();
            fetchProducts(parseInt(a.dataset.page), currentSearch);
        });
    });
}

function clearSearch() {
    searchInput.value = '';
    fetchProducts(1, '');
    searchInput.focus();
}

searchInput.addEventListener('input', () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
        fetchProducts(1, searchInput.value.trim());
    }, 300);
});

// prevent form submit on Enter
searchInput.addEventListener('keydown', e => { if (e.key === 'Enter') e.preventDefault(); });

bindPagination();

// ── Excel import ──────────────────────────────────────────────────────────────
document.getElementById('importForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const file = document.getElementById('excelFile').files[0];
    if (!file) return;

    const btn    = document.getElementById('importBtn');
    const result = document.getElementById('importResult');

    btn.disabled    = true;
    btn.textContent = 'Importing…';
    result.style.display = 'none';

    const fd = new FormData();
    fd.append('excel_file', file);

    try {
        const res  = await fetch('products.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd
        });
        const data = await res.json();

        if (data.success) {
            result.className = 'alert alert-success';
            let msg = `${data.added} product(s) imported.`;
            if (data.duplicates > 0) msg += ` ${data.duplicates} duplicate(s) skipped.`;
            if (data.skipped    > 0) msg += ` ${data.skipped} empty row(s) skipped.`;
            result.textContent = msg;
            if (data.added > 0) fetchProducts(1, currentSearch);
        } else {
            result.className   = 'alert alert-danger';
            result.textContent = data.error;
        }
    } catch {
        result.className   = 'alert alert-danger';
        result.textContent = 'Upload failed. Please try again.';
    }

    result.style.display    = 'block';
    btn.disabled            = false;
    btn.textContent         = 'Import';
    document.getElementById('excelFile').value = '';
});
</script>
</body>
</html>
