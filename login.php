<?php
require_once __DIR__ . '/functions.php';

if (current_user()) {
    redirect('index.php');
}

$error = '';
$notice = (get_str('out') === '1') ? 'You have been logged out.' : '';
$username = '';

if (is_post()) {
    $username = clean_text(post_str('username'), 32, false);
    $password = post_str('password');
    $now = time();

    if (!csrf_ok()) {
        $error = 'Session expired. Please try again (cookies must be enabled).';
    } elseif ((int)($_SESSION['login_lock'] ?? 0) > $now) {
        $error = 'Too many failed attempts. Please wait a few minutes.';
    } else {
        $st = db()->prepare('SELECT id, password_hash, status FROM users WHERE username = ?');
        $st->execute([$username]);
        $row = $st->fetch() ?: null;

        // Always run one password_verify() so unknown users cost the same time as known ones.
        $dummy = '$2y$12$' . str_repeat('a', 53);
        $ok = password_verify(substr($password, 0, 1024), $row ? $row['password_hash'] : $dummy) && $row !== null;

        if ($ok) {
            if ($row['status'] === 'banned') {
                banned_page();
            }
            if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
                db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                    ->execute([password_hash($password, PASSWORD_DEFAULT), (int)$row['id']]);
            }
            unset($_SESSION['login_fails'], $_SESSION['login_lock']);
            login_user((int)$row['id'], post_str('remember') === '1');
            redirect('index.php');
        }

        $_SESSION['login_fails'] = (int)($_SESSION['login_fails'] ?? 0) + 1;
        if ($_SESSION['login_fails'] >= 5) {
            $_SESSION['login_lock'] = $now + 300;
            $_SESSION['login_fails'] = 0;
        }
        usleep(300000);
        $error = 'Invalid username or password.';
    }
}

$page_title = 'Log in';
require __DIR__ . '/header.php';
?>
<center><b>Log in</b></center>
<hr>
<?php if ($notice !== ''): ?>
<font color="green"><b><?php echo h($notice); ?></b></font><br><br>
<?php endif; ?>
<?php if ($error !== ''): ?>
<font color="red"><b><?php echo h($error); ?></b></font><br><br>
<?php endif; ?>
<form method="post" action="login.php" accept-charset="utf-8">
<?php echo csrf_field(); ?>
Username:<br>
<input type="text" name="username" size="20" maxlength="32" value="<?php echo h($username); ?>"><br><br>
Password:<br>
<input type="password" name="password" size="20" maxlength="72"><br><br>
<input type="checkbox" name="remember" value="1"> Keep me logged in<br><br>
<input type="submit" value="[ Log In ]">
</form>
<hr>
<center>New here? <a href="register.php">Create an account</a></center>
<?php require __DIR__ . '/footer.php'; ?>
