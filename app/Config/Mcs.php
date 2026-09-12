<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Ustawienia MCS spoza rdzenia CI (token API badge itd.).
 * Wartości z .env: mcs.badgeToken = '…'
 */
class Mcs extends BaseConfig
{
    /**
     * Sekret serwerowy LMS→MCS dla GET|POST /api/badge (single + batch).
     * Pusty = filtr zawsze odrzuca (fail-closed).
     */
    public string $badgeToken = '';
}
