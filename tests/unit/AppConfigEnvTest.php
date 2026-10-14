<?php

use CodeIgniter\Test\CIUnitTestCase;
use Config\App;

/**
 * @internal
 */
final class AppConfigEnvTest extends CIUnitTestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['test.mcs.lista'], $_ENV['test.mcs.proxy']);
        parent::tearDown();
    }

    public function testListaZEnvPustaDajeDomyslne(): void
    {
        $this->assertSame([], App::listaZEnv('test.mcs.lista', []));
        $_ENV['test.mcs.lista'] = '';
        $this->assertSame(['x'], App::listaZEnv('test.mcs.lista', ['x']));
    }

    public function testListaZEnvParsujePrzecinki(): void
    {
        $_ENV['test.mcs.lista'] = " 192.0.2.10, MCS ,mcs";
        $this->assertSame(['192.0.2.10', 'mcs'], App::listaZEnv('test.mcs.lista', []));
    }

    public function testProxyZEnv(): void
    {
        $_ENV['test.mcs.proxy'] = '192.0.2.247=X-Forwarded-For, 198.51.100.0/24=X-Real-IP,203.0.113.5';
        $this->assertSame([
            '192.0.2.247'     => 'X-Forwarded-For',
            '198.51.100.0/24' => 'X-Real-IP',
            '203.0.113.5'     => 'X-Forwarded-For',
        ], App::proxyZEnv('test.mcs.proxy', []));
    }

    public function testDomyslnieBrakDanychWewnetrznych(): void
    {
        $r = new ReflectionClass(App::class);
        $d = $r->getDefaultProperties();
        $this->assertSame([], $d['allowedHostnames']);
        $this->assertSame([], $d['proxyIPs']);
        $this->assertSame([], $d['httpHostnames']);
    }
}
