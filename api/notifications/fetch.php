<?php
/**
 * Loop — Notifications Fetch API
 *
 * GET /api/notifications/fetch.php
 *
 * Returns the current user's 20 most recent notifications, newest first,
 * plus a separate unread_count for the bell badge.
 *
 * Notifications are currently created only on new messages (see send.php),
 * but the table/type column is designed to support other kinds later
 * (e.g. 'new_contact') without any schema change.
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

start_session();
if (!is_logged_in()) {
    send_json(false, 'Unauthorised.', [], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json(false, 'Method not allowed.', [], 405);
}

$me  = current_user_id();
$pdo = get_db();

// ── Fetch recent notifications ──────────────────────────────────────────────
$stmt = $pdo->prepare('
    SELECT id, type, reference_id, message, is_read, created_at
    FROM   notifications
    WHERE  user_id = ?
    ORDER  BY created_at DESC
    LIMIT  20
');
$stmt->execute([$me]);
$notifications = $stmt->fetchAll();

foreach ($notifications as &$n) {
    $n['id']           = (int) $n['id'];
    $n['reference_id'] = $n['reference_id'] !== null ? (int) $n['reference_id'] : null;
    $n['is_read']      = (bool) $n['is_read'];
    $n['time_label']   = time_ago($n['created_at']);
}
unset($n);

// ── Unread count (for the badge — independent of the 20-item limit above) ──
$stmt = $pdo->prepare('
    SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0
');
$stmt->execute([$me]);
$unread_count = (int) $stmt->fetchColumn();

// ── If the request asks to mark all as read, do so ──────────────────────────
// The dropdown calls this with ?mark_read=1 when it's opened, so notifications
// are considered "seen" once the user actually looks at the panel.
if (!empty($_GET['mark_read'])) {
    try {
        $stmt = $pdo->prepare('
            UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0
        ');
        $stmt->execute([$me]);
    } catch (PDOException $e) {
        error_log('Failed to mark notifications read: ' . $e->getMessage());
    }
}

send_json(true, '', [
    'notifications' => $notifications,
    'unread_count'  => $unread_count,
]);