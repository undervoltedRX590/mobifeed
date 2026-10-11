<?php
require_once __DIR__ . '/functions.php';

if (current_user()) {
    redirect('index.php');
}

$error = '';
$username = '';

if (is_post()) {
    $username = clean_text(post_str('username'), 32, false);
    $pw1 = post_str('password');
    $pw2 = post_str('password2');

    if (!csrf_ok()) {
        $error = 'Session expired. Please try again (cookies must be enabled).';
    } elseif (!preg_match('/^[A-Za-z0-9_]{3,32}$/D', $username)) {
        $error = 'Username must be 3-32 characters: letters, digits or underscore.';
    } elseif (in_array(strtolower($username), ['admin', 'administrator', 'root', 'system', 'moderator', 'mobifeed', 'support'], true)
        || moderate_text($username)['level'] !== 'clean') {
        $error = 'That username is not allowed.';
    } elseif (strlen($pw1) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif (strlen($pw1) > 72) {
        $error = 'Password must be at most 72 characters.';
    } elseif ($pw1 !== $pw2) {
        $error = 'Passwords do not match.';
    } else {
        $st = db()->prepare('SELECT 1 FROM users WHERE username = ?');
        $st->execute([$username]);
        if ($st->fetchColumn()) {
            $error = 'That username is already taken.';
        } else {
            try {
                $st = db()->prepare('INSERT INTO users (username, password_hash) VALUES (?, ?)');
                $st->execute([$username, password_hash($pw1, PASSWORD_DEFAULT)]);
                $newId = (int)db()->lastInsertId();
                login_user($newId, false);
                flash('Welcome to ' . APP_NAME . '!');
                redirect('index.php');
            } catch (PDOException $e) {
                if ((string)$e->getCode() === '23000') {      // lost a race for the username
                    $error = 'That username is already taken.';
                } else {
                    throw $e;
                }
            }
        }
    }
}

$page_title = 'Register';
require __DIR__ . '/header.php';
?>
<center><b>Create account</b></center>
<hr>
<?php if ($error !== ''): ?>
<font color="red"><b><?php echo h($error); ?></b></font><br><br>
<?php endif; ?>
<form method="post" action="register.php" accept-charset="utf-8">
<?php echo csrf_field(); ?>
Username:<br>
<input type="text" name="username" size="20" maxlength="32" value="<?php echo h($username); ?>"><br>
<font size="1">3-32 letters, digits or _</font><br><br>
Password:<br>
<input type="password" name="password" size="20" maxlength="72"><br><br>
Repeat password:<br>
<input type="password" name="password2" size="20" maxlength="72"><br><br>
<input type="submit" value="[ Sign Up ]">
</form>
<hr>
<center>Have an account? <a href="login.php">Log in</a></center>
<?php require __DIR__ . '/footer.php'; ?>
