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

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">
            IP <span class="font-monospace"><?= esc($ip !== '' ? $ip : '—') ?></span>
        </h1>
        <?php if (! empty($revDnsWlaczone)): ?>
            <?php if (! empty($revDns)): ?>
                <p class="text-body-secondary mb-1">
                    <span class="small text-uppercase text-body-secondary me-1">reverse DNS</span>
                    <span class="font-monospace"><?= esc((string) $revDns) ?></span>
                </p>
            <?php else: ?>
                <p class="text-body-secondary small mb-1">reverse DNS: brak PTR</p>
            <?php endif; ?>
        <?php endif; ?>
        <p class="text-body-secondary mb-0">Status + problemy zagregowane — triage bez osobnej karty na każde zdarzenie.</p>
    </div>
</div>

<?php if (! empty($asMatchWlaczone) && empty($blad)): ?>
    <?php if (! empty($naszeIp)): ?>
        <div class="alert alert-success border-0 shadow-sm rounded-3 py-2 mb-4" role="status">
            <i class="bi bi-check-circle-fill me-1" aria-hidden="true"></i>
            Adres należy do przestrzeni AS198050 (CIDR z <a href="<?= site_url('admin/ustawienia') ?>" class="alert-link">ustawień</a>).
        </div>
    <?php else: ?>
        <p class="text-body-secondary small mb-4">
            Poza AS198050 — brak trafienia w prefiksy CIDR
            (<a href="<?= site_url('admin/ustawienia') ?>">ustawienia</a>).
        </p>
    <?php endif; ?>
<?php endif; ?>

<?php if (! empty($flashOk)): ?>
    <div class="alert alert-success border-0 shadow-sm rounded-3" role="status"><?= esc($flashOk) ?></div>
<?php endif; ?>
<?php if (! empty($flashBlad)): ?>
    <div class="alert alert-danger border-0 shadow-sm rounded-3" role="alert"><?= esc($flashBlad) ?></div>
<?php endif; ?>

<?php if (! empty($blad)): ?>
    <div class="alert alert-danger border-0 shadow-sm rounded-3" role="alert"><?= esc($blad) ?></div>
