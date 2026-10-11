# MobiFeed

An image-first social feed ("TikTok, but only for static images") written in **plain PHP + MySQL** and **pure legacy HTML**: no CSS and no JavaScript anywhere. That makes it light enough for old J2ME, Symbian, NetFront and Opera Mini browsers (240x320 screens), while still working on modern phones and desktops. My hosted MobiFeed website can be found here: http://mobifeed.rf.gd

## Features

- **Accounts:** sign up, log in, optional "keep me logged in", banned accounts are blocked on every request
- **Feed:** one image per page with Previous / Next links, plus an *All* / *Following* switch
- **Uploads:** JPG, PNG and GIF, resized server-side with GD (max 600px wide, JPEG quality 70) plus a small thumbnail; EXIF/location data is stripped
- **Social:** likes, comments, likes on comments (most-liked comment first), follow system, account search
- **Direct messages** between mutual friends only, with **streaks** (consecutive days both of you messaged) and **post sharing**
- **Moderation:** keyword filter (auto-ban for severe words, auto-flag for suspicious ones), user reports, admin panel (delete post, ban user, dismiss report, manage keywords, unban)
- **Security basics:** PDO prepared statements, escaped output, CSRF tokens, upload validation, rate limits, strict Content-Security-Policy

## Requirements

- PHP 8.0 or newer with `pdo_mysql`, `gd` (JPEG/PNG/GIF) and `mbstring` (`fileinfo` and `exif` are used when available)
- MySQL 5.7+ or MariaDB 10.3+
- Apache with `.htaccess` support (for nginx, see the notes below)

## Quick start (local, e.g. XAMPP)

1. Copy this folder to your web root (for XAMPP: `C:\xampp\htdocs\mobifeed`).
2. Copy `config.sample.php` to **`config.php`** and edit it: database login, admin settings, timezone. (XAMPP defaults: user `root`, empty password.)
3. Create the tables: open phpMyAdmin, go to **Import**, and choose `sql/schema.sql`.
4. Open `http://localhost/mobifeed/`, register an account.
5. To use the admin panel, put your username in `ADMIN_USERNAMES` in `config.php`, or set `ADMIN_PASSKEY`. Admin stays locked until you do.

## Shared hosting

- Use `sql/schema_hosting.sql` instead (it has no `CREATE DATABASE` line, because hosts create the database for you) and import it into the database your host gave you.
- `DB_HOST` is usually **not** `localhost`; use the host shown in your hosting panel.
- Make sure the `uploads/` folder exists and is writable.
- Some free hosts run a JavaScript browser check before your site loads. Browsers without JavaScript (the very phones MobiFeed targets) cannot pass it.

## Upgrading from the first version

Run `sql/migrate_v2.sql` once. It adds shared posts in DMs and likes on comments.

## Configuration (`config.php`)

| Setting | What it does |
|---|---|
| `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` | Database connection |
| `BASE_URL` | Optional absolute URL for redirects (some very old browsers prefer it) |
| `DISPLAY_TIMEZONE` | Timezone for displayed times and where streak days roll over |
| `SHOW_ERRORS` | `true` only while developing |
| `ADMIN_USERNAMES`, `ADMIN_PASSKEY` | Who can open `admin.php` |
| `MAX_UPLOAD_BYTES`, `IMG_*`, `THUMB_*` | Upload size and image processing |
| `RATE_*` | Hourly limits for posts, comments, messages and reports |

## Project structure

```
config.sample.php   copy to config.php (config.php is git-ignored)
functions.php       sessions, CSRF, auth/ban check, images, shared helpers
moderation.php      keyword filter, auto-ban, auto-flag
header.php / footer.php   page frame (master table + navigation)
index.php           feed          upload.php   image upload
post.php            post, comments, likes      share.php    share with friends
profile.php         profile       follow.php   follow / unfollow
search.php          find accounts messages.php direct messages
report.php          report a post admin.php    moderation panel
login.php / register.php / logout.php
sql/                database schemas and migration
uploads/            resized images (contents are git-ignored)
```

## Design rules (please keep them when contributing)

- **No CSS and no JavaScript.** Layout uses HTML tables and tags like `<font>`, `<center>`, `<b>`.
- All database access uses PDO prepared statements.
- All user-generated output goes through `h()` (a wrapper around `htmlspecialchars`).
- Every action that changes data needs a CSRF token.
- Nothing is loaded from external sites.

## Security notes

- **Never commit `config.php`.** It holds your database password and admin passkey. It is already in `.gitignore`.
- Use a long random `ADMIN_PASSKEY`, and serve the site over HTTPS if you can.
- Found a security problem? Please report it privately to the maintainer instead of posting it publicly.

## Known limitations

- Browsers must accept cookies (sessions, CSRF and remember-me depend on them).
- There is no email verification, password reset, or captcha yet.
- Direct messages are not keyword-scanned; only captions and comments are.
- One remember-me token per account: a new "keep me logged in" login replaces the old one.
- Nginx has no `.htaccess`: deny direct access to `config|functions|moderation|header|footer.php` and disable PHP execution inside `uploads/`.

## Contributing

Issues and pull requests are welcome. Please follow the design rules above and describe how you tested your change.

## License

GPL
