<?php

namespace McsPluginy\spamhaus;

use App\Libraries\PluginOpisPolaczenia;
use App\Libraries\SourcePluginInterface;
use App\Models\PluginModel;
use RuntimeException;

/**
 * Plugin Spamhaus DROP — darmowe listy DROP (IPv4), DROPv6 i ASN-DROP dopasowane lokalnie
 * do prefiksów AS (mcs_ustawienia.as198050_cidry) i ASN (mcs.as198050.asn).
 *
 * Bez zapytań DNS (zen/DQS) i bez scrapingu check.spamhaus.org.
 * Cron mcs-pobierz.sh woła pobierz() co 15 min; plugin sam pilnuje harmonogramu
 * (raz na 24 h + losowa minuta, backoff po błędzie, nigdy częściej niż 1/h).
 *
 * @see https://www.spamhaus.org/blocklists/do-not-route-or-peer/
 * @see https://www.spamhaus.org/blocklists/drop-fair-use-policy/
 */
class Plugin implements SourcePluginInterface
{
    public const ATRYBUCJA = '© The Spamhaus Project';

    private ?Serwis $serwis;

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
        $wynik = $this->serwis()->uruchom();
        if ($wynik['status'] === 'pominieto') {
            return;
        }
        $ok = $wynik['status'] === 'ok';
        $this->aktualizujHealth($ok, $wynik['msg']);
        if (! $ok) {
            throw new RuntimeException($wynik['msg']);
        }
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
            'msg' => $msg !== '' ? $msg : 'brak przebiegu (pobieranie raz na dobę)',
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
        $pozycje = [];
        foreach (array_keys(DropKlient::PLIKI) as $lista) {
            $pozycje[] = PluginOpisPolaczenia::pozycja($lista, DropKlient::url($lista), true, true);
        }
        $pozycje[] = PluginOpisPolaczenia::pozycja(
            'Harmonogram',
            'co ' . \App\Libraries\OpcjeMcs::int('mcs.spamhaus.interwalGodzin') . ' h + losowa minuta (min. 1 h)',
            false,
            true,
        );

        return PluginOpisPolaczenia::zloz('http', 'HTTP', 'HTTPS ' . DropKlient::URL_BAZA . ' (DROP, DROPv6, ASN-DROP)', $pozycje);
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
