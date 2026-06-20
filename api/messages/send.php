<?php
/**
 * Loop — Send Message API
 *
 * POST /api/messages/send.php
 * Body: conversation_id, content (optional if image attached), image (optional file)
 *
 * Flow:
 * 1. Verify the user is logged in and a member of the conversation
 * 2. Validate text content OR image (at least one is required)
 * 3. If an image was uploaded, validate + store it
 * 4. Insert the message
 * 5. Touch the conversation's updated_at
 * 6. Create a notification for the OTHER member of the conversation
 * 7. Return the fully formatted message
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
$conversation_id = (int) ($_POST['conversation_id'] ?? 0);
$content          = trim($_POST['content'] ?? '');
$has_image        = !empty($_FILES['image']['name']);

// ── Validate ───────────────────────────────────────────────────────────────
if (!$conversation_id) {
    send_json(false, 'Invalid conversation.', [], 400);
}
if ($content === '' && !$has_image) {
    send_json(false, 'Message cannot be empty.', [], 422);
}
if (mb_strlen($content) > 4000) {
    send_json(false, 'Message is too long.', [], 422);
}

// ── Membership check ───────────────────────────────────────────────────────
// Critical — without it, any logged-in user could POST into any conversation_id.
$stmt = $pdo->prepare('
    SELECT id FROM conversation_members
    WHERE  conversation_id = ? AND user_id = ?
    LIMIT  1
');
$stmt->execute([$conversation_id, $me]);
if (!$stmt->fetch()) {
    send_json(false, 'You are not part of this conversation.', [], 403);
}

// ── Handle image upload (if present) ────────────────────────────────────────
$message_type = 'text';
$image_path   = null;

if ($has_image) {
    $file = $_FILES['image'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $upload_errors = [
            UPLOAD_ERR_INI_SIZE   => 'Image is too large (server limit).',
            UPLOAD_ERR_FORM_SIZE  => 'Image is too large.',
            UPLOAD_ERR_PARTIAL    => 'Image upload was interrupted.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server upload folder missing.',
            UPLOAD_ERR_CANT_WRITE => 'Server cannot write file.',
        ];
        send_json(false, $upload_errors[$file['error']] ?? 'Upload failed.', [], 422);
    }

    if ($file['size'] > MAX_MESSAGE_IMAGE_SIZE) {
        send_json(false, 'Image must be under 5MB.', [], 422);
    }

    // Read actual file bytes, not just the extension — same defence as avatar uploads
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);

    if (!in_array($mime, ALLOWED_IMAGE_TYPES, true)) {
        send_json(false, 'Image must be a JPEG, PNG, or WebP file.', [], 422);
    }

    $ext_map  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $filename = unique_filename($ext_map[$mime]);
    $destination = MESSAGE_IMAGES_PATH . $filename;

    if (!is_dir(MESSAGE_IMAGES_PATH)) {
        mkdir(MESSAGE_IMAGES_PATH, 0755, true);
    }

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        error_log('Failed to move uploaded message image to: ' . $destination);
        send_json(false, 'Could not save image. Please try again.', [], 500);
    }

    $message_type = 'image';
    $image_path   = $filename;
}

// ── Insert message + touch conversation + create notification ──────────────
// All three happen in one transaction: either the full send succeeds,
// or nothing is left half-written (e.g. a message with no notification,
// or a touched conversation with no actual message).
try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('
        INSERT INTO messages (conversation_id, sender_id, content, message_type, image_path)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->execute([$conversation_id, $me, $content, $message_type, $image_path]);
    $message_id = (int) $pdo->lastInsertId();

    // Bump conversation's updated_at so it sorts to the top of the home list
    $stmt = $pdo->prepare('
        UPDATE conversations SET updated_at = NOW() WHERE id = ?
    ');
    $stmt->execute([$conversation_id]);

    // ── Find the OTHER member to notify ──────────────────────────────────
    $stmt = $pdo->prepare('
        SELECT user_id FROM conversation_members
        WHERE  conversation_id = ? AND user_id != ?
        LIMIT  1
    ');
    $stmt->execute([$conversation_id, $me]);
    $recipient_id = $stmt->fetchColumn();

    if ($recipient_id) {
        // Get my display name for the notification text
        $stmt = $pdo->prepare('SELECT display_name FROM users WHERE id = ?');
        $stmt->execute([$me]);
        $my_name = $stmt->fetchColumn();

        $notif_message = $message_type === 'image'
            ? "{$my_name} sent a photo"
            : "{$my_name}: " . (mb_strlen($content) > 80 ? mb_substr($content, 0, 80) . '…' : $content);

        $stmt = $pdo->prepare('
            INSERT INTO notifications (user_id, type, reference_id, message)
            VALUES (?, \'new_message\', ?, ?)
        ');
        $stmt->execute([$recipient_id, $conversation_id, $notif_message]);
    }

    $pdo->commit();

} catch (PDOException $e) {
    $pdo->rollBack();

    // Clean up an orphaned uploaded file if the DB transaction failed
    if ($image_path && file_exists(MESSAGE_IMAGES_PATH . $image_path)) {
        unlink(MESSAGE_IMAGES_PATH . $image_path);
    }

    error_log('Send message failed: ' . $e->getMessage());
    send_json(false, 'Could not send message. Please try again.', [], 500);
}

// ── Fetch the message back fully formatted ──────────────────────────────────
$stmt = $pdo->prepare('
    SELECT
        m.id, m.conversation_id, m.sender_id, m.content,
        m.message_type, m.image_path, m.is_read, m.created_at,
        u.display_name AS sender_name, u.avatar AS sender_avatar
    FROM   messages m
    INNER  JOIN users u ON u.id = m.sender_id
    WHERE  m.id = ?
');
$stmt->execute([$message_id]);
$message = $stmt->fetch();

$message['id']              = (int) $message['id'];
$message['conversation_id'] = (int) $message['conversation_id'];
$message['sender_id']       = (int) $message['sender_id'];
$message['is_read']         = (bool) $message['is_read'];
$message['sender_avatar_url'] = avatar_url($message['sender_avatar']);
unset($message['sender_avatar']);

// Build the full image URL for the frontend, if applicable
if ($message['message_type'] === 'image' && $message['image_path']) {
    $message['image_url'] = MESSAGE_IMAGES_URL . $message['image_path'];
} else {
    $message['image_url'] = null;
}

send_json(true, '', ['message' => $message]);