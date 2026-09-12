<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc($title ?? 'MCS') ?></title>
    <style>
        :root {
            --tlo: #e8eef4;
            --panel: #f7fafc;
            --akcent: #1f4e79;
            --blad: #8b1e1e;
            --ok: #1e5a3a;
            --tekst: #1a2332;
            --kreska: #b8c5d4;
            --meta: #4a5a6a;
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
        .shell {
            max-width: 72rem;
            margin: 0 auto;
        }
        header.top {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            gap: 0.75rem;
            align-items: baseline;
            margin-bottom: 0.75rem;
        }
        header.top h1 {
            margin: 0;
            font-size: 1.35rem;
            color: var(--akcent);
        }
        header.top .meta {
            font-size: 0.85rem;
            color: var(--meta);
        }
        header.top a { color: var(--akcent); }
        nav.nav {
            display: flex;
            flex-wrap: wrap;
            gap: 0.35rem 0.75rem;
            align-items: center;
            margin-bottom: 1rem;
            font-size: 0.9rem;
        }
        nav.nav a {
            color: var(--akcent);
            text-decoration: none;
            padding: 0.25rem 0.5rem;
            border: 1px solid transparent;
        }
        nav.nav a.aktywny {
            border-color: var(--akcent);
            background: #e4eef7;
            font-weight: 600;
        }
        nav.nav form {
            display: flex;
            gap: 0.35rem;
            margin-left: auto;
            align-items: center;
        }
        nav.nav input[type="text"] {
            width: 11rem;
            max-width: 40vw;
            padding: 0.3rem 0.45rem;
            border: 1px solid var(--kreska);
            background: #fff;
            font: inherit;
            font-size: 0.85rem;
        }
        nav.nav button {
            padding: 0.3rem 0.6rem;
            border: 0;
            background: var(--akcent);
            color: #fff;
            font: inherit;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
        }
        nav.nav button:hover { filter: brightness(1.08); }
        main.panel {
            background: var(--panel);
            border: 1px solid var(--kreska);
            padding: 1.25rem 1.1rem;
        }
        main.panel + main.panel { margin-top: 1rem; }
        h2 {
            margin: 0 0 0.35rem;
            font-size: 1.15rem;
        }
        h3 {
            margin: 1.25rem 0 0.5rem;
            font-size: 1rem;
            color: var(--akcent);
        }
        .podpis {
            margin: 0 0 1rem;
            font-size: 0.9rem;
            color: var(--meta);
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
        .kpi {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(9.5rem, 1fr));
            gap: 0.65rem;
            margin: 0 0 1.25rem;
        }
        .kpi .pozycja {
            border: 1px solid var(--kreska);
            background: #eef3f8;
            padding: 0.65rem 0.75rem;
        }
        .kpi .etykieta {
            display: block;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            color: var(--meta);
            margin-bottom: 0.2rem;
        }
        .kpi .wartosc {
            font-size: 1.35rem;
            font-weight: 700;
            color: var(--akcent);
            line-height: 1.2;
        }
        .filtry {
            display: flex;
            flex-wrap: wrap;
            gap: 0.55rem 0.75rem;
            margin: 0 0 1rem;
            align-items: end;
            font-size: 0.9rem;
        }
        .filtry label {
            display: flex;
            flex-direction: column;
            gap: 0.2rem;
            font-size: 0.8rem;
            color: var(--meta);
        }
        .filtry select,
        .filtry input[type="text"] {
            padding: 0.3rem 0.45rem;
            border: 1px solid var(--kreska);
            background: #fff;
            font: inherit;
            font-size: 0.9rem;
            color: var(--tekst);
            min-width: 7rem;
        }
        .filtry button,
        .filtry .reset {
            padding: 0.35rem 0.7rem;
            border: 0;
            background: var(--akcent);
            color: #fff;
            font: inherit;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        .filtry .reset {
            background: #eef2f6;
            color: #4a5a6a;
            border: 1px solid var(--kreska);
            font-weight: 500;
        }
        .filtry button:hover { filter: brightness(1.08); }
        .filtry a {
            color: var(--akcent);
            text-decoration: none;
            padding: 0.2rem 0.45rem;
            border: 1px solid transparent;
            align-self: center;
        }
        .filtry a.aktywny {
            border-color: var(--akcent);
            background: #e4eef7;
            font-weight: 600;
        }
        .filtry > span {
            align-self: center;
            font-size: 0.8rem;
            color: var(--meta);
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
            color: var(--meta);
        }
        td a { color: var(--akcent); }
        .pusto {
            margin: 0.5rem 0 0;
            color: var(--meta);
            font-size: 0.95rem;
        }
        .waga-critical { font-weight: 700; color: #7a1212; }
        .waga-high { font-weight: 600; color: #9a4a12; }
        .waga-medium { color: #6a5a10; }
        .waga-low { color: #3a5a4a; }
        .zrodla-chip {
            display: inline-block;
            margin: 0 0.2rem 0.2rem 0;
            padding: 0.05rem 0.35rem;
            border: 1px solid var(--kreska);
            background: #eef3f8;
            font-size: 0.78rem;
            white-space: nowrap;
        }
        .legenda {
            margin: 1.25rem 0 0;
            font-size: 0.85rem;
            color: var(--meta);
        }
        .legenda dt { font-weight: 600; margin-top: 0.4rem; }
        .legenda dd { margin: 0.15rem 0 0; }
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
            flex-direction: row;
            color: var(--tekst);
            font-size: 0.85rem;
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
        .health { font-size: 0.8rem; color: var(--meta); }
        .health.ok { color: var(--ok); }
        .health.blad { color: var(--blad); }
        .badge-nasze {
            display: inline-block;
            margin-left: 0.35rem;
            padding: 0.05rem 0.4rem;
            font-size: 0.72rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            border: 1px solid #7a9ab8;
            background: #dce8f4;
            color: var(--akcent);
            vertical-align: middle;
            white-space: nowrap;
        }
        .wiersz-nasze td:first-child {
            box-shadow: inset 3px 0 0 var(--akcent);
        }
        tr.wiersz-klik {
            cursor: pointer;
        }
        tr.wiersz-klik:hover td {
            background: #eef3f8;
        }
        .meta-lista {
            display: grid;
            grid-template-columns: 10rem 1fr;
            gap: 0.35rem 0.75rem;
            margin: 0 0 1rem;
            font-size: 0.9rem;
        }
        .meta-lista dt {
            margin: 0;
            color: var(--meta);
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .meta-lista dd {
            margin: 0;
        }
        .meta-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
            margin: 0 0 1rem;
        }
        .meta-table th {
            width: 11rem;
            text-align: left;
            color: var(--meta);
            font-weight: 600;
            text-transform: none;
            letter-spacing: 0;
            font-size: 0.85rem;
            vertical-align: top;
        }
        .payload {
            margin: 0.35rem 0 0.75rem;
            padding: 0.55rem 0.65rem;
            background: #eef3f8;
            border: 1px solid var(--kreska);
            font-size: 0.8rem;
            overflow-x: auto;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .timeline {
            margin: 0;
            padding: 0 0 0 1.1rem;
            list-style: decimal;
        }
        .timeline li {
            margin: 0 0 0.85rem;
            padding-left: 0.25rem;
        }
        .timeline-czas {
            font-weight: 600;
            color: var(--akcent);
            font-size: 0.9rem;
        }
        .timeline-meta {
            font-size: 0.8rem;
            color: var(--meta);
            margin-top: 0.15rem;
        }
        .enrichment-akcje {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            align-items: center;
            margin: 0 0 1rem;
        }
        .enrichment-akcje button {
            padding: 0.35rem 0.7rem;
            border: 0;
            background: var(--akcent);
            color: #fff;
            font: inherit;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
        }
        .enrichment-akcje button.reset {
            background: #eef2f6;
            color: #4a5a6a;
            border: 1px solid var(--kreska);
            font-weight: 500;
        }
        .enrichment-akcje button:hover:not([disabled]) { filter: brightness(1.08); }
        .enrichment-akcje button[disabled] {
            background: #eef2f6;
            color: #6a7a8a;
            border: 1px solid var(--kreska);
            cursor: not-allowed;
        }
        .enrichment-wynik[hidden] { display: none; }
        @media (max-width: 640px) {
            nav.nav form { margin-left: 0; width: 100%; }
            nav.nav input[type="text"] { flex: 1; max-width: none; }
            .kpi .wartosc { font-size: 1.2rem; }
            table { display: block; overflow-x: auto; }
            .meta-lista { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="shell">
    <header class="top">
        <h1>Moja Czysta Sieć</h1>
        <p class="meta">
            <?= esc($email ?? '') ?> · <?= esc($rola ?? '') ?>
            · <a href="<?= site_url('logout') ?>">Wyloguj</a>
        </p>
    </header>

    <?php
    $nav = $navAktywny ?? '';
    ?>
    <nav class="nav" aria-label="Główne">
        <a href="<?= site_url('/') ?>" class="<?= $nav === 'dashboard' ? 'aktywny' : '' ?>">Przegląd</a>
        <a href="<?= site_url('watchlista') ?>" class="<?= $nav === 'watchlista' ? 'aktywny' : '' ?>">Watchlista</a>
        <a href="<?= site_url('admin/pluginy') ?>" class="<?= $nav === 'pluginy' ? 'aktywny' : '' ?>">Wtyczki</a>
        <a href="<?= site_url('admin/ustawienia') ?>" class="<?= $nav === 'ustawienia' ? 'aktywny' : '' ?>">Ustawienia</a>
        <?php if ($nav === 'ip' && ! empty($ipNav)): ?>
            <a href="<?= site_url('ip/' . $ipNav) ?>" class="aktywny">IP <?= esc($ipNav) ?></a>
        <?php endif; ?>
        <form method="get" action="<?= site_url('szukaj') ?>" role="search">
            <label class="sr-only" for="szukaj-ip" style="position:absolute;left:-9999px">Szukaj IP</label>
            <input id="szukaj-ip" type="text" name="ip" placeholder="Szukaj IP…"
                   value="<?= esc($szukajIp ?? '', 'attr') ?>" autocomplete="off">
            <button type="submit">Otwórz</button>
        </form>
    </nav>

    <?= $this->renderSection('content') ?>
</div>
</body>
</html>
