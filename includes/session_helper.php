<?php
// includes/session_helper.php

function start_user_session() {
    $status = session_status();

    if ($status === PHP_SESSION_NONE) {
        session_name('app_session');

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => false,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);

        session_start();
    } elseif ($status === PHP_SESSION_ACTIVE && session_name() !== 'app_session') {
        session_name('app_session');
    }
}

function require_role($roles) {
    start_user_session();
    
    // Convert single role to array for uniform handling
    if (!is_array($roles)) {
        $roles = [$roles];
    }
    
    if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', $roles)) {
        header("Location: /index.php?login");
        exit();
    }
}

function check_login() {
    start_user_session();
    return !empty($_SESSION['user_id']);
}