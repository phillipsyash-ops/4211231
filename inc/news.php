<?php
// Crypto news from public RSS/Atom feeds of crypto media. Cached in data/news.json.

function news_fetch_all(array $feeds): array {
    $mh = curl_multi_init(); $hs = [];
    foreach ($feeds as $i => $f) {
        $ch = curl_init($f['url']);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; ' . SITE_NAME . '-news/1.0)',
            CURLOPT_HTTPHEADER => ['Accept: application/rss+xml, application/atom+xml, application/xml;q=0.9, */*;q=0.5']]);
        curl_multi_add_handle($mh, $ch); $hs[$i] = $ch;
    }
    do { $st = curl_multi_exec($mh, $run); if ($run) curl_multi_select($mh, 1); } while ($run && $st === CURLM_OK);
    $out = [];
    foreach ($hs as $i => $ch) {
        $body = curl_multi_getcontent($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($body && $code >= 200 && $code < 300) $out[$i] = $body;
        curl_multi_remove_handle($mh, $ch); curl_close($ch);
    }
    curl_multi_close($mh);
    return $out;
}

function news_clean(string $html, int $max = 220): string {
    $t = html_entity_decode(strip_tags(preg_replace('~<(script|style|figure|figcaption)\b.*?</\1>~is', '', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = trim(preg_replace('/\s+/u', ' ', $t));
    $t = preg_replace('~^(The post .*? appeared first on .*)$~u', '', $t);
    if (mb_strlen($t) > $max) $t = rtrim(mb_substr($t, 0, $max - 1), " ,.;:-") . '…';
    return $t;
}
function news_url_ok(?string $u): ?string {
    $u = trim((string)$u);
    return preg_match('~^https?://[^\s"<>]+$~i', $u) ? $u : null;
}

function news_parse(string $xml, string $source): array {
    $prev = libxml_use_internal_errors(true);
    $x = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
    libxml_clear_errors(); libxml_use_internal_errors($prev);
    if (!$x) return [];
    $items = [];
    $nodes = isset($x->channel->item) ? $x->channel->item : (isset($x->item) ? $x->item : $x->entry);
    foreach ($nodes ?? [] as $it) {
        $ns = $it->getNamespaces(true);
        $link = (string)$it->link;
        if ($link === '' && isset($it->link)) foreach ($it->link as $l) { if ((string)$l['rel'] === '' || (string)$l['rel'] === 'alternate') { $link = (string)$l['href']; break; } }
        $link = news_url_ok($link); if (!$link) continue;
        $title = news_clean((string)$it->title, 200); if ($title === '') continue;
        $date = (string)($it->pubDate ?? '') ?: (string)($it->published ?? '') ?: (string)($it->updated ?? '');
        if ($date === '' && isset($ns['dc'])) $date = (string)$it->children($ns['dc'])->date;
        $ts = strtotime($date) ?: time();
        $content = '';
        if (isset($ns['content'])) $content = (string)$it->children($ns['content'])->encoded;
        $desc = (string)($it->description ?? '') ?: (string)($it->summary ?? '') ?: (string)($it->content ?? '');
        // picture: media:content / media:thumbnail / enclosure / first <img>
        $img = null;
        if (isset($ns['media'])) {
            $m = $it->children($ns['media']);
            foreach ([$m->content, $m->thumbnail, $m->group->content ?? null] as $node)
                if ($node) foreach ($node as $c) { $a = $c->attributes(); $u = news_url_ok((string)$a['url']);
                    if ($u && (!isset($a['medium']) || (string)$a['medium'] === 'image') && !preg_match('~\.(mp4|mp3|webm)(\?|$)~i', $u)) { $img = $u; break 2; } }
        }
        if (!$img && isset($it->enclosure)) { $a = $it->enclosure->attributes(); if (str_starts_with((string)$a['type'], 'image')) $img = news_url_ok((string)$a['url']); }
        if (!$img && preg_match('~<img[^>]+src=["\']([^"\']+)~i', $content . $desc, $mm)) $img = news_url_ok(html_entity_decode($mm[1]));
        $cats = [];
        foreach ($it->category ?? [] as $c) $cats[] = (string)$c ?: (string)$c['term'];
        $items[] = ['title' => $title, 'link' => $link, 'ts' => $ts, 'source' => $source, 'img' => $img,
                    'text' => news_clean($desc !== '' ? $desc : $content), 'cats' => array_slice(array_filter($cats), 0, 8)];
    }
    return $items;
}

// Which of our coins a story is about (by ticker or name in title, teaser and categories)
function news_tag(array $it, array $coins): array {
    $hay = ' ' . $it['title'] . ' ' . $it['text'] . ' ' . implode(' ', $it['cats']) . ' ';
    $tags = [];
    $alias = ['BTC' => ['bitcoin'], 'ETH' => ['ethereum', 'ether'], 'SOL' => ['solana'], 'BNB' => ['bnb chain', 'binance coin'],
              'TON' => ['toncoin', 'telegram open network'], 'TRX' => ['tron'], 'XRP' => ['ripple'], 'USDT' => ['tether']];
    foreach ($coins as $sym => $c) {
        $words = array_merge([$sym], $alias[$sym] ?? [], [strtolower($c['name'] ?? '')]);
        foreach (array_unique(array_filter($words)) as $w) {
            $re = ctype_upper($w) ? '~(?<![A-Za-z])' . preg_quote($w, '~') . '(?![A-Za-z])~' : '~\b' . preg_quote($w, '~') . '\b~i';
            if (strlen($w) >= 3 && preg_match($re, $hay)) { $tags[] = $sym; break; }
        }
    }
    return $tags;
}

// List of stories; refreshed at most every NEWS_TTL seconds, old copy is kept if all feeds fail
function news_items(bool $force = false): array {
    global $NEWS_FEEDS;
    $f = __DIR__ . '/../data/news.json';
    $cache = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    if (!$force && $cache && time() - ($cache['t'] ?? 0) < NEWS_TTL) return $cache;
    // only one request rebuilds the cache at a time; others get the old copy
    $lock = @fopen($f . '.lock', 'c');
    if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) return $cache ?: ['t' => 0, 'items' => []];
    $feeds = $NEWS_FEEDS ?? [];
    $raw = $feeds ? news_fetch_all($feeds) : [];
    $items = [];
    foreach ($raw as $i => $xml) foreach (news_parse($xml, $feeds[$i]['name']) as $it) $items[] = $it;
    if (!$items) {   // nothing came — keep the previous copy, retry in a minute
        $keep = $cache ?: ['t' => 0, 'items' => []];
        $keep['t'] = time() - NEWS_TTL + 60;
        @file_put_contents($f, json_encode($keep, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($lock) flock($lock, LOCK_UN);
        return $keep;
    }
    $seen = []; $uniq = [];
    usort($items, fn($a, $b) => $b['ts'] <=> $a['ts']);
    foreach ($items as $it) {
        $k = strtolower(preg_replace('~[^a-z0-9]+~i', '', $it['title']));
        if (isset($seen[$k]) || isset($seen[$it['link']])) continue;
        $seen[$k] = $seen[$it['link']] = 1;
        if ($it['ts'] > time() + 3600) $it['ts'] = time();
        $uniq[] = $it;
        if (count($uniq) >= 60) break;
    }
    $data = ['t' => time(), 'items' => $uniq, 'sources' => array_values(array_unique(array_map(fn($i) => $feeds[$i]['name'], array_keys($raw))))];
    @file_put_contents($f . '.tmp', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    @rename($f . '.tmp', $f);
    if ($lock) flock($lock, LOCK_UN);
    return $data;
}

function time_ago(int $ts): string {
    $d = max(0, time() - $ts);
    if ($d < 60) return 'just now';
    if ($d < 3600) return floor($d / 60) . ' min ago';
    if ($d < 86400) return floor($d / 3600) . ' h ago';
    if ($d < 86400 * 7) return floor($d / 86400) . ' d ago';
    return date('M j', $ts);
}
