<?php
require __DIR__ . '/inc/core.php';
$u = require_user();
$uid = (int)$u['id'];

$wallets = user_wallets($uid);
if (!$wallets) { create_wallet($uid, 'Main'); $wallets = user_wallets($uid); }
$byId = array_column($wallets, null, 'id');
$wid = (int)($_GET['w'] ?? $_SESSION['wid'] ?? 0);
if (!isset($byId[$wid])) $wid = (int)$wallets[0]['id'];
$_SESSION['wid'] = $wid;
$self = 'wallet.php?w=' . $wid;

// ---------- actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['act'] ?? '';

    if ($act === 'new_wallet') {
        if (count($wallets) >= MAX_WALLETS) flash('You’ve reached the wallet limit (' . MAX_WALLETS . ').', 'err');
        else {
            $name = trim($_POST['name'] ?? '') ?: 'Wallet ' . (count($wallets) + 1);
            db()->beginTransaction(); $new = create_wallet($uid, $name); db()->commit();
            flash('Wallet “' . $name . '” created, addresses generated.');
            redirect('wallet.php?w=' . $new);
        }
    }
    elseif ($act === 'rename') {
        $name = trim($_POST['name'] ?? '');
        if ($name !== '') { q('UPDATE wallets SET name = ? WHERE id = ? AND user_id = ?', [mb_substr($name, 0, 60), $wid, $uid]); flash('Name updated.'); }
    }
    elseif ($act === 'send') {
        $coin = $_POST['coin'] ?? '';
        $to = trim($_POST['to'] ?? '');
        $amt = parse_amount($_POST['amount'] ?? '');
        $pwErr = check_password($u, (string)($_POST['password'] ?? ''));
        $res = $pwErr ?? transfer($wid, $coin, $to, $amt, (string)($_POST['note'] ?? ''));
        if ($res === true) {
            $msg = 'Sent ' . fmt_amount($amt) . ' ' . $coin . '';
            $cname = mb_substr(trim($_POST['save_name'] ?? ''), 0, 60);
            if (!empty($_POST['save']) && $cname !== '') {
                try { q('INSERT INTO contacts (user_id, name, address, coin) VALUES (?,?,?,?)', [$uid, $cname, $to, $coin]); $msg .= ', recipient saved to contacts'; } catch (Throwable $e) {}
            }
            flash($msg);
        }
        else flash($res, 'err');
    }
    elseif ($act === 'revoke_ext') {
        q('DELETE FROM api_tokens WHERE user_id = ?', [$uid]);
        flash('All connected extensions were disconnected.');
    }
    elseif ($act === 'req_cancel') {
        // a user can cancel their request until it’s reviewed
        $rid = (int)($_POST['rid'] ?? 0);
        db()->beginTransaction();
        $r = q('SELECT * FROM requests WHERE id = ? AND user_id = ? AND status = ?', [$rid, $uid, 'pending'])->fetch();
        if ($r && q('UPDATE requests SET status = ?, decided_at = CURRENT_TIMESTAMP WHERE id = ? AND status = ?', ['cancelled', $rid, 'pending'])->rowCount() === 1) {
            if ($r['type'] === 'withdraw') q('UPDATE balances SET amount = amount + ? WHERE wallet_id = ? AND coin = ?', [$r['amount'], $r['wallet_id'], $r['coin']]);
            db()->commit(); flash('Request #' . $rid . ' cancelled' . ($r['type'] === 'withdraw' ? ', the coins are back in your balance.' : '.'));
        } else { db()->rollBack(); flash('This request can no longer be cancelled.', 'err'); }
        redirect($self . '#requests');
    }
    redirect($self);
}

// ---------- data ----------
$p = prices();
$bal = wallet_balances($wid);
$addr = wallet_addresses($wid);
$total = 0; foreach ($bal as $c => $v) $total += $v / UNIT * ($p[$c]['usd'] ?? 0);

$wTotals = [];
foreach ($wallets as $w) { $s = 0; foreach (wallet_balances((int)$w['id']) as $c => $v) $s += $v / UNIT * ($p[$c]['usd'] ?? 0); $wTotals[$w['id']] = $s; }

