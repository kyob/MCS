<!DOCTYPE html>
<html lang="pl" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc($title ?? 'MCS') ?></title>
    <script>
        (function () {
            try {
                var t = localStorage.getItem('mcs-theme');
                if (t === 'light' || t === 'dark') {
                    document.documentElement.setAttribute('data-bs-theme', t);
                } else if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                    document.documentElement.setAttribute('data-bs-theme', 'dark');
                }
            } catch (e) { /* ignore */ }
        })();
    </script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
          crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet"
          integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+"
          crossorigin="anonymous">
    <style>
        .waga-critical { color: var(--bs-danger); font-weight: 700; }
        .waga-high { color: #984c0c; font-weight: 650; }
        .waga-medium { color: #664d03; font-weight: 600; }
        .waga-low { color: var(--bs-success); font-weight: 550; }
        [data-bs-theme="dark"] .waga-high { color: var(--bs-warning); }
        [data-bs-theme="dark"] .waga-medium { color: var(--bs-secondary-color); }
        .border-waga-critical { border-left: 0.25rem solid var(--bs-danger) !important; }
        .border-waga-high { border-left: 0.25rem solid var(--bs-warning) !important; }
        .border-waga-medium { border-left: 0.25rem solid var(--bs-secondary) !important; }
        .border-waga-low { border-left: 0.25rem solid var(--bs-success) !important; }
        .mini-timeline { display: flex; gap: 2px; align-items: flex-end; height: 1.05rem; }
        .mini-timeline-slupek { width: 0.4rem; height: 0.3rem; background: var(--bs-border-color); }
        .mini-timeline-slupek.aktywny { height: 100%; background: var(--bs-primary); }
        .payload { max-height: 24rem; overflow: auto; font-size: 0.8rem; }
        .navbar .form-control { min-width: 10rem; }
        .mcs-plugin-popover { max-width: 28rem; }
        .mcs-dzien-zrodla > summary { list-style: none; cursor: pointer; }
        .mcs-dzien-zrodla > summary::-webkit-details-marker { display: none; }
        .mcs-dzien-zrodla > summary:hover .progress,
        .mcs-dzien-zrodla[open] > summary .progress {
            outline: 2px solid var(--bs-primary);
            outline-offset: 1px;
        }
        .mcs-dzien-chevron { transition: transform .15s ease; }
        .mcs-dzien-zrodla[open] .mcs-dzien-chevron { transform: rotate(180deg); }
        body { min-height: 100vh; display: flex; flex-direction: column; }
        main { flex: 1 0 auto; }
        /* Link poza aplikacją (nowa karta): mała ikona za tekstem. */
        a[target="_blank"]::after {
            content: "\f1c5";
            font-family: "bootstrap-icons";
            font-style: normal;
            font-weight: 400 !important;
            font-variant: normal;
            text-transform: none;
            line-height: 1;
            font-size: 0.72em;
            margin-inline-start: 0.28em;
            vertical-align: 0.08em;
            display: inline-block;
            speak: never;
            -webkit-font-smoothing: antialiased;
        }
    </style>
</head>
<body class="bg-body-tertiary">
<?php
$nav            = $navAktywny ?? '';
$rolaKod        = (string) ($rola ?? '');
$rolaEtykieta   = $rolaKod !== '' ? \App\Libraries\Rola::etykieta($rolaKod) : '';
$mozePanelAdmin = \App\Libraries\Rola::mozePanelAdmin($rolaKod);
$mozeUzytkownicy = \App\Libraries\Rola::mozeUzytkownicy($rolaKod);
?>

<nav class="navbar navbar-expand-lg bg-body border-bottom shadow-sm sticky-top">
    <div class="container">
        <a class="navbar-brand fw-semibold" href="<?= site_url('/') ?>">
            <i class="bi bi-shield-lock-fill me-1 text-primary" aria-hidden="true"></i>
            MCS
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mcsNavbar"
                aria-controls="mcsNavbar" aria-expanded="false" aria-label="Przełącz nawigację">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="mcsNavbar">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <?php
                $navDashboard = in_array($nav, ['dashboard', 'kolejka', 'problematyczne-ip', 'incydenty', 'speedtest', 'dnsbl', 'spamhaus'], true);
                ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle<?= $navDashboard ? ' active' : '' ?>"
                       <?= $navDashboard ? 'aria-current="page"' : '' ?>
                       href="<?= site_url('/') ?>" data-bs-toggle="dropdown" aria-expanded="false">
                        Dashboard
                    </a>
                    <ul class="dropdown-menu">
                        <li>
                            <a class="dropdown-item<?= $nav === 'dashboard' ? ' active' : '' ?>"
                               href="<?= site_url('/') ?>">Przegląd</a>
                        </li>
                        <li>
                            <a class="dropdown-item<?= $nav === 'kolejka' ? ' active' : '' ?>"
                               href="<?= site_url('dashboard/kolejka') ?>">Kolejka dnia</a>
                        </li>
                        <li>
                            <a class="dropdown-item<?= $nav === 'problematyczne-ip' ? ' active' : '' ?>"
                               href="<?= site_url('dashboard/problematyczne-ip') ?>">Problematyczne IP</a>
                        </li>
                        <li>
                            <a class="dropdown-item<?= $nav === 'incydenty' ? ' active' : '' ?>"
                               href="<?= site_url('dashboard/incydenty') ?>">Ostatnie incydenty</a>
                        </li>
                        <li>
                            <a class="dropdown-item<?= $nav === 'speedtest' ? ' active' : '' ?>"
                               href="<?= site_url('dashboard/speedtest') ?>">Speedtest</a>
                        </li>
                        <li>
                            <a class="dropdown-item<?= $nav === 'dnsbl' ? ' active' : '' ?>"
                               href="<?= site_url('dashboard/dnsbl') ?>">DNSBL</a>
                        </li>
                        <li>
                            <a class="dropdown-item<?= $nav === 'spamhaus' ? ' active' : '' ?>"
                               href="<?= site_url('dashboard/spamhaus') ?>">Spamhaus DROP</a>
                        </li>
                    </ul>
                </li>
                <li class="nav-item">
                    <a class="nav-link<?= $nav === 'watchlista' ? ' active' : '' ?>"
                       <?= $nav === 'watchlista' ? 'aria-current="page"' : '' ?>
                       href="<?= site_url('watchlista') ?>">Watchlista</a>
                </li>
                <?php if ($mozePanelAdmin): ?>
                <li class="nav-item">
                    <a class="nav-link<?= $nav === 'pluginy' ? ' active' : '' ?>"
                       <?= $nav === 'pluginy' ? 'aria-current="page"' : '' ?>
                       href="<?= site_url('admin/pluginy') ?>">Wtyczki</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link<?= $nav === 'ustawienia' ? ' active' : '' ?>"
                       <?= $nav === 'ustawienia' ? 'aria-current="page"' : '' ?>
                       href="<?= site_url('admin/ustawienia') ?>">Ustawienia</a>
                </li>
                <?php endif; ?>
                <?php if ($mozeUzytkownicy): ?>
                <li class="nav-item">
                    <a class="nav-link<?= $nav === 'uzytkownicy' ? ' active' : '' ?>"
                       <?= $nav === 'uzytkownicy' ? 'aria-current="page"' : '' ?>
                       href="<?= site_url('admin/uzytkownicy') ?>">Użytkownicy</a>
                </li>
                <?php endif; ?>
                <?php if ($nav === 'ip' && ! empty($ipNav)): ?>
                    <li class="nav-item">
                        <a class="nav-link active font-monospace" aria-current="page"
                           href="<?= site_url('ip/' . $ipNav) ?>">IP <?= esc($ipNav) ?></a>
                    </li>
                <?php endif; ?>
            </ul>

            <form class="d-flex me-lg-2 mb-2 mb-lg-0" method="get" action="<?= site_url('szukaj') ?>" role="search">
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span>
                    <input class="form-control font-monospace" type="search" name="ip" id="szukaj-ip"
                           placeholder="Szukaj IP, AS…" aria-label="Szukaj IP"
                           value="<?= esc($szukajIp ?? '', 'attr') ?>" autocomplete="off">
                    <button class="btn btn-outline-secondary" type="submit" aria-label="Szukaj">
                        <i class="bi bi-arrow-return-left" aria-hidden="true"></i>
                    </button>
                </div>
            </form>

            <button type="button" class="btn btn-outline-secondary me-lg-2 mb-2 mb-lg-0" id="themeToggle"
                    aria-label="Przełącz motyw jasny/ciemny" title="Motyw">
                <i class="bi bi-moon-stars-fill" id="themeIcon" aria-hidden="true"></i>
            </button>

            <div class="navbar-text small text-body-secondary d-flex flex-wrap align-items-center gap-1">
                <?php if (! empty($email)): ?>
                    <span class="me-1"><?= esc($email) ?><?php if ($rolaEtykieta !== ''): ?> · <?= esc($rolaEtykieta) ?><?php endif; ?></span>
                <?php endif; ?>
                <form method="post" action="<?= site_url('logout') ?>" class="d-inline m-0">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-box-arrow-right me-1" aria-hidden="true"></i>Wyloguj
                    </button>
                </form>
            </div>
        </div>
    </div>
</nav>

<?php
// Pasek ostrzeżenia o wtyczkach (tylko zalogowani). Zmienna $pasekZdrowia pozwala podać dane w testach.
$pasekZdrowia = ! empty($email)
    ? ($pasekZdrowia ?? \App\Libraries\ZdrowieWtyczek::pasek())
    : null;
?>
<?php if ($pasekZdrowia !== null): ?>
    <div class="alert alert-<?= $pasekZdrowia['status'] === 'CRITICAL' ? 'danger' : 'warning' ?> rounded-0 border-0 border-bottom mb-0 py-2" role="alert" id="pasek-zdrowia">
        <div class="container small">
            <i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>
            <strong>Wtyczki:</strong> <?= esc((string) $pasekZdrowia['opis']) ?>
            <?php if ($mozePanelAdmin): ?>
                <a class="alert-link ms-1" href="<?= site_url('admin/pluginy') ?>">Szczegóły</a>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<main class="container py-4 py-lg-5">
    <?= $this->renderSection('content') ?>
</main>

<footer class="border-top bg-body py-4 mt-auto">
    <div class="container">
        <div class="row g-3 align-items-center">
            <div class="col-12 col-md">
                <div class="fw-semibold mb-1">
                    <i class="bi bi-shield-lock-fill me-1 text-primary" aria-hidden="true"></i>
                    MCS
                </div>
                <p class="small text-body-secondary mb-0">
                    Monitoring incydentów i watchlista IP · © <?= date('Y') ?> MCS
                </p>
            </div>
            <div class="col-12 col-md-auto">
                <nav class="d-flex flex-wrap gap-3 small" aria-label="Stopka">
                    <a class="link-body-emphasis text-decoration-none" href="<?= site_url('/') ?>">Dashboard</a>
                    <a class="link-body-emphasis text-decoration-none" href="<?= site_url('watchlista') ?>">Watchlista</a>
                    <?php if ($mozePanelAdmin): ?>
                    <a class="link-body-emphasis text-decoration-none" href="<?= site_url('admin/pluginy') ?>">Wtyczki</a>
                    <a class="link-body-emphasis text-decoration-none" href="<?= site_url('admin/ustawienia') ?>">Ustawienia</a>
                    <?php endif; ?>
                    <?php if ($mozeUzytkownicy): ?>
                    <a class="link-body-emphasis text-decoration-none" href="<?= site_url('admin/uzytkownicy') ?>">Użytkownicy</a>
                    <?php endif; ?>
                </nav>
            </div>
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
        crossorigin="anonymous"></script>
<script>
(function () {
    var root = document.documentElement;
    var toggle = document.getElementById('themeToggle');
    var icon = document.getElementById('themeIcon');
    var storageKey = 'mcs-theme';

    function applyTheme(theme) {
        root.setAttribute('data-bs-theme', theme);
        if (icon) {
            icon.className = theme === 'dark' ? 'bi bi-sun-fill' : 'bi bi-moon-stars-fill';
        }
        try { localStorage.setItem(storageKey, theme); } catch (e) { /* ignore */ }
    }

    applyTheme(root.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light');

    if (toggle) {
        toggle.addEventListener('click', function () {
            var next = root.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
            applyTheme(next);
        });
    }

    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
        bootstrap.Tooltip.getOrCreateInstance(el);
    });
    document.querySelectorAll('[data-bs-toggle="popover"]').forEach(function (el) {
        bootstrap.Popover.getOrCreateInstance(el, {
            container: 'body',
            html: true,
            customClass: 'mcs-plugin-popover',
            content: function () {
                var id = el.getAttribute('data-mcs-popover');
                var src = id ? document.getElementById(id) : null;
                return src ? src.innerHTML : '';
            }
        });
    });
})();
</script>
</body>
</html>
