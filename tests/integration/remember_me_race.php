<?php
/**
 * Integration regression test — concurrent remember-me recovery.
 *
 * NOT run by CI: it needs a live web server and a writable database.
 * Run manually against a development install:
 *
 *   php tests/integration/remember_me_race.php https://servbay.host/cybokron-exchange-rate-and-portfolio-tracking
 *
 * Regression it guards
 * --------------------
 * Auth::loginFromRememberToken() used to DELETE the token row and immediately
 * issue a replacement. Requests that share a cookie arrive concurrently — a page
 * navigation next to the 5-minute rate poll, several tabs, a PWA precache — so
 * every sibling of the winner found no row, failed to authenticate and rendered
 * a logged-out page (portfolio.php redirects such requests to login.php).
 *
 * The fix keeps the outgoing token valid for Auth::ROTATION_GRACE seconds and
 * lets exactly one request mint the replacement.
 *
 * Expected: 5/5 concurrent requests authenticate, 1 replacement cookie minted.
 */

const RACE_USER = 'race_probe';
const RACE_PASS = 'RaceProbe123!';
const RACE_N    = 5;

$base = rtrim($argv[1] ?? '', '/');
if ($base === '') {
    fwrite(STDERR, "usage: php tests/integration/remember_me_race.php <base-url>\n");
    exit(2);
}

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/Database.php';

$failures = [];

function line(string $s = ''): void { echo $s . "\n"; }
function hdr(string $s): void { line(); line("=== $s ==="); }
function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    line(sprintf('  [%s] %s%s', $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? " — $detail" : ''));
    if (!$ok) { $failures[] = $label; }
}

function raceCurlOpts(string $cookie = ''): array
{
    $o = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_SSL_VERIFYPEER => false, // dev installs use a local CA
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 25,
    ];
    if ($cookie !== '') { $o[CURLOPT_COOKIE] = $cookie; }
    return $o;
}

function raceReq(string $url, array $cookies = [], ?array $post = null): array
{
    $pairs = [];
    foreach ($cookies as $k => $v) { $pairs[] = "$k=$v"; }
    $ch = curl_init($url);
    $o = raceCurlOpts(implode('; ', $pairs));
    if ($post !== null) {
        $o[CURLOPT_POST] = true;
        $o[CURLOPT_POSTFIELDS] = http_build_query($post);
    }
    curl_setopt_array($ch, $o);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $hsize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    if ($raw === false) { return ['headers' => '', 'body' => '', 'error' => $err]; }
    return ['headers' => substr($raw, 0, $hsize), 'body' => substr($raw, $hsize), 'error' => ''];
}

function raceSetCookies(string $headers): array
{
    $out = [];
    if (preg_match_all('/^Set-Cookie:\s*([^=]+)=([^;]*)/mi', $headers, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) { $out[trim($x[1])] = trim($x[2]); }
    }
    return $out;
}

function raceLoggedIn(string $body): bool { return str_contains($body, 'logout.php'); }

/**
 * Remove the temporary user and its tokens. Registered as a shutdown handler so
 * an early abort never leaves an admin-role test account behind.
 */
function raceCleanup(): void
{
    global $raceUid;
    if (!$raceUid) { return; }
    Database::execute('DELETE FROM remember_tokens WHERE user_id = ?', [$raceUid]);
    Database::execute('DELETE FROM users WHERE id = ? AND username = ?', [$raceUid, RACE_USER]);
    $raceUid = null;
    line('(temporary test user removed)');
}

/** Log in with remember=1 and return the fresh remember cookie value. */
function raceFreshCookie(string $base): string
{
    $g = raceReq($base . '/login.php');
    if ($g['error'] !== '') {
        fwrite(STDERR, 'cURL error: ' . $g['error'] . "\n");
        exit(1);
    }
    $jar = raceSetCookies($g['headers']);
    preg_match('/name="csrf_token" value="([^"]+)"/', $g['body'], $m);
    $p = raceReq($base . '/login.php', $jar, [
        'csrf_token' => $m[1] ?? '',
        'username'   => RACE_USER,
        'password'   => RACE_PASS,
        'remember'   => '1',
    ]);
    $cookie = raceSetCookies($p['headers'])['cybokron_remember'] ?? '';
    if ($cookie === '') {
        fwrite(STDERR, "login did not set a remember cookie — the login rate limit is\n"
            . "probably tripped (5 attempts / 5 min per IP). Wait and re-run.\n");
        exit(1);
    }
    return $cookie;
}

