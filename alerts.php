<?php
// Price alerts: notify when a coin goes above / below a price (on the site and in the extension)
require __DIR__ . '/inc/core.php';
$u = require_user();
$uid = (int)$u['id'];
$p = prices();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['act'] ?? '';
    if ($act === 'add') {
        $coin = (string)($_POST['coin'] ?? '');
        $dir = ($_POST['dir'] ?? '') === 'below' ? 'below' : 'above';
        $raw = str_replace([' ', '$', "\u{a0}"], '', (string)($_POST['price'] ?? ''));
        // "3,400" and "3,400.5" are thousands, "0,25" is a decimal comma
        $raw = (str_contains($raw, '.') || preg_match('~^\d{1,3}(,\d{3})+$~', $raw)) ? str_replace(',', '', $raw) : str_replace(',', '.', $raw);
        $price = (float)$raw;
        $now = (float)($p[$coin]['usd'] ?? 0);
        $active = (int)q('SELECT COUNT(*) FROM price_alerts WHERE user_id = ? AND fired_at IS NULL', [$uid])->fetchColumn();
        if (!isset(coins()[$coin]))       flash('Choose a coin.', 'err');
        elseif ($price <= 0 || $price > 1e12) flash('Enter a price greater than zero.', 'err');
        elseif ($active >= MAX_ALERTS)    flash('You can have up to ' . MAX_ALERTS . ' active alerts. Delete one first.', 'err');
        elseif ($now > 0 && ($dir === 'above' ? $now >= $price : $now <= $price))
            flash($coin . '‑x is already ' . ($dir === 'above' ? 'above' : 'below') . ' ' . fmt_price($price) . ' (now ' . fmt_price($now) . '). Pick another price.', 'err');
        else {
            q('INSERT INTO price_alerts (user_id, coin, dir, price) VALUES (?,?,?,?)', [$uid, $coin, $dir, $price]);
            flash('Alert set: you’ll be notified when ' . $coin . '‑x goes ' . $dir . ' ' . fmt_price($price) . '.');
        }
    }
    elseif ($act === 'del') {
        q('DELETE FROM price_alerts WHERE id = ? AND user_id = ?', [(int)($_POST['id'] ?? 0), $uid]);
        flash('Alert deleted.');
    }
    elseif ($act === 'clear') {
        q('DELETE FROM price_alerts WHERE user_id = ? AND fired_at IS NOT NULL', [$uid]);
        flash('History cleared.');
    }
    redirect('alerts.php');
}

// opening this page marks fired alerts as seen
$active = q('SELECT * FROM price_alerts WHERE user_id = ? AND fired_at IS NULL ORDER BY coin, price', [$uid])->fetchAll();
$fired  = fired_alerts($uid, false, 30);
q('UPDATE price_alerts SET seen = 1 WHERE user_id = ? AND fired_at IS NOT NULL AND seen = 0', [$uid]);
$pre = isset(coins()[$_GET['coin'] ?? '']) ? $_GET['coin'] : (isset(coins()['BTC']) ? 'BTC' : array_key_first(coins()));

