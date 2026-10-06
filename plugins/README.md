# Wtyczki źródeł MCS

Każde źródło zgłoszeń to osobna wtyczka w katalogu `plugins/{zrodlo}/`.

W Fazie 1a katalog był pusty (placeholdery w `mcs_pluginy`).  
Faza **1b** dokłada importery (Shadowserver / Play / SNDS) — patrz [docs/09-plan-faza-1b-importery.md](../docs/09-plan-faza-1b-importery.md).
Dodatkowe streamy: MojeCert, CERT N6, Wanguard, Cloudflare Botnet (`cloudflare_botnet`), Qrator Radar (`qrator` — tor sieć/ASN), CAIDA Spoofer (`spoofer` — BCP38, filtr ASN, poza badge hosta).
Enrichment + sync: LMS mapowanie (`lms_mapowanie` — IP→komputer/abonent, poza badge); isMalicious (`ismalicious`); SpamRats (`spamrats` — DNSBL RATS-All, panel `/ip/{ip}`, poza badge); DNSBL AS (`dnsbl` — UCEPROTECT/SpamCop/DroneBL/+Spamhaus DQS, skan LMS codziennie + pełne AS raz/tydzień, incydenty + `/dashboard/dnsbl`); Speedtest (`speedtest` — portal Ookla lub CSV Servers reporting, filtr AS198050, **agregaty /24** na `/dashboard/speedtest`, poza badge).

## Kontrakt

Plik: `plugins/{zrodlo}/Plugin.php`  
Przestrzeń nazw: `McsPluginy\{zrodlo}\Plugin`  
Interfejs: `App\Libraries\SourcePluginInterface`

Metody:

- `zrodlo()` — stabilny identyfikator (np. `shadowserver`)
- `pobierz()` — import (IMAP / API / plik) → koperta → tabela źródła → `IncydentZapis`  
  (wyjątek: `lms_mapowanie` / `ismalicious` / `spamrats` / `speedtest` zapisują tylko enrichment, bez indeksu)
- `health()` — tablica `['ok' => bool, 'msg' => string]`

Rdzeń ładuje wtyczki przez `App\Libraries\PluginRegistry`.

Biblioteki wspólne (nie wtyczki): `ImportKoperta`, `WagaV1` (fallback `WagaV0`), `IncydentZapis`, `App\Libraries\Imap\*`.  
Waga: [docs/13-waga-v1.md](../docs/13-waga-v1.md).  
Enrichment (Faza 3, **nie** w indeksie badge): `App\Libraries\Enrichment\*` — np. `php spark mcs:abuseipdb <ip>`, `php spark mcs:lms-mapowanie <ip>`.  
Klucze skrzynek: [docs/09-imap-konfiguracja-env.md](../docs/09-imap-konfiguracja-env.md).

## Rejestr w bazie

```bash
php spark mcs:seed-pluginy
php spark mcs:seed-pluginy --dry-run
```

Komenda jest powtarzalna: istniejące wiersze zostawia bez zmian.
Nowe źródła dostają `import_wstrzymany=true`.
