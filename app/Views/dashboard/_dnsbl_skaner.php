<?php
/**
 * Skaner DNSBL (plugin dnsbl, E2) — formularz, kafelki, tabela per strefa, szczegóły per IP.
 *
 * @var array<string, mixed> $skaner
 */
$sk        = is_array($skaner ?? null) ? $skaner : [];
$skan      = is_array($sk['skan'] ?? null) ? $sk['skan'] : null;
$strefyS   = is_array($sk['strefy'] ?? null) ? $sk['strefy'] : [];
$szcz      = is_array($sk['szczegoly'] ?? null) ? $sk['szczegoly'] : null;
$ostatnie  = is_array($sk['ostatnie'] ?? null) ? $sk['ostatnie'] : [];
$filtr     = (string) ($sk['filtr'] ?? 'listed');
$strona    = (int) ($sk['strona'] ?? 1);
$naStrone  = (int) ($sk['naStrone'] ?? 50);
$prefiksy  = is_array($sk['prefiksy'] ?? null) ? $sk['prefiksy'] : [];
$trend     = is_array($sk['trend'] ?? null) ? $sk['trend'] : [];
$trwa      = $skan !== null && in_array($skan['status'], ['oczekuje', 'w_toku'], true);
$gotowy    = $skan !== null && $skan['status'] === 'zakonczony';
$urlBaza   = site_url('dashboard/dnsbl');
$statusPl  = [
    'ok' => 'OK', 'wylaczona_test' => 'wyłączona (test kontrolny)', 'przerwana' => 'przerwana (kod błędu)',
    'oczekuje' => 'oczekuje', 'w_toku' => 'w toku', 'zakonczony' => 'zakończony', 'blad' => 'błąd',
];
?>

<?php if (! empty($flashOk)): ?>
    <div class="alert alert-success border-0 shadow-sm rounded-3" role="status"><?= esc((string) $flashOk) ?></div>
<?php endif; ?>
<?php if (! empty($flashBlad)): ?>
    <div class="alert alert-danger border-0 shadow-sm rounded-3" role="alert"><?= esc((string) $flashBlad) ?></div>
<?php endif; ?>
<?php if (! empty($sk['blad'])): ?>
    <div class="alert alert-danger border-0 shadow-sm rounded-3" role="alert"><?= esc((string) $sk['blad']) ?></div>
<?php endif; ?>

<form class="card border-0 shadow-sm rounded-3 mb-4" method="post" action="<?= site_url('dashboard/dnsbl/skan') ?>" id="dnsbl-skan-form">
    <?= csrf_field() ?>
    <div class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-md-8">
                <label class="form-label" for="dnsbl-zakres">IP albo CIDR (IPv4, /21–/32, tylko prefiksy AS198050)</label>
                <input class="form-control font-monospace" id="dnsbl-zakres" type="text" name="zakres" required
                       pattern="[0-9./,; ]+" maxlength="400"
                       value="<?= esc((string) old('zakres', $skan['zakres'] ?? '')) ?>" placeholder="np. 91.231.70.0/23 albo 91.231.70.187">
                <div class="form-text">
                    Prefiksy z ustawień (as198050_cidry):
                    <span class="font-monospace"><?= esc(implode(', ', $prefiksy)) ?></span>.
                    Wynik jest ważny 6 h (cache).
                    Limit: <?= (int) \App\Libraries\Dnsbl\LimitySkanuWww::maksIp() ?> IP na skan,
                    <?= (int) \App\Libraries\Dnsbl\LimitySkanuWww::limitDoba() ?> skanów z WWW na 24 h.
                </div>
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary" <?= $trwa ? 'disabled' : '' ?>>Skanuj</button>
            </div>
        </div>
    </div>
</form>

