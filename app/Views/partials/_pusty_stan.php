<?php
$tekst = trim((string) ($tekst ?? 'Brak danych.'));
$opis  = trim((string) ($opis ?? ''));
$ikona = trim((string) ($ikona ?? 'bi-inbox'));
?>
<div class="mcs-empty-state text-center px-3 py-4">
    <i class="bi <?= esc($ikona, 'attr') ?> fs-3 text-body-tertiary" aria-hidden="true"></i>
    <p class="mb-0 mt-2 text-body-secondary"><?= esc($tekst) ?></p>
    <?php if ($opis !== ''): ?>
        <p class="small text-body-tertiary mb-0 mt-1"><?= esc($opis) ?></p>
    <?php endif; ?>
</div>
