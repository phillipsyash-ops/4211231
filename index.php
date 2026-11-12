<?php
require __DIR__ . '/inc/core.php';
$p = prices();
try {
    $stWallets = (int)q('SELECT COUNT(*) FROM wallets')->fetchColumn();
    $stTx = (int)q('SELECT COUNT(*) FROM txs')->fetchColumn();
} catch (Throwable $e) { $stWallets = $stTx = 0; }
$orbit = array_slice(array_keys(coins()), 0, 4);

page_head('Your  coin wallet', 'landing');
site_nav(true);
?>
<main>
<!-- HERO -->
<header class="hero"><div class="wrap">
  <div>
    <div class="pill rv"><b>NEW</b><span class="live-dot"></span> Private ledger is live</div>
    <h1 class="rv d1">Your coins,<br><span class="grad">on your own ledger</span></h1>
    <p class="lead rv d2">USDT, BTC, ETH, SOL and more in one wallet. Access with a recovery phrase only, instant transfers by address inside the blockchain network.</p>
    <div class="hero-cta rv d3">
      <a href="create.php" class="btn btn-p btn-lg">Open a wallet →</a>
      <a href="unlock.php" class="btn btn-lg">I have a recovery phrase</a>
      <?php if (is_file(__DIR__ . '/assets/video/promo.mp4') || defined('PROMO_YOUTUBE')): ?><a href="#video" class="btn btn-lg btn-ghost-play">▶ Watch video</a><?php endif; ?>
    </div>
    <div class="hero-stats rv d3">
      <div><b data-count="<?= count(coins()) ?>">0</b><span>coins in the ledger</span></div>
      <div><b data-count="<?= $stWallets ?>">0</b><span>wallets</span></div>
      <div><b data-count="<?= $stTx ?>">0</b><span>transactions</span></div>
    </div>
  </div>

  <!-- the 3D coin is drawn here by assets/coin3d.js; it drifts into the background as you scroll -->
  <div class="coin-stage" aria-hidden="true">
    <?php foreach (['a' => 58, 'b' => 50, 'c' => 42] as $cls => $sz): $s = array_shift($orbit); if (!$s) break; ?>
      <div class="orbit <?= $cls ?>"><?= coin_icon($s, $sz) ?></div>
    <?php endforeach; ?>
    <div class="stage-tag"><span class="live-dot"></span><?= count(coins()) ?> coins · live prices</div>
  </div>
</div></header>

<!-- TICKER -->
<div class="ticker"><div class="ticker-track">
<?php for ($k = 0; $k < 2; $k++) foreach (coins() as $s => $c): $x = $p[$s] ?? ['usd' => 0, 'ch' => 0]; ?>
  <div class="tk" data-chart="<?= $s ?>"><?= coin_icon($s, 24) ?><b><?= $s ?></b><span class="mono"><?= fmt_usd($x['usd']) ?></span><span class="mono <?= $x['ch'] >= 0 ? 'up' : 'down' ?>"><?= ($x['ch'] >= 0 ? '+' : '') . number_format($x['ch'], 2) ?>%</span></div>
<?php endforeach; ?>
</div></div>

<?php
// Promo video: upload assets/video/promo.mp4 (and optionally promo.jpg as the cover) — the section appears by itself.
// Or set PROMO_YOUTUBE in config.php to a YouTube link.
$vid = is_file(__DIR__ . '/assets/video/promo.mp4') ? asset('assets/video/promo.mp4') : null;
$poster = is_file(__DIR__ . '/assets/video/promo.jpg') ? asset('assets/video/promo.jpg') : null;
$yt = (defined('PROMO_YOUTUBE') && preg_match('~(?:youtu\.be/|v=|shorts/|embed/)([\w-]{11})~', PROMO_YOUTUBE, $m)) ? $m[1] : null;
if ($vid || $yt): ?>
<!-- PROMO VIDEO -->
<section id="video"><div class="wrap">
  <div class="sec-head rv"><div class="eyebrow">See it in action</div><h2><?= h(SITE_NAME) ?> <span class="grad">in one minute</span></h2></div>
  <div class="promo rv">
    <?php if ($vid): ?>
      <video id="promo-v" src="<?= h($vid) ?>" <?= $poster ? 'poster="' . h($poster) . '"' : '' ?> preload="metadata" playsinline controls></video>
    <?php else: ?>
      <div id="promo-yt" data-id="<?= h($yt) ?>" style="background-image:url(https://i.ytimg.com/vi/<?= h($yt) ?>/maxresdefault.jpg)"></div>
    <?php endif; ?>
    <button type="button" class="promo-play" id="promo-play" aria-label="Play video"><span>▶</span></button>
  </div>
