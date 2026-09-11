<?php

use CodeIgniter\Test\CIUnitTestCase;
use McsPluginy\shadowserver\Plugin;

/**
 * @internal
 */
final class ShadowserverZipCsvTest extends CIUnitTestCase
{
    public function testWyciagaCsvZZipa(): void
    {
        $csv = "timestamp,ip,protocol,port,tag\n"
            . "2026-09-10 12:00:00,198.51.100.50,tcp,445,smb\n";

        $tmpZip = tempnam(sys_get_temp_dir(), 'mcs_zip_');
        $this->assertNotFalse($tmpZip);
        $zipPath = $tmpZip . '.zip';
        @unlink($tmpZip);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath, ZipArchive::CREATE) === true);
        $zip->addFromString('report.csv', $csv);
        $zip->close();

        $plugin = new Plugin();
        $ref    = new ReflectionMethod(Plugin::class, 'wyciagnijCsvZZalacznika');
        $ref->setAccessible(true);
        $wynik = $ref->invoke($plugin, [
            'filename' => 'report.csv.zip',
            'mime'     => 'application/zip',
            'tresc'    => (string) file_get_contents($zipPath),
        ]);
        @unlink($zipPath);

        $this->assertNotNull($wynik);
        $this->assertSame('report.csv', $wynik['filename']);
        $this->assertTrue($wynik['z_zip']);
        $this->assertStringContainsString('198.51.100.50', $wynik['tresc']);
    }
}
