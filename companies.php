<?php
require_once 'config.php';

if (!isLoggedIn()) redirect('login.php');
if (!isSuperAdmin()) redirect('dashboard.php');

// Handle CRUD
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['modal_action']) && in_array($_POST['modal_action'], ['add_company', 'edit_company'])) {
        $name    = mysqli_real_escape_string($conn, trim($_POST['name']));
        $email   = mysqli_real_escape_string($conn, trim($_POST['email'] ?? ''));
        $phone   = mysqli_real_escape_string($conn, trim($_POST['phone'] ?? ''));
        $address = mysqli_real_escape_string($conn, trim($_POST['address'] ?? ''));
        $status  = in_array($_POST['status'] ?? '', ['active','inactive']) ? $_POST['status'] : 'active';

        if (empty($name)) {
            $error = "Company name is required.";
        } elseif ($_POST['modal_action'] === 'add_company') {
            $check = mysqli_query($conn, "SELECT id FROM companies WHERE name='$name'");
            if (mysqli_num_rows($check) > 0) {
                $error = "A company with that name already exists.";
            } else {
                $q = "INSERT INTO companies (name,email,phone,address,status,created_at)
                      VALUES ('$name','$email','$phone','$address','$status',NOW())";
                if (mysqli_query($conn, $q)) {
                    $success = "Company added successfully!";
                } else {
                    $error = "Error: " . mysqli_error($conn);
                }
            }
        } else {
            $company_id = (int)$_POST['company_id'];
            $check = mysqli_query($conn, "SELECT id FROM companies WHERE name='$name' AND id!=$company_id");
            if (mysqli_num_rows($check) > 0) {
                $error = "Another company with that name already exists.";
            } else {
                $q = "UPDATE companies SET name='$name',email='$email',phone='$phone',address='$address',status='$status' WHERE id=$company_id";
                if (mysqli_query($conn, $q)) {
                    $success = "Company updated successfully!";
                } else {
                    $error = "Error: " . mysqli_error($conn);
                }
            }
        }
    }

    if (isset($_POST['delete_company'])) {
        $company_id = (int)$_POST['company_id'];
        $users_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM users WHERE company_id=$company_id"))['cnt'];
        if ($users_count > 0) {
            $error = "Cannot delete: this company has $users_count user(s) assigned to it.";
        } else {
            if (mysqli_query($conn, "DELETE FROM companies WHERE id=$company_id")) {
                $success = "Company deleted.";
            } else {
                $error = "Error: " . mysqli_error($conn);
            }
        }
    }

    if (isset($_POST['toggle_status'])) {
        $company_id     = (int)$_POST['company_id'];
        $current_status = $_POST['current_status'] === 'active' ? 'active' : 'inactive';
        $new_status     = $current_status === 'active' ? 'inactive' : 'active';
        mysqli_query($conn, "UPDATE companies SET status='$new_status' WHERE id=$company_id");
        $success = "Status updated to $new_status.";
    }
}

