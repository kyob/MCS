# Polityka bezpieczeństwa

## Zgłaszanie luk

Nie otwieraj publicznego issue dla luki bezpieczeństwa.
Użyj prywatnego zgłoszenia GitHub: zakładka **Security** → **Report a vulnerability** w tym repozytorium.

W zgłoszeniu podaj:
- wersję lub commit,
- kroki odtworzenia,
- skutek (np. odczyt danych, obejście logowania).

## Obsługiwane wersje

Obsługiwana jest tylko gałąź `main`.

## Sekrety

- Klucze API, hasła i tokeny trzymaj w `.env`. Plik jest w `.gitignore`.
- Jeśli sekret trafi do historii git, zmień go natychmiast. Usunięcie pliku z repo nie wystarcza.
