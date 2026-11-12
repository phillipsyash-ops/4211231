<?php
// =====================================================================
//  Установщик Loyal. Открыть один раз: https://ваш-сайт/install.php
//  Проверяет хостинг, создаёт таблицы, админа и секретные ключи,
//  записывает config.local.php и удаляет сам себя.
// =====================================================================
declare(strict_types=1);
session_start();
$ROOT  = __DIR__;
$LOCAL = $ROOT . '/config.local.php';
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ---------- уже установлено ----------
if (is_file($LOCAL)) {
    $deleted = @unlink(__FILE__);
    page('Уже установлено', '<p class="sub">Сайт уже настроен.</p>'
        . ($deleted ? '<div class="note">Файл install.php удалён.</div>' : '<div class="err">Удалите файл <b>install.php</b> с хостинга вручную — он больше не нужен.</div>')
        . '<a class="btn btn-p btn-block btn-lg" href="./">На главную</a>');
    exit;
}

// ---------- проверка хостинга ----------
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
$checks = [
    ['PHP 8.0 или новее', version_compare(PHP_VERSION, '8.0.0', '>='), true, 'Сейчас ' . PHP_VERSION . '. Выберите версию PHP 8.x в панели хостинга (cPanel → Select PHP Version).'],
    ['Расширение PDO MySQL', extension_loaded('pdo_mysql'), true, 'Включите pdo_mysql в настройках PHP.'],
    ['Расширение mbstring', extension_loaded('mbstring'), true, 'Включите mbstring в настройках PHP.'],
    ['Расширение cURL (курсы)', extension_loaded('curl'), true, 'Включите curl в настройках PHP.'],
    ['Расширение zip (скачивание расширения)', class_exists('ZipArchive'), false, 'Без него кнопка скачает расширение без вписанного адреса сайта.'],
    ['Папка data/ доступна для записи', is_writable($ROOT . '/data'), true, 'Поставьте на папку data права 755 (или 775).'],
    ['Папка сайта доступна для записи', is_writable($ROOT), true, 'Нужно, чтобы записать config.local.php. Поставьте на папку права 755.'],
    ['Защищённое соединение (https)', $https, false, 'Подключите SSL-сертификат (в cPanel: SSL/TLS Status → AutoSSL). Без https не будет работать расширение.'],
];
$blocking = array_filter($checks, fn($c) => $c[2] && !$c[1]);

if (empty($_SESSION['inst_csrf'])) $_SESSION['inst_csrf'] = bin2hex(random_bytes(16));
$err = null; $v = $_POST + ['db_host' => 'localhost', 'db_name' => '', 'db_user' => '', 'admin_login' => 'admin', 'tz' => 'Europe/Moscow'];

