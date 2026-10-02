// graf commitów (graph.php) - cały js tej strony w jednym pliku. Render: własny SVG, zero bibliotek.
// Dane przychodzą z graph_data.php (serwer pyta GitHub tokenem). Wszystko z repo wstawiamy przez textContent.
(function () {
'use strict';

var Cfg = window.GraphConfig || { dataUrl: 'graph_data.php', demo: false };
var Palette = ['#6d8bff', '#3ecf8e', '#e8b93f', '#f0616d', '#22d3ee', '#b07cff', '#ff8a1f', '#ff7eb6', '#84e043', '#4fa8ff', '#f6c08f', '#9aa5ff'];

var Prefs = loadPrefs();
var S = {
  Data: null, ById: new Map(), Children: new Map(), Refs: new Map(), BranchColor: {}, Reach: {},
  On: new Set(), Layout: null, Selected: null, HoverBranch: null, Matches: null, Focus: null,
  Compact: !!Prefs.compact, Lineage: Prefs.lineage !== false, Authors: new Map(), HotRow: -1, LastJump: -1,
  Els: { Rows: [], Nodes: [], Edges: [] }, Animate: false
};
var G = {};

// ---------------- helpers ----------------

function $(Id) { return document.getElementById(Id); }
function el(Tag, Cls, Text) {
  var E = document.createElement(Tag);
  if (Cls) E.className = Cls;
  if (Text !== undefined && Text !== null) E.textContent = Text;
  return E;
}
function svgEl(Tag, Attrs) {
  var E = document.createElementNS('http://www.w3.org/2000/svg', Tag);
  for (var K in Attrs) E.setAttribute(K, Attrs[K]);
  return E;
}
function headline(Msg) { return (Msg || '').split('\n')[0]; }
function bodyOf(Msg) { var I = (Msg || '').indexOf('\n'); return I < 0 ? '' : Msg.slice(I + 1).trim(); }
function shortSha(Sha) { return Sha.slice(0, 7); }
function authorKey(C) { return C.login || C.author || '?'; }
function initials(Name) { var P = (Name || '?').trim().split(/\s+/); return ((P[0] || '?')[0] + (P.length > 1 ? P[P.length - 1][0] : '')).toUpperCase(); }

var Rtf = (typeof Intl !== 'undefined' && Intl.RelativeTimeFormat) ? new Intl.RelativeTimeFormat('pl', { numeric: 'auto' }) : null;
function relTime(Ts) {
  var Sec = Math.round((Ts - Date.now()) / 1000), A = Math.abs(Sec);
  if (!Rtf) return new Date(Ts).toLocaleDateString('pl-PL');
  if (A < 45) return 'przed chwilą';
  if (A < 3600) return Rtf.format(Math.round(Sec / 60), 'minute');
  if (A < 86400) return Rtf.format(Math.round(Sec / 3600), 'hour');
  if (A < 86400 * 30) return Rtf.format(Math.round(Sec / 86400), 'day');
  if (A < 86400 * 365) return Rtf.format(Math.round(Sec / (86400 * 30)), 'month');
  return Rtf.format(Math.round(Sec / (86400 * 365)), 'year');
}
function absTime(Ts) { return new Date(Ts).toLocaleString('pl-PL', { dateStyle: 'medium', timeStyle: 'short' }); }

function loadPrefs() { try { return JSON.parse(localStorage.getItem('cotvGraph') || '{}') || {}; } catch (E) { return {}; } }
function savePrefs() {
  var Hidden = [];
  if (S.Data) S.Data.branches.forEach(function (B) { if (!S.On.has(B.name)) Hidden.push(B.name); });
  try { localStorage.setItem('cotvGraph', JSON.stringify({ compact: S.Compact, lineage: S.Lineage, hidden: Hidden })); } catch (E) {}
}

function Heap(Cmp) { this.a = []; this.cmp = Cmp; }
Heap.prototype.push = function (X) {
  var A = this.a, C = this.cmp, I = A.length;
  A.push(X);
  while (I > 0) {
    var P = (I - 1) >> 1;
    if (C(A[I], A[P]) < 0) { var T = A[I]; A[I] = A[P]; A[P] = T; I = P; } else break;
  }
};
Heap.prototype.pop = function () {
  var A = this.a, C = this.cmp, Top = A[0], Last = A.pop();
  if (A.length) {
    A[0] = Last;
    var I = 0, N = A.length;
    for (;;) {
      var L = 2 * I + 1, R = L + 1, M = I;
      if (L < N && C(A[L], A[M]) < 0) M = L;
      if (R < N && C(A[R], A[M]) < 0) M = R;
      if (M === I) break;
      var T = A[I]; A[I] = A[M]; A[M] = T; I = M;
    }
  }
  return Top;
};

function dims() {
  return S.Compact ? { Row: 26, Lane: 16, R: 4.5, Pad: 8 } : { Row: 34, Lane: 22, R: 6, Pad: 10 };
}

// ---------------- ładowanie danych ----------------

function setState(Kind, Html) {
  G.Canvas.querySelectorAll('.g-row, svg').forEach(function (N) { N.remove(); });
  G.State.className = 'graph-state ' + Kind;
  G.State.innerHTML = '';
  G.State.style.display = 'block';
  if (Kind === 'loading') {
    G.State.appendChild(el('div', 'spinner'));
    G.State.appendChild(el('div', 'state-title', 'Pobieranie historii z GitHuba…'));
    G.State.appendChild(el('div', 'state-sub', 'Pierwsze pobranie może potrwać kilka sekund - potem dane są trzymane w cache.'));
    for (var I = 0; I < 7; I++) { var Sk = el('div', 'skeleton-row'); Sk.style.animationDelay = (I * 0.08) + 's'; Sk.style.width = (55 + (I * 37) % 40) + '%'; G.State.appendChild(Sk); }
  }
}
function clearGraph() {
  G.Canvas.querySelectorAll('.g-row, svg').forEach(function (N) { N.remove(); });
}
function hideState() { G.State.style.display = 'none'; G.State.innerHTML = ''; }

function showError(Err) {
  closeDetail();
  setState('error');
  G.Stats.innerHTML = '';
  G.State.appendChild(el('div', 'state-icon', '!'));
  G.State.appendChild(el('div', 'state-title', Err.error || 'Nie udało się pobrać danych.'));
  if (Err.hint) G.State.appendChild(el('div', 'state-sub', Err.hint));
  var Row = el('div', 'state-actions');
  var Retry = el('button', 'btn btn-primary', 'Spróbuj ponownie');
  Retry.type = 'button';
  Retry.addEventListener('click', function () { load(false); });
  Row.appendChild(Retry);
  if (!Cfg.demo) {
    var Demo = el('a', 'btn btn-ghost', 'Zobacz podgląd na danych przykładowych');
    Demo.href = 'graph.php?demo=1';
    Row.appendChild(Demo);
  }
  G.State.appendChild(Row);
}

function toast(Text) {
  var T = el('div', 'toast', Text);
  document.body.appendChild(T);
  setTimeout(function () { T.classList.add('out'); }, 2600);
  setTimeout(function () { T.remove(); }, 3100);
}

function load(Refresh) {
  setState('loading');
  G.Refresh.disabled = true;
  G.Refresh.classList.add('busy');
  var Url = Cfg.dataUrl + (Refresh ? (Cfg.dataUrl.indexOf('?') < 0 ? '?' : '&') + 'refresh=1' : '');
  fetch(Url, { credentials: 'same-origin' })
    .then(function (R) { return R.json().then(function (J) { return { Ok: R.ok, Json: J }; }); })
    .then(function (Res) {
      if (!Res.Ok) { showError(Res.Json); return; }
      ingest(Res.Json);
      render();
      openFromHash();
      if (Refresh) toast(Res.Json.cached ? 'Dane z cache - GitHub odpytujemy najwyżej co 15 s.' : 'Pobrano świeże dane z GitHuba.');
    })
    .catch(function (E) { showError({ error: 'Nie udało się odczytać odpowiedzi serwera.', hint: String(E) }); })
    .then(function () { G.Refresh.disabled = false; G.Refresh.classList.remove('busy'); });
}

function reachFrom(Sha) {
  var Seen = new Set();
  if (!S.ById.has(Sha)) return Seen;
  var Stack = [Sha];
  Seen.add(Sha);
  while (Stack.length) {
    var C = S.ById.get(Stack.pop());
    C.parents.forEach(function (P) { if (S.ById.has(P) && !Seen.has(P)) { Seen.add(P); Stack.push(P); } });
  }
  return Seen;
}

function ingest(Data) {
  S.Data = Data;
  S.ById = new Map(); S.Children = new Map(); S.Refs = new Map(); S.BranchColor = {}; S.Reach = {}; S.Authors = new Map();
  S.Selected = null; S.Focus = null; S.Matches = null; S.HoverBranch = null; S.LastJump = -1; S.Animate = true;

  Data.commits.forEach(function (C, I) {
    C.idx = I;
    C.ts = Date.parse(C.date) || 0;
    C.ats = Date.parse(C.adate || C.date) || C.ts;
    C.parents = C.parents || [];
    S.ById.set(C.sha, C);
  });
  Data.commits.forEach(function (C) {
    C.parents.forEach(function (P) {
      if (!S.ById.has(P)) return;
      if (!S.Children.has(P)) S.Children.set(P, []);
      S.Children.get(P).push(C.sha);
    });
    var K = authorKey(C);
    if (!S.Authors.has(K)) S.Authors.set(K, { Name: C.author || C.login, Count: 0 });
    S.Authors.get(K).Count++;
  });

  function refsOf(Sha) {
    if (!S.Refs.has(Sha)) S.Refs.set(Sha, { branches: [], tags: [] });
    return S.Refs.get(Sha);
  }
  var Next = 1;
  Data.branches.forEach(function (B) {
    refsOf(B.sha).branches.push(B.name);
    S.BranchColor[B.name] = B.default ? 0 : (Next++ % (Palette.length - 1)) + 1;
    S.Reach[B.name] = reachFrom(B.sha);
  });
  (Data.tags || []).forEach(function (T) { refsOf(T.sha).tags.push(T.name); });

  var Hidden = new Set(Prefs.hidden || []);
  S.On = new Set();
  Data.branches.forEach(function (B) { if (!Hidden.has(B.name)) S.On.add(B.name); });
  if (S.On.size === 0) Data.branches.forEach(function (B) { S.On.add(B.name); });

  if (Data.url) G.Repo.href = Data.url; else G.Repo.removeAttribute('href');
  G.Repo.textContent = Data.repo || G.Repo.textContent;
  fillAuthors();
  buildSidebar();
}

// ---------------- układ grafu (lane assignment, jak git log --graph) ----------------

function visibleSet() {
  var Vis = new Set();
  S.Data.branches.forEach(function (B) {
    if (S.On.has(B.name)) S.Reach[B.name].forEach(function (Sha) { Vis.add(Sha); });
  });
  return Vis;
}

function layoutGraph(Vis) {
  var List = [];
  Vis.forEach(function (Sha) { List.push(S.ById.get(Sha)); });

  var Indeg = new Map();
  List.forEach(function (C) { Indeg.set(C.sha, 0); C.vchildren = []; });
  List.forEach(function (C) {
    C.vparents = C.parents.filter(function (P) { return Vis.has(P); });
    C.vparents.forEach(function (P) { Indeg.set(P, Indeg.get(P) + 1); S.ById.get(P).vchildren.push(C); });
  });

  // kolejność: dzieci zawsze przed rodzicami, a spośród gotowych - najnowszy pierwszy
  var Queue = new Heap(function (A, B) { return (B.ts - A.ts) || (A.idx - B.idx); });
  List.forEach(function (C) { if (Indeg.get(C.sha) === 0) Queue.push(C); });
  var Order = [];
  while (Queue.a.length) {
    var C = Queue.pop();
    C.row = Order.length;
    Order.push(C);
    C.vparents.forEach(function (P) {
      var N = Indeg.get(P) - 1;
      Indeg.set(P, N);
      if (N === 0) Queue.push(S.ById.get(P));
    });
  }

  var Lanes = [], Edges = [], Unnamed = 0;
  function freeLane() {
    for (var I = 0; I < Lanes.length; I++) if (!Lanes[I]) return I;
    Lanes.push(null);
    return Lanes.length - 1;
  }
  function findLane(Sha) {
    for (var I = 0; I < Lanes.length; I++) if (Lanes[I] && Lanes[I].sha === Sha) return I;
    return -1;
  }
  function colorFor(Commit) {
    var Refs = S.Refs.get(Commit.sha);
    if (Refs && Refs.branches.length) return S.BranchColor[Refs.branches[0]];
    Unnamed++;
    return 1 + (Unnamed * 5) % (Palette.length - 1);
  }

  Order.forEach(function (C) {
    var Lane = findLane(C.sha), Color;
    if (Lane >= 0) Color = Lanes[Lane].color;
    else { Lane = freeLane(); Color = colorFor(C); }
    C.lane = Lane; C.color = Color;
    Lanes[Lane] = null;

    C.vparents.forEach(function (P, K) {
      var EL = findLane(P);
      if (EL < 0) {
        if (K === 0) { EL = Lane; Lanes[Lane] = { sha: P, color: Color }; }
        else { EL = freeLane(); Lanes[EL] = { sha: P, color: colorFor(S.ById.get(P)) }; }
      }
      Edges.push({ From: C, To: S.ById.get(P), Lane: EL, Color: Lanes[EL].color });
    });
  });

  return { Order: Order, Edges: Edges, Lanes: Math.max(1, Lanes.length) };
}

// ---------------- render ----------------

function render() {
  var Vis = visibleSet();
  if (S.Selected && !Vis.has(S.Selected)) { S.Selected = null; S.Focus = null; closeDetail(); }

  var L = layoutGraph(Vis);
  S.Layout = L;
  S.HotRow = -1;

  clearGraph();
  hideState();
  if (L.Order.length === 0) {
    setState('empty');
    G.State.appendChild(el('div', 'state-title', 'Brak widocznych commitów'));
    G.State.appendChild(el('div', 'state-sub', 'Zaznacz przynajmniej jeden branch po lewej stronie.'));
    updateStats(Vis);
    return;
  }

  var D = dims();
  var GraphW = Math.max(72, D.Pad * 2 + L.Lanes * D.Lane);
  var TotalH = L.Order.length * D.Row;
  function xOf(Lane) { return D.Pad + Lane * D.Lane + D.Lane / 2; }
  function yOf(Row) { return Row * D.Row + D.Row / 2; }

  G.Canvas.style.height = (TotalH + 24) + 'px';
  G.Canvas.style.setProperty('--gw', GraphW + 'px');
  G.Canvas.style.setProperty('--rh', D.Row + 'px');
  G.ColHead.style.setProperty('--gw', GraphW + 'px');
  G.Layout.classList.toggle('compact', S.Compact);

  var Svg = svgEl('svg', { width: GraphW, height: TotalH, viewBox: '0 0 ' + GraphW + ' ' + TotalH, 'class': 'graph-svg' });
  var EdgeG = svgEl('g', { 'class': 'edges' });
  var NodeG = svgEl('g', { 'class': 'nodes' });
  Svg.appendChild(EdgeG); Svg.appendChild(NodeG);

  S.Els = { Rows: [], Nodes: [], Edges: [] };

  L.Edges.forEach(function (E) {
    var X1 = xOf(E.From.lane), Y1 = yOf(E.From.row), X2 = xOf(E.To.lane), Y2 = yOf(E.To.row), Path;
    if (X1 === X2) Path = 'M' + X1 + ' ' + Y1 + ' V' + Y2;
    else {
      var Ym = Math.min(Y2, Y1 + D.Row);
      Path = 'M' + X1 + ' ' + Y1 + ' C' + X1 + ' ' + (Y1 + D.Row * 0.55) + ' ' + X2 + ' ' + (Y1 + D.Row * 0.45) + ' ' + X2 + ' ' + Ym;
      if (Y2 > Ym) Path += ' V' + Y2;
    }
    var P = svgEl('path', { d: Path, 'class': 'edge', stroke: Palette[E.Color] });
    EdgeG.appendChild(P);
    S.Els.Edges.push({ El: P, From: E.From.row, To: E.To.row });
  });

  var Frag = document.createDocumentFragment();
  L.Order.forEach(function (C, I) {
    var Refs = S.Refs.get(C.sha) || { branches: [], tags: [] };
    var Color = Palette[C.color];
    var IsMerge = C.vparents.length > 1;
    var Cut = C.parents.some(function (P) { return !S.ById.has(P); });

    // węzeł
    var X = xOf(C.lane), Y = yOf(I);
    var Node = svgEl('g', { 'class': 'node' + (IsMerge ? ' merge' : '') + (Refs.branches.length ? ' tip' : ''), transform: 'translate(' + X + ' ' + Y + ')' });
    var In = svgEl('g', { 'class': 'node-in' });
    if (Refs.branches.length) In.appendChild(svgEl('circle', { r: D.R + 4.5, fill: Color, 'class': 'n-halo' }));
    In.appendChild(svgEl('circle', { r: D.R + 6, fill: 'none', stroke: Color, 'class': 'n-sel' }));
    In.appendChild(svgEl('circle', { r: D.R, fill: IsMerge ? 'var(--bg)' : Color, stroke: IsMerge ? Color : 'var(--bg)', 'class': 'n-main' }));
    if (IsMerge) In.appendChild(svgEl('circle', { r: Math.max(1.6, D.R - 3.6), fill: Color }));
    Node.appendChild(In);
    NodeG.appendChild(Node);
    if (Cut) {
      EdgeG.appendChild(svgEl('path', { d: 'M' + X + ' ' + Y + ' V' + (Y + D.Row * 0.8), 'class': 'edge stub', stroke: Color }));
    }
    S.Els.Nodes.push(Node);

    // wiersz
    var Row = el('div', 'g-row');
    Row.style.top = (I * D.Row) + 'px';
    if (S.Animate && I < 36) { Row.classList.add('enter'); Row.style.animationDelay = (I * 14) + 'ms'; }
    Row.setAttribute('data-row', I);

    var Msg = el('div', 'g-msg');
    var Shown = 0, Extra = [];
    Refs.branches.forEach(function (Name) {
      if (Shown >= 3) { Extra.push(Name); return; }
      var Chip = el('span', 'chip chip-branch', (S.Data.default_branch === Name ? '★ ' : '') + Name);
      Chip.style.setProperty('--c', Palette[S.BranchColor[Name]]);
      Chip.title = 'Branch: ' + Name;
      Msg.appendChild(Chip); Shown++;
    });
    Refs.tags.forEach(function (Name) {
      if (Shown >= 4) { Extra.push(Name); return; }
      var Chip = el('span', 'chip chip-tag', '⚑ ' + Name);
      Chip.title = 'Tag: ' + Name;
      Msg.appendChild(Chip); Shown++;
    });
    if (Extra.length) { var More = el('span', 'chip chip-more', '+' + Extra.length); More.title = Extra.join(', '); Msg.appendChild(More); }
    Msg.appendChild(el('span', 'g-title', headline(C.msg) || '(brak opisu)'));
    if (Cut) Msg.appendChild(el('span', 'g-cut', 'starsza historia nie została pobrana'));
    Row.appendChild(Msg);

    var Au = el('div', 'g-author');
    Au.appendChild(avatarEl(C, 20));
    Au.appendChild(el('span', 'g-aname', C.author || C.login || '?'));
    Row.appendChild(Au);

    var Dt = el('div', 'g-date', relTime(C.ats));
    Dt.title = absTime(C.ats);
    Row.appendChild(Dt);
    Row.appendChild(el('code', 'g-sha', shortSha(C.sha)));

    S.Els.Rows.push(Row);
    Frag.appendChild(Row);
  });

  G.Canvas.appendChild(Svg);
  G.Canvas.appendChild(Frag);

  S.Animate = false;
  if (S.Selected) { var Sel = S.ById.get(S.Selected); markSelected(Sel.row); computeFocus(); }
  computeMatches();
  updateStats(Vis);
  applyHighlight();
}

function avatarEl(C, Size) {
  var Wrap = el('span', 'avatar');
  Wrap.style.width = Wrap.style.height = Size + 'px';
  Wrap.textContent = initials(C.author || C.login);
  if (C.avatar) {
    var Img = new Image();
    Img.alt = '';
    Img.referrerPolicy = 'no-referrer';
    Img.loading = 'lazy';
    Img.onload = function () { Wrap.textContent = ''; Wrap.appendChild(Img); };
    Img.src = C.avatar + (C.avatar.indexOf('?') < 0 ? '?' : '&') + 's=' + (Size * 2);
  }
  return Wrap;
}

function updateStats(Vis) {
  var D = S.Data;
  var Shown = Vis ? Vis.size : D.commits.length;
  G.Stats.innerHTML = '';
  function Stat(Label, Value) {
    var Box = el('span', 'stat');
    Box.appendChild(el('span', 'stat-l', Label));
    Box.appendChild(el('b', null, String(Value)));
    G.Stats.appendChild(Box);
  }
  Stat('Commity', Shown === D.commits.length ? D.commits.length : Shown + ' / ' + D.commits.length);
  Stat('Branche', S.On.size === D.branches.length ? D.branches.length : S.On.size + ' / ' + D.branches.length);
  Stat('Tagi', (D.tags || []).length);
  Stat('Autorzy', S.Authors.size);
}

// ---------------- podświetlanie (zaznaczenie / szukanie / hover brancha) ----------------

function computeFocus() {
  S.Focus = null;
  if (!S.Selected || !S.Layout) return;
  var Start = S.ById.get(S.Selected);
  if (Start.row === undefined) return;
  var Set_ = new Set([Start.sha]);
  var Up = [Start], Down = [Start];
  while (Up.length) Up.pop().vparents.forEach(function (P) { if (!Set_.has(P)) { Set_.add(P); Up.push(S.ById.get(P)); } });
  while (Down.length) Down.pop().vchildren.forEach(function (C) { if (!Set_.has(C.sha)) { Set_.add(C.sha); Down.push(C); } });
  S.Focus = Set_;
}

function computeMatches() {
  var Q = G.Search.value.trim().toLowerCase();
  var A = G.Author.value;
  S.Matches = null;
  G.Match.textContent = '';
  if (!Q && !A) return;
  var Set_ = new Set();
  S.Layout.Order.forEach(function (C) {
    if (A && authorKey(C) !== A) return;
    if (Q) {
      var Refs = S.Refs.get(C.sha);
      var Hay = (C.msg + '\n' + (C.author || '') + '\n' + (C.login || '') + '\n' + C.sha + '\n' + (Refs ? Refs.branches.concat(Refs.tags).join(' ') : '')).toLowerCase();
      if (Hay.indexOf(Q) < 0) return;
    }
    Set_.add(C.sha);
  });
  S.Matches = Set_;
  G.Match.textContent = Set_.size ? Set_.size + ' trafień' : 'brak trafień';
  G.Match.classList.toggle('none', !Set_.size);
}

function applyHighlight() {
  if (!S.Layout) return;
  var Order = S.Layout.Order, N = Order.length;
  var Alive = new Array(N), Any = false;

  var Hover = S.HoverBranch ? S.Reach[S.HoverBranch] : null;
  // podgląd brancha (hover) > szukanie/autor > linia zaznaczonego commita
  var Match = Hover ? null : S.Matches;
  var Focus = (!Hover && !Match && S.Lineage) ? S.Focus : null;
  Any = !!(Hover || Focus || Match);

  for (var I = 0; I < N; I++) {
    var Sha = Order[I].sha, Ok = true;
    if (Hover && !Hover.has(Sha)) Ok = false;
    if (Focus && !Focus.has(Sha)) Ok = false;
    if (Match && !Match.has(Sha)) Ok = false;
    Alive[I] = Ok;
    S.Els.Rows[I].classList.toggle('dim', Any && !Ok);
    S.Els.Nodes[I].classList.toggle('dim', Any && !Ok);
  }
  S.Els.Edges.forEach(function (E) {
    E.El.classList.toggle('dim', Any && !(Alive[E.From] && Alive[E.To]));
  });
}

// ---------------- zaznaczanie + panel szczegółów ----------------

function markSelected(Row) {
  S.Els.Rows.forEach(function (R) { R.classList.remove('sel'); });
  S.Els.Nodes.forEach(function (N) { N.classList.remove('sel'); });
  if (Row >= 0 && S.Els.Rows[Row]) { S.Els.Rows[Row].classList.add('sel'); S.Els.Nodes[Row].classList.add('sel'); }
}

function scrollToRow(Row, Center) {
  var D = dims(), Y = Row * D.Row, H = G.Scroll.clientHeight, Top = G.Scroll.scrollTop, Head = G.ColHead.offsetHeight;
  var Target = Top;
  if (Center) Target = Y - (H - Head) / 2 + D.Row / 2;
  else if (Y < Top) Target = Y - 6;
  else if (Y + D.Row > Top + H - Head) Target = Y + D.Row - H + Head + 6;
  if (Target !== Top) G.Scroll.scrollTo({ top: Math.max(0, Target), behavior: 'smooth' });
}

function selectSha(Sha, Opts) {
  Opts = Opts || {};
  var C = S.ById.get(Sha);
  if (!C) return;
  if (C.row === undefined || !S.Layout || S.Layout.Order[C.row] !== C) {
    // commit jest ukryty przez filtr branchy - pokaż branche, które go zawierają
    S.Data.branches.forEach(function (B) { if (S.Reach[B.name].has(Sha)) S.On.add(B.name); });
    syncSidebar(); savePrefs();
    S.Selected = Sha; render();
    C = S.ById.get(Sha);
  }
  S.Selected = Sha;
  markSelected(C.row);
  computeFocus();
  applyHighlight();
  renderDetail(C);
  if (Opts.scroll === 'center') scrollToRow(C.row, true);
  else if (Opts.scroll) scrollToRow(C.row, false);
  try { history.replaceState(null, '', '#' + shortSha(Sha)); } catch (E) {}
}

function closeDetail() {
  S.Selected = null; S.Focus = null;
  G.Layout.classList.remove('detail-open');
  G.Detail.setAttribute('aria-hidden', 'true');
  if (S.Layout && S.Els.Rows.length) { markSelected(-1); applyHighlight(); }
  try { history.replaceState(null, '', location.pathname + location.search); } catch (E) {}
}

function copyText(Text, Btn) {
  function Done() { var Old = Btn.textContent; Btn.textContent = 'Skopiowano ✓'; setTimeout(function () { Btn.textContent = Old; }, 1400); }
  if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(Text).then(Done); return; }
  var T = document.createElement('textarea');
  T.value = Text; document.body.appendChild(T); T.select();
  try { document.execCommand('copy'); Done(); } catch (E) {}
  T.remove();
}

function linkList(Title, Shas) {
  var Sec = el('div', 'd-section');
  Sec.appendChild(el('div', 'd-label', Title + ' (' + Shas.length + ')'));
  Shas.forEach(function (Sha) {
    var C = S.ById.get(Sha);
    var B = el('button', 'd-link');
    B.type = 'button';
    B.appendChild(el('code', null, shortSha(Sha)));
    B.appendChild(el('span', null, C ? headline(C.msg) : '(poza pobraną historią)'));
    if (C) B.addEventListener('click', function () { selectSha(Sha, { scroll: 'center' }); });
    else B.disabled = true;
    Sec.appendChild(B);
  });
  return Sec;
}

function renderDetail(C) {
  var Box = G.Detail;
  Box.innerHTML = '';
  var Color = Palette[C.color !== undefined ? C.color : 0];

  var Top = el('div', 'd-top');
  var Dot = el('span', 'd-dot'); Dot.style.background = Color; Dot.style.color = Color;
  Top.appendChild(Dot);
  Top.appendChild(el('code', 'd-sha', C.sha));
  var Close = el('button', 'd-close', '✕'); Close.type = 'button'; Close.title = 'Zamknij (Esc)';
  Close.addEventListener('click', closeDetail);
  Top.appendChild(Close);
  Box.appendChild(Top);

  Box.appendChild(el('h2', 'd-title', headline(C.msg) || '(brak opisu)'));
  var Body = bodyOf(C.msg);
  if (Body) Box.appendChild(el('pre', 'd-body', Body));

  var Meta = el('div', 'd-meta');
  var Who = el('div', 'd-who');
  Who.appendChild(avatarEl(C, 34));
  var WhoT = el('div');
  WhoT.appendChild(el('div', 'd-name', C.author || C.login || '?'));
  if (C.login) WhoT.appendChild(el('div', 'd-login', '@' + C.login));
  Who.appendChild(WhoT);
  Meta.appendChild(Who);
  var When = el('div', 'd-when');
  When.appendChild(el('div', null, absTime(C.ats)));
  When.appendChild(el('div', 'd-rel', relTime(C.ats)));
  Meta.appendChild(When);
  Box.appendChild(Meta);

  var Branches = S.Data.branches.filter(function (B) { return S.Reach[B.name].has(C.sha); });
  var Refs = S.Refs.get(C.sha);
  if (Branches.length) {
    var Sec = el('div', 'd-section');
    Sec.appendChild(el('div', 'd-label', 'Zawarty w branchach (' + Branches.length + ')'));
    var Chips = el('div', 'd-chips');
    Branches.forEach(function (B) {
      var Chip = el('button', 'chip chip-branch', (B.default ? '★ ' : '') + B.name);
      Chip.type = 'button';
      Chip.style.setProperty('--c', Palette[S.BranchColor[B.name]]);
      Chip.title = 'Przejdź do czubka brancha';
      Chip.addEventListener('click', function () { focusBranch(B); });
      Chips.appendChild(Chip);
    });
    Sec.appendChild(Chips);
    Box.appendChild(Sec);
  }
  if (Refs && Refs.tags.length) {
    var TS = el('div', 'd-section');
    TS.appendChild(el('div', 'd-label', 'Tagi'));
    var TC = el('div', 'd-chips');
    Refs.tags.forEach(function (T) { TC.appendChild(el('span', 'chip chip-tag', '⚑ ' + T)); });
    TS.appendChild(TC);
    Box.appendChild(TS);
  }

  if (C.parents.length) Box.appendChild(linkList(C.parents.length > 1 ? 'Rodzice (merge)' : 'Rodzic', C.parents));
  var Kids = S.Children.get(C.sha) || [];
  if (Kids.length) Box.appendChild(linkList('Dzieci', Kids));

  var Actions = el('div', 'd-actions');
  var Copy = el('button', 'btn btn-ghost btn-sm', 'Kopiuj SHA'); Copy.type = 'button';
  Copy.addEventListener('click', function () { copyText(C.sha, Copy); });
  Actions.appendChild(Copy);
  if (S.Data.url) {
    var Gh = el('a', 'btn btn-primary btn-sm', 'Otwórz na GitHubie ↗');
    Gh.href = S.Data.url + '/commit/' + C.sha; Gh.target = '_blank'; Gh.rel = 'noopener';
    Actions.appendChild(Gh);
  }
  Box.appendChild(Actions);

  G.Layout.classList.add('detail-open');
  Box.setAttribute('aria-hidden', 'false');
  Box.scrollTop = 0;
}

// ---------------- panel branchy ----------------

function fillAuthors() {
  G.Author.innerHTML = '';
  var All = el('option', null, 'Wszyscy autorzy'); All.value = '';
  G.Author.appendChild(All);
  var Arr = [];
  S.Authors.forEach(function (V, K) { Arr.push({ Key: K, Name: V.Name, Count: V.Count }); });
  Arr.sort(function (A, B) { return B.Count - A.Count; });
  Arr.forEach(function (A) { var O = el('option', null, A.Name + ' (' + A.Count + ')'); O.value = A.Key; G.Author.appendChild(O); });
}

function focusBranch(B) {
  S.On.add(B.name);
  syncSidebar(); savePrefs();
  selectSha(B.sha, { scroll: 'center' });
}

function buildSidebar() {
  var D = S.Data;
  G.Branches.innerHTML = '';
  D.branches.forEach(function (B) {
    var Item = el('div', 'branch-item');
    Item.setAttribute('data-name', B.name.toLowerCase());
    var Lbl = el('label', 'switch');
    var Chk = el('input'); Chk.type = 'checkbox'; Chk.checked = S.On.has(B.name);
    Chk.addEventListener('change', function () {
      if (Chk.checked) S.On.add(B.name); else S.On.delete(B.name);
      Item.classList.toggle('off', !Chk.checked);
      savePrefs(); render();
    });
    Lbl.appendChild(Chk); Lbl.appendChild(el('span', 'slider'));
    Lbl.style.setProperty('--c', Palette[S.BranchColor[B.name]]);
    Item.appendChild(Lbl);

    var Name = el('button', 'branch-name'); Name.type = 'button';
    Name.title = 'Przejdź do czubka brancha: ' + B.name;
    Name.appendChild(el('span', 'dot')); Name.firstChild.style.background = Palette[S.BranchColor[B.name]];
    Name.appendChild(el('span', 'bn', (B.default ? '★ ' : '') + B.name));
    var Tip = S.ById.get(B.sha);
    Name.appendChild(el('span', 'bd', Tip ? relTime(Tip.ats) : ''));
    Name.addEventListener('click', function () { focusBranch(B); });
    Item.appendChild(Name);

    Item.addEventListener('mouseenter', function () { if (S.On.has(B.name)) { S.HoverBranch = B.name; applyHighlight(); } });
    Item.addEventListener('mouseleave', function () { S.HoverBranch = null; applyHighlight(); });
    Item.classList.toggle('off', !S.On.has(B.name));
    G.Branches.appendChild(Item);
  });

  if ((D.tags || []).length) {
    G.Branches.appendChild(el('div', 'side-sub', 'Tagi (' + D.tags.length + ')'));
    D.tags.forEach(function (T) {
      var B = el('button', 'tag-item', '⚑ ' + T.name); B.type = 'button';
      B.setAttribute('data-name', T.name.toLowerCase());
      B.addEventListener('click', function () { selectSha(T.sha, { scroll: 'center' }); });
      G.Branches.appendChild(B);
    });
  }

  var When = new Date(D.fetched_at * 1000);
  G.Fetched.textContent = 'Dane z ' + When.toLocaleTimeString('pl-PL') + (D.cached ? ' (cache)' : '') + (Cfg.demo ? ' · przykładowe' : '');
  if (D.truncated) G.Fetched.appendChild(el('div', 'warn', 'Repo ma więcej commitów - pobrano najnowsze 2000.'));
}

function syncSidebar() {
  var Items = G.Branches.querySelectorAll('.branch-item');
  S.Data.branches.forEach(function (B, I) {
    if (!Items[I]) return;
    Items[I].querySelector('input').checked = S.On.has(B.name);
    Items[I].classList.toggle('off', !S.On.has(B.name));
  });
}

// ---------------- nawigacja klawiaturą / hash ----------------

function stepSelection(Dir) {
  if (!S.Layout) return;
  var Order = S.Layout.Order, I = S.Selected ? S.ById.get(S.Selected).row : (Dir > 0 ? -1 : Order.length);
  for (I += Dir; I >= 0 && I < Order.length; I += Dir) {
    if (!S.Matches || S.Matches.has(Order[I].sha)) { selectSha(Order[I].sha, { scroll: true }); return; }
  }
}

function jumpToMatch(Dir) {
  if (!S.Matches || !S.Matches.size) return;
  var Order = S.Layout.Order, N = Order.length;
  var From = S.Selected ? S.ById.get(S.Selected).row : S.LastJump;
  for (var K = 1; K <= N; K++) {
    var I = ((From + Dir * K) % N + N) % N;
    if (S.Matches.has(Order[I].sha)) { S.LastJump = I; selectSha(Order[I].sha, { scroll: 'center' }); return; }
  }
}

function openFromHash() {
  var H = location.hash.replace('#', '').toLowerCase();
  if (H.length < 4 || !S.Data) return;
  for (var I = 0; I < S.Data.commits.length; I++) {
    if (S.Data.commits[I].sha.indexOf(H) === 0) { selectSha(S.Data.commits[I].sha, { scroll: 'center' }); return; }
  }
}

// ---------------- start ----------------

function init() {
  G.Layout = $('gLayout'); G.Canvas = $('gCanvas'); G.State = $('gState'); G.Scroll = $('gScroll'); G.ColHead = $('gColHead');
  G.Detail = $('gDetail'); G.Stats = $('gStats'); G.Repo = $('gRepo'); G.Search = $('gSearch'); G.Author = $('gAuthor');
  G.Branches = $('gBranches'); G.Fetched = $('gFetched'); G.Refresh = $('gRefresh');
  G.Match = el('span', 'match-count'); G.Search.parentNode.insertBefore(G.Match, G.Search.nextSibling);

  var BtnCompact = $('gCompact'), BtnLineage = $('gLineage');
  function syncButtons() {
    BtnCompact.textContent = S.Compact ? 'Luźno' : 'Gęsto';
    BtnLineage.classList.toggle('on', S.Lineage);
    BtnLineage.setAttribute('aria-pressed', S.Lineage ? 'true' : 'false');
  }
  syncButtons();
  BtnCompact.addEventListener('click', function () { S.Compact = !S.Compact; syncButtons(); savePrefs(); if (S.Data) { render(); if (S.Selected) scrollToRow(S.ById.get(S.Selected).row, true); } });
  BtnLineage.addEventListener('click', function () { S.Lineage = !S.Lineage; syncButtons(); savePrefs(); applyHighlight(); });
  G.Refresh.addEventListener('click', function () { load(true); });

  $('gAll').addEventListener('click', function () { S.Data.branches.forEach(function (B) { S.On.add(B.name); }); syncSidebar(); savePrefs(); render(); });
  $('gOnlyDefault').addEventListener('click', function () {
    S.On = new Set(); S.Data.branches.forEach(function (B) { if (B.default) S.On.add(B.name); });
    if (!S.On.size && S.Data.branches.length) S.On.add(S.Data.branches[0].name);
    syncSidebar(); savePrefs(); render();
  });
  $('gBranchFilter').addEventListener('input', function () {
    var Q = this.value.trim().toLowerCase();
    G.Branches.querySelectorAll('[data-name]').forEach(function (N) { N.style.display = !Q || N.getAttribute('data-name').indexOf(Q) >= 0 ? '' : 'none'; });
  });

  var Timer = null;
  G.Search.addEventListener('input', function () { clearTimeout(Timer); Timer = setTimeout(function () { S.LastJump = -1; computeMatches(); applyHighlight(); }, 120); });
  G.Search.addEventListener('keydown', function (E) {
    if (E.key === 'Enter') { E.preventDefault(); computeMatches(); jumpToMatch(E.shiftKey ? -1 : 1); }
    if (E.key === 'Escape') { this.value = ''; computeMatches(); applyHighlight(); this.blur(); }
  });
  G.Author.addEventListener('change', function () { S.LastJump = -1; computeMatches(); applyHighlight(); });

  // klik w wiersz + hover (delegacja - jeden listener zamiast tysięcy)
  G.Canvas.addEventListener('click', function (E) {
    var Row = E.target.closest('.g-row');
    if (Row) selectSha(S.Layout.Order[+Row.getAttribute('data-row')].sha, {});
  });
  G.Canvas.addEventListener('mouseover', function (E) {
    var Row = E.target.closest('.g-row'), I = Row ? +Row.getAttribute('data-row') : -1;
    if (I === S.HotRow) return;
    if (S.HotRow >= 0 && S.Els.Nodes[S.HotRow]) S.Els.Nodes[S.HotRow].classList.remove('hot');
    S.HotRow = I;
    if (I >= 0) S.Els.Nodes[I].classList.add('hot');
  });
  G.Canvas.addEventListener('mouseleave', function () {
    if (S.HotRow >= 0 && S.Els.Nodes[S.HotRow]) S.Els.Nodes[S.HotRow].classList.remove('hot');
    S.HotRow = -1;
  });

  document.addEventListener('keydown', function (E) {
    var Typing = /^(INPUT|SELECT|TEXTAREA)$/.test(E.target.tagName);
    if (E.key === '/' && !Typing) { E.preventDefault(); G.Search.focus(); G.Search.select(); return; }
    if (Typing) return;
    if (E.key === 'ArrowDown' || E.key === 'j') { E.preventDefault(); stepSelection(1); }
    else if (E.key === 'ArrowUp' || E.key === 'k') { E.preventDefault(); stepSelection(-1); }
    else if (E.key === 'Escape' && S.Selected) closeDetail();
  });

  window.addEventListener('hashchange', openFromHash);
  load(false);
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
