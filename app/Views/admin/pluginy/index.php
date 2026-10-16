<?= $this->extend('layouts/mcs') ?>
<?= $this->section('content') ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Wtyczki źródeł</h1>
        <p class="text-body-secondary mb-0">
            Import i UI przełączasz kliknięciem stanu. Liczby ostatniego przebiegu są w kolumnie Status; pełny komunikat po najechaniu, szczegóły połączenia pod ikoną info.
            „Pobierz teraz” = ten sam tor co cron (<code>mcs:pobierz</code>).
        </p>
    </div>
    <?php if ($pluginy !== []): ?>
        <span class="badge text-bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle px-3 py-2">
            <?= count($pluginy) ?> źródeł
        </span>
    <?php endif; ?>
</div>

<?php if (! empty($blad)): ?>
    <div class="alert alert-danger border-0 shadow-sm rounded-3" role="alert"><?= esc($blad) ?></div>
<?php endif; ?>
<?php if (! empty($komunikat)): ?>
    <div class="alert alert-success border-0 shadow-sm rounded-3" role="status"><?= esc($komunikat) ?></div>
<?php endif; ?>

<?php if ($pluginy === []): ?>
    <div class="card border-0 shadow-sm rounded-3 mb-4">
        <div class="card-body">
            <p class="text-body-secondary mb-0">Brak wtyczek w rejestrze. Uruchom: <code>php spark mcs:seed-pluginy</code></p>
        </div>
    </div>
