-- Migracja dla istniejącej bazy (uruchom raz): sudo mysql -u root < sql/migration_2.sql
USE TaskManager;

-- 1) czas oczekiwany w minutach zamiast w godzinach (dni/godziny/minuty w formularzu)
ALTER TABLE Tasks ADD COLUMN ExpectedMinutes INT UNSIGNED NULL
  COMMENT 'Oczekiwany czas realizacji w minutach' AFTER ApprovedAt;
UPDATE Tasks SET ExpectedMinutes = ROUND(ExpectedHours * 60)
  WHERE ExpectedHours IS NOT NULL AND ExpectedHours > 0;
ALTER TABLE Tasks DROP COLUMN ExpectedHours;

-- 2) trudność zadania 1-10 (NULL = brak)
ALTER TABLE Tasks ADD COLUMN Difficulty TINYINT UNSIGNED NULL
  COMMENT 'Trudność 1-10, NULL = brak' AFTER ExpectedMinutes;

-- 3) historia: zamiast pary "dodał" + "zaakceptował" jeden wpis "dodał" z czasem akceptacji
UPDATE TaskHistory A
  JOIN (SELECT TaskId, MAX(CreatedAt) AS ApprovedTime FROM TaskHistory WHERE Action = 'approved' GROUP BY TaskId) P
    ON P.TaskId = A.TaskId
  SET A.CreatedAt = P.ApprovedTime, A.Details = NULL
  WHERE A.Action = 'added';
DELETE P FROM TaskHistory P
  JOIN TaskHistory A ON A.TaskId = P.TaskId AND A.Action = 'added'
  WHERE P.Action = 'approved';
-- zadania jeszcze nie zaakceptowane lub odrzucone nie mają wpisu "dodał"
DELETE A FROM TaskHistory A
  JOIN Tasks T ON T.Id = A.TaskId
  WHERE A.Action = 'added' AND T.Status = 'pending';
DELETE A FROM TaskHistory A
  JOIN TaskHistory R ON R.TaskId = A.TaskId AND R.Action = 'rejected'
  WHERE A.Action = 'added';
