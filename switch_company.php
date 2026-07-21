<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

$raw = $_POST['company_id'] ?? null;

if (isSuperAdmin()) {
    // Superadmin can browse "as" any active company, or clear back to
    // unscoped "All Companies" (empty selection -> cid() falls back to null).
    if ($raw === '') {
        $_SESSION['viewing_company_id'] = null;
    } elseif ($raw !== null) {
        $requested = (int)$raw;
        $valid = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM companies WHERE id=$requested AND status='active'"));
        if ($valid) {
            $_SESSION['viewing_company_id'] = $requested;
        }
    }
} elseif ($raw === 'all_mine') {
    // "All My Companies" — combined read-only view across everything this
    // user has access to. Blocked from writes by cidSql() until they pick one.
    $allowed = getAccessibleCompanies($conn, (int)$_SESSION['user_id']);
    if (count($allowed) > 1) {
        $_SESSION['viewing_all_mine'] = true;
    }
} else {
    $requested   = $raw !== null && $raw !== '' ? (int)$raw : null;
    $allowed     = getAccessibleCompanies($conn, (int)$_SESSION['user_id']);
    $allowed_ids = array_column($allowed, 'id');
    if ($requested !== null && in_array($requested, $allowed_ids, true)) {
        $_SESSION['viewing_all_mine'] = false;
        $_SESSION['viewing_company_id'] = $requested;
    }
}

$back = $_POST['return'] ?? 'dashboard.php';
// Only allow same-site relative redirects, never an absolute/external URL.
if (preg_match('#^[a-zA-Z0-9_\-]+\.php(\?.*)?$#', $back) !== 1) {
    $back = 'dashboard.php';
}
redirect($back);
