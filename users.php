<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

$is_super = isSuperAdmin();
$is_admin = ($_SESSION['role'] ?? '') === 'admin';
// Superadmin or a company admin (for users in their own company) can grant
// a user access to view another company's data, in addition to granting
// module permissions.
$can_manage_company_access = $is_super || $is_admin;

// Module definitions used by the permissions system
$perm_modules = [
    'inventory'    => 'Inventory (Products & Stock)',
    'stock_adjust' => 'Stock Adjustment',
    'purchases'    => 'Purchases',
    'sales'        => 'Sales',
    'expenses'     => 'Expenses',
    'loans'        => 'Loans',
    'orders'       => 'Orders',
    'reports'      => 'Reports',
    'losses'       => 'Losses',
    'consumption'  => 'Consumption',
    'notes'        => 'Notes',
    'audit_log'    => 'Audit Log',
    'financials'   => 'Financial Data (Costs & Profits)',
];

// If filtering by company (superadmin feature)
$filter_company_id = isset($_GET['company_id']) ? (int)$_GET['company_id'] : null;

// Fetch companies for superadmin's "assign home company" dropdown (full list —
// superadmin can put any user in any company).
$all_companies    = [];
$filter_companies = [];
$filter_company_name = '';
if ($is_super) {
    $res_c = mysqli_query($conn, "SELECT id, name FROM companies WHERE status='active' ORDER BY name");
    while ($row = mysqli_fetch_assoc($res_c)) $all_companies[] = $row;
}

// Companies a granting user can offer in the Company Access modal: superadmin
// can grant any active company; a plain admin can only grant companies THEY
// themselves can view (their own home company + whatever they were granted) —
// they can't hand out visibility into a company they have no relationship to.
$grantable_companies = $is_super ? $all_companies : getAccessibleCompanies($conn, (int)$_SESSION['user_id']);

if ($is_super) {
    $res_fc = mysqli_query($conn, "SELECT id, name FROM companies ORDER BY name");
    while ($row = mysqli_fetch_assoc($res_fc)) $filter_companies[] = $row;

    if ($filter_company_id) {
        $fcr = mysqli_fetch_assoc(mysqli_query($conn, "SELECT name FROM companies WHERE id=$filter_company_id"));
        $filter_company_name = $fcr['name'] ?? '';
    }
}

