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
$listaTotal  = (int) ($listaTotal ?? 0);
$pagerQuery  = ['okno', 'zrodlo', 'ip', 'waga', 'min_zrodel', 'kategoria', 'port', 'cidr', 'podsiec'];
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <nav aria-label="Okruszki" class="mb-2">
            <ol class="breadcrumb mb-0 small">
                <li class="breadcrumb-item"><a href="<?= site_url('/') ?>">Przegląd</a></li>
                <li class="breadcrumb-item active" aria-current="page">Ostatnie incydenty</li>
            </ol>
        </nav>
        <h1 class="h3 mb-1">Ostatnie incydenty</h1>
        <p class="text-body-secondary mb-0">Najnowsze skróty z indeksu (po filtrach).</p>
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
            <?php if ($ostatnie === []): ?>
                <p class="text-body-secondary mb-0">Brak incydentów w wybranym zakresie.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                        <tr>
                            <th scope="col">Ostatnio</th>
                            <th scope="col">IP</th>
                            <th scope="col">Waga</th>
                            <th scope="col">Źródło</th>
                            <th scope="col">Tytuł</th>
                            <th scope="col">Kategoria</th>
                            <th scope="col">Port</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($ostatnie as $wiersz): ?>
                            <?php
                            $ipWiersz = (string) ($wiersz['podmiot'] ?? '');
                            $waga     = (string) ($wiersz['waga'] ?? '');
                            $borderWaga = in_array($waga, ['critical', 'high', 'medium', 'low'], true)
                                ? ' border-waga-' . $waga
                                : '';
                            $incId = (int) ($wiersz['id'] ?? 0);
                            $portW = $wiersz['port'] ?? null;
                            ?>
                            <tr class="<?= esc(ltrim($borderWaga), 'attr') ?>">
                                <td class="small text-nowrap"><?= esc((string) ($wiersz['ostatnio_widziane'] ?? '')) ?></td>
                                <td>
                                    <?php if ($ipWiersz !== ''): ?>
                                        <a class="font-monospace text-decoration-none" href="<?= site_url('ip/' . $ipWiersz) ?>"><?= esc($ipWiersz) ?></a>
                                        <?php if (! empty($wiersz['nowe'])): ?>
                                            <span class="badge text-bg-success ms-1" title="Pierwszy raz w MCS w ostatnich dniach">NEW</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($waga !== ''): ?>
                                        <span class="badge <?= esc($badgeWaga($waga), 'attr') ?>"><?= esc($waga) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?= esc((new \App\Libraries\EtykietyUi())->zrodlo((string) ($wiersz['zrodlo'] ?? ''))) ?></td>
                                <td>
                                    <?php if ($incId > 0 && $ipWiersz !== ''): ?>
                                        <a class="text-decoration-none" href="<?= site_url('ip/' . $ipWiersz . '/incydent/' . $incId) ?>">
                                            <?= esc((string) ($wiersz['tytul'] ?? '')) ?>
                                        </a>
                                    <?php else: ?>
                                        <?= esc((string) ($wiersz['tytul'] ?? '')) ?>
                                    <?php endif; ?>
                                </td>
                                <td><?= esc((string) ($wiersz['kategoria'] ?? '')) ?></td>
                                <td class="small"><?= $portW !== null && $portW !== '' ? esc((string) $portW) : '—' ?></td>
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