page_head('Price alerts', 'app-page');
site_nav();
cab_tabs('alerts');
?>
<main><div class="wrap">
  <div class="page-head rv"><div><h1>Price alerts</h1><p class="sub">Get notified when a coin reaches your price — here in the wallet and in the browser extension</p></div></div>

  <div class="al-grid">
    <section class="box rv">
      <h3>New alert</h3>
      <form method="post" class="al-form" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="act" value="add">
        <div class="field"><label>Coin</label>
          <select name="coin" id="al-coin">
            <?php foreach (coins() as $s => $c): ?><option value="<?= h($s) ?>" data-p="<?= h((string)($p[$s]['usd'] ?? 0)) ?>" <?= $s === $pre ? 'selected' : '' ?>><?= h($s) ?>‑x · <?= h($c['name']) ?></option><?php endforeach; ?>
          </select></div>
        <div class="field"><label>When the price</label>
          <div class="seg-ctl al-dir"><label><input type="radio" name="dir" value="above" checked><span>rises above</span></label><label><input type="radio" name="dir" value="below"><span>falls below</span></label></div></div>
        <div class="field"><label>Price, USD <span class="muted" style="float:right">now <b id="al-now" class="mono"></b></span></label>
          <input type="text" name="price" id="al-price" required inputmode="decimal" placeholder="0.00">
          <div class="al-quick" id="al-quick"></div>
          <small class="muted" id="al-diff"></small></div>
        <button class="btn btn-p btn-block btn-lg">Create alert</button>
      </form>
    </section>

    <section class="box rv d1">
      <h3>Active <span class="muted" style="font-weight:400;font-size:13px"><?= count($active) ?> / <?= MAX_ALERTS ?></span></h3>
      <?php if (!$active): ?><div class="empty">No active alerts yet.</div><?php endif; ?>
      <?php foreach ($active as $a): $now = (float)($p[$a['coin']]['usd'] ?? 0); $d = $now > 0 ? ((float)$a['price'] / $now - 1) * 100 : 0; ?>
      <div class="al-row">
        <?= coin_icon($a['coin'], 34) ?>
        <div class="t"><b><?= h($a['coin']) ?>‑x <?= $a['dir'] === 'above' ? '↑ above' : '↓ below' ?> <?= h(fmt_price((float)$a['price'])) ?></b>
          <small>now <span data-price="<?= h($a['coin']) ?>"><?= h(fmt_price($now)) ?></span> · <?= ($d >= 0 ? '+' : '') . number_format($d, 2) ?>% to go · set <?= h(fmt_date($a['created_at'], false)) ?></small></div>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="del"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button class="rename" title="Delete">delete</button></form>
      </div>
      <?php endforeach; ?>

      <?php if ($fired): ?>
      <div class="al-hist-h"><h3>Triggered</h3>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="clear"><button class="rename">clear</button></form></div>
      <?php foreach ($fired as $a): ?>
      <div class="al-row done">
        <?= coin_icon($a['coin'], 34) ?>
        <div class="t"><b>🔔 <?= h(alert_text($a)) ?></b><small>reached <?= h(fmt_price((float)$a['fired_price'])) ?> · <?= h(fmt_date($a['fired_at'])) ?></small></div>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="del"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button class="rename" title="Delete">×</button></form>
      </div>
      <?php endforeach; endif; ?>
    </section>
  </div>
  <p class="muted" style="font-size:12px;margin-top:16px">Prices are checked as they update (about every 10 seconds while the site or extension is in use). Each alert fires once.</p>
</div></main>
<script>
(() => {
  const sel = document.getElementById('al-coin'), inp = document.getElementById('al-price'), now = document.getElementById('al-now'),
        quick = document.getElementById('al-quick'), diff = document.getElementById('al-diff');
  const dirOf = () => document.querySelector('input[name=dir]:checked').value;
  const fmt = (v) => v >= 1000 ? Math.round(v).toString() : v >= 1 ? v.toFixed(2) : v >= 0.01 ? v.toFixed(4) : v.toFixed(6);
  const money = (v) => '$' + Number(v).toLocaleString('en-US', { maximumFractionDigits: v >= 1000 ? 0 : v >= 1 ? 2 : 6 });
  const cur = () => +sel.selectedOptions[0].dataset.p || 0;
  const upd = () => {
    const p = cur(), v = parseFloat(inp.value.replace(',', '.'));
    now.textContent = money(p);
    if (!p || !v) { diff.textContent = ''; return; }
    const d = (v / p - 1) * 100;
    diff.textContent = (d >= 0 ? '+' : '') + d.toFixed(2) + '% from the current price';
    diff.className = (dirOf() === 'above' ? d <= 0 : d >= 0) ? 'down' : 'muted';
    if (diff.className === 'down') diff.textContent += ' — already reached';
  };
  const chips = () => {
    const sign = dirOf() === 'above' ? 1 : -1;
    quick.innerHTML = [1, 3, 5, 10].map((k) => `<button type="button" data-k="${k}">${sign > 0 ? '+' : '−'}${k}%</button>`).join('');
  };
  quick.onclick = (e) => { const b = e.target.closest('button'); if (!b) return; inp.value = fmt(cur() * (1 + (dirOf() === 'above' ? 1 : -1) * b.dataset.k / 100)); upd(); };
  sel.onchange = () => { inp.value = ''; upd(); };
  document.querySelectorAll('input[name=dir]').forEach((r) => (r.onchange = () => { chips(); upd(); }));
  inp.oninput = upd;
  chips(); upd();
})();
</script>
<?php page_foot(); ?>
