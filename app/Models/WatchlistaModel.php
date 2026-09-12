<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Lista obserwowanych IP (watchlista) — tylko INFO, bez auto-blokad.
 */
class WatchlistaModel extends Model
{
    protected $table            = 'mcs_watchlista';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $allowedFields    = ['ip', 'notatka', 'dodano_at', 'dodano_przez'];
    protected $useTimestamps    = false;

    /**
     * @return list<array<string, mixed>>
     */
    public function wszystkie(): array
    {
        $lista = $this->orderBy('dodano_at', 'DESC')->findAll();

        return is_array($lista) ? $lista : [];
    }

    /**
     * @return list<string>
     */
    public function listaIp(): array
    {
        $lista = [];
        foreach ($this->wszystkie() as $wiersz) {
            $ip = isset($wiersz['ip']) ? (string) $wiersz['ip'] : '';
            if ($ip !== '') {
                $lista[] = $ip;
            }
        }

        return $lista;
    }

    public function czyObserwowane(string $ip): bool
    {
        $ip = trim($ip);
        if ($ip === '' || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        return $this->where('ip', $ip)->first() !== null;
    }

    public function dodaj(string $ip, ?string $notatka = null, ?string $dodanoPrzez = null): bool
    {
        $ip = trim($ip);
        if ($ip === '' || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        if ($this->czyObserwowane($ip)) {
            return false;
        }

        $notatka = $notatka !== null ? trim($notatka) : '';
        if ($notatka === '') {
            $notatka = null;
        }

        return $this->insert([
            'ip'           => $ip,
            'notatka'      => $notatka,
            'dodano_at'    => date('c'),
            'dodano_przez' => $dodanoPrzez !== null && trim($dodanoPrzez) !== ''
                ? trim($dodanoPrzez)
                : null,
        ]) !== false;
    }

    public function usun(string $ip): bool
    {
        $ip = trim($ip);
        if ($ip === '' || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        $wiersz = $this->where('ip', $ip)->first();
        if ($wiersz === null) {
            return false;
        }

        return $this->delete($wiersz['id']) !== false;
    }
}
