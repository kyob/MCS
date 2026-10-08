<?php

namespace McsPluginy\abusix;

use App\Libraries\ImportKoperta;
use App\Libraries\IncydentZapis;
use App\Libraries\PluginOpisPolaczenia;
use App\Libraries\SourcePluginInterface;
use App\Libraries\WagaV1;
use App\Models\PluginModel;
use Config\Database;
use RuntimeException;

/**
 * Plugin Abusix (Guardian Ops API).
 *
 * Przebieg: klienci (1 strona), sprawy (do maxPages stron), zdarzenia (do maxPages stron).
 * Domyślnie 1 + 2 + 2 = max 5 zapytań/przebieg. 429 lub błąd przerywa przebieg (bez ponowień);
 * Retry-After zapisywany w writable/abusix_backoff i respektowany przez kolejne przebiegi.
 *
 * Zdarzenia z poprawnym IP -> incydent (klucz_dedupu = event:{event_id}); sprawy i klienci -> tylko tabele.
 */
class Plugin implements SourcePluginInterface
{
    private ImportKoperta $koperta;
    private IncydentZapis $zapis;
    private WagaV1 $waga;
    private ?ApiClient $apiClient;

    public function __construct(
        ?ImportKoperta $koperta = null,
        ?ApiClient $apiClient = null,
        ?IncydentZapis $zapis = null,
        ?WagaV1 $waga = null,
    ) {
        $this->koperta   = $koperta ?? new ImportKoperta();
        $this->apiClient = $apiClient;
        $this->zapis     = $zapis ?? new IncydentZapis();
        $this->waga      = $waga ?? new WagaV1();
    }

    public function zrodlo(): string
    {
        return 'abusix';
    }

    public static function plikBackoff(): string
    {
        return WRITEPATH . 'abusix_backoff';
    }

    public function pobierz(): void
    {
        $client = $this->apiClient ?? ApiClient::fromEnv();
        if (! $client->hasAuth()) {
            $msg = 'Abusix: ustaw mcs.abusix.apiKey w .env';
            $this->aktualizujHealth(false, $msg);
            throw new RuntimeException($msg);
        }

        $do = is_file(self::plikBackoff()) ? (int) trim((string) file_get_contents(self::plikBackoff())) : 0;
        if ($do > time()) {
            $this->aktualizujHealth(false, 'Abusix: limit zapytań (429), pauza do ' . date('Y-m-d H:i:s', $do));

            return;
        }

        $importId = $this->koperta->rozpocznij($this->zrodlo(), null, null, ['opis' => 'Abusix Guardian Ops: klienci, sprawy, zdarzenia']);
        if ($importId === null) {
            $this->aktualizujHealth(true, 'pominięto (duplikat przebiegu)');

            return;
        }

        $limit    = max(1, min(500, (int) env('mcs.abusix.pageLimit', 100)));
        $maxPages = max(1, min(5, (int) env('mcs.abusix.maxPages', 2)));
        $db       = Database::connect();
        $stat     = ['zapytan' => 0, 'klienci' => 0, 'sprawy' => 0, 'zdarzenia' => 0, 'incydenty' => 0];

        try {
            $this->zbierz($client, 'listCustomers', 1, $limit, $stat, function (array $items) use ($db, &$stat): void {
                $stat['klienci'] += $this->zapiszKlientow($db, $items);
            });
            $this->zbierz($client, 'listCases', $maxPages, $limit, $stat, function (array $items) use ($db, $importId, &$stat): void {
                $stat['sprawy'] += $this->zapiszSprawy($db, $importId, $items);
            });
            $this->zbierz($client, 'listEvents', $maxPages, $limit, $stat, function (array $items) use ($db, $importId, &$stat): void {
                [$z, $i] = $this->zapiszZdarzenia($db, $importId, $items);
                $stat['zdarzenia'] += $z;
                $stat['incydenty'] += $i;
            });
        } catch (RateLimitException $e) {
            @file_put_contents(self::plikBackoff(), (string) (time() + $e->retryAfter));
            $msg = 'Abusix API 429, pauza ' . $e->retryAfter . ' s (zapisano: ' . $this->opisStat($stat) . ')';
            $this->koperta->zakonczBlad($importId, $msg);
            $this->aktualizujHealth(false, $msg);

            return;
        } catch (\Throwable $e) {
            $this->koperta->zakonczBlad($importId, $e->getMessage());
            $this->aktualizujHealth(false, $e->getMessage());
            throw $e;
        }

        @unlink(self::plikBackoff());
        $this->koperta->zakonczOk($importId, $stat);
        $this->aktualizujHealth(true, 'Abusix OK (' . $this->opisStat($stat) . ')');
    }

