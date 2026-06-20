<?php
/**
 * Loop — Validation Functions
 * 
 * All input validation logic lives here.
 * Returns arrays of errors so forms can show specific messages.
 */

/**
 * Validates registration form input.
 * 
 * @param array $data  Associative array of form fields
 * @return array       List of error messages (empty = valid)
 */
function validate_registration(array $data): array {
    $errors = [];

    // Username
    if (empty($data['username'])) {
        $errors[] = 'Username is required.';
    } elseif (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $data['username'])) {
        $errors[] = 'Username must be 3–20 characters and contain only letters, numbers, or underscores.';
    }

    // Display name
    if (empty($data['display_name'])) {
        $errors[] = 'Display name is required.';
    } elseif (strlen($data['display_name']) < 2 || strlen($data['display_name']) > 50) {
        $errors[] = 'Display name must be between 2 and 50 characters.';
    }

    // Email
    if (empty($data['email'])) {
        $errors[] = 'Email address is required.';
    } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    // Password
    if (empty($data['password'])) {
        $errors[] = 'Password is required.';
    } elseif (strlen($data['password']) < 8) {
        $errors[] = 'Password must be at least 8 characters long.';
    }

    // Confirm password
    if ($data['password'] !== $data['confirm_password']) {
        $errors[] = 'Passwords do not match.';
    }

    return $errors;
}

/**
 * Validates login form input.
 * 
 * @param array $data
 * @return array
 */
function validate_login(array $data): array {
    $errors = [];

    if (empty($data['username'])) {
        $errors[] = 'Username is required.';
    }

    if (empty($data['password'])) {
        $errors[] = 'Password is required.';
    }

    return $errors;
}

/**
 * Validates profile update input.
 * 
 * @param array $data
 * @return array
 */
function validate_profile(array $data): array {
    $errors = [];

    if (empty($data['display_name'])) {
        $errors[] = 'Display name is required.';
    } elseif (strlen($data['display_name']) < 2 || strlen($data['display_name']) > 50) {
        $errors[] = 'Display name must be between 2 and 50 characters.';
    }

    if (!empty($data['bio']) && strlen($data['bio']) > 160) {
        $errors[] = 'Bio must be 160 characters or fewer.';
    }

    return $errors;
}