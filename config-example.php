<?php
session_start();

// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'olive2_db');

// Create connection
$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Check connection
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// Set timezone
date_default_timezone_set('Africa/Kigali');

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
?>