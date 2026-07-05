<?php
require_once 'config.php';

if (!isLoggedIn()) redirect('login.php');
if (!hasPermission('inventory')) { $_SESSION['flash_error'] = "You don't have permission to access Inventory."; redirect('dashboard.php'); }

function is_ajax() {
    return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

function json_result($success, $message) {
    header('Content-Type: application/json');
    echo json_encode(['success' => $success, 'message' => $message]);
    exit;
}

// Syncs the denormalized products.category text for every product pointing at $category_id.
function sync_products_category_text($conn, $category_id, $name) {
    $n = mysqli_real_escape_string($conn, $name);
    mysqli_query($conn, "UPDATE products SET category='$n' WHERE category_id=$category_id");
}

// ── Build rows HTML ────────────────────────────────────────────────────────────
function build_category_rows($conn) {
    $result = mysqli_query($conn, "
        SELECT c.id, c.name, COUNT(p.id) AS product_count
        FROM categories c
        LEFT JOIN products p ON p.category_id = c.id AND p.deleted = 0
        GROUP BY c.id, c.name
        ORDER BY c.name ASC
    ");
    if (mysqli_num_rows($result) === 0) {
        return "<tr><td colspan='3' style='text-align:center;color:var(--secondary);padding:32px;'>No categories yet.</td></tr>";
    }
    $html = '';
    while ($row = mysqli_fetch_assoc($result)) {
        $id      = (int)$row['id'];
        $name    = htmlspecialchars($row['name']);
        $js_name = addslashes($name);
        $count   = (int)$row['product_count'];
        $delete_item = $count === 0
            ? "<a class='act-item danger' href='#' onclick=\"deleteCategory($id,'$js_name',this);closeActMenus()\"><i class='fas fa-trash'></i> Delete</a>"
            : "<span class='act-item' style='color:var(--secondary);cursor:default;' title='Merge into another category first'>Delete (in use)</span>";
        $html .= "<tr data-name='" . strtolower($js_name) . "'>
            <td>{$name}</td>
            <td>{$count}</td>
            <td>
                <div class='act-menu-wrap'>
                    <button class='act-btn' title='Actions' onclick='toggleActMenu(this)'>&#8942;</button>
                    <div class='act-menu'>
                        <a class='act-item' href='products.php?search=" . urlencode($row['name']) . "'><i class='fas fa-eye'></i> View Products</a>
                        <div class='act-menu-sep'></div>
                        <a class='act-item' href='#' onclick=\"openRenameModal($id,'$js_name');closeActMenus()\"><i class='fas fa-pen'></i> Rename</a>
                        <a class='act-item' href='#' onclick=\"openMergeModal($id,'$js_name');closeActMenus()\"><i class='fas fa-code-branch'></i> Merge into&hellip;</a>
                        <div class='act-menu-sep'></div>
                        {$delete_item}
                    </div>
                </div>
            </td>
        </tr>";
    }
    return $html;
}

// ── AJAX: refresh table + category list ───────────────────────────────────────
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    echo json_encode([
        'rows'       => build_category_rows($conn),
        'categories' => get_categories($conn),
    ]);
    exit;
}

// Handle Add Category
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_category'])) {
    $name = trim($_POST['name'] ?? '');
    if ($name === '') {
        json_result(false, "Category name is required.");
    }
    [$id, $resolved_name] = resolve_category($conn, $name);
    if (strcasecmp($resolved_name, $name) !== 0) {
        json_result(false, "A category named \"$resolved_name\" already exists.");
    }
    json_result(true, "Category \"$resolved_name\" added.");
}

// Handle Rename Category
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['rename_category'])) {
    $id       = (int)$_POST['id'];
    $new_name = trim($_POST['new_name'] ?? '');
    $old      = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id, name FROM categories WHERE id=$id")) ?: [];

    if ($new_name === '') {
        json_result(false, "Category name is required.");
    }
    if (!$old) {
        json_result(false, "Category not found.");
    }
    $n = mysqli_real_escape_string($conn, $new_name);
    $dup = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM categories WHERE name='$n' AND id != $id LIMIT 1"));
    if ($dup) {
        json_result(false, "A category named \"$new_name\" already exists. Use \"Merge into\" instead.");
    }
    if (!mysqli_query($conn, "UPDATE categories SET name='$n' WHERE id=$id")) {
        json_result(false, "Error renaming category: " . mysqli_error($conn));
    }
    sync_products_category_text($conn, $id, $new_name);
    touchCacheStore($conn, 'products');
    touchCacheStore($conn, 'categories');
    logActivity($conn, (int)$_SESSION['user_id'], 'Rename Category', "Renamed category: {$old['name']} -> $new_name",
        'categories', $id, ['name' => $old['name']], ['name' => $new_name]);
    json_result(true, "Category renamed to \"$new_name\".");
}

