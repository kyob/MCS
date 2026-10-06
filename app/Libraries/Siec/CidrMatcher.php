<?php

namespace App\Libraries\Siec;

/**
 * Match IP ∈ CIDR (IPv4/IPv6) bez zewnętrznych bibliotek.
 * Współdzielona logika (dashboard / ustawienia AS / ewentualnie importery).
 */
final class CidrMatcher
{
    /**
     * @param list<string> $cidry
     */
    public function ipWLiscie(string $ip, array $cidry): bool
    {
        $ip = trim($ip);
        if ($ip === '' || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        foreach ($cidry as $cidr) {
            $cidr = trim((string) $cidr);
            if ($cidr === '') {
                continue;
            }
            if ($this->ipWCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    public function ipWCidr(string $ip, string $cidr): bool
    {
        $ip   = trim($ip);
        $cidr = trim($cidr);
        if ($ip === '' || $cidr === '') {
            return false;
        }

        if (! str_contains($cidr, '/')) {
            return $ip === $cidr;
        }

        [$subnet, $mask] = explode('/', $cidr, 2);
        $subnet          = trim($subnet);
        $mask            = trim($mask);

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            && filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
        ) {
            $mask = (int) $mask;
            if ($mask < 0 || $mask > 32) {
                return false;
            }
            $ipLong     = ip2long($ip);
            $subnetLong = ip2long($subnet);
            if ($ipLong === false || $subnetLong === false) {
                return false;
            }
            $maskLong = $mask === 0 ? 0 : (~((1 << (32 - $mask)) - 1) & 0xFFFFFFFF);

            return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
            && filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
        ) {
            $mask = (int) $mask;
            if ($mask < 0 || $mask > 128) {
                return false;
            }
            $ipBin     = inet_pton($ip);
            $subnetBin = inet_pton($subnet);
            if ($ipBin === false || $subnetBin === false) {
                return false;
            }
            $bytes    = intdiv($mask, 8);
            $bitsLeft = $mask % 8;
            if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
                return false;
            }
            if ($bitsLeft === 0) {
                return true;
            }
            $maskByte = (~((1 << (8 - $bitsLeft)) - 1)) & 0xFF;

            return (ord($ipBin[$bytes]) & $maskByte) === (ord($subnetBin[$bytes]) & $maskByte);
        }

        return false;
    }

    /**
     * Parsuje listę CIDR z tekstu (przecinek, spacja, średnik, nowa linia).
     *
     * @return list<string>
     */
    public function parsujListe(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $parts = preg_split('/[\s,;]+/', $raw) ?: [];
        $out   = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '') {
                $out[] = $p;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Waliduje pojedynczy wpis: IP albo IP/mask.
     */
    public function czyPoprawnyCidr(string $cidr): bool
    {
        $cidr = trim($cidr);
        if ($cidr === '') {
            return false;
        }
        if (! str_contains($cidr, '/')) {
            return filter_var($cidr, FILTER_VALIDATE_IP) !== false;
        }
        [$subnet, $mask] = explode('/', $cidr, 2);
        $subnet          = trim($subnet);
        if (! ctype_digit(trim($mask))) {
            return false;
        }
        $mask = (int) $mask;
        if (filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $mask >= 0 && $mask <= 32;
        }
        if (filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $mask >= 0 && $mask <= 128;
        }

        return false;
    }

    /**
     * @param list<string> $cidry
     *
     * @return list<string> niepoprawne wpisy
     */
    public function niepoprawne(array $cidry): array
    {
        $zle = [];
        foreach ($cidry as $c) {
            if (! $this->czyPoprawnyCidr($c)) {
                $zle[] = $c;
            }
        }

        return $zle;
    }

    /**
     * Enumerator IPv4 w CIDR (włącznie z adresem sieci i broadcast).
     * Odrzuca maski szersze niż /16 (bezpieczeństwo skanów).
     *
     * @return \Generator<int, string>
     */
    public function enumerujIpv4(string $cidr): \Generator
    {
        $cidr = trim($cidr);
        if ($cidr === '' || ! $this->czyPoprawnyCidr($cidr)) {
            return;
        }

        if (! str_contains($cidr, '/')) {
            if (filter_var($cidr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                yield $cidr;
            }

            return;
        }

        [$subnet, $mask] = explode('/', $cidr, 2);
        $subnet          = trim($subnet);
        $mask            = (int) trim($mask);

        if (! filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return;
        }
        if ($mask < 16 || $mask > 32) {
            return;
        }

        $subnetLong = ip2long($subnet);
        if ($subnetLong === false) {
            return;
        }
        $subnetLong = $subnetLong & 0xFFFFFFFF;
        $hostBits   = 32 - $mask;
        $count      = 1 << $hostBits;
        $maskLong   = $mask === 0 ? 0 : (~((1 << $hostBits) - 1) & 0xFFFFFFFF);
        $base       = $subnetLong & $maskLong;

        for ($i = 0; $i < $count; $i++) {
            yield long2ip($base + $i);
        }
    }
}