<?php else: ?>
    <div class="card border-0 shadow-sm rounded-3 mb-4">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <th scope="col" class="text-nowrap ps-3">Lp.</th>
                        <th scope="col">Nazwa</th>
                        <th scope="col" title="Kliknij stan, żeby włączyć lub wstrzymać pobieranie (cron i Pobierz teraz).">Import</th>
                        <th scope="col" title="Kliknij, żeby pokazać lub ukryć źródło w listach i badge.">UI</th>
                        <th scope="col" class="text-nowrap">Ostatni update</th>
                        <th scope="col" title="Najedź, żeby zobaczyć komunikat.">Status</th>
                        <th scope="col" class="pe-3">Akcje</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php $lp = 0; ?>
                    <?php foreach ($pluginy as $wiersz): ?>
                        <?php
                        $lp++;
                        $zrodlo = (string) ($wiersz['zrodlo'] ?? '');
                        $nazwa  = (string) ($wiersz['nazwa'] ?? $zrodlo);
                        $opis   = trim((string) ($wiersz['opis'] ?? ''));
                        $importWstrzymany = (bool) ($wiersz['import_wstrzymany'] ?? false);
                        $ukrytyWUi        = (bool) ($wiersz['ukryty_w_ui'] ?? false);
                        $healthOk         = $wiersz['health_ok'] ?? null;
                        $healthMsg        = \App\Libraries\PluginOpisPolaczenia::bezZargonu(
                            (string) ($wiersz['health_msg'] ?? ''),
                        );
                        $healthAt         = (string) ($wiersz['health_at'] ?? '');
                        $opisPol          = is_array($wiersz['opis_polaczenia'] ?? null) ? $wiersz['opis_polaczenia'] : [];
                        $polSkrot         = (string) ($opisPol['skrot'] ?? '');
                        $polPozycje       = is_array($opisPol['pozycje'] ?? null) ? $opisPol['pozycje'] : [];
                        $dataUp           = \App\Libraries\CzasUi::dataGodzina($healthAt);
                        $ileTemu          = \App\Libraries\CzasUi::wzglednie($healthAt);

                        $szczegolyHtml = '';
                        if ($opis !== '') {
                            $szczegolyHtml .= '<p class="mb-2">' . esc($opis) . '</p>';
                        }
                        if ($zrodlo === 'dnsbl') {
                            $szczegolyHtml .= '<p class="mb-2"><a href="' . site_url('admin/ustawienia/opcje')
                                . '">Konfiguracja list i skanu DNSBL</a> (Ustawienia → Opcje strojenia → grupa DNSBL)</p>';
                        }
                        if ($polSkrot !== '') {
                            $szczegolyHtml .= '<p class="mb-2"><span class="text-body-secondary">Połączenie:</span> '
                                . '<code>' . esc($polSkrot) . '</code></p>';
                        }
                        if ($polPozycje !== []) {
                            $szczegolyHtml .= '<ul class="list-unstyled small mb-0">';
                            foreach ($polPozycje as $poz) {
                                $pozNazwa   = (string) ($poz['nazwa'] ?? '');
                                $pozWartosc = (string) ($poz['wartosc'] ?? '');
                                $pozAktywna = (bool) ($poz['aktywna'] ?? false);
                                $szczegolyHtml .= '<li class="mb-2"><div class="text-body-secondary">' . esc($pozNazwa);
                                if ($pozAktywna) {
                                    $szczegolyHtml .= ' <span class="badge text-bg-primary">Aktywny</span>';
                                }
                                $szczegolyHtml .= '</div><div class="font-monospace text-break">'
                                    . esc($pozWartosc) . '</div></li>';
                            }
                            $szczegolyHtml .= '</ul>';
                        }

                        if ($healthOk === true) {
                            $statusIkona  = 'bi-check-circle-fill text-success';
                            $statusEtykieta = 'OK';
                        } elseif ($healthOk === false) {
                            $statusIkona  = 'bi-x-circle-fill text-danger';
                            $statusEtykieta = 'błąd';
                        } else {
                            $statusIkona  = 'bi-dash-circle text-secondary';
                            $statusEtykieta = 'brak danych';
                        }
                        $statusTitle = $healthMsg !== '' ? $healthMsg : $statusEtykieta;
                        $paryStatusu = \App\Libraries\MetrykiTekstu::pary($healthMsg);
                        $szczegolyId = 'plugin-szcz-' . preg_replace('/[^a-z0-9_-]+/i', '-', $zrodlo);
                        $szczegolySkrot = $polSkrot !== '' ? $polSkrot : $opis;
                        ?>
                        <tr>
                            <td class="text-body-secondary ps-3"><?= $lp ?></td>
                            <td>
                                <span class="fw-semibold" title="<?= esc($zrodlo) ?>"><?= esc($nazwa) ?></span>
                                <?php if ($szczegolyHtml !== ''): ?>
                                    <button type="button"
                                            class="btn btn-link btn-sm p-0 ms-1 align-baseline"
                                            data-bs-toggle="popover"
                                            data-bs-html="true"
                                            data-bs-placement="bottom"
                                            data-bs-title="<?= esc($nazwa) ?>"
                                            data-mcs-popover="<?= esc($szczegolyId, 'attr') ?>"
                                            title="<?= esc($szczegolySkrot) ?>"
                                            aria-label="Szczegóły połączenia">
                                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                                    </button>
                                    <div id="<?= esc($szczegolyId, 'attr') ?>" hidden>
                                        <?= $szczegolyHtml ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="post" action="<?= site_url('admin/pluginy') ?>" class="mb-0">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="zrodlo" value="<?= esc($zrodlo, 'attr') ?>">
                                    <input type="hidden" name="import_wstrzymany" value="<?= $importWstrzymany ? '0' : '1' ?>">
                                    <input type="hidden" name="ukryty_w_ui" value="<?= $ukrytyWUi ? '1' : '0' ?>">
                                    <button type="submit"
                                            class="badge rounded-pill border-0 <?= $importWstrzymany ? 'text-bg-secondary' : 'text-bg-success' ?>"
                                            title="<?= $importWstrzymany ? 'Kliknij, aby włączyć import' : 'Kliknij, aby wstrzymać import' ?>"><?= $importWstrzymany ? 'nieaktywny' : 'aktywny' ?></button>
                                </form>
                            </td>
                            <td>
                                <form method="post" action="<?= site_url('admin/pluginy') ?>" class="mb-0">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="zrodlo" value="<?= esc($zrodlo, 'attr') ?>">
                                    <input type="hidden" name="import_wstrzymany" value="<?= $importWstrzymany ? '1' : '0' ?>">
                                    <input type="hidden" name="ukryty_w_ui" value="<?= $ukrytyWUi ? '0' : '1' ?>">
                                    <button type="submit"
                                            class="badge rounded-pill border-0 <?= $ukrytyWUi ? 'text-bg-secondary' : 'text-bg-primary' ?>"
                                            title="<?= $ukrytyWUi ? 'Kliknij, aby pokazać w UI' : 'Kliknij, aby ukryć w listach i badge' ?>"><?= $ukrytyWUi ? 'niewidoczny' : 'widoczny' ?></button>
                                </form>
                            </td>
                            <td class="small text-nowrap">
                                <?php if ($dataUp === ''): ?>
                                    <span class="text-body-secondary">—</span>
                                <?php else: ?>
                                    <div><?= esc($dataUp) ?></div>
                                    <div class="text-body-secondary"><?= esc($ileTemu) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="small">
                                <div class="d-flex align-items-start gap-2">
                                    <span class="fs-5 flex-shrink-0"
                                          data-bs-toggle="tooltip"
                                          data-bs-title="<?= esc($statusTitle) ?>"
                                          title="<?= esc($statusTitle) ?>">
                                        <i class="bi <?= $statusIkona ?>" aria-hidden="true"></i>
                                        <span class="visually-hidden"><?= esc($statusEtykieta) ?></span>
                                    </span>
                                    <?php if ($paryStatusu !== null): ?>
                                        <?= view('partials/_metryki', ['pary' => $paryStatusu]) ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="pe-3">
                                <div class="d-inline-flex align-items-center gap-1 text-nowrap">
                                    <?php if ($zrodlo === 'snds'): ?>
                                        <button type="button" class="btn btn-sm btn-outline-primary"
                                                data-bs-toggle="modal" data-bs-target="#modalSndsKlucz"
                                                title="Klucz Automated Data Access (UUID)">
                                            <i class="bi bi-key me-1" aria-hidden="true"></i>Klucz
                                        </button>
                                    <?php endif; ?>
                                    <form method="post" action="<?= site_url('admin/pluginy/pobierz') ?>" class="mb-0"
                                          onsubmit="return confirm('Uruchomić pobieranie dla <?= esc($zrodlo, 'js') ?>? Może potrwać dłużej.');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="zrodlo" value="<?= esc($zrodlo, 'attr') ?>">
                                        <button type="submit" class="btn btn-sm btn-success"
                                            <?= $importWstrzymany ? 'disabled title="Import nieaktywny — włącz import, potem spróbuj ponownie"' : '' ?>>
                                            <i class="bi bi-download me-1" aria-hidden="true"></i>Pobierz teraz
                                        </button>
                                    </form>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" disabled
                                            title="Dostępne w późniejszej fazie">Usuń dane</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php
