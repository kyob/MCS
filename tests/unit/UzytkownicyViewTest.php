<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class UzytkownicyViewTest extends CIUnitTestCase
{
    public function testListaIFormularzDodawania(): void
    {
        $html = view('admin/uzytkownicy/index', [
            'title'      => 'Użytkownicy — MCS',
            'navAktywny' => 'uzytkownicy',
            'email'      => 'sa@example.local',
            'rola'       => 'superadmin',
            'sesjaId'    => 1,
            'komunikat'  => null,
            'blad'       => null,
            'uzytkownicy'=> [
                [
                    'id'       => 1,
                    'email'    => 'sa@example.local',
                    'rola'     => 'superadmin',
                    'aktywny'  => true,
                    'ostatni_sa' => true,
                ],
                [
                    'id'       => 2,
                    'email'    => 'ops@example.local',
                    'rola'     => 'operator',
                    'aktywny'  => true,
                    'ostatni_sa' => false,
                ],
            ],
        ]);

        $this->assertStringContainsString('Użytkownicy', $html);
        $this->assertStringContainsString('sa@example.local', $html);
        $this->assertStringContainsString('ops@example.local', $html);
        $this->assertStringContainsString('Dodaj konto', $html);
        $this->assertStringContainsString('name="email"', $html);
        $this->assertStringContainsString('name="haslo"', $html);
        $this->assertStringContainsString('name="rola"', $html);
        $this->assertStringContainsString('admin/uzytkownicy', $html);
        $this->assertStringContainsString('csrf', $html);
        $this->assertStringContainsString('Wyłącz', $html);
        $this->assertMatchesRegularExpression('/ostatniego superadmina|disabled[^>]*>\\s*Wyłącz/u', $html);
        $this->assertStringContainsString('admin/uzytkownicy/usun', $html);
        $this->assertStringContainsString('Usuń', $html);
        $this->assertStringNotContainsString('bez kasowania', $html);
        $this->assertMatchesRegularExpression('/onsubmit="return confirm\\(\'Usunąć /u', $html);
    }

    public function testOstatniSuperadminNieMaAktywnegoUsuwania(): void
    {
        $html = view('admin/uzytkownicy/index', [
            'title'       => 'Użytkownicy — MCS',
            'navAktywny'  => 'uzytkownicy',
            'email'       => 'sa@example.local',
            'rola'        => 'superadmin',
            'sesjaId'     => 1,
            'komunikat'   => null,
            'blad'        => null,
            'uzytkownicy' => [
                [
                    'id'         => 1,
                    'email'      => 'sa@example.local',
                    'rola'       => 'superadmin',
                    'aktywny'    => true,
                    'ostatni_sa' => true,
                ],
            ],
        ]);

        $this->assertDoesNotMatchRegularExpression(
            '/action="[^"]*admin\\/uzytkownicy\\/usun"[^>]*>\\s*.*name="id" value="1"/s',
            $html,
        );
        $this->assertMatchesRegularExpression('/disabled[^>]*>\\s*Usuń/u', $html);
    }
}
