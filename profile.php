<?php
require_once __DIR__ . '/functions.php';
$me = require_login();
$meId = (int)$me['id'];

$id = get_int('id', $meId);
$st = db()->prepare("SELECT id, username, created_at FROM users WHERE id = ? AND status = 'active'");
$st->execute([$id]);
$user = $st->fetch() ?: null;
if ($user === null) {
    message_page(
        'Not found',
        '<center><i>This user does not exist.</i></center><hr><center>[ <a href="index.php">Back to feed</a> ]</center>',
        404
    );
}
$isMe = ($id === $meId);

$st = db()->prepare(
    'SELECT (SELECT COUNT(*) FROM posts WHERE user_id = ?) AS posts,
            (SELECT COUNT(*) FROM follows WHERE followed_id = ?) AS followers,
            (SELECT COUNT(*) FROM follows WHERE follower_id = ?) AS following'
);
$st->execute([$id, $id, $id]);
$stats = $st->fetch();

$iFollow = (!$isMe) && is_following($meId, $id);
$followsMe = (!$isMe) && is_following($id, $meId);
$friends = (!$isMe) && $iFollow && $followsMe;

/* ---- Posts grid (3 x 3 thumbnails per page) ---- */
$pg  = max(1, get_int('pg', 1));
$per = (int)PROFILE_POSTS_PER_PAGE;
$off = ($pg - 1) * $per;
$st = db()->prepare(
    'SELECT id, image_path FROM posts WHERE user_id = ? ORDER BY id DESC LIMIT '
    . ($per + 1) . ' OFFSET ' . (int)$off
);
$st->execute([$id]);
$posts = $st->fetchAll();
$hasMore = count($posts) > $per;
$posts = array_slice($posts, 0, $per);
$selfUrl = 'profile.php?id=' . $id;

$page_title = $user['username'];
require __DIR__ . '/header.php';

echo '<center><font size="4"><b>' . h($user['username']) . '</b></font><br>';
echo '<font size="1" color="#666666">Joined ' . h(substr((string)$user['created_at'], 0, 10)) . '</font></center>';
echo '<br><center><font size="2">Posts: <b>' . (int)$stats['posts'] . '</b> | Followers: <b>' . (int)$stats['followers']
   . '</b> | Following: <b>' . (int)$stats['following'] . '</b></font></center><br>';

if ($isMe) {
    echo '<center>[ <a href="upload.php">Upload</a> ] [ <a href="messages.php">Messages</a> ]</center>';
} else {
    echo '<center>';
    if ($friends) {
        echo '<font size="2" color="green"><b>Mutual friends</b></font><br>';
    } elseif ($followsMe) {
        echo '<font size="2"><i>Follows you</i></font><br>';
    }
    echo '<form method="post" action="follow.php">' . csrf_field()
       . '<input type="hidden" name="uid" value="' . $id . '">'
       . '<input type="hidden" name="do" value="' . ($iFollow ? 'unfollow' : 'follow') . '">'
       . '<input type="submit" value="' . ($iFollow ? '[ Unfollow ]' : '[ Follow ]') . '"></form>';
    if ($friends) {
        echo '[ <a href="messages.php?with=' . $id . '">Send message</a> ]';
    } else {
        echo '<font size="1"><i>Follow each other to exchange messages.</i></font>';
    }
    echo '</center>';
}

echo '<hr>';
if (!$posts) {
    echo '<center><i>No posts yet.</i></center>';
} else {
    echo '<table width="100%" border="0" cellspacing="0" cellpadding="2">';
    $n = 0;
    foreach ($posts as $p) {
        if ($n % 3 === 0) {
            echo '<tr>';
        }
        echo '<td align="center" valign="top"><a href="post.php?id=' . (int)$p['id'] . '"><img src="'
           . h(thumb_url((string)$p['image_path'])) . '" alt="Post" border="0"></a></td>';
        $n++;
        if ($n % 3 === 0) {
            echo '</tr>';
        }
    }
    while ($n % 3 !== 0) {
        echo '<td>&nbsp;</td>';
        $n++;
        if ($n % 3 === 0) {
            echo '</tr>';
        }
    }
    echo '</table>';
}

if ($pg > 1 || $hasMore) {
    echo '<br><center><font size="2">';
    if ($pg > 1) {
        echo '[ <a href="' . h($selfUrl . '&pg=' . ($pg - 1)) . '">&lt; Newer</a> ] ';
    }
    if ($hasMore) {
        echo '[ <a href="' . h($selfUrl . '&pg=' . ($pg + 1)) . '">Older &gt;</a> ]';
    }
    echo '</font></center>';
}

require __DIR__ . '/footer.php';
