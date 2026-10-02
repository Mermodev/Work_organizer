<?php
require_once __DIR__ . '/common.php';
require_login();
$User = current_user();
$Db = get_db();

// ---- akcje (POST) - wszystko na jednej stronie, bez osobnych ajaxów ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $Action = $_POST['action'] ?? '';
  $Id = (int)($_POST['id'] ?? 0);

  if ($Action === 'add') {
    $Title = trim($_POST['title'] ?? '');
    $Description = trim($_POST['description'] ?? '');
    $Minutes = read_duration_minutes('exp');
    $Priority = read_priority();
    if ($Title !== '' && $Description !== '') {
      $Stmt = $Db->prepare('INSERT INTO Tasks (Title, Description, Status, AddedBy, ExpectedMinutes, Priority) VALUES (?, ?, "pending", ?, ?, ?)');
      $Stmt->bind_param('ssiii', $Title, $Description, $User['Id'], $Minutes, $Priority);
      $Stmt->execute();
      // wpis "dodanie zadania" trafia do historii dopiero po akceptacji przez admina (admin.php)
    }
  }

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

  // odłożenie zadania - wraca do puli dostępnych (todo), NIE do porzuconych; można odłożyć
  // zarówno z realizacji, jak i z "czeka na dodanie" (np. ktoś porzucił swój branch)
  if ($Action === 'abandon') {
    $Row = $Db->query("SELECT Status, StartedBy FROM Tasks WHERE Id = $Id")->fetch_assoc();
    if ($Row && in_array($Row['Status'], ['in_progress', 'awaiting_merge'], true) && (int)$Row['StartedBy'] === (int)$User['Id']) {
      $Stmt = $Db->prepare("UPDATE Tasks SET Status='todo', StartedBy=NULL, StartedAt=NULL WHERE Id=?");
      $Stmt->bind_param('i', $Id);
      $Stmt->execute();
      log_history($Id, $User['Id'], 'abandoned', 'Zadanie odłożone, wraca do puli dostępnych');
    }
  }

  // autor może wycofać własne zgłoszenie, dopóki admin go nie rozpatrzył (nie ma jeszcze wpisu w historii)
  if ($Action === 'cancel') {
    $Stmt = $Db->prepare("DELETE FROM Tasks WHERE Id=? AND Status='pending' AND AddedBy=?");
    $Stmt->bind_param('ii', $Id, $User['Id']);
    $Stmt->execute();
  }

  header('Location: tasks.php');
  exit;
}

