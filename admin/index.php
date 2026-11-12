<?php
require __DIR__ . '/../inc/core.php';
if (is_file(__DIR__ . '/../inc/addons.php')) require_once __DIR__ . '/../inc/addons.php';   // блокировка свапа, поддержка

// ---- сессии администратора: несколько устройств одновременно, каждое помнится 30 дней ----
const ADM_COOKIE = 'ladm';
const ADM_DAYS = 30;
function adm_table(): bool {
    static $ok = null; if ($ok !== null) return $ok;
    try {
        q('CREATE TABLE IF NOT EXISTS admin_sessions (
            id INTEGER PRIMARY KEY AUTO_INCREMENT,
            selector CHAR(24) NOT NULL UNIQUE,
            vhash CHAR(64) NOT NULL,
            ua VARCHAR(200) NULL,
            ip VARCHAR(45) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_used DATETIME NULL,
            expires_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        return $ok = true;
    } catch (Throwable $e) { return $ok = false; }
}
function adm_cookie(string $v, int $exp) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    setcookie(ADM_COOKIE, $v, ['expires' => $exp, 'path' => rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
}
function adm_ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45); }
// новая сессия этого устройства
function adm_start() {
    session_regenerate_id(true);
    $_SESSION['admin'] = 1;
    if (!adm_table()) return;
    $sel = bin2hex(random_bytes(12)); $val = bin2hex(random_bytes(24));
    q('INSERT INTO admin_sessions (selector, vhash, ua, ip, last_used, expires_at) VALUES (?,?,?,?, NOW(), DATE_ADD(NOW(), INTERVAL ' . ADM_DAYS . ' DAY))',
      [$sel, hash('sha256', $val), mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200), adm_ip()]);
    $_SESSION['adm_sid'] = (int)db()->lastInsertId();
    adm_cookie($sel . ':' . $val, time() + ADM_DAYS * 86400);
}
function adm_end() {
    if (!empty($_SESSION['adm_sid'])) { try { q('DELETE FROM admin_sessions WHERE id = ?', [(int)$_SESSION['adm_sid']]); } catch (Throwable $e) {} }
    unset($_SESSION['admin'], $_SESSION['adm_sid']);
    adm_cookie('', time() - 3600);
}
// вход по запомненному устройству (если PHP-сессия истекла или из неё вышли на сайте)
if (empty($_SESSION['admin']) && !empty($_COOKIE[ADM_COOKIE]) && str_contains($_COOKIE[ADM_COOKIE], ':') && adm_table()) {
    [$sel, $val] = explode(':', (string)$_COOKIE[ADM_COOKIE], 2);
    $row = q('SELECT * FROM admin_sessions WHERE selector = ? AND expires_at > NOW()', [$sel])->fetch();
    if ($row && hash_equals($row['vhash'], hash('sha256', $val))) {
        session_regenerate_id(true);
        $_SESSION['admin'] = 1; $_SESSION['adm_sid'] = (int)$row['id'];
    } else adm_cookie('', time() - 3600);
}
// сессию завершили с другого устройства — выходим и здесь
if (!empty($_SESSION['admin']) && !empty($_SESSION['adm_sid']) && adm_table()) {
    $alive = q('SELECT id FROM admin_sessions WHERE id = ? AND expires_at > NOW()', [(int)$_SESSION['adm_sid']])->fetch();
    if (!$alive) { unset($_SESSION['admin'], $_SESSION['adm_sid']); adm_cookie('', time() - 3600); }
    elseif (empty($_SESSION['adm_touch']) || time() - $_SESSION['adm_touch'] > 60) {
        q('UPDATE admin_sessions SET last_used = NOW(), ip = ?, expires_at = DATE_ADD(NOW(), INTERVAL ' . ADM_DAYS . ' DAY) WHERE id = ?', [adm_ip(), (int)$_SESSION['adm_sid']]);
        $_SESSION['adm_touch'] = time();
    }
}
// вошли до этого обновления — заведём запись, чтобы устройство появилось в списке
if (!empty($_SESSION['admin']) && empty($_SESSION['adm_sid']) && adm_table()) adm_start();

// Скрытый вход: без ?k=КЛЮЧ страница выглядит как 404
if (empty($_SESSION['admin'])) {
    $keyOk = hash_equals(ADMIN_URL_KEY, (string)($_GET['k'] ?? $_POST['k'] ?? ''));
    if (!$keyOk) { http_response_code(404); exit('<!doctype html><title>404 Not Found</title><h1>Not Found</h1>'); }
    $err = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        if (($_POST['login'] ?? '') === ADMIN_LOGIN && password_verify($_POST['password'] ?? '', ADMIN_PASS_HASH)) {
            adm_start(); redirect('./');
        }
        sleep(2); $err = 'Неверный логин или пароль';
    }
    page_head('Админ');
    ?><main class="auth"><div class="panel rv in"><h1>Панель управления</h1><p class="sub">Вход для администратора</p>
    <?php if ($err): ?><div class="err"><?= h($err) ?></div><?php endif; ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="k" value="<?= h($_GET['k'] ?? $_POST['k']) ?>">
      <div class="field"><label>Логин</label><input type="text" name="login" required></div>
      <div class="field"><label>Пароль</label><input type="password" name="password" required></div>
      <button class="btn btn-p btn-block btn-lg">Войти</button></form></div></main>
    <?php page_foot(); exit;
}

if (isset($_GET['logout'])) { adm_end(); redirect('../'); }
// поддержка: новые сообщения в открытом тикете (для автообновления)
if (isset($_GET['sup_poll']) && function_exists('addons_db') && addons_db()) {
    header('Content-Type: application/json; charset=utf-8');
    $tid = (int)$_GET['sup_poll']; $after = (int)($_GET['after'] ?? 0);
    $ms = q('SELECT * FROM support_messages WHERE ticket_id = ? AND id > ? ORDER BY id', [$tid, $after])->fetchAll();
    if ($ms) q('UPDATE support_tickets SET admin_unread = 0 WHERE id = ?', [$tid]);
    $unread = (int)q('SELECT COUNT(*) FROM support_tickets WHERE admin_unread > 0')->fetchColumn();
    echo json_encode(['unread' => $unread, 'messages' => array_map(fn($m) => ['id' => (int)$m['id'], 'me' => $m['sender'] === 'admin', 'body' => $m['body'], 'at' => date('d.m H:i', strtotime($m['created_at']))], $ms)], JSON_UNESCAPED_UNICODE);
    exit;
}
if (isset($_GET['probe'])) {
    header('Content-Type: application/json; charset=utf-8');
    $pr = probe_price(strtoupper(trim($_GET['bn'] ?? '')) ?: null, strtolower(trim($_GET['cg'] ?? '')) ?: null, !empty($_GET['inv']));
    echo json_encode($pr ? ['ok' => true, 'usd' => round($pr['usd'], $pr['usd'] < 1 ? 6 : 2), 'ch' => $pr['ch'], 'src' => $pr['src']] : ['ok' => false]);
    exit;
}
$tab = $_GET['tab'] ?? 'req';

// ---- приложение для телефона (PWA): подключается одной строкой в конце inc/core.php ----
const PAPP_LINE = "\n// phone app (PWA) layer — inc/pwa.php\nif (is_file(__DIR__ . '/pwa.php')) require_once __DIR__ . '/pwa.php';\n";
function papp_ready() {
    $f = __DIR__ . '/../inc/core.php';
    foreach (['inc/pwa.php', 'manifest.php', 'sw.js', 'assets/app.css', 'assets/app-shell.js', 'assets/app/icon-192.png'] as $need)
        if (!is_file(__DIR__ . '/../' . $need)) return 'не хватает файла ' . $need . ' — загрузите все файлы приложения';
    $src = (string)@file_get_contents($f);
    if (str_contains($src, "/pwa.php'")) return true;
    $src = rtrim($src);
    if (str_ends_with($src, '?>')) $src = substr($src, 0, -2);
    @mkdir(__DIR__ . '/../data', 0755);
    if (!@copy($f, __DIR__ . '/../data/core.php.bak-' . date('Ymd-His'))) return 'manual';
    if (@file_put_contents($f, $src . "\n" . PAPP_LINE, LOCK_EX) === false) return 'manual';
    if (function_exists('opcache_invalidate')) @opcache_invalidate($f, true);
    return true;
}

// ---- персональные уведомления: колонка user_id у объявлений + фильтр в inc/core.php ----
// Фильтр включается правкой одной строки запроса в inc/core.php (остальной файл не трогается, копия — в data/).
const PANN_OLD = "WHERE a.active = 1 AND d.ann_id IS NULL ORDER BY a.id DESC LIMIT 3', [\$uid])";
const PANN_NEW = "WHERE a.active = 1 AND d.ann_id IS NULL AND (a.user_id IS NULL OR a.user_id = ?) ORDER BY a.id DESC LIMIT 3', [\$uid, \$uid])";
function pann_column(): bool {
    static $ok = null; if ($ok !== null) return $ok;
    try { q('SELECT user_id FROM announcements LIMIT 1'); return $ok = true; } catch (Throwable $e) {}
    try { q('ALTER TABLE announcements ADD COLUMN user_id INTEGER NULL'); q('CREATE INDEX ann_user ON announcements(user_id)'); return $ok = true; } catch (Throwable $e) { return $ok = false; }
}
// true — фильтр уже стоит; иначе пытаемся поставить; строка — что пошло не так
function pann_ready() {
    $f = __DIR__ . '/../inc/core.php';
    $src = (string)@file_get_contents($f);
    if (!pann_column()) return 'Не удалось добавить колонку user_id в таблицу announcements.';
    if (str_contains($src, 'a.user_id IS NULL OR a.user_id = ?')) return true;
    if (substr_count($src, PANN_OLD) !== 1) return 'manual';
    @mkdir(__DIR__ . '/../data', 0755);
    if (!@copy($f, __DIR__ . '/../data/core.php.bak-' . date('Ymd-His'))) return 'manual';
    if (@file_put_contents($f, str_replace(PANN_OLD, PANN_NEW, $src), LOCK_EX) === false) return 'manual';
    if (function_exists('opcache_invalidate')) @opcache_invalidate($f, true);
    return true;
}

