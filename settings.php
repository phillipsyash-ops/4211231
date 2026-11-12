<?php
// Profile settings: email, password, display, security
require __DIR__ . '/inc/core.php';
$u = require_user();
$uid = (int)$u['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['act'] ?? '';
    $pw = (string)($_POST['password'] ?? '');
    $anchor = '';

    if ($act === 'email') {
        $anchor = '#profile';
        $email = strtolower(trim($_POST['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) flash('Enter a valid email.', 'err');
        elseif ($email === $u['email']) flash('That’s already your email.', 'err');
        elseif ($e = check_password($u, $pw)) flash($e, 'err');
        elseif (q('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$email, $uid])->fetch()) flash('This email is already used by another wallet.', 'err');
        else { q('UPDATE users SET email = ? WHERE id = ?', [$email, $uid]); flash('Email updated.'); }
    }
    elseif ($act === 'password') {
        $anchor = '#password';
        $new = (string)($_POST['new'] ?? '');
        if (strlen($new) < 8) flash('The new password must be at least 8 characters.', 'err');
        elseif ($new !== ($_POST['new2'] ?? '')) flash('The new passwords don’t match.', 'err');
        elseif ($e = check_password($u, $pw)) flash($e, 'err');
        else {
            // new password + log out on other devices; the stored phrase is re-encrypted with the new password
            $seed = seed_decrypt($u['seed_enc'] ?? null, $pw);
            q('UPDATE users SET pass_hash = ?, seed_enc = ?, session_ver = session_ver + 1 WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $seed ? seed_encrypt($seed, $new) : null, $uid]);
            $_SESSION['sv'] = (int)q('SELECT session_ver FROM users WHERE id = ?', [$uid])->fetchColumn();
            flash('Password changed. Other devices will need to log in again.');
        }
    }
    elseif ($act === 'display') {
        $anchor = '#display';
        q('UPDATE users SET hide_zero = ? WHERE id = ?', [empty($_POST['hide_zero']) ? 0 : 1, $uid]);
        flash('Display settings saved.');
    }
    elseif ($act === 'reveal') {
        if ($e = check_password($u, $pw)) flash($e, 'err');
        elseif ($e = check_totp($u, (string)($_POST['code'] ?? ''))) flash($e, 'err');
        elseif (!($seed = seed_decrypt($u['seed_enc'] ?? null, $pw))) flash('The phrase isn’t available yet — log in once with your recovery phrase to enable this.', 'err');
        else $revealed = explode(' ', $seed);
        if (isset($revealed)) goto render;
        $anchor = '#seed';
    }
    elseif ($act === 'tfa_off') {
        $anchor = '#twofa';
        if ($e = check_password($u, $pw)) flash($e, 'err');
        elseif ($e = check_totp($u, (string)($_POST['code'] ?? ''))) flash($e, 'err');
        else { q('UPDATE users SET totp_secret = NULL, totp_last = NULL WHERE id = ?', [$uid]); flash('Two‑factor authentication is off.'); }
    }
    elseif ($act === 'logout_all') {
        $anchor = '#security';
        if ($e = check_password($u, $pw)) flash($e, 'err');
        else {
            q('UPDATE users SET session_ver = session_ver + 1 WHERE id = ?', [$uid]);
            q('DELETE FROM api_tokens WHERE user_id = ?', [$uid]);
            $_SESSION['sv'] = (int)q('SELECT session_ver FROM users WHERE id = ?', [$uid])->fetchColumn();
            flash('Done: all other sessions and extensions are disconnected.');
        }
    }
    redirect('settings.php' . $anchor);
}
render:
header('Cache-Control: no-store');
$revealed = $revealed ?? null;

$u = current_user();
$tfa = !empty($u['totp_secret']);
$ext = q('SELECT label, created_at, last_used FROM api_tokens WHERE user_id = ? ORDER BY id DESC', [$uid])->fetchAll();
$nW = count(user_wallets($uid));
$nC = (int)q('SELECT COUNT(*) FROM contacts WHERE user_id = ?', [$uid])->fetchColumn();

page_head('Settings', 'app-page');
site_nav();
cab_tabs('settings');
?>
<main><div class="wrap page-narrow">
  <div class="page-head rv"><div><h1>Settings</h1><p class="sub">Account <?= h($u['email']) ?> · since <?= h(fmt_date($u['created_at'], false)) ?> · wallets: <?= $nW ?> · contacts: <?= $nC ?></p></div></div>

  <section class="box set-sec rv" id="profile">
    <div class="set-h"><h3>Email</h3><p class="muted">Used to log in and, together with your recovery phrase, to reset the password</p></div>
    <form method="post" class="set-form"><?= csrf_field() ?><input type="hidden" name="act" value="email">
      <div class="row2">
        <div class="field"><label>New email</label><input type="email" name="email" required value="<?= h($u['email']) ?>"></div>
        <div class="field"><label>Current password</label><input type="password" name="password" required autocomplete="current-password"></div>
      </div>
      <button class="btn">Save email</button></form>
  </section>

  <section class="box set-sec rv" id="password">
    <div class="set-h"><h3>Password</h3><p class="muted">Changing it logs out your other devices</p></div>
    <form method="post" class="set-form"><?= csrf_field() ?><input type="hidden" name="act" value="password">
      <div class="field"><label>Current password</label><input type="password" name="password" required autocomplete="current-password"></div>
      <div class="row2">
        <div class="field"><label>New password</label><input type="password" name="new" required minlength="8" data-pw="#pwm" autocomplete="new-password"><div class="pw-meter"><i id="pwm"></i></div></div>
        <div class="field"><label>Repeat new password</label><input type="password" name="new2" required minlength="8" autocomplete="new-password"></div>
      </div>
      <button class="btn">Change password</button></form>
  </section>

  <section class="box set-sec rv" id="display">
    <div class="set-h"><h3>Display</h3></div>
    <form method="post" class="set-form" id="disp-form"><?= csrf_field() ?><input type="hidden" name="act" value="display">
      <label class="set-row"><span><b>Hide coins with a zero balance</b><small class="muted">Your asset list will show only the coins you hold</small></span>
        <span class="sw"><input type="checkbox" name="hide_zero" value="1" <?= !empty($u['hide_zero']) ? 'checked' : '' ?> onchange="this.form.submit()"><span></span></span></label>
    </form>
    <div class="set-row"><span><b>Theme</b><small class="muted">Remembered in this browser</small></span>
      <div class="seg-ctl" id="theme-ctl"><button type="button" data-t="light">Light</button><button type="button" data-t="dark">Dark</button></div></div>
  </section>

  <section class="box set-sec rv" id="security">
    <div class="set-h"><h3>Security</h3></div>
    <div class="set-row" id="twofa"><span><b>Two‑factor authentication <?= $tfa ? '<span class="badge okb">on</span>' : '<span class="badge">off</span>' ?></b><small class="muted"><?= $tfa ? 'Logging in needs a 6‑digit code from your authenticator app (site and extension).' : 'Add a 6‑digit code from an app on your phone to every login — Google Authenticator, Microsoft Authenticator, Authy.' ?></small></span>
      <?php if (!$tfa): ?><a class="btn btn-p btn-sm" href="twofa.php">Set up</a><?php endif; ?></div>
    <?php if ($tfa): ?>
    <form method="post" class="set-form" autocomplete="off" style="margin:-4px 0 14px"><?= csrf_field() ?><input type="hidden" name="act" value="tfa_off">
      <div class="inline"><input type="password" name="password" required placeholder="Current password" autocomplete="current-password" style="max-width:220px"><input type="text" name="code" required inputmode="numeric" maxlength="7" placeholder="Code" autocomplete="one-time-code" style="max-width:120px"><button class="btn danger">Turn off</button></div>
    </form>
    <?php endif; ?>
    <div class="set-row"><span><b>Password confirmation for transfers</b><small class="muted">Sending coins needs your password — on the site and in the extension</small></span><span class="badge okb">on</span></div>
    <div class="set-row" id="seed"><span><b>Recovery phrase</b><small class="muted">Stored encrypted with your password — only your password can unlock it. Never share it with anyone, including support.</small></span></div>
    <?php if ($revealed): ?>
      <div class="seed-view" data-autohide="60">
        <div class="seed blur">
          <?php foreach ($revealed as $i => $w): ?><div class="w"><i><?= $i + 1 ?></i><?= h($w) ?></div><?php endforeach; ?>
          <div class="seed-cover"><span style="font-size:28px">👁</span>Click to reveal</div>
        </div>
        <div class="inline"><button type="button" class="btn btn-sm" data-copy="<?= h(implode(' ', $revealed)) ?>">Copy phrase</button>
          <a href="settings.php#seed" class="btn btn-sm">Hide</a><span class="muted seed-timer"></span></div>
      </div>
    <?php elseif (empty($u['seed_enc'])): ?>
      <p class="muted" style="margin:6px 0 14px">To view it here, log out and log in once with your recovery phrase — after that it can be shown with your password.</p>
    <?php else: ?>
      <form method="post" class="set-form" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="act" value="reveal">
        <div class="inline"><input type="password" name="password" required placeholder="Current password" autocomplete="current-password" style="max-width:240px"><?php if ($tfa): ?><input type="text" name="code" required inputmode="numeric" maxlength="7" placeholder="2FA code" autocomplete="one-time-code" style="max-width:130px"><?php endif; ?><button class="btn">Show recovery phrase</button></div>
      </form>
    <?php endif; ?>
    <div class="set-row"><span><b>Browser extension</b><small class="muted"><?php if (!$ext): ?>Not connected<?php else: foreach ($ext as $e): ?>Connected <?= h(fmt_date($e['created_at'], false)) ?><?= $e['last_used'] ? ', last active ' . h(fmt_date($e['last_used'])) : '' ?><br><?php endforeach; endif; ?></small></span></div>
    <form method="post" class="set-form logout-all"><?= csrf_field() ?><input type="hidden" name="act" value="logout_all">
      <div class="set-row" style="border:0;padding-bottom:0"><span><b>Log out on all other devices</b><small class="muted">Disconnects every other session and extension. This device stays logged in.</small></span></div>
      <div class="inline"><input type="password" name="password" required placeholder="Current password" autocomplete="current-password" style="max-width:240px"><button class="btn danger">Log out everywhere</button></div>
    </form>
  </section>
</div></main>
<script>
(() => {
  const ctl = document.getElementById('theme-ctl'), cur = () => document.documentElement.dataset.theme === 'dark' ? 'dark' : 'light';
  const paint = () => ctl.querySelectorAll('button').forEach((b) => b.classList.toggle('on', b.dataset.t === cur()));
  ctl.querySelectorAll('button').forEach((b) => b.onclick = () => { if (b.dataset.t !== cur()) document.querySelector('[data-theme-toggle]')?.click(); setTimeout(paint, 30); });
  paint();
  // hide the revealed phrase automatically
  const sv = document.querySelector('.seed-view');
  if (sv) {
    sv.scrollIntoView({ block: 'center' });
    history.replaceState(null, '', 'settings.php#seed');
    let left = +sv.dataset.autohide; const tm = sv.querySelector('.seed-timer');
    const tick = () => { tm.textContent = 'Hides in ' + left + 's'; if (left-- <= 0) { sv.innerHTML = '<p class="muted">Hidden. Enter your password again to view it.</p>'; return; } setTimeout(tick, 1000); };
    tick();
  }
})();
</script>
<?php page_foot(); ?>
