<?php
/**
 * PEPO Mail Configuration
 * 
 * Configure your SMTP settings here.
 * 
 * For Gmail:
 * - Enable 2-Factor Authentication
 * - Generate App Password: https://myaccount.google.com/apppasswords
 * 
 * For Outlook:
 * - Use SMTP.office365.com with port 587
 */

date_default_timezone_set('Asia/Manila');

function env_value(string $key, string $default = ''): string
{
    $value = getenv($key);
    return $value === false ? $default : $value;
}

// SMTP Server Settings
define('SMTP_HOST', env_value('SMTP_HOST', 'smtp.gmail.com'));
define('SMTP_PORT', (int) env_value('SMTP_PORT', '587'));
define('SMTP_USERNAME', env_value('SMTP_USERNAME'));
define('SMTP_PASSWORD', env_value('SMTP_PASSWORD'));
define('SMTP_FROM_EMAIL', env_value('SMTP_FROM_EMAIL', SMTP_USERNAME));
define('SMTP_FROM_NAME', env_value('SMTP_FROM_NAME', 'PEPO System'));

// Base URL for password reset links
define('BASE_URL', env_value('APP_BASE_URL', 'http://localhost:8080/'));

// Token expiration time (in minutes)
define('RESET_TOKEN_EXPIRY', (int) env_value('RESET_TOKEN_EXPIRY', '60'));
