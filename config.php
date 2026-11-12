<?php
// =====================================================================
//  Настройки Loyal.
//  Доступ к базе, пароли и секретные ключи пишет установщик (install.php)
//  в файл config.local.php — здесь их трогать не нужно.
// =====================================================================

$__local = __DIR__ . '/config.local.php';
if (is_file($__local)) require $__local;
elseif (basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'install.php' && PHP_SAPI !== 'cli') {
    // сайт ещё не установлен — отправляем на установщик
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    if (preg_match('~/(admin|download)$~', $base)) $base = dirname($base);
    header('Location: ' . rtrim($base, '/') . '/install.php');
    exit;
}

// ===== Основное =====
defined('SITE_NAME') || define('SITE_NAME', 'Loyal');
defined('TIMEZONE')  || define('TIMEZONE', 'Europe/Moscow');   // часовой пояс для дат в истории

// Адрес сайта для расширения, без слэша в конце, например 'https://loyal-true.com'.
// Пусто — определяется автоматически по адресу, с которого открыт сайт.
defined('SITE_URL')  || define('SITE_URL', '');

// ===== Монеты =====
// bn — пара на Binance (основной источник курса и графика), cg — id в CoinGecko (запасной).
// USDT считается через пару USDC/USDT (inv — перевернуть курс).
// color/bg — цвет значка, glyph — знак на значке, ink — цвет знака (по умолчанию белый).
$COINS = [
    'USDT' => ['name' => 'Tether',   'cg' => 'tether',           'bn' => 'USDCUSDT', 'inv' => true, 'color' => '#26A17B', 'glyph' => '₮', 'dec' => 2],
    'BTC'  => ['name' => 'Bitcoin',  'cg' => 'bitcoin',          'bn' => 'BTCUSDT',  'color' => '#F7931A', 'glyph' => '₿', 'dec' => 8],
    'ETH'  => ['name' => 'Ethereum', 'cg' => 'ethereum',         'bn' => 'ETHUSDT',  'color' => '#627EEA', 'glyph' => 'Ξ', 'dec' => 6],
    'SOL'  => ['name' => 'Solana',   'cg' => 'solana',           'bn' => 'SOLUSDT',  'color' => '#9945FF', 'bg' => 'linear-gradient(135deg,#9945FF,#19FB9B)', 'glyph' => '◎', 'dec' => 4],
    'BNB'  => ['name' => 'BNB',      'cg' => 'binancecoin',      'bn' => 'BNBUSDT',  'color' => '#F0B90B', 'glyph' => '◆', 'ink' => '#1E2026', 'dec' => 4],
    'TON'  => ['name' => 'Toncoin',  'cg' => 'the-open-network', 'bn' => 'TONUSDT',  'color' => '#0098EA', 'glyph' => '▽', 'dec' => 4],
    'TRX'  => ['name' => 'TRON',     'cg' => 'tron',             'bn' => 'TRXUSDT',  'color' => '#EB0029', 'glyph' => '◁', 'dec' => 2],
    'XRP'  => ['name' => 'XRP',      'cg' => 'ripple',           'bn' => 'XRPUSDT',  'color' => '#2B3138', 'glyph' => '✕', 'dec' => 2],
];

// ===== Ограничения =====
defined('MAX_WALLETS') || define('MAX_WALLETS', 10);  // кошельков на пользователя
defined('MAX_PENDING') || define('MAX_PENDING', 5);   // заявок (пополнение/вывод) одновременно в ожидании

// ===== Расширение для браузера =====
// Ссылки на страницу расширения в магазинах. Пока пусто — кнопка в кабинете скачивает архив
// расширения прямо с сайта (адрес сайта уже вписан внутрь) и показывает, как его установить.
defined('EXT_CHROME_URL') || define('EXT_CHROME_URL', '');  // Chrome Web Store (подходит и для Opera, Brave, Яндекс.Браузера, Vivaldi)
defined('EXT_EDGE_URL')   || define('EXT_EDGE_URL', '');    // Microsoft Edge Add-ons (если пусто — Edge откроет Chrome Web Store)

// ===== Новости (вкладка News в кабинете) =====
// Открытые RSS-ленты крипто-изданий. Сайт показывает заголовок, картинку и короткий анонс,
// по клику открывается оригинал статьи. Можно убрать или добавить свою ленту (RSS или Atom).
if (!isset($NEWS_FEEDS)) $NEWS_FEEDS = [
    ['name' => 'CoinDesk',          'url' => 'https://www.coindesk.com/arc/outboundfeeds/rss/'],
    ['name' => 'Cointelegraph',     'url' => 'https://cointelegraph.com/rss'],
    ['name' => 'Decrypt',           'url' => 'https://decrypt.co/feed'],
    ['name' => 'Bitcoin Magazine',  'url' => 'https://bitcoinmagazine.com/.rss/full/'],
];
defined('NEWS_TTL') || define('NEWS_TTL', 900);   // как часто обновлять новости, сек (15 минут)

// ===== Промо-видео на главной =====
// Вариант 1: загрузите файл assets/video/promo.mp4 (и картинку-обложку assets/video/promo.jpg) — блок появится сам.
// Вариант 2: залейте ролик на YouTube и впишите ссылку ниже (уберите // в начале строки).
// define('PROMO_YOUTUBE', 'https://youtu.be/XXXXXXXXXXX');
