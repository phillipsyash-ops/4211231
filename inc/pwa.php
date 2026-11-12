<?php
// Loyal as a phone app (PWA): "Add to Home Screen" on iPhone and Android.
// Connected by one line at the very end of inc/core.php. Everything else lives in new files:
//   inc/pwa.php, manifest.php, sw.js, offline.html, assets/app.css, assets/app-shell.js, assets/app/*
// What this file does:
//   1) keeps the app logged in on the phone ("remember this device"), only inside the installed app;
//   2) adds the app tags, mobile styles and the bottom tab bar to every page of the site (not to the admin).

if (defined('LOYAL_PWA')) return;
define('LOYAL_PWA', 1);
if (is_file(__DIR__ . '/addons.php')) require_once __DIR__ . '/addons.php';   // swap block + Support chat
const PWA_COOKIE = 'lrm';            // remember-me token
const PWA_FLAG   = 'lapp';           // set by the installed app itself
const PWA_DAYS   = 90;

function pwa_script(): string { return basename($_SERVER['SCRIPT_NAME'] ?? ''); }
function pwa_in_admin(): bool { return (bool)preg_match('~/(admin|download)/~', $_SERVER['SCRIPT_NAME'] ?? ''); }
function pwa_https(): bool { return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'); }
function pwa_cookie(string $v, int $exp) {
    setcookie(PWA_COOKIE, $v, ['expires' => $exp, 'path' => '/', 'secure' => pwa_https(), 'httponly' => true, 'samesite' => 'Lax']);
}
function pwa_table(): bool {
    $f = __DIR__ . '/../data/pwa_v';
    if (is_file($f)) return true;
    try {
        q('CREATE TABLE IF NOT EXISTS remember_tokens (
            id INTEGER PRIMARY KEY AUTO_INCREMENT,
            user_id INTEGER NOT NULL,
            selector CHAR(24) NOT NULL UNIQUE,
            vhash CHAR(64) NOT NULL,
            sv INTEGER NOT NULL DEFAULT 1,
            ua VARCHAR(200) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_used DATETIME NULL,
            expires_at DATETIME NOT NULL,
            KEY rt_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        @file_put_contents($f, '1');
        return true;
    } catch (Throwable $e) { return false; }
}
function pwa_forget() {
    $c = (string)($_COOKIE[PWA_COOKIE] ?? '');
    if ($c !== '' && str_contains($c, ':')) { try { q('DELETE FROM remember_tokens WHERE selector = ?', [explode(':', $c)[0]]); } catch (Throwable $e) {} }
    if ($c !== '') pwa_cookie('', time() - 3600);
}
function pwa_issue(int $uid, int $sv) {
    if (!pwa_table()) return;
    $sel = bin2hex(random_bytes(12)); $val = bin2hex(random_bytes(24));
    q('INSERT INTO remember_tokens (user_id, selector, vhash, sv, ua, expires_at) VALUES (?,?,?,?,?, DATE_ADD(NOW(), INTERVAL ' . PWA_DAYS . ' DAY))',
      [$uid, $sel, hash('sha256', $val), $sv, mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200)]);
    pwa_cookie($sel . ':' . $val, time() + PWA_DAYS * 86400);
}

// ---- 1) remember this device (only in the installed app) ----
(function () {
    if (PHP_SAPI === 'cli' || pwa_in_admin()) return;
    $script = pwa_script();
    if ($script === 'logout.php') { pwa_forget(); return; }
    $c = (string)($_COOKIE[PWA_COOKIE] ?? '');
    try {
        if (empty($_SESSION['uid']) && $c !== '' && str_contains($c, ':') && pwa_table()) {
            [$sel, $val] = explode(':', $c, 2);
            $t = q('SELECT t.*, u.session_ver, u.blocked FROM remember_tokens t JOIN users u ON u.id = t.user_id WHERE t.selector = ? AND t.expires_at > NOW()', [$sel])->fetch();
            if ($t && hash_equals($t['vhash'], hash('sha256', $val)) && !$t['blocked'] && (int)$t['sv'] === (int)$t['session_ver']) {
                session_regenerate_id(true);
                $_SESSION['uid'] = (int)$t['user_id'];
                $_SESSION['sv'] = (int)$t['session_ver'];
                q('UPDATE remember_tokens SET last_used = NOW(), expires_at = DATE_ADD(NOW(), INTERVAL ' . PWA_DAYS . ' DAY) WHERE id = ?', [$t['id']]);
                pwa_cookie($c, time() + PWA_DAYS * 86400);
            } else {
                if ($t) q('DELETE FROM remember_tokens WHERE id = ?', [$t['id']]);   // logged out everywhere / password changed
                pwa_cookie('', time() - 3600);
            }
        }
        elseif (!empty($_SESSION['uid']) && !empty($_COOKIE[PWA_FLAG]) && pwa_table()) {
            $sv = (int)($_SESSION['sv'] ?? 1);
            if ($c === '') pwa_issue((int)$_SESSION['uid'], $sv);
            elseif (str_contains($c, ':')) {   // the session is current (e.g. password just changed here) — keep the token in step
                q('UPDATE remember_tokens SET sv = ? WHERE selector = ? AND user_id = ?', [$sv, explode(':', $c)[0], (int)$_SESSION['uid']]);
            }
        }
    } catch (Throwable $e) { /* never break the site because of the app layer */ }
})();

// ---- 1b) prices never hold up a page ----
// When the saved prices are a little old, the page is built with them right away and the fresh prices are
// fetched from the exchanges after the page has been sent (the visitor doesn't wait for Binance/CoinGecko).
(function () {
    if (PHP_SAPI === 'cli' || !defined('PRICE_TTL')) return;
    if (!function_exists('litespeed_finish_request') && !function_exists('fastcgi_finish_request')) return;   // can't refresh after the response here
    $f = __DIR__ . '/../data/prices.json';
    $raw = @file_get_contents($f); if (!$raw) return;
    $c = json_decode($raw, true); if (!is_array($c) || empty($c['p'])) return;
    $age = time() - (int)($c['t'] ?? 0);
    if ($age < PRICE_TTL || $age > 600) return;   // fresh — nothing to do; very old — let the page fetch normally
    $c['t'] = time();                             // this request (and others for a few seconds) use the saved prices
    @file_put_contents($f, json_encode($c), LOCK_EX);
    register_shutdown_function(function () use ($f, $c) {
        while (ob_get_level() > 0) @ob_end_flush();
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        if (function_exists('litespeed_finish_request')) litespeed_finish_request(); else fastcgi_finish_request();
        $c['t'] = 0; @file_put_contents($f, json_encode($c), LOCK_EX);
        try { prices(); } catch (Throwable $e) {}
    });
})();

// ---- 2) app tags, styles and the tab bar on every page ----
if (PHP_SAPI !== 'cli' && !pwa_in_admin()) {
    // Support widget data — read now, while the database is open (the page is assembled at the very end)
    if (!empty($_SESSION['uid']) && defined('LOYAL_ADDONS') && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
        && !in_array(pwa_script(), ['api.php', 'ext_api.php', 'support.php', 'manifest.php', 'logout.php'], true)) {
        $uid = (int)$_SESSION['uid'];
        $GLOBALS['__pwa_sup'] = ['csrf' => csrf(), 'swapBlocked' => pwa_script() === 'swap.php' && swap_blocked_uid($uid), 'supportUnread' => support_unread($uid)];
    }
    ob_start(function (string $html): string {
        foreach (headers_list() as $hd) if (stripos($hd, 'content-type:') === 0 && stripos($hd, 'text/html') === false) return $html;
        if (stripos($html, '</head>') === false || stripos($html, '</body>') === false) return $html;
        $name = defined('SITE_NAME') ? SITE_NAME : 'Loyal';
        $html = preg_replace('~<meta name="viewport" content="[^"]*">~', '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">', $html, 1);
        $html = preg_replace('~<link rel="apple-touch-icon"[^>]*>~', '', $html);
        $head = '<link rel="manifest" href="manifest.php">'
              . '<meta name="mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-capable" content="yes">'
              . '<meta name="apple-mobile-web-app-status-bar-style" content="default">'
              . '<meta name="apple-mobile-web-app-title" content="' . htmlspecialchars($name) . '">'
              . '<link rel="apple-touch-icon" href="' . asset('assets/app/icon-180.png') . '">'
              . '<script>(function(){var a=matchMedia("(display-mode: standalone)").matches||navigator.standalone===true;if(a){document.documentElement.classList.add("is-app");try{if(localStorage.getItem("loyal-pin")&&!sessionStorage.getItem("loyal-unlocked"))document.documentElement.classList.add("pin-locked");}catch(e){}setTimeout(function(){if(!document.querySelector(".pin-lock"))document.documentElement.classList.remove("pin-locked");},6000);document.cookie="' . PWA_FLAG . '=1; path=/; max-age=31536000; SameSite=Lax";}})();</script>'
              . '<link rel="stylesheet" href="' . asset('assets/app.css') . '">';
        $user = !empty($_SESSION['uid']);
        $cfg = ['name' => $name, 'user' => $user, 'page' => pwa_script()];
        $sup = '';
        if ($user && !empty($GLOBALS['__pwa_sup'])) {
            $cfg += $GLOBALS['__pwa_sup'];
            $head .= '<link rel="stylesheet" href="' . asset('assets/support.css') . '">';
            $sup = '<script src="' . asset('assets/support.js') . '" defer></script>';
            if (pwa_script() === 'wallet.php' && is_file(__DIR__ . '/../assets/mywallets.js')) {   // "My crypto": user's own wallets, read-only
                $head .= '<link rel="stylesheet" href="' . asset('assets/mywallets.css') . '">';
                $sup .= '<script src="' . asset('assets/mywallets.js') . '" defer></script>';
            }
        }
        $foot = '<script>window.LOYAL_APP=' . json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';</script>'
              . '<script src="' . asset('assets/app-shell.js') . '" defer></script>' . $sup;
        $i = strripos($html, '</head>'); $html = substr_replace($html, $head . '</head>', $i, 7);
        $i = strripos($html, '</body>'); $html = substr_replace($html, $foot . '</body>', $i, 7);
        return $html;
    });
}
