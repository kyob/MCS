<?php

namespace App\Libraries;

use App\Models\ImportModel;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;

/**
 * Koperta przebiegu importu (mail/CSV/API): id wiadomości, checksum, kwarantanna.
 *
 * Dedup: jeśli istnieje import ze statusem `ok` o tym samym (zrodlo, checksum)
 * albo (zrodlo, id_wiadomosci) — nie otwieramy drugiego przebiegu.
 */
class ImportKoperta
{
    private ImportModel $importy;
    private BaseConnection $db;

    public function __construct(?ImportModel $importy = null, ?BaseConnection $db = null)
    {
        $this->db      = $db ?? Database::connect();
        $this->importy = $importy ?? model(ImportModel::class);
    }

    /**
     * Czy ten payload / wiadomość był już poprawnie zaimportowany.
     */
    public function juzZaimportowano(string $zrodlo, ?string $checksum = null, ?string $idWiadomosci = null): bool
    {
        if (($checksum === null || $checksum === '') && ($idWiadomosci === null || $idWiadomosci === '')) {
            return false;
        }

        $builder = $this->db->table('mcs_importy')
            ->where('zrodlo', $zrodlo)
            ->where('status', 'ok');

        $builder->groupStart();
        $maWarunek = false;
        if ($checksum !== null && $checksum !== '') {
            $builder->where('checksum', $checksum);
            $maWarunek = true;
        }
        if ($idWiadomosci !== null && $idWiadomosci !== '') {
            if ($maWarunek) {
                $builder->orWhere('id_wiadomosci', $idWiadomosci);
            } else {
                $builder->where('id_wiadomosci', $idWiadomosci);
            }
        }
        $builder->groupEnd();

        return $builder->countAllResults() > 0;
    }

    /**
     * Otwiera przebieg `w_trakcie`. Zwraca id importu albo null gdy duplikat ok.
     *
     * @param array<string, mixed> $meta
     */
    public function rozpocznij(
        string $zrodlo,
        ?string $checksum = null,
        ?string $idWiadomosci = null,
        array $meta = [],
    ): ?int {
        if ($this->juzZaimportowano($zrodlo, $checksum, $idWiadomosci)) {
            return null;
        }

        $teraz = $this->terazUtc();
        $dane  = [
            'zrodlo'         => $zrodlo,
            'id_wiadomosci'  => $idWiadomosci,
            'rozpoczeto_at'  => $teraz,
            'zakonczono_at'  => null,
            'status'         => 'w_trakcie',
            'checksum'       => $checksum,
            'meta_json'      => $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE),
            'blad_text'      => null,
        ];

        $id = $this->importy->insert($dane, true);
        if ($id === false) {
            throw new RuntimeException('Nie udało się otworzyć przebiegu importu dla ' . $zrodlo);
        }

        return (int) $id;
    }

    /**
     * @param array<string, mixed> $metaDodatek
     */
    public function zakonczOk(int $importId, array $metaDodatek = []): void
    {
        $this->zakoncz($importId, 'ok', null, $metaDodatek);
    }

    /**
     * @param array<string, mixed> $metaDodatek
     */
    public function zakonczBlad(int $importId, string $blad, array $metaDodatek = []): void
    {
        $this->zakoncz($importId, 'blad', $blad, $metaDodatek);
    }

    /**
     * Normalizacja się nie udała — rekord zostaje, bez śmieci w indeksie.
     *
     * @param array<string, mixed> $metaDodatek
     */
    public function zakonczKwarantanna(int $importId, string $powod, array $metaDodatek = []): void
    {
        $this->zakoncz($importId, 'kwarantanna', $powod, $metaDodatek);
    }

    /**
     * @param array<string, mixed> $metaDodatek
     */
    private function zakoncz(int $importId, string $status, ?string $blad, array $metaDodatek): void
    {
        $wiersz = $this->importy->find($importId);
        if (! is_array($wiersz)) {
            throw new RuntimeException('Brak przebiegu importu id=' . $importId);
        }

        $meta = [];
        if (isset($wiersz['meta_json']) && is_string($wiersz['meta_json']) && $wiersz['meta_json'] !== '') {
            $decoded = json_decode($wiersz['meta_json'], true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }
        $meta = array_merge($meta, $metaDodatek);

        $this->importy->update($importId, [
            'status'        => $status,
            'zakonczono_at' => $this->terazUtc(),
            'blad_text'     => $blad,
            'meta_json'     => $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function terazUtc(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:sP');
    }
}