<?php if ($skan !== null): ?>
    <?php
    $fmtLiczbaSkanu = static fn (int $n): string => number_format($n, 0, ',', "\u{00A0}");
    $metaSkanu = [
        ['Skan', '#' . (int) $skan['id'], ''],
        ['Zakres', (string) $skan['zakres'], 'font-monospace'],
        ['Status', (string) ($statusPl[$skan['status']] ?? $skan['status']), ''],
    ];
    if (! empty($skan['strefa_filtr'])) {
        $metaSkanu[] = ['Strefa', (string) $skan['strefa_filtr'], ''];
    }
    if (! empty($skan['koniec_at'])) {
        $metaSkanu[] = ['Koniec', (string) $skan['koniec_at'], ''];
    }
    if (! empty($skan['uzytkownik'])) {
        $metaSkanu[] = ['Zlecił', (string) $skan['uzytkownik'], ''];
    }
    if ($gotowy && array_key_exists('nowe', $skan)) {
        $noweS = (int) $skan['nowe'];
        $zdjeteS = (int) ($skan['zdjete'] ?? 0);
        $incS = (int) ($skan['incydenty'] ?? 0);
        $metaSkanu[] = ['Nowe', $fmtLiczbaSkanu($noweS), $noweS > 0 ? 'text-danger' : ''];
        $metaSkanu[] = ['Zdjęte', $fmtLiczbaSkanu($zdjeteS), $zdjeteS > 0 ? 'text-success' : ''];
        $metaSkanu[] = ['Incydenty', $fmtLiczbaSkanu($incS), $incS > 0 ? 'text-danger' : ''];
    }
    ?>
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
        <?= view('partials/_metryki', ['pary' => $metaSkanu]) ?>
        <?php if ($gotowy): ?>
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-sm btn-outline-secondary" id="dnsbl-csv" href="<?= site_url('dashboard/dnsbl/skan/' . (int) $skan['id'] . '/csv') ?>">CSV</a>
                <form method="post" action="<?= site_url('dashboard/dnsbl/skan') ?>" class="m-0">
                    <?= csrf_field() ?>
                    <input type="hidden" name="zakres" value="<?= esc((string) $skan['zakres'], 'attr') ?>">
                    <input type="hidden" name="force" value="1">
                    <button type="submit" class="btn btn-sm btn-outline-secondary">Skanuj ponownie</button>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($trwa): ?>
        <?php
        $plan = max(1, (int) $skan['zapytan_plan']);
        $proc = (int) min(100, round(100 * (int) $skan['zapytan_wykonano'] / $plan));
        ?>
        <div class="card border-0 shadow-sm rounded-3 mb-4" id="dnsbl-postep"
             data-url="<?= site_url('dashboard/dnsbl/status/' . (int) $skan['id']) ?>">
            <div class="card-body">
                <div class="mb-2">Skan w toku… <span id="dnsbl-postep-txt"><?= (int) $skan['zapytan_wykonano'] ?> / <?= (int) $skan['zapytan_plan'] ?></span> zapytań</div>
                <div class="progress" role="progressbar" aria-label="Postęp skanu" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $proc ?>">
                    <div class="progress-bar progress-bar-striped progress-bar-animated" id="dnsbl-postep-bar" style="width: <?= $proc ?>%"></div>
                </div>
            </div>
        </div>
        <script>
        (function () {
            var box = document.getElementById('dnsbl-postep');
            if (!box) { return; }
            var url = box.getAttribute('data-url');
            function tick() {
                fetch(url, {credentials: 'same-origin', headers: {'Accept': 'application/json'}})
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (!d || !d.ok) { return; }
                        if (d.status !== 'oczekuje' && d.status !== 'w_toku') { window.location.reload(); return; }
                        var plan = Math.max(1, d.zapytan_plan || 0);
                        var p = Math.min(100, Math.round(100 * (d.zapytan_wykonano || 0) / plan));
                        document.getElementById('dnsbl-postep-bar').style.width = p + '%';
                        document.getElementById('dnsbl-postep-txt').textContent = (d.zapytan_wykonano || 0) + ' / ' + (d.zapytan_plan || 0);
                    })
                    .catch(function () {});
            }
            setInterval(tick, 2000);
        })();
        </script>
    <?php elseif ($gotowy && in_array($skan['fala'] ?? false, [true, 't', '1', 1], true)): ?>
        <div class="alert alert-warning border-0 shadow-sm rounded-3" role="alert" id="dnsbl-fala">
            <strong>Możliwy błąd skanu.</strong> <?= esc((string) ($skan['komunikat'] ?? '')) ?>
        </div>
    <?php elseif ($skan['status'] === 'blad'): ?>
        <div class="alert alert-danger border-0 shadow-sm rounded-3" role="alert">
            Skan zakończony błędem: <?= esc((string) ($skan['komunikat'] ?? '')) ?>
        </div>
    <?php endif; ?>

    <?php if ($gotowy): ?>
        <?php
        $kafle = [
            ['Total (IP)', (int) $skan['liczba_ip'], ''],
            ['Clean', (int) $skan['czyste'], 'text-success'],
            ['Blacklisted', (int) $skan['listowane'], (int) $skan['listowane'] > 0 ? 'text-danger' : ''],
            ['Duration', number_format(((int) $skan['czas_ms']) / 1000, 1, ',', ' ') . ' s', ''],
            ['Błędy', (int) $skan['bledy'], (int) $skan['bledy'] > 0 ? 'text-warning' : ''],
        ];
        ?>
        <div class="row g-3 mb-4" id="dnsbl-kafle">
            <?php foreach ($kafle as [$et, $wart, $cls]): ?>
                <div class="col-6 col-md-4 col-xl">
                    <div class="card border-0 shadow-sm rounded-3 h-100">
                        <div class="card-body">
                            <div class="text-body-secondary small mb-1"><?= esc($et) ?></div>
                            <div class="fs-3 fw-semibold <?= $cls ?>"><?= esc((string) $wart) ?></div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="card border-0 shadow-sm rounded-3 mb-4">
            <div class="card-header bg-transparent border-0 pt-3 px-3 pb-0"><h2 class="h6 mb-0">Wynik per strefa</h2></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="dnsbl-strefy">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Lista</th>
                                <th scope="col">Strefa</th>
                                <th scope="col">Status</th>
                                <th scope="col" class="text-end">Listed</th>
                                <th scope="col" class="text-end">Błędy</th>
                                <th scope="col" class="pe-3">Request removal</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($strefyS as $s): ?>
                            <?php
                            $st  = (string) ($s['status'] ?? '');
                            $cls = $st === 'ok' ? 'text-bg-success' : 'text-bg-warning';
                            $tr  = is_array($s['trafienia'] ?? null) ? $s['trafienia'] : [];
                            ?>
                            <tr>
                                <td>
                                    <?= esc((string) $s['nazwa']) ?>
                                    <?php if (! empty($s['informacyjna'])): ?><span class="badge text-bg-light border ms-1">informacyjna</span><?php endif; ?>
                                </td>
                                <td class="font-monospace small"><?= esc((string) $s['strefa']) ?></td>
                                <td>
                                    <span class="badge <?= $cls ?>"><?= esc($statusPl[$st] ?? $st) ?></span>
                                    <?php if (! empty($s['powod'])): ?><div class="small text-body-secondary"><?= esc((string) $s['powod']) ?></div><?php endif; ?>
                                </td>
                                <td class="text-end <?= (int) $s['listed'] > 0 ? 'text-danger fw-semibold' : '' ?>"><?= (int) $s['listed'] ?></td>
                                <td class="text-end <?= (int) $s['bledy'] > 0 ? 'text-warning fw-semibold' : '' ?>"><?= (int) $s['bledy'] ?></td>
                                <td class="pe-3 small">
                                    <?php if ($tr === []): ?>
                                        <span class="text-body-secondary">—</span>
                                    <?php else: ?>
                                        <?php foreach (array_slice($tr, 0, 10) as $t): ?>
                                            <a class="btn btn-sm btn-outline-danger mb-1"
                                               href="<?= esc(\App\Libraries\Dnsbl\DnsblNarzedzia::bezpiecznyUrl(\App\Libraries\Dnsbl\DnsblNarzedzia::urlDelisting((string) $s['url'], (string) $t['ip'])), 'attr') ?>"
                                               target="_blank" rel="noopener noreferrer"
                                               title="<?= esc((string) ($t['txt'] ?? ''), 'attr') ?>">
                                                Request removal <?= esc((string) $t['ip']) ?>
                                            </a>
                                        <?php endforeach; ?>
                                        <?php if (count($tr) > 10): ?><div class="text-body-secondary">+<?= count($tr) - 10 ?> więcej (szczegóły niżej)</div><?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php if ($szcz !== null): ?>
            <?php
            $licz = $szcz['liczniki'];
            $etykF = ['all' => 'All', 'listed' => 'Listed', 'clean' => 'Clean', 'bledy' => 'Błędy'];
            $q = static fn (array $extra): string => $urlBaza . '?' . http_build_query(array_merge(['skan' => (int) $skan['id'], 'f' => $filtr], $extra));
            $stron = max(1, (int) ceil($szcz['razem'] / max(1, $naStrone)));
            ?>
            <div class="card border-0 shadow-sm rounded-3 mb-4" id="dnsbl-szczegoly">
                <div class="card-header bg-transparent border-0 pt-3 px-3 pb-2 d-flex flex-wrap gap-2 align-items-center">
                    <h2 class="h6 mb-0 me-2">Szczegóły per IP</h2>
                    <?php foreach ($etykF as $k => $e): ?>
                        <a class="btn btn-sm <?= $filtr === $k ? 'btn-primary' : 'btn-outline-secondary' ?>"
                           href="<?= esc($q(['f' => $k, 'strona' => 1]), 'attr') ?>"><?= esc($e) ?> (<?= (int) ($licz[$k] ?? 0) ?>)</a>
                    <?php endforeach; ?>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th scope="col" class="ps-3">IP</th>
                                    <th scope="col">Status</th>
                                    <th scope="col">Listy</th>
                                    <th scope="col" class="pe-3">Błędy</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if ($szcz['wiersze'] === []): ?>
                                <tr><td colspan="4" class="text-center text-body-secondary py-4">Brak pozycji dla filtra „<?= esc($etykF[$filtr] ?? $filtr) ?>”.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($szcz['wiersze'] as $w): ?>
                                <tr>
                                    <td class="ps-3 font-monospace"><a href="<?= site_url('ip/' . rawurlencode((string) $w['ip'])) ?>"><?= esc((string) $w['ip']) ?></a></td>
                                    <td>
                                        <?php if ($w['status'] === 'listed'): ?><span class="badge text-bg-danger">listed</span>
                                        <?php elseif ($w['status'] === 'bledy'): ?><span class="badge text-bg-warning">błąd</span>
                                        <?php else: ?><span class="badge text-bg-success">clean</span><?php endif; ?>
                                    </td>
                                    <td class="small">
                                        <?php foreach ($w['listy'] as $l): ?>
                                            <a class="badge <?= $l['info'] ? 'text-bg-secondary' : 'text-bg-danger' ?> text-decoration-none me-1"
                                               href="<?= esc(\App\Libraries\Dnsbl\DnsblNarzedzia::bezpiecznyUrl((string) $l['url']), 'attr') ?>" target="_blank" rel="noopener noreferrer"
                                               title="Request removal (<?= esc((string) $l['kody'], 'attr') ?>)"><?= esc((string) $l['kod']) ?></a>
                                        <?php endforeach; ?>
                                        <?php if ($w['listy'] === []): ?><span class="text-body-secondary">—</span><?php endif; ?>
                                    </td>
                                    <td class="small pe-3">
                                        <?php foreach ($w['bledy'] as $b): ?>
                                            <span class="badge text-bg-warning me-1" title="<?= esc((string) $b['blad'], 'attr') ?>"><?= esc((string) $b['kod']) ?></span>
                                        <?php endforeach; ?>
                                        <?php if ($w['bledy'] === []): ?><span class="text-body-secondary">—</span><?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php if ($stron > 1): ?>
                    <div class="card-footer bg-transparent border-0 d-flex gap-2 align-items-center small">
                        <?php if ($strona > 1): ?><a class="btn btn-sm btn-outline-secondary" href="<?= esc($q(['strona' => $strona - 1]), 'attr') ?>">« Poprzednia</a><?php endif; ?>
                        <span>Strona <?= $strona ?> z <?= $stron ?> (<?= (int) $szcz['razem'] ?> pozycji)</span>
                        <?php if ($strona < $stron): ?><a class="btn btn-sm btn-outline-secondary" href="<?= esc($q(['strona' => $strona + 1]), 'attr') ?>">Następna »</a><?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>

