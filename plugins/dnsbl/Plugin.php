<?php

namespace McsPluginy\dnsbl;

use App\Libraries\Enrichment\DnsblClient;
use App\Libraries\Enrichment\DnsblSerwis;
use App\Libraries\ImportKoperta;
use App\Libraries\SourcePluginInterface;
use App\Models\PluginModel;
use RuntimeException;

/**
 * Plugin stream DNSBL — skan LMS (codziennie) / pełne AS (dzień asScanWeekday).
 * Incydenty w indeksie + enrichment na /ip/{ip} + /dashboard/dnsbl.
 */
class Plugin implements SourcePluginInterface
{
    private ImportKoperta $koperta;
    private DnsblSerwis $serwis;
    private DnsblClient $client;

    public function __construct(
        ?ImportKoperta $koperta = null,
        ?DnsblSerwis $serwis = null,
        ?DnsblClient $client = null,
    ) {
        $this->koperta = $koperta ?? new ImportKoperta();
        $this->serwis  = $serwis ?? new DnsblSerwis();
        $this->client  = $client ?? DnsblClient::fromEnv();
    }

    public function zrodlo(): string
    {
        return 'dnsbl';
    }

    public function pobierz(): void
    {
        $zakres   = $this->serwis->wybierzZakres();
        $importId = $this->koperta->rozpocznij($this->zrodlo(), null, null, [
            'opis'   => 'skan DNSBL zakres=' . $zakres,
            'zakres' => $zakres,
        ]);
        if ($importId === null) {
            $this->aktualizujHealth(true, 'pominięto (duplikat przebiegu)');

            return;
        }

        try {
            $wynik = $this->serwis->skanuj($importId, $zakres);
            $msg   = sprintf(
                'DNSBL OK zakres=%s sprawdzono=%d listed=%d incydenty=%d strefy=%s',
                $wynik['zakres'],
                $wynik['sprawdzono'],
                $wynik['listed'],
                $wynik['incydenty'],
                implode(',', array_keys($this->client->aktywneStrefy())),
            );
            $this->koperta->zakonczOk($importId, [
                'zakres'     => $wynik['zakres'],
                'sprawdzono' => $wynik['sprawdzono'],
                'listed'     => $wynik['listed'],
                'incydenty'  => $wynik['incydenty'],
            ]);
            $this->aktualizujHealth(true, $msg);
        } catch (\Throwable $e) {
            $this->koperta->zakonczBlad($importId, $e->getMessage());
            $this->aktualizujHealth(false, $e->getMessage());
            throw $e instanceof RuntimeException ? $e : new RuntimeException($e->getMessage(), 0, $e);
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

        if ($msg === '' && $ok === null) {
            $msg = $this->opisKonfiguracji();
        }

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

    private function opisKonfiguracji(): string
    {
        if ($this->serwis->hasFixture()) {
            return 'gotowy plik testowy (mcs.dnsbl.fixturePath)';
        }

        $strefy = implode(', ', array_keys($this->client->aktywneStrefy()));

        return 'DNSBL strefy: ' . ($strefy !== '' ? $strefy : 'brak');
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
