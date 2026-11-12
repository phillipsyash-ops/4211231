<?php
// Insights: portfolio make-up and money flow for a period (in USD at current prices)
require __DIR__ . '/inc/core.php';
$u = require_user();
$uid = (int)$u['id'];
$wallets = user_wallets($uid);
if (!$wallets) { create_wallet($uid, 'Main'); $wallets = user_wallets($uid); }
$byId = array_column($wallets, null, 'id');

$sel = $_GET['w'] ?? 'all';
$ids = ($sel !== 'all' && isset($byId[(int)$sel])) ? [(int)$sel] : array_map('intval', array_keys($byId));
if (count($ids) > 1) $sel = 'all';
$days = in_array((int)($_GET['d'] ?? 30), [7, 30, 90], true) ? (int)($_GET['d'] ?? 30) : 30;

$p = prices();
$px = fn($c) => $p[$c]['usd'] ?? 0;
$usdOf = fn($c, $units) => $units / UNIT * $px($c);

// ---------- portfolio ----------
$hold = [];
foreach ($ids as $id) foreach (wallet_balances($id) as $c => $v) $hold[$c] = ($hold[$c] ?? 0) + $v;
$parts = [];
foreach ($hold as $c => $v) if ($v > 0 && isset(coins()[$c])) $parts[$c] = ['units' => $v, 'usd' => $usdOf($c, $v)];
uasort($parts, fn($a, $b) => $b['usd'] <=> $a['usd']);
$total = array_sum(array_column($parts, 'usd'));

// ---------- flow for the period ----------
$since = date('Y-m-d H:i:s', strtotime("-{$days} days"));
$ph = implode(',', array_fill(0, count($ids), '?'));
$txs = q("SELECT * FROM txs WHERE created_at >= ? AND (from_wallet IN ($ph) OR to_wallet IN ($ph)) ORDER BY id", array_merge([$since], $ids, $ids))->fetchAll();
$set = array_flip($ids);
$sum = ['in' => 0, 'out' => 0, 'credit' => 0, 'debit' => 0, 'swap' => 0];
$cnt = ['in' => 0, 'out' => 0, 'swap' => 0];
$byCoinOut = []; $recips = [];
$week = $days === 90;
$bucket = fn($ts) => $week ? date('Y-m-d', strtotime('monday this week', $ts)) : date('Y-m-d', $ts);
$buckets = [];
for ($t = strtotime(date('Y-m-d', strtotime("-" . ($days - 1) . " days"))); $t <= time(); $t += 86400) $buckets[$bucket($t)] = ['in' => 0, 'out' => 0];

foreach ($txs as $t) {
    $fromIn = isset($set[(int)$t['from_wallet']]); $toIn = isset($set[(int)$t['to_wallet']]);
    $b = $bucket(strtotime($t['created_at']));
    $v = $usdOf($t['coin'], (int)$t['amount']);
    if ($t['type'] === 'swap') { if ($fromIn) { $sum['swap'] += $v; $cnt['swap']++; } continue; }
    if ($t['type'] === 'credit' && $toIn) { $sum['credit'] += $v; $buckets[$b]['in'] = ($buckets[$b]['in'] ?? 0) + $v; continue; }
    if ($t['type'] === 'debit' && $fromIn) { $sum['debit'] += $v; $buckets[$b]['out'] = ($buckets[$b]['out'] ?? 0) + $v; $byCoinOut[$t['coin']] = ($byCoinOut[$t['coin']] ?? 0) + $v; continue; }
    if ($t['type'] !== 'transfer' || ($fromIn && $toIn)) continue;   // transfers between your own wallets don’t count
    if ($toIn) { $sum['in'] += $v; $cnt['in']++; $buckets[$b]['in'] = ($buckets[$b]['in'] ?? 0) + $v; }
    if ($fromIn) {
        $sum['out'] += $v; $cnt['out']++; $buckets[$b]['out'] = ($buckets[$b]['out'] ?? 0) + $v;
        $byCoinOut[$t['coin']] = ($byCoinOut[$t['coin']] ?? 0) + $v;
        $r = &$recips[$t['to_addr']]; $r = ['usd' => ($r['usd'] ?? 0) + $v, 'n' => ($r['n'] ?? 0) + 1, 'coin' => $t['coin']]; unset($r);
    }
}
arsort($byCoinOut);
uasort($recips, fn($a, $b) => $b['usd'] <=> $a['usd']);
$recips = array_slice($recips, 0, 6, true);
$labels = address_labels($uid);
$inTotal = $sum['in'] + $sum['credit']; $outTotal = $sum['out'] + $sum['debit'];

