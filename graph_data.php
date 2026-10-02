<?php
// graph_data.php - JSON dla grafu commitów (graph.php).
// Serwer sam pyta GitHub API tokenem z common.php, więc prywatne repo działa, a token nie trafia do przeglądarki.
// Wynik ląduje w cache w katalogu tymczasowym systemu (NIE w katalogu projektu - ten jest serwowany przez www).
require_once __DIR__ . '/common.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function graph_fail($Http, $Kind, $Message, $Hint = '') {
  http_response_code($Http);
  echo json_encode(['error' => $Message, 'kind' => $Kind, 'hint' => $Hint], JSON_UNESCAPED_UNICODE);
  exit;
}

if (!current_user()) graph_fail(401, 'auth', 'Sesja wygasła - zaloguj się ponownie.');

// podgląd na danych przykładowych (działa bez tokena)
if (!empty($_GET['demo'])) {
  readfile(__DIR__ . '/tools/graph_demo.json');
  exit;
}

if (GithubToken === '') {
  graph_fail(503, 'no_token', 'Serwer nie ma tokena GitHub.',
    'Repozytorium jest prywatne. Ustaw zmienną GITHUB_TOKEN (np. GITHUB_TOKEN=github_pat_... ./start.sh) albo wpisz token w common.php (GithubToken).');
}

// ---------------- cache ----------------

$CacheFile = sys_get_temp_dir() . '/cotv_graph_' . md5(GithubRepo) . '.json';
$CacheAge = is_file($CacheFile) ? time() - filemtime($CacheFile) : PHP_INT_MAX;
$Force = !empty($_GET['refresh']);
// "Odśwież" omija cache, ale nie częściej niż co 15 s (ochrona limitu GitHub API)
$MaxAge = $Force ? 45 : GithubCacheSeconds;

if ($CacheAge < $MaxAge) {
  $Cached = json_decode(file_get_contents($CacheFile), true);
  if (is_array($Cached)) {
    $Cached['cached'] = true;
    echo json_encode($Cached, JSON_UNESCAPED_UNICODE);
    exit;
  }
}

// ---------------- GitHub API ----------------

// zwraca zdekodowany JSON; błędy zamienia na czytelny komunikat (graph_fail)
// $OnError: gdy podane, zamiast kończyć skrypt zwraca null (np. dla tagów, które są dodatkiem)
function github_api($Path, $OnError = false) {
  $Url = GithubApi . $Path;
  $Headers = [
    'Authorization: Bearer ' . GithubToken,
    'Accept: application/vnd.github+json',
    'X-GitHub-Api-Version: 2022-11-28',
    'User-Agent: CallOfTheVoid-TaskOrganizer',
  ];

  $Status = 0;
  $Body = false;
  if (function_exists('curl_init')) {
    $Ch = curl_init($Url);
    curl_setopt_array($Ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_HTTPHEADER => $Headers]);
    $Body = curl_exec($Ch);
    $Status = (int)curl_getinfo($Ch, CURLINFO_RESPONSE_CODE);
    curl_close($Ch);
  } else {
    $Ctx = stream_context_create(['http' => ['method' => 'GET', 'header' => implode("\r\n", $Headers), 'timeout' => 25, 'ignore_errors' => true]]);
    $Body = @file_get_contents($Url, false, $Ctx);
    if (!empty($http_response_header[0]) && preg_match('~\s(\d{3})\s~', $http_response_header[0], $M)) $Status = (int)$M[1];
  }

  if ($Body === false || $Status === 0) {
    if ($OnError) return null;
    graph_fail(502, 'no_http', 'Nie udało się połączyć z GitHubem.',
      'Sprawdź połączenie z internetem. Jeśli nie ma włączonych rozszerzeń PHP, odkomentuj w php.ini: extension=curl oraz extension=openssl.');
  }

  $Json = json_decode($Body, true);
  if ($Status >= 200 && $Status < 300 && $Json !== null) return $Json;
  if ($OnError) return null;

  $Msg = is_array($Json) ? ($Json['message'] ?? '') : '';
  if ($Status === 401) graph_fail(502, 'bad_token', 'GitHub odrzucił token (401).', 'Token jest błędny, wygasł albo został cofnięty. Wygeneruj nowy.');
  if ($Status === 403 || $Status === 429) {
    if (stripos($Msg, 'rate limit') !== false || $Status === 429) graph_fail(503, 'rate_limit', 'Przekroczono limit zapytań do GitHub API.', 'Odczekaj kilka minut i spróbuj ponownie (limit GitHub odnawia się co godzinę).');
    graph_fail(502, 'forbidden', 'GitHub odmówił dostępu (403).', 'Token nie ma uprawnień do tego repozytorium. Potrzebne: Contents: Read-only. ' . $Msg);
  }
  if ($Status === 404) graph_fail(502, 'not_found', 'GitHub nie widzi repozytorium ' . GithubRepo . ' (404).',
    'Dla prywatnych repo GitHub zwraca 404, gdy token nie ma do nich dostępu. Sprawdź, czy token ma wybrane właśnie to repo (a dla repo organizacji - czy organizacja go zatwierdziła).');
  graph_fail(502, 'github_error', 'GitHub zwrócił błąd ' . $Status . '.', $Msg);
}

