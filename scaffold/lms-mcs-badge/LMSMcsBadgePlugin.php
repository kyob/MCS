<?php

/**
 * LMSMcsBadgePlugin — cienki badge MCS na nodeinfo (Faza 5).
 *
 * Katalog wtyczki = nazwa klasy. Config: [mcs] w lms.ini (base_url, badge_token).
 */

require_once __DIR__ . '/lib/McsBadgeClient.php';
require_once __DIR__ . '/lib/McsBadgeCache.php';
require_once __DIR__ . '/handlers/McsBadgeHandler.php';

class LMSMcsBadgePlugin extends LMSPlugin
{
    public const PLUGIN_DIRECTORY = 'LMSMcsBadgePlugin';
    public const PLUGIN_DIRECTORY_NAME = 'LMSMcsBadgePlugin';
    // Bez PLUGIN_DB_VERSION — brak migracji SQL (sam badge API).
    public const PLUGIN_NAME = 'MCS Badge';
    public const PLUGIN_DESCRIPTION = 'Badge MCS na nodeinfo: pigułki wagi/liczby/źródeł + deep link (bez auto-blokad).';
    public const PLUGIN_AUTHOR = 'MCS';

    public function registerHandlers()
    {
        $this->handlers = [
            'smarty_initialized' => [
                'class'  => 'McsBadgeHandler',
                'method' => 'smartyInit',
            ],
            'nodeinfo_before_display' => [
                'class'  => 'McsBadgeHandler',
                'method' => 'nodeInfoBeforeDisplay',
            ],
        ];
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
            'version'     => '0.5.0',
            'revision'    => 3,
            'minver'      => '1.0',
        ];
    }
}
