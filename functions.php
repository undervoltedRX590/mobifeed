<?php
/**
 * MobiFeed - shared helpers.
 * Input/output sanitising, sessions, CSRF, auth + ban middleware, friends,
 * GD image handling and post rendering.
 *
 * Every page starts with:  require_once __DIR__ . '/functions.php';
 * Loading this file runs the session/ban middleware (auth_bootstrap) on every request.
 */
define('MOBIFEED_LOADED', true);
require_once __DIR__ . '/config.php';

define('REMEMBER_COOKIE', 'mobifeed_rm');

mb_internal_encoding('UTF-8');
date_default_timezone_set('UTC');
ini_set('display_errors', SHOW_ERRORS ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

/* =====================================================================
 * Input / output helpers
 * ===================================================================== */

/** Escape user-generated output for HTML. */
function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function post_str(string $k): string
{
    return (isset($_POST[$k]) && is_string($_POST[$k])) ? $_POST[$k] : '';
}

function get_str(string $k): string
{
    return (isset($_GET[$k]) && is_string($_GET[$k])) ? $_GET[$k] : '';
}

function get_int(string $k, int $default = 0): int
{
    $v = get_str($k);
    return (ctype_digit($v) && strlen($v) < 10) ? (int)$v : $default;
}

function post_int(string $k, int $default = 0): int
{
    $v = post_str($k);
    return (ctype_digit($v) && strlen($v) < 10) ? (int)$v : $default;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** Normalise user text: valid UTF-8, no control/bidi characters, trimmed, length-limited. */
function clean_text(string $s, int $max, bool $multiline = true): string
{
    $s = mb_scrub($s, 'UTF-8');
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    $s = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s);
    $s = (string)preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $s);
    if (!$multiline) {
        $s = str_replace("\n", ' ', $s);
    }
    $s = trim($s);
    if ($multiline) {
        $s = (string)preg_replace("/\n{3,}/", "\n\n", $s);
    }
    return mb_substr($s, 0, $max, 'UTF-8');
}

/** Format a UTC MySQL timestamp for display. */
function fmt_time(string $ts): string
{
    try {
        $d = new DateTime($ts, new DateTimeZone('UTC'));
        $d->setTimezone(new DateTimeZone(DISPLAY_TIMEZONE));
        return $d->format('M j, H:i');
    } catch (Exception $e) {
        return '';
    }
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

function send_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    // No CSS or JS is ever used, so modern browsers can be locked down completely.
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    header('Cache-Control: private, no-cache, must-revalidate');
}

function redirect(string $url): void
{
    if (BASE_URL !== '' && !preg_match('#^https?://#i', $url)) {
        $url = rtrim(BASE_URL, '/') . '/' . ltrim($url, '/');
    }
    if (!headers_sent()) {
        header('Location: ' . $url);
    } else {
        echo '<a href="' . h($url) . '">Continue</a>';
    }
    exit;
}

/** Only allow redirects back to our own pages (prevents open redirects). */
function safe_back(string $b, string $default = 'index.php'): string
{
    return preg_match('/^(index|post|profile|messages)\.php(\?[A-Za-z0-9_=&%.\-]*)?$/D', $b) ? $b : $default;
}

/* =====================================================================
 * Sessions, CSRF, flash messages
 * ===================================================================== */

