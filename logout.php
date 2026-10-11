<?php
require_once __DIR__ . '/functions.php';

// Reached via the "Logout" link, which carries the session's CSRF token.
$u = current_user();
if ($u !== null && csrf_ok()) {
    logout_user((int)$u['id']);       // destroys session, clears cookie + stored remember token
    redirect('login.php?out=1');
}
redirect($u !== null ? 'index.php' : 'login.php');