// Handle Create/Update/Delete/Toggle
if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    // Determine company_id for new/edited user
    if ($is_super) {
        $post_company_id = isset($_POST['company_id']) && $_POST['company_id'] !== ''
            ? (int)$_POST['company_id'] : null;
    } else {
        $post_company_id = $_SESSION['company_id'] ?? null;
    }

    // Add / Edit
    if (isset($_POST['modal_action']) && in_array($_POST['modal_action'], ['add_user','edit_user'])) {
        $username  = mysqli_real_escape_string($conn, $_POST['username']);
        $email     = mysqli_real_escape_string($conn, $_POST['email']);
        $full_name = mysqli_real_escape_string($conn, $_POST['full_name']);
        $role      = mysqli_real_escape_string($conn, $_POST['role']);
        $status    = isset($_POST['status']) ? mysqli_real_escape_string($conn, $_POST['status']) : 'active';
        $cid_sql   = $post_company_id !== null ? (int)$post_company_id : 'NULL';

        if ($_POST['modal_action'] === 'add_user') {
            $password     = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $check_result = mysqli_query($conn, "SELECT id FROM users WHERE username='$username' OR email='$email'");
            if (mysqli_num_rows($check_result) > 0) {
                $error = "Username or email already exists!";
            } else {
                $q = "INSERT INTO users (company_id,username,password,email,full_name,role,status,created_at)
                      VALUES ($cid_sql,'$username','$password','$email','$full_name','$role','$status',NOW())";
                if (mysqli_query($conn, $q)) {
                    $new_user_id = (int)mysqli_insert_id($conn);
                    $success = "User added successfully!";
                    if ($post_company_id !== null) {
                        grantCompanyAccess($conn, $new_user_id, $post_company_id, (int)$_SESSION['user_id']);
                    }
                    logActivity($conn, (int)$_SESSION['user_id'], 'Add User', "Added user: $username",
                        'users', $new_user_id, [],
                        ['username' => $username, 'email' => $_POST['email'], 'full_name' => $full_name, 'role' => $role, 'status' => $status]
                    );
                } else {
                    $error = "Error adding user: " . mysqli_error($conn);
                }
            }
        } else {
            $user_id      = (int)$_POST['user_id'];
            $old_user     = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id,username,email,full_name,role,status FROM users WHERE id=$user_id")) ?: [];
            $check_result = mysqli_query($conn, "SELECT id FROM users WHERE (username='$username' OR email='$email') AND id!=$user_id");
            if (mysqli_num_rows($check_result) > 0) {
                $error = "Username or email already exists!";
            } else {
                $q = "UPDATE users SET company_id=$cid_sql,username='$username',email='$email',full_name='$full_name',role='$role',status='$status' WHERE id=$user_id";
                if (!empty($_POST['password'])) {
                    $pw = password_hash($_POST['password'], PASSWORD_DEFAULT);
                    $q  = "UPDATE users SET company_id=$cid_sql,username='$username',password='$pw',email='$email',full_name='$full_name',role='$role',status='$status' WHERE id=$user_id";
                }
                if (mysqli_query($conn, $q)) {
                    $success = "User updated successfully!";
                    if ($post_company_id !== null) {
                        // Ensure the (possibly new) home company is accessible — doesn't touch
                        // any other grants the user already has.
                        grantCompanyAccess($conn, $user_id, $post_company_id, (int)$_SESSION['user_id']);
                    }
                    logActivity($conn, (int)$_SESSION['user_id'], 'Edit User', "Edited user: $username",
                        'users', $user_id, $old_user,
                        ['username' => $username, 'email' => $_POST['email'], 'full_name' => $full_name, 'role' => $role, 'status' => $status, 'password_changed' => !empty($_POST['password'])]
                    );
                } else {
                    $error = "Error updating user: " . mysqli_error($conn);
                }
            }
        }
    }

    // Delete
    if (isset($_POST['delete_user'])) {
        $user_id = (int)$_POST['user_id'];
        if ($user_id == $_SESSION['user_id']) {
            $error = "You cannot delete your own account!";
        } else {
            $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id,username,email,full_name,role,status FROM users WHERE id=$user_id")) ?: [];
            if (mysqli_query($conn, "DELETE FROM users WHERE id=$user_id")) {
                $success = "User deleted successfully!";
                logActivity($conn, (int)$_SESSION['user_id'], 'Delete User', "Deleted user: " . ($row['username'] ?? ''),
                    'users', $user_id, $row, []);
            } else {
                $error = "Error deleting user: " . mysqli_error($conn);
            }
        }
    }

    // Toggle status
    if (isset($_POST['toggle_status'])) {
        $user_id        = (int)$_POST['user_id'];
        $current_status = mysqli_real_escape_string($conn, $_POST['current_status']);
        $new_status     = $current_status == 'active' ? 'inactive' : 'active';
        if ($user_id == $_SESSION['user_id']) {
            $error = "You cannot change your own status!";
        } else {
            if (mysqli_query($conn, "UPDATE users SET status='$new_status' WHERE id=$user_id")) {
                $success = "User status updated to $new_status.";
                logActivity($conn, $_SESSION['user_id'], 'Toggle Status', "Changed user $user_id status to $new_status");
            } else {
                $error = "Error updating status: " . mysqli_error($conn);
            }
        }
    }

    // Save permissions for a specific user
    if (isset($_POST['modal_action']) && $_POST['modal_action'] === 'save_permissions') {
        $target_uid  = (int)$_POST['perm_user_id'];
        $target_user = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id, role, company_id FROM users WHERE id=$target_uid"));
        $can_set     = false;
        if ($target_user) {
            if ($is_super) {
                $can_set = true;
            } elseif ((int)($target_user['company_id'] ?? 0) === (int)($_SESSION['company_id'] ?? 0)) {
                $can_set = true;
            }
        }
        if ($can_set && $target_user) {
            $cid_val = $target_user['company_id'] !== null ? (int)$target_user['company_id'] : 'NULL';
            foreach ($perm_modules as $module => $label) {
                $v = isset($_POST["perm_{$module}_view"])   ? 1 : 0;
                $c = isset($_POST["perm_{$module}_create"]) ? 1 : 0;
                $e = isset($_POST["perm_{$module}_edit"])   ? 1 : 0;
                $d = isset($_POST["perm_{$module}_delete"]) ? 1 : 0;
                mysqli_query($conn, "INSERT INTO user_permissions (user_id, company_id, module, can_view, can_create, can_edit, can_delete)
                    VALUES ($target_uid, $cid_val, '$module', $v, $c, $e, $d)
                    ON DUPLICATE KEY UPDATE can_view=$v, can_create=$c, can_edit=$e, can_delete=$d");
            }
            $success = "Permissions updated successfully!";
            logActivity($conn, (int)$_SESSION['user_id'], 'Update Permissions',
                "Set permissions for user ID: $target_uid", 'user_permissions', $target_uid);
        } else {
            $error = "Unauthorized to set permissions for this user.";
        }
    }

    // Save which other companies a user is allowed to switch their view to
    if (isset($_POST['modal_action']) && $_POST['modal_action'] === 'save_company_access') {
        $target_uid  = (int)$_POST['access_user_id'];
        $target_user = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id, company_id FROM users WHERE id=$target_uid"));
        $can_set     = false;
        if ($target_user) {
            if ($is_super) {
                $can_set = true;
            } elseif ($is_admin && (int)($target_user['company_id'] ?? 0) === (int)(homeCid() ?? 0)) {
                $can_set = true;
            }
        }
        if ($can_set && $target_user) {
            $selected = isset($_POST['access_companies']) && is_array($_POST['access_companies'])
                ? array_map('intval', $_POST['access_companies']) : [];

            // A plain admin can only hand out companies THEY themselves can view —
            // never a company they have no relationship to, regardless of what the
            // request claims. Superadmin can grant any company. Home is no longer
            // special-cased here — it's just another row in user_company_access now,
            // so it goes through the same allow-list filtering as any other grant.
            if (!$is_super) {
                $grant_allowed = array_column(getAccessibleCompanies($conn, (int)$_SESSION['user_id']), 'id');
                $selected = array_intersect($selected, $grant_allowed);
            }

            if (empty($selected)) {
                $error = "Cannot save — this user must have access to at least one company.";
            } else {
                mysqli_query($conn, "DELETE FROM user_company_access WHERE user_id=$target_uid");
                foreach ($selected as $grant_cid) {
                    grantCompanyAccess($conn, $target_uid, (int)$grant_cid, (int)$_SESSION['user_id']);
                }
                $success = "Company access updated successfully!";
                logActivity($conn, (int)$_SESSION['user_id'], 'Update Company Access',
                    "Set company access for user ID: $target_uid", 'user_company_access', $target_uid);
            }
        } else {
            $error = "Unauthorized to set company access for this user.";
        }
    }

}