// ---- sortowanie i wyszukiwanie ----
$AllowedCols = ['CreatedAt', 'Title', 'Login', 'Priority'];
$S1 = in_array($_GET['s1'] ?? '', $AllowedCols, true) ? $_GET['s1'] : 'CreatedAt';
$D1 = ($_GET['d1'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
$S2 = in_array($_GET['s2'] ?? '', $AllowedCols, true) ? $_GET['s2'] : 'CreatedAt';
$D2 = ($_GET['d2'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
$S3 = in_array($_GET['s3'] ?? '', $AllowedCols, true) ? $_GET['s3'] : 'CreatedAt';
$D3 = ($_GET['d3'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
$S4 = in_array($_GET['s4'] ?? '', $AllowedCols, true) ? $_GET['s4'] : 'CreatedAt';
$D4 = ($_GET['d4'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
$Search = trim($_GET['q'] ?? '');
$SearchLike = '%' . $Db->real_escape_string($Search) . '%';
$MinPriority = isset($_GET['minp']) && ctype_digit($_GET['minp']) ? min(10, max(1, (int)$_GET['minp'])) : 0;

// $Dir jest już zwalidowane (ASC/DESC). Przy sortowaniu po wadze zadania bez wagi (NULL)
// zawsze lądują na końcu, niezależnie od kierunku.
function sort_expr_tasks($Col, $Dir) {
  if ($Col === 'Priority') return "T.Priority IS NULL, T.Priority $Dir, T.CreatedAt DESC";
  $Expr = $Col === 'Login' ? 'AddedByLogin' : ($Col === 'Title' ? 'T.Title' : 'T.CreatedAt');
  return "$Expr $Dir";
}

$SearchSql = $Search !== '' ? " AND T.Title LIKE '$SearchLike'" : '';
$SearchSql .= $MinPriority > 0 ? " AND T.Priority >= $MinPriority" : '';

$Sql1 = "SELECT T.*, U.Login AS AddedByLogin FROM Tasks T JOIN Users U ON U.Id=T.AddedBy
         WHERE (T.Status='todo' OR (T.Status='pending' AND T.AddedBy={$User['Id']})) $SearchSql
         ORDER BY " . sort_expr_tasks($S1, $D1);
$Col1 = $Db->query($Sql1)->fetch_all(MYSQLI_ASSOC);

$Sql2 = "SELECT T.*, TIMESTAMPDIFF(SECOND, T.StartedAt, NOW()) AS ElapsedSec, U.Login AS AddedByLogin, S.Login AS StartedByLogin FROM Tasks T
         JOIN Users U ON U.Id=T.AddedBy JOIN Users S ON S.Id=T.StartedBy
         WHERE T.Status='in_progress' $SearchSql
         ORDER BY " . str_replace('AddedByLogin', 'StartedByLogin', sort_expr_tasks($S2, $D2));
$Col2 = $Db->query($Sql2)->fetch_all(MYSQLI_ASSOC);

$Sql2b = "SELECT T.*, TIMESTAMPDIFF(SECOND, T.StartedAt, NOW()) AS ElapsedSec, U.Login AS AddedByLogin, S.Login AS StartedByLogin FROM Tasks T
         JOIN Users U ON U.Id=T.AddedBy JOIN Users S ON S.Id=T.StartedBy
         WHERE T.Status='awaiting_merge' $SearchSql
         ORDER BY " . str_replace('AddedByLogin', 'StartedByLogin', sort_expr_tasks($S4, $D4));
$Col2b = $Db->query($Sql2b)->fetch_all(MYSQLI_ASSOC);

$Sql3 = "SELECT T.*, U.Login AS AddedByLogin, S.Login AS StartedByLogin FROM Tasks T
         JOIN Users U ON U.Id=T.AddedBy JOIN Users S ON S.Id=T.StartedBy
         WHERE T.Status='done' $SearchSql
         ORDER BY " . str_replace('AddedByLogin', 'StartedByLogin', sort_expr_tasks($S3, $D3));
$Col3 = $Db->query($Sql3)->fetch_all(MYSQLI_ASSOC);

function sort_select($Name, $DirName, $CurCol, $CurDir) {
  $Opts = ['CreatedAt' => 'Data', 'Title' => 'Tytuł', 'Login' => 'Użytkownik', 'Priority' => 'Waga'];
  $Html = "<select onchange=\"applySort()\" id=\"$Name\">";
  foreach ($Opts as $Val => $Label) $Html .= "<option value=\"$Val\" " . ($Val === $CurCol ? 'selected' : '') . ">$Label</option>";
  $Html .= '</select>';
  $Html .= "<select onchange=\"applySort()\" id=\"$DirName\">";
  $Html .= '<option value="ASC" ' . ($CurDir === 'ASC' ? 'selected' : '') . '>Rosnąco</option>';
  $Html .= '<option value="DESC" ' . ($CurDir === 'DESC' ? 'selected' : '') . '>Malejąco</option>';
  $Html .= '</select>';
  return $Html;
}

function task_json($T, $IsMine) {
  global $User;
  return task_json_attr($T, $IsMine, $User['Id']);
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Zadania</title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<header class="topbar">
  <div class="topbar-left">
    <a href="index.php" class="brand">Call of the Void</a>
    <nav class="mainnav">
      <a href="tasks.php" class="active">Zadania</a>
      <a href="my_tasks.php">Moje zadania</a>
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
  <h1>Zadania</h1>
  <form method="get" class="search-box">
    <input type="text" name="q" placeholder="Szukaj po tytule..." value="<?= htmlspecialchars($Search) ?>">
    <select name="minp" onchange="this.form.submit()" title="Pokaż tylko od podanej wagi wzwyż">
      <option value="0">Dowolna waga</option>
      <?php for ($P = 1; $P <= 10; $P++): ?>
        <option value="<?= $P ?>" <?= $MinPriority === $P ? 'selected' : '' ?>>Waga &ge; <?= $P ?></option>
      <?php endfor; ?>
    </select>
    <button class="btn btn-ghost btn-sm">Filtruj</button>
  </form>
  <button class="btn btn-primary" onclick="openAddTaskModal()">+ Dodaj zadanie</button>
</div>

<div class="board">

  <div class="board-col">
    <h2><span class="dot" style="background:var(--gray); color:var(--gray);"></span>Do zrobienia<span class="count"><?= count($Col1) ?></span></h2>
    <div class="sort-controls"><?= sort_select('s1', 'd1', $S1, $D1) ?></div>
    <div style="margin-top:10px;">
    <?php foreach ($Col1 as $T): $Unapproved = $T['Status'] === 'pending'; ?>
      <div class="task-card col-todo <?= $Unapproved ? 'unapproved' : '' ?>" data-task="<?= task_json($T, false) ?>" onclick="openTaskModal(this)">
        <div class="task-title"><?= htmlspecialchars($T['Title']) ?> <?= priority_badge_html($T['Priority']) ?></div>
        <div class="task-desc"><?= htmlspecialchars(short_text($T['Description'], 70)) ?></div>
        <div class="task-meta"><?= $Unapproved ? 'Oczekuje na akceptację &middot; ' : '' ?>dodał: <?= htmlspecialchars($T['AddedByLogin']) ?></div>
      </div>
    <?php endforeach; ?>
    <?php if (empty($Col1)): ?><p class="modal-info">Brak zadań.</p><?php endif; ?>
    </div>
  </div>

  <div class="board-col">
    <h2><span class="dot" style="background:var(--yellow); color:var(--yellow);"></span>W trakcie realizacji<span class="count"><?= count($Col2) ?></span></h2>
    <div class="sort-controls"><?= sort_select('s2', 'd2', $S2, $D2) ?></div>
    <div style="margin-top:10px;">
    <?php foreach ($Col2 as $T):
      $IsMine = (int)$T['StartedBy'] === (int)$User['Id'];
    ?>
      <div class="task-card col-progress" data-task="<?= task_json($T, $IsMine) ?>" onclick="openTaskModal(this)">
        <div class="task-title"><?= htmlspecialchars($T['Title']) ?> <?= priority_badge_html($T['Priority']) ?></div>
        <div class="task-desc"><?= htmlspecialchars(short_text($T['Description'], 70)) ?></div>
        <div class="task-meta">
          <?= htmlspecialchars($T['StartedByLogin']) ?> &middot; od <?= date('d.m.Y', strtotime($T['StartedAt'])) ?>
          <?= timer_html($T, 6) ?>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (empty($Col2)): ?><p class="modal-info">Brak zadań.</p><?php endif; ?>
    </div>
  </div>

  <div class="board-col">
    <h2><span class="dot" style="background:var(--cyan); color:var(--cyan);"></span>Czekające na dodanie<span class="count"><?= count($Col2b) ?></span></h2>
    <div class="sort-controls"><?= sort_select('s4', 'd4', $S4, $D4) ?></div>
    <div style="margin-top:10px;">
    <?php foreach ($Col2b as $T):
      $IsMine = (int)$T['StartedBy'] === (int)$User['Id'];
    ?>
      <div class="task-card col-awaiting" data-task="<?= task_json($T, $IsMine) ?>" onclick="openTaskModal(this)">
        <div class="task-title"><?= htmlspecialchars($T['Title']) ?> <?= priority_badge_html($T['Priority']) ?></div>
        <div class="task-desc"><?= htmlspecialchars(short_text($T['Description'], 70)) ?></div>
        <div class="task-meta"><?= htmlspecialchars($T['StartedByLogin']) ?> &middot; od <?= date('d.m.Y', strtotime($T['StartedAt'])) ?></div>
      </div>
    <?php endforeach; ?>
    <?php if (empty($Col2b)): ?><p class="modal-info">Brak zadań.</p><?php endif; ?>
    </div>
  </div>

  <div class="board-col">
    <h2><span class="dot" style="background:var(--green); color:var(--green);"></span>Zrealizowane<span class="count"><?= count($Col3) ?></span></h2>
    <div class="sort-controls"><?= sort_select('s3', 'd3', $S3, $D3) ?></div>
    <div style="margin-top:10px;">
    <?php foreach ($Col3 as $T): ?>
      <div class="task-card col-done" data-task="<?= task_json($T, false) ?>" onclick="openTaskModal(this)">
        <div class="task-title"><?= htmlspecialchars($T['Title']) ?> <?= priority_badge_html($T['Priority']) ?></div>
        <div class="task-desc"><?= htmlspecialchars(short_text($T['Description'], 70)) ?></div>
        <div class="task-meta"><?= htmlspecialchars($T['StartedByLogin']) ?> &middot; <?= $T['FinishedAt'] ? date('d.m.Y', strtotime($T['FinishedAt'])) : '' ?></div>
      </div>
    <?php endforeach; ?>
    <?php if (empty($Col3)): ?><p class="modal-info">Brak zadań.</p><?php endif; ?>
    </div>
  </div>

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

<div class="modal-overlay" id="addTaskModalOverlay">
  <div class="modal">
    <span class="modal-close" onclick="closeAddTaskModal()">&times;</span>
    <h2>Zaproponuj nowe zadanie</h2>
    <p class="modal-info">Zadanie trafi do akceptacji administratora.</p>
    <form method="post">
      <input type="hidden" name="action" value="add">
      <div class="field"><label>Tytuł</label><input type="text" name="title" required></div>
      <div class="field"><label>Opis</label><textarea name="description" class="edit-desc" required></textarea></div>
      <div class="field"><label>Oczekiwany czas realizacji (opcjonalnie)</label><?= duration_fields_html('exp') ?></div>
      <div class="field"><label>Waga 1-10 (opcjonalnie)</label><?= priority_input_html() ?></div>
      <div class="modal-actions"><button class="btn btn-primary">Wyślij do akceptacji</button></div>
    </form>
  </div>
</div>

<script>
function applySort() {
  var P = new URLSearchParams(window.location.search);
  ['s1','d1','s2','d2','s3','d3','s4','d4'].forEach(function(Id) {
    var El = document.getElementById(Id);
    if (El) P.set(Id, El.value);
  });
  window.location.search = P.toString();
}
</script>
<script src="assets/js/app.js"></script>

</main>
</body>
</html>