    /**
     * @param array<string,int> $stat
     * @param callable(list<array<string,mixed>>): void $zapis
     */
    private function zbierz(ApiClient $client, string $metoda, int $maxPages, int $limit, array &$stat, callable $zapis): void
    {
        $cursor = null;
        for ($strona = 0; $strona < $maxPages; $strona++) {
            $res = $client->{$metoda}($limit, $cursor);
            $stat['zapytan']++;
            if ($res['rate_limited']) {
                throw new RateLimitException($res['retry_after']);
            }
            if (! $res['ok']) {
                throw new RuntimeException('Abusix API ' . $metoda . ': ' . $res['error']);
            }
            $items = array_values(array_filter((array) ($res['data']['items'] ?? []), 'is_array'));
            $zapis($items);
            $cursor = isset($res['data']['next_cursor']) && is_string($res['data']['next_cursor']) && $res['data']['next_cursor'] !== ''
                ? $res['data']['next_cursor'] : null;
            if ($cursor === null) {
                break;
            }
        }
    }

    /**
     * @param array<string,int> $stat
     */
    private function opisStat(array $stat): string
    {
        return sprintf(
            'zapytań=%d, klienci=%d, sprawy=%d, zdarzenia=%d, incydenty=%d',
            $stat['zapytan'],
            $stat['klienci'],
            $stat['sprawy'],
            $stat['zdarzenia'],
            $stat['incydenty'],
        );
    }

    /**
     * Mapowanie zdarzenia z API na wiersz tabeli; null gdy brak event_id/event_date.
     *
     * @param array<string,mixed> $e
     *
     * @return array<string,mixed>|null
     */
    public static function mapujZdarzenie(array $e): ?array
    {
        $id   = trim((string) ($e['event_id'] ?? ''));
        $date = trim((string) ($e['event_date'] ?? ''));
        if ($id === '' || $date === '' || strtotime($date) === false) {
            return null;
        }
        $ip = trim((string) ($e['ip'] ?? ''));

        return [
            'event_id'         => $id,
            'event_type'       => mb_substr(trim((string) ($e['event_type'] ?? '')), 0, 128),
            'zaobserwowano_at' => gmdate('Y-m-d H:i:sP', (int) strtotime($date)),
            'ip'               => filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '',
            'domain'           => mb_substr(trim((string) ($e['domain'] ?? '')), 0, 255),
            'sender_email'     => mb_substr(trim((string) ($e['sender_email'] ?? '')), 0, 255),
            'klucz_dedupu'     => 'event:' . $id,
        ];
    }

    /**
     * @param list<array<string,mixed>> $items
     */
    private function zapiszKlientow($db, array $items): int
    {
        $n = 0;
        foreach ($items as $c) {
            $cid = trim((string) ($c['customer_id'] ?? ''));
            if ($cid === '') {
                continue;
            }
            $tid  = trim((string) ($c['tenant_id'] ?? ''));
            $teraz = gmdate('Y-m-d H:i:sP');
            $jest = $db->table('mcs_abusix_klienci')->where('customer_id', $cid)->where('tenant_id', $tid)->countAllResults();
            if ($jest > 0) {
                $db->table('mcs_abusix_klienci')->where('customer_id', $cid)->where('tenant_id', $tid)->update(['widziano_at' => $teraz]);
            } else {
                $db->table('mcs_abusix_klienci')->insert(['customer_id' => $cid, 'tenant_id' => $tid, 'widziano_at' => $teraz]);
            }
            $n++;
        }

        return $n;
    }

