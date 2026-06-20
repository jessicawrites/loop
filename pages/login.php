<?php
/**
 * Loop — Login Page
 * Redirects to home if already logged in.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/constants.php';
require_guest();   // Kick logged-in users to home
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <meta name="description" content="Login to Loop — modern messaging">
    <title>Sign In — Loop</title>

    <!-- The single CSS import that pulls in everything -->
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css">

    <!-- Prevent flash of wrong theme -->
    <script>
        const t = localStorage.getItem('loop_theme') || 'light';
        document.documentElement.setAttribute('data-theme', t);
        window.CSRF_TOKEN = '<?= csrf_token() ?>';
    </script>
</head>
<body>

<div class="auth-container animate-fade-in">

    <!-- ── Logo ─────────────────────────────────────────────────────────── -->
    <div class="auth-logo">
        <div class="auth-logo-text">Loop</div>
        <div class="auth-logo-sub">Stay in the loop.</div>
    </div>

    <!-- ── Login Card ────────────────────────────────────────────────────── -->
    <div class="auth-card animate-slide-up">

        <h1 class="auth-title">Welcome back</h1>
        <p class="auth-subtitle">Sign in to continue your conversations.</p>

        <form class="auth-form" id="loginForm" novalidate>

            <!-- Username -->
            <div class="input-group">
                <label class="input-label" for="username">Username</label>
                <input
                    class="input-field"
                    type="text"
                    id="username"
                    name="username"
                    placeholder="your_username"
                    autocomplete="username"
                    autocapitalize="none"
                    spellcheck="false"
                    required
                >
            </div>

            <!-- Password -->
            <div class="input-group">
                <label class="input-label" for="password">Password</label>
                <div style="position:relative;">
                    <input
                        class="input-field"
                        type="password"
                        id="password"
                        name="password"
                        placeholder="••••••••"
                        autocomplete="current-password"
                        required
                        style="padding-right: 48px;"
                    >
                    <!-- Show/hide password toggle -->
                    <button
                        type="button"
                        class="btn-ghost"
                        id="togglePassword"
                        aria-label="Toggle password visibility"
                        style="position:absolute; right:12px; top:50%; transform:translateY(-50%);
                               width:32px; height:32px; border-radius:50%; padding:0;
                               display:flex; align-items:center; justify-content:center;"
                    >
                        <!-- Eye icon -->
                        <svg id="eyeIcon" xmlns="http://www.w3.org/2000/svg"
                             width="18" height="18" viewBox="0 0 24 24"
                             fill="none" stroke="currentColor"
                             stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                    </button>
                </div>
            </div>

            <!-- Submit -->
            <button
                type="submit"
                class="btn btn-primary btn-full"
                id="loginBtn"
                style="margin-top: 8px;"
            >
                Sign In
            </button>

        </form>
    </div>

    <!-- ── Footer Link ───────────────────────────────────────────────────── -->
    <div class="auth-footer">
        Don't have an account?
        <a href="<?= APP_URL ?>/pages/register.php">Create one</a>
    </div>

</div>

<!-- Scripts loaded in order: utils → ui → api → page logic -->
<script src="<?= APP_URL ?>/assets/js/utils.js"></script>
<script src="<?= APP_URL ?>/assets/js/ui.js"></script>
<script src="<?= APP_URL ?>/assets/js/api.js"></script>
<script>
/**
 * Login Page Logic
 * Handles form submission, validation feedback, and redirect.
 */
(function () {

    const form        = document.getElementById('loginForm');
    const loginBtn    = document.getElementById('loginBtn');
    const toggleBtn   = document.getElementById('togglePassword');
    const passwordFld = document.getElementById('password');
    const eyeIcon     = document.getElementById('eyeIcon');

    // ── Show/hide password ─────────────────────────────────────────────
    toggleBtn.addEventListener('click', () => {
        const isPassword = passwordFld.type === 'password';
        passwordFld.type = isPassword ? 'text' : 'password';

        // Swap eye icon to eye-off when visible
        eyeIcon.innerHTML = isPassword
            ? `<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8
                a18.45 18.45 0 0 1 5.06-5.94"/>
               <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8
                a18.5 18.5 0 0 1-2.16 3.19"/>
               <line x1="1" y1="1" x2="23" y2="23"/>`
            : `<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
               <circle cx="12" cy="12" r="3"/>`;
    });

    // ── Form Submission ────────────────────────────────────────────────
    form.addEventListener('submit', async (e) => {
        e.preventDefault();   // Stop the browser from reloading the page

        const username = document.getElementById('username').value.trim();
        const password = passwordFld.value;

        // ── Client-side validation ─────────────────────────────────────
        // Fast feedback before touching the network
        if (!username) {
            UI.toast('Please enter your username.', 'error');
            document.getElementById('username').focus();
            return;
        }
        if (!password) {
            UI.toast('Please enter your password.', 'error');
            passwordFld.focus();
            return;
        }

        // ── Show loading state ─────────────────────────────────────────
        const restore = UI.setLoading(loginBtn, 'Signing in…');

        // ── Call the API ───────────────────────────────────────────────
        const result = await API.login(username, password);

        restore();   // Always restore button, success or fail

        // ── Handle Response ────────────────────────────────────────────
        if (result.success) {
            UI.toast(result.message, 'success');
            // Short delay so the user sees the success toast
            setTimeout(() => {
                window.location.href = result.data.redirect;
            }, 800);
        } else {
            UI.toast(result.message, 'error');
            // Shake the form card for tactile feedback
            const card = document.querySelector('.auth-card');
            card.style.animation = 'none';
            card.offsetHeight;   // Force reflow to restart animation
            card.style.animation = 'shake 0.4s ease';
        }
    });

    // ── Shake animation (defined inline for single use) ────────────────
    const style = document.createElement('style');
    style.textContent = `
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20%       { transform: translateX(-8px); }
            40%       { transform: translateX(8px); }
            60%       { transform: translateX(-5px); }
            80%       { transform: translateX(5px); }
        }
    `;
    document.head.appendChild(style);

})();
</script>

</body>
</html>