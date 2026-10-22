<?php

namespace McsPluginy\blocklist_de;

use App\Libraries\PluginOpisPolaczenia;
use App\Libraries\SourcePluginInterface;
use App\Models\PluginModel;
use RuntimeException;

/**
 * Importer blocklist.de — listy ataków z 48 h, tylko adresy z prefiksów AS.
 * Cron co 15 min; plugin sam czeka godzinę między próbami.
 */
class Plugin implements SourcePluginInterface
{
    private ?Serwis $serwis;

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
            'msg' => $msg !== '' ? $msg : 'brak przebiegu (pobieranie raz na godzinę)',
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
        foreach (Listy::DOZWOLONE as $nazwa) {
            $pozycje[] = PluginOpisPolaczenia::pozycja($nazwa, Klient::urlTxt($nazwa), true, true);
        }
        $pozycje[] = PluginOpisPolaczenia::pozycja('Harmonogram', 'co 1 h (min. 1 h)', false, true);

        return PluginOpisPolaczenia::zloz(
            'http',
            'HTTP',
            'HTTPS lists.blocklist.de (ssh, mail, bruteforcelogin, bots, strongips)',
            $pozycje,
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
