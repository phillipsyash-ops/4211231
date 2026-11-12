<?php
require __DIR__ . '/inc/core.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (isset($_GET['prices'])) { echo json_encode(prices()); exit; }

// Price history for charts: api.php?chart=BTC&range=7d
if (isset($_GET['chart'])) {
    $sym = strtoupper((string)$_GET['chart']); $range = (string)($_GET['range'] ?? '7d');
    if (!isset(all_coins()[$sym]) || !isset(CHART_RANGES[$range])) { http_response_code(400); echo '[]'; exit; }
    echo json_encode(['sym' => $sym, 'range' => $range, 'now' => prices()[$sym] ?? null, 'points' => price_history($sym, $range)]);
    exit;
}

// Check a recipient address before sending (signed-in users only)
if (isset($_GET['addr'])) {
    if (!current_user()) { http_response_code(401); echo '{}'; exit; }
    $r = q('SELECT coin FROM addresses WHERE address = ?', [trim($_GET['addr'])])->fetch();
    echo json_encode(['ok' => (bool)$r, 'coin' => $r['coin'] ?? null]);
    exit;
}
// Hide an announcement for this user
if (isset($_GET['dismiss']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = current_user();
    if (!$u || !hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) { http_response_code(403); echo '{}'; exit; }
    $d = (string)$_GET['dismiss'];
    try {
        if ($d !== '' && $d[0] === 'a') q('UPDATE price_alerts SET seen = 1 WHERE id = ? AND user_id = ?', [(int)substr($d, 1), $u['id']]);   // price alert
        else q('INSERT IGNORE INTO ann_dismiss (user_id, ann_id) VALUES (?,?)', [$u['id'], (int)$d]);
    } catch (Throwable $e) {}
    echo '{"ok":true}'; exit;
}
http_response_code(404); echo '{}';
