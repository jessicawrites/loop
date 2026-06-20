<?php
/**
 * Loop — Registration API Endpoint
 *
 * Accepts: POST request with form fields
 * Returns: JSON { success, message, data }
 *
 * Flow:
 * 1. Validate all input fields
 * 2. Check username and email aren't already taken
 * 3. Hash the password with bcrypt
 * 4. Insert the new user into the database
 * 5. Create their online_status row
 * 6. Log them in immediately (start session)
 * 7. Return success
 */

// ── Bootstrap ─────────────────────────────────────────────────────────────
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/validation.php';

// Only accept POST requests — reject anything else
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(false, 'Method not allowed.', [], 405);
}

// Only respond to AJAX requests — basic CSRF protection
if (!isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    send_json(false, 'Invalid request.', [], 400);
}

start_session();

// ── Collect Input ──────────────────────────────────────────────────────────
// trim() removes accidental leading/trailing spaces
$data = [
    'username'         => trim($_POST['username']         ?? ''),
    'display_name'     => trim($_POST['display_name']     ?? ''),
    'email'            => trim($_POST['email']            ?? ''),
    'password'         => $_POST['password']              ?? '',
    'confirm_password' => $_POST['confirm_password']      ?? '',
];

// ── Client-side Mirrored Validation ───────────────────────────────────────
// We validate on the frontend too, but NEVER trust the frontend alone.
// A user can bypass JavaScript — the backend must always re-validate.
$errors = validate_registration($data);
if (!empty($errors)) {
    send_json(false, $errors[0], [], 422);
}

// ── Database Duplicate Check ───────────────────────────────────────────────
$pdo = get_db();

// Check username
$stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
$stmt->execute([$data['username']]);
if ($stmt->fetch()) {
    send_json(false, 'That username is already taken. Try another one.', [], 409);
}

// Check email
$stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
$stmt->execute([$data['email']]);
if ($stmt->fetch()) {
    send_json(false, 'An account with that email already exists.', [], 409);
}

// ── Password Hashing ───────────────────────────────────────────────────────
// password_hash() uses bcrypt automatically. BCRYPT_COST is defined in
// constants.php as 12 — high enough to be secure, low enough to be fast.
// The hash includes the salt automatically — we never store salt separately.
$password_hash = password_hash($data['password'], PASSWORD_BCRYPT, [
    'cost' => BCRYPT_COST
]);

// ── Insert User ────────────────────────────────────────────────────────────
// A transaction ensures BOTH inserts succeed or NEITHER does.
// Without this, we could end up with a user but no online_status row.
try {
    $pdo->beginTransaction();

    // Insert into users table
    $stmt = $pdo->prepare('
        INSERT INTO users (username, display_name, email, password_hash)
        VALUES (?, ?, ?, ?)
    ');
    $stmt->execute([
        $data['username'],
        $data['display_name'],
        strtolower($data['email']),   // Emails stored lowercase
        $password_hash
    ]);

    $new_user_id = (int) $pdo->lastInsertId();

    // Every user needs an online_status row immediately
    $stmt = $pdo->prepare('
        INSERT INTO online_status (user_id, is_online)
        VALUES (?, 0)
    ');
    $stmt->execute([$new_user_id]);

    $pdo->commit();

} catch (PDOException $e) {
    $pdo->rollBack();
    error_log('Registration failed: ' . $e->getMessage());
    send_json(false, 'Registration failed. Please try again.', [], 500);
}

// ── Auto Login After Registration ─────────────────────────────────────────
// Fetch the full user row so we can populate the session properly
$stmt = $pdo->prepare('SELECT id, username, display_name, avatar FROM users WHERE id = ?');
$stmt->execute([$new_user_id]);
$new_user = $stmt->fetch();

login_user($new_user);

// ── Success ────────────────────────────────────────────────────────────────
send_json(true, 'Account created successfully. Welcome to Loop!', [
    'redirect' => APP_URL . '/pages/home.php'
]);