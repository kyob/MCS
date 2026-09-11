# Wtyczki źródeł MCS

Każde źródło zgłoszeń to osobna wtyczka w katalogu `plugins/{zrodlo}/`.

W Fazie 1a ten katalog jest **pusty** (brak importerów). Rdzeń zna tylko kontrakt
i wiersze w tabeli `mcs_pluginy` (placeholdery: `shadowserver`, `play`, `snds`).

## Kontrakt (Faza 1a)

Plik: `plugins/{zrodlo}/Plugin.php`  
Przestrzeń nazw: `McsPluginy\{zrodlo}\Plugin`  
Interfejs: `App\Libraries\SourcePluginInterface`

Metody:

- `zrodlo()` — stabilny identyfikator (np. `shadowserver`)
- `pobierz()` — Faza 1b; w 1a może rzucać `RuntimeException('not implemented')`
- `health()` — tablica `['ok' => bool, 'msg' => string]`

Rdzeń ładuje wtyczki przez `App\Libraries\PluginRegistry`. Brak pliku `Plugin.php`
oznacza: źródło jest w rejestrze bazy, ale nie ma jeszcze kodu importera.

## Rejestr w bazie

```bash
php spark mcs:seed-pluginy
php spark mcs:seed-pluginy --dry-run
```

Komenda jest powtarzalna: istniejące wiersze zostawia bez zmian.
Nowe źródła dostają `import_wstrzymany=true`.
