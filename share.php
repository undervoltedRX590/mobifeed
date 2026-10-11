<?php
require_once __DIR__ . '/functions.php';
$me = require_login();
$meId = (int)$me['id'];

$id = get_int('id');
$post = ($id > 0) ? fetch_post($id, $meId) : null;
if ($post === null) {
    message_page(
        'Not found',
        '<center><i>This post does not exist or was removed.</i></center><hr><center>[ <a href="index.php">Back to feed</a> ]</center>',
        404
    );
}
$self = 'share.php?id=' . $id;

// Only mutual friends can receive shares (same rule as direct messages).
$st = db()->prepare(
    "SELECT u.id, u.username
     FROM follows f1
     JOIN follows f2 ON f2.follower_id = f1.followed_id AND f2.followed_id = f1.follower_id
     JOIN users u ON u.id = f1.followed_id
     WHERE f1.follower_id = ? AND u.status = 'active'
     ORDER BY u.username LIMIT 200"
);
$st->execute([$meId]);
$friends = $st->fetchAll();
$friendIds = array_map(static function (array $f): int { return (int)$f['id']; }, $friends);

$error = '';
$note = '';
$picked = [];

if (is_post()) {
    $note = clean_text(post_str('note'), 150, false);
    if (isset($_POST['to']) && is_array($_POST['to'])) {
        foreach ($_POST['to'] as $v) {
            if (is_string($v) && ctype_digit($v) && strlen($v) < 10) {
                $picked[(int)$v] = true;
            }
        }
    }
    $targets = array_values(array_intersect(array_keys($picked), $friendIds));

    if (!csrf_ok()) {
        $error = 'Session expired. Please try again.';
    } elseif (!$targets) {
        $error = 'Choose at least one friend.';
    } elseif (count($targets) > 10) {
        $error = 'Choose up to 10 friends at a time.';
    } elseif (rate_limited('messages', $meId, RATE_MESSAGES_PER_HOUR - count($targets) + 1)) {
        $error = 'You are sending messages too fast. Please wait a bit.';
    } else {
        $ins = db()->prepare(
            'INSERT INTO messages (sender_id, receiver_id, message_text, shared_post_id, is_share) VALUES (?, ?, ?, ?, 1)'
        );
        db()->beginTransaction();
        try {
            foreach ($targets as $t) {
                $ins->execute([$meId, $t, $note, $id]);
            }
            db()->commit();
        } catch (Throwable $e) {
            db()->rollBack();
            throw $e;
        }
        flash('Shared with ' . count($targets) . (count($targets) === 1 ? ' friend.' : ' friends.'));
        redirect('post.php?id=' . $id);
    }
}

$page_title = 'Share';
require __DIR__ . '/header.php';
?>
<center><b>Share post</b></center>
<hr>
<font size="2">Post by <b><?php echo h($post['username']); ?></b>:</font><br>
<center><img src="<?php echo h(thumb_url((string)$post['image_path'])); ?>" alt="Post"></center>
<hr>
<?php if ($error !== ''): ?>
<font color="red"><b><?php echo h($error); ?></b></font><br><br>
<?php endif; ?>
<?php if (!$friends): ?>
<center><i>You have no mutual friends yet.</i></center><br>
<font size="2">You can share with people who follow you back. <a href="search.php">Find people</a>, open their profile and press Follow.</font>
<?php else: ?>
<form method="post" action="<?php echo h($self); ?>" accept-charset="utf-8">
<?php echo csrf_field(); ?>
Send to (up to 10):<br>
<?php foreach ($friends as $f): ?>
<input type="checkbox" name="to[]" value="<?php echo (int)$f['id']; ?>"<?php echo isset($picked[(int)$f['id']]) ? ' checked' : ''; ?>> <?php echo h($f['username']); ?><br>
<?php endforeach; ?>
<br>
Note (optional):<br>
<input type="text" name="note" size="22" maxlength="150" value="<?php echo h($note); ?>"><br><br>
<input type="submit" value="[ Send ]">
</form>
<?php endif; ?>
<hr>
<center>[ <a href="post.php?id=<?php echo $id; ?>">Back to post</a> ]</center>
<?php require __DIR__ . '/footer.php'; ?>
