#!/usr/bin/env bash
# MCS — wrapper cron/systemd dla php spark mcs:pobierz
# Użycie: mcs-pobierz.sh [all|shadowserver|play|snds]
# Domyślnie: all. Nie loguje sekretów (.env). Uruchamiać jako www-data.

set -euo pipefail

APP_ROOT="${MCS_APP_ROOT:-/var/www/mcs}"
ZRODLO="${1:-all}"
LOG_DIR="${MCS_POBIERZ_LOG_DIR:-$APP_ROOT/writable/logs}"
STAMP="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

cd "$APP_ROOT"

if [[ ! -x ./spark ]]; then
  echo "[mcs-pobierz] brak ./spark w $APP_ROOT" >&2
  exit 1
fi

mkdir -p "$LOG_DIR"
LOG_FILE="$LOG_DIR/mcs-pobierz.log"

{
  echo "==== $STAMP zrodlo=$ZRODLO ===="
  /usr/bin/php ./spark mcs:pobierz "$ZRODLO"
} >>"$LOG_FILE" 2>&1
