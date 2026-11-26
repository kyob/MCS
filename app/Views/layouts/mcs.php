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
    <link href="<?= base_url('vendor/bootstrap-5.3.3/dist/css/bootstrap.min.css') ?>" rel="stylesheet">
    <link href="<?= base_url('vendor/bootstrap-icons-1.11.3/font/bootstrap-icons.min.css') ?>" rel="stylesheet">
    <link href="<?= base_url('assets/css/mcs.css') ?>" rel="stylesheet">
</head>
<body class="bg-body-tertiary">
<?php
$nav            = $navAktywny ?? '';
$rolaKod        = (string) ($rola ?? '');
$rolaEtykieta   = $rolaKod !== '' ? \App\Libraries\Rola::etykieta($rolaKod) : '';
$mozePanelAdmin = \App\Libraries\Rola::mozePanelAdmin($rolaKod);
$mozeUzytkownicy = \App\Libraries\Rola::mozeUzytkownicy($rolaKod);
// Zmienna $wtyczkiMenu pozwala podać dane w testach.
$wtyczkiMenu = $wtyczkiMenu
    ?? (! empty($email) ? \App\Libraries\WidocznoscWtyczek::menu() : ['speedtest' => true, 'threatfox' => true]);
?>

<nav class="navbar navbar-expand-lg bg-body border-bottom shadow-sm sticky-top">
    <div class="container-fluid px-3 px-lg-4">
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
                $navDashboard = in_array($nav, ['dashboard', 'kolejka', 'problematyczne-ip', 'incydenty', 'speedtest', 'dnsbl', 'spamhaus', 'threatfox'], true);
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
                        <?php if (! empty($wtyczkiMenu['speedtest'])): ?>
                        <li>
                            <a class="dropdown-item<?= $nav === 'speedtest' ? ' active' : '' ?>"
                               href="<?= site_url('dashboard/speedtest') ?>">Speedtest</a>
                        </li>
                        <?php endif; ?>
                        <li>
                            <a class="dropdown-item<?= $nav === 'dnsbl' ? ' active' : '' ?>"
                               href="<?= site_url('dashboard/dnsbl') ?>">DNSBL</a>
                        </li>
                        <li>
                            <a class="dropdown-item<?= $nav === 'spamhaus' ? ' active' : '' ?>"
                               href="<?= site_url('dashboard/spamhaus') ?>">Spamhaus DROP</a>
                        </li>
                        <?php if (! empty($wtyczkiMenu['threatfox'])): ?>
                        <li>
                            <a class="dropdown-item<?= $nav === 'threatfox' ? ' active' : '' ?>"
                               href="<?= site_url('dashboard/threatfox') ?>">ThreatFox IOC</a>
                        </li>
                        <?php endif; ?>
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

            <div class="mcs-nav-akcje d-flex align-items-center flex-wrap flex-lg-nowrap gap-2 ms-lg-2 mb-2 mb-lg-0">
            <form class="d-flex m-0" method="get" action="<?= site_url('szukaj') ?>" role="search">
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

            <button type="button" class="btn btn-outline-secondary" id="themeToggle"
                    aria-label="Przełącz motyw jasny/ciemny" title="Motyw">
                <i class="bi bi-moon-stars-fill" id="themeIcon" aria-hidden="true"></i>
            </button>

            <div class="mcs-nav-konto navbar-text small text-body-secondary d-flex flex-nowrap align-items-center gap-2 m-0 p-0">
                <?php if (! empty($email)): ?>
                    <span class="text-nowrap"><?= esc($email) ?><?php if ($rolaEtykieta !== ''): ?> · <?= esc($rolaEtykieta) ?><?php endif; ?></span>
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

<footer class="border-top bg-body py-3 mt-auto">
    <div class="container small text-body-secondary">
        MCS · Moja Czysta Sieć · © <?= date('Y') ?>
    </div>
</footer>

<script src="<?= base_url('vendor/bootstrap-5.3.3/dist/js/bootstrap.bundle.min.js') ?>"></script>
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
