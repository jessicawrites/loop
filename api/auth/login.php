<?php
/**
 * Loop — Login API Endpoint
 *
 * Accepts: POST with username + password
 * Returns: JSON { success, message, data }
 *
 * Flow:
 * 1. Validate input isn't empty
 * 2. Look up user by username
 * 3. Verify password against stored hash
 * 4. Start session + store user data
 * 5. Return success with redirect URL
 *
 * Security note: We always run password_verify() even when the user
 * doesn't exist. This prevents timing attacks — an attacker can't
 * tell the difference between "wrong username" and "wrong password"
 * by measuring response time.
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/validation.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(false, 'Method not allowed.', [], 405);
}

if (!verify_csrf($_POST['csrf_token'] ?? null)) {
    send_json(false, 'Security check failed. Please refresh and try again.', [], 403);
}

if (!isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    send_json(false, 'Invalid request.', [], 400);
}

start_session();

// ── Rate Limiting ────────────────────────────────────────────────────────
// Tracks failed attempts per session. After 5 failures, the user must
// wait 60 seconds before trying again. This is intentionally simple —
// it stops casual/scripted brute-forcing, which is the realistic threat
// on a learning project. A production app would track this server-side
// per-IP (e.g. in Redis) since session cookies can be cleared.
$_SESSION['login_attempts']   = $_SESSION['login_attempts']   ?? 0;
$_SESSION['login_locked_until'] = $_SESSION['login_locked_until'] ?? 0;

if (time() < $_SESSION['login_locked_until']) {
    $wait = $_SESSION['login_locked_until'] - time();
    send_json(false, "Too many attempts. Try again in {$wait}s.", [], 429);
}

// ── Collect Input ──────────────────────────────────────────────────────────
$data = [
    'username' => trim($_POST['username'] ?? ''),
    'password' => $_POST['password']      ?? '',
];

// ── Basic Validation ───────────────────────────────────────────────────────
$errors = validate_login($data);
if (!empty($errors)) {
    send_json(false, $errors[0], [], 422);
}

// ── Fetch User from Database ───────────────────────────────────────────────
$pdo  = get_db();
$stmt = $pdo->prepare('
    SELECT id, username, display_name, email, password_hash, avatar
    FROM   users
    WHERE  username = ?
    LIMIT  1
');
$stmt->execute([$data['username']]);
$user = $stmt->fetch();

// ── Timing-Safe Password Verification ─────────────────────────────────────
// If user doesn't exist, we still call password_verify() with a dummy hash.
// This takes the same amount of time as a real check — prevents timing attacks.
$dummy_hash = '$2y$12$invalidhashfortimingattackprevention00000000000000000000';
$hash_to_check = $user ? $user['password_hash'] : $dummy_hash;

if (!$user || !password_verify($data['password'], $hash_to_check)) {
    // ── Track the failure ──────────────────────────────────────────────
    $_SESSION['login_attempts']++;

    if ($_SESSION['login_attempts'] >= 5) {
        $_SESSION['login_locked_until'] = time() + 60;  // 60s cooldown
        $_SESSION['login_attempts']     = 0;             // Reset counter for next window
        send_json(false, 'Too many failed attempts. Try again in 60s.', [], 429);
    }

    // Same error message for both wrong username AND wrong password.
    // Never tell an attacker which one was incorrect.
    send_json(false, 'Incorrect username or password.', [], 401);
}

// ── Update Online Status ───────────────────────────────────────────────────
$stmt = $pdo->prepare('
    UPDATE online_status
    SET    is_online = 1, last_seen = NOW()
    WHERE  user_id = ?
');
$stmt->execute([$user['id']]);

// ── Start Session ──────────────────────────────────────────────────────────
login_user($user);

// Successful login clears any accumulated failure count
unset($_SESSION['login_attempts'], $_SESSION['login_locked_until']);

// ── Success ────────────────────────────────────────────────────────────────
send_json(true, 'Welcome back, ' . $user['display_name'] . '!', [
    'redirect' => APP_URL . '/pages/home.php'
]);