<?php
require_once 'config.php';
if (!isLoggedIn()) { http_response_code(401); exit; }
if (!hasPermission('orders')) { http_response_code(403); exit; }

global $conn;
$user_id = (int)$_SESSION['user_id'];

// ── Mark as read: the user dismissed/acted on a notification — delete it now. ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_read'])) {
    header('Content-Type: application/json');
    $id = (int)$_POST['id'];
    mysqli_query($conn, "DELETE FROM notifications WHERE id=$id AND user_id=$user_id");
    echo json_encode(['success' => true]);
    exit;
}

// ── Total unread count, for the topnav bell badge (includes ones already
// delivered-but-unread from an earlier visit, not just brand-new ones). ──────
if (isset($_GET['action']) && $_GET['action'] === 'count') {
    header('Content-Type: application/json');
    $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM notifications WHERE user_id=$user_id"));
    echo json_encode(['count' => (int)($row['c'] ?? 0)]);
    exit;
}

// ── Full list, for the bell's dropdown panel (most recent first). ───────────────
if (isset($_GET['action']) && $_GET['action'] === 'list') {
    header('Content-Type: application/json');
    $res  = mysqli_query($conn, "SELECT * FROM notifications WHERE user_id=$user_id ORDER BY id DESC");
    $rows = [];
    while ($row = mysqli_fetch_assoc($res)) $rows[] = $row;
    echo json_encode($rows);
    exit;
}

header('Content-Type: application/json');

// Long-poll: hold the request open, checking every second, so the client gets
// notifications the moment they're inserted instead of on a fixed refresh
// interval — this app has no websocket support on shared hosting. A row is
// handed out once (delivered_at gets stamped so the loop below doesn't keep
// re-returning it every second) but is only ever deleted once the user
// explicitly marks it read via the action above.
$deadline = time() + 25;
session_write_close();

while (true) {
    $res  = mysqli_query($conn, "SELECT * FROM notifications WHERE user_id=$user_id AND delivered_at IS NULL ORDER BY id ASC");
    $rows = [];
    while ($row = mysqli_fetch_assoc($res)) $rows[] = $row;

    if (!empty($rows)) {
        $ids = implode(',', array_column($rows, 'id'));
        mysqli_query($conn, "UPDATE notifications SET delivered_at = NOW() WHERE id IN ($ids)");
        echo json_encode($rows);
        exit;
    }

    if (time() >= $deadline) { echo json_encode([]); exit; }
    usleep(1000000);
}
