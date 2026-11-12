<?php
require __DIR__ . '/inc/core.php';
if (current_user()) redirect('wallet.php');

$pend = $_SESSION['pending'] ?? null;
$step = $pend ? ($pend['shown'] ? 3 : 2) : 1;
if (isset($_GET['restart'])) { unset($_SESSION['pending']); redirect('create.php'); }
$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['act'] ?? '';

    if ($act === 'start') {
        $email = strtolower(trim($_POST['email'] ?? ''));
        $pw = $_POST['password'] ?? ''; $pw2 = $_POST['password2'] ?? '';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'Enter a valid email.';
        elseif (strlen($pw) < 8) $err = 'The password must be at least 8 characters.';
        elseif ($pw !== $pw2) $err = 'The passwords don’t match.';
        elseif (empty($_POST['agree'])) $err = 'Please confirm you understand how the recovery phrase works.';
        elseif (q('SELECT 1 FROM users WHERE email = ?', [$email])->fetch()) $err = 'A wallet with this email already exists. Log in with your recovery phrase.';
        else {
            $seed = new_seed();
            $_SESSION['pending'] = ['email' => $email, 'pass' => password_hash($pw, PASSWORD_DEFAULT), 'seed' => $seed,
                                    'enc' => seed_encrypt(implode(' ', $seed), $pw), 'shown' => false];
            redirect('create.php');
        }
    }
    elseif ($act === 'saved' && $pend) {
        if (empty($_POST['saved'])) $err = 'Tick the box to confirm you wrote the phrase down.';
        else {
            $idx = range(0, 11); shuffle($idx); $idx = array_slice($idx, 0, 3); sort($idx);
            $_SESSION['pending']['shown'] = true; $_SESSION['pending']['check'] = $idx;
            redirect('create.php');
        }
    }
    elseif ($act === 'verify' && $pend) {
        $ok = true;
        foreach ($pend['check'] as $i) if (strtolower(trim($_POST['w' . $i] ?? '')) !== $pend['seed'][$i]) $ok = false;
        if (!$ok) $err = 'The words don’t match. Check the phrase you wrote down.';
        else {
            $norm = implode(' ', $pend['seed']);
            db()->beginTransaction();
            try {
                q('INSERT INTO users (email, pass_hash, seed_fp, seed_enc) VALUES (?,?,?,?)', [$pend['email'], $pend['pass'], seed_fp($norm), $pend['enc'] ?? null]);
                $uid = (int)db()->lastInsertId();
                create_wallet($uid, 'Main');
                db()->commit();
            } catch (Throwable $e) { db()->rollBack(); $err = 'Couldn’t create the wallet. Please try again.'; }
            if (!$err) {
                unset($_SESSION['pending']);
                login_user(['id' => $uid, 'session_ver' => 1]);
                $_SESSION['fresh'] = time();   // lets the optional 2FA step skip asking for the password again
                redirect('twofa.php?welcome=1');
            }
        }
    }
    $pend = $_SESSION['pending'] ?? null;
    $step = $pend ? ($pend['shown'] ? 3 : 2) : 1;
}

page_head('Open a wallet');
site_nav();
?>
<main class="auth">
  <div class="panel <?= $step === 2 ? 'wide' : '' ?> rv in">
    <div class="progress"><i class="on"></i><i class="<?= $step >= 2 ? 'on' : '' ?>"></i><i class="<?= $step >= 3 ? 'on' : '' ?>"></i></div>
    <?php if ($err): ?><div class="err"><?= h($err) ?></div><?php endif; ?>

    <?php if ($step === 1): ?>
      <h1>New wallet</h1>
      <p class="sub">Step 1 of 3 — email and password</p>
      <form method="post" autocomplete="off">
        <?= csrf_field() ?><input type="hidden" name="act" value="start">
        <div class="field"><label>Email</label><input type="email" name="email" required value="<?= h($_POST['email'] ?? '') ?>" placeholder="you@mail.com"></div>
        <div class="field"><label>Password</label><input type="password" name="password" required minlength="8" data-pw="#pwm" placeholder="At least 8 characters"><div class="pw-meter"><i id="pwm"></i></div></div>
        <div class="field"><label>Repeat password</label><input type="password" name="password2" required minlength="8"></div>
        <label class="check"><input type="checkbox" name="agree" value="1"> I understand that the recovery phrase is the only way into my wallet. If I lose it, access can’t be restored.</label>
        <button class="btn btn-p btn-block btn-lg">Create my recovery phrase →</button>
      </form>
      <div class="alt">Already have a wallet? <a href="unlock.php">Log in with your phrase</a></div>

    <?php elseif ($step === 2): ?>
      <h1>Your recovery phrase</h1>
      <p class="sub">Step 2 of 3 — write down the 12 words in order</p>
      <div class="note warn">Never show this phrase to anyone. <?= h(SITE_NAME) ?> staff will never ask for it. Keep it on paper, not in a screenshot.</div>
      <div class="seed blur">
        <?php foreach ($pend['seed'] as $i => $w): ?><div class="w" style="animation-delay:<?= $i * 45 ?>ms"><i><?= $i + 1 ?></i><?= h($w) ?></div><?php endforeach; ?>
        <div class="seed-cover"><span style="font-size:28px">👁</span>Click to reveal</div>
      </div>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="act" value="saved">
        <div style="margin-bottom:16px"><button type="button" class="btn btn-sm" data-copy="<?= h(implode(' ', $pend['seed'])) ?>">Copy phrase</button></div>
        <label class="check"><input type="checkbox" name="saved" value="1"> I wrote the phrase down and keep it somewhere safe</label>
        <button class="btn btn-p btn-block btn-lg">Continue →</button>
      </form>
      <div class="alt"><a href="create.php?restart=1">Start over</a></div>

    <?php else: ?>
      <h1>Check your phrase</h1>
      <p class="sub">Step 3 of 3 — enter the words by their numbers</p>
      <form method="post" autocomplete="off">
        <?= csrf_field() ?><input type="hidden" name="act" value="verify">
        <div class="verify">
          <?php foreach ($pend['check'] as $i): ?>
          <div><label>Word #<?= $i + 1 ?></label><input type="text" name="w<?= $i ?>" required autocapitalize="off" spellcheck="false"></div>
          <?php endforeach; ?>
        </div>
        <button class="btn btn-p btn-block btn-lg">Open my wallet</button>
      </form>
      <div class="alt"><a href="create.php?restart=1">Start over</a></div>
    <?php endif; ?>
  </div>
</main>
<?php page_foot(); ?>
