<?php

namespace McsPluginy\play;

use App\Libraries\SourcePluginInterface;
use App\Models\PluginModel;
use RuntimeException;

/**
 * Plugin Play scrubbing — szkielet.
 *
 * Wymaga: osobna skrzynka IMAP (mcs.play.imap*) oraz próbka formatu maila/CSV
 * do domknięcia parsera. Do czasu próbek pobierz() kończy się czytelnym błędem.
 */
class Plugin implements SourcePluginInterface
{
    public function zrodlo(): string
    {
        return 'play';
    }

    public function pobierz(): void
    {
        $fixture = trim((string) env('mcs.play.fixturePath', ''));
        if ($fixture !== '') {
            throw new RuntimeException(
                'Play: fixturePath ustawione, ale parser formatu Play jeszcze niezaimplementowany — potrzebna próbka maila/CSV.',
            );
        }

        $msg = 'Play: brak credentials IMAP (mcs.play.imapHost/User/Pass) oraz brak próbki formatu. '
            . 'Uzupełnij .env i dostarcz przykładowy raport — patrz docs/09-imap-konfiguracja-env.md';
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
            'msg' => (string) ($wiersz['health_msg'] ?? 'szkielet — czekam na próbkę/IMAP'),
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
