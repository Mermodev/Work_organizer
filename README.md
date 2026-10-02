# Task Manager — PHP + MySQL (mysqli)

Płaska struktura, mało plików, kod prosty (bez warstw abstrakcji) - `common.php`
trzyma połączenie do bazy, hashe i drobne helpery, każda strona (`tasks.php`,
`my_tasks.php`, `history.php`, `admin.php`) obsługuje sama swoje akcje (POST)
i sama renderuje HTML.

## Uruchomienie na Arch Linux

```bash
sudo pacman -S php php-gd mariadb
sudo mariadb-install-db --user=mysql --basedir=/usr --datadir=/var/lib/mysql   # tylko raz
sudo systemctl enable --now mariadb
```

W `/etc/php/php.ini` odkomentuj:

```
extension=mysqli
extension=gd
```

Baza:

```bash
sudo mysql -u root < sql/schema.sql
sudo mysql -u root -e "
CREATE USER 'task_manager'@'localhost' IDENTIFIED BY 'twoje_haslo';
CREATE USER 'task_manager'@'127.0.0.1' IDENTIFIED BY 'twoje_haslo';
GRANT ALL PRIVILEGES ON TaskManager.* TO 'task_manager'@'localhost';
GRANT ALL PRIVILEGES ON TaskManager.* TO 'task_manager'@'127.0.0.1';
FLUSH PRIVILEGES;"
```

(Obie nazwy hosta na wszelki wypadek - `common.php` łączy się przez `127.0.0.1`,
czyli TCP, co dla MySQL/MariaDB liczy się jako inny "host" niż `localhost`.)

W `common.php` ustaw `DbPass` i własny `PasswordSalt`.

Serwer (document root = katalog projektu, bo ścieżki w HTML są względne od niego):

```bash
php -S 127.0.0.1:8080 -t .
```

<http://127.0.0.1:8080/index.php>

Pierwszy admin — zarejestruj się normalnie, potem:

```bash
sudo mysql -u root TaskManager -e "UPDATE Users SET IsAdmin=1, IsApproved=1 WHERE Login='twoj_login';"
```
To polecenie jest też jedynym sposobem zaakceptowania PIERWSZEGO konta - samo
zarejestrowanie się nie wystarczy (patrz niżej „Akceptacja nowych użytkowników”),
a nie ma jeszcze żadnego admina, kto mógłby to zrobić przez panel.
Wyloguj się i zaloguj ponownie (link do panelu pojawia się po nowym zalogowaniu).

Aktualizacja istniejącej bazy (czas w minutach, trudność, nowa historia): `sudo mysql -u root < sql/migration_2.sql` (jednorazowo). Jeśli baza powstała przed zmianą nazwy „trudność” → „waga”, uruchom dodatkowo `sudo mysql -u root < sql/migration_3.sql`. Jeśli baza powstała przed dodaniem statusu usuwania zadań, uruchom też `sudo mysql -u root TaskManager < sql/migration_4.sql`. Kolumna „Czekające na dodanie” i wymóg akceptacji nowych kont wymagają `sudo mysql -u root TaskManager < sql/migration_5.sql` (wszystkie już istniejące konta zostają przy tym automatycznie zaakceptowane, więc nikt nie traci dostępu).

Captcha — w paczce jest 10 gotowych obrazków. Więcej: `php tools/generate_captcha_samples.php`.

## Struktura

```
common.php        połączenie mysqli, własne hashe, sesja, helpery (jeden plik)
index.php         strona główna z paskiem postępu + Twoje statystyki
register.php      rejestracja + captcha + siła hasła
login.php         logowanie
logout.php        wylogowanie
tasks.php         tablica 4-kolumnowa; tu też obsługa: dodaj/przyjmij/zgłoś gotowe/dodano do main/wróć/odłóż
my_tasks.php      moje zadania + filtry + sortowanie; ta sama obsługa akcji
history.php       historia: filtr po użytkowniku, zawsze najnowsze->najstarsze, 50/stronę
graph.php         graf commitów repozytorium GitHub (wszystkie branche, interaktywny); ?demo=1 = dane przykładowe
graph_data.php    JSON dla grafu: serwer pyta GitHub API tokenem, trzyma cache w katalogu tymczasowym
admin.php         panel admina: zadania (pending/todo/in_progress/awaiting_merge/done) + użytkownicy + nowe konta (?tab=pending|todo|in_progress|awaiting_merge|done|users|new_users)
assets/           css (dark theme) + app.js (cała aplikacja) + graph.js (tylko strona grafu)
Bot_veryfication/ obrazki captchy - nazwa pliku = hash1(tekst na obrazku)
sql/schema.sql    3 tabele: Users, Tasks, TaskHistory
tools/            generator przykładowych obrazków captchy + graph_demo.json (dane do ?demo=1)
```

