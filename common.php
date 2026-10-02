<?php
// common.php - połączenie do bazy, własne hashe, sesja, drobne helpery.
// Wszystko w jednym pliku celowo - projekt ma być prosty, nie "enterprise".

session_start();

// ---------------- config ----------------
define('DbHost', '127.0.0.1');
define('DbUser', 'task_manager');
define('DbPass', 'zmien_to_haslo');
define('DbName', 'TaskManager');
define('CaptchaDir', __DIR__ . '/Bot_veryfication');
define('PasswordSalt', 'zmien-na-losowy-losowy-ciag-znakow');

// graf commitów (graph.php + graph_data.php). Repo jest PRYWATNE, więc serwer musi mieć token.
// Token zostaje tylko po stronie PHP - przeglądarka nigdy go nie widzi (graph_data.php robi proxy).
// Najlepiej podać go zmienną środowiskową:  GITHUB_TOKEN=github_pat_xxx ./start.sh
// (albo wpisać tu zamiast pustego ''). Fine-grained token: tylko to repo, uprawnienie Contents: Read-only.
define('GithubRepo', 'Mermodev/Call-of-the-void-private');
define('GithubToken', getenv('GITHUB_TOKEN') ?: '');
define('GithubApi', getenv('GITHUB_API_URL') ?: 'https://api.github.com');
define('GithubCacheSeconds', 90);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$Db = new mysqli(DbHost, DbUser, DbPass, DbName);
$Db->set_charset('utf8mb4');

function get_db() {
  global $Db;
  return $Db;
}

// ---------------- hash własny (polynomial rolling hash, jak w cp) ----------------

function poly_hash($Text, $Base, $Mod) {
  $Hash = 0;
  $Len = strlen($Text);
  for ($I = 0; $I < $Len; $I++) {
    $Hash = ($Hash * $Base + (ord($Text[$I]) + 1)) % $Mod;
  }
  return $Hash;
}

function double_poly_hash($Text, $Base1, $Mod1, $Base2, $Mod2) {
  $A = poly_hash($Text, $Base1, $Mod1);
  $B = poly_hash($Text, $Base2, $Mod2);
  $Combined = $A * $Mod2 + $B;
  return str_pad(dechex($Combined), 16, '0', STR_PAD_LEFT);
}

function hash1($Text) {
  return double_poly_hash($Text, 131, 1000000007, 137, 998244353);
}

function hash2($Text) {
  return double_poly_hash($Text, 911382323, 972663749, 1000000009, 999999937);
}

function hash_password($Password, $Login) {
  $Salted = PasswordSalt . '|' . strtolower($Login) . '|' . $Password;
  return [hash1($Salted), hash2($Salted)];
}

function verify_password($Password, $Login, $Hash1, $Hash2) {
  [$C1, $C2] = hash_password($Password, $Login);
  return hash_equals($Hash1, $C1) && hash_equals($Hash2, $C2);
}

function hash_captcha_text($Text) {
  return hash1(trim(mb_strtolower($Text)));
}

// ---------------- sesja / auth ----------------

function current_user() {
  static $Cached = null;
  static $Done = false;
  if ($Done) return $Cached;
  $Done = true;
  if (!isset($_SESSION['UserId'])) return null;
  $Db = get_db();
  $Stmt = $Db->prepare('SELECT Id, Login, IsAdmin FROM Users WHERE Id = ?');
  $Stmt->bind_param('i', $_SESSION['UserId']);
  $Stmt->execute();
  $Cached = $Stmt->get_result()->fetch_assoc();
  return $Cached;
}

function require_login() {
  if (!current_user()) {
    header('Location: login.php');
    exit;
  }
}

function require_admin() {
  require_login();
  $U = current_user();
  if ((int)$U['IsAdmin'] !== 1) {
    http_response_code(403);
    die('Brak uprawnień administratora.');
  }
}

// ---------------- drobne helpery ----------------

