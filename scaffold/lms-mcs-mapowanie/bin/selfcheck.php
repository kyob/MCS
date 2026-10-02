#!/usr/bin/env php
<?php

/**
 * Lokalny smoke scaffoldu mapowania (bez LMS / bez sieci).
 * Sprawdza pliki, php -l oraz twarde reguły auth w api.php.
 * Nie wypisuje sekretów. Uruchom: php bin/selfcheck.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$ok   = true;

function fail(string $msg): void
{
    global $ok;
    $ok = false;
    fwrite(STDERR, "FAIL: $msg\n");
}

foreach ([
    'LMSMcsMapowaniePlugin.php',
    'api.php',
    'config/lms.ini.example',
    'bin/install-to-lms.sh',
    'bin/selfcheck.php',
    'README.md',
] as $rel) {
    $path = $root . '/' . $rel;
    if (! is_readable($path)) {
        fail("brak pliku $rel");
    }
}

foreach (['LMSMcsMapowaniePlugin.php', 'api.php'] as $rel) {
    $path = $root . '/' . $rel;
    $out  = [];
    $code = 0;
    exec('php -l ' . escapeshellarg($path) . ' 2>&1', $out, $code);
    if ($code !== 0) {
        fail("php -l $rel: " . implode(' ', $out));
    }
}

$api = (string) file_get_contents($root . '/api.php');
if ($api === '') {
    fail('api.php pusty');
}

if (preg_match('/\$_GET\s*\[\s*[\'"]token[\'"]\s*\]/', $api) === 1) {
    fail('api.php nie może akceptować ?token= (tylko Bearer)');
}
if (preg_match('/mcs\.badge_token|CONFIG\[.mcs.\]\[.badge_token.\]|getConfig\(\s*[\'"]mcs\.badge_token/', $api) === 1) {
    fail('api.php nie może czytać badge_token jako fallback auth');
}
if (! str_contains($api, 'Authorization') || ! str_contains($api, 'Bearer')) {
    fail('api.php musi wymagać Authorization: Bearer');
}
if (! str_contains($api, 'FILTER_FLAG_IPV4')) {
    fail('api.php lookup musi być IPv4-only');
}
if (! str_contains($api, 'has_more') || ! str_contains($api, 'limit')) {
    fail('api.php sync musi mieć paginację limit/offset + has_more');
}

$ini = (string) file_get_contents($root . '/config/lms.ini.example');
if (! str_contains($ini, 'map_token')) {
    fail('lms.ini.example bez map_token');
}
if (preg_match('/może użyć badge_token|fallback.*badge_token|badge_token jako fallback/i', $ini) === 1) {
    fail('lms.ini.example nie powinien sugerować fallbacku badge_token');
}

$plugin = (string) file_get_contents($root . '/LMSMcsMapowaniePlugin.php');
if (! str_contains($plugin, "'version'") && ! str_contains($plugin, '"version"')) {
    fail('LMSMcsMapowaniePlugin.php bez version');
}

if (! is_executable($root . '/bin/install-to-lms.sh')) {
    @chmod($root . '/bin/install-to-lms.sh', 0755);
}

echo $ok ? "OK scaffold lms-mcs-mapowanie selfcheck\n" : '';
exit($ok ? 0 : 1);
