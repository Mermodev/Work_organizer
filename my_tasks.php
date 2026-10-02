<?php
require_once __DIR__ . '/common.php';
require_login();
$User = current_user();
$Db = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $Action = $_POST['action'] ?? '';
  $Id = (int)($_POST['id'] ?? 0);

  if ($Action === 'accept') {
    $Row = $Db->query("SELECT Status FROM Tasks WHERE Id = $Id")->fetch_assoc();
    if ($Row && $Row['Status'] === 'todo') {
      $Stmt = $Db->prepare("UPDATE Tasks SET Status='in_progress', StartedBy=?, StartedAt=NOW() WHERE Id=?");
      $Stmt->bind_param('ii', $User['Id'], $Id);
      $Stmt->execute();
      log_history($Id, $User['Id'], 'accepted', 'Zadanie przyjęte do realizacji');
    }
  }
  // praca napisana (np. wypchnięta na branch), ale jeszcze nie w main - zadanie czeka na dodanie
  if ($Action === 'ready') {
    $Row = $Db->query("SELECT Status, StartedBy FROM Tasks WHERE Id = $Id")->fetch_assoc();
    if ($Row && $Row['Status'] === 'in_progress' && (int)$Row['StartedBy'] === (int)$User['Id']) {
      $Stmt = $Db->prepare("UPDATE Tasks SET Status='awaiting_merge' WHERE Id=?");
      $Stmt->bind_param('i', $Id);
      $Stmt->execute();
      log_history($Id, $User['Id'], 'awaiting_merge', 'Praca napisana, zadanie czeka na dodanie do main');
    }
  }
  // potwierdzenie, że praca trafiła do main - dopiero teraz zadanie jest naprawdę zrealizowane
  if ($Action === 'merged') {
    $Row = $Db->query("SELECT Status, StartedBy FROM Tasks WHERE Id = $Id")->fetch_assoc();
    if ($Row && $Row['Status'] === 'awaiting_merge' && (int)$Row['StartedBy'] === (int)$User['Id']) {
      $Stmt = $Db->prepare("UPDATE Tasks SET Status='done', FinishedAt=NOW() WHERE Id=?");
      $Stmt->bind_param('i', $Id);
      $Stmt->execute();
      log_history($Id, $User['Id'], 'finished', 'Zadanie dodane do main i oznaczone jako zrealizowane');
    }
  }
  // cofnięcie z "czeka na dodanie" z powrotem do realizacji (np. wymagane poprawki przed scaleniem)
  if ($Action === 'reopen') {
    $Row = $Db->query("SELECT Status, StartedBy FROM Tasks WHERE Id = $Id")->fetch_assoc();
    if ($Row && $Row['Status'] === 'awaiting_merge' && (int)$Row['StartedBy'] === (int)$User['Id']) {
      $Stmt = $Db->prepare("UPDATE Tasks SET Status='in_progress' WHERE Id=?");
      $Stmt->bind_param('i', $Id);
      $Stmt->execute();
      log_history($Id, $User['Id'], 'reopened', 'Zadanie wróciło do realizacji z oczekiwania na dodanie');
    }
  }
  // odłożenie zadania - wraca do puli dostępnych (todo); można odłożyć zarówno z realizacji,
  // jak i z "czeka na dodanie" (np. ktoś porzucił swój branch)
  if ($Action === 'abandon') {
    $Row = $Db->query("SELECT Status, StartedBy FROM Tasks WHERE Id = $Id")->fetch_assoc();
    if ($Row && in_array($Row['Status'], ['in_progress', 'awaiting_merge'], true) && (int)$Row['StartedBy'] === (int)$User['Id']) {
      $Stmt = $Db->prepare("UPDATE Tasks SET Status='todo', StartedBy=NULL, StartedAt=NULL WHERE Id=?");
      $Stmt->bind_param('i', $Id);
      $Stmt->execute();
      log_history($Id, $User['Id'], 'abandoned', 'Zadanie odłożone, wraca do puli dostępnych');
    }
  }

  header('Location: my_tasks.php');
  exit;
}

