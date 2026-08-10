<?php
session_start();

require_once __DIR__ . '/license_check.php';
license_check();

// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '@Git');
// define('DB_NAME', 'screen_db');
define('DB_NAME', 'stock_db');


// Create connection
$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Check connection
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// Set timezone
date_default_timezone_set('Africa/Kigali');

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/lang.php';

// Catches AggregateViewWriteBlocked (thrown by cidSql() when a create/edit is
// attempted while viewing the "All Companies" aggregate) and turns it into a
// friendly response instead of a raw fatal error — JSON for AJAX requests,
// a flash_error + redirect-back for normal form submissions.
set_exception_handler(function (\Throwable $e) {
    if (!($e instanceof AggregateViewWriteBlocked)) {
        throw $e;
    }
    $is_ajax = (
        (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_POST['ajax']) && $_POST['ajax'] === '1')
    );
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
    $_SESSION['flash_error'] = $e->getMessage();
    $back = $_SERVER['HTTP_REFERER'] ?? 'dashboard.php';
    header("Location: $back");
    exit;
});
