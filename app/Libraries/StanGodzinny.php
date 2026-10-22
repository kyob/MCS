<?php

namespace App\Libraries;

use App\Models\UstawieniaModel;

/**
 * Stan harmonogramu importera w mcs_ustawienia:
 * {zrodlo}.ostatnia_proba_at, {zrodlo}.nastepne_po, {zrodlo}.bledy_z_rzedu.
 */
final class StanGodzinny
{
    private UstawieniaModel $model;

    public function __construct(?UstawieniaModel $model = null)
    {
        $this->model = $model ?? model(UstawieniaModel::class);
    }

    /**
     * @return array{ostatnia_proba_at: ?int, nastepne_po: ?int, bledy_z_rzedu: int}
     */
    public function odczyt(string $zrodlo): array
    {
        return [
            'ostatnia_proba_at' => $this->jakoInt($this->model->pobierz($zrodlo . '.ostatnia_proba_at')),
            'nastepne_po'       => $this->jakoInt($this->model->pobierz($zrodlo . '.nastepne_po')),
            'bledy_z_rzedu'     => (int) ($this->model->pobierz($zrodlo . '.bledy_z_rzedu') ?? '0'),
        ];
    }

    public function zapisz(string $zrodlo, int $proba, int $nastepne, int $bledy): void
    {
        $this->model->ustaw($zrodlo . '.ostatnia_proba_at', (string) $proba);
        $this->model->ustaw($zrodlo . '.nastepne_po', (string) $nastepne);
        $this->model->ustaw($zrodlo . '.bledy_z_rzedu', (string) max(0, $bledy));
    }

    private function jakoInt(?string $raw): ?int
    {
        if ($raw === null || trim($raw) === '' || ! is_numeric($raw)) {
            return null;
        }

        return (int) $raw;
    }
}
