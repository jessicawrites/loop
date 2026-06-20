<?php
/**
 * Loop — Registration Page
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/constants.php';
require_guest();
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <title>Create Account — Loop</title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css">
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

    <!-- ── Register Card ─────────────────────────────────────────────────── -->
    <div class="auth-card animate-slide-up">

        <h1 class="auth-title">Create account</h1>
        <p class="auth-subtitle">Join Loop and start messaging.</p>

        <form class="auth-form" id="registerForm" novalidate>

            <!-- Username -->
            <div class="input-group">
                <label class="input-label" for="username">
                    Username
                </label>
                <input
                    class="input-field"
                    type="text"
                    id="username"
                    name="username"
                    placeholder="e.g. tonny_k"
                    autocomplete="username"
                    autocapitalize="none"
                    spellcheck="false"
                    maxlength="20"
                    required
                >
                <span class="input-hint">
                    3–20 characters. Letters, numbers, underscores only.
                </span>
            </div>

            <!-- Display Name -->
            <div class="input-group">
                <label class="input-label" for="display_name">
                    Display Name
                </label>
                <input
                    class="input-field"
                    type="text"
                    id="display_name"
                    name="display_name"
                    placeholder="e.g. Tonny Ochieng"
                    autocomplete="name"
                    maxlength="50"
                    required
                >
                <span class="input-hint">
                    This is how others will see you in Loop.
                </span>
            </div>

            <!-- Email -->
            <div class="input-group">
                <label class="input-label" for="email">Email</label>
                <input
                    class="input-field"
                    type="email"
                    id="email"
                    name="email"
                    placeholder="you@example.com"
                    autocomplete="email"
                    required
                >
            </div>

            <!-- Password -->
            <div class="input-group">
                <label class="input-label" for="password">Password</label>
                <input
                    class="input-field"
                    type="password"
                    id="password"
                    name="password"
                    placeholder="At least 8 characters"
                    autocomplete="new-password"
                    required
                    style="padding-right: 48px;"
                >

                <!-- Live password strength meter -->
                <div id="strengthMeter" style="display:none; margin-top:6px;">
                    <div style="height:4px; border-radius:2px; background:var(--color-border); overflow:hidden;">
                        <div id="strengthBar"
                             style="height:100%; width:0%; border-radius:2px;
                                    transition:all 0.3s ease; background:var(--color-error);">
                        </div>
                    </div>
                    <span id="strengthLabel"
                          style="font-size:11px; color:var(--color-text-muted); margin-top:3px; display:block;">
                    </span>
                </div>
            </div>

            <!-- Confirm Password -->
            <div class="input-group">
                <label class="input-label" for="confirm_password">
                    Confirm Password
                </label>
                <input
                    class="input-field"
                    type="password"
                    id="confirm_password"
                    name="confirm_password"
                    placeholder="Repeat your password"
                    autocomplete="new-password"
                    required
                >
                <span id="matchHint" class="input-hint" style="display:none;"></span>
            </div>

            <!-- Submit -->
            <button
                type="submit"
                class="btn btn-primary btn-full"
                id="registerBtn"
                style="margin-top: 8px;"
            >
                Create Account
            </button>

        </form>
    </div>

    <!-- ── Footer Link ───────────────────────────────────────────────────── -->
    <div class="auth-footer">
        Already have an account?
        <a href="<?= APP_URL ?>/pages/login.php">Sign in</a>
    </div>

</div>

<script src="<?= APP_URL ?>/assets/js/utils.js"></script>
<script src="<?= APP_URL ?>/assets/js/ui.js"></script>
<script src="<?= APP_URL ?>/assets/js/api.js"></script>
<script>
(function () {

    const form           = document.getElementById('registerForm');
    const registerBtn    = document.getElementById('registerBtn');
    const passwordFld    = document.getElementById('password');
    const confirmFld     = document.getElementById('confirm_password');
    const strengthMeter  = document.getElementById('strengthMeter');
    const strengthBar    = document.getElementById('strengthBar');
    const strengthLabel  = document.getElementById('strengthLabel');
    const matchHint      = document.getElementById('matchHint');

    // ── Password Strength Meter ────────────────────────────────────────
    passwordFld.addEventListener('input', () => {
        const val = passwordFld.value;

        if (!val) {
            strengthMeter.style.display = 'none';
            return;
        }
        strengthMeter.style.display = 'block';

        // Score the password on four criteria
        let score = 0;
        if (val.length >= 8)                score++;   // Minimum length
        if (/[A-Z]/.test(val))              score++;   // Uppercase letter
        if (/[0-9]/.test(val))              score++;   // A number
        if (/[^A-Za-z0-9]/.test(val))       score++;   // Special character

        const levels = [
            { label: 'Too short',  color: 'var(--color-error)',   width: '15%' },
            { label: 'Weak',       color: 'var(--color-error)',   width: '30%' },
            { label: 'Fair',       color: 'var(--color-warning)', width: '55%' },
            { label: 'Good',       color: 'var(--color-warning)', width: '75%' },
            { label: 'Strong ✓',   color: 'var(--color-success)', width: '100%' },
        ];

        const level = levels[score];
        strengthBar.style.width      = level.width;
        strengthBar.style.background = level.color;
        strengthLabel.textContent    = level.label;
        strengthLabel.style.color    = level.color;
    });

    // ── Password Match Indicator ───────────────────────────────────────
    confirmFld.addEventListener('input', () => {
        if (!confirmFld.value) {
            matchHint.style.display = 'none';
            return;
        }
        matchHint.style.display = 'block';

        const match = passwordFld.value === confirmFld.value;
        matchHint.textContent   = match ? '✓ Passwords match' : '✕ Passwords do not match';
        matchHint.style.color   = match
            ? 'var(--color-success)'
            : 'var(--color-error)';

        confirmFld.classList.toggle('error', !match);
    });

    // ── Username Live Sanitizer ────────────────────────────────────────
    // Prevent invalid characters as the user types
    document.getElementById('username').addEventListener('input', function () {
        // Replace anything that isn't a letter, number, or underscore
        this.value = this.value.replace(/[^a-zA-Z0-9_]/g, '');
    });

    // ── Form Submission ────────────────────────────────────────────────
    form.addEventListener('submit', async (e) => {
        e.preventDefault();

        const username        = document.getElementById('username').value.trim();
        const display_name    = document.getElementById('display_name').value.trim();
        const email           = document.getElementById('email').value.trim();
        const password        = passwordFld.value;
        const confirm         = confirmFld.value;

        // ── Client-side validation (fast feedback) ─────────────────────
        if (!username || username.length < 3) {
            UI.toast('Username must be at least 3 characters.', 'error');
            document.getElementById('username').focus();
            return;
        }
        if (!display_name) {
            UI.toast('Please enter your display name.', 'error');
            document.getElementById('display_name').focus();
            return;
        }
        if (!email || !email.includes('@')) {
            UI.toast('Please enter a valid email address.', 'error');
            document.getElementById('email').focus();
            return;
        }
        if (password.length < 8) {
            UI.toast('Password must be at least 8 characters.', 'error');
            passwordFld.focus();
            return;
        }
        if (password !== confirm) {
            UI.toast('Passwords do not match.', 'error');
            confirmFld.focus();
            return;
        }

        // ── Build FormData ─────────────────────────────────────────────
        // FormData automatically handles encoding — clean and simple
        const formData = new FormData();
        formData.append('username',         username);
        formData.append('display_name',     display_name);
        formData.append('email',            email);
        formData.append('password',         password);
        formData.append('confirm_password', confirm);

        // ── Loading state ──────────────────────────────────────────────
        const restore = UI.setLoading(registerBtn, 'Creating account…');

        // ── API Call ───────────────────────────────────────────────────
        const result = await API.register(formData);
        restore();

        if (result.success) {
            UI.toast(result.message, 'success');
            setTimeout(() => {
                window.location.href = result.data.redirect;
            }, 800);
        } else {
            UI.toast(result.message, 'error');
        }
    });

})();
</script>

</body>
</html>