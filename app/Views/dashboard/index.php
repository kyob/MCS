<?= $this->extend('layouts/mcs') ?>
<?= $this->section('content') ?>

<?php
$trendy         = is_array($trendy ?? null) ? $trendy : [];
$trendyDni      = is_array($trendy['dni'] ?? null) ? $trendy['dni'] : [];
$trendyZrodla   = is_array($trendy['zrodla_suma'] ?? null) ? $trendy['zrodla_suma'] : [];
$maxObs         = max(1, (int) ($trendy['max_obserwacje'] ?? 0));
$sumaObs        = (int) ($trendy['suma_obserwacji'] ?? 0);
$queryEksport   = (string) ($queryEksport ?? 'okno=' . (int) $okno);
$qs             = $queryEksport !== '' ? '?' . $queryEksport : '';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Przegląd zagrożeń</h1>
        <p class="text-body-secondary mb-0">
            Triage IP z indeksu MCS — Shadowserver, Wanguard, SNDS, CERT N6 i inne źródła.
            KPI i trendy w wybranym oknie; ranking IP i skróty incydentów są na osobnych listach.
        </p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <span class="badge text-bg-primary-subtle text-primary-emphasis border border-primary-subtle px-3 py-2">
            Okno: <?= (int) $okno ?> <?= (int) $okno === 1 ? 'dzień' : 'dni' ?>
        </span>
        <?php if (! empty($asMatchWlaczone)): ?>
            <span class="badge text-bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle px-3 py-2">
                AS198050
            </span>
        <?php endif; ?>
        <?php if (! empty($filtrCidrEfekt)): ?>
            <span class="badge text-bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle px-3 py-2 font-monospace">
                <?= esc((string) $filtrCidrEfekt) ?>
            </span>
        <?php endif; ?>
        <a class="btn btn-sm btn-primary" href="<?= site_url('dashboard/kolejka') ?>">
            <i class="bi bi-list-check me-1" aria-hidden="true"></i>Kolejka dnia
        </a>
        <a class="btn btn-sm btn-outline-primary" href="<?= site_url('dashboard/problematyczne-ip') . $qs ?>">
            Problematyczne IP
        </a>
        <a class="btn btn-sm btn-outline-primary" href="<?= site_url('dashboard/incydenty') . $qs ?>">
            Ostatnie incydenty
        </a>
    </div>
</div>

<?php if (! empty($asMatchWlaczone)): ?>
    <div class="alert alert-primary border-0 shadow-sm rounded-3 py-2 mb-4" role="status">
        <i class="bi bi-funnel-fill me-1" aria-hidden="true"></i>
        Matchowanie AS198050 jest włączone — przegląd (KPI, ranking i ostatnie) obejmuje tylko IP
        z prefiksów CIDR w <a href="<?= site_url('admin/ustawienia') ?>" class="alert-link">ustawieniach</a>.
    </div>
<?php endif; ?>

<?php if (! empty($flashOk)): ?>
    <div class="alert alert-success border-0 shadow-sm rounded-3" role="status"><?= esc($flashOk) ?></div>
<?php endif; ?>
<?php if (! empty($flashBlad)): ?>
    <div class="alert alert-danger border-0 shadow-sm rounded-3" role="alert"><?= esc($flashBlad) ?></div>
<?php endif; ?>
<?php if (! empty($blad)): ?>
    <div class="alert alert-danger border-0 shadow-sm rounded-3" role="alert"><?= esc($blad) ?></div>
<?php endif; ?>

<?= $this->include('dashboard/_filtry') ?>

<div class="row g-3 g-lg-4 mb-4" aria-label="Wskaźniki">
    <div class="col-6 col-lg">
        <div class="card border-0 shadow-sm rounded-3 h-100 position-relative">
            <div class="card-body d-flex align-items-start gap-3">
                <div class="rounded-3 bg-primary-subtle text-primary p-3">
                    <i class="bi bi-exclamation-triangle-fill fs-5" aria-hidden="true"></i>
                </div>
                <div>
                    <div class="text-body-secondary small">Incydenty</div>
                    <div class="fs-3 fw-semibold lh-1 mt-1"><?= (int) $kpi['liczba_incydentow'] ?></div>
                </div>
            </div>
            <a class="stretched-link" href="<?= site_url('dashboard/incydenty') . $qs ?>"
               aria-label="Otwórz ostatnie incydenty"></a>
        </div>
    </div>
    <div class="col-6 col-lg">
        <div class="card border-0 shadow-sm rounded-3 h-100 position-relative">
            <div class="card-body d-flex align-items-start gap-3">
                <div class="rounded-3 bg-info-subtle text-info p-3">
                    <i class="bi bi-hdd-network-fill fs-5" aria-hidden="true"></i>
                </div>
                <div>
                    <div class="text-body-secondary small">Unikalne IP</div>
                    <div class="fs-3 fw-semibold lh-1 mt-1"><?= (int) $kpi['liczba_ip'] ?></div>
                </div>
            </div>
            <a class="stretched-link" href="<?= site_url('dashboard/problematyczne-ip') . $qs ?>"
               aria-label="Otwórz problematyczne IP"></a>
        </div>
    </div>
    <div class="col-6 col-lg">
        <div class="card border-0 shadow-sm rounded-3 h-100">
            <div class="card-body d-flex align-items-start gap-3">
                <div class="rounded-3 bg-warning-subtle text-warning-emphasis p-3">
                    <i class="bi bi-layers-fill fs-5" aria-hidden="true"></i>
                </div>
                <div>
                    <div class="text-body-secondary small">IP wieloźródłowe</div>
                    <div class="fs-3 fw-semibold lh-1 mt-1"><?= (int) $kpi['ip_wielozrodlowe'] ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg">
        <div class="card border-0 shadow-sm rounded-3 h-100">
            <div class="card-body d-flex align-items-start gap-3">
                <div class="rounded-3 bg-danger-subtle text-danger p-3">
                    <i class="bi bi-fire fs-5" aria-hidden="true"></i>
                </div>
                <div>
                    <div class="text-body-secondary small">critical / high</div>
                    <div class="fs-3 fw-semibold lh-1 mt-1 text-danger"><?= (int) $kpi['critical_high'] ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg">
        <div class="card border-0 shadow-sm rounded-3 h-100">
            <div class="card-body d-flex align-items-start gap-3">
                <div class="rounded-3 bg-secondary-subtle text-secondary p-3">
                    <i class="bi bi-speedometer2 fs-5" aria-hidden="true"></i>
                </div>
                <div>
                    <div class="text-body-secondary small">Max waga</div>
                    <div class="fs-3 fw-semibold lh-1 mt-1 <?= ! empty($kpi['max_waga']) ? 'waga-' . esc((string) $kpi['max_waga'], 'attr') : '' ?>">
                        <?= esc((string) ($kpi['max_waga'] ?? '—')) ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($sumaObs > 0): ?>
