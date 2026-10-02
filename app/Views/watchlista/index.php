<?= $this->extend('layouts/mcs') ?>
<?= $this->section('content') ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Watchlista IP</h1>
        <p class="text-body-secondary mb-0">
            Adresy do digesta operacyjnego (<code>mcs:digest</code>). Tylko INFO — MCS nie blokuje ani nie karze.
            Szczegóły: strona IP.
        </p>
    </div>
    <span class="badge text-bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle px-3 py-2">
        <?= count($lista) ?> obserwowanych
    </span>
</div>

<?php if (! empty($blad)): ?>
    <div class="alert alert-danger border-0 shadow-sm rounded-3" role="alert"><?= esc($blad) ?></div>
<?php endif; ?>
<?php if (! empty($komunikat)): ?>
    <div class="alert alert-success border-0 shadow-sm rounded-3" role="status"><?= esc($komunikat) ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm rounded-3 mb-4">
    <div class="card-header bg-transparent border-0 pt-3 px-3 pb-0">
        <h2 class="h5 mb-0">
            <i class="bi bi-plus-circle me-1 text-primary" aria-hidden="true"></i>
            Dodaj IP
        </h2>
    </div>
    <div class="card-body">
        <form method="post" action="<?= site_url('watchlista/dodaj') ?>" class="row g-3 align-items-end">
            <?= csrf_field() ?>
            <div class="col-md-4">
                <label class="form-label" for="wl-ip">Adres IP</label>
                <input class="form-control font-monospace" id="wl-ip" type="text" name="ip" required
                       placeholder="np. 198.51.100.10" autocomplete="off" value="">
            </div>
            <div class="col-md-5">
                <label class="form-label" for="wl-notatka">Notatka (opcjonalnie)</label>
                <input class="form-control" id="wl-notatka" type="text" name="notatka"
                       placeholder="np. klient VIP / skarga" maxlength="500" value="">
            </div>
            <div class="col-md-auto">
                <button type="submit" class="btn btn-primary">Dodaj do watchlisty</button>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm rounded-3 mb-4">
    <div class="card-header bg-transparent border-0 pt-3 px-3 pb-0">
        <h2 class="h5 mb-1">Obserwowane adresy</h2>
        <p class="small text-body-secondary mb-0"><?= count($lista) ?> pozycji na liście</p>
    </div>
    <div class="card-body pt-3">
        <?php if ($lista === []): ?>
            <p class="text-body-secondary mb-0">
                Watchlista jest pusta. Dodaj IP powyżej, potem uruchom <code>php spark mcs:digest</code>.
            </p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <th scope="col">IP</th>
                        <th scope="col">Notatka</th>
                        <th scope="col">Dodano</th>
                        <th scope="col">Przez</th>
                        <th scope="col">Akcje</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($lista as $w): ?>
                        <?php $ip = (string) ($w['ip'] ?? ''); ?>
                        <tr>
                            <td>
                                <a class="font-monospace text-decoration-none" href="<?= site_url('ip/' . $ip) ?>"><?= esc($ip) ?></a>
                            </td>
                            <td><?= esc((string) ($w['notatka'] ?? '')) ?: '—' ?></td>
                            <td class="small text-nowrap"><?= esc((string) ($w['dodano_at'] ?? '')) ?></td>
                            <td><?= esc((string) ($w['dodano_przez'] ?? '')) ?: '—' ?></td>
                            <td>
                                <form method="post" action="<?= site_url('watchlista/usun') ?>" class="d-inline"
                                      onsubmit="return confirm('Usunąć <?= esc($ip, 'js') ?> z watchlisty?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="ip" value="<?= esc($ip, 'attr') ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="bi bi-trash me-1" aria-hidden="true"></i>Usuń
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card border-0 shadow-sm rounded-3">
    <div class="card-body small text-body-secondary">
        <h3 class="h6 text-body">
            <i class="bi bi-terminal me-1" aria-hidden="true"></i>Digest CLI
        </h3>
        <p class="mb-2">
            <code>php spark mcs:digest</code> — high|critical z ostatnich 24 h dla IP z listy.<br>
            <code>php spark mcs:digest --godziny=6 --tylko-as198050</code> — węższe okno + filtr AS<?= (int) $asAsn ?>.
        </p>
        <h3 class="h6 text-body">Zakres</h3>
        <p class="mb-0">
            Digest raportuje tylko wagi <strong>high</strong> i <strong>critical</strong>
            (widoczne źródła). Nie uruchamia blokad ani kar.
        </p>
    </div>
</div>

<?= $this->endSection() ?>
