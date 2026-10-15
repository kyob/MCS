# Wtyczka `spamhaus_sbl` — powiadomienia e-mail Spamhaus SBL

Importuje maile Spamhaus o wpisach SBL ze skrzynki IMAP.
Trafienie w prefiks AS (`mcs_ustawienia.as198050_cidry`) = incydent `kategoria=spam`, `podmiot_typ=ip`.
Po `mcs:seed-pluginy` źródło jest **wstrzymane** (`import_wstrzymany=true`).

## Włączenie

1. Ustaw w `.env` klucze `mcs.spamhaus_sbl.imapHost`, `imapPort` (np. 993), `imapUser`, `imapPass`.
   Opcjonalnie: `imapMailbox` (domyślnie INBOX), `imapEncryption` (ssl|tls|none), `authservId`.
2. Odblokuj import w `/admin/pluginy` (kolumna „Import”).
3. Sprawdź: `php spark mcs:pobierz spamhaus_sbl` i kartę „Spamhaus SBL (e-mail)” w `/admin/pluginy`.

Skrzynka: adres abuse-c z RIPE (tam Spamhaus wysyła powiadomienia). Najlepiej osobny folder
(reguła Sieve kopiuje maile od `*@spamhaus.org`) albo osobne konto. Wtyczka oznacza jako
przeczytane tylko maile przyjęte jako incydent.

## Filtr wiadomości (wszystkie warunki)

1. **Nadawca.** Adres z nagłówka `From` po parsowaniu (nazwa wyświetlana i komentarze są usuwane).
   Domena musi być dokładnie `spamhaus.org` albo `*.spamhaus.org`. Dozwolone tylko znaki ASCII
   `[a-z0-9.-]`. Odrzucane: `spamhaus.org.evil.com`, `notspamhaus.org`, homografy (Unicode),
   kilka adresów w `From`, kilka nagłówków `From`.
2. **Temat** zawiera `SBL` (bez rozróżniania wielkości liter).
3. **Authentication-Results** (RFC 8601), tylko nagłówek dodany przez **nasz** MTA:
   - `authservId` ustawiony: liczą się tylko nagłówki z tym authserv-id. Inne są ignorowane.
   - `authservId` pusty: liczy się tylko najwyższy nagłówek (dodany jako ostatni).
   - Wymagane: `dkim=pass` z `header.d` (lub `header.i`) w spamhaus.org **albo** `spf=pass`
     z `smtp.mailfrom` w spamhaus.org. Inaczej wiadomość jest odrzucona (`auth_fail`).
   - **Brak nagłówka**: wiadomość jest przyjęta, ale incydent ma dopisek
     „nieuwierzytelniony nadawca” i niższą wagę (`medium` zamiast `high`). Incydent nigdy nie jest ukryty.

Maile bez domeny spamhaus.org i bez „SBL” w temacie to zwykła poczta: wtyczka ich nie zapisuje i nie oznacza.

## Parser i bezpieczeństwo treści

- Wyciąga numer `SBL123456`, adresy IPv4 i CIDR IPv4 (CIDR → adres sieci, jak `spoofer`),
  opcjonalnie linię `Reason:` i link `https://*.spamhaus.org/...` (tylko jako tekst).
- Brak IP z AS → wiadomość odrzucona (`obcy_ip`), licznik pominięć.
- Treść jest niezaufana: brak zapytań sieciowych, linki nie są otwierane, HTML jest usuwany,
  pola mają limity (temat 255, powód 300, link 255), UI escapuje (`esc()`).
- Link `https://check.spamhaus.org/sbl/query/SBL<ID>` w karcie jest tylko dla człowieka.

## Dane

- `mcs_spamhaus_sbl_wiadomosci` — decyzja per wiadomość. Unikat `klucz` = Message-ID albo sha256.
  Statusy: `przyjeta`, `nadawca`, `temat`, `auth_fail`, `brak_numeru`, `brak_ip`, `obcy_ip`.
- `mcs_spamhaus_sbl_wiersze` — wiersz źródła dla każdego incydentu (`id_w_zrodle`).
- Klucz dedupu incydentu: `sbl|{numer_SBL}|{ip}`. Ten sam mail drugi raz = brak duplikatu.

## Ryzyka

- Format maila Spamhaus jest nieznany z dokumentacji (UNVERIFIED). Parser szuka wzorców.
  Po pierwszym prawdziwym mailu sprawdź kartę i statusy w `mcs_spamhaus_sbl_wiadomosci`.
- Dokładny adres nadawcy Spamhaus jest nieznany (UNVERIFIED). Stąd filtr po domenie.
- Bez `authservId` i bez nagłówka Authentication-Results od MTA podróbka z poprawnym `From`
  przejdzie jako „nieuwierzytelniony nadawca” (waga medium, widoczny dopisek).

## Rollback

1. Wstrzymaj import w `/admin/pluginy`.
2. `git revert <sha>` i `php spark migrate:rollback` (usuwa tabele `mcs_spamhaus_sbl_*`)
   albo zostaw tabele. Wiersz `spamhaus_sbl` w `mcs_pluginy` usuń ręcznie, jeśli trzeba.