    /**
     * @param list<array<string,mixed>> $items
     */
    private function zapiszSprawy($db, int $importId, array $items): int
    {
        $n = 0;
        foreach ($items as $c) {
            $id = trim((string) ($c['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $ts  = static function ($v): ?string {
                $v = trim((string) $v);

                return $v !== '' && strtotime($v) !== false ? gmdate('Y-m-d H:i:sP', (int) strtotime($v)) : null;
            };
            $row = [
                'customer_id'       => mb_substr(trim((string) ($c['customer_id'] ?? '')), 0, 128),
                'tenant_id'         => mb_substr(trim((string) ($c['tenant_id'] ?? '')), 0, 128),
                'contract_id'       => mb_substr(trim((string) ($c['contract_id'] ?? '')), 0, 128),
                'state'             => mb_substr(trim((string) ($c['state'] ?? '')), 0, 32),
                'created_at'        => $ts($c['created_at'] ?? ''),
                'first_event_date'  => $ts($c['first_event_date'] ?? ''),
                'last_event_date'   => $ts($c['last_event_date'] ?? ''),
                'zaktualizowano_at' => gmdate('Y-m-d H:i:sP'),
                'payload_json'      => json_encode($c, JSON_UNESCAPED_UNICODE),
            ];
            $jest = $db->table('mcs_abusix_sprawy')->where('case_id', $id)->countAllResults();
            if ($jest > 0) {
                $db->table('mcs_abusix_sprawy')->where('case_id', $id)->update($row);
            } else {
                $db->table('mcs_abusix_sprawy')->insert($row + ['case_id' => $id, 'import_id' => $importId]);
            }
            $n++;
        }

        return $n;
    }

    /**
     * @param list<array<string,mixed>> $items
     *
     * @return array{0:int,1:int} [zdarzenia, incydenty]
     */
    private function zapiszZdarzenia($db, int $importId, array $items): array
    {
        $z = 0;
        $i = 0;
        foreach ($items as $e) {
            $m = self::mapujZdarzenie($e);
            if ($m === null) {
                continue;
            }
            $istn = $db->table('mcs_abusix_zdarzenia')->select('id')->where('event_id', $m['event_id'])->get()->getRowArray();
            if (is_array($istn)) {
                $idWZrodle = (string) $istn['id'];
            } else {
                $db->table('mcs_abusix_zdarzenia')->insert($m + ['import_id' => $importId, 'payload_json' => json_encode($e, JSON_UNESCAPED_UNICODE)]);
                $idWZrodle = (string) $db->insertID();
                $z++;
            }
            if ($m['ip'] === '') {
                continue;
            }
            $w = $this->waga->wylicz('abuse', []);
            $this->zapis->zapisz([
                'zrodlo'           => $this->zrodlo(),
                'klucz_dedupu'     => $m['klucz_dedupu'],
                'podmiot_typ'      => 'ip',
                'podmiot'          => $m['ip'],
                'zaobserwowano_at' => $m['zaobserwowano_at'],
                'waga'             => $w['waga'],
                'wersja_wagi'      => $w['wersja'],
                'kategoria'        => 'abuse',
                'tytul'            => 'Abusix' . ($m['event_type'] !== '' ? ' — ' . $m['event_type'] : ''),
                'port'             => null,
                'protokol'         => null,
                'import_id'        => $importId,
                'id_w_zrodle'      => $idWZrodle,
                'payload'          => ['event_id' => $m['event_id'], 'event_type' => $m['event_type'], 'domain' => $m['domain'], 'sender_email' => $m['sender_email']],
            ]);
            $i++;
        }

        return [$z, $i];
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
        if ($msg === '' && $ok === null) {
            $msg = ($this->apiClient ?? ApiClient::fromEnv())->hasAuth()
                ? 'konfiguracja: token API ustawiony (jeszcze bez przebiegu)'
                : 'brak mcs.abusix.apiKey';
        }

        return ['ok' => (bool) ($ok ?? false), 'msg' => $msg !== '' ? $msg : 'brak przebiegu'];
    }

    /**
     * @return array{skrot: string, tryb_aktywny: string, tryb_etykieta: string, pozycje: list<array{nazwa: string, wartosc: string, aktywna: bool, ustawiona: bool}>}
     */
    public function opisPolaczenia(): array
    {
        $client = $this->apiClient ?? ApiClient::fromEnv();
        $ok     = $client->hasAuth();
        $host   = PluginOpisPolaczenia::hostZUrl($client->getBaseUrl());

        return PluginOpisPolaczenia::zloz(
            $ok ? 'api' : 'brak',
            $ok ? 'API (Bearer)' : 'brak konfiguracji',
            $ok ? 'API · ' . $host : 'brak konfiguracji',
            [
                PluginOpisPolaczenia::pozycja('API Abusix', $client->getBaseUrl(), $ok),
                PluginOpisPolaczenia::pozycja('Token', PluginOpisPolaczenia::sekret($ok), false, $ok),
            ],
        );
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