function cookie_opts(int $expires): array
{
    return [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('MOBIFEEDSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="t" value="' . h(csrf_token()) . '">';
}

/** Checks the "t" token from POST (for POST requests) or GET (for token links). */
function csrf_ok(): bool
{
    $given = is_post() ? post_str('t') : get_str('t');
    return $given !== '' && !empty($_SESSION['csrf']) && is_string($_SESSION['csrf'])
        && hash_equals($_SESSION['csrf'], $given);
}

function flash(string $msg): void
{
    $_SESSION['flash'] = $msg;
}

function take_flash(): string
{
    $m = $_SESSION['flash'] ?? '';
    unset($_SESSION['flash']);
    return is_string($m) ? $m : '';
}

/* =====================================================================
 * Page helpers (also usable when the database is down)
 * ===================================================================== */

/** Minimal complete page using the master centering table. */
function bare_page(string $innerHtml, int $code = 200): void
{
    send_headers();
    if (!headers_sent()) {
        http_response_code($code);
    }
    echo '<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd">' . "\n";
    echo '<html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . h(APP_NAME) . '</title></head><body bgcolor="#ffffff" text="#000000">';
    echo '<table width="100%" border="0" cellspacing="0" cellpadding="0"><tr><td align="center">';
    echo '<table width="320" border="1" cellpadding="6" cellspacing="0"><tr><td>' . $innerHtml . '</td></tr></table>';
    echo '</td></tr></table></body></html>';
}

function fatal_page(string $msg): void
{
    bare_page('<center><h3>' . h($msg) . '</h3></center>', 500);
    exit;
}

function banned_page(): void
{
    bare_page('<center><h2>Your account has been banned due to violation of platform rules.</h2></center>', 403);
    exit;
}

/** Full page (header + footer) with a simple HTML message. */
function message_page(string $title, string $html, int $code = 200): void
{
    if ($code !== 200 && !headers_sent()) {
        http_response_code($code);
    }
    $page_title = $title;
    require __DIR__ . '/header.php';
    echo $html;
    require __DIR__ . '/footer.php';
    exit;
}

/* =====================================================================
 * Authentication, remember-me cookie, ban middleware
 * ===================================================================== */

function current_user(): ?array
{
    $u = $GLOBALS['CURRENT_USER'] ?? null;
    return is_array($u) ? $u : null;
}

function require_login(): array
{
    $u = current_user();
    if ($u === null) {
        redirect('login.php');
    }
    return $u;
}

function clear_remember_cookie(): void
{
    if (!headers_sent()) {
        setcookie(REMEMBER_COOKIE, '', cookie_opts(time() - 86400));
    }
    unset($_COOKIE[REMEMBER_COOKIE]);
}

/**
 * Log a user in. The remember-me cookie holds a random 64-char token;
 * only its SHA-256 hash (also 64 chars) is stored in users.remember_token.
 * (One token per account: logging in with "keep me" on a new device replaces the old one.)
 */
function login_user(int $uid, bool $remember): void
{
    start_session();
    session_regenerate_id(true);
    unset($_SESSION['admin_ok']);
    $_SESSION['uid']  = $uid;
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
    if ($remember) {
        $raw = bin2hex(random_bytes(32));
        db()->prepare('UPDATE users SET remember_token = ? WHERE id = ?')
            ->execute([hash('sha256', $raw), $uid]);
        setcookie(REMEMBER_COOKIE, $raw, cookie_opts(time() + REMEMBER_DAYS * 86400));
    }
}

/** Destroy the session and clear the remember-me cookie + stored token. */
function logout_user(int $uid = 0): void
{
    start_session();
    if ($uid <= 0) {
        $uid = (int)($_SESSION['uid'] ?? 0);
    }
    if ($uid > 0) {
        try {
            db()->prepare('UPDATE users SET remember_token = NULL WHERE id = ?')->execute([$uid]);
        } catch (Throwable $e) {
            error_log('MobiFeed logout: ' . $e->getMessage());
        }
    }
    clear_remember_cookie();
    $_SESSION = [];
    if (!headers_sent()) {
        setcookie(session_name(), '', cookie_opts(time() - 86400));
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    $GLOBALS['CURRENT_USER'] = null;
}

/**
 * Runs on EVERY request: resolve the user from session or remember-me cookie.
 * Banned accounts are logged out and shown the permanent ban page.
 */
function auth_bootstrap(): void
{
    $GLOBALS['CURRENT_USER'] = null;
    send_headers();
    start_session();

    $row = null;
    $uid = (int)($_SESSION['uid'] ?? 0);
    if ($uid > 0) {
        $st = db()->prepare('SELECT id, username, status FROM users WHERE id = ?');
        $st->execute([$uid]);
        $row = $st->fetch() ?: null;
        if ($row === null) {
            unset($_SESSION['uid']);
        }
    }

    if ($row === null && isset($_COOKIE[REMEMBER_COOKIE]) && is_string($_COOKIE[REMEMBER_COOKIE])) {
        $raw = $_COOKIE[REMEMBER_COOKIE];
        if (preg_match('/^[a-f0-9]{64}$/D', $raw)) {
            $st = db()->prepare('SELECT id, username, status FROM users WHERE remember_token = ? LIMIT 1');
            $st->execute([hash('sha256', $raw)]);
            $row = $st->fetch() ?: null;
            if ($row !== null && $row['status'] === 'active') {
                session_regenerate_id(true);
                $_SESSION['uid'] = (int)$row['id'];
            } elseif ($row === null) {
                clear_remember_cookie();   // stale or forged token
            }
        } else {
            clear_remember_cookie();
        }
    }

    if ($row !== null && $row['status'] === 'banned') {
        logout_user((int)$row['id']);
        banned_page();
    }

    $GLOBALS['CURRENT_USER'] = $row;
}

function is_admin(): bool
{
    $u = current_user();
    if ($u !== null) {
        foreach (ADMIN_USERNAMES as $name) {
            if (strcasecmp((string)$name, (string)$u['username']) === 0) {
                return true;
            }
        }
    }
    return !empty($_SESSION['admin_ok']);
}

/* =====================================================================
 * Social graph
 * ===================================================================== */

function is_following(int $follower, int $followed): bool
{
    $st = db()->prepare('SELECT 1 FROM follows WHERE follower_id = ? AND followed_id = ?');
    $st->execute([$follower, $followed]);
    return (bool)$st->fetchColumn();
}

/** Mutual follow: $user1 follows $user2 AND $user2 follows $user1. */
function is_friend(int $user1, int $user2): bool
{
    if ($user1 <= 0 || $user2 <= 0 || $user1 === $user2) {
        return false;
    }
    $st = db()->prepare(
        'SELECT COUNT(*) FROM follows
         WHERE (follower_id = ? AND followed_id = ?) OR (follower_id = ? AND followed_id = ?)'
    );
    $st->execute([$user1, $user2, $user2, $user1]);
    return (int)$st->fetchColumn() === 2;
}

/**
 * DM streak between two users.
 * A day counts when BOTH users sent at least one message that day (days roll over at midnight
 * in DISPLAY_TIMEZONE, using today's UTC offset). The streak stays alive while the latest
 * qualifying day is today or yesterday.
 * Returns [streakDays, qualifiedToday]. Looks back at most 1001 days (enough for "999+").
 */
function dm_streak(int $a, int $b): array
{
    $tz  = new DateTimeZone(DISPLAY_TIMEZONE);
    $now = new DateTime('now', $tz);
    $offset = (int)$tz->getOffset($now);

    $st = db()->prepare(
        'SELECT DATE(created_at + INTERVAL ' . $offset . ' SECOND) AS d
         FROM messages
         WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)
         GROUP BY d
         HAVING COUNT(DISTINCT sender_id) = 2
         ORDER BY d DESC
         LIMIT 1001'
    );
    $st->execute([$a, $b, $b, $a]);
    $days = $st->fetchAll(PDO::FETCH_COLUMN);
    if (!$days) {
        return [0, false];
    }

    $today     = $now->format('Y-m-d');
    $yesterday = date('Y-m-d', strtotime($today . ' -1 day'));
    $first     = (string)$days[0];
    if ($first !== $today && $first !== $yesterday) {
        return [0, false];
    }

    $streak = 1;
    $prev = $first;
    for ($i = 1, $n = count($days); $i < $n; $i++) {
        if ((string)$days[$i] !== date('Y-m-d', strtotime($prev . ' -1 day'))) {
            break;
        }
        $streak++;
        $prev = (string)$days[$i];
    }
    return [$streak, $first === $today];
}

/** "1 day", "10 days", "999+ days" */
function streak_label(int $days): string
{
    if ($days > 999) {
        return '999+ days';
    }
    return $days . ($days === 1 ? ' day' : ' days');
}

/** Simple per-user hourly throttle. $table is whitelisted. */
function rate_limited(string $table, int $uid, int $max, int $seconds = 3600): bool
{
    $cols = ['posts' => 'user_id', 'comments' => 'user_id', 'messages' => 'sender_id', 'reports' => 'reporter_id'];
    if (!isset($cols[$table])) {
        return false;
    }
    $sql = 'SELECT COUNT(*) FROM `' . $table . '` WHERE `' . $cols[$table]
         . '` = ? AND created_at > (NOW() - INTERVAL ' . (int)$seconds . ' SECOND)';
    $st = db()->prepare($sql);
    $st->execute([$uid]);
    return (int)$st->fetchColumn() >= $max;
}

/* =====================================================================
 * Images (GD): validate, resize to <=600px wide, JPEG q70, thumbnail
 * ===================================================================== */

function gd_scaled($src, int $w, int $h, int $maxW, int $maxH)
{
    $scale = min(1.0, $maxW / $w, $maxH / $h);
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    if ($dst === false) {
        return false;
    }
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));   // flatten transparency onto white
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    return $dst;
}

