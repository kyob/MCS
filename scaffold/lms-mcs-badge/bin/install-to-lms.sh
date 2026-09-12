#!/usr/bin/env bash
# Instaluje scaffold LMSMcsBadgePlugin do drzewa LMS+ (staging lub prod — świadomie).
# Domyślnie: dry-run. Nic nie nadpisuje bez --yes. Nie kopiuje sekretów.
set -euo pipefail

SRC_DIR="$(cd "$(dirname "$0")/.." && pwd)"
LMS_ROOT="${LMS_ROOT:-/var/www/html/lmsplus}"
YES=0
FORCE=0

usage() {
  cat <<'EOF'
Użycie: install-to-lms.sh [--lms-root DIR] [--yes] [--force]

  --lms-root DIR   Korzeń LMS+ (domyślnie /var/www/html/lmsplus lub $LMS_ROOT)
  --yes            Wykonaj kopiowanie (bez tego tylko dry-run)
  --force          Nadpisz istniejący plugins/LMSMcsBadgePlugin (ostrożnie)

Skrypt:
  - kopiuje TYLKO pliki pluginu (PHP/HTML), BEZ tokenów
  - nie modyfikuje lms.ini
  - wymaga ręcznego dopisania [mcs] w configu LMS (patrz config/lms.ini.example)
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

DEST="${LMS_ROOT%/}/plugins/LMSMcsBadgePlugin"

echo "== LMSMcsBadgePlugin installer =="
echo "Źródło:  $SRC_DIR"
echo "LMS:     $LMS_ROOT"
echo "Cel:     $DEST"
echo

if [[ ! -d "$LMS_ROOT" ]]; then
  echo "BŁĄD: brak katalogu LMS: $LMS_ROOT" >&2
  echo "Na CT MCS drzewo LMS zwykle nie istnieje — uruchom ten skrypt na hoście LMS." >&2
  exit 1
fi

if [[ ! -d "$LMS_ROOT/plugins" ]]; then
  echo "BŁĄD: brak $LMS_ROOT/plugins — to nie wygląda na korzeń LMS+." >&2
  exit 1
fi

if [[ -e "$DEST" && "$FORCE" -ne 1 ]]; then
  echo "BŁĄD: cel już istnieje: $DEST" >&2
  echo "Użyj --force żeby nadpisać (tylko po świadomej decyzji / staging)." >&2
  exit 1
fi

FILES=(
  LMSMcsBadgePlugin.php
  lib/McsBadgeClient.php
  lib/McsBadgeCache.php
  templates/nodeinfo-badge.html
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
  echo "DRY-RUN: nic nie skopiowano. Dodaj --yes aby zainstalować."
  echo "Potem ręcznie:"
  echo "  1) Dopisz [mcs] do lms.ini (wzór: config/lms.ini.example) — ten sam token co mcs.badgeToken"
  echo "  2) Włącz plugin w panelu LMS+ (jak inne LMS*Plugin)"
  echo "  3) Sprawdź nodeinfo jednego hosta z IP z incydentami"
  exit 0
fi

mkdir -p "$DEST/lib" "$DEST/templates" "$DEST/config"
for f in "${FILES[@]}"; do
  install -m 0644 "$SRC_DIR/$f" "$DEST/$f"
done

echo "Zainstalowano do: $DEST"
echo "NIE zapisano tokenu. Uzupełnij lms.ini sekcją [mcs] ręcznie."
echo "Weryfikacja składni:"
php -l "$DEST/LMSMcsBadgePlugin.php"
php -l "$DEST/lib/McsBadgeClient.php"
php -l "$DEST/lib/McsBadgeCache.php"
