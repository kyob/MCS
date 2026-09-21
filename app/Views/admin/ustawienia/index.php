<?= $this->extend('layouts/mcs') ?>
<?= $this->section('content') ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Ustawienia</h1>
        <p class="text-body-secondary mb-0">
            Matchowanie IP do AS<?= (int) $asAsn ?> (Alfa-System), opcje wyświetlania
            oraz domyślne filtry przeglądu.
        </p>
    </div>
    <span class="badge text-bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle px-3 py-2">
        AS<?= (int) $asAsn ?>
    </span>
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
                Gdy matchowanie jest <strong>włączone</strong>, przegląd zagrożeń (KPI, ranking IP i ostatnie incydenty)
                pokazuje automatycznie tylko adresy z poniższych sieci CIDR.
                Wyłączenie nie usuwa listy — tylko przestaje zawężać przegląd.
            </p>

            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" role="switch"
                       name="as198050_wlaczone" value="1" id="as198050_wlaczone"
                    <?= ! empty($asWlaczone) ? 'checked' : '' ?>>
                <label class="form-check-label" for="as198050_wlaczone">
                    Włącz matchowanie do AS<?= (int) $asAsn ?>
                </label>
            </div>

            <div class="mb-3">
                <label class="form-label" for="as198050_cidry">
                    Prefiksy CIDR (jeden na linię; dozwolone też przecinki/spacje)
                </label>
                <textarea class="form-control font-monospace" id="as198050_cidry" name="as198050_cidry"
                          rows="12" spellcheck="false"
                          placeholder="np.&#10;91.231.80.0/22&#10;176.97.24.0/21&#10;2a0f:1700::/29"><?= esc($asCidryTekst) ?></textarea>
            </div>

            <p class="small text-body-secondary mb-0">
                ASN: <code>AS<?= (int) $asAsn ?></code>.
                Lista startowa z RIPE (announced-prefixes) — aktualizuj ręcznie przy zmianie routingu.
                Pusta lista + włączone matchowanie = przegląd bez żadnych IP z CIDR.
            </p>
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
            <p class="card-text text-body-secondary">
                Gdy włączone, strona IP pokazuje hostname z zapytania PTR (reverse DNS).
                Na przeglądzie — tylko gdy wynik jest już w cache (bez cold lookup dla całej listy).
                Lookup nie wpływa na indeks ani badge.
            </p>

            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" role="switch"
                       name="pokazuj_rev_dns" value="1" id="pokazuj_rev_dns"
                    <?= ! empty($revDnsWlaczone) ? 'checked' : '' ?>>
                <label class="form-check-label" for="pokazuj_rev_dns">
                    Pokazuj reverse DNS
                </label>
            </div>

            <p class="small text-body-secondary mb-0">
                Domyślnie wyłączone. PTR pochodzi z lokalnego resolwera DNS (<code>gethostbyaddr</code>),
                wynik cache’owany ~1&nbsp;h. Brak rekordu = brak etykiety (bez błędu).
            </p>
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
                Te wartości obowiązują przy pierwszym wejściu na przegląd i po kliknięciu
                <strong>Wyczyść</strong>. Ostatni wybór filtrów (łącznie z IP) jest pamiętany w sesji
                — wracając na Dashboard, zobaczysz poprzednie ustawienia.
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

            <p class="small text-body-secondary mt-3 mb-0">
                Pole IP na przeglądzie nie ma wartości domyślnej — to wyszukiwarka, nie stały filtr.
            </p>
        </div>
    </div>

    <div class="d-flex flex-wrap gap-2 mb-4">
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-check-lg me-1" aria-hidden="true"></i>Zapisz ustawienia
        </button>
    </div>
</form>

<form method="post" action="<?= site_url('admin/ustawienia/przywroc-domyslne') ?>" class="mb-4"
      onsubmit="return confirm('Przywrócić domyślną listę prefiksów AS<?= (int) $asAsn ?>? Obecna lista w bazie zostanie nadpisana.');">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>
        Przywróć domyślne prefiksy RIPE
    </button>
</form>

<div class="card border-0 shadow-sm rounded-3">
    <div class="card-body small text-body-secondary">
        <dl class="row mb-0">
            <dt class="col-md-3">Co robi matchowanie?</dt>
            <dd class="col-md-9">
                Porównuje adres IP incydentu z listą CIDR. Trafienie = adres w AS<?= (int) $asAsn ?>.
                Nie pobiera live BGP — tylko lokalna lista z tych ustawień.
            </dd>
            <dt class="col-md-3">Gdzie widać efekt?</dt>
            <dd class="col-md-9">
                Przegląd: przy włączonym AS automatycznie tylko IP z CIDR;
                filtry startowe z tej strony, potem ostatni wybór z sesji.
                Strona IP: komunikat, czy adres należy do AS<?= (int) $asAsn ?>;
                przy włączonym reverse DNS — hostname PTR pod adresem IP.
            </dd>
            <dt class="col-md-3">Domyślne prefiksy (seed)</dt>
            <dd class="col-md-9 mb-0">
                <code><?= esc(implode(', ', $asDomyslneCidry)) ?></code>
            </dd>
        </dl>
    </div>
</div>

<?= $this->endSection() ?>
