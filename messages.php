<?php
require_once __DIR__ . '/functions.php';
$me = require_login();
$meId = (int)$me['id'];

$with = get_int('with', 0);

/* =====================================================================
 * Inbox: list of mutual friends
 * ===================================================================== */
if ($with === 0) {
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

    $page_title = 'Messages';
    require __DIR__ . '/header.php';
    echo '<center><b>Direct messages</b><br><font size="2">[ <a href="search.php">Find people</a> ]</font></center><hr>';
    if (!$friends) {
        echo '<center><i>No friends to message yet.</i></center><br>'
           . '<font size="2">You can message people who follow you back. Use Find people to look someone up, open their profile and press Follow.</font>';
    } else {
        echo '<font size="2">Mutual friends:</font><br><br>';
        foreach ($friends as $f) {
            echo '[ <a href="messages.php?with=' . (int)$f['id'] . '">' . h($f['username']) . '</a> ]<br><br>';
        }
    }
    require __DIR__ . '/footer.php';
    exit;
}

/* =====================================================================
 * Conversation with one user
 * ===================================================================== */
$st = db()->prepare("SELECT id, username FROM users WHERE id = ? AND status = 'active'");
$st->execute([$with]);
$other = $st->fetch() ?: null;
if ($other === null || $with === $meId) {
    flash('That conversation is not available.');
    redirect('messages.php');
}
$self = 'messages.php?with=' . $with;
$isFriend = is_friend($meId, $with);

$error = '';
if (is_post() && $isFriend) {
    $text = clean_text(post_str('message'), MESSAGE_MAX);
    if (!csrf_ok()) {
        $error = 'Session expired. Please try again.';
    } elseif ($text === '') {
        $error = 'Please write a message.';
    } elseif (rate_limited('messages', $meId, RATE_MESSAGES_PER_HOUR)) {
        $error = 'You are sending messages too fast. Please wait a bit.';
    } else {
        db()->prepare('INSERT INTO messages (sender_id, receiver_id, message_text) VALUES (?, ?, ?)')
            ->execute([$meId, $with, $text]);
        redirect($self);
    }
}

$page_title = 'Chat';
require __DIR__ . '/header.php';
echo '<center><b>Chat with <a href="profile.php?id=' . $with . '">' . h($other['username']) . '</a></b></center><hr>';

if (!$isFriend) {
    echo '<center><i>You must be mutual friends to message this user.</i></center>';
    echo '<hr><center>[ <a href="profile.php?id=' . $with . '">View profile</a> ] [ <a href="messages.php">Back</a> ]</center>';
    require __DIR__ . '/footer.php';
    exit;
}

[$streak, $doneToday] = dm_streak($meId, $with);
if ($streak > 0) {
    echo '<center><font color="#cc6600"><b>Streak: ' . h(streak_label($streak)) . '</b></font>';
    if (!$doneToday) {
        echo '<br><font size="1">You both need to message today to keep it.</font>';
    }
    echo '</center><br>';
} else {
    echo '<center><font size="1" color="#666666">No streak yet. Message each other today to start one.</font></center><br>';
}

if ($error !== '') {
    echo '<font color="red"><b>' . h($error) . '</b></font><br><br>';
}
echo '<form method="post" action="' . h($self) . '" accept-charset="utf-8">' . csrf_field()
   . '<textarea name="message" rows="3" cols="24"></textarea><br>'
   . '<input type="submit" value="[ Send ]"></form><hr>';

// Newest first so the latest message sits right under the form on small screens.
$mp  = max(1, get_int('mp', 1));
$per = (int)MESSAGES_PER_PAGE;
$off = ($mp - 1) * $per;
$st = db()->prepare(
    "SELECT m.id, m.sender_id, m.message_text, m.created_at, m.is_share, m.shared_post_id,
            p.image_path, p.caption, pu.username AS post_author
     FROM messages m
     LEFT JOIN posts p ON p.id = m.shared_post_id
     LEFT JOIN users pu ON pu.id = p.user_id AND pu.status = 'active'
     WHERE (m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?)
     ORDER BY m.id DESC LIMIT " . ($per + 1) . ' OFFSET ' . (int)$off
);
$st->execute([$meId, $with, $with, $meId]);
$rows = $st->fetchAll();
$hasMore = count($rows) > $per;
$rows = array_slice($rows, 0, $per);

if (!$rows) {
    echo '<center><i>No messages yet. Say hello!</i></center>';
} else {
    echo '<table width="100%" border="1" cellspacing="0" cellpadding="3">';
    foreach ($rows as $m) {
        $mine = ((int)$m['sender_id'] === $meId);
        echo '<tr><td bgcolor="' . ($mine ? '#e6f0ff' : '#f4f4f4') . '"><font size="1"><b>'
           . ($mine ? 'You' : h($other['username'])) . '</b> ' . h(fmt_time((string)$m['created_at']))
           . '</font><br>';
        if ((int)$m['is_share'] === 1) {
            if ($m['post_author'] !== null && $m['image_path'] !== null) {
                $cap = (string)$m['caption'];
                echo '<font size="2"><i>Shared a post by <b>' . h($m['post_author']) . '</b></i></font><br>'
                   . '<a href="post.php?id=' . (int)$m['shared_post_id'] . '"><img src="'
                   . h(thumb_url((string)$m['image_path'])) . '" alt="Shared post" border="0"></a><br>';
                if ($cap !== '') {
                    echo '<font size="1">' . h(mb_substr($cap, 0, 80, 'UTF-8')) . (mb_strlen($cap, 'UTF-8') > 80 ? '...' : '') . '</font><br>';
                }
            } else {
                echo '<i>[This shared post is no longer available]</i><br>';
            }
            if ((string)$m['message_text'] !== '') {
                echo nl2br(h($m['message_text']), false);
            }
        } else {
            echo nl2br(h($m['message_text']), false);
        }
        echo '</td></tr>';
    }
    echo '</table>';
}

if ($mp > 1 || $hasMore) {
    echo '<br><center><font size="2">';
    if ($mp > 1) {
        echo '[ <a href="' . h($self . '&mp=' . ($mp - 1)) . '">&lt; Newer</a> ] ';
    }
    if ($hasMore) {
        echo '[ <a href="' . h($self . '&mp=' . ($mp + 1)) . '">Older &gt;</a> ]';
    }
    echo '</font></center>';
}
echo '<hr><center>[ <a href="messages.php">All chats</a> ]</center>';
require __DIR__ . '/footer.php';