// Fetch users — superadmin sees all (optionally filtered), others see own company only
$all_users = [];
if ($is_super) {
    if ($filter_company_id) {
        $res = mysqli_query($conn, "SELECT u.*, c.name AS company_name FROM users u LEFT JOIN companies c ON c.id=u.company_id WHERE u.company_id=$filter_company_id ORDER BY u.created_at DESC");
    } else {
        $res = mysqli_query($conn, "SELECT u.*, c.name AS company_name FROM users u LEFT JOIN companies c ON c.id=u.company_id ORDER BY u.created_at DESC");
    }
} else {
    $my_company = (int)($_SESSION['company_id'] ?? 0);
    $res = mysqli_query($conn, "SELECT u.*, c.name AS company_name FROM users u LEFT JOIN companies c ON c.id=u.company_id WHERE u.company_id=$my_company ORDER BY u.created_at DESC");
}
while ($row = mysqli_fetch_assoc($res)) $all_users[] = $row;

// Pre-load permissions for all listed users (for the modal)
$all_user_perms = [];
if (!empty($all_users)) {
    $uids = implode(',', array_map('intval', array_column($all_users, 'id')));
    $pr   = mysqli_query($conn, "SELECT user_id, module, can_view, can_create, can_edit, can_delete FROM user_permissions WHERE user_id IN ($uids)");
    while ($row = mysqli_fetch_assoc($pr)) {
        $all_user_perms[$row['user_id']][$row['module']] = [
            'view'   => (bool)$row['can_view'],
            'create' => (bool)$row['can_create'],
            'edit'   => (bool)$row['can_edit'],
            'delete' => (bool)$row['can_delete'],
        ];
    }
}

// Pre-load company access grants for all listed users (for the modal)
$all_user_access = [];
if (!empty($all_users)) {
    $access_uids = implode(',', array_map('intval', array_column($all_users, 'id')));
    $ar = mysqli_query($conn, "SELECT user_id, company_id FROM user_company_access WHERE user_id IN ($access_uids)");
    while ($row = mysqli_fetch_assoc($ar)) {
        $all_user_access[$row['user_id']][] = (int)$row['company_id'];
    }
}

$total_users    = count($all_users);
$active_users   = count(array_filter($all_users, fn($u) => $u['status'] === 'active'));
$inactive_users = count(array_filter($all_users, fn($u) => $u['status'] === 'inactive'));
$admin_users    = count(array_filter($all_users, fn($u) => $u['role'] === 'admin'));

