# Scaffold: LMSMcsBadgePlugin (Faza 5)

**Uwaga:** to jest **szkic w repo MCS**, nie instalacja produkcyjna.

- **Nie kopiować** automatycznie do `/var/www/html/lmsplus` bez akceptacji.
- Na CT MCS drzewo LMS zwykle **niedostępne** — instalację robi operator na hoście LMS (najpierw staging).
- Plugin jest **cienki**: tylko HTTP do MCS `/api/badge` (+ opcjonalnie `?ips=`) + deep link. Bez lokalnego indeksu, bez AbuseIPDB, bez Shadowserver/Play/…

## Status instalacji (2026-09-12)

| Środowisko | Stan |
|------------|------|
| MCS `GET /api/badge?ip=` | działa (token w `.env` MCS); smoke z CT i z LMS hosta → 200/401 |
| LMS drzewo | na `kyob@192.0.2.50:/var/www/html/lmsplus` (nie na CT MCS) |
| Scaffold na LMS | skopiowany do `~/mcs-badge-staging/lms-mcs-badge/` (selfcheck OK) |
| Plugin w `plugins/LMSMcsBadgePlugin` | **nie zainstalowany** — brak passwordless sudo / zapis do `plugins/` i `/etc/lms/lms.ini` |
| `[mcs]` w `/etc/lms/lms.ini` | **brak** |
| Cutover starych źródeł LMS | **wstrzymany** do smoke badge |

### Bloker — komendy operatora (sudo na LMS)

```bash
sudo ~/mcs-badge-staging/lms-mcs-badge/bin/install-to-lms.sh \
  --lms-root /var/www/html/lmsplus --yes
sudo edit /etc/lms/lms.ini   # dopisz [mcs] wg wzoru poniżej; token = mcs.badgeToken z MCS
# włącz plugin w panelu LMS+ → smoke nodeinfo
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
| `LMSMcsBadgePlugin.php` | Plugin LMS+ — hook `nodeinfo_before_display` |
| `lib/McsBadgeClient.php` | HTTP Bearer → `/api/badge` (single + batch `ips=`) |
| `lib/McsBadgeCache.php` | Cache plikowy TTL (domyślnie 120 s) |
| `templates/nodeinfo-badge.html` | Markup badge / stan „API niedostępne” |
| `config/lms.ini.example` | Wzór sekcji `[mcs]` |
| `bin/install-to-lms.sh` | Bezpieczna kopia do `plugins/LMSMcsBadgePlugin` |
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

5. **Smoke nodeinfo:** otwórz kartę hosta z IP, które ma incydenty w MCS (okno 7 dni) → badge `MCS: N (waga)`.  
   IP bez incydentów → `MCS: 0`. Zły token / MCS down → `MCS: —` (bez stack trace).

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
