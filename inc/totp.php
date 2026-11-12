<?php
// Two-factor authentication: standard TOTP codes (RFC 6238, 6 digits, 30 s) —
// works with Google Authenticator, Microsoft Authenticator, Authy, 1Password, etc.

function b32_encode(string $bin): string {
    $a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; $out = '';
    foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    foreach (str_split($bits, 5) as $chunk) $out .= $a[bindec(str_pad($chunk, 5, '0'))];
    return $out;
}
function b32_decode(string $s): string {
    $a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; $out = '';
    foreach (str_split(strtoupper(preg_replace('~[^A-Za-z2-7]~', '', $s))) as $c) $bits .= str_pad(decbin(strpos($a, $c)), 5, '0', STR_PAD_LEFT);
    foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr(bindec($byte));
    return $out;
}
function totp_new_secret(): string { return b32_encode(random_bytes(20)); }
function totp_at(string $secret, int $step): string {
    $h = hash_hmac('sha1', pack('J', $step), b32_decode($secret), true);
    $o = ord($h[19]) & 0xf;
    $n = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
    return str_pad((string)($n % 1000000), 6, '0', STR_PAD_LEFT);
}
// Returns the matched time step or null. ±1 step (30 s) for clock drift; steps <= $last are refused (no reuse).
function totp_match(string $secret, string $code, ?int $last = null): ?int {
    $code = preg_replace('~\D~', '', $code);
    if (strlen($code) !== 6) return null;
    $now = intdiv(time(), 30);
    foreach ([0, -1, 1] as $d) {
        $st = $now + $d;
        if ($last !== null && $st <= $last) continue;
        if (hash_equals(totp_at($secret, $st), $code)) return $st;
    }
    return null;
}
function totp_uri(string $secret, string $email): string {
    return 'otpauth://totp/' . rawurlencode(SITE_NAME . ':' . $email) . '?secret=' . $secret . '&issuer=' . rawurlencode(SITE_NAME) . '&digits=6&period=30';
}
// Check a user's code and remember it so it can't be used twice. Throttled like the password check.
function check_totp(array $u, string $code): ?string {
    if (empty($u['totp_secret'])) return null;
    $f = $_SESSION['totpf'] ?? ['n' => 0, 't' => 0];
    if ($f['n'] >= 5 && time() - $f['t'] < 300) return 'Too many wrong codes. Try again in 5 minutes.';
    $st = totp_match($u['totp_secret'], $code, isset($u['totp_last']) ? (int)$u['totp_last'] : null);
    if ($st !== null) { unset($_SESSION['totpf']); q('UPDATE users SET totp_last = ? WHERE id = ?', [$st, $u['id']]); return null; }
    $_SESSION['totpf'] = ['n' => ($f['n'] >= 5 ? 0 : $f['n']) + 1, 't' => time()];
    return trim($code) === '' ? 'Enter the 6‑digit code from your authenticator app.' : 'Wrong code. Check that the time on your phone is set automatically.';
}
