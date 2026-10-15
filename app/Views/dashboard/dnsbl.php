<?= $this->extend('layouts/mcs') ?>
<?= $this->section('content') ?>

<?php
$kpi     = is_array($kpi ?? null) ? $kpi : ['listed' => 0, 'listy_uniq' => 0];
$wiersze = is_array($wiersze ?? null) ? $wiersze : [];
$lista   = (string) ($lista ?? '');
$cidr    = (string) ($cidr ?? '');
$blad    = $blad ?? null;
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <nav aria-label="Okruszki" class="mb-2">
            <ol class="breadcrumb mb-0 small">
                <li class="breadcrumb-item"><a href="<?= site_url('/') ?>">Przegląd</a></li>
                <li class="breadcrumb-item active" aria-current="page">DNSBL</li>
            </ol>
        </nav>
        <h1 class="h3 mb-1">DNSBL — spamerzy w AS</h1>
        <p class="text-body-secondary mb-0">
            Skaner IPv4 AS198050 na listach DNSBL (lokalny resolver, tylko darmowe strefy).
            Ręczny check Spamhaus:
            <a href="https://check.spamhaus.org/" target="_blank" rel="noopener noreferrer">check.spamhaus.org</a>
            (per IP w tabeli lub na karcie IP).
        </p>
    </div>
</div>

<?= $this->include('dashboard/_dnsbl_skaner') ?>

<h2 class="h5 mt-5 mb-3">Skan cron (UCEPROTECT L1, SpamCop, DroneBL) — cache enrichment</h2>

<?php if (! empty($blad)): ?>
    <div class="alert alert-danger border-0 shadow-sm rounded-3" role="alert"><?= esc((string) $blad) ?></div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-3">
        <div class="card border-0 shadow-sm rounded-3 h-100">
            <div class="card-body">
                <div class="text-body-secondary small mb-1">Listed IP</div>
                <div class="fs-3 fw-semibold"><?= (int) ($kpi['listed'] ?? 0) ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card border-0 shadow-sm rounded-3 h-100">
            <div class="card-body">
                <div class="text-body-secondary small mb-1">Unikalne listy</div>
                <div class="fs-3 fw-semibold"><?= (int) ($kpi['listy_uniq'] ?? 0) ?></div>
            </div>
        </div>
    </div>
</div>

<form class="card border-0 shadow-sm rounded-3 mb-4" method="get" action="<?= site_url('dashboard/dnsbl') ?>">
    <div class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label" for="dnsbl-lista">Lista</label>
                <select class="form-select" id="dnsbl-lista" name="lista">
                    <option value="">Wszystkie</option>
                    <?php foreach (['uceprotect1' => 'UCEPROTECT', 'spamcop' => 'SpamCop', 'dronebl' => 'DroneBL'] as $kod => $etyk): ?>
                        <option value="<?= esc($kod, 'attr') ?>" <?= $lista === $kod ? 'selected' : '' ?>><?= esc($etyk) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="dnsbl-cidr">CIDR (opcjonalnie)</label>
                <input class="form-control font-monospace" id="dnsbl-cidr" type="text" name="cidr"
                       value="<?= esc($cidr) ?>" placeholder="np. 91.231.80.0/22">
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary">Filtruj</button>
                <a class="btn btn-outline-secondary" href="<?= site_url('dashboard/dnsbl') ?>">Wyczyść</a>
            </div>
        </div>
    </div>
</form>

<div class="card border-0 shadow-sm rounded-3">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th scope="col">IP</th>
                        <th scope="col">Listy</th>
                        <th scope="col">LMS</th>
                        <th scope="col">Zakres skanu</th>
                        <th scope="col">Checked at</th>
                        <th scope="col" class="pe-3">Spamhaus</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($wiersze === []): ?>
                    <tr>
                        <td colspan="6" class="text-body-secondary text-center py-4">
                            Brak aktualnie listed IP w cache.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($wiersze as $w): ?>
                        <?php $ipW = (string) ($w['ip'] ?? ''); ?>
                        <tr>
                            <td class="font-monospace">
                                <a href="<?= site_url('ip/' . rawurlencode($ipW)) ?>"><?= esc($ipW) ?></a>
                            </td>
                            <td>
                                <?php foreach ($w['listy'] as $l): ?>
                                    <span class="badge text-bg-danger me-1"><?= esc((string) $l) ?></span>
                                <?php endforeach; ?>
                            </td>
                            <td class="small">
                                <?php
                                $lmsTxt = trim((string) ($w['abonent'] ?? ''));
                                if ($lmsTxt === '') {
                                    $lmsTxt = trim((string) ($w['komputer'] ?? ''));
                                }
                                echo $lmsTxt !== '' ? esc($lmsTxt) : '—';
                                ?>
                            </td>
                            <td class="small"><?= esc((string) (($w['zakres_skanu'] ?? '') !== '' ? $w['zakres_skanu'] : '—')) ?></td>
                            <td class="small font-monospace"><?= esc((string) (($w['checked_at'] ?? '') !== '' ? $w['checked_at'] : '—')) ?></td>
                            <td class="pe-3">
                                <a class="btn btn-sm btn-outline-secondary"
                                   href="https://check.spamhaus.org/results/?query=<?= rawurlencode($ipW) ?>"
                                   target="_blank" rel="noopener noreferrer"
                                   title="Spamhaus Check">
                                    Check
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?= $this->endSection() ?>