$sndsKlucz = is_array($sndsKlucz ?? null) ? $sndsKlucz : [];
$sndsUstawiony = (bool) ($sndsKlucz['ustawiony'] ?? false);
$sndsZrodlo = (string) ($sndsKlucz['zrodlo'] ?? '');
$sndsSkrot = (string) ($sndsKlucz['skrot'] ?? '');
$sndsZrodloEtykieta = match ($sndsZrodlo) {
    \App\Libraries\OpcjeMcs::ZRODLO_DB  => 'z bazy',
    \App\Libraries\OpcjeMcs::ZRODLO_ENV => 'z .env',
    default                             => 'brak',
};
?>
<div class="modal fade" id="modalSndsKlucz" tabindex="-1" aria-labelledby="modalSndsKluczLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" action="<?= site_url('admin/pluginy/snds-klucz') ?>" class="modal-content">
            <?= csrf_field() ?>
            <div class="modal-header">
                <h2 class="modal-title h5" id="modalSndsKluczLabel">Klucz SNDS (Auto Data Access)</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
            </div>
            <div class="modal-body">
                <p class="small text-body-secondary mb-3">
                    Status:
                    <?php if ($sndsUstawiony): ?>
                        <span class="badge text-bg-success"><?= esc($sndsZrodloEtykieta) ?></span>
                        <code class="ms-1"><?= esc($sndsSkrot) ?></code>
                    <?php else: ?>
                        <span class="badge text-bg-secondary">brak</span>
                    <?php endif; ?>
                </p>
                <div class="mb-3">
                    <a class="btn btn-sm btn-outline-secondary"
                       href="https://substrate.office.com/ip-domain-management-snds/SNDS/Auto"
                       target="_blank" rel="noopener noreferrer">
                        Pobierz klucz z portalu Auto
                    </a>
                    <div class="form-text text-break mt-1">
                        <a href="https://substrate.office.com/ip-domain-management-snds/SNDS/Auto"
                           target="_blank" rel="noopener noreferrer">https://substrate.office.com/ip-domain-management-snds/SNDS/Auto</a>
                        — klucz wygasa zwykle po ~30 dniach.
                    </div>
                </div>
                <label class="form-label" for="mcs_snds_key">Nowy klucz (UUID)</label>
                <div class="input-group">
                    <input type="password" class="form-control font-monospace" id="mcs_snds_key"
                           name="mcs_snds_key" autocomplete="off"
                           placeholder="<?= $sndsUstawiony ? '••••••••-••••-••••-••••-••••••••••••' : 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx' ?>">
                    <button type="button" class="btn btn-outline-secondary" id="mcs_snds_key_toggle"
                            title="Pokaż / ukryj" aria-label="Pokaż lub ukryj klucz">
                        <i class="bi bi-eye" aria-hidden="true"></i>
                    </button>
                </div>
                <div class="form-text">
                    Puste pole + Zapisz usuwa wartość z bazy (wraca <code>mcs.snds.key</code> z .env).
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1" aria-hidden="true"></i>Zapisz klucz
                </button>
            </div>
        </form>
    </div>
