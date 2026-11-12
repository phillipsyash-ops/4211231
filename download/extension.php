<?php
// Extension download: builds a zip from inc/extension with this site's address written in,
// so the extension connects to it right away.
require __DIR__ . '/../inc/core.php';

$src  = __DIR__ . '/../inc/extension';
$site = site_url();
$static = __DIR__ . '/loyal-extension.zip';

function send_zip(string $file) {
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="loyal-extension.zip"');
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: no-store');
    readfile($file);
    exit;
}

if (!class_exists('ZipArchive') || !is_dir($src)) {
    if (is_file($static)) send_zip($static);   // fallback — a zip without the site address
    http_response_code(500); exit('The PHP “zip” extension is not enabled on this server.');
}

// list the files and compute a build version so we don't rebuild on every click
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) if ($f->isFile()) $files[] = $f->getPathname();
sort($files);
$ver = md5($site . '|' . SITE_NAME . '|' . implode('|', array_map(fn($f) => $f . filemtime($f), $files)));
$out = __DIR__ . '/../data/ext_' . $ver . '.zip';

if (!is_file($out)) {
    foreach (glob(__DIR__ . '/../data/ext_*.zip') ?: [] as $old) @unlink($old);
    $origin = preg_replace('~^(https?://[^/]+).*$~', '$1', $site);
    $tmp = $out . '.' . bin2hex(random_bytes(4));
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { http_response_code(500); exit('Couldn’t build the archive.'); }
    foreach ($files as $f) {
        $rel = 'loyal-extension/' . str_replace('\\', '/', substr($f, strlen($src) + 1));
        $data = file_get_contents($f);
        if (basename($f) === 'config.js') {
            $data = preg_replace("~export const DEFAULT_SITE = '[^']*';~", 'export const DEFAULT_SITE = ' . json_encode($site, JSON_UNESCAPED_SLASHES) . ';', $data);
            $data = preg_replace("~export const APP_NAME = '[^']*';~", 'export const APP_NAME = ' . json_encode(SITE_NAME, JSON_UNESCAPED_UNICODE) . ';', $data);
        }
        if (basename($f) === 'manifest.json' && dirname($f) === $src) {
            $m = json_decode($data, true);
            $m['name'] = SITE_NAME . ' Wallet'; $m['short_name'] = SITE_NAME; $m['action']['default_title'] = SITE_NAME . ' Wallet';
            $m['host_permissions'] = [$origin . '/*'];   // site access is granted on install
            $data = json_encode($m, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        }
        $zip->addFromString($rel, $data);
    }
    $zip->close();
    @rename($tmp, $out);
    if (!is_file($out)) $out = $tmp;
}
send_zip($out);