// ---------- действия ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['act'] ?? '';

    // удалить ошибочное начисление/списание: баланс откатывается, запись исчезает из истории пользователя
    if ($act === 'swap_toggle' && function_exists('addons_db') && addons_db()) {
        $uidS = (int)($_POST['uid'] ?? 0);
        q('UPDATE users SET swap_blocked = 1 - swap_blocked WHERE id = ?', [$uidS]);
        $on = (int)q('SELECT swap_blocked FROM users WHERE id = ?', [$uidS])->fetchColumn();
        try { q('INSERT INTO user_notes (user_id, body, author) VALUES (?,?,?)', [$uidS, $on ? 'Свап запрещён.' : 'Свап снова разрешён.', ADMIN_LOGIN]); } catch (Throwable $e) {}
        flash($on ? 'Свап для пользователя запрещён — при попытке обмена он увидит ошибку и предложение написать в поддержку.' : 'Свап для пользователя снова разрешён.');
        redirect('./?tab=users' . (($_POST['q'] ?? '') !== '' ? '&q=' . urlencode($_POST['q']) : '') . '#u' . $uidS);
    }
    if (in_array($act, ['sup_reply', 'sup_close', 'sup_open'], true) && function_exists('addons_db') && addons_db()) {
        $tid = (int)($_POST['tid'] ?? 0);
        if ($act === 'sup_reply') {
            $body = mb_substr(trim(str_replace("\r", '', (string)($_POST['body'] ?? ''))), 0, 4000);
            if ($body === '') flash('Пустой ответ.', 'err');
            else {
                q('INSERT INTO support_messages (ticket_id, sender, body) VALUES (?,?,?)', [$tid, 'admin', $body]);
                q("UPDATE support_tickets SET status = ?, user_unread = user_unread + 1, admin_unread = 0, updated_at = NOW() WHERE id = ?", [!empty($_POST['close']) ? 'closed' : 'answered', $tid]);
            }
        }
        if ($act === 'sup_close') q("UPDATE support_tickets SET status = 'closed', admin_unread = 0 WHERE id = ?", [$tid]);
        if ($act === 'sup_open') q("UPDATE support_tickets SET status = 'open' WHERE id = ?", [$tid]);
        redirect('./?tab=support&t=' . $tid . (($_POST['f'] ?? '') ? '&f=' . urlencode($_POST['f']) : ''));
    }
    if ($act === 'adm_kill') {
        $sid = (int)($_POST['sid'] ?? 0);
        if ($sid === (int)($_SESSION['adm_sid'] ?? 0)) flash('Это текущее устройство — для него нажмите «Выйти».', 'err');
        else { q('DELETE FROM admin_sessions WHERE id = ?', [$sid]); flash('Сессия завершена — на том устройстве потребуется войти заново.'); }
        redirect('./?tab=sess');
    }
    if ($act === 'adm_kill_others') {
        q('DELETE FROM admin_sessions WHERE id <> ?', [(int)($_SESSION['adm_sid'] ?? 0)]);
        flash('Все остальные сессии завершены.');
        redirect('./?tab=sess');
    }
    if ($act === 'dev_off') {
        try { q('DELETE FROM remember_tokens WHERE id = ?', [(int)($_POST['did'] ?? 0)]); flash('Устройство отключено — в приложении на нём нужно будет войти заново.'); } catch (Throwable $e) {}
        redirect('./?tab=app');
    }
    if ($act === 'tx_undo') {
        $t = q("SELECT * FROM txs WHERE id = ? AND type IN ('credit','debit')", [(int)($_POST['tid'] ?? 0)])->fetch();
        if (!$t) flash('Удалять можно только начисления и списания из админки.', 'err');
        else {
            db()->beginTransaction();
            try {
                $amt = (int)$t['amount'];
                if ($t['type'] === 'credit') {   // забираем начисленное обратно
                    $d = q('UPDATE balances SET amount = amount - ? WHERE wallet_id = ? AND coin = ? AND amount >= ?', [$amt, $t['to_wallet'], $t['coin'], $amt]);
                    if ($d->rowCount() !== 1) throw new RuntimeException('funds');
                } else q('UPDATE balances SET amount = amount + ? WHERE wallet_id = ? AND coin = ?', [$amt, $t['from_wallet'], $t['coin']]);   // возвращаем списанное
                q('DELETE FROM txs WHERE id = ?', [$t['id']]);
                db()->commit();
                flash(($t['type'] === 'credit' ? 'Начисление' : 'Списание') . ' ' . fmt_amount($amt) . ' ' . $t['coin'] . '‑x удалено: баланс кошелька #' . ($t['to_wallet'] ?: $t['from_wallet']) . ' возвращён, в истории пользователя записи больше нет.');
            } catch (Throwable $e) { db()->rollBack(); flash($e->getMessage() === 'funds' ? 'Нельзя удалить начисление: пользователь уже потратил часть этих монет (на кошельке меньше, чем было начислено).' : 'Ошибка: ' . $e->getMessage(), 'err'); }
        }
        redirect('./?tab=tx');
    }
    if ($act === 'issue') {
        $type = ($_POST['type'] ?? '') === 'debit' ? 'debit' : 'credit';
        $coin = $_POST['coin'] ?? '';
        $amt = parse_amount($_POST['amount'] ?? '');
        $target = trim($_POST['target'] ?? '');
        $note = mb_substr(trim($_POST['note'] ?? ''), 0, 140) ?: null;

        // адрес любой монеты кошелька, или ID кошелька
        $wid = ctype_digit($target) ? (int)$target : (int)q('SELECT wallet_id FROM addresses WHERE address = ?', [$target])->fetchColumn();
        $exists = $wid && q('SELECT 1 FROM wallets WHERE id = ?', [$wid])->fetch();

        if (!isset(coins()[$coin]) || !$amt) flash('Укажите монету и сумму.', 'err');
        elseif (!$exists) flash('Кошелёк не найден.', 'err');
        else {
            db()->beginTransaction();
            try {
                if ($type === 'credit') q('UPDATE balances SET amount = amount + ? WHERE wallet_id = ? AND coin = ?', [$amt, $wid, $coin]);
                else {
                    $d = q('UPDATE balances SET amount = amount - ? WHERE wallet_id = ? AND coin = ? AND amount >= ?', [$amt, $wid, $coin, $amt]);
                    if ($d->rowCount() !== 1) throw new RuntimeException('funds');
                }
                $waddr = q('SELECT address FROM addresses WHERE wallet_id = ? AND coin = ?', [$wid, $coin])->fetchColumn();
                q('INSERT INTO txs (hash, type, coin, amount, from_wallet, to_wallet, from_addr, to_addr, note) VALUES (?,?,?,?,?,?,?,?,?)',
                  [tx_hash(), $type, $coin, $amt, $type === 'debit' ? $wid : null, $type === 'credit' ? $wid : null,
                   $type === 'debit' ? $waddr : null, $type === 'credit' ? $waddr : null, $note]);
                db()->commit();
                flash(($type === 'credit' ? 'Начислено ' : 'Списано ') . fmt_amount($amt) . ' ' . $coin . '‑x → кошелёк #' . $wid);
            } catch (Throwable $e) { db()->rollBack(); flash($e->getMessage() === 'funds' ? 'На кошельке недостаточно средств для списания.' : 'Ошибка: ' . $e->getMessage(), 'err'); }
        }
        redirect('./?tab=issue');
    }
    if ($act === 'req_decide') {
        $rid = (int)($_POST['rid'] ?? 0);
        $ok = ($_POST['decision'] ?? '') === 'approve';
        $anote = mb_substr(trim($_POST['admin_note'] ?? ''), 0, 200) ?: null;
        db()->beginTransaction();
        try {
            $r = q('SELECT * FROM requests WHERE id = ?', [$rid])->fetch();
            // смена статуса с проверкой — заявку нельзя провести дважды
            if (!$r || q('UPDATE requests SET status = ?, admin_note = ?, decided_at = CURRENT_TIMESTAMP WHERE id = ? AND status = ?',
                    [$ok ? 'approved' : 'rejected', $anote, $rid, 'pending'])->rowCount() !== 1) throw new RuntimeException('done');
            $waddr = q('SELECT address FROM addresses WHERE wallet_id = ? AND coin = ?', [$r['wallet_id'], $r['coin']])->fetchColumn();
            $txNote = ($r['type'] === 'deposit' ? 'Пополнение' : 'Вывод') . ' по заявке #' . $rid;
            if ($r['type'] === 'deposit' && $ok) {
                q('UPDATE balances SET amount = amount + ? WHERE wallet_id = ? AND coin = ?', [$r['amount'], $r['wallet_id'], $r['coin']]);
                q('INSERT INTO txs (hash, type, coin, amount, to_wallet, to_addr, note) VALUES (?,?,?,?,?,?,?)', [tx_hash(), 'credit', $r['coin'], $r['amount'], $r['wallet_id'], $waddr, $txNote]);
            } elseif ($r['type'] === 'withdraw' && $ok) {
                // монеты уже заморожены при создании заявки — фиксируем списание в истории
                q('INSERT INTO txs (hash, type, coin, amount, from_wallet, from_addr, note) VALUES (?,?,?,?,?,?,?)', [tx_hash(), 'debit', $r['coin'], $r['amount'], $r['wallet_id'], $waddr, $txNote]);
            } elseif ($r['type'] === 'withdraw' && !$ok) {
                q('UPDATE balances SET amount = amount + ? WHERE wallet_id = ? AND coin = ?', [$r['amount'], $r['wallet_id'], $r['coin']]);
            }
            db()->commit();
            flash('Заявка #' . $rid . ($ok ? ' одобрена.' : ' отклонена.'));
        } catch (Throwable $e) {
            db()->rollBack();
            flash($e->getMessage() === 'done' ? 'Заявка уже рассмотрена или отменена.' : 'Ошибка: ' . $e->getMessage(), 'err');
        }
        redirect('./?tab=req');
    }
    // ---- монеты ----
    if ($act === 'coin_save') {
        $sym = strtoupper(trim($_POST['sym'] ?? ''));
        $isNew = !empty($_POST['is_new']);
        $f = [
            'name' => mb_substr(trim($_POST['name'] ?? ''), 0, 40),
            'bn' => strtoupper(trim($_POST['bn'] ?? '')) ?: null,
            'inv' => empty($_POST['inv']) ? 0 : 1,
            'cg' => strtolower(trim($_POST['cg'] ?? '')) ?: null,
            'color' => preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['color'] ?? '') ? $_POST['color'] : '#888888',
            'glyph' => mb_substr(trim($_POST['glyph'] ?? ''), 0, 2) ?: null,
            'dec' => max(0, min(8, (int)($_POST['dec'] ?? 4))),
            'prefix' => preg_replace('/[^A-Za-z0-9]/', '', mb_substr(trim($_POST['prefix'] ?? ''), 0, 6)) ?: null,
            'sort' => (int)($_POST['sort'] ?? 100),
        ];
        if (!preg_match('/^[A-Z0-9]{2,10}$/', $sym)) flash('Тикер: 2–10 латинских букв или цифр, например DOGE.', 'err');
        elseif ($f['name'] === '') flash('Укажите название монеты.', 'err');
        elseif (!$f['bn'] && !$f['cg']) flash('Нужен источник курса: пара Binance или id CoinGecko.', 'err');
        elseif ($isNew && isset(all_coins()[$sym])) flash('Монета ' . $sym . ' уже есть — её можно изменить в списке.', 'err');
        elseif (!($pr = probe_price($f['bn'], $f['cg'], (bool)$f['inv'])) && empty($_POST['force'])) flash('Не удалось получить курс ни с Binance, ни с CoinGecko. Проверьте пару и id (или отметьте «сохранить без проверки»).', 'err');
        else {
            if ($isNew) {
                q('INSERT INTO coins (sym, name, bn, inv, cg, color, glyph, dec_places, prefix, sort, enabled) VALUES (?,?,?,?,?,?,?,?,?,?,1)',
                  [$sym, $f['name'], $f['bn'], $f['inv'], $f['cg'], $f['color'], $f['glyph'], $f['dec'], $f['prefix'], $f['sort']]);
                coins_reset(); ensure_coin_rows($sym);
                flash('Монета ' . $sym . '‑x добавлена, у всех кошельков появились адреса' . (!empty($pr) ? '. Курс сейчас ' . fmt_usd($pr['usd']) . ' (' . $pr['src'] . ')' : '') . '.');
            } else {
                q('UPDATE coins SET name=?, bn=?, inv=?, cg=?, color=?, glyph=?, dec_places=?, prefix=?, sort=? WHERE sym=?',
                  [$f['name'], $f['bn'], $f['inv'], $f['cg'], $f['color'], $f['glyph'], $f['dec'], $f['prefix'], $f['sort'], $sym]);
                coins_reset();
                foreach (glob(__DIR__ . '/../data/chart_' . $sym . '_*.json') ?: [] as $cf) @unlink($cf);
                flash('Монета ' . $sym . '‑x сохранена.');
            }
        }
        redirect('./?tab=coins');
    }
    if ($act === 'coin_toggle') {
        $sym = (string)($_POST['sym'] ?? '');
        q('UPDATE coins SET enabled = 1 - enabled WHERE sym = ?', [$sym]);
        coins_reset(); ensure_coin_rows($sym);
        flash('Монета ' . $sym . '‑x ' . (!empty(all_coins()[$sym]['enabled']) ? 'включена.' : 'выключена: пользователи её не видят, балансы сохранены.'));
        redirect('./?tab=coins');
    }
    // ---- объявления ----
    if ($act === 'ann_save') {
        $title = mb_substr(trim($_POST['title'] ?? ''), 0, 120);
        $body = mb_substr(trim($_POST['body'] ?? ''), 0, 2000) ?: null;
        $level = in_array($_POST['level'] ?? '', ['info', 'success', 'warning'], true) ? $_POST['level'] : 'info';
        // кому: всем или одному пользователю (почта или ID)
        $to = trim((string)($_POST['to'] ?? '')); $toUser = null;
        if (($_POST['scope'] ?? 'all') === 'one') {
            $toUser = q('SELECT id, email FROM users WHERE email = ? OR id = ?', [strtolower($to), ctype_digit($to) ? (int)$to : -1])->fetch() ?: false;
        }
        if ($title === '') flash('Введите заголовок объявления.', 'err');
        elseif ($toUser === false) flash('Пользователь «' . $to . '» не найден — укажите почту или ID.', 'err');
        elseif ($toUser && ($r = pann_ready()) !== true) flash('Персональные уведомления пока недоступны: ' . ($r === 'manual' ? 'не удалось автоматически поправить inc/core.php — см. инструкцию на этой вкладке.' : $r), 'err');
        elseif (!empty($_POST['id'])) {
            $uidT = $toUser ? (int)$toUser['id'] : null;
            if ($uidT || pann_column()) q('UPDATE announcements SET title=?, body=?, level=?, user_id=? WHERE id=?', [$title, $body, $level, $uidT, (int)$_POST['id']]);
            else q('UPDATE announcements SET title=?, body=?, level=? WHERE id=?', [$title, $body, $level, (int)$_POST['id']]);
            flash('Объявление обновлено.');
        }
        elseif ($toUser) { q('INSERT INTO announcements (title, body, level, active, user_id) VALUES (?,?,?,1,?)', [$title, $body, $level, $toUser['id']]); flash('Уведомление отправлено только пользователю ' . $toUser['email'] . '.'); }
        else { q('INSERT INTO announcements (title, body, level, active) VALUES (?,?,?,1)', [$title, $body, $level]); flash('Объявление опубликовано — его увидят все пользователи в кабинете.'); }
        redirect('./?tab=ann' . (($toUser === false || isset($r) && $r !== true) && !empty($_POST['id']) ? '&edit=' . (int)$_POST['id'] : ''));
    }
    if ($act === 'ann_toggle') { q('UPDATE announcements SET active = 1 - active WHERE id = ?', [(int)$_POST['id']]); flash('Статус объявления изменён.'); redirect('./?tab=ann'); }
    if ($act === 'ann_delete') { q('DELETE FROM announcements WHERE id = ?', [(int)$_POST['id']]); q('DELETE FROM ann_dismiss WHERE ann_id = ?', [(int)$_POST['id']]); flash('Объявление удалено.'); redirect('./?tab=ann'); }

    // заметки администратора к пользователю и сброс двухфакторной защиты
    $back = './?tab=users' . (($_POST['q'] ?? '') !== '' ? '&q=' . urlencode($_POST['q']) : '') . '&open=' . (int)($_POST['uid'] ?? 0) . '#u' . (int)($_POST['uid'] ?? 0);
    // смена адреса кошелька (красивый адрес). Балансы и история не трогаются:
    // они привязаны к кошельку, а старый адрес заменяется в истории и в контактах у всех пользователей.
    if ($act === 'addr_save') {
        $wid = (int)($_POST['wid'] ?? 0); $coin = (string)($_POST['coin'] ?? '');
        $new = trim((string)($_POST['address'] ?? ''));
        if (isset($_POST['random'])) $new = gen_address($coin);
        $row = q('SELECT a.id, a.address, w.user_id FROM addresses a JOIN wallets w ON w.id = a.wallet_id WHERE a.wallet_id = ? AND a.coin = ?', [$wid, $coin])->fetch();
        $_POST['uid'] = $row['user_id'] ?? 0;
        $back = './?tab=users' . (($_POST['q'] ?? '') !== '' ? '&q=' . urlencode($_POST['q']) : '') . '&open=' . (int)$_POST['uid'] . '&addr=' . $wid . '#u' . (int)$_POST['uid'];
        if (!$row) flash('Адрес не найден.', 'err');
        elseif ($new === $row['address']) flash('Адрес не изменился.', 'err');
        elseif (!preg_match('~^[A-Za-z0-9][A-Za-z0-9._:-]{3,79}$~', $new)) flash('Адрес: 4–80 символов — латинские буквы, цифры и . _ : - (без пробелов, первый символ буква или цифра).', 'err');
        elseif (q('SELECT 1 FROM addresses WHERE address = ? AND id <> ?', [$new, $row['id']])->fetch()) flash('Такой адрес уже занят другим кошельком.', 'err');
        else {
            $old = $row['address'];
            db()->beginTransaction();
            try {
                q('UPDATE addresses SET address = ? WHERE id = ?', [$new, $row['id']]);
                q('UPDATE txs SET from_addr = ? WHERE from_addr = ?', [$new, $old]);
                q('UPDATE txs SET to_addr = ? WHERE to_addr = ?', [$new, $old]);
                // контакты других людей с этим адресом — переписываем (если у человека уже есть контакт с новым адресом, старый оставляем)
                q('UPDATE IGNORE contacts SET address = ? WHERE address = ?', [$new, $old]);
                try { q('INSERT INTO user_notes (user_id, body, author) VALUES (?,?,?)', [$row['user_id'], 'Адрес ' . $coin . '‑x кошелька #' . $wid . ' изменён: ' . $old . ' → ' . $new, ADMIN_LOGIN]); } catch (Throwable $e) {}
                db()->commit();
                flash('Адрес ' . $coin . '‑x изменён на ' . $new . '. Старый адрес больше не принимает переводы.');
            } catch (Throwable $e) { db()->rollBack(); flash('Не удалось сохранить: ' . $e->getMessage(), 'err'); }
        }
        redirect($back);
    }
    if ($act === 'note_add') {
        $body = trim((string)($_POST['body'] ?? ''));
        if ($body === '') flash('Пустая заметка.', 'err');
        else { q('INSERT INTO user_notes (user_id, body, author) VALUES (?,?,?)', [(int)$_POST['uid'], mb_substr($body, 0, 2000), ADMIN_LOGIN]); flash('Заметка добавлена.'); }
        redirect($back);
    }
    if ($act === 'note_del') { q('DELETE FROM user_notes WHERE id = ? AND user_id = ?', [(int)$_POST['nid'], (int)$_POST['uid']]); flash('Заметка удалена.'); redirect($back); }
    if ($act === 'tfa_reset') {
        q('UPDATE users SET totp_secret = NULL, totp_last = NULL WHERE id = ?', [(int)$_POST['uid']]);
        q('INSERT INTO user_notes (user_id, body, author) VALUES (?,?,?)', [(int)$_POST['uid'], 'Двухфакторная защита сброшена администратором.', ADMIN_LOGIN]);
        flash('Двухфакторная защита отключена — пользователь войдёт по сид‑фразе и паролю.'); redirect($back);
    }
    if ($act === 'block') {
        q('UPDATE users SET blocked = 1 - blocked WHERE id = ?', [(int)$_POST['uid']]);
        flash('Статус пользователя изменён.'); redirect('./?tab=users');
    }
}

