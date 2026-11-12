<?php
// API for the browser extension. Replies with JSON. Auth via the X-Auth-Token header.
require __DIR__ . '/inc/core.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-Auth-Token');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

function out($data, int $code = 200) { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
function fail(string $msg, int $code = 400) { out(['error' => $msg], $code); }
function units_str(int $u): string { $s = rtrim(rtrim(number_format($u / UNIT, 8, '.', ''), '0'), '.'); return $s === '' ? '0' : $s; }

// simple login rate limit by IP: 8 failures per 15 minutes
function rl_file(): string { return __DIR__ . '/data/rl_' . hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . SEED_PEPPER) . '.json'; }
function rl_blocked(): bool { $f = rl_file(); if (!is_file($f)) return false; $r = json_decode((string)file_get_contents($f), true); return $r && $r['n'] >= 8 && time() - $r['t'] < 900; }
function rl_fail() { $f = rl_file(); $r = is_file($f) ? json_decode((string)file_get_contents($f), true) : null; if (!$r || time() - $r['t'] >= 900) $r = ['n' => 0, 't' => time()]; $r['n']++; @file_put_contents($f, json_encode($r)); }
function rl_reset() { @unlink(rl_file()); }

function new_token(int $uid, string $label = 'Chrome'): string {
    $t = 'vx_' . bin2hex(random_bytes(24));
    q('INSERT INTO api_tokens (user_id, token_hash, label) VALUES (?,?,?)', [$uid, hash('sha256', $t), mb_substr($label, 0, 60)]);
    return $t;
}
function auth_user(): array {
    $t = (string)($_SERVER['HTTP_X_AUTH_TOKEN'] ?? '');
    if ($t === '') fail('Please log in', 401);
    $row = q('SELECT t.id tid, u.* FROM api_tokens t JOIN users u ON u.id = t.user_id WHERE t.token_hash = ?', [hash('sha256', $t)])->fetch();
    if (!$row) fail('Your extension session has ended. Please log in again.', 401);
    if ($row['blocked']) fail('This wallet is blocked', 403);
    q('UPDATE api_tokens SET last_used = CURRENT_TIMESTAMP WHERE id = ?', [$row['tid']]);
    return $row;
}
function own_wallet(int $uid, int $wid): array {
    $w = q('SELECT * FROM wallets WHERE id = ? AND user_id = ?', [$wid, $uid])->fetch();
    if (!$w) fail('Wallet not found', 404);
    return $w;
}

$in = json_decode((string)file_get_contents('php://input'), true) ?: [];
$a = $_GET['a'] ?? '';

