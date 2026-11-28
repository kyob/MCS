<?php

use App\Commands\McsDigest;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class McsDigestOpcjeTest extends CIUnitTestCase
{
    public function testTylkoAsBezNumeru(): void
    {
        $this->assertTrue(McsDigest::czyTylkoAs(['tylko-as' => null]));
    }

    public function testDawneNazwyZNumeremAsDzialaja(): void
    {
        $this->assertTrue(McsDigest::czyTylkoAs(['tylko-as64496' => null]));
        $this->assertTrue(McsDigest::czyTylkoAs(['tylko-as64500' => null]));
        $this->assertTrue(McsDigest::czyTylkoAs([], ['spark', 'mcs:digest', '--godziny=6', '--tylko-as64500']));
    }

    public function testBezFlagiFiltrWylaczony(): void
    {
        $this->assertFalse(McsDigest::czyTylkoAs(['godziny' => '6', 'json' => null], ['spark', 'mcs:digest', '--godziny=6']));
        $this->assertFalse(McsDigest::czyTylkoAs(['tylko-asx' => null]));
    }
}
