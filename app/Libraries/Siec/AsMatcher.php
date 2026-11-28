<?php

namespace App\Libraries\Siec;

use App\Models\UstawieniaModel;

/**
 * Matchowanie adresów do przestrzeni naszego AS („nasze IP”); ASN z opcji mcs.as.asn.
 *
 * Prefiksy i przełącznik z mcs_ustawienia; opcjonalny fallback .env:
 * mcs.as.cidry, mcs.as.wlaczone.
 */
final class AsMatcher
{
    /** ASN dokumentacyjny z RFC 5398 — przykład, nie numer operatora. */
    public const DOMYSLNY_ASN = 64496;

    public const KLUCZ_WLACZONE = 'as_wlaczone';

    public const KLUCZ_CIDRY = 'as_cidry';

    private const ASN_LISTY_RIPE = 198050;

    /** Prefiksy AS198050 (RIPE, stan 2026-09). */
    public const DOMYSLNE_CIDRY = [
        '5.253.230.0/24',
        '64.46.124.0/24',
        '64.190.43.0/24',
        '91.231.70.0/23',
        '91.231.80.0/22',
        '176.97.24.0/21',
        '194.169.138.0/24',
        '209.162.203.0/24',
        '212.47.33.0/24',
        '2a0f:1700::/29',
    ];

    private CidrMatcher $cidr;

    private ?UstawieniaModel $ustawienia;

    /** @var list<string>|null */
    private ?array $cacheCidry = null;

    private ?bool $cacheWlaczone = null;

    public function __construct(?CidrMatcher $cidr = null, ?UstawieniaModel $ustawienia = null)
    {
        $this->cidr        = $cidr ?? new CidrMatcher();
        $this->ustawienia  = $ustawienia;
    }

    /**
     * ASN globalny (opcja mcs.as.asn: DB → .env → DOMYSLNY_ASN).
     */
    public static function asn(): int
    {
        return \App\Libraries\OpcjeMcs::int('mcs.as.asn');
    }

    /** @return list<string> */
    public static function domyslneCidry(int $asn): array
    {
        return $asn === self::ASN_LISTY_RIPE ? self::DOMYSLNE_CIDRY : [];
    }

    public function jestWlaczone(): bool
    {
        if ($this->cacheWlaczone !== null) {
            return $this->cacheWlaczone;
        }

        $model = $this->ustawienia ?? $this->sprobujModel();
        if ($model !== null) {
            try {
                $raw = $model->pobierz(self::KLUCZ_WLACZONE);
                if ($raw !== null) {
                    return $this->cacheWlaczone = \App\Models\PluginModel::jakoBool($raw);
                }
            } catch (\Throwable) {
                // brak tabeli / DB — fallback env
            }
        }

        $env = env('mcs.as.wlaczone', env('mcs.as198050.wlaczone'));
        if ($env !== null && $env !== '') {
            return $this->cacheWlaczone = filter_var($env, FILTER_VALIDATE_BOOLEAN);
        }

        return $this->cacheWlaczone = true;
    }

    /**
     * @return list<string>
     */
    public function cidry(): array
    {
        if ($this->cacheCidry !== null) {
            return $this->cacheCidry;
        }

        $asn = self::asn();
        $model = $this->ustawienia ?? $this->sprobujModel();
        if ($model !== null) {
            try {
                $raw = $model->pobierz(self::KLUCZ_CIDRY);
                if ($raw === null && $asn === self::ASN_LISTY_RIPE) {
                    $raw = $model->pobierz('as198050_cidry');
                }
                if ($raw !== null) {
                    return $this->cacheCidry = $this->cidr->parsujListe($raw);
                }
            } catch (\Throwable) {
                // fallback poniżej
            }
        }

        $envRaw = env('mcs.as.cidry');
        if ($envRaw === null && $asn === self::ASN_LISTY_RIPE) {
            $envRaw = env('mcs.as198050.cidry');
        }
        if (trim((string) $envRaw) !== '') {
            return $this->cacheCidry = $this->cidr->parsujListe((string) $envRaw);
        }

        return $this->cacheCidry = self::domyslneCidry($asn);
    }

    /**
     * Prefiksy IPv4 AS (do selecta Podsieć). IPv6 pomijane — ręczne pole CIDR.
     *
     * @return list<string>
     */
    public function cidryIpv4(): array
    {
        $out = [];
        foreach ($this->cidry() as $cidr) {
            $siec = str_contains($cidr, '/') ? explode('/', $cidr, 2)[0] : $cidr;
            if (filter_var($siec, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                $out[] = $cidr;
            }
        }

        return $out;
    }

    /**
     * Czy IP należy do skonfigurowanej przestrzeni naszego AS.
     * Gdy matchowanie wyłączone — zawsze false (nie oznaczamy jako „nasze”).
     */
    public function czyNaszeIp(string $ip): bool
    {
        if (! $this->jestWlaczone()) {
            return false;
        }

        return $this->cidr->ipWLiscie($ip, $this->cidry());
    }

    /**
     * Callback dla list dashboardu: AS (gdy włączone) oraz opcjonalny CIDR.
     *
     * @return (callable(string): bool)|null
     */
    public function filtrAkceptujIp(?string $cidr = null): ?callable
    {
        $cidr = $cidr !== null ? trim($cidr) : '';
        $cidr = $cidr !== '' ? $cidr : null;
        $asOn = $this->jestWlaczone();
        if (! $asOn && $cidr === null) {
            return null;
        }

        return function (string $adres) use ($asOn, $cidr): bool {
            if ($asOn && ! $this->czyNaszeIp($adres)) {
                return false;
            }
            if ($cidr !== null && ! $this->cidr->ipWCidr($adres, $cidr)) {
                return false;
            }

            return true;
        };
    }

    public function cidrMatcher(): CidrMatcher
    {
        return $this->cidr;
    }

    /**
     * Tekst CIDR do formularza (jeden na linię).
     */
    public function cidryJakoTekst(): string
    {
        return implode("\n", $this->cidry());
    }

    public function wyczyscCache(): void
    {
        $this->cacheCidry    = null;
        $this->cacheWlaczone = null;
    }

    private function sprobujModel(): ?UstawieniaModel
    {
        try {
            return model(UstawieniaModel::class);
        } catch (\Throwable) {
            return null;
        }
    }
}
