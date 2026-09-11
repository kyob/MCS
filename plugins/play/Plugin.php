<?php

namespace McsPluginy\play;

use App\Libraries\Imap\ImapKlientFabryka;
use App\Libraries\Imap\ImapKlientInterface;
use App\Libraries\SourcePluginInterface;
use App\Models\PluginModel;
use RuntimeException;

/**
 * Plugin Play scrubbing.
 *
 * IMAP (mcs.play.imap*) jest gotowy. Parser formatu raportu wymaga prawdziwej
 * probki CSV/maila od Play — skrzynka testowa miala tylko maile „Witamy”/„test”
 * bez zalacznikow (stan 2026-09-11).
 */
class Plugin implements SourcePluginInterface
{
    private ?ImapKlientFabryka $imapFabryka;

    public function __construct(?ImapKlientFabryka $imapFabryka = null)
    {
        $this->imapFabryka = $imapFabryka;
    }

    public function zrodlo(): string
    {
        return 'play';
    }

    public function pobierz(): void
    {
        $fixture = trim((string) env('mcs.play.fixturePath', ''));
        if ($fixture !== '') {
            if (! is_readable($fixture)) {
                throw new RuntimeException('Play: nie mozna odczytac fixturePath=' . $fixture);
            }
            $msg = 'Play: fixturePath ustawione, ale parser formatu Play jeszcze niezaimplementowany'
                . ' — potrzebna prawdziwa probka raportu scrubbing (CSV/zalacznik).';
            $this->aktualizujHealth(false, $msg);
            throw new RuntimeException($msg);
        }

        $fabryka = $this->imapFabryka ?? new ImapKlientFabryka();
        try {
            $klient = $fabryka->dlaZrodla('play');
        } catch (RuntimeException $e) {
            $this->aktualizujHealth(false, $e->getMessage());
            throw $e;
        }

        try {
            $raport = $this->zbadajSkrzynke($klient);
            $msg    = sprintf(
                'Play IMAP OK (wiadomosci=%d, z_csv_lub_zip=%d) — brak parsera: potrzebna prawdziwa'
                . ' probka raportu scrubbing (obecnie brak uzytecznych zalacznikow CSV).',
                $raport['wiadomosci'],
                $raport['z_csv'],
            );
            if ($raport['wiadomosci'] === 0) {
                $msg = 'Play IMAP OK — skrzynka pusta (0 wiadomosci). Czekam na raport scrubbing.';
            } elseif ($raport['z_csv'] === 0) {
                $msg = sprintf(
                    'Play IMAP OK — %d wiadomosci, 0 zalacznikow CSV/ZIP z danymi.'
                    . ' Skrzynka ma tylko maile testowe/powitalne; potrzebna probka raportu Play.',
                    $raport['wiadomosci'],
                );
            }
            $this->aktualizujHealth(false, $msg);
            throw new RuntimeException($msg);
        } finally {
            $klient->zamknij();
        }
    }

    public function health(): array
    {
        $wiersz = model(PluginModel::class)->find($this->zrodlo());
        if (! is_array($wiersz)) {
            return ['ok' => false, 'msg' => 'brak w rejestrze mcs_pluginy'];
        }

        return [
            'ok'  => (bool) ($wiersz['health_ok'] ?? false),
            'msg' => (string) ($wiersz['health_msg'] ?? 'szkielet — czekam na probke/IMAP'),
        ];
    }

    /**
     * @return array{wiadomosci: int, z_csv: int}
     */
    private function zbadajSkrzynke(ImapKlientInterface $klient): array
    {
        $uidy = $klient->listujUid(false);
        $zCsv = 0;
        // Limit sondy — nie sciagamy calej historii przy kazdym health-checku.
        $sonda = array_slice($uidy, 0, 50);
        foreach ($sonda as $uid) {
            $w = $klient->pobierzWiadomosc($uid);
            foreach ($w['zalaczniki'] as $zal) {
                $n = strtolower($zal['filename']);
                if (str_ends_with($n, '.csv') || str_ends_with($n, '.zip')) {
                    $zCsv++;
                }
            }
        }

        return [
            'wiadomosci' => count($uidy),
            'z_csv'      => $zCsv,
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