// ---------- SVG: portfolio donut ----------
function donut(array $parts, float $total): string {
    $r = 76; $w = 24; $c = 2 * M_PI * $r; $gap = count($parts) > 1 ? 3 : 0; $off = 0;
    $s = '<svg viewBox="0 0 200 200" class="donut" role="img" aria-label="Portfolio">';
    $s .= '<circle cx="100" cy="100" r="' . $r . '" fill="none" stroke="var(--line)" stroke-width="' . $w . '"/>';
    foreach ($parts as $coin => $pt) {
        $len = $total > 0 ? $pt['usd'] / $total * $c : 0;
        $vis = max(0.0, $len - $gap);
        if ($vis > 0) {
            $col = coins()[$coin]['color'] ?? '#888';
            $s .= '<circle class="seg" data-c="' . h($coin) . '" cx="100" cy="100" r="' . $r . '" fill="none" stroke="' . h($col) . '" stroke-width="' . $w . '"'
                . ' stroke-dasharray="' . round($vis, 2) . ' ' . round($c - $vis, 2) . '" stroke-dashoffset="' . round(-$off, 2) . '" transform="rotate(-90 100 100)"><title>'
                . h($coin) . ' · ' . fmt_usd($pt['usd']) . ' · ' . round($pt['usd'] / $total * 100, 1) . '%</title></circle>';
        }
        $off += $len;
    }
    return $s . '</svg>';
}

// ---------- SVG: money in and out bars ----------
function money_short(float $v): string {
    if ($v >= 1e6) return '$' . rtrim(rtrim(number_format($v / 1e6, 1, '.', ''), '0'), '.') . 'M';
    if ($v >= 1e3) return '$' . rtrim(rtrim(number_format($v / 1e3, 1, '.', ''), '0'), '.') . 'K';
    return '$' . round($v);
}
function bars(array $buckets, bool $week): string {
    $W = 760; $H = 230; $L = 46; $B = 26; $T = 10; $n = max(1, count($buckets));
    $max = 0; foreach ($buckets as $b) $max = max($max, $b['in'], $b['out']);
    $nice = $max > 0 ? (function ($m) { $e = 10 ** floor(log10($m)); foreach ([1, 2, 2.5, 5, 10] as $k) if ($k * $e >= $m) return $k * $e; return 10 * $e; })($max) : 100;
    $slot = ($W - $L) / $n; $bw = max(2, min(18, ($slot - 6) / 2));
    $y = fn($v) => $T + ($H - $T - $B) * (1 - $v / $nice);
    $s = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" class="bars" role="img" aria-label="Money in and out by ' . ($week ? 'week' : 'day') . '">';
    for ($i = 0; $i <= 3; $i++) { $v = $nice * $i / 3; $yy = round($y($v), 1);
        $s .= '<line x1="' . $L . '" x2="' . $W . '" y1="' . $yy . '" y2="' . $yy . '" class="grid"/><text x="' . ($L - 8) . '" y="' . ($yy + 4) . '" class="ax" text-anchor="end">' . money_short($v) . '</text>'; }
    $i = 0; $every = max(1, (int)ceil($n / 8));
    foreach ($buckets as $date => $b) {
        $x0 = $L + $i * $slot + ($slot - (2 * $bw + 2)) / 2;
        foreach (['in' => 0, 'out' => 1] as $k => $j) {
            $v = $b[$k]; if ($v <= 0) continue;
            $x = $x0 + $j * ($bw + 2); $top = $y($v); $base = $H - $B; $h = max(2, $base - $top); $r = min(4, $bw / 2, $h);
            $s .= '<path class="bar ' . $k . '" d="M' . round($x, 1) . ' ' . $base . 'V' . round($base - $h + $r, 1) . 'q0 -' . $r . ' ' . $r . ' -' . $r . 'h' . round($bw - 2 * $r, 1) . 'q' . $r . ' 0 ' . $r . ' ' . $r . 'V' . $base . 'Z"/>';
        }
        $label = $week ? 'week of ' . date('M j', strtotime($date)) : date('M j', strtotime($date));
        $s .= '<rect class="hit" x="' . round($L + $i * $slot, 1) . '" y="0" width="' . round($slot, 1) . '" height="' . ($H - $B) . '" data-l="' . h($label) . '" data-in="' . h(fmt_usd($b['in'])) . '" data-out="' . h(fmt_usd($b['out'])) . '"/>';
        if ($i % $every === 0) $s .= '<text x="' . round($L + $i * $slot + $slot / 2, 1) . '" y="' . ($H - 8) . '" class="ax" text-anchor="middle">' . date('M j', strtotime($date)) . '</text>';
        $i++;
    }
    $s .= '<line x1="' . $L . '" x2="' . $W . '" y1="' . ($H - $B) . '" y2="' . ($H - $B) . '" class="base"/>';
    return $s . '</svg>';
}

