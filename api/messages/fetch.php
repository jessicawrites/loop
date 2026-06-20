<?php
/**
 * Loop — Fetch Messages API
 *
 * GET /api/messages/fetch.php?conversation_id=5&last_id=120
 *
 * Two modes:
 * - last_id = 0   → initial load, returns the most recent 50 messages
 * - last_id > 0   → polling mode, returns only messages newer than that ID
 *
 * Also marks incoming messages as read when this user fetches them,
 * since opening/polling a conversation implies the user has seen it.
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

$conversation_id = (int) ($_GET['conversation_id'] ?? 0);
$last_id          = (int) ($_GET['last_id'] ?? 0);

if (!$conversation_id) {
    send_json(false, 'Invalid conversation.', [], 400);
}

// ── Membership check ───────────────────────────────────────────────────────
// Same rule as send.php — never trust a conversation_id without verifying
// the requesting user actually belongs to it.
$stmt = $pdo->prepare('
    SELECT id FROM conversation_members
    WHERE  conversation_id = ? AND user_id = ?
    LIMIT  1
');
$stmt->execute([$conversation_id, $me]);
if (!$stmt->fetch()) {
    send_json(false, 'You are not part of this conversation.', [], 403);
}

// ── Fetch the OTHER user's live online status ──────────────────────────────
// Piggybacks on the existing poll cycle so the chat header can show
// real-time presence without a separate timer.
$stmt = $pdo->prepare('
    SELECT
        u.id,
        CASE
            WHEN os.is_online = 1
            AND  os.last_seen >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
            THEN 1 ELSE 0
        END AS is_online
    FROM conversation_members cm
    INNER JOIN users u ON u.id = cm.user_id
    LEFT  JOIN online_status os ON os.user_id = u.id
    WHERE cm.conversation_id = ? AND cm.user_id != ?
    LIMIT 1
');
$stmt->execute([$conversation_id, $me]);
$other_status = $stmt->fetch();
$other_is_online = $other_status ? (bool) $other_status['is_online'] : false;

// ── Fetch messages ─────────────────────────────────────────────────────────
if ($last_id > 0) {
    // Polling mode — only messages newer than what the client already has
    $stmt = $pdo->prepare('
        SELECT
            m.id, m.conversation_id, m.sender_id, m.content,
            m.message_type, m.image_path, m.is_read, m.created_at,
            u.display_name AS sender_name, u.avatar AS sender_avatar
        FROM   messages m
        INNER  JOIN users u ON u.id = m.sender_id
        WHERE  m.conversation_id = ? AND m.id > ?
        ORDER  BY m.created_at ASC
    ');
    $stmt->execute([$conversation_id, $last_id]);
} else {
    // Initial load — most recent 50, returned in ascending order for display
    $stmt = $pdo->prepare('
        SELECT * FROM (
            SELECT
                m.id, m.conversation_id, m.sender_id, m.content,
                m.message_type, m.image_path, m.is_read, m.created_at,
                u.display_name AS sender_name, u.avatar AS sender_avatar
            FROM   messages m
            INNER  JOIN users u ON u.id = m.sender_id
            WHERE  m.conversation_id = ?
            ORDER  BY m.created_at DESC
            LIMIT  ?
        ) recent
        ORDER BY created_at ASC
    ');
    $stmt->bindValue(1, $conversation_id, PDO::PARAM_INT);
    $stmt->bindValue(2, MESSAGES_PER_PAGE, PDO::PARAM_INT);
    $stmt->execute();
}

$messages = $stmt->fetchAll();

foreach ($messages as &$m) {
    $m['id']              = (int) $m['id'];
    $m['conversation_id'] = (int) $m['conversation_id'];
    $m['sender_id']       = (int) $m['sender_id'];
    $m['is_read']         = (bool) $m['is_read'];
    $m['sender_avatar_url'] = avatar_url($m['sender_avatar']);
    unset($m['sender_avatar']);

    // Build full image URL, mirroring send.php's response shape
    $m['image_url'] = ($m['message_type'] === 'image' && $m['image_path'])
        ? MESSAGE_IMAGES_URL . $m['image_path']
        : null;
}
unset($m);

// ── Check for read-status updates on MY OWN sent messages ──────────────────
// The poller only returns messages newer than last_id, but a message I sent
// earlier might have JUST been read by the other person. Without this, my
// own read-receipt ticks never update — the recipient's fetch.php marks
// is_read=1 in the DB, but my browser has no way to learn about it.
// We send back the FULL list of currently-read message IDs I sent — simpler
// and more robust than diffing "newly read since last check," and the
// payload stays tiny since it's just a list of integers.
$stmt = $pdo->prepare('
    SELECT id FROM messages
    WHERE  conversation_id = ? AND sender_id = ? AND is_read = 1
');
$stmt->execute([$conversation_id, $me]);
$read_message_ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

// ── Mark incoming messages as read ──────────────────────────────────────────
// Any message in this conversation NOT sent by me, still unread, gets
// marked read now — since fetching this conversation means I'm viewing it.
try {
    $stmt = $pdo->prepare('
        UPDATE messages
        SET    is_read = 1
        WHERE  conversation_id = ? AND sender_id != ? AND is_read = 0
    ');
    $stmt->execute([$conversation_id, $me]);
} catch (PDOException $e) {
    // Non-fatal — log but don't fail the whole request over a read-receipt update
    error_log('Failed to mark messages as read: ' . $e->getMessage());
}

// ── Update last_read_at for this user's membership row ──────────────────────
// Used for unread-count calculations elsewhere (e.g. home screen).
try {
    $stmt = $pdo->prepare('
        UPDATE conversation_members
        SET    last_read_at = NOW()
        WHERE  conversation_id = ? AND user_id = ?
    ');
    $stmt->execute([$conversation_id, $me]);
} catch (PDOException $e) {
    error_log('Failed to update last_read_at: ' . $e->getMessage());
}

send_json(true, '', [
    'messages'         => $messages,
    'read_message_ids' => $read_message_ids,
    'other_is_online'  => $other_is_online,
]);