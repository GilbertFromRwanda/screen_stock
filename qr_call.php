<?php
require_once 'config.php';
if (!isLoggedIn()) redirect('login.php');

$phone = trim($_GET['phone'] ?? '');
$name  = trim($_GET['name']  ?? '');

$show_form = empty($phone);

// Build tel: URI — handle both plain numbers and USSD codes (*182*...*#)
$tel      = '';
$tel_href = '';
$is_ussd  = false;
if (!$show_form) {
    $is_ussd = str_starts_with($phone, '*');
    if ($is_ussd) {
        // Encode # as %23 in both QR text and href — raw # is treated as a URI
        // fragment by scanners and browsers, causing it to be silently stripped
        $tel      = str_replace('#', '%23', $phone);
        $tel_href = $tel;
    } else {
        $tel = preg_replace('/[\s\-\(\)]/', '', $phone);
        if (!str_starts_with($tel, '+')) {
            $tel = preg_replace('/^0/', '+250', $tel);
        }
        $tel_href = $tel;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QR Call<?php echo !$show_form ? ' — ' . htmlspecialchars($name ?: $phone) : ''; ?></title>
    <link rel="stylesheet" href="css/style.css?v=<?php echo filemtime(__DIR__ . '/css/style.css'); ?>">
    <link rel="stylesheet" href="css/all.min.css">
    <script src="js/qrcode.min.js"></script>
    <style>
        /* ── Card centered ── */
        .main-content { display: flex; flex-direction: column; align-items: center; }
        .loans-header  { width: 100%; max-width: 520px; }
        .qr-card {
            background: #fff; border-radius: 14px;
            box-shadow: 0 2px 12px rgba(0,0,0,.07);
            padding: 32px 36px; max-width: 520px; width: 100%;
        }
        .qr-card-title { font-size: 18px; font-weight: 700; color: #1e293b; margin-bottom: 4px; }
        .qr-card-sub   { font-size: 13px; color: #64748b; margin-bottom: 22px; }

        /* ── Form fields ── */
        .fg { margin-bottom: 14px; }
        .fg label {
            display: block; font-size: 12px; font-weight: 700; color: #475569;
            text-transform: uppercase; letter-spacing: .5px; margin-bottom: 5px;
        }
        .fg input, .fg select {
            width: 100%; box-sizing: border-box; padding: 10px 12px;
            border: 1px solid #e2e8f0; border-radius: 8px;
            font-size: 14px; color: #1e293b; outline: none;
            transition: border .15s, box-shadow .15s; background: #fff;
            font-family: system-ui, sans-serif;
        }
        .fg input.mono { font-family: monospace; font-size: 15px; }
        .fg input:focus, .fg select:focus { border-color: #1a4280; box-shadow: 0 0 0 3px rgba(26,66,128,.12); }

        /* ── USSD row ── */
        .ussd-row { display: flex; align-items: stretch; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; transition: border .15s, box-shadow .15s; }
        .ussd-row:focus-within { border-color: #1a4280; box-shadow: 0 0 0 3px rgba(26,66,128,.12); }
        .ussd-prefix {
            background: #f1f5f9; border: none; border-right: 1px solid #e2e8f0;
            padding: 10px 10px; font-family: monospace; font-size: 13px;
            color: #475569; font-weight: 700; min-width: 80px; max-width: 160px;
            outline: none;
        }
        .ussd-var {
            flex: 1; border: none !important; box-shadow: none !important;
            border-radius: 0 !important; padding: 10px 10px;
            font-family: monospace; font-size: 15px; outline: none; min-width: 0;
        }
        .ussd-suffix {
            background: #f1f5f9; border-left: 1px solid #e2e8f0;
            padding: 10px 12px; font-family: monospace; font-size: 15px;
            color: #475569; font-weight: 700; display: flex; align-items: center;
        }

        /* ── Chips ── */
        .chips-label { font-size: 11px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 8px; }
        .chips { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 16px; }
        .chip {
            font-size: 11px; font-weight: 700; font-family: monospace;
            padding: 5px 10px; border-radius: 99px; cursor: pointer;
            border: 1.5px solid #e2e8f0; background: #f8fafc; color: #475569;
            transition: all .12s; line-height: 1.3; text-align: left;
        }
        .chip:hover { background: #e8edf5; border-color: #93c5fd; color: #0a2148; }
        .chip.sel   { background: #103060; border-color: #103060; color: #fff; }
        .chip-name  { display: block; font-family: system-ui, sans-serif; font-size: 10px; font-weight: 600; opacity: .7; margin-top: 1px; }

        /* ── Preview ── */
        .ussd-preview {
            background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;
            padding: 9px 12px; font-family: monospace; font-size: 13px; color: #1e293b;
            min-height: 38px; line-height: 1.5; word-break: break-all;
        }
        .ussd-preview .ph { color: #94a3b8; }

        /* ── Generate button ── */
        .gen-btn {
            width: 100%; padding: 11px; background: #103060; color: #fff;
            border: none; border-radius: 8px; font-size: 14px; font-weight: 700;
            cursor: pointer; transition: background .15s; margin-top: 6px;
        }
        .gen-btn:hover { background: #0a2148; }

        /* ── Result view ── */
        .result-wrap { display: flex; gap: 36px; align-items: flex-start; flex-wrap: wrap; }
        .result-qr { flex-shrink: 0; }
        .result-qr #qrcode canvas, .result-qr #qrcode img { border-radius: 10px; display: block; }
        .result-info { flex: 1; min-width: 180px; }
        .res-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: #64748b; margin-bottom: 4px; }
        .res-name  { font-size: 20px; font-weight: 700; color: #1e293b; margin-bottom: 6px; }
        .res-phone { font-size: 14px; color: #475569; font-family: monospace; word-break: break-all; margin-bottom: 20px; line-height: 1.5; }
        .res-hint  { font-size: 12px; color: #94a3b8; margin-bottom: 20px; line-height: 1.5; }
        .print-btn {
            display: inline-flex; align-items: center; gap: 8px;
            background: #103060; color: #fff; border: none; border-radius: 8px;
            padding: 11px 22px; font-size: 14px; font-weight: 700;
            cursor: pointer; text-decoration: none; transition: background .15s;
        }
        .print-btn:hover { background: #0a2148; }

        @media (max-width: 600px) {
            .qr-card { padding: 22px 18px; }
            .result-wrap { flex-direction: column; align-items: center; }
            .result-info { text-align: center; }
        }
        @media print {
            .topnav, .quickbar, .print-btn, .loans-header { display: none !important; }
            .main-content { margin-top: 0 !important; }
            .qr-card { box-shadow: none; border: none; padding: 0; max-width: 100%; }
            body, .dashboard-container { background: #fff; }
        }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php include 'sidebar.php'; ?>
    <div class="main-content">

        <!-- Page header -->
        <div class="loans-header" style="margin-bottom:24px;">
            <h1>&#128222; QR Code</h1>
            <?php if (!$show_form): ?>
            <a href="qr_call.php" class="btn btn-secondary">&#8592; Generate another</a>
            <?php endif; ?>
        </div>

        <div class="qr-card">

        <?php if ($show_form): ?>

            <div class="qr-card-title">&#9998;&nbsp; USSD QR Generator</div>
            <div class="qr-card-sub">Select a type, fill in the variable part, then generate.</div>

            <form method="get" action="qr_call.php" onsubmit="return submitUSSD()">
                    <input type="hidden" name="phone" id="ussd-hidden">

                    <div class="chips-label">Select USSD type</div>
                    <div class="chips" id="ussd-chips">
                        <button type="button" class="chip" data-prefix="*182*1*1*" data-hint="Phone number" onclick="pickChip(this)">
                            *182*1*1*<span class="chip-name">Send Money</span>
                        </button>
                        <button type="button" class="chip" data-prefix="*182*8*1*" data-hint="Code" onclick="pickChip(this)">
                            *182*8*1*<span class="chip-name">Momo pay</span>
                        </button>
                        <button type="button" class="chip" data-prefix="" data-hint="Full USSD string" onclick="pickChip(this)">
                            Custom<span class="chip-name">Enter manually</span>
                        </button>
                    </div>

                    <div class="fg">
                        <label>USSD String</label>
                        <div class="ussd-row">
                            <input type="text" class="ussd-prefix" id="ussd-prefix"
                                   placeholder="*182*…*"
                                   oninput="updateUSSDPreview()">
                            <input type="text" class="ussd-var" id="ussd-var"
                                   placeholder="phone / code"
                                   oninput="updateUSSDPreview()">
                            <span class="ussd-suffix" id="ussd-suffix">#</span>
                        </div>
                    </div>

                    <div class="fg">
                        <label>Preview</label>
                        <div class="ussd-preview" id="ussd-preview"><span class="ph">Fill in the fields above…</span></div>
                    </div>

                    <div class="fg">
                        <label>Label <small style="font-weight:400;text-transform:none;">(optional)</small></label>
                        <input type="text" name="name" placeholder="e.g. Send money to client">
                    </div>
                    <button type="submit" class="gen-btn">&#9654;&nbsp; Generate QR Code</button>
            </form>

        <?php else: ?>

            <div class="result-wrap">
                <div class="result-qr">
                    <div id="qrcode"></div>
                </div>
                <div class="result-info">
                    <div class="res-label"><?php echo $is_ussd ? 'Scan to Dial USSD' : 'Scan to Call'; ?></div>
                    <?php if ($name): ?>
                    <div class="res-name"><?php echo htmlspecialchars($name); ?></div>
                    <?php endif; ?>
                    <div class="res-phone"><?php echo htmlspecialchars(str_replace('%23', '#', $phone)); ?></div>
                    <p class="res-hint">
                        <?php if ($is_ussd && !str_contains($phone, '%23')): ?>
                            Scan opens the dialer with the prefix pre-filled — recipient types the number/code and presses call.
                        <?php else: ?>
                            Point a phone camera at the QR code — it will open the dialer instantly.
                        <?php endif; ?>
                    </p>
                    <button onclick="window.print()" class="print-btn">&#128438;&nbsp; Print QR</button>
                </div>
            </div>

        <?php endif; ?>

        </div><!-- /.qr-card -->

    </div><!-- /.main-content -->
</div><!-- /.dashboard-container -->

<script src="script.js"></script>
<?php if ($show_form): ?>
<script>
function pickChip(chip) {
    document.querySelectorAll('#ussd-chips .chip').forEach(function(c) { c.classList.remove('sel'); });
    chip.classList.add('sel');
    document.getElementById('ussd-prefix').value    = chip.dataset.prefix;
    document.getElementById('ussd-var').placeholder = chip.dataset.hint || 'variable';
    updateUSSDPreview();
    document.getElementById('ussd-var').focus();
}

function updateUSSDPreview() {
    var pre  = document.getElementById('ussd-prefix').value.trim();
    var vr   = document.getElementById('ussd-var').value.trim();
    var prev = document.getElementById('ussd-preview');
    if (!pre) {
        prev.innerHTML = '<span class="ph">Fill in the fields above…</span>';
        return;
    }
    prev.textContent = pre + (vr || '…') + '#';
}

function submitUSSD() {
    var pre = document.getElementById('ussd-prefix').value.trim();
    var vr  = document.getElementById('ussd-var').value.trim();
    if (!pre) { alert('Please enter or select a USSD prefix.'); return false; }
    if (!vr)  { alert('Please enter the variable part (phone number or code).'); return false; }
    document.getElementById('ussd-hidden').value = pre + vr + '%23';
    return true;
}
</script>
<?php else: ?>
<script>
new QRCode(document.getElementById('qrcode'), {
    text: 'tel:<?php echo addslashes($tel); ?>',
    width: 200, height: 200,
    colorDark: '#1e293b', colorLight: '#ffffff',
    correctLevel: QRCode.CorrectLevel.M
});
</script>
<?php endif; ?>
</body>
</html>
