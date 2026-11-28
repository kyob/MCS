<?= $this->extend('layouts/mcs') ?>
<?= $this->section('content') ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h1 class="h3 mb-0">Ustawienia</h1>
    </div>
    <div class="d-flex flex-wrap gap-2 align-items-center">
        <a class="btn btn-sm btn-outline-primary" href="<?= site_url('admin/ustawienia/opcje') ?>">
            <i class="bi bi-sliders me-1" aria-hidden="true"></i>Opcje strojenia
        </a>
    </div>
</div>

<?php if (! empty($blad)): ?>
    <div class="alert alert-danger border-0 shadow-sm rounded-3" role="alert"><?= esc($blad) ?></div>
<?php endif; ?>
<?php if (! empty($komunikat)): ?>
    <div class="alert alert-success border-0 shadow-sm rounded-3" role="status"><?= esc($komunikat) ?></div>
<?php endif; ?>

<form method="post" action="<?= site_url('admin/ustawienia') ?>">
    <?= csrf_field() ?>

    <div class="card border-0 shadow-sm rounded-3 mb-4">
        <div class="card-header bg-transparent border-0 pt-3 px-3 pb-0">
            <h2 class="h5 mb-0">
                <i class="bi bi-diagram-3 me-1 text-primary" aria-hidden="true"></i>
                AS<?= (int) $asAsn ?> — prefiksy CIDR
            </h2>
        </div>
        <div class="card-body">
            <p class="card-text text-body-secondary">
                Po włączeniu przegląd pokazuje tylko adresy z tych sieci.
            </p>

            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" role="switch"
                       name="as_wlaczone" value="1" id="as_wlaczone"
                    <?= ! empty($asWlaczone) ? 'checked' : '' ?>>
                <label class="form-check-label" for="as_wlaczone">
                    Włącz matchowanie do AS<?= (int) $asAsn ?>
                </label>
            </div>

            <div>
                <label class="form-label" for="as_cidry">Prefiksy CIDR, jeden na linię</label>
                <textarea class="form-control font-monospace" id="as_cidry" name="as_cidry"
                          rows="12" spellcheck="false"
                          placeholder="np.&#10;192.0.2.0/24&#10;2001:db8::/32"><?= esc((string) old('as_cidry', $asCidryTekst, false)) ?></textarea>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-3 mb-4">
        <div class="card-header bg-transparent border-0 pt-3 px-3 pb-0">
            <h2 class="h5 mb-0">
                <i class="bi bi-globe2 me-1 text-primary" aria-hidden="true"></i>
                Reverse DNS (PTR)
            </h2>
        </div>
        <div class="card-body">
            <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" role="switch"
                       name="pokazuj_rev_dns" value="1" id="pokazuj_rev_dns"
                    <?= ! empty($revDnsWlaczone) ? 'checked' : '' ?>>
                <label class="form-check-label" for="pokazuj_rev_dns">
                    Pokazuj reverse DNS
                </label>
            </div>
        </div>
    </div>

    <?php
    $dashOkno      = (int) ($dashboardOkno ?? 7);
    $dashZrodlo    = $dashboardZrodlo ?? null;
    $dashKategoria = $dashboardKategoria ?? null;
    $dashPort      = $dashboardPort ?? null;
    $dashWaga      = $dashboardWaga ?? null;
    $dashMinZrodel = (int) ($dashboardMinZrodel ?? 1);
    $dashZrodla    = is_array($zrodla ?? null) ? $zrodla : [];
    $dashKategorie = is_array($kategorie ?? null) ? $kategorie : [];
    if (is_string($dashZrodlo) && $dashZrodlo !== '' && ! in_array($dashZrodlo, $dashZrodla, true)) {
        $dashZrodla[] = $dashZrodlo;
    }
    if (is_string($dashKategoria) && $dashKategoria !== '' && ! in_array($dashKategoria, $dashKategorie, true)) {
        $dashKategorie[] = $dashKategoria;
    }
    ?>
    <div class="card border-0 shadow-sm rounded-3 mb-4">
        <div class="card-header bg-transparent border-0 pt-3 px-3 pb-0">
            <h2 class="h5 mb-0">
                <i class="bi bi-funnel me-1 text-primary" aria-hidden="true"></i>
                Domyślne filtry przeglądu
            </h2>
        </div>
        <div class="card-body">
            <p class="card-text text-body-secondary">
                Używane przy pierwszym wejściu i po wyczyszczeniu filtrów.
            </p>

            <div class="row g-3">
                <div class="col-6 col-md-4 col-lg-2">
                    <label class="form-label mb-1" for="dashboard_okno">Okno</label>
                    <select class="form-select" id="dashboard_okno" name="dashboard_okno">
                        <?php foreach (\App\Libraries\DashboardFiltry::OKNA as $dni): ?>
                            <option value="<?= $dni ?>" <?= $dashOkno === $dni ? 'selected' : '' ?>>
                                <?= $dni ?> <?= $dni === 1 ? 'dzień' : 'dni' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-4 col-lg-2">
                    <label class="form-label mb-1" for="dashboard_zrodlo">Źródło</label>
                    <select class="form-select" id="dashboard_zrodlo" name="dashboard_zrodlo">
                        <option value="">wszystkie</option>
                        <?php foreach ($dashZrodla as $z): ?>
                            <option value="<?= esc($z, 'attr') ?>" <?= $dashZrodlo === $z ? 'selected' : '' ?>>
                                <?= esc($z) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-4 col-lg-2">
                    <label class="form-label mb-1" for="dashboard_kategoria">Kategoria</label>
                    <select class="form-select" id="dashboard_kategoria" name="dashboard_kategoria">
                        <option value="">wszystkie</option>
                        <?php foreach ($dashKategorie as $kat): ?>
                            <option value="<?= esc($kat, 'attr') ?>" <?= $dashKategoria === $kat ? 'selected' : '' ?>>
                                <?= esc($kat) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-4 col-lg-2">
                    <label class="form-label mb-1" for="dashboard_port">Port</label>
                    <input class="form-control" id="dashboard_port" type="number" name="dashboard_port"
                           min="1" max="65535" inputmode="numeric" placeholder="opcjonalnie"
                           value="<?= $dashPort !== null ? (int) $dashPort : '' ?>">
                </div>
                <div class="col-6 col-md-4 col-lg-2">
                    <label class="form-label mb-1" for="dashboard_waga">Min. waga</label>
                    <select class="form-select" id="dashboard_waga" name="dashboard_waga">
                        <option value="">dowolna</option>
                        <?php foreach (\App\Libraries\DashboardFiltry::WAGI as $w): ?>
                            <option value="<?= $w ?>" <?= $dashWaga === $w ? 'selected' : '' ?>><?= $w ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-4 col-lg-2">
                    <label class="form-label mb-1" for="dashboard_min_zrodel">Min. źródeł</label>
                    <select class="form-select" id="dashboard_min_zrodel" name="dashboard_min_zrodel">
                        <?php foreach (\App\Libraries\DashboardFiltry::MIN_ZRODEL as $n): ?>
                            <option value="<?= $n ?>" <?= $dashMinZrodel === $n ? 'selected' : '' ?>>
                                <?= $n ?><?= $n >= 2 ? '+' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex flex-wrap gap-2 mb-4">
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-check-lg me-1" aria-hidden="true"></i>Zapisz ustawienia
        </button>
    </div>
</form>

<?php if ($asDomyslneCidry !== []): ?>
<form method="post" action="<?= site_url('admin/ustawienia/przywroc-domyslne') ?>" class="mb-4"
      onsubmit="return confirm('Przywrócić domyślne prefiksy AS<?= (int) $asAsn ?>? Obecna lista zostanie nadpisana.');">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn-outline-secondary"
            title="<?= esc(implode(', ', $asDomyslneCidry), 'attr') ?>">
        <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>
        Przywróć domyślne prefiksy
    </button>
</form>
<?php else: ?>
<p>Brak domyślnych prefiksów dla AS<?= (int) $asAsn ?>. Prefiksy możesz wpisać ręcznie.</p>
<?php endif; ?>

<?= $this->endSection() ?>