function password_strength_errors($Password) {
  $Errors = [];
  if (strlen($Password) < 8) $Errors[] = 'Hasło musi mieć co najmniej 8 znaków.';
  if (!preg_match('/[a-z]/', $Password)) $Errors[] = 'Hasło musi zawierać małą literę.';
  if (!preg_match('/[A-Z]/', $Password)) $Errors[] = 'Hasło musi zawierać wielką literę.';
  if (!preg_match('/[0-9]/', $Password)) $Errors[] = 'Hasło musi zawierać cyfrę.';
  if (!preg_match('/[^a-zA-Z0-9]/', $Password)) $Errors[] = 'Hasło musi zawierać znak specjalny.';
  return $Errors;
}

function pick_random_captcha() {
  $Files = glob(CaptchaDir . '/*.{png,jpg,jpeg,gif}', GLOB_BRACE);
  if (empty($Files)) return null;
  $Path = $Files[array_rand($Files)];
  return ['hash' => pathinfo($Path, PATHINFO_FILENAME), 'ext' => pathinfo($Path, PATHINFO_EXTENSION)];
}

function verify_captcha($ExpectedHash, $UserAnswer) {
  return hash_equals($ExpectedHash, hash_captcha_text($UserAnswer));
}

function log_history($TaskId, $UserId, $Action, $Details = null) {
  $Db = get_db();
  $Stmt = $Db->prepare('INSERT INTO TaskHistory (TaskId, UserId, Action, Details) VALUES (?, ?, ?, ?)');
  $Stmt->bind_param('iiss', $TaskId, $UserId, $Action, $Details);
  $Stmt->execute();
}

function short_text($Text, $Len = 60) {
  $Text = trim($Text);
  return mb_strlen($Text) > $Len ? mb_substr($Text, 0, $Len) . '…' : $Text;
}

// ---------------- czas realizacji: dni / godziny / minuty ----------------

function split_minutes($Minutes) {
  $Minutes = (int)$Minutes;
  return [intdiv($Minutes, 1440), intdiv($Minutes % 1440, 60), $Minutes % 60];
}

// czyta pola {Prefix}_days, {Prefix}_hours, {Prefix}_minutes z POST; zwraca minuty albo null gdy puste/0
function read_duration_minutes($Prefix) {
  $D = min(3650, max(0, (int)($_POST[$Prefix . '_days'] ?? 0)));
  $H = min(87600, max(0, (int)($_POST[$Prefix . '_hours'] ?? 0)));
  $M = min(5256000, max(0, (int)($_POST[$Prefix . '_minutes'] ?? 0)));
  $Total = min(5256000, $D * 1440 + $H * 60 + $M);
  return $Total > 0 ? $Total : null;
}

function format_duration($Minutes) {
  [$D, $H, $M] = split_minutes($Minutes);
  $Parts = [];
  if ($D > 0) $Parts[] = $D . 'd';
  if ($H > 0) $Parts[] = $H . 'h';
  if ($M > 0 || empty($Parts)) $Parts[] = $M . 'min';
  return implode(' ', $Parts);
}

function duration_fields_html($Prefix, $Minutes = null) {
  $D = $H = $M = '';
  if ($Minutes) [$D, $H, $M] = split_minutes($Minutes);
  $Html = '<div class="duration-fields">';
  $Html .= '<label><input type="number" name="' . $Prefix . '_days" min="0" max="3650" placeholder="0" value="' . $D . '"> dni</label>';
  $Html .= '<label><input type="number" name="' . $Prefix . '_hours" min="0" max="87600" placeholder="0" value="' . $H . '"> godz.</label>';
  $Html .= '<label><input type="number" name="' . $Prefix . '_minutes" min="0" max="5256000" placeholder="0" value="' . $M . '"> min</label>';
  return $Html . '</div>';
}

// ---------------- pasek postępu czasu na zadaniu ----------------

// kolory kolejnych "okrążeń": zielony, żółty, pomarańczowy, czerwony, bordowy
function progress_colors() {
  return ['#3ecf8e', '#e8b93f', '#ff8a1f', '#f0414f', '#b3203c'];
}

// czas z sekundami tylko gdy zostalo/uplynelo mniej niz godzina (zeby odliczanie "tykalo")
function format_duration_secs($Sec) {
  $Sec = max(0, (int)round($Sec));
  if ($Sec >= 3600) return format_duration(intdiv($Sec + 30, 60));
  $M = intdiv($Sec, 60);
  $S = $Sec % 60;
  return $M > 0 ? $M . 'min ' . $S . 's' : $S . 's';
}

