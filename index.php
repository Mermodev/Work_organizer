<?php
require_once __DIR__ . '/common.php';
$User = current_user();

$Counts = get_status_counts();
$Segments = [
  ['label' => 'Zrealizowane', 'key' => 'done', 'color' => '#3ecf8e', 'count' => $Counts['done']],
  ['label' => 'W trakcie realizacji', 'key' => 'in_progress', 'color' => '#e8b93f', 'count' => $Counts['in_progress']],
  ['label' => 'Czekające na dodanie', 'key' => 'awaiting_merge', 'color' => '#22d3ee', 'count' => $Counts['awaiting_merge']],
  ['label' => 'Porzucone / usunięte', 'key' => 'abandoned', 'color' => '#f0616d', 'count' => $Counts['abandoned'] + $Counts['deleted']],
  ['label' => 'Czekające na akceptację', 'key' => 'pending', 'color' => '#4fa8ff', 'count' => $Counts['pending']],
  ['label' => 'Inne', 'key' => 'other', 'color' => '#7c8697', 'count' => $Counts['other'] + $Counts['todo']],
];
$Total = array_sum(array_column($Segments, 'count'));
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Call of the Void task organizer</title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<header class="topbar">
  <div class="topbar-left">
    <a href="index.php" class="brand">Call of the Void</a>
    <?php if ($User): ?>
    <nav class="mainnav">
      <a href="tasks.php">Zadania</a>
      <a href="my_tasks.php">Moje zadania</a>
      <a href="history.php">Historia</a>
      <a href="graph.php">Graf commitów</a>
      <?php if ((int)$User['IsAdmin'] === 1): ?><a href="admin.php" class="admin-link">Panel administracji</a><?php endif; ?>
    </nav>
    <?php endif; ?>
  </div>
  <div class="topbar-right">
    <?php if ($User): ?>
      <span class="hello">Zalogowano jako <strong><?= htmlspecialchars($User['Login']) ?></strong></span>
      <a href="logout.php" class="btn btn-ghost">Wyloguj</a>
    <?php else: ?>
      <a href="register.php" class="btn btn-ghost">Zarejestruj</a>
      <a href="login.php" class="btn btn-primary">Zaloguj</a>
    <?php endif; ?>
  </div>
</header>
<main class="container">

<section class="hero">
  <h1>Call of the Void</h1>

  <div class="progressbar-wrap">
    <div class="progressbar">
      <?php foreach ($Segments as $Seg): $Pct = $Total > 0 ? round($Seg['count'] / $Total * 100, 2) : 0; ?>
        <div class="segment" style="width: <?= $Pct ?>%; background: <?= $Seg['color'] ?>;"
             data-label="<?= htmlspecialchars($Seg['label']) ?>" data-count="<?= $Seg['count'] ?>"></div>
      <?php endforeach; ?>
    </div>
    <div class="progressbar-ticks">
      <span style="left: 0%" data-tick="0"></span>
      <span style="left: 33.333%" data-tick="<?= (int)($Total / 3) ?>"></span>
      <span style="left: 66.666%" data-tick="<?= (int)($Total * 2 / 3) ?>"></span>
      <span style="left: 100%; transform: translateX(-100%);" data-tick="<?= $Total ?>"></span>
    </div>
  </div>

  <div class="legend">
    <?php foreach ($Segments as $Seg): ?>
      <div class="legend-item"><span class="legend-dot" style="background:<?= $Seg['color'] ?>; color:<?= $Seg['color'] ?>;"></span><?= htmlspecialchars($Seg['label']) ?> (<?= $Seg['count'] ?>)</div>
    <?php endforeach; ?>
  </div>

  <?php if ($User):
    $Db = get_db();
    $Stmt = $Db->prepare("SELECT
      SUM(CASE WHEN AddedBy=? THEN 1 ELSE 0 END) AS Proposed,
      SUM(CASE WHEN StartedBy=? AND Status='in_progress' THEN 1 ELSE 0 END) AS Doing,
      SUM(CASE WHEN StartedBy=? AND Status='done' THEN 1 ELSE 0 END) AS Done
      FROM Tasks");
    $Stmt->bind_param('iii', $User['Id'], $User['Id'], $User['Id']);
    $Stmt->execute();
    $My = $Stmt->get_result()->fetch_assoc();
  ?>
  <div class="mini-stats">
    <div class="mini-stat"><div class="num"><?= (int)$My['Proposed'] ?></div><div class="lbl">Twoje propozycje</div></div>
    <div class="mini-stat"><div class="num"><?= (int)$My['Doing'] ?></div><div class="lbl">Realizujesz teraz</div></div>
    <div class="mini-stat"><div class="num"><?= (int)$My['Done'] ?></div><div class="lbl">Ukończone przez Ciebie</div></div>
  </div>
  <?php endif; ?>
</section>

</main>
</body>
</html>