</div>
<script>
(function () {
    var btn = document.getElementById('mcs_snds_key_toggle');
    var inp = document.getElementById('mcs_snds_key');
    if (!btn || !inp) return;
    btn.addEventListener('click', function () {
        var show = inp.type === 'password';
        inp.type = show ? 'text' : 'password';
        btn.querySelector('i')?.classList.toggle('bi-eye', !show);
        btn.querySelector('i')?.classList.toggle('bi-eye-slash', show);
    });
})();
</script>

<section class="mb-4">
    <div class="card border-0 shadow-sm rounded-3">
        <div class="card-header bg-transparent border-0 pt-3 px-3 pb-0 d-flex flex-wrap justify-content-between align-items-start gap-2">
            <div>
                <h2 class="h5 mb-1">Historia importów</h2>
                <p class="small text-body-secondary mb-0">
                    Ostatnie <?= (int) count($importy ?? []) ?> przebiegów
                    (ok / błąd / kwarantanna / w trakcie).
                </p>
            </div>
            <a href="<?= site_url('admin/pluginy/historia-importow') ?>" class="btn btn-outline-primary btn-sm">
                Pełna historia importów
            </a>
        </div>
        <div class="card-body pt-3">
            <?php if (empty($importy)): ?>
                <p class="text-body-secondary mb-0">Brak przebiegów importu.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                        <tr>
                            <th scope="col">ID</th>
                            <th scope="col">Źródło</th>
                            <th scope="col">Status</th>
                            <th scope="col">Start</th>
                            <th scope="col">Koniec</th>
                            <th scope="col">Szczegóły</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($importy as $imp): ?>
                            <?= view('admin/pluginy/_import_wiersz', ['imp' => $imp]) ?>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<?= $this->endSection() ?>
