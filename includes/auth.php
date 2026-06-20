<?php
/**
 * Loop — Authentication Helpers
 * 
 * Manages sessions, login state, and access control.
 * Every protected page starts by calling require_login().
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/helpers.php';

/**
 * Starts the session safely.
 * Called once at the top of every page.
 */
function start_session(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path'     => '/',
            'secure'   => false,    // Set to true in production with HTTPS
            'httponly' => true,      // JS cannot access session cookie
            'samesite' => 'Strict',  // CSRF protection
        ]);
        session_start();
    }
}

/**
 * Checks if a user is currently logged in.
 * 
 * @return bool
 */
function is_logged_in(): bool {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Returns the current logged-in user's ID.
 * 
 * @return int|null
 */
function current_user_id(): ?int {
    return $_SESSION['user_id'] ?? null;
}

/**
 * Returns the current logged-in user's data from session.
 * 
 * @return array|null
 */
function current_user(): ?array {
    if (!is_logged_in()) return null;
    return [
        'id'           => $_SESSION['user_id'],
        'username'     => $_SESSION['username'],
        'display_name' => $_SESSION['display_name'],
        'avatar'       => $_SESSION['avatar'] ?? null,
    ];
}

/**
 * Redirects to login if user is not authenticated.
 * Place at the top of every protected page.
 */
function require_login(): void {
    start_session();
    if (!is_logged_in()) {
        redirect(APP_URL . '/pages/login.php');
    }
}

/**
 * Redirects to home if user IS already logged in.
 * Place at the top of login and register pages.
 */
function require_guest(): void {
    start_session();
    if (is_logged_in()) {
        redirect(APP_URL . '/pages/home.php');
    }
}

/**
 * Logs a user in by storing their data in the session.
 * 
 * @param array $user  User row from the database
 */
function login_user(array $user): void {
    session_regenerate_id(true);   // Prevent session fixation attacks
    $_SESSION['user_id']      = $user['id'];
    $_SESSION['username']     = $user['username'];
    $_SESSION['display_name'] = $user['display_name'];
    $_SESSION['avatar']       = $user['avatar'] ?? null;
}

/**
 * Logs the current user out and destroys the session.
 */
function logout_user(): void {
    session_unset();
    session_destroy();
}