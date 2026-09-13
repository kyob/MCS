<?= $this->extend('layouts/mcs') ?>
<?= $this->section('content') ?>

<main class="panel">
    <h2>
        Podmiot IP: <?= esc($ip !== '' ? $ip : '—') ?>
        <?php if (! empty($asMatchWlaczone) && ! empty($naszeIp)): ?>
            <span class="badge-nasze" title="Adres w przestrzeni AS198050">nasze IP</span>
        <?php endif; ?>
    </h2>
    <p class="podpis">Szybka ocena: status + problemy zagregowane (nie każda karta osobno).</p>

    <?php if (! empty($asMatchWlaczone) && empty($blad)): ?>
        <?php if (! empty($naszeIp)): ?>
            <p class="ok">Ten adres należy do przestrzeni AS198050 (nasze IP wg ustawień CIDR).</p>
        <?php else: ?>
            <p class="podpis" style="margin-top:0">Poza AS198050 — nie ma trafienia w skonfigurowane prefiksy
                (<a href="<?= site_url('admin/ustawienia') ?>">ustawienia</a>).</p>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (! empty($blad)): ?>
        <p class="blad"><?= esc($blad) ?></p>
    <?php else: ?>
        <?php
        $statusIp = is_array($statusIp ?? null) ? $statusIp : [];
        $problemy = is_array($problemy ?? null) ? $problemy : [];
        $maxWaga  = (string) ($statusIp['max_waga'] ?? '');
        $klasaMax = in_array($maxWaga, ['low', 'medium', 'high', 'critical'], true)
            ? 'waga-' . $maxWaga
            : '';
        $liczbaProblemow = (int) ($statusIp['liczba_problemow'] ?? 0);
        $topTytuly = is_array($statusIp['top_tytuly'] ?? null) ? $statusIp['top_tytuly'] : [];
        ?>

        <section class="ip-status" aria-label="Status IP">
            <div class="ip-status-wiersz">
                <?php if ($maxWaga !== ''): ?>
                    <span class="kontekst-chip <?= esc($klasaMax, 'attr') ?>"><?= esc(strtoupper($maxWaga)) ?></span>
                <?php else: ?>
                    <span class="kontekst-chip">brak wagi</span>
                <?php endif; ?>
                <strong><?= $liczbaProblemow ?> <?= $liczbaProblemow === 1 ? 'problem' : 'problemów' ?></strong>
                <?php if ($topTytuly !== []): ?>
                    <span class="ip-status-top">
                        Najgorsze:
                        <?= esc(implode(' · ', $topTytuly)) ?>
                    </span>
                <?php endif; ?>
            </div>
        </section>

        <nav class="filtry" aria-label="Filtr źródła">
            <span>Źródło:</span>
            <a href="<?= site_url('ip/' . $ip) ?>" class="<?= $filtrZrodlo === null ? 'aktywny' : '' ?>">wszystkie</a>
            <?php foreach ($zrodla as $z): ?>
                <a href="<?= site_url('ip/' . $ip) . '?zrodlo=' . rawurlencode($z) ?>"
                   class="<?= $filtrZrodlo === $z ? 'aktywny' : '' ?>"><?= esc($z) ?></a>
            <?php endforeach; ?>
        </nav>

        <h3>Problemy</h3>
        <?php if ($problemy === []): ?>
            <p class="pusto">Brak widocznych problemów dla tego IP.</p>
        <?php else: ?>
            <ul class="lista-problemow" aria-label="Zagregowane problemy">
                <?php foreach ($problemy as $problem): ?>
                    <?php
                    $waga = (string) ($problem['waga'] ?? '');
                    $klasaWagi = in_array($waga, ['low', 'medium', 'high', 'critical'], true)
                        ? 'waga-' . $waga
                        : '';
                    $liczba = (int) ($problem['liczba_zdarzen'] ?? 0);
                    $timeline = is_array($problem['timeline'] ?? null) ? $problem['timeline'] : [];
                    $czlonkowie = is_array($problem['czlonkowie'] ?? null) ? $problem['czlonkowie'] : [];
                    ?>
                    <li class="pozycja-problemu">
                        <details>
                            <summary>
                                <span class="problem-naglowek">
                                    <span class="kontekst-chip kontekst-chip-zrodlo"><?= esc((string) ($problem['zrodlo_etykieta'] ?? '')) ?></span>
                                    <?php if ($waga !== ''): ?>
                                        <span class="kontekst-chip <?= esc($klasaWagi, 'attr') ?>"><?= esc(strtoupper($waga)) ?></span>
                                    <?php endif; ?>
                                    <?php if (($problem['kategoria'] ?? '') !== '' && strcasecmp((string) $problem['kategoria'], 'other') !== 0): ?>
                                        <span class="kontekst-chip kontekst-chip-kat"><?= esc((string) ($problem['kategoria_etykieta'] ?? '')) ?></span>
                                    <?php endif; ?>
                                    <span class="problem-tytul"><?= esc((string) ($problem['tytul'] ?? 'Problem')) ?></span>
                                </span>
                                <span class="problem-meta">
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
                            </summary>
                            <?php if ($czlonkowie === []): ?>
                                <p class="pusto">Brak zdarzeń w grupie.</p>
                            <?php else: ?>
                                <ul class="lista-czlonkow">
                                    <?php foreach ($czlonkowie as $cz): ?>
                                        <li>
                                            <a href="<?= esc((string) ($cz['url'] ?? ''), 'attr') ?>">
                                                Timeline i pełne dane
                                            </a>
                                            <span class="podpis">
                                                #<?= (int) ($cz['id'] ?? 0) ?>
                                                · <?= esc((string) ($cz['waga'] ?? '')) ?>
                                                · <?= esc((string) ($cz['ostatnio_widziane'] ?? '')) ?>
                                            </span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </details>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    <?php endif; ?>
