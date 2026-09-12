<?= $this->extend('layouts/mcs') ?>
<?= $this->section('content') ?>

<main class="panel">
    <h2>Wtyczki źródeł</h2>
    <p class="podpis">Przełączniki: wstrzymaj import oraz ukryj w liście IP i badge. „Pobierz teraz” uruchamia ten sam tor co cron (<code>mcs:pobierz</code>).</p>

    <?php if (! empty($blad)): ?>
        <p class="blad"><?= esc($blad) ?></p>
    <?php endif; ?>
    <?php if (! empty($komunikat)): ?>
        <p class="ok"><?= esc($komunikat) ?></p>
    <?php endif; ?>

    <?php if ($pluginy === []): ?>
        <p class="pusto">Brak wtyczek w rejestrze. Uruchom: <code>php spark mcs:seed-pluginy</code></p>
    <?php else: ?>
        <table>
            <thead>
            <tr>
                <th>Źródło</th>
                <th>Stany</th>
                <th>Zdrowie</th>
                <th>Akcje</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($pluginy as $wiersz): ?>
                <?php
                $zrodlo = (string) ($wiersz['zrodlo'] ?? '');
                $nazwa  = (string) ($wiersz['nazwa'] ?? $zrodlo);
                $importWstrzymany = (bool) ($wiersz['import_wstrzymany'] ?? false);
                $ukrytyWUi        = (bool) ($wiersz['ukryty_w_ui'] ?? false);
                $healthOk         = $wiersz['health_ok'] ?? null;
                $healthMsg        = (string) ($wiersz['health_msg'] ?? '');
                $healthAt         = (string) ($wiersz['health_at'] ?? '');
                $klasaHealth      = $healthOk === true ? ' ok' : ($healthOk === false ? ' blad' : '');
                ?>
                <tr>
                    <td>
                        <strong><?= esc($nazwa) ?></strong><br>
                        <code><?= esc($zrodlo) ?></code>
                    </td>
                    <td>
                        <form method="post" action="<?= site_url('admin/pluginy') ?>" class="przełączniki">
                            <?= csrf_field() ?>
                            <input type="hidden" name="zrodlo" value="<?= esc($zrodlo, 'attr') ?>">
                            <label>
                                <input type="checkbox" name="import_wstrzymany" value="1"
                                    <?= $importWstrzymany ? 'checked' : '' ?>>
                                Wstrzymaj import
                            </label>
                            <label>
                                <input type="checkbox" name="ukryty_w_ui" value="1"
                                    <?= $ukrytyWUi ? 'checked' : '' ?>>
                                Ukryj w UI / badge
                            </label>
                            <button type="submit">Zapisz</button>
                        </form>
                    </td>
                    <td class="health<?= esc($klasaHealth, 'attr') ?>">
                        <?php if ($healthAt === '' && $healthMsg === '' && $healthOk === null): ?>
                            brak danych
                        <?php else: ?>
                            <?= $healthOk === true ? 'OK' : ($healthOk === false ? 'błąd' : '—') ?>
                            <?php if ($healthMsg !== ''): ?>
                                — <?= esc($healthMsg) ?>
                            <?php endif; ?>
                            <?php if ($healthAt !== ''): ?>
                                <br><?= esc($healthAt) ?>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td class="akcje-zrodla">
                        <form method="post" action="<?= site_url('admin/pluginy/pobierz') ?>" class="form-pobierz"
                              onsubmit="return confirm('Uruchomić pobieranie dla <?= esc($zrodlo, 'js') ?>? Może potrwać dłużej.');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="zrodlo" value="<?= esc($zrodlo, 'attr') ?>">
                            <button type="submit" <?= $importWstrzymany ? 'disabled title="Odblokuj import (Wstrzymaj import = wyłączone), potem spróbuj ponownie"' : '' ?>>
                                Pobierz teraz
                            </button>
                        </form>
                        <button type="button" disabled title="Dostępne w późniejszej fazie">Usuń dane</button>
                        <span class="pozniej" title="Świadoma akcja usuwania danych — poza Fazą 1a">później</span>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <dl class="legenda">
        <dt>Wstrzymaj import</dt>
        <dd>Job (cron i „Pobierz teraz”) nie biegnie; historia i badge nadal widoczne (o ile nie ukryte).</dd>
        <dt>Pobierz teraz</dt>
        <dd>Ręczny przebieg tego samego toru co <code>php spark mcs:pobierz &lt;zrodlo&gt;</code>. Przy zajętej blokadzie (cron) dostaniesz komunikat zamiast drugiego równoległego joba.</dd>
        <dt>Ukryj w UI / badge</dt>
        <dd>Dane zostają w bazie; lista IP i badge ich nie pokazują.</dd>
        <dt>Usuń dane</dt>
        <dd>Osobna, świadoma akcja — niedostępna w Fazie 1a.</dd>
    </dl>
</main>

<main class="panel">
    <h2>Historia importów</h2>
    <p class="podpis">Ostatnie <?= (int) count($importy ?? []) ?> przebiegów z <code>mcs_importy</code> (ok / błąd / kwarantanna / w trakcie).</p>

    <?php if (empty($importy)): ?>
        <p class="pusto">Brak przebiegów importu.</p>
    <?php else: ?>
        <table>
            <thead>
            <tr>
                <th>ID</th>
                <th>Źródło</th>
                <th>Status</th>
                <th>Start</th>
                <th>Koniec</th>
                <th>Szczegóły</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($importy as $imp): ?>
                <?php
                $st = (string) ($imp['status'] ?? '');
                $klasaSt = match ($st) {
                    'ok' => ' ok',
                    'blad', 'kwarantanna' => ' blad',
                    default => '',
                };
                $etykietaSt = match ($st) {
                    'ok' => 'ok',
                    'blad' => 'błąd',
                    'kwarantanna' => 'kwarantanna',
                    'w_trakcie' => 'w trakcie',
                    default => $st !== '' ? $st : '—',
                };
                $szczegoly = trim((string) ($imp['blad_text'] ?? ''));
                if ($szczegoly === '' && ! empty($imp['id_wiadomosci'])) {
                    $szczegoly = 'msg: ' . (string) $imp['id_wiadomosci'];
                }
                if ($szczegoly === '' && ! empty($imp['checksum'])) {
                    $szczegoly = 'checksum: ' . substr((string) $imp['checksum'], 0, 16) . '…';
                }
                ?>
                <tr>
                    <td><?= (int) ($imp['id'] ?? 0) ?></td>
                    <td><code><?= esc((string) ($imp['zrodlo'] ?? '')) ?></code></td>
                    <td class="health<?= esc($klasaSt, 'attr') ?>"><?= esc($etykietaSt) ?></td>
                    <td><?= esc((string) ($imp['rozpoczeto_at'] ?? '')) ?></td>
                    <td><?= esc((string) ($imp['zakonczono_at'] ?? '—')) ?></td>
                    <td class="health"><?= $szczegoly !== '' ? esc($szczegoly) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</main>

<style>
    .akcje-zrodla {
        display: flex;
        flex-wrap: wrap;
        gap: 0.35rem;
        align-items: center;
    }
    .form-pobierz { display: inline; margin: 0; }
    .form-pobierz button[type="submit"] {
        background: #2a6a4a;
    }
    .form-pobierz button[type="submit"]:disabled {
        background: #eef2f6;
        color: #6a7a8a;
        border: 1px solid var(--kreska);
        cursor: not-allowed;
        filter: none;
    }
</style>

<?= $this->endSection() ?>
