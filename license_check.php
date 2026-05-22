<?php
define('LICENSE_SECRET', 'SS_PRV_K3Y_SCR33N_ST0CK_2024_RWND');
define('LICENSE_FILE', __DIR__ . '/license.dat');

function license_validate_key(string $key) {
    $key = strtoupper(trim($key));
    if (!preg_match('/^SS-(\d{8})-([A-F0-9]{8})$/', $key, $m)) {
        return ['valid' => false, 'reason' => 'invalid_format'];
    }
    $date_part = $m[1];
    $sig       = $m[2];
    $expected  = strtoupper(substr(hash_hmac('sha256', 'SS-' . $date_part, LICENSE_SECRET), 0, 8));
    if ($sig !== $expected) {
        return ['valid' => false, 'reason' => 'invalid_key'];
    }
    $expiry = DateTime::createFromFormat('Ymd', $date_part);
    if (!$expiry) {
        return ['valid' => false, 'reason' => 'invalid_date'];
    }
    $expiry->setTime(23, 59, 59);
    if (new DateTime() > $expiry) {
        return ['valid' => false, 'reason' => 'expired', 'expired_on' => $expiry->format('d M Y')];
    }
    return ['valid' => true, 'expires' => $expiry->format('d M Y')];
}

function license_check() {
    $exempt  = ['license_gate.php', 'logout.php'];
    $current = basename($_SERVER['SCRIPT_FILENAME']);
    if (in_array($current, $exempt)) return;

    if (!file_exists(LICENSE_FILE)) {
        header('Location: license_gate.php?reason=no_license');
        exit();
    }

    $key    = trim(file_get_contents(LICENSE_FILE));
    $result = license_validate_key($key);

    if (!$result['valid']) {
        header('Location: license_gate.php?reason=' . $result['reason']);
        exit();
    }

    $GLOBALS['_license_expires'] = $result['expires'];
}
