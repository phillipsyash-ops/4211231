<?php
// Turn on two-factor authentication (offered right after creating a wallet, and in Settings)
require __DIR__ . '/inc/core.php';
$u = require_user();
$uid = (int)$u['id'];
$welcome = isset($_GET['welcome']) || isset($_POST['welcome']);
$fresh = !empty($_SESSION['fresh']) && time() - $_SESSION['fresh'] < 900;   // just created the wallet

if (!empty($u['totp_secret'])) redirect('settings.php#twofa');
if (isset($_GET['skip'])) { unset($_SESSION['fresh'], $_SESSION['totp_setup']); flash('Your wallet is ready. Welcome! You can turn on two‑factor authentication any time in Settings.'); redirect('wallet.php'); }

if (empty($_SESSION['totp_setup'])) $_SESSION['totp_setup'] = totp_new_secret();
$secret = $_SESSION['totp_setup'];
$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $st = totp_match($secret, (string)($_POST['code'] ?? ''));
    if (!$fresh && ($e = check_password($u, (string)($_POST['password'] ?? '')))) $err = $e;
    elseif ($st === null) $err = 'Wrong code. Scan the QR code again and enter the current 6 digits. The time on your phone must be set automatically.';
    else {
        q('UPDATE users SET totp_secret = ?, totp_last = ? WHERE id = ?', [$secret, $st, $uid]);
        unset($_SESSION['totp_setup'], $_SESSION['fresh']);
        flash($welcome ? 'Your wallet is ready and protected with two‑factor authentication.' : 'Two‑factor authentication is on. You’ll need a code from the app each time you log in.');
        redirect($welcome ? 'wallet.php' : 'settings.php#twofa');
    }
}

page_head('Two‑factor authentication');
site_nav();
?>
<main class="auth">
  <div class="panel wide rv in">
    <?php if ($welcome): ?><div class="progress"><i class="on"></i><i class="on"></i><i class="on"></i><i class="on"></i></div><div class="opt-tag">Optional</div><?php endif; ?>
    <div class="tfa-ic">🛡</div>
    <h1><?= $welcome ? 'Protect your wallet' : 'Turn on two‑factor authentication' ?></h1>
    <p class="sub">After this, logging in will need your recovery phrase, password <b>and</b> a 6‑digit code from an app on your phone. Even with your phrase and password, nobody else can get in.</p>
    <?php if ($err): ?><div class="err"><?= h($err) ?></div><?php endif; ?>

    <div class="tfa-setup">
      <div class="tfa-qr" id="tfa-qr" data-uri="<?= h(totp_uri($secret, $u['email'])) ?>"></div>
      <div>
        <ol class="tfa-steps">
          <li>Install an authenticator app: <b>Google Authenticator</b>, <b>Microsoft Authenticator</b> or <b>Authy</b>.</li>
          <li>Tap <b>+</b> and scan this QR code.</li>
          <li>Enter the 6‑digit code the app shows.</li>
        </ol>
        <div class="tfa-key"><span class="muted" style="font-size:12px">Can’t scan? Enter the key:</span><code id="tfa-key"><?= h(trim(chunk_split($secret, 4, ' '))) ?></code><button type="button" class="btn btn-sm" data-copy="<?= h($secret) ?>">Copy</button></div>
      </div>
    </div>

    <form method="post" autocomplete="off"><?= csrf_field() ?><?php if ($welcome): ?><input type="hidden" name="welcome" value="1"><?php endif; ?>
      <div class="<?= $fresh ? '' : 'row2' ?>">
        <div class="field"><label>Code from the app</label><input type="text" name="code" class="code-in" required inputmode="numeric" maxlength="7" autocomplete="one-time-code" placeholder="000000"></div>
        <?php if (!$fresh): ?><div class="field"><label>Current password</label><input type="password" name="password" required autocomplete="current-password" style="height:58px"></div><?php endif; ?>
      </div>
      <button class="btn btn-p btn-block btn-lg">Turn on</button>
    </form>
    <div class="alt">
      <?php if ($welcome): ?><a href="twofa.php?skip=1">Skip for now</a> — you can turn it on later in Settings
      <?php else: ?><a href="settings.php#twofa">Cancel</a><?php endif; ?>
      <br><br><span class="muted" style="font-size:12px">If you lose your phone, the administrator can turn two‑factor off for your wallet.</span>
    </div>
  </div>
</main>
<script src="<?= asset('assets/qrcode.js') ?>"></script>
<script>
(() => { const el = document.getElementById('tfa-qr'); try { const q = qrcode(0, 'M'); q.addData(el.dataset.uri); q.make(); el.innerHTML = q.createSvgTag({ cellSize: 4, margin: 0 }); } catch (e) {} })();
</script>
<?php page_foot(); ?>
