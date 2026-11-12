<?php
// Contacts: labelled addresses of other ledger members
require __DIR__ . '/inc/core.php';
$u = require_user();
$uid = (int)$u['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['act'] ?? '';
    $name = mb_substr(trim($_POST['name'] ?? ''), 0, 60);
    $note = mb_substr(trim($_POST['note'] ?? ''), 0, 140) ?: null;
    if ($act === 'add') {
        $addr = trim($_POST['address'] ?? '');
        $own = q('SELECT a.coin, w.user_id FROM addresses a JOIN wallets w ON w.id = a.wallet_id WHERE a.address = ?', [$addr])->fetch();
        if ($name === '')                         flash('Enter a contact name.', 'err');
        elseif (!$own)                            flash('This address isn’t in the ledger. Make sure you copied all of it.', 'err');
        elseif ((int)$own['user_id'] === $uid)    flash('That’s an address of your own wallet.', 'err');
        elseif (q('SELECT 1 FROM contacts WHERE user_id = ? AND address = ?', [$uid, $addr])->fetch()) flash('This address is already in your contacts.', 'err');
        else {
            q('INSERT INTO contacts (user_id, name, address, coin, note) VALUES (?,?,?,?,?)', [$uid, $name, $addr, $own['coin'], $note]);
            flash('Contact “' . $name . '” saved (' . $own['coin'] . '‑x).');
        }
    }
    elseif ($act === 'edit' && $name !== '') {
        q('UPDATE contacts SET name = ?, note = ? WHERE id = ? AND user_id = ?', [$name, $note, (int)$_POST['id'], $uid]);
        flash('Contact updated.');
    }
    elseif ($act === 'delete') {
        q('DELETE FROM contacts WHERE id = ? AND user_id = ?', [(int)$_POST['id'], $uid]);
        flash('Contact deleted.');
    }
    redirect('contacts.php');
}

$list = user_contacts($uid);
// group addresses by name: one person can have several addresses (one per coin)
$groups = [];
foreach ($list as $c) $groups[mb_strtolower($c['name'])][] = $c;
$prefill = trim((string)($_GET['add'] ?? ''));

page_head('Contacts', 'app-page');
site_nav();
cab_tabs('contacts');
?>
<main><div class="wrap">
  <div class="page-head rv">
    <div><h1>Contacts</h1><p class="sub">Label your friends’ addresses — transfers and history will show a name instead of a string of characters</p></div>
    <button class="btn btn-p" data-open="m-add">+ Add contact</button>
  </div>

  <?php if (!$list): ?>
    <div class="box empty-big rv">
      <div class="empty-ic">👤</div>
      <h3>No contacts yet</h3>
      <p class="muted">Ask a friend to copy their address from “Receive” and add it here. Or click “+ add to contacts” next to a transfer in your history.</p>
      <button class="btn btn-p" data-open="m-add">Add your first contact</button>
    </div>
  <?php else: ?>
    <div class="field rv" style="max-width:360px"><input type="text" id="c-search" placeholder="Search by name or address"></div>
    <div class="contacts rv d1">
    <?php foreach ($groups as $g): $first = $g[0]; ?>
      <div class="box ccard" data-s="<?= h(mb_strtolower(implode(' ', array_map(fn($c) => $c['name'] . ' ' . $c['address'] . ' ' . ($c['note'] ?? ''), $g)))) ?>">
        <div class="ccard-h"><span class="av big"><?= h(mb_strtoupper(mb_substr($first['name'], 0, 1))) ?></span>
          <div><b><?= h($first['name']) ?></b><small class="muted"><?= count($g) ?> <?= count($g) === 1 ? 'address' : 'addresses' ?></small></div></div>
        <?php foreach ($g as $c): ?>
        <div class="caddr"><?= coin_icon($c['coin'], 28) ?>
          <div class="n"><span class="mono"><?= h(short_addr($c['address'])) ?></span><?php if ($c['note']): ?><small><?= h($c['note']) ?></small><?php endif; ?></div>
          <div class="acts-sm">
            <a class="btn btn-sm btn-p" href="wallet.php?send=<?= urlencode($c['address']) ?>">Send</a>
            <button class="btn btn-sm" data-copy="<?= h($c['address']) ?>" title="Copy address">⧉</button>
            <button class="btn btn-sm" data-open="m-edit" data-id="<?= $c['id'] ?>" data-name="<?= h($c['name']) ?>" data-note="<?= h($c['note'] ?? '') ?>" title="Edit">✎</button>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div></main>