$txs = q('SELECT * FROM txs WHERE from_wallet = ? OR to_wallet = ? ORDER BY id DESC LIMIT 60', [$wid, $wid])->fetchAll();
$cur = $byId[$wid];
$reqs = q('SELECT * FROM requests WHERE user_id = ? ORDER BY id DESC LIMIT 30', [$uid])->fetchAll();
$frozen = []; foreach ($reqs as $r) if ($r['status'] === 'pending' && $r['type'] === 'withdraw' && (int)$r['wallet_id'] === $wid) $frozen[$r['coin']] = ($frozen[$r['coin']] ?? 0) + (int)$r['amount'];
$allAddr = []; foreach ($wallets as $w) $allAddr[$w['id']] = wallet_addresses((int)$w['id']);
$labels = address_labels($uid);
$contacts = user_contacts($uid);
$hideZero = !empty($u['hide_zero']);
$shown = $hideZero ? array_filter(coins(), fn($c, $s) => ($bal[$s] ?? 0) > 0 || !empty($frozen[$s]), ARRAY_FILTER_USE_BOTH) : coins();
if (!$shown) $shown = coins();

page_head('Wallet', 'app-page');
site_nav();
cab_tabs('wallet');
?>
<main><div class="wrap app">

<aside class="side">
  <h4>Wallets</h4>
  <?php foreach ($wallets as $w): ?>
  <a href="wallet.php?w=<?= $w['id'] ?>" class="wl <?= $w['id'] == $wid ? 'on' : '' ?>">
    <span class="av"><?= h(mb_strtoupper(mb_substr($w['name'], 0, 1))) ?></span>
    <span><b><?= h($w['name']) ?></b><small><?= fmt_usd($wTotals[$w['id']]) ?></small></span>
  </a>
  <?php endforeach; ?>
  <?php if (count($wallets) < MAX_WALLETS): ?><button class="wl new-w btn" data-open="m-new">+ New wallet</button><?php endif; ?>
  <?php $extN = (int)q('SELECT COUNT(*) FROM api_tokens WHERE user_id = ?', [$uid])->fetchColumn(); ?>
  <div class="ext-card" id="ext-card">
    <div class="ext-head"><img class="ext-ic" src="<?= asset('assets/logo/coin-64.png') ?>" width="30" height="30" alt=""><div><b>Browser extension</b><small><?= $extN ? 'Connected: ' . $extN : 'Your wallet in the browser toolbar' ?></small></div></div>
    <button class="btn btn-sm btn-block" data-open="m-ext" id="ext-btn">Install extension</button>
    <?php if ($extN): ?>
    <form method="post" action="<?= h($self) ?>"><?= csrf_field() ?><input type="hidden" name="act" value="revoke_ext">
      <button class="rename" onclick="return confirm('Disconnect all extensions? You’ll need to log in to them again.')">disconnect all</button></form>
    <?php endif; ?>
  </div>
</aside>

