<?php
/**
 * Loop — Profile Update API
 *
 * POST /api/users/profile.php
 *
 * Accepts: display_name, bio, and optional avatar file
 * Returns: JSON with updated user data
 *
 * Three things can happen in one request:
 * 1. Text fields (display_name, bio) are always updated
 * 2. Avatar is updated only if a new file was uploaded
 * 3. Session is refreshed so the header avatar updates instantly
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/validation.php';

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

// ── Collect text fields ────────────────────────────────────────────────────
$data = [
    'display_name' => trim($_POST['display_name'] ?? ''),
    'bio'          => trim($_POST['bio']          ?? ''),
];

// ── Validate text fields ───────────────────────────────────────────────────
$errors = validate_profile($data);
if (!empty($errors)) {
    send_json(false, $errors[0], [], 422);
}

// ── Handle Avatar Upload ───────────────────────────────────────────────────
$new_avatar_filename = null;   // null means "no change"

if (!empty($_FILES['avatar']['name'])) {
    $file = $_FILES['avatar'];

    // ── File error check ───────────────────────────────────────────────
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $upload_errors = [
            UPLOAD_ERR_INI_SIZE   => 'File is too large (server limit).',
            UPLOAD_ERR_FORM_SIZE  => 'File is too large.',
            UPLOAD_ERR_PARTIAL    => 'File upload was interrupted.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server upload folder missing.',
            UPLOAD_ERR_CANT_WRITE => 'Server cannot write file.',
        ];
        $msg = $upload_errors[$file['error']] ?? 'Upload failed.';
        send_json(false, $msg, [], 422);
    }

    // ── File size check ────────────────────────────────────────────────
    if ($file['size'] > MAX_AVATAR_SIZE) {
        send_json(false, 'Avatar must be under 2MB.', [], 422);
    }

    // ── MIME type check (read the actual file bytes, not just extension) ──
    // finfo reads the real file signature — a renamed .exe won't pass this.
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mime     = $finfo->file($file['tmp_name']);

    if (!in_array($mime, ALLOWED_IMAGE_TYPES, true)) {
        send_json(false, 'Avatar must be a JPEG, PNG, or WebP image.', [], 422);
    }

    // ── Generate safe filename ─────────────────────────────────────────
    // Never use the original filename — it could contain malicious characters.
    $ext_map  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $ext      = $ext_map[$mime];
    $filename = unique_filename($ext);

    // ── Move uploaded file to permanent location ───────────────────────
    $destination = UPLOADS_PATH . $filename;

    if (!is_dir(UPLOADS_PATH)) {
        mkdir(UPLOADS_PATH, 0755, true);
    }

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        error_log('Failed to move uploaded avatar to: ' . $destination);
        send_json(false, 'Could not save avatar. Please try again.', [], 500);
    }

    $new_avatar_filename = $filename;
}

// ── Build the UPDATE query dynamically ────────────────────────────────────
// We only update avatar column when a new file was actually uploaded.
try {
    if ($new_avatar_filename) {

        // ── Delete old avatar file to save disk space ──────────────────
        $stmt = $pdo->prepare('SELECT avatar FROM users WHERE id = ?');
        $stmt->execute([$me]);
        $old  = $stmt->fetchColumn();

        if ($old && file_exists(UPLOADS_PATH . $old)) {
            unlink(UPLOADS_PATH . $old);
        }

        $stmt = $pdo->prepare('
            UPDATE users
            SET    display_name = ?,
                   bio          = ?,
                   avatar       = ?
            WHERE  id           = ?
        ');
        $stmt->execute([
            $data['display_name'],
            $data['bio'] ?: null,
            $new_avatar_filename,
            $me
        ]);

    } else {

        $stmt = $pdo->prepare('
            UPDATE users
            SET    display_name = ?,
                   bio          = ?
            WHERE  id           = ?
        ');
        $stmt->execute([
            $data['display_name'],
            $data['bio'] ?: null,
            $me
        ]);
    }

} catch (PDOException $e) {
    error_log('Profile update failed: ' . $e->getMessage());
    send_json(false, 'Could not save changes. Please try again.', [], 500);
}

// ── Refresh session so header reflects changes immediately ─────────────────
$_SESSION['display_name'] = $data['display_name'];
if ($new_avatar_filename) {
    $_SESSION['avatar'] = $new_avatar_filename;
}

// ── Return updated data to JavaScript ─────────────────────────────────────
send_json(true, 'Profile updated successfully.', [
    'display_name' => $data['display_name'],
    'avatar_url'   => avatar_url($_SESSION['avatar'] ?? null),
]);