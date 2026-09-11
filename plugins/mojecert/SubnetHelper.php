<?php

namespace McsPluginy\mojecert;

/**
 * Normalizacja pola subnet z odpowiedzi API moje.cert.pl (port z LMSMojeCertPlugin).
 */
class SubnetHelper
{
    /**
     * @param list<string> $keyOrder
     */
    public static function resolveByKeys(mixed $v, array $keyOrder): ?string
    {
        if ($v === null) {
            return null;
        }
        if (is_string($v)) {
            $t = trim($v);

            return $t !== '' ? $t : null;
        }
        if (is_int($v) || is_float($v)) {
            return trim((string) $v);
        }
        if (! is_array($v)) {
            return null;
        }
        foreach ($keyOrder as $k) {
            if (array_key_exists($k, $v)) {
                $inner = self::resolveByKeys($v[$k], $keyOrder);
                if ($inner !== null) {
                    return $inner;
                }
            }
        }
        if (isset($v[0])) {
            return self::resolveByKeys($v[0], $keyOrder);
        }

        return null;
    }

    /** @return list<string> */
    public static function apiKeyOrder(): array
    {
        return ['slug', 'subnet', 'id', 'cidr', 'address', 'value'];
    }

    /** @return list<string> */
    public static function storageKeyOrder(): array
    {
        return ['cidr', 'subnet', 'address', 'value', 'slug', 'id'];
    }

    public static function apiPathFromSn(array $sn): ?string
    {
        if (! array_key_exists('subnet', $sn)) {
            return null;
        }

        return self::resolveByKeys($sn['subnet'], self::apiKeyOrder());
    }

    public static function storageLabelFromSn(array $sn): ?string
    {
        if (! array_key_exists('subnet', $sn)) {
            return null;
        }

        return self::resolveByKeys($sn['subnet'], self::storageKeyOrder());
    }
}