</div></section>
<script>
(() => {
  const btn = document.getElementById('promo-play'), v = document.getElementById('promo-v'), yt = document.getElementById('promo-yt');
  if (v) { v.controls = false; v.addEventListener('pause', () => btn.hidden = false); v.addEventListener('play', () => { btn.hidden = true; v.controls = true; }); }
  btn.onclick = () => {
    if (v) return v.play();
    yt.innerHTML = '<iframe src="https://www.youtube-nocookie.com/embed/' + yt.dataset.id + '?autoplay=1&rel=0" allow="autoplay; encrypted-media; picture-in-picture; fullscreen" allowfullscreen></iframe>';
    btn.hidden = true;
  };
})();
</script>
<?php endif; ?>

<!-- COINS -->
<section id="coins"><div class="wrap">
  <div class="sec-head rv">
    <div class="eyebrow">Available coins</div>
    <h2>Every popular network, <span class="grad">has its own coins </span></h2>
    <p>These coins serve as the native assets of the network. Their market prices are tracked in real time. Tap on a coin to view its price chart.</p>
  </div>
  <div class="coins-grid">
  <?php $i = 0; foreach (coins() as $s => $c): $x = $p[$s] ?? ['usd' => 0, 'ch' => 0]; ?>
    <div class="coin-card rv d<?= $i++ % 4 ?>" data-chart="<?= $s ?>">
      <span class="hint">chart ↗</span>
      <div class="top"><?= coin_icon($s, 44) ?><div><b><?= h($c['name']) ?></b><small><?= $s ?></small></div></div>
      <div class="price" data-price="<?= $s ?>"><?= fmt_usd($x['usd']) ?></div>
      <div class="ch <?= $x['ch'] >= 0 ? 'up' : 'down' ?>" data-ch="<?= $s ?>"><?= ($x['ch'] >= 0 ? '+' : '') . number_format($x['ch'], 2) ?>%</div>
    </div>
  <?php endforeach; ?>
  </div>
</div></section>

<!-- LEDGER -->
<section id="ledger"><div class="wrap chain-wrap">
  <div class="rv">
    <div class="eyebrow">Local blockchain</div>
    <h2>A ledger of your own, <span class="grad">no middlemen</span></h2>
    <p class="muted" style="font-size:18px">Every transfer is written to the platform’s private ledger and gets a unique hash. No fees and no waiting for confirmations — a transfer settles in a second.</p>
    <ul class="chain-list">
      <li><span class="chk">✓</span><div><b>12‑word recovery phrase</b>The BIP‑39 standard used by hardware wallets. The phrase itself is never stored on the server.</div></li>
      <li><span class="chk">✓</span><div><b>Unique addresses</b>Every coin in every wallet gets its own randomly generated address.</div></li>
      <li><span class="chk">✓</span><div><b>Multiple wallets</b>Split your funds across as many wallets as you like under one phrase.</div></li>
    </ul>
  </div>
  <div class="chain rv d2"><div class="chain-track">
    <?php $ks = array_keys(coins()); for ($k = 0; $k < 2; $k++) for ($b = 0; $b < 6; $b++): $s = $ks[$b % max(1, count($ks))] ?? 'USDT'; ?>
    <div class="block"><b>Block #<?= 184200 + $b ?></b>coin: <?= $s ?><br>tx: <?= 3 + $b * 2 ?><div class="hs"><?= substr(hash('sha256', 'blk' . $b), 0, 40) ?>…</div></div>
    <?php endfor; ?>
  </div></div>
</div></section>

<!-- HOW -->
<section id="how"><div class="wrap">
  <div class="sec-head rv"><div class="eyebrow">Get started</div><h2>Three steps to your wallet</h2></div>
  <div class="steps">
    <div class="step rv"><h3>Create a wallet</h3><p>Enter your email and choose a password. It takes less than a minute.</p></div>
    <div class="step rv d1"><h3>Write down the phrase</h3><p>12 words are the only key to your wallet. Write them on paper and keep them somewhere safe.</p></div>
    <div class="step rv d2"><h3>Send by address</h3><p>Send  coins to other members by their address — instantly and with no fees.</p></div>
  </div>
</div></section>

<section><div class="wrap">
  <div class="cta-box rv">
    <h2>Ready to open <span class="grad">your wallet?</span></h2>
    <p>Your recovery phrase, addresses and wallets are generated right now.</p>
    <a href="create.php" class="btn btn-p btn-lg">Open a wallet →</a>
  </div>
</div></section>
</main>
<?php page_foot(); ?>