function avatarColor($name) {
    $colors = ['#3b82f6','#10b981','#f59e0b','#ef4444','#8b5cf6','#06b6d4','#ec4899','#14b8a6'];
    return $colors[ord($name[0]) % count($colors)];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - Small Stock Management</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/user.css">
</head>
<body>
<div class="dashboard-container">
    <?php include 'sidebar.php'; ?>

    <div class="main-content">

        <!-- Page Header -->
        <div class="dashboard-header">
            <div>
                <h1>User Management</h1>
                <p class="page-subtitle">Manage system accounts and permissions</p>
            </div>
            <div style="display:flex;align-items:center;gap:16px;">
                <div class="date-display"><strong><?php echo date('l, F j, Y'); ?></strong></div>
                <button class="btn btn-primary" onclick="openUserModal()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    New User
                </button>
            </div>
        </div>

        <!-- Alerts -->
        <?php if (isset($success)): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <!-- Stats Row -->
        <div class="um-stats-row">
            <div class="um-stat-card">
                <div class="um-stat-icon" style="background:#dbeafe;color:#2563eb;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                </div>
                <div class="um-stat-body">
                    <div class="um-stat-value"><?php echo $total_users; ?></div>
                    <div class="um-stat-label">Total Users</div>
                </div>
            </div>
            <div class="um-stat-card">
                <div class="um-stat-icon" style="background:#d1fae5;color:#059669;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                </div>
                <div class="um-stat-body">
                    <div class="um-stat-value"><?php echo $active_users; ?></div>
                    <div class="um-stat-label">Active</div>
                </div>
            </div>
            <div class="um-stat-card">
                <div class="um-stat-icon" style="background:#fee2e2;color:#dc2626;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="10" y1="15" x2="10" y2="9"/><line x1="14" y1="15" x2="14" y2="9"/></svg>
                </div>
                <div class="um-stat-body">
                    <div class="um-stat-value"><?php echo $inactive_users; ?></div>
                    <div class="um-stat-label">Inactive</div>
                </div>
            </div>
            <div class="um-stat-card">
                <div class="um-stat-icon" style="background:#ede9fe;color:#7c3aed;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                </div>
                <div class="um-stat-body">
                    <div class="um-stat-value"><?php echo $admin_users; ?></div>
                    <div class="um-stat-label">Admins</div>
                </div>
            </div>
        </div>

        <!-- Users Table (full width) -->
        <div class="user-table-container">
            <div class="user-table-header">
                <div class="uth-left">
                    <h2>
                        Users <span class="count-pill"><?php echo $total_users; ?> total</span>
                        <?php if ($is_super && $filter_company_name): ?>
                            <span style="font-size:13px;font-weight:500;color:var(--primary);margin-left:8px;">
                                — <?php echo htmlspecialchars($filter_company_name); ?>
                                <a href="users.php" style="font-size:11px;color:var(--gray-400);margin-left:6px;">&times; clear</a>
                            </span>
                        <?php endif; ?>
                    </h2>
                </div>
                <div class="table-actions">
                    <div class="search-box">
                        <svg class="search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <input type="text" id="txtSearchusersTable" placeholder="Search users…">
                    </div>
                    <?php if ($is_super): ?>
                    <select class="filter-dropdown" id="companyFilter" onchange="filterByCompany(this.value)">
                        <option value="">All Companies</option>
                        <?php foreach ($filter_companies as $fc): ?>
                            <option value="<?php echo $fc['id']; ?>"
                                <?php echo $filter_company_id == $fc['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($fc['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                    <select class="filter-dropdown" id="roleFilter" onchange="filterUsers()">
                        <option value="all">All Roles</option>
                        <?php if ($is_super): ?><option value="superadmin">Superadmin</option><?php endif; ?>
                        <option value="admin">Admin</option>
                        <option value="manager">Manager</option>
                        <option value="user">User</option>
                    </select>
                    <select class="filter-dropdown" id="statusFilter" onchange="filterUsers()">
                        <option value="all">All Status</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                        <option value="suspended">Suspended</option>
                    </select>
                </div>
            </div>

            <div class="table-responsive">
                    <table class="table" id="usersTable">
                        <thead>
                            <tr>
                                <th>User</th>
                                <?php if ($is_super): ?><th>Company</th><?php endif; ?>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Joined</th>
                                <th>Last Login</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($all_users as $user):
                            $initials = strtoupper(substr($user['full_name'], 0, 1));
                            if (strpos($user['full_name'], ' ') !== false) {
                                $parts    = explode(' ', $user['full_name']);
                                $initials = strtoupper($parts[0][0] . end($parts)[0]);
                            }
                            $av_color   = avatarColor($user['full_name']);
                            $search_val = strtolower($user['full_name'] . ' ' . $user['username'] . ' ' . $user['email']);
                            $data_attrs = 'data-id="'         . $user['id']                              . '"'
                                        . ' data-fullname="'  . htmlspecialchars($user['full_name'])  . '"'
                                        . ' data-username="'  . htmlspecialchars($user['username'])   . '"'
                                        . ' data-email="'     . htmlspecialchars($user['email'])      . '"'
                                        . ' data-role="'      . $user['role']                         . '"'
                                        . ' data-status="'    . $user['status']                       . '"'
                                        . ' data-companyid="' . ($user['company_id'] ?? '')           . '"';
                        ?>
                            <tr data-role="<?php echo $user['role']; ?>"
                                data-status="<?php echo $user['status']; ?>"
                                data-search="<?php echo htmlspecialchars($search_val); ?>">
                                <td>
                                    <div class="user-cell">
                                        <div class="user-avatar" style="background:<?php echo $av_color; ?>20;color:<?php echo $av_color; ?>;">
                                            <?php echo $initials; ?>
                                        </div>
                                        <div class="user-details">
                                            <span class="user-name"><?php echo htmlspecialchars($user['full_name']); ?></span>
                                            <span class="user-username">@<?php echo htmlspecialchars($user['username']); ?></span>
                                            <?php if ($user['email']): ?>
                                                <span class="user-email"><?php echo htmlspecialchars($user['email']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <?php if ($is_super): ?>
                                <td>
                                    <?php if ($user['company_name']): ?>
                                        <span style="font-size:13px;"><?php echo htmlspecialchars($user['company_name']); ?></span>
                                    <?php else: ?>
                                        <span style="color:var(--gray-400);font-size:13px;">—</span>
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
                                <td><span class="role-badge <?php echo $user['role']; ?>"><?php echo ucfirst($user['role']); ?></span></td>
                                <td><span class="status-badge <?php echo $user['status']; ?>"><?php echo ucfirst($user['status']); ?></span></td>
                                <td><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                                <td><?php echo $user['last_login'] ? date('M d, Y h:i A', strtotime($user['last_login'])) : '<span style="color:var(--gray-400);">—</span>'; ?></td>

                                <td>
                                    <div class="act-menu-wrap">
                                        <button class="act-btn" title="Actions" onclick="toggleActMenu(this)">⋮</button>
                                        <div class="act-menu">
                                            <button class="act-item" type="button" onclick="openEditModal(this);closeActMenus()" <?php echo $data_attrs; ?>><i class="fas fa-pen"></i> Edit</button>
                                            <?php if (in_array($user['role'], ['manager', 'user'])): ?>
                                            <button class="act-item" type="button" onclick="openPermModal(<?php echo $user['id']; ?>,'<?php echo addslashes(htmlspecialchars($user['full_name'])); ?>');closeActMenus()"><i class="fas fa-shield-alt"></i> Permissions</button>
                                            <?php endif; ?>
                                            <?php if ($can_manage_company_access && $user['role'] !== 'superadmin'): ?>
                                            <button class="act-item" type="button" onclick="openAccessModal(<?php echo $user['id']; ?>,'<?php echo addslashes(htmlspecialchars($user['full_name'])); ?>',<?php echo (int)($user['company_id'] ?? 0); ?>);closeActMenus()"><i class="fas fa-building"></i> Company Access</button>
                                            <?php endif; ?>
                                            <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                            <form method="POST">
                                                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                <input type="hidden" name="current_status" value="<?php echo $user['status']; ?>">
                                                <button type="submit" name="toggle_status" class="act-item"><i class="fas fa-<?php echo $user['status']=='active' ? 'ban' : 'check'; ?>"></i> <?php echo $user['status']=='active' ? 'Deactivate' : 'Activate'; ?></button>
                                            </form>
                                            <div class="act-menu-sep"></div>
                                            <form method="POST" onsubmit="return confirmDelete('<?php echo addslashes($user['full_name']); ?>')">
                                                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                <button type="submit" name="delete_user" class="act-item danger"><i class="fas fa-trash"></i> Delete</button>
                                            </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($all_users)): ?>
                            <tr><td colspan="<?php echo $is_super ? 7 : 6; ?>" style="text-align:center;color:var(--secondary);padding:32px;">No users found.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
        </div><!-- /user-table-container -->

    </div><!-- /main-content -->
</div><!-- /dashboard-container -->


<!-- ═══════════════════════════════════════════════════
     User Form Modal (New + Edit)
═══════════════════════════════════════════════════ -->
<div id="userModal" class="modal">
    <div class="modal-content modal-form">
        <div class="modal-header">
            <div class="modal-title-group">
                <div class="modal-form-icon" id="modalIcon">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                </div>
                <h3 id="modalTitle">New User</h3>
            </div>
            <button type="button" class="modal-close" onclick="closeUserModal()" aria-label="Close">&times;</button>
        </div>

        <form method="POST" action="" id="userForm" onsubmit="return validateUserForm()">
            <input type="hidden" name="modal_action" id="modalAction" value="add_user">
            <input type="hidden" name="user_id" id="modalUserId" value="">

            <div class="modal-body">
                <div class="form-group">
                    <label>Full Name <span class="req">*</span></label>
                    <input type="text" name="full_name" id="mFullName" required placeholder="John Doe">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Username <span class="req">*</span></label>
                        <input type="text" name="username" id="mUsername" required placeholder="johndoe">
                    </div>
                    <div class="form-group">
                        <label>Email <span class="req">*</span></label>
                        <input type="email" name="email" id="mEmail" required placeholder="john@example.com">
                    </div>
                </div>

                <div class="form-group">
                    <label id="pwLabel">Password <span class="req">*</span></label>
                    <div class="pw-wrap">
                        <input type="password" name="password" id="mPassword" placeholder="Min. 6 characters">
                        <button type="button" class="pw-toggle" onclick="togglePw()" title="Show/hide password">
                            <svg id="pwEye" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                    <p class="pw-hint" id="pwHint">Minimum 6 characters</p>
                </div>

                <?php if ($is_super): ?>
                <div class="form-group">
                    <label>Company</label>
                    <select name="company_id" id="mCompanyId">
                        <option value="">— No company (superadmin) —</option>
                        <?php foreach ($all_companies as $co): ?>
                            <option value="<?php echo $co['id']; ?>"><?php echo htmlspecialchars($co['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="form-row">
                    <div class="form-group">
                        <label>Role <span class="req">*</span></label>
                        <select name="role" id="mRole" required>
                            <option value="user">User</option>
                            <option value="manager">Manager</option>
                            <option value="admin">Admin</option>
                            <?php if ($is_super): ?>
                            <option value="superadmin">Superadmin</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" id="mStatus">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="suspended">Suspended</option>
                        </select>
                    </div>
                </div>
            </div><!-- /modal-body -->

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeUserModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" id="modalSubmitBtn">Create User</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Confirm Delete</h3>
            <button type="button" class="modal-close" onclick="closeDeleteModal()">&times;</button>
        </div>
        <div class="modal-body">
            <p>Are you sure you want to delete <strong id="deleteUserName"></strong>?</p>
            <p class="modal-warning">This action cannot be undone.</p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeDeleteModal()">Cancel</button>
            <button class="btn btn-danger" id="confirmDeleteBtn">Delete</button>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════
     Permissions Modal
═══════════════════════════════════════════════════ -->
<div id="permModal" class="modal">
    <div class="modal-content" style="max-width:640px;">
        <div class="modal-header">
            <div class="modal-title-group">
                <div class="modal-form-icon" style="background:#ecfdf5;color:#059669;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                </div>
                <h3>Permissions — <span id="permUserName"></span></h3>
            </div>
            <button type="button" class="modal-close" onclick="closePermModal()">&times;</button>
        </div>
        <form method="POST" action="" id="permForm">
            <input type="hidden" name="modal_action" value="save_permissions">
            <input type="hidden" name="perm_user_id" id="permUserId">
            <div class="modal-body" style="padding:0;">
                <table style="width:100%;border-collapse:collapse;font-size:13.5px;">
                    <thead>
                        <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                            <th style="padding:10px 16px;text-align:left;font-weight:600;color:#374151;">Module</th>
                            <th style="padding:10px 12px;text-align:center;font-weight:600;color:#374151;width:70px;">View</th>
                            <th style="padding:10px 12px;text-align:center;font-weight:600;color:#059669;width:70px;">Create</th>
                            <th style="padding:10px 12px;text-align:center;font-weight:600;color:#374151;width:70px;">Edit</th>
                            <th style="padding:10px 12px;text-align:center;font-weight:600;color:#374151;width:70px;">Delete</th>
                        </tr>
                    </thead>
                    <tbody id="permTableBody">
                        <?php foreach ($perm_modules as $module => $label): ?>
                        <?php
                            $no_create = in_array($module, ['audit_log', 'reports', 'financials']);
                            $no_edit   = in_array($module, ['audit_log', 'reports', 'financials']);
                            $no_delete = in_array($module, ['audit_log', 'reports', 'notes', 'financials']);
                        ?>
                        <tr class="perm-row" style="border-bottom:1px solid #f1f5f9;" data-module="<?php echo $module; ?>">
                            <td style="padding:9px 16px;color:#1e293b;font-weight:500;"><?php echo $label; ?></td>
                            <td style="text-align:center;padding:9px 12px;">
                                <input type="checkbox" name="perm_<?php echo $module; ?>_view"
                                    class="perm-cb perm-view" data-module="<?php echo $module; ?>">
                            </td>
                            <td style="text-align:center;padding:9px 12px;">
                                <?php if (!$no_create): ?>
                                <input type="checkbox" name="perm_<?php echo $module; ?>_create"
                                    class="perm-cb perm-create" data-module="<?php echo $module; ?>">
                                <?php else: ?>
                                <span style="color:#cbd5e1;">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:center;padding:9px 12px;">
                                <?php if (!$no_edit): ?>
                                <input type="checkbox" name="perm_<?php echo $module; ?>_edit"
                                    class="perm-cb perm-edit" data-module="<?php echo $module; ?>">
                                <?php else: ?>
                                <span style="color:#cbd5e1;">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:center;padding:9px 12px;">
                                <?php if (!$no_delete): ?>
                                <input type="checkbox" name="perm_<?php echo $module; ?>_delete"
                                    class="perm-cb perm-delete" data-module="<?php echo $module; ?>">
                                <?php else: ?>
                                <span style="color:#cbd5e1;">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div style="padding:10px 16px;background:#f8fafc;border-top:1px solid #e2e8f0;display:flex;gap:12px;align-items:center;">
                    <label style="font-size:12.5px;font-weight:600;color:#475569;cursor:pointer;display:flex;align-items:center;gap:6px;">
                        <input type="checkbox" id="selectAllView" onchange="toggleAllPerms('view',this.checked)"> All View
                    </label>
                    <label style="font-size:12.5px;font-weight:600;color:#059669;cursor:pointer;display:flex;align-items:center;gap:6px;">
                        <input type="checkbox" id="selectAllCreate" onchange="toggleAllPerms('create',this.checked)"> All Create
                    </label>
                    <label style="font-size:12.5px;font-weight:600;color:#475569;cursor:pointer;display:flex;align-items:center;gap:6px;">
                        <input type="checkbox" id="selectAllEdit" onchange="toggleAllPerms('edit',this.checked)"> All Edit
                    </label>
                    <label style="font-size:12.5px;font-weight:600;color:#475569;cursor:pointer;display:flex;align-items:center;gap:6px;">
                        <input type="checkbox" id="selectAllDelete" onchange="toggleAllPerms('delete',this.checked)"> All Delete
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closePermModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Permissions</button>
            </div>
        </form>
    </div>
</div>


<?php if ($can_manage_company_access): ?>
<!-- ═══════════════════════════════════════════════════
     Company Access Modal
═══════════════════════════════════════════════════ -->
<div id="accessModal" class="modal">
    <div class="modal-content" style="max-width:420px;">
        <div class="modal-header">
            <div class="modal-title-group">
                <div class="modal-form-icon" style="background:#eff6ff;color:#2563eb;">
                    <i class="fas fa-building"></i>
                </div>
                <h3>Company Access — <span id="accessUserName"></span></h3>
            </div>
            <button type="button" class="modal-close" onclick="closeAccessModal()">&times;</button>
        </div>
        <form method="POST" action="" id="accessForm" onsubmit="return validateAccessForm()">
            <input type="hidden" name="modal_action" value="save_company_access">
            <input type="hidden" name="access_user_id" id="accessUserId">
            <div class="modal-body">
                <p style="font-size:12.5px;color:#64748b;margin-bottom:12px;">
                    Choose which companies this user can switch to viewing — including their own. At least one must stay checked.
                </p>
                <?php foreach ($grantable_companies as $co): ?>
                <label class="access-co-row" data-company-id="<?php echo $co['id']; ?>" style="display:flex;align-items:center;gap:8px;padding:8px 4px;font-size:13.5px;cursor:pointer;">
                    <input type="checkbox" name="access_companies[]" value="<?php echo $co['id']; ?>" class="access-cb">
                    <span><?php echo htmlspecialchars($co['name']); ?></span>
                    <span class="access-home-tag" style="display:none;font-size:11px;color:#94a3b8;">(home company)</span>
                </label>
                <?php endforeach; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeAccessModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Access</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
// permissions data pre-loaded from PHP
const allUserPerms = <?php echo json_encode($all_user_perms); ?>;
const allUserAccess = <?php echo json_encode($all_user_access); ?>;
</script>

<script src="script.js"></script>
<script>
// ── User Modal ──────────────────────────────────────
function openUserModal() {
    setModalMode('add');
    document.getElementById('userModal').classList.add('is-open');
    document.getElementById('mFullName').focus();
}

function openEditModal(btn) {
    setModalMode('edit');
    document.getElementById('modalUserId').value = btn.dataset.id;
    document.getElementById('mFullName').value   = btn.dataset.fullname;
    document.getElementById('mUsername').value   = btn.dataset.username;
    document.getElementById('mEmail').value      = btn.dataset.email;
    document.getElementById('mRole').value       = btn.dataset.role;
    document.getElementById('mStatus').value     = btn.dataset.status;
    const cmp = document.getElementById('mCompanyId');
    if (cmp) cmp.value = btn.dataset.companyid || '';
    document.getElementById('userModal').classList.add('is-open');
    document.getElementById('mFullName').focus();
}

function closeUserModal() {
    document.getElementById('userModal').classList.remove('is-open');
    document.getElementById('userForm').reset();
    document.getElementById('modalUserId').value = '';
}

function setModalMode(mode) {
    const isEdit = mode === 'edit';
    document.getElementById('modalAction').value   = isEdit ? 'edit_user' : 'add_user';
    document.getElementById('modalTitle').textContent = isEdit ? 'Edit User' : 'New User';
    document.getElementById('modalSubmitBtn').textContent = isEdit ? 'Update User' : 'Create User';

    const pwInput = document.getElementById('mPassword');
    const pwLabel = document.getElementById('pwLabel');
    const pwHint  = document.getElementById('pwHint');
    if (isEdit) {
        pwInput.required    = false;
        pwLabel.innerHTML   = 'New Password';
        pwInput.placeholder = 'Leave blank to keep current';
        pwHint.textContent  = 'Only fill to change the password';
    } else {
        pwInput.required    = true;
        pwLabel.innerHTML   = 'Password <span class="req">*</span>';
        pwInput.placeholder = 'Min. 6 characters';
        pwHint.textContent  = 'Minimum 6 characters';
    }

    // icon colour
    const icon = document.getElementById('modalIcon');
    icon.style.background = isEdit ? '#fef3c7' : '#dbeafe';
    icon.style.color      = isEdit ? '#d97706'  : '#2563eb';
    icon.innerHTML = isEdit
        ? '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>'
        : '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>';
}

// close on backdrop click
document.getElementById('userModal').addEventListener('click', function(e) {
    if (e.target === this) closeUserModal();
});

// password show/hide
function togglePw() {
    const inp = document.getElementById('mPassword');
    inp.type = inp.type === 'password' ? 'text' : 'password';
}

// ── Form validation ─────────────────────────────────
function validateUserForm() {
    const username = document.getElementById('mUsername').value.trim();
    const fullName = document.getElementById('mFullName').value.trim();
    const password = document.getElementById('mPassword').value;
    const isEdit   = document.getElementById('modalAction').value === 'edit_user';

    if (username.length < 3) { showToast('Username must be at least 3 characters', 'error'); return false; }
    if (fullName.length < 2) { showToast('Please enter a valid full name', 'error');          return false; }
    if (!isEdit && password.length < 6) { showToast('Password must be at least 6 characters', 'error'); return false; }
    return true;
}

// ── Search + Filter ──────────────────────────────────
function applyListFilter() {
    const term         = document.getElementById('txtSearchusersTable').value.toLowerCase().trim();
    const roleFilter   = document.getElementById('roleFilter').value;
    const statusFilter = document.getElementById('statusFilter').value;
    document.querySelectorAll('#usersTable tbody tr[data-search]').forEach(row => {
        const matchSearch = !term || row.dataset.search.includes(term);
        const matchRole   = roleFilter   === 'all' || row.dataset.role   === roleFilter;
        const matchStatus = statusFilter === 'all' || row.dataset.status === statusFilter;
        row.style.display = (matchSearch && matchRole && matchStatus) ? '' : 'none';
    });
}
function filterUsers() { applyListFilter(); }

function filterByCompany(company_id) {
    const url = new URL(window.location.href);
    if (company_id) {
        url.searchParams.set('company_id', company_id);
    } else {
        url.searchParams.delete('company_id');
    }
    window.location.href = url.toString();
}

document.getElementById('txtSearchusersTable')
    .addEventListener('input', applyListFilter);

// ── Delete modal ────────────────────────────────────
let pendingDeleteForm = null;
function confirmDelete(userName) {
    document.getElementById('deleteUserName').textContent = userName;
    document.getElementById('deleteModal').classList.add('is-open');
    pendingDeleteForm = event.target.closest('form');
    return false;
}
function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('is-open');
    pendingDeleteForm = null;
}
document.getElementById('confirmDeleteBtn').addEventListener('click', () => {
    if (pendingDeleteForm) pendingDeleteForm.submit();
});
document.getElementById('deleteModal').addEventListener('click', function(e) {
    if (e.target === this) closeDeleteModal();
});

// ── Toast ───────────────────────────────────────────
function showToast(message, type = 'success') {
    const t = document.createElement('div');
    t.className = `toast ${type}`;
    t.textContent = message;
    document.body.appendChild(t);
    setTimeout(() => { t.style.opacity='0'; t.style.transform='translateX(110%)'; setTimeout(() => t.remove(), 300); }, 3000);
}

// ── Init ────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.alert').forEach(a => {
        setTimeout(() => { a.style.opacity='0'; setTimeout(() => a.style.display='none', 300); }, 5000);
    });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') { closeUserModal(); closeDeleteModal(); closePermModal(); closeAccessModal(); }
    });
});