/**
 * Validate and store an uploaded image. Returns the stored path ("uploads/xxxx.jpg")
 * or null with a user-safe message in $err. Everything is re-encoded as baseline JPEG,
 * which also strips EXIF/GPS data.
 */
function save_uploaded_image(array $file, ?string &$err): ?string
{
    $err = '';
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
        $err = 'Image processing (GD) is not available on this server.';
        return null;
    }
    $code = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($code !== UPLOAD_ERR_OK) {
        $err = match ($code) {
            UPLOAD_ERR_NO_FILE => 'Please choose an image.',
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'That image is too large.',
            default => 'Upload failed. Please try again.',
        };
        return null;
    }
    $tmp = $file['tmp_name'] ?? '';
    if (!is_string($tmp) || $tmp === '' || !is_uploaded_file($tmp)) {
        $err = 'Invalid upload.';
        return null;
    }
    $size = @filesize($tmp);
    if ($size === false || $size <= 0 || $size > MAX_UPLOAD_BYTES) {
        $err = 'Image must be smaller than ' . (int)(MAX_UPLOAD_BYTES / 1048576) . ' MB.';
        return null;
    }

    $info = @getimagesize($tmp);
    if ($info === false) {
        $err = 'That file is not a valid image.';
        return null;
    }
    $mime = '';
    if (class_exists('finfo')) {
        $fi = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$fi->file($tmp);
    }
    if ($mime === '') {
        $mime = (string)($info['mime'] ?? '');
    }
    $types = ['image/jpeg' => IMAGETYPE_JPEG, 'image/png' => IMAGETYPE_PNG, 'image/gif' => IMAGETYPE_GIF];
    if (!isset($types[$mime]) || $info[2] !== $types[$mime]) {
        $err = 'Only JPG, PNG and GIF images are allowed.';
        return null;
    }
    $w = (int)$info[0];
    $h = (int)$info[1];
    if ($w < 1 || $h < 1 || $w * $h > MAX_IMAGE_PIXELS) {
        $err = 'Image dimensions are too large.';
        return null;
    }

    switch ($mime) {
        case 'image/jpeg': $src = @imagecreatefromjpeg($tmp); break;
        case 'image/png':  $src = @imagecreatefrompng($tmp);  break;
        default:           $src = @imagecreatefromgif($tmp);  break;   // static (first frame) only
    }
    if (!$src) {
        $err = 'Could not read that image.';
        return null;
    }

    // Respect EXIF orientation of phone photos before the metadata is dropped.
    if ($mime === 'image/jpeg' && function_exists('exif_read_data') && function_exists('imagerotate')) {
        $exif = @exif_read_data($tmp);
        $o = is_array($exif) ? (int)($exif['Orientation'] ?? 1) : 1;
        $angle = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        if ($angle !== 0) {
            $rot = imagerotate($src, $angle, 0);
            if ($rot) {
                $src = $rot;
                $w = imagesx($src);
                $h = imagesy($src);
            }
        }
    }

    if (!is_dir(UPLOAD_DIR)) {
        @mkdir(UPLOAD_DIR, 0755, true);
    }
    if (!is_dir(UPLOAD_DIR) || !is_writable(UPLOAD_DIR)) {
        error_log('MobiFeed: uploads directory is not writable: ' . UPLOAD_DIR);
        $err = 'Server storage error. Please try again later.';
        return null;
    }

    $big   = gd_scaled($src, $w, $h, IMG_MAX_WIDTH, IMG_MAX_HEIGHT);
    $small = gd_scaled($src, $w, $h, THUMB_WIDTH, (int)(THUMB_WIDTH * 1.5));
    if (!$big || !$small) {
        $err = 'Could not process that image.';
        return null;
    }
    imageinterlace($big, false);      // baseline JPEG: the only kind old J2ME browsers decode
    imageinterlace($small, false);

    $base  = bin2hex(random_bytes(12));
    $full  = UPLOAD_DIR . $base . '.jpg';
    $thumb = UPLOAD_DIR . 't_' . $base . '.jpg';
    if (!imagejpeg($big, $full, IMG_QUALITY) || !imagejpeg($small, $thumb, THUMB_QUALITY)) {
        @unlink($full);
        @unlink($thumb);
        $err = 'Could not save that image.';
        return null;
    }
    @chmod($full, 0644);
    @chmod($thumb, 0644);
    return 'uploads/' . $base . '.jpg';
}

