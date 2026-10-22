# blocklist.de, URLhaus i GreyNoise — plan wdrożenia

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dodać importery `blocklist_de` i `urlhaus` (incydenty w kolejce i badge) oraz enrichment `greynoise` poza badge.

**Architecture:** Oba importery mają własny harmonogram (min. 1 h) w `mcs_ustawienia`, pobierają HTTPS, filtrują `As198050Matcher::czyNaszeIp()` i zapisują przez `IncydentZapis`. URLhaus bierze tylko host będący adresem IP. GreyNoise to lookup na `/ip/{ip}` ze cache 7 dni; cron nie woła API.

**Tech Stack:** PHP 8.2, CodeIgniter 4.7, PostgreSQL, PHPUnit.

## Global Constraints

- Nowe wiersze seedera: `import_wstrzymany=true`, `ukryty_w_ui=false`.
- Błąd pobrania ustawia health na fałsz i nie zapisuje incydentów z tego przebiegu.
- Udane pobranie i zero adresów w prefiksach to sukces.
- Filtr AS wyłączony albo pusta lista prefiksów: błąd „brak prefiksów AS”.
- URLhaus: tylko `url_status=online` i host IP; domen bez DNS.
- GreyNoise: 200 i 404 wchodzą do cache; 429 i 5xx nie nadpisują cache; `pobierz()` bez zapytania HTTP.
- Sekrety nie pojawiają się w health, historii importu ani logu.
- Listy blocklist.de: `ssh`, `mail`, `bruteforcelogin`, `bots`, `strongips`.

## Tasks

- [ ] Parser, allowlista, składacz i harmonogram blocklist.de oraz testy czyste
- [ ] Przebieg blocklist.de (HTTPS, MD5, incydenty) i test na SQLite
- [ ] Parser i przebieg URLhaus oraz testy
- [ ] GreyNoise: migracja, cache, karta IP, API, spark, testy
- [ ] Seeder, `.env.example`, README, opcje, rejestr testów
