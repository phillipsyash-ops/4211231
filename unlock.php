<?php
require __DIR__ . '/inc/core.php';
// where to return after login (only the extension connect page)
$next = (string)($_GET['next'] ?? $_POST['next'] ?? '');
if (!preg_match('~^ext_link\.php\?code=[a-f0-9]{32}$~', $next)) $next = '';
if (current_user()) redirect($next ?: 'wallet.php');

$mode = ($_GET['mode'] ?? '') === 'restore' ? 'restore' : 'login';
$err = null;

// finish login: apply what the first step prepared (new password / saved phrase) and open the session
function finish_login(array $u, array $upd, string $mode, string $next) {
    if ($upd) q('UPDATE users SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($upd))) . ' WHERE id = ?', array_merge(array_values($upd), [$u['id']]));
    unset($_SESSION['fails'], $_SESSION['pre2fa']);
    login_user($u);
    flash($mode === 'restore' ? 'Password updated, your wallet is open.' : 'Wallet unlocked.');
    redirect($next ?: 'wallet.php');
}

// step 2: the 6-digit code (only if two-factor authentication is on)
$pre = $_SESSION['pre2fa'] ?? null;
if ($pre && time() - $pre['t'] > 300) { unset($_SESSION['pre2fa']); $pre = null; $err = 'The login took too long. Please start again.'; }
if (isset($_GET['cancel2fa'])) { unset($_SESSION['pre2fa']); redirect('unlock.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['step'] ?? '') === '2fa' && $pre) {
        $u = q('SELECT * FROM users WHERE id = ? AND blocked = 0', [$pre['uid']])->fetch();
        if (!$u) { unset($_SESSION['pre2fa']); redirect('unlock.php'); }
        if (!($err = check_totp($u, (string)($_POST['code'] ?? '')))) finish_login($u, $pre['upd'], $pre['mode'], $pre['next']);
    } else {
    // basic brute-force protection
    $fails = $_SESSION['fails'] ?? 0;
    if ($fails >= 5) sleep(min(10, $fails - 3));

    $norm = normalize_seed($_POST['seed'] ?? '');
    $pw = $_POST['password'] ?? '';
    if (!seed_valid($norm)) $err = 'The phrase must be 12 words from the BIP‑39 word list.';
    else {
        $u = q('SELECT * FROM users WHERE seed_fp = ?', [seed_fp($norm)])->fetch();
        $upd = [];
        if (!$u) $err = 'No wallet found for this recovery phrase.';
        elseif ($u['blocked']) $err = 'This wallet is blocked.';
        elseif ($mode === 'login' && !password_verify($pw, $u['pass_hash'])) $err = 'Wrong password.';
        elseif ($mode === 'restore') {
            if (strlen($pw) < 8) $err = 'The new password must be at least 8 characters.';
            elseif ($pw !== ($_POST['password2'] ?? '')) $err = 'The passwords don’t match.';
            else $upd = ['pass_hash' => password_hash($pw, PASSWORD_DEFAULT), 'seed_enc' => seed_encrypt($norm, $pw)];
        }
        // older accounts: keep an encrypted copy of the phrase so it can be viewed in Settings
        if (!$err && $mode === 'login' && empty($u['seed_enc'])) $upd = ['seed_enc' => seed_encrypt($norm, $pw)];
        if (!$err) {
            unset($_SESSION['fails']);
            if (empty($u['totp_secret'])) finish_login($u, $upd, $mode, $next);
            // two-factor is on: nothing is changed until the code is entered
            $_SESSION['pre2fa'] = ['uid' => (int)$u['id'], 'upd' => $upd, 'mode' => $mode, 'next' => $next, 't' => time()];
            redirect('unlock.php');
        }
    }
    $_SESSION['fails'] = $fails + 1;
    }
}

if ($pre = $_SESSION['pre2fa'] ?? null) {
    page_head('Two‑factor authentication');
    site_nav(); ?>
<main class="auth">
  <div class="panel rv in">
    <div class="tfa-ic">🛡</div>
    <h1>Enter the code</h1>
    <p class="sub">Open your authenticator app and enter the 6‑digit code for <?= h(SITE_NAME) ?></p>
    <?php if ($err): ?><div class="err"><?= h($err) ?></div><?php endif; ?>
    <form method="post" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="step" value="2fa">
      <div class="field"><input type="text" name="code" class="code-in" required inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code" autofocus placeholder="000000"></div>
      <button class="btn btn-p btn-block btn-lg">Confirm</button>
    </form>
    <div class="alt">Lost access to the app? Ask the administrator to turn off two‑factor authentication for your wallet.<br><br><a href="unlock.php?cancel2fa=1">Cancel</a></div>
  </div>
</main>
<script>document.querySelector('.code-in').addEventListener('input', (e) => { const v = e.target.value.replace(/\D/g, ''); if (v.length === 6) e.target.form.requestSubmit ? e.target.form.requestSubmit() : e.target.form.submit(); });</script>
<?php page_foot(); exit; }

page_head($mode === 'restore' ? 'Restore access' : 'Log in');
site_nav();
?>
<main class="auth">
  <div class="panel rv in">
    <h1><?= $mode === 'restore' ? 'Restore access' : 'Unlock your wallet' ?></h1>
    <p class="sub"><?= $mode === 'restore' ? 'Enter your recovery phrase and choose a new password' : 'Enter your 12‑word recovery phrase and password' ?></p>
    <?php if ($err): ?><div class="err"><?= h($err) ?></div><?php endif; ?>
    <form method="post" autocomplete="off">
      <?= csrf_field() ?><input type="hidden" name="next" value="<?= h($next) ?>">
      <?php if ($next): ?><div class="note">Log in to connect the browser extension.</div><?php endif; ?>
      <div class="field"><label>Recovery phrase</label>
        <textarea name="seed" rows="4" required spellcheck="false" autocapitalize="off" placeholder="word1 word2 word3 … word12"></textarea></div>
      <div class="field"><label><?= $mode === 'restore' ? 'New password' : 'Password' ?></label>
        <input type="password" name="password" required <?= $mode === 'restore' ? 'minlength="8" data-pw="#pwm"' : '' ?>>
        <?php if ($mode === 'restore'): ?><div class="pw-meter"><i id="pwm"></i></div><?php endif; ?></div>
      <?php if ($mode === 'restore'): ?>
      <div class="field"><label>Repeat password</label><input type="password" name="password2" required minlength="8"></div>
      <?php endif; ?>
      <button class="btn btn-p btn-block btn-lg"><?= $mode === 'restore' ? 'Restore' : 'Unlock' ?></button>
    </form>
    <div class="alt">
      <?php if ($mode === 'restore'): ?><a href="unlock.php">I remember my password</a>
      <?php else: ?>Forgot your password? <a href="unlock.php?mode=restore">Reset it with your phrase</a><?php endif; ?>
      <br><br>No wallet yet? <a href="create.php">Create one</a>
    </div>
  </div>
</main>
<?php page_foot(); ?>