function image_url(string $imagePath): string
{
    return UPLOAD_URL . rawurlencode(basename($imagePath));
}

function thumb_url(string $imagePath): string
{
    return UPLOAD_URL . 't_' . rawurlencode(basename($imagePath));
}

function delete_image_files(string $imagePath): void
{
    $b = basename($imagePath);
    if (!preg_match('/^[a-f0-9]{24}\.jpg$/D', $b)) {
        return;
    }
    @unlink(UPLOAD_DIR . $b);
    @unlink(UPLOAD_DIR . 't_' . $b);
}

/* =====================================================================
 * Posts
 * ===================================================================== */

/** Fetch one post (author must be active) with like/comment counts and "liked by me". */
function fetch_post(int $id, int $meId): ?array
{
    $st = db()->prepare(
        "SELECT p.id, p.user_id, p.image_path, p.caption, p.created_at, u.username,
                (SELECT COUNT(*) FROM likes l WHERE l.post_id = p.id) AS like_count,
                (SELECT COUNT(*) FROM comments c WHERE c.post_id = p.id) AS comment_count,
                (SELECT COUNT(*) FROM likes l2 WHERE l2.post_id = p.id AND l2.user_id = ?) AS liked
         FROM posts p JOIN users u ON u.id = p.user_id
         WHERE p.id = ? AND u.status = 'active'"
    );
    $st->execute([$meId, $id]);
    return $st->fetch() ?: null;
}

