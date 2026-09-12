#!/usr/bin/env php
<?php

/**
 * Lokalny smoke scaffoldu (bez LMS): składnia + klient (bez sieci).
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
    'LMSMcsBadgePlugin.php',
    'handlers/McsBadgeHandler.php',
    'lib/McsBadgeClient.php',
    'lib/McsBadgeCache.php',
    'templates/node/nodeinfobox.html',
    'templates/nodeinfo-badge.html',
    'config/lms.ini.example',
    'bin/install-to-lms.sh',
] as $rel) {
    $path = $root . '/' . $rel;
    if (!is_readable($path)) {
        fail("brak pliku $rel");
    }
}

require_once $root . '/lib/McsBadgeClient.php';
require_once $root . '/lib/McsBadgeCache.php';

$client = new McsBadgeClient('https://mcs.example/', '');
$r = $client->badgeDlaIp('198.51.100.10');
if ($r['ok'] || $r['error'] !== 'mcs config incomplete') {
    fail('oczekiwano błędu incomplete config bez tokenu');
}

$client = new McsBadgeClient('https://mcs.example/', 'dummy-token-not-used-for-invalid-ip');
$r = $client->badgeDlaIp('not-an-ip');
if ($r['ok'] || $r['error'] !== 'invalid ip') {
    fail('oczekiwano invalid ip');
}

$batch = $client->badgeDlaIpLista([]);
if ($batch['ok'] || ($batch['error'] !== 'empty ips' && $batch['error'] !== 'invalid ip')) {
    fail('batch pusty powinien zwrócić empty ips / invalid ip, dostano: ' . ($batch['error'] ?? '?'));
}

$batch = $client->badgeDlaIpLista(['not-an-ip']);
if ($batch['ok'] || $batch['error'] !== 'invalid ip') {
    fail('batch z złym IP powinien zwrócić invalid ip');
}

$dir = sys_get_temp_dir() . '/mcs-badge-selfcheck-' . getmypid();
@mkdir($dir, 0700, true);
$cache = new McsBadgeCache(60, $dir);
$cache->set('198.51.100.10', ['liczba' => 2, 'max_waga' => 'high', 'url' => 'https://mcs.example/ip/198.51.100.10']);
$got = $cache->get('198.51.100.10');
if (!is_array($got) || (int) $got['liczba'] !== 2) {
    fail('cache get/set');
}
foreach (glob($dir . '/*') ?: [] as $f) {
    @unlink($f);
}
@rmdir($dir);

$tpl = file_get_contents($root . '/templates/nodeinfo-badge.html');
if ($tpl === false || !str_contains($tpl, 'mcs-badge')) {
    fail('template');
}

if (!is_executable($root . '/bin/install-to-lms.sh')) {
    @chmod($root . '/bin/install-to-lms.sh', 0755);
}

echo $ok ? "OK scaffold lms-mcs-badge selfcheck\n" : '';
exit($ok ? 0 : 1);
