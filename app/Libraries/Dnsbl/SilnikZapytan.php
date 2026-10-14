<?php

namespace App\Libraries\Dnsbl;

/**
 * Asynchroniczne zapytania DNS po UDP do jednego resolvera (domyślnie lokalny unbound 127.0.0.1).
 *
 * - limit QPS na grupę (strefę),
 * - globalny limit zapytań w locie,
 * - timeout + N ponowień,
 * - anulowanie grupy w trakcie (np. kod 127.255.255.x).
 */
class SilnikZapytan
{
    private string $serwer;
    private int $port;
    private float $odstep;
    private int $wLocie;
    private float $timeout;
    private int $retry;

    /** @var array<string, true> */
    private array $anulowane = [];

    public function __construct(
        string $serwer = '127.0.0.1',
        int $port = 53,
        float $qpsGrupa = 10.0,
        int $wLocie = 32,
        float $timeout = 2.0,
        int $retry = 1,
    ) {
        $this->serwer  = $serwer;
        $this->port    = $port;
        $this->odstep  = 1.0 / max(0.1, $qpsGrupa);
        $this->wLocie  = max(1, $wLocie);
        $this->timeout = max(0.1, $timeout);
        $this->retry   = max(0, $retry);
    }

    public function anulujGrupe(string $grupa): void
    {
        $this->anulowane[$grupa] = true;
    }

    /**
     * @param list<array{klucz: string, grupa: string, nazwa: string, typ: int}> $zadania
     * @param callable(string, array<string, mixed>, array<string, mixed>): void|null $poOdpowiedzi (klucz, wynik, zadanie)
     *
     * @return array<string, array{status: string, rcode: ?int, a: list<string>, txt: list<string>, blad: string}>
     */
    public function wykonaj(array $zadania, ?callable $poOdpowiedzi = null): array
    {
        /** @var array<string, list<array{0: array<string, mixed>, 1: int}>> $kolejki */
        $kolejki  = [];
        $nastepny = [];
        foreach ($zadania as $z) {
            $kolejki[$z['grupa']][] = [$z, 0];
            $nastepny[$z['grupa']]  = 0.0;
        }
        foreach ($kolejki as $g => $q) {
            $kolejki[$g] = new \SplQueue();
            foreach ($q as $el) {
                $kolejki[$g]->enqueue($el);
            }
        }

        $wyniki = [];
        /** @var array<int, array{s: resource, z: array<string, mixed>, proba: int, id: int, termin: float}> $wLocie */
        $wLocie = [];

        $zapisz = function (array $z, array $w) use (&$wyniki, $poOdpowiedzi): void {
            $wyniki[$z['klucz']] = $w;
            if ($poOdpowiedzi !== null) {
                $poOdpowiedzi($z['klucz'], $w, $z);
            }
        };

        while (true) {
            $teraz = microtime(true);

            // anulowane grupy: wyczyść kolejki, zamknij zapytania w locie
            foreach (array_keys($this->anulowane) as $g) {
                if (isset($kolejki[$g])) {
                    while (! $kolejki[$g]->isEmpty()) {
                        [$z] = $kolejki[$g]->dequeue();
                        $wyniki[$z['klucz']] = self::wynik('anulowane', null, [], [], 'strefa przerwana');
                    }
                }
                foreach ($wLocie as $k => $f) {
                    if ($f['z']['grupa'] === $g) {
                        fclose($f['s']);
                        unset($wLocie[$k]);
                        $wyniki[$f['z']['klucz']] = self::wynik('anulowane', null, [], [], 'strefa przerwana');
                    }
                }
            }

            // wypełnij okno zapytań (round-robin po grupach)
            $dodano = true;
            while ($dodano && count($wLocie) < $this->wLocie) {
                $dodano = false;
                foreach ($kolejki as $g => $q) {
                    if (count($wLocie) >= $this->wLocie) {
                        break;
                    }
                    if ($q->isEmpty() || $nastepny[$g] > $teraz) {
                        continue;
                    }
                    [$z, $proba] = $q->dequeue();
                    $nastepny[$g] = max($nastepny[$g], $teraz) + $this->odstep;
                    $f = $this->wyslij($z, $proba);
                    if (is_array($f)) {
                        $wLocie[(int) $f['s']] = $f;
                        $dodano                = true;
                    } else {
                        $zapisz($z, self::wynik('blad', null, [], [], $f));
                    }
                }
            }

            // timeouty
            $teraz = microtime(true);
            foreach ($wLocie as $k => $f) {
                if ($f['termin'] <= $teraz) {
                    fclose($f['s']);
                    unset($wLocie[$k]);
                    if ($f['proba'] < $this->retry) {
                        // ponowienie: na początek kolejki grupy (dalej z limitem QPS)
                        $kolejki[$f['z']['grupa']]->unshift([$f['z'], $f['proba'] + 1]);
                    } else {
                        $zapisz($f['z'], self::wynik('timeout', null, [], [], 'timeout'));
                    }
                }
            }

            $czekajace = 0;
            $najblizszy = INF;
            foreach ($kolejki as $g => $q) {
                if (! $q->isEmpty()) {
                    $czekajace += $q->count();
                    $najblizszy = min($najblizszy, $nastepny[$g]);
                }
            }
            if ($wLocie === [] && $czekajace === 0) {
                break;
            }

            $termin = $najblizszy;
            foreach ($wLocie as $f) {
                $termin = min($termin, $f['termin']);
            }
            $czas = max(0.002, min(0.5, $termin - microtime(true)));

            if ($wLocie === []) {
                usleep((int) ($czas * 1_000_000));

                continue;
            }

            $czytaj = [];
            foreach ($wLocie as $f) {
                $czytaj[] = $f['s'];
            }
            $zapis = null;
            $wyj   = null;
            $sek   = (int) floor($czas);
            $usek  = (int) (($czas - $sek) * 1_000_000);
            $n     = @stream_select($czytaj, $zapis, $wyj, $sek, $usek);
            if ($n === false || $n === 0) {
                continue;
            }

            foreach ($czytaj as $s) {
                $k = (int) $s;
                if (! isset($wLocie[$k])) {
                    continue;
                }
                $f   = $wLocie[$k];
                $buf = @stream_socket_recvfrom($s, 4096);
                if ($buf === false || $buf === '') {
                    fclose($s);
                    unset($wLocie[$k]);
                    $zapisz($f['z'], self::wynik('blad', null, [], [], 'brak odpowiedzi resolvera (ICMP/odmowa)'));

                    continue;
                }
                try {
                    $odp = DnsPakiet::parsuj($buf);
                } catch (\Throwable $e) {
                    // śmieciowy pakiet — czekaj dalej na właściwy do terminu
                    continue;
                }
                if ($odp['id'] !== $f['id']) {
                    continue;
                }
                fclose($s);
                unset($wLocie[$k]);
                $zapisz($f['z'], self::wynik('odp', $odp['rcode'], $odp['a'], $odp['txt'], $odp['tc'] ? 'TC' : ''));
            }
        }

        $this->anulowane = [];

        return $wyniki;
    }

