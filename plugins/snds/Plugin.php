<?php

namespace McsPluginy\snds;

use App\Libraries\SourcePluginInterface;
use App\Models\PluginModel;
use RuntimeException;

/**
 * Plugin SNDS (Microsoft) — szkielet.
 *
 * Zazwyczaj bez IMAP. Klucze .env (do uzupełnienia):
 * - mcs.snds.dataUrl   (URL danych / endpoint)
 * - mcs.snds.apiKey    (jeśli wymagany)
 * - mcs.snds.fixturePath (dev)
 */
class Plugin implements SourcePluginInterface
{
    public function zrodlo(): string
    {
        return 'snds';
    }

    public function pobierz(): void
    {
        $fixture = trim((string) env('mcs.snds.fixturePath', ''));
        $url     = trim((string) env('mcs.snds.dataUrl', ''));
        $key     = trim((string) env('mcs.snds.apiKey', ''));

        if ($fixture !== '') {
            throw new RuntimeException(
                'SNDS: fixturePath ustawione, ale parser jeszcze niezaimplementowany — potrzebna próbka danych SNDS.',
            );
        }

        if ($url === '' && $key === '') {
            $msg = 'SNDS: uzupełnij mcs.snds.dataUrl i/lub mcs.snds.apiKey w .env (albo fixturePath + próbka).';
            $this->aktualizujHealth(false, $msg);
            throw new RuntimeException($msg);
        }

        $msg = 'SNDS: credentials częściowo obecne, parser/API client jeszcze niezaimplementowany.';
        $this->aktualizujHealth(false, $msg);
        throw new RuntimeException($msg);
    }

    public function health(): array
    {
        $wiersz = model(PluginModel::class)->find($this->zrodlo());
        if (! is_array($wiersz)) {
            return ['ok' => false, 'msg' => 'brak w rejestrze mcs_pluginy'];
        }

        return [
            'ok'  => (bool) ($wiersz['health_ok'] ?? false),
            'msg' => (string) ($wiersz['health_msg'] ?? 'szkielet — czekam na API/próbkę'),
        ];
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