<div>
  <div class="hero-bal rv">
    <div class="lbl"><span class="live-dot"></span><?= h($cur['name']) ?> · total balance
      <button class="rename" data-open="m-rename">✎ rename</button></div>
    <div class="big" data-total><?= fmt_usd($total) ?></div>
    <div class="acts">
      <button class="btn btn-p" data-open="m-send">↑ Send</button>
      <button class="btn" data-open="m-recv">↓ Receive</button>
      <a class="btn" href="swap.php?w=<?= $wid ?>">⇄ Swap</a>
    </div>
  </div>

  <div class="cards2">
    <div class="box rv d1">
      <h3>Assets</h3>
      <?php foreach ($shown as $s => $c): $v = $bal[$s] ?? 0; ?>
      <div class="asset" data-chart="<?= $s ?>">
        <?= coin_icon($s, 40) ?>
        <div class="n"><b><?= h($c['name']) ?></b><small><?= fmt_amount($v, $c['dec']) ?> <?= $s ?></small><?php if (!empty($frozen[$s])): ?><small class="frz">❄ <?= fmt_amount($frozen[$s], $c['dec']) ?> on hold for withdrawal</small><?php endif; ?></div>
        <div class="v"><span data-price="<?= $s ?>" data-units="<?= $v / UNIT ?>"><?= fmt_usd($v / UNIT * ($p[$s]['usd'] ?? 0)) ?></span>
          <small><button class="rename" data-open="m-recv" data-coin="<?= $s ?>">address</button></small></div>
      </div>
      <?php endforeach; ?>
      <?php if (count($shown) < count(coins())): ?><a class="more-link" href="settings.php#display"><?= count(coins()) - count($shown) ?> coins with zero balance hidden · show</a><?php endif; ?>
    </div>

    <div class="box rv d2">
      <h3>Prices <span class="muted mono" style="font-size:12px;font-weight:400">live</span></h3>
      <?php foreach (coins() as $s => $c): $x = $p[$s] ?? ['usd' => 0, 'ch' => 0]; ?>
      <div class="rate" data-chart="<?= $s ?>"><?= coin_icon($s, 28) ?><span class="n"><?= $s ?></span>
        <span class="p" data-price="<?= $s ?>"><?= fmt_usd($x['usd']) ?></span>
        <span class="ch <?= $x['ch'] >= 0 ? 'up' : 'down' ?>" data-ch="<?= $s ?>"><?= ($x['ch'] >= 0 ? '+' : '') . number_format($x['ch'], 2) ?>%</span></div>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if ($reqs): $st = ['pending' => ['pending', 'wait'], 'approved' => ['completed', 'okb'], 'rejected' => ['declined', 'red'], 'cancelled' => ['cancelled', '']]; ?>
  <div class="box rv" id="requests" style="margin-top:22px">
    <h3>Requests to the administrator</h3>
    <?php foreach ($reqs as $r): $dep = $r['type'] === 'deposit'; $c = coins()[$r['coin']] ?? ['dec' => 8]; ?>
    <div class="tx">
      <div class="ico <?= $dep ? 'in' : 'out' ?>"><?= $dep ? '＋' : '−' ?></div>
      <div class="t"><b><?= $dep ? 'Deposit' : 'Withdrawal' ?> · <?= h($r['coin']) ?> <span class="badge <?= $st[$r['status']][1] ?? '' ?>"><?= $st[$r['status']][0] ?? h($r['status']) ?></span></b>
        <small>#<?= $r['id'] ?> · wallet “<?= h($byId[$r['wallet_id']]['name'] ?? '—') ?>”<?= $r['note'] ? ' · “' . h($r['note']) . '”' : '' ?></small>
        <?php if ($r['admin_note']): ?><small class="adm-ans">Administrator’s reply: <?= h($r['admin_note']) ?></small><?php endif; ?></div>
      <div class="a"><?= $dep ? '+' : '−' ?><?= fmt_amount((int)$r['amount'], $c['dec']) ?><small><?= h(fmt_date($r['created_at'])) ?></small>
        <?php if ($r['status'] === 'pending'): ?><form method="post" action="<?= h($self) ?>"><?= csrf_field() ?><input type="hidden" name="act" value="req_cancel"><input type="hidden" name="rid" value="<?= $r['id'] ?>"><button class="rename">cancel</button></form><?php endif; ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="box rv" id="history" style="margin-top:22px">
    <h3>Transaction history</h3>
    <?php if (!$txs): ?><div class="empty">No transactions yet. Share your address to receive coins.</div><?php endif; ?>
    <?php foreach ($txs as $t): $v = tx_view($t, $wid, $labels); $swap = $v['kind'] === 'swap'; ?>
    <div class="tx">
      <div class="ico <?= $swap ? 'swp' : ($v['in'] ? 'in' : 'out') ?>"><?= $swap ? '⇄' : ($v['in'] ? '↓' : '↑') ?></div>
      <div class="t"><b><?= h($v['title']) ?></b>
        <small><?= h($v['peer']) ?><?php if ($v['peerAddr']): ?> <a class="add-c" href="contacts.php?add=<?= urlencode($v['peerAddr']) ?>">+ add to contacts</a><?php endif; ?></small>
        <small>tx <?= substr($t['hash'], 0, 10) ?>…<?= substr($t['hash'], -6) ?><?= (!$swap && $t['note']) ? ' · «' . h($t['note']) . '»' : '' ?></small></div>
      <div class="a <?= $v['in'] ? 'up' : '' ?>"><?php if ($swap): ?><span><?= h($v['amount']) ?></span><span class="up"><?= h($v['amount2']) ?></span><?php else: ?><?= h($v['amount']) ?><?php endif; ?><small><?= h(fmt_date($t['created_at'])) ?></small></div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
</div></main>