<?php else: ?>
    <?php
    $statusIp = is_array($statusIp ?? null) ? $statusIp : [];
    $problemy = is_array($problemy ?? null) ? $problemy : [];
    $maxWaga  = (string) ($statusIp['max_waga'] ?? '');
    $borderStatus = in_array($maxWaga, ['critical', 'high', 'medium', 'low'], true)
        ? ' border-waga-' . $maxWaga
        : '';
    $liczbaProblemow = (int) ($statusIp['liczba_problemow'] ?? 0);
    $topTytuly = is_array($statusIp['top_tytuly'] ?? null) ? $statusIp['top_tytuly'] : [];
    $lms = is_array($lmsCache ?? null) ? $lmsCache : [];
    $lmsTrafiony = ! empty($lms['trafiony']);
    $lmsDisabled = (! empty($lmsKomunikat) && ($lmsTryb ?? '') === 'brak_konfiguracji');
    ?>

    <div class="card border-0 shadow-sm rounded-3 mb-4<?= esc($borderStatus, 'attr') ?>">
        <div class="card-body">
            <div class="d-flex flex-wrap gap-2 align-items-center" aria-label="Status IP">
                <?php if ($maxWaga !== ''): ?>
                    <span class="badge <?= esc($badgeWaga($maxWaga), 'attr') ?>"><?= esc(strtoupper($maxWaga)) ?></span>
                <?php else: ?>
                    <span class="badge text-bg-light text-dark border">brak wagi</span>
                <?php endif; ?>
                <strong><?= $liczbaProblemow ?> <?= $liczbaProblemow === 1 ? 'problem' : 'problemów' ?></strong>
                <?php if ($topTytuly !== []): ?>
                    <span class="text-body-secondary small">
                        Najgorsze: <?= esc(implode(' · ', $topTytuly)) ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="mt-2 small" id="status-lms-skrot">
                <?php if ($lmsTrafiony): ?>
                    <span class="text-body-secondary">LMS:</span>
                    <strong><?= esc((string) ($lms['komputer'] ?? '—')) ?></strong>
                    <?php if (($lms['abonent'] ?? '') !== ''): ?>
                        · <?= esc((string) $lms['abonent']) ?>
                    <?php endif; ?>
                <?php elseif (! empty($lmsCache)): ?>
                    <span class="text-body-secondary">LMS: brak hosta dla tego IP</span>
                <?php elseif (! empty($lmsKomunikat)): ?>
                    <span class="text-body-secondary">LMS: niedostępne</span>
                <?php else: ?>
                    <span class="text-body-secondary">LMS: brak danych</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-3 mb-4<?= $lmsTrafiony ? ' border-start border-4 border-primary' : '' ?>" id="panel-lms">
        <div class="card-body py-3">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                <div>
                    <h2 class="h5 mb-1">
                        <i class="bi bi-hdd-network me-1 text-primary" aria-hidden="true"></i>
                        LMS — komputer / abonent
                    </h2>
                    <p class="text-body-secondary small mb-0">
                        Mapowanie z LMS (cache TTL). Nie zmienia indeksu ani badge.
                    </p>
                </div>
                <span class="small text-body-secondary" id="lms-status">
                    tryb: <?= esc((string) ($lmsTryb ?? '—')) ?>
                </span>
            </div>

            <?php if (! empty($lmsKomunikat)): ?>
                <div class="alert alert-warning border-0 rounded-3 py-2 mb-2" id="lms-komunikat" role="status"><?= esc($lmsKomunikat) ?></div>
            <?php else: ?>
                <div class="alert alert-danger border-0 rounded-3 py-2 mb-2" id="lms-komunikat" role="alert" hidden></div>
            <?php endif; ?>

            <div id="lms-wynik" class="mb-2" <?= empty($lmsCache) ? 'hidden' : '' ?>>
                <div class="d-flex flex-wrap gap-3 align-items-baseline" id="lms-trafiony-box" <?= $lmsTrafiony ? '' : 'hidden' ?>>
                    <div>
                        <div class="small text-body-secondary">Komputer</div>
                        <div class="fs-5 fw-semibold" id="lms-komputer"><?= esc((string) (($lms['komputer'] ?? '') !== '' ? $lms['komputer'] : '—')) ?></div>
                    </div>
                    <div>
                        <div class="small text-body-secondary">Abonent</div>
                        <div class="fs-5" id="lms-abonent"><?= esc((string) (($lms['abonent'] ?? '') !== '' ? $lms['abonent'] : '—')) ?></div>
                    </div>
                    <div class="small text-body-secondary align-self-end" id="lms-meta">
                        node #<span id="lms-node-id"><?= esc((string) (($lms['node_id'] ?? '') !== '' ? $lms['node_id'] : '—')) ?></span>
                        · klient #<span id="lms-customer-id"><?= esc((string) (($lms['customer_id'] ?? '') !== '' ? $lms['customer_id'] : '—')) ?></span>
                        <span id="lms-link-wrap" <?= empty($lms['lms_url']) ? 'hidden' : '' ?>>
                            · <a href="<?= esc((string) ($lms['lms_url'] ?? '#'), 'attr') ?>" target="_blank" rel="noopener" id="lms-link">otwórz w LMS</a>
                        </span>
                    </div>
                </div>
                <p class="text-body-secondary small mb-0" id="lms-brak" <?= (! empty($lmsCache) && ! $lmsTrafiony) ? '' : 'hidden' ?>>
                    Brak hosta w LMS dla tego IP.
                </p>
            </div>

            <div class="d-flex flex-wrap gap-2 align-items-center">
                <button type="button" class="btn btn-primary btn-sm" id="lms-sprawdz"
                    data-url="<?= site_url('ip/' . $ip . '/enrichment/lms') ?>" data-metoda="post"
                    <?= $lmsDisabled ? 'disabled' : '' ?>>
                    Sprawdź w LMS
                </button>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="lms-force"
                    data-url="<?= site_url('ip/' . $ip . '/enrichment/lms') ?>" data-metoda="post" data-force="1"
                    <?= $lmsDisabled ? 'disabled' : '' ?>>
                    Odśwież (force)
                </button>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-3 mb-4">
        <div class="card-body py-3">
            <div class="small text-body-secondary mb-2">Filtr źródła</div>
            <div class="btn-group flex-wrap" role="group" aria-label="Filtr źródła">
                <a href="<?= site_url('ip/' . $ip) ?>"
                   class="btn <?= $filtrZrodlo === null ? 'btn-primary' : 'btn-outline-secondary' ?>">wszystkie</a>
                <?php foreach ($zrodla as $z): ?>
                    <a href="<?= site_url('ip/' . $ip) . '?zrodlo=' . rawurlencode($z) ?>"
                       class="btn <?= $filtrZrodlo === $z ? 'btn-primary' : 'btn-outline-secondary' ?>"><?= esc((new \App\Libraries\EtykietyUi())->zrodlo((string) $z)) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-3 mb-4">
        <div class="card-header bg-transparent border-0 pt-3 px-3 pb-0">
            <h2 class="h5 mb-0">Problemy</h2>
        </div>
        <div class="card-body pt-3">
            <?php if ($problemy === []): ?>
                <p class="text-body-secondary mb-0">Brak widocznych problemów dla tego IP.</p>
            <?php else: ?>
                <div class="accordion accordion-flush" id="accordionProblemy" aria-label="Zagregowane problemy">
                    <?php foreach ($problemy as $i => $problem): ?>
                        <?php
                        $waga = (string) ($problem['waga'] ?? '');
                        $borderProb = in_array($waga, ['critical', 'high', 'medium', 'low'], true)
                            ? ' border-waga-' . $waga
                            : '';
                        $liczba = (int) ($problem['liczba_zdarzen'] ?? 0);
                        $timeline = is_array($problem['timeline'] ?? null) ? $problem['timeline'] : [];
                        $czlonkowie = is_array($problem['czlonkowie'] ?? null) ? $problem['czlonkowie'] : [];
                        $collapseId = 'prob-' . $i;
                        $headingId = 'prob-h-' . $i;
                        ?>
                        <div class="accordion-item bg-transparent<?= esc($borderProb, 'attr') ?>">
                            <h3 class="accordion-header" id="<?= esc($headingId, 'attr') ?>">
                                <button class="accordion-button collapsed" type="button"
                                        data-bs-toggle="collapse" data-bs-target="#<?= esc($collapseId, 'attr') ?>"
                                        aria-expanded="false" aria-controls="<?= esc($collapseId, 'attr') ?>">
                                    <span class="d-flex flex-column gap-1 w-100 pe-2">
                                        <span class="d-flex flex-wrap gap-1 align-items-center">
                                            <span class="badge text-bg-info-subtle text-info-emphasis border border-info-subtle">
                                                <?= esc((string) ($problem['zrodlo_etykieta'] ?? '')) ?>
                                            </span>
                                            <?php if ($waga !== ''): ?>
                                                <span class="badge <?= esc($badgeWaga($waga), 'attr') ?>"><?= esc(strtoupper($waga)) ?></span>
                                            <?php endif; ?>
                                            <?php if (($problem['kategoria'] ?? '') !== '' && strcasecmp((string) $problem['kategoria'], 'other') !== 0): ?>
                                                <span class="badge text-bg-secondary-subtle text-secondary-emphasis border">
                                                    <?= esc((string) ($problem['kategoria_etykieta'] ?? '')) ?>
                                                </span>
                                            <?php endif; ?>
                                            <span class="fw-semibold"><?= esc((string) ($problem['tytul'] ?? 'Problem')) ?></span>
                                        </span>
                                        <span class="small text-body-secondary fw-normal">
                                            port <?= esc((string) ($problem['port_tekst'] ?? '—')) ?>
                                            · <?= $liczba ?> <?php
                                                echo match (true) {
                                                    $liczba === 1 => 'zdarzenie',
                                                    $liczba >= 2 && $liczba <= 4 => 'zdarzenia',
                                                    default => 'zdarzeń',
                                                };
                                            ?>
                                            · <?= esc((string) ($problem['pierwsze_widziane'] ?? '—')) ?>
                                            → <?= esc((string) ($problem['ostatnio_widziane'] ?? '—')) ?>
                                        </span>
                                        <?php if ($timeline !== []): ?>
                                            <span class="mini-timeline" aria-label="Aktywność 14 dni" title="Aktywność w ostatnich 14 dniach">
                                                <?php foreach ($timeline as $dzien): ?>
                                                    <span class="mini-timeline-slupek<?= ! empty($dzien['aktywny']) ? ' aktywny' : '' ?>"
                                                          title="<?= esc((string) ($dzien['dzien'] ?? ''), 'attr') ?>"></span>
                                                <?php endforeach; ?>
                                            </span>
                                        <?php endif; ?>
                                    </span>
                                </button>
                            </h3>
                            <div id="<?= esc($collapseId, 'attr') ?>" class="accordion-collapse collapse"
                                 aria-labelledby="<?= esc($headingId, 'attr') ?>" data-bs-parent="#accordionProblemy">
                                <div class="accordion-body py-2">
                                    <?php if ($czlonkowie === []): ?>
                                        <p class="text-body-secondary mb-0">Brak zdarzeń w grupie.</p>
                                    <?php else: ?>
                                        <ul class="list-group list-group-flush">
                                            <?php foreach ($czlonkowie as $cz): ?>
                                                <?php $czId = (int) ($cz['id'] ?? 0); ?>
                                                <li class="list-group-item bg-transparent px-0 d-flex flex-wrap gap-2 align-items-baseline justify-content-between">
                                                    <span class="d-flex flex-wrap gap-2 align-items-baseline">
                                                        <a href="<?= esc((string) ($cz['url'] ?? ''), 'attr') ?>">Timeline i pełne dane</a>
                                                        <span class="small text-body-secondary">
                                                            #<?= $czId ?>
                                                            · <?= esc((string) ($cz['waga'] ?? '')) ?>
                                                            · <?= esc((string) ($cz['ostatnio_widziane'] ?? '')) ?>
                                                        </span>
                                                    </span>
                                                    <?php if ($czId > 0): ?>
                                                        <form method="post"
                                                              action="<?= site_url('ip/' . $ip . '/incydent/' . $czId . '/ukryj') ?>"
                                                              class="m-0"
                                                              onsubmit="return confirm('Ukryć ten incydent w UI? (triage FP — dane zostają w bazie)');">
                                                            <?= csrf_field() ?>
                                                            <button type="submit" class="btn btn-sm btn-outline-secondary">
                                                                Ukryj w UI
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php if (empty($blad)): ?>
<div class="card border-0 shadow-sm rounded-3" id="panel-abuseipdb">
    <div class="card-header bg-transparent border-0 pt-3 px-3 pb-0">
        <h2 class="h5 mb-0">
            <i class="bi bi-search me-1 text-primary" aria-hidden="true"></i>
            Enrichment — AbuseIPDB
        </h2>
    </div>
    <div class="card-body">
        <p class="card-text text-body-secondary small">
            Lookup reputacji na żądanie (cache TTL). Nie zmienia indeksu ani badge.
        </p>

        <?php if (! empty($abuseipdbKomunikat)): ?>
            <div class="alert alert-danger border-0 rounded-3 py-2" id="abuseipdb-komunikat" role="alert"><?= esc($abuseipdbKomunikat) ?></div>
        <?php else: ?>
            <div class="alert alert-danger border-0 rounded-3 py-2" id="abuseipdb-komunikat" role="alert" hidden></div>
        <?php endif; ?>

        <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
            <button type="button" class="btn btn-primary" id="abuseipdb-sprawdz"
                data-url="<?= site_url('ip/' . $ip . '/enrichment/abuseipdb') ?>" data-metoda="post"
                <?= (! empty($abuseipdbKomunikat) && ($abuseipdbTryb ?? '') === 'brak_klucza') ? 'disabled' : '' ?>>
                Sprawdź AbuseIPDB
            </button>
            <button type="button" class="btn btn-outline-secondary" id="abuseipdb-force"
                data-url="<?= site_url('ip/' . $ip . '/enrichment/abuseipdb') ?>" data-metoda="post" data-force="1"
                <?= (! empty($abuseipdbKomunikat) && ($abuseipdbTryb ?? '') === 'brak_klucza') ? 'disabled' : '' ?>>
                Odśwież (force)
            </button>
            <span class="small text-body-secondary" id="abuseipdb-status">
                tryb: <?= esc((string) ($abuseipdbTryb ?? '—')) ?>
            </span>
        </div>

        <div id="abuseipdb-wynik" class="enrichment-wynik" <?= empty($abuseipdbCache) ? 'hidden' : '' ?>>
            <?php $ab = is_array($abuseipdbCache ?? null) ? $abuseipdbCache : []; ?>
            <dl class="row mb-0 small">
                <dt class="col-sm-3 col-md-2 text-body-secondary">Score</dt>
                <dd class="col-sm-9 col-md-10" id="ab-score"><?= esc((string) (($ab['abuse_confidence_score'] ?? '') !== '' ? $ab['abuse_confidence_score'] : '—')) ?></dd>
                <dt class="col-sm-3 col-md-2 text-body-secondary">Raporty</dt>
                <dd class="col-sm-9 col-md-10" id="ab-reports"><?= esc((string) (($ab['total_reports'] ?? '') !== '' ? $ab['total_reports'] : '—')) ?></dd>
                <dt class="col-sm-3 col-md-2 text-body-secondary">Kraj</dt>
                <dd class="col-sm-9 col-md-10" id="ab-cc"><?= esc((string) (($ab['country_code'] ?? '') !== '' ? $ab['country_code'] : '—')) ?></dd>
                <dt class="col-sm-3 col-md-2 text-body-secondary">ISP</dt>
                <dd class="col-sm-9 col-md-10" id="ab-isp"><?= esc((string) (($ab['isp'] ?? '') !== '' ? $ab['isp'] : '—')) ?></dd>
                <dt class="col-sm-3 col-md-2 text-body-secondary">Typ</dt>
                <dd class="col-sm-9 col-md-10" id="ab-usage"><?= esc((string) (($ab['usage_type'] ?? '') !== '' ? $ab['usage_type'] : '—')) ?></dd>
                <dt class="col-sm-3 col-md-2 text-body-secondary">Ostatni raport</dt>
                <dd class="col-sm-9 col-md-10" id="ab-last"><?= esc((string) (($ab['last_reported_at'] ?? '') !== '' ? $ab['last_reported_at'] : '—')) ?></dd>
                <dt class="col-sm-3 col-md-2 text-body-secondary">Checked at</dt>
                <dd class="col-sm-9 col-md-10" id="ab-checked"><?= esc((string) (($ab['checked_at'] ?? '') !== '' ? $ab['checked_at'] : '—')) ?></dd>
                <dt class="col-sm-3 col-md-2 text-body-secondary">Źródło danych</dt>
                <dd class="col-sm-9 col-md-10" id="ab-zrodlo"><?= esc((string) (($ab['zrodlo_danych'] ?? '') !== '' ? $ab['zrodlo_danych'] : '—')) ?></dd>
            </dl>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm rounded-3" id="panel-ismalicious">
    <div class="card-header bg-transparent border-0 pt-3 px-3 pb-0">
        <h2 class="h5 mb-0">
            <i class="bi bi-shield-exclamation me-1 text-danger" aria-hidden="true"></i>
            Enrichment — isMalicious
        </h2>
    </div>
    <div class="card-body">
        <p class="card-text text-body-secondary small">
            Lookup reputacji IP (API isMalicious, cache TTL). Nie zmienia indeksu ani badge.
        </p>

        <?php if (! empty($ismaliciousKomunikat)): ?>
            <div class="alert alert-danger border-0 rounded-3 py-2" id="ismalicious-komunikat" role="alert"><?= esc($ismaliciousKomunikat) ?></div>
        <?php else: ?>
            <div class="alert alert-danger border-0 rounded-3 py-2" id="ismalicious-komunikat" role="alert" hidden></div>
        <?php endif; ?>

        <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
            <button type="button" class="btn btn-primary" id="ismalicious-sprawdz"
                data-url="<?= site_url('ip/' . $ip . '/enrichment/ismalicious') ?>" data-metoda="post"
                <?= (! empty($ismaliciousKomunikat) && ($ismaliciousTryb ?? '') === 'brak_klucza') ? 'disabled' : '' ?>>
                Sprawdź isMalicious
            </button>
            <button type="button" class="btn btn-outline-secondary" id="ismalicious-force"
                data-url="<?= site_url('ip/' . $ip . '/enrichment/ismalicious') ?>" data-metoda="post" data-force="1"
                <?= (! empty($ismaliciousKomunikat) && ($ismaliciousTryb ?? '') === 'brak_klucza') ? 'disabled' : '' ?>>
                Odśwież (force)
            </button>
            <span class="small text-body-secondary" id="ismalicious-status">
                tryb: <?= esc((string) ($ismaliciousTryb ?? '—')) ?>
            </span>
        </div>

        <div id="ismalicious-wynik" class="enrichment-wynik" <?= empty($ismaliciousCache) ? 'hidden' : '' ?>>
            <?php $im = is_array($ismaliciousCache ?? null) ? $ismaliciousCache : []; ?>
            <?php
            $imMal = $im['malicious'] ?? null;
            if (is_string($imMal)) {
                $imMal = \App\Models\PluginModel::jakoBool($imMal);
            }
            $imListed = $im['blocklist_listed'] ?? null;
            if (is_string($imListed)) {
                $imListed = \App\Models\PluginModel::jakoBool($imListed);
            }
            ?>
            <dl class="row mb-0 small">
                <dt class="col-sm-3 col-md-2 text-body-secondary">Malicious</dt>
                <dd class="col-sm-9 col-md-10" id="im-malicious"><?= $imMal === null ? '—' : ($imMal ? 'tak' : 'nie') ?></dd>
                <dt class="col-sm-3 col-md-2 text-body-secondary">Risk score</dt>
                <dd class="col-sm-9 col-md-10" id="im-risk"><?= esc((string) (($im['risk_score'] ?? '') !== '' && $im['risk_score'] !== null ? $im['risk_score'] : '—')) ?></dd>
                <dt class="col-sm-3 col-md-2 text-body-secondary">Confidence</dt>
                <dd class="col-sm-9 col-md-10" id="im-confidence"><?= esc((string) (($im['confidence'] ?? '') !== '' && $im['confidence'] !== null ? $im['confidence'] : '—')) ?></dd>
                <dt class="col-sm-3 col-md-2 text-body-secondary">Blocklist hits</dt>
                <dd class="col-sm-9 col-md-10" id="im-hits"><?= esc((string) (($im['blocklist_hits'] ?? '') !== '' && $im['blocklist_hits'] !== null ? $im['blocklist_hits'] : '—')) ?></dd>
                <dt class="col-sm-3 col-md-2 text-body-secondary">Na listach</dt>
                <dd class="col-sm-9 col-md-10" id="im-listed"><?= $imListed === null ? '—' : ($imListed ? 'tak' : 'nie') ?></dd>
                <dt class="col-sm-3 col-md-2 text-body-secondary">Klasyfikacja</dt>
                <dd class="col-sm-9 col-md-10" id="im-class"><?= esc((string) (($im['classification'] ?? '') !== '' ? $im['classification'] : '—')) ?></dd>
                <dt class="col-sm-3 col-md-2 text-body-secondary">Checked at</dt>
                <dd class="col-sm-9 col-md-10" id="im-checked"><?= esc((string) (($im['checked_at'] ?? '') !== '' ? $im['checked_at'] : '—')) ?></dd>
                <dt class="col-sm-3 col-md-2 text-body-secondary">Źródło danych</dt>
                <dd class="col-sm-9 col-md-10" id="im-zrodlo"><?= esc((string) (($im['zrodlo_danych'] ?? '') !== '' ? $im['zrodlo_danych'] : '—')) ?></dd>
            </dl>
        </div>
    </div>