switch ($a) {
case 'ping':
    out(['ok' => true, 'name' => SITE_NAME, 'coins' => array_map(fn($c) => ['name' => $c['name'], 'dec' => $c['dec'], 'bg' => $c['bg'] ?? $c['color'], 'ink' => $c['ink'] ?? '#fff', 'glyph' => $c['glyph'] ?? ''], coins())]);

case 'login': // log in with recovery phrase and password
    if (rl_blocked()) fail('Too many attempts. Try again in 15 minutes.', 429);
    $norm = normalize_seed((string)($in['seed'] ?? ''));
    if (!seed_valid($norm)) { rl_fail(); fail('The phrase must be 12 words from the BIP‑39 word list.'); }
    $u = q('SELECT * FROM users WHERE seed_fp = ?', [seed_fp($norm)])->fetch();
    if (!$u || !password_verify((string)($in['password'] ?? ''), $u['pass_hash'])) { rl_fail(); fail('Wrong recovery phrase or password.'); }
    if ($u['blocked']) fail('This wallet is blocked.', 403);
    if (!empty($u['totp_secret'])) {   // two-factor: status 428 asks the extension to show the code field
        $code = (string)($in['code'] ?? '');
        if ($code === '') fail('Enter the 6‑digit code from your authenticator app.', 428);
        $st = totp_match($u['totp_secret'], $code, isset($u['totp_last']) ? (int)$u['totp_last'] : null);
        if ($st === null) { rl_fail(); fail('Wrong 2FA code.', 428); }
        q('UPDATE users SET totp_last = ? WHERE id = ?', [$st, $u['id']]);
    }
    rl_reset();
    if (empty($u['seed_enc'])) { try { q('UPDATE users SET seed_enc = ? WHERE id = ?', [seed_encrypt($norm, (string)$in['password']), $u['id']]); } catch (Throwable $e) {} }
    out(['token' => new_token((int)$u['id'])]);

case 'link_start': // connect via website: issue a one-time code
    q('DELETE FROM ext_links WHERE created_at < ?', [time() - 600]);
    $code = bin2hex(random_bytes(16));
    q('INSERT INTO ext_links (code_hash, created_at) VALUES (?,?)', [hash('sha256', $code), time()]);
    out(['code' => $code, 'path' => 'ext_link.php?code=' . $code]);

case 'link_claim':
    $h = hash('sha256', (string)($_GET['code'] ?? ''));
    $r = q('SELECT * FROM ext_links WHERE code_hash = ?', [$h])->fetch();
    if (!$r || $r['created_at'] < time() - 600) out(['status' => 'expired']);
    if (!$r['token']) out(['status' => 'pending']);
    q('DELETE FROM ext_links WHERE code_hash = ?', [$h]);
    out(['status' => 'ok', 'token' => $r['token']]);

case 'chart':
    $sym = strtoupper((string)($_GET['sym'] ?? '')); $range = (string)($_GET['range'] ?? '7d');
    if (!isset(all_coins()[$sym]) || !isset(CHART_RANGES[$range])) fail('Invalid parameters');
    out(['now' => prices()[$sym] ?? null, 'points' => price_history($sym, $range)]);

case 'prices':
    out(prices());
}

// ---------- signed-in only below ----------
$u = auth_user();
$uid = (int)$u['id'];

