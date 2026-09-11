<?php

namespace McsPluginy\play;

/**
 * Parser powiadomień DDoS Play (treść maila, nie CSV).
 *
 * Format obserwowany na skrzynce play-antyddos@ (temat + body):
 *   Subject: DDoS notification - {Message type}: {Policy} - {TID} - {Event} [- {Mitigation}]
 *   Body: Message type / Event / Event Status / Start / End / Policy / Mitigation …
 *
 * Wcześniejsze docs zakładały „Play scrubbing” CSV — rzeczywisty strumień to alerty Anty-DDoS.
 */
final class Parser
{
    public const FROM_PLAY = 'antyddos@play.pl';

    /**
     * @return list<array{
     *   message_type: string,
     *   event_id: ?string,
     *   event_status: ?string,
     *   policy: ?string,
     *   tid: ?string,
     *   mitigation_id: ?string,
     *   start: ?string,
     *   end: ?string,
     *   ip: ?string,
     *   cidr: ?string,
     *   podmiot_typ: string,
     *   podmiot: string,
     *   klucz_dedupu: string,
     *   timestamp: string,
     *   raw: array<string, mixed>
     * }>
     */
    public function parsujPowiadomienie(string $subject, string $tresc, ?string $dateNaglowka = null): array
    {
        if (! $this->toPowiadomienieDdos($subject, $tresc)) {
            return [];
        }

        $pola = $this->wyciagnijPola($tresc);
        $zTematu = $this->parsujTemat($subject);

        $messageType = $pola['message_type']
            ?? $zTematu['message_type']
            ?? 'DDoS notification';
        $eventId = $pola['event'] ?? $zTematu['event_id'] ?? null;
        $policy  = $pola['policy'] ?? $zTematu['policy'] ?? null;
        $tid     = $pola['tid'] ?? $zTematu['tid'] ?? null;
        $mitId   = $pola['mitigation'] ?? $zTematu['mitigation_id'] ?? null;
        $status  = $pola['event_status'] ?? null;
        $start   = $pola['start'] ?? null;
        $end     = $pola['end'] ?? null;

        $timestamp = $this->wybierzCzas($start, $dateNaglowka);
        $cele      = $this->wybierzPodmioty($tresc, $policy, $tid, $eventId);

        $wynik = [];
        foreach ($cele as $cel) {
            $klucz = implode('|', array_filter([
                'ddos',
                $messageType,
                $eventId,
                $mitId,
                $cel['podmiot_typ'],
                $cel['podmiot'],
            ], static fn ($v) => $v !== null && $v !== ''));

            $wynik[] = [
                'message_type'  => $messageType,
                'event_id'      => $eventId,
                'event_status'  => $status,
                'policy'        => $policy,
                'tid'           => $tid,
                'mitigation_id' => $mitId,
                'start'         => $start,
                'end'           => $end,
                'ip'            => $cel['ip'],
                'cidr'          => $cel['cidr'],
                'podmiot_typ'   => $cel['podmiot_typ'],
                'podmiot'       => $cel['podmiot'],
                'klucz_dedupu'  => $klucz,
                'timestamp'     => $timestamp,
                'raw'           => [
                    'subject' => $subject,
                    'fields'  => $pola,
                    'topic'   => $zTematu,
                ],
            ];
        }

        return $wynik;
    }

    public function toPowiadomienieDdos(string $subject, string $tresc): bool
    {
        if (preg_match('/DDoS\s+notification/i', $subject) === 1) {
            return true;
        }

        return preg_match('/Message\s+type\s+(Event|Mitigation|CIDR)/i', $tresc) === 1;
    }

    public function kategoriaDlaWiersza(array $w): string
    {
        $typ = strtolower((string) ($w['message_type'] ?? ''));
        if (str_contains($typ, 'mitigation')) {
            return 'abuse';
        }
        if (str_contains($typ, 'event')) {
            return 'abuse';
        }
        if (str_contains($typ, 'cidr')) {
            return 'other';
        }

        return 'abuse';
    }

