<?php

namespace McsPluginy\greynoise;

use App\Libraries\Enrichment\GreynoiseSerwis;
use App\Libraries\SourcePluginInterface;
use App\Models\PluginModel;
use RuntimeException;

/**
 * Enrichment GreyNoise. pobierz() nie odpytuje API (limit darmowego konta).
 */
class Plugin implements SourcePluginInterface
{
    private GreynoiseSerwis $serwis;

    public function __construct(?GreynoiseSerwis $serwis = null)
    {
        $this->serwis = $serwis ?? new GreynoiseSerwis();
    }

    public function zrodlo(): string
    {
        return 'greynoise';
    }

    public function pobierz(): void
    {
        if (! $this->serwis->hasCredentials() && ! $this->serwis->hasFixture()) {
            $msg = 'Brak klucza GreyNoise. Wtyczki → GreyNoise → Klucz.';
            $this->aktualizujHealth(false, $msg);
            throw new RuntimeException($msg);
        }

        $msg = $this->serwis->hasCredentials()
            ? 'GreyNoise: klucz ustawiony; cron nie odpytuje API, lookup ręczny w /ip/{ip}'
            : 'GreyNoise: plik lokalny; cron nie odpytuje API, lookup ręczny w /ip/{ip}';
        $this->aktualizujHealth(true, $msg);
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
            'msg' => $msg !== '' ? $msg : 'brak przebiegu',
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
        return $this->serwis->opisPolaczenia();
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
