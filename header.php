<?php
/**
 * MobiFeed - header: doctype, master centering table, navigation, flash message.
 * Set $page_title before including. footer.php closes everything opened here.
 * Pure HTML 4.01 Transitional: no CSS, no JavaScript.
 */
if (!defined('MOBIFEED_LOADED')) { exit; }

$_hdr_user  = current_user();
$_hdr_title = (isset($page_title) && $page_title !== '') ? $page_title . ' - ' . APP_NAME : APP_NAME;
$_hdr_flash = take_flash();
?>
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd">
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo h($_hdr_title); ?></title>
</head>
<body bgcolor="#ffffff" text="#000000" link="#0000cc" vlink="#551a8b" alink="#cc0000">
<table width="100%" border="0" cellspacing="0" cellpadding="0"><tr><td align="center"><table width="320" border="1" cellpadding="6" cellspacing="0">
<tr><td bgcolor="#f0f0f0" align="center">
<font size="4"><b><?php echo h(APP_NAME); ?></b></font><br>
<?php if ($_hdr_user): ?>
[ <a href="index.php">Feed</a> | <a href="search.php">Search</a> | <a href="upload.php">Upload</a> | <a href="messages.php">DMs</a> | <a href="profile.php">Profile</a> ]<br>
<font size="1">Hi, <b><?php echo h($_hdr_user['username']); ?></b> | <a href="logout.php?t=<?php echo h(urlencode(csrf_token())); ?>">Logout</a><?php if (is_admin()): ?> | <a href="admin.php">Admin</a><?php endif; ?></font>
<?php else: ?>
[ <a href="login.php">Login</a> | <a href="register.php">Register</a> ]
<?php endif; ?>
</td></tr>
<tr><td>
<?php if ($_hdr_flash !== ''): ?>
<font color="green"><b><?php echo h($_hdr_flash); ?></b></font>
<hr>
<?php endif; ?>
