<?= $this->extend('layouts/mcs') ?>
<?= $this->section('content') ?>

<?php
$badgeWaga = static function (string $waga): string {
    return match ($waga) {
        'critical' => 'text-bg-danger',
        'high' => 'text-bg-warning',
        'medium' => 'text-bg-secondary',
        'low' => 'text-bg-success',
        default => 'text-bg-light text-dark border',
    };
};
$queryEksport = (string) ($queryEksport ?? 'okno=' . (int) $okno);
$listaTotal   = (int) ($listaTotal ?? 0);
$pagerQuery   = ['okno', 'zrodlo', 'ip', 'waga', 'min_zrodel', 'kategoria', 'port', 'cidr', 'podsiec'];
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <nav aria-label="Okruszki" class="mb-2">
            <ol class="breadcrumb mb-0 small">
                <li class="breadcrumb-item"><a href="<?= site_url('/') ?>">Przegląd</a></li>
                <li class="breadcrumb-item active" aria-current="page">Problematyczne IP</li>
            </ol>
        </nav>
        <h1 class="h3 mb-1">Problematyczne IP</h1>
        <p class="text-body-secondary mb-0">
            Sort: źródła → waga → incydenty → ostatnia aktywność. CTA otwiera szczegóły IP.
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
        <a class="btn btn-sm btn-outline-success"
           href="<?= site_url('dashboard/eksport.csv') . ($queryEksport !== '' ? '?' . $queryEksport : '') ?>">
            <i class="bi bi-download me-1" aria-hidden="true"></i>Eksport CSV
        </a>
    </div>
</div>

<?php if (! empty($asMatchWlaczone)): ?>
    <div class="alert alert-primary border-0 shadow-sm rounded-3 py-2 mb-4" role="status">
        <i class="bi bi-funnel-fill me-1" aria-hidden="true"></i>
        Matchowanie AS198050 jest włączone — lista obejmuje tylko IP z prefiksów CIDR w
        <a href="<?= site_url('admin/ustawienia') ?>" class="alert-link">ustawieniach</a>.
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

<section class="mb-4">
    <div class="card border-0 shadow-sm rounded-3">
        <div class="card-body pt-3">
            <?php if ($topIp === []): ?>
                <p class="text-body-secondary mb-0">Brak IP spełniających filtry w wybranym oknie.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                        <tr>
                            <th scope="col">IP</th>
                            <th scope="col">Kontekst</th>
                            <th scope="col">Źródła</th>
                            <th scope="col">Incydenty</th>
                            <th scope="col">Max waga</th>
                            <th scope="col">Ostatnio</th>
                            <th scope="col"><span class="visually-hidden">Akcja</span></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($topIp as $wiersz): ?>
                            <?php
                            $ipWiersz = (string) ($wiersz['ip'] ?? '');
                            $maxWaga  = (string) ($wiersz['max_waga'] ?? '');
                            $borderWaga = in_array($maxWaga, ['critical', 'high', 'medium', 'low'], true)
                                ? ' border-waga-' . $maxWaga
                                : '';
                            $kontekst = is_array($wiersz['kontekst'] ?? null) ? $wiersz['kontekst'] : null;
                            $urlIp    = site_url('ip/' . $ipWiersz);
                            ?>
                            <tr class="<?= esc(ltrim($borderWaga), 'attr') ?>">
                                <td>
                                    <a class="font-monospace text-decoration-none" href="<?= $urlIp ?>"><?= esc($ipWiersz) ?></a>
                                    <?php if (! empty($wiersz['nowe'])): ?>
                                        <span class="badge text-bg-success ms-1" title="Pierwszy raz w MCS w ostatnich <?= (int) ($noweDni ?? 7) ?> dniach">NEW</span>
                                    <?php endif; ?>
                                    <?php if (! empty($revDnsWlaczone) && ! empty($wiersz['rev_dns'])): ?>
                                        <div class="small text-body-secondary font-monospace"><?= esc((string) $wiersz['rev_dns']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td style="min-width:14rem;max-width:28rem">
                                    <?php if ($kontekst !== null): ?>
                                        <div class="d-flex flex-wrap gap-1" aria-label="Skrót kontekstu">
                                            <?php foreach (($kontekst['chipy'] ?? []) as $chip): ?>
                                                <?php
                                                $typ = (string) ($chip['typ'] ?? '');
                                                $tekst = (string) ($chip['tekst'] ?? '');
                                                if (str_starts_with($typ, 'waga-')) {
                                                    $w = substr($typ, 5);
                                                    echo '<span class="badge ' . esc($badgeWaga($w), 'attr') . '">' . esc($tekst) . '</span>';
                                                } elseif ($typ === 'zrodlo') {
                                                    echo '<span class="badge text-bg-info-subtle text-info-emphasis border border-info-subtle">' . esc($tekst) . '</span>';
                                                } elseif ($typ === 'kategoria') {
                                                    echo '<span class="badge text-bg-secondary-subtle text-secondary-emphasis border">' . esc($tekst) . '</span>';
                                                } else {
                                                    echo '<span class="badge text-bg-light text-secondary border">' . esc($tekst) . '</span>';
                                                }
                                                ?>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <?php foreach (($wiersz['zrodla'] ?? []) as $z): ?>
                                            <span class="badge text-bg-light text-dark border"><?= esc((new \App\Libraries\EtykietyUi())->zrodlo((string) $z)) ?></span>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </td>
                                <td><?= (int) ($wiersz['liczba_zrodel'] ?? 0) ?></td>
                                <td><?= (int) ($wiersz['liczba_incydentow'] ?? 0) ?></td>
                                <td>
                                    <?php if ($maxWaga !== ''): ?>
                                        <span class="badge <?= esc($badgeWaga($maxWaga), 'attr') ?>"><?= esc($maxWaga) ?></span>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td class="small text-nowrap"><?= esc((string) ($wiersz['ostatnio_widziane'] ?? '—')) ?></td>
                                <td class="text-nowrap">
                                    <a class="btn btn-sm btn-outline-primary"
                                       href="<?= $urlIp ?>"><?= esc((string) (($kontekst['cta'] ?? null) ?: 'Otwórz')) ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
                    <p class="small text-body-secondary mb-0">
                        Wyniki <?= (int) ($listaOd ?? 0) ?>–<?= (int) ($listaDo ?? 0) ?> z <?= $listaTotal ?>
                    </p>
                    <?php if (! empty($pager) && $listaTotal > (int) ($listaNaStrone ?? 25)): ?>
                        <?= $pager->only($pagerQuery)->links() ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<?= $this->endSection() ?>