<?php if ($trend !== []): ?>
    <?php
    $maxT = max(1, max(array_column($trend, 'listowane')));
    $n    = count($trend);
    $szer = 600;
    $wys  = 120;
    $krok = $szer / max(1, $n);
    $slup = max(2, min(16, $krok - 2));
    ?>
    <div class="card border-0 shadow-sm rounded-3 mb-4" id="dnsbl-trend">
        <div class="card-header bg-transparent border-0 pt-3 px-3 pb-0">
            <h2 class="h6 mb-0">Listowane IP w AS — ostatnie <?= $n ?> pełnych skanów</h2>
            <div class="small text-body-secondary">Pełny skan prefiksów as198050_cidry (nocny lub ręczny). Bez stref informacyjnych.</div>
        </div>
        <div class="card-body">
            <svg viewBox="0 0 <?= $szer ?> <?= $wys + 18 ?>" width="100%" height="<?= $wys + 18 ?>" role="img" aria-label="Trend liczby listowanych IP" preserveAspectRatio="none">
                <line x1="0" y1="<?= $wys ?>" x2="<?= $szer ?>" y2="<?= $wys ?>" stroke="currentColor" stroke-opacity=".25" stroke-width="1"/>
                <?php foreach ($trend as $i => $t): ?>
                    <?php
                    $h = $t['listowane'] > 0 ? max(2, $wys * $t['listowane'] / $maxT) : 0;
                    $x = $i * $krok + ($krok - $slup) / 2;
                    ?>
                    <a href="<?= esc($urlBaza . '?skan=' . $t['id'], 'attr') ?>">
                        <rect x="<?= round($x, 1) ?>" y="0" width="<?= round($krok, 1) ?>" height="<?= $wys ?>" fill="transparent"/>
                        <rect x="<?= round($x, 1) ?>" y="<?= round($wys - $h, 1) ?>" width="<?= round($slup, 1) ?>" height="<?= round($h, 1) ?>" rx="2" fill="var(--bs-primary)">
                        </rect>
                        <title>Skan #<?= $t['id'] ?> (<?= esc($t['tryb']) ?>) <?= esc($t['koniec_at']) ?>: <?= $t['listowane'] ?> listowanych IP</title>
                    </a>
                <?php endforeach; ?>
                <text x="0" y="<?= $wys + 14 ?>" font-size="11" fill="currentColor" fill-opacity=".6"><?= esc(substr((string) $trend[0]['koniec_at'], 0, 10)) ?></text>
                <text x="<?= $szer ?>" y="<?= $wys + 14 ?>" font-size="11" text-anchor="end" fill="currentColor" fill-opacity=".6"><?= esc(substr((string) $trend[$n - 1]['koniec_at'], 0, 10)) ?> · max <?= $maxT ?></text>
            </svg>
        </div>
    </div>
