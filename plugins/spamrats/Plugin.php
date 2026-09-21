<?php

namespace McsPluginy\spamrats;

use App\Libraries\Enrichment\SpamratsClient;
use App\Libraries\Enrichment\SpamratsSerwis;
use App\Libraries\ImportKoperta;
use App\Libraries\SourcePluginInterface;
use App\Models\PluginModel;
use RuntimeException;

/**
 * Plugin enrichment SpamRats — DNSBL RATS-All (cache, poza indeksem/badge).
 *
 * Panel: /ip/{ip} → „Sprawdź SpamRats”.
 * Pobierz teraz: smoke-check (domyślnie 127.0.0.36) + aktualizacja health.
 */
class Plugin implements SourcePluginInterface
{
    private ImportKoperta $koperta;
    private SpamratsSerwis $serwis;
    private SpamratsClient $client;

    public function __construct(
        ?ImportKoperta $koperta = null,
        ?SpamratsSerwis $serwis = null,
        ?SpamratsClient $client = null,
    ) {
        $this->koperta = $koperta ?? new ImportKoperta();
        $this->serwis  = $serwis ?? new SpamratsSerwis();
        $this->client  = $client ?? SpamratsClient::fromEnv();
    }

    public function zrodlo(): string
    {
        return 'spamrats';
    }

    public function pobierz(): void
    {
        $importId = $this->koperta->rozpocznij($this->zrodlo(), null, null, [
            'opis' => 'smoke SpamRats DNSBL RATS-All',
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
                $ip = trim((string) env('mcs.spamrats.smokeIp', '127.0.0.36'));
                if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                    $ip = '127.0.0.36';
                }
                $wynik = $this->serwis->sprawdz($ip, true);
            } else {
                $msg = $this->client->brakCredentialsKomunikat();
                $this->koperta->zakonczBlad($importId, $msg);
                $this->aktualizujHealth(false, $msg);
                throw new RuntimeException($msg);
            }

            if (! $wynik['ok']) {
                $msg = (string) ($wynik['blad'] ?? 'błąd SpamRats');
                $this->koperta->zakonczBlad($importId, $msg);
                $this->aktualizujHealth(false, $msg);
                throw new RuntimeException($msg);
            }

            $w   = $wynik['wynik'] ?? [];
            $msg = sprintf(
                'SpamRats OK (tryb=%s, ip=%s, listed=%s, dyna=%s, noptr=%s, spam=%s, auth=%s)',
                $wynik['tryb'],
                $wynik['ip'],
                isset($w['listed']) ? ($w['listed'] ? 'tak' : 'nie') : '—',
                isset($w['listed_dyna']) ? ($w['listed_dyna'] ? 'tak' : 'nie') : '—',
                isset($w['listed_noptr']) ? ($w['listed_noptr'] ? 'tak' : 'nie') : '—',
                isset($w['listed_spam']) ? ($w['listed_spam'] ? 'tak' : 'nie') : '—',
                isset($w['listed_auth']) ? ($w['listed_auth'] ? 'tak' : 'nie') : '—',
            );
            $this->koperta->zakonczOk($importId, [
                'tryb'         => $wynik['tryb'],
                'ip'           => $wynik['ip'],
                'listed'       => $w['listed'] ?? null,
                'listed_dyna'  => $w['listed_dyna'] ?? null,
                'listed_noptr' => $w['listed_noptr'] ?? null,
                'listed_spam'  => $w['listed_spam'] ?? null,
                'listed_auth'  => $w['listed_auth'] ?? null,
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
            'fixture' => 'gotowy fixture (mcs.spamrats.fixturePath)',
            'dns'     => 'gotowe credentials (mcs.spamrats.apiKey)',
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
