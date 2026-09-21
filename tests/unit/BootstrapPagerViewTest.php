<?php

use CodeIgniter\HTTP\URI;
use CodeIgniter\Pager\PagerRenderer;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Pager as PagerConfig;

/**
 * @internal
 */
final class BootstrapPagerViewTest extends CIUnitTestCase
{
    public function testConfigUzywaSzablonowBootstrap(): void
    {
        $config = config(PagerConfig::class);

        $this->assertSame('pager/bootstrap_full', $config->templates['default_full']);
        $this->assertSame('pager/bootstrap_simple', $config->templates['default_simple']);
    }

    public function testBootstrapFullMaKlasyBootstrap5(): void
    {
        $pager = new PagerRenderer([
            'pageCount'    => 5,
            'currentPage'  => 1,
            'total'        => 100,
            'uri'          => new URI('http://example.test/admin/pluginy/historia-importow?zrodlo=mojecert&status=ok'),
            'segment'      => 0,
            'pageSelector' => 'page',
            'perPage'      => 20,
        ]);

        $html = view('pager/bootstrap_full', ['pager' => $pager]);

        $this->assertStringContainsString('class="pagination', $html);
        $this->assertStringContainsString('page-item', $html);
        $this->assertStringContainsString('page-link', $html);
        $this->assertStringContainsString('bi-chevron', $html);
        $this->assertStringContainsString('Nawigacja', $html);
        $this->assertStringContainsString('page-item active', $html);
        $this->assertStringContainsString('page-item disabled', $html);
        $this->assertMatchesRegularExpression('/href="[^"]*page=2/', $html);
    }

    public function testBootstrapSimpleMaKlasyBootstrap5(): void
    {
        $pager = new PagerRenderer([
            'pageCount'    => 3,
            'currentPage'  => 2,
            'total'        => 60,
            'uri'          => new URI('http://example.test/admin/pluginy/historia-importow'),
            'segment'      => 0,
            'pageSelector' => 'page',
            'perPage'      => 20,
        ]);

        $html = view('pager/bootstrap_simple', ['pager' => $pager]);

        $this->assertStringContainsString('page-item', $html);
        $this->assertStringContainsString('page-link', $html);
        $this->assertStringContainsString('Poprzednia', $html);
        $this->assertStringContainsString('Następna', $html);
    }
}