function map_commit($C) {
  return [
    'sha' => $C['sha'],
    'parents' => array_map(function ($P) { return $P['sha']; }, $C['parents'] ?? []),
    'msg' => mb_substr($C['commit']['message'] ?? '', 0, 4000),
    'author' => $C['commit']['author']['name'] ?? '',
    'login' => $C['author']['login'] ?? null,
    'avatar' => $C['author']['avatar_url'] ?? null,
    'date' => $C['commit']['committer']['date'] ?? ($C['commit']['author']['date'] ?? null),
    'adate' => $C['commit']['author']['date'] ?? null,
  ];
}

$Repo = github_api('/repos/' . GithubRepo);
$DefaultBranch = $Repo['default_branch'] ?? 'main';

// wszystkie branche (domyślny pierwszy)
$Branches = [];
for ($Page = 1; $Page <= 5; $Page++) {
  $Chunk = github_api('/repos/' . GithubRepo . '/branches?per_page=100&page=' . $Page);
  foreach ($Chunk as $B) {
    $Branches[] = ['name' => $B['name'], 'sha' => $B['commit']['sha'], 'default' => $B['name'] === $DefaultBranch, 'protected' => !empty($B['protected'])];
  }
  if (count($Chunk) < 100) break;
}
usort($Branches, function ($A, $B) {
  if ($A['default'] !== $B['default']) return $A['default'] ? -1 : 1;
  return strcasecmp($A['name'], $B['name']);
});

// tagi (dodatek - jak się nie uda, graf działa bez nich)
$Tags = [];
$TagChunk = github_api('/repos/' . GithubRepo . '/tags?per_page=100', true);
foreach ($TagChunk ?: [] as $T) $Tags[] = ['name' => $T['name'], 'sha' => $T['commit']['sha']];

// commity: najpierw domyślny branch (do 2000 commitów), potem każdy inny tylko do miejsca,
// w którym wchodzi w historię, którą już mamy - dzięki temu ~2 zapytania na branch zamiast pobierania wszystkiego od nowa
$Commits = [];
$Truncated = false;
$MaxPagesDefault = 20;
foreach ($Branches as $B) {
  if (isset($Commits[$B['sha']])) continue;   // czubek tego brancha już znamy (np. wcześniej scalony)
  $IsDefault = $B['default'];
  $PerPage = $IsDefault ? 300 : 150;
  $MaxPages = $IsDefault ? $MaxPagesDefault : 6;

  for ($Page = 1; $Page <= $MaxPages; $Page++) {
    $Chunk = github_api('/repos/' . GithubRepo . '/commits?sha=' . rawurlencode($B['name']) . '&per_page=' . $PerPage . '&page=' . $Page);
    $NewInPage = 0;
    foreach ($Chunk as $C) {
      if (!isset($Commits[$C['sha']])) { $Commits[$C['sha']] = map_commit($C); $NewInPage++; }
    }
    if(count($Chunk) < $PerPage) break;                 // koniec historii
    if(!$IsDefault && $NewInPage === 0) break;          // cała strona już znana -> reszta też
    if($IsDefault && $Page === $MaxPages) $Truncated = true;
  }
}

$Data = [
  'repo' => GithubRepo,
  'url' => 'https://github.com/' . GithubRepo,
  'default_branch' => $DefaultBranch,
  'fetched_at' => time(),
  'cached' => false,
  'truncated' => $Truncated,
  'branches' => $Branches,
  'tags' => $Tags,
  'commits' => array_values($Commits),
];

$Json = json_encode($Data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
$Tmp = $CacheFile . '.' . getmypid();
if (file_put_contents($Tmp, $Json) !== false) rename($Tmp, $CacheFile);
echo $Json;