$p = prices();
page_head('Админ');
?>
<nav class="nav"><div class="wrap">
  <a href="./" class="logo"><?= logo_mark() ?><span class="logo-word"><?= h(SITE_NAME) ?></span><span class="muted" style="font-weight:500">&nbsp;· админ</span></a>
  <div class="nav-cta"><?= theme_toggle() ?><a href="../" class="btn btn-sm" target="_blank">Сайт</a><a href="?logout=1" class="btn btn-sm">Выйти</a></div>
</div></nav>
<main><div class="wrap adm">
  <?php $pend = (int)q('SELECT COUNT(*) FROM requests WHERE status = ?', ['pending'])->fetchColumn(); ?>
  <div class="tabs">
    <a href="?tab=req" class="<?= $tab === 'req' ? 'on' : '' ?>">Заявки<?= $pend ? ' <span class="cnt">' . $pend . '</span>' : '' ?></a>
    <a href="?tab=issue" class="<?= $tab === 'issue' ? 'on' : '' ?>">Начисление / списание</a>
    <a href="?tab=users" class="<?= $tab === 'users' ? 'on' : '' ?>">Пользователи и кошельки</a>
    <a href="?tab=tx" class="<?= $tab === 'tx' ? 'on' : '' ?>">Все транзакции</a>
    <a href="?tab=coins" class="<?= $tab === 'coins' ? 'on' : '' ?>">Монеты</a>
    <a href="?tab=ann" class="<?= $tab === 'ann' ? 'on' : '' ?>">Объявления</a>
    <a href="?tab=app" class="<?= $tab === 'app' ? 'on' : '' ?>">Приложение</a>
    <?php $supN = 0; if (function_exists('addons_db') && addons_db()) { try { $supN = (int)q('SELECT COUNT(*) FROM support_tickets WHERE admin_unread > 0')->fetchColumn(); } catch (Throwable $e) {} } ?>
    <a href="?tab=support" class="<?= $tab === 'support' ? 'on' : '' ?>">Поддержка<?= $supN ? ' <span class="cnt">' . $supN . '</span>' : '' ?></a>
    <a href="?tab=sess" class="<?= $tab === 'sess' ? 'on' : '' ?>">Сессии</a>
  </div>

