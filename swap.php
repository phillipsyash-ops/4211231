<?php
// Swap  coins at the current market price of the originals
require __DIR__ . '/inc/core.php';
$u = require_user();
$uid = (int)$u['id'];
[$cur, $wallets] = current_wallet($uid);
$wid = (int)$cur['id'];
$self = 'swap.php?w=' . $wid;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $from = (string)($_POST['from'] ?? ''); $to = (string)($_POST['to'] ?? '');
    $amt = parse_amount((string)($_POST['amount'] ?? ''));
    $seen = (float)($_POST['rate'] ?? 0) ?: null;
    [$out] = $amt && isset(coins()[$from], coins()[$to]) ? swap_quote($from, $to, $amt) : [0];
    $res = swap($wid, $from, $to, $amt, $seen);
    if ($res === true) flash('Swapped ' . fmt_amount($amt) . ' ' . $from . ' → ' . fmt_amount($out, coins()[$to]['dec']) . ' ' . $to . '');
    else flash($res, 'err');
    redirect($self . '&from=' . urlencode($from) . '&to=' . urlencode($to));
}

$p = prices();
$bal = wallet_balances($wid);
$from = isset(coins()[$_GET['from'] ?? '']) ? $_GET['from'] : (array_keys(array_filter($bal))[0] ?? 'USDT');
$to = isset(coins()[$_GET['to'] ?? '']) && ($_GET['to'] !== $from) ? $_GET['to'] : ($from === 'BTC' ? 'USDT' : 'BTC');
$recent = q("SELECT * FROM txs WHERE type = 'swap' AND from_wallet = ? ORDER BY id DESC LIMIT 12", [$wid])->fetchAll();
$coinsJs = []; foreach (coins() as $s => $c) $coinsJs[$s] = ['dec' => $c['dec'], 'bal' => ($bal[$s] ?? 0) / UNIT, 'name' => $c['name']];

page_head('Swap', 'app-page');
site_nav();
cab_tabs('swap');
?>
<main><div class="wrap page-narrow">
  <div class="page-head rv">
    <div><h1>Swap</h1><p class="sub">Coins at the current market price</p></div>
    <form class="wsel-form"><label class="muted" for="wsel">Wallet</label>
      <select id="wsel" name="w" onchange="this.form.submit()"><?php foreach ($wallets as $w): ?><option value="<?= $w['id'] ?>" <?= $w['id'] == $wid ? 'selected' : '' ?>><?= h($w['name']) ?></option><?php endforeach; ?></select></form>
  </div>

  <form method="post" action="<?= h($self) ?>" class="swap-card box rv d1" id="swap" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="rate" id="sw-rate">
    <div class="sw-leg">
      <div class="sw-top"><span class="muted">You pay</span><span class="muted">Balance: <b id="sw-bal" class="mono"></b> <button type="button" class="maxl" id="sw-max">Max</button></span></div>
      <div class="sw-row">
        <input type="text" name="amount" id="sw-amt" inputmode="decimal" placeholder="0" required>
        <label class="sw-coin"><span id="sw-from-ic"></span><select name="from" id="sw-from"><?php foreach (coins() as $s => $c): ?><option value="<?= $s ?>" <?= $s === $from ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select></label>
      </div>
      <div class="sw-usd muted mono" id="sw-usd1">≈ $0.00</div>
    </div>
    <button type="button" class="sw-flip" id="sw-flip" title="Flip" aria-label="Flip the coins">⇅</button>
    <div class="sw-leg">
      <div class="sw-top"><span class="muted">You get</span><span class="muted">Balance: <b id="sw-bal2" class="mono"></b></span></div>
      <div class="sw-row">
        <div class="sw-out mono" id="sw-out">0</div>
        <label class="sw-coin"><span id="sw-to-ic"></span><select name="to" id="sw-to"><?php foreach (coins() as $s => $c): ?><option value="<?= $s ?>" <?= $s === $to ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select></label>
      </div>
      <div class="sw-usd muted mono" id="sw-usd2">≈ $0.00</div>
    </div>
    <div class="sw-rate"><span class="live-dot"></span><span id="sw-rate-txt" class="mono"></span><span class="muted" id="sw-upd">updates every 10 sec</span></div>
    <div class="err" id="sw-err" hidden></div>
    <button class="btn btn-p btn-block btn-lg" id="sw-go">Swap</button>
    <p class="muted sw-note">The rate is taken from the exchange at the moment of the swap. If it moves by more than 1%, the swap stops and asks you to confirm again.</p>
  </form>

  <div class="box rv d2" style="margin-top:20px">
    <h3>Recent swaps</h3>
    <?php if (!$recent): ?><div class="empty">No swaps yet</div><?php endif; ?>
    <?php foreach ($recent as $t): $v = tx_view($t, $wid, []); ?>
    <div class="tx"><div class="ico swp">⇄</div>
      <div class="t"><b><?= h($v['title']) ?></b><small><?= h($t['note'] ?? '') ?></small></div>
      <div class="a"><span><?= h($v['amount']) ?></span><span class="up"><?= h($v['amount2']) ?></span><small><?= h(fmt_date($t['created_at'])) ?></small></div></div>
    <?php endforeach; ?>
  </div>
