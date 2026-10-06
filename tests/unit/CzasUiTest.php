<?php

use App\Libraries\CzasUi;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class CzasUiTest extends CIUnitTestCase
{
    public function testPustyZnacznikZwracaPuste(): void
    {
        $this->assertSame('', CzasUi::dataGodzina(''));
        $this->assertSame('', CzasUi::wzglednie(''));
    }

    public function testDataGodzinaWStrefieAplikacji(): void
    {
        // Europe/Warsaw = CEST (UTC+2) w wrześniu.
        $this->assertSame('2026-09-18 09:30:05', CzasUi::dataGodzina('2026-09-18 07:30:05+00'));
    }

    public function testWzglednieSekundyMinutyGodzinyDni(): void
    {
        $teraz = (new DateTimeImmutable('2026-09-18 10:00:00+00'))->getTimestamp();

        $this->assertSame('12 s temu', CzasUi::wzglednie('2026-09-18 09:59:48+00', $teraz));
        $this->assertSame('5 min temu', CzasUi::wzglednie('2026-09-18 09:55:00+00', $teraz));
        $this->assertSame('2 godz. temu', CzasUi::wzglednie('2026-09-18 08:00:00+00', $teraz));
        $this->assertSame('1 dzień temu', CzasUi::wzglednie('2026-09-17 10:00:00+00', $teraz));
        $this->assertSame('7 dni temu', CzasUi::wzglednie('2026-09-11 10:00:00+00', $teraz));
    }
}
