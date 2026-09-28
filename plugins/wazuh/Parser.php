<?php

namespace McsPluginy\wazuh;

use RuntimeException;

/**
 * Parser eksportu Wazuh (kontrakt wersja 1, docs/11-plan-zrodlo-wazuh.md).
 * Wiersz = agregat ip + kategoria_wazuh + dzień (UTC).
 */
class Parser
{
    public const MAPA_WERSJA = 'wazuh-v1';

    public const KATEGORIE = ['spam', 'smtp-auth', 'ssh-brute', 'web-attack', 'other'];

    /**
     * @return array{wygenerowano: string, okno_od: string, okno_do: string, wiersze: list<array<string, mixed>>}
     */
    public function parsujEksport(string $json): array
    {
        $dane = json_decode($json, true);
        if (! is_array($dane)) {
            throw new RuntimeException('Wazuh: eksport nie jest poprawnym JSON');
        }
        if ((int) ($dane['wersja'] ?? 0) !== 1) {
            throw new RuntimeException('Wazuh: nieobsługiwana wersja eksportu: ' . json_encode($dane['wersja'] ?? null));
        }
        if (! isset($dane['wiersze']) || ! is_array($dane['wiersze'])) {
            throw new RuntimeException('Wazuh: brak listy wiersze');
        }

        return [
            'wygenerowano' => (string) ($dane['wygenerowano'] ?? ''),
            'okno_od'      => (string) ($dane['okno_od'] ?? ''),
            'okno_do'      => (string) ($dane['okno_do'] ?? ''),
            'wiersze'      => array_values(array_filter($dane['wiersze'], 'is_array')),
        ];
    }

    /**
     * @param array<string, mixed> $w
     *
     * @return array{
     *   ip: string, dzien: string, kategoria_wazuh: string, liczba: int, poziom_max: int,
     *   pierwsze_at: string|null, ostatnie_at: string, hosty_docelowe: list<string>,
     *   reguly: list<array<string, mixed>>, klucz_dedupu: string, raw: array<string, mixed>
     * }|null
     */
    public function mapujWiersz(array $w): ?array
    {
        $ip = trim((string) ($w['ip'] ?? ''));
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $dzien = (string) ($w['dzien'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dzien) !== 1) {
            return null;
        }
        $kat = (string) ($w['kategoria_wazuh'] ?? '');
        if (! in_array($kat, self::KATEGORIE, true)) {
            return null;
        }
        $liczba = (int) ($w['liczba'] ?? 0);
        if ($liczba < 1) {
            return null;
        }
        $ostatnie = $this->czas($w['ostatnie'] ?? null);
        if ($ostatnie === null) {
            return null;
        }

        $hosty = [];
        foreach ((array) ($w['hosty_docelowe'] ?? []) as $h) {
            if (is_string($h) && $h !== '') {
                $hosty[] = $h;
            }
        }
        $reguly = [];
        foreach ((array) ($w['reguly'] ?? []) as $r) {
            if (is_array($r)) {
                $reguly[] = [
                    'id'     => (string) ($r['id'] ?? ''),
                    'opis'   => mb_substr((string) ($r['opis'] ?? ''), 0, 200),
                    'poziom' => (int) ($r['poziom'] ?? 0),
                    'liczba' => (int) ($r['liczba'] ?? 0),
                ];
            }
        }

        return [
            'ip'              => $ip,
            'dzien'           => $dzien,
            'kategoria_wazuh' => $kat,
            'liczba'          => $liczba,
            'poziom_max'      => (int) ($w['poziom_max'] ?? 0),
            'pierwsze_at'     => $this->czas($w['pierwsze'] ?? null),
            'ostatnie_at'     => $ostatnie,
            'hosty_docelowe'  => $hosty,
            'reguly'          => $reguly,
            'klucz_dedupu'    => 'wazuh|' . $ip . '|' . $kat . '|' . $dzien,
            'raw'             => $w,
        ];
    }

    /**
     * @param array{kategoria_wazuh: string, liczba: int, hosty_docelowe: list<string>} $m
     */
    public function tytul(array $m): string
    {
        $t = 'Wazuh: ' . $m['liczba'] . '× ' . $m['kategoria_wazuh'];
        if ($m['hosty_docelowe'] !== []) {
            $t .= ' na ' . implode(', ', array_slice($m['hosty_docelowe'], 0, 3));
        }

        return mb_substr($t, 0, 255);
    }

    private function czas(mixed $v): ?string
    {
        if (! is_string($v) || $v === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($v))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
        } catch (\Exception) {
            return null;
        }
    }
}