<?php if ($tab === 'req'):
  $filter = $_GET['f'] ?? 'pending';
  $rows = $filter === 'all'
    ? q('SELECT r.*, u.email, w.name wname FROM requests r JOIN users u ON u.id = r.user_id JOIN wallets w ON w.id = r.wallet_id ORDER BY r.id DESC LIMIT 300')->fetchAll()
    : q('SELECT r.*, u.email, w.name wname FROM requests r JOIN users u ON u.id = r.user_id JOIN wallets w ON w.id = r.wallet_id WHERE r.status = ? ORDER BY r.id ASC', ['pending'])->fetchAll();
  $stn = ['pending' => 'ждёт', 'approved' => 'одобрена', 'rejected' => 'отклонена', 'cancelled' => 'отменена пользователем']; ?>
  <div class="inline" style="margin-bottom:16px">
    <a href="?tab=req" class="btn btn-sm <?= $filter !== 'all' ? 'btn-p' : '' ?>">Ожидают (<?= $pend ?>)</a>
    <a href="?tab=req&f=all" class="btn btn-sm <?= $filter === 'all' ? 'btn-p' : '' ?>">Все заявки</a>
  </div>
  <div class="box" style="overflow-x:auto">
  <?php if (!$rows): ?><div class="empty">Новых заявок нет</div><?php else: ?>
  <table><tr><th>#</th><th>Тип</th><th>Пользователь / кошелёк</th><th>Сумма</th><th>Комментарий</th><th>Создана</th><th>Решение</th></tr>
  <?php foreach ($rows as $r): $bl = wallet_balances((int)$r['wallet_id']); ?>
    <tr><td><?= $r['id'] ?></td>
      <td><span class="badge <?= $r['type'] === 'deposit' ? 'okb' : 'wait' ?>"><?= $r['type'] === 'deposit' ? 'пополнение' : 'вывод' ?></span></td>
      <td><?= h($r['email']) ?><br><span class="muted">#<?= $r['wallet_id'] ?> «<?= h($r['wname']) ?>» · сейчас <?= fmt_amount($bl[$r['coin']] ?? 0) ?> <?= h($r['coin']) ?>‑x</span></td>
      <td class="mono"><b><?= fmt_amount((int)$r['amount']) ?> <?= h($r['coin']) ?>‑x</b><br><span class="muted"><?= fmt_usd((int)$r['amount'] / UNIT * ($p[$r['coin']]['usd'] ?? 0)) ?></span></td>
      <td><?= h($r['note'] ?? '—') ?></td>
      <td class="muted"><?= h(date('d.m H:i', strtotime($r['created_at']))) ?></td>
      <td><?php if ($r['status'] === 'pending'): ?>
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="req_decide"><input type="hidden" name="rid" value="<?= $r['id'] ?>">
          <input type="text" name="admin_note" maxlength="200" placeholder="Ответ (необязательно)" style="width:180px">
          <button name="decision" value="approve" class="btn btn-sm btn-p">Одобрить</button>
          <button name="decision" value="reject" class="btn btn-sm">Отклонить</button></form>
      <?php else: ?><span class="badge <?= $r['status'] === 'approved' ? 'okb' : ($r['status'] === 'rejected' ? 'red' : '') ?>"><?= $stn[$r['status']] ?? h($r['status']) ?></span>
        <?= $r['admin_note'] ? '<br><span class="muted">' . h($r['admin_note']) . '</span>' : '' ?><?php endif; ?></td></tr>
  <?php endforeach; ?></table>
  <?php endif; ?>
  </div>

<?php elseif ($tab === 'issue'): ?>
  <div class="cards2">
    <div class="box">
      <h3>Выдать или списать монеты</h3>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="issue">
        <div class="row2">
          <div class="field"><label>Операция</label><select name="type"><option value="credit">Начислить</option><option value="debit">Списать</option></select></div>
          <div class="field"><label>Монета</label><select name="coin"><?php foreach (coins() as $s => $c): ?><option value="<?= $s ?>"><?= $s ?>‑x</option><?php endforeach; ?></select></div>
        </div>
        <div class="field"><label>Адрес кошелька (любой его монеты) или ID кошелька</label><input type="text" name="target" required value="<?= h($_GET['w'] ?? '') ?>" spellcheck="false"></div>
        <div class="field"><label>Сумма</label><input type="text" name="amount" required inputmode="decimal" placeholder="0.00"></div>
        <div class="field"><label>Комментарий (виден пользователю)</label><input type="text" name="note" maxlength="140"></div>
        <button class="btn btn-p btn-block btn-lg">Выполнить</button>
      </form>
    </div>
    <div class="box">
      <h3>Всего в обороте</h3>
      <?php $sums = []; foreach (q('SELECT coin, SUM(amount) s FROM balances GROUP BY coin') as $r) $sums[$r['coin']] = (int)$r['s'];
      foreach (coins() as $s => $c): $v = $sums[$s] ?? 0; ?>
      <div class="rate"><?= coin_icon($s, 28) ?><span class="n"><?= $s ?>‑x</span><span class="p"><?= fmt_amount($v, $c['dec']) ?></span><span class="ch muted"><?= fmt_usd($v / UNIT * ($p[$s]['usd'] ?? 0)) ?></span></div>
      <?php endforeach; ?>
    </div>
  </div>

