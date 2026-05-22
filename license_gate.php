<?php
session_start();
require_once __DIR__ . '/license_check.php';

$reason = $_GET['reason'] ?? 'no_license';
$error  = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['license_key'])) {
    $key    = trim($_POST['license_key']);
    $result = license_validate_key($key);
    if ($result['valid']) {
        file_put_contents(LICENSE_FILE, strtoupper($key));
        $success = 'License activated! Expires on ' . $result['expires'] . '.';
        header('Refresh: 2; url=index.php');
    } else {
        $msgs = [
            'invalid_format' => 'Invalid key format. Expected: SS-YYYYMMDD-XXXXXXXX',
            'invalid_key'    => 'License key is not valid. Contact your provider.',
            'expired'        => 'This license key has already expired.',
            'invalid_date'   => 'License key contains an invalid date.',
        ];
        $error = $msgs[$result['reason']] ?? 'Invalid license key.';
    }
}

$titles = [
    'no_license' => 'Software Not Activated',
    'expired'    => 'License Expired',
    'invalid_key'=> 'Invalid License',
];
$page_title = $titles[$reason] ?? 'License Required';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($page_title) ?> — Screen System</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'Inter', -apple-system, sans-serif;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #f0f4ff;
}
.card {
    background: #fff;
    border-radius: 20px;
    box-shadow: 0 8px 40px rgba(0,0,0,0.10);
    padding: 52px 48px 48px;
    width: 100%;
    max-width: 460px;
    text-align: center;
}
.icon-wrap {
    width: 72px; height: 72px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 28px;
}
.icon-wrap.expired    { background: #fef3c7; }
.icon-wrap.no-license { background: #fee2e2; }
.icon-wrap svg { width: 36px; height: 36px; }
h1 { font-size: 24px; font-weight: 700; color: #0f172a; margin-bottom: 10px; }
.sub { font-size: 14px; color: #64748b; line-height: 1.7; margin-bottom: 36px; }
.divider { height: 1px; background: #e2e8f0; margin-bottom: 28px; }
.activate-label { text-align: left; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 8px; }
.key-input {
    width: 100%; padding: 12px 16px;
    border: 1.5px solid #e2e8f0; border-radius: 10px;
    font-size: 14px; font-family: 'Courier New', monospace;
    color: #0f172a; background: #f8fafc;
    outline: none; letter-spacing: 1px; text-transform: uppercase;
    transition: border-color .2s, box-shadow .2s;
}
.key-input:focus { border-color: #3b82f6; background: #fff; box-shadow: 0 0 0 3px rgba(59,130,246,.12); }
.key-input::placeholder { letter-spacing: 0; text-transform: none; color: #cbd5e1; }
.btn-activate {
    width: 100%; margin-top: 14px; padding: 12px;
    background: linear-gradient(135deg, #3b82f6, #6366f1);
    color: #fff; border: none; border-radius: 10px;
    font-size: 15px; font-weight: 600; font-family: inherit;
    cursor: pointer; transition: opacity .2s, box-shadow .2s;
}
.btn-activate:hover { opacity: .9; box-shadow: 0 4px 16px rgba(59,130,246,.3); }
.alert { padding: 12px 16px; border-radius: 10px; font-size: 13px; margin-top: 14px; text-align: left; }
.alert-error   { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
.alert-success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
.contact-box {
    margin-top: 28px; padding: 16px 18px;
    background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; text-align: left;
}
.contact-box p { font-size: 12px; color: #64748b; line-height: 1.7; }
.contact-box p:first-child { font-weight: 600; color: #475569; margin-bottom: 4px; }
</style>
</head>
<body>
<div class="card">

    <?php if ($reason === 'expired'): ?>
    <div class="icon-wrap expired">
        <svg viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="10"/>
            <line x1="12" y1="8" x2="12" y2="12"/>
            <line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
    </div>
    <h1>License Expired</h1>
    <p class="sub">Your software license has expired. Please contact your provider to renew and enter your new license key below.</p>
    <?php else: ?>
    <div class="icon-wrap no-license">
        <svg viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
            <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
        </svg>
    </div>
    <h1>Software Not Activated</h1>
    <p class="sub">This software requires a valid license key to run. Please enter the license key provided by your supplier.</p>
    <?php endif; ?>

    <div class="divider"></div>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?> Redirecting…</div>
    <?php else: ?>
    <form method="POST">
        <p class="activate-label">Enter your license key</p>
        <input
            type="text"
            name="license_key"
            class="key-input"
            placeholder="SS-YYYYMMDD-XXXXXXXX"
            maxlength="20"
            value="<?= htmlspecialchars($_POST['license_key'] ?? '') ?>"
            autocomplete="off"
            spellcheck="false"
        >
        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <button type="submit" class="btn-activate">Activate License</button>
    </form>
    <?php endif; ?>

    <div class="contact-box">
        <p>Need a license key?</p>
        <p>Contact your software provider to purchase or renew your license.</p>
    </div>

</div>
</body>
</html>