    /**
     * @param array<string, mixed> $z
     *
     * @return array{s: resource, z: array<string, mixed>, proba: int, id: int, termin: float}|string
     */
    private function wyslij(array $z, int $proba): array|string
    {
        try {
            $id  = random_int(0, 0xFFFF);
            $pak = DnsPakiet::zapytanie($id, (string) $z['nazwa'], (int) $z['typ']);
        } catch (\Throwable $e) {
            return 'nieprawidłowe zapytanie: ' . $e->getMessage();
        }
        $err = 0;
        $msg = '';
        $s   = @stream_socket_client('udp://' . $this->serwer . ':' . $this->port, $err, $msg, 1.0);
        if ($s === false) {
            return 'socket: ' . $msg;
        }
        stream_set_blocking($s, false);
        if (@fwrite($s, $pak) !== strlen($pak)) {
            fclose($s);

            return 'nie wysłano zapytania';
        }

        return ['s' => $s, 'z' => $z, 'proba' => $proba, 'id' => $id, 'termin' => microtime(true) + $this->timeout];
    }

    /**
     * @param list<string> $a
     * @param list<string> $txt
     *
     * @return array{status: string, rcode: ?int, a: list<string>, txt: list<string>, blad: string}
     */
    public static function wynik(string $status, ?int $rcode, array $a, array $txt, string $blad): array
    {
        return ['status' => $status, 'rcode' => $rcode, 'a' => $a, 'txt' => $txt, 'blad' => $blad];
    }
}
