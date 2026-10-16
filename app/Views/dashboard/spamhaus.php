<?= $this->extend('layouts/mcs') ?>
<?= $this->section('content') ?>

<?php
$d         = is_array($d ?? null) ? $d : [];
$stan      = is_array($d['stan'] ?? null) ? $d['stan'] : [];
$pliki     = is_array($d['pliki'] ?? null) ? $d['pliki'] : [];
$trafienia = is_array($d['trafienia'] ?? null) ? $d['trafienia'] : [];
$historia  = is_array($d['historia'] ?? null) ? $d['historia'] : [];
$blad      = $blad ?? null;
$wynik     = (string) ($stan['wynik'] ?? 'brak');
$okAt      = \App\Libraries\CzasUi::dataGodzina((string) ($stan['ostatnie_ok_at'] ?? ''));
$nastepne  = \App\Libraries\CzasUi::dataGodzina((string) ($stan['nastepne_po'] ?? ''));
$bledy     = (int) ($stan['bledy_z_rzedu'] ?? 0);
$plikData  = (string) ($d['plik_data'] ?? '');
$healthOk  = $d['health_ok'] ?? null;
$healthMsg = (string) ($d['health_msg'] ?? '');
$fmtLiczba = static fn (int $n): string => number_format($n, 0, ',', "\u{00A0}");
$podsumowanie = null;
if (preg_match(
    '/^DROP (?:czysto|TRAFIENIE): v4=(\d+) v6=(\d+) asn=(\d+), trafienia=(\d+) \(nowe=(\d+), zdjęte=(\d+)\), prefiksów=(\d+), AS(\d+)$/u',
    $healthMsg,
    $mh,
)) {
    $podsumowanie = [
        ['DROP IPv4', $fmtLiczba((int) $mh[1]), ''],
        ['DROPv6', $fmtLiczba((int) $mh[2]), ''],
        ['ASN-DROP', $fmtLiczba((int) $mh[3]), ''],
        ['Trafienia', $fmtLiczba((int) $mh[4]), (int) $mh[4] > 0 ? 'text-danger' : ''],
        ['Nowe', $fmtLiczba((int) $mh[5]), (int) $mh[5] > 0 ? 'text-danger' : ''],
        ['Zdjęte', $fmtLiczba((int) $mh[6]), (int) $mh[6] > 0 ? 'text-success' : ''],
        ['Prefiksy', $fmtLiczba((int) $mh[7]), ''],
        ['ASN', 'AS' . $mh[8], 'font-monospace'],
    ];
}
$opisHistorii = static function (string $opis) use ($fmtLiczba): ?array {
    if (! preg_match('/^pliki OK \(([^)]*)\); trafienia=(\d+), nowe=(\d+), zdjęte=(\d+)$/u', $opis, $m)) {
        return null;
    }
    $nazwy = ['drop_v4' => 'IPv4', 'drop_v6' => 'IPv6', 'asndrop' => 'ASN-DROP'];
    $pary  = [];
    if (preg_match_all('/([A-Za-z0-9_]+)=(\d+)/', $m[1], $czesci, PREG_SET_ORDER)) {
        foreach ($czesci as $c) {
            $pary[] = [$nazwy[$c[1]] ?? $c[1], $fmtLiczba((int) $c[2]), ''];
        }
    }
    $pary[] = ['trafienia', $fmtLiczba((int) $m[2]), (int) $m[2] > 0 ? 'text-danger' : ''];
    $pary[] = ['nowe', $fmtLiczba((int) $m[3]), (int) $m[3] > 0 ? 'text-danger' : ''];
    $pary[] = ['zdjęte', $fmtLiczba((int) $m[4]), (int) $m[4] > 0 ? 'text-success' : ''];

    return $pary;
};
$aktywne   = array_values(array_filter($trafienia, static fn ($t) => \App\Models\PluginModel::jakoBool($t['aktywne'] ?? false)));
[$wynikKlasa, $wynikTekst] = match ($wynik) {
    'czysto'    => ['text-bg-success', 'czysto'],
    'trafienie' => ['text-bg-danger', 'TRAFIENIE (' . count($aktywne) . ')'],
    default     => ['text-bg-secondary', 'brak udanego pobrania'],
};
?>