<div class="modal" id="m-add"><div class="panel">
  <button class="x" data-close>×</button>
  <h1>New contact</h1><p class="sub">The coin is detected from the address</p>
  <form method="post" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="act" value="add">
    <div class="field"><label>Name</label><input type="text" name="name" maxlength="60" required placeholder="e.g. Andrew" list="c-names">
      <datalist id="c-names"><?php foreach ($groups as $g): ?><option value="<?= h($g[0]['name']) ?>"><?php endforeach; ?></datalist>
      <small class="muted hint">Use the same name to add another address to a person</small></div>
    <div class="field"><label>Address <span id="c-chk" class="mono" style="float:right"></span></label><input type="text" name="address" id="c-addr" required spellcheck="false" value="<?= h($prefill) ?>" placeholder="Paste an address"></div>
    <div class="field"><label>Note (optional)</label><input type="text" name="note" maxlength="140" placeholder="e.g. lunch money"></div>
    <button class="btn btn-p btn-block btn-lg">Save</button>
  </form>
</div></div>

<div class="modal" id="m-edit"><div class="panel">
  <button class="x" data-close>×</button>
  <h1>Edit contact</h1>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="edit"><input type="hidden" name="id" id="e-id">
    <div class="field"><label>Name</label><input type="text" name="name" id="e-name" maxlength="60" required></div>
    <div class="field"><label>Note</label><input type="text" name="note" id="e-note" maxlength="140"></div>
    <button class="btn btn-p btn-block btn-lg">Save</button>
  </form>
  <form method="post" id="del-form" style="margin-top:8px"><?= csrf_field() ?><input type="hidden" name="act" value="delete"><input type="hidden" name="id" id="d-id">
    <button type="button" class="btn btn-block danger" id="del-btn">Delete contact</button>
    <button class="btn btn-block danger" id="del-yes" hidden>Delete for sure? Click again</button></form>
</div></div>

<script>
(() => {
  const s = document.getElementById('c-search');
  if (s) s.oninput = () => { const v = s.value.trim().toLowerCase(); document.querySelectorAll('.ccard').forEach((c) => c.hidden = v && !c.dataset.s.includes(v)); };
  document.getElementById('m-edit').addEventListener('opened', (e) => {
    const d = e.detail; document.getElementById('e-id').value = d.id; document.getElementById('d-id').value = d.id;
    document.getElementById('e-name').value = d.name; document.getElementById('e-note').value = d.note;
    document.getElementById('del-btn').hidden = false; document.getElementById('del-yes').hidden = true;
  });
  document.getElementById('del-btn').onclick = () => { document.getElementById('del-btn').hidden = true; document.getElementById('del-yes').hidden = false; };
  const a = document.getElementById('c-addr'), chk = document.getElementById('c-chk'); let tm;
  const check = () => { clearTimeout(tm); chk.textContent = ''; const v = a.value.trim(); if (v.length < 20) return;
    tm = setTimeout(() => fetch('api.php?addr=' + encodeURIComponent(v)).then((r) => r.json()).then((d) => {
      chk.textContent = d.ok ? '✓ ' + d.coin + '‑x' : 'not in the ledger'; chk.className = 'mono ' + (d.ok ? 'up' : 'down'); }), 300); };
  a.oninput = check;
  if (a.value) { document.getElementById('m-add').classList.add('open'); check(); }
})();
</script>
<?php page_foot(); ?>
