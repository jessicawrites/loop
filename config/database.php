<?php
/**
 * Loop — Database Connection
 * 
 * Uses PDO for secure, prepared-statement-based database access.
 * This function is called once per request wherever DB access is needed.
 */

require_once __DIR__ . '/constants.php';

/**
 * Returns a PDO database connection instance.
 * Uses a static variable so the connection is created only once
 * per request (singleton pattern — efficient and clean).
 * 
 * @return PDO
 */
function get_db(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        // ── Database credentials ──────────────────────────────────────────
        $host    = 'localhost';
        $db      = 'loop_db';
        $user    = 'root';
        $pass    = '';             // Laragon default: empty password
        $charset = 'utf8mb4';      // Full Unicode — supports emojis ✅

        // ── DSN = Data Source Name ────────────────────────────────────────
        $dsn = "mysql:host={$host};dbname={$db};charset={$charset}";

        // ── PDO Options ───────────────────────────────────────────────────
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,   // Throw exceptions on error
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,         // Return arrays by column name
            PDO::ATTR_EMULATE_PREPARES   => false,                    // Use real prepared statements
        ];

        try {
            $pdo = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            // Never expose DB errors to the browser in production
            error_log('Database connection failed: ' . $e->getMessage());
            http_response_code(500);
            die(json_encode([
                'success' => false,
                'message' => 'A database error occurred. Please try again later.'
            ]));
        }
    }

    return $pdo;
}