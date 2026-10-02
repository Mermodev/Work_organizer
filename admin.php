<?php
require_once __DIR__ . '/common.php';
require_admin();
$Admin = current_user();
$Db = get_db();

// ---- akcje admina (POST) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $Id = (int)($_POST['id'] ?? 0);
  $Do = $_POST['do'] ?? '';

  $Row = $Db->query("SELECT T.Status, T.Title, T.AddedBy, U.Login AS AddedByLogin FROM Tasks T JOIN Users U ON U.Id=T.AddedBy WHERE T.Id = $Id")->fetch_assoc();

  // edycja: zadania czekające na akceptację oraz zaakceptowane, ale jeszcze nieprzyjęte przez nikogo (todo).
  // Zapisywana jest przy KAŻDEJ akcji na tym formularzu (nie tylko "Zapisz zmiany"), żeby np. kliknięcie
  // "Zaakceptuj" od razu zaakceptowało zadanie z właśnie wprowadzonymi zmianami - bez dwóch kliknięć.
  if (in_array($Do, ['edit', 'approve', 'reject', 'delete'], true) && $Row && in_array($Row['Status'], ['pending', 'todo'], true) && isset($_POST['title'])) {
    $Title = trim($_POST['title'] ?? '');
    $Description = trim($_POST['description'] ?? '');
    $Minutes = read_duration_minutes('exp');
    $Priority = read_priority();
    if ($Title !== '' && $Description !== '') {
      $Stmt = $Db->prepare("UPDATE Tasks SET Title=?, Description=?, ExpectedMinutes=?, Priority=? WHERE Id=? AND Status IN ('pending','todo')");
      $Stmt->bind_param('ssiii', $Title, $Description, $Minutes, $Priority, $Id);
      $Stmt->execute();
      // zadanie czekające na akceptację nie jest jeszcze w historii, więc jego edycji też nie logujemy
      if ($Row['Status'] === 'todo') log_history($Id, $Admin['Id'], 'edited', 'Admin zmodyfikował treść zadania');
    }
  }
  // akceptacja = dopiero teraz zadanie pojawia się w historii jako "dodane" przez autora (z czasem akceptacji)
  if ($Do === 'approve' && $Row && $Row['Status'] === 'pending') {
    $Stmt = $Db->prepare("UPDATE Tasks SET Status='todo', ApprovedBy=?, ApprovedAt=NOW() WHERE Id=? AND Status='pending'");
    $Stmt->bind_param('ii', $Admin['Id'], $Id);
    $Stmt->execute();
    if ($Stmt->affected_rows > 0) log_history($Id, $Row['AddedBy'], 'added');
  }
  // usunięcie zadania: zaakceptowanego a nieprzyjętego (todo) albo już zrealizowanego (done) -
  // "usunięcie" jest miękkie: zadanie zostaje w bazie i w historii ze statusem 'deleted',
  // dzięki czemu nie trzeba kasować wpisów z TaskHistory (FkHistoryTask) i historia pozostaje kompletna
  if ($Do === 'delete' && $Row && in_array($Row['Status'], ['todo', 'done'], true)) {
    $FromStatus = $Row['Status'];
    $Stmt = $Db->prepare("UPDATE Tasks SET Status='deleted', ApprovedBy=?, ApprovedAt=NOW() WHERE Id=? AND Status=?");
    $Stmt->bind_param('iis', $Admin['Id'], $Id, $FromStatus);
    $Stmt->execute();
    if ($Stmt->affected_rows > 0) log_history($Id, $Admin['Id'], 'deleted', 'Zadanie usunięte przez admina');
  }
  if ($Do === 'reject' && $Row && $Row['Status'] === 'pending') {
    $Stmt = $Db->prepare("UPDATE Tasks SET Status='abandoned', ApprovedBy=?, ApprovedAt=NOW() WHERE Id=? AND Status='pending'");
    $Stmt->bind_param('ii', $Admin['Id'], $Id);
    $Stmt->execute();
    if ($Stmt->affected_rows > 0) log_history($Id, $Admin['Id'], 'rejected', 'Zadanie użytkownika ' . $Row['AddedByLogin'] . ' odrzucone przez admina');
  }

  // admin może interweniować w zadanie w trakcie realizacji lub czekające na dodanie,
  // gdy np. wykonawca zniknął - działa z obu statusów, zawsze prowadzi wprost do done/todo
  if ($Do === 'force_finish' && $Row && in_array($Row['Status'], ['in_progress', 'awaiting_merge'], true)) {
    $FromStatus = $Row['Status'];
    $Stmt = $Db->prepare("UPDATE Tasks SET Status='done', FinishedAt=NOW() WHERE Id=? AND Status=?");
    $Stmt->bind_param('is', $Id, $FromStatus);
    $Stmt->execute();
    if ($Stmt->affected_rows > 0) log_history($Id, $Admin['Id'], 'finished', 'Zadanie oznaczone jako zrealizowane przez admina');
  }
  if ($Do === 'force_abandon' && $Row && in_array($Row['Status'], ['in_progress', 'awaiting_merge'], true)) {
    $FromStatus = $Row['Status'];
    $Stmt = $Db->prepare("UPDATE Tasks SET Status='todo', StartedBy=NULL, StartedAt=NULL WHERE Id=? AND Status=?");
    $Stmt->bind_param('is', $Id, $FromStatus);
    $Stmt->execute();
    if ($Stmt->affected_rows > 0) log_history($Id, $Admin['Id'], 'abandoned', 'Zadanie odłożone przez admina, wraca do puli dostępnych');
  }

  // nadanie / odebranie uprawnień administratora - nie na własnym koncie, żeby się nie zablokować
  if ($Do === 'toggle_admin') {
    $TargetId = (int)($_POST['user_id'] ?? 0);
    if ($TargetId > 0 && $TargetId !== (int)$Admin['Id']) {
      $Db->query("UPDATE Users SET IsAdmin = 1 - IsAdmin WHERE Id = $TargetId");
    }
  }

  // akceptacja / odrzucenie nowej rejestracji - dopóki konto czeka na akceptację, nie mogło
  // nic zrobić w systemie (nie zalogowało się), więc odrzucenie może je bezpiecznie skasować
  if ($Do === 'approve_user') {
    $TargetId = (int)($_POST['user_id'] ?? 0);
    $Db->query("UPDATE Users SET IsApproved = 1 WHERE Id = $TargetId AND IsApproved = 0");
  }
  if ($Do === 'reject_user') {
    $TargetId = (int)($_POST['user_id'] ?? 0);
    $Db->query("DELETE FROM Users WHERE Id = $TargetId AND IsApproved = 0");
  }

  header('Location: admin.php?tab=' . urlencode($_GET['tab'] ?? 'pending'));
  exit;
}