// Handle Merge Category (moves all products from source into target, deletes source)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['merge_category'])) {
    $source_id = (int)$_POST['source_id'];
    $target_id = (int)$_POST['target_id'];

    if ($source_id === $target_id || $source_id <= 0 || $target_id <= 0) {
        json_result(false, "Choose two different categories to merge.");
    }
    $source = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id, name FROM categories WHERE id=$source_id")) ?: [];
    $target = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id, name FROM categories WHERE id=$target_id")) ?: [];
    if (!$source || !$target) {
        json_result(false, "Category not found.");
    }
    $tn = mysqli_real_escape_string($conn, $target['name']);
    mysqli_query($conn, "UPDATE products SET category_id=$target_id, category='$tn' WHERE category_id=$source_id");
    mysqli_query($conn, "DELETE FROM categories WHERE id=$source_id");
    touchCacheStore($conn, 'products');
    touchCacheStore($conn, 'categories');
    logActivity($conn, (int)$_SESSION['user_id'], 'Merge Category', "Merged category \"{$source['name']}\" into \"{$target['name']}\"",
        'categories', $target_id, ['name' => $source['name']], ['merged_into' => $target['name']]);
    json_result(true, "Merged \"{$source['name']}\" into \"{$target['name']}\".");
}

// Handle Delete Category (only when unused)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_category'])) {
    $id  = (int)$_POST['id'];
    $cat = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id, name FROM categories WHERE id=$id")) ?: [];
    $in_use = (int)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM products WHERE category_id=$id AND deleted=0"))['cnt'];

    if (!$cat) {
        json_result(false, "Category not found.");
    }
    if ($in_use > 0) {
        json_result(false, "\"{$cat['name']}\" is used by $in_use product(s). Merge it into another category first.");
    }
    if (!mysqli_query($conn, "DELETE FROM categories WHERE id=$id")) {
        json_result(false, "Error deleting category: " . mysqli_error($conn));
    }
    touchCacheStore($conn, 'categories');
    logActivity($conn, (int)$_SESSION['user_id'], 'Delete Category', "Deleted category: {$cat['name']}", 'categories', $id, $cat, []);
    json_result(true, "Category \"{$cat['name']}\" deleted.");
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Categories - Small Stock Management</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .products-toolbar { display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:16px; }
        .search-wrap { position:relative; flex:1; min-width:200px; max-width:360px; }
        .search-wrap input { width:100%; padding:8px 36px 8px 12px; border:1px solid var(--gray-200); border-radius:8px; font-size:14px; box-sizing:border-box; }
        .search-wrap .search-clear { position:absolute; right:10px; top:50%; transform:translateY(-50%); background:none; border:none; cursor:pointer; font-size:16px; color:var(--secondary); display:none; line-height:1; }
        .search-wrap input:not(:placeholder-shown) ~ .search-clear { display:block; }
        #categoriesBody { transition: opacity .15s; }
        #categoriesBody.loading { opacity: .4; pointer-events:none; }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php include 'sidebar.php'; ?>

    <div class="main-content">
        <h1>Category Management</h1>

        <div id="flashBox"></div>

        <div class="products-toolbar">
            <button onclick="openModal('addCategoryModal')" class="btn btn-primary">Add Category</button>
            <div class="search-wrap">
                <input type="text" id="categorySearch" placeholder="Search category..." autocomplete="off">
                <button class="search-clear" onclick="clearSearch()" title="Clear">&times;</button>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table" id="tblCategories">
                <thead>
                    <tr><th>Name</th><th>Products</th><th>Actions</th></tr>
                </thead>
                <tbody id="categoriesBody">
                    <?php echo build_category_rows($conn); ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Category Modal -->
<div id="addCategoryModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('addCategoryModal')">&times;</span>
        <h2>Add Category</h2>
        <form id="addCategoryForm">
            <div class="form-group"><label>Name*</label><input type="text" name="name" required></div>
            <button type="submit" class="btn btn-primary">Add Category</button>
        </form>
    </div>
</div>

<!-- Rename Category Modal -->
<div id="renameCategoryModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('renameCategoryModal')">&times;</span>
        <h2>Rename Category</h2>
        <form id="renameCategoryForm">
            <input type="hidden" id="rename_id" name="id">
            <div class="form-group"><label>New Name*</label><input type="text" id="rename_new_name" name="new_name" required></div>
            <button type="submit" class="btn btn-primary">Rename</button>
        </form>
    </div>
</div>

<!-- Merge Category Modal -->
<div id="mergeCategoryModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('mergeCategoryModal')">&times;</span>
        <h2>Merge Category</h2>
        <p style="font-size:13px;color:var(--secondary);margin-bottom:16px;">
            Every product in <strong id="merge_source_label"></strong> will be moved into the category you pick below,
            and <strong id="merge_source_label2"></strong> will be deleted. This can't be undone.
        </p>
        <form id="mergeCategoryForm">
            <input type="hidden" id="merge_source_id" name="source_id">
            <div class="form-group">
                <label>Merge into*</label>
                <select id="merge_target_id" name="target_id" required></select>
            </div>
            <button type="submit" class="btn btn-primary">Merge</button>
        </form>
    </div>
</div>

<script src="script.js"></script>
<script>
var allCategoriesData = [];
fetch('categories.php?ajax=1').then(r => r.json()).then(data => { allCategoriesData = data.categories; });