<?php elseif ($tab === 'users'):
  $search = trim($_GET['q'] ?? '');
  $users = $search !== ''
    ? q('SELECT * FROM users WHERE email LIKE ? OR id = ? OR id IN (SELECT user_id FROM user_notes WHERE body LIKE ?) OR id IN (SELECT w.user_id FROM wallets w JOIN addresses a ON a.wallet_id = w.id WHERE a.address = ?) ORDER BY id DESC LIMIT 200',
        ['%' . $search . '%', ctype_digit($search) ? (int)$search : -1, '%' . $search . '%', $search])->fetchAll()
    : q('SELECT * FROM users ORDER BY id DESC LIMIT 200')->fetchAll();
  $notes = [];
  if ($users) { $ids = array_column($users, 'id'); foreach (q('SELECT * FROM user_notes WHERE user_id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY id DESC') as $n) $notes[$n['user_id']][] = $n; } ?>
  <form class="inline" style="margin-bottom:18px"><input type="hidden" name="tab" value="users"><input type="text" name="q" value="<?= h($search) ?>" placeholder="Почта, ID, адрес или текст заметки" style="max-width:360px"><button class="btn btn-sm">Найти</button><?php if ($search !== ''): ?><a href="?tab=users" class="btn btn-sm">Сбросить</a><?php endif; ?></form>
  <div class="box" style="overflow-x:auto">
  <style>
    .addr-ed{margin-top:4px}.addr-ed summary{cursor:pointer;font-size:12px;color:var(--muted);font-weight:600;display:inline}
    .addr-ed summary:hover{color:var(--txt)}
    .addr-row{display:flex;gap:6px;align-items:center;margin:6px 0}
    .addr-row .addr-c{width:58px;flex:none;font-size:12px;font-weight:600}
    .addr-row input[type=text]{font-family:var(--mono);font-size:12px;height:32px;min-width:260px;flex:1;padding:0 8px}
    .addr-row .btn{height:32px;padding:0 10px}
  </style>
  <table class="users-adm"><tr><th>ID</th><th>Почта</th><th>Кошельки</th><th>Заметки</th><th>Создан</th><th></th></tr>
  <?php foreach ($users as $us): $un = $notes[$us['id']] ?? []; ?>
    <tr id="u<?= $us['id'] ?>"><td><?= $us['id'] ?></td>
      <td><?= h($us['email']) ?> <?= $us['blocked'] ? '<span class="badge red">заблокирован</span>' : '' ?>
        <?php if (!empty($us['totp_secret'])): ?><br><span class="badge okb" title="Двухфакторная защита включена">2FA</span><?php endif; ?></td>
      <td><?php foreach (user_wallets((int)$us['id']) as $w): $b = wallet_balances((int)$w['id']); $s = 0; foreach ($b as $c => $v) $s += $v / UNIT * ($p[$c]['usd'] ?? 0); ?>
        <div style="margin-bottom:6px"><b>#<?= $w['id'] ?> <?= h($w['name']) ?></b> · <?= fmt_usd($s) ?>
          <a href="?tab=issue&w=<?= $w['id'] ?>" class="badge">выдать</a>
          <span class="muted" style="font-size:12px"><?php foreach ($b as $c => $v) if ($v) echo h($c) . '‑x ' . fmt_amount($v, coins()[$c]['dec'] ?? 8) . ' · '; ?></span>
          <details class="addr-ed"<?= (($_GET['addr'] ?? '') == $w['id']) ? ' open' : '' ?>><summary>адреса</summary>
            <?php foreach (wallet_addresses((int)$w['id']) as $c => $a): if (!isset(all_coins()[$c])) continue; ?>
            <form method="post" class="addr-row"><?= csrf_field() ?><input type="hidden" name="act" value="addr_save"><input type="hidden" name="wid" value="<?= $w['id'] ?>"><input type="hidden" name="coin" value="<?= h($c) ?>"><input type="hidden" name="q" value="<?= h($search) ?>">
              <span class="addr-c"><?= h($c) ?>‑x</span>
              <input type="text" name="address" value="<?= h($a) ?>" maxlength="80" spellcheck="false" autocomplete="off" required>
              <button class="btn btn-sm" title="Сохранить этот адрес">Сохранить</button>
              <button class="btn btn-sm" name="random" value="1" title="Сгенерировать новый случайный адрес" onclick="return confirm('Заменить адрес <?= h($c) ?>‑x на новый случайный?')">↻</button>
            </form>
            <?php endforeach; ?>
            <small class="muted">Балансы и история не меняются. Старый адрес перестаёт принимать переводы; в контактах и истории он заменится на новый.</small>
          </details></div>
      <?php endforeach; ?></td>
      <td class="notes-cell">
        <?php if ($un): ?><div class="note-last">📝 <?= h(mb_strimwidth($un[0]['body'], 0, 90, '…')) ?></div><?php endif; ?>
        <details<?= (($_GET['open'] ?? '') == $us['id']) ? ' open' : '' ?>><summary><?= $un ? 'Все заметки (' . count($un) . ')' : '+ Добавить заметку' ?></summary>
          <div class="notes">
            <?php foreach ($un as $n): ?>
            <div class="note-i"><div><?= nl2br(h($n['body'])) ?></div>
              <small class="muted"><?= h(date('d.m.Y H:i', strtotime($n['created_at']))) ?><?= $n['author'] ? ' · ' . h($n['author']) : '' ?></small>
              <form method="post" onsubmit="return confirm('Удалить заметку?')"><?= csrf_field() ?><input type="hidden" name="act" value="note_del"><input type="hidden" name="uid" value="<?= $us['id'] ?>"><input type="hidden" name="nid" value="<?= $n['id'] ?>"><input type="hidden" name="q" value="<?= h($search) ?>"><button class="rename">удалить</button></form></div>
            <?php endforeach; ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="note_add"><input type="hidden" name="uid" value="<?= $us['id'] ?>"><input type="hidden" name="q" value="<?= h($search) ?>">
              <textarea name="body" rows="2" maxlength="2000" required placeholder="Комментарий — видно только в админке"></textarea>
              <button class="btn btn-sm" style="margin-top:6px">Сохранить</button></form>
          </div></details></td>
      <td class="muted"><?= h(date('d.m.Y', strtotime($us['created_at']))) ?></td>
      <td><form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="block"><input type="hidden" name="uid" value="<?= $us['id'] ?>"><button class="btn btn-sm"><?= $us['blocked'] ? 'Разблокировать' : 'Заблокировать' ?></button></form>
        <a class="btn btn-sm" style="margin-top:6px" href="?tab=ann&to=<?= $us['id'] ?>">✉ Уведомление</a>
        <?php if (array_key_exists('swap_blocked', $us)): ?>
        <form method="post" style="margin-top:6px"><?= csrf_field() ?><input type="hidden" name="act" value="swap_toggle"><input type="hidden" name="uid" value="<?= $us['id'] ?>"><input type="hidden" name="q" value="<?= h($search) ?>">
          <button class="btn btn-sm"<?= $us['swap_blocked'] ? ' style="border-color:#e5484d;color:#e5484d"' : '' ?>><?= $us['swap_blocked'] ? '⛔ Свап запрещён · разрешить' : 'Запретить свап' ?></button></form>
        <?php endif; ?>
        <?php if (!empty($us['totp_secret'])): ?><form method="post" style="margin-top:6px" onsubmit="return confirm('Отключить двухфакторную защиту у <?= h($us['email']) ?>? Делайте это, только если вы уверены, что пишет владелец кошелька.')"><?= csrf_field() ?><input type="hidden" name="act" value="tfa_reset"><input type="hidden" name="uid" value="<?= $us['id'] ?>"><input type="hidden" name="q" value="<?= h($search) ?>"><button class="btn btn-sm">Сбросить 2FA</button></form><?php endif; ?></td></tr>
  <?php endforeach; ?>
  </table></div>

