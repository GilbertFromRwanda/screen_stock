<?php
require_once 'config.php';

if (!isLoggedIn()) redirect('login.php');
if (!in_array($_SESSION['role'] ?? '', ['admin', 'superadmin'])) {
    $_SESSION['flash_error'] = "You don't have permission to access Settings.";
    redirect('dashboard.php');
}

// Per-company page — the company being edited is whichever one the topnav
// switcher currently has selected (cid()). A superadmin with no company picked
// (or anyone viewing the combined "All Companies" aggregate) has no single
// company to save against, so the form is replaced with a picker prompt below.
$target_cid = cid();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $target_cid !== null) {
    $enable_orders        = isset($_POST['enable_orders'])        ? 1 : 0;
    $enable_exchange_rate = isset($_POST['enable_exchange_rate']) ? 1 : 0;
    $enable_notifications = isset($_POST['enable_notifications']) ? 1 : 0;
    $enable_external_sale = isset($_POST['enable_external_sale']) ? 1 : 0;
    $enable_ip            = isset($_POST['enable_ip'])            ? 1 : 0;

    $old = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT enable_orders, enable_exchange_rate, enable_notifications, enable_external_sale, enable_ip FROM company_settings WHERE company_id=$target_cid"));

    mysqli_query($conn, "
        INSERT INTO company_settings (company_id, enable_orders, enable_exchange_rate, enable_notifications, enable_external_sale, enable_ip)
        VALUES ($target_cid, $enable_orders, $enable_exchange_rate, $enable_notifications, $enable_external_sale, $enable_ip)
        ON DUPLICATE KEY UPDATE
            enable_orders = VALUES(enable_orders),
            enable_exchange_rate = VALUES(enable_exchange_rate),
            enable_notifications = VALUES(enable_notifications),
            enable_external_sale = VALUES(enable_external_sale),
            enable_ip = VALUES(enable_ip)
    ");

    logActivity($conn, (int)$_SESSION['user_id'], 'Edit Settings', 'Updated company feature settings',
        'company_settings', $target_cid,
        $old ?: ['enable_orders' => 1, 'enable_exchange_rate' => 1, 'enable_notifications' => 1, 'enable_external_sale' => 1, 'enable_ip' => 1],
        ['enable_orders' => $enable_orders, 'enable_exchange_rate' => $enable_exchange_rate, 'enable_notifications' => $enable_notifications, 'enable_external_sale' => $enable_external_sale, 'enable_ip' => $enable_ip]
    );

    $success = 'Settings saved.';
}

$settings = ['enable_orders' => true, 'enable_exchange_rate' => true, 'enable_notifications' => true, 'enable_external_sale' => true, 'enable_ip' => true];
if ($target_cid !== null) {
    $row = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT enable_orders, enable_exchange_rate, enable_notifications, enable_external_sale, enable_ip FROM company_settings WHERE company_id=$target_cid"));
    if ($row) {
        $settings = [
            'enable_orders'        => (bool)$row['enable_orders'],
            'enable_exchange_rate' => (bool)$row['enable_exchange_rate'],
            'enable_notifications' => (bool)$row['enable_notifications'],
            'enable_external_sale' => (bool)$row['enable_external_sale'],
            'enable_ip'            => (bool)$row['enable_ip'],
        ];
    }
}

$company_label = $target_cid !== null ? companyName($conn) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#103060">
    <link rel="icon" type="image/png" href="icons/favicon-32.png">
    <link rel="apple-touch-icon" href="icons/apple-touch-icon.png">
    <script src="pwa.js" defer></script>
    <title>Settings - GilStock</title>
    <link rel="stylesheet" href="css/style.css?v=<?php echo filemtime(__DIR__ . '/css/style.css'); ?>">
</head>
<body>
<div class="dashboard-container">
    <?php include 'sidebar.php'; ?>

    <div class="main-content">

        <div class="dashboard-header">
            <div>
                <h1>Settings</h1>
                <p class="page-subtitle">
                    <?php echo $company_label ? 'Feature toggles for ' . htmlspecialchars($company_label) : 'Feature toggles'; ?>
                </p>
            </div>
            <div class="date-display"><strong><?php echo date('l, F j, Y'); ?></strong></div>
        </div>

        <?php if (isset($success)): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <?php if ($target_cid === null): ?>
            <div class="alert alert-danger">
                Pick a specific company from the switcher in the top bar before changing its settings
                — there's no single company to save these against while viewing "All Companies".
            </div>
        <?php else: ?>

        <form method="POST" class="settings-card">
            <div class="settings-row">
                <div class="settings-row-text">
                    <div class="settings-row-title">Orders</div>
                    <div class="settings-row-desc">Enables the Orders module (nav, quick-access bar, staff order pages, and customer order links) for this company.</div>
                </div>
                <label class="switch">
                    <input type="checkbox" name="enable_orders" <?php echo $settings['enable_orders'] ? 'checked' : ''; ?>>
                    <span class="switch-track"><span class="switch-thumb"></span></span>
                </label>
            </div>

            <div class="settings-row">
                <div class="settings-row-text">
                    <div class="settings-row-title">Exchange Rate</div>
                    <div class="settings-row-desc">Shows the Exchange Rates box on New Purchase, so foreign-currency cost prices can be converted to RWF. Turn off if this company only buys in RWF.</div>
                </div>
                <label class="switch">
                    <input type="checkbox" name="enable_exchange_rate" <?php echo $settings['enable_exchange_rate'] ? 'checked' : ''; ?>>
                    <span class="switch-track"><span class="switch-thumb"></span></span>
                </label>
            </div>

            <div class="settings-row">
                <div class="settings-row-text">
                    <div class="settings-row-title">Notifications</div>
                    <div class="settings-row-desc">Enables the notification bell and new-order alerts for staff who can view Orders.</div>
                </div>
                <label class="switch">
                    <input type="checkbox" name="enable_notifications" <?php echo $settings['enable_notifications'] ? 'checked' : ''; ?>>
                    <span class="switch-track"><span class="switch-thumb"></span></span>
                </label>
            </div>

            <div class="settings-row">
                <div class="settings-row-text">
                    <div class="settings-row-title">External Sale</div>
                    <div class="settings-row-desc">Enables the External Sale page (nav and quick-access bar) for recording sales made outside the normal bulk/retail flow.</div>
                </div>
                <label class="switch">
                    <input type="checkbox" name="enable_external_sale" <?php echo $settings['enable_external_sale'] ? 'checked' : ''; ?>>
                    <span class="switch-track"><span class="switch-thumb"></span></span>
                </label>
            </div>

            <div class="settings-row">
                <div class="settings-row-text">
                    <div class="settings-row-title">Server IP</div>
                    <div class="settings-row-desc">Shows the server's local IP address in the quick-access bar, for staff connecting to this app over the local network.</div>
                </div>
                <label class="switch">
                    <input type="checkbox" name="enable_ip" <?php echo $settings['enable_ip'] ? 'checked' : ''; ?>>
                    <span class="switch-track"><span class="switch-thumb"></span></span>
                </label>
            </div>

            <div class="settings-footer">
                <button type="submit" class="btn btn-primary">Save Settings</button>
            </div>
        </form>

        <?php endif; ?>

    </div>
</div>

<style>
.settings-card {
    background: var(--white); border: 1px solid var(--gray-200);
    border-radius: 12px; max-width: 640px; overflow: hidden;
}
.settings-row {
    display: flex; align-items: center; justify-content: space-between; gap: 20px;
    padding: 18px 22px; border-bottom: 1px solid var(--gray-200);
}
.settings-row:last-of-type { border-bottom: none; }
.settings-row-title { font-size: 14px; font-weight: 700; color: var(--dark); margin-bottom: 4px; }
.settings-row-desc { font-size: 12.5px; color: var(--secondary); line-height: 1.5; max-width: 460px; }
.settings-footer { padding: 16px 22px; background: var(--gray-100); text-align: right; }

.switch { position: relative; display: inline-block; width: 42px; height: 24px; flex-shrink: 0; cursor: pointer; }
.switch input { position: absolute; opacity: 0; width: 100%; height: 100%; margin: 0; cursor: pointer; }
.switch-track {
    position: absolute; inset: 0; background: var(--gray-300); border-radius: 999px;
    transition: background .15s;
}
.switch-thumb {
    position: absolute; top: 3px; left: 3px; width: 18px; height: 18px; border-radius: 50%;
    background: var(--white); box-shadow: 0 1px 3px rgba(0,0,0,.3); transition: transform .15s;
}
.switch input:checked + .switch-track { background: var(--success); }
.switch input:checked + .switch-track .switch-thumb { transform: translateX(18px); }
.switch input:focus-visible + .switch-track { outline: 2px solid var(--primary); outline-offset: 2px; }
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.alert').forEach(a => {
        setTimeout(() => { a.style.opacity = '0'; setTimeout(() => a.style.display = 'none', 300); }, 4000);
    });
});
</script>
</body>
</html>
