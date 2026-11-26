<?= $this->extend('layouts/mcs') ?>
<?= $this->section('content') ?>

<?php
$filtry = is_array($filtry ?? null) ? $filtry : [
    'okno'              => (int) ($okno ?? 7),
    'min_download_mbps' => 500,
    'min_upload_mbps'   => 500,
    'cidr'              => '',
    'sort'              => 'download',
    'top_n'             => 20,
    'tylko_as'          => true,
    'wszystkie'         => false,
];
$okno     = (int) ($filtry['okno'] ?? 7);
$asAsn    = (int) ($asAsn ?? \App\Libraries\Siec\AsMatcher::asn());
$kpi      = is_array($kpi ?? null) ? $kpi : [];
$agregaty = is_array($agregaty ?? null) ? $agregaty : [];
$dzienne  = is_array($dzienne ?? null) ? $dzienne : [];
$anomalie = is_array($anomalie ?? null) ? $anomalie : [
    'liczba_powyzej'    => 0,
    'liczba_pasujacych' => 0,
    'top_testy'         => [],
    'cidr_peaki'        => [],
];

$mbps = static function (mixed $kbps): string {
    if ($kbps === null || $kbps === '') {
        return '—';
    }
    $v = ((float) $kbps) / 1000;
    if ($v >= 1000) {
        return number_format($v / 1000, 2, ',', ' ') . ' Gb/s';
    }

    return number_format($v, 1, ',', ' ') . ' Mb/s';
};
$ms = static function (mixed $wartosc): string {
    if ($wartosc === null || $wartosc === '') {
        return '—';
    }

    return number_format((float) $wartosc, 1, ',', ' ') . ' ms';
};
$ratio = static function (mixed $wartosc): string {
    if ($wartosc === null || $wartosc === '') {
        return '—';
    }

    return '×' . number_format((float) $wartosc, 1, ',', ' ');
};
$oknoEtykieta = match ($okno) {
    0       => 'Cała historia',
    1       => '1 dzień',
    default => $okno . ' dni',
};
$formAkcja   = site_url('dashboard/speedtest');
$wyczyscHref = site_url('dashboard/speedtest') . '?okno=7&min_download_mbps=500&min_upload_mbps=500&sort=download&tylko_as=1';
$minDown     = (int) ($filtry['min_download_mbps'] ?? 500);
$minUp       = (int) ($filtry['min_upload_mbps'] ?? 500);
$sort        = (string) ($filtry['sort'] ?? 'download');
$cidrFiltr   = (string) ($filtry['cidr'] ?? '');
$tylkoAs     = (bool) ($filtry['tylko_as'] ?? true);
$wszystkie   = (bool) ($filtry['wszystkie'] ?? false);
$topN        = (int) ($filtry['top_n'] ?? 20);
$liczbaPasuj = (int) ($anomalie['liczba_pasujacych'] ?? count($anomalie['top_testy'] ?? []));
$pokazano    = count($anomalie['top_testy'] ?? []);
$queryBaza   = [
    'okno'              => $okno,
    'min_download_mbps' => $minDown,
    'min_upload_mbps'   => $minUp,
    'cidr'              => $cidrFiltr,
    'sort'              => $sort,
    'tylko_as'          => $tylkoAs ? '1' : '0',
    'wszystkie'         => $wszystkie ? '1' : '0',
];
$presetHref  = $formAkcja . '?' . http_build_query(array_merge($queryBaza, [
    'min_download_mbps' => 900,
    'min_upload_mbps'   => 900,
    'wszystkie'         => '0',
]));
$wiecejHref  = $formAkcja . '?' . http_build_query(array_merge($queryBaza, [
    'top_n' => min(500, max(100, $topN * 2)),
]));
$pokazWyczysc = isset($pokazWyczysc)
    ? (bool) $pokazWyczysc
    : $filtry !== \App\Libraries\Enrichment\SpeedtestSerwis::parsujFiltry([]);
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <nav aria-label="Okruszki" class="mb-2">
            <ol class="breadcrumb mb-0 small">
                <li class="breadcrumb-item"><a href="<?= site_url('/') ?>">Przegląd</a></li>
                <li class="breadcrumb-item active" aria-current="page">Speedtest</li>
            </ol>
        </nav>
        <h1 class="h3 mb-1">Speedtest — agregaty /24 i /48</h1>
        <p class="text-body-secondary mb-0">
            Raport Ookla <strong>Servers reporting</strong>: testy klientów AS<?= $asAsn ?> do Waszego serwera.
            Ookla anonimizuje adresy do /24 (IPv4) i /48 (IPv6).
        </p>
    </div>
    <span class="badge text-bg-primary-subtle text-primary-emphasis border border-primary-subtle px-3 py-2">
        Okno: <?= esc($oknoEtykieta) ?>
    </span>
