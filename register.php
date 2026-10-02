<?php
require_once __DIR__ . '/common.php';

if (current_user()) { header('Location: tasks.php'); exit; }

$Errors = [];
$Login = '';
$Registered = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $Login = trim($_POST['login'] ?? '');
  $Password = $_POST['password'] ?? '';
  $Password2 = $_POST['password2'] ?? '';
  $CaptchaAnswer = $_POST['captcha'] ?? '';

  if ($Login === '' || !preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $Login)) {
    $Errors[] = 'Login musi mieć 3-50 znaków (litery, cyfry, . _ -).';
  }
  if ($Password !== $Password2) $Errors[] = 'Podane hasła nie są identyczne.';
  $Errors = array_merge($Errors, password_strength_errors($Password));

  $ExpectedCaptcha = $_SESSION['CaptchaHash'] ?? null;
  if (!$ExpectedCaptcha || !verify_captcha($ExpectedCaptcha, $CaptchaAnswer)) {
    $Errors[] = 'Nieprawidłowa odpowiedź w weryfikacji "nie jestem botem".';
  }

  if (empty($Errors)) {
    $Db = get_db();
    $Check = $Db->prepare('SELECT Id FROM Users WHERE Login = ?');
    $Check->bind_param('s', $Login);
    $Check->execute();
    if ($Check->get_result()->fetch_assoc()) {
      $Errors[] = 'Ten login jest już zajęty.';
    } else {
      [$Hash1, $Hash2] = hash_password($Password, $Login);
      // IsApproved=0 domyślnie - konto czeka na akceptację admina, logowanie zablokowane do tego czasu
      $Stmt = $Db->prepare('INSERT INTO Users (Login, Hash1, Hash2) VALUES (?, ?, ?)');
      $Stmt->bind_param('sss', $Login, $Hash1, $Hash2);
      $Stmt->execute();
      unset($_SESSION['CaptchaHash']);
      $Registered = true;
    }
  }
}

$Captcha = null;
if (!$Registered) {
  $Captcha = pick_random_captcha();
  if ($Captcha) $_SESSION['CaptchaHash'] = $Captcha['hash'];
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Rejestracja</title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<header class="topbar">
  <div class="topbar-left"><a href="index.php" class="brand">Call of the Void</a></div>
  <div class="topbar-right"><a href="login.php" class="btn btn-primary">Zaloguj</a></div>
</header>
<main class="container">

<div class="auth-card">
  <h1>Rejestracja</h1>
  <?php if ($Registered): ?>
    <p class="modal-info">Konto zostało utworzone. Zanim się zalogujesz, musi je zaakceptować administrator - wróć tu za chwilę.</p>
    <p style="margin-top:14px; font-size:0.85rem;"><a href="login.php">Przejdź do logowania</a></p>
  <?php else: ?>
  <?php if (!empty($Errors)): ?>
    <div class="error-list"><ul><?php foreach ($Errors as $E): ?><li><?= htmlspecialchars($E) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>
  <form method="post" novalidate>
    <div class="field">
      <label for="login">Login</label>
      <input type="text" id="login" name="login" value="<?= htmlspecialchars($Login) ?>" required>
    </div>
    <div class="field">
      <label for="password">Hasło</label>
      <input type="password" id="password" name="password" required oninput="updatePwStrength(this.value)">
      <div class="pw-strength"><div id="pwStrengthBar"></div></div>
      <small>Min. 8 znaków, mała i wielka litera, cyfra, znak specjalny.</small>
    </div>
    <div class="field">
      <label for="password2">Powtórz hasło</label>
      <input type="password" id="password2" name="password2" required>
    </div>
    <?php if ($Captcha): ?>
    <div class="field captcha-box">
      <label>Przepisz tekst z obrazka</label>
      <div><img src="Bot_veryfication/<?= htmlspecialchars($Captcha['hash'] . '.' . $Captcha['ext']) ?>" alt="captcha"></div>
      <a href="register.php" class="captcha-refresh">Zmień obrazek</a>
      <input type="text" name="captcha" required autocomplete="off">
    </div>
    <?php else: ?>
      <p class="error-list">Brak obrazków weryfikacyjnych w folderze Bot_veryfication.</p>
    <?php endif; ?>
    <button type="submit" class="btn btn-primary" style="width:100%;">Zarejestruj się</button>
  </form>
  <p style="margin-top:14px; font-size:0.85rem;">Masz już konto? <a href="login.php">Zaloguj się</a></p>
  <?php endif; ?>
</div>

</main>
<script src="assets/js/app.js"></script>
</body>
</html>
