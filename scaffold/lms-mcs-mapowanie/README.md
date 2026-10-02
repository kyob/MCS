# Scaffold: LMSMcsMapowaniePlugin

Cienkie API JSON na hoście LMS: **IP → komputer (node) + abonent (customer)**.
MCS trzyma wynik w cache (`mcs_enrichment_lms`) — bez kopiowania pełnej bazy klientów.

## Hosty LMS (ważne)

| Rola | Host | Kiedy |
|------|------|--------|
| **DEV/TEST** mapowanie | `192.0.2.60` | domyślny target testów MCS↔LMS |
| **PROD** LMS / badge | `192.0.2.50` | **tylko** gdy użytkownik wyraźnie powie |

Nie mylić z pluginem badge (`scaffold/lms-mcs-badge/`) — badge na produkcji może nadal wskazywać `.50`. Mapowanie testujemy **wyłącznie** na `.60`, dopóki użytkownik nie awansuje.

## Endpoint

Po instalacji (Apache docroot LMS+), na hoście TEST:

`http://192.0.2.60/plugins/LMSMcsMapowaniePlugin/api.php?action=lookup&ip=1.2.3.4`

Auth: **wyłącznie** nagłówek `Authorization: Bearer <map_token>` (bez `?token=`, bez fallbacku `badge_token`).

Akcje: `health` | `lookup` (tylko **IPv4**) | `sync&limit=&offset=` (paginacja, domyślnie limit=2000, max=5000).

## Instalacja na LMS (TEST = .60)

1. Na hoście **TEST** `192.0.2.60` skopiuj ten katalog do `plugins/LMSMcsMapowaniePlugin`
   (lub uruchom installer **na tym hoście**):

   ```bash
   ./bin/install-to-lms.sh --lms-root /var/www/html/lmsplus --yes
   ```

   Operator **musi** podać `--lms-root` (albo `LMS_ROOT`) wskazujący drzewo LMS+ na hoście, na którym uruchamia skrypt.
   **Nie instalować na PROD `.50`**, dopóki użytkownik tego nie zleci.

2. W `/etc/lms/lms.ini` na `.60` ustaw `[mcs] map_token = …` (ten sam co `mcs.lms.mapToken` w MCS).
   Osobny sekret — **nie** ten sam co `badge_token`.
3. Apache: vhost musi serwować `plugins/*.php`. Na TEST `.60` jest `AllowOverride None`, więc
   `plugins/.htaccess` (`Require all denied`) jest **ignorowany**. Przy `AllowOverride All/FileInfo`
   ten `.htaccess` zablokuje `api.php` (403) — wtedy potrzebna wyjątek dla tego katalogu.
4. W MCS `.env` (test mapowania):
   - `mcs.lms.baseUrl = 'http://192.0.2.60'`
   - `mcs.lms.mapToken = …` (ten sam co `map_token` na LMS `.60`; nie wymyślaj)
   - **wyczyść** `mcs.lms.fixturePath` (inaczej MCS zostaje w trybie fixture i nie woła API)
   - opcjonalnie `mcs.lms.nodeUrlTemplate = 'http://192.0.2.60/?m=nodeinfo&id={id}'`
   - przy sync: `mcs.lms.httpTimeout` ≥ 60 zalecane
5. `php spark mcs:seed-pluginy` + `php spark mcs:pobierz lms_mapowanie` (sync)
   albo „Sprawdź w LMS” na `/ip/{ip}`.

Wpis w `phpui.plugins` **nie jest wymagany** — `api.php` działa standalone.

## Smoke (bez wypisywania tokenu)

```bash
# bez auth → 401 JSON
curl -sS -o /dev/null -w '%{http_code}\n' \
  'http://192.0.2.60/plugins/LMSMcsMapowaniePlugin/api.php?action=health'

# z Bearer → 200
curl -sS -o /dev/null -w '%{http_code}\n' \
  -H "Authorization: Bearer \$TOKEN" \
  'http://192.0.2.60/plugins/LMSMcsMapowaniePlugin/api.php?action=health'
```

Lokalnie (bez LMS): `php bin/selfcheck.php`

## Uwagi

- Lookup: `vnodes` (jak CertN6/MojeCert) po `ipaddr` **lub** `ipaddr_pub`; `DISTINCT ON (id)` przy wielu MAC.
- Tylko **IPv4** (LMS `ipaddr` / `ipaddr_pub` są bigint IPv4).
- MCS przy wielu trafieniach wybiera: trafienie po `ipaddr`, wyższy `access`, niższe `node_id`.
- Fixture: tylko dokładne dopasowanie IP — **bez** remapowania pierwszego wpisu na obce adresy (fałszywe deep-linki).
- `sync` paginowany (`limit`/`offset`/`has_more`) — klient MCS zbiera strony. Używaj z sieci zaufanej.
- Alternatywa bez API: bezpośredni `mcs.lms.db*` (PostgreSQL) — tylko gdy firewall pozwala.
