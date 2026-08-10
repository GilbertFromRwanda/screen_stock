<?php
require_once 'config.php';

if (!isLoggedIn()) redirect('login.php');
if (!in_array($_SESSION['role'] ?? '', ['admin', 'superadmin'])) {
    $_SESSION['flash_error'] = "You don't have permission to access Translations.";
    redirect('dashboard.php');
}

$lang_files = ['en' => __DIR__ . '/lang/en.php', 'rw' => __DIR__ . '/lang/rw.php', 'fr' => __DIR__ . '/lang/fr.php'];
$lang_labels = ['en' => 'English', 'rw' => 'Kinyarwanda', 'fr' => 'French'];

function readLangFile(string $path): array {
    return file_exists($path) ? (require $path) : [];
}

// Rewrites a lang/*.php file from scratch as a flat `<?php return [...]` array.
// var_export() escapes keys/values safely, so this is immune to injection —
// but it does mean any section-comment grouping in the file is lost on save;
// row order is preserved (whatever order $data was built in).
function writeLangFile(string $path, array $data, string $label): void {
    $lines = ['<?php', "// $label translations — managed from Admin > Translations (translations.php).",
              '// Hand edits are fine but will be reformatted (order/quoting) on the next save there.', 'return ['];
    foreach ($data as $k => $v) {
        $lines[] = '    ' . var_export((string)$k, true) . ' => ' . var_export((string)$v, true) . ',';
    }
    $lines[] = '];';
    $lines[] = '';
    file_put_contents($path, implode("\n", $lines));
    if (function_exists('opcache_invalidate')) opcache_invalidate($path, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $is_ajax = (
        (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || ($_POST['ajax'] ?? '') === '1'
    );

    $keys   = $_POST['key']    ?? [];
    $en_in  = $_POST['en']     ?? [];
    $rw_in  = $_POST['rw']     ?? [];
    $fr_in  = $_POST['fr']     ?? [];
    $delete = $_POST['delete'] ?? [];

    $new_keys = $_POST['new_key'] ?? [];
    $new_en   = $_POST['new_en']  ?? [];
    $new_rw   = $_POST['new_rw']  ?? [];
    $new_fr   = $_POST['new_fr']  ?? [];

    $en_out = []; $rw_out = []; $fr_out = [];

    foreach ($keys as $i => $key) {
        if (!empty($delete[$i])) continue;
        $key = trim((string)$key);
        if ($key === '' || isset($en_out[$key])) continue;
        $en_out[$key] = (string)($en_in[$i] ?? '');
        $rw_out[$key] = (string)($rw_in[$i] ?? '');
        $fr_out[$key] = (string)($fr_in[$i] ?? '');
    }

    foreach ($new_keys as $i => $key) {
        $key = trim((string)$key);
        // Only plain snake_case identifiers — these become PHP array keys read by t().
        if ($key === '' || !preg_match('/^[a-z][a-z0-9_]*$/', $key) || isset($en_out[$key])) continue;
        $en_out[$key] = (string)($new_en[$i] ?? '');
        $rw_out[$key] = (string)($new_rw[$i] ?? '');
        $fr_out[$key] = (string)($new_fr[$i] ?? '');
    }

    writeLangFile($lang_files['en'], $en_out, $lang_labels['en']);
    writeLangFile($lang_files['rw'], $rw_out, $lang_labels['rw']);
    writeLangFile($lang_files['fr'], $fr_out, $lang_labels['fr']);

    // logActivity($conn, (int)$_SESSION['user_id'], 'Edit Translations', 'Updated language strings', 'lang', 0, [], ['keys' => count($en_out)]);

    $message = 'Translations saved (' . count($en_out) . ' keys).';

    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => $message, 'count' => count($en_out)]);
        exit;
    }

    $_SESSION['flash_success'] = $message;
    redirect('translations.php');
}

