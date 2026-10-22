<?php
$stan = is_array($stan ?? null) ? $stan : [];
$ustawiony = (bool) ($stan['ustawiony'] ?? false);
$skrot = (string) ($stan['skrot'] ?? '');
$id = (string) ($id ?? 'modalKlucz');
$pole = (string) ($pole ?? 'klucz');
?>
<div class="modal fade" id="<?= esc($id, 'attr') ?>" tabindex="-1" aria-labelledby="<?= esc($id, 'attr') ?>Label" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" action="<?= site_url('admin/pluginy/klucz') ?>" class="modal-content">
            <?= csrf_field() ?>
            <input type="hidden" name="zrodlo" value="<?= esc((string) ($zrodlo ?? ''), 'attr') ?>">
            <div class="modal-header">
                <h2 class="modal-title h5" id="<?= esc($id, 'attr') ?>Label"><?= esc((string) ($tytul ?? 'Klucz')) ?></h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
            </div>
            <div class="modal-body">
                <p class="small text-body-secondary mb-3">
                    Status:
                    <?php if ($ustawiony): ?>
                        <span class="badge text-bg-success"><?= esc((string) ($etykietaZrodla ?? 'ustawiony')) ?></span>
                        <?php if ($skrot !== ''): ?>
                            <code class="ms-1"><?= esc($skrot) ?></code>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="badge text-bg-secondary">brak klucza</span>
                    <?php endif; ?>
                </p>
                <div class="mb-3">
                    <a class="btn btn-sm btn-outline-secondary"
                       href="<?= esc((string) ($link ?? '#')) ?>"
                       target="_blank" rel="noopener noreferrer">
                        <?= esc((string) ($linkTekst ?? 'Skąd wziąć klucz')) ?>
                    </a>
                    <div class="form-text mt-1"><?= esc((string) ($opisLinku ?? '')) ?></div>
                </div>
                <label class="form-label" for="<?= esc($pole, 'attr') ?>"><?= esc((string) ($labelPola ?? 'Klucz')) ?></label>
                <div class="input-group">
                    <input type="password" class="form-control font-monospace" id="<?= esc($pole, 'attr') ?>"
                           name="<?= esc($pole, 'attr') ?>" autocomplete="off"
                           placeholder="<?= esc((string) ($placeholder ?? ''), 'attr') ?>">
                    <button type="button" class="btn btn-outline-secondary" data-sekret-toggle="<?= esc($pole, 'attr') ?>"
                            title="Pokaż / ukryj" aria-label="Pokaż lub ukryj klucz">
                        <i class="bi bi-eye" aria-hidden="true"></i>
                    </button>
                </div>
                <div class="form-text">
                    Puste pole i Zapisz usuwa klucz z panelu. Jeśli klucz jest też w pliku .env, wraca ten z pliku.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1" aria-hidden="true"></i>Zapisz klucz
                </button>
            </div>
        </form>
    </div>
</div>