// $ElapsedSec = ile sekund uplynelo od startu - liczone w SQL (TIMESTAMPDIFF(SECOND, StartedAt, NOW())),
// dzieki temu strefa czasowa PHP nie ma wplywu na odliczanie.
// Zwraca tekst "Pozostało ..." / "Przekroczono o ...", procent zapelnienia biezacego okrazenia
// oraz kolory: Base = to co juz zapelnione poprzednim okrazeniem (tlo), Fill = biezacy kolor
function remaining_time_text($ElapsedSec, $ExpectedMinutes) {
  $Empty = ['text' => '', 'pct' => 0, 'overdue' => false, 'fill' => '', 'base' => '', 'color' => ''];
  if ($ElapsedSec === null || !$ExpectedMinutes) return $Empty;

  $ElapsedSec = max(0, (int)$ElapsedSec);
  $ExpectedSec = $ExpectedMinutes * 60;
  $Ratio = $ElapsedSec / $ExpectedSec;
  $Colors = progress_colors();
  $Last = count($Colors) - 1;

  if ($Ratio >= $Last + 1) {
    $Lap = $Last;
    $Pct = 100;
  } else {
    $Lap = (int)floor($Ratio);
    $Pct = round(($Ratio - $Lap) * 100, 2);
  }
  $Fill = $Colors[$Lap];
  $Base = $Lap === 0 ? '#59616f' : $Colors[$Lap - 1];
  $Overdue = $Ratio >= 1;
  $Txt = ($Overdue ? 'Przekroczono o ' : 'Pozostało ') . format_duration_secs(abs($ExpectedSec - $ElapsedSec));
  return ['text' => $Txt, 'pct' => $Pct, 'overdue' => $Overdue, 'fill' => $Fill, 'base' => $Base, 'color' => $Fill];
}

function remaining_html($Rem, $Extra = '') {
  return '<div class="remaining" style="color:' . $Rem['color'] . ';' . $Extra . '">' . htmlspecialchars($Rem['text']) . '</div>';
}

function progress_bar_html($Rem, $Height = 6) {
  return '<div class="mini-progress" style="height:' . $Height . 'px; background:' . $Rem['base'] . ';">'
       . '<div style="width:' . $Rem['pct'] . '%; background:' . $Rem['fill'] . ';"></div></div>';
}

// ---------------- waga zadania 1-10 (kolory jak rangi na CF) ----------------

// indeks = waga 1-10; [nazwa koloru, tło, kolor tekstu]
function priority_palette() {
  return [
    1  => ['gray',          '#8a93a3', '#10131a'],
    2  => ['lime',          '#84e043', '#10200a'],
    3  => ['cyan',          '#22d3ee', '#04222a'],
    4  => ['blue',          '#4f8cff', '#061633'],
    5  => ['purple',        '#b07cff', '#1e0a3a'],
    6  => ['pastel_orange', '#f6c08f', '#3a2108'],
    7  => ['orange',        '#ff8a1f', '#2a1400'],
    8  => ['pastel_red',    '#ff9a9a', '#3a0a0a'],
    9  => ['red',           '#f0414f', '#ffffff'],
    10 => ['dark_red',      '#a3122a', '#ffffff'],
  ];
}

// puste pole = brak wagi; liczba jest przycinana do 1-10
function read_priority() {
  $Raw = trim($_POST['priority'] ?? '');
  if ($Raw === '' || !ctype_digit($Raw)) return null;
  return min(10, max(1, (int)$Raw));
}

function priority_info($Priority) {
  if ($Priority === null || $Priority === '') return null;
  $Pal = priority_palette();
  $Level = min(10, max(1, (int)$Priority));
  return ['level' => $Level, 'name' => $Pal[$Level][0], 'bg' => $Pal[$Level][1], 'fg' => $Pal[$Level][2]];
}

function priority_badge_html($Priority) {
  $Info = priority_info($Priority);
  if (!$Info) return '';
  return '<span class="prio-badge" style="background:' . $Info['bg'] . '; color:' . $Info['fg'] . ';" title="Waga ' . $Info['level'] . ' (' . $Info['name'] . ')">Waga ' . $Info['level'] . '</span>';
}

