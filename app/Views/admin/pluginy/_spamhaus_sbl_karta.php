<?php
/**
 * Karta Spamhaus SBL (e-mail) w /admin/pluginy. Dane z McsPluginy\spamhaus_sbl\Widok.
 * Link do check.spamhaus.org tylko dla człowieka — MCS go nie odpytuje.
 *
 * @var array<string, mixed> $k
 */
$hOk   = $k['health_ok'] ?? null;
$hMsg  = \App\Libraries\PluginOpisPolaczenia::bezZargonu((string) ($k['health_msg'] ?? ''));
$hAt   = \App\Libraries\CzasUi::dataGodzina((string) ($k['health_at'] ?? ''));
$sbl   = (string) ($k['ostatni_sbl'] ?? '');
$sblAt = \App\Libraries\CzasUi::dataGodzina((string) ($k['ostatni_sbl_at'] ?? ''));
$link  = $k['ostatni_sbl_link'] ?? null;
[$hKlasa, $hTekst] = match (true) {
    $hOk === true  => ['text-bg-success', 'OK'],
    $hOk === false => ['text-bg-danger', 'błąd'],
    default        => ['text-bg-secondary', 'brak danych'],
};
?>
<div class="card border-0 shadow-sm rounded-3 mb-4" id="spamhaus-sbl-karta">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
            <h2 class="h5 mb-0">Spamhaus SBL (e-mail)
                <span class="badge <?= $hKlasa ?> align-middle fs-6"><?= esc($hTekst) ?></span>
            </h2>
            <?php if ($hAt !== ''): ?>
                <span class="small text-body-secondary">health: <?= esc($hAt) ?></span>
            <?php endif; ?>
        </div>
        <?php if ($hMsg !== ''): ?>
            <p class="small text-body-secondary text-break mb-3"><?= esc($hMsg) ?></p>
        <?php endif; ?>
        <div class="row g-3 text-center mb-3">
            <div class="col-6 col-md-3">
                <div class="fs-4 fw-semibold"><?= (int) ($k['przetworzone'] ?? 0) ?></div>
                <div class="small text-body-secondary">przetworzone</div>
            </div>
            <div class="col-6 col-md-3">
                <div class="fs-4 fw-semibold"><?= (int) ($k['pominiete'] ?? 0) ?></div>
                <div class="small text-body-secondary">pominięte</div>
            </div>
            <div class="col-6 col-md-3">
                <div class="fs-4 fw-semibold text-warning-emphasis"><?= (int) ($k['nieuwierzytelnione'] ?? 0) ?></div>
                <div class="small text-body-secondary">nieuwierzytelniony nadawca</div>
            </div>
            <div class="col-6 col-md-3">
                <div class="fs-4 fw-semibold font-monospace"><?= $sbl !== '' ? esc($sbl) : '—' ?></div>
                <div class="small text-body-secondary">ostatni numer SBL<?= $sblAt !== '' ? ' · ' . esc($sblAt) : '' ?></div>
            </div>
        </div>
        <?php if (is_string($link) && $link !== ''): ?>
            <p class="small mb-0">
                Ręczny przegląd:
                <a href="<?= esc($link, 'attr') ?>" target="_blank" rel="noopener noreferrer nofollow"><?= esc($link) ?></a>
                <span class="text-body-secondary">(MCS nie wysyła zapytań do Spamhaus)</span>
            </p>
        <?php endif; ?>
    </div>
</div>
