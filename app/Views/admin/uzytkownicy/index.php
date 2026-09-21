<?= $this->extend('layouts/mcs') ?>
<?= $this->section('content') ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Użytkownicy</h1>
        <p class="text-body-secondary mb-0">
            Konta MCS i role. Ostatniego superadmina nie można wyłączyć, zdegradować ani usunąć.
        </p>
    </div>
    <span class="badge text-bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle px-3 py-2">
        <?= count($uzytkownicy) ?> kont
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
            <i class="bi bi-person-plus me-1 text-primary" aria-hidden="true"></i>
            Dodaj konto
        </h2>
    </div>
    <div class="card-body">
        <form method="post" action="<?= site_url('admin/uzytkownicy') ?>" class="row g-3 align-items-end">
            <?= csrf_field() ?>
            <div class="col-md-4">
                <label class="form-label" for="user-email">E-mail</label>
                <input class="form-control" id="user-email" type="email" name="email" required
                       autocomplete="off" placeholder="np. ops@mcs.local">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="user-haslo">Hasło</label>
                <input class="form-control" id="user-haslo" type="password" name="haslo" required
                       minlength="8" autocomplete="new-password" placeholder="min. 8 znaków">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="user-rola">Rola</label>
                <select class="form-select" id="user-rola" name="rola">
                    <?php foreach (\App\Libraries\Rola::WSZYSTKIE as $kod): ?>
                        <option value="<?= esc($kod, 'attr') ?>"<?= $kod === \App\Libraries\Rola::OPERATOR ? ' selected' : '' ?>>
                            <?= esc(\App\Libraries\Rola::etykieta($kod)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-auto">
                <button type="submit" class="btn btn-primary">Dodaj konto</button>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm rounded-3">
    <div class="card-header bg-transparent border-0 pt-3 px-3 pb-0">
        <h2 class="h5 mb-1">Konta</h2>
        <p class="small text-body-secondary mb-0">Zmiana roli, hasła, włączenie/wyłączenie i usuwanie.</p>
    </div>
    <div class="card-body pt-3">
        <?php if ($uzytkownicy === []): ?>
            <p class="text-body-secondary mb-0">Brak kont.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <th scope="col">E-mail</th>
                        <th scope="col">Rola</th>
                        <th scope="col">Hasło</th>
                        <th scope="col">Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($uzytkownicy as $u): ?>
                        <?php
                        $id         = (int) ($u['id'] ?? 0);
                        $emailW     = (string) ($u['email'] ?? '');
                        $rolaW      = (string) ($u['rola'] ?? '');
                        $aktywny    = ! empty($u['aktywny']);
                        $ostatniSa  = ! empty($u['ostatni_sa']);
                        $wlasne     = $id === (int) ($sesjaId ?? 0);
                        $nieUsuwaj  = $ostatniSa || $wlasne;
                        ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= esc($emailW) ?></div>
                                <?php if ($ostatniSa): ?>
                                    <div class="small text-body-secondary">ostatni superadmin</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="post" action="<?= site_url('admin/uzytkownicy/rola') ?>" class="d-flex flex-wrap gap-2 align-items-center">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= $id ?>">
                                    <select class="form-select form-select-sm" name="rola"<?= $ostatniSa ? ' disabled' : '' ?>
                                            aria-label="Rola <?= esc($emailW, 'attr') ?>">
                                        <?php foreach (\App\Libraries\Rola::WSZYSTKIE as $kod): ?>
                                            <option value="<?= esc($kod, 'attr') ?>"<?= $kod === $rolaW ? ' selected' : '' ?>>
                                                <?= esc(\App\Libraries\Rola::etykieta($kod)) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if ($ostatniSa): ?>
                                        <input type="hidden" name="rola" value="<?= esc($rolaW, 'attr') ?>">
                                    <?php endif; ?>
                                    <button type="submit" class="btn btn-sm btn-outline-primary"<?= $ostatniSa ? ' disabled' : '' ?>>
                                        Zapisz
                                    </button>
                                </form>
                            </td>
                            <td>
                                <form method="post" action="<?= site_url('admin/uzytkownicy/haslo') ?>" class="d-flex flex-wrap gap-2 align-items-center">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= $id ?>">
                                    <input class="form-control form-control-sm" type="password" name="haslo"
                                           minlength="8" autocomplete="new-password" placeholder="Nowe hasło"
                                           aria-label="Nowe hasło <?= esc($emailW, 'attr') ?>" required>
                                    <button type="submit" class="btn btn-sm btn-outline-primary">Ustaw</button>
                                </form>
                            </td>
                            <td>
                                <div class="d-flex flex-wrap align-items-center gap-2">
                                <?php if ($aktywny): ?>
                                    <span class="badge text-bg-success">aktywne</span>
                                    <form method="post" action="<?= site_url('admin/uzytkownicy/aktywny') ?>" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= $id ?>">
                                        <input type="hidden" name="aktywny" value="0">
                                        <button type="submit" class="btn btn-sm btn-outline-danger"
                                            <?= $ostatniSa ? ' disabled title="Nie można wyłączyć ostatniego superadmina"' : '' ?>>
                                            Wyłącz
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="badge text-bg-secondary">wyłączone</span>
                                    <form method="post" action="<?= site_url('admin/uzytkownicy/aktywny') ?>" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= $id ?>">
                                        <input type="hidden" name="aktywny" value="1">
                                        <button type="submit" class="btn btn-sm btn-outline-success">Włącz</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($nieUsuwaj): ?>
                                    <button type="button" class="btn btn-sm btn-outline-danger" disabled
                                        title="<?= $ostatniSa ? 'Nie można usunąć ostatniego superadmina' : 'Nie można usunąć własnego konta' ?>">
                                        Usuń
                                    </button>
                                <?php else: ?>
                                    <form method="post" action="<?= site_url('admin/uzytkownicy/usun') ?>" class="d-inline"
                                          onsubmit="return confirm('Usunąć <?= esc($emailW, 'js') ?>?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= $id ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Usuń</button>
                                    </form>
                                <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?= $this->endSection() ?>
