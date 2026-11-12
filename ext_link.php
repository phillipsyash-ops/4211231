<?php
// Connect the browser extension via the website: the user confirms it in their account
require __DIR__ . '/inc/core.php';

$code = (string)($_GET['code'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $code)) { http_response_code(400); exit('Invalid link.'); }
$self = 'ext_link.php?code=' . $code;

$u = current_user();
if (!$u) redirect('unlock.php?next=' . urlencode($self));

$h = hash('sha256', $code);
$link = q('SELECT * FROM ext_links WHERE code_hash = ?', [$h])->fetch();
$state = (!$link || $link['created_at'] < time() - 600) ? 'expired' : ($link['token'] ? 'done' : 'ask');

if ($state === 'ask' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['act'] ?? '') === 'approve') {
        $t = 'vx_' . bin2hex(random_bytes(24));
        db()->beginTransaction();
        q('INSERT INTO api_tokens (user_id, token_hash, label) VALUES (?,?,?)', [$u['id'], hash('sha256', $t), 'Chrome']);
        q('UPDATE ext_links SET user_id = ?, token = ? WHERE code_hash = ? AND token IS NULL', [$u['id'], $t, $h]);
        db()->commit();
        $state = 'done';
    } else {
        q('DELETE FROM ext_links WHERE code_hash = ?', [$h]);
        $state = 'cancelled';
    }
}

page_head('Connect extension');
site_nav();
?>
<main class="auth">
  <div class="panel rv in" style="text-align:center">
    <div style="display:flex;justify-content:center;margin-bottom:20px"><?= logo_mark(56) ?></div>
    <?php if ($state === 'ask'): ?>
      <h1>Connect the extension?</h1>
      <p class="sub">The <?= h(SITE_NAME) ?> browser extension will get access to the wallets of <b><?= h($u['email']) ?></b>: balance, history and transfers.</p>
      <div class="note warn" style="text-align:left">Only confirm if you just clicked “Log in via website” in the extension yourself.</div>
      <form method="post"><?= csrf_field() ?>
        <button name="act" value="approve" class="btn btn-p btn-block btn-lg">Connect extension</button>
        <button name="act" value="cancel" class="btn btn-block" style="margin-top:8px">Cancel</button>
      </form>
    <?php elseif ($state === 'done'): ?>
      <h1>Done</h1>
      <p class="sub">The extension is connected. You can close this tab and open the extension — just choose a password for it.</p>
    <?php elseif ($state === 'cancelled'): ?>
      <h1>Cancelled</h1>
      <p class="sub">The extension did not get access to your wallet.</p>
    <?php else: ?>
      <h1>This link has expired</h1>
      <p class="sub">Click “Log in via website” in the extension again.</p>
    <?php endif; ?>
  </div>
</main>
<?php page_foot(); ?>
