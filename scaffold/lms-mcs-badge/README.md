# Scaffold: LMSMcsBadgePlugin (Faza 5)

**Uwaga:** to jest **szkic w repo MCS**, nie instalacja produkcyjna.

- **Nie kopiować** automatycznie do `/var/www/html/lmsplus` bez akceptacji.
- Na CT MCS drzewo LMS zwykle **niedostępne** — instalację robi operator na hoście LMS (najpierw staging).
- Plugin jest **cienki**: tylko HTTP do MCS `/api/badge` (+ opcjonalnie `?ips=`) + deep link. Bez lokalnego indeksu, bez AbuseIPDB, bez Shadowserver/Play/…

## Status instalacji (2026-09-12)

| Środowisko | Stan |
|------------|------|
| MCS `GET /api/badge?ip=` | **OK** (200/401) |
| LMS drzewo | `kyob@192.0.2.50:/var/www/html/lmsplus` |
| Scaffold na LMS | `~/mcs-badge-staging/lms-mcs-badge/` (LMS+ handlers + `templates/node/nodeinfobox.html`) |
| `[mcs]` w `/etc/lms/lms.ini` | **OK** |
| `phpui.plugins` → `LMSMcsBadgePlugin` | **OK** |
| Runtime | autoload bridge ze stagingu (do `composer dump-autoload` jako root) |
| Smoke | node `9910` / `176.97.24.50` → `liczba>0` |
| Cutover fetch | SNDS + N6 soft-off; MojeCert timer = leftover root |

### Opcjonalnie — pełna kopia do `plugins/` (root)

```bash
bash /home/kyob/mcs-badge-staging/update-mcs-badge-fix.sh
```

## Config LMS (bez sekretów w gicie)

Wzór: [`config/lms.ini.example`](config/lms.ini.example)

```ini
[mcs]
base_url = https://mcs.example/
badge_token = ZMIEN_TEN_SAM_CO_mcs.badgeToken
cache_ttl = 120
timeout = 5
```

- `base_url` = `app.baseURL` MCS (osiągalny z hosta LMS).
- `badge_token` = **ten sam** ciąg co `mcs.badgeToken` w `.env` MCS.
- Nigdy nie commituj prawdziwego tokenu.

## Pliki

| Plik | Rola |
|------|------|
| `LMSMcsBadgePlugin.php` | Plugin LMS+ — `registerHandlers()` |
| `handlers/McsBadgeHandler.php` | `smarty_initialized` + `nodeinfo_before_display` |
| `lib/McsBadgeClient.php` | HTTP Bearer → `/api/badge` (single + batch `ips=`) |
| `lib/McsBadgeCache.php` | Cache plikowy TTL (domyślnie 120 s) |
| `templates/node/nodeinfobox.html` | Blok Smarty `nodeinfobox-leftcolumn` (extendsall) |
| `templates/nodeinfo-badge.html` | Zapasowy markup mini-tpl |
| `config/lms.ini.example` | Wzór sekcji `[mcs]` |
| `bin/install-to-lms.sh` | Kopia do `plugins/LMSMcsBadgePlugin` (+ przypomnienie `composer dump-autoload`) |
| `bin/selfcheck.php` | Smoke lokalny (bez LMS) |

## Kroki instalacji (operator — host LMS)

Wykonuj na **staging**, dopiero potem prod.

1. **Sieć:** z hosta LMS sprawdź, że MCS odpowiada (bez wypisywania tokenu):
   ```bash
   curl -sS -o /dev/null -w '%{http_code}\n' -H "Authorization: Bearer \$TOKEN" \
     "https://MCS_BASE/api/badge?ip=198.51.100.10"
   ```
   Oczekiwane: `200`. Bez tokenu / ze złym: `401`.

2. **Config LMS:** dopisz sekcję `[mcs]` do `lms.ini` (lub odpowiednika) wg wzoru powyżej.  
   Token skopiuj z MCS `.env` (`mcs.badgeToken`) — **ręcznie**, nie przez git.

3. **Kopiuj plugin** (z maszyny, na której jest checkout MCS, albo spakuj katalog):
   ```bash
   # dry-run (domyślnie)
   ./bin/install-to-lms.sh --lms-root /var/www/html/lmsplus

   # instalacja
   ./bin/install-to-lms.sh --lms-root /var/www/html/lmsplus --yes

   # ponowna instalacja nadpisująca (świadomie)
   ./bin/install-to-lms.sh --lms-root /var/www/html/lmsplus --yes --force
   ```
   Cel: `{LMS}/plugins/LMSMcsBadgePlugin/`.

4. **Włącz plugin** w panelu LMS+ (jak inne `LMS*Plugin`). W razie potrzeby dostosuj nazwę hooka / assign Smarty do Waszej wersji LMS+ (kod jest luźno sprzężony).

5. **Smoke nodeinfo:** otwórz kartę hosta z IP, które ma incydenty w MCS → rząd pigułek: waga (kolor), liczba zgłoszeń, źródła (N6/…), 1–2 kategorie + link „Szczegóły w MCS”.  
   IP bez incydentów → pigułka „Brak zgłoszeń”. Zły token / MCS down → „API niedostępne”.

6. **Klik** → deep link `/ip/{ip}` w MCS (po zalogowaniu szczegóły).

7. **Cutover starych boxów** źródeł (SNDS, Play, …) — **dopiero** po cutover danego źródła do MCS (osobne plany `docs/11-*.md`). Ten plugin ich nie wyłącza.

## API MCS (kontrakt)

Pojedyncze IP (wymagane, działa):

```http
GET /api/badge?ip=198.51.100.10
Authorization: Bearer <mcs.badgeToken>
```

Batch (MCS już obsługuje; klient: `badgeDlaIpLista`):

```http
GET /api/badge?ips=198.51.100.10,198.51.100.11
Authorization: Bearer <mcs.badgeToken>
```

Dla >20 IP klient może użyć `POST /api/badge` z JSON `{"ips":[…]}` (limit 100).  
Odpowiedź batch: `{ "okno_dni", "liczba_ip", "wyniki": [ {ip, liczba, …}, … ] }`.

## Co NIE robić

- Nie fetchować Shadowserver / Play / SNDS / N6 z pluginu badge.
- Nie trzymać lokalnej tabeli „incydentów MCS”.
- Nie wkładać klucza AbuseIPDB do badge.
- Nie commitować `badge_token` / `.env`.

## Weryfikacja scaffoldu na CT MCS (bez LMS)

```bash
cd /var/www/mcs/scaffold/lms-mcs-badge
php bin/selfcheck.php
./bin/install-to-lms.sh   # powinien zakończyć się błędem „brak katalogu LMS” — OK
```
