<?php
/**
 * Loop — Conversations API
 *
 * GET  → returns all conversations for the logged-in user
 * POST → finds or creates a conversation with another user
 *
 * Requires login.
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

start_session();
if (!is_logged_in()) {
    send_json(false, 'Unauthorised.', [], 401);
}

$me  = current_user_id();
$pdo = get_db();
$method = $_SERVER['REQUEST_METHOD'];

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// GET — Return conversation list
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
if ($method === 'GET') {

    /**
     * This query does several things at once:
     *
     * 1. Finds every conversation the logged-in user is a member of
     * 2. Finds the OTHER person in each conversation
     * 3. Finds the last message sent in each conversation
     * 4. Counts unread messages (messages not sent by me, not yet read)
     * 5. Orders by most recently updated first
     */
    $stmt = $pdo->prepare('
        SELECT
            c.id                    AS conversation_id,
            c.updated_at,

            -- The other person
            u.id                    AS other_user_id,
            u.display_name          AS other_display_name,
            u.avatar                AS other_avatar,

            -- Online status of the other person
            CASE
                WHEN os.is_online = 1
                AND  os.last_seen >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                THEN 1
                ELSE 0
            END                     AS is_online,

            -- Last message preview
            m.content               AS last_message,
            m.sender_id             AS last_sender_id,
            m.created_at            AS last_message_at,
            m.message_type          AS last_message_type,

            -- Unread count — messages NOT sent by me that I haven\'t read
            (
                SELECT COUNT(*)
                FROM   messages m2
                WHERE  m2.conversation_id = c.id
                AND    m2.sender_id      != :me_unread
                AND    m2.is_read         = 0
            )                       AS unread_count

        FROM conversations c

        -- I must be a member
        INNER JOIN conversation_members me_member
            ON  me_member.conversation_id = c.id
            AND me_member.user_id         = :me_member

        -- The other person must be a member
        INNER JOIN conversation_members other_member
            ON  other_member.conversation_id = c.id
            AND other_member.user_id        != :me_other

        -- Get the other person\'s profile
        INNER JOIN users u
            ON u.id = other_member.user_id

        -- Get their online status
        LEFT JOIN online_status os
            ON os.user_id = u.id

        -- Get the most recent message in this conversation
        LEFT JOIN messages m
            ON m.id = (
                SELECT id FROM messages
                WHERE  conversation_id = c.id
                ORDER  BY created_at DESC
                LIMIT  1
            )

        ORDER BY c.updated_at DESC
        LIMIT  :limit
    ');

    $stmt->bindValue(':me_member', $me, PDO::PARAM_INT);
    $stmt->bindValue(':me_other',  $me, PDO::PARAM_INT);
    $stmt->bindValue(':me_unread', $me, PDO::PARAM_INT);
    $stmt->bindValue(':limit', CONVERSATIONS_PER_PAGE, PDO::PARAM_INT);
    $stmt->execute();

    $conversations = $stmt->fetchAll();

    // ── Format for frontend ────────────────────────────────────────────
    foreach ($conversations as &$conv) {

        // Resolve avatar URL
        $conv['other_avatar_url'] = avatar_url($conv['other_avatar']);
        unset($conv['other_avatar']);

        // Format timestamp for display
        $conv['time_label'] = !empty($conv['last_message_at'])
            ? time_ago($conv['last_message_at'])
            : time_ago($conv['updated_at']);

        // Truncate long messages for preview
        if ($conv['last_message_type'] === 'image') {
            $conv['last_message_preview'] = '📷 Photo';
        } elseif (!empty($conv['last_message'])) {
            $conv['last_message_preview'] = mb_strlen($conv['last_message']) > 55
                ? mb_substr($conv['last_message'], 0, 55) . '…'
                : $conv['last_message'];
        } else {
            $conv['last_message_preview'] = 'No messages yet';
        }

        // Cast types JavaScript expects
        $conv['unread_count']   = (int) $conv['unread_count'];
        $conv['is_online']      = (bool) $conv['is_online'];
        $conv['conversation_id']= (int) $conv['conversation_id'];
        $conv['other_user_id']  = (int) $conv['other_user_id'];
        $conv['last_sender_id'] = (int) ($conv['last_sender_id'] ?? 0);
    }
    unset($conv);

    send_json(true, '', ['conversations' => $conversations]);
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// POST — Find or create a conversation
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
elseif ($method === 'POST') {

    $other_user_id = (int) ($_POST['user_id'] ?? 0);

    if (!$other_user_id || $other_user_id === $me) {
        send_json(false, 'Invalid user.', [], 400);
    }

    // ── Verify the other user exists ───────────────────────────────────
    $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$other_user_id]);
    if (!$stmt->fetch()) {
        send_json(false, 'User not found.', [], 404);
    }

    // ── Check if conversation already exists ───────────────────────────
    // We look for a conversation where BOTH users are members.
    // The HAVING COUNT(*) = 2 ensures BOTH are present — not just one.
    $stmt = $pdo->prepare('
        SELECT c.id
        FROM   conversations c
        INNER  JOIN conversation_members cm
            ON cm.conversation_id = c.id
        WHERE  cm.user_id IN (?, ?)
        GROUP  BY c.id
        HAVING COUNT(DISTINCT cm.user_id) = 2
        LIMIT  1
    ');
    $stmt->execute([$me, $other_user_id]);
    $existing = $stmt->fetch();

    if ($existing) {
        // Conversation already exists — just return its ID
        send_json(true, '', [
            'conversation_id' => (int) $existing['id'],
            'created'         => false
        ]);
    }

    // ── Create new conversation ────────────────────────────────────────
    // Transaction: both the conversation AND both member rows
    // must be created together, or not at all.
    try {
        $pdo->beginTransaction();

        // Insert conversation
        $stmt = $pdo->prepare('
            INSERT INTO conversations (created_by) VALUES (?)
        ');
        $stmt->execute([$me]);
        $conv_id = (int) $pdo->lastInsertId();

        // Insert both members
        $stmt = $pdo->prepare('
            INSERT INTO conversation_members (conversation_id, user_id)
            VALUES (?, ?), (?, ?)
        ');
        $stmt->execute([$conv_id, $me, $conv_id, $other_user_id]);

        $pdo->commit();

    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('Failed to create conversation: ' . $e->getMessage());
        send_json(false, 'Could not start conversation. Please try again.', [], 500);
    }

    send_json(true, '', [
        'conversation_id' => $conv_id,
        'created'         => true
    ]);

} else {
    send_json(false, 'Method not allowed.', [], 405);
}