$Tab = $_GET['tab'] ?? 'pending';

// ---- sortowanie list w poszczególnych zakładkach ----
// każda zakładka ma własny zestaw dozwolonych kolumn (klucz => wyrażenie SQL)
$SortColumns = [
  'pending'     => ['CreatedAt' => 'T.CreatedAt', 'Title' => 'T.Title', 'Login' => 'AddedByLogin', 'Priority' => 'T.Priority'],
  'todo'        => ['CreatedAt' => 'T.CreatedAt', 'Title' => 'T.Title', 'Login' => 'AddedByLogin', 'Priority' => 'T.Priority'],
  'in_progress' => ['StartedAt' => 'T.StartedAt', 'Title' => 'T.Title', 'Login' => 'StartedByLogin', 'Priority' => 'T.Priority'],
  'awaiting_merge' => ['StartedAt' => 'T.StartedAt', 'Title' => 'T.Title', 'Login' => 'StartedByLogin', 'Priority' => 'T.Priority'],
  'done'        => ['FinishedAt' => 'T.FinishedAt', 'StartedAt' => 'T.StartedAt', 'Title' => 'T.Title', 'Login' => 'StartedByLogin', 'Priority' => 'T.Priority'],
  'users'       => ['Login' => 'U.Login', 'CreatedAt' => 'U.CreatedAt', 'IsAdmin' => 'U.IsAdmin', 'AcceptedCount' => 'AcceptedCount'],
  'new_users'   => ['CreatedAt' => 'U.CreatedAt', 'Login' => 'U.Login'],
];
$SortLabels = ['CreatedAt' => 'Data dodania', 'StartedAt' => 'Data rozpoczęcia', 'FinishedAt' => 'Data zakończenia',
  'Title' => 'Tytuł', 'Login' => 'Użytkownik', 'Priority' => 'Waga', 'IsAdmin' => 'Admin', 'AcceptedCount' => 'Zaakceptowane zadania'];

