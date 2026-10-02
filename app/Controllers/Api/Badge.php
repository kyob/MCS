<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\IncydentModel;

/**
 * Agregat badge 7 dni dla LMS (token serwerowy — filtr mcsBadgeToken).
 *
 * Pojedyncze IP: GET ?ip=
 * Batch: GET ?ips=ip1,ip2  albo POST JSON {"ips":["…"]}.
 */
class Badge extends BaseController
{
    /** Limit adresów w jednym żądaniu zbiorczym. */
    public const MAX_IPS_BATCH = 100;

    /**
     * Limit IP w batchu (opcja mcs.badge.maxIpsBatch; domyślnie MAX_IPS_BATCH).
     */
    public static function maxIpsBatch(): int
    {
        return max(1, \App\Libraries\OpcjeMcs::int('mcs.badge.maxIpsBatch'));
    }

    public function index()
    {
        $ips = $this->parsujListeIp();
        if ($ips instanceof \CodeIgniter\HTTP\ResponseInterface) {
            return $ips;
        }

        if (count($ips) === 1 && $this->trybPojedynczy()) {
            return $this->odpowiedzPojedyncza($ips[0]);
        }

        return $this->odpowiedzBatch($ips);
    }

    /**
     * Czy klient prosi o kontrakt single-IP (parametr `ip`, bez `ips`).
     */
    private function trybPojedynczy(): bool
    {
        $ipsRaw = $this->request->getGet('ips');
        if ($ipsRaw !== null && $ipsRaw !== '') {
            return false;
        }

        if (strtoupper($this->request->getMethod()) === 'POST') {
            $json = $this->request->getJSON(true);
            if (is_array($json) && array_key_exists('ips', $json)) {
                return false;
            }
            if ($this->request->getPost('ips') !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>|\CodeIgniter\HTTP\ResponseInterface
     */
    private function parsujListeIp()
    {
        $surowa = $this->pobierzSurowaListeIp();
        if ($surowa instanceof \CodeIgniter\HTTP\ResponseInterface) {
            return $surowa;
        }

        $lista = [];
        foreach ($surowa as $kandydat) {
            if (! is_string($kandydat) && ! is_int($kandydat) && ! is_float($kandydat)) {
                return $this->response->setStatusCode(400)->setJSON([
                    'blad' => 'Każdy element ips musi być adresem IP (string).',
                ]);
            }
            $ip = trim((string) $kandydat);
            if ($ip === '') {
                continue;
            }
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                return $this->response->setStatusCode(400)->setJSON([
                    'blad' => 'Nieprawidłowy adres IP w żądaniu: ' . $ip,
                ]);
            }
            if (! in_array($ip, $lista, true)) {
                $lista[] = $ip;
            }
        }

        if ($lista === []) {
            return $this->response->setStatusCode(400)->setJSON([
                'blad' => 'Wymagany prawidłowy parametr ip albo ips (1–'
                    . self::maxIpsBatch() . ' adresów).',
            ]);
        }

        if (count($lista) > self::maxIpsBatch()) {
            return $this->response->setStatusCode(400)->setJSON([
                'blad' => 'Maksymalnie ' . self::maxIpsBatch() . ' adresów IP w jednym żądaniu.',
            ]);
        }

        return $lista;
    }

    /**
     * @return list<mixed>|\CodeIgniter\HTTP\ResponseInterface
     */
    private function pobierzSurowaListeIp()
    {
        $ipsGet = $this->request->getGet('ips');
        if ($ipsGet !== null && $ipsGet !== '') {
            if (is_array($ipsGet)) {
                return array_values($ipsGet);
            }

            return preg_split('/\s*,\s*/', trim((string) $ipsGet), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        if (strtoupper($this->request->getMethod()) === 'POST') {
            $json = $this->request->getJSON(true);
            if (is_array($json) && array_key_exists('ips', $json)) {
                if (! is_array($json['ips'])) {
                    return $this->response->setStatusCode(400)->setJSON([
                        'blad' => 'Pole ips musi być tablicą adresów IP.',
                    ]);
                }

                return array_values($json['ips']);
            }

            $ipsPost = $this->request->getPost('ips');
            if (is_array($ipsPost)) {
                return array_values($ipsPost);
            }
            if (is_string($ipsPost) && $ipsPost !== '') {
                return preg_split('/\s*,\s*/', trim($ipsPost), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            }
        }

        $ip = $this->request->getGet('ip');
        if ($ip === null || $ip === '') {
            $ip = $this->request->getPost('ip');
        }
        if (is_string($ip) && trim($ip) !== '') {
            return [trim($ip)];
        }

        return $this->response->setStatusCode(400)->setJSON([
            'blad' => 'Wymagany prawidłowy parametr ip albo ips.',
        ]);
    }

    private function odpowiedzPojedyncza(string $ip)
    {
        $agregat = model(IncydentModel::class)->agregatBadgeDlaIp($ip);

        return $this->response->setJSON($this->wierszBadge($ip, $agregat));
    }

    /**
     * @param list<string> $ips
     */
    private function odpowiedzBatch(array $ips)
    {
        $mapa = model(IncydentModel::class)->agregatBadgeDlaIpLista($ips);
        $okno = 7;
        $wyniki = [];

        foreach ($ips as $ip) {
            $agregat = $mapa[$ip] ?? [
                'okno_dni'      => $okno,
                'liczba'        => 0,
                'max_waga'      => null,
                'liczba_zrodel' => 0,
                'zrodla'        => [],
                'kategorie'     => [],
                'przyklady'     => [],
            ];
            $okno     = $agregat['okno_dni'];
            $wyniki[] = $this->wierszBadge($ip, $agregat);
        }

        return $this->response->setJSON([
            'okno_dni'  => $okno,
            'liczba_ip' => count($wyniki),
            'wyniki'    => $wyniki,
        ]);
    }

    /**
     * @param array{
     *   okno_dni: int,
     *   liczba: int,
     *   max_waga: ?string,
     *   liczba_zrodel: int,
     *   zrodla?: list<string>,
     *   kategorie?: list<string>,
     *   przyklady?: list<string>
     * } $agregat
     * @return array{
     *   ip: string,
     *   okno_dni: int,
     *   liczba: int,
     *   max_waga: ?string,
     *   liczba_zrodel: int,
     *   zrodla: list<string>,
     *   kategorie: list<string>,
     *   przyklady: list<string>,
     *   url: string
     * }
     */
    private function wierszBadge(string $ip, array $agregat): array
    {
        $baza = rtrim((string) config('App')->baseURL, '/');

        return [
            'ip'            => $ip,
            'okno_dni'      => $agregat['okno_dni'],
            'liczba'        => $agregat['liczba'],
            'max_waga'      => $agregat['max_waga'],
            'liczba_zrodel' => $agregat['liczba_zrodel'],
            'zrodla'        => array_values($agregat['zrodla'] ?? []),
            'kategorie'     => array_values($agregat['kategorie'] ?? []),
            'przyklady'     => array_values($agregat['przyklady'] ?? []),
            'url'           => $baza . '/ip/' . rawurlencode($ip),
        ];
    }
}
