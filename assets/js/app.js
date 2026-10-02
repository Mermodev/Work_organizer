// cały js aplikacji w jednym pliku, celowo bez podziału na moduły

function updatePwStrength(Password) {
  var Score = 0;
  if (Password.length >= 8) Score++;
  if (/[a-z]/.test(Password)) Score++;
  if (/[A-Z]/.test(Password)) Score++;
  if (/[0-9]/.test(Password)) Score++;
  if (/[^a-zA-Z0-9]/.test(Password)) Score++;
  var Bar = document.getElementById('pwStrengthBar');
  if (!Bar) return;
  var Colors = ['#f0616d', '#f0616d', '#e8b93f', '#e8b93f', '#3ecf8e'];
  Bar.style.width = (Score / 5 * 100) + '%';
  Bar.style.background = Colors[Math.max(0, Score - 1)];
}

// modal szczegółów zadania - dane są już w HTML (data-task na karcie), zero AJAX
function openTaskModal(El) {
  var Data = JSON.parse(El.getAttribute('data-task'));

  document.getElementById('taskModalTitle').textContent = Data.Title;

  var Meta = 'Dodał: ' + Data.AddedByLogin + ' &middot; ' + Data.CreatedAt;
  if (Data.ApprovedAt) Meta += ' &middot; zaakceptowano: ' + Data.ApprovedAt;
  if (Data.ExpectedText) Meta += ' &middot; oczekiwany czas: ' + Data.ExpectedText;
  if (Data.Priority !== null) Meta += '<br>Waga: <span class="prio-badge" style="background:' + Data.PriorityBg + '; color:' + Data.PriorityFg + ';">' + Data.Priority + ' (' + Data.PriorityName + ')</span>';
  if (Data.StartedByLogin) Meta += '<br>Realizuje: ' + Data.StartedByLogin + ' od ' + Data.StartedAt;
  if (Data.FinishedAt) Meta += '<br>Zakończono: ' + Data.FinishedAt;
  document.getElementById('taskModalMeta').innerHTML = Meta;
  document.getElementById('taskModalDesc').textContent = Data.Description;

  var ProgHtml = '';
  if (Data.Status === 'in_progress' && Data.ExpectedMinutes && Data.ElapsedSec !== null) {
    ProgHtml = '<div class="live-timer" data-elapsed="' + Data.ElapsedSec + '" data-expected="' + Data.ExpectedMinutes + '">' +
      '<p class="modal-info remaining" style="color:' + Data.Color + ';">' + Data.RemainingText + '</p>' +
      '<div class="mini-progress" style="height:10px; background:' + Data.Base + ';"><div style="width:' + Data.Pct + '%; background:' + Data.Fill + ';"></div></div></div>';
  }
  document.getElementById('taskModalProgress').innerHTML = ProgHtml;

  var Actions = '';
  if (Data.Status === 'todo') {
    Actions += '<form method="post" style="margin:0;"><input type="hidden" name="id" value="' + Data.Id + '"><input type="hidden" name="action" value="accept"><button class="btn btn-success">Przyjmij zadanie</button></form>';
  }
  if (Data.CanCancel) {
    Actions += '<form method="post" style="margin:0;" onsubmit="return confirm(\'Na pewno wycofać zgłoszenie tego zadania?\');"><input type="hidden" name="id" value="' + Data.Id + '"><input type="hidden" name="action" value="cancel"><button class="btn btn-danger">Wycofaj zgłoszenie</button></form>';
  }
  if (Data.CanManage && Data.Status === 'in_progress') {
    Actions += '<form method="post" style="margin:0;"><input type="hidden" name="id" value="' + Data.Id + '"><input type="hidden" name="action" value="ready"><button class="btn btn-primary">Gotowe - czeka na dodanie</button></form>';
    Actions += '<form method="post" style="margin:0;" onsubmit="return confirm(\'Na pewno odłożyć to zadanie?\');"><input type="hidden" name="id" value="' + Data.Id + '"><input type="hidden" name="action" value="abandon"><button class="btn btn-danger">Odłóż zadanie</button></form>';
  }
  if (Data.CanManage && Data.Status === 'awaiting_merge') {
    Actions += '<form method="post" style="margin:0;"><input type="hidden" name="id" value="' + Data.Id + '"><input type="hidden" name="action" value="merged"><button class="btn btn-success">Dodano do main - zrealizowane</button></form>';
    Actions += '<form method="post" style="margin:0;"><input type="hidden" name="id" value="' + Data.Id + '"><input type="hidden" name="action" value="reopen"><button class="btn btn-ghost">Wróć do realizacji</button></form>';
    Actions += '<form method="post" style="margin:0;" onsubmit="return confirm(\'Na pewno odłożyć to zadanie?\');"><input type="hidden" name="id" value="' + Data.Id + '"><input type="hidden" name="action" value="abandon"><button class="btn btn-danger">Odłóż zadanie</button></form>';
  }
  document.getElementById('taskModalActions').innerHTML = Actions;

  document.getElementById('taskModalOverlay').classList.add('open');
  refreshTimers();
}