// Fetch companies with user counts
$companies = [];
$res = mysqli_query($conn, "
    SELECT c.*, COUNT(u.id) AS user_count
    FROM companies c
    LEFT JOIN users u ON u.company_id = c.id
    GROUP BY c.id
    ORDER BY c.created_at DESC
");
while ($row = mysqli_fetch_assoc($res)) $companies[] = $row;

$total    = count($companies);
$active   = count(array_filter($companies, fn($c) => $c['status'] === 'active'));
$inactive = $total - $active;

function companyColor($name) {
    $colors = ['#3b82f6','#10b981','#f59e0b','#ef4444','#8b5cf6','#06b6d4','#ec4899','#14b8a6'];
    return $colors[ord($name[0]) % count($colors)];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Companies - Screen Stock</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/user.css">
</head>
<body>
<div class="dashboard-container">
    <?php include 'sidebar.php'; ?>

    <div class="main-content">

        <div class="dashboard-header">
            <div>
                <h1>Companies</h1>
                <p class="page-subtitle">Manage registered companies on this platform</p>
            </div>
            <div style="display:flex;align-items:center;gap:16px;">
                <div class="date-display"><strong><?php echo date('l, F j, Y'); ?></strong></div>
                <button class="btn btn-primary" onclick="openModal()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    New Company
                </button>
            </div>
        </div>

        <?php if (isset($success)): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="um-stats-row">
            <div class="um-stat-card">
                <div class="um-stat-icon" style="background:#dbeafe;color:#2563eb;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 3H8a2 2 0 0 0-2 2v2h12V5a2 2 0 0 0-2-2Z"/></svg>
                </div>
                <div class="um-stat-body">
                    <div class="um-stat-value"><?php echo $total; ?></div>
                    <div class="um-stat-label">Total Companies</div>
                </div>
            </div>
            <div class="um-stat-card">
                <div class="um-stat-icon" style="background:#d1fae5;color:#059669;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                </div>
                <div class="um-stat-body">
                    <div class="um-stat-value"><?php echo $active; ?></div>
                    <div class="um-stat-label">Active</div>
                </div>
            </div>
            <div class="um-stat-card">
                <div class="um-stat-icon" style="background:#fee2e2;color:#dc2626;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="10" y1="15" x2="10" y2="9"/><line x1="14" y1="15" x2="14" y2="9"/></svg>
                </div>
                <div class="um-stat-body">
                    <div class="um-stat-value"><?php echo $inactive; ?></div>
                    <div class="um-stat-label">Inactive</div>
                </div>
            </div>
        </div>

        <!-- Table -->
        <div class="user-table-container">
            <div class="user-table-header">
                <div class="uth-left">
                    <h2>Companies <span class="count-pill"><?php echo $total; ?> total</span></h2>
                </div>
                <div class="table-actions">
                    <div class="search-box">
                        <svg class="search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <input type="text" id="txtSearch" placeholder="Search companies…">
                    </div>
                    <select class="filter-dropdown" id="statusFilter" onchange="applyFilter()">
                        <option value="all">All Status</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table" id="companiesTable">
                    <thead>
                        <tr>
                            <th>Company</th>
                            <th>Contact</th>
                            <th>Users</th>
                            <th>Status</th>
                            <th>Registered</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($companies as $c):
                        $color = companyColor($c['name']);
                        $initial = strtoupper($c['name'][0]);
                        $search_val = strtolower($c['name'] . ' ' . $c['email'] . ' ' . $c['phone']);
                        $data = 'data-id="'      . $c['id']                        . '"'
                              . ' data-name="'   . htmlspecialchars($c['name'])    . '"'
                              . ' data-email="'  . htmlspecialchars($c['email'] ?? '') . '"'
                              . ' data-phone="'  . htmlspecialchars($c['phone'] ?? '') . '"'
                              . ' data-address="'. htmlspecialchars($c['address'] ?? '') . '"'
                              . ' data-status="' . $c['status']                    . '"';
                    ?>
                        <tr data-status="<?php echo $c['status']; ?>"
                            data-search="<?php echo htmlspecialchars($search_val); ?>">
                            <td>
                                <div class="user-cell">
                                    <div class="user-avatar" style="background:<?php echo $color; ?>20;color:<?php echo $color; ?>;">
                                        <?php echo $initial; ?>
                                    </div>
                                    <div class="user-details">
                                        <span class="user-name"><?php echo htmlspecialchars($c['name']); ?></span>
                                        <?php if ($c['address']): ?>
                                            <span class="user-email"><?php echo htmlspecialchars($c['address']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div style="font-size:13px;">
                                    <?php if ($c['email']): ?>
                                        <div><?php echo htmlspecialchars($c['email']); ?></div>
                                    <?php endif; ?>
                                    <?php if ($c['phone']): ?>
                                        <div style="color:var(--gray-500);"><?php echo htmlspecialchars($c['phone']); ?></div>
                                    <?php endif; ?>
                                    <?php if (!$c['email'] && !$c['phone']): ?>
                                        <span style="color:var(--gray-400);">—</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <span style="font-weight:600;"><?php echo $c['user_count']; ?></span>
                                <a href="users.php?company_id=<?php echo $c['id']; ?>" style="font-size:12px;color:var(--primary);margin-left:6px;">view</a>
                            </td>
                            <td><span class="status-badge <?php echo $c['status']; ?>"><?php echo ucfirst($c['status']); ?></span></td>
                            <td><?php echo date('M d, Y', strtotime($c['created_at'])); ?></td>
                            <td>
                                <div class="act-menu-wrap">
                                    <button class="act-btn" title="Actions" onclick="toggleActMenu(this)"><i class="fas fa-ellipsis-v"></i></button>
                                    <div class="act-menu">
                                        <button class="act-item" type="button" onclick="openEditModal(this);closeActMenus()" <?php echo $data; ?>><i class="fas fa-pen"></i> Edit</button>
                                        <form method="POST">
                                            <input type="hidden" name="company_id" value="<?php echo $c['id']; ?>">
                                            <input type="hidden" name="current_status" value="<?php echo $c['status']; ?>">
                                            <button type="submit" name="toggle_status" class="act-item"><i class="fas fa-<?php echo $c['status'] === 'active' ? 'ban' : 'check'; ?>"></i> <?php echo $c['status'] === 'active' ? 'Deactivate' : 'Activate'; ?></button>
                                        </form>
                                        <div class="act-menu-sep"></div>
                                        <form method="POST" onsubmit="return confirm('Delete <?php echo addslashes($c['name']); ?>?')">
                                            <input type="hidden" name="company_id" value="<?php echo $c['id']; ?>">
                                            <button type="submit" name="delete_company" class="act-item danger"><i class="fas fa-trash"></i> Delete</button>
                                        </form>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($companies)): ?>
                        <tr><td colspan="6" style="text-align:center;color:var(--secondary);padding:32px;">No companies registered yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<!-- Company Form Modal -->
<div id="companyModal" class="modal">
    <div class="modal-content modal-form">
        <div class="modal-header">
            <div class="modal-title-group">
                <div class="modal-form-icon" id="modalIcon" style="background:#dbeafe;color:#2563eb;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                </div>
                <h3 id="modalTitle">New Company</h3>
            </div>
            <button type="button" class="modal-close" onclick="closeModal()">&times;</button>
        </div>

        <form method="POST" id="companyForm" onsubmit="return validateForm()">
            <input type="hidden" name="modal_action" id="modalAction" value="add_company">
            <input type="hidden" name="company_id"   id="modalId"     value="">

            <div class="modal-body">
                <div class="form-group">
                    <label>Company Name <span class="req">*</span></label>
                    <input type="text" name="name" id="mName" required placeholder="Acme Ltd.">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="email" id="mEmail" placeholder="info@company.com">
                    </div>
                    <div class="form-group">
                        <label>Phone</label>
                        <input type="text" name="phone" id="mPhone" placeholder="+250 700 000 000">
                    </div>
                </div>
                <div class="form-group">
                    <label>Address</label>
                    <input type="text" name="address" id="mAddress" placeholder="City, Country">
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" id="mStatus">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" id="modalSubmitBtn">Create Company</button>
            </div>
        </form>
    </div>
</div>

<script src="script.js"></script>
<script>
function openModal() {
    document.getElementById('modalAction').value = 'add_company';
    document.getElementById('modalTitle').textContent = 'New Company';
    document.getElementById('modalSubmitBtn').textContent = 'Create Company';
    document.getElementById('modalIcon').style.background = '#dbeafe';
    document.getElementById('modalIcon').style.color = '#2563eb';
    document.getElementById('companyForm').reset();
    document.getElementById('modalId').value = '';
    document.getElementById('companyModal').classList.add('is-open');
    document.getElementById('mName').focus();
}

function openEditModal(btn) {
    document.getElementById('modalAction').value = 'edit_company';
    document.getElementById('modalTitle').textContent = 'Edit Company';
    document.getElementById('modalSubmitBtn').textContent = 'Update Company';
    document.getElementById('modalIcon').style.background = '#fef3c7';
    document.getElementById('modalIcon').style.color = '#d97706';
    document.getElementById('modalId').value   = btn.dataset.id;
    document.getElementById('mName').value     = btn.dataset.name;
    document.getElementById('mEmail').value    = btn.dataset.email;
    document.getElementById('mPhone').value    = btn.dataset.phone;
    document.getElementById('mAddress').value  = btn.dataset.address;
    document.getElementById('mStatus').value   = btn.dataset.status;
    document.getElementById('companyModal').classList.add('is-open');
    document.getElementById('mName').focus();
}

function closeModal() {
    document.getElementById('companyModal').classList.remove('is-open');
}

document.getElementById('companyModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

function validateForm() {
    if (document.getElementById('mName').value.trim().length < 2) {
        alert('Company name must be at least 2 characters.');
        return false;
    }
    return true;
}

function applyFilter() {
    const term   = document.getElementById('txtSearch').value.toLowerCase().trim();
    const status = document.getElementById('statusFilter').value;
    document.querySelectorAll('#companiesTable tbody tr[data-search]').forEach(row => {
        const matchSearch = !term   || row.dataset.search.includes(term);
        const matchStatus = status === 'all' || row.dataset.status === status;
        row.style.display = (matchSearch && matchStatus) ? '' : 'none';
    });
}

document.getElementById('txtSearch').addEventListener('input', applyFilter);

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.alert').forEach(a => {
        setTimeout(() => { a.style.opacity = '0'; setTimeout(() => a.style.display = 'none', 300); }, 5000);
    });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });
});
</script>
</body>
</html>
