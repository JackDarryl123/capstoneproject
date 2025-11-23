<?php 
// includes/session_helper.php

function start_user_session($role = 'user') {
    if (session_status() === PHP_SESSION_NONE) {
        $sessionName = match($role) {
            'admin'  => 'admin_session',
            'staff'  => 'staff_session',
            'supply' => 'supply_session',
            'user'   => 'user_session',
            default  => 'user_session'
        };

        session_name($sessionName);

        session_set_cookie_params([
            'lifetime' => 3600,
            'path' => '/',
            'domain' => '',
            'secure' => false, // true if HTTPS
            'httponly' => true,
            'samesite' => 'Lax'
        ]);

        session_start();
    }
}

/**
 * ✅ Require a specific role for a page
 */

