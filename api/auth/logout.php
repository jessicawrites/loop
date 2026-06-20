<?php
/**
 * Loop — Logout API Endpoint
 *
 * Accepts: POST request (GET would allow logout via link — a security risk)
 * Returns: JSON with redirect URL
 *
 * On logout we:
 * 1. Mark the user as offline in the database
 * 2. Destroy the PHP session completely
 * 3. Return a redirect URL for JavaScript to follow
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(false, 'Method not allowed.', [], 405);
}

if (!verify_csrf($_POST['csrf_token'] ?? null)) {
    send_json(false, 'Security check failed. Please refresh and try again.', [], 403);
}

start_session();

// ── Mark User Offline ──────────────────────────────────────────────────────
// Only update DB if someone is actually logged in
if (is_logged_in()) {
    try {
        $pdo  = get_db();
        $stmt = $pdo->prepare('
            UPDATE online_status
            SET    is_online = 0, last_seen = NOW()
            WHERE  user_id = ?
        ');
        $stmt->execute([current_user_id()]);
    } catch (PDOException $e) {
        // Log but don't block logout — user experience first
        error_log('Failed to update online status on logout: ' . $e->getMessage());
    }
}

// ── Destroy Session ────────────────────────────────────────────────────────
logout_user();

// ── Respond ────────────────────────────────────────────────────────────────
send_json(true, 'You have been logged out.', [
    'redirect' => APP_URL . '/pages/login.php'
]);