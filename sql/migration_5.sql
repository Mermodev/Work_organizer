-- Migracja dla istniejącej bazy (uruchom raz, po migration_4.sql): sudo mysql -u root TaskManager < sql/migration_5.sql
USE TaskManager;

-- 1) nowa kolumna na tablicy: "Czekające na dodanie" między "W trakcie realizacji" a "Zrealizowane" -
-- dla zadań, których praca jest już napisana (np. na branchu), ale jeszcze nie trafiła do main.
ALTER TABLE Tasks MODIFY COLUMN Status
  ENUM('pending','todo','in_progress','awaiting_merge','done','abandoned','deleted','other')
  NOT NULL DEFAULT 'pending';

-- 2) rejestracja wymaga teraz akceptacji admina. Wszystkie JUŻ istniejące konta są automatycznie
-- zatwierdzane, żeby nikt z obecnych użytkowników nie stracił dostępu po tej migracji.
ALTER TABLE Users ADD COLUMN IsApproved TINYINT(1) NOT NULL DEFAULT 0 AFTER IsAdmin;
UPDATE Users SET IsApproved = 1;
