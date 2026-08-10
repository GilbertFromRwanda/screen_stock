<?php
require_once 'config.php';

$requested = $_POST['language'] ?? '';

if (array_key_exists($requested, SUPPORTED_LANGUAGES)) {
    $_SESSION['language'] = $requested;
    // Persist to the user's profile so the choice follows them across devices.
    if (isLoggedIn()) {
        mysqli_query($conn, "UPDATE users SET language='" . mysqli_real_escape_string($conn, $requested) . "' WHERE id=" . (int)$_SESSION['user_id']);
    }
}

$back = $_POST['return'] ?? 'dashboard.php';
// Only allow same-site relative redirects, never an absolute/external URL.
if (preg_match('#^[a-zA-Z0-9_\-]+\.php(\?.*)?$#', $back) !== 1) {
    $back = 'dashboard.php';
}
redirect($back);
