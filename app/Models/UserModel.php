<?php

namespace App\Models;

use CodeIgniter\Model;

class UserModel extends Model
{
    protected $table            = 'mcs_users';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $allowedFields    = [
        'email',
        'password_hash',
        'rola',
        'aktywny',
        'created_at',
    ];
    protected $useTimestamps = false;

    /**
     * @return array{id: int|string, email: string, password_hash: string, rola: string, aktywny: bool|int|string, created_at?: string}|null
     */
    public function znajdzAktywnegoPoEmailu(string $email): ?array
    {
        $email = strtolower(trim($email));

        $wiersz = $this->where('email', $email)
            ->where('aktywny', true)
            ->first();

        return is_array($wiersz) ? $wiersz : null;
    }
}
