<?php
session_start();

require_once __DIR__ . '/license_check.php';
license_check();

// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '@Git123');
define('DB_NAME', 'screen_db');

// Create connection
$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Check connection
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// Set timezone
date_default_timezone_set('Africa/Kigali');

// Function to check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isSuperAdmin() {
    return ($_SESSION['role'] ?? '') === 'superadmin';
}

// Returns the session company_id as int, or null for superadmin
function cid(): ?int {
    return isset($_SESSION['company_id']) && $_SESSION['company_id'] !== null
        ? (int)$_SESSION['company_id'] : null;
}
// Returns company_id for SQL INSERT values ("5" or "NULL")
function cidSql(): string {
    $c = cid(); return $c !== null ? (string)$c : 'NULL';
}
// Returns "AND company_id = X" or "" (superadmin sees all)
function cidAnd(): string {
    $c = cid(); return $c !== null ? "AND company_id = $c" : '';
}
// Returns "AND alias.company_id = X" or "" — use when query has multiple joined tables
function cidAndFor(string $alias): string {
    $c = cid(); return $c !== null ? "AND $alias.company_id = $c" : '';
}
// Returns "WHERE company_id = X" or "" (superadmin sees all)
function cidWhere(): string {
    $c = cid(); return $c !== null ? "WHERE company_id = $c" : '';
}

// Function to redirect
function redirect($url) {
    header("Location: $url");
    exit();
}

// Returns true if the current session user can perform $action on $module.
// superadmin and admin always return true; manager/user check user_permissions table.
function hasPermission(string $module, string $action = 'view'): bool {
    global $conn;
    $role = $_SESSION['role'] ?? '';
    if (in_array($role, ['superadmin', 'admin'])) return true;

    $valid  = ['view', 'create', 'edit', 'delete'];
    $action = in_array($action, $valid) ? $action : 'view';
    $col    = "can_$action";
    $uid    = (int)($_SESSION['user_id'] ?? 0);
    $mod    = mysqli_real_escape_string($conn, $module);

    $r   = mysqli_query($conn, "SELECT $col FROM user_permissions WHERE user_id=$uid AND module='$mod'");
    $row = $r ? mysqli_fetch_assoc($r) : null;
    return $row ? (bool)$row[$col] : false;
}

// Returns all permissions for a user keyed by module → ['view'=>bool,'edit'=>bool,'delete'=>bool]
function getUserPermissions(int $user_id): array {
    global $conn;
    $perms = [];
    $r = mysqli_query($conn, "SELECT module, can_view, can_create, can_edit, can_delete FROM user_permissions WHERE user_id=$user_id");
    while ($row = mysqli_fetch_assoc($r)) {
        $perms[$row['module']] = [
            'view'   => (bool)$row['can_view'],
            'create' => (bool)$row['can_create'],
            'edit'   => (bool)$row['can_edit'],
            'delete' => (bool)$row['can_delete'],
        ];
    }
    return $perms;
}

function logActivity(
    mysqli $conn,
    int $user_id,
    string $action,
    string $description,
    string $table_name = '',
    int $record_id = 0,
    array $old_values = [],
    array $new_values = []
): void {
    $company_id = cid();
    $cid_sql    = $company_id !== null ? (int)$company_id : 'NULL';
    $action_esc = mysqli_real_escape_string($conn, $action);
    $table_esc  = mysqli_real_escape_string($conn, $table_name);
    $old_sql    = !empty($old_values)
        ? "'" . mysqli_real_escape_string($conn, json_encode($old_values, JSON_UNESCAPED_UNICODE)) . "'"
        : 'NULL';
    $new_sql    = !empty($new_values)
        ? "'" . mysqli_real_escape_string($conn, json_encode($new_values, JSON_UNESCAPED_UNICODE)) . "'"
        : 'NULL';
    $ip_esc     = mysqli_real_escape_string($conn, $_SERVER['REMOTE_ADDR'] ?? '');
    $rec_id     = $record_id > 0 ? $record_id : 'NULL';

    mysqli_query($conn, "
        INSERT INTO audit_log (company_id, user_id, action, table_name, record_id, old_values, new_values, ip_address)
        VALUES ($cid_sql, $user_id, '$action_esc', '$table_esc', $rec_id, $old_sql, $new_sql, '$ip_esc')
    ");
}
?>