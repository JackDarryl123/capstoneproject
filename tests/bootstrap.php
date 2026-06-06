<?php
declare(strict_types=1);

define('BASE_PATH', __DIR__ . '/..');

$_SESSION = [];
$_POST = [];
$_GET = [];
$_COOKIE = [];

require_once BASE_PATH . '/includes/session_helper.php';
start_user_session();

$_SESSION = [];

require_once BASE_PATH . '/config.php';