$Cols = $SortColumns[$Tab] ?? [];
$ASort = array_key_exists($_GET['asort'] ?? '', $Cols) ? $_GET['asort'] : array_key_first($Cols);
$ADir = ($_GET['adir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

// wyrażenie ORDER BY dla bieżącej zakładki; Waga sortowana tak, że brak wagi (NULL) zawsze na końcu
function admin_order_by($Cols, $Col, $Dir) {
  if (!$Col || !isset($Cols[$Col])) return '';
  $Expr = $Cols[$Col];
  return $Col === 'Priority' ? "$Expr IS NULL, $Expr $Dir" : "$Expr $Dir";
}
$OrderBy = admin_order_by($Cols, $ASort, $ADir);

// select z kolumnami + kierunkiem, submitowany od razu po zmianie
function admin_sort_select($Cols, $Labels, $CurCol, $CurDir, $Tab) {
  if (empty($Cols)) return '';
  $Html = '<form method="get" class="sort-controls" style="display:inline-flex;">'
        . '<input type="hidden" name="tab" value="' . htmlspecialchars($Tab) . '">'
        . '<select name="asort" onchange="this.form.submit()">';
  foreach ($Cols as $Val => $Expr) {
    $Html .= '<option value="' . $Val . '" ' . ($Val === $CurCol ? 'selected' : '') . '>' . ($Labels[$Val] ?? $Val) . '</option>';
  }
  $Html .= '</select><select name="adir" onchange="this.form.submit()">'
         . '<option value="ASC" ' . ($CurDir === 'ASC' ? 'selected' : '') . '>Rosnąco</option>'
         . '<option value="DESC" ' . ($CurDir === 'DESC' ? 'selected' : '') . '>Malejąco</option>'
         . '</select></form>';
  return $Html;
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Panel administracji</title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<header class="topbar">
  <div class="topbar-left">
    <a href="index.php" class="brand">Call of the Void</a>
    <nav class="mainnav">
      <a href="tasks.php">Zadania</a>
      <a href="my_tasks.php">Moje zadania</a>
      <a href="history.php">Historia</a>
      <a href="graph.php">Repozytorium</a>
      <a href="admin.php" class="admin-link active">Panel administracji</a>
    </nav>
  </div>
  <div class="topbar-right">
    <span class="hello">Zalogowano jako <strong><?= htmlspecialchars($Admin['Login']) ?></strong></span>
    <a href="logout.php" class="btn btn-ghost">Wyloguj</a>
  </div>
</header>
<main class="container">

<?php $PendingUsersCount = (int)$Db->query("SELECT COUNT(*) AS C FROM Users WHERE IsApproved = 0")->fetch_assoc()['C']; ?>
<div class="admin-tabs">
  <a href="admin.php?tab=pending" class="<?= $Tab === 'pending' ? 'active' : '' ?>">Zadania do akceptacji</a>
  <a href="admin.php?tab=todo" class="<?= $Tab === 'todo' ? 'active' : '' ?>">Nieprzyjęte zadania</a>
  <a href="admin.php?tab=in_progress" class="<?= $Tab === 'in_progress' ? 'active' : '' ?>">W trakcie realizacji</a>
  <a href="admin.php?tab=awaiting_merge" class="<?= $Tab === 'awaiting_merge' ? 'active' : '' ?>">Czekające na dodanie</a>
  <a href="admin.php?tab=done" class="<?= $Tab === 'done' ? 'active' : '' ?>">Zrealizowane</a>
  <a href="admin.php?tab=users" class="<?= $Tab === 'users' ? 'active' : '' ?>">Użytkownicy</a>
  <a href="admin.php?tab=new_users" class="<?= $Tab === 'new_users' ? 'active' : '' ?>">Nowi użytkownicy<?= $PendingUsersCount > 0 ? ' (' . $PendingUsersCount . ')' : '' ?></a>
</div>

<?php
function edit_fields_html($T) {
  return '<input type="hidden" name="id" value="' . (int)$T['Id'] . '">'
    . '<div class="field"><label>Tytuł</label><input type="text" name="title" value="' . htmlspecialchars($T['Title']) . '"></div>'
    . '<div class="field"><label>Opis</label><textarea name="description" class="edit-desc">' . htmlspecialchars($T['Description']) . '</textarea></div>'
    . '<div class="field"><label>Oczekiwany czas realizacji</label>' . duration_fields_html('exp', $T['ExpectedMinutes']) . '</div>'
    . '<div class="field"><label>Waga 1-10</label>' . priority_input_html($T['Priority']) . '</div>';
}
?>

<?php if ($Tab === 'pending'): ?>

  <?php
  $Rows = $Db->query("SELECT T.*, U.Login AS AddedByLogin FROM Tasks T JOIN Users U ON U.Id=T.AddedBy
                       WHERE T.Status='pending' ORDER BY $OrderBy")->fetch_all(MYSQLI_ASSOC);
  ?>
  <h1>Zadania oczekujące na akceptację</h1>
  <?= admin_sort_select($Cols, $SortLabels, $ASort, $ADir, $Tab) ?>

  <?php foreach ($Rows as $T): ?>
    <div class="task-card" style="cursor:default; margin-bottom:16px;">
      <form method="post">
        <?= edit_fields_html($T) ?>
        <div class="modal-info">Zgłosił: <?= htmlspecialchars($T['AddedByLogin']) ?> &middot; <?= date('d.m.Y H:i', strtotime($T['CreatedAt'])) ?></div>
        <div class="modal-actions">
          <button class="btn btn-ghost btn-sm" name="do" value="edit">Zapisz zmiany</button>
          <button class="btn btn-success btn-sm" name="do" value="approve">Zaakceptuj</button>
          <button class="btn btn-danger btn-sm" name="do" value="reject" onclick="return confirm('Na pewno odrzucić to zadanie?');">Odrzuć</button>
        </div>
      </form>
    </div>
  <?php endforeach; ?>
  <?php if (empty($Rows)): ?><p class="modal-info">Brak zadań oczekujących na akceptację.</p><?php endif; ?>

<?php elseif ($Tab === 'todo'): ?>

  <?php
  $Rows = $Db->query("SELECT T.*, U.Login AS AddedByLogin FROM Tasks T JOIN Users U ON U.Id=T.AddedBy
                       WHERE T.Status='todo' ORDER BY $OrderBy")->fetch_all(MYSQLI_ASSOC);
  ?>
  <h1>Nieprzyjęte zadania</h1>
  <p class="modal-info">Zaakceptowane zadania, których nikt jeszcze nie przyjął do realizacji.</p>
  <?= admin_sort_select($Cols, $SortLabels, $ASort, $ADir, $Tab) ?>

  <?php foreach ($Rows as $T): ?>
    <div class="task-card" style="cursor:default; margin-bottom:16px;">
      <form method="post">
        <?= edit_fields_html($T) ?>
        <div class="modal-info">Dodał: <?= htmlspecialchars($T['AddedByLogin']) ?> &middot; <?= date('d.m.Y H:i', strtotime($T['CreatedAt'])) ?></div>
        <div class="modal-actions">
          <button class="btn btn-primary btn-sm" name="do" value="edit">Zapisz zmiany</button>
          <button class="btn btn-danger btn-sm" name="do" value="delete" onclick="return confirm('Na pewno usunąć to zadanie? Tej operacji nie można cofnąć.');">Usuń</button>
        </div>
      </form>
    </div>
  <?php endforeach; ?>
  <?php if (empty($Rows)): ?><p class="modal-info">Brak nieprzyjętych zadań.</p><?php endif; ?>

<?php elseif ($Tab === 'in_progress'): ?>

  <?php
  $Rows = $Db->query("SELECT T.*, U.Login AS AddedByLogin, S.Login AS StartedByLogin,
                       TIMESTAMPDIFF(SECOND, T.StartedAt, NOW()) AS ElapsedSec
                       FROM Tasks T JOIN Users U ON U.Id=T.AddedBy JOIN Users S ON S.Id=T.StartedBy
                       WHERE T.Status='in_progress' ORDER BY $OrderBy")->fetch_all(MYSQLI_ASSOC);
  ?>
  <h1>Zadania w trakcie realizacji</h1>
  <p class="modal-info">Interwencja administratora - użyteczne, gdy wykonawca zniknął albo zadanie utknęło.</p>
  <?= admin_sort_select($Cols, $SortLabels, $ASort, $ADir, $Tab) ?>

  <?php foreach ($Rows as $T): $Rem = remaining_time_text($T['ElapsedSec'], $T['ExpectedMinutes']); ?>
    <div class="task-card col-progress" style="cursor:default; margin-bottom:16px;">
      <div class="task-title"><?= htmlspecialchars($T['Title']) ?> <?= priority_badge_html($T['Priority']) ?></div>
      <div class="task-desc"><?= htmlspecialchars(short_text($T['Description'], 120)) ?></div>
      <div class="modal-info">
        Realizuje: <?= htmlspecialchars($T['StartedByLogin']) ?> &middot; od <?= date('d.m.Y H:i', strtotime($T['StartedAt'])) ?>
        &middot; dodał: <?= htmlspecialchars($T['AddedByLogin']) ?>
        <?php if ($Rem['text']): ?><?= remaining_html($Rem) ?><?php endif; ?>
      </div>
      <form method="post" class="modal-actions" style="margin-top:8px;">
        <input type="hidden" name="id" value="<?= (int)$T['Id'] ?>">
        <button class="btn btn-primary btn-sm" name="do" value="force_finish" onclick="return confirm('Oznaczyć to zadanie jako zrealizowane?');">Wymuś zakończenie</button>
        <button class="btn btn-danger btn-sm" name="do" value="force_abandon" onclick="return confirm('Odłożyć to zadanie i zwolnić wykonawcę?');">Wymuś odłożenie</button>
      </form>
    </div>
  <?php endforeach; ?>
  <?php if (empty($Rows)): ?><p class="modal-info">Brak zadań w trakcie realizacji.</p><?php endif; ?>

<?php elseif ($Tab === 'awaiting_merge'): ?>

  <?php
  $Rows = $Db->query("SELECT T.*, U.Login AS AddedByLogin, S.Login AS StartedByLogin,
                       TIMESTAMPDIFF(SECOND, T.StartedAt, NOW()) AS ElapsedSec
                       FROM Tasks T JOIN Users U ON U.Id=T.AddedBy JOIN Users S ON S.Id=T.StartedBy
                       WHERE T.Status='awaiting_merge' ORDER BY $OrderBy")->fetch_all(MYSQLI_ASSOC);
  ?>
  <h1>Zadania czekające na dodanie</h1>
  <p class="modal-info">Praca jest już napisana (np. na branchu), ale jeszcze nie trafiła do main.</p>
  <?= admin_sort_select($Cols, $SortLabels, $ASort, $ADir, $Tab) ?>

  <?php foreach ($Rows as $T): ?>
    <div class="task-card col-awaiting" style="cursor:default; margin-bottom:16px;">
      <div class="task-title"><?= htmlspecialchars($T['Title']) ?> <?= priority_badge_html($T['Priority']) ?></div>
      <div class="task-desc"><?= htmlspecialchars(short_text($T['Description'], 120)) ?></div>
      <div class="modal-info">
        Realizuje: <?= htmlspecialchars($T['StartedByLogin']) ?> &middot; od <?= date('d.m.Y H:i', strtotime($T['StartedAt'])) ?>
        &middot; dodał: <?= htmlspecialchars($T['AddedByLogin']) ?>
      </div>
      <form method="post" class="modal-actions" style="margin-top:8px;">
        <input type="hidden" name="id" value="<?= (int)$T['Id'] ?>">
        <button class="btn btn-primary btn-sm" name="do" value="force_finish" onclick="return confirm('Oznaczyć to zadanie jako zrealizowane (dodane do main)?');">Wymuś dodanie do main</button>
        <button class="btn btn-danger btn-sm" name="do" value="force_abandon" onclick="return confirm('Odłożyć to zadanie i zwolnić wykonawcę?');">Wymuś odłożenie</button>
      </form>
    </div>
  <?php endforeach; ?>
  <?php if (empty($Rows)): ?><p class="modal-info">Brak zadań czekających na dodanie.</p><?php endif; ?>

<?php elseif ($Tab === 'done'): ?>

  <?php
  $Rows = $Db->query("SELECT T.*, U.Login AS AddedByLogin, S.Login AS StartedByLogin
                       FROM Tasks T JOIN Users U ON U.Id=T.AddedBy LEFT JOIN Users S ON S.Id=T.StartedBy
                       WHERE T.Status='done' ORDER BY $OrderBy")->fetch_all(MYSQLI_ASSOC);
  ?>
  <h1>Zrealizowane zadania</h1>
  <?= admin_sort_select($Cols, $SortLabels, $ASort, $ADir, $Tab) ?>

  <?php foreach ($Rows as $T): ?>
    <div class="task-card col-done" style="cursor:default; margin-bottom:16px;">
      <div class="task-title"><?= htmlspecialchars($T['Title']) ?> <?= priority_badge_html($T['Priority']) ?></div>
      <div class="task-desc"><?= htmlspecialchars(short_text($T['Description'], 120)) ?></div>
      <div class="modal-info">
        Zrealizował: <?= htmlspecialchars($T['StartedByLogin'] ?? '—') ?>
        &middot; dodał: <?= htmlspecialchars($T['AddedByLogin']) ?>
        &middot; zakończono: <?= $T['FinishedAt'] ? date('d.m.Y H:i', strtotime($T['FinishedAt'])) : '—' ?>
      </div>
      <form method="post" class="modal-actions" style="margin-top:8px;">
        <input type="hidden" name="id" value="<?= (int)$T['Id'] ?>">
        <button class="btn btn-danger btn-sm" name="do" value="delete" onclick="return confirm('Na pewno usunąć to zadanie? Tej operacji nie można cofnąć.');">Usuń</button>
      </form>
    </div>
  <?php endforeach; ?>
  <?php if (empty($Rows)): ?><p class="modal-info">Brak zrealizowanych zadań.</p><?php endif; ?>

<?php elseif ($Tab === 'users' && !isset($_GET['id'])): ?>

  <?php
  $Users = $Db->query("SELECT U.*, (SELECT COUNT(*) FROM Tasks WHERE StartedBy=U.Id) AS AcceptedCount
                        FROM Users U WHERE IsApproved = 1 ORDER BY $OrderBy")->fetch_all(MYSQLI_ASSOC);
  ?>
  <h1>Użytkownicy</h1>
  <?= admin_sort_select($Cols, $SortLabels, $ASort, $ADir, $Tab) ?>
  <table class="admin-table">
    <thead><tr><th>Login</th><th>Data rejestracji</th><th>Admin</th><th>Zaakceptowane zadania</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($Users as $U): ?>
      <tr>
        <td><?= htmlspecialchars($U['Login']) ?></td>
        <td><?= date('d.m.Y', strtotime($U['CreatedAt'])) ?></td>
        <td><?= (int)$U['IsAdmin'] === 1 ? 'Tak' : 'Nie' ?></td>
        <td><?= (int)$U['AcceptedCount'] ?></td>
        <td style="display:flex; gap:6px;">
          <a href="admin.php?tab=users&id=<?= $U['Id'] ?>" class="btn btn-ghost btn-sm">Zobacz zadania</a>
          <?php if ((int)$U['Id'] !== (int)$Admin['Id']): ?>
          <form method="post" style="margin:0;" onsubmit="return confirm('<?= (int)$U['IsAdmin'] === 1 ? 'Odebrać' : 'Nadać' ?> uprawnienia administratora użytkownikowi <?= htmlspecialchars($U['Login'], ENT_QUOTES) ?>?');">
            <input type="hidden" name="user_id" value="<?= (int)$U['Id'] ?>">
            <button class="btn btn-sm <?= (int)$U['IsAdmin'] === 1 ? 'btn-danger' : 'btn-success' ?>" name="do" value="toggle_admin"><?= (int)$U['IsAdmin'] === 1 ? 'Odbierz admina' : 'Nadaj admina' ?></button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

<?php elseif ($Tab === 'new_users'): ?>

  <?php
  $NewUsers = $Db->query("SELECT * FROM Users U WHERE IsApproved = 0 ORDER BY $OrderBy")->fetch_all(MYSQLI_ASSOC);
  ?>
  <h1>Nowi użytkownicy</h1>
  <p class="modal-info">Konta czekające na akceptację - dopóki admin ich nie zaakceptuje, nie mogą się zalogować.</p>
  <?= admin_sort_select($Cols, $SortLabels, $ASort, $ADir, $Tab) ?>
  <table class="admin-table">
    <thead><tr><th>Login</th><th>Data rejestracji</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($NewUsers as $U): ?>
      <tr>
        <td><?= htmlspecialchars($U['Login']) ?></td>
        <td><?= date('d.m.Y H:i', strtotime($U['CreatedAt'])) ?></td>
        <td style="display:flex; gap:6px;">
          <form method="post" style="margin:0;">
            <input type="hidden" name="user_id" value="<?= (int)$U['Id'] ?>">
            <button class="btn btn-success btn-sm" name="do" value="approve_user">Zaakceptuj</button>
          </form>
          <form method="post" style="margin:0;" onsubmit="return confirm('Na pewno odrzucić i usunąć to konto (<?= htmlspecialchars($U['Login'], ENT_QUOTES) ?>)? Tej operacji nie można cofnąć.');">
            <input type="hidden" name="user_id" value="<?= (int)$U['Id'] ?>">
            <button class="btn btn-danger btn-sm" name="do" value="reject_user">Odrzuć</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($NewUsers)): ?><tr><td colspan="3">Brak nowych użytkowników czekających na akceptację.</td></tr><?php endif; ?>
    </tbody>
  </table>

<?php elseif ($Tab === 'users' && isset($_GET['id'])): ?>

  <?php
  $UserId = (int)$_GET['id'];
  $TargetUser = $Db->query("SELECT * FROM Users WHERE Id=$UserId")->fetch_assoc();
  if (!$TargetUser) { die('Nie znaleziono użytkownika.'); }
  $Tasks = $Db->query("SELECT * FROM Tasks WHERE StartedBy=$UserId ORDER BY StartedAt DESC")->fetch_all(MYSQLI_ASSOC);
  $Labels = [
    'in_progress' => ['W trakcie realizacji', 'badge-progress'], 'awaiting_merge' => ['Czekające na dodanie', 'badge-awaiting'],
    'done' => ['Zrealizowane', 'badge-done'],
    'abandoned' => ['Porzucone', 'badge-abandoned'], 'other' => ['Inne', 'badge-other'],
  ];
  ?>
  <p><a href="admin.php?tab=users">&larr; Wróć do listy użytkowników</a></p>
  <h1>Zadania zaakceptowane przez: <?= htmlspecialchars($TargetUser['Login']) ?></h1>
  <table class="admin-table">
    <thead><tr><th>Tytuł</th><th>Status</th><th>Rozpoczęte</th><th>Zakończone</th></tr></thead>
    <tbody>
    <?php foreach ($Tasks as $T): $L = $Labels[$T['Status']] ?? ['-', 'badge-other']; ?>
      <tr>
        <td><?= htmlspecialchars($T['Title']) ?></td>
        <td><span class="badge <?= $L[1] ?>"><?= $L[0] ?></span></td>
        <td><?= $T['StartedAt'] ? date('d.m.Y H:i', strtotime($T['StartedAt'])) : '—' ?></td>
        <td><?= $T['FinishedAt'] ? date('d.m.Y H:i', strtotime($T['FinishedAt'])) : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($Tasks)): ?><tr><td colspan="4">Ten użytkownik nie zaakceptował jeszcze żadnego zadania.</td></tr><?php endif; ?>
    </tbody>
  </table>

<?php endif; ?>

</main>
</body>
</html>
