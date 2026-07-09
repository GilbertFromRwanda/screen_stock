<?php
require_once 'config.php';
// No login gate — same public order_number+phone check as order_track.php.
// Long-polled by order_track.php while a customer keeps the tracking page
// open, so a staff status change (orders.php) shows up there live instead
// of requiring the customer to refresh.
global $conn;
header('Content-Type: application/json');

function normPhone(string $p): string {
    return preg_replace('/\D/', '', $p);
}

$order_number = trim($_GET['order_number'] ?? '');
$phone_input  = trim($_GET['phone'] ?? '');
$since        = trim($_GET['since'] ?? '');

if ($order_number === '' || $phone_input === '') {
    echo json_encode(['error' => 'missing']);
    exit;
}

$on = mysqli_real_escape_string($conn, $order_number);
$row = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT phone, status, delivery_status, cancel_reason, updated_at FROM `orders`
     WHERE order_number='$on' AND status NOT IN ('new','open') LIMIT 1"));

if (!$row || normPhone($row['phone']) === '' || normPhone($row['phone']) !== normPhone($phone_input)) {
    echo json_encode(['error' => 'not_found']);
    exit;
}

session_write_close();

$deadline = time() + 25;
while (true) {
    $row = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT status, delivery_status, cancel_reason, updated_at FROM `orders` WHERE order_number='$on' LIMIT 1"));
    if (!$row) { echo json_encode(['error' => 'not_found']); exit; }

    if ($since === '' || $row['updated_at'] !== $since) {
        echo json_encode([
            'status'          => $row['status'],
            'delivery_status' => $row['delivery_status'],
            'cancel_reason'   => $row['cancel_reason'],
            'updated_at'      => $row['updated_at'],
            'changed'         => $since !== '',
        ]);
        exit;
    }

    if (time() >= $deadline) {
        echo json_encode(['updated_at' => $row['updated_at'], 'changed' => false]);
        exit;
    }
    usleep(1000000);
}