// ── Permissions Modal ───────────────────────────────
function openPermModal(userId, userName) {
    document.getElementById('permUserId').value   = userId;
    document.getElementById('permUserName').textContent = userName;

    // Reset all checkboxes
    document.querySelectorAll('.perm-cb').forEach(cb => cb.checked = false);
    document.getElementById('selectAllView').checked   = false;
    document.getElementById('selectAllCreate').checked = false;
    document.getElementById('selectAllEdit').checked   = false;
    document.getElementById('selectAllDelete').checked = false;

    // Populate from pre-loaded data
    const perms = allUserPerms[userId] || {};
    Object.entries(perms).forEach(([module, flags]) => {
        const row = document.querySelector(`.perm-row[data-module="${module}"]`);
        if (!row) return;
        if (flags.view)   { const cb = row.querySelector('.perm-view');   if (cb) cb.checked = true; }
        if (flags.create) { const cb = row.querySelector('.perm-create'); if (cb) cb.checked = true; }
        if (flags.edit)   { const cb = row.querySelector('.perm-edit');   if (cb) cb.checked = true; }
        if (flags.delete) { const cb = row.querySelector('.perm-delete'); if (cb) cb.checked = true; }
    });

    document.getElementById('permModal').classList.add('is-open');
}

function closePermModal() {
    document.getElementById('permModal').classList.remove('is-open');
}