<?php elseif ($tab === 'coins'): echo '<style>.coins-adm{grid-template-columns:1.5fr 1fr}.coins-adm td{white-space:nowrap}.coins-adm td.mono{word-break:normal}</style>';
  $all = all_coins(); $edit = $all[$_GET['edit'] ?? ''] ?? null; $esym = $edit ? $_GET['edit'] : '';
  $held = []; foreach (q('SELECT coin, SUM(amount) s, COUNT(CASE WHEN amount > 0 THEN 1 END) n FROM balances GROUP BY coin') as $r) $held[$r['coin']] = $r; ?>
  <div class="cards2 coins-adm">
    <div class="box" style="overflow-x:auto">
      <h3>Монеты <span class="muted" style="font-size:12px;font-weight:400">выключенные скрыты у пользователей, балансы сохраняются</span></h3>
      <table><tr><th></th><th>Монета</th><th>Источник курса</th><th>Курс</th><th>На руках</th><th></th></tr>
      <?php foreach ($all as $s => $c): ?>
        <tr style="<?= $c['enabled'] ? '' : 'opacity:.5' ?>"><td><?= coin_icon($s, 28) ?></td>
          <td><b><?= h($s) ?>‑x</b><br><span class="muted"><?= h($c['name']) ?></span></td>
          <td class="mono"><?= $c['bn'] ? 'Binance ' . h($c['bn']) . ($c['inv'] ? ' ⁻¹' : '') : '' ?><?= $c['bn'] && $c['cg'] ? '<br>' : '' ?><?= $c['cg'] ? 'CG ' . h($c['cg']) : '' ?></td>
          <td class="mono"><?= $c['enabled'] ? fmt_usd($p[$s]['usd'] ?? 0) : '—' ?></td>
          <td class="mono"><?= fmt_amount((int)($held[$s]['s'] ?? 0), $c['dec']) ?><br><span class="muted"><?= (int)($held[$s]['n'] ?? 0) ?> кошел.</span></td>
          <td><div class="inline"><a class="btn btn-sm" href="?tab=coins&edit=<?= urlencode($s) ?>">Изменить</a>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="coin_toggle"><input type="hidden" name="sym" value="<?= h($s) ?>"><button class="btn btn-sm <?= $c['enabled'] ? 'danger' : 'btn-p' ?>"><?= $c['enabled'] ? 'Выключить' : 'Включить' ?></button></form></div></td></tr>
      <?php endforeach; ?></table>
    </div>
    <div class="box">
      <h3><?= $edit ? 'Изменить ' . h($esym) . '‑x' : 'Добавить монету' ?><?php if ($edit): ?> <a class="btn btn-sm" href="?tab=coins">+ новая</a><?php endif; ?></h3>
      <form method="post" id="coin-form" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="act" value="coin_save"><?php if (!$edit): ?><input type="hidden" name="is_new" value="1"><?php endif; ?>
        <div class="row2">
          <div class="field"><label>Тикер</label><input type="text" name="sym" id="cf-sym" required value="<?= h($esym) ?>" <?= $edit ? 'readonly' : '' ?> placeholder="DOGE" style="text-transform:uppercase"></div>
          <div class="field"><label>Название</label><input type="text" name="name" required value="<?= h($edit['name'] ?? '') ?>" placeholder="Dogecoin"></div>
        </div>
        <div class="row2">
          <div class="field"><label>Пара Binance (курс и график)</label><input type="text" name="bn" id="cf-bn" value="<?= h($edit['bn'] ?? '') ?>" placeholder="DOGEUSDT" style="text-transform:uppercase"></div>
          <div class="field"><label>id CoinGecko (запасной)</label><input type="text" name="cg" id="cf-cg" value="<?= h($edit['cg'] ?? '') ?>" placeholder="dogecoin"></div>
        </div>
        <div class="row2">
          <div class="field"><label>Цвет значка</label><input type="color" name="color" id="cf-color" value="<?= h($edit['color'] ?? '#c2a633') ?>" style="padding:4px;height:46px"></div>
          <div class="field"><label>Знак на значке</label><input type="text" name="glyph" id="cf-glyph" maxlength="2" value="<?= h($edit['glyph'] ?? '') ?>" placeholder="Ð"></div>
        </div>
        <div class="row2">
          <div class="field"><label>Знаков после запятой</label><input type="number" name="dec" min="0" max="8" value="<?= (int)($edit['dec'] ?? 4) ?>"></div>
          <div class="field"><label>Порядок в списке</label><input type="number" name="sort" value="<?= (int)($edit['sort'] ?? 100) ?>"></div>
        </div>
        <div class="field"><label>Начало адресов (необязательно)</label><input type="text" name="prefix" maxlength="6" value="<?= h($edit['prefix'] ?? '') ?>" placeholder="Dx — по умолчанию первая буква тикера + x"></div>
        <label class="check"><input type="checkbox" name="inv" value="1" <?= !empty($edit['inv']) ? 'checked' : '' ?>> Перевернуть курс (для пар вида USDCUSDT, как у USDT)</label>
        <div class="coin-probe"><span id="cf-prev"></span><button type="button" class="btn btn-sm" id="cf-check">Проверить курс</button><span id="cf-res" class="mono"></span></div>
        <label class="check"><input type="checkbox" name="force" value="1"> Сохранить без проверки курса</label>
        <button class="btn btn-p btn-block btn-lg"><?= $edit ? 'Сохранить' : 'Добавить монету' ?></button>
        <p class="muted" style="font-size:12px;margin-top:10px">Пару Binance можно найти на binance.com: тикер + USDT (DOGEUSDT, ADAUSDT). id CoinGecko — последняя часть адреса страницы монеты на coingecko.com. График подключится автоматически.</p>
      </form>
    </div>
  </div>
  <script>
  (() => {
    const $ = (id) => document.getElementById(id);
    const prev = () => { $('cf-prev').innerHTML = `<span class="coin-ic" style="--cbg:${$('cf-color').value};width:34px;height:34px;font-size:17px"><b>${($('cf-glyph').value || $('cf-sym').value[0] || '?').replace(/[<>&]/g, '')}</b><i>${window.COIN_BADGE || 'L'}</i></span>`; };
    ['cf-color', 'cf-glyph', 'cf-sym'].forEach((i) => $(i).addEventListener('input', prev)); prev();
    $('cf-check').onclick = () => {
      $('cf-res').textContent = 'проверяю…'; $('cf-res').className = 'mono muted';
      const qs = new URLSearchParams({ probe: 1, bn: $('cf-bn').value, cg: $('cf-cg').value, inv: document.querySelector('[name=inv]').checked ? 1 : 0 });
      fetch('./?' + qs).then((r) => r.json()).then((d) => {
        if (d.ok) { $('cf-res').textContent = `✓ $${d.usd} · ${d.src} · ${d.ch >= 0 ? '+' : ''}${d.ch}% за 24ч`; $('cf-res').className = 'mono up'; }
        else { $('cf-res').textContent = '✕ курс не найден'; $('cf-res').className = 'mono down'; }
      }).catch(() => { $('cf-res').textContent = '✕ ошибка'; });
    };
  })();
  </script>

