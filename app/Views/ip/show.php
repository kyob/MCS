<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>IP <?= esc($ip) ?> — MCS</title>
    <style>
        :root {
            --tlo: #e8eef4;
            --panel: #f7fafc;
            --akcent: #1f4e79;
            --blad: #8b1e1e;
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
        .blad {
            margin: 0 0 1rem;
            padding: 0.55rem 0.7rem;
            background: #f8e8e8;
            border: 1px solid #d9a0a0;
            color: var(--blad);
            font-size: 0.9rem;
        }
        .filtry {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin: 0 0 1rem;
            align-items: center;
            font-size: 0.9rem;
        }
        .filtry a {
            color: var(--akcent);
            text-decoration: none;
            padding: 0.2rem 0.45rem;
            border: 1px solid transparent;
        }
        .filtry a.aktywny {
            border-color: var(--akcent);
            background: #e4eef7;
            font-weight: 600;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }
        th, td {
            text-align: left;
            padding: 0.45rem 0.4rem;
            border-bottom: 1px solid var(--kreska);
            vertical-align: top;
        }
        th {
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            color: #4a5a6a;
        }
        .pusto {
            margin: 0.5rem 0 0;
            color: #4a5a6a;
            font-size: 0.95rem;
        }
        .waga-critical { font-weight: 700; color: #7a1212; }
        .waga-high { font-weight: 600; color: #9a4a12; }
        .waga-medium { color: #6a5a10; }
        .waga-low { color: #3a5a4a; }
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
    <h2>Podmiot IP: <?= esc($ip !== '' ? $ip : '—') ?></h2>
    <p class="podpis">Lista skrótów incydentów ze wspólnego indeksu (źródła nieukryte).</p>

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
                    ?>
                    <tr>
                        <td class="<?= esc($klasaWagi, 'attr') ?>"><?= esc($waga) ?></td>
                        <td><?= esc((string) ($wiersz['zrodlo'] ?? '')) ?></td>
                        <td><?= esc((string) ($wiersz['tytul'] ?? '')) ?></td>
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
</body>
</html>
