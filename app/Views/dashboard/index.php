<?= $this->extend('layouts/mcs') ?>
<?= $this->section('content') ?>

<main class="panel">
    <h2>Przegląd zagrożeń</h2>
    <p class="podpis">
        Korelacja skrótów z indeksu MCS (Shadowserver, Wanguard, SNDS, CERT N6, MojeCert, Play…).
        Problematyczne IP = wiele źródeł, wysoka waga lub duża liczba incydentów w oknie.
    </p>

    <?php if (! empty($flashBlad)): ?>
        <p class="blad"><?= esc($flashBlad) ?></p>
    <?php endif; ?>
    <?php if (! empty($blad)): ?>
        <p class="blad"><?= esc($blad) ?></p>
    <?php endif; ?>

    <form class="filtry" method="get" action="<?= site_url('/') ?>" aria-label="Filtry przeglądu">
        <label>
            Okno
            <select name="okno">
                <?php foreach ([1, 7, 14, 30, 90] as $dni): ?>
                    <option value="<?= $dni ?>" <?= (int) $okno === $dni ? 'selected' : '' ?>>
                        <?= $dni ?> <?= $dni === 1 ? 'dzień' : 'dni' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            Źródło
            <select name="zrodlo">
                <option value="">wszystkie</option>
                <?php foreach ($zrodla as $z): ?>
                    <option value="<?= esc($z, 'attr') ?>" <?= $filtrZrodlo === $z ? 'selected' : '' ?>>
                        <?= esc($z) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            Min. waga
            <select name="waga">
                <option value="">dowolna</option>
                <?php foreach (['critical', 'high', 'medium', 'low'] as $w): ?>
                    <option value="<?= $w ?>" <?= $filtrMinWaga === $w ? 'selected' : '' ?>><?= $w ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            Min. źródeł
            <select name="min_zrodel">
                <?php foreach ([1, 2, 3, 4, 5] as $n): ?>
                    <option value="<?= $n ?>" <?= (int) $filtrMinZrodel === $n ? 'selected' : '' ?>>
                        <?= $n ?><?= $n >= 2 ? '+' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            IP
            <input type="text" name="ip" value="<?= esc((string) ($filtrIp ?? ''), 'attr') ?>"
                   placeholder="np. 198.51.100.10" autocomplete="off">
        </label>
        <?php if (! empty($asMatchWlaczone)): ?>
            <label>
                Sieć (AS198050)
                <select name="siec">
                    <option value="" <?= ($filtrSiec ?? '') === '' ? 'selected' : '' ?>>wszystkie</option>
                    <option value="nasze" <?= ($filtrSiec ?? '') === 'nasze' ? 'selected' : '' ?>>tylko nasze IP</option>
                    <option value="obce" <?= ($filtrSiec ?? '') === 'obce' ? 'selected' : '' ?>>tylko obce</option>
                </select>
            </label>
        <?php endif; ?>
        <button type="submit">Filtruj</button>
        <a class="reset" href="<?= site_url('/') ?>">Wyczyść</a>
    </form>

    <div class="kpi" aria-label="Wskaźniki">
        <div class="pozycja">
            <span class="etykieta">Incydenty</span>
            <span class="wartosc"><?= (int) $kpi['liczba_incydentow'] ?></span>
        </div>
        <div class="pozycja">
            <span class="etykieta">Unikalne IP</span>
            <span class="wartosc"><?= (int) $kpi['liczba_ip'] ?></span>
        </div>
        <div class="pozycja">
            <span class="etykieta">IP wieloźródłowe</span>
            <span class="wartosc"><?= (int) $kpi['ip_wielozrodlowe'] ?></span>
        </div>
        <div class="pozycja">
            <span class="etykieta">critical / high</span>
            <span class="wartosc"><?= (int) $kpi['critical_high'] ?></span>
        </div>
        <div class="pozycja">
            <span class="etykieta">Max waga</span>
            <span class="wartosc <?= $kpi['max_waga'] ? 'waga-' . esc((string) $kpi['max_waga'], 'attr') : '' ?>">
                <?= esc((string) ($kpi['max_waga'] ?? '—')) ?>
            </span>
        </div>
    </div>

    <h3>Problematyczne IP</h3>
    <p class="podpis" style="margin-top:0">
        Sortowanie: liczba źródeł → waga → liczba incydentów → ostatnia aktywność.
        Chipy i krótkie „dlaczego” — jak w badge LMS; CTA prowadzi do szczegółów IP.
    </p>

    <?php if ($topIp === []): ?>
        <p class="pusto">Brak IP spełniających filtry w wybranym oknie.</p>
    <?php else: ?>
        <table>
            <thead>
            <tr>
                <th>IP</th>
                <th>Kontekst</th>
                <th>Źródła</th>
                <th>Incydenty</th>
                <th>Max waga</th>
                <th>Ostatnio</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($topIp as $wiersz): ?>
                <?php
                $ipWiersz = (string) ($wiersz['ip'] ?? '');
                $maxWaga  = (string) ($wiersz['max_waga'] ?? '');
                $klasaWagi = in_array($maxWaga, ['low', 'medium', 'high', 'critical'], true)
                    ? 'waga-' . $maxWaga
                    : '';
                $kontekst = is_array($wiersz['kontekst'] ?? null) ? $wiersz['kontekst'] : null;
                $urlIp    = site_url('ip/' . $ipWiersz);
                ?>
                <tr class="<?= ! empty($wiersz['nasze_ip']) ? 'wiersz-nasze' : '' ?>">
                    <td>
                        <a href="<?= $urlIp ?>"><?= esc($ipWiersz) ?></a>
                        <?php if (! empty($wiersz['nasze_ip'])): ?>
                            <span class="badge-nasze" title="Adres w przestrzeni AS198050">nasze</span>
                        <?php endif; ?>
                    </td>
                    <td class="komorka-kontekst">
                        <?php if ($kontekst !== null): ?>
                            <div class="kontekst-chipy" aria-label="Skrót kontekstu">
                                <?php foreach (($kontekst['chipy'] ?? []) as $chip): ?>
                                    <?php
                                    $typ = (string) ($chip['typ'] ?? '');
                                    $klasaChip = 'kontekst-chip';
                                    if (str_starts_with($typ, 'waga-')) {
                                        $klasaChip .= ' ' . $typ;
                                    } elseif ($typ === 'zrodlo') {
                                        $klasaChip .= ' kontekst-chip-zrodlo';
                                    } elseif ($typ === 'kategoria') {
                                        $klasaChip .= ' kontekst-chip-kat';
                                    } elseif ($typ === 'liczba') {
                                        $klasaChip .= ' kontekst-chip-liczba';
                                    }
                                    ?>
                                    <span class="<?= esc($klasaChip, 'attr') ?>"><?= esc((string) ($chip['tekst'] ?? '')) ?></span>
                                <?php endforeach; ?>
                            </div>
                            <p class="kontekst-zdanie"><?= esc((string) ($kontekst['zdanie'] ?? '')) ?></p>
                        <?php else: ?>
                            <?php foreach (($wiersz['zrodla'] ?? []) as $z): ?>
                                <span class="zrodla-chip"><?= esc((string) $z) ?></span>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </td>
                    <td><?= (int) ($wiersz['liczba_zrodel'] ?? 0) ?></td>
                    <td><?= (int) ($wiersz['liczba_incydentow'] ?? 0) ?></td>
                    <td class="<?= esc($klasaWagi, 'attr') ?>"><?= esc($maxWaga !== '' ? $maxWaga : '—') ?></td>
                    <td><?= esc((string) ($wiersz['ostatnio_widziane'] ?? '—')) ?></td>
                    <td class="komorka-cta">
                        <a class="cta-ip" href="<?= $urlIp ?>"><?= esc((string) (($kontekst['cta'] ?? null) ?: 'Szczegóły')) ?></a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <h3>Ostatnie incydenty</h3>
    <p class="podpis" style="margin-top:0">Najnowsze skróty z indeksu (po filtrach).</p>

    <?php if ($ostatnie === []): ?>
        <p class="pusto">Brak incydentów w wybranym zakresie.</p>
    <?php else: ?>
        <table>
            <thead>
            <tr>
                <th>Ostatnio</th>
                <th>IP</th>
                <th>Waga</th>
                <th>Źródło</th>
                <th>Tytuł</th>
                <th>Kategoria</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($ostatnie as $wiersz): ?>
                <?php
                $ipWiersz = (string) ($wiersz['podmiot'] ?? '');
                $waga     = (string) ($wiersz['waga'] ?? '');
                $klasaWagi = in_array($waga, ['low', 'medium', 'high', 'critical'], true)
                    ? 'waga-' . $waga
                    : '';
                ?>
                <tr class="<?= ! empty($wiersz['nasze_ip']) ? 'wiersz-nasze' : '' ?>">
                    <td><?= esc((string) ($wiersz['ostatnio_widziane'] ?? '')) ?></td>
                    <td>
                        <?php if ($ipWiersz !== ''): ?>
                            <a href="<?= site_url('ip/' . $ipWiersz) ?>"><?= esc($ipWiersz) ?></a>
                            <?php if (! empty($wiersz['nasze_ip'])): ?>
                                <span class="badge-nasze" title="Adres w przestrzeni AS198050">nasze</span>
                            <?php endif; ?>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td class="<?= esc($klasaWagi, 'attr') ?>"><?= esc($waga) ?></td>
                    <td><?= esc((string) ($wiersz['zrodlo'] ?? '')) ?></td>
                    <td><?= esc((string) ($wiersz['tytul'] ?? '')) ?></td>
                    <td><?= esc((string) ($wiersz['kategoria'] ?? '')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</main>

<?= $this->endSection() ?>