Modal ze szczegółami zadania nie chodzi przez AJAX - dane zadania są zapisane
wprost w atrybucie `data-task` karty (JSON), JS tylko je czyta i wypełnia modal.
Akcje (przyjmij / zakończ / odłóż / dodaj) to zwykłe formularze POST na tę samą
stronę, z przekierowaniem na końcu (żeby F5 nie powtarzało akcji).

## Graf commitów (graph.php)

Strona dla zalogowanych użytkowników (link „Graf commitów” w menu): graf wszystkich
branchy i tagów repozytorium `GithubRepo` (domyślnie `Mermodev/Call-of-the-void-private`).

**Repo jest prywatne, więc serwer potrzebuje tokena GitHub.** Token zostaje wyłącznie
po stronie PHP - `graph_data.php` robi proxy do GitHub API, przeglądarka go nigdy nie widzi.

1. GitHub → Settings → Developer settings → Personal access tokens → **Fine-grained tokens** → Generate new token.
2. *Repository access*: **Only select repositories** → tylko `Call-of-the-void-private`
   (jeśli to repo organizacji, wybierz organizację jako *Resource owner* i poczekaj na zatwierdzenie).
3. *Repository permissions*: **Contents: Read-only** (Metadata: Read-only dojdzie automatycznie). Nic więcej.
4. Ustaw token jedną z dwóch metod:

```bash
GITHUB_TOKEN=github_pat_xxxxxxxx ./start.sh     # zmienna środowiskowa (zalecane - token nie ląduje w pliku)
```

albo wpisz go w `common.php` w `GithubToken` (wtedy pilnuj, żeby `common.php` nie trafił do publicznego repo).

PHP musi mieć włączone `extension=curl` i `extension=openssl` (w `/etc/php/php.ini`).
Gdy czegoś brakuje, strona pokazuje konkretny komunikat (brak tokena / token odrzucony /
brak dostępu do repo / limit zapytań / brak połączenia). Przycisk „Zobacz podgląd na danych
przykładowych” (albo `graph.php?demo=1`) działa bez tokena.

**Jak to działa**
- Pobieranie: domyślny branch do 2000 commitów, każdy kolejny branch tylko do miejsca, w którym wchodzi
  w już pobraną historię (zwykle 1-2 zapytania na branch; branche już scalone nie kosztują nic).
- Cache: `GithubCacheSeconds` (90 s) w `sys_get_temp_dir()` - celowo NIE w katalogu projektu,
  bo ten jest serwowany przez `php -S` i historia prywatnego repo byłaby do pobrania bez logowania.
  „Odśwież” omija cache, ale nie częściej niż co 15 s (limit GitHub API to 5000 zapytań/h).
- Układ grafu liczy `graph.js` (przypisywanie „torów” jak w `git log --graph`), rysowanie to własny SVG.

**Interakcje**: klik w commit → panel szczegółów + podświetlenie jego przodków i potomków („Linia commita” wyłącza to);
przełączniki branchy po lewej (przeliczają graf; wybór zapamiętany w przeglądarce); najechanie na branch = podgląd jego historii;
klik w nazwę brancha/tagu = skok do commita; szukanie (`/`, Enter = następne trafienie, Shift+Enter = poprzednie)
po opisie/autorze/sha/nazwie brancha; filtr autora; `↑`/`↓` lub `j`/`k` - poprzedni/następny commit; `Esc` - zamknij panel;
link do commita (`graph.php#abc1234`) otwiera go od razu.

**Uwaga o prywatności:** każdy *zatwierdzony* użytkownik tej aplikacji zobaczy opisy commitów prywatnego repo.
Jeśli ma to być tylko dla adminów, zamień `require_login()` na `require_admin()` w `graph.php`,
a w `graph_data.php` dodaj sprawdzenie `IsAdmin`.

## Statusy zadań

| Status            | Znaczenie                                  | Kolor na pasku |
|-------------------|---------------------------------------------|----------------|
| `pending`         | czeka na akceptację administratora           | niebieski      |
| `todo`            | zaakceptowane / odłożone, wolne              | szary (Inne)   |
| `in_progress`     | w trakcie realizacji                         | żółty          |
| `awaiting_merge`  | praca napisana (np. na branchu), czeka na dodanie do main | cyjan |
| `done`            | zrealizowane                                 | zielony        |
| `abandoned`       | odrzucone przez admina                       | czerwony       |
| `deleted`         | usunięte przez admina (zostaje w historii)   | -              |
| `other`           | inne (ręcznie)                               | szary          |

