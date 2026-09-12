<?= $this->extend('layouts/mcs') ?>
<?= $this->section('content') ?>

<main class="panel">
    <h2>
        Podmiot IP: <?= esc($ip !== '' ? $ip : '—') ?>
        <?php if (! empty($asMatchWlaczone) && ! empty($naszeIp)): ?>
            <span class="badge-nasze" title="Adres w przestrzeni AS198050">nasze IP</span>
        <?php endif; ?>
    </h2>
    <p class="podpis">Lista skrótów incydentów ze wspólnego indeksu (źródła nieukryte). Korelacja wielu źródeł na jednym IP.</p>

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
        <nav class="filtry" aria-label="Filtr źródła">
            <span>Źródło:</span>
            <a href="<?= site_url('ip/' . $ip) ?>" class="<?= $filtrZrodlo === null ? 'aktywny' : '' ?>">wszystkie</a>
            <?php foreach ($zrodla as $z): ?>
                <a href="<?= site_url('ip/' . $ip) . '?zrodlo=' . rawurlencode($z) ?>"
                   class="<?= $filtrZrodlo === $z ? 'aktywny' : '' ?>"><?= esc($z) ?></a>
            <?php endforeach; ?>
        </nav>

        <?php if ($incydenty === []): ?>
            <p class="pusto">Brak widocznych incydentów dla tego IP.</p>
        <?php else: ?>
            <table>
                <thead>
                <tr>
                    <th>Waga</th>
                    <th>Źródło</th>
                    <th>Tytuł</th>
                    <th>Kategoria</th>
                    <th>Port</th>
                    <th>Ostatnio</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($incydenty as $wiersz): ?>
                    <?php
                    $waga = (string) ($wiersz['waga'] ?? '');
                    $klasaWagi = in_array($waga, ['low', 'medium', 'high', 'critical'], true)
                        ? 'waga-' . $waga
                        : '';
                    $port = $wiersz['port'] ?? null;
                    $protokol = $wiersz['protokol'] ?? null;
                    $portTekst = $port !== null && $port !== ''
                        ? (string) $port . ($protokol ? '/' . $protokol : '')
                        : '—';
                    $incydentId = (int) ($wiersz['id'] ?? 0);
                    $urlSzczegoly = $incydentId > 0
                        ? site_url('ip/' . $ip . '/incydent/' . $incydentId)
                        : null;
                    ?>
                    <tr class="<?= $urlSzczegoly ? 'wiersz-klik' : '' ?>"
                        <?php if ($urlSzczegoly): ?>
                            data-href="<?= $urlSzczegoly ?>"
                            tabindex="0"
                            role="link"
                            aria-label="Szczegóły incydentu"
                        <?php endif; ?>>
                        <td class="<?= esc($klasaWagi, 'attr') ?>"><?= esc($waga) ?></td>
                        <td><?= esc((string) ($wiersz['zrodlo'] ?? '')) ?></td>
                        <td>
                            <?php if ($urlSzczegoly): ?>
                                <a href="<?= $urlSzczegoly ?>"><?= esc((string) ($wiersz['tytul'] ?? '')) ?></a>
                            <?php else: ?>
                                <?= esc((string) ($wiersz['tytul'] ?? '')) ?>
                            <?php endif; ?>
                        </td>
                        <td><?= esc((string) ($wiersz['kategoria'] ?? '')) ?></td>
                        <td><?= esc($portTekst) ?></td>
                        <td><?= esc((string) ($wiersz['ostatnio_widziane'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
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
    document.querySelectorAll('tr.wiersz-klik[data-href]').forEach(function (tr) {
        tr.addEventListener('click', function (e) {
            if (e.target && e.target.closest('a')) return;
            window.location = tr.getAttribute('data-href');
        });
        tr.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                window.location = tr.getAttribute('data-href');
            }
        });
    });
})();
</script>
<?php endif; ?>

<?= $this->endSection() ?>
