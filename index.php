<?php
require_once __DIR__ . '/functions.php';
$me = require_login();
$meId = (int)$me['id'];

// One post per page. Cursor = post id, so paging stays stable while new posts arrive.
// "Previous" = newer post, "Next" = older post.
$feed = (get_str('feed') === 'following') ? 'following' : 'all';
$cur  = get_int('post', 0);

$where = "u.status = 'active'";
$base  = [];
if ($feed === 'following') {
    $where .= ' AND p.user_id IN (SELECT followed_id FROM follows WHERE follower_id = ?)';
    $base[] = $meId;
}

$pick = function (string $extra, array $args, string $dir) use ($where, $base): ?int {
    $sql = "SELECT p.id FROM posts p JOIN users u ON u.id = p.user_id
            WHERE $where $extra ORDER BY p.id $dir LIMIT 1";
    $st = db()->prepare($sql);
    $st->execute(array_merge($base, $args));
    $v = $st->fetchColumn();
    return $v === false ? null : (int)$v;
};

$curId = ($cur > 0) ? $pick('AND p.id <= ?', [$cur], 'DESC') : $pick('', [], 'DESC');
$post  = $curId !== null ? fetch_post($curId, $meId) : null;
$newer = $post ? $pick('AND p.id > ?', [$curId], 'ASC') : null;
$older = $post ? $pick('AND p.id < ?', [$curId], 'DESC') : null;

$page_title = 'Feed';
require __DIR__ . '/header.php';

echo '<center><font size="2">[ '
   . ($feed === 'all' ? '<b>All</b>' : '<a href="index.php">All</a>')
   . ' | '
   . ($feed === 'following' ? '<b>Following</b>' : '<a href="index.php?feed=following">Following</a>')
   . ' ]</font></center><hr>';

if (!$post) {
    if ($feed === 'following') {
        echo '<center><i>Nothing here yet. Follow people to see their posts.</i></center>';
    } else {
        echo '<center><i>No posts yet.</i><br><br>[ <a href="upload.php">Upload the first image</a> ]</center>';
    }
} else {
    render_post($post, 'index.php?post=' . $curId . '&feed=' . $feed, true);
    echo '<hr><table width="100%" border="0" cellpadding="2" cellspacing="0"><tr>';
    echo '<td align="left" width="50%"><font size="2">';
    echo $newer ? '[ <a href="index.php?post=' . $newer . '&feed=' . $feed . '">&lt; Previous Post</a> ]' : '&nbsp;';
    echo '</font></td><td align="right" width="50%"><font size="2">';
    echo $older ? '[ <a href="index.php?post=' . $older . '&feed=' . $feed . '">Next Post &gt;</a> ]' : '<i>End of feed</i>';
    echo '</font></td></tr></table>';
}

require __DIR__ . '/footer.php';