$en = readLangFile($lang_files['en']);
$rw = readLangFile($lang_files['rw']);
$fr = readLangFile($lang_files['fr']);

// English is canonical, so its key order drives the table; any stray keys that
// only exist in rw/fr (shouldn't normally happen) are appended, not dropped.
$all_keys = array_keys($en);
foreach ([$rw, $fr] as $extra) {
    foreach (array_keys($extra) as $k) {
        if (!in_array($k, $all_keys, true)) $all_keys[] = $k;
    }
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(currentLang()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#103060">
    <link rel="icon" type="image/png" href="icons/favicon-32.png">
    <link rel="apple-touch-icon" href="icons/apple-touch-icon.png">
    <script src="pwa.js" defer></script>
    <title>Translations - GilStock</title>
    <link rel="stylesheet" href="css/style.css?v=<?php echo filemtime(__DIR__ . '/css/style.css'); ?>">
    <style>
        .tr-wrap       { max-width: 100%; margin: 0 auto; padding: 32px 24px 80px; }
        .tr-page-title { font-size: 22px; font-weight: 700; margin-bottom: 6px; color: var(--text, #1e293b); }
        .tr-page-sub   { font-size: 13px; color: #64748b; margin-bottom: 20px; }

        .tr-toolbar { display: flex; align-items: center; gap: 10px; margin-bottom: 14px; flex-wrap: wrap; }
        .tr-search  { flex: 1; min-width: 220px; max-width: 360px; padding: 9px 14px; border: 1.5px solid #e2e8f0;
                      border-radius: 8px; font-size: 13px; }
        .tr-count   { font-size: 12px; color: #64748b; }

        .tr-table-wrap { background: #fff; border: 1px solid var(--gray-200, #e2e8f0); border-radius: 12px;
                          overflow: auto; box-shadow: 0 1px 4px rgba(0,0,0,.05); }
        table.tr-table { width: 100%; border-collapse: collapse; font-size: 13px; min-width: 900px; }
        table.tr-table thead th {
            position: sticky; top: 0; background: #f8fafc; text-align: left; font-size: 11px;
            text-transform: uppercase; letter-spacing: .4px; color: #64748b; font-weight: 700;
            padding: 10px 12px; border-bottom: 1px solid #e2e8f0; z-index: 1;
        }
        table.tr-table td { padding: 6px 10px; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
        table.tr-table tr:last-child td { border-bottom: none; }
        .tr-key { font-family: monospace; font-size: 12px; color: #475569; padding-top: 12px; white-space: nowrap; }
        .tr-input, .tr-key-input {
            width: 100%; min-width: 160px; border: 1.5px solid #e2e8f0; border-radius: 6px;
            padding: 7px 9px; font-size: 12.5px; font-family: inherit; resize: vertical;
        }
        .tr-input:focus, .tr-key-input:focus { outline: none; border-color: #103060; }
        .tr-key-input { font-family: monospace; }
        .tr-del-cell { text-align: center; padding-top: 12px; }
        .tr-del-cell input { cursor: pointer; }
        tr.tr-row-deleted { opacity: .35; }

        .tr-new-hdr { padding: 10px 12px; font-size: 12px; font-weight: 700; color: #103060; background: #eef2ff; }
        .tr-add-btn { margin-top: 10px; padding: 7px 16px; border: 1px dashed #94a3b8; border-radius: 8px;
                      background: #fff; color: #475569; font-size: 12.5px; cursor: pointer; }
        .tr-add-btn:hover { background: #f8fafc; border-color: #64748b; }

        .tr-actions { position: sticky; bottom: 0; margin-top: 18px; padding: 14px 0; }
        .tr-save-btn { padding: 10px 26px; background: #103060; color: #fff; border: none; border-radius: 8px;
                       font-size: 14px; font-weight: 600; cursor: pointer; }
        .tr-save-btn:hover { background: #0a2148; }
        .tr-back { margin-left: 14px; font-size: 13px; color: #64748b; text-decoration: none; }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php include 'sidebar.php'; ?>
    <div class="main-content">
    <div class="tr-wrap">
        <div class="tr-page-title">Translations</div>
        <div class="tr-page-sub">Every key used by <code>t()</code> across the app, with its English, Kinyarwanda and French text. Edit any cell and Save — new pages that call <code>t('some_key')</code> just need a row added here.</div>

        <div id="trFlash">
        <?php if (!empty($_SESSION['flash_success'])): ?>
            <div class="alert alert-success" style="margin-bottom:16px;"><?php echo htmlspecialchars($_SESSION['flash_success']); unset($_SESSION['flash_success']); ?></div>
        <?php endif; ?>
        </div>

        <form method="POST" id="trForm">
            <div class="tr-toolbar">
                <input type="text" id="trSearch" class="tr-search" placeholder="Filter by key or text…" oninput="trFilter()">
                <span class="tr-count" id="trCount"><?= count($all_keys) ?> keys</span>
            </div>

            <div class="tr-table-wrap">
                <table class="tr-table" id="trTable">
                    <thead>
                        <tr>
                            <th style="width:220px;">Key</th>
                            <th>English</th>
                            <th>Kinyarwanda</th>
                            <th>French</th>
                            <th style="width:60px;">Remove</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($all_keys as $i => $key): ?>
                        <tr class="tr-row" data-search="<?= htmlspecialchars(strtolower($key . ' ' . ($en[$key] ?? '') . ' ' . ($rw[$key] ?? '') . ' ' . ($fr[$key] ?? ''))) ?>">
                            <td class="tr-key">
                                <?= htmlspecialchars($key) ?>
                                <input type="hidden" name="key[<?= $i ?>]" value="<?= htmlspecialchars($key) ?>">
                            </td>
                            <td><textarea class="tr-input" name="en[<?= $i ?>]" rows="1"><?= htmlspecialchars($en[$key] ?? '') ?></textarea></td>
                            <td><textarea class="tr-input" name="rw[<?= $i ?>]" rows="1"><?= htmlspecialchars($rw[$key] ?? '') ?></textarea></td>
                            <td><textarea class="tr-input" name="fr[<?= $i ?>]" rows="1"><?= htmlspecialchars($fr[$key] ?? '') ?></textarea></td>
                            <td class="tr-del-cell">
                                <label title="Remove this key from all languages">
                                    <input type="checkbox" name="delete[<?= $i ?>]" value="1" onchange="this.closest('tr').classList.toggle('tr-row-deleted', this.checked)">
                                </label>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tbody id="trNewRows"></tbody>
                </table>
            </div>

            <button type="button" class="tr-add-btn" onclick="trAddRow()">+ Add new key</button>

            <div class="tr-actions">
                <button type="submit" class="tr-save-btn" id="trSaveBtn">Save All Translations</button>
                <a href="dashboard.php" class="tr-back">&larr; Back to Dashboard</a>
            </div>
        </form>
    </div>
    </div>
</div>

<script>
var trNewIndex = 0;
function trAddRow() {
    var tbody = document.getElementById('trNewRows');
    var i = 'n' + (trNewIndex++);
    var tr = document.createElement('tr');
    tr.className = 'tr-row';
    tr.dataset.search = '';
    tr.innerHTML =
        '<td><input type="text" class="tr-key-input" name="new_key[' + i + ']" placeholder="new_key_name"></td>' +
        '<td><textarea class="tr-input" name="new_en[' + i + ']" rows="1"></textarea></td>' +
        '<td><textarea class="tr-input" name="new_rw[' + i + ']" rows="1"></textarea></td>' +
        '<td><textarea class="tr-input" name="new_fr[' + i + ']" rows="1"></textarea></td>' +
        '<td class="tr-del-cell"><button type="button" onclick="this.closest(\'tr\').remove()" style="border:none;background:none;color:#dc2626;cursor:pointer;font-size:16px;">&times;</button></td>';
    tbody.appendChild(tr);
    tr.querySelector('input').focus();
}

function trFilter() {
    var q = document.getElementById('trSearch').value.trim().toLowerCase();
    var rows = document.querySelectorAll('#trTable tbody tr.tr-row');
    var shown = 0;
    rows.forEach(function (row) {
        var match = q === '' || (row.dataset.search || '').indexOf(q) !== -1;
        row.style.display = match ? '' : 'none';
        if (match) shown++;
    });
    document.getElementById('trCount').textContent = shown + ' / ' + rows.length + ' keys';
}

// Auto-grow textareas so multi-line values don't get clipped.
document.getElementById('trTable').addEventListener('input', function (e) {
    if (e.target.tagName === 'TEXTAREA') {
        e.target.style.height = 'auto';
        e.target.style.height = e.target.scrollHeight + 'px';
    }
});
document.querySelectorAll('#trTable textarea').forEach(function (t) {
    t.style.height = 'auto';
    t.style.height = t.scrollHeight + 'px';
});

// ── Save via AJAX ────────────────────────────────────────────────────────────
function trFlash(msg, ok) {
    var box = document.getElementById('trFlash');
    box.innerHTML = '<div class="alert alert-' + (ok ? 'success' : 'danger') + '" style="margin-bottom:16px;">' + msg + '</div>';
}

// Rows deleted (checked) get removed from the DOM, and any still-editable
// "new key" rows get folded into the main table as locked-in existing rows —
// so a second save doesn't re-submit the same new_key[] fields (which would
// silently no-op on the backend since the key already exists by then).
function trLockInSavedRows() {
    document.querySelectorAll('#trTable tr.tr-row').forEach(function (row) {
        var delCb = row.querySelector('input[type="checkbox"][name^="delete"]');
        if (delCb && delCb.checked) { row.remove(); return; }

        var keyInput = row.querySelector('input.tr-key-input');
        if (!keyInput) return; // already a locked-in row
        var key = keyInput.value.trim();
        if (key === '') { row.remove(); return; }

        var idx = 'saved' + (trNewIndex++);
        var texts = row.querySelectorAll('textarea');
        var enVal = texts[0] ? texts[0].value : '';
        var rwVal = texts[1] ? texts[1].value : '';
        var frVal = texts[2] ? texts[2].value : '';

        row.dataset.search = (key + ' ' + enVal + ' ' + rwVal + ' ' + frVal).toLowerCase();
        row.cells[0].innerHTML = key.replace(/&/g,'&amp;').replace(/</g,'&lt;') +
            '<input type="hidden" name="key[' + idx + ']" value="' + key.replace(/"/g,'&quot;') + '">';
        texts[0].name = 'en[' + idx + ']';
        texts[1].name = 'rw[' + idx + ']';
        texts[2].name = 'fr[' + idx + ']';
        row.cells[4].innerHTML = '<label title="Remove this key from all languages">' +
            '<input type="checkbox" name="delete[' + idx + ']" value="1" onchange="this.closest(\'tr\').classList.toggle(\'tr-row-deleted\', this.checked)"></label>';

        document.getElementById('trTable').querySelector('tbody').appendChild(row);
    });
}

document.getElementById('trForm').addEventListener('submit', function (e) {
    e.preventDefault();
    var btn = document.getElementById('trSaveBtn');
    var originalText = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Saving…';

    var data = new FormData(this);
    data.append('ajax', '1');

    fetch('translations.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: data
    })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res.success) {
                trLockInSavedRows();
                trFilter();
                trFlash(res.message, true);
            } else {
                trFlash(res.message || 'Save failed — please try again.', false);
            }
        })
        .catch(function () {
            trFlash('Network error — your changes were not saved. Please try again.', false);
        })
        .finally(function () {
            btn.disabled = false;
            btn.textContent = originalText;
        });
});
</script>
</body>
</html>