    /**
     * @return array{message_type: ?string, policy: ?string, tid: ?string, event_id: ?string, mitigation_id: ?string}
     */
    private function parsujTemat(string $subject): array
    {
        // DDoS notification - Event finished: POLICY - TID - EVENT [- MIT]
        if (preg_match(
            '/DDoS\s+notification\s*-\s*([^:]+):\s*([^-]+)\s*-\s*([^-]+)\s*-\s*(\d+)(?:\s*-\s*(\d+))?/i',
            $subject,
            $m,
        ) === 1) {
            return [
                'message_type'   => trim($m[1]),
                'policy'         => trim($m[2]),
                'tid'            => trim($m[3]),
                'event_id'       => trim($m[4]),
                'mitigation_id'  => isset($m[5]) ? trim($m[5]) : null,
            ];
        }

        return [
            'message_type'  => null,
            'policy'        => null,
            'tid'           => null,
            'event_id'      => null,
            'mitigation_id' => null,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function wyciagnijPola(string $tresc): array
    {
        $mapa = [
            'message_type'  => 'Message type',
            'event'         => 'Event',
            'event_status'  => 'Event Status',
            'start'         => 'Start',
            'end'           => 'End',
            'policy'        => 'Policy',
            'mitigation'    => 'Mitigation',
            'mitigation_source' => 'Mitigation Source',
            'dimension'     => 'Dimension',
        ];

        $out = [];
        $norm = preg_replace("/\r\n|\r/", "\n", $tresc) ?? $tresc;

        foreach ($mapa as $klucz => $etykieta) {
            $quoted = preg_quote($etykieta, '/');
            // Linia: "Message type: Event finished" albo "Message type Event finished"
            if (preg_match('/(?:^|\n)\s*' . $quoted . '\s*:?\s*([^\n]+)/i', $norm, $m) === 1) {
                $val = trim($m[1]);
                // FreeScout/snippet często skleja pola w jedną linię — obetnij przy następnym znanym kluczu
                $val = $this->obetnijPrzyNastepnymPolu($val, array_values($mapa));
                if ($val !== '' && strcasecmp($val, '-') !== 0) {
                    $out[$klucz] = $val;
                }
            }
        }

        // TID z treści lub z wartości Dimension / Customer…
        if (preg_match('/\b(TID\d+[_\w-]*)\b/i', $norm, $m) === 1) {
            $out['tid'] = $m[1];
        }

        return $out;
    }

    /**
     * @param list<string> $etykiety
     */
    private function obetnijPrzyNastepnymPolu(string $val, array $etykiety): string
    {
        $pozycje = [];
        foreach ($etykiety as $e) {
            if (preg_match('/\b' . preg_quote($e, '/') . '\b/i', $val, $m, PREG_OFFSET_CAPTURE) === 1
                && ($m[0][1] ?? 0) > 0
            ) {
                $pozycje[] = (int) $m[0][1];
            }
        }
        if ($pozycje === []) {
            return $val;
        }

        return trim(substr($val, 0, min($pozycje)));
    }

    /**
     * @return list<array{podmiot_typ: string, podmiot: string, ip: ?string, cidr: ?string}>
     */
    private function wybierzPodmioty(string $tresc, ?string $policy, ?string $tid, ?string $eventId): array
    {
        $cele = [];
        $seen = [];

        if (preg_match_all(
            '/\b((?:\d{1,3}\.){3}\d{1,3}\/\d{1,2}|[0-9a-f:]+\/\d{1,3})\b/i',
            $tresc,
            $cidry,
        ) > 0) {
            foreach ($cidry[1] as $cidr) {
                $k = 'cidr:' . strtolower($cidr);
                if (isset($seen[$k])) {
                    continue;
                }
                $seen[$k] = true;
                $cele[]   = [
                    'podmiot_typ' => 'cidr',
                    'podmiot'     => $cidr,
                    'ip'          => null,
                    'cidr'        => $cidr,
                ];
            }
        }

        if (preg_match_all('/\b((?:\d{1,3}\.){3}\d{1,3})\b/', $tresc, $ipy) > 0) {
            foreach ($ipy[1] as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                    continue;
                }
                // odrzuć oczywiste fragmenty dat/czasów już obsłużone jako CIDR network
                $k = 'ip:' . $ip;
                if (isset($seen[$k])) {
                    continue;
                }
                // pomiń IP będące częścią już znalezionego CIDR
                $wCidr = false;
                foreach ($cele as $c) {
                    if (($c['cidr'] ?? '') !== '' && str_starts_with($c['cidr'], $ip . '/')) {
                        $wCidr = true;
                        break;
                    }
                }
                if ($wCidr) {
                    continue;
                }
                $seen[$k] = true;
                $cele[]   = [
                    'podmiot_typ' => 'ip',
                    'podmiot'     => $ip,
                    'ip'          => $ip,
                    'cidr'        => null,
                ];
            }
        }

        if ($cele !== []) {
            return $cele;
        }

        $podmiot = $policy ?: ($tid ?: ($eventId ? 'event:' . $eventId : 'play-ddos'));

        return [[
            'podmiot_typ' => 'siec',
            'podmiot'     => $podmiot,
            'ip'          => null,
            'cidr'        => null,
        ]];
    }

    private function wybierzCzas(?string $start, ?string $dateNaglowka): string
    {
        foreach ([$start, $dateNaglowka] as $kandydat) {
            if ($kandydat === null || trim($kandydat) === '' || $kandydat === '-') {
                continue;
            }
            try {
                $dt = new \DateTimeImmutable($kandydat);

                return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
            } catch (\Exception) {
                // próbuj dalej
            }
        }

        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:sP');
    }
}
