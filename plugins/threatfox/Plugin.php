<?php

namespace McsPluginy\threatfox;

use App\Libraries\PluginOpisPolaczenia;
use App\Libraries\SourcePluginInterface;
use App\Models\PluginModel;
use RuntimeException;

/**
 * Plugin ThreatFox (abuse.ch) — zbiorcze IOC (get_iocs, ostatnie 1–7 dni) dopasowane lokalnie
 * do prefiksów AS (mcs_ustawienia.as_cidry). Tylko ioc_type ip / ip:port dają trafienia.
 *
 * API wymaga Auth-Key (mcs.threatfox.authKey). Cron mcs-pobierz.sh woła pobierz() co 15 min;
 * plugin sam pilnuje harmonogramu (domyślnie raz na 6 h + losowa minuta, backoff po błędzie,
 * nigdy częściej niż 1/h).
 *
 * @see https://threatfox.abuse.ch/api/
 */
class Plugin implements SourcePluginInterface
{
    public const ATRYBUCJA = '© abuse.ch — ThreatFox';

    private ?Serwis $serwis;

    /** Komunikat pominięcia z ostatniego pobierz() (harmonogram) — dla mcs:pobierz zamiast starego health. */
    private ?string $pominiecie = null;

    public function __construct(?Serwis $serwis = null)
    {
        $this->serwis = $serwis;
    }

    public function zrodlo(): string
    {
        return Serwis::ZRODLO;
    }

    public function pobierz(): void
    {
        $this->pominiecie = null;
        $wynik            = $this->serwis()->uruchom();
        if ($wynik['status'] === 'pominieto') {
            $this->pominiecie = $wynik['msg'];

            return;
        }
        $ok = $wynik['status'] === 'ok';
        $this->aktualizujHealth($ok, $wynik['msg']);
        if (! $ok) {
            throw new RuntimeException($wynik['msg']);
        }
    }

    /**
     * Ostatni przebieg pominięty przez harmonogram: komunikat, inaczej null (zob. PobierzSerwis).
     */
    public function komunikatPominiecia(): ?string
    {
        return $this->pominiecie;
    }

    /**
     * @return array{ok: bool, msg: string}
     */
    public function health(): array
    {
        $wiersz = model(PluginModel::class)->find($this->zrodlo());
        if (! is_array($wiersz)) {
            return ['ok' => false, 'msg' => 'brak w rejestrze mcs_pluginy'];
        }

        $ok  = array_key_exists('health_ok', $wiersz) ? $wiersz['health_ok'] : null;
        $msg = (string) ($wiersz['health_msg'] ?? '');

        return [
            'ok'  => (bool) ($ok ?? false),
            'msg' => $msg !== '' ? $msg : 'brak przebiegu (pobieranie co kilka godzin)',
        ];
    }

    /**
     * @return array{
     *   skrot: string,
     *   tryb_aktywny: string,
     *   tryb_etykieta: string,
     *   pozycje: list<array{nazwa: string, wartosc: string, aktywna: bool, ustawiona: bool}>
     * }
     */
    public function opisPolaczenia(): array
    {
        $jest = trim(\App\Libraries\OpcjeMcs::tekst('mcs.threatfox.authKey')) !== '';

        return PluginOpisPolaczenia::zloz(
            'http',
            'HTTP',
            'HTTPS threatfox-api.abuse.ch/api/v1/ (POST JSON, Auth-Key)',
            [
                PluginOpisPolaczenia::pozycja('Auth-Key', PluginOpisPolaczenia::sekret($jest), false, $jest),
                PluginOpisPolaczenia::pozycja('API', Klient::URL_API, true, true),
                PluginOpisPolaczenia::pozycja('Zapytanie', 'get_iocs, dni=' . Klient::dniZOpcji() . ' (1–7)', false, true),
                PluginOpisPolaczenia::pozycja(
                    'Harmonogram',
                    'co ' . \App\Libraries\OpcjeMcs::int('mcs.threatfox.interwalGodzin') . ' h + losowa minuta (min. 1 h)',
                    false,
                    true,
                ),
            ],
        );
    }

    private function serwis(): Serwis
    {
        return $this->serwis ??= new Serwis();
    }

    private function aktualizujHealth(bool $ok, string $msg): void
    {
        model(PluginModel::class)->update($this->zrodlo(), [
            'health_ok'  => $ok,
            'health_msg' => mb_substr($msg, 0, 500),
            'health_at'  => gmdate('Y-m-d H:i:sP'),
        ]);
    }
}
