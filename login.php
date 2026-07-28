<?php
require_once 'config.php';

if (isLoggedIn()) {
    redirect('dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');

    $username = mysqli_real_escape_string($conn, $_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $result = mysqli_query($conn, "SELECT * FROM users WHERE username = '$username'");

    if (mysqli_num_rows($result) == 1) {
        $user = mysqli_fetch_assoc($result);
        if (password_verify($password, $user['password'])) {
            // Block inactive users
            if (($user['status'] ?? 'active') !== 'active') {
                echo json_encode(['success' => false, 'error' => 'Your account has been deactivated. Contact your administrator.']);
                exit;
            }
            // Block users whose company is inactive (superadmin with NULL company_id is exempt)
            if (!empty($user['company_id'])) {
                $company = mysqli_fetch_assoc(mysqli_query($conn,
                    "SELECT status FROM companies WHERE id = " . (int)$user['company_id']
                ));
                if (!$company || $company['status'] !== 'active') {
                    echo json_encode(['success' => false, 'error' => 'Your company account is inactive. Please contact support.']);
                    exit;
                }
            }
            $_SESSION['user_id']    = $user['id'];
            $_SESSION['username']   = $user['username'];
            $_SESSION['full_name']  = $user['full_name'];
            $_SESSION['role']       = $user['role'];
            $_SESSION['company_id']      = $user['company_id'] ?? null;
            $_SESSION['viewing_all_mine'] = false;

            // Default the viewing company to home if the user still has access to it
            // (home access can be revoked from users.php's Company Access modal, same
            // as any other granted company) — otherwise fall back to whatever they do
            // still have access to.
            $_SESSION['viewing_company_id'] = null;
            if ($_SESSION['company_id'] !== null) {
                $accessible = getAccessibleCompanies($conn, (int)$user['id']);
                $accessible_ids = array_column($accessible, 'id');
                if (in_array((int)$_SESSION['company_id'], $accessible_ids, true)) {
                    $_SESSION['viewing_company_id'] = (int)$_SESSION['company_id'];
                } elseif (!empty($accessible_ids)) {
                    $_SESSION['viewing_company_id'] = (int)$accessible_ids[0];
                } else {
                    // Defensive fallback — save_company_access blocks a user from ending
                    // up with zero accessible companies, so this shouldn't happen.
                    $_SESSION['viewing_company_id'] = (int)$_SESSION['company_id'];
                }
            }
            $time=date('Y-m-d H:i:s');
            mysqli_query($conn, "UPDATE users SET last_login = '$time' WHERE id = " . $user['id']);
            echo json_encode(['success' => true, 'redirect' => 'dashboard.php']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Invalid username or password']);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid username or password']);
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Screen Stock Management — Sign In</title>
    <link href="fonts/inter.css" rel="stylesheet">
    <link href="css/all.min.css" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        html, body { height: 100%; overflow: hidden; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            height: 100vh;
            display: flex;
        }

        /* ══ LEFT PANEL ══ */
        .auth-left {
            width: 44%;
            height: 100vh;
            overflow: hidden;
            background:
                radial-gradient(circle at 15% 15%, rgba(56,128,255,0.18), transparent 45%),
                radial-gradient(circle at 85% 85%, rgba(99,102,241,0.16), transparent 50%),
                linear-gradient(160deg, #0d2754 0%, #103060 50%, #0a1f44 100%);
            display: flex;
            flex-direction: column;
            padding: 24px 36px;
            color: #fff;
            position: relative;
        }

        .auth-left::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image:
                linear-gradient(rgba(255,255,255,0.035) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,0.035) 1px, transparent 1px);
            background-size: 40px 40px;
            mask-image: radial-gradient(ellipse at center, #000 40%, transparent 80%);
            -webkit-mask-image: radial-gradient(ellipse at center, #000 40%, transparent 80%);
            pointer-events: none;
        }

        .auth-left > * { position: relative; z-index: 1; }

        .auth-left::after {
            content: '';
            position: absolute;
            right: 0;
            top: 10%;
            height: 80%;
            width: 1px;
            background: rgba(255,255,255,0.08);
            z-index: 1;
        }

        .auth-logo { display: flex; align-items: center; gap: 12px; }

        .auth-logo-icon {
            width: 48px; height: 48px; flex-shrink: 0;
            background: rgba(255,255,255,.12);
            border: 1px solid rgba(255,255,255,.2);
            border-radius: 9px;
            display: flex; align-items: center; justify-content: center;
        }
        .auth-logo-icon svg { width: 26px; height: 26px; fill: #fff; }
        .auth-logo-text { font-size: 16px; font-weight: 700; color: #f1f5f9; letter-spacing: 0.2px; }

        .auth-copy {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 20px 0 16px;
        }

        .auth-copy h2 {
            font-size: 28px;
            font-weight: 800;
            line-height: 1.22;
            margin-bottom: 10px;
            letter-spacing: -0.6px;
            background: linear-gradient(135deg, #ffffff 0%, #cfe0ff 100%);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .auth-copy .tagline {
            font-size: 13px;
            color: rgba(255,255,255,0.6);
            line-height: 1.6;
            margin-bottom: 22px;
            max-width: 320px;
        }

        .auth-features {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 14px;
            opacity: 0;
            animation: fadeUp 0.6s ease-out 0.15s forwards;
        }
        .auth-features li {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            padding: 0;
            opacity: 0;
            transform: translateY(8px);
            animation: fadeUp 0.5s ease-out forwards;
        }
        .auth-features li:nth-child(1) { animation-delay: 0.20s; }
        .auth-features li:nth-child(2) { animation-delay: 0.30s; }
        .auth-features li:nth-child(3) { animation-delay: 0.40s; }

        .auth-features li .feat-icon {
            width: 34px;
            height: 34px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            flex-shrink: 0;
            color: #cfe0ff;
            background: linear-gradient(135deg, rgba(255,255,255,0.10), rgba(255,255,255,0.03));
            border: 1px solid rgba(255,255,255,0.10);
            border-radius: 9px;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.08);
            transition: transform 0.25s ease, color 0.25s ease, border-color 0.25s ease, box-shadow 0.25s ease;
        }
        .auth-features li:hover .feat-icon {
            transform: translateY(-2px) scale(1.04);
            color: #ffffff;
            border-color: rgba(255,255,255,0.22);
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.12), 0 4px 14px rgba(16,48,96,0.5);
        }
        .feat-text strong {
            display: block;
            font-size: 13.5px;
            font-weight: 600;
            color: rgba(255,255,255,0.95);
            margin-bottom: 3px;
            letter-spacing: 0.1px;
        }
        .feat-text span {
            font-size: 12px;
            color: rgba(255,255,255,0.5);
            line-height: 1.45;
        }

        @keyframes fadeUp {
            to { opacity: 1; transform: translateY(0); }
        }

        @media (prefers-reduced-motion: reduce) {
            .auth-features,
            .auth-features li { animation: none; opacity: 1; transform: none; }
            .auth-features li .feat-icon { transition: none; }
        }
        .auth-left-footer { font-size: 11.5px; color: rgba(255,255,255,0.22); }

        /* ══ RIGHT PANEL ══ */
        .auth-right {
            flex: 1;
            height: 100vh;
            overflow: hidden;
            background: #ffffff;
            border-radius: 12px 0 0 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 36px;
        }

        .login-box { width: 100%; max-width: 380px; }

        .mobile-logo { display: none; align-items: center; gap: 10px; margin-bottom: 32px; }
        .mobile-logo-icon {
            width: 36px; height: 36px; flex-shrink: 0;
            background: #103060; border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
        }
        .mobile-logo-icon svg { width: 20px; height: 20px; fill: #fff; }
        .mobile-logo strong { font-size: 16px; font-weight: 700; color: #103060; letter-spacing: 0.3px; }

        .login-box {
            opacity: 0;
            transform: translateY(8px);
            animation: fadeUp 0.5s ease-out 0.1s forwards;
        }

        .login-box h1 {
            font-size: 26px;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 6px;
            letter-spacing: -0.4px;
        }

        .login-box .subtitle { font-size: 13.5px; color: #94a3b8; margin-bottom: 22px; }

        .alert-error {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 14px;
            background: #fee2e2;
            border: 1.5px solid #fca5a5;
            border-radius: 6px;
            color: #7f1d1d;
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 20px;
        }
        .alert-error i { color: #dc2626; font-size: 14px; flex-shrink: 0; }

        .field { margin-bottom: 14px; }

        .field label {
            display: block;
            font-size: 12.5px;
            font-weight: 600;
            color: #475569;
            margin-bottom: 7px;
            letter-spacing: 0.2px;
        }

        .input-wrap { position: relative; }

        .input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 14px;
            pointer-events: none;
            transition: color 0.15s;
        }

        .input-wrap input {
            width: 100%;
            height: 48px;
            padding: 0 14px 0 42px;
            font-size: 14px;
            font-family: inherit;
            color: #0f172a;
            background: #f8fafc;
            border: 1.5px solid #e2e8f0;
            border-radius: 8px;
            outline: none;
            transition: border-color 0.18s ease, background 0.18s ease, box-shadow 0.18s ease;
        }

        .input-wrap input:hover:not(:focus) { border-color: #cbd5e1; background: #fff; }
        .input-wrap input::placeholder { color: #cbd5e1; }

        .input-wrap input:focus {
            background: #fff;
            border-color: #103060;
            box-shadow: 0 0 0 4px rgba(16,48,96,0.10);
        }

        .input-wrap:focus-within .input-icon { color: #103060; }

        .input-wrap input[type="password"] { padding-right: 46px; }

        .toggle-pw {
            position: absolute;
            right: 13px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: #94a3b8;
            font-size: 14px;
            padding: 0;
            display: flex;
            align-items: center;
            transition: color 0.15s;
        }

        .toggle-pw:hover { color: #475569; }

        input:-webkit-autofill,
        input:-webkit-autofill:hover,
        input:-webkit-autofill:focus {
            -webkit-box-shadow: 0 0 0 40px #f8fafc inset !important;
            -webkit-text-fill-color: #0f172a !important;
        }

        .btn-signin {
            width: 100%;
            height: 48px;
            background: linear-gradient(135deg, #143873 0%, #103060 60%, #0c2550 100%);
            color: #fff;
            font-size: 14px;
            font-weight: 700;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            letter-spacing: 0.3px;
            transition: transform 0.15s ease, box-shadow 0.2s ease, filter 0.2s ease;
            font-family: inherit;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            box-shadow: 0 4px 14px rgba(16,48,96,0.25), inset 0 1px 0 rgba(255,255,255,0.08);
            position: relative;
            overflow: hidden;
            margin-top: 6px;
        }

        .btn-signin::after {
            content: '';
            position: absolute;
            top: 0;
            left: -120%;
            width: 60%;
            height: 100%;
            background: linear-gradient(120deg, transparent, rgba(255,255,255,0.18), transparent);
            transform: skewX(-20deg);
            transition: left 0.6s ease;
        }
        .btn-signin:hover::after { left: 130%; }

        .btn-signin:hover { transform: translateY(-1px); filter: brightness(1.05); box-shadow: 0 6px 18px rgba(16,48,96,0.32), inset 0 1px 0 rgba(255,255,255,0.1); }
        .btn-signin:active { transform: translateY(0); filter: brightness(0.98); }
        .btn-signin:disabled { opacity: 0.7; cursor: not-allowed; transform: none; }
        .btn-signin > i, .btn-signin > span { position: relative; z-index: 1; }

        .btn-spinner {
            display: inline-block;
            width: 14px;
            height: 14px;
            border: 2px solid rgba(255,255,255,0.4);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
            vertical-align: middle;
            margin-right: 2px;
        }

        @keyframes spin { to { transform: rotate(360deg); } }

        .powered-by {
            margin-top: 24px;
            text-align: center;
            font-size: 12px;
            color: #94a3b8;
        }
        .powered-by span { font-weight: 600; color: #103060; }
        .powered-by a { color: #103060; text-decoration: none; }
        .powered-by a:hover { text-decoration: underline; }

        /* ── Tablet (landscape & portrait) ── */
        @media (max-width: 1024px) {
            .auth-left { width: 42%; padding: 20px 28px; }
            .auth-right { padding: 20px 28px; }
            .auth-copy h2 { font-size: 23px; }
        }

        @media (max-width: 860px) {
            .auth-left { width: 46%; padding: 16px 20px; }
            .auth-copy { padding: 14px 0 10px; }
            .auth-copy h2 { font-size: 20px; }
            .auth-right { padding: 16px 20px; }
        }

        /* ── Mobile ── */
        @media (max-width: 768px) {
            body { background: #fff; display: block; overflow-y: auto; }
            .auth-left { display: none; }
            .auth-right {
                width: 100%;
                min-height: 100vh;
                height: auto;
                border-radius: 0;
                padding: 48px 24px 40px;
                align-items: flex-start;
            }
            .mobile-logo { display: flex; }
            .login-box { max-width: 100%; }
        }

        @media (max-width: 400px) {
            .auth-right { padding: 40px 18px 32px; }
            .input-wrap input { height: 44px; font-size: 13.5px; }
            .btn-signin { height: 44px; font-size: 13.5px; }
        }
    </style>
</head>
<body>

    <!-- LEFT -->
    <div class="auth-left">
        <div class="auth-logo">
            <div class="auth-logo-icon">
                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path d="M20 7H4a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2Z"/>
                    <path d="M16 3H8a2 2 0 0 0-2 2v2h12V5a2 2 0 0 0-2-2Z" opacity=".6"/>
                </svg>
            </div>
            <span class="auth-logo-text">Screen Stock</span>
        </div>

        <div class="auth-copy">
            <h2>Manage your<br>stock with ease.</h2>
            <p class="tagline">A unified platform for inventory control, sales tracking, and financial reporting — all in one place.</p>

            <ul class="auth-features">
                <li>
                    <span class="feat-icon"><i class="fas fa-boxes-stacked"></i></span>
                    <div class="feat-text">
                        <strong>Real-Time Inventory</strong>
                        <span>Track stock levels across every product line</span>
                    </div>
                </li>
                <li>
                    <span class="feat-icon"><i class="fas fa-chart-line"></i></span>
                    <div class="feat-text">
                        <strong>Sales &amp; Purchase Analytics</strong>
                        <span>Revenue, profit and trends at a glance</span>
                    </div>
                </li>
                <li>
                    <span class="feat-icon"><i class="fas fa-truck-fast"></i></span>
                    <div class="feat-text">
                        <strong>Supplier &amp; Expense Management</strong>
                        <span>Keep purchases, loans and costs in one place</span>
                    </div>
                </li>
            </ul>
        </div>

        <p class="auth-left-footer">&copy; <?= date('Y') ?> Screen Stock Management. All rights reserved.</p>
    </div>

    <!-- RIGHT -->
    <div class="auth-right">
        <div class="login-box">

            <div class="mobile-logo">
                <div class="mobile-logo-icon">
                    <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path d="M20 7H4a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2Z"/>
                        <path d="M16 3H8a2 2 0 0 0-2 2v2h12V5a2 2 0 0 0-2-2Z" opacity=".6"/>
                    </svg>
                </div>
                <strong>Screen Stock</strong>
            </div>

            <h1>Welcome back</h1>
            <p class="subtitle">Sign in to your account to continue</p>

            <div class="alert-error" id="login-error" style="display:none" aria-live="polite">
                <i class="fas fa-circle-exclamation"></i>
                <span id="login-error-text"></span>
            </div>

            <form id="login-form" autocomplete="on" novalidate>

                <div class="field">
                    <label for="username">Username</label>
                    <div class="input-wrap">
                        <i class="fas fa-user input-icon"></i>
                        <input type="text" id="username" name="username" placeholder="Enter your username" required autocomplete="username">
                    </div>
                </div>

                <div class="field">
                    <label for="password">Password</label>
                    <div class="input-wrap">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" id="password" name="password" placeholder="Enter your password" required autocomplete="current-password">
                        <button type="button" class="toggle-pw" aria-label="Toggle password visibility" onclick="togglePassword()">
                            <i class="fas fa-eye" id="eye-icon"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" id="submit-btn" class="btn-signin"><i class="fas fa-arrow-right-to-bracket"></i><span>Sign In</span></button>
            </form>

            <div class="powered-by">
                &copy; <?= date('Y') ?> Powered by <span>Eng Gilbert</span><br>
                <a href="mailto:askforgilbert@gmail.com">askforgilbert@gmail.com</a>
            </div>

        </div>
    </div>

    <script>
        function togglePassword() {
            const input = document.getElementById('password');
            const icon  = document.getElementById('eye-icon');
            const show  = input.type === 'password';
            input.type  = show ? 'text' : 'password';
            icon.className = show ? 'fas fa-eye-slash' : 'fas fa-eye';
        }

        document.getElementById('login-form').addEventListener('submit', async function (e) {
            e.preventDefault();

            const btn       = document.getElementById('submit-btn');
            const errorBox  = document.getElementById('login-error');
            const errorText = document.getElementById('login-error-text');

            // Loading state
            btn.disabled = true;
            btn.innerHTML = '<span class="btn-spinner"></span><span>Signing in…</span>';
            errorBox.style.display = 'none';

            try {
                const res  = await fetch('login.php', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: new FormData(this)
                });

                const data = await res.json();

                if (data.success) {
                    btn.innerHTML = '<span>Redirecting…</span>';
                    window.location.href = data.redirect;
                } else {
                    errorText.textContent = data.error;
                    errorBox.style.display = 'flex';
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-arrow-right-to-bracket"></i><span>Sign In</span>';
                    document.getElementById('password').value = '';
                    document.getElementById('password').focus();
                }
            } catch {
                errorText.textContent = 'Network error — please try again.';
                errorBox.style.display = 'flex';
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-arrow-right-to-bracket"></i><span>Sign In</span>';
            }
        });
    </script>
</body>
</html>