function closeTaskModal() {
  document.getElementById('taskModalOverlay').classList.remove('open');
}

function openAddTaskModal() {
  document.getElementById('addTaskModalOverlay').classList.add('open');
}
function closeAddTaskModal() {
  document.getElementById('addTaskModalOverlay').classList.remove('open');
}

// kolor pola wagi (1-10) zmienia się na bieżąco podczas wpisywania
function updatePriorityInput(El) {
  var Colors = JSON.parse(El.getAttribute('data-colors'));
  var N = parseInt(El.value, 10);
  var Color = (N >= 1 && N <= 10) ? Colors[N - 1] : '';
  El.style.color = Color;
  El.style.borderColor = Color;
  El.style.fontWeight = Color ? '700' : '';
}
document.addEventListener('DOMContentLoaded', function() {
  document.querySelectorAll('input.prio-input').forEach(function(El) {
    updatePriorityInput(El);
    El.addEventListener('input', function() { updatePriorityInput(El); });
  });
});

// ---------- odliczanie na zywo ----------
// Serwer podaje ile sekund uplynelo w chwili wygenerowania strony (data-elapsed), reszte dolicza przegladarka.
var PageLoadedAt = Date.now();
var TimerColors = ['#3ecf8e', '#e8b93f', '#ff8a1f', '#f0414f', '#b3203c'];

function formatDurationSecs(Sec) {
  Sec = Math.max(0, Math.round(Sec));
  if (Sec >= 3600) {
    var Min = Math.floor((Sec + 30) / 60);
    var D = Math.floor(Min / 1440), H = Math.floor((Min % 1440) / 60), M = Min % 60;
    var Parts = [];
    if (D > 0) Parts.push(D + 'd');
    if (H > 0) Parts.push(H + 'h');
    if (M > 0 || Parts.length === 0) Parts.push(M + 'min');
    return Parts.join(' ');
  }
  var Mm = Math.floor(Sec / 60), S = Sec % 60;
  return Mm > 0 ? Mm + 'min ' + S + 's' : S + 's';
}

function refreshTimers() {
  var Passed = (Date.now() - PageLoadedAt) / 1000;
  document.querySelectorAll('.live-timer').forEach(function(El) {
    var ExpectedSec = parseInt(El.getAttribute('data-expected'), 10) * 60;
    var Elapsed = Math.max(0, parseInt(El.getAttribute('data-elapsed'), 10) + Passed);
    var Ratio = Elapsed / ExpectedSec;
    var Last = TimerColors.length - 1;
    var Lap, Pct;
    if (Ratio >= Last + 1) { Lap = Last; Pct = 100; }
    else { Lap = Math.floor(Ratio); Pct = (Ratio - Lap) * 100; }
    var Fill = TimerColors[Lap];
    var Base = Lap === 0 ? '#59616f' : TimerColors[Lap - 1];
    var Text = (Ratio >= 1 ? 'Przekroczono o ' : 'Pozostało ') + formatDurationSecs(Math.abs(ExpectedSec - Elapsed));
    var Label = El.querySelector('.remaining');
    var Bar = El.querySelector('.mini-progress');
    if (Label) { Label.textContent = Text; Label.style.color = Fill; }
    if (Bar) { Bar.style.background = Base; Bar.firstElementChild.style.width = Pct + '%'; Bar.firstElementChild.style.background = Fill; }
  });
}
setInterval(refreshTimers, 1000);
document.addEventListener('DOMContentLoaded', refreshTimers);
