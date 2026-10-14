# Changelog

Format wzorowany na [Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

### Added
- Skaner DNSBL dla prefiksów AS: `php spark mcs:dnsbl-skan`, widok `/dashboard/dnsbl`, lokalny resolver, cache wyników.
- Wtyczka `spamhaus`: lokalne dopasowanie Spamhaus DROP, DROPv6 i ASN-DROP, widok `/dashboard/spamhaus`.
- Wtyczka `abusix` (import wstrzymany do czasu subskrypcji).
- Licencja MIT, SECURITY.md, opis projektu w README.

### Changed
- Opis i metadane `composer.json` dla projektu MCS.