/** Delete a post row (comments/likes/reports cascade) and its image files. */
function purge_post(int $postId): void
{
    $st = db()->prepare('SELECT image_path FROM posts WHERE id = ?');
    $st->execute([$postId]);
    $paths = $st->fetchAll(PDO::FETCH_COLUMN);
    db()->prepare('DELETE FROM posts WHERE id = ?')->execute([$postId]);
    foreach ($paths as $p) {
        delete_image_files((string)$p);
    }
}

/** Delete every post of a user and their image files. Returns number of posts removed. */
function purge_user_posts(int $uid): int
{
    $st = db()->prepare('SELECT image_path FROM posts WHERE user_id = ?');
    $st->execute([$uid]);
    $paths = $st->fetchAll(PDO::FETCH_COLUMN);
    db()->prepare('DELETE FROM posts WHERE user_id = ?')->execute([$uid]);
    foreach ($paths as $p) {
        delete_image_files((string)$p);
    }
    return count($paths);
}

/**
 * Print one post: author, image, caption, counts and quick links.
 * $back is the page to return to after Like/Unlike (see safe_back()).
 */
function render_post(array $p, string $back, bool $linkImage = true): void
{
    $pid   = (int)$p['id'];
    $uid   = (int)$p['user_id'];
    $liked = (int)$p['liked'] > 0;
    $likeUrl = 'post.php?id=' . $pid . '&act=' . ($liked ? 'unlike' : 'like')
             . '&t=' . urlencode(csrf_token()) . '&back=' . urlencode($back);
    $imgTag = '<img src="' . h(image_url((string)$p['image_path'])) . '" width="100%" alt="Feed Image">';

    echo '<table width="100%" border="0" cellpadding="3" cellspacing="0">';
    echo '<tr><td><b><a href="profile.php?id=' . $uid . '">' . h($p['username']) . '</a></b> '
       . '<font size="1" color="#666666">' . h(fmt_time((string)$p['created_at'])) . '</font></td></tr>';
    echo '<tr><td align="center">'
       . ($linkImage ? '<a href="post.php?id=' . $pid . '">' . $imgTag . '</a>' : $imgTag)
       . '</td></tr>';
    if ((string)$p['caption'] !== '') {
        echo '<tr><td>' . nl2br(h($p['caption']), false) . '</td></tr>';
    }
    echo '<tr><td><font size="2">Likes: <b>' . (int)$p['like_count'] . '</b> | Comments: <b>'
       . (int)$p['comment_count'] . '</b></font></td></tr>';
    echo '<tr><td><font size="2">'
       . '[ <a href="' . h($likeUrl) . '">' . ($liked ? 'Unlike' : 'Like') . '</a> ] '
       . '[ <a href="post.php?id=' . $pid . '#comments">Comment</a> ] '
       . '[ <a href="share.php?id=' . $pid . '">Share</a> ] '
       . '[ <a href="report.php?id=' . $pid . '">Report</a> ] '
       . '[ <a href="profile.php?id=' . $uid . '">Profile</a> ]'
       . '</font></td></tr>';
    echo '</table>';
}

/* =====================================================================
 * Bootstrap (order matters)
 * ===================================================================== */

require_once __DIR__ . '/moderation.php';

set_exception_handler(function (Throwable $e): void {
    error_log('MobiFeed error: ' . get_class($e) . ': ' . $e->getMessage()
        . ' in ' . $e->getFile() . ':' . $e->getLine());
    fatal_page('Something went wrong. Please try again.');
});

auth_bootstrap();
