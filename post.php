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
$self = 'post.php?id=' . $id;
$isOwner = ((int)$post['user_id'] === $meId);

/* ---- Like / Unlike (token link) ---- */
$act = get_str('act');
if ($act === 'like' || $act === 'unlike') {
    if (csrf_ok()) {
        if ($act === 'like') {
            db()->prepare('INSERT IGNORE INTO likes (post_id, user_id) VALUES (?, ?)')->execute([$id, $meId]);
        } else {
            db()->prepare('DELETE FROM likes WHERE post_id = ? AND user_id = ?')->execute([$id, $meId]);
        }
    } else {
        flash('Session expired. Please try again.');
    }
    redirect(safe_back(get_str('back'), $self));
}

/* ---- Like / Unlike a comment (token link) ---- */
if ($act === 'clike' || $act === 'cunlike') {
    if (csrf_ok()) {
        $cid = get_int('cid');
        $st = db()->prepare('SELECT 1 FROM comments WHERE id = ? AND post_id = ?');
        $st->execute([$cid, $id]);
        if ($st->fetchColumn()) {
            if ($act === 'clike') {
                db()->prepare('INSERT IGNORE INTO comment_likes (comment_id, user_id) VALUES (?, ?)')->execute([$cid, $meId]);
            } else {
                db()->prepare('DELETE FROM comment_likes WHERE comment_id = ? AND user_id = ?')->execute([$cid, $meId]);
            }
        }
    } else {
        flash('Session expired. Please try again.');
    }
    redirect(safe_back(get_str('back'), $self));
}

/* ---- POST actions: comment, delete comment, delete post ---- */
$error = '';
if (is_post()) {
    $do = post_str('do');
    if (!csrf_ok()) {
        $error = 'Session expired. Please try again.';
    } elseif ($do === 'comment') {
        $text = clean_text(post_str('comment'), COMMENT_MAX);
        if ($text === '') {
            $error = 'Please write a comment.';
        } elseif (rate_limited('comments', $meId, RATE_COMMENTS_PER_HOUR)) {
            $error = 'Too many comments. Please try again later.';
        } else {
            $word = '';
            $level = moderate_or_ban($text, $meId, $word);   // SEVERE: ban + logout, comment is NOT stored
            $st = db()->prepare('INSERT INTO comments (post_id, user_id, comment_text) VALUES (?, ?, ?)');
            $st->execute([$id, $meId, $text]);
            $cid = (int)db()->lastInsertId();
            if ($level === 'suspicious') {
                flag_post($id, null, '[AUTO] Suspicious comment #' . $cid . ' (term: ' . $word . ')');
            }
            flash('Comment added.');
            redirect($self);
        }
    } elseif ($do === 'delcomment') {
        $cid = post_int('cid');
        $st = db()->prepare('DELETE FROM comments WHERE id = ? AND post_id = ? AND (user_id = ? OR ? = 1)');
        $st->execute([$cid, $id, $meId, $isOwner ? 1 : 0]);
        flash($st->rowCount() > 0 ? 'Comment deleted.' : 'Could not delete that comment.');
        redirect($self);
    } elseif ($do === 'delpost') {
        if (!$isOwner) {
            $error = 'You can only delete your own posts.';
        } elseif (post_str('confirm') !== '1') {
            $error = 'Tick the box to confirm deleting this post.';
        } else {
            purge_post($id);
            flash('Post deleted.');
            redirect('index.php');
        }
    }
}

