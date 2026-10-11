<?php
require_once __DIR__ . '/functions.php';
$me = require_login();
$meId = (int)$me['id'];

$q  = clean_text(get_str('q'), 32, false);
$sp = max(1, get_int('sp', 1));
$per = 10;
$results = [];
$hasMore = false;

if ($q !== '') {
    // Escape LIKE wildcards typed by the user (! is the escape character).
    $esc = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q);
    $off = ($sp - 1) * $per;
    $st = db()->prepare(
        "SELECT u.id, u.username,
                (SELECT COUNT(*) FROM follows f1 WHERE f1.follower_id = ? AND f1.followed_id = u.id) AS i_follow,
                (SELECT COUNT(*) FROM follows f2 WHERE f2.follower_id = u.id AND f2.followed_id = ?) AS follows_me
         FROM users u
         WHERE u.status = 'active' AND u.username LIKE ? ESCAPE '!'
         ORDER BY (u.username = ?) DESC, (u.username LIKE ? ESCAPE '!') DESC, u.username ASC
         LIMIT " . ($per + 1) . ' OFFSET ' . (int)$off
    );
    $st->execute([$meId, $meId, '%' . $esc . '%', $q, $esc . '%']);
    $results = $st->fetchAll();
    $hasMore = count($results) > $per;
    $results = array_slice($results, 0, $per);
}

$page_title = 'Search';
require __DIR__ . '/header.php';
?>
<center><b>Find people</b></center>
<hr>
<form method="get" action="search.php" accept-charset="utf-8">
<input type="text" name="q" size="18" maxlength="32" value="<?php echo h($q); ?>">
<input type="submit" value="[ Search ]">
</form>
<hr>
<?php
if ($q === '') {
    echo '<font size="2">Type a username (or part of one) to find an account.</font>';
} elseif (!$results) {
    echo '<center><i>No accounts found for "' . h($q) . '".</i></center>';
} else {
    echo '<table width="100%" border="1" cellspacing="0" cellpadding="4">';
    foreach ($results as $r) {
        $uid = (int)$r['id'];
        $isMe = ($uid === $meId);
        $iFollow = ((int)$r['i_follow'] > 0);
        $followsMe = ((int)$r['follows_me'] > 0);
        $friends = $iFollow && $followsMe;

        echo '<tr><td><b><a href="profile.php?id=' . $uid . '">' . h($r['username']) . '</a></b><br><font size="1">';
        if ($isMe) {
            echo '<i>This is you</i>';
        } elseif ($friends) {
            echo '<font color="green"><b>Mutual friends</b></font> [ <a href="messages.php?with=' . $uid . '">Message</a> ]';
        } elseif ($iFollow) {
            echo 'You follow them. Waiting for them to follow back.';
        } elseif ($followsMe) {
            echo 'Follows you. Follow back to become friends.';
        } else {
            echo '[ <a href="profile.php?id=' . $uid . '">View profile</a> ] to follow';
        }
        echo '</font></td></tr>';
    }
    echo '</table>';

    if ($sp > 1 || $hasMore) {
        $base = 'search.php?q=' . urlencode($q);
        echo '<br><center><font size="2">';
        if ($sp > 1) {
            echo '[ <a href="' . h($base . '&sp=' . ($sp - 1)) . '">&lt; Previous</a> ] ';
        }
        if ($hasMore) {
            echo '[ <a href="' . h($base . '&sp=' . ($sp + 1)) . '">Next &gt;</a> ]';
        }
        echo '</font></center>';
    }
}
require __DIR__ . '/footer.php';
