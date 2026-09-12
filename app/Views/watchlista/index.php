<?= $this->extend('layouts/mcs') ?>
<?= $this->section('content') ?>

<main class="panel">
    <h2>Watchlista IP</h2>
    <p class="podpis">
        Obserwowane adresy do digesta operacyjnego (<code>mcs:digest</code>).
        Tylko <strong>INFO</strong> — MCS nie blokuje ani nie karze automatycznie.
        Szczegóły incydentów: strona <code>/ip/{ip}</code>.
    </p>

    <?php if (! empty($blad)): ?>
        <p class="blad"><?= esc($blad) ?></p>
    <?php endif; ?>
    <?php if (! empty($komunikat)): ?>
        <p class="ok"><?= esc($komunikat) ?></p>
    <?php endif; ?>

    <h3>Dodaj IP</h3>
    <form method="post" action="<?= site_url('watchlista/dodaj') ?>" class="filtry">
        <?= csrf_field() ?>
        <label>
            Adres IP
            <input type="text" name="ip" required placeholder="np. 198.51.100.10"
                   autocomplete="off" value="">
        </label>
        <label>
            Notatka (opcjonalnie)
            <input type="text" name="notatka" placeholder="np. klient VIP / skarga"
                   maxlength="500" value="">
        </label>
        <button type="submit">Dodaj do watchlisty</button>
    </form>

    <h3>Obserwowane adresy (<?= count($lista) ?>)</h3>
    <?php if ($lista === []): ?>
        <p class="pusto">Watchlista jest pusta. Dodaj IP powyżej, potem uruchom <code>php spark mcs:digest</code>.</p>
    <?php else: ?>
        <table>
            <thead>
            <tr>
                <th>IP</th>
                <th>Notatka</th>
                <th>Dodano</th>
                <th>Przez</th>
                <th>Akcje</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($lista as $w): ?>
                <?php
                $ip = (string) ($w['ip'] ?? '');
                $klasa = ! empty($w['nasze_ip']) ? 'wiersz-nasze' : '';
                ?>
                <tr class="<?= esc($klasa, 'attr') ?>">
                    <td>
                        <a href="<?= site_url('ip/' . $ip) ?>"><?= esc($ip) ?></a>
                        <?php if (! empty($w['nasze_ip'])): ?>
                            <span class="badge-nasze">nasze IP</span>
                        <?php endif; ?>
                    </td>
                    <td><?= esc((string) ($w['notatka'] ?? '')) ?: '—' ?></td>
                    <td><?= esc((string) ($w['dodano_at'] ?? '')) ?></td>
                    <td><?= esc((string) ($w['dodano_przez'] ?? '')) ?: '—' ?></td>
                    <td>
                        <form method="post" action="<?= site_url('watchlista/usun') ?>"
                              style="display:inline"
                              onsubmit="return confirm('Usunąć <?= esc($ip, 'js') ?> z watchlisty?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="ip" value="<?= esc($ip, 'attr') ?>">
                            <button type="submit" class="reset" style="cursor:pointer">Usuń</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <dl class="legenda">
        <dt>Digest CLI</dt>
        <dd>
            <code>php spark mcs:digest</code> — high|critical z ostatnich 24 h dla IP z listy.<br>
            <code>php spark mcs:digest --godziny=6 --tylko-as198050</code> — węższe okno + filtr AS<?= (int) $asAsn ?>.<br>
            Cron: dopisz np. co godzinę i loguj do <code>writable/logs/mcs-digest.log</code>.
        </dd>
        <dt>Zakres</dt>
        <dd>
            Digest raportuje tylko wagi <strong>high</strong> i <strong>critical</strong>
            (widoczne źródła). Nie uruchamia blokad ani kar.
        </dd>
    </dl>
</main>

<?= $this->endSection() ?>
