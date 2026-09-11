<?php

use App\Libraries\Imap\ImapKlientFabryka;
use App\Libraries\Imap\ImapKlientStub;
use App\Libraries\Imap\ImapUstawienia;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class ImapKlientTest extends CIUnitTestCase
{
    public function testUstawieniaPusteNieSaSkonfigurowane(): void
    {
        $u = new ImapUstawienia('', 993, '', '');
        $this->assertFalse($u->jestSkonfigurowane());
    }

    public function testUstawieniaPelne(): void
    {
        $u = new ImapUstawienia('mail.example.local', 993, 'user@example.local', 'sekret');
        $this->assertTrue($u->jestSkonfigurowane());
    }

    public function testStubListujeIOznacza(): void
    {
        $stub = new ImapKlientStub([
            [
                'uid'        => 10,
                'message_id' => '<a@b>',
                'subject'    => 'Raport',
                'zalaczniki' => [
                    ['filename' => 'x.csv', 'mime' => 'text/csv', 'tresc' => "ip,type\n1.2.3.4,open\n"],
                ],
            ],
            [
                'uid'         => 11,
                'subject'     => 'Stary',
                'przeczytane' => true,
            ],
        ]);

        $this->assertSame([10], $stub->listujUid(true));
        $this->assertSame([10, 11], $stub->listujUid(false));

        $w = $stub->pobierzWiadomosc(10);
        $this->assertSame('<a@b>', $w['message_id']);
        $this->assertSame('x.csv', $w['zalaczniki'][0]['filename']);

        $stub->oznaczPrzeczytane(10);
        $this->assertSame([], $stub->listujUid(true));
    }

    public function testFabrykaBezConfiguRzucaPoPolsku(): void
    {
        // Czyścimy ewentualne env z .env testowego — fabryka czyta env();
        // używamy prefiksu którego na pewno nie ma.
        $fabryka = new ImapKlientFabryka();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('uzupełnij klucze');
        $fabryka->dlaZrodla('zrodlo_bez_imap_xyz');
    }

    public function testFabrykaRaportujeExt(): void
    {
        $fabryka = new ImapKlientFabryka();
        $this->assertSame(extension_loaded('imap'), $fabryka->rozszerzenieDostepne());
    }
}
