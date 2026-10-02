-- Migracja dla istniejącej bazy (uruchom raz, po migration_2.sql): sudo mysql -u root < sql/migration_3.sql
USE TaskManager;

-- trudność zadania -> waga zadania (1-10, NULL = brak); dane zostają bez zmian
ALTER TABLE Tasks CHANGE COLUMN Difficulty Priority TINYINT UNSIGNED NULL
  COMMENT 'Waga 1-10 (kolory jak rangi na CF), NULL = brak';
