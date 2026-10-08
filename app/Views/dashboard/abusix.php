<?= $this->extend('layouts/mcs') ?>
<?= $this->section('content') ?>

<?php
$d         = is_array($d ?? null) ? $d : [];
$kpi       = $d['kpi'] ?? ['sprawy' => 0, 'otwarte' => 0, 'zdarzenia' => 0, 'klienci' => 0];
$sprawy    = $d['sprawy'] ?? [];
$zdarzenia = $d['zdarzenia'] ?? [];
$klienci   = $d['klienci'] ?? [];
$wgStanu   = $d['wg_stanu'] ?? [];
$blad      = $blad ?? null;
$ostatniOk = (string) ($d['ostatni_ok'] ?? '');
$stanKlasa = static fn (string $s): string => match ($s) {
    'NEW' => 'text-bg-danger', 'IN_PROGRESS' => 'text-bg-warning', 'RESOLVED' => 'text-bg-success', default => 'text-bg-secondary',
};
?>

<div class="mb-4">
    <nav aria-label="Okruszki" class="mb-2">
        <ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= site_url('/') ?>">Przegląd</a></li>
            <li class="breadcrumb-item active" aria-current="page">Abusix</li>
        </ol>
    </nav>
    <h1 class="h3 mb-1">Abusix — Guardian Ops
        <?php if ((int) $kpi['otwarte'] > 0): ?>
            <span class="badge text-bg-danger align-middle fs-6"><?= (int) $kpi['otwarte'] ?> otwartych</span>
        <?php endif; ?>
    </h1>
    <p class="text-body-secondary mb-0">
        Sprawy, zdarzenia i klienci z Abusix API. Zdarzenia z IP trafiają także do indeksu incydentów (źródło abusix).
        Ostatnie udane pobranie:
        <strong><?= $ostatniOk !== '' ? esc($ostatniOk) : 'jeszcze nie było' ?></strong>.
        Status wtyczki:
        <span class="badge <?= ($d['health_ok'] ?? null) === true ? 'text-bg-success' : 'text-bg-warning' ?>"><?= esc((string) (($d['health_msg'] ?? '') !== '' ? $d['health_msg'] : 'brak przebiegu')) ?></span>
    </p>
</div>

<div class="alert alert-warning border-0 shadow-sm rounded-3" role="note" id="abusix-adnotacja">
    <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i><?= esc(\App\Libraries\AbusixWidok::ADNOTACJA) ?>
    Import jest wstrzymany.
</div>

<?php if (! empty($blad)): ?>
    <div class="alert alert-danger border-0 shadow-sm rounded-3" role="alert"><?= esc((string) $blad) ?></div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <?php foreach (['sprawy' => 'Sprawy', 'otwarte' => 'Otwarte (NEW / IN_PROGRESS)', 'zdarzenia' => 'Zdarzenia', 'klienci' => 'Klienci'] as $k => $et): ?>
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm rounded-3 h-100"><div class="card-body">
                <div class="text-body-secondary small mb-1"><?= esc($et) ?></div>
                <div class="fs-3 fw-semibold"><?= (int) $kpi[$k] ?></div>
            </div></div>
        </div>
    <?php endforeach; ?>
</div>

<h2 class="h5">Sprawy (cases)
    <?php foreach ($wgStanu as $s => $n): ?><span class="badge <?= $stanKlasa((string) $s) ?> ms-1"><?= esc((string) $s) ?>: <?= (int) $n ?></span><?php endforeach; ?>
</h2>
<div class="card border-0 shadow-sm rounded-3 mb-4"><div class="card-body p-0"><div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr>
            <th scope="col" class="ps-3">Sprawa</th><th scope="col">Stan</th><th scope="col">Klient</th>
            <th scope="col">Umowa</th><th scope="col">Pierwsze zdarzenie</th><th scope="col" class="pe-3">Ostatnie zdarzenie</th>
        </tr></thead>
        <tbody>
        <?php if ($sprawy === []): ?>
            <tr><td colspan="6" class="text-body-secondary text-center py-4">
                Brak spraw w Abusix.
                <?= $ostatniOk !== '' ? 'Ostatnie udane pobranie: ' . esc($ostatniOk) . '.' : 'Pobranie jeszcze się nie udało.' ?>
            </td></tr>
        <?php else: foreach ($sprawy as $s): ?>
            <tr>
                <td class="ps-3 font-monospace small"><?= esc((string) $s['case_id']) ?></td>
                <td><span class="badge <?= $stanKlasa((string) $s['state']) ?>"><?= esc((string) $s['state']) ?></span></td>
                <td class="small"><?= esc((string) $s['customer_id']) ?></td>
                <td class="small"><?= esc((string) (($s['contract_id'] ?? '') !== '' ? $s['contract_id'] : '—')) ?></td>
                <td class="small font-monospace"><?= esc((string) ($s['first_event_date'] ?? '—')) ?></td>
                <td class="small font-monospace pe-3"><?= esc((string) ($s['last_event_date'] ?? '—')) ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div></div></div>

<h2 class="h5">Zdarzenia (events)</h2>
<div class="card border-0 shadow-sm rounded-3 mb-4"><div class="card-body p-0"><div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr>
            <th scope="col" class="ps-3">Data</th><th scope="col">IP</th><th scope="col">Typ</th>
            <th scope="col">Domena</th><th scope="col" class="pe-3">Nadawca raportu</th>
        </tr></thead>
        <tbody>
        <?php if ($zdarzenia === []): ?>
            <tr><td colspan="5" class="text-body-secondary text-center py-4">
                Brak zdarzeń w Abusix.
                <?= $ostatniOk !== '' ? 'Ostatnie udane pobranie: ' . esc($ostatniOk) . '.' : 'Pobranie jeszcze się nie udało.' ?>
            </td></tr>
        <?php else: foreach ($zdarzenia as $z): ?>
            <tr>
                <td class="ps-3 small font-monospace"><?= esc((string) $z['zaobserwowano_at']) ?></td>
                <td class="font-monospace"><?php if (($z['ip'] ?? '') !== ''): ?><a href="<?= site_url('ip/' . rawurlencode((string) $z['ip'])) ?>"><?= esc((string) $z['ip']) ?></a><?php else: ?>—<?php endif; ?></td>
                <td class="small"><?= esc((string) $z['event_type']) ?></td>
                <td class="small"><?= esc((string) (($z['domain'] ?? '') !== '' ? $z['domain'] : '—')) ?></td>
                <td class="small pe-3"><?= esc((string) $z['sender_email']) ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div></div></div>

<h2 class="h5">Klienci (customers)</h2>
<div class="card border-0 shadow-sm rounded-3"><div class="card-body p-0"><div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th scope="col" class="ps-3">Klient</th><th scope="col">Tenant</th><th scope="col" class="pe-3">Ostatnio widziany</th></tr></thead>
        <tbody>
        <?php if ($klienci === []): ?>
            <tr><td colspan="3" class="text-body-secondary text-center py-4">
                Brak klientów w Abusix.
                <?= $ostatniOk !== '' ? 'Ostatnie udane pobranie: ' . esc($ostatniOk) . '.' : 'Pobranie jeszcze się nie udało.' ?>
            </td></tr>
        <?php else: foreach ($klienci as $k): ?>
            <tr>
                <td class="ps-3 small"><?= esc((string) $k['customer_id']) ?></td>
                <td class="small"><?= esc((string) $k['tenant_id']) ?></td>
                <td class="small font-monospace pe-3"><?= esc((string) ($k['widziano_at'] ?? '—')) ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div></div></div>

<?= $this->endSection() ?>
