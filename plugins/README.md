# Wtyczki źródeł MCS

Każde źródło zgłoszeń to osobna wtyczka w katalogu `plugins/{zrodlo}/`.

W Fazie 1a katalog był pusty (placeholdery w `mcs_pluginy`).  
Faza **1b** dokłada importery (Shadowserver / Play / SNDS) — patrz [docs/09-plan-faza-1b-importery.md](../docs/09-plan-faza-1b-importery.md).

## Kontrakt

Plik: `plugins/{zrodlo}/Plugin.php`  
Przestrzeń nazw: `McsPluginy\{zrodlo}\Plugin`  
Interfejs: `App\Libraries\SourcePluginInterface`

Metody:

- `zrodlo()` — stabilny identyfikator (np. `shadowserver`)
- `pobierz()` — import (IMAP / API / plik) → koperta → tabela źródła → `IncydentZapis`
- `health()` — tablica `['ok' => bool, 'msg' => string]`

Rdzeń ładuje wtyczki przez `App\Libraries\PluginRegistry`.

Biblioteki wspólne (nie wtyczki): `ImportKoperta`, `WagaV0`, `IncydentZapis`, `App\Libraries\Imap\*`.  
Klucze skrzynek: [docs/09-imap-konfiguracja-env.md](../docs/09-imap-konfiguracja-env.md).

## Rejestr w bazie

```bash
php spark mcs:seed-pluginy
php spark mcs:seed-pluginy --dry-run
```

Komenda jest powtarzalna: istniejące wiersze zostawia bez zmian.
Nowe źródła dostają `import_wstrzymany=true`.