function showFlash(success, message) {
    const box = document.getElementById('flashBox');
    box.innerHTML = "<div class='alert alert-" + (success ? 'success' : 'danger') + "'>" + message + "</div>";
    clearTimeout(showFlash._t);
    showFlash._t = setTimeout(() => { box.innerHTML = ''; }, 4000);
}

function refreshCategories() {
    const tbody = document.getElementById('categoriesBody');
    tbody.classList.add('loading');
    fetch('categories.php?ajax=1')
        .then(r => r.json())
        .then(data => {
            tbody.innerHTML = data.rows;
            allCategoriesData = data.categories;
            tbody.classList.remove('loading');
            applySearchFilter();
        })
        .catch(() => tbody.classList.remove('loading'));
}

function postAjax(fd) {
    return fetch('categories.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    }).then(r => r.json());
}

// Disables a form's submit button and swaps its label while an AJAX request is in
// flight; returns a restore() to call once the request settles (success, error, or
// network failure) so the button never gets stuck disabled.
function setFormLoading(form, loadingText) {
    const btn      = form.querySelector('button[type="submit"]');
    const original = btn.textContent;
    btn.disabled    = true;
    btn.textContent = loadingText;
    return () => { btn.disabled = false; btn.textContent = original; };
}

document.getElementById('addCategoryForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const form = this;
    const fd = new FormData(form);
    fd.append('add_category', '1');
    const restore = setFormLoading(form, 'Adding…');
    postAjax(fd).then(res => {
        restore();
        showFlash(res.success, res.message);
        if (res.success) { closeModal('addCategoryModal'); form.reset(); refreshCategories(); }
    }).catch(() => { restore(); showFlash(false, 'Network error. Please try again.'); });
});

document.getElementById('renameCategoryForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const form = this;
    const fd = new FormData(form);
    fd.append('rename_category', '1');
    const restore = setFormLoading(form, 'Renaming…');
    postAjax(fd).then(res => {
        restore();
        showFlash(res.success, res.message);
        if (res.success) { closeModal('renameCategoryModal'); refreshCategories(); }
    }).catch(() => { restore(); showFlash(false, 'Network error. Please try again.'); });
});

document.getElementById('mergeCategoryForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const form = this;
    const fd = new FormData(form);
    fd.append('merge_category', '1');
    const restore = setFormLoading(form, 'Merging…');
    postAjax(fd).then(res => {
        restore();
        showFlash(res.success, res.message);
        if (res.success) { closeModal('mergeCategoryModal'); refreshCategories(); }
    }).catch(() => { restore(); showFlash(false, 'Network error. Please try again.'); });
});

function deleteCategory(id, name, el) {
    if (!confirm('Delete "' + name + '"?')) return;
    const fd = new FormData();
    fd.append('delete_category', '1');
    fd.append('id', id);
    if (el) { el.style.pointerEvents = 'none'; el.style.opacity = '.5'; }
    postAjax(fd).then(res => {
        showFlash(res.success, res.message);
        if (res.success) refreshCategories();
        else if (el) { el.style.pointerEvents = ''; el.style.opacity = ''; }
    }).catch(() => {
        showFlash(false, 'Network error. Please try again.');
        if (el) { el.style.pointerEvents = ''; el.style.opacity = ''; }
    });
}

function openRenameModal(id, name) {
    document.getElementById('rename_id').value = id;
    document.getElementById('rename_new_name').value = name;
    openModal('renameCategoryModal');
}

function buildMergeOptions(excludeId) {
    const select = document.getElementById('merge_target_id');
    select.innerHTML = '';
    allCategoriesData.forEach(c => {
        if (c.id == excludeId) return;
        const opt = document.createElement('option');
        opt.value = c.id;
        opt.textContent = c.name;
        select.appendChild(opt);
    });
}

function openMergeModal(id, name) {
    document.getElementById('merge_source_id').value = id;
    document.getElementById('merge_source_label').textContent  = name;
    document.getElementById('merge_source_label2').textContent = name;

    // The initial category-list fetch may not have resolved yet if this is opened
    // right after page load; show a loading option instead of an empty dropdown.
    if (allCategoriesData.length === 0) {
        const select = document.getElementById('merge_target_id');
        select.innerHTML = '<option value="">Loading&hellip;</option>';
        fetch('categories.php?ajax=1').then(r => r.json()).then(data => {
            allCategoriesData = data.categories;
            buildMergeOptions(id);
        });
    } else {
        buildMergeOptions(id);
    }
    openModal('mergeCategoryModal');
}

// ── Client-side search filter (category lists are small; no server round-trip needed) ──
const categorySearch = document.getElementById('categorySearch');

function applySearchFilter() {
    const q = categorySearch.value.trim().toLowerCase();
    document.querySelectorAll('#categoriesBody tr[data-name]').forEach(tr => {
        tr.style.display = tr.dataset.name.indexOf(q) === -1 ? 'none' : '';
    });
}

function clearSearch() {
    categorySearch.value = '';
    applySearchFilter();
    categorySearch.focus();
}

categorySearch.addEventListener('input', applySearchFilter);
</script>
</body>
</html>