**Uwaga:** „Odłóż zadanie" wraca do `todo` (pula dostępnych), NIE do `abandoned`.
`abandoned` jest teraz używane tylko przy odrzuceniu propozycji przez admina.

## Kolumna „Czekające na dodanie”

Czwarta kolumna na tablicy, między „W trakcie realizacji” a „Zrealizowane” -
dla zadań, których praca jest już napisana (np. wypchnięta na branch), ale
jeszcze nie trafiła do main. Przepływ zadania w trakcie realizacji:

1. Wykonawca klika **„Gotowe - czeka na dodanie”** → zadanie trafia do
   `awaiting_merge` (kolumna „Czekające na dodanie”).
2. Stąd wykonawca może kliknąć **„Dodano do main - zrealizowane”** (`done`,
   dopiero to ustawia `FinishedAt`) albo **„Wróć do realizacji”**, jeśli np.
   trzeba jeszcze coś poprawić przed scaleniem.
3. „Odłóż zadanie” działa też z tego etapu - zwalnia wykonawcę i zadanie
   wraca do puli (`todo`).

Admin ma osobną zakładkę `awaiting_merge` z podglądem i wymuszonymi akcjami
(„Wymuś dodanie do main” / „Wymuś odłożenie”) na wypadek, gdyby wykonawca
zniknął w trakcie oczekiwania na scalenie - te same `force_finish` /
`force_abandon` co na zakładce „W trakcie realizacji”.

## Hashowanie haseł

`common.php` — polynomial rolling hash (styl competitive programming), `hash1()`
i `hash2()` na dwóch niezależnych parach (baza, moduł). W bazie tylko te dwa
hashe, nigdy hasło jawne. To NIE jest kryptograficzny hash (szybki = podatny na
brute-force) — do zaliczenia w porządku, do produkcji podmień `hash_password()`
na `password_hash()`.

## Czas realizacji i pasek postępu

Oczekiwany czas wpisuje się w polach dni / godziny / minuty, w bazie jest jedna
liczba (`ExpectedMinutes`). Pasek na zadaniu w trakcie realizacji zapełnia się
w kolejnych „okrążeniach” (kolor tekstu „Pozostało / Przekroczono o” = kolor
aktualnie zapełniającego się paska):

| Okrążenie (upłynięty czas / oczekiwany) | Kolor    |
|------------------------------------------|----------|
| 0–100%                                   | zielony (na szarym) |
| 100–200%                                 | żółty    |
| 200–300%                                 | pomarańczowy |
| 300–400%                                 | czerwony |
| 400–500%, potem już na stałe             | bordowy  |

Kolory: `progress_colors()` w `common.php`.

## Waga zadania (1–10)

Opcjonalna liczba 1–10 wpisywana w polu (`Priority`, NULL = brak), ustawiana
przy dodawaniu zadania i w panelu admina (po wadze można też sortować — zadania bez wagi zawsze na końcu); kolor pola i plakietki zależy od liczby. Kolory w stylu rang z Codeforces — `priority_palette()` w
`common.php` (1 gray, 2 lime, 3 cyan, 4 blue, 5 purple, 6 pastel_orange,
7 orange, 8 pastel_red, 9 red, 10 dark_red).

## Akceptacja nowych użytkowników

Rejestracja (`register.php`) nie loguje już automatycznie - nowe konto dostaje
`IsApproved=0` i czeka w zakładce admina „Nowi użytkownicy” (`admin.php?tab=new_users`,
z licznikiem oczekujących przy nazwie zakładki). Próba zalogowania się na
niezaakceptowane konto kończy się komunikatem „Twoje konto oczekuje jeszcze
na akceptację administratora.” (`login.php`). Admin może zadanie konto:

- **Zaakceptować** - `IsApproved=1`, konto może się od teraz zalogować.
- **Odrzucić** - konto jest kasowane z bazy. Bezpieczne tylko dlatego, że
  niezaakceptowane konto nigdy się nie zalogowało, więc nie ma żadnych
  zadań powiązanych kluczem obcym (`AddedBy`/`StartedBy`/`ApprovedBy`).

Pierwsze konto administratora trzeba zaakceptować ręcznie w bazie (patrz
sekcja „Uruchomienie” wyżej) - dopóki nie ma żadnego zatwierdzonego admina,
nikt nie może tego zrobić przez panel.

## Historia

Propozycja zadania nie trafia do historii od razu. Dopiero akceptacja przez
admina tworzy jeden wpis „<autor> dodał(a) zadanie …” z czasem akceptacji.
Odrzucenie zapisuje wpis „odrzucił(a)”.
# Work_organizer