<div class="mb-4">
    <nav aria-label="Okruszki" class="mb-2">
        <ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= site_url('/') ?>">Przegląd</a></li>
            <li class="breadcrumb-item active" aria-current="page">Spamhaus DROP</li>
        </ol>
    </nav>
    <h1 class="h3 mb-1">Spamhaus DROP <span class="badge <?= $wynikKlasa ?> align-middle fs-6" id="spamhaus-wynik"><?= esc($wynikTekst) ?></span></h1>
    <p class="text-body-secondary mb-0">
        Listy DROP (IPv4), DROPv6 i ASN-DROP pobierane raz na dobę i dopasowane lokalnie do prefiksów AS
        (<a href="<?= site_url('admin/ustawienia') ?>">as198050_cidry</a>) i ASN. Trafienie tworzy incydent (źródło Spamhaus DROP).
    </p>
</div>

<?php if (! empty($blad)): ?>
    <div class="alert alert-danger border-0 shadow-sm rounded-3" role="alert"><?= esc((string) $blad) ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm rounded-3 mb-4" id="spamhaus-karta">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-sm-6 col-lg-3">
                <div class="text-body-secondary small mb-1">Status wtyczki</div>
                <span class="badge <?= $healthOk === true ? 'text-bg-success' : ($healthOk === false ? 'text-bg-danger' : 'text-bg-secondary') ?>">
                    <?= $healthOk === true ? 'OK' : ($healthOk === false ? 'błąd' : 'brak przebiegu') ?>
                </span>
                <?php if ($healthMsg !== '' && $podsumowanie === null): ?><div class="small text-body-secondary mt-1 text-break"><?= esc($healthMsg) ?></div><?php endif; ?>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="text-body-secondary small mb-1">Ostatnie udane pobranie</div>
                <div class="fw-semibold"><?= $okAt !== '' ? esc($okAt) : 'jeszcze nie było' ?></div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="text-body-secondary small mb-1">Data pliku (metadane)</div>
                <div class="fw-semibold"><?= $plikData !== '' ? esc($plikData) : '—' ?></div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="text-body-secondary small mb-1">Następne pobranie</div>
                <div class="fw-semibold"><?= $nastepne !== '' ? esc($nastepne) : 'przy najbliższym cronie' ?></div>
                <?php if ($bledy > 0): ?>
                    <div class="small text-danger">błędy z rzędu: <?= $bledy ?> — <?= esc((string) ($stan['ostatni_blad'] ?? '')) ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($podsumowanie !== null): ?>
            <div class="row row-cols-2 row-cols-md-4 g-3 mt-1 pt-3 border-top" id="spamhaus-podsumowanie">
                <?php foreach ($podsumowanie as [$etykieta, $wartosc, $klasa]): ?>
                    <div class="col">
                        <div class="text-body-secondary small mb-1"><?= esc($etykieta) ?></div>
                        <div class="fw-semibold <?= esc($klasa, 'attr') ?>"><?= esc($wartosc) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<h2 class="h5">Pliki</h2>
<div class="card border-0 shadow-sm rounded-3 mb-4"><div class="card-body p-0"><div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
        <thead class="table-light"><tr><th class="ps-3">Lista</th><th>Wpisów</th><th>Data pliku</th><th>Pobrano</th><th class="pe-3">Copyright</th></tr></thead>
        <tbody>
        <?php if ($pliki === []): ?>
            <tr><td colspan="5" class="ps-3 text-body-secondary">Brak udanego pobrania.</td></tr>
        <?php endif; ?>
        <?php foreach ($pliki as $p): ?>
            <tr>
                <td class="ps-3"><a href="<?= esc((string) ($p['url'] ?? ''), 'attr') ?>" rel="noopener noreferrer" target="_blank"><?= esc((string) $p['lista']) ?></a></td>
                <td><?= (int) ($p['rekordy'] ?? 0) ?></td>
                <td class="text-nowrap"><?= esc(\App\Libraries\CzasUi::dataGodzina((string) ($p['plik_timestamp'] ?? ''))) ?></td>
                <td class="text-nowrap"><?= esc(\App\Libraries\CzasUi::dataGodzina((string) ($p['pobrano_at'] ?? ''))) ?></td>
                <td class="pe-3 small"><?= esc((string) ($p['copyright'] ?? '')) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div></div></div>

