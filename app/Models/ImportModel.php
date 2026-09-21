<?php

namespace App\Models;

use CodeIgniter\Model;

class ImportModel extends Model
{
    protected $table            = 'mcs_importy';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $allowedFields    = [
        'zrodlo',
        'id_wiadomosci',
        'rozpoczeto_at',
        'zakonczono_at',
        'status',
        'checksum',
        'meta_json',
        'blad_text',
    ];
    protected $useTimestamps = false;

    public const STATUSY = ['ok', 'blad', 'kwarantanna', 'w_trakcie'];

    /**
     * Ostatnie przebiegi importu (najnowsze pierwsze).
     *
     * @return list<array<string, mixed>>
     */
    public function ostatnie(int $limit = 30, ?string $zrodlo = null): array
    {
        $limit = max(1, min(200, $limit));
        $b     = $this->builder();

        if ($zrodlo !== null && $zrodlo !== '') {
            $b->where('zrodlo', $zrodlo);
        }

        return $b->orderBy('id', 'DESC')->limit($limit)->get()->getResultArray();
    }

    /**
     * Distinct źródła występujące w historii importów (do filtra UI).
     *
     * @return list<string>
     */
    public function listaZrodel(): array
    {
        $wiersze = $this->builder()
            ->select('zrodlo')
            ->distinct()
            ->orderBy('zrodlo', 'ASC')
            ->get()
            ->getResultArray();

        $lista = [];
        foreach ($wiersze as $w) {
            $z = (string) ($w['zrodlo'] ?? '');
            if ($z !== '') {
                $lista[] = $z;
            }
        }

        return $lista;
    }

    /**
     * Historia importów z filtrami i paginacją CI4 (najnowsze pierwsze).
     *
     * @return list<array<string, mixed>>
     */
    public function szukaj(
        ?string $zrodlo = null,
        ?string $status = null,
        ?string $q = null,
        ?string $od = null,
        ?string $do = null,
        int $perPage = 25,
    ): array {
        $perPage = max(1, min(100, $perPage));

        if ($zrodlo !== null && $zrodlo !== '') {
            $this->where('zrodlo', $zrodlo);
        }

        if ($status !== null && $status !== '' && in_array($status, self::STATUSY, true)) {
            $this->where('status', $status);
        }

        $q = $q !== null ? trim($q) : '';
        if ($q !== '') {
            $this->groupStart()
                ->like('blad_text', $q)
                ->orLike('id_wiadomosci', $q)
                ->orLike('checksum', $q)
                ->groupEnd();
        }

        $od = $this->normalizujDate($od);
        if ($od !== null) {
            $this->where('rozpoczeto_at >=', $od);
        }

        $do = $this->normalizujDate($do);
        if ($do !== null) {
            // Wyłącznie następny dzień — obejmuje cały dzień $do niezależnie od strefy w tekście.
            $nastepny = date('Y-m-d', strtotime($do . ' +1 day'));
            $this->where('rozpoczeto_at <', $nastepny);
        }

        return $this->orderBy('id', 'DESC')->paginate($perPage);
    }

    /**
     * Akceptuje YYYY-MM-DD; odrzuca inne wartości.
     */
    private function normalizujDate(?string $wartosc): ?string
    {
        if ($wartosc === null) {
            return null;
        }
        $wartosc = trim($wartosc);
        if ($wartosc === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $wartosc)) {
            return null;
        }

        return $wartosc;
    }
}
