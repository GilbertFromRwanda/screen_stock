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
            $_SESSION['company_id'] = $user['company_id'] ?? null;
            mysqli_query($conn, "UPDATE users SET last_login = NOW() WHERE id = " . $user['id']);
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
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            min-height: 100vh;
            display: flex;
            background: #f0f4ff;
        }

        /* ── Left branding panel ── */
        .auth-brand {
            width: 420px;
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: flex-start;
            padding: 48px 52px;
            background: #0f172a;
            position: relative;
            overflow: hidden;
        }

        .auth-brand::before {
            content: '';
            position: absolute;
            inset: 0;
            background:
                radial-gradient(circle at 15% 75%, rgba(59,130,246,0.12) 0%, transparent 55%),
                radial-gradient(circle at 80% 15%, rgba(99,102,241,0.10) 0%, transparent 50%);
        }

        .brand-logo {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 56px;
            position: relative;
            z-index: 1;
        }

        .brand-logo-icon {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, #3b82f6, #6366f1);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 16px rgba(59,130,246,0.35);
        }

        .brand-logo-icon svg {
            width: 26px;
            height: 26px;
            fill: #fff;
        }

        .brand-logo-text {
            font-size: 20px;
            font-weight: 700;
            color: #f1f5f9;
            letter-spacing: -0.3px;
        }

        .brand-headline {
            position: relative;
            z-index: 1;
        }

        .brand-headline h1 {
            font-size: 40px;
            font-weight: 700;
            color: #f1f5f9;
            line-height: 1.2;
            letter-spacing: -0.8px;
            margin-bottom: 20px;
        }

        .brand-headline p {
            font-size: 17px;
            color: #64748b;
            line-height: 1.65;
            max-width: 380px;
        }

        .brand-features {
            position: relative;
            z-index: 1;
            margin-top: 56px;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .feature-item {
            display: flex;
            align-items: center;
            gap: 12px;
            color: #94a3b8;
            font-size: 15px;
        }

        .feature-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #3b82f6;
            flex-shrink: 0;
        }

        .brand-decor {
            position: absolute;
            bottom: -60px;
            right: -60px;
            width: 320px;
            height: 320px;
            border: 1px solid rgba(59,130,246,0.08);
            border-radius: 50%;
        }

        .brand-decor::before {
            content: '';
            position: absolute;
            inset: 40px;
            border: 1px solid rgba(99,102,241,0.07);
            border-radius: 50%;
        }

        /* ── Right form panel ── */
        .auth-form-panel {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 48px 40px;
            background: #fff;
            min-width: 0;
        }

        .auth-form-wrap {
            width: 100%;
            max-width: 380px;
            animation: fadeUp 0.4s ease both;
        }

        .form-header {
            margin-bottom: 40px;
        }

        .form-header h2 {
            font-size: 26px;
            font-weight: 700;
            color: #0f172a;
            letter-spacing: -0.4px;
            margin-bottom: 8px;
        }

        .form-header p {
            font-size: 14px;
            color: #64748b;
        }

        .alert-error {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 10px;
            color: #991b1b;
            font-size: 14px;
            margin-bottom: 24px;
        }

        .alert-error svg {
            width: 16px;
            height: 16px;
            flex-shrink: 0;
            fill: #ef4444;
        }

        .field {
            margin-bottom: 20px;
        }

        .field label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 7px;
        }

        .input-wrap {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            width: 16px;
            height: 16px;
            fill: #94a3b8;
            pointer-events: none;
        }

        .input-wrap input {
            width: 100%;
            padding: 11px 14px 11px 42px;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            font-family: inherit;
            color: #0f172a;
            background: #f8fafc;
            transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
            outline: none;
        }

        .input-wrap input::placeholder { color: #cbd5e1; }

        .input-wrap input:focus {
            border-color: #3b82f6;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(59,130,246,0.12);
        }

        .input-wrap input[type="password"] { padding-right: 42px; }

        .toggle-pw {
            position: absolute;
            right: 13px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            padding: 2px;
            display: flex;
            align-items: center;
        }

        .toggle-pw svg {
            width: 17px;
            height: 17px;
            fill: #94a3b8;
            transition: fill 0.2s;
        }

        .toggle-pw:hover svg { fill: #475569; }

        .btn-signin {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #3b82f6, #6366f1);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
            transition: opacity 0.2s, box-shadow 0.2s, transform 0.1s;
            margin-top: 8px;
            letter-spacing: 0.1px;
        }

        .btn-signin:hover {
            opacity: 0.9;
            box-shadow: 0 4px 16px rgba(59,130,246,0.35);
        }

        .btn-signin:active { transform: scale(0.99); }

        .btn-signin:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }

        .btn-spinner {
            display: inline-block;
            width: 14px;
            height: 14px;
            border: 2px solid rgba(255,255,255,0.4);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
            vertical-align: middle;
            margin-right: 8px;
        }

        @keyframes spin { to { transform: rotate(360deg); } }

        .demo-hint {
            margin-top: 32px;
            padding: 14px 16px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
        }

        .demo-hint p {
            font-size: 12px;
            color: #64748b;
            line-height: 1.6;
        }

        .demo-hint p:first-child {
            font-weight: 600;
            color: #475569;
            margin-bottom: 4px;
        }

        .demo-hint code {
            background: #eff6ff;
            color: #3b82f6;
            padding: 1px 5px;
            border-radius: 4px;
            font-size: 12px;
        }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(16px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 900px) {
            .auth-brand { display: none; }
            .auth-form-panel { width: 100%; }
        }

        @media (max-width: 480px) {
            .auth-form-panel { padding: 32px 24px; }
        }
    </style>
</head>
<body>

    <!-- Branding panel -->
    <div class="auth-brand">
        <div class="brand-logo">
            <div class="brand-logo-icon">
                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path d="M20 7H4a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2Z"/>
                    <path d="M16 3H8a2 2 0 0 0-2 2v2h12V5a2 2 0 0 0-2-2Z" opacity=".6"/>
                </svg>
            </div>
            <span class="brand-logo-text">Screen System</span>
        </div>

        <div class="brand-headline">
            <h1>Manage your<br>stock with ease.</h1>
            <p>A unified platform for inventory control, sales tracking, and financial reporting — all in one place.</p>
        </div>

        <div class="brand-features">
            <div class="feature-item"><span class="feature-dot"></span>Real-time inventory tracking</div>
            <div class="feature-item"><span class="feature-dot"></span>Sales &amp; purchase analytics</div>
            <div class="feature-item"><span class="feature-dot"></span>Supplier &amp; expense management</div>
        </div>

        <div class="brand-decor"></div>
    </div>

    <!-- Form panel -->
    <div class="auth-form-panel">
        <div class="auth-form-wrap">

            <div class="form-header">
                <h2>Welcome back</h2>
                <p>Sign in to your account to continue</p>
            </div>

            <div class="alert-error" id="login-error" style="display:none" aria-live="polite">
                <svg viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0Zm-8-5a.75.75 0 0 1 .75.75v4.5a.75.75 0 0 1-1.5 0v-4.5A.75.75 0 0 1 10 5Zm0 10a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd"/>
                </svg>
                <span id="login-error-text"></span>
            </div>

            <form id="login-form" autocomplete="on" novalidate>

                <div class="field">
                    <label for="username">Username</label>
                    <div class="input-wrap">
                        <svg class="input-icon" viewBox="0 0 20 20">
                            <path d="M10 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM3.465 14.493a1.23 1.23 0 0 0 .41 1.412A9.957 9.957 0 0 0 10 18c2.31 0 4.438-.784 6.131-2.1.43-.333.604-.903.408-1.41a7.002 7.002 0 0 0-13.074.003Z"/>
                        </svg>
                        <input type="text" id="username" name="username" placeholder="Enter your username" required autocomplete="username">
                    </div>
                </div>

                <div class="field">
                    <label for="password">Password</label>
                    <div class="input-wrap">
                        <svg class="input-icon" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 1a4.5 4.5 0 0 0-4.5 4.5V9H5a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2h-.5V5.5A4.5 4.5 0 0 0 10 1Zm3 8V5.5a3 3 0 1 0-6 0V9h6Z" clip-rule="evenodd"/>
                        </svg>
                        <input type="password" id="password" name="password" placeholder="Enter your password" required autocomplete="current-password">
                        <button type="button" class="toggle-pw" aria-label="Toggle password visibility" onclick="togglePassword()">
                            <svg id="eye-icon" viewBox="0 0 20 20">
                                <path d="M10 12.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5Z"/>
                                <path fill-rule="evenodd" d="M.664 10.59a1.651 1.651 0 0 1 0-1.186A10.004 10.004 0 0 1 10 3c4.257 0 7.893 2.66 9.336 6.41.147.381.146.804 0 1.186A10.004 10.004 0 0 1 10 17c-4.257 0-7.893-2.66-9.336-6.41ZM14 10a4 4 0 1 1-8 0 4 4 0 0 1 8 0Z" clip-rule="evenodd"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <button type="submit" id="submit-btn" class="btn-signin">Sign In</button>
            </form>

            <div class="demo-hint">
                <p>Demo credentials</p>
                <p>Username: <code>admin</code> &nbsp;&nbsp; Password: <code>admin123</code></p>
            </div>

        </div>
    </div>

    <script>
        function togglePassword() {
            const input = document.getElementById('password');
            const icon  = document.getElementById('eye-icon');
            const show  = input.type === 'password';
            input.type  = show ? 'text' : 'password';
            icon.innerHTML = show
                ? '<path fill-rule="evenodd" d="M3.28 2.22a.75.75 0 0 0-1.06 1.06l14.5 14.5a.75.75 0 1 0 1.06-1.06l-1.745-1.745a10.029 10.029 0 0 0 3.3-4.38 1.651 1.651 0 0 0 0-1.185A10.004 10.004 0 0 0 9.999 3a9.956 9.956 0 0 0-4.744 1.194L3.28 2.22ZM7.752 6.69l1.092 1.092a2.5 2.5 0 0 1 3.374 3.373l1.091 1.092a4 4 0 0 0-5.557-5.557Z" clip-rule="evenodd"/><path d="m10.748 13.93 2.523 2.524a10.065 10.065 0 0 1-3.27.547c-4.258 0-7.894-2.66-9.337-6.41a1.651 1.651 0 0 1 0-1.186A10.007 10.007 0 0 1 2.839 6.02L6.07 9.252a4 4 0 0 0 4.678 4.678Z"/>'
                : '<path d="M10 12.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5Z"/><path fill-rule="evenodd" d="M.664 10.59a1.651 1.651 0 0 1 0-1.186A10.004 10.004 0 0 1 10 3c4.257 0 7.893 2.66 9.336 6.41.147.381.146.804 0 1.186A10.004 10.004 0 0 1 10 17c-4.257 0-7.893-2.66-9.336-6.41ZM14 10a4 4 0 1 1-8 0 4 4 0 0 1 8 0Z" clip-rule="evenodd"/>';
        }

        document.getElementById('login-form').addEventListener('submit', async function (e) {
            e.preventDefault();

            const btn       = document.getElementById('submit-btn');
            const errorBox  = document.getElementById('login-error');
            const errorText = document.getElementById('login-error-text');

            // Loading state
            btn.disabled = true;
            btn.innerHTML = '<span class="btn-spinner"></span>Signing in…';
            errorBox.style.display = 'none';

            try {
                const res  = await fetch('login.php', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: new FormData(this)
                });

                const data = await res.json();

                if (data.success) {
                    btn.innerHTML = 'Redirecting…';
                    window.location.href = data.redirect;
                } else {
                    errorText.textContent = data.error;
                    errorBox.style.display = 'flex';
                    btn.disabled = false;
                    btn.innerHTML = 'Sign In';
                    document.getElementById('password').value = '';
                    document.getElementById('password').focus();
                }
            } catch {
                errorText.textContent = 'Network error — please try again.';
                errorBox.style.display = 'flex';
                btn.disabled = false;
                btn.innerHTML = 'Sign In';
            }
        });
    </script>
</body>
</html>
