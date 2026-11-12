<?php
// Web app manifest: lets Loyal be installed on a phone's home screen (iPhone and Android)
require __DIR__ . '/config.php';
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
$name = defined('SITE_NAME') ? SITE_NAME : 'Loyal';
echo json_encode([
    'id' => './?app=loyal',
    'name' => $name . ' Wallet',
    'short_name' => $name,
    'description' => $name . ' — your ‑x coin wallet: balances, transfers, swaps, price alerts and news.',
    'lang' => 'en',
    'start_url' => './wallet.php?source=app',
    'scope' => './',
    'display' => 'standalone',
    'orientation' => 'portrait',
    'background_color' => '#f6f6f4',
    'theme_color' => '#f6f6f4',
    'categories' => ['finance', 'utilities'],
    'icons' => [
        ['src' => 'assets/app/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => 'assets/app/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => 'assets/app/maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
    'shortcuts' => [
        ['name' => 'Send', 'url' => './wallet.php?do=send', 'icons' => [['src' => 'assets/app/icon-96.png', 'sizes' => '96x96']]],
        ['name' => 'Swap', 'url' => './swap.php', 'icons' => [['src' => 'assets/app/icon-96.png', 'sizes' => '96x96']]],
        ['name' => 'Price alerts', 'url' => './alerts.php', 'icons' => [['src' => 'assets/app/icon-96.png', 'sizes' => '96x96']]],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
