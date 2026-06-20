<?php
/**
 * Loop — Online Status Heartbeat API
 *
 * POST /api/users/status.php   → mark online, refresh last_seen (heartbeat)
 * POST /api/users/status.php   with status=offline → mark offline explicitly
 *
 * Called automatically every ~25s by app.js while any page is open,
 * and once more (via sendBeacon) when the tab/browser closes.
 *
 * A user is considered "online" elsewhere in the app if last_seen is
 * within the last 5 minutes. As long as the heartbeat keeps firing,
 * is_online stays 1 and last_seen keeps refreshing. If the heartbeat
 * stops — tab closed, laptop slept, network dropped — the user simply
 * ages out of the 5-minute window with no further action needed.
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

start_session();
if (!is_logged_in()) {
    // Heartbeats from a logged-out session are simply ignored — not an error,
    // since this can legitimately happen in the brief window right after logout.
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

// ── Determine desired status ────────────────────────────────────────────────
// Default to "online" (a regular heartbeat). The frontend explicitly sends
// status=offline only when using sendBeacon on page unload.
$status    = $_POST['status'] ?? 'online';
$is_online = ($status === 'offline') ? 0 : 1;

try {
    $stmt = $pdo->prepare('
        UPDATE online_status
        SET    is_online = ?, last_seen = NOW()
        WHERE  user_id    = ?
    ');
    $stmt->execute([$is_online, $me]);

    // Defensive fallback: if for any reason this user has no online_status
    // row (shouldn't happen given registration always creates one, but
    // protects against any data drift), insert it now.
    if ($stmt->rowCount() === 0) {
        $stmt = $pdo->prepare('
            INSERT INTO online_status (user_id, is_online, last_seen)
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE is_online = VALUES(is_online), last_seen = NOW()
        ');
        $stmt->execute([$me, $is_online]);
    }

} catch (PDOException $e) {
    error_log('Heartbeat update failed: ' . $e->getMessage());
    send_json(false, 'Could not update status.', [], 500);
}

send_json(true, '', ['is_online' => (bool) $is_online]);