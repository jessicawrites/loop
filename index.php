<?php
/**
 * Loop — Application Entry Point
 * 
 * This is the first file accessed when visiting localhost/loop.
 * It simply redirects to the right page based on login status.
 */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/includes/auth.php';

start_session();

if (is_logged_in()) {
    redirect(APP_URL . '/pages/home.php');
} else {
    redirect(APP_URL . '/pages/login.php');
}