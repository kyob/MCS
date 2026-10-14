<?php

namespace App\Libraries;

/**
 * Czytelne etykiety źródeł / kategorii w UI MCS (dashboard, karty IP).
 */
class EtykietyUi
{
    /** @var array<string, string> */
    private const ZRODLA = [
        'cert_n6'            => 'N6',
        'shadowserver'       => 'Shadowserver',
        'play'               => 'Play Scrubbing',
        'snds'               => 'SNDS',
        'abuseipdb'          => 'AbuseIPDB',
        'mojecert'           => 'MojeCERT',
        'wanguard'           => 'Wanguard',
        'cloudflare_botnet'  => 'Cloudflare Botnet',
        'qrator'             => 'Qrator',
        'lms_mapowanie'      => 'LMS',
        'ismalicious'        => 'isMalicious',
        'spamrats'           => 'SpamRats',
        'dnsbl'              => 'DNSBL',
        'spoofer'            => 'CAIDA Spoofer',
        'speedtest'          => 'Speedtest',
        'abusix'             => 'Abusix',
        'spamhaus'           => 'Spamhaus DROP',
    ];

    /** @var array<string, string> */
    private const KATEGORIE = [
        'malware'       => 'malware',
        'amplification' => 'amplifikacja',
        'vulnerability' => 'podatność',
        'abuse'         => 'abuse',
        'reputation'    => 'reputacja',
        'exposure'      => 'ekspozycja',
        'other'         => 'inne',
        'spam'          => 'spam',
        'hijack'        => 'hijack (DROP)',
    ];

    public function zrodlo(string $kod): string
    {
        $kod = trim($kod);

        return self::ZRODLA[$kod] ?? ($kod !== '' ? $kod : '—');
    }

    /**
     * @param list<string> $kody
     *
     * @return list<string>
     */
    public function zrodla(array $kody): array
    {
        $out = [];
        foreach ($kody as $kod) {
            if (! is_string($kod) && ! is_int($kod)) {
                continue;
            }
            $s = trim((string) $kod);
            if ($s === '') {
                continue;
            }
            $out[] = $this->zrodlo($s);
        }

        return $out;
    }

    public function kategoria(string $kod): string
    {
        $kod = strtolower(trim($kod));
        if ($kod === '') {
            return '—';
        }

        return self::KATEGORIE[$kod] ?? $kod;
    }

    /**
     * Kategorie do chipów — pomija „other”, limit jak w badge LMS.
     *
     * @param list<string> $kategorie
     *
     * @return list<string>
     */
    public function kategorieChip(array $kategorie, int $limit = 2): array
    {
        $out = [];
        foreach ($kategorie as $kat) {
            if (! is_string($kat) && ! is_int($kat)) {
                continue;
            }
            $s = trim((string) $kat);
            if ($s === '' || strcasecmp($s, 'other') === 0) {
                continue;
            }
            $out[] = $this->kategoria($s);
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }
}