</div></main>

<template id="ic-tpl"><?php foreach (coins() as $s => $c) echo '<span data-ic="' . $s . '">' . coin_icon($s, 32) . '</span>'; ?></template>
<script>
(() => {
  const C = <?= json_encode($coinsJs, JSON_UNESCAPED_UNICODE) ?>;
  let P = <?= json_encode($p) ?>;
  const $ = (id) => document.getElementById(id);
  const from = $('sw-from'), to = $('sw-to'), amt = $('sw-amt'), tpl = $('ic-tpl').content;
  const usd = (v) => '$' + v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: v < 1 && v > 0 ? 6 : 2 });
  const fmt = (v, d) => v.toLocaleString('en-US', { maximumFractionDigits: d });
  const icon = (s) => tpl.querySelector(`[data-ic="${s}"]`).innerHTML;
  const rate = () => (P[from.value]?.usd || 0) / (P[to.value]?.usd || 1);
  function upd() {
    if (from.value === to.value) { const o = [...to.options].find((x) => x.value !== from.value); to.value = o.value; }
    $('sw-from-ic').innerHTML = icon(from.value); $('sw-to-ic').innerHTML = icon(to.value);
    $('sw-bal').textContent = fmt(C[from.value].bal, C[from.value].dec) + ' ' + from.value + '';
    $('sw-bal2').textContent = fmt(C[to.value].bal, C[to.value].dec) + ' ' + to.value + '';
    const a = parseFloat(amt.value.replace(',', '.').replace(/\s/g, '')) || 0, r = rate(), step = Math.pow(10, C[to.value].dec);
    const out = Math.floor(a * r * step) / step;
    $('sw-out').textContent = fmt(out, C[to.value].dec);
    $('sw-usd1').textContent = '≈ ' + usd(a * (P[from.value]?.usd || 0));
    $('sw-usd2').textContent = '≈ ' + usd(out * (P[to.value]?.usd || 0));
    $('sw-rate-txt').textContent = `1 ${from.value} = ${fmt(r, r >= 100 ? 2 : r >= 1 ? 4 : 8)} ${to.value}`;
    $('sw-rate').value = r;
    const err = a > C[from.value].bal ? `Not enough ${from.value} in this wallet` : '';
    $('sw-err').textContent = err; $('sw-err').hidden = !err; $('sw-go').disabled = !!err || !(a > 0) || out <= 0;
    history.replaceState(null, '', `?w=<?= $wid ?>&from=${from.value}&to=${to.value}`);
  }
  from.onchange = to.onchange = upd; amt.oninput = upd;
  $('sw-max').onclick = () => { amt.value = String(C[from.value].bal); upd(); };
  $('sw-flip').onclick = () => { const f = from.value; from.value = to.value; to.value = f; $('sw-flip').animate([{ transform: 'rotate(0)' }, { transform: 'rotate(180deg)' }], 300); upd(); };
  setInterval(() => fetch('api.php?prices=1').then((r) => r.json()).then((p) => { P = p; upd(); $('sw-rate-txt').animate([{ opacity: .3 }, { opacity: 1 }], 500); }).catch(() => {}), 10000);
  upd();
})();
</script>
<?php page_foot(); ?>
