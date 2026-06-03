<?php
require_once 'config.php';
if (!isLoggedIn()) redirect('login.php');

$month_start = date('Y-m-01');
$month_end   = date('Y-m-t');

$date_from = mysqli_real_escape_string($conn, $_GET['date_from'] ?? $month_start);
$date_to   = mysqli_real_escape_string($conn, $_GET['date_to']   ?? $month_end);

// All loans in date range with paid sum and balance
$loans_q = mysqli_query($conn, "
    SELECT l.id, l.loan_date, l.client, l.phone,
           COALESCE(p.name, l.product_name)     AS product_name,
           COALESCE(p.category, 'External')      AS product_category,
           l.amount,
           COALESCE(lp_s.paid, 0)               AS total_paid,
           l.amount - COALESCE(lp_s.paid, 0)    AS balance,
           u.full_name                           AS given_by_name,
           l.bulk_id, l.retail_id, l.external_id,
           l.client_id
    FROM loans l
    LEFT JOIN products p ON p.id = l.product_id
    LEFT JOIN (SELECT loan_id, SUM(amount_paid) AS paid FROM loan_payments GROUP BY loan_id) lp_s
           ON lp_s.loan_id = l.id
    LEFT JOIN users u ON u.id = l.given_by
    WHERE l.loan_date BETWEEN '$date_from' AND '$date_to' " . cidAndFor('l') . "
    ORDER BY l.loan_date DESC, l.id DESC
");

$loans      = [];
$total_amt  = 0;
$total_paid = 0;
while ($r = mysqli_fetch_assoc($loans_q)) {
    $loans[]     = $r;
    $total_amt  += (float)$r['amount'];
    $total_paid += (float)$r['total_paid'];
}
$total_balance = $total_amt - $total_paid;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Loans</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/loans.css">
</head>
<body>
<div class="dashboard-container">
    <?php include 'sidebar.php'; ?>
    <div class="main-content">

        <div class="loans-header">
            <h1>All Loans</h1>
        </div>

        <!-- Date filter -->
        <form method="GET" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-bottom:20px;">
            <div class="form-group" style="margin:0;min-width:140px;">
                <label style="font-size:12px;">From</label>
                <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>"
                    style="padding:7px 10px;border:1px solid var(--gray-300);border-radius:var(--radius);font-size:13px;width:100%;">
            </div>
            <div class="form-group" style="margin:0;min-width:140px;">
                <label style="font-size:12px;">To</label>
                <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>"
                    style="padding:7px 10px;border:1px solid var(--gray-300);border-radius:var(--radius);font-size:13px;width:100%;">
            </div>
            <button type="submit" class="btn btn-primary" style="padding:7px 20px;">Filter</button>
            <a href="all_loans.php" class="btn btn-secondary" style="padding:7px 16px;">This Month</a>
        </form>

        <!-- Summary cards -->
        <div class="loans-summary" style="margin-bottom:20px;">
            <div class="loan-card">
                <div class="loan-card-label">Total Loans</div>
                <div class="loan-card-value"><?= count($loans) ?></div>
                <div class="loan-card-sub"><?= htmlspecialchars($date_from) ?> → <?= htmlspecialchars($date_to) ?></div>
            </div>
            <div class="loan-card green">
                <div class="loan-card-label">Total Loaned</div>
                <div class="loan-card-value">RWF <?= number_format($total_amt, 0) ?></div>
            </div>
            <div class="loan-card orange">
                <div class="loan-card-label">Collected</div>
                <div class="loan-card-value success">RWF <?= number_format($total_paid, 0) ?></div>
            </div>
            <div class="loan-card red">
                <div class="loan-card-label">Outstanding</div>
                <div class="loan-card-value <?= $total_balance > 0 ? 'danger' : 'success' ?>">
                    RWF <?= number_format($total_balance, 0) ?>
                </div>
            </div>
        </div>

        <div id="pageAlert" class="alert" style="display:none;"></div>

        <!-- Search -->
        <div style="margin-bottom:12px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <input type="text" id="loanSearch" placeholder="Search client, product, phone..."
                oninput="filterTable()"
                style="flex:1;min-width:200px;max-width:360px;padding:8px 12px;border:1px solid var(--gray-300);border-radius:var(--radius);font-size:14px;">
            <div style="display:flex;gap:6px;">
                <button class="filter-status-btn active" data-status="all"     onclick="setFilter(this)">All</button>
                <button class="filter-status-btn"        data-status="unpaid"  onclick="setFilter(this)">Unpaid</button>
                <button class="filter-status-btn"        data-status="partial" onclick="setFilter(this)">Partial</button>
                <button class="filter-status-btn"        data-status="paid"    onclick="setFilter(this)">Paid</button>
            </div>
            <span id="rowCount" style="font-size:13px;color:var(--secondary);"></span>
        </div>

        <?php if (empty($loans)): ?>
        <div style="text-align:center;padding:48px;color:var(--secondary);">No loans found for this period.</div>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table class="table" id="tbl-all-loans" style="min-width:800px;">
            <thead>
                <tr>
                    <th></th>
                    <th>#</th>
                    <th>Date</th>
                    <th>Client</th>
                    <th>Phone</th>
                    <th>Product</th>
                    <th>Amount</th>
                    <th>Paid</th>
                    <th>Balance</th>
                    <th>Status</th>
                    <th>Given By</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($loans as $i => $l):
                $balance = (float)$l['balance'];
                $paid    = (float)$l['total_paid'];
                if ($balance <= 0)      { $status = 'paid';    $badge = 'badge-paid';    $label = 'Paid'; }
                elseif ($paid > 0)      { $status = 'partial'; $badge = 'badge-partial'; $label = 'Partial'; }
                else                    { $status = 'unpaid';  $badge = 'badge-unpaid';  $label = 'Unpaid'; }

                $sale_tab = '';
                $sale_id  = 0;
                if ($l['external_id'])      { $sale_tab = 'external'; $sale_id = $l['external_id']; }
                elseif ($l['bulk_id'])      { $sale_tab = 'bulk';     $sale_id = $l['bulk_id']; }
                elseif ($l['retail_id'])    { $sale_tab = 'retail';   $sale_id = $l['retail_id']; }
            ?>
            <tr data-status="<?= $status ?>">
                <td>
                    <div class="act-menu-wrap">
                        <button class="act-btn" title="Actions" onclick="toggleActMenu(this)"><i class="fas fa-ellipsis-v"></i></button>
                        <div class="act-menu">
                            <?php if ($sale_id):
                                $sale_label = ['bulk'=>'Bulk','retail'=>'Retail','external'=>'External'][$sale_tab] ?? 'Sale';
                            ?>
                            <a class="act-item" href="sales.php?tab=<?= $sale_tab ?>&highlight=<?= $sale_id ?>" target="_blank"><i class="fas fa-arrow-up-right-from-square"></i> <?= $sale_label ?> Sale</a>
                            <?php endif; ?>
                            <?php if ($balance > 0): ?>
                            <?php if ($sale_id): ?><div class="act-menu-sep"></div><?php endif; ?>
                            <button class="act-item" style="color:#d97706;"
                                data-loan-id="<?= (int)$l['id'] ?>"
                                data-balance="<?= (float)$balance ?>"
                                data-client="<?= htmlspecialchars($l['client'], ENT_QUOTES) ?>"
                                onclick="openPayment(this);closeActMenus()"><i class="fas fa-money-bill-wave"></i> Pay</button>
                            <?php endif; ?>
                        </div>
                    </div>
                </td>
                <td style="color:var(--secondary);"><?= $i + 1 ?></td>
                <td style="white-space:nowrap;"><?= htmlspecialchars($l['loan_date']) ?></td>
                <td style="font-weight:600;"><?= htmlspecialchars($l['client']) ?></td>
                <td style="color:var(--secondary);"><?= htmlspecialchars($l['phone'] ?: '—') ?></td>
                <td><?= htmlspecialchars($l['product_category'] . '-' . ($l['product_name'] ?: '—')) ?></td>
                <td>RWF <?= number_format((float)$l['amount'], 0) ?></td>
                <td>RWF <?= number_format($paid, 0) ?></td>
                <td class="<?= $balance > 0 ? 'has-balance' : 'cleared' ?>">
                    <strong>RWF <?= number_format(abs($balance), 0) ?></strong>
                </td>
                <td><span class="<?= $badge ?>"><?= $label ?></span></td>
                <td style="color:var(--secondary);font-size:12px;"><?= htmlspecialchars($l['given_by_name'] ?: '—') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>

    </div>
</div>

<!-- Payment Modal -->
<div id="paymentModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('paymentModal')">&times;</span>
        <h2>Record Payment</h2>
        <div id="paymentAlert" class="alert" style="display:none;"></div>
        <div id="paymentInfo" class="payment-info-box" style="display:none;"></div>
        <form id="paymentForm">
            <input type="hidden" id="pay_loan_id" name="loan_id">
            <div class="form-group">
                <label>Payment Date*</label>
                <input type="date" id="payment_date" name="payment_date" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="form-group">
                <label>Amount Paid (RWF)*</label>
                <input type="text" id="pay_amount" name="amount_paid" min="1" step="1" required>
            </div>
            <button type="submit" name="add_payment" class="btn btn-primary">Save Payment</button>
        </form>
    </div>
</div>

<script src="script.js"></script>
<script>
var _activeFilter = 'all';

function setFilter(btn) {
    document.querySelectorAll('.filter-status-btn').forEach(function(b) { b.classList.remove('active'); });
    btn.classList.add('active');
    _activeFilter = btn.getAttribute('data-status');
    filterTable();
}

function filterTable() {
    var term = (document.getElementById('loanSearch').value || '').trim().toLowerCase();
    var rows = document.querySelectorAll('#tbl-all-loans tbody tr');
    var visible = 0;
    rows.forEach(function(row) {
        var text   = row.textContent.toLowerCase();
        var status = row.getAttribute('data-status') || '';
        var matchText   = !term || text.indexOf(term) !== -1;
        var matchStatus = _activeFilter === 'all' || status === _activeFilter;
        var show = matchText && matchStatus;
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    var badge = document.getElementById('rowCount');
    if (badge) badge.textContent = (term || _activeFilter !== 'all') ? visible + ' match' + (visible !== 1 ? 'es' : '') : '';
}

function openPayment(btn) {
    var d = btn.dataset;
    document.getElementById('pay_loan_id').value = d.loanId;
    document.getElementById('pay_amount').value  = d.balance;
    document.getElementById('pay_amount').max    = d.balance;
    var info = document.getElementById('paymentInfo');
    info.innerHTML =
        '<span><strong>Client</strong>' + d.client + '</span>' +
        '<span><strong>Balance</strong>RWF ' + parseFloat(d.balance).toLocaleString() + '</span>';
    info.style.display = 'flex';
    document.getElementById('paymentAlert').style.display = 'none';
    document.getElementById('payment_date').value = new Date().toISOString().split('T')[0];
    openModal('paymentModal');
}

// Generic form submit helper
document.getElementById('paymentForm').addEventListener('submit', function(e) {
    e.preventDefault();
    var form = this;
    var btn  = form.querySelector('button[type="submit"]');
    var alertBox = document.getElementById('paymentAlert');
    var orig = btn.textContent;
    btn.disabled = true; btn.textContent = 'Saving...';
    alertBox.style.display = 'none';

    var data = new FormData(form);
    data.append('add_payment', '1');

    fetch('loans.php', { method: 'POST', body: data })
        .then(function(r) { return r.text(); })
        .then(function(raw) {
            var res;
            try { res = JSON.parse(raw); } catch(e) {
                alertBox.className = 'alert alert-danger';
                alertBox.textContent = 'Unexpected server response.';
                alertBox.style.display = 'block';
                btn.disabled = false; btn.textContent = orig;
                return;
            }
            if (res.success) {
                closeModal('paymentModal');
                location.reload();
            } else {
                alertBox.className = 'alert alert-danger';
                alertBox.textContent = res.message || 'An error occurred.';
                alertBox.style.display = 'block';
                btn.disabled = false; btn.textContent = orig;
            }
        })
        .catch(function() {
            alertBox.className = 'alert alert-danger';
            alertBox.textContent = 'Network error. Please try again.';
            alertBox.style.display = 'block';
            btn.disabled = false; btn.textContent = orig;
        });
});
</script>
</body>
</html>
