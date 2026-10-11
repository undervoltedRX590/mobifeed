<?php
/**
 * MobiFeed - automated moderation.
 *
 *   SEVERE      -> reject the submission, ban the user, log them out, show the ban page.
 *   SUSPICIOUS  -> accept the submission but flag the post in `reports` for admin review.
 *
 * Word sources:
 *   1. Built-in dictionaries below (extend them freely).
 *   2. Table `banned_keywords` (managed in admin.php): plain words are SEVERE,
 *      words starting with "~" are SUSPICIOUS.
 *
 * Matching is whole-word / whole-phrase (so "class" never trips on "ass") and also
 * checks simple evasions: leetspeak (sh1t, $hit), stretched letters (fuuuuck) and
 * spaced-out letters (f u c k). Common endings (s, ed, er, ing...) are accepted.
 */
if (!defined('MOBIFEED_LOADED')) { exit; }

/** Extreme profanity / explicit adult content. Add more here as needed. */
function mod_builtin_severe(): array
{
    return [
        'fuck', 'fucking', 'fucked', 'fucker', 'fuckers', 'motherfucker', 'motherfucking', 'fck', 'fuk',
        'cunt', 'cunts',
        'porn', 'porno', 'pornography', 'pornhub', 'xxx', 'hentai',
        'blowjob', 'blowjobs', 'handjob', 'cumshot', 'creampie', 'gangbang', 'bukkake',
        'pedophile', 'paedophile', 'pedophilia', 'pedo', 'bestiality',
        'child porn', 'kiddie porn',
    ];
}

/** Milder or context-dependent words: flagged for human review only. */
function mod_builtin_suspicious(): array
{
    return [
        'shit', 'shitty', 'bullshit', 'bitch', 'bitches', 'asshole', 'bastard',
        'dick', 'dicks', 'cock', 'cocks', 'pussy', 'slut', 'whore', 'tits', 'boobs',
        'nude', 'nudes', 'naked', 'nsfw', 'sex', 'sexy', 'sexting', 'anal', 'erotic',
        'rape', 'raped', 'rapist', 'kill yourself', 'kys', 'nazi',
    ];
}

/** Lower-case, strip punctuation, collapse whitespace; result is " word word " (space padded). */
function mod_squash(string $t): string
{
    $t = (string)preg_replace('/[^\p{L}\p{N}]+/u', ' ', $t);
    return ' ' . trim($t) . ' ';
}

function mod_prepare(string $word): string
{
    return trim(mod_squash(mb_strtolower($word, 'UTF-8')));
}

/** @return array{severe: string[], suspicious: string[]} normalised terms */
function mod_terms(): array
{
    static $terms = null;
    if ($terms !== null) {
        return $terms;
    }
    $severe = mod_builtin_severe();
    $susp   = mod_builtin_suspicious();
    try {
        $rows = db()->query('SELECT word FROM banned_keywords')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $w) {
            $w = (string)$w;
            if ($w !== '' && $w[0] === '~') {
                $susp[] = substr($w, 1);
            } elseif ($w !== '') {
                $severe[] = $w;
            }
        }
    } catch (Throwable $e) {
        error_log('MobiFeed moderation: could not read banned_keywords: ' . $e->getMessage());
    }
    $terms = ['severe' => [], 'suspicious' => []];
    foreach (['severe' => $severe, 'suspicious' => $susp] as $level => $list) {
        $seen = [];
        foreach ($list as $w) {
            $n = mod_prepare((string)$w);
            if ($n !== '' && !isset($seen[$n])) {
                $seen[$n] = true;
                $terms[$level][] = $n;
            }
        }
    }
    return $terms;
}

/** Text variants that catch common obfuscation. Each is space padded. */
function mod_variants(string $text): array
{
    $t = mb_strtolower(mb_scrub(mb_substr($text, 0, 5000, 'UTF-8'), 'UTF-8'), 'UTF-8');
    $leetRaw = strtr($t, ['0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't', '@' => 'a', '$' => 's']);

    $plain = mod_squash($t);
    $leet  = mod_squash($leetRaw);
    $stretched = mod_squash((string)preg_replace('/(\p{L})\1{2,}/u', '$1', $leetRaw));
    $joined = (string)preg_replace_callback(
        '/(?<![\p{L}\p{N}])(?:\p{L} ){2,}\p{L}(?![\p{L}\p{N}])/u',
        static function (array $m): string { return str_replace(' ', '', $m[0]); },
        $leet
    );
    return array_values(array_unique([$plain, $leet, $stretched, $joined]));
}

function mod_find(array $variants, array $terms): ?string
{
    $suffixes = ['', 's', 'es', 'ed', 'd', 'er', 'ers', 'ing', 'y'];
    foreach ($terms as $term) {
        foreach ($variants as $v) {
            foreach ($suffixes as $sx) {
                if (strpos($v, ' ' . $term . $sx . ' ') !== false) {
                    return $term;
                }
            }
        }
    }
    return null;
}

/**
 * Scan text. Returns ['level' => 'clean'|'suspicious'|'severe', 'word' => matched term|''].
 */
function moderate_text(string $text): array
{
    if (trim($text) === '') {
        return ['level' => 'clean', 'word' => ''];
    }
    $terms = mod_terms();
    $variants = mod_variants($text);
    $hit = mod_find($variants, $terms['severe']);
    if ($hit !== null) {
        return ['level' => 'severe', 'word' => $hit];
    }
    $hit = mod_find($variants, $terms['suspicious']);
    if ($hit !== null) {
        return ['level' => 'suspicious', 'word' => $hit];
    }
    return ['level' => 'clean', 'word' => ''];
}

function ban_user(int $uid): void
{
    db()->prepare("UPDATE users SET status = 'banned', remember_token = NULL WHERE id = ?")->execute([$uid]);
}

/**
 * Moderate a caption/comment submitted by $uid.
 * SEVERE: bans the user, logs them out and shows the ban page (never returns).
 * Otherwise returns 'clean' or 'suspicious' and sets $word to the matched term.
 */
function moderate_or_ban(string $text, int $uid, ?string &$word = null): string
{
    $r = moderate_text($text);
    $word = $r['word'];
    if ($r['level'] === 'severe') {
        ban_user($uid);
        logout_user($uid);
        error_log('MobiFeed moderation: user #' . $uid . ' auto-banned (matched "' . $r['word'] . '")');
        banned_page();   // exits
    }
    return $r['level'];
}

/** Put a post into the admin review queue. $reporterId NULL = raised by the system. */
function flag_post(int $postId, ?int $reporterId, string $reason): void
{
    db()->prepare('INSERT INTO reports (reporter_id, post_id, reason) VALUES (?, ?, ?)')
        ->execute([$reporterId, $postId, mb_substr($reason, 0, 255, 'UTF-8')]);
}
