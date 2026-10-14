<?php

namespace App\Libraries\Dnsbl;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Dane dla /dashboard/dnsbl (skaner) i karty DNSBL na /ip/{ip}. Bez zapytań DNS.
 */
class SkanyWidok
{
    public const FILTRY = ['listed', 'clean', 'bledy', 'all'];

    /** Skan bez heartbeat dłużej niż tyle sekund = porzucony. */
    private const PORZUCONY_S = 300;

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function oznaczPorzucone(): void
    {
        $granica = gmdate('Y-m-d H:i:sP', time() - self::PORZUCONY_S);
        $this->db->table('mcs_dnsbl_skany')
            ->whereIn('status', ['oczekuje', 'w_toku'])
            ->where('COALESCE(heartbeat_at, utworzono_at) < ' . $this->db->escape($granica), null, false)
            ->update([
                'status'    => 'blad',
                'koniec_at' => gmdate('Y-m-d H:i:sP'),
                'komunikat' => 'Przerwany: brak heartbeat ponad ' . (self::PORZUCONY_S / 60) . ' min.',
            ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function trwajacy(): ?array
    {
        $r = $this->db->table('mcs_dnsbl_skany')->whereIn('status', ['oczekuje', 'w_toku'])
            ->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();

        return is_array($r) ? $this->dekoduj($r) : null;
    }

    /**
     * Ostatni zakończony skan tego samego zakresu (wszystkie strefy) w oknie TTL.
     *
     * @return array<string, mixed>|null
     */
    public function zCache(string $zakres, int $ttl): ?array
    {
        if ($ttl <= 0) {
            return null;
        }
        $r = $this->db->table('mcs_dnsbl_skany')
            ->where('zakres', $zakres)->where('strefa_filtr', null)->where('status', 'zakonczony')
            ->where('koniec_at >=', gmdate('Y-m-d H:i:sP', time() - $ttl))
            ->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();

        return is_array($r) ? $this->dekoduj($r) : null;
    }

    /**
     * Sekundy do końca blokady ponownego skanu zakresu (0 = można).
     */
    public function blokadaS(string $zakres, int $minuty): int
    {
        if ($minuty <= 0) {
            return 0;
        }
        $r = $this->db->table('mcs_dnsbl_skany')->select('utworzono_at')
            ->where('zakres', $zakres)->where('status !=', 'blad')
            ->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();
        if (! is_array($r)) {
            return 0;
        }
        $koniec = strtotime((string) $r['utworzono_at']) + $minuty * 60;

        return max(0, $koniec - time());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function skan(int $id): ?array
    {
        $r = $this->db->table('mcs_dnsbl_skany')->where('id', $id)->get()->getRowArray();

        return is_array($r) ? $this->dekoduj($r) : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function ostatnie(int $limit = 10): array
    {
        $out = [];
        foreach ($this->db->table('mcs_dnsbl_skany')->orderBy('id', 'DESC')->limit($limit)->get()->getResultArray() as $r) {
            $out[] = $this->dekoduj($r);
        }

        return $out;
    }

    /**
     * Strefy skanu z listą trafień (do tabeli „per strefa”).
     *
     * @param array<string, mixed> $skan
     *
     * @return list<array<string, mixed>>
     */
    public function strefySkanu(array $skan): array
    {
        $trafienia = [];
        foreach ($this->db->table('mcs_dnsbl_wyniki')->select('ip, strefa_kod, kody, txt')
            ->where('skan_id', $skan['id'])->where('status', 'listed')->orderBy('ip')->get()->getResultArray() as $r) {
            $trafienia[$r['strefa_kod']][] = $r;
        }
        $out = [];
        foreach ($skan['strefy'] as $s) {
            $s['trafienia'] = $trafienia[$s['kod']] ?? [];
            $out[]          = $s;
        }

        return $out;
    }

    /**
     * Szczegóły per IP (z filtrem i paginacją).
     *
     * @param array<string, mixed> $skan
     *
     * @return array{wiersze: list<array<string, mixed>>, razem: int, liczniki: array<string, int>}
     */
    public function szczegoly(array $skan, string $filtr, int $strona, int $naStrone = 50): array
    {
        $info = [];
        $url  = [];
        foreach ($skan['strefy'] as $s) {
            $info[$s['kod']] = (bool) ($s['informacyjna'] ?? false);
            $url[$s['kod']]  = (string) ($s['url'] ?? '');
        }

        $perIp = [];
        foreach ($this->db->table('mcs_dnsbl_wyniki')->select('ip, strefa_kod, status, kody, blad')
            ->where('skan_id', $skan['id'])->get()->getResultArray() as $r) {
            $perIp[$r['ip']][] = $r;
        }

        $cidry = array_values(array_filter(explode(',', (string) $skan['zakres'])));
        $ips   = ZakresSkanu::adresy($cidry);
        $wsz   = [];
        $liczniki = ['listed' => 0, 'clean' => 0, 'bledy' => 0, 'all' => count($ips)];
        foreach ($ips as $ip) {
            $listy  = [];
            $bledy  = [];
            $listed = false;
            foreach ($perIp[$ip] ?? [] as $r) {
                if ($r['status'] === 'listed') {
                    $listy[] = ['kod' => $r['strefa_kod'], 'kody' => $r['kody'], 'info' => $info[$r['strefa_kod']] ?? false, 'url' => DnsblNarzedzia::urlDelisting($url[$r['strefa_kod']] ?? '', $ip)];
                    if (! ($info[$r['strefa_kod']] ?? false)) {
                        $listed = true;
                    }
                } else {
                    $bledy[] = ['kod' => $r['strefa_kod'], 'blad' => $r['blad']];
                }
            }
            $status = $listed ? 'listed' : ($bledy !== [] ? 'bledy' : 'clean');
            $liczniki[$status]++;
            if ($filtr === 'all' || $filtr === $status) {
                $wsz[] = ['ip' => $ip, 'status' => $status, 'listy' => $listy, 'bledy' => $bledy];
            }
        }

        $razem  = count($wsz);
        $strona = max(1, $strona);

        return [
            'wiersze'  => array_slice($wsz, ($strona - 1) * $naStrone, $naStrone),
            'razem'    => $razem,
            'liczniki' => $liczniki,
        ];
    }

    /**
     * Aktywne listingi dla IP (karta /ip/{ip}), łącznie z /24 tego IP.
     *
     * @return list<array<string, mixed>>
     */
    public function listingiIp(string $ip): array
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return [];
        }
        $rows = $this->db->table('mcs_dnsbl_listingi l')
            ->select('l.ip, l.strefa_kod, l.kody, l.txt, l.pierwszy_raz, l.ostatni_raz, l.skan_id, s.nazwa, s.url_delisting, s.informacyjna')
            ->join('mcs_dnsbl_strefy s', 's.kod = l.strefa_kod', 'left')
            ->whereIn('l.ip', [$ip, DnsblNarzedzia::siec24($ip)])
            ->where('l.aktywny', true)
            ->orderBy('l.strefa_kod')->get()->getResultArray();
        foreach ($rows as &$r) {
            $r['informacyjna'] = in_array($r['informacyjna'], [true, 't', '1', 1], true);
            $r['url']          = DnsblNarzedzia::urlDelisting((string) ($r['url_delisting'] ?? ''), $ip);
        }

        return $rows;
    }

    /**
     * Ostatni zakończony skan obejmujący IP (do opisu karty).
     *
     * @return array<string, mixed>|null
     */
    public function ostatniSkanIp(string $ip): ?array
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }
        foreach ($this->db->table('mcs_dnsbl_skany')->select('id, zakres, koniec_at, strefa_filtr')
            ->where('status', 'zakonczony')->orderBy('id', 'DESC')->limit(50)->get()->getResultArray() as $r) {
            foreach (explode(',', (string) $r['zakres']) as $c) {
                if ($c !== '' && DnsblNarzedzia::cidrZawieraCidr($c, $ip)) {
                    return $r;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return array<string, mixed>
     */
    private function dekoduj(array $r): array
    {
        $s           = is_string($r['strefy_json'] ?? null) ? json_decode($r['strefy_json'], true) : null;
        $r['strefy'] = is_array($s) ? $s : [];
        $r['id']     = (int) $r['id'];

        return $r;
    }
}
