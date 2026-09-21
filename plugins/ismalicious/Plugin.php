<?php

namespace McsPluginy\ismalicious;

use App\Libraries\Enrichment\IsmaliciousClient;
use App\Libraries\Enrichment\IsmaliciousSerwis;
use App\Libraries\ImportKoperta;
use App\Libraries\PluginOpisPolaczenia;
use App\Libraries\SourcePluginInterface;
use App\Models\PluginModel;
use RuntimeException;

/**
 * Plugin enrichment isMalicious — lookup reputacji IP (cache, poza indeksem/badge).
 *
 * Panel: /ip/{ip} → „Sprawdź isMalicious”.
 * Pobierz teraz: smoke-check (domyślnie 1.1.1.1) + aktualizacja health.
 */
class Plugin implements SourcePluginInterface
{
    private ImportKoperta $koperta;
    private IsmaliciousSerwis $serwis;
    private IsmaliciousClient $client;

    public function __construct(
        ?ImportKoperta $koperta = null,
        ?IsmaliciousSerwis $serwis = null,
        ?IsmaliciousClient $client = null,
    ) {
        $this->koperta = $koperta ?? new ImportKoperta();
        $this->serwis  = $serwis ?? new IsmaliciousSerwis();
        $this->client  = $client ?? IsmaliciousClient::fromEnv();
    }

    public function zrodlo(): string
    {
        return 'ismalicious';
    }

    public function pobierz(): void
    {
        $importId = $this->koperta->rozpocznij($this->zrodlo(), null, null, [
            'opis' => 'smoke isMalicious /check',
        ]);
        if ($importId === null) {
            $this->aktualizujHealth(true, 'pominięto (duplikat przebiegu)');

            return;
        }

        try {
            if ($this->serwis->hasFixture()) {
                $ip    = '198.51.100.10';
                $wynik = $this->serwis->sprawdz($ip, true);
            } elseif ($this->serwis->hasCredentials()) {
                $ip    = trim((string) env('mcs.ismalicious.smokeIp', '1.1.1.1'));
                if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
                    $ip = '1.1.1.1';
                }
                $wynik = $this->serwis->sprawdz($ip, true);
            } else {
                $msg = $this->client->brakCredentialsKomunikat();
                $this->koperta->zakonczBlad($importId, $msg);
                $this->aktualizujHealth(false, $msg);
                throw new RuntimeException($msg);
            }

            if (! $wynik['ok']) {
                $msg = (string) ($wynik['blad'] ?? 'błąd isMalicious');
                $this->koperta->zakonczBlad($importId, $msg);
                $this->aktualizujHealth(false, $msg);
                throw new RuntimeException($msg);
            }

            $w   = $wynik['wynik'] ?? [];
            $msg = sprintf(
                'isMalicious OK (tryb=%s, ip=%s, malicious=%s, risk=%s)',
                $wynik['tryb'],
                $wynik['ip'],
                isset($w['malicious']) ? ($w['malicious'] ? 'tak' : 'nie') : '—',
                (string) ($w['risk_score'] ?? '—'),
            );
            $this->koperta->zakonczOk($importId, [
                'tryb'       => $wynik['tryb'],
                'ip'         => $wynik['ip'],
                'malicious'  => $w['malicious'] ?? null,
                'risk_score' => $w['risk_score'] ?? null,
            ]);
            $this->aktualizujHealth(true, $msg);
        } catch (\Throwable $e) {
            if ($e instanceof RuntimeException) {
                throw $e;
            }
            $this->koperta->zakonczBlad($importId, $e->getMessage());
            $this->aktualizujHealth(false, $e->getMessage());
            throw $e;
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
        $tryb = $this->serwis->tryb();

        return match ($tryb) {
            'fixture' => 'gotowy fixture (mcs.ismalicious.fixturePath)',
            'api'     => 'gotowe credentials (mcs.ismalicious.apiKey+apiSecret)',
            default   => $this->client->brakCredentialsKomunikat(),
        };
    }

    private function aktualizujHealth(bool $ok, string $msg): void
    {
        model(PluginModel::class)->update($this->zrodlo(), [
            'health_ok'  => $ok,
            'health_msg' => $msg,
            'health_at'  => gmdate('Y-m-d H:i:sP'),
        ]);
    }
}
