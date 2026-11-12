<?php
// News: crypto headlines from public RSS feeds of crypto media, shown in the site's style
require __DIR__ . '/inc/core.php';
require __DIR__ . '/inc/news.php';
$u = require_user();

$cacheFile = __DIR__ . '/data/news.json';
// background refresh, called by the page itself when the copy is old
if (isset($_GET['refresh'])) {
    header('Content-Type: application/json');
    $d = news_items();
    echo json_encode(['t' => $d['t'] ?? 0, 'n' => count($d['items'] ?? [])]);
    exit;
}
$cache = is_file($cacheFile) ? json_decode((string)file_get_contents($cacheFile), true) : null;
$data = $cache ?: news_items();          // first visit — load right away
$stale = $cache && time() - ($cache['t'] ?? 0) >= NEWS_TTL;
$items = $data['items'] ?? [];
$coins = coins();
foreach ($items as &$it) $it['tags'] = news_tag($it, $coins);
unset($it);
$counts = [];
foreach ($items as $it) foreach ($it['tags'] as $t) $counts[$t] = ($counts[$t] ?? 0) + 1;
global $NEWS_FEEDS;
$srcNames = $data['sources'] ?? array_column($NEWS_FEEDS ?? [], 'name');

function news_card(array $it, bool $big = false): string {
    $tags = implode(' ', $it['tags']);
    $first = $it['tags'][0] ?? null;
    $c = $first ? (coins()[$first] ?? []) : [];
    $ph = '<div class="nw-ph" style="--c:' . h($c['color'] ?? 'var(--txt)') . '">' . ($first ? coin_icon($first, $big ? 64 : 44) : '<b>' . h(mb_substr($it['source'], 0, 1)) . '</b>') . '</div>';
    $img = $it['img'] ? '<img src="' . h($it['img']) . '" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.remove()">' : '';
    $chips = '';
    foreach (array_slice($it['tags'], 0, 3) as $t) $chips .= '<span class="nw-tag">' . coin_icon($t, 16) . h($t) . '‑x</span>';
    return '<a class="nw-card' . ($big ? ' nw-big' : '') . '" href="' . h($it['link']) . '" target="_blank" rel="noopener noreferrer nofollow"'
         . ' data-tags="' . h($tags) . '" data-q="' . h(mb_strtolower($it['title'] . ' ' . $it['text'] . ' ' . $it['source'])) . '">'
         . '<div class="nw-img">' . $ph . $img . '</div>'
         . '<div class="nw-body"><div class="nw-meta"><b>' . h($it['source']) . '</b><span>·</span><time datetime="' . date('c', $it['ts']) . '" title="' . h(fmt_date(date('Y-m-d H:i:s', $it['ts']))) . '">' . h(time_ago($it['ts'])) . '</time></div>'
         . '<h3>' . h($it['title']) . '</h3>'
         . ($it['text'] !== '' ? '<p>' . h($it['text']) . '</p>' : '')
         . '<div class="nw-foot">' . $chips . '<span class="nw-go">Read on ' . h($it['source']) . ' ↗</span></div></div></a>';
}

page_head('News', 'app-page');
site_nav();
cab_tabs('news');
?>
<main><div class="wrap">
  <div class="page-head rv"><div><h1>News</h1>
    <p class="sub">Crypto headlines from <?= h(implode(', ', $srcNames)) ?><?php if (!empty($data['t'])): ?> · updated <span id="nw-upd"><?= h(time_ago((int)$data['t'])) ?></span><?php endif; ?></p></div></div>

  <?php if (!$items): ?>
    <div class="box empty rv"><p><b>News isn’t available right now.</b></p><p class="muted">The news sources didn’t respond. Try again in a few minutes.</p></div>
  <?php else: ?>
  <div class="nw-bar rv">
    <div class="nw-chips" id="nw-chips">
      <button type="button" class="on" data-f="">All <small><?= count($items) ?></small></button>
      <?php foreach ($coins as $sym => $c): if (empty($counts[$sym])) continue; ?>
        <button type="button" data-f="<?= h($sym) ?>"><?= coin_icon($sym, 18) ?><?= h($sym) ?>‑x <small><?= $counts[$sym] ?></small></button>
      <?php endforeach; ?>
    </div>
    <input type="search" id="nw-q" placeholder="Search news" autocomplete="off">
  </div>

  <?php
    // featured: newest story with a picture
    $fi = 0; foreach ($items as $i => $it) if ($it['img']) { $fi = $i; break; }
    $feat = $items[$fi]; array_splice($items, $fi, 1);
  ?>
  <div class="nw-grid" id="nw-grid">
    <?= news_card($feat, true) ?>
    <?php foreach ($items as $it) echo news_card($it); ?>
  </div>
  <div class="box empty" id="nw-none" hidden><p class="muted">No stories match.</p></div>
  <p class="muted nw-note">Headlines and teasers come from the publishers’ public RSS feeds; full articles open on their sites. News is about the real coins — the ‑x coins in your wallet follow their prices.</p>
  <?php endif; ?>
</div></main>
<script>
(() => {
  const grid = document.getElementById('nw-grid'); if (!grid) return;
  const cards = [...grid.children], chips = document.getElementById('nw-chips'), q = document.getElementById('nw-q'), none = document.getElementById('nw-none');
  let f = '';
  const apply = () => {
    const s = q.value.trim().toLowerCase(); let n = 0;
    cards.forEach((c) => { const ok = (!f || c.dataset.tags.split(' ').includes(f)) && (!s || c.dataset.q.includes(s)); c.hidden = !ok; if (ok) n++; });
    // the first visible card is shown large
    cards.forEach((c) => c.classList.remove('nw-big')); const first = cards.find((c) => !c.hidden); if (first) first.classList.add('nw-big');
    none.hidden = n > 0;
  };
  chips.addEventListener('click', (e) => { const b = e.target.closest('button'); if (!b) return; f = b.dataset.f; chips.querySelectorAll('button').forEach((x) => x.classList.toggle('on', x === b)); apply(); });
  q.addEventListener('input', apply);
  <?php if ($stale): ?>fetch('news.php?refresh=1', { credentials: 'same-origin' }).then((r) => r.json()).then((d) => { if (d.n) { const u = document.getElementById('nw-upd'); if (u) u.innerHTML = 'just now · <a href="news.php">show new</a>'; } }).catch(() => {});<?php endif; ?>
})();
</script>
<?php page_foot(); ?>
