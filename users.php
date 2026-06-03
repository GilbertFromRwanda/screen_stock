<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

$is_super = isSuperAdmin();

// If filtering by company (superadmin feature)
$filter_company_id = isset($_GET['company_id']) ? (int)$_GET['company_id'] : null;

// Fetch companies for superadmin dropdowns
$all_companies    = [];
$filter_companies = [];
$filter_company_name = '';
if ($is_super) {
    $res_c = mysqli_query($conn, "SELECT id, name FROM companies WHERE status='active' ORDER BY name");
    while ($row = mysqli_fetch_assoc($res_c)) $all_companies[] = $row;

    $res_fc = mysqli_query($conn, "SELECT id, name FROM companies ORDER BY name");
    while ($row = mysqli_fetch_assoc($res_fc)) $filter_companies[] = $row;

    if ($filter_company_id) {
        $fcr = mysqli_fetch_assoc(mysqli_query($conn, "SELECT name FROM companies WHERE id=$filter_company_id"));
        $filter_company_name = $fcr['name'] ?? '';
    }
}

// Handle Create/Update/Delete/Toggle/Bulk
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
                    $success = "User added successfully!";
                    logActivity($conn, $_SESSION['user_id'], 'Add User', "Added user: $username");
                } else {
                    $error = "Error adding user: " . mysqli_error($conn);
                }
            }
        } else {
            $user_id      = (int)$_POST['user_id'];
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
                    logActivity($conn, $_SESSION['user_id'], 'Edit User', "Edited user: $username");
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
            $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT username FROM users WHERE id=$user_id"));
            if (mysqli_query($conn, "DELETE FROM users WHERE id=$user_id")) {
                $success = "User deleted successfully!";
                logActivity($conn, $_SESSION['user_id'], 'Delete User', "Deleted user: " . $row['username']);
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

    // Bulk actions
    if (isset($_POST['bulk_action'])) {
        $action   = $_POST['bulk_action'];
        $user_ids = $_POST['user_ids'] ?? [];
        if (!empty($user_ids)) {
            $ids_arr    = array_diff(array_map('intval', $user_ids), [(int)$_SESSION['user_id']]);
            $ids_string = implode(',', $ids_arr);
            if (empty($ids_string)) {
                $error = "Cannot perform action on your own account!";
            } else {
                $q = match($action) {
                    'delete'     => "DELETE FROM users WHERE id IN ($ids_string)",
                    'activate'   => "UPDATE users SET status='active' WHERE id IN ($ids_string)",
                    'deactivate' => "UPDATE users SET status='inactive' WHERE id IN ($ids_string)",
                    default      => null
                };
                if ($q && mysqli_query($conn, $q)) {
                    $success = "Bulk action completed.";
                    logActivity($conn, $_SESSION['user_id'], 'Bulk Action', "$action on IDs: $ids_string");
                } elseif ($q) {
                    $error = "Error: " . mysqli_error($conn);
                }
            }
        } else {
            $error = "No users selected!";
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

$total_users    = count($all_users);
$active_users   = count(array_filter($all_users, fn($u) => $u['status'] === 'active'));
$inactive_users = count(array_filter($all_users, fn($u) => $u['status'] === 'inactive'));
$admin_users    = count(array_filter($all_users, fn($u) => $u['role'] === 'admin'));

function avatarColor($name) {
    $colors = ['#3b82f6','#10b981','#f59e0b','#ef4444','#8b5cf6','#06b6d4','#ec4899','#14b8a6'];
    return $colors[ord($name[0]) % count($colors)];
}

function logActivity(mysqli $_conn, int $_user_id, string $_action, string $_description): void {}
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

            <form method="POST" action="" id="bulkActionForm">
                <div class="table-responsive">
                    <table class="table" id="usersTable">
                        <thead>
                            <tr>
                                <th width="40"><input type="checkbox" id="selectAll" onclick="toggleSelectAll()"></th>
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
                                    <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                        <input type="checkbox" name="user_ids[]" value="<?php echo $user['id']; ?>" class="user-checkbox">
                                    <?php else: ?>
                                        <span class="self-badge">You</span>
                                    <?php endif; ?>
                                </td>
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
                                <td><?php echo $user['last_login'] ? date('M d, Y', strtotime($user['last_login'])) : '<span style="color:var(--gray-400);">—</span>'; ?></td>
                                <td>
                                    <div class="act-menu-wrap">
                                        <button class="act-btn" title="Actions" onclick="toggleActMenu(this)"><i class="fas fa-ellipsis-v"></i></button>
                                        <div class="act-menu">
                                            <button class="act-item" type="button" onclick="openEditModal(this);closeActMenus()" <?php echo $data_attrs; ?>><i class="fas fa-pen"></i> Edit</button>
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
                            <tr><td colspan="<?php echo $is_super ? 8 : 7; ?>" style="text-align:center;color:var(--secondary);padding:32px;">No users found.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Bulk Actions -->
                <div class="bulk-actions">
                    <label class="select-all">
                        <input type="checkbox" id="selectAllBottom" onclick="toggleSelectAll()">
                        Select All
                    </label>
                    <select name="bulk_action" id="bulkAction" class="filter-dropdown">
                        <option value="">Bulk Actions</option>
                        <option value="activate">Activate Selected</option>
                        <option value="deactivate">Deactivate Selected</option>
                        <option value="delete">Delete Selected</option>
                    </select>
                    <button type="submit" name="bulk_action_submit" class="btn btn-primary btn-sm"
                            onclick="return confirmBulkAction()">Apply</button>
                </div>
            </form>
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

// ── Select all ──────────────────────────────────────
function toggleSelectAll() {
    const top     = document.getElementById('selectAll');
    const bottom  = document.getElementById('selectAllBottom');
    const checked = top.checked || bottom.checked;
    document.querySelectorAll('.user-checkbox').forEach(cb => cb.checked = checked);
    top.checked = bottom.checked = checked;
}
// keep header checkbox in sync when individual boxes change
document.addEventListener('change', function(e) {
    if (!e.target.classList.contains('user-checkbox')) return;
    const all  = document.querySelectorAll('.user-checkbox');
    const chkd = document.querySelectorAll('.user-checkbox:checked');
    const allChecked = all.length === chkd.length;
    document.getElementById('selectAll').checked        = allChecked;
    document.getElementById('selectAllBottom').checked  = allChecked;
});

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

// ── Bulk actions ────────────────────────────────────
function confirmBulkAction() {
    const action    = document.getElementById('bulkAction').value;
    const checkboxes = document.querySelectorAll('.user-checkbox:checked');
    if (checkboxes.length === 0) { showToast('Please select at least one user', 'warning'); return false; }
    if (!action)                 { showToast('Please select an action', 'warning');          return false; }
    const msg = action === 'delete'
        ? `Delete ${checkboxes.length} user(s)? This cannot be undone!`
        : `${action === 'activate' ? 'Activate' : 'Deactivate'} ${checkboxes.length} user(s)?`;
    return confirm(msg);
}

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
        if (e.key === 'Escape') { closeUserModal(); closeDeleteModal(); }
    });
});
</script>
</body>
</html>
