-- Migracja dla istniejącej bazy (uruchom raz): sudo mysql -u root TaskManager < sql/migration_4.sql
-- Dodaje status 'deleted': admin usuwając nieprzyjęte zadanie nie kasuje już wiersza
-- (co wcześniej wymagało kasowania powiązanej historii przez FkHistoryTask) - zamiast tego
-- zadanie zostaje oznaczone jako usunięte, znika z list, ale jego historia jest zachowana.
USE TaskManager;

ALTER TABLE Tasks MODIFY COLUMN Status
  ENUM('pending','todo','in_progress','done','abandoned','deleted','other')
  NOT NULL DEFAULT 'pending';
