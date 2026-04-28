<?php
require_once 'config.php';
if (!isLoggedIn()) { http_response_code(403); exit; }

header('Content-Type: application/json');

$today = date('Y-m-d');
$from  = mysqli_real_escape_string($conn, $_GET['coll_from'] ?? $today);
$to    = mysqli_real_escape_string($conn, $_GET['coll_to']   ?? $today);

$row = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT
        COALESCE(SUM(cash_amount), 0) as cash_total,
        COALESCE(SUM(momo_amount), 0) as momo_total,
        COALESCE(SUM(loan_amount), 0) as loan_total
    FROM (
        SELECT cash_amount, momo_amount, loan_amount FROM sales_bulk     WHERE sale_date BETWEEN '$from' AND '$to'
        UNION ALL
        SELECT cash_amount, momo_amount, loan_amount FROM sales_retail   WHERE sale_date BETWEEN '$from' AND '$to'
        UNION ALL
        SELECT cash_amount, momo_amount, loan_amount FROM sales_external WHERE sale_date BETWEEN '$from' AND '$to'
    ) as combined
"));

$outstanding = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COALESCE(SUM(amount), 0) as total FROM loans
"))['total'] ?? 0;

echo json_encode([
    'cash'        => (float)$row['cash_total'],
    'momo'        => (float)$row['momo_total'],
    'loan'        => (float)$row['loan_total'],
    'outstanding' => (float)$outstanding,
    'from'        => $from,
    'to'          => $to,
]);