/* ---- Comments (newest first, paged) ---- */
$cp  = max(1, get_int('cp', 1));
$per = (int)COMMENTS_PER_PAGE;
$off = ($cp - 1) * $per;
$st = db()->prepare(
    "SELECT c.id, c.user_id, c.comment_text, c.created_at, u.username,
            (SELECT COUNT(*) FROM comment_likes cl WHERE cl.comment_id = c.id) AS like_count,
            (SELECT COUNT(*) FROM comment_likes cl2 WHERE cl2.comment_id = c.id AND cl2.user_id = ?) AS liked
     FROM comments c JOIN users u ON u.id = c.user_id
     WHERE c.post_id = ? AND u.status = 'active'
     ORDER BY like_count DESC, c.id DESC LIMIT " . ($per + 1) . ' OFFSET ' . (int)$off
);
$st->execute([$meId, $id]);
$comments = $st->fetchAll();
$hasMore = count($comments) > $per;
$comments = array_slice($comments, 0, $per);

$page_title = 'Post';
require __DIR__ . '/header.php';

if ($error !== '') {
    echo '<font color="red"><b>' . h($error) . '</b></font><hr>';
}

render_post($post, $self, false);

if ($isOwner) {
    echo '<hr><form method="post" action="' . h($self) . '">' . csrf_field()
       . '<input type="hidden" name="do" value="delpost">'
       . '<font size="2"><input type="checkbox" name="confirm" value="1"> Yes, delete my post</font><br>'
       . '<input type="submit" value="[ Delete Post ]"></form>';
}

echo '<hr><a name="comments"></a><b>Comments</b> <font size="1">(most liked first)</font><br><br>';
echo '<form method="post" action="' . h($self) . '" accept-charset="utf-8">' . csrf_field()
   . '<input type="hidden" name="do" value="comment">'
   . '<textarea name="comment" rows="3" cols="24"></textarea><br>'
   . '<input type="submit" value="[ Post Comment ]"></form><br>';

if (!$comments) {
    echo '<i>No comments yet.</i>';
} else {
    echo '<table width="100%" border="1" cellspacing="0" cellpadding="3">';
    $rank = 0;
    $cBack = $self . ($cp > 1 ? '&cp=' . $cp : '');
    foreach ($comments as $c) {
        $cUid   = (int)$c['user_id'];
        $cLikes = (int)$c['like_count'];
        $cLiked = ((int)$c['liked'] > 0);
        $cLikeUrl = $self . '&act=' . ($cLiked ? 'cunlike' : 'clike') . '&cid=' . (int)$c['id']
                  . '&t=' . urlencode(csrf_token()) . '&back=' . urlencode($cBack);
        echo '<tr><td>';
        if ($cp === 1 && $rank === 0 && $cLikes > 0) {
            echo '<font size="1" color="#cc6600"><b>Top comment</b></font><br>';
        }
        $rank++;
        echo '<font size="1"><b><a href="profile.php?id=' . $cUid . '">' . h($c['username']) . '</a></b> '
           . h(fmt_time((string)$c['created_at'])) . '</font><br>'
           . nl2br(h($c['comment_text']), false)
           . '<br><font size="1">Likes: <b>' . $cLikes . '</b> [ <a href="' . h($cLikeUrl) . '">'
           . ($cLiked ? 'Unlike' : 'Like') . '</a> ]</font>';
        if ($cUid === $meId || $isOwner) {
            echo '<form method="post" action="' . h($self) . '">' . csrf_field()
               . '<input type="hidden" name="do" value="delcomment">'
               . '<input type="hidden" name="cid" value="' . (int)$c['id'] . '">'
               . '<input type="submit" value="[ Delete ]"></form>';
        }
        echo '</td></tr>';
    }
    echo '</table>';
}

if ($cp > 1 || $hasMore) {
    echo '<br><center><font size="2">';
    if ($cp > 1) {
        echo '[ <a href="' . h($self . '&cp=' . ($cp - 1)) . '">&lt; Newer</a> ] ';
    }
    if ($hasMore) {
        echo '[ <a href="' . h($self . '&cp=' . ($cp + 1)) . '">Older &gt;</a> ]';
    }
    echo '</font></center>';
}

echo '<hr><center>[ <a href="index.php">Back to feed</a> ]</center>';
require __DIR__ . '/footer.php';