// pole do wpisania liczby 1-10; kolor obramowania/tekstu ustawia JS na bieżąco (data-colors)
function priority_input_html($Value = null) {
  $Colors = [];
  foreach (priority_palette() as $Pal) $Colors[] = $Pal[1];
  $Val = ($Value !== null && $Value !== '') ? (string)min(10, max(1, (int)$Value)) : '';
  return '<input type="number" name="priority" class="prio-input" min="1" max="10" step="1" placeholder="1-10" value="' . $Val . '" data-colors="' . htmlspecialchars(json_encode($Colors), ENT_QUOTES) . '">';
}

// napis + pasek odliczania w jednym kontenerze .live-timer; JS (app.js) odswieza go co sekunde
function timer_html($T, $Height = 6, $Extra = '') {
  $Elapsed = $T['ElapsedSec'] ?? null;
  if ($T['Status'] !== 'in_progress' || !$T['ExpectedMinutes'] || $Elapsed === null) return '';
  $Rem = remaining_time_text($Elapsed, $T['ExpectedMinutes']);
  return '<div class="live-timer" data-elapsed="' . (int)$Elapsed . '" data-expected="' . (int)$T['ExpectedMinutes'] . '">'
       . remaining_html($Rem, $Extra) . progress_bar_html($Rem, $Height) . '</div>';
}

// dane zadania do atrybutu data-task (czyta je JS w modalu)
function task_json_attr($T, $CanManage, $CurrentUserId = null) {
  $Rem = ($T['Status'] === 'in_progress')
    ? remaining_time_text($T['ElapsedSec'] ?? null, $T['ExpectedMinutes'])
    : remaining_time_text(null, null);
  $Prio = priority_info($T['Priority']);
  $CanCancel = $CurrentUserId !== null && $T['Status'] === 'pending' && (int)$T['AddedBy'] === (int)$CurrentUserId;
  return htmlspecialchars(json_encode([
    'Id' => (int)$T['Id'],
    'Title' => $T['Title'],
    'Description' => $T['Description'],
    'Status' => $T['Status'],
    'AddedByLogin' => $T['AddedByLogin'],
    'CreatedAt' => date('d.m.Y H:i', strtotime($T['CreatedAt'])),
    'ApprovedAt' => $T['ApprovedAt'] ? date('d.m.Y H:i', strtotime($T['ApprovedAt'])) : null,
    'ExpectedText' => $T['ExpectedMinutes'] ? format_duration($T['ExpectedMinutes']) : null,
    'Priority' => $Prio ? $Prio['level'] : null,
    'PriorityBg' => $Prio ? $Prio['bg'] : null,
    'PriorityFg' => $Prio ? $Prio['fg'] : null,
    'PriorityName' => $Prio ? $Prio['name'] : null,
    'StartedByLogin' => $T['StartedByLogin'] ?? null,
    'StartedAt' => $T['StartedAt'] ? date('d.m.Y H:i', strtotime($T['StartedAt'])) : null,
    'FinishedAt' => $T['FinishedAt'] ? date('d.m.Y H:i', strtotime($T['FinishedAt'])) : null,
    'ElapsedSec' => isset($T['ElapsedSec']) ? (int)$T['ElapsedSec'] : null, 'ExpectedMinutes' => $T['ExpectedMinutes'] ? (int)$T['ExpectedMinutes'] : null,
    'RemainingText' => $Rem['text'], 'Pct' => $Rem['pct'], 'Fill' => $Rem['fill'], 'Base' => $Rem['base'], 'Color' => $Rem['color'],
    'CanManage' => $CanManage,
    'CanCancel' => $CanCancel,
  ]), ENT_QUOTES);
}

function get_status_counts() {
  $Db = get_db();
  $Counts = ['todo' => 0, 'in_progress' => 0, 'awaiting_merge' => 0, 'abandoned' => 0, 'pending' => 0, 'other' => 0, 'done' => 0, 'deleted' => 0];
  $Res = $Db->query('SELECT Status, COUNT(*) AS Cnt FROM Tasks GROUP BY Status');
  while ($Row = $Res->fetch_assoc()) $Counts[$Row['Status']] = (int)$Row['Cnt'];
  return $Counts;
}
