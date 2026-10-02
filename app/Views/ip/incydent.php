<?= $this->extend('layouts/mcs') ?>
<?= $this->section('content') ?>

<?php
$badgeWaga = static function (string $waga): string {
    return match ($waga) {
        'critical' => 'text-bg-danger',
        'high' => 'text-bg-warning',
        'medium' => 'text-bg-secondary',
        'low' => 'text-bg-success',
        default => 'text-bg-light text-dark border',
    };
};
?>

<p class="mb-4">
    <a class="btn btn-sm btn-outline-secondary" href="<?= site_url('ip/' . $ip) ?>">
        <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>
        Powrót do IP <span class="font-monospace"><?= esc($ip) ?></span>
    </a>
</p>

<?php if (! empty($blad)): ?>
    <h1 class="h3">Szczegóły incydentu</h1>
    <div class="alert alert-danger border-0 shadow-sm rounded-3" role="alert"><?= esc($blad) ?></div>
<?php else: ?>
    <?php
    $inc = $szczegoly['incydent'] ?? [];
    $waga = (string) ($inc['waga'] ?? '');
    $port = $inc['port'] ?? null;
    $protokol = $inc['protokol'] ?? null;
    $portTekst = $port !== null && $port !== ''
        ? (string) $port . ($protokol ? '/' . $protokol : '')
        : '—';
    ?>
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <h1 class="h3 mb-2"><?= esc((string) ($inc['tytul'] ?? 'Incydent')) ?></h1>
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <?php if ($waga !== ''): ?>
                    <span class="badge <?= esc($badgeWaga($waga), 'attr') ?>"><?= esc(strtoupper($waga)) ?></span>
                <?php endif; ?>
                <span class="badge text-bg-info-subtle text-info-emphasis border border-info-subtle">
                    <?= esc((new \App\Libraries\EtykietyUi())->zrodlo((string) ($inc['zrodlo'] ?? ''))) ?>
                </span>
                <span class="text-body-secondary small">
                    kategoria <?= esc((string) ($inc['kategoria'] ?? '—')) ?>
                    · port <?= esc($portTekst) ?>
                    · IP <a class="font-monospace" href="<?= site_url('ip/' . $ip) ?>"><?= esc($ip) ?></a>
                </span>
            </div>
        </div>
        <form method="post"
              action="<?= site_url('ip/' . $ip . '/incydent/' . (int) ($inc['id'] ?? 0) . '/ukryj') ?>"
              class="m-0"
              onsubmit="return confirm('Ukryć ten incydent w UI? (triage FP — dane zostają w bazie)');">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-outline-secondary">
                <i class="bi bi-eye-slash me-1" aria-hidden="true"></i>Ukryj w UI
            </button>
        </form>
    </div>

    <?php if (! empty($flashOk)): ?>
        <div class="alert alert-success border-0 shadow-sm rounded-3" role="status"><?= esc($flashOk) ?></div>
    <?php endif; ?>
    <?php if (! empty($flashBlad)): ?>
        <div class="alert alert-danger border-0 shadow-sm rounded-3" role="alert"><?= esc($flashBlad) ?></div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-3 mb-4">
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-3 col-md-2 text-body-secondary">Pierwsze widziane</dt>
                <dd class="col-sm-9 col-md-10"><?= esc((string) ($inc['pierwsze_widziane'] ?? '—')) ?></dd>
                <dt class="col-sm-3 col-md-2 text-body-secondary">Ostatnio widziane</dt>
                <dd class="col-sm-9 col-md-10"><?= esc((string) ($inc['ostatnio_widziane'] ?? '—')) ?></dd>
                <dt class="col-sm-3 col-md-2 text-body-secondary">Klucz dedupu</dt>
                <dd class="col-sm-9 col-md-10"><code><?= esc((string) ($inc['klucz_dedupu'] ?? '—')) ?></code></dd>
                <dt class="col-sm-3 col-md-2 text-body-secondary">ID</dt>
                <dd class="col-sm-9 col-md-10 font-monospace">#<?= esc((string) ($inc['id'] ?? '')) ?></dd>
            </dl>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-3 mb-4">
        <div class="card-header bg-transparent border-0 pt-3 px-3 pb-0">
            <h2 class="h5 mb-0">
                Dane ze źródła
                <?php if (! empty($szczegoly['zrodlo_tabela'])): ?>
                    <span class="text-body-secondary fw-normal small">(<?= esc((string) $szczegoly['zrodlo_tabela']) ?>)</span>
                <?php endif; ?>
            </h2>
        </div>
        <div class="card-body pt-3">
            <?php if (empty($szczegoly['zrodlo_wiersz'])): ?>
                <p class="text-body-secondary mb-0">Brak wiersza w tabeli źródła (id_w_zrodle puste lub nieznane).</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <tbody>
                        <?php foreach ($szczegoly['zrodlo_wiersz'] as $klucz => $wartosc): ?>
                            <?php
                            if ($klucz === 'payload_json' || $klucz === 'payload') {
                                continue;
                            }
                            if (is_array($wartosc) || is_object($wartosc)) {
                                $wartosc = json_encode($wartosc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                            }
                            if ($wartosc === null || $wartosc === '') {
                                $wartosc = '—';
                            }
                            ?>
                            <tr>
                                <th scope="row" class="text-body-secondary fw-normal" style="width:12rem"><?= esc((string) $klucz) ?></th>
                                <td><?= esc((string) $wartosc) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (! empty($szczegoly['zrodlo_wiersz']['payload']) && is_array($szczegoly['zrodlo_wiersz']['payload'])): ?>
                    <h3 class="h6 mt-4 mb-2">Payload źródła (JSON)</h3>
                    <pre class="payload bg-body-secondary text-body p-3 rounded-3 border mb-0"><?= esc(json_encode($szczegoly['zrodlo_wiersz']['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-3 mb-4">
        <div class="card-header bg-transparent border-0 pt-3 px-3 pb-0">
            <h2 class="h5 mb-0">Historia obserwacji</h2>
        </div>
        <div class="card-body pt-3">
            <?php if (empty($szczegoly['obserwacje'])): ?>
                <p class="text-body-secondary mb-0">Brak obserwacji w mcs_obserwacje.</p>
            <?php else: ?>
                <ol class="list-group list-group-numbered list-group-flush">
                    <?php foreach ($szczegoly['obserwacje'] as $obs): ?>
                        <li class="list-group-item bg-transparent px-0">
                            <div class="fw-semibold text-primary"><?= esc((string) ($obs['zaobserwowano_at'] ?? '')) ?></div>
                            <div class="small text-body-secondary">
                                id_w_zrodle: <?= esc((string) ($obs['id_w_zrodle'] ?? '—')) ?>
                                <?php if (! empty($obs['import_id'])): ?>
                                    · import #<?= esc((string) $obs['import_id']) ?>
                                <?php endif; ?>
                            </div>
                            <?php if (! empty($obs['payload']) && is_array($obs['payload'])): ?>
                                <pre class="payload bg-body-secondary text-body p-3 rounded-3 border mt-2 mb-0"><?= esc(json_encode($obs['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?= $this->endSection() ?>
