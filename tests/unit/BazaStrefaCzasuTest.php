<?php

use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

/**
 * Okna dat i dzień w osi czasu zakładają UTC w sesji bazy (PostgreSQL),
 * więc połączenie wymusza timezone=UTC niezależnie od ustawień serwera.
 *
 * @internal
 */
final class BazaStrefaCzasuTest extends CIUnitTestCase
{
    public function testPolaczenieWymuszaUtcWSesjiPostgreSql(): void
    {
        $this->assertSame('-c timezone=UTC', (new Database())->default['options']);
    }
}