// ---------- установка ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$blocking) {
    if (!hash_equals($_SESSION['inst_csrf'], $_POST['csrf'] ?? '')) $err = 'Сессия устарела, обновите страницу.';
    elseif (trim($v['db_name']) === '' || trim($v['db_user']) === '') $err = 'Заполните имя базы и пользователя.';
    elseif (!preg_match('/^[A-Za-z0-9_.\-]{3,40}$/', $v['admin_login'])) $err = 'Логин админа: 3–40 символов, латиница, цифры, _ . -';
    elseif (strlen($v['admin_pass'] ?? '') < 10) $err = 'Пароль админа — минимум 10 символов.';
    elseif (($v['admin_pass'] ?? '') !== ($v['admin_pass2'] ?? '')) $err = 'Пароли админа не совпадают.';
    elseif (!in_array($v['tz'], timezone_identifiers_list(), true)) $err = 'Неверный часовой пояс.';
    else {
        $dsn = 'mysql:host=' . trim($v['db_host']) . ';dbname=' . trim($v['db_name']) . ';charset=utf8mb4';
        try {
            $pdo = new PDO($dsn, trim($v['db_user']), $v['db_pass'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (Throwable $e) {
            $code = (int)(($e instanceof PDOException && isset($e->errorInfo[1])) ? $e->errorInfo[1] : 0);
            if (!$code && preg_match('/\[(\d{4})\]/', $e->getMessage(), $m)) $code = (int)$m[1];
            $why = [
                1045 => 'Неверный пароль базы (или такого пользователя нет). Проверьте пароль пользователя в cPanel → Manage My Databases → Current Users → Change Password.',
                1044 => 'Пользователь не добавлен к этой базе. В cPanel → Manage My Databases → Add User To Database выберите пользователя и базу, отметьте ALL PRIVILEGES.',
                1049 => 'Базы с таким именем нет. Проверьте полное имя с префиксом в cPanel → Manage My Databases → Current Databases.',
                2002 => 'Сервер базы не отвечает. Оставьте в поле «Сервер базы» значение localhost.',
            ];
            $err = 'Не удалось подключиться к базе. ' . ($why[$code] ?? ('Ответ сервера: ' . $e->getMessage()));
        }
        if (!$err) {
            try {
                $has = (bool)$pdo->query("SHOW TABLES LIKE 'users'")->fetch();
                // в базе уже есть таблицы другого сайта — не трогаем их
                $ours = $has && (bool)$pdo->query("SHOW COLUMNS FROM users LIKE 'seed_fp'")->fetch();
                $busy = (int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
                if (($has && !$ours) || (!$has && $busy > 0)) {
                    throw new RuntimeException('В этой базе уже есть таблицы другого сайта. Создайте для кошелька отдельную пустую базу — пользователя и пароль можно оставить прежними.');
                }
                if (!$has) {
                    $sql = preg_replace('/--[^\n]*/', '', (string)file_get_contents($ROOT . '/schema.sql'));
                    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) $pdo->exec($stmt);
                }
            } catch (RuntimeException $e) { $err = $e->getMessage(); } catch (Throwable $e) { $err = 'Ошибка при создании таблиц: ' . $e->getMessage(); }
        }
        if (!$err) {
            $key = bin2hex(random_bytes(12));
            $cfg = "<?php\n// Создано установщиком " . date('Y-m-d H:i') . ". Не публикуйте этот файл.\n"
                . "define('DB_DSN', " . var_export($dsn, true) . ");\n"
                . "define('DB_USER', " . var_export(trim($v['db_user']), true) . ");\n"
                . "define('DB_PASS', " . var_export($v['db_pass'] ?? '', true) . ");\n"
                . "// Солит отпечатки сид-фраз. НЕ МЕНЯТЬ — иначе никто не сможет войти.\n"
                . "define('SEED_PEPPER', " . var_export(bin2hex(random_bytes(32)), true) . ");\n"
                . "define('ADMIN_URL_KEY', " . var_export($key, true) . ");\n"
                . "define('ADMIN_LOGIN', " . var_export($v['admin_login'], true) . ");\n"
                . "define('ADMIN_PASS_HASH', " . var_export(password_hash($v['admin_pass'], PASSWORD_DEFAULT), true) . ");\n"
                . "define('TIMEZONE', " . var_export($v['tz'], true) . ");\n";
            if (@file_put_contents($LOCAL, $cfg, LOCK_EX) === false) $err = 'Не удалось записать config.local.php — проверьте права на папку сайта.';
            else {
                @chmod($LOCAL, 0640);
                $base = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
                $adminUrl = $base . '/admin/?k=' . $key;
                $deleted = @unlink(__FILE__);
                page('Готово', '<p class="sub">Loyal установлен.</p>
                  <div class="note warn"><b>Сохраните ссылку на админку</b> — она показывается один раз. Без ключа в ссылке админка отвечает «404».</div>
                  <div class="field"><label>Админка</label><div class="addr" id="adm">' . h($adminUrl) . '</div></div>
                  <button class="btn btn-block" type="button" onclick="navigator.clipboard.writeText(document.getElementById(\'adm\').textContent).then(()=>this.textContent=\'Скопировано\')">Скопировать ссылку</button>
                  <div class="field" style="margin-top:14px"><label>Логин</label><div class="addr">' . h($v['admin_login']) . '</div></div>
                  ' . ($deleted ? '<div class="note">Установщик удалён с сервера.</div>' : '<div class="err">Удалите файл <b>install.php</b> с хостинга — он больше не нужен.</div>') . '
                  <p class="muted" style="font-size:13px;margin:6px 0 16px">Ключ лежит в файле config.local.php (ADMIN_URL_KEY), если ссылка потеряется.</p>
                  <a class="btn btn-p btn-block btn-lg" href="./">Открыть сайт</a>');
                exit;
            }
        }
    }
}

// ---------- форма ----------
ob_start(); ?>
<p class="sub">Установка займёт минуту. Сначала создайте в панели хостинга базу MySQL и пользователя для неё.</p>
<div class="checks">
<?php foreach ($checks as [$name, $ok, $req, $hint]): ?>
  <div class="ck <?= $ok ? 'ok' : ($req ? 'bad' : 'warn') ?>"><span><?= $ok ? '✓' : ($req ? '✕' : '!') ?></span><div><?= h($name) ?><?php if (!$ok): ?><small><?= h($hint) ?></small><?php endif; ?></div></div>
<?php endforeach; ?>
</div>
<?php if ($blocking): ?>
  <div class="err">Исправьте отмеченные пункты и обновите страницу.</div>
<?php else: ?>
<?php if ($err): ?><div class="err"><?= h($err) ?></div><?php endif; ?>
<form method="post" autocomplete="off">
  <input type="hidden" name="csrf" value="<?= h($_SESSION['inst_csrf']) ?>">
  <h3>База данных</h3>
  <div class="field"><label>Сервер базы</label><input type="text" name="db_host" value="<?= h($v['db_host']) ?>" required></div>
  <div class="row2">
    <div class="field"><label>Имя базы</label><input type="text" name="db_name" value="<?= h($v['db_name']) ?>" placeholder="user_loyal" required></div>
    <div class="field"><label>Пользователь</label><input type="text" name="db_user" value="<?= h($v['db_user']) ?>" placeholder="user_loyal" required></div>
  </div>
  <div class="field"><label>Пароль базы</label><input type="password" name="db_pass"></div>
  <h3>Администратор</h3>
  <div class="field"><label>Логин</label><input type="text" name="admin_login" value="<?= h($v['admin_login']) ?>" required></div>
  <div class="row2">
    <div class="field"><label>Пароль (от 10 символов)</label><input type="password" name="admin_pass" required minlength="10"></div>
    <div class="field"><label>Повторите пароль</label><input type="password" name="admin_pass2" required minlength="10"></div>
  </div>
  <div class="field"><label>Часовой пояс</label><select name="tz">
    <?php foreach (['Europe/Kaliningrad', 'Europe/Moscow', 'Europe/Samara', 'Asia/Yekaterinburg', 'Asia/Novosibirsk', 'Europe/Kiev', 'Europe/Minsk', 'Europe/Berlin', 'Europe/London', 'UTC'] as $tz): ?>
      <option <?= $v['tz'] === $tz ? 'selected' : '' ?>><?= $tz ?></option><?php endforeach; ?>
  </select></div>
  <button class="btn btn-p btn-block btn-lg">Установить</button>
</form>
<?php endif;
page('Установка Loyal', ob_get_clean());

// ---------- оформление ----------
function page(string $title, string $body) { ?><!doctype html>
<html lang="ru" data-theme="light"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex"><title><?= h($title) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css">
<style>
  body{display:grid;place-items:center;padding:40px 16px}
  .panel{max-width:560px}
  .panel h3{font-size:14px;margin:22px 0 12px;font-weight:600}
  .checks{display:grid;gap:6px;margin-bottom:18px}
  .ck{display:flex;gap:10px;align-items:flex-start;font-size:13px;padding:8px 10px;border-radius:9px;background:var(--card2)}
  .ck span{width:18px;height:18px;border-radius:50%;display:grid;place-items:center;font-size:11px;font-weight:700;flex:none;color:#fff;margin-top:1px}
  .ck.ok span{background:var(--up)}.ck.bad span{background:var(--dn)}.ck.warn span{background:#c9902a}
  .ck small{display:block;color:var(--muted);font-size:12px}
</style></head>
<body><div class="panel"><img src="assets/logo/coin-128.png" width="56" height="56" alt="" style="display:block;margin-bottom:16px;border-radius:50%">
<h1><?= h($title) ?></h1><?= $body ?></div></body></html>
<?php }
