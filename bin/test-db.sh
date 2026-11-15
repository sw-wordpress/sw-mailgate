#!/usr/bin/env bash
# Starts/stops a private, throw-away MariaDB instance for tests (never touches other databases).
# Usage: bin/test-db.sh start|stop|status   (data dir: $SWMG_TEST_DB_DIR, default /tmp/swmg-test-db)
set -euo pipefail

DIR="${SWMG_TEST_DB_DIR:-/tmp/swmg-test-db}"
PORT="${SWMG_TEST_DB_PORT:-33119}"
SOCKET="$DIR/mysql.sock"

case "${1:-}" in
  start)
    if [ -S "$SOCKET" ] && mariadb-admin --socket="$SOCKET" ping >/dev/null 2>&1; then echo "already running"; exit 0; fi
    if [ ! -d "$DIR/data/mysql" ]; then
      mkdir -p "$DIR/data"
      mariadb-install-db --no-defaults --datadir="$DIR/data" --auth-root-authentication-method=normal >/dev/null
    fi
    nohup mariadbd --no-defaults --datadir="$DIR/data" --socket="$SOCKET" --port="$PORT" --bind-address=127.0.0.1 \
      --pid-file="$DIR/mysql.pid" --skip-name-resolve --innodb-flush-log-at-trx-commit=0 --skip-grant-tables=0 \
      --character-set-server=utf8mb4 >"$DIR/mysqld.log" 2>&1 &
    for _ in $(seq 1 60); do
      mariadb-admin --socket="$SOCKET" ping >/dev/null 2>&1 && break; sleep 0.5
    done
    mariadb --socket="$SOCKET" -uroot -e "CREATE USER IF NOT EXISTS 'swmg'@'%' IDENTIFIED BY 'swmg'; GRANT ALL ON *.* TO 'swmg'@'%'; CREATE USER IF NOT EXISTS 'swmg'@'localhost' IDENTIFIED BY 'swmg'; GRANT ALL ON *.* TO 'swmg'@'localhost'; FLUSH PRIVILEGES;"
    mariadb --socket="$SOCKET" -uroot -e "CREATE DATABASE IF NOT EXISTS swmg_wp_tests CHARACTER SET utf8mb4; CREATE DATABASE IF NOT EXISTS mailgate_test CHARACTER SET utf8mb4"
    echo "started on 127.0.0.1:$PORT (user swmg / swmg)"
    ;;
  stop)
    [ -f "$DIR/mysql.pid" ] && kill "$(cat "$DIR/mysql.pid")" 2>/dev/null || true
    echo stopped
    ;;
  status)
    mariadb-admin --socket="$SOCKET" ping 2>&1 || true
    ;;
  *) echo "usage: $0 start|stop|status"; exit 1;;
esac
