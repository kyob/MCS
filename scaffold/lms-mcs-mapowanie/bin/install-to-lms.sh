#!/usr/bin/env bash
# Instaluje scaffold LMSMcsMapowaniePlugin do drzewa LMS+.
# Domyślnie: dry-run. Nie kopiuje sekretów / nie rusza lms.ini.
#
# TEST mapowania: uruchamiaj na LMS 192.0.2.60 (DEV/TEST).
# PROD LMS 192.0.2.50 — tylko gdy użytkownik wyraźnie zleci (nie mylić z badge).
set -euo pipefail

SRC_DIR="$(cd "$(dirname "$0")/.." && pwd)"
LMS_ROOT="${LMS_ROOT:-/var/www/html/lmsplus}"
YES=0
FORCE=0

usage() {
  cat <<'EOF'
Użycie: install-to-lms.sh [--lms-root DIR] [--yes] [--force]

  --lms-root DIR   Korzeń LMS+ (domyślnie /var/www/html/lmsplus lub $LMS_ROOT).
                   Uruchamiaj na hoście LMS — ścieżka lokalna na tym hoście.
  --yes            Wykonaj kopiowanie
  --force          Nadpisz istniejący plugins/LMSMcsMapowaniePlugin

TEST mapowania: host 192.0.2.60. Przykład na hoście TEST:
  ./bin/install-to-lms.sh --lms-root /var/www/html/lmsplus --yes

PROD (192.0.2.50): NIE instalować, dopóki użytkownik tego nie zleci.
Skrypt kopiuje TYLKO pliki pluginu. Config [mcs] map_token dopisz ręcznie na tym LMS.
API działa standalone (api.php) — wpis w phpui.plugins NIE jest wymagany.
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --lms-root) LMS_ROOT="${2:-}"; shift 2 ;;
    --yes) YES=1; shift ;;
    --force) FORCE=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Nieznany argument: $1" >&2; usage >&2; exit 2 ;;
  esac
done

DEST="${LMS_ROOT%/}/plugins/LMSMcsMapowaniePlugin"

echo "== LMSMcsMapowaniePlugin installer =="
echo "Źródło:  $SRC_DIR"
echo "LMS:     $LMS_ROOT"
echo "Cel:     $DEST"
echo

if [[ ! -d "$LMS_ROOT" ]]; then
  echo "BŁĄD: brak katalogu LMS: $LMS_ROOT" >&2
  echo "Uruchom ten skrypt na hoście LMS (TEST: 192.0.2.60)." >&2
  exit 1
fi

if [[ ! -d "$LMS_ROOT/plugins" ]]; then
  echo "BŁĄD: brak $LMS_ROOT/plugins — to nie wygląda na korzeń LMS+." >&2
  exit 1
fi

if [[ -e "$DEST" && "$FORCE" -ne 1 ]]; then
  echo "BŁĄD: cel już istnieje: $DEST (użyj --force)" >&2
  exit 1
fi

FILES=(
  LMSMcsMapowaniePlugin.php
  api.php
  config/lms.ini.example
  README.md
)

echo "Pliki do skopiowania:"
for f in "${FILES[@]}"; do
  if [[ ! -f "$SRC_DIR/$f" ]]; then
    echo "  BRAK: $f" >&2
    exit 1
  fi
  echo "  $f"
done
echo

if [[ "$YES" -ne 1 ]]; then
  echo "DRY-RUN — nic nie skopiowano. Uruchom z --yes."
  echo "Potem ręcznie:"
  echo "  1) Dopisz [mcs] map_token do /etc/lms/lms.ini (wzór: config/lms.ini.example)"
  echo "     — osobny sekret, NIE ten sam co badge_token"
  echo "  2) MCS .env: mcs.lms.mapToken = ten sam; wyczyść mcs.lms.fixturePath"
  echo "  3) Smoke: curl health bez tokenu → 401 JSON; z Bearer → 200"
  exit 0
fi

mkdir -p "$DEST/config"
install -m 0644 "$SRC_DIR/LMSMcsMapowaniePlugin.php" "$DEST/LMSMcsMapowaniePlugin.php"
install -m 0644 "$SRC_DIR/api.php" "$DEST/api.php"
install -m 0644 "$SRC_DIR/config/lms.ini.example" "$DEST/config/lms.ini.example"
install -m 0644 "$SRC_DIR/README.md" "$DEST/README.md"

echo "OK: zainstalowano w $DEST"
echo "NIE zapisano tokenu. Dopisz [mcs] map_token w /etc/lms/lms.ini."
echo "Uwaga Apache: plugins/.htaccess ma „Require all denied” — działa tylko gdy"
echo "  AllowOverride None (jak na TEST .60). Przy AllowOverride All api.php dostanie 403."
echo "Weryfikacja składni:"
php -l "$DEST/LMSMcsMapowaniePlugin.php"
php -l "$DEST/api.php"