<h2 class="h5">Trafienia</h2>
<div class="card border-0 shadow-sm rounded-3 mb-4"><div class="card-body p-0"><div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
        <thead class="table-light"><tr><th class="ps-3">Stan</th><th>Lista</th><th>Wpis</th><th>SBL</th><th>Nasz prefiks</th><th>Od</th><th class="pe-3">Ostatnio / zdjęte</th></tr></thead>
        <tbody>
        <?php if ($trafienia === []): ?>
            <tr><td colspan="7" class="ps-3 text-body-secondary">Brak trafień<?= $wynik === 'czysto' ? ' — nasze prefiksy i ASN nie występują na listach DROP.' : '.' ?></td></tr>
        <?php endif; ?>
        <?php foreach ($trafienia as $t): ?>
            <?php $akt = \App\Models\PluginModel::jakoBool($t['aktywne'] ?? false); ?>
            <tr>
                <td class="ps-3"><span class="badge <?= $akt ? 'text-bg-danger' : 'text-bg-secondary' ?>"><?= $akt ? 'aktywne' : 'zdjęte' ?></span></td>
                <td><?= esc((string) $t['lista']) ?></td>
                <td class="font-monospace"><?= esc((string) $t['wpis']) ?></td>
                <td><?= esc((string) ($t['sblid'] ?? '')) ?></td>
                <td class="font-monospace">
                    <?php if (! empty($t['incydent_id'])): ?>
                        <a href="<?= site_url('ip/' . rawurlencode((string) $t['podmiot']) . '/incydent/' . (int) $t['incydent_id']) ?>"><?= esc((string) $t['nasz_prefiks']) ?></a>
                    <?php else: ?>
                        <?= esc((string) $t['nasz_prefiks']) ?>
                    <?php endif; ?>
                </td>
                <td class="text-nowrap small"><?= esc(\App\Libraries\CzasUi::dataGodzina((string) ($t['pierwsze_at'] ?? ''))) ?></td>
                <td class="pe-3 text-nowrap small"><?= esc(\App\Libraries\CzasUi::dataGodzina((string) ($akt ? ($t['ostatnie_at'] ?? '') : ($t['zdjete_at'] ?? '')))) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div></div></div>

<h2 class="h5">Historia</h2>
<div class="card border-0 shadow-sm rounded-3 mb-4"><div class="card-body p-0"><div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
        <thead class="table-light"><tr><th class="ps-3">Czas</th><th>Zdarzenie</th><th class="pe-3">Opis</th></tr></thead>
        <tbody>
        <?php if ($historia === []): ?>
            <tr><td colspan="3" class="ps-3 text-body-secondary">Brak wpisów.</td></tr>
        <?php endif; ?>
        <?php foreach ($historia as $h): ?>
            <?php $z = (string) ($h['zdarzenie'] ?? ''); ?>
            <tr>
                <td class="ps-3 text-nowrap small"><?= esc(\App\Libraries\CzasUi::dataGodzina((string) ($h['at'] ?? ''))) ?></td>
                <td><span class="badge <?= match ($z) { 'nowe' => 'text-bg-danger', 'zdjete' => 'text-bg-success', 'blad' => 'text-bg-warning', default => 'text-bg-secondary' } ?>"><?= esc($z) ?></span></td>
                <td class="pe-3 small">
                    <?php $paryOpisu = $opisHistorii((string) ($h['opis'] ?? '')); ?>
                    <?php if ($paryOpisu === null): ?>
                        <span class="text-break"><?= esc((string) ($h['opis'] ?? '')) ?></span>
                    <?php else: ?>
                        <?php foreach (['listy' => array_slice($paryOpisu, 0, -3), 'przebieg' => array_slice($paryOpisu, -3)] as $linia): ?>
                            <div class="d-flex flex-wrap column-gap-2 row-gap-1 align-items-baseline">
                                <?php $pierwsza = true; ?>
                                <?php foreach ($linia as [$etykieta, $wartosc, $klasa]): ?>
                                    <?php if (! $pierwsza): ?><span class="text-body-secondary" aria-hidden="true">·</span><?php endif; ?>
                                    <?php $pierwsza = false; ?>
                                    <span><span class="text-body-secondary"><?= esc($etykieta) ?></span> <span class="fw-semibold <?= esc($klasa, 'attr') ?>"><?= esc($wartosc) ?></span></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div></div></div>

<p class="small text-body-secondary border-top pt-2" id="spamhaus-atrybucja">
    Źródło: Spamhaus DROP (<a href="https://www.spamhaus.org/blocklists/do-not-route-or-peer/" rel="noopener noreferrer" target="_blank">spamhaus.org</a>),
    plik z <?= $plikData !== '' ? esc($plikData) : '—' ?>, <?= esc(\McsPluginy\spamhaus\Plugin::ATRYBUCJA) ?>.
    Warunki: <a href="https://www.spamhaus.org/blocklists/drop-fair-use-policy/" rel="noopener noreferrer" target="_blank">DROP Fair Use Policy</a>.
</p>

<?= $this->endSection() ?>
