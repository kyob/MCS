<?php

namespace McsPluginy\abusix;

use App\Libraries\ImportKoperta;
use App\Libraries\PluginOpisPolaczenia;
use App\Libraries\SourcePluginInterface;
use App\Models\PluginModel;
use RuntimeException;

/**
 * Plugin Abusix (Guardian Ops API) — wersja podstawowa.
 *
 * pobierz(): odpytuje /beta/guardian-ops/cases (podsumowanie wg stanu) i /customers,
 * zapisuje wynik w health (karta źródła). Bez tabeli i bez indeksu badge.
 */
class Plugin implements SourcePluginInterface
{
    private ImportKoperta $koperta;
    private ?ApiClient $apiClient;

    public function __construct(?ImportKoperta $koperta = null, ?ApiClient $apiClient = null)
    {
        $this->koperta   = $koperta ?? new ImportKoperta();
        $this->apiClient = $apiClient;
    }

    public function zrodlo(): string
    {
        return 'abusix';
    }

    public function pobierz(): void
    {
        $client = $this->apiClient ?? ApiClient::fromEnv();
        if (! $client->hasAuth()) {
            $msg = 'Abusix: ustaw mcs.abusix.apiKey w .env';
            $this->aktualizujHealth(false, $msg);
            throw new RuntimeException($msg);
        }

        $importId = $this->koperta->rozpocznij($this->zrodlo(), null, null, ['opis' => 'Abusix Guardian Ops: cases + customers']);
        if ($importId === null) {
            $this->aktualizujHealth(true, 'pominięto (duplikat przebiegu)');

            return;
        }

        $cases = $client->listCases();
        if (! $cases['ok']) {
            $this->blad($importId, 'Abusix API cases: ' . $cases['error']);
        }
        $customers = $client->listCustomers();
        if (! $customers['ok']) {
            $this->blad($importId, 'Abusix API customers: ' . $customers['error']);
        }

        $wgStanu = [];
        foreach ((array) ($cases['data']['items'] ?? []) as $c) {
            $stan           = (string) ($c['state'] ?? '?');
            $wgStanu[$stan] = ($wgStanu[$stan] ?? 0) + 1;
        }
        ksort($wgStanu);
        $nCases = array_sum($wgStanu);
        $nCust  = count((array) ($customers['data']['items'] ?? []));
        $wiecej = ! empty($cases['data']['next_cursor']) ? '+' : '';

        $stany = [];
        foreach ($wgStanu as $k => $v) {
            $stany[] = "{$k}={$v}";
        }
        $msg = sprintf(
            'Abusix OK (sprawy=%d%s [%s], klienci=%d%s)',
            $nCases,
            $wiecej,
            implode(', ', $stany),
            $nCust,
            ! empty($customers['data']['next_cursor']) ? '+' : '',
        );

        $this->koperta->zakonczOk($importId, ['sprawy' => $nCases, 'stany' => $wgStanu, 'klienci' => $nCust]);
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
        if ($msg === '' && $ok === null) {
            $msg = ($this->apiClient ?? ApiClient::fromEnv())->hasAuth()
                ? 'konfiguracja: token API ustawiony (jeszcze bez przebiegu)'
                : 'brak mcs.abusix.apiKey';
        }

        return ['ok' => (bool) ($ok ?? false), 'msg' => $msg !== '' ? $msg : 'brak przebiegu'];
    }

    /**
     * @return array{skrot: string, tryb_aktywny: string, tryb_etykieta: string, pozycje: list<array{nazwa: string, wartosc: string, aktywna: bool, ustawiona: bool}>}
     */
    public function opisPolaczenia(): array
    {
        $client = $this->apiClient ?? ApiClient::fromEnv();
        $ok     = $client->hasAuth();
        $host   = PluginOpisPolaczenia::hostZUrl($client->getBaseUrl());

        return PluginOpisPolaczenia::zloz(
            $ok ? 'api' : 'brak',
            $ok ? 'API (Bearer)' : 'brak konfiguracji',
            $ok ? 'API · ' . $host : 'brak konfiguracji',
            [
                PluginOpisPolaczenia::pozycja('API Abusix', $client->getBaseUrl(), $ok),
                PluginOpisPolaczenia::pozycja('Token', PluginOpisPolaczenia::sekret($ok), false, $ok),
            ],
        );
    }

    private function blad(int $importId, string $msg): never
    {
        $this->koperta->zakonczBlad($importId, $msg);
        $this->aktualizujHealth(false, $msg);
        throw new RuntimeException($msg);
    }

    private function aktualizujHealth(bool $ok, string $msg): void
    {
        model(PluginModel::class)->update($this->zrodlo(), [
            'health_at'  => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:sP'),
            'health_ok'  => $ok,
            'health_msg' => mb_substr($msg, 0, 500),
        ]);
    }
}
