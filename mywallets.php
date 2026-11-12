<?php
// "My crypto": the user's own external wallets (read-only). JSON for assets/mywallets.js
require __DIR__ . '/inc/core.php';
require_once __DIR__ . '/inc/addons.php';
require_once __DIR__ . '/inc/chainbal.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
const MAX_LINKED = 6;

function mout($d, int $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
$u = current_user();
if (!$u) mout(['error' => 'Please log in again.'], 401);
if (!addons_db()) mout(['error' => 'Unavailable right now.'], 500);
$uid = (int)$u['id'];
$a = (string)($_GET['a'] ?? 'list');

// the icon a browser wallet gives about itself (EIP-6963) — small images only
function clean_icon($s): ?string {
    $s = (string)$s;
    if (strlen($s) > 40000 || !preg_match('~^data:image/(svg\+xml|png|webp|jpeg|gif);base64,[A-Za-z0-9+/=]+$~', $s)) return null;
    return $s;
}
function wallet_name($s): ?string {
    $s = trim(preg_replace('~[^\p{L}\p{N} ._-]~u', '', (string)$s));
    return $s === '' ? null : mb_substr($s, 0, 40);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = json_decode((string)file_get_contents('php://input'), true) ?: $_POST;
    if (!hash_equals(csrf(), (string)($_SERVER['HTTP_X_CSRF'] ?? $in['csrf'] ?? ''))) mout(['error' => 'Session expired — reload the page.'], 403);
    if ($a === 'add') {
        $addr = trim((string)($in['address'] ?? ''));
        $chain = chain_detect($addr);
        if (!$chain) mout(['error' => 'That doesn’t look like an Ethereum/BNB (0x…), Tron (T…) or Bitcoin address.'], 422);
        if ($chain === 'evm') $addr = strtolower($addr);
        $n = (int)q('SELECT COUNT(*) FROM linked_wallets WHERE user_id = ?', [$uid])->fetchColumn();
        if ($n >= MAX_LINKED) mout(['error' => 'You can link up to ' . MAX_LINKED . ' wallets. Remove one first.'], 422);
        q('INSERT INTO linked_wallets (user_id, chain, address, wallet, icon) VALUES (?,?,?,?,?)
           ON DUPLICATE KEY UPDATE wallet = COALESCE(VALUES(wallet), wallet), icon = COALESCE(VALUES(icon), icon)',
          [$uid, $chain, $addr, wallet_name($in['wallet'] ?? ''), clean_icon($in['icon'] ?? '')]);
        mout(['ok' => true]);
    }
    if ($a === 'remove') {
        q('DELETE FROM linked_wallets WHERE id = ? AND user_id = ?', [(int)($in['id'] ?? 0), $uid]);
        mout(['ok' => true]);
    }
    mout(['error' => 'Unknown action.'], 400);
}

if ($a === 'list') {
    $rows = q('SELECT * FROM linked_wallets WHERE user_id = ? ORDER BY id', [$uid])->fetchAll();
    $out = []; $total = 0;
    foreach ($rows as $r) {
        $b = chain_balances($r['chain'], $r['address']);
        $total += $b['total'];
        $out[] = ['id' => (int)$r['id'], 'chain' => $r['chain'], 'address' => $r['address'], 'wallet' => $r['wallet'], 'icon' => $r['icon'],
                  'ok' => $b['ok'], 'stale' => !empty($b['stale']), 'assets' => $b['assets'], 'total' => $b['total']];
    }
    mout(['wallets' => $out, 'total' => $total]);
}
mout(['error' => 'Unknown action.'], 400);
