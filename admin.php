<?php
require_once __DIR__ . '/functions.php';

$error = '';

/* =====================================================================
 * Access control: listed admin usernames OR the passkey from config.php
 * ===================================================================== */
$passkeySet = (ADMIN_PASSKEY !== '' && ADMIN_PASSKEY !== 'CHANGE_ME_TO_A_LONG_RANDOM_PASSKEY');
$namesSet   = (count(ADMIN_USERNAMES) > 0);

if (!is_admin()) {
    if (!$passkeySet && !$namesSet) {
        message_page(
            'Admin',
            '<center><i>Admin access is not configured.</i></center><br>'
            . '<font size="2">Set ADMIN_USERNAMES and/or ADMIN_PASSKEY in config.php.</font>',
            403
        );
    }
    if (is_post() && post_str('do') === 'adminlogin') {
        if (!csrf_ok()) {
            $error = 'Session expired. Please try again.';
        } elseif (!$passkeySet) {
            $error = 'Passkey login is disabled.';
        } elseif ((int)($_SESSION['adm_lock'] ?? 0) > time()) {
            $error = 'Too many attempts. Please wait a few minutes.';
        } elseif (hash_equals(hash('sha256', ADMIN_PASSKEY), hash('sha256', post_str('passkey')))) {
            session_regenerate_id(true);
            $_SESSION['admin_ok'] = true;
            unset($_SESSION['adm_fails'], $_SESSION['adm_lock']);
            redirect('admin.php');
        } else {
            $_SESSION['adm_fails'] = (int)($_SESSION['adm_fails'] ?? 0) + 1;
            if ($_SESSION['adm_fails'] >= 5) {
                $_SESSION['adm_lock'] = time() + 300;
                $_SESSION['adm_fails'] = 0;
            }
            usleep(500000);
            $error = 'Wrong passkey.';
        }
    }
    $page_title = 'Admin';
    require __DIR__ . '/header.php';
    echo '<center><b>Admin login</b></center><hr>';
    if ($error !== '') {
        echo '<font color="red"><b>' . h($error) . '</b></font><br><br>';
    }
    if ($passkeySet) {
        echo '<form method="post" action="admin.php">' . csrf_field()
           . '<input type="hidden" name="do" value="adminlogin">'
           . 'Passkey:<br><input type="password" name="passkey" size="20"><br><br>'
           . '<input type="submit" value="[ Enter ]"></form>';
    } else {
        echo '<i>Log in with an admin account to continue.</i>';
    }
    require __DIR__ . '/footer.php';
    exit;
}

/* =====================================================================
 * Actions (all POST + CSRF, then redirect)
 * ===================================================================== */
if (is_post()) {
    if (!csrf_ok()) {
        flash('Session expired. Please try again.');
        redirect('admin.php');
    }

    $rid = post_int('rid');
    $msg = '';

    if (isset($_POST['act_delete']) || isset($_POST['act_ban']) || isset($_POST['act_dismiss'])) {
        $st = db()->prepare(
            'SELECT r.id, r.post_id, p.user_id, u.username
             FROM reports r
             JOIN posts p ON p.id = r.post_id
             JOIN users u ON u.id = p.user_id
             WHERE r.id = ?'
        );
        $st->execute([$rid]);
        $rep = $st->fetch() ?: null;

        if ($rep === null) {
            $msg = 'That report no longer exists.';
        } elseif (isset($_POST['act_delete'])) {
            purge_post((int)$rep['post_id']);      // reports for the post cascade away with it
            $msg = 'Post deleted.';
        } elseif (isset($_POST['act_ban'])) {
            $isProtected = false;
            foreach (ADMIN_USERNAMES as $n) {
                if (strcasecmp((string)$n, (string)$rep['username']) === 0) {
                    $isProtected = true;
                }
            }
            if ($isProtected) {
                $msg = 'Admin accounts cannot be banned here.';
            } else {
                ban_user((int)$rep['user_id']);
                $count = purge_user_posts((int)$rep['user_id']);   // also removes this report (cascade)
                $msg = 'User ' . $rep['username'] . ' banned; ' . $count . ' post(s) deleted.';
            }
        } else {
            db()->prepare("UPDATE reports SET status = 'reviewed' WHERE id = ?")->execute([$rid]);
            $msg = 'Report dismissed.';
        }
    } elseif (post_str('do') === 'addword') {
        $w = mb_strtolower(clean_text(post_str('word'), 64, false), 'UTF-8');
        if (mb_strlen(ltrim($w, '~'), 'UTF-8') < 2) {
            $msg = 'Keyword is too short.';
        } else {
            $st = db()->prepare('INSERT IGNORE INTO banned_keywords (word) VALUES (?)');
            $st->execute([$w]);
            $msg = $st->rowCount() > 0 ? 'Keyword added.' : 'Keyword already exists.';
        }
    } elseif (post_str('do') === 'delword') {
        db()->prepare('DELETE FROM banned_keywords WHERE id = ?')->execute([post_int('wid')]);
        $msg = 'Keyword removed.';
    } elseif (post_str('do') === 'unban') {
        $name = clean_text(post_str('username'), 32, false);
        $st = db()->prepare("UPDATE users SET status = 'active' WHERE username = ? AND status = 'banned'");
        $st->execute([$name]);
        $msg = $st->rowCount() > 0 ? 'User ' . $name . ' unbanned.' : 'No banned user with that name.';
    } elseif (post_str('do') === 'adminlogout') {
        unset($_SESSION['admin_ok']);
        $msg = 'Passkey session ended.';
    }

    flash($msg !== '' ? $msg : 'Nothing to do.');
    redirect('admin.php');
}

