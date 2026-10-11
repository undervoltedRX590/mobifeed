<?php
/**
 * THIS IS THE SAMPLE FILE. Copy it to config.php and fill in your own values.
 * config.php is listed in .gitignore so your passwords never reach GitHub.
 *
 * MobiFeed - configuration & database connection (PDO).
 * EDIT THE VALUES BELOW BEFORE GOING LIVE.
 */
if (!defined('MOBIFEED_LOADED')) { exit; }

/* ---------- Database ---------- */
define('DB_HOST', 'localhost');
define('DB_NAME', 'mobifeed');
define('DB_USER', 'mobifeed');
define('DB_PASS', 'change_me');

/* ---------- Site ---------- */
define('APP_NAME', 'MobiFeed');
// Optional absolute base URL (with trailing slash), e.g. 'https://example.com/mobifeed/'.
// Leave '' to send relative redirects. Some very old browsers prefer absolute ones.
define('BASE_URL', '');
define('DISPLAY_TIMEZONE', 'UTC');   // e.g. 'Europe/Prague'
define('SHOW_ERRORS', false);        // true only while developing

/* ---------- Admin access ---------- */
// admin.php opens for (a) logged-in usernames listed here, or (b) anyone who enters the passkey.
// Admin stays LOCKED until you set at least one of them. Use a long random passkey.
define('ADMIN_USERNAMES', []);       // e.g. ['alice', 'bob']
define('ADMIN_PASSKEY', 'CHANGE_ME_TO_A_LONG_RANDOM_PASSKEY');

/* ---------- Uploads ---------- */
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('UPLOAD_URL', 'uploads/');
define('MAX_UPLOAD_BYTES', 4 * 1024 * 1024);   // also check upload_max_filesize / post_max_size in php.ini
define('MAX_IMAGE_PIXELS', 25000000);          // refuse huge images (GD memory)
define('IMG_MAX_WIDTH', 600);
define('IMG_MAX_HEIGHT', 1200);
define('IMG_QUALITY', 70);
define('THUMB_WIDTH', 90);
define('THUMB_QUALITY', 60);

/* ---------- Limits ---------- */
define('CAPTION_MAX', 500);
define('COMMENT_MAX', 500);
define('MESSAGE_MAX', 1000);
define('COMMENTS_PER_PAGE', 10);
define('MESSAGES_PER_PAGE', 15);
define('PROFILE_POSTS_PER_PAGE', 9);
define('REMEMBER_DAYS', 30);
define('RATE_POSTS_PER_HOUR', 20);
define('RATE_COMMENTS_PER_HOUR', 40);
define('RATE_MESSAGES_PER_HOUR', 120);
define('RATE_REPORTS_PER_HOUR', 20);

/**
 * Lazy PDO connection (utf8mb4, exceptions, native prepared statements).
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
        $pdo->exec("SET time_zone = '+00:00'");   // keep all timestamps in UTC
    } catch (PDOException $e) {
        error_log('MobiFeed DB connection failed: ' . $e->getMessage());
        $pdo = null;
        fatal_page('The service is temporarily unavailable. Please try again later.');
    }
    return $pdo;
}