<?php elseif ($tab === 'support'):
  $ok = function_exists('addons_db') && addons_db();
  $flt = $_GET['f'] ?? 'open';
  $tickets = $ok ? q("SELECT t.*, u.email, (SELECT body FROM support_messages m WHERE m.ticket_id = t.id ORDER BY m.id DESC LIMIT 1) last
      FROM support_tickets t JOIN users u ON u.id = t.user_id " . ($flt === 'all' ? '' : "WHERE t.status <> 'closed' ") . "ORDER BY t.admin_unread > 0 DESC, t.updated_at DESC LIMIT 300")->fetchAll() : [];
  $cur = null; $msgs = [];
  if ($ok && !empty($_GET['t'])) {
      $cur = q('SELECT t.*, u.email, u.id uid FROM support_tickets t JOIN users u ON u.id = t.user_id WHERE t.id = ?', [(int)$_GET['t']])->fetch();
      if ($cur) { $msgs = q('SELECT * FROM support_messages WHERE ticket_id = ? ORDER BY id', [$cur['id']])->fetchAll(); if ($cur['admin_unread']) q('UPDATE support_tickets SET admin_unread = 0 WHERE id = ?', [$cur['id']]); }
  }
  $stRu = ['open' => 'ждёт ответа', 'answered' => 'отвечено', 'closed' => 'закрыт']; ?>
  <style>
    .sup-n{display:inline-block;min-width:18px;height:18px;padding:0 5px;border-radius:9px;background:#e5484d;color:#fff;font-size:11px;line-height:18px;text-align:center;font-weight:700;margin-left:4px}
    .sp-wrap{display:grid;grid-template-columns:340px 1fr;gap:18px;align-items:start}
    .sp-list{display:grid;gap:6px;max-height:72vh;overflow:auto}
    .sp-wrap>div{min-width:0}.sp-t{display:block;overflow:hidden;min-width:0;padding:11px 13px;border-radius:12px;border:1px solid var(--line);background:var(--card);color:var(--txt)}
    .sp-t.on{border-color:var(--txt)} .sp-t.new{box-shadow:inset 3px 0 0 #e5484d}
    .sp-t b{display:block;font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .sp-t span{display:block;font-size:12px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px}
    .sp-chat{display:flex;flex-direction:column;height:72vh}
    .sp-msgs{flex:1;overflow:auto;display:flex;flex-direction:column;gap:10px;padding:6px 2px 12px}
    .sp-m{max-width:78%;display:flex;flex-direction:column}
    .sp-m.me{align-self:flex-end;align-items:flex-end}
    .sp-m .bb{padding:10px 13px;border-radius:15px;font-size:14px;line-height:1.45;white-space:pre-wrap;overflow-wrap:anywhere}
    .sp-m.me .bb{background:var(--a);color:var(--at);border-bottom-right-radius:4px}
    .sp-m.them .bb{background:var(--card2);border:1px solid var(--line);border-bottom-left-radius:4px}
    .sp-m small{font-size:11px;color:var(--dim);margin:3px 4px 0}
    .sp-reply textarea{font-family:inherit;font-size:14px;width:100%;min-height:76px;resize:vertical;box-sizing:border-box}
    @media (max-width:900px){.sp-wrap{grid-template-columns:1fr}.sp-chat{height:auto}.sp-msgs{max-height:60vh}}
  </style>
  <?php $hook = $ok ? papp_ready() : false; ?>
  <?php if (!$ok): ?><div class="box"><p class="err">Поддержка не подключена: загрузите файл <code>inc/addons.php</code>.</p></div><?php else: ?>
  <?php if ($hook !== true): ?><div class="box" style="margin-bottom:14px"><p class="err" style="margin:0">Кнопка «Support» у клиентов появится после подключения слоя приложения — откройте вкладку «Приложение».</p></div><?php endif; ?>
  <div class="sp-wrap">
    <div>
      <div class="seg-ctl" style="display:flex;margin-bottom:10px"><a href="?tab=support" style="flex:1;text-align:center" class="<?= $flt !== 'all' ? 'on' : '' ?>">Открытые</a><a href="?tab=support&f=all" style="flex:1;text-align:center" class="<?= $flt === 'all' ? 'on' : '' ?>">Все</a></div>
      <div class="sp-list">
        <?php if (!$tickets): ?><div class="empty box">Обращений нет</div><?php endif; ?>
        <?php foreach ($tickets as $t): ?>
          <a class="sp-t<?= $cur && $cur['id'] == $t['id'] ? ' on' : '' ?><?= $t['admin_unread'] ? ' new' : '' ?>" href="?tab=support&t=<?= $t['id'] ?><?= $flt === 'all' ? '&f=all' : '' ?>">
            <b><?= $t['admin_unread'] ? '<span class="sup-n" style="margin:0 6px 0 0">' . (int)$t['admin_unread'] . '</span>' : '' ?>#<?= $t['id'] ?> · <?= h($t['subject']) ?></b>
            <span><?= h($t['email']) ?> · <?= h($stRu[$t['status']] ?? $t['status']) ?> · <?= h(date('d.m H:i', strtotime($t['updated_at']))) ?></span>
            <span><?= h(mb_strimwidth(preg_replace('~\s+~', ' ', (string)$t['last']), 0, 70, '…')) ?></span></a>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="box sp-chat">
      <?php if (!$cur): ?><div class="empty" style="margin:auto">Выберите обращение слева</div><?php else: ?>
        <div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start;border-bottom:1px solid var(--line);padding-bottom:12px">
          <div><b style="font-size:16px">#<?= $cur['id'] ?> · <?= h($cur['subject']) ?></b><br>
            <span class="muted" style="font-size:13px"><a href="?tab=users&q=<?= urlencode($cur['email']) ?>&open=<?= (int)$cur['uid'] ?>#u<?= (int)$cur['uid'] ?>"><?= h($cur['email']) ?></a> · создан <?= h(date('d.m.Y H:i', strtotime($cur['created_at']))) ?> · <?= h($stRu[$cur['status']] ?? $cur['status']) ?></span></div>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="tid" value="<?= $cur['id'] ?>"><input type="hidden" name="f" value="<?= h($flt) ?>">
            <?php if ($cur['status'] === 'closed'): ?><button class="btn btn-sm" name="act" value="sup_open">Открыть снова</button><?php else: ?><button class="btn btn-sm" name="act" value="sup_close">Закрыть</button><?php endif; ?></form>
        </div>
        <div class="sp-msgs" id="sp-msgs">
          <?php foreach ($msgs as $m): ?><div class="sp-m <?= $m['sender'] === 'admin' ? 'me' : 'them' ?>" data-id="<?= $m['id'] ?>"><div class="bb"><?= h($m['body']) ?></div><small><?= $m['sender'] === 'admin' ? 'Вы' : 'Клиент' ?> · <?= h(date('d.m H:i', strtotime($m['created_at']))) ?></small></div><?php endforeach; ?>
        </div>
        <form method="post" class="sp-reply"><?= csrf_field() ?><input type="hidden" name="act" value="sup_reply"><input type="hidden" name="tid" value="<?= $cur['id'] ?>"><input type="hidden" name="f" value="<?= h($flt) ?>">
          <textarea name="body" required placeholder="Ответ клиенту (на английском — сайт на английском). Ctrl+Enter — отправить" id="sp-ta"></textarea>
          <div class="inline" style="margin-top:8px;justify-content:space-between"><label class="check" style="font-size:13px"><input type="checkbox" name="close" value="1"> закрыть после ответа</label><button class="btn btn-p">Отправить</button></div>
        </form>
        <script>
        (() => {
          const box = document.getElementById('sp-msgs'); box.scrollTop = box.scrollHeight;
          const ta = document.getElementById('sp-ta'); ta.addEventListener('keydown', (e) => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) ta.form.submit(); });
          const esc = (s) => s.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
          setInterval(async () => {
            if (document.hidden) return;
            const last = [...box.querySelectorAll('[data-id]')].pop()?.dataset.id || 0;
            try {
              const d = await (await fetch('?sup_poll=<?= (int)$cur['id'] ?>&after=' + last, { credentials: 'same-origin' })).json();
              d.messages.forEach((m) => box.insertAdjacentHTML('beforeend', `<div class="sp-m ${m.me ? 'me' : 'them'}" data-id="${m.id}"><div class="bb">${esc(m.body)}</div><small>${m.me ? 'Вы' : 'Клиент'} · ${m.at}</small></div>`));
              if (d.messages.length) box.scrollTop = box.scrollHeight;
            } catch (e) {}
          }, 6000);
        })();
        </script>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

<?php elseif ($tab === 'sess'):
  $ss = adm_table() ? q('SELECT * FROM admin_sessions WHERE expires_at > NOW() ORDER BY COALESCE(last_used, created_at) DESC')->fetchAll() : [];
  $me = (int)($_SESSION['adm_sid'] ?? 0);
  $devName = function (string $ua): string {
      $os = preg_match('~iPhone~', $ua) ? 'iPhone' : (preg_match('~iPad~', $ua) ? 'iPad' : (preg_match('~Android~', $ua) ? 'Android' : (preg_match('~Mac OS~', $ua) ? 'Mac' : (preg_match('~Windows~', $ua) ? 'Windows' : (preg_match('~Linux~', $ua) ? 'Linux' : 'Устройство')))));
      $br = preg_match('~Edg/~', $ua) ? 'Edge' : (preg_match('~OPR/|Opera~', $ua) ? 'Opera' : (preg_match('~YaBrowser~', $ua) ? 'Яндекс Браузер' : (preg_match('~Firefox/~', $ua) ? 'Firefox' : (preg_match('~Chrome/|CriOS/~', $ua) ? 'Chrome' : (preg_match('~Safari/~', $ua) ? 'Safari' : 'браузер')))));
      return $os . ' · ' . $br;
  }; ?>
  <div class="box" style="max-width:820px">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
      <h3 style="margin:0">Активные сессии админки <span class="muted" style="font-weight:400;font-size:13px"><?= count($ss) ?></span></h3>
      <?php if (count($ss) > 1): ?><form method="post" onsubmit="return confirm('Выйти из админки на всех остальных устройствах?')"><?= csrf_field() ?><input type="hidden" name="act" value="adm_kill_others"><button class="btn btn-sm">Завершить все, кроме этой</button></form><?php endif; ?>
    </div>
    <p class="muted" style="font-size:13px;margin:8px 0 14px">Можно быть в админке с нескольких устройств одновременно. Каждое устройство помнится <?= ADM_DAYS ?> дней — вход не слетает, даже если закрыть браузер или выйти из кошелька на сайте. Если устройство потеряно — завершите его сессию здесь.</p>
    <?php foreach ($ss as $x): ?>
      <div style="display:flex;gap:12px;align-items:center;padding:12px 0;border-top:1px solid var(--line)">
        <div style="width:38px;height:38px;border-radius:10px;background:var(--card2);border:1px solid var(--line);display:grid;place-items:center;font-size:18px;flex:none"><?= preg_match('~iPhone|Android|iPad~', (string)$x['ua']) ? '📱' : '💻' ?></div>
        <div style="flex:1;min-width:0"><b style="font-size:14px"><?= h($devName((string)$x['ua'])) ?></b><?= (int)$x['id'] === $me ? ' <span class="badge okb">это устройство</span>' : '' ?><br>
          <span class="muted" style="font-size:12px">IP <?= h($x['ip'] ?: '—') ?> · вход <?= h(date('d.m.Y H:i', strtotime($x['created_at']))) ?><?= $x['last_used'] ? ' · активность ' . h(date('d.m H:i', strtotime($x['last_used']))) : '' ?></span></div>
        <?php if ((int)$x['id'] !== $me): ?><form method="post" onsubmit="return confirm('Завершить эту сессию?')"><?= csrf_field() ?><input type="hidden" name="act" value="adm_kill"><input type="hidden" name="sid" value="<?= (int)$x['id'] ?>"><button class="btn btn-sm">Завершить</button></form>
        <?php else: ?><a class="btn btn-sm" href="?logout=1">Выйти</a><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

<?php elseif ($tab === 'app'):
  $ready = papp_ready();
  $devs = [];
  try { $devs = q('SELECT t.id, t.ua, t.created_at, t.last_used, u.email FROM remember_tokens t JOIN users u ON u.id = t.user_id WHERE t.expires_at > NOW() ORDER BY COALESCE(t.last_used, t.created_at) DESC LIMIT 200')->fetchAll(); } catch (Throwable $e) {}
  $dev = function (string $ua): string {
      $os = preg_match('~iPhone|iPad~', $ua) ? 'iPhone / iPad' : (preg_match('~Android~', $ua) ? 'Android' : (preg_match('~Mac OS~', $ua) ? 'Mac' : (preg_match('~Windows~', $ua) ? 'Windows' : 'устройство')));
      return $os;
  }; ?>
  <div class="cards2">
    <div class="box">
      <h3>Приложение для телефона</h3>
      <?php if ($ready === true): ?>
        <p><span class="badge okb">включено</span></p>
        <p class="muted" style="font-size:14px;line-height:1.6">Клиенты ставят <?= h(SITE_NAME) ?> на телефон прямо с сайта — без App Store и Google Play:</p>
        <ul style="font-size:14px;line-height:1.7;padding-left:18px;margin:8px 0 16px">
          <li><b>iPhone:</b> открыть сайт в <b>Safari</b> → кнопка «Поделиться» → «На экран Домой» → «Добавить».</li>
          <li><b>Android:</b> открыть сайт в <b>Chrome</b> → внизу появится «Install» (или меню ⋮ → «Установить приложение»).</li>
        </ul>
        <p class="muted" style="font-size:13px;line-height:1.6">В кабинете на телефоне сам появляется баннер «<?= h(SITE_NAME) ?> app · Install», а в Settings — кнопка «Install».
          В приложении: нижнее меню, без адресной строки, вход сохраняется на 90 дней (только на этом телефоне; «Log out everywhere» и смена пароля его сбрасывают).</p>
      <?php else: ?>
        <p><span class="badge red">не подключено</span></p>
        <?php if ($ready === 'manual'): ?>
          <p class="err" style="font-size:13px">Нет прав на запись <code>inc/core.php</code>. Откройте его в редакторе cPanel и в самый конец файла добавьте строку:<br><code>if (is_file(__DIR__ . '/pwa.php')) require_once __DIR__ . '/pwa.php';</code></p>
        <?php else: ?><p class="err" style="font-size:13px"><?= h($ready) ?></p><?php endif; ?>
      <?php endif; ?>
    </div>
    <div class="box">
      <h3>Устройства с приложением <span class="muted" style="font-weight:400;font-size:13px"><?= count($devs) ?></span></h3>
      <?php if (!$devs): ?><div class="empty">Пока никто не установил приложение.</div><?php endif; ?>
      <?php foreach ($devs as $d): ?>
        <div style="display:flex;gap:10px;align-items:center;padding:10px 0;border-bottom:1px solid var(--line)">
          <div style="flex:1;min-width:0"><b style="font-size:14px"><?= h($d['email']) ?></b><br>
            <span class="muted" style="font-size:12px"><?= h($dev((string)$d['ua'])) ?> · с <?= h(date('d.m.Y', strtotime($d['created_at']))) ?><?= $d['last_used'] ? ' · был ' . h(date('d.m H:i', strtotime($d['last_used']))) : '' ?></span></div>
          <form method="post" onsubmit="return confirm('Отключить это устройство?')"><?= csrf_field() ?><input type="hidden" name="act" value="dev_off"><input type="hidden" name="did" value="<?= (int)$d['id'] ?>"><button class="btn btn-sm">Отключить</button></form>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

<?php elseif ($tab === 'ann'):
  $pready = pann_ready();
  $anns = q('SELECT a.*, (SELECT COUNT(*) FROM ann_dismiss d WHERE d.ann_id = a.id) dis' . (pann_column() ? ', (SELECT email FROM users u WHERE u.id = a.user_id) to_email' : ', NULL to_email, NULL user_id') . ' FROM announcements a ORDER BY a.id DESC')->fetchAll();
  $emails = q('SELECT id, email FROM users ORDER BY email LIMIT 1000')->fetchAll();
  $preTo = '';
  if (!empty($_GET['to'])) { foreach ($emails as $e) if ((string)$e['id'] === (string)$_GET['to']) $preTo = $e['email']; }
  $edit = null; foreach ($anns as $a) if ((string)$a['id'] === ($_GET['edit'] ?? '')) $edit = $a;
  $lv = ['info' => 'Информация', 'success' => 'Хорошая новость', 'warning' => 'Важно']; ?>
  <div class="cards2">
    <div class="box">
      <h3><?= $edit ? 'Изменить объявление' : 'Новое объявление' ?><?php if ($edit): ?> <a class="btn btn-sm" href="?tab=ann">+ новое</a><?php endif; ?></h3>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="ann_save"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?: '' ?>">
        <?php $toCur = $edit ? (string)($edit['to_email'] ?? '') : $preTo; ?>
        <div class="field"><label>Кому</label>
          <div class="seg-ctl" style="display:flex;width:100%">
            <label style="flex:1;cursor:pointer"><input type="radio" name="scope" value="all" <?= $toCur === '' ? 'checked' : '' ?> style="display:none" onchange="document.getElementById('to-one').hidden=true"><span class="scope-b">Всем пользователям</span></label>
            <label style="flex:1;cursor:pointer"><input type="radio" name="scope" value="one" <?= $toCur !== '' ? 'checked' : '' ?> style="display:none" onchange="document.getElementById('to-one').hidden=false"><span class="scope-b">Одному пользователю</span></label>
          </div>
          <div id="to-one" <?= $toCur === '' ? 'hidden' : '' ?> style="margin-top:8px">
            <input type="text" name="to" list="emails-dl" value="<?= h($toCur) ?>" placeholder="Почта или ID пользователя" autocomplete="off">
            <datalist id="emails-dl"><?php foreach ($emails as $e): ?><option value="<?= h($e['email']) ?>">#<?= (int)$e['id'] ?></option><?php endforeach; ?></datalist>
            <?php if ($pready !== true): ?><p class="err" style="margin-top:8px;font-size:12px">Персональные уведомления не включились автоматически: нет прав на запись <code>inc/core.php</code><?= $pready === 'manual' ? '' : ' (' . h($pready) . ')' ?>. Откройте <code>inc/core.php</code>, найдите функцию <code>announcements_for</code> и замените в ней<br><code><?= h(PANN_OLD) ?></code><br>на<br><code><?= h(PANN_NEW) ?></code></p><?php endif; ?>
          </div>
        </div>
        <style>.scope-b{display:block;text-align:center;padding:8px 10px;border-radius:7px;font-size:13px;font-weight:600;color:var(--muted)}input[name=scope]:checked+.scope-b{background:var(--card);color:var(--txt);box-shadow:var(--shadow)}</style>
        <div class="field"><label>Тип</label><select name="level"><?php foreach ($lv as $k => $l): ?><option value="<?= $k ?>" <?= ($edit['level'] ?? 'info') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>Заголовок</label><input type="text" name="title" maxlength="120" required value="<?= h($edit['title'] ?? '') ?>" placeholder="New coin: DOGE‑x"></div>
        <div class="field"><label>Текст (необязательно, ссылки станут кликабельными)</label><textarea name="body" rows="5" maxlength="2000" style="font-family:inherit"><?= h($edit['body'] ?? '') ?></textarea></div>
        <button class="btn btn-p btn-block btn-lg"><?= $edit ? 'Сохранить' : 'Опубликовать' ?></button>
        <p class="muted" style="font-size:12px;margin-top:10px">Сайт на английском — пишите объявления на английском. Пользователь может скрыть объявление крестиком; в расширении оно тоже показывается.</p>
      </form>
    </div>
    <div class="box" style="overflow-x:auto">
      <h3>Опубликованные</h3>
      <?php if (!$anns): ?><div class="empty">Объявлений пока нет</div><?php endif; ?>
      <?php foreach ($anns as $a): ?>
      <div class="ann ann-<?= h($a['level']) ?>" style="<?= $a['active'] ? '' : 'opacity:.5' ?>"><div class="ann-b"><b><?= h($a['title']) ?></b><?php if ($a['body']): ?><p><?= ann_html($a['body']) ?></p><?php endif; ?>
        <small><?= $a['to_email'] ? '<b>👤 только ' . h($a['to_email']) . '</b> · ' : (!empty($a['user_id']) ? '<b>👤 пользователь удалён</b> · ' : 'всем · ') ?><?= h(date('d.m.Y H:i', strtotime($a['created_at']))) ?> · <?= $a['active'] ? 'показывается' : 'скрыто' ?> · <?= $a['to_email'] ? ((int)$a['dis'] ? 'прочитано и скрыто' : 'ещё не скрыл') : 'скрыли ' . (int)$a['dis'] . ' польз.' ?></small>
        <div class="inline" style="margin-top:8px"><a class="btn btn-sm" href="?tab=ann&edit=<?= $a['id'] ?>">Изменить</a>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="ann_toggle"><input type="hidden" name="id" value="<?= $a['id'] ?>"><button class="btn btn-sm"><?= $a['active'] ? 'Снять с показа' : 'Показывать' ?></button></form>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="ann_delete"><input type="hidden" name="id" value="<?= $a['id'] ?>"><button class="btn btn-sm danger">Удалить</button></form></div></div></div>
      <?php endforeach; ?>
    </div>
  </div>

<?php else:
  $txs = q('SELECT * FROM txs ORDER BY id DESC LIMIT 300')->fetchAll(); ?>
  <div class="box" style="overflow-x:auto">
  <table><tr><th>Время</th><th>Тип</th><th>Сумма</th><th>Откуда</th><th>Куда</th><th>Хэш</th><th></th></tr>
  <?php foreach ($txs as $t): ?>
    <tr><td class="muted"><?= h(date('d.m H:i', strtotime($t['created_at']))) ?></td>
      <td><span class="badge"><?= ['transfer' => 'перевод', 'credit' => 'начисление', 'debit' => 'списание', 'swap' => 'обмен'][$t['type']] ?? h($t['type']) ?></span></td>
      <td class="mono"><?= fmt_amount((int)$t['amount']) ?> <?= h($t['coin']) ?>‑x<?php if ($t['type'] === 'swap'): ?><br>→ <?= fmt_amount((int)$t['amount2']) ?> <?= h($t['coin2']) ?>‑x<?php endif; ?></td>
      <td class="mono"><?= $t['from_wallet'] ? '#' . $t['from_wallet'] . '<br>' : '' ?><?= h($t['from_addr'] ?? '—') ?></td>
      <td class="mono"><?= $t['to_wallet'] ? '#' . $t['to_wallet'] . '<br>' : '' ?><?= h($t['to_addr'] ?? '—') ?></td>
      <td class="mono"><?= substr($t['hash'], 0, 16) ?>…</td>
      <td><?php if (in_array($t['type'], ['credit', 'debit'], true)): ?><form method="post" onsubmit="return confirm('Удалить <?= $t['type'] === 'credit' ? 'начисление' : 'списание' ?> <?= h(fmt_amount((int)$t['amount'])) ?> <?= h($t['coin']) ?>‑x?\n\nБаланс кошелька вернётся как было до этой операции, а запись пропадёт из истории пользователя.')"><?= csrf_field() ?><input type="hidden" name="act" value="tx_undo"><input type="hidden" name="tid" value="<?= (int)$t['id'] ?>"><button class="btn btn-sm" title="Удалить операцию и откатить баланс">Удалить</button></form><?php endif; ?></td></tr>
  <?php endforeach; ?>
  </table></div>
<?php endif; ?>
</div></main>
<?php page_foot(); ?>