<section class="mb-4" aria-label="Trendy dzienne">
    <div class="card border-0 shadow-sm rounded-3">
        <div class="card-header bg-transparent border-0 pt-3 px-3 pb-0">
            <h2 class="h5 mb-1">Trendy dzienne z obserwacji</h2>
            <p class="small text-body-secondary mb-0">
                Wolumen obserwacji w oknie <?= (int) $okno ?> <?= (int) $okno === 1 ? 'dnia' : 'dni' ?>
                — <strong>nowe</strong> (pierwszy dzień incydentu) vs <strong>powtarzające</strong>, plus rozkład źródeł.
            </p>
        </div>
        <div class="card-body pt-3">
            <div class="row g-4">
                <div class="col-lg-8">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light">
                            <tr>
                                <th scope="col" style="width:7rem">Dzień</th>
                                <th scope="col">Wolumen</th>
                                <th scope="col" class="text-end" style="width:4rem">Nowe</th>
                                <th scope="col" class="text-end" style="width:5rem">Powt.</th>
                                <th scope="col" class="text-end" style="width:4rem">Σ</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($trendyDni as $dzien): ?>
                                <?php
                                $obs  = (int) ($dzien['obserwacje'] ?? 0);
                                $nowe = (int) ($dzien['nowe'] ?? 0);
                                $powt = (int) ($dzien['powtarzajace'] ?? 0);
                                $pct  = $obs > 0 ? (int) round(100 * $obs / $maxObs) : 0;
                                $pctN = ($obs > 0 && $pct > 0) ? (int) round($pct * $nowe / $obs) : 0;
                                $pctP = max(0, $pct - $pctN);
                                ?>
                                <tr>
                                    <td class="small text-nowrap font-monospace"><?= esc((string) ($dzien['dzien'] ?? '')) ?></td>
                                    <td>
                                        <div class="progress" style="height:1.1rem" role="img"
                                             aria-label="<?= $obs ?> obserwacji (<?= $nowe ?> nowych)">
                                            <?php if ($pctN > 0): ?>
                                                <div class="progress-bar bg-primary" style="width:<?= $pctN ?>%"></div>
                                            <?php endif; ?>
                                            <?php if ($pctP > 0): ?>
                                                <div class="progress-bar bg-primary-subtle text-primary-emphasis"
                                                     style="width:<?= $pctP ?>%"></div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="text-end small"><?= $nowe ?></td>
                                    <td class="text-end small"><?= $powt ?></td>
                                    <td class="text-end fw-semibold"><?= $obs ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="small text-body-secondary mt-2 mb-0">
                        Pasek: ciemniejszy = nowe, jaśniejszy = powtarzające. Skala względem dnia z max. wolumenem.
                    </p>
                </div>
                <div class="col-lg-4">
                    <h3 class="h6">Źródła (suma w oknie)</h3>
                    <?php if ($trendyZrodla === []): ?>
                        <p class="text-body-secondary small mb-0">Brak danych per źródło.</p>
                    <?php else: ?>
                        <ul class="list-group list-group-flush">
                            <?php
                            $maxZ = max(1, (int) max($trendyZrodla));
                            foreach ($trendyZrodla as $zKod => $zCnt):
                                $zPct = (int) round(100 * (int) $zCnt / $maxZ);
                                ?>
                                <li class="list-group-item bg-transparent px-0">
                                    <div class="d-flex justify-content-between small mb-1">
                                        <span><?= esc((string) $zKod) ?></span>
                                        <span class="fw-semibold"><?= (int) $zCnt ?></span>
                                    </div>
                                    <div class="progress" style="height:0.4rem">
                                        <div class="progress-bar bg-info" style="width:<?= $zPct ?>%"></div>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<?= $this->endSection() ?>