/* =====================================================================
 * Dashboard
 * ===================================================================== */
$total = (int)db()->query("SELECT COUNT(*) FROM reports WHERE status = 'pending'")->fetchColumn();
$reports = db()->query(
    "SELECT r.id, r.reason, r.created_at, r.post_id,
            p.image_path, p.caption, p.user_id AS author_id,
            a.username AS author, a.status AS author_status,
            rp.username AS reporter
     FROM reports r
     JOIN posts p ON p.id = r.post_id
     JOIN users a ON a.id = p.user_id
     LEFT JOIN users rp ON rp.id = r.reporter_id
     WHERE r.status = 'pending'
     ORDER BY r.id ASC LIMIT 10"
)->fetchAll();
$words = db()->query('SELECT id, word FROM banned_keywords ORDER BY word LIMIT 100')->fetchAll();

$page_title = 'Admin';
require __DIR__ . '/header.php';

echo '<center><b>Moderation</b><br><font size="2">Pending reports: <b>' . $total . '</b>'
   . (count($reports) < $total ? ' (showing oldest ' . count($reports) . ')' : '') . '</font></center><hr>';

if (!$reports) {
    echo '<center><i>Nothing to review.</i></center>';
}

foreach ($reports as $r) {
    echo '<table width="100%" border="1" cellspacing="0" cellpadding="4" bgcolor="#f0f0f0">';
    echo '<tr><td><font size="2"><b>Report #' . (int)$r['id'] . '</b> ' . h(fmt_time((string)$r['created_at'])) . '</font></td></tr>';
    echo '<tr><td align="center"><img src="' . h(image_url((string)$r['image_path'])) . '" width="100%" alt="Reported post"></td></tr>';
    echo '<tr><td><font size="2">Caption: '
       . ((string)$r['caption'] !== '' ? nl2br(h($r['caption']), false) : '<i>(none)</i>') . '<br>'
       . 'Author: <b><a href="profile.php?id=' . (int)$r['author_id'] . '">' . h($r['author']) . '</a></b><br>'
       . 'Reporter: ' . ($r['reporter'] !== null ? '<b>' . h($r['reporter']) . '</b>' : '<i>System (auto-flag)</i>') . '<br>'
       . 'Reason: <font color="red">' . h($r['reason']) . '</font></font></td></tr>';
    echo '<tr><td><form method="post" action="admin.php">' . csrf_field()
       . '<input type="hidden" name="rid" value="' . (int)$r['id'] . '">'
       . '<input type="submit" name="act_delete" value="[ Delete Post ]"><br>'
       . '<input type="submit" name="act_ban" value="[ Ban User ]"><br>'
       . '<input type="submit" name="act_dismiss" value="[ Dismiss Report ]">'
       . '</form></td></tr>';
    echo '</table><br>';
}

echo '<hr><b>Keywords</b><br><font size="1">Plain word = auto-ban. Start with ~ = flag only. Built-in lists live in moderation.php.</font><br><br>';
echo '<form method="post" action="admin.php">' . csrf_field()
   . '<input type="hidden" name="do" value="addword">'
   . '<input type="text" name="word" size="16" maxlength="64"> <input type="submit" value="[ Add ]"></form><br>';
if ($words) {
    echo '<table width="100%" border="1" cellspacing="0" cellpadding="3">';
    foreach ($words as $w) {
        echo '<tr><td><font size="2">' . h($w['word']) . '</font></td><td align="right">'
           . '<form method="post" action="admin.php">' . csrf_field()
           . '<input type="hidden" name="do" value="delword"><input type="hidden" name="wid" value="' . (int)$w['id'] . '">'
           . '<input type="submit" value="[ Remove ]"></form></td></tr>';
    }
    echo '</table>';
} else {
    echo '<i>No custom keywords.</i>';
}

echo '<hr><b>Unban user</b><br><br><form method="post" action="admin.php">' . csrf_field()
   . '<input type="hidden" name="do" value="unban">'
   . '<input type="text" name="username" size="16" maxlength="32"> <input type="submit" value="[ Unban ]"></form>';

if (!empty($_SESSION['admin_ok'])) {
    echo '<hr><form method="post" action="admin.php">' . csrf_field()
       . '<input type="hidden" name="do" value="adminlogout"><input type="submit" value="[ End admin session ]"></form>';
}

require __DIR__ . '/footer.php';
