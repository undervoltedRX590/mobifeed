<?php
require_once __DIR__ . '/functions.php';
$me = require_login();
$meId = (int)$me['id'];

if (!is_post()) {
    redirect('index.php');
}

$uid = post_int('uid');
$do  = post_str('do');
$back = ($uid > 0) ? 'profile.php?id=' . $uid : 'index.php';

if (!csrf_ok()) {
    flash('Session expired. Please try again.');
    redirect($back);
}

$st = db()->prepare("SELECT id FROM users WHERE id = ? AND status = 'active'");
$st->execute([$uid]);
if ($uid <= 0 || $uid === $meId || !$st->fetchColumn()) {
    flash('You cannot follow that user.');
    redirect('index.php');
}

if ($do === 'follow') {
    db()->prepare('INSERT IGNORE INTO follows (follower_id, followed_id) VALUES (?, ?)')->execute([$meId, $uid]);
    flash(is_friend($meId, $uid) ? 'You are now mutual friends!' : 'Followed.');
} elseif ($do === 'unfollow') {
    db()->prepare('DELETE FROM follows WHERE follower_id = ? AND followed_id = ?')->execute([$meId, $uid]);
    flash('Unfollowed.');
}
redirect($back);
