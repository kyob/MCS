# blocklist.de, URLhaus i GreyNoise

Data: 2026-10-22. Status: do przeglądu.

„Tak” po pytaniu o wiązanie URL-i oznacza wariant 1: URLhaus tworzy incydent tylko wtedy, gdy host URL-a jest adresem IP należącym do prefiksów AS. Domen nie rozwiązujemy.

## Cel

Trzy źródła na istniejącym kontrakcie `SourcePluginInterface`. Dwa zasilają kolejkę i badge. Trzecie jest lookupem na karcie IP.

| Źródło | Identyfikator | Zapis |
|---|---|---|
| blocklist.de | `blocklist_de` | incydent, widoczny w badge |
| URLhaus | `urlhaus` | incydent, widoczny w badge |
| GreyNoise Community | `greynoise` | cache enrichment, poza indeksem i badge |

Żadne źródło nie odcina abonenta, nie wysyła maila i nie zapisuje nic w LMS.

## Wspólne reguły

- Seeder dopisuje wiersz z `import_wstrzymany=true` i `ukryty_w_ui=false`. Istniejących flag nie rusza. `opis` aktualizuje, jak dziś `PluginSeeder`.
- Cron `mcs:pobierz` zostaje co 15 minut. Importer sam pomija przebieg i zwraca `komunikatPominiecia()`, tak jak Spamhaus. `PobierzSerwis` zapisuje wtedy status `pominiety`.
- Wyjątek z `pobierz()` albo `health_ok=false` oznacza błąd. Udane pobranie i zero adresów w prefiksach to sukces: komunikat w stylu „0 trafień w prefiksach”.
- Filtr AS wyłączony albo pusta lista prefiksów: przebieg kończy się błędem „brak prefiksów AS”, bez nowych incydentów. Całej listy świata nie wciągamy do indeksu.
- Adres należy do sieci, gdy `As198050Matcher::czyNaszeIp()` zwraca prawdę (IPv4 i IPv6).
- Sekrety wyłącznie w `.env`. Komunikat health, historia importu i log nie zawierają klucza ani pełnego URL-a z kluczem.
- Testy jednostkowe czytają fixture z repo. Żywe HTTP jest wyłączone w testach.
- Po wdrożeniu zaktualizować `plugins/README.md`, `.env.example` i oczekiwaną listę w `tests/database/PluginSeederTest.php`.

Waga przez `WagaV1::wylicz()`:

- blocklist.de, zwykła lista: kategoria `abuse` → `high`.
- blocklist.de, adres jest też na `strongips`: `wylicz('abuse', ['severity' => 'critical'])` → `critical`.
- URLhaus: kategoria `malware` → `critical`.

Incydent idzie przez `IncydentZapis`. Podmiot to IP. Zniknięcie adresu z kolejnego zrzutu nie kasuje incydentu i nie ustawia `ukryty_w_ui`. Przestajemy tylko odświeżać `ostatnio_widziane`, więc okno kolejki samo go starzeje.

## Harmonogram importerów

Osobno dla `blocklist_de` i `urlhaus`, stan w `mcs_ustawienia`:

- `{zrodlo}.ostatnia_proba_at`
- `{zrodlo}.nastepne_po`
- `{zrodlo}.bledy_z_rzedu`

Reguły, kopia zachowania Spamhaus, bez refaktoru `plugins/spamhaus/Harmonogram.php`:

- po udanym przebiegu następny za 1 godzinę,
- twardo nie częściej niż raz na godzinę,
- po błędzie backoff 1 h, 2 h, 4 h, sufit 24 h.

GreyNoise nie ma harmonogramu pobierania.

## blocklist.de

Listy z ostatnich 48 godzin, jeden adres na linię, regenerowane co 30 minut. Domyślny zestaw:

- `ssh`
- `mail`
- `bruteforcelogin`
- `bots`
- `strongips`