</div>

<?php if (! empty($flashOk)): ?>
    <div class="alert alert-success border-0 shadow-sm rounded-3" role="status"><?= esc($flashOk) ?></div>
<?php endif; ?>
<?php if (! empty($flashBlad)): ?>
    <div class="alert alert-danger border-0 shadow-sm rounded-3" role="alert"><?= esc($flashBlad) ?></div>
<?php endif; ?>
<?php if (! empty($blad)): ?>
    <div class="alert alert-warning border-0 shadow-sm rounded-3" role="alert"><?= esc($blad) ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm rounded-3 mb-4 mcs-filter-card">
    <div class="card-body">
        <form class="row g-3 align-items-end" method="get" action="<?= esc($formAkcja) ?>" aria-label="Filtry Speedtest">
            <div class="col-6 col-md-auto">
                <label class="form-label mb-1" for="filtr-okno">Okno</label>
                <select class="form-select" id="filtr-okno" name="okno">
                    <option value="7" <?= $okno === 7 ? 'selected' : '' ?>>7 dni</option>
                    <option value="30" <?= $okno === 30 ? 'selected' : '' ?>>30 dni</option>
                    <option value="0" <?= $okno === 0 ? 'selected' : '' ?>>Cała historia</option>
                </select>
            </div>
            <div class="col-6 col-md-auto" style="min-width:8rem">
                <label class="form-label mb-1" for="filtr-min-down">Min. pobieranie (Mb/s)</label>
                <input class="form-control" id="filtr-min-down" type="number" name="min_download_mbps"
                       min="0" max="100000" step="1" value="<?= $minDown ?>"
                       placeholder="np. 500" inputmode="numeric">
            </div>
            <div class="col-6 col-md-auto" style="min-width:8rem">
                <label class="form-label mb-1" for="filtr-min-up">Min. wysyłanie (Mb/s)</label>
                <input class="form-control" id="filtr-min-up" type="number" name="min_upload_mbps"
                       min="0" max="100000" step="1" value="<?= $minUp ?>"
                       placeholder="np. 500" inputmode="numeric">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label mb-1" for="filtr-cidr">CIDR / prefiks</label>
                <input class="form-control font-monospace" id="filtr-cidr" type="text" name="cidr"
                       value="<?= esc($cidrFiltr, 'attr') ?>"
                       placeholder="np. 91.231.80" autocomplete="off">
            </div>
            <div class="col-6 col-md-auto">
                <label class="form-label mb-1" for="filtr-sort">Sortuj</label>
                <select class="form-select" id="filtr-sort" name="sort">
                    <option value="download" <?= $sort === 'download' ? 'selected' : '' ?>>Maks. pobieranie</option>
                    <option value="upload" <?= $sort === 'upload' ? 'selected' : '' ?>>Maks. wysyłanie</option>
                    <option value="data" <?= $sort === 'data' ? 'selected' : '' ?>>Data</option>
                    <option value="testy" <?= $sort === 'testy' ? 'selected' : '' ?>>Liczba testów</option>
                </select>
            </div>
            <div class="col-12 col-md-auto d-flex flex-wrap gap-2">
                <?= view('partials/_akcje_filtrow', [
                    'wyczyscHref'  => $wyczyscHref,
                    'pokazWyczysc' => $pokazWyczysc,
                ], ['saveData' => false]) ?>
                <a class="btn btn-outline-primary" href="<?= esc($presetHref) ?>">
                    <i class="bi bi-speedometer2 me-1" aria-hidden="true"></i>Preset ≥900
                </a>
            </div>
            <div class="col-12">
                <div class="d-flex flex-wrap gap-4">
                    <div class="form-check">
                        <input type="hidden" name="tylko_as" value="0">
                        <input class="form-check-input" type="checkbox" value="1" name="tylko_as"
                               id="filtr-tylko-as" <?= $tylkoAs ? 'checked' : '' ?>>
                        <label class="form-check-label" for="filtr-tylko-as">
                            Tylko AS<?= $asAsn ?>
                            <span class="text-body-secondary small">(CIDR z ustawień)</span>
                        </label>
                    </div>
                    <div class="form-check">
                        <input type="hidden" name="wszystkie" value="0">
                        <input class="form-check-input" type="checkbox" value="1" name="wszystkie"
                               id="filtr-wszystkie" <?= $wszystkie ? 'checked' : '' ?>>
                        <label class="form-check-label" for="filtr-wszystkie">
                            Pokaż wszystkie pomiary
                            <span class="text-body-secondary small">(nie tylko powyżej progów)</span>
                        </label>
                    </div>
                </div>
            </div>
            <div class="col-12">
                <p class="form-text mb-0">
                    Progi określają pomiary wysycenia; wartość 0 wyłącza dany próg.
                    <button type="button" class="btn btn-link btn-sm p-0 ms-1 align-baseline text-body-secondary"
                            aria-label="Jak działają progi Speedtest"
                            title="Pomiar trafia do KPI wysycenia, gdy pobieranie lub wysyłanie osiąga próg. Pole „Pokaż wszystkie pomiary” rozszerza listę, ale zachowuje progi KPI."
                            data-bs-toggle="tooltip" data-bs-placement="top">
                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                        <span class="visually-hidden">Jak działają progi Speedtest</span>
                    </button>
                </p>
            </div>
        </form>
    </div>