// ── setup ──────────────────────────────────────────────────────────────────
hdr('setup');
$hash = password_hash(RACE_PASS, PASSWORD_BCRYPT);
$existing = Database::queryOne('SELECT id FROM users WHERE username = ?', [RACE_USER]);
if ($existing) {
    Database::execute('UPDATE users SET password_hash = ?, is_active = 1 WHERE id = ?', [$hash, $existing['id']]);
    $uid = (int) $existing['id'];
} else {
    $uid = Database::insert('users', [
        'username' => RACE_USER, 'password_hash' => $hash, 'role' => 'admin', 'is_active' => 1,
    ]);
}
Database::execute('DELETE FROM remember_tokens WHERE user_id = ?', [$uid]);
$raceUid = $uid;
register_shutdown_function('raceCleanup');
line("temporary test user id = $uid");

// ── baseline ───────────────────────────────────────────────────────────────
hdr('baseline — one request, expired session');
$one = raceReq($base . '/index.php', ['cybokron_remember' => raceFreshCookie($base)]);
check('sequential recovery authenticates', raceLoggedIn($one['body']));
check('sequential recovery rotates the cookie', isset(raceSetCookies($one['headers'])['cybokron_remember']));

// ── the race ───────────────────────────────────────────────────────────────
hdr('race — ' . RACE_N . ' concurrent requests sharing one remember cookie');
Database::execute('DELETE FROM remember_tokens WHERE user_id = ?', [$uid]);
$shared = raceFreshCookie($base);

$mh = curl_multi_init();
$handles = [];
for ($i = 0; $i < RACE_N; $i++) {
    $ch = curl_init($base . '/index.php?race=' . $i);
    curl_setopt_array($ch, raceCurlOpts('cybokron_remember=' . $shared));
    curl_multi_add_handle($mh, $ch);
    $handles[$i] = $ch;
}
$active = null;
do {
    $status = curl_multi_exec($mh, $active);
    if ($active) { curl_multi_select($mh, 1.0); }
} while ($active && $status === CURLM_OK);

$minted = [];
$loggedIn = 0;
foreach ($handles as $i => $ch) {
    $raw = (string) curl_multi_getcontent($ch);
    $hsize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $tok = raceSetCookies(substr($raw, 0, $hsize))['cybokron_remember'] ?? null;
    $in = raceLoggedIn(substr($raw, $hsize));
    if ($in) { $loggedIn++; }
    if ($tok !== null) { $minted[$i] = $tok; }
    printf("  req %d: logged_in=%-3s new_cookie=%s\n", $i, $in ? 'YES' : 'NO', $tok ? 'yes' : '(none)');
    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);
}
curl_multi_close($mh);

// ── assertions ─────────────────────────────────────────────────────────────
hdr('checks');
check('all concurrent requests authenticate', $loggedIn === RACE_N, "$loggedIn/" . RACE_N);
check('exactly one replacement cookie minted', count($minted) === 1, count($minted) . ' minted');

$kept = $minted ? end($minted) : $shared;
check(
    'the cookie the browser keeps still authenticates',
    raceLoggedIn(raceReq($base . '/index.php', ['cybokron_remember' => $kept])['body'])
);

// The superseded token must sit inside its grace window and never be re-extended,
// otherwise sustained concurrency would keep a stale token alive indefinitely.
// setcookie() urlencodes the value, so the ':' separator arrives as %3A.
$sel = explode(':', urldecode($shared))[0] ?? '';
$graced = Database::queryOne(
    'SELECT TIMESTAMPDIFF(SECOND, NOW(), expires_at) AS secs_left FROM remember_tokens WHERE selector = ?',
    [$sel]
);
if ($graced === null) {
    check('superseded token not re-extended', true, 'already reaped');
} else {
    $left = (int) $graced['secs_left'];
    check('superseded token not re-extended', $left <= 60, "expires in {$left}s (must stay <= 60)");
}

// ── teardown ───────────────────────────────────────────────────────────────
hdr('summary');
if ($failures) {
    line('FAILED: ' . count($failures));
    foreach ($failures as $f) { line('  - ' . $f); }
} else {
    line('ALL CHECKS PASSED');
}

// raceCleanup() runs via register_shutdown_function().
exit($failures ? 1 : 0);
