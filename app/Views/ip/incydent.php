<?= $this->extend('layouts/mcs') ?>
<?= $this->section('content') ?>

<main class="panel">
    <p class="podpis" style="margin-bottom:0.5rem">
        <a href="<?= site_url('ip/' . $ip) ?>">← Powrót do IP <?= esc($ip) ?></a>
    </p>

    <?php if (! empty($blad)): ?>
        <h2>Szczegóły incydentu</h2>
        <p class="blad"><?= esc($blad) ?></p>
    <?php else: ?>
        <?php
        $inc = $szczegoly['incydent'] ?? [];
        $waga = (string) ($inc['waga'] ?? '');
        $klasaWagi = in_array($waga, ['low', 'medium', 'high', 'critical'], true)
            ? 'waga-' . $waga
            : '';
        $port = $inc['port'] ?? null;
        $protokol = $inc['protokol'] ?? null;
        $portTekst = $port !== null && $port !== ''
            ? (string) $port . ($protokol ? '/' . $protokol : '')
            : '—';
        ?>
        <h2><?= esc((string) ($inc['tytul'] ?? 'Incydent')) ?></h2>
        <p class="podpis">
            Źródło <strong><?= esc((string) ($inc['zrodlo'] ?? '')) ?></strong>
            · waga <span class="<?= esc($klasaWagi, 'attr') ?>"><?= esc($waga) ?></span>
            · kategoria <?= esc((string) ($inc['kategoria'] ?? '—')) ?>
            · port <?= esc($portTekst) ?>
        </p>

        <dl class="meta-lista">
            <dt>Pierwsze widziane</dt>
            <dd><?= esc((string) ($inc['pierwsze_widziane'] ?? '—')) ?></dd>
            <dt>Ostatnio widziane</dt>
            <dd><?= esc((string) ($inc['ostatnio_widziane'] ?? '—')) ?></dd>
            <dt>Klucz dedupu</dt>
            <dd><code><?= esc((string) ($inc['klucz_dedupu'] ?? '—')) ?></code></dd>
            <dt>ID</dt>
            <dd>#<?= esc((string) ($inc['id'] ?? '')) ?></dd>
        </dl>

        <h3>Dane ze źródła
            <?php if (! empty($szczegoly['zrodlo_tabela'])): ?>
                <span class="health">(<?= esc((string) $szczegoly['zrodlo_tabela']) ?>)</span>
            <?php endif; ?>
        </h3>
        <?php if (empty($szczegoly['zrodlo_wiersz'])): ?>
            <p class="pusto">Brak wiersza w tabeli źródła (id_w_zrodle puste lub nieznane).</p>
        <?php else: ?>
            <table class="meta-table">
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
                        <th scope="row"><?= esc((string) $klucz) ?></th>
                        <td><?= esc((string) $wartosc) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (! empty($szczegoly['zrodlo_wiersz']['payload']) && is_array($szczegoly['zrodlo_wiersz']['payload'])): ?>
                <h3>Payload źródła (JSON)</h3>
                <pre class="payload"><?= esc(json_encode($szczegoly['zrodlo_wiersz']['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
            <?php endif; ?>
        <?php endif; ?>

        <h3>Historia obserwacji</h3>
        <?php if (empty($szczegoly['obserwacje'])): ?>
            <p class="pusto">Brak obserwacji w mcs_obserwacje.</p>
        <?php else: ?>
            <ol class="timeline">
                <?php foreach ($szczegoly['obserwacje'] as $obs): ?>
                    <li>
                        <div class="timeline-czas"><?= esc((string) ($obs['zaobserwowano_at'] ?? '')) ?></div>
                        <div class="timeline-meta">
                            id_w_zrodle: <?= esc((string) ($obs['id_w_zrodle'] ?? '—')) ?>
                            <?php if (! empty($obs['import_id'])): ?>
                                · import #<?= esc((string) $obs['import_id']) ?>
                            <?php endif; ?>
                        </div>
                        <?php if (! empty($obs['payload']) && is_array($obs['payload'])): ?>
                            <pre class="payload"><?= esc(json_encode($obs['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    <?php endif; ?>
</main>

<?= $this->endSection() ?>
