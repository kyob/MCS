<?php

namespace App\Libraries;

use App\Models\PluginModel;

/**
 * Ocena zdrowia wtyczek-importerów (mcs_pluginy.health_*) dla monitoringu (Icinga).
 *
 * Pomija wtyczki z import_wstrzymany. Wynik OK tylko wtedy, gdy każda aktywna wtyczka
 * ma health_ok=true i świeży health_at.
 */
class ZdrowieWtyczek
{
    public const STALE_GODZIN = 48;

    /**
     * @param list<array<string, mixed>> $wiersze wiersze z PluginModel::wszystkie()
     *
     * @return array{
     *   status: string,
     *   liczba: int,
     *   opis: string,
     *   wtyczki: list<array{zrodlo: string, ok: ?bool, health_at: ?string, msg: string, problem: string}>
     * }
     */
    public static function ocen(array $wiersze, ?\DateTimeImmutable $teraz = null, int $staleGodzin = self::STALE_GODZIN): array
    {
        $teraz = $teraz ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $graniczny = $teraz->modify('-' . max(1, $staleGodzin) . ' hours')->getTimestamp();

        $wtyczki = [];
        $bledy   = [];
        $uwagi   = [];

        foreach ($wiersze as $w) {
            if (PluginModel::jakoBool($w['import_wstrzymany'] ?? false)) {
                continue;
            }
            $zrodlo = (string) ($w['zrodlo'] ?? '');
            $okRaw  = $w['health_ok'] ?? null;
            $ok     = $okRaw === null ? null : PluginModel::jakoBool($okRaw);
            $at     = isset($w['health_at']) && $w['health_at'] !== '' ? (string) $w['health_at'] : null;
            $msg    = mb_substr(trim((string) ($w['health_msg'] ?? '')), 0, 120);
            $ts     = $at !== null ? strtotime($at) : false;

            $problem = '';
            if ($ok === false) {
                $problem = 'błąd';
                $bledy[] = $zrodlo . ': ' . ($msg !== '' ? $msg : 'health_ok=false');
            } elseif ($ok === null || $ts === false) {
                $problem = 'bez przebiegu';
                $uwagi[] = $zrodlo . ': bez przebiegu';
            } elseif ($ts < $graniczny) {
                $problem = 'nieaktualny';
                $uwagi[] = $zrodlo . ': health starszy niż ' . $staleGodzin . ' h (' . $at . ')';
            }

            $wtyczki[] = [
                'zrodlo'    => $zrodlo,
                'ok'        => $ok,
                'health_at' => $at,
                'msg'       => $msg,
                'problem'   => $problem,
            ];
        }

        $liczba = count($wtyczki);
        if ($bledy !== []) {
            $status = 'CRITICAL';
            $opis   = implode('; ', array_merge($bledy, $uwagi));
        } elseif ($uwagi !== []) {
            $status = 'WARNING';
            $opis   = implode('; ', $uwagi);
        } else {
            $status = 'OK';
            $opis   = $liczba . ' aktywnych wtyczek, wszystkie zdrowe';
        }

        return ['status' => $status, 'liczba' => $liczba, 'opis' => $opis, 'wtyczki' => $wtyczki];
    }
}
