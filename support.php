<?php
// Support chat for wallet users (JSON). Tickets are answered in the admin, tab «Поддержка».
require __DIR__ . '/inc/core.php';
require_once __DIR__ . '/inc/addons.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function sout($d, int $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
$u = current_user();
if (!$u) sout(['error' => 'Please log in again.'], 401);
if (!addons_db()) sout(['error' => 'Support is unavailable right now.'], 500);
$uid = (int)$u['id'];
$a = (string)($_GET['a'] ?? '');

function ticket_of(int $uid, int $id) {
    $t = q('SELECT * FROM support_tickets WHERE id = ? AND user_id = ?', [$id, $uid])->fetch();
    if (!$t) sout(['error' => 'Ticket not found.'], 404);
    return $t;
}
function msgs(int $tid, int $after = 0): array {
    return array_map(fn($m) => ['id' => (int)$m['id'], 'me' => $m['sender'] === 'user', 'body' => $m['body'], 'at' => fmt_date($m['created_at'])],
        q('SELECT * FROM support_messages WHERE ticket_id = ? AND id > ? ORDER BY id', [$tid, $after])->fetchAll());
}
function clean_body($s): string {
    $s = trim(str_replace("\r", '', (string)$s));
    return mb_substr(preg_replace("~\n{3,}~", "\n\n", $s), 0, 2000);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = json_decode((string)file_get_contents('php://input'), true) ?: $_POST;
    if (!hash_equals(csrf(), (string)($_SERVER['HTTP_X_CSRF'] ?? $in['csrf'] ?? ''))) sout(['error' => 'Session expired — reload the page.'], 403);

    if ($a === 'new') {
        $subj = mb_substr(trim((string)($in['subject'] ?? '')), 0, 140);
        $body = clean_body($in['body'] ?? '');
        if ($body === '') sout(['error' => 'Write a message.'], 422);
        if ($subj === '') $subj = mb_strimwidth(preg_replace('~\s+~', ' ', $body), 0, 60, '…');
        $recent = (int)q('SELECT COUNT(*) FROM support_tickets WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)', [$uid])->fetchColumn();
        if ($recent >= 5) sout(['error' => 'Too many new requests. Please continue in an existing one.'], 429);
        q('INSERT INTO support_tickets (user_id, subject, admin_unread) VALUES (?,?,1)', [$uid, $subj]);
        $tid = (int)db()->lastInsertId();
        q('INSERT INTO support_messages (ticket_id, sender, body) VALUES (?,?,?)', [$tid, 'user', $body]);
        sout(['ok' => true, 'id' => $tid]);
    }
    if ($a === 'send') {
        $t = ticket_of($uid, (int)($in['id'] ?? 0));
        $body = clean_body($in['body'] ?? '');
        if ($body === '') sout(['error' => 'Write a message.'], 422);
        $n = (int)q('SELECT COUNT(*) FROM support_messages WHERE ticket_id = ? AND sender = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)', [$t['id'], 'user'])->fetchColumn();
        if ($n >= 8) sout(['error' => 'You’re sending messages too fast.'], 429);
        q('INSERT INTO support_messages (ticket_id, sender, body) VALUES (?,?,?)', [$t['id'], 'user', $body]);
        q("UPDATE support_tickets SET status = 'open', admin_unread = admin_unread + 1, updated_at = NOW() WHERE id = ?", [$t['id']]);
        sout(['ok' => true, 'messages' => msgs((int)$t['id'], (int)($in['after'] ?? 0))]);
    }
    sout(['error' => 'Unknown action.'], 400);
}

if ($a === 'list') {
    $rows = q('SELECT t.*, (SELECT body FROM support_messages m WHERE m.ticket_id = t.id ORDER BY m.id DESC LIMIT 1) last
               FROM support_tickets t WHERE t.user_id = ? ORDER BY t.updated_at DESC LIMIT 50', [$uid])->fetchAll();
    sout(['tickets' => array_map(fn($t) => ['id' => (int)$t['id'], 'subject' => $t['subject'], 'status' => $t['status'], 'unread' => (int)$t['user_unread'],
        'last' => mb_strimwidth(preg_replace('~\s+~', ' ', (string)$t['last']), 0, 80, '…'), 'at' => fmt_date($t['updated_at'])], $rows)]);
}
if ($a === 'get') {
    $t = ticket_of($uid, (int)($_GET['id'] ?? 0));
    if ($t['user_unread']) q('UPDATE support_tickets SET user_unread = 0 WHERE id = ?', [$t['id']]);
    sout(['ticket' => ['id' => (int)$t['id'], 'subject' => $t['subject'], 'status' => $t['status']], 'messages' => msgs((int)$t['id'], (int)($_GET['after'] ?? 0))]);
}
if ($a === 'unread') sout(['unread' => support_unread($uid)]);
sout(['error' => 'Unknown action.'], 400);
