<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class AkcjeFiltrowViewTest extends CIUnitTestCase
{
    public function testCzyszczenieJestUkryteBezAktywnychFiltrow(): void
    {
        $html = view('partials/_akcje_filtrow', [
            'wyczyscHref'  => '/reset',
            'pokazWyczysc' => false,
        ], ['saveData' => false]);

        $this->assertStringContainsString('aria-label="Zastosuj filtry"', $html);
        $this->assertStringNotContainsString('aria-label="Wyczyść filtry"', $html);
        $this->assertStringNotContainsString('/reset', $html);
    }

    public function testCzyszczenieJestWidoczneDlaAktywnychFiltrow(): void
    {
        $html = view('partials/_akcje_filtrow', [
            'wyczyscHref'  => '/reset',
            'pokazWyczysc' => true,
        ], ['saveData' => false]);

        $this->assertStringContainsString('aria-label="Wyczyść filtry"', $html);
        $this->assertStringContainsString('/reset', $html);
    }
}
