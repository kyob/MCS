<?php
$wyczyscHref  = (string) ($wyczyscHref ?? site_url('/'));
$pokazWyczysc = (bool) ($pokazWyczysc ?? true);
?>
<div class="btn-group mcs-filter-actions" role="group" aria-label="Akcje filtrów">
    <button type="submit" class="btn btn-primary"
            aria-label="Zastosuj filtry" title="Zastosuj filtry"
            data-bs-toggle="tooltip" data-bs-placement="top">
        <i class="bi bi-funnel" aria-hidden="true"></i>
        <span class="visually-hidden">Zastosuj filtry</span>
    </button>
    <?php if ($pokazWyczysc): ?>
    <a class="btn btn-outline-secondary" href="<?= esc($wyczyscHref) ?>"
       aria-label="Wyczyść filtry" title="Wyczyść filtry"
       data-bs-toggle="tooltip" data-bs-placement="top">
        <i class="bi bi-x-lg" aria-hidden="true"></i>
        <span class="visually-hidden">Wyczyść filtry</span>
    </a>
    <?php endif; ?>
</div>
