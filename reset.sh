#!/usr/bin/env bash
# Kasuje cała bazę TaskManager i tworzy ją od nowa ze schematu (sql/schema.sql).
# Użytkownik bazy 'task_manager' jest tworzony, jeśli nie istnieje; hasło bierze z common.php (DbPass).
# Użycie: ./reset.sh          (pyta o potwierdzenie)
#         ./reset.sh -y       (bez pytania)
set -euo pipefail
cd "$(dirname "$0")"

if [ "$(id -u)" -eq 0 ]; then Mysql=(mysql -u root); else Mysql=(sudo mysql -u root); fi

if [ "${1:-}" != "-y" ]; then
  read -r -p "Ta operacja USUNIE wszystkie dane (użytkownicy, zadania, historia). Kontynuować? [t/N] " Ans
  case "$Ans" in t|T|y|Y) ;; *) echo "Anulowano."; exit 1 ;; esac
fi

DbPass=$(grep -oP "define\('DbPass',\s*'\K[^']*" common.php)

"${Mysql[@]}" -e "DROP DATABASE IF EXISTS TaskManager;"
"${Mysql[@]}" < sql/schema.sql
"${Mysql[@]}" -e "
CREATE USER IF NOT EXISTS 'task_manager'@'localhost' IDENTIFIED BY '$DbPass';
CREATE USER IF NOT EXISTS 'task_manager'@'127.0.0.1' IDENTIFIED BY '$DbPass';
ALTER USER 'task_manager'@'localhost' IDENTIFIED BY '$DbPass';
ALTER USER 'task_manager'@'127.0.0.1' IDENTIFIED BY '$DbPass';
GRANT ALL PRIVILEGES ON TaskManager.* TO 'task_manager'@'localhost';
GRANT ALL PRIVILEGES ON TaskManager.* TO 'task_manager'@'127.0.0.1';
FLUSH PRIVILEGES;"

echo "Baza TaskManager zresetowana. Zarejestruj konto na stronie (trafi do akceptacji),"
echo "a pierwszego admina ustaw (nada uprawnienia ORAZ zaakceptuje konto):"
echo "  sudo mysql -u root TaskManager -e \"UPDATE Users SET IsAdmin=1, IsApproved=1 WHERE Login='twoj_login';\""
