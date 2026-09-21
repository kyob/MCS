<?php

namespace App\Libraries;

use App\Models\UserModel;

/**
 * Tworzenie, edycja i usuwanie kont MCS. Ostatniego aktywnego superadmina
 * nie wolno wyłączyć, zdegradować ani usunąć; własnego konta też nie.
 */
class UzytkownicySerwis
{
    private UserModel $model;

    public function __construct(?UserModel $model = null)
    {
        $this->model = $model ?? model(UserModel::class);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function lista(): array
    {
        $wiersze = $this->model->orderBy('email', 'ASC')->findAll();
        $out     = [];
        foreach ($wiersze as $wiersz) {
            if (! is_array($wiersz)) {
                continue;
            }
            unset($wiersz['password_hash']);
            $out[] = $wiersz;
        }

        return $out;
    }

    /**
     * @return array{ok: bool, blad: ?string, id?: int|string}
     */
    public function utworz(string $email, string $haslo, string $rola): array
    {
        $email = strtolower(trim($email));
        $rola  = strtolower(trim($rola));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->blad('Nieprawidłowy e-mail.');
        }
        if (! Rola::jestDozwolona($rola)) {
            return $this->blad('Rola musi być: superadmin, admin albo operator.');
        }
        $hasloBlad = $this->walidujHaslo($haslo);
        if ($hasloBlad !== null) {
            return $this->blad($hasloBlad);
        }
        if ($this->model->where('email', $email)->first() !== null) {
            return $this->blad('Użytkownik o tym e-mailu już istnieje.');
        }

        $id = $this->model->insert([
            'email'         => $email,
            'password_hash' => password_hash($haslo, PASSWORD_DEFAULT),
            'rola'          => $rola,
            'aktywny'       => true,
        ], true);

        if ($id === false) {
            return $this->blad('Nie udało się utworzyć użytkownika.');
        }

        return ['ok' => true, 'blad' => null, 'id' => $id];
    }

    /**
     * @return array{ok: bool, blad: ?string}
     */
    public function zmienRole(int $id, string $rola): array
    {
        $rola = strtolower(trim($rola));
        if (! Rola::jestDozwolona($rola)) {
            return $this->blad('Rola musi być: superadmin, admin albo operator.');
        }

        $user = $this->model->find($id);
        if (! is_array($user)) {
            return $this->blad('Nie znaleziono użytkownika.');
        }

        if (
            $rola !== Rola::SUPERADMIN
            && $this->jestOstatnimAktywnymSuperadminem($user)
        ) {
            return $this->blad('Nie można zdegradować ostatniego superadmina.');
        }

        if ($this->model->update($id, ['rola' => $rola]) === false) {
            return $this->blad('Nie udało się zmienić roli.');
        }

        return ['ok' => true, 'blad' => null];
    }

    /**
     * @return array{ok: bool, blad: ?string}
     */
    public function ustawHaslo(int $id, string $haslo): array
    {
        $hasloBlad = $this->walidujHaslo($haslo);
        if ($hasloBlad !== null) {
            return $this->blad($hasloBlad);
        }

        $user = $this->model->find($id);
        if (! is_array($user)) {
            return $this->blad('Nie znaleziono użytkownika.');
        }

        if ($this->model->update($id, ['password_hash' => password_hash($haslo, PASSWORD_DEFAULT)]) === false) {
            return $this->blad('Nie udało się ustawić hasła.');
        }

        return ['ok' => true, 'blad' => null];
    }

    /**
     * @return array{ok: bool, blad: ?string}
     */
    public function ustawAktywny(int $id, bool $aktywny): array
    {
        $user = $this->model->find($id);
        if (! is_array($user)) {
            return $this->blad('Nie znaleziono użytkownika.');
        }

        if (! $aktywny && $this->jestOstatnimAktywnymSuperadminem($user)) {
            return $this->blad('Nie można wyłączyć ostatniego superadmina.');
        }

        if ($this->model->update($id, ['aktywny' => $aktywny]) === false) {
            return $this->blad('Nie udało się zmienić statusu konta.');
        }

        return ['ok' => true, 'blad' => null];
    }

    /**
     * @return array{ok: bool, blad: ?string}
     */
    public function usun(int $id, int $idWykonawcy): array
    {
        if ($id === $idWykonawcy) {
            return $this->blad('Nie można usunąć własnego konta.');
        }

        $user = $this->model->find($id);
        if (! is_array($user)) {
            return $this->blad('Nie znaleziono użytkownika.');
        }

        if ($this->jestOstatnimAktywnymSuperadminem($user)) {
            return $this->blad('Nie można usunąć ostatniego superadmina.');
        }

        if ($this->model->delete($id) === false) {
            return $this->blad('Nie udało się usunąć konta.');
        }

        return ['ok' => true, 'blad' => null];
    }

    /**
     * @param array<string, mixed> $user
     */
    public function jestOstatnimAktywnymSuperadminem(array $user): bool
    {
        if (($user['rola'] ?? '') !== Rola::SUPERADMIN) {
            return false;
        }
        if (! $this->jestAktywny($user)) {
            return false;
        }

        return $this->liczbaAktywnychSuperadminow() <= 1;
    }

    public function liczbaAktywnychSuperadminow(): int
    {
        return $this->model
            ->where('rola', Rola::SUPERADMIN)
            ->where('aktywny', true)
            ->countAllResults();
    }

    /**
     * @param array<string, mixed> $user
     */
    private function jestAktywny(array $user): bool
    {
        $v = $user['aktywny'] ?? false;

        return $v === true || $v === 1 || $v === '1' || $v === 't' || $v === 'true';
    }

    private function walidujHaslo(string $haslo): ?string
    {
        if (strlen($haslo) < 8) {
            return 'Hasło musi mieć co najmniej 8 znaków.';
        }

        return null;
    }

    /**
     * @return array{ok: bool, blad: string}
     */
    private function blad(string $komunikat): array
    {
        return ['ok' => false, 'blad' => $komunikat];
    }
}
