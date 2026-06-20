<?php
/**
 * Loop — User Search API
 *
 * GET /api/users/search.php?q=tonny
 *
 * Returns a list of users matching the query.
 * Excludes the currently logged-in user from results.
 * Requires login.
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

// ── Auth guard ─────────────────────────────────────────────────────────────
start_session();
if (!is_logged_in()) {
    send_json(false, 'Unauthorised.', [], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json(false, 'Method not allowed.', [], 405);
}

// ── Query param ────────────────────────────────────────────────────────────
$query = trim($_GET['q'] ?? '');

if (strlen($query) < 2) {
    // Don't search on 0 or 1 character — too broad, too slow
    send_json(true, '', ['users' => []]);
}

$me  = current_user_id();
$pdo = get_db();

// ── Search ─────────────────────────────────────────────────────────────────
// LIKE with % on both sides searches anywhere in the string.
// We search both username AND display_name so users can search either.
// LIMIT 20 — enough results, not so many it feels overwhelming.
$search = '%' . $query . '%';

$stmt = $pdo->prepare('
    SELECT
        id,
        username,
        display_name,
        avatar,
        bio
    FROM   users
    WHERE  (username LIKE ? OR display_name LIKE ?)
    AND    id != ?
    ORDER  BY display_name ASC
    LIMIT  20
');
$stmt->execute([$search, $search, $me]);
$users = $stmt->fetchAll();

// ── Format avatar URLs ─────────────────────────────────────────────────────
foreach ($users as &$user) {
    $user['avatar_url'] = avatar_url($user['avatar']);
    unset($user['avatar']);   // Don't expose raw filename to frontend
}
unset($user);

send_json(true, '', ['users' => $users]);