</div>

<div class="row g-3 g-lg-4 mb-4" aria-label="Wskaźniki Speedtest">
    <div class="col-6 col-lg">
        <div class="card border-0 shadow-sm rounded-3 h-100">
            <div class="card-body d-flex align-items-start gap-3">
                <div class="rounded-3 bg-primary-subtle text-primary p-3">
                    <i class="bi bi-speedometer2 fs-5" aria-hidden="true"></i>
                </div>
                <div>
                    <div class="text-body-secondary small">Liczba testów</div>
                    <div class="fs-3 fw-semibold lh-1 mt-1"><?= (int) ($kpi['liczba_testow'] ?? 0) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg">
        <div class="card border-0 shadow-sm rounded-3 h-100">
            <div class="card-body d-flex align-items-start gap-3">
                <div class="rounded-3 bg-info-subtle text-info p-3">
                    <i class="bi bi-hdd-network-fill fs-5" aria-hidden="true"></i>
                </div>
                <div>
                    <div class="text-body-secondary small">Unikalne /24·/48</div>
                    <div class="fs-3 fw-semibold lh-1 mt-1"><?= (int) ($kpi['liczba_cidr'] ?? 0) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg">
        <div class="card border-0 shadow-sm rounded-3 h-100 border-warning-subtle">
            <div class="card-body d-flex align-items-start gap-3">
                <div class="rounded-3 bg-warning-subtle text-warning-emphasis p-3">
                    <i class="bi bi-lightning-charge-fill fs-5" aria-hidden="true"></i>
                </div>
                <div>
                    <div class="text-body-secondary small">Wysycenie portu (≥ progi)</div>
                    <div class="fs-3 fw-semibold lh-1 mt-1"><?= (int) ($anomalie['liczba_powyzej'] ?? 0) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg">
        <div class="card border-0 shadow-sm rounded-3 h-100">
            <div class="card-body d-flex align-items-start gap-3">
                <div class="rounded-3 bg-success-subtle text-success p-3">
                    <i class="bi bi-download fs-5" aria-hidden="true"></i>
                </div>
                <div>
                    <div class="text-body-secondary small">Pobieranie śr. / p50</div>
                    <div class="fs-5 fw-semibold lh-1 mt-1">
                        <?= esc($mbps($kpi['avg_download_kbps'] ?? null)) ?>
                        <span class="text-body-secondary fw-normal small">/</span>
                        <?= esc($mbps($kpi['p50_download_kbps'] ?? null)) ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg">
        <div class="card border-0 shadow-sm rounded-3 h-100">
            <div class="card-body d-flex align-items-start gap-3">
                <div class="rounded-3 bg-secondary-subtle text-secondary p-3">
                    <i class="bi bi-upload fs-5" aria-hidden="true"></i>
                </div>
                <div>
                    <div class="text-body-secondary small">Wysyłanie śr. / p50</div>
                    <div class="fs-5 fw-semibold lh-1 mt-1">
                        <?= esc($mbps($kpi['avg_upload_kbps'] ?? null)) ?>
                        <span class="text-body-secondary fw-normal small">/</span>
                        <?= esc($mbps($kpi['p50_upload_kbps'] ?? null)) ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<section class="mb-4">
    <div class="card border-0 shadow-sm rounded-3">
        <div class="card-header bg-transparent border-0 pt-3 px-3 pb-0 d-flex flex-wrap justify-content-between gap-2">
            <div>
                <?php if ($wszystkie): ?>
                    <h2 class="h5 mb-1">Pomiary</h2>
                    <p class="text-body-secondary small mb-0">
                        Wszystkie testy w filtrze (okno / AS / CIDR) — nie tylko powyżej progów wysycenia.
                    </p>
                <?php else: ?>
                    <h2 class="h5 mb-1">Najcięższe testy</h2>
                    <p class="text-body-secondary small mb-0">
                        Surowe wyniki powyżej progów (nie średnie /24) — kandydaci do spike’ów na porcie.
                    </p>
                <?php endif; ?>
            </div>
            <span class="badge text-bg-warning-subtle text-warning-emphasis border border-warning-subtle align-self-start">
                <?php if ($wszystkie): ?>
                    wszystkie · KPI ≥<?= $minDown ?>↓ / ≥<?= $minUp ?>↑
                <?php else: ?>
                    ≥<?= $minDown ?>↓ / ≥<?= $minUp ?>↑ Mb/s
                <?php endif; ?>
            </span>
        </div>
        <div class="card-body pt-3">
            <?php if (($anomalie['top_testy'] ?? []) === []): ?>
                <?= view('partials/_pusty_stan', [
                    'tekst' => $wszystkie
                        ? 'Brak pomiarów w wybranym filtrze.'
                        : 'Brak testów powyżej progów w wybranym oknie.',
                    'ikona' => 'bi-speedometer2',
                ], ['saveData' => false]) ?>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                        <tr>
                            <th scope="col">Czas</th>
                            <th scope="col">Sieć /24</th>
                            <th scope="col" class="text-end">Pobieranie</th>
                            <th scope="col" class="text-end">Wysyłanie</th>
                            <th scope="col" class="text-end">Ping</th>
                            <th scope="col">Test ID</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($anomalie['top_testy'] as $t): ?>
                            <?php
                            $downKbps = isset($t['download_kbps']) ? (int) $t['download_kbps'] : 0;
                            $upKbps   = isset($t['upload_kbps']) ? (int) $t['upload_kbps'] : 0;
                            $hot      = ($minDown > 0 && $downKbps >= $minDown * 1000)
                                || ($minUp > 0 && $upKbps >= $minUp * 1000);
                            ?>
                            <tr<?= ($wszystkie && $hot) ? ' class="table-warning"' : '' ?>>
                                <td class="text-nowrap small"><?= esc(\App\Libraries\CzasUi::pokaz((string) ($t['zaobserwowano_at'] ?? ''), '—')) ?></td>
                                <td class="font-monospace"><?= esc((string) ($t['cidr'] ?? '')) ?></td>
                                <td class="text-end fw-semibold"><?= esc($mbps($t['download_kbps'] ?? null)) ?></td>
                                <td class="text-end fw-semibold"><?= esc($mbps($t['upload_kbps'] ?? null)) ?></td>
                                <td class="text-end"><?= esc($ms($t['latency_ms'] ?? null)) ?></td>
                                <td class="font-monospace small text-body-secondary"><?= esc((string) ($t['test_id'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($liczbaPasuj > $pokazano): ?>
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
                        <p class="text-body-secondary small mb-0">
                            Pokazano <?= $pokazano ?> z <?= $liczbaPasuj ?>.
                        </p>
                        <a class="btn btn-sm btn-outline-primary" href="<?= esc($wiecejHref) ?>">Więcej</a>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</section>

<section class="mb-4">
    <div class="card border-0 shadow-sm rounded-3">
        <div class="card-header bg-transparent border-0 pt-3 px-3 pb-0">
            <h2 class="h5 mb-1">Sieci /24 ze szczytami</h2>
            <p class="text-body-secondary small mb-0">
                Maksimum względem średniej w oknie — wysoki stosunek szczytu do średniej sugeruje krótkie wysycenie jak na LibreNMS.
            </p>
        </div>
        <div class="card-body pt-3">
            <?php if (($anomalie['cidr_peaki'] ?? []) === []): ?>
                <?= view('partials/_pusty_stan', [
                    'tekst' => 'Brak sieci /24 z testami powyżej progów.',
                    'ikona' => 'bi-graph-up',
                ], ['saveData' => false]) ?>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                        <tr>
                            <th scope="col">Sieć /24</th>
                            <th scope="col" class="text-end">Powyżej</th>
                            <th scope="col" class="text-end">Testy</th>
                            <th scope="col" class="text-end">Pob. maks.</th>
                            <th scope="col" class="text-end">Pob. śr.</th>
                            <th scope="col" class="text-end">Szczyt ↓</th>
                            <th scope="col" class="text-end">Wysł. maks.</th>
                            <th scope="col" class="text-end">Wysł. śr.</th>
                            <th scope="col" class="text-end">Szczyt ↑</th>
                            <th scope="col">Ostatni</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($anomalie['cidr_peaki'] as $p): ?>
                            <tr>
                                <td class="font-monospace"><?= esc((string) ($p['cidr'] ?? '')) ?></td>
                                <td class="text-end"><?= (int) ($p['liczba_powyzej'] ?? 0) ?></td>
                                <td class="text-end"><?= (int) ($p['liczba_testow'] ?? 0) ?></td>
                                <td class="text-end fw-semibold"><?= esc($mbps($p['max_download_kbps'] ?? null)) ?></td>
                                <td class="text-end"><?= esc($mbps($p['avg_download_kbps'] ?? null)) ?></td>
                                <td class="text-end"><?= esc($ratio($p['peak_download_ratio'] ?? null)) ?></td>
                                <td class="text-end fw-semibold"><?= esc($mbps($p['max_upload_kbps'] ?? null)) ?></td>
                                <td class="text-end"><?= esc($mbps($p['avg_upload_kbps'] ?? null)) ?></td>
                                <td class="text-end"><?= esc($ratio($p['peak_upload_ratio'] ?? null)) ?></td>
                                <td class="text-nowrap small"><?= esc(\App\Libraries\CzasUi::pokaz((string) ($p['ostatni_test_at'] ?? ''), '—')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<section class="mb-4">
    <div class="card border-0 shadow-sm rounded-3">
        <div class="card-header bg-transparent border-0 pt-3 px-3 pb-0">
            <h2 class="h5 mb-0">Agregaty /24</h2>
        </div>
        <div class="card-body pt-3">
            <?php if ($agregaty === []): ?>
                <?= view('partials/_pusty_stan', [
                    'tekst' => 'Brak testów Speedtest w wybranym oknie.',
                    'ikona' => 'bi-speedometer2',
                ], ['saveData' => false]) ?>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                        <tr>
                            <th scope="col">Sieć /24</th>
                            <th scope="col" class="text-end">Testy</th>
                            <th scope="col" class="text-end">Pob. maks.</th>
                            <th scope="col" class="text-end">Pob. śr.</th>
                            <th scope="col" class="text-end">Pob. p50</th>
                            <th scope="col" class="text-end">Szczyt ↓</th>
                            <th scope="col" class="text-end">Wysł. maks.</th>
                            <th scope="col" class="text-end">Wysł. śr.</th>
                            <th scope="col" class="text-end">Wysł. p50</th>
                            <th scope="col" class="text-end">Szczyt ↑</th>
                            <th scope="col" class="text-end">Ping śr.</th>
                            <th scope="col">Ostatni test</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($agregaty as $w): ?>
                            <?php
                            $maxD = (int) ($w['max_download_kbps'] ?? 0);
                            $maxU = (int) ($w['max_upload_kbps'] ?? 0);
                            $hot  = ($minDown > 0 && $maxD >= $minDown * 1000)
                                || ($minUp > 0 && $maxU >= $minUp * 1000);
                            ?>
                            <tr<?= $hot ? ' class="table-warning"' : '' ?>>
                                <td class="font-monospace"><?= esc((string) ($w['cidr'] ?? '')) ?></td>
                                <td class="text-end"><?= (int) ($w['liczba_testow'] ?? 0) ?></td>
                                <td class="text-end fw-semibold"><?= esc($mbps($w['max_download_kbps'] ?? null)) ?></td>
                                <td class="text-end"><?= esc($mbps($w['avg_download_kbps'] ?? null)) ?></td>
                                <td class="text-end"><?= esc($mbps($w['p50_download_kbps'] ?? null)) ?></td>
                                <td class="text-end"><?= esc($ratio($w['peak_download_ratio'] ?? null)) ?></td>
                                <td class="text-end fw-semibold"><?= esc($mbps($w['max_upload_kbps'] ?? null)) ?></td>
                                <td class="text-end"><?= esc($mbps($w['avg_upload_kbps'] ?? null)) ?></td>
                                <td class="text-end"><?= esc($mbps($w['p50_upload_kbps'] ?? null)) ?></td>
                                <td class="text-end"><?= esc($ratio($w['peak_upload_ratio'] ?? null)) ?></td>
                                <td class="text-end"><?= esc($ms($w['avg_latency_ms'] ?? null)) ?></td>
                                <td class="text-nowrap small"><?= esc(\App\Libraries\CzasUi::pokaz((string) ($w['ostatni_test_at'] ?? ''), '—')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<section class="mb-4">
    <div class="card border-0 shadow-sm rounded-3">
        <div class="card-header bg-transparent border-0 pt-3 px-3 pb-0">
            <h2 class="h5 mb-0">Trend dzienny</h2>
        </div>
        <div class="card-body pt-3">
            <?php if ($dzienne === []): ?>
                <?= view('partials/_pusty_stan', [
                    'tekst' => 'Brak danych dziennych w wybranym oknie.',
                    'ikona' => 'bi-calendar3',
                ], ['saveData' => false]) ?>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                        <tr>
                            <th scope="col">Dzień</th>
                            <th scope="col" class="text-end">Testy</th>
                            <th scope="col" class="text-end">Pob. śr.</th>
                            <th scope="col" class="text-end">Wysł. śr.</th>
                            <th scope="col" class="text-end">Ping śr.</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($dzienne as $d): ?>
                            <tr>
                                <td class="text-nowrap"><?= esc((string) ($d['dzien'] ?? '')) ?></td>
                                <td class="text-end"><?= (int) ($d['liczba_testow'] ?? 0) ?></td>
                                <td class="text-end"><?= esc($mbps($d['avg_download_kbps'] ?? null)) ?></td>
                                <td class="text-end"><?= esc($mbps($d['avg_upload_kbps'] ?? null)) ?></td>
                                <td class="text-end"><?= esc($ms($d['avg_latency_ms'] ?? null)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<?= $this->endSection() ?>
