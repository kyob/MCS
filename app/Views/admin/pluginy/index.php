<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Wtyczki źródeł — MCS</title>
    <style>
        :root {
            --tlo: #e8eef4;
            --panel: #f7fafc;
            --akcent: #1f4e79;
            --blad: #8b1e1e;
            --ok: #1e5a3a;
            --tekst: #1a2332;
            --kreska: #b8c5d4;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: "Segoe UI", "Liberation Sans", sans-serif;
            color: var(--tekst);
            background:
                radial-gradient(ellipse at 10% 0%, #c5d8ea 0%, transparent 50%),
                linear-gradient(160deg, var(--tlo), #d5dde6);
            padding: 1.25rem 1rem 2rem;
        }
        header {
            max-width: 56rem;
            margin: 0 auto 1rem;
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            gap: 0.75rem;
            align-items: baseline;
        }
        header h1 {
            margin: 0;
            font-size: 1.35rem;
            color: var(--akcent);
        }
        header .meta {
            font-size: 0.85rem;
            color: #4a5a6a;
        }
        header a { color: var(--akcent); }
        main {
            max-width: 56rem;
            margin: 0 auto;
            background: var(--panel);
            border: 1px solid var(--kreska);
            padding: 1.25rem 1.1rem;
        }
        h2 {
            margin: 0 0 0.35rem;
            font-size: 1.15rem;
        }
        .podpis {
            margin: 0 0 1rem;
            font-size: 0.9rem;
            color: #4a5a6a;
        }
        .blad, .ok {
            margin: 0 0 1rem;
            padding: 0.55rem 0.7rem;
            font-size: 0.9rem;
            border: 1px solid;
        }
        .blad {
            background: #f8e8e8;
            border-color: #d9a0a0;
            color: var(--blad);
        }
        .ok {
            background: #e8f5ee;
            border-color: #9bc4ae;
            color: var(--ok);
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }
        th, td {
            text-align: left;
            padding: 0.55rem 0.4rem;
            border-bottom: 1px solid var(--kreska);
            vertical-align: middle;
        }
        th {
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            color: #4a5a6a;
        }
        .przełączniki {
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
            font-size: 0.85rem;
        }
        .przełączniki label {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            cursor: pointer;
        }
        button[type="submit"] {
            padding: 0.35rem 0.7rem;
            border: 0;
            background: var(--akcent);
            color: #fff;
            font: inherit;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
        }
        button[type="submit"]:hover { filter: brightness(1.08); }
        button[disabled],
        .pozniej {
            padding: 0.35rem 0.7rem;
            border: 1px solid var(--kreska);
            background: #eef2f6;
            color: #6a7a8a;
            font: inherit;
            font-size: 0.85rem;
            cursor: not-allowed;
            text-decoration: none;
            display: inline-block;
        }
        .health {
            font-size: 0.8rem;
            color: #4a5a6a;
        }
        .health.ok { color: var(--ok); }
        .health.blad { color: var(--blad); }
        .pusto {
            margin: 0.5rem 0 0;
            color: #4a5a6a;
        }
        .legenda {
            margin: 1.25rem 0 0;
            font-size: 0.85rem;
            color: #4a5a6a;
        }
        .legenda dt { font-weight: 600; margin-top: 0.4rem; }
        .legenda dd { margin: 0.15rem 0 0; }
    </style>
</head>
<body>
<header>
    <h1>Moja Czysta Sieć</h1>
    <p class="meta">
        <?= esc($email) ?> · <?= esc($rola) ?>
        · <a href="<?= site_url('logout') ?>">Wyloguj</a>
    </p>
</header>

<main>
    <h2>Wtyczki źródeł</h2>
    <p class="podpis">Przełączniki: wstrzymaj import oraz ukryj w liście IP i badge. Usuwanie danych — później.</p>

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
                    <td>
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
        <dd>Job nie biegnie; historia i badge nadal widoczne (o ile nie ukryte).</dd>
        <dt>Ukryj w UI / badge</dt>
        <dd>Dane zostają w bazie; lista IP i badge ich nie pokazują.</dd>
        <dt>Usuń dane</dt>
        <dd>Osobna, świadoma akcja — niedostępna w Fazie 1a.</dd>
    </dl>
</main>
</body>
</html>