$FormSubmitted = isset($_GET['filters']);
$ShowProposed   = $FormSubmitted ? isset($_GET['proposed'])   : true;
$ShowInProgress = $FormSubmitted ? isset($_GET['inprogress']) : true;
$ShowAwaiting   = $FormSubmitted ? isset($_GET['awaiting'])   : true;
$ShowDone       = $FormSubmitted ? isset($_GET['done'])       : true;

$AllowedSort = ['CreatedAt', 'StartedAt', 'FinishedAt', 'Title', 'Priority'];
$SortCol = in_array($_GET['sort'] ?? '', $AllowedSort, true) ? $_GET['sort'] : 'CreatedAt';
$SortDir = ($_GET['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
// przy sortowaniu po wadze zadania bez wagi (NULL) zawsze na końcu
$OrderSql = $SortCol === 'Priority'
  ? "T.Priority IS NULL, T.Priority $SortDir, T.CreatedAt DESC"
  : "T.$SortCol $SortDir";

$Conditions = [];
if ($ShowProposed) $Conditions[] = "(T.AddedBy = {$User['Id']} AND T.Status IN ('pending','todo'))";
if ($ShowInProgress) $Conditions[] = "(T.StartedBy = {$User['Id']} AND T.Status = 'in_progress')";
if ($ShowAwaiting) $Conditions[] = "(T.StartedBy = {$User['Id']} AND T.Status = 'awaiting_merge')";
if ($ShowDone) $Conditions[] = "(T.StartedBy = {$User['Id']} AND T.Status = 'done')";

$Rows = [];
if (!empty($Conditions)) {
  $Sql = "SELECT T.*, TIMESTAMPDIFF(SECOND, T.StartedAt, NOW()) AS ElapsedSec, U.Login AS AddedByLogin, S.Login AS StartedByLogin
          FROM Tasks T JOIN Users U ON U.Id=T.AddedBy LEFT JOIN Users S ON S.Id=T.StartedBy
          WHERE " . implode(' OR ', $Conditions) . " ORDER BY $OrderSql";
  $Rows = $Db->query($Sql)->fetch_all(MYSQLI_ASSOC);
}

$StatusLabels = [
  'pending' => ['Czekające na akceptację', 'badge-pending'],
  'todo' => ['Do zrobienia', 'badge-todo'],
  'in_progress' => ['W trakcie realizacji', 'badge-progress'],
  'awaiting_merge' => ['Czekające na dodanie', 'badge-awaiting'],
  'done' => ['Zrealizowane', 'badge-done'],
  'abandoned' => ['Porzucone', 'badge-abandoned'],
  'other' => ['Inne', 'badge-other'],
];

function task_json_mine($T, $UserId) {
  $IsMine = in_array($T['Status'], ['in_progress', 'awaiting_merge'], true) && (int)($T['StartedBy'] ?? 0) === (int)$UserId;
  return task_json_attr($T, $IsMine);
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Moje zadania</title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<header class="topbar">
  <div class="topbar-left">
    <a href="index.php" class="brand">Call of the void</a>
    <nav class="mainnav">
      <a href="tasks.php">Zadania</a>
      <a href="my_tasks.php" class="active">Moje zadania</a>
      <a href="history.php">Historia</a>
      <a href="graph.php">Graf commitów</a>
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
  <h1>Moje zadania</h1>
  <div class="sort-controls">
    <select id="sortColSelect" onchange="updateSortFields()">
      <?php foreach (['CreatedAt' => 'Data dodania', 'StartedAt' => 'Data rozpoczęcia', 'FinishedAt' => 'Data zakończenia', 'Title' => 'Tytuł', 'Priority' => 'Waga'] as $Val => $Label): ?>
        <option value="<?= $Val ?>" <?= $Val === $SortCol ? 'selected' : '' ?>><?= $Label ?></option>
      <?php endforeach; ?>
    </select>
    <select id="sortDirSelect" onchange="updateSortFields()">
      <option value="ASC" <?= $SortDir === 'ASC' ? 'selected' : '' ?>>Rosnąco</option>
      <option value="DESC" <?= $SortDir === 'DESC' ? 'selected' : '' ?>>Malejąco</option>
    </select>
  </div>
</div>

<form method="get" class="filters" id="filterForm">
  <label><input type="checkbox" name="proposed" value="1" <?= $ShowProposed ? 'checked' : '' ?> onchange="this.form.submit()"> Zaproponowane przeze mnie</label>
  <label><input type="checkbox" name="inprogress" value="1" <?= $ShowInProgress ? 'checked' : '' ?> onchange="this.form.submit()"> Realizowane przeze mnie</label>
  <label><input type="checkbox" name="awaiting" value="1" <?= $ShowAwaiting ? 'checked' : '' ?> onchange="this.form.submit()"> Czekające na dodanie (moje)</label>
  <label><input type="checkbox" name="done" value="1" <?= $ShowDone ? 'checked' : '' ?> onchange="this.form.submit()"> Zrealizowane przeze mnie</label>
  <input type="hidden" name="filters" value="1">
  <input type="hidden" name="sort" value="<?= htmlspecialchars($SortCol) ?>">
  <input type="hidden" name="dir" value="<?= htmlspecialchars($SortDir) ?>">
</form>

<div>
<?php foreach ($Rows as $T): [$Label, $Badge] = $StatusLabels[$T['Status']]; ?>
  <div class="task-row" data-task="<?= task_json_mine($T, $User['Id']) ?>" onclick="openTaskModal(this)">
    <div><strong><?= htmlspecialchars($T['Title']) ?></strong> <?= priority_badge_html($T['Priority']) ?><br><span style="color:var(--muted); font-size:0.8rem;"><?= htmlspecialchars(short_text($T['Description'], 90)) ?></span></div>
    <div>Dodał: <?= htmlspecialchars($T['AddedByLogin']) ?></div>
    <div><?= $T['StartedByLogin'] ? 'Realizuje: ' . htmlspecialchars($T['StartedByLogin']) : '—' ?>
      <?= timer_html($T, 5, 'font-size:0.75rem;') ?>
    </div>
    <div>
      <?= $T['StartedAt'] ? 'Start: ' . date('d.m.Y', strtotime($T['StartedAt'])) . '<br>' : '' ?>
      <?= $T['FinishedAt'] ? 'Koniec: ' . date('d.m.Y', strtotime($T['FinishedAt'])) : '' ?>
      <?= (!$T['StartedAt'] && !$T['FinishedAt']) ? date('d.m.Y', strtotime($T['CreatedAt'])) : '' ?>
    </div>
    <div><span class="badge <?= $Badge ?>"><?= $Label ?></span></div>
  </div>
<?php endforeach; ?>
<?php if (empty($Rows)): ?><p class="modal-info">Brak zadań spełniających wybrane filtry.</p><?php endif; ?>
</div>

<div class="modal-overlay" id="taskModalOverlay">
  <div class="modal">
    <span class="modal-close" onclick="closeTaskModal()">&times;</span>
    <h2 id="taskModalTitle"></h2>
    <div class="modal-info" id="taskModalMeta"></div>
    <div class="modal-desc" id="taskModalDesc"></div>
    <div id="taskModalProgress"></div>
    <div class="modal-actions" id="taskModalActions"></div>
  </div>
</div>

<script src="assets/js/app.js"></script>
<script>
function updateSortFields() {
  var Form = document.getElementById('filterForm');
  Form.sort.value = document.getElementById('sortColSelect').value;
  Form.dir.value = document.getElementById('sortDirSelect').value;
  Form.submit();
}
</script>

</main>
</body>
</html>
