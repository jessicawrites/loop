<?php
/**
 * Loop — Application Constants
 * 
 * Central place for all app-wide configuration values.
 * Change values here and they update everywhere automatically.
 */

// ─── Application Info ───────────────────────────────────────────────────────
define('APP_NAME',    'Loop');
define('APP_VERSION', '1.0.0');
define('APP_URL',     'http://localhost/loop');

// ─── File Paths ──────────────────────────────────────────────────────────────
define('ROOT_PATH',    dirname(__DIR__));          // C:/laragon/www/loop
define('UPLOADS_PATH', ROOT_PATH . '/uploads/avatars/');
define('AVATARS_URL',  APP_URL   . '/uploads/avatars/');

// ─── Upload Limits ───────────────────────────────────────────────────────────
define('MAX_AVATAR_SIZE',  2 * 1024 * 1024);       // 2MB in bytes
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/webp']);

// ─── Message Image Uploads ───────────────────────────────────────────────────
define('MAX_MESSAGE_IMAGE_SIZE', 5 * 1024 * 1024); // 5MB — larger than avatars, photos can be bigger
define('MESSAGE_IMAGES_PATH', ROOT_PATH . '/uploads/messages/');
define('MESSAGE_IMAGES_URL',  APP_URL   . '/uploads/messages/');

// ─── Session & Security ──────────────────────────────────────────────────────
define('SESSION_LIFETIME',  86400);                // 24 hours in seconds
define('BCRYPT_COST',       12);                   // Password hashing cost factor

// ─── Pagination & Limits ─────────────────────────────────────────────────────
define('MESSAGES_PER_PAGE',     50);               // Messages loaded per scroll
define('CONVERSATIONS_PER_PAGE', 20);              // Conversations per load
define('POLLING_INTERVAL',      3000);             // AJAX polling in milliseconds

// ─── Default Avatar ──────────────────────────────────────────────────────────
define('DEFAULT_AVATAR', APP_URL . '/assets/images/avatars/default.svg');