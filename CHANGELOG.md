# Changelog

Format wzorowany na [Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

### Added
- Skaner DNSBL dla prefiksów AS: `php spark mcs:dnsbl-skan`, widok `/dashboard/dnsbl`, lokalny resolver, cache wyników.
- Wtyczka `spamhaus`: lokalne dopasowanie Spamhaus DROP, DROPv6 i ASN-DROP, widok `/dashboard/spamhaus`.
- Licencja MIT, SECURITY.md, opis projektu w README.

### Changed
- Opis i metadane `composer.json` dla projektu MCS.

### Removed
- Wtyczka `abusix` (płatna subskrypcja). Tabele `mcs_abusix_*` zostają w bazie.
- Wtyczka `qrator` (API Qrator Radar płatne). Tabela `mcs_qrator_wiersze` zostaje w bazie.
- Opcja Spamhaus DQS w DNSBL (`mcs.dnsbl.spamhausDqsKey`, `mcs.dnsbl.listaSpamhaus`). Spamhaus zen/DQS jest płatny dla ISP. Darmowa wtyczka `spamhaus` (DROP) zostaje.
