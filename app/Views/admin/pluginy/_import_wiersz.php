<?php
$st = (string) ($imp['status'] ?? '');
$badgeSt = match ($st) {
    'ok' => 'text-bg-success',
    'blad', 'kwarantanna' => 'text-bg-danger',
    'w_trakcie' => 'text-bg-warning',
    default => 'text-bg-secondary',
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
$szczegolySkrot = $szczegoly !== '' && mb_strlen($szczegoly) > 80
    ? mb_substr($szczegoly, 0, 80) . '…'
    : $szczegoly;
$startAt = trim((string) ($imp['rozpoczeto_at'] ?? ''));
$koniecAt = trim((string) ($imp['zakonczono_at'] ?? ''));
$startUi = $startAt !== '' ? \App\Libraries\CzasUi::dataGodzina($startAt) : '';
$koniecUi = $koniecAt !== '' ? \App\Libraries\CzasUi::dataGodzina($koniecAt) : '—';
?>
<tr>
    <td class="font-monospace"><?= (int) ($imp['id'] ?? 0) ?></td>
    <td><code class="small"><?= esc((string) ($imp['zrodlo'] ?? '')) ?></code></td>
    <td><span class="badge <?= esc($badgeSt, 'attr') ?>"><?= esc($etykietaSt) ?></span></td>
    <td class="small text-nowrap"><?= esc($startUi) ?></td>
    <td class="small text-nowrap"><?= esc($koniecUi) ?></td>
    <td class="small text-body-secondary text-truncate" style="max-width:20rem"
        <?= $szczegoly !== '' ? 'title="' . esc($szczegoly, 'attr') . '"' : '' ?>>
        <?= $szczegoly !== '' ? esc($szczegolySkrot) : '—' ?>
    </td>
</tr>