<?php endif; ?>

<?php if ($ostatnie !== []): ?>
    <details class="mb-4">
        <summary class="small text-body-secondary">Ostatnie skany (<?= count($ostatnie) ?>)</summary>
        <div class="table-responsive mt-2">
            <table class="table table-sm small mb-0">
                <thead><tr><th>#</th><th>Tryb</th><th>Zakres</th><th>Status</th><th>Listed</th><th>Nowe/zdjęte</th><th>Błędy</th><th>Koniec</th><th>Zlecił</th></tr></thead>
                <tbody>
                <?php foreach ($ostatnie as $o): ?>
                    <tr>
                        <td><a href="<?= esc($urlBaza . '?skan=' . (int) $o['id'], 'attr') ?>">#<?= (int) $o['id'] ?></a></td>
                        <td><?= esc((string) ($o['tryb'] ?? '')) ?></td>
                        <td class="font-monospace text-truncate" style="max-width: 24rem"><?= esc((string) $o['zakres']) ?><?= ! empty($o['strefa_filtr']) ? ' [' . esc((string) $o['strefa_filtr']) . ']' : '' ?></td>
                        <td><?= esc($statusPl[$o['status']] ?? (string) $o['status']) ?></td>
                        <td><?= (int) $o['listowane'] ?></td>
                        <td><?= (int) ($o['nowe'] ?? 0) ?>/<?= (int) ($o['zdjete'] ?? 0) ?></td>
                        <td><?= (int) $o['bledy'] ?></td>
                        <td><?= esc((string) ($o['koniec_at'] ?? '—')) ?></td>
                        <td><?= esc((string) $o['uzytkownik']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </details>
<?php endif; ?>