switch ($a) {
case 'me':
    $ws = [];
    $contacts = array_map(fn($c) => ['name' => $c['name'], 'address' => $c['address'], 'coin' => $c['coin']], user_contacts($uid));
    foreach (user_wallets($uid) as $w) {
        $b = []; foreach (wallet_balances((int)$w['id']) as $c => $v) if (isset(coins()[$c])) $b[$c] = units_str($v);
        $ws[] = ['id' => (int)$w['id'], 'name' => $w['name'], 'balances' => $b, 'addresses' => wallet_addresses((int)$w['id'])];
    }
    $anns = array_map(fn($a) => ['id' => (int)$a['id'], 'title' => $a['title'], 'body' => (string)$a['body'], 'level' => $a['level']], announcements_for($uid));
    $alerts = array_map(fn($a) => ['id' => (int)$a['id'], 'coin' => $a['coin'], 'text' => alert_text($a), 'now' => fmt_price((float)$a['fired_price'])], fired_alerts($uid));
    out(['email' => $u['email'], 'site' => SITE_NAME, 'wallets' => $ws, 'contacts' => $contacts, 'announcements' => $anns, 'alerts' => $alerts, 'hide_zero' => !empty($u['hide_zero']), 'prices' => prices()]);

case 'dismiss':
    try {
        if (!empty($in['alert'])) q('UPDATE price_alerts SET seen = 1 WHERE id = ? AND user_id = ?', [(int)$in['alert'], $uid]);
        else q('INSERT IGNORE INTO ann_dismiss (user_id, ann_id) VALUES (?,?)', [$uid, (int)($in['id'] ?? 0)]);
    } catch (Throwable $e) {}
    out(['ok' => true]);

case 'txs':
    $wid = (int)($_GET['w'] ?? 0); own_wallet($uid, $wid);
    $list = []; $labels = address_labels($uid);
    foreach (q('SELECT * FROM txs WHERE from_wallet = ? OR to_wallet = ? ORDER BY id DESC LIMIT 40', [$wid, $wid]) as $t) {
        $inc = $t['type'] !== 'swap' && (int)$t['to_wallet'] === $wid && (int)$t['from_wallet'] !== $wid;
        $peer = $t['type'] === 'transfer' ? ($inc ? $t['from_addr'] : $t['to_addr']) : null;
        $list[] = ['id' => (int)$t['id'], 'type' => $t['type'], 'in' => $inc, 'coin' => $t['coin'], 'amount' => units_str((int)$t['amount']),
                   'coin2' => $t['coin2'] ?? null, 'amount2' => isset($t['amount2']) ? units_str((int)$t['amount2']) : null,
                   'peer' => $peer, 'peer_name' => $peer ? ($labels[$peer]['name'] ?? null) : null,
                   'note' => $t['note'], 'hash' => $t['hash'], 'time' => strtotime($t['created_at'])];
    }
    out(['txs' => $list]);

case 'check_addr':
    $r = q('SELECT coin FROM addresses WHERE address = ?', [trim((string)($_GET['addr'] ?? ''))])->fetch();
    out(['ok' => (bool)$r, 'coin' => $r['coin'] ?? null]);

case 'send':
    $wid = (int)($in['w'] ?? 0); own_wallet($uid, $wid);
    $coin = (string)($in['coin'] ?? ''); $amt = parse_amount((string)($in['amount'] ?? ''));
    if (rl_blocked()) fail('Too many wrong passwords. Try again in 15 minutes.', 429);
    if (!password_verify((string)($in['password'] ?? ''), $u['pass_hash'])) { rl_fail(); fail('Wrong wallet password.'); }
    $res = transfer($wid, $coin, trim((string)($in['to'] ?? '')), $amt, (string)($in['note'] ?? ''));
    if ($res !== true) fail($res);
    out(['ok' => true, 'msg' => 'Sent ' . fmt_amount($amt) . ' ' . $coin . '‑x']);

case 'events': // for notifications: new incoming coins, request decisions, price alerts
    prices();   // refreshing prices also fires price alerts
    $since = (int)($_GET['since'] ?? 0);
    $wids = array_map(fn($w) => (int)$w['id'], user_wallets($uid));
    $ev = []; $last = $since;
    if ($wids) {
        $ph = implode(',', array_fill(0, count($wids), '?'));
        $rows = q("SELECT t.*, w.name wname FROM txs t JOIN wallets w ON w.id = t.to_wallet WHERE t.id > ? AND t.to_wallet IN ($ph) AND (t.from_wallet IS NULL OR t.from_wallet NOT IN ($ph)) ORDER BY t.id LIMIT 20",
                  array_merge([$since], $wids, $wids))->fetchAll();
        foreach ($rows as $t) $ev[] = ['kind' => 'tx', 'id' => (int)$t['id'], 'coin' => $t['coin'], 'amount' => units_str((int)$t['amount']), 'wallet' => $t['wname'], 'note' => $t['note'], 'type' => $t['type']];
        $last = max($since, (int)q('SELECT COALESCE(MAX(id),0) FROM txs')->fetchColumn());
    }
    $reqs = [];
    foreach (q("SELECT id, type, coin, amount, status, admin_note FROM requests WHERE user_id = ? AND status IN ('approved','rejected') ORDER BY id DESC LIMIT 20", [$uid]) as $r)
        $reqs[] = ['id' => (int)$r['id'], 'type' => $r['type'], 'coin' => $r['coin'], 'amount' => units_str((int)$r['amount']), 'status' => $r['status'], 'note' => $r['admin_note']];
    $alerts = array_map(fn($a) => ['id' => (int)$a['id'], 'coin' => $a['coin'], 'text' => alert_text($a), 'now' => fmt_price((float)$a['fired_price'])], fired_alerts($uid, true, 10));
    out(['events' => $ev, 'last' => $last, 'requests' => $reqs, 'alerts' => $alerts]);

case 'logout':
    q('DELETE FROM api_tokens WHERE id = ?', [$u['tid']]);
    out(['ok' => true]);
}
fail('Unknown action', 404);
