<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Skaner DNSBL AS198050 (plugin dnsbl, etap E1): rejestr stref, skany, wyniki, bieżące listingi.
 *
 * mcs_dnsbl_wyniki trzyma tylko listingi i błędy (czyste IP = brak wiersza).
 * mcs_dnsbl_listingi = stan bieżący (unikat ip+strefa), aktualizowany diffem po skanie.
 */
class CreateMcsDnsblSkaner extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'             => ['type' => 'INT', 'auto_increment' => true],
            'kod'            => ['type' => 'VARCHAR', 'constraint' => 32],
            'nazwa'          => ['type' => 'VARCHAR', 'constraint' => 100],
            'strefa'         => ['type' => 'VARCHAR', 'constraint' => 255],
            // ip = zapytanie per IP; siec24 = jedno zapytanie na /24 (np. UCEPROTECT L2/L3)
            'typ'            => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'ip'],
            'mapa_kodow'     => ['type' => 'JSONB', 'null' => true],
            'url_delisting'  => ['type' => 'VARCHAR', 'constraint' => 500, 'default' => ''],
            'informacyjna'   => ['type' => 'BOOLEAN', 'default' => false],
            'aktywna'        => ['type' => 'BOOLEAN', 'default' => true],
            // klucz z .env wstawiany przed strefą (np. spamrats); pusty = brak
            'wymaga_klucza'  => ['type' => 'VARCHAR', 'constraint' => 32, 'default' => ''],
            'test_ip'        => ['type' => 'VARCHAR', 'constraint' => 45, 'default' => '127.0.0.2'],
            'kolejnosc'      => ['type' => 'INT', 'default' => 100],
            'uwagi'          => ['type' => 'TEXT', 'null' => true],
            'created_at'     => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'updated_at'     => ['type' => 'TIMESTAMPTZ', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('kod');
        $this->forge->createTable('mcs_dnsbl_strefy', true);

        $this->forge->addField([
            'id'               => ['type' => 'BIGINT', 'auto_increment' => true],
            'zakres'           => ['type' => 'TEXT'],
            'strefa_filtr'     => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'tryb'             => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'cli'],
            'status'           => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'oczekuje'],
            'uzytkownik'       => ['type' => 'VARCHAR', 'constraint' => 255, 'default' => ''],
            'utworzono_at'     => ['type' => 'TIMESTAMPTZ', 'null' => false],
            'start_at'         => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'koniec_at'        => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'heartbeat_at'     => ['type' => 'TIMESTAMPTZ', 'null' => true],
            'liczba_ip'        => ['type' => 'INT', 'default' => 0],
            'zapytan_plan'     => ['type' => 'INT', 'default' => 0],
            'zapytan_wykonano' => ['type' => 'INT', 'default' => 0],
            'czyste'           => ['type' => 'INT', 'default' => 0],
            'listowane'        => ['type' => 'INT', 'default' => 0],
            'bledy'            => ['type' => 'INT', 'default' => 0],
            'czas_ms'          => ['type' => 'INT', 'default' => 0],
            'strefy_json'      => ['type' => 'JSONB', 'null' => true],
            'komunikat'        => ['type' => 'TEXT', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('status');
        $this->forge->addKey('utworzono_at');
        $this->forge->createTable('mcs_dnsbl_skany', true);

        $this->forge->addField([
            'id'         => ['type' => 'BIGINT', 'auto_increment' => true],
            'skan_id'    => ['type' => 'BIGINT'],
            'ip'         => ['type' => 'VARCHAR', 'constraint' => 45],
            'strefa_kod' => ['type' => 'VARCHAR', 'constraint' => 32],
            'status'     => ['type' => 'VARCHAR', 'constraint' => 16],
            'kody'       => ['type' => 'VARCHAR', 'constraint' => 255, 'default' => ''],
            'txt'        => ['type' => 'TEXT', 'null' => true],
            'blad'       => ['type' => 'VARCHAR', 'constraint' => 255, 'default' => ''],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['skan_id', 'status']);
        $this->forge->addKey('ip');
        $this->forge->addForeignKey('skan_id', 'mcs_dnsbl_skany', 'id', '', 'CASCADE');
        $this->forge->createTable('mcs_dnsbl_wyniki', true);

        $this->forge->addField([
            'id'           => ['type' => 'BIGINT', 'auto_increment' => true],
            'ip'           => ['type' => 'VARCHAR', 'constraint' => 45],
            'strefa_kod'   => ['type' => 'VARCHAR', 'constraint' => 32],
            'kody'         => ['type' => 'VARCHAR', 'constraint' => 255, 'default' => ''],
            'txt'          => ['type' => 'TEXT', 'null' => true],
            'pierwszy_raz' => ['type' => 'TIMESTAMPTZ', 'null' => false],
            'ostatni_raz'  => ['type' => 'TIMESTAMPTZ', 'null' => false],
            'aktywny'      => ['type' => 'BOOLEAN', 'default' => true],
            'skan_id'      => ['type' => 'BIGINT', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['ip', 'strefa_kod']);
        $this->forge->addKey('aktywny');
        $this->forge->addForeignKey('skan_id', 'mcs_dnsbl_skany', 'id', '', 'SET NULL');
        $this->forge->createTable('mcs_dnsbl_listingi', true);

        $teraz = gmdate('Y-m-d H:i:sP');
        $wiersze = [];
        foreach (self::strefyStartowe() as $i => $s) {
            $wiersze[] = array_merge([
                'typ'           => 'ip',
                'informacyjna'  => false,
                'aktywna'       => true,
                'wymaga_klucza' => '',
                'test_ip'       => '127.0.0.2',
                'uwagi'         => null,
                'kolejnosc'     => ($i + 1) * 10,
                'created_at'    => $teraz,
                'updated_at'    => $teraz,
            ], $s, [
                'mapa_kodow' => json_encode($s['mapa_kodow'] ?? new \stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        }
        $this->db->table('mcs_dnsbl_strefy')->insertBatch($wiersze);
    }

    public function down()
    {
        $this->forge->dropTable('mcs_dnsbl_listingi', true);
        $this->forge->dropTable('mcs_dnsbl_wyniki', true);
        $this->forge->dropTable('mcs_dnsbl_skany', true);
        $this->forge->dropTable('mcs_dnsbl_strefy', true);
    }

    /**
     * Strefy startowe (MVP bez Spamhaus i Abusix). Sprawdzone z lokalnego unbound 2026-10-14.
     *
     * @return list<array<string, mixed>>
     */
    public static function strefyStartowe(): array
    {
        return [
            [
                'kod' => 'spamcop', 'nazwa' => 'SpamCop', 'strefa' => 'bl.spamcop.net',
                'url_delisting' => 'https://www.spamcop.net/bl.shtml?{ip}',
                'mapa_kodow' => ['127.0.0.2' => 'listed'],
            ],
            [
                'kod' => 'psbl', 'nazwa' => 'PSBL (Passive Spam Block List)', 'strefa' => 'psbl.surriel.com',
                'url_delisting' => 'https://psbl.org/listing?ip={ip}',
                'mapa_kodow' => ['127.0.0.2' => 'listed'],
            ],
            [
                'kod' => 'uceprotect1', 'nazwa' => 'UCEPROTECT Level 1', 'strefa' => 'dnsbl-1.uceprotect.net',
                'url_delisting' => 'https://www.uceprotect.net/en/rblcheck.php?ipr={ip}',
                'mapa_kodow' => ['127.0.0.2' => 'listed'],
            ],
            [
                'kod' => 'uceprotect2', 'nazwa' => 'UCEPROTECT Level 2 (sieć)', 'strefa' => 'dnsbl-2.uceprotect.net',
                'typ' => 'siec24', 'informacyjna' => true,
                'url_delisting' => 'https://www.uceprotect.net/en/rblcheck.php?ipr={ip}',
                'mapa_kodow' => ['127.0.0.2' => 'listed'],
                'uwagi' => 'Informacyjna. Jedno zapytanie na /24.',
            ],
            [
                'kod' => 'uceprotect3', 'nazwa' => 'UCEPROTECT Level 3 (ASN)', 'strefa' => 'dnsbl-3.uceprotect.net',
                'typ' => 'siec24', 'informacyjna' => true,
                'url_delisting' => 'https://www.uceprotect.net/en/rblcheck.php?ipr={ip}',
                'mapa_kodow' => ['127.0.0.2' => 'listed'],
                'uwagi' => 'Informacyjna. Jedno zapytanie na /24.',
            ],
            [
                'kod' => 'mailspike', 'nazwa' => 'Mailspike BL', 'strefa' => 'bl.mailspike.net',
                'url_delisting' => 'https://mailspike.org/iplookup.html',
                'mapa_kodow' => [
                    '127.0.0.2' => 'listed', '127.0.0.10' => 'reputacja L5', '127.0.0.11' => 'reputacja L4',
                    '127.0.0.12' => 'reputacja L3', '127.0.0.13' => 'reputacja L2', '127.0.0.14' => 'reputacja L1',
                ],
            ],
            [
                'kod' => 'manitu', 'nazwa' => 'NiX Spam (manitu)', 'strefa' => 'ix.dnsbl.manitu.net',
                'aktywna' => false,
                'url_delisting' => 'https://www.dnsbl.manitu.net/lookup.php?value={ip}',
                'mapa_kodow' => ['127.0.0.2' => 'listed'],
                'uwagi' => 'Wyłączona 2026-10-14: brak odpowiedzi (SERVFAIL/timeout) z lokalnego unbound i z 91.231.70.48.',
            ],
            [
                'kod' => 'dronebl', 'nazwa' => 'DroneBL', 'strefa' => 'dnsbl.dronebl.org',
                'url_delisting' => 'https://dronebl.org/lookup?ip={ip}',
                'mapa_kodow' => [
                    '127.0.0.1' => 'test', '127.0.0.3' => 'IRC drone', '127.0.0.5' => 'bottler',
                    '127.0.0.6' => 'nieznany spambot/drone', '127.0.0.7' => 'DDoS drone', '127.0.0.8' => 'SOCKS proxy',
                    '127.0.0.9' => 'HTTP proxy', '127.0.0.10' => 'ProxyChain', '127.0.0.11' => 'web proxy',
                    '127.0.0.13' => 'brute force', '127.0.0.14' => 'open Wingate', '127.0.0.15' => 'przejęty router',
                    '127.0.0.16' => 'autorooting worm', '127.0.0.17' => 'botnet', '127.0.0.19' => 'nadużywany VPN',
                ],
            ],
            [
                'kod' => 's5h', 'nazwa' => 's5h.net', 'strefa' => 'all.s5h.net',
                'url_delisting' => 'http://s5h.net/rbl',
                'mapa_kodow' => ['127.0.0.2' => 'listed'],
            ],
            [
                'kod' => 'barracuda', 'nazwa' => 'Barracuda BRBL', 'strefa' => 'b.barracudacentral.org',
                'url_delisting' => 'https://www.barracudacentral.org/rbl/removal-request',
                'mapa_kodow' => ['127.0.0.2' => 'listed'],
                'uwagi' => 'Odpowiada bez rejestracji resolvera (test 2026-10-14). Barracuda zaleca bezpłatną rejestrację.',
            ],
            [
                'kod' => 'spamrats', 'nazwa' => 'SpamRats (RATS-All)', 'strefa' => 'all.spamrats.com',
                'wymaga_klucza' => 'spamrats',
                'url_delisting' => 'https://www.spamrats.com/removal.php?ip={ip}',
                'mapa_kodow' => [
                    '127.0.0.36' => 'RATS-Dyna', '127.0.0.37' => 'RATS-NoPtr', '127.0.0.38' => 'RATS-Spam', '127.0.0.43' => 'RATS-Auth',
                ],
                'uwagi' => 'Zapytanie {ip}.{mcs.spamrats.apiKey}.all.spamrats.com, jak plugin spamrats.',
            ],
        ];
    }
}
