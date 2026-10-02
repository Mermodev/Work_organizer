<?php
require_once __DIR__ . '/common.php';
require_login();
$User = current_user();

$Demo = !empty($_GET['demo']);
$DataUrl = 'graph_data.php' . ($Demo ? '?demo=1' : '');
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Repozytorium</title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="graph-page">
<header class="topbar">
  <div class="topbar-left">
    <a href="index.php" class="brand">Call of the Void</a>
    <nav class="mainnav">
      <a href="tasks.php">Zadania</a>
      <a href="my_tasks.php">Moje zadania</a>
      <a href="history.php">Historia</a>
      <a href="graph.php" class="active">Repozytorium</a>
      <?php if ((int)$User['IsAdmin'] === 1): ?><a href="admin.php" class="admin-link">Panel administracji</a><?php endif; ?>
    </nav>
  </div>
  <div class="topbar-right">
    <span class="hello">Zalogowano jako <strong><?= htmlspecialchars($User['Login']) ?></strong></span>
    <a href="logout.php" class="btn btn-ghost">Wyloguj</a>
  </div>
</header>
<main class="container graph-container">

<div class="board-toolbar graph-toolbar">
  <div class="graph-title">
    <h1>Graf commitów</h1>
    <a id="gRepo" class="repo-pill" href="#" target="_blank" rel="noopener"><?= htmlspecialchars($Demo ? 'dane przykładowe' : GithubRepo) ?></a>
    <?php if ($Demo): ?><span class="demo-badge">PODGLĄD DEMO</span><?php endif; ?>
  </div>
  <div class="graph-stats" id="gStats"></div>
  <div class="graph-controls">
    <input type="search" id="gSearch" placeholder="Szukaj: opis, autor, sha, branch…  ( / )" autocomplete="off">
    <select id="gAuthor"><option value="">Wszyscy autorzy</option></select>
    <button type="button" class="btn btn-ghost btn-sm" id="gCompact" title="Gęstszy widok">Gęsto</button>
    <button type="button" class="btn btn-ghost btn-sm" id="gLineage" title="Podświetlaj przodków i potomków zaznaczonego commita" aria-pressed="true">Linia commita</button>
    <button type="button" class="btn btn-primary btn-sm" id="gRefresh">Odśwież</button>
  </div>
</div>

<div class="graph-layout" id="gLayout">
  <aside class="graph-side" id="gSide">
    <div class="side-head">
      <strong>Branche</strong>
      <span class="side-actions">
        <button type="button" class="link-btn" id="gAll">wszystkie</button>
        <button type="button" class="link-btn" id="gOnlyDefault">tylko domyślny</button>
      </span>
    </div>
    <input type="search" id="gBranchFilter" placeholder="Filtruj branche…" autocomplete="off">
    <div class="branch-list" id="gBranches"></div>
    <div class="side-foot" id="gFetched"></div>
  </aside>

  <section class="graph-panel">
    <div class="graph-scroll" id="gScroll">
      <div class="graph-colhead" id="gColHead">
        <span class="c-graph">Graf</span><span class="c-msg">Opis</span><span class="c-author">Autor</span><span class="c-date">Data</span><span class="c-sha">SHA</span>
      </div>
      <div class="graph-canvas" id="gCanvas">
        <div class="graph-state" id="gState"></div>
      </div>
    </div>
  </section>

  <aside class="graph-detail" id="gDetail" aria-hidden="true"></aside>
</div>

</main>
<script>window.GraphConfig = { dataUrl: <?= json_encode($DataUrl) ?>, demo: <?= $Demo ? 'true' : 'false' ?> };</script>
<script src="assets/js/graph.js"></script>
</body>
</html>
