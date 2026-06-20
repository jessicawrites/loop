<?php
/**
 * Loop — Helper Functions
 * 
 * Reusable utility functions used throughout the application.
 * These are the "Swiss Army knife" of the backend.
 */

require_once __DIR__ . '/../config/constants.php';

/**
 * Sends a JSON response and stops execution.
 * Every API endpoint uses this — keeps responses consistent.
 * 
 * @param bool   $success  Whether the operation succeeded
 * @param string $message  Human-readable message
 * @param array  $data     Optional extra data to return
 * @param int    $code     HTTP status code
 */
function send_json(bool $success, string $message = '', array $data = [], int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data'    => $data,
    ]);
    exit;
}

/**
 * Sanitizes a string for safe HTML output.
 * Always use this before displaying user-generated content.
 * 
 * @param string $input
 * @return string
 */
function clean(string $input): string {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Formats a timestamp into a human-readable relative time.
 * e.g. "just now", "5m ago", "Yesterday", "Mon 14 Jun"
 * 
 * @param string $timestamp  MySQL DATETIME string
 * @return string
 */
function time_ago(string $timestamp): string {
    $now  = new DateTime();
    $then = new DateTime($timestamp);
    $diff = $now->getTimestamp() - $then->getTimestamp();

    if ($diff < 60)                  return 'just now';
    if ($diff < 3600)                return floor($diff / 60) . 'm ago';
    if ($diff < 86400)               return floor($diff / 3600) . 'h ago';
    if ($diff < 172800)              return 'Yesterday';

    return $then->format('D j M');   // e.g. "Mon 14 Jun"
}

/**
 * Generates the correct avatar URL for a user.
 * Falls back to the default avatar if none is set.
 * 
 * @param string|null $filename  The stored avatar filename
 * @return string
 */
function avatar_url(?string $filename): string {
    if (!empty($filename) && file_exists(UPLOADS_PATH . $filename)) {
        return AVATARS_URL . $filename;
    }
    return DEFAULT_AVATAR;
}



/**
 * Returns the current CSRF token, generating one if it doesn't exist yet.
 * Call this when rendering any page with a form or AJAX calls.
 *
 * @return string
 */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verifies a submitted CSRF token against the one in session.
 * Call this at the top of every state-changing API endpoint.
 * Uses hash_equals() for timing-safe comparison — a regular === check
 * leaks timing information that could theoretically help an attacker
 * guess the token character by character.
 *
 * @param string|null $submitted
 * @return bool
 */
function verify_csrf(?string $submitted): bool {
    if (empty($_SESSION['csrf_token']) || empty($submitted)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $submitted);
}

/**
 * Redirects to a URL and stops execution.
 * 
 * @param string $url
 */
function redirect(string $url): void {
    header("Location: {$url}");
    exit;
}

/**
 * Checks if the current request is a POST request.
 * 
 * @return bool
 */
function is_post(): bool {
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

/**
 * Safely retrieves a value from $_POST with a fallback.
 * 
 * @param string $key
 * @param mixed  $default
 * @return mixed
 */
function post(string $key, mixed $default = ''): mixed {
    return isset($_POST[$key]) ? trim($_POST[$key]) : $default;
}

/**
 * Generates a random unique filename for uploads.
 * e.g. "a3f9b2c1d4e5.jpg"
 * 
 * @param string $extension  File extension without dot
 * @return string
 */
function unique_filename(string $extension): string {
    return bin2hex(random_bytes(8)) . '.' . strtolower($extension);
}