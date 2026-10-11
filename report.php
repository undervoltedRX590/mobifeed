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
$self = 'report.php?id=' . $id;
$reasons = [
    'Spam or scam',
    'Nudity or sexual content',
    'Harassment or bullying',
    'Violence or dangerous content',
    'Hate speech',
    'Other',
];

$error = '';
$selected = '';
$details = '';

if (is_post()) {
    $selected = post_str('reason');
    $details  = clean_text(post_str('details'), 150, false);

    if (!csrf_ok()) {
        $error = 'Session expired. Please try again.';
    } elseif ((int)$post['user_id'] === $meId) {
        $error = 'You cannot report your own post.';
    } elseif (!in_array($selected, $reasons, true)) {
        $error = 'Please choose a reason.';
    } elseif (rate_limited('reports', $meId, RATE_REPORTS_PER_HOUR)) {
        $error = 'Too many reports. Please try again later.';
    } else {
        $st = db()->prepare("SELECT 1 FROM reports WHERE post_id = ? AND reporter_id = ? AND status = 'pending'");
        $st->execute([$id, $meId]);
        if ($st->fetchColumn()) {
            flash('You already reported this post. Thank you.');
        } else {
            flag_post($id, $meId, $selected . ($details !== '' ? ': ' . $details : ''));
            flash('Thank you. Our moderators will review this post.');
        }
        redirect('index.php?post=' . $id);
    }
}

$page_title = 'Report';
require __DIR__ . '/header.php';
?>
<center><b>Report post</b></center>
<hr>
<?php if ($error !== ''): ?>
<font color="red"><b><?php echo h($error); ?></b></font><br><br>
<?php endif; ?>
<font size="2">Post by <b><?php echo h($post['username']); ?></b>:</font><br>
<center><a href="post.php?id=<?php echo $id; ?>"><img src="<?php echo h(thumb_url((string)$post['image_path'])); ?>" alt="Post" border="0"></a></center>
<br>
<form method="post" action="<?php echo h($self); ?>" accept-charset="utf-8">
<?php echo csrf_field(); ?>
Reason:<br>
<select name="reason">
<option value="">-- choose --</option>
<?php foreach ($reasons as $r): ?>
<option value="<?php echo h($r); ?>"<?php echo ($r === $selected) ? ' selected' : ''; ?>><?php echo h($r); ?></option>
<?php endforeach; ?>
</select><br><br>
Details (optional):<br>
<input type="text" name="details" size="22" maxlength="150" value="<?php echo h($details); ?>"><br><br>
<input type="submit" value="[ Send Report ]">
</form>
<hr>
<center>[ <a href="index.php?post=<?php echo $id; ?>">Cancel</a> ]</center>
<?php require __DIR__ . '/footer.php'; ?>
