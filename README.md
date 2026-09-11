# MCS — Moja Czysta Sieć

Osobna aplikacja (nie część LMS+).

CodeIgniter 4 zbootstrapowany (Task 2, 2026-09-11), wdrozony na CT `mcs`
(192.0.2.110). Uruchomienie lokalne / na CT:

```bash
composer install
cp .env.example .env   # uzupelnic haslo DB (na CT: z /root/mcs-bootstrap-secrets.txt)
php spark list
```

Docroot: `public/`. Migracje domenowe MCS jeszcze nie istnieja (Task 3).

LMS+ później dostanie mały plugin (badge → MCS).  
**Planować można; realizacja pluginu dopiero po uruchomieniu MCS.**

## Dokumenty

| Plik | Co to jest |
|------|------------|
| [docs/01-ustalenia.md](docs/01-ustalenia.md) | Krok 1 — co już ustaliliśmy |
| [docs/02-wyglad-raportu.md](docs/02-wyglad-raportu.md) | Krok 2 — zgłoszenie, indeks, badge, moduły |
| [docs/03-przeplyw-danych.md](docs/03-przeplyw-danych.md) | Krok 3 — jak dane płyną |
| [docs/04-czesci-systemu.md](docs/04-czesci-systemu.md) | Krok 4 — części MCS, pluginy, fazy |
| [docs/05-stack.md](docs/05-stack.md) | Stack: PHP, CodeIgniter 4, PostgreSQL, osobny LXC/VM |
| [docs/06-poprawki-po-krytyce.md](docs/06-poprawki-po-krytyce.md) | Decyzje po krytyce planu |
| [docs/07-plan-faza-1a-szkielet.md](docs/07-plan-faza-1a-szkielet.md) | Plan Fazy 1a + LXC / sprzęt |
| [docs/07-srodowisko-lxc.md](docs/07-srodowisko-lxc.md) | Fakty środowiska LXC (szablon) |
| [docs/07-zlecenie-agent-lxc.md](docs/07-zlecenie-agent-lxc.md) | Polecenie dla agenta: Task 1 LXC |
| [docs/07-zlecenie-agent-ci4.md](docs/07-zlecenie-agent-ci4.md) | Polecenie dla agenta: Task 2 CI4 |
| [docs/07-zlecenie-agent-migracje.md](docs/07-zlecenie-agent-migracje.md) | Polecenie dla agenta: Task 3 migracje |

## Lokalizacja

- Ten projekt: `/home/kyob/mcs`
- LMS+ (osobno): `/var/www/html/lmsplus`