document.getElementById('permModal').addEventListener('click', function(e) {
    if (e.target === this) closePermModal();
});

function toggleAllPerms(type, checked) {
    document.querySelectorAll('.perm-' + type).forEach(cb => { cb.checked = checked; });
}

// ── Company Access Modal ────────────────────────────
function openAccessModal(userId, userName, homeCompanyId) {
    document.getElementById('accessUserId').value   = userId;
    document.getElementById('accessUserName').textContent = userName;

    // Home is just a normal, revocable grant now — checked/unchecked purely based
    // on what's actually in user_company_access, same as any other company. The
    // "(home company)" tag is an informational label only, not a lock.
    const granted = allUserAccess[userId] || [];
    document.querySelectorAll('.access-co-row').forEach(row => {
        const cid = parseInt(row.dataset.companyId, 10);
        const cb  = row.querySelector('.access-cb');
        const tag = row.querySelector('.access-home-tag');
        cb.checked = granted.includes(cid);
        tag.style.display = (cid === homeCompanyId) ? 'inline' : 'none';
    });

    document.getElementById('accessModal').classList.add('is-open');
}

function validateAccessForm() {
    const anyChecked = Array.from(document.querySelectorAll('.access-cb')).some(cb => cb.checked);
    if (!anyChecked) {
        showToast('Select at least one company for this user to view.', 'error');
        return false;
    }
    return true;
}

function closeAccessModal() {
    document.getElementById('accessModal').classList.remove('is-open');
}

const accessModalEl = document.getElementById('accessModal');
if (accessModalEl) {
    accessModalEl.addEventListener('click', function(e) {
        if (e.target === this) closeAccessModal();
    });
}
</script>
</body>
</html>
