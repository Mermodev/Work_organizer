#!/usr/bin/env bash
# Uruchamia stronę: upewnia się, że MariaDB działa, i startuje serwer PHP.
# Użycie: ./start.sh            (http://127.0.0.1:8080/index.php)
#         PORT=9000 ./start.sh
set -euo pipefail
cd "$(dirname "$0")"

Port="${PORT:-8080}"
if [ "$(id -u)" -eq 0 ]; then Sudo=(); else Sudo=(sudo); fi

if command -v systemctl >/dev/null 2>&1 && ! systemctl is-active --quiet mariadb; then
  echo "Startuje MariaDB..."
  "${Sudo[@]}" systemctl start mariadb || echo "UWAGA: nie udało się uruchomić MariaDB - uruchom ją ręcznie."
fi

echo "Strona: http://127.0.0.1:$Port/index.php  (Ctrl+C kończy)"
exec php -S "127.0.0.1:$Port" -t .