page_head('Insights', 'app-page');
site_nav();
cab_tabs('stats');
$q = fn($k, $v) => '?' . http_build_query(array_merge(['w' => $sel, 'd' => $days], [$k => $v]));
?>
<main><div class="wrap">
  <div class="page-head rv">
    <div><h1>Insights</h1><p class="sub">Amounts in USD at current coin prices</p></div>
    <div class="filters">
      <div class="seg-ctl"><?php foreach ([7 => '7 days', 30 => '30 days', 90 => '90 days'] as $d => $l): ?><a href="<?= h($q('d', $d)) ?>" class="<?= $d === $days ? 'on' : '' ?>"><?= $l ?></a><?php endforeach; ?></div>
      <form><input type="hidden" name="d" value="<?= $days ?>"><select name="w" onchange="this.form.submit()" aria-label="Wallet">
        <option value="all">All wallets</option><?php foreach ($wallets as $w): ?><option value="<?= $w['id'] ?>" <?= (string)$w['id'] === (string)$sel ? 'selected' : '' ?>><?= h($w['name']) ?></option><?php endforeach; ?></select></form>
    </div>
  </div>

  <div class="tiles rv d1">
    <div class="tile"><small>Money in</small><b><?= fmt_usd($inTotal) ?></b><span class="muted"><?= $cnt['in'] ?> transfers<?= $sum['credit'] > 0 ? ' · credits ' . fmt_usd($sum['credit']) : '' ?></span></div>
    <div class="tile"><small>Money out</small><b><?= fmt_usd($outTotal) ?></b><span class="muted"><?= $cnt['out'] ?> transfers<?= $sum['debit'] > 0 ? ' · withdrawals ' . fmt_usd($sum['debit']) : '' ?></span></div>
    <div class="tile"><small>Net</small><b class="<?= $inTotal - $outTotal >= 0 ? 'up' : 'down' ?>"><?= ($inTotal - $outTotal >= 0 ? '+' : '−') . fmt_usd(abs($inTotal - $outTotal)) ?></b><span class="muted">over <?= $days ?> days</span></div>
    <div class="tile"><small>Swapped</small><b><?= fmt_usd($sum['swap']) ?></b><span class="muted"><?= $cnt['swap'] ?> swaps</span></div>
  </div>

  <div class="stats-grid">
    <div class="box rv d1">
      <h3>Portfolio</h3>
      <?php if ($total <= 0): ?><div class="empty">No coins in <?= $sel === 'all' ? 'your wallets' : 'this wallet' ?> yet</div>
      <?php else: ?>
      <div class="donut-wrap">
        <div class="donut-box"><?= donut($parts, $total) ?><div class="donut-c"><small>Total</small><b><?= fmt_usd($total) ?></b></div></div>
        <div class="legend">
          <?php foreach ($parts as $c => $pt): ?>
          <div class="lg-row" data-c="<?= h($c) ?>"><?= coin_icon($c, 26) ?>
            <div class="n"><b><?= h($c) ?></b><small><?= fmt_amount($pt['units'], coins()[$c]['dec']) ?></small></div>
            <div class="v"><b><?= round($pt['usd'] / $total * 100, 1) ?>%</b><small><?= fmt_usd($pt['usd']) ?></small></div></div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <div class="box rv d2">
      <h3>Money in and out <span class="lg-inline"><i class="k-in"></i>in <i class="k-out"></i>out</span></h3>
      <?php if ($inTotal + $outTotal <= 0): ?><div class="empty">No activity in this period</div>
      <?php else: ?>
      <div class="bars-box"><?= bars($buckets, $week) ?><div class="bar-tip" id="bar-tip"></div></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="stats-grid">
    <div class="box rv">
      <h3>Spending by coin</h3>
      <?php if (!$byCoinOut): ?><div class="empty">No spending in this period</div><?php endif; ?>
      <?php $mx = $byCoinOut ? max($byCoinOut) : 1; foreach ($byCoinOut as $c => $v): ?>
      <div class="hbar"><?= coin_icon($c, 24) ?><span class="n"><?= h($c) ?></span>
        <span class="track"><i style="width:<?= max(2, round($v / $mx * 100, 1)) ?>%;background:<?= h(coins()[$c]['color'] ?? '#888') ?>"></i></span><span class="v mono"><?= fmt_usd($v) ?></span></div>
      <?php endforeach; ?>
    </div>
    <div class="box rv d1">
      <h3>Top recipients</h3>
      <?php if (!$recips): ?><div class="empty">No transfers in this period</div><?php endif; ?>
      <?php foreach ($recips as $a => $r): $l = $labels[$a] ?? null; ?>
      <div class="rcp"><span class="av"><?= h(mb_strtoupper(mb_substr($l['name'] ?? '?', 0, 1))) ?></span>
        <div class="n"><b><?= h($l['name'] ?? short_addr($a)) ?></b><small class="mono"><?= h(short_addr($a)) ?> · <?= $r['n'] ?> transfers</small></div>
        <div class="v mono"><?= fmt_usd($r['usd']) ?><?php if (!$l): ?><a class="add-c" href="contacts.php?add=<?= urlencode($a) ?>">+ add to contacts</a><?php endif; ?></div></div>
      <?php endforeach; ?>
    </div>
  </div>
