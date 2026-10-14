# MCS — Moja Czysta Sieć

Panel i zbieracz zgłoszeń o reputacji sieci dla małego ISP. MCS zbiera zgłoszenia nadużyć i listingi z wielu źródeł, łączy je z adresami IP własnej sieci (AS) i pokazuje jeden obraz: co jest zgłoszone, przez kogo i co z tym zrobić.

![PHP](https://img.shields.io/badge/PHP-%5E8.2-777bb4) ![CodeIgniter](https://img.shields.io/badge/CodeIgniter-4.7-ee4623) ![PostgreSQL](https://img.shields.io/badge/PostgreSQL-required-336791) ![License](https://img.shields.io/badge/license-MIT-green)

## Co robi

- Importuje zgłoszenia z wielu źródeł przez wtyczki (IMAP, API, pliki): Shadowserver, SNDS, Play, MojeCert, CERT N6, Wanguard, Wazuh, Abusix, Cloudflare Botnet, Qrator, CAIDA Spoofer, speedtest.
- Skanuje własne prefiksy IPv4 na listach DNSBL (lokalny resolver `unbound`, limity zapytań, test kontrolny strefy).
- Dopasowuje lokalnie darmowe listy Spamhaus DROP, DROPv6 i ASN-DROP do prefiksów AS.
- Łączy dane w incydenty z wagą i deduplikacją, z widokiem per IP (`/ip/{adres}`).
- Udostępnia API badge dla LMS+ (token) oraz panel z logowaniem i rolami.

## Wymagania

- PHP 8.2 lub nowszy, rozszerzenia wymagane przez CodeIgniter 4.7
- PostgreSQL
- Composer
- Dla skanera DNSBL: lokalny resolver rekurencyjny (np. `unbound` na 127.0.0.1)

## Instalacja

```bash
composer install
cp .env.example .env     # uzupełnij dane bazy i mcs.badgeToken
php spark migrate
php spark mcs:seed-pluginy
php spark mcs:create-user admin@example.local 'hasło' superadmin
php spark mcs:pobierz all   # import; uruchamiaj z crona co 15 min
```

Docroot serwera WWW: `public/`. Sekrety trzymaj wyłącznie w `.env`. Nie commituj tego pliku.

## Testy

```bash
vendor/bin/phpunit
```

## Architektura

- Rdzeń: CodeIgniter 4 (`app/`), baza PostgreSQL, migracje w `app/Database/Migrations`.
- Źródła: wtyczki w `plugins/{zrodlo}/` (interfejs `SourcePluginInterface`). Opis kontraktu: [plugins/README.md](plugins/README.md).
- Nowe źródła startują jako wstrzymane (`import_wstrzymany=true`). Włączasz je świadomie w panelu.
- Błąd pobrania nigdy nie oznacza "czysto". Brak danych jest osobnym statusem.

## Bezpieczeństwo

Zasady zgłaszania luk: [SECURITY.md](SECURITY.md). Nie umieszczaj kluczy API, haseł ani tokenów w repozytorium.

## Dane zewnętrzne i atrybucja

- Spamhaus DROP / DROPv6 / ASN-DROP: dane © The Spamhaus Project SLU, używane zgodnie z DROP Fair Use Policy. Panel pokazuje źródło i datę pliku. MCS nie odpytuje `zen.spamhaus.org` ani `check.spamhaus.org` automatem.
- Pozostałe źródła: obowiązują ich własne regulaminy i limity. Sprawdź je przed włączeniem wtyczki.
- Używane biblioteki: zobacz `composer.lock` (CodeIgniter 4, licencja MIT).

## Licencja

MIT. Tekst w pliku [LICENSE](LICENSE).

## Konfiguracja

Wszystkie opcje są opisane w komentarzach pliku [.env.example](.env.example). Kontrakt wtyczek źródeł: [plugins/README.md](plugins/README.md).

Przykład crona (co 15 minut):

```cron
*/15 * * * * www-data cd /var/www/mcs && php spark mcs:pobierz all
```
