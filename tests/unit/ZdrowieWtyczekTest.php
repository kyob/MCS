<?php

use App\Libraries\ZdrowieWtyczek;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Filters;

/**
 * @internal
 */
final class ZdrowieWtyczekTest extends CIUnitTestCase
{
    private function teraz(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-28 12:00:00', new DateTimeZone('UTC'));
    }

    /**
     * @return array<string, mixed>
     */
    private function wtyczka(string $zrodlo, mixed $ok, ?string $at, bool $wstrzymany = false, string $msg = 'ok'): array
    {
        return ['zrodlo' => $zrodlo, 'import_wstrzymany' => $wstrzymany, 'health_ok' => $ok, 'health_at' => $at, 'health_msg' => $msg];
    }

    public function testWszystkieZdroweToOk(): void
    {
        $w = ZdrowieWtyczek::ocen([
            $this->wtyczka('a', true, '2026-10-28 11:50:00+00'),
            $this->wtyczka('b', 't', '2026-10-28 07:00:00+00'),
        ], $this->teraz());

        $this->assertSame('OK', $w['status']);
        $this->assertSame(2, $w['liczba']);
    }

    public function testHealthFalseToCritical(): void
    {
        $w = ZdrowieWtyczek::ocen([
            $this->wtyczka('a', true, '2026-10-28 11:50:00+00'),
            $this->wtyczka('b', 'f', '2026-10-28 11:50:00+00', false, 'API 429'),
        ], $this->teraz());

        $this->assertSame('CRITICAL', $w['status']);
        $this->assertStringContainsString('b: API 429', $w['opis']);
    }

    public function testWstrzymanaWtyczkaJestPomijana(): void
    {
        $w = ZdrowieWtyczek::ocen([
            $this->wtyczka('a', true, '2026-10-28 11:50:00+00'),
            $this->wtyczka('stop', false, null, true),
        ], $this->teraz());

        $this->assertSame('OK', $w['status']);
        $this->assertSame(1, $w['liczba']);
    }

    public function testNieaktualnyHealthToWarning(): void
    {
        $w = ZdrowieWtyczek::ocen([
            $this->wtyczka('stara', true, '2026-10-25 12:00:00+00'),
        ], $this->teraz());

        $this->assertSame('WARNING', $w['status']);
        $this->assertStringContainsString('stara', $w['opis']);
    }

    public function testBezPrzebieguToWarning(): void
    {
        $w = ZdrowieWtyczek::ocen([$this->wtyczka('nowa', null, null)], $this->teraz());

        $this->assertSame('WARNING', $w['status']);
        $this->assertSame('bez przebiegu', $w['wtyczki'][0]['problem']);
    }

    public function testBladMaPierwszenstwoNadOstrzezeniem(): void
    {
        $w = ZdrowieWtyczek::ocen([
            $this->wtyczka('stara', true, '2026-10-20 12:00:00+00'),
            $this->wtyczka('zla', false, '2026-10-28 11:00:00+00', false, 'boom'),
        ], $this->teraz());

        $this->assertSame('CRITICAL', $w['status']);
        $this->assertStringContainsString('zla: boom', $w['opis']);
        $this->assertStringContainsString('stara', $w['opis']);
    }

    public function testEndpointZdrowiaJestZaFiltremTokenu(): void
    {
        $filtry = (new Filters())->filters['mcsBadgeToken']['before'];

        $this->assertContains('api/health', $filtry);
    }

    public function testPasekNullGdyWszystkoOk(): void
    {
        $this->assertNull(ZdrowieWtyczek::pasek([
            $this->wtyczka('a', true, '2026-10-28 11:50:00+00'),
        ], $this->teraz()));
    }

    public function testPasekZOpisemGdyAwaria(): void
    {
        $p = ZdrowieWtyczek::pasek([
            $this->wtyczka('zla', false, '2026-10-28 11:50:00+00', false, 'API 500'),
        ], $this->teraz());

        $this->assertNotNull($p);
        $this->assertSame('CRITICAL', $p['status']);
        $this->assertStringContainsString('zla: API 500', $p['opis']);
    }

    public function testPasekNieRzucaPrzyBrakuTabeliPluginow(): void
    {
        // Bez tabeli mcs_pluginy odczyt z bazy rzuca wyjątek: pasek ma zwrócić null, nie przerwać strony.
        $this->assertNull(ZdrowieWtyczek::pasek());
    }
}