</div></main>
<script>
(() => {
  const tip = document.getElementById('bar-tip'); if (!tip) return;
  const box = tip.parentElement;
  box.querySelectorAll('.hit').forEach((r) => {
    r.addEventListener('mouseenter', () => {
      tip.innerHTML = `<b>${r.dataset.l}</b><span><i class="k-in"></i>${r.dataset.in}</span><span><i class="k-out"></i>${r.dataset.out}</span>`;
      const br = box.getBoundingClientRect(), rr = r.getBoundingClientRect();
      tip.style.left = Math.min(Math.max(rr.left - br.left + rr.width / 2 - 70, 0), br.width - 140) + 'px'; tip.style.opacity = 1;
      r.classList.add('on');
    });
    r.addEventListener('mouseleave', () => { tip.style.opacity = 0; r.classList.remove('on'); });
  });
  // hovering the legend highlights the donut segment
  document.querySelectorAll('.lg-row').forEach((row) => {
    const seg = document.querySelector(`.seg[data-c="${row.dataset.c}"]`);
    row.onmouseenter = () => { document.querySelector('.donut')?.classList.add('dim'); seg?.classList.add('hl'); };
    row.onmouseleave = () => { document.querySelector('.donut')?.classList.remove('dim'); seg?.classList.remove('hl'); };
  });
})();
</script>
<?php page_foot(); ?>
