-- Schemat bazy danych systemu zarządzania zadaniami
-- Użycie: mysql -u root -p < schema.sql

CREATE DATABASE IF NOT EXISTS TaskManager CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE TaskManager;

-- Użytkownicy. Hasło NIGDY nie jest trzymane w formie jawnej - tylko dwa
-- niezależne hashe (Hash1, Hash2) liczone własnymi funkcjami (patrz includes/hash.php).
-- Podwójne hashowanie = duża redundancja, żeby kolizja jednej funkcji hashującej
-- nie wystarczyła do podrobienia hasła.
-- IsApproved: nowe konto musi zostać zaakceptowane przez admina, zanim będzie mogło się zalogować.
CREATE TABLE Users (
  Id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  Login         VARCHAR(50)  NOT NULL UNIQUE,
  Hash1         CHAR(16)     NOT NULL,
  Hash2         CHAR(16)     NOT NULL,
  IsAdmin       TINYINT(1)   NOT NULL DEFAULT 0,
  IsApproved    TINYINT(1)   NOT NULL DEFAULT 0,
  CreatedAt     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Zadania. Status determinuje w której kolumnie / kategorii pasek postępu zadanie się znajduje.
--   pending      -> Czekające na akceptację administratora (niebieski)
--   todo         -> Zaakceptowane, nikt jeszcze nie zaczął (kolumna 1)
--   in_progress  -> W trakcie realizacji (żółty)
--   awaiting_merge -> Czekające na dodanie: praca napisana (np. na branchu), ale jeszcze nie w main (cyjan)
--   done         -> Zrealizowane (zielony)
--   abandoned    -> Porzucone (czerwony)
--   deleted      -> Usunięte przez admina (zadanie zaakceptowane, ale nieprzyjęte) - zostaje w historii
--   other        -> Inne (szary) - ręcznie ustawiane przez admina
CREATE TABLE Tasks (
  Id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  Title          VARCHAR(150) NOT NULL,
  Description    TEXT         NOT NULL,
  Status         ENUM('pending','todo','in_progress','awaiting_merge','done','abandoned','deleted','other')
                               NOT NULL DEFAULT 'pending',
  AddedBy        INT UNSIGNED NOT NULL,
  CreatedAt      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ApprovedBy     INT UNSIGNED NULL,
  ApprovedAt     DATETIME     NULL,
  ExpectedMinutes INT UNSIGNED NULL COMMENT 'Oczekiwany czas realizacji w minutach (dni/godziny/minuty przeliczane w aplikacji)',
  Priority     TINYINT UNSIGNED NULL COMMENT 'Waga 1-10 (kolory jak rangi na CF), NULL = brak',
  StartedBy      INT UNSIGNED NULL,
  StartedAt      DATETIME     NULL,
  FinishedAt     DATETIME     NULL,
  CONSTRAINT FkTasksAddedBy    FOREIGN KEY (AddedBy)    REFERENCES Users(Id),
  CONSTRAINT FkTasksApprovedBy FOREIGN KEY (ApprovedBy) REFERENCES Users(Id),
  CONSTRAINT FkTasksStartedBy  FOREIGN KEY (StartedBy)  REFERENCES Users(Id)
) ENGINE=InnoDB;

-- Historia wszystkich zdarzeń na zadaniach (dodanie, akceptacja, odrzucenie,
-- przyjęcie, odłożenie, ukończenie, usunięcie...) do zakładki "Historia".
CREATE TABLE TaskHistory (
  Id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  TaskId     INT UNSIGNED NOT NULL,
  UserId     INT UNSIGNED NULL,
  Action     VARCHAR(30)  NOT NULL,
  Details    VARCHAR(255) NULL,
  CreatedAt  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT FkHistoryTask FOREIGN KEY (TaskId) REFERENCES Tasks(Id),
  CONSTRAINT FkHistoryUser FOREIGN KEY (UserId) REFERENCES Users(Id)
) ENGINE=InnoDB;

-- Pierwsze konto administratora: zarejestruj się normalnie (konto trafi do akceptacji,
-- ale nie będzie jeszcze komu go zaakceptować), a potem ręcznie ustaw mu oba uprawnienia:
--   sudo mysql -u root TaskManager -e "UPDATE Users SET IsAdmin=1, IsApproved=1 WHERE Login='twoj_login';"
-- Dopiero wtedy będzie mógł się zalogować i zacząć akceptować kolejnych użytkowników.
