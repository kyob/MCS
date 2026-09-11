<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;

/**
 * Zapis indeksu: upsert incydentu + nowa obserwacja w jednej transakcji.
 */
class IncydentZapis
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @param array{
     *   zrodlo: string,
     *   klucz_dedupu: string,
     *   podmiot_typ?: string,
     *   podmiot: string,
     *   zaobserwowano_at: string,
     *   waga: string,
     *   wersja_wagi?: string,
     *   kategoria: string,
     *   tytul: string,
     *   port?: int|null,
     *   protokol?: string|null,
     *   ukryty_w_ui?: bool,
     *   import_id?: int|null,
     *   id_w_zrodle?: string|null,
     *   payload?: array<string, mixed>|null
     * } $dane
     *
     * @return array{incydent_id: int, obserwacja_id: int, utworzono_incydent: bool}
     */
    public function zapisz(array $dane): array
    {
        foreach (['zrodlo', 'klucz_dedupu', 'podmiot', 'zaobserwowano_at', 'waga', 'kategoria', 'tytul'] as $pole) {
            if (! isset($dane[$pole]) || $dane[$pole] === '') {
                throw new RuntimeException('Brak wymaganego pola: ' . $pole);
            }
        }

        $this->db->transException(true)->transStart();

        try {
            $istniejacy = $this->db->table('mcs_incydenty')
                ->where('zrodlo', $dane['zrodlo'])
                ->where('klucz_dedupu', $dane['klucz_dedupu'])
                ->get()
                ->getRowArray();

            $utworzono = false;
            $czas      = (string) $dane['zaobserwowano_at'];

            if (is_array($istniejacy) && isset($istniejacy['id'])) {
                $incydentId = (int) $istniejacy['id'];
                $update     = [
                    'ostatnio_widziane' => $czas,
                    'waga'              => (string) $dane['waga'],
                    'kategoria'         => (string) $dane['kategoria'],
                    'tytul'             => (string) $dane['tytul'],
                ];
                if (array_key_exists('wersja_wagi', $dane)) {
                    $update['wersja_wagi'] = $dane['wersja_wagi'];
                }
                if (array_key_exists('port', $dane)) {
                    $update['port'] = $dane['port'];
                }
                if (array_key_exists('protokol', $dane)) {
                    $update['protokol'] = $dane['protokol'];
                }
                $ok = $this->db->table('mcs_incydenty')
                    ->where('id', $incydentId)
                    ->update($update);
                if ($ok === false) {
                    throw new RuntimeException('Update incydentu nieudany');
                }
            } else {
                $insert = [
                    'zrodlo'            => (string) $dane['zrodlo'],
                    'klucz_dedupu'      => (string) $dane['klucz_dedupu'],
                    'podmiot_typ'       => (string) ($dane['podmiot_typ'] ?? 'ip'),
                    'podmiot'           => (string) $dane['podmiot'],
                    'pierwsze_widziane' => $czas,
                    'ostatnio_widziane' => $czas,
                    'waga'              => (string) $dane['waga'],
                    'wersja_wagi'       => $dane['wersja_wagi'] ?? null,
                    'kategoria'         => (string) $dane['kategoria'],
                    'tytul'             => (string) $dane['tytul'],
                    'port'              => $dane['port'] ?? null,
                    'protokol'          => $dane['protokol'] ?? null,
                    'ukryty_w_ui'       => (bool) ($dane['ukryty_w_ui'] ?? false),
                ];
                $ok = $this->db->table('mcs_incydenty')->insert($insert);
                if ($ok === false) {
                    throw new RuntimeException('Insert incydentu nieudany');
                }
                $incydentId = (int) $this->db->insertID();
                $utworzono  = true;
            }

            $payload = $dane['payload'] ?? null;
            $okObs   = $this->db->table('mcs_obserwacje')->insert([
                'incydent_id'      => $incydentId,
                'import_id'        => $dane['import_id'] ?? null,
                'zaobserwowano_at' => $czas,
                'payload_json'     => is_array($payload)
                    ? json_encode($payload, JSON_UNESCAPED_UNICODE)
                    : null,
                'id_w_zrodle'      => $dane['id_w_zrodle'] ?? null,
            ]);

            if ($okObs === false) {
                throw new RuntimeException('Insert obserwacji nieudany');
            }

            $obsId = (int) $this->db->insertID();

            $this->db->transComplete();

            if ($this->db->transStatus() === false) {
                throw new RuntimeException('Transakcja zapisu incydentu/obserwacji nieudana');
            }

            return [
                'incydent_id'        => $incydentId,
                'obserwacja_id'      => $obsId,
                'utworzono_incydent' => $utworzono,
            ];
        } catch (\Throwable $e) {
            if ($this->db->transStatus() !== false) {
                $this->db->transRollback();
            }
            throw $e;
        }
    }
}
