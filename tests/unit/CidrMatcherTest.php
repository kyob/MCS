<?php

use App\Libraries\Siec\CidrMatcher;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class CidrMatcherTest extends CIUnitTestCase
{
    public function testIpv4WCidr(): void
    {
        $m = new CidrMatcher();
        $this->assertTrue($m->ipWCidr('91.231.80.10', '91.231.80.0/22'));
        $this->assertTrue($m->ipWCidr('91.231.83.255', '91.231.80.0/22'));
        $this->assertFalse($m->ipWCidr('91.231.84.1', '91.231.80.0/22'));
        $this->assertTrue($m->ipWCidr('198.51.100.10', '198.51.100.10'));
        $this->assertFalse($m->ipWCidr('198.51.100.11', '198.51.100.10'));
    }

    public function testIpv6WCidr(): void
    {
        $m = new CidrMatcher();
        $this->assertTrue($m->ipWCidr('2a0f:1700::1', '2a0f:1700::/29'));
        $this->assertFalse($m->ipWCidr('2a0f:1f00::1', '2a0f:1700::/29'));
    }

    public function testParsujIWaliduj(): void
    {
        $m = new CidrMatcher();
        $lista = $m->parsujListe("91.231.80.0/22\n176.97.24.0/21, 2a0f:1700::/29");
        $this->assertSame(['91.231.80.0/22', '176.97.24.0/21', '2a0f:1700::/29'], $lista);
        $this->assertTrue($m->czyPoprawnyCidr('91.231.80.0/22'));
        $this->assertFalse($m->czyPoprawnyCidr('not-a-cidr'));
        $this->assertFalse($m->czyPoprawnyCidr('10.0.0.0/99'));
        $this->assertSame(['foo'], $m->niepoprawne(['91.231.80.0/22', 'foo']));
    }

    public function testIpWLiscie(): void
    {
        $m = new CidrMatcher();
        $cidry = ['91.231.80.0/22', '176.97.24.0/21'];
        $this->assertTrue($m->ipWLiscie('176.97.25.5', $cidry));
        $this->assertFalse($m->ipWLiscie('8.8.8.8', $cidry));
        $this->assertFalse($m->ipWLiscie('nie-ip', $cidry));
    }
}
