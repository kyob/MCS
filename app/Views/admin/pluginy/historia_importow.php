<?= $this->extend('layouts/mcs') ?>
<?= $this->section('content') ?>

<?php
$pokazWyczysc = isset($pokazWyczysc)
    ? (bool) $pokazWyczysc
    : trim((string) ($filtrZrodlo ?? '')) !== ''
        || trim((string) ($filtrStatus ?? '')) !== ''
        || trim((string) ($filtrQ ?? '')) !== ''
        || trim((string) ($filtrOd ?? '')) !== ''
        || trim((string) ($filtrDo ?? '')) !== '';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <nav aria-label="Okruszki" class="mb-2">
            <ol class="breadcrumb mb-0 small">
                <li class="breadcrumb-item">
                    <a href="<?= site_url('admin/pluginy') ?>">Wtyczki</a>
                </li>
                <li class="breadcrumb-item active" aria-current="page">Historia importów</li>
            </ol>
        </nav>
        <h1 class="h3 mb-0">Historia importów</h1>
    </div>
    <a href="<?= site_url('admin/pluginy') ?>" class="btn btn-outline-secondary btn-sm">
        Wróć do wtyczek
    </a>
</div>

<div class="card border-0 shadow-sm rounded-3 mb-4 mcs-filter-card">
    <div class="card-body">
        <form class="row g-3 align-items-end" method="get"
              action="<?= site_url('admin/pluginy/historia-importow') ?>"
              aria-label="Filtry historii importów">
            <div class="col-6 col-md-auto">
                <label class="form-label mb-1" for="filtr-zrodlo">Źródło</label>
                <select class="form-select" id="filtr-zrodlo" name="zrodlo">
                    <option value="">wszystkie</option>
                    <?php foreach ($zrodla ?? [] as $z): ?>
                        <option value="<?= esc($z, 'attr') ?>"
                            <?= ($filtrZrodlo ?? '') === $z ? 'selected' : '' ?>>
                            <?= esc($z) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-auto">
                <label class="form-label mb-1" for="filtr-status">Status</label>
                <select class="form-select" id="filtr-status" name="status">
                    <option value="">wszystkie</option>
                    <?php
                    $statusy = [
                        'ok'          => 'ok',
                        'blad'        => 'błąd',
                        'kwarantanna' => 'kwarantanna',
                        'w_trakcie'   => 'w trakcie',
                    ];
                    foreach ($statusy as $wartosc => $etykieta):
                        ?>
                        <option value="<?= esc($wartosc, 'attr') ?>"
                            <?= ($filtrStatus ?? '') === $wartosc ? 'selected' : '' ?>>
                            <?= esc($etykieta) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-auto">
                <label class="form-label mb-1" for="filtr-od">Od</label>
                <input type="date" class="form-control" id="filtr-od" name="od"
                       value="<?= esc((string) ($filtrOd ?? ''), 'attr') ?>">
            </div>
            <div class="col-6 col-md-auto">
                <label class="form-label mb-1" for="filtr-do">Do</label>
                <input type="date" class="form-control" id="filtr-do" name="do"
                       value="<?= esc((string) ($filtrDo ?? ''), 'attr') ?>">
            </div>
            <div class="col-12 col-md">
                <label class="form-label mb-1" for="filtr-q">Szczegóły / wiadomość</label>
                <input type="search" class="form-control" id="filtr-q" name="q"
                       placeholder="błąd, identyfikator wiadomości, suma kontrolna…"
                       value="<?= esc((string) ($filtrQ ?? ''), 'attr') ?>">
            </div>
            <div class="col-12 col-md-auto">
                <?= view('partials/_akcje_filtrow', [
                    'wyczyscHref'  => site_url('admin/pluginy/historia-importow'),
                    'pokazWyczysc' => $pokazWyczysc,
                ], ['saveData' => false]) ?>
            </div>
        </form>
    </div>
</div>

<section class="mb-4">
    <div class="card border-0 shadow-sm rounded-3">
        <div class="card-body pt-3">
            <?php if (empty($importy)): ?>
                <?= view('partials/_pusty_stan', [
                    'tekst' => 'Brak przebiegów spełniających kryteria.',
                    'opis'  => 'Zmień filtry, aby rozszerzyć wyniki.',
                    'ikona' => 'bi-clock-history',
                ], ['saveData' => false]) ?>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                        <tr>
                            <th scope="col">ID</th>
                            <th scope="col">Źródło</th>
                            <th scope="col">Status</th>
                            <th scope="col">Start</th>
                            <th scope="col">Koniec</th>
                            <th scope="col">Szczegóły</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($importy as $imp): ?>
                            <?= view('admin/pluginy/_import_wiersz', ['imp' => $imp]) ?>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php
                // #439: licznik „Wyniki X–Y z Z” jak w kolejka/incydenty; pager tylko przy więcej niż 1 stronie.
                $importyLiczba = is_array($importy) ? count($importy) : 0;
                $razem         = ! empty($pager) ? (int) $pager->getTotal() : $importyLiczba;
                $naStrone      = ! empty($pager) ? (int) $pager->getPerPage() : max(1, $importyLiczba);
                $strona        = ! empty($pager) ? (int) $pager->getCurrentPage() : 1;
                $stron         = ! empty($pager) ? (int) $pager->getPageCount() : 1;
                $listaOd       = $razem > 0 ? ($strona - 1) * $naStrone + 1 : 0;
                $listaDo       = min($razem, $strona * $naStrone);
                ?>
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
                    <p class="small text-body-secondary mb-0">
                        Wyniki <?= $listaOd ?>–<?= $listaDo ?> z <?= $razem ?>
                    </p>
                    <?php if (! empty($pager) && $stron > 1): ?>
                        <div class="d-flex justify-content-center">
                            <?= $pager->only(['zrodlo', 'status', 'q', 'od', 'do'])->links() ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<?= $this->endSection() ?>
