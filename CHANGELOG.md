# Changelog

Format wzorowany na [Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

### Added
- Wtyczka `blocklist_de`: listy ataków z 48 h (ssh, mail, bruteforcelogin, bots, strongips), incydent tylko dla adresów z prefiksów AS.
- Wtyczka `urlhaus`: zrzut recent abuse.ch, incydent gdy host URL-a jest adresem IP z prefiksów AS.
- Enrichment GreyNoise Community na `/ip/{ip}` (poza badge, bez zapytań z crona).
- Klucz GreyNoise i URLhaus wpisuje się w panelu: Wtyczki → przycisk Klucz. Puste pole wraca do wartości z `.env`.
- Skaner DNSBL dla prefiksów AS: `php spark mcs:dnsbl-skan`, widok `/dashboard/dnsbl`, lokalny resolver, cache wyników.
- Wtyczka `spamhaus`: lokalne dopasowanie Spamhaus DROP, DROPv6 i ASN-DROP, widok `/dashboard/spamhaus`.
- Licencja MIT, SECURITY.md, opis projektu w README.

### Changed
- Opis i metadane `composer.json` dla projektu MCS.

### Removed
- Wtyczka `abusix` (płatna subskrypcja). Tabele `mcs_abusix_*` zostają w bazie.
- Wtyczka `qrator` (API Qrator Radar płatne). Tabela `mcs_qrator_wiersze` zostaje w bazie.
- Opcja Spamhaus DQS w DNSBL (`mcs.dnsbl.spamhausDqsKey`, `mcs.dnsbl.listaSpamhaus`). Spamhaus zen/DQS jest płatny dla ISP. Darmowa wtyczka `spamhaus` (DROP) zostaje.
