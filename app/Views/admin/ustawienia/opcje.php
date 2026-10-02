<?= $this->extend('layouts/mcs') ?>
<?= $this->section('content') ?>

<?php
$bledy = is_array($bledy ?? null) ? $bledy : [];
$etykietaZrodla = static function (string $zrodlo): array {
    return match ($zrodlo) {
        'db'    => ['WWW', 'text-bg-primary'],
        'env'   => ['.env', 'text-bg-info'],
        default => ['domyślna', 'text-bg-light text-dark border'],
    };
};
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <nav aria-label="Okruszki" class="mb-2">
            <ol class="breadcrumb mb-0 small">
                <li class="breadcrumb-item"><a href="<?= site_url('admin/ustawienia') ?>">Ustawienia</a></li>
                <li class="breadcrumb-item active" aria-current="page">Opcje strojenia</li>
            </ol>
        </nav>
        <h1 class="h3 mb-1">Opcje strojenia</h1>
        <p class="text-body-secondary mb-0">
            Progi, limity, TTL, timeouty i ASN. Kolejność: wartość z WWW → <code>.env</code> → domyślna.
            Sekrety, hosty z poświadczeniami, TLS i ścieżki plików są tylko w <code>.env</code>.
        </p>
    </div>
</div>

<?php if (! empty($blad)): ?>
    <div class="alert alert-danger border-0 shadow-sm rounded-3" role="alert">
        <?= esc($blad) ?>
        <?php if ($bledy !== []): ?>
            <ul class="mb-0 mt-2">
                <?php foreach ($bledy as $b): ?>
                    <li><?= esc((string) $b) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?php if (! empty($komunikat)): ?>
    <div class="alert alert-success border-0 shadow-sm rounded-3" role="status"><?= esc($komunikat) ?></div>
<?php endif; ?>

<form method="post" action="<?= site_url('admin/ustawienia/opcje') ?>">
    <?= csrf_field() ?>

    <?php foreach ($grupy as $grupa => $opcje): ?>
        <div class="card border-0 shadow-sm rounded-3 mb-4">
            <div class="card-header bg-transparent border-0 pt-3 px-3 pb-0">
                <h2 class="h5 mb-0"><?= esc((string) $grupa) ?></h2>
            </div>
            <div class="card-body">
                <?php foreach ($opcje as $klucz => $o): ?>
                    <?php
                    $id = 'opcja_' . preg_replace('/[^a-zA-Z0-9_]/', '_', (string) $klucz);
                    [$zrTekst, $zrKlasa] = $etykietaZrodla((string) $o['zrodlo']);
                    $typ = rtrim((string) $o['typ'], '?');
                    ?>
                    <div class="row g-2 align-items-center mb-2">
                        <label class="col-md-5 col-form-label" for="<?= esc($id, 'attr') ?>">
                            <?= esc((string) $o['etykieta']) ?>
                            <br><code class="small"><?= esc((string) $klucz) ?></code>
                        </label>
                        <div class="col-md-3">
                            <?php if ($typ === 'enum'): ?>
                                <select class="form-select<?= isset($bledy[$klucz]) ? ' is-invalid' : '' ?>"
                                        id="<?= esc($id, 'attr') ?>" name="opcja[<?= esc((string) $klucz, 'attr') ?>]">
                                    <?php foreach ($o['opcje'] ?? [] as $opt): ?>
                                        <option value="<?= esc((string) $opt, 'attr') ?>"<?= (string) $opt === (string) $o['wartosc'] ? ' selected' : '' ?>><?= esc((string) $opt) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <input class="form-control<?= isset($bledy[$klucz]) ? ' is-invalid' : '' ?>"
                                       id="<?= esc($id, 'attr') ?>" name="opcja[<?= esc((string) $klucz, 'attr') ?>]"
                                       type="<?= $typ === 'email' ? 'email' : ($typ === 'int' ? 'number' : 'text') ?>"
                                       <?php if ($typ === 'int' && isset($o['min'])): ?>min="<?= (int) $o['min'] ?>"<?php endif; ?>
                                       <?php if ($typ === 'int' && isset($o['max'])): ?>max="<?= (int) $o['max'] ?>"<?php endif; ?>
                                       value="<?= esc((string) $o['wartosc'], 'attr') ?>">
                            <?php endif; ?>
                        </div>
                        <div class="col-md-4 small">
                            <span class="badge <?= esc($zrKlasa, 'attr') ?>"><?= esc($zrTekst) ?></span>
                            domyślnie: <code><?= esc((string) $o['domyslna'] !== '' ? (string) $o['domyslna'] : '(puste)') ?></code>
                            <?php if ($o['zrodlo'] === 'db'): ?>
                                <div class="form-check form-check-inline ms-2">
                                    <input class="form-check-input" type="checkbox" value="1"
                                           id="<?= esc($id . '_przywroc', 'attr') ?>" name="przywroc[<?= esc((string) $klucz, 'attr') ?>]">
                                    <label class="form-check-label" for="<?= esc($id . '_przywroc', 'attr') ?>">usuń z WWW</label>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>

    <div class="d-flex flex-wrap gap-2 mb-4">
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-check-lg me-1" aria-hidden="true"></i>Zapisz opcje
        </button>
        <a class="btn btn-outline-secondary" href="<?= site_url('admin/ustawienia') ?>">Wróć do ustawień</a>
    </div>
</form>

<?= $this->endSection() ?>
