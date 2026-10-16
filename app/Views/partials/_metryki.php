<?php
/** @var list<array{0: string, 1: string, 2: string}> $pary */
$pary = is_array($pary ?? null) ? $pary : [];
?>
<div class="d-flex flex-wrap column-gap-3 row-gap-2">
    <?php foreach ($pary as [$etykieta, $wartosc, $klasa]): ?>
        <div class="flex-shrink-0">
            <div class="text-body-secondary small mb-1"><?= esc($etykieta) ?></div>
            <div class="fw-semibold lh-sm <?= mb_strlen($wartosc) <= 28 ? 'text-nowrap' : '' ?> <?= esc($klasa, 'attr') ?>"><?= esc($wartosc) ?></div>
        </div>
    <?php endforeach; ?>
</div>
