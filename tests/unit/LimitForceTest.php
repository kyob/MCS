<?php

use App\Libraries\LimitForce;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\Mock\MockCache;
use CodeIgniter\Throttle\Throttler;

/**
 * @internal
 */
final class LimitForceTest extends CIUnitTestCase
{
    private Throttler $throttler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->throttler = new Throttler(new MockCache());
        $this->throttler->setTestTime(1_800_000_000);
    }

    public function testUzytkownikMaLimitIOddzielnyKubelek(): void
    {
        $limit = new LimitForce($this->throttler);
        $max   = \App\Libraries\OpcjeMcs::int('mcs.force.limitUzytkownik');

        for ($i = 0; $i < $max; $i++) {
            $this->assertTrue($limit->pozwolUzytkownik('Op@example.net'), (string) $i);
        }

        $this->assertFalse($limit->pozwolUzytkownik('op@example.net'));
        $this->assertTrue($limit->pozwolUzytkownik('inny@example.net'));
    }

    public function testApiMaLaczonyLimit(): void
    {
        $limit = new LimitForce($this->throttler);
        $max   = \App\Libraries\OpcjeMcs::int('mcs.force.limitApi');

        for ($i = 0; $i < $max; $i++) {
            $this->assertTrue($limit->pozwolApi());
        }

        $this->assertFalse($limit->pozwolApi());
    }

    public function testRetryAfterToCzasJednegoTokenu(): void
    {
        $this->assertSame(120, LimitForce::retryAfter(30));
        $this->assertSame(3600, LimitForce::retryAfter(1));
    }
}
