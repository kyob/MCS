# MCS — Moja Czysta Sieć

Osobna aplikacja (nie część LMS+), CodeIgniter 4 + PostgreSQL na LXC.

**Faza 1a (szkielet):** domknięta 2026-09-11 — odbiór: [docs/08-kryteria-odbioru-1a.md](docs/08-kryteria-odbioru-1a.md).  
**Faza 1b (importery):** częściowo — Shadowserver live OK; Play parser OK / IMAP pusty (FreeScout); SNDS czeka — [docs/09-plan-faza-1b-importery.md](docs/09-plan-faza-1b-importery.md), szkic [docs/10-kryteria-odbioru-1b.md](docs/10-kryteria-odbioru-1b.md).  
Środowisko: CT `mcs` (192.0.2.110), katalog `/var/www/mcs`.

```bash
composer install
cp .env.example .env   # uzupełnić hasło DB i mcs.badgeToken (tylko lokalnie / na CT)
php spark migrate
php spark mcs:seed-pluginy
php spark mcs:create-user admin@example.local 'hasło' admin
php spark list
php spark mcs:hello
php spark mcs:pobierz all   # ingest; cron: docs/09-cron-pobierz.md
```

Docroot: `public/` (nginx → PHP-FPM). Sekrety wyłącznie w `.env` — nie commitować.

LMS+ później dostanie mały plugin (badge → MCS).  
**Planować można; realizacja pluginu dopiero po MCS (po 1b / stabilnym API).**

## Dokumenty

| Plik | Co to jest |
|------|------------|
| [docs/01-ustalenia.md](docs/01-ustalenia.md) | Krok 1 — co już ustaliliśmy |
| [docs/02-wyglad-raportu.md](docs/02-wyglad-raportu.md) | Krok 2 — zgłoszenie, indeks, badge, moduły |
| [docs/03-przeplyw-danych.md](docs/03-przeplyw-danych.md) | Krok 3 — jak dane płyną |
| [docs/04-czesci-systemu.md](docs/04-czesci-systemu.md) | Krok 4 — części MCS, pluginy, fazy (+ status 1a/1b) |
| [docs/05-stack.md](docs/05-stack.md) | Stack: PHP, CodeIgniter 4, PostgreSQL, osobny LXC/VM |
| [docs/06-poprawki-po-krytyce.md](docs/06-poprawki-po-krytyce.md) | Decyzje po krytyce planu |
| [docs/07-plan-faza-1a-szkielet.md](docs/07-plan-faza-1a-szkielet.md) | Plan Fazy 1a + LXC / sprzęt |
| [docs/07-srodowisko-lxc.md](docs/07-srodowisko-lxc.md) | Fakty środowiska LXC |
| [docs/08-kryteria-odbioru-1a.md](docs/08-kryteria-odbioru-1a.md) | Odbiór Fazy 1a — checklista i wyniki |
| [docs/09-plan-faza-1b-importery.md](docs/09-plan-faza-1b-importery.md) | Plan Fazy 1b + status źródeł |
| [docs/09-imap-konfiguracja-env.md](docs/09-imap-konfiguracja-env.md) | Klucze `.env` IMAP (bez haseł) |
| [docs/09-cron-pobierz.md](docs/09-cron-pobierz.md) | Cron / systemd dla `mcs:pobierz` |
| [docs/10-kryteria-odbioru-1b.md](docs/10-kryteria-odbioru-1b.md) | Szkic odbioru Fazy 1b |
| [docs/07-zlecenie-agent-lxc.md](docs/07-zlecenie-agent-lxc.md) | Polecenie dla agenta: Task 1 LXC |
| [docs/07-zlecenie-agent-ci4.md](docs/07-zlecenie-agent-ci4.md) | Polecenie dla agenta: Task 2 CI4 |
| [docs/07-zlecenie-agent-migracje.md](docs/07-zlecenie-agent-migracje.md) | Polecenie dla agenta: Task 3 migracje |
| [docs/07-zlecenie-agent-przenies-repo.md](docs/07-zlecenie-agent-przenies-repo.md) | Polecenie: przenieś repo na serwer MCS |

## Lokalizacja

- Serwer MCS (źródło prawdy): `/var/www/mcs` na `192.0.2.110`
- LMS+ (osobno, poza tym hostem): nie instalować MCS na hoście LMS
