<?php
declare(strict_types=1);

/**
 * TEMPLATE ONLY - copy to credentials.php and edit with REAL values.
 * credentials.php is git-ignored and lives outside public_html, so Apache
 * can never serve it. Values may also come from environment variables.
 */

/* ---- MySQL / MariaDB (hPanel -> Databases -> Management) -------------------- */
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'eduportal_db');
define('DB_USER', getenv('DB_USER') ?: 'eduportal_user');
define('DB_PASS', getenv('DB_PASS') ?: 'CHANGE_ME');

/* ---- Hostinger Webmail SMTP (hPanel -> Emails) --------------------------------
 * Port 465 = SSL  |  Port 587 = TLS. Hostinger recommends 465 for PHPMailer. */
define('SMTP_HOST', getenv('SMTP_HOST') ?: 'smtp.hostinger.com');
define('SMTP_PORT', (int) (getenv('SMTP_PORT') ?: 465));
define('SMTP_USER', getenv('SMTP_USER') ?: 'no-reply@datamex.edu.ph');
define('SMTP_PASS', getenv('SMTP_PASS') ?: 'CHANGE_ME');
define('SMTP_FROM_EMAIL', getenv('SMTP_FROM_EMAIL') ?: 'no-reply@datamex.edu.ph');
define('MAIL_FROM_NAME', getenv('MAIL_FROM_NAME') ?: 'EduPortal - Datamex College of Saint Adeline');

/* ---- PayRex payment gateway (Dashboard -> Developers -> API keys) ------------- */
define('PAYREX_API_BASE', getenv('PAYREX_API_BASE') ?: 'https://api.payrexhq.com/v1');
define('PAYREX_MODE', getenv('PAYREX_MODE') ?: 'test');            // test | live
define('PAYREX_PUBLIC_KEY', getenv('PAYREX_PUBLIC_KEY') ?: '');    // used by browser JS SDK
define('PAYREX_SECRET_KEY', getenv('PAYREX_SECRET_KEY') ?: '');    // server-side only!
define('PAYREX_WEBHOOK_SECRET', getenv('PAYREX_WEBHOOK_SECRET') ?: ''); // whsec_...