<!-- Send -->
<div class="modal" id="m-send"><div class="panel">
  <button class="x" data-close>×</button>
  <h1>Send</h1><p class="sub">A transfer inside the ledger — instant, no fees</p>
  <form method="post" action="<?= h($self) ?>" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="act" value="send">
    <div class="field"><label>Coin</label>
      <select name="coin" id="s-coin">
        <?php foreach (coins() as $s => $c): ?><option value="<?= $s ?>" data-max="<?= h(rtrim(rtrim(number_format(($bal[$s] ?? 0) / UNIT, 8, '.', ''), '0'), '.')) ?>"><?= $s ?> — balance <?= fmt_amount($bal[$s] ?? 0, $c['dec']) ?></option><?php endforeach; ?>
      </select></div>
    <?php if ($contacts): ?>
    <div class="field"><label>From contacts</label>
      <select id="s-contact"><option value="">— choose —</option>
        <?php foreach ($contacts as $ct): ?><option value="<?= h($ct['address']) ?>" data-coin="<?= h($ct['coin']) ?>"><?= h($ct['name']) ?> · <?= h($ct['coin']) ?> · <?= h(short_addr($ct['address'])) ?></option><?php endforeach; ?>
      </select></div>
    <?php endif; ?>
    <div class="field"><label>Recipient address <span id="s-chk" class="mono" style="float:right"></span></label>
      <input type="text" name="to" id="s-to" required spellcheck="false" placeholder="Paste an address"></div>
    <div class="save-c" id="s-save" hidden>
      <label class="check" style="margin-bottom:8px"><input type="checkbox" name="save" value="1" id="s-save-on"> Save the recipient to contacts</label>
      <input type="text" name="save_name" id="s-save-name" maxlength="60" placeholder="Contact name, e.g. “Andrew”" hidden>
    </div>
    <div class="field"><label>Amount <span class="maxl" id="s-max">Max</span></label>
      <input type="text" name="amount" id="s-amt" required inputmode="decimal" placeholder="0.00"></div>
    <div class="field"><label>Note (optional)</label><input type="text" name="note" maxlength="140"></div>
    <div class="field pw-confirm"><label>🔒 Password to confirm</label><input type="password" name="password" required autocomplete="current-password"></div>
    <button class="btn btn-p btn-block btn-lg">Confirm and send</button>
  </form>
</div></div>

<!-- Receive -->
<div class="modal" id="m-recv"><div class="panel">
  <button class="x" data-close>×</button>
  <h1>Receive</h1><p class="sub">Share this address with the sender. It accepts only the selected coin.</p>
  <div class="addr-box">
    <div class="coin-tabs" id="r-tabs">
      <?php foreach ($addr as $s => $a): if (!isset(coins()[$s])) continue; ?><button type="button" data-c="<?= $s ?>" data-a="<?= h($a) ?>"><?= $s ?></button><?php endforeach; ?>
    </div>
    <div class="qr" id="r-qr"></div>
    <div class="addr" id="r-addr"></div>
    <button class="btn btn-p btn-block" data-copy-from="#r-addr" data-copy="">Copy address</button>
  </div>
</div></div>

<!-- Extension -->
<div class="modal" id="m-ext"><div class="panel">
  <button class="x" data-close>×</button>
  <h1>Browser extension</h1>
  <p class="sub">Balance, transfers, prices and alerts for incoming coins — right in your browser toolbar.</p>
  <div id="ext-store" style="display:none">
    <a class="btn btn-p btn-block btn-lg" id="ext-store-link" target="_blank" rel="noopener">Install from the store</a>
    <p class="muted" style="font-size:13px;margin-top:12px">After installing, click the puzzle icon next to the address bar and pin <?= h(SITE_NAME) ?>.</p>
  </div>
  <div id="ext-manual" style="display:none">
    <a class="btn btn-p btn-block btn-lg" href="download/extension.php" data-noload>Download extension</a>
    <ol class="ext-steps">
      <li>Unzip the downloaded file into any folder.</li>
      <li>Open <span class="mono" id="ext-page">chrome://extensions</span> <button class="rename" data-copy-from="#ext-page" data-copy="">copy</button></li>
      <li>Turn on <b>Developer mode</b> in the top right corner.</li>
      <li>Click <b>Load unpacked</b> and choose the extension folder.</li>
      <li>Pin the icon via the puzzle button next to the address bar.</li>
      <li>Open the extension and log in — the site address is already set.</li>
    </ol>
    <p class="muted" style="font-size:12px;margin-top:10px">Don’t delete the extension folder: the browser runs the extension from it.</p>
  </div>
  <div id="ext-unsup" style="display:none" class="note warn">Your browser isn’t supported yet. The extension works in Chrome, Edge, Opera, Brave, Yandex Browser and Vivaldi.</div>
  <div class="note" style="margin:16px 0 0">You can log in to the extension with your recovery phrase, or with “Log in via website” — you’ll then confirm it here.</div>
</div></div>

<!-- New wallet -->
<div class="modal" id="m-new"><div class="panel">
  <button class="x" data-close>×</button>
  <h1>New wallet</h1><p class="sub">It opens with the same recovery phrase. Addresses for all coins are generated automatically.</p>
  <form method="post" action="<?= h($self) ?>"><?= csrf_field() ?><input type="hidden" name="act" value="new_wallet">
    <div class="field"><label>Name</label><input type="text" name="name" maxlength="60" placeholder="e.g. Savings"></div>
    <button class="btn btn-p btn-block btn-lg">Create</button></form>
