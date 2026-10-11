<?php
require_once __DIR__ . '/functions.php';
$me = require_login();
$meId = (int)$me['id'];

$error = '';
$caption = '';

if (is_post()) {
    if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        // Body exceeded post_max_size, so PHP discarded everything.
        $error = 'That image is too large.';
    } elseif (!csrf_ok()) {
        $error = 'Session expired. Please try again.';
    } elseif (rate_limited('posts', $meId, RATE_POSTS_PER_HOUR)) {
        $error = 'Upload limit reached. Please try again later.';
    } else {
        $caption = clean_text(post_str('caption'), CAPTION_MAX);

        // Moderation first: a SEVERE caption bans the user and cancels the post (no file is stored).
        $word = '';
        $level = ($caption !== '') ? moderate_or_ban($caption, $meId, $word) : 'clean';

        $imgErr = '';
        $path = save_uploaded_image(isset($_FILES['image']) && is_array($_FILES['image']) ? $_FILES['image'] : [], $imgErr);
        if ($path === null) {
            $error = $imgErr;
        } else {
            $newId = 0;
            try {
                $st = db()->prepare('INSERT INTO posts (user_id, image_path, caption) VALUES (?, ?, ?)');
                $st->execute([$meId, $path, $caption !== '' ? $caption : null]);
                $newId = (int)db()->lastInsertId();
            } catch (Throwable $e) {
                delete_image_files($path);
                error_log('MobiFeed upload insert failed: ' . $e->getMessage());
                $error = 'Could not save your post. Please try again.';
            }
            if ($newId > 0) {
                if ($level === 'suspicious') {
                    flag_post($newId, null, '[AUTO] Suspicious caption term: ' . $word);
                }
                flash('Posted!');
                redirect('post.php?id=' . $newId);
            }
        }
    }
}

$page_title = 'Upload';
require __DIR__ . '/header.php';
?>
<center><b>Upload an image</b></center>
<hr>
<?php if ($error !== ''): ?>
<font color="red"><b><?php echo h($error); ?></b></font><br><br>
<?php endif; ?>
<form method="post" action="upload.php" enctype="multipart/form-data" accept-charset="utf-8">
<?php echo csrf_field(); ?>
<input type="hidden" name="MAX_FILE_SIZE" value="<?php echo (int)MAX_UPLOAD_BYTES; ?>">
Image (JPG, PNG or GIF):<br>
<input type="file" name="image"><br><br>
Caption (optional):<br>
<textarea name="caption" rows="4" cols="24"><?php echo h($caption); ?></textarea><br>
<font size="1">Max <?php echo (int)CAPTION_MAX; ?> characters. Images are resized to <?php echo (int)IMG_MAX_WIDTH; ?>px wide.</font><br><br>
<input type="submit" value="[ Upload ]">
</form>
<?php require __DIR__ . '/footer.php'; ?>