</main>

<?php if (empty($blad)): ?>
<main class="panel" id="panel-abuseipdb">
    <h2>Enrichment — AbuseIPDB</h2>
    <p class="podpis">Lookup reputacji na żądanie (cache TTL). Nie wpływa na indeks incydentów ani badge.</p>

    <?php if (! empty($abuseipdbKomunikat)): ?>
        <p class="blad" id="abuseipdb-komunikat"><?= esc($abuseipdbKomunikat) ?></p>
    <?php else: ?>
        <p class="podpis" id="abuseipdb-komunikat" style="display:none"></p>
    <?php endif; ?>

    <div class="enrichment-akcje">
        <button type="button" id="abuseipdb-sprawdz"
            data-url="<?= site_url('ip/' . $ip . '/enrichment/abuseipdb') ?>"
            <?= (! empty($abuseipdbKomunikat) && ($abuseipdbTryb ?? '') === 'brak_klucza') ? 'disabled' : '' ?>>
            Sprawdź AbuseIPDB
        </button>
        <button type="button" id="abuseipdb-force" class="reset"
            data-url="<?= site_url('ip/' . $ip . '/enrichment/abuseipdb?force=1') ?>"
            <?= (! empty($abuseipdbKomunikat) && ($abuseipdbTryb ?? '') === 'brak_klucza') ? 'disabled' : '' ?>>
            Odśwież (force)
        </button>
        <span class="health" id="abuseipdb-status">
            tryb: <?= esc((string) ($abuseipdbTryb ?? '—')) ?>
        </span>
    </div>

    <div id="abuseipdb-wynik" class="enrichment-wynik" <?= empty($abuseipdbCache) ? 'hidden' : '' ?>>
        <?php if (! empty($abuseipdbCache) && is_array($abuseipdbCache)): ?>
            <dl class="meta-lista">
                <dt>Score</dt>
                <dd id="ab-score"><?= esc((string) ($abuseipdbCache['abuse_confidence_score'] ?? '—')) ?></dd>
                <dt>Raporty</dt>
                <dd id="ab-reports"><?= esc((string) ($abuseipdbCache['total_reports'] ?? '—')) ?></dd>
                <dt>Kraj</dt>
                <dd id="ab-cc"><?= esc((string) ($abuseipdbCache['country_code'] ?? '—')) ?></dd>
                <dt>ISP</dt>
                <dd id="ab-isp"><?= esc((string) ($abuseipdbCache['isp'] ?? '—')) ?></dd>
                <dt>Typ</dt>
                <dd id="ab-usage"><?= esc((string) ($abuseipdbCache['usage_type'] ?? '—')) ?></dd>
                <dt>Ostatni raport</dt>
                <dd id="ab-last"><?= esc((string) ($abuseipdbCache['last_reported_at'] ?? '—')) ?></dd>
                <dt>Checked at</dt>
                <dd id="ab-checked"><?= esc((string) ($abuseipdbCache['checked_at'] ?? '—')) ?></dd>
                <dt>Źródło danych</dt>
                <dd id="ab-zrodlo"><?= esc((string) ($abuseipdbCache['zrodlo_danych'] ?? '—')) ?></dd>
            </dl>
        <?php else: ?>
            <dl class="meta-lista">
                <dt>Score</dt><dd id="ab-score">—</dd>
                <dt>Raporty</dt><dd id="ab-reports">—</dd>
                <dt>Kraj</dt><dd id="ab-cc">—</dd>
                <dt>ISP</dt><dd id="ab-isp">—</dd>
                <dt>Typ</dt><dd id="ab-usage">—</dd>
                <dt>Ostatni raport</dt><dd id="ab-last">—</dd>
                <dt>Checked at</dt><dd id="ab-checked">—</dd>
                <dt>Źródło danych</dt><dd id="ab-zrodlo">—</dd>
            </dl>
        <?php endif; ?>
    </div>
</main>

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
        el.style.display = '';
        el.className = 'blad';
        el.textContent = msg || 'Błąd enrichment AbuseIPDB.';
    }
    function odpytaj(btn) {
        var url = btn.getAttribute('data-url');
        if (!url) return;
        btn.disabled = true;
        fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
            .then(function (x) {
                if (!x.j || !x.j.ok) {
                    pokazBlad((x.j && x.j.blad) ? x.j.blad : 'Błąd enrichment AbuseIPDB.');
                    return;
                }
                var el = document.getElementById('abuseipdb-komunikat');
                if (el) { el.style.display = 'none'; el.textContent = ''; }
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