</div></div>

<!-- Rename -->
<div class="modal" id="m-rename"><div class="panel">
  <button class="x" data-close>×</button>
  <h1>Rename</h1>
  <form method="post" action="<?= h($self) ?>"><?= csrf_field() ?><input type="hidden" name="act" value="rename">
    <div class="field"><label>Name</label><input type="text" name="name" maxlength="60" value="<?= h($cur['name']) ?>"></div>
    <button class="btn btn-p btn-block btn-lg">Save</button></form>
</div></div>

<script src="<?= asset('assets/qrcode.js') ?>"></script>
<script>
(() => {
  // Receive: coin tabs + QR
  const tabs = document.querySelectorAll('#r-tabs button'), qrEl = document.getElementById('r-qr'), aEl = document.getElementById('r-addr');
  const pick = c => tabs.forEach(b => { if (b.dataset.c !== c) return b.classList.remove('on');
    b.classList.add('on'); aEl.textContent = b.dataset.a; qrEl.innerHTML = '';
    try { const q = qrcode(0, 'M'); q.addData(b.dataset.a); q.make(); qrEl.innerHTML = q.createSvgTag({ cellSize: 5, margin: 2 }); } catch (e) {} });
  tabs.forEach(b => b.addEventListener('click', () => pick(b.dataset.c)));
  document.getElementById('m-recv').addEventListener('opened', e => pick(e.detail.coin || tabs[0].dataset.c));

  // Send: address check and Max
  // Extension: detect the browser and where to send the user
  (() => {
    const ua = navigator.userAgent, chromeUrl = <?= json_encode(EXT_CHROME_URL) ?>, edgeUrl = <?= json_encode(EXT_EDGE_URL) ?>;
    const chromium = !!window.chrome && !/Firefox|FxiOS/.test(ua);
    const mobile = /Android|iPhone|iPad|Mobile/.test(ua);
    const isEdge = /Edg\//.test(ua), isOpera = /OPR\//.test(ua), isYa = /YaBrowser/.test(ua);
    const page = isEdge ? 'edge://extensions' : isOpera ? 'opera://extensions' : isYa ? 'browser://extensions' : 'chrome://extensions';
    document.getElementById('ext-page').textContent = page;
    const show = id => document.getElementById(id).style.display = '';
    if (!chromium || mobile) { show('ext-unsup'); return; }
    const url = (isEdge && edgeUrl) || chromeUrl;
    if (url) { document.getElementById('ext-store-link').href = url; show('ext-store'); }
    else show('ext-manual');
  })();

  const sel = document.getElementById('s-coin'), to = document.getElementById('s-to'), chk = document.getElementById('s-chk');
  document.getElementById('s-max').onclick = () => document.getElementById('s-amt').value = sel.selectedOptions[0].dataset.max;
  const KNOWN = <?= json_encode(array_keys($labels)) ?>;
  const save = document.getElementById('s-save'), saveOn = document.getElementById('s-save-on'), saveName = document.getElementById('s-save-name');
  saveOn.onchange = () => { saveName.hidden = !saveOn.checked; if (saveOn.checked) saveName.focus(); };
  let tm;
  const check = () => { clearTimeout(tm); chk.textContent = ''; save.hidden = true; const v = to.value.trim(); if (v.length < 20) return;
    tm = setTimeout(() => fetch('api.php?addr=' + encodeURIComponent(v)).then(r => r.json()).then(d => {
      if (d.ok) { chk.textContent = '✓ ' + d.coin + ''; chk.className = 'mono up'; sel.value = d.coin; save.hidden = KNOWN.includes(v); }
      else { chk.textContent = 'address not found'; chk.className = 'mono down'; } }), 300); };
  to.addEventListener('input', check);
  const pickC = document.getElementById('s-contact');
  if (pickC) pickC.onchange = () => { if (!pickC.value) return; to.value = pickC.value; sel.value = pickC.selectedOptions[0].dataset.coin; check(); };
  // open Send right away: wallet.php?send=ADDRESS (from contacts) or ?open=send
  const qs = new URLSearchParams(location.search);
  if (qs.get('send') || qs.get('open') === 'send') { document.getElementById('m-send').classList.add('open'); if (qs.get('send')) { to.value = qs.get('send'); check(); } }
})();
</script>
<?php page_foot(); ?>
