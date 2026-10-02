<?php
require_once __DIR__ . '/common.php';
require_login();
$User = current_user();
$Db = get_db();

$PerPage = 50;
$Page = max(1, (int)($_GET['page'] ?? 1));
$FilterUser = (int)($_GET['user'] ?? 0);

$Where = $FilterUser > 0 ? "WHERE H.UserId = $FilterUser" : '';

$Total = $Db->query("SELECT COUNT(*) AS C FROM TaskHistory H $Where")->fetch_assoc()['C'];
$Pages = max(1, (int)ceil($Total / $PerPage));
$Page = min($Page, $Pages);
$Offset = ($Page - 1) * $PerPage;

$Sql = "SELECT H.*, T.Title, U.Login
        FROM TaskHistory H
        JOIN Tasks T ON T.Id = H.TaskId
        LEFT JOIN Users U ON U.Id = H.UserId
        $Where
        ORDER BY H.CreatedAt DESC
        LIMIT $PerPage OFFSET $Offset";
$Rows = $Db->query($Sql)->fetch_all(MYSQLI_ASSOC);

$AllUsers = $Db->query('SELECT Id, Login FROM Users ORDER BY Login')->fetch_all(MYSQLI_ASSOC);

$ActionLabels = [
  'added' => 'dodał(a) zadanie', 'approved' => 'zaakceptował(a) zadanie', 'rejected' => 'odrzucił(a) zadanie',
  'edited' => 'edytował(a) zadanie', 'accepted' => 'przyjął(ęła) zadanie do realizacji',
  'abandoned' => 'odłożył(a) zadanie', 'finished' => 'ukończył(a) zadanie', 'deleted' => 'usunął(ęła) zadanie',
  'awaiting_merge' => 'zgłosił(a) jako gotowe (czeka na dodanie) zadanie', 'reopened' => 'cofnął(ęła) do realizacji zadanie',
];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Historia</title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<header class="topbar">
  <div class="topbar-left">
    <a href="index.php" class="brand">Call of the Void</a>
    <nav class="mainnav">
      <a href="tasks.php">Zadania</a>
      <a href="my_tasks.php">Moje zadania</a>
      <a href="history.php" class="active">Historia</a>
      <a href="graph.php">Repozytorium</a>
      <?php if ((int)$User['IsAdmin'] === 1): ?><a href="admin.php" class="admin-link">Panel administracji</a><?php endif; ?>
    </nav>
  </div>
  <div class="topbar-right">
    <span class="hello">Zalogowano jako <strong><?= htmlspecialchars($User['Login']) ?></strong></span>
    <a href="logout.php" class="btn btn-ghost">Wyloguj</a>
  </div>
</header>
<main class="container">

<div class="board-toolbar">
  <h1>Historia</h1>
  <form method="get" class="sort-controls">
    <select name="user" onchange="this.form.submit()">
      <option value="0">Wszyscy użytkownicy</option>
      <?php foreach ($AllUsers as $U): ?>
        <option value="<?= $U['Id'] ?>" <?= $U['Id'] == $FilterUser ? 'selected' : '' ?>><?= htmlspecialchars($U['Login']) ?></option>
      <?php endforeach; ?>
    </select>
  </form>
</div>

<div class="timeline">
  <?php foreach ($Rows as $R): ?>
    <div class="timeline-item">
      <div class="ts"><?= date('d.m.Y H:i', strtotime($R['CreatedAt'])) ?></div>
      <strong><?= htmlspecialchars($R['Login'] ?? 'system') ?></strong>
      <?= htmlspecialchars($ActionLabels[$R['Action']] ?? $R['Action']) ?>
      "<?= htmlspecialchars($R['Title']) ?>"
      <?php if ($R['Details']): ?><div class="modal-info"><?= htmlspecialchars($R['Details']) ?></div><?php endif; ?>
    </div>
  <?php endforeach; ?>
  <?php if (empty($Rows)): ?><p class="modal-info">Brak wpisów w historii.</p><?php endif; ?>
</div>

<?php if ($Pages > 1): ?>
<div class="pagination">
  <?php for ($P = 1; $P <= $Pages; $P++): ?>
    <?php if ($P == $Page): ?>
      <span class="current"><?= $P ?></span>
    <?php else: ?>
      <a href="history.php?page=<?= $P ?>&user=<?= $FilterUser ?>"><?= $P ?></a>
    <?php endif; ?>
  <?php endfor; ?>
</div>
<?php endif; ?>

</main>
</body>
</html>
