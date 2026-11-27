<?php
/**
 * EnformaSys Shared Hosting Configuration Template (InfinityFree / Apache + MySQL)
 *
 * Setup steps:
 *   1. Copy this file to `includes/config.php`
 *   2. Fill in your InfinityFree MySQL Host, Username, Password, and Database Name
 *   3. Upload `includes/config.php` alongside your site files into `htdocs/`
 *
 * SECURITY: Never commit `includes/config.php` to GitHub.
 */

return [
    // Application environment ('production' disables verbose errors and runtime schema sync)
    'APP_ENV' => 'production',
    'APP_URL' => 'https://yourdomain.infinityfreeapp.com',

    // InfinityFree MySQL Database Credentials (from Control Panel -> MySQL Databases)
    'DB_HOST' => 'sqlXXX.infinityfree.com',
    'DB_PORT' => 3306,
    'DB_USER' => 'if0_xxxxxxx',
    'DB_PASS' => 'your_mysql_password',
    'DB_NAME' => 'if0_xxxxxxx_enformasys',

    // InfinityFree uses standard non-SSL internal MySQL connections
    'DB_SSL' => false,
    'DB_SSL_CA' => '',
    'DB_PERSISTENT' => false,
    'DB_SKIP_SCHEMA_SYNC' => true,

    // Standard shared hosting uses native PHP file-based sessions (disable DB session handler)
    'DB_SESSION_HANDLER' => false,
    'SESSION_LIFETIME' => 3600,

    // Gmail SMTP Settings (for Email OTP Password Reset — InfinityFree blocks PHP mail())
    // Generate a 16-character Gmail App Password at https://myaccount.google.com/apppasswords
    'SMTP_HOST' => 'smtp.gmail.com',
    'SMTP_PORT' => 587,
    'SMTP_ENCRYPTION' => 'tls',
    'SMTP_USER' => 'your_seminary_email@gmail.com',
    'SMTP_PASS' => 'your_16_char_gmail_app_password',
    'SMTP_FROM_EMAIL' => '',
    'SMTP_FROM_NAME' => 'EnformaSys Seminary Portal',
];