</div>

</div>

<div class="card border-0 shadow-sm rounded-3 mt-4" id="panel-greynoise">
    <div class="card-header bg-transparent border-0 pt-3 px-3 pb-0">
        <h2 class="h5 mb-0">
            <i class="bi bi-radar me-1 text-info" aria-hidden="true"></i>
            Enrichment — GreyNoise
        </h2>
    </div>
    <div class="card-body">
        <p class="card-text text-body-secondary small">
            Lookup Community (szum internetowy albo usługa RIOT). Cache 7 dni. Nie zmienia indeksu ani badge.
            Cron nie odpytuje API — „Odśwież” zużywa limit darmowego konta.
        </p>

        <?php if (! empty($greynoiseKomunikat)): ?>
            <div class="alert alert-danger border-0 rounded-3 py-2" id="greynoise-komunikat" role="alert"><?= esc($greynoiseKomunikat) ?></div>
        <?php else: ?>
            <div class="alert alert-danger border-0 rounded-3 py-2" id="greynoise-komunikat" role="alert" hidden></div>
        <?php endif; ?>

        <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
            <button type="button" class="btn btn-primary" id="greynoise-sprawdz"
                data-url="<?= site_url('ip/' . $ip . '/enrichment/greynoise') ?>" data-metoda="post"
                <?= (! empty($greynoiseKomunikat) && ($greynoiseTryb ?? '') === 'brak_klucza') ? 'disabled' : '' ?>>
                Sprawdź GreyNoise
            </button>
            <button type="button" class="btn btn-outline-secondary" id="greynoise-force"
                data-url="<?= site_url('ip/' . $ip . '/enrichment/greynoise') ?>" data-metoda="post" data-force="1"
                <?= (! empty($greynoiseKomunikat) && ($greynoiseTryb ?? '') === 'brak_klucza') ? 'disabled' : '' ?>>
                Odśwież (force)
            </button>
            <span class="small text-body-secondary" id="greynoise-status">
                tryb: <?= esc((string) ($greynoiseTryb ?? '—')) ?>
            </span>
        </div>

        <?php $gn = is_array($greynoiseCache ?? null) ? $greynoiseCache : []; ?>
        <div id="greynoise-wynik" class="enrichment-wynik" <?= $gn === [] ? 'hidden' : '' ?>>
            <dl class="row mb-0 small">
                <dt class="col-sm-3 col-md-2 text-body-secondary">Klasyfikacja</dt>
                <dd class="col-sm-9 col-md-10" id="gn-class"><?= esc((string) (($gn['classification'] ?? '') !== '' ? $gn['classification'] : '—')) ?></dd>
                <dt class="col-sm-3 col-md-2 text-body-secondary">Szum</dt>
                <dd class="col-sm-9 col-md-10" id="gn-noise"><?= ($gn['noise'] ?? null) === null ? '—' : ($gn['noise'] ? 'tak' : 'nie') ?></dd>
                <dt class="col-sm-3 col-md-2 text-body-secondary">RIOT</dt>
                <dd class="col-sm-9 col-md-10" id="gn-riot"><?= ($gn['riot'] ?? null) === null ? '—' : ($gn['riot'] ? 'tak' : 'nie') ?></dd>
                <dt class="col-sm-3 col-md-2 text-body-secondary">Nazwa</dt>
                <dd class="col-sm-9 col-md-10" id="gn-name"><?= esc((string) (($gn['name'] ?? '') !== '' ? $gn['name'] : '—')) ?></dd>
                <dt class="col-sm-3 col-md-2 text-body-secondary">Ostatnio widziany</dt>
                <dd class="col-sm-9 col-md-10" id="gn-last"><?= esc((string) (($gn['last_seen'] ?? '') !== '' ? $gn['last_seen'] : '—')) ?></dd>
                <dt class="col-sm-3 col-md-2 text-body-secondary">Link</dt>
                <dd class="col-sm-9 col-md-10" id="gn-link"><?php
                    $gnLink = (string) ($gn['link'] ?? '');
                    if (str_starts_with($gnLink, 'https://')) {
                        echo '<a href="' . esc($gnLink, 'attr') . '" rel="noopener noreferrer">' . esc($gnLink) . '</a>';
                    } else {
                        echo '—';
                    }
                ?></dd>
                <dt class="col-sm-3 col-md-2 text-body-secondary">Komunikat</dt>
                <dd class="col-sm-9 col-md-10" id="gn-message"><?= esc((string) (($gn['message'] ?? '') !== '' ? $gn['message'] : '—')) ?></dd>
            </dl>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm rounded-3 mt-4" id="panel-spamrats">
    <div class="card-header bg-transparent border-0 pt-3 px-3 pb-0">
        <h2 class="h5 mb-0">
            <i class="bi bi-envelope-exclamation me-1 text-warning" aria-hidden="true"></i>
            Enrichment — SpamRats
        </h2>
    </div>
    <div class="card-body">
        <p class="card-text text-body-secondary small">
            DNSBL RATS-All (Dyna / NoPtr / Spam / Auth) — lookup IPv4 na żądanie, cache TTL.
            Listy są przeznaczone do sprawdzania IP łączącego się serwera poczty, nie do monitoringu WWW.
            Nie zmienia indeksu ani badge.
        </p>

        <?php if (! empty($spamratsKomunikat)): ?>
            <div class="alert alert-danger border-0 rounded-3 py-2" id="spamrats-komunikat" role="alert"><?= esc($spamratsKomunikat) ?></div>
        <?php else: ?>
            <div class="alert alert-danger border-0 rounded-3 py-2" id="spamrats-komunikat" role="alert" hidden></div>
        <?php endif; ?>

        <?php
        $spamratsIpv6 = filter_var((string) $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        $spamratsDisabled = $spamratsIpv6
            || (! empty($spamratsKomunikat) && ($spamratsTryb ?? '') === 'brak_klucza');
        ?>
        <?php if ($spamratsIpv6): ?>
            <div class="alert alert-warning border-0 rounded-3 py-2" role="status">
                SpamRats DNSBL obsługuje tylko IPv4.
            </div>
        <?php endif; ?>

        <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
            <button type="button" class="btn btn-primary" id="spamrats-sprawdz"
                data-url="<?= site_url('ip/' . $ip . '/enrichment/spamrats') ?>" data-metoda="post"
                <?= $spamratsDisabled ? 'disabled' : '' ?>>
                Sprawdź SpamRats
            </button>
            <button type="button" class="btn btn-outline-secondary" id="spamrats-force"
                data-url="<?= site_url('ip/' . $ip . '/enrichment/spamrats') ?>" data-metoda="post" data-force="1"
                <?= $spamratsDisabled ? 'disabled' : '' ?>>
                Odśwież (force)
            </button>
            <a class="btn btn-outline-secondary" href="https://www.spamrats.com/bl?<?= rawurlencode((string) $ip) ?>"
               target="_blank" rel="noopener noreferrer">
                SpamRats BL
            </a>
            <span class="small text-body-secondary" id="spamrats-status">
                tryb: <?= esc((string) ($spamratsTryb ?? '—')) ?>
            </span>
        </div>

        <div id="spamrats-wynik" class="enrichment-wynik" <?= empty($spamratsCache) ? 'hidden' : '' ?>>
            <?php $sr = is_array($spamratsCache ?? null) ? $spamratsCache : []; ?>
            <?php
            $srListed = $sr['listed'] ?? null;
            if (is_string($srListed)) {
                $srListed = \App\Models\PluginModel::jakoBool($srListed);
            }
            $srBadge = static function (string $key, string $label) use ($sr): string {
                $v = $sr[$key] ?? null;
                if (is_string($v)) {
                    $v = \App\Models\PluginModel::jakoBool($v);
                }
                if ($v === true) {
                    return '<span class="badge text-bg-danger me-1" id="sr-badge-' . esc($key, 'attr') . '">' . esc($label) . '</span>';
                }
                if ($v === false) {
                    return '<span class="badge text-bg-secondary me-1" id="sr-badge-' . esc($key, 'attr') . '">' . esc($label) . '</span>';
                }

                return '<span class="badge text-bg-light text-body-secondary me-1" id="sr-badge-' . esc($key, 'attr') . '">' . esc($label) . '</span>';
            };
            ?>
            <dl class="row mb-0 small">
                <dt class="col-sm-3 col-md-2 text-body-secondary">Na liście</dt>
                <dd class="col-sm-9 col-md-10" id="sr-listed"><?= $srListed === null ? '—' : ($srListed ? 'tak' : 'nie') ?></dd>
                <dt class="col-sm-3 col-md-2 text-body-secondary">Listy</dt>
                <dd class="col-sm-9 col-md-10" id="sr-listy">
                    <?= $srBadge('listed_dyna', 'Dyna') ?>
                    <?= $srBadge('listed_noptr', 'NoPtr') ?>
                    <?= $srBadge('listed_spam', 'Spam') ?>
                    <?= $srBadge('listed_auth', 'Auth') ?>
                </dd>
                <dt class="col-sm-3 col-md-2 text-body-secondary">Kody A</dt>
                <dd class="col-sm-9 col-md-10 font-monospace" id="sr-kody"><?php
                    $srKody = $sr['kody'] ?? [];
                    if (is_string($srKody)) {
                        $decoded = json_decode($srKody, true);
                        $srKody  = is_array($decoded) ? $decoded : [];
                    }
                    echo esc(is_array($srKody) && $srKody !== [] ? implode(', ', $srKody) : '—');
                ?></dd>
                <dt class="col-sm-3 col-md-2 text-body-secondary">Checked at</dt>
                <dd class="col-sm-9 col-md-10" id="sr-checked"><?= esc((string) (($sr['checked_at'] ?? '') !== '' ? $sr['checked_at'] : '—')) ?></dd>
                <dt class="col-sm-3 col-md-2 text-body-secondary">Źródło danych</dt>
                <dd class="col-sm-9 col-md-10" id="sr-zrodlo"><?= esc((string) (($sr['zrodlo_danych'] ?? '') !== '' ? $sr['zrodlo_danych'] : '—')) ?></dd>
            </dl>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm rounded-3 mt-4" id="panel-dnsbl">
    <div class="card-header bg-transparent border-0 pt-3 px-3 pb-0">
        <h2 class="h5 mb-0">
            <i class="bi bi-shield-exclamation me-1 text-danger" aria-hidden="true"></i>
            Enrichment — DNSBL (AS)
        </h2>
    </div>
    <div class="card-body">
        <p class="card-text text-body-secondary small">
            Multi-list DNSBL (UCEPROTECT, SpamCop, DroneBL) — skan cron AS / LMS
            oraz lookup na żądanie. Trafienia trafiają też do indeksu incydentów (źródło dnsbl).
            <a href="<?= site_url('dashboard/dnsbl') ?>">Zbiorczy widok AS</a>.
        </p>

        <?php if (! empty($dnsblKomunikat)): ?>
            <div class="alert alert-danger border-0 rounded-3 py-2" id="dnsbl-komunikat" role="alert"><?= esc($dnsblKomunikat) ?></div>
        <?php else: ?>
            <div class="alert alert-danger border-0 rounded-3 py-2" id="dnsbl-komunikat" role="alert" hidden></div>
        <?php endif; ?>

        <?php
        $dnsblIpv6 = filter_var((string) $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        ?>
        <?php if ($dnsblIpv6): ?>
            <div class="alert alert-warning border-0 rounded-3 py-2" role="status">
                DNSBL w MCS obsługuje tylko IPv4.
            </div>
        <?php endif; ?>

        <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
            <button type="button" class="btn btn-primary" id="dnsbl-sprawdz"
                data-url="<?= site_url('ip/' . $ip . '/enrichment/dnsbl') ?>" data-metoda="post"
                <?= $dnsblIpv6 ? 'disabled' : '' ?>>
                Sprawdź DNSBL
            </button>
            <button type="button" class="btn btn-outline-secondary" id="dnsbl-force"
                data-url="<?= site_url('ip/' . $ip . '/enrichment/dnsbl') ?>" data-metoda="post" data-force="1"
                <?= $dnsblIpv6 ? 'disabled' : '' ?>>
                Odśwież (force)
            </button>
            <a class="btn btn-outline-secondary" href="<?= site_url('dashboard/dnsbl') ?>">
                Dashboard DNSBL
            </a>
            <a class="btn btn-outline-secondary" href="https://check.spamhaus.org/results/?query=<?= rawurlencode((string) $ip) ?>"
               target="_blank" rel="noopener noreferrer" title="Ręczny lookup w Spamhaus Check (strona WWW)">
                Spamhaus Check
            </a>
            <span class="small text-body-secondary" id="dnsbl-status">
                tryb: <?= esc((string) ($dnsblTryb ?? '—')) ?>
            </span>
        </div>

        <?php
        $skList  = is_array($dnsblListingi ?? null) ? $dnsblListingi : [];
        $skOstat = is_array($dnsblOstatniSkan ?? null) ? $dnsblOstatniSkan : null;
        ?>
        <div class="mb-3" id="dnsbl-skaner">
            <div class="small text-body-secondary mb-1">
                Skaner DNSBL (listy darmowe, lokalny resolver):
                <?php if ($skOstat !== null): ?>
                    ostatni skan <a href="<?= site_url('dashboard/dnsbl?skan=' . (int) $skOstat['id']) ?>">#<?= (int) $skOstat['id'] ?></a>
                    (<?= esc((string) $skOstat['koniec_at']) ?>, zakres <span class="font-monospace"><?= esc((string) $skOstat['zakres']) ?></span>)
                <?php else: ?>
                    brak skanu obejmującego to IP.
                <?php endif; ?>
            </div>
            <?php if ($skList === []): ?>
                <span class="badge text-bg-success" id="dnsbl-skaner-brak">brak aktywnych listingów</span>
            <?php else: ?>
                <ul class="list-unstyled mb-0 small" id="dnsbl-skaner-listingi">
                    <?php foreach ($skList as $l): ?>
                        <li class="mb-1">
                            <span class="badge <?= ! empty($l['informacyjna']) ? 'text-bg-secondary' : 'text-bg-danger' ?>"><?= esc((string) ($l['nazwa'] ?? $l['strefa_kod'])) ?></span>
                            <span class="font-monospace"><?= esc((string) $l['ip']) ?></span>
                            kody <?= esc((string) $l['kody']) ?>
                            · od <?= esc((string) $l['pierwszy_raz']) ?> · ostatnio <?= esc((string) $l['ostatni_raz']) ?>
                            <?php if ((string) ($l['url'] ?? '') !== ''): ?>
                                · <a href="<?= esc((string) $l['url'], 'attr') ?>" target="_blank" rel="noopener noreferrer">Request removal</a>
                            <?php endif; ?>
                            <?php if ((string) ($l['txt'] ?? '') !== ''): ?>
                                <div class="text-body-secondary"><?= esc((string) $l['txt']) ?></div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div id="dnsbl-wynik" class="enrichment-wynik" <?= empty($dnsblCache) ? 'hidden' : '' ?>>
            <?php $dbCache = is_array($dnsblCache ?? null) ? $dnsblCache : []; ?>
            <?php
            $dbListed = $dbCache['listed_any'] ?? null;
            if (is_string($dbListed) || is_int($dbListed)) {
                $dbListed = \App\Models\PluginModel::jakoBool($dbListed);
            }
            $dbListy = $dbCache['listy'] ?? [];
            if (is_string($dbListy)) {
                $decoded = json_decode($dbListy, true);
                $dbListy = is_array($decoded) ? $decoded : [];
            }
            if (! is_array($dbListy)) {
                $dbListy = [];
            }
            $dbBledy = [];
            foreach ($dbListy as $listaId => $info) {
                if (is_array($info) && (string) ($info['error'] ?? '') !== '') {
                    $dbBledy[] = (string) $listaId;
                }
            }
            if ($dbListed === null) {
                $dbListedTxt = '—';
            } elseif ($dbListed) {
                $dbListedTxt = 'tak';
            } elseif ($dbBledy !== []) {
                // Błąd zapytania nigdy nie oznacza „czysto”.
                $dbListedTxt = 'nieznane (błąd zapytania: ' . implode(', ', $dbBledy) . ')';
            } else {
                $dbListedTxt = 'nie';
            }
            ?>
            <dl class="row mb-0 small">
                <dt class="col-sm-3 col-md-2 text-body-secondary">Na liście</dt>
                <dd class="col-sm-9 col-md-10" id="dnsbl-listed"><?= esc($dbListedTxt) ?></dd>
                <dt class="col-sm-3 col-md-2 text-body-secondary">Listy</dt>
                <dd class="col-sm-9 col-md-10" id="dnsbl-listy">
                    <?php if ($dbListy === []): ?>
                        <span class="text-body-secondary">—</span>
                    <?php else: ?>
                        <?php foreach ($dbListy as $listaId => $info): ?>
                            <?php
                            $hit  = is_array($info) && ! empty($info['listed']);
                            $err  = is_array($info) ? (string) ($info['error'] ?? '') : '';
                            $cls  = $hit ? 'text-bg-danger' : ($err !== '' ? 'text-bg-warning' : 'text-bg-secondary');
                            ?>
                            <span class="badge <?= $cls ?> me-1 dnsbl-lista-badge" data-lista="<?= esc((string) $listaId, 'attr') ?>"<?= $err !== '' ? ' title="błąd: ' . esc($err, 'attr') . '"' : '' ?>>
                                <?= esc((string) $listaId) ?><?= $err !== '' ? ' (błąd)' : '' ?>
                            </span>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </dd>
                <dt class="col-sm-3 col-md-2 text-body-secondary">Checked at</dt>
                <dd class="col-sm-9 col-md-10" id="dnsbl-checked"><?= esc((string) (($dbCache['checked_at'] ?? '') !== '' ? $dbCache['checked_at'] : '—')) ?></dd>
                <dt class="col-sm-3 col-md-2 text-body-secondary">Źródło danych</dt>
                <dd class="col-sm-9 col-md-10" id="dnsbl-zrodlo"><?= esc((string) (($dbCache['zrodlo_danych'] ?? '') !== '' ? $dbCache['zrodlo_danych'] : '—')) ?></dd>
            </dl>
        </div>
    </div>
</div>

<?php
$stCidr   = isset($speedtestCidr) && is_string($speedtestCidr) && $speedtestCidr !== '' ? $speedtestCidr : null;
$stLiczba = (int) ($speedtestLiczba24 ?? 0);
?>
<?php if ($stCidr !== null && $stLiczba > 0): ?>
<div class="alert alert-info border-0 shadow-sm rounded-3 mt-4 mb-0" role="status" id="speedtest-cidr-note">
    <i class="bi bi-speedometer2 me-1" aria-hidden="true"></i>
    Speedtest: testy w Twoim /24
    (<span class="font-monospace"><?= esc($stCidr) ?></span>, <?= $stLiczba ?> <?= $stLiczba === 1 ? 'test' : 'testów' ?>) —
    Ookla anonimizuje klientów do <span class="font-monospace">*.0</span>.
    <a class="alert-link" href="<?= site_url('dashboard/speedtest') ?>">Agregaty Speedtest</a>
</div>
<?php endif; ?>

<input type="hidden" id="mcs-csrf" name="<?= esc(csrf_token(), 'attr') ?>" value="<?= esc(csrf_hash(), 'attr') ?>">
<script>
// Enrichment: „Sprawdź” i „Odśwież (force)” = POST z CSRF (GET tylko czyta cache).
// force=1 wysyłany tylko z przycisku z data-force="1".
// Po POST serwer zwraca nowy token CSRF w nagłówku X-Mcs-Csrf (regenerate = true).
window.mcsZapytaj = function (btn, url) {
    var opcje = { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } };
    var pole = document.getElementById('mcs-csrf');
    if (btn.getAttribute('data-metoda') === 'post' && pole) {
        var body = new URLSearchParams();
        if (btn.getAttribute('data-force') === '1') {
            body.append('force', '1');
        }
        body.append(pole.name, pole.value);
        opcje.method = 'POST';
        opcje.body = body;
    }
    return fetch(url, opcje).then(function (r) {
        var nowy = r.headers.get('X-Mcs-Csrf');
        if (nowy && pole) {
            document.querySelectorAll('input[name="' + pole.name + '"]').forEach(function (el) { el.value = nowy; });
        }
        return r;
    });
};
</script>

<script>
(function () {
    function tekst(v) {
        return (v === null || v === undefined || v === '') ? '—' : String(v);
    }
    function takNie(v) {
        if (v === true || v === 1 || v === '1' || v === 't' || v === 'true') return 'tak';
        if (v === false || v === 0 || v === '0' || v === 'f' || v === 'false') return 'nie';
        return '—';
    }
    function pokazIsm(dane) {
        var box = document.getElementById('ismalicious-wynik');
        if (!box) return;
        box.hidden = false;
        var w = dane.wynik || {};
        document.getElementById('im-malicious').textContent = takNie(w.malicious);
        document.getElementById('im-risk').textContent = tekst(w.risk_score);
        document.getElementById('im-confidence').textContent = tekst(w.confidence);
        document.getElementById('im-hits').textContent = tekst(w.blocklist_hits);
        document.getElementById('im-listed').textContent = takNie(w.blocklist_listed);
        document.getElementById('im-class').textContent = tekst(w.classification);
        document.getElementById('im-checked').textContent = tekst(w.checked_at);
        document.getElementById('im-zrodlo').textContent = tekst(w.zrodlo_danych);
        var st = document.getElementById('ismalicious-status');
        if (st) {
            st.textContent = 'tryb: ' + tekst(dane.tryb)
                + (dane.z_cache ? ' (z cache)' : '');
        }
    }
    function pokazIsmBlad(msg) {
        var el = document.getElementById('ismalicious-komunikat');
        if (!el) return;
        el.hidden = false;
        el.textContent = msg || 'Błąd enrichment isMalicious.';
    }
    function odpytajIsm(btn) {
        var url = btn.getAttribute('data-url');
        if (!url) return;
        btn.disabled = true;
        window.mcsZapytaj(btn, url)
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
            .then(function (x) {
                if (!x.j || !x.j.ok) {
                    pokazIsmBlad((x.j && x.j.blad) ? x.j.blad : 'Błąd enrichment isMalicious.');
                    return;
                }
                var el = document.getElementById('ismalicious-komunikat');
                if (el) { el.hidden = true; el.textContent = ''; }
                pokazIsm(x.j);
            })
            .catch(function () { pokazIsmBlad('Nie udało się połączyć z MCS.'); })
            .finally(function () { btn.disabled = false; });
    }
    ['ismalicious-sprawdz', 'ismalicious-force'].forEach(function (id) {
        var b = document.getElementById(id);
        if (b && !b.disabled) {
            b.addEventListener('click', function () { odpytajIsm(b); });
        }
    });
})();
</script>

<script>
(function () {
    function tekst(v) {
        return (v === null || v === undefined || v === '') ? '—' : String(v);
    }
    function takNie(v) {
        if (v === true || v === 1 || v === '1' || v === 't' || v === 'true') return 'tak';
        if (v === false || v === 0 || v === '0' || v === 'f' || v === 'false') return 'nie';
        return '—';
    }
    function pokazGn(dane) {
        var box = document.getElementById('greynoise-wynik');
        if (!box) return;
        box.hidden = false;
        var w = dane.wynik || {};
        document.getElementById('gn-class').textContent = tekst(w.classification);
        document.getElementById('gn-noise').textContent = takNie(w.noise);
        document.getElementById('gn-riot').textContent = takNie(w.riot);
        document.getElementById('gn-name').textContent = tekst(w.name);
        document.getElementById('gn-last').textContent = tekst(w.last_seen);
        document.getElementById('gn-message').textContent = tekst(w.message);
        var link = document.getElementById('gn-link');
        if (link) {
            var href = (typeof w.link === 'string' && w.link.indexOf('https://') === 0) ? w.link : '';
            link.textContent = '';
            if (href) {
                var a = document.createElement('a');
                a.href = href;
                a.rel = 'noopener noreferrer';
                a.textContent = href;
                link.appendChild(a);
            } else {
                link.textContent = '—';
            }
        }
        var st = document.getElementById('greynoise-status');
        if (st) {
            st.textContent = 'tryb: ' + tekst(dane.tryb) + (dane.z_cache ? ' (z cache)' : '');
        }
    }
    function pokazGnBlad(msg) {
        var el = document.getElementById('greynoise-komunikat');
        if (!el) return;
        el.hidden = false;
        el.textContent = msg || 'Błąd enrichment GreyNoise.';
    }
    function odpytajGn(btn) {
        var url = btn.getAttribute('data-url');
        if (!url) return;
        btn.disabled = true;
        window.mcsZapytaj(btn, url)
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
            .then(function (x) {
                if (!x.j || !x.j.ok) {
                    pokazGnBlad((x.j && x.j.blad) ? x.j.blad : 'Błąd enrichment GreyNoise.');
                    return;
                }
                var el = document.getElementById('greynoise-komunikat');
                if (el) { el.hidden = true; el.textContent = ''; }
                pokazGn(x.j);
            })
            .catch(function () { pokazGnBlad('Nie udało się połączyć z MCS.'); })
            .finally(function () { btn.disabled = false; });
    }
    ['greynoise-sprawdz', 'greynoise-force'].forEach(function (id) {
        var b = document.getElementById(id);
        if (b && !b.disabled) {
            b.addEventListener('click', function () { odpytajGn(b); });
        }
    });
})();
</script>

<script>
(function () {
    function tekst(v) {
        return (v === null || v === undefined || v === '') ? '—' : String(v);
    }
    function takNie(v) {
        if (v === true || v === 1 || v === '1' || v === 't' || v === 'true') return 'tak';
        if (v === false || v === 0 || v === '0' || v === 'f' || v === 'false') return 'nie';
        return '—';
    }
    function badgeClass(v) {
        if (v === true || v === 1 || v === '1' || v === 't' || v === 'true') return 'badge text-bg-danger me-1';
        if (v === false || v === 0 || v === '0' || v === 'f' || v === 'false') return 'badge text-bg-secondary me-1';
        return 'badge text-bg-light text-body-secondary me-1';
    }
    function ustawBadge(id, v) {
        var el = document.getElementById(id);
        if (!el) return;
        el.className = badgeClass(v);
    }
    function pokazSr(dane) {
        var box = document.getElementById('spamrats-wynik');
        if (!box) return;
        box.hidden = false;
        var w = dane.wynik || {};
        document.getElementById('sr-listed').textContent = takNie(w.listed);
        ustawBadge('sr-badge-listed_dyna', w.listed_dyna);
        ustawBadge('sr-badge-listed_noptr', w.listed_noptr);
        ustawBadge('sr-badge-listed_spam', w.listed_spam);
        ustawBadge('sr-badge-listed_auth', w.listed_auth);
        var kody = w.kody;
        if (Array.isArray(kody) && kody.length) {
            document.getElementById('sr-kody').textContent = kody.join(', ');
        } else {
            document.getElementById('sr-kody').textContent = '—';
        }
        document.getElementById('sr-checked').textContent = tekst(w.checked_at);
        document.getElementById('sr-zrodlo').textContent = tekst(w.zrodlo_danych);
        var st = document.getElementById('spamrats-status');
        if (st) {
            st.textContent = 'tryb: ' + tekst(dane.tryb)
                + (dane.z_cache ? ' (z cache)' : '');
        }
    }
    function pokazSrBlad(msg) {
        var el = document.getElementById('spamrats-komunikat');
        if (!el) return;
        el.hidden = false;
        el.textContent = msg || 'Błąd enrichment SpamRats.';
    }
    function odpytajSr(btn) {
        var url = btn.getAttribute('data-url');
        if (!url) return;
        btn.disabled = true;
        window.mcsZapytaj(btn, url)
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
            .then(function (x) {
                if (!x.j || !x.j.ok) {
                    pokazSrBlad((x.j && x.j.blad) ? x.j.blad : 'Błąd enrichment SpamRats.');
                    return;
                }
                var el = document.getElementById('spamrats-komunikat');
                if (el) { el.hidden = true; el.textContent = ''; }
                pokazSr(x.j);
            })
            .catch(function () { pokazSrBlad('Nie udało się połączyć z MCS.'); })
            .finally(function () { btn.disabled = false; });
    }
    ['spamrats-sprawdz', 'spamrats-force'].forEach(function (id) {
        var b = document.getElementById(id);
        if (b && !b.disabled) {
            b.addEventListener('click', function () { odpytajSr(b); });
        }
    });
})();
</script>

<script>
(function () {
    function tekst(v) {
        return (v === null || v === undefined || v === '') ? '—' : String(v);
    }
    function takNie(v) {
        if (v === true || v === 1 || v === '1' || v === 't' || v === 'true') return 'tak';
        if (v === false || v === 0 || v === '0' || v === 'f' || v === 'false') return 'nie';
        return '—';
    }
    function pokazDnsbl(dane) {
        var box = document.getElementById('dnsbl-wynik');
        if (!box) return;
        box.hidden = false;
        var w = dane.wynik || {};
        var listy = w.listy || {};
        var bledy = Object.keys(listy).filter(function (id) {
            return !!((listy[id] || {}).error);
        });
        var listedTxt = takNie(w.listed_any);
        if (listedTxt === 'nie' && bledy.length) {
            // Błąd zapytania nigdy nie oznacza „czysto”.
            listedTxt = 'nieznane (błąd zapytania: ' + bledy.join(', ') + ')';
        }
        document.getElementById('dnsbl-listed').textContent = listedTxt;
        var listyEl = document.getElementById('dnsbl-listy');
        if (listyEl) {
            listyEl.textContent = '';
            Object.keys(listy).forEach(function (id) {
                var info = listy[id] || {};
                var hit = !!(info.listed);
                var err = info.error ? String(info.error) : '';
                var span = document.createElement('span');
                span.className = 'badge me-1 ' + (hit ? 'text-bg-danger' : (err ? 'text-bg-warning' : 'text-bg-secondary'));
                span.textContent = String(id) + (err ? ' (błąd)' : '');
                if (err) span.title = 'błąd: ' + err;
                listyEl.appendChild(span);
            });
            if (!listyEl.childNodes.length) {
                var pusty = document.createElement('span');
                pusty.className = 'text-body-secondary';
                pusty.textContent = '—';
                listyEl.appendChild(pusty);
            }
        }
        document.getElementById('dnsbl-checked').textContent = tekst(w.checked_at);
        document.getElementById('dnsbl-zrodlo').textContent = tekst(w.zrodlo_danych);
        var st = document.getElementById('dnsbl-status');
        if (st) {
            st.textContent = 'tryb: ' + tekst(dane.tryb) + (dane.z_cache ? ' (z cache)' : '');
        }
    }
    function pokazDnsblBlad(msg) {
        var el = document.getElementById('dnsbl-komunikat');
        if (!el) return;
        el.hidden = false;
        el.textContent = msg || 'Błąd enrichment DNSBL.';
    }
    function odpytajDnsbl(btn) {
        var url = btn.getAttribute('data-url');
        if (!url) return;
        btn.disabled = true;
        window.mcsZapytaj(btn, url)
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
            .then(function (x) {
                if (!x.j || !x.j.ok) {
                    pokazDnsblBlad((x.j && x.j.blad) ? x.j.blad : 'Błąd enrichment DNSBL.');
                    return;
                }
                var el = document.getElementById('dnsbl-komunikat');
                if (el) { el.hidden = true; el.textContent = ''; }
                pokazDnsbl(x.j);
            })
            .catch(function () { pokazDnsblBlad('Nie udało się połączyć z MCS.'); })
            .finally(function () { btn.disabled = false; });
    }
    ['dnsbl-sprawdz', 'dnsbl-force'].forEach(function (id) {
        var b = document.getElementById(id);
        if (b && !b.disabled) {
            b.addEventListener('click', function () { odpytajDnsbl(b); });
        }
    });
})();
</script>

<script>
(function () {
    function tekst(v) {
        return (v === null || v === undefined || v === '') ? '—' : String(v);
    }
    function pokazLms(dane) {
        var box = document.getElementById('lms-wynik');
        if (!box) return;
        box.hidden = false;
        var w = dane.wynik || {};
        var trafiony = !!w.trafiony;
        var trafBox = document.getElementById('lms-trafiony-box');
        var brak = document.getElementById('lms-brak');
        if (trafBox) trafBox.hidden = !trafiony;
        if (brak) brak.hidden = trafiony;
        var k = document.getElementById('lms-komputer');
        var a = document.getElementById('lms-abonent');
        var n = document.getElementById('lms-node-id');
        var c = document.getElementById('lms-customer-id');
        if (k) k.textContent = tekst(w.komputer);
        if (a) a.textContent = tekst(w.abonent);
        if (n) n.textContent = tekst(w.node_id);
        if (c) c.textContent = tekst(w.customer_id);
        var linkWrap = document.getElementById('lms-link-wrap');
        var link = document.getElementById('lms-link');
        if (linkWrap && link) {
            if (w.lms_url) {
                linkWrap.hidden = false;
                link.href = w.lms_url;
            } else {
                linkWrap.hidden = true;
            }
        }
        var panel = document.getElementById('panel-lms');
        if (panel) {
            panel.classList.toggle('border-start', trafiony);
            panel.classList.toggle('border-4', trafiony);
            panel.classList.toggle('border-primary', trafiony);
        }
        var st = document.getElementById('lms-status');
        if (st) {
            st.textContent = 'tryb: ' + tekst(dane.tryb)
                + (dane.z_cache ? ' (z cache)' : '');
        }
    }
    function pokazLmsBlad(msg) {
        var el = document.getElementById('lms-komunikat');
        if (!el) return;
        el.hidden = false;
        el.className = 'alert alert-danger border-0 rounded-3 py-2 mb-2';
        el.textContent = msg || 'Błąd mapowania LMS.';
    }
    function odpytajLms(btn) {
        var url = btn.getAttribute('data-url');
        if (!url) return;
        btn.disabled = true;
        window.mcsZapytaj(btn, url)
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
            .then(function (x) {
                if (!x.j || !x.j.ok) {
                    pokazLmsBlad((x.j && x.j.blad) ? x.j.blad : 'Błąd mapowania LMS.');
                    return;
                }
                var el = document.getElementById('lms-komunikat');
                if (el) { el.hidden = true; el.textContent = ''; }
                pokazLms(x.j);
            })
            .catch(function () { pokazLmsBlad('Nie udało się połączyć z MCS.'); })
            .finally(function () { btn.disabled = false; });
    }
    ['lms-sprawdz', 'lms-force'].forEach(function (id) {
        var b = document.getElementById(id);
        if (b && !b.disabled) {
            b.addEventListener('click', function () { odpytajLms(b); });
        }
    });
})();
</script>

<script>
(function () {
    function tekst(v) {
        return (v === null || v === undefined || v === '') ? '—' : String(v);
    }
    function pokazWynik(dane) {
        var box = document.getElementById('abuseipdb-wynik');
        if (!box) return;
        box.hidden = false;
        var w = dane.wynik || {};
        document.getElementById('ab-score').textContent = tekst(w.abuse_confidence_score);
        document.getElementById('ab-reports').textContent = tekst(w.total_reports);
        document.getElementById('ab-cc').textContent = tekst(w.country_code);
        document.getElementById('ab-isp').textContent = tekst(w.isp);
        document.getElementById('ab-usage').textContent = tekst(w.usage_type);
        document.getElementById('ab-last').textContent = tekst(w.last_reported_at);
        document.getElementById('ab-checked').textContent = tekst(w.checked_at);
        document.getElementById('ab-zrodlo').textContent = tekst(w.zrodlo_danych);
        var st = document.getElementById('abuseipdb-status');
        if (st) {
            st.textContent = 'tryb: ' + tekst(dane.tryb)
                + (dane.z_cache ? ' (z cache)' : '');
        }
    }
    function pokazBlad(msg) {
        var el = document.getElementById('abuseipdb-komunikat');
        if (!el) return;
        el.hidden = false;
        el.textContent = msg || 'Błąd enrichment AbuseIPDB.';
    }
    function odpytaj(btn) {
        var url = btn.getAttribute('data-url');
        if (!url) return;
        btn.disabled = true;
        window.mcsZapytaj(btn, url)
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
            .then(function (x) {
                if (!x.j || !x.j.ok) {
                    pokazBlad((x.j && x.j.blad) ? x.j.blad : 'Błąd enrichment AbuseIPDB.');
                    return;
                }
                var el = document.getElementById('abuseipdb-komunikat');
                if (el) { el.hidden = true; el.textContent = ''; }
                pokazWynik(x.j);
            })
            .catch(function () { pokazBlad('Nie udało się połączyć z MCS.'); })
            .finally(function () { btn.disabled = false; });
    }
    ['abuseipdb-sprawdz', 'abuseipdb-force'].forEach(function (id) {
        var b = document.getElementById(id);
        if (b && !b.disabled) {
            b.addEventListener('click', function () { odpytaj(b); });
        }
    });
})();
</script>
<?php endif; ?>

<?= $this->endSection() ?>
