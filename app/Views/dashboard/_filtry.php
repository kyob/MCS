<?php
$formAkcja      = (string) ($formAkcja ?? site_url('/'));
$wyczyscHref    = (string) ($wyczyscHref ?? site_url('/') . '?wyczysc=1');
$kategorieLista = is_array($kategorie ?? null) ? $kategorie : [];
$podsieciAs     = is_array($podsieciAs ?? null) ? $podsieciAs : [];
$filtrPodsiec   = isset($filtrPodsiec) && is_string($filtrPodsiec) ? $filtrPodsiec : null;
$filtrCidr      = (string) ($filtrCidr ?? '');
?>
<div class="card border-0 shadow-sm rounded-3 mb-4">
    <div class="card-body">
        <form class="row g-3 align-items-end" method="get" action="<?= $formAkcja ?>" aria-label="Filtry przeglądu">
            <div class="col-6 col-md-auto">
                <label class="form-label mb-1" for="filtr-okno">Okno</label>
                <select class="form-select" id="filtr-okno" name="okno">
                    <?php foreach (\App\Libraries\DashboardFiltry::OKNA as $dni): ?>
                        <option value="<?= $dni ?>" <?= (int) $okno === $dni ? 'selected' : '' ?>>
                            <?= $dni ?> <?= $dni === 1 ? 'dzień' : 'dni' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-auto">
                <label class="form-label mb-1" for="filtr-zrodlo">Źródło</label>
                <select class="form-select" id="filtr-zrodlo" name="zrodlo">
                    <option value="">wszystkie</option>
                    <?php foreach ($zrodla as $z): ?>
                        <option value="<?= esc($z, 'attr') ?>" <?= $filtrZrodlo === $z ? 'selected' : '' ?>>
                            <?= esc((new \App\Libraries\EtykietyUi())->zrodlo((string) $z)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-auto">
                <label class="form-label mb-1" for="filtr-kategoria">Kategoria</label>
                <select class="form-select" id="filtr-kategoria" name="kategoria">
                    <option value="">wszystkie</option>
                    <?php foreach ($kategorieLista as $kat): ?>
                        <option value="<?= esc($kat, 'attr') ?>" <?= $filtrKategoria === $kat ? 'selected' : '' ?>>
                            <?= esc($kat) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-auto" style="min-width:7rem">
                <label class="form-label mb-1" for="filtr-port">Port</label>
                <input class="form-control" id="filtr-port" type="number" name="port" min="1" max="65535"
                       value="<?= $filtrPort !== null ? (int) $filtrPort : '' ?>"
                       placeholder="opcjonalnie" inputmode="numeric">
            </div>
            <div class="col-6 col-md-auto">
                <label class="form-label mb-1" for="filtr-waga">Min. waga</label>
                <select class="form-select" id="filtr-waga" name="waga">
                    <option value="">dowolna</option>
                    <?php foreach (\App\Libraries\DashboardFiltry::WAGI as $w): ?>
                        <option value="<?= $w ?>" <?= $filtrMinWaga === $w ? 'selected' : '' ?>><?= $w ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-auto">
                <label class="form-label mb-1" for="filtr-min-zrodel">Min. źródeł</label>
                <select class="form-select" id="filtr-min-zrodel" name="min_zrodel">
                    <?php foreach (\App\Libraries\DashboardFiltry::MIN_ZRODEL as $n): ?>
                        <option value="<?= $n ?>" <?= (int) $filtrMinZrodel === $n ? 'selected' : '' ?>>
                            <?= $n ?><?= $n >= 2 ? '+' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-auto">
                <label class="form-label mb-1" for="filtr-podsiec">Podsieć</label>
                <select class="form-select font-monospace" id="filtr-podsiec" name="podsiec">
                    <option value="">wszystkie</option>
                    <?php foreach ($podsieciAs as $p): ?>
                        <?php $p = (string) $p; ?>
                        <option value="<?= esc($p, 'attr') ?>" <?= $filtrPodsiec === $p ? 'selected' : '' ?>>
                            <?= esc($p) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-auto" style="min-width:11rem">
                <label class="form-label mb-1" for="filtr-cidr">CIDR</label>
                <input class="form-control font-monospace" id="filtr-cidr" type="text" name="cidr"
                       value="<?= esc($filtrCidr, 'attr') ?>"
                       placeholder="np. 91.231.80.0/24" autocomplete="off">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label mb-1" for="filtr-ip">IP</label>
                <input class="form-control font-monospace" id="filtr-ip" type="text" name="ip"
                       value="<?= esc((string) ($filtrIp ?? ''), 'attr') ?>"
                       placeholder="np. 198.51.100.10" autocomplete="off">
            </div>
            <div class="col-12 col-md-auto d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-funnel me-1" aria-hidden="true"></i>Filtruj
                </button>
                <a class="btn btn-outline-secondary" href="<?= $wyczyscHref ?>">Wyczyść</a>
            </div>
        </form>
    </div>
</div>
