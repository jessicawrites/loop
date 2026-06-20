<?php
/**
 * Loop — Password Change API
 *
 * POST /api/users/password.php
 *
 * Requires the current password to be correct before allowing a change.
 * This prevents someone who grabbed an unlocked phone from changing
 * the password without knowing the original.
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

start_session();
if (!is_logged_in()) {
    send_json(false, 'Unauthorised.', [], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(false, 'Method not allowed.', [], 405);
}

if (!verify_csrf($_POST['csrf_token'] ?? null)) {
    send_json(false, 'Security check failed. Please refresh and try again.', [], 403);
}

$me  = current_user_id();
$pdo = get_db();

// ── Collect input ──────────────────────────────────────────────────────────
$current_password  = $_POST['current_password']  ?? '';
$new_password      = $_POST['new_password']      ?? '';
$confirm_password  = $_POST['confirm_password']  ?? '';

// ── Validate ───────────────────────────────────────────────────────────────
if (empty($current_password)) {
    send_json(false, 'Please enter your current password.', [], 422);
}
if (strlen($new_password) < 8) {
    send_json(false, 'New password must be at least 8 characters.', [], 422);
}
if ($new_password !== $confirm_password) {
    send_json(false, 'New passwords do not match.', [], 422);
}
if ($current_password === $new_password) {
    send_json(false, 'New password must be different from your current password.', [], 422);
}

// ── Fetch current hash ─────────────────────────────────────────────────────
$stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$me]);
$user = $stmt->fetch();

if (!$user || !password_verify($current_password, $user['password_hash'])) {
    send_json(false, 'Current password is incorrect.', [], 401);
}

// ── Hash new password ──────────────────────────────────────────────────────
$new_hash = password_hash($new_password, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);

// ── Update ─────────────────────────────────────────────────────────────────
try {
    $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    $stmt->execute([$new_hash, $me]);
} catch (PDOException $e) {
    error_log('Password change failed: ' . $e->getMessage());
    send_json(false, 'Could not update password. Please try again.', [], 500);
}

// ── Regenerate session for security ───────────────────────────────────────
// After a password change, issue a fresh session ID.
session_regenerate_id(true);

send_json(true, 'Password changed successfully.');