URL listy: `https://lists.blocklist.de/lists/{nazwa}.txt`. `all.txt` nie wchodzi, bo gubi nazwę usługi. Nazwa spoza tej piątki jest odrzucana (stała allowlista), także gdy przyjdzie z `mcs.blocklistDe.listy`. Nieznana nazwa albo pusta lista po odrzuceniu to błąd przebiegu i zero zapisów. Puste `mcs.blocklistDe.listy` oznacza domyślną piątkę.

Przebieg:

1. Jeśli nie pora, zwróć pominięcie i nie rób HTTP.
2. Pobierz każdą listę po HTTPS, timeout 30 s, bez podążania za przekierowaniem na inny host.
3. Gdy istnieje `{nazwa}.txt.md5` i suma się nie zgadza, cały przebieg jest błędem i nic nie zapisujemy.
4. Plik MD5 niedostępny: jedziemy na treści i dopisujemy to do komunikatu health.
5. Pusta lista (zero bajtów albo same puste linie) jest poprawna.
6. Linia, która nie jest adresem IP, jest pomijana. Gdy plik ma treść i zero poprawnych adresów, to błąd parsowania.
7. Najpierw pobierz i sparsuj wszystkie listy. Zapis incydentów startuje dopiero, gdy każda lista jest poprawna. Błąd jednej listy przerywa przebieg i z tego przebiegu nie ma żadnych zapisów. Stare incydenty zostają.
8. Zsumuj listy per IP i zostaw tylko adresy, dla których `czyNaszeIp()` jest prawdą.

Incydent:

- `zrodlo`: `blocklist_de`
- `klucz_dedupu`: `blocklist_de|{ip}`
- `kategoria`: `abuse`
- `tytul`: `blocklist.de: {usługi po przecinku}`
- payload: listy, liczba list, informacja „blocklist.de, ostatnie 48 h”

Osobnego widoku dashboardu nie ma. Kolejka i `/ip/{ip}` wystarczą.

## URLhaus

Auth-Key z https://auth.abuse.ch/, pole `mcs.urlhaus.authKey`. Zrzut recent (URL-e z 30 dni) jako tekst CSV. Szablon URL to `https://urlhaus-api.abuse.ch/v2/files/exports/{authKey}/recent.csv`. Klient podstawia klucz tylko w pamięci, na czas żądania. Log, health i historia importu dostają host oraz nazwę pliku `recent.csv`, nigdy pełny URL.

Oczekujemy tekstu CSV z komentarzami `#` i kolumnami `id`, `dateadded`, `url`, `url_status`, `last_online`, `threat`, `tags`, `urlhaus_link`, `reporter`. Odpowiedź zaczynająca się od magicznych bajtów ZIP (`PK`) to błąd konfiguracji URL, bez zapisu incydentów.

Do incydentu wchodzi wiersz, który spełnia wszystko naraz:

- `url_status` równe `online`,
- host z `parse_url` jest adresem IP (nawiasy IPv6 zdejmujemy),
- `czyNaszeIp()` jest prawdą.

Host będący domeną jest liczony w statystyce „pominięte domeny” i nie generuje DNS. Wiele URL-i na jednym IP składa się w jeden incydent.

Incydent:

- `zrodlo`: `urlhaus`
- `klucz_dedupu`: `urlhaus|{ip}`
- `kategoria`: `malware`
- `tytul`: `URLhaus: {n} aktywnych URL`
- payload: co najwyżej 20 URL-i (`id`, `url`, `threat`, `tags`, `urlhaus_link`, `dateadded`) oraz łączna liczba

Gdy przy kolejnym udanym zrzucie IP nie ma już żadnego online URL-a w prefiksie, incydentu nie odświeżamy.

Błąd pobrania, brak klucza albo CSV bez wymaganych kolumn: `health_ok=false`, zero zapisów z tego przebiegu.

## GreyNoise Community

Endpoint: `GET https://api.greynoise.io/v3/community/{ip}`, nagłówek `key` z `mcs.greynoise.apiKey`.

Limit darmowego konta to około 50 wyszukiwań tygodniowo łącznie z Visualizerem GreyNoise. Bez klucza dokumentacja podaje 10 lookupów na dobę. Dlatego:

- `pobierz()` nie wykonuje żadnego zapytania do API,
- z kluczem health jest prawdziwy, komunikat mówi, że lookup jest ręczny na `/ip/{ip}`,
- bez klucza health jest fałszywy i podaje nazwę brakującej zmiennej.

Na karcie IP jest ten sam układ co isMalicious: GET czyta cache, POST z CSRF i `force=1` wydaje jedno zapytanie. Do tego:

- `GET /api/enrichment/greynoise?ip=` pod filtrem `mcsBadgeToken`,
- `php spark mcs:greynoise <ip> [--force] [--json]`.

Cache w tabeli `mcs_enrichment_greynoise`, klucz `ip`. Kolumny: `noise`, `riot`, `classification`, `name`, `link`, `last_seen`, `message`, `http_status`, `raw_json`, `zrodlo_danych`, `checked_at`, `created_at`, `updated_at`. TTL domyślnie 7 dni (`mcs.greynoise.cacheTtl` = 604800) przez `OpcjeMcs`.

Odpowiedź 200 zapisujemy. Odpowiedź 404 („adres nie występuje w danych Community”) też zapisujemy jako wynik, nie jako awarię. 400 zostaje błędem walidacji adresu i nie wchodzi do cache. 429 i 5xx są błędem, cache się nie nadpisuje, komunikat mówi o limicie. Brak rekordu w cache nie jest „czysto”.

Panel pokazuje `classification`, `noise`, `riot`, `name`, `last_seen` i link. Incydentu nie tworzymy.

## Konfiguracja

W `.env.example`, puste sekrety:

- `mcs.blocklistDe.listy` = `ssh,mail,bruteforcelogin,bots,strongips`
- `mcs.blocklistDe.httpTimeout` = `30`
- `mcs.urlhaus.authKey` = puste
- `mcs.urlhaus.exportUrl` = `https://urlhaus-api.abuse.ch/v2/files/exports/{authKey}/recent.csv`
- `mcs.urlhaus.httpTimeout` = `60`
- `mcs.greynoise.apiKey` = puste
- `mcs.greynoise.baseUrl` = `https://api.greynoise.io`
- `mcs.greynoise.cacheTtl` = `604800`
- `mcs.greynoise.httpTimeout` = `15`
- `mcs.greynoise.fixturePath` = puste, tylko testy

## Testy

- Parser list blocklist.de: IP, pusta lista, śmieciowa linia, plik z treścią bez żadnego IP, allowlista nazw.
- Składanie wielu list w jeden incydent i podbicie wagi przy `strongips`.
- Parser URLhaus: komentarz `#`, host IP, host domena pominięty, IPv6 w nawiasach, status inny niż `online` pominięty, ZIP odrzucony.
- Składanie wielu URL-i jednego IP w jeden payload, sufit 20.
- GreyNoise: fixture 200, fixture 404 zapisany jako wynik, 429 bez nadpisania cache, `pobierz()` bez wywołania klienta HTTP.
- Seeder zawiera `blocklist_de`, `urlhaus`, `greynoise`.

## Poza zakresem

Rozwiązywanie domen URLhaus, Feodo, webhooki, maile, notatki LMS, osobne dashboardy tych trzech źródeł, lokalny licznik tygodniowego limitu GreyNoise, automatyczne ukrywanie incydentu po zniknięciu z listy.

## Kolejność implementacji

1. `blocklist_de` razem z harmonogramem w `mcs_ustawienia`.
2. `urlhaus` na tym samym harmonogramie.
3. `greynoise` na wzorze isMalicious: migracja, serwis, karta IP, API, komenda spark.
