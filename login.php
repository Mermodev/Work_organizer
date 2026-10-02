<?php
require_once __DIR__ . '/common.php';

if (current_user()) { header('Location: tasks.php'); exit; }

$Errors = [];
$Login = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $Login = trim($_POST['login'] ?? '');
  $Password = $_POST['password'] ?? '';

  $Db = get_db();
  $Stmt = $Db->prepare('SELECT Id, Login, Hash1, Hash2, IsApproved FROM Users WHERE Login = ?');
  $Stmt->bind_param('s', $Login);
  $Stmt->execute();
  $Row = $Stmt->get_result()->fetch_assoc();

  if ($Row && verify_password($Password, $Row['Login'], $Row['Hash1'], $Row['Hash2'])) {
    if ((int)$Row['IsApproved'] !== 1) {
      $Errors[] = 'Twoje konto oczekuje jeszcze na akceptację administratora.';
    } else {
      $_SESSION['UserId'] = $Row['Id'];
      header('Location: tasks.php');
      exit;
    }
  } else {
    $Errors[] = 'Nieprawidłowy login lub hasło.';
  }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Logowanie</title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<header class="topbar">
  <div class="topbar-left"><a href="index.php" class="brand">Call of the Void</a></div>
  <div class="topbar-right"><a href="register.php" class="btn btn-ghost">Zarejestruj</a></div>
</header>
<main class="container">

<div class="auth-card">
  <h1>Logowanie</h1>
  <?php if (!empty($Errors)): ?>
    <div class="error-list"><ul><?php foreach ($Errors as $E): ?><li><?= htmlspecialchars($E) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>
  <form method="post">
    <div class="field">
      <label for="login">Login</label>
      <input type="text" id="login" name="login" value="<?= htmlspecialchars($Login) ?>" required>
    </div>
    <div class="field">
      <label for="password">Hasło</label>
      <input type="password" id="password" name="password" required>
    </div>
    <button type="submit" class="btn btn-primary" style="width:100%;">Zaloguj się</button>
  </form>
  <p style="margin-top:14px; font-size:0.85rem;">Nie masz konta? <a href="register.php">Zarejestruj się</a></p>
</div>

</main>
</body>
</html>
