<?php

/**
 * LMSMcsMapowaniePlugin — API mapowania IP → komputer/abonent dla MCS.
 *
 * Instalacja: skopiuj katalog do plugins/LMSMcsMapowaniePlugin na hoście LMS.
 * Config [mcs] w lms.ini: map_token (ten sam co mcs.lms.mapToken w MCS).
 *
 * Endpointy (Bearer map_token — bez ?token= / bez badge_token):
 *   GET api.php?action=health
 *   GET api.php?action=lookup&ip=1.2.3.4   (IPv4)
 *   GET api.php?action=sync&limit=&offset=
 */

declare(strict_types=1);

class LMSMcsMapowaniePlugin extends LMSPlugin
{
    public const PLUGIN_DIRECTORY = 'LMSMcsMapowaniePlugin';
    public const PLUGIN_DIRECTORY_NAME = 'LMSMcsMapowaniePlugin';
    public const PLUGIN_NAME = 'MCS Mapowanie';
    public const PLUGIN_DESCRIPTION = 'API JSON: IP → komputer (node) + abonent (customer) dla MCS.';
    public const PLUGIN_AUTHOR = 'MCS';

    public function registerHandlers()
    {
        $this->handlers = [];
    }

    /**
     * @return array<string, mixed>
     */
    public static function getInfo(): array
    {
        return [
            'alias'       => self::PLUGIN_DIRECTORY_NAME,
            'name'        => self::PLUGIN_NAME,
            'description' => self::PLUGIN_DESCRIPTION,
            'authors'     => [['name' => 'MCS', 'email' => '']],
            'version'     => '0.2.0',
            'revision'    => 2,
            'minver'      => '1.0',
        ];
    